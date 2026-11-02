<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Status_Report class.
 *
 * The Status tab's support report: plain text from approved fields, each
 * with a declared type and domain, read from the facts and checks the page
 * shows in the same request. A value that is unavailable or fails its type
 * is left out, never written as an empty string or a zero. Nothing is
 * stored or sent; the browser copies or downloads the text.
 *
 * @class Post_Views_Counter_Status_Report
 */
class Post_Views_Counter_Status_Report {

	/** Version of the report's layout and keys. */
	const FORMAT = 1;

	/** Most entries a list value keeps. */
	const MAX_LIST = 50;

	/** Most report entries read from one extension check. */
	const MAX_EXTENSION_FIELDS = 32;

	/** Availability and state reasons a report may carry. */
	const REASONS = [
		'ready', 'pending', 'views_only', 'interval_zero', 'schema_pending', 'schema_failed', 'schema_lock_unavailable', 'writer_unsupported',
		'storage_incapable', 'state_read_failed', 'state_format_unsupported', 'state_missing', 'state_conflict', 'state_cache_publication_failed',
		'state_mutation_failed', 'state_write_failed', 'reset_in_progress', 'core_deactivated', 'derived_not_ready', 'before_derived_since',
		'explicit_range_required', 'explicit_activation_required', 'invalid_range', 'invalid_timestamp', 'query_failed', 'read_unusable',
		'settings_unavailable', 'table_missing', 'key_missing', 'not_applicable', 'extension_writer'
	];

	/** Earliest date a report writes: 2000-01-01T00:00:00Z. */
	const MIN_DATE = 946684800;

	/** First date a report does not write: 2100-01-01T00:00:00Z. */
	const MAX_DATE = 4102444800;

	/** A release number, with an optional pre-release tag; nothing else. */
	const VERSION_PATTERN = '/^\d{1,3}\.\d{1,3}(\.\d{1,3})?(-(alpha|beta|rc|RC|dev)\d{0,3}(-\d{1,6})?)?\z/';

	/** Scheduled event states. */
	const CRON_STATES = [ 'ok', 'missing', 'overdue', 'not_applicable', 'unknown' ];

	/** @var array Facts keyed by id. */
	private $facts;

	/** @var array Checks as the page shows them. */
	private $checks;

	/** @var int Collection time. */
	private $generated_at;

	/** @var array|null Accepted fields, key => PHP value. */
	private $fields = null;

	/**
	 * Class constructor.
	 *
	 * @param array $facts Facts keyed by id.
	 * @param array $checks Checks as the page shows them.
	 * @param int   $generated_at Collection time.
	 * @return void
	 */
	public function __construct( $facts, $checks, $generated_at ) {
		$this->facts = is_array( $facts ) ? $facts : [];
		$this->checks = is_array( $checks ) ? $checks : [];
		$this->generated_at = (int) $generated_at;
	}

