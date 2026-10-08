<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Entrances_Eligibility class.
 *
 * Decides whether per-object Entrances (stored Visits: visit windows that
 * started on the object) may be compared at all. Traffic Signals and
 * The extension insights use the same eligibility rules.
 *
 * @internal Not a public API. Entrances values never leave the server.
 *
 * @class Post_Views_Counter_Entrances_Eligibility
 */
class Post_Views_Counter_Entrances_Eligibility {

	/**
	 * The larger compared Entrances value must reach this floor.
	 *
	 * @var int
	 */
	const MIN_ENTRANCES = 10;

	/**
	 * Get the Visits availability of an inclusive day range in the counting clock.
	 *
	 * The range runs from 00:00 of its first day to the earlier of 00:00 after
	 * its last day and now, so a running range ends now.
	 *
	 * @param string|int        $from First day, Ymd
	 * @param string|int        $to   Last day, Ymd
	 * @param DateTimeInterface $now  Current time
	 * @return array [ 'derived_available' => bool, 'derived_reason' => string ]
	 */
	public static function get_day_range_availability( $from, $to, $now ) {
		$pvc = Post_Views_Counter();

		if ( ! isset( $pvc->visits ) || ! is_object( $pvc->visits ) || ! method_exists( $pvc->visits, 'get_visits_availability' ) )
			return [ 'derived_available' => false, 'derived_reason' => 'unsupported' ];

		$timezone = Post_Views_Counter_Visits_Query::get_timezone();
		$start = DateTimeImmutable::createFromFormat( '!Ymd', (string) $from, $timezone );
		$last = DateTimeImmutable::createFromFormat( '!Ymd', (string) $to, $timezone );

		if ( ! $start || ! $last || ! ( $now instanceof DateTimeInterface ) || $start->format( 'Ymd' ) !== (string) $from || $last->format( 'Ymd' ) !== (string) $to )
			return [ 'derived_available' => false, 'derived_reason' => 'invalid_range' ];

		$end = min( $last->modify( '+1 day' )->getTimestamp(), $now->getTimestamp() );

		if ( $end <= $start->getTimestamp() )
			return [ 'derived_available' => false, 'derived_reason' => 'invalid_range' ];

		$availability = $pvc->visits->get_visits_availability( [ 'start' => $start->getTimestamp(), 'end' => $end ] );

		return [
			'derived_available'	=> is_array( $availability ) && ! empty( $availability['derived_available'] ),
			'derived_reason'	=> is_array( $availability ) && isset( $availability['derived_reason'] ) ? (string) $availability['derived_reason'] : 'unknown'
		];
	}

	/**
	 * Check compared ranges against the Entrances eligibility rules.
	 *
	 * Each range carries its Views, its Entrances and whether it is derived.
	 * A null value means the read failed, the range was not read, its daily rows
	 * are incomplete or its Entrances failed the parity check.
	 *
	 * @param array    $ranges    [ [ 'views' => int|null, 'entrances' => int|null, 'derived_available' => bool ],... ]
	 * @param int|null $min_views Views floor every range must reach, or null
	 * @return array [ 'eligible' => bool, 'reason' => string ]
	 */
	public static function check( $ranges, $min_views = null ) {
		if ( ! is_array( $ranges ) || empty( $ranges ) )
			return [ 'eligible' => false, 'reason' => 'no_ranges' ];

		$largest = 0;

		foreach ( $ranges as $range ) {
			if ( ! is_array( $range ) || empty( $range['derived_available'] ) )
				return [ 'eligible' => false, 'reason' => 'not_derived' ];

			if ( ! isset( $range['views'] ) || ! is_int( $range['views'] ) )
				return [ 'eligible' => false, 'reason' => 'views_unavailable' ];

			if ( ! isset( $range['entrances'] ) || ! is_int( $range['entrances'] ) )
				return [ 'eligible' => false, 'reason' => 'entrances_unavailable' ];

			if ( $range['entrances'] > $range['views'] )
				return [ 'eligible' => false, 'reason' => 'entrances_above_views' ];

			if ( $min_views !== null && $range['views'] < (int) $min_views )
				return [ 'eligible' => false, 'reason' => 'below_views_floor' ];

			$largest = max( $largest, $range['entrances'] );
		}

		if ( $largest < self::MIN_ENTRANCES )
			return [ 'eligible' => false, 'reason' => 'below_entrances_floor' ];

		return [ 'eligible' => true, 'reason' => 'eligible' ];
	}
}
