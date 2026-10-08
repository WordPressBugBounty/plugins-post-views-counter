<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Frontend class.
 *
 * @class Post_Views_Counter_Frontend
 */
class Post_Views_Counter_Frontend {

	private $script_args = [];

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'after_setup_theme', [ $this, 'register_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'wp_enqueue_scripts' ], 1 );
		add_action( 'wp', [ $this, 'run' ] );
	}

	/**
	 * Register post-views shortcode function.
	 *
	 * @return void
	 */
	public function register_shortcode() {
		add_shortcode( 'post-views', [ $this, 'post_views_shortcode' ] );
	}

	/**
	 * Post views shortcode function.
	 *
	 * @param array $args
	 *
	 * @return string
	 */
	public function post_views_shortcode( $args ) {
		$html = '';
		$options = Post_Views_Counter()->options['display'];
		$raw_args = is_array( $args ) ? $args : [];

		$defaults = [
			'id'		=> get_the_ID(),
			'type'		=> 'post',
			'period'	=> $options['display_period'],
			'format'	=> null
		];

		// combine attributes
		$atts = shortcode_atts( $defaults, $raw_args );
		$atts = apply_filters( 'pvc_post_views_shortcode_atts', $atts, $raw_args, $defaults );

		if ( ! is_array( $atts ) )
			$atts = [];

		$type = isset( $atts['type'] ) && is_scalar( $atts['type'] ) ? sanitize_key( (string) $atts['type'] ) : '';
		$period = isset( $atts['period'] ) && is_scalar( $atts['period'] ) ? sanitize_key( (string) $atts['period'] ) : '';
		$atts['type'] = $type;
		$atts['period'] = $period;
		$format_present = array_key_exists( 'format', $atts ) && $atts['format'] !== null;
		$post_id = isset( $atts['id'] ) && is_scalar( $atts['id'] ) && ctype_digit( (string) $atts['id'] ) ? (int) $atts['id'] : 0;
		$atts['id'] = $post_id;

		// default type?
		if ( $type === 'post' && $post_id > 0 && get_post( $post_id ) instanceof WP_Post ) {
			$render_args = [];

			if ( $format_present )
				$render_args['format'] = $atts['format'];

			$html = function_exists( 'pvc_post_views' ) ? pvc_post_views( $post_id, false, $period, $render_args ) : '';
		}

		return apply_filters( 'pvc_post_views_shortcode', $html, $atts );
	}