	/**
	 * Core's approved keys, in report order, each with its type and domain.
	 *
	 * @return array
	 */
	public static function get_core_schema() {
		$reason = [ 'type' => 'code', 'domain' => self::REASONS, 'fallback' => 'other' ];
		$big = [ 'type' => 'int', 'min' => 0, 'max' => 9007199254740991 ];
		$table = [ 'type' => 'code', 'domain' => [ 'found', 'missing' ] ];
		$cron = [ 'type' => 'code', 'domain' => self::CRON_STATES ];

		return [
			'report_format'						=> [ 'type' => 'int', 'min' => 1, 'max' => 1 ],
			'generated_at'						=> [ 'type' => 'date' ],
			'pvc_version'						=> [ 'type' => 'version' ],
			'pvc_extension_version'				=> [ 'type' => 'version' ],
			'pvc_wordpress_version'				=> [ 'type' => 'version' ],
			'pvc_php_version'					=> [ 'type' => 'version' ],
			'pvc_database_version'				=> [ 'type' => 'version' ],
			'pvc_database_server'				=> [ 'type' => 'code', 'domain' => [ 'mariadb', 'mysql' ] ],
			'pvc_site_scope'					=> [ 'type' => 'code', 'domain' => [ 'single', 'main_site', 'subsite' ] ],
			'pvc_counter_mode'					=> [ 'type' => 'code', 'domain' => [ 'php', 'js', 'rest_api', 'ajax' ], 'fallback' => 'other' ],
			'pvc_post_types'					=> [ 'type' => 'slugs' ],
			'pvc_count_interval_hours'			=> [ 'type' => 'int', 'min' => 0, 'max' => 720 ],
			'pvc_interval_storage'				=> [ 'type' => 'code', 'domain' => [ 'cookies', 'cookieless' ], 'fallback' => 'other' ],
			'pvc_count_time'					=> [ 'type' => 'code', 'domain' => [ 'gmt', 'local' ] ],
			'pvc_clock_differs'					=> [ 'type' => 'bool' ],
			'pvc_exclusion_groups'				=> [ 'type' => 'codes', 'domain' => [ 'robots', 'ai_bots', 'users', 'guests', 'roles' ] ],
			'pvc_excluded_roles_count'			=> [ 'type' => 'int', 'min' => 0, 'max' => 1000 ],
			'pvc_excluded_ips_count'			=> [ 'type' => 'int', 'min' => 0, 'max' => 100000 ],
			'pvc_admin_request_excluded_by'		=> [ 'type' => 'code', 'domain' => [ 'none', 'ip', 'logged_in_users', 'role', 'guests', 'crawler', 'unrecognized' ] ],
			'pvc_ip_strategy'					=> [ 'type' => 'code', 'domain' => [ 'remote_addr', 'trusted_proxy_only', 'auto' ] ],
			'pvc_ip_resolved'					=> [ 'type' => 'bool' ],
			'pvc_caching_plugins'				=> [ 'type' => 'names', 'domain' => [ 'Autoptimize', 'Breeze', 'Hummingbird', 'LiteSpeed Cache', 'Speed Optimizer', 'SpeedyCache', 'WP Fastest Cache', 'WP-Optimize', 'WP Rocket' ] ],
			'pvc_caching_plugins_other'			=> [ 'type' => 'int', 'min' => 0, 'max' => self::MAX_LIST ],
			'pvc_security_plugins'				=> [ 'type' => 'names', 'domain' => [ 'Wordfence', 'Solid Security', 'Sucuri Security', 'All-In-One Security' ] ],
			'pvc_security_plugins_other'		=> [ 'type' => 'int', 'min' => 0, 'max' => self::MAX_LIST ],
			'pvc_views_access'					=> [ 'type' => 'bool' ],
			'pvc_views_access_reason'			=> $reason,
			'pvc_write_eligible'				=> [ 'type' => 'bool' ],
			'pvc_write_reason'					=> $reason,
			'pvc_visits_readable'				=> [ 'type' => 'bool' ],
			'pvc_visits_read_reason'			=> $reason,
			'pvc_visits_writable'				=> [ 'type' => 'bool' ],
			'pvc_visits_write_reason'			=> $reason,
			'pvc_visits_reason'					=> $reason,
			'pvc_derived_available'				=> [ 'type' => 'bool' ],
			'pvc_derived_reason'				=> $reason,
			'pvc_derived_since'					=> [ 'type' => 'date' ],
			'pvc_state_kind'					=> [ 'type' => 'code', 'domain' => [ 'normal', 'repair', 'tombstone', 'future', 'malformed', 'missing', 'unreadable' ] ],
			'pvc_state_format'					=> [ 'type' => 'int', 'min' => 0, 'max' => 99 ],
			'pvc_state'							=> [ 'type' => 'code', 'domain' => [ 'pending', 'ready', 'unavailable' ], 'fallback' => 'other' ],
			'pvc_state_reason'					=> $reason,
			'pvc_schema_state'					=> [ 'type' => 'code', 'domain' => [ 'pending', 'ready', 'failed' ] ],
			'pvc_schema_last_status'			=> [ 'type' => 'code', 'domain' => array_merge( Post_Views_Counter_Visits::SCHEMA_STATUS_CODES, [ 'unrecognized' ] ) ],
			'pvc_queue_generation'				=> [ 'type' => 'int', 'min' => 0, 'max' => 2147483647 ],
			'pvc_schema_lease'					=> [ 'type' => 'bool' ],
			'pvc_schema_lease_expires_at'		=> [ 'type' => 'date' ],
			'pvc_table_post_views'				=> $table,
			'pvc_table_post_views_sources'		=> $table,
			'pvc_table_post_views_terms'		=> $table,
			'pvc_table_post_views_other'		=> $table,
			'pvc_table_visits_column'			=> [ 'type' => 'bool' ],
			'pvc_table_content_column'			=> [ 'type' => 'bool' ],
			'pvc_table_indexes'					=> [ 'type' => 'identifiers' ],
			'pvc_table_collation'				=> [ 'type' => 'identifier' ],
			'pvc_table_rows_estimate'			=> $big,
			'pvc_table_size_bytes'				=> $big,
			'pvc_table_shape_reason'			=> $reason,
			'pvc_activity_days_with_views'		=> [ 'type' => 'int', 'min' => 0, 'max' => Post_Views_Counter_Status_Facts::ACTIVITY_DAYS ],
			'pvc_activity_last_day'				=> [ 'type' => 'day' ],
			'pvc_activity_reason'				=> $reason,
			'pvc_buffering'						=> [ 'type' => 'bool' ],
			'pvc_buffering_setting'				=> [ 'type' => 'bool' ],
			'pvc_persistent_object_cache'		=> [ 'type' => 'bool' ],
			'pvc_flush_interval_minutes'		=> [ 'type' => 'int', 'min' => 0, 'max' => 525600 ],
			'pvc_core_queue_keys'				=> [ 'type' => 'int', 'min' => 0, 'max' => 2147483647 ],
			'pvc_core_queue_reason'				=> $reason,
			'pvc_core_flush_lock_expires_at'	=> [ 'type' => 'date' ],
			'pvc_cron_disabled'					=> [ 'type' => 'bool' ],
			'pvc_cron_reset_counts'				=> $cron,
			'pvc_cron_reset_counts_next'		=> [ 'type' => 'date' ],
			'pvc_cron_schema_sweep'				=> $cron,
			'pvc_cron_schema_sweep_next'		=> [ 'type' => 'date' ],
			'pvc_cron_weekly_summary'			=> $cron,
			'pvc_cron_weekly_summary_next'		=> [ 'type' => 'date' ]
		];
	}

