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

	/** @var bool Read pages set in two columns column by column (see JIMCA_Pdf_Layout). */
	private $detect_columns;

	/**
	 * @var array{mode: string, probed: int} Column detection across the
	 * document: 'probe' (looking), 'on' (columns found: every page is
	 * checked) or 'off' (none in the first pages: not checked any more).
	 */
	private $columns = array(
		'mode'   => 'probe',
		'probed' => 0,
	);

	/**
	 * Pages with text checked for columns before giving up. Reading the
	 * positions doubles or triples the time of a page, which a book in one
	 * column should not pay: layouts rarely change after the first pages.
	 */
	const COLUMN_PROBE_PAGES = 5;

	/** @var string Language subtag of the text (pt, en, ru...), '' while unknown: turns the language's hyphen rules on (see keeps_hyphen()). */
	private $language = '';

	/**
	 * @var string|null Text of the first pages, from which the language is
	 * learned (see learn_language()); null once it is settled.
	 */
	private $language_sample = '';

	/** Characters of the first pages read before a recognised language is settled (unrecognised: up to JIMCA_Language::SAMPLE). */
	const LANGUAGE_SAMPLE = 3000;

	/**
	 * Punctuation that ends a paragraph (see reconstruct_paragraphs()),
	 * including the Chinese and Japanese full stops and marks and the
	 * Devanagari danda.
	 */
	const TERMINAL_PUNCTUATION = array( '.', '!', '?', ':', '"', '”', '»', '。', '！', '？', '」', '』', '।', '॥' );

	/**
	 * Han, kana and CJK punctuation (listed, since PCRE versions differ on
	 * whether \p{Han} covers "。"): lines of these scripts join without a
	 * space, as the text has none.
	 */
	const CJK = '\p{Han}\p{Hiragana}\p{Katakana}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}';

	/**
	 * Dot leader of a printed table of contents: 4 or more dots (spaced or
	 * not), repeated "…" or underscores. An ordinary ellipsis ("...") has only
	 * 3 dots and does not match.
	 */
	const TOC_LEADER = '(?:\.[ \t]?){4,}|…{2,}|_{4,}';

	/**
	 * Typographic ligatures (LaTeX, InDesign): one character for "fi". Kept,
	 * "ﬁcção" is not found by a search for "ficção" and some voices stumble
	 * on it.
	 */
	const LIGATURES = array(
		"\u{FB00}" => 'ff',
		"\u{FB01}" => 'fi',
		"\u{FB02}" => 'fl',
		"\u{FB03}" => 'ffi',
		"\u{FB04}" => 'ffl',
	);

	/**
	 * Enclitic pronouns: in "chama-" + "se" the hyphen is part of the word,
	 * not a line break inside it. Not "lhes": it is also the last syllable
	 * of "detalhes".
	 */
	const ENCLITICS = 'se|me|te|lhe|nos';

	/**
	 * Pronouns that follow a verb that lost its final r, s or z
	 * ("encontrá-lo", "fazê-la", "assisti-los", "fazemo-lo"): only after
	 * that ending. Otherwise it is a cut word: "capítu-" + "los".
	 */
	const VERB_ENCLITICS = 'lo|la|los|las';
	const VERB_ENDING    = '(?:[áâéêíóô]|i|mo)';

	/**
	 * Pronouns kept only after a gerund ("executando-os", "fazendo-a"): no
	 * Portuguese word ends in "ndoo", "ndoa", "ndoos" or "ndoas".
	 */
	const GERUND_ENCLITICS = 'os|as|o|a';

	/**
	 * Prefixes always written with a hyphen (pós-graduação, guarda-chuva).
	 * Not "ex-": every word starting with "ex" cut right after it
	 * ("ex-" + "plícitas") would keep the hyphen.
	 */
	const HYPHEN_PREFIXES = array( 'guarda', 'pós', 'pré', 'vice', 'recém' );

	/**
	 * Words after a suspended hyphen: "pré- e pós-operatório", "micro- ou
	 * macroeconomia", "pre- and post-war", "Ein- und Ausgang". A one-letter
	 * word ("e", "y", "и") is never joined anyway.
	 */
	const SUSPENDED_HYPHEN = 'ou|nem|ni|and|or|et|und|oder|или';

	/**
	 * Russian particles after a hyphen, each only after the words it goes
	 * with: "-то" and "-ка" are also the last syllables of "лето" and "рука".
	 * Particle => pattern of the word before the hyphen.
	 */
	const RU_PARTICLES = array(
		'то'     => '(?:кто|что|где|куда|откуда|как|когда|почему|зачем|чей|чья|чьё|чьи|сколько|кого|кому|кем|ком|чего|чему|чем|как\p{L}+|чь\p{L}+)',
		'либо'   => '(?:кто|что|где|куда|откуда|как|когда|почему|зачем|чей|чья|чьё|чьи|сколько|кого|кому|кем|ком|чего|чему|чем|как\p{L}+|чь\p{L}+)',
		'нибудь' => '(?:кто|что|где|куда|откуда|как|когда|почему|зачем|чей|чья|чьё|чьи|сколько|кого|кому|кем|ком|чего|чему|чем|как\p{L}+|чь\p{L}+)',
		'таки'   => '(?:всё|все|так|опять|довольно|верно)',
		'ка'     => '\p{L}+(?:й|и|ите|йте)',
	);

	/**
	 * Russian prefixes kept with a hyphen, each only before what it goes
	 * with ("по-русски", "по-моему", "из-за", "из-под"): "по-" + "лучить" is
	 * "получить". Prefix => pattern of the text after the hyphen.
	 */
	const RU_PREFIXES = array(
		'по' => '\p{L}*(?:ски|цки|ому|ему|ьи)(?![\p{L}\p{M}])',
		'из' => '(?:за|под)(?![\p{L}\p{M}])',
	);

	/**
	 * Prefixes written with a hyphen only before "h" or the vowel they end
	 * in (auto-observação, anti-inflamatório; but automóvel, antigo).
	 */
	const VOWEL_PREFIXES = array( 'auto', 'anti' );

	/**
	 * @param JIMCA_Image_Store|null $image_store
	 * @param array                  $options     'page_markers' => bool (default true), 'progress' => callable|null,
	 *                                            'columns' => bool (default true), 'language' => BCP-47 tag
	 *                                            chosen for the document ('' = detected from the text).
	 */
	public function __construct( $image_store = null, array $options = array() ) {
		$this->image_store       = $image_store;
		$this->show_page_markers = ! isset( $options['page_markers'] ) || (bool) $options['page_markers'];
		$this->progress          = isset( $options['progress'] ) && is_callable( $options['progress'] ) ? $options['progress'] : null;
		$this->detect_columns    = ! isset( $options['columns'] ) || (bool) $options['columns'];
		$this->language          = isset( $options['language'] ) ? JIMCA_Language::primary( $options['language'] ) : '';

		// Chosen on import: nothing to learn.
		if ( '' !== $this->language ) {
			$this->language_sample = null;
		}
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
			// Font sizes in getDataTm(): the column detection measures text with them.
			$config = new \Smalot\PdfParser\Config();
			$config->setDataTmFontInfoHasToBeIncluded( true );

			$parser  = new PdfParser( array(), $config );
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

		if ( isset( $state['columns'] ) ) {
			$this->columns = $state['columns'];
		}

		// array_key_exists(): a settled language travels with a null sample, which isset() would miss.
		if ( null !== $this->language_sample && array_key_exists( 'language_sample', $state ) ) {
			$this->language        = isset( $state['language'] ) ? (string) $state['language'] : '';
			$this->language_sample = $state['language_sample'];
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
					$blocks[0]['text'] = trim( self::join_lines( $carry, $blocks[0]['text'], $this->language ) );
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
						'columns'      => $this->columns,
						'language'        => $this->language,
						'language_sample' => $this->language_sample,
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
		$text = self::drop_page_number_lines( $this->page_text( $page ) );

		return $this->page_blocks(
			$this->reconstruct_paragraphs( $text ),
			isset( $outline[ $index ] ) ? $outline[ $index ] : array()
		);
	}

	/**
	 * The page text in reading order: getText(), or the columns read one
	 * after the other when JIMCA_Pdf_Layout finds them mixed up.
	 *
	 * @param \Smalot\PdfParser\Page $page
	 * @return string
	 */
	private function page_text( $page ) {
		$text = $page->getText();

		if ( $this->detect_columns && 'off' !== $this->columns['mode'] ) {
			$ordered = JIMCA_Pdf_Layout::page_text( $page, $text );

			if ( null !== $ordered ) {
				$this->columns['mode'] = 'on';
				$text                  = $ordered;
			} elseif ( 'probe' === $this->columns['mode'] && mb_strlen( trim( $text ) ) > 200 && ++$this->columns['probed'] >= self::COLUMN_PROBE_PAGES ) {
				// Covers, blank and image-only pages say nothing about the layout.
				$this->columns['mode'] = 'off';
			}
		}

		$text = strtr( $text, self::LIGATURES );
		$this->learn_language( $text );

		return $text;
	}

	/**
	 * Learns the language from the first pages, unless it was chosen on
	 * import. Until LANGUAGE_SAMPLE characters are read, each page updates
	 * the guess: a book in Portuguese that opens with a copyright page in
	 * English is not settled as English by that page.
	 *
	 * @param string $text Text of a page.
	 */
	private function learn_language( $text ) {
		if ( null === $this->language_sample ) {
			return;
		}

		$joined = trim( $this->language_sample . ' ' . $text );
		$first  = mb_substr( $joined, 0, self::LANGUAGE_SAMPLE );

		$this->language = JIMCA_Language::primary( JIMCA_Language::detect( $first ) );

		if ( '' !== $this->language ) {
			$this->language_sample = mb_strlen( $first ) >= self::LANGUAGE_SAMPLE ? null : $first;
			return;
		}

		/*
		 * Not recognised in the first LANGUAGE_SAMPLE characters: reading goes
		 * on, up to JIMCA_Language::SAMPLE. The first pages may be a cover and
		 * a table of contents in capitals ("CAPÍTULO II – DA DEFESA"), and
		 * settling on "unknown" there turned the Portuguese rules off for the
		 * whole document.
		 */
		$this->language_sample = mb_substr( $joined, 0, JIMCA_Language::SAMPLE );
		$this->language        = JIMCA_Language::primary( JIMCA_Language::detect( $this->language_sample ) );

		if ( '' !== $this->language || mb_strlen( $this->language_sample ) >= JIMCA_Language::SAMPLE ) {
			$this->language_sample = null;
		}
	}

	/**
	 * Joins two lines of the same paragraph.
	 *
	 * - Chinese and Japanese: no space between the lines (the text has none).
	 * - A hyphen at the end of the line before a capital letter stays, with
	 *   no space: "Software-" + "Entwicklung", "Rio-" + "Grande".
	 * - A word cut at the end of the line ("acessi-" + "bilidade") is joined
	 *   without the hyphen, unless it belongs to the word (see
	 *   keeps_hyphen()). Only when the next line goes on in lowercase.
	 *
	 * A compound cut exactly at its hyphen ("bem-" + "vindo") loses it: there
	 * is no dictionary to tell it from a cut word.
	 *
	 * @param string $before
	 * @param string $after
	 * @param string $language Language subtag of the text ('' = unknown).
	 * @return string
	 */
	private static function join_lines( $before, $after, $language ) {
		if ( preg_match( '/[' . self::CJK . ']$/u', $before ) && preg_match( '/^[' . self::CJK . ']/u', $after ) ) {
			return $before . $after;
		}

		if ( ! preg_match( '/([\p{L}\p{M}]+)-$/u', $before, $match ) ) {
			return $before . ' ' . $after;
		}

		if ( preg_match( '/^\p{Lu}/u', $after ) ) {
			return $before . $after;
		}

		if ( ! preg_match( '/^\p{Ll}/u', $after ) ) {
			return $before . ' ' . $after;
		}

		return ( self::keeps_hyphen( $match[1], $after, $language ) ? $before : mb_substr( $before, 0, -1 ) ) . $after;
	}

	/**
	 * A word cut by a hyphen that smalot/pdfparser already put on the same
	 * line as its end, with a space between ("desen- volvedor"): joined like
	 * a cut at the end of the line (see join_lines()). Not before "ou",
	 * "nem"... (see SUSPENDED_HYPHEN), nor before a capital letter, nor
	 * before a one-letter word except the Portuguese pronoun after a gerund
	 * ("fazendo- a"): "pré- e pós-" stays.
	 *
	 * @param string $line
	 * @param string $language Language subtag of the text ('' = unknown).
	 * @return string
	 */
	private static function join_cut_words( $line, $language ) {
		if ( false === strpos( $line, '- ' ) && false === strpos( $line, "-\t" ) ) {
			return $line;
		}

		return (string) preg_replace_callback(
			'/([\p{L}\p{M}]+)-[ \t]+(?!(?:' . self::SUSPENDED_HYPHEN . ')(?![\p{L}\p{M}]))(?=\p{Ll}[\p{Ll}\p{M}]|[oa](?![\p{L}\p{M}]))/u',
			static function ( $match ) use ( $line, $language ) {
				$after = substr( $line, $match[0][1] + strlen( $match[0][0] ) );

				// A one-letter word is only touched as the pronoun of a Portuguese gerund.
				if ( preg_match( '/^[oa](?![\p{L}\p{M}])/u', $after ) && ! ( 'pt' === $language && preg_match( '/ndo$/u', $match[1][0] ) ) ) {
					return $match[0][0];
				}

				return $match[1][0] . ( self::keeps_hyphen( $match[1][0], $after, $language ) ? '-' : '' );
			},
			$line,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);
	}

	/**
	 * Whether the hyphen between a word and the text after it belongs to the
	 * word. In any language, after a single letter ("e-mail"). In
	 * Portuguese, before an enclitic pronoun ("chama-se", "encontrá-lo") or
	 * after a hyphenated prefix ("pós-graduação"). In Russian, before a
	 * particle ("кто-то") or after a prefix ("по-русски", "из-за"). The
	 * language rules run only in their language: in English "welco-" + "me"
	 * is "welcome", in Spanish "mode-" + "lo" is "modelo".
	 *
	 * @param string $word     The letters right before the hyphen.
	 * @param string $after    The text right after it.
	 * @param string $language Language subtag of the text ('' = unknown).
	 * @return bool
	 */
	private static function keeps_hyphen( $word, $after, $language ) {
		$word = mb_strtolower( $word );

		// Hyphenation never leaves one letter before the break: "e-" + "mail" is "e-mail".
		if ( 1 === mb_strlen( $word ) ) {
			return true;
		}

		if ( 'ru' === $language ) {
			return self::keeps_russian_hyphen( $word, $after );
		}

		if ( 'pt' !== $language ) {
			return false;
		}

		// No verb of two letters without an accent takes a pronoun ("dá-nos", "vê-se" have one): "me-" + "nos" is "menos".
		$verb = mb_strlen( $word ) > 2 || preg_match( '/[áâéêíóô]/u', $word );
		$end  = '(?![\p{L}\p{M}])';

		return ( $verb && preg_match( '/^(?:' . self::ENCLITICS . ')' . $end . '/u', $after ) )
			|| ( preg_match( '/^(?:' . self::VERB_ENCLITICS . ')' . $end . '/u', $after ) && preg_match( '/' . self::VERB_ENDING . '$/u', $word ) )
			|| ( preg_match( '/^(?:' . self::GERUND_ENCLITICS . ')' . $end . '/u', $after ) && preg_match( '/ndo$/u', $word ) )
			|| in_array( $word, self::HYPHEN_PREFIXES, true )
			|| ( in_array( $word, self::VOWEL_PREFIXES, true ) && preg_match( '/^(?:h|' . mb_substr( $word, -1 ) . ')/u', $after ) );
	}

	/**
	 * @param string $word  Lowercase letters right before the hyphen.
	 * @param string $after The text right after it.
	 * @return bool See keeps_hyphen().
	 */
	private static function keeps_russian_hyphen( $word, $after ) {
		foreach ( self::RU_PARTICLES as $particle => $before ) {
			if ( preg_match( '/^' . $particle . '(?![\p{L}\p{M}])/u', $after ) && preg_match( '/^' . $before . '$/u', $word ) ) {
				return true;
			}
		}

		return isset( self::RU_PREFIXES[ $word ] ) && preg_match( '/^' . self::RU_PREFIXES[ $word ] . '/u', $after );
	}

	/**
	 * Merges the page text with the outline headings that start on it.
	 *
	 * The outline gives the page, not the line. But the heading is almost
	 * always printed on the page too: when a paragraph starts with the heading
	 * text, it becomes the heading right there (the rest of the paragraph, if
	 * any, stays as text). A heading not found goes to the top of the page.
	 *
	 * Lists are confirmed first: a numbered heading taken for a list item
	 * ("1. Introduction" alone) is a paragraph again by then, and can match
	 * its outline entry.
	 *
	 * @param string[] $paragraphs
	 * @param array    $entries    Outline entries of this page (see JIMCA_Pdf_Outline).
	 * @return array<int, array{type: string, text?: string, level?: int, kind?: string, items?: string[]}>
	 */
	private function page_blocks( array $paragraphs, array $entries ) {
		$blocks = array();
		foreach ( $paragraphs as $paragraph ) {
			$blocks = array_merge( $blocks, self::blocks_for( $paragraph ) );
		}
		$blocks = JIMCA_Pdf_Lists::group( self::split_toc_heading( $blocks ) );
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

			if ( 'list' === $block['type'] ) {
				/*
				 * Numbered items keep their number in the text (the read-aloud
				 * player reads text, not list numbering), so the CSS hides the
				 * list's own number. role="list": Safari drops the list
				 * semantics of a list without markers.
				 */
				$tag   = $block['kind'];
				$html .= 'ol' === $tag ? '<ol class="jimca-pdf-list jimca-pdf-list--numbered" role="list">' : '<ul class="jimca-pdf-list">';
				$html .= "\n";
				foreach ( $block['items'] as $item ) {
					$html .= '<li>' . esc_html( $item ) . '</li>' . "\n";
				}
				$html .= '</' . $tag . '>' . "\n";
			} elseif ( 'toc-heading' === $block['type'] ) {
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
	 * A paragraph, a printed contents item ("title ..... 87") or a list item
	 * candidate (see JIMCA_Pdf_Lists::group()).
	 *
	 * @param string $text
	 * @return array[]
	 */
	private static function blocks_for( $text ) {
		// The title may be missing: the item's text ended the previous page.
		if ( ! preg_match( '/^(.*?)\s*(?:' . self::TOC_LEADER . ')\s*(\d{1,4})$/u', $text, $match ) ) {
			$marker = JIMCA_Pdf_Lists::marker( $text );
			if ( null !== $marker ) {
				return array(
					array(
						'type'   => 'li',
						'text'   => $text,
						'marker' => $marker,
					),
				);
			}

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
	 * List items have no final punctuation either, so a line that starts with
	 * a bullet or a number starts a new paragraph (see JIMCA_Pdf_Lists), and
	 * an item ends at the next line that does not continue it in lowercase.
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
		$in_item    = false;

		foreach ( $lines as $raw_line ) {
			$line = self::join_cut_words( trim( $raw_line ), $this->language );

			if ( '' === $line ) {
				if ( '' !== $buffer ) {
					$paragraphs[] = $buffer;
					$buffer       = '';
				}
				continue;
			}

			$marker = JIMCA_Pdf_Lists::marker( $line );

			if ( '' !== $buffer && (
				( null !== $marker && JIMCA_Pdf_Lists::starts_item( $marker, $buffer, $in_item ) )
				|| ( $in_item && null === $marker && ! JIMCA_Pdf_Lists::continues_item( $line ) )
			) ) {
				$paragraphs[] = $buffer;
				$buffer       = '';
			}

			if ( '' === $buffer ) {
				$in_item = null !== $marker;
			}

			$buffer = ( '' === $buffer ) ? $line : self::join_lines( $buffer, $line, $this->language );

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
