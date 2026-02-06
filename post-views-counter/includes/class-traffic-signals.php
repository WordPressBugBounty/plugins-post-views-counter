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
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// early exit if traffic signals are disabled (e.g., by Pro plugin)
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
	}

	/**
	 * Check if traffic signals are enabled.
	 *
	 * Allows Pro plugin (or other extensions) to disable Free signals via filter.
	 * Example: add_filter( 'pvc_enable_traffic_signals', '__return_false' );
	 *
	 * @return bool True if enabled, false otherwise
	 */
	private function is_enabled() {
		/**
		 * Filter whether traffic signals should be enabled.
		 *
		 * Pro plugin uses this to disable Free signals and prevent duplicates.
		 * Site owners can override by removing the Pro filter if they want both.
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
		// check if traffic signals are enabled (Pro may disable this)
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
			'traffic_signal' => '<span class="pvc-signal-header" title="' . esc_attr__( 'Traffic Signals', 'post-views-counter' ) . '"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M14.828 14.828 21 21"/><path d="M21 16v5h-5"/><path d="m21 3-9 9-4-4-6 6"/><path d="M21 8V3h-5"/></svg><span class="screen-reader-text">' . esc_html__( 'Traffic Signals', 'post-views-counter' ) . '</span></span>'
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
			// anomaly detected - generic signal
			printf(
				'<span class="pvc-signal" role="tooltip" aria-label="%s" data-microtip-position="top" data-microtip-size="medium"><span class="pvc-insight pvc-insight-anomaly"></span></span>',
				esc_attr__( 'Unusual traffic pattern detected. More insights available in Post Views Counter Pro.', 'post-views-counter' )
			);
		}
	}

	/**
	 * Detect traffic signal using simple Month-over-Month comparison.
	 *
	 * @param int $post_id Post ID
	 * @return array|null Signal data or null if no anomaly
	 */
	private function detect_signal( $post_id ) {
		// check cache first
		$cache_key = 'pvc_signal_' . $post_id;
		$cache_group = 'post_views_counter';
		$cached = wp_cache_get( $cache_key, $cache_group );

		if ( $cached !== false ) {
			return $cached;
		}

		// get current month views
		$current_date = new DateTime();
		$current_period = $current_date->format( 'Ym' );

		$current_total = (int) pvc_get_post_views( $post_id, $current_period );

		// minimum views threshold
		if ( $current_total < 10 ) {
			wp_cache_set( $cache_key, null, $cache_group, HOUR_IN_SECONDS );
			return null;
		}

		// get previous month views (same number of days as current month-to-date)
		$prev_date = clone $current_date;
		$prev_date->modify( '-1 month' );
		$prev_year = $prev_date->format( 'Y' );
		$prev_month = $prev_date->format( 'm' );
		$prev_last_day = (int) $prev_date->format( 't' );
		$current_day = (int) $current_date->format( 'd' );
		// compare against the same number of days from last month (clamp to last day)
		$end_day = min( $current_day, $prev_last_day );

		$prev_start = (int) ( $prev_year . $prev_month . '01' );
		$prev_end = (int) ( $prev_year . $prev_month . str_pad( (string) $end_day, 2, '0', STR_PAD_LEFT ) );

		$prev_total = (int) pvc_get_post_views(
			$post_id,
			'',
			[
				'type'         => 0,
				'period_range' => [ $prev_start, $prev_end ]
			]
		);

		// need both periods to compare
		if ( $prev_total < 10 ) {
			wp_cache_set( $cache_key, null, $cache_group, HOUR_IN_SECONDS );
			return null;
		}

		// calculate change percentage
		$change_percent = ( ( $current_total - $prev_total ) / $prev_total ) * 100;

		// threshold: 25% change (up or down)
		if ( abs( $change_percent ) > 25 ) {
			$result = [
				'anomaly' => true,
				'change_percent' => round( $change_percent )
			];
		} else {
			$result = null;
		}

		// cache for 1 hour
		wp_cache_set( $cache_key, $result, $cache_group, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Enqueue traffic signals assets on post list screens.
	 *
	 * @param string $hook Current admin page hook
	 * @return void
	 */
	public function enqueue_signals_assets( $hook ) {
		// check if traffic signals are enabled (Pro may disable this)
		if ( ! $this->is_enabled() )
			return;

		// only on post list screens
		if ( ! in_array( $hook, [ 'edit.php', 'upload.php' ], true ) )
			return;

		$screen = get_current_screen();
		$pvc = Post_Views_Counter();

		// check if traffic signals should be displayed
		if ( ! $screen || ! $screen->post_type )
			return;

		// check if this post type has view counting enabled
		if ( ! in_array( $screen->post_type, $pvc->options['general']['post_types_count'], true ) )
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
		$cache_key = 'pvc_signal_' . $post_id;
		$cache_group = 'post_views_counter';
		wp_cache_delete( $cache_key, $cache_group );
	}

	/**
	 * Flush all signals cache (called on period resets, cache flushes).
	 *
	 * @return void
	 */
	public function flush_all_signals_cache() {
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
