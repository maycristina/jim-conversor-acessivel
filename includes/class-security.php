<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps other sites from putting Jim's pages inside a frame (clickjacking).
 *
 * The header is `Content-Security-Policy: frame-ancestors 'self'` (the modern
 * way, which also takes a list of sites) plus `X-Frame-Options: SAMEORIGIN`
 * for old browsers. It goes out on:
 * - the front-end pages that print a Jim shortcode;
 * - Jim's own screens in the admin.
 *
 * It only says who may FRAME the page. It does not touch requests the page
 * makes (admin-ajax.php, the AI provider, the search) and has no effect on
 * calls from server to server. A site that embeds its documents on purpose
 * (a course platform, for example) lists its address with the
 * `jimca_frame_ancestors` filter, or returns '' to send no header.
 */
class JIMCA_Security {

	public static function register_hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'send_front_headers' ) );
		add_action( 'admin_init', array( __CLASS__, 'send_admin_headers' ) );
	}

	/**
	 * Value of frame-ancestors: "'self'" (default) or a list of sources.
	 *
	 * @return string '' when the administrator turned the header off.
	 */
	public static function frame_ancestors() {
		/**
		 * Who may frame Jim's pages.
		 *
		 * @param string $sources CSP source list, e.g. "'self' https://campus.example.com". '' sends no header.
		 */
		$sources = apply_filters( 'jimca_frame_ancestors', "'self'" );
		$sources = is_string( $sources ) ? trim( $sources ) : "'self'";

		// A header value must be one line: anything that could end it is dropped.
		return trim( preg_replace( '/[^\x20-\x7E]|[;,]/', '', $sources ) );
	}

	/**
	 * The headers to send, as [name => value].
	 *
	 * @return array<string, string>
	 */
	public static function headers() {
		$sources = self::frame_ancestors();

		if ( '' === $sources ) {
			return array();
		}

		$headers = array( 'Content-Security-Policy' => 'frame-ancestors ' . $sources );

		// X-Frame-Options cannot list sites: only sent while the rule is "same site".
		if ( "'self'" === $sources ) {
			$headers['X-Frame-Options'] = 'SAMEORIGIN';
		}

		return $headers;
	}

	/** A page that prints the shortcode. */
	public static function send_front_headers() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || ! has_shortcode( (string) $post->post_content, JIMCA_Shortcode::TAG ) ) {
			return;
		}

		self::send();
	}

	/** Jim's screens in the admin (WordPress already sends X-Frame-Options there). */
	public static function send_admin_headers() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which screens get a header; nothing is changed.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same.
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';

		if ( 0 === strpos( $page, JIMCA_Admin::MENU_SLUG ) || JIMCA_Post_Type::POST_TYPE === $type ) {
			self::send();
		}
	}

	private static function send() {
		if ( headers_sent() ) {
			return;
		}

		foreach ( self::headers() as $name => $value ) {
			// Added to, not replacing, a policy the host or another plugin already sends: browsers apply all of them.
			header( $name . ': ' . $value, 'X-Frame-Options' === $name );
		}
	}
}
