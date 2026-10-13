<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guided tours of the New Document and Settings screens (assets/js/tour.js).
 *
 * It starts by itself once after a fresh installation, for the first person
 * who opens the screen, and can be opened again whenever they want: from the
 * Tutorial page, from a button on the screen, or with `&jimca_tour=1` in the
 * address. Leaving it in any way (Finish, Skip or Esc) is remembered for that
 * person, so it never starts by itself again.
 */
class JIMCA_Tour {

	/** Option set by a fresh installation; removed when someone finishes or skips the tour. */
	const OPTION_PENDING = 'jimca_tour_pending';

	/** User meta: the version in which this person finished or skipped the tour. */
	const USER_META = 'jimca_tour_done';

	const AJAX_DONE    = 'jimca_tour_done';
	const ACTION_DISMISS = 'jimca_tour_dismiss';

	public static function register_hooks() {
		add_action( 'wp_ajax_' . self::AJAX_DONE, array( __CLASS__, 'ajax_done' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_invitation' ) );
	}

	/** Called on a fresh installation (see JIMCA_Activator::activate()). */
	public static function mark_pending() {
		add_option( self::OPTION_PENDING, 1, '', false );
	}

	/** @return string Address of the New Document screen with the tour running. */
	public static function open_url() {
		return add_query_arg( 'jimca_tour', '1', admin_url( 'admin.php?page=' . JIMCA_Admin::MENU_SLUG ) );
	}

	/** @return string Address of the Settings screen with its tour running. */
	public static function settings_open_url() {
		return add_query_arg( 'jimca_tour', '1', admin_url( 'admin.php?page=' . JIMCA_Admin::MENU_SLUG . '-settings' ) );
	}

	/**
	 * Does the tour start by itself on this load of the New Document screen?
	 *
	 * @param string $screen 'new-document' or 'settings'.
	 * @return bool
	 */
	public static function should_autostart( $screen = 'new-document' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only asks to show a help dialog; nothing is changed.
		if ( isset( $_GET['jimca_tour'] ) ) {
			return true;
		}

		// Only the New Document tour starts by itself after an installation.
		return 'new-document' === $screen && self::is_pending_for_user();
	}

	private static function is_pending_for_user() {
		return (bool) get_option( self::OPTION_PENDING )
			&& current_user_can( JIMCA_Admin::CAPABILITY_UPLOAD )
			&& ! get_user_meta( get_current_user_id(), self::USER_META, true );
	}

	/**
	 * Loads the tour on the New Document or the Settings screen.
	 *
	 * @param string $screen 'new-document' or 'settings'.
	 */
	public static function enqueue( $screen = 'new-document' ) {
		wp_enqueue_script( 'jimca-tour', JIMCA_PLUGIN_URL . 'assets/js/tour.js', array(), jimca_asset_version( 'assets/js/tour.js' ), true );
		wp_localize_script(
			'jimca-tour',
			'jimcaTourConfig',
			array(
				'autostart' => self::should_autostart( $screen ),
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'action'    => self::AJAX_DONE,
				'nonce'     => wp_create_nonce( self::AJAX_DONE ),
				'strings'   => array(
					/* translators: 1: number of the step, 2: total number of steps */
					'progress' => __( 'Step %1$s of %2$s', 'jim-conversor-acessivel' ),
					'back'     => __( 'Back', 'jim-conversor-acessivel' ),
					'next'     => __( 'Next', 'jim-conversor-acessivel' ),
					'finish'   => __( 'Finish', 'jim-conversor-acessivel' ),
					'skip'     => __( 'Skip tour', 'jim-conversor-acessivel' ),
				),
				'steps'     => 'settings' === $screen ? self::settings_steps() : self::steps(),
			)
		);
	}

	/**
	 * The steps. `target` lists selectors in order of preference: the first one
	 * that is on screen is highlighted; with none, the step is shown centered.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function steps() {
		return array(
			array(
				'target' => array(),
				'title'  => __( 'Welcome to Jim', 'jim-conversor-acessivel' ),
				'text'   => __( 'This short tour shows how to turn a file into an accessible page and publish it. You can leave it at any time with the Esc key, and open it again from the Tutorial.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '#jimca_file' ),
				'title'  => __( 'Choose the file', 'jim-conversor-acessivel' ),
				'text'   => __( 'Pick a PDF, DOCX, TXT or Markdown file. You can send up to 20 files in a batch at a time, within the configured limit.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '#jimca_title' ),
				'title'  => __( 'Title (optional)', 'jim-conversor-acessivel' ),
				'text'   => __( 'Without a title, Jim uses the file name. The file name is also the address (slug) of the post if you publish it below.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '#jimca_display_mode_label', '.jimca-admin-card form fieldset' ),
				'title'  => __( 'Display mode', 'jim-conversor-acessivel' ),
				'text'   => __( 'Choose how the document looks on the page: Accessible reader, with reading bar, voice and themes, or Standard blog, a plain blog post with the converted text. You can change it later in the documents list.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '#jimca_dest_type' ),
				'title'  => __( 'Also publish in', 'jim-conversor-acessivel' ),
				'text'   => __( 'Pick posts, pages or a custom post type and Jim creates one post per file with the shortcode already inside, so you do not have to copy and paste shortcodes. Choose "Nowhere" to keep the documents only in the Jim panel.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '#jimca_dest_status', '#jimca_dest_type' ),
				'title'  => __( 'Status and schedule', 'jim-conversor-acessivel' ),
				'text'   => __( 'After picking a type you choose the status: publish now, draft, pending, private or Schedule. A schedule uses the site clock and becomes an Action Scheduler event, where you can see or cancel it under Tools > Scheduled Actions.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '#jimca_dest_terms_post_tag', '#jimca_dest_terms_category_label' ),
				'title'  => __( 'Categories and tags', 'jim-conversor-acessivel' ),
				'text'   => __( 'The categories and tags you choose classify the Jim document and, when the post type uses them, the new post. The fields of the chosen type appear here too.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '#submit' ),
				'title'  => __( 'Convert', 'jim-conversor-acessivel' ),
				'text'   => __( 'Click "Convert document". Large files can take up to a minute: keep the page open. When it finishes, a notice shows the shortcode, the link to the post and, if you scheduled, the name of the event.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array(),
				'title'  => __( 'That is it', 'jim-conversor-acessivel' ),
				'text'   => __( 'Your documents are in Jim > Accessible Documents, with columns for the shortcode, the display mode and where each one was published. In Settings you connect the AI assistant and choose its quick texts (the suggestions readers see in the chat). The Tutorial page explains every option, and you can open this tour again from there.', 'jim-conversor-acessivel' ),
				'links'  => array(
					array(
						'label' => __( 'Settings tour (assistant and quick texts)', 'jim-conversor-acessivel' ),
						'url'   => self::settings_open_url(),
					),
					array(
						'label' => __( 'Accessible Documents', 'jim-conversor-acessivel' ),
						'url'   => admin_url( 'edit.php?post_type=' . JIMCA_Post_Type::POST_TYPE ),
					),
					array(
						'label' => __( 'Tutorial', 'jim-conversor-acessivel' ),
						'url'   => admin_url( 'admin.php?page=' . JIMCA_Admin::MENU_SLUG . '-tutorial' ),
					),
				),
			),
		);
	}

	/**
	 * Steps of the Settings tour: file rules and the JIM assistant, with its quick texts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function settings_steps() {
		return array(
			array(
				'target' => array(),
				'title'  => __( 'Welcome to Settings', 'jim-conversor-acessivel' ),
				'text'   => __( 'This short tour shows the rules for uploaded files and the JIM assistant, the chat where readers ask about a document, including the quick texts you can choose. Leave it at any time with the Esc key.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '#jimca_max_size', '.jimca-admin-card form .form-table' ),
				'title'  => __( 'File rules', 'jim-conversor-acessivel' ),
				'text'   => __( 'Choose which file types can be converted and the largest size accepted. Your host may have a smaller limit than the one set here.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '#jimca-ai' ),
				'title'  => __( 'JIM - Assistant', 'jim-conversor-acessivel' ),
				'text'   => __( 'Readers chat with JIM about the document they are reading. JIM answers through your own AI account, so every answer has a cost.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '#jimca_ai_provider' ),
				'title'  => __( 'Connect and turn on', 'jim-conversor-acessivel' ),
				'text'   => __( 'Pick the provider, save your key (it is stored encrypted), choose the model and turn JIM on. Use a key made only for this site and set a spending limit at the provider; if you suspect a leak, replace it. Until JIM is on, it stays hidden from readers.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '.jimca-assistant__advanced summary' ),
				'title'  => __( 'Advanced options', 'jim-conversor-acessivel' ),
				'text'   => __( 'Open "Advanced" to set who can use JIM, its name, personality, instructions, greeting, quick texts, limits and saved answers.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '#jimca-quick-texts' ),
				'title'  => __( 'Quick texts', 'jim-conversor-acessivel' ),
				'text'   => __( 'These are the suggestions readers see at the start of the chat and under the = button. Tick which ones to show, rename them, change the text they put in the field, add your own (up to 12) or turn them all off. "Send at once" sends the text without the reader typing, good for challenges. Tick "Restore the standard quick texts" to go back to the original five.', 'jim-conversor-acessivel' ),
			),
			array(
				'target' => array( '#jimca-ai-submit' ),
				'title'  => __( 'Save JIM', 'jim-conversor-acessivel' ),
				'text'   => __( 'Click "Save JIM" for the changes to reach the readers. They appear the next time a document page is loaded.', 'jim-conversor-acessivel' ),
				'links'  => array(
					array(
						'label' => __( 'Tutorial', 'jim-conversor-acessivel' ),
						'url'   => admin_url( 'admin.php?page=' . JIMCA_Admin::MENU_SLUG . '-tutorial' ),
					),
				),
			),
		);
	}

	/**
	 * The tour ended (Finish, Skip or Esc): do not start it by itself again.
	 */
	public static function ajax_done() {
		check_ajax_referer( self::AJAX_DONE );

		if ( ! current_user_can( JIMCA_Admin::CAPABILITY_UPLOAD ) ) {
			wp_send_json_error( null, 403 );
		}

		self::remember_done();
		wp_send_json_success();
	}

	private static function remember_done() {
		update_user_meta( get_current_user_id(), self::USER_META, JIMCA_VERSION );
		delete_option( self::OPTION_PENDING );
	}

	/**
	 * "No, thanks" in the invitation: same as finishing the tour.
	 */
	public static function handle_dismiss() {
		check_admin_referer( self::ACTION_DISMISS );

		if ( current_user_can( JIMCA_Admin::CAPABILITY_UPLOAD ) ) {
			self::remember_done();
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Right after a fresh installation, on the Dashboard and Plugins screens:
	 * an invitation to take the tour. It goes away when the tour is taken or
	 * declined.
	 */
	public static function render_invitation() {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || ! self::is_pending_for_user() ) {
			return;
		}

		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS ), self::ACTION_DISMISS );
		?>
		<div class="notice notice-info">
			<p><strong><?php esc_html_e( 'Jim - Accessible Converter is ready.', 'jim-conversor-acessivel' ); ?></strong> <?php esc_html_e( 'Want a one-minute guided tour of how to convert and publish your first document?', 'jim-conversor-acessivel' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::open_url() ); ?>"><?php esc_html_e( 'Start the tour', 'jim-conversor-acessivel' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'No, thanks', 'jim-conversor-acessivel' ); ?></a>
			</p>
		</div>
		<?php
	}
}
