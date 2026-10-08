<?php
/**
 * Post Views Counter pluggable template functions
 *
 * Override any of those functions by copying it to your theme or replace it via plugin
 *
 * @author Digital Factory
 * @package Post Views Counter
 * @since 1.0.0
 */
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Get post views for a post or array of posts.
 *
 * @global object $wpdb
 *
 * @param int|array $post_id
 * @param string $period
 * @param array $args Optional arguments to override period parsing
 *
 * @return int
 */
if ( ! function_exists( 'pvc_get_post_views' ) ) {
	function pvc_get_post_views( $post_id = 0, $period = 'total', $args = [] ) {
		global $wpdb;

		// ensure args is an array
		if ( ! is_array( $args ) ) {
			$args = [];
		}

		// sanitize period
		$period = sanitize_key( $period );

		if ( empty( $post_id ) )
			$post_id = get_the_ID();

		if ( is_array( $post_id ) ) {
			$numbers = array_filter( array_unique( array_map( 'intval', $post_id ) ) );
			$post_id = implode( ',', $numbers );
		} else if ( $post_id === 'all' ) {
			$numbers = [];
		} else {
			$post_id = (int) $post_id;
			$numbers = [ $post_id ];
		}

		if ( isset( Post_Views_Counter()->visits ) && method_exists( Post_Views_Counter()->visits, 'is_site_data_accessible' ) && ! Post_Views_Counter()->visits->is_site_data_accessible() )
			return (int) apply_filters( 'pvc_get_post_views', 0, $post_id, $period, $args );

		// Resolve Core-supported named and storage periods before the compatibility
		// filter runs. Unknown helper periods still receive one filter opportunity.
		$has_explicit_range = isset( $args['period_range'] ) || isset( $args['period_from'], $args['period_to'] );
		$normalized_period = $has_explicit_range ? null : pvc_normalize_views_period( $period, $args );

		// set where clause
		$where = [ 'type' => 'type = 4' ];

		if ( $normalized_period !== null ) {
			$where['type'] = 'type = ' . (int) $normalized_period['type'];

			if ( (int) $normalized_period['type'] === 4 )
				$where['period'] = "period = 'total'";
			elseif ( $normalized_period['period_from'] === $normalized_period['period_to'] )
				$where['period'] = 'CAST( period AS SIGNED ) = ' . (int) $normalized_period['period_from'];
			else
				$where['period'] = 'CAST( period AS SIGNED ) <= ' . (int) $normalized_period['period_to'] . ' AND CAST( period AS SIGNED ) >= ' . (int) $normalized_period['period_from'];
		}

		$has_explicit_type = false;

		// Override type if explicitly provided in args. Historically an explicit
		// type without a range selected every row of that storage type, so it must
		// not retain a canonical period predicate from the named period argument.
		if ( isset( $args['type'] ) ) {
			$where['type'] = 'type = ' . (int) $args['type'];
			$has_explicit_type = true;
		} elseif ( isset( $args['period_type'] ) ) {
			$period_type = sanitize_key( $args['period_type'] );
			$type_map = [
				'day'   => 0,
				'week'  => 1,
				'month' => 2,
				'year'  => 3,
				'total' => 4
			];
			if ( isset( $type_map[ $period_type ] ) ) {
				$where['type'] = 'type = ' . $type_map[ $period_type ];
				$has_explicit_type = true;
			}
		}

		if ( $has_explicit_type && ! $has_explicit_range )
			unset( $where['period'] );

		// optional period range (e.g., for day-based ranges within a month)
		$range_from = null;
		$range_to = null;

		if ( isset( $args['period_range'] ) && is_array( $args['period_range'] ) ) {
			$range = array_values( $args['period_range'] );
			if ( isset( $range[0], $range[1] ) ) {
				$range_from = (int) $range[0];
				$range_to = (int) $range[1];
			}
		} elseif ( isset( $args['period_from'], $args['period_to'] ) ) {
			$range_from = (int) $args['period_from'];
			$range_to = (int) $args['period_to'];
		}

		if ( $range_from && $range_to ) {
			$range_min = min( $range_from, $range_to );
			$range_max = max( $range_from, $range_to );

			$where['period'] = 'CAST( period AS SIGNED ) <= ' . $range_max . ' AND CAST( period AS SIGNED ) >= ' . $range_min;

			// default to day type when using explicit ranges and no type set
			if ( ! isset( $args['type'] ) && ! isset( $args['period_type'] ) ) {
				$where['type'] = 'type = 0';
			}
		}

		$is_total_period_query = false;

		if (
			$period === 'total'
			&& ( ! isset( $args['type'] ) || (int) $args['type'] === 4 )
			&& ( ! isset( $args['period_type'] ) || sanitize_key( $args['period_type'] ) === 'total' )
		) {
			$is_total_period_query = true;
		} elseif (
			isset( $args['period_type'] )
			&& sanitize_key( $args['period_type'] ) === 'total'
			&& ( ! isset( $args['type'] ) || (int) $args['type'] === 4 )
		) {
			$is_total_period_query = true;
		} elseif (
			isset( $args['type'] )
			&& (int) $args['type'] === 4
			&& ! isset( $args['period_type'] )
			&& in_array( $period, [ '', 'total' ], true )
		) {
			$is_total_period_query = true;
		}

		// total views are stored as a dedicated period; constrain the query so existing indexes can be used.
		if ( $is_total_period_query && ! $range_from && ! $range_to ) {
			$where['period'] = "period = 'total'";
		}

		// handle explicit content parameter for custom implementations
		if ( isset( $args['content'] ) ) {
			$where['content'] = 'content = ' . (int) $args['content'];
		}

		// update where clause
		$filter_args = $args;

		if ( $normalized_period !== null )
			$filter_args['_pvc_core_period_resolved'] = $normalized_period;

		$where = apply_filters( 'pvc_get_post_views_period_where', $where, $period, $post_id, $filter_args );

		if ( ! is_array( $where ) )
			return 0;

		// Explicit content is authoritative across Core and extension callbacks.
		if ( isset( $args['content'] ) )
			$where['content'] = 'content = ' . (int) $args['content'];

		// updated where clause
		$_where = [];
		$strict_period_selector = false;

		// sanitize where clause
		foreach ( $where as $index => $value ) {
			if ( $index === 'type' || $index === 'content' ) {
				if ( ! is_scalar( $value ) )
					continue;

				$selector = preg_replace( '/[^0-9]/', '', (string) $value );

				if ( $selector !== '' )
					$_where[$index] = $selector;
			}
			elseif ( $index === 'period' ) {
				if ( ! is_string( $value ) )
					continue;

				$period_operand = '(?:CAST\s*\(\s*period\s+AS\s+(?:SIGNED|UNSIGNED)\s*\)|period)';

				if ( preg_match( "/^\s*period\s*=\s*'?total'?\s*$/i", $value ) ) {
					$_where['period'] = [ 'total' ];
					$strict_period_selector = true;
					continue;
				}

				$values = preg_match_all( '/\d+/', $value, $matches );

				// any values?
				if ( $values !== false && $values > 0 && $values <= 2 )
					$_where['period'] = $matches[0];

				if ( preg_match( '/^\s*' . $period_operand . "\s*=\s*'?\d+'?\s*$/i", $value ) )
					$strict_period_selector = true;
				elseif ( preg_match( '/^\s*' . $period_operand . "\s*<=\s*'?\d+'?\s+AND\s+" . $period_operand . "\s*>=\s*'?\d+'?\s*$/i", $value ) )
					$strict_period_selector = true;
				elseif ( preg_match( '/^\s*' . $period_operand . "\s*>=\s*'?\d+'?\s+AND\s+" . $period_operand . "\s*<=\s*'?\d+'?\s*$/i", $value ) )
					$strict_period_selector = true;
				elseif ( preg_match( '/^\s*' . $period_operand . "\s+BETWEEN\s+'?\d+'?\s+AND\s+'?\d+'?\s*$/i", $value ) )
					$strict_period_selector = true;
			}
		}

		// A raw SQL-fragment filter remains a helper-only compatibility mechanism.
		// Unknown periods fail closed unless it supplied a complete, strictly
		// recognized storage selector. REST never consults this hook.
		if ( $normalized_period === null && ! $range_from && ! $range_to && ( ! isset( $_where['type'], $_where['period'] ) || ! $strict_period_selector ) )
			return 0;

		// get current number of ids
		$ids_count = count( $numbers );

		$where_parts = [];

		// add post ids if needed
		if ( $ids_count )
			$where_parts[] = "id IN (" . implode( ',', array_fill( 0, $ids_count, '%d' ) ) . ")";

		// validate where clause
		foreach( $_where as $index => $value ) {
			if ( $index === 'type' ) {
				$where_parts[] = 'type = %d';
				$numbers[] = (int) $value;
			} elseif ( $index === 'content' && pvc_post_views_has_content_column() ) {
				$where_parts[] = 'content = %d';
				$numbers[] = (int) $value;
			} elseif ( $index === 'period' ) {
				$nop = count( $_where['period'] );

				if ( $nop === 1 ) {
					if ( $_where['period'][0] === 'total' ) {
						$where_parts[] = 'period = %s';
						$numbers[] = 'total';
					} else {
						$where_parts[] = 'CAST( period AS SIGNED ) = %d';
						$numbers[] = (int) $_where['period'][0];
					}
				} elseif ( $nop === 2 ) {
					// Recognized selectors may list bounds in either order, so normalize
					// them instead of trusting the extracted positional order.
					$bounds = [
						(int) $_where['period'][0],
						(int) $_where['period'][1]
					];

					$where_parts[] = 'CAST( period AS SIGNED ) <= %d AND CAST( period AS SIGNED ) >= %d';
					$numbers[] = max( $bounds );
					$numbers[] = min( $bounds );
				}
			}
		}

		if ( empty( $where_parts ) )
			return 0;

		$where_clause = ' WHERE ' . implode( ' AND ', $where_parts );

		// prepare query
		$query = $wpdb->prepare( "SELECT SUM(count) AS views FROM " . $wpdb->prefix . "post_views" . $where_clause, $numbers );

		// calculate query hash
		$query_hash = md5( $query );

		// get cached data
		$post_views = wp_cache_get( $query_hash, 'pvc-get_post_views' );

		// cached data not found?
		if ( $post_views === false ) {
			// get post views
			$post_views = (int) $wpdb->get_var( $query );

			// set the cache expiration, 5 minutes by default
			$expire = absint( apply_filters( 'pvc_object_cache_expire', 300 ) );

			// add cached post views
			wp_cache_add( $query_hash, $post_views, 'pvc-get_post_views', $expire );
		}

		return (int) apply_filters( 'pvc_get_post_views', $post_views, $post_id, $period, $args );
	}
}

