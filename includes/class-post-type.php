<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post type of the converted documents.
 *
 * A post type rather than a custom table: revisions, export, backups and the
 * admin list screen come from WordPress for free.
 */
class JIMCA_Post_Type {

	const POST_TYPE = 'jimca_documento';
	const MODE_META = '_jimca_display_mode';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_display_mode' ) );
		add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_box' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'delete_document_images' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_shortcode_column' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_shortcode_column' ), 10, 2 );
	}

	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Accessible Documents', 'jim-conversor-acessivel' ),
			'singular_name'      => __( 'Accessible Document', 'jim-conversor-acessivel' ),
			'add_new_item'       => __( 'Add New Document', 'jim-conversor-acessivel' ),
			'edit_item'          => __( 'Edit Document', 'jim-conversor-acessivel' ),
			'all_items'          => __( 'All Documents', 'jim-conversor-acessivel' ),
			'search_items'       => __( 'Search Documents', 'jim-conversor-acessivel' ),
			'not_found'          => __( 'No converted documents yet.', 'jim-conversor-acessivel' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'jimca-conversor',
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				// Documents only come from a conversion, never from a blank editor.
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'supports'        => array( 'title', 'editor', 'revisions' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'show_in_rest'    => false,
			)
		);
	}

	public function add_shortcode_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'title' === $key ) {
				$new_columns['jimca_shortcode'] = __( 'Shortcode', 'jim-conversor-acessivel' );
				$new_columns['jimca_mode']      = __( 'Display mode', 'jim-conversor-acessivel' );
			}
		}

		return $new_columns;
	}

	public function render_shortcode_column( $column, $post_id ) {
		if ( 'jimca_mode' === $column ) {
			$mode  = self::get_mode( $post_id );
			$modes = JIMCA_Admin::get_display_modes();
			echo '<span data-jimca-mode="' . esc_attr( $mode ) . '">' . esc_html( $modes[ $mode ] ) . '</span>';
			return;
		}

		if ( 'jimca_shortcode' !== $column ) {
			return;
		}

		echo '<input type="text" readonly onclick="this.select();" class="jimca-shortcode-input" style="width:100%;max-width:220px;" value="' . esc_attr( '[documento_acessivel id="' . $post_id . '"]' ) . '">';
	}

	/**
	 * Deletes the document's images when it is deleted for good, not when it
	 * goes to the trash: from there it can still be restored with its images.
	 *
	 * @param int $post_id
	 */
	public function delete_document_images( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		JIMCA_Image_Store::delete_attachments( $post_id );
		// Older documents keep their images in a folder of their own instead of the Media Library.
		JIMCA_Image_Store::delete_dir( get_post_meta( $post_id, '_jimca_images_dir', true ) );
	}

	/**
	 * Display mode of a document: 'reader' (default) or 'post'. Only Quick
	 * Edit in the documents list changes it, so whoever publishes the page
	 * cannot change what the document is.
	 */
	public static function get_mode( $post_id ) {
		$mode = get_post_meta( $post_id, self::MODE_META, true );

		return ( is_string( $mode ) && isset( JIMCA_Admin::get_display_modes()[ $mode ] ) ) ? $mode : 'reader';
	}

	/**
	 * "Display mode" field of Quick Edit. quick-edit.js selects the row's
	 * current mode.
	 */
	public function render_quick_edit_box( $column, $post_type ) {
		if ( 'jimca_mode' !== $column || self::POST_TYPE !== $post_type ) {
			return;
		}

		wp_nonce_field( 'jimca_save_mode', 'jimca_mode_nonce' );
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php esc_html_e( 'Display mode', 'jim-conversor-acessivel' ); ?></span>
					<select name="jimca_display_mode">
						<?php foreach ( JIMCA_Admin::get_display_modes() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Saves the Quick Edit display mode. "reader" is the default, stored as no meta.
	 */
	public function save_display_mode( $post_id ) {
		if ( ! isset( $_POST['jimca_mode_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jimca_mode_nonce'] ) ), 'jimca_save_mode' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$mode = isset( $_POST['jimca_display_mode'] ) ? sanitize_key( wp_unslash( $_POST['jimca_display_mode'] ) ) : '';

		if ( 'post' === $mode ) {
			update_post_meta( $post_id, self::MODE_META, 'post' );
		} elseif ( 'reader' === $mode ) {
			delete_post_meta( $post_id, self::MODE_META );
		}
	}

	public function add_meta_boxes() {
		add_meta_box(
			'jimca_documento_info',
			__( 'Conversion details', 'jim-conversor-acessivel' ),
			array( $this, 'render_info_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'jimca_documento_audit',
			__( 'Conversion review', 'jim-conversor-acessivel' ),
			array( $this, 'render_audit_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * "Did you find problems in the conversion? Click here": runs the
	 * semantic review in the browser (assets/js/auditor.js) over the content
	 * of the editor and offers to fix what it finds.
	 */
	public function render_audit_meta_box() {
		?>
		<p id="jimca-audit-question"><?php esc_html_e( 'Did you find problems in the conversion?', 'jim-conversor-acessivel' ); ?></p>
		<p>
			<button type="button" class="button button-primary" id="jimca-audit-run" aria-describedby="jimca-audit-question jimca-audit-help">
				<?php esc_html_e( 'Click here', 'jim-conversor-acessivel' ); ?><span class="screen-reader-text"> <?php esc_html_e( 'to review the conversion', 'jim-conversor-acessivel' ); ?></span>
			</button>
		</p>
		<p class="description" id="jimca-audit-help">
			<?php esc_html_e( 'Checks the document for headings that are not headings, text outside paragraphs, empty paragraphs and broken line breaks, which stop screen readers and the Listen button from working well.', 'jim-conversor-acessivel' ); ?>
		</p>
		<div id="jimca-audit-result" class="jimca-audit" role="status" aria-live="polite"></div>
		<?php
	}

	public function render_info_meta_box( $post ) {
		$original_filename = get_post_meta( $post->ID, '_jimca_original_filename', true );
		$original_type     = get_post_meta( $post->ID, '_jimca_original_type', true );
		$word_count        = get_post_meta( $post->ID, '_jimca_word_count', true );
		$converted_at      = get_post_meta( $post->ID, '_jimca_converted_at', true );

		echo '<p><strong>' . esc_html__( 'Original file:', 'jim-conversor-acessivel' ) . '</strong><br>' . esc_html( $original_filename ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Type:', 'jim-conversor-acessivel' ) . '</strong> ' . esc_html( strtoupper( $original_type ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Words:', 'jim-conversor-acessivel' ) . '</strong> ' . esc_html( number_format_i18n( (int) $word_count ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Converted on:', 'jim-conversor-acessivel' ) . '</strong><br>' . esc_html( $converted_at ) . '</p>';

		$report = JIMCA_Image_Store::render_report( get_post_meta( $post->ID, '_jimca_image_report', true ), true );
		if ( '' !== $report ) {
			echo '<hr><p><strong>' . esc_html__( 'Images:', 'jim-conversor-acessivel' ) . '</strong></p>' . wp_kses_post( $report );
		}

		echo '<hr>';
		echo '<p><strong>' . esc_html__( 'Shortcode:', 'jim-conversor-acessivel' ) . '</strong></p>';
		echo '<input type="text" readonly onclick="this.select();" style="width:100%" value="' . esc_attr( '[documento_acessivel id="' . $post->ID . '"]' ) . '">';
	}
}
