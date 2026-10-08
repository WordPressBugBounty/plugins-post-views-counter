<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Functions class.
 *
 * @class Post_Views_Counter_Functions
 */
class Post_Views_Counter_Functions {

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {}

	/**
	 * Get post types available for counting.
	 *
	 * @return array
	 */
	public function get_post_types() {
		$post_types = [];

		// get public post types
		foreach ( get_post_types( [ 'public' => true ], 'objects', 'and' ) as $key => $post_type ) {
			$post_types[$key] = $post_type->labels->name;
		}

		// remove bbPress replies
		if ( class_exists( 'bbPress' ) && isset( $post_types['reply'] ) )
			unset( $post_types['reply'] );

		// filter post types
		$post_types = apply_filters( 'pvc_available_post_types', $post_types );

		// sort post types alphabetically
		asort( $post_types, SORT_STRING );

		return $post_types;
	}

	/**
	 * Get all user roles.
	 *
	 * @global object $wp_roles
	 *
	 * @return array
	 */
	public function get_user_roles() {
		global $wp_roles;

		$roles = [];

		foreach ( apply_filters( 'editable_roles', $wp_roles->roles ) as $role => $details ) {
			$roles[$role] = translate_user_role( $details['name'] );
		}

		// sort user roles alphabetically
		asort( $roles, SORT_STRING );

		return $roles;
	}

	/**
	 * Get taxonomies available for counting.
	 *
	 * @param bool $mode
	 * @return array
	 */
	public function get_taxonomies( $mode = 'labels' ) {
		// get public taxonomies
		$taxonomies = get_taxonomies(
			[
				'public' => true
			],
			$mode === 'keys' ? 'names' : 'objects',
			'and'
		);

		// only keys
		if ( $mode === 'keys' )
			$_taxonomies = array_keys( $taxonomies );
		// objects
		elseif ( $mode === 'objects' )
			$_taxonomies = $taxonomies;
		// labels
		else {
			$_taxonomies = [];

			// prepare taxonomy labels
			foreach ( $taxonomies as $name => $taxonomy ) {
				$_taxonomies[$name] = $taxonomy->label;
			}
		}

		return $_taxonomies;
	}

	/**
	 * Get color scheme.
	 *
	 * @global array $_wp_admin_css_colors
	 *
	 * @return string
	 */
	public function get_current_scheme_color( $default_color = '' ) {
		// get color scheme global
		global $_wp_admin_css_colors;

		// set default color;
		$color = '#2271b1';

		if ( ! empty( $_wp_admin_css_colors ) ) {
			// get current admin color scheme name
			$current_color_scheme = get_user_option( 'admin_color' );

			if ( empty( $current_color_scheme ) )
				$current_color_scheme = 'fresh';

			$wp_scheme_colors = [
				'coffee'	=> 2,
				'ectoplasm'	=> 2,
				'ocean'		=> 2,
				'sunrise'	=> 2,
				'midnight'	=> 3,
				'blue'		=> 3,
				'modern'	=> 1,
				'light'		=> 1,
				'fresh'		=> 2
			];

			// one of default wp schemes?
			if ( array_key_exists( $current_color_scheme, $wp_scheme_colors ) ) {
				$color_number = $wp_scheme_colors[$current_color_scheme];

				// color exists?
				if ( isset( $_wp_admin_css_colors[$current_color_scheme] ) && property_exists( $_wp_admin_css_colors[$current_color_scheme], 'colors' ) && isset( $_wp_admin_css_colors[$current_color_scheme]->colors[$color_number] ) )
					$color = $_wp_admin_css_colors[$current_color_scheme]->colors[$color_number];
			}
		}

		return sanitize_hex_color( apply_filters( 'pvc_current_scheme_color', $color ) );
	}

	/**
	 * Convert HEX to RGB color.
	 *
	 * @param string $color
	 * @return bool|array
	 */
	public function hex2rgb( $color ) {
		if ( ! is_string( $color ) )
			return false;

		// with hash?
		if ( $color[0] === '#' )
			$color = substr( $color, 1 );

		if ( sanitize_hex_color_no_hash( $color ) !== $color )
			return false;

		// 6 hex digits?
		if ( strlen( $color ) === 6 )
			list( $r, $g, $b ) = [ $color[0] . $color[1], $color[2] . $color[3], $color[4] . $color[5] ];
		// 3 hex digits?
		elseif ( strlen( $color ) === 3 )
			list( $r, $g, $b ) = [ $color[0] . $color[0], $color[1] . $color[1], $color[2] . $color[2] ];
		else
			return false;

		return [ 'r' => hexdec( $r ), 'g' => hexdec( $g ), 'b' => hexdec( $b ) ];
	}