/**
 * Check if content column exists in post_views table.
 *
 * @global object $wpdb
 *
 * @return bool
 */
function pvc_post_views_has_content_column() {
	$status = isset( Post_Views_Counter()->visits ) ? Post_Views_Counter()->visits->get_shared_content_column_status() : false;

	// On an ambiguous probe, requiring the discriminator fails closed on a legacy
	// schema instead of silently mixing extended content rows that share an ID.
	$has_content_column = $status === null ? true : $status;

	// always allow override via filter (applied on every call)
	return apply_filters( 'pvc_post_views_has_content_column', $has_content_column );
}

/**
 * Get views query.
 *
 * @global object $wpdb
 *
 * @param array $args
 *
 * @return int|array
 */
if ( ! function_exists( 'pvc_get_views' ) ) {
	function pvc_get_views( $args = [] ) {
		global $wpdb;

		$range = [];
		$defaults = [
			'fields'		=> 'views',
			'post_id'		=> '',
			'post_type'		=> '',
			'views_query'	=> [
				'year'		=> '',
				'month'		=> '',
				'week'		=> '',
				'day'		=> '',
				'after'		=> '',	// string or array
				'before'	=> '',	// string or array
				'inclusive'	=> true
			]
		];

		// merge default options with new arguments
		$args = array_merge( $defaults, $args );

		// check views query
		if ( ! is_array( $args['views_query'] ) )
			$args['views_query'] = $defaults['views_query'];

		// merge views query too
		$args['views_query'] = array_merge( $defaults['views_query'], $args['views_query'] );

		// filter arguments
		$args = apply_filters( 'pvc_get_views_args', $args );

		// check post types
		if ( is_string( $args['post_type'] ) )
			$args['post_type'] = [ $args['post_type'] ];
		elseif ( ! is_array( $args['post_type'] ) )
			$args['post_type'] = [];

		// get number of post types
		$post_types_count = count( $args['post_type'] );

		// check post ids
		if ( is_array( $args['post_id'] ) && ! empty( $args['post_id'] ) )
			$args['post_id'] = array_filter( array_unique( array_map( 'intval', $args['post_id'] ) ) );
		elseif ( is_string( $args['post_id'] ) || is_numeric( $args['post_id'] ) ) {
			$post_id = (int) $args['post_id'];

			if ( $post_id === 0 )
				$args['post_id'] = [];
			else
				$args['post_id'] = [ $post_id ];
		} else
			$args['post_id'] = [];

		// get number of post ids
		$post_ids_count = count( $args['post_id'] );

		// placeholder for empty query data
		$query_data = [ 1 ];

		// set query data
		if ( $post_ids_count === 0 && $post_types_count === 0 )
			$query_data = [ 1 ];
		elseif ( $post_ids_count === 0 )
			$query_data = array_merge( $query_data, array_values( $args['post_type'] ) );
		elseif ( $post_types_count === 0 )
			$query_data = array_merge( $query_data, array_values( $args['post_id'] ) );
		else
			$query_data = array_merge( $query_data, array_values( $args['post_id'] ), array_values( $args['post_type'] ) );
		
		// set where clause, post rows only when the table stores other content
		$where = [];

		if ( pvc_post_views_has_content_column() )
			$where['content'] = 'pvc.content = 0';

		$where = apply_filters( 'pvc_get_views_period_where', $where, $args );

		// every period of one type has one length of digits (Ymd, oW, Ym, Y), so
		// a same-length string bound selects the rows a numeric one would and
		// keeps the (type, period) keys usable, which CAST( period ) defeats
		$period_sql = static function( $period ) {
			return "'" . preg_replace( '/[^0-9]/', '', (string) $period ) . "'";
		};

		// check fields
		if ( ! in_array( $args['fields'], [ 'views', 'date=>views' ], true ) )
			$args['fields'] = $defaults['fields'];

		$query_chunks = [];
		$views_query = '';

		// views query after/before parameters work only when fields == views
		if ( $args['fields'] === 'views' ) {
			// check views query inclusive
			if ( ! isset( $args['views_query']['inclusive'] ) )
				$args['views_query']['inclusive'] = $defaults['views_query']['inclusive'];
			else
				$args['views_query']['inclusive'] = (bool) $args['views_query']['inclusive'];

			// check after and before dates
			foreach ( [ 'after' => '>', 'before' => '<' ] as $date => $type ) {
				$year_ = null;
				$month_ = null;
				$week_ = null;
				$day_ = null;

				// check views query date
				if ( ! empty( $args['views_query'][$date] ) ) {
					// is it a date array?
					if ( is_array( $args['views_query'][$date] ) ) {
						// check views query $date date year
						if ( ! empty( $args['views_query'][$date]['year'] ) )
							$year_ = str_pad( (int) $args['views_query'][$date]['year'], 4, 0, STR_PAD_LEFT );

						// check views query date month
						if ( ! empty( $args['views_query'][$date]['month'] ) )
							$month_ = str_pad( (int) $args['views_query'][$date]['month'], 2, 0, STR_PAD_LEFT );

						// check views query date week
						if ( ! empty( $args['views_query'][$date]['week'] ) )
							$week_ = str_pad( (int) $args['views_query'][$date]['week'], 2, 0, STR_PAD_LEFT );

						// check views query date day
						if ( ! empty( $args['views_query'][$date]['day'] ) )
							$day_ = str_pad( (int) $args['views_query'][$date]['day'], 2, 0, STR_PAD_LEFT );
					// is it a date string?
					} elseif ( is_string( $args['views_query'][$date] ) ) {
						$time_ = strtotime( $args['views_query'][$date] );

						// valid datetime?
						if ( $time_ !== false ) {
							// week does not exists here, string dates are always treated as year + month + day
							list( $day_, $month_, $year_ ) = explode( ' ', date( "d m Y", $time_ ) );
						}
					}

					// valid date?
					if ( ! ( $year_ === null && $month_ === null && $week_ === null && $day_ === null ) ) {
						$query_chunks[] = [
							'year'	=> $year_,
							'month'	=> $month_,
							'day'	=> $day_,
							'week'	=> $week_,
							'type'	=> $type . ( $args['views_query']['inclusive'] ? '=' : '' )
						];
					}
				}
			}

			if ( ! empty( $query_chunks ) ) {
				$valid_dates = true;

				// after and before?
				if ( count( $query_chunks ) === 2 ) {
					// before and after dates should be the same
					foreach ( [ 'year', 'month', 'day', 'week' ] as $date_type ) {
						if ( ! ( ( $query_chunks[0][$date_type] !== null && $query_chunks[1][$date_type] !== null ) || ( $query_chunks[0][$date_type] === null && $query_chunks[1][$date_type] === null ) ) )
							$valid_dates = false;
					}
				}

				if ( $valid_dates ) {
					foreach ( $query_chunks as $chunk ) {
						// year
						if ( isset( $chunk['year'] ) ) {
							// year, week
							if ( isset( $chunk['week'] ) ) {
								$where['type'] = 'pvc.type = 1';
								$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND pvc.period " . $chunk['type'] . " " . $period_sql( $chunk['year'] . $chunk['week'] );
							}
							// year, month
							elseif ( isset( $chunk['month'] ) ) {
								// year, month, day
								if ( isset( $chunk['day'] ) ) {
									$where['type'] = 'pvc.type = 0';
									$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND pvc.period " . $chunk['type'] . " " . $period_sql( $chunk['year'] . $chunk['month'] . $chunk['day'] );
								}
								// year, month
								else {
									$where['type'] = 'pvc.type = 2';
									$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND pvc.period " . $chunk['type'] . " " . $period_sql( $chunk['year'] . $chunk['month'] );
								}
							// year
							} else {
								$where['type'] = 'pvc.type = 3';
								$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND pvc.period " . $chunk['type'] . " " . $period_sql( $chunk['year'] );
							}
						// month
						} elseif ( isset( $chunk['month'] ) ) {
							// month, day
							if ( isset( $chunk['day'] ) ) {
								$where['type'] = 'pvc.type = 0';
								$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND CAST( RIGHT( pvc.period, 4 ) AS SIGNED ) " . $chunk['type'] . " " . (int) ( $chunk['month'] . $chunk['day'] );
							// month
							} else {
								$where['type'] = 'pvc.type = 2';
								$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND CAST( RIGHT( pvc.period, 2 ) AS SIGNED ) " . $chunk['type'] . " " . (int) ( $chunk['month'] );
							}
						// week
						} elseif ( isset( $chunk['week'] ) ) {
								$where['type'] = 'pvc.type = 1';
								$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND CAST( RIGHT( pvc.period, 2 ) AS SIGNED ) " . $chunk['type'] . " " . (int) ( $chunk['week'] );
						}
						// day
						elseif ( isset( $chunk['day'] ) ) {
							$where['type'] = 'pvc.type = 0';
							$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND CAST( RIGHT( pvc.period, 2 ) AS SIGNED ) " . $chunk['type'] . " " . (int) ( $chunk['day'] );
						}
					}
				}
			}
		}

		$special_views_query = ( $views_query !== '' );

		if ( $args['fields'] === 'date=>views' || $views_query === '' ) {
			// check views query year
			if ( ! empty( $args['views_query']['year'] ) )
				$year = str_pad( (int) $args['views_query']['year'], 4, 0, STR_PAD_LEFT );

			// check views query month
			if ( ! empty( $args['views_query']['month'] ) )
				$month = str_pad( (int) $args['views_query']['month'], 2, 0, STR_PAD_LEFT );

			// check views query week
			if ( ! empty( $args['views_query']['week'] ) )
				$week = str_pad( (int) $args['views_query']['week'], 2, 0, STR_PAD_LEFT );

			// check views query day
			if ( ! empty( $args['views_query']['day'] ) )
				$day = str_pad( (int) $args['views_query']['day'], 2, 0, STR_PAD_LEFT );

			// year
			if ( isset( $year ) ) {
				// year, week
				if ( isset( $week ) ) {
					if ( $args['fields'] === 'date=>views' ) {
						// create date based on week number
						$date = new DateTime( $year . 'W' . $week );

						// get monday
						$monday = $date->format( 'd' );

						// get month of monday
						$monday_month = $date->format( 'm' );

						// and its calendar year: week 1 may start in the previous one
						$monday_year = $date->format( 'Y' );

						// prepare range
						for( $i = 1; $i <= 6; $i++ ) {
							$range[(string) ( $date->format( 'Y' ) . $date->format( 'm' ) . $date->format( 'd' ) )] = 0;

							$date->modify( '+1days' );
						}

						$range[(string) ( $date->format( 'Y' ) . $date->format( 'm' ) . $date->format( 'd' ) )] = 0;

						// get month of sunday
						$sunday_month = $date->format( 'm' );

						$where['type'] = 'pvc.type = 0';
						$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND pvc.period >= " . $period_sql( $monday_year . $monday_month . $monday ) . " AND pvc.period <= " . $period_sql( $date->format( 'Y' ) . $sunday_month . $date->format( 'd' ) );
					} else {
						$where['type'] = 'pvc.type = 1';
						$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND pvc.period = " . $period_sql( $year . $week );
					}
				// year, month
				} elseif ( isset( $month ) ) {
					// year, month, day
					if ( isset( $day ) ) {
						if ( $args['fields'] === 'date=>views' )
							// prepare range
							$range[(string) ( $year . $month . $day )] = 0;

						$where['type'] = 'pvc.type = 0';
						$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND pvc.period = " . $period_sql( $year . $month . $day );
					// year, month
					} else {
						if ( $args['fields'] === 'date=>views' ) {
							// create date
							$date = new DateTime( $year . '-' . $month . '-01' );

							// get last day
							$last = $date->format( 't' );

							// prepare range
							for( $i = 1; $i <= $last; $i++ ) {
								$range[(string) ( $year . $month . str_pad( $i, 2, 0, STR_PAD_LEFT ) )] = 0;
							}

							$where['type'] = 'pvc.type = 0';
							$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND pvc.period >= " . $period_sql( $year . $month . '01' ) . " AND pvc.period <= " . $period_sql( $year . $month . $last );
						} else {
							$where['type'] = 'pvc.type = 2';
							$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND pvc.period = " . $period_sql( $year . $month );
						}
					}
				// year
				} else {
					if ( $args['fields'] === 'date=>views' ) {
						// prepare range
						for( $i = 1; $i <= 12; $i++ ) {
							$range[(string) ( $year . str_pad( $i, 2, 0, STR_PAD_LEFT ) )] = 0;
						}

						// create date
						$date = new DateTime( $year . '-12-01' );

						$where['type'] = 'pvc.type = 2';
						$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND pvc.period >= " . $period_sql( $year . '01' ) . " AND pvc.period <= " . $period_sql( $year . '12' );
					} else {
						$where['type'] = 'pvc.type = 3';
						$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND pvc.period = " . $period_sql( $year );
					}
				}
			// month
			} elseif ( isset( $month ) ) {
				// month, day
				if ( isset( $day ) ) {
					$where['type'] = 'pvc.type = 0';
					$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND CAST( RIGHT( pvc.period, 4 ) AS SIGNED ) = " . (int) ( $month . $day );
				// month
				} else {
					$where['type'] = 'pvc.type = 2';
					$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND CAST( RIGHT( pvc.period, 2 ) AS SIGNED ) = " . (int) ( $month );
				}
			// week
			} elseif ( isset( $week ) ) {
				$where['type'] = 'pvc.type = 1';
				$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND CAST( RIGHT( pvc.period, 2 ) AS SIGNED ) = " . (int) ( $week );
			// day
			} elseif ( isset( $day ) ) {
				$where['type'] = 'pvc.type = 0';
				$views_query .= ' AND ' . implode( ' AND ', $where ) . " AND CAST( RIGHT( pvc.period, 2 ) AS SIGNED ) = " . (int) ( $day );
			}
		}

		// update where clause
		$where['type'] = 'pvc.type = 4';

		$query = $wpdb->prepare(
			"SELECT " . ( $args['fields'] === 'date=>views' ? 'pvc.period, ' : '' ) . "SUM( COALESCE( pvc.count, 0 ) ) AS post_views
			FROM " . $wpdb->prefix . "posts wpp
			LEFT JOIN " . $wpdb->prefix . "post_views pvc ON pvc.id = wpp.ID AND 1 = %d" . ( $views_query !== '' ? ' ' . $views_query : ' AND ' . implode( ' AND ', $where ) ) . ( ! empty( $args['post_id'] ) ? ' AND pvc.id IN (' . implode( ',', array_fill( 0, $post_ids_count, '%d' ) ) . ')' : '' ) . "
			" . ( ! empty( $args['post_type'] ) ? 'WHERE wpp.post_type IN (' . implode( ',', array_fill( 0, $post_types_count, '%s' ) ) . ')' : '' ) . "
			" . ( $views_query !== '' && $special_views_query === false ? 'GROUP BY pvc.period HAVING post_views > 0' : '' ),
			$query_data
		);

		$query = apply_filters( 'pvc_get_views_query_sql', $query, $args, $views_query );

		// get cached data
		$post_views = wp_cache_get( md5( $query ), 'pvc-get_views' );

		// cached data not found?
		if ( $post_views === false ) {
			if ( $args['fields'] === 'date=>views' && ! empty( $range ) ) {
				$results = $wpdb->get_results( $query );

				if ( ! empty( $results ) ) {
					foreach ( $results as $row ) {
						$range[$row->period] = (int) $row->post_views;
					}
				}

				$post_views = $range;
			} else
				$post_views = (int) $wpdb->get_var( $query );

			// set the cache expiration, 5 minutes by default
			$expire = absint( apply_filters( 'pvc_object_cache_expire', 300 ) );

			wp_cache_add( md5( $query ), $post_views, 'pvc-get_views', $expire );
		}

		return apply_filters( 'pvc_get_views', $post_views );
	}
}

