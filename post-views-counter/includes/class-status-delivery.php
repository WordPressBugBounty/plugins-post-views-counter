<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Status_Delivery class.
 *
 * The Status tab's delivery checks, run only from its button: a probe of
 * the active counting mode's handler, sent anonymously from the browser and
 * repeated by the server, and a scan of one published countable post. Each
 * probe is a request the handler refuses before counting, or one to a
 * handler that never counts, so a check never counts a view; PHP mode has
 * none, since there the page request is the count. Results are returned to
 * the page and never stored.
 *
 * @class Post_Views_Counter_Status_Delivery
 */
class Post_Views_Counter_Status_Delivery {

	/** AJAX action and nonce action of the button. */
	const ACTION = 'pvc_status_delivery_checks';

	/** A post id no site reaches, for the REST API probe. */
	const MISSING_POST_ID = 9007199254740991;

	/** Seconds a probe or page fetch may take. */
	const TIMEOUT = 10;

	/** Result codes of a probe. */
	const RESULTS = [ 'reached_pvc', 'reached_wordpress', 'blocked', 'network_error', 'timed_out', 'tls_error', 'unknown' ];

	/** Result codes of the page scan. */
	const SCAN_RESULTS = [ 'script_found', 'script_off', 'config_unreadable', 'script_missing', 'no_post', 'blocked', 'network_error', 'timed_out', 'tls_error', 'unknown' ];

	/** Modes in which the browser counts, so fetching a page counts nothing: the page scan runs only there. */
	const SCAN_MODES = [ 'js', 'rest_api', 'ajax' ];

	/** Redirects the page scan follows, each to this site only. */
	const MAX_REDIRECTS = 3;

