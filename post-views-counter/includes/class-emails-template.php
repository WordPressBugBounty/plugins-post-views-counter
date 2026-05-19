<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Emails_Template class.
 *
 * @class Post_Views_Counter_Emails_Template
 */
class Post_Views_Counter_Emails_Template {

	/**
	 * @var Post_Views_Counter
	 */
	private $pvc;

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->pvc = Post_Views_Counter();
	}

	/**
	 * Get supported subject template tags.
	 *
	 * @param array $data
	 * @param string $summary_type
	 * @return array
	 */
	public static function get_subject_template_tags( $data = [], $summary_type = 'weekly' ) {
		$allowed_tags = [
			'%%site_name%%',
			'%%report_label%%',
			'%%period_label%%',
			'%%total_views%%',
			'%%views_change%%',
			'%%views_change_percent%%',
			'%%viewed_content_count%%',
			'%%top_post_title%%'
		];

		return self::filter_template_tags_by_definitions( $allowed_tags, self::get_content_template_tags( $data, $summary_type ) );
	}

	/**
	 * Get supported content template tags.
	 *
	 * @param array $data
	 * @param string $summary_type
	 * @return array
	 */
	public static function get_content_template_tags( $data = [], $summary_type = 'weekly' ) {
		return array_keys( self::get_template_tag_definitions( $data, $summary_type ) );
	}

	/**
	 * Get additional template tags reserved for locked UI display.
	 *
	 * @return array
	 */
	public static function get_pro_template_tags() {
		$tags = apply_filters(
			'pvc_email_summary_pro_template_tags',
			[
				'%%content_performance_summary%%',
				'%%monthly_report_summary%%',
				'%%top_gainers%%',
				'%%top_decliners%%',
				'%%rolling_average_summary%%',
				'%%projection_summary%%',
				'%%top_authors%%',
				'%%top_terms%%',
				'%%top_sources%%',
				'%%device_summary%%',
				'%%browser_summary%%',
				'%%language_summary%%',
				'%%suggested_actions%%'
			]
		);

		if ( ! is_array( $tags ) )
			return [];

		return array_values(
			array_filter(
				array_map(
					static function( $tag ) {
						$tag = sanitize_text_field( (string) $tag );

						return preg_match( '/^%%[a-z0-9_]+%%$/i', $tag ) ? $tag : '';
					},
					$tags
				)
			)
		);
	}

	/**
	 * Get supported Free template tags.
	 *
	 * @param array $data
	 * @param string $summary_type
	 * @return array
	 */
	public static function get_free_template_tags( $data = [], $summary_type = 'weekly' ) {
		return self::get_content_template_tags( $data, $summary_type );
	}

	/**
	 * Find unsupported template tags.
	 *
	 * @param string $template
	 * @param array $data
	 * @param string $summary_type
	 * @param string $context
	 * @return array
	 */
	public static function find_unknown_template_tags( $template, $data = [], $summary_type = 'weekly', $context = 'content' ) {
		if ( ! preg_match_all( '/%%[a-z0-9_]+%%/i', (string) $template, $matches ) )
			return [];

		$allowed = array_map( 'strtolower', self::get_template_tags_for_context( $context, $data, $summary_type ) );
		$found = array_unique( array_map( 'strtolower', $matches[0] ) );

		return array_values( array_diff( $found, $allowed ) );
	}

	/**
	 * Remove unsupported template tags for a specific field context.
	 *
	 * @param string $template
	 * @param string $context
	 * @param array $data
	 * @param string $summary_type
	 * @return array
	 */
	public static function strip_invalid_template_tags( $template, $context = 'content', $data = [], $summary_type = 'weekly' ) {
		$template = (string) $template;
		$removed_tags = [];
		$allowed = array_map( 'strtolower', self::get_template_tags_for_context( $context, $data, $summary_type ) );

		$cleaned_template = preg_replace_callback(
			'/%%[a-z0-9_]+%%/i',
			static function( $matches ) use ( $allowed, &$removed_tags ) {
				$token = isset( $matches[0] ) ? (string) $matches[0] : '';
				$normalized = strtolower( $token );

				if ( in_array( $normalized, $allowed, true ) )
					return $token;

				$removed_tags[] = $token;

				return '';
			},
			$template
		);

		return [
			'template' => self::normalize_cleaned_template( $cleaned_template, $context ),
			'removed_tags' => array_values( array_unique( $removed_tags ) )
		];
	}

	/**
	 * Render the summary subject and body.
	 *
	 * @param array $data
	 * @param array $settings
	 * @return array
	 */
	public function render( $data, $settings = [] ) {
		$data = is_array( $data ) ? $data : [];
		$summary_type = ! empty( $data['type'] ) ? sanitize_key( $data['type'] ) : 'weekly';
		$settings = $this->get_settings( $settings );
		$subject_template = self::strip_invalid_template_tags( $this->get_subject_template( $settings, $summary_type ), 'subject', $data, $summary_type );
		$body_template = self::strip_invalid_template_tags( $this->get_body_template( $settings ), 'content', $data, $summary_type );
		$footer_template = self::strip_invalid_template_tags( self::get_default_footer_template(), 'content', $data, $summary_type );
		$subject_template = $subject_template['template'];
		$content_template_html = $body_template['template'];
		$content_template_text = $body_template['template'];

		if ( ! $this->template_contains_html_markup( $content_template_html ) )
			$content_template_html = $this->render_plain_text_template_html( $content_template_html );

		$content_template_text .= "\n\n" . $footer_template['template'];

		$replacements = $this->get_tag_replacements( $data, $settings, $summary_type );
		$subject = $this->render_template_text( $subject_template, $replacements, true );
		$html = $this->render_template_html( $content_template_html, $replacements );
		$footer_html = $this->render_template_html( $this->render_footer_template_html( $footer_template['template'], $data ), $replacements, true );
		$plain_text = $this->render_template_text( $content_template_text, $replacements );

		$subject = apply_filters( 'pvc_email_summary_subject', $subject, $data, $summary_type );
		$html = apply_filters( 'pvc_email_summary_html', $html, $data, $summary_type );
		$html = $this->sanitize_html_output( $html );
		$html = $this->render_email_document_html( $html, $footer_html, $data, $settings, $summary_type );
		$plain_text = apply_filters( 'pvc_email_summary_plain_text', $plain_text, $data, $summary_type );

		return [
			'subject' => $subject,
			'html' => $html,
			'plain_text' => $plain_text,
			'data' => $data
		];
	}

	/**
	 * Get template tag definitions.
	 *
	 * @param array $data
	 * @param string $summary_type
	 * @return array
	 */
	public static function get_template_tag_definitions( $data = [], $summary_type = 'weekly' ) {
		$tags = [
			'%%site_name%%' => [ 'type' => 'scalar' ],
			'%%site_url%%' => [ 'type' => 'scalar' ],
			'%%plugin_name%%' => [ 'type' => 'scalar' ],
			'%%report_type%%' => [ 'type' => 'scalar' ],
			'%%report_cadence%%' => [ 'type' => 'scalar' ],
			'%%report_label%%' => [ 'type' => 'scalar' ],
			'%%period_start%%' => [ 'type' => 'scalar' ],
			'%%period_end%%' => [ 'type' => 'scalar' ],
			'%%period_label%%' => [ 'type' => 'scalar' ],
			'%%time_basis%%' => [ 'type' => 'scalar' ],
			'%%generated_at%%' => [ 'type' => 'scalar' ],
			'%%total_views%%' => [ 'type' => 'scalar' ],
			'%%previous_total_views%%' => [ 'type' => 'scalar' ],
			'%%views_change%%' => [ 'type' => 'scalar' ],
			'%%views_change_percent%%' => [ 'type' => 'scalar' ],
			'%%viewed_content_count%%' => [ 'type' => 'scalar' ],
			'%%top_post_title%%' => [ 'type' => 'scalar' ],
			'%%top_post_url%%' => [ 'type' => 'scalar' ],
			'%%top_post_views%%' => [ 'type' => 'scalar' ],
			'%%report_summary%%' => [ 'type' => 'block' ],
			'%%top_content%%' => [ 'type' => 'block' ],
			'%%traffic_signals%%' => [ 'type' => 'block' ],
			'%%manage_emails_url%%' => [ 'type' => 'scalar' ],
			'%%manage_emails_link%%' => [ 'type' => 'block' ],
			'%%site_link%%' => [ 'type' => 'block' ]
		];

		return self::sanitize_template_tag_definitions( apply_filters( 'pvc_email_summary_template_tags', $tags, $data, $summary_type ) );
	}

	/**
	 * Sanitize filtered template tag definitions.
	 *
	 * @param array $tags
	 * @return array
	 */
	private static function sanitize_template_tag_definitions( $tags ) {
		if ( ! is_array( $tags ) )
			return [];

		$sanitized = [];

		foreach ( $tags as $tag => $definition ) {
			if ( ! is_string( $tag ) || ! preg_match( '/^%%[a-z0-9_]+%%$/i', $tag ) )
				continue;

			if ( ! is_array( $definition ) )
				$definition = [];

			$definition['type'] = isset( $definition['type'] ) && $definition['type'] === 'block' ? 'block' : 'scalar';
			$sanitized[$tag] = $definition;
		}

		return $sanitized;
	}

	/**
	 * Get merged email settings.
	 *
	 * @param array $settings
	 * @return array
	 */
	private function get_settings( $settings ) {
		$stored = isset( $this->pvc->options['emails'] ) && is_array( $this->pvc->options['emails'] ) ? $this->pvc->options['emails'] : [];
		$defaults = $this->pvc->get_default_emails_settings();

		if ( ! is_array( $settings ) )
			$settings = [];

		return wp_parse_args( $settings, array_merge( $defaults, $stored ) );
	}

	/**
	 * Get the default subject template.
	 *
	 * @return string
	 */
	public static function get_default_subject_template( $summary_type = 'weekly' ) {
		if ( self::normalize_summary_type_key( $summary_type ) === 'weekly' )
			return '[%%site_name%%] Weekly content views: %%total_views%% views';

		return '[%%site_name%%] %%report_label%% content views: %%total_views%% views';
	}

	/**
	 * Get the default body template.
	 *
	 * @return string
	 */
	public static function get_default_body_template() {
		return '<p>Hi,</p>' . "\n"
			. '<p>Here is your %%report_label%% content views summary for %%site_name%%.</p>' . "\n"
			. '<p>This summary covers %%period_label%% and shows which content received the most views during the report period.</p>' . "\n"
			. '<p>%%report_summary%%</p>' . "\n"
			. '<p>%%top_content%%</p>' . "\n"
			. '<p>%%traffic_signals%%</p>' . "\n"
			. '<p>That\'s all for this summary.</p>';
	}

	/**
	 * Sanitize user-editable email template HTML.
	 *
	 * Inline styles remain blocked for saved templates. The optional style support
	 * is only used when sanitizing plugin-generated HTML blocks and the outer email wrapper.
	 *
	 * @param string $html
	 * @param bool $allow_styles
	 * @return string
	 */
	public static function sanitize_template_html( $html, $allow_styles = false ) {
		$masked = self::mask_template_tags( $html );
		$style_filter = null;

		if ( $allow_styles ) {
			$style_filter = static function( $styles ) {
				return array_values( array_unique( array_merge( $styles, self::get_email_template_safe_css() ) ) );
			};

			add_filter( 'safe_style_css', $style_filter );
		}

		$sanitized = wp_kses( $masked['html'], self::get_email_template_allowed_html( $allow_styles ) );
		$sanitized = self::restore_masked_template_tags( $sanitized, $masked['replacements'] );

		if ( $allow_styles && $style_filter !== null )
			remove_filter( 'safe_style_css', $style_filter );

		return $sanitized;
	}

	/**
	 * Get the default footer template.
	 *
	 * @return string
	 */
	public static function get_default_footer_template() {
		return 'Sent from %%site_url%% by Post Views Counter.';
	}

	/**
	 * Get the configured subject template.
	 *
	 * @param array $settings
	 * @param string $summary_type
	 * @return string
	 */
	private function get_subject_template( $settings, $summary_type = 'weekly' ) {
		$template = ! empty( $settings['email_subject_template'] ) ? sanitize_text_field( $settings['email_subject_template'] ) : '';
		$weekly_default = self::get_default_subject_template( 'weekly' );

		if ( $template === '' )
			return self::get_default_subject_template( $summary_type );

		if ( self::normalize_summary_type_key( $summary_type ) !== 'weekly' && $template === $weekly_default )
			return self::get_default_subject_template( $summary_type );

		return $template;
	}

	/**
	 * Get the configured body template.
	 *
	 * @param array $settings
	 * @return string
	 */
	private function get_body_template( $settings ) {
		$template = ! empty( $settings['email_body_template'] ) ? self::sanitize_template_html( $settings['email_body_template'] ) : '';

		return $template !== '' ? $template : self::get_default_body_template();
	}

	/**
	 * Get template tags allowed for a field context.
	 *
	 * @param string $context
	 * @param array $data
	 * @param string $summary_type
	 * @return array
	 */
	private static function get_template_tags_for_context( $context, $data = [], $summary_type = 'weekly' ) {
		switch ( sanitize_key( $context ) ) {
			case 'subject':
				return self::get_subject_template_tags( $data, $summary_type );

			case 'content':
			default:
				return self::get_content_template_tags( $data, $summary_type );
		}
	}

	/**
	 * Normalize a cleaned template string after invalid tags are removed.
	 *
	 * @param string $template
	 * @param string $context
	 * @return string
	 */
	private static function normalize_cleaned_template( $template, $context ) {
		$template = (string) $template;

		if ( sanitize_key( $context ) === 'subject' )
			return trim( preg_replace( '/\s{2,}/', ' ', $template ) );

		return $template;
	}

	/**
	 * Keep requested tag order while dropping tags that are not currently defined.
	 *
	 * @param array $requested_tags
	 * @param array $available_tags
	 * @return array
	 */
	private static function filter_template_tags_by_definitions( $requested_tags, $available_tags ) {
		$available_lookup = array_map( 'strtolower', is_array( $available_tags ) ? $available_tags : [] );

		return array_values(
			array_filter(
				is_array( $requested_tags ) ? $requested_tags : [],
				static function( $tag ) use ( $available_lookup ) {
					return is_string( $tag ) && in_array( strtolower( $tag ), $available_lookup, true );
				}
			)
		);
	}

	/**
	 * Resolve HTML and plaintext values for each template tag.
	 *
	 * @param array $data
	 * @param array $settings
	 * @param string $summary_type
	 * @return array
	 */
	private function get_tag_replacements( $data, $settings, $summary_type ) {
		$definitions = self::get_template_tag_definitions( $data, $summary_type );
		$replacements = [];

		foreach ( $definitions as $tag => $definition ) {
			$replacement = $this->resolve_replacement( $tag, $definition, $data, $settings, $summary_type );
			$replacement['type'] = $definition['type'];
			$replacements[$tag] = $replacement;
		}

		return $replacements;
	}

	/**
	 * Resolve a template tag replacement.
	 *
	 * @param string $tag
	 * @param array $definition
	 * @param array $data
	 * @param array $settings
	 * @param string $summary_type
	 * @return array
	 */
	private function resolve_replacement( $tag, $definition, $data, $settings, $summary_type ) {
		if ( array_key_exists( 'text', $definition ) || array_key_exists( 'html', $definition ) ) {
			$text = $this->resolve_definition_value( isset( $definition['text'] ) ? $definition['text'] : '', $data, $settings, $summary_type );
			$html = $this->resolve_definition_value( isset( $definition['html'] ) ? $definition['html'] : $text, $data, $settings, $summary_type );

			return [
				'text' => $this->sanitize_text_output( $text, $definition['type'] === 'block' ),
				'html' => $definition['type'] === 'block' ? $this->sanitize_html_output( $html ) : esc_html( $this->sanitize_text_output( $html, false ) )
			];
		}

		switch ( $tag ) {
			case '%%site_name%%':
				$value = $this->get_site_name( $data );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%site_url%%':
				$value = $this->get_site_url( $data );

				return [
					'text' => esc_url_raw( $value ),
					'html' => esc_url( $value )
				];

			case '%%plugin_name%%':
				$value = __( 'Post Views Counter', 'post-views-counter' );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%report_type%%':
				$value = $this->get_report_type_label( $data, $summary_type );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%report_cadence%%':
				$value = $this->get_report_cadence_key( $data, $summary_type );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%report_label%%':
				$value = $this->get_report_label( $data, $summary_type );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%period_start%%':
				$value = $this->get_period_date( $data, 'start_date' );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%period_end%%':
				$value = $this->get_period_date( $data, 'end_date' );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%period_label%%':
				$value = ! empty( $data['period']['label'] ) ? sanitize_text_field( $data['period']['label'] ) : '';

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%time_basis%%':
				$value = ! empty( $data['period']['time_basis'] ) ? sanitize_key( $data['period']['time_basis'] ) : 'local';

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%generated_at%%':
				$value = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), null, wp_timezone() );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%total_views%%':
				$value = $this->format_number( $this->get_overview_value( $data, 'total_views' ) );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%previous_total_views%%':
				$value = $this->format_number( $this->get_overview_value( $data, 'previous_total_views' ) );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%views_change%%':
				$value = $this->get_formatted_views_change( $data, $settings );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%views_change_percent%%':
				$value = $this->get_formatted_views_change_percent( $data, $settings );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%viewed_content_count%%':
				$value = $this->format_number( $this->get_overview_value( $data, 'viewed_content_count' ) );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%top_post_title%%':
				$value = $this->get_top_content_value( $data, 'title' );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%top_post_url%%':
				$value = $this->get_top_content_value( $data, 'url' );

				return [
					'text' => esc_url_raw( $value ),
					'html' => esc_url( $value )
				];

			case '%%top_post_views%%':
				$value = $this->format_number( $this->get_top_content_value( $data, 'current_views' ) );

				return [
					'text' => $value,
					'html' => esc_html( $value )
				];

			case '%%report_summary%%':
				return [
					'text' => $this->render_report_summary_text( $data, $settings ),
					'html' => $this->render_report_summary_html( $data, $settings )
				];

			case '%%top_content%%':
				return [
					'text' => $this->render_top_content_text( $data ),
					'html' => $this->render_top_content_html( $data )
				];

			case '%%traffic_signals%%':
				return [
					'text' => $this->render_traffic_signals_text( $data, $settings ),
					'html' => $this->render_traffic_signals_html( $data, $settings )
				];

			case '%%manage_emails_url%%':
				$value = $this->get_manage_emails_url( $data );

				return [
					'text' => esc_url_raw( $value ),
					'html' => esc_url( $value )
				];

			case '%%manage_emails_link%%':
				return [
					'text' => $this->render_manage_emails_link_text( $data ),
					'html' => $this->render_manage_emails_link_html( $data )
				];

			case '%%site_link%%':
				return [
					'text' => $this->render_site_link_text( $data ),
					'html' => $this->render_site_link_html( $data )
				];
		}

		return [
			'text' => $tag,
			'html' => esc_html( $tag )
		];
	}

	/**
	 * Resolve a filtered template tag value.
	 *
	 * @param mixed $value
	 * @param array $data
	 * @param array $settings
	 * @param string $summary_type
	 * @return string
	 */
	private function resolve_definition_value( $value, $data, $settings, $summary_type ) {
		if ( is_callable( $value ) )
			$value = call_user_func( $value, $data, $settings, $summary_type, $this );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Render a plaintext template.
	 *
	 * @param string $template
	 * @param array $replacements
	 * @param bool $single_line
	 * @return string
	 */
	private function render_template_text( $template, $replacements, $single_line = false ) {
		$template = (string) $template;
		$map = [];

		foreach ( $replacements as $tag => $replacement ) {
			$map[$tag] = isset( $replacement['text'] ) ? $this->sanitize_text_output( $replacement['text'], ! empty( $replacement['type'] ) && $replacement['type'] === 'block' ) : $tag;
		}

		$text = strtr( $template, $map );
		$text = $single_line ? wp_strip_all_tags( $text, true ) : $this->convert_html_to_text( $text );
		$text = preg_replace( "/\r\n?|\n/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", trim( $text ) );

		if ( $single_line )
			$text = preg_replace( '/\s+/', ' ', trim( html_entity_decode( $text, ENT_QUOTES, $this->get_charset() ) ) );

		return trim( $text );
	}

	/**
	 * Render an HTML template.
	 *
	 * @param string $template
	 * @param array $replacements
	 * @return string
	 */
	private function render_template_html( $template, $replacements, $allow_template_styles = false ) {
		$template = self::sanitize_template_html( $template, $allow_template_styles );
		$placeholder_map = [];
		$scalar_map = [];
		$index = 0;

		foreach ( $replacements as $tag => $replacement ) {
			if ( ! empty( $replacement['type'] ) && $replacement['type'] === 'block' ) {
				$placeholder = '__PVC_EMAIL_BLOCK_' . $index . '__';
				$placeholder_map[$placeholder] = isset( $replacement['html'] ) ? $this->sanitize_html_output( $replacement['html'] ) : '';
				$scalar_map[$tag] = $placeholder;
				$index++;
			} else {
				$scalar_map[$tag] = isset( $replacement['html'] ) ? $replacement['html'] : esc_html( $tag );
			}
		}

		$html = strtr( $template, $scalar_map );
		$html = $this->unwrap_block_placeholders( $html, array_keys( $placeholder_map ) );
		$html = strtr( $html, $placeholder_map );

		return $this->sanitize_html_output( $html );
	}

	/**
	 * Check whether a template already contains HTML markup.
	 *
	 * @param string $template
	 * @return bool
	 */
	private function template_contains_html_markup( $template ) {
		return preg_match( '/<\/?[a-z][^>]*>/i', (string) $template ) === 1;
	}

	/**
	 * Convert a plaintext template fragment into simple HTML paragraphs.
	 *
	 * @param string $template
	 * @return string
	 */
	private function render_plain_text_template_html( $template ) {
		$template = preg_replace( "/\r\n?|\n/", "\n", sanitize_textarea_field( $template ) );
		$template = trim( $template );

		if ( $template === '' )
			return '';

		$paragraphs = preg_split( "/\n\s*\n+/", $template );

		if ( ! is_array( $paragraphs ) )
			return '';

		$html = [];
		$paragraph_count = count( $paragraphs );

		foreach ( $paragraphs as $index => $paragraph ) {
			$paragraph = trim( $paragraph );

			if ( $paragraph === '' )
				continue;

			$style = $index + 1 === $paragraph_count ? 'body-copy body-copy-last' : 'body-copy';
			$html[] = '<p style="' . esc_attr( $this->get_email_style( $style ) ) . '">' . nl2br( esc_html( $paragraph ) ) . '</p>';
		}

		return implode( '', $html );
	}

	/**
	 * Render the automatic footer as muted HTML.
	 *
	 * @param string $template
	 * @param array $data
	 * @return string
	 */
	private function render_footer_template_html( $template, $data = [] ) {
		$template = preg_replace( "/\r\n?|\n/", "\n", sanitize_textarea_field( $template ) );
		$template = trim( $template );

		if ( $template === '' )
			return '';

		$template = esc_html( $template );
		$template = str_replace( '%%site_url%%', '<a href="%%site_url%%" style="' . esc_attr( $this->get_email_style( 'footer-link' ) ) . '">%%site_url%%</a>', $template );
		$template = str_replace(
			'Post Views Counter',
			'<a href="' . esc_url( $this->pvc->get_postviewscounter_url( '/', 'email', 'content-summary', 'email-footer-brand-link', $this->get_email_utm_context( $data ) ) ) . '" style="' . esc_attr( $this->get_email_style( 'footer-link' ) ) . '">Post Views Counter</a>',
			$template
		);

		return '<p style="' . esc_attr( $this->get_email_style( 'footer-copy' ) ) . '">' . nl2br( $template ) . '</p>';
	}

	/**
	 * Get the UTM context for email links to postviewscounter.com.
	 *
	 * @param array $data
	 * @return string
	 */
	private function get_email_utm_context( $data ) {
		$context = ! empty( $data['plugin_tier'] ) ? sanitize_key( (string) $data['plugin_tier'] ) : 'free';

		if ( $context === 'pro' )
			return 'pro-active';

		if ( in_array( $context, [ 'free', 'pro-expired', 'pro-active' ], true ) )
			return $context;

		return 'free';
	}

	/**
	 * Wrap the rendered inner content in a full email-safe HTML shell.
	 *
	 * @param string $inner_html
	 * @param string $footer_html
	 * @param array $data
	 * @param array $settings
	 * @param string $summary_type
	 * @return string
	 */
	private function render_email_document_html( $inner_html, $footer_html, $data, $settings, $summary_type ) {
		$inner_html = trim( (string) $inner_html );
		$footer_html = trim( (string) $footer_html );
		$report_label = $this->get_report_type_label( $data, $summary_type );
		$period_label = $this->get_period_label( $data );
		$header_meta = $report_label;

		if ( $period_label !== '' )
			$header_meta .= ' | ' . $period_label;
		$title = wp_strip_all_tags( $report_label . ' - ' . $this->get_site_name( $data ), true );
		$document = '<html>' . "\n";
		$document .= '<head>' . "\n";
		$document .= '<meta http-equiv="Content-Type" content="text/html; charset=' . esc_attr( $this->get_charset() ) . '">' . "\n";
		$document .= '<meta name="viewport" content="width=device-width">' . "\n";
		$document .= '<title>' . esc_html( $title ) . '</title>' . "\n";
		$document .= '<style type="text/css">a{color:#2271b1;text-decoration:underline;}@media only screen and (max-width: 599px){table.pvc-email-shell{width:100% !important;}td.pvc-email-wrap{padding:16px !important;}td.pvc-email-panel{padding:24px 20px !important;}}</style>' . "\n";
		$document .= '</head>' . "\n";
		$document .= '<body style="' . esc_attr( $this->get_email_style( 'document-body' ) ) . '">' . "\n";
		$document .= '<table class="pvc-email-body" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="' . esc_attr( $this->get_email_style( 'document-table' ) ) . '">' . "\n";
		$document .= '<tbody><tr><td class="pvc-email-wrap" align="center" style="' . esc_attr( $this->get_email_style( 'wrap' ) ) . '">' . "\n";
		$document .= '<table class="pvc-email-shell" role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="' . esc_attr( $this->get_email_style( 'shell' ) ) . '">' . "\n";
		$document .= '<tbody>';
		$document .= '<tr><td class="pvc-email-header" style="' . esc_attr( $this->get_email_style( 'header' ) ) . '">';
		$document .= '<span style="' . esc_attr( $this->get_email_style( 'header-label' ) ) . '">' . esc_html__( 'Post Views Counter', 'post-views-counter' ) . '</span>';
		$document .= '<span style="' . esc_attr( $this->get_email_style( 'header-meta' ) ) . '">' . esc_html( $header_meta ) . '</span>';
		$document .= '</td></tr>';
		$document .= '<tr><td class="pvc-email-panel" style="' . esc_attr( $this->get_email_style( 'panel' ) ) . '">' . $inner_html . '</td></tr>';

		if ( $footer_html !== '' )
			$document .= '<tr><td class="pvc-email-footer" style="' . esc_attr( $this->get_email_style( 'footer-wrap' ) ) . '">' . $footer_html . '</td></tr>';

		$document .= '</tbody></table>' . "\n";
		$document .= '</td></tr></tbody></table>' . "\n";
		$document .= '</body>' . "\n";
		$document .= '</html>';

		return $this->sanitize_email_document_html( $document );
	}

	/**
	 * Get merged inline styles for the email wrapper and shared HTML blocks.
	 *
	 * @param string $selectors
	 * @return string
	 */
	private function get_email_style( $selectors ) {
		$available_styles = self::get_email_styles();
		$selectors = preg_split( '/\s+/', trim( (string) $selectors ) );
		$styles = [];

		foreach ( $selectors as $selector ) {
			if ( $selector === '' )
				continue;

			if ( ! isset( $available_styles[$selector] ) )
				continue;

			foreach ( $available_styles[$selector] as $property => $value )
				$styles[$property] = $value;
		}

		$css = '';

		foreach ( $styles as $property => $value )
			$css .= $property . ': ' . $value . ';';

		return $css;
	}

	/**
	 * Get inline style definitions used by the email wrapper and shared blocks.
	 *
	 * @return array
	 */
	private static function get_email_styles() {
		return [
			'document-body' => [
				'background-color' => '#f8f9fa',
				'margin' => '0',
				'padding' => '0',
				'width' => '100%',
				'min-width' => '100%',
				'font-family' => '-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif',
				'font-size' => '14px',
				'line-height' => '1.6',
				'color' => '#1f2933',
			],
			'document-table' => [
				'background-color' => '#f8f9fa',
				'border-collapse' => 'collapse',
				'border-spacing' => '0',
				'margin' => '0',
				'padding' => '0',
				'width' => '100%',
				'min-width' => '100%',
			],
			'wrap' => [
				'padding' => '24px 12px',
			],
			'shell' => [
				'border-collapse' => 'collapse',
				'border-spacing' => '0',
				'margin' => '0 auto',
				'max-width' => '600px',
				'width' => '100%',
			],
			'header' => [
				'color' => '#6c757d',
				'font-family' => '-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif',
				'font-size' => '12px',
				'line-height' => '1.5',
				'padding' => '0 4px 24px 4px',
				'text-align' => 'center',
			],
			'header-label' => [
				'color' => '#212529',
				'display' => 'block',
				'font-family' => '-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif',
				'font-size' => '14px',
				'font-weight' => '700',
				'line-height' => '1.4',
				'margin' => '0',
				'text-align' => 'center',
			],
			'header-meta' => [
				'color' => '#6c757d',
				'display' => 'block',
				'font-family' => '-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif',
				'font-size' => '14px',
				'line-height' => '1.5',
				'margin' => '4px 0 0 0',
				'text-align' => 'center',
			],
			'panel' => [
				'background-color' => '#ffffff',
				'border' => '1px solid #dee2e6',
				'border-radius' => '3px',
				'color' => '#343a40',
				'font-family' => '-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif',
				'font-size' => '14px',
				'line-height' => '1.6',
				'padding' => '32px 32px 28px 32px',
			],
			'footer-wrap' => [
				'color' => '#6c757d',
				'font-family' => '-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif',
				'font-size' => '12px',
				'line-height' => '1.6',
				'padding' => '24px 4px 0 4px',
				'text-align' => 'center',
			],
			'footer-copy' => [
				'color' => '#6c757d',
				'font-family' => '-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif',
				'font-size' => '12px',
				'line-height' => '1.6',
				'margin' => '0',
				'text-align' => 'center',
			],
			'footer-link' => [
				'color' => '#4f5d75',
				'text-decoration' => 'underline',
			],
			'section' => [
				'margin' => '0 0 24px 0',
			],
			'body-copy' => [
				'color' => '#343a40',
				'font-family' => '-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif',
				'font-size' => '14px',
				'line-height' => '1.6',
				'margin' => '0 0 24px 0',
			],
			'body-copy-last' => [
				'margin' => '0',
			],
			'section-title' => [
				'color' => '#212529',
				'font-family' => '-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif',
				'font-size' => '13px',
				'font-weight' => '700',
				'line-height' => '1.5',
				'margin' => '0 0 8px 0',
			],
			'paragraph' => [
				'color' => '#343a40',
				'font-family' => '-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif',
				'font-size' => '14px',
				'line-height' => '1.6',
				'margin' => '0 0 8px 0',
			],
			'paragraph-last' => [
				'margin' => '0',
			],
			'list' => [
				'margin' => '0',
				'padding-left' => '20px',
			],
			'list-item' => [
				'color' => '#343a40',
				'font-family' => '-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif',
				'font-size' => '14px',
				'line-height' => '1.6',
				'margin' => '0 0 8px 0',
			],
			'sub-copy' => [
				'color' => '#343a40',
				'font-family' => '-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif',
				'font-size' => '14px',
				'line-height' => '1.6',
				'margin' => '4px 0 0 0',
			],
		];
	}

	/**
	 * Sanitize the plugin-generated full email document.
	 *
	 * @param string $html
	 * @return string
	 */
	private function sanitize_email_document_html( $html ) {
		$style_filter = static function( $styles ) {
			return array_values( array_unique( array_merge( $styles, self::get_email_document_safe_css() ) ) );
		};

		add_filter( 'safe_style_css', $style_filter );

		try {
			return wp_kses( (string) $html, self::get_email_document_allowed_html() );
		} finally {
			remove_filter( 'safe_style_css', $style_filter );
		}
	}

	/**
	 * Prevent block replacements from being trapped inside paragraph-like wrappers.
	 *
	 * @param string $html
	 * @param array $placeholders
	 * @return string
	 */
	private function unwrap_block_placeholders( $html, $placeholders ) {
		$html = (string) $html;

		foreach ( $placeholders as $placeholder ) {
			$quoted = preg_quote( $placeholder, '/' );
			$html = preg_replace( '/<(p|div|span|h[1-4])\b([^>]*)>(.*?)<br\s*\/?>\s*' . $quoted . '\s*<\/\1>/is', '<$1$2>$3</$1>' . $placeholder, $html );
			$html = preg_replace( '/<(p|div|span|h[1-4])\b[^>]*>\s*' . $quoted . '\s*<\/\1>/i', $placeholder, $html );
		}

		return $html;
	}

	/**
	 * Convert sanitized email HTML into readable plaintext.
	 *
	 * @param string $html
	 * @return string
	 */
	private function convert_html_to_text( $html ) {
		$html = $this->sanitize_html_output( $html );

		if ( $html === '' )
			return '';

		$html = preg_replace( '/<\s*br\s*\/?>/i', "\n", $html );
		$html = preg_replace( '/<\s*li\b[^>]*>/i', '- ', $html );
		$html = preg_replace( '/<\s*\/(?:li)\s*>/i', "\n", $html );
		$html = preg_replace( '/<\s*\/(?:p|div|h[1-4]|ul|ol)\s*>/i', "\n\n", $html );

		$text = wp_strip_all_tags( $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES, $this->get_charset() );
		$text = preg_replace( "/\r\n?|\n/", "\n", $text );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );

		return preg_replace( "/\n{3,}/", "\n\n", trim( $text ) );
	}

	/**
	 * Render the overview block as text.
	 *
	 * @param array $data
	 * @param array $settings
	 * @return string
	 */
	private function render_report_summary_text( $data, $settings ) {
		$summary_type = self::normalize_summary_type_key( ! empty( $data['type'] ) ? $data['type'] : ( ! empty( $data['cadence'] ) ? $data['cadence'] : 'weekly' ) );
		$lines = [
			__( 'Content views', 'post-views-counter' ),
			'',
			sprintf( __( '%s total views', 'post-views-counter' ), $this->format_number( $this->get_overview_value( $data, 'total_views' ) ) )
		];

		if ( ! empty( $settings['include_period_trend'] ) ) {
			$trend = $this->get_trend_summary_text( $data, $settings );

			if ( $trend !== '' )
				$lines[] = $trend;
		}

		$lines[] = sprintf( __( '%s content items received views', 'post-views-counter' ), $this->format_number( $this->get_overview_value( $data, 'viewed_content_count' ) ) );

		$rendered = implode( "\n", $lines );

		return apply_filters( 'pvc_email_summary_report_summary_text', $rendered, $data, $settings, $summary_type, $this );
	}

	/**
	 * Render the overview block as HTML.
	 *
	 * @param array $data
	 * @param array $settings
	 * @return string
	 */
	private function render_report_summary_html( $data, $settings ) {
		$summary_type = self::normalize_summary_type_key( ! empty( $data['type'] ) ? $data['type'] : ( ! empty( $data['cadence'] ) ? $data['cadence'] : 'weekly' ) );
		$items = [
			'<p style="' . esc_attr( $this->get_email_style( 'paragraph' ) ) . '">' . sprintf( esc_html__( '%s total views', 'post-views-counter' ), esc_html( $this->format_number( $this->get_overview_value( $data, 'total_views' ) ) ) ) . '</p>'
		];

		if ( ! empty( $settings['include_period_trend'] ) ) {
			$trend = $this->get_trend_summary_text( $data, $settings );

			if ( $trend !== '' )
				$items[] = '<p style="' . esc_attr( $this->get_email_style( 'paragraph' ) ) . '">' . esc_html( $trend ) . '</p>';
		}

		$items[] = '<p style="' . esc_attr( $this->get_email_style( 'paragraph paragraph-last' ) ) . '">' . sprintf( esc_html__( '%s content items received views', 'post-views-counter' ), esc_html( $this->format_number( $this->get_overview_value( $data, 'viewed_content_count' ) ) ) ) . '</p>';

		$rendered = '<div style="' . esc_attr( $this->get_email_style( 'section' ) ) . '">';
		$rendered .= '<p style="' . esc_attr( $this->get_email_style( 'section-title' ) ) . '"><strong>' . esc_html__( 'Content views', 'post-views-counter' ) . '</strong></p>';
		$rendered .= implode( '', $items );
		$rendered .= '</div>';

		return apply_filters( 'pvc_email_summary_report_summary_html', $rendered, $data, $settings, $summary_type, $this );
	}

	/**
	 * Render the top content block as text.
	 *
	 * @param array $data
	 * @return string
	 */
	private function render_top_content_text( $data ) {
		$top_content = isset( $data['top_content'] ) && is_array( $data['top_content'] ) ? $data['top_content'] : [];

		if ( empty( $top_content ) )
			return __( 'No top content was available for this period.', 'post-views-counter' );

		$lines = [ __( 'Top content', 'post-views-counter' ), '' ];

		foreach ( $top_content as $index => $item ) {
			$title = ! empty( $item['title'] ) ? sanitize_text_field( $item['title'] ) : __( '(Untitled)', 'post-views-counter' );
			$views = $this->format_number( isset( $item['current_views'] ) ? $item['current_views'] : 0 );
			$lines[] = sprintf( __( '%1$s. %2$s - %3$s views', 'post-views-counter' ), $index + 1, $title, $views );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Render the top content block as HTML.
	 *
	 * @param array $data
	 * @return string
	 */
	private function render_top_content_html( $data ) {
		$top_content = isset( $data['top_content'] ) && is_array( $data['top_content'] ) ? $data['top_content'] : [];

		if ( empty( $top_content ) )
			return '<div style="' . esc_attr( $this->get_email_style( 'section' ) ) . '"><p style="' . esc_attr( $this->get_email_style( 'paragraph paragraph-last' ) ) . '">' . esc_html__( 'No top content was available for this period.', 'post-views-counter' ) . '</p></div>';

		$items = [];

		foreach ( $top_content as $item ) {
			$title = ! empty( $item['title'] ) ? sanitize_text_field( $item['title'] ) : __( '(Untitled)', 'post-views-counter' );
			$url = ! empty( $item['url'] ) ? esc_url( $item['url'] ) : '';
			$views = $this->format_number( isset( $item['current_views'] ) ? $item['current_views'] : 0 );
			$label = $url !== '' ? '<a href="' . $url . '">' . esc_html( $title ) . '</a>' : esc_html( $title );

			$items[] = '<li style="' . esc_attr( $this->get_email_style( 'list-item' ) ) . '">' . $label . ' - ' . sprintf( esc_html__( '%s views', 'post-views-counter' ), esc_html( $views ) ) . '</li>';
		}

		$html = '<div style="' . esc_attr( $this->get_email_style( 'section' ) ) . '">';
		$html .= '<p style="' . esc_attr( $this->get_email_style( 'section-title' ) ) . '"><strong>' . esc_html__( 'Top content', 'post-views-counter' ) . '</strong></p>';
		$html .= '<ol style="' . esc_attr( $this->get_email_style( 'list' ) ) . '">' . implode( '', $items ) . '</ol>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the traffic signals block as text.
	 *
	 * @param array $data
	 * @param array $settings
	 * @return string
	 */
	private function render_traffic_signals_text( $data, $settings ) {
		$summary_type = self::normalize_summary_type_key( ! empty( $data['type'] ) ? $data['type'] : ( ! empty( $data['cadence'] ) ? $data['cadence'] : 'weekly' ) );
		$rendered = '';

		if ( empty( $settings['include_traffic_signals'] ) )
			return apply_filters( 'pvc_email_summary_traffic_signals_text', $rendered, $data, $settings, $summary_type, $this );

		$message = $this->get_traffic_signals_message( $data );

		if ( $message === '' )
			return apply_filters( 'pvc_email_summary_traffic_signals_text', $rendered, $data, $settings, $summary_type, $this );

		$rendered = __( 'Traffic signals', 'post-views-counter' ) . "\n\n" . $message;

		return apply_filters( 'pvc_email_summary_traffic_signals_text', $rendered, $data, $settings, $summary_type, $this );
	}

	/**
	 * Render the traffic signals block as HTML.
	 *
	 * @param array $data
	 * @param array $settings
	 * @return string
	 */
	private function render_traffic_signals_html( $data, $settings ) {
		$summary_type = self::normalize_summary_type_key( ! empty( $data['type'] ) ? $data['type'] : ( ! empty( $data['cadence'] ) ? $data['cadence'] : 'weekly' ) );
		$rendered = '';

		if ( empty( $settings['include_traffic_signals'] ) )
			return apply_filters( 'pvc_email_summary_traffic_signals_html', $rendered, $data, $settings, $summary_type, $this );

		$message = $this->get_traffic_signals_message( $data );
		$message_key = ! empty( $data['traffic_signals']['message_key'] ) ? sanitize_key( $data['traffic_signals']['message_key'] ) : 'not_enough_data';
		$signal_title = $this->get_traffic_signals_post_value( $data, 'title' );
		$signal_url = $this->get_traffic_signals_post_value( $data, 'url' );

		if ( $message === '' )
			return apply_filters( 'pvc_email_summary_traffic_signals_html', $rendered, $data, $settings, $summary_type, $this );

		if ( $message_key === 'anomaly' && $signal_title !== '' && $signal_url !== '' ) {
			$message = sprintf(
				__( 'Unusual traffic activity was detected for %s.', 'post-views-counter' ),
				'<a href="' . esc_url( $signal_url ) . '">' . esc_html( $signal_title ) . '</a>'
			);

			$rendered = '<div style="' . esc_attr( $this->get_email_style( 'section' ) ) . '"><p style="' . esc_attr( $this->get_email_style( 'section-title' ) ) . '"><strong>' . esc_html__( 'Traffic signals', 'post-views-counter' ) . '</strong></p><p style="' . esc_attr( $this->get_email_style( 'paragraph paragraph-last' ) ) . '">' . $message . '</p></div>';

			return apply_filters( 'pvc_email_summary_traffic_signals_html', $rendered, $data, $settings, $summary_type, $this );
		}

		$rendered = '<div style="' . esc_attr( $this->get_email_style( 'section' ) ) . '"><p style="' . esc_attr( $this->get_email_style( 'section-title' ) ) . '"><strong>' . esc_html__( 'Traffic signals', 'post-views-counter' ) . '</strong></p><p style="' . esc_attr( $this->get_email_style( 'paragraph paragraph-last' ) ) . '">' . esc_html( $message ) . '</p></div>';

		return apply_filters( 'pvc_email_summary_traffic_signals_html', $rendered, $data, $settings, $summary_type, $this );
	}

	/**
	 * Render the manage Emails link as text.
	 *
	 * @param array $data
	 * @return string
	 */
	private function render_manage_emails_link_text( $data ) {
		return sprintf( __( 'Manage Emails in WordPress: %s', 'post-views-counter' ), esc_url_raw( $this->get_manage_emails_url( $data ) ) );
	}

	/**
	 * Render the manage Emails link as HTML.
	 *
	 * @param array $data
	 * @return string
	 */
	private function render_manage_emails_link_html( $data ) {
		$url = esc_url( $this->get_manage_emails_url( $data ) );

		return '<div style="margin:0 0 24px;"><a href="' . $url . '">' . esc_html__( 'Manage Emails in WordPress', 'post-views-counter' ) . '</a></div>';
	}

	/**
	 * Render the site link as text.
	 *
	 * @param array $data
	 * @return string
	 */
	private function render_site_link_text( $data ) {
		return sprintf( __( 'Visit your site: %s', 'post-views-counter' ), esc_url_raw( $this->get_site_url( $data ) ) );
	}

	/**
	 * Render the site link as HTML.
	 *
	 * @param array $data
	 * @return string
	 */
	private function render_site_link_html( $data ) {
		$url = esc_url( $this->get_site_url( $data ) );
		$label = ! empty( $data['site']['name'] ) ? sanitize_text_field( $data['site']['name'] ) : __( 'your site', 'post-views-counter' );

		return '<div style="margin:0 0 24px;"><a href="' . $url . '">' . sprintf( esc_html__( 'Visit %s', 'post-views-counter' ), esc_html( $label ) ) . '</a></div>';
	}

	/**
	 * Get the traffic signals message.
	 *
	 * @param array $data
	 * @return string
	 */
	private function get_traffic_signals_message( $data ) {
		$message_key = ! empty( $data['traffic_signals']['message_key'] ) ? sanitize_key( $data['traffic_signals']['message_key'] ) : 'not_enough_data';
		$signal_title = $this->get_traffic_signals_post_value( $data, 'title' );

		switch ( $message_key ) {
			case 'no_data':
				return '';

			case 'no_visible_post_types':
				return '';

			case 'anomaly':
				if ( $signal_title !== '' )
					return sprintf( __( 'Unusual traffic activity was detected for %s.', 'post-views-counter' ), $signal_title );

				return __( 'Unusual traffic activity was detected during this report period.', 'post-views-counter' );

			case 'silence':
				return __( 'No unusual traffic activity was detected during this report period.', 'post-views-counter' );
		}

		return '';
	}

	/**
	 * Get the period label.
	 *
	 * @param array $data
	 * @return string
	 */
	private function get_period_label( $data ) {
		return ! empty( $data['period']['label'] ) ? sanitize_text_field( $data['period']['label'] ) : '';
	}

	/**
	 * Get the normalized report cadence key.
	 *
	 * @param array $data
	 * @param string $summary_type
	 * @return string
	 */
	private function get_report_cadence_key( $data, $summary_type = 'weekly' ) {
		$cadence = ! empty( $data['cadence'] ) ? sanitize_key( $data['cadence'] ) : sanitize_key( $summary_type );

		return self::normalize_summary_type_key( $cadence );
	}

	/**
	 * Get the comparison period label for trend text.
	 *
	 * @param array $data
	 * @return string
	 */
	private function get_trend_comparison_period_label( $data ) {
		switch ( $this->get_report_cadence_key( $data ) ) {
			case 'daily':
				return __( 'day', 'post-views-counter' );

			case 'monthly':
				return __( 'month', 'post-views-counter' );
		}

		return __( 'week', 'post-views-counter' );
	}

	/**
	 * Normalize a summary cadence key without depending on the period service load order.
	 *
	 * @param string $summary_type
	 * @return string
	 */
	private static function normalize_summary_type_key( $summary_type ) {
		$summary_type = sanitize_key( (string) $summary_type );
		$supported = apply_filters( 'pvc_email_summary_supported_types', [ 'weekly' ] );

		if ( ! is_array( $supported ) )
			$supported = [ 'weekly' ];

		$supported = array_values(
			array_filter(
				array_map(
					static function( $type ) {
						return sanitize_key( (string) $type );
					},
					$supported
				)
			)
		);

		if ( ! in_array( 'weekly', $supported, true ) )
			$supported[] = 'weekly';

		if ( in_array( $summary_type, $supported, true ) )
			return $summary_type;

		return 'weekly';
	}

	/**
	 * Get the report label.
	 *
	 * @param array $data
	 * @param string $summary_type
	 * @return string
	 */
	private function get_report_label( $data, $summary_type = 'weekly' ) {
		$cadence = $this->get_report_cadence_key( $data, $summary_type );
		$label = __( 'weekly', 'post-views-counter' );
		$label = apply_filters( 'pvc_email_summary_report_label', $label, $cadence, $data, $summary_type );

		return sanitize_text_field( (string) $label );
	}

	/**
	 * Get the report type label.
	 *
	 * @param array $data
	 * @param string $summary_type
	 * @return string
	 */
	private function get_report_type_label( $data, $summary_type = 'weekly' ) {
		$cadence = $this->get_report_cadence_key( $data, $summary_type );
		$label = __( 'Weekly Content Views Summary', 'post-views-counter' );
		$label = apply_filters( 'pvc_email_summary_report_type_label', $label, $cadence, $data, $summary_type );

		return sanitize_text_field( (string) $label );
	}

	/**
	 * Get a formatted period date.
	 *
	 * @param array $data
	 * @param string $key
	 * @return string
	 */
	private function get_period_date( $data, $key ) {
		if ( empty( $data['period'][$key] ) )
			return '';

		$timezone = $this->get_period_timezone( $data );
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d', $data['period'][$key], $timezone );

		if ( ! $datetime )
			return sanitize_text_field( $data['period'][$key] );

		return wp_date( get_option( 'date_format' ), $datetime->getTimestamp(), $timezone );
	}

	/**
	 * Get the period timezone.
	 *
	 * @param array $data
	 * @return DateTimeZone
	 */
	private function get_period_timezone( $data ) {
		if ( ! empty( $data['period']['timezone'] ) ) {
			try {
				return new DateTimeZone( $data['period']['timezone'] );
			} catch ( Exception $e ) {
				return wp_timezone();
			}
		}

		return wp_timezone();
	}

	/**
	 * Get an overview value.
	 *
	 * @param array $data
	 * @param string $key
	 * @return int|float
	 */
	private function get_overview_value( $data, $key ) {
		return isset( $data['overview'][$key] ) ? $data['overview'][$key] : 0;
	}

	/**
	 * Get a value from the first top-content item.
	 *
	 * @param array $data
	 * @param string $key
	 * @return mixed
	 */
	private function get_top_content_value( $data, $key ) {
		if ( empty( $data['top_content'][0] ) || ! is_array( $data['top_content'][0] ) )
			return '';

		return isset( $data['top_content'][0][$key] ) ? $data['top_content'][0][$key] : '';
	}

	/**
	 * Get a value for the traffic signals content item.
	 *
	 * @param array $data
	 * @param string $key
	 * @return mixed
	 */
	private function get_traffic_signals_post_value( $data, $key ) {
		$post_id = ! empty( $data['traffic_signals']['post_id'] ) ? (int) $data['traffic_signals']['post_id'] : 0;

		if ( $post_id <= 0 )
			return '';

		if ( ! empty( $data['top_content'] ) && is_array( $data['top_content'] ) ) {
			foreach ( $data['top_content'] as $item ) {
				if ( ! is_array( $item ) || empty( $item['post_id'] ) || (int) $item['post_id'] !== $post_id )
					continue;

				return isset( $item[$key] ) ? $item[$key] : '';
			}
		}

		if ( $key === 'title' )
			return sanitize_text_field( get_the_title( $post_id ) );

		if ( $key === 'url' ) {
			$url = get_permalink( $post_id );

			return is_string( $url ) ? esc_url_raw( $url ) : '';
		}

		return '';
	}

	/**
	 * Format a number for output.
	 *
	 * @param int|float|string $value
	 * @return string
	 */
	private function format_number( $value ) {
		return number_format_i18n( (float) $value, 0 );
	}

	/**
	 * Get a formatted signed views change string.
	 *
	 * @param array $data
	 * @param array $settings
	 * @return string
	 */
	private function get_formatted_views_change( $data, $settings ) {
		if ( empty( $settings['include_period_trend'] ) || empty( $data['overview']['trend_reliable'] ) )
			return '';

		$change = (int) $this->get_overview_value( $data, 'views_change' );

		return $this->format_signed_number( $change ) . ' ' . __( 'views', 'post-views-counter' );
	}

	/**
	 * Get a formatted signed percentage change string.
	 *
	 * @param array $data
	 * @param array $settings
	 * @return string
	 */
	private function get_formatted_views_change_percent( $data, $settings ) {
		if ( empty( $settings['include_period_trend'] ) || empty( $data['overview']['trend_reliable'] ) || ! isset( $data['overview']['views_change_percent'] ) )
			return '';

		$percent = (float) $data['overview']['views_change_percent'];

		return $this->format_signed_decimal( $percent ) . '%';
	}

	/**
	 * Get a trend summary string.
	 *
	 * @param array $data
	 * @param array $settings
	 * @return string
	 */
	private function get_trend_summary_text( $data, $settings ) {
		if ( empty( $settings['include_period_trend'] ) )
			return '';

		$comparison_period = $this->get_trend_comparison_period_label( $data );

		if ( empty( $data['overview']['trend_reliable'] ) )
			return sprintf( __( 'No previous %s comparison is available yet', 'post-views-counter' ), $comparison_period );

		$change_value = (int) $this->get_overview_value( $data, 'views_change' );
		$percent = $this->get_formatted_views_change_percent( $data, $settings );

		if ( $change_value === 0 )
			return sprintf( __( 'No change from the previous %s', 'post-views-counter' ), $comparison_period );

		$change = $this->format_number( abs( $change_value ) );

		if ( $change_value > 0 )
			$summary = sprintf( __( 'Up %1$s views from the previous %2$s', 'post-views-counter' ), $change, $comparison_period );
		else
			$summary = sprintf( __( 'Down %1$s views from the previous %2$s', 'post-views-counter' ), $change, $comparison_period );

		if ( $percent !== '' )
			return $summary . ' (' . $percent . ')';

		return $summary;
	}

	/**
	 * Get the site name.
	 *
	 * @param array $data
	 * @return string
	 */
	private function get_site_name( $data ) {
		if ( ! empty( $data['site']['name'] ) )
			return sanitize_text_field( $data['site']['name'] );

		return get_bloginfo( 'name' );
	}

	/**
	 * Get the site URL.
	 *
	 * @param array $data
	 * @return string
	 */
	private function get_site_url( $data ) {
		if ( ! empty( $data['links']['site_url'] ) )
			return $data['links']['site_url'];

		return home_url( '/' );
	}

	/**
	 * Get the Emails settings URL.
	 *
	 * @param array $data
	 * @return string
	 */
	private function get_manage_emails_url( $data ) {
		if ( ! empty( $data['links']['emails_settings_url'] ) )
			return $data['links']['emails_settings_url'];

		return admin_url( 'admin.php?page=post-views-counter&tab=emails' );
	}

	/**
	 * Format a signed integer string.
	 *
	 * @param int $value
	 * @return string
	 */
	private function format_signed_number( $value ) {
		$formatted = $this->format_number( absint( $value ) );

		if ( (int) $value > 0 )
			return '+' . $formatted;

		if ( (int) $value < 0 )
			return '-' . $formatted;

		return $formatted;
	}

	/**
	 * Format a signed decimal string.
	 *
	 * @param float $value
	 * @return string
	 */
	private function format_signed_decimal( $value ) {
		$formatted = number_format_i18n( abs( (float) $value ), 2 );

		if ( (float) $value > 0 )
			return '+' . $formatted;

		if ( (float) $value < 0 )
			return '-' . $formatted;

		return $formatted;
	}

	/**
	 * Sanitize plaintext output.
	 *
	 * @param string $text
	 * @param bool $allow_newlines
	 * @return string
	 */
	private function sanitize_text_output( $text, $allow_newlines = false ) {
		$text = (string) $text;

		return $allow_newlines ? sanitize_textarea_field( $text ) : sanitize_text_field( $text );
	}

	/**
	 * Sanitize rendered HTML output.
	 *
	 * @param string $html
	 * @return string
	 */
	private function sanitize_html_output( $html ) {
		return self::sanitize_template_html( $html, true );
	}

	/**
	 * Get the email-safe HTML allowlist for the full wrapper document.
	 *
	 * @return array
	 */
	private static function get_email_document_allowed_html() {
		$allowed_html = self::get_email_template_allowed_html( true );
		$global_attributes = [
			'class' => true,
			'id' => true,
			'title' => true,
			'aria-label' => true,
			'style' => true,
		];

		$allowed_html['html'] = [
			'lang' => true,
			'dir' => true,
		];
		$allowed_html['head'] = [];
		$allowed_html['body'] = $global_attributes;
		$allowed_html['meta'] = [
			'charset' => true,
			'content' => true,
			'http-equiv' => true,
			'name' => true,
		];
		$allowed_html['style'] = [
			'media' => true,
			'type' => true,
		];
		$allowed_html['title'] = [];
		$allowed_html['table'] = array_merge(
			$global_attributes,
			[
				'align' => true,
				'border' => true,
				'cellpadding' => true,
				'cellspacing' => true,
				'role' => true,
				'width' => true,
			]
		);
		$allowed_html['tbody'] = $global_attributes;
		$allowed_html['thead'] = $global_attributes;
		$allowed_html['tfoot'] = $global_attributes;
		$allowed_html['tr'] = $global_attributes;
		$allowed_html['td'] = array_merge(
			$global_attributes,
			[
				'align' => true,
				'colspan' => true,
				'height' => true,
				'rowspan' => true,
				'valign' => true,
				'width' => true,
			]
		);
		$allowed_html['th'] = $allowed_html['td'];

		return $allowed_html;
	}

	/**
	 * Get the email-safe HTML allowlist.
	 *
	 * @param bool $allow_styles
	 * @return array
	 */
	private static function get_email_template_allowed_html( $allow_styles = false ) {
		$post_allowed_html = wp_kses_allowed_html( 'post' );
		$allowed_tags = [ 'a', 'b', 'br', 'div', 'em', 'h1', 'h2', 'h3', 'h4', 'i', 'li', 'ol', 'p', 'span', 'strong', 'u', 'ul' ];
		$global_attributes = [
			'class' => true,
			'id' => true,
			'title' => true,
			'aria-label' => true
		];
		$allowed_html = [];

		foreach ( $allowed_tags as $tag ) {
			$allowed_html[$tag] = array_intersect_key( isset( $post_allowed_html[$tag] ) ? $post_allowed_html[$tag] : [], $global_attributes );
			$allowed_html[$tag] = array_merge( $allowed_html[$tag], $global_attributes );

			if ( $allow_styles && $tag !== 'br' )
				$allowed_html[$tag]['style'] = true;
		}

		$allowed_html['a'] = array_merge(
			$allowed_html['a'],
			[
				'href' => true,
				'target' => true,
				'rel' => true
			]
		);

		$allowed_html['br'] = [];

		return $allowed_html;
	}

	/**
	 * Get the safe inline CSS properties for plugin-generated email HTML.
	 *
	 * @return array
	 */
	private static function get_email_template_safe_css() {
		return [
			'background-color',
			'border',
			'border-collapse',
			'border-spacing',
			'box-sizing',
			'color',
			'display',
			'font-family',
			'font-size',
			'font-weight',
			'height',
			'line-height',
			'margin',
			'margin-bottom',
			'margin-left',
			'margin-right',
			'margin-top',
			'max-width',
			'min-width',
			'overflow-wrap',
			'padding',
			'padding-bottom',
			'padding-left',
			'padding-right',
			'padding-top',
			'text-align',
			'text-decoration',
			'vertical-align',
			'width',
			'word-break',
			'word-wrap'
		];
	}

	/**
	 * Get the safe inline CSS properties for the full email document wrapper.
	 *
	 * @return array
	 */
	private static function get_email_document_safe_css() {
		return self::get_email_template_safe_css();
	}

	/**
	 * Mask template tags so KSES does not strip them from safe attributes.
	 *
	 * @param string $html
	 * @return array
	 */
	private static function mask_template_tags( $html ) {
		$index = 0;
		$replacements = [];
		$html = preg_replace_callback(
			'/%%[a-z0-9_]+%%/i',
			static function( $matches ) use ( &$index, &$replacements ) {
				$placeholder = 'https://pvc-template-tag.local/' . $index . '/';
				$replacements[$placeholder] = $matches[0];
				$index++;

				return $placeholder;
			},
			(string) $html
		);

		return [
			'html' => $html,
			'replacements' => $replacements
		];
	}

	/**
	 * Restore masked template tags after sanitization.
	 *
	 * @param string $html
	 * @param array $replacements
	 * @return string
	 */
	private static function restore_masked_template_tags( $html, $replacements ) {
		if ( empty( $replacements ) || ! is_array( $replacements ) )
			return (string) $html;

		return strtr( (string) $html, $replacements );
	}

	/**
	 * Get the blog charset with a safe fallback.
	 *
	 * @return string
	 */
	private function get_charset() {
		$charset = get_bloginfo( 'charset' );

		return is_string( $charset ) && $charset !== '' ? $charset : 'UTF-8';
	}

	/**
	 * Backward-compatible wrapper for deterministic PVC link parameters.
	 *
	 * @param string $url
	 * @param string $medium
	 * @param string $context
	 * @param string $content
	 * @return string
	 */
	public function add_pvc_utm_args( $url, $medium = 'email', $context = 'free', $content = 'content-summary' ) {
		return $this->pvc->add_postviewscounter_utm_args( $url, $medium, 'content-summary', $content, $context );
	}
}