/**
 * Normalize a Views period to the canonical shared-table selector.
 *
 * Pluggable: a theme or plugin may define this before the plugin loads.
 *
 * @since 1.7.16
 *
 * @param mixed $period Requested period.
 * @param array $args Optional explicit period arguments.
 *
 * @return array|null Normalized period, or null when the request is invalid.
 */
if ( ! function_exists( 'pvc_normalize_views_period' ) ) {
	function pvc_normalize_views_period( $period = 'total', $args = [] ) {
		return Post_Views_Counter_Visits_Query::normalize_views_period( $period, $args );
	}
}

/**
 * Normalize one Visit period into storage and derived-range semantics.
 *
 * Availability ranges are half-open. Storage day ranges remain inclusive because
 * the table stores one YYYYMMDD key per calendar day.
 *
 * Pluggable: a theme or plugin may define this before the plugin loads.
 *
 * @since 1.7.16
 *
 * @param string|array $period Named or explicit storage period.
 * @param array        $args Optional explicit range/type arguments.
 *
 * @return array|null Normalized period, or null when the request is invalid.
 */
if ( ! function_exists( 'pvc_normalize_visits_period' ) ) {
	function pvc_normalize_visits_period( $period = 'total', $args = [] ) {
		return Post_Views_Counter_Visits_Query::normalize_period( $period, $args );
	}
}

