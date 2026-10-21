<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggestions drawn from the text of a document: author, title, summary,
 * and section lines that should be headings.
 *
 * Everything is read from the document itself with fixed patterns, so it
 * costs nothing and gives the same answer every time. The author, the title
 * and the summary are only suggestions (the person confirms them); the
 * author is also filled in at conversion when the text states it plainly
 * ("Author: ...").
 */
class JIMCA_Autofill {

	/** Characters of the start of the document that are read. */
	const SCAN_CHARS = 1500;

	/** Longest and shortest title that search engines show whole. */
	const TITLE_MIN = 10;
	const TITLE_MAX = 70;

	/** Summary lengths: a description for search results. */
	const SUMMARY_MIN = 70;
	const SUMMARY_MAX = 300;

	/**
	 * Lines of plain text from the start of the document.
	 *
	 * @param string $html Document HTML.
	 * @return string[]
	 */
	public static function lines( $html ) {
		$text = preg_replace( '#</(?:p|h[1-6]|li|div|tr)>|<br\s*/?>#i', "\n", (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$out  = array();
		$used = 0;

		foreach ( preg_split( '/\R/u', $text ) as $line ) {
			$line = trim( preg_replace( '/\s+/u', ' ', $line ) );

			if ( '' === $line ) {
				continue;
			}

			$out[] = $line;
			$used += mb_strlen( $line );

			if ( $used >= self::SCAN_CHARS || count( $out ) >= 40 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Value of a "Label: value" line in the first lines of the document.
	 *
	 * @param string[] $lines  See lines().
	 * @param string   $labels Regex alternatives for the label.
	 * @param int      $min    Shortest value.
	 * @param int      $max    Longest value.
	 * @return string '' when there is none.
	 */
	private static function labelled( array $lines, $labels, $min, $max ) {
		foreach ( $lines as $line ) {
			if ( preg_match( '/^(?:' . $labels . ')\s*[:\-–—]\s*(.{' . (int) $min . ',' . (int) $max . '})$/iu', $line, $match ) ) {
				return trim( $match[1], " \t,;" );
			}
		}

		return '';
	}

	/**
	 * Author written in the text: "Author: ...", "Autor: ...", "Written by ...".
	 * "By" and "Por" only count with a colon, or any sentence starting with
	 * them would be taken for an author.
	 *
	 * @param string $html Document HTML.
	 * @return string '' when the text does not say it.
	 */
	public static function detect_author( $html ) {
		$lines  = self::lines( $html );
		$author = self::labelled( $lines, 'Authors?|Autor(?:es|a|as)?|Autoria|Escrito por|Written by|Auteurs?|Autore|Verfasser', 3, 80 );

		if ( '' === $author ) {
			foreach ( $lines as $line ) {
				if ( preg_match( '/^(?:By|Por)\s*:\s*(.{3,80})$/iu', $line, $match ) ) {
					$author = trim( $match[1], " \t,;" );
					break;
				}
			}
		}

		$author = rtrim( $author, '.' );

		// A name has letters, and the account names ("user", "admin") are not authors.
		if ( '' === $author || ! preg_match( '/\p{L}{2}/u', $author ) || JIMCA_Metadata::is_generic_author( $author ) || preg_match( '/\d{3,}/', $author ) ) {
			return '';
		}

		return $author;
	}

	/**
	 * A title of 10 to 70 characters: the "Title: ..." line, else the first
	 * heading, else the first line.
	 *
	 * @param string $html    Document HTML.
	 * @param string $current Current title (not suggested again).
	 * @return string '' when nothing better than the current title is found.
	 */
	public static function detect_title( $html, $current = '' ) {
		$candidates = array();
		$lines      = self::lines( $html );

		$labelled = self::labelled( $lines, 'Title|T[ií]tulo|Documento|Titre|Titel', self::TITLE_MIN, 200 );
		if ( '' !== $labelled ) {
			$candidates[] = rtrim( $labelled, '.' );
		}

		if ( preg_match( '#<h[1-3]\b[^>]*>(.*?)</h[1-3]>#is', (string) $html, $heading ) ) {
			$candidates[] = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $heading[1] ), ENT_QUOTES, 'UTF-8' ) ) );
		}

		if ( $lines ) {
			$candidates[] = $lines[0];
		}

		foreach ( $candidates as $candidate ) {
			$title = self::fit_title( $candidate );

			if ( '' !== $title && $title !== trim( (string) $current ) ) {
				return $title;
			}
		}

		return '';
	}

	/**
	 * @param string $title Any text.
	 * @return string The text within 10 to 70 characters, cut at a whole word; '' when it cannot be.
	 */
	public static function fit_title( $title ) {
		$title = trim( preg_replace( '/\s+/u', ' ', (string) $title ) );

		if ( mb_strlen( $title ) > self::TITLE_MAX ) {
			$cut   = mb_substr( $title, 0, self::TITLE_MAX );
			$space = mb_strrpos( $cut, ' ' );
			$title = rtrim( false !== $space && $space >= self::TITLE_MIN ? mb_substr( $cut, 0, $space ) : $cut, " ,;:.-–—" );
		}

		return mb_strlen( $title ) >= self::TITLE_MIN ? $title : '';
	}

	/**
	 * A summary for search results: the "Summary: ..." line, else the first
	 * paragraph of real prose.
	 *
	 * @param string $html Document HTML.
	 * @return string '' when there is no text to take it from.
	 */
	public static function detect_summary( $html ) {
		$summary = self::labelled( self::lines( $html ), 'Summary|Abstract|Resumo|Sinopse|Resumen|R[ée]sum[ée]|Zusammenfassung', 30, self::SUMMARY_MAX );

		if ( '' === $summary && preg_match_all( '#<p\b[^>]*>(.*?)</p>#is', (string) $html, $paragraphs ) ) {
			foreach ( array_slice( $paragraphs[1], 0, 40 ) as $paragraph ) {
				$text = JIMCA_SEO::prose( $paragraph );

				if ( mb_strlen( $text ) >= self::SUMMARY_MIN ) {
					$summary = $text;
					break;
				}
			}
		}

		return '' === $summary ? '' : JIMCA_SEO::shorten( $summary, JIMCA_SEO::DESCRIPTION_CHARS );
	}

	/**
	 * Turns the lines that are section titles into headings: numbered lines
	 * ("1. Introduction", "2.1 Method"), "Chapter 3", lines in capitals, and
	 * short lines with no final punctuation that open a long paragraph.
	 * Gives up when too many lines look like headings (a poem, a list), since
	 * then they are probably not.
	 *
	 * @param string $html Document HTML.
	 * @return array{html: string, count: int}
	 */
	public static function fix_headings( $html ) {
		$html = (string) $html;

		if ( ! preg_match_all( '#<p\b[^>]*>(.*?)</p>#is', $html, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array(
				'html'  => $html,
				'count' => 0,
			);
		}

		$plain = array();
		foreach ( $found as $i => $match ) {
			$plain[ $i ] = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $match[1][0] ), ENT_QUOTES, 'UTF-8' ) ) );
		}

		$picked = array();

		foreach ( $found as $i => $match ) {
			$level = self::heading_level( $plain[ $i ], $match[1][0], isset( $plain[ $i + 1 ] ) ? $plain[ $i + 1 ] : '', $i > 0 ? $plain[ $i - 1 ] : '' );

			if ( $level ) {
				$picked[ $i ] = $level;
			}
		}

		// Too many "headings": it is a poem, a list or a script, not a book with chapters.
		if ( count( $picked ) > max( 3, (int) floor( count( $found ) * 0.4 ) ) ) {
			return array(
				'html'  => $html,
				'count' => 0,
			);
		}

		for ( $i = count( $found ) - 1; $i >= 0; $i-- ) {
			if ( ! isset( $picked[ $i ] ) ) {
				continue;
			}

			$whole  = $found[ $i ][0];
			$inner  = $found[ $i ][1][0];
			$tag    = 'h' . $picked[ $i ];
			$html   = substr_replace( $html, '<' . $tag . '>' . trim( $inner ) . '</' . $tag . '>', $whole[1], strlen( $whole[0] ) );
		}

		return array(
			'html'  => $html,
			'count' => count( $picked ),
		);
	}

	/**
	 * @param string $text     Plain text of the paragraph.
	 * @param string $inner    Its inner HTML.
	 * @param string $next     Plain text of the next paragraph.
	 * @param string $previous Plain text of the previous paragraph.
	 * @return int 0 when it is no heading, else 2 or 3.
	 */
	private static function heading_level( $text, $inner, $next, $previous ) {
		$length = mb_strlen( $text );

		// One line only, short, no sentence ending.
		if ( $length < 3 || $length > 80 || preg_match( '#<br\b#i', $inner ) || preg_match( '/[.,;:!?…]$/u', $text ) || ! preg_match( '/\p{L}/u', $text ) ) {
			return 0;
		}

		// "Chapter 3", "Part II", "Section 4".
		if ( preg_match( '/^(?:cap[ií]tulo|chapter|parte|part|se[cç][aã]o|section|livro|book|anexo|appendix|unidade|aula|chapitre)\s+[\p{L}\d.]+/iu', $text ) ) {
			return 2;
		}

		// "1. Introduction", "2.1 Method", "III. Results".
		if ( preg_match( '/^(\d{1,2}(?:\.\d{1,2}){0,3})[.)]?\s+\p{Lu}/u', $text, $number ) ) {
			return substr_count( $number[1], '.' ) >= 1 ? 3 : 2;
		}
		if ( preg_match( '/^[IVXLC]{1,6}[.)]\s+\p{Lu}/u', $text ) ) {
			return 2;
		}

		// A line in capitals.
		if ( $length <= 60 && preg_match_all( '/\p{L}/u', $text ) >= 3 && mb_strtoupper( $text ) === $text && mb_strtolower( $text ) !== $text ) {
			return 2;
		}

		// A short line that opens a long paragraph, after a paragraph that ended or at the start.
		if ( $length <= 50 && preg_match_all( '/\p{L}+/u', $text ) <= 8 && mb_strlen( $next ) >= 100 && ( '' === $previous || preg_match( '/[.!?…"”»)]$/u', $previous ) ) ) {
			return 2;
		}

		return 0;
	}
}
