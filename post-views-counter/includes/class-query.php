<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Query class.
 *
 * @class Post_Views_Counter_Query
 */
class Post_Views_Counter_Query {

	private $join_sql = '';

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'pre_get_posts', [ $this, 'extend_pre_query' ], 1 );

		// filters
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_filter( 'posts_join', [ $this, 'posts_join' ], 100, 2 );
		add_filter( 'posts_groupby', [ $this, 'posts_groupby' ], 10, 2 );
		add_filter( 'posts_orderby', [ $this, 'posts_orderby' ], 10, 2 );
		add_filter( 'posts_distinct', [ $this, 'posts_distinct' ], 10, 2 );
		add_filter( 'posts_fields', [ $this, 'posts_fields' ], 10, 2 );
		add_filter( 'the_posts', [ $this, 'the_posts' ], 10, 2 );
	}

	/**
	 * Register views_query var.
	 *
	 * @param array $query_vars
	 * @return array
	 */
	public function query_vars( $query_vars ) {
		$query_vars[] = 'views_query';

		return $query_vars;
	}

	/**
	 * Extend query with post_views orderby parameter.
	 *
	 * @param object $query
	 * @return void
	 */
	public function extend_pre_query( $query ) {
		$this->join_sql = '';
		$query->pvc_orderby = false;
		$query->pvc_query = false;
		$query->total_views = 0;

		$orderby = isset( $query->query_vars['orderby'] ) ? $query->query_vars['orderby'] : '';
		$has_orderby = function( $metric ) use ( $orderby ) {
			if ( is_array( $orderby ) )
				return array_key_exists( $metric, $orderby );

			return is_string( $orderby ) && preg_match( '/(?:^|\s)' . preg_quote( $metric, '/' ) . '(?:\s|$)/', $orderby ) === 1;
		};
		$query->pvc_orderby = $has_orderby( 'post_views' );

		// skip empty sort order
		if ( empty( $query->query_vars['orderby'] ) )
			return;

		if ( is_string( $query->query_vars['orderby'] ) ) {
			// simple order by post_views
			if ( $query->query_vars['orderby'] === 'post_views' )
				$query->pvc_orderby = true;
			// multisort post_views as string
			elseif ( strpos( $query->query_vars['orderby'], 'post_views' ) !== false ) {
				// explode orderby
				$sort = explode( ' ', $query->query_vars['orderby'] );

				// make sure only full string is available
				if ( ! empty( $sort ) ) {
					// clear it
					$sort = array_filter( $sort );

					if ( in_array( 'post_views', $sort, true ) )
						$query->pvc_orderby = true;
				}
			}
		// post_views in array
		} elseif ( is_array( $query->query_vars['orderby'] ) && array_key_exists( 'post_views', $query->query_vars['orderby'] ) )
			$query->pvc_orderby = true;
	}

	/**
	 * Modify the database query to use post_views parameter.
	 *
	 * @global object $wpdb
	 *
	 * @param string $join
	 * @param object $query
	 * @return string
	 */
	public function posts_join( $join, $query ) {
		$sql = '';
		$query_chunks = [];

		// views query?
		if ( ! empty( $query->query['views_query'] ) ) {
			if ( isset( $query->query['views_query']['inclusive'] ) )
				$query->query['views_query']['inclusive'] = (bool) $query->query['views_query']['inclusive'];
			else
				$query->query['views_query']['inclusive'] = true;

			// check after and before dates
			foreach ( [ 'after' => '>', 'before' => '<' ] as $date => $type ) {
				$year_ = null;
				$month_ = null;
				$week_ = null;
				$day_ = null;

				// check views query date
				if ( ! empty( $query->query['views_query'][$date] ) ) {
					// is it a date array?
					if ( is_array( $query->query['views_query'][$date] ) ) {
						// check views query $date date year
						if ( ! empty( $query->query['views_query'][$date]['year'] ) )
							$year_ = str_pad( (int) $query->query['views_query'][$date]['year'], 4, 0, STR_PAD_LEFT );

						// check views query date month
						if ( ! empty( $query->query['views_query'][$date]['month'] ) )
							$month_ = str_pad( (int) $query->query['views_query'][$date]['month'], 2, 0, STR_PAD_LEFT );

						// check views query date week
						if ( ! empty( $query->query['views_query'][$date]['week'] ) )
							$week_ = str_pad( (int) $query->query['views_query'][$date]['week'], 2, 0, STR_PAD_LEFT );

						// check views query date day
						if ( ! empty( $query->query['views_query'][$date]['day'] ) )
							$day_ = str_pad( (int) $query->query['views_query'][$date]['day'], 2, 0, STR_PAD_LEFT );
					// is it a date string?
					} elseif ( is_string( $query->query['views_query'][$date] ) ) {
						$time_ = strtotime( $query->query['views_query'][$date] );

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
							'type'	=> $type . ( $query->query['views_query']['inclusive'] ? '=' : '' )
						];
					}
				}
			}

			// any after, before query chunks?
			if ( ! empty( $query_chunks ) ) {
				$valid_dates = true;

				// check only if both dates are in query
				if ( count( $query_chunks ) === 2 ) {
					// before and after dates should be the same
					foreach ( [ 'year', 'month', 'day', 'week' ] as $date_type ) {
						if ( ! ( ( $query_chunks[0][$date_type] !== null && $query_chunks[1][$date_type] !== null ) || ( $query_chunks[0][$date_type] === null && $query_chunks[1][$date_type] === null ) ) )
							$valid_dates = false;
					}
				}

				// after and before dates should be both valid
				if ( $valid_dates ) {
					foreach ( $query_chunks as $chunk ) {
						// year
						if ( isset( $chunk['year'] ) ) {
							// year, week
							if ( isset( $chunk['week'] ) )
								$sql .= " AND pvc.type = 1 AND pvc.period " . $chunk['type'] . " '" . $chunk['year'] . $chunk['week'] . "'";
							// year, month
							elseif ( isset( $chunk['month'] ) ) {
								// year, month, day
								if ( isset( $chunk['day'] ) )
									$sql .= " AND pvc.type = 0 AND pvc.period " . $chunk['type'] . " '" . $chunk['year'] . $chunk['month'] . $chunk['day'] . "'";
								// year, month
								else
									$sql .= " AND pvc.type = 2 AND pvc.period " . $chunk['type'] . " '" . $chunk['year'] . $chunk['month'] . "'";
							// year
							} else
								$sql .= " AND pvc.type = 3 AND pvc.period " . $chunk['type'] . " '" . $chunk['year'] . "'";
						// month
						} elseif ( isset( $chunk['month'] ) ) {
							// month, day
							if ( isset( $chunk['day'] ) )
								$sql .= " AND pvc.type = 0 AND RIGHT( pvc.period, 4 ) " . $chunk['type'] . " '" . $chunk['month'] . $chunk['day'] . "'";
							// month
							else
								$sql .= " AND pvc.type = 2 AND RIGHT( pvc.period, 2 ) " . $chunk['type'] . " '" . $chunk['month'] . "'";
						// week
						} elseif ( isset( $chunk['week'] ) )
							$sql .= " AND pvc.type = 1 AND RIGHT( pvc.period, 2 ) " . $chunk['type'] . " '" . $chunk['week'] . "'";
						// day
						elseif ( isset( $chunk['day'] ) )
							$sql .= " AND pvc.type = 0 AND RIGHT( pvc.period, 2 ) " . $chunk['type'] . " '" . $chunk['day'] . "'";
					}
				}
			}

			// standard query
			if ( $sql === '' ) {
				// check year
				if ( isset( $query->query['views_query']['year'] ) )
					$year = (int) $query->query['views_query']['year'];

				// check month
				if ( isset( $query->query['views_query']['month'] ) )
					$month = (int) $query->query['views_query']['month'];

				// check week
				if ( isset( $query->query['views_query']['week'] ) )
					$week = (int) $query->query['views_query']['week'];

				// check day
				if ( isset( $query->query['views_query']['day'] ) )
					$day = (int) $query->query['views_query']['day'];

				// year
				if ( isset( $year ) ) {
					// year, week
					if ( isset( $week ) && $this->is_date_valid( 'yw', $year, 0, 0, $week ) )
						$sql = " AND pvc.type = 1 AND pvc.period = '" . str_pad( $year, 4, 0, STR_PAD_LEFT ) . str_pad( $week, 2, 0, STR_PAD_LEFT ) . "'";
					// year, month
					elseif ( isset( $month ) ) {
						// year, month, day
						if ( isset( $day ) && $this->is_date_valid( 'ymd', $year, $month, $day ) )
							$sql = " AND pvc.type = 0 AND pvc.period = '" . str_pad( $year, 4, 0, STR_PAD_LEFT ) . str_pad( $month, 2, 0, STR_PAD_LEFT ) . str_pad( $day, 2, 0, STR_PAD_LEFT ) . "'";
						// year, month
						elseif ( $this->is_date_valid( 'ym', $year, $month ) )
							$sql = " AND pvc.type = 2 AND pvc.period = '" . str_pad( $year, 4, 0, STR_PAD_LEFT ) . str_pad( $month, 2, 0, STR_PAD_LEFT ) . "'";
					// year
					} elseif ( $this->is_date_valid( 'y', $year ) )
						$sql = " AND pvc.type = 3 AND pvc.period = '" . str_pad( $year, 4, 0, STR_PAD_LEFT ) . "'";
				// month
				} elseif ( isset( $month ) ) {
					// month, day
					if ( isset( $day ) && $this->is_date_valid( 'md', 0, $month, $day ) ) {
						$sql = " AND pvc.type = 0 AND RIGHT( pvc.period, 4 ) = '" . str_pad( $month, 2, 0, STR_PAD_LEFT ) . str_pad( $day, 2, 0, STR_PAD_LEFT ) . "'";
					// month
					} elseif ( $this->is_date_valid( 'm', 0, $month ) )
						$sql = " AND pvc.type = 2 AND RIGHT( pvc.period, 2 ) = '" . str_pad( $month, 2, 0, STR_PAD_LEFT ) . "'";
				// week
				} elseif ( isset( $week ) && $this->is_date_valid( 'w', 0, 0, 0, $week ) )
					$sql = " AND pvc.type = 1 AND RIGHT( pvc.period, 2 ) = '" . str_pad( $week, 2, 0, STR_PAD_LEFT ) . "'";
				// day
				elseif ( isset( $day ) && $this->is_date_valid( 'd', 0, 0, $day ) )
					$sql = " AND pvc.type = 0 AND RIGHT( pvc.period, 2 ) = '" . str_pad( $day, 2, 0, STR_PAD_LEFT ) . "'";
			}

			if ( $sql !== '' )
				$query->pvc_query = true;
		}

		// is it sorted by post views?
		if ( ( $sql === '' && ! empty( $query->pvc_orderby ) ) || apply_filters( 'pvc_extend_post_object', false, $query ) === true )
			$sql = ' AND pvc.type = 4';

		// add date range
		if ( $sql !== '' ) {
			global $wpdb;

			$content_sql = pvc_post_views_has_content_column() ? ' AND pvc.content = 0' : '';
			$join .= " LEFT JOIN " . $wpdb->prefix . "post_views pvc ON pvc.id = " . $wpdb->prefix . "posts.ID" . $content_sql . $sql;

			$this->join_sql = $join;
		}

		return $join;
	}

	/**
	 * Group posts using the post ID.
	 *
	 * @global object $wpdb
	 * @global string $pagenow
	 *
	 * @param string $groupby
	 * @param object $query
	 * @return string
	 */
	public function posts_groupby( $groupby, $query ) {
		// is it sorted by post views or views_query is used?
		if ( ! empty( $query->pvc_orderby ) || ! empty( $query->pvc_query ) || apply_filters( 'pvc_extend_post_object', false, $query ) === true ) {
			global $pagenow;

			// needed only for sorting
			if ( $pagenow === 'upload.php' || $pagenow === 'edit.php' )
				$query->query['views_query']['hide_empty'] = false;

			global $wpdb;

			$groupby = trim( $groupby );
			$groupby_aliases = [];
			$groupby_values = [];
			$groupby_sql = '';
			$groupby_set = false;

			// standard group by
			if ( strpos( $groupby, $wpdb->prefix . 'posts.ID' ) === false )
				$groupby_aliases[] = $wpdb->prefix . 'posts.ID';
			else
				$groupby_set = true;

			// tax query group by
			$groupby_aliases[] = $this->get_groupby_meta_aliases( $query );

			// meta query group by
			if ( $this->join_sql ) {
				$groupby_aliases[] = $this->get_groupby_tax_aliases( $query, $this->join_sql );

				// clear join to avoid possible issues
				$this->join_sql = '';
			}

			// any group by aliases?
			if ( ! empty( $groupby_aliases ) ) {
				foreach ( $groupby_aliases as $alias ) {
					if ( is_array( $alias ) ) {
						$groupby_values = array_merge( $groupby_values, $alias );
					} else
						$groupby_values[] = $alias;
				}
			}

			// any group by values?
			if ( ! empty( $groupby_values ) ) {
				$groupby = ( $groupby !== '' ? $groupby . ', ' : '' ) . implode( ', ', $groupby_values );

				// set group by flag
				$groupby_set = true;
			}

			if ( $groupby_set )
				$query->pvc_groupby = true;

			// hide empty?
			$views_query = isset( $query->query['views_query'] ) ? $query->query['views_query'] : [];

			if ( ! isset( $views_query['hide_empty'] ) || $views_query['hide_empty'] === true )
				$groupby .= ' HAVING SUM( COALESCE( pvc.count, 0 ) ) > 0';
		}

		return $groupby;
	}

	/**
	 * Order posts by post views.
	 *
	 * @param string $orderby
	 * @param object $query
	 * @return string
	 */
	public function posts_orderby( $orderby, $query ) {
		global $wpdb;

		if ( empty( $query->pvc_orderby ) )
			return $orderby;

		$requested = $query->get( 'orderby' );
		$default_order = strtoupper( (string) $query->get( 'order' ) );

		if ( ! in_array( $default_order, [ 'ASC', 'DESC' ], true ) )
			$default_order = 'DESC';

		$tokens = $this->get_metric_orderby_tokens( $requested, $default_order );
		$nonmetric = $this->split_top_level_orderby( $orderby );
		$translated = [];

		foreach ( $tokens as $token ) {
			if ( $token['metric'] === 'post_views' && ! empty( $query->pvc_orderby ) )
				$translated[] = 'SUM( COALESCE( pvc.count, 0 ) ) ' . $token['order'] . ( isset( $query->pvc_admin_column_sort ) && $query->pvc_admin_column_sort === 'views' ? ', ' . $wpdb->posts . '.ID ASC' : '' );
			elseif ( $this->did_wordpress_orderby_token_emit_clause( $token['metric'], $query ) && ! empty( $nonmetric ) )
				$translated[] = array_shift( $nonmetric );
		}

		return implode( ', ', array_merge( $translated, $nonmetric ) );
	}

	/**
	 * Split rendered ORDER BY SQL at commas outside function calls and quoted
	 * strings. WordPress uses FIELD( ... ) for the inclusion-order tokens.
	 *
	 * @param mixed $orderby Rendered ORDER BY SQL.
	 * @return array
	 */
	private function split_top_level_orderby( $orderby ) {
		if ( ! is_string( $orderby ) || trim( $orderby ) === '' )
			return [];

		$clauses = [];
		$start = 0;
		$depth = 0;
		$quote = '';
		$length = strlen( $orderby );

		for ( $index = 0; $index < $length; $index++ ) {
			$character = $orderby[$index];

			if ( $quote !== '' ) {
				if ( $character === $quote && ( $index === 0 || $orderby[$index - 1] !== '\\' ) )
					$quote = '';
				continue;
			}

			if ( $character === "'" || $character === '"' || $character === '`' )
				$quote = $character;
			elseif ( $character === '(' )
				$depth++;
			elseif ( $character === ')' && $depth > 0 )
				$depth--;
			elseif ( $character === ',' && $depth === 0 ) {
				$clause = trim( substr( $orderby, $start, $index - $start ) );
				if ( $clause !== '' )
					$clauses[] = $clause;
				$start = $index + 1;
			}
		}

		$clause = trim( substr( $orderby, $start ) );
		if ( $clause !== '' )
			$clauses[] = $clause;

		return $clauses;
	}

	/**
	 * Check whether a requested token emitted one rendered WordPress clause.
	 *
	 * This mirrors the supported WP 6.3 parse_orderby() branches. Requested
	 * inclusion and meta tokens are not necessarily rendered, so they must not
	 * consume a later clause while metric ordering is reconstructed.
	 *
	 * @param string $token Requested orderby token.
	 * @param object $query WP_Query instance.
	 * @return bool
	 */
	private function did_wordpress_orderby_token_emit_clause( $token, $query ) {
		$token = (string) $token;
		$meta_clauses = is_object( $query->meta_query ) && method_exists( $query->meta_query, 'get_clauses' ) ? $query->meta_query->get_clauses() : [];
		$meta_clauses = is_array( $meta_clauses ) ? $meta_clauses : [];
		$primary_meta_clause = empty( $meta_clauses ) ? [] : reset( $meta_clauses );
		$primary_meta_key = is_array( $primary_meta_clause ) && ! empty( $primary_meta_clause['key'] ) ? (string) $primary_meta_clause['key'] : '';

		switch ( $token ) {
			// WP_Query::parse_orderby() renders these direct fields and aliases.
			case 'post_name':
			case 'post_author':
			case 'post_date':
			case 'post_title':
			case 'post_modified':
			case 'post_parent':
			case 'post_type':
			case 'name':
			case 'author':
			case 'date':
			case 'title':
			case 'modified':
			case 'parent':
			case 'type':
			case 'ID':
			case 'menu_order':
			case 'comment_count':
			case 'rand':
				return true;

			case 'post__in':
				return ! empty( $query->get( 'post__in' ) );

			case 'post_name__in':
				return ! empty( $query->get( 'post_name__in' ) );

			case 'post_parent__in':
				return ! empty( $query->get( 'post_parent__in' ) );

			case 'meta_value':
			case 'meta_value_num':
				return ! empty( $meta_clauses );

			case 'relevance':
				// Core only emits search relevance for the standalone string token.
				return ! empty( $query->get( 's' ) ) && $query->get( 'orderby' ) === 'relevance';
		}

		if ( $primary_meta_key !== '' && $token === $primary_meta_key )
			return true;

		if ( array_key_exists( $token, $meta_clauses ) )
			return true;

		return preg_match( '/^RAND\([0-9]+\)$/i', $token ) === 1;
	}

	/**
	 * Preserve metric tokens at their original WP_Query orderby positions.
	 *
	 * @param mixed  $orderby Requested orderby value.
	 * @param string $default_order Validated query-wide direction.
	 * @return array
	 */
	private function get_metric_orderby_tokens( $orderby, $default_order ) {
		$tokens = [];

		if ( is_array( $orderby ) ) {
			foreach ( $orderby as $key => $direction ) {
				// a non-scalar direction (orderby[title][]=x) takes the default order
				$order = is_scalar( $direction ) ? strtoupper( (string) $direction ) : $default_order;
				$tokens[] = [
					'metric' => (string) $key,
					'order' => in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : $default_order
				];
			}

			return $tokens;
		}

		$parts = preg_split( '/\s+/', trim( (string) $orderby ) );

		for ( $index = 0; $index < count( $parts ); $index++ ) {
			$order = $default_order;

			if ( isset( $parts[$index + 1] ) && in_array( strtoupper( $parts[$index + 1] ), [ 'ASC', 'DESC' ], true ) )
				$order = strtoupper( $parts[++$index] );

			$tokens[] = [ 'metric' => $parts[$index], 'order' => $order ];
		}

		return $tokens;
	}

	/**
	 * Add DISTINCT clause.
	 *
	 * @param string $distinct
	 * @param object $query
	 * @return string
	 */
	public function posts_distinct( $distinct, $query ) {
		if ( ( ! empty( $query->pvc_groupby ) || ! empty( $query->pvc_orderby ) || ! empty( $query->pvc_query ) || apply_filters( 'pvc_extend_post_object', false, $query ) === true ) && strpos( $distinct, 'DISTINCT' ) === false )
			$distinct = $distinct . ' DISTINCT ';

		return $distinct;
	}

	/**
	 * Return post views in queried post objects.
	 *
	 * @param string $fields
	 * @param object $query
	 * @return string
	 */
	public function posts_fields( $fields, $query ) {
		if ( ! isset( $query->query['fields'] ) || $query->query['fields'] === '' || $query->query['fields'] === 'all' ) {
			if ( ! empty( $query->pvc_orderby ) || ! empty( $query->pvc_query ) || apply_filters( 'pvc_extend_post_object', false, $query ) === true )
				$fields .= ', SUM( COALESCE( pvc.count, 0 ) ) AS post_views';
		}

		return $fields;
	}

	/**
	 * Get tax table aliases from query.
	 *
	 * @global object $wpdb
	 *
	 * @param object $query
	 * @param string $join_sql
	 * @return array
	 */
	private function get_groupby_tax_aliases( $query, $join_sql ) {
		global $wpdb;

		$groupby = [];

		// trim join sql
		$join_sql = trim( $join_sql );

		// any join sql? valid query with tax query?
		if ( $join_sql !== '' && is_a( $query, 'WP_Query' ) && ! empty( $query->tax_query ) && is_a( $query->tax_query, 'WP_Tax_Query' ) ) {
			// unfortunately there is no way to get table_aliases by native function
			// tax query does not have get_clauses either like meta query does
			// we have to find aliases the hard way
			$chunks = explode( 'JOIN', $join_sql );

			// any join clauses?
			if ( ! empty( $chunks ) ) {
				$aliases = [];

				foreach ( $chunks as $chunk ) {
					// standard join
					if ( strpos( $chunk, $wpdb->prefix . 'term_relationships ON' ) !== false )
						$aliases[] = $wpdb->prefix . 'term_relationships';
					// alias join
					elseif ( strpos( $chunk, $wpdb->prefix . 'term_relationships AS' ) !== false && preg_match( '/' . $wpdb->prefix . 'term_relationships AS ([a-z0-9]+) ON/i', $chunk, $matches ) === 1 )
						$aliases[] = $matches[1];
				}

				// any aliases?
				if ( ! empty( $aliases ) ) {
					foreach ( array_unique( $aliases ) as $alias ) {
						$groupby[] = $alias . '.term_taxonomy_id';
					}
				}
			}
		}

		return $groupby;
	}

	/**
	 * Get meta table aliases from query.
	 *
	 * @param object $query
	 * @return array
	 */
	private function get_groupby_meta_aliases( $query ) {
		$groupby = [];

		// valid query with meta query?
		if ( is_a( $query, 'WP_Query' ) && ! empty( $query->meta_query ) && is_a( $query->meta_query, 'WP_Meta_Query' ) ) {
			// get meta clauses, we can't use table_aliases here since it's protected value
			$clauses = $query->meta_query->get_clauses();

			// any meta clauses?
			if ( ! empty( $clauses ) ) {
				$aliases = [];

				foreach ( $clauses as $clause ) {
					$aliases[] = $clause['alias'];
				}

				// any aliases?
				if ( ! empty( $aliases ) ) {
					foreach ( array_unique( $aliases ) as $alias ) {
						$groupby[] = $alias . '.meta_id';
					}
				}
			}
		}

		return $groupby;
	}

	/**
	 * Extend query object with total post views.
	 *
	 * @param array $posts
	 * @param object $query
	 * @return array
	 */
	public function the_posts( $posts, $query ) {
		if ( ! empty( $query->pvc_orderby ) || ! empty( $query->pvc_query ) || apply_filters( 'pvc_extend_post_object', false, $query ) === true ) {
			$sum = 0;

			// any posts found?
			if ( ! empty( $posts ) ) {
				foreach ( $posts as $post ) {
					if ( is_object( $post ) && ! empty( $post->post_views ) )
						$sum += (int) $post->post_views;
				}
			}

			// pass total views
			$query->total_views = $sum;
		}

		return $posts;
	}

	/**
	 * Check whether date is valid.
	 *
	 * @param string $type
	 * @param int $year
	 * @param int $month
	 * @param int $day
	 * @param int $week
	 * @return bool
	 */
	private function is_date_valid( $type, $year = 0, $month = 0, $day = 0, $week = 0 ) {
		switch ( $type ) {
			case 'y':
				$bool = ( $year >= 1 && $year <= 32767 );
				break;

			case 'yw':
				$bool = ( $year >= 1 && $year <= 32767 && $week >= 0 && $week <= 53 );
				break;

			case 'ym':
				$bool = ( $year >= 1 && $year <= 32767 && $month >= 1 && $month <= 12 );
				break;

			case 'ymd':
				$bool = checkdate( $month, $day, $year );
				break;

			case 'm':
				$bool = ( $month >= 1 && $month <= 12 );
				break;

			case 'md':
				$bool = ( $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 );
				break;

			case 'w':
				$bool = ( $week >= 0 && $week <= 53 );
				break;

			case 'd':
				$bool = ( $day >= 1 && $day <= 31 );
				break;
		}

		return $bool;
	}
}