	/**
	 * Get default color.
	 *
	 * @return array
	 */
	public function get_colors() {
		// get current color scheme
		$color = $this->get_current_scheme_color();

		// convert it to rgb
		$color = $this->hex2rgb( $color );

		// invalid color?
		if ( $color === false ) {
			// set default color
			$color = [ 'r' => 34, 'g' => 113, 'b' => 177 ];
		}

		return $color;
	}

	/**
	 * Convert an RGB color to HSL.
	 *
	 * Deliberately dependency-free: the only consumer is the derived Visits
	 * color, which needs a hue rotation and nothing a perceptual color library
	 * would add.
	 *
	 * @param int $r Red channel, 0-255.
	 * @param int $g Green channel, 0-255.
	 * @param int $b Blue channel, 0-255.
	 * @return array Hue in degrees, saturation and lightness as 0-1 floats.
	 */
	public function rgb2hsl( $r, $g, $b ) {
		$r = (int) $r / 255;
		$g = (int) $g / 255;
		$b = (int) $b / 255;

		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$delta = $max - $min;
		$l = ( $max + $min ) / 2;

		// gray has no hue and no saturation
		if ( $delta == 0 )
			return [ 'h' => 0.0, 's' => 0.0, 'l' => (float) $l ];

		$s = $l > 0.5 ? $delta / ( 2 - $max - $min ) : $delta / ( $max + $min );

		if ( $max === $r )
			$h = fmod( ( $g - $b ) / $delta, 6 );
		elseif ( $max === $g )
			$h = ( $b - $r ) / $delta + 2;
		else
			$h = ( $r - $g ) / $delta + 4;

		$h *= 60;

		if ( $h < 0 )
			$h += 360;

		return [ 'h' => (float) $h, 's' => (float) $s, 'l' => (float) $l ];
	}

	/**
	 * Resolve one HSL channel back to its 0-1 RGB value.
	 *
	 * @param float $p Lower bound.
	 * @param float $q Upper bound.
	 * @param float $t Normalized hue offset.
	 * @return float
	 */
	private function hue2channel( $p, $q, $t ) {
		if ( $t < 0 )
			$t += 1;

		if ( $t > 1 )
			$t -= 1;

		if ( $t < 1 / 6 )
			return $p + ( $q - $p ) * 6 * $t;

		if ( $t < 1 / 2 )
			return $q;

		if ( $t < 2 / 3 )
			return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;

		return $p;
	}

	/**
	 * Convert an HSL color to RGB.
	 *
	 * @param float $h Hue in degrees.
	 * @param float $s Saturation, 0-1.
	 * @param float $l Lightness, 0-1.
	 * @return array
	 */
	public function hsl2rgb( $h, $s, $l ) {
		$h = fmod( (float) $h, 360 );

		if ( $h < 0 )
			$h += 360;

		$h /= 360;
		$s = min( max( (float) $s, 0 ), 1 );
		$l = min( max( (float) $l, 0 ), 1 );

		if ( $s == 0 ) {
			$value = (int) round( $l * 255 );

			return [ 'r' => $value, 'g' => $value, 'b' => $value ];
		}

		$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
		$p = 2 * $l - $q;

		return [
			'r' => (int) round( $this->hue2channel( $p, $q, $h + 1 / 3 ) * 255 ),
			'g' => (int) round( $this->hue2channel( $p, $q, $h ) * 255 ),
			'b' => (int) round( $this->hue2channel( $p, $q, $h - 1 / 3 ) * 255 )
		];
	}

