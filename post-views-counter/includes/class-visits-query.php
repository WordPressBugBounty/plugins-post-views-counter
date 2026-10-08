<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Visits_Query class.
 *
 * Internal helper surface for Visit period parsing, query identity and
 * the Visit read-cache protocol. These are implementation details of the public
 * Views/Visits reading functions in includes/functions.php and are deliberately
 * kept off the global `pvc_` function namespace.
 *
 * Nothing here writes counter data. Row persistence belongs to the counter
 * classes; read/write/derived status belongs to Post_Views_Counter_Visits.
 *
 * The public, documented, pluggable wrappers pvc_normalize_views_period() and
 * pvc_normalize_visits_period() delegate to normalize_views_period() and
 * normalize_period(). Callers that need a period normalized should keep calling
 * those global functions so a site override still takes effect.
 *
 * @class Post_Views_Counter_Visits_Query
 * @since 1.7.16
 */
class Post_Views_Counter_Visits_Query {

	/**
	 * Cache group holding the Visit read-cache generation counter.
	 *
	 * @var string
	 */
	const CACHE_GENERATION_GROUP = 'pvc-get_visits';

	/**
	 * Cache key holding the Visit read-cache generation counter.
	 *
	 * @var string
	 */
	const CACHE_GENERATION_KEY = 'generation';

	/**
	 * Cache key serializing generation updates when atomic increments are unavailable.
	 *
	 * @var string
	 */
	const CACHE_GENERATION_LOCK_KEY = 'generation-lock';

	/**
	 * Per-site durable namespace used only when cache generation cannot advance.
	 *
	 * This non-autoloaded option is intentionally retained across normal plugin
	 * lifecycle events so a persistent object cache cannot reuse stale keys.
	 *
	 * @var string
	 */
	const CACHE_FALLBACK_OPTION = 'post_views_counter_visits_cache_fallback';

	/**
	 * Whether Visit result caching is unsafe for the remainder of this request.
	 *
	 * @var bool[]
	 */
	private static $bypass_read_cache = [];

	/** @var bool Whether the latest invalidation retired cross-request caches. */
	private static $last_invalidation_success = true;

	/** @return bool */
	public static function was_last_invalidation_successful() {
		return self::$last_invalidation_success;
	}

	/**
	 * Set request-local cache bypass state for only the active site.
	 *
	 * @param bool $bypass Whether to bypass Visit result caches.
	 * @return void
	 */
	private static function set_read_cache_bypass( $bypass ) {
		$blog_id = get_current_blog_id();

		if ( $bypass )
			self::$bypass_read_cache[$blog_id] = true;
		else
			unset( self::$bypass_read_cache[$blog_id] );
	}

	/**
	 * Result-cache group for aggregate Visit reads.
	 *
	 * @var string
	 */
	const VISITS_CACHE_GROUP = 'pvc-get_visits';

	/**
	 * Result-cache group for single/aggregate post Visit reads.
	 *
	 * @var string
	 */
	const POST_VISITS_CACHE_GROUP = 'pvc-get_post_visits';

	/**
	 * Result-cache group for Views-per-Visit reads.
	 *
	 * @var string
	 */
	const VIEWS_PER_VISIT_CACHE_GROUP = 'pvc-get_views_per_visit';

	/**
	 * Read the current Visit read-cache generation.
	 *
	 * @since 1.7.16
	 *
	 * @return int Current generation; 0 when none has been recorded.
	 */
	public static function get_cache_generation() {
		return (int) wp_cache_get( self::CACHE_GENERATION_KEY, self::CACHE_GENERATION_GROUP );
	}

	/**
	 * Build a generation-scoped result-cache key from prepared SQL.
	 *
	 * Bumping the generation therefore retires every Visit result key at once
	 * without touching the View or source caches, which use their own groups.
	 *
	 * @since 1.7.16
	 *
	 * @param string $sql Prepared SQL the cached value was derived from.
	 *
	 * @return string Cache key.
	 */
	public static function get_read_cache_key( $sql ) {
		if ( ! empty( self::$bypass_read_cache[get_current_blog_id()] ) )
			return md5( wp_generate_uuid4() . '|' . $sql );

		return md5( get_current_blog_id() . '|' . self::get_cache_generation() . '|' . self::get_durable_cache_token() . '|' . $sql );
	}

	/**
	 * Read the bounded per-site fallback namespace.
	 *
	 * @since 1.7.16
	 *
	 * @return string Durable token, or an empty string before fallback is needed.
	 */
	public static function get_durable_cache_token() {
		$state = get_option( self::CACHE_FALLBACK_OPTION, [] );

		return is_array( $state ) && isset( $state['token'] ) && is_string( $state['token'] ) ? $state['token'] : '';
	}

	/**
	 * Replace the per-site durable namespace after cache publication failure.
	 *
	 * The option owns no analytics data and remains one non-autoloaded row. It is
	 * retained across activation/deactivation/reset so old cache keys never become
	 * current again after a rollback or persistent-cache restart.
	 *
	 * @since 1.7.16
	 *
	 * @return bool Whether the new namespace was durably verified.
	 */
	public static function rotate_durable_cache_token() {
		$state = [
			'version' => 1,
			'token' => wp_generate_uuid4(),
			'updated_at' => time()
		];
		$updated = update_option( self::CACHE_FALLBACK_OPTION, $state, false );
		$stored = get_option( self::CACHE_FALLBACK_OPTION, [] );

		return ( $updated || $stored === $state ) && is_array( $stored ) && isset( $stored['token'] ) && hash_equals( $state['token'], (string) $stored['token'] );
	}