/**
 * Get aggregate post Visits for an explicit range.
 *
 * @since 1.7.16
 *
 * @param array $args Query arguments.
 *
 * @return int|array|null Scalar or availability-bearing date result. Integer
 *                        zero is a stored zero; null means Visits are
 *                        unavailable. There is no public Visit increment or
 *                        update API.
 */
if ( ! function_exists( 'pvc_get_visits' ) ) {
	function pvc_get_visits( $args = [] ) {
		global $wpdb;

		$args = is_array( $args ) ? $args : [];
		$range = Post_Views_Counter_Visits_Query::parse_range( $args );
		$availability = isset( Post_Views_Counter()->visits ) ? Post_Views_Counter()->visits->get_visits_availability( $range ) : [ 'readable' => false, 'read_reason' => 'schema_failed' ];
		$fields = isset( $args['fields'] ) && $args['fields'] === 'date=>visits' ? 'date=>visits' : 'visits';

		if ( $range === null || empty( $availability['readable'] ) )
			return $fields === 'date=>visits' ? [ 'values' => [], 'availability' => $availability ] : null;

		// daily keys are fixed-width Ymd strings, so the (type, period) key serves the range
		$where = [ 'pvc.`type` = 0', 'pvc.`period` BETWEEN %s AND %s' ];
		$params = [ sprintf( '%08d', $range['period_from'] ), sprintf( '%08d', $range['period_to'] ) ];

		if ( pvc_post_views_has_content_column() )
			$where[] = 'pvc.`content` = 0';

		$post_types = isset( $args['post_type'] ) ? array_values( array_filter( array_map( 'sanitize_key', (array) $args['post_type'] ) ) ) : [];
		$join = '';

		if ( ! empty( $post_types ) ) {
			$join = ' INNER JOIN `' . $wpdb->posts . '` posts ON posts.ID = pvc.id';
			$where[] = 'posts.post_type IN (' . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
			$params = array_merge( $params, $post_types );
		}

		$select = $fields === 'date=>visits' ? 'pvc.`period`, SUM(pvc.`visits`) AS post_visits' : 'SUM(pvc.`visits`)';
		$group = $fields === 'date=>visits' ? ' GROUP BY pvc.`period` ORDER BY pvc.`period` ASC' : '';
		$sql = $wpdb->prepare( 'SELECT ' . $select . ' FROM `' . $wpdb->prefix . 'post_views` pvc' . $join . ' WHERE ' . implode( ' AND ', $where ) . $group, $params );
		$cache_key = Post_Views_Counter_Visits_Query::get_read_cache_key( $sql );

		if ( $fields === 'date=>visits' ) {
			$values = wp_cache_get( $cache_key, Post_Views_Counter_Visits_Query::VISITS_CACHE_GROUP );

			if ( $values === false ) {
				$values = [];

				$wpdb->last_error = '';
				$rows = $wpdb->get_results( $sql );

				if ( $wpdb->last_error !== '' )
					return [ 'values' => [], 'availability' => array_merge( $availability, [ 'readable' => false, 'read_reason' => 'database_error' ] ) ];

				foreach ( (array) $rows as $row )
					$values[(string) $row->period] = (int) $row->post_visits;

				wp_cache_add( $cache_key, $values, Post_Views_Counter_Visits_Query::VISITS_CACHE_GROUP, absint( apply_filters( 'pvc_visits_object_cache_expire', 300 ) ) );
			}

			return apply_filters( 'pvc_get_visits', [ 'values' => $values, 'availability' => $availability ], $args );
		}

		$value = wp_cache_get( $cache_key, Post_Views_Counter_Visits_Query::VISITS_CACHE_GROUP );

		if ( $value === false ) {
			$result = Post_Views_Counter_Visits_Query::get_scalar_result( $sql );

			if ( ! $result['success'] )
				return null;

			$value = $result['value'];
			wp_cache_add( $cache_key, $value, Post_Views_Counter_Visits_Query::VISITS_CACHE_GROUP, absint( apply_filters( 'pvc_visits_object_cache_expire', 300 ) ) );
		}

		return (int) apply_filters( 'pvc_get_visits', (int) $value, $args, $availability );
	}
}

