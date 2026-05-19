<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Emails_Query class.
 *
 * @class Post_Views_Counter_Emails_Query
 */
class Post_Views_Counter_Emails_Query {

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
	 * Get summary data for a supported cadence.
	 *
	 * @param string $summary_type
	 * @param array|null $period
	 * @param array $args
	 * @return array
	 */
	public function get_summary_data( $summary_type = 'weekly', $period = null, $args = [] ) {
		$summary_type = Post_Views_Counter_Emails_Period::normalize_summary_type_key( $summary_type );
		$period = $this->normalize_period( $summary_type, $period );
		$settings = $this->get_settings();
		$query_args = $this->build_query_args( $args, $period, $settings );
		$cache_key = $this->get_cache_key( $period, $query_args );
		$data = wp_cache_get( $cache_key, 'post_views_counter' );

		if ( $data === false ) {
			$data = $this->build_summary_data( $period, $query_args );
			$ttl = absint( apply_filters( 'pvc_email_summary_cache_ttl', 300, $query_args, $period, $this ) );

			wp_cache_set( $cache_key, $data, 'post_views_counter', $ttl );
		}

		return apply_filters( 'pvc_email_summary_data', $data, $period, $query_args, $this );
	}

	/**
	 * Get weekly summary data.
	 *
	 * @param array|null $period
	 * @param array $args
	 * @return array
	 */
	public function get_weekly_summary_data( $period = null, $args = [] ) {
		return $this->get_summary_data( 'weekly', $period, $args );
	}

	/**
	 * Normalize the requested period.
	 *
	 * @param array|null $period
	 * @return array
	 */
	private function normalize_period( $summary_type = 'weekly', $period = null ) {
		$default_period = ( new Post_Views_Counter_Emails_Period() )->get_period( $summary_type );

		if ( ! is_array( $period ) )
			return $default_period;

		return array_merge( $default_period, $period );
	}

	/**
	 * Get normalized email settings.
	 *
	 * @return array
	 */
	private function get_settings() {
		$stored = isset( $this->pvc->options['emails'] ) && is_array( $this->pvc->options['emails'] ) ? $this->pvc->options['emails'] : [];

		return array_merge( $this->pvc->defaults['emails'], $stored );
	}

	/**
	 * Build query arguments.
	 *
	 * @param array $args
	 * @param array $period
	 * @param array $settings
	 * @return array
	 */
	private function build_query_args( $args, $period, $settings ) {
		$defaults = [
			'post_types'			=> $this->get_post_types( $settings ),
			'limit'					=> min( 10, max( 3, (int) $settings['max_top_items'] ) ),
			'threshold'			=> max( 0, (int) $settings['min_views_threshold'] ),
			'send_empty_reports'	=> ! empty( $settings['send_empty_reports'] )
		];

		if ( ! is_array( $args ) )
			$args = [];

		$query_args = wp_parse_args( $args, $defaults );
		$query_args = apply_filters( 'pvc_email_summary_query_args', $query_args, $period, $settings, $this );
		$query_args['post_types'] = $this->sanitize_post_types( isset( $query_args['post_types'] ) ? $query_args['post_types'] : [] );
		$query_args['limit'] = min( 10, max( 3, (int) ( isset( $query_args['limit'] ) ? $query_args['limit'] : 5 ) ) );
		$query_args['threshold'] = max( 0, (int) ( isset( $query_args['threshold'] ) ? $query_args['threshold'] : 25 ) );
		$query_args['send_empty_reports'] = ! empty( $query_args['send_empty_reports'] );

		return $query_args;
	}

	/**
	 * Resolve post types from settings.
	 *
	 * @param array $settings
	 * @return array
	 */
	private function get_post_types( $settings ) {
		$post_types = [];

		if ( ! empty( $settings['include_post_types'] ) && is_array( $settings['include_post_types'] ) )
			$post_types = $settings['include_post_types'];
		elseif ( isset( $this->pvc->options['general']['post_types_count'] ) && is_array( $this->pvc->options['general']['post_types_count'] ) )
			$post_types = $this->pvc->options['general']['post_types_count'];

		return $this->sanitize_post_types( $post_types );
	}

