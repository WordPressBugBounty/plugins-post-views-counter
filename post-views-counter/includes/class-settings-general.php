<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Settings_General class.
 *
 * @class Post_Views_Counter_Settings_General
 */
class Post_Views_Counter_Settings_General {

	private $pvc;

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->pvc = Post_Views_Counter();

		// actions
		add_action( 'admin_init', [ $this, 'update_counter_mode' ], 12 );
	}

	/**
	 * Update counter mode.
	 *
	 * @return void
	 */
	public function update_counter_mode() {
		// get settings
		$settings = $this->pvc->settings_api->get_settings();

		// fast ajax as active but not available counter mode?
		if ( $this->pvc->options['general']['counter_mode'] === 'ajax' && in_array( 'ajax', $settings['post-views-counter']['fields']['counter_mode']['disabled'], true ) ) {
			// set standard javascript ajax calls
			$this->pvc->options['general']['counter_mode'] = 'js';

			// update database options
			update_option( 'post_views_counter_settings_general', $this->pvc->options['general'] );
		}
	}

	/**
	 * Get available counter modes.
	 *
	 * @return array
	 */
	public function get_counter_modes() {
		// counter modes
		$modes = [
			'php'		=> __( 'PHP', 'post-views-counter' ),
			'js'		=> __( 'JavaScript', 'post-views-counter' ),
			'rest_api'	=> __( 'REST API', 'post-views-counter' ),
			'ajax'		=> __( 'Fast AJAX', 'post-views-counter' )
		];

		return apply_filters( 'pvc_get_counter_modes', $modes );
	}

	/**
	 * Get sections for general tab.
	 *
	 * @return array
	 */
	public function get_sections() {
		return [
			'post_views_counter_general_tracking_targets' => [
				'tab'      => 'general',
				'title'    => __( 'Tracking Targets', 'post-views-counter' ),
				'callback' => [ $this, 'section_tracking_targets' ],
			],
			'post_views_counter_general_tracking_behavior' => [
				'tab'      => 'general',
				'title'    => __( 'Tracking Behavior', 'post-views-counter' ),
				'callback' => [ $this, 'section_tracking_behavior' ],
			],
			'post_views_counter_general_exclusions' => [
				'tab'      => 'general',
				'title'    => __( 'Visitor Exclusions', 'post-views-counter' ),
				'callback' => [ $this, 'section_tracking_exclusions' ],
			],
			'post_views_counter_general_performance' => [
				'tab'      => 'general',
				'title'    => __( 'Performance & Caching', 'post-views-counter' ),
				'callback' => [ $this, 'section_tracking_performance' ],
			]
		];
	}

	/**
	 * Get fields for general tab.
	 *
	 * @return array
	 */
	public function get_fields() {
		// time types
		$time_types = [
			'minutes'	=> __( 'minutes', 'post-views-counter' ),
			'hours'		=> __( 'hours', 'post-views-counter' ),
			'days'		=> __( 'days', 'post-views-counter' ),
			'weeks'		=> __( 'weeks', 'post-views-counter' ),
			'months'	=> __( 'months', 'post-views-counter' ),
			'years'		=> __( 'years', 'post-views-counter' )
		];

		// user groups
		$groups = [
			'robots'	=> __( 'crawlers', 'post-views-counter' ),
			'ai_bots'	=> __( 'AI bots', 'post-views-counter' ),
			'users'		=> __( 'logged in users', 'post-views-counter' ),
			'guests'	=> __( 'guests', 'post-views-counter' ),
			'roles'		=> __( 'selected user roles', 'post-views-counter' )
		];

		// get user roles
		$user_roles = $this->pvc->functions->get_user_roles();

		// get post types
		$post_types = $this->pvc->functions->get_post_types();

		return [
			'post_types_count' => [
				'tab'			=> 'general',
				'title'			=> __( 'Post Types', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_tracking_targets',
				'type'			=> 'checkbox',
				'description'	=> __( 'Select post types whose views should be counted.', 'post-views-counter' ),
				'options'		=> $post_types
			],
			'taxonomies_count' => [
				'tab'			=> 'general',
				'title'			=> __( 'Taxonomies', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_tracking_targets',
				'type'			=> 'boolean',
				'label'			=> __( 'Enable counting views on taxonomy term archive pages.', 'post-views-counter' ),
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'value'			=> false,
				'pro_only'		=> true
			],
			'users_count' => [
				'tab'			=> 'general',
				'title'			=> __( 'Author Archives', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_tracking_targets',
				'type'			=> 'boolean',
				'label'			=> __( 'Enable counting views on author archive pages.', 'post-views-counter' ),
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'value'			=> false,
				'pro_only'		=> true
			],
			'other_count' => [
				'tab'			=> 'general',
				'title'			=> __( 'Other Pages', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_tracking_targets',
				'type'			=> 'boolean',
				'label'			=> __( 'Track views on the front page, post type archives, date archives, search results, and 404 pages.', 'post-views-counter' ),
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'value'			=> false,
				'pro_only'		=> true
			],
			'technology_count' => [
				'tab'			=> 'general',
				'title'			=> __( 'Traffic Sources', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_tracking_targets',
				'type'			=> 'boolean',
				'label'			=> __( 'Collect aggregate stats about visitors\' browsers, devices, operating systems and referrers.', 'post-views-counter' ),
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'value'			=> false,
				'pro_only'		=> true
			],
			'counter_mode' => [
				'tab'			=> 'general',
				'title'			=> __( 'Counter Mode', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_tracking_behavior',
				'type'			=> 'radio',
				'description'	=> __( 'Choose how views are recorded. If you use caching, select JavaScript, REST API or Fast AJAX (up to <code>10+</code> times faster).', 'post-views-counter' ),
				'class'			=> 'pvc-pro-extended',
				'options'		=> $this->get_counter_modes(),
				'disabled'		=> [ 'ajax' ],
				'pro_only'		=> [ 'ajax' ]
			],
			'data_storage' => [
				'tab'			=> 'general',
				'title'			=> __( 'Data Storage', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_tracking_behavior',
				'type'			=> 'radio',
				'class'			=> 'pvc-pro',
				'skip_saving'	=> true,
				'description'	=> __( "Choose how to store the content views data in the user's browser - with or without cookies.", 'post-views-counter' ),
				'pro_only'		=> true,
				'options'		=> [
					'cookies'		=> __( 'Cookies', 'post-views-counter' ),
					'cookieless'	=> __( 'Cookieless', 'post-views-counter' )
				],
				'disabled'		=> [ 'cookies', 'cookieless' ],
				'value'			=> 'cookies'
			],
			'time_between_counts' => [
				'tab'			=> 'general',
				'title'			=> __( 'Count Interval', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_tracking_behavior',
				'type'			=> 'custom',
				'description'	=> '',
				'min'			=> 0,
				'max'			=> 999999,
				'options'		=> $time_types,
				'callback'		=> [ $this, 'setting_time_between_counts' ],
				'validate'		=> [ $this, 'validate_time_between_counts' ]
			],
			'count_time' => [
				'tab'			=> 'general',
				'title'			=> __( 'Count Time', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_tracking_behavior',
				'type'			=> 'radio',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'description'	=> __( 'Whether to store the views using GMT timezone or adjust it to the GMT offset of the site.', 'post-views-counter' ),
				'options'		=> [
					'gmt'		=> __( 'GMT Time', 'post-views-counter' ),
					'local'		=> __( 'Local Time', 'post-views-counter' )
				],
				'pro_only'		=> true
			],
			'strict_counts' => [
				'tab'			=> 'general',
				'title'			=> __( 'Strict Counts', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_tracking_behavior',
				'type'			=> 'boolean',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'value'			=> false,
				'description'	=> '',
				'label'			=> __( 'Prevent bypassing the count interval (for example by using incognito mode or clearing cookies).', 'post-views-counter' ),
				'pro_only'		=> true
			],
			'reset_counts' => [
				'tab'			=> 'general',
				'title'			=> __( 'Cleanup Interval', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_tracking_behavior',
				'type'			=> 'custom',
				'description'	=> sprintf( __( 'Delete daily content view data older than the period specified above. Enter %s to keep data regardless of age. Cleanup runs once per day.', 'post-views-counter' ), '<code>0</code>' ),
				'min'			=> 0,
				'max'			=> 999999,
				'options'		=> $time_types,
				'callback'		=> [ $this, 'setting_reset_counts' ],
				'validate'		=> [ $this, 'validate_reset_counts' ]
			],
			'caching_compatibility' => [
				'tab'			=> 'general',
				'title'			=> __( 'Caching Compatibility', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_performance',
				'type'			=> 'boolean',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'value'			=> false,
				'skip_saving'	=> true,
				'animation'		=> 'slide',
				'available'		=> $this->is_caching_compatibility_available(),
				'label'			=> $this->get_caching_compatibility_label(),
				'description'	=> $this->get_caching_compatibility_description(),
				'pro_only'		=> true
			],
			'object_cache' => [
				'tab'			=> 'general',
				'title'			=> __( 'Object Cache Support', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_performance',
				'type'			=> 'boolean',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'value'			=> false,
				'skip_saving'	=> true,
				'animation'		=> 'slide',
				'available'		=> $this->is_object_cache_available(),
				'label'			=> $this->get_object_cache_label(),
				'description'	=> $this->get_object_cache_description(),
				'pro_only'		=> true
			],
			'exclude_groups' => [
				'tab'			=> 'general',
				'title'			=> __( 'Exclude Visitors', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_exclusions',
				'type'			=> 'checkbox',
				'description'	=> __( 'Use this to exclude specific visitor groups from counting views.', 'post-views-counter' ),
				'class'			=> 'pvc-pro-extended',
				'options'		=> $groups,
				'disabled'		=> [ 'ai_bots' ],
				'pro_only'		=> [ 'ai_bots' ],
				'name'			=> 'post_views_counter_settings_general[exclude][groups]',
				'value'			=> $this->pvc->options['general']['exclude']['groups'],
				'validate'		=> [ $this, 'validate_exclude_groups' ]
			],
			'exclude_roles' => [
				'tab'			=> 'general',
				'title'			=> __( 'Exclude User Roles', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_exclusions',
				'type'			=> 'checkbox',
				'description'	=> __( 'Use this to exclude specific user roles from counting views.', 'post-views-counter' ),
				'options'		=> $user_roles,
				'logic'			=> [
					[
						'field'		=> 'exclude_groups',
						'operator'	=> 'contains',
						'value'		=> 'roles'
					]
				],
				'animation'		=> 'slide',
				'name'			=> 'post_views_counter_settings_general[exclude][roles]',
				'value'			=> $this->pvc->options['general']['exclude']['roles'],
				'validate'		=> [ $this, 'validate_exclude_roles' ]
			],
			'exclude_ips' => [
				'tab'			=> 'general',
				'title'			=> __( 'Exclude IPs', 'post-views-counter' ),
				'section'		=> 'post_views_counter_general_exclusions',
				'type'			=> 'custom',
				'description'	=> '',
				'callback'		=> [ $this, 'setting_exclude_ips' ],
				'validate'		=> [ $this, 'validate_exclude_ips' ]
			]
		];
	}

	/**
	 * Setting: taxonomies count.
	 *
	 * @param array $field
	 * @return string
	 */
	public function setting_taxonomies_count( $field ) {
		$html = '
			<label class="pvc-disabled"><input id="post_views_counter_general_taxonomies_count" type="checkbox" name="" value="" disabled role="switch" />' . esc_html( $field['label'] ) . '</label>';

		return $html;
	}

	/**
	 * Setting: users count.
	 *
	 * @param array $field
	 * @return string
	 */
	public function setting_users_count( $field ) {
		$html = '
			<label class="pvc-disabled"><input id="post_views_counter_general_users_count" type="checkbox" name="" value="" disabled role="switch" />' . esc_html( $field['label'] ) . '</label>';

		return $html;
	}

	/**
	 * Setting: count interval.
	 *
	 * @param array $field
	 * @return string
	 */
	public function setting_time_between_counts( $field ) {
		$html = '
		<input size="6" type="number" min="' . ( (int) $field['min'] ) . '" max="' . ( (int) $field['max'] ) . '" name="post_views_counter_settings_general[time_between_counts][number]" value="' . esc_attr( $this->pvc->options['general']['time_between_counts']['number'] ) . '" />
		<select name="post_views_counter_settings_general[time_between_counts][type]">';

		foreach ( $field['options'] as $type => $type_name ) {
			$html .= '
			<option value="' . esc_attr( $type ) . '" ' . selected( $type, $this->pvc->options['general']['time_between_counts']['type'], false ) . '>' . esc_html( $type_name ) . '</option>';
		}

		$html .= '
		</select>
		<p class="description">' . __( 'Minimum time between counting new views from the same visitor. Enter <code>0</code> to count every page view.', 'post-views-counter' ) . '</p>';

		return $html;
	}

	/**
	 * Validate count interval.
	 *
	 * @param array $input
	 * @param array $field
	 * @return array
	 */
	public function validate_time_between_counts( $input, $field ) {
		// number
		$input['time_between_counts']['number'] = isset( $input['time_between_counts']['number'] ) ? (int) $input['time_between_counts']['number'] : $this->pvc->defaults['general']['time_between_counts']['number'];

		if ( $input['time_between_counts']['number'] < $field['min'] || $input['time_between_counts']['number'] > $field['max'] )
			$input['time_between_counts']['number'] = $this->pvc->defaults['general']['time_between_counts']['number'];

		// type
		$input['time_between_counts']['type'] = isset( $input['time_between_counts']['type'], $field['options'][$input['time_between_counts']['type']] ) ? $input['time_between_counts']['type'] : $this->pvc->defaults['general']['time_between_counts']['type'];

		return $input;
	}

	/**
	 * Setting: reset data interval.
	 *
	 * @param array $field
	 * @return string
	 */
	public function setting_reset_counts( $field ) {
		$html = '
		<input size="6" type="number" min="' . ( (int) $field['min'] ) . '" max="' . ( (int) $field['max'] ) . '" name="post_views_counter_settings_general[reset_counts][number]" value="' . esc_attr( $this->pvc->options['general']['reset_counts']['number'] ) . '" />
		<select name="post_views_counter_settings_general[reset_counts][type]">';

		foreach ( array_slice( $field['options'], 2, null, true ) as $type => $type_name ) {
			$html .= '
			<option value="' . esc_attr( $type ) . '" ' . selected( $type, $this->pvc->options['general']['reset_counts']['type'], false ) . '>' . esc_html( $type_name ) . '</option>';
		}

		$html .= '
		</select>';

		return $html;
	}

	/**
	 * Validate reset data interval.
	 *
	 * @param array $input
	 * @param array $field
	 * @return array
	 */
	public function validate_reset_counts( $input, $field ) {
		// number
		$input['reset_counts']['number'] = isset( $input['reset_counts']['number'] ) ? (int) $input['reset_counts']['number'] : $this->pvc->defaults['general']['reset_counts']['number'];

		if ( $input['reset_counts']['number'] < $field['min'] || $input['reset_counts']['number'] > $field['max'] )
			$input['reset_counts']['number'] = $this->pvc->defaults['general']['reset_counts']['number'];

		// type
		$input['reset_counts']['type'] = isset( $input['reset_counts']['type'], $field['options'][$input['reset_counts']['type']] ) ? $input['reset_counts']['type'] : $this->pvc->defaults['general']['reset_counts']['type'];

		// run cron on next visit?
		$input['cron_run'] = ( $input['reset_counts']['number'] > 0 );

		// cron update?
		$input['cron_update'] = ( $input['cron_run'] && ( $this->pvc->options['general']['reset_counts']['number'] !== $input['reset_counts']['number'] || $this->pvc->options['general']['reset_counts']['type'] !== $input['reset_counts']['type'] ) );

		return $input;
	}

	/**
	 * Setting: object cache.
	 *
	 * @param array $field
	 * @return string
	 */
	public function setting_object_cache( $field ) {
		$html = '
		<input size="4" type="number" min="' . ( (int) $field['min'] ) . '" max="' . ( (int) $field['max'] ) . '" name="" value="0" disabled /> <span>' . __( 'minutes', 'post-views-counter' ) . '</span>
		<p class="">' . __( 'Persistent Object Cache', 'post-views-counter' ) . ': <span class="' . ( wp_using_ext_object_cache() ? '' : 'un' ) . 'available">' . ( wp_using_ext_object_cache() ? __( 'available', 'post-views-counter' ) : __( 'unavailable', 'post-views-counter' ) ) . '</span></p>
		<p class="description">' . sprintf( __( 'How often to flush cached view counts from the object cache into the database. This feature is used only if a persistent object cache like %s or %s is detected and the interval is greater than %s. When used, view counts will be collected and stored in the object cache instead of the database and will then be asynchronously flushed to the database according to the specified interval. The maximum value is %s which means 24 hours.%sNotice:%s Potential data loss may occur if the object cache is cleared/unavailable for the duration of the interval.', 'post-views-counter' ), '<code>Redis</code>', '<code>Memcached</code>', '<code>0</code>', '<code>1440</code>', '<br /><strong> ', '</strong>' ) . '</p>';

		return $html;
	}

	/**
	 * Setting: exclude visitors.
	 *
	 * @param array $field
	 * @return string
	 */


	/**
	 * Validate exclude visitors.
	 *
	 * @param array $input
	 * @param array $field
	 * @return array
	 */
	public function validate_exclude_groups( $input, $field ) {
		$groups = [];

		if ( is_array( $input ) ) {
			foreach ( $input as $group ) {
				// sanitize value
				$group = sanitize_key( $group );

				// disallow disabled checkboxes
				if ( ! empty( $field['disabled'] ) && in_array( $group, $field['disabled'], true ) )
					continue;

				if ( isset( $field['options'][$group] ) )
					$groups[] = $group;
			}
		}

		return array_unique( $groups );
	}

	public function validate_exclude_roles( $input, $field ) {
		$roles = [];

		if ( is_array( $input ) ) {
			foreach ( $input as $role ) {
				// sanitize value
				$role = sanitize_key( $role );

				if ( isset( $field['options'][$role] ) )
					$roles[] = $role;
			}
		}

		return array_unique( $roles );
	}

	/**
	 * Setting: exclude IP addresses.
	 *
	 * @return string
	 */
	public function setting_exclude_ips() {
		// get ip addresses
		$ips = $this->pvc->options['general']['exclude_ips'];

		$html = '<div class="pvc-ip-box-group">';

		// any ip addresses?
		if ( ! empty( $ips ) ) {
			foreach ( $ips as $key => $ip ) {
				$html .= '
			<div class="pvc-ip-box">
				<input type="text" name="post_views_counter_settings_general[exclude_ips][]" value="' . esc_attr( $ip ) . '" /> <a href="#" class="pvc-remove-exclude-ip" title="' . esc_attr__( 'Remove', 'post-views-counter' ) . '">' . esc_html__( 'Remove', 'post-views-counter' ) . '</a>
			</div>';
			}
		} else {
			$html .= '
			<div class="pvc-ip-box">
				<input type="text" name="post_views_counter_settings_general[exclude_ips][]" value="" /> <a href="#" class="pvc-remove-exclude-ip pvc-hidden" title="' . esc_attr__( 'Remove', 'post-views-counter' ) . '">' . esc_html__( 'Remove', 'post-views-counter' ) . '</a>
			</div>';
		}

		$html .= '</div>';

		$html .= '
		<div class="pvc-field-group pvc-buttons-group">
				<input type="button" class="button outline pvc-add-exclude-ip" value="' . esc_attr__( 'Add new', 'post-views-counter' ) . '" /> <input type="button" class="button outline pvc-add-current-ip" value="' . esc_attr__( 'Add my current IP', 'post-views-counter' ) . '" data-rel="' . esc_attr( $_SERVER['REMOTE_ADDR'] ) . '" />
		</div>';

		$html .= '<p class="description">' . esc_html__( 'Add IP addresses or wildcards (e.g. 192.168.0.*) to exclude them from counting views.', 'post-views-counter' ) . '</p>';

		return $html;
	}

	/**
	 * Validate exclude IP addresses.
	 *
	 * @param array $input
	 * @param array $field
	 * @return array
	 */
	public function validate_exclude_ips( $input, $field ) {
		// any ip addresses?
		if ( isset( $input['exclude_ips'] ) ) {
			$ips = [];

			foreach ( $input['exclude_ips'] as $ip ) {
				if ( strpos( $ip, '*' ) !== false ) {
					$new_ip = str_replace( '*', '0', $ip );

					if ( filter_var( $new_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) )
						$ips[] = $ip;
				} elseif ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) )
					$ips[] = $ip;
			}

			$input['exclude_ips'] = array_unique( $ips );
		}

		return $input;
	}

	/**
	 * Section description: tracking targets.
	 *
	 * @return void
	 */
	public function section_tracking_targets() {
		echo '<p class="description">' . esc_html__( 'Control which post types, archives and other content types are included in view counting.', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Section description: tracking behavior.
	 *
	 * @return void
	 */
	public function section_tracking_behavior() {
		echo '<p class="description">' . esc_html__( 'Control how views are recorded — counting mode, intervals, time zone, and cleanup.', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Section description: tracking exclusions.
	 *
	 * @return void
	 */
	public function section_tracking_exclusions() {
		echo '<p class="description">' . esc_html__( 'Exclude specific visitor groups or IP addresses from incrementing view counts.', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Section description: performance & caching.
	 *
	 * @return void
	 */
	public function section_tracking_performance() {
		echo '<p class="description">' . esc_html__( 'Configure caching compatibility and object-cache handling for counting.', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Get caching compatibility description.
	 *
	 * @return string
	 */
	public function get_caching_compatibility_description() {
		// get active caching plugins
		$active_plugins = $this->get_active_caching_plugins();

		if ( ! empty( $active_plugins ) ) {
			$active_plugins_html = [];

			$description = esc_html__( 'Currently detected active caching plugins', 'post-views-counter' ) . ': ';

			foreach ( $active_plugins as $plugin ) {
				$active_plugins_html[] = '<code>' . esc_html( $plugin ) . '</code>';
			}

			$description .= implode( ', ', $active_plugins_html ) . '.';
		} else {
			$description = esc_html__( 'No compatible caching plugins found.', 'post-views-counter' );
		}

		return $description;
	}

	/**
	 * Get caching compatibility label with availability indicator.
	 *
	 * @return string
	 */
	public function get_caching_compatibility_label() {
		$label = __( 'Enable compatibility tweaks for supported caching plugins.', 'post-views-counter' );

		// add availability indicator when Pro is missing but feature is available
		if ( ! class_exists( 'Post_Views_Counter_Pro' ) && $this->is_caching_compatibility_available() ) {
			$label = '<span class="pvc-availability-status available">' . esc_html__( '(available)', 'post-views-counter' ) . '</span> ' . $label;
		}

		return $label;
	}

	/**
	 * Get object cache label with availability indicator.
	 *
	 * @return string
	 */
	public function get_object_cache_label() {
		$label = __( 'Enable Redis or Memcached object cache optimization.', 'post-views-counter' );

		// add availability indicator when Pro is missing but feature is available
		if ( ! class_exists( 'Post_Views_Counter_Pro' ) && $this->is_object_cache_available() ) {
			$label = '<span class="pvc-availability-status available">' . esc_html__( '(available)', 'post-views-counter' ) . '</span> ' . $label;
		}

		return $label;
	}

	/**
	 * Check if caching compatibility feature is available.
	 *
	 * @return bool
	 */
	public function is_caching_compatibility_available() {
		$active_plugins = $this->get_active_caching_plugins();

		return ! empty( $active_plugins );
	}

	/**
	 * Check if object cache feature is available.
	 *
	 * @return bool
	 */
	public function is_object_cache_available() {
		return wp_using_ext_object_cache();
	}

	/**
	 * Get object cache description based on availability.
	 *
	 * @return string
	 */
	public function get_object_cache_description() {
		if ( $this->is_object_cache_available() ) {
			return __( 'Persistent object cache has been detected.', 'post-views-counter' );
		}

		return sprintf( __( 'This feature requires a persistent object cache like %s or %s to be installed and activated.', 'post-views-counter' ), '<code>Redis</code>', '<code>Memcached</code>' );
	}

	/**
	 * Extend active caching plugins.
	 *
	 * @param array $plugins
	 *
	 * @return array
	 */
	public function extend_active_caching_plugins( $plugins ) {
		// breeze
		if ( $this->is_plugin_active( 'breeze' ) )
			$plugins[] = 'Breeze';

		return $plugins;
	}

	/**
	 * Check whether specified plugin is active.
	 *
	 * @param bool $is_plugin_active
	 * @param string $plugin
	 *
	 * @return bool
	 */
	public function extend_is_plugin_active( $is_plugin_active, $plugin ) {
		// breeze
		if ( $plugin === 'breeze' && class_exists( 'Breeze_PurgeCache' ) && class_exists( 'Breeze_Options_Reader' ) && function_exists( 'breeze_get_option' ) && function_exists( 'breeze_update_option' ) && defined( 'BREEZE_VERSION' ) && version_compare( BREEZE_VERSION, '2.0.30', '>=' ) )
			$is_plugin_active = true;

		return $is_plugin_active;
	}

	/**
	 * Get active caching plugins.
	 *
	 * @return array
	 */
	public function get_active_caching_plugins() {
		$active_plugins = [];

		// autoptimize
		if ( $this->is_plugin_active( 'autoptimize' ) )
			$active_plugins[] = 'Autoptimize';

		// hummingbird
		if ( $this->is_plugin_active( 'hummingbird' ) )
			$active_plugins[] = 'Hummingbird';

		// litespeed
		if ( $this->is_plugin_active( 'litespeed' ) )
			$active_plugins[] = 'LiteSpeed Cache';

		// speed optimizer
		if ( $this->is_plugin_active( 'speedoptimizer' ) )
			$active_plugins[] = 'Speed Optimizer';

		// speedycache
		if ( $this->is_plugin_active( 'speedycache' ) )
			$active_plugins[] = 'SpeedyCache';

		// wp fastest cache
		if ( $this->is_plugin_active( 'wpfastestcache' ) )
			$active_plugins[] = 'WP Fastest Cache';

		// wp-optimize
		if ( $this->is_plugin_active( 'wpoptimize' ) )
			$active_plugins[] = 'WP-Optimize';

		// wp rocket
		if ( $this->is_plugin_active( 'wprocket' ) )
			$active_plugins[] = 'WP Rocket';

		return apply_filters( 'pvc_active_caching_plugins', $active_plugins );
	}

	/**
	 * Check whether specified plugin is active.
	 *
	 * @param string $plugin
	 *
	 * @return bool
	 */
	public function is_plugin_active( $plugin = '' ) {
		// set default flag
		$is_plugin_active = false;

		switch ( $plugin ) {
			// autoptimize
			case 'autoptimize':
				if ( function_exists( 'autoptimize' ) && defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) && version_compare( AUTOPTIMIZE_PLUGIN_VERSION, '2.4', '>=' ) )
					$is_plugin_active = true;
				break;

			// hummingbird
			case 'hummingbird':
				if ( class_exists( 'Hummingbird\\WP_Hummingbird' ) && defined( 'WPHB_VERSION' ) && version_compare( WPHB_VERSION, '2.1.0', '>=' ) )
					$is_plugin_active = true;
				break;

			// litespeed
			case 'litespeed':
				if ( class_exists( 'LiteSpeed\Core' ) && defined( 'LSCWP_CUR_V' ) && version_compare( LSCWP_CUR_V, '3.0', '>=' ) )
					$is_plugin_active = true;
				break;

			// speed optimizer
			case 'speedoptimizer':
				global $siteground_optimizer_loader;

				if ( ! empty( $siteground_optimizer_loader ) && is_object( $siteground_optimizer_loader ) && is_a( $siteground_optimizer_loader, 'SiteGround_Optimizer\Loader\Loader' ) && defined( '\SiteGround_Optimizer\VERSION' ) && version_compare( \SiteGround_Optimizer\VERSION, '5.5', '>=' ) )
					$is_plugin_active = true;
				break;

			// speedycache
			case 'speedycache':
				if ( class_exists( 'SpeedyCache' ) && defined( 'SPEEDYCACHE_VERSION' ) && function_exists( 'speedycache_delete_cache' ) && version_compare( SPEEDYCACHE_VERSION, '1.0.0', '>=' ) )
					$is_plugin_active = true;
				break;

			// wp fastest cache
			case 'wpfastestcache':
				if ( function_exists( 'wpfc_clear_all_cache' ) )
					$is_plugin_active = true;
				break;

			// wp-optimize
			case 'wpoptimize':
				if ( function_exists( 'WP_Optimize' ) && defined( 'WPO_VERSION' ) && version_compare( WPO_VERSION, '3.0.12', '>=' ) )
					$is_plugin_active = true;
				break;

			// wp rocket
			case 'wprocket':
				if ( function_exists( 'rocket_init' ) && defined( 'WP_ROCKET_VERSION' ) && version_compare( WP_ROCKET_VERSION, '3.8', '>=' ) )
					$is_plugin_active = true;
				break;

			// other caching plugin
			default:
				$is_plugin_active = apply_filters( 'pvc_is_plugin_active', false, $plugin );
		}

		return $is_plugin_active;
	}
}
