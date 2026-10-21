<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands.
 *
 * `wp jimca convert` runs a file through the same converter as the admin
 * screen and shows the document on a published page, so that the result can
 * be checked from a script (bin/seo-audit.js) without opening the browser:
 * file in, page address out.
 */
class JIMCA_CLI {

	/**
	 * Converts a file and publishes it on a page with the document's shortcode.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path of a PDF, DOCX, TXT or MD file.
	 *
	 * [--mode=<mode>]
	 * : Display mode of the document.
	 * ---
	 * default: reader
	 * options:
	 *   - reader
	 *   - post
	 * ---
	 *
	 * [--language=<tag>]
	 * : Language of the document (for example fr, pt-BR). Default: automatic.
	 *
	 * [--author=<name>]
	 * : Author of the document. Default: the one in the file properties.
	 *
	 * [--title=<title>]
	 * : Title of the document. Default: the file name.
	 *
	 * [--slug=<slug>]
	 * : Address of the page. Default: made from the title.
	 *
	 * [--porcelain]
	 * : Print only the address of the page.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jimca convert relatorio.docx --mode=post --language=pt-BR --slug=relatorio
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 */
	public function convert( $args, $assoc ) {
		$source = $args[0];

		if ( ! is_readable( $source ) ) {
			WP_CLI::error( 'File not found: ' . $source );
		}

		$extension = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
		$mode      = isset( $assoc['mode'] ) ? $assoc['mode'] : 'reader';
		$language  = isset( $assoc['language'] ) ? (string) $assoc['language'] : '';

		if ( ! in_array( $mode, array( 'reader', 'post' ), true ) ) {
			WP_CLI::error( 'Invalid --mode. Use reader or post.' );
		}

		if ( '' !== $language && ! isset( JIMCA_Metadata::languages()[ $language ] ) ) {
			WP_CLI::error( 'Unsupported --language. Use one of: ' . implode( ', ', array_keys( JIMCA_Metadata::languages() ) ) );
		}

		// The converter deletes the original when Settings say so: work on a copy.
		$copy = wp_tempnam( basename( $source ) );
		copy( $source, $copy );

		$received = array(
			'file'     => $copy,
			'url'      => '',
			'ext'      => $extension,
			'name'     => basename( $source ),
			'size'     => (int) filesize( $source ),
			'language' => $language,
			'author'   => isset( $assoc['author'] ) ? mb_substr( sanitize_text_field( (string) $assoc['author'] ), 0, 120 ) : '',
		);

		try {
			$document = JIMCA_Admin::get_instance()->convert_received( $received, isset( $assoc['title'] ) ? (string) $assoc['title'] : '', false );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		if ( 'post' === $mode ) {
			update_post_meta( $document, JIMCA_Post_Type::MODE_META, 'post' );
		}

		$page = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => get_the_title( $document ),
				'post_name'    => isset( $assoc['slug'] ) ? sanitize_title( $assoc['slug'] ) : '',
				'post_content' => '[' . JIMCA_Shortcode::TAG . ' id="' . (int) $document . '"]',
				'post_status'  => 'publish',
			),
			true
		);

		if ( is_wp_error( $page ) ) {
			WP_CLI::error( $page->get_error_message() );
		}

		$url = get_permalink( $page );

		if ( ! empty( $assoc['porcelain'] ) ) {
			WP_CLI::line( $url );
			return;
		}

		WP_CLI::success( sprintf( 'Document #%d (%s mode) is on %s', $document, $mode, $url ) );
	}
}
