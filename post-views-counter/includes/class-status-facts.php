<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Status_Facts class.
 *
 * Collects the Status tab's facts (COUNTING_HEALTH.md, "Fact inventory")
 * once per request, each from the source that owns it, into one shape:
 * value, available, reason and observed_at. Collection is passive: it
 * writes, repairs, flushes, schedules and counts nothing, calls nothing with
 * a force flag, and reads table sizes from estimates, never COUNT(*). An
 * unavailable read is never a zero.
 *
 * The delivery facts are button-triggered checks and are not collected
 * here.
 *
 * @class Post_Views_Counter_Status_Facts
 */
class Post_Views_Counter_Status_Facts {

	/** Days in the Counting activity window, today included. */
	const ACTIVITY_DAYS = 14;

	/** Grace before an expected scheduled event counts as overdue. */
	const CRON_GRACE = HOUR_IN_SECONDS;

	/** @var array|null Facts collected in this request. */
	private $facts = null;

	/** @var int Collection time. */
	private $now = 0;

	/**
	 * Collect every fact once per request.
	 *
	 * @param int|null $now Collection time; the current time by default.
	 * @return array Facts keyed by id.
	 */
	public function collect( $now = null ) {
		if ( $this->facts !== null )
			return $this->facts;

		$this->now = $now === null ? time() : (int) $now;
		$this->facts = Post_Views_Counter()->visits->run_passive( function () {
			return $this->gather();
		} );

		return $this->facts;
	}

