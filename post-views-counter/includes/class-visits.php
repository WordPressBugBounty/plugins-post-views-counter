<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Visits class.
 *
 * Single authority for the independently resolved Visit read, write, and
 * derived-metric states. Stored Visits are raw counter data, like Views; only
 * calculations derived from Visits use the per-site derived_since boundary.
 *
 * Responsibilities:
 * - Schema migration and readiness for the additive `visits` column, including
 *   the bounded retry state and the non-blocking sweep that advances it.
 * - Writer and storage capability: whether a visit-aware writer is present and
 *   whether the configured browser storage can identify a Count Interval at all.
 * - Multisite convergence: per-site verification and the bounded network cursor
 *   that brings a whole network to a known state.
 * - Diagnostics and the availability result consumed by readers and writers.
 *
 * Explicitly NOT owned here: counting individual View and Visit events and
 * persisting their rows. That belongs to the counter classes
 * and their extension implementations, which consult this class for permission
 * before performing their own writes. Period parsing,
 * query identity and the Visit read-cache protocol belong to
 * Post_Views_Counter_Visits_Query.
 *
 * > TODO: This class is large enough to warrant a future split, deliberately not
 * > performed in this change: Post_Views_Counter_Visits_Schema (migration,
 * > sweep, network cursor), Post_Views_Counter_Visits_Status (read/write/
 * > derived status), and the internal period/query/cache helper that already
 * > exists as
 * > Post_Views_Counter_Visits_Query.
 *
 * @class Post_Views_Counter_Visits
 */
class Post_Views_Counter_Visits {

	const OPTION_NAME = 'post_views_counter_visits_state';
	const LOCK_OPTION_NAME = 'post_views_counter_visits_schema_lock';
	const NETWORK_OPTION_NAME = 'post_views_counter_visits_schema_migration';
	const CRON_HOOK = 'pvc_visits_schema_sweep';
	const SCHEMA_VERSION = 1;
	const SCHEMA_CHECK_INTERVAL = 43200;
	const SCHEMA_RETRY_INTERVAL = 300;
	const SCHEMA_PENDING_LEASE = 900;
	const NETWORK_BATCH_SIZE = 25;
	const NETWORK_FAILED_RETRY_BATCH_SIZE = 5;
	const NETWORK_FAILED_SITES_LIMIT = 50;
	const NETWORK_TIME_BUDGET = 10;
	const STATE_WRITE_ATTEMPTS = 3;

	/**
	 * Request-local availability results. WordPress already caches the backing
 * option; this avoids repeating compatibility work per request.
	 *
	 * Keyed by blog ID and normalized range identity only, so a long-running
	 * request holds at most one entry per distinct range no matter how much time
	 * passes. Each entry records the clock second it was computed in, because
	 * Results are replaced rather than accumulated so a long-running request has
	 * one entry for every normalized range identity.
	 *
	 * @var array<string,array{checked_at:int,value:array}>
	 */
	private $availability_cache = [];

	/** @var array<int,array> Request-local durable state rows by blog. */
	private $durable_state_cache = [];

	/**
	 * Blog whose singleton settings were loaded at construction time.
	 *
	 * @var int
	 */
	private $origin_blog_id = 0;

	/**
	 * Whether this database connection owns the non-blocking sweep lock.
	 *
	 * @var bool
	 */
	private $schema_sweep_lock_held = false;

	/**
	 * Whether this database connection owns the measurement-reset table lock.
	 *
	 * @var bool
	 */
	private $measurement_reset_lock_held = false;

	/** @var int Nesting depth of the restrictive post_views/options SQL fence. */
	private $measurement_fence_depth = 0;

	/** @var array<int,array{type:string,retire_reads:bool,invalidate_availability:bool}> FIFO post-unlock cache work. */
	private $measurement_fence_work = [];

	/** @var bool Whether this fence performed a durable write that must not be acknowledged before unlock. */
	private $measurement_fence_critical_write = false;