	/**
	 * Derive the secondary Visits color from the Views color.
	 *
	 * Color means metric everywhere PVC draws a chart, so Visits needs exactly
	 * one companion color to whatever the admin theme gives Views. Rotating the
	 * hue by a fixed 45 degrees, capping saturation and clamping lightness keeps
	 * the result distinguishable and legible on every supported admin scheme
	 * without carrying a perceptual-color dependency. The dashed line remains
	 * the authoritative non-color distinction.
	 *
	 * @param array|null $rgb Source Views color; the admin-theme color by default.
	 * @return array
	 */
	public function get_visits_color( $rgb = null ) {
		// the historical Visits purple, used whenever the source is unusable
		$fallback = [ 'r' => 116, 'g' => 72, 'b' => 157 ];

		if ( $rgb === null )
			$rgb = $this->get_colors();

		if ( ! is_array( $rgb ) )
			return $fallback;

		$source = [];

		foreach ( [ 'r', 'g', 'b' ] as $channel ) {
			if ( ! isset( $rgb[$channel] ) || ! is_numeric( $rgb[$channel] ) )
				return $fallback;

			$value = (int) $rgb[$channel];

			if ( $value < 0 || $value > 255 )
				return $fallback;

			$source[$channel] = $value;
		}

		$hsl = $this->rgb2hsl( $source['r'], $source['g'], $source['b'] );

		if ( ! is_array( $hsl ) || ! isset( $hsl['h'], $hsl['s'], $hsl['l'] ) )
			return $fallback;

		// rotate hue with wraparound, cap saturation, clamp lightness
		$derived = $this->hsl2rgb( fmod( $hsl['h'] + 45, 360 ), min( $hsl['s'], 0.6 ), min( max( $hsl['l'], 0.35 ), 0.5 ) );

		if ( ! is_array( $derived ) || ! isset( $derived['r'], $derived['g'], $derived['b'] ) )
			return $fallback;

		return $derived;
	}

	/**
	 * Get the canonical Chart.js dataset style for one metric.
	 *
	 * Views and Visits look the same wherever they are drawn: the dashboard
	 * charts, the column modal, and the extension-provided charts. Views uses the admin-theme color, Visits the color
	 * derived from it, and the line variant separates them a second time with a
	 * dash pattern so the two series stay distinguishable without color.
	 *
	 * @param string $metric Metric identifier, 'views' or 'visits'.
	 * @param string $type Chart type, 'line' or 'bar'.
	 * @param array  $context Optional presentation context. Only 'fill' is read:
	 *                        true draws the filled one- or two-line presentation.
	 * @return array
	 */
	public function get_metric_dataset_style( $metric = 'views', $type = 'line', $context = [] ) {
		$metric = $metric === 'visits' ? 'visits' : 'views';
		$type = $type === 'bar' ? 'bar' : 'line';
		$context = is_array( $context ) ? $context : [];
		$context = [ 'fill' => ! empty( $context['fill'] ) ];

		$color = $metric === 'visits' ? $this->get_visits_color() : $this->get_colors();
		$rgb = $color['r'] . ',' . $color['g'] . ',' . $color['b'];

		if ( $type === 'bar' ) {
			// Only the ranked Views reports draw bars, one dataset per chart, so
			// there is no Visits bar variant. borderDash is deliberately absent -
			// the Chart.js bar element has no such property and silently ignores
			// it. Bar geometry never reads the fill context. The 0.2 fill is
			// legacy report bars.
			$style = [
				'backgroundColor'		=> 'rgba(' . $rgb . ',0.2)',
				'borderColor'			=> 'rgba(' . $rgb . ',1)',
				'borderWidth'			=> 0,
				'grouped'				=> false,
				'categoryPercentage'	=> 0.9,
				'barPercentage'			=> 0.9
			];
		} else {
			// One unified line presentation for every PVC line chart: a 2-pixel
			// rounded stroke, a 0.4 tension curve, and white points with a
			// 2-pixel stroke in the series color. The area fill varies only when a
			// caller explicitly requests the unfilled presentation.
			$style = [
				'borderColor'			=> 'rgba(' . $rgb . ',1)',
				'backgroundColor'		=> 'rgba(' . $rgb . ',' . ( $context['fill'] ? '0.1' : '0.15' ) . ')',
				'borderWidth'			=> 2,
				'borderCapStyle'		=> 'round',
				'borderJoinStyle'		=> 'round',
				'borderDash'			=> $metric === 'visits' ? [ 6, 4 ] : [],
				'fill'					=> $context['fill'] ? 'origin' : false,
				'tension'				=> 0.4,
				'pointRadius'			=> 3,
				'pointHoverRadius'		=> 5,
				'pointBackgroundColor'	=> 'rgba(255,255,255,1)',
				'pointBorderColor'		=> 'rgba(' . $rgb . ',1)',
				'pointBorderWidth'		=> 2
			];
		}

		// the context is additive; the first three arguments keep their meaning
		return apply_filters( 'pvc_metric_dataset_style', $style, $metric, $type, $context );
	}

