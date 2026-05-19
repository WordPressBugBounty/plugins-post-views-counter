<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Emails class.
 *
 * @class Post_Views_Counter_Emails
 */
class Post_Views_Counter_Emails {

	/**
	 * @var Post_Views_Counter
	 */
	private $pvc;

	/**
	 * @var Post_Views_Counter_Emails_Query
	 */
	private $query;

	/**
	 * @var Post_Views_Counter_Emails_Template
	 */
	private $template;

	/**
	 * Class constructor.
	 *
	 * @param Post_Views_Counter_Emails_Query|null $query
	 * @param Post_Views_Counter_Emails_Template|null $template
	 * @return void
	 */
	public function __construct( $query = null, $template = null ) {
		$this->pvc = Post_Views_Counter();
		$this->query = $query instanceof Post_Views_Counter_Emails_Query ? $query : new Post_Views_Counter_Emails_Query();
		$this->template = $template instanceof Post_Views_Counter_Emails_Template ? $template : new Post_Views_Counter_Emails_Template();
	}

	/**
	 * Build normalized summary data.
	 *
	 * @param string $summary_type
	 * @param array|null $period
	 * @param array $args
	 * @return array
	 */
	public function build_summary( $summary_type = 'weekly', $period = null, $args = [] ) {
		$summary_type = Post_Views_Counter_Emails_Period::normalize_summary_type_key( $summary_type );
		$data = $this->query->get_summary_data( $summary_type, $period, is_array( $args ) ? $args : [] );
		$site_url = home_url( '/' );
		$links = [
			'emails_settings_url' => admin_url( 'admin.php?page=post-views-counter&tab=emails' ),
			'site_url' => $site_url
		];

		$data['site'] = [
			'name' => get_bloginfo( 'name' ),
			'url' => $site_url
		];
		$data['links'] = $links;
		$data['plugin_tier'] = 'free';

		return apply_filters( 'pvc_email_summary_normalized_data', $data, $summary_type, $this );
	}

	/**
	 * Build normalized weekly summary data.
	 *
	 * @param array|null $period
	 * @param array $args
	 * @return array
	 */
	public function build_weekly_summary( $period = null, $args = [] ) {
		return $this->build_summary( 'weekly', $period, $args );
	}

	/**
	 * Render a summary without sending it.
	 *
	 * @param string $summary_type
	 * @param array|null $period
	 * @param array $args
	 * @return array
	 */
	public function render_summary( $summary_type = 'weekly', $period = null, $args = [] ) {
		$summary_type = Post_Views_Counter_Emails_Period::normalize_summary_type_key( $summary_type );
		$args = is_array( $args ) ? $args : [];
		$settings = isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : [];

		if ( isset( $args['settings'] ) )
			unset( $args['settings'] );

		$args = $this->apply_settings_query_args( $args, $settings );

		$data = $this->build_summary( $summary_type, $period, $args );

		return $this->template->render( $data, $settings );
	}

	/**
	 * Render a weekly summary without sending it.
	 *
	 * @param array|null $period
	 * @param array $args
	 * @return array
	 */
	public function render_weekly_summary( $period = null, $args = [] ) {
		return $this->render_summary( 'weekly', $period, $args );
	}

	/**
	 * Apply settings-derived query arguments when a caller overrides email settings.
	 *
	 * @param array $args
	 * @param array $settings
	 * @return array
	 */
	private function apply_settings_query_args( $args, $settings ) {
		if ( ! is_array( $settings ) || empty( $settings ) )
			return $args;

		if ( ! isset( $args['threshold'] ) && isset( $settings['min_views_threshold'] ) )
			$args['threshold'] = max( 0, (int) $settings['min_views_threshold'] );

		if ( ! isset( $args['send_empty_reports'] ) && array_key_exists( 'send_empty_reports', $settings ) )
			$args['send_empty_reports'] = ! empty( $settings['send_empty_reports'] );

		if ( ! isset( $args['limit'] ) && isset( $settings['max_top_items'] ) )
			$args['limit'] = min( 10, max( 3, (int) $settings['max_top_items'] ) );

		if ( ! isset( $args['post_types'] ) && ! empty( $settings['include_post_types'] ) && is_array( $settings['include_post_types'] ) )
			$args['post_types'] = $settings['include_post_types'];

		return $args;
	}

}