	/**
	 * Retire a fallback namespace with an atomic SQL compare-and-swap.
	 *
	 * This is used only after the normal Options API publication path fails.
	 *
	 * @return bool Whether the previous namespace is no longer current.
	 */
	private static function force_rotate_durable_cache_token() {
		global $wpdb;

		$previous = get_option( self::CACHE_FALLBACK_OPTION, null );
		$state = [
			'version' => 1,
			'token' => wp_generate_uuid4(),
			'updated_at' => time()
		];

		if ( is_array( $previous ) && isset( $previous['token'] ) && is_string( $previous['token'] ) ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
					maybe_serialize( $state ),
					self::CACHE_FALLBACK_OPTION,
					maybe_serialize( $previous )
				)
			);
		} else {
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
					self::CACHE_FALLBACK_OPTION,
					maybe_serialize( $state )
				)
			);
		}

		$cache_deleted = wp_cache_delete( self::CACHE_FALLBACK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		if ( $result === 1 && ! $cache_deleted )
			wp_cache_set( self::CACHE_FALLBACK_OPTION, $state, 'options' );

		$stored = get_option( self::CACHE_FALLBACK_OPTION, [] );

		if ( is_array( $stored ) && isset( $stored['token'] ) && is_string( $stored['token'] ) ) {
			if ( hash_equals( $state['token'], $stored['token'] ) )
				return true;

			return ! is_array( $previous ) || ! isset( $previous['token'] ) || ! hash_equals( (string) $previous['token'], $stored['token'] );
		}

		return false;
	}

	/**
	 * Get the timezone used to build counter periods.
	 *
	 * @since 1.7.16
	 *
	 * @param array|null $settings Optional site-specific general settings.
	 * @return DateTimeZone
	 */
	public static function get_timezone( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$pvc = Post_Views_Counter();
			$settings = isset( $pvc->options['general'] ) && is_array( $pvc->options['general'] ) ? $pvc->options['general'] : [];
		}

		return isset( $settings['count_time'] ) && $settings['count_time'] === 'local' ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	/**
	 * Create a counter-calendar date without inheriting the PHP server timezone.
	 *
	 * @since 1.7.16
	 *
	 * @param string       $format Strict DateTime format.
	 * @param mixed        $value Date-compatible input.
	 * @param DateTimeZone $timezone Counter timezone.
	 * @return DateTimeImmutable|null
	 */
	public static function create_exact_date( $format, $value, $timezone ) {
		$date = DateTimeImmutable::createFromFormat( '!' . $format, $value, $timezone );
		$errors = DateTimeImmutable::getLastErrors();

		if ( ! $date || ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) || $date->format( $format ) !== $value )
			return null;

		return $date;
	}

	/**
	 * Create a strict local calendar date for Visit/Views period parsing.
	 *
	 * @since 1.7.16
	 *
	 * @param mixed $value Date descriptor, supported relative name, or timestamp.
	 * @param DateTimeZone $timezone Counting timezone.
	 * @return DateTimeImmutable|null
	 */
	public static function create_date( $value, $timezone ) {
		try {
			if ( is_array( $value ) ) {
				if ( isset( $value['year'], $value['week'] ) ) {
					$year = (int) $value['year'];
					$week = (int) $value['week'];

					if ( $year < 1 || $week < 1 || $week > 53 )
						return null;

					$date = ( new DateTimeImmutable( 'now', $timezone ) )->setISODate( $year, $week )->setTime( 0, 0, 0 );

					return $date->format( 'o-W' ) === sprintf( '%04d-%02d', $year, $week ) ? $date : null;
				}

				if ( ! isset( $value['year'], $value['month'], $value['day'] ) )
					return null;

				$year = (int) $value['year'];
				$month = (int) $value['month'];
				$day = (int) $value['day'];

				if ( $year < 1 || ! checkdate( $month, $day, $year ) )
					return null;

				$value = sprintf( '%04d-%02d-%02d', $year, $month, $day );
			}

			if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) && strlen( $value ) > 8 ) )
				$date = ( new DateTimeImmutable( '@' . (int) $value ) )->setTimezone( $timezone );
			else {
				$value = strtolower( trim( (string) $value ) );

				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) )
					$date = self::create_exact_date( 'Y-m-d', $value, $timezone );
				elseif ( preg_match( '/^\d{8}$/', $value ) )
					$date = self::create_exact_date( 'Ymd', $value, $timezone );
				elseif ( in_array( $value, [ 'today', 'yesterday' ], true ) )
					$date = new DateTimeImmutable( $value, $timezone );
				else
					return null;
			}
		} catch ( Exception $exception ) {
			return null;
		}

		return $date ? $date->setTimezone( $timezone )->setTime( 0, 0, 0 ) : null;
	}

	/**
	 * Normalize a Views period to the canonical shared-table selector.
	 *
	 * @since 1.7.16
	 *
	 * @param mixed $period Requested period.
	 * @param array $args Optional explicit period arguments.
	 * @return array|null
	 */
	public static function normalize_views_period( $period = 'total', $args = [] ) {
		$normalized = pvc_normalize_visits_period( $period, $args );

		if ( $normalized === null )
			return null;

		$request_period = sanitize_key( is_array( $period ) && isset( $period['period'] ) ? $period['period'] : (string) $period );

		if ( in_array( $request_period, [ '', 'total', 'lifetime', 'all_time' ], true ) )
			$request_period = 'total';
		elseif ( isset( $args['period_from'], $args['period_to'] ) || isset( $args['start'], $args['end'] ) )
			$request_period = 'custom';

		$normalized['request_period'] = $request_period;
		$normalized['signature'] = 'views:' . substr( $normalized['signature'], strlen( 'visits:' ) );

		return $normalized;
	}

	/**
 * Normalize one Visit period into storage and derived-range semantics.
	 *
 * Availability ranges are half-open. Storage day ranges remain inclusive because
	 * the table stores one YYYYMMDD key per calendar day.
	 *
	 * @since 1.7.16
	 *
	 * @param string $period Named or explicit storage period.
	 * @param array  $args Optional explicit range/type arguments.
	 * @return array|null
	 */
	public static function normalize_period( $period = 'total', $args = [] ) {
		// only an array-form period carries year/week/month/day and after/before;
		// a caller-supplied `visits_query` argument is never read, so it cannot
		// redirect a Views read that passes its arguments through here
		$query = [];

		if ( is_array( $period ) ) {
			$query = $period;
			$args = array_merge( $query, is_array( $args ) ? $args : [] );
			$period = isset( $query['period'] ) ? $query['period'] : 'total';
		}

		$args = is_array( $args ) ? $args : [];
		$timezone = self::get_timezone( isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : null );
		if ( ! isset( $query['period'] ) && isset( $query['year'] ) ) {
			if ( isset( $query['week'] ) )
				$query['period'] = sprintf( '%04d-%02d', (int) $query['year'], (int) $query['week'] );
			elseif ( isset( $query['month'], $query['day'] ) )
				$query['period'] = sprintf( '%04d%02d%02d', (int) $query['year'], (int) $query['month'], (int) $query['day'] );
			elseif ( isset( $query['month'] ) )
				$query['period'] = sprintf( '%04d%02d', (int) $query['year'], (int) $query['month'] );
			else
				$query['period'] = sprintf( '%04d', (int) $query['year'] );
		}

		$period = sanitize_key( (string) ( isset( $query['period'] ) ? $query['period'] : $period ) );
		$period = $period === '' ? 'total' : $period;
		$now = new DateTimeImmutable( 'now', $timezone );
		$today = $now->setTime( 0, 0, 0 );
		$dynamic_end = false;
		$type = null;
		$period_from = null;
		$period_to = null;
		$start_date = null;
		$end_date = null;

		if ( isset( $args['period_from'], $args['period_to'] ) ) {
			$from = (string) absint( $args['period_from'] );
			$to = (string) absint( $args['period_to'] );

			if ( strlen( $from ) !== 8 || strlen( $to ) !== 8 || (int) $to < (int) $from )
				return null;

			$start_date = DateTimeImmutable::createFromFormat( '!Ymd', $from, $timezone );
			$last_date = DateTimeImmutable::createFromFormat( '!Ymd', $to, $timezone );

			if ( ! $start_date || ! $last_date || $start_date->format( 'Ymd' ) !== $from || $last_date->format( 'Ymd' ) !== $to )
				return null;

			$end_date = $last_date->modify( '+1 day' );
			$type = 0;
			$period_from = (int) $from;
			$period_to = (int) $to;
			$period = 'custom';
		} elseif ( array_key_exists( 'start', $args ) || array_key_exists( 'end', $args ) || array_key_exists( 'after', $query ) || array_key_exists( 'before', $query ) ) {
			$start_value = array_key_exists( 'start', $args ) ? $args['start'] : ( isset( $query['after'] ) ? $query['after'] : null );
			$end_value = array_key_exists( 'end', $args ) ? $args['end'] : ( isset( $query['before'] ) ? $query['before'] : null );

			if ( $start_value === null || $end_value === null )
				return null;

			$start_date = self::create_date( $start_value, $timezone );
			$last_date = self::create_date( $end_value, $timezone );

			if ( ! $start_date || ! $last_date || $last_date < $start_date )
				return null;

			$end_date = $last_date->modify( '+1 day' );
			$type = 0;
			$period_from = (int) $start_date->format( 'Ymd' );
			$period_to = (int) $last_date->format( 'Ymd' );
			$period = 'custom';
		} else {
			switch ( $period ) {
				case 'total':
				case 'lifetime':
				case 'all_time':
					return [
						'is_total' => true,
						'type' => 4,
						'period_from' => 'total',
						'period_to' => 'total',
						'range' => null,
						'availability_range' => null,
						'signature' => 'visits:total'
					];

				case 'today':
					$start_date = $today;
					$end_date = $today->modify( '+1 day' );
					$type = 0;
					$dynamic_end = true;
					break;

				case 'yesterday':
					$start_date = $today->modify( '-1 day' );
					$end_date = $today;
					$type = 0;
					break;

				case 'last_3_days':
				case 'last_7_days':
				case 'last_30_days':
					$days = (int) str_replace( [ 'last_', '_days' ], '', $period );
					$start_date = $today->modify( '-' . ( $days - 1 ) . ' days' );
					$end_date = $today->modify( '+1 day' );
					$type = 0;
					$dynamic_end = true;
					break;

				case 'this_week':
				case 'last_week':
					$start_date = $today->modify( 'monday this week' );

					if ( $period === 'last_week' )
						$start_date = $start_date->modify( '-7 days' );

					$end_date = $start_date->modify( '+7 days' );
					$type = 1;
					$dynamic_end = $period === 'this_week';
					break;

				case 'this_month':
				case 'last_month':
					$start_date = $today->modify( 'first day of this month' );

					if ( $period === 'last_month' )
						$start_date = $start_date->modify( '-1 month' );

					$end_date = $start_date->modify( '+1 month' );
					$type = 2;
					$dynamic_end = $period === 'this_month';
					break;

				case 'this_year':
				case 'last_year':
					$start_date = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );

					if ( $period === 'last_year' )
						$start_date = $start_date->modify( '-1 year' );

					$end_date = $start_date->modify( '+1 year' );
					$type = 3;
					$dynamic_end = $period === 'this_year';
					break;

				default:
					if ( preg_match( '/^(\d{4})-(?:w)?(\d{2})$/i', $period, $matches ) ) {
						$iso_year = (int) $matches[1];
						$iso_week = (int) $matches[2];

						if ( $iso_week < 1 || $iso_week > 53 )
							return null;

						$start_date = $today->setISODate( $iso_year, $iso_week );

						if ( $start_date->format( 'o-W' ) !== sprintf( '%04d-%02d', $iso_year, $iso_week ) )
							return null;

						$end_date = $start_date->modify( '+7 days' );
						$type = 1;
					} elseif ( preg_match( '/^\d{8}$/', $period ) ) {
						$start_date = self::create_exact_date( 'Ymd', $period, $timezone );

						if ( ! $start_date )
							return null;

						$end_date = $start_date->modify( '+1 day' );
						$type = 0;
					} elseif ( preg_match( '/^\d{6}$/', $period ) ) {
						$start_date = self::create_exact_date( 'Ym', $period, $timezone );

						if ( ! $start_date )
							return null;

						$end_date = $start_date->modify( '+1 month' );
						$type = 2;
					} elseif ( preg_match( '/^\d{4}$/', $period ) ) {
						$start_date = DateTimeImmutable::createFromFormat( '!Y', $period, $timezone );
						$end_date = $start_date ? $start_date->modify( '+1 year' ) : null;
						$type = 3;
					} else
						return null;
			}

			if ( $type === 0 ) {
				$period_from = (int) $start_date->format( 'Ymd' );
				$period_to = (int) $end_date->modify( '-1 day' )->format( 'Ymd' );
			} elseif ( $type === 1 )
				$period_from = $period_to = (int) $start_date->format( 'oW' );
			elseif ( $type === 2 )
				$period_from = $period_to = (int) $start_date->format( 'Ym' );
			else
				$period_from = $period_to = (int) $start_date->format( 'Y' );
		}

		if ( ! $start_date || ! $end_date || $start_date->getTimestamp() < 1 || $end_date <= $start_date )
			return null;

		if ( $period !== 'custom' && $start_date <= $now && $end_date > $now )
			$dynamic_end = true;

		$range = [
			'start' => $start_date->getTimestamp(),
			'end' => $end_date->getTimestamp()
		];
		$availability_range = $range;

		if ( $dynamic_end )
			$availability_range['end'] = min( $availability_range['end'], $now->getTimestamp() );

		if ( $availability_range['end'] <= $availability_range['start'] || ( ! $dynamic_end && $availability_range['end'] > $now->getTimestamp() ) )
			return null;

		$signature_data = [
			'type' => (int) $type,
			'period_from' => $period_from,
			'period_to' => $period_to,
			'range_start' => $range['start'],
			'range_end' => $range['end'],
			'availability_start' => $availability_range['start'],
			'availability_end' => $availability_range['end']
		];

		return [
			'is_total' => false,
			'type' => (int) $type,
			'period_from' => $period_from,
			'period_to' => $period_to,
			'range' => $range,
			'availability_range' => $availability_range,
			'signature' => 'visits:' . md5( wp_json_encode( $signature_data ) )
		];
	}

	/**
	 * Revalidate structured Visit period components after extensibility filters.
	 *
	 * @since 1.7.16
	 *
	 * @param array $period Candidate normalized period.
	 * @return array|null
	 */
	public static function validate_normalized_period( $period ) {
		if ( ! is_array( $period ) || ! isset( $period['type'], $period['period_from'], $period['period_to'] ) )
			return null;

		$type = (int) $period['type'];

		if ( $type < 0 || $type > 4 )
			return null;

		if ( $type === 4 ) {
			if ( (string) $period['period_from'] !== 'total' || (string) $period['period_to'] !== 'total' )
				return null;

			return [
				'is_total' => true,
				'type' => 4,
				'period_from' => 'total',
				'period_to' => 'total',
				'range' => null,
				'availability_range' => null,
				'signature' => 'visits:total'
			];
		}

		$from = (int) $period['period_from'];
		$to = (int) $period['period_to'];
		$expected_length = $type === 0 ? 8 : ( $type === 3 ? 4 : 6 );

		if ( strlen( (string) $from ) !== $expected_length || strlen( (string) $to ) !== $expected_length || $to < $from )
			return null;

		foreach ( [ 'range', 'availability_range' ] as $range_key ) {
			if ( empty( $period[$range_key] ) || ! is_array( $period[$range_key] ) || ! isset( $period[$range_key]['start'], $period[$range_key]['end'] ) )
				return null;

			$period[$range_key] = [ 'start' => (int) $period[$range_key]['start'], 'end' => (int) $period[$range_key]['end'] ];

			if ( $period[$range_key]['start'] < 1 || $period[$range_key]['end'] <= $period[$range_key]['start'] )
				return null;
		}

		$timezone = self::get_timezone();
		$expected_start = null;
		$expected_end = null;

		if ( $type === 0 ) {
			$expected_start = self::create_exact_date( 'Ymd', (string) $from, $timezone );
			$last_date = self::create_exact_date( 'Ymd', (string) $to, $timezone );
			$expected_end = $last_date ? $last_date->modify( '+1 day' ) : null;
		} elseif ( $type === 1 ) {
			if ( $from !== $to )
				return null;

			$iso_year = (int) substr( (string) $from, 0, 4 );
			$iso_week = (int) substr( (string) $from, 4, 2 );

			if ( $iso_week < 1 || $iso_week > 53 )
				return null;

			$expected_start = ( new DateTimeImmutable( 'now', $timezone ) )->setISODate( $iso_year, $iso_week, 1 )->setTime( 0, 0, 0 );

			if ( $expected_start->format( 'oW' ) !== sprintf( '%04d%02d', $iso_year, $iso_week ) )
				return null;

			$expected_end = $expected_start->modify( '+1 week' );
		} elseif ( $type === 2 ) {
			if ( $from !== $to )
				return null;

			$expected_start = self::create_exact_date( 'Ym', (string) $from, $timezone );
			$expected_end = $expected_start ? $expected_start->modify( '+1 month' ) : null;
		} else {
			if ( $from !== $to )
				return null;

			$expected_start = self::create_exact_date( 'Y', (string) $from, $timezone );
			$expected_end = $expected_start ? $expected_start->modify( '+1 year' ) : null;
		}

		if ( ! $expected_start || ! $expected_end || $period['range']['start'] !== $expected_start->getTimestamp() || $period['range']['end'] !== $expected_end->getTimestamp() )
			return null;

		if ( $period['availability_range']['start'] !== $period['range']['start'] || $period['availability_range']['end'] > $period['range']['end'] )
			return null;

		$period['is_total'] = false;
		$period['type'] = $type;
		$period['period_from'] = $from;
		$period['period_to'] = $to;
		$period['signature'] = 'visits:' . md5( wp_json_encode( [
			'type' => $type,
			'period_from' => $from,
			'period_to' => $to,
			'range_start' => $period['range']['start'],
			'range_end' => $period['range']['end'],
			'availability_start' => $period['availability_range']['start'],
			'availability_end' => $period['availability_range']['end']
		] ) );

		return $period;
	}

	/**
	 * Resolve one explicit post-only daily range for aggregate Visit helpers.
	 *
	 * @since 1.7.16
	 *
	 * @param array $args Arguments containing explicit start/end timestamps.
	 * @return array|null
	 */
	public static function parse_range( $args ) {
		$normalized = pvc_normalize_visits_period( 'custom', $args );

		if ( $normalized === null || ! empty( $normalized['is_total'] ) )
			return null;

		return [
			'start' => $normalized['availability_range']['start'],
			'end' => $normalized['availability_range']['end'],
			'period_from' => $normalized['period_from'],
			'period_to' => $normalized['period_to'],
			'signature' => $normalized['signature']
		];
	}

	/**
	 * Read Visit totals for a bounded set of Core post IDs.
	 *
	 * This is an internal reader kept for future insights. It keeps the full
	 * requested ID set in one prepared grouped query instead of one query per
	 * object.
	 *
	 * @since 1.7.16
	 *
	 * @param array  $post_ids Current page's post IDs.
	 * @param mixed  $period Requested Visit period.
	 * @param array  $args Optional Visit query arguments.
	 * @return array{values:array,availability:array,error:string|null}
	 */
	public static function get_post_visit_values( $post_ids, $period = 'total', $args = [] ) {
		$post_ids = self::normalize_post_ids( $post_ids );
		$values = array_fill_keys( $post_ids, null );
		$context = self::get_post_visit_read_context( $post_ids, $period, $args );

		if ( $context['error'] !== null )
			return [ 'values' => $values, 'availability' => $context['availability'], 'error' => $context['error'] ];

		if ( empty( $context['availability']['readable'] ) )
			return [ 'values' => $values, 'availability' => $context['availability'], 'error' => null ];

		global $wpdb;

		$params = $post_ids;
		$where = [ '`id` IN (' . implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) ) . ')', '`type` = %d' ];
		$params[] = $context['period']['type'];
		$period_condition = self::get_period_condition( $context['period'] );
		$where[] = $period_condition['where'];
		$params = array_merge( $params, $period_condition['params'] );

		if ( pvc_post_views_has_content_column() )
			$where[] = '`content` = 0';

		$sql = $wpdb->prepare( 'SELECT `id`, SUM(`visits`) AS `visits` FROM `' . $wpdb->prefix . 'post_views` WHERE ' . implode( ' AND ', $where ) . ' GROUP BY `id`', $params );
		$cache_key = self::get_read_cache_key( $sql );
		$rows = wp_cache_get( $cache_key, self::POST_VISITS_CACHE_GROUP );

		if ( $rows === false ) {
			$result = self::get_rows_result( $sql );

			if ( ! $result['success'] )
				return [ 'values' => $values, 'availability' => $context['availability'], 'error' => 'read_failed' ];

			$rows = $result['value'];
			wp_cache_add( $cache_key, $rows, self::POST_VISITS_CACHE_GROUP, absint( apply_filters( 'pvc_visits_object_cache_expire', 300 ) ) );
		}

		$values = array_fill_keys( $post_ids, 0 );

		foreach ( $rows as $row ) {
			$post_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;

			if ( $post_id > 0 && array_key_exists( $post_id, $values ) )
				$values[$post_id] = isset( $row['visits'] ) ? (int) $row['visits'] : 0;
		}

		return [ 'values' => $values, 'availability' => $context['availability'], 'error' => null ];
	}

	/**
	 * Read Visit totals for a bounded term or user page.
	 *
	 * This internal helper intentionally accepts only the two shared-table
	 * extended content types. Post-only readers remain constrained to content 0.
	 *
	 * @since 1.7.16
	 *
	 * @param string $content_type Shared content type: term or user.
	 * @param array  $content_ids Current page's content IDs.
	 * @param mixed  $period Requested Visit period.
	 * @param array  $args Optional Visit query arguments.
	 * @return array{values:array,availability:array,error:string|null}
	 */
	public static function get_content_visit_values( $content_type, $content_ids, $period = 'total', $args = [] ) {
		$content_ids = self::normalize_post_ids( $content_ids );
		$values = array_fill_keys( $content_ids, null );
		$context = self::get_content_visit_read_context( $content_type, $content_ids, $period, $args );

		if ( $context['error'] !== null )
			return [ 'values' => $values, 'availability' => $context['availability'], 'error' => $context['error'] ];

		if ( empty( $context['availability']['readable'] ) )
			return [ 'values' => $values, 'availability' => $context['availability'], 'error' => null ];

		global $wpdb;

		$params = $content_ids;
		$where = [ '`id` IN (' . implode( ', ', array_fill( 0, count( $content_ids ), '%d' ) ) . ')', '`type` = %d' ];
		$params[] = $context['period']['type'];
		$period_condition = self::get_period_condition( $context['period'] );
		$where[] = $period_condition['where'];
		$params = array_merge( $params, $period_condition['params'] );
		$where[] = '`content` = %d';
		$params[] = $context['content'];

		$sql = $wpdb->prepare( 'SELECT `id`, SUM(`visits`) AS `visits` FROM `' . $wpdb->prefix . 'post_views` WHERE ' . implode( ' AND ', $where ) . ' GROUP BY `id`', $params );
		$cache_key = self::get_read_cache_key( $sql );
		$rows = wp_cache_get( $cache_key, self::POST_VISITS_CACHE_GROUP );

		if ( $rows === false ) {
			$result = self::get_rows_result( $sql );

			if ( ! $result['success'] )
				return [ 'values' => $values, 'availability' => $context['availability'], 'error' => 'read_failed' ];

			$rows = $result['value'];
			wp_cache_add( $cache_key, $rows, self::POST_VISITS_CACHE_GROUP, absint( apply_filters( 'pvc_visits_object_cache_expire', 300 ) ) );
		}

		$values = array_fill_keys( $content_ids, 0 );

		foreach ( $rows as $row ) {
			$content_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;

			if ( $content_id > 0 && array_key_exists( $content_id, $values ) )
				$values[$content_id] = isset( $row['visits'] ) ? (int) $row['visits'] : 0;
		}

		return [ 'values' => $values, 'availability' => $context['availability'], 'error' => null ];
	}

	/**
	 * Read one bounded term or user Visit chart series.
	 *
	 * @since 1.7.16
	 *
	 * @param string $content_type Shared content type: term or user.
	 * @param int    $content_id Content ID.
	 * @param mixed  $period_context Requested chart period.
	 * @param array  $args Optional Visit query arguments.
	 * @return array{values:array|null,availability:array,error:string|null}
	 */
	public static function get_content_visit_series( $content_type, $content_id, $period_context, $args = [] ) {
		$content_id = absint( $content_id );
		$context = self::get_content_visit_read_context( $content_type, $content_id > 0 ? [ $content_id ] : [], $period_context, $args );

		if ( $context['error'] !== null || empty( $context['availability']['readable'] ) )
			return [ 'values' => null, 'availability' => $context['availability'], 'error' => $context['error'] ];

		$series_period = self::get_series_period( $context['period'] );

		if ( $series_period === null )
			return [ 'values' => null, 'availability' => $context['availability'], 'error' => 'invalid_period' ];

		$series_availability = isset( Post_Views_Counter()->visits ) ? Post_Views_Counter()->visits->get_visits_availability( $context['period']['availability_range'] ) : [ 'readable' => false, 'read_reason' => 'schema_failed' ];

		if ( empty( $series_availability['readable'] ) )
			return [ 'values' => null, 'availability' => $series_availability, 'error' => null ];

		global $wpdb;

		$params = [ $content_id, $series_period['type'] ];
		$where = [ '`id` = %d', '`type` = %d' ];
		$period_condition = self::get_period_condition( $series_period );
		$where[] = $period_condition['where'];
		$params = array_merge( $params, $period_condition['params'] );
		$where[] = '`content` = %d';
		$params[] = $context['content'];

		$sql = $wpdb->prepare( 'SELECT `period`, SUM(`visits`) AS `visits` FROM `' . $wpdb->prefix . 'post_views` WHERE ' . implode( ' AND ', $where ) . ' GROUP BY `period` ORDER BY CAST(`period` AS UNSIGNED) ASC', $params );
		$cache_key = self::get_read_cache_key( $sql );
		$rows = wp_cache_get( $cache_key, self::POST_VISITS_CACHE_GROUP );

		if ( $rows === false ) {
			$result = self::get_rows_result( $sql );

			if ( ! $result['success'] )
				return [ 'values' => null, 'availability' => $context['availability'], 'error' => 'read_failed' ];

			$rows = $result['value'];
			wp_cache_add( $cache_key, $rows, self::POST_VISITS_CACHE_GROUP, absint( apply_filters( 'pvc_visits_object_cache_expire', 300 ) ) );
		}

		$values = [];

		foreach ( $rows as $row ) {
			if ( ! isset( $row['period'] ) )
				continue;

			$values[(string) $row['period']] = isset( $row['visits'] ) ? (int) $row['visits'] : 0;
		}

		return [ 'values' => $values, 'availability' => $context['availability'], 'error' => null ];
	}

	/**
	 * Read one Core post's bounded Visit chart series.
	 *
	 * Month and week selections read daily rows, while a year selection reads
	 * monthly rows. The caller fills missing presentation slots; this method
	 * returns only rows stored for the selected post.
	 *
	 * @since 1.7.16
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $period_context Requested chart period.
	 * @param array $args Optional Visit query arguments.
	 * @return array{values:array|null,availability:array,error:string|null}
	 */
	public static function get_post_visit_series( $post_id, $period_context, $args = [] ) {
		$post_id = absint( $post_id );
		$context = self::get_post_visit_read_context( $post_id > 0 ? [ $post_id ] : [], $period_context, $args );

		if ( $context['error'] !== null || empty( $context['availability']['readable'] ) )
			return [ 'values' => null, 'availability' => $context['availability'], 'error' => $context['error'] ];

		$series_period = self::get_series_period( $context['period'] );

		if ( $series_period === null )
			return [ 'values' => null, 'availability' => $context['availability'], 'error' => 'invalid_period' ];

		$series_availability = isset( Post_Views_Counter()->visits ) ? Post_Views_Counter()->visits->get_visits_availability( $context['period']['availability_range'] ) : [ 'readable' => false, 'read_reason' => 'schema_failed' ];

		if ( empty( $series_availability['readable'] ) )
			return [ 'values' => null, 'availability' => $series_availability, 'error' => null ];

		global $wpdb;

		$params = [ $post_id, $series_period['type'] ];
		$where = [ '`id` = %d', '`type` = %d' ];
		$period_condition = self::get_period_condition( $series_period );
		$where[] = $period_condition['where'];
		$params = array_merge( $params, $period_condition['params'] );

		if ( pvc_post_views_has_content_column() )
			$where[] = '`content` = 0';

		$sql = $wpdb->prepare( 'SELECT `period`, SUM(`visits`) AS `visits` FROM `' . $wpdb->prefix . 'post_views` WHERE ' . implode( ' AND ', $where ) . ' GROUP BY `period` ORDER BY CAST(`period` AS UNSIGNED) ASC', $params );
		$cache_key = self::get_read_cache_key( $sql );
		$rows = wp_cache_get( $cache_key, self::POST_VISITS_CACHE_GROUP );

		if ( $rows === false ) {
			$result = self::get_rows_result( $sql );

			if ( ! $result['success'] )
				return [ 'values' => null, 'availability' => $context['availability'], 'error' => 'read_failed' ];

			$rows = $result['value'];
			wp_cache_add( $cache_key, $rows, self::POST_VISITS_CACHE_GROUP, absint( apply_filters( 'pvc_visits_object_cache_expire', 300 ) ) );
		}

		$values = [];

		foreach ( $rows as $row ) {
			if ( ! isset( $row['period'] ) )
				continue;

			$values[(string) $row['period']] = isset( $row['visits'] ) ? (int) $row['visits'] : 0;
		}

		return [ 'values' => $values, 'availability' => $context['availability'], 'error' => null ];
	}

	/**
	 * Validate the common object, period, and availability contract.
	 *
	 * An empty object scope or an invalid period fails without issuing SQL. The
	 * period is revalidated because `pvc_normalize_visits_period()` is
	 * pluggable.
	 *
	 * @since 1.7.16
	 *
	 * @param array $post_ids Requested post IDs.
	 * @param mixed $period Requested Visit period.
	 * @param array $args Optional Visit query arguments.
	 * @return array
	 */
	private static function get_post_visit_read_context( $post_ids, $period, $args ) {
		$args = is_array( $args ) ? $args : [];
		$post_ids = self::normalize_post_ids( $post_ids );

		if ( empty( $post_ids ) )
			return [ 'availability' => [ 'readable' => false, 'read_reason' => 'invalid_object' ], 'error' => 'invalid_object' ];

		$normalized = self::validate_normalized_period( pvc_normalize_visits_period( $period, $args ) );

		if ( $normalized === null )
			return [ 'availability' => [ 'readable' => false, 'read_reason' => 'invalid_period' ], 'error' => 'invalid_period' ];

		$availability = isset( Post_Views_Counter()->visits ) ? Post_Views_Counter()->visits->get_visits_availability( $normalized['availability_range'] ) : [ 'readable' => false, 'read_reason' => 'schema_failed' ];

		return [ 'period' => $normalized, 'availability' => $availability, 'error' => null ];
	}

	/**
	 * Validate a bounded term or user read without widening the post scope.
	 *
	 * @since 1.7.16
	 *
	 * @param string $content_type Shared content type.
	 * @param array  $content_ids Requested content IDs.
	 * @param mixed  $period Requested Visit period.
	 * @param array  $args Optional Visit query arguments.
	 * @return array
	 */
	private static function get_content_visit_read_context( $content_type, $content_ids, $period, $args ) {
		$content_types = [ 'term' => 1, 'user' => 2 ];
		$content_type = is_scalar( $content_type ) ? sanitize_key( (string) $content_type ) : '';
		$args = is_array( $args ) ? $args : [];
		$content_ids = self::normalize_post_ids( $content_ids );

		if ( ! isset( $content_types[$content_type] ) )
			return [ 'availability' => [ 'readable' => false, 'read_reason' => 'invalid_content_type' ], 'error' => 'invalid_content_type' ];

		if ( empty( $content_ids ) )
			return [ 'availability' => [ 'readable' => false, 'read_reason' => 'invalid_object' ], 'error' => 'invalid_object' ];

		if ( ! pvc_post_views_has_content_column() )
			return [ 'availability' => [ 'readable' => false, 'read_reason' => 'content_unsupported' ], 'error' => 'content_unsupported' ];

		$normalized = self::validate_normalized_period( pvc_normalize_visits_period( $period, $args ) );

		if ( $normalized === null )
			return [ 'availability' => [ 'readable' => false, 'read_reason' => 'invalid_period' ], 'error' => 'invalid_period' ];

		$availability = isset( Post_Views_Counter()->visits ) ? Post_Views_Counter()->visits->get_visits_availability( $normalized['availability_range'] ) : [ 'readable' => false, 'read_reason' => 'schema_failed' ];

		return [
			'period' => $normalized,
			'availability' => $availability,
			'content_type' => $content_type,
			'content' => $content_types[$content_type],
			'error' => null
		];
	}

	/**
	 * Normalize a positive, unique set of post IDs without widening its scope.
	 *
	 * @since 1.7.16
	 *
	 * @param mixed $post_ids Candidate IDs.
	 * @return array
	 */
	private static function normalize_post_ids( $post_ids ) {
		$post_ids = is_array( $post_ids ) ? $post_ids : [ $post_ids ];
		$post_ids = array_values( array_filter( array_unique( array_map( 'absint', $post_ids ) ) ) );

		return $post_ids;
	}

	/**
	 * Build the prepared storage period predicate for a validated selector.
	 *
	 * @since 1.7.16
	 *
	 * @param array $period Validated Visit storage period.
	 * @return array
	 */
	private static function get_period_condition( $period ) {
		if ( (int) $period['type'] === 4 )
			return [ 'where' => '`period` = %s', 'params' => [ 'total' ] ];

		if ( (int) $period['period_from'] === (int) $period['period_to'] )
			return [ 'where' => 'CAST(`period` AS UNSIGNED) = %d', 'params' => [ (int) $period['period_from'] ] ];

		return [ 'where' => 'CAST(`period` AS UNSIGNED) BETWEEN %d AND %d', 'params' => [ (int) $period['period_from'], (int) $period['period_to'] ] ];
	}

	/**
	 * Convert a selected period into the storage rows needed for its chart.
	 *
	 * @since 1.7.16
	 *
	 * @param array $period Validated selected Visit period.
	 * @return array|null
	 */
	private static function get_series_period( $period ) {
		if ( (int) $period['type'] === 4 )
			return $period;

		if ( empty( $period['range']['start'] ) || empty( $period['range']['end'] ) )
			return null;

		$timezone = self::get_timezone();
		$start = ( new DateTimeImmutable( '@' . (int) $period['range']['start'] ) )->setTimezone( $timezone );
		$end = ( new DateTimeImmutable( '@' . (int) $period['range']['end'] ) )->setTimezone( $timezone )->modify( '-1 second' );

		if ( (int) $period['type'] === 3 ) {
			return [
				'type' => 2,
				'period_from' => (int) $start->format( 'Ym' ),
				'period_to' => (int) $end->format( 'Ym' )
			];
		}

		return [
			'type' => 0,
			'period_from' => (int) $start->format( 'Ymd' ),
			'period_to' => (int) $end->format( 'Ymd' )
		];
	}

	/**
	 * Execute a grouped Visit read without turning database failure into zero.
	 *
	 * @since 1.7.16
	 *
	 * @param string $sql Prepared SQL.
	 * @return array{success:bool,value:array}
	 */
	private static function get_rows_result( $sql ) {
		global $wpdb;

		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( $wpdb->last_error !== '' )
			return [ 'success' => false, 'value' => [] ];

		return [ 'success' => true, 'value' => is_array( $rows ) ? $rows : [] ];
	}

	/**
	 * Execute an aggregate Visit query without turning database failure into zero.
	 *
	 * @since 1.7.16
	 *
	 * @param string $sql Prepared SQL.
	 * @return array{success:bool,value:int}
	 */
	public static function get_scalar_result( $sql ) {
		global $wpdb;

		$wpdb->last_error = '';
		$value = $wpdb->get_var( $sql );

		if ( $wpdb->last_error !== '' )
			return [ 'success' => false, 'value' => 0 ];

		return [ 'success' => true, 'value' => $value === null ? 0 : (int) $value ];
	}

	/**
	 * Invalidate every Visit read cache by advancing the shared generation.
	 *
	 * Only Visit result groups are keyed by this generation, so View and source
	 * caches are deliberately left untouched.
	 *
	 * @since 1.7.16
	 *
	 * @return int New cache generation.
	 */
	public static function invalidate_read_cache() {
		if ( function_exists( 'Post_Views_Counter' ) && isset( Post_Views_Counter()->visits ) && Post_Views_Counter()->visits->is_measurement_fence_active() ) {
			Post_Views_Counter()->visits->defer_measurement_fence_work( [ 'type' => 'visit_read_caches' ] );
			return;
		}

		self::$last_invalidation_success = true;
		// Initialize once, then rely on the cache backend's atomic increment so
		// concurrent writers cannot publish the same generation.
		wp_cache_add( self::CACHE_GENERATION_KEY, 0, self::CACHE_GENERATION_GROUP );
		$generation = wp_cache_incr( self::CACHE_GENERATION_KEY, 1, self::CACHE_GENERATION_GROUP );

		if ( $generation !== false ) {
			self::set_read_cache_bypass( false );
			return (int) $generation;
		}

		$before = self::get_cache_generation();
		$token = wp_generate_uuid4();
		$locked = false;

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			if ( wp_cache_add( self::CACHE_GENERATION_LOCK_KEY, $token, self::CACHE_GENERATION_GROUP, 5 ) ) {
				$locked = true;
				break;
			}

			$generation = self::get_cache_generation();

			if ( $generation !== $before ) {
				self::set_read_cache_bypass( false );
				return (int) $generation;
			}

			usleep( 1000 );
		}

		if ( $locked ) {
			$generation = self::get_cache_generation() + 1;
			$updated = wp_cache_set( self::CACHE_GENERATION_KEY, $generation, self::CACHE_GENERATION_GROUP );
			$verified = $updated && self::get_cache_generation() === $generation;

			if ( wp_cache_get( self::CACHE_GENERATION_LOCK_KEY, self::CACHE_GENERATION_GROUP ) === $token )
				wp_cache_delete( self::CACHE_GENERATION_LOCK_KEY, self::CACHE_GENERATION_GROUP );

			if ( $verified ) {
				self::set_read_cache_bypass( false );
				return (int) $generation;
			}
		} else {
			// The lock may be orphaned, so unchanged state must use the same durable fallback.
		}

		if ( self::rotate_durable_cache_token() ) {
			self::set_read_cache_bypass( false );
			return self::get_cache_generation();
		}

		if ( self::force_rotate_durable_cache_token() ) {
			self::set_read_cache_bypass( false );
			return self::get_cache_generation();
		}

		self::set_read_cache_bypass( true );
		$flushed = false;

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			$flushed = true;

			foreach ( [ self::VISITS_CACHE_GROUP, self::POST_VISITS_CACHE_GROUP, self::VIEWS_PER_VISIT_CACHE_GROUP ] as $group )
				$flushed = wp_cache_flush_group( $group ) && $flushed;
		}

		if ( ! $flushed ) {
			self::$last_invalidation_success = false;
			error_log( 'Post Views Counter: Visit read caches could not be retired after a committed write.' );
		}

		return self::get_cache_generation();
	}
}