	/**
	 * Render a complete custom metric template without parsing it as a shortcode.
	 *
	 * %%views%% renders the count and %%icon%% the counter icon. The icon is
	 * placed by the template alone and is not a metric, so a template without
	 * %%views%% still renders nothing.
	 *
	 * @param string        $format Template HTML.
	 * @param int           $content_id Content ID.
	 * @param string        $content_type Content type.
	 * @param string        $period Display period.
	 * @param callable      $views_renderer Views token renderer. It receives whether the template has visible words of its own, so the count can leave out its hidden label.
	 * @param callable|null $icon_renderer Icon token renderer; null uses the counter icon without a content-type icon filter.
	 * @return string
	 */
	public function render_template( $format, $content_id, $content_type, $period, $views_renderer, $icon_renderer = null ) {
		if ( ! is_scalar( $format ) || ! is_callable( $views_renderer ) )
			return '';

		$tag_pattern = self::get_template_tag_pattern();
		$format = preg_replace_callback(
			$tag_pattern,
			static function ( $matches ) {
				return preg_replace_callback(
					'/(["\'])(.*?)\1/',
					static function ( $attribute ) {
						return $attribute[1] . str_replace( '>', '&gt;', $attribute[2] ) . $attribute[1];
					},
					$matches[0]
				);
			},
			(string) $format
		);
		$format = wp_kses_post( $format );
		$parts = preg_split( $tag_pattern, $format, -1, PREG_SPLIT_DELIM_CAPTURE );
		$token_pattern = '/%%(views|icon)%%/';
		$metrics = self::get_template_text_metrics( $format );

		if ( empty( $metrics ) )
			return '';

		if ( ! is_callable( $icon_renderer ) ) {
			$frontend = $this;
			$icon_renderer = static function () use ( $frontend, $content_id, $content_type ) {
				return $frontend->get_counter_icon( 'views', $content_id, $content_type );
			};
		}

		// A template that says "Views:" or "reads" itself already gives the count
		// its context, and a hidden label would make a screen reader announce it
		// twice. Only visible letters count: tokens, tags and attributes do not.
		$text = '';

		foreach ( $parts as $index => $part ) {
			if ( $index % 2 === 0 )
				$text .= preg_replace( $token_pattern, ' ', $part );
		}

		$has_text = preg_match( '/\p{L}/u', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) === 1;

		// the icon is resolved only when the template places it, so its filters
		// never run for a template without %%icon%%
		$fragments = [ 'views' => (string) call_user_func( $views_renderer, $has_text ) ];

		foreach ( $parts as $index => $part ) {
			if ( $index % 2 === 0 ) {
				$parts[$index] = preg_replace_callback(
					$token_pattern,
					static function ( $matches ) use ( &$fragments, $icon_renderer ) {
						// the template owns the spacing around the icon
						if ( ! isset( $fragments[$matches[1]] ) )
							$fragments[$matches[1]] = rtrim( (string) call_user_func( $icon_renderer ) );

						return $fragments[$matches[1]];
					},
					$part
				);
			}
		}

		$class = 'pvc-custom-format content-' . $content_type . ' ' . $content_type . '-' . $content_id . ' entry-meta';
		$html = '<div class="' . esc_attr( $class ) . '" data-pvc-type="' . esc_attr( $content_type ) . '" data-pvc-id="' . esc_attr( $content_id ) . '" data-pvc-period="' . esc_attr( $period ) . '">' . implode( '', $parts ) . '</div>';
		$html = apply_filters( 'pvc_frontend_template_html', $html, $format, $content_id, $content_type, $period, $metrics );

		return wp_kses( $html, Post_Views_Counter()->functions->get_counter_allowed_html() );
	}

	/**
	 * Get the metric tokens present in the text parts of a sanitized template.
	 *
	 * Tokens in HTML attributes are not rendered and therefore are not metrics.
	 *
	 * @param string $format Sanitized template HTML.
	 * @return array
	 */
	public static function get_template_text_metrics( $format ) {
		if ( ! is_scalar( $format ) )
			return [];

		$parts = preg_split( self::get_template_tag_pattern(), (string) $format, -1, PREG_SPLIT_DELIM_CAPTURE );
		$metrics = [];
		$token_pattern = '/%%(views)%%/';

		foreach ( $parts as $index => $part ) {
			if ( $index % 2 !== 0 || preg_match_all( $token_pattern, $part, $matches ) === false )
				continue;

			foreach ( $matches[1] as $metric ) {
				if ( ! in_array( $metric, $metrics, true ) )
					$metrics[] = $metric;
			}
		}

		return $metrics;
	}

	/**
	 * Get the HTML-tag pattern used to separate rendered text nodes.
	 *
	 * @return string
	 */
	private static function get_template_tag_pattern() {
		return '/(<(?:"[^"]*"|\'[^\']*\'|[^\'">])*>)/';
	}

	/**
	 * Get the validated and translated saved frontend template.
	 *
	 * @return string
	 */
	public function get_frontend_template() {
		$pvc = Post_Views_Counter();
		$default = $pvc->defaults['display']['frontend_template'];
		$source = isset( $pvc->options['display']['frontend_template'] ) && is_scalar( $pvc->options['display']['frontend_template'] ) ? (string) $pvc->options['display']['frontend_template'] : $default;

		if ( function_exists( 'icl_t' ) ) {
			$translated = icl_t( 'Post Views Counter', 'Frontend Template', $source );
			$source = is_scalar( $translated ) ? (string) $translated : $source;
		}

		return wp_kses_post( $source );
	}

