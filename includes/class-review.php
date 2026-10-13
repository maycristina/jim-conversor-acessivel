<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conversion review: server side of the review that runs in the browser
 * (assets/js/auditor.js).
 *
 * The review itself is made in the browser, over the document's HTML. Two
 * things need the server:
 * - the list of documents can run it without opening the document, so the
 *   HTML is handed out (only to whoever can edit that document);
 * - every run is written to the activity log with where it was started: the
 *   document edit screen ("edit") or the documents list ("list").
 */
class JIMCA_Review {

	const NONCE        = 'jimca_review';
	const AJAX_CONTENT = 'jimca_review_content';
	const AJAX_LOG     = 'jimca_review_log';

	/** Where a review can be started from. */
	const ORIGINS = array(
		'edit' => 'document edit screen',
		'list' => 'documents list',
	);

	public static function register_hooks() {
		add_action( 'wp_ajax_' . self::AJAX_CONTENT, array( __CLASS__, 'ajax_content' ) );
		add_action( 'wp_ajax_' . self::AJAX_LOG, array( __CLASS__, 'ajax_log' ) );
	}

	/**
	 * Data the browser needs on the screens that can start a review.
	 *
	 * @return array<string, string>
	 */
	public static function script_data() {
		return array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( self::NONCE ),
			'actionContent' => self::AJAX_CONTENT,
			'actionLog'     => self::AJAX_LOG,
		);
	}

	/**
	 * The document, if it exists and the current user can edit it; otherwise
	 * the request ends with an error.
	 *
	 * @return WP_Post
	 */
	private static function checked_document() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$post_id = isset( $_POST['doc'] ) ? absint( $_POST['doc'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || JIMCA_Post_Type::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( null, 403 );
		}

		return $post;
	}

	/**
	 * HTML of the document, for a review started from the list.
	 */
	public static function ajax_content() {
		$post = self::checked_document();

		wp_send_json_success(
			array(
				'html' => $post->post_content,
			)
		);
	}

	/**
	 * A review ran: one line in the activity log, with its origin.
	 */
	public static function ajax_log() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$post   = self::checked_document();
		$origin = isset( $_POST['origin'] ) ? sanitize_key( wp_unslash( $_POST['origin'] ) ) : '';
		$issues = isset( $_POST['issues'] ) ? min( 100000, absint( $_POST['issues'] ) ) : 0;

		if ( ! isset( self::ORIGINS[ $origin ] ) ) {
			wp_send_json_error( null, 400 );
		}

		self::log( $post->ID, $origin, $issues );
		wp_send_json_success();
	}

	/**
	 * @param int    $post_id Document.
	 * @param string $origin  Key of ORIGINS.
	 * @param int    $issues  Problems the review found.
	 */
	public static function log( $post_id, $origin, $issues ) {
		JIMCA_Log::add(
			'info',
			'review.run',
			sprintf( 'Conversion review run on document #%1$d, started from the %2$s.', (int) $post_id, self::ORIGINS[ $origin ] ),
			array(
				'origin'   => $origin,
				'document' => (int) $post_id,
				'issues'   => (int) $issues,
			)
		);
	}
}