	/**
	 * Read every fact, inside a passive Visits read.
	 *
	 * @return array Facts keyed by id.
	 */
	private function gather() {
		$pvc = Post_Views_Counter();
		$general = $pvc->options['general'];
		$snapshot = $pvc->visits->get_status_snapshot();
		$facts = [];

		// Counting setup
		$facts['counter_mode'] = $this->fact( (string) $general['counter_mode'] );
		$facts['countable_content'] = $this->fact( [ 'post_types' => array_values( (array) $general['post_types_count'] ) ] );
		$interval = $pvc->normalize_time_between_counts( $general['time_between_counts'], $pvc->defaults['general']['time_between_counts'] );
		$facts['count_interval'] = $this->fact( (int) $interval['number'], true, (int) $interval['number'] === 0 ? 'interval_zero' : 'ready' );
		$facts['interval_storage'] = $this->fact( (string) $general['data_storage'] );
		$facts['exclusions'] = $this->fact( $this->get_exclusions( $general ) );
		$facts['admin_request'] = $this->get_admin_request( $pvc );
		$facts['ip_resolution'] = $this->get_ip_resolution( $pvc );
		$facts['counting_clock'] = $this->fact( [ 'count_time' => $general['count_time'] === 'local' ? 'local' : 'gmt', 'site_timezone' => wp_timezone_string(), 'differs' => $general['count_time'] !== 'local' && ! in_array( wp_timezone_string(), [ 'UTC', '+00:00' ], true ) ] );

		// Delivery
		$facts['caching_plugins'] = isset( $pvc->settings->general ) ? $this->fact( array_values( (array) $pvc->settings->general->get_active_caching_plugins() ) ) : $this->unavailable( 'settings_unavailable' );
		$facts['security_plugins'] = $this->fact( $this->get_security_plugins() );

		// Data and storage
		// both owners answer a failed state read: Views stay allowed, counts are refused
		$facts['views_access'] = $this->fact( (bool) $snapshot['access']['allowed'], true, $snapshot['access']['reason'] );
		$facts['write_eligibility'] = $this->fact( (bool) $snapshot['eligibility']['eligible'], true, $snapshot['eligibility']['reason'] );
		$visits = $pvc->visits->get_passive_visits_availability();
		$facts['visits'] = $this->fact( [ 'readable' => $visits['readable'], 'read_reason' => $visits['read_reason'], 'writable' => $visits['writable'], 'write_reason' => $visits['write_reason'] ], $snapshot['read_ok'], $snapshot['read_ok'] ? 'ready' : 'state_read_failed' );
		$window = $this->get_activity_window( $general );
		$derived = $pvc->visits->get_passive_visits_availability( [ 'start' => $window['start'], 'end' => $window['end'] ] );
		$facts['derived'] = $this->fact( [ 'derived_available' => $derived['derived_available'], 'derived_reason' => $derived['derived_reason'], 'derived_since' => $derived['derived_since'] ], $snapshot['read_ok'], $snapshot['read_ok'] ? 'ready' : 'state_read_failed' );
		$facts['state_row'] = $this->fact( array_merge( [ 'kind' => $snapshot['kind'] ], $snapshot['row'] ), $snapshot['read_ok'], $snapshot['read_ok'] ? $snapshot['kind'] : 'state_read_failed' );
		$facts['tables'] = isset( $pvc->settings->other ) ? $this->get_tables( $pvc->settings->other->get_status_tables() ) : $this->unavailable( 'settings_unavailable' );
		$facts['table_shape'] = $this->get_table_shape();
		$facts['schema_lease'] = $this->fact( [ 'present' => $snapshot['lease']['present'], 'expires_at' => $snapshot['lease']['expires_at'] ], $snapshot['lease']['read_ok'], $snapshot['lease']['read_ok'] ? 'ready' : 'state_read_failed' );
		$facts['counting_activity'] = $this->get_counting_activity( $snapshot, $window );

		// Buffering and scheduled tasks
		$facts['buffering'] = $this->fact( [ 'setting' => ! empty( $general['object_cache'] ), 'persistent_cache' => (bool) wp_using_ext_object_cache(), 'in_effect' => (bool) $pvc->counter->using_object_cache(), 'flush_interval' => $general['flush_interval'] ] );
		$facts['core_queue'] = $this->get_core_queue( $pvc );
		$facts['scheduled_events'] = $this->fact( $this->get_scheduled_events( $pvc ) );

		// Environment
		global $wpdb;

		$facts['versions'] = $this->fact( [
			'core'		=> (string) $pvc->defaults['version'],
			'pro'		=> class_exists( 'Post_Views_Counter_Pro' ) ? (string) get_option( 'post_views_counter_pro_version', '' ) : null,
			'wordpress'	=> (string) get_bloginfo( 'version' ),
			'php'		=> PHP_VERSION,
			'database'	=> method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : ''
		] );
		$facts['site_scope'] = $this->fact( ! is_multisite() ? 'single' : ( is_main_site() ? 'main_site' : 'subsite' ) );

		return $facts;
	}

	/**
	 * Build one fact.
	 *
	 * @param mixed  $value The observation; null when unavailable.
	 * @param bool   $available Whether the source gave a usable answer.
	 * @param string $reason Code.
	 * @return array
	 */
	private function fact( $value, $available = true, $reason = 'ready' ) {
		return [
			'value'			=> $available ? $value : null,
			'available'		=> (bool) $available,
			'reason'		=> (string) $reason,
			'observed_at'	=> $this->now
		];
	}

	/**
	 * Build an unavailable fact: never a zero.
	 *
	 * @param string $reason Code.
	 * @return array
	 */
	private function unavailable( $reason ) {
		return $this->fact( null, false, $reason );
	}

	/**
	 * Exclusions as groups and counts; IP values are never kept.
	 *
	 * @param array $general General settings.
	 * @return array
	 */
	private function get_exclusions( $general ) {
		$exclude = isset( $general['exclude'] ) && is_array( $general['exclude'] ) ? $general['exclude'] : [];

		return [
			'groups'		=> isset( $exclude['groups'] ) ? array_values( array_map( 'strval', (array) $exclude['groups'] ) ) : [],
			'roles_count'	=> isset( $exclude['roles'] ) ? count( (array) $exclude['roles'] ) : 0,
			'ips_count'		=> isset( $general['exclude_ips'] ) ? count( (array) $general['exclude_ips'] ) : 0
		];
	}