	/**
	 * Get the counter icon HTML for a metric.
	 *
	 * The icon is a CSS-masked glyph painted on an empty span, so the counter
	 * never loads the Dashicons font. Returning a class from
	 * 'pvc_counter_icon_class' replaces our glyph with that class instead; the
	 * stored 'icon_class' setting no longer takes part in rendering.
	 *
	 * @param string $metric Metric name; always 'views'.
	 * @param int    $content_id Content ID.
	 * @param string $content_type Content type.
	 * @param string $icon_filter Metric-specific filter applied to the icon HTML.
	 * @return string
	 */
	public function get_counter_icon( $metric = 'views', $content_id = 0, $content_type = 'post', $icon_filter = '' ) {
		$metric = 'views';
		$content_id = (int) $content_id;
		$content_type = sanitize_key( (string) $content_type );

		// a custom class is the only supported way to replace the built-in glyph
		$class = apply_filters( 'pvc_counter_icon_class', '', $metric, $content_id, $content_type );
		$class = is_scalar( $class ) ? Post_Views_Counter()->functions->sanitize_html_class_list( (string) $class ) : '';

		if ( $class !== '' )
			$default = '<span class="pvc-icon pvc-icon-custom ' . esc_attr( $class ) . '" aria-hidden="true"></span> ';
		else
			$default = '<span class="pvc-icon" aria-hidden="true"></span> ';

		// the released metric-specific filters keep their markup-level authority
		if ( is_string( $icon_filter ) && $icon_filter !== '' ) {
			$icon = apply_filters( $icon_filter, $default, $content_id, $content_type );
			$icon = is_scalar( $icon ) ? (string) $icon : '';
		} else
			$icon = $default;

		$icon = apply_filters( 'pvc_counter_icon', $icon, $metric, $content_id, $content_type );

		return is_scalar( $icon ) ? (string) $icon : '';
	}

