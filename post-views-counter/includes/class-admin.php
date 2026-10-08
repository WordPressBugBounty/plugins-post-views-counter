<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Admin class.
 *
 * @class Post_Views_Counter_Admin
 */
class Post_Views_Counter_Admin {

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'plugins_loaded', [ $this, 'init_block_editor' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'register_chartjs' ], 9 );
	}

	/**
	 * Register Chart.js and the shared metric button component.
	 *
	 * Core owns the metric button runtime so dashboard charts, the column modal,
	 * and extension charts draw the same control. Consumers load the registered
	 * handle when available and keep a local fallback for older Core versions.
	 *
	 * @return void
	 */
	public function register_chartjs() {
		wp_register_script( 'pvc-chartjs', POST_VIEWS_COUNTER_URL . '/assets/chartjs/chart.min.js', [ 'jquery' ], '4.5.1', true );
		wp_register_script( 'pvc-metric-toggle', POST_VIEWS_COUNTER_URL . '/js/metric-toggle.js', [], Post_Views_Counter()->defaults['version'], true );
	}

	/**
	 * Init block editor actions.
	 *
	 * @return void
	 */
	public function init_block_editor() {
		add_action( 'rest_api_init', [ $this, 'block_editor_rest_api_init' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'block_editor_enqueue_scripts' ] );
	}

	/**
	 * Register REST API block editor endpoints.
	 *
	 * @return void
	 */
	public function block_editor_rest_api_init() {
		// update counter totals route
		register_rest_route(
			'post-views-counter',
			'/update-post-views/',
			[
				'methods'				=> [ 'POST' ],
				'callback'				=> [ $this, 'block_editor_update_callback' ],
				'permission_callback'	=> [ $this, 'check_rest_route_permissions' ],
				'args'					=> [
					'id' => [
						'required'			=> true,
						'validate_callback'	=> [ $this, 'validate_post_id_param' ],
						'sanitize_callback'	=> 'absint'
					],
					'views' => [
						'validate_callback'	=> [ $this, 'validate_counter_total_param' ]
					],
					// released alias of views
					'post_views' => [
						'validate_callback'	=> [ $this, 'validate_counter_total_param' ]
					]
				]
			]
		);
	}

	/**
	 * Validate the post ID before absint() can turn a malformed value such
	 * as -20, 20.7 or 20junk into another existing post ID.
	 *
	 * @param mixed           $value Submitted value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param Parameter name.
	 * @return true|WP_Error
	 */
	public function validate_post_id_param( $value, $request, $param ) {
		// a positive integer, as an int or a canonical decimal string
		if ( ( is_int( $value ) || is_string( $value ) ) && filter_var( $value, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] ) !== false && ( is_int( $value ) || ctype_digit( $value ) ) )
			return true;

		/* translators: %s: request parameter name */
		return new WP_Error( 'rest_invalid_param', sprintf( __( '%s must be a positive whole number.', 'post-views-counter' ), $param ), [ 'status' => 400 ] );
	}

	/**
	 * Validate one submitted counter total: a whole number, or blank.
	 *
	 * @param mixed           $value Submitted value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param Parameter name.
	 * @return true|WP_Error
	 */
	public function validate_counter_total_param( $value, $request, $param ) {
		if ( is_null( $value ) || is_int( $value ) )
			return true;

		if ( is_string( $value ) && ( trim( $value ) === '' || preg_match( '/^-?\d+$/', trim( $value ) ) ) )
			return true;

		/* translators: %s: request parameter name */
		return new WP_Error( 'rest_invalid_param', sprintf( __( '%s must be a whole number.', 'post-views-counter' ), $param ), [ 'status' => 400 ] );
	}

	/**
	 * Check whether the current user may update this post's counter totals
	 * from the block editor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_rest_route_permissions( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$columns = Post_Views_Counter()->columns;

		// checked first so the route does not reveal which posts exist
		if ( ! current_user_can( 'edit_post', $post_id ) )
			return new WP_Error( 'pvc-user-not-allowed', __( 'You are not allowed to edit this item.', 'post-views-counter' ), [ 'status' => rest_authorization_required_code() ] );

		if ( ! $columns || ! $columns->is_post_counter_visible_in_editor( $post_id ) )
			return new WP_Error( 'pvc-invalid-post', __( 'Invalid post ID.', 'post-views-counter' ), [ 'status' => 404 ] );

		if ( ! $columns->can_edit_post_counter_in_editor( $post_id ) )
			return new WP_Error( 'pvc-user-not-allowed', __( 'You are not allowed to edit this item.', 'post-views-counter' ), [ 'status' => rest_authorization_required_code() ] );

		return true;
	}

	/**
	 * REST API callback for the block editor endpoint.
	 *
	 * Only an edited Views total is written. The shared writer re-checks
	 * eligibility.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function block_editor_update_callback( $request ) {
		$columns = Post_Views_Counter()->columns;

		if ( ! $columns )
			return new WP_Error( 'pvc-counter-write-failed', __( 'The counter totals could not be saved.', 'post-views-counter' ), [ 'status' => 500 ] );

		$views = $request->get_param( 'views' );

		// released clients send the Views total as post_views
		if ( is_null( $views ) )
			$views = $request->get_param( 'post_views' );

		// a blank value never means zero
		if ( is_null( $views ) || trim( (string) $views ) === '' )
			return new WP_Error( 'pvc-no-counter-totals', __( 'No counter totals were submitted.', 'post-views-counter' ), [ 'status' => 400 ] );

		$post_id = (int) $request->get_param( 'id' );
		$state = $columns->apply_editor_counter_totals( $post_id, [ 'post_views' => (string) $views ] );

		if ( is_wp_error( $state ) )
			return $state;

		return rest_ensure_response(
			[
				'id'	=> $post_id,
				'views'	=> $state['views']
			]
		);
	}

	/**
	 * Enqueue frontend and editor JavaScript and CSS.
	 *
	 * @global string $pagenow
	 *
	 * @return void
	 */
	public function block_editor_enqueue_scripts() {
		global $pagenow;

		// get main instance
		$pvc = Post_Views_Counter();

		// skip screens without a single post context: widgets, customizer and the site editor
		// wp.editor.PluginPostStatusInfo renders in the site editor too, where get_the_ID() is unavailable
		if ( $pagenow === 'widgets.php' || $pagenow === 'customize.php' || $pagenow === 'site-editor.php' )
			return;

		// enqueue frontend and editor block styles
		wp_enqueue_style( 'pvc-block-editor', POST_VIEWS_COUNTER_URL . '/css/block-editor.css', '', $pvc->defaults['version'] );

		$id = (int) get_the_ID();

		// untracked post types and posts hidden by the display filters get no counter rows
		if ( ! $id || ! $pvc->columns || ! $pvc->columns->is_post_counter_visible_in_editor( $id ) )
			return;

		$state = $pvc->columns->get_post_editor_counter_state( $id );

		// enqueue the bundled block JS file
		// wp-edit-post: PluginPostStatusInfo, wp-api-fetch: counter totals request after save
		wp_enqueue_script( 'pvc-block-editor', POST_VIEWS_COUNTER_URL . '/js/block-editor.js', [ 'wp-element', 'wp-components', 'wp-editor', 'wp-edit-post', 'wp-data', 'wp-plugins', 'wp-api-fetch' ], $pvc->defaults['version'], false );

		// prepare script data
		$script_data = [
			'postID'			=> $id,
			'views'				=> $state['views'],
			'canEdit'			=> $state['editable'],
			'locale'			=> str_replace( '_', '-', get_user_locale() ),
			'i18n'				=> [
				'views'				=> __( 'Views', 'post-views-counter' ),
				/* translators: 1: metric name, 2: formatted count */
				'valueLabel'		=> __( '%1$s: %2$s.', 'post-views-counter' ),
				'help'				=> __( 'Lifetime totals. Changing them does not alter daily, weekly, monthly, or yearly history.', 'post-views-counter' ),
				'cancel'			=> __( 'Cancel', 'post-views-counter' ),
				'saveFailed'		=> __( 'Views could not be saved.', 'post-views-counter' )
			]
		];

		wp_add_inline_script( 'pvc-block-editor', 'var pvcEditorArgs = ' . wp_json_encode( $script_data ) . ";\n", 'before' );
	}
}
