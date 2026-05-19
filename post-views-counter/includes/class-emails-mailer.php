<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Emails_Mailer class.
 *
 * @class Post_Views_Counter_Emails_Mailer
 */
class Post_Views_Counter_Emails_Mailer {

	/**
	 * @var Post_Views_Counter
	 */
	private $pvc;

	/**
	 * @var Post_Views_Counter_Emails
	 */
	private $emails;

	/**
	 * @var string
	 */
	private $mail_from_name = '';

	/**
	 * @var WP_Error|null
	 */
	private $mail_error = null;

	/**
	 * Class constructor.
	 *
	 * @param Post_Views_Counter_Emails|null $emails
	 * @return void
	 */
	public function __construct( $emails = null ) {
		$this->pvc = Post_Views_Counter();
		$this->emails = $emails instanceof Post_Views_Counter_Emails ? $emails : new Post_Views_Counter_Emails();
	}

	/**
	 * Render and send a summary email.
	 *
	 * @param array $args
	 * @return array
	 */
	public function send_summary( $args = [] ) {
		$args = $this->normalize_args( $args );
		$context = $this->build_send_context( $args );
		$pre_render_result = $this->maybe_skip_before_render( $context );

		if ( is_array( $pre_render_result ) )
			return $pre_render_result;

		$send_lock = $this->maybe_acquire_send_lock( $context, $this->get_default_result( $context['recipient'], [], [] ) );

		if ( $this->is_send_result( $send_lock ) )
			return $send_lock;

		try {
			$this->maybe_flush_pending_counts( $context );

			$rendered = $this->emails->render_summary(
				$context['summary_type'],
				$context['period'],
				[
					'settings' => $context['settings']
				]
			);

			return $this->deliver_rendered_summary( $rendered, $context );
		} finally {
			$this->release_send_lock( $send_lock );
		}
	}

	/**
	 * Render and send the weekly summary email.
	 *
	 * @param array $args
	 * @return array
	 */
	public function send_weekly_summary( $args = [] ) {
		$args = $this->normalize_args( $args );
		$args['summary_type'] = 'weekly';

		return $this->send_summary( $args );
	}

	/**
	 * Send a rendered summary email.
	 *
	 * @param array $rendered
	 * @param array $args
	 * @return array
	 */
	public function send_rendered_summary( $rendered, $args = [] ) {
		$args = $this->normalize_args( $args );
		$rendered = is_array( $rendered ) ? $rendered : [];
		$data = isset( $rendered['data'] ) && is_array( $rendered['data'] ) ? $rendered['data'] : [];
		$context = $this->build_send_context( $args, $data );
		$result = $this->get_default_result( $context['recipient'], $data, $rendered );
		$pre_render_result = $this->maybe_skip_before_render( $context, $result );

		if ( is_array( $pre_render_result ) )
			return $pre_render_result;

		$send_lock = $this->maybe_acquire_send_lock( $context, $result );

		if ( $this->is_send_result( $send_lock ) )
			return $send_lock;

		try {
			return $this->deliver_rendered_summary( $rendered, $context );
		} finally {
			$this->release_send_lock( $send_lock );
		}
	}