	/** Response headers whose names show a cache or CDN in front of the site. */
	const CACHE_HEADERS = [ 'age', 'cf-cache-status', 'x-cache', 'x-cache-hits', 'x-varnish', 'x-litespeed-cache', 'x-proxy-cache', 'x-fastcgi-cache', 'x-sucuri-cache', 'x-kinsta-cache' ];

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'ajax_run' ] );
	}

	/**
	 * The probe of the active counting mode's handler, or null when the mode
	 * has none (PHP mode) or its handler is not described. An extension
	 * whose handler counts in this mode describes its probe through the
	 * pvc_status_delivery_probe filter.
	 *
	 * @param string $mode Counting mode.
	 * @return array|null Probe: transport, handler, url, method, body, status, code, shape, marker.
	 */
	public function get_probe( $mode ) {
		if ( $mode === 'php' )
			return null;

		$extended = Post_Views_Counter()->counter->get_extended_writer() !== null;

		/**
		 * Filter the delivery probe of the active counting mode, for an
		 * extension whose handler counts in it. Return an array with:
		 * - url (string): an http or https URL on this site's host.
		 * - method (string): POST or GET.
		 * - body (array): at most ten string fields, for POST only; a GET probe
		 *   carries its query in url.
		 * - shape (string): how the handler answers, with a JSON content type:
		 *   ajax, a refusal whose code is data.code; rest, a refusal whose code
		 *   is code; or ajax_success, an answer of a handler that never counts,
		 *   { success: true, data: code }.
		 * - status (int): the status of that answer: 400 to 499 for a refusal,
		 *   200 for ajax_success.
		 * - code (string): the code in that answer, matching
		 *   [a-z][a-z0-9_]{0,63}.
		 * - marker (string, optional): text the counting script prints into
		 *   a page, for the page scan; [A-Za-z0-9_-]{3,64}.
		 *
		 * The probe is sent without cookies from the administrator's browser
		 * and from the server, so it must be a request the handler refuses
		 * before counting, or one to a handler that never counts. Anything
		 * else, or an invalid array, is ignored.
		 *
		 * @since 1.8.2
		 *
		 * @param array|null $probe Probe, initially null.
		 * @param string     $mode Counting mode.
		 */
		$probe = $this->validate_probe( apply_filters( 'pvc_status_delivery_probe', null, $mode ) );

		if ( $probe !== null )
			return $probe;

		if ( $mode === 'js' ) {
			// with an extended writer its own action counts; this tests the transport only
			return [
				'transport'	=> 'admin_ajax',
				'handler'	=> $extended ? 'core_transport_only' : 'core',
				'url'		=> admin_url( 'admin-ajax.php' ),
				'method'	=> 'POST',
				'body'		=> [ 'action' => 'pvc-check-post' ],
				'status'	=> 400,
				'code'		=> 'pvc_missing_parameters',
				'shape'		=> 'ajax',
				'marker'	=> $extended ? '' : 'pvcArgsFrontend'
			];
		}

		if ( $mode === 'rest_api' ) {
			// the handler refuses a post that does not exist before counting
			if ( get_post( self::MISSING_POST_ID ) !== null )
				return null;

			return [
				'transport'	=> 'rest_api',
				'handler'	=> 'core',
				'url'		=> rest_url( 'post-views-counter/view-post/' . self::MISSING_POST_ID ),
				'method'	=> 'POST',
				'body'		=> [],
				'status'	=> 404,
				'code'		=> 'pvc_post_invalid_id',
				'shape'		=> 'rest',
				'marker'	=> $extended ? '' : 'pvcArgsFrontend'
			];
		}

		return null;
	}

	/**
	 * The probe of a display endpoint that loads values into cached pages,
	 * when an extension provides one: tested from the browser and the server
	 * like the counting probe, in every counting mode.
	 *
	 * @return array|null Probe.
	 */
	public function get_display_probe() {
		/**
		 * Filter the probe of a display endpoint that loads values into
		 * cached pages. The array has the fields of
		 * pvc_status_delivery_probe (url, method, body, shape, status, code)
		 * and must name a request the endpoint answers without counting; the
		 * marker is not used. Anything else, or an invalid array, is ignored.
		 *
		 * @since 1.8.2
		 *
		 * @param array|null $probe Probe, initially null.
		 */
		$probe = $this->validate_probe( apply_filters( 'pvc_status_display_probe', null ) );

		if ( $probe === null )
			return null;

		$probe['transport'] = 'display';
		$probe['marker'] = '';

		return $probe;
	}

	/**
	 * Validate an extension's probe into a fresh array.
	 *
	 * @param mixed $probe Returned probe.
	 * @return array|null
	 */
	private function validate_probe( $probe ) {
		if ( ! is_array( $probe ) || ! isset( $probe['url'], $probe['method'], $probe['status'], $probe['code'], $probe['shape'] ) )
			return null;

		if ( ! is_string( $probe['url'] ) || ! $this->is_site_url( $probe['url'] ) )
			return null;

		if ( ! in_array( $probe['method'], [ 'POST', 'GET' ], true ) || ! in_array( $probe['shape'], [ 'ajax', 'rest', 'ajax_success' ], true ) )
			return null;

		// a refusal is a 4xx; only a handler that never counts may answer 200
		if ( ! is_int( $probe['status'] ) || ( $probe['shape'] === 'ajax_success' ? $probe['status'] !== 200 : ( $probe['status'] < 400 || $probe['status'] > 499 ) ) )
			return null;

		if ( ! is_string( $probe['code'] ) || preg_match( '/^[a-z][a-z0-9_]{0,63}\z/', $probe['code'] ) !== 1 )
			return null;

		$body = isset( $probe['body'] ) ? $probe['body'] : [];

		if ( ! is_array( $body ) || count( $body ) > 10 || ( $probe['method'] === 'GET' && ! empty( $body ) ) )
			return null;

		foreach ( $body as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) || strlen( $key ) > 64 || strlen( $value ) > 256 )
				return null;
		}

		$marker = isset( $probe['marker'] ) && is_string( $probe['marker'] ) && preg_match( '/^[A-Za-z0-9_-]{3,64}\z/', $probe['marker'] ) === 1 ? $probe['marker'] : '';

		return [
			'transport'	=> 'extension',
			'handler'	=> 'extension',
			'url'		=> esc_url_raw( $probe['url'] ),
			'method'	=> $probe['method'],
			'body'		=> $body,
			'status'	=> $probe['status'],
			'code'		=> $probe['code'],
			'shape'		=> $probe['shape'],
			'marker'	=> $marker
		];
	}

	/**
	 * Whether a URL points at this site: http or https, the host and port of
	 * the home or site URL, and no credentials.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_site_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) )
			return false;

		foreach ( [ home_url(), site_url() ] as $site ) {
			$site = wp_parse_url( $site );

			if ( is_array( $site ) && isset( $site['host'] ) && strtolower( $site['host'] ) === strtolower( $parts['host'] ) && ( isset( $site['port'] ) ? $site['port'] : null ) === ( isset( $parts['port'] ) ? $parts['port'] : null ) )
				return true;
		}

		return false;
	}

	/**
	 * Classify an answer to a probe. Only the probe's named answer (its
	 * status, a JSON content type and its code) shows that the request
	 * reached the plugin; WordPress answering otherwise is not that.
	 *
	 * @param array  $probe Probe.
	 * @param int    $status HTTP status.
	 * @param string $content_type Content type.
	 * @param string $body Response body.
	 * @return string Result code.
	 */
	public function classify( $probe, $status, $content_type, $body ) {
		$status = (int) $status;
		$json = $this->normalize_content_type( $content_type ) === 'application/json';
		$data = $json ? json_decode( (string) $body, true ) : null;
		$code = null;

		if ( is_array( $data ) ) {
			if ( $probe['shape'] === 'ajax' && isset( $data['data']['code'] ) && is_string( $data['data']['code'] ) )
				$code = $data['data']['code'];
			elseif ( $probe['shape'] === 'rest' && isset( $data['code'] ) && is_string( $data['code'] ) )
				$code = $data['code'];
			elseif ( $probe['shape'] === 'ajax_success' && isset( $data['success'], $data['data'] ) && $data['success'] === true && is_string( $data['data'] ) )
				$code = $data['data'];
		}

		if ( $status === $probe['status'] && $json && $code === $probe['code'] )
			return 'reached_pvc';

		// WordPress's own answers: admin-ajax's 0 or -1, or a JSON error it built
		$trimmed = trim( (string) $body );

		if ( in_array( $trimmed, [ '0', '-1' ], true ) || ( is_array( $data ) && ( array_key_exists( 'success', $data ) || ( isset( $data['code'], $data['message'] ) ) ) ) )
			return 'reached_wordpress';

		if ( $status >= 300 )
			return 'blocked';

		return 'unknown';
	}

	/**
	 * A response's media type, lowercase and without parameters; an empty
	 * string for anything else.
	 *
	 * @param mixed $content_type Content type header.
	 * @return string
	 */
	public function normalize_content_type( $content_type ) {
		if ( ! is_string( $content_type ) )
			return '';

		$type = strtolower( trim( explode( ';', $content_type )[0] ) );

		return preg_match( '/^[a-z]+\/[a-z0-9.+-]{1,60}\z/', $type ) === 1 ? $type : '';
	}

	/**
	 * The result of a failed request: a timeout, a certificate the server
	 * could not verify, or another network error.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	private function get_failure( $error ) {
		$message = $error->get_error_message();

		if ( stripos( $message, 'timed out' ) !== false )
			return 'timed_out';

		if ( stripos( $message, 'ssl' ) !== false || stripos( $message, 'certificate' ) !== false )
			return 'tls_error';

		return 'network_error';
	}

	/**
	 * Severity of a probe from both origins: a block seen from both is a
	 * likely stop, from one a risk; only the named answer is Good.
	 *
	 * @param string $result Result of this origin.
	 * @param string $other Result of the other origin.
	 * @return string
	 */
	public function get_severity( $result, $other ) {
		if ( $result === 'reached_pvc' )
			return 'good';

		if ( in_array( $result, [ 'blocked', 'network_error', 'timed_out' ], true ) )
			return in_array( $other, [ 'blocked', 'network_error', 'timed_out' ], true ) ? 'critical' : 'warning';

		return 'unknown';
	}

	/**
	 * Send the probe from the server, without cookies and without following
	 * redirects.
	 *
	 * @param array $probe Probe.
	 * @return array{result:string,status:int,content_type:string,headers:array}
	 */
	public function run_server_probe( $probe ) {
		$response = wp_remote_request( $probe['url'], [
			'method'		=> $probe['method'],
			'body'			=> $probe['method'] === 'POST' ? $probe['body'] : null,
			'timeout'		=> self::TIMEOUT,
			'redirection'	=> 0,
			'cookies'		=> [],
			'headers'		=> [ 'Cache-Control' => 'no-cache' ],
			// an answer from an unverified certificate is not evidence
			'sslverify'		=> true,
			'user-agent'	=> 'Post Views Counter delivery check'
		] );

		if ( is_wp_error( $response ) )
			return [ 'result' => $this->get_failure( $response ), 'status' => 0, 'content_type' => '', 'headers' => [] ];

		$status = (int) wp_remote_retrieve_response_code( $response );
		$content_type = $this->normalize_content_type( wp_remote_retrieve_header( $response, 'content-type' ) );

		return [
			'result'		=> $this->classify( $probe, $status, $content_type, wp_remote_retrieve_body( $response ) ),
			'status'		=> $status,
			'content_type'	=> $content_type,
			'headers'		=> $this->get_cache_headers( $response )
		];
	}

	/**
	 * Fetch one published countable post as an anonymous visitor and look
	 * for the counting script. In REST API mode the printed token is checked
	 * as WordPress would check it for a visitor. Only in the modes where the
	 * browser counts: fetching a page in PHP mode would count it.
	 *
	 * @param string $mode Counting mode.
	 * @param array  $probe Probe.
	 * @return array{result:string,status:int,token:string,headers:array}
	 */
	public function run_page_scan( $mode, $probe ) {
		$scan = [ 'result' => 'unknown', 'status' => 0, 'token' => 'none', 'headers' => [] ];

		if ( ! in_array( $mode, self::SCAN_MODES, true ) || $probe === null || $probe['marker'] === '' )
			return $scan;

		$post_types = array_values( array_filter( (array) Post_Views_Counter()->options['general']['post_types_count'], 'is_string' ) );
		$posts = empty( $post_types ) ? [] : get_posts( [
			'post_type'			=> $post_types,
			'post_status'		=> 'publish',
			'has_password'		=> false,
			'numberposts'		=> 1,
			'orderby'			=> 'date',
			'order'				=> 'DESC',
			'fields'			=> 'ids',
			'no_found_rows'		=> true,
			'suppress_filters'	=> true
		] );

		if ( empty( $posts ) ) {
			$scan['result'] = 'no_post';

			return $scan;
		}

		$url = get_permalink( $posts[0] );

		// redirects are followed by hand, and only while they stay on this site
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$response = wp_remote_get( $url, [
				'timeout'		=> self::TIMEOUT,
				'redirection'	=> 0,
				'cookies'		=> [],
				'sslverify'		=> true,
				'user-agent'	=> 'Post Views Counter delivery check'
			] );

			if ( is_wp_error( $response ) ) {
				$scan['result'] = $this->get_failure( $response );

				return $scan;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$location = wp_remote_retrieve_header( $response, 'location' );

			if ( $code < 300 || $code > 399 || ! is_string( $location ) || $location === '' || $hop === self::MAX_REDIRECTS )
				break;

			$next = WP_Http::make_absolute_url( $location, $url );

			if ( ! $this->is_site_url( $next ) )
				break;

			$url = $next;
		}

		$scan['status'] = (int) wp_remote_retrieve_response_code( $response );
		$scan['headers'] = $this->get_cache_headers( $response );

		if ( $scan['status'] !== 200 ) {
			$scan['result'] = 'blocked';

			return $scan;
		}

		$html = (string) wp_remote_retrieve_body( $response );

		if ( strpos( $html, $probe['marker'] ) === false ) {
			$scan['result'] = 'script_missing';

			return $scan;
		}

		// an extension's script is known by its marker alone
		if ( $probe['marker'] !== 'pvcArgsFrontend' ) {
			$scan['result'] = 'script_found';

			return $scan;
		}

		// Core's configuration, minified or not: whether it counts here, and its token
		$args = preg_match( '/var\s+pvcArgsFrontend\s*=\s*(\{[^<]*?\})\s*;/', $html, $match ) === 1 ? json_decode( $match[1], true ) : null;

		if ( ! is_array( $args ) || ! isset( $args['countEnabled'] ) || ! is_bool( $args['countEnabled'] ) ) {
			$scan['result'] = 'config_unreadable';

			return $scan;
		}

		$scan['result'] = $args['countEnabled'] ? 'script_found' : 'script_off';

		if ( $mode === 'rest_api' && isset( $args['nonce'] ) && is_string( $args['nonce'] ) && $args['nonce'] !== '' )
			$scan['token'] = $this->is_visitor_token_valid( $args['nonce'] ) ? 'valid' : 'expired';

		return $scan;
	}

	/**
	 * Whether a REST token printed for a logged-out visitor still verifies,
	 * computed as wp_verify_nonce() does for user 0 without switching the
	 * current user.
	 *
	 * @param string $nonce Token.
	 * @return bool
	 */
	private function is_visitor_token_valid( $nonce ) {
		$uid = (int) apply_filters( 'nonce_user_logged_out', 0, 'wp_rest' );
		$tick = wp_nonce_tick( 'wp_rest' );

		foreach ( [ $tick, $tick - 1 ] as $i ) {
			if ( hash_equals( substr( wp_hash( $i . '|wp_rest|' . $uid . '|', 'nonce' ), -12, 10 ), $nonce ) )
				return true;
		}

		return false;
	}

	/**
	 * Names of cache and CDN headers in a response, from a fixed list.
	 *
	 * @param array $response HTTP response.
	 * @return array
	 */
	private function get_cache_headers( $response ) {
		$headers = wp_remote_retrieve_headers( $response );
		$seen = [];

		foreach ( self::CACHE_HEADERS as $name ) {
			if ( ( is_object( $headers ) || is_array( $headers ) ) && isset( $headers[ $name ] ) )
				$seen[] = $name;
		}

		return $seen;
	}

	/**
	 * Run the server checks for the button and return the updated rows,
	 * verdict and report lines. Nothing is stored.
	 *
	 * @return void
	 */
	public function ajax_run() {
		if ( ! check_ajax_referer( self::ACTION, 'nonce', false ) || ! current_user_can( apply_filters( 'pvc_settings_capability', 'manage_options' ) ) )
			wp_send_json_error( [ 'code' => 'pvc_forbidden' ], 403 );

		wp_send_json_success( $this->build_results( wp_unslash( $_POST ) ) );
	}

	/**
	 * One browser probe's result as the page sent it: a result code from the
	 * fixed set, an HTTP status or none, and a media type. Anything else is
	 * unknown or none.
	 *
	 * @param array  $input Posted fields.
	 * @param string $prefix browser or display.
	 * @return array{result:string,status:int,content_type:string}
	 */
	private function get_browser_result( $input, $prefix ) {
		$result = isset( $input[ $prefix . '_result' ] ) ? $input[ $prefix . '_result' ] : null;
		$status = isset( $input[ $prefix . '_status' ] ) ? $input[ $prefix . '_status' ] : null;

		return [
			'result'		=> in_array( $result, self::RESULTS, true ) ? $result : 'unknown',
			// an HTTP status, or 0 for none: anything else from the browser is no status
			'status'		=> is_string( $status ) && preg_match( '/^[1-5]\d\d\z/', $status ) === 1 ? (int) $status : 0,
			'content_type'	=> isset( $input[ $prefix . '_content_type' ] ) ? $this->normalize_content_type( $input[ $prefix . '_content_type' ] ) : ''
		];
	}

	/**
	 * The results of one run, from the browser's result and the server's
	 * own checks.
	 *
	 * @param array $input Posted fields: browser_result, browser_status, counts.
	 * @return array
	 */
	public function build_results( $input ) {
		$pvc = Post_Views_Counter();
		$mode = (string) $pvc->options['general']['counter_mode'];
		$probe = $mode === 'php' ? null : $this->get_probe( $mode );
		$display = $this->get_display_probe();
		$now = time();
		$browser = $this->get_browser_result( $input, 'browser' );
		$server = $probe === null ? [ 'result' => 'unknown', 'status' => 0, 'content_type' => '', 'headers' => [] ] : $this->run_server_probe( $probe );
		$scan = $this->run_page_scan( $mode, $probe );
		$delivery = [
			'mode'			=> $mode,
			'probe'			=> $probe,
			'browser'		=> $browser,
			'server'		=> $server,
			'scan'			=> $scan,
			'headers'		=> array_values( array_unique( array_merge( $server['headers'], $scan['headers'] ) ) ),
			'display'		=> $display === null ? null : [
				'probe'		=> $display,
				'browser'	=> $this->get_browser_result( $input, 'display' ),
				'server'	=> $this->run_server_probe( $display )
			],
			'checked_at'	=> $now
		];

		return $pvc->settings->status->render_delivery_results( $delivery, isset( $input['counts'] ) ? $input['counts'] : '' );
	}
}