	/**
	 * Render a single metric target.
	 *
	 * The single place a Views target is built, for every content type and render
	 * path. Preset roots may be divs; custom tokens are spans. Each target carries
	 * its loading/data identity and contains its own count element so current and
	 * legacy extension loaders can consume it.
	 *
	 * @param string $metric Metric name; only 'views' is rendered.
	 * @param int    $content_id Content ID.
	 * @param string $content_type Content type.
	 * @param string $period Display period.
	 * @param array  $args Optional per-caller overrides.
	 * @param array|null $resolved Resolved value, label and icon output.
	 * @return string
	 */
	public function render_metric_span( $metric, $content_id, $content_type, $period, $args = [], &$resolved = null ) {
		$resolved = [ 'value' => null, 'label' => '', 'icon' => '' ];

		if ( $metric !== 'views' )
			return '';

		$options = Post_Views_Counter()->options['display'];
		$content_id = (int) $content_id;
		$content_type = sanitize_key( (string) $content_type );
		$period = sanitize_key( (string) $period );
		$supplied = is_array( $args ) ? $args : [];

		$args = array_merge(
			[
				'base_class'			=> 'post-views content-' . $content_type . ' ' . $content_type . '-' . $content_id . ' entry-meta',
				'class_filter'			=> 'pvc_post_views_class',
				'label_filter'			=> 'pvc_post_views_label',
				'icon_filter'			=> 'pvc_post_views_icon',
				'html_filter'			=> '',
				'number_format_filter'	=> 'pvc_post_views_number_format',
				'wpml_context'			=> 'Post Views Counter',
				'wpml_name'				=> 'Post Views Label',
				'wpml_fallbacks'		=> [],
				'inline_icon'			=> false,
				'force_sr_label'		=> false,
				// a custom format with words of its own needs no label, visible or hidden
				'omit_label'			=> false,
				// null follows the dynamic_loading option; false forces a static span for
				// content that is not a dynamic target, such as site-wide totals
				'dynamic'				=> null,
				// a pre-resolved icon, so a container that already rendered one does not
				// fire the icon filter a second time
				'icon'					=> null,
				// an explicit label bypasses the option and WPML lookup entirely
				'label'					=> null,
				// preset output uses a div root; custom tokens use spans
				'tag'					=> 'span',
				'before'				=> '',
				'after'					=> ''
			],
			$supplied
		);

		// resolve the value
		if ( array_key_exists( 'value', $supplied ) )
			$views = $supplied['value'];
		else {
			$getter = 'pvc_get_' . $content_type . '_views';
			$views = function_exists( $getter ) ? call_user_func( $getter, $content_id, $period ) : 0;
		}

		$value = ! empty( $options['use_format'] ) ? number_format_i18n( $views ) : $views;

		if ( $args['number_format_filter'] !== '' )
			$value = apply_filters( $args['number_format_filter'], $value, $content_id, $content_type, $period );

		// resolve the label
		$raw_label = isset( $options['label'] ) && is_scalar( $options['label'] ) ? (string) $options['label'] : 'Views:';
		$label_supplied = array_key_exists( 'label', $supplied ) && is_scalar( $supplied['label'] );
		$label_default = $label_supplied ? (string) $supplied['label'] : $raw_label;

		if ( ! $label_supplied && function_exists( 'icl_t' ) ) {
			$translated = icl_t( $args['wpml_context'], $args['wpml_name'], $raw_label );
			$label_default = is_scalar( $translated ) ? (string) $translated : $raw_label;

			// read-fallback: honor the legacy per-content-type string slots for one release
			if ( $label_default === $raw_label && is_array( $args['wpml_fallbacks'] ) ) {
				foreach ( $args['wpml_fallbacks'] as $fallback ) {
					if ( ! is_array( $fallback ) || count( $fallback ) < 2 )
						continue;

					$candidate = icl_t( $fallback[0], $fallback[1], $raw_label );

					if ( is_scalar( $candidate ) && (string) $candidate !== $raw_label ) {
						$label_default = (string) $candidate;

						break;
					}
				}
			}
		}

		$label = apply_filters( $args['label_filter'], $label_default, $content_id, $content_type );
		$label = is_scalar( $label ) ? (string) $label : '';
		$metric_label = $label !== '' ? $label : __( 'Views:', 'post-views-counter' );

		// build the class string
		$class = apply_filters( $args['class_filter'], $args['base_class'], $content_id, $content_type );
		$class = is_scalar( $class ) ? (string) $class : $args['base_class'];
		$dynamic = $args['dynamic'] === null ? ! empty( $options['dynamic_loading'] ) : (bool) $args['dynamic'];
		$class .= $dynamic ? ' load-dynamic' : ' load-static';

		$icon = is_string( $args['icon'] ) ? $args['icon'] : $this->get_counter_icon( 'views', $content_id, $content_type, $args['icon_filter'] );
		$show_icon = ! empty( $options['display_style']['icon'] ) && ! empty( $args['inline_icon'] );
		$show_text = ! empty( $options['display_style']['text'] ) && empty( $args['force_sr_label'] );

		if ( ! empty( $args['omit_label'] ) )
			$label_html = '';
		elseif ( $show_text )
			$label_html = '<span class="post-views-label pvc-label">' . esc_html( $label ) . '</span> ';
		else
			$label_html = '<span class="screen-reader-text pvc-metric-label pvc-label">' . esc_html( $metric_label ) . '</span> ';

		$tag = $args['tag'] === 'div' ? 'div' : 'span';
		$before = is_scalar( $args['before'] ) ? (string) $args['before'] : '';
		$after = is_scalar( $args['after'] ) ? (string) $args['after'] : '';
		$html = '<' . $tag . ' class="' . esc_attr( $class ) . '" data-pvc-metric="views" data-pvc-type="' . esc_attr( $content_type ) . '" data-pvc-id="' . esc_attr( $content_id ) . '" data-pvc-period="' . esc_attr( $period ) . '"'
			. ' data-pvc-server-formatted="true">'
			. $before
			. ( $show_icon ? $icon : '' )
			. $label_html
			. '<span class="post-views-count pvc-count">' . wp_kses_post( $value ) . '</span>'
			. $after
			. '</' . $tag . '>';

		// report the resolved parts so a caller filtering at container level can
		// pass the same arguments without re-running the value and label filters
		$resolved = [ 'value' => $value, 'label' => $label, 'icon' => $icon ];

		if ( is_string( $args['html_filter'] ) && $args['html_filter'] !== '' ) {
			$html = apply_filters( $args['html_filter'], $html, $content_id, $value, $label, $icon, $content_type, $period );
			$html = is_scalar( $html ) ? (string) $html : '';
		}

		return $html;
	}

