<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Settings_Display class.
 *
 * @class Post_Views_Counter_Settings_Display
 */
class Post_Views_Counter_Settings_Display {

	private $pvc;
	private static $syncing_menu_position = false;

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->pvc = Post_Views_Counter();

		// actions
		add_action( 'update_option_post_views_counter_settings_display', [ $this, 'sync_menu_position_option' ], 10, 3 );
		add_action( 'add_option_post_views_counter_settings_display', [ $this, 'sync_menu_position_option_on_add' ], 10, 2 );

		// filters
		add_filter( 'pre_update_option_post_views_counter_settings_other', [ $this, 'sync_menu_position_from_other' ], 10, 2 );
	}

	/**
	 * Get sections for display tab.
	 *
	 * @return array
	 */
	public function get_sections() {
		return [
			'post_views_counter_display_appearance' => [
				'tab'      => 'display',
				'title'    => __( 'Counter Appearance', 'post-views-counter' ),
				'callback' => [ $this, 'section_display_appearance' ],
			],
			'post_views_counter_display_locations' => [
				'tab'   => 'display',
				'title' => __( 'Display Targets', 'post-views-counter' ),
				'callback' => [ $this, 'section_display_targets' ],
			],
			'post_views_counter_display_visibility' => [
				'tab'   => 'display',
				'title' => __( 'Display Audience', 'post-views-counter' ),
				'callback' => [ $this, 'section_display_audience' ],
			],
			'post_views_counter_display_admin' => [
				'tab'      => 'display',
				'title'    => __( 'Admin Interface', 'post-views-counter' ),
				'callback' => [ $this, 'section_display_admin' ],
			]
		];
	}

	/**
	 * Get fields for display tab.
	 *
	 * @return array
	 */
	public function get_fields() {
		// get post types
		$post_types = $this->pvc->functions->get_post_types();

		// user groups
		$groups = [
			'users'		=> __( 'logged in users', 'post-views-counter' ),
			'guests'	=> __( 'guests', 'post-views-counter' ),
			'roles'		=> __( 'selected user roles', 'post-views-counter' )
		];

		// get user roles
		$user_roles = $this->pvc->functions->get_user_roles();

		return [
			'label' => [
				'tab'			=> 'display',
				'title'			=> __( 'Views Label', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_appearance',
				'type'			=> 'input',
				'html_id'		=> 'pvc-label',
				'description'	=> __( 'Text shown next to the view count.', 'post-views-counter' ),
				'subclass'		=> 'regular-text',
				'validate'		=> [ $this, 'validate_label' ],
				'reset'			=> [ $this, 'reset_label' ]
			],
			'display_period' => [
				'tab'			=> 'display',
				'title'			=> __( 'Views Period', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_appearance',
				'type'			=> 'select',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'description'	=> __( 'Time range used when displaying the number of views.', 'post-views-counter' ),
				'pro_only'		=> true,
				'options'		=> 	[
					'total'		=> __( 'Total Views', 'post-views-counter' )
				]
			],
			'display_style' => [
				'tab'			=> 'display',
				'title'			=> __( 'Display Style', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_appearance',
				'type'			=> 'custom',
				'html_id'		=> 'pvc-display-style',
				'description'	=> __( 'Choose whether to show an icon, label text, or both.', 'post-views-counter' ),
				'callback'		=> [ $this, 'setting_display_style' ],
				'validate'		=> [ $this, 'validate_display_style' ],
				'options'		=> [
					'icon'	=> __( 'Icon', 'post-views-counter' ),
					'text'	=> __( 'Label', 'post-views-counter' )
				]
			],
			'icon_class' => [
				'tab'			=> 'display',
				'title'			=> __( 'Icon Class', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_appearance',
				'type'			=> 'class',
				'default'		=> '',
				'html_id'		=> 'pvc-icon-class',
				'parent'		=> 'pvc-display-style',
				'logic'			=> [
					'field'		=> 'pvc-display-style-icon',
					'operator'	=> 'is',
					'value'		=> 'icon'
				],
				'animation'		=> 'slide',
				'description'	=> sprintf( __( 'Enter the CSS class for the views icon. Any Dashicons class is supported.', 'post-views-counter' ), 'https://developer.wordpress.org/resource/dashicons/' ),
				'subclass'		=> 'regular-text'
			],
			'position' => [
				'tab'			=> 'display',
				'title'			=> __( 'Position', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_locations',
				'type'			=> 'select',
				'html_id'		=> 'pvc-position',
				'description'	=> sprintf( __( 'Where to insert the counter automatically. Use %s shortcode for manual placement.', 'post-views-counter' ), '<code>[post-views]</code>' ),
				'options'		=> [
					'before'	=> __( 'Before the content', 'post-views-counter' ),
					'after'		=> __( 'After the content', 'post-views-counter' ),
					'manual'	=> __( 'Manual only', 'post-views-counter' )
				]
			],
			'post_views_column' => [
				'tab'			=> 'display',
				'title'			=> __( 'Admin Column', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_admin',
				'type'			=> 'boolean',
				'description'	=> '',
				'label'			=> __( 'Show a "Views" column on post and page list screens.', 'post-views-counter' )
			],
			'restrict_edit_views' => [
				'tab'			=> 'display',
				'title'			=> __( 'Admin Edit', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_admin',
				'type'			=> 'boolean',
				'description'	=> '',
				'label'			=> __( 'Allow editing the view count on the post edit screen.', 'post-views-counter' )
			],
			'dynamic_loading' => [
				'tab'			=> 'display',
				'title'			=> __( 'Dynamic Loading', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_appearance',
				'type'			=> 'boolean',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'value'			=> false,
				'label'			=> __( 'Load the view count dynamically to avoid caching the displayed value.', 'post-views-counter' ),
				'pro_only'		=> true
			],
			'use_format' => [
				'tab'			=> 'display',
				'title'			=> __( 'Format Number', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_appearance',
				'type'			=> 'boolean',
				'label'			=> __( 'Format the view count according to the site locale (uses the WordPress number_format_i18n function).', 'post-views-counter' )
			],
			'taxonomies' => [
				'tab'			=> 'display',
				'title'			=> __( 'Taxonomies', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_locations',
				'type'			=> 'boolean',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'value'			=> false,
				'label'			=> __( 'Display the view count on category, tag, or custom taxonomy archive pages.', 'post-views-counter' ),
				'pro_only'		=> true
			],
			'taxonomies_display' => [
				'tab'			=> 'display',
				'section'		=> 'post_views_counter_display_locations',
				'type'			=> 'checkbox',
				'class'			=> 'pvc-pro',
				'description'	=> __( 'Select taxonomies where the view counter will be displayed.', 'post-views-counter' ),			'skip_saving'	=> true,
				'options'		=> $this->pvc->functions->get_taxonomies( 'labels' ),
				'disabled'		=> true,
				'name'			=> 'post_views_counter_settings_display[taxonomies_display]',
				'value'			=> $this->pvc->options['display']['taxonomies_display'],
				'pro_only'		=> true,
				'logic'			=> [
					'field'		=> 'taxonomies',
					'operator'	=> 'is',
					'value'		=> 'true'
				],
				'animation'		=> 'slide'
			],
			'user_display' => [
				'tab'			=> 'display',
				'title'			=> __( 'Author Archives', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_locations',
				'type'			=> 'boolean',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'value'			=> false,
				'label'			=> __( 'Display the view count on author archive pages.', 'post-views-counter' ),
				'pro_only'		=> true
			],
			'post_types_display' => [
				'tab'			=> 'display',
				'title'			=> __( 'Post Types', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_locations',
				'type'			=> 'checkbox',
				'description'	=> __( 'Select post types where the view counter will be displayed.', 'post-views-counter' ),
				'options'		=> $post_types
			],
			'page_types_display' => [
				'tab'			=> 'display',
				'title'			=> __( 'Page Type', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_locations',
				'type'			=> 'checkbox',
				'description'	=> __( 'Select page contexts where the view counter will be displayed.', 'post-views-counter' ),
				'options'		=> apply_filters(
					'pvc_page_types_display_options',
					[
						'home'		=> __( 'Home', 'post-views-counter' ),
						'archive'	=> __( 'Archives', 'post-views-counter' ),
						'singular'	=> __( 'Single posts and pages', 'post-views-counter' ),
						'search'	=> __( 'Search results', 'post-views-counter' ),
					]
				)
			],
			'restrict_display_groups' => [
				'tab' => 'display',
				'title' => __( 'User Type', 'post-views-counter' ),
				'section' => 'post_views_counter_display_visibility',
				'type' => 'checkbox',
				'description' => __( 'Hide the view counter for selected visitor groups.', 'post-views-counter' ),
				'options' => $groups,
				'name' => 'post_views_counter_settings_display[restrict_display][groups]',
				'value' => $this->pvc->options['display']['restrict_display']['groups'],
				'validate' => [ $this, 'validate_restrict_display_groups' ],
			],
			'restrict_display_roles' => [
				'tab' => 'display',
				'title' => __( 'User Roles', 'post-views-counter' ),
				'section' => 'post_views_counter_display_visibility',
				'type' => 'checkbox',
				'description' => __( 'Hide the view counter for selected user roles.', 'post-views-counter' ),
				'options' => $user_roles,
				'name' => 'post_views_counter_settings_display[restrict_display][roles]',
				'value' => $this->pvc->options['display']['restrict_display']['roles'],
				'logic' => [ [ 'field' => 'restrict_display_groups', 'operator' => 'contains', 'value' => 'roles' ] ],
				'animation' => 'slide',
				'validate' => [ $this, 'validate_restrict_display_roles' ],
			],
			'toolbar_statistics' => [
				'tab'			=> 'display',
				'title'			=> __( 'Toolbar Chart', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_admin',
				'type'			=> 'boolean',
				'description'	=> __( 'A views chart is shown for content types that are being counted.', 'post-views-counter' ),
				'label'			=> __( 'Show a views chart in the admin toolbar.', 'post-views-counter' )
			],
			'menu_position' => [
				'tab'			=> 'display',
				'title'			=> __( 'Menu Position', 'post-views-counter' ),
				'section'		=> 'post_views_counter_display_admin',
				'type'			=> 'radio',				'html_id'		=> 'pvc-menu-position',				'options'		=> [
					'top'	=> __( 'Top menu', 'post-views-counter' ),
					'sub'	=> __( 'Settings submenu', 'post-views-counter' )
				],
				'description'	=> __( 'Choose where the plugin menu appears in the admin sidebar.', 'post-views-counter' ),
			]
		];
	}

	/**
	 * Validate label.
	 *
	 * @param array $input
	 * @param array $field
	 * @return array
	 */
	public function validate_label( $input, $field ) {
		if ( ! isset( $input ) )
			$input = $this->pvc->defaults['display']['label'];

		// use internal settings API to validate settings first
		$input = $this->pvc->settings_api->validate_field( $input, 'input', $field );

		if ( function_exists( 'icl_register_string' ) )
			icl_register_string( 'Post Views Counter', 'Post Views Label', $input );

		return $input;
	}

	/**
	 * Restore post views label to default value.
	 *
	 * @param array $default
	 * @param array $field
	 * @return array
	 */
	public function reset_label( $default, $field ) {
		if ( function_exists( 'icl_register_string' ) )
			icl_register_string( 'Post Views Counter', 'Post Views Label', $default );

		return $default;
	}

	/**
	 * Setting: display style.
	 *
	 * @param array $field
	 * @return string
	 */
	public function setting_display_style( $field ) {
		$html = '<div class="pvc-field-group pvc-checkbox-group">';
		$html .= '<input type="hidden" name="post_views_counter_settings_display[display_style]" value="empty" />';
		
		foreach ( $field['options'] as $key => $label ) {
			$html .= '
			<label><input id="pvc-display-style-' . esc_attr( $key ) . '" type="checkbox" name="post_views_counter_settings_display[display_style][]" value="' . esc_attr( $key ) . '" ' . checked( ! empty( $this->pvc->options['display']['display_style'][$key] ), true, false ) . ' />' . esc_html( $label ) . '</label> ';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Validate display style.
	 *
	 * @param array $input
	 * @param array $field
	 * @return array
	 */
	public function validate_display_style( $input, $field ) {
		$data = [];

		foreach ( $field['options'] as $value => $label ) {
			$data[$value] = false;
		}

		// any data?
		if ( ! empty( $input['display_style'] && $input['display_style'] !== 'empty' && is_array( $input['display_style'] ) ) ) {
			foreach ( $input['display_style'] as $value ) {
				if ( array_key_exists( $value, $field['options'] ) )
					$data[$value] = true;
			}
		}

		$input['display_style'] = $data;

		return $input;
	}

	/**
	 * Validate user type.
	 *
	 * @param array $input
	 * @param array $field
	 *
	 * @return array
	 */
	public function validate_restrict_display_groups( $input, $field ) {
		$groups = [];

		if ( is_array( $input ) ) {
			foreach ( $input as $group ) {
				// sanitize value
				$group = sanitize_key( $group );

				if ( $group === 'robots' || $group === 'ai_bots' )
					continue;

				if ( isset( $field['options'][$group] ) )
					$groups[] = $group;
			}
		}

		return array_unique( $groups );
	}

	public function validate_restrict_display_roles( $input, $field ) {
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
	 * Section description: display targets.
	 *
	 * @return void
	 */
	public function section_display_targets() {
		echo '<p class="description">' . esc_html__( 'Choose where the counter is inserted and which content types it attaches to.', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Section description: display audience.
	 *
	 * @return void
	 */
	public function section_display_audience() {
		echo '<p class="description">' . esc_html__( 'Control which visitor groups can see the counter. These rules apply on top of the display targets.', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Section description: counter appearance.
	 *
	 * @return void
	 */
	public function section_display_appearance() {
		echo '<p class="description">' . esc_html__( 'Adjust the label, period, icon, and formatting used when the counter is rendered.', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Section description: admin interface.
	 *
	 * @return void
	 */
	public function section_display_admin() {
		echo '<p class="description">' . esc_html__( 'Control how view counts are shown and managed in WordPress admin (columns, edit permissions, toolbar chart).', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Mirror the saved menu position to legacy storage.
	 *
	 * @param mixed $old_value
	 * @param mixed $value
	 * @param string $option
	 * @return void
	 */
	public function sync_menu_position_option( $old_value, $value, $option ) {
		if ( self::$syncing_menu_position )
			return;

		self::$syncing_menu_position = true;
		$this->mirror_menu_position_value( $value );
		self::$syncing_menu_position = false;
	}

	/**
	 * Mirror the saved menu position when the option is added.
	 *
	 * @param string $option
	 * @param mixed $value
	 * @return void
	 */
	public function sync_menu_position_option_on_add( $option, $value ) {
		if ( self::$syncing_menu_position )
			return;

		self::$syncing_menu_position = true;
		$this->mirror_menu_position_value( $value );
		self::$syncing_menu_position = false;
	}

	/**
	 * Update the legacy menu position value stored under "Other" settings.
	 *
	 * @param mixed $value
	 * @return void
	 */
	private function mirror_menu_position_value( $value ) {
		if ( ! is_array( $value ) )
			return;

		$menu_position = isset( $value['menu_position'] ) && in_array( $value['menu_position'], [ 'top', 'sub' ], true ) ? $value['menu_position'] : 'top';
		$other_options = get_option( 'post_views_counter_settings_other', [] );

		if ( ! is_array( $other_options ) )
			$other_options = [];

		if ( ! isset( $other_options['menu_position'] ) || $other_options['menu_position'] !== $menu_position ) {
			$other_options['menu_position'] = $menu_position;
			update_option( 'post_views_counter_settings_other', $other_options );
		}

		$this->pvc->options['other']['menu_position'] = $menu_position;
	}

	/**
	 * Sync menu position from legacy writes to "Other" settings.
	 *
	 * @param mixed $value
	 * @param mixed $old_value
	 * @return mixed
	 */
	public function sync_menu_position_from_other( $value, $old_value ) {
		if ( self::$syncing_menu_position || ! is_array( $value ) || ! isset( $value['menu_position'] ) )
			return $value;

		$menu_position = in_array( $value['menu_position'], [ 'top', 'sub' ], true ) ? $value['menu_position'] : 'top';

		self::$syncing_menu_position = true;

		$display_options = get_option( 'post_views_counter_settings_display', [] );

		if ( ! is_array( $display_options ) )
			$display_options = [];

		if ( ! isset( $display_options['menu_position'] ) || $display_options['menu_position'] !== $menu_position ) {
			$display_options['menu_position'] = $menu_position;
			update_option( 'post_views_counter_settings_display', $display_options );
		}

		$this->pvc->options['display']['menu_position'] = $menu_position;

		self::$syncing_menu_position = false;

		return $value;
	}
}