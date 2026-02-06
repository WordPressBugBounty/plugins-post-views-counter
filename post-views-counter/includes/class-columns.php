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
		add_action( 'wp_ajax_save_bulk_post_views', [ $this, 'save_bulk_post_views' ] );
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

		// get main instance
		$pvc = Post_Views_Counter();

		// break if display is not allowed
		if ( ! $pvc->options['display']['post_views_column'] || ! in_array( $post->post_type, $pvc->options['general']['post_types_count'] ) )
			return;

		// check if user can see post stats
		if ( apply_filters( 'pvc_admin_display_post_views', true, $post->ID ) === false )
			return;

		// get total post views
		$count = (int) pvc_get_post_views( $post->ID ); ?>

		<div class="misc-pub-section" id="post-views">

			<?php wp_nonce_field( 'post_views_count', 'pvc_nonce' ); ?>

			<span id="post-views-display">
				<?php echo __( 'Post Views', 'post-views-counter' ) . ': <b>' . number_format_i18n( $count ) . '</b>'; ?>
			</span>

			<?php
			// allow editing
			$allow_edit = (bool) $pvc->options['display']['restrict_edit_views'];

			// allow editing condition
			$allow_edit_condition = (bool) current_user_can( apply_filters( 'pvc_restrict_edit_capability', 'manage_options' ) ); 

			if ( $allow_edit === true && $allow_edit_condition === true ) {
				?>
				<a href="#post-views" class="edit-post-views hide-if-no-js"><?php _e( 'Edit', 'post-views-counter' ); ?></a>

				<div id="post-views-input-container" class="hide-if-js">

					<p><?php _e( 'Adjust the views count for this post.', 'post-views-counter' ); ?></p>
					<input type="hidden" name="current_post_views" id="post-views-current" value="<?php echo esc_attr( $count ); ?>" />
					<input type="text" name="post_views" id="post-views-input" value="<?php echo esc_attr( $count ); ?>"/><br />
					<p>
						<a href="#post-views" class="save-post-views hide-if-no-js button"><?php _e( 'OK', 'post-views-counter' ); ?></a>
						<a href="#post-views" class="cancel-post-views hide-if-no-js"><?php _e( 'Cancel', 'post-views-counter' ); ?></a>
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

		// break if current user can't edit this post
		if ( ! current_user_can( 'edit_post', $post_id ) )
			return;

		// is post views set
		if ( ! isset( $_POST['post_views'] ) )
			return;

		// cast numeric post views
		$post_views = (int) $_POST['post_views'];

		// unchanged post views value?
		if ( isset( $_POST['current_post_views'] ) && $post_views === (int) $_POST['current_post_views'] )
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
		if ( ! isset( $_POST['pvc_nonce'] ) || ! wp_verify_nonce( $_POST['pvc_nonce'], 'post_views_count' ) )
			return;

		// update post views
		pvc_update_post_views( $post_id, $post_views );

		do_action( 'pvc_after_update_post_views_count', $post_id );
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
	 * @param array $columns
	 * @return array
	 */
	public function register_sortable_custom_column( $columns ) {
		global $post_type;
		
		// get main instance
		$pvc = Post_Views_Counter();

		// break if display is disabled
		if ( ! $pvc->options['display']['post_views_column'] || ! in_array( $post_type, $pvc->options['general']['post_types_count'] ) )
			return $columns;

		// check if user can see stats
		if ( apply_filters( 'pvc_admin_display_post_views', true ) === false )
			return $columns;

		// add new sortable column
		$columns['post_views'] = 'post_views';

		return $columns;
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
		$columns['post_views'] = '<span class="pvc-views-header" title="' . esc_attr__( 'Post Views', 'post-views-counter' ) . '"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"><path d="M12 2a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h1a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1h-1ZM6.5 6a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V6ZM2 9a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V9Z" /></svg><span class="screen-reader-text">' . esc_attr__( 'Post Views', 'post-views-counter' ) . '</span></span>';

		// restore date column
		if ( isset( $date ) )
			$columns['date'] = $date;

		// restore comments column
		if ( isset( $comments ) )
			$columns['comments'] = $comments;

		return $columns;
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
			// get total post views
			$count = pvc_get_post_views( $id );
			
			// check if user can see stats
			if ( apply_filters( 'pvc_admin_display_post_views', true, $id ) === false ) {
				echo '—';
				return;
			}

			// get post title
			$post_title = get_the_title( $id );

			if ( $post_title === '' )
				$post_title = __( '(no title)', 'post-views-counter' );

			// get post type labels
			$post_type_object = get_post_type_object( get_post_type( $id ) );

			if ( $post_type_object ) {
				$post_type_labels = get_post_type_labels( $post_type_object );
			}

			if ( $post_type_labels ) {
				$post_title = $post_type_labels->singular_name . ': ' . $post_title;
			}

			// clickable link (modal opening handled via JavaScript)
			echo '<a href="#" class="pvc-view-chart" data-post-id="' . esc_attr( $id ) . '" data-post-title="' . esc_attr( $post_title ) . '">' . esc_html( $count ) . '</a>';
		}
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

		// break if display is not allowed
		if ( ! $pvc->options['display']['post_views_column'] || ! in_array( $post_type, $pvc->options['general']['post_types_count'] ) )
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
				<label class="inline-edit-group">
					<span class="title"><?php _e( 'Post Views', 'post-views-counter' ); ?></span>
					<?php if ( $allow_edit === true && $allow_edit_condition === true ) { ?>
						<span class="input-text-wrap"><input type="text" name="post_views" class="title text" value=""></span>
						<input type="hidden" name="current_post_views" value="" />
						<?php wp_nonce_field( 'post_views_count', 'pvc_nonce' ); ?>
					<?php } else { ?>
						<span class="input-text-wrap"><input type="text" name="post_views" class="title text" value="" disabled readonly /></span>
					<?php } ?>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Bulk save post views.
	 *
	 * @global object $wpdb
	 *
	 * @return void
	 */
	function save_bulk_post_views() {
		global $wpdb;

		// check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'pvc_save_bulk_post_views' ) )
			exit;

		$count = null;

		if ( isset( $_POST['post_views'] ) && is_numeric( trim( $_POST['post_views'] ) ) ) {
			$count = (int) $_POST['post_views'];

			if ( $count < 0 )
				$count = 0;
		}

		// check post ids
		$post_ids = ( ! empty( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ) ? array_map( 'absint', $_POST['post_ids'] ) : [];

		if ( is_null( $count ) )
			exit;

		// allow editing
		$allow_edit = (bool) $pvc->options['display']['restrict_edit_views'];

		// allow editing condition
		$allow_edit_condition = (bool) current_user_can( apply_filters( 'pvc_restrict_edit_capability', 'manage_options' ) ); 

		// break if views editing not allowed or editing condition not met
		if ( $allow_edit === false || $allow_edit_condition === false )
			exit;

		// any post ids?
		if ( ! empty( $post_ids ) ) {
			foreach ( $post_ids as $post_id ) {
				// break if current user can't edit this post
				if ( ! current_user_can( 'edit_post', $post_id ) )
					continue;

				// insert or update db post views count
				$wpdb->query( $wpdb->prepare( "INSERT INTO " . $wpdb->prefix . "post_views (id, type, period, count) VALUES (%d, %d, %s, %d) ON DUPLICATE KEY UPDATE count = %d", $post_id, 4, 'total', $count, $count ) );
			}
		}

		exit;
	}
}
