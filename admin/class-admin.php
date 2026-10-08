<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens: document upload, settings and tutorial.
 */
class JIMCA_Admin {

	const CAPABILITY_UPLOAD  = 'upload_files';
	const CAPABILITY_MANAGE  = 'manage_options';
	const MENU_SLUG          = 'jimca-conversor';
	const SETTINGS_OPTION    = 'jimca_settings';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_jimca_upload_document', array( $this, 'handle_upload' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'render_documents_list_banner' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_row_meta' ), 10, 2 );

		JIMCA_Log::register_hooks();
		JIMCA_AI::register_hooks();
		JIMCA_Conversion_Job::register_hooks();
		JIMCA_Privacy::get_instance()->init();
	}

	/**
	 * Shows a message on the next admin screen of the current user.
	 *
	 * @param string $type    'success', 'warning' or 'error'.
	 * @param string $message Message, already escaped.
	 */
	public function flash_notice( $type, $message ) {
		$this->set_user_notice( $type, $message );
	}

	/**
	 * Extra links in the plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Links already in the row.
	 * @param string   $file  Plugin of that row.
	 * @return string[]
	 */
	public function add_plugin_row_meta( $links, $file ) {
		if ( plugin_basename( JIMCA_PLUGIN_FILE ) !== $file ) {
			return $links;
		}

		// The tutorial is an admin screen: only useful to whoever can open it.
		if ( current_user_can( self::CAPABILITY_UPLOAD ) ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-tutorial' ) ),
				esc_html__( 'Tutorial', 'jim-conversor-acessivel' )
			);
		}

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( JIMCA_REPO_URL ),
			esc_html__( 'Visit the repository', 'jim-conversor-acessivel' )
		);

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( JIMCA_SITE_URL ),
			esc_html__( 'Plugin website', 'jim-conversor-acessivel' )
		);

		return $links;
	}

	/**
	 * Loads CSS/JS only on the plugin's own screens, so nothing leaks into the
	 * rest of wp-admin.
	 *
	 * @param string $hook Hook suffix of the current screen.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Settings screen: live log.
		if ( false !== strpos( (string) $hook, self::MENU_SLUG . '-settings' ) ) {
			wp_enqueue_script( 'jimca-log', JIMCA_PLUGIN_URL . 'assets/js/log.js', array(), jimca_asset_version( 'assets/js/log.js' ), true );
			wp_localize_script(
				'jimca-log',
				'jimcaLogI18n',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( JIMCA_Log::NONCE ),
					'fetch'     => JIMCA_Log::AJAX_FETCH,
					'clear'     => JIMCA_Log::AJAX_CLEAR,
					'empty'     => __( 'No entries yet.', 'jim-conversor-acessivel' ),
					'newOne'    => __( '1 new entry.', 'jim-conversor-acessivel' ),
					/* translators: %d: number of new log entries */
					'newMany'   => __( '%d new entries.', 'jim-conversor-acessivel' ),
					'cleared'   => __( 'Log cleared.', 'jim-conversor-acessivel' ),
					'failed'    => __( 'Could not update the log.', 'jim-conversor-acessivel' ),
					'confirm'   => __( 'Clear the whole log?', 'jim-conversor-acessivel' ),
					'liveOn'    => __( 'Updating every 3 seconds.', 'jim-conversor-acessivel' ),
					'liveOff'   => __( 'Live updates paused.', 'jim-conversor-acessivel' ),
				)
			);
		}

		unset( $hook ); // get_current_screen() is enough here.
		$screen                 = get_current_screen();
		$documents_list_screen  = 'edit-' . JIMCA_Post_Type::POST_TYPE;
		$is_plugin_screen       = $screen && ( false !== strpos( $screen->id, self::MENU_SLUG ) || $documents_list_screen === $screen->id );

		if ( ! $is_plugin_screen ) {
			return;
		}

		wp_enqueue_style( 'jimca-admin', JIMCA_PLUGIN_URL . 'assets/css/admin.css', array(), jimca_asset_version( 'assets/css/admin.css' ) );

		if ( $documents_list_screen === $screen->id ) {
			wp_enqueue_script( 'jimca-quick-edit', JIMCA_PLUGIN_URL . 'assets/js/quick-edit.js', array( 'inline-edit-post' ), jimca_asset_version( 'assets/js/quick-edit.js' ), true );
		}

		if ( self::MENU_SLUG === $screen->id || 'toplevel_page_' . self::MENU_SLUG === $screen->id ) {
			wp_enqueue_script( 'jimca-admin', JIMCA_PLUGIN_URL . 'assets/js/admin.js', array(), jimca_asset_version( 'assets/js/admin.js' ), true );
			wp_localize_script(
				'jimca-admin',
				'jimcaAdminI18n',
				array(
					/* translators: %s: name of the chosen file */
					'fileAdded'      => __( 'File added: %s', 'jim-conversor-acessivel' ),
					/* translators: %s: number of files and total size */
					'filesAdded'     => __( 'Files added: %s', 'jim-conversor-acessivel' ),
					'converting'     => __( 'Converting the document…', 'jim-conversor-acessivel' ),
					'convertingHint' => __( 'Depending on the file size this can take from a few seconds to about a minute. Do not close or refresh this page.', 'jim-conversor-acessivel' ),
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'actionStart'    => JIMCA_Conversion_Job::AJAX_START,
					'actionRun'      => JIMCA_Conversion_Job::AJAX_RUN,
					'actionStatus'   => JIMCA_Conversion_Job::AJAX_STATUS,
					'sending'        => __( 'Sending the file…', 'jim-conversor-acessivel' ),
					'longHint'       => __( 'Large books can take several minutes. You can keep working in another tab; do not close this one.', 'jim-conversor-acessivel' ),
					'reading'        => __( 'Reading the file structure…', 'jim-conversor-acessivel' ),
					/* translators: 1: page being converted, 2: total pages */
					'pages'          => __( 'Page %1$s of %2$s', 'jim-conversor-acessivel' ),
					/* translators: %s: number of images included so far */
					'images'         => __( '%s images so far', 'jim-conversor-acessivel' ),
					/* translators: %s: percentage, e.g. "50%" */
					'percent'        => __( '%s converted', 'jim-conversor-acessivel' ),
					'done'           => __( 'Conversion finished. Opening the result…', 'jim-conversor-acessivel' ),
					'failed'         => __( 'The conversion failed.', 'jim-conversor-acessivel' ),
					'network'        => __( 'Could not reach the server. Check your connection and try again.', 'jim-conversor-acessivel' ),
					'tooLarge'       => __( 'The server refused the file because it is too large (HTTP 413). Ask your host to raise the upload limit (nginx "client_max_body_size" or PHP "upload_max_filesize").', 'jim-conversor-acessivel' ),
					/* translators: %s: HTTP status code */
					'httpError'      => __( 'The server answered with an error (HTTP %s) before the conversion started.', 'jim-conversor-acessivel' ),
				)
			);
		}
	}

	public function register_menu() {
		add_menu_page(
			__( 'Jim - Accessible Converter', 'jim-conversor-acessivel' ),
			__( 'Jim - Accessible Converter', 'jim-conversor-acessivel' ),
			self::CAPABILITY_UPLOAD,
			self::MENU_SLUG,
			array( $this, 'render_upload_page' ),
			'dashicons-image-rotate-right',
			26
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'New Document', 'jim-conversor-acessivel' ),
			__( 'New Document', 'jim-conversor-acessivel' ),
			self::CAPABILITY_UPLOAD,
			self::MENU_SLUG,
			array( $this, 'render_upload_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'jim-conversor-acessivel' ),
			__( 'Settings', 'jim-conversor-acessivel' ),
			self::CAPABILITY_MANAGE,
			self::MENU_SLUG . '-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Tutorial', 'jim-conversor-acessivel' ),
			__( 'Tutorial', 'jim-conversor-acessivel' ),
			self::CAPABILITY_UPLOAD,
			self::MENU_SLUG . '-tutorial',
			array( $this, 'render_tutorial_page' )
		);
	}

	public static function get_settings() {
		$stored   = get_option( self::SETTINGS_OPTION, array() );
		$settings = wp_parse_args( $stored, JIMCA_Activator::default_settings() );

		// Settings saved by 1.x have no Markdown in the allowed types: it is added once.
		if ( is_array( $stored ) && $stored && empty( $stored['settings_version'] ) && ! in_array( 'md', $settings['allowed_types'], true ) ) {
			$settings['allowed_types'][] = 'md';
		}

		// Sites still on the earlier image defaults (40 images, 30 MB) move to the current ones.
		if ( is_array( $stored ) && $stored && ( empty( $stored['settings_version'] ) || (int) $stored['settings_version'] < 3 )
			&& JIMCA_Limits::LEGACY_MAX_IMAGES === (int) $settings['max_images']
			&& JIMCA_Limits::LEGACY_MAX_IMAGES_MB === (int) $settings['max_images_mb'] ) {
			$settings['max_images']    = JIMCA_Limits::DEFAULT_MAX_IMAGES;
			$settings['max_images_mb'] = JIMCA_Limits::DEFAULT_MAX_IMAGES_MB;
		}

		return $settings;
	}

	/**
	 * The upload limit that really applies: the lower of the setting and what
	 * PHP accepts (upload_max_filesize / post_max_size). Shown before upload so
	 * nobody sends a file PHP will refuse before WordPress even sees it.
	 *
	 * @return int Bytes.
	 */
	public static function get_effective_max_bytes() {
		$settings   = self::get_settings();
		$plugin_max = (int) $settings['max_file_size_mb'] * MB_IN_BYTES;
		$server_max = (int) wp_max_upload_size();

		if ( $server_max > 0 ) {
			return min( $plugin_max, $server_max );
		}

		return $plugin_max;
	}

	/**
	 * Reading themes; each key matches a `jimca-theme-{key}` class in
	 * assets/css/frontend.css.
	 *
	 * @return array<string, string> Key => translated label.
	 */
	public static function get_available_themes() {
		return array(
			'light' => __( 'Light', 'jim-conversor-acessivel' ),
			'sepia' => __( 'Sepia', 'jim-conversor-acessivel' ),
			'dark'  => __( 'Dark', 'jim-conversor-acessivel' ),
		);
	}

	/**
	 * Display modes: reader (floating bar, voice, themes) or blog post
	 * (converted HTML page without the player). Chosen only in the documents
	 * list (Quick Edit), never in the shortcode.
	 */
	public static function get_display_modes() {
		return array(
			'reader' => __( 'Reader (with reading bar and voice)', 'jim-conversor-acessivel' ),
			'post'   => __( 'Blog post (converted HTML page)', 'jim-conversor-acessivel' ),
		);
	}

	/**
	 * Shared header of the plugin screens: logo and plugin name. Navigation
	 * between screens is the wp-admin submenu; each screen shows its own title
	 * below (see render_page_title()).
	 *
	 * The `wp-header-end` marker tells WordPress where to move admin notices.
	 * Without it, notices are moved right after the first <h1>, which is
	 * inside the blue banner, and inherit its white text.
	 *
	 * @param bool $header_end Print the marker. The documents list already has
	 *                         WordPress's own; a second one would duplicate notices.
	 */
	private function render_admin_header( $header_end = true ) {
		?>
		<div class="jimca-admin-header">
			<div class="jimca-admin-header__logo">
				<img src="<?php echo esc_url( JIMCA_PLUGIN_URL . 'assets/images/logo.png' ); ?>" alt="" width="56" height="56">
			</div>
			<div class="jimca-admin-header__text">
				<h1><?php esc_html_e( 'Jim - Accessible Converter', 'jim-conversor-acessivel' ); ?></h1>
				<p><?php esc_html_e( 'Accessible documents, read aloud, in a few clicks.', 'jim-conversor-acessivel' ); ?></p>
			</div>
		</div>
		<?php
		if ( $header_end ) {
			echo '<hr class="wp-header-end">';
		}
	}

	/**
	 * Title of each plugin screen, shown below the header.
	 *
	 * @param string $title Translated title.
	 */
	private function render_page_title( $title ) {
		?>
		<h2 class="jimca-admin-page-title"><?php echo esc_html( $title ); ?></h2>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Upload
	 * ------------------------------------------------------------- */

	public function render_upload_page() {
		if ( ! current_user_can( self::CAPABILITY_UPLOAD ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'jim-conversor-acessivel' ) );
		}

		$settings = self::get_settings();
		?>
		<div class="wrap">
			<?php
			$this->render_admin_header();
			$this->render_page_title( __( 'New Document', 'jim-conversor-acessivel' ) );
			?>

			<div class="jimca-admin-card">
				<p><?php esc_html_e( 'Upload a PDF, DOCX, TXT or Markdown (.md) file to generate an accessible page (with reading aloud) and a shortcode to publish it. Light files (TXT and MD) can be sent several at a time: each one becomes a document and you get the list of shortcodes.', 'jim-conversor-acessivel' ); ?></p>

				<div id="jimca-file-toast" class="jimca-admin-toast" role="status" aria-live="polite" hidden></div>

				<form id="jimca-upload-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'jimca_upload_document', 'jimca_upload_nonce' ); ?>
					<input type="hidden" name="action" value="jimca_upload_document">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="jimca_title"><?php esc_html_e( 'Document title', 'jim-conversor-acessivel' ); ?></label></th>
							<td>
								<input type="text" id="jimca_title" name="jimca_title" class="regular-text" aria-describedby="jimca_title_help">
								<p class="description" id="jimca_title_help"><?php esc_html_e( 'Optional: without a title, the file name is used. When sending several files, each document uses its own file name.', 'jim-conversor-acessivel' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jimca_file"><?php esc_html_e( 'File', 'jim-conversor-acessivel' ); ?></label></th>
							<td>
								<input type="file" id="jimca_file" name="jimca_file[]" accept=".pdf,.docx,.txt,.md,.markdown" multiple required>
								<input type="hidden" name="jimca_expected_files" id="jimca_expected_files" value="0">
								<p class="description">
									<?php
									printf(
										/* translators: 1: allowed file types, 2: formatted maximum size (e.g. "10 MB") */
										esc_html__( 'Accepted types: %1$s. Maximum size per file: %2$s. Several files at once: only TXT and MD (up to 20); PDF and DOCX are sent one at a time.', 'jim-conversor-acessivel' ),
										esc_html( strtoupper( implode( ', ', $settings['allowed_types'] ) ) ),
										esc_html( size_format( self::get_effective_max_bytes() ) )
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'PDF pages', 'jim-conversor-acessivel' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="jimca_remove_pages" name="jimca_remove_pages" value="1" aria-describedby="jimca_remove_pages_help">
									<?php esc_html_e( 'Remove page markers ("Page 1", "Page 2"…)', 'jim-conversor-acessivel' ); ?>
								</label>
								<p class="description" id="jimca_remove_pages_help"><?php esc_html_e( 'The text flows like in a digital book: sentences split at a page break are joined and the page numbers printed at the top or bottom are dropped. PDF only.', 'jim-conversor-acessivel' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Convert document', 'jim-conversor-acessivel' ) ); ?>

					<?php
					/*
					 * Conversion progress, filled in by assets/js/admin.js. Without
					 * it a long conversion gives no feedback at all after the click.
					 */
					?>
					<div id="jimca-upload-progress" class="jimca-admin-progress" hidden>
						<span class="jimca-admin-spinner" aria-hidden="true" data-jimca-progress-spinner></span>
						<span class="jimca-admin-progress__body">
							<strong class="jimca-admin-progress__title" data-jimca-progress-title></strong>
							<span class="jimca-admin-progress__hint" data-jimca-progress-hint></span>
							<progress class="jimca-admin-progress__bar" max="100" value="0" aria-label="<?php esc_attr_e( 'Conversion progress', 'jim-conversor-acessivel' ); ?>" data-jimca-progress-bar hidden></progress>
							<span class="jimca-admin-progress__detail" data-jimca-progress-detail></span>
						</span>
						<?php // Only milestones (start, 25%, 50%, 75%, end, error) are announced: reading every page aloud would be noise. ?>
						<span class="screen-reader-text" role="status" aria-live="polite" data-jimca-progress-live></span>
					</div>
				</form>
			</div>

			<p class="jimca-admin-footer-link">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . JIMCA_Post_Type::POST_TYPE ) ); ?>">
					<?php esc_html_e( 'See all converted documents and their shortcodes →', 'jim-conversor-acessivel' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	public function handle_upload() {
		if ( ! current_user_can( self::CAPABILITY_UPLOAD ) ) {
			wp_die( esc_html__( 'You do not have permission to upload documents.', 'jim-conversor-acessivel' ) );
		}

		$redirect = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		/*
		 * When the upload exceeds PHP's post_max_size, PHP drops $_POST and
		 * $_FILES entirely BEFORE WordPress runs, nonce included. Without this
		 * block, check_admin_referer() would fail and show the generic "Are you
		 * sure you want to do this?" screen with no clue. The case is detected
		 * and explained here.
		 */
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- no $_POST value is read here; this only detects that PHP dropped the whole request (nonce included) for exceeding post_max_size, which must be handled before the nonce check.
		if ( 'POST' === $request_method && empty( $_POST ) && empty( $_FILES ) ) {
			$this->set_user_notice(
				'error',
				sprintf(
					/* translators: %s: server upload limit, formatted (e.g. "2 MB") */
					esc_html__( 'The file is too large for the server to accept (current limit: %s). Send a smaller file or ask your host to raise the PHP limits "upload_max_filesize" and "post_max_size".', 'jim-conversor-acessivel' ),
					esc_html( size_format( wp_max_upload_size() ) )
				)
			);

			wp_safe_redirect( $redirect );
			exit;
		}

		check_admin_referer( 'jimca_upload_document', 'jimca_upload_nonce' );

		try {
			$this->process_upload_batch();
		} catch ( \Throwable $e ) {
			$this->set_user_notice( 'error', $this->record_failure( $e ) );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Logs a failed conversion and returns the message for the screen.
	 *
	 * \Throwable, not just \Exception: the PDF/DOCX libraries also throw
	 * \Error (corrupted file, missing PHP extension).
	 *
	 * @param \Throwable $e
	 * @return string Escaped message.
	 */
	public function record_failure( \Throwable $e ) {
		$peak_mb = JIMCA_Log::metrics( microtime( true ) )['peak_mb'];

		if ( $e instanceof JIMCA_Converter_Exception ) {
			JIMCA_Log::add( 'error', 'upload.failed', $e->getMessage(), array( 'peak_mb' => $peak_mb ) );

			// Messages of this exception are escaped where they are thrown.
			return $e->getMessage();
		}

		JIMCA_Log::add(
			'error',
			'upload.exception',
			get_class( $e ) . ': ' . $e->getMessage(),
			array(
				'file'    => basename( $e->getFile() ),
				'line'    => $e->getLine(),
				'peak_mb' => $peak_mb,
			)
		);

		$message = esc_html__( 'An unexpected error occurred while processing the file.', 'jim-conversor-acessivel' );

		// The technical detail only for site administrators: it is what points to the cause.
		if ( current_user_can( self::CAPABILITY_MANAGE ) ) {
			$message .= ' <br><small><code>' . esc_html( $e->getMessage() ) . '</code></small>';
		}

		return $message;
	}

	/**
	 * Next-screen notice for a freshly converted document: success, or a
	 * warning when images were left out or the text looks unreadable, with the
	 * image report and the shortcode.
	 *
	 * @param int $post_id
	 */
	public function notify_converted( $post_id ) {
		$report = get_post_meta( $post_id, '_jimca_image_report', true );
		$report = is_array( $report ) ? $report : array();
		$link   = '<a href="' . esc_url( get_edit_post_link( $post_id, '' ) ) . '">' . esc_html__( 'View document and shortcode', 'jim-conversor-acessivel' ) . '</a>';
		$lost   = ! empty( $report['skipped'] ) || ! empty( $report['stopped'] );

		if ( get_post_meta( $post_id, '_jimca_unreadable', true ) ) {
			$this->set_user_notice(
				'warning',
				sprintf(
					/* translators: %s: link to edit the document */
					esc_html__( 'The document was created, but its text looks unreadable: this PDF uses fonts that do not say which letter each character is, so the text comes out as symbols (and would be read aloud that way). Check the document. If it is garbled, run OCR on the PDF or export it again with embedded Unicode fonts, then convert it again. %s', 'jim-conversor-acessivel' ),
					$link
				),
				JIMCA_Image_Store::render_report( $report, true ),
				array( '[' . JIMCA_Shortcode::TAG . ' id="' . $post_id . '"]' )
			);
			return;
		}

		if ( ! $lost ) {
			/* translators: %s: link to edit the document */
			$message = sprintf( esc_html__( 'Document converted successfully! %s', 'jim-conversor-acessivel' ), $link );
		} elseif ( empty( $report['included'] ) ) {
			/* translators: %s: link to edit the document */
			$message = sprintf( esc_html__( 'The text was converted, but none of the images could be included. See why below. %s', 'jim-conversor-acessivel' ), $link );
		} else {
			/* translators: %s: link to edit the document */
			$message = sprintf( esc_html__( 'The text was converted, but some images were left out. See why below. %s', 'jim-conversor-acessivel' ), $link );
		}

		$this->set_user_notice(
			$lost ? 'warning' : 'success',
			$message,
			JIMCA_Image_Store::render_report( $report, true ),
			array( '[' . JIMCA_Shortcode::TAG . ' id="' . $post_id . '"]' )
		);
	}

	/**
	 * Stores a notice for the current user's next screen.
	 *
	 * @param string $type    'success', 'warning' or 'error'.
	 * @param string $message Escaped message.
	 */
	private function set_user_notice( $type, $message, $details = '', array $shortcodes = array() ) {
		set_transient(
			'jimca_notice_' . get_current_user_id(),
			array(
				'type'       => $type,
				'message'    => $message,
				'details'    => $details,
				'shortcodes' => $shortcodes,
			),
			300
		);
	}

	/**
	 * Images of the conversion in progress. If PHP dies mid-way (memory,
	 * time), JIMCA_Conversion_Job deletes those already in the Media Library.
	 *
	 * @var JIMCA_Image_Store|null
	 */
	public $image_store_in_progress = null;

	/** Most light files (TXT/MD) per upload. */
	const MAX_BATCH = 20;

	/**
	 * Converts every file of the form upload and stores the notice (with the
	 * list of shortcodes) for the next screen.
	 *
	 * Several files at once only for the light formats (TXT and MD):
	 * converting PDFs or DOCX one after another in one request would run out
	 * of PHP time. If one file of a batch fails, the others go on and the
	 * notice says which one failed and why.
	 *
	 * @throws JIMCA_Converter_Exception When the upload is invalid, or when its only file fails.
	 */
	private function process_upload_batch() {
		// Nonce already checked in handle_upload(); again here as defense in depth.
		check_admin_referer( 'jimca_upload_document', 'jimca_upload_nonce' );

		// How many files the browser sent (PHP silently drops those beyond max_file_uploads).
		$expected = isset( $_POST['jimca_expected_files'] ) ? absint( $_POST['jimca_expected_files'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP upload array: collect_uploaded_files() sanitizes each name with sanitize_file_name() and casts sizes and errors to int; the file then goes through wp_check_filetype_and_ext() and wp_handle_upload().
		$files    = $this->collect_uploaded_files( isset( $_FILES['jimca_file'] ) ? $_FILES['jimca_file'] : array(), $expected );
		$multiple = count( $files ) > 1;

		if ( $multiple ) {
			if ( count( $files ) > self::MAX_BATCH ) {
				throw new JIMCA_Converter_Exception(
					sprintf(
						/* translators: %d: maximum number of files per upload */
						esc_html__( 'Send at most %d files at a time.', 'jim-conversor-acessivel' ),
						absint( self::MAX_BATCH )
					)
				);
			}

			foreach ( $files as $file ) {
				if ( ! in_array( self::extension_of( $file['name'] ), array( 'txt', 'md' ), true ) ) {
					throw new JIMCA_Converter_Exception(
						sprintf(
							/* translators: %s: file name */
							esc_html__( 'To send several files at once, use only TXT and MD. "%s" must be sent alone (PDF and DOCX are heavy to convert in sequence).', 'jim-conversor-acessivel' ),
							esc_html( $file['name'] )
						)
					);
				}
			}
		}

		$title = isset( $_POST['jimca_title'] ) ? sanitize_text_field( wp_unslash( $_POST['jimca_title'] ) ) : '';
		$remove_pages = ! empty( $_POST['jimca_remove_pages'] );

		JIMCA_Limits::raise_for_conversion();

		$done   = array();
		$failed = array();

		foreach ( $files as $file ) {
			try {
				$done[] = $this->process_file( $file, $multiple ? '' : $title, $remove_pages );
			} catch ( JIMCA_Converter_Exception $e ) {
				if ( ! $multiple ) {
					throw $e;
				}
				JIMCA_Log::add( 'error', 'upload.failed', sanitize_file_name( $file['name'] ) . ': ' . $e->getMessage() );
				$failed[] = array( $file['name'], $e->getMessage() );
			} catch ( \Throwable $e ) {
				if ( ! $multiple ) {
					throw $e;
				}
				JIMCA_Log::add( 'error', 'upload.exception', sanitize_file_name( $file['name'] ) . ': ' . get_class( $e ) . ': ' . $e->getMessage() );
				$failed[] = array( $file['name'], esc_html( $e->getMessage() ) );
			}
		}

		$shortcodes = array();
		$details    = '<ul>';

		foreach ( $done as $post_id ) {
			$shortcodes[] = '[' . JIMCA_Shortcode::TAG . ' id="' . $post_id . '"]';
			$details     .= '<li><a href="' . esc_url( get_edit_post_link( $post_id, '' ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a>'
				. JIMCA_Image_Store::render_report( get_post_meta( $post_id, '_jimca_image_report', true ) ) . '</li>';
		}

		foreach ( $failed as $failure ) {
			$details .= '<li><strong>' . esc_html( $failure[0] ) . ':</strong> ' . $failure[1] . '</li>';
		}

		$details .= '</ul>';

		if ( ! $multiple ) {
			$this->notify_converted( $done[0] );
			return;
		}

		$this->set_user_notice(
			$done ? 'success' : 'error',
			sprintf(
				/* translators: 1: documents converted, 2: files sent */
				esc_html__( '%1$d of %2$d documents converted.', 'jim-conversor-acessivel' ),
				count( $done ),
				count( $files )
			),
			$details,
			$shortcodes
		);
	}

	/**
	 * Reads the uploaded files (one or several "jimca_file[]" fields).
	 *
	 * @param array $raw      Content of $_FILES['jimca_file'].
	 * @param int   $expected How many files the browser said it sent.
	 * @return array<int, array{name: string, tmp_name: string, size: int, error: int, type: string}>
	 * @throws JIMCA_Converter_Exception
	 */
	public function collect_uploaded_files( $raw, $expected ) {
		if ( empty( $raw ) || ! is_array( $raw ) || ! isset( $raw['name'], $raw['error'], $raw['tmp_name'], $raw['size'] ) ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'No valid file was sent.', 'jim-conversor-acessivel' ) );
		}

		// Each field is validated later in receive_file() (type, size, wp_handle_upload()); the name is sanitized below.
		$files = array();

		foreach ( (array) $raw['name'] as $index => $name ) {
			$error = (int) ( (array) $raw['error'] )[ $index ];

			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}

			if ( UPLOAD_ERR_OK !== $error ) {
				throw new JIMCA_Converter_Exception(
					sprintf(
						/* translators: 1: file name, 2: server upload limit */
						esc_html__( 'The file "%1$s" could not be uploaded (server limit: %2$s). Send a smaller file or ask your host to raise "upload_max_filesize" and "post_max_size".', 'jim-conversor-acessivel' ),
						esc_html( sanitize_file_name( wp_unslash( $name ) ) ),
						esc_html( size_format( wp_max_upload_size() ) )
					)
				);
			}

			$files[] = array(
				'name'     => sanitize_file_name( wp_unslash( $name ) ),
				'tmp_name' => (string) ( (array) $raw['tmp_name'] )[ $index ],
				'size'     => (int) ( (array) $raw['size'] )[ $index ],
				'error'    => $error,
				'type'     => (string) ( (array) $raw['type'] )[ $index ],
			);
		}

		if ( ! $files ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'No valid file was sent.', 'jim-conversor-acessivel' ) );
		}

		/*
		 * PHP accepts only `max_file_uploads` files per request (20 by default)
		 * and silently drops the rest. The form says how many files the browser
		 * sent; if fewer arrived, the upload is refused so nobody believes
		 * everything was converted.
		 */
		if ( $expected > count( $files ) ) {
			throw new JIMCA_Converter_Exception(
				sprintf(
					/* translators: 1: files chosen, 2: files received, 3: PHP limit */
					esc_html__( 'You chose %1$d files, but only %2$d reached the server (PHP limit "max_file_uploads": %3$d). Nothing was converted; send them in smaller batches.', 'jim-conversor-acessivel' ),
					absint( $expected ),
					absint( count( $files ) ),
					absint( ini_get( 'max_file_uploads' ) )
				)
			);
		}

		return $files;
	}

	/**
	 * Refuses, with an explanation, the extensions the plugin does not convert.
	 *
	 * @param string $filename
	 * @throws JIMCA_Converter_Exception
	 */
	private function assert_supported_extension( $filename ) {
		$extension = self::extension_of( $filename );

		if ( in_array( $extension, array( 'pdf', 'docx', 'txt', 'md' ), true ) ) {
			return;
		}

		$hints = array(
			'doc'  => __( 'The old Word format (.doc) is not supported. Open the file in Word or LibreOffice and use Save as › .docx.', 'jim-conversor-acessivel' ),
			'odt'  => __( 'LibreOffice documents (.odt) are not supported. Use Save as › .docx (or export to PDF).', 'jim-conversor-acessivel' ),
			'rtf'  => __( 'The RTF format is not supported. Open the file in Word or LibreOffice and use Save as › .docx.', 'jim-conversor-acessivel' ),
			'xls'  => __( 'Spreadsheets are not converted. Export the content to PDF or DOCX.', 'jim-conversor-acessivel' ),
			'xlsx' => __( 'Spreadsheets are not converted. Export the content to PDF or DOCX.', 'jim-conversor-acessivel' ),
			'ppt'  => __( 'Presentations are not converted. Export to PDF.', 'jim-conversor-acessivel' ),
			'pptx' => __( 'Presentations are not converted. Export to PDF.', 'jim-conversor-acessivel' ),
			'epub' => __( 'EPUB books are not supported. Convert them to PDF or DOCX.', 'jim-conversor-acessivel' ),
			'html' => __( 'HTML pages are not supported. Save the text as TXT or Markdown.', 'jim-conversor-acessivel' ),
			'htm'  => __( 'HTML pages are not supported. Save the text as TXT or Markdown.', 'jim-conversor-acessivel' ),
		);

		$image_types = array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tif', 'tiff' );

		if ( isset( $hints[ $extension ] ) ) {
			$hint = $hints[ $extension ];
		} elseif ( in_array( $extension, $image_types, true ) ) {
			$hint = __( 'Loose images are not converted: the plugin converts text. For a scanned PDF, run OCR first.', 'jim-conversor-acessivel' );
		} else {
			$hint = __( 'Accepted formats: PDF, DOCX, TXT and MD (Markdown).', 'jim-conversor-acessivel' );
		}

		throw new JIMCA_Converter_Exception(
			sprintf(
				/* translators: 1: file name, 2: explanation */
				esc_html__( 'Could not convert "%1$s". %2$s', 'jim-conversor-acessivel' ),
				esc_html( $filename ),
				esc_html( $hint )
			)
		);
	}

	/**
	 * Lowercase extension; "markdown" counts as "md".
	 *
	 * @param string $filename
	 * @return string
	 */
	private static function extension_of( $filename ) {
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		return ( 'markdown' === $extension ) ? 'md' : $extension;
	}

	/**
	 * Converts an uploaded file and creates the document.
	 *
	 * @param array  $file         name, tmp_name, size (see collect_uploaded_files()).
	 * @param string $title        Title; empty = file name without extension.
	 * @param bool   $remove_pages PDF: leave out the "Page N" markers.
	 * @return int ID of the document.
	 * @throws JIMCA_Converter_Exception
	 */
	private function process_file( array $file, $title, $remove_pages ) {
		return $this->convert_received( $this->receive_file( $file ), $title, $remove_pages );
	}

	/**
	 * Checks an uploaded file (size, extension, content, allowed types) and
	 * moves it to the plugin folder in uploads/.
	 *
	 * @param array $file One item of collect_uploaded_files().
	 * @return array{file: string, url: string, ext: string, name: string, size: int}
	 * @throws JIMCA_Converter_Exception
	 */
	public function receive_file( array $file ) {
		$settings  = self::get_settings();
		$max_bytes = self::get_effective_max_bytes();

		if ( 0 === (int) $file['size'] ) {
			throw new JIMCA_Converter_Exception(
				sprintf(
					/* translators: %s: file name */
					esc_html__( 'The file "%s" is empty (0 bytes).', 'jim-conversor-acessivel' ),
					esc_html( $file['name'] )
				)
			);
		}

		$this->assert_supported_extension( $file['name'] );

		if ( $file['size'] > $max_bytes ) {
			throw new JIMCA_Converter_Exception(
				sprintf(
					/* translators: %s: formatted maximum size (e.g. "10 MB") */
					esc_html__( 'File larger than the limit of %s.', 'jim-conversor-acessivel' ),
					esc_html( size_format( $max_bytes ) )
				)
			);
		}

		$upload_args = array( 'test_form' => false );

		if ( 'md' === self::extension_of( $file['name'] ) ) {
			/*
			 * WordPress does not know .md, and for text files the content check
			 * returns "text/plain", which does not match "text/markdown". So the
			 * type is checked here: allowed extension and content that looks
			 * like text (no null bytes).
			 */
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temporary upload file.
			$head = (string) file_get_contents( $file['tmp_name'], false, null, 0, 8192 );

			if ( false !== strpos( $head, "\0" ) ) {
				throw new JIMCA_Converter_Exception( esc_html__( 'The .md file does not look like valid Markdown text.', 'jim-conversor-acessivel' ) );
			}

			$filetype              = array( 'ext' => 'md', 'type' => 'text/markdown' );
			$upload_args['test_type'] = false;
			$upload_args['mimes']     = array( 'md|markdown' => 'text/markdown' );
		} else {
			$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		}

		if ( empty( $filetype['ext'] ) ) {
			// The extension is accepted, but the content does not match it (renamed or corrupted file).
			throw new JIMCA_Converter_Exception(
				sprintf(
					/* translators: 1: file name, 2: extension */
					esc_html__( 'The content of "%1$s" does not match the .%2$s extension: the file is corrupted or was renamed from another format.', 'jim-conversor-acessivel' ),
					esc_html( $file['name'] ),
					esc_html( self::extension_of( $file['name'] ) )
				)
			);
		}

		if ( ! in_array( $filetype['ext'], $settings['allowed_types'], true ) ) {
			throw new JIMCA_Converter_Exception(
				sprintf(
					/* translators: %s: extension */
					esc_html__( '.%s files are turned off in Jim › Settings › Allowed file types.', 'jim-conversor-acessivel' ),
					esc_html( $filetype['ext'] )
				)
			);
		}

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
		$moved = wp_handle_upload( $file, $upload_args );
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		if ( isset( $moved['error'] ) ) {
			throw new JIMCA_Converter_Exception( esc_html( $moved['error'] ) );
		}

		$this->protect_upload_dir( dirname( $moved['file'] ) );

		return array(
			'file' => $moved['file'],
			'url'  => $moved['url'],
			'ext'  => $filetype['ext'],
			'name' => $file['name'],
			'size' => (int) $file['size'],
		);
	}

	/**
	 * Converts an already received file and creates the document.
	 *
	 * @param array         $received     receive_file() result.
	 * @param string        $title        Title; empty = file name.
	 * @param bool          $remove_pages Leave out the "Page N" markers (PDF).
	 * @param callable|null $progress     Gets (stage, done, total, images) during the conversion.
	 * @return int ID of the document.
	 * @throws JIMCA_Converter_Exception
	 */
	public function convert_received( array $received, $title, $remove_pages, $progress = null ) {
		$settings  = self::get_settings();
		$extension = $received['ext'];
		$html      = '';
		$started   = microtime( true );

		self::log_start( $received );

		/*
		 * Reading a large PDF is the heaviest part: the libraries build the
		 * whole file structure in memory (embedded fonts, compressed streams,
		 * cross-reference tables), which costs far more than the extracted
		 * text. This asks WordPress for the higher limit it already plans for
		 * admin tasks (WP_MAX_MEMORY_LIMIT, 256 MB by default); without it the
		 * process can die mid-conversion without even throwing an exception.
		 */
		JIMCA_Limits::raise_for_conversion();

		$image_store = null;

		try {
			if ( ! empty( $settings['include_images'] ) ) {
				$image_store = new JIMCA_Image_Store();
			}

			$this->image_store_in_progress = $image_store;

			$html = JIMCA_Converter::convert(
				$received['file'],
				$extension,
				$image_store,
				array(
					'page_markers' => ! $remove_pages,
					'progress'     => $progress,
				)
			);
		} catch ( \Throwable $e ) {
			// Aborted conversion: do not leave behind images of a document that will not exist.
			if ( $image_store ) {
				$image_store->discard();
			}
			throw $e;
		} finally {
			self::cleanup_original( $received );
		}

		return $this->create_document( $received, $html, $image_store, $title, $started );
	}

	/**
	 * @param array $received receive_file() result.
	 */
	public static function log_start( array $received ) {
		JIMCA_Log::add(
			'info',
			'upload.start',
			sprintf( 'Converting "%s".', sanitize_file_name( $received['name'] ) ),
			array(
				'type'    => $received['ext'],
				'size_mb' => round( (int) $received['size'] / MB_IN_BYTES, 2 ),
			)
		);
	}

	/**
	 * Deletes the uploaded original when Settings say so.
	 *
	 * @param array $received receive_file() result.
	 */
	public static function cleanup_original( array $received ) {
		$settings = self::get_settings();

		if ( ! empty( $settings['delete_original_after'] ) && file_exists( $received['file'] ) ) {
			wp_delete_file( $received['file'] );
		}
	}

	/**
	 * Creates the document from converted HTML: checks there is text, saves
	 * the post, its images and details, and logs the result.
	 *
	 * @param array                  $received    receive_file() result.
	 * @param string                 $html        Sanitized HTML.
	 * @param JIMCA_Image_Store|null $image_store
	 * @param string                 $title       Title; empty = file name.
	 * @param float                  $started     microtime( true ) when the conversion started.
	 * @return int ID of the document.
	 * @throws JIMCA_Converter_Exception
	 */
	public function create_document( array $received, $html, $image_store, $title, $started ) {
		$settings  = self::get_settings();
		$extension = $received['ext'];
		$file      = array(
			'name' => $received['name'],
			'size' => $received['size'],
		);
		$moved     = array(
			'file' => $received['file'],
			'url'  => $received['url'],
		);

		if ( '' === $title ) {
			$title = sanitize_text_field( pathinfo( $file['name'], PATHINFO_FILENAME ) );
		}

		/*
		 * Without a third argument, preg_match_all() returns the count without
		 * building an array of every word, which costs megabytes of memory on a
		 * book.
		 */
		$word_count = (int) preg_match_all( '/\p{L}+/u', wp_strip_all_tags( $html ) );

		if ( 0 === $word_count ) {
			// A document with no text has nothing to read, on screen or aloud: an error, not a success.
			if ( $image_store ) {
				$image_store->discard();
			}

			throw new JIMCA_Converter_Exception(
				'pdf' === $extension
					? esc_html__( 'This PDF has no selectable text: it is probably a scanned (image-only) document. The plugin does not do OCR. Run OCR on the file (for example in Adobe Acrobat or Google Drive) and upload the PDF with text.', 'jim-conversor-acessivel' )
					: esc_html__( 'The document has no text to convert.', 'jim-conversor-acessivel' )
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => JIMCA_Post_Type::POST_TYPE,
				'post_title'   => $title,
				'post_content' => $html,
				'post_status'  => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			if ( $image_store ) {
				$image_store->discard();
			}
			throw new JIMCA_Converter_Exception( esc_html( $post_id->get_error_message() ) );
		}

		if ( $image_store ) {
			// The attachments now belong to the document: that is how they get deleted with it.
			$image_store->attach_to( $post_id );

			// Image report (included, left out and why): notice and document box.
			update_post_meta( $post_id, '_jimca_image_report', $image_store->get_report() );
		}

		update_post_meta( $post_id, '_jimca_original_filename', sanitize_file_name( $file['name'] ) );
		update_post_meta( $post_id, '_jimca_original_type', $extension );
		update_post_meta( $post_id, '_jimca_word_count', $word_count );

		if ( JIMCA_Converter::looks_unreadable( $html ) ) {
			update_post_meta( $post_id, '_jimca_unreadable', 1 );
			JIMCA_Log::add( 'warning', 'upload.unreadable', sprintf( 'The text of document #%d looks unreadable (font without Unicode mapping).', $post_id ) );
		}
		update_post_meta( $post_id, '_jimca_converted_at', current_time( 'mysql' ) );

		$report  = $image_store ? $image_store->get_report() : array();
		$skipped = isset( $report['skipped'] ) ? (int) $report['skipped'] : 0;

		JIMCA_Log::add(
			$skipped || ! empty( $report['stopped'] ) ? 'warning' : 'info',
			'upload.done',
			sprintf( 'Converted "%s" into document #%d.', sanitize_file_name( $file['name'] ), $post_id ),
			array_merge(
				JIMCA_Log::metrics( $started ),
				array(
					'type'           => $extension,
					'words'          => $word_count,
					'images'         => isset( $report['included'] ) ? (int) $report['included'] : 0,
					'images_no_alt'  => isset( $report['missing_alt'] ) ? (int) $report['missing_alt'] : 0,
					'images_skipped' => $skipped,
					'stopped_page'   => ! empty( $report['stopped']['page'] ) ? (int) $report['stopped']['page'] : 0,
					'stopped_reason' => ! empty( $report['stopped']['reason'] ) ? (string) $report['stopped']['reason'] : '',
				)
			)
		);

		if ( ! empty( $report['reasons'] ) && is_array( $report['reasons'] ) ) {
			foreach ( $report['reasons'] as $code => $count ) {
				JIMCA_Log::add(
					'warning',
					'upload.images_skipped',
					sprintf( '%d image(s) left out of document #%d: %s', (int) $count, $post_id, (string) $code ),
					array(
						'reason' => (string) $code,
						'count'  => (int) $count,
					)
				);
			}
		}

		if ( empty( $settings['delete_original_after'] ) ) {
			update_post_meta( $post_id, '_jimca_original_url', esc_url_raw( $moved['url'] ) );
		}

		$this->image_store_in_progress = null;

		return $post_id;
	}

	/**
	 * Defense in depth: only checked PDF/DOCX/TXT/MD files reach this folder,
	 * but a .htaccess denies EXECUTING any script there (not reading files:
	 * the original file must stay reachable when "delete original" is off).
	 * Covers another plugin or setting allowing a dangerous upload one day.
	 * It only works where the server honors .htaccess (Apache, LiteSpeed; not
	 * nginx). index.php prevents directory listing.
	 */
	private function protect_upload_dir( $dir ) {
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents(
				$htaccess,
				"<IfModule mod_authz_core.c>\n" .
				"    <FilesMatch \"\\.(php\\d?|phtml|pl|py|cgi|sh|asp|aspx)$\">\n" .
				"        Require all denied\n" .
				"    </FilesMatch>\n" .
				"</IfModule>\n" .
				"<IfModule !mod_authz_core.c>\n" .
				"    <FilesMatch \"\\.(php\\d?|phtml|pl|py|cgi|sh|asp|aspx)$\">\n" .
				"        Order allow,deny\n" .
				"        Deny from all\n" .
				"    </FilesMatch>\n" .
				"</IfModule>\n" .
				"Options -Indexes -ExecCGI\n"
			);
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	public function filter_upload_dir( $dirs ) {
		$dirs['subdir'] = '/jimca-documentos' . $dirs['subdir'];
		$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	}

	public function render_admin_notices() {
		$key    = 'jimca_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice ) {
			return;
		}

		delete_transient( $key );

		$classes = array(
			'error'   => 'notice-error',
			'warning' => 'notice-warning',
		);
		$class   = isset( $classes[ $notice['type'] ] ) ? $classes[ $notice['type'] ] : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . wp_kses_post( $notice['message'] ) . '</p>';

		if ( ! empty( $notice['details'] ) ) {
			echo wp_kses_post( $notice['details'] );
		}

		if ( ! empty( $notice['shortcodes'] ) ) {
			echo '<p><label for="jimca-shortcode-list"><strong>' . esc_html__( 'List of shortcodes (one per line, ready to copy):', 'jim-conversor-acessivel' ) . '</strong></label><br>';
			echo '<textarea id="jimca-shortcode-list" class="large-text code" rows="' . esc_attr( min( 12, count( $notice['shortcodes'] ) + 1 ) ) . '" readonly onclick="this.select();">' . esc_textarea( implode( "\n", array_map( 'strval', $notice['shortcodes'] ) ) ) . '</textarea></p>';
		}

		echo '</div>';
	}

	/**
	 * Plugin header above the native "All Documents" list. No page title is
	 * added: WordPress's own <h1> already plays that role there.
	 */
	public function render_documents_list_banner() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . JIMCA_Post_Type::POST_TYPE !== $screen->id ) {
			return;
		}

		$this->render_admin_header( false );
	}

	/* ---------------------------------------------------------------
	 * Tutorial
	 * ------------------------------------------------------------- */

	/**
	 * Introduction page: what the plugin is, how to use it, what each
	 * feature does, where to look when something goes wrong, which version is
	 * installed and who makes it.
	 */
	public function render_tutorial_page() {
		if ( ! current_user_can( self::CAPABILITY_UPLOAD ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'jim-conversor-acessivel' ) );
		}

		$upload_url    = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$documents_url = admin_url( 'edit.php?post_type=' . JIMCA_Post_Type::POST_TYPE );
		$settings_url  = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' );
		?>
		<div class="wrap">
			<?php
			$this->render_admin_header();
			$this->render_page_title( __( 'Tutorial', 'jim-conversor-acessivel' ) );
			?>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'What Jim is', 'jim-conversor-acessivel' ); ?></h2>
				<p>
					<?php esc_html_e( 'Jim turns a PDF, Word (.docx), Markdown or TXT file into an accessible reading page inside your site. Instead of offering a file to download, the content becomes real text on the page: screen reader users can read it, people who need larger letters can enlarge them, and people who prefer listening get reading aloud from the browser itself.', 'jim-conversor-acessivel' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Each converted document is stored in your WordPress and gets a shortcode that you paste into any page or post to publish it.', 'jim-conversor-acessivel' ); ?>
				</p>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'Step by step', 'jim-conversor-acessivel' ); ?></h2>
				<ol class="jimca-admin-steps">
					<li>
						<?php
						printf(
							/* translators: %s: link to the upload screen */
							esc_html__( 'Open %s and choose the file (PDF, DOCX, TXT or Markdown).', 'jim-conversor-acessivel' ),
							'<a href="' . esc_url( $upload_url ) . '"><strong>' . esc_html__( 'New Document', 'jim-conversor-acessivel' ) . '</strong></a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Give the document a title (optional) and click "Convert document". Large files can take up to about a minute; do not close or refresh the page.', 'jim-conversor-acessivel' ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %s: link to the documents list */
							esc_html__( 'When it finishes, the document appears in %s, with its shortcode in the list.', 'jim-conversor-acessivel' ),
							'<a href="' . esc_url( $documents_url ) . '"><strong>' . esc_html__( 'Documents', 'jim-conversor-acessivel' ) . '</strong></a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Copy the shortcode and paste it into the page or post where the document should appear.', 'jim-conversor-acessivel' ); ?></li>
				</ol>

				<h3><?php esc_html_e( 'Available shortcodes', 'jim-conversor-acessivel' ); ?></h3>
				<table class="widefat striped jimca-admin-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Shortcode', 'jim-conversor-acessivel' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What it does', 'jim-conversor-acessivel' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>[documento_acessivel id="123"]</code></td>
							<td><?php esc_html_e( 'Publishes the converted document, with the reading controls.', 'jim-conversor-acessivel' ); ?></td>
						</tr>
						<tr>
							<td><code>[jimca_instalacoes]</code></td>
							<td><?php esc_html_e( 'Shows how many active installs the plugin has, according to the WordPress.org API.', 'jim-conversor-acessivel' ); ?></td>
						</tr>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'The shortcode names are kept in Portuguese so that pages published with earlier versions keep working.', 'jim-conversor-acessivel' ); ?></p>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'Formats and sending several files', 'jim-conversor-acessivel' ); ?></h2>
				<ul class="jimca-admin-list">
					<li><?php esc_html_e( 'PDF (with selectable text; scanned, image-only PDFs have no text and are refused: there is no OCR), DOCX, TXT and Markdown (.md).', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'TXT and Markdown files can be sent up to 20 at a time. Each one becomes a document, and you get a ready-to-copy list of shortcodes. PDF and DOCX are sent one at a time because they are heavy to convert.', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'Old .doc, .odt, .rtf, spreadsheets, presentations and EPUB are not converted. The error message tells you what to do (for example, save as .docx).', 'jim-conversor-acessivel' ); ?></li>
				</ul>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'Images', 'jim-conversor-acessivel' ); ?></h2>
				<ul class="jimca-admin-list">
					<li><?php esc_html_e( 'Images from PDF and DOCX files go to the Media Library, linked to the document, and are deleted with it.', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'Descriptions (alt text) written in Word are kept. Images without one get a temporary text: edit the document and describe them, so screen reader users know what they show.', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'Large illustrated books can exhaust the server, so there are limits on the number and total size of images, plus time and memory guards. When one is reached, the whole text is still converted, and the notice says which images were left out and why.', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'Readers can click an image to open it larger, with arrow keys to move between images and Esc to close.', 'jim-conversor-acessivel' ); ?></li>
				</ul>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'Reader mode and blog post mode', 'jim-conversor-acessivel' ); ?></h2>
				<p>
					<?php esc_html_e( 'Each document is shown either as a reader (floating reading bar with voice and themes) or as a blog post (a reading-time bar with listen and share buttons). The mode is chosen only in the documents list, with Quick Edit or the "Display mode" column, so whoever publishes the page cannot change what the document is. Without a choice, the document is a reader.', 'jim-conversor-acessivel' ); ?>
				</p>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'Reading controls', 'jim-conversor-acessivel' ); ?></h2>
				<p><?php esc_html_e( 'On the published page, a floating bar follows the reader while they scroll the document:', 'jim-conversor-acessivel' ); ?></p>
				<ul class="jimca-admin-list">
					<li><?php esc_html_e( 'Text size and reading theme (Light, Sepia or Dark).', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'A side table of contents with the document headings (Word heading styles or PDF bookmarks), to jump straight to a chapter.', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'High contrast (black background, white text and yellow highlights), which works from any theme.', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'Voice choice, listen/pause, speed and stop. Reading aloud uses the browser, so no text is sent to anyone.', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'A highlighter: select a passage and pick one of four colors. Highlights are saved only in that reader\'s browser.', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'Hiding the bar, for readers who want the whole screen for the text.', 'jim-conversor-acessivel' ); ?></li>
				</ul>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to the settings */
						esc_html__( 'The site default theme and the initial reading speed are in %s. Each reader can still change them on the spot, and their choice only applies to their own browser.', 'jim-conversor-acessivel' ),
						'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'jim-conversor-acessivel' ) . '</a>'
					);
					?>
				</p>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'SEO', 'jim-conversor-acessivel' ); ?></h2>
				<p><?php esc_html_e( 'The page that contains the shortcode gets a meta description, Open Graph, Twitter Card and JSON-LD tags, built from the title and the beginning of the document. If Yoast SEO, Rank Math, All in One SEO or SEOPress is active, Jim writes nothing, to avoid duplicates. You can turn this off in Settings.', 'jim-conversor-acessivel' ); ?></p>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'AI assistant', 'jim-conversor-acessivel' ); ?></h2>
				<p><?php esc_html_e( 'In Settings, save an API key (stored encrypted) for OpenRouter, Claude (Anthropic), OpenAI, Gemini (Google) or DeepSeek, choose the model and test the connection. Then you can turn on the AI tutor: a chat bubble on the document page where readers ask questions about that document, by text or voice. You set its name, personality, instructions and greeting, where it appears and how many questions it answers. Each question, with passages of the document, is sent to the provider you chose.', 'jim-conversor-acessivel' ); ?></p>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'When something goes wrong', 'jim-conversor-acessivel' ); ?></h2>
				<ul class="jimca-admin-list">
					<li>
						<?php
						printf(
							/* translators: %s: link to the settings */
							esc_html__( 'Open the "Activity log" and the "Server requirements" table in %s. The log shows each conversion (time, memory, words, images and the cause of each image left out) and every error, live: keep Settings open in another tab while a conversion runs.', 'jim-conversor-acessivel' ),
							'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'jim-conversor-acessivel' ) . '</a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Most failures with big files are server limits (memory, execution time, upload size). The requirements table shows which one is short.', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'The log never contains the text of your documents or your AI key, so it is safe to export and send when asking for help.', 'jim-conversor-acessivel' ); ?></li>
				</ul>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'Privacy', 'jim-conversor-acessivel' ); ?></h2>
				<p><?php esc_html_e( 'Jim does not track visitors, sets no cookies and loads nothing from other sites. Reader choices and highlights stay in the reader\'s browser. The only outside contacts are api.wordpress.org (install counter, only if you use that shortcode) and the AI provider you choose (OpenRouter, Anthropic, OpenAI, Google or DeepSeek), when you test the connection or when readers use the tutor. Suggested policy text is available in Settings > Privacy > Policy Guide.', 'jim-conversor-acessivel' ); ?></p>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'Version and updates', 'jim-conversor-acessivel' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: installed version number */
						esc_html__( 'Installed version: %s.', 'jim-conversor-acessivel' ),
						'<strong>' . esc_html( JIMCA_VERSION ) . '</strong>'
					);
					?>
				</p>
				<p><?php esc_html_e( 'Once the plugin is published in the official WordPress directory, updates arrive on your site\'s Plugins screen like for any other plugin. Until then, each new version is published in the project repository.', 'jim-conversor-acessivel' ); ?></p>
			</div>

			<div class="jimca-admin-card jimca-admin-card--author">
				<h2><?php esc_html_e( 'Authorship', 'jim-conversor-acessivel' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: author name */
						esc_html__( 'Developed by %s. Free software, under the GPL v2 or later license.', 'jim-conversor-acessivel' ),
						'<strong><a href="' . esc_url( JIMCA_AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">Mayara Nascimento</a></strong>'
					);
					?>
				</p>
				<h3><?php esc_html_e( 'Support the project', 'jim-conversor-acessivel' ); ?></h3>
				<p><?php esc_html_e( 'If this project was useful to you, please consider giving the repository a star on GitHub! It helps the project grow and reach more developers.', 'jim-conversor-acessivel' ); ?></p>
				<p class="jimca-admin-links">
					<a class="button" href="<?php echo esc_url( JIMCA_REPO_URL ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Visit the repository', 'jim-conversor-acessivel' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( JIMCA_SITE_URL ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Plugin website', 'jim-conversor-acessivel' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( JIMCA_REPO_URL . '/issues' ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Report a problem', 'jim-conversor-acessivel' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------- */

	public function register_settings() {

		register_setting( 'jimca_settings_group', self::SETTINGS_OPTION, array( $this, 'sanitize_settings' ) );
		register_setting(
			'jimca_tutor_group',
			JIMCA_Tutor::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'JIMCA_Tutor', 'sanitize' ),
				'default'           => array(),
			)
		);
		register_setting(
			'jimca_ai_group',
			JIMCA_AI::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'JIMCA_AI', 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$defaults = JIMCA_Activator::default_settings();
		$output   = array();

		$allowed_types_input     = isset( $input['allowed_types'] ) ? (array) $input['allowed_types'] : array();
		$output['allowed_types'] = array_values( array_intersect( array( 'pdf', 'docx', 'txt', 'md' ), $allowed_types_input ) );
		if ( empty( $output['allowed_types'] ) ) {
			$output['allowed_types'] = $defaults['allowed_types'];
		}

		$output['max_file_size_mb']      = max( 1, min( 100, (int) ( $input['max_file_size_mb'] ?? $defaults['max_file_size_mb'] ) ) );
		$output['delete_original_after'] = ! empty( $input['delete_original_after'] );
		$output['include_images']        = ! empty( $input['include_images'] );
		$output['seo_enabled']           = ! empty( $input['seo_enabled'] );
		$output['max_images']            = max( 1, min( JIMCA_Image_Store::MAX_IMAGES, (int) ( $input['max_images'] ?? $defaults['max_images'] ) ) );
		$output['max_images_mb']         = max( 1, min( 500, (int) ( $input['max_images_mb'] ?? $defaults['max_images_mb'] ) ) );
		$output['settings_version']      = 3;
		// The WordPress.org slug is no longer an editable setting (the [jimca_instalacoes] shortcode uses it internally).
		$output['wporg_slug'] = $defaults['wporg_slug'];

		$rate                       = isset( $input['tts_default_rate'] ) ? (float) $input['tts_default_rate'] : $defaults['tts_default_rate'];
		$output['tts_default_rate'] = max( 0.5, min( 2, $rate ) );

		$valid_themes    = array_keys( self::get_available_themes() );
		$output['theme'] = ( isset( $input['theme'] ) && in_array( $input['theme'], $valid_themes, true ) )
			? $input['theme']
			: $defaults['theme'];

		return $output;
	}

	public function render_settings_page() {
		if ( ! current_user_can( self::CAPABILITY_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'jim-conversor-acessivel' ) );
		}

		$settings = self::get_settings();
		?>
		<div class="wrap">
			<?php
			$this->render_admin_header();
			$this->render_page_title( __( 'Settings', 'jim-conversor-acessivel' ) );
			?>

			<div class="jimca-admin-card">
			<form method="post" action="options.php">
				<?php settings_fields( 'jimca_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Allowed file types', 'jim-conversor-acessivel' ); ?></th>
						<td>
							<?php foreach ( array( 'pdf', 'docx', 'txt', 'md' ) as $type ) : ?>
								<label style="margin-right:1em;">
									<input type="checkbox" name="jimca_settings[allowed_types][]" value="<?php echo esc_attr( $type ); ?>"
										<?php checked( in_array( $type, $settings['allowed_types'], true ) ); ?>>
									<?php echo esc_html( strtoupper( $type ) ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jimca_max_size"><?php esc_html_e( 'Maximum size (MB)', 'jim-conversor-acessivel' ); ?></label></th>
						<td><input type="number" id="jimca_max_size" name="jimca_settings[max_file_size_mb]" min="1" max="100" value="<?php echo esc_attr( $settings['max_file_size_mb'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Delete the original file after converting', 'jim-conversor-acessivel' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jimca_settings[delete_original_after]" value="1" <?php checked( ! empty( $settings['delete_original_after'] ) ); ?>>
								<?php esc_html_e( 'Recommended: keeps only the converted HTML, without storing the uploaded file.', 'jim-conversor-acessivel' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Document images', 'jim-conversor-acessivel' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jimca_settings[include_images]" value="1" <?php checked( ! empty( $settings['include_images'] ) ); ?>>
								<?php esc_html_e( 'Include the images from the PDF or DOCX in the converted document.', 'jim-conversor-acessivel' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Descriptions (alt text) written in Word are kept. Images without a description, such as those from PDFs, get a temporary text and should be described by editing the document. Applies to future conversions.', 'jim-conversor-acessivel' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic SEO', 'jim-conversor-acessivel' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jimca_settings[seo_enabled]" value="1" <?php checked( ! empty( $settings['seo_enabled'] ) ); ?>>
								<?php esc_html_e( 'Generate description, Open Graph, Twitter Card and structured data (JSON-LD) on pages that publish a document.', 'jim-conversor-acessivel' ); ?>
							</label>
							<p class="description">
								<?php
								echo esc_html(
									JIMCA_SEO::other_seo_plugin_active()
										? __( 'Another SEO plugin is active on this site (Yoast, Rank Math, All in One SEO or SEOPress): Jim writes no tags, to avoid duplicating theirs, even with this option on.', 'jim-conversor-acessivel' )
										: __( 'The tags go in the head of the page where the shortcode is, built from the title and the beginning of the document. If you install an SEO plugin, Jim turns itself off on those pages.', 'jim-conversor-acessivel' )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jimca_max_images"><?php esc_html_e( 'Image limits', 'jim-conversor-acessivel' ); ?></label></th>
						<td>
							<p>
								<label for="jimca_max_images"><?php esc_html_e( 'Maximum images per document:', 'jim-conversor-acessivel' ); ?></label>
								<input type="number" id="jimca_max_images" name="jimca_settings[max_images]" min="1" max="<?php echo esc_attr( JIMCA_Image_Store::MAX_IMAGES ); ?>" value="<?php echo esc_attr( $settings['max_images'] ); ?>">
							</p>
							<p>
								<label for="jimca_max_images_mb"><?php esc_html_e( 'Maximum total size of images (MB):', 'jim-conversor-acessivel' ); ?></label>
								<input type="number" id="jimca_max_images_mb" name="jimca_settings[max_images_mb]" min="1" max="500" value="<?php echo esc_attr( $settings['max_images_mb'] ); ?>">
							</p>
							<p class="description"><?php esc_html_e( 'Each image is decoded, saved to the Media Library and gets thumbnails: books with many figures can exhaust PHP\'s memory or time and take the server down. When any limit is reached (or PHP\'s time or memory is close to running out), image extraction stops, the whole text is still converted and the notice explains why. On shared hosting, keep low values.', 'jim-conversor-acessivel' ); ?></p>
							<p class="description">
								<?php
								if ( JIMCA_Pdf_Image_Extractor::can_decode_jpx() ) {
									esc_html_e( 'JPEG 2000 images (common in PDFs of books): supported on this server.', 'jim-conversor-acessivel' );
								} else {
									esc_html_e( 'JPEG 2000 images (common in PDFs of books): not supported on this server, so they are left out. They are converted when PHP has the Imagick extension with JPEG 2000 support; ask your host.', 'jim-conversor-acessivel' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jimca_tts_rate"><?php esc_html_e( 'Default reading-aloud speed', 'jim-conversor-acessivel' ); ?></label></th>
						<td><input type="number" id="jimca_tts_rate" name="jimca_settings[tts_default_rate]" min="0.5" max="2" step="0.1" value="<?php echo esc_attr( $settings['tts_default_rate'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="jimca_theme"><?php esc_html_e( 'Default reading theme', 'jim-conversor-acessivel' ); ?></label></th>
						<td>
							<select id="jimca_theme" name="jimca_settings[theme]">
								<?php foreach ( self::get_available_themes() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['theme'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Applied to all converted documents. Each reader can still change the theme on the spot, from the reading bar; their choice only applies to their own browser and does not change this default.', 'jim-conversor-acessivel' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			</div>

			<?php $this->render_ai_card(); ?>
			<?php $this->render_tutor_card(); ?>

			<?php $this->render_log_card(); ?>

			<?php $this->render_languages_card(); ?>

			<?php $this->render_server_check(); ?>
		</div>
		<?php
	}

	/**
	 * "AI assistant" card: one key and model per provider (stored encrypted),
	 * the active provider and a connection test for each.
	 *
	 * The test buttons belong to small forms printed after the settings form
	 * (HTML "form" attribute), because forms cannot be nested.
	 */
	private function render_ai_card() {
		$ai = JIMCA_AI::get_settings();
		?>
		<div class="jimca-admin-card" id="jimca-ai">
			<h3><?php esc_html_e( 'AI assistant (API keys)', 'jim-conversor-acessivel' ); ?></h3>
			<?php settings_errors( JIMCA_AI::OPTION ); ?>
			<p class="description">
				<?php esc_html_e( 'One API key per provider, stored encrypted. A connection test sends one fixed sentence to that provider only, with no document text. The AI tutor (below) uses the provider chosen here.', 'jim-conversor-acessivel' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'jimca_ai_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="jimca_ai_provider"><?php esc_html_e( 'Provider used by the assistant', 'jim-conversor-acessivel' ); ?></label></th>
						<td>
							<select id="jimca_ai_provider" name="jimca_ai[provider]">
								<?php foreach ( JIMCA_AI::providers() as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $ai['provider'], $code ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Assistant', 'jim-conversor-acessivel' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jimca_ai[enabled]" value="1" <?php checked( $ai['enabled'] ); ?>>
								<?php esc_html_e( 'Mark the assistant as on (only works with a saved key for the provider above).', 'jim-conversor-acessivel' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php
				foreach ( JIMCA_AI::provider_specs() as $code => $spec ) :
					$entry    = $ai['providers'][ $code ];
					$custom   = 'custom' === $code;
					$has_key  = '' !== $entry['key'];
					$testable = $custom ? '' !== $entry['endpoint'] && '' !== $entry['model'] : $has_key;
					$id       = 'jimca_ai_' . $code;
					$name     = 'jimca_ai[providers][' . $code . ']';
					?>
					<fieldset class="jimca-ai-provider">
						<legend><?php echo esc_html( $spec['label'] ); ?></legend>
						<?php if ( $custom ) : ?>
							<p class="description"><?php esc_html_e( 'Any service that uses the OpenAI Chat Completions format: open-source models on your own server (Ollama, vLLM, LM Studio, LocalAI, llama.cpp, Hugging Face TGI) or an AI gateway (LiteLLM, Portkey, Cloudflare AI Gateway and others). With a model on your own server, questions and passages do not leave it.', 'jim-conversor-acessivel' ); ?></p>
						<?php endif; ?>
						<table class="form-table" role="presentation">
							<?php if ( $custom ) : ?>
								<tr>
									<th scope="row"><label for="<?php echo esc_attr( $id ); ?>_name"><?php esc_html_e( 'Name shown to readers', 'jim-conversor-acessivel' ); ?></label></th>
									<td><input type="text" id="<?php echo esc_attr( $id ); ?>_name" name="<?php echo esc_attr( $name ); ?>[name]" class="regular-text" maxlength="60" value="<?php echo esc_attr( $entry['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Llama on our server', 'jim-conversor-acessivel' ); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><label for="<?php echo esc_attr( $id ); ?>_endpoint"><?php esc_html_e( 'Address (URL)', 'jim-conversor-acessivel' ); ?></label></th>
									<td>
										<input type="url" id="<?php echo esc_attr( $id ); ?>_endpoint" name="<?php echo esc_attr( $name ); ?>[endpoint]" class="regular-text code" value="<?php echo esc_attr( $entry['endpoint'] ); ?>" placeholder="http://localhost:11434/v1" spellcheck="false" aria-describedby="<?php echo esc_attr( $id ); ?>_endpoint_help">
										<p class="description" id="<?php echo esc_attr( $id ); ?>_endpoint_help"><?php esc_html_e( 'The base address of the API, for example http://localhost:11434/v1 for Ollama. "/chat/completions" is added when missing.', 'jim-conversor-acessivel' ); ?></p>
									</td>
								</tr>
							<?php endif; ?>
							<tr>
								<th scope="row"><label for="<?php echo esc_attr( $id ); ?>_model"><?php esc_html_e( 'Model', 'jim-conversor-acessivel' ); ?></label></th>
								<td>
									<input type="text" id="<?php echo esc_attr( $id ); ?>_model" name="<?php echo esc_attr( $name ); ?>[model]" class="regular-text code" value="<?php echo esc_attr( $entry['model'] ); ?>" spellcheck="false" aria-describedby="<?php echo esc_attr( $id ); ?>_model_help">
									<p class="description" id="<?php echo esc_attr( $id ); ?>_model_help">
										<?php
										if ( $custom ) {
											esc_html_e( 'Required: the exact model name on that service, for example llama3.2 or qwen2.5:7b on Ollama.', 'jim-conversor-acessivel' );
										} else {
											/* translators: %s: default model name */
											printf( esc_html__( 'Default: %s. Leave blank to go back to it.', 'jim-conversor-acessivel' ), '<code>' . esc_html( $spec['model'] ) . '</code>' );
										}

										if ( 'openrouter' === $code ) {
											echo ' ' . esc_html__( 'Free models end in ":free". "openrouter/free" lets OpenRouter pick a free model that is available at the moment.', 'jim-conversor-acessivel' );
										}
										?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="<?php echo esc_attr( $id ); ?>_key"><?php echo $custom ? esc_html__( 'API key (optional)', 'jim-conversor-acessivel' ) : esc_html__( 'API key', 'jim-conversor-acessivel' ); ?></label></th>
								<td>
									<input type="password" id="<?php echo esc_attr( $id ); ?>_key" name="<?php echo esc_attr( $name ); ?>[api_key]" class="regular-text" value="" autocomplete="new-password" spellcheck="false" aria-describedby="<?php echo esc_attr( $id ); ?>_key_help">
									<p class="description" id="<?php echo esc_attr( $id ); ?>_key_help">
										<?php
										if ( $has_key ) {
											/* translators: %s: last 4 characters of the key */
											printf( esc_html__( 'A key is saved, ending in ••••%s. Leave blank to keep it, or type another to replace it.', 'jim-conversor-acessivel' ), esc_html( $entry['hint'] ) );

											if ( '' === JIMCA_AI::get_key( $code ) ) {
												echo ' <strong>' . esc_html__( 'Warning: the saved key could not be read (the security keys in wp-config.php changed). Type it again.', 'jim-conversor-acessivel' ) . '</strong>';
											}
										} elseif ( $custom ) {
											esc_html_e( 'Only if the service asks for one; a local Ollama does not. It is stored encrypted.', 'jim-conversor-acessivel' );
										} else {
											esc_html_e( 'Paste the key here. It is never shown again and is stored encrypted.', 'jim-conversor-acessivel' );
										}

										if ( '' !== $spec['keys_url'] ) {
											echo ' <a href="' . esc_url( $spec['keys_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Where to get a key', 'jim-conversor-acessivel' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'jim-conversor-acessivel' ) . '</span></a>';
										}
										?>
									</p>
									<?php if ( $has_key ) : ?>
										<p>
											<label>
												<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_key]" value="1">
												<?php esc_html_e( 'Delete the saved key when saving.', 'jim-conversor-acessivel' ); ?>
											</label>
										</p>
									<?php endif; ?>
									<?php if ( $testable ) : ?>
										<p>
											<button type="submit" class="button" form="jimca-ai-test-<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Test connection', 'jim-conversor-acessivel' ); ?></button>
											<span class="description">
												<?php
												/* translators: %s: provider address, e.g. api.anthropic.com */
												printf( esc_html__( 'Sends one fixed sentence to %s using the saved key and model.', 'jim-conversor-acessivel' ), '<code>' . esc_html( $spec['host'] ) . '</code>' );
												?>
											</span>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						</table>
					</fieldset>
				<?php endforeach; ?>

				<?php submit_button( __( 'Save keys and models', 'jim-conversor-acessivel' ), 'primary', 'submit', true, array( 'id' => 'jimca-ai-submit' ) ); ?>
			</form>

			<?php foreach ( array_keys( JIMCA_AI::provider_specs() ) as $code ) : ?>
				<?php
				$entry = $ai['providers'][ $code ];
				if ( 'custom' === $code ? '' === $entry['endpoint'] || '' === $entry['model'] : '' === $entry['key'] ) {
					continue;
				}
				?>
				<form id="jimca-ai-test-<?php echo esc_attr( $code ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( JIMCA_AI::TEST_ACTION ); ?>">
					<input type="hidden" name="provider" value="<?php echo esc_attr( $code ); ?>">
					<?php // One nonce input per form, without the fixed id="_wpnonce" that wp_nonce_field() would repeat. ?>
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( JIMCA_AI::TEST_NONCE ) ); ?>">
				</form>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * "AI tutor" card: the assistant readers talk to on the document page.
	 */
	private function render_tutor_card() {
		$tutor   = JIMCA_Tutor::get_settings();
		$ai      = JIMCA_AI::get_settings();
		$ready   = JIMCA_AI::is_active();
		$label   = JIMCA_AI::spec( $ai['provider'] )['label'];
		?>
		<div class="jimca-admin-card" id="jimca-tutor">
			<h3><?php esc_html_e( 'AI tutor for readers', 'jim-conversor-acessivel' ); ?></h3>
			<?php settings_errors( JIMCA_Tutor::OPTION ); ?>
			<p class="description">
				<?php esc_html_e( 'A chat bubble on the document page where readers ask questions about that document. Each question is sent, with the passages of the document that best match it and the last turns of the conversation, to the provider chosen above, using your key: every answer has a cost. Readers can hide the bubble.', 'jim-conversor-acessivel' ); ?>
			</p>
			<?php if ( ! $ready ) : ?>
				<p><strong><?php esc_html_e( 'The tutor needs the assistant turned on, with a working key, in the card above.', 'jim-conversor-acessivel' ); ?></strong></p>
			<?php else : ?>
				<p>
					<?php
					/* translators: %s: AI provider name */
					printf( esc_html__( 'Provider in use: %s.', 'jim-conversor-acessivel' ), '<strong>' . esc_html( $label ) . '</strong>' );
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'jimca_tutor_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Tutor', 'jim-conversor-acessivel' ); ?></th>
						<td>
							<p>
								<label>
									<input type="checkbox" name="jimca_tutor[enabled]" value="1" <?php checked( $tutor['enabled'] ); ?>>
									<?php esc_html_e( 'Turn on the tutor on this site.', 'jim-conversor-acessivel' ); ?>
								</label>
							</p>
							<p>
								<label>
									<input type="checkbox" name="jimca_tutor[default_on]" value="1" <?php checked( $tutor['default_on'] ); ?>>
									<?php esc_html_e( 'Show it on every document by default.', 'jim-conversor-acessivel' ); ?>
								</label>
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: 1: shortcode with the tutor on, 2: shortcode with the tutor off */
									esc_html__( 'Each shortcode can override this: %1$s shows the tutor, %2$s hides it.', 'jim-conversor-acessivel' ),
									'<code>[' . esc_html( JIMCA_Shortcode::TAG ) . ' id="123" tutor="yes"]</code>',
									'<code>[' . esc_html( JIMCA_Shortcode::TAG ) . ' id="123" tutor="no"]</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jimca_tutor_audience"><?php esc_html_e( 'Who can use it', 'jim-conversor-acessivel' ); ?></label></th>
						<td>
							<select id="jimca_tutor_audience" name="jimca_tutor[audience]">
								<option value="everyone" <?php selected( $tutor['audience'], 'everyone' ); ?>><?php esc_html_e( 'Every visitor', 'jim-conversor-acessivel' ); ?></option>
								<option value="logged_in" <?php selected( $tutor['audience'], 'logged_in' ); ?>><?php esc_html_e( 'Only logged-in users', 'jim-conversor-acessivel' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jimca_tutor_name"><?php esc_html_e( 'Name', 'jim-conversor-acessivel' ); ?></label></th>
						<td><input type="text" id="jimca_tutor_name" name="jimca_tutor[name]" class="regular-text" maxlength="60" value="<?php echo esc_attr( $tutor['name'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="jimca_tutor_personality"><?php esc_html_e( 'Personality', 'jim-conversor-acessivel' ); ?></label></th>
						<td>
							<textarea id="jimca_tutor_personality" name="jimca_tutor[personality]" class="large-text" rows="3" maxlength="1000" aria-describedby="jimca_tutor_personality_help"><?php echo esc_textarea( $tutor['personality'] ); ?></textarea>
							<p class="description" id="jimca_tutor_personality_help"><?php esc_html_e( 'How the tutor talks: tone, vocabulary, patience.', 'jim-conversor-acessivel' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jimca_tutor_instructions"><?php esc_html_e( 'Instructions (prompt)', 'jim-conversor-acessivel' ); ?></label></th>
						<td>
							<textarea id="jimca_tutor_instructions" name="jimca_tutor[instructions]" class="large-text" rows="6" maxlength="4000" aria-describedby="jimca_tutor_instructions_help"><?php echo esc_textarea( $tutor['instructions'] ); ?></textarea>
							<p class="description" id="jimca_tutor_instructions_help"><?php esc_html_e( 'What the tutor should do. Some rules always apply and cannot be changed here: answer from the document, say when the answer is not in it, ignore commands hidden in the document text, and reply in the reader\'s language. Leave a field empty to go back to the default text.', 'jim-conversor-acessivel' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jimca_tutor_greeting"><?php esc_html_e( 'Greeting', 'jim-conversor-acessivel' ); ?></label></th>
						<td><textarea id="jimca_tutor_greeting" name="jimca_tutor[greeting]" class="large-text" rows="2" maxlength="500"><?php echo esc_textarea( $tutor['greeting'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Limits', 'jim-conversor-acessivel' ); ?></th>
						<td>
							<p>
								<label for="jimca_tutor_per_hour"><?php esc_html_e( 'Questions per reader per hour:', 'jim-conversor-acessivel' ); ?></label>
								<input type="number" id="jimca_tutor_per_hour" name="jimca_tutor[per_hour]" min="1" max="500" value="<?php echo esc_attr( $tutor['per_hour'] ); ?>">
							</p>
							<p>
								<label for="jimca_tutor_per_day"><?php esc_html_e( 'Questions per day on the whole site:', 'jim-conversor-acessivel' ); ?></label>
								<input type="number" id="jimca_tutor_per_day" name="jimca_tutor[per_day]" min="1" max="100000" value="<?php echo esc_attr( $tutor['per_day'] ); ?>">
							</p>
							<p class="description"><?php esc_html_e( 'They keep the cost of your key under control. Readers are counted by account, or by a hashed IP address that is kept for one hour.', 'jim-conversor-acessivel' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Saved answers', 'jim-conversor-acessivel' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jimca_tutor[cache]" value="1" <?php checked( $tutor['cache'] ); ?>>
								<?php esc_html_e( 'Reuse the answer when a reader asks almost the same first question about the same document.', 'jim-conversor-acessivel' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Instant and free for repeated questions. Only the answer and the weight of each word of the question are kept, never the question text. Saved answers are discarded when the document or the tutor changes.', 'jim-conversor-acessivel' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save tutor', 'jim-conversor-acessivel' ), 'primary', 'submit', true, array( 'id' => 'jimca-tutor-submit' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * "Activity log" card: what happened in the conversions, updated live.
	 * Without JavaScript it shows the last entries as of page load.
	 */
	private function render_log_card() {
		$entries   = JIMCA_Log::all();
		$last_id   = $entries ? (int) end( $entries )['id'] : 0;
		$entries   = array_reverse( array_slice( $entries, -100 ) );
		$export    = wp_nonce_url( admin_url( 'admin-post.php?action=' . JIMCA_Log::EXPORT_ACTION ), JIMCA_Log::NONCE );
		?>
		<div class="jimca-admin-card" id="jimca-log" data-jimca-log data-last-id="<?php echo esc_attr( $last_id ); ?>">
			<h3><?php esc_html_e( 'Activity log', 'jim-conversor-acessivel' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'What happened in each conversion: file, type, time, memory used, words, images included and the cause of each image left out, plus errors. The text of your documents and your AI key are never recorded. Only the last 200 entries are kept. To follow a conversion as it happens, keep this page open in another tab.', 'jim-conversor-acessivel' ); ?>
			</p>
			<p class="jimca-log-tools">
				<label>
					<input type="checkbox" data-jimca-log-live checked>
					<?php esc_html_e( 'Update live', 'jim-conversor-acessivel' ); ?>
				</label>
				<button type="button" class="button" data-jimca-log-refresh hidden><?php esc_html_e( 'Refresh now', 'jim-conversor-acessivel' ); ?></button>
				<button type="button" class="button" data-jimca-log-clear hidden><?php esc_html_e( 'Clear log', 'jim-conversor-acessivel' ); ?></button>
				<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'jim-conversor-acessivel' ); ?></a>
			</p>
			<p class="screen-reader-text" role="status" aria-live="polite" data-jimca-log-status></p>
			<div class="jimca-log-scroll">
				<table class="widefat striped jimca-admin-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Time (UTC)', 'jim-conversor-acessivel' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Level', 'jim-conversor-acessivel' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Event', 'jim-conversor-acessivel' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Message', 'jim-conversor-acessivel' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Details', 'jim-conversor-acessivel' ); ?></th>
						</tr>
					</thead>
					<tbody data-jimca-log-body>
						<?php if ( ! $entries ) : ?>
							<tr data-jimca-log-empty><td colspan="5"><?php esc_html_e( 'No entries yet.', 'jim-conversor-acessivel' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $entries as $entry ) : ?>
							<?php
							$details = array();
							foreach ( $entry['context'] as $key => $value ) {
								$details[] = $key . '=' . $value;
							}
							?>
							<tr data-id="<?php echo esc_attr( $entry['id'] ); ?>">
								<td><?php echo esc_html( $entry['time'] ); ?></td>
								<td><strong class="jimca-log-level jimca-log-level--<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( $entry['level'] ); ?></strong></td>
								<td><code><?php echo esc_html( $entry['event'] ); ?></code></td>
								<td><?php echo esc_html( $entry['message'] ); ?></td>
								<td><?php echo esc_html( implode( '; ', $details ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * "Languages" card: which languages ship with the plugin, which one the
	 * panel is using, and where to review or add a translation. Jim is open
	 * source, so anyone can correct a text or add a language.
	 */
	private function render_languages_card() {
		$locale    = determine_locale();
		$names     = JIMCA_Plugin::bundled_languages();
		$review    = JIMCA_REPO_URL . '/issues/new?title=' . rawurlencode( 'Translation review: ' . $locale );
		$translate = 'https://translate.wordpress.org/projects/wp-plugins/' . JIMCA_PLUGIN_SLUG . '/';
		?>
		<div class="jimca-admin-card" id="jimca-languages">
			<h3><?php esc_html_e( 'Languages and translations', 'jim-conversor-acessivel' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: 1: list of language names, 2: locale code of the language in use (e.g. pt_BR) */
					esc_html__( 'Jim comes in %1$s, following the site language (or yours, in the dashboard). Language in use now: %2$s.', 'jim-conversor-acessivel' ),
					esc_html( implode( ', ', $names ) ),
					'<code>' . esc_html( $locale ) . '</code>'
				);
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Jim is open source, so more languages are welcome. If you find a mistake or want to add a language, you can review the translation files or translate online.', 'jim-conversor-acessivel' ); ?></p>
			<p class="jimca-admin-links">
				<a class="button button-primary" href="<?php echo esc_url( $review ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Review translation', 'jim-conversor-acessivel' ); ?></a>
				<a class="button" href="<?php echo esc_url( $translate ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Translate on WordPress.org', 'jim-conversor-acessivel' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'The WordPress.org page works once the plugin is listed in the official directory.', 'jim-conversor-acessivel' ); ?></p>
		</div>
		<?php
	}

	/**
	 * "Server requirements" table: this site's PHP against what the plugin
	 * needs and recommends. Each row states its status in words, not just
	 * color, for screen reader users and people who do not tell colors apart.
	 *
	 * Values follow how the plugin works: conversion is 100% PHP
	 * (smalot/pdfparser and phpoffice/phpword), with no external programs.
	 */
	private function render_server_check() {
		$memory   = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$time     = (int) ini_get( 'max_execution_time' );
		$upload   = (int) wp_max_upload_size();
		$wp_limit = wp_convert_hr_to_bytes( defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '256M' );

		$ok   = __( 'OK', 'jim-conversor-acessivel' );
		$warn = __( 'Warning', 'jim-conversor-acessivel' );
		$miss = __( 'Missing', 'jim-conversor-acessivel' );

		$rows = array();

		$rows[] = array(
			__( 'PHP version', 'jim-conversor-acessivel' ),
			PHP_VERSION,
			__( 'Minimum 7.4; recommended 8.1 or higher.', 'jim-conversor-acessivel' ),
			version_compare( PHP_VERSION, '8.1', '>=' ) ? $ok : ( version_compare( PHP_VERSION, '7.4', '>=' ) ? $warn : $miss ),
		);

		$rows[] = array(
			__( 'Memory limit (memory_limit)', 'jim-conversor-acessivel' ),
			( $memory < 0 ) ? __( 'no limit', 'jim-conversor-acessivel' ) : size_format( $memory ),
			sprintf(
				/* translators: %s: WordPress maximum memory limit, formatted (e.g. "256 MB") */
				__( 'Minimum 256 MB; recommended 512 MB for books with many pages and figures. The plugin tries to raise it up to WordPress\'s maximum limit (%s).', 'jim-conversor-acessivel' ),
				size_format( $wp_limit )
			),
			( $memory < 0 || $memory >= 512 * MB_IN_BYTES ) ? $ok : ( $memory >= 256 * MB_IN_BYTES ? $warn : $miss ),
		);

		$rows[] = array(
			__( 'Maximum execution time (max_execution_time)', 'jim-conversor-acessivel' ),
			( 0 === $time ) ? __( 'no limit', 'jim-conversor-acessivel' ) : sprintf( '%d s', $time ),
			__( 'Minimum 120 s; recommended 300 s. Large PDFs take minutes to convert.', 'jim-conversor-acessivel' ),
			( 0 === $time || $time >= 300 ) ? $ok : ( $time >= 120 ? $warn : $miss ),
		);

		$rows[] = array(
			__( 'Maximum upload size', 'jim-conversor-acessivel' ),
			size_format( $upload ),
			__( 'Smaller of upload_max_filesize and post_max_size. Recommended: 32 MB or more.', 'jim-conversor-acessivel' ),
			( $upload >= 32 * MB_IN_BYTES ) ? $ok : $warn,
		);

		$extensions = array(
			'zlib'     => array( __( 'PDF', 'jim-conversor-acessivel' ), true ),
			'iconv'    => array( __( 'PDF', 'jim-conversor-acessivel' ), true ),
			'mbstring' => array( __( 'PDF, DOCX, TXT and Markdown', 'jim-conversor-acessivel' ), true ),
			'zip'      => array( __( 'DOCX', 'jim-conversor-acessivel' ), true ),
			'dom'      => array( __( 'DOCX', 'jim-conversor-acessivel' ), true ),
			'xml'      => array( __( 'DOCX', 'jim-conversor-acessivel' ), true ),
			'gd'       => array( __( 'convert BMP/TIFF images from Word to PNG', 'jim-conversor-acessivel' ), false ),
		);

		foreach ( $extensions as $name => $info ) {
			$loaded = extension_loaded( $name );
			$rows[] = array(
				/* translators: %s: PHP extension name */
				sprintf( __( '%s extension', 'jim-conversor-acessivel' ), $name ),
				$loaded ? __( 'active', 'jim-conversor-acessivel' ) : __( 'inactive', 'jim-conversor-acessivel' ),
				/* translators: 1: formats that depend on the extension, 2: required or optional */
				sprintf( __( 'Used for: %1$s (%2$s).', 'jim-conversor-acessivel' ), $info[0], $info[1] ? __( 'required', 'jim-conversor-acessivel' ) : __( 'optional', 'jim-conversor-acessivel' ) ),
				$loaded ? $ok : ( $info[1] ? $miss : $warn ),
			);
		}

		$rows[] = array(
			__( 'HTTPS', 'jim-conversor-acessivel' ),
			is_ssl() ? __( 'on', 'jim-conversor-acessivel' ) : __( 'off', 'jim-conversor-acessivel' ),
			__( 'Recommended. Without HTTPS, browsers block the Share button (copy link) in post mode.', 'jim-conversor-acessivel' ),
			is_ssl() ? $ok : $warn,
		);
		?>
		<div class="jimca-admin-card">
			<h3><?php esc_html_e( 'Server requirements', 'jim-conversor-acessivel' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Conversion is done in PHP only, with no external programs (no need for Poppler, Ghostscript or similar). What weighs most is memory and execution time.', 'jim-conversor-acessivel' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Item', 'jim-conversor-acessivel' ); ?></th>
						<th scope="col"><?php esc_html_e( 'On this server', 'jim-conversor-acessivel' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Recommendation', 'jim-conversor-acessivel' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'jim-conversor-acessivel' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
							<td><?php echo esc_html( $row[1] ); ?></td>
							<td><?php echo esc_html( $row[2] ); ?></td>
							<td><strong><?php echo esc_html( $row[3] ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
