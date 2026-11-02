<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Settings_Status class.
 *
 * The Status tab: checks built from the passively collected facts, and the
 * checks extensions add through the pvc_status_checks filter. The tab saves
 * no settings and counts no views.
 *
 * @class Post_Views_Counter_Settings_Status
 */
class Post_Views_Counter_Settings_Status {

	/**
	 * Version of the pvc_status_checks contract this class implements. An
	 * extension reads it at admin_init or later to detect the extension
	 * point; reading it never collects checks.
	 */
	const CHECKS_API_VERSION = 1;

	/** Settings page the optionless Status section is registered on. */
	const PAGE = 'pvc_status_settings';

	/** Status section ID. */
	const SECTION = 'post_views_counter_status';

	/** Most extension checks accepted per request. */
	const MAX_EXTENSION_CHECKS = 64;

	/** Groups only Core reports in. */
	const CORE_GROUPS = [ 'counting_setup', 'delivery', 'scheduled_tasks', 'data_storage', 'environment' ];

	/** Groups an extension may report in; Core also reports its counting rules. */
	const EXTENSION_GROUPS = [ 'fast_ajax', 'dynamic_loading', 'buffering', 'caching_integration', 'counting_rules', 'license', 'other_checks' ];

	/** Every group, in collection and report order. */
	const GROUP_ORDER = [ 'counting_setup', 'counting_rules', 'delivery', 'scheduled_tasks', 'data_storage', 'environment', 'fast_ajax', 'dynamic_loading', 'buffering', 'caching_integration', 'license', 'other_checks' ];

	/** Display sections combine related groups without changing their report order. */
	const DISPLAY_SECTIONS = [
		'counting_setup'	=> [ 'counting_setup' ],
		'counting_rules'	=> [ 'counting_rules' ],
		'delivery'		=> [ 'delivery', 'fast_ajax', 'dynamic_loading', 'caching_integration' ],
		'scheduled_tasks' => [ 'scheduled_tasks', 'buffering' ],
		'data_storage'	=> [ 'data_storage' ],
		'environment'	=> [ 'environment', 'license' ],
		'other_checks'	=> [ 'other_checks' ]
	];

	/** Severities, problems first: the order checks take within a group. */
	const SEVERITIES = [ 'critical', 'warning', 'recommended', 'unknown', 'not_tested', 'good', 'not_applicable' ];

	/** Severities that need a scope and an observation time as evidence. */
	const EVIDENCE_SEVERITIES = [ 'critical', 'warning', 'recommended', 'good' ];

	/** Severities shown collapsed as passed while any problem is shown. */
	const PASSED_SEVERITIES = [ 'good', 'not_applicable' ];

	/** Fields an extension check may carry. */
	const EXTENSION_FIELDS = [ 'id', 'group', 'severity', 'title', 'detail', 'scope', 'observed_at', 'action', 'report' ];

	private $pvc;
	private $settings;

	/** @var array|null Checks of this request, ordered. */
	private $checks = null;

	/** @var array|null Facts the checks of this request were built from. */
	private $facts = null;

	/** @var Post_Views_Counter_Status_Delivery Delivery checks. */
	private $delivery;

	/** Checks the delivery button runs; they start as Not tested. */
	const DELIVERY_IDS = [ 'pvc_delivery_browser', 'pvc_delivery_server', 'pvc_page_scan', 'pvc_display_browser', 'pvc_display_server' ];

	/** @var bool Whether checks are being collected, so a callback cannot re-enter. */
	private $collecting = false;

	/** @var int Collection time of this request's checks. */
	private $checked_at = 0;

	/**
	 * Class constructor.
	 *
	 * @param Post_Views_Counter_Settings $settings
	 * @return void
	 */
	public function __construct( $settings = null ) {
		$this->pvc = Post_Views_Counter();
		$this->settings = $settings;
		$this->delivery = new Post_Views_Counter_Status_Delivery();

		// after the settings API registers the option tabs
		add_action( 'admin_init', [ $this, 'register_section' ], 12 );
	}

	/**
	 * Get sections for the Status tab.
	 *
	 * @return array
	 */
	public function get_sections() {
		return [
			self::SECTION => [
				'tab'		=> 'status',
				'callback'	=> [ $this, 'section_status' ]
			]
		];
	}

	/**
	 * Register the Status section directly: the tab has no option, so the
	 * settings API registers no section for it.
	 *
	 * @return void
	 */
	public function register_section() {
		$prefix = sanitize_html_class( apply_filters( 'pvc_settings_section_prefix', 'pvc' ) );
		$slug = sanitize_html_class( str_replace( '_', '-', self::SECTION ) );

		add_settings_section(
			self::SECTION,
			'',
			[ $this, 'section_status' ],
			self::PAGE,
			[
				'section_class'		=> $prefix . '-section ' . $prefix . '-section-' . $slug,
				'before_section'	=> '<section id="' . $prefix . '-section-' . $slug . '" class="%s">',
				'after_section'		=> '</section>'
			]
		);
	}