	/**
	 * The delivery run's keys, written only after the button runs the checks.
	 *
	 * @return array
	 */
	public static function get_delivery_schema() {
		$status = [ 'type' => 'int', 'min' => 100, 'max' => 599 ];
		$result = [ 'type' => 'code', 'domain' => Post_Views_Counter_Status_Delivery::RESULTS ];

		return [
			'pvc_delivery_transport'		=> [ 'type' => 'code', 'domain' => [ 'admin_ajax', 'rest_api', 'extension' ] ],
			'pvc_delivery_handler'			=> [ 'type' => 'code', 'domain' => [ 'core', 'core_transport_only', 'extension' ] ],
			'pvc_delivery_browser'			=> $result,
			'pvc_delivery_browser_status'	=> $status,
			'pvc_delivery_server'			=> $result,
			'pvc_delivery_server_status'	=> $status,
			'pvc_display_browser'			=> $result,
			'pvc_display_browser_status'	=> $status,
			'pvc_display_server'			=> $result,
			'pvc_display_server_status'		=> $status,
			'pvc_page_scan'					=> [ 'type' => 'code', 'domain' => Post_Views_Counter_Status_Delivery::SCAN_RESULTS ],
			'pvc_page_scan_status'			=> $status,
			'pvc_page_token'				=> [ 'type' => 'code', 'domain' => [ 'valid', 'expired', 'none' ] ],
			'pvc_cache_headers'				=> [ 'type' => 'names', 'domain' => Post_Views_Counter_Status_Delivery::CACHE_HEADERS ],
			'pvc_delivery_checked_at'		=> [ 'type' => 'date' ]
		];
	}

