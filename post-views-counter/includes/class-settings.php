<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Settings class.
 *
 * @class Post_Views_Counter_Settings
 */
class Post_Views_Counter_Settings {

	/**
	 * @var Post_Views_Counter_Settings_General
	 */
	public $general;

	/**
	 * @var Post_Views_Counter_Settings_Display
	 */
	public $display;

	/**
	 * @var Post_Views_Counter_Settings_Reports
	 */
	public $reports;

	/**
	 * @var Post_Views_Counter_Settings_Other
	 */
	public $other;

	/**
	 * @var Post_Views_Counter_Settings_Integrations
	 */
	public $integrations;

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'pvc_settings_sidebar', [ $this, 'settings_sidebar' ], 12, 4 );
		add_action( 'pvc_settings_form', [ $this, 'settings_form' ], 10, 4 );

		// filters
		add_filter( 'pvc_settings_data', [ $this, 'settings_data' ], 1 );
		add_filter( 'pvc_settings_data', [ $this, 'settings_fields_compat' ], 2 );
		add_filter( 'pvc_settings_data', [ $this, 'normalize_pro_settings' ], 10 );
		add_filter( 'pvc_settings_data', [ $this, 'settings_sections_compat' ], 99 );
		add_filter( 'pvc_settings_pages', [ $this, 'settings_page' ] );
		add_filter( 'pvc_settings_page_class', [ $this, 'settings_page_class' ] );
		add_filter( 'pvc_plugin_status_tables', [ $this, 'register_core_tables' ] );

		// instantiate page classes
		$this->general = new Post_Views_Counter_Settings_General();
		$this->display = new Post_Views_Counter_Settings_Display();
		$this->reports = new Post_Views_Counter_Settings_Reports();
		$this->integrations = new Post_Views_Counter_Settings_Integrations();
		$this->other = new Post_Views_Counter_Settings_Other( $this );
	}

	/**
	 * Magic method to proxy method calls to page classes for backward compatibility.
	 *
	 * @param string $method
	 * @param array $args
	 *
	 * @return mixed
	 */
	public function __call( $method, $args ) {
		// Check if method exists in general page class
		if ( method_exists( $this->general, $method ) ) {
			return call_user_func_array( [ $this->general, $method ], $args );
		}

		// Check if method exists in display page class
		if ( method_exists( $this->display, $method ) ) {
			return call_user_func_array( [ $this->display, $method ], $args );
		}

		// Check if method exists in reports page class
		if ( method_exists( $this->reports, $method ) ) {
			return call_user_func_array( [ $this->reports, $method ], $args );
		}

		// Check if method exists in integrations page class
		if ( method_exists( $this->integrations, $method ) ) {
			return call_user_func_array( [ $this->integrations, $method ], $args );
		}

		// Check if method exists in other page class
		if ( method_exists( $this->other, $method ) ) {
			return call_user_func_array( [ $this->other, $method ], $args );
		}

		// Method not found
		throw new BadMethodCallException( "Method {$method} does not exist" );
	}

	/**
	 * Add hidden inputs to redirect to valid page after changing menu position.
	 *
	 * @param string $setting
	 * @param string $page_type
	 * @param string $url_page
	 * @param string $tab_key
	 *
	 * @return void
	 */
	public function settings_form( $setting, $page_type, $url_page, $tab_key ) {
		// get main instance
		$pvc = Post_Views_Counter();
		$menu_position = $pvc->get_menu_position();

		// topmenu referer
		$topmenu = '<input type="hidden" name="_wp_http_referer" data-pvc-menu="topmenu" value="' .esc_url( admin_url( 'admin.php?page=post-views-counter' . ( $tab_key !== '' ? '&tab=' . $tab_key : '' ) ) ) . '" />';

		// submenu referer
		$submenu = '<input type="hidden" name="_wp_http_referer" data-pvc-menu="submenu" value="' .esc_url( admin_url( 'options-general.php?page=post-views-counter' . ( $tab_key !== '' ? '&tab=' . $tab_key : '' ) ) ) . '" />';

		if ( $menu_position === 'sub' )
			echo $topmenu . $submenu;
		else
			echo $submenu . $topmenu;
	}

	/**
	 * Display settings sidebar.
	 *
	 * @param string $setting
	 * @param string $page_type
	 * @param string $url_page
	 * @param string $tab_key
	 *
	 * @return void
	 */
	public function settings_sidebar( $setting = '', $page_type = '', $url_page = '', $tab_key = 'general' ) {
		if ( class_exists( 'Post_Views_Counter_Pro' ) ) {
			return;
		}

		// get sidebar content for current tab
		$content = $this->get_sidebar_content();

		// use general tab content if tab not found
		if ( ! isset( $content[$tab_key] ) ) {
			$tab_key = 'general';
		}

		$tab_content = $content[$tab_key];

		// build body HTML
		$body_html = '<h4 class="pvc-sidebar-subtitle">' . esc_html( $tab_content['subtitle'] ) . '</h4>';

		// add bullets if present
		if ( ! empty( $tab_content['bullets'] ) ) {
			foreach ( $tab_content['bullets'] as $bullet ) {
				$body_html .= '<p><span class="pvc-icon pvc-icon-check"></span>' . $bullet . '</p>';
			}
		}

		// add one-liner
		$body_html .= '<div class="pvc-sidebar-one-liner">' . esc_html( $tab_content['one_liner'] ) . '</div>';

		echo '
		<div class="post-views-sidebar">
			<div class="post-views-credits">
				<div class="inside">
					<div class="inner">
						<div class="pvc-sidebar-info">
							<div class="pvc-sidebar-head">
								<h3 class="pvc-sidebar-title">' . 'Post Views Counter' . '</h3>
							</div>
							<div class="pvc-sidebar-body">
								' . $body_html . '
							</div>
							<div class="pvc-sidebar-footer">
								<a href="https://postviewscounter.com/upgrade/?utm_source=post-views-counter-lite&utm_medium=button&utm_campaign=upgrade-to-pro" class="button button-secondary button-hero pvc-button" target="_blank">' . esc_html__( 'Upgrade to Pro', 'post-views-counter' ) . ' &rarr;</a>
								<p>' . esc_html__( 'Starting from $29 per year', 'post-views-counter' ) . '<br />' . esc_html__( '14-day money back guarantee.', 'post-views-counter' ) . '</p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>';
	}

	/**
	 * Get sidebar content for each settings tab.
	 *
	 * @return array
	 */
	private function get_sidebar_content() {
		return [
			'general' => [
				'subtitle'  => __( 'Reliable view counting', 'post-views-counter' ),
				'bullets'   => [
					__( 'Accurate counts with caching enabled', 'post-views-counter' ),
					__( 'Better performance under high traffic', 'post-views-counter' ),
					__( 'Protection against fake and repeated views', 'post-views-counter' )
				],
				'one_liner' => __( 'Upgrade to Pro to make your view counts reliable on real-world, cached WordPress sites.', 'post-views-counter' )
			],
			'display' => [
				'subtitle'  => __( 'Accurate & meaningful view display', 'post-views-counter' ),
				'bullets'   => [
					__( 'Choose which time period is displayed', 'post-views-counter' ),
					__( 'Always show up-to-date view counts', 'post-views-counter' ),
					__( 'Control where the counter appears', 'post-views-counter' )
				],
				'one_liner' => __( 'Upgrade to Pro to control what view data visitors actually see - not just total counts.', 'post-views-counter' )
			],
			'reports' => [
				'subtitle'  => __( 'Advanced view reports', 'post-views-counter' ),
				'bullets'   => [],
				'one_liner' => __( 'Upgrade to Pro to access detailed reports and visual insights for your content.', 'post-views-counter' )
			],
			'integrations' => [
				'subtitle'  => __( 'Use view counts where they actually matter', 'post-views-counter' ),
				'bullets'   => [
					__( 'Display and sort content by popularity', 'post-views-counter' ),
					__( 'Works with the tools you already use', 'post-views-counter' ),
					__( 'No custom queries or advanced setup', 'post-views-counter' )
				],
				'one_liner' => __( 'Upgrade to Pro to use view data across layouts and blocks - not just store it.', 'post-views-counter' )
			],
			'other' => [
				'subtitle'  => __( 'Migrate your view data safely', 'post-views-counter' ),
				'bullets'   => [
					__( 'Import view data from other WordPress plugins', 'post-views-counter' ),
					__( 'Choose how existing data is handled', 'post-views-counter' ),
					__( 'Support for posts and other content types', 'post-views-counter' )
				],
				'one_liner' => __( 'Upgrade to Pro to migrate historical view data without losing control over existing counts.', 'post-views-counter' )
			]
		];
	}

	/**
	 * Add settings data.
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	public function settings_data( $settings ) {
		// add settings
		$settings['post-views-counter'] = [
			'label' => __( 'Post Views Counter', 'post-views-counter' ),
			'form' => [
				'reports'	=> [
					'buttons'	=> false
				]
			],
			'option_name' => [
				'general'	=> 'post_views_counter_settings_general',
				'display'	=> 'post_views_counter_settings_display',
				'reports'	=> 'post_views_counter_settings_reports',
				'integrations'	=> 'post_views_counter_settings_integrations',
				'other'		=> 'post_views_counter_settings_other'
			],
			'validate' => [ $this, 'validate_settings' ],
			'sections' => array_merge(
				$this->general->get_sections(),
				$this->display->get_sections(),
				$this->reports->get_sections(),
				$this->other->get_sections(),
				$this->integrations->get_sections()
			),
			'fields' => array_merge(
				$this->general->get_fields(),
				$this->display->get_fields(),
				$this->reports->get_fields(),
				$this->other->get_fields(),
				$this->integrations->get_fields()
			)
		];

		// Backward compatibility: ensure legacy settings keys exist for older Pro versions.
		if ( ! isset( $settings['post-views-counter'] ) || ! is_array( $settings['post-views-counter'] ) )
			$settings['post-views-counter'] = [];

		if ( empty( $settings['post-views-counter']['fields'] ) || ! is_array( $settings['post-views-counter']['fields'] ) )
			$settings['post-views-counter']['fields'] = [];

		if ( empty( $settings['post-views-counter']['sections'] ) || ! is_array( $settings['post-views-counter']['sections'] ) )
			$settings['post-views-counter']['sections'] = [];

		if ( ! isset( $settings['post-views-counter']['sections']['post_views_counter_reports_settings'] ) || ! is_array( $settings['post-views-counter']['sections']['post_views_counter_reports_settings'] ) ) {
			$settings['post-views-counter']['sections']['post_views_counter_reports_settings'] = [
				'tab'		=> 'reports',
				'callback'	=> null
			];
		}

		// Backward compatibility: allow to hook into old filter name
		if ( has_filter( 'post_views_counter_settings_data' ) ) {
			// Add compatibility fields BEFORE deprecated filter
			$settings = $this->add_compat_fields( $settings );

			$settings = apply_filters_deprecated(
				'post_views_counter_settings_data',
				[ $settings ],
				'1.7.1',
				'pvc_settings_data'
			);

			// Copy Pro's changes from 'exclude' field back to 'exclude_groups' (Pro v1.7.0 compatibility)
			$settings = $this->sync_compat_fields( $settings );
		}

		return $settings;
	}

	/**
	 * Normalize Pro-gated settings when Pro is active.
	 *
	 * This runs at priority 10 (after settings_fields_compat at priority 2,
	 * before Pro hooks at priority 11), ensuring the normalizer executes in
	 * the main settings flow.
	 *
	 * @param array $settings
	 * @return array
	 */
	public function normalize_pro_settings( $settings ) {
		// Only run if Pro is active
		if ( ! class_exists( 'Post_Views_Counter_Pro' ) ) {
			return $settings;
		}

		// Normalize fields
		if ( ! empty( $settings['post-views-counter']['fields'] ) ) {
			$settings['post-views-counter']['fields'] = $this->normalize_pro_fields( $settings['post-views-counter']['fields'] );
		}

		return $settings;
	}

	/**
	 * Add compatibility fields before deprecated filter runs.
	 *
	 * @param array $settings
	 * @return array
	 */
	private function add_compat_fields( $settings ) {
		if ( empty( $settings['post-views-counter']['fields'] ) )
			return $settings;

		$fields =& $settings['post-views-counter']['fields'];

		// Define fallback fields
		$fallback_fields = [
			'exclude' => isset( $fields['exclude_groups'] ) ? $fields['exclude_groups'] : [
				'tab'				=> 'general',
				'section'			=> 'post_views_counter_general_exclusions',
				'type'				=> 'custom',
				'class'				=> 'pvc-pro',
				'skip_rendering'	=> true
			],
			'strict_counts' => [
				'tab'				=> 'general',
				'section'			=> 'post_views_counter_general_tracking_behavior',
				'type'				=> 'boolean',
				'class'				=> 'pvc-pro',
				'skip_rendering'	=> true
			],
			'amp_support' => [
				'tab'				=> 'general',
				'section'			=> 'post_views_counter_general_tracking_behavior',
				'type'				=> 'boolean',
				'class'				=> 'pvc-pro',
				'skip_rendering'	=> true
			]
		];

		// Add missing fields
		foreach ( $fallback_fields as $field_key => $field_def ) {
			if ( ! isset( $fields[$field_key] ) ) {
				$fields[$field_key] = $field_def;
			}
		}

		return $settings;
	}

	/**
	 * Sync changes from compatibility fields back to actual fields.
	 *
	 * @param array $settings
	 * @return array
	 */
	private function sync_compat_fields( $settings ) {
		if ( empty( $settings['post-views-counter']['fields'] ) )
			return $settings;

		$fields =& $settings['post-views-counter']['fields'];

		// If modified 'exclude' field, copy ALL changes to 'exclude_groups'
		if ( isset( $fields['exclude'] ) && isset( $fields['exclude_groups'] ) ) {
			// Only copy if 'exclude' was actually modified
			if ( ! isset( $fields['exclude']['skip_rendering'] ) || ! $fields['exclude']['skip_rendering'] ) {
				// Copy all changes to exclude_groups
				foreach ( $fields['exclude'] as $key => $value ) {
					// Don't overwrite critical properties that define the field structure
					if ( ! in_array( $key, [ 'tab', 'section', 'title', 'name', 'type' ], true ) ) {
						$fields['exclude_groups'][$key] = $value;
					}
				}
			}

			// Always mark 'exclude' to not be rendered (it's just an alias)
			$fields['exclude']['skip_rendering'] = true;
		}

		return $settings;
	}

	/**
	 * Normalize Pro-gated fields when Pro is active.
	 *
	 * This method removes PRO badges and disabled states from fields that are
	 * purely Pro-gated (marked with pro_only metadata), while preserving any
	 * disabled states that are based on runtime availability (like missing
	 * caching plugins, object cache, or integration dependencies).
	 *
	 * Runtime availability is checked for specific fields that have dynamic
	 * requirements (not static unavailable flags).
	 *
	 * @param array $fields
	 * @return array
	 */
	private function normalize_pro_fields( $fields ) {
		foreach ( $fields as $field_key => &$field ) {
			// Skip if field doesn't have pro_only flag
			if ( empty( $field['pro_only'] ) ) {
				continue;
			}

			// Check runtime availability for fields with dynamic requirements
			$is_available = $this->check_field_availability( $field_key, $field );

			// Check if pro_only is an array (for option-level gating like counter_mode['ajax'])
			if ( is_array( $field['pro_only'] ) ) {
				// Remove pvc-pro-extended class from field (preserve other classes)
				if ( isset( $field['class'] ) ) {
					$classes = array_filter( array_map( 'trim', explode( ' ', $field['class'] ) ) );
					$classes = array_diff( $classes, [ 'pvc-pro', 'pvc-pro-extended' ] );
					$field['class'] = implode( ' ', $classes );
				}

				// Remove pro_only options from disabled array
				if ( isset( $field['disabled'] ) && is_array( $field['disabled'] ) ) {
					$field['disabled'] = array_values( array_diff( $field['disabled'], $field['pro_only'] ) );
					
					// If disabled array is now empty, set to empty array or false
					if ( empty( $field['disabled'] ) ) {
						$field['disabled'] = [];
					}
				}

				// Clear skip_saving for option-level pro_only fields
				if ( isset( $field['skip_saving'] ) ) {
					$field['skip_saving'] = false;
				}
			} else {
				// Field-level gating - only unlock if available
				if ( $is_available ) {
					// Remove pvc-pro classes (preserve other classes)
					if ( isset( $field['class'] ) ) {
						$classes = array_filter( array_map( 'trim', explode( ' ', $field['class'] ) ) );
						$classes = array_diff( $classes, [ 'pvc-pro', 'pvc-pro-extended' ] );
						$field['class'] = implode( ' ', $classes );
					}

					// Remove disabled flag
					if ( isset( $field['disabled'] ) ) {
						$field['disabled'] = false;
					}

					// Remove skip_saving flag
					if ( isset( $field['skip_saving'] ) ) {
						$field['skip_saving'] = false;
					}
				} else {
					// Not available - only remove badge, keep disabled
					if ( isset( $field['class'] ) ) {
						$classes = array_filter( array_map( 'trim', explode( ' ', $field['class'] ) ) );
						$classes = array_diff( $classes, [ 'pvc-pro', 'pvc-pro-extended' ] );
						$field['class'] = implode( ' ', $classes );
					}
				}
			}
		}

		return $fields;
	}

	/**
	 * Check runtime availability for fields with dynamic requirements.
	 *
	 * @param string $field_key
	 * @param array $field
	 * @return bool
	 */
	private function check_field_availability( $field_key, $field ) {
		// Default to available (pure Pro licensing gate)
		$is_available = true;

		// Check specific fields with runtime requirements
		switch ( $field_key ) {
			case 'caching_compatibility':
				// Check if any caching plugins are active
				$active_plugins = $this->get_active_caching_plugins();
				$is_available = ! empty( $active_plugins );
				break;

			case 'object_cache':
				// Check if persistent object cache is available
				$is_available = wp_using_ext_object_cache();
				break;

			// Other fields are purely license-gated (no runtime requirements)
			default:
				$is_available = true;
				break;
		}

		return $is_available;
	}

	/**
	 * Backward compatibility for missing fields.
	 *
	 * @param array $settings
	 * @return array
	 */
	public function settings_fields_compat( $settings ) {
		if ( empty( $settings['post-views-counter']['fields'] ) )
			return $settings;

		$fields =& $settings['post-views-counter']['fields'];

		$canonical_fields = array_merge(
			$this->general->get_fields(),
			$this->display->get_fields(),
			$this->reports->get_fields(),
			$this->other->get_fields(),
			$this->integrations->get_fields()
		);

		$compat_fields = [
			'data_storage',
			'restrict_edit_views',
			'post_views_column',
			'count_time',
			'caching_compatibility',
			'counter_mode',
			'other_count'
		];

		$fallback_fields = [];

		foreach ( $compat_fields as $field_key ) {
			if ( empty( $fields[$field_key] ) || ! is_array( $fields[$field_key] ) ) {
				if ( isset( $canonical_fields[$field_key] ) && is_array( $canonical_fields[$field_key] ) ) {
					$fields[$field_key] = $canonical_fields[$field_key];
				} else if ( isset( $fallback_fields[$field_key] ) ) {
					$fields[$field_key] = $fallback_fields[$field_key];
				} else {
					$fields[$field_key] = [];
				}
			}
		}

		return $settings;
	}

	/**
	 * Add settings page.
	 *
	 * @param array $pages
	 *
	 * @return array
	 */
	public function settings_page( $pages ) {
		// get main instance
		$pvc = Post_Views_Counter();
		$menu_position = $pvc->get_menu_position();

		// default page
		$pages['post-views-counter'] = [
			'menu_slug'		=> 'post-views-counter',
			'page_title'	=> __( 'Post Views Counter', 'post-views-counter' ),
			'menu_title'	=> $menu_position === 'sub' ? __( 'Post Views Counter', 'post-views-counter' ) : __( 'Post Views', 'post-views-counter' ),
			'capability'	=> apply_filters( 'pvc_settings_capability', 'manage_options' ),
			'callback'		=> null,
			'tabs'			=> [
				'general'	 => [
					'label'			=> __( 'Counting', 'post-views-counter' ),
					'option_name'	=> 'post_views_counter_settings_general',
					'use_plugin_title' => true
				],
				'display'	 => [
					'label'			=> __( 'Display', 'post-views-counter' ),
					'option_name'	=> 'post_views_counter_settings_display',
					'use_plugin_title' => true
				],
				'reports'	=> [
					'label'			=> __( 'Reports', 'post-views-counter' ),
					'option_name'	=> 'post_views_counter_settings_reports',
					'use_plugin_title' => true
				],
				'integrations'	 => [
					'label'			=> __( 'Integrations', 'post-views-counter' ),
					'option_name'	=> 'post_views_counter_settings_integrations',
					'use_plugin_title' => true
				],
				'other'		=> [
					'label'			=> __( 'Other', 'post-views-counter' ),
					'option_name'	=> 'post_views_counter_settings_other',
					'use_plugin_title' => true
				]
			]
		];

		// update admin title
		add_filter( 'admin_title', [ $this, 'admin_title' ], 10, 2 );

		// submenu?
		if ( $menu_position === 'sub' ) {
			$pages['post-views-counter']['type'] = 'settings_page';
		// topmenu?
		} else {
			// highlight submenus
			add_filter( 'submenu_file', [ $this, 'submenu_file' ], 10, 2 );

			// add parameters
			$pages['post-views-counter']['type'] = 'page';
			$pages['post-views-counter']['icon'] = 'dashicons-chart-bar';
			$pages['post-views-counter']['position'] = '99.301';

			// add subpages
			$pages['post-views-counter-general'] = [
				'menu_slug'		=> 'post-views-counter',
				'parent_slug'	=> 'post-views-counter',
				'type'			=> 'subpage',
				'page_title'	=> __( 'Counting', 'post-views-counter' ),
				'menu_title'	=> __( 'Counting', 'post-views-counter' ),
				'capability'	=> apply_filters( 'pvc_settings_capability', 'manage_options' ),
				'callback'		=> null
			];

			$pages['post-views-counter-display'] = [
				'menu_slug'		=> 'post-views-counter&tab=display',
				'parent_slug'	=> 'post-views-counter',
				'type'			=> 'subpage',
				'page_title'	=> __( 'Display', 'post-views-counter' ),
				'menu_title'	=> __( 'Display', 'post-views-counter' ),
				'capability'	=> apply_filters( 'pvc_settings_capability', 'manage_options' ),
				'callback'		=> null
			];

			$pages['post-views-counter-reports'] = [
				'menu_slug'		=> 'post-views-counter&tab=reports',
				'parent_slug'	=> 'post-views-counter',
				'type'			=> 'subpage',
				'page_title'	=> __( 'Reports', 'post-views-counter' ),
				'menu_title'	=> __( 'Reports', 'post-views-counter' ),
				'capability'	=> apply_filters( 'pvc_settings_capability', 'manage_options' ),
				'callback'		=> null
			];

			$pages['post-views-counter-integrations'] = [
				'menu_slug'		=> 'post-views-counter&tab=integrations',
				'parent_slug'	=> 'post-views-counter',
				'type'			=> 'subpage',
				'page_title'	=> __( 'Integrations', 'post-views-counter' ),
				'menu_title'	=> self::mark_new( __( 'Integrations', 'post-views-counter' ) ),
				'capability'	=> apply_filters( 'pvc_settings_capability', 'manage_options' ),
				'callback'		=> null
			];

			$pages['post-views-counter-other'] = [
				'menu_slug'		=> 'post-views-counter&tab=other',
				'parent_slug'	=> 'post-views-counter',
				'type'			=> 'subpage',
				'page_title'	=> __( 'Other', 'post-views-counter' ),
				'menu_title'	=> __( 'Other', 'post-views-counter' ),
				'capability'	=> apply_filters( 'pvc_settings_capability', 'manage_options' ),
				'callback'		=> null
			];
		}

		// Backward compatibility: ensure legacy reports tab exists for older Pro versions.
		if ( ! isset( $pages['post-views-counter']['tabs'] ) || ! is_array( $pages['post-views-counter']['tabs'] ) )
			$pages['post-views-counter']['tabs'] = [];

		if ( ! isset( $pages['post-views-counter']['tabs']['reports'] ) || ! is_array( $pages['post-views-counter']['tabs']['reports'] ) ) {
			$pages['post-views-counter']['tabs']['reports'] = [
				'label'			=> __( 'Reports', 'post-views-counter' ),
				'option_name'		=> 'post_views_counter_settings_reports',
				'use_plugin_title'	=> true,
				'disabled'		=> true
			];
		}

		// Backward compatibility: allow to hook into old filter name
		if ( has_filter( 'post_views_counter_settings_pages' ) ) {
			$pages = apply_filters_deprecated(
				'post_views_counter_settings_pages',
				[ $pages ],
				'1.7.1',
				'pvc_settings_pages'
			);
		}

		return $pages;
	}

	/**
	 * Settings page CSS class(es).
	 *
	 * @param array $class
	 * @return array
	 */
	public function settings_page_class( $class ) {
		$is_pro = class_exists( 'Post_Views_Counter_Pro' );

		if ( ! $is_pro )
			$class[] = 'has-sidebar';

		return $class;
	}

	/**
	 * Highlight submenu items.
	 *
	 * @param string|null $submenu_file
	 * @param string $parent_file
	 *
	 * @return string|null
	 */
	public function submenu_file( $submenu_file, $parent_file ) {
		if ( $parent_file === 'post-views-counter' ) {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

			if ( $tab !== 'general' )
				return 'post-views-counter&tab=' . $tab;
		}

		return $submenu_file;
	}

	/**
	 * Update admin title.
	 *
	 * @global array $submenu
	 * @global string $pagenow
	 *
	 * @param string $admin_title
	 * @param string $title
	 *
	 * @return string
	 */
	public function admin_title( $admin_title, $title ) {
		global $submenu, $pagenow;

		// get main instance
		$pvc = Post_Views_Counter();
		$menu_position = $pvc->get_menu_position();

		if ( isset( $_GET['page'] ) && $_GET['page'] === 'post-views-counter' ) {
			if ( $menu_position === 'sub' && $pagenow === 'options-general.php' ) {
				// get tab
				$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

				// get settings pages
				$pages = $pvc->settings_api->get_pages();

				if ( array_key_exists( $tab, $pages['post-views-counter']['tabs'] ) ) {
					// update title
					$admin_title = preg_replace( '/' . $pages['post-views-counter']['page_title'] . '/', $pages['post-views-counter']['page_title'] . ' - ' . $pages['post-views-counter']['tabs'][$tab]['label'], $admin_title, 1 );
				}
			} else if ( $menu_position === 'top' && get_admin_page_parent() === 'post-views-counter' && ! empty( $submenu['post-views-counter'] ) ) {
				// get tab
				$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

				// get settings pages
				$pages = $pvc->settings_api->get_pages();

				if ( array_key_exists( 'post-views-counter-' . $tab, $pages ) ) {
					// update title
					$admin_title = $pages['post-views-counter']['page_title'] . ' - ' . preg_replace( '/' . $title . '/', $pages['post-views-counter-' . $tab]['page_title'], $admin_title, 1 );
				}
			}
		}

		return $admin_title;
	}

	/**
	 * Validate options.
	 *
	 * @global object $wpdb
	 *
	 * @param array $input
	 *
	 * @return array
	 */
	public function validate_settings( $input ) {
		// check capability
		if ( ! current_user_can( 'manage_options' ) )
			return $input;

		global $wpdb;

		// get main instance
		$pvc = Post_Views_Counter();

		// map exclude array to separate fields before validation (fields post as exclude[groups]/exclude[roles])
		if ( isset( $input['exclude'] ) && is_array( $input['exclude'] ) ) {
			if ( isset( $input['exclude']['groups'] ) )
				$input['exclude_groups'] = $input['exclude']['groups'];

			if ( isset( $input['exclude']['roles'] ) )
				$input['exclude_roles'] = $input['exclude']['roles'];
		}

		// map restrict_display array to separate fields before validation (fields post as restrict_display[groups]/restrict_display[roles])
		if ( isset( $input['restrict_display'] ) && is_array( $input['restrict_display'] ) ) {
			if ( isset( $input['restrict_display']['groups'] ) )
				$input['restrict_display_groups'] = $input['restrict_display']['groups'];

			if ( isset( $input['restrict_display']['roles'] ) )
				$input['restrict_display_roles'] = $input['restrict_display']['roles'];
		}

		// use internal settings api to validate settings first
		$input = $pvc->settings_api->validate_settings( $input );

		// merge exclude fields for backward compatibility
		if ( isset( $input['exclude_groups'] ) || isset( $input['exclude_roles'] ) ) {
			$input['exclude'] = [
				'groups' => isset( $input['exclude_groups'] ) ? $input['exclude_groups'] : [],
				'roles' => isset( $input['exclude_roles'] ) ? $input['exclude_roles'] : []
			];
		}

		// merge restrict display fields for backward compatibility
		if ( isset( $input['restrict_display_groups'] ) || isset( $input['restrict_display_roles'] ) ) {
			$input['restrict_display'] = [
				'groups' => isset( $input['restrict_display_groups'] ) ? $input['restrict_display_groups'] : [],
				'roles' => isset( $input['restrict_display_roles'] ) ? $input['restrict_display_roles'] : []
			];
			unset( $input['restrict_display_groups'], $input['restrict_display_roles'] );
		}

		// handle new provider-based import/analyse
		if ( isset( $_POST['post_views_counter_import_views'] ) || isset( $_POST['post_views_counter_analyse_views'] ) ) {
			// make sure we do not change anything in the settings
			$input = $pvc->options['other'];

			// delegate to import class
			$result = $pvc->import->handle_manual_action( $_POST );

			if ( isset( $result['message'] ) ) {
				add_settings_error( 'pvc_' . ( isset( $_POST['post_views_counter_analyse_views'] ) ? 'analyse' : 'import' ), 'pvc_' . ( isset( $_POST['post_views_counter_analyse_views'] ) ? 'analyse' : 'import' ), $result['message'], isset( $result['type'] ) ? $result['type'] : 'updated' );
			}

			if ( isset( $result['provider_settings'] ) ) {
				$input['import_provider_settings'] = $result['provider_settings'];
			}

			return $input;
		// delete all post views data
		} elseif ( isset( $_POST['post_views_counter_reset_views'] ) ) {
			// make sure we do not change anything in the settings
			$input = $pvc->options['other'];

			if ( $wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'post_views' ) )
				add_settings_error( 'reset_post_views', 'reset_post_views', __( 'All existing data deleted successfully.', 'post-views-counter' ), 'updated' );
			else
				add_settings_error( 'reset_post_views', 'reset_post_views', __( 'Error occurred. All existing data were not deleted.', 'post-views-counter' ), 'error' );
		// save general settings
		} elseif ( isset( $_POST['save_post_views_counter_settings_general'] ) ) {
			$input['update_version'] = $pvc->options['general']['update_version'];
			$input['update_notice'] = $pvc->options['general']['update_notice'];
			$input['update_delay_date'] = $pvc->options['general']['update_delay_date'];
		// reset general settings
		} elseif ( isset( $_POST['reset_post_views_counter_settings_general'] ) ) {
			$input['update_version'] = $pvc->options['general']['update_version'];
			$input['update_notice'] = $pvc->options['general']['update_notice'];
			$input['update_delay_date'] = $pvc->options['general']['update_delay_date'];
		// save other settings (handle provider inputs)
		} elseif ( isset( $_POST['save_post_views_counter_settings_other'] ) ) {
			$input['import_provider_settings'] = $pvc->import->prepare_provider_settings_from_request( $_POST );

			// keep menu position for backward compatibility with older add-ons expecting it under "other" settings
			if ( ! isset( $input['menu_position'] ) ) {
				$input['menu_position'] = $pvc->get_menu_position();
			}
		// save integrations settings
		} elseif ( isset( $_POST['save_post_views_counter_settings_integrations'] ) ) {
			// ensure integrations array exists
			if ( ! isset( $input['integrations'] ) ) {
				$input['integrations'] = [];
			}

			// get all known integrations
			$known_integrations = array_keys( Post_Views_Counter_Integrations::get_base_integrations() );

			// preserve unknown slugs from existing settings
			$existing = $pvc->options['integrations']['integrations'];
			foreach ( $existing as $slug => $status ) {
				if ( ! in_array( $slug, $known_integrations, true ) ) {
					$input['integrations'][$slug] = $status;
				}
			}

			// set missing known integrations to false (unchecked boxes don't submit)
			foreach ( $known_integrations as $slug ) {
				if ( ! isset( $input['integrations'][$slug] ) ) {
					$input['integrations'][$slug] = false;
				}
			}
		}

		return $input;
	}

	/**
	 * Register core PVC database tables for status checking.
	 *
	 * @param array $tables Existing table definitions
	 * @return array
	 */
	public function register_core_tables( $tables ) {
		$tables[] = [
			'name' => 'post_views',
			'label' => 'post_views'
		];

		return $tables;
	}

	/**
	 * Backward compatibility for section IDs.
	 *
	 * @param array $settings
	 * @return array
	 */
	public function settings_sections_compat( $settings ) {
		if ( empty( $settings['post-views-counter']['fields'] ) )
			return $settings;

		$fields =& $settings['post-views-counter']['fields'];

		$compat_sections = [
			'technology_count' => [
				'legacy' => 'post_views_counter_general_settings',
				'current' => 'post_views_counter_general_tracking_targets'
			],
			'post_views_column' => [
				'legacy' => 'post_views_counter_display_settings',
				'current' => 'post_views_counter_display_admin'
			],
			'restrict_edit_views' => [
				'legacy' => 'post_views_counter_display_settings',
				'current' => 'post_views_counter_display_admin'
			],
			'menu_position' => [
				'legacy' => 'post_views_counter_other_management',
				'current' => 'post_views_counter_display_admin'
			]
		];

		foreach ( $compat_sections as $field => $map ) {
			if ( empty( $fields[$field] ) )
				continue;

			if ( isset( $fields[$field]['section'] ) && $fields[$field]['section'] === $map['legacy'] )
				$fields[$field]['section'] = $map['current'];
		}

		return $settings;
	}

	/**
	 * Mark menu item as new.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function mark_new( $text ) {
		return sprintf(
			'%s<span class="pvc-admin-menu-new">&nbsp;%s</span>',
			$text,
			__( 'NEW!', 'post-views-counter' )
		);
	}
}
