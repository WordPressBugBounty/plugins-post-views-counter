<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Dashboard class.
 *
 * @class Post_Views_Counter_Dashboard
 */
class Post_Views_Counter_Dashboard {

	/**
	 * The insight notice list this Core renders.
	 *
	 * A paired chart item carries a box that the dashboard script fills from a
	 * widget payload's `insight_notices` on every successful load, and
	 * Post_Views_Counter_Dashboard_Comparison is available. An extension
	 * detects it on the loaded dashboard object's class, never by version.
	 *
	 * @since 1.7.16
	 *
	 * @var int
	 */
	const NOTICES_FORMAT = 1;

	/**
	 * Fewest Views on each side of the comparison notice. This Core constant
	 * is separate from the Traffic Signal floor.
	 *
	 * @internal
	 *
	 * @since 1.7.16
	 *
	 * @var int
	 */
	const FREE_NOTICE_MIN_VIEWS = 10;

	private $widget_items = [];

	/**
	 * Current time of the period comparison; null is now. Set only by tests.
	 *
	 * @var DateTimeInterface|null
	 */
	private $now = null;

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'admin_init', [ $this, 'init_admin_dashboard' ] );
		add_action( 'pvc_dashboard_widget_content_top', [ $this, 'render_widget_headline' ] );
	}

	/**
	 * Dashboard initialization.
	 *
	 * @global string $pagenow
	 *
	 * @return void
	 */
	public function init_admin_dashboard() {
		global $pagenow;

		// setup widget items
		$this->setup_widget_items();

		// do it only on dashboard page
		if ( $pagenow === 'index.php' ) {
			// filter user_can_see_stats
			if ( ! apply_filters( 'pvc_user_can_see_stats', current_user_can( 'publish_posts' ) ) )
				return;

			add_action( 'wp_dashboard_setup', [ $this, 'wp_dashboard_setup' ], 1 );
			add_action( 'admin_enqueue_scripts', [ $this, 'admin_scripts_styles' ] );
		// ajax endpoints
		} elseif ( $pagenow === 'admin-ajax.php' ) {
			add_action( 'wp_ajax_pvc_dashboard_post_most_viewed', [ $this, 'dashboard_post_most_viewed' ] );
			add_action( 'wp_ajax_pvc_dashboard_post_views_chart', [ $this, 'dashboard_post_views_chart' ] );
			add_action( 'wp_ajax_pvc_dashboard_user_options', [ $this, 'update_dashboard_user_options' ] );
		}
	}

	/**
	 * Add dashboard widget.
	 *
	 * @return void
	 */
	public function wp_dashboard_setup() {
		// add dashboard widget
		wp_add_dashboard_widget( 'pvc_dashboard', __( 'Post Views', 'post-views-counter' ), [ $this, 'dashboard_widget' ] );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @return void
	 */
	public function admin_scripts_styles() {
		// get main instance
		$pvc = Post_Views_Counter();

		// styles
		wp_enqueue_style( 'pvc-admin-dashboard', POST_VIEWS_COUNTER_URL . '/css/admin-dashboard.css', [], $pvc->defaults['version'] );
		wp_enqueue_style( 'pvc-microtip', POST_VIEWS_COUNTER_URL . '/assets/microtip/microtip.min.css', [], '1.0.0' );

		// scripts
		wp_enqueue_script( 'pvc-admin-dashboard', POST_VIEWS_COUNTER_URL . '/js/admin-dashboard.js', [ 'jquery', 'pvc-chartjs', 'pvc-metric-toggle' ], $pvc->defaults['version'], true );

		// prepare script data
		$script_data = [
			'ajaxURL'	=> admin_url( 'admin-ajax.php' ),
			'nonce'		=> wp_create_nonce( 'pvc-dashboard-widget' ),
			'nonceUser'	=> wp_create_nonce( 'pvc-dashboard-user-options' ),
			'i18n'		=> [
				'loadError' => __( 'Failed to load this widget.', 'post-views-counter' ),
				'retry' => __( 'Retry', 'post-views-counter' ),
			]
		];

		$script_data = apply_filters( 'pvc_admin_dashboard_script_data', $script_data );

		wp_add_inline_script( 'pvc-admin-dashboard', 'var pvcArgs = ' . wp_json_encode( $script_data ) . ";\n", 'before' );
	}

	/**
	 * Setup dashboard widget items.
	 *
	 * @return void
	 */
	private function setup_widget_items() {
		// standard items
		$items = [
			[
				'id'			=> 'post-views',
				'title'			=> __( 'Post Views', 'post-views-counter' ),
				'description'	=> __( 'Displays a chart of most viewed post types.', 'post-views-counter' ),
				// The first-party chart no longer derives a canvas height from
				// the legend size. It lives in a fixed CSS wrapper so the plotted
				// graph is the same height in every metric mode while the
				// controls and the dataset legend grow the widget around it.
				'content'		=> '<div class="pvc-chart-wrapper"><canvas id="pvc-post-views-chart"></canvas></div>',
				'metrics'		=> true,
				'position'		=> 2
			],
			[
				'id'			=> 'post-most-viewed',
				'title'			=> __( 'Top Posts', 'post-views-counter' ),
				'description'	=> __( 'Displays a list of most viewed single posts or pages.', 'post-views-counter' ),
				'content'		=> '<div id="pvc-post-most-viewed-content" class="pvc-table-responsive"></div>',
				'position'		=> 3
			]
		];

		// filter items, do not allow to remove main items
		$new_items = apply_filters( 'pvc_dashboard_widget_items', [] );

		// any new items?
		if ( is_array( $new_items ) && ! empty( $new_items ) ) {
			foreach ( $new_items as $item ) {
				// add new item
				array_push( $items, $item );
			}
		}

		// sort dashboard items by position
		array_multisort( array_column( $items, 'position' ), SORT_ASC, SORT_NUMERIC, $items );

		// set widget items
		$this->widget_items = $items;
	}

	public function get_widget_items() {
		// return widget items
		return $this->widget_items;	
	}

	/**
	 * Calculate canvas height based on number of legend items.
	 *
	 * @param array $data
	 * @param bool $expression
	 * @return int
	 */
	public function calculate_canvas_size( $data, $expression = true ) {
		if ( $expression && ! empty( $data ) ) {
			// treat every 4 legend items as 1 line - 23 pixels
			$height = 23 * ( (int) ceil( count( $data ) / 4 ) - 1 );
		} else
			$height = 0;

		return (int) ( 170 + $height );
	}

	/**
	 * Render dashboard widget.
	 *
	 * @return void
	 */
	public function dashboard_widget() {
		// get user options
		$user_options = get_user_meta( get_current_user_id(), 'pvc_dashboard', true );

		// check whether the related features are available
		$is_pro = class_exists( 'Post_Views_Counter_Pro' );

		// empty options?
		if ( empty( $user_options ) || ! is_array( $user_options ) )
			$user_options = [];

		// sanitize options
		$user_options = map_deep( $user_options, 'sanitize_text_field' );

		// get menu items
		$menu_items = ! empty( $user_options['menu_items'] ) ? $user_options['menu_items'] : [];

		// get widget items
		$items = $this->widget_items;

		$html = '
		<div id="pvc-dashboard-accordion" class="pvc-accordion">';

		foreach ( $items as $item ) {
			$html .= $this->generate_dashboard_widget_item( $item, $menu_items );
		}

		if ( ! $is_pro ) {
			$html .= '
			<div class="pvc-dashboard-unlock">
				<div class="pvc-dashboard-unlock-content">
					<h3>' . esc_html__( 'More Insights', 'post-views-counter' ) . '</h3>
					<ul>
						<li>' . esc_html__( 'Go beyond basic post views.', 'post-views-counter' ) . '</li>
						<li>' . esc_html__( 'See site views, referrers, popular searches, top users, and more.', 'post-views-counter' ) . '</li>
					</ul>
					<a href="' . esc_url( Post_Views_Counter()->get_postviewscounter_url( '/upgrade/', 'button', 'upgrade-to-pro', 'dashboard-unlock-button', 'free' ) ) . '" class="button button-secondary" target="_blank">' . esc_html__( 'Unlock more insights', 'post-views-counter' ) . ' &rarr;</a>
				</div>
			</div>';
		}

		$html .= '
		<div class="pvc-dashboard-block"><span>' . esc_html__( 'Powered by', 'post-views-counter' ) . ' <a href="' . esc_url( Post_Views_Counter()->get_postviewscounter_url( '/', 'link', 'powered-by', 'dashboard-powered-by-link', 'free' ) ) . '" target="_blank">Post Views Counter</a></span></div>
		</div>';

		// Output is admin-only, content is already escaped, and contains dynamic elements like canvas
		echo $html;
	}

	/**
	 * Generate dashboard widget item HTML.
	 *
	 * @param array $item
	 * @param array $menu_items
	 *
	 * @return string
	 */
	public function generate_dashboard_widget_item( $item, $menu_items ) {
		// get allowed html tags
		$allowed_html = wp_kses_allowed_html( 'post' );
		$allowed_html['canvas'] = [
			'id' => [],
			'height' => []
		];

		// Only first-party paired widgets opt in. A widget added through
		// pvc_dashboard_widget_items by an older extension or a third party never
		// gets the controls, so it keeps its exact current markup.
		$has_metrics = ! empty( $item['metrics'] );

		ob_start(); ?>

		<div id="pvc-<?php esc_attr_e( $item['id'] ); ?>" class="pvc-accordion-item<?php echo ( in_array( $item['id'], $menu_items, true ) ? ' pvc-collapsed' : '' ); ?>">
			<div class="pvc-accordion-header">
				<div class="pvc-accordion-toggle"><span class="pvc-accordion-title"><?php esc_html_e( $item['title'] ); ?></span><span class="pvc-tooltip" aria-label="<?php esc_html_e( $item['description'] ); ?>" data-microtip-position="top" data-microtip-size="large" role="tooltip"><span class="pvc-tooltip-icon"></span></span></div>
				<div class="pvc-accordion-actions">
					<!--<a href="javascript:void(0);" class="pvc-accordion-action dashicons dashicons-admin-generic"></a>-->
					<a href="javascript:void(0);" class="pvc-accordion-action pvc-toggle-indicator"></a>
				</div>
			</div>
			<div class="pvc-accordion-content">
				<div class="pvc-dashboard-container loading<?php echo $has_metrics ? ' pvc-dashboard-container-chart' : ''; ?>">
					<div class="pvc-dashboard-content-top">
						<?php do_action( 'pvc_dashboard_widget_content_top', $item['id'] ); ?>
					</div>
					<?php if ( $has_metrics ) : ?>
						<div class="pvc-dashboard-metric-controls">
							<div class="pvc-dashboard-metrics" data-pvc-widget="<?php echo esc_attr( $item['id'] ); ?>" role="group" aria-label="<?php esc_attr_e( 'Chart metrics', 'post-views-counter' ); ?>"></div>
							<p class="pvc-dashboard-visits-unavailable" id="pvc-<?php echo esc_attr( $item['id'] ); ?>-visits-unavailable" hidden></p>
						</div>
					<?php endif; ?>
					<div class="pvc-data-container">
						<?php echo wp_kses( $item['content'], $allowed_html ); ?>
						<div class="pvc-dashboard-message" aria-live="polite"></div>
						<span class="spinner"></span>
					</div>
					<?php if ( $has_metrics ) : ?>
						<div class="pvc-dashboard-legend" data-pvc-widget="<?php echo esc_attr( $item['id'] ); ?>" role="group" aria-label="<?php esc_attr_e( 'Chart datasets', 'post-views-counter' ); ?>"></div>
						<div class="pvc-widget-insights" id="pvc-<?php echo esc_attr( $item['id'] ); ?>-widget-insights" aria-live="polite" hidden></div>
					<?php endif; ?>
					<?php $this->render_widget_insights( $item['id'] ); ?>
					<div class="pvc-dashboard-content-bottom">
						<div class="pvc-date-nav pvc-months">
							<?php 
							// generate dates
							echo wp_kses_post( $this->generate_months( current_time( 'timestamp', false ), $item['id'] ) );
							?>
						</div>
						<?php do_action( 'pvc_dashboard_widget_content_bottom', $item['id'] ); ?>
					</div>
				</div>
			</div>
		</div>
		
		<?php
		// Output current buffer
		return ob_get_clean();
	}

	/**
	 * Render monthly period controls and the chart's Views headline when no extension owns the header.
	 *
	 * @param string $widget_id Widget item ID.
	 * @return void
	 */
	public function render_widget_headline( $widget_id ) {
		if ( ! in_array( $widget_id, [ 'post-views', 'post-most-viewed' ], true ) || class_exists( 'Post_Views_Counter_Pro' ) )
			return;
		?>
		<div class="pvc-dashboard-headline">
			<?php if ( $widget_id === 'post-views' ) : ?>
				<div class="pvc-summary"><span class="pvc-data-count" aria-live="polite"></span></div>
			<?php endif; ?>
			<div class="pvc-date-select button-group <?php echo esc_attr( $widget_id ); ?>" role="group" aria-label="<?php echo esc_attr( $widget_id === 'post-views' ? __( 'Chart period', 'post-views-counter' ) : __( 'Views Period', 'post-views-counter' ) ); ?>">
				<button type="button" class="button button-small button-secondary" data-datenav="yearly" title="<?php esc_attr_e( 'Yearly', 'post-views-counter' ); ?>" aria-label="<?php esc_attr_e( 'Yearly', 'post-views-counter' ); ?>" aria-pressed="false" disabled><span>Y</span></button>
				<button type="button" class="button button-small button-primary" data-datenav="monthly" title="<?php esc_attr_e( 'Monthly', 'post-views-counter' ); ?>" aria-label="<?php esc_attr_e( 'Monthly', 'post-views-counter' ); ?>" aria-pressed="true">M</button>
				<button type="button" class="button button-small button-secondary" data-datenav="weekly" title="<?php esc_attr_e( 'Weekly', 'post-views-counter' ); ?>" aria-label="<?php esc_attr_e( 'Weekly', 'post-views-counter' ); ?>" aria-pressed="false" disabled><span>W</span></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Fire the insights slot of a dashboard widget item.
	 *
	 * A slot between the chart legend and the date navigation, fired for every
	 * item. Core prints no markup of its own there; an extension decides by the
	 * widget ID whether anything belongs to it.
	 *
	 * @since 1.7.16
	 *
	 * @param string $widget_id Widget item ID.
	 * @return void
	 */
	public function render_widget_insights( $widget_id ) {
		do_action( 'pvc_dashboard_widget_content_insights', $widget_id );
	}

	/**
	 * Get the allow-listed metric display modes.
	 *
	 * @return array
	 */
	public function get_metric_modes() {
		return [ 'both', 'views', 'visits' ];
	}

	/**
	 * Get the stored metric display mode of one widget.
	 *
	 * This is the preferred mode, not the effective one. A temporary Visits
	 * outage never rewrites it.
	 *
	 * @param string $widget_id Dashboard widget identifier.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public function get_widget_metric_mode( $widget_id, $user_id = 0 ) {
		$modes = $this->get_dashboard_user_options( $user_id, 'metrics' );
		$mode = isset( $modes[$widget_id] ) ? $modes[$widget_id] : '';

		// both metrics are on for a user with no stored preference
		return in_array( $mode, $this->get_metric_modes(), true ) ? $mode : 'both';
	}

	/**
	 * Get the readable state of the dashboard Visit series.
	 *
	 * Raw dashboard buckets ignore derived_since entirely: a readable slot with
	 * no stored row is zero, and only an unreadable series is withheld.
	 *
	 * @return array
	 */
	public function get_dashboard_visits_availability() {
		$availability = isset( Post_Views_Counter()->visits ) ? Post_Views_Counter()->visits->get_visits_availability() : [ 'readable' => false, 'read_reason' => 'schema_failed' ];
		$readable = ! empty( $availability['readable'] );

		return [
			'readable'	=> $readable,
			'reason'	=> $readable ? 'ready' : ( isset( $availability['read_reason'] ) ? $availability['read_reason'] : 'read_failed' )
		];
	}

	/**
	 * Build the ordered chart buckets of one dashboard views_query.
	 *
	 * The order and the grain deliberately mirror what pvc_get_views() builds
	 * for the same query with `date=>views`, so a Visit series lines up with the
	 * Views series it is paired with. Weekly and monthly charts use daily
	 * buckets; a year uses monthly buckets.
	 *
	 * @param array $views_query Dashboard views query.
	 * @return array|null Storage type and ordered period keys, or null.
	 */
	public function get_chart_buckets( $views_query ) {
		$views_query = is_array( $views_query ) ? $views_query : [];

		$year = ! empty( $views_query['year'] ) ? str_pad( (int) $views_query['year'], 4, '0', STR_PAD_LEFT ) : '';
		$month = ! empty( $views_query['month'] ) ? str_pad( (int) $views_query['month'], 2, '0', STR_PAD_LEFT ) : '';
		$week = ! empty( $views_query['week'] ) ? str_pad( (int) $views_query['week'], 2, '0', STR_PAD_LEFT ) : '';
		$day = ! empty( $views_query['day'] ) ? str_pad( (int) $views_query['day'], 2, '0', STR_PAD_LEFT ) : '';

		if ( $year === '' )
			return null;

		$periods = [];

		// year and week: the seven days of that ISO week
		if ( $week !== '' ) {
			$date = new DateTime( $year . 'W' . $week );

			for ( $i = 1; $i <= 7; $i++ ) {
				$periods[] = $date->format( 'Ymd' );
				$date->modify( '+1 days' );
			}

			return [ 'type' => 0, 'periods' => $periods ];
		}

		if ( $month !== '' ) {
			// year, month and day: one daily bucket
			if ( $day !== '' )
				return [ 'type' => 0, 'periods' => [ $year . $month . $day ] ];

			// year and month: every day of that month
			$date = new DateTime( $year . '-' . $month . '-01' );
			$last = (int) $date->format( 't' );

			for ( $i = 1; $i <= $last; $i++ ) {
				$periods[] = $year . $month . str_pad( $i, 2, '0', STR_PAD_LEFT );
			}

			return [ 'type' => 0, 'periods' => $periods ];
		}

		// year: twelve monthly buckets
		for ( $i = 1; $i <= 12; $i++ ) {
			$periods[] = $year . str_pad( $i, 2, '0', STR_PAD_LEFT );
		}

		return [ 'type' => 2, 'periods' => $periods ];
	}

	/**
	 * Key a paired chart's Views read by the Visits read generation.
	 *
	 * Views reads are cached by their SQL for the cache lifetime, while the
	 * Visit series drawn beside them is keyed by the Visits read generation,
	 * which every committed write, cleanup or reset advances. A trailing SQL
	 * comment carrying that key gives the chart's Views the same freshness
	 * boundary, so both series retire together. Nothing is flushed, and every
	 * other Views read keeps its key.
	 *
	 * @internal Hooked at PHP_INT_MAX on a Views SQL filter only while a paired
	 *           chart reads its Views, including extension-provided content types.
	 *
	 * @param string $query Views SQL.
	 * @return string
	 */
	public static function tag_paired_views_sql( $query ) {
		if ( ! is_string( $query ) || $query === '' || ! class_exists( 'Post_Views_Counter_Visits_Query' ) )
			return $query;

		// an md5 hex key, so the comment cannot close early
		return $query . ' /* pvc-visits-read ' . Post_Views_Counter_Visits_Query::get_read_cache_key( 'paired_views' ) . ' */';
	}

	/**
	 * Read the paired post Visit series for every counted post type at once.
	 *
	 * This is an internal dashboard reader, not a public helper: it exists so a
	 * widget can draw Visits beside Views without adding one Visit query per
	 * dimension. Exactly one bounded, cached query runs per widget request no
	 * matter how many post types are counted, and a database failure is
	 * reported as unavailable rather than measured zero.
	 *
	 * @global object $wpdb
	 *
	 * @param array $args Post types, the dashboard views_query, and the period.
	 * @return array Availability plus visits keyed by post type and period.
	 */
	public function get_paired_visit_series( $args = [] ) {
		global $wpdb;

		$args = wp_parse_args( $args, [
			'post_types'	=> [],
			'views_query'	=> [],
			'period'		=> '',
			'lang'			=> ''
		] );

		$availability = $this->get_dashboard_visits_availability();
		$buckets = $this->get_chart_buckets( $args['views_query'] );
		$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $args['post_types'] ) ) );
		$empty = [ 'available' => false, 'reason' => $availability['reason'], 'periods' => [], 'values' => [] ];

		if ( ! $availability['readable'] )
			return $empty;

		if ( $buckets === null )
			return array_merge( $empty, [ 'reason' => 'invalid_period' ] );

		// no counted post type means nothing to pair, but the series is still
		// readable: every bucket is a measured zero
		if ( empty( $post_types ) )
			return [ 'available' => true, 'reason' => 'ready', 'periods' => $buckets['periods'], 'values' => [] ];

		// bucket keys share their row type's width (Ymd daily, Ym monthly), so the
		// (type, period) key serves the range instead of a cast scan
		$periods = array_map( 'strval', $buckets['periods'] );
		sort( $periods, SORT_STRING );
		$where = [ 'pvc.`type` = %d', 'pvc.`period` BETWEEN %s AND %s' ];
		$params = [ $buckets['type'], reset( $periods ), end( $periods ) ];

		// tables with a content dimension identify post rows as 0
		if ( pvc_post_views_has_content_column() )
			$where[] = 'pvc.`content` = 0';

		$where[] = 'wpp.post_type IN (' . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
		$params = array_merge( $params, $post_types );

		$query = $wpdb->prepare(
			'SELECT pvc.`period`, wpp.post_type, SUM(pvc.`visits`) AS post_visits
			FROM `' . $wpdb->prefix . 'post_views` pvc
			INNER JOIN `' . $wpdb->posts . '` wpp ON wpp.ID = pvc.id
			WHERE ' . implode( ' AND ', $where ) . '
			GROUP BY pvc.`period`, wpp.post_type',
			$params
		);

		// Additive companion to pvc_get_views_query_sql. It exists so the
		// translation integrations can scope the paired Visit read exactly as
		// they already scope the Views read; the `wpp` alias is deliberate.
		$query = apply_filters( 'pvc_dashboard_visit_series_sql', $query, $args, $buckets );

		// keyed by the Visits generation, so every committed write, cleanup or state
		// change retires it, not only its lifetime
		$cache_key = Post_Views_Counter_Visits_Query::get_read_cache_key( 'dashboard_visit_series|' . $query );
		$values = wp_cache_get( $cache_key, Post_Views_Counter_Visits_Query::VISITS_CACHE_GROUP );

		if ( $values === false ) {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $query, ARRAY_A );

			// a failed read is unavailable, never a measured zero
			if ( $wpdb->last_error !== '' )
				return array_merge( $empty, [ 'reason' => 'database_error' ] );

			$values = [];

			foreach ( (array) $rows as $row ) {
				if ( ! isset( $row['post_type'], $row['period'] ) )
					continue;

				$values[(string) $row['post_type']][(string) $row['period']] = isset( $row['post_visits'] ) ? (int) $row['post_visits'] : 0;
			}

			wp_cache_add( $cache_key, $values, Post_Views_Counter_Visits_Query::VISITS_CACHE_GROUP, absint( apply_filters( 'pvc_object_cache_expire', 300 ) ) );
		}

		return [ 'available' => true, 'reason' => 'ready', 'periods' => $buckets['periods'], 'values' => $values ];
	}

	/**
	 * Flatten one dimension of a paired Visit series into chart order.
	 *
	 * @param array  $series Result of get_paired_visit_series().
	 * @param string $dimension Dimension key, or an empty string for the total.
	 * @return array
	 */
	private function get_visit_series_values( $series, $dimension = '' ) {
		$values = [];

		foreach ( $series['periods'] as $period ) {
			$value = 0;

			if ( $dimension === '' ) {
				foreach ( $series['values'] as $dimension_values ) {
					$value += isset( $dimension_values[$period] ) ? (int) $dimension_values[$period] : 0;
				}
			} else {
				$value = isset( $series['values'][$dimension][$period] ) ? (int) $series['values'][$dimension][$period] : 0;
			}

			// a readable bucket with no stored row is a measured zero
			$values[] = $value;
		}

		return $values;
	}

	/**
	 * Describe the two metric buttons of a paired dashboard widget.
	 *
	 * Core owns the shared metric control and therefore its copy, so both
	 * plugins show the same two translated labels.
	 *
	 * @param bool   $visits_available Whether the Visit series can be drawn.
	 * @param string $reason Availability reason.
	 * @return array
	 */
	public function get_metric_buttons( $visits_available, $reason = 'ready' ) {
		$pvc = Post_Views_Counter();
		$views_style = $pvc->functions->get_metric_dataset_style( 'views', 'line' );
		$visits_style = $pvc->functions->get_metric_dataset_style( 'visits', 'line' );

		return [
			[
				'key'		=> 'views',
				'label'		=> _x( 'Views', 'dashboard chart metric', 'post-views-counter' ),
				'available'	=> true,
				'reason'	=> 'ready',
				'color'		=> $views_style['borderColor'],
				'dashed'	=> false
			],
			[
				'key'		=> 'visits',
				'label'		=> _x( 'Visits', 'dashboard chart metric', 'post-views-counter' ),
				'available'	=> (bool) $visits_available,
				'reason'	=> $reason,
				'color'		=> $visits_style['borderColor'],
				'dashed'	=> true
			]
		];
	}

	/**
	 * Render dashboard widget with post views.
	 *
	 * @return void
	 */
	public function dashboard_post_views_chart() {
		if ( ! apply_filters( 'pvc_user_can_see_stats', current_user_can( 'publish_posts' ) ) || ! check_ajax_referer( 'pvc-dashboard-widget', 'nonce' ) )
			wp_die( __( 'You do not have permission to access this page.', 'post-views-counter' ) );

		// get period
		$period = isset( $_POST['period'] ) && ! empty( $_POST['period'] ) ? preg_replace( '/[^a-z0-9_|]/', '', $_POST['period'] ) : apply_filters( 'pvc_dashboard_widget_default_period', 'this_month', 'post-views' );

		echo wp_json_encode( $this->get_post_views_chart_data( $period ) );
		exit;
	}

	/**
	 * Build the paired post views widget payload.
	 *
	 * Separated from the AJAX handler so the payload can be asserted without
	 * the transport. The response contract, filters and arguments are unchanged.
	 *
	 * @param string $period Requested period.
	 * @return array
	 */
	public function get_post_views_chart_data( $period ) {
		$period = preg_replace( '/[^a-z0-9_|]/', '', (string) $period );

		// get post types
		$post_types = Post_Views_Counter()->options['general']['post_types_count'];

		// empty options?
		if ( empty( $post_types ) || ! is_array( $post_types ) )
			$post_types = [];

		// sanitize post_types
		$post_types = map_deep( $post_types, 'sanitize_text_field' );

		// get dashboard user options
		$user_options = $this->get_dashboard_user_options( get_current_user_id(), 'post_types' );

		// empty options?
		if ( empty( $user_options ) || ! is_array( $user_options ) )
			$user_options = [];

		// sanitize options
		$user_options = map_deep( $user_options, 'sanitize_text_field' );

		// get colors
		$colors = Post_Views_Counter()->functions->get_colors();

		// parameters to be used in filter
		$args = [
			'widget'		=> 'post_views',
			'period'		=> $period,
			'post_types'	=> $post_types,
			'user_options'	=> $user_options
		];

		$data = [
			'widget'	=> 'post-views',
			// The flat design object is the legacy third-party contract. First
			// party datasets carry their own style and no longer read it.
			'design'	=> [
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
				'datasets'	=> []
			],
		];

		// convert period
		$time = pvc_period2timestamp( $period );

		// get date chunks
		$date = date( 'Y m W d t', $time );
		$date_chunks = explode( ' ', $date );

		// get date
		$year = (int) $date_chunks[0];
		$month = sanitize_text_field( $date_chunks[1] );
		$dates_number = (int) $date_chunks[4];

		// get previous date chunks
		$previous_time = strtotime( '-1 months', $time );
		$previous_date = date( 'Y m W d t', $previous_time );
		$previous_date_chunks = explode( ' ', $previous_date );

		// get current date
		$current_date = date_create( 'now', wp_timezone() )->format('Y m W d t');
		$current_date_chunks = explode( ' ', $current_date );

		// generate dates
		$data['dates'] = $this->generate_months( $time );

		// the Views query this request actually reads, so the paired Visit read
		// uses the same grain an extension may have switched it to
		$visits_views_query = [
			'year'			=> $year,
			'month'			=> $month,
			'hide_empty'	=> true
		];

		// generate chart data
		$sum = [];
		$views_datasets = [];
		$dimensions = [];

		// whether every dimension reads the query Core built
		$core_chart = true;

		$views_datasets[] = [
			'label'				=> __( 'Total Views', 'post-views-counter' ),
			'metric'			=> 'views',
			'post_type'			=> '_pvc_total_views',
			'dimension_hidden'	=> in_array( '_pvc_total_views', $user_options, true ),
			'hidden'			=> in_array( '_pvc_total_views', $user_options, true ),
			'data'				=> []
		];

		// any post types?
		if ( ! empty( $post_types ) ) {
			// reindex post types
			$post_types = array_combine( range( 1, count( $post_types ) ), array_values( $post_types ) );

			$post_type_data = [];

			// the Views reads share the Visits series' freshness boundary
			$tag_views = ! empty( $this->get_dashboard_visits_availability()['readable'] );

			if ( $tag_views )
				add_filter( 'pvc_get_views_query_sql', [ __CLASS__, 'tag_paired_views_sql' ], PHP_INT_MAX );

			foreach ( $post_types as $id => $post_type ) {
				$post_type_obj = get_post_type_object( $post_type );

				// unrecognized post type? (mainly from deactivated plugins)
				if ( empty( $post_type_obj ) )
					$label = $post_type;
				else
					$label = $post_type_obj->labels->name;

				$dimensions[$id] = [ 'key' => $post_type, 'label' => $label ];

				$views_datasets[$id] = [
					'label'				=> $label,
					'metric'			=> 'views',
					'post_type'			=> $post_type,
					'dimension_hidden'	=> in_array( $post_type, $user_options, true ),
					'hidden'			=> in_array( $post_type, $user_options, true ),
					'data'				=> []
				];

				$core_query_args = [
					'fields'		=> 'date=>views',
					'post_type'		=> $post_type,
					'views_query'	=> [
						'year'			=> $year,
						'month'			=> $month,
						'hide_empty'	=> true
					]
				];

				$query_args = apply_filters( 'pvc_dashboard_post_views_query_args', $core_query_args, $period );

				// any dimension a filter changed (its content, grain, language or
				// anything else) makes a chart the Free notice cannot describe
				if ( $query_args !== $core_query_args )
					$core_chart = false;

				// every dimension reads the same grain, so the paired Visit read
				// follows whatever the filter settled on
				if ( isset( $query_args['views_query'] ) && is_array( $query_args['views_query'] ) )
					$visits_views_query = $query_args['views_query'];

				if ( isset( $query_args['lang'] ) )
					$args['lang'] = $query_args['lang'];

				// get month views
				$post_type_data[$id] = array_values( pvc_get_views( $query_args ) );
			}

			if ( $tag_views )
				remove_filter( 'pvc_get_views_query_sql', [ __CLASS__, 'tag_paired_views_sql' ], PHP_INT_MAX );

			foreach ( $post_type_data as $post_type_id => $post_views ) {
				foreach ( $post_views as $id => $views ) {
					// generate chart data for specific post types
					$views_datasets[$post_type_id]['data'][] = $views;

					if ( ! array_key_exists( $id, $sum ) )
						$sum[$id] = 0;

					$sum[$id] += $views;
				}
			}
		}

		// One bounded, cached read covers every dimension and the total, so the
		// paired payload never costs one extra Visit query per dimension.
		$visit_series = $this->get_paired_visit_series( [
			'post_types'	=> array_values( $post_types ),
			'views_query'	=> $visits_views_query,
			'period'		=> $period,
			'lang'			=> isset( $args['lang'] ) ? $args['lang'] : ''
		] );

		$visits_available = ! empty( $visit_series['available'] );
		$visits_sum = $visits_available ? $this->get_visit_series_values( $visit_series ) : [];

		// this month all days
		for ( $i = 1; $i <= $dates_number; $i++ ) {
			// generate chart data
			$data['data']['labels'][] = ( $i % 2 === 0 ? '' : $i );
			$data['data']['dates'][] = date_i18n( get_option( 'date_format' ), strtotime( $year . '-' . $month . '-' . str_pad( $i, 2, '0', STR_PAD_LEFT ) ) );
			$views_datasets[0]['data'][] = ( array_key_exists( $i - 1, $sum ) ? $sum[$i - 1] : 0 );
		}

		// Visits are paired with Views on every request even when the initial
		// mode only draws the two totals, so a metric switch never refetches.
		$visits_datasets = [];

		if ( $visits_available ) {
			$visits_datasets[] = [
				'label'				=> __( 'Total Visits', 'post-views-counter' ),
				'metric'			=> 'visits',
				'post_type'			=> '_pvc_total_views',
				'dimension_hidden'	=> in_array( '_pvc_total_views', $user_options, true ),
				'hidden'			=> true,
				'data'				=> array_slice( $visits_sum, 0, $dates_number )
			];

			foreach ( $dimensions as $id => $dimension ) {
				$visits_datasets[] = [
					'label'				=> $dimension['label'],
					'metric'			=> 'visits',
					'post_type'			=> $dimension['key'],
					'dimension_hidden'	=> in_array( $dimension['key'], $user_options, true ),
					'hidden'			=> true,
					'data'				=> array_slice( $this->get_visit_series_values( $visit_series, $dimension['key'] ), 0, $dates_number )
				];
			}
		}

		// the helper also writes the metric state onto $data by reference, so
		// the assignment is deliberately kept as its own statement
		$datasets = $this->prepare_metric_datasets( $views_datasets, $visits_datasets, [
			'widget'	=> 'post-views',
			'available'	=> $visits_available,
			'reason'	=> isset( $visit_series['reason'] ) ? $visit_series['reason'] : 'read_failed'
		], $data );

		$data['data']['datasets'] = $datasets;

		// The Free Views comparison, only on the month chart Core drew
		// itself, with every dimension's query as built, and never
		// beside an active extension of any version, which decides the list on its
		// own. No list means no box.
		if ( ! class_exists( 'Post_Views_Counter_Pro' ) && $core_chart ) {
			$insight_notices = $this->get_free_insight_notices( $year . $month, array_values( $post_types ) );

			if ( ! empty( $insight_notices ) )
				$data['insight_notices'] = $insight_notices;
		}

		$data = apply_filters( 'pvc_dashboard_post_views_data', $data, $args );

		// The headline describes the final chart's total, regardless of dataset
		// order or visibility. An explicit summary (including null) stays owned
		// by the filter; an unreadable series never becomes an invented zero.
		if ( ! class_exists( 'Post_Views_Counter_Pro' ) && is_array( $data ) && ! array_key_exists( 'summary', $data ) && isset( $data['data']['datasets'] ) && is_array( $data['data']['datasets'] ) ) {
			foreach ( $data['data']['datasets'] as $dataset ) {
				if ( ! is_array( $dataset ) || ! isset( $dataset['metric'], $dataset['post_type'] ) || $dataset['metric'] !== 'views' || $dataset['post_type'] !== '_pvc_total_views' )
					continue;

				if ( empty( $dataset['data'] ) || ! is_array( $dataset['data'] ) )
					break;

				$views = 0;

				foreach ( $dataset['data'] as $value ) {
					if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) )
						break 2;

					$views += $value;
				}

				if ( is_finite( (float) $views ) ) {
					$data['summary'] = [
						'views' => $views,
						'views_html' => html_entity_decode( number_format_i18n( $views ), ENT_NOQUOTES, 'UTF-8' )
					];
				}

				break;
			}
		}

		// formatted after the filter, so tooltips match the values it returns
		if ( is_array( $data ) && isset( $data['data'] ) )
			$data['data'] = Post_Views_Counter()->functions->format_chart_datasets( $data['data'] );

		return $data;
	}

	/**
	 * Build the Free Views comparison notice.
	 *
	 * The month drawn against the previous month, on the shared comparison
	 * of the counted post types, with no language and no history: a
	 * running month on its completed days, a complete one on whole months.
	 * - No valid comparison: null, so the payload has no list and no box.
	 * - Fewer than FREE_NOTICE_MIN_VIEWS Views on either side: an empty list, no box.
	 * - Otherwise the direction and the rounded change. A
	 *   running month is "This month" against the same days last month; a
	 *   complete one is named against the month before it. A change that
	 *   rounds to 0 reads "less than 1%"; equal Views read "level with".
	 *
	 * @param string $period     The month drawn, YYYYMM.
	 * @param array  $post_types Counted post types.
	 * @return array|null The `insight_notices` list.
	 */
	private function get_free_insight_notices( $period, $post_types ) {
		$comparison = Post_Views_Counter_Dashboard_Comparison::get( [
			'grain'			=> 'month',
			'period'		=> $period,
			'scope'			=> 'posts',
			'post_types'	=> $post_types,
			'now'			=> $this->now
		] );

		if ( empty( $comparison['available'] ) )
			return null;

		$notices = [];
		$current = (int) $comparison['current']['views'];
		$previous = (int) $comparison['previous']['views'];

		if ( $current >= self::FREE_NOTICE_MIN_VIEWS && $previous >= self::FREE_NOTICE_MIN_VIEWS ) {
			// a running month compares its completed days with the same days
			// of the month before, named relatively, never as a range of days
			$running = $comparison['state'] !== 'complete';
			$month = self::format_comparison_month( $comparison['current']['from'] );
			$previous_month = self::format_comparison_month( $comparison['previous']['from'] );

			if ( $current === $previous ) {
				$direction = 'same';
				$text = $running
					? __( 'This month is level with the same days last month.', 'post-views-counter' )
					/* translators: 1: the month the chart shows, e.g. June 2026, 2: the month before it */
					: sprintf( __( '%1$s was level with %2$s.', 'post-views-counter' ), self::ucfirst( $month ), $previous_month );
			} else {
				$direction = $current > $previous ? 'up' : 'down';

				// half away from zero; whole-number inputs keep 0.5% exact
				$percent = (int) round( abs( $current - $previous ) * 100 / $previous );

				if ( $percent < 1 )
					$change = __( 'less than 1%', 'post-views-counter' );
				else
					/* translators: %s: a change in percent, a whole number without a sign */
					$change = sprintf( __( '%s%%', 'post-views-counter' ), number_format_i18n( $percent ) );

				if ( $running ) {
					$text = $direction === 'up'
						/* translators: %s: the change, e.g. 12% */
						? sprintf( __( 'This month is %s higher than the same days last month.', 'post-views-counter' ), $change )
						/* translators: %s: the change, e.g. 12% */
						: sprintf( __( 'This month is %s lower than the same days last month.', 'post-views-counter' ), $change );
				} else {
					$text = $direction === 'up'
						/* translators: 1: the month the chart shows, e.g. June 2026, 2: the change, e.g. 12%, 3: the month before it */
						? sprintf( __( '%1$s was %2$s higher than %3$s.', 'post-views-counter' ), self::ucfirst( $month ), $change, $previous_month )
						/* translators: 1: the month the chart shows, e.g. June 2026, 2: the change, e.g. 12%, 3: the month before it */
						: sprintf( __( '%1$s was %2$s lower than %3$s.', 'post-views-counter' ), self::ucfirst( $month ), $change, $previous_month );
				}
			}

			$notices[] = [
				'family'	=> 'views',
				'class'		=> 'comparison',
				'direction'	=> $direction,
				'text'		=> $text
			];
		}

		return $notices;
	}

	/**
	 * Name a whole month of a comparison, e.g. "June 2026".
	 *
	 * Ranges are counting-clock buckets, so they are formatted as UTC days and
	 * never shifted by the site timezone.
	 *
	 * @param string $from Its first day, Ymd.
	 * @return string
	 */
	private static function format_comparison_month( $from ) {
		$utc = new DateTimeZone( 'UTC' );

		return wp_date( _x( 'F Y', 'dashboard comparison: a month', 'post-views-counter' ), DateTimeImmutable::createFromFormat( '!Ymd', $from, $utc )->getTimestamp(), $utc );
	}

	/**
	 * Capitalise the first letter of a sentence's subject, for locales whose
	 * month names are lowercase.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function ucfirst( $text ) {
		if ( $text === '' || ! function_exists( 'mb_substr' ) )
			return ucfirst( $text );

		return mb_strtoupper( mb_substr( $text, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $text, 1, null, 'UTF-8' );
	}

	/**
	 * Attach the paired metric state, styles, and copy to a widget payload.
	 *
	 * Views datasets come first and Visits second so Chart.js draws the derived
	 * colour in front of the theme colour in the totals pair.
	 *
	 * @param array $views_datasets Ordered Views datasets.
	 * @param array $visits_datasets Ordered Visits datasets, empty when unreadable.
	 * @param array $context Widget id and Visit availability.
	 * @param array $data Payload being built, passed by reference.
	 * @return array Combined, styled datasets.
	 */
	public function prepare_metric_datasets( $views_datasets, $visits_datasets, $context, &$data ) {
		$pvc = Post_Views_Counter();
		$context = wp_parse_args( $context, [
			'widget'	=> '',
			'available'	=> false,
			'reason'	=> 'read_failed'
		] );

		$available = (bool) $context['available'];
		$preferred = $this->get_widget_metric_mode( $context['widget'], get_current_user_id() );

		// An unreadable Visit series forces the Views breakdown for this render
		// only. The stored preference is never rewritten, so the preferred mode
		// returns by itself once Visits becomes readable again.
		$effective = $available ? $preferred : 'views';

		$datasets = [];

		foreach ( [ 'views' => $views_datasets, 'visits' => $visits_datasets ] as $metric => $metric_datasets ) {
			foreach ( $metric_datasets as $index => $dataset ) {
				$dataset['metric'] = $metric;
				$dataset['is_total'] = $index === 0;

				if ( $effective === 'both' ) {
					// both totals are always drawn and the saved breakdown
					// visibility of Total is deliberately not consulted
					$dataset['hidden'] = $index !== 0;
				} else {
					$dataset['hidden'] = $metric !== $effective || ! empty( $dataset['dimension_hidden'] );
				}

				// The translucent area is part of the dashboard presentation in all
				// three metric modes, including multi-line breakdowns.
				$datasets[] = array_merge( $dataset, $pvc->functions->get_metric_dataset_style( $metric, 'line', [ 'fill' => true ] ) );
			}
		}

		$data['metrics'] = $this->get_metric_buttons( $available, $context['reason'] );
		$data['metric_state'] = [
			'preferred'	=> $preferred,
			'effective'	=> $effective
		];
		$data['availability'] = [
			'readable'	=> $available,
			'reason'	=> $available ? 'ready' : $context['reason']
		];
		$data['notices'] = [
			'visits_unavailable'	=> __( 'Visit data is not available right now.', 'post-views-counter' )
		];

		return $datasets;
	}

	/**
	 * Render dashboard widget with most viewed posts.
	 *
	 * @return void
	 */
	public function dashboard_post_most_viewed() {
		if ( ! apply_filters( 'pvc_user_can_see_stats', current_user_can( 'publish_posts' ) ) || ! check_ajax_referer( 'pvc-dashboard-widget', 'nonce' ) )
			wp_die( __( 'You do not have permission to access this page.', 'post-views-counter' ) );

		// get period
		$period = isset( $_POST['period'] ) && ! empty( $_POST['period'] ) ? preg_replace( '/[^a-z0-9_|]/', '', $_POST['period'] ) : apply_filters( 'pvc_dashboard_widget_default_period', 'this_month', 'post-most-viewed' );

		echo wp_json_encode( $this->get_post_most_viewed_data( $period ) );
		exit;
	}

	/**
	 * Build the Top Posts widget payload.
	 *
	 * Separated from the AJAX handler so the list can be asserted without the
	 * transport. Rows are selected, ordered and ranked by Views.
	 *
	 * @param string $period Requested period.
	 * @return array
	 */
	public function get_post_most_viewed_data( $period ) {
		$period = preg_replace( '/[^a-z0-9_|]/', '', (string) $period );

		// get post types
		$post_types = Post_Views_Counter()->options['general']['post_types_count'];
		
		// empty options?
		if ( empty( $post_types ) || ! is_array( $post_types ) )
			$post_types = [];

		// sanitize post_types
		$post_types = map_deep( $post_types, 'sanitize_text_field' );

		// parameters to be used in filter
		$args = [
			'widget'		=> 'post_most_viewed',
			'period'		=> $period,
			'post_types'	=> $post_types
		];

		$data = [
			'widget'	=> 'post-most-viewed',
			'html'		=> ''
		];

		// convert period
		$time = pvc_period2timestamp( $period );

		// get date chunks
		$date = date( 'Y m W d t', $time );
		$date_chunks = explode( ' ', $date );

		// get date
		$year = (int) $date_chunks[0];
		$month = sanitize_text_field( $date_chunks[1] );
		
		// generate dates
		$data['dates'] = $this->generate_months( $time );

		// query args
		$query_args = apply_filters( 'pvc_dashboard_post_most_viewed_query_args', [
			'post_type'			=> $post_types,
			'posts_per_page'	=> 10,
			'paged'				=> false,
			'suppress_filters'	=> false,
			'no_found_rows'		=> true,
			'views_query'		=> [
				'year'			=> $year,
				'month'			=> $month,
				'hide_empty'	=> true
			]
		], $period );

		$posts = pvc_get_most_viewed_posts( $query_args );

		$html = '
		<table id="pvc-post-most-viewed-table" class="pvc-table pvc-table-hover">
			<thead>
				<tr>
					<th scope="col">#</th>
					<th scope="col">' . esc_html__( 'Post', 'post-views-counter' ) . '</th>
					<th scope="col">' . esc_html__( 'Views', 'post-views-counter' ) . '</th>
				</tr>
			</thead>
			<tbody>';

		if ( $posts ) {
			// active post types
			$active_post_types = [];

			foreach ( $posts as $index => $post ) {
				setup_postdata( $post );

				$html .= '
				<tr>
					<th scope="col">' . ( $index + 1 ) . '</th>';

				// check post type existence
				if ( array_key_exists( $post->post_type, $active_post_types ) )
					$post_type_exists = $active_post_types[$post->post_type];
				else
					$post_type_exists = $active_post_types[$post->post_type] = post_type_exists( $post->post_type );

				$title = get_the_title( $post );

				if ( $title === '' )
					$title = __( '(no title)' );

				// post link
				$link = '<a href="' . esc_url( get_permalink( $post->ID ) ) . '" target="_blank">' . esc_html( $title ) . '</a>';

				// edit post link
				if ( $post_type_exists && current_user_can( 'edit_post', $post->ID ) ) {
					$link .= ' <a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '" class="cn-edit-link" target="_blank">' . esc_html__( 'Edit', 'post-views-counter' ) . '</a>';
				}

				$html .= '
					<td>' . $link . '</td>';

				$html .= '
					<td>' . number_format_i18n( $post->post_views ) . '</td>
				</tr>';
			}
		} else {
			$html .= '
				<tr class="no-posts">
					<td colspan="3">' . esc_html__( 'No most viewed posts found.', 'post-views-counter' ) . '</td>
				</tr>';
		}

		$html .= '
			</tbody>
		</table>';
		
		$data['html'] = $html;

		return apply_filters( 'pvc_dashboard_post_most_viewed_data', $data, $args );
	}

	/**
	 * Generate dashboard widget months HTML.
	 *
	 * @param int $timestamp
	 * @return string
	 */
	public function generate_months( $timestamp, $item = '' ) {
		$dates = [
			explode( ' ', date( 'm F Y', strtotime( '-1 months', $timestamp ) ) ),
			explode( ' ', date( 'm F Y', $timestamp ) ),
			explode( ' ', date( 'm F Y', strtotime( '+1 months', $timestamp ) ) )
		];

		$current = date( 'Ym', current_time( 'timestamp', false ) );

		if ( (int) $current <= (int) ( $dates[1][2] . $dates[1][0] ) )
			$next = '<span class="next">' . $dates[2][1] . ' ' . $dates[2][2] . ' ›</span>';
		else
			$next = '<a class="next" href="#" data-date="' . ( $dates[2][2] . $dates[2][0] ) . '">' . $dates[2][1] . ' ' . $dates[2][2] . ' ›</a>';

		$dates_formatted = apply_filters( 'pvc_dashboard_widget_generate_months_formatted', [
			'prev'		=> '<a class="prev" href="#" data-date="' . ( $dates[0][2] . $dates[0][0] ) . '">‹ ' . $dates[0][1] . ' ' . $dates[0][2] . '</a>',
			'current'	=> '<span class="current">' . $dates[1][1] . ' ' . $dates[1][2] . '</span>',
			'next'		=> $next
		], $timestamp, $item );

		return wp_kses_post( apply_filters( 'pvc_dashboard_widget_generate_months_html', $dates_formatted['prev'] . $dates_formatted['current'] . $dates_formatted['next'], $timestamp, $item ) );
	}

	/**
	 * Update dashboard widget user options.
	 *
	 * @return void
	 */
	public function update_dashboard_user_options() {
		if ( ! check_ajax_referer( 'pvc-dashboard-user-options', 'nonce' ) )
			wp_die( __( 'You do not have permission to access this page.', 'post-views-counter' ) );

		// valid data?
		if ( isset( $_POST['nonce'], $_POST['options'] ) && ! empty( $_POST['options'] ) ) {
			// get sanitized options
			$update = map_deep( $_POST['options'], 'sanitize_text_field' );

			// get user ID
			$user_id = get_current_user_id();

			// update userdata
			update_user_meta( $user_id, 'pvc_dashboard', $this->merge_dashboard_user_options( $update, $user_id ) );

			echo wp_send_json_success();
		}

		echo wp_send_json_error();
		exit;
	}

	/**
	 * Merge one dashboard preference update into the stored user options.
	 *
	 * Separated from the AJAX handler so the allow-lists can be asserted
	 * without the transport. The stored shape and the filter are unchanged.
	 *
	 * @param array $update Sanitized update payload.
	 * @param int   $user_id User ID.
	 * @return array
	 */
	public function merge_dashboard_user_options( $update, $user_id ) {
		$update = is_array( $update ) ? $update : [];

		// get user dashboard data
		$user_options = get_user_meta( $user_id, 'pvc_dashboard', true );

		// empty userdata?
		if ( ! is_array( $user_options ) || empty( $user_options ) )
			$user_options = [];

		// empty post types?
		if ( ! array_key_exists( 'post_types', $user_options ) || ! is_array( $user_options['post_types'] ) )
			$user_options['post_types'] = [];

		// hide post type?
		if ( ! empty( $update['post_type'] ) ) {
			// get allowed post types
			$allowed_post_types = Post_Views_Counter()->options['general']['post_types_count'];

			// simulate total post views as post type
			$allowed_post_types[] = '_pvc_total_views';

			if ( in_array( $update['post_type'], $allowed_post_types, true ) ) {
				if ( isset( $update['hidden'] ) && $update['hidden'] === 'true' ) {
					if ( ! in_array( $update['post_type'], $user_options['post_types'], true ) )
						$user_options['post_types'][] = $update['post_type'];
				} else {
					if ( ( $key = array_search( $update['post_type'], $user_options['post_types'] ) ) !== false )
						unset( $user_options['post_types'][$key] );
				}
			}
		}

		// empty metric modes?
		if ( ! array_key_exists( 'metrics', $user_options ) || ! is_array( $user_options['metrics'] ) )
			$user_options['metrics'] = [];

		// Only an explicit metric-button action reaches this branch. An
		// availability fallback renders in Views breakdown mode without ever
		// sending an update, so a stored both or visits preference survives
		// a Visits outage untouched.
		if ( ! empty( $update['metrics'] ) && is_array( $update['metrics'] ) ) {
			// the widget list is normally built on admin_init; build it here too
			// so the allow-list is never silently empty
			if ( empty( $this->widget_items ) )
				$this->setup_widget_items();

			$allowed_widgets = array_column( $this->widget_items, 'id' );

			foreach ( $update['metrics'] as $widget_id => $mode ) {
				$widget_id = sanitize_key( $widget_id );

				if ( in_array( $widget_id, $allowed_widgets, true ) && in_array( $mode, $this->get_metric_modes(), true ) )
					$user_options['metrics'][$widget_id] = $mode;
			}
		}

		// empty menu items?
		if ( ! array_key_exists( 'menu_items', $user_options ) || ! is_array( $user_options['menu_items'] ) )
			$user_options['menu_items'] = [];

		if ( ! empty( $update['menu_items'] ) && is_array( $update['menu_items'] ) ) {
			$user_options['menu_items'] = [];

			// get allowed menu items
			$allowed_menu_items = array_column( $this->widget_items, 'id' );

			foreach ( $update['menu_items'] as $menu_item => $hidden ) {
				if ( in_array( $menu_item, $allowed_menu_items, true ) && $hidden === 'true' )
					$user_options['menu_items'][] = $menu_item;
			}
		}

		// filter user options
		$user_options = apply_filters( 'pvc_update_dashboard_user_options', $user_options, $update, $user_id );

		return $user_options;
	}

	/**
	 * Get user dashboard data.
	 *
	 * @param int $user_id
	 * @param string $data_type
	 * @return array
	 */
	public function get_dashboard_user_options( $user_id = 0, $data_type = '' ) {
		$user_options = get_user_meta( $user_id, 'pvc_dashboard', true );

		if ( ! is_array( $user_options ) || empty( $user_options ) )
			$user_options = [];

		if ( ! array_key_exists( $data_type, $user_options ) || ! is_array( $user_options[$data_type] ) )
			$user_options[$data_type] = [];

		return $user_options[$data_type];
	}
	
	/**
	 * Convert period to timestamp.
	 * 
	 * @deprecated
	 * @param string $period
	 * @return int
	 */
	public function period2timestamp( $period ) {
		return pvc_period2timestamp( $period );
	}
}
