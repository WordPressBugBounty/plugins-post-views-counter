<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Emails_Scheduler class.
 *
 * @class Post_Views_Counter_Emails_Scheduler
 */
class Post_Views_Counter_Emails_Scheduler {

	/**
	 * @var Post_Views_Counter
	 */
	private $pvc;

	/**
	 * @var string
	 */
	private $hook = 'pvc_weekly_content_summary_send';

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->pvc = Post_Views_Counter();

		add_action( 'pvc_weekly_content_summary_send', [ $this, 'send_weekly_summary' ] );
		add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
		add_action( 'pvc_configuration_updated', [ $this, 'handle_configuration_updated' ], 10, 2 );
	}

	/**
	 * Register the weekly email summary schedule.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		$display = 'Post Views Counter weekly email summary interval';

		if ( did_action( 'init' ) )
			$display = __( 'Post Views Counter weekly email summary interval', 'post-views-counter' );

		$schedules['post_views_counter_weekly'] = [
			'interval' => WEEK_IN_SECONDS,
			'display' => $display
		];

		return $schedules;
	}

	/**
	 * Schedule the weekly summary event when enabled, otherwise clear it.
	 *
	 * @param array|null $settings
	 * @return int|false
	 */
	public function maybe_schedule( $settings = null ) {
		if ( $this->is_enabled( $settings ) )
			return $this->schedule( $settings );

		$this->clear();

		return false;
	}

	/**
	 * Schedule the weekly summary event without creating duplicates.
	 *
	 * @param array|null $settings
	 * @return int|false
	 */
	public function schedule( $settings = null ) {
		$settings = $this->get_settings( $settings );

		if ( ! $this->is_enabled( $settings ) )
			return false;

		$scheduled = $this->get_next_scheduled_timestamp();

		if ( $scheduled )
			return $scheduled;

		$timestamp = $this->get_next_run_timestamp( $settings );
		$recurrence = $this->get_recurrence( $settings );

		if ( ! $timestamp || ! $recurrence )
			return false;

		if ( ! isset( wp_get_schedules()[$recurrence] ) )
			$recurrence = 'post_views_counter_weekly';

		return wp_schedule_event( $timestamp, $recurrence, $this->hook ) ? $timestamp : false;
	}

	/**
	 * Clear and recreate the weekly summary schedule.
	 *
	 * @param array|null $settings
	 * @return int|false
	 */
	public function reschedule( $settings = null ) {
		$this->clear();

		return $this->maybe_schedule( $settings );
	}

	/**
	 * Clear all weekly summary events for the current site.
	 *
	 * @return int|false
	 */
	public function clear() {
		return wp_clear_scheduled_hook( $this->hook );
	}

	/**
	 * Get the next scheduled weekly summary timestamp.
	 *
	 * @return int|false
	 */
	public function get_next_scheduled_timestamp() {
		return wp_next_scheduled( $this->hook );
	}

	/**
	 * Get the next eligible Monday 09:00 site-local run timestamp.
	 *
	 * @param array|null $settings
	 * @param mixed $now
	 * @return int
	 */
	public function get_next_run_timestamp( $settings = null, $now = null ) {
		$settings = $this->get_settings( $settings );
		$timezone = wp_timezone();
		$reference = $this->normalize_datetime( $now, $timezone );
		$next_run = $reference->modify( 'monday this week' )->setTime( 9, 0, 0 );

		if ( $reference >= $next_run )
			$next_run = $next_run->modify( '+7 days' );

		$timestamp = $next_run->getTimestamp();

		return (int) apply_filters( 'pvc_email_summary_schedule_timestamp', $timestamp, $settings );
	}

	/**
	 * Determine whether the weekly summary schedule is enabled.
	 *
	 * @param array|null $settings
	 * @return bool
	 */
	public function is_enabled( $settings = null ) {
		$settings = $this->get_settings( $settings );
		$enabled = ! empty( $settings['enabled'] );

		return (bool) apply_filters( 'pvc_email_summary_schedule_enabled', $enabled, $settings );
	}

	/**
	 * Send the scheduled weekly summary.
	 *
	 * @return array
	 */
	public function send_weekly_summary() {
		$mailer = new Post_Views_Counter_Emails_Mailer();

		return $mailer->send_weekly_summary(
			[
				'source' => 'cron',
				'is_test' => false,
				'summary_type' => 'weekly'
			]
		);
	}

	/**
	 * Handle settings updates that affect scheduler state.
	 *
	 * @param string $context
	 * @param array $input
	 * @return void
	 */
	public function handle_configuration_updated( $context, $input ) {
		if ( $context !== 'settings' )
			return;

		$option_page = isset( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';

		if ( $option_page !== 'post_views_counter_settings_emails' )
			return;

		$this->reschedule( is_array( $input ) ? $input : null );
	}

	/**
	 * Get the configured recurrence key.
	 *
	 * @param array $settings
	 * @return string
	 */
	private function get_recurrence( $settings ) {
		$recurrence = apply_filters( 'pvc_email_summary_schedule_recurrence', 'post_views_counter_weekly', $settings );
		$recurrence = sanitize_key( (string) $recurrence );

		return $recurrence !== '' ? $recurrence : 'post_views_counter_weekly';
	}

	/**
	 * Get normalized email settings.
	 *
	 * @param array|null $settings
	 * @return array
	 */
	private function get_settings( $settings = null ) {
		$defaults = $this->pvc->get_default_emails_settings();
		$stored = get_option( 'post_views_counter_settings_emails', [] );
		$stored = is_array( $stored ) ? $stored : [];
		$settings = is_array( $settings ) ? $settings : [];

		return array_merge( $defaults, $stored, $settings );
	}

	/**
	 * Normalize date input in the site timezone.
	 *
	 * @param mixed $now
	 * @param DateTimeZone $timezone
	 * @return DateTimeImmutable
	 */
	private function normalize_datetime( $now, $timezone ) {
		if ( $now instanceof DateTimeInterface )
			return ( new DateTimeImmutable( '@' . $now->getTimestamp() ) )->setTimezone( $timezone );

		if ( is_numeric( $now ) )
			return ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $timezone );

		if ( is_string( $now ) && $now !== '' ) {
			try {
				return ( new DateTimeImmutable( $now, $timezone ) )->setTimezone( $timezone );
			} catch ( Exception $e ) {
				return new DateTimeImmutable( 'now', $timezone );
			}
		}

		return new DateTimeImmutable( 'now', $timezone );
	}
}