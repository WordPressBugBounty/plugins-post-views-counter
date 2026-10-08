<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Traffic_Signals class.
 *
 * @class Post_Views_Counter_Traffic_Signals
 */
class Post_Views_Counter_Traffic_Signals {

	/**
	 * Views floor on both sides of the comparison.
	 *
	 * @var int
	 */
	const MIN_VIEWS = 10;

	/**
	 * The Views comparison fires above this change, in percent.
	 *
	 * @var int
	 */
	const VIEWS_CHANGE = 25;

	/**
	 * The Entrances state fires at this change or more, in percent.
	 *
	 * @var int
	 */
	const ENTRANCES_CHANGE = 25;

	/**
	 * Month comparisons read for this request, by period and post ID.
	 *
	 * @var array
	 */
	private static $primed = [];

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// early exit if traffic signals are disabled
		if ( ! $this->is_enabled() )
			return;

		// actions
		add_action( 'admin_init', [ $this, 'register_signals_column' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_signals_assets' ] );
		add_action( 'pvc_after_update_post_views_count', [ $this, 'invalidate_signal_cache' ], 10, 1 );
		add_action( 'pvc_flush_cached_counts', [ $this, 'flush_all_signals_cache' ] );
		add_action( 'pvc_reset_counts', [ $this, 'flush_all_signals_cache' ] );
		add_action( 'transition_post_status', [ $this, 'invalidate_signal_cache_on_status_change' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'invalidate_signal_cache' ], 10, 1 );
		add_filter( 'the_posts', [ $this, 'prime_list_signals' ], 10, 2 );
	}

	/**
	 * Check if traffic signals are enabled.
	 *
	 * Allows filters to disable traffic signals when needed.
	 * Example: add_filter( 'pvc_enable_traffic_signals', '__return_false' );
	 *
	 * @return bool True if enabled, false otherwise
	 */
	private function is_enabled() {
		/**
		 * Filter whether traffic signals should be enabled.
		 *
		 * Filters can use this to disable traffic signals and prevent duplicates.
		 *
		 * @param bool $enabled Whether traffic signals are enabled (default: true)
		 */
		return apply_filters( 'pvc_enable_traffic_signals', true );
	}

	/**
	 * Register traffic signals column for post types.
	 *
	 * @return void
	 */
	public function register_signals_column() {
		// check if traffic signals are enabled
		if ( ! $this->is_enabled() )
			return;

		// get main instance
		$pvc = Post_Views_Counter();

		// is posts views column active?
		if ( ! $pvc->options['display']['post_views_column'] )
			return;

		// get post types
		$post_types = $pvc->options['general']['post_types_count'];

		// any post types?
		if ( ! empty( $post_types ) ) {
			foreach ( $post_types as $post_type ) {
				if ( $post_type !== 'attachment' ) {
					add_filter( 'manage_' . $post_type . '_posts_columns', [ $this, 'add_traffic_signal_column' ], 10, 1 );
					add_filter( 'manage_edit-' . $post_type . '_columns', [ $this, 'add_traffic_signal_column' ], 20 );
					add_action( 'manage_' . $post_type . '_posts_custom_column', [ $this, 'render_traffic_signal_column' ], 10, 2 );
				}
			}
		}
	}

	/**
	 * Add traffic signals column to post list.
	 *
	 * @param array $columns Existing columns
	 * @return array Modified columns
	 */
	public function add_traffic_signal_column( $columns ) {
		// find position of views column
		$views_position = array_search( 'post_views', array_keys( $columns ), true );

		$signal_column = [
			'traffic_signal' => '<span class="pvc-signal-header" data-pvc-tooltip="' . esc_attr__( 'Traffic Signals', 'post-views-counter' ) . '"><svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M14.828 14.828 21 21"/><path d="M21 16v5h-5"/><path d="m21 3-9 9-4-4-6 6"/><path d="M21 8V3h-5"/></svg><span class="screen-reader-text">' . esc_html__( 'Traffic Signals', 'post-views-counter' ) . '</span></span>'
		];

		if ( $views_position !== false ) {
			// insert after views column
			$before = array_slice( $columns, 0, $views_position + 1, true );
			$after = array_slice( $columns, $views_position + 1, null, true );

			$columns = array_merge( $before, $signal_column, $after );
		}

		return $columns;
	}

	/**
	 * Render traffic signal column content.
	 *
	 * @param string $column_name Column name
	 * @param int    $post_id Post ID
	 * @return void
	 */
	public function render_traffic_signal_column( $column_name, $post_id ) {
		if ( $column_name !== 'traffic_signal' ) {
			return;
		}

		// signals disabled after the column was registered: no read, no state
		if ( ! $this->is_enabled() )
			return;

		// check if user can see stats
		if ( apply_filters( 'pvc_admin_display_post_views', true, $post_id ) === false ) {
			return;
		}

		// get signal status
		$signal = $this->detect_signal( $post_id );

		if ( $signal === null ) {
			// smart silence - no unusual activity
			printf(
				'<span class="pvc-signal" role="tooltip" aria-label="%s" data-microtip-position="top"><span class="pvc-insight pvc-insight-silence"></span></span>',
				esc_attr__( 'No unusual activity detected.', 'post-views-counter' )
			);
		} else {
			// anomaly detected - generic signal; an Entrances state looks the same, Free never says which
			printf(
				'<span class="pvc-signal" role="tooltip" aria-label="%s" data-microtip-position="top" data-microtip-size="medium"><span class="pvc-insight pvc-insight-anomaly"></span></span>',
				esc_attr__( 'Unusual traffic pattern detected. More insights available in Post Views Counter Pro.', 'post-views-counter' )
			);
		}
	}

	/**
	 * Read the signals of a posts list page with one query.
	 *
	 * Only rows whose signal is not cached are read; the result is kept for
	 * this request, never in the object cache.
	 *
	 * @param array    $posts Posts returned by the main list query
	 * @param WP_Query $query Query object
	 * @return array Posts
	 */
	public function prime_list_signals( $posts, $query = null ) {
		if ( ! is_admin() || empty( $posts ) || ! ( $query instanceof WP_Query ) || ! $query->is_main_query() || ! $this->is_enabled() )
			return $posts;

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || $screen->base !== 'edit' || empty( $screen->post_type ) || $screen->post_type === 'attachment' )
			return $posts;

		$pvc = Post_Views_Counter();

		if ( ! $pvc->options['display']['post_views_column'] || ! in_array( $screen->post_type, (array) $pvc->options['general']['post_types_count'], true ) )
			return $posts;

		// the Views column is removed when stats are denied, and the signal column with it
		if ( apply_filters( 'pvc_admin_display_post_views', true ) === false )
			return $posts;

		$post_ids = [];

		// read only the rows the cell may show
		foreach ( $posts as $post ) {
			if ( isset( $post->ID ) && apply_filters( 'pvc_admin_display_post_views', true, (int) $post->ID ) !== false )
				$post_ids[] = (int) $post->ID;
		}

		self::prime( $post_ids, $this->get_signal_now() );

		return $posts;
	}

