<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Columns_Modal class.
 *
 * Handles modal functionality for post view charts in admin columns,
 * including AJAX handlers, asset enqueuing, and HTML rendering.
 *
 * @class Post_Views_Counter_Columns_Modal
 */
class Post_Views_Counter_Columns_Modal {

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_chart_modal_assets' ] );
		add_action( 'wp_ajax_pvc_column_chart', [ $this, 'ajax_column_chart' ] );
	}

	/**
	 * Enqueue chart modal assets on post list screens.
	 *
	 * @param string $page
	 * @return void
	 */
	public function enqueue_chart_modal_assets( $page ) {
		// only on edit.php and upload.php
		if ( ! in_array( $page, [ 'edit.php', 'upload.php' ], true ) )
			return;

		$screen = get_current_screen();
		$pvc = Post_Views_Counter();
		$post_types = (array) $pvc->options['general']['post_types_count'];

		// break if display is not allowed
		if ( ! $pvc->options['display']['post_views_column'] || ! in_array( $screen->post_type, $post_types, true ) )
			return;

		// check if user can see stats
		if ( apply_filters( 'pvc_admin_display_post_views', true ) === false )
			return;

		// enqueue Micromodal
		wp_enqueue_script( 'pvc-micromodal', POST_VIEWS_COUNTER_URL . '/assets/micromodal/micromodal.min.js', [], '0.4.10', true );

		// enqueue Chart.js (already registered)
		wp_enqueue_script( 'pvc-chartjs' );

		// enqueue modal assets
		wp_enqueue_style( 'pvc-admin-columns', POST_VIEWS_COUNTER_URL . '/css/admin-columns.css', [], $pvc->defaults['version'] );
		wp_enqueue_script( 'pvc-admin-columns', POST_VIEWS_COUNTER_URL . '/js/admin-columns.js', [ 'jquery', 'pvc-chartjs', 'pvc-micromodal' ], $pvc->defaults['version'], true );

		// BACKWARD COMPAT: Register legacy handles for version 1.7.3 and earlier
		// Legacy checks for 'pvc-column-modal' handle; keep both registered
		wp_register_style( 'pvc-column-modal', POST_VIEWS_COUNTER_URL . '/css/admin-columns.css', [], $pvc->defaults['version'] );
		wp_register_script( 'pvc-column-modal', POST_VIEWS_COUNTER_URL . '/js/admin-columns.js', [ 'jquery', 'pvc-chartjs', 'pvc-micromodal' ], $pvc->defaults['version'], true );

		$extension_owns_modal = $this->extension_modal_owner_is_loaded();
		$current_extension_owner = $extension_owns_modal && $this->extension_modal_owner_replaces_base_later();

		// The configuration is created once by the base plugin. A compatible modal
		// owner transfers this script data to its controller. Legacy extensions supply
		// their own declaration, so the base must not add a second assignment.
		if ( ! $extension_owns_modal || $current_extension_owner )
			wp_add_inline_script( 'pvc-admin-columns', 'var pvcColumnModal = ' . wp_json_encode( $this->get_modal_config( $extension_owns_modal ) ) . "\n", 'before' );

		// A loaded extension modal owner renders its own compatible footer markup.
		// Do not rely on a released extension removing this callback after registration.
		if ( ! $extension_owns_modal )
			add_action( 'admin_footer', [ $this, 'render_modal_html' ] );
	}

	/**
	 * Build the private configuration shared by the base and extension modal
	 * controllers.
	 *
	 * @param bool $extension_owns_modal Whether a compatible extension footer
	 *                                   owner is loaded.
	 * @return array
	 */
	private function get_modal_config( $extension_owns_modal ) {
		return [
			'ajaxURL'			=> admin_url( 'admin-ajax.php' ),
			'nonce'				=> wp_create_nonce( 'pvc-column-modal' ),
			'handlerNamespace'	=> 'pvcCoreModal',
			'modalOwner'		=> $extension_owns_modal ? 'pro' : 'core',
			'i18n'				=> [
				'loading'				=> __( 'Loading...', 'post-views-counter' ),
				'close'					=> __( 'Close', 'post-views-counter' ),
				'error'					=> __( 'An error occurred while loading data.', 'post-views-counter' ),
				'summary'				=> __( 'Views in this period:', 'post-views-counter' ),
				'retry'					=> __( 'Retry', 'post-views-counter' ),
				'view'					=> __( 'view', 'post-views-counter' ),
				'views'					=> __( 'views', 'post-views-counter' )
			]
		];
	}

	/**
	 * Check whether an instantiated extension modal renderer owns this request.
	 *
	 * Class presence alone is insufficient: partial extension bootstraps must leave
	 * the base modal active until an actual renderer callback is registered.
	 *
	 * @return bool
	 */
	private function extension_modal_owner_is_loaded() {
		global $wp_filter;

		if ( ! class_exists( 'Post_Views_Counter_Pro_Columns_Modal' ) || ! isset( $wp_filter['admin_footer'] ) )
			return false;

		foreach ( $wp_filter['admin_footer']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! is_array( $callback['function'] ) || ! isset( $callback['function'][0], $callback['function'][1] ) )
					continue;

				if ( $callback['function'][1] === 'render_modal_html' && is_a( $callback['function'][0], 'Post_Views_Counter_Pro_Columns_Modal' ) )
					return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the loaded extension owner transfers base script data at
	 * priority 30.
	 *
	 * @return bool
	 */
	private function extension_modal_owner_replaces_base_later() {
		global $wp_filter;

		if ( ! isset( $wp_filter['admin_enqueue_scripts']->callbacks[30] ) )
			return false;

		foreach ( $wp_filter['admin_enqueue_scripts']->callbacks[30] as $callback ) {
			if ( is_array( $callback['function'] ) && isset( $callback['function'][0], $callback['function'][1] ) && $callback['function'][1] === 'remove_base_modal' && is_a( $callback['function'][0], 'Post_Views_Counter_Pro_Columns_Modal' ) )
				return true;
		}

		return false;
	}

	/**
	 * AJAX handler for column chart data.
	 *
	 * @return void
	 */
	public function ajax_column_chart() {
		// permission & nonce check
		if ( ! check_ajax_referer( 'pvc-column-modal', 'nonce', false ) )
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-views-counter' ) ] );

		// get PVC instance
		$pvc = Post_Views_Counter();
		$post_types = (array) $pvc->options['general']['post_types_count'];

		// get post ID
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		
		if ( ! $post_id )
			wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'post-views-counter' ) ] );

		// check post exists
		$post = get_post( $post_id );

		if ( ! $post )
			wp_send_json_error( [ 'message' => __( 'Post not found.', 'post-views-counter' ) ] );

		// break if display is not allowed
		if ( ! $pvc->options['display']['post_views_column'] )
			wp_send_json_error( [ 'message' => __( 'Admin column disabled.', 'post-views-counter' ) ] );

		// ensure post type is tracked
		if ( ! in_array( $post->post_type, $post_types, true ) )
			wp_send_json_error( [ 'message' => __( 'Post type is not tracked.', 'post-views-counter' ) ] );

		// the same rule as the chart link in the list column
		if ( ! $pvc->columns || ! $pvc->columns->current_user_can_view_post_chart( $post ) )
			wp_send_json_error( [ 'message' => __( 'Access denied for this post.', 'post-views-counter' ) ] );

		// check display permission for this specific post
		if ( apply_filters( 'pvc_admin_display_post_views', true, $post_id ) === false )
			wp_send_json_error( [ 'message' => __( 'Access denied for this post.', 'post-views-counter' ) ] );

		// get period (format: YYYYMM or empty for current month)
		if ( isset( $_POST['period'] ) && ! is_scalar( $_POST['period'] ) )
			wp_send_json_error( [ 'message' => __( 'Invalid period.', 'post-views-counter' ) ] );

		$period_str = isset( $_POST['period'] ) && ! empty( $_POST['period'] ) ? preg_replace( '/[^0-9]/', '', wp_unslash( (string) $_POST['period'] ) ) : '';

		// parse period or use current
		$date = $this->get_requested_month( $period_str, $this->get_counting_now() );

		$year = $date->format( 'Y' );
		$month = $date->format( 'm' );
		$last_day = $date->format( 't' );

		wp_send_json_success( $this->get_paired_column_chart_data( $post, (int) $year, (int) $month, (int) $last_day ) );
	}

	/**
	 * Build the Views modal response with the shared metric dataset style.
	 *
	 * The released total_views and period_has_data keys are kept for any
	 * consumer of the legacy payload.
	 *
	 * @param WP_Post $post Post object.
	 * @param int     $year Requested year.
	 * @param int     $month Requested month.
	 * @param int     $last_day Days in the requested month.
	 * @return array
	 */
	private function get_paired_column_chart_data( $post, $year, $month, $last_day ) {
		$pvc = Post_Views_Counter();
		$views = pvc_get_views( [
			'post_id' => $post->ID,
			'post_type' => $post->post_type,
			'fields' => 'date=>views',
			'views_query' => [ 'year' => $year, 'month' => $month ]
		] );
		$data = [
			'post_id' => (int) $post->ID,
			'post_title' => get_the_title( $post->ID ),
			'period' => sprintf( '%04d%02d', $year, $month ),
			'dates_html' => $this->generate_modal_dates( $year, $month ),
			'data' => [
				'labels' => [],
				'dates' => [],
				'datasets' => [
					array_merge( [
						'metric' => 'views',
						'label' => __( 'Views', 'post-views-counter' ),
						'data' => []
					], $pvc->functions->get_metric_dataset_style( 'views', 'line', [ 'fill' => true ] ) )
				]
			],
			'totals' => [ 'views' => 0 ]
		];

		$timezone = wp_timezone();

		for ( $day = 1; $day <= $last_day; $day++ ) {
			$date_key = sprintf( '%04d%02d%02d', $year, $month, $day );
			$timestamp = ( new DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day ), $timezone ) )->getTimestamp();

			$data['data']['labels'][] = $day % 2 === 0 ? '' : $day;
			$data['data']['dates'][] = wp_date( get_option( 'date_format' ), $timestamp, $timezone );
			$data['data']['datasets'][0]['data'][] = isset( $views[$date_key] ) ? (int) $views[$date_key] : 0;
		}

		$data['totals']['views'] = array_sum( $data['data']['datasets'][0]['data'] );
		$data['total_views'] = $data['totals']['views'];
		$data['period_has_data'] = $data['totals']['views'] > 0;
		$data['data'] = Post_Views_Counter()->functions->format_chart_datasets( $data['data'] );

		return $data;
	}

	/**
	 * Generate month navigation for modal.
	 *
	 * @param int $year
	 * @param int $month
	 * @return string
	 */
	private function generate_modal_dates( $year, $month, $now = null ) {
		$timezone = wp_timezone();

		if ( ! ( $now instanceof DateTimeInterface ) )
			$now = $this->get_counting_now();

		$current_date = $this->parse_month( sprintf( '%04d-%02d', $year, $month ) );

		if ( ! $current_date )
			$current_date = $this->parse_month( $now->format( 'Y-m' ) );

		$prev_date = $current_date->modify( '-1 month' );
		$next_date = $current_date->modify( '+1 month' );

		// next is available once its month has started in the counting clock
		$can_go_next = (int) $next_date->format( 'Ym' ) <= (int) $now->format( 'Ym' );

		$html = '<div class="pvc-modal-nav">';
		$html .= '<a href="#" class="pvc-modal-nav-prev" data-period="' . $prev_date->format( 'Ym' ) . '">‹ ' . wp_date( 'F Y', $prev_date->getTimestamp(), $timezone ) . '</a>';
		$html .= '<span class="pvc-modal-nav-current">' . wp_date( 'F Y', $current_date->getTimestamp(), $timezone ) . '</span>';
		
		if ( $can_go_next )
			$html .= '<a href="#" class="pvc-modal-nav-next" data-period="' . $next_date->format( 'Ym' ) . '">' . wp_date( 'F Y', $next_date->getTimestamp(), $timezone ) . ' ›</a>';
		else
			$html .= '<span class="pvc-modal-nav-next pvc-disabled">' . wp_date( 'F Y', $next_date->getTimestamp(), $timezone ) . ' ›</span>';
		
		$html .= '</div>';
		
		return $html;
	}

	/**
	 * Get the current time in the clock that buckets the stored periods.
	 *
	 * @return DateTimeImmutable
	 */
	private function get_counting_now() {
		return new DateTimeImmutable( 'now', Post_Views_Counter_Visits_Query::get_timezone() );
	}

	/**
	 * Get the first day of the requested month, or of the current month in the
	 * counting clock when none (or an invalid one) is requested.
	 *
	 * @param string            $period_str Requested period in YYYYMM format, or empty.
	 * @param DateTimeInterface $now Current time in the counting clock.
	 * @return DateTimeImmutable
	 */
	private function get_requested_month( $period_str, $now ) {
		$date = false;

		if ( $period_str && strlen( $period_str ) === 6 )
			$date = $this->parse_month( substr( $period_str, 0, 4 ) . '-' . substr( $period_str, 4, 2 ) );

		return $date ? $date : $this->parse_month( $now->format( 'Y-m' ) );
	}

	/**
	 * Parse one exact calendar month without inheriting the current day.
	 *
	 * @param string $value Month in Y-m format.
	 * @return DateTimeImmutable|false
	 */
	private function parse_month( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m', $value, wp_timezone() );
		$errors = DateTimeImmutable::getLastErrors();

		if ( ! $date || ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) || $date->format( 'Y-m' ) !== $value )
			return false;

		return $date;
	}

	/**
	 * Render modal HTML in admin footer.
	 *
	 * @return void
	 */
	public function render_modal_html() {
	?>
		<div id="pvc-chart-modal" class="pvc-modal micromodal-slide" aria-hidden="true">
			<div class="pvc-modal__overlay" tabindex="-1" data-micromodal-close>
				<div class="pvc-modal__container" role="dialog" aria-modal="true" aria-labelledby="pvc-modal-title">
					<header class="pvc-modal__header">
						<h2 class="pvc-modal__title" id="pvc-modal-title"></h2>
						<button class="pvc-modal__close" aria-label="<?php esc_attr_e( 'Close', 'post-views-counter' ); ?>" data-micromodal-close></button>
					</header>
					<div class="pvc-modal__content">
						<div class="pvc-modal-content-top">
							<div class="pvc-modal-summary">
								<span class="pvc-modal-views-label"><?php esc_html_e( 'Views in this period:', 'post-views-counter' ); ?></span>
								<span class="pvc-modal-views-data">
									<span class="pvc-modal-count pvc-modal-count-views"></span>
								</span>
							</div>
	                            <div class="pvc-modal-tabs" role="group" aria-label="<?php esc_attr_e( 'Chart period', 'post-views-counter' ); ?>">
								<button type="button" class="pvc-modal-tab pvc-pro" disabled aria-pressed="false"><span><?php _e( 'Year', 'post-views-counter' ); ?></span></button>
								<button type="button" class="pvc-modal-tab active" aria-pressed="true"><span><?php _e( 'Month', 'post-views-counter' ); ?></span></button>
								<button type="button" class="pvc-modal-tab pvc-pro" disabled aria-pressed="false"><span><?php _e( 'Week', 'post-views-counter' ); ?></span></button>
							</div>
						</div>
							<div id="pvc-modal-chart-panel" class="pvc-modal-chart-container">
							    <canvas id="pvc-modal-chart" height="200"></canvas>
								<span class="spinner"></span>
							</div>
							<div class="pvc-modal-status" role="status" aria-live="polite"></div>
                        <div class="pvc-modal-content-middle" style="display: none;">
							<div class="pvc-modal-insights">
                                <div class="pvc-insight pvc-insight-lock pvc-modal-insights-empty">
                                    <span class="pvc-insight-text"><?php _e( 'More insights available', 'post-views-counter' ); ?></span>
									<a href="<?php echo esc_url( Post_Views_Counter()->get_postviewscounter_url( '/upgrade/', 'link', 'upgrade-to-pro', 'admin-column-modal-locked-insight-link', 'free' ) ); ?>" target="_blank"><?php echo esc_html__( 'Upgrade to Pro to unlock it', 'post-views-counter' ); ?></a>
                                </div>
                            </div>
						</div>
						<div class="pvc-modal-content-bottom pvc-modal-dates"></div>
					</div>
				</div>
			</div>
		</div>
	<?php
	}
}