	/**
	 * Whether this administrator request passes Core's exclusion checks, by
	 * check_conditions() itself, and the first check that fails. Not whether
	 * a view would count.
	 *
	 * @param Post_Views_Counter $pvc Main instance.
	 * @return array
	 */
	private function get_admin_request( $pvc ) {
		$counter = $pvc->counter;
		$general = $pvc->options['general'];
		$groups = isset( $general['exclude']['groups'] ) ? (array) $general['exclude']['groups'] : [];
		$user_id = get_current_user_id();
		$ip = $counter->get_user_ip();

		if ( $counter->check_conditions( true, 0, $user_id, $ip, 'post', [] ) )
			return $this->fact( [ 'passes' => true, 'excluded_by' => '' ] );

		// the verdict is the owner's; these checks only name it, in its order
		$excluded_by = '';

		foreach ( (array) $general['exclude_ips'] as $excluded_ip ) {
			if ( $counter->validate_user_ip( $ip ) && $counter->is_excluded_ip( $ip, $excluded_ip ) ) {
				$excluded_by = 'ip';
				break;
			}
		}

		if ( $excluded_by === '' && $user_id && in_array( 'users', $groups, true ) )
			$excluded_by = 'logged_in_users';
		elseif ( $excluded_by === '' && $user_id && in_array( 'roles', $groups, true ) && $counter->is_user_role_excluded( $user_id, $general['exclude']['roles'] ) )
			$excluded_by = 'role';
		elseif ( $excluded_by === '' && ! $user_id && in_array( 'guests', $groups, true ) )
			$excluded_by = 'guests';
		elseif ( $excluded_by === '' && in_array( 'robots', $groups, true ) && isset( $pvc->crawler ) && $pvc->crawler->is_crawler() )
			$excluded_by = 'crawler';

		return $this->fact( [ 'passes' => false, 'excluded_by' => $excluded_by === '' ? 'unrecognized' : $excluded_by ] );
	}

	/**
	 * The IP source strategy and whether this request's IP resolved; the
	 * address itself is never kept.
	 *
	 * @param Post_Views_Counter $pvc Main instance.
	 * @return array
	 */
	private function get_ip_resolution( $pvc ) {
		$strategy = apply_filters( 'pvc_ip_resolution_strategy', 'remote_addr' );

		if ( ! in_array( $strategy, [ 'remote_addr', 'trusted_proxy_only', 'auto' ], true ) )
			$strategy = 'remote_addr';

		return $this->fact( [ 'strategy' => $strategy, 'resolved' => $pvc->counter->get_user_ip() !== '' ] );
	}

	/**
	 * Recognized security plugins, by name only. None recognized
	 * never means no firewall.
	 *
	 * @return array
	 */
	private function get_security_plugins() {
		$plugins = [];

		if ( defined( 'WORDFENCE_VERSION' ) )
			$plugins[] = 'Wordfence';

		if ( class_exists( 'ITSEC_Core' ) )
			$plugins[] = 'Solid Security';

		if ( defined( 'SUCURISCAN' ) )
			$plugins[] = 'Sucuri Security';

		if ( class_exists( 'AIO_WP_Security' ) )
			$plugins[] = 'All-In-One Security';

		/**
		 * Filter the recognized security plugin names shown on the Status tab.
		 *
		 * @since 1.8.2
		 *
		 * @param array $plugins Plugin names.
		 */
		$plugins = apply_filters( 'pvc_active_security_plugins', $plugins );

		return is_array( $plugins ) ? array_values( array_filter( array_map( 'strval', array_filter( $plugins, 'is_scalar' ) ), 'strlen' ) ) : [];
	}