	/**
	 * The keys an extension may add through its checks' report maps, each
	 * with its type and domain. A key not listed here is never written.
	 *
	 * @return array
	 */
	public static function get_extension_schema() {
		$date = [ 'type' => 'date' ];

		return [
			'pvcp_fast_ajax_available'			=> [ 'type' => 'bool' ],
			'pvcp_fast_ajax_checked_at'			=> $date,
			'pvcp_fast_ajax_error'				=> [ 'type' => 'code', 'domain' => [ 'http_request_failed', 'http_error', 'unexpected_response', 'unresolved_endpoint' ], 'fallback' => 'other' ],
			'pvcp_fast_ajax_http_status'		=> [ 'type' => 'int', 'min' => 100, 'max' => 599 ],
			'pvcp_dynamic_loading'				=> [ 'type' => 'bool' ],
			'pvcp_queue_batches_waiting'		=> [ 'type' => 'int', 'min' => 0, 'max' => 2147483647 ],
			'pvcp_flush_next'					=> $date,
			'pvcp_flush_lock_expires_at'		=> $date,
			'pvcp_retry_after'					=> $date,
			'pvcp_caching_integration'			=> [ 'type' => 'code', 'domain' => [ 'none', 'autoptimize', 'breeze', 'hummingbird', 'litespeed_cache', 'speed_optimizer', 'speedycache', 'wp_fastest_cache', 'wp_optimize', 'wp_rocket' ], 'fallback' => 'other' ],
			'pvcp_strict_counts'				=> [ 'type' => 'bool' ],
			'pvcp_ai_bots_excluded'				=> [ 'type' => 'bool' ],
			'pvcp_license_status'				=> [ 'type' => 'code', 'domain' => [ 'none', 'unknown', 'valid', 'expired', 'disabled', 'inactive', 'site_inactive', 'invalid', 'invalid_item_id', 'item_name_mismatch' ], 'fallback' => 'other' ],
			'pvcp_license_check_failed'			=> [ 'type' => 'bool' ],
			'pvcp_license_expiry_kind'			=> [ 'type' => 'code', 'domain' => [ 'date', 'lifetime', 'none' ] ],
			'pvcp_license_expires_at'			=> $date,
			'pvcp_license_last_action'			=> [ 'type' => 'code', 'domain' => [ 'activate_license', 'check_license', 'deactivate_license', 'none' ] ],
			'pvcp_license_last_activity'		=> $date,
			'pvcp_license_last_error_category'	=> [ 'type' => 'code', 'domain' => [ 'business_error', 'unknown_business_error', 'transport_error', 'http_error', 'invalid_response', 'none' ] ],
			'pvcp_license_last_error_code'		=> [ 'type' => 'code', 'domain' => [ 'missing', 'missing_url', 'missing_item_id', 'license_not_activable', 'bundle_activation_not_allowed', 'disabled', 'no_activations_left', 'expired', 'key_mismatch', 'invalid_item_id', 'item_name_mismatch', 'site_inactive', 'invalid', 'failed' ], 'fallback' => 'other' ],
			'pvcp_license_last_http_status'		=> [ 'type' => 'int', 'min' => 0, 'max' => 599 ]
		];
	}

	/**
	 * The accepted fields: Core's, then the extensions', each a value that
	 * passed its type. Built once.
	 *
	 * @return array Key => PHP value.
	 */
	public function get_fields() {
		if ( $this->fields !== null )
			return $this->fields;

		$fields = [];
		$values = $this->get_core_values();

		foreach ( self::get_core_schema() as $key => $spec ) {
			$accepted = array_key_exists( $key, $values ) ? $this->accept( $spec, $values[ $key ] ) : null;

			if ( $accepted !== null )
				$fields[ $key ] = $accepted;
		}

		$schema = self::get_extension_schema();

		foreach ( $this->checks as $check ) {
			if ( ! isset( $check['source'], $check['report'] ) || $check['source'] !== 'extension' || ! is_array( $check['report'] ) )
				continue;

			foreach ( array_slice( $check['report'], 0, self::MAX_EXTENSION_FIELDS, true ) as $key => $value ) {
				// matched exactly; a key already written keeps its first value
				if ( ! is_string( $key ) || ! isset( $schema[ $key ] ) || array_key_exists( $key, $fields ) )
					continue;

				$accepted = $this->accept( $schema[ $key ], $value );

				if ( $accepted !== null )
					$fields[ $key ] = $accepted;
			}
		}

		$this->fields = $fields;

		return $this->fields;
	}

	/**
	 * The report as plain text: a heading, one "key: value" line per field,
	 * then one line per check with its severity and observation time. Core's
	 * checks are named by their ids; an extension's check by its group and
	 * position, since its id is the extension's own text.
	 *
	 * @return string
	 */
	public function get_text() {
		$schema = array_merge( self::get_extension_schema(), self::get_core_schema() );
		$lines = [ 'Post Views Counter support report' ];

		foreach ( $this->get_fields() as $key => $value ) {
			$lines[] = $key . ': ' . $this->write( $schema[ $key ], $value );
		}

		return implode( "\n", array_merge( $lines, $this->get_check_lines() ) ) . "\n";
	}