/**
 * Get aggregate post Views per Visit for one derived-eligible range.
 *
 * @since 1.7.16
 *
 * @param array $args Explicit start/end and optional post_type scope.
 *
 * @return float|null Float zero is a stored zero; null means the range is not
 *                    derived-eligible or Visits are unavailable.
 */
if ( ! function_exists( 'pvc_get_views_per_visit' ) ) {
	function pvc_get_views_per_visit( $args ) {
		global $wpdb;

		$args = is_array( $args ) ? $args : [];

		if ( ! empty( $args['post_id'] ) || ( isset( $args['content_type'] ) && $args['content_type'] !== 'post' ) )
			return null;

		$range = Post_Views_Counter_Visits_Query::parse_range( $args );
		$availability = isset( Post_Views_Counter()->visits ) ? Post_Views_Counter()->visits->get_visits_availability( $range ) : [ 'derived_available' => false ];

		if ( $range === null || empty( $availability['derived_available'] ) )
			return null;

		// daily keys are fixed-width Ymd strings, so the (type, period) key serves the range
		$where = [ 'pvc.`type` = 0', 'pvc.`period` BETWEEN %s AND %s' ];
		$params = [ sprintf( '%08d', $range['period_from'] ), sprintf( '%08d', $range['period_to'] ) ];

		if ( pvc_post_views_has_content_column() )
			$where[] = 'pvc.`content` = 0';

		$post_types = isset( $args['post_type'] ) ? array_values( array_filter( array_map( 'sanitize_key', (array) $args['post_type'] ) ) ) : [];
		$join = '';

		if ( ! empty( $post_types ) ) {
			$join = ' INNER JOIN `' . $wpdb->posts . '` posts ON posts.ID = pvc.id';
			$where[] = 'posts.post_type IN (' . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
			$params = array_merge( $params, $post_types );
		}

		$sql = $wpdb->prepare( 'SELECT SUM(pvc.`count`) AS views, SUM(pvc.`visits`) AS visits FROM `' . $wpdb->prefix . 'post_views` pvc' . $join . ' WHERE ' . implode( ' AND ', $where ), $params );
		$cache_key = Post_Views_Counter_Visits_Query::get_read_cache_key( $sql );
		$row = wp_cache_get( $cache_key, Post_Views_Counter_Visits_Query::VIEWS_PER_VISIT_CACHE_GROUP );

		if ( $row === false ) {
			$wpdb->last_error = '';
			$row = $wpdb->get_row( $sql, ARRAY_A );

			if ( $wpdb->last_error !== '' )
				return null;

			$row = is_array( $row ) ? $row : [ 'views' => 0, 'visits' => 0 ];
			wp_cache_add( $cache_key, $row, Post_Views_Counter_Visits_Query::VIEWS_PER_VISIT_CACHE_GROUP, absint( apply_filters( 'pvc_visits_object_cache_expire', 300 ) ) );
		}
		$visits = isset( $row['visits'] ) ? (int) $row['visits'] : 0;

		if ( $visits === 0 )
			return null;

		return (float) apply_filters( 'pvc_get_views_per_visit', (int) $row['views'] / $visits, $args, $availability );
	}
}