	/**
	 * The Counting activity window in the counting clock: today and the 13
	 * days before it, with day keys and Unix bounds.
	 *
	 * @param array $general General settings.
	 * @return array{days:array,start:int,end:int}
	 */
	private function get_activity_window( $general ) {
		$zone = $general['count_time'] === 'local' ? wp_timezone() : new DateTimeZone( 'UTC' );
		$today = ( new DateTimeImmutable( '@' . $this->now ) )->setTimezone( $zone )->setTime( 0, 0 );
		$first = $today->modify( '-' . ( self::ACTIVITY_DAYS - 1 ) . ' days' );
		$days = [];

		for ( $day = $first; $day <= $today; $day = $day->modify( '+1 day' ) )
			$days[] = $day->format( 'Ymd' );

		return [ 'days' => $days, 'start' => $first->getTimestamp(), 'end' => min( $this->now, $today->modify( '+1 day' )->getTimestamp() - 1 ), 'first' => $first, 'today' => $today ];
	}

	/**
	 * Daily Views in the Counting activity window through pvc_get_views(),
	 * with its bounds and cache; a blocked or failed read is unavailable,
	 * never zero.
	 *
	 * @param array $snapshot Visits status snapshot.
	 * @param array $window Activity window.
	 * @return array
	 */
	private function get_counting_activity( $snapshot, $window ) {
		if ( ! $snapshot['access']['allowed'] )
			return $this->unavailable( $snapshot['access']['reason'] );

		$failed = false;
		$unusable = false;
		$listen = static function () use ( &$failed ) {
			$failed = true;
		};
		$values = [];

		add_action( 'pvc_views_read_failed', $listen );

		try {
			// one read per calendar month the window touches, at most two, over
			// all stored content: a string post type would match none
			foreach ( array_unique( [ $window['first']->format( 'Y-n' ), $window['today']->format( 'Y-n' ) ] ) as $month ) {
				list( $year, $month_number ) = array_map( 'intval', explode( '-', $month ) );
				$series = pvc_get_views( [ 'post_type' => [], 'fields' => 'date=>views', 'views_query' => [ 'year' => $year, 'month' => $month_number ] ] );

				// a filtered result that is not a series is not zero Views
				if ( ! is_array( $series ) ) {
					$unusable = true;
					break;
				}

				$values += $series;
			}
		} finally {
			remove_action( 'pvc_views_read_failed', $listen );
		}

		if ( $failed )
			return $this->unavailable( 'query_failed' );

		if ( $unusable )
			return $this->unavailable( 'read_unusable' );

		$days = [];
		$last_day = null;

		foreach ( $window['days'] as $key ) {
			$days[ $key ] = isset( $values[ $key ] ) ? (int) $values[ $key ] : 0;

			if ( $days[ $key ] > 0 )
				$last_day = $key;
		}

		return $this->fact( [ 'days' => $days, 'today' => end( $window['days'] ), 'last_day' => $last_day ], true, $last_day === null ? 'none_in_window' : 'ready' );
	}

	/**
	 * The plugin tables: a table whose check failed exists as null,
	 * unknown, never missing.
	 *
	 * @param array $tables Tables from Post_Views_Counter_Settings_Other::get_status_tables().
	 * @return array
	 */
	private function get_tables( $tables ) {
		return $this->fact( $tables, true, in_array( null, array_column( $tables, 'exists' ), true ) ? 'query_failed' : 'ready' );
	}

	/**
	 * The Views table's shape from metadata and estimates; never COUNT(*).
	 *
	 * @return array
	 */
	private function get_table_shape() {
		global $wpdb;

		$table = $wpdb->prefix . 'post_views';
		$columns = Post_Views_Counter_Visits_Query::run_read( 'SHOW COLUMNS FROM `' . $table . '`' );
		$indexes = Post_Views_Counter_Visits_Query::run_read( 'SHOW INDEX FROM `' . $table . '`' );
		$status = Post_Views_Counter_Visits_Query::run_read( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ) );

		if ( ! $columns['success'] || ! $indexes['success'] || ! $status['success'] )
			return $this->unavailable( 'query_failed' );

		$fields = array_map( 'strval', array_column( $columns['rows'], 'Field' ) );
		$row = isset( $status['rows'][0] ) ? $status['rows'][0] : [];