	/** @var string|false Schema lease retained across destructive deletion. */
	private $lifecycle_schema_lock_token = false;

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->origin_blog_id = is_multisite() ? (int) get_current_blog_id() : 0;
		add_action( 'admin_init', [ $this, 'ensure_schema_sweep_scheduled' ], 4 );
		add_action( 'admin_init', [ $this, 'maybe_upgrade_schema' ], 5 );
		add_action( 'plugins_loaded', [ $this, 'refresh_availability' ], 20 );
		add_action( self::CRON_HOOK, [ $this, 'run_schema_sweep' ] );
		add_action( 'activated_plugin', [ $this, 'handle_activated_plugin' ], 10, 2 );
		add_action( 'activated_plugin', [ $this, 'handle_plugin_state_change' ], 20, 2 );
		add_action( 'deactivated_plugin', [ $this, 'handle_plugin_state_change' ], 20, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'handle_upgrader_process_complete' ], 10, 2 );
		add_action( 'update_option_post_views_counter_settings_general', [ $this, 'handle_general_settings_updated' ], 10, 3 );
		add_action( 'add_option_post_views_counter_settings_general', [ $this, 'handle_general_settings_added' ], 10, 2 );
		add_filter( 'pvc_plugin_status_rows', [ $this, 'add_plugin_status_rows' ], 20, 2 );
	}

	/**
	 * Run the controlled schema upgrader in an authorized admin context.
	 *
	 * @return void
	 */
	public function maybe_upgrade_schema() {
		if ( wp_installing() )
			return;

		$is_background = wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
		$is_authorized_admin = is_admin() && current_user_can( 'manage_options' );

		if ( ! $is_background && ! $is_authorized_admin )
			return;

		if ( is_multisite() && is_network_admin() && current_user_can( 'manage_network_plugins' ) ) {
			$this->maybe_upgrade_network_schema();
			return;
		}

		$state = $this->get_state();
		$last_probe_error_at = (int) $state['schema']['last_probe_error_at'];

		if ( $last_probe_error_at > 0 && $this->now() - $last_probe_error_at < self::SCHEMA_RETRY_INTERVAL )
			return;

		if ( $state['schema']['state'] === 'ready' ) {
			$last_check = (int) $state['last_compatibility_check'];

			if ( $last_check > 0 && $this->now() - $last_check < self::SCHEMA_CHECK_INTERVAL )
				return;

			$this->verify_schema();
			return;
		}

		if ( $state['schema']['state'] === 'failed' && empty( $state['schema']['retryable'] ) )
			return;

		$last_attempt_at = (int) $state['schema']['last_attempt_at'];

		if ( $last_attempt_at > 0 && $this->now() - $last_attempt_at < self::SCHEMA_RETRY_INTERVAL )
			return;

		$this->migrate_schema();
	}

	/**
	 * Run one bounded cron migration unit without requiring an interactive user.
	 *
	 * @return void
	 */
	public function run_schema_sweep() {
		if ( wp_installing() )
			return;

		if ( ! $this->acquire_schema_sweep_lock() )
			return;

		try {
			if ( is_multisite() && is_main_site() && $this->has_network_migration_state() )
				$this->maybe_upgrade_network_schema();
			else {
				$state = $this->get_state();

				if ( $state['schema']['state'] === 'ready' )
					$this->verify_schema();
				elseif ( $state['schema']['state'] !== 'failed' || ! empty( $state['schema']['retryable'] ) )
					$this->migrate_schema();
			}

			$this->schedule_schema_sweep();
		} finally {
			$this->release_schema_sweep_lock();
		}
	}

	/**
	 * Acquire one connection-owned, non-blocking schema-sweep lock.
	 *
	 * @return bool
	 */
	private function acquire_schema_sweep_lock() {
		global $wpdb;

		if ( $this->schema_sweep_lock_held )
			return false;

		$name = 'pvc_visits_sweep_' . md5( DB_NAME . ':' . get_current_network_id() );
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );

		if ( (string) $acquired !== '1' )
			return false;

		$this->schema_sweep_lock_held = true;
		return true;
	}

	/**
	 * Release the schema-sweep lock held by this database connection.
	 *
	 * @return void
	 */
	private function release_schema_sweep_lock() {
		global $wpdb;

		if ( ! $this->schema_sweep_lock_held )
			return;

		$name = 'pvc_visits_sweep_' . md5( DB_NAME . ':' . get_current_network_id() );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		$this->schema_sweep_lock_held = false;
	}

	/**
	 * Re-arm the bounded sweep from an admin request when required.
	 *
	 * @return void
	 */
	public function ensure_schema_sweep_scheduled() {
		if ( wp_installing() || ! is_main_site() )
			return;

		$this->schedule_schema_sweep();
	}

	/**
	 * Schedule or clear the bounded migration sweep according to current state.
	 *
	 * @return bool
	 */
	public function schedule_schema_sweep() {
		if ( ! is_main_site() )
			return false;

		if ( ! $this->schema_sweep_needed() ) {
			$this->clear_schema_sweep();
			return false;
		}

		if ( wp_next_scheduled( self::CRON_HOOK ) !== false )
			return true;

		return (bool) wp_schedule_event( $this->now() + MINUTE_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
	}

	/**
	 * Clear the migration sweep for the current site.
	 *
	 * @return int|false
	 */
	public function clear_schema_sweep() {
		return wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Reset/arm migration state after this plugin is activated.
	 *
	 * @param string $plugin Plugin basename.
	 * @param bool   $network_wide Whether activation is network-wide.
	 * @return void
	 */
	public function handle_activated_plugin( $plugin, $network_wide ) {
		if ( ! defined( 'POST_VIEWS_COUNTER_BASENAME' ) || $plugin !== POST_VIEWS_COUNTER_BASENAME )
			return;

		if ( is_multisite() && $network_wide )
			$this->reset_network_schema_migration();

		$this->schedule_schema_sweep();
	}

	/**
	 * Re-arm migration after this plugin is updated without doing DDL in the
	 * upgrader request itself.
	 *
	 * @param object $upgrader Upgrader instance.
	 * @param array  $options Upgrade result metadata.
	 * @return void
	 */
	public function handle_upgrader_process_complete( $upgrader, $options ) {
		if ( empty( $options['type'] ) || $options['type'] !== 'plugin' || empty( $options['action'] ) || $options['action'] !== 'update' )
			return;

		$plugins = [];

		if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) )
			$plugins = $options['plugins'];
		elseif ( ! empty( $options['plugin'] ) )
			$plugins = [ $options['plugin'] ];

		if ( ! defined( 'POST_VIEWS_COUNTER_BASENAME' ) || ! in_array( POST_VIEWS_COUNTER_BASENAME, $plugins, true ) )
			return;

		if ( $this->is_network_active() )
			$this->reset_network_schema_migration();
		else {
			$state = $this->get_state();

			if ( $state['schema']['state'] === 'ready' )
				$this->verify_schema();
		}

		$this->schedule_schema_sweep();
	}

	/**
	 * Reconcile writer support when the shared-schema companion is activated or
	 * deactivated.
	 * Deactivation needs an explicit override because the old class remains
	 * loaded until the current PHP request ends.
	 *
	 * @param string $plugin Plugin basename.
	 * @param bool   $network_wide Whether the change is network-wide.
	 * @return void
	 */
	public function handle_plugin_state_change( $plugin, $network_wide = false ) {
		if ( ! $this->is_shared_content_schema_plugin( $plugin ) )
			return;

		if ( is_multisite() && $network_wide )
			$this->reset_network_schema_migration();

		$this->invalidate_shared_table_shape();

		$this->refresh_availability(
			true,
			null,
			[ 'pro_active' => current_filter() !== 'deactivated_plugin' ]
		);
	}

	/**
	 * Reconcile live-write capability after a General settings change.
	 *
	 * @param mixed  $old_value Previous option value.
	 * @param mixed  $value New option value.
	 * @param string $option Option name.
	 * @return void
	 */
	public function handle_general_settings_updated( $old_value, $value, $option = '' ) {
		$value = is_array( $value ) ? $value : [];
		$pvc = Post_Views_Counter();
		$settings = array_merge( $pvc->defaults['general'], $value );

		if ( ! is_multisite() || (int) get_current_blog_id() === $this->origin_blog_id )
			$pvc->options['general'] = $settings;

		$this->refresh_availability( true, $settings );
	}

	/**
	 * Reconcile live-write capability when General settings are first created.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value Option value.
	 * @return void
	 */
	public function handle_general_settings_added( $option, $value ) {
		$value = is_array( $value ) ? $value : [];
		$pvc = Post_Views_Counter();
		$settings = array_merge( $pvc->defaults['general'], $value );

		if ( ! is_multisite() || (int) get_current_blog_id() === $this->origin_blog_id )
			$pvc->options['general'] = $settings;

		$this->refresh_availability( true, $settings );
	}

	/**
	 * Add and verify the Visits column for the current site.
	 *
	 * The method is intentionally column-guarded rather than version-keyed.
	 * Normal frontend counting never invokes it; site initialization may invoke
	 * it after dbDelta has created a new empty table.
	 *
	 * @param bool $force Whether to bypass the failed-attempt retry interval.
	 * @return bool
	 */
	public function migrate_schema( $force = false ) {
		return $this->migrate_schema_status( $force ) === 'ready';
	}

	/**
	 * Add and verify the Visits column and return a tri-state result.
	 *
	 * @param bool $force Whether to bypass the failed-attempt retry interval.
	 * @return string ready, unknown, or failed.
	 */
	protected function migrate_schema_status( $force = false ) {
		global $wpdb;

		$state = $this->get_state();
		$now = $this->now();
		$last_probe_error_at = (int) $state['schema']['last_probe_error_at'];

		if ( ! $force && $last_probe_error_at > 0 && $now - $last_probe_error_at < self::SCHEMA_RETRY_INTERVAL )
			return 'unknown';

		if ( ! $force && $state['schema']['state'] === 'ready' )
			return 'ready';

		if ( ! $force && $state['schema']['state'] === 'pending' && (int) $state['schema']['last_attempt_at'] > 0 && $now - (int) $state['schema']['last_attempt_at'] < self::SCHEMA_PENDING_LEASE )
			return 'unknown';

		if ( $state['schema']['state'] === 'failed' && empty( $state['schema']['retryable'] ) )
			return 'failed';

		if ( ! $force && $state['schema']['state'] === 'failed' && (int) $state['schema']['last_attempt_at'] > 0 && $now - (int) $state['schema']['last_attempt_at'] < self::SCHEMA_RETRY_INTERVAL )
			return 'failed';

		$table = $wpdb->prefix . 'post_views';
		$table_probe = $this->probe_table( $table );

		if ( $table_probe['status'] === 'error' ) {
			$this->mark_schema_probe_unknown( $state );
			return 'unknown';
		}

		if ( $table_probe['status'] === 'missing' ) {
			$this->mark_schema_failed( $state, 'table_missing' );
			return 'failed';
		}

		$column_probe = $this->probe_visits_column( $table );

		if ( $column_probe['status'] === 'error' ) {
			$this->mark_schema_probe_unknown( $state );
			return 'unknown';
		}

		if ( $column_probe['status'] === 'found' )
			return $this->verify_and_mark_ready( $state, $column_probe['column'] ) ? 'ready' : 'failed';

		$lock_token = $this->acquire_schema_lock();

		if ( $lock_token === false )
			return 'unknown';

		try {
			// Re-probe after the lock in case another request completed the DDL.
			$column_probe = $this->probe_visits_column( $table );

			if ( $column_probe['status'] === 'error' ) {
				$this->mark_schema_probe_unknown( $state );
				return 'unknown';
			}

			if ( $column_probe['status'] === 'found' )
				return $this->verify_and_mark_ready( $state, $column_probe['column'] ) ? 'ready' : 'failed';

			$pending = $this->mutate_state(
				function( $latest ) use ( $now ) {
					if ( in_array( $latest['reason'], [ 'reset_in_progress', 'core_deactivated' ], true ) )
						return $latest;

					$latest['state'] = 'pending';
					$latest['reason'] = 'schema_pending';
					$latest['schema']['state'] = 'pending';
					$latest['schema']['retryable'] = true;
					$latest['schema']['last_attempt_at'] = $now;
					$latest['schema']['last_status'] = 'checking';
					$latest['schema']['last_error'] = '';
					$latest['versions'] = $this->get_active_versions();
					return $latest;
				},
				true
			);

			if ( ! $pending['success'] || in_array( $pending['state']['reason'], [ 'reset_in_progress', 'core_deactivated' ], true ) )
				return 'unknown';

			$state = $pending['state'];
			$ddl_state = $this->read_durable_state( true );

			if ( $ddl_state === false || ! $this->is_current_normal_state_raw( $ddl_state['value'] ) || $this->is_deactivation_tombstone_raw( $ddl_state['value'] ) )
				return 'unknown';

			$this->add_visits_column( $table );

			// A concurrent upgrader may have added the column even if this query failed.
			$column_probe = $this->probe_visits_column( $table );

			if ( $column_probe['status'] === 'error' ) {
				$this->mark_schema_probe_unknown( $state );
				return 'unknown';
			}

			if ( $column_probe['status'] === 'missing' ) {
				$this->mark_schema_failed( $state, 'column_add_failed' );
				return 'failed';
			}

			return $this->verify_and_mark_ready( $state, $column_probe['column'] ) ? 'ready' : 'failed';
		} finally {
			$this->release_schema_lock( $lock_token );
		}
	}

	/**
	 * Add the canonical Visits column at the end of the table so supported
	 * engines can use their cheapest additive-column algorithm.
	 *
	 * @param string $table Table name derived from the WordPress prefix.
	 * @return int|bool
	 */
	protected function add_visits_column( $table ) {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );

		try {
			return $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `visits` BIGINT UNSIGNED NOT NULL DEFAULT 0" );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * Read-only schema verification for an already migrated site.
	 *
	 * A database probe error leaves readiness and the compatibility timestamp
	 * unchanged, records a bounded backoff timestamp, and returns unknown.
	 * Confirmed absence or incompatibility returns failed.
	 *
	 * @return string ready, unknown, or failed.
	 */
	public function verify_schema() {
		global $wpdb;

		$state = $this->get_state();
		$table = $wpdb->prefix . 'post_views';
		$table_probe = $this->probe_table( $table );

		if ( $table_probe['status'] === 'error' ) {
			$this->mark_schema_probe_unknown( $state );
			return 'unknown';
		}

		if ( $table_probe['status'] === 'missing' ) {
			$this->mark_schema_failed( $state, 'table_missing' );
			return 'failed';
		}

		$column_probe = $this->probe_visits_column( $table );

		if ( $column_probe['status'] === 'error' ) {
			$this->mark_schema_probe_unknown( $state );
			return 'unknown';
		}

		if ( $column_probe['status'] === 'missing' ) {
			$this->mark_schema_failed( $state, 'column_missing' );
			return 'failed';
		}

		return $this->verify_and_mark_ready( $state, $column_probe['column'] ) ? 'ready' : 'failed';
	}

	/**
	 * Reset the bounded network migration cursor after network activation/update.
	 *
	 * @return bool
	 */
	public function reset_network_schema_migration() {
		if ( ! is_multisite() )
			return true;

		update_network_option( get_current_network_id(), self::NETWORK_OPTION_NAME, $this->get_default_network_status() );
		$this->schedule_schema_sweep();

		return true;
	}

	/**
	 * Remove per-site migration state through the plugin's established
	 * deactivation-delete lifecycle.
	 *
	 * @return bool|string Whether stale Visit cache namespaces were safely retired,
	 *                     `measurement_fence_unlock_failed`, or
	 *                     `schema_lease_retry_blocked` when the caller must stop
	 *                     all deactivation work.
	 */
	public function delete_site_state() {
		if ( function_exists( 'Post_Views_Counter_Pro' ) ) {
			$pro = Post_Views_Counter_Pro();
			$counter = isset( $pro->counter ) ? $pro->counter : null;

			if ( ! is_object( $counter ) || ! method_exists( $counter, 'supports_queue_tombstone' ) || ! $counter->supports_queue_tombstone() )
				return false;
		}

		$durable = $this->read_durable_state( true );

		if ( $this->is_cache_repair_state_raw( $durable === false ? null : $durable['value'] ) ) {
			$repair = $this->repair_cache_state( $durable );

			if ( ! $repair['success'] )
				return false;

			$durable = $repair['record'];
		}

		if ( $durable === false || ( ! $this->is_current_normal_state_raw( $durable['value'] ) && ! $this->is_deactivation_tombstone_raw( $durable['value'] ) ) )
			return false;

		$retrying_tombstone = $this->is_deactivation_tombstone_raw( $durable['value'] );

		$lock_token = $this->acquire_schema_lock();

		if ( $lock_token === false || ! $this->acquire_measurement_reset_lock() ) {
			if ( $lock_token !== false )
				$this->release_schema_lock( $lock_token );

			if ( $lock_token === false && $retrying_tombstone && $this->is_schema_lease_active() )
				return 'schema_lease_retry_blocked';

			return false;
		}

		$this->lifecycle_schema_lock_token = $lock_token;
		$success = false;
		$fence_result = [ 'unlocked' => false, 'flushed' => false ];

		$cache_token = class_exists( 'Post_Views_Counter_Visits_Query' ) ? [
			'version' => 1,
			'token' => wp_generate_uuid4(),
			'updated_at' => time()
		] : null;

		try {
			if ( $cache_token === null || $this->rotate_durable_cache_token_fenced( $cache_token ) ) {
				$result = $this->mutate_state(
					function( $state, $record ) {
						if ( $this->is_deactivation_tombstone_raw( $record['value'] ) )
							return $record['value'];

						return $this->build_deactivation_tombstone( (int) $state['queue_generation'] + 1 );
					},
					true,
					true,
					true
				);
				$success = $result['success'];
			}
		} finally {
			$fence_result = $this->end_measurement_fence();
		}

		if ( empty( $fence_result['unlocked'] ) || empty( $fence_result['flushed'] ) )
			$success = false;

		// The failed SQL unlock retains this connection's restrictive fence. The
		// tombstone, schema lease, and deferred FIFO are the retry obligations; a
		// lifecycle wrapper must not continue into post-migration scheduling cleanup.
		if ( $this->is_measurement_fence_active() )
			return 'measurement_fence_unlock_failed';

		// A failed unlock leaves the restrictive connection fence in force. Its
		// schema lease is a durable recovery obligation and release_schema_lock()
		// reaches the Options API, so it must remain untouched until a later
		// successful unlock.
		if ( ! $success && ! $this->is_measurement_fence_active() )
			$this->finish_site_state_deletion();

		return $success;
	}

	/**
	 * Rotate the read-cache fallback namespace without invoking the Options API
	 * while the destructive table fence is held.
	 *
	 * @param array $state New durable namespace record prepared before the fence.
	 * @return bool
	 */
	private function rotate_durable_cache_token_fenced( $state ) {
		global $wpdb;

		if ( ! $this->is_measurement_fence_active()
			|| ! is_array( $state )
			|| ! $this->has_exact_state_keys( $state, [ 'version', 'token', 'updated_at' ] )
			|| $state['version'] !== 1
			|| ! is_string( $state['token'] )
			|| $state['token'] === ''
			|| ! $this->is_nonnegative_integer( $state['updated_at'] ) )
			return false;

		$serialized = serialize( $state );
		$result = $wpdb->query( $wpdb->prepare( "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s", $serialized, Post_Views_Counter_Visits_Query::CACHE_FALLBACK_OPTION ) );

		if ( $result === 0 ) {
			$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')", Post_Views_Counter_Visits_Query::CACHE_FALLBACK_OPTION, $serialized ) );
		}

		if ( $result !== 1 )
			return false;

		$this->defer_measurement_fence_work( [
			'type' => 'option_caches',
			'keys' => [ Post_Views_Counter_Visits_Query::CACHE_FALLBACK_OPTION, 'notoptions', 'alloptions' ]
		] );

		return true;
	}

	/**
	 * Release the lifecycle schema lease after DROP/probe completes.
	 *
	 * @return void
	 */
	public function finish_site_state_deletion() {
		if ( $this->is_measurement_fence_active() )
			return;

		if ( $this->lifecycle_schema_lock_token !== false ) {
			$this->release_schema_lock( $this->lifecycle_schema_lock_token );
			$this->lifecycle_schema_lock_token = false;
		}
	}

	/**
	 * Identify the owner-approved exact raw destructive-deactivation tombstone.
	 *
	 * @param mixed $value Raw unserialized option value.
	 * @return bool
	 */
	private function is_deactivation_tombstone_raw( $value ) {
		if ( ! is_array( $value ) )
			return false;

		$expected = $this->build_deactivation_tombstone( isset( $value['queue_generation'] ) ? (int) $value['queue_generation'] : -1 );

		return array_keys( $value ) === array_keys( $expected ) && $value === $expected;
	}

	/** @return array */
	private function build_deactivation_tombstone( $generation ) {
		return [
			'format_version' => 1,
			'state' => 'unavailable',
			'reason' => 'core_deactivated',
			'derived_since' => null,
			'queue_generation' => max( 0, (int) $generation )
		];
	}

	/**
	 * Identify a complete normal state held behind the transient cache-repair fence.
	 *
	 * Format version 0 was never a public format. It is reserved for this bounded
	 * crash-recovery transition and is accepted only with the complete current
	 * normal-state subtrees.
	 *
	 * @param mixed $value Raw unserialized option value.
	 * @return bool
	 */
	private function is_cache_repair_state_raw( $value ) {
		if ( ! is_array( $value ) || ! array_key_exists( 'format_version', $value ) || $value['format_version'] !== 0 )
			return false;

		$canonical = $this->canonicalize_state_shape( $value );
		$schema = isset( $value['schema'] ) && is_array( $value['schema'] ) ? $value['schema'] : [];
		$writer = isset( $value['writer'] ) && is_array( $value['writer'] ) ? $value['writer'] : [];
		$storage = isset( $value['storage'] ) && is_array( $value['storage'] ) ? $value['storage'] : [];
		$versions = isset( $value['versions'] ) && is_array( $value['versions'] ) ? $value['versions'] : [];

		return $value === $canonical
			&& in_array( $value['state'], [ 'pending', 'ready', 'unavailable' ], true )
			&& is_string( $value['reason'] )
			&& $value['reason'] !== ''
			&& $this->is_raw_state_key( $value['reason'] )
			&& in_array( $schema['state'], [ 'pending', 'ready', 'failed' ], true )
			&& is_bool( $schema['retryable'] )
			&& ( is_bool( $schema['content_column'] ) || $schema['content_column'] === null )
			&& is_string( $schema['last_status'] )
			&& is_string( $schema['last_error'] )
			&& $this->is_nonnegative_integer( $schema['last_attempt_at'] )
			&& $this->is_nonnegative_integer( $schema['last_probe_error_at'] )
			&& in_array( $writer['state'], [ 'ready', 'unsupported' ], true )
			&& is_string( $writer['reason'] )
			&& $this->is_nonnegative_integer( $writer['last_checked_at'] )
			&& in_array( $storage['state'], [ 'ready', 'incapable', 'unknown' ], true )
			&& is_string( $storage['reason'] )
			&& ( $value['derived_since'] === null || $this->is_positive_integer( $value['derived_since'] ) )
			&& $this->is_nonnegative_integer( $value['queue_generation'] )
			&& $this->is_nonnegative_integer( $value['last_compatibility_check'] )
			&& is_string( $versions['core'] )
			&& is_string( $versions['pro'] );
	}

	/**
	 * Accept only the exact current persisted state before it authorizes a write.
	 *
	 * @param mixed $value Raw unserialized option value.
	 * @return bool
	 */
	public function is_current_normal_state_raw( $value ) {
		if ( ! is_array( $value ) )
			return false;

		$expected = $this->normalize_state( [] );

		if ( ! $this->has_exact_state_keys( $value, array_keys( $expected ) ) )
			return false;

		$schema = $value['schema'];
		$writer = $value['writer'];
		$storage = $value['storage'];
		$versions = $value['versions'];

		return is_int( $value['format_version'] )
			&& $value['format_version'] === 1
			&& in_array( $value['state'], [ 'pending', 'ready', 'unavailable' ], true )
			&& is_string( $value['reason'] )
			&& $value['reason'] !== ''
			&& $this->is_raw_state_key( $value['reason'] )
			&& $this->has_exact_state_keys( $schema, array_keys( $expected['schema'] ) )
			&& in_array( $schema['state'], [ 'pending', 'ready', 'failed' ], true )
			&& is_bool( $schema['retryable'] )
			&& ( is_bool( $schema['content_column'] ) || $schema['content_column'] === null )
			&& is_string( $schema['last_status'] )
			&& is_string( $schema['last_error'] )
			&& $this->is_nonnegative_integer( $schema['last_attempt_at'] )
			&& $this->is_nonnegative_integer( $schema['last_probe_error_at'] )
			&& $this->has_exact_state_keys( $writer, array_keys( $expected['writer'] ) )
			&& in_array( $writer['state'], [ 'ready', 'unsupported' ], true )
			&& is_string( $writer['reason'] )
			&& $writer['reason'] !== ''
			&& $this->is_raw_state_key( $writer['reason'] )
			&& $this->is_nonnegative_integer( $writer['last_checked_at'] )
			&& $this->has_exact_state_keys( $storage, array_keys( $expected['storage'] ) )
			&& in_array( $storage['state'], [ 'ready', 'incapable', 'unknown' ], true )
			&& is_string( $storage['reason'] )
			&& $storage['reason'] !== ''
			&& $this->is_raw_state_key( $storage['reason'] )
			&& ( $value['derived_since'] === null || $this->is_positive_integer( $value['derived_since'] ) )
			&& $this->is_nonnegative_integer( $value['queue_generation'] )
			&& $this->is_nonnegative_integer( $value['last_compatibility_check'] )
			&& $this->has_exact_state_keys( $versions, array_keys( $expected['versions'] ) )
			&& is_string( $versions['core'] )
			&& is_string( $versions['pro'] );
	}

	/**
	 * Return whether an array has exactly the required keys, regardless of order.
	 *
	 * @param mixed $value Candidate value.
	 * @param array $expected_keys Required keys.
	 * @return bool
	 */
	private function has_exact_state_keys( $value, $expected_keys ) {
		return is_array( $value )
			&& count( $value ) === count( $expected_keys )
			&& ! array_diff_key( $value, array_flip( $expected_keys ) )
			&& ! array_diff_key( array_flip( $expected_keys ), $value );
	}

	/**
	 * Remove fields outside the current internal state format and normalize values.
	 *
	 * @param array $state Candidate state.
	 * @return array
	 */
	private function canonicalize_state_shape( $state ) {
		$shape = $this->normalize_state( [] );
		$state = array_intersect_key( $this->normalize_state( $state ), $shape );

		foreach ( [ 'schema', 'writer', 'storage', 'versions' ] as $key )
			$state[$key] = array_intersect_key( $state[$key], $shape[$key] );

		return $state;
	}

	/** @return bool */
	private function is_nonnegative_integer( $value ) {
		return is_int( $value ) && $value >= 0;
	}

	/** @return bool */
	private function is_positive_integer( $value ) {
		return is_int( $value ) && $value > 0;
	}

	/**
	 * Validate a persisted lowercase key without invoking sanitize_key(), whose
	 * WordPress filter is unsafe while this connection is table-fenced.
	 *
	 * @param mixed $value Candidate key.
	 * @return bool
	 */
	private function is_raw_state_key( $value ) {
		return is_string( $value ) && $value !== '' && preg_match( '/^[a-z0-9_-]+$/', $value ) === 1;
	}

	/**
	 * Derive an unavailable response without changing the intended durable state.
	 *
	 * @param array $state Normalized state held behind the repair fence.
	 * @return array
	 */
	private function make_cache_repair_unavailable( $state ) {
		$state['state'] = 'unavailable';
		$state['derived_since'] = null;

		if ( $state['reason'] !== 'reset_in_progress' )
			$state['reason'] = 'state_format_unsupported';

		return $state;
	}

	/**
	 * Return whether activation is allowed to initialize/replace current state.
	 *
	 * @param bool $explicit Whether this is explicit plugin activation.
	 * @return string|false normal, tombstone, or false.
	 */
	public function prepare_site_activation( $explicit ) {
		$lock_token = $this->acquire_schema_lock();

		if ( $lock_token === false )
			return false;

		$this->lifecycle_schema_lock_token = $lock_token;
		$durable = $this->read_durable_state( true );

		if ( $this->is_cache_repair_state_raw( $durable === false ? null : $durable['value'] ) ) {
			$repair = $this->repair_cache_state( $durable );

			if ( ! $repair['success'] ) {
				$this->finish_site_state_deletion();
				return false;
			}

			$durable = $repair['record'];
		}

		if ( $durable === false || ( $durable['exists'] && ! $this->is_current_normal_state_raw( $durable['value'] ) && ! $this->is_deactivation_tombstone_raw( $durable['value'] ) ) ) {
			$this->finish_site_state_deletion();
			return false;
		}

		if ( $this->is_deactivation_tombstone_raw( $durable['value'] ) ) {
			if ( ! $explicit ) {
				$this->finish_site_state_deletion();
				return false;
			}

			return 'tombstone';
		}

		return 'normal';
	}

	/** @return void */
	public function finish_site_activation() {
		$this->finish_site_state_deletion();
	}

	/**
	 * Clear only an exact tombstone after an empty table has been verified.
	 *
	 * @return bool
	 */
	public function complete_site_activation() {
		$result = $this->mutate_state(
			function( $state, $record ) {
				if ( ! $this->is_deactivation_tombstone_raw( $record['value'] ) )
					return $record['value'];

				$next = $this->normalize_state( [] );
				$next['queue_generation'] = (int) $state['queue_generation'];
				return $next;
			},
			true,
			true,
			true
		);

		return $result['success'];
	}

	/**
	 * Remove network migration state only during network deactivation.
	 *
	 * @return void
	 */
	public function delete_network_state() {
		if ( is_multisite() )
			delete_network_option( get_current_network_id(), self::NETWORK_OPTION_NAME );
	}

	/**
	 * Get the bounded per-site Visits state.
	 *
	 * @return array
	 */
	public function get_state() {
		$durable = $this->read_durable_state();

		if ( $durable !== false && $this->is_cache_repair_state_raw( $durable['value'] ) )
			return $this->make_cache_repair_unavailable( $durable['state'] );

		if ( $durable !== false )
			return $durable['state'];

		return $this->normalize_state( get_option( self::OPTION_NAME, [] ) );
	}

	/**
	 * Normalize one persisted state value without changing its raw representation.
	 *
	 * @param mixed $state Persisted value.
	 * @return array
	 */
	private function normalize_state( $state ) {
		$state = is_array( $state ) ? $state : [];

		$defaults = [
			'format_version' => 1,
			'state' => 'pending',
			'reason' => 'schema_pending',
			'schema' => [
				'state' => 'pending',
				'retryable' => true,
				'content_column' => null,
				'last_status' => 'not_checked',
				'last_error' => '',
				'last_attempt_at' => 0,
				'last_probe_error_at' => 0
			],
			'writer' => [
				'state' => 'unsupported',
				'reason' => 'writer_unsupported',
				'last_checked_at' => 0
			],
			'storage' => [
				'state' => 'unknown',
				'reason' => 'storage_incapable'
			],
			'derived_since' => null,
			'queue_generation' => 0,
			'last_compatibility_check' => 0,
			'versions' => [
				'core' => '',
				'pro' => ''
			]
		];

		$state = array_merge( $defaults, $state );
		$state['schema'] = array_merge( $defaults['schema'], is_array( $state['schema'] ) ? $state['schema'] : [] );
		$state['writer'] = array_merge( $defaults['writer'], is_array( $state['writer'] ) ? $state['writer'] : [] );
		$state['storage'] = array_merge( $defaults['storage'], is_array( $state['storage'] ) ? $state['storage'] : [] );
		$state['derived_since'] = is_numeric( $state['derived_since'] ) && (int) $state['derived_since'] > 0 ? (int) $state['derived_since'] : null;
		$state['queue_generation'] = max( 0, (int) $state['queue_generation'] );
		$state['versions'] = array_merge( $defaults['versions'], is_array( $state['versions'] ) ? $state['versions'] : [] );

		return $state;
	}

	/**
	 * Read the authoritative per-site state row, bypassing the Options cache.
	 *
	 * @param bool $force Force another database read in this request.
	 * @return array|false
	 */
	private function read_durable_state( $force = false ) {
		global $wpdb;

		$blog_id = is_multisite() ? (int) get_current_blog_id() : 0;

		if ( ! $force && isset( $this->durable_state_cache[$blog_id] ) )
			return $this->durable_state_cache[$blog_id];

		$wpdb->last_error = '';
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", self::OPTION_NAME ) );

		if ( $wpdb->last_error !== '' )
			return false;

		$value = $raw === null ? [] : ( $this->is_measurement_fence_active() ? $this->decode_fenced_serialized_array( $raw ) : maybe_unserialize( $raw ) );

		if ( $value === false )
			return false;
		$record = [
			'exists' => $raw !== null,
			'raw' => $raw,
			'value' => $value,
			'state' => $this->normalize_state( $value )
		];
		$this->durable_state_cache[$blog_id] = $record;

		return $record;
	}

	/**
	 * Decode an option array without object construction or WordPress helpers.
	 * This narrow decoder is the only serialized-value reader permitted while the
	 * restrictive measurement fence is active.
	 *
	 * @param mixed $raw Raw option value.
	 * @return array|false
	 */
	private function decode_fenced_serialized_array( $raw ) {
		if ( ! is_string( $raw ) || $raw === '' || strpos( $raw, 'a:' ) !== 0 || substr( $raw, -1 ) !== '}' )
			return false;

		set_error_handler( static function() {
			return true;
		} );

		try {
			$value = unserialize( $raw, [ 'allowed_classes' => false ] );
		} finally {
			restore_error_handler();
		}

		return is_array( $value ) && serialize( $value ) === $raw ? $value : false;
	}

	/**
	 * Read the durable per-site queue generation without the Options API cache.
	 *
	 * @return int|false Generation, or false when the durable read fails.
	 */
	public function get_queue_generation() {
		$durable = $this->read_durable_state( true );

		if ( $durable === false || ( ! $this->is_current_normal_state_raw( $durable['value'] ) && ! $this->is_deactivation_tombstone_raw( $durable['value'] ) ) )
			return false;

		return (int) $durable['state']['queue_generation'];
	}

	/**
	 * Get a generation only while new shared queue work may be published.
	 *
	 * @return int|false
	 */
	public function get_queue_publish_generation() {
		$durable = $this->read_durable_state( true );

		if ( $durable === false || ! $this->is_current_normal_state_raw( $durable['value'] ) )
			return false;

		if ( $durable['state']['reason'] === 'reset_in_progress' || $this->is_deactivation_tombstone_raw( $durable['value'] ) )
			return false;

		return (int) $durable['state']['queue_generation'];
	}

	/**
	 * Capture the raw durable state token used to fence a direct shared-row write.
	 *
	 * @param bool $force Force a fresh database read.
	 * @return array|false
	 */
	public function get_write_fence_token( $force = false ) {
		$durable = $this->read_durable_state( (bool) $force );

		if ( $durable === false || ! $this->is_current_normal_state_raw( $durable['value'] ) )
			return false;

		if ( $durable['state']['reason'] === 'reset_in_progress' || $this->is_deactivation_tombstone_raw( $durable['value'] ) )
			return false;

		return [
			'exists' => $durable['exists'],
			'raw' => $durable['raw'],
			'generation' => (int) $durable['state']['queue_generation']
		];
	}

	/**
	 * Prevent public count reads while a destructive tombstone is authoritative.
	 *
	 * @return bool
	 */
	public function is_site_data_accessible() {
		$durable = $this->read_durable_state();

		return $durable !== false
			&& ( ! $durable['exists'] || ( $this->is_current_normal_state_raw( $durable['value'] ) && ! $this->is_deactivation_tombstone_raw( $durable['value'] ) ) );
	}

	/**
	 * Resolve the three independent Visit availability axes.
	 *
	 * Raw readers use readable/read_reason. Live counters and queues use
	 * writable/write_reason. Ratios and other Visit-derived calculations use
	 * derived_available/derived_reason with an explicit range. The deprecated
	 * interval/fingerprint coverage model deliberately has no representation
	 * here: stored Visit rows are read exactly like stored View rows.
	 *
	 * @param array|null $range Optional explicit start/end range.
	 * @param bool       $force Bypass the request-local cache and re-evaluate.
	 * @return array
	 */
	public function get_visits_availability( $range = null, $force = false ) {
		$normalized_range = $range === null ? null : $this->normalize_range( $range );
		$range_identity = $range === null ? 'none' : ( $normalized_range === null ? 'invalid' : $normalized_range['start'] . ':' . $normalized_range['end'] );
		$cache_key = ( is_multisite() ? get_current_blog_id() : 0 ) . ':' . md5( $range_identity );
		$now = $this->now();

		// Reuse only within the same clock second so reset and lifecycle changes
		// are never hidden by a request-local result.
		if ( ! $force && isset( $this->availability_cache[$cache_key] ) && (int) $this->availability_cache[$cache_key]['checked_at'] === $now )
			return $this->availability_cache[$cache_key]['value'];

		$state = $this->refresh_availability( (bool) $force );
		$fenced_reason = in_array( $state['reason'], [ 'reset_in_progress', 'core_deactivated' ], true ) ? $state['reason'] : '';
		$readable = $fenced_reason === '' && $state['schema']['state'] === 'ready';

		if ( $readable )
			$read_reason = 'ready';
		elseif ( $fenced_reason !== '' )
			$read_reason = $fenced_reason;
		elseif ( $state['schema']['state'] === 'pending' )
			$read_reason = 'schema_pending';
		else
			$read_reason = 'schema_failed';

		$writable = $readable && $state['state'] === 'ready' && $state['reason'] === 'ready';
		$write_reason = $writable ? 'ready' : ( $readable ? $state['reason'] : $read_reason );
		$derived_available = false;
		$derived_reason = 'explicit_range_required';

		if ( $normalized_range === null && $range !== null )
			$derived_reason = 'invalid_range';
		elseif ( ! $readable )
			$derived_reason = $read_reason;
		elseif ( ! $writable )
			$derived_reason = $write_reason;
		elseif ( $state['derived_since'] === null )
			$derived_reason = 'derived_not_ready';
		elseif ( $normalized_range !== null && $normalized_range['start'] < (int) $state['derived_since'] )
			$derived_reason = 'before_derived_since';
		elseif ( $normalized_range !== null ) {
			$derived_available = true;
			$derived_reason = 'ready';
		}

		$result = [
			'readable' => $readable,
			'read_reason' => $read_reason,
			'writable' => $writable,
			'write_reason' => $write_reason,
			'derived_since' => $state['derived_since'],
			'derived_available' => $derived_available,
			'derived_reason' => $derived_reason
		];

		// Replace, never append: an elapsed second must not grow the cache.
		$this->availability_cache[$cache_key] = [ 'checked_at' => $now, 'value' => $result ];

		return $result;
	}

	/**
	 * Reconcile the state with current operational semantics without querying the
	 * schema. The backing option and this instance cache make normal reads cheap;
	 * unchanged frontend requests perform no writes.
	 *
	 * @param bool       $force Bypass the durable-state request cache.
	 * @param array|null $settings Optional General settings snapshot.
	 * @param array      $runtime_overrides Controlled lifecycle/test overrides.
	 * @return array
	 */
	public function refresh_availability( $force = false, $settings = null, $runtime_overrides = [] ) {
		$now = $this->now();
		// Ordinary availability derivation shares the verified request snapshot.
		// Forced lifecycle/publication/recovery paths still require a fresh read.
		$initial = $this->read_durable_state( $force );

		if ( $initial === false ) {
			$state = $this->get_state();
			$state['persistence_success'] = false;
			return $state;
		}

		if ( $this->is_cache_repair_state_raw( $initial['value'] ) ) {
			$repair = $this->repair_cache_state( $initial );

			if ( ! $repair['success'] ) {
				$state = $this->make_cache_repair_unavailable( $repair['state'] );
				$state['persistence_success'] = false;
				return $state;
			}

			$initial = $repair['record'];
		}

		if ( $this->is_deactivation_tombstone_raw( $initial['value'] ) )
			return $initial['state'];

		if ( $initial['exists'] && ! $this->is_current_normal_state_raw( $initial['value'] ) ) {
			$state = $initial['state'];
			$state['state'] = 'unavailable';
			$state['reason'] = 'state_format_unsupported';
			$state['derived_since'] = null;
			return $state;
		}

		if ( $initial['state']['reason'] === 'reset_in_progress' )
			return $initial['state'];

		$reconcile = function( $state, $record, $attempt ) use ( $settings, $runtime_overrides, $now ) {
			if ( $state['reason'] === 'reset_in_progress' || $this->is_deactivation_tombstone_raw( $record['value'] ) )
				return $state;

			$original = $state;

			if ( $attempt === 0 )
				$attempt_settings = is_array( $settings ) ? $settings : null;
			else {
				$settings_record = $this->read_option_durable( 'post_views_counter_settings_general' );

				if ( $settings_record === false )
					return null;

				$attempt_settings = $settings_record['exists'] && is_array( $settings_record['value'] ) ? $settings_record['value'] : null;
			}

			$attempt_settings = is_array( $attempt_settings ) ? $attempt_settings : null;
			$attempt_settings = $this->get_general_settings( $attempt_settings );
			$writer = $this->get_writer_status( $runtime_overrides );
			$storage = $this->get_storage_status( $attempt_settings );
			$pvc = Post_Views_Counter();
			$interval = $pvc->normalize_time_between_counts(
				isset( $attempt_settings['time_between_counts'] ) ? $attempt_settings['time_between_counts'] : null,
				$pvc->defaults['general']['time_between_counts']
			);
			$interval_seconds = max( 0, (int) $interval['number'] ) * HOUR_IN_SECONDS;
			$was_writable = $state['state'] === 'ready' && $state['reason'] === 'ready';

			$state['writer']['state'] = $writer['supported'] ? 'ready' : 'unsupported';
			$state['writer']['reason'] = $writer['reason'];
			$state['storage']['state'] = $storage['capable'] ? 'ready' : 'incapable';
			$state['storage']['reason'] = $storage['reason'];
			$state['versions'] = $this->get_active_versions();

			if ( $interval_seconds === 0 )
				$availability_reason = 'interval_zero';
			elseif ( $state['schema']['state'] === 'pending' )
				$availability_reason = 'schema_pending';
			elseif ( $state['schema']['state'] !== 'ready' )
				$availability_reason = 'schema_failed';
			elseif ( ! $writer['supported'] )
				$availability_reason = 'writer_unsupported';
			elseif ( ! $storage['capable'] )
				$availability_reason = 'storage_incapable';
			else
				$availability_reason = 'ready';

			if ( $availability_reason === 'ready' ) {
				if ( $state['derived_since'] === null || ( ! $was_writable && $original['reason'] !== 'reset_complete' ) )
					$state['derived_since'] = $now;

				$state['state'] = 'ready';
				$state['reason'] = 'ready';
			} else {
				$state['state'] = $availability_reason === 'schema_pending' ? 'pending' : 'unavailable';
				$state['reason'] = $availability_reason;
			}

			if ( $state !== $original ) {
				$state['last_compatibility_check'] = $now;
				$state['writer']['last_checked_at'] = $now;
			}

			return $state;
		};
		$preflight = $reconcile( $initial['state'], $initial, 0 );

		if ( ! $force && is_array( $preflight ) && $preflight === $initial['state'] )
			return $initial['state'];

		$result = $this->mutate_state( $reconcile, true, true, false );
		$state = $result['state'];

		if ( ! $result['success'] )
			$state['persistence_success'] = false;

		return $state;
	}

	/**
	 * Mark a destructive lifecycle interruption without changing stored raw
	 * Visits. A later healthy refresh establishes a new derived_since boundary.
	 *
	 * @param string $reason Stable lifecycle reason.
	 * @return array Updated state with persistence status.
	 */
	public function suspend_live_visit_writes( $reason ) {
		$reason = sanitize_key( $reason );

		if ( $reason === '' )
			$reason = 'writer_unsupported';

		$result = $this->mutate_state(
			function( $state ) use ( $reason ) {
				if ( $state['reason'] === 'reset_in_progress' )
					return $state;

				$state['state'] = 'unavailable';
				$state['reason'] = $reason;
				$state['last_compatibility_check'] = $this->now();
				return $state;
			},
			true
		);
		$result['state']['persistence_success'] = $result['success'];
		return $result['state'];
	}

	/**
	 * Move derived_since backward after an importer has successfully written
	 * paired count and visits values. Callers must invoke this only while live
	 * Visit writing is currently available. They own source interpretation,
	 * mapping, merge/override behavior, and continuity: this timestamp asserts
	 * usable paired data continuously through the prior derived window. It is
	 * misuse to call this for an isolated range that does not bridge to that
	 * window.
	 *
	 * Core deliberately trusts the caller and neither reads nor certifies rows.
	 *
	 * @param mixed $timestamp Positive UTC timestamp.
	 * @return array{success:bool,reason:string,state:array}
	 */
	public function set_derived_metrics_since( $timestamp ) {
		$timestamp = is_int( $timestamp ) ? $timestamp : ( is_string( $timestamp ) && ctype_digit( $timestamp ) ? (int) $timestamp : 0 );

		if ( $timestamp < 1 || $timestamp > $this->now() )
			return [ 'success' => false, 'reason' => 'invalid_timestamp', 'state' => $this->get_state() ];

		// Reconcile before authorizing a trusted import. An importer must not
		// extend a derived window while live Visit writes are unavailable.
		$this->refresh_availability( true );
		$initial = $this->read_durable_state( true );

		if ( $initial === false || ! $initial['exists'] )
			return [ 'success' => false, 'reason' => 'state_format_unsupported', 'state' => $initial === false ? $this->get_state() : $initial['state'] ];

		if ( $this->is_deactivation_tombstone_raw( $initial['value'] ) )
			return [ 'success' => false, 'reason' => 'core_deactivated', 'state' => $initial['state'] ];

		if ( ! $this->is_current_normal_state_raw( $initial['value'] ) )
			return [ 'success' => false, 'reason' => 'state_format_unsupported', 'state' => $initial['state'] ];

		$initial_reason = $this->get_live_write_reason( $initial['state'], $initial['value'] );

		if ( $initial_reason !== 'ready' )
			return [ 'success' => false, 'reason' => $initial_reason, 'state' => $initial['state'] ];

		$callback_reason = 'ready';

		$result = $this->mutate_state(
			function( $state, $record ) use ( $timestamp, &$callback_reason ) {
				$callback_reason = $this->get_live_write_reason( $state, $record['value'] );

				if ( $callback_reason !== 'ready' )
					return $state;

				if ( $state['derived_since'] === null || $timestamp < (int) $state['derived_since'] )
					$state['derived_since'] = $timestamp;

				return $state;
			},
			false,
			true
		);

		if ( $callback_reason !== 'ready' )
			return [ 'success' => false, 'reason' => $callback_reason, 'state' => $result['state'] ];

		$final = $this->read_durable_state( true );

		if ( $final === false || ! $final['exists'] )
			return [ 'success' => false, 'reason' => 'state_read_failed', 'state' => $final === false ? $this->get_state() : $final['state'] ];

		if ( $this->is_deactivation_tombstone_raw( $final['value'] ) )
			return [ 'success' => false, 'reason' => 'core_deactivated', 'state' => $final['state'] ];

		if ( ! $this->is_current_normal_state_raw( $final['value'] ) )
			return [ 'success' => false, 'reason' => 'state_format_unsupported', 'state' => $final['state'] ];

		$final_reason = $this->get_live_write_reason( $final['state'], $final['value'] );
		$success = $result['success'] && $final_reason === 'ready';

		return [ 'success' => $success, 'reason' => $success ? 'ready' : ( $final_reason !== 'ready' ? $final_reason : $result['reason'] ), 'state' => $final['state'] ];
	}

	/**
	 * Resolve the current stable live-write reason without mutating state.
	 *
	 * The importer setter calls this before and inside its CAS callback so a
	 * reset, deactivation, changed setting, or writer/storage transition cannot
	 * be bypassed by a trusted importer.
	 *
	 * @param array $state Normalized current state.
	 * @param mixed $raw   Exact persisted state value.
	 * @return string
	 */
	private function get_live_write_reason( $state, $raw ) {
		if ( $this->is_deactivation_tombstone_raw( $raw ) )
			return 'core_deactivated';

		if ( ! $this->is_current_normal_state_raw( $raw ) )
			return 'state_format_unsupported';

		if ( $state['reason'] === 'reset_in_progress' )
			return 'reset_in_progress';

		if ( $state['schema']['state'] === 'pending' )
			return 'schema_pending';

		if ( $state['schema']['state'] !== 'ready' )
			return 'schema_failed';

		if ( $state['state'] !== 'ready' || $state['reason'] !== 'ready' )
			return $state['reason'];

		$settings = $this->get_general_settings();
		$pvc = Post_Views_Counter();
		$interval = $pvc->normalize_time_between_counts(
			isset( $settings['time_between_counts'] ) ? $settings['time_between_counts'] : null,
			$pvc->defaults['general']['time_between_counts']
		);

		if ( max( 0, (int) $interval['number'] ) === 0 )
			return 'interval_zero';

		$writer = $this->get_writer_status();

		if ( ! $writer['supported'] )
			return $writer['reason'];

		$storage = $this->get_storage_status( $settings );

		if ( ! $storage['capable'] )
			return $storage['reason'];

		return 'ready';
	}

	/**
	 * Fence Visit reset and shared counter rows against direct count writes.
	 *
	 * @return bool
	 */
	public function begin_measurement_fence() {
		global $wpdb;

		if ( $this->measurement_fence_depth > 0 ) {
			$this->measurement_fence_depth++;
			return true;
		}

		if ( $wpdb->query( "LOCK TABLES `{$wpdb->prefix}post_views` WRITE, `{$wpdb->options}` WRITE" ) === false )
			return false;

		$this->measurement_reset_lock_held = true;
		$this->measurement_fence_depth = 1;
		$this->measurement_fence_critical_write = false;
		return true;
	}

	/**
	 * Release the measurement-reset table fence.
	 *
	 * @return void
	 */
	public function end_measurement_fence() {
		global $wpdb;

		if ( $this->measurement_fence_depth < 1 )
			return [ 'unlocked' => false, 'flushed' => false, 'critical_write' => false ];

		if ( $this->measurement_fence_depth > 1 ) {
			$this->measurement_fence_depth--;
			return [ 'unlocked' => false, 'flushed' => false, 'critical_write' => $this->measurement_fence_critical_write ];
		}

		$critical_write = $this->measurement_fence_critical_write;
		$unlocked = $wpdb->query( 'UNLOCK TABLES' ) !== false;

		if ( ! $unlocked )
			return [ 'unlocked' => false, 'flushed' => false, 'critical_write' => $critical_write ];

		$this->measurement_reset_lock_held = false;
		$this->measurement_fence_depth = 0;
		$this->measurement_fence_critical_write = false;
		$work = $this->measurement_fence_work;
		$this->measurement_fence_work = [];
		$flushed = true;

		foreach ( $work as $descriptor ) {
			if ( $descriptor['type'] === 'state_caches' && ! $this->synchronize_state_caches( $descriptor['retire_reads'], $descriptor['invalidate_availability'] ) ) {
				$flushed = false;
				break;
			}

			if ( $descriptor['type'] === 'option_caches' ) {
				foreach ( $descriptor['keys'] as $key ) {
					if ( ! wp_cache_delete( $key, 'options' ) && wp_cache_get( $key, 'options' ) !== false ) {
						$flushed = false;
						break 2;
					}
				}
			}

			if ( $descriptor['type'] === 'visit_read_caches' && class_exists( 'Post_Views_Counter_Visits_Query' ) ) {
				Post_Views_Counter_Visits_Query::invalidate_read_cache();

				if ( method_exists( 'Post_Views_Counter_Visits_Query', 'was_last_invalidation_successful' ) && ! Post_Views_Counter_Visits_Query::was_last_invalidation_successful() ) {
					$flushed = false;
					break;
				}
			}
		}

		return [ 'unlocked' => true, 'flushed' => $flushed, 'critical_write' => $critical_write ];
	}

	/** @return bool */
	public function is_measurement_fence_active() {
		return $this->measurement_fence_depth > 0;
	}

	/**
	 * Queue an internal, idempotent publication record for the outermost unlock.
	 * Callables are deliberately not accepted here.
	 *
	 * @param array $descriptor Internal descriptor.
	 * @return void
	 */
	public function defer_measurement_fence_work( $descriptor ) {
		if ( ! $this->is_measurement_fence_active() || ! is_array( $descriptor ) || ! isset( $descriptor['type'] ) )
			return;

		if ( $descriptor['type'] === 'option_caches' ) {
			$keys = isset( $descriptor['keys'] ) && is_array( $descriptor['keys'] ) ? array_values( array_unique( array_filter( $descriptor['keys'], 'is_string' ) ) ) : [];

			if ( empty( $keys ) )
				return;

			$this->measurement_fence_work[] = [ 'type' => 'option_caches', 'keys' => $keys ];
			return;
		}

		if ( $descriptor['type'] === 'visit_read_caches' ) {
			$this->measurement_fence_work[] = [ 'type' => 'visit_read_caches' ];
			return;
		}

		if ( $descriptor['type'] !== 'state_caches' )
			return;

		$this->measurement_fence_work[] = [
			'type' => 'state_caches',
			'retire_reads' => ! empty( $descriptor['retire_reads'] ),
			'invalidate_availability' => ! array_key_exists( 'invalidate_availability', $descriptor ) || ! empty( $descriptor['invalidate_availability'] )
		];
	}

	/** @return void */
	public function mark_measurement_fence_critical_write() {
		if ( $this->is_measurement_fence_active() )
			$this->measurement_fence_critical_write = true;
	}

	/**
	 * Enter the reset-only version-zero state using an exact raw-value CAS.
	 *
	 * This is intentionally narrow: callers use it only while the restrictive
	 * table fence is held, so it must neither
	 * repair caches nor invoke the Options API.
	 *
	 * @param int $timestamp UTC timestamp captured before entering the fence.
	 * @return array{code:string,raw:string|null}
	 */
	public function enter_visit_reset_v0( $timestamp ) {
		$current = $this->read_durable_state( true );

		if ( $current === false || ! $current['exists'] || ! is_string( $current['raw'] ) )
			return [ 'code' => 'state_read_failed', 'raw' => null ];

		if ( ! $this->is_current_normal_state_raw( $current['value'] ) && ! $this->is_cache_repair_state_raw( $current['value'] ) )
			return [ 'code' => 'state_format_unsupported', 'raw' => null ];

		$next = $current['value'];

		// A generation is retained only by the durable reset-continuation marker.
		// Version-zero repair state alone is produced by ordinary compatibility
		// writes and therefore cannot prove that this reset already advanced it.
		if ( $next['reason'] !== 'reset_in_progress' )
			$next['queue_generation'] = (int) $next['queue_generation'] + 1;

		$next['format_version'] = 0;
		$next['derived_since'] = max( 1, (int) $timestamp );
		$next['state'] = 'unavailable';
		$next['reason'] = 'reset_in_progress';
		$next['last_compatibility_check'] = max( 0, (int) $timestamp );
		$updated = $this->compare_and_swap_state_raw( $current['raw'], $next );

		if ( $updated !== 1 )
			return [ 'code' => $updated === false ? 'state_write_failed' : 'state_conflict', 'raw' => null ];

		$this->durable_state_cache = [];
		return [ 'code' => 'completed', 'raw' => maybe_serialize( $next ) ];
	}

	/**
	 * Complete a reset-only version-zero state using its exact entry value.
	 *
	 * @param string $entry_raw Exact raw value returned by enter_visit_reset_v0().
	 * @param int    $timestamp UTC timestamp captured before entering the fence.
	 * @return array{code:string,raw:string|null}
	 */
	public function complete_visit_reset_v0( $entry_raw, $timestamp ) {
		$current = $this->read_durable_state( true );

		if ( $current === false || ! $current['exists'] || $current['raw'] !== $entry_raw || ! $this->is_cache_repair_state_raw( $current['value'] ) || $current['value']['reason'] !== 'reset_in_progress' )
			return [ 'code' => 'state_conflict', 'raw' => null ];

		$next = $current['value'];
		$next['derived_since'] = max( 1, (int) $timestamp );
		$next['state'] = 'unavailable';
		$next['reason'] = 'reset_complete';
		$next['last_compatibility_check'] = max( 0, (int) $timestamp );
		$updated = $this->compare_and_swap_state_raw( $entry_raw, $next );

		if ( $updated !== 1 )
			return [ 'code' => $updated === false ? 'state_write_failed' : 'state_conflict', 'raw' => null ];

		$this->durable_state_cache = [];
		return [ 'code' => 'completed', 'raw' => maybe_serialize( $next ) ];
	}

	/**
	 * Promote one known version-zero reset value only after its cache retirement.
	 *
	 * @param string $expected_raw Exact version-zero raw value.
	 * @return bool
	 */
	public function promote_visit_reset_v0( $expected_raw ) {
		$current = $this->read_durable_state( true );

		if ( $current === false || ! $current['exists'] || $current['raw'] !== $expected_raw || ! $this->is_cache_repair_state_raw( $current['value'] ) )
			return false;

		$next = $current['value'];
		$next['format_version'] = 1;
		$updated = $this->compare_and_swap_state_raw( $expected_raw, $next );

		if ( $updated !== 1 )
			return false;

		$this->durable_state_cache = [];
		return true;
	}

	/** @return bool */
	public function acquire_measurement_reset_lock() {
		// Kept as a non-reentrant compatibility shim for unmigrated callers. New
		// restrictive windows must use begin_measurement_fence() directly.
		if ( $this->is_measurement_fence_active() )
			return false;

		return $this->begin_measurement_fence();
	}

	/** @return void */
	public function release_measurement_reset_lock() {
		$this->end_measurement_fence();
	}

	/**
	 * Clear only request-local availability results.
	 *
	 * This remains the explicit invalidator for mutations that change the answer
	 * within the same clock second, such as reset lifecycle transitions.
	 *
	 * @return void
	 */
	public function invalidate_availability_cache() {
		$this->availability_cache = [];
		$this->durable_state_cache = [];
	}

	/**
	 * Return the cached shared-table content-column shape. A null result means
	 * the probe failed and callers must retain the views-only SQL shape.
	 *
	 * @return bool|null
	 */
	public function get_shared_content_column_status() {
		global $wpdb;

		$state = $this->get_state();

		if ( is_bool( $state['schema']['content_column'] ) )
			return $state['schema']['content_column'];

		$probe = null;
		$result = $this->mutate_state(
			function( $latest ) use ( $wpdb, &$probe ) {
				$probe = $this->probe_named_column( $wpdb->prefix . 'post_views', 'content' );

				if ( $probe !== null )
					$latest['schema']['content_column'] = $probe;

				return $latest;
			},
			true
		);

		return $result['success'] && $probe !== null ? $probe : null;
	}

	/**
	 * Force one later table-shape probe after the companion changes the shared
	 * schema.
	 *
	 * @return void
	 */
	public function invalidate_shared_table_shape() {
		$this->mutate_state(
			function( $state ) {
				$state['schema']['content_column'] = null;
				return $state;
			},
			true
		);
	}

	/**
	 * Add bounded, privacy-safe readiness data to the existing Plugin Status
	 * table. Values contain only stable codes, timestamps, counts, and versions.
	 *
	 * @param array $rows Existing rows.
	 * @param mixed $settings Settings instance (unused, retained for filter shape).
	 * @return array
	 */
	public function add_plugin_status_rows( $rows, $settings = null ) {
		$state = $this->refresh_availability();
		$status = $this->get_visits_availability();

		$rows[] = [
			'label' => __( 'Visits Readiness', 'post-views-counter' ),
			'lines' => [
				sprintf( /* translators: 1: read state, 2: reason code */ __( 'Read: %1$s (%2$s)', 'post-views-counter' ), ! empty( $status['readable'] ) ? __( 'ready', 'post-views-counter' ) : __( 'unavailable', 'post-views-counter' ), $status['read_reason'] ),
				sprintf( /* translators: 1: live write state, 2: reason code */ __( 'Live write: %1$s (%2$s)', 'post-views-counter' ), ! empty( $status['writable'] ) ? __( 'ready', 'post-views-counter' ) : __( 'unavailable', 'post-views-counter' ), $status['write_reason'] ),
				sprintf( /* translators: 1: schema state, 2: schema status code */ __( 'Schema: %1$s (%2$s)', 'post-views-counter' ), $state['schema']['state'], $state['schema']['last_status'] ),
				sprintf( /* translators: derived metrics start timestamp or none */ __( 'Derived metrics since: %s', 'post-views-counter' ), $state['derived_since'] !== null ? gmdate( 'c', (int) $state['derived_since'] ) : __( 'none', 'post-views-counter' ) ),
				sprintf(
					/* translators: 1: last migration attempt timestamp or never, 2: last compatibility check timestamp or never */
					__( 'Checks: migration attempt %1$s; compatibility %2$s', 'post-views-counter' ),
					(int) $state['schema']['last_attempt_at'] > 0 ? gmdate( 'c', (int) $state['schema']['last_attempt_at'] ) : __( 'never', 'post-views-counter' ),
					(int) $state['last_compatibility_check'] > 0 ? gmdate( 'c', (int) $state['last_compatibility_check'] ) : __( 'never', 'post-views-counter' )
				),
				sprintf( /* translators: 1: Core version, 2: extension version */ __( 'Versions: Core %1$s; Pro %2$s', 'post-views-counter' ), $state['versions']['core'] !== '' ? $state['versions']['core'] : __( 'unknown', 'post-views-counter' ), $state['versions']['pro'] !== '' ? $state['versions']['pro'] : __( 'inactive', 'post-views-counter' ) )
			]
		];

		return $rows;
	}

	/**
	 * Normalize General settings for definition and storage evaluation.
	 *
	 * @param array|null $settings Optional saved settings snapshot.
	 * @return array
	 */
	protected function get_general_settings( $settings = null ) {
		$pvc = Post_Views_Counter();
		$defaults = isset( $pvc->defaults['general'] ) && is_array( $pvc->defaults['general'] ) ? $pvc->defaults['general'] : [];

		if ( is_array( $settings ) )
			return array_merge( $defaults, $settings );

		$current = isset( $pvc->options['general'] ) && is_array( $pvc->options['general'] ) ? $pvc->options['general'] : [];

		return array_merge( $defaults, $current );
	}

	/**
	 * Resolve the complete active writer capability.
	 *
	 * Every active writer must declare Visit support. An extended shared writer
	 * also needs the content column before live Visit writes can be authorized.
	 *
	 * @param array $runtime_overrides Controlled lifecycle/test overrides.
	 * @return array
	 */
	protected function get_writer_status( $runtime_overrides = [] ) {
		$pvc = Post_Views_Counter();
		$core_supported = isset( $pvc->counter ) && is_object( $pvc->counter ) && method_exists( $pvc->counter, 'supports_visit_writes' ) && $pvc->counter->supports_visit_writes();

		if ( ! $core_supported )
			return [ 'supported' => false, 'reason' => 'writer_unsupported' ];

		$pro_active = array_key_exists( 'pro_active', $runtime_overrides ) ? (bool) $runtime_overrides['pro_active'] : class_exists( 'Post_Views_Counter_Pro' );

		if ( ! $pro_active )
			return [ 'supported' => true, 'reason' => 'ready' ];

		if ( ! function_exists( 'Post_Views_Counter_Pro' ) )
			return [ 'supported' => false, 'reason' => 'writer_unsupported' ];

		$pro = Post_Views_Counter_Pro();
		$supported = is_object( $pro ) && method_exists( $pro, 'supports_visit_writes' ) && $pro->supports_visit_writes();

		// The extended shared writer includes the content discriminator. Until its
		// existing database update has installed that column, keep Visits
		// unavailable and let the active writer preserve post Views through its fallback.
		if ( $supported && $this->get_shared_content_column_status() !== true )
			$supported = false;

		return [ 'supported' => (bool) $supported, 'reason' => $supported ? 'ready' : 'writer_unsupported' ];
	}

	/**
	 * Resolve configured storage capability for normalized session payloads.
	 * Runtime browser refusal remains per-request and never authorizes a write.
	 *
	 * @param array $settings General settings.
	 * @return array
	 */
	protected function get_storage_status( $settings ) {
		$mode = isset( $settings['data_storage'] ) && is_scalar( $settings['data_storage'] ) ? sanitize_key( (string) $settings['data_storage'] ) : '';
		$pvc = Post_Views_Counter();
		$session_supported = isset( $pvc->counter ) && is_object( $pvc->counter ) && method_exists( $pvc->counter, 'use_session_storage_payload_writes' ) && $pvc->counter->use_session_storage_payload_writes();
		$capable = in_array( $mode, [ 'cookies', 'cookieless' ], true ) && $session_supported;

		return [ 'capable' => $capable, 'reason' => $capable ? 'ready' : 'storage_incapable' ];
	}

	/**
	 * Normalize a bounded explicit range to UTC timestamps.
	 *
	 * @param mixed $range Range input.
	 * @return array|null
	 */
	private function normalize_range( $range ) {
		if ( ! is_array( $range ) || ! array_key_exists( 'start', $range ) || ! array_key_exists( 'end', $range ) )
			return null;

		$start = $this->normalize_range_timestamp( $range['start'] );
		$end = $this->normalize_range_timestamp( $range['end'] );

		if ( $start <= 0 || $end <= $start || $end > $this->now() )
			return null;

		return [ 'start' => $start, 'end' => $end ];
	}

	/**
	 * @param mixed $value Timestamp-like value.
	 * @return int
	 */
	private function normalize_range_timestamp( $value ) {
		if ( $value instanceof DateTimeInterface )
			return $value->getTimestamp();

		if ( is_numeric( $value ) )
			return (int) $value;

		if ( is_string( $value ) ) {
			$timestamp = strtotime( $value );

			return $timestamp === false ? 0 : (int) $timestamp;
		}

		return 0;
	}

	/**
	 * Match only the companion plugin lifecycle event.
	 *
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	private function is_shared_content_schema_plugin( $plugin ) {
		if ( ! is_string( $plugin ) )
			return false;

		if ( defined( 'POST_VIEWS_COUNTER_PRO_BASENAME' ) )
			return $plugin === POST_VIEWS_COUNTER_PRO_BASENAME;

		return basename( $plugin ) === 'post-views-counter-pro.php';
	}

	/**
	 * Probe whether the shared table exists without conflating absence with an
	 * unsuccessful database query.
	 *
	 * @param string $table Table name.
	 * @return array
	 */
	protected function probe_table( $table ) {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';

		try {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			$error = $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( $error !== '' )
			return [ 'status' => 'error' ];

		return [ 'status' => is_string( $found ) && $found === $table ? 'found' : 'missing' ];
	}

	/**
	 * Probe an additive shared-table column without surfacing database errors.
	 *
	 * @param string $table Trusted prefixed table name.
	 * @param string $column Column name.
	 * @return bool|null True when present, false when absent, null on probe error.
	 */
	protected function probe_named_column( $table, $column ) {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';

		try {
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
			$error = $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( $error !== '' )
			return null;

		return is_string( $found ) && $found === $column;
	}

	/**
	 * Migrate one bounded batch of existing active sites.
	 *
	 * @return void
	 */
	protected function maybe_upgrade_network_schema() {
		$network_id = get_current_network_id();
		$status = $this->get_network_status( $network_id );
		$now = $this->now();

		if ( (int) $status['version'] !== self::SCHEMA_VERSION )
			$status = $this->get_default_network_status();

		if ( ! empty( $status['complete'] ) ) {
			$has_failures = ! empty( $status['failed_sites'] ) || ! empty( $status['failed_sites_omitted'] );
			$last_completed_at = (int) $status['last_completed_at'];

			// A fresh bounded pass retries every active site, including IDs that
			// could not fit in the 50-entry diagnostic window.
			if ( $has_failures && ( $last_completed_at === 0 || $now - $last_completed_at >= self::SCHEMA_CHECK_INTERVAL ) ) {
				$status['cursor'] = 0;
				$status['complete'] = false;
				$status['last_status'] = 'rescan_pending';
				$status['failed_sites'] = [];
				$status['failed_sites_omitted'] = 0;
				$status['updated_at'] = $now;
				update_network_option( $network_id, self::NETWORK_OPTION_NAME, $status );
			} else {
				$status = $this->retry_failed_network_sites( $network_id, $status );

				if ( empty( $status['failed_sites'] ) && empty( $status['failed_sites_omitted'] ) )
					$this->clear_schema_sweep();

				return;
			}
		}

		$batch_started_at = $this->microtime_now();
		$site_ids = $this->get_network_site_ids( $network_id, (int) $status['cursor'] );

		if ( $site_ids === null ) {
			$status['last_status'] = 'site_query_failed';
			$status['updated_at'] = $now;
			update_network_option( $network_id, self::NETWORK_OPTION_NAME, $status );
			return;
		}

		$processed = 0;

		foreach ( $site_ids as $site_id ) {
			$result = $this->process_network_site( (int) $site_id );
			$status['cursor'] = (int) $site_id;
			$processed++;

			if ( $result['status'] === 'ready' )
				$this->remove_network_failed_site( $status, (int) $site_id );
			elseif ( $result['status'] === 'unknown' )
				$this->record_network_unknown_site( $status, (int) $site_id, $result['state'] );
			else
				$this->record_network_failed_site( $status, (int) $site_id, $result['state'] );

			if ( $this->microtime_now() - $batch_started_at >= self::NETWORK_TIME_BUDGET )
				break;
		}

		$status['last_status'] = empty( $status['failed_sites'] ) && empty( $status['failed_sites_omitted'] ) ? 'running' : 'running_with_failures';
		$status['updated_at'] = $this->now();

		if ( $processed === count( $site_ids ) && count( $site_ids ) < self::NETWORK_BATCH_SIZE ) {
			$status['complete'] = true;
			$status['last_completed_at'] = $this->now();
			$status['last_status'] = empty( $status['failed_sites'] ) && empty( $status['failed_sites_omitted'] ) ? 'ready' : 'ready_with_failures';
		}

		update_network_option( $network_id, self::NETWORK_OPTION_NAME, $status );

		if ( ! empty( $status['complete'] ) && empty( $status['failed_sites'] ) && empty( $status['failed_sites_omitted'] ) )
			$this->clear_schema_sweep();
	}

	/**
	 * Query one page of active sites for the current network.
	 *
	 * @param int $network_id Network ID.
	 * @param int $cursor Last processed site ID.
	 * @return array|null Null on query error.
	 */
	protected function get_network_site_ids( $network_id, $cursor ) {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';

		try {
			$site_ids = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND blog_id > %d AND archived = '0' AND deleted = '0' AND spam = '0' ORDER BY blog_id ASC LIMIT %d",
					$network_id,
					$cursor,
					self::NETWORK_BATCH_SIZE
				)
			);
			$error = $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		return $error === '' ? array_map( 'intval', $site_ids ) : null;
	}

	/**
	 * Evaluate one switched site's schema and restore the original site.
	 *
	 * @param int $site_id Site ID.
	 * @return array
	 */
	protected function process_network_site( $site_id ) {
		switch_to_blog( $site_id );

		try {
			$pvc = Post_Views_Counter();
			$site_settings = get_option( 'post_views_counter_settings_general', [] );
			$site_settings = array_merge( $pvc->defaults['general'], is_array( $site_settings ) ? $site_settings : [] );
			$current_state = $this->get_state();

			if ( $current_state['schema']['state'] === 'ready' )
				$result = $this->verify_schema();
			else
				$result = $this->migrate_schema_status();

			$this->refresh_availability( true, $site_settings );
			$site_state = $this->get_state();
		} finally {
			restore_current_blog();
		}

		return [
			'status' => $result,
			'state' => $site_state
		];
	}

	/**
	 * Get the bounded network migration status shape.
	 *
	 * @return array
	 */
	protected function get_default_network_status() {
		return [
			'version' => self::SCHEMA_VERSION,
			'cursor' => 0,
			'complete' => false,
			'last_status' => 'pending',
			'failed_sites' => [],
			'failed_sites_omitted' => 0,
			'last_completed_at' => 0,
			'updated_at' => $this->now()
		];
	}

	/**
	 * Normalize persisted network status.
	 *
	 * @param int $network_id Network ID.
	 * @return array
	 */
	protected function get_network_status( $network_id ) {
		$status = get_network_option( $network_id, self::NETWORK_OPTION_NAME, [] );

		if ( ! is_array( $status ) )
			$status = [];

		$status = array_merge( $this->get_default_network_status(), $status );
		$status['failed_sites'] = is_array( $status['failed_sites'] ) ? $status['failed_sites'] : [];

		return $status;
	}

	/**
	 * Record one failed site without allowing diagnostics to grow unbounded.
	 *
	 * @param array $status Network migration status.
	 * @param int   $site_id Site ID.
	 * @param array $site_state Per-site Visits state.
	 * @return void
	 */
	protected function record_network_failed_site( &$status, $site_id, $site_state ) {
		$this->record_network_site_status(
			$status,
			$site_id,
			isset( $site_state['schema']['last_status'] ) ? $site_state['schema']['last_status'] : 'unknown',
			! empty( $site_state['schema']['retryable'] ),
			isset( $site_state['schema']['last_attempt_at'] ) ? (int) $site_state['schema']['last_attempt_at'] : $this->now()
		);
	}

	/**
	 * Record an unknown probe result without demoting the site's ready state.
	 *
	 * @param array $status Network migration status.
	 * @param int   $site_id Site ID.
	 * @param array $site_state Per-site Visits state.
	 * @return void
	 */
	protected function record_network_unknown_site( &$status, $site_id, $site_state ) {
		$attempted_at = isset( $site_state['schema']['last_probe_error_at'] ) ? (int) $site_state['schema']['last_probe_error_at'] : $this->now();
		$this->record_network_site_status( $status, $site_id, 'schema_probe_failed', true, $attempted_at );
	}

	/**
	 * Store one bounded site diagnostic.
	 *
	 * @param array  $status Network migration status.
	 * @param int    $site_id Site ID.
	 * @param string $code Stable status code.
	 * @param bool   $retryable Whether retry is allowed.
	 * @param int    $attempted_at Attempt timestamp.
	 * @return void
	 */
	private function record_network_site_status( &$status, $site_id, $code, $retryable, $attempted_at ) {
		$key = (string) (int) $site_id;
		$entry = [
			'site_id' => (int) $site_id,
			'code' => sanitize_key( $code ),
			'retryable' => (bool) $retryable,
			'last_attempt_at' => (int) $attempted_at
		];

		if ( isset( $status['failed_sites'][ $key ] ) || count( $status['failed_sites'] ) < self::NETWORK_FAILED_SITES_LIMIT ) {
			$status['failed_sites'][ $key ] = $entry;
			return;
		}

		$status['failed_sites_omitted'] = (int) $status['failed_sites_omitted'] + 1;
	}

	/**
	 * Remove a site from bounded failure diagnostics after recovery.
	 *
	 * @param array $status Network migration status.
	 * @param int   $site_id Site ID.
	 * @return void
	 */
	protected function remove_network_failed_site( &$status, $site_id ) {
		unset( $status['failed_sites'][ (string) (int) $site_id ] );
	}

	/**
	 * Retry a small due subset after the initial cursor has completed.
	 * Terminal failures remain visible and are not retried.
	 *
	 * @param int   $network_id Network ID.
	 * @param array $status Network migration status.
	 * @return array Updated status.
	 */
	protected function retry_failed_network_sites( $network_id, $status ) {
		if ( empty( $status['failed_sites'] ) )
			return $status;

		$attempted = 0;

		foreach ( $status['failed_sites'] as $key => $failure ) {
			if ( $attempted >= self::NETWORK_FAILED_RETRY_BATCH_SIZE )
				break;

			if ( empty( $failure['retryable'] ) || ( (int) $failure['last_attempt_at'] > 0 && $this->now() - (int) $failure['last_attempt_at'] < self::SCHEMA_RETRY_INTERVAL ) )
				continue;

			$site_id = isset( $failure['site_id'] ) ? (int) $failure['site_id'] : (int) $key;
			$result = $this->process_network_site( $site_id );
			$attempted++;

			if ( $result['status'] === 'ready' )
				$this->remove_network_failed_site( $status, $site_id );
			elseif ( $result['status'] === 'unknown' )
				$this->record_network_unknown_site( $status, $site_id, $result['state'] );
			else
				$this->record_network_failed_site( $status, $site_id, $result['state'] );
		}

		if ( $attempted === 0 )
			return $status;

		$status['last_status'] = empty( $status['failed_sites'] ) && empty( $status['failed_sites_omitted'] ) ? 'ready' : 'ready_with_failures';
		$status['updated_at'] = $this->now();
		update_network_option( $network_id, self::NETWORK_OPTION_NAME, $status );

		return $status;
	}

	/**
	 * Read the Visits column definition without conflating absence with an
	 * unsuccessful database query.
	 *
	 * @param string $table Table name.
	 * @return array
	 */
	protected function probe_visits_column( $table ) {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';

		try {
			$column = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'visits'", ARRAY_A );
			$error = $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( $error !== '' )
			return [ 'status' => 'error', 'column' => null ];

		return [
			'status' => is_array( $column ) ? 'found' : 'missing',
			'column' => is_array( $column ) ? $column : null
		];
	}

	/**
	 * Verify the expected additive column shape and mark the site ready.
	 *
	 * @param array $state Current state.
	 * @param array $column Column definition.
	 * @return bool
	 */
	private function verify_and_mark_ready( $state, $column ) {
		if ( ! $this->is_canonical_visits_column( $column ) )
			return $this->mark_schema_failed( $state, 'column_incompatible', false );

		$ready = true;
		$result = $this->mutate_state(
			function( $latest, $record, $attempt ) use ( &$ready ) {
				$probe = $this->probe_visits_column( $GLOBALS['wpdb']->prefix . 'post_views' );

				if ( $probe['status'] !== 'found' || ! $this->is_canonical_visits_column( $probe['column'] ) ) {
					$ready = false;
					return $latest;
				}

				$latest['schema']['state'] = 'ready';
				$latest['schema']['retryable'] = false;
				$latest['schema']['last_status'] = 'ready';
				$latest['schema']['last_error'] = '';
				$latest['schema']['last_probe_error_at'] = 0;

				if ( in_array( $latest['reason'], [ 'schema_pending', 'schema_failed' ], true ) ) {
					$latest['state'] = 'pending';
					$latest['reason'] = 'writer_unsupported';
				}

				$latest['last_compatibility_check'] = $this->now();
				$latest['versions'] = $this->get_active_versions();
				return $latest;
			},
			true
		);

		return $result['success'] && $ready;
	}

	/**
	 * Check the additive Visits column shape used by writers and schema retries.
	 *
	 * @param array $column Column definition.
	 * @return bool
	 */
	private function is_canonical_visits_column( $column ) {
		$type = isset( $column['Type'] ) ? strtolower( (string) $column['Type'] ) : '';
		$nullable = isset( $column['Null'] ) ? strtoupper( (string) $column['Null'] ) : '';
		$default = array_key_exists( 'Default', $column ) ? (string) $column['Default'] : '';

		return strpos( $type, 'bigint' ) === 0 && strpos( $type, 'unsigned' ) !== false && $nullable === 'NO' && $default === '0';
	}

	/**
	 * Preserve readiness after a transient verification probe error and record
	 * only a stable error code plus a backoff timestamp.
	 *
	 * @param array $state Current state.
	 * @return void
	 */
	private function mark_schema_probe_unknown( $state ) {
		$this->mutate_state(
			function( $latest, $record, $attempt ) {
				if ( $this->probe_visits_column( $GLOBALS['wpdb']->prefix . 'post_views' )['status'] !== 'error' )
					return $latest;

				$latest['schema']['last_status'] = 'schema_probe_failed';
				$latest['schema']['last_error'] = 'schema_probe_failed';
				$latest['schema']['last_probe_error_at'] = $this->now();
				$latest['versions'] = $this->get_active_versions();
				return $latest;
			},
			true
		);
	}

	/**
	 * Record a privacy-safe schema failure.
	 *
	 * @param array  $state Current state.
	 * @param string $code Stable failure code.
	 * @param bool   $retryable Whether a later controlled attempt can recover.
	 * @return false
	 */
	private function mark_schema_failed( $state, $code, $retryable = true ) {
		$code = sanitize_key( $code );
		$this->mutate_state(
			function( $latest, $record, $attempt ) use ( $code, $retryable ) {
				$probe = $this->probe_visits_column( $GLOBALS['wpdb']->prefix . 'post_views' );

				if ( $probe['status'] === 'error' )
					return $latest;

				if ( $probe['status'] === 'found' && $this->is_canonical_visits_column( $probe['column'] ) )
					return $latest;

				if ( ! in_array( $latest['reason'], [ 'reset_in_progress', 'core_deactivated' ], true ) ) {
					$latest['state'] = 'unavailable';
					$latest['reason'] = 'schema_failed';
				}

				$latest['schema']['state'] = 'failed';
				$latest['schema']['retryable'] = (bool) $retryable;
				$latest['schema']['last_status'] = $code;
				$latest['schema']['last_error'] = $code;
				$latest['schema']['last_attempt_at'] = $this->now();
				$latest['last_compatibility_check'] = $this->now();
				$latest['versions'] = $this->get_active_versions();
				return $latest;
			},
			true
		);

		return false;
	}

	/**
	 * Acquire an atomic per-site schema lease using the options unique index.
	 *
	 * @return string|false Lock token or false when another request owns it.
	 */
	private function acquire_schema_lock() {
		$token = wp_generate_uuid4();
		$lock = [
			'token' => $token,
			'expires_at' => $this->now() + self::SCHEMA_PENDING_LEASE
		];

		if ( add_option( self::LOCK_OPTION_NAME, $lock, '', false ) )
			return $token;

		$record = $this->read_option_durable( self::LOCK_OPTION_NAME );

		if ( $record === false || ! $record['exists'] )
			return false;

		$current = $record['value'];

		if ( is_array( $current ) && ! empty( $current['expires_at'] ) && (int) $current['expires_at'] > $this->now() )
			return false;

		return $this->replace_option_if_unchanged( self::LOCK_OPTION_NAME, $current, $lock ) ? $token : false;
	}

	/**
	 * Return whether another request's durable schema lease still blocks a
	 * deactivation-tombstone retry.
	 *
	 * @return bool
	 */
	private function is_schema_lease_active() {
		$record = $this->read_option_durable( self::LOCK_OPTION_NAME );

		return $record !== false
			&& $record['exists']
			&& is_array( $record['value'] )
			&& ! empty( $record['value']['expires_at'] )
			&& (int) $record['value']['expires_at'] > $this->now();
	}

	/**
	 * Release the schema lease only when this request still owns it.
	 *
	 * @param string $token Lock token.
	 * @return void
	 */
	private function release_schema_lock( $token ) {
		$record = $this->read_option_durable( self::LOCK_OPTION_NAME );

		if ( $record === false || ! $record['exists'] )
			return;

		$current = $record['value'];

		if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], (string) $token ) )
			$this->delete_option_if_unchanged( self::LOCK_OPTION_NAME, $current );
	}

	/**
	 * Read an option row without trusting a possibly stale Options API cache.
	 *
	 * @param string $name Option name.
	 * @return array|false
	 */
	private function read_option_durable( $name ) {
		global $wpdb;

		$wpdb->last_error = '';
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", $name ) );

		if ( $wpdb->last_error !== '' )
			return false;

		return [
			'exists' => $raw !== null,
			'raw' => $raw,
			'value' => $raw === null ? null : maybe_unserialize( $raw )
		];
	}

	/**
	 * Atomically replace an option only when its serialized value is unchanged.
	 *
	 * @param string $name Option name.
	 * @param mixed  $expected Expected value.
	 * @param mixed  $replacement Replacement value.
	 * @return bool
	 */
	private function replace_option_if_unchanged( $name, $expected, $replacement ) {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
					"UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND BINARY `option_value` = BINARY %s",
				maybe_serialize( $replacement ),
				$name,
				maybe_serialize( $expected )
			)
		);

		if ( $result === 1 ) {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}

		return $result === 1;
	}

	/**
	 * Delete an option only when the caller still owns its exact value.
	 *
	 * @param string $name Option name.
	 * @param mixed  $expected Expected value.
	 * @return bool
	 */
	private function delete_option_if_unchanged( $name, $expected ) {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
					"DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND BINARY `option_value` = BINARY %s",
				$name,
				maybe_serialize( $expected )
			)
		);

		if ( $result === 1 ) {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}

		return $result === 1;
	}

	/**
	 * Complete a durable version-0 cache-repair obligation.
	 *
	 * @param array $record Durable version-0 state record.
	 * @return array
	 */
	private function repair_cache_state( $record ) {
		$last = $record;

		for ( $attempt = 0; $attempt < self::STATE_WRITE_ATTEMPTS; $attempt++ ) {
			$current = $attempt === 0 ? $record : $this->read_durable_state( true );

			if ( $current === false )
				return [ 'success' => false, 'record' => false, 'state' => $last === false ? $this->get_state() : $last['state'], 'reason' => 'state_read_failed' ];

			$last = $current;

			if ( ! $this->is_cache_repair_state_raw( $current['value'] ) ) {
				if ( ! $current['exists'] || $this->is_current_normal_state_raw( $current['value'] ) || $this->is_deactivation_tombstone_raw( $current['value'] ) )
					return [ 'success' => true, 'record' => $current, 'state' => $current['state'], 'reason' => 'ready' ];

				return [ 'success' => false, 'record' => $current, 'state' => $current['state'], 'reason' => 'state_format_unsupported' ];
			}

			if ( ! $this->synchronize_state_caches( true, true ) )
				return [ 'success' => false, 'record' => $current, 'state' => $this->make_cache_repair_unavailable( $current['state'] ), 'reason' => 'state_cache_publication_failed' ];

			$ready = $current['value'];
			$ready['format_version'] = 1;
			$ready_raw = maybe_serialize( $ready );
			$updated = $this->compare_and_swap_state_raw( $current['raw'], $ready );

			if ( $updated === false )
				return [ 'success' => false, 'record' => $current, 'state' => $this->make_cache_repair_unavailable( $current['state'] ), 'reason' => 'state_write_failed' ];

			if ( $updated !== 1 )
				continue;

			$this->durable_state_cache = [];

			if ( ! $this->synchronize_state_caches( false, true ) ) {
				// Restore the exact pending value when publication of the ready value
				// fails. A concurrent successor prevents this rollback and owns its
				// own state/cache outcome.
				$this->compare_and_swap_state_raw( $ready_raw, $current['value'] );
				$this->durable_state_cache = [];
				$this->synchronize_state_caches( false, true );
				$failed = $this->read_durable_state( true );
				return [ 'success' => false, 'record' => $failed, 'state' => $failed === false ? $this->make_cache_repair_unavailable( $current['state'] ) : $this->make_cache_repair_unavailable( $failed['state'] ), 'reason' => 'state_cache_publication_failed' ];
			}

			$latest = $this->read_durable_state( true );

			if ( $latest === false )
				return [ 'success' => false, 'record' => false, 'state' => $ready, 'reason' => 'state_read_failed' ];

			if ( $this->is_cache_repair_state_raw( $latest['value'] ) ) {
				$record = $latest;
				continue;
			}

			if ( $latest['exists'] && ! $this->is_current_normal_state_raw( $latest['value'] ) && ! $this->is_deactivation_tombstone_raw( $latest['value'] ) )
				return [ 'success' => false, 'record' => $latest, 'state' => $latest['state'], 'reason' => 'state_format_unsupported' ];

			return [ 'success' => true, 'record' => $latest, 'state' => $latest['state'], 'reason' => 'ready' ];
		}

		return [ 'success' => false, 'record' => $last, 'state' => $last === false ? $this->get_state() : $this->make_cache_repair_unavailable( $last['state'] ), 'reason' => 'state_conflict' ];
	}

	/**
	 * Atomically replace the raw state row.
	 *
	 * @param string $expected_raw Exact serialized value being replaced.
	 * @param array  $next Replacement value.
	 * @return int|false
	 */
	private function compare_and_swap_state_raw( $expected_raw, $next ) {
		global $wpdb;

		$wpdb->last_error = '';
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND BINARY `option_value` = BINARY %s",
				maybe_serialize( $next ),
				self::OPTION_NAME,
				$expected_raw
			)
		);

		return $updated === false || $wpdb->last_error !== '' ? false : (int) $updated;
	}

	/**
	 * Apply one state transition through bounded full-value compare-and-swap.
	 *
	 * @param callable $callback State mutation callback.
	 * @param bool     $allow_create Whether a missing row may be initialized.
	 * @param bool     $retire_reads Whether derived Visit caches must be retired.
	 * @param bool     $allow_terminal Whether the exact deactivation tombstone may be replaced.
	 * @return array
	 */
	private function mutate_state( $callback, $allow_create = false, $retire_reads = true, $allow_terminal = false ) {
		$last = false;

		for ( $attempt = 0; $attempt < self::STATE_WRITE_ATTEMPTS; $attempt++ ) {
			$current = $this->read_durable_state( true );

			if ( $current === false )
				return [ 'success' => false, 'committed' => false, 'cache_success' => false, 'state' => $this->get_state(), 'reason' => 'state_read_failed' ];

			if ( $this->is_cache_repair_state_raw( $current['value'] ) ) {
				$repair = $this->repair_cache_state( $current );

				if ( ! $repair['success'] )
					return [ 'success' => false, 'committed' => true, 'cache_success' => false, 'state' => $repair['state'], 'reason' => $repair['reason'] ];

				$current = $repair['record'];
			}

			if ( ! $allow_terminal && $this->is_deactivation_tombstone_raw( $current['value'] ) ) {
				$cache_success = $this->synchronize_state_caches( false, true );
				return [ 'success' => $cache_success, 'committed' => true, 'cache_success' => $cache_success, 'state' => $current['state'], 'reason' => $cache_success ? 'ready' : 'state_cache_publication_failed' ];
			}

			if ( $current['exists'] && ! $this->is_current_normal_state_raw( $current['value'] ) && ! ( $allow_terminal && $this->is_deactivation_tombstone_raw( $current['value'] ) ) )
				return [ 'success' => false, 'committed' => false, 'cache_success' => false, 'state' => $current['state'], 'reason' => 'state_format_unsupported' ];

			$last = $current;
			$next = call_user_func( $callback, $current['state'], $current, $attempt );

			if ( ! is_array( $next ) )
				return [ 'success' => false, 'committed' => false, 'cache_success' => false, 'state' => $current['state'], 'reason' => 'state_mutation_failed' ];

			$next_raw = maybe_serialize( $next );

			if ( $current['exists'] && hash_equals( (string) $current['raw'], $next_raw ) ) {
				$cache_success = ! $this->is_deactivation_tombstone_raw( $next ) || $this->synchronize_state_caches( false, true );
				return [ 'success' => $cache_success, 'committed' => true, 'cache_success' => $cache_success, 'state' => $current['state'], 'reason' => $cache_success ? 'ready' : 'state_cache_publication_failed' ];
			}

			$persisted = $next;
			$needs_repair = $retire_reads && ! $this->is_deactivation_tombstone_raw( $persisted );

			if ( $needs_repair ) {
				$persisted = $this->canonicalize_state_shape( $persisted );
				$persisted['format_version'] = 0;
			}

			if ( $current['exists'] )
				$committed = $this->compare_and_swap_state_raw( $current['raw'], $persisted );
			elseif ( $allow_create )
				$committed = add_option( self::OPTION_NAME, $persisted, '', false ) ? 1 : 0;
			else
				return [ 'success' => false, 'committed' => false, 'cache_success' => false, 'state' => $current['state'], 'reason' => 'state_missing' ];

			if ( $committed === false )
				return [ 'success' => false, 'committed' => false, 'cache_success' => false, 'state' => $current['state'], 'reason' => 'state_write_failed' ];

			if ( $committed !== 1 )
				continue;

			$this->durable_state_cache = [];
			$latest = $this->read_durable_state( true );

			if ( $latest === false )
				return [ 'success' => false, 'committed' => true, 'cache_success' => false, 'state' => $this->normalize_state( $persisted ), 'reason' => 'state_read_failed' ];

			if ( $needs_repair ) {
				$repair = $this->repair_cache_state( $latest );
				return [ 'success' => $repair['success'], 'committed' => true, 'cache_success' => $repair['success'], 'state' => $repair['state'], 'reason' => $repair['reason'] ];
			}

			$cache_success = $this->synchronize_state_caches( $retire_reads, true );
			$latest = $this->read_durable_state( true );
			return [ 'success' => $cache_success, 'committed' => true, 'cache_success' => $cache_success, 'state' => $latest === false ? $this->normalize_state( $persisted ) : $latest['state'], 'reason' => $cache_success ? 'ready' : 'state_cache_publication_failed' ];
		}

		return [ 'success' => false, 'committed' => false, 'cache_success' => false, 'state' => $last === false ? $this->get_state() : $last['state'], 'reason' => 'state_conflict' ];
	}

	/**
	 * Publish the newest durable state through WordPress caches and verify it.
	 *
	 * @param bool $retire_reads Whether derived Visit caches must be retired.
	 * @param bool $invalidate_availability Whether the request availability memo must be cleared.
	 * @return bool
	 */
	private function synchronize_state_caches( $retire_reads, $invalidate_availability = true ) {
		if ( $this->is_measurement_fence_active() ) {
			$this->defer_measurement_fence_work( [
				'type' => 'state_caches',
				'retire_reads' => (bool) $retire_reads,
				'invalidate_availability' => (bool) $invalidate_availability
			] );

			return true;
		}

		for ( $attempt = 0; $attempt < self::STATE_WRITE_ATTEMPTS; $attempt++ ) {
			$latest = $this->read_durable_state( true );

			if ( $latest === false )
				return false;

			wp_cache_delete( self::OPTION_NAME, 'options' );
			$cached = wp_cache_get( self::OPTION_NAME, 'options' );

			if ( $cached !== false && $cached !== $latest['value'] ) {
				if ( ! wp_cache_set( self::OPTION_NAME, $latest['value'], 'options' ) || wp_cache_get( self::OPTION_NAME, 'options' ) !== $latest['value'] )
					continue;
			}

			foreach ( [ 'notoptions', 'alloptions' ] as $cache_key ) {
				if ( ! wp_cache_delete( $cache_key, 'options' ) && wp_cache_get( $cache_key, 'options' ) !== false )
					continue 2;
			}

			$verified = $this->read_durable_state( true );

			if ( $verified === false || $verified['raw'] !== $latest['raw'] )
				continue;

			if ( $invalidate_availability )
				$this->availability_cache = [];

			if ( $retire_reads && class_exists( 'Post_Views_Counter_Visits_Query' ) ) {
				Post_Views_Counter_Visits_Query::invalidate_read_cache();

				if ( method_exists( 'Post_Views_Counter_Visits_Query', 'was_last_invalidation_successful' ) && ! Post_Views_Counter_Visits_Query::was_last_invalidation_successful() )
					return false;
			}

			return true;
		}

		return false;
	}

	/**
	 * Determine whether the main-site sweep still has work.
	 *
	 * @return bool
	 */
	private function schema_sweep_needed() {
		if ( is_multisite() && $this->has_network_migration_state() ) {
			$status = $this->get_network_status( get_current_network_id() );

			return (int) $status['version'] !== self::SCHEMA_VERSION || empty( $status['complete'] ) || ! empty( $status['failed_sites'] ) || ! empty( $status['failed_sites_omitted'] );
		}

		$state = $this->get_state();

		return $state['schema']['state'] !== 'ready' && ( $state['schema']['state'] !== 'failed' || ! empty( $state['schema']['retryable'] ) );
	}

	/**
	 * Check whether the current network owns a migration cursor.
	 *
	 * @return bool
	 */
	private function has_network_migration_state() {
		if ( ! is_multisite() )
			return false;

		return is_array( get_network_option( get_current_network_id(), self::NETWORK_OPTION_NAME, null ) );
	}

	/**
	 * Check whether Core is active network-wide.
	 *
	 * @return bool
	 */
	private function is_network_active() {
		if ( ! is_multisite() || ! defined( 'POST_VIEWS_COUNTER_BASENAME' ) )
			return false;

		if ( ! function_exists( 'is_plugin_active_for_network' ) )
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

		return is_plugin_active_for_network( POST_VIEWS_COUNTER_BASENAME );
	}

	/**
	 * Get privacy-safe active component versions.
	 *
	 * @return array
	 */
	private function get_active_versions() {
		$pvc = Post_Views_Counter();
		$core_version = isset( $pvc->defaults['version'] ) ? sanitize_text_field( (string) $pvc->defaults['version'] ) : '';
		$pro_version = class_exists( 'Post_Views_Counter_Pro' ) ? sanitize_text_field( (string) get_option( 'post_views_counter_pro_version', '' ) ) : '';

		return [
			'core' => substr( $core_version, 0, 32 ),
			'pro' => substr( $pro_version, 0, 32 )
		];
	}

	/**
	 * Current Unix timestamp, isolated for deterministic migration tests.
	 *
	 * @return int
	 */
	protected function now() {
		return time();
	}

	/**
	 * Get a monotonic-enough request-local timestamp for the network batch
	 * wall-clock budget. Kept separate from now() so the budget is testable.
	 *
	 * @return float
	 */
	protected function microtime_now() {
		return microtime( true );
	}
}