	/**
	 * Render the unified counter container.
	 *
	 * Preserves the released post-views root as the Views target and adds one
	 * icon and the container classes, so context and object classes remain on
	 * the root once.
	 *
	 * @param array  $metrics Metric names; only 'views' is rendered.
	 * @param int    $content_id Content ID.
	 * @param string $content_type Content type.
	 * @param string $period Display period.
	 * @param array  $args Optional; 'metric_args' holds the 'views' overrides.
	 * @param array|null $resolved_out Resolved values keyed by metric.
	 * @return string
	 */
	public function render_counter( $metrics, $content_id, $content_type, $period, $args = [], &$resolved_out = null ) {
		$resolved_out = [];
		$metrics = is_array( $metrics ) ? $metrics : [ $metrics ];

		if ( ! in_array( 'views', $metrics, true ) )
			return '';

		$content_id = (int) $content_id;
		$content_type = sanitize_key( (string) $content_type );
		$period = sanitize_key( (string) $period );
		$args = is_array( $args ) ? $args : [];
		$per_metric = isset( $args['metric_args'] ) && is_array( $args['metric_args'] ) ? $args['metric_args'] : [];
		$root_args = isset( $per_metric['views'] ) && is_array( $per_metric['views'] ) ? $per_metric['views'] : [];

		// One container, one icon. The released metric-specific icon filter still
		// runs. Callers such as pvc_site_views() may pass a pre-resolved icon to
		// preserve their per-call icon contract.
		$icon_filter = isset( $root_args['icon_filter'] ) && is_string( $root_args['icon_filter'] ) ? $root_args['icon_filter'] : 'pvc_post_views_icon';
		$icon = isset( $root_args['icon'] ) && is_string( $root_args['icon'] )
			? $root_args['icon']
			: $this->get_counter_icon( 'views', $content_id, $content_type, $icon_filter );

		// Every root keeps the released `.post-views` class. Two independent
		// reasons, both load-bearing:
		//
		// 1. It is the released styling hook. The shipped 1.7.15 stylesheet is
		//    written entirely against `.post-views`, and a decade of theme and user
		//    CSS targets it.
		// 2. Replacing the Core frontend script can leave only a legacy loader,
		//    which collects targets
		//    with getElementsByClassName( 'post-views' ). A dynamic count is held at
		//    `color: transparent` until JS adds `.loaded` or `.load-error`, so a root
		//    that loader never claims stays permanently invisible.
		$root_base = isset( $root_args['base_class'] ) && is_scalar( $root_args['base_class'] )
			? (string) $root_args['base_class']
			: 'post-views content-' . $content_type . ' ' . $content_type . '-' . $content_id . ' entry-meta';

		$root_args['base_class'] = trim( (string) $root_base ) . ' pvc-container pvc-views';
		$root_args['inline_icon'] = true;
		$root_args['icon'] = $icon;
		$root_args['tag'] = 'div';
		$html = $this->render_metric_span( 'views', $content_id, $content_type, $period, $root_args, $root_resolved );

		if ( $html === '' )
			return '';

		$html = apply_filters( 'pvc_counter_html', $html, [ 'views' ], $content_id, $content_type, $period );

		// hand back what the counter resolved to, so a caller applying its own
		// complete-counter filter can report the displayed value
		$resolved_out = [ 'views' => $root_resolved ];

		return is_scalar( $html ) ? (string) $html : '';
	}

