<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts Markdown (.md) to HTML without an external library.
 *
 * Hand-written because the plugin already depends on two heavy libraries
 * (PDF and DOCX): a small converter covering everyday Markdown avoids a third
 * dependency and keeps security under the plugin's control.
 *
 * Security: ALL text is escaped (esc_html) before any markup is applied, so
 * HTML written inside the .md never becomes HTML on the page. Links only
 * accept http, https, mailto and anchors (#). Markdown images are not loaded
 * (they point outside the file); they become their description as text. The
 * result still goes through wp_kses_post() in JIMCA_Converter::convert().
 *
 * Supported: headings (# and === / --- underlines), paragraphs, emphasis
 * (**bold**, *italic*, ~~strikethrough~~), `code`, fenced code blocks,
 * quotes (>), bulleted and numbered lists (nested by indentation),
 * horizontal rules, tables (|a|b|) and links. YAML front matter at the top
 * (--- ... ---) is dropped.
 */
class JIMCA_Markdown_Converter implements JIMCA_Converter_Interface {

	/** Maximum nesting of blocks (a quote inside a list...). */
	const MAX_DEPTH = 8;

	/** @var string[] `code` spans set aside while the rest is formatted. */
	private $code_spans = array();

	public function convert( $file_path ) {
		$contents = file_get_contents( $file_path );

		if ( false === $contents ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'Could not read the Markdown file.', 'jim-conversor-acessivel' ) );
		}

		$html = self::render( $contents );

		if ( '' === trim( $html ) ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'The Markdown file is empty.', 'jim-conversor-acessivel' ) );
		}

		return $html;
	}

	/**
	 * @param string $markdown Markdown text in any common encoding.
	 * @return string HTML, not yet passed through wp_kses_post().
	 */
	public static function render( $markdown ) {
		// Text files are often saved as Windows-1252 rather than UTF-8.
		if ( ! mb_check_encoding( $markdown, 'UTF-8' ) ) {
			$markdown = mb_convert_encoding( $markdown, 'UTF-8', 'Windows-1252' );
		}

		$markdown = preg_replace( '/^\xEF\xBB\xBF/', '', $markdown ); // BOM.
		$markdown = str_replace( array( "\r\n", "\r" ), "\n", $markdown );

		// YAML front matter (--- ... ---).
		$markdown = preg_replace( '/\A---\n.*?\n---[ \t]*(?:\n|\z)/s', '', $markdown, 1 );

		$converter = new self();

		return $converter->blocks( explode( "\n", $markdown ), 0 );
	}

	/**
	 * @param string[] $lines
	 * @param int      $depth
	 * @return string
	 */
	private function blocks( array $lines, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return '<p>' . $this->inline( implode( "\n", $lines ) ) . '</p>' . "\n";
		}

		$html = '';
		$i    = 0;
		$n    = count( $lines );

		while ( $i < $n ) {
			$line = $lines[ $i ];

			if ( '' === trim( $line ) ) {
				++$i;
				continue;
			}

			// Fenced code block.
			if ( preg_match( '/^\s{0,3}(`{3,}|~{3,})\s*([\w+-]*)/', $line, $m ) ) {
				$fence = $m[1][0];
				$size  = strlen( $m[1] );
				$code  = array();
				++$i;
				while ( $i < $n && ! preg_match( '/^\s{0,3}' . preg_quote( $fence, '/' ) . '{' . $size . ',}\s*$/', $lines[ $i ] ) ) {
					$code[] = $lines[ $i ];
					++$i;
				}
				++$i; // Closing fence.
				$html .= '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>' . "\n";
				continue;
			}

			// ATX heading (# ... ######).
			if ( preg_match( '/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m ) ) {
				$level = strlen( $m[1] );
				$html .= '<h' . $level . '>' . $this->inline( $m[2] ) . '</h' . $level . '>' . "\n";
				++$i;
				continue;
			}

			// Horizontal rule.
			if ( preg_match( '/^\s{0,3}([-*_])(?:\s*\1){2,}\s*$/', $line ) ) {
				$html .= "<hr>\n";
				++$i;
				continue;
			}

			// Quote.
			if ( preg_match( '/^\s{0,3}>/', $line ) ) {
				$quote = array();
				while ( $i < $n && preg_match( '/^\s{0,3}>\s?(.*)$/', $lines[ $i ], $m ) ) {
					$quote[] = $m[1];
					++$i;
				}
				$html .= '<blockquote>' . "\n" . $this->blocks( $quote, $depth + 1 ) . '</blockquote>' . "\n";
				continue;
			}

			// List.
			if ( preg_match( '/^(\s*)([-*+]|\d{1,9}[.)])\s+/', $line ) ) {
				$html .= $this->list_block( $lines, $i, $depth );
				continue;
			}

			// Table.
			if ( $i + 1 < $n && false !== strpos( $line, '|' ) && preg_match( '/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $lines[ $i + 1 ] ) ) {
				$html .= $this->table_block( $lines, $i );
				continue;
			}

			// Paragraph, or a heading underlined with === / ---.
			$para = array();
			while ( $i < $n && '' !== trim( $lines[ $i ] ) && ! $this->starts_block( $lines[ $i ], empty( $para ) ) ) {
				if ( $para && preg_match( '/^\s{0,3}(=+|-+)\s*$/', $lines[ $i ], $m ) ) {
					$level = ( '=' === $m[1][0] ) ? 1 : 2;
					$html .= '<h' . $level . '>' . $this->inline( implode( "\n", $para ) ) . '</h' . $level . '>' . "\n";
					$para  = array();
					++$i;
					continue 2;
				}
				$para[] = $lines[ $i ];
				++$i;
			}

			if ( $para ) {
				$html .= '<p>' . $this->inline( implode( "\n", $para ) ) . '</p>' . "\n";
			} else {
				++$i; // Never stay on the same line forever.
			}
		}

		return $html;
	}

	/**
	 * Does the line open a new block (and so end the paragraph)?
	 *
	 * @param string $line
	 * @param bool   $first First line of the paragraph.
	 */
	private function starts_block( $line, $first ) {
		if ( preg_match( '/^\s{0,3}(`{3,}|~{3,}|#{1,6}\s|>)/', $line ) ) {
			return true;
		}

		if ( preg_match( '/^\s{0,3}([-*_])(?:\s*\1){2,}\s*$/', $line ) ) {
			// "---" right under text underlines a heading; it is not a horizontal rule.
			return $first;
		}

		// A list interrupts a paragraph only with a bullet or a list numbered from 1 (CommonMark rule).
		return (bool) preg_match( '/^\s{0,3}([-*+]|1[.)])\s+/', $line );
	}

	/**
	 * @param string[] $lines
	 * @param int      $i     Current line (moved to the end of the list).
	 * @param int      $depth
	 * @return string
	 */
	private function list_block( array $lines, &$i, $depth ) {
		$n       = count( $lines );
		$first   = $lines[ $i ];
		$ordered = (bool) preg_match( '/^\s*\d{1,9}[.)]\s+/', $first );
		$base    = strlen( preg_replace( '/^(\s*).*$/', '$1', str_replace( "\t", '    ', $first ) ) );
		$items   = array();
		$current = null;

		while ( $i < $n ) {
			$line = str_replace( "\t", '    ', $lines[ $i ] );

			if ( '' === trim( $line ) ) {
				// A blank line keeps the list going only if the next line still belongs to it.
				$next = $i + 1 < $n ? str_replace( "\t", '    ', $lines[ $i + 1 ] ) : '';
				if ( '' !== trim( $next ) && ( strlen( $next ) - strlen( ltrim( $next ) ) > $base || preg_match( '/^\s{' . $base . '}([-*+]|\d{1,9}[.)])\s+/', $next ) ) ) {
					if ( null !== $current ) {
						$current[] = '';
					}
					++$i;
					continue;
				}
				break;
			}

			$indent = strlen( $line ) - strlen( ltrim( $line ) );

			if ( $indent <= $base + 1 && preg_match( '/^\s*([-*+]|\d{1,9}[.)])\s+(.*)$/', $line, $m ) ) {
				if ( $indent < $base ) {
					break;
				}
				// Bullets switching to numbers (or back) start another list.
				if ( $ordered !== (bool) preg_match( '/^\d/', $m[1] ) ) {
					break;
				}
				if ( null !== $current ) {
					$items[] = $current;
				}
				$current = array( $m[2] );
				++$i;
				continue;
			}

			if ( $indent > $base && null !== $current ) {
				$current[] = substr( $line, min( $indent, $base + 2 ) );
				++$i;
				continue;
			}

			// Unindented text right below an item: a "lazy" continuation line.
			if ( null !== $current && ! $this->starts_block( $line, false ) ) {
				$current[] = ltrim( $line );
				++$i;
				continue;
			}

			break;
		}

		if ( null !== $current ) {
			$items[] = $current;
		}

		$tag  = $ordered ? 'ol' : 'ul';
		$html = '<' . $tag . '>' . "\n";

		foreach ( $items as $item_lines ) {
			$html .= '<li>' . $this->list_item( $item_lines, $depth ) . '</li>' . "\n";
		}

		return $html . '</' . $tag . '>' . "\n";
	}

	/**
	 * A simple item is plain inline text; one with sublists or several
	 * paragraphs becomes blocks.
	 *
	 * @param string[] $item_lines
	 * @param int      $depth
	 * @return string
	 */
	private function list_item( array $item_lines, $depth ) {
		$has_nested = false;
		foreach ( array_slice( $item_lines, 1 ) as $line ) {
			if ( '' === trim( $line ) || preg_match( '/^\s*([-*+]|\d{1,9}[.)])\s+/', $line ) ) {
				$has_nested = true;
				break;
			}
		}

		if ( ! $has_nested ) {
			return $this->inline( implode( "\n", $item_lines ) );
		}

		$html = trim( $this->blocks( $item_lines, $depth + 1 ) );

		// One line followed by a sublist: drop the leading <p>.
		return preg_replace( '#^<p>(.*?)</p>\n?#s', '$1' . "\n", $html, 1 );
	}

	/**
	 * @param string[] $lines
	 * @param int      $i
	 * @return string
	 */
	private function table_block( array $lines, &$i ) {
		$n      = count( $lines );
		$header = $this->table_cells( $lines[ $i ] );
		$aligns = array();

		foreach ( $this->table_cells( $lines[ $i + 1 ] ) as $cell ) {
			$left     = ( ':' === substr( $cell, 0, 1 ) );
			$right    = ( ':' === substr( $cell, -1 ) );
			$aligns[] = ( $left && $right ) ? 'center' : ( $right ? 'right' : ( $left ? 'left' : '' ) );
		}

		$i   += 2;
		$html = '<table>' . "\n" . '<thead><tr>';

		foreach ( $header as $index => $cell ) {
			$html .= $this->table_cell( 'th', $cell, isset( $aligns[ $index ] ) ? $aligns[ $index ] : '' );
		}

		$html .= '</tr></thead>' . "\n" . '<tbody>' . "\n";

		while ( $i < $n && '' !== trim( $lines[ $i ] ) && false !== strpos( $lines[ $i ], '|' ) ) {
			$html .= '<tr>';
			foreach ( $this->table_cells( $lines[ $i ] ) as $index => $cell ) {
				$html .= $this->table_cell( 'td', $cell, isset( $aligns[ $index ] ) ? $aligns[ $index ] : '' );
			}
			$html .= '</tr>' . "\n";
			++$i;
		}

		return $html . '</tbody>' . "\n" . '</table>' . "\n";
	}

	private function table_cell( $tag, $text, $align ) {
		$style = $align ? ' style="text-align:' . $align . '"' : '';

		return '<' . $tag . $style . '>' . $this->inline( $text ) . '</' . $tag . '>';
	}

	/**
	 * @param string $line
	 * @return string[]
	 */
	private function table_cells( $line ) {
		$line = trim( $line );
		$line = preg_replace( '/^\|/', '', $line );
		$line = preg_replace( '/\|$/', '', $line );

		return array_map( 'trim', preg_split( '/(?<!\\\\)\|/', $line ) );
	}

	/**
	 * Inline formatting. The text is escaped first and markup is added after,
	 * so nothing from the file becomes HTML.
	 *
	 * @param string $text
	 * @return string
	 */
	private function inline( $text ) {
		$this->code_spans = array();

		// 1. Code spans, set aside before any other rule touches them.
		$text = preg_replace_callback(
			'/(`+)(.+?)\1/s',
			function ( $m ) {
				$this->code_spans[] = '<code>' . esc_html( trim( $m[2] ) ) . '</code>';
				return "\x1A" . ( count( $this->code_spans ) - 1 ) . "\x1A";
			},
			$text
		);

		$text = esc_html( $text );

		// 2. Images are not loaded; their description stays as text.
		$text = preg_replace_callback(
			'/!\[([^\]]*)\]\(([^)]*)\)/',
			function ( $m ) {
				$alt = trim( $m[1] );
				return '' === $alt ? '' : '[' . esc_html__( 'Image', 'jim-conversor-acessivel' ) . ': ' . $alt . ']';
			},
			$text
		);

		// 3. Links: [text](url "title") and <https://...>.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(\s*((?:[^\s()]|\([^\s()]*\))+)(?:\s+&quot;.*?&quot;)?\s*\)/',
			function ( $m ) {
				$url = esc_url( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ), array( 'http', 'https', 'mailto' ) );

				if ( '' === $url && 0 === strpos( $m[2], '#' ) ) {
					$url = esc_url( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ) );
				}

				return '' === $url ? $m[1] : '<a href="' . $url . '">' . $m[1] . '</a>';
			},
			$text
		);

		$text = preg_replace_callback(
			'/&lt;((?:https?:\/\/|mailto:)[^\s&]+)&gt;/',
			function ( $m ) {
				$url = esc_url( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), array( 'http', 'https', 'mailto' ) );

				return '' === $url ? $m[0] : '<a href="' . $url . '">' . $m[1] . '</a>';
			},
			$text
		);

		// 4. Emphasis.
		$text = preg_replace( '/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/s', '<strong>$2</strong>', $text );
		$text = preg_replace( '/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/s', '<em>$1</em>', $text );
		$text = preg_replace( '/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/s', '<em>$1</em>', $text );
		$text = preg_replace( '/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $text );

		// 5. Hard line break (two trailing spaces or a backslash).
		$text = preg_replace( '/(?: {2,}|\\\\)\n/', "<br>\n", $text );

		// 6. Put the code spans back.
		$spans = $this->code_spans;
		$text  = preg_replace_callback(
			'/\x1A(\d+)\x1A/',
			function ( $m ) use ( $spans ) {
				return isset( $spans[ (int) $m[1] ] ) ? $spans[ (int) $m[1] ] : '';
			},
			$text
		);

		return trim( $text );
	}
}