/**
 * Display post views for a given post.
 *
 * @param int $post_id
 * @param bool $display
 *
 * @return string|void
 */
if ( ! function_exists( 'pvc_post_views' ) ) {
	function pvc_post_views( $post_id = 0, $display = true, $period = '', $args = [] ) {
		// get all data
		$post_id = (int) ( empty( $post_id ) ? get_the_ID() : $post_id );
		$options = Post_Views_Counter()->options['display'];
		$args = is_array( $args ) ? $args : [];
		$format_present = array_key_exists( 'format', $args );
		$format = $format_present ? $args['format'] : null;
		$resolved_period = is_scalar( $period ) ? sanitize_key( $period !== '' ? (string) $period : $options['display_period'] ) : '';
		$frontend = Post_Views_Counter()->frontend;

		if ( $format_present ) {
			if ( ! is_scalar( $format ) )
				$html = '';
			else {
				// the format places the icon itself with %%icon%%, so the Views token
				// renders the count alone, whatever the Display Style, and resolves no
				// icon it would never draw; its hidden label is left out when the
				// format supplies words of its own
				$views_token_renderer = static function ( $format_has_text = false ) use ( $frontend, $post_id, $resolved_period ) {
					return $frontend->render_metric_span(
						'views',
						$post_id,
						'post',
						$resolved_period,
						[
							'force_sr_label'	=> true,
							'omit_label'		=> (bool) $format_has_text,
							'icon'				=> ''
						]
					);
				};
				$icon_token_renderer = static function () use ( $frontend, $post_id ) {
					return $frontend->get_counter_icon( 'views', $post_id, 'post', 'pvc_post_views_icon' );
				};
				$html = $frontend->render_template( (string) $format, $post_id, 'post', $resolved_period, $views_token_renderer, $icon_token_renderer );
			}
		} else {
			$html = $frontend->render_counter(
				[ 'views' ],
				$post_id,
				'post',
				$resolved_period,
				[
					'metric_args' => [
						'views' => [ 'html_filter' => 'pvc_post_views_html' ]
					]
				]
			);
		}

		if ( $display )
			echo wp_kses( $html, Post_Views_Counter()->functions->get_counter_allowed_html() );
		else
			return $html;
	}
}

