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

		// break if display is not allowed
		if ( ! $pvc->options['display']['post_views_column'] || ! in_array( $screen->post_type, $pvc->options['general']['post_types_count'], true ) )
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

		// BACKWARD COMPAT: Register legacy handles for Pro 1.7.3 and earlier
		// Pro checks for 'pvc-column-modal' handle; keep both registered
		wp_register_style( 'pvc-column-modal', POST_VIEWS_COUNTER_URL . '/css/admin-columns.css', [], $pvc->defaults['version'] );
		wp_register_script( 'pvc-column-modal', POST_VIEWS_COUNTER_URL . '/js/admin-columns.js', [ 'jquery', 'pvc-chartjs', 'pvc-micromodal' ], $pvc->defaults['version'], true );

		// localize script
		wp_add_inline_script( 'pvc-admin-columns', 'var pvcColumnModal = ' . wp_json_encode( [
			'ajaxURL'	=> admin_url( 'admin-ajax.php' ),
			'nonce'		=> wp_create_nonce( 'pvc-column-modal' ),
			'i18n'		=> [
				'loading'		=> __( 'Loading...', 'post-views-counter' ),
				'close'			=> __( 'Close', 'post-views-counter' ), 
				'error'			=> __( 'An error occurred while loading data.', 'post-views-counter' ),
				'summary'		=> __( 'Total views in this period:', 'post-views-counter' ),
				'view'			=> __( 'view', 'post-views-counter' ),
				'views'			=> __( 'views', 'post-views-counter' )
			]
		] ) . "\n", 'before' );

		// add modal HTML to footer
		add_action( 'admin_footer', [ $this, 'render_modal_html' ] );
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
		if ( ! in_array( $post->post_type, $pvc->options['general']['post_types_count'], true ) )
			wp_send_json_error( [ 'message' => __( 'Post type is not tracked.', 'post-views-counter' ) ] );

		// check display permission for this specific post
		if ( apply_filters( 'pvc_admin_display_post_views', true, $post_id ) === false )
			wp_send_json_error( [ 'message' => __( 'Access denied for this post.', 'post-views-counter' ) ] );

		// get period (format: YYYYMM or empty for current month)
		$period_str = isset( $_POST['period'] ) && ! empty( $_POST['period'] ) ? preg_replace( '/[^0-9]/', '', $_POST['period'] ) : '';

		// parse period or use current
		if ( $period_str && strlen( $period_str ) === 6 ) {
			$year = substr( $period_str, 0, 4 );
			$month = substr( $period_str, 4, 2 );
			$date = DateTime::createFromFormat( 'Y-m', $year . '-' . $month, wp_timezone() );
			
			if ( ! $date )
				$date = new DateTime( 'now', wp_timezone() );
		} else {
			$date = new DateTime( 'now', wp_timezone() );
		}

		$year = $date->format( 'Y' );
		$month = $date->format( 'm' );
		$last_day = $date->format( 't' );

		// fetch views data
		$views = pvc_get_views( [
			'post_id'		=> $post_id,
			'post_type'		=> $post->post_type,
			'fields'		=> 'date=>views',
			'views_query'	=> [
				'year'	=> (int) $year,
				'month'	=> (int) $month
			]
		] );

		// get colors
		$colors = $pvc->functions->get_colors();

		// prepare response data
		$data = [
			'post_id'	=> $post_id,
			'post_title'=> get_the_title( $post_id ),
			'period'	=> $year . $month,
			'design'		=> [
				'fill'					=> true,
				'backgroundColor'		=> 'rgba(' . $colors['r'] . ',' . $colors['g'] . ',' . $colors['b'] . ', 0.2)',
				'borderColor'			=> 'rgba(' . $colors['r'] . ',' . $colors['g'] . ',' . $colors['b'] . ', 1)',
				'borderWidth'			=> 1.2,
				'borderDash'			=> [],
				'pointBorderColor'		=> 'rgba(' . $colors['r'] . ',' . $colors['g'] . ',' . $colors['b'] . ', 1)',
				'pointBackgroundColor'	=> 'rgba(255, 255, 255, 1)',
				'pointBorderWidth'		=> 1.2
			],
			'data'		=> [
				'labels'	=> [],
				'dates'		=> [],
				'datasets'	=> [
					[
						'label'	=> get_the_title( $post_id ),
						'data'	=> []
					]
				]
			]
		];

		// generate dates and data
		for ( $i = 1; $i <= $last_day; $i++ ) {
			$date_key = $year . $month . str_pad( $i, 2, '0', STR_PAD_LEFT );
			
			// labels: show only odd days
			$data['data']['labels'][] = ( $i % 2 === 0 ? '' : $i );
			
			// formatted dates for tooltips
			$data['data']['dates'][] = date_i18n( get_option( 'date_format' ), strtotime( $year . '-' . $month . '-' . str_pad( $i, 2, '0', STR_PAD_LEFT ) ) );
			
			// view count
			$data['data']['datasets'][0]['data'][] = isset( $views[$date_key] ) ? (int) $views[$date_key] : 0;
		}

		// calculate total views for the period
		$data['total_views'] = array_sum( $data['data']['datasets'][0]['data'] );

		// check if there is any period-specific data
		$period_has_data = false;
		foreach ( $data['data']['datasets'][0]['data'] as $val ) {
			if ( (int) $val > 0 ) {
				$period_has_data = true;
				break;
			}
		}

		$data['period_has_data'] = $period_has_data;

		// generate date navigation HTML
		$data['dates_html'] = $this->generate_modal_dates( (int) $year, (int) $month );

		wp_send_json_success( $data );
	}

	/**
	 * Generate month navigation for modal.
	 *
	 * @param int $year
	 * @param int $month
	 * @return string
	 */
	private function generate_modal_dates( $year, $month ) {
		// previous month
		$prev_date = DateTime::createFromFormat( 'Y-m', $year . '-' . $month, wp_timezone() );
		$prev_date->modify( '-1 month' );
		
		// next month
		$next_date = DateTime::createFromFormat( 'Y-m', $year . '-' . $month, wp_timezone() );
		$next_date->modify( '+1 month' );
		
		// current
		$current_date = DateTime::createFromFormat( 'Y-m', $year . '-' . $month, wp_timezone() );
		
		// check if next is in the future
		$now = new DateTime( 'now', wp_timezone() );
		$can_go_next = $next_date <= $now;
		
		$html = '<div class="pvc-modal-nav">';
		$html .= '<a href="#" class="pvc-modal-nav-prev" data-period="' . $prev_date->format( 'Ym' ) . '">‹ ' . date_i18n( 'F Y', $prev_date->getTimestamp() ) . '</a>';
		$html .= '<span class="pvc-modal-nav-current">' . date_i18n( 'F Y', $current_date->getTimestamp() ) . '</span>';
		
		if ( $can_go_next )
			$html .= '<a href="#" class="pvc-modal-nav-next" data-period="' . $next_date->format( 'Ym' ) . '">' . date_i18n( 'F Y', $next_date->getTimestamp() ) . ' ›</a>';
		else
			$html .= '<span class="pvc-modal-nav-next pvc-disabled">' . date_i18n( 'F Y', $next_date->getTimestamp() ) . ' ›</span>';
		
		$html .= '</div>';
		
		return $html;
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
								<span class="pvc-modal-views-label"></span>
								<span class="pvc-modal-views-data">
									<span class="pvc-modal-count"></span>
								</span>
							</div>
                            <div class="pvc-modal-tabs" role="tablist">
								<button type="button" class="pvc-modal-tab pvc-pro" disabled><span><?php _e( 'Year', 'post-views-counter' ); ?></span></button>
								<button type="button" class="pvc-modal-tab active"><span><?php _e( 'Month', 'post-views-counter' ); ?></span></button>
								<button type="button" class="pvc-modal-tab pvc-pro" disabled><span><?php _e( 'Week', 'post-views-counter' ); ?></span></button>
							</div>
						</div>
						<div class="pvc-modal-chart-container">
						    <canvas id="pvc-modal-chart" height="200"></canvas>
							<span class="spinner"></span>
						</div>
                        <div class="pvc-modal-content-middle" style="display: none;">
							<div class="pvc-modal-insights">
                                <div class="pvc-insight pvc-insight-lock pvc-modal-insights-empty">
                                    <span class="pvc-insight-text"><?php _e( 'More insights available', 'post-views-counter' ); ?></span>
                                    <a href="https://postviewscounter.com/upgrade/?utm_source=post-views-counter-lite&utm_medium=link&utm_campaign=unlock-insights" target="_blank"><?php echo esc_html__( 'Upgrade to Pro to unlock it', 'post-views-counter' ); ?></a>
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
