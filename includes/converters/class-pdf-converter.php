<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Smalot\PdfParser\Parser as PdfParser;

class JIMCA_Pdf_Converter implements JIMCA_Converter_Interface {

	/** @var JIMCA_Image_Store|null null when images are turned off in Settings. */
	private $image_store;

	/** @var bool Write "Page 1", "Page 2"... between pages. */
	private $show_page_markers;

	/** @var callable|null See JIMCA_Converter::convert(), option 'progress'. */
	private $progress;

	/** Punctuation that ends a paragraph (see reconstruct_paragraphs()). */
	const TERMINAL_PUNCTUATION = array( '.', '!', '?', ':', '"', '”', '»' );

	/**
	 * Dot leader of a printed table of contents: 4 or more dots (spaced or
	 * not), repeated "…" or underscores. An ordinary ellipsis ("...") has only
	 * 3 dots and does not match.
	 */
	const TOC_LEADER = '(?:\.[ \t]?){4,}|…{2,}|_{4,}';

	/**
	 * @param JIMCA_Image_Store|null $image_store
	 * @param array                  $options     'page_markers' => bool (default true), 'progress' => callable|null.
	 */
	public function __construct( $image_store = null, array $options = array() ) {
		$this->image_store       = $image_store;
		$this->show_page_markers = ! isset( $options['page_markers'] ) || (bool) $options['page_markers'];
		$this->progress          = isset( $options['progress'] ) && is_callable( $options['progress'] ) ? $options['progress'] : null;
	}

	/**
	 * @param int $done  Pages done.
	 * @param int $total Pages in the file.
	 */
	private function report_progress( $done, $total ) {
		if ( $this->progress ) {
			call_user_func( $this->progress, 'pages', $done, $total, $this->image_store ? $this->image_store->get_report()['included'] : 0 );
		}
	}

	public function convert( $file_path ) {
		$part = $this->convert_part( $file_path, array(), 0 );

		return self::finish( $part['html'] );
	}