/**
 * Get most viewed posts.
 *
 * @param array $args
 *
 * @return array
 */
if ( ! function_exists( 'pvc_get_most_viewed_posts' ) ) {
	function pvc_get_most_viewed_posts( $args = [] ) {
		$args = array_merge(
			[
				'posts_per_page'	=> 10,
				'order'				=> 'desc',
				'post_type'			=> [ 'post' ],
				'post_status'		=> [ 'publish' ],
				'fields'			=> 'all',
				'period'			=> 'total'
			],
			$args
		);

		if ( ( is_array( $args['post_type'] ) && in_array( 'attachment', $args['post_type'], true ) ) || ( is_string( $args['post_type'] ) && $args['post_type'] === 'attachment' ) )
			$args['post_status'][] = 'inherit';

		$args = apply_filters( 'pvc_get_most_viewed_posts_args', $args );

		// force to use filters
		$args['suppress_filters'] = false;

		// force to use post views as order
		$args['orderby'] = 'post_views';

		return apply_filters( 'pvc_get_most_viewed_posts', get_posts( $args ), $args );
	}
}

/**
 * Display a list of most viewed posts.
 *
 * @param array $args
 * @param bool $display
 *
 * @return void|string
 */
if ( ! function_exists( 'pvc_most_viewed_posts' ) ) {
	function pvc_most_viewed_posts( $args = [], $display = true ) {
		$defaults = [
			'number_of_posts'		=> 5,
			'post_type'				=> [ 'post' ],
			'order'					=> 'desc',
			'thumbnail_size'		=> 'thumbnail',
			'list_type'				=> 'unordered',
			'show_post_views'		=> true,
			'show_post_thumbnail'	=> false,
			'show_post_author'		=> false,
			'show_post_excerpt'		=> false,
			'no_posts_message'		=> __( 'No most viewed posts found.', 'post-views-counter' ),
			'item_before'			=> '',
			'item_after'			=> '',
			'period'				=> 'total'
		];

		$args = apply_filters( 'pvc_most_viewed_posts_args', wp_parse_args( $args, $defaults ) );

		// get periods
		$periods = apply_filters( 'pvc_display_period_options', [ 'total' => __( 'Total Views', 'post-views-counter' ) ] );

		// sanitize arguments
		$args['show_post_views'] = (bool) $args['show_post_views'];
		$args['show_post_thumbnail'] = (bool) $args['show_post_thumbnail'];
		$args['show_post_author'] = (bool) $args['show_post_author'];
		$args['show_post_excerpt'] = (bool) $args['show_post_excerpt'];
		$args['period'] = isset( $args['period'] ) && array_key_exists( $args['period'], $periods ) ? $args['period'] : $defaults['period'];
		$args['post_type'] = isset( $args['post_type'] ) ? $args['post_type'] : $defaults['post_type'];

		// no post types?
		if ( empty( $args['post_type'] ) )
			$html = $args['no_posts_message'];
		else {
			// get posts
			$posts = pvc_get_most_viewed_posts( [
				'posts_per_page'	=> isset( $args['number_of_posts'] ) ? (int) $args['number_of_posts'] : $defaults['number_of_posts'],
				'order'				=> isset( $args['order'] ) ? $args['order'] : $defaults['order'],
				'post_type'			=> $args['post_type'],
				'period'			=> $args['period']
			] );

			if ( ! empty( $posts ) ) {
				$html = ( $args['list_type'] === 'unordered' ? '<ul>' : '<ol>' );

				foreach ( $posts as $post ) {
					setup_postdata( $post );

					$html .= '<li>';
					$html .= apply_filters( 'pvc_most_viewed_posts_item_before', $args['item_before'], $post );

					if ( $args['show_post_thumbnail'] && has_post_thumbnail( $post->ID ) ) {
						$html .= '<span class="post-thumbnail">' . get_the_post_thumbnail( $post->ID, $args['thumbnail_size'] ) . '</span>';
					}

					$html .= '<a class="post-title" href="' . get_permalink( $post->ID ) . '">' . get_the_title( $post->ID ) . '</a>' . ( $args['show_post_author'] ? ' <span class="author">(' . get_the_author_meta( 'display_name', $post->post_author ) . ')</span> ' : '' ) . ( $args['show_post_views'] ? ' <span class="count">(' . number_format_i18n( (int) ( property_exists( $post, 'post_views' ) ? $post->post_views : pvc_get_post_views( $post->ID, $args['period'] ) ) ) . ')</span>' : '' );

					if ( $args['show_post_excerpt'] ) {
						$excerpt = '';

						if ( empty( $post->post_excerpt ) )
							$text = $post->post_content;
						else
							$text = $post->post_excerpt;

						if ( ! empty( $text ) )
							$excerpt = wp_trim_words( str_replace( ']]>', ']]&gt;', strip_shortcodes( $text ) ), apply_filters( 'excerpt_length', 55 ), apply_filters( 'excerpt_more', ' ' . '[&hellip;]' ) );

						if ( ! empty( $excerpt ) )
							$html .= '<div class="post-excerpt">' . esc_html( $excerpt ) . '</div>';
					}

					$html .= apply_filters( 'pvc_most_viewed_posts_item_after', $args['item_after'], $post );
					$html .= '</li>';
				}

				wp_reset_postdata();

				$html .= ( $args['list_type'] === 'unordered' ? '</ul>' : '</ol>' );
			} else
				$html = $args['no_posts_message'];
		}

		$html = apply_filters( 'pvc_most_viewed_posts_html', $html, $args );

		if ( $display )
			echo wp_kses_post( $html );
		else
			return $html;
	}
}

