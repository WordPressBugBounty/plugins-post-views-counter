<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Emails_Period class.
 *
 * @class Post_Views_Counter_Emails_Period
 */
class Post_Views_Counter_Emails_Period {

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
	 * Get a summary period.
	 *
	 * @param string $summary_type
	 * @param mixed $now
	 * @return array
	 */
	public function get_period( $summary_type = 'weekly', $now = null ) {
		$summary_type = self::normalize_summary_type_key( $summary_type );
		$period = $this->build_weekly_period( $now );

		if ( $summary_type === 'weekly' )
			return apply_filters( 'pvc_email_summary_period', $period, 'weekly', $now, $this );

		$filtered_period = apply_filters( 'pvc_email_summary_period', $period, $summary_type, $now, $this );

		return is_array( $filtered_period ) ? array_merge( $period, $filtered_period ) : $period;
	}

	/**
	 * Get the previous complete weekly period.
	 *
	 * @param mixed $now
	 * @return array
	 */
	public function get_weekly_period( $now = null ) {
		$period = $this->build_weekly_period( $now );

		return apply_filters( 'pvc_email_summary_period', $period, 'weekly', $now, $this );
	}

	/**
	 * Build the previous complete weekly period.
	 *
	 * @param mixed $now
	 * @return array
	 */
	private function build_weekly_period( $now = null ) {
		$time_basis = $this->get_time_basis();
		$timezone = $this->get_timezone();
		$reference = $this->normalize_datetime( $now, $timezone )->setTime( 12, 0, 0 );

		// Free MVP intentionally uses previous complete ISO Monday-Sunday weeks, not start_of_week.
		$current_week_start = $reference->modify( 'monday this week' )->setTime( 0, 0, 0 );
		$period_start = $current_week_start->modify( '-7 days' );
		$period_end = $period_start->modify( '+6 days' );
		$comparison_start = $period_start->modify( '-7 days' );
		$comparison_end = $period_start->modify( '-1 day' );

		$period = [
			'cadence'				=> 'weekly',
			'time_basis'			=> $time_basis,
			'timezone'				=> $timezone->getName(),
			'start_date'			=> $period_start->format( 'Y-m-d' ),
			'end_date'				=> $period_end->format( 'Y-m-d' ),
			'start_period'			=> $period_start->format( 'Ymd' ),
			'end_period'			=> $period_end->format( 'Ymd' ),
			'label'					=> $this->build_period_label( $period_start, $period_end, $timezone ),
			'comparison_start_date'	=> $comparison_start->format( 'Y-m-d' ),
			'comparison_end_date'	=> $comparison_end->format( 'Y-m-d' ),
			'comparison_start_period' => $comparison_start->format( 'Ymd' ),
			'comparison_end_period'	=> $comparison_end->format( 'Ymd' ),
			'week_period'			=> $period_start->format( 'oW' ),
			'comparison_week_period' => $comparison_start->format( 'oW' )
		];

		return $period;
	}

	/**
	 * Get the supported summary cadence keys.
	 *
	 * @return array
	 */
	public static function get_supported_summary_types() {
		$supported = apply_filters( 'pvc_email_summary_supported_types', [ 'weekly' ] );

		if ( ! is_array( $supported ) )
			$supported = [ 'weekly' ];

		$normalized = [];

		foreach ( $supported as $summary_type ) {
			$summary_type = sanitize_key( (string) $summary_type );

			if ( $summary_type !== '' && ! in_array( $summary_type, $normalized, true ) )
				$normalized[] = $summary_type;
		}

		if ( ! in_array( 'weekly', $normalized, true ) )
			array_unshift( $normalized, 'weekly' );

		return $normalized;
	}

	/**
	 * Normalize a summary cadence key.
	 *
	 * @param string $summary_type
	 * @return string
	 */
	public static function normalize_summary_type_key( $summary_type ) {
		$summary_type = sanitize_key( (string) $summary_type );

		if ( in_array( $summary_type, self::get_supported_summary_types(), true ) )
			return $summary_type;

		return 'weekly';
	}

	/**
	 * Get the current count time basis.
	 *
	 * @return string
	 */
	private function get_time_basis() {
		return $this->pvc->options['general']['count_time'] === 'gmt' ? 'gmt' : 'local';
	}

	/**
	 * Get the timezone used for period calculations.
	 *
	 * @return DateTimeZone
	 */
	private function get_timezone() {
		return $this->get_time_basis() === 'gmt' ? new DateTimeZone( 'UTC' ) : wp_timezone();
	}

	/**
	 * Normalize a date input into the selected timezone.
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

	/**
	 * Build a human readable period label.
	 *
	 * @param DateTimeImmutable $period_start
	 * @param DateTimeImmutable $period_end
	 * @param DateTimeZone $timezone
	 * @return string
	 */
	private function build_period_label( $period_start, $period_end, $timezone ) {
		$start_timestamp = $period_start->getTimestamp();
		$end_timestamp = $period_end->getTimestamp();

		if ( $period_start->format( 'Y-m-d' ) === $period_end->format( 'Y-m-d' ) )
			return wp_date( 'M j, Y', $start_timestamp, $timezone );

		if ( $period_start->format( 'Y' ) === $period_end->format( 'Y' ) )
			return wp_date( 'M j', $start_timestamp, $timezone ) . ' - ' . wp_date( 'M j, Y', $end_timestamp, $timezone );

		return wp_date( 'M j, Y', $start_timestamp, $timezone ) . ' - ' . wp_date( 'M j, Y', $end_timestamp, $timezone );
	}

}