	/**
	 * Deliver a rendered summary after preflight gates have already passed.
	 *
	 * @param array $rendered
	 * @param array $context
	 * @return array
	 */
	private function deliver_rendered_summary( $rendered, $context ) {
		$rendered = is_array( $rendered ) ? $rendered : [];
		$data = isset( $rendered['data'] ) && is_array( $rendered['data'] ) ? $rendered['data'] : [];
		$result = $this->get_default_result( $context['recipient'], $data, $rendered );

		if ( empty( $data['overview']['should_send'] ) )
			return $this->finalize_skip( $result, $this->get_skip_reason( $data ), $context['settings'], $context['period'], $context['source'], $context['is_test'], $context['summary_type'] );

		$should_send = (bool) apply_filters( 'pvc_email_summary_should_send', true, $data, $context['settings'], $context['summary_type'] );

		if ( ! $should_send )
			return $this->finalize_skip( $result, 'filtered', $context['settings'], $context['period'], $context['source'], $context['is_test'], $context['summary_type'] );

		$subject = $this->prepare_subject( isset( $rendered['subject'] ) ? $rendered['subject'] : '' );
		$html = isset( $rendered['html'] ) ? (string) $rendered['html'] : '';
		$header_sets = $this->build_mail_headers( $context['settings'], $context['period'], $context['summary_type'], $context['recipient'] );
		$result['headers'] = $header_sets['reported'];
		$this->mail_from_name = $this->get_from_name( $data, $context['summary_type'] );
		$this->mail_error = null;

		add_filter( 'wp_mail_content_type', [ $this, 'set_html_content_type' ] );
		add_filter( 'wp_mail_from_name', [ $this, 'set_from_name' ] );
		add_action( 'wp_mail_failed', [ $this, 'capture_mail_error' ] );

		try {
			$mail_result = wp_mail( $context['recipient'], $subject, $html, $header_sets['mail'] );
		} finally {
			remove_action( 'wp_mail_failed', [ $this, 'capture_mail_error' ] );
			remove_filter( 'wp_mail_from_name', [ $this, 'set_from_name' ] );
			remove_filter( 'wp_mail_content_type', [ $this, 'set_html_content_type' ] );
			$this->mail_from_name = '';
		}

		if ( ! $mail_result ) {
			$error = $this->mail_error instanceof WP_Error ? $this->mail_error : new WP_Error(
				'pvc_email_summary_wp_mail_failed',
				__( 'WordPress could not send the content views summary email.', 'post-views-counter' )
			);

			return $this->finalize_failure( $result, 'wp_mail_failed', $error, $context['settings'], $context['period'], $context['source'], $context['is_test'], $context['summary_type'] );
		}

		$result['sent'] = true;
		$result['reason'] = 'sent';
		$result['error'] = '';
		$this->store_status( $context['settings'], $context['period'], $context['recipient'], $context['source'], $context['is_test'], true, null, $context['summary_type'] );

		do_action( 'pvc_email_summary_sent', $data, $context['recipient'], $result['headers'], $mail_result, $context['summary_type'] );

		return $this->finalize_delivery( $result, $context['settings'], $context['period'], $context['source'], $context['is_test'], $context['summary_type'] );
	}

	/**
	 * Build normalized send context.
	 *
	 * @param array $args
	 * @param array $data
	 * @return array
	 */
	private function build_send_context( $args, $data = [] ) {
		$summary_type = ! empty( $args['summary_type'] ) ? sanitize_key( $args['summary_type'] ) : ( ! empty( $data['type'] ) ? sanitize_key( $data['type'] ) : 'weekly' );
		$settings = $this->get_settings( isset( $args['settings'] ) ? $args['settings'] : [] );
		$summary_type = Post_Views_Counter_Emails_Period::normalize_summary_type_key( $summary_type );
		$period = $this->resolve_period( $summary_type, isset( $args['period'] ) ? $args['period'] : null, $data );
		$source = ! empty( $args['source'] ) ? sanitize_key( $args['source'] ) : 'manual';
		$is_test = ! empty( $args['is_test'] );

		if ( $source === '' )
			$source = 'manual';

		return [
			'summary_type' => $summary_type,
			'settings' => $settings,
			'period' => $period,
			'source' => $source,
			'is_test' => $is_test,
			'recipient' => $this->resolve_recipient( $args, $settings, $period, $summary_type )
		];
	}

