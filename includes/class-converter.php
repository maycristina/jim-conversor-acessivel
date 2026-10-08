<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks the converter for a file extension and returns sanitized HTML for
 * post_content.
 */
class JIMCA_Converter {

	/**
	 * PHP extensions each format needs. Checked before the reading library
	 * runs: without `zip` (DOCX) or `zlib` (PDF) the libraries die with a raw
	 * fatal error instead of telling the administrator what to enable.
	 *
	 * @var array<string, array<int, string>>
	 */
	private static $required_extensions = array(
		'pdf'  => array( 'zlib', 'iconv', 'mbstring' ),
		'docx' => array( 'zip', 'dom', 'xml', 'mbstring' ),
		'txt'  => array( 'mbstring' ),
		'md'   => array( 'mbstring' ),
	);

	/**
	 * @param string $extension Lowercase file extension.
	 * @throws JIMCA_Converter_Exception When a PHP extension is missing.
	 */
	public static function assert_php_extensions( $extension ) {
		if ( ! isset( self::$required_extensions[ $extension ] ) ) {
			return;
		}

		$missing = array();

		foreach ( self::$required_extensions[ $extension ] as $required ) {
			if ( ! extension_loaded( $required ) ) {
				$missing[] = $required;
			}
		}

		if ( ! empty( $missing ) ) {
			throw new JIMCA_Converter_Exception(
				sprintf(
					/* translators: 1: file extension (e.g. docx), 2: list of missing PHP extensions */
					esc_html__( 'This server lacks the PHP extensions needed to read .%1$s files: %2$s. Ask your host to enable them.', 'jim-conversor-acessivel' ),
					sanitize_key( $extension ),
					implode( ', ', array_map( 'sanitize_key', $missing ) )
				)
			);
		}
	}

	/**
	 * Share of symbols (neither letters nor digits) above which extracted text
	 * is unreadable. Ordinary books stay between 3% and 7% (programming books
	 * with code, about 6%); a PDF whose fonts have no Unicode map gave 94%.
	 */
	const UNREADABLE_SYMBOL_RATIO = 0.3;

	/**
	 * Does the extracted text look like garbage? It happens with PDFs whose
	 * fonts do not say which letter each glyph is: the PDF looks right on
	 * screen, but its text is a run of symbols.
	 *
	 * @param string $html Converted document.
	 * @return bool
	 */
	public static function looks_unreadable( $html ) {
		$text  = preg_replace( '/\s+/u', '', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
		$total = mb_strlen( (string) $text );

		if ( $total < 200 ) {
			return false;
		}

		$symbols = preg_match_all( '/[^\p{L}\p{M}\p{N}]/u', $text );

		return $symbols / $total > self::UNREADABLE_SYMBOL_RATIO;
	}

	/**
	 * @param string                 $file_path   Absolute path of the uploaded file.
	 * @param string                 $extension   Lowercase extension: pdf, docx, txt, md.
	 * @param JIMCA_Image_Store|null $image_store Where the document images go; null drops them.
	 * @param array                  $options     'page_markers' (bool, PDF): write "Page 1",
	 *                                            "Page 2"... in the text.
	 *                                            'progress' (callable|null): called with
	 *                                            (stage, done, total, images); stage is
	 *                                            'reading' before the file is opened and
	 *                                            'pages' after each PDF page.
	 * @return string Sanitized HTML.
	 *
	 * @throws JIMCA_Converter_Exception
	 */
	public static function convert( $file_path, $extension, $image_store = null, array $options = array() ) {
		$extension = strtolower( $extension );

		if ( 'markdown' === $extension ) {
			$extension = 'md';
		}

		self::assert_php_extensions( $extension );

		switch ( $extension ) {
			case 'pdf':
				$converter = new JIMCA_Pdf_Converter( $image_store, $options );
				break;
			case 'docx':
				$converter = new JIMCA_Word_Converter( $image_store );
				break;
			case 'txt':
				$converter = new JIMCA_Txt_Converter();
				break;
			case 'md':
				$converter = new JIMCA_Markdown_Converter();
				break;
			case 'doc':
				throw new JIMCA_Converter_Exception(
					esc_html__( '.doc files (Word 97-2003) are not supported. Save the document as .docx and upload it again.', 'jim-conversor-acessivel' )
				);
			default:
				throw new JIMCA_Converter_Exception(
					sprintf(
						/* translators: %s: file extension */
						esc_html__( 'Unsupported file type: %s', 'jim-conversor-acessivel' ),
						sanitize_key( $extension )
					)
				);
		}

		if ( isset( $options['progress'] ) && is_callable( $options['progress'] ) ) {
			call_user_func( $options['progress'], 'reading', 0, 0, 0 );
		}

		$html = $converter->convert( $file_path );

		return wp_kses_post( $html );
	}
}
