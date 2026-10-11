<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guided tour of the New Document screen (assets/js/tour.js).
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

	/**
	 * Does the tour start by itself on this load of the New Document screen?
	 *
	 * @return bool
	 */
	public static function should_autostart() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only asks to show a help dialog; nothing is changed.
		if ( isset( $_GET['jimca_tour'] ) ) {
			return true;
		}

		return self::is_pending_for_user();
	}

	private static function is_pending_for_user() {
		return (bool) get_option( self::OPTION_PENDING )
			&& current_user_can( JIMCA_Admin::CAPABILITY_UPLOAD )
			&& ! get_user_meta( get_current_user_id(), self::USER_META, true );
	}

	/**
	 * Loads the tour on the New Document screen.
	 */
	public static function enqueue() {
		wp_enqueue_script( 'jimca-tour', JIMCA_PLUGIN_URL . 'assets/js/tour.js', array(), jimca_asset_version( 'assets/js/tour.js' ), true );
		wp_localize_script(
			'jimca-tour',
			'jimcaTourConfig',
			array(
				'autostart' => self::should_autostart(),
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
				'steps'     => self::steps(),
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
				'text'   => __( 'Pick a PDF, DOCX, TXT or Markdown file. TXT and Markdown can be sent up to 20 at a time; PDF and DOCX one at a time.', 'jim-conversor-acessivel' ),
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
				'text'   => __( 'Your documents are in Jim > Accessible Documents, with columns for the shortcode, the display mode and where each one was published. The Tutorial page explains every option, and you can open this tour again from there.', 'jim-conversor-acessivel' ),
				'links'  => array(
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