	/**
	 * Apply the cheap gates that do not require rendered summary content.
	 *
	 * @param array $context
	 * @param array|null $result
	 * @return array|null
	 */
	private function maybe_skip_before_render( $context, $result = null ) {
		$result = is_array( $result ) ? $result : $this->get_default_result( $context['recipient'], [], [] );

		if ( ! $context['is_test'] && empty( $context['settings']['enabled'] ) )
			return $this->finalize_skip( $result, 'disabled', $context['settings'], $context['period'], $context['source'], $context['is_test'], $context['summary_type'] );

		if ( ! is_email( $context['recipient'] ) ) {
			$error = new WP_Error(
				'pvc_email_summary_invalid_recipient',
				__( 'The content views summary recipient is invalid.', 'post-views-counter' )
			);

			return $this->finalize_failure( $result, 'invalid_recipient', $error, $context['settings'], $context['period'], $context['source'], $context['is_test'], $context['summary_type'] );
		}

		if ( ! $context['is_test'] && $this->is_duplicate_period( $context['settings'], $context['period'], $context['summary_type'] ) )
			return $this->finalize_skip( $result, 'duplicate', $context['settings'], $context['period'], $context['source'], $context['is_test'], $context['summary_type'] );

		return null;
	}

	/**
	 * Acquire a duplicate-send lock for non-test summary delivery.
	 *
	 * @param array $context
	 * @param array $result
	 * @return array|string
	 */
	private function maybe_acquire_send_lock( $context, $result ) {
		if ( ! $this->should_use_send_lock( $context ) )
			return '';

		$lock = $this->get_send_lock( $context );

		if ( $this->add_send_lock( $lock ) )
			return $lock;

		$current_lock = get_option( $lock['key'], [] );
		$current_expires_at = $this->get_send_lock_expires_at( $current_lock );

		if ( $current_expires_at <= time() ) {
			delete_option( $lock['key'] );

			if ( $this->add_send_lock( $lock ) )
				return $lock;
		}

		return $this->finalize_locked_skip( $result, $context['settings'], $context['period'], $context['source'], $context['is_test'], $context['summary_type'] );
	}

	/**
	 * Release a held summary-send lock.
	 *
	 * @param array|string $lock
	 * @return void
	 */
	private function release_send_lock( $lock ) {
		if ( ! is_array( $lock ) || empty( $lock['key'] ) || empty( $lock['token'] ) )
			return;

		$current_lock = get_option( $lock['key'], [] );

		if ( ! is_array( $current_lock ) || empty( $current_lock['token'] ) || $current_lock['token'] !== $lock['token'] )
			return;

		delete_option( $lock['key'] );
	}

	/**
	 * Flush pending object-cache counts before a cron summary render.
	 *
	 * Free flushes its base PVC counts here. Addons can hook into the action to
	 * flush addon-owned cache groups before summary query generation.
	 *
	 * @param array $context
	 * @return void
	 */
	private function maybe_flush_pending_counts( $context ) {
		if ( ! $this->should_flush_pending_counts( $context ) )
			return;

		$counter = isset( $this->pvc->counter ) ? $this->pvc->counter : null;
		$should_flush_base_counts = (bool) apply_filters( 'pvc_email_summary_should_flush_pending_counts', true, $context, $this );

		if ( $should_flush_base_counts && $counter && method_exists( $counter, 'using_object_cache' ) && method_exists( $counter, 'flush_cache_to_db' ) && $counter->using_object_cache() )
			$counter->flush_cache_to_db();

		do_action( 'pvc_email_summary_before_query', $context, $this );
	}

	/**
	 * Determine whether the current send should use the duplicate-send lock.
	 *
	 * @param array $context
	 * @return bool
	 */
	private function should_use_send_lock( $context ) {
		return empty( $context['is_test'] );
	}

	/**
	 * Determine whether the current send should flush pending cached counts.
	 *
	 * @param array $context
	 * @return bool
	 */
	private function should_flush_pending_counts( $context ) {
		return empty( $context['is_test'] ) && ! empty( $context['source'] ) && sanitize_key( $context['source'] ) === 'cron';
	}

