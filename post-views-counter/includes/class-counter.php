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

	private $storage = [];
	private $storage_type = 'cookies';
	private $queue = [];
	private $queue_mode = false;
	private $db_insert_values = '';
	private $cookie = [];

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
	 * Run manual pvc_view_post queue.
	 *
	 * @return void
	 */
	public function queue_count() {
		// check conditions
		if ( ! isset( $_POST['action'], $_POST['ids'], $_POST['pvc_nonce'] ) || ! wp_verify_nonce( $_POST['pvc_nonce'], 'pvc-view-posts' ) || $_POST['ids'] === '' || ! is_string( $_POST['ids'] ) )
			exit;

		// get post ids
		$ids = explode( ',', $_POST['ids'] );

		$counted = [];

		if ( ! empty( $ids ) ) {
			$ids = array_filter( array_map( 'intval', $ids ) );

			if ( ! empty( $ids ) ) {
				// turn on queue mode
				$this->queue_mode = true;

				foreach ( $ids as $id ) {
					$counted[$id] = ! ( $this->check_post( $id ) === null );
				}

				// turn off queue mode
				$this->queue_mode = false;
			}
		}

		echo wp_json_encode(
			[
				'post_ids'	=> $ids,
				'counted'	=> $counted
			]
		);

		exit;
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
					document.addEventListener( 'DOMContentLoaded', function() {
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
								nonce: '" . wp_create_nonce( 'pvc-view-posts' ) . "',
								ids: '" . implode( ',', $this->queue ) . "'
							};

							// main javascript file was loaded?
							if ( typeof PostViewsCounter !== 'undefined' && PostViewsCounter.promise !== null ) {
								PostViewsCounter.promise.then( function() {
									PostViewsCounterManual.init( pvcManualCounterArgs );
								} );
							// PostViewsCounter is undefined or promise is null
							} else {
								PostViewsCounterManual.init( pvcManualCounterArgs );
							}
						}

						pvcLoadManualCounter( '" . POST_VIEWS_COUNTER_URL . "/js/counter.js', pvcExecuteManualCounter );
					}, false );
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
	 *
	 * @return null|int
	 */
	public function check_post( $post_id = 0, $content_data = [] ) {
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

		// filter visit counting
		$count_visit = (bool) apply_filters( 'pvc_count_visit', $count_visit, $post_id, $user_id, $user_ip, 'post', $hook_content_data );

		// count visit
		if ( $count_visit ) {
			// before count visit action
			do_action( 'pvc_before_count_visit', $post_id, $user_id, $user_ip, 'post', $hook_content_data );

			return $this->count_visit( $post_id );
		}
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
	public function build_session_storage_payload( $storage_state, $content_id = 0, $content_type = 'post', $default_expiration = 0, $current_time = 0 ) {
		$session_state = $this->create_session_storage_state( $storage_state, $content_id, $content_type, $default_expiration, $current_time );

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

		if ( $active_session !== null ) {
			$merged_state['format'] = 'session';
			$merged_state['version'] = 1;
			$merged_state['session_id'] = $active_session['session_id'];
			$merged_state['started_at'] = (int) $active_session['started_at'];
			$merged_state['expires_at'] = (int) $active_session['expires_at'];
			$merged_state['is_valid'] = true;
			$merged_state['is_expired'] = false;
			$merged_state['needs_writeback'] = false;

			return $merged_state;
		}

		if ( $has_legacy_entries ) {
			$merged_state['format'] = 'legacy_map';
			$merged_state['is_valid'] = true;
		}

		return $merged_state;
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
	private function create_session_storage_state( $storage_state, $content_id = 0, $content_type = 'post', $default_expiration = 0, $current_time = 0 ) {
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
			if ( $content_id > 0 ) {
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

		if ( $content_id > 0 )
			$session_state['visited'][$content_type][$content_id] = true;

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
	 * Clear stale cookie chunks that are no longer used by the current payload.
	 *
	 * @param string $cookie_name
	 * @param int $valid_chunk_count
	 * @param bool $php_at_least_73
	 *
	 * @return void
	 */
	private function clear_stale_cookie_chunks( $cookie_name, $valid_chunk_count, $php_at_least_73 ) {
		if ( ! isset( $_COOKIE[$cookie_name] ) || ! is_array( $_COOKIE[$cookie_name] ) )
			return;

		foreach ( array_keys( $_COOKIE[$cookie_name] ) as $chunk_index ) {
			$chunk_index = (int) $chunk_index;

			if ( $chunk_index < $valid_chunk_count )
				continue;

			if ( $php_at_least_73 ) {
				setcookie(
					$cookie_name . '[' . $chunk_index . ']',
					'',
					[
						'expires'	=> 1,
						'path'		=> COOKIEPATH,
						'domain'	=> COOKIE_DOMAIN,
						'secure'	=> is_ssl(),
						'httponly'	=> false,
						'samesite'	=> 'LAX'
					]
				);
			} else {
				setcookie( $cookie_name . '[' . $chunk_index . ']', '', 1, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
			}
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

			$decoded_payloads = json_decode( stripslashes( $storage_data_all ), true, 8 );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded_payloads ) )
				return [];
		} elseif ( is_array( $storage_data_all ) )
			$decoded_payloads = $storage_data_all;
		else
			return [];

		$storage_payloads = [];

		foreach ( array_keys( $this->get_empty_storage_buckets() ) as $bucket ) {
			if ( isset( $decoded_payloads[$bucket] ) && ( is_scalar( $decoded_payloads[$bucket] ) || is_array( $decoded_payloads[$bucket] ) ) )
				$storage_payloads[$bucket] = $decoded_payloads[$bucket];
		}

		return $storage_payloads;
	}

	/**
	 * Check whether the active Pro plugin supports session payload writes.
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
	 * Session payload writes are the default for PVC-only installs. When Pro is active,
	 * PVC uses Pro's explicit capability declaration and allows this filter to override
	 * the computed default for controlled testing or emergency rollback.
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

		foreach ( $cookie_chunks as $chunk ) {
			if ( is_scalar( $chunk ) )
				$chunks[] = (string) $chunk;
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

		if ( is_array( $storage_data ) )
			return $this->normalize_json_storage_state( $storage_data, $content_type );

		if ( ! is_scalar( $storage_data ) ) {
			$state['format'] = 'invalid';
			$state['is_valid'] = false;

			return $state;
		}

		$storage_data = trim( (string) $storage_data );

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

		if ( $session_id === '' || $started_at <= 0 || $expires_at <= 0 || $expires_at < $started_at || ! isset( $storage_data['visited'] ) || ! is_array( $storage_data['visited'] ) ) {
			$state['format'] = 'invalid';
			$state['is_valid'] = false;

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

		if ( $this->use_session_storage_payload_writes() )
			$this->storage = $this->build_session_storage_payload( $content_data, $content, $content_type, $expiration, $current_time );
		else
			$this->storage = [ $content_type => $this->build_legacy_storage_payload( $content_data, $content, $content_type, $expiration, $current_time ) ];

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

			return $count_visit;
		}

		$session_payload = $this->build_session_storage_payload( $content_data, $content, 'post', $expiration, $current_time );
		$session_json = wp_json_encode( $session_payload );

		if ( ! is_string( $session_json ) || $session_json === '' ) {
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
		$php_at_least_73 = version_compare( phpversion(), '7.3', '>=' );

		if ( ! $this->use_session_storage_payload_writes() ) {
			$legacy_payload = $this->serialize_legacy_cookie_payload( $this->build_legacy_storage_payload( $cookie, $id, 'post', $expiration, $current_time ) );
			$cookies_data = $this->build_legacy_cookie_storage_data( $cookie, $cookie_name, $id, 'post', $expiration, $current_time );

			foreach ( $cookies_data['name'] as $key => $cookie_chunk_name ) {
				if ( $php_at_least_73 ) {
					setcookie(
						$cookie_chunk_name,
						$cookies_data['value'][$key],
						[
							'expires'	=> $cookies_data['expiry'][$key],
							'path'		=> COOKIEPATH,
							'domain'	=> COOKIE_DOMAIN,
							'secure'	=> is_ssl(),
							'httponly'	=> false,
							'samesite'	=> 'LAX'
						]
					);
				} else {
					setcookie( $cookie_chunk_name, $cookies_data['value'][$key], $cookies_data['expiry'][$key], COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
				}
			}

			$this->clear_stale_cookie_chunks( $cookie_name, count( $cookies_data['name'] ), $php_at_least_73 );

			if ( $this->queue_mode )
				$this->cookie = $this->sanitize_cookies_data( $legacy_payload, 'post' );

			return $count_visit;
		}

		$session_payload = $this->build_session_storage_payload( $cookie, $id, 'post', $expiration, $current_time );
		$session_json = wp_json_encode( $session_payload );

		if ( ! is_string( $session_json ) || $session_json === '' )
			return false;

		// check whether php version is at least 7.3
		$cookie_chunks = str_split( $session_json, 3980 );
		$cookie_expiration = (int) $session_payload['expires_at'];

		foreach ( $cookie_chunks as $key => $value ) {
			if ( $php_at_least_73 ) {
				setcookie(
					$cookie_name . '[' . $key . ']',
					$value,
					[
						'expires'	=> $cookie_expiration,
						'path'		=> COOKIEPATH,
						'domain'	=> COOKIE_DOMAIN,
						'secure'	=> is_ssl(),
						'httponly'	=> false,
						'samesite'	=> 'LAX'
					]
				);
			} else {
				setcookie( $cookie_name . '[' . $key . ']', $value, $cookie_expiration, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
			}
		}

		$this->clear_stale_cookie_chunks( $cookie_name, count( $cookie_chunks ), $php_at_least_73 );

		if ( $this->queue_mode )
			$this->cookie = $this->sanitize_cookies_data( $session_json, 'post' );

		return $count_visit;
	}

	/**
	 * Count visit.
	 *
	 * @param int $post_id
	 *
	 * @return int|null
	 */
	private function count_visit( $post_id ) {
		// increment amount
		$increment_amount = (int) apply_filters( 'pvc_views_increment_amount', 1, $post_id, 'post' );

		if ( $increment_amount < 1 )
			$increment_amount = 1;

		// get day, week, month and year
		$date = explode( '-', date( 'W-d-m-Y-o', current_time( 'timestamp', Post_Views_Counter()->options['general']['count_time'] === 'gmt' ) ) );

		// prepare count data
		$count_data = [
			'content_id'	=> $post_id,
			'content_type'	=> 'post',
			'increment'		=> $increment_amount,
			'visits'		=> [
				0 => $date[3] . $date[2] . $date[1], // day like 20140324
				1 => $date[4] . $date[0],			 // week like 201439
				2 => $date[3] . $date[2],			 // month like 201405
				3 => $date[3],						 // year like 2014
				4 => 'total'						 // total views
			]
		];

		// attempt to count the visit and check for success
		if ( call_user_func( apply_filters( 'pvc_count_visit_multi', [ $this, 'count_visit_multi' ] ), $count_data ) ) {
			do_action( 'pvc_after_count_visit', $post_id, 'post' );

			return $post_id;
		}

		// return null on failure to indicate the count did not succeed
		return null;
	}

	/**
	 * Prepare values to be inserted into database.
	 *
	 * @param array $data
	 *
	 * @return bool
	 */
	public function count_visit_multi( $data ) {
		// no count data?
		if ( empty( $data ) )
			return false;

		$success = true;

		foreach ( $data['visits'] as $type => $period ) {
			// hit the database directly and check for failure
			if ( ! $this->db_insert( $data['content_id'], $type, $period, $data['increment'] ) )
				$success = false;
		}

		return $success;
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

		$wpdb->delete( $wpdb->prefix . 'post_views', $data['where'], $data['format'] );
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
		// get keys
		$key_names = wp_cache_get( 'cached_key_names', 'pvc' );

		if ( ! $key_names )
			$key_names = [];
		else {
			// create an array out of a string that's stored in the cache
			$key_names = explode( '|', $key_names );
		}

		// any data?
		if ( ! empty( $key_names ) ) {
			foreach ( $key_names as $key_name ) {
				// get values stored within the key name itself
				list( $id, $type, $period ) = explode( '.', $key_name );

				// get the cached count value
				$count = wp_cache_get( $key_name, 'pvc' );

				// store cached value in the database
				$this->db_prepare_insert( $id, $type, $period, $count );

				// clear the cache key we just flushed
				wp_cache_delete( $key_name, 'pvc' );
			}

			// flush values to database
			$this->db_commit_insert();

			// delete the key holding the list
			wp_cache_delete( 'cached_key_names', 'pvc' );
		}

		// remove last flush
		wp_cache_delete( 'last-flush', 'pvc' );

		return true;
	}

	/**
	 * Insert or update views count.
	 *
	 * @global object $wpdb
	 *
	 * @param int $id
	 * @param int $type
	 * @param string $period
	 * @param int $count
	 *
	 * @return bool
	 */
	private function db_insert( $id, $type, $period, $count ) {
		global $wpdb;

		// skip single query?
		if ( (bool) apply_filters( 'pvc_skip_single_query', false, $id, $type, $period, $count, 'post' ) )
			return true; // consider skipped as "successful" for this context

		$result = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . $wpdb->prefix . 'post_views (`id`, `type`, `period`, `count`) VALUES (%d, %d, %s, %d) ON DUPLICATE KEY UPDATE count = count + %d', $id, $type, $period, $count, $count ) );

		// check for query failure
		if ( $result === false ) {
			// log the error for debugging
			error_log( sprintf( 'Post Views Counter: Failed to insert/update views for ID %d, type %d, period %s. MySQL error: %s', $id, $type, $period, $wpdb->last_error ) );
			return false;
		}

		return true;
	}

	/**
	 * Prepare bulk insert or update views count.
	 *
	 * @param int $id
	 * @param int $type
	 * @param string $period
	 * @param int $count
	 *
	 * @return void
	 */
	private function db_prepare_insert( $id, $type, $period, $count = 1 ) {
		// cast count
		$count = (int) $count;

		if ( ! $count )
			$count = 1;

		// any queries?
		if ( ! empty( $this->db_insert_values ) )
			$this->db_insert_values .= ', ';

		// append insert queries
		$this->db_insert_values .= sprintf( '(%d, %d, "%s", %d)', $id, $type, $period, $count );

		if ( strlen( $this->db_insert_values ) > 25000 )
			$this->db_commit_insert();
	}

	/**
	 * Insert accumulated values to database.
	 *
	 * @global object $wpdb
	 *
	 * @return int|bool
	 */
	private function db_commit_insert() {
		global $wpdb;

		if ( empty( $this->db_insert_values ) )
			return false;

		$result = $wpdb->query(
			"INSERT INTO " . $wpdb->prefix . "post_views (id, type, period, count)
			VALUES " . $this->db_insert_values . "
			ON DUPLICATE KEY UPDATE count = count + VALUES(count)"
		);

		$this->db_insert_values = '';

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
						'default'			=> 0,
						'sanitize_callback'	=> [ $this, 'validate_rest_api_data' ]
					]
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
		return pvc_get_post_views( $request->get_param( 'id' ) );
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