	/**
	 * Sanitize post type slugs.
	 *
	 * @param array|string $post_types
	 * @return array
	 */
	private function sanitize_post_types( $post_types ) {
		if ( is_string( $post_types ) )
			$post_types = [ $post_types ];

		if ( ! is_array( $post_types ) )
			return [];

		$sanitized = [];

		foreach ( $post_types as $post_type ) {
			$post_type = sanitize_key( $post_type );

			if ( $post_type !== '' && post_type_exists( $post_type ) )
				$sanitized[] = $post_type;
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Build the cache key for summary data.
	 *
	 * @param array $period
	 * @param array $query_args
	 * @return string
	 */
	private function get_cache_key( $period, $query_args ) {
		$cadence = Post_Views_Counter_Emails_Period::normalize_summary_type_key( isset( $period['cadence'] ) ? $period['cadence'] : 'weekly' );

		$cache_context = [
			'blog_id'			=> get_current_blog_id(),
			'cadence'			=> $cadence,
			'start_period'		=> $period['start_period'],
			'end_period'		=> $period['end_period'],
			'comparison_start_period' => $period['comparison_start_period'],
			'comparison_end_period' => $period['comparison_end_period'],
			'post_types'		=> $query_args['post_types'],
			'limit'				=> $query_args['limit'],
			'threshold'			=> $query_args['threshold'],
			'send_empty_reports'	=> $query_args['send_empty_reports']
		];

		return 'pvc_email_summary_query_' . md5( serialize( $cache_context ) );
	}

	/**
	 * Build normalized summary data.
	 *
	 * @param array $period
	 * @param array $query_args
	 * @return array
	 */
	private function build_summary_data( $period, $query_args ) {
		$cadence = Post_Views_Counter_Emails_Period::normalize_summary_type_key( isset( $period['cadence'] ) ? $period['cadence'] : 'weekly' );

		if ( empty( $query_args['post_types'] ) )
			return $this->build_empty_summary_data( $period, $query_args, 'no_post_types' );

		$current = $this->query_period_overview( $period['start_period'], $period['end_period'], $query_args['post_types'] );
		$previous = $this->query_period_overview( $period['comparison_start_period'], $period['comparison_end_period'], $query_args['post_types'] );
		$top_items = $this->query_top_content( $period['start_period'], $period['end_period'], $query_args['post_types'], $query_args['limit'] );
		$previous_views = [];

		if ( ! empty( $top_items ) )
			$previous_views = $this->query_previous_views_map( $period['comparison_start_period'], $period['comparison_end_period'], $query_args['post_types'], wp_list_pluck( $top_items, 'post_id' ) );

		$overview_trend = $this->build_trend_data( $current['total_views'], $previous['total_views'] );
		$threshold_met = (int) $current['total_views'] >= (int) $query_args['threshold'];
		$should_send = $threshold_met || $query_args['send_empty_reports'];
		$top_content = $this->build_top_content_data( $top_items, $previous_views );

		$overview = [
			'total_views'			=> (int) $current['total_views'],
			'previous_total_views'	=> (int) $previous['total_views'],
			'views_change'			=> $overview_trend['views_change'],
			'views_change_percent'	=> $overview_trend['views_change_percent'],
			'trend_reliable'		=> $overview_trend['trend_reliable'],
			'trend_status'			=> $overview_trend['trend_status'],
			'threshold_met'			=> $threshold_met,
			'should_send'			=> $should_send,
			'viewed_content_count'	=> (int) $current['viewed_content_count']
		];

		return [
			'type'			=> $cadence,
			'cadence'		=> $cadence,
			'period'		=> $period,
			'query'			=> [
				'post_types'			=> $query_args['post_types'],
				'limit'					=> $query_args['limit'],
				'threshold'			=> $query_args['threshold'],
				'send_empty_reports'	=> $query_args['send_empty_reports']
			],
			'overview'		=> $overview,
			'top_content'	=> $top_content,
			'traffic_signals' => $this->build_traffic_signals_data( $overview, $top_content ),
			'status'		=> $this->build_status_data( $overview, ! empty( $top_content ) )
		];
	}

	/**
	 * Build an empty summary response.
	 *
	 * @param array $period
	 * @param array $query_args
	 * @param string $reason
	 * @return array
	 */
	private function build_empty_summary_data( $period, $query_args, $reason = 'no_data' ) {
		$cadence = Post_Views_Counter_Emails_Period::normalize_summary_type_key( isset( $period['cadence'] ) ? $period['cadence'] : 'weekly' );
		$should_send = $reason === 'no_post_types' ? false : $query_args['send_empty_reports'];

		return [
			'type'			=> $cadence,
			'cadence'		=> $cadence,
			'period'		=> $period,
			'query'			=> [
				'post_types'			=> $query_args['post_types'],
				'limit'					=> $query_args['limit'],
				'threshold'			=> $query_args['threshold'],
				'send_empty_reports'	=> $query_args['send_empty_reports']
			],
			'overview'		=> [
				'total_views'			=> 0,
				'previous_total_views'	=> 0,
				'views_change'			=> 0,
				'views_change_percent'	=> null,
				'trend_reliable'		=> false,
				'trend_status'			=> 'neutral',
				'threshold_met'			=> false,
				'should_send'			=> $should_send,
				'viewed_content_count'	=> 0
			],
			'top_content'	=> [],
			'traffic_signals' => [
				'state'			=> 'silence',
				'post_id'		=> 0,
				'message_key'	=> $reason === 'no_post_types' ? 'no_visible_post_types' : 'no_data'
			],
			'status'		=> [
				'empty'			=> true,
				'reason'		=> $reason
			]
		];
	}

	/**
	 * Query total views and viewed content count for a period.
	 *
	 * @global object $wpdb
	 * @param string|int $start_period
	 * @param string|int $end_period
	 * @param array $post_types
	 * @return array
	 */
	private function query_period_overview( $start_period, $end_period, $post_types ) {
		global $wpdb;

		$post_type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$period_query = $this->get_period_query_parts( $start_period, $end_period );
		$params = array_merge( [ 0 ], $period_query['params'], [ 'publish', '' ], $post_types );
		$query = $wpdb->prepare(
			' SELECT COALESCE( SUM( summary.period_views ), 0 ) AS total_views, COUNT( summary.post_id ) AS viewed_content_count
			FROM (
				SELECT pv.id AS post_id, SUM( pv.count ) AS period_views
				FROM ' . $wpdb->prefix . 'post_views AS pv
				INNER JOIN ' . $wpdb->posts . ' AS p ON pv.id = p.ID
				WHERE pv.type = %d
					AND ' . $period_query['clause'] . '
					AND p.post_status = %s
					AND p.post_password = %s
					AND p.post_type IN (' . $post_type_placeholders . ')
				GROUP BY pv.id
			) AS summary',
			$params
		);

		$row = $wpdb->get_row( $query, ARRAY_A );

		return [
			'total_views'			=> isset( $row['total_views'] ) ? (int) $row['total_views'] : 0,
			'viewed_content_count'	=> isset( $row['viewed_content_count'] ) ? (int) $row['viewed_content_count'] : 0
		];
	}

	/**
	 * Query top content for the current period.
	 *
	 * @global object $wpdb
	 * @param string|int $start_period
	 * @param string|int $end_period
	 * @param array $post_types
	 * @param int $limit
	 * @return array
	 */
	private function query_top_content( $start_period, $end_period, $post_types, $limit ) {
		global $wpdb;

		$post_type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$period_query = $this->get_period_query_parts( $start_period, $end_period );
		$params = array_merge( [ 0 ], $period_query['params'], [ 'publish', '' ], $post_types, [ (int) $limit ] );
		$query = $wpdb->prepare(
			' SELECT pv.id AS post_id, p.post_title AS post_title, SUM( pv.count ) AS current_views
			FROM ' . $wpdb->prefix . 'post_views AS pv
			INNER JOIN ' . $wpdb->posts . ' AS p ON pv.id = p.ID
			WHERE pv.type = %d
				AND ' . $period_query['clause'] . '
				AND p.post_status = %s
				AND p.post_password = %s
				AND p.post_type IN (' . $post_type_placeholders . ')
			GROUP BY pv.id, p.post_title
			ORDER BY current_views DESC, pv.id ASC
			LIMIT %d',
			$params
		);

		$items = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $items ) ? $items : [];
	}

	/**
	 * Query previous-period views for a list of posts.
	 *
	 * @global object $wpdb
	 * @param string|int $start_period
	 * @param string|int $end_period
	 * @param array $post_types
	 * @param array $post_ids
	 * @return array
	 */
	private function query_previous_views_map( $start_period, $end_period, $post_types, $post_ids ) {
		global $wpdb;

		$post_ids = array_filter( array_map( 'intval', (array) $post_ids ) );

		if ( empty( $post_ids ) )
			return [];

		$post_type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$post_id_placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
		$period_query = $this->get_period_query_parts( $start_period, $end_period );
		$params = array_merge( [ 0 ], $period_query['params'], [ 'publish', '' ], $post_types, $post_ids );
		$query = $wpdb->prepare(
			' SELECT pv.id AS post_id, SUM( pv.count ) AS previous_views
			FROM ' . $wpdb->prefix . 'post_views AS pv
			INNER JOIN ' . $wpdb->posts . ' AS p ON pv.id = p.ID
			WHERE pv.type = %d
				AND ' . $period_query['clause'] . '
				AND p.post_status = %s
				AND p.post_password = %s
				AND p.post_type IN (' . $post_type_placeholders . ')
				AND pv.id IN (' . $post_id_placeholders . ')
			GROUP BY pv.id',
			$params
		);

		$results = $wpdb->get_results( $query, ARRAY_A );
		$views = [];

		if ( is_array( $results ) ) {
			foreach ( $results as $result ) {
				$views[(int) $result['post_id']] = (int) $result['previous_views'];
			}
		}

		return $views;
	}

	/**
	 * Build the period comparison clause and parameters.
	 *
	 * Date-based period rows use zero-padded Ymd strings, so string range
	 * comparisons remain semantically correct and avoid casting the indexed
	 * period column when the bounds are valid eight-digit dates.
	 *
	 * @param string|int $start_period
	 * @param string|int $end_period
	 * @return array
	 */
	private function get_period_query_parts( $start_period, $end_period ) {
		$raw_start_period = $start_period;
		$raw_end_period = $end_period;
		$start_period = $this->normalize_date_period_value( $start_period );
		$end_period = $this->normalize_date_period_value( $end_period );

		if ( $start_period !== '' && $end_period !== '' ) {
			if ( $start_period > $end_period ) {
				$temp = $start_period;
				$start_period = $end_period;
				$end_period = $temp;
			}

			return [
				'clause' => 'pv.period >= %s AND pv.period <= %s',
				'params' => [ $start_period, $end_period ]
			];
		}

		return [
			'clause' => 'CAST( pv.period AS SIGNED ) BETWEEN %d AND %d',
			'params' => [ (int) $raw_start_period, (int) $raw_end_period ]
		];
	}

	/**
	 * Normalize a date period value to an eight-digit Ymd string.
	 *
	 * @param string|int $period
	 * @return string
	 */
	private function normalize_date_period_value( $period ) {
		$period = preg_replace( '/\D+/', '', (string) $period );

		return preg_match( '/^\d{8}$/', $period ) ? $period : '';
	}

	/**
	 * Build top-content data.
	 *
	 * @param array $top_items
	 * @param array $previous_views
	 * @return array
	 */
	private function build_top_content_data( $top_items, $previous_views ) {
		$content = [];

		foreach ( $top_items as $item ) {
			$post_id = (int) $item['post_id'];
			$current_views = (int) $item['current_views'];
			$item_previous_views = isset( $previous_views[$post_id] ) ? (int) $previous_views[$post_id] : 0;
			$trend = $this->build_trend_data( $current_views, $item_previous_views );
			$url = get_permalink( $post_id );

			$content[] = [
				'post_id'			=> $post_id,
				'title'				=> sanitize_text_field( $item['post_title'] ),
				'url'				=> is_string( $url ) ? esc_url_raw( $url ) : '',
				'current_views'		=> $current_views,
				'previous_views'	=> $item_previous_views,
				'views_change'		=> $trend['views_change'],
				'views_change_percent' => $trend['views_change_percent'],
				'trend_reliable'	=> $trend['trend_reliable'],
				'trend_status'		=> $trend['trend_status']
			];
		}

		return $content;
	}

	/**
	 * Build trend data.
	 *
	 * @param int $current_views
	 * @param int $previous_views
	 * @return array
	 */
	private function build_trend_data( $current_views, $previous_views ) {
		$current_views = (int) $current_views;
		$previous_views = (int) $previous_views;
		$views_change = $current_views - $previous_views;
		$trend = [
			'views_change'			=> $views_change,
			'views_change_percent'	=> null,
			'trend_reliable'		=> false,
			'trend_status'			=> 'neutral'
		];

		if ( $previous_views < 25 )
			return $trend;

		$trend['trend_reliable'] = true;
		$trend['views_change_percent'] = round( ( $views_change / $previous_views ) * 100, 2 );

		if ( $views_change > 0 )
			$trend['trend_status'] = 'up';
		elseif ( $views_change < 0 )
			$trend['trend_status'] = 'down';
		else
			$trend['trend_status'] = 'flat';

		return $trend;
	}

	/**
	 * Build traffic signal data.
	 *
	 * @param array $overview
	 * @param array $top_content
	 * @return array
	 */
	private function build_traffic_signals_data( $overview, $top_content ) {
		if ( (int) $overview['total_views'] === 0 ) {
			return [
				'state'			=> 'silence',
				'post_id'		=> 0,
				'message_key'	=> 'no_data'
			];
		}

		if ( empty( $top_content ) ) {
			return [
				'state'			=> 'silence',
				'post_id'		=> 0,
				'message_key'	=> 'not_enough_data'
			];
		}

		foreach ( $top_content as $item ) {
			$current_views = isset( $item['current_views'] ) ? (int) $item['current_views'] : 0;
			$previous_views = isset( $item['previous_views'] ) ? (int) $item['previous_views'] : 0;

			if ( $current_views < 10 || $previous_views < 10 )
				continue;
			$change_percent = ( ( $current_views - $previous_views ) / $previous_views ) * 100;

			if ( abs( $change_percent ) > 25 ) {
				return [
					'state'			=> 'anomaly',
					'post_id'		=> ! empty( $item['post_id'] ) ? (int) $item['post_id'] : 0,
					'message_key'	=> 'anomaly'
				];
			}
		}

		return [
			'state'			=> 'silence',
			'post_id'		=> ! empty( $top_content[0]['post_id'] ) ? (int) $top_content[0]['post_id'] : 0,
			'message_key'	=> 'silence'
		];
	}

	/**
	 * Build status data.
	 *
	 * @param array $overview
	 * @param bool $has_top_content
	 * @return array
	 */
	private function build_status_data( $overview, $has_top_content ) {
		if ( (int) $overview['total_views'] === 0 ) {
			return [
				'empty'		=> true,
				'reason'	=> 'no_data'
			];
		}

		if ( ! $overview['threshold_met'] ) {
			return [
				'empty'		=> ! $has_top_content,
				'reason'	=> 'below_threshold'
			];
		}

		return [
			'empty'		=> ! $has_top_content,
			'reason'	=> 'ready'
		];
	}

}
