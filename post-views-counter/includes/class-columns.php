<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Post_Views_Counter_Columns class.
 *
 * @class Post_Views_Counter_Columns
 */
class Post_Views_Counter_Columns {

	private $bulk_counter_result = null;

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'admin_init', [ $this, 'register_new_column' ] );
		add_action( 'post_submitbox_misc_actions', [ $this, 'submitbox_views' ] );
		add_action( 'attachment_submitbox_misc_actions', [ $this, 'submitbox_views' ] );
		add_action( 'save_post', [ $this, 'save_post' ], 10, 2 );
		add_action( 'edit_attachment', [ $this, 'save_post' ], 10 );
		add_action( 'bulk_edit_custom_box', [ $this, 'quick_edit_custom_box' ], 10, 2 );
		add_action( 'quick_edit_custom_box', [ $this, 'quick_edit_custom_box' ], 10, 2 );
		// Retained for compatibility with any external consumer. The shipped UI
		// no longer calls it; native Bulk Edit owns current counter writes.
		add_action( 'wp_ajax_save_bulk_post_views', [ $this, 'save_bulk_post_views' ] );
		add_action( 'bulk_edit_posts', [ $this, 'bulk_edit_post_counters' ], 10, 2 );
		add_action( 'admin_notices', [ $this, 'bulk_counter_admin_notice' ] );
		add_filter( 'removable_query_args', [ $this, 'add_removable_query_args' ] );
		add_action( 'pre_get_posts', [ $this, 'prepare_admin_list_sort' ], 0 );
	}

	/**
	 * Sanitize one absolute counter total.
	 *
	 * @param mixed $value
	 * @return int|null
	 */
	private function sanitize_counter_total_input( $value ) {
		if ( ! is_scalar( $value ) )
			return null;

		$value = trim( wp_unslash( (string) $value ) );

		if ( $value === '' || ! preg_match( '/^-?\d+$/', $value ) )
			return null;

		$count = (int) $value;

		return $count < 0 ? 0 : $count;
	}

	/**
	 * Collect the nonblank Views total submitted by an editor.
	 *
	 * @param array|null $source Submitted fields. Defaults to the POST body.
	 * @return array|null Null indicates malformed input.
	 */
	private function get_submitted_counter_totals( $source = null ) {
		if ( is_null( $source ) )
			$source = $_POST;

		if ( ! is_array( $source ) )
			return null;

		if ( ! array_key_exists( 'post_views', $source ) )
			return [];

		if ( ! is_scalar( $source['post_views'] ) )
			return null;

		if ( trim( wp_unslash( (string) $source['post_views'] ) ) === '' )
			return [];

		$value = $this->sanitize_counter_total_input( $source['post_views'] );

		if ( is_null( $value ) )
			return null;

		// A value equal to the one the form was loaded with was not edited.
		// Writing it back would roll back Views counted since then.
		if ( array_key_exists( 'current_post_views', $source ) && $this->sanitize_counter_total_input( $source['current_post_views'] ) === $value )
			return [];

		return [ 'views' => $value ];
	}

	/**
	 * Check whether a post's counter totals appear on its edit screen.
	 *
	 * The edit screen is a separate feature from the list column, so the
	 * column toggle does not apply here.
	 *
	 * @internal Shared by the Classic Editor and the block editor.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function is_post_counter_visible_in_editor( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! $post )
			return false;

		return in_array( $post->post_type, (array) Post_Views_Counter()->options['general']['post_types_count'], true )
			&& apply_filters( 'pvc_admin_display_post_views', true, $post->ID ) !== false
			// The block editor has always honoured this filter name.
			&& apply_filters( 'pvc_admin_display_views', true, $post->ID ) !== false;
	}

	/**
	 * Check whether the current user may edit a post's counter totals on its
	 * edit screen.
	 *
	 * @internal Shared by the Classic Editor and the block editor.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function can_edit_post_counter_in_editor( $post_id ) {
		$pvc = Post_Views_Counter();

		return $this->is_post_counter_visible_in_editor( $post_id )
			&& current_user_can( 'edit_post', (int) $post_id )
			&& ! empty( $pvc->options['display']['restrict_edit_views'] )
			&& current_user_can( apply_filters( 'pvc_restrict_edit_capability', 'manage_options' ) );
	}

	/**
	 * Get the lifetime Views total shown on a post's edit screen.
	 *
	 * @internal Shared by the Classic Editor and the block editor.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_post_editor_counter_state( $post_id ) {
		$post_id = (int) $post_id;

		return [
			'views'		=> (int) pvc_get_post_views( $post_id ),
			'editable'	=> $this->can_edit_post_counter_in_editor( $post_id )
		];
	}

	/**
	 * Apply counter totals submitted by the block editor.
	 *
	 * @internal Used only by the block editor REST route.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $source Submitted fields keyed post_views.
	 * @return array|WP_Error Committed totals, the reason nothing was written, or
	 *                        pvc-counter-read-failed when the committed total
	 *                        cannot be read back.
	 */
	public function apply_editor_counter_totals( $post_id, $source ) {
		$post_id = (int) $post_id;

		if ( ! $this->is_post_counter_visible_in_editor( $post_id ) )
			return new WP_Error( 'pvc-invalid-post', __( 'Invalid post ID.', 'post-views-counter' ), [ 'status' => 404 ] );

		if ( ! $this->can_edit_post_counter_in_editor( $post_id ) )
			return new WP_Error( 'pvc-user-not-allowed', __( 'You are not allowed to edit this item.', 'post-views-counter' ), [ 'status' => rest_authorization_required_code() ] );

		$updates = $this->get_submitted_counter_totals( $source );

		if ( is_null( $updates ) )
			return new WP_Error( 'pvc-invalid-counter-totals', __( 'Invalid counter totals.', 'post-views-counter' ), [ 'status' => 400 ] );

		if ( ! $this->update_post_counter_totals( $post_id, $updates ) )
			return new WP_Error( 'pvc-counter-write-failed', __( 'The counter totals could not be saved.', 'post-views-counter' ), [ 'status' => 500 ] );

		$state = $this->get_post_editor_counter_state( $post_id );

		// pvc_get_post_views() may still hold the total it cached before the write
		$views = $this->get_stored_post_views_total( $post_id );

		if ( $views === null )
			return new WP_Error( 'pvc-counter-read-failed', __( 'The saved counter totals could not be read back.', 'post-views-counter' ), [ 'status' => 500 ] );

		$state['views'] = $views;

		return $state;
	}

	/**
	 * Read one post's committed lifetime Views total, bypassing read caches.
	 *
	 * @param int $post_id Post ID.
	 * @return int|null The total (0 when no row exists), or null when the read failed.
	 */
	private function get_stored_post_views_total( $post_id ) {
		global $wpdb;

		$where = ' WHERE `id` = %d AND `type` = %d AND `period` = %s';
		$args = [ (int) $post_id, 4, 'total' ];

		if ( pvc_post_views_has_content_column() ) {
			$where .= ' AND `content` = %d';
			$args[] = 0;
		}

		$wpdb->last_error = '';
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT `count` FROM `' . $wpdb->prefix . 'post_views`' . $where, $args ) );

		// a failed read must never pass for a stored zero
		return $wpdb->last_error === '' ? (int) $count : null;
	}

	/**
	 * Check whether the current request is one exact WordPress AJAX action.
	 *
	 * @param string $action AJAX action.
	 * @return bool
	 */
	private function is_ajax_action( $action ) {
		$is_ajax = function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : defined( 'DOING_AJAX' ) && DOING_AJAX;

		return $is_ajax
			&& isset( $_REQUEST['action'] )
			&& is_scalar( $_REQUEST['action'] )
			&& sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) === $action;
	}

	/**
	 * Check whether WordPress is saving one Quick Edit row.
	 *
	 * @return bool
	 */
	private function is_quick_edit_request() {
		return $this->is_ajax_action( 'inline-save' ) && isset( $_POST['_inline_edit'] );
	}

	/**
	 * Check whether the request submitted this post's edit form. The Classic
	 * Editor, the attachment editor and Quick Edit all send the edited post as
	 * post_ID.
	 *
	 * @param int $post_id Post ID being saved.
	 * @return bool
	 */
	private function is_submitted_post( $post_id ) {
		if ( ! isset( $_POST['post_ID'] ) || ! is_scalar( $_POST['post_ID'] ) )
			return false;

		$submitted = trim( wp_unslash( (string) $_POST['post_ID'] ) );

		return preg_match( '/^[1-9]\d*$/', $submitted ) === 1 && (int) $submitted === (int) $post_id;
	}

	/**
	 * Check whether the ordinary save_post callback is running inside native
	 * WordPress Bulk Edit. The bulk_edit_posts completion hook owns current
	 * native Bulk Edit counter writes there.
	 *
	 * @return bool
	 */
	private function is_native_bulk_edit_request() {
		return isset( $_REQUEST['bulk_edit'] );
	}

	/**
	 * Check all server-side conditions for one post total adjustment.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function can_update_post_counter_totals( $post_id ) {
		$pvc = Post_Views_Counter();
		$post = get_post( $post_id );

		return $post
			&& ! empty( $pvc->options['display']['post_views_column'] )
			&& in_array( $post->post_type, (array) $pvc->options['general']['post_types_count'], true )
			&& apply_filters( 'pvc_admin_display_post_views', true, $post_id ) !== false
			&& current_user_can( 'edit_post', $post_id )
			&& ! empty( $pvc->options['display']['restrict_edit_views'] )
			&& current_user_can( apply_filters( 'pvc_restrict_edit_capability', 'manage_options' ) );
	}

	/**
	 * Retire the cached lifetime Views total of one post after a manual write.
	 *
	 * pvc_get_post_views() and shared cache priming use the query's MD5 key, so
	 * only the single-post total query needs to be rebuilt. The content condition
	 * is included when the shared table has a content dimension.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function retire_post_views_cache( $post_id ) {
		global $wpdb;

		$query = "SELECT SUM(count) AS views FROM " . $wpdb->prefix . "post_views WHERE id IN (%d) AND type = %d AND period = %s";

		wp_cache_delete( md5( $wpdb->prepare( $query, (int) $post_id, 4, 'total' ) ), 'pvc-get_post_views' );
		wp_cache_delete( md5( $wpdb->prepare( $query . ' AND content = %d', (int) $post_id, 4, 'total', 0 ) ), 'pvc-get_post_views' );
	}

	/**
	 * Write one post's lifetime Views total without altering historical buckets.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $updates Absolute totals; only views is written.
	 * @return bool
	 */
	private function update_post_counter_totals( $post_id, $updates ) {
		if ( ! isset( $updates['views'] ) )
			return true;

		// Compare with the committed total, never a cached read: a stale cached
		// total equal to the submitted value would skip a real edit.
		$current = $this->get_stored_post_views_total( $post_id );

		if ( $current === null )
			return false;

		// The submitted Views value is already a validated, normalized
		// non-negative integer. Pass it directly so pvc_update_post_views()
		// remains the single owner of the pvc_update_post_views_count filter
		// on this path.
		$views = (int) $updates['views'];

		if ( $current === $views )
			return true;

		global $wpdb;
		$wpdb->last_error = '';

		if ( pvc_update_post_views( $post_id, $views ) === false || $wpdb->last_error !== '' )
			return false;

		$this->retire_post_views_cache( $post_id );
		do_action( 'pvc_after_update_post_views_count', $post_id );

		return true;
	}

	/**
	 * Output post views for single post.
	 *
	 * @global object $post
	 *
	 * @return void
	 */
	public function submitbox_views() {
		global $post;

		if ( ! $post || ! $this->is_post_counter_visible_in_editor( $post->ID ) )
			return;

		$state = $this->get_post_editor_counter_state( $post->ID ); ?>

		<div class="misc-pub-section" id="post-views">

			<span id="post-views-display" class="pvc-editor-counter-line">
				<?php echo esc_html__( 'Views', 'post-views-counter' ) . ': <b>' . esc_html( number_format_i18n( $state['views'] ) ) . '</b>'; ?>
			</span>

			<?php
			if ( $state['editable'] ) {
				wp_nonce_field( 'post_views_count', 'pvc_nonce' );
				?>
				<a href="#post-views" class="edit-post-views hide-if-no-js" role="button"><?php esc_html_e( 'Edit', 'post-views-counter' ); ?><span class="screen-reader-text"> <?php esc_html_e( 'Views', 'post-views-counter' ); ?></span></a>

				<div id="post-views-input-container" class="hide-if-js">

					<p class="pvc-editor-counter-field">
						<label for="post-views-input"><?php esc_html_e( 'Views', 'post-views-counter' ); ?></label>
						<input type="hidden" name="current_post_views" id="post-views-current" value="<?php echo esc_attr( $state['views'] ); ?>" />
						<input type="number" min="0" step="1" inputmode="numeric" name="post_views" id="post-views-input" value="<?php echo esc_attr( $state['views'] ); ?>" />
					</p>

					<p class="description"><?php esc_html_e( 'Lifetime totals. Changing them does not alter daily, weekly, monthly, or yearly history.', 'post-views-counter' ); ?></p>

					<p>
						<a href="#post-views" class="save-post-views hide-if-no-js button"><?php esc_html_e( 'OK', 'post-views-counter' ); ?></a>
						<a href="#post-views" class="cancel-post-views hide-if-no-js button-cancel"><?php esc_html_e( 'Cancel', 'post-views-counter' ); ?></a>
					</p>

				</div>
				<?php
			}
			?>

		</div>
		<?php
	}

	/**
	 * Save post views data.
	 *
	 * @param int $post_id
	 * @param object $post
	 * @return void
	 */
	public function save_post( $post_id, $post = null ) {
		// break if doing autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return;

		// The bulk_edit_posts completion hook is the sole counter writer for
		// native Bulk Edit, so each updated post gets exactly one write attempt.
		if ( $this->is_native_bulk_edit_request() )
			return;

		// break if current user can't edit this post
		if ( ! current_user_can( 'edit_post', $post_id ) )
			return;

		// is the views total set
		if ( ! isset( $_POST['post_views'] ) )
			return;

		// get main instance
		$pvc = Post_Views_Counter();

		// break if post views in not one of the selected
		$post_types = (array) $pvc->options['general']['post_types_count'];

		// get post type
		if ( is_null( $post ) )
			$post_type = get_post_type( $post_id );
		else
			$post_type = $post->post_type;

		// invalid post type?
		if ( ! in_array( $post_type, $post_types, true ) )
			return;
		
		// allow editing
		$allow_edit = (bool) $pvc->options['display']['restrict_edit_views'];

		// allow editing condition
		$allow_edit_condition = (bool) current_user_can( apply_filters( 'pvc_restrict_edit_capability', 'manage_options' ) ); 

		// break if views editing not allowed or editing condition not met
		if ( $allow_edit === false || $allow_edit_condition === false )
			return;

		// validate data
		if ( ! isset( $_POST['pvc_nonce'] ) || ! is_scalar( $_POST['pvc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['pvc_nonce'] ) ), 'post_views_count' ) )
			return;

		// The submitted total belongs to the post the form was rendered for.
		// Another post saved during the same request never receives it.
		if ( ! $this->is_submitted_post( $post_id ) )
			return;

		$updates = $this->get_submitted_counter_totals();

		if ( is_null( $updates ) || empty( $updates ) )
			return;

		// Quick Edit belongs to the list column; the edit screen does not.
		if ( $this->is_quick_edit_request() ? ! $this->can_update_post_counter_totals( $post_id ) : ! $this->can_edit_post_counter_in_editor( $post_id ) )
			return;

		$this->update_post_counter_totals( $post_id, $updates );
	}

	/**
	 * Register post views column for specific post types.
	 *
	 * @return void
	 */
	public function register_new_column() {
		// get main instance
		$pvc = Post_Views_Counter();

		// is posts views column active?
		if ( ! $pvc->options['display']['post_views_column'] )
			return false;

		// get post types
		$post_types = $pvc->options['general']['post_types_count'];

		// any post types?
		if ( ! empty( $post_types ) ) {
			foreach ( $post_types as $post_type ) {
				if ( $post_type === 'attachment' ) {
					// actions
					add_action( 'manage_media_custom_column', [ $this, 'add_new_column_content' ], 10, 2 );

					// filters
					add_filter( 'manage_media_columns', [ $this, 'add_new_column' ] );
					add_filter( 'manage_upload_sortable_columns', [ $this, 'register_sortable_custom_column' ] );
				} else {
					// actions
					add_action( 'manage_' . $post_type . '_posts_custom_column', [ $this, 'add_new_column_content' ], 10, 2 );

					// filters
					add_filter( 'manage_' . $post_type . '_posts_columns', [ $this, 'add_new_column' ] );
					add_filter( 'manage_edit-' . $post_type . '_columns', [ $this, 'add_new_column' ], 20 );
					add_filter( 'manage_edit-' . $post_type . '_sortable_columns', [ $this, 'register_sortable_custom_column' ] );

					// bbPress?
					if ( class_exists( 'bbPress' ) ) {
						if ( $post_type === 'forum' )
							add_filter( 'bbp_admin_forums_column_headers', [ $this, 'add_new_column' ] );
						elseif ( $post_type === 'topic' )
							add_filter( 'bbp_admin_topics_column_headers', [ $this, 'add_new_column' ] );
					}
				}
			}
		}
	}

	/**
	 * Register sortable post views column.
	 *
	 * The column sorts natively, descending first.
	 *
	 * @param array $columns
	 * @return array
	 */
	public function register_sortable_custom_column( $columns ) {
		global $post_type;

		// get main instance
		$pvc = Post_Views_Counter();
		$post_types = (array) $pvc->options['general']['post_types_count'];

		// break if display is disabled
		if ( ! $pvc->options['display']['post_views_column'] || ! in_array( $post_type, $post_types, true ) )
			return $columns;

		// check if user can see stats
		if ( apply_filters( 'pvc_admin_display_post_views', true ) === false )
			return $columns;

		// add new sortable column
		$columns['post_views'] = [ 'post_views', true ];

		return $columns;
	}

	/**
	 * Prepare a native Views sort on a managed admin post list: keep posts
	 * without a stored total listed and add the deterministic ID tie-break.
	 *
	 * @param WP_Query $query Query object.
	 * @return void
	 */
	public function prepare_admin_list_sort( $query ) {
		if ( ! $this->is_admin_post_list_query( $query ) )
			return;

		$orderby = $query->get( 'orderby' );

		if ( ! is_string( $orderby ) || sanitize_key( $orderby ) !== 'post_views' )
			return;

		$order = $query->get( 'order' );
		$order = is_scalar( $order ) ? strtoupper( trim( (string) $order ) ) : '';
		$order = $order === 'ASC' ? 'ASC' : 'DESC';
		$metric_query = [ 'period' => 'total', 'hide_empty' => false ];

		$query->set( 'orderby', 'post_views' );
		$query->set( 'order', $order );
		$query->set( 'views_query', $metric_query );
		$query->query['orderby'] = 'post_views';
		$query->query['order'] = $order;
		$query->query['views_query'] = $metric_query;

		// Posts with equal totals keep a stable order across pages.
		$query->pvc_admin_column_sort = 'views';
	}

	/**
	 * Check whether the current query is the enabled Core post-list query.
	 *
	 * @param mixed $query Query object.
	 * @return bool
	 */
	private function is_admin_post_list_query( $query ) {
		global $pagenow;

		if ( ! is_admin() || ! ( $query instanceof WP_Query ) || ! $query->is_main_query() || ! in_array( $pagenow, [ 'edit.php', 'upload.php' ], true ) )
			return false;

		$pvc = Post_Views_Counter();

		if ( empty( $pvc->options['display']['post_views_column'] ) || apply_filters( 'pvc_admin_display_post_views', true ) === false )
			return false;

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = $query->get( 'post_type' );

		if ( ! is_string( $post_type ) || $post_type === '' )
			$post_type = $screen && ! empty( $screen->post_type ) ? $screen->post_type : ( $pagenow === 'upload.php' ? 'attachment' : 'post' );

		return in_array( $post_type, (array) $pvc->options['general']['post_types_count'], true );
	}

	/**
	 * Add post views column.
	 *
	 * @param array $columns
	 * @return array
	 */
	public function add_new_column( $columns ) {
		// date column exists?
		if ( isset( $columns['date'] ) ) {
			// store date column
			$date = $columns['date'];

			// unset date column
			unset( $columns['date'] );
		}

		// comments column exists?
		if ( isset( $columns['comments'] ) ) {
			// store comments column
			$comments = $columns['comments'];

			// unset comments column
			unset( $columns['comments'] );
		}

		// add post views column
		$columns['post_views'] = $this->get_admin_column_header_markup();

		// restore date column
		if ( isset( $date ) )
			$columns['date'] = $date;

		// restore comments column
		if ( isset( $comments ) )
			$columns['comments'] = $comments;

		return $columns;
	}

	/**
	 * Render the compact statistics-icon Views header. WordPress wraps it in
	 * the native sort link.
	 *
	 * @return string
	 */
	private function get_admin_column_header_markup() {
		$label = __( 'Views', 'post-views-counter' );

		return '<span class="pvc-views-header" data-pvc-tooltip="' . esc_attr( $label ) . '">' . Post_Views_Counter()->functions->get_counter_icon_svg() . '<span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
	}

	/**
	 * Add post views column content.
	 *
	 * @param string $column_name
	 * @param int $id
	 * @return void
	 */
	public function add_new_column_content( $column_name, $id ) {
		if ( $column_name === 'post_views' ) {
			// check if user can see stats
			if ( apply_filters( 'pvc_admin_display_post_views', true, $id ) === false ) {
				echo $this->get_restricted_metric_markup();
				return;
			}

			// get total post views
			$count = (int) pvc_get_post_views( $id );

			// get post title
			$post_title = get_the_title( $id );

			if ( $post_title === '' )
				$post_title = __( '(no title)', 'post-views-counter' );

			// get post type labels
			$post_type_labels = null;
			$post_type_object = get_post_type_object( get_post_type( $id ) );

			if ( $post_type_object ) {
				$post_type_labels = get_post_type_labels( $post_type_object );
			}

			if ( $post_type_labels ) {
				$post_title = $post_type_labels->singular_name . ': ' . $post_title;
			}

			echo $this->get_column_metric_markup( $count, $id, $post_title, $this->current_user_can_view_post_chart( $id ) );
		}
	}

	/**
	 * Check whether the current user may open a post's Views chart: the post
	 * type's list-screen capability plus read access to the post. Authors and
	 * contributors keep the chart for other users' posts they can see in the
	 * list; private posts and drafts they cannot read stay closed.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return bool
	 */
	public function current_user_can_view_post_chart( $post ) {
		$post = get_post( $post );

		if ( ! $post )
			return false;

		$post_type_object = get_post_type_object( $post->post_type );

		return $post_type_object && current_user_can( $post_type_object->cap->edit_posts ) && current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Render the Views value, keeping the raw total for Quick Edit.
	 *
	 * @param int    $value Views total.
	 * @param int    $post_id Post ID.
	 * @param string $post_title Post title.
	 * @param bool   $can_open_chart Whether the current user may open the chart.
	 * @return string
	 */
	private function get_column_metric_markup( $value, $post_id, $post_title, $can_open_chart ) {
		$label = __( 'Views', 'post-views-counter' );
		$value = (int) $value;
		$formatted = number_format_i18n( $value );

		// Quick Edit reads the raw total, never the locale-formatted text.
		$data_views = ' data-post-views="' . esc_attr( $value ) . '"';

		if ( ! $can_open_chart )
			return '<span class="pvc-column-metric-value"' . $data_views . '><span aria-hidden="true">' . esc_html( $formatted ) . '</span><span class="screen-reader-text"> ' . esc_html( sprintf( __( '%1$s: %2$s.', 'post-views-counter' ), $label, $formatted ) ) . '</span></span>';

		// A link whose visible text is only a number still needs an accessible
		// name. The tooltip is a data attribute, so it never replaces that name
		// or wraps the link in a tooltip role.
		$tooltip = sprintf( __( 'Total Views: %s', 'post-views-counter' ), $formatted );
		$accessible_label = sprintf( __( '%1$s: %2$s. Open chart.', 'post-views-counter' ), $label, $formatted );

		return '<a href="#" class="pvc-view-chart" data-post-id="' . esc_attr( $post_id ) . '" data-post-title="' . esc_attr( $post_title ) . '" data-pvc-tooltip="' . esc_attr( $tooltip ) . '"' . $data_views . ' aria-label="' . esc_attr( $accessible_label ) . '">' . esc_html( $formatted ) . '</a>';
	}

	/**
	 * Render a count-free restricted-state value for the admin column.
	 *
	 * @return string
	 */
	private function get_restricted_metric_markup() {
		return '<span class="pvc-column-metric-value"><span aria-hidden="true">&mdash;</span><span class="screen-reader-text"> ' . esc_html__( 'View statistics are restricted.', 'post-views-counter' ) . '</span></span>';
	}

	/**
	 * Handle quick edit.
	 *
	 * @global string $pagenow
	 *
	 * @param string $column_name
	 * @param string $post_type
	 * @return void
	 */
	function quick_edit_custom_box( $column_name, $post_type ) {
		global $pagenow, $post;

		if ( $pagenow !== 'edit.php' )
			return;

		if ( $column_name !== 'post_views' )
			return;

		if ( ! $post )
			return;
		
		// get main instance
		$pvc = Post_Views_Counter();
		$post_types = (array) $pvc->options['general']['post_types_count'];

		// break if display is not allowed
		if ( ! $pvc->options['display']['post_views_column'] || ! in_array( $post_type, $post_types, true ) )
			return;
		
		// check if user can see stats
		if ( apply_filters( 'pvc_admin_display_post_views', true, $post->ID ) === false )
			return;

		// allow editing
		$allow_edit = (bool) $pvc->options['display']['restrict_edit_views'];

		// allow editing condition
		$allow_edit_condition = (bool) current_user_can( apply_filters( 'pvc_restrict_edit_capability', 'manage_options' ) ); 
		?>
		<fieldset class="inline-edit-col-left">
			<div id="inline-edit-post_views" class="inline-edit-col">
				<?php
				// The Quick Edit template is rendered once and reused for every
				// row, so the template-level marker records whether the field may
				// ever be enabled.
				?>
				<label class="inline-edit-group">
					<span class="title"><?php esc_html_e( 'Views', 'post-views-counter' ); ?></span>
					<?php if ( $allow_edit === true && $allow_edit_condition === true ) { ?>
						<span class="input-text-wrap"><input type="number" min="0" step="1" inputmode="numeric" name="post_views" class="title text" value="" data-pvc-editable="1"></span>
						<input type="hidden" name="current_post_views" value="" />
					<?php } else { ?>
						<span class="input-text-wrap"><input type="number" min="0" step="1" inputmode="numeric" name="post_views" class="title text" value="" disabled readonly /></span>
					<?php } ?>
				</label>
				<?php if ( $allow_edit === true && $allow_edit_condition === true ) wp_nonce_field( 'post_views_count', 'pvc_nonce' ); ?>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Apply Bulk Edit counter totals after WordPress has finished its own bulk
	 * update. Only the IDs WordPress actually updated are written, so a locked
	 * or skipped post never receives a counter change.
	 *
	 * @param array $updated Post IDs WordPress updated.
	 * @param array $shared_post_data Submitted bulk fields.
	 * @return void
	 */
	public function bulk_edit_post_counters( $updated, $shared_post_data ) {
		if ( ! is_array( $updated ) || ! is_array( $shared_post_data ) )
			return;

		// Only the submitted bulk fields are considered, never ambient request data.
		if ( ! array_key_exists( 'post_views', $shared_post_data ) )
			return;

		// A request without the plugin's own bulk nonce is not one of ours.
		if ( ! isset( $shared_post_data['pvc_nonce'] ) || ! is_scalar( $shared_post_data['pvc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $shared_post_data['pvc_nonce'] ) ), 'post_views_count' ) )
			return;

		$updates = $this->get_submitted_counter_totals( $shared_post_data );

		// Blank means no change.
		if ( is_array( $updates ) && empty( $updates ) )
			return;

		$post_ids = array_values( array_filter( array_unique( array_map( 'absint', $updated ) ) ) );
		$pvc = Post_Views_Counter();
		$rejected = is_null( $updates )
			|| empty( $pvc->options['display']['restrict_edit_views'] )
			|| ! current_user_can( apply_filters( 'pvc_restrict_edit_capability', 'manage_options' ) );

		if ( $rejected ) {
			$this->set_bulk_counter_result( 0, count( $post_ids ) );

			return;
		}

		$post_types = (array) $pvc->options['general']['post_types_count'];
		$succeeded = 0;
		$failed = 0;

		foreach ( $post_ids as $post_id ) {
			if ( ! in_array( get_post_type( $post_id ), $post_types, true ) || ! $this->can_update_post_counter_totals( $post_id ) || ! $this->update_post_counter_totals( $post_id, $updates ) )
				$failed++;
			else
				$succeeded++;
		}

		$this->set_bulk_counter_result( $succeeded, $failed );
	}

	/**
	 * Record one request-local Bulk Edit counter outcome and arm the narrowly
	 * scoped redirect filter that reports it. No persistent state is stored.
	 *
	 * @param int $updated Successful counter writes.
	 * @param int $failed Failed counter writes.
	 * @return void
	 */
	private function set_bulk_counter_result( $updated, $failed ) {
		$this->bulk_counter_result = [ 'updated' => (int) $updated, 'failed' => (int) $failed ];

		add_filter( 'wp_redirect', [ $this, 'add_bulk_counter_redirect_args' ] );
	}

	/**
	 * Append sanitized counter outcome counts to the matching bulk redirect.
	 *
	 * @param string $location Redirect location.
	 * @return string
	 */
	public function add_bulk_counter_redirect_args( $location ) {
		if ( is_null( $this->bulk_counter_result ) || ! is_string( $location ) || $location === '' )
			return $location;

		$path = wp_parse_url( $location, PHP_URL_PATH );

		// Only the post-list redirect that ends this bulk request is touched.
		if ( ! is_string( $path ) || basename( $path ) !== 'edit.php' )
			return $location;

		$result = $this->bulk_counter_result;
		$this->bulk_counter_result = null;

		remove_filter( 'wp_redirect', [ $this, 'add_bulk_counter_redirect_args' ] );

		if ( $result['updated'] < 1 && $result['failed'] < 1 )
			return $location;

		return add_query_arg( [ 'pvc_counters_updated' => $result['updated'], 'pvc_counters_failed' => $result['failed'] ], $location );
	}

	/**
	 * Report the Bulk Edit counter outcome on the resulting list page.
	 *
	 * WordPress content updates already completed, so a counter failure is
	 * reported truthfully instead of implying the bulk operation was cancelled.
	 *
	 * @global string $pagenow
	 *
	 * @return void
	 */
	public function bulk_counter_admin_notice() {
		global $pagenow;

		if ( $pagenow !== 'edit.php' || ( ! isset( $_GET['pvc_counters_updated'] ) && ! isset( $_GET['pvc_counters_failed'] ) ) )
			return;

		$updated = isset( $_GET['pvc_counters_updated'] ) && is_scalar( $_GET['pvc_counters_updated'] ) ? absint( wp_unslash( $_GET['pvc_counters_updated'] ) ) : 0;
		$failed = isset( $_GET['pvc_counters_failed'] ) && is_scalar( $_GET['pvc_counters_failed'] ) ? absint( wp_unslash( $_GET['pvc_counters_failed'] ) ) : 0;

		if ( $updated < 1 && $failed < 1 )
			return;

		$messages = [];

		if ( $updated > 0 )
			$messages[] = sprintf( _n( 'Counter totals updated for %s post.', 'Counter totals updated for %s posts.', $updated, 'post-views-counter' ), number_format_i18n( $updated ) );

		if ( $failed > 0 )
			$messages[] = sprintf( _n( 'Counter totals could not be updated for %s post.', 'Counter totals could not be updated for %s posts.', $failed, 'post-views-counter' ), number_format_i18n( $failed ) );

		echo '<div class="notice notice-' . ( $failed > 0 ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( implode( ' ', $messages ) ) . '</p></div>';
	}

	/**
	 * Let WordPress strip the Bulk Edit outcome arguments from the list URL, so
	 * the notice is not shown again on reload.
	 *
	 * @param array $args Removable query arguments.
	 * @return array
	 */
	public function add_removable_query_args( $args ) {
		if ( ! is_array( $args ) )
			return $args;

		$args[] = 'pvc_counters_updated';
		$args[] = 'pvc_counters_failed';

		return $args;
	}

	/**
	 * Bulk save post views.
	 *
	 * Deprecated for the shipped UI: native Bulk Edit now writes counter totals
	 * from the bulk_edit_posts completion hook. The endpoint remains registered,
	 * with unchanged security behavior, for compatibility.
	 *
	 * @return void
	 */
	function save_bulk_post_views() {
		$pvc = Post_Views_Counter();

		// check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'pvc_save_bulk_post_views' ) )
			exit;

		// check post ids
		$post_ids = ( ! empty( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ) ? array_values( array_filter( array_unique( array_map( 'absint', wp_unslash( $_POST['post_ids'] ) ) ) ) ) : [];
		$updates = $this->get_submitted_counter_totals();

		if ( is_null( $updates ) )
			wp_send_json_error( [ 'message' => __( 'Invalid counter totals.', 'post-views-counter' ) ] );

		// allow editing
		$allow_edit = (bool) $pvc->options['display']['restrict_edit_views'];

		// allow editing condition
		$allow_edit_condition = (bool) current_user_can( apply_filters( 'pvc_restrict_edit_capability', 'manage_options' ) ); 

		// break if views editing not allowed or editing condition not met
		if ( $allow_edit === false || $allow_edit_condition === false )
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-views-counter' ) ] );

		$post_types = (array) $pvc->options['general']['post_types_count'];

		// any post ids?
		$updated = 0;
		$failed = 0;

		if ( ! empty( $post_ids ) && ! empty( $updates ) ) {
			foreach ( $post_ids as $post_id ) {
				if ( ! in_array( get_post_type( $post_id ), $post_types, true ) || ! $this->can_update_post_counter_totals( $post_id ) || ! $this->update_post_counter_totals( $post_id, $updates ) )
					$failed++;
				else
					$updated++;
			}
		}

		wp_send_json_success( [ 'updated' => $updated, 'failed' => $failed ] );
	}
}