	/**
	 * Build a lock payload for the current summary period.
	 *
	 * @param array $context
	 * @return array
	 */
	private function get_send_lock( $context ) {
		$ttl = max( 1, absint( apply_filters( 'pvc_email_summary_lock_ttl', 15 * MINUTE_IN_SECONDS, $context, $this ) ) );
		$blog_id = get_current_blog_id();
		$summary_type = Post_Views_Counter_Emails_Period::normalize_summary_type_key( $context['summary_type'] );
		$start_period = isset( $context['period']['start_period'] ) ? (string) $context['period']['start_period'] : '';
		$end_period = isset( $context['period']['end_period'] ) ? (string) $context['period']['end_period'] : '';
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'pvc-email-lock-', true );

		return [
			'key' => 'pvc_email_summary_lock_' . md5( implode( '|', [ $blog_id, $summary_type, $start_period, $end_period ] ) ),
			'token' => $token,
			'expires_at' => time() + $ttl,
			'value' => [
				'token' => $token,
				'expires_at' => time() + $ttl,
			]
		];
	}

	/**
	 * Persist a lock payload atomically.
	 *
	 * @param array $lock
	 * @return bool
	 */
	private function add_send_lock( $lock ) {
		return ! empty( $lock['key'] ) && isset( $lock['value'] ) ? add_option( $lock['key'], $lock['value'], '', false ) : false;
	}

	/**
	 * Normalize the expiration value from a stored lock payload.
	 *
	 * @param mixed $lock
	 * @return int
	 */
	private function get_send_lock_expires_at( $lock ) {
		if ( is_array( $lock ) && isset( $lock['expires_at'] ) )
			return absint( $lock['expires_at'] );

		return absint( $lock );
	}

	/**
	 * Scope the send call to HTML content.
	 *
	 * @return string
	 */
	public function set_html_content_type() {
		return 'text/html';
	}

	/**
	 * Set the from name during summary delivery.
	 *
	 * @param string $from_name
	 * @return string
	 */
	public function set_from_name( $from_name ) {
		return $this->mail_from_name !== '' ? $this->mail_from_name : $from_name;
	}

	/**
	 * Capture the most recent wp_mail failure.
	 *
	 * @param WP_Error $error
	 * @return void
	 */
	public function capture_mail_error( $error ) {
		if ( $error instanceof WP_Error )
			$this->mail_error = $error;
	}

	/**
	 * Normalize mailer arguments.
	 *
	 * @param array $args
	 * @return array
	 */
	private function normalize_args( $args ) {
		return is_array( $args ) ? $args : [];
	}

	/**
	 * Get normalized email settings.
	 *
	 * @param array $overrides
	 * @return array
	 */
	private function get_settings( $overrides = [] ) {
		$defaults = $this->pvc->get_default_emails_settings();
		$stored = isset( $this->pvc->options['emails'] ) && is_array( $this->pvc->options['emails'] ) ? $this->pvc->options['emails'] : [];
		$overrides = is_array( $overrides ) ? $overrides : [];
		$settings = array_merge( $defaults, $stored, $overrides );

		$settings['latest_status'] = $this->merge_latest_status(
			isset( $defaults['latest_status'] ) ? $defaults['latest_status'] : [],
			isset( $stored['latest_status'] ) ? $stored['latest_status'] : [],
			isset( $overrides['latest_status'] ) ? $overrides['latest_status'] : []
		);

		return $settings;
	}

	/**
	 * Resolve the summary period.
	 *
	 * @param array|null $period
	 * @param array $data
	 * @return array
	 */
	private function resolve_period( $summary_type, $period = null, $data = [] ) {
		$default_period = ( new Post_Views_Counter_Emails_Period() )->get_period( $summary_type );

		if ( is_array( $period ) )
			return array_merge( $default_period, $period );

		if ( is_array( $data ) && ! empty( $data['period'] ) && is_array( $data['period'] ) )
			return array_merge( $default_period, $data['period'] );

		return $default_period;
	}

	/**
	 * Resolve the primary recipient.
	 *
	 * @param array $args
	 * @param array $settings
	 * @param array $period
	 * @param string $summary_type
	 * @return string
	 */
	private function resolve_recipient( $args, $settings, $period, $summary_type ) {
		$recipient = isset( $args['recipient'] ) ? $args['recipient'] : ( isset( $settings['recipient'] ) ? $settings['recipient'] : '' );
		$recipient = apply_filters( 'pvc_email_summary_recipient', (string) $recipient, $period, $summary_type );

		return sanitize_email( (string) $recipient );
	}

	/**
	 * Build the wp_mail header list for summary delivery.
	 *
	 * BCC recipients are sent in the actual mail headers but omitted from
	 * visible/reporting headers to avoid exposing private recipients in admin
	 * output or hook consumers.
	 *
	 * @param array $settings
	 * @param array $period
	 * @param string $summary_type
	 * @param string $primary_recipient
	 * @return array
	 */
	private function build_mail_headers( $settings, $period, $summary_type, $primary_recipient ) {
		if ( ! $this->can_use_additional_recipients( $settings ) ) {
			return [
				'mail' => [],
				'reported' => []
			];
		}

		$cc_recipients = $this->resolve_cc_recipients( $settings, $period, $summary_type, $primary_recipient );
		$bcc_recipients = $this->resolve_bcc_recipients( $settings, $period, $summary_type, array_merge( [ $primary_recipient ], $cc_recipients ) );
		$mail_headers = [];
		$reported_headers = [];

		if ( ! empty( $cc_recipients ) ) {
			$cc_header = 'Cc: ' . implode( ', ', $cc_recipients );
			$mail_headers[] = $cc_header;
			$reported_headers[] = $cc_header;
		}

		if ( ! empty( $bcc_recipients ) )
			$mail_headers[] = 'Bcc: ' . implode( ', ', $bcc_recipients );

		return [
			'mail' => $mail_headers,
			'reported' => $reported_headers
		];
	}

	/**
	 * Determine whether additional recipients are enabled for delivery.
	 *
	 * Free keeps the stored values intact, but addons must opt into delivery.
	 *
	 * @param array $settings
	 * @return bool
	 */
	private function can_use_additional_recipients( $settings ) {
		if ( ! apply_filters( 'pvc_email_summary_supports_additional_recipients', false, $settings, $this ) )
			return false;

		$value = isset( $settings['additional_recipients'] ) ? $settings['additional_recipients'] : false;

		return $value === true || $value === 'true' || $value === 1 || $value === '1';
	}

	/**
	 * Resolve CC recipients for summary delivery.
	 *
	 * @param array $settings
	 * @param array $period
	 * @param string $summary_type
	 * @param string $primary_recipient
	 * @return array
	 */
	private function resolve_cc_recipients( $settings, $period, $summary_type, $primary_recipient ) {
		$cc_recipients = isset( $settings['cc_recipients'] ) ? $settings['cc_recipients'] : [];
		$cc_recipients = $this->normalize_header_recipients( $cc_recipients );

		/**
		 * Filters the CC recipients for an email summary.
		 *
		 * @param array $cc_recipients Sanitized CC recipients.
		 * @param array $period Summary period data.
		 * @param string $summary_type Summary cadence/type.
		 */
		$cc_recipients = apply_filters( 'pvc_email_summary_cc_recipients', $cc_recipients, $period, $summary_type );

		return $this->remove_excluded_header_recipients(
			$this->normalize_header_recipients( $cc_recipients ),
			array_filter( [ $primary_recipient ] )
		);
	}

	/**
	 * Resolve BCC recipients for summary delivery.
	 *
	 * @param array $settings
	 * @param array $period
	 * @param string $summary_type
	 * @param array $excluded
	 * @return array
	 */
	private function resolve_bcc_recipients( $settings, $period, $summary_type, $excluded = [] ) {
		$bcc_recipients = isset( $settings['bcc_recipients'] ) ? $settings['bcc_recipients'] : [];
		$bcc_recipients = $this->normalize_header_recipients( $bcc_recipients );

		/**
		 * Filters the BCC recipients for an email summary.
		 *
		 * @param array $bcc_recipients Sanitized BCC recipients.
		 * @param array $period Summary period data.
		 * @param string $summary_type Summary cadence/type.
		 */
		$bcc_recipients = apply_filters( 'pvc_email_summary_bcc_recipients', $bcc_recipients, $period, $summary_type );

		return $this->remove_excluded_header_recipients(
			$this->normalize_header_recipients( $bcc_recipients ),
			$excluded
		);
	}

	/**
	 * Normalize a recipient list for use in email headers.
	 *
	 * @param mixed $recipients
	 * @return array
	 */
	private function normalize_header_recipients( $recipients ) {
		$normalized = [];

		foreach ( $this->split_header_recipients( $recipients ) as $recipient ) {
			$recipient = sanitize_email( $recipient );

			if ( $recipient === '' || ! is_email( $recipient ) || in_array( $recipient, $normalized, true ) )
				continue;

			$normalized[] = $recipient;
		}

		return $normalized;
	}

	/**
	 * Split header recipients from arrays or delimited strings.
	 *
	 * @param mixed $recipients
	 * @return array
	 */
	private function split_header_recipients( $recipients ) {
		if ( is_array( $recipients ) ) {
			$parts = [];

			foreach ( $recipients as $nested_recipient ) {
				$parts = array_merge( $parts, $this->split_header_recipients( $nested_recipient ) );
			}

			return $parts;
		}

		$recipients = preg_replace( '/[\r\n]+/', "\n", wp_unslash( (string) $recipients ) );
		$parts = preg_split( '/[;,\n]+/', $recipients );

		if ( ! is_array( $parts ) )
			return [];

		$parts = array_map(
			static function( $recipient ) {
				$recipient = preg_replace( '/[\r\n]+/', ' ', (string) $recipient );
				$recipient = trim( preg_replace( '/\s+/', ' ', $recipient ) );

				return $recipient;
			},
			$parts
		);

		return array_values( array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Remove recipients already used elsewhere in the email envelope.
	 *
	 * @param array $recipients
	 * @param array $excluded
	 * @return array
	 */
	private function remove_excluded_header_recipients( $recipients, $excluded = [] ) {
		$recipients = is_array( $recipients ) ? $recipients : [];
		$excluded = is_array( $excluded ) ? array_values( array_unique( array_filter( $excluded ) ) ) : [];
		$filtered = [];

		foreach ( $recipients as $recipient ) {
			if ( in_array( $recipient, $excluded, true ) || in_array( $recipient, $filtered, true ) )
				continue;

			$filtered[] = $recipient;
		}

		return $filtered;
	}

	/**
	 * Determine whether the period has already been sent successfully.
	 *
	 * @param array $settings
	 * @param array $period
	 * @param string $summary_type
	 * @return bool
	 */
	private function is_duplicate_period( $settings, $period, $summary_type ) {
		$summary_type = Post_Views_Counter_Emails_Period::normalize_summary_type_key( $summary_type );
		$is_duplicate = $summary_type === 'weekly'
			&& ! empty( $settings['last_sent_at'] )
			&& ! empty( $settings['last_period_start'] )
			&& ! empty( $settings['last_period_end'] )
			&& (string) $settings['last_period_start'] === (string) $period['start_period']
			&& (string) $settings['last_period_end'] === (string) $period['end_period'];

		return (bool) apply_filters( 'pvc_email_summary_is_duplicate_period', $is_duplicate, $summary_type, $period, $settings, $this );
	}

	/**
	 * Build the default result structure.
	 *
	 * @param string $recipient
	 * @param array $data
	 * @param array $rendered
	 * @return array
	 */
	private function get_default_result( $recipient, $data, $rendered ) {
		return [
			'sent' => false,
			'skipped' => false,
			'reason' => '',
			'recipient' => $recipient,
			'headers' => [],
			'data' => $data,
			'rendered' => $rendered,
			'error' => ''
		];
	}

	/**
	 * Determine whether a value is a finalized send result.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	private function is_send_result( $value ) {
		return is_array( $value ) && array_key_exists( 'sent', $value ) && array_key_exists( 'skipped', $value );
	}

	/**
	 * Resolve the skip reason for rendered data that should not send.
	 *
	 * @param array $data
	 * @return string
	 */
	private function get_skip_reason( $data ) {
		$status_reason = ! empty( $data['status']['reason'] ) ? sanitize_key( $data['status']['reason'] ) : '';

		return $status_reason === 'no_post_types' ? 'no_post_types' : 'below_threshold';
	}

	/**
	 * Finalize a skipped send.
	 *
	 * @param array $result
	 * @param string $reason
	 * @param array $settings
	 * @param array $period
	 * @param string $source
	 * @param bool $is_test
	 * @return array
	 */
	private function finalize_skip( $result, $reason, $settings, $period, $source, $is_test, $summary_type ) {
		$result['skipped'] = true;
		$result['reason'] = $reason;
		$result['error'] = '';
		$this->store_status( $settings, $period, $result['recipient'], $source, $is_test, false, null, $summary_type );

		return $this->finalize_delivery( $result, $settings, $period, $source, $is_test, $summary_type );
	}

	/**
	 * Finalize a locked send without updating the persisted delivery status.
	 *
	 * @param array $result
	 * @param array $settings
	 * @param array $period
	 * @param string $source
	 * @param bool $is_test
	 * @param string $summary_type
	 * @return array
	 */
	private function finalize_locked_skip( $result, $settings, $period, $source, $is_test, $summary_type ) {
		$result['skipped'] = true;
		$result['reason'] = 'locked';
		$result['error'] = '';

		return $this->finalize_delivery( $result, $settings, $period, $source, $is_test, $summary_type );
	}

	/**
	 * Finalize a failed send.
	 *
	 * @param array $result
	 * @param string $reason
	 * @param WP_Error $error
	 * @param array $settings
	 * @param array $period
	 * @param string $source
	 * @param bool $is_test
	 * @param string $summary_type
	 * @return array
	 */
	private function finalize_failure( $result, $reason, $error, $settings, $period, $source, $is_test, $summary_type ) {
		$message = $this->get_error_message( $error );
		$result['reason'] = $reason;
		$result['error'] = $message;
		$this->store_status( $settings, $period, $result['recipient'], $source, $is_test, false, $message, $summary_type );

		do_action( 'pvc_email_summary_failed', $result['data'], $result['recipient'], $result['headers'], $error, $summary_type );

		return $this->finalize_delivery( $result, $settings, $period, $source, $is_test, $summary_type );
	}

	/**
	 * Finalize delivery for extension hooks after Free status storage completes.
	 *
	 * @param array $result
	 * @param array $settings
	 * @param array $period
	 * @param string $source
	 * @param bool $is_test
	 * @param string $summary_type
	 * @return array
	 */
	private function finalize_delivery( $result, $settings, $period, $source, $is_test, $summary_type ) {
		do_action( 'pvc_email_summary_delivery_finalized', $result, $settings, $period, $source, $is_test, $summary_type, $this );

		return $result;
	}

	/**
	 * Persist latest delivery metadata.
	 *
	 * @param array $settings
	 * @param array $period
	 * @param string $recipient
	 * @param string $source
	 * @param bool $is_test
	 * @param bool $success
	 * @param string|null $error_message
	 * @return void
	 */
	private function store_status( $settings, $period, $recipient, $source, $is_test, $success, $error_message, $summary_type ) {
		if ( Post_Views_Counter_Emails_Period::normalize_summary_type_key( $summary_type ) !== 'weekly' )
			return;

		$defaults = $this->pvc->get_default_emails_settings();
		$stored = get_option( 'post_views_counter_settings_emails', [] );
		$stored = is_array( $stored ) ? $stored : [];
		$updated = array_merge( $defaults, $stored );
		$updated['latest_status'] = $this->merge_latest_status(
			isset( $defaults['latest_status'] ) ? $defaults['latest_status'] : [],
			isset( $stored['latest_status'] ) ? $stored['latest_status'] : []
		);
		$timestamp = time();
		$last_error = $error_message !== null ? sanitize_text_field( $error_message ) : null;

		$updated['latest_status']['last_attempt_at'] = $timestamp;
		$updated['latest_status']['last_error'] = $last_error;
		$updated['latest_status']['recipient'] = sanitize_email( (string) $recipient );
		$updated['latest_status']['source'] = sanitize_key( (string) $source );
		$updated['latest_status']['is_test'] = (bool) $is_test;
		$updated['latest_status']['period_start'] = isset( $period['start_period'] ) ? (string) $period['start_period'] : null;
		$updated['latest_status']['period_end'] = isset( $period['end_period'] ) ? (string) $period['end_period'] : null;

		if ( $success ) {
			$updated['latest_status']['last_success_at'] = $timestamp;
			$updated['last_error'] = null;

			if ( ! $is_test ) {
				$updated['last_sent_at'] = $timestamp;
				$updated['last_period_start'] = isset( $period['start_period'] ) ? (string) $period['start_period'] : null;
				$updated['last_period_end'] = isset( $period['end_period'] ) ? (string) $period['end_period'] : null;
			}
		} elseif ( $last_error !== null )
			$updated['last_error'] = $last_error;

		update_option( 'post_views_counter_settings_emails', $updated );
		$this->pvc->options['emails'] = $updated;
	}

	/**
	 * Merge nested latest status arrays.
	 *
	 * @param array ...$status_sets
	 * @return array
	 */
	private function merge_latest_status( ...$status_sets ) {
		$merged = [];

		foreach ( $status_sets as $status ) {
			if ( is_array( $status ) )
				$merged = array_merge( $merged, $status );
		}

		return $merged;
	}

	/**
	 * Get the from name used during delivery.
	 *
	 * @param array $data
	 * @param string $summary_type
	 * @return string
	 */
	private function get_from_name( $data, $summary_type ) {
		$site_name = ! empty( $data['site']['name'] ) ? wp_specialchars_decode( $data['site']['name'], ENT_QUOTES ) : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$from_name = sprintf( __( 'Post Views Counter on %s', 'post-views-counter' ), $site_name );
		$from_name = apply_filters( 'pvc_email_summary_from_name', $from_name, $data, $summary_type );

		return sanitize_text_field( preg_replace( '/[\r\n]+/', ' ', (string) $from_name ) );
	}

	/**
	 * Normalize the email subject.
	 *
	 * @param string $subject
	 * @return string
	 */
	private function prepare_subject( $subject ) {
		$subject = preg_replace( '/[\r\n]+/', ' ', wp_strip_all_tags( (string) $subject ) );

		return trim( $subject );
	}

	/**
	 * Get a human-readable error message.
	 *
	 * @param WP_Error|string $error
	 * @return string
	 */
	private function get_error_message( $error ) {
		if ( $error instanceof WP_Error ) {
			$message = $error->get_error_message();
			$code = $error->get_error_code();

			if ( $message !== '' && $code )
				return sanitize_text_field( $code . ': ' . $message );

			if ( $message !== '' )
				return sanitize_text_field( $message );
		}

		return sanitize_text_field( (string) $error );
	}
}