	/**
	 * Display number of post views.
	 *
	 * @return void
	 */
	public function run() {
		if ( is_admin() && ! wp_doing_ajax() )
			return;

		$filter = apply_filters( 'pvc_shortcode_filter_hook', Post_Views_Counter()->options['display']['position'] );

		// valid filter?
		if ( ! empty( $filter ) && in_array( $filter, [ 'before', 'after' ] ) ) {
			// post content
			add_filter( 'the_content', [ $this, 'add_post_views_count' ] );

			// bbpress support
			add_action( 'bbp_template_' . $filter . '_single_topic', [ $this, 'display_bbpress_post_views' ] );
			add_action( 'bbp_template_' . $filter . '_single_forum', [ $this, 'display_bbpress_post_views' ] );
		// custom
		} elseif ( $filter !== 'manual' && is_string( $filter ) )
			add_filter( $filter, [ $this, 'add_post_views_count' ] );
	}

	/**
	 * Add post views counter to forum/topic of bbPress.
	 *
	 * @return void
	 */
	public function display_bbpress_post_views() {
		$post_id = get_the_ID();

		// check only for forums and topics
		if ( bbp_is_forum( $post_id ) || bbp_is_topic( $post_id ) )
			echo $this->add_post_views_count( '' );
	}

	/**
	 * Add post views counter to content.
	 *
	 * @param string $content
	 * @return string
	 */
	public function add_post_views_count( $content = '' ) {
		// get main instance
		$pvc = Post_Views_Counter();

		$display = false;

		// post type check
		if ( ! empty( $pvc->options['display']['post_types_display'] ) )
			$display = is_singular( $pvc->options['display']['post_types_display'] );

		// page visibility check
		if ( ! empty( $pvc->options['display']['page_types_display'] ) ) {
			foreach ( $pvc->options['display']['page_types_display'] as $page ) {
				switch ( $page ) {
					case 'singular':
						if ( is_singular( $pvc->options['display']['post_types_display'] ) )
							$display = true;
						break;

					case 'archive':
						if ( is_archive() )
							$display = true;
						break;

					case 'search':
						if ( is_search() )
							$display = true;
						break;

					case 'home':
						if ( is_home() || is_front_page() )
							$display = true;
						break;
				}
			}
		}

		// get groups to check it faster
		$groups = isset( $pvc->options['display']['restrict_display']['groups'] ) && is_array( $pvc->options['display']['restrict_display']['groups'] ) ? $pvc->options['display']['restrict_display']['groups'] : [];

		// whether to display views
		if ( is_user_logged_in() ) {
			// exclude logged in users?
			if ( in_array( 'users', $groups, true ) )
				$display = false;
			// exclude specific roles?
			elseif ( in_array( 'roles', $groups, true ) && $pvc->counter->is_user_role_excluded( get_current_user_id(), $pvc->options['display']['restrict_display']['roles'] ) )
				$display = false;
		// exclude guests?
		} elseif ( in_array( 'guests', $groups, true ) )
			$display = false;

		// we don't want to mess custom loops
		if ( ! in_the_loop() && ! class_exists( 'bbPress' ) )
			$display = false;

		if ( (bool) apply_filters( 'pvc_display_views_count', $display ) === true ) {
			$filter = apply_filters( 'pvc_shortcode_filter_hook', $pvc->options['display']['position'] );
				$custom = isset( $pvc->options['display']['frontend_counter_metrics'] ) && $pvc->options['display']['frontend_counter_metrics'] === 'custom';
				$output = $custom ? $this->post_views_shortcode( [ 'format' => $this->get_frontend_template() ] ) : do_shortcode( '[post-views]' );

				switch ( $filter ) {
					case 'after':
						$content = $content . $output;
						break;

					case 'before':
						$content = $output . $content;
					break;

				case 'manual':
				default:
					break;
			}
		}

		return $content;
	}

