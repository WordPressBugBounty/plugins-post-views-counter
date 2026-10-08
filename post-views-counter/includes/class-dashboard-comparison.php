<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Dashboard_Comparison class.
 *
 * The period comparison of the dashboard chart widgets:
 * the ranges a chart's period is compared on, and the Views of those ranges
 * read in one Views-only statement that proves each range complete.
 *
 * - The chart's period is taken as drawn; whether it has started, is running
 *   or is complete is decided in the counting clock.
 * - A running period compares its completed days only: the first
 *   `d_prev = min( completed days, previous length )` days of both periods,
 *   or the previous period's own row when `d_prev` is its length; history
 *   periods use `d_hist`, the shortest compared history period.
 * - A complete period compares whole stored periods.
 * - A day range counts only when the daily rows of every month it touches
 *   (the ISO week of a week chart) add up to that period's own row; an
 *   unproven range has no value, never zero.
 * - The read never touches `visits` and runs behind Core's site data guard.
 *
 * @internal Shared by dashboard readers; not a public API.
 *
 * @since 1.7.16
 *
 * @class Post_Views_Counter_Dashboard_Comparison
 */
class Post_Views_Counter_Dashboard_Comparison {

	/** Object cache group of the comparison reads. */
	const CACHE_GROUP = 'pvc-dashboard-comparison';

	/** Most history periods one read accepts (a year of ISO weeks and one more). */
	const MAX_HISTORY = 54;

	/** Row type of each grain's stored period. */
	const TYPES = [ 'week' => 1, 'month' => 2, 'year' => 3 ];

	/**
	 * Get the ranges of a chart period, without reading anything.
	 *
	 * @param string                 $grain   week, month or year
	 * @param string                 $period  The period the chart drew: YYYYWW (ISO), YYYYMM or YYYY.
	 * @param DateTimeInterface|null $now     Current time; now by default.
	 * @param array                  $history Periods of the same grain compared with this one.
	 * @return array {
	 *     @type string     $grain
	 *     @type string     $period
	 *     @type string     $state          invalid, not_started, first_day, running or complete
	 *     @type int|null   $completed_days Completed days of a period that has started and is not complete.
	 *     @type int|null   $days           d_prev of a running period.
	 *     @type array|null $current        [ 'key', 'from', 'to' ] (range key, first and last Ymd day)
	 *     @type array|null $previous       [ 'period', 'key', 'from', 'to' ]
	 *     @type array      $history        [ 'days' => d_hist|null, 'current' => range|null, 'periods' => [ period => range ] ]
	 * }
	 */
	public static function get_ranges( $grain, $period, $now = null, $history = [] ) {
		$result = [
			'grain'				=> is_string( $grain ) ? $grain : '',
			'period'			=> is_scalar( $period ) ? (string) $period : '',
			'state'				=> 'invalid',
			'completed_days'	=> null,
			'days'				=> null,
			'current'			=> null,
			'previous'			=> null,
			'history'			=> [ 'days' => null, 'current' => null, 'periods' => [] ]
		];

		$current = self::get_period( $result['grain'], $result['period'] );

		if ( $current === null )
			return $result;

		$previous_period = self::shift( $result['grain'], $current['start'] );
		$previous = self::get_period( $result['grain'], $previous_period );

		// the history periods that parse, other than the period itself
		$periods = [];

		foreach ( array_slice( array_values( array_unique( array_filter( (array) $history, 'is_scalar' ) ) ), 0, self::MAX_HISTORY ) as $key ) {
			$parsed = self::get_period( $result['grain'], (string) $key );

			if ( $parsed !== null && (string) $key !== $result['period'] )
				$periods[(string) $key] = $parsed;
		}

		// the chart's bucket judged in the counting clock
		$timezone = Post_Views_Counter_Visits_Query::get_timezone();
		$now = $now instanceof DateTimeInterface ? $now->getTimestamp() : time();
		$now = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $timezone );
		$start = DateTimeImmutable::createFromFormat( '!Ymd', $current['start']->format( 'Ymd' ), $timezone );
		$next = $start->modify( '+' . $current['length'] . ' days' );

		if ( $now < $start ) {
			$result['state'] = 'not_started';

			return $result;
		}

		if ( $now >= $next ) {
			$result['state'] = 'complete';
			$result['current'] = self::own_range( $result['grain'], $result['period'], $current );
			$result['previous'] = [ 'period' => $previous_period ] + self::own_range( $result['grain'], $previous_period, $previous );
			$result['history']['current'] = $result['current'];

			foreach ( $periods as $key => $parsed )
				$result['history']['periods'][$key] = self::own_range( $result['grain'], $key, $parsed );

			return $result;
		}