	/**
	 * Whether this is an authorized PVC settings request. Every tab uses
	 * the same passive checks for its Status indicator.
	 *
	 * @return bool
	 */
	private function can_collect() {
		if ( ! current_user_can( apply_filters( 'pvc_settings_capability', 'manage_options' ) ) )
			return false;

		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) )
			return false;

		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'post-views-counter' )
			return false;

		$tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'general';

		return in_array( $tab, [ 'general', 'display', 'reports', 'integrations', 'emails', 'other', 'status' ], true );
	}

	/**
	 * The same passive attention state on every PVC settings tab.
	 *
	 * @return string default, warning or error.
	 */
	public function get_tab_state() {
		if ( ! $this->can_collect() )
			return 'default';

		$type = $this->get_notice_type( $this->count_severities( $this->get_checks() ) );

		return $type === 'info' ? 'default' : $type;
	}

	/**
	 * Server-rendered tab icon classes and accessible label.
	 *
	 * @return array
	 */
	public function get_tab_icon() {
		$state = $this->get_tab_state();
		$label = __( 'Status', 'post-views-counter' );

		return [
			'class' => 'pvc-status-tab-icon' . ( $state === 'default' ? '' : ' notice-' . $state ),
			'label' => $state === 'default' ? $label : $label . ': ' . $this->get_severity_label( $state === 'error' ? 'critical' : 'warning' )
		];
	}

	/**
	 * Render the Status tab.
	 *
	 * @return void
	 */
	public function section_status() {
		if ( ! $this->can_collect() || ! isset( $_GET['tab'] ) || $_GET['tab'] !== 'status' )
			return;

		$checks = $this->get_checks();
		$counts = $this->count_severities( $checks );
		$collapse = $counts['critical'] + $counts['warning'] > 0;

		echo $this->get_verdict_html( $counts );

		foreach ( self::DISPLAY_SECTIONS as $group => $groups ) {
			$group_checks = $this->get_section_checks( $checks, $groups );

			if ( empty( $group_checks ) )
				continue;

			echo '<div class="pvc-status-group pvc-status-group-' . esc_attr( str_replace( '_', '-', $group ) ) . '">';

			if ( $group !== 'counting_setup' )
				echo '<h2>' . esc_html( $this->get_group_label( $group ) ) . '</h2>';

			// Recorded activity stays visible even when other checks are folded.
			if ( $group === 'counting_setup' ) {
				foreach ( $checks as $check ) {
					if ( $check['id'] === 'pvc_counting_activity' )
						echo $this->get_activity_html( $check );
				}
			}

			$shown = [];
			$passed = [];

			foreach ( $group_checks as $check ) {
				if ( $collapse && in_array( $check['severity'], self::PASSED_SEVERITIES, true ) )
					$passed[] = $check;
				else
					$shown[] = $check;
			}

			if ( ! empty( $shown ) )
				echo $this->get_checks_table_html( $shown );

			// while problems are shown, passed checks fold into one line
			if ( ! empty( $passed ) ) {
				/* translators: %d: number of checks */
				echo '<details class="pvc-status-passed"><summary>' . esc_html( sprintf( _n( '%d check passed or does not apply', '%d checks passed or do not apply', count( $passed ), 'post-views-counter' ), count( $passed ) ) ) . '</summary>';
				echo $this->get_checks_table_html( $passed );
				echo '</details>';
			}

			if ( $group === 'delivery' )
				echo $this->get_delivery_controls_html( $checks );

			echo '</div>';
		}

		if ( $this->facts !== null )
			echo $this->get_report_html( new Post_Views_Counter_Status_Report( $this->facts, $checks, $this->checked_at ) );
	}

	/**
	 * A section's rows, problems first across all its groups. Equal severities
	 * keep their collected order; the original list still feeds the report.
	 *
	 * @param array $checks Collected checks.
	 * @param array $groups Groups shown in this section.
	 * @return array
	 */
	private function get_section_checks( $checks, $groups ) {
		$ordered = array_fill_keys( self::SEVERITIES, [] );

		foreach ( $checks as $check ) {
			if ( $check['id'] !== 'pvc_counting_activity' && in_array( $check['group'], $groups, true ) )
				$ordered[ $check['severity'] ][] = $check;
		}

		return array_merge( ...array_values( $ordered ) );
	}

	/**
	 * The checks of this request: Core's, then the accepted extension
	 * checks, each group problems first. Collected once per authorized PVC
	 * settings page; a callback that asks again while
	 * they are collected gets none.
	 *
	 * @return array
	 */
	public function get_checks() {
		if ( $this->checks !== null )
			return $this->checks;

		if ( $this->collecting || ! $this->can_collect() )
			return [];

		$this->collecting = true;

		try {
			$this->checked_at = time();
			$facts = ( new Post_Views_Counter_Status_Facts() )->collect( $this->checked_at );
			$this->facts = $facts;
			$core = $this->get_core_checks( $facts );
			$this->checks = $this->order_checks( array_merge( $core, $this->get_extension_checks( array_column( $core, 'id' ), $this->checked_at ) ) );
		} finally {
			$this->collecting = false;
		}

		return $this->checks;
	}

	/**
	 * Core's checks, from the collected facts.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_core_checks( $facts ) {
		return array_values( array_filter( array_merge( [
			$this->get_counting_mode_check( $facts ),
			$this->get_ip_source_check( $facts ),
			$this->get_exclusions_check( $facts ),
			$this->get_counted_content_check( $facts ),
			$this->get_caching_check( $facts ),
			$this->get_security_check( $facts ),
			$this->get_scheduled_events_check( $facts ),
			$this->get_buffering_check( $facts ),
			$this->get_core_queue_check( $facts ),
			$this->get_views_access_check( $facts ),
			$this->get_write_check( $facts ),
			$this->get_visits_check( $facts ),
			$this->get_derived_check( $facts ),
			$this->get_activity_check( $facts ),
			$this->get_tables_check( $facts ),
			$this->get_versions_check( $facts )
		], $this->get_delivery_checks( $facts ) ) ) );
	}

	/**
	 * Build one check.
	 *
	 * @param string       $id Check ID.
	 * @param string       $group Group.
	 * @param string       $severity Severity.
	 * @param string       $title Finding.
	 * @param array        $detail Detail lines.
	 * @param string       $scope What was read, and what stays unverified.
	 * @param int|null     $observed_at Observation time.
	 * @return array
	 */
	private function check( $id, $group, $severity, $title, $detail, $scope, $observed_at ) {
		return [
			'id'			=> $id,
			'group'			=> $group,
			'severity'		=> $severity,
			'title'			=> $title,
			'detail'		=> array_values( $detail ),
			'scope'			=> $scope,
			'observed_at'	=> $observed_at,
			'action'		=> null,
			'source'		=> 'core'
		];
	}

	/**
	 * The active counting mode.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_counting_mode_check( $facts ) {
		$mode = $facts['counter_mode']['value'];
		$modes = isset( $this->pvc->settings->general ) ? (array) $this->pvc->settings->general->get_counter_modes() : [];
		$label = isset( $modes[ $mode ] ) && is_string( $modes[ $mode ] ) ? wp_strip_all_tags( $modes[ $mode ] ) : $mode;

		/* translators: %s: counting mode */
		return $this->check( 'pvc_counting_mode', 'counting_setup', 'good', sprintf( __( 'Counting mode: %s.', 'post-views-counter' ), $label ), [], __( 'Set in the Counting settings. Whether views are counted shows in Counting activity.', 'post-views-counter' ), $facts['counter_mode']['observed_at'] );
	}

	/**
	 * Where visitor IP addresses come from, as a row only when it needs
	 * attention: the source could not be read, or it takes headers that any
	 * visitor can set. Otherwise the Exclusions row describes it, when IP
	 * addresses are excluded. The address itself is never shown.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array|null
	 */
	private function get_ip_source_check( $facts ) {
		$fact = $facts['ip_resolution'];
		$scope = __( 'Set in code; by default, addresses come from the direct connection.', 'post-views-counter' );

		if ( ! $fact['available'] )
			return $this->check( 'pvc_ip_source', 'counting_rules', 'unknown', __( 'Where visitor IP addresses come from could not be checked.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		if ( $fact['value']['strategy'] !== 'auto' )
			return null;

		return $this->check( 'pvc_ip_source', 'counting_rules', 'recommended', __( 'Visitor IP addresses can be taken from forwarded headers, which visitors can fake, so IP exclusions can be bypassed or applied to the wrong visitor.', 'post-views-counter' ), $this->get_ip_resolved_detail( $fact ), $scope, $fact['observed_at'] );
	}

	/**
	 * Where excluded IP addresses are matched from, for the Exclusions row.
	 * An unreadable source or one that takes forwarded headers has its own
	 * row.
	 *
	 * @param array $fact IP resolution fact.
	 * @return array Detail lines.
	 */
	private function get_ip_detail( $fact ) {
		if ( ! $fact['available'] || $fact['value']['strategy'] === 'auto' )
			return [];

		if ( $fact['value']['strategy'] === 'trusted_proxy_only' )
			$detail = [ __( 'IP addresses come from forwarded headers only for requests through a proxy you trust.', 'post-views-counter' ) ];
		else
			$detail = [ __( 'IP addresses come from the direct connection. Behind a proxy or CDN such as Cloudflare, visitors can share one address, so IP exclusions may not tell them apart.', 'post-views-counter' ) ];

		return array_merge( $detail, $this->get_ip_resolved_detail( $fact ) );
	}

	/**
	 * A line when no address resolved for this administrator: a private or
	 * local address, or one the strategy does not trust, resolves to none.
	 *
	 * @param array $fact IP resolution fact.
	 * @return array Detail lines.
	 */
	private function get_ip_resolved_detail( $fact ) {
		return $fact['value']['resolved'] ? [] : [ __( 'Your own IP address could not be determined, so IP exclusions cannot match you here.', 'post-views-counter' ) ];
	}

	/**
	 * Who is excluded from counting, by group and count: never a role name
	 * or an IP address. Only the groups this plugin's settings name are
	 * listed.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_exclusions_check( $facts ) {
		$fact = $facts['exclusions'];
		$scope = __( 'Read from the Counting settings.', 'post-views-counter' );

		if ( ! $fact['available'] )
			return $this->check( 'pvc_exclusions', 'counting_rules', 'unknown', __( 'Who is excluded from counting could not be checked.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		$groups = $fact['value']['groups'];

		// the counter refuses a logged-in visitor by the first, any other by the second
		if ( in_array( 'users', $groups, true ) && in_array( 'guests', $groups, true ) ) {
			$check = $this->check( 'pvc_exclusions', 'counting_rules', 'critical', __( 'Every visitor is excluded: logged-in users and guests. No view can be counted.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );
			$check['action'] = [ 'label' => __( 'Review the exclusions', 'post-views-counter' ), 'url' => $this->get_tab_url( 'general' ) ];

			return $check;
		}

		$excluded = [];

		if ( in_array( 'robots', $groups, true ) )
			$excluded[] = __( 'known crawlers', 'post-views-counter' );

		if ( in_array( 'users', $groups, true ) )
			$excluded[] = __( 'logged-in users', 'post-views-counter' );

		if ( in_array( 'guests', $groups, true ) )
			$excluded[] = __( 'guests', 'post-views-counter' );

		if ( in_array( 'roles', $groups, true ) && $fact['value']['roles_count'] > 0 )
			/* translators: %d: number of roles */
			$excluded[] = sprintf( _n( 'users with %d excluded role', 'users with %d excluded roles', $fact['value']['roles_count'], 'post-views-counter' ), $fact['value']['roles_count'] );

		if ( $fact['value']['ips_count'] > 0 )
			/* translators: %d: number of IP addresses */
			$excluded[] = sprintf( _n( '%d excluded IP address', '%d excluded IP addresses', $fact['value']['ips_count'], 'post-views-counter' ), $fact['value']['ips_count'] );

		if ( empty( $excluded ) )
			// a group this row does not name has a row of its own
			return $this->check( 'pvc_exclusions', 'counting_rules', 'good', array_diff( $groups, [ 'robots', 'users', 'guests', 'roles' ] ) ? __( 'No other visitors are excluded from counting.', 'post-views-counter' ) : __( 'No visitors are excluded from counting.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		// where the addresses come from matters only when some are excluded
		$detail = $fact['value']['ips_count'] > 0 ? $this->get_ip_detail( $facts['ip_resolution'] ) : [];

		/* translators: %s: excluded visitors */
		return $this->check( 'pvc_exclusions', 'counting_rules', 'good', sprintf( __( 'Not counted: %s.', 'post-views-counter' ), wp_sprintf( '%l', $excluded ) ), $detail, $scope, $fact['observed_at'] );
	}

	/**
	 * The post types whose views are counted. A selected post type that is
	 * no longer registered counts nothing.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_counted_content_check( $facts ) {
		$fact = $facts['countable_content'];
		$scope = __( 'Read from the Counting settings.', 'post-views-counter' );

		if ( ! $fact['available'] )
			return $this->check( 'pvc_counted_content', 'counting_rules', 'unknown', __( 'Which post types are counted could not be checked.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		$names = [];
		$missing = [];

		foreach ( $fact['value']['post_types'] as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( $object === null )
				$missing[] = (string) $post_type;
			else
				$names[] = $object->labels->name;
		}

		/* translators: %s: post types */
		$detail = empty( $missing ) ? [] : [ sprintf( __( 'Selected, but not registered on this site: %s.', 'post-views-counter' ), implode( ', ', $missing ) ) ];

		if ( ! empty( $names ) )
			/* translators: %s: post type names */
			return $this->check( 'pvc_counted_content', 'counting_rules', 'good', sprintf( __( 'Counted: %s.', 'post-views-counter' ), wp_sprintf( '%l', $names ) ), $detail, $scope, $fact['observed_at'] );

		$title = empty( $missing ) ? __( 'No post type is selected for counting, so post views are not counted automatically.', 'post-views-counter' ) : __( 'None of the post types selected for counting is registered on this site.', 'post-views-counter' );
		$check = $this->check( 'pvc_counted_content', 'counting_rules', 'critical', $title, $detail, $scope, $fact['observed_at'] );
		$check['action'] = [ 'label' => __( 'Choose what to count', 'post-views-counter' ), 'url' => $this->get_tab_url( 'general' ) ];

		return $check;
	}

	/**
	 * Recognized caching plugins, against the counting mode. None
	 * recognized never means no page cache.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_caching_check( $facts ) {
		$fact = $facts['caching_plugins'];
		$php = $facts['counter_mode']['value'] === 'php';
		$scope = __( 'Only known caching plugins are recognized; caching by your host, a CDN or the server is not detected.', 'post-views-counter' );

		// the finding depends on the mode as much as on the plugins
		if ( ! $fact['available'] || ! $facts['counter_mode']['available'] )
			return $this->check( 'pvc_caching', 'delivery', 'unknown', __( 'Whether a known caching plugin is active could not be checked.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		$plugins = $fact['value'];
		$names = wp_sprintf( '%l', $plugins );

		if ( $php ) {
			if ( empty( $plugins ) )
				$check = $this->check( 'pvc_caching', 'delivery', 'recommended', __( 'No caching plugin recognized.', 'post-views-counter' ), [ __( 'Hosts and CDNs can cache pages too, and PHP mode does not count a page served from such a cache.', 'post-views-counter' ) ], $scope, $fact['observed_at'] );
			else
				/* translators: %s: plugin names */
				$check = $this->check( 'pvc_caching', 'delivery', 'recommended', sprintf( _n( 'PHP mode is used with a recognized caching plugin: %s.', 'PHP mode is used with recognized caching plugins: %s.', count( $plugins ), 'post-views-counter' ), $names ), [ __( 'When the cache serves a page without running WordPress, PHP mode does not count that view.', 'post-views-counter' ) ], $scope, $fact['observed_at'] );

			$check['action'] = [ 'label' => __( 'Review the counting mode', 'post-views-counter' ), 'url' => $this->get_tab_url( 'general' ) ];

			return $check;
		}

		$detail = [ __( 'This mode counts from the visitor\'s browser, so cached pages can be counted when the counting script runs and its request reaches the plugin.', 'post-views-counter' ) ];

		if ( empty( $plugins ) )
			return $this->check( 'pvc_caching', 'delivery', 'good', __( 'No caching plugin recognized.', 'post-views-counter' ), $detail, $scope, $fact['observed_at'] );

		/* translators: %s: plugin names */
		return $this->check( 'pvc_caching', 'delivery', 'good', sprintf( _n( 'Recognized caching plugin: %s.', 'Recognized caching plugins: %s.', count( $plugins ), 'post-views-counter' ), $names ), $detail, $scope, $fact['observed_at'] );
	}

	/**
	 * Recognized security plugins. None recognized never means no firewall.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_security_check( $facts ) {
		$fact = $facts['security_plugins'];
		$scope = __( 'Only known security plugins are recognized; firewalls at your host or a CDN are not detected.', 'post-views-counter' );

		if ( ! $fact['available'] )
			return $this->check( 'pvc_security_plugins', 'delivery', 'unknown', __( 'Whether a known security plugin is active could not be checked.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		if ( empty( $fact['value'] ) )
			return $this->check( 'pvc_security_plugins', 'delivery', 'good', __( 'No security plugin recognized.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		/* translators: %s: plugin names */
		return $this->check( 'pvc_security_plugins', 'delivery', 'good', sprintf( _n( 'Recognized security plugin: %s.', 'Recognized security plugins: %s.', count( $fact['value'] ), 'post-views-counter' ), wp_sprintf( '%l', $fact['value'] ) ), [ __( 'A firewall can block counting requests; the delivery checks test this.', 'post-views-counter' ) ], $scope, $fact['observed_at'] );
	}

	/**
	 * The delivery checks as the page first shows them: Not applicable in
	 * PHP mode, otherwise Not tested until the button runs them.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_delivery_checks( $facts ) {
		$mode = $facts['counter_mode']['available'] ? $facts['counter_mode']['value'] : null;

		if ( $mode === null )
			return [ $this->check( 'pvc_delivery', 'delivery', 'unknown', __( 'The counting mode could not be read, so no delivery check applies.', 'post-views-counter' ), [], __( 'Read from the Counting settings.', 'post-views-counter' ), $facts['counter_mode']['observed_at'] ) ];

		$display = $this->get_display_checks();

		if ( $mode === 'php' )
			return array_merge( $display, [ $this->check( 'pvc_delivery', 'delivery', 'not_applicable', __( 'PHP mode counts while WordPress builds the page, so there is no separate counting request to test.', 'post-views-counter' ), [ __( 'To confirm counting, open a counted post as a visitor who is not excluded (a private window does not get around IP or guest exclusions), then check Counting activity.', 'post-views-counter' ) ], __( 'Read from the Counting settings.', 'post-views-counter' ), $facts['counter_mode']['observed_at'] ) ] );

		$probe = $this->delivery->get_probe( $mode );

		return array_merge( [
			$this->get_probe_check( 'browser', $probe ),
			$this->get_probe_check( 'server', $probe ),
			$this->get_page_scan_check( $mode, $probe )
		], $display );
	}

	/**
	 * The display probe's rows before the button runs, when an extension
	 * provides a display endpoint.
	 *
	 * @return array
	 */
	private function get_display_checks() {
		$display = $this->delivery->get_display_probe();

		if ( $display === null )
			return [];

		return [
			$this->get_probe_check( 'browser', $display, null, null, null, 'pvc_display_browser' ),
			$this->get_probe_check( 'server', $display, null, null, null, 'pvc_display_server' )
		];
	}

	/**
	 * One origin's probe result.
	 *
	 * @param string      $origin browser or server.
	 * @param array|null  $probe Probe.
	 * @param array|null  $run Result and status; null before the button runs.
	 * @param string|null $other The other origin's result.
	 * @param int|null    $observed_at When the probe ran.
	 * @param string|null $id Check id; the counting probe's by default.
	 * @return array
	 */
	private function get_probe_check( $origin, $probe, $run = null, $other = null, $observed_at = null, $id = null ) {
		if ( $id === null )
			$id = $origin === 'browser' ? 'pvc_delivery_browser' : 'pvc_delivery_server';

		$where = $origin === 'browser' ? __( 'this browser', 'post-views-counter' ) : __( 'the server', 'post-views-counter' );
		$scope = $origin === 'browser' ? __( 'Sent from your browser without cookies, as a visitor\'s would be. It shows whether the request gets through, not that views are counted or how other visitors\' networks behave.', 'post-views-counter' ) : __( 'Sent by your server to your own site. It does not show how visitors\' browsers reach the site.', 'post-views-counter' );

		if ( $probe === null )
			return $this->check( $id, 'delivery', 'unknown', __( 'No delivery check is available for the way this mode counts.', 'post-views-counter' ), [], $scope, $observed_at === null ? $this->checked_at : $observed_at );

		$transport = $this->get_transport_label( $probe );

		if ( $probe['handler'] === 'core_transport_only' )
			$scope .= ' ' . __( 'The active extension counts through its own request, so this tests only the plugin\'s standard counting request.', 'post-views-counter' );

		if ( $run === null )
			/* translators: 1: counting request, 2: this browser or the server */
			return $this->check( $id, 'delivery', 'not_tested', sprintf( __( 'Not tested yet. Run the delivery checks to send %1$s from %2$s.', 'post-views-counter' ), $transport, $where ), [], $scope, null );

		$status = (int) $run['status'];
		$type = isset( $run['content_type'] ) && $run['content_type'] !== '' ? $run['content_type'] : __( 'no content type', 'post-views-counter' );

		switch ( $run['result'] ) {
			case 'reached_pvc':
				/* translators: 1: this browser or the server, 2: counting request */
				$title = sprintf( __( 'From %1$s, %2$s reached the plugin and got the expected answer. The test itself counts nothing.', 'post-views-counter' ), $where, $transport );
				$detail = [ __( 'This shows the request reaches the plugin, not that views are counted.', 'post-views-counter' ) ];
				break;

			case 'reached_wordpress':
				/* translators: 1: this browser or the server, 2: counting request, 3: HTTP status */
				$title = sprintf( __( 'From %1$s, %2$s reached WordPress, but the plugin\'s expected answer did not come back (HTTP %3$d), so whether it reaches the plugin is unknown.', 'post-views-counter' ), $where, $transport, $status );
				$detail = [];
				break;

			case 'blocked':
				/* translators: 1: this browser or the server, 2: counting request, 3: HTTP status, 4: content type */
				$title = sprintf( __( 'From %1$s, %2$s was blocked (HTTP %3$d, %4$s). Views sent this way may not be counted. Whether other visitors are blocked too is not known.', 'post-views-counter' ), $where, $transport, $status, $type );
				$detail = [];
				break;

			case 'tls_error':
				/* translators: 1: this browser or the server, 2: counting request */
				$title = sprintf( __( 'From %1$s, %2$s failed because the site\'s security certificate could not be verified, so this result shows nothing either way. Visitors\' browsers check the certificate themselves.', 'post-views-counter' ), $where, $transport );
				$detail = [];
				break;

			case 'network_error':
			case 'timed_out':
				/* translators: 1: this browser or the server, 2: counting request */
				$title = sprintf( $run['result'] === 'timed_out' ? __( 'From %1$s, %2$s got no answer in time. Views sent this way may not be counted. Whether other visitors are affected is not known.', 'post-views-counter' ) : __( 'From %1$s, %2$s failed without an answer. Views sent this way may not be counted. Whether other visitors are affected is not known.', 'post-views-counter' ), $where, $transport );
				$detail = [];
				break;

			default:
				/* translators: 1: this browser or the server, 2: counting request, 3: HTTP status */
				$title = sprintf( __( 'From %1$s, %2$s got an unexpected answer (HTTP %3$d), so the result is unknown.', 'post-views-counter' ), $where, $transport, $status );
				$detail = [];
		}

		return $this->check( $id, 'delivery', $this->delivery->get_severity( $run['result'], $other ), $title, $detail, $scope, $observed_at );
	}

	/**
	 * The page scan's result.
	 *
	 * @param string     $mode Counting mode.
	 * @param array|null $probe Probe.
	 * @param array|null $scan Scan; null before the button runs.
	 * @param int|null   $observed_at When the scan ran.
	 * @return array
	 */
	private function get_page_scan_check( $mode, $probe, $scan = null, $observed_at = null ) {
		$scope = __( 'Your server opens one published post as a visitor would. It does not show that browsers run the script, or that every page has it.', 'post-views-counter' );

		if ( $probe === null || $probe['marker'] === '' )
			return $this->check( 'pvc_page_scan', 'delivery', 'unknown', __( 'This check cannot tell which counting script to look for in this mode.', 'post-views-counter' ), [], $scope, $observed_at === null ? $this->checked_at : $observed_at );

		if ( $scan === null )
			return $this->check( 'pvc_page_scan', 'delivery', 'not_tested', __( 'Not tested yet. Run the delivery checks to look for the counting script on a published post.', 'post-views-counter' ), [], $scope, null );

		$results = [
			'script_found'		=> [ 'good', __( 'A published post, opened as a visitor, has the counting script.', 'post-views-counter' ) ],
			'script_off'		=> [ 'recommended', __( 'A published post has the counting script, but set not to count visitors who are not logged in: an exclusion or setting stops it.', 'post-views-counter' ) ],
			'script_missing'	=> [ 'warning', __( 'A published post, opened as a visitor, has no counting script. A cache or optimization plugin may have removed or delayed it.', 'post-views-counter' ) ],
			'config_unreadable'	=> [ 'unknown', __( 'A published post has the counting script, but its settings could not be read, so whether it counts there is unknown.', 'post-views-counter' ) ],
			'no_post'			=> [ 'unknown', __( 'There is no published post of a counted type to open.', 'post-views-counter' ) ],
			'tls_error'			=> [ 'unknown', __( 'Your server could not open a published post: the site\'s security certificate could not be verified.', 'post-views-counter' ) ],
			/* translators: %d: HTTP status */
			'blocked'			=> [ 'warning', sprintf( __( 'Opening a published post as a visitor was refused (HTTP %d).', 'post-views-counter' ), (int) $scan['status'] ) ],
			'network_error'		=> [ 'unknown', __( 'Your server could not open a published post.', 'post-views-counter' ) ],
			'timed_out'			=> [ 'unknown', __( 'Your server could not open a published post in time.', 'post-views-counter' ) ]
		];
		list( $severity, $title ) = isset( $results[ $scan['result'] ] ) ? $results[ $scan['result'] ] : [ 'unknown', __( 'Whether a published post has the counting script is unknown.', 'post-views-counter' ) ];
		$detail = [];

		// a normal cache effect, worth knowing: one extra request, and a logged-in visitor counted as a guest
		if ( $scan['token'] === 'expired' ) {
			$detail[] = __( 'The security token saved in this cached page has expired; the counting script retries once without it.', 'post-views-counter' );

			if ( $severity === 'good' )
				$severity = 'recommended';
		}

		if ( ! empty( $scan['headers'] ) )
			/* translators: %s: header names */
			$detail[] = sprintf( __( 'Caching seen in the page\'s headers: %s.', 'post-views-counter' ), implode( ', ', $scan['headers'] ) );

		return $this->check( 'pvc_page_scan', 'delivery', $severity, $title, $detail, $scope, $observed_at );
	}

	/**
	 * The counting request a probe sends, in words.
	 *
	 * @param array $probe Probe.
	 * @return string
	 */
	private function get_transport_label( $probe ) {
		if ( $probe['transport'] === 'admin_ajax' )
			return __( 'the counting request', 'post-views-counter' );

		if ( $probe['transport'] === 'rest_api' )
			return __( 'the REST API counting request', 'post-views-counter' );

		if ( $probe['transport'] === 'display' )
			return __( 'the request that shows view counts on cached pages', 'post-views-counter' );

		return __( 'the active extension\'s counting request', 'post-views-counter' );
	}

	/**
	 * The button and what the browser needs to run the delivery checks: the
	 * probe it sends, and the severity counts without the rows it replaces.
	 *
	 * @param array $checks Checks on the page.
	 * @return string
	 */
	private function get_delivery_controls_html( $checks ) {
		$mode = $this->facts !== null && $this->facts['counter_mode']['available'] ? $this->facts['counter_mode']['value'] : 'php';
		$probe = $mode === 'php' ? null : $this->delivery->get_probe( $mode );
		$display = $this->delivery->get_display_probe();

		if ( $probe === null && $display === null )
			return '';

		$base = $this->count_severities( array_filter( $checks, static function( $check ) {
			return ! in_array( $check['id'], self::DELIVERY_IDS, true );
		} ) );
		$fields = array_flip( [ 'url', 'method', 'body', 'status', 'code', 'shape' ] );
		$browser = $probe === null ? null : array_intersect_key( $probe, $fields );
		$browser_display = $display === null ? null : array_intersect_key( $display, $fields );

		// a settings row without a label, so the button lines up with the findings
		$html = '<table class="form-table pvc-status-delivery-controls" role="presentation"><tbody><tr><th scope="row"><span class="screen-reader-text">' . esc_html__( 'Delivery checks', 'post-views-counter' ) . '</span></th><td>';
		$html .= '<div class="pvc-status-delivery" data-probe="' . esc_attr( wp_json_encode( $browser ) ) . '" data-display-probe="' . esc_attr( wp_json_encode( $browser_display ) ) . '" data-ajax-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-action="' . esc_attr( Post_Views_Counter_Status_Delivery::ACTION ) . '" data-nonce="' . esc_attr( wp_create_nonce( Post_Views_Counter_Status_Delivery::ACTION ) ) . '" data-counts="' . esc_attr( wp_json_encode( $base ) ) . '" data-running="' . esc_attr__( 'Running the delivery checks.', 'post-views-counter' ) . '" data-failed="' . esc_attr__( 'The delivery checks could not run. Reload the page and try again.', 'post-views-counter' ) . '">';
		$html .= '<p><button type="button" class="button pvc-status-delivery-run">' . esc_html__( 'Run delivery checks', 'post-views-counter' ) . '</button> <span class="pvc-status-delivery-notice" aria-live="polite"></span><span class="spinner"></span></p>';
		$sends = [];

		if ( $probe !== null )
			$sends[] = __( 'Tests the counting request from your browser and from your server.', 'post-views-counter' );

		if ( $probe !== null && $probe['marker'] !== '' && in_array( $mode, Post_Views_Counter_Status_Delivery::SCAN_MODES, true ) )
			$sends[] = __( 'Opens one published post as a visitor would.', 'post-views-counter' );

		if ( $display !== null )
			$sends[] = __( 'Tests the request that shows view counts on cached pages, from your browser and from your server.', 'post-views-counter' );

		$sends[] = __( 'Nothing is counted or saved.', 'post-views-counter' );
		$html .= '<p class="description">' . esc_html( implode( ' ', $sends ) ) . '</p>';
		$html .= '</div></td></tr></tbody></table>';

		return $html;
	}

	/**
	 * The page's updates after a delivery run: the three rows, the verdict
	 * recounted from the other checks' counts and the report lines.
	 *
	 * @param array        $delivery Run: mode, probe, browser, server, scan, headers, checked_at.
	 * @param array|string $base Severity counts of the other checks, as the page sent them.
	 * @return array
	 */
	public function render_delivery_results( $delivery, $base ) {
		$base = is_string( $base ) ? json_decode( $base, true ) : $base;
		$counts = array_fill_keys( self::SEVERITIES, 0 );

		foreach ( self::SEVERITIES as $severity ) {
			if ( is_array( $base ) && isset( $base[ $severity ] ) && is_numeric( $base[ $severity ] ) )
				$counts[ $severity ] = max( 0, min( 1000, (int) $base[ $severity ] ) );
		}

		$this->checked_at = (int) $delivery['checked_at'];
		$probe = $delivery['probe'];
		$checks = [];

		// PHP mode shows no counting probe rows, only the display probe's
		if ( $delivery['mode'] !== 'php' ) {
			$checks[] = $this->get_probe_check( 'browser', $probe, $probe === null ? null : $delivery['browser'], $delivery['server']['result'], $this->checked_at );
			$checks[] = $this->get_probe_check( 'server', $probe, $probe === null ? null : $delivery['server'], $delivery['browser']['result'], $this->checked_at );
			$checks[] = $this->get_page_scan_check( $delivery['mode'], $probe, $delivery['scan'], $this->checked_at );
		}

		if ( ! empty( $delivery['display'] ) ) {
			$display = $delivery['display'];
			$checks[] = $this->get_probe_check( 'browser', $display['probe'], $display['browser'], $display['server']['result'], $this->checked_at, 'pvc_display_browser' );
			$checks[] = $this->get_probe_check( 'server', $display['probe'], $display['server'], $display['browser']['result'], $this->checked_at, 'pvc_display_server' );
		}
		$rows = [];

		foreach ( $checks as $check ) {
			$counts[ $check['severity'] ]++;
			$rows[ $check['id'] ] = $this->get_check_html( $check );
		}

		$report = new Post_Views_Counter_Status_Report( [], $checks, $this->checked_at );

		return [
			'rows'		=> $rows,
			'verdict'	=> $this->get_verdict_html( $counts ),
			'report'	=> $report->get_delivery_text( $delivery ),
			'notice'	=> __( 'The delivery checks finished.', 'post-views-counter' )
		];
	}

	/**
	 * The plugin's scheduled events: expected, scheduled and not overdue.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_scheduled_events_check( $facts ) {
		$fact = $facts['scheduled_events'];
		$value = $fact['value'];
		$labels = [
			'pvc_reset_counts'					=> __( 'the cleanup of old Views', 'post-views-counter' ),
			Post_Views_Counter_Visits::CRON_HOOK	=> __( 'the Visits database update', 'post-views-counter' ),
			'pvc_weekly_content_summary_send'	=> __( 'the weekly email summary', 'post-views-counter' )
		];
		$scope = __( 'Whether the tasks finished successfully is not checked.', 'post-views-counter' );
		$problems = [];
		$unknown = [];

		foreach ( $value['events'] as $hook => $event ) {
			$label = isset( $labels[ $hook ] ) ? $labels[ $hook ] : $hook;

			if ( $event['status'] === 'overdue' )
				/* translators: 1: task, 2: time span */
				$problems[] = sprintf( __( 'A plugin task is late by %2$s: %1$s. Check that WordPress runs its scheduled tasks (WP-Cron) on this site.', 'post-views-counter' ), $label, human_time_diff( $event['next'], $fact['observed_at'] ) );
			elseif ( $event['status'] === 'missing' )
				/* translators: %s: task */
				$problems[] = sprintf( __( 'A task the plugin needs is not scheduled: %s. Check that WordPress runs its scheduled tasks (WP-Cron) on this site.', 'post-views-counter' ), $label );
			elseif ( $event['status'] === 'unknown' )
				/* translators: %s: task */
				$unknown[] = sprintf( __( 'Whether %s is expected could not be checked: the plugin state could not be read.', 'post-views-counter' ), $label );
		}

		if ( ! empty( $problems ) )
			return $this->check( 'pvc_scheduled_events', 'scheduled_tasks', 'warning', array_shift( $problems ), array_merge( $problems, $unknown ), $scope, $fact['observed_at'] );

		if ( ! empty( $unknown ) )
			return $this->check( 'pvc_scheduled_events', 'scheduled_tasks', 'unknown', array_shift( $unknown ), $unknown, $scope, $fact['observed_at'] );

		if ( $value['cron_disabled'] )
			return $this->check( 'pvc_scheduled_events', 'scheduled_tasks', 'good', __( 'WordPress\'s built-in task runner (WP-Cron) is turned off, so a server task must run scheduled tasks. None of the plugin\'s tasks is more than an hour late.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		return $this->check( 'pvc_scheduled_events', 'scheduled_tasks', 'good', __( 'The plugin\'s scheduled tasks are on time: none is more than an hour late.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );
	}

	/**
	 * Views queued in the object cache before database saving.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_buffering_check( $facts ) {
		$buffering = $facts['buffering'];
		$scope = __( 'Read from the Counting settings and the site\'s object cache.', 'post-views-counter' );

		if ( ! $buffering['value']['in_effect'] )
			return $this->check( 'pvc_buffering', 'scheduled_tasks', 'not_applicable', __( 'Views are saved to the database straight away.', 'post-views-counter' ), [], $scope, $buffering['observed_at'] );

		return $this->check( 'pvc_buffering', 'scheduled_tasks', 'good', __( 'Views are collected in the object cache and saved to the database later.', 'post-views-counter' ), [], $scope . ' ' . __( 'This does not show that the collected Views will be saved.', 'post-views-counter' ), $buffering['observed_at'] );
	}

	/**
	 * The queue this plugin keeps in the object cache: keys waiting and the
	 * drain lock. None while buffering is off, or while another writer keeps
	 * its own queue, which that writer's checks describe.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array|null
	 */
	private function get_core_queue_check( $facts ) {
		$queue = $facts['core_queue'];

		if ( ! $facts['buffering']['value']['in_effect'] || $queue['reason'] === 'extension_writer' || $queue['reason'] === 'not_applicable' )
			return null;

		$scope = __( 'Reads the list of waiting Views in the object cache and its save lock. It does not show that they will be saved.', 'post-views-counter' );

		if ( ! $queue['available'] || ! $queue['value']['lock_read_ok'] )
			return $this->check( 'pvc_core_queue', 'scheduled_tasks', 'unknown', __( 'The Views waiting in the object cache could not be checked.', 'post-views-counter' ), [], $scope, $queue['observed_at'] );

		$lock = $queue['value']['lock_expires_at'];
		$held = $lock !== null && $lock > $queue['observed_at'];
		$detail = [];

		// a save takes over an expired lock, so only a live one holds queued Views back
		if ( $held )
			/* translators: %s: date and time */
			$detail[] = sprintf( __( 'A save in progress holds the save lock until %s.', 'post-views-counter' ), $this->format_time( $lock ) );
		elseif ( $lock !== null )
			/* translators: %s: date and time */
			$detail[] = sprintf( __( 'An old save lock expired at %s; the next save can take it over.', 'post-views-counter' ), $this->format_time( $lock ) );

		if ( $queue['reason'] === 'key_missing' || $queue['value']['keys'] === null )
			return $this->check( 'pvc_core_queue', 'scheduled_tasks', 'unknown', __( 'The list of waiting Views is missing from the object cache, so whether any are waiting is unknown.', 'post-views-counter' ), $detail, $scope, $queue['observed_at'] );

		$keys = (int) $queue['value']['keys'];

		if ( $keys > 0 && $held )
			/* translators: 1: number of entries, 2: date and time */
			return $this->check( 'pvc_core_queue', 'scheduled_tasks', 'warning', sprintf( _n( '%1$d entry of waiting Views stays while a save holds the save lock until %2$s.', '%1$d entries of waiting Views stay while a save holds the save lock until %2$s.', $keys, 'post-views-counter' ), $keys, $this->format_time( $lock ) ), [ __( 'A save in progress holds the lock briefly; a lock that stays held delays saving.', 'post-views-counter' ) ], $scope, $queue['observed_at'] );

		if ( $keys > 0 )
			/* translators: %d: number of entries */
			return $this->check( 'pvc_core_queue', 'scheduled_tasks', 'good', sprintf( _n( '%d entry of Views is waiting for the next save.', '%d entries of Views are waiting for the next save.', $keys, 'post-views-counter' ), $keys ), $detail, $scope, $queue['observed_at'] );

		return $this->check( 'pvc_core_queue', 'scheduled_tasks', 'good', __( 'The object cache holds no Views to save.', 'post-views-counter' ), $detail, $scope, $queue['observed_at'] );
	}

	/**
	 * Whether stored Views can be read.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_views_access_check( $facts ) {
		$fact = $facts['views_access'];
		$scope = __( 'Read from the plugin\'s counting state.', 'post-views-counter' );

		if ( $fact['reason'] === 'state_read_failed' )
			return $this->check( 'pvc_views_access', 'data_storage', 'unknown', __( 'Whether stored Views can be read could not be checked: the plugin\'s counting state could not be read.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		if ( $fact['value'] === false )
			return $this->check( 'pvc_views_access', 'data_storage', 'critical', __( 'Views access and counting are blocked by a marker from deactivation with data removal enabled on this site. The marker alone does not show whether stored data remains. Contact support with the support report before changing plugins.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		return $this->check( 'pvc_views_access', 'data_storage', 'good', __( 'Stored Views can be read.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );
	}

	/**
	 * Whether a view is eligible to be written.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_write_check( $facts ) {
		$fact = $facts['write_eligibility'];
		$kind = $facts['state_row']['available'] ? $facts['state_row']['value']['kind'] : 'unreadable';
		$scope = __( 'Uses the same test as the counter. It does not save a view.', 'post-views-counter' );

		if ( $fact['value'] === true && $fact['reason'] === 'views_only' )
			return $this->check( 'pvc_write_eligibility', 'data_storage', 'warning', __( 'New Views can still be saved while Visits wait for the counting state to be repaired. This does not confirm that a view was saved.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		if ( $fact['value'] === true )
			return $this->check( 'pvc_write_eligibility', 'data_storage', 'good', __( 'The counting state allows new Views to be saved. This does not confirm that a view was saved; Counting activity shows whether any were.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		switch ( $fact['reason'] ) {
			case 'state_read_failed':
				$title = __( 'Views are not being saved: the plugin could not read its counting state, so it cannot confirm that saving is safe. Stored Views are kept.', 'post-views-counter' );
				break;

			case 'reset_in_progress':
				$title = __( 'Views are not being saved: a statistics reset is in progress or did not finish. Contact support with the support report; do not start another reset.', 'post-views-counter' );
				break;

			case 'core_deactivated':
				$title = __( 'Views are not being saved: a marker from deactivation with data removal enabled blocks counting on this site. Contact support with the support report before changing plugins.', 'post-views-counter' );
				break;

			case 'state_missing':
				$title = __( 'Views are not being saved: the plugin\'s counting state is missing on this site. Stored Views are kept. Contact support with the support report.', 'post-views-counter' );
				break;

			default:
				$title = $kind === 'future' ? __( 'Views are not being saved: the counting state was written by a newer version of the plugin than the one running. Stored Views are kept.', 'post-views-counter' ) : __( 'Views are not being saved: the counting state could not be verified. Stored Views are kept. Contact support with the support report.', 'post-views-counter' );
		}

		return $this->check( 'pvc_write_eligibility', 'data_storage', 'critical', $title, [], $scope, $fact['observed_at'] );
	}

	/**
	 * Visits read and write availability, with the stored state's codes.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_visits_check( $facts ) {
		$fact = $facts['visits'];
		$scope = __( 'Read from the plugin\'s counting state. It does not record a visit.', 'post-views-counter' );
		$detail = $this->get_state_detail( $facts );

		if ( ! $fact['available'] )
			return $this->check( 'pvc_visits', 'data_storage', 'unknown', __( 'Whether Visits can be read and recorded could not be checked: the plugin\'s counting state could not be read.', 'post-views-counter' ), $detail, $scope, $fact['observed_at'] );

		$visits = $fact['value'];
		$row = $facts['state_row']['value'];

		// behind the repair fence Views keep counting while Visits wait
		if ( $row['kind'] === 'repair' && $row['reason'] !== 'reset_in_progress' )
			return $this->check( 'pvc_visits', 'data_storage', 'warning', __( 'New Visits are paused while the plugin\'s counting state awaits recovery. Views keep counting.', 'post-views-counter' ), $detail, $scope, $fact['observed_at'] );

		if ( ! $visits['readable'] )
			/* translators: %s: reason */
			return $this->check( 'pvc_visits', 'data_storage', 'warning', sprintf( __( 'Stored Visits cannot be read: %s. This does not mean stored Views have been lost.', 'post-views-counter' ), $this->get_reason_label( $visits['read_reason'] ) ), $detail, $scope, $fact['observed_at'] );

		if ( ! $visits['writable'] )
			/* translators: %s: reason */
			return $this->check( 'pvc_visits', 'data_storage', $visits['write_reason'] === 'interval_zero' ? 'recommended' : 'warning', sprintf( __( 'New Visits are paused: %s. Views are checked in their own rows.', 'post-views-counter' ), $this->get_reason_label( $visits['write_reason'] ) ), $visits['write_reason'] === 'interval_zero' ? [] : $detail, $scope, $fact['observed_at'] );

		// the stored state's codes are for support; the report carries them
		return $this->check( 'pvc_visits', 'data_storage', 'good', __( 'Visits can be read and recorded.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );
	}

	/**
	 * Detail lines for the stored Visits state: codes, times and counts only.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_state_detail( $facts ) {
		$detail = [];
		$row = $facts['state_row'];

		if ( $row['available'] ) {
			$state = $row['value'];

			/* translators: 1: stored state kind, 2: format version */
			$detail[] = sprintf( __( 'Stored state: %1$s, format %2$s.', 'post-views-counter' ), $state['kind'], $state['format_version'] === null ? __( 'unknown', 'post-views-counter' ) : (string) $state['format_version'] );

			if ( $state['state'] !== null )
				/* translators: 1: state, 2: reason code */
				$detail[] = sprintf( __( 'State: %1$s (%2$s).', 'post-views-counter' ), $state['state'], $state['reason'] );

			if ( $state['schema_state'] !== null )
				/* translators: 1: schema state, 2: schema status code */
				$detail[] = sprintf( __( 'Schema: %1$s (%2$s).', 'post-views-counter' ), $state['schema_state'], $state['schema_last_status'] );
		}

		$lease = $facts['schema_lease'];

		if ( $lease['available'] && $lease['value']['present'] )
			/* translators: %s: date and time, or unknown */
			$detail[] = sprintf( __( 'A database update holds its lock until %s.', 'post-views-counter' ), $lease['value']['expires_at'] === null ? __( 'unknown', 'post-views-counter' ) : $this->format_time( $lease['value']['expires_at'] ) );

		return $detail;
	}

	/**
	 * Derived metrics, such as Views per visit, for the Counting activity
	 * window, and where they start.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_derived_check( $facts ) {
		$fact = $facts['derived'];
		$scope = __( 'Read from the plugin\'s counting state.', 'post-views-counter' );

		if ( ! $fact['available'] )
			return $this->check( 'pvc_derived', 'data_storage', 'unknown', __( 'Whether Views per visit and Entrances are available could not be checked: the plugin\'s counting state could not be read.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		$derived = $fact['value'];

		switch ( $derived['derived_reason'] ) {
			case 'ready':
				return $this->check( 'pvc_derived', 'data_storage', 'good', __( 'Views per visit and Entrances are available for the past 14 days.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

			case 'before_derived_since':
				// the range is judged as a whole: a boundary inside it makes all of it unavailable
				if ( $derived['derived_since'] === null )
					return $this->check( 'pvc_derived', 'data_storage', 'recommended', __( 'Views per visit and Entrances are unavailable for the past 14 days. Stored Views and Visits are checked in their own rows.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

				/* translators: %s: date and time */
				return $this->check( 'pvc_derived', 'data_storage', 'recommended', sprintf( __( 'Views per visit and Entrances are unavailable for the past 14 days: they start at %s, inside this range. Stored Views and Visits are checked in their own rows.', 'post-views-counter' ), $this->format_time( $derived['derived_since'] ) ), [], $scope, $fact['observed_at'] );

			case 'derived_not_ready':
				return $this->check( 'pvc_derived', 'data_storage', 'recommended', __( 'Views per visit and Entrances are not available yet; they start once Visits are recorded.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

			case 'interval_zero':
				return $this->check( 'pvc_derived', 'data_storage', 'not_applicable', __( 'Views per visit and Entrances are off while the Count Interval is below one hour.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );
		}

		/* translators: %s: reason */
		return $this->check( 'pvc_derived', 'data_storage', 'warning', sprintf( __( 'Views per visit and Entrances are unavailable: %s. Views are checked in their own rows.', 'post-views-counter' ), $this->get_reason_label( $derived['derived_reason'] ) ), [], $scope, $fact['observed_at'] );
	}

	/**
	 * Views today and in the past 14 days, and the last day with Views.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_activity_check( $facts ) {
		$fact = $facts['counting_activity'];
		$utc = $facts['counting_clock']['value']['count_time'] !== 'local';
		$zone = $utc ? 'UTC' : $facts['counting_clock']['value']['site_timezone'];
		/* translators: %s: time zone */
		$scope = sprintf( __( 'Days are counted in %s; today is still in progress.', 'post-views-counter' ), $zone );

		if ( ! $fact['available'] ) {
			$reasons = [
				'query_failed'		=> __( 'the Views query failed', 'post-views-counter' ),
				'read_unusable'		=> __( 'the Views read gave no usable series', 'post-views-counter' ),
				'core_deactivated'	=> __( 'Views access is blocked', 'post-views-counter' )
			];

			/* translators: %s: reason */
			return $this->check( 'pvc_counting_activity', 'data_storage', 'unknown', sprintf( __( 'Counting activity is unavailable: %s. This is not the same as zero Views.', 'post-views-counter' ), isset( $reasons[ $fact['reason'] ] ) ? $reasons[ $fact['reason'] ] : $this->get_reason_label( $fact['reason'] ) ), [], $scope, $fact['observed_at'] );
		}

		if ( $fact['value']['last_day'] === null )
			return $this->check( 'pvc_counting_activity', 'data_storage', 'recommended', __( 'No Views in the past 14 days. A quiet site can have none. To test, open a counted post as a visitor who is not excluded, then check again.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		$days = $fact['value']['days'];
		$today = isset( $days[ $fact['value']['today'] ] ) ? (int) $days[ $fact['value']['today'] ] : 0;
		/* translators: 1: Views today, 2: Views in the past 14 days */
		$title = sprintf( __( 'Views today so far: %1$s. Views in the past 14 days, including today: %2$s.', 'post-views-counter' ), number_format_i18n( $today ), number_format_i18n( array_sum( $days ) ) );

		// without Views today, the last day with Views shows when counting was last seen
		if ( $today === 0 )
			/* translators: %s: date */
			$title .= ' ' . sprintf( __( 'Last day with Views: %s.', 'post-views-counter' ), $this->format_day( $fact['value']['last_day'] ) );

		return $this->check( 'pvc_counting_activity', 'data_storage', 'good', $title, [], $scope, $fact['observed_at'] );
	}

	/**
	 * Whether the plugin's tables exist.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_tables_check( $facts ) {
		$fact = $facts['tables'];
		$scope = __( 'Checks that each table exists, not what it holds.', 'post-views-counter' );

		if ( ! $fact['available'] )
			return $this->check( 'pvc_tables', 'data_storage', 'unknown', __( 'The plugin tables could not be checked.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );

		$detail = [];
		$missing = [];
		$unknown = 0;

		foreach ( $fact['value'] as $table ) {
			if ( $table['exists'] === null ) {
				$unknown++;
				/* translators: %s: table label */
				$detail[] = sprintf( __( '%s: could not be checked', 'post-views-counter' ), $table['label'] );
			} elseif ( $table['exists'] ) {
				/* translators: %s: table label */
				$detail[] = sprintf( __( '%s: found', 'post-views-counter' ), $table['label'] );
			} else {
				$missing[] = $table['name'];
				/* translators: %s: table label */
				$detail[] = sprintf( __( '%s: missing', 'post-views-counter' ), $table['label'] );
			}
		}

		// the Views table holds every count; another missing table limits its feature
		if ( in_array( 'post_views', $missing, true ) )
			return $this->check( 'pvc_tables', 'data_storage', 'critical', __( 'The Views table is missing, so views cannot be saved.', 'post-views-counter' ), $detail, $scope, $fact['observed_at'] );

		if ( ! empty( $missing ) )
			return $this->check( 'pvc_tables', 'data_storage', 'warning', __( 'A plugin table is missing; the features that use it cannot save data.', 'post-views-counter' ), $detail, $scope, $fact['observed_at'] );

		if ( $unknown > 0 )
			return $this->check( 'pvc_tables', 'data_storage', 'unknown', __( 'Some plugin tables could not be checked.', 'post-views-counter' ), $detail, $scope, $fact['observed_at'] );

		// each table is listed only when one needs attention
		return $this->check( 'pvc_tables', 'data_storage', 'good', __( 'The plugin tables exist.', 'post-views-counter' ), [], $scope, $fact['observed_at'] );
	}

	/**
	 * Plugin, WordPress, PHP and database versions, and the site scope.
	 *
	 * @param array $facts Facts keyed by id.
	 * @return array
	 */
	private function get_versions_check( $facts ) {
		$versions = $facts['versions']['value'];
		$scopes = [
			'single'	=> __( 'single site', 'post-views-counter' ),
			'main_site'	=> __( 'multisite main site', 'post-views-counter' ),
			'subsite'	=> __( 'multisite subsite', 'post-views-counter' )
		];
		/* translators: %s: version */
		$detail = [ sprintf( __( 'Post Views Counter: %s', 'post-views-counter' ), $versions['core'] ) ];

		// an extension's version is shown only while it is active
		if ( $versions['pro'] !== null )
			/* translators: %s: version */
			$detail[] = sprintf( __( 'Post Views Counter Pro: %s', 'post-views-counter' ), $versions['pro'] );

		/* translators: %s: version */
		$detail[] = sprintf( __( 'WordPress: %s', 'post-views-counter' ), $versions['wordpress'] );
		/* translators: %s: version */
		$detail[] = sprintf( __( 'PHP: %s', 'post-views-counter' ), $versions['php'] );

		if ( $versions['database'] !== '' )
			/* translators: %s: database server version */
			$detail[] = sprintf( __( 'Database server: %s', 'post-views-counter' ), $versions['database'] );

		/* translators: %s: site scope */
		$detail[] = sprintf( __( 'Site: %s', 'post-views-counter' ), $scopes[ $facts['site_scope']['value'] ] );

		return $this->check( 'pvc_versions', 'environment', 'good', __( 'Versions and site type.', 'post-views-counter' ), $detail, __( 'Read from the running code and the database server.', 'post-views-counter' ), $facts['versions']['observed_at'] );
	}

	/**
	 * Run the pvc_status_checks filter once and keep the checks it returns
	 * that pass validation.
	 *
	 * @param array $core_ids Core check IDs.
	 * @param int   $now Collection time.
	 * @return array
	 */
	private function get_extension_checks( $core_ids, $now ) {
		/**
		 * Filter the checks an extension adds to the Status tab.
		 *
		 * Callbacks append checks to the list and return it. The list starts
		 * empty: Core's checks and facts are not passed, and an extension
		 * cannot replace, remove or change them. The filter runs at most once
		 * per authorized PVC settings page request, after the settings
		 * capability check, and never on other admin pages, frontend, counting,
		 * AJAX, REST or cron requests; asking for the checks again from a
		 * callback gives none. Callbacks must do bounded passive reads only:
		 * no network requests, writes, repairs, flushes, counts or
		 * rescheduling. Callbacks are trusted code; Core validates what they
		 * return, not what they do.
		 *
		 * Each check is an array with these fields; unknown fields are ignored,
		 * and a field of the wrong type rejects the check unless noted:
		 * - id (string, required): matches [a-z][a-z0-9_-]{0,63}, with an
		 *   extension-owned prefix; the pvc_ prefix is reserved, and a
		 *   duplicate of an accepted id is dropped.
		 * - group (string): fast_ajax, dynamic_loading, buffering,
		 *   caching_integration, counting_rules, license or other_checks; any
		 *   other string becomes other_checks.
		 * - severity (string): critical, warning, recommended, good,
		 *   not_tested, unknown or not_applicable; any other string becomes
		 *   unknown.
		 * - title (string, required): plain text, cut to 160 characters.
		 * - detail (string or list of strings): plain text, at most eight
		 *   strings, each cut to 500 characters.
		 * - scope (string): plain text, cut to 500 characters: what was tested,
		 *   from where, and what stays unverified.
		 * - observed_at (int or null): Unix time of the observation, after 0
		 *   and not after the checks are collected; anything else becomes null.
		 *   Core shows the time and its age as given and applies no expiry: an
		 *   extension reporting a cached observation decides when it is too
		 *   old and reports it as unknown.
		 * - action ({ label, url }): a link to a tab of this settings page in
		 *   the current menu position, exactly as Core builds it, with an
		 *   optional fragment of [A-Za-z0-9_-]{1,80}; anything else removes
		 *   the action and keeps the check.
		 * - report (map): support report fields, key => value. Only the keys
		 *   Post_Views_Counter_Status_Report::get_extension_schema() approves
		 *   are written, each only when its value has the declared type and
		 *   domain; a key already written keeps its first value. Anything else
		 *   is left out of the report and the check still renders.
		 *
		 * A critical, warning, recommended or good check without a scope and an
		 * observation time becomes unknown. Text is shown as plain text; no
		 * HTML is rendered. At most 64 checks are accepted; a non-array return
		 * adds none. Accepted checks are collected after Core's, by group in the
		 * order above, problems first, keeping callback order otherwise. The
		 * report keeps that order; display sections combine related groups,
		 * problems first across each section. All checks count in the verdict.
		 * Detect this contract with
		 * Post_Views_Counter_Settings_Status::CHECKS_API_VERSION.
		 *
		 * @since 1.8.2
		 *
		 * @param array $checks Extension checks, initially empty.
		 */
		$returned = apply_filters( 'pvc_status_checks', [] );

		// an observation made while the callbacks ran is not in the future
		$now = max( $now, time() );

		if ( ! is_array( $returned ) )
			return [];

		$accepted = [];
		$seen = array_fill_keys( $core_ids, true );

		foreach ( $returned as $check ) {
			if ( count( $accepted ) >= self::MAX_EXTENSION_CHECKS )
				break;

			$check = $this->validate_extension_check( $check, $now );

			if ( $check === null || isset( $seen[ $check['id'] ] ) )
				continue;

			$seen[ $check['id'] ] = true;
			$accepted[] = $check;
		}

		return $accepted;
	}

	/**
	 * Validate one extension check into a fresh array.
	 *
	 * @param mixed $check Returned check.
	 * @param int   $now Collection time.
	 * @return array|null Null when the check is rejected.
	 */
	private function validate_extension_check( $check, $now ) {
		if ( ! is_array( $check ) )
			return null;

		// only the documented fields are read
		$check = array_intersect_key( $check, array_flip( self::EXTENSION_FIELDS ) );

		if ( ! isset( $check['id'] ) || ! is_string( $check['id'] ) || preg_match( '/^[a-z][a-z0-9_-]{0,63}\z/', $check['id'] ) !== 1 || strpos( $check['id'], 'pvc_' ) === 0 )
			return null;

		$title = array_key_exists( 'title', $check ) ? $this->plain_text( $check['title'], 160 ) : null;

		if ( $title === null || $title === '' )
			return null;

		foreach ( [ 'group', 'severity' ] as $field ) {
			if ( array_key_exists( $field, $check ) && ! is_string( $check[ $field ] ) )
				return null;
		}

		$detail = [];

		if ( array_key_exists( 'detail', $check ) ) {
			$lines = is_string( $check['detail'] ) ? [ $check['detail'] ] : $check['detail'];

			if ( ! is_array( $lines ) || count( $lines ) > 8 )
				return null;

			foreach ( $lines as $line ) {
				$line = $this->plain_text( $line, 500 );

				if ( $line === null )
					return null;

				if ( $line !== '' )
					$detail[] = $line;
			}
		}

		$scope = '';

		if ( array_key_exists( 'scope', $check ) ) {
			$scope = $this->plain_text( $check['scope'], 500 );

			if ( $scope === null )
				return null;
		}

		$group = isset( $check['group'] ) && in_array( $check['group'], self::EXTENSION_GROUPS, true ) ? $check['group'] : 'other_checks';
		$severity = isset( $check['severity'] ) && in_array( $check['severity'], self::SEVERITIES, true ) ? $check['severity'] : 'unknown';
		$observed_at = isset( $check['observed_at'] ) && is_int( $check['observed_at'] ) && $check['observed_at'] > 0 && $check['observed_at'] <= $now ? $check['observed_at'] : null;

		// a finding needs its evidence
		if ( in_array( $severity, self::EVIDENCE_SEVERITIES, true ) && ( $scope === '' || $observed_at === null ) )
			$severity = 'unknown';

		return [
			'id'			=> $check['id'],
			'group'			=> $group,
			'severity'		=> $severity,
			'title'			=> $title,
			'detail'		=> $detail,
			'scope'			=> $scope,
			'observed_at'	=> $observed_at,
			'action'		=> isset( $check['action'] ) ? $this->validate_action( $check['action'] ) : null,
			'report'		=> isset( $check['report'] ) ? $this->validate_report_map( $check['report'] ) : [],
			'source'		=> 'extension'
		];
	}

	/**
	 * An extension's report map, kept as scalars and lists of scalars under
	 * string keys; the report types and approves each entry.
	 *
	 * @param mixed $report Returned map.
	 * @return array
	 */
	private function validate_report_map( $report ) {
		if ( ! is_array( $report ) )
			return [];

		$map = [];

		foreach ( array_slice( $report, 0, Post_Views_Counter_Status_Report::MAX_EXTENSION_FIELDS, true ) as $key => $value ) {
			if ( ! is_string( $key ) )
				continue;

			if ( is_scalar( $value ) || ( is_array( $value ) && count( $value ) === count( array_filter( $value, 'is_scalar' ) ) ) )
				$map[ $key ] = $value;
		}

		return $map;
	}

	/**
	 * Plain text from a string: tags stripped, whitespace collapsed, cut to
	 * a length.
	 *
	 * @param mixed $value Value.
	 * @param int   $length Most characters.
	 * @return string|null Null when the value is not a string.
	 */
	private function plain_text( $value, $length ) {
		if ( ! is_string( $value ) )
			return null;

		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}

	/**
	 * Accept an action only as a link to a tab of this settings page, in the
	 * current menu position, with an optional fragment.
	 *
	 * @param mixed $action Action.
	 * @return array|null
	 */
	private function validate_action( $action ) {
		if ( ! is_array( $action ) || ! isset( $action['label'], $action['url'] ) || ! is_string( $action['url'] ) )
			return null;

		$label = $this->plain_text( $action['label'], 160 );

		if ( $label === null || $label === '' )
			return null;

		$parts = explode( '#', $action['url'], 2 );

		if ( isset( $parts[1] ) && preg_match( '/^[A-Za-z0-9_-]{1,80}\z/', $parts[1] ) !== 1 )
			return null;

		if ( ! in_array( $parts[0], $this->get_tab_urls(), true ) )
			return null;

		return [ 'label' => $label, 'url' => $action['url'] ];
	}

	/**
	 * Canonical URLs of this settings page's tabs in the current menu
	 * position.
	 *
	 * @return array
	 */
	private function get_tab_urls() {
		$pages = isset( $this->pvc->settings_api ) ? $this->pvc->settings_api->get_pages() : [];
		$tabs = isset( $pages['post-views-counter']['tabs'] ) && is_array( $pages['post-views-counter']['tabs'] ) ? $pages['post-views-counter']['tabs'] : [];
		$urls = [];

		foreach ( $tabs as $key => $tab ) {
			if ( empty( $tab['disabled'] ) )
				$urls[] = $this->get_tab_url( $key );
		}

		return $urls;
	}

	/**
	 * The support report: a read-only preview with Copy and Download, which
	 * work in the browser without a request.
	 *
	 * @param Post_Views_Counter_Status_Report $report Report.
	 * @return string
	 */
	private function get_report_html( $report ) {
		$intro = __( 'When you ask for help, paste this report into your support request. It shows how counting is set up on this site and what the checks on this page found, so support can start without asking you for these details.', 'post-views-counter' );

		// a run adds its results to the report
		if ( $this->has_delivery_checks() )
			$intro .= ' ' . __( 'Run the delivery checks first to include their results.', 'post-views-counter' );

		$html = '<div class="pvc-status-report">';
		$html .= '<h2>' . esc_html__( 'Support report', 'post-views-counter' ) . '</h2>';
		$html .= '<table class="form-table" role="presentation"><tbody><tr>';
		$html .= '<th scope="row"><label for="pvc-status-report-text" class="screen-reader-text">' . esc_html__( 'Support report', 'post-views-counter' ) . '</label></th>';
		$html .= '<td><p class="pvc-status-report-intro">' . esc_html( $intro ) . '</p>';
		$html .= '<textarea id="pvc-status-report-text" class="large-text code pvc-status-report-text" rows="12" readonly>' . esc_textarea( $report->get_text() ) . '</textarea>';
		$html .= '<p class="pvc-status-report-actions">';
		$html .= '<button type="button" class="button pvc-status-report-copy" data-copied="' . esc_attr__( 'Copied.', 'post-views-counter' ) . '" data-failed="' . esc_attr__( 'Copying failed. Select the text and copy it.', 'post-views-counter' ) . '">' . esc_html__( 'Copy', 'post-views-counter' ) . '</button> ';
		$html .= '<button type="button" class="button pvc-status-report-download" data-filename="pvc-support-report-' . esc_attr( gmdate( 'Y-m-d', $this->checked_at ) ) . '.txt">' . esc_html__( 'Download', 'post-views-counter' ) . '</button> ';
		$html .= '<span class="pvc-status-report-notice" aria-live="polite"></span>';
		$html .= '</p>';
		$html .= '<p class="description">' . esc_html__( 'It contains no license key, IP address, web address, file path, user name, email address or error message. Copy and Download do not send this report anywhere; they work in your browser.', 'post-views-counter' ) . '</p>';
		$html .= '</td></tr></tbody></table></div>';

		return $html;
	}

	/**
	 * Whether the page offers the delivery checks: a counting probe outside
	 * PHP mode, or a display probe.
	 *
	 * @return bool
	 */
	private function has_delivery_checks() {
		$mode = $this->facts !== null && $this->facts['counter_mode']['available'] ? $this->facts['counter_mode']['value'] : 'php';

		return ( $mode !== 'php' && $this->delivery->get_probe( $mode ) !== null ) || $this->delivery->get_display_probe() !== null;
	}

	/**
	 * The URL of one settings tab in the current menu position.
	 *
	 * @param string $tab Tab key.
	 * @return string
	 */
	public function get_tab_url( $tab ) {
		$path = $this->pvc->get_menu_position() === 'sub' ? 'options-general.php' : 'admin.php';

		return admin_url( $path . '?page=post-views-counter&tab=' . $tab );
	}

	/**
	 * Order checks: Core groups, then extension groups, each problems first,
	 * keeping the given order within a severity.
	 *
	 * @param array $checks Checks.
	 * @return array
	 */
	private function order_checks( $checks ) {
		$groups = array_flip( self::GROUP_ORDER );
		$severities = array_flip( self::SEVERITIES );

		foreach ( $checks as $position => &$check ) {
			$check['position'] = $position;
		}

		unset( $check );

		usort( $checks, static function( $a, $b ) use ( $groups, $severities ) {
			return [ $groups[ $a['group'] ], $severities[ $a['severity'] ], $a['position'] ] <=> [ $groups[ $b['group'] ], $severities[ $b['severity'] ], $b['position'] ];
		} );

		foreach ( $checks as &$check ) {
			unset( $check['position'] );
		}

		unset( $check );

		return $checks;
	}

	/**
	 * Count checks by severity.
	 *
	 * @param array $checks Checks.
	 * @return array Counts keyed by severity.
	 */
	public function count_severities( $checks ) {
		$counts = array_fill_keys( self::SEVERITIES, 0 );

		foreach ( $checks as $check ) {
			$counts[ $check['severity'] ]++;
		}

		return $counts;
	}

	/**
	 * The verdict banner: counts by severity, never a score, when it was
	 * checked, and what stays unchecked.
	 *
	 * @param array $counts Counts keyed by severity.
	 * @return string
	 */
	private function get_verdict_html( $counts ) {
		if ( $counts['critical'] + $counts['warning'] > 0 ) {
			$parts = [];

			if ( $counts['critical'] > 0 )
				/* translators: %d: number of results */
				$parts[] = sprintf( _n( '%d critical', '%d critical', $counts['critical'], 'post-views-counter' ), $counts['critical'] );

			if ( $counts['warning'] > 0 )
				/* translators: %d: number of results */
				$parts[] = sprintf( _n( '%d warning', '%d warnings', $counts['warning'], 'post-views-counter' ), $counts['warning'] );

			/* translators: %s: counts by severity */
			$verdict = sprintf( __( 'Problems found: %s.', 'post-views-counter' ), implode( ', ', $parts ) );
		} elseif ( $counts['recommended'] > 0 ) {
			/* translators: %d: number of results */
			$verdict = sprintf( _n( 'Recommendations: %d.', 'Recommendations: %d.', $counts['recommended'], 'post-views-counter' ), $counts['recommended'] );
		} else {
			$verdict = __( 'No problems found in the checks that ran.', 'post-views-counter' );
		}

		$html = '<p class="pvc-status-verdict-title"><strong>' . esc_html( $verdict ) . '</strong></p>';

		if ( $counts['not_tested'] + $counts['unknown'] > 0 )
			/* translators: 1: number of results not tested, 2: number of unknown results */
			$html .= '<p>' . esc_html( sprintf( __( 'Not tested: %1$d. Unknown: %2$d.', 'post-views-counter' ), $counts['not_tested'], $counts['unknown'] ) ) . '</p>';

		/* translators: %s: date and time */
		$html .= '<p>' . esc_html( sprintf( __( 'Checked %s.', 'post-views-counter' ), $this->format_time( $this->checked_at ) ) ) . '</p>';
		$html .= '<p class="description">' . esc_html__( 'This page changes no settings and counts no views. It describes the checks that ran; it does not certify that counting works.', 'post-views-counter' ) . '</p>';
		return wp_get_admin_notice( $html, [
			'type'				=> $this->get_notice_type( $counts ),
			'dismissible'		=> false,
			'paragraph_wrap'	=> false,
			'additional_classes' => [ 'inline', 'pvc-status-verdict' ]
		] );
	}

	/**
	 * One severity mapping for both the notice and the tab indicator.
	 *
	 * @param array $counts Counts keyed by severity.
	 * @return string WordPress notice type.
	 */
	private function get_notice_type( $counts ) {
		if ( $counts['critical'] > 0 )
			return 'error';

		return $counts['warning'] + $counts['recommended'] > 0 ? 'warning' : 'info';
	}

	/**
	 * Counting activity: its heading and one check.
	 *
	 * @param array $check Counting activity check.
	 * @return string
	 */
	private function get_activity_html( $check ) {
		$html = '<div class="pvc-status-activity">';
		$html .= '<h2>' . esc_html__( 'Counting activity', 'post-views-counter' ) . '</h2>';
		$html .= $this->get_checks_table_html( [ $check ] );
		$html .= '</div>';

		return $html;
	}

	/**
	 * Checks as settings rows.
	 *
	 * @param array $checks Checks.
	 * @return string
	 */
	private function get_checks_table_html( $checks ) {
		$html = '<table class="form-table pvc-status-checks" role="presentation"><tbody>';

		foreach ( $checks as $check ) {
			$html .= $this->get_check_html( $check );
		}

		return $html . '</tbody></table>';
	}

	/**
	 * One check's row: its severity label, then the finding. Every value is
	 * escaped as plain text.
	 *
	 * @param array $check Check.
	 * @return string
	 */
	private function get_check_html( $check ) {
		$severity = $check['severity'];
		$html = '<tr class="pvc-status-check pvc-status-check-' . esc_attr( str_replace( '_', '-', $severity ) ) . '" data-check="' . esc_attr( $check['id'] ) . '">';
		$html .= '<th scope="row"><span class="pvc-status-badge pvc-status-badge-' . esc_attr( str_replace( '_', '-', $severity ) ) . '">' . esc_html( $this->get_severity_label( $severity ) ) . '</span></th>';
		$html .= '<td>';
		$html .= '<p class="pvc-status-check-title">' . esc_html( $check['title'] ) . '</p>';

		if ( ! empty( $check['detail'] ) ) {
			$html .= '<ul class="pvc-status-check-detail">';

			foreach ( $check['detail'] as $line ) {
				$html .= '<li>' . esc_html( $line ) . '</li>';
			}

			$html .= '</ul>';
		}

		$meta = [];

		if ( $check['scope'] !== '' )
			$meta[] = $check['scope'];

		/* translators: %s: date and time */
		$meta[] = $check['observed_at'] === null ? __( 'Observation time unknown.', 'post-views-counter' ) : sprintf( __( 'Observed %s.', 'post-views-counter' ), $this->format_time( $check['observed_at'] ) );

		$html .= '<p class="pvc-status-check-scope description">' . esc_html( implode( ' ', $meta ) ) . '</p>';

		if ( $check['action'] !== null )
			$html .= '<p class="pvc-status-check-action"><a href="' . esc_url( $check['action']['url'] ) . '">' . esc_html( $check['action']['label'] ) . '</a></p>';

		$html .= '</td></tr>';

		return $html;
	}

	/**
	 * A time in the site's date and time formats, with its time zone.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private function format_time( $timestamp ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', (int) $timestamp );
	}

	/**
	 * A counting day (Ymd, in the counting clock) in the site's date format.
	 *
	 * @param string $day Day key.
	 * @return string
	 */
	private function format_day( $day ) {
		$date = DateTimeImmutable::createFromFormat( '!Ymd', $day, new DateTimeZone( 'UTC' ) );

		return $date === false ? $day : wp_date( get_option( 'date_format' ), $date->getTimestamp(), new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Severity label.
	 *
	 * @param string $severity Severity.
	 * @return string
	 */
	private function get_severity_label( $severity ) {
		$labels = [
			'critical'			=> __( 'Critical', 'post-views-counter' ),
			'warning'			=> __( 'Warning', 'post-views-counter' ),
			'recommended'		=> __( 'Recommended', 'post-views-counter' ),
			'good'				=> __( 'Good', 'post-views-counter' ),
			'not_tested'		=> __( 'Not tested', 'post-views-counter' ),
			'unknown'			=> __( 'Unknown', 'post-views-counter' ),
			'not_applicable'	=> __( 'Not applicable', 'post-views-counter' )
		];

		return $labels[ $severity ];
	}

	/**
	 * Group label.
	 *
	 * @param string $group Group.
	 * @return string
	 */
	private function get_group_label( $group ) {
		$labels = [
			'delivery'				=> __( 'Delivery and caching', 'post-views-counter' ),
			'scheduled_tasks'		=> __( 'Buffering and scheduled tasks', 'post-views-counter' ),
			'data_storage'			=> __( 'Data and storage', 'post-views-counter' ),
			'environment'			=> __( 'Environment and license', 'post-views-counter' ),
			'counting_rules'		=> __( 'Counting rules', 'post-views-counter' ),
			'other_checks'			=> __( 'Other checks', 'post-views-counter' )
		];

		return $labels[ $group ];
	}

	/**
	 * A readable phrase for an availability reason code; an unrecognized
	 * code is shown as it is.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	private function get_reason_label( $reason ) {
		$labels = [
			'interval_zero'				=> __( 'the Count Interval is below one hour, so new Visits are not recorded', 'post-views-counter' ),
			'schema_pending'			=> __( 'the database column for Visits is not ready yet', 'post-views-counter' ),
			'schema_failed'				=> __( 'the database column for Visits could not be prepared', 'post-views-counter' ),
			'writer_unsupported'		=> __( 'an active counting component does not support Visits', 'post-views-counter' ),
			'storage_incapable'			=> __( 'the storage used to spot repeat views cannot record Visits', 'post-views-counter' ),
			'state_read_failed'			=> __( 'the plugin state could not be read', 'post-views-counter' ),
			'state_format_unsupported'	=> __( 'the plugin state could not be verified', 'post-views-counter' ),
			'reset_in_progress'			=> __( 'a statistics reset is in progress or did not finish', 'post-views-counter' ),
			'core_deactivated'			=> __( 'a marker from deactivation with data removal blocks them', 'post-views-counter' )
		];

		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
	}
}
