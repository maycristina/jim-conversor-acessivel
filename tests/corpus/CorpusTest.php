<?php
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/unit/UnitTestCase.php';

/**
 * Conversion corpus: each folder in tests/corpus/ is a file that goes through
 * JIMCA_Converter::convert(), as in the plugin, and is checked two ways:
 *
 * - expected.json: what must hold (sentences in order, text that must not
 *   appear, how many <li>, <th>...). Written by hand: it says what "right" is.
 * - expected-reader.txt: what the read-aloud player speaks, one element per
 *   line. Written by the test (composer corpus:update): it records how the
 *   converter reads today, so any change shows up as a diff.
 *
 * The result of each run goes to build/corpus/<case>/ (actual.html and
 * actual-reader.txt) to be opened and compared. See tests/corpus/README.md.
 */
class CorpusTest extends UnitTestCase {

	/**
	 * Elements the read-aloud player speaks, in document order: the same
	 * selector as collectElements() in assets/js/frontend.js. Keep both in step.
	 */
	const READER_XPATH = '//h1|//h2|//h3|//h4|//h5|//h6|//p|//li|//blockquote|//figcaption|//img[normalize-space(@alt) != "" and not(@data-jimca-alt-missing)]';

	/** Tags of WordPress' $allowedposttags that converted documents use. */
	const POST_TAGS = '<a><abbr><b><blockquote><br><caption><cite><code><col><colgroup><dd><del><div><dl><dt><em><figcaption><figure><h1><h2><h3><h4><h5><h6><hr><i><img><ins><li><mark><ol><p><pre><q><s><section><small><span><strong><sub><sup><table><tbody><td><tfoot><th><thead><tr><u><ul>';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubEscapeFunctions();
		Functions\stubs(
			array(
				'esc_html__'        => static function ( $text ) {
					return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
				},
				'sanitize_key'      => static function ( $key ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
				},
				'wp_tempnam'        => static function ( $prefix = '' ) {
					return tempnam( sys_get_temp_dir(), (string) $prefix );
				},
				'wp_delete_file'    => static function ( $file ) {
					if ( file_exists( $file ) ) {
						unlink( $file );
					}
				},
				'wp_normalize_path' => static function ( $path ) {
					return str_replace( '\\', '/', (string) $path );
				},
				// Not the real filter, which needs WordPress: it only drops the tags WordPress does not allow in posts, keeping their text.
				'wp_kses_post'      => static function ( $html ) {
					return strip_tags( (string) $html, self::POST_TAGS );
				},
			)
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function cases() {
		$cases = array();

		foreach ( glob( __DIR__ . '/*/expected.json' ) as $spec ) {
			$name           = basename( dirname( $spec ) );
			$cases[ $name ] = array( $name );
		}

		return $cases;
	}

	/**
	 * @dataProvider cases
	 *
	 * @param string $name Folder of the case.
	 */
	public function test_case( $name ) {
		$dir  = __DIR__ . '/' . $name;
		$spec = json_decode( (string) file_get_contents( $dir . '/expected.json' ), true );
		$this->assertIsArray( $spec, $name . '/expected.json is not valid JSON' );

		$sources = glob( $dir . '/source.*' );
		$this->assertNotEmpty( $sources, $name . ': no source.* file' );

		$source  = $sources[0];
		$options = isset( $spec['options'] ) ? $spec['options'] : array();
		$html    = JIMCA_Converter::convert( $source, pathinfo( $source, PATHINFO_EXTENSION ), null, $options );
		$reader  = self::reader( $html );
		$text    = self::reader_text( $reader );

		$out = dirname( __DIR__, 2 ) . '/build/corpus/' . $name;
		if ( ! is_dir( $out ) ) {
			mkdir( $out, 0777, true );
		}
		file_put_contents( $out . '/actual.html', $html );
		file_put_contents( $out . '/actual-reader.txt', $text );

		$problems = self::check( $spec, $html, $reader, $source );

		if ( isset( $spec['pending'] ) ) {
			// A known gap: the case documents the goal and must not break the build until it is fixed.
			if ( $problems ) {
				$this->markTestIncomplete( $name . ' (pending: ' . $spec['pending'] . ")\n- " . implode( "\n- ", $problems ) );
			}
			$this->fail( $name . ' passes now: remove "pending" from expected.json and run composer corpus:update.' );
		}

		$this->assertSame( array(), $problems, $name . ":\n- " . implode( "\n- ", $problems ) . "\nSee build/corpus/$name/actual-reader.txt" );

		$snapshot = $dir . '/expected-reader.txt';

		if ( getenv( 'JIMCA_UPDATE_CORPUS' ) ) {
			file_put_contents( $snapshot, $text );
		}

		$this->assertFileExists( $snapshot, $name . ': no expected-reader.txt yet. Run composer corpus:update, review it and commit it.' );
		$this->assertSame(
			str_replace( "\r\n", "\n", (string) file_get_contents( $snapshot ) ),
			$text,
			"$name: the text the reader speaks changed (- expected, + actual). If the change is intended, run composer corpus:update and commit expected-reader.txt."
		);
	}

	/**
	 * What the read-aloud player speaks: tag and text of each element, as
	 * frontend.js does (text content trimmed; images read as "Image: alt").
	 *
	 * @param string $html
	 * @return array<int, array{tag: string, text: string}>
	 */
	public static function reader( $html ) {
		$xpath = self::xpath( $html );
		$items = array();

		foreach ( $xpath->query( self::READER_XPATH ) as $node ) {
			$tag  = strtolower( $node->nodeName );
			$text = 'img' === $tag ? 'Image: ' . trim( $node->getAttribute( 'alt' ) ) : $node->textContent;
			$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

			if ( '' !== $text ) {
				$items[] = array(
					'tag'  => $tag,
					'text' => $text,
				);
			}
		}

		return $items;
	}

	/**
	 * @param array<int, array{tag: string, text: string}> $reader
	 * @return string One "tag: text" line per element.
	 */
	private static function reader_text( array $reader ) {
		$lines = array();
		foreach ( $reader as $item ) {
			$lines[] = $item['tag'] . ': ' . $item['text'];
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @param array                                        $spec   See tests/corpus/README.md.
	 * @param string                                       $html
	 * @param array<int, array{tag: string, text: string}> $reader
	 * @param string                                       $source The case's source file.
	 * @return string[] What failed; empty when everything holds.
	 */
	private static function check( array $spec, $html, array $reader, $source ) {
		$problems = array();
		$spoken   = implode( "\n", array_column( $reader, 'text' ) );

		// Checked in every case: a replacement character or a private-use glyph is read as noise or skipped.
		if ( false !== strpos( $spoken, "\u{FFFD}" ) ) {
			$problems[] = 'the text has the replacement character (U+FFFD): a font without a Unicode map';
		}
		if ( preg_match( '/[\x{E000}-\x{F8FF}]/u', $spoken, $pua ) ) {
			$problems[] = sprintf( 'the text has a private-use character (U+%04X), usually a Symbol/Wingdings bullet', mb_ord( $pua[0], 'UTF-8' ) );
		}

		$offset = 0;
		foreach ( isset( $spec['in_order'] ) ? $spec['in_order'] : array() as $fragment ) {
			$at = mb_strpos( $spoken, $fragment, $offset );
			if ( false === $at ) {
				$problems[] = false === mb_strpos( $spoken, $fragment )
					? 'missing (or broken apart): "' . $fragment . '"'
					: 'out of order: "' . $fragment . '" appears before the previous expected fragment';
				continue;
			}
			$offset = $at + mb_strlen( $fragment );
		}

		foreach ( isset( $spec['absent'] ) ? $spec['absent'] : array() as $fragment ) {
			if ( false !== mb_strpos( $spoken, $fragment ) ) {
				$problems[] = 'must not be read: "' . $fragment . '"';
			}
		}

		foreach ( isset( $spec['absent_patterns'] ) ? $spec['absent_patterns'] : array() as $pattern ) {
			if ( preg_match( $pattern, $spoken, $match ) ) {
				$problems[] = 'pattern ' . $pattern . ' matched: "' . $match[0] . '"';
			}
		}

		// The count create_document() uses: 0 means the upload is refused as a PDF without text.
		if ( isset( $spec['words'] ) && (int) $spec['words'] !== JIMCA_Converter::count_words( $html ) ) {
			$problems[] = sprintf( 'words: expected %d, found %d', $spec['words'], JIMCA_Converter::count_words( $html ) );
		}

		if ( isset( $spec['images'] ) ) {
			$found = self::pdf_images( $source );
			if ( $spec['images'] !== $found ) {
				$problems[] = sprintf( 'images: expected [%s], found [%s]', implode( ', ', $spec['images'] ), implode( ', ', $found ) );
			}
		}

		if ( ! empty( $spec['xpath'] ) ) {
			$xpath = self::xpath( $html );
			foreach ( $spec['xpath'] as $expression => $expected ) {
				$count = (int) $xpath->evaluate( 'count(' . $expression . ')' );
				if ( (int) $expected !== $count ) {
					$problems[] = sprintf( '%s: expected %d, found %d', $expression, $expected, $count );
				}
			}
		}

		return $problems;
	}

	/**
	 * Images the plugin would put in the document, as "WxH" in order. The
	 * conversion above runs without images (no Media Library in the tests),
	 * so the extractor runs here on its own, page by page, as the converter
	 * calls it.
	 *
	 * @param string $source PDF.
	 * @return string[]
	 */
	private static function pdf_images( $source ) {
		$pdf       = ( new \Smalot\PdfParser\Parser() )->parseFile( $source );
		$extractor = new JIMCA_Pdf_Image_Extractor( $pdf, $source );
		$sizes     = array();

		foreach ( $pdf->getPages() as $page ) {
			foreach ( $extractor->extract( $page ) as $binary ) {
				$size    = getimagesizefromstring( $binary );
				$sizes[] = $size ? $size[0] . 'x' . $size[1] : '?';
			}
		}

		return $sizes;
	}

	/**
	 * @param string $html
	 * @return \DOMXPath
	 */
	private static function xpath( $html ) {
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new \DOMXPath( $dom );
	}
}