/**
 * Update total number of post views for a post.
 *
 * @global object $wpdb
 *
 * @param int $post_id
 * @param int $post_views
 *
 * @return bool|int
 */
function pvc_update_post_views( $post_id = 0, $post_views = 0 ) {
	global $wpdb;

	// cast post ID
	$post_id = (int) $post_id;

	// get post
	$post = get_post( $post_id );

	// check if post exists
	if ( empty( $post ) )
		return false;

	// cast number of views
	$post_views = (int) $post_views;
	$post_views = $post_views < 0 ? 0 : $post_views;

	// change post views?
	$post_views = apply_filters( 'pvc_update_post_views_count', $post_views, $post_id );

	// insert or update database post views count
	$wpdb->query( $wpdb->prepare( "INSERT INTO " . $wpdb->prefix . "post_views (id, type, period, count) VALUES (%d, %d, %s, %d) ON DUPLICATE KEY UPDATE count = %d", $post_id, 4, 'total', $post_views, $post_views ) );

	// query fails only if it returns false
	return apply_filters( 'pvc_update_post_views', $post_id );
}

/**
 * View post manually function.
 *
 * By default this function has limitations. It works properly only between
 * wp_loaded (minimum priority 10) and wp_head (maximum priority 6) actions and
 * it can handle only one function execution per site request.
 *
 * To bypass these limitations there is a $bypass_content argument. It requires
 * JavaScript or REST API as counter mode but it extends the ability to use
 * pvc_view_post up to wp_print_footer_scripts (maximum priority 10) action. It
 * also bypass one function execution limitation to allow multiple function
 * calls during one site request. This also includes the correct saving of
 * cookies.
 *
 * @since 1.2.0
 *
 * @param int $post_id
 * @param bool $bypass_content
 *
 * @return bool
 */
function pvc_view_post( $post_id = 0, $bypass_content = false ) {
	// no post id?
	if ( empty( $post_id ) ) {
		// get current id
		$post_id = get_the_ID();
	} else {
		// cast post id
		$post_id = (int) $post_id;
	}

	// get post
	$post = get_post( $post_id );

	// invalid post?
	if ( ! is_a( $post, 'WP_Post' ) )
		return false;

	// get main instance
	$pvc = Post_Views_Counter();

	if ( $bypass_content )
		$pvc->counter->add_to_queue( $post_id );
	else
		$pvc->counter->check_post( $post_id, [], true );

	return true;
}

/**
 * Convert string to date.
 *
 * @param string $period
 * @return DateTiem object
 */
if ( ! function_exists( 'pvc_period2date' ) ) {
	function pvc_period2date( $period ) {
		$datetime = false;
		
		// check requested period by string length
		$length = is_string( $period ) ? strlen( $period ) : 0;

		if ( $length ) {
			switch ( $length ) {
				// day
				case 8:
					$datetime = date_create_from_format( 'Ymd' , $period );
					break;

				// week
				case 7:
					// get year and week from string
					$period_year = (int) substr( $period, 0, -3 );
					$period_week = (int) substr( $period, 4, -1 );

					$datetime = new DateTime();
					$datetime->setISODate( $period_year, $period_week );
					break;

				// month
				case 6:
					$datetime = date_create_from_format( 'Ymd' , $period . '01' );
					break;
				// year
				case 4:
					$datetime = date_create_from_format( 'Y' , $period );
					break;

				default:
					$datetime = new DateTime();
			}
		}
		
		return apply_filters( 'pvc_period2date', $datetime, $period );
	}
}

/**
* Convert period to timestamp.
*
* @param string $period
* @return int
*/
if ( ! function_exists( 'pvc_period2timestamp' ) ) {
	function pvc_period2timestamp( $period ) {
		$period = preg_replace( '/[^a-z0-9_|]/', '', $period );
		
		// default time
		$timestamp = current_time( 'timestamp', false );
		
		// whitelisted period?
		if ( in_array( $period, [ 'this_week', 'this_year', 'this_month' ], true ) ) {
			$timestamp = current_time( 'timestamp', false );
		// backward compatibility
		} else if ( preg_match( '/^([0-9]{2}\|[0-9]{4})$/', $period ) === 1 ) {
			// month|year
			$date = explode( '|', $period, 2 );

			// get timestamp
			$timestamp = strtotime( (string) $date[1] . '-' . (string) $date[0] . '-13' );
		} else {
			// convert string to DateTime()
			$d = pvc_period2date( $period );
			
			if ( $d ) {
				$timestamp = $d->getTimestamp();
			}
		}

		return $timestamp;
	}
}