	/**
	 * Read and keep the month comparisons of uncached rows for this request.
	 *
	 * @internal Called for a list page and by tests; not a public API.
	 *
	 * @param array             $post_ids Post IDs
	 * @param DateTimeInterface $now      Current time in the counting clock
	 * @return void
	 */
	public static function prime( $post_ids, $now ) {
		$periods = self::get_signal_periods( $now );
		$misses = [];

		foreach ( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) as $post_id ) {
			if ( wp_cache_get( 'pvc_signal_' . $post_id . '_' . $periods['period'], 'post_views_counter' ) === false )
				$misses[] = $post_id;
		}

		if ( empty( $misses ) )
			return;

		$key = self::get_primed_key( $periods );

		foreach ( self::get_month_comparisons( $misses, $periods, $now ) as $post_id => $values ) {
			self::$primed[$key][$post_id] = $values;
		}
	}

	/**
	 * Forget the comparisons kept for this request.
	 *
	 * @internal For tests.
	 *
	 * @return void
	 */
	public static function reset_primed() {
		self::$primed = [];
	}

	/**
	 * Detect traffic signal using simple Month-over-Month comparison.
	 *
	 * @param int                    $post_id Post ID
	 * @param DateTimeInterface|null $now     Current time in the counting clock
	 * @return array|null Signal data or null if no anomaly
	 */
	private function detect_signal( $post_id, $now = null ) {
		$now = $now instanceof DateTimeInterface ? $now : $this->get_signal_now();
		$periods = self::get_signal_periods( $now );

		// check cache first
		$cache_key = 'pvc_signal_' . $post_id . '_' . $periods['period'];
		$cache_group = 'post_views_counter';
		$cached = wp_cache_get( $cache_key, $cache_group );

		if ( $cached !== false ) {
			// a cached Entrances state lasts only while both ranges stay derived
			if ( is_array( $cached ) && ! empty( $cached['entrances'] ) && ! self::are_entrances_derived( $periods, $now ) ) {
				wp_cache_delete( $cache_key, $cache_group );
				return null;
			}

			return $cached;
		}

		$values = self::get_month_comparison( $post_id, $periods, $now );

		// a failed read is silence, never cached
		if ( $values === false ) {
			return null;
		}

		// current month views: month to date, or its first days on the 29th-31st; null when unproven
		$current_total = $values['current'];

		// previous month views (same number of days as current month-to-date), null when pruned
		$prev_total = $values['previous'];

		if ( self::is_views_anomaly( $current_total, $prev_total ) ) {
			$result = [
				'anomaly' => true,
				'change_percent' => round( ( ( $current_total - $prev_total ) / $prev_total ) * 100 )
			];
		} elseif ( self::evaluate_entrances_state( self::get_entrances_evidence( $values ) ) !== null ) {
			// the state carries no number and no direction
			$result = [ 'entrances' => true ];
		} else {
			$result = null;
		}

		// cache for 1 hour, unless the Entrances read failed
		if ( ! isset( $values['cacheable'] ) || $values['cacheable'] ) {
			wp_cache_set( $cache_key, $result, $cache_group, HOUR_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Whether the Views comparison fires.
	 *
	 * @param int|null $current  Current month Views on the compared days, null when unknown
	 * @param int|null $previous Views on the same days of the previous month, null when unknown
	 * @return bool
	 */
	private static function is_views_anomaly( $current, $previous ) {
		// minimum views threshold on both sides
		if ( ! is_int( $current ) || ! is_int( $previous ) || $current < self::MIN_VIEWS || $previous < self::MIN_VIEWS )
			return false;

		// threshold: more than 25% change (up or down)
		return abs( ( ( $current - $previous ) / $previous ) * 100 ) > self::VIEWS_CHANGE;
	}

	/**
	 * Decide the Entrances state.
	 *
	 * Shown only while the Views comparison is silent: month-to-date Entrances
	 * against the same days of the previous month, on eligible data. A
	 * previous value of 0 is a measured zero (the range is derived) and
	 * triggers a rise without a percentage.
	 *
	 * @internal Shared by Traffic Signals and extension insights; not a public API.
	 *
	 * @param array $evidence {
	 *     @type int|null $views_current      Month-to-date Views
	 *     @type int|null $views_previous     Views of the previous range; null unless its month is complete
	 *     @type int|null $entrances_current  Month-to-date Entrances
	 *     @type int|null $entrances_previous Entrances of the previous range; null unless complete and at parity
	 *     @type bool     $derived_current    Visits derived for the current range
	 *     @type bool     $derived_previous   Visits derived for the previous range
	 * }
	 * @return array|null [ 'direction' => up|down ], or null
	 */
	public static function evaluate_entrances_state( $evidence ) {
		$evidence = is_array( $evidence ) ? $evidence : [];
		$value = static function( $key ) use ( $evidence ) {
			return isset( $evidence[$key] ) && is_int( $evidence[$key] ) ? $evidence[$key] : null;
		};
		$views_current = $value( 'views_current' );
		$views_previous = $value( 'views_previous' );
		$current = $value( 'entrances_current' );
		$previous = $value( 'entrances_previous' );

		// the Views state wins
		if ( $views_current === null || $views_previous === null || self::is_views_anomaly( $views_current, $views_previous ) )
			return null;

		$check = Post_Views_Counter_Entrances_Eligibility::check(
			[
				[ 'views' => $views_current, 'entrances' => $current, 'derived_available' => ! empty( $evidence['derived_current'] ) ],
				[ 'views' => $views_previous, 'entrances' => $previous, 'derived_available' => ! empty( $evidence['derived_previous'] ) ]
			],
			self::MIN_VIEWS
		);

		if ( ! $check['eligible'] || $current === $previous )
			return null;

		// measured-zero trigger: from 0 any rise to the floor is unusual
		if ( $previous > 0 && abs( $current - $previous ) * 100 < self::ENTRANCES_CHANGE * $previous )
			return null;

		return [ 'direction' => $current > $previous ? 'up' : 'down' ];
	}

	/**
	 * Build the Entrances evidence of a month comparison.
	 *
	 * @param array $values Result of get_month_comparison()
	 * @return array
	 */
	private static function get_entrances_evidence( $values ) {
		$entrances = isset( $values['entrances'] ) && is_array( $values['entrances'] ) ? $values['entrances'] : [];

		return [
			'views_current'			=> $values['current'],
			'views_previous'		=> $values['previous'],
			'entrances_current'		=> isset( $entrances['current'] ) ? $entrances['current'] : null,
			'entrances_previous'	=> isset( $entrances['previous'] ) ? $entrances['previous'] : null,
			'derived_current'		=> ! empty( $entrances['derived_current'] ),
			'derived_previous'		=> ! empty( $entrances['derived_previous'] )
		];
	}

	/**
	 * Read a post's month-to-date Views and its equal-day comparison.
	 *
	 * A primed list page answers from memory; otherwise the post is read on its
	 * own with the list statement.
	 *
	 * @internal Shared with the Entrances state; not a public API.
	 *
	 * @param int                    $post_id Post ID
	 * @param array                  $periods Result of get_signal_periods()
	 * @param DateTimeInterface|null $now     Current time in the counting clock
	 * @return array|false See get_month_comparisons(); false when the read failed
	 */
	public static function get_month_comparison( $post_id, $periods, $now = null ) {
		$post_id = (int) $post_id;
		$key = self::get_primed_key( $periods );

		if ( isset( self::$primed[$key] ) && array_key_exists( $post_id, self::$primed[$key] ) )
			return self::$primed[$key][$post_id];

		$now = $now instanceof DateTimeInterface ? $now : new DateTimeImmutable( 'now', Post_Views_Counter_Visits_Query::get_timezone() );
		$values = self::get_month_comparisons( [ $post_id ], $periods, $now );

		return isset( $values[$post_id] ) ? $values[$post_id] : false;
	}

	/**
	 * Read month-to-date Views and their equal-day comparison for posts, in one query.
	 *
	 * The previous range counts only when the previous month's daily rows are
	 * complete: their sum equals its monthly row. The daily cleanup deletes only
	 * daily rows, so a pruned day with Views leaves the two unequal. Entrances
	 * (the `visits` column) are read in the same statement only while both
	 * ranges are derived; the previous range's Entrances also need parity:
	 * the month's daily `visits` sum equals its monthly row's. A failed read
	 * with Entrances falls back to one Views-only read, and nothing from it is
	 * cached.
	 *
	 * When the previous range is shorter than month to date (the 29th-31st after a shorter month), the current side is the same number of first
	 * days of the current month, under the same rule: its daily rows must add
	 * up to its monthly row, and for Entrances their `visits` too. Otherwise
	 * the current value is null and the signal is silent.
	 *
	 * @param array             $post_ids Post IDs
	 * @param array             $periods  Result of get_signal_periods()
	 * @param DateTimeInterface $now      Current time in the counting clock
	 * @return array Values by post ID: [ 'current' => int|null, 'previous' => int|null,
	 *               'entrances' => [ 'current' => int|null, 'previous' => int|null,
	 *               'derived_current' => bool, 'derived_previous' => bool ],
	 *               'cacheable' => bool ], or false per post when the read failed
	 */
	private static function get_month_comparisons( $post_ids, $periods, $now ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) );
		$result = [];

		if ( empty( $post_ids ) )
			return $result;

		$pvc = Post_Views_Counter();
		$no_entrances = [ 'current' => null, 'previous' => null, 'derived_current' => false, 'derived_previous' => false ];

		// no site data yet: nothing to compare
		if ( isset( $pvc->visits ) && method_exists( $pvc->visits, 'is_site_data_accessible' ) && ! $pvc->visits->is_site_data_accessible() ) {
			foreach ( $post_ids as $post_id ) {
				$result[$post_id] = [ 'current' => 0, 'previous' => null, 'entrances' => $no_entrances, 'cacheable' => true ];
			}

			return $result;
		}

		$derived = self::get_entrances_availability( $periods, $now );
		$with_entrances = $derived['current'] && $derived['previous'];
		$rows = self::query_month_comparisons( $post_ids, $periods, $with_entrances );
		$cacheable = true;

		// Entrances must never cost the Views: one Views-only read, nothing cached
		if ( $rows === false && $with_entrances ) {
			$with_entrances = false;
			$cacheable = false;
			$rows = self::query_month_comparisons( $post_ids, $periods, false );
		}

		if ( $rows === false ) {
			foreach ( $post_ids as $post_id ) {
				$result[$post_id] = false;
			}

			return $result;
		}

		foreach ( $post_ids as $post_id ) {
			$row = isset( $rows[$post_id] ) ? $rows[$post_id] : [];
			$sum = static function( $column ) use ( $row ) {
				return (int) ( isset( $row[$column] ) ? $row[$column] : 0 );
			};
			$complete = $sum( 'previous_daily_views' ) === $sum( 'previous_month_views' );
			$current = $sum( 'current_views' );
			$current_entrances = $with_entrances ? $sum( 'current_entrances' ) : null;

			// the current month's first days count only when its daily rows add up to its monthly row
			if ( ! empty( $periods['current'] ) ) {
				$current = $sum( 'current_daily_views' ) === $sum( 'current_views' ) ? $sum( 'current_range_views' ) : null;
				$current_entrances = $current !== null && $with_entrances && $sum( 'current_daily_entrances' ) === $sum( 'current_entrances' ) ? $sum( 'current_range_entrances' ) : null;
			}

			$entrances = $no_entrances;

			if ( $with_entrances ) {
				$entrances = [
					'current'			=> $current_entrances,
					'previous'			=> $complete && $sum( 'previous_daily_entrances' ) === $sum( 'previous_month_entrances' ) ? $sum( 'previous_entrances' ) : null,
					'derived_current'	=> true,
					'derived_previous'	=> true
				];
			}

			$result[$post_id] = [
				'current'	=> $current,
				'previous'	=> $complete ? $sum( 'previous_views' ) : null,
				'entrances'	=> $entrances,
				'cacheable'	=> $cacheable
			];
		}

		return $result;
	}

	/**
	 * Run the month comparison statement for posts.
	 *
	 * @param array $post_ids       Post IDs
	 * @param array $periods        Result of get_signal_periods()
	 * @param bool  $with_entrances Whether to sum `visits` too
	 * @return array|false Rows by post ID, or false when the read failed
	 */
	private static function query_month_comparisons( $post_ids, $periods, $with_entrances ) {
		global $wpdb;

		$from = (string) $periods['comparison'][0];
		$to = (string) $periods['comparison'][1];
		$previous_month = substr( $from, 0, 6 );
		$month_start = DateTimeImmutable::createFromFormat( '!Ymd', $previous_month . '01', new DateTimeZone( 'UTC' ) );
		$month_end = $previous_month . $month_start->format( 't' );
		$content_sql = pvc_post_views_has_content_column() ? ' AND `content` = 0' : '';
		$columns = [ 'count' => 'views' ];

		// `visits` is in no index; keep the per-post index instead of the (type, period) primary key
		if ( $with_entrances )
			$columns['visits'] = 'entrances';

		// on the 29th-31st after a shorter month: the current month's first days and its daily sum
		$current = empty( $periods['current'] ) ? null : [ (string) $periods['current'][0], (string) $periods['current'][1] ];
		$current_end = $current === null ? '' : $periods['period'] . DateTimeImmutable::createFromFormat( '!Ymd', $periods['period'] . '01', new DateTimeZone( 'UTC' ) )->format( 't' );
		$select = [];
		$params = [];

		foreach ( $columns as $column => $name ) {
			$select[] = 'COALESCE( SUM( CASE WHEN `type` = 2 AND `period` = %s THEN `' . $column . '` ELSE 0 END ), 0 ) AS current_' . $name;
			$select[] = 'COALESCE( SUM( CASE WHEN `type` = 0 AND `period` >= %s AND `period` <= %s THEN `' . $column . '` ELSE 0 END ), 0 ) AS previous_' . $name;
			$params = array_merge( $params, [ (string) $periods['period'], $from, $to ] );

			// the daily rows read are the previous month's only, unless the current month's are read too
			if ( $current === null )
				$select[] = 'COALESCE( SUM( CASE WHEN `type` = 0 THEN `' . $column . '` ELSE 0 END ), 0 ) AS previous_daily_' . $name;
			else {
				$select[] = 'COALESCE( SUM( CASE WHEN `type` = 0 AND `period` >= %s AND `period` <= %s THEN `' . $column . '` ELSE 0 END ), 0 ) AS previous_daily_' . $name;
				$params = array_merge( $params, [ $previous_month . '01', $month_end ] );
			}

			$select[] = 'COALESCE( SUM( CASE WHEN `type` = 2 AND `period` = %s THEN `' . $column . '` ELSE 0 END ), 0 ) AS previous_month_' . $name;
			$params[] = $previous_month;

			if ( $current !== null ) {
				$select[] = 'COALESCE( SUM( CASE WHEN `type` = 0 AND `period` >= %s AND `period` <= %s THEN `' . $column . '` ELSE 0 END ), 0 ) AS current_range_' . $name;
				$select[] = 'COALESCE( SUM( CASE WHEN `type` = 0 AND `period` >= %s AND `period` <= %s THEN `' . $column . '` ELSE 0 END ), 0 ) AS current_daily_' . $name;
				$params = array_merge( $params, [ $current[0], $current[1], $periods['period'] . '01', $current_end ] );
			}
		}

		$sql = $wpdb->prepare(
			'SELECT `id`,
				' . implode( ",\n\t\t\t\t", $select ) . '
			FROM `' . $wpdb->prefix . 'post_views`' . ( $with_entrances ? ' FORCE INDEX (`id_type_period_count`)' : '' ) . '
			WHERE `id` IN (' . implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) ) . ')' . $content_sql . '
			AND ( ( `type` = 2 AND `period` IN ( %s, %s ) ) OR ( `type` = 0 AND `period` >= %s AND `period` <= %s )' . ( $current === null ? '' : ' OR ( `type` = 0 AND `period` >= %s AND `period` <= %s )' ) . ' )
			GROUP BY `id`',
			array_merge( $params, $post_ids, [ (string) $periods['period'], $previous_month, $previous_month . '01', $month_end ], $current === null ? [] : [ $periods['period'] . '01', $current_end ] )
		);

		// a table without the index fails here and takes the Views-only fallback
		$suppress = $with_entrances ? $wpdb->suppress_errors( true ) : null;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$failed = $wpdb->last_error !== '' || ! is_array( $rows );

		if ( $with_entrances )
			$wpdb->suppress_errors( $suppress );

		if ( $failed )
			return false;

		$by_id = [];

		foreach ( $rows as $row ) {
			if ( isset( $row['id'] ) )
				$by_id[(int) $row['id']] = $row;
		}

		return $by_id;
	}

	/**
	 * Whether Visits are derived for both compared ranges.
	 *
	 * @param array             $periods Result of get_signal_periods()
	 * @param DateTimeInterface $now     Current time in the counting clock
	 * @return array [ 'current' => bool, 'previous' => bool ]
	 */
	private static function get_entrances_availability( $periods, $now ) {
		$current = Post_Views_Counter_Entrances_Eligibility::get_day_range_availability( $periods['period'] . '01', empty( $periods['current'] ) ? $now->format( 'Ymd' ) : (string) $periods['current'][1], $now );
		$previous = Post_Views_Counter_Entrances_Eligibility::get_day_range_availability( $periods['comparison'][0], $periods['comparison'][1], $now );

		return [ 'current' => $current['derived_available'], 'previous' => $previous['derived_available'] ];
	}

	/**
	 * Whether a cached Entrances state still stands on derived ranges.
	 *
	 * @param array             $periods Result of get_signal_periods()
	 * @param DateTimeInterface $now     Current time in the counting clock
	 * @return bool
	 */
	private static function are_entrances_derived( $periods, $now ) {
		$derived = self::get_entrances_availability( $periods, $now );

		return $derived['current'] && $derived['previous'];
	}

	/**
	 * Key of the comparisons kept for this request.
	 *
	 * @param array $periods Result of get_signal_periods()
	 * @return string
	 */
	private static function get_primed_key( $periods ) {
		return get_current_blog_id() . ':' . $periods['period'] . ':' . implode( '-', $periods['comparison'] ) . ( empty( $periods['current'] ) ? '' : ':' . implode( '-', $periods['current'] ) );
	}

	/**
	 * Get the current time in the clock that buckets the stored periods.
	 *
	 * @return DateTimeImmutable
	 */
	private function get_signal_now() {
		return new DateTimeImmutable( 'now', Post_Views_Counter_Visits_Query::get_timezone() );
	}

	/**
	 * Get the current month and its equal-day comparison range.
	 *
	 * The previous month is stepped back from the first day of the current
	 * month, so the 29th-31st cannot roll over into the current month. Both
	 * sides hold the same days: on the 29th-31st after a shorter
	 * month the current side stops where the previous month does.
	 *
	 * @param DateTimeInterface $now Current time in the counting clock
	 * @return array Current period (Ym), comparison day range [Ymd, Ymd], and
	 *               the current day range [Ymd, Ymd] when it is shorter than
	 *               month to date, else null (the monthly row)
	 */
	private static function get_signal_periods( $now ) {
		$month_start = DateTimeImmutable::createFromFormat( '!Y-m-d', $now->format( 'Y-m-01' ), $now->getTimezone() );
		$prev_month = $month_start->modify( '-1 month' );
		$day = (int) $now->format( 'j' );

		// compare against the same number of days from last month (clamp to last day)
		$end_day = min( $day, (int) $prev_month->format( 't' ) );
		$end = str_pad( (string) $end_day, 2, '0', STR_PAD_LEFT );

		return [
			'period'		=> $now->format( 'Ym' ),
			'comparison'	=> [
				(int) ( $prev_month->format( 'Ym' ) . '01' ),
				(int) ( $prev_month->format( 'Ym' ) . $end )
			],
			'current'		=> $end_day < $day ? [ (int) ( $now->format( 'Ym' ) . '01' ), (int) ( $now->format( 'Ym' ) . $end ) ] : null
		];
	}

	/**
	 * Enqueue traffic signals assets on post list screens.
	 *
	 * @param string $hook Current admin page hook
	 * @return void
	 */
	public function enqueue_signals_assets( $hook ) {
		// check if traffic signals are enabled
		if ( ! $this->is_enabled() )
			return;

		// only on post list screens
		if ( ! in_array( $hook, [ 'edit.php', 'upload.php' ], true ) )
			return;

		$screen = get_current_screen();
		$pvc = Post_Views_Counter();
		$post_types = (array) $pvc->options['general']['post_types_count'];

		// check if traffic signals should be displayed
		if ( ! $screen || ! $screen->post_type )
			return;

		// check if this post type has view counting enabled
		if ( ! in_array( $screen->post_type, $post_types, true ) )
			return;

		// enqueue Microtip CSS for tooltips
		wp_enqueue_style( 'pvc-microtip', POST_VIEWS_COUNTER_URL . '/assets/microtip/microtip.min.css', [], '1.0.0' );
		
		// enqueue admin-columns CSS for signal icons (if not already enqueued by modal)
		if ( ! wp_style_is( 'pvc-admin-columns', 'enqueued' ) ) {
			wp_enqueue_style( 'pvc-admin-columns', POST_VIEWS_COUNTER_URL . '/css/admin-columns.css', [], $pvc->defaults['version'] );
		}
	}

	/**
	 * Invalidate signal cache when view counts update.
	 *
	 * @param int $post_id Post ID
	 * @return void
	 */
	public function invalidate_signal_cache( $post_id ) {
		// older periods' entries expire within their one-hour lifetime
		$periods = self::get_signal_periods( $this->get_signal_now() );
		$cache_key = 'pvc_signal_' . $post_id . '_' . $periods['period'];
		$cache_group = 'post_views_counter';
		wp_cache_delete( $cache_key, $cache_group );
		unset( self::$primed[self::get_primed_key( $periods )][(int) $post_id] );
	}

	/**
	 * Flush all signals cache (called on period resets, cache flushes).
	 *
	 * @return void
	 */
	public function flush_all_signals_cache() {
		// comparisons read earlier in this request predate the flush or reset
		self::$primed = [];

		// WordPress doesn't support wildcard cache deletion
		// We rely on natural cache expiration (1 hour) for bulk invalidation
		
		// For persistent object caches (Redis, Memcached), implement via plugin-specific flush
		if ( wp_using_ext_object_cache() ) {
			do_action( 'pvc_flush_signals_cache' );
		}
	}

	/**
	 * Invalidate signal cache when post status changes.
	 *
	 * @param string  $new_status New post status
	 * @param string  $old_status Old post status
	 * @param WP_Post $post Post object
	 * @return void
	 */
	public function invalidate_signal_cache_on_status_change( $new_status, $old_status, $post ) {
		// only invalidate if status actually changed
		if ( $new_status !== $old_status ) {
			$this->invalidate_signal_cache( $post->ID );
		}
	}
}