		return $this->fact( [
			'visits_column'		=> in_array( 'visits', $fields, true ),
			'content_column'	=> in_array( 'content', $fields, true ),
			'indexes'			=> array_values( array_unique( array_map( 'strval', array_column( $indexes['rows'], 'Key_name' ) ) ) ),
			'collation'			=> isset( $row['Collation'] ) ? (string) $row['Collation'] : null,
			'rows_estimate'		=> isset( $row['Rows'] ) ? (int) $row['Rows'] : null,
			'size_bytes'		=> isset( $row['Data_length'], $row['Index_length'] ) ? (int) $row['Data_length'] + (int) $row['Index_length'] : null
		], ! empty( $fields ), empty( $fields ) ? 'table_missing' : 'ready' );
	}

	/**
	 * The Core legacy queue: keys waiting and the drain lock. Applies with a
	 * persistent object cache only; a missing key list is Unknown, never
	 * "nothing waiting". The lock row is read durably, so the Options
	 * API cache is neither trusted nor filled.
	 *
	 * @param Post_Views_Counter $pvc Main instance.
	 * @return array
	 */
	private function get_core_queue( $pvc ) {
		if ( ! wp_using_ext_object_cache() )
			return $this->fact( null, false, 'not_applicable' );

		// another writer keeps its own queue and lock, which this does not read
		if ( $pvc->counter->get_extended_writer() !== null )
			return $this->fact( null, false, 'extension_writer' );

		$keys = wp_cache_get( 'cached_key_names', 'pvc' );
		$lock = $pvc->visits->read_option_durable( 'post_views_counter_core_flush_lock' );
		$lock_value = $lock === false ? null : $lock['value'];
		$value = [
			'keys'				=> null,
			'lock_read_ok'		=> $lock !== false,
			'lock_expires_at'	=> is_array( $lock_value ) && isset( $lock_value['expires_at'] ) ? (int) $lock_value['expires_at'] : null
		];

		if ( $keys === false )
			return $this->fact( $value, true, 'key_missing' );

		$keys = is_string( $keys ) ? array_filter( explode( '|', $keys ), 'strlen' ) : (array) $keys;
		$value['keys'] = count( array_unique( $keys ) );

		return $this->fact( $value );
	}

	/**
	 * PVC's scheduled events: whether each is expected in this configuration,
	 * its next run and whether it is overdue beyond the grace period. A next
	 * run is never evidence that a run happened. Applicability is the
	 * owners' own: the reset event's interval check and the sweep's and the
	 * mailer's. wp_next_scheduled() upgrades only a pre-2.1 cron array, which
	 * earlier admin requests have already converted.
	 *
	 * @param Post_Views_Counter $pvc Main instance.
	 * @return array
	 */
	private function get_scheduled_events( $pvc ) {
		$general = $pvc->options['general'];
		$expected = [
			'pvc_reset_counts'					=> ! empty( $general['cron_run'] ) && isset( $pvc->cron ) && $pvc->cron->get_reset_interval_days() > 0,
			Post_Views_Counter_Visits::CRON_HOOK	=> $pvc->visits->is_schema_sweep_expected(),
			'pvc_weekly_content_summary_send'	=> isset( $pvc->emails_scheduler ) && $pvc->emails_scheduler->is_enabled()
		];
		$events = [];

		foreach ( $expected as $hook => $is_expected ) {
			$next = wp_next_scheduled( $hook );
			$next = $next === false ? null : (int) $next;

			if ( $is_expected === null )
				$status = 'unknown';
			elseif ( ! $is_expected )
				$status = 'not_applicable';
			elseif ( $next === null )
				$status = 'missing';
			elseif ( $next < $this->now - self::CRON_GRACE )
				$status = 'overdue';
			else
				$status = 'ok';

			$events[ $hook ] = [ 'expected' => $is_expected === null ? null : (bool) $is_expected, 'next' => $next, 'status' => $status ];
		}

		return [ 'events' => $events, 'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'grace' => self::CRON_GRACE ];
	}
}
