<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Counter class.
 *
 * @class Post_Views_Counter_Counter
 */
class Post_Views_Counter_Counter {
	const REST_MAX_TARGETS = 100;
	const STORAGE_MAX_PAYLOAD_BYTES = 15920;
	const STORAGE_MAX_COOKIE_CHUNKS = 4;
	const STORAGE_MAX_BUCKETS = 8;
	const STORAGE_MAX_MEMBERS = 1000;

	private $storage = [];
	private $storage_type = 'cookies';
	private $queue = [];
	private $queue_mode = false;
	private $db_insert_values = [];
	private $cookie = [];
	private $pending_storage_commit = null;
	private $pending_session_created = null;
	private $window_created_candidate = false;
	private $last_cache_flush_status = 'idle';

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'plugins_loaded', [ $this, 'check_cookie' ], 1 );
		add_action( 'init', [ $this, 'init_counter' ] );
		add_action( 'deleted_post', [ $this, 'delete_post_views' ] );
	}

	/**
	 * Add Post ID to queue.
	 *
	 * @param int $post_id
	 *
	 * @return void
	 */
	public function add_to_queue( $post_id ) {
		$this->queue[] = (int) $post_id;
	}

	/**
	 * Return a fresh nonce for the manual post queue.
	 *
	 * The nonce is deliberately minted at request time instead of being embedded in cacheable
	 * page markup. This preserves the existing queue request contract while allowing cached pages
	 * to keep working after the normal WordPress nonce lifetime expires.
	 *
	 * @return void
	 */
	public function get_queue_runtime_data() {
		if ( function_exists( 'nocache_headers' ) )
			nocache_headers();

		wp_send_json_success( [
			'runtime' => [
				'queueNonce' => wp_create_nonce( 'pvc-view-posts' )
			]
		] );
	}

	/**
	 * Run manual pvc_view_post queue.
	 *
	 * @return void
	 */
	public function queue_count() {
		// missing or invalid parameters?
		if ( ! isset( $_POST['action'], $_POST['ids'], $_POST['pvc_nonce'] ) || $_POST['ids'] === '' || ! is_string( $_POST['ids'] ) )
			wp_send_json_error( [
				'code' => 'pvc_missing_parameters',
				'message' => __( 'Missing or invalid queue parameters.', 'post-views-counter' )
			], 400 );

		// invalid nonce?
		if ( ! wp_verify_nonce( $_POST['pvc_nonce'], 'pvc-view-posts' ) )
			wp_send_json_error( [
				'code' => 'pvc_invalid_nonce',
				'message' => __( 'Security check failed.', 'post-views-counter' )
			], 403 );

		// get post ids
		$ids = array_values( array_filter( array_map( 'intval', explode( ',', $_POST['ids'] ) ), function( $id ) {
			return $id > 0;
		} ) );

		$counted = [];

		if ( empty( $ids ) )
			wp_send_json_error( [
				'code' => 'pvc_invalid_post_ids',
				'message' => __( 'No valid post IDs were provided.', 'post-views-counter' )
			], 400 );

		// turn on queue mode
		$this->queue_mode = true;

		foreach ( $ids as $id ) {
			$counted[$id] = ! ( $this->check_post( $id, [], true ) === null );
		}

		// turn off queue mode
		$this->queue_mode = false;

		// preserve the existing flat success response contract
		wp_send_json( [
			'post_ids'	=> $ids,
			'counted'	=> $counted
		] );
	}

	/**
	 * Print JavaScript with queue in the footer.
	 *
	 * @return void
	 */
	public function print_queue_count() {
		// get main instance
		$pvc = Post_Views_Counter();

		// only load manual counter for js mode, not for rest_api mode
		if ( $pvc->options['general']['counter_mode'] !== 'js' )
			return;

		// any ids to "view"?
		if ( ! empty( $this->queue ) ) {
			echo "
			<script>
				( function( window, document, undefined ) {
					let pvcInitManualCounter = function() {
						let pvcLoadManualCounter = function( url, counter ) {
							let pvcScriptTag = document.createElement( 'script' );

							// append script
							document.body.appendChild( pvcScriptTag );

							// set attributes
							pvcScriptTag.onload = counter;
							pvcScriptTag.onreadystatechange = counter;
							pvcScriptTag.src = url;
						};

						let pvcExecuteManualCounter = function() {
							let pvcManualCounterArgs = {
								url: '" . esc_url( admin_url( 'admin-ajax.php' ) ) . "',
								runtimeAction: 'pvc-queue-runtime',
								ids: '" . implode( ',', $this->queue ) . "'
							};

							let pvcPendingRequest = null;

							if ( typeof PostViewsCounter !== 'undefined' )
								pvcPendingRequest = PostViewsCounter.promise || null;
							else if ( typeof PostViewsCounterPro !== 'undefined' )
								pvcPendingRequest = PostViewsCounterPro.countPromise || PostViewsCounterPro.bootstrapPromise || PostViewsCounterPro.promise || null;

							// wait for the main counter request when one is active
							if ( pvcPendingRequest && typeof pvcPendingRequest.then === 'function' ) {
								pvcPendingRequest.then( function() {
									PostViewsCounterManual.init( pvcManualCounterArgs );
								}, function() {
									PostViewsCounterManual.init( pvcManualCounterArgs );
								} );
							// PostViewsCounter is undefined or has no active request
							} else {
								PostViewsCounterManual.init( pvcManualCounterArgs );
							}
						}

						pvcLoadManualCounter( '" . esc_url( add_query_arg( 'ver', $pvc->defaults['version'], POST_VIEWS_COUNTER_URL . '/js/counter.js' ) ) . "', pvcExecuteManualCounter );
					};

					if ( document.readyState === 'loading' )
						document.addEventListener( 'DOMContentLoaded', pvcInitManualCounter, { once: true } );
					else
						pvcInitManualCounter();
				} )( window, document );
			</script>";
		}
	}

	/**
	 * Initialize counter.
	 *
	 * @return void
	 */
	public function init_counter() {
		// admin?
		if ( is_admin() && ! wp_doing_ajax() )
			return;

		// get main instance
		$pvc = Post_Views_Counter();

		// actions
		add_action( 'wp_ajax_pvc-view-posts', [ $this, 'queue_count' ] );
		add_action( 'wp_ajax_nopriv_pvc-view-posts', [ $this, 'queue_count' ] );
		add_action( 'wp_ajax_pvc-queue-runtime', [ $this, 'get_queue_runtime_data' ] );
		add_action( 'wp_ajax_nopriv_pvc-queue-runtime', [ $this, 'get_queue_runtime_data' ] );
		add_action( 'wp_print_footer_scripts', [ $this, 'print_queue_count' ], 11 );

		// php counter
		if ( $pvc->options['general']['counter_mode'] === 'php' )
			add_action( 'wp', [ $this, 'check_post_php' ] );
		// javascript (ajax) counter
		elseif ( $pvc->options['general']['counter_mode'] === 'js' ) {
			add_action( 'wp_ajax_pvc-check-post', [ $this, 'check_post_js' ] );
			add_action( 'wp_ajax_nopriv_pvc-check-post', [ $this, 'check_post_js' ] );
		}

		// rest api
		add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
	}

	/**
	 * Check whether to count visit.
	 *
	 * @param int $post_id
	 * @param array $content_data
	 * @param bool $views_only
	 *
	 * @return null|int
	 */
	public function check_post( $post_id = 0, $content_data = [], $views_only = false ) {
		$this->reset_pending_count_state();

		// force check cookie in short init mode
		if ( defined( 'SHORTINIT' ) && SHORTINIT )
			$this->check_cookie();

		// get post id
		$post_id = (int) ( empty( $post_id ) ? get_the_ID() : $post_id );

		// empty id?
		if ( empty( $post_id ) )
			return null;

		// get main instance
		$pvc = Post_Views_Counter();

		// get user id, from current user or static var in rest api request
		$user_id = get_current_user_id();

		// get user ip address
		$user_ip = $this->get_user_ip();
		$hook_content_data = $this->get_public_storage_hook_data( $content_data, 'post', $this->storage_type );

		// before visit action
		do_action( 'pvc_before_check_visit', $post_id, $user_id, $user_ip, 'post', $hook_content_data );

		// check all conditions to count visit
		add_filter( 'pvc_count_conditions_met', [ $this, 'check_conditions' ], 10, 6 );

		// check conditions - excluded ips, excluded groups
		$conditions_met = apply_filters( 'pvc_count_conditions_met', true, $post_id, $user_id, $user_ip, 'post', $hook_content_data );

		// conditions failed?
		if ( ! $conditions_met )
			return null;

		// do not count visit by default
		$count_visit = false;

		// cookieless data storage?
		if ( $pvc->options['general']['data_storage'] === 'cookieless' && $this->storage_type === 'cookieless' ) {
			$count_visit = $this->save_data_storage( $post_id, 'post', $content_data );
		} elseif ( $pvc->options['general']['data_storage'] === 'cookies' && $this->storage_type === 'cookies' ) {
			// php counter mode?
			if ( $pvc->options['general']['counter_mode'] === 'php' )
				$count_visit = $this->save_cookie( $post_id, $this->cookie );
			else
				$count_visit = $this->save_cookie_storage( $post_id, $content_data );
		}

		$visit_increment = 0;

		if ( ! $views_only && $this->window_created_candidate ) {
			$availability = $pvc->visits instanceof Post_Views_Counter_Visits ? $pvc->visits->get_visits_availability() : [ 'writable' => false ];

			if ( ! empty( $availability['writable'] ) )
				$visit_increment = 1;
		}

		// filter visit counting
		$count_visit = (bool) apply_filters( 'pvc_count_visit', $count_visit, $post_id, $user_id, $user_ip, 'post', $hook_content_data );

		// count visit
		if ( $count_visit ) {
			// before count visit action
			do_action( 'pvc_before_count_visit', $post_id, $user_id, $user_ip, 'post', $hook_content_data );

			$result = $this->count_visit( $post_id, $visit_increment );

			if ( $result !== null ) {
				if ( $views_only )
					$this->discard_pending_storage();
				else
					$this->commit_pending_storage();

				return $result;
			}
		}

		$this->discard_pending_storage();

		return null;
	}

	/**
	 * Check whether counting conditions are met.
	 *
	 * @param bool $allow_counting
	 * @param int $post_id
	 * @param int $user_id
	 * @param string $user_ip
	 * @param string $content_type
	 * @param array $content_data
	 *
	 * @return bool
	 */
	public function check_conditions( $allow_counting, $post_id, $user_id, $user_ip, $content_type, $content_data ) {
		// already failed?
		if ( ! $allow_counting )
			return false;

		// get main instance
		$pvc = Post_Views_Counter();

		// get ips
		$ips = $pvc->options['general']['exclude_ips'];

		// whether to count this ip
		if ( ! empty( $ips ) && $this->validate_user_ip( $user_ip ) ) {
			// check ips
			foreach ( $ips as $ip ) {
				if ( $this->is_excluded_ip( $user_ip, $ip ) )
					return false;
			}
		}

		// get groups to check them faster
		$groups = isset( $pvc->options['general']['exclude']['groups'] ) && is_array( $pvc->options['general']['exclude']['groups'] ) ? $pvc->options['general']['exclude']['groups'] : [];

		// whether to count this user
		if ( ! empty( $user_id ) ) {
			// exclude logged in users?
			if ( in_array( 'users', $groups, true ) )
				return false;
			// exclude specific roles?
			elseif ( in_array( 'roles', $groups, true ) && $this->is_user_role_excluded( $user_id, $pvc->options['general']['exclude']['roles'] ) )
				return false;
		// exclude guests?
		} elseif ( in_array( 'guests', $groups, true ) )
			return false;

		// whether to count robots
		if ( in_array( 'robots', $groups, true ) && $pvc->crawler->is_crawler() )
			return false;

		return $allow_counting;
	}

	/**
	 * Check whether real home page is displayed.
	 *
	 * @param object $object
	 *
	 * @return bool
	 */
	public function is_homepage( $object ) {
		$is_homepage = false;

		// get show on front option
		$show_on_front = get_option( 'show_on_front' );

		if ( $show_on_front === 'posts' )
			$is_homepage = is_home() && is_front_page();
		else {
			// home page
			$homepage = (int) get_option( 'page_on_front' );

			// posts page
			$postspage = (int) get_option( 'page_for_posts' );

			// both pages are set
			if ( $homepage && $postspage )
				$is_homepage = is_front_page();
			// only home page is set
			elseif ( $homepage && ! $postspage )
				$is_homepage = is_front_page();
			// only posts page is set
			elseif( ! $homepage && $postspage )
				$is_homepage = is_home() && ( empty( $object ) || get_queried_object_id() === 0 );
		}

		return $is_homepage;
	}

	/**
	 * Check whether posts page (archive) is displayed.
	 *
	 * @param object $object
	 *
	 * @return bool
	 */
	public function is_posts_page( $object ) {
		// get show on front option
		$show_on_front = get_option( 'show_on_front' );

		// get page for posts option
		$page_for_posts = (int) get_option( 'page_for_posts' );

		// check page
		$result = ( $show_on_front === 'page' && ! empty( $object ) && is_home() && is_a( $object, 'WP_Post' ) && (int) $object->ID === $page_for_posts );

		return apply_filters( 'pvc_is_posts_page', $result, $object );
	}

	/**
	 * Check whether to count visit via PHP request.
	 *
	 * @return void
	 */
	public function check_post_php() {
		// do not count admin entries
		if ( is_admin() && ! wp_doing_ajax() )
			return;

		// skip special requests
		if ( is_preview() || is_feed() || is_trackback() || ( function_exists( 'is_favicon' ) && is_favicon() ) || is_customize_preview() )
			return;

		// get main instance
		$pvc = Post_Views_Counter();

		// do we use php as counter?
		if ( $pvc->options['general']['counter_mode'] !== 'php' )
			return;

		// get countable post types
		$post_types = $pvc->options['general']['post_types_count'];

		// whether to count this post type
		if ( empty( $post_types ) || ! is_singular( $post_types ) )
			return;

		// get current post id
		$post_id = (int) get_the_ID();

		// allow to run check post?
		if ( ! (bool) apply_filters( 'pvc_run_check_post', true, $post_id ) )
			return;

		$this->check_post( $post_id );
	}

	/**
	 * Check whether to count visit via JavaScript (AJAX) request.
	 *
	 * @return void
	 */
	public function check_post_js() {
		// check conditions
		if ( ! isset( $_POST['action'], $_POST['id'], $_POST['storage_type'], $_POST['storage_data'], $_POST['pvc_nonce'] ) || ! wp_verify_nonce( $_POST['pvc_nonce'], 'pvc-check-post' ) )
			exit;

		// get post id
		$post_id = (int) $_POST['id'];

		if ( $post_id <= 0 )
			exit;

		// get main instance
		$pvc = Post_Views_Counter();

		// do we use javascript as counter?
		if ( $pvc->options['general']['counter_mode'] !== 'js' )
			exit;

		// get countable post types
		$post_types = $pvc->options['general']['post_types_count'];

		// check if post exists
		$post = get_post( $post_id );

		// whether to count this post type or not
		if ( empty( $post_types ) || empty( $post ) || ! in_array( $post->post_type, $post_types, true ) )
			exit;

		// get storage type
		$storage_type = sanitize_key( $_POST['storage_type'] );

		// invalid storage type?
		if ( ! in_array( $storage_type, [ 'cookies', 'cookieless' ], true ) )
			exit;

		// set storage type
		$this->storage_type = $storage_type;

		// cookieless data storage?
		if ( $storage_type === 'cookieless' && $pvc->options['general']['data_storage'] === 'cookieless' )
			$storage_data = $this->sanitize_storage_payload_set( $_POST['storage_data'], 'post', 'cookieless', isset( $_POST['storage_data_all'] ) ? $_POST['storage_data_all'] : '' );
		// cookies?
		elseif ( $storage_type === 'cookies' && $pvc->options['general']['data_storage'] === 'cookies' )
			$storage_data = $this->sanitize_storage_payload_set( $_POST['storage_data'], 'post', 'cookies', isset( $_POST['storage_data_all'] ) ? $_POST['storage_data_all'] : '' );
		else
			$storage_data = [];

		$storage_data = $this->mark_storage_capability( $storage_data, isset( $_POST['storage_capable'] ) ? $_POST['storage_capable'] : null );

		echo wp_json_encode(
			[
				'post_id'	=> $post_id,
				'counted'	=> ! ( $this->check_post( $post_id, $storage_data ) === null ),
				'storage'	=> $this->storage,
				'type'		=> 'post'
			]
		);

		exit;
	}

	/**
	 * Check whether to count visit via REST API request.
	 *
	 * @param object $request
	 *
	 * @return object|array
	 */
	public function check_post_rest_api( $request ) {
		// get main instance
		$pvc = Post_Views_Counter();

		// get post id (already sanitized)
		$post_id = $request->get_param( 'id' );

		// do we use REST API as counter?
		if ( $pvc->options['general']['counter_mode'] !== 'rest_api' )
			return new WP_Error( 'pvc_rest_api_disabled', __( 'REST API method is disabled.', 'post-views-counter' ), [ 'status' => 404 ] );

//TODO get current user id in direct api endpoint calls
		// check if post exists
		$post = get_post( $post_id );

		if ( ! $post )
			return new WP_Error( 'pvc_post_invalid_id', __( 'Invalid post ID.', 'post-views-counter' ), [ 'status' => 404 ] );

		// get countable post types
		$post_types = $pvc->options['general']['post_types_count'];

		// whether to count this post type
		if ( empty( $post_types ) || ! in_array( $post->post_type, $post_types, true ) )
			return new WP_Error( 'pvc_post_type_excluded', __( 'Post type excluded.', 'post-views-counter' ), [ 'status' => 404 ] );

		// get storage type
		$storage_type = sanitize_key( $request->get_param( 'storage_type' ) );

		// invalid storage type?
		if ( ! in_array( $storage_type, [ 'cookies', 'cookieless' ], true ) )
			return new WP_Error( 'pvc_invalid_storage_type', __( 'Invalid storage type.', 'post-views-counter' ), [ 'status' => 404 ] );

		// apply crawler/bot check filter
		$allowed = apply_filters( 'pvc_rest_api_count_post_check', true, $request, $post_id );

		if ( ! $allowed ) {
			return new WP_REST_Response( [
				'post_id'	=> $post_id,
				'counted'	=> false,
				'reason'	=> 'filtered',
				'storage'	=> [],
				'type'		=> 'post'
			], 200 );
		}

		// set storage type
		$this->storage_type = $storage_type;

		// cookieless data storage?
		if ( $storage_type === 'cookieless' && $pvc->options['general']['data_storage'] === 'cookieless' )
			$storage_data = $this->sanitize_storage_payload_set( $request->get_param( 'storage_data' ), 'post', 'cookieless', $request->get_param( 'storage_data_all' ) );
		// cookies?
		elseif ( $storage_type === 'cookies' && $pvc->options['general']['data_storage'] === 'cookies' )
			$storage_data = $this->sanitize_storage_payload_set( $request->get_param( 'storage_data' ), 'post', 'cookies', $request->get_param( 'storage_data_all' ) );
		else
			$storage_data = [];

		$storage_data = $this->mark_storage_capability( $storage_data, $request->get_param( 'storage_capable' ) );

		return [
			'post_id'	=> $post_id,
			'counted'	=> ! ( $this->check_post( $post_id, $storage_data ) === null ),
			'storage'	=> $this->storage,
			'type'		=> 'post'
		];
	}

	/**
	 * Initialize cookie session. Use $cookie to force custom data instead of real $_COOKIE.
	 *
	 * @param array $cookie
	 *
	 * @return void
	 */
	public function check_cookie( $cookie = [] ) {
		// do not run in admin except for ajax requests
		if ( is_admin() && ! wp_doing_ajax() )
			return;

		$this->cookie = $this->get_empty_storage_state();

		if ( empty( $cookie ) || ! is_array( $cookie ) ) {
			// assign cookie name
			$cookie_name = 'pvc_visits' . ( is_multisite() ? '_' . get_current_blog_id() : '' );

			// is cookie set?
			if ( isset( $_COOKIE[$cookie_name] ) && ! empty( $_COOKIE[$cookie_name] ) )
				$cookie = $_COOKIE[$cookie_name];
		}

		// cookie data?
		if ( $cookie && is_array( $cookie ) )
			$this->cookie = $this->sanitize_cookies_data( $this->combine_cookie_chunks( $cookie ), 'post' );
	}

	/**
	 * Get empty normalized storage state.
	 *
	 * @return array
	 */
	public function get_empty_storage_state() {
		return [
			'format'		=> 'empty',
			'version'		=> null,
			'session_id'	=> null,
			'started_at'	=> null,
			'expires_at'	=> null,
			'visited'		=> $this->get_empty_storage_buckets(),
			'legacy'		=> [
				'expirations' => $this->get_empty_storage_buckets()
			],
			'is_expired'	=> false,
			'is_valid'		=> true,
			'needs_writeback' => false
		];
	}

	/**
	 * Check whether normalized storage allows counting content.
	 *
	 * @param array $storage_state
	 * @param int $content_id
	 * @param string $content_type
	 * @param int $current_time
	 *
	 * @return bool
	 */
	public function storage_state_allows_count( $storage_state, $content_id, $content_type = 'post', $current_time = 0 ) {
		$content_type = $this->normalize_storage_bucket( $content_type );
		$current_time = (int) ( $current_time > 0 ? $current_time : current_time( 'timestamp', true ) );

		if ( ! $this->is_normalized_storage_state( $storage_state ) || ! $storage_state['is_valid'] )
			return true;

		if ( $storage_state['format'] === 'session' ) {
			if ( ! $this->use_session_storage_payload_writes() )
				return true;

			if ( $storage_state['is_expired'] )
				return true;

			return ! isset( $storage_state['visited'][$content_type][(int) $content_id] );
		}

		$legacy_expirations = $this->get_storage_state_bucket_expirations( $storage_state, $content_type, $current_time );

		return ! ( isset( $legacy_expirations[(int) $content_id] ) && $current_time < $legacy_expirations[(int) $content_id] );
	}

	/**
	 * Get relevant legacy expirations for normalized storage state.
	 *
	 * @param array $storage_state
	 * @param string $content_type
	 * @param int $current_time
	 *
	 * @return array
	 */
	public function get_storage_state_bucket_expirations( $storage_state, $content_type = 'post', $current_time = 0 ) {
		$content_type = $this->normalize_storage_bucket( $content_type );
		$current_time = (int) ( $current_time > 0 ? $current_time : current_time( 'timestamp', true ) );

		if ( ! $this->is_normalized_storage_state( $storage_state ) || ! $storage_state['is_valid'] )
			return [];

		if ( $storage_state['format'] === 'session' ) {
			if ( $storage_state['is_expired'] || empty( $storage_state['visited'][$content_type] ) || empty( $storage_state['expires_at'] ) )
				return [];

			$expires_at = (int) $storage_state['expires_at'];

			if ( $expires_at <= $current_time )
				return [];

			$expirations = [];

			foreach ( array_keys( $storage_state['visited'][$content_type] ) as $bucket_content_id ) {
				$expirations[(int) $bucket_content_id] = $expires_at;
			}

			return $expirations;
		}

		$expirations = [];

		foreach ( $storage_state['legacy']['expirations'][$content_type] as $bucket_content_id => $expiration ) {
			$bucket_content_id = (int) $bucket_content_id;
			$expiration = (int) $expiration;

			if ( $bucket_content_id > 0 && $expiration > $current_time )
				$expirations[$bucket_content_id] = $expiration;
		}

		return $expirations;
	}

	/**
	 * Get write expiration for normalized storage state.
	 *
	 * @param array $storage_state
	 * @param int $default_expiration
	 * @param int $current_time
	 *
	 * @return int
	 */
	public function get_storage_state_write_expiration( $storage_state, $default_expiration, $current_time = 0 ) {
		$current_time = (int) ( $current_time > 0 ? $current_time : current_time( 'timestamp', true ) );
		$default_expiration = (int) $default_expiration;

		if ( ! $this->is_normalized_storage_state( $storage_state ) || ! $storage_state['is_valid'] )
			return $default_expiration;

		if ( $storage_state['format'] === 'session' ) {
			$expires_at = (int) $storage_state['expires_at'];

			if ( ! $storage_state['is_expired'] && $expires_at > $current_time )
				return $expires_at;
		}

		return $default_expiration;
	}

	/**
	 * Build canonical session payload for storage state.
	 *
	 * @param array $storage_state
	 * @param int $content_id
	 * @param string $content_type
	 * @param int $default_expiration
	 * @param int $current_time
	 *
	 * @return array
	 */
	public function build_session_storage_payload( $storage_state, $content_id = 0, $content_type = 'post', $default_expiration = 0, $current_time = 0, $emit_hook = true ) {
		$session_state = $this->create_session_storage_state( $storage_state, $content_id, $content_type, $default_expiration, $current_time, $emit_hook );

		return $this->get_public_session_storage_payload( $session_state );
	}

	/**
	 * Merge normalized storage states into one canonical state.
	 *
	 * @param array $storage_states
	 * @param int $current_time
	 *
	 * @return array
	 */
	public function merge_storage_states( $storage_states, $current_time = 0 ) {
		$current_time = (int) ( $current_time > 0 ? $current_time : current_time( 'timestamp', true ) );
		$merged_state = $this->get_empty_storage_state();
		$active_session = null;
		$has_legacy_entries = false;

		if ( ! is_array( $storage_states ) )
			return $merged_state;

		foreach ( $storage_states as $storage_state ) {
			if ( is_array( $storage_state ) && isset( $storage_state['_pvc_storage_capable'] ) && $storage_state['_pvc_storage_capable'] === false ) {
				$merged_state['_pvc_storage_capable'] = false;
				continue;
			}

			if ( ! $this->is_normalized_storage_state( $storage_state ) || ! $storage_state['is_valid'] )
				continue;

			if ( $storage_state['format'] === 'session' && ! $storage_state['is_expired'] && ! empty( $storage_state['session_id'] ) && ! empty( $storage_state['started_at'] ) && ! empty( $storage_state['expires_at'] ) ) {
				if ( $active_session === null )
					$active_session = $storage_state;

				// merge all buckets from source state, including unregistered ones
				foreach ( array_keys( $storage_state['visited'] ) as $bucket ) {
					if ( ! isset( $merged_state['visited'][$bucket] ) ) {
						$merged_state['visited'][$bucket] = [];
						$merged_state['legacy']['expirations'][$bucket] = [];
					}

					foreach ( $storage_state['visited'][$bucket] as $bucket_content_id => $is_visited ) {
						if ( $is_visited )
							$merged_state['visited'][$bucket][(int) $bucket_content_id] = true;
					}
				}
			}

			foreach ( array_keys( $merged_state['legacy']['expirations'] ) as $bucket ) {
				foreach ( $this->get_storage_state_bucket_expirations( $storage_state, $bucket, $current_time ) as $bucket_content_id => $expiration ) {
					$bucket_content_id = (int) $bucket_content_id;
					$expiration = (int) $expiration;

					if ( $bucket_content_id <= 0 || $expiration <= $current_time )
						continue;

					$merged_state['legacy']['expirations'][$bucket][$bucket_content_id] = isset( $merged_state['legacy']['expirations'][$bucket][$bucket_content_id] ) ? max( $merged_state['legacy']['expirations'][$bucket][$bucket_content_id], $expiration ) : $expiration;
					$merged_state['visited'][$bucket][$bucket_content_id] = true;
					$has_legacy_entries = true;
				}
			}
		}

		if ( count( $merged_state['visited'] ) > self::STORAGE_MAX_BUCKETS ) {
			$merged_state['format'] = 'invalid';
			$merged_state['is_valid'] = false;
			$merged_state['_pvc_storage_capable'] = false;

			return $merged_state;
		}

		if ( $active_session !== null ) {
			$merged_state['format'] = 'session';
			$merged_state['version'] = 1;
			$merged_state['session_id'] = $active_session['session_id'];
			$merged_state['started_at'] = (int) $active_session['started_at'];
			$merged_state['expires_at'] = (int) $active_session['expires_at'];
			$merged_state['is_valid'] = true;
			$merged_state['is_expired'] = false;
			$merged_state['needs_writeback'] = false;

			return $this->limit_storage_state_members( $merged_state );
		}

		if ( $has_legacy_entries ) {
			$merged_state['format'] = 'legacy_map';
			$merged_state['is_valid'] = true;
		}

		return $this->limit_storage_state_members( $merged_state );
	}

	/**
	 * Keep merged storage membership within the request payload budget.
	 *
	 * @param array $storage_state Normalized storage state.
	 * @return array
	 */
	private function limit_storage_state_members( $storage_state ) {
		$member_count = 0;

		foreach ( $storage_state['visited'] as $bucket => $bucket_members ) {
			foreach ( array_keys( $bucket_members ) as $content_id ) {
				$member_count++;

				if ( $member_count <= self::STORAGE_MAX_MEMBERS )
					continue;

				unset( $storage_state['visited'][$bucket][$content_id], $storage_state['legacy']['expirations'][$bucket][$content_id] );
			}
		}

		return $storage_state;
	}

	/**
	 * Create normalized session storage state.
	 *
	 * @param array $storage_state
	 * @param int $content_id
	 * @param string $content_type
	 * @param int $default_expiration
	 * @param int $current_time
	 *
	 * @return array
	 */
	private function create_session_storage_state( $storage_state, $content_id = 0, $content_type = 'post', $default_expiration = 0, $current_time = 0, $emit_hook = true ) {
		$content_type = $this->normalize_storage_bucket( $content_type );
		$content_id = (int) $content_id;
		$current_time = (int) ( $current_time > 0 ? $current_time : current_time( 'timestamp', true ) );
		$default_expiration = (int) $default_expiration;
		$seed_state = $this->merge_storage_states( [ $storage_state ], $current_time );

		if ( $default_expiration < 0 )
			$default_expiration = 0;

		$session_expiration = $default_expiration > $current_time ? $default_expiration : $current_time;

		if ( $seed_state['format'] === 'session' && ! $seed_state['is_expired'] && ! empty( $seed_state['session_id'] ) && ! empty( $seed_state['started_at'] ) && ! empty( $seed_state['expires_at'] ) )
			$session_state = $seed_state;
		else {
			$session_state = $this->get_empty_storage_state();
			$session_state['format'] = 'session';
			$session_state['version'] = 1;
			$session_state['session_id'] = $this->generate_session_storage_id();
			$session_state['started_at'] = $current_time;
			$session_state['expires_at'] = $session_expiration;

			// new session created -- entrance/visit hook for the triggering content item
			if ( $content_id > 0 && $emit_hook ) {
				/**
				 * Fires when a new anonymous session is created.
				 *
				 * The content item that triggered the session is the entrance (landing page).
				 * Listeners can use this to record per-content visit/entrance metrics.
				 *
				 * @param array  $session_state  Normalized session state (format, session_id, started_at, expires_at, visited).
				 * @param int    $content_id      Content ID that triggered session creation.
				 * @param string $content_type    Content bucket: 'post', 'term', 'user', 'other'.
				 */
				do_action( 'pvc_session_created', $session_state, $content_id, $content_type );
			}
		}

		$session_state['format'] = 'session';
		$session_state['version'] = 1;
		$session_state['is_valid'] = true;
		$session_state['is_expired'] = ( (int) $session_state['expires_at'] <= $current_time );
		$session_state['needs_writeback'] = false;

		if ( $content_id > 0 && ! isset( $session_state['visited'][$content_type][$content_id] ) ) {
			$member_count = 0;

			foreach ( $session_state['visited'] as $bucket_members )
				$member_count += count( $bucket_members );

			if ( $member_count >= self::STORAGE_MAX_MEMBERS ) {
				foreach ( $session_state['visited'] as $bucket => $bucket_members ) {
					if ( empty( $bucket_members ) )
						continue;

					$remove_id = key( $bucket_members );
					unset( $session_state['visited'][$bucket][$remove_id] );
					break;
				}
			}

			$session_state['visited'][$content_type][$content_id] = true;
		}

		return $session_state;
	}

	/**
	 * Convert normalized session state to the public payload.
	 *
	 * @param array $storage_state
	 *
	 * @return array
	 */
	private function get_public_session_storage_payload( $storage_state ) {
		if ( ! $this->is_normalized_storage_state( $storage_state ) || ! $storage_state['is_valid'] || $storage_state['format'] !== 'session' )
			return [];

		$payload = [
			'version' => 1,
			'session_id' => (string) $storage_state['session_id'],
			'started_at' => (int) $storage_state['started_at'],
			'expires_at' => (int) $storage_state['expires_at'],
			'visited' => $this->get_empty_storage_buckets()
		];

		// emit all buckets present in state, including unregistered ones preserved by the tolerant reader
		foreach ( array_keys( $storage_state['visited'] ) as $bucket ) {
			if ( ! isset( $payload['visited'][$bucket] ) )
				$payload['visited'][$bucket] = [];

			$bucket_ids = array_map( 'intval', array_keys( $storage_state['visited'][$bucket] ) );
			sort( $bucket_ids, SORT_NUMERIC );
			$payload['visited'][$bucket] = $bucket_ids;
		}

		return $payload;
	}

	/**
	 * Generate an anonymous session identifier.
	 *
	 * @return string
	 */
	private function generate_session_storage_id() {
		if ( function_exists( 'wp_generate_uuid4' ) )
			return wp_generate_uuid4();

		return md5( uniqid( (string) wp_rand(), true ) );
	}

	/**
	 * Emit one browser storage cookie chunk.
	 *
	 * Single emission point for every counter cookie so path, domain, secure,
	 * httponly and SameSite stay identical for writes and deletions. The plugin
	 * requires PHP 7.4, so the options-array form is always available.
	 *
	 * @param string $name Full cookie name including its chunk suffix.
	 * @param string $value Chunk value; an empty string deletes the chunk.
	 * @param int    $expires Expiry timestamp; 1 deletes the chunk.
	 *
	 * @return bool
	 */
	private function set_storage_cookie( $name, $value, $expires ) {
		return $this->send_cookie(
			$name,
			(string) $value,
			[
				'expires'	=> (int) $expires,
				'path'		=> COOKIEPATH,
				'domain'	=> COOKIE_DOMAIN,
				'secure'	=> is_ssl(),
				'httponly'	=> false,
				'samesite'	=> 'LAX'
			]
		);
	}

	/**
	 * Transport seam for cookie emission.
	 *
	 * Isolated so the prepared attributes can be observed without a real HTTP
	 * response. Nothing but set_storage_cookie() may call it.
	 *
	 * @param string $name Cookie name.
	 * @param string $value Cookie value.
	 * @param array  $options PHP 7.4-compatible setcookie() options array.
	 *
	 * @return bool
	 */
	protected function send_cookie( $name, $value, $options ) {
		return setcookie( $name, $value, $options );
	}

	/**
	 * Clear stale cookie chunks that are no longer used by the current payload.
	 *
	 * @param string $cookie_name
	 * @param int $valid_chunk_count
	 *
	 * @return void
	 */
	private function clear_stale_cookie_chunks( $cookie_name, $valid_chunk_count ) {
		if ( ! isset( $_COOKIE[$cookie_name] ) || ! is_array( $_COOKIE[$cookie_name] ) )
			return;

		foreach ( array_keys( $_COOKIE[$cookie_name] ) as $chunk_index ) {
			$chunk_index = (int) $chunk_index;

			if ( $chunk_index < $valid_chunk_count )
				continue;

			$this->set_storage_cookie( $cookie_name . '[' . $chunk_index . ']', '', 1 );
		}
	}

	/**
	 * Sanitize storage data.
	 *
	 * @param string $storage_data
	 * @param string|null $content_type
	 *
	 * @return array
	 */
	public function sanitize_storage_data( $storage_data, $content_type = null ) {
		$normalized_state = $this->normalize_storage_state( $storage_data, is_string( $content_type ) ? $content_type : 'post', 'auto' );

		if ( $content_type === null )
			return $this->get_legacy_storage_data_result( $normalized_state );

		return $normalized_state;
	}

	/**
	 * Sanitize cookies.
	 *
	 * @param string $storage_data
	 * @param string|null $content_type
	 *
	 * @return array
	 */
	public function sanitize_cookies_data( $storage_data, $content_type = null ) {
		$normalized_state = $this->normalize_storage_state( $storage_data, is_string( $content_type ) ? $content_type : 'post', 'auto' );

		if ( $content_type === null )
			return $this->get_legacy_cookie_data_result( $normalized_state );

		return $normalized_state;
	}

	/**
	 * Sanitize and merge a set of storage payloads.
	 *
	 * @param mixed $storage_data
	 * @param string $content_type
	 * @param string $storage_type
	 * @param mixed $storage_data_all
	 *
	 * @return array
	 */
	public function sanitize_storage_payload_set( $storage_data, $content_type, $storage_type, $storage_data_all = '' ) {
		$content_type = $this->normalize_storage_bucket( $content_type );
		$storage_payloads = $this->parse_storage_payload_map( $storage_data_all );

		if ( isset( $storage_payloads['_pvc_storage_capable'] ) && $storage_payloads['_pvc_storage_capable'] === false ) {
			$state = $this->get_empty_storage_state();
			$state['_pvc_storage_capable'] = false;

			return $state;
		}

		if ( empty( $storage_payloads ) ) {
			if ( $storage_type === 'cookies' )
				return $this->sanitize_cookies_data( $storage_data, $content_type );

			return $this->sanitize_storage_data( $storage_data, $content_type );
		}

		if ( ! array_key_exists( $content_type, $storage_payloads ) && ( is_scalar( $storage_data ) || is_array( $storage_data ) ) )
			$storage_payloads[$content_type] = $storage_data;

		$storage_states = [];

		foreach ( $storage_payloads as $bucket => $bucket_storage_data ) {
			if ( $storage_type === 'cookies' )
				$storage_states[] = $this->sanitize_cookies_data( $bucket_storage_data, $bucket );
			else
				$storage_states[] = $this->sanitize_storage_data( $bucket_storage_data, $bucket );
		}

		return $this->merge_storage_states( $storage_states );
	}

	/**
	 * Parse a serialized map of storage payloads.
	 *
	 * @param mixed $storage_data_all
	 *
	 * @return array
	 */
	public function parse_storage_payload_map( $storage_data_all ) {
		if ( is_scalar( $storage_data_all ) ) {
			$storage_data_all = trim( (string) $storage_data_all );

			if ( $storage_data_all === '' )
				return [];

			if ( strlen( $storage_data_all ) > self::STORAGE_MAX_PAYLOAD_BYTES )
				return [ '_pvc_storage_capable' => false ];

			$decoded_payloads = json_decode( stripslashes( $storage_data_all ), true, 8 );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded_payloads ) )
				return [];

			if ( count( $decoded_payloads ) > self::STORAGE_MAX_BUCKETS )
				return [ '_pvc_storage_capable' => false ];
		} elseif ( is_array( $storage_data_all ) ) {
			if ( count( $storage_data_all ) > self::STORAGE_MAX_BUCKETS )
				return [ '_pvc_storage_capable' => false ];

			$decoded_payloads = $storage_data_all;
		} else
			return [];

		$storage_payloads = [];

		foreach ( array_keys( $this->get_empty_storage_buckets() ) as $bucket ) {
			if ( isset( $decoded_payloads[$bucket] ) && ( is_scalar( $decoded_payloads[$bucket] ) || is_array( $decoded_payloads[$bucket] ) ) )
				$storage_payloads[$bucket] = $decoded_payloads[$bucket];
		}

		return $storage_payloads;
	}

	/**
	 * Check whether the active extension plugin supports session payload writes.
	 *
	 * @return bool
	 */
	private function is_active_session_storage_payload_writes() {
		if ( ! class_exists( 'Post_Views_Counter_Pro' ) )
			return true;

		if ( ! function_exists( 'Post_Views_Counter_Pro' ) )
			return false;

		$pro = Post_Views_Counter_Pro();

		return ( is_object( $pro ) && method_exists( $pro, 'supports_session_storage_payload_writes' ) && $pro->supports_session_storage_payload_writes() );
	}

	/**
	 * Check whether session payload writes are enabled.
	 *
	 * Session payload writes are the default for Core-only installs. With an
	 * active extension, its declared capability determines the default. This
	 * filter can override it for controlled testing or emergency rollback.
	 *
	 * @return bool
	 */
	public function use_session_storage_payload_writes() {
		return (bool) apply_filters( 'pvc_use_session_storage_payload_writes', $this->is_active_session_storage_payload_writes() );
	}

	/**
	 * Build legacy expiration payload for a storage bucket.
	 *
	 * @param array $storage_state
	 * @param int $content_id
	 * @param string $content_type
	 * @param int $default_expiration
	 * @param int $current_time
	 *
	 * @return array
	 */
	public function build_legacy_storage_payload( $storage_state, $content_id = 0, $content_type = 'post', $default_expiration = 0, $current_time = 0 ) {
		$content_type = $this->normalize_storage_bucket( $content_type );
		$content_id = (int) $content_id;
		$current_time = (int) ( $current_time > 0 ? $current_time : current_time( 'timestamp', true ) );
		$rewriting_session_payload = ( $this->is_normalized_storage_state( $storage_state ) && $storage_state['format'] === 'session' && ! $this->use_session_storage_payload_writes() );
		$bucket_expirations = [];

		if ( ! $rewriting_session_payload )
			$bucket_expirations = $this->get_storage_state_bucket_expirations( $storage_state, $content_type, $current_time );

		$write_expiration = $rewriting_session_payload ? (int) $default_expiration : $this->get_storage_state_write_expiration( $storage_state, $default_expiration, $current_time );

		if ( $content_id > 0 && $write_expiration > $current_time )
			$bucket_expirations[$content_id] = $write_expiration;

		ksort( $bucket_expirations, SORT_NUMERIC );

		while ( count( $bucket_expirations ) > self::STORAGE_MAX_MEMBERS || strlen( $this->serialize_legacy_cookie_payload( $bucket_expirations ) ) > self::STORAGE_MAX_PAYLOAD_BYTES ) {
			$remove_id = key( $bucket_expirations );

			if ( (int) $remove_id === $content_id ) {
				next( $bucket_expirations );
				$remove_id = key( $bucket_expirations );
			}

			if ( $remove_id === null )
				break;

			unset( $bucket_expirations[$remove_id] );
			reset( $bucket_expirations );
		}

		return $bucket_expirations;
	}

	/**
	 * Build chunked legacy cookie payload data.
	 *
	 * @param array $storage_state
	 * @param string $cookie_name
	 * @param int $content_id
	 * @param string $content_type
	 * @param int $default_expiration
	 * @param int $current_time
	 *
	 * @return array
	 */
	public function build_legacy_cookie_storage_data( $storage_state, $cookie_name, $content_id = 0, $content_type = 'post', $default_expiration = 0, $current_time = 0 ) {
		$bucket_expirations = $this->build_legacy_storage_payload( $storage_state, $content_id, $content_type, $default_expiration, $current_time );
		$payload = $this->serialize_legacy_cookie_payload( $bucket_expirations );

		if ( $payload === '' ) {
			return [
				'name'		=> [ $cookie_name . '[0]' ],
				'value'		=> [ '' ],
				'expiry'	=> [ 1 ]
			];
		}

		$cookies_data = [
			'name'		=> [],
			'value'		=> [],
			'expiry'	=> []
		];
		$cookie_chunks = str_split( $payload, 3980 );

		if ( count( $cookie_chunks ) > self::STORAGE_MAX_COOKIE_CHUNKS )
			return [ 'name' => [], 'value' => [], 'expiry' => [] ];

		$cookie_expiration = max( $bucket_expirations );

		foreach ( $cookie_chunks as $key => $value ) {
			$cookies_data['name'][] = $cookie_name . '[' . $key . ']';
			$cookies_data['value'][] = $value;
			$cookies_data['expiry'][] = $cookie_expiration;
		}

		return $cookies_data;
	}

	/**
	 * Get legacy-compatible cookieless storage data.
	 *
	 * @param array $storage_state
	 *
	 * @return array
	 */
	private function get_legacy_storage_data_result( $storage_state ) {
		return $this->flatten_storage_state_expirations( $storage_state );
	}

	/**
	 * Get legacy-compatible cookie data.
	 *
	 * @param array $storage_state
	 *
	 * @return array
	 */
	private function get_legacy_cookie_data_result( $storage_state ) {
		$expirations = $this->flatten_storage_state_expirations( $storage_state );

		return [
			'visited'		=> $expirations,
			'expiration'	=> empty( $expirations ) ? 0 : max( $expirations )
		];
	}

	/**
	 * Flatten normalized storage state to legacy expiration map.
	 *
	 * @param array $storage_state
	 *
	 * @return array
	 */
	private function flatten_storage_state_expirations( $storage_state ) {
		$expirations = [];

		if ( ! $this->is_normalized_storage_state( $storage_state ) || ! $storage_state['is_valid'] )
			return $expirations;

		foreach ( array_keys( $storage_state['legacy']['expirations'] ) as $bucket ) {
			foreach ( $this->get_storage_state_bucket_expirations( $storage_state, $bucket ) as $content_id => $expiration ) {
				$expirations[(int) $content_id] = (int) $expiration;
			}
		}

		return $expirations;
	}

	/**
	 * Get legacy-compatible hook payload for storage state.
	 *
	 * @param array $storage_state
	 * @param string $content_type
	 * @param string $storage_type
	 *
	 * @return array
	 */
	private function get_public_storage_hook_data( $storage_state, $content_type, $storage_type ) {
		if ( ! $this->is_normalized_storage_state( $storage_state ) )
			return $storage_state;

		$bucket_expirations = $this->get_storage_state_bucket_expirations( $storage_state, $content_type );

		if ( $storage_type === 'cookies' ) {
			return [
				'visited'		=> $bucket_expirations,
				'expiration'	=> empty( $bucket_expirations ) ? 0 : max( $bucket_expirations )
			];
		}

		return $bucket_expirations;
	}

	/**
	 * Get legacy-compatible cookie filter payload.
	 *
	 * @param array $storage_state
	 * @param string $content_type
	 *
	 * @return array
	 */
	private function get_public_cookie_filter_data( $storage_state, $content_type ) {
		if ( ! $this->is_normalized_storage_state( $storage_state ) )
			return $storage_state;

		$bucket_expirations = $this->get_storage_state_bucket_expirations( $storage_state, $content_type );

		if ( empty( $bucket_expirations ) )
			return [];

		return [
			'exists'		=> true,
			'visited_posts'	=> $bucket_expirations,
			'expiration'	=> max( $bucket_expirations )
		];
	}

	/**
	 * Serialize legacy cookie payload.
	 *
	 * @param array $bucket_expirations
	 *
	 * @return string
	 */
	private function serialize_legacy_cookie_payload( $bucket_expirations ) {
		if ( empty( $bucket_expirations ) || ! is_array( $bucket_expirations ) )
			return '';

		ksort( $bucket_expirations, SORT_NUMERIC );

		$segments = [];

		foreach ( $bucket_expirations as $bucket_content_id => $expiration ) {
			$bucket_content_id = (int) $bucket_content_id;
			$expiration = (int) $expiration;

			if ( $bucket_content_id > 0 && $expiration > 0 )
				$segments[] = $expiration . 'b' . $bucket_content_id;
		}

		return implode( 'a', $segments );
	}

	/**
	 * Reconstruct a cookie payload from chunks.
	 *
	 * Legacy chunked cookies need an "a" separator between chunks, while JSON payloads need a direct concat.
	 *
	 * @param array $cookie_chunks
	 *
	 * @return string
	 */
	private function combine_cookie_chunks( $cookie_chunks ) {
		$chunks = [];
		$payload_bytes = 0;

		if ( ! is_array( $cookie_chunks ) || count( $cookie_chunks ) > self::STORAGE_MAX_COOKIE_CHUNKS )
			return '__pvc_invalid_storage_payload__';

		ksort( $cookie_chunks, SORT_NUMERIC );

		if ( ! empty( $cookie_chunks ) && array_keys( $cookie_chunks ) !== range( 0, count( $cookie_chunks ) - 1 ) )
			return '__pvc_invalid_storage_payload__';

		foreach ( $cookie_chunks as $chunk ) {
			if ( ! is_scalar( $chunk ) )
				return '__pvc_invalid_storage_payload__';

			$chunk = (string) $chunk;
			$payload_bytes += strlen( $chunk );

			if ( $payload_bytes > self::STORAGE_MAX_PAYLOAD_BYTES )
				return '__pvc_invalid_storage_payload__';

			$chunks[] = $chunk;
		}

		if ( empty( $chunks ) )
			return '';

		$json_payload = implode( '', $chunks );

		if ( $this->looks_like_json_storage( trim( $json_payload ) ) ) {
			$json_data = json_decode( stripslashes( $json_payload ), true, 8 );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_data ) && isset( $json_data['version'] ) )
				return $json_payload;
		}

		return implode( 'a', $chunks );
	}

	/**
	 * Normalize storage state.
	 *
	 * @param mixed $storage_data
	 * @param string $content_type
	 * @param string $format_hint
	 *
	 * @return array
	 */
	private function normalize_storage_state( $storage_data, $content_type = 'post', $format_hint = 'auto' ) {
		$content_type = $this->normalize_storage_bucket( $content_type );
		$state = $this->get_empty_storage_state();

		if ( is_array( $storage_data ) ) {
			$encoded_storage = wp_json_encode( $storage_data );

			if ( ! is_string( $encoded_storage ) || strlen( $encoded_storage ) > self::STORAGE_MAX_PAYLOAD_BYTES ) {
				$state['format'] = 'invalid';
				$state['is_valid'] = false;
				$state['_pvc_storage_capable'] = false;

				return $state;
			}

			return $this->normalize_json_storage_state( $storage_data, $content_type );
		}

		if ( ! is_scalar( $storage_data ) ) {
			$state['format'] = 'invalid';
			$state['is_valid'] = false;

			return $state;
		}

		$storage_data = trim( (string) $storage_data );

		if ( $storage_data === '__pvc_invalid_storage_payload__' ) {
			$state['format'] = 'invalid';
			$state['is_valid'] = false;
			$state['_pvc_storage_capable'] = false;

			return $state;
		}

		if ( strlen( $storage_data ) > self::STORAGE_MAX_PAYLOAD_BYTES ) {
			$state['format'] = 'invalid';
			$state['is_valid'] = false;
			$state['_pvc_storage_capable'] = false;

			return $state;
		}

		if ( $storage_data === '' )
			return $state;

		if ( $format_hint !== 'legacy_cookie' && $this->looks_like_json_storage( $storage_data ) ) {
			$json_storage = json_decode( stripslashes( $storage_data ), true, 8 );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_storage ) )
				return $this->normalize_json_storage_state( $json_storage, $content_type );
		}

		if ( $format_hint !== 'legacy_map' && preg_match( '/^(([0-9]+b[0-9]+a?)+)$/', $storage_data ) === 1 )
			return $this->normalize_legacy_cookie_state( $storage_data, $content_type );

		$state['format'] = 'invalid';
		$state['is_valid'] = false;

		return $state;
	}

	/**
	 * Normalize decoded JSON storage state.
	 *
	 * @param array $storage_data
	 * @param string $content_type
	 *
	 * @return array
	 */
	private function normalize_json_storage_state( $storage_data, $content_type ) {
		if ( empty( $storage_data ) )
			return $this->get_empty_storage_state();

		if ( isset( $storage_data['version'] ) )
			return $this->normalize_session_storage_state( $storage_data );

		return $this->normalize_legacy_map_state( $storage_data, $content_type );
	}

	/**
	 * Normalize session storage state.
	 *
	 * @param array $storage_data
	 *
	 * @return array
	 */
	private function normalize_session_storage_state( $storage_data ) {
		$state = $this->get_empty_storage_state();
		$state['format'] = 'session';
		$state['version'] = isset( $storage_data['version'] ) ? (int) $storage_data['version'] : null;

		if ( $state['version'] !== 1 ) {
			$state['format'] = 'invalid';
			$state['is_valid'] = false;

			return $state;
		}

		$session_id = isset( $storage_data['session_id'] ) && is_scalar( $storage_data['session_id'] ) ? sanitize_text_field( wp_unslash( (string) $storage_data['session_id'] ) ) : '';
		$started_at = isset( $storage_data['started_at'] ) ? (int) $storage_data['started_at'] : 0;
		$expires_at = isset( $storage_data['expires_at'] ) ? (int) $storage_data['expires_at'] : 0;

		if ( $session_id === '' || $started_at <= 0 || $expires_at <= 0 || $expires_at < $started_at || ! isset( $storage_data['visited'] ) || ! is_array( $storage_data['visited'] ) || count( $storage_data['visited'] ) > self::STORAGE_MAX_BUCKETS ) {
			$state['format'] = 'invalid';
			$state['is_valid'] = false;

			if ( isset( $storage_data['visited'] ) && is_array( $storage_data['visited'] ) && count( $storage_data['visited'] ) > self::STORAGE_MAX_BUCKETS )
				$state['_pvc_storage_capable'] = false;

			return $state;
		}

		$state['session_id'] = $session_id;
		$state['started_at'] = $started_at;
		$state['expires_at'] = $expires_at;
		$state['is_expired'] = current_time( 'timestamp', true ) >= $expires_at;

		// populate registered buckets from payload
		foreach ( array_keys( $state['visited'] ) as $bucket ) {
			if ( isset( $storage_data['visited'][$bucket] ) )
				$state['visited'][$bucket] = $this->normalize_session_bucket_membership( $storage_data['visited'][$bucket] );
		}

		// preserve unregistered buckets from payload (tolerant reader)
		foreach ( $storage_data['visited'] as $bucket => $bucket_data ) {
			if ( ! isset( $state['visited'][$bucket] ) && is_array( $bucket_data ) ) {
				$bucket = sanitize_key( $bucket );

				if ( $bucket !== '' ) {
					$state['visited'][$bucket] = $this->normalize_session_bucket_membership( $bucket_data );
					$state['legacy']['expirations'][$bucket] = [];
				}
			}
		}

		if ( count( $state['visited'] ) > self::STORAGE_MAX_BUCKETS ) {
			$state['format'] = 'invalid';
			$state['is_valid'] = false;
			$state['_pvc_storage_capable'] = false;

			return $state;
		}

		$member_count = 0;

		foreach ( $state['visited'] as $bucket_members )
			$member_count += count( $bucket_members );

		if ( $member_count > self::STORAGE_MAX_MEMBERS ) {
			$state['format'] = 'invalid';
			$state['is_valid'] = false;
			$state['_pvc_storage_capable'] = false;
		}

		return $state;
	}

	/**
	 * Normalize legacy map storage state.
	 *
	 * @param array $storage_data
	 * @param string $content_type
	 *
	 * @return array
	 */
	private function normalize_legacy_map_state( $storage_data, $content_type ) {
		$state = $this->get_empty_storage_state();
		$valid_items = 0;
		$state['format'] = 'legacy_map';

		foreach ( $storage_data as $content_id => $expiration ) {
			$content_id = (int) $content_id;
			$expiration = (int) $expiration;

			if ( $content_id <= 0 || $expiration <= 0 )
				continue;

			$state['visited'][$content_type][$content_id] = true;
			$state['legacy']['expirations'][$content_type][$content_id] = $expiration;
			$valid_items++;

			if ( $valid_items > self::STORAGE_MAX_MEMBERS ) {
				$state['format'] = 'invalid';
				$state['is_valid'] = false;
				$state['_pvc_storage_capable'] = false;

				return $state;
			}
		}

		if ( $valid_items === 0 ) {
			$state['format'] = 'invalid';
			$state['is_valid'] = false;
		}

		return $state;
	}

	/**
	 * Normalize legacy cookie storage state.
	 *
	 * @param string $storage_data
	 * @param string $content_type
	 *
	 * @return array
	 */
	private function normalize_legacy_cookie_state( $storage_data, $content_type ) {
		$state = $this->get_empty_storage_state();
		$state['format'] = 'legacy_cookie';

		foreach ( explode( 'a', $storage_data ) as $pair ) {
			$pair = explode( 'b', $pair );

			if ( count( $pair ) !== 2 )
				continue;

			$expiration = (int) $pair[0];
			$content_id = (int) $pair[1];

			if ( $content_id <= 0 || $expiration <= 0 )
				continue;

			$state['visited'][$content_type][$content_id] = true;
			$state['legacy']['expirations'][$content_type][$content_id] = $expiration;

			if ( count( $state['visited'][$content_type] ) > self::STORAGE_MAX_MEMBERS ) {
				$state['format'] = 'invalid';
				$state['is_valid'] = false;
				$state['_pvc_storage_capable'] = false;

				return $state;
			}
		}

		if ( empty( $state['legacy']['expirations'][$content_type] ) ) {
			$state['format'] = 'invalid';
			$state['is_valid'] = false;
		}

		return $state;
	}

	/**
	 * Normalize session bucket membership.
	 *
	 * @param array $bucket_data
	 *
	 * @return array
	 */
	private function normalize_session_bucket_membership( $bucket_data ) {
		$members = [];

		if ( ! is_array( $bucket_data ) )
			return $members;

		foreach ( $bucket_data as $key => $value ) {
			$content_id = 0;

			if ( is_int( $key ) )
				$content_id = (int) $value;
			else {
				$content_id = (int) $key;

				if ( $content_id <= 0 && is_scalar( $value ) )
					$content_id = (int) $value;
			}

			if ( $content_id > 0 )
				$members[$content_id] = true;
		}

		return $members;
	}

	/**
	 * Check whether string looks like JSON storage.
	 *
	 * @param string $storage_data
	 *
	 * @return bool
	 */
	private function looks_like_json_storage( $storage_data ) {
		return ( strlen( $storage_data ) > 1 && $storage_data[0] === '{' && substr( $storage_data, -1 ) === '}' );
	}

	/**
	 * Check whether storage state is normalized.
	 *
	 * @param mixed $storage_state
	 *
	 * @return bool
	 */
	private function is_normalized_storage_state( $storage_state ) {
		return ( is_array( $storage_state ) && isset( $storage_state['format'], $storage_state['visited'], $storage_state['legacy']['expirations'], $storage_state['is_valid'], $storage_state['is_expired'] ) );
	}

	/**
	 * Get empty storage buckets.
	 *
	 * Filterable via pvc_storage_buckets so that extensions can register additional content-type buckets.
	 * PVC free registers only 'post'. Additional buckets can be added by integrations.
	 *
	 * @return array
	 */
	public function get_empty_storage_buckets() {
		$buckets = apply_filters( 'pvc_storage_buckets', [
			'post' => []
		] );

		if ( ! is_array( $buckets ) || empty( $buckets ) )
			return [ 'post' => [] ];

		$buckets = array_slice( $buckets, 0, self::STORAGE_MAX_BUCKETS, true );

		// ensure all bucket values are arrays
		foreach ( $buckets as $key => $value ) {
			if ( ! is_array( $value ) )
				$buckets[$key] = [];
		}

		return $buckets;
	}

	/**
	 * Normalize storage bucket name.
	 *
	 * Validates against the registered bucket list from get_empty_storage_buckets().
	 *
	 * @param string $content_type
	 *
	 * @return string
	 */
	public function normalize_storage_bucket( $content_type ) {
		$content_type = sanitize_key( $content_type );
		$registered_buckets = array_keys( $this->get_empty_storage_buckets() );

		return in_array( $content_type, $registered_buckets, true ) ? $content_type : 'post';
	}

	/**
	 * Save data storage.
	 *
	 * @param int $content
	 * @param string $content_type
	 * @param array $content_data
	 *
	 * @return bool
	 */
	private function save_data_storage( $content, $content_type, $content_data ) {
		if ( isset( $content_data['_pvc_storage_capable'] ) && $content_data['_pvc_storage_capable'] === false ) {
			$this->storage = [];

			return true;
		}

		// get base instance
		$pvc = Post_Views_Counter();

		// get expiration
		$expiration = $this->get_timestamp( $pvc->options['general']['time_between_counts']['type'], $pvc->options['general']['time_between_counts']['number'] );
		$current_time = current_time( 'timestamp', true );
		$count_visit = $this->storage_state_allows_count( $content_data, $content, $content_type, $current_time );

		if ( ! $count_visit ) {
			$this->storage = [];

			return false;
		}

		if ( $this->use_session_storage_payload_writes() ) {
			$this->window_created_candidate = $this->storage_state_creates_window( $content_data );
			$this->storage = $this->build_session_storage_payload( $content_data, $content, $content_type, $expiration, $current_time, false );
			$this->prepare_session_created_hook( $this->storage, $content, $content_type );
		}
		else
			$this->storage = [ $content_type => $this->build_legacy_storage_payload( $content_data, $content, $content_type, $expiration, $current_time ) ];

		$this->pending_storage_commit = [ 'type' => 'response' ];

		return $count_visit;
	}

	/**
	 * Save cookie storage.
	 *
	 * @param int $content
	 * @param array $content_data
	 *
	 * @return bool
	 */
	private function save_cookie_storage( $content, $content_data ) {
		if ( isset( $content_data['_pvc_storage_capable'] ) && $content_data['_pvc_storage_capable'] === false ) {
			$this->storage = [];

			return true;
		}

		// early return?
//TODO check this filter in js
		// if ( apply_filters( 'pvc_maybe_set_cookie', true, $content, $content_type, $content_data ) !== true )
			// return;

		// get base instance
		$pvc = Post_Views_Counter();

		// get expiration
		$expiration = $this->get_timestamp( $pvc->options['general']['time_between_counts']['type'], $pvc->options['general']['time_between_counts']['number'] );
		$current_time = current_time( 'timestamp', true );
		$count_visit = $this->storage_state_allows_count( $content_data, $content, 'post', $current_time );

		if ( ! $count_visit ) {
			$this->storage = [];

			return false;
		}

		// assign cookie name
		$cookie_name = 'pvc_visits' . ( is_multisite() ? '_' . get_current_blog_id() : '' );

		if ( ! $this->use_session_storage_payload_writes() ) {
			$this->storage = $this->build_legacy_cookie_storage_data( $content_data, $cookie_name, $content, 'post', $expiration, $current_time );
			$this->pending_storage_commit = [ 'type' => 'response' ];

			return $count_visit;
		}

		$this->window_created_candidate = $this->storage_state_creates_window( $content_data );
		$session_payload = $this->build_session_storage_payload( $content_data, $content, 'post', $expiration, $current_time, false );
		$session_json = wp_json_encode( $session_payload );

		if ( ! is_string( $session_json ) || $session_json === '' || strlen( $session_json ) > self::STORAGE_MAX_PAYLOAD_BYTES ) {
			$this->storage = [];

			return false;
		}

		$cookies_data = [
			'name'		=> [],
			'value'		=> [],
			'expiry'	=> []
		];
		$cookie_chunks = str_split( $session_json, 3980 );
		$cookie_expiration = (int) $session_payload['expires_at'];

		foreach ( $cookie_chunks as $key => $value ) {
			$cookies_data['name'][] = $cookie_name . '[' . $key . ']';
			$cookies_data['value'][] = $value;
			$cookies_data['expiry'][] = $cookie_expiration;
		}

		$this->storage = $cookies_data;
		$this->pending_storage_commit = [ 'type' => 'response' ];
		$this->prepare_session_created_hook( $session_payload, $content, 'post' );

		return $count_visit;
	}

	/**
	 * Save cookie function.
	 *
	 * @param int $id
	 * @param array $cookie
	 *
	 * @return bool|void
	 */
	private function save_cookie( $id, $cookie = [] ) {
		// early return?
		if ( apply_filters( 'pvc_maybe_set_cookie', true, $id, 'post', $this->get_public_cookie_filter_data( $cookie, 'post' ) ) !== true )
			return;

		// get main instance
		$pvc = Post_Views_Counter();

		// get expiration
		$expiration = $this->get_timestamp( $pvc->options['general']['time_between_counts']['type'], $pvc->options['general']['time_between_counts']['number'] );
		$current_time = current_time( 'timestamp', true );
		$count_visit = $this->storage_state_allows_count( $cookie, $id, 'post', $current_time );

		if ( ! $count_visit )
			return false;

		// assign cookie name
		$cookie_name = 'pvc_visits' . ( is_multisite() ? '_' . get_current_blog_id() : '' );

		if ( ! $this->use_session_storage_payload_writes() ) {
			$legacy_payload = $this->serialize_legacy_cookie_payload( $this->build_legacy_storage_payload( $cookie, $id, 'post', $expiration, $current_time ) );
			$cookies_data = $this->build_legacy_cookie_storage_data( $cookie, $cookie_name, $id, 'post', $expiration, $current_time );
			$this->pending_storage_commit = [
				'type' => 'cookie',
				'cookies' => $cookies_data,
				'cookie_name' => $cookie_name,
				'normalized_payload' => $legacy_payload
			];

			return $count_visit;
		}

		$this->window_created_candidate = $this->storage_state_creates_window( $cookie );
		$session_payload = $this->build_session_storage_payload( $cookie, $id, 'post', $expiration, $current_time, false );
		$session_json = wp_json_encode( $session_payload );

		if ( ! is_string( $session_json ) || $session_json === '' || strlen( $session_json ) > self::STORAGE_MAX_PAYLOAD_BYTES )
			return false;

		$cookie_chunks = str_split( $session_json, 3980 );
		$cookie_expiration = (int) $session_payload['expires_at'];

		$cookies_data = [ 'name' => [], 'value' => [], 'expiry' => [] ];

		foreach ( $cookie_chunks as $key => $value ) {
			$cookies_data['name'][] = $cookie_name . '[' . $key . ']';
			$cookies_data['value'][] = $value;
			$cookies_data['expiry'][] = $cookie_expiration;
		}

		$this->pending_storage_commit = [
			'type' => 'cookie',
			'cookies' => $cookies_data,
			'cookie_name' => $cookie_name,
			'normalized_payload' => $session_json
		];
		$this->prepare_session_created_hook( $session_payload, $id, 'post' );

		return $count_visit;
	}

	/**
	 * Reset temporary decision state at every public count operation boundary.
	 *
	 * @return void
	 */
	private function reset_pending_count_state() {
		$this->storage = [];
		$this->pending_storage_commit = null;
		$this->pending_session_created = null;
		$this->window_created_candidate = false;
	}

	/**
	 * Drop uncommitted browser state after a filtered or failed writer attempt.
	 *
	 * @return void
	 */
	private function discard_pending_storage() {
		$this->storage = [];
		$this->pending_storage_commit = null;
		$this->pending_session_created = null;
		$this->window_created_candidate = false;
	}

	/**
	 * Commit the prepared browser response/cookie only after writer success.
	 *
	 * @return void
	 */
	private function commit_pending_storage() {
		if ( is_array( $this->pending_storage_commit ) && $this->pending_storage_commit['type'] === 'cookie' ) {
			$this->emit_cookie_storage( $this->pending_storage_commit );
			$this->cookie = $this->sanitize_cookies_data( $this->pending_storage_commit['normalized_payload'], 'post' );
		}

		if ( is_array( $this->pending_session_created ) )
			do_action( 'pvc_session_created', $this->pending_session_created['state'], $this->pending_session_created['content_id'], $this->pending_session_created['content_type'] );

		$this->pending_storage_commit = null;
		$this->pending_session_created = null;
		$this->window_created_candidate = false;
	}

	/**
	 * Emit prepared cookie chunks.
	 *
	 * @param array $commit Prepared cookie commit.
	 * @return void
	 */
	protected function emit_cookie_storage( $commit ) {
		$cookies_data = $commit['cookies'];

		foreach ( $cookies_data['name'] as $key => $cookie_chunk_name ) {
			$this->set_storage_cookie( $cookie_chunk_name, $cookies_data['value'][$key], $cookies_data['expiry'][$key] );
		}

		$this->clear_stale_cookie_chunks( $commit['cookie_name'], count( $cookies_data['name'] ) );
	}

	/**
	 * A logical Visit can start only from empty or expired valid normalized
	 * session storage. Legacy per-content state cannot prove a global start.
	 *
	 * @param mixed $storage_state Normalized state.
	 * @return bool
	 */
	private function storage_state_creates_window( $storage_state ) {
		if ( ! $this->use_session_storage_payload_writes() || ! $this->is_normalized_storage_state( $storage_state ) || empty( $storage_state['is_valid'] ) )
			return false;

		if ( empty( Post_Views_Counter()->options['general']['time_between_counts']['number'] ) )
			return false;

		return $storage_state['format'] === 'empty' || ( $storage_state['format'] === 'session' && ! empty( $storage_state['is_expired'] ) );
	}

	/**
	 * Attach the browser's explicit storage capability result to normalized
	 * request state. Older clients omit the flag and retain legacy behavior.
	 *
	 * @param array $storage_state Normalized request storage.
	 * @param mixed $capable Client capability value.
	 * @return array
	 */
	private function mark_storage_capability( $storage_state, $capable ) {
		$storage_state = is_array( $storage_state ) ? $storage_state : [];

		if ( isset( $storage_state['_pvc_storage_capable'] ) && $storage_state['_pvc_storage_capable'] === false )
			return $storage_state;

		if ( $capable !== null )
			$storage_state['_pvc_storage_capable'] = is_bool( $capable ) ? $capable : ! in_array( strtolower( (string) $capable ), [ '0', 'false', 'no' ], true );

		return $storage_state;
	}

	/**
	 * Prepare the deferred session-created hook for the triggering tuple.
	 *
	 * @param array  $payload Public session payload.
	 * @param int    $content_id Content ID.
	 * @param string $content_type Content type.
	 * @return void
	 */
	private function prepare_session_created_hook( $payload, $content_id, $content_type ) {
		if ( ! $this->window_created_candidate )
			return;

		$state = $this->normalize_storage_state( $payload, $content_type, 'auto' );

		if ( ! $this->is_normalized_storage_state( $state ) || empty( $state['is_valid'] ) || $state['format'] !== 'session' )
			return;

		$this->pending_session_created = [
			'state' => $state,
			'content_id' => (int) $content_id,
			'content_type' => $content_type
		];
	}

	/**
	 * Record one countable event for a post.
	 *
	 * Terminology used across the counting pipeline:
	 * - View: an eligible content impression, written to the `count` column.
	 * - Visit: the first eligible content item that starts a fixed Count
	 *   Interval, written to the `visits` column. At most one Visit exists per
	 *   interval, so ancillary tuples in the same request receive Views only.
	 * - Legacy hook terminology predates the Visit metric and uses "visit" to
	 *   mean a countable View event. `count_visit()`, `pvc_count_visit`,
	 *   `pvc_before_count_visit` and `pvc_after_count_visit` all keep that older
	 *   meaning and are unchanged public contracts.
	 *
	 * @param int $post_id Post ID.
	 * @param int $visit_increment Visit delta for this event; 0 for Views-only.
	 *
	 * @return int|null Post ID on success, null when the write did not succeed.
	 */
	private function count_visit( $post_id, $visit_increment = 0 ) {
		// increment amount
		$view_increment = (int) apply_filters( 'pvc_views_increment_amount', 1, $post_id, 'post' );

		if ( $view_increment < 1 )
			$view_increment = 1;

		// get day, week, month and year
		$date = explode( '-', date( 'W-d-m-Y-o', current_time( 'timestamp', Post_Views_Counter()->options['general']['count_time'] === 'gmt' ) ) );

		// prepare count data
		$count_data = [
			'content_id'	=> $post_id,
			'content_type'	=> 'post',
			'increment'		=> $view_increment,
			'visit_increment' => (int) $visit_increment,
			'period_buckets' => [
				0 => $date[3] . $date[2] . $date[1], // day like 20140324
				1 => $date[4] . $date[0],			 // week like 201439
				2 => $date[3] . $date[2],			 // month like 201405
				3 => $date[3],						 // year like 2014
				4 => 'total'						 // total views
			]
		];

		// Backward compatibility only. Replacements registered on the released
		// `pvc_count_visit_multi` filter read their period buckets from the
		// `visits` key, so it must keep carrying the buckets. It is NOT the new
		// Visit metric; that value travels in `visit_increment`. New consumers
		// should read `period_buckets`.
		$count_data['visits'] = $count_data['period_buckets'];

		// attempt to count the visit and check for success
		if ( call_user_func( apply_filters( 'pvc_count_visit_multi', [ $this, 'count_visit_multi' ] ), $count_data ) ) {
			do_action( 'pvc_after_count_visit', $post_id, 'post' );

			return $post_id;
		}

		// return null on failure to indicate the count did not succeed
		return null;
	}

	/**
	 * Public writer for one complete set of period rows.
	 *
	 * Accepts the released payload: `content_id`, `content_type`, `increment`
	 * (the View delta), `visit_increment` (the Visit delta), `period_buckets`,
	 * and the legacy `visits` alias that still carries period buckets. This must
	 * fail closed when it is called independently or through a
	 * `pvc_count_visit_multi` replacement, so it re-derives Visit availability
	 * rather than trusting the caller.
	 *
	 * @param array $data Count payload.
	 *
	 * @return bool
	 */
	public function count_visit_multi( $data ) {
		// no count data?
		if ( empty( $data ) )
			return false;

		$period_buckets = isset( $data['period_buckets'] ) && is_array( $data['period_buckets'] ) ? $data['period_buckets'] : ( isset( $data['visits'] ) && is_array( $data['visits'] ) ? $data['visits'] : [] );

		if ( empty( $period_buckets ) )
			return false;

		$visits_delta = isset( $data['visit_increment'] ) ? max( 0, (int) $data['visit_increment'] ) : 0;
		$visits_service = Post_Views_Counter()->visits instanceof Post_Views_Counter_Visits ? Post_Views_Counter()->visits : null;
		$availability = $visits_service ? $visits_service->get_visits_availability() : [ 'writable' => false, 'write_reason' => 'writer_unsupported' ];

		if ( isset( $availability['write_reason'] ) && $availability['write_reason'] === 'reset_in_progress' )
			return false;

		$visit_aware = ! empty( $availability['writable'] );

		if ( ! $visit_aware )
			$visits_delta = 0;

		$rows = [];

		foreach ( $period_buckets as $type => $period ) {
			if ( (bool) apply_filters( 'pvc_skip_single_query', false, $data['content_id'], $type, $period, $data['increment'], 'post', $visits_delta ) )
				continue;

			$rows[] = [
				'id' => (int) $data['content_id'],
				'type' => (int) $type,
				'period' => (string) $period,
				'count' => (int) $data['increment'],
				'visits' => $visits_delta
			];
		}

		return empty( $rows ) || $this->write_period_rows( $rows, $visit_aware, true );
	}

	/**
	 * Declare the complete Core visit-aware writer capability.
	 *
	 * @return bool
	 */
	public function supports_visit_writes() {
		return true;
	}

	/**
	 * Atomically add one complete set of period rows using prepared values.
	 * A views-only fallback deliberately omits the visits column so a pending
	 * or failed migration cannot interrupt existing view counting.
	 *
	 * The physical shape of the shared table is re-verified here rather than
	 * trusted from the caller: `visit_aware` is downgraded when the Visits
	 * service is absent or its content-column probe is inconclusive, and the
	 * The extended `content` column is written only when it is known to exist.
	 *
	 * @param array $rows Period rows, each with id, type, period, count, visits.
	 * @param bool  $visit_aware Whether the verified writer may use visits.
	 *
	 * @return bool True on success, false when the database write failed.
	 */
	private function write_period_rows( $rows, $visit_aware, $use_write_fence = false ) {
		global $wpdb;

		if ( empty( $rows ) )
			return true;

		$has_content = false;

		if ( Post_Views_Counter()->visits instanceof Post_Views_Counter_Visits ) {
			$content_status = Post_Views_Counter()->visits->get_shared_content_column_status();

			if ( $content_status === null )
				$visit_aware = false;
			else
				$has_content = $content_status;
		} else {
			$visit_aware = false;
		}

		$write_fence = $use_write_fence && Post_Views_Counter()->visits instanceof Post_Views_Counter_Visits ? Post_Views_Counter()->visits->get_write_fence_token() : null;

		if ( $use_write_fence && $write_fence === false )
			return false;

		$columns = [ '`id`', '`type`', '`period`', '`count`' ];
		$placeholders = [ '%d', '%d', '%s', '%d' ];

		if ( $visit_aware ) {
			$columns[] = '`visits`';
			$placeholders[] = '%d';
		}

		if ( $has_content ) {
			$columns[] = '`content`';
			$placeholders[] = '%d';
		}

		$value_groups = [];
		$values = [];

		foreach ( $rows as $row ) {
			$value_groups[] = '(' . implode( ', ', $placeholders ) . ')';
			$values[] = (int) $row['id'];
			$values[] = (int) $row['type'];
			$values[] = (string) $row['period'];
			$values[] = (int) $row['count'];

			if ( $visit_aware )
				$values[] = max( 0, (int) $row['visits'] );

			if ( $has_content )
				$values[] = 0;
		}

		$target_table = '`' . $wpdb->prefix . 'post_views`';
		$updates = [ '`count` = ' . $target_table . '.`count` + VALUES(`count`)' ];

		if ( $visit_aware )
			$updates[] = '`visits` = ' . $target_table . '.`visits` + VALUES(`visits`)';

		$base_values = $values;
		$generation = is_array( $write_fence ) && isset( $write_fence['generation'] ) ? (int) $write_fence['generation'] : null;
		$attempts = $write_fence === null ? 1 : 3;
		$result = false;

		for ( $attempt = 0; $attempt < $attempts; $attempt++ ) {
			$values = $base_values;

			if ( is_array( $write_fence ) ) {
				$selects = [];

				foreach ( $rows as $row_index => $row ) {
					$aliases = $row_index === 0 ? ' AS `id`, %d AS `type`, %s AS `period`, %d AS `count`' : ', %d, %s, %d';
					$select = 'SELECT %d' . $aliases;
					$column_count = 4;

					if ( $visit_aware ) {
						$select .= $row_index === 0 ? ', %d AS `visits`' : ', %d';
						$column_count++;
					}

					if ( $has_content ) {
						$select .= $row_index === 0 ? ', %d AS `content`' : ', %d';
						$column_count++;
					}

					$selects[] = $select;
				}

				$predicate = ! empty( $write_fence['exists'] ) ? "EXISTS (SELECT 1 FROM `{$wpdb->options}` WHERE `option_name` = %s AND BINARY `option_value` = BINARY %s)" : "NOT EXISTS (SELECT 1 FROM `{$wpdb->options}` WHERE `option_name` = %s)";
				$values[] = Post_Views_Counter_Visits::OPTION_NAME;

				if ( ! empty( $write_fence['exists'] ) )
					$values[] = $write_fence['raw'];

				$sql = 'INSERT INTO ' . $target_table . ' (' . implode( ', ', $columns ) . ') SELECT * FROM (' . implode( ' UNION ALL ', $selects ) . ') AS `pvc_rows` WHERE ' . $predicate . ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
			} else
				$sql = 'INSERT INTO ' . $target_table . ' (' . implode( ', ', $columns ) . ') VALUES ' . implode( ', ', $value_groups ) . ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );

			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

			if ( $result !== 0 || ! is_array( $write_fence ) )
				break;

			$next_fence = Post_Views_Counter()->visits->get_write_fence_token( true );

			if ( $next_fence === false || (int) $next_fence['generation'] !== $generation )
				return false;

			$write_fence = $next_fence;
		}

		if ( $result === false || $result === 0 ) {
			error_log( 'Post Views Counter: shared counter write failed.' );
			return false;
		}

		if ( class_exists( 'Post_Views_Counter_Visits_Query' ) )
			Post_Views_Counter_Visits_Query::invalidate_read_cache();

		return true;
	}

	/**
	 * Remove post views from database when post is deleted.
	 *
	 * @global object $wpdb
	 *
	 * @param int $post_id
	 *
	 * @return void
	 */
	public function delete_post_views( $post_id ) {
		global $wpdb;

		$data = [
			'where'		=> [ 'id' => $post_id ],
			'format'	=> [ '%d' ]
		];

		$data = apply_filters( 'pvc_delete_post_views_where_clause', $data, $post_id );

		$deleted = $wpdb->delete( $wpdb->prefix . 'post_views', $data['where'], $data['format'] );

		if ( $deleted > 0 && class_exists( 'Post_Views_Counter_Visits_Query' ) )
			Post_Views_Counter_Visits_Query::invalidate_read_cache();
	}

	/**
	 * Get timestamp convertion.
	 *
	 * @param string $type
	 * @param int $number
	 * @param bool $timestamp
	 *
	 * @return int
	 */
	public function get_timestamp( $type, $number, $timestamp = true ) {
		$converter = [
			'minutes'	=> MINUTE_IN_SECONDS,
			'hours'		=> HOUR_IN_SECONDS,
			'days'		=> DAY_IN_SECONDS,
			'weeks'		=> WEEK_IN_SECONDS,
			'months'	=> MONTH_IN_SECONDS,
			'years'		=> YEAR_IN_SECONDS
		];

		return (int) ( ( $timestamp ? current_time( 'timestamp', true ) : 0 ) + $number * $converter[$type] );
	}

	/**
	 * Check if object cache is in use.
	 *
	 * @param bool $only_interval
	 *
	 * @return bool
	 */
	public function using_object_cache( $only_interval = false ) {
		$using = wp_using_ext_object_cache();

		// is object cache active?
		if ( $using ) {
			// get main instance
			$pvc = Post_Views_Counter();

			// check object cache
			if ( ! $only_interval && ! $pvc->options['general']['object_cache'] )
				$using = false;

			// check interval
			if ( $pvc->options['general']['flush_interval']['number'] <= 0 )
				$using = false;
		}

		return $using;
	}

	/**
	 * Flush views data stored in the persistent object cache into
	 * our custom table and clear the object cache keys when done.
	 *
	 * @return bool
	 */
	public function flush_cache_to_db() {
		// The extended writer owns the four-part shared-row queue and its Visit
		// companion. Keep existing base update/email preflight callers compatible by delegating
		// through the active writer rather than parsing extended keys as base keys.
		if ( function_exists( 'Post_Views_Counter_Pro' ) ) {
			$pro = Post_Views_Counter_Pro();

			if ( is_object( $pro ) && isset( $pro->counter ) && is_object( $pro->counter ) && $pro->counter !== $this && method_exists( $pro->counter, 'flush_cache_to_db' ) ) {
				$result = $pro->counter->flush_cache_to_db( 'pvc' );
				$this->last_cache_flush_status = method_exists( $pro->counter, 'get_cache_flush_status' ) ? $pro->counter->get_cache_flush_status() : ( $result ? 'completed' : 'failed' );
				return $result;
			}
		}

		$lock_token = $this->acquire_core_flush_lock();

		if ( $lock_token === false ) {
			$this->last_cache_flush_status = 'busy';
			return false;
		}

		try {
			$result = $this->flush_core_cache_owner( $lock_token );
			$this->last_cache_flush_status = $result ? 'completed' : ( $this->core_flush_lock_owned( $lock_token ) ? 'failed' : 'ownership_lost' );
			return $result;
		} finally {
			$this->release_core_flush_lock( $lock_token );
		}
	}

	/**
	 * Return the outcome of this request's most recent cache flush attempt.
	 *
	 * @return string idle|busy|pending|failed|ownership_lost|completed
	 */
	public function get_cache_flush_status() {
		return $this->last_cache_flush_status;
	}

	/**
	 * Drain the legacy Core queue while the caller owns the drain lock.
	 *
	 * Database commit precedes cache acknowledgement. A crash can replay a
	 * committed snapshot, but it cannot silently discard acknowledged work.
	 *
	 * @return bool
	 */
	private function flush_core_cache_owner( $lock_token ) {
		global $wpdb;

		$key_names = wp_cache_get( 'cached_key_names', 'pvc' );
		$key_names = is_string( $key_names ) ? array_filter( explode( '|', $key_names ), 'strlen' ) : (array) $key_names;
		$key_names = array_values( array_unique( array_filter( $key_names, 'strlen' ) ) );

		if ( empty( $key_names ) ) {
			wp_cache_delete( 'last-flush', 'pvc' );
			return true;
		}

		$batch_size = max( 1, absint( apply_filters( 'pvc_core_flush_batch_rows', 100 ) ) );

		foreach ( array_chunk( $key_names, $batch_size ) as $key_batch ) {
			$lock_ttl = $this->get_core_flush_lock_ttl();

			if ( ! $this->refresh_core_flush_lock( $lock_token, $lock_ttl ) )
				return false;

			$snapshots = [];
			$this->db_insert_values = [];

			foreach ( $key_batch as $key_name ) {
				$key = $this->parse_core_queue_key( $key_name );

				if ( $key === null )
					continue;

				$count = (int) wp_cache_get( $key_name, 'pvc' );

				if ( $count <= 0 )
					continue;

				$snapshots[ $key_name ] = $count;

				// Legacy three-part Core queue entries (id.type.period) carry a
				// View delta only; they have no Visit companion and predate the
				// Visits metric entirely. A Visit cannot be reconstructed from
				// them, so passing visit_increment = 0 here and committing with
				// visit_aware = false below is intentional: it preserves the
				// queued Views without inventing Visit data.
				if ( ! $this->db_prepare_insert( $key['id'], $key['type'], $key['period'], $count, 0, 'post' ) ) {
					$this->db_insert_values = [];
					return false;
				}
			}

			// Renew immediately before acquiring the reset fence. The snapshot is
			// legacy generation 0 work, so only a generation read ordered by this
			// fence may authorize its database commit.
			if ( ! $this->refresh_core_flush_lock( $lock_token, $lock_ttl ) ) {
				$this->db_insert_values = [];
				return false;
			}

			// Build every helper-dependent part of the Views-only upsert before the
			// measurement fence. Cache backends and extension callbacks are not safe
			// while MySQL permits this connection to use only two locked tables.
			$commit_sql = $this->prepare_core_legacy_insert_sql();

			if ( $commit_sql === false ) {
				$this->db_insert_values = [];
				return false;
			}

			$fenced_renewal = $this->prepare_core_fenced_lock_renewal( $lock_token, $lock_ttl );

			if ( $fenced_renewal === false ) {
				$this->db_insert_values = [];
				return false;
			}

			$fenced_generation_read = $this->prepare_core_fenced_generation_read();

			$visits = Post_Views_Counter()->visits;

			if ( ! $visits instanceof Post_Views_Counter_Visits || ! $visits->begin_measurement_fence() ) {
				$this->db_insert_values = [];
				return false;
			}

			$generation = false;
			$committed = false;
			$fence_result = [ 'unlocked' => false, 'flushed' => false ];

			try {
				// This fenced region permits only the prebuilt options UPDATE, an
				// uncached options SELECT, and the prebuilt post_views upsert. Do not
				// call WordPress/cache helpers here: extension callbacks may query an
				// unrelated table while LOCK TABLES is active.
				if ( ! $this->renew_core_flush_lock_fenced( $fenced_renewal ) ) {
					$this->db_insert_values = [];
					return false;
				}

				$generation = $this->get_core_queue_generation_fenced( $fenced_generation_read );

				// A missing, malformed, or otherwise unusable durable state cannot
				// prove this legacy snapshot belongs before a reset. Fail closed and
				// leave the snapshot recoverable for a later retry.
				if ( $generation === false ) {
					$this->db_insert_values = [];
					return false;
				}

				if ( $generation === 0 ) {
					if ( $commit_sql !== '' ) {
						$result = $wpdb->query( $commit_sql );

						if ( $result === false || $result === 0 )
							return false;

						$this->db_insert_values = [];
						$committed = true;
					}
				} else
					// Any supported Core scalar was published before the first reset.
					// Retire it without callbacks or writes; retaining its cache entry
					// makes a later retry prove the same generation before doing anything.
					$this->db_insert_values = [];
			} finally {
				$fence_result = $visits->end_measurement_fence();
			}

			// No cache acknowledgement or derived-cache retirement is valid until
			// the restrictive SQL fence has been confirmed released and its FIFO
			// publication work has completed.
			if ( empty( $fence_result['unlocked'] ) || empty( $fence_result['flushed'] ) )
				return false;

			// The legacy commit changes the Views numerator used by the Visit-derived
			// cache, so retire that cache only after the table fence is released.
			if ( $committed && class_exists( 'Post_Views_Counter_Visits_Query' ) )
				Post_Views_Counter_Visits_Query::invalidate_read_cache();

			// The acknowledgement is outside the table fence, but must still prove
			// that this flusher owns the exact snapshot before changing cache state.
			if ( ! $this->refresh_core_flush_lock( $lock_token, $lock_ttl ) )
				return false;

			$completed = [];

			foreach ( $snapshots as $key_name => $count ) {
				$remainder = wp_cache_decr( $key_name, $count, 'pvc' );

				if ( $remainder === false )
					return false;

				if ( (int) $remainder === 0 )
					$completed[] = $key_name;
			}

			if ( ! $this->cleanup_core_queue_membership( $completed ) )
				return false;
		}

		if ( wp_cache_delete( 'last-flush', 'pvc' ) === false && wp_cache_get( 'last-flush', 'pvc' ) !== false )
			return false;

		return true;
	}

	/**
	 * Build the legacy Views-only upsert before acquiring the measurement fence.
	 *
	 * @return string|false Prepared SQL, an empty string, or false on an unsafe shape.
	 */
	private function prepare_core_legacy_insert_sql() {
		global $wpdb;

		if ( empty( $this->db_insert_values ) )
			return '';

		$has_content = false;
		$visits = Post_Views_Counter()->visits;

		if ( $visits instanceof Post_Views_Counter_Visits )
			$has_content = $visits->get_shared_content_column_status() === true;

		$columns = [ '`id`', '`type`', '`period`', '`count`' ];
		$placeholders = [ '%d', '%d', '%s', '%d' ];

		if ( $has_content ) {
			$columns[] = '`content`';
			$placeholders[] = '%d';
		}

		$value_groups = [];
		$values = [];

		foreach ( $this->db_insert_values as $row ) {
			$value_groups[] = '(' . implode( ', ', $placeholders ) . ')';
			$values[] = (int) $row['id'];
			$values[] = (int) $row['type'];
			$values[] = (string) $row['period'];
			$values[] = (int) $row['count'];

			if ( $has_content )
				$values[] = 0;
		}

		$table = '`' . $wpdb->prefix . 'post_views`';
		$sql = 'INSERT INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES ' . implode( ', ', $value_groups ) . ' ON DUPLICATE KEY UPDATE `count` = ' . $table . '.`count` + VALUES(`count`)';

		return $wpdb->prepare( $sql, $values );
	}

	/**
	 * Capture an owner-checked lease replacement before the measurement fence.
	 *
	 * @param string $token Lock token.
	 * @param int    $ttl Lease duration in seconds.
	 * @return string|false Prebuilt constrained options UPDATE or false.
	 */
	private function prepare_core_fenced_lock_renewal( $token, $ttl ) {
		global $wpdb;

		$name = 'post_views_counter_core_flush_lock';
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", $name ) );
		$current = $raw === null ? false : maybe_unserialize( $raw );

		if ( ! is_array( $current ) || empty( $current['token'] ) || ! hash_equals( (string) $current['token'], (string) $token ) )
			return false;

		$replacement = [
			'token' => $token,
			'expires_at' => max( time() + $ttl, (int) $current['expires_at'] + 1 )
		];

		return $wpdb->prepare( "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND BINARY `option_value` = BINARY %s", maybe_serialize( $replacement ), $name, $raw );
	}

	/**
	 * Renew the Core lease while the measurement fence permits only options SQL.
	 *
	 * @param string $sql Prebuilt constrained UPDATE statement.
	 * @return bool
	 */
	private function renew_core_flush_lock_fenced( $sql ) {
		global $wpdb;

		return $wpdb->query( $sql ) === 1;
	}

	/**
	 * Build the durable queue-generation SELECT before the measurement fence.
	 *
	 * @return string
	 */
	private function prepare_core_fenced_generation_read() {
		global $wpdb;

		return $wpdb->prepare( "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", Post_Views_Counter_Visits::OPTION_NAME );
	}

	/**
	 * Read the durable queue generation while the measurement fence is held.
	 *
	 * Missing state is the released generation-0 format. Existing state must be
	 * the exact current durable shape required by get_queue_generation().
	 *
	 * @param string $sql Prebuilt constrained options SELECT.
	 * @return int|false
	 */
	private function get_core_queue_generation_fenced( $sql ) {
		global $wpdb;

		$wpdb->last_error = '';
		$raw = $wpdb->get_var( $sql );

		if ( $wpdb->last_error !== '' )
			return false;

		if ( $raw === null )
			return 0;

		// Never instantiate serialized objects under the table fence. A poisoned
		// __wakeup()/autoload callback could issue SQL for a table MySQL has not
		// locked, so only a non-instantiating exact array decode is permitted here.
		if ( ! is_string( $raw ) || $raw === '' || substr( $raw, 0, 2 ) !== 'a:' || substr( $raw, -1 ) !== '}' )
			return false;

		set_error_handler( [ $this, 'suppress_core_fenced_decode_diagnostic' ] );

		try {
			$state = unserialize( $raw, [ 'allowed_classes' => false ] );
		} finally {
			restore_error_handler();
		}

		$visits = Post_Views_Counter()->visits;

		if ( ! $visits instanceof Post_Views_Counter_Visits || ! $visits->is_current_normal_state_raw( $state ) || serialize( $state ) !== $raw )
			return false;

		return $state['queue_generation'];
	}

	/**
	 * Contain unserialize diagnostics within the restricted fence without calling
	 * a third-party error handler that could issue arbitrary SQL.
	 *
	 * @return bool
	 */
	private function suppress_core_fenced_decode_diagnostic() {
		return true;
	}

	/**
	 * Parse one legacy base queue key without accepting the four-part extended
	 * shape.
	 *
	 * @param mixed $key_name Cache key.
	 * @return array|null
	 */
	private function parse_core_queue_key( $key_name ) {
		$raw_key_name = (string) $key_name;
		$key_name = sanitize_text_field( $raw_key_name );

		if ( $key_name !== $raw_key_name )
			return null;

		$parts = explode( '.', $key_name );

		if ( count( $parts ) !== 3 )
			return null;

		if ( ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) )
			return null;

		$id = (int) $parts[0];
		$type = (int) $parts[1];
		$period = sanitize_key( $parts[2] );

		if ( $period !== $parts[2] || $id < 1 || $type < 0 || $type > 4 || $period === '' )
			return null;

		if ( ! $this->is_valid_core_queue_period( (int) $type, $period ) )
			return null;

		return [ 'id' => $id, 'type' => (int) $type, 'period' => $period ];
	}

	/**
	 * Validate the calendar identity encoded by a Core queue period.
	 *
	 * @param int    $type Storage type.
	 * @param string $period Storage period.
	 * @return bool
	 */
	private function is_valid_core_queue_period( $type, $period ) {
		if ( $type === 4 )
			return $period === 'total';

		if ( $type === 0 && preg_match( '/^\d{8}$/', $period ) )
			return checkdate( (int) substr( $period, 4, 2 ), (int) substr( $period, 6, 2 ), (int) substr( $period, 0, 4 ) );

		if ( $type === 1 && preg_match( '/^(\d{4})(\d{2})$/', $period, $matches ) ) {
			$year = (int) $matches[1];
			$week = (int) $matches[2];

			if ( $week < 1 || $week > 53 )
				return false;

			$date = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setISODate( $year, $week, 1 );
			return $date->format( 'oW' ) === $period;
		}

		if ( $type === 2 && preg_match( '/^(\d{4})(\d{2})$/', $period, $matches ) )
			return (int) $matches[1] > 0 && (int) $matches[2] >= 1 && (int) $matches[2] <= 12;

		return $type === 3 && preg_match( '/^\d{4}$/', $period ) && (int) $period > 0;
	}

	/**
	 * Remove completed Core scalars and only their membership entries.
	 *
	 * @param array $completed Completed keys.
	 * @return bool
	 */
	private function cleanup_core_queue_membership( $completed ) {
		if ( empty( $completed ) )
			return true;

		$live = wp_cache_get( 'cached_key_names', 'pvc' );
		$live = is_string( $live ) ? array_filter( explode( '|', $live ), 'strlen' ) : (array) $live;
		$remaining = [];

		foreach ( $live as $key_name ) {
			if ( in_array( $key_name, $completed, true ) && (int) wp_cache_get( $key_name, 'pvc' ) === 0 ) {
				if ( ! wp_cache_delete( $key_name, 'pvc' ) && wp_cache_get( $key_name, 'pvc' ) !== false )
					return false;

				continue;
			}

			$remaining[] = $key_name;
		}

		$remaining = array_values( array_unique( array_filter( $remaining, 'strlen' ) ) );

		if ( empty( $remaining ) )
			return wp_cache_delete( 'cached_key_names', 'pvc' ) || wp_cache_get( 'cached_key_names', 'pvc' ) === false;

		return (bool) wp_cache_set( 'cached_key_names', implode( '|', $remaining ), 'pvc' );
	}

	/**
	 * Acquire the Core drain lock with token-conditioned stale takeover.
	 *
	 * @return string|false
	 */
	private function acquire_core_flush_lock() {
		$name = 'post_views_counter_core_flush_lock';
		$token = wp_generate_uuid4();
		$lock = [ 'token' => $token, 'expires_at' => time() + max( 30, absint( apply_filters( 'pvc_core_flush_lock_ttl', 300 ) ) ) ];

		if ( add_option( $name, $lock, '', false ) )
			return $token;

		$current = get_option( $name, [] );

		if ( is_array( $current ) && ! empty( $current['expires_at'] ) && (int) $current['expires_at'] > time() )
			return false;

		return $this->replace_core_lock_if_unchanged( $name, $current, $lock ) ? $token : false;
	}

	/**
	 * Refresh the Core drain lease only while the token still owns it.
	 *
	 * @param string   $token Lock token.
	 * @param int|null $ttl Optional pre-evaluated lease duration.
	 * @return bool
	 */
	private function refresh_core_flush_lock( $token, $ttl = null ) {
		$name = 'post_views_counter_core_flush_lock';
		$current = get_option( $name, [] );

		if ( ! is_array( $current ) || empty( $current['token'] ) || ! hash_equals( (string) $current['token'], (string) $token ) )
			return false;

		$replacement = [
			'token' => $token,
			'expires_at' => max(
				time() + ( $ttl === null ? $this->get_core_flush_lock_ttl() : $ttl ),
				(int) $current['expires_at'] + 1
			)
		];

		return $this->replace_core_lock_if_unchanged( $name, $current, $replacement );
	}

	/**
	 * Resolve the Core drain lease duration before entering a table fence.
	 *
	 * @return int
	 */
	private function get_core_flush_lock_ttl() {
		return max( 30, absint( apply_filters( 'pvc_core_flush_lock_ttl', 300 ) ) );
	}

	/**
	 * Check current Core drain ownership.
	 *
	 * @param string $token Lock token.
	 * @return bool
	 */
	private function core_flush_lock_owned( $token ) {
		$current = get_option( 'post_views_counter_core_flush_lock', [] );

		return is_array( $current ) && ! empty( $current['token'] ) && hash_equals( (string) $current['token'], (string) $token );
	}

	/**
	 * Release the Core drain lock only while still its owner.
	 *
	 * @param string $token Owner token.
	 * @return void
	 */
	private function release_core_flush_lock( $token ) {
		global $wpdb;

		$name = 'post_views_counter_core_flush_lock';
		$current = get_option( $name, [] );

		if ( ! is_array( $current ) || empty( $current['token'] ) || ! hash_equals( (string) $current['token'], (string) $token ) )
			return;

		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s", $name, maybe_serialize( $current ) ) );

		if ( $result === 1 )
			wp_cache_delete( $name, 'options' );
	}

	/**
	 * Replace an expired Core drain lock without delete-then-add takeover.
	 *
	 * @param string $name Option name.
	 * @param mixed  $expected Expected lock.
	 * @param array  $replacement Replacement lock.
	 * @return bool
	 */
	private function replace_core_lock_if_unchanged( $name, $expected, $replacement ) {
		global $wpdb;

		$result = $wpdb->query( $wpdb->prepare( "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s", maybe_serialize( $replacement ), $name, maybe_serialize( $expected ) ) );

		if ( $result === 1 )
			wp_cache_delete( $name, 'options' );

		return $result === 1;
	}

	/**
	 * Buffer one period row for the next multi-row commit.
	 *
	 * Rows are accumulated in `$db_insert_values` and written by
	 * `db_commit_insert()`. A skipped row is reported as success because the
	 * caller asked for it to be omitted, not because a write failed.
	 *
	 * @param int    $id Content ID.
	 * @param int    $type Period type (0-4).
	 * @param string $period Period bucket value.
	 * @param int    $count View increment for this row.
	 * @param int    $visit_increment Visit increment for this row; 0 for Views-only.
	 * @param string $content_type Content type passed to `pvc_skip_single_query`.
	 *
	 * @return bool True when the row was buffered or deliberately skipped.
	 */
	private function db_prepare_insert( $id, $type, $period, $count = 1, $visit_increment = 0, $content_type = 'post' ) {
		$count = (int) $count;
		$visit_increment = max( 0, (int) $visit_increment );

		if ( (bool) apply_filters( 'pvc_skip_single_query', false, $id, $type, $period, $count, $content_type, $visit_increment ) )
			return true;

		$this->db_insert_values[] = [
			'id' => (int) $id,
			'type' => (int) $type,
			'period' => (string) $period,
			'count' => $count,
			'visits' => $visit_increment
		];

		return true;
	}

	/**
	 * Write every buffered period row in one prepared multi-row upsert.
	 *
	 * @param bool $visit_aware Whether the verified writer may use the `visits`
	 *                          column. Callers that cannot reconstruct Visits
	 *                          must pass false so the column is left untouched.
	 *
	 * @return bool True on success, false when there was nothing buffered or the
	 *              write failed. The buffer is only cleared on success.
	 */
	private function db_commit_insert( $visit_aware = false ) {
		if ( empty( $this->db_insert_values ) )
			return false;

		$result = $this->write_period_rows( $this->db_insert_values, $visit_aware );

		if ( $result !== false )
			$this->db_insert_values = [];

		return $result;
	}

	/**
	 * Check whether user has excluded roles.
	 *
	 * @param int $user_id
	 * @param array $option
	 *
	 * @return bool
	 */
	public function is_user_role_excluded( $user_id, $option = [] ) {
		$option = is_array( $option ) ? $option : [];

		// get user by ID
		$user = get_user_by( 'id', $user_id );

		// no user?
		if ( empty( $user ) )
			return false;

		// get user roles
		$roles = (array) $user->roles;

		// any roles?
		if ( ! empty( $roles ) ) {
			foreach ( $roles as $role ) {
				if ( in_array( $role, $option, true ) )
					return true;
			}
		}

		return false;
	}

	/**
	 * Check if IPv4 is in range.
	 *
	 * @param string $ip
	 * @param string $range
	 *
	 * @return bool
	 */
	public function ipv4_in_range( $ip, $range ) {
		$start = str_replace( '*', '0', $range );
		$end = str_replace( '*', '255', $range );
		$ip = (float) sprintf( "%u", ip2long( $ip ) );

		return ( $ip >= (float) sprintf( "%u", ip2long( $start ) ) && $ip <= (float) sprintf( "%u", ip2long( $end ) ) );
	}

	/**
	 * Normalize an IP address for consistent comparisons.
	 *
	 * @param string $ip
	 *
	 * @return string
	 */
	public function normalize_ip( $ip ) {
		$ip = $this->sanitize_ip( trim( $ip ) );

		if ( $ip === '' || filter_var( $ip, FILTER_VALIDATE_IP ) === false )
			return '';

		if ( function_exists( 'inet_pton' ) && function_exists( 'inet_ntop' ) ) {
			$packed_ip = inet_pton( $ip );

			if ( $packed_ip !== false ) {
				$normalized_ip = inet_ntop( $packed_ip );

				if ( is_string( $normalized_ip ) )
					$ip = $normalized_ip;
			}
		}

		return strtolower( $ip );
	}

	/**
	 * Validate and normalize an IP exclusion rule.
	 *
	 * Exact IPv4 and IPv6 addresses are supported. Wildcards remain IPv4-only.
	 *
	 * @param string $ip
	 *
	 * @return string
	 */
	public function validate_excluded_ip( $ip ) {
		$ip = $this->sanitize_ip( trim( $ip ) );

		if ( $ip === '' )
			return '';

		if ( strpos( $ip, '*' ) !== false ) {
			$wildcard_ip = str_replace( '*', '0', $ip );

			if ( filter_var( $wildcard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) !== false )
				return $ip;

			return '';
		}

		return $this->normalize_ip( $ip );
	}

	/**
	 * Check whether a visitor IP matches an exclusion rule.
	 *
	 * @param string $user_ip
	 * @param string $excluded_ip
	 *
	 * @return bool
	 */
	public function is_excluded_ip( $user_ip, $excluded_ip ) {
		$user_ip = $this->normalize_ip( $user_ip );
		$excluded_ip = $this->validate_excluded_ip( $excluded_ip );

		if ( $user_ip === '' || $excluded_ip === '' )
			return false;

		if ( strpos( $excluded_ip, '*' ) !== false ) {
			if ( filter_var( $user_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) === false )
				return false;

			return $this->ipv4_in_range( $user_ip, $excluded_ip );
		}

		if ( function_exists( 'inet_pton' ) ) {
			$user_ip_binary = inet_pton( $user_ip );
			$excluded_ip_binary = inet_pton( $excluded_ip );

			if ( $user_ip_binary !== false && $excluded_ip_binary !== false )
				return hash_equals( $excluded_ip_binary, $user_ip_binary );
		}

		return ( $user_ip === strtolower( $excluded_ip ) );
	}

	/**
	 * Get user real IP address.
	 *
	 * @return string
	 */
	public function get_user_ip() {
		// Default strategy: respect only REMOTE_ADDR (most secure, backward compatible)
		$strategy = apply_filters( 'pvc_ip_resolution_strategy', 'remote_addr' );

		// Validate strategy - only allow known values to prevent silent weakening
		$valid_strategies = [ 'remote_addr', 'trusted_proxy_only', 'auto' ];
		if ( ! in_array( $strategy, $valid_strategies, true ) )
			$strategy = 'remote_addr';

		// Always get REMOTE_ADDR first (most reliable)
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
		$remote_addr = $this->sanitize_ip( $remote_addr );

		// If strategy is remote_addr only, return REMOTE_ADDR if valid
		if ( $strategy === 'remote_addr' ) {
			if ( $this->validate_user_ip( $remote_addr ) )
				return $this->normalize_ip( $remote_addr );

			return '';
		}

		// For other strategies, check if REMOTE_ADDR is a trusted proxy
		$trusted_proxies = apply_filters( 'pvc_trusted_proxy_cidrs', [] );
		$is_proxy_request = ! empty( $trusted_proxies ) && $this->is_ip_in_cidrs( $remote_addr, $trusted_proxies );

		// If strategy is trusted_proxy_only, require REMOTE_ADDR to be trusted proxy
		if ( $strategy === 'trusted_proxy_only' && ! $is_proxy_request )
			return '';

		// If strategy is 'auto' or unknown (shouldn't happen after validation), use forwarded headers if available
		// Priority: check forwarded headers only if we have a valid base IP
		$ip_headers = [ 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED' ];

		foreach ( $ip_headers as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				$ips = explode( ',', $_SERVER[$key] );

				foreach ( $ips as $header_ip ) {
					$header_ip = $this->sanitize_ip( trim( $header_ip ) );

					// Skip if same as remote addr (prevent loops)
					if ( $header_ip === $remote_addr )
						continue;

					// Validate the IP
					if ( $this->validate_user_ip( $header_ip ) )
						return $this->normalize_ip( $header_ip );
				}
			}
		}

		// Fallback to REMOTE_ADDR if valid
		if ( $this->validate_user_ip( $remote_addr ) )
			return $this->normalize_ip( $remote_addr );

		return '';
	}

	/**
	 * Sanitize an IP address.
	 *
	 * @param string $ip
	 *
	 * @return string
	 */
	private function sanitize_ip( $ip ) {
		return sanitize_text_field( wp_unslash( $ip ) );
	}

	/**
	 * Check if IP matches any CIDR range.
	 *
	 * @param string $ip
	 * @param array  $cidrs
	 *
	 * @return bool
	 */
	private function is_ip_in_cidrs( $ip, $cidrs ) {
		if ( empty( $cidrs ) || ! is_array( $cidrs ) )
			return false;

		$ip_long = ip2long( $ip );
		if ( $ip_long === false )
			return false;

		foreach ( $cidrs as $cidr ) {
			$cidr = trim( $cidr );

			if ( strpos( $cidr, '/' ) === false )
				$cidr .= '/32';

			list( $subnet, $mask ) = explode( '/', $cidr );

			$subnet_long = ip2long( $subnet );
			if ( $subnet_long === false )
				continue;

			$mask = (int) $mask;

			// Validate mask range to prevent ArithmeticError
			if ( $mask < 0 || $mask > 32 )
				continue;

			// Apply mask
			if ( ( $ip_long & ~( ( 1 << ( 32 - $mask ) ) - 1 ) ) === ( $subnet_long & ~( ( 1 << ( 32 - $mask ) ) - 1 ) ) )
				return true;
		}

		return false;
	}

	/**
	 * Ensure an IP address is public and routable.
	 *
	 * @param string $ip
	 *
	 * @return bool
	 */
	public function validate_user_ip( $ip ) {
		$ip = $this->normalize_ip( $ip );

		if ( $ip === '' )
			return false;

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false )
			return false;

		return true;
	}

	/**
	 * Register REST API endpoints.
	 *
	 * @return void
	 */
	public function rest_api_init() {
		// view post route
		register_rest_route(
			'post-views-counter',
			'/view-post/(?P<id>\d+)|/view-post/',
			[
				'methods'				 => [ 'POST' ],
				'callback'				 => [ $this, 'check_post_rest_api' ],
				'permission_callback'	 => [ $this, 'view_post_permissions_check' ],
				'args'					 => apply_filters( 'pvc_rest_api_view_post_args', [
					'id'			=> [
						'default'			 => 0,
						'sanitize_callback'	 => 'absint'
					],
					'storage_type'	=> [
						'default'			 => 'cookies'
					],
					'storage_data'	=> [
						'default'			 => ''
					],
					'storage_data_all' => [
						'default'			 => ''
					],
					'storage_capable' => [
						'sanitize_callback' => 'rest_sanitize_boolean'
					]
				] )
			]
		);

		// get views route
		register_rest_route(
			'post-views-counter',
			'/get-post-views/(?P<id>(\d+,?)+)',
			[
				'methods'				 => [ 'GET', 'POST' ],
				'callback'				 => [ $this, 'get_post_views_rest_api' ],
				'permission_callback'	 => [ $this, 'get_post_views_permissions_check' ],
				'args'					 => apply_filters( 'pvc_rest_api_get_post_views_args', [
					'id' => [
						'default'			=> 0
					],
					'metric' => [],
					'period' => [ 'default' => 'total' ],
					'type' => [ 'default' => 'post' ]
				] )
			]
		);
	}

	/**
	 * Get post views via REST API request.
	 *
	 * @param object $request
	 *
	 * @return int
	 */
	public function get_post_views_rest_api( $request ) {
		$raw_metric = $request->get_param( 'metric' );
		$raw_id = $request->get_param( 'id' );

		if ( ! is_scalar( $raw_id ) || ! preg_match( '/^[1-9]\d*(,[1-9]\d*)*$/', (string) $raw_id ) )
			return new WP_Error( 'pvc_invalid_metric', __( 'The requested PVC metric is not supported.', 'post-views-counter' ), [ 'status' => 400 ] );

		$ids = array_values( array_filter( array_unique( array_map( 'absint', explode( ',', (string) $raw_id ) ) ) ) );

		// Preserve the exact legacy scalar response when named metric mode is absent.
		if ( $raw_metric === null )
			return pvc_get_post_views( $ids );

		if ( ! is_scalar( $raw_metric ) )
			return new WP_Error( 'pvc_invalid_metric', __( 'The requested PVC metric is not supported.', 'post-views-counter' ), [ 'status' => 400 ] );

		$metric = (string) $raw_metric;

		if ( count( $ids ) > self::REST_MAX_TARGETS )
			return new WP_Error( 'pvc_too_many_targets', __( 'Too many PVC metric targets were requested.', 'post-views-counter' ), [ 'status' => 400 ] );
		$raw_period = $request->get_param( 'period' );
		$period = is_scalar( $raw_period ) ? (string) $raw_period : '';
		$raw_type = $request->get_param( 'type' );
		$type = is_scalar( $raw_type ) ? (string) $raw_type : '';

		// Visits Cleanup Addendum -- 2026-09-26 (O2): the named read is Views-only
		if ( $metric !== 'views' || $type !== 'post' || ! preg_match( '/^[a-z0-9_-]+$/', $period ) )
			return new WP_Error( 'pvc_invalid_metric', __( 'The requested PVC metric is not supported.', 'post-views-counter' ), [ 'status' => 400 ] );

		$normalized = function_exists( 'pvc_normalize_views_period' ) ? pvc_normalize_views_period( $period ) : null;

		if ( $normalized === null )
			$availability = [ 'readable' => false, 'read_reason' => 'invalid_period' ];
		else {
			$content_status = isset( Post_Views_Counter()->visits ) ? Post_Views_Counter()->visits->get_shared_content_column_status() : false;
			$availability = $content_status === null ? [ 'readable' => false, 'read_reason' => 'schema_probe_failed' ] : [ 'readable' => true, 'read_reason' => 'ready' ];
		}

		$raw_values = [];

		if ( ! empty( $availability['readable'] ) )
			$raw_values = $this->get_named_post_view_values( $ids, $period, $normalized );

		$values = [];
		$formatted = [];
		$display_options = Post_Views_Counter()->options['display'];

		foreach ( $ids as $id ) {
			$key = 'post:' . $id . ':' . $period;
			$value = empty( $availability['readable'] ) ? null : ( isset( $raw_values[$id] ) ? $raw_values[$id] : 0 );
			$values[$key] = $value;

			// An unreadable View is not a stored zero, so it never reaches numeric
			// formatting; it renders the unavailable dash instead.
			$display_value = $value === null ? '&mdash;' : ( ! empty( $display_options['use_format'] ) ? number_format_i18n( $value ) : (string) $value );
			$filtered_value = apply_filters( 'pvc_post_views_number_format', $display_value, $id );

			$filtered_value = is_scalar( $filtered_value ) ? (string) $filtered_value : $display_value;
			$formatted[$key] = html_entity_decode( wp_strip_all_tags( $filtered_value ), ENT_QUOTES, 'UTF-8' );
		}

		// Anonymous dynamic payloads expose only the stable raw-read result. They
		// never expose schema internals or other internal state metadata.
		$public_availability = [
			'readable' => ! empty( $availability['readable'] ),
			'reason' => isset( $availability['read_reason'] ) ? sanitize_key( $availability['read_reason'] ) : 'unavailable'
		];

		return [
			'metric' => $metric,
			'type' => $type,
			'period' => $period,
			'values' => $values,
			'formatted' => $formatted,
			'availability' => $public_availability
		];
	}

	/**
	 * Read named post Views in one grouped query while preserving value filters.
	 *
	 * @param int[]  $ids Post IDs.
	 * @param string $period Requested period.
	 * @param array  $normalized Canonical period.
	 * @return int[] Values keyed by post ID.
	 */
	private function get_named_post_view_values( $ids, $period, $normalized ) {
		if ( $this->has_custom_post_views_period_filter() ) {
			$values = [];

			foreach ( $ids as $id )
				$values[$id] = pvc_get_post_views( $id, $period );

			return $values;
		}

		global $wpdb;
		$params = $ids;
		$where = [ 'id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', 'type = %d' ];
		$params[] = (int) $normalized['type'];

		if ( (int) $normalized['type'] === 4 ) {
			$where[] = 'period = %s';
			$params[] = 'total';
		} elseif ( $normalized['period_from'] === $normalized['period_to'] ) {
			$where[] = 'CAST(period AS SIGNED) = %d';
			$params[] = (int) $normalized['period_from'];
		} else {
			$where[] = 'CAST(period AS SIGNED) BETWEEN %d AND %d';
			$params[] = (int) $normalized['period_from'];
			$params[] = (int) $normalized['period_to'];
		}

		$content_status = isset( Post_Views_Counter()->visits ) ? Post_Views_Counter()->visits->get_shared_content_column_status() : false;

		if ( $content_status === null )
			return [];

		if ( $content_status )
			$where[] = 'content = 0';

		$sql = $wpdb->prepare( 'SELECT id, SUM(count) AS views FROM `' . $wpdb->prefix . 'post_views` WHERE ' . implode( ' AND ', $where ) . ' GROUP BY id', $params );
		$cache_key = md5( $sql );
		$rows = wp_cache_get( $cache_key, 'pvc-get_post_views' );

		if ( $rows === false ) {
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			$rows = is_array( $rows ) ? $rows : [];
			wp_cache_add( $cache_key, $rows, 'pvc-get_post_views', absint( apply_filters( 'pvc_object_cache_expire', 300 ) ) );
		}

		$values = array_fill_keys( $ids, 0 );

		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;

			if ( array_key_exists( $id, $values ) )
				$values[$id] = isset( $row['views'] ) ? (int) $row['views'] : 0;
		}

		foreach ( $values as $id => $value )
			$values[$id] = (int) apply_filters( 'pvc_get_post_views', $value, $id, $period, [] );

		return $values;
	}

	/**
	 * Check for period filters beyond the canonical post discriminator.
	 *
	 * @return bool
	 */
	private function has_custom_post_views_period_filter() {
		global $wp_filter;

		if ( empty( $wp_filter['pvc_get_post_views_period_where'] ) || ! isset( $wp_filter['pvc_get_post_views_period_where']->callbacks ) )
			return false;

		foreach ( $wp_filter['pvc_get_post_views_period_where']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! isset( $callback['function'] ) || $callback['function'] !== 'pvc_get_post_views_period_where' )
					return true;
			}
		}

		return false;
	}

	/**
	 * Check if a given request has access to get views.
	 *
	 * @param object $request
	 *
	 * @return bool|\WP_Error
	 */
	public function get_post_views_permissions_check( $request ) {
		// GET views is always public by default (read-only operation)
		$default = true;

		return (bool) apply_filters( 'pvc_rest_api_get_post_views_check', $default, $request );
	}

	/**
	 * Check if a given request has access to view post.
	 *
	 * @param object $request
	 *
	 * @return bool|\WP_Error
	 */
	public function view_post_permissions_check( $request ) {
		// Default: allow if REST API mode is enabled
		$pvc = post_views_counter();
		$default = isset( $pvc->options['general']['counter_mode'] ) && $pvc->options['general']['counter_mode'] === 'rest_api';

		$result = (bool) apply_filters( 'pvc_rest_api_view_post_check', $default, $request );

		// If filter denied access, return WP_Error for clearer feedback
		if ( ! $result && $default ) {
			return new \WP_Error(
				'rest_not_allowed',
				__( 'You do not have permission to count post views via REST API.', 'post-views-counter' ),
				[ 'status' => 403 ]
			);
		}

		return $result;
	}

	/**
	 * Validate REST API incoming data.
	 *
	 * @param int|array|string $data
	 *
	 * @return int|array
	 */
	public function validate_rest_api_data( $data ) {
		// POST array?
		if ( is_array( $data ) )
			$data = array_unique( array_filter( array_map( 'absint', $data ) ), SORT_NUMERIC );
		// multiple comma-separated values?
		elseif ( strpos( $data, ',' ) !== false ) {
			$data = explode( ',', $data );

			if ( is_array( $data ) && ! empty( $data ) )
				$data = array_unique( array_filter( array_map( 'absint', $data ) ), SORT_NUMERIC );
			else
				$data = [];
		// single value?
		} else
			$data = absint( $data );

		return $data;
	}
}
