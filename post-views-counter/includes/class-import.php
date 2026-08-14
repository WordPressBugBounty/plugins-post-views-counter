<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Import class.
 *
 * @class Post_Views_Counter_Import
 */
class Post_Views_Counter_Import {
	const SOURCE_BATCH_SIZE = 250;
	const DESTINATION_CHUNK_SIZE = 500;
	const MAX_SOURCE_BATCHES = 100000;

	/**
	 * Import providers registry.
	 *
	 * @var array
	 */
	private $import_providers = [];
	private $import_provider_labels = [];
	private $import_strategies = null;
	private $default_import_strategy = 'merge';
	private $statify_post_cache = [];
	private $import_diagnostics = [];

	/**
	 * Whether providers have been initialized.
	 *
	 * @var bool
	 */
	private $import_providers_initialized = false;

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// register import providers after translations are available
		add_action( 'init', [ $this, 'initialize_import_providers' ], 5 );
	}

	/**
	 * Initialize import providers.
	 *
	 * @return void
	 */
	public function initialize_import_providers() {
		if ( $this->import_providers_initialized )
			return;

		$this->register_import_providers();
		$this->import_providers_initialized = true;
	}

	/**
	 * Register import providers.
	 *
	 * @return void
	 */
	private function register_import_providers() {
		// custom meta key provider (always available)
		$this->import_providers['custom_meta_key'] = [
			'slug'			=> 'custom_meta_key',
			'label'			=> __( 'Custom Meta Key', 'post-views-counter' ),
			'supports'		=> [ 'total', 'post_types' ],
			'is_available'	=> '__return_true',
			'render'		=> [ $this, 'render_provider_custom_meta_key' ],
			'sanitize'		=> [ $this, 'sanitize_provider_custom_meta_key' ],
			'analyse'		=> [ $this, 'analyse_provider_custom_meta_key' ],
			'import'		=> [ $this, 'import_provider_custom_meta_key' ]
		];

		// wp-postviews provider (conditional)
		$this->import_providers['wp_postviews'] = [
			'slug'			=> 'wp_postviews',
			'label'			=> 'WP-PostViews',
			'supports'		=> [ 'total', 'post_types' ],
			'is_available'	=> [ $this, 'is_wp_postviews_available' ],
			'render'		=> [ $this, 'render_provider_wp_postviews' ],
			'sanitize'		=> [ $this, 'sanitize_provider_wp_postviews' ],
			'analyse'		=> [ $this, 'analyse_provider_wp_postviews' ],
			'import'		=> [ $this, 'import_provider_wp_postviews' ]
		];

		// statify provider (conditional)
		$this->import_providers['statify'] = [
			'slug'			=> 'statify',
			'label'			=> 'Statify',
			'supports'		=> [ 'total', 'yearly', 'monthly', 'weekly', 'daily', 'post_types' ],
			'is_available'	=> [ $this, 'is_statify_available' ],
			'render'		=> [ $this, 'render_provider_statify' ],
			'sanitize'		=> [ $this, 'sanitize_provider_statify' ],
			'analyse'		=> [ $this, 'analyse_provider_statify' ],
			'import'		=> [ $this, 'import_provider_statify' ]
		];

		// page views count provider (conditional)
		$this->import_providers['page_views_count'] = [
			'slug'			=> 'page_views_count',
			'label'			=> 'Page Views Count',
			'supports'		=> [ 'total', 'yearly', 'monthly', 'weekly', 'daily', 'post_types' ],
			'is_available'	=> [ $this, 'is_page_views_count_available' ],
			'render'		=> [ $this, 'render_provider_page_views_count' ],
			'sanitize'		=> [ $this, 'sanitize_provider_page_views_count' ],
			'analyse'		=> [ $this, 'analyse_provider_page_views_count' ],
			'import'		=> [ $this, 'import_provider_page_views_count' ]
		];

		// allow extensions to register additional providers without overriding core ones
		$additional_providers = apply_filters( 'pvc_import_providers', [] );

		if ( is_array( $additional_providers ) ) {
			foreach ( $additional_providers as $slug => $provider ) {
				if ( ! is_string( $slug ) || $slug === '' || isset( $this->import_providers[ $slug ] ) ) {
					continue;
				}

				$this->import_providers[ $slug ] = $provider;
			}
		}

		// ensure third-party providers have default supports
		foreach ( $this->import_providers as $slug => $provider ) {
			if ( ! isset( $provider['supports'] ) || ! is_array( $provider['supports'] ) ) {
				$this->import_providers[ $slug ]['supports'] = [ 'total', 'post_types' ];
			}
		}

		foreach ( $this->import_providers as $slug => $provider ) {
			$this->import_provider_labels[ $slug ] = isset( $provider['label'] ) ? $provider['label'] : $slug;
		}
	}

	/**
	 * Ensure import providers are loaded.
	 *
	 * @return void
	 */
	private function ensure_import_providers_loaded() {
		if ( ! $this->import_providers_initialized && did_action( 'init' ) )
			$this->initialize_import_providers();
	}

	/**
	 * Get all import providers.
	 *
	 * @return array
	 */
	public function get_all_providers() {
		$this->ensure_import_providers_loaded();
		return $this->import_providers;
	}

	/**
	 * Get available import providers.
	 *
	 * @return array
	 */
	public function get_available_providers() {
		$this->ensure_import_providers_loaded();

		$available = [];

		foreach ( $this->import_providers as $slug => $provider ) {
			if ( is_callable( $provider['is_available'] ) && call_user_func( $provider['is_available'] ) ) {
				$available[$slug] = $provider;
			}
		}

		return $available;
	}

	/**
	 * Get a specific provider.
	 *
	 * @param string $slug
	 * @return array|null
	 */
	public function get_provider( $slug ) {
		$this->ensure_import_providers_loaded();
		return isset( $this->import_providers[$slug] ) ? $this->import_providers[$slug] : null;
	}

	/**
	 * Get supports for a specific provider.
	 *
	 * @param string $slug
	 * @return array
	 */
	public function get_provider_supports( $slug ) {
		$provider = $this->get_provider( $slug );
		$supports = $provider && isset( $provider['supports'] ) ? $provider['supports'] : [];
		return apply_filters( 'pvc_import_provider_supports', $supports, $slug );
	}

	/**
	 * Get registered import strategies.
	 *
	 * @return array
	 */
	public function get_import_strategies() {
		if ( $this->import_strategies === null ) {
			$this->import_strategies = [
				'override' => [
					'label' => __( 'Override existing views', 'post-views-counter' ),
					'description' => __( 'Replace stored counts with the imported values.', 'post-views-counter' ),
					'pro_only' => false,
					'enabled' => true
				],
				'merge' => [
					'label' => __( 'Merge with existing views', 'post-views-counter' ),
					'description' => __( 'Add imported counts on top of the existing values.', 'post-views-counter' ),
					'pro_only' => false,
					'enabled' => true
				],
				'skip_existing' => [
					'label' => __( 'Skip Existing', 'post-views-counter' ),
					'description' => __( 'Only import data when the target record does not exist yet.', 'post-views-counter' ),
					'pro_only' => true,
					'enabled' => false
				],
				'keep_higher_count' => [
					'label' => __( 'Keep Higher Count', 'post-views-counter' ),
					'description' => __( 'Keep whichever value is higher when comparing imported and stored counts.', 'post-views-counter' ),
					'pro_only' => true,
					'enabled' => false
				],
				'fill_empty_only' => [
					'label' => __( 'Fill Empty Counts', 'post-views-counter' ),
					'description' => __( 'Only import data for posts or periods that currently store zero views.', 'post-views-counter' ),
					'pro_only' => true,
					'enabled' => false
				]
			];

			/**
			 * Filter the available import strategies.
			 *
			 * @since 1.5.10
			 *
			 * @param array $strategies Strategy definitions.
			 * @param Post_Views_Counter_Import $importer Import handler instance.
			 */
			$this->import_strategies = apply_filters( 'pvc_import_strategies', $this->import_strategies, $this );
		}

		return $this->import_strategies;
	}

	/**
	 * Get default import strategy.
	 *
	 * @return string
	 */
	public function get_default_strategy() {
		return $this->default_import_strategy;
	}

	/**
	 * Normalize import strategy against current availability.
	 *
	 * @param string $strategy
	 * @return string
	 */
	public function normalize_strategy( $strategy ) {
		$strategy = sanitize_key( $strategy );

		if ( $this->is_strategy_enabled( $strategy ) ) {
			return $strategy;
		}

		return $this->get_default_strategy();
	}

	/**
	 * Check if a strategy can be used in the current environment.
	 *
	 * @param string $strategy
	 * @return bool
	 */
	public function is_strategy_enabled( $strategy ) {
		$strategy = sanitize_key( $strategy );
		$definition = $this->get_strategy_definition( $strategy );

		if ( $definition === null ) {
			return false;
		}

		if ( array_key_exists( 'enabled', $definition ) ) {
			return (bool) $definition['enabled'];
		}

		// Preserve the pre-1.7.15 contract for filtered definitions that expose
		// only the legacy Pro badge/availability flag.
		if ( ! empty( $definition['pro_only'] ) ) {
			return class_exists( 'Post_Views_Counter_Pro' );
		}

		return true;
	}

	/**
	 * Get strategy definition.
	 *
	 * @param string $strategy
	 * @return array|null
	 */
	private function get_strategy_definition( $strategy ) {
		$strategy = sanitize_key( $strategy );
		$strategies = $this->get_import_strategies();

		return isset( $strategies[ $strategy ] ) ? $strategies[ $strategy ] : null;
	}

	/**
	 * Generate a description for a provider based on its supports.
	 *
	 * @param array  $supports Provider capabilities.
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private function generate_provider_description( $supports, $provider ) {
		$parts = [];

		// Date periods
		$dates = [];
		if ( in_array( 'total', $supports, true ) ) {
			$dates[] = _x( 'total', 'view_counts', 'post-views-counter' );
		}
		if ( in_array( 'yearly', $supports, true ) ) {
			$dates[] = _x( 'yearly', 'view_counts', 'post-views-counter' );
		}
		if ( in_array( 'monthly', $supports, true ) ) {
			$dates[] = _x( 'monthly', 'view_counts', 'post-views-counter' );
		}
		if ( in_array( 'weekly', $supports, true ) ) {
			$dates[] = _x( 'weekly', 'view_counts', 'post-views-counter' );
		}
		if ( in_array( 'daily', $supports, true ) ) {
			$dates[] = _x( 'daily', 'view_counts', 'post-views-counter' );
		}

		// Content types
		$content_labels = [
			'post_types' => _x( 'post types', 'view_counts', 'post-views-counter' )
		];

		$content = [];
		$content_keys = [ 'post_types' ];

		foreach ( $content_keys as $key ) {
			if ( in_array( $key, $supports, true ) && isset( $content_labels[ $key ] ) ) {
				$content[] = $content_labels[ $key ];
			}
		}

		if ( ! empty( $dates ) && ! empty( $content ) ) {
			$parts[] = sprintf( __( 'Imports %s view counts for %s', 'post-views-counter' ), $this->format_list( $dates ), $this->format_list( $content ) );
		} elseif ( ! empty( $dates ) ) {
			$parts[] = sprintf( __( 'Imports %s view counts', 'post-views-counter' ), $this->format_list( $dates ) );
		}

		/**
		 * Filter import provider description parts.
		 *
		 * Runs when a provider's settings fields are rendered.
		 *
		 * @since 1.7.15
		 *
		 * @param array $parts Description sentences without trailing periods.
		 * @param array $context Provider slug and declared supports.
		 * @param Post_Views_Counter_Import $importer Import handler instance.
		 */
		$parts = apply_filters( 'pvc_import_provider_description_parts', $parts, [
			'provider' => sanitize_key( $provider ),
			'supports' => array_values( array_filter( array_map( 'sanitize_key', (array) $supports ) ) )
		], $this );
		$parts = is_array( $parts ) ? array_filter( array_map( 'sanitize_text_field', $parts ) ) : [];

		if ( empty( $parts ) ) {
			return '';
		}

		return implode( '. ', $parts ) . '.';
	}

	/**
	 * Format a list of items into a human-readable string.
	 *
	 * @param array $items
	 * @return string
	 */
	private function format_list( $items ) {
		if ( count( $items ) === 1 ) {
			return $items[0];
		}

		$last = array_pop( $items );
		return implode( ', ', $items ) . ' ' . _x( 'and', 'view_counts', 'post-views-counter' ) . ' ' . $last;
	}

	/**
	 * Handle manual import/analyse action.
	 *
	 * @param array $request
	 * @return array
	 */
	public function handle_manual_action( $request ) {
		// get provider selection
		$provider_slug = isset( $request['pvc_import_provider'] ) ? sanitize_key( $request['pvc_import_provider'] ) : 'custom_meta_key';

		// get import strategy and validate
		$strategy = isset( $request['pvc_import_strategy'] ) ? $this->normalize_strategy( $request['pvc_import_strategy'] ) : $this->get_default_strategy();

		// get provider inputs
		$provider_inputs = isset( $request['pvc_import_provider_inputs'] ) ? $request['pvc_import_provider_inputs'] : [];

		// get available providers
		$providers = $this->get_available_providers();

		// validate provider exists
		if ( ! isset( $providers[$provider_slug] ) ) {
			return [
				'success' => false,
				'message' => __( 'Invalid import provider selected.', 'post-views-counter' ),
				'type' => 'error'
			];
		}

		$provider = $providers[$provider_slug];

		// sanitize provider inputs
		$sanitized_inputs = [];
		if ( is_callable( $provider['sanitize'] ) ) {
			$sanitized_inputs = call_user_func( $provider['sanitize'], $provider_inputs );
		}

		// get main instance
		$pvc = Post_Views_Counter();

		// preserve existing provider settings, only update current provider
		$existing_settings = isset( $pvc->options['other']['import_provider_settings'] ) ? $pvc->options['other']['import_provider_settings'] : [];

		$provider_settings = array_merge(
			$existing_settings,
			[
				'provider' => $provider_slug,
				'strategy' => $strategy,
				$provider_slug => $sanitized_inputs
			]
		);

		$result = [];
		$mode = isset( $request['post_views_counter_analyse_views'] ) ? 'analyze' : ( isset( $request['post_views_counter_import_views'] ) ? 'import' : '' );

		if ( $mode !== '' && $provider_slug !== 'statify' ) {
			/**
			 * Fires before an import provider operation starts.
			 *
			 * @since 1.7.15
			 *
			 * @param array $context Operation mode, provider slug and normalized strategy.
			 * @param Post_Views_Counter_Import $importer Import handler instance.
			 */
			do_action( 'pvc_import_before_provider', [
				'mode' => $mode,
				'provider' => $provider_slug,
				'strategy' => $strategy
			], $this );
		}

		// handle analyse
		if ( isset( $request['post_views_counter_analyse_views'] ) ) {
			if ( is_callable( $provider['analyse'] ) ) {
				$analyse_result = call_user_func( $provider['analyse'], $sanitized_inputs );

					if ( isset( $analyse_result['message'] ) ) {
						$result = [
							'success' => ! isset( $analyse_result['type'] ) || $analyse_result['type'] !== 'error',
							'message' => $analyse_result['message'],
							'type' => isset( $analyse_result['type'] ) ? $analyse_result['type'] : 'updated',
							'provider_settings' => $provider_settings
						];

						if ( isset( $analyse_result['error_code'] ) ) {
							$result['error_code'] = sanitize_key( $analyse_result['error_code'] );
						}
					}
			}
		// handle import
		} elseif ( isset( $request['post_views_counter_import_views'] ) ) {
			if ( is_callable( $provider['import'] ) ) {
				$import_result = call_user_func( $provider['import'], $sanitized_inputs, $strategy );

					if ( isset( $import_result['success'] ) && $import_result['success'] ) {
						$result = [
							'success' => true,
							'message' => $import_result['message'],
							'type' => isset( $import_result['type'] ) ? $import_result['type'] : 'updated',
							'provider_settings' => $provider_settings
						];
					} else if ( isset( $import_result['message'] ) ) {
						$result = [
							'success' => false,
							'message' => $import_result['message'],
							'type' => isset( $import_result['type'] ) ? $import_result['type'] : ( isset( $import_result['success'] ) && ! $import_result['success'] ? 'updated' : 'error' ),
							'provider_settings' => $provider_settings
						];
					}

					if ( isset( $import_result['error_code'] ) ) {
						$result['error_code'] = sanitize_key( $import_result['error_code'] );
					}
			}
		}

		return $result;
	}

	/**
	 * Prepare provider settings from request.
	 *
	 * @param array $request
	 * @return array
	 */
	public function prepare_provider_settings_from_request( $request ) {
		// get existing provider settings or initialize
		$pvc = Post_Views_Counter();
		$existing_settings = isset( $pvc->options['other']['import_provider_settings'] ) ? $pvc->options['other']['import_provider_settings'] : [];

		// check if provider inputs were submitted
		if ( isset( $request['pvc_import_provider'], $request['pvc_import_provider_inputs'], $request['pvc_import_strategy'] ) ) {
			$provider_slug = sanitize_key( $request['pvc_import_provider'] );
			$provider_inputs = $request['pvc_import_provider_inputs'];
			$strategy = $this->normalize_strategy( $request['pvc_import_strategy'] );

			// get available providers
			$providers = $this->get_available_providers();

			// validate provider exists
			if ( isset( $providers[$provider_slug] ) ) {
				$provider = $providers[$provider_slug];

				// sanitize provider inputs
				$sanitized_inputs = [];
				if ( is_callable( $provider['sanitize'] ) ) {
					$sanitized_inputs = call_user_func( $provider['sanitize'], $provider_inputs );
				}

				// update provider settings
				return array_merge(
					$existing_settings,
					[
						'provider' => $provider_slug,
						'strategy' => $strategy,
						$provider_slug => $sanitized_inputs
					]
				);
			}
		}

		// preserve existing settings if not changed
		return $existing_settings;
	}

	/**
	 * Check if WP-PostViews is available.
	 *
	 * @return bool
	 */
	public function is_wp_postviews_available() {
		return function_exists( 'the_views' );
	}

	/**
	 * Render custom meta key provider fields.
	 *
	 * @return string
	 */
	public function render_provider_custom_meta_key() {
		// get main instance
		$pvc = Post_Views_Counter();

		// get saved meta key or default
		$meta_key = isset( $pvc->options['other']['import_provider_settings']['custom_meta_key']['meta_key'] ) ? $pvc->options['other']['import_provider_settings']['custom_meta_key']['meta_key'] : 'views';

		// get provider
		$provider = $this->get_provider( 'custom_meta_key' );

		// generate description
		$description = $this->generate_provider_description( $provider['supports'], 'custom_meta_key' ) . ' ' . esc_html__( 'Enter the meta key from which the views data is to be retrieved during import.', 'post-views-counter' );

		$html = '
		<div class="pvc-provider-fields">
			<input type="text" id="pvc_import_meta_key" class="regular-text" name="pvc_import_provider_inputs[meta_key]" value="' . esc_attr( $meta_key ) . '" />
			<p class="description">' . $description . '</p>
		</div>';

		return $html;
	}

	/**
	 * Sanitize custom meta key provider inputs.
	 *
	 * @param array $inputs
	 * @return array
	 */
	public function sanitize_provider_custom_meta_key( $inputs ) {
		$sanitized = [];

		if ( isset( $inputs['meta_key'] ) ) {
			$sanitized['meta_key'] = sanitize_key( $inputs['meta_key'] );
		}

		return $sanitized;
	}

	/**
	 * Analyse custom meta key provider.
	 *
	 * @global object $wpdb
	 *
	 * @param array $inputs
	 * @return array
	 */
	public function analyse_provider_custom_meta_key( $inputs ) {
		$meta_key = isset( $inputs['meta_key'] ) ? sanitize_key( $inputs['meta_key'] ) : 'views';
		$source = $this->collect_meta_source( $meta_key, 'custom_meta_key' );

		if ( $source['status'] === 'error' ) {
			return [
				'count' => 0,
				'message' => __( 'The source data could not be analyzed because a database operation failed.', 'post-views-counter' ),
				'type' => 'error',
				'error_code' => $source['error_code']
			];
		}

		if ( empty( $source['rows'] ) ) {
			return [
				'count' => 0,
				'message' => sprintf( __( 'No valid views data found for %s.', 'post-views-counter' ), sprintf( __( 'meta key: %s', 'post-views-counter' ), esc_html( $meta_key ) ) )
			];
		}

		$stats = [
			'total_views' => $source['total_views'],
			'posts_processed' => count( $source['totals_map'] ),
			'source' => $this->get_provider_label( 'custom_meta_key' ),
			'additional_info' => sprintf( __( 'Meta key "%s".', 'post-views-counter' ), esc_html( $meta_key ) )
		];

		return [
			'count' => count( $source['rows'] ),
			'message' => $this->generate_import_message( $stats, 'analyze' )
		];
	}

	/**
	 * Import custom meta key provider.
	 *
	 * @global object $wpdb
	 *
	 * @param array $inputs
	 * @param string $strategy
	 * @return array
	 */
	public function import_provider_custom_meta_key( $inputs, $strategy ) {
		global $wpdb;

		$meta_key = isset( $inputs['meta_key'] ) ? sanitize_key( $inputs['meta_key'] ) : 'views';
		$this->start_import_diagnostics( 'custom_meta_key', 'source' );
		$source = $this->collect_meta_source( $meta_key, 'custom_meta_key' );

		if ( $source['status'] === 'error' ) {
			return $this->import_failure_result( 'custom_meta_key', $source['error_code'] );
		}

		if ( empty( $source['rows'] ) ) {
			$this->log_import_diagnostics();
			return [
				'success' => false,
				'message' => __( 'No valid post data found to import.', 'post-views-counter' )
			];
		}

		$sql = [];
		foreach ( $source['rows'] as $view ) {
			$post_id = (int) $view['post_id'];
			$count = (int) $view['meta_value'];

			$sql[] = $wpdb->prepare( "(%d, 4, 'total', %d)", $post_id, $count );
		}

		// the raw source rows are no longer needed once tuples are prepared
		$source['rows'] = null;
		unset( $source['rows'] );

		$tuple_count = count( $sql );
		$existing_totals = $this->snapshot_existing_total_counts( $source['totals_map'], $strategy );

		$destination_start = microtime( true );
		$write_result = $this->execute_provider_insert_query( $sql, $strategy, 'custom_meta_key' );
		$sql = null;
		unset( $sql );
		$this->import_diagnostics['destination_elapsed_ms'] = round( ( microtime( true ) - $destination_start ) * 1000, 3 );
		$this->import_diagnostics['destination_chunks'] = $write_result['chunks'];
		if ( $write_result['status'] === 'error' ) {
			return $this->import_failure_result( 'custom_meta_key', $write_result['error_code'] );
		}

		$stats = [
			'total_views' => $source['total_views'],
			'posts_processed' => count( $source['totals_map'] ),
			'source' => $this->get_provider_label( 'custom_meta_key' ),
			'additional_info' => sprintf( __( 'Meta key "%s".', 'post-views-counter' ), esc_html( $meta_key ) )
		];

		$this->apply_skip_statistics( $stats, $source['totals_map'], $strategy, $existing_totals, $source['compare_map'] );

		// this provider keeps its legacy absence of pvc_import_after_provider,
		// but stored counts changed, so PVC's own caches must be invalidated
		$this->flush_pvc_caches();

		$this->import_diagnostics['destination_rows'] = [ 'total' => $tuple_count ];
		$this->import_diagnostics['phase'] = 'complete';
		$this->log_import_diagnostics();

		return [
			'success' => true,
			'message' => $this->generate_import_message( $stats )
		];
	}

	/**
	 * Render WP-PostViews provider fields.
	 *
	 * @return string
	 */
	public function render_provider_wp_postviews() {
		// get provider
		$provider = $this->get_provider( 'wp_postviews' );

		// generate description
		$description = $this->generate_provider_description( $provider['supports'], 'wp_postviews' );

		$html = '
		<div class="pvc-provider-fields">
			<p class="description">' . $description . '</p>
		</div>';

		return $html;
	}

	/**
	 * Sanitize WP-PostViews provider inputs.
	 *
	 * @param array $inputs
	 * @return array
	 */
	public function sanitize_provider_wp_postviews( $inputs ) {
		// no inputs needed for WP-PostViews
		return [];
	}

	/**
	 * Analyse WP-PostViews provider.
	 *
	 * @global object $wpdb
	 *
	 * @param array $inputs
	 * @return array
	 */
	public function analyse_provider_wp_postviews( $inputs ) {
		$source = $this->collect_meta_source( 'views', 'wp_postviews' );

		if ( $source['status'] === 'error' ) {
			return [
				'count' => 0,
				'message' => __( 'The source data could not be analyzed because a database operation failed.', 'post-views-counter' ),
				'type' => 'error',
				'error_code' => $source['error_code']
			];
		}

		if ( empty( $source['rows'] ) ) {
			// diagnostics are only initialized for imports; analysis must not
			// emit another provider's stale record
			return [
				'count' => 0,
				'message' => sprintf( __( 'No valid views data found for %s.', 'post-views-counter' ), 'WP-PostViews' )
			];
		}

		$stats = [
			'total_views' => $source['total_views'],
			'posts_processed' => count( $source['totals_map'] ),
			'source' => $this->get_provider_label( 'wp_postviews' )
		];

		return [
			'count' => count( $source['rows'] ),
			'message' => $this->generate_import_message( $stats, 'analyze' )
		];
	}

	/**
	 * Import WP-PostViews provider.
	 *
	 * @global object $wpdb
	 *
	 * @param array $inputs
	 * @param string $strategy
	 * @return array
	 */
	public function import_provider_wp_postviews( $inputs, $strategy ) {
		global $wpdb;
		$this->start_import_diagnostics( 'wp_postviews', 'source' );
		$source = $this->collect_meta_source( 'views', 'wp_postviews' );

		if ( $source['status'] === 'error' ) {
			return $this->import_failure_result( 'wp_postviews', $source['error_code'] );
		}

		if ( empty( $source['rows'] ) ) {
			$this->log_import_diagnostics();
			return [
				'success' => false,
				'message' => __( 'No valid post data found to import.', 'post-views-counter' )
			];
		}

		$sql = [];
		foreach ( $source['rows'] as $view ) {
			$post_id = (int) $view['post_id'];
			$count = (int) $view['meta_value'];

			$sql[] = $wpdb->prepare( "(%d, 4, 'total', %d)", $post_id, $count );
		}

		// the raw source rows are no longer needed once tuples are prepared
		$source['rows'] = null;
		unset( $source['rows'] );

		$tuple_count = count( $sql );
		$existing_totals = $this->snapshot_existing_total_counts( $source['totals_map'], $strategy );

		$destination_start = microtime( true );
		$write_result = $this->execute_provider_insert_query( $sql, $strategy, 'wp_postviews' );
		$sql = null;
		unset( $sql );
		$this->import_diagnostics['destination_elapsed_ms'] = round( ( microtime( true ) - $destination_start ) * 1000, 3 );
		$this->import_diagnostics['destination_chunks'] = $write_result['chunks'];
		if ( $write_result['status'] === 'error' ) {
			return $this->import_failure_result( 'wp_postviews', $write_result['error_code'] );
		}

		$stats = [
			'total_views' => $source['total_views'],
			'posts_processed' => count( $source['totals_map'] ),
			'source' => $this->get_provider_label( 'wp_postviews' )
		];

		$this->apply_skip_statistics( $stats, $source['totals_map'], $strategy, $existing_totals, $source['compare_map'] );

		// this provider keeps its legacy absence of pvc_import_after_provider,
		// but stored counts changed, so PVC's own caches must be invalidated
		$this->flush_pvc_caches();

		$this->import_diagnostics['destination_rows'] = [ 'total' => $tuple_count ];
		$this->import_diagnostics['phase'] = 'complete';
		$this->log_import_diagnostics();

		return [
			'success' => true,
			'message' => $this->generate_import_message( $stats )
		];
	}

	/**
	 * Collect one indexed metadata source using shared batch mechanics.
	 *
	 * The retained single read avoids forcing a meta_id sort without a matching
	 * composite meta_key/meta_id index.
	 *
	 * @param string $meta_key Metadata key.
	 * @param string $provider Provider slug.
	 * @return array
	 */
	private function collect_meta_source( $meta_key, $provider ) {
		global $wpdb;

		$collected = [];
		$start = microtime( true );
		$fetch = function ( $cursor, $limit ) use ( $wpdb, $meta_key ) {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value > 0", $meta_key ), ARRAY_A );

			if ( $wpdb->last_error !== '' ) {
				return [ 'status' => 'error', 'rows' => [], 'next_cursor' => null, 'done' => true, 'error_code' => 'meta_source_query_failed' ];
			}

			return [ 'status' => 'success', 'rows' => is_array( $rows ) ? $rows : [], 'next_cursor' => null, 'done' => true, 'error_code' => '' ];
		};
		$consume = function ( $rows ) use ( &$collected ) {
			$collected = $rows;
		};
		$run = $this->run_source_batches( null, $fetch, '__return_false', $consume );

		if ( $run['status'] === 'error' ) {
			return [ 'status' => 'error', 'rows' => [], 'totals_map' => [], 'compare_map' => [], 'total_views' => 0, 'error_code' => $run['error_code'] ];
		}

		$totals_map = [];
		$compare_map = [];
		$total_views = 0;
		foreach ( $collected as $row ) {
			$post_id = (int) $row['post_id'];
			$count = (int) $row['meta_value'];
			$total_views += $count;
			$totals_map[ $post_id ] = ( isset( $totals_map[ $post_id ] ) ? $totals_map[ $post_id ] : 0 ) + $count;
			$compare_map[ $post_id ] = isset( $compare_map[ $post_id ] ) ? max( $compare_map[ $post_id ], $count ) : $count;
		}

		if ( ! empty( $this->import_diagnostics ) && isset( $this->import_diagnostics['provider'] ) && $this->import_diagnostics['provider'] === $provider ) {
			$this->import_diagnostics['raw_rows'] = count( $collected );
			$this->import_diagnostics['logical_rows'] = count( $collected );
			$this->import_diagnostics['distinct_posts'] = count( $totals_map );
			$this->import_diagnostics['source_batches'] = $run['batches'];
			$this->import_diagnostics['source_elapsed_ms'] = round( ( microtime( true ) - $start ) * 1000, 3 );
		}

		return [
			'status' => 'success',
			'rows' => $collected,
			'totals_map' => $totals_map,
			'compare_map' => $compare_map,
			'total_views' => $total_views,
			'error_code' => ''
		];
	}

	/**
	 * Check if Statify is available.
	 *
	 * @return bool
	 */
	public function is_statify_available() {
		global $wpdb;
		$table = esc_sql( isset( $wpdb->statify ) ? $wpdb->statify : $wpdb->prefix . 'statify' );
		return class_exists( 'Statify' ) && $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table;
	}

	/**
	 * Render Statify provider fields.
	 *
	 * @return string
	 */
	public function render_provider_statify() {
		// get provider
		$provider = $this->get_provider( 'statify' );

		// generate description
		$description = $this->generate_provider_description( $provider['supports'], 'statify' );

		$html = '
		<div class="pvc-provider-fields">
			<p class="description">' . $description . '</p>
		</div>';

		return $html;
	}

	/**
	 * Sanitize Statify provider inputs.
	 *
	 * @param array $inputs
	 * @return array
	 */
	public function sanitize_provider_statify( $inputs ) {
		// no inputs needed for Statify
		return [];
	}

	/**
	 * Analyse Statify provider.
	 *
	 * @global object $wpdb
	 *
	 * @param array $inputs
	 * @return array
	 */
	public function analyse_provider_statify( $inputs ) {
		return $this->process_statify_provider( 'analyze', 'merge' );
	}

	/**
	 * Import Statify provider.
	 *
	 * @global object $wpdb
	 *
	 * @param array $inputs
	 * @param string $strategy
	 * @return array
	 */
	public function import_provider_statify( $inputs, $strategy ) {
		return $this->process_statify_provider( 'import', $strategy );
	}

	/**
	 * Analyze or import Statify through target-keyset source batches.
	 *
	 * @param string $mode analyze|import.
	 * @param string $strategy Import strategy.
	 * @return array
	 */
	private function process_statify_provider( $mode, $strategy ) {
		global $wpdb;

		/**
		 * Fires before a Statify provider operation starts.
		 *
		 * This provider-level boundary also covers direct calls to the public
		 * Statify analyze and import methods.
		 *
		 * @since 1.7.15
		 *
		 * @param array $context Operation mode, provider slug and normalized strategy.
		 * @param Post_Views_Counter_Import $importer Import handler instance.
		 */
		do_action( 'pvc_import_before_provider', [
			'mode' => $mode,
			'provider' => 'statify',
			'strategy' => $strategy
		], $this );

		if ( $mode === 'import' ) {
			$this->start_import_diagnostics( 'statify', 'source' );
		}

		$source = $this->collect_statify_source( $mode, $strategy );
		if ( $source['status'] === 'error' ) {
			if ( $mode === 'import' ) {
				return $this->import_failure_result( 'statify', $source['error_code'] );
			}

			return [
				'count' => 0,
				'message' => __( 'The Statify source could not be analyzed because a database operation failed.', 'post-views-counter' ),
				'type' => 'error',
				'error_code' => $source['error_code']
			];
		}

		// rows accepted through pvc_import_handle_non_post_row are valid provider
		// work even when no core post tuples remain, so the operation must still
		// reach its completion boundary and message filter
		$handled_rows = isset( $source['handled_rows'] ) ? (int) $source['handled_rows'] : 0;

		if ( empty( $source['stats'] ) && $handled_rows === 0 ) {
			if ( $mode === 'analyze' ) {
				return [
					'count' => 0,
					'message' => sprintf( __( 'No valid views data found for %s.', 'post-views-counter' ), 'Statify' )
				];
			}

			$message = __( 'No valid post data found to import.', 'post-views-counter' );

			// safe configuration hint; never a sample of skipped source URLs
			if ( empty( $source['tracked_post_types'] ) ) {
				$message .= ' ' . __( 'No post types are selected for tracking in settings.', 'post-views-counter' );
			}

			$this->log_import_diagnostics();

			return [ 'success' => false, 'message' => $message ];
		}

		$period_counts = [ 'daily' => 0, 'weekly' => 0, 'monthly' => 0, 'yearly' => 0 ];
		$sql_parts = [];
		$totals_map = [];
		foreach ( $source['stats'] as $post_id => $periods ) {
			foreach ( [ 'daily' => 0, 'weekly' => 1, 'monthly' => 2, 'yearly' => 3 ] as $period_type => $type ) {
				foreach ( $periods[ $period_type ] as $period => $count ) {
					$period_counts[ $period_type ]++;
					if ( $mode === 'import' ) {
						$sql_parts[] = $wpdb->prepare( '(%d, %d, %s, %d)', $post_id, $type, $period, $count );
					}
				}
			}

			$totals_map[ $post_id ] = $periods['total'];
			if ( $mode === 'import' ) {
				$sql_parts[] = $wpdb->prepare( "(%d, 4, 'total', %d)", $post_id, $periods['total'] );
			}
		}

		// the normalized period structure is redundant once tuples exist
		$source['stats'] = null;
		unset( $source['stats'] );

		if ( $mode === 'import' ) {
			// eligibility must be measured before the destination changes
			$existing_totals = $this->snapshot_existing_total_counts( $totals_map, $strategy );

			$destination_start = microtime( true );
			$write_result = $this->execute_provider_insert_query( $sql_parts, $strategy, 'statify' );
			$sql_parts = null;
			unset( $sql_parts );
			$this->import_diagnostics['destination_elapsed_ms'] = round( ( microtime( true ) - $destination_start ) * 1000, 3 );
			$this->import_diagnostics['destination_chunks'] = $write_result['chunks'];
			if ( $write_result['status'] === 'error' ) {
				return $this->import_failure_result( 'statify', $write_result['error_code'] );
			}

			do_action( 'pvc_import_after_provider', [
				'strategy' => $strategy,
				'source' => 'statify',
				'use_gmt' => $source['use_gmt']
			] );

			$this->flush_pvc_caches();
		}

		$message_stats = [
			'total_views' => array_sum( $totals_map ),
			'posts_processed' => count( $totals_map ),
			'periods' => $period_counts,
			'source' => $this->get_provider_label( 'statify' )
		];

		if ( empty( $totals_map ) ) {
			$message_stats['no_post_rows_handled'] = true;
		}

		if ( ! empty( $source['skipped_targets'] ) ) {
			$message_stats['additional_info'] = sprintf(
				$mode === 'analyze' ? __( 'Would skip %s non-post URLs.', 'post-views-counter' ) : __( 'Skipped %s non-post URLs.', 'post-views-counter' ),
				number_format_i18n( count( $source['skipped_targets'] ) )
			);
		}

		if ( $mode === 'import' ) {
			$this->apply_skip_statistics( $message_stats, $totals_map, $strategy, $existing_totals );
		}

		$message_stats = apply_filters( 'pvc_import_message_stats', $message_stats, [
			'mode' => $mode,
			'source' => 'statify'
		] );

		if ( $mode === 'import' ) {
			$this->import_diagnostics['destination_rows'] = array_merge( $period_counts, [ 'total' => count( $totals_map ) ] );
			$this->import_diagnostics['phase'] = 'complete';
			$this->log_import_diagnostics();
			return [ 'success' => true, 'message' => $this->generate_import_message( $message_stats ) ];
		}

		return [ 'count' => array_sum( $totals_map ), 'message' => $this->generate_import_message( $message_stats, 'analyze' ) ];
	}

	/**
	 * Collect normalized Statify counts from target-keyset batches.
	 *
	 * @param string $mode analyze|import.
	 * @param string $strategy Import strategy.
	 * @return array
	 */
	private function collect_statify_source( $mode, $strategy ) {
		global $wpdb;

		$table = esc_sql( isset( $wpdb->statify ) ? $wpdb->statify : $wpdb->prefix . 'statify' );
		$settings = Post_Views_Counter()->options['general'];
		$use_gmt = $settings['count_time'] === 'gmt';
		$tracked_post_types = array_map( 'sanitize_key', (array) $settings['post_types_count'] );
		$this->statify_post_cache = [];

		$wpdb->last_error = '';
		$snapshot = $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" );
		if ( $wpdb->last_error !== '' ) {
			return [ 'status' => 'error', 'error_code' => 'statify_snapshot_failed' ];
		}

		if ( $snapshot === null ) {
			return [
				'status' => 'success',
				'stats' => [],
				'skipped_targets' => [],
				'handled_rows' => 0,
				'tracked_post_types' => $tracked_post_types,
				'use_gmt' => $use_gmt,
				'error_code' => ''
			];
		}

		$snapshot = (int) $snapshot;
		$stats = [];
		$skipped_targets = [];
		$handled_rows = 0;
		$raw_rows = 0;
		$logical_rows = 0;
		$date_min = '';
		$date_max = '';
		$mapping_elapsed = 0;
		$source_start = microtime( true );

		$fetch = function ( $cursor, $limit ) use ( $wpdb, $table, $snapshot ) {
			$wpdb->last_error = '';
			if ( $cursor === null ) {
				$target_query = $wpdb->prepare( "SELECT DISTINCT target FROM `{$table}` WHERE id <= %d ORDER BY target LIMIT %d", $snapshot, $limit );
			} else {
				$target_query = $wpdb->prepare( "SELECT DISTINCT target FROM `{$table}` WHERE id <= %d AND target > %s ORDER BY target LIMIT %d", $snapshot, $cursor, $limit );
			}
			$targets = $wpdb->get_col( $target_query );
			if ( $wpdb->last_error !== '' ) {
				return [ 'status' => 'error', 'rows' => [], 'next_cursor' => $cursor, 'done' => true, 'error_code' => 'statify_target_batch_failed' ];
			}

			if ( empty( $targets ) ) {
				return [ 'status' => 'success', 'rows' => [], 'next_cursor' => $cursor, 'done' => true, 'error_code' => '' ];
			}

			$placeholders = implode( ',', array_fill( 0, count( $targets ), '%s' ) );
			$query_args = array_merge( [ $snapshot ], $targets );
			$aggregate_query = $wpdb->prepare( "SELECT target, created, COUNT(*) AS views FROM `{$table}` WHERE id <= %d AND target IN ({$placeholders}) GROUP BY target, created ORDER BY target, created", $query_args );
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $aggregate_query, ARRAY_A );
			if ( $wpdb->last_error !== '' ) {
				return [ 'status' => 'error', 'rows' => [], 'next_cursor' => end( $targets ), 'done' => true, 'error_code' => 'statify_aggregate_batch_failed' ];
			}

			return [
				'status' => 'success',
				'rows' => is_array( $rows ) ? $rows : [],
				'next_cursor' => end( $targets ),
				'done' => count( $targets ) < $limit,
				'error_code' => ''
			];
		};

		// the SQL predicate target > last_target is authoritative; MySQL orders by
		// the column collation, so a PHP byte comparison would reject legitimate
		// mixed-case and non-ASCII progress. Only identity is checked here, with
		// MAX_SOURCE_BATCHES remaining the runaway-loop guard.
		$advanced = function ( $cursor, $next_cursor ) {
			return is_string( $next_cursor ) && $next_cursor !== $cursor;
		};

		$consume = function ( $rows ) use ( &$stats, &$skipped_targets, &$handled_rows, &$raw_rows, &$logical_rows, &$date_min, &$date_max, &$mapping_elapsed, $tracked_post_types, $mode, $strategy, $use_gmt ) {
			foreach ( $rows as $row ) {
				$views = (int) $row['views'];
				$raw_rows += $views;
				$logical_rows++;

				$mapping_start = microtime( true );
				$content = $this->map_target_to_content( $row['target'], $tracked_post_types, 'statify' );
				$mapping_elapsed += microtime( true ) - $mapping_start;
				$post_id = isset( $content['content_id'] ) ? (int) $content['content_id'] : 0;
				if ( ! $post_id ) {
					$skipped_targets[ $row['target'] ] = true;
					continue;
				}

				$timestamp = strtotime( $row['created'] );
				$period_keys = $this->get_period_keys_from_timestamp( $timestamp, $use_gmt );
				if ( isset( $content['content_type'] ) && $content['content_type'] !== 'post' ) {
					$non_post_context = [
						'mode' => $mode,
						'source' => 'statify',
						'row' => $row,
						'content' => $content,
						'period_keys' => $period_keys,
						'timestamp' => $timestamp,
						'use_gmt' => $use_gmt
					];
					if ( $mode === 'import' ) {
						$non_post_context['strategy'] = $strategy;
					}
					$handled = apply_filters( 'pvc_import_handle_non_post_row', false, $non_post_context, $this );
					if ( $handled ) {
						$handled_rows++;
						continue;
					}
					$skipped_targets[ $row['target'] ] = true;
					continue;
				}

				if ( $this->is_valid_source_date( $row['created'] ) ) {
					$date_min = $date_min === '' || $row['created'] < $date_min ? $row['created'] : $date_min;
					$date_max = $date_max === '' || $row['created'] > $date_max ? $row['created'] : $date_max;
				}

				if ( ! isset( $stats[ $post_id ] ) ) {
					$stats[ $post_id ] = [ 'daily' => [], 'weekly' => [], 'monthly' => [], 'yearly' => [], 'total' => 0 ];
				}

				foreach ( [ 'day' => 'daily', 'week' => 'weekly', 'month' => 'monthly', 'year' => 'yearly' ] as $key => $period_type ) {
					$period = $period_keys[ $key ];
					$stats[ $post_id ][ $period_type ][ $period ] = ( isset( $stats[ $post_id ][ $period_type ][ $period ] ) ? $stats[ $post_id ][ $period_type ][ $period ] : 0 ) + $views;
				}
				$stats[ $post_id ]['total'] += $views;
			}
		};

		$run = $this->run_source_batches( null, $fetch, $advanced, $consume );
		if ( $run['status'] === 'error' ) {
			return [ 'status' => 'error', 'error_code' => $run['error_code'] ];
		}

		if ( $mode === 'import' ) {
			$this->import_diagnostics['snapshot_boundary'] = $snapshot;
			$this->import_diagnostics['raw_rows'] = $raw_rows;
			$this->import_diagnostics['logical_rows'] = $logical_rows;
			$this->import_diagnostics['distinct_targets'] = count( $this->statify_post_cache );
			$this->import_diagnostics['distinct_posts'] = count( $stats );
			$this->import_diagnostics['skipped_count'] = count( $skipped_targets );
			$this->import_diagnostics['handled_non_post_rows'] = $handled_rows;
			$this->import_diagnostics['source_date_min'] = $date_min;
			$this->import_diagnostics['source_date_max'] = $date_max;
			$this->import_diagnostics['source_batches'] = $run['batches'];
			$this->import_diagnostics['source_elapsed_ms'] = round( max( 0, ( microtime( true ) - $source_start ) - $mapping_elapsed ) * 1000, 3 );
			$this->import_diagnostics['mapping_elapsed_ms'] = round( $mapping_elapsed * 1000, 3 );
		}

		return [
			'status' => 'success',
			'stats' => $stats,
			'skipped_targets' => $skipped_targets,
			'handled_rows' => $handled_rows,
			'tracked_post_types' => $tracked_post_types,
			'use_gmt' => $use_gmt,
			'error_code' => ''
		];
	}

	/**
	 * Check if Page Views Count is available.
	 *
	 * @return bool
	 */
	public function is_page_views_count_available() {
		global $wpdb;
		$table_total = $wpdb->prefix . 'pvc_total';
		$table_daily = $wpdb->prefix . 'pvc_daily';
		return $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_total ) ) === $table_total &&
			$wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_daily ) ) === $table_daily;
	}

	/**
	 * Render Page Views Count provider fields.
	 *
	 * @return string
	 */
	public function render_provider_page_views_count() {
		// get provider
		$provider = $this->get_provider( 'page_views_count' );

		// generate description
		$description = $this->generate_provider_description( $provider['supports'], 'page_views_count' );

		$html = '
		<div class="pvc-provider-fields">
			<p class="description">' . $description . '</p>
		</div>';

		return $html;
	}

	/**
	 * Sanitize Page Views Count provider inputs.
	 *
	 * @param array $inputs
	 * @return array
	 */
	public function sanitize_provider_page_views_count( $inputs ) {
		// no inputs needed for Page Views Count
		return [];
	}

	/**
	 * Analyse Page Views Count provider.
	 *
	 * @global object $wpdb
	 *
	 * @param array $inputs
	 * @return array
	 */
	public function analyse_provider_page_views_count( $inputs ) {
		return $this->process_page_views_count_provider( 'analyze', 'merge' );
	}

	/**
	 * Import Page Views Count provider.
	 *
	 * @global object $wpdb
	 *
	 * @param array $inputs
	 * @param string $strategy
	 * @return array
	 */
	public function import_provider_page_views_count( $inputs, $strategy ) {
		return $this->process_page_views_count_provider( 'import', $strategy );
	}

	/**
	 * Analyze or import normalized Page Views Count streams.
	 *
	 * @param string $mode analyze|import.
	 * @param string $strategy Import strategy.
	 * @return array
	 */
	private function process_page_views_count_provider( $mode, $strategy ) {
		global $wpdb;

		if ( $mode === 'import' ) {
			$this->start_import_diagnostics( 'page_views_count', 'source' );
		}

		$source = $this->collect_page_views_count_source( $mode );
		if ( $source['status'] === 'error' ) {
			if ( $mode === 'import' ) {
				return $this->import_failure_result( 'page_views_count', $source['error_code'] );
			}

			return [
				'count' => 0,
				'message' => __( 'The Page Views Count source could not be analyzed because a database operation failed.', 'post-views-counter' ),
				'type' => 'error',
				'error_code' => $source['error_code']
			];
		}

		if ( empty( $source['posts'] ) ) {
			$message = $mode === 'analyze'
				? sprintf( __( 'No valid views data found for %s.', 'post-views-counter' ), 'Page Views Count' )
				: __( 'No valid post data found to import.', 'post-views-counter' );
			if ( $mode === 'import' ) {
				$this->log_import_diagnostics();
			}
			return $mode === 'analyze' ? [ 'count' => 0, 'message' => $message ] : [ 'success' => false, 'message' => $message ];
		}

		$period_counts = [ 'daily' => 0, 'weekly' => 0, 'monthly' => 0, 'yearly' => 0 ];
		$sql_parts = [];
		if ( $mode === 'import' ) {
			foreach ( $source['totals'] as $post_id => $count ) {
				$sql_parts[] = $wpdb->prepare( "(%d, 4, 'total', %d)", $post_id, $count );
			}
		}

		foreach ( $source['periods'] as $post_id => $periods ) {
			foreach ( [ 'daily' => 0, 'weekly' => 1, 'monthly' => 2, 'yearly' => 3 ] as $period_type => $type ) {
				foreach ( $periods[ $period_type ] as $period => $count ) {
					$period_counts[ $period_type ]++;
					if ( $mode === 'import' ) {
						$sql_parts[] = $wpdb->prepare( '(%d, %d, %s, %d)', $post_id, $type, $period, $count );
					}
				}
			}
		}

		if ( $mode === 'import' ) {
			// eligibility must be measured before the destination changes
			$existing_totals = $this->snapshot_existing_total_counts( $source['totals'], $strategy );

			$destination_start = microtime( true );
			$write_result = $this->execute_provider_insert_query( $sql_parts, $strategy, 'page_views_count' );
			$sql_parts = null;
			unset( $sql_parts );
			$this->import_diagnostics['destination_elapsed_ms'] = round( ( microtime( true ) - $destination_start ) * 1000, 3 );
			$this->import_diagnostics['destination_chunks'] = $write_result['chunks'];
			if ( $write_result['status'] === 'error' ) {
				return $this->import_failure_result( 'page_views_count', $write_result['error_code'] );
			}

			do_action( 'pvc_import_after_provider', [
				'strategy' => $strategy,
				'source' => 'page_views_count',
				'use_gmt' => false
			] );
			$this->flush_pvc_caches();
		}

		$period_only_posts = 0;
		foreach ( $source['periods'] as $post_id => $periods_data ) {
			if ( ! isset( $source['totals'][ $post_id ] ) ) {
				$period_only_posts++;
			}
		}

		// the total-views sentence describes posts with valid source totals only;
		// daily-only posts are reported once, as additional posts
		$message_stats = [
			'total_views' => array_sum( $source['totals'] ),
			'posts_processed' => count( $source['totals'] ),
			'source' => $this->get_provider_label( 'page_views_count' )
		];
		if ( empty( $source['totals'] ) ) {
			$message_stats['period_data_only'] = true;
			$message_stats['posts_processed'] = count( $source['periods'] );
		} elseif ( $period_only_posts > 0 ) {
			$message_stats['period_only_posts'] = $period_only_posts;
		}
		if ( $mode === 'import' ) {
			$message_stats['periods'] = $period_counts;
			$this->apply_skip_statistics( $message_stats, $source['totals'], $strategy, $existing_totals );

			// analysis intentionally keeps its legacy absence of this filter
			$message_stats = apply_filters( 'pvc_import_message_stats', $message_stats, [
				'mode' => $mode,
				'source' => 'page_views_count'
			] );

			$this->import_diagnostics['destination_rows'] = array_merge( $period_counts, [ 'total' => count( $source['totals'] ) ] );
			$this->import_diagnostics['phase'] = 'complete';
			$this->log_import_diagnostics();
			return [ 'success' => true, 'message' => $this->generate_import_message( $message_stats ) ];
		}

		return [
			'count' => array_sum( $source['totals'] ) + $source['daily_views'],
			'message' => $this->generate_import_message( $message_stats, 'analyze' )
		];
	}

	/**
	 * Read Page Views Count totals and dailies as independent ID streams.
	 *
	 * @param string $mode analyze|import.
	 * @return array
	 */
	private function collect_page_views_count_source( $mode ) {
		global $wpdb;

		$total_table = esc_sql( $wpdb->prefix . 'pvc_total' );
		$daily_table = esc_sql( $wpdb->prefix . 'pvc_daily' );
		$tracked_post_types = array_map( 'sanitize_key', (array) Post_Views_Counter()->options['general']['post_types_count'] );
		$post_types = [];
		$totals = [];
		$periods = [];
		$posts = [];
		$daily_views = 0;
		$raw_rows = 0;
		$date_min = '';
		$date_max = '';
		$source_start = microtime( true );

		$wpdb->last_error = '';
		$total_snapshot = $wpdb->get_var( "SELECT MAX(id) FROM `{$total_table}`" );
		if ( $wpdb->last_error !== '' ) {
			return [ 'status' => 'error', 'error_code' => 'page_views_total_snapshot_failed' ];
		}
		$wpdb->last_error = '';
		$daily_snapshot = $wpdb->get_var( "SELECT MAX(id) FROM `{$daily_table}`" );
		if ( $wpdb->last_error !== '' ) {
			return [ 'status' => 'error', 'error_code' => 'page_views_daily_snapshot_failed' ];
		}

		$total_snapshot = $total_snapshot === null ? 0 : (int) $total_snapshot;
		$daily_snapshot = $daily_snapshot === null ? 0 : (int) $daily_snapshot;
		$advanced = function ( $cursor, $next_cursor ) {
			return (int) $next_cursor > (int) $cursor;
		};

		$total_fetch = function ( $cursor, $limit ) use ( $wpdb, $total_table, $total_snapshot ) {
			if ( $total_snapshot === 0 ) {
				return [ 'status' => 'success', 'rows' => [], 'next_cursor' => $cursor, 'done' => true, 'error_code' => '' ];
			}
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, postnum, postcount FROM `{$total_table}` WHERE id > %d AND id <= %d ORDER BY id LIMIT %d", $cursor, $total_snapshot, $limit ), ARRAY_A );
			if ( $wpdb->last_error !== '' ) {
				return [ 'status' => 'error', 'rows' => [], 'next_cursor' => $cursor, 'done' => true, 'error_code' => 'page_views_total_batch_failed' ];
			}
			$next_cursor = empty( $rows ) ? $cursor : (int) $rows[ count( $rows ) - 1 ]['id'];
			return [ 'status' => 'success', 'rows' => $rows, 'next_cursor' => $next_cursor, 'done' => count( $rows ) < $limit, 'error_code' => '' ];
		};
		$total_consume = function ( $rows ) use ( &$totals, &$posts, &$post_types, &$raw_rows, $tracked_post_types ) {
			foreach ( $rows as $row ) {
				$raw_rows++;
				$post_id = $this->parse_positive_integer( $row['postnum'] );
				$count = $this->parse_positive_integer( $row['postcount'] );
				if ( ! $post_id || ! $count || ! $this->is_tracked_source_post( $post_id, $tracked_post_types, $post_types ) ) {
					continue;
				}
				$totals[ $post_id ] = $count;
				$posts[ $post_id ] = true;
			}
		};
		$total_run = $this->run_source_batches( 0, $total_fetch, $advanced, $total_consume );
		if ( $total_run['status'] === 'error' ) {
			return [ 'status' => 'error', 'error_code' => $total_run['error_code'] ];
		}

		$daily_fetch = function ( $cursor, $limit ) use ( $wpdb, $daily_table, $daily_snapshot ) {
			if ( $daily_snapshot === 0 ) {
				return [ 'status' => 'success', 'rows' => [], 'next_cursor' => $cursor, 'done' => true, 'error_code' => '' ];
			}
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, time, postnum, postcount FROM `{$daily_table}` WHERE id > %d AND id <= %d ORDER BY id LIMIT %d", $cursor, $daily_snapshot, $limit ), ARRAY_A );
			if ( $wpdb->last_error !== '' ) {
				return [ 'status' => 'error', 'rows' => [], 'next_cursor' => $cursor, 'done' => true, 'error_code' => 'page_views_daily_batch_failed' ];
			}
			$next_cursor = empty( $rows ) ? $cursor : (int) $rows[ count( $rows ) - 1 ]['id'];
			return [ 'status' => 'success', 'rows' => $rows, 'next_cursor' => $next_cursor, 'done' => count( $rows ) < $limit, 'error_code' => '' ];
		};
		$daily_consume = function ( $rows ) use ( &$periods, &$posts, &$post_types, &$daily_views, &$raw_rows, &$date_min, &$date_max, $tracked_post_types ) {
			foreach ( $rows as $row ) {
				$raw_rows++;
				$post_id = $this->parse_positive_integer( $row['postnum'] );
				$count = $this->parse_positive_integer( $row['postcount'] );
				if ( ! $post_id || ! $count || ! $this->is_valid_source_date( $row['time'] ) || ! $this->is_tracked_source_post( $post_id, $tracked_post_types, $post_types ) ) {
					continue;
				}

				$timestamp = strtotime( $row['time'] . ' 00:00:00' );
				$keys = $this->get_period_keys_from_timestamp( $timestamp, false );
				if ( ! isset( $periods[ $post_id ] ) ) {
					$periods[ $post_id ] = [ 'daily' => [], 'weekly' => [], 'monthly' => [], 'yearly' => [] ];
				}
				foreach ( [ 'day' => 'daily', 'week' => 'weekly', 'month' => 'monthly', 'year' => 'yearly' ] as $key => $period_type ) {
					$period = $keys[ $key ];
					$periods[ $post_id ][ $period_type ][ $period ] = ( isset( $periods[ $post_id ][ $period_type ][ $period ] ) ? $periods[ $post_id ][ $period_type ][ $period ] : 0 ) + $count;
				}
				$daily_views += $count;
				$posts[ $post_id ] = true;
				$date_min = $date_min === '' || $row['time'] < $date_min ? $row['time'] : $date_min;
				$date_max = $date_max === '' || $row['time'] > $date_max ? $row['time'] : $date_max;
			}
		};
		$daily_run = $this->run_source_batches( 0, $daily_fetch, $advanced, $daily_consume );
		if ( $daily_run['status'] === 'error' ) {
			return [ 'status' => 'error', 'error_code' => $daily_run['error_code'] ];
		}

		ksort( $totals );
		ksort( $periods );
		if ( $mode === 'import' ) {
			$this->import_diagnostics['snapshot_boundary'] = [ 'total' => $total_snapshot, 'daily' => $daily_snapshot ];
			$this->import_diagnostics['raw_rows'] = $raw_rows;
			$this->import_diagnostics['logical_rows'] = count( $totals ) + array_sum( array_map( function ( $item ) { return count( $item['daily'] ); }, $periods ) );
			$this->import_diagnostics['distinct_posts'] = count( $posts );
			$this->import_diagnostics['source_date_min'] = $date_min;
			$this->import_diagnostics['source_date_max'] = $date_max;
			$this->import_diagnostics['source_batches'] = $total_run['batches'] + $daily_run['batches'];
			$this->import_diagnostics['source_elapsed_ms'] = round( ( microtime( true ) - $source_start ) * 1000, 3 );
		}

		return [
			'status' => 'success',
			'totals' => $totals,
			'periods' => $periods,
			'posts' => $posts,
			'daily_views' => $daily_views,
			'error_code' => ''
		];
	}

	/**
	 * Parse a positive decimal integer without accepting partial strings.
	 *
	 * @param mixed $value Source value.
	 * @return int
	 */
	private function parse_positive_integer( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( $value === '' || preg_match( '/^\d+$/', $value ) !== 1 ) {
			return 0;
		}

		$value = (int) $value;
		return $value > 0 ? $value : 0;
	}

	/**
	 * Validate a source date exactly.
	 *
	 * Rejects malformed values, zero dates, year zero and impossible calendar
	 * dates. This strict policy applies to Page Views Count source rows and to
	 * the reported source date range; Statify row handling is unchanged (D8-8).
	 *
	 * @param mixed $value Source date.
	 * @return bool
	 */
	private function is_valid_source_date( $value ) {
		if ( ! is_string( $value ) || preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) !== 1 ) {
			return false;
		}

		$year = (int) $parts[1];

		// a four-digit format still allows year zero, which is not a real date
		if ( $year < 1 || ! checkdate( (int) $parts[2], (int) $parts[3], $year ) ) {
			return false;
		}

		$date = DateTime::createFromFormat( '!Y-m-d', $value );
		return $date instanceof DateTime && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Resolve and cache whether a source post is tracked.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $tracked_post_types Tracked types.
	 * @param array $cache Request-local type cache.
	 * @return bool
	 */
	private function is_tracked_source_post( $post_id, $tracked_post_types, &$cache ) {
		if ( ! array_key_exists( $post_id, $cache ) ) {
			$cache[ $post_id ] = get_post_type( $post_id );
		}

		return $cache[ $post_id ] && in_array( $cache[ $post_id ], $tracked_post_types, true );
	}

	/**
	 * Get SQL clause for the provided strategy.
	 *
	 * @param string $strategy
	 * @param string $provider
	 * @return string
	 */
	private function get_strategy_on_duplicate_clause( $strategy, $provider ) {
		$strategy_key = sanitize_key( $strategy );

		$clauses = [
			'override' => 'count = VALUES(count)',
			'merge' => 'count = count + VALUES(count)'
		];

		$clause = isset( $clauses[ $strategy_key ] ) ? $clauses[ $strategy_key ] : $clauses['merge'];

		/**
		 * Filter the SQL ON DUPLICATE KEY UPDATE clause used during import.
		 *
		 * @since 1.5.10
		 *
		 * @param string $clause SQL clause for the duplicate key handler.
		 * @param array  $context {
		 *     @type string $strategy Selected strategy key.
		 *     @type string $provider Provider slug.
		 * }
		 * @param Post_Views_Counter_Import $importer Import handler instance.
		 */
		return apply_filters( 'pvc_import_strategy_clause', $clause, [
			'strategy' => $strategy_key,
			'provider' => $provider
		], $this );
	}

	/**
	 * Execute a provider insert query.
	 *
	 * @param array $sql_parts Prepared SQL value tuples.
	 * @param string $strategy Selected strategy.
	 * @param string $provider Provider slug.
	 * @return array Structured result with status, affected_rows, chunks and error_code.
	 */
	private function execute_provider_insert_query( $sql_parts, $strategy, $provider ) {
		global $wpdb;

		if ( empty( $sql_parts ) ) {
			return $this->destination_success_result( 0, 0, 'not_applicable' );
		}

		$on_duplicate = $this->get_strategy_on_duplicate_clause( $strategy, $provider );
		$table = $wpdb->prefix . 'post_views';
		$query_prefix = "INSERT INTO {$table} (id, type, period, count) VALUES ";
		$query_suffix = " ON DUPLICATE KEY UPDATE {$on_duplicate}";
		$has_query_filter = has_filter( 'pvc_import_provider_query' ) !== false;

		// derive the equivalent legacy query size without joining every tuple
		$query_bytes = strlen( $query_prefix ) + strlen( $query_suffix ) + count( $sql_parts ) - 1;
		foreach ( $sql_parts as $tuple ) {
			$query_bytes += strlen( $tuple );
		}

		$this->import_diagnostics['destination_tuple_count'] = count( $sql_parts );
		$this->import_diagnostics['destination_query_bytes'] = $query_bytes;

		if ( $has_query_filter ) {
			$query = $query_prefix . implode( ',', $sql_parts ) . $query_suffix;

			/**
			 * Filter the SQL query used when inserting provider data.
			 *
			 * Returning false skips the default query. The legacy full query and
			 * complete context are passed exactly once when callbacks are attached.
			 *
			 * @since 1.5.10
			 *
			 * @param string|false $query SQL query string or false to skip execution.
			 * @param array        $context Provider, strategy, tuples and clause.
			 * @param Post_Views_Counter_Import $importer Import handler instance.
			 */
			$query = apply_filters( 'pvc_import_provider_query', $query, [
				'strategy' => sanitize_key( $strategy ),
				'provider' => $provider,
				'sql_parts' => $sql_parts,
				'on_duplicate' => $on_duplicate
			], $this );

			if ( $query === false ) {
				return [
					'status' => 'handled',
					'affected_rows' => 0,
					'affected_rows_known' => true,
					'chunks' => 0,
					'transaction_state' => 'not_applicable',
					'error_code' => ''
				];
			}

			if ( ! is_string( $query ) || $wpdb->query( $query ) === false ) {
				return $this->destination_error_result( 'destination_write_failed', 1, 'not_applicable', 'destination_write_failed' );
			}

			return $this->destination_success_result( (int) $wpdb->rows_affected, 1, 'not_applicable' );
		}

		$engine = $this->get_destination_engine( $table );
		if ( $engine !== 'innodb' ) {
			$result = $wpdb->query( $query_prefix . implode( ',', $sql_parts ) . $query_suffix );
			if ( $result === false ) {
				return $this->destination_error_result( 'destination_write_failed', 1, 'not_applicable', 'destination_write_failed' );
			}

			return $this->destination_success_result( (int) $result, 1, 'not_applicable' );
		}

		if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
			return $this->destination_error_result( 'transaction_start_failed', 0, 'not_started', 'transaction_start_failed' );
		}

		$affected_rows = 0;
		$chunks = 0;
		foreach ( array_chunk( $sql_parts, self::DESTINATION_CHUNK_SIZE ) as $chunk ) {
			$chunk_query = $query_prefix . implode( ',', $chunk ) . $query_suffix;
			$result = $wpdb->query( $chunk_query );

			if ( $result === false ) {
				// a failed rollback must never be reported as a clean rollback
				if ( $wpdb->query( 'ROLLBACK' ) === false ) {
					return $this->destination_error_result( 'transaction_rollback_failed', $chunks, 'uncertain', 'destination_chunk_failed' );
				}

				return $this->destination_error_result( 'destination_chunk_failed', $chunks, 'rolled_back', 'destination_chunk_failed' );
			}

			$affected_rows += (int) $result;
			$chunks++;
		}

		if ( $wpdb->query( 'COMMIT' ) === false ) {
			// the unit may or may not have been committed; the rollback attempt
			// below only releases session state so a later START TRANSACTION
			// cannot implicitly commit it, and is not a claim that it was undone
			$wpdb->query( 'ROLLBACK' );

			return $this->destination_error_result( 'transaction_commit_state_uncertain', $chunks, 'uncertain', 'transaction_commit_failed' );
		}

		return $this->destination_success_result( $affected_rows, $chunks, 'committed' );
	}

	/**
	 * Build a destination success result with a known transaction outcome.
	 *
	 * @param int    $affected_rows Destination affected rows.
	 * @param int    $chunks Executed chunk count.
	 * @param string $transaction_state Resolved transaction state.
	 * @return array
	 */
	private function destination_success_result( $affected_rows, $chunks, $transaction_state ) {
		$this->import_diagnostics['destination_affected_rows'] = (int) $affected_rows;
		$this->import_diagnostics['destination_affected_rows_known'] = true;
		$this->import_diagnostics['transaction_state'] = $transaction_state;

		return [
			'status' => 'success',
			'affected_rows' => (int) $affected_rows,
			'affected_rows_known' => true,
			'chunks' => (int) $chunks,
			'transaction_state' => $transaction_state,
			'error_code' => ''
		];
	}

	/**
	 * Build a stable destination failure result.
	 *
	 * Affected rows are never presented as authoritative once the transaction
	 * outcome is uncertain, and the original cause is preserved without any
	 * database text.
	 *
	 * @param string $error_code Safe internal error code.
	 * @param int    $chunks Successful chunk count.
	 * @param string $transaction_state not_started|rolled_back|uncertain|not_applicable.
	 * @param string $failure_cause Safe original cause code.
	 * @return array
	 */
	private function destination_error_result( $error_code, $chunks, $transaction_state, $failure_cause ) {
		$known = $transaction_state !== 'uncertain';

		$this->import_diagnostics['failure_code'] = $error_code;
		$this->import_diagnostics['failure_cause'] = $failure_cause;
		$this->import_diagnostics['phase'] = 'destination';
		$this->import_diagnostics['transaction_state'] = $transaction_state;
		$this->import_diagnostics['destination_affected_rows'] = 0;
		$this->import_diagnostics['destination_affected_rows_known'] = $known;

		return [
			'status' => 'error',
			'affected_rows' => 0,
			'affected_rows_known' => $known,
			'chunks' => (int) $chunks,
			'transaction_state' => $transaction_state,
			'error_code' => $error_code
		];
	}

	/**
	 * Detect the actual destination engine conservatively.
	 *
	 * @param string $table Destination table.
	 * @return string Lowercase engine name or an empty string.
	 */
	private function get_destination_engine( $table ) {
		global $wpdb;

		$wpdb->last_error = '';
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		if ( $wpdb->last_error !== '' || ! is_array( $status ) || empty( $status['Engine'] ) ) {
			return '';
		}

		return strtolower( (string) $status['Engine'] );
	}

	/**
	 * Execute provider-owned source batches with cursor safety checks.
	 *
	 * @param mixed    $cursor Initial opaque cursor.
	 * @param callable $fetch Provider batch fetcher.
	 * @param callable $advanced Provider cursor comparison.
	 * @param callable $consume Batch consumer.
	 * @return array
	 */
	private function run_source_batches( $cursor, $fetch, $advanced, $consume ) {
		$source_rows = 0;
		$batches = 0;

		while ( $batches < self::MAX_SOURCE_BATCHES ) {
			$batch = call_user_func( $fetch, $cursor, self::SOURCE_BATCH_SIZE );
			$batches++;

			if ( ! is_array( $batch ) || ! isset( $batch['status'] ) || $batch['status'] !== 'success' ) {
				return [
					'status' => 'error',
					'rows' => $source_rows,
					'batches' => $batches,
					'error_code' => isset( $batch['error_code'] ) ? $batch['error_code'] : 'source_batch_failed'
				];
			}

			$rows = isset( $batch['rows'] ) && is_array( $batch['rows'] ) ? $batch['rows'] : [];
			$source_rows += count( $rows );
			call_user_func( $consume, $rows );

			if ( ! empty( $batch['done'] ) ) {
				return [
					'status' => 'success',
					'rows' => $source_rows,
					'batches' => $batches,
					'error_code' => ''
				];
			}

			$next_cursor = isset( $batch['next_cursor'] ) ? $batch['next_cursor'] : null;
			if ( ! call_user_func( $advanced, $cursor, $next_cursor ) ) {
				return [
					'status' => 'error',
					'rows' => $source_rows,
					'batches' => $batches,
					'error_code' => 'source_cursor_not_advanced'
				];
			}

			$cursor = $next_cursor;
			unset( $rows, $batch );
		}

		return [
			'status' => 'error',
			'rows' => $source_rows,
			'batches' => $batches,
			'error_code' => 'source_batch_limit_exceeded'
		];
	}

	/**
	 * Initialize safe diagnostics for one operation.
	 *
	 * @param string $provider Provider slug.
	 * @param string $phase Initial phase.
	 * @return void
	 */
	private function start_import_diagnostics( $provider, $phase ) {
		$this->import_diagnostics = [
			'provider' => sanitize_key( $provider ),
			'phase' => sanitize_key( $phase ),
			'snapshot_boundary' => null,
			'raw_rows' => 0,
			'logical_rows' => 0,
			'distinct_targets' => 0,
			'distinct_posts' => 0,
			'skipped_count' => 0,
			'handled_non_post_rows' => 0,
			'source_date_min' => '',
			'source_date_max' => '',
			'destination_rows' => [],
			'source_batches' => 0,
			'destination_chunks' => 0,
			'source_elapsed_ms' => 0,
			'mapping_elapsed_ms' => 0,
			'destination_elapsed_ms' => 0,
			'destination_tuple_count' => 0,
			'destination_query_bytes' => 0,
			'destination_affected_rows' => 0,
			'destination_affected_rows_known' => false,
			'transaction_state' => 'not_applicable',
			'peak_memory' => memory_get_peak_usage( true ),
			'failure_code' => '',
			'failure_cause' => ''
		];
	}

	/**
	 * Emit one safe debug record for an import.
	 *
	 * @return void
	 */
	private function log_import_diagnostics() {
		$this->import_diagnostics['peak_memory'] = memory_get_peak_usage( true );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'PVC import diagnostics: ' . wp_json_encode( $this->import_diagnostics ) );
		}
	}

	/**
	 * Build a safe built-in provider failure result.
	 *
	 * The stable code is surfaced as a support reference. It never contains SQL,
	 * table prefixes, URLs or database error text.
	 *
	 * @param string $provider Provider slug.
	 * @param string $error_code Safe internal code.
	 * @return array
	 */
	private function import_failure_result( $provider, $error_code ) {
		$error_code = sanitize_key( $error_code );

		if ( empty( $this->import_diagnostics['provider'] ) ) {
			$this->start_import_diagnostics( $provider, 'source' );
		}

		$this->import_diagnostics['failure_code'] = $error_code;

		if ( empty( $this->import_diagnostics['failure_cause'] ) ) {
			$this->import_diagnostics['failure_cause'] = $error_code;
		}

		$message = __( 'The import stopped after a database error. Verify destination totals and restore a database backup before retrying or correcting the import.', 'post-views-counter' );

		if ( isset( $this->import_diagnostics['transaction_state'] ) && $this->import_diagnostics['transaction_state'] === 'uncertain' ) {
			$message .= ' ' . __( 'The database transaction outcome could not be confirmed, so part of this import may have been stored.', 'post-views-counter' );
		}

		$message .= ' ' . sprintf( __( 'Support reference: %s.', 'post-views-counter' ), $error_code );

		$this->log_import_diagnostics();

		return [
			'success' => false,
			'message' => $message,
			'type' => 'error',
			'error_code' => $error_code
		];
	}

	/**
	 * Snapshot the destination totals an advanced strategy will compare against.
	 *
	 * Must be called before the destination write so eligibility is never
	 * derived from already-modified state.
	 *
	 * @param array  $totals_map Array of post_id => source total.
	 * @param string $strategy Selected strategy.
	 * @return array|null Pre-write totals, or null when the strategy needs none.
	 */
	private function snapshot_existing_total_counts( $totals_map, $strategy ) {
		if ( empty( $totals_map ) || ! $this->is_advanced_strategy( $strategy ) ) {
			return null;
		}

		return $this->get_existing_total_counts( array_keys( $totals_map ) );
	}

	/**
	 * Check whether a strategy compares against stored destination counts.
	 *
	 * @param string $strategy Selected strategy.
	 * @return bool
	 */
	private function is_advanced_strategy( $strategy ) {
		return in_array( sanitize_key( $strategy ), [ 'skip_existing', 'keep_higher_count', 'fill_empty_only' ], true );
	}

	/**
	 * Adjust import statistics to include skipped totals information.
	 *
	 * @param array      $stats Message statistics.
	 * @param array      $totals_map Array of post_id => accepted source total.
	 * @param string     $strategy Selected strategy.
	 * @param array|null $existing Pre-write destination totals.
	 * @param array|null $compare_map Optional per-post comparison values.
	 * @return void
	 */
	private function apply_skip_statistics( &$stats, $totals_map, $strategy, $existing, $compare_map = null ) {
		if ( empty( $stats ) || empty( $totals_map ) || ! is_array( $existing ) ) {
			return;
		}

		$skip_stats = $this->calculate_total_skip_stats( $totals_map, $strategy, $existing, $compare_map );

		if ( empty( $skip_stats ) ) {
			return;
		}

		$stats['skipped'] = $skip_stats;

		if ( isset( $stats['total_views'] ) ) {
			$stats['total_views'] = max( 0, (int) $stats['total_views'] - $skip_stats['views'] );
		}

		if ( isset( $stats['posts_processed'] ) ) {
			$stats['posts_processed'] = max( 0, (int) $stats['posts_processed'] - $skip_stats['posts'] );
		}
	}

	/**
	 * Calculate how many source totals the given strategy leaves unapplied.
	 *
	 * Statistics describe accepted versus skipped source totals measured against
	 * the pre-write destination snapshot. They are not exact destination deltas:
	 * ordered duplicate source tuples are summed for view accounting while the
	 * comparison value follows the strategy's documented clause.
	 *
	 * @param array      $totals_map Array of post_id => accepted source total.
	 * @param string     $strategy Selected strategy.
	 * @param array      $existing Pre-write destination totals.
	 * @param array|null $compare_map Optional per-post comparison values.
	 * @return array
	 */
	private function calculate_total_skip_stats( $totals_map, $strategy, $existing, $compare_map = null ) {
		$strategy = sanitize_key( $strategy );

		if ( empty( $totals_map ) || ! $this->is_advanced_strategy( $strategy ) ) {
			return [];
		}

		$skipped_views = 0;
		$skipped_posts = 0;

		foreach ( $totals_map as $post_id => $value ) {
			$current = isset( $existing[ $post_id ] ) ? (int) $existing[ $post_id ] : null;

			// keep_higher_count resolves per tuple, so the highest source tuple
			// is the value the destination can reach for this post
			$candidate = is_array( $compare_map ) && isset( $compare_map[ $post_id ] ) ? (int) $compare_map[ $post_id ] : (int) $value;
			$skip = false;

			if ( $strategy === 'skip_existing' && $current !== null ) {
				$skip = true;
			} elseif ( $strategy === 'keep_higher_count' && $current !== null && $current >= $candidate ) {
				$skip = true;
			} elseif ( $strategy === 'fill_empty_only' && $current !== null && $current > 0 ) {
				$skip = true;
			}

			if ( $skip ) {
				$skipped_views += (int) $value;
				$skipped_posts++;
			}
		}

		if ( $skipped_posts === 0 ) {
			return [];
		}

		return [
			'views' => $skipped_views,
			'posts' => $skipped_posts
		];
	}

	/**
	 * Get existing total counts for selected posts.
	 *
	 * @param array $post_ids
	 * @return array
	 */
	private function get_existing_total_counts( $post_ids ) {
		global $wpdb;

		$post_ids = array_filter( array_map( 'intval', (array) $post_ids ) );
		$post_ids = array_values( array_unique( $post_ids ) );

		if ( empty( $post_ids ) ) {
			return [];
		}

		$existing = [];

		foreach ( array_chunk( $post_ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$sql = $wpdb->prepare(
				"SELECT id, count FROM {$wpdb->prefix}post_views WHERE type = 4 AND period = 'total' AND id IN ({$placeholders})",
				$chunk
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A );

			foreach ( (array) $rows as $row ) {
				$existing[ (int) $row['id'] ] = (int) $row['count'];
			}
		}

		return $existing;
	}

	/**
	 * Generate import/analyze message with statistics.
	 *
	 * @param array $stats Import statistics
	 * @param string $mode Mode: 'import' or 'analyze'
	 * @return string
	 */
	private function generate_import_message( $stats, $mode = 'import' ) {
		$message_parts = [];

		$source_label = '';

		if ( ! empty( $stats['source'] ) ) {
			$source_label = $stats['source'];
		}

		if ( $source_label !== '' ) {
			if ( $mode === 'analyze' ) {
				$message_parts[] = sprintf( __( 'Analysis of %s:', 'post-views-counter' ), $source_label );
			} else {
				$message_parts[] = sprintf( __( 'Import from %s:', 'post-views-counter' ), $source_label );
			}
		}

		// main success message
		if ( ! empty( $stats['no_post_rows_handled'] ) ) {
			if ( $mode === 'analyze' ) {
				$message_parts[] = __( 'No post view data was found. The valid source rows map to other content types.', 'post-views-counter' );
			} else {
				$message_parts[] = __( 'No post view data was imported. The valid source rows map to other content types.', 'post-views-counter' );
			}
		} elseif ( ! empty( $stats['period_data_only'] ) && isset( $stats['posts_processed'] ) ) {
			if ( $mode === 'analyze' ) {
				$message_parts[] = sprintf(
					__( 'Found period view data for %s posts, but no valid source total rows.', 'post-views-counter' ),
					number_format_i18n( $stats['posts_processed'] )
				);
			} else {
				$message_parts[] = sprintf(
					__( 'Imported period view data for %s posts; no valid source total rows were available.', 'post-views-counter' ),
					number_format_i18n( $stats['posts_processed'] )
				);
			}
		} elseif ( isset( $stats['total_views'] ) && isset( $stats['posts_processed'] ) ) {
			if ( $mode === 'analyze' ) {
				$message_parts[] = sprintf(
					__( 'Found %s total views for %s posts.', 'post-views-counter' ),
					number_format_i18n( $stats['total_views'] ),
					number_format_i18n( $stats['posts_processed'] )
				);
			} else {
				$message_parts[] = sprintf(
					__( 'Successfully imported %s total views for %s posts.', 'post-views-counter' ),
					number_format_i18n( $stats['total_views'] ),
					number_format_i18n( $stats['posts_processed'] )
				);
			}
		}

		// posts that contributed only period data
		if ( ! empty( $stats['period_only_posts'] ) ) {
			if ( $mode === 'analyze' ) {
				$message_parts[] = sprintf(
					__( 'Also found period view data for %s additional posts without source totals.', 'post-views-counter' ),
					number_format_i18n( $stats['period_only_posts'] )
				);
			} else {
				$message_parts[] = sprintf(
					__( 'Also imported period view data for %s additional posts without source totals.', 'post-views-counter' ),
					number_format_i18n( $stats['period_only_posts'] )
				);
			}
		}

		// additional info (like skipped URLs)
		if ( ! empty( $stats['additional_info'] ) ) {
			$message_parts[] = $stats['additional_info'];
		}

		if ( isset( $stats['skipped']['views'], $stats['skipped']['posts'] ) ) {
			$message_parts[] = sprintf(
				__( 'Skipped %1$s total views for %2$s posts.', 'post-views-counter' ),
				number_format_i18n( $stats['skipped']['views'] ),
				number_format_i18n( $stats['skipped']['posts'] )
			);
		}

		return implode( ' ', $message_parts );
	}

	/**
	 * Map provider target URL to content data.
	 *
	 * @param string $target
	 * @param array $tracked_post_types
	 * @param string $provider
	 * @return array
	 */
	private function map_target_to_content( $target, $tracked_post_types, $provider ) {
		if ( $provider === 'statify' && array_key_exists( $target, $this->statify_post_cache ) ) {
			$post_id = $this->statify_post_cache[ $target ];
		} else {
			$post_id = $this->map_target_to_post_id( $target, $tracked_post_types );
			if ( $provider === 'statify' ) {
				$this->statify_post_cache[ $target ] = $post_id;
			}
		}

		$content = [
			'content_type' => 'post',
			'content_id' => $post_id
		];

		/**
		 * Filter provider content mapping so it's possible to process non-post URLs.
		 *
		 * @since 1.5.10
		 *
		 * @param array $content {
		 *     @type string $content_type Resolved content type.
		 *     @type int    $content_id   Target identifier.
		 * }
		 * @param string $target Target path recorded by the provider.
		 * @param string $provider Current import provider slug.
		 * @param array  $tracked_post_types Allowed post types for PVC.
		 * @param Post_Views_Counter_Import $importer Import handler instance.
		 */
		return apply_filters( 'pvc_import_map_target_to_content', $content, $target, $provider, $tracked_post_types, $this );
	}

	/**
	 * Map Statify target URL to post ID.
	 *
	 * @param string $target
	 * @param array $tracked_post_types
	 * @return int
	 */
	private function map_target_to_post_id( $target, $tracked_post_types ) {
		// handle empty tracked post types
		if ( empty( $tracked_post_types ) ) {
			return 0;
		}

		// handle homepage
		if ( $target === '/' ) {
			$post_id = get_option( 'page_on_front' );
			if ( $post_id && in_array( get_post_type( $post_id ), $tracked_post_types, true ) ) {
				return (int) $post_id;
			}
		}

		// build full URL from relative target path
		$url = home_url( $target );
		$post_id = url_to_postid( $url );

		// verify post exists and is a tracked type
		if ( $post_id ) {
			$post_type = get_post_type( $post_id );
			if ( $post_type && in_array( $post_type, $tracked_post_types, true ) ) {
				return (int) $post_id;
			}
		}

		return 0;
	}

	/**
	 * Flush caches specific to Post Views Counter.
	 *
	 * @return void
	 */
	private function flush_pvc_caches() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) || ! property_exists( $wp_object_cache, 'cache' ) ) {
			return;
		}

		$groups = [ 'pvc', 'pvc-get_post_views', 'pvc-get_views' ];

		foreach ( $groups as $group ) {
			if ( empty( $wp_object_cache->cache[$group] ) || ! is_array( $wp_object_cache->cache[$group] ) ) {
				continue;
			}

			foreach ( array_keys( $wp_object_cache->cache[$group] ) as $key ) {
				wp_cache_delete( $key, $group );
			}
		}
	}

	/**
	 * Retrieve the human readable label for a provider.
	 *
	 * @param string $slug
	 * @return string
	 */
	private function get_provider_label( $slug ) {
		if ( isset( $this->import_provider_labels[ $slug ] ) ) {
			return $this->import_provider_labels[ $slug ];
		}

		return ucwords( str_replace( '_', ' ', $slug ) );
	}

	/**
	 * Build period keys for a timestamp.
	 *
	 * @param int $timestamp
	 * @param bool $use_gmt
	 * @return array
	 */
	private function get_period_keys_from_timestamp( $timestamp, $use_gmt ) {
		$date = $use_gmt ? gmdate( 'W-d-m-Y-o', $timestamp ) : date( 'W-d-m-Y-o', $timestamp );
		$parts = explode( '-', $date );

		return [
			'day' => $parts[3] . $parts[2] . $parts[1],
			'week' => $parts[4] . $parts[0],
			'month' => $parts[3] . $parts[2],
			'year' => $parts[3]
		];
	}
}