	/**
	 * Converts pages until a deadline, so a long book can be converted over
	 * several requests (see JIMCA_Conversion_Job): hosts often end a request
	 * after 30 or 60 seconds, whatever PHP's own limits say.
	 *
	 * Everything that links one page to the next travels in $state: the next
	 * page, the paragraph waiting for the next page ($carry), the blocks built
	 * one page ahead ($next) and the images already seen.
	 *
	 * @param string $file_path
	 * @param array  $state    Empty to start, or the 'state' of the previous call.
	 * @param float  $deadline microtime( true ) after which to stop at the next page; 0 = no limit.
	 * @return array{done: bool, html: string, state: array}
	 * @throws JIMCA_Converter_Exception
	 */
	public function convert_part( $file_path, array $state, $deadline ) {
		if ( ! class_exists( PdfParser::class ) ) {
			throw new JIMCA_Converter_Exception(
				esc_html__( 'The smalot/pdfparser library was not found. Run "composer install" in the plugin folder.', 'jim-conversor-acessivel' )
			);
		}

		try {
			$parser  = new PdfParser();
			$pdf     = $parser->parseFile( $file_path );
			$pages   = $pdf->getPages();
		} catch ( \Exception $e ) {
			throw new JIMCA_Converter_Exception(
				sprintf(
					/* translators: %s: original error message */
					esc_html__( 'Could not read the PDF. Common causes: corrupted file, password-protected or not standard PDF. Technical detail: %s', 'jim-conversor-acessivel' ),
					esc_html( $e->getMessage() )
				)
			);
		}

		if ( empty( $pages ) ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'The PDF contains no extractable text (it may be a scanned/image-only PDF).', 'jim-conversor-acessivel' ) );
		}

		$pages     = array_values( $pages );
		$total     = count( $pages );
		$index     = isset( $state['next_page'] ) ? (int) $state['next_page'] : 0;
		$outline   = JIMCA_Pdf_Outline::read( $pdf, $pages );
		$extractor = $this->image_store ? new JIMCA_Pdf_Image_Extractor( $pdf, $file_path ) : null;
		$markers   = $this->show_page_markers && $total > 1;

		if ( $extractor && isset( $state['extractor'] ) ) {
			$extractor->import_state( $state['extractor'] );
		}

		$this->report_progress( $index, $total );

		$html = '';

		/*
		 * Without page markers, a sentence that runs into the next page must
		 * not become two paragraphs. The last paragraph of each page waits
		 * ($carry) for the next one: if it starts with text, both are joined.
		 * The page images wait too ($carry_images), to come after that
		 * paragraph.
		 */
		$carry        = isset( $state['carry'] ) ? $state['carry'] : null;
		$carry_images = isset( $state['carry_images'] ) ? $state['carry_images'] : '';

		/*
		 * Blocks are built one page ahead: with page markers, a table of
		 * contents item whose title ends one page and whose page number starts
		 * the next must be joined BEFORE the first page is written.
		 */
		if ( array_key_exists( 'next', $state ) ) {
			$next = $state['next'];
		} else {
			$next = isset( $pages[ $index ] ) ? $this->blocks_of_page( $pages[ $index ], $outline, $index ) : array();
		}

		while ( $index < $total ) {
			$page   = $pages[ $index ];
			$blocks = $next;
			$next   = isset( $pages[ $index + 1 ] ) ? $this->blocks_of_page( $pages[ $index + 1 ], $outline, $index + 1 ) : array();

			$last = end( $blocks );
			if ( $markers && $last && 'p' === $last['type'] && ! self::ends_sentence( $last['text'] )
				&& isset( $next[0] ) && 'toc' === $next[0]['type'] && '' === $next[0]['text'] ) {
				$blocks[ count( $blocks ) - 1 ] = array(
					'type' => 'toc',
					'text' => $last['text'],
					'page' => $next[0]['page'],
				);
				array_shift( $next );
			}

			if ( null !== $carry ) {
				// This page finishes the sentence (or contents item) the previous one cut.
				if ( isset( $blocks[0] ) && in_array( $blocks[0]['type'], array( 'p', 'toc' ), true ) ) {
					$blocks[0]['text'] = trim( $carry . ' ' . $blocks[0]['text'] );
					$html             .= $this->render_blocks( array( array_shift( $blocks ) ) );
				} else {
					$html .= $this->render_blocks( array( self::paragraph( $carry ) ) );
				}
				$html        .= $carry_images;
				$carry        = null;
				$carry_images = '';
			}

			if ( $markers ) {
				$html .= '<h2 class="jimca-page-title">' . sprintf(
					/* translators: %d: page number */
					esc_html__( 'Page %d', 'jim-conversor-acessivel' ),
					$index + 1
				) . '</h2>' . "\n";
			}

			$last = end( $blocks );
			if ( ! $markers && $last && 'p' === $last['type'] && ! self::ends_sentence( $last['text'] ) ) {
				$carry = $last['text'];
				array_pop( $blocks );
			}

			$html  .= $this->render_blocks( $blocks );
			$images = $extractor ? $this->render_page_images( $extractor, $page, $index + 1 ) : '';

			if ( null !== $carry ) {
				$carry_images = $images;
			} else {
				$html .= $images;
			}

			++$index;
			$this->report_progress( $index, $total );

			if ( $deadline > 0 && $index < $total && microtime( true ) >= $deadline ) {
				return array(
					'done'  => false,
					'html'  => $html,
					'state' => array(
						'next_page'    => $index,
						'carry'        => $carry,
						'carry_images' => $carry_images,
						'next'         => $next,
						'extractor'    => $extractor ? $extractor->export_state() : array(),
					),
				);
			}
		}

		if ( null !== $carry ) {
			$html .= $this->render_blocks( array( self::paragraph( $carry ) ) ) . $carry_images;
		}

		if ( $extractor ) {
			$this->image_store->add_skipped( $extractor->get_skip_reasons() );
		}

		return array(
			'done'  => true,
			'html'  => $html,
			'state' => array(),
		);
	}

	/**
	 * Last touch on the whole document: a printed table of contents spanning
	 * pages becomes one list.
	 *
	 * @param string $html
	 * @return string
	 */
	public static function finish( $html ) {
		return str_replace( "</ul>\n<ul class=\"jimca-printed-toc\">\n", '', $html );
	}

	/**
	 * @param \Smalot\PdfParser\Page $page
	 * @param array                  $outline See JIMCA_Pdf_Outline::read().
	 * @param int                    $index
	 * @return array Blocks of the page (see page_blocks()).
	 */
	private function blocks_of_page( $page, array $outline, $index ) {
		/*
		 * Printed page numbers go in both modes: without markers they are
		 * noise, and with markers they repeat "Page N" (and stick to the last
		 * paragraph or contents item).
		 */
		$text = self::drop_page_number_lines( $page->getText() );

		return $this->page_blocks(
			$this->reconstruct_paragraphs( $text ),
			isset( $outline[ $index ] ) ? $outline[ $index ] : array()
		);
	}

	/**
	 * Merges the page text with the outline headings that start on it.
	 *
	 * The outline gives the page, not the line. But the heading is almost
	 * always printed on the page too: when a paragraph starts with the heading
	 * text, it becomes the heading right there (the rest of the paragraph, if
	 * any, stays as text). A heading not found goes to the top of the page.
	 *
	 * @param string[] $paragraphs
	 * @param array    $entries    Outline entries of this page (see JIMCA_Pdf_Outline).
	 * @return array<int, array{type: string, text: string, level?: int}>
	 */
	private function page_blocks( array $paragraphs, array $entries ) {
		$blocks = array();
		foreach ( $paragraphs as $paragraph ) {
			$blocks = array_merge( $blocks, self::blocks_for( $paragraph ) );
		}
		$blocks = self::split_toc_heading( $blocks );
		$cursor = 0;

		foreach ( $entries as $entry ) {
			$heading = array(
				'type'  => 'h',
				'text'  => $entry['title'],
				'level' => $entry['level'],
			);
			$needle  = self::normalize( $entry['title'] );
			$found   = false;

			for ( $i = $cursor, $n = count( $blocks ); $i < $n; $i++ ) {
				if ( 'p' !== $blocks[ $i ]['type'] ) {
					continue;
				}

				$text = $blocks[ $i ]['text'];
				if ( 0 !== strpos( self::normalize( $text ), $needle ) ) {
					continue;
				}

				$rest        = self::remove_prefix( $text, $entry['title'] );
				$replacement = array( $heading );
				if ( '' !== $rest ) {
					$replacement[] = self::paragraph( $rest );
				}

				array_splice( $blocks, $i, 1, $replacement );
				$cursor = $i + 1;
				$found  = true;
				break;
			}

			if ( ! $found ) {
				array_splice( $blocks, $cursor, 0, array( $heading ) );
				++$cursor;
			}
		}

		return $blocks;
	}

	/**
	 * @param array $blocks
	 * @return string
	 */
	private function render_blocks( array $blocks ) {
		$html    = '';
		$in_list = false;

		foreach ( $blocks as $block ) {
			// Consecutive contents items form one list.
			if ( 'toc' === $block['type'] ) {
				if ( ! $in_list ) {
					$html   .= '<ul class="jimca-printed-toc">' . "\n";
					$in_list = true;
				}

				$html .= '<li><span class="jimca-printed-toc__title">' . esc_html( $block['text'] ) . '</span> '
					. '<span class="jimca-printed-toc__leader" aria-hidden="true"></span> '
					. '<span class="jimca-printed-toc__page"><span class="jimca-visually-hidden">' . esc_html__( 'page', 'jim-conversor-acessivel' ) . ' </span>' . esc_html( $block['page'] ) . '</span></li>' . "\n";
				continue;
			}

			if ( $in_list ) {
				$html   .= '</ul>' . "\n";
				$in_list = false;
			}

			if ( 'toc-heading' === $block['type'] ) {
				$html .= '<p class="jimca-printed-toc__heading"><strong>' . esc_html( $block['text'] ) . '</strong></p>' . "\n";
			} elseif ( 'h' === $block['type'] ) {
				// Outline level 0 is <h2>: the <h1> belongs to the site page, and the document title is an <h2> too.
				$tag   = 'h' . min( 6, 2 + (int) $block['level'] );
				$html .= '<' . $tag . '>' . esc_html( $block['text'] ) . '</' . $tag . '>' . "\n";
			} else {
				$html .= '<p>' . esc_html( $block['text'] ) . '</p>' . "\n";
			}
		}

		if ( $in_list ) {
			$html .= '</ul>' . "\n";
		}

		return $html;
	}

	/**
	 * A paragraph, or a printed contents item ("title ..... 87").
	 *
	 * @param string $text
	 * @return array[]
	 */
	private static function blocks_for( $text ) {
		// The title may be missing: the item's text ended the previous page.
		if ( ! preg_match( '/^(.*?)\s*(?:' . self::TOC_LEADER . ')\s*(\d{1,4})$/u', $text, $match ) ) {
			return array( self::paragraph( $text ) );
		}

		return array(
			array(
				'type' => 'toc',
				'text' => trim( $match[1], " \t." ),
				'page' => $match[2],
			),
		);
	}

	/**
	 * The "Contents" title of the page (and sometimes text before it, like
	 * the booklet name on the cover) tends to stick to the first contents
	 * item, since it has no final punctuation either. Only the first item of
	 * each table of contents is checked, and only for the capitalized word,
	 * so an item like "4.1 Executive summary" is not split.
	 *
	 * @param array $blocks
	 * @return array
	 */
	private static function split_toc_heading( array $blocks ) {
		$out  = array();
		$prev = null;

		foreach ( $blocks as $block ) {
			if ( 'toc' === $block['type'] && ( null === $prev || 'toc' !== $prev ) &&
				preg_match( '/^(?:(.+?)\s+)?(SUMÁRIO|Sumário|ÍNDICE|Índice|CONTEÚDO|Conteúdo|TABLE OF CONTENTS|Table of Contents|CONTENTS|Contents)\s+(.+)$/u', $block['text'], $split ) ) {
				if ( '' !== trim( $split[1] ) ) {
					$out[] = self::paragraph( trim( $split[1] ) );
				}
				$out[]         = array(
					'type' => 'toc-heading',
					'text' => $split[2],
				);
				$block['text'] = $split[3];
			}

			$out[] = $block;
			$prev  = $block['type'];
		}

		return $out;
	}

	private static function paragraph( $text ) {
		return array(
			'type' => 'p',
			'text' => $text,
		);
	}

	private static function ends_sentence( $text ) {
		return in_array( mb_substr( rtrim( $text ), -1 ), self::TERMINAL_PUNCTUATION, true );
	}

	/**
	 * Drops the page number printed at the top or bottom of the page ("12",
	 * "- 12 -", "Page 12", "12 of 300"), which would otherwise become a loose
	 * paragraph or stick to the sentence the page cuts. Only the first and
	 * last lines are checked: a lone number mid-page is content.
	 *
	 * @param string $text Page text, one line per visual line break.
	 * @return string
	 */
	private static function drop_page_number_lines( $text ) {
		$pattern = '/^(?:p(?:á|a)g(?:ina)?\.?\s*)?[-–—]?\s*\d{1,4}\s*[-–—]?(?:\s*(?:de|of|\/)\s*\d{1,4})?$/iu';
		$lines   = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $text ) );

		foreach ( array( 'first', 'last' ) as $edge ) {
			$indexes = array_keys(
				array_filter(
					$lines,
					function ( $line ) {
						return '' !== trim( $line );
					}
				)
			);

			if ( ! $indexes ) {
				break;
			}

			$i = 'first' === $edge ? reset( $indexes ) : end( $indexes );
			if ( preg_match( $pattern, trim( $lines[ $i ] ) ) ) {
				unset( $lines[ $i ] );
			}
		}

		return implode( "\n", $lines );
	}

	private static function normalize( $text ) {
		return mb_strtolower( trim( preg_replace( '/\s+/u', ' ', $text ) ) );
	}

	/**
	 * Removes the title from the start of the paragraph, ignoring case and
	 * spacing, and returns the rest.
	 */
	private static function remove_prefix( $text, $title ) {
		$words = preg_split( '/\s+/u', trim( $title ), -1, PREG_SPLIT_NO_EMPTY );
		$rest  = preg_replace( '/\s+/u', ' ', trim( $text ) );

		foreach ( $words as $word ) {
			if ( 0 !== mb_stripos( $rest, $word ) ) {
				return trim( $rest );
			}
			$rest = ltrim( mb_substr( $rest, mb_strlen( $word ) ) );
		}

		return trim( $rest );
	}

	/**
	 * A PDF keeps text and images in separate lists, without saying between
	 * which paragraphs each image was. Each page's images go right after its
	 * text: on the right page, which is the reader's reference to the
	 * original.
	 *
	 * PDFs almost never carry an image description the library can read, so
	 * all images get the fallback text with the page number, and the notice
	 * says how many need describing.
	 *
	 * @param JIMCA_Pdf_Image_Extractor $extractor
	 * @param \Smalot\PdfParser\Page    $page
	 * @param int                       $page_number
	 * @return string
	 */
	private function render_page_images( JIMCA_Pdf_Image_Extractor $extractor, $page, $page_number ) {
		$html = '';

		/*
		 * Once a count, size, time or memory limit is reached, no more images
		 * are decoded, on this page or the next ones: decoding is the heavy
		 * part, and what brings servers down on books with many images.
		 */
		$stop = $this->image_store->stop_reason();

		if ( '' !== $stop ) {
			$this->image_store->note_stopped( $stop, $page_number );
			return '';
		}

		foreach ( $extractor->extract( $page ) as $position => $binary ) {
			$img = $this->image_store->add(
				$binary,
				null,
				sprintf(
					/* translators: 1: number of the image on the page, 2: page number */
					__( 'Image %1$d on page %2$d (no description)', 'jim-conversor-acessivel' ),
					$position + 1,
					$page_number
				)
			);

			if ( '' !== $img ) {
				$html .= '<figure class="jimca-figure">' . $img . '</figure>' . "\n";
			}
		}

		return $html;
	}

	/**
	 * PDFs have no paragraphs: text is placed by coordinates, and getText()
	 * returns one line per visual line break. Lines that do not end in final
	 * punctuation are joined into the same sentence; a paragraph closes when
	 * the joined text ends in final punctuation or at a blank line.
	 *
	 * @return string[] Paragraphs without inner line breaks.
	 */
	private function reconstruct_paragraphs( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		/*
		 * Printed table of contents ("3.4.4 EXTENDED DIAGRAM ........ 87"):
		 * each item ends in a page number, without final punctuation, so the
		 * whole table would become one paragraph. Text extraction also breaks
		 * these lines in the wrong place: the page number may fall to the next
		 * line, and the next section number ("3.5") may stick after it. The
		 * two replacements below move those breaks back.
		 */
		$leader = self::TOC_LEADER;
		$text   = preg_replace( '/(' . $leader . ')[ \t]*\n[ \t]*(\d{1,4})(?=\s|$)/u', '$1 $2', $text );
		$text   = preg_replace( '/(' . $leader . ')[ \t]*(\d{1,4})[ \t]+(?=\S)/u', "\$1 \$2\n", $text );

		$lines = explode( "\n", $text );

		$paragraphs = array();
		$buffer     = '';

		foreach ( $lines as $raw_line ) {
			$line = trim( $raw_line );

			if ( '' === $line ) {
				if ( '' !== $buffer ) {
					$paragraphs[] = $buffer;
					$buffer       = '';
				}
				continue;
			}

			$buffer = ( '' === $buffer ) ? $line : $buffer . ' ' . $line;

			$last_char = mb_substr( rtrim( $buffer ), -1 );
			if ( in_array( $last_char, self::TERMINAL_PUNCTUATION, true ) || preg_match( '/(?:' . $leader . ')\s*\d{1,4}$/u', $buffer ) ) {
				$paragraphs[] = $buffer;
				$buffer       = '';
			}
		}

		if ( '' !== $buffer ) {
			$paragraphs[] = $buffer;
		}

		return array_map(
			function ( $paragraph ) {
				return trim( preg_replace( '/\s+/u', ' ', $paragraph ) );
			},
			$paragraphs
		);
	}
}