	/**
	 * The lines one delivery run adds to the report: its fields, then its
	 * checks.
	 *
	 * @param array $delivery Run: probe, browser, server, scan, headers, checked_at.
	 * @return string
	 */
	public function get_delivery_text( $delivery ) {
		$probe = $delivery['probe'];
		$values = [
			'pvc_delivery_transport'		=> $probe === null ? null : $probe['transport'],
			'pvc_delivery_handler'			=> $probe === null ? null : $probe['handler'],
			'pvc_delivery_browser'			=> $probe === null ? null : $delivery['browser']['result'],
			'pvc_delivery_browser_status'	=> $probe === null ? null : $delivery['browser']['status'],
			'pvc_delivery_server'			=> $probe === null ? null : $delivery['server']['result'],
			'pvc_delivery_server_status'	=> $probe === null ? null : $delivery['server']['status'],
			'pvc_display_browser'			=> empty( $delivery['display'] ) ? null : $delivery['display']['browser']['result'],
			'pvc_display_browser_status'	=> empty( $delivery['display'] ) ? null : $delivery['display']['browser']['status'],
			'pvc_display_server'			=> empty( $delivery['display'] ) ? null : $delivery['display']['server']['result'],
			'pvc_display_server_status'		=> empty( $delivery['display'] ) ? null : $delivery['display']['server']['status'],
			'pvc_page_scan'					=> $probe === null ? null : $delivery['scan']['result'],
			'pvc_page_scan_status'			=> $probe === null ? null : $delivery['scan']['status'],
			'pvc_page_token'				=> $probe === null ? null : $delivery['scan']['token'],
			'pvc_cache_headers'				=> $delivery['headers'],
			'pvc_delivery_checked_at'		=> $delivery['checked_at']
		];
		$lines = [];

		foreach ( self::get_delivery_schema() as $key => $spec ) {
			$accepted = array_key_exists( $key, $values ) ? $this->accept( $spec, $values[ $key ] ) : null;

			if ( $accepted !== null )
				$lines[] = $key . ': ' . $this->write( $spec, $accepted );
		}

		return implode( "\n", array_merge( $lines, $this->get_check_lines() ) ) . "\n";
	}