	/**
	 * Get frontend script arguments.
	 *
	 * @return array
	 */
	public function get_frontend_script_args() {
		return $this->script_args;
	}

	/**
	 * Enqueue frontend scripts and styles.
	 *
	 * @return void
	 */
	public function wp_enqueue_scripts() {
		// get main instance
		$pvc = Post_Views_Counter();

		// enable styles?
		if ( (bool) apply_filters( 'pvc_enqueue_styles', true ) === true ) {
			// load style
			wp_enqueue_style( 'post-views-counter-frontend', POST_VIEWS_COUNTER_URL . '/css/frontend.css', [], $pvc->defaults['version'] );
		}

		// skip special requests
		if ( is_preview() || is_feed() || is_trackback() || ( function_exists( 'is_favicon' ) && is_favicon() ) || is_customize_preview() )
			return;

		$dynamic_loading = ! empty( $pvc->options['display']['dynamic_loading'] );

		// get countable post types
		$post_types = (array) $pvc->options['general']['post_types_count'];
		$post_id = (int) get_the_ID();
		$mode = $pvc->options['general']['counter_mode'];
		$should_count = ! empty( $post_types ) && is_singular( $post_types ) && (bool) apply_filters( 'pvc_run_check_post', true, $post_id );

		if ( $dynamic_loading || ( $should_count && in_array( $mode, [ 'js', 'rest_api' ], true ) ) ) {
			wp_enqueue_script( 'post-views-counter-frontend', POST_VIEWS_COUNTER_URL . '/js/frontend.js', [], $pvc->defaults['version'], false );

			// prepare args
			$args = [
				'mode'			=> $mode,
				'countEnabled'	=> $should_count && in_array( $mode, [ 'js', 'rest_api' ], true ),
				'postID'		=> $post_id,
				'requestURL'	=> '',
				'displayURL'	=> untrailingslashit( rest_url( 'post-views-counter/get-post-views' ) ),
				'nonce'			=> '',
					'dataStorage'	=> $pvc->options['general']['data_storage'],
					'multisite'		=> ( is_multisite() ? (int) get_current_blog_id() : false ),
				'path'			=> empty( COOKIEPATH ) || ! is_string( COOKIEPATH ) ? '/' : COOKIEPATH,
				'domain'		=> empty( COOKIE_DOMAIN ) || ! is_string( COOKIE_DOMAIN ) ? '' : COOKIE_DOMAIN
			];

			switch ( $mode ) {
				// rest api
				case 'rest_api':
					$args['requestURL'] = rest_url( 'post-views-counter/view-post/' . $args['postID'] );
					$args['nonce'] = wp_create_nonce( 'wp_rest' );
					break;

				// javascript
				case 'js':
				default:
					$args['requestURL'] = admin_url( 'admin-ajax.php' );
					$args['nonce'] = wp_create_nonce( 'pvc-check-post' );
			}

			// make it safe
			$args['requestURL'] = esc_url_raw( $args['requestURL'] );

			// set script args
			$this->script_args = apply_filters( 'pvc_frontend_script_args', $args, 'standard' );

			wp_add_inline_script( 'post-views-counter-frontend', 'var pvcArgsFrontend = ' . wp_json_encode( $this->script_args ) . ";\n", 'before' );
		}
	}
}