		// whole days elapsed before today, in the counting clock
		$completed = (int) $start->diff( $now->setTime( 0, 0 ) )->days;
		$result['completed_days'] = $completed;

		if ( $completed < 1 ) {
			$result['state'] = 'first_day';

			return $result;
		}

		$days = min( $completed, $previous['length'] );

		$result['state'] = 'running';
		$result['days'] = $days;
		$result['current'] = self::day_range( $current['start'], $days );
		$result['previous'] = [ 'period' => $previous_period ] + ( $days >= $previous['length'] ? self::own_range( $result['grain'], $previous_period, $previous ) : self::day_range( $previous['start'], $days ) );

		if ( ! empty( $periods ) ) {
			$history_days = $completed;

			foreach ( $periods as $parsed )
				$history_days = min( $history_days, $parsed['length'] );

			$result['history']['days'] = $history_days;
			$result['history']['current'] = self::day_range( $current['start'], $history_days );

			foreach ( $periods as $key => $parsed )
				$result['history']['periods'][$key] = $history_days >= $parsed['length'] ? self::own_range( $result['grain'], $key, $parsed ) : self::day_range( $parsed['start'], $history_days );
		}

		return $result;
	}

	/**
	 * Compare a chart period with the previous one, and with history periods.
	 *
	 * One statement reads the Views of every range and the rows that prove
	 * them; it is cached per blog, Visits generation and full statement, so
	 * two scopes or two conditions never share a result.
	 *
	 * @param array $args {
	 *     @type string                 $grain      week, month or year
	 *     @type string                 $period     The period the chart drew.
	 *     @type string                 $scope      posts (the counted post types, post rows only) or all (every row).
	 *     @type array                  $post_types Post types of the posts scope.
	 *     @type string                 $condition  A prepared SQL condition on `pv` (and `p` in the posts
	 *                                              scope) that scopes the chart, e.g. its language; it
	 *                                              applies to every value and proof.
	 *     @type array                  $history    History periods of the same grain.
	 *     @type DateTimeInterface|null $now        Current time; now by default.
	 * }
	 * @return array The ranges of get_ranges(), each with `views` (int, or null when unproven or
	 *               unread), plus `available` (both sides of the comparison proven) and `reason`:
	 *               ok, invalid, not_started, first_day, invalid_scope, data_inaccessible,
	 *               read_failed or incomplete.
	 */
	public static function get( $args ) {
		global $wpdb;

		$args = is_array( $args ) ? $args : [];
		$result = self::get_ranges(
			isset( $args['grain'] ) ? $args['grain'] : '',
			isset( $args['period'] ) ? $args['period'] : '',
			isset( $args['now'] ) ? $args['now'] : null,
			isset( $args['history'] ) ? $args['history'] : []
		);
		$result['available'] = false;
		$result['reason'] = $result['state'];

		if ( ! in_array( $result['state'], [ 'running', 'complete' ], true ) )
			return $result;

		$result = self::with_views( $result, null );
		$posts = ! isset( $args['scope'] ) || $args['scope'] !== 'all';
		$post_types = $posts && isset( $args['post_types'] ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $args['post_types'] ) ) ) ) : [];
		$condition = isset( $args['condition'] ) && is_string( $args['condition'] ) ? trim( $args['condition'] ) : '';

		if ( $posts && empty( $post_types ) ) {
			$result['reason'] = 'invalid_scope';

			return $result;
		}

		// the shared data guard, before any cache lookup or read
		$pvc = Post_Views_Counter();

		if ( isset( $pvc->visits ) && is_object( $pvc->visits ) && method_exists( $pvc->visits, 'is_site_data_accessible' ) && ! $pvc->visits->is_site_data_accessible() ) {
			$result['reason'] = 'data_inaccessible';

			return $result;
		}

		$basis = $result['grain'] === 'week' ? 'week' : 'month';
		$builder = [ 'columns' => [], 'params' => [], 'own' => [], 'days' => [] ];
		$plans = [];

		foreach ( self::get_range_keys( $result ) as $key )
			$plans[$key] = self::plan_range( $key, $builder, $basis );

		$hint = '';
		$join = '';
		$where = [];
		$where_params = [];

		if ( $posts ) {
			// the period range drives the read: left
			// to the optimizer, the posts join (or a language semi-join) may
			// drive it instead, reading every row of every counted post through
			// `id_type_period_count`; PRIMARY (type, period, id) exists on every
			// install
			$hint = ' FORCE INDEX (PRIMARY)';
			$join = ' INNER JOIN `' . $wpdb->posts . '` AS p ON p.ID = pv.`id`';
			$where[] = 'p.post_type IN (' . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
			$where_params = $post_types;

			if ( function_exists( 'pvc_post_views_has_content_column' ) && pvc_post_views_has_content_column() )
				$where[] = 'pv.`content` = 0';
		}

		$bounds = [];
		$bound_params = [];

		foreach ( $builder['own'] as $type => $periods ) {
			$periods = array_values( array_unique( $periods ) );
			$bounds[] = '( pv.`type` = %d AND pv.`period` IN (' . implode( ', ', array_fill( 0, count( $periods ), '%s' ) ) . ') )';
			$bound_params = array_merge( $bound_params, [ (int) $type ], $periods );
		}

		foreach ( self::merge_spans( $builder['days'] ) as $span ) {
			$bounds[] = '( pv.`type` = 0 AND pv.`period` >= %s AND pv.`period` <= %s )';
			$bound_params[] = $span[0];
			$bound_params[] = $span[1];
		}

		$where[] = '( ' . implode( ' OR ', $bounds ) . ' )';
		$select = [];

		foreach ( $builder['columns'] as $alias => $expression )
			$select[] = $expression . ' AS `' . $alias . '`';

		$where = $wpdb->prepare( implode( ' AND ', $where ), array_merge( $where_params, $bound_params ) );

		// the caller's condition is already prepared: it leads the WHERE list
		// whole, never through prepare() or a replacement pattern
		if ( $condition !== '' )
			$where = '( ' . $condition . ' ) AND ' . $where;

		$sql = $wpdb->prepare(
			'SELECT ' . implode( ', ', $select ) . ' FROM `' . $wpdb->prefix . 'post_views` AS pv' . $hint . $join,
			$builder['params']
		) . ' WHERE ' . $where;

		$row = self::get_row( $sql );

		if ( $row === false ) {
			$result['reason'] = 'read_failed';

			return $result;
		}

		$result = self::with_views( $result, function( $range ) use ( $plans, $row ) {
			return self::sum_plan( $plans[$range['key']], $row );
		} );
		$result['available'] = $result['current']['views'] !== null && $result['previous']['views'] !== null;
		$result['reason'] = $result['available'] ? 'ok' : 'incomplete';

		return $result;
	}

	/**
	 * Parse a period of a grain.
	 *
	 * @param string $grain
	 * @param string $period
	 * @return array|null [ 'start' => DateTimeImmutable (UTC), 'length' => days ]
	 */
	private static function get_period( $grain, $period ) {
		$utc = new DateTimeZone( 'UTC' );

		switch ( $grain ) {
			case 'week':
				if ( ! preg_match( '/^(\d{4})(\d{2})$/', $period, $matches ) )
					return null;

				$start = DateTimeImmutable::createFromFormat( '!Ymd', '20000103', $utc )->setISODate( (int) $matches[1], (int) $matches[2] );

				// week 53 of a 52-week year would silently become week 1 of the next
				return $start->format( 'oW' ) === $period ? [ 'start' => $start, 'length' => 7 ] : null;

			case 'month':
				if ( ! preg_match( '/^\d{6}$/', $period ) )
					return null;

				$start = DateTimeImmutable::createFromFormat( '!Ymd', $period . '01', $utc );

				return $start && $start->format( 'Ym' ) === $period ? [ 'start' => $start, 'length' => (int) $start->format( 't' ) ] : null;

			case 'year':
				if ( ! preg_match( '/^\d{4}$/', $period ) )
					return null;

				$start = DateTimeImmutable::createFromFormat( '!Ymd', $period . '0101', $utc );

				return $start ? [ 'start' => $start, 'length' => $start->format( 'L' ) === '1' ? 366 : 365 ] : null;
		}

		return null;
	}

	/**
	 * Get the period before a period's first day.
	 *
	 * @param string            $grain
	 * @param DateTimeImmutable $start
	 * @return string
	 */
	private static function shift( $grain, $start ) {
		$formats = [ 'week' => 'oW', 'month' => 'Ym', 'year' => 'Y' ];

		return $start->modify( '-1 day' )->format( $formats[$grain] );
	}

	/**
	 * The range of a whole stored period.
	 *
	 * @param string $grain
	 * @param string $period
	 * @param array  $parsed
	 * @return array
	 */
	private static function own_range( $grain, $period, $parsed ) {
		return [
			'key'	=> $grain . ':' . $period,
			'from'	=> $parsed['start']->format( 'Ymd' ),
			'to'	=> $parsed['start']->modify( '+' . ( $parsed['length'] - 1 ) . ' days' )->format( 'Ymd' )
		];
	}

	/**
	 * The range of a period's first days.
	 *
	 * @param DateTimeImmutable $start
	 * @param int               $days
	 * @return array
	 */
	private static function day_range( $start, $days ) {
		$from = $start->format( 'Ymd' );
		$to = $start->modify( '+' . ( $days - 1 ) . ' days' )->format( 'Ymd' );

		return [ 'key' => 'days:' . $from . '-' . $to, 'from' => $from, 'to' => $to ];
	}

	/**
	 * Every range key a result reads.
	 *
	 * @param array $result
	 * @return array
	 */
	private static function get_range_keys( $result ) {
		$keys = [ $result['current']['key'], $result['previous']['key'] ];

		if ( $result['history']['current'] !== null )
			$keys[] = $result['history']['current']['key'];

		foreach ( $result['history']['periods'] as $range )
			$keys[] = $range['key'];

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Set the Views of every range of a result.
	 *
	 * @param array         $result
	 * @param callable|null $value  Views of a range; null leaves every range unread.
	 * @return array
	 */
	private static function with_views( $result, $value ) {
		$get = function( $range ) use ( $value ) {
			return $range === null ? null : array_merge( $range, [ 'views' => $value === null ? null : $value( $range ) ] );
		};

		$result['current'] = $get( $result['current'] );
		$result['previous'] = $get( $result['previous'] );
		$result['history']['current'] = $get( $result['history']['current'] );

		foreach ( $result['history']['periods'] as $key => $range )
			$result['history']['periods'][$key] = $get( $range );

		return $result;
	}

	/**
	 * Plan the columns of a range: own rows as they are; day spans from daily
	 * rows, whole calendar months from their monthly rows, each touched month
	 * (the ISO week of a week chart) proven by its daily rows.
	 *
	 * @param string $key   week:, month:, year: or days: range key
	 * @param array  $builder
	 * @param string $basis week or month
	 * @return array [ 'values' => aliases, 'checks' => [ [ daily alias, own alias ], ... ] ]
	 */
	private static function plan_range( $key, &$builder, $basis ) {
		$parts = explode( ':', $key, 2 );

		if ( isset( self::TYPES[$parts[0]] ) )
			return [ 'values' => [ self::own_column( self::TYPES[$parts[0]], $parts[1], $builder ) ], 'checks' => [] ];

		$utc = new DateTimeZone( 'UTC' );
		list( $from, $to ) = explode( '-', $parts[1] );
		$from = DateTimeImmutable::createFromFormat( '!Ymd', $from, $utc );
		$to = DateTimeImmutable::createFromFormat( '!Ymd', $to, $utc );
		$plan = [ 'values' => [], 'checks' => [] ];
		$segments = [];
		$cursor = $from;

		while ( $cursor <= $to ) {
			$month_end = $cursor->setDate( (int) $cursor->format( 'Y' ), (int) $cursor->format( 'n' ), (int) $cursor->format( 't' ) );
			$chunk_end = $month_end < $to ? $month_end : $to;

			if ( $cursor->format( 'j' ) === '1' && $chunk_end == $month_end )
				$plan['values'][] = self::own_column( 2, $cursor->format( 'Ym' ), $builder );
			else {
				$last = count( $segments ) - 1;

				if ( $last >= 0 && $segments[$last][1]->modify( '+1 day' ) == $cursor )
					$segments[$last][1] = $chunk_end;
				else
					$segments[] = [ $cursor, $chunk_end ];
			}

			$cursor = $chunk_end->modify( '+1 day' );
		}

		if ( empty( $segments ) )
			return $plan;

		foreach ( $segments as $segment )
			$plan['values'][] = self::days_column( $segment[0], $segment[1], $builder );

		$first = $segments[0][0];
		$last = $segments[count( $segments ) - 1][1];

		// completeness in the chart's basis: the ISO week of a week chart, else each calendar month
		if ( $basis === 'week' && $first->format( 'oW' ) === $last->format( 'oW' ) ) {
			$monday = $first->setISODate( (int) $first->format( 'o' ), (int) $first->format( 'W' ) );
			$plan['checks'][] = [ self::days_column( $monday, $monday->modify( '+6 days' ), $builder ), self::own_column( 1, $first->format( 'oW' ), $builder ) ];

			return $plan;
		}

		$months = [];

		foreach ( $segments as $segment ) {
			$month = $segment[0]->setDate( (int) $segment[0]->format( 'Y' ), (int) $segment[0]->format( 'n' ), 1 );

			while ( $month <= $segment[1] ) {
				$months[$month->format( 'Ym' )] = $month;
				$month = $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ) + 1, 1 );
			}
		}

		foreach ( $months as $month_key => $month )
			$plan['checks'][] = [ self::days_column( $month, $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), (int) $month->format( 't' ) ), $builder ), self::own_column( 2, $month_key, $builder ) ];

		return $plan;
	}

	/**
	 * Get (or add) the column summing a period's own row.
	 *
	 * @param int    $type
	 * @param string $period
	 * @param array  $builder
	 * @return string Alias
	 */
	private static function own_column( $type, $period, &$builder ) {
		$alias = 'o' . $type . '_' . $period;

		if ( ! isset( $builder['columns'][$alias] ) ) {
			$builder['columns'][$alias] = 'COALESCE( SUM( CASE WHEN pv.`type` = %d AND pv.`period` = %s THEN pv.`count` ELSE 0 END ), 0 )';
			$builder['params'][] = (int) $type;
			$builder['params'][] = (string) $period;
			$builder['own'][$type][] = (string) $period;
		}

		return $alias;
	}

	/**
	 * Get (or add) the column summing the daily rows of an inclusive day span.
	 *
	 * @param DateTimeImmutable $from
	 * @param DateTimeImmutable $to
	 * @param array             $builder
	 * @return string Alias
	 */
	private static function days_column( $from, $to, &$builder ) {
		$alias = 'd_' . $from->format( 'Ymd' ) . '_' . $to->format( 'Ymd' );

		if ( ! isset( $builder['columns'][$alias] ) ) {
			$builder['columns'][$alias] = 'COALESCE( SUM( CASE WHEN pv.`type` = 0 AND pv.`period` >= %s AND pv.`period` <= %s THEN pv.`count` ELSE 0 END ), 0 )';
			$builder['params'][] = $from->format( 'Ymd' );
			$builder['params'][] = $to->format( 'Ymd' );
			$builder['days'][] = [ $from->format( 'Ymd' ), $to->format( 'Ymd' ) ];
		}

		return $alias;
	}

	/**
	 * Merge overlapping or adjacent day spans for the WHERE bound.
	 *
	 * @param array $spans [ [ Ymd, Ymd ], ... ]
	 * @return array
	 */
	private static function merge_spans( $spans ) {
		if ( empty( $spans ) )
			return [];

		usort( $spans, function( $a, $b ) {
			return strcmp( $a[0], $b[0] );
		} );

		$utc = new DateTimeZone( 'UTC' );
		$merged = [ $spans[0] ];

		foreach ( array_slice( $spans, 1 ) as $span ) {
			$last = count( $merged ) - 1;
			$next_day = DateTimeImmutable::createFromFormat( '!Ymd', $merged[$last][1], $utc )->modify( '+1 day' )->format( 'Ymd' );

			if ( strcmp( $span[0], $next_day ) <= 0 ) {
				if ( strcmp( $span[1], $merged[$last][1] ) > 0 )
					$merged[$last][1] = $span[1];
			} else
				$merged[] = $span;
		}

		return $merged;
	}

	/**
	 * Sum a plan's values when its checks hold.
	 *
	 * @param array $plan
	 * @param array $row
	 * @return int|null
	 */
	private static function sum_plan( $plan, $row ) {
		foreach ( $plan['checks'] as $check ) {
			if ( (int) ( isset( $row[$check[0]] ) ? $row[$check[0]] : 0 ) !== (int) ( isset( $row[$check[1]] ) ? $row[$check[1]] : 0 ) )
				return null;
		}

		$value = 0;

		foreach ( $plan['values'] as $alias )
			$value += isset( $row[$alias] ) ? (int) $row[$alias] : 0;

		return $value;
	}

	/**
	 * Run the statement, cached with the chart's freshness boundary;
	 * a failure is never cached and never read as zero.
	 *
	 * @param string $sql
	 * @return array|false The one result row.
	 */
	private static function get_row( $sql ) {
		global $wpdb;

		$cache_key = Post_Views_Counter_Visits_Query::get_read_cache_key( $sql );
		$row = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $row ) )
			return $row;

		$wpdb->last_error = '';
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( $wpdb->last_error !== '' || ! is_array( $row ) )
			return false;

		wp_cache_set( $cache_key, $row, self::CACHE_GROUP, absint( apply_filters( 'pvc_object_cache_expire', 300 ) ) );

		return $row;
	}
}