	/**
	 * One line per check: its name, severity and observation time.
	 *
	 * @return array
	 */
	private function get_check_lines() {
		$lines = [];
		$positions = [];

		foreach ( $this->checks as $check ) {
			if ( ! isset( $check['id'], $check['severity'] ) || ! in_array( $check['severity'], Post_Views_Counter_Settings_Status::SEVERITIES, true ) )
				continue;

			if ( isset( $check['source'] ) && $check['source'] === 'extension' ) {
				$group = isset( $check['group'] ) && in_array( $check['group'], Post_Views_Counter_Settings_Status::EXTENSION_GROUPS, true ) ? $check['group'] : 'other_checks';
				$positions[ $group ] = isset( $positions[ $group ] ) ? $positions[ $group ] + 1 : 1;
				$name = 'extension_' . $group . '_' . $positions[ $group ];
			} elseif ( is_string( $check['id'] ) && preg_match( '/^pvc_[a-z0-9_]{1,60}\z/', $check['id'] ) === 1 )
				$name = $check['id'];
			else
				continue;

			$line = 'check ' . $name . ': ' . $check['severity'];

			if ( isset( $check['observed_at'] ) && $this->accept( [ 'type' => 'date' ], $check['observed_at'] ) !== null )
				$line .= ', observed ' . $this->write( [ 'type' => 'date' ], $check['observed_at'] );

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Core's raw values, keyed by report key, from the facts; an
	 * unavailable fact gives no value, and its reason where the schema has
	 * one.
	 *
	 * @return array
	 */
	private function get_core_values() {
		$value = function ( $id, $field = null ) {
			if ( ! isset( $this->facts[ $id ] ) || empty( $this->facts[ $id ]['available'] ) )
				return null;

			$fact = $this->facts[ $id ]['value'];

			if ( $field === null )
				return $fact;

			return is_array( $fact ) && array_key_exists( $field, $fact ) ? $fact[ $field ] : null;
		};
		$reason = function ( $id ) {
			return isset( $this->facts[ $id ] ) && empty( $this->facts[ $id ]['available'] ) ? $this->facts[ $id ]['reason'] : null;
		};

		$values = [
			'report_format'					=> self::FORMAT,
			'generated_at'					=> $this->generated_at,
			'pvc_version'					=> $value( 'versions', 'core' ),
			'pvc_extension_version'			=> $value( 'versions', 'pro' ),
			'pvc_wordpress_version'			=> $value( 'versions', 'wordpress' ),
			'pvc_php_version'				=> $this->get_release( $value( 'versions', 'php' ) ),
			'pvc_database_version'			=> $this->get_release( $value( 'versions', 'database' ) ),
			'pvc_database_server'			=> is_string( $value( 'versions', 'database' ) ) && $value( 'versions', 'database' ) !== '' ? ( stripos( $value( 'versions', 'database' ), 'mariadb' ) !== false ? 'mariadb' : 'mysql' ) : null,
			'pvc_site_scope'				=> $value( 'site_scope' ),
			'pvc_counter_mode'				=> $value( 'counter_mode' ),
			'pvc_post_types'				=> $value( 'countable_content', 'post_types' ),
			'pvc_count_interval_hours'		=> $value( 'count_interval' ),
			'pvc_interval_storage'			=> $value( 'interval_storage' ),
			'pvc_count_time'				=> $value( 'counting_clock', 'count_time' ),
			'pvc_clock_differs'				=> $value( 'counting_clock', 'differs' ),
			'pvc_exclusion_groups'			=> $value( 'exclusions', 'groups' ),
			'pvc_excluded_roles_count'		=> $value( 'exclusions', 'roles_count' ),
			'pvc_excluded_ips_count'		=> $value( 'exclusions', 'ips_count' ),
			'pvc_ip_strategy'				=> $value( 'ip_resolution', 'strategy' ),
			'pvc_ip_resolved'				=> $value( 'ip_resolution', 'resolved' ),
			'pvc_views_access'				=> $value( 'views_access' ),
			'pvc_views_access_reason'		=> isset( $this->facts['views_access'] ) ? $this->facts['views_access']['reason'] : null,
			'pvc_write_eligible'			=> $value( 'write_eligibility' ),
			'pvc_write_reason'				=> isset( $this->facts['write_eligibility'] ) ? $this->facts['write_eligibility']['reason'] : null,
			'pvc_visits_readable'			=> $value( 'visits', 'readable' ),
			'pvc_visits_read_reason'		=> $value( 'visits', 'read_reason' ),
			'pvc_visits_writable'			=> $value( 'visits', 'writable' ),
			'pvc_visits_write_reason'		=> $value( 'visits', 'write_reason' ),
			'pvc_visits_reason'				=> $reason( 'visits' ),
			'pvc_derived_available'			=> $value( 'derived', 'derived_available' ),
			'pvc_derived_reason'			=> $value( 'derived', 'derived_reason' ),
			'pvc_derived_since'				=> $value( 'derived', 'derived_since' ),
			'pvc_state_kind'				=> isset( $this->facts['state_row'] ) ? ( $this->facts['state_row']['available'] ? $value( 'state_row', 'kind' ) : 'unreadable' ) : null,
			'pvc_state_format'				=> $value( 'state_row', 'format_version' ),
			'pvc_state'						=> $value( 'state_row', 'state' ),
			'pvc_state_reason'				=> $value( 'state_row', 'reason' ),
			'pvc_schema_state'				=> $value( 'state_row', 'schema_state' ),
			'pvc_schema_last_status'		=> $value( 'state_row', 'schema_last_status' ),
			'pvc_queue_generation'			=> $value( 'state_row', 'queue_generation' ),
			'pvc_schema_lease'				=> $value( 'schema_lease', 'present' ),
			'pvc_schema_lease_expires_at'	=> $value( 'schema_lease', 'expires_at' ),
			'pvc_table_visits_column'		=> $value( 'table_shape', 'visits_column' ),
			'pvc_table_content_column'		=> $value( 'table_shape', 'content_column' ),
			'pvc_table_indexes'				=> $value( 'table_shape', 'indexes' ),
			'pvc_table_collation'			=> $value( 'table_shape', 'collation' ),
			'pvc_table_rows_estimate'		=> $value( 'table_shape', 'rows_estimate' ),
			'pvc_table_size_bytes'			=> $value( 'table_shape', 'size_bytes' ),
			'pvc_table_shape_reason'		=> $reason( 'table_shape' ),
			'pvc_activity_days_with_views'	=> is_array( $value( 'counting_activity', 'days' ) ) ? count( array_filter( $value( 'counting_activity', 'days' ) ) ) : null,
			'pvc_activity_last_day'			=> $value( 'counting_activity', 'last_day' ),
			'pvc_activity_reason'			=> $reason( 'counting_activity' ),
			'pvc_buffering'					=> $value( 'buffering', 'in_effect' ),
			'pvc_buffering_setting'			=> $value( 'buffering', 'setting' ),
			'pvc_persistent_object_cache'	=> $value( 'buffering', 'persistent_cache' ),
			'pvc_flush_interval_minutes'	=> $this->get_flush_minutes( $value( 'buffering', 'flush_interval' ) ),
			'pvc_core_queue_keys'			=> $value( 'core_queue', 'keys' ),
			'pvc_core_queue_reason'			=> isset( $this->facts['core_queue'] ) ? $this->facts['core_queue']['reason'] : null,
			'pvc_core_flush_lock_expires_at' => $value( 'core_queue', 'lock_expires_at' )
		];

		$admin = $value( 'admin_request' );

		if ( is_array( $admin ) && isset( $admin['passes'] ) )
			$values['pvc_admin_request_excluded_by'] = $admin['passes'] ? 'none' : $admin['excluded_by'];

		// recognized names are written; any other name is only counted
		foreach ( [ 'caching_plugins', 'security_plugins' ] as $id ) {
			$names = $value( $id );

			if ( ! is_array( $names ) )
				continue;

			$domain = self::get_core_schema()[ 'pvc_' . $id ]['domain'];
			$values[ 'pvc_' . $id ] = array_values( array_intersect( $names, $domain ) );
			$values[ 'pvc_' . $id . '_other' ] = count( array_diff( $names, $domain ) );
		}

		$tables = $value( 'tables' );

		if ( is_array( $tables ) ) {
			foreach ( $tables as $table ) {
				if ( isset( $table['name'], $table['exists'] ) && is_bool( $table['exists'] ) && in_array( $table['name'], [ 'post_views', 'post_views_sources', 'post_views_terms', 'post_views_other' ], true ) )
					$values[ 'pvc_table_' . $table['name'] ] = $table['exists'] ? 'found' : 'missing';
			}
		}

		$cron = $value( 'scheduled_events' );

		if ( is_array( $cron ) ) {
			$values['pvc_cron_disabled'] = $cron['cron_disabled'];
			$hooks = [
				'pvc_reset_counts'						=> 'pvc_cron_reset_counts',
				Post_Views_Counter_Visits::CRON_HOOK	=> 'pvc_cron_schema_sweep',
				'pvc_weekly_content_summary_send'		=> 'pvc_cron_weekly_summary'
			];

			foreach ( $hooks as $hook => $key ) {
				if ( ! isset( $cron['events'][ $hook ] ) )
					continue;

				$values[ $key ] = $cron['events'][ $hook ]['status'];
				$values[ $key . '_next' ] = $cron['events'][ $hook ]['next'];
			}
		}

		return $values;
	}

	/**
	 * The release number at the start of a server version string, without
	 * its build or distribution suffix.
	 *
	 * @param mixed $version Version string.
	 * @return string|null
	 */
	private function get_release( $version ) {
		if ( ! is_string( $version ) )
			return null;

		// MariaDB can put a MySQL compatibility prefix before its own release
		if ( stripos( $version, 'mariadb' ) !== false )
			$version = preg_replace( '/^5\.5\.5-/', '', $version );

		return preg_match( '/^\d{1,3}\.\d{1,3}(\.\d{1,3})?/', $version, $match ) === 1 ? $match[0] : null;
	}

	/**
	 * The flush interval in minutes.
	 *
	 * @param mixed $interval Number and type.
	 * @return int|null
	 */
	private function get_flush_minutes( $interval ) {
		if ( ! is_array( $interval ) || ! isset( $interval['number'], $interval['type'] ) || ! is_numeric( $interval['number'] ) )
			return null;

		$minutes = [ 'minutes' => 1, 'hours' => 60, 'days' => 1440, 'weeks' => 10080, 'months' => 43200, 'years' => 525600 ];

		return isset( $minutes[ $interval['type'] ] ) ? (int) $interval['number'] * $minutes[ $interval['type'] ] : null;
	}

	/**
	 * A value that passes its declared type, normalized; null when it does
	 * not.
	 *
	 * @param array $spec Type and domain.
	 * @param mixed $value Raw value.
	 * @return mixed|null
	 */
	private function accept( $spec, $value ) {
		switch ( $spec['type'] ) {
			case 'bool':
				return is_bool( $value ) ? $value : null;

			case 'int':
				return is_int( $value ) && $value >= $spec['min'] && $value <= $spec['max'] ? $value : null;

			case 'code':
				if ( ! is_string( $value ) || preg_match( '/^[a-z][a-z0-9_]{0,63}\z/', $value ) !== 1 )
					return null;

				if ( in_array( $value, $spec['domain'], true ) )
					return $value;

				return isset( $spec['fallback'] ) ? $spec['fallback'] : null;

			case 'codes':
				return $this->accept_list( $value, static function ( $item ) use ( $spec ) {
					return is_string( $item ) && in_array( $item, $spec['domain'], true );
				} );

			case 'names':
				return $this->accept_list( $value, static function ( $item ) use ( $spec ) {
					return is_string( $item ) && in_array( $item, $spec['domain'], true );
				}, true );

			case 'slugs':
				return $this->accept_list( $value, static function ( $item ) {
					return is_string( $item ) && preg_match( '/^[a-z0-9_-]{1,20}\z/', $item ) === 1 && post_type_exists( $item );
				} );

			case 'identifiers':
				return $this->accept_list( $value, static function ( $item ) {
					return is_string( $item ) && preg_match( '/^[A-Za-z0-9_]{1,64}\z/', $item ) === 1;
				} );

			case 'identifier':
				return is_string( $value ) && preg_match( '/^[A-Za-z0-9_]{1,64}\z/', $value ) === 1 ? $value : null;

			case 'version':
				return is_string( $value ) && preg_match( self::VERSION_PATTERN, $value ) === 1 ? $value : null;

			case 'date':
				return is_int( $value ) && $value >= self::MIN_DATE && $value < self::MAX_DATE ? $value : null;

			case 'day':
				if ( ! is_string( $value ) || preg_match( '/^\d{8}\z/', $value ) !== 1 )
					return null;

				// a day that exists: parsing rolls 20260231 over into March
				$day = DateTimeImmutable::createFromFormat( '!Ymd', $value, new DateTimeZone( 'UTC' ) );

				return $day !== false && $day->format( 'Ymd' ) === $value ? $value : null;
		}

		return null;
	}

	/**
	 * A list of accepted items, unique and sorted; null when the value is not
	 * a list. An empty list is a value: nothing of that kind.
	 *
	 * @param mixed    $value Raw value.
	 * @param callable $accepts Item check.
	 * @param bool     $keep_case Whether to sort case-insensitively.
	 * @return array|null
	 */
	private function accept_list( $value, $accepts, $keep_case = false ) {
		if ( ! is_array( $value ) )
			return null;

		$items = array_values( array_unique( array_filter( array_values( $value ), $accepts ) ) );

		if ( $keep_case )
			natcasesort( $items );
		else
			sort( $items );

		return array_slice( array_values( $items ), 0, self::MAX_LIST );
	}

	/**
	 * A value written as text.
	 *
	 * @param array $spec Type and domain.
	 * @param mixed $value Accepted value.
	 * @return string
	 */
	private function write( $spec, $value ) {
		switch ( $spec['type'] ) {
			case 'bool':
				return $value ? 'yes' : 'no';

			case 'date':
				return gmdate( 'Y-m-d\TH:i:s\Z', $value );

			case 'day':
				return substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 );

			case 'codes':
			case 'names':
			case 'slugs':
			case 'identifiers':
				return empty( $value ) ? 'none' : implode( ', ', $value );
		}

		return (string) $value;
	}
}