	/**
	 * Attach locale-formatted tooltip values to Chart.js datasets.
	 *
	 * Tooltips print these strings, so they match the admin column, which
	 * formats with number_format_i18n(). Entities are decoded because Chart.js
	 * draws text on a canvas. A value that is not numeric stays null.
	 *
	 * @param array $chart Chart.js data object holding a datasets list.
	 * @return array
	 */
	public function format_chart_datasets( $chart ) {
		if ( ! is_array( $chart ) || empty( $chart['datasets'] ) || ! is_array( $chart['datasets'] ) )
			return $chart;

		foreach ( $chart['datasets'] as $index => $dataset ) {
			if ( ! is_array( $dataset ) || ! isset( $dataset['data'] ) || ! is_array( $dataset['data'] ) )
				continue;

			$chart['datasets'][$index]['formatted'] = array_map( static function ( $value ) {
				return is_numeric( $value ) ? html_entity_decode( number_format_i18n( (float) $value ), ENT_QUOTES, 'UTF-8' ) : null;
			}, $dataset['data'] );
		}

		return $chart;
	}

	/**
	 * Get the allowed HTML for sanitizing rendered counter markup.
	 *
	 * wp_kses_post() strips inline SVG, which would silently empty the counter
	 * icon. This is the post allow-list plus the two elements the shared icon
	 * uses, so counter output can still be sanitized before it is echoed.
	 *
	 * @return array
	 */
	public function get_counter_allowed_html() {
		$allowed = wp_kses_allowed_html( 'post' );

		// kses lowercases attribute names; the HTML parser maps viewbox back to
		// viewBox for inline SVG, so the rendered icon is unaffected.
		$allowed['svg'] = [
			'aria-hidden'	=> true,
			'class'			=> true,
			'fill'			=> true,
			'focusable'		=> true,
			'height'		=> true,
			'role'			=> true,
			'viewbox'		=> true,
			'width'			=> true,
			'xmlns'			=> true
		];
		$allowed['path'] = [
			'd'		=> true,
			'fill'	=> true
		];

		return $allowed;
	}

	/**
	 * Sanitize a space-separated list of HTML classes.
	 *
	 * @param string $class Raw class list.
	 * @return string
	 */
	public function sanitize_html_class_list( $class ) {
		if ( ! is_scalar( $class ) )
			return '';

		$classes = preg_split( '/\s+/', trim( (string) $class ) );
		$classes = array_filter( array_map( 'sanitize_html_class', is_array( $classes ) ? $classes : [] ) );

		return implode( ' ', array_unique( $classes ) );
	}

	/**
	 * Get the shared inline bar-chart counter icon.
	 *
	 * The single source of the plugin's bar-chart glyph: the admin columns inline
	 * it and the admin menu icon is built from it. The frontend paints the same
	 * glyph through a CSS mask, so its path data is duplicated in the stylesheet
	 * and locked to this one by test.
	 *
	 * @return string
	 */
	public function get_counter_icon_svg() {
		return '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3.5 15v6a1.5 1.5 0 0 0 3 0v-6a1.5 1.5 0 0 0-3 0Zm7-6v12a1.5 1.5 0 0 0 3 0V9a1.5 1.5 0 0 0-3 0Zm7-6v18a1.5 1.5 0 0 0 3 0V3a1.5 1.5 0 0 0-3 0Z" /></svg>';
	}

	/**
	 * Get the counter icon as an admin menu icon.
	 *
	 * WordPress recolours a menu icon to the admin colour scheme only when it is
	 * a base64 SVG data URI, by rewriting its fill attributes. The literal fill is
	 * the default scheme's icon colour, so the icon is right before that script
	 * runs.
	 *
	 * @return string
	 */
	public function get_menu_icon_data_uri() {
		$svg = str_replace( 'fill="currentColor"', 'fill="#a7aaad"', $this->get_counter_icon_svg() );

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
