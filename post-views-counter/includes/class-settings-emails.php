<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Settings_Emails class.
 *
 * @class Post_Views_Counter_Settings_Emails
 */
class Post_Views_Counter_Settings_Emails {

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
		add_action( 'wp_ajax_pvc_send_test_email', [ $this, 'handle_test_email_ajax' ] );
	}

	/**
	 * Get sections.
	 *
	 * @return array
	 */
	public function get_sections() {
		return [
			'post_views_counter_emails_summary' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Email Summaries', 'post-views-counter' ),
				'callback'		=> [ $this, 'section_summary' ]
			],
			'post_views_counter_emails_recipients' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Email Recipients', 'post-views-counter' ),
				'callback'		=> [ $this, 'section_recipients' ]
			],
			'post_views_counter_emails_template' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Email Template', 'post-views-counter' ),
				'callback'		=> [ $this, 'section_template' ]
			],
			'post_views_counter_emails_test' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Test Email', 'post-views-counter' ),
				'callback'		=> [ $this, 'section_test' ]
			],
			'post_views_counter_emails_status' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Delivery & Status', 'post-views-counter' ),
				'callback'		=> [ $this, 'section_status' ]
			]
		];
	}

	/**
	 * Get fields.
	 *
	 * @return array
	 */
	public function get_fields() {
		return [
			'enabled' => [
				'field_key'		=> 'enabled',
				'tab'			=> 'emails',
				'title'			=> __( 'Email Summary', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_summary',
				'type'			=> 'boolean',
				'label'			=> __( 'Send a summary of tracked content views to the selected recipient.', 'post-views-counter' )
			],
			'enabled_frequencies' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Summary Frequency', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_summary',
				'type'			=> 'checkbox',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'pro_only'		=> true,
				'value'			=> [ 'weekly' ],
				'options'		=> [
					'daily'		=> __( 'Daily', 'post-views-counter' ),
					'weekly'	=> __( 'Weekly', 'post-views-counter' ),
					'monthly'	=> __( 'Monthly', 'post-views-counter' )
				],
				'description'	=> __( 'Choose how often views tracking summaries are sent.', 'post-views-counter' )
			],
			'min_views_threshold' => [
				'field_key'		=> 'min_views_threshold',
				'tab'			=> 'emails',
				'title'			=> __( 'Minimum Tracked Views', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_summary',
				'type'			=> 'number',
				'min'			=> 0,
				'max'			=> 1000000,
				'description'	=> __( 'Do not send the summary if the report period has fewer than this many tracked views.', 'post-views-counter' ),
				'validate'		=> [ $this, 'validate_min_views_threshold' ]
			],
			'max_top_items' => [
				'field_key'		=> 'max_top_items',
				'tab'			=> 'emails',
				'title'			=> __( 'Top Content Items', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_summary',
				'type'			=> 'number',
				'min'			=> 3,
				'max'			=> 10,
				'description'	=> __( 'Choose how many top content items are included in each summary.', 'post-views-counter' ),
				'validate'		=> [ $this, 'validate_max_top_items' ]
			],
			'include_top_gainers_decliners' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Top Gainers and Decliners', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_summary',
				'type'			=> 'boolean',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'pro_only'		=> true,
				'value'			=> false,
				'label'			=> __( 'Show which content is gaining or losing momentum compared with previous periods.', 'post-views-counter' ),
				'description'	=> __( 'When matching data is available, this appears in the Traffic signals section if the email template includes %%traffic_signals%%.', 'post-views-counter' )
			],
			'include_author_summary' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Author Summary', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_summary',
				'type'			=> 'boolean',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'pro_only'		=> true,
				'value'			=> false,
				'label'			=> __( 'Include the authors whose content received the most tracked views during the report period.', 'post-views-counter' ),
				'description'	=> __( 'When matching data is available, this appears in the Report summary section if the email template includes %%report_summary%%.', 'post-views-counter' )
			],
			'include_source_summary' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Source Summary', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_summary',
				'type'			=> 'boolean',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'pro_only'		=> true,
				'value'			=> false,
				'label'			=> __( 'Include top referrers or source dimensions when source tracking is available.', 'post-views-counter' ),
				'description'	=> __( 'When source tracking data is available, this appears in the Report summary section if the email template includes %%report_summary%%.', 'post-views-counter' )
			],
			'recipient' => [
				'field_key'		=> 'recipient',
				'tab'			=> 'emails',
				'title'			=> __( 'Recipient Email', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_recipients',
				'type'			=> 'input',
				'subclass'		=> 'regular-text',
				'description'	=> __( 'The email address that receives summary emails. Defaults to the site administrator email.', 'post-views-counter' ),
				'validate'		=> [ $this, 'validate_recipient' ]
			],
			'additional_recipients' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Additional Recipients', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_recipients',
				'type'			=> 'boolean',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'pro_only'		=> true,
				'value'			=> false,
				'label'			=> __( 'Send summaries to multiple people with CC and BCC recipients.', 'post-views-counter' )
			],
			'cc_recipients' => [
				'tab'			=> 'emails',
				'title'			=> __( 'CC Recipients', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_recipients',
				'type'			=> 'input',
				'subclass'		=> 'regular-text',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'pro_only'		=> true,
				'value'			=> '',
				'logic'			=> [
					'field'		=> 'additional_recipients',
					'operator'	=> 'is',
					'value'		=> 'true'
				],
				'animation'		=> 'slide',
				'description'	=> __( 'Send visible copies to additional recipients.', 'post-views-counter' )
			],
			'bcc_recipients' => [
				'tab'			=> 'emails',
				'title'			=> __( 'BCC Recipients', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_recipients',
				'type'			=> 'input',
				'subclass'		=> 'regular-text',
				'class'			=> 'pvc-pro',
				'disabled'		=> true,
				'skip_saving'	=> true,
				'pro_only'		=> true,
				'value'			=> '',
				'logic'			=> [
					'field'		=> 'additional_recipients',
					'operator'	=> 'is',
					'value'		=> 'true'
				],
				'animation'		=> 'slide',
				'description'	=> __( 'Send private copies to additional recipients.', 'post-views-counter' )
			],
			'email_subject_template' => [
				'field_key'		=> 'email_subject_template',
				'tab'			=> 'emails',
				'title'			=> __( 'Email Subject', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_template',
				'type'			=> 'input',
				'subclass'		=> 'regular-text',
				'description'	=> $this->get_subject_template_description(),
				'validate'		=> [ $this, 'validate_template_text' ]
			],
			'email_body_template' => [
				'field_key'		=> 'email_body_template',
				'tab'			=> 'emails',
				'title'			=> __( 'Email Content', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_template',
				'type'			=> 'editor',
				'rows'			=> 10,
				'description'	=> $this->get_content_template_description(),
				'validate'		=> [ $this, 'validate_template_text' ]
			],
			'test_email_scaffold' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Send Test To', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_test',
				'type'			=> 'custom',
				'skip_saving'	=> true,
				'callback'		=> [ $this, 'setting_test_email_scaffold' ]
			],
			'schedule_status' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Schedule Status', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_status',
				'type'			=> 'info',
				'text'			=> __( 'Weekly summaries are scheduled for Monday after the previous full week has ended.', 'post-views-counter' ),
				'description'	=> __( 'Summary emails are scheduled with WordPress cron. Delivery time may vary depending on site traffic and hosting configuration.', 'post-views-counter' ),
				'skip_saving'	=> true
			],
			'next_scheduled_email' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Next Scheduled Email', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_status',
				'type'			=> 'info',
				'text'			=> $this->get_next_scheduled_text(),
				'skip_saving'	=> true
			],
			'last_sent_status' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Last Sent', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_status',
				'type'			=> 'info',
				'text'			=> $this->get_last_sent_text(),
				'skip_saving'	=> true
			],
			'last_error_status' => [
				'tab'			=> 'emails',
				'title'			=> __( 'Last Error', 'post-views-counter' ),
				'section'		=> 'post_views_counter_emails_status',
				'type'			=> 'info',
				'text'			=> $this->get_last_error_text(),
				'skip_saving'	=> true
			]
		];
	}

	/**
	 * Section description: summary.
	 *
	 * @return void
	 */
	public function section_summary() {
		echo '<p class="description">' . esc_html__( 'Send email summaries with your most viewed content and view totals.', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Section description: recipients.
	 *
	 * @return void
	 */
	public function section_recipients() {
		echo '<p class="description">' . esc_html__( 'Choose who should receive your email summaries.', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Section description: status.
	 *
	 * @return void
	 */
	public function section_status() {
		echo '<p class="description">' . esc_html__( 'Review scheduling, delivery, and recent email status information.', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Section description: template.
	 *
	 * @return void
	 */
	public function section_template() {
		echo '<p class="description">' . esc_html__( 'Customize the subject and message used for content views summaries.', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Section description: test.
	 *
	 * @return void
	 */
	public function section_test() {
		echo $this->get_test_email_notice_container_html();
		echo '<p class="description">' . esc_html__( 'Send a test using the latest available summary. This does not change the saved recipient or scheduled delivery.', 'post-views-counter' ) . '</p>';
	}

	/**
	 * Setting: test email scaffold.
	 *
	 * @return string
	 */
	public function setting_test_email_scaffold( $field ) {
		$default_recipient = $this->get_test_email_default_recipient();
		$nonce = wp_create_nonce( 'pvc_send_test_email_ajax' );
		$html = '<div class="pvc-email-test-scaffold">';
		$html .= '<p><input id="pvc-test-email-recipient" type="email" class="regular-text" name="pvc_test_email_recipient" value="' . esc_attr( $default_recipient ) . '" placeholder="' . esc_attr__( 'email@example.com', 'post-views-counter' ) . '" aria-label="' . esc_attr__( 'Test email recipient', 'post-views-counter' ) . '" /></p>';
		$html .= '<p><button type="button" class="button outline pvc-send-test-email" data-pvc-email-test-nonce="' . esc_attr( $nonce ) . '" data-default-label="' . esc_attr__( 'Send Test Email', 'post-views-counter' ) . '" data-sending-label="' . esc_attr__( 'Sending...', 'post-views-counter' ) . '">' . esc_html__( 'Send Test Email', 'post-views-counter' ) . '</button></p>';
		$html .= '<p class="description">' . esc_html__( 'Enter an email address for this test only.', 'post-views-counter' ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Handle a one-off Ajax test email request.
	 *
	 * @return void
	 */
	public function handle_test_email_ajax() {
		$capability = apply_filters( 'pvc_settings_capability', 'manage_options' );

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error(
				[
					'code' => 'forbidden',
					'message' => __( 'Sorry, you are not allowed to do that.', 'post-views-counter' )
				],
				403
			);
		}

		check_ajax_referer( 'pvc_send_test_email_ajax', 'nonce' );

		$recipient = isset( $_POST['pvc_test_email_recipient'] ) ? sanitize_email( wp_unslash( $_POST['pvc_test_email_recipient'] ) ) : '';
		$result = $this->send_test_email_request( $recipient );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success(
				[
					'message' => $result['message']
				]
			);
		}

		wp_send_json_error(
			[
				'code' => $result['code'],
				'message' => $result['message']
			],
			$this->get_test_email_status_code( $result['code'] )
		);
	}

	/**
	 * Run a one-off test email request.
	 *
	 * @param string $recipient
	 * @return array
	 */
	private function send_test_email_request( $recipient ) {
		if ( ! is_email( $recipient ) ) {
			return [
				'success' => false,
				'code' => 'invalid_recipient',
				'message' => __( 'Enter a valid test recipient email address.', 'post-views-counter' )
			];
		}

		$base_settings = isset( $this->pvc->options['emails'] ) && is_array( $this->pvc->options['emails'] ) ? $this->pvc->options['emails'] : [];
		$test_settings = array_merge(
			$base_settings,
			[
				'enabled' => true,
				'recipient' => $recipient,
				'min_views_threshold' => 0,
				'send_empty_reports' => true
			]
		);
		$emails = new Post_Views_Counter_Emails();
		$rendered = $emails->render_weekly_summary( null, [ 'settings' => $test_settings ] );
		$data = isset( $rendered['data'] ) && is_array( $rendered['data'] ) ? $rendered['data'] : [];

		if ( ! $this->has_real_test_email_data( $data ) ) {
			return [
				'success' => false,
				'code' => 'not_enough_data',
				'message' => __( 'There is not enough tracked data from the latest complete week to send a real test summary yet.', 'post-views-counter' )
			];
		}

		$mailer = new Post_Views_Counter_Emails_Mailer( $emails );
		$result = $mailer->send_rendered_summary(
			$rendered,
			[
				'recipient' => $recipient,
				'is_test' => true,
				'source' => 'test',
				'summary_type' => 'weekly',
				'settings' => $test_settings
			]
		);

		if ( ! empty( $result['sent'] ) ) {
			return [
				'success' => true,
				'code' => 'sent',
				'message' => __( 'The summary test email was sent successfully.', 'post-views-counter' )
			];
		}

		return [
			'success' => false,
			'code' => 'send_failed',
			'message' => ! empty( $result['error'] )
				? sprintf( __( 'The test email could not be sent. %s', 'post-views-counter' ), sanitize_text_field( $result['error'] ) )
				: __( 'The test email could not be sent. Check your WordPress mail configuration and try again.', 'post-views-counter' )
		];
	}

	/**
	 * Validate recipient.
	 *
	 * @param string $value
	 * @param array $field
	 * @return string
	 */
	public function validate_recipient( $value, $field ) {
		$value = sanitize_email( $value );
		$enabled = $this->is_emails_enabled_in_request();
		$previous = $this->get_previous_value( $field, $this->pvc->get_default_emails_settings()['recipient'] );

		if ( $enabled && $value === '' ) {
			add_settings_error( 'post_views_counter_settings_emails', 'pvc_emails_recipient_required', __( 'Recipient email is required while the views summary email is enabled.', 'post-views-counter' ), 'error' );

			return $previous;
		}

		if ( $value !== '' && ! is_email( $value ) ) {
			add_settings_error( 'post_views_counter_settings_emails', 'pvc_emails_recipient_invalid', __( 'Enter a valid recipient email address for the views summary email.', 'post-views-counter' ), 'error' );

			return $previous;
		}

		return $value;
	}

	/**
	 * Validate minimum views threshold.
	 *
	 * @param mixed $value
	 * @param array $field
	 * @return int
	 */
	public function validate_min_views_threshold( $value, $field ) {
		$threshold = (int) $value;

		if ( $threshold < 0 ) {
			add_settings_error( 'post_views_counter_settings_emails', 'pvc_emails_threshold_invalid', __( 'Minimum tracked views cannot be lower than 0.', 'post-views-counter' ), 'error' );
			$threshold = 0;
		}

		if ( isset( $field['max'] ) )
			$threshold = min( $threshold, (int) $field['max'] );

		return $threshold;
	}

	/**
	 * Validate email template text.
	 *
	 * @param string $value
	 * @param array $field
	 * @return string
	 */
	public function validate_template_text( $value, $field ) {
		$field_key = ! empty( $field['field_key'] ) ? sanitize_key( $field['field_key'] ) : '';
		$context = $this->get_template_context_for_field( $field_key );
		$is_body_template = $field_key === 'email_body_template' || ( ! empty( $field['type'] ) && $field['type'] === 'editor' );
		$sanitized = $is_body_template ? Post_Views_Counter_Emails_Template::sanitize_template_html( $value ) : sanitize_text_field( $value );
		$cleaned_template = Post_Views_Counter_Emails_Template::strip_invalid_template_tags( $sanitized, $context );

		if ( ! empty( $cleaned_template['removed_tags'] ) ) {
			add_settings_error(
				'post_views_counter_settings_emails',
				'pvc_emails_template_tags_invalid_' . sanitize_key( $field['field_key'] ),
				sprintf(
					__( '%1$s contained tags that are not available in this field. Removed tags: %2$s. Supported tags here: %3$s', 'post-views-counter' ),
					wp_strip_all_tags( $field['title'] ),
					implode( ', ', array_map( 'sanitize_text_field', $cleaned_template['removed_tags'] ) ),
					implode( ', ', array_map( 'sanitize_text_field', $this->get_template_tags_for_field( $field_key ) ) )
				),
				'warning'
			);
		}

		return isset( $cleaned_template['template'] ) ? $cleaned_template['template'] : $sanitized;
	}

	/**
	 * Validate the top content items limit.
	 *
	 * @param mixed $value
	 * @param array $field
	 * @return int
	 */
	public function validate_max_top_items( $value, $field ) {
		$max_top_items = (int) $value;

		if ( isset( $field['min'] ) )
			$max_top_items = max( (int) $field['min'], $max_top_items );

		if ( isset( $field['max'] ) )
			$max_top_items = min( (int) $field['max'], $max_top_items );

		return $max_top_items;
	}

	/**
	 * Determine whether PVC Pro is active.
	 *
	 * @return bool
	 */
	private function is_pro_active() {
		return class_exists( 'Post_Views_Counter_Pro' );
	}

	/**
	 * Get supported template tags.
	 *
	 * @return array
	 */
	public function get_free_template_tags() {
		return Post_Views_Counter_Emails_Template::get_free_template_tags();
	}

	/**
	 * Find unsupported template tags.
	 *
	 * @param string $template
	 * @param string $context
	 * @return array
	 */
	public function find_unknown_template_tags( $template, $context = 'content' ) {
		return Post_Views_Counter_Emails_Template::find_unknown_template_tags( $template, [], 'weekly', $context );
	}

	/**
	 * Get the supported tag context for a field.
	 *
	 * @param string $field_key
	 * @return string
	 */
	private function get_template_context_for_field( $field_key ) {
		return $field_key === 'email_subject_template' ? 'subject' : 'content';
	}

	/**
	 * Get supported template tags for a specific field.
	 *
	 * @param string $field_key
	 * @return array
	 */
	private function get_template_tags_for_field( $field_key ) {
		if ( $this->get_template_context_for_field( $field_key ) === 'subject' )
			return Post_Views_Counter_Emails_Template::get_subject_template_tags();

		return Post_Views_Counter_Emails_Template::get_content_template_tags();
	}

	/**
	 * Get the primary content block tags shown inline for the email body.
	 *
	 * @return array
	 */
	private function get_primary_content_template_tags() {
		return [
			'%%site_name%%',
			'%%report_label%%',
			'%%period_label%%',
			'%%report_summary%%',
			'%%top_content%%',
			'%%traffic_signals%%'
		];
	}

	/**
	 * Get the subject tags shown in the UI.
	 *
	 * Keep this list intentionally smaller than the full allowlist used
	 * for validation and rendering.
	 *
	 * @return array
	 */
	private function get_display_subject_template_tags() {
		return [
			'%%site_name%%',
			'%%report_label%%',
			'%%period_label%%',
			'%%total_views%%',
			'%%top_post_title%%'
		];
	}

	/**
	 * Get the Email Subject field description.
	 *
	 * @return string
	 */
	private function get_subject_template_description() {
		$html = esc_html__( 'Customize the subject line for summary emails.', 'post-views-counter' );
		$html .= ' ' . sprintf(
			esc_html__( 'Use supported subject tags such as %1$s and %2$s.', 'post-views-counter' ),
			'<code>%%site_name%%</code>',
			'<code>%%total_views%%</code>'
		);

		return $this->sanitize_description_html( $html );
	}

	/**
	 * Get the Email Content field description.
	 *
	 * @return string
	 */
	private function get_content_template_description() {
		$html = esc_html__( 'Customize the main message sent with each email summary.', 'post-views-counter' );
		$html .= ' ' . sprintf(
			esc_html__( 'Use supported content tags such as %s.', 'post-views-counter' ),
			$this->render_template_tags_html( array_merge( [ '%%site_url%%' ], $this->get_primary_content_template_tags() ) )
		);

		return $this->sanitize_description_html( $html );
	}

	/**
	 * Render template tags as safe inline HTML.
	 *
	 * @param array $tags
	 * @return string
	 */
	private function render_template_tags_html( $tags ) {
		$tags = is_array( $tags ) ? $tags : [];

		if ( empty( $tags ) )
			return '';

		$tag_html = array_map(
			static function( $tag ) {
				return '<code>' . esc_html( sanitize_text_field( $tag ) ) . '</code>';
			},
			$tags
		);

		return implode( ', ', $tag_html );
	}

	/**
	 * Sanitize safe HTML used in settings field descriptions.
	 *
	 * @param string $html
	 * @return string
	 */
	private function sanitize_description_html( $html ) {
		return wp_kses(
			(string) $html,
			[
				'code' => [],
				'span' => [
					'class' => true
				]
			]
		);
	}

	/**
	 * Get previous saved value for a field.
	 *
	 * @param array $field
	 * @param mixed $fallback
	 * @return mixed
	 */
	public function get_previous_value( $field, $fallback = null ) {
		$field_key = ! empty( $field['field_key'] ) ? $field['field_key'] : '';

		if ( $field_key !== '' && array_key_exists( $field_key, $this->pvc->options['emails'] ) )
			return $this->pvc->options['emails'][$field_key];

		return $fallback;
	}

	/**
	 * Determine whether emails are enabled in the current request.
	 *
	 * @return bool
	 */
	private function is_emails_enabled_in_request() {
		$request = isset( $_POST['post_views_counter_settings_emails'] ) && is_array( $_POST['post_views_counter_settings_emails'] ) ? wp_unslash( $_POST['post_views_counter_settings_emails'] ) : [];

		if ( array_key_exists( 'enabled', $request ) )
			return $request['enabled'] === 'true' || $request['enabled'] === true || $request['enabled'] === '1' || $request['enabled'] === 1;

		return ! empty( $this->pvc->options['emails']['enabled'] );
	}

	/**
	 * Get formatted last sent text.
	 *
	 * @return string
	 */
	private function get_last_sent_text() {
		$value = ! empty( $this->pvc->options['emails']['last_sent_at'] ) ? $this->format_saved_datetime( $this->pvc->options['emails']['last_sent_at'] ) : '';

		return $value !== '' ? $value : __( 'Not sent yet.', 'post-views-counter' );
	}

	/**
	 * Get formatted next scheduled send text.
	 *
	 * @return string
	 */
	private function get_next_scheduled_text() {
		$timestamp = false;

		if ( isset( $this->pvc->emails_scheduler ) && $this->pvc->emails_scheduler instanceof Post_Views_Counter_Emails_Scheduler )
			$timestamp = $this->pvc->emails_scheduler->get_next_scheduled_timestamp();
		else
			$timestamp = wp_next_scheduled( 'pvc_weekly_content_summary_send' );

		if ( ! $timestamp )
			return __( 'Not scheduled.', 'post-views-counter' );

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Get formatted last error text.
	 *
	 * @return string
	 */
	private function get_last_error_text() {
		$value = $this->get_latest_error_message();

		return $value !== '' ? $value : __( 'No email errors recorded yet.', 'post-views-counter' );
	}

	/**
	 * Get the latest stored email error message.
	 *
	 * @return string
	 */
	private function get_latest_error_message() {
		if ( ! empty( $this->pvc->options['emails']['latest_status']['last_error'] ) )
			return sanitize_text_field( $this->pvc->options['emails']['latest_status']['last_error'] );

		if ( ! empty( $this->pvc->options['emails']['last_error'] ) )
			return sanitize_text_field( $this->pvc->options['emails']['last_error'] );

		return '';
	}

	/**
	 * Get the default test email recipient.
	 *
	 * @return string
	 */
	private function get_test_email_default_recipient() {
		$recipient = ! empty( $this->pvc->options['emails']['recipient'] ) ? sanitize_email( $this->pvc->options['emails']['recipient'] ) : '';

		if ( is_email( $recipient ) )
			return $recipient;

		$admin_email = sanitize_email( get_option( 'admin_email' ) );

		return is_email( $admin_email ) ? $admin_email : '';
	}

	/**
	 * Determine whether the rendered summary contains real complete-period data.
	 *
	 * @param array $data
	 * @return bool
	 */
	private function has_real_test_email_data( $data ) {
		if ( ! is_array( $data ) )
			return false;

		if ( ! empty( $data['status']['empty'] ) )
			return false;

		return ! empty( $data['overview']['total_views'] ) && ! empty( $data['overview']['viewed_content_count'] );
	}

	/**
	 * Get the current test email notice container markup.
	 *
	 * @return string
	 */
	private function get_test_email_notice_container_html() {
		return '<div id="pvc-email-test-notice" class="pvc-email-test-notice" aria-live="polite"></div>';
	}

	/**
	 * Get the HTTP status code for a test email response.
	 *
	 * @param string $code
	 * @return int
	 */
	private function get_test_email_status_code( $code ) {
		if ( $code === 'not_enough_data' )
			return 422;

		if ( $code === 'invalid_recipient' )
			return 400;

		return 500;
	}

	/**
	 * Format a saved datetime value.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function format_saved_datetime( $value ) {
		if ( is_numeric( $value ) )
			$timestamp = (int) $value;
		else
			$timestamp = strtotime( (string) $value );

		if ( ! $timestamp )
			return sanitize_text_field( (string) $value );

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
