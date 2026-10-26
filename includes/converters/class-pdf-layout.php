<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reading order of a PDF page set in two columns.
 *
 * getText() returns the text in the order of the content stream. Some
 * programs (Word, Canva) write a two-column page line by line across the
 * page: line 1 of the left column, line 1 of the right column, and so on. Read
 * in that order, each sentence is cut every half line by a piece of the other
 * column, and the read-aloud player speaks nonsense. (Programs that write
 * the left column first and then the right one are read correctly already.)
 *
 * Here the position of each piece of text (getDataTm()) is used to find a
 * second column. The page is only reordered when all of these hold, so that
 * pages getText() reads well are never touched:
 *
 * - a gap free of text runs down the page, with a right column whose lines
 *   start at the same x, beside lines that also have text on the left;
 * - both sides look like running text: long lines, mostly letters, aligned on
 *   a left margin (not table cells, dot leaders or page numbers);
 * - the content stream jumps between the two sides line after line, which is
 *   what mixes them up in getText().
 *
 * Then the page is read down the left column and down the right one. Lines
 * that span both columns (title, abstract) or sit apart from them (header,
 * page number) keep their place.
 */
class JIMCA_Pdf_Layout {

	/**
	 * Average glyph width, in em, to estimate where a piece of text ends: the
	 * PDF gives where it starts, and smalot/pdfparser has no widths for most
	 * embedded (CID) fonts. Only used for gaps of an em or more.
	 */
	const CHAR_WIDTH = 0.5;

	/** A horizontal gap of this many em splits a line into segments (a word space is about a quarter em). */
	const SEGMENT_GAP = 1.0;

	/** Lines with a segment starting at the second margin, beside text on the left, needed to call it a column. */
	const MIN_SPLIT_LINES = 4;

	/**
	 * Median characters per line on each side. Running text has long lines;
	 * a table drawn as loose text (name, city, number) has short cells.
	 */
	const MIN_COLUMN_CHARS = 20;

	/** Share of letters among the characters of each side: dot leaders and numbers are not running text. */
	const MIN_LETTER_SHARE = 0.6;

	/** Share of the lines of each side that start at its left margin. */
	const MIN_ALIGNED_SHARE = 0.6;

	/** Lines further apart than this many times the usual spacing are not part of the columns (header, footer). */
	const ZONE_GAP = 2.5;

	/** A vertical gap this many times the usual spacing ends a paragraph. */
	const PARAGRAPH_GAP = 1.6;

	/** getDataTm() must give at least this share of the letters of getText(). */
	const MIN_TEXT_SHARE = 0.9;

	/** Share of one-letter pieces above which a page places letters one by one (see join_runs()). */
	const GLYPH_SHARE = 0.8;

	/** @var bool Whether the page being read places letters one by one. */
	private static $glyphs = false;

	/**
	 * @param \Smalot\PdfParser\Page $page
	 * @param string                 $plain The page's getText().
	 * @return string|null The text in reading order, one line per visual line; null to keep $plain.
	 */
	public static function page_text( $page, $plain ) {
		try {
			$text = self::reading_order( self::runs( $page->getDataTm() ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		// getDataTm() skips text it cannot place (inside form XObjects, for example): order is never traded for text.
		if ( null === $text || self::letters( $text ) < self::MIN_TEXT_SHARE * self::letters( $plain ) ) {
			return null;
		}

		return $text;
	}

	/**
	 * Pieces of text with their position, in content stream order, from
	 * getDataTm(): each item is [text matrix, text] or, when the parser
	 * includes font data, [text matrix, text, font id, font size].
	 *
	 * Pieces made only of spaces are kept: PDFs that place each letter on its
	 * own (Google Docs, Skia) write the spaces between words that way, and
	 * they are the only reliable word breaks there.
	 *
	 * @param array $data
	 * @return array<int, array{x: float, y: float, size: float, text: string, space: bool, order: int}>
	 */
	public static function runs( array $data ) {
		$runs = array();

		foreach ( $data as $item ) {
			if ( ! isset( $item[0], $item[1] ) || ! is_array( $item[0] ) || count( $item[0] ) < 6 ) {
				continue;
			}

			$tm   = array_map( 'floatval', array_values( $item[0] ) );
			$text = (string) $item[1];

			// Rotated or vertical text is not part of a column.
			if ( '' === $text || abs( $tm[1] ) > 0.01 || abs( $tm[2] ) > 0.01 ) {
				continue;
			}

			$size = abs( $tm[3] ) * ( isset( $item[3] ) ? (float) $item[3] : 1.0 );

			$runs[] = array(
				'x'     => $tm[4],
				'y'     => $tm[5],
				// A size of 1 means the font data was missing: assume body text.
				'size'  => $size >= 3 ? $size : 10.0,
				'text'  => $text,
				'space' => (bool) preg_match( '/^[\s\x{00A0}\x{200B}]*$/u', $text ),
				'order' => count( $runs ),
			);
		}

		return $runs;
	}

	/**
	 * @param array<int, array{x: float, y: float, size: float, text: string, space: bool, order: int}> $runs
	 * @return string|null Lines in reading order, or null when the page is not in two mixed-up columns.
	 */
	public static function reading_order( array $runs ) {
		$runs  = self::upright( $runs );
		$words = array_filter(
			$runs,
			static function ( $run ) {
				return ! $run['space'];
			}
		);
		$count = count( $words );

		if ( $count < 2 * self::MIN_SPLIT_LINES ) {
			return null;
		}

		$lengths      = array_count_values( array_map( 'mb_strlen', array_column( $words, 'text' ) ) );
		self::$glyphs = ( isset( $lengths[1] ) ? $lengths[1] : 0 ) >= self::GLYPH_SHARE * $count;

		$lines   = self::lines( $runs );
		$spacing = self::usual_spacing( $lines );
		$margin  = self::second_margin( $lines );

		if ( null === $margin || $spacing <= 0 ) {
			return null;
		}

		$zone = self::column_zone( $lines, $margin, $spacing );

		if ( null === $zone || ! self::looks_like_columns( $lines, $margin, $zone ) ) {
			return null;
		}

		return self::render( $lines, $margin, $zone, $spacing );
	}

	/**
	 * Makes y grow upwards, as in a plain PDF page. Some programs draw the
	 * page upside down and flip it back (y grows downwards): the content
	 * stream still goes down the page most of the time, which tells which
	 * way y runs.
	 *
	 * @param array $runs
	 * @return array
	 */
	private static function upright( array $runs ) {
		$up   = 0;
		$down = 0;
		$prev = null;

		foreach ( $runs as $run ) {
			if ( $run['space'] ) {
				continue;
			}
			if ( null !== $prev ) {
				if ( $run['y'] - $prev > 1 ) {
					++$up;
				} elseif ( $prev - $run['y'] > 1 ) {
					++$down;
				}
			}
			$prev = $run['y'];
		}

		if ( $up > $down ) {
			foreach ( $runs as &$run ) {
				$run['y'] = -$run['y'];
			}
			unset( $run );
		}

		return $runs;
	}

	/**
	 * Groups runs into visual lines, top to bottom, each line's runs left to
	 * right, with its segments: the runs that are not spaces, grouped where
	 * the gap between them is under an em.
	 *
	 * @param array $runs
	 * @return array<int, array{y: float, size: float, runs: array, segments: array}>
	 */
	private static function lines( array $runs ) {
		usort(
			$runs,
			static function ( $a, $b ) {
				return $b['y'] !== $a['y'] ? $b['y'] <=> $a['y'] : $a['x'] <=> $b['x'];
			}
		);

		$lines = array();
		$index = -1;

		foreach ( $runs as $run ) {
			if ( $index < 0 || abs( $lines[ $index ]['y'] - $run['y'] ) > max( 1.0, 0.3 * $run['size'] ) ) {
				$lines[] = array(
					'y'        => $run['y'],
					'size'     => $run['size'],
					'runs'     => array(),
					'segments' => array(),
				);
				++$index;
			}

			$lines[ $index ]['runs'][] = $run;
			if ( ! $run['space'] ) {
				$lines[ $index ]['size'] = max( $lines[ $index ]['size'], $run['size'] );
			}
		}

		foreach ( $lines as &$line ) {
			usort(
				$line['runs'],
				static function ( $a, $b ) {
					return $a['x'] <=> $b['x'];
				}
			);

			foreach ( $line['runs'] as $run ) {
				if ( $run['space'] ) {
					continue;
				}

				$last = count( $line['segments'] ) - 1;
				if ( $last < 0 || $run['x'] - $line['segments'][ $last ]['x1'] > self::SEGMENT_GAP * $line['size'] ) {
					$line['segments'][] = array(
						'x0' => $run['x'],
						'x1' => self::run_end( $run ),
					);
				} else {
					$line['segments'][ $last ]['x1'] = max( $line['segments'][ $last ]['x1'], self::run_end( $run ) );
				}
			}
		}
		unset( $line );

		return $lines;
	}

	/**
	 * The left margin of a second column: the x, in the middle half of the
	 * text, where most lines start a segment after another one.
	 *
	 * @param array $lines
	 * @return float|null A hair left of that x (runs at the margin are on the right side).
	 */
	private static function second_margin( array $lines ) {
		$min = INF;
		$max = -INF;
		foreach ( $lines as $line ) {
			foreach ( $line['segments'] as $segment ) {
				$min = min( $min, $segment['x0'] );
				$max = max( $max, $segment['x1'] );
			}
		}

		$width = $max - $min;
		if ( $width <= 0 ) {
			return null;
		}

		// Lines per starting x (rounded to the point): each line counts once per x.
		$starts = array();
		foreach ( $lines as $line ) {
			$seen = array();
			foreach ( array_slice( $line['segments'], 1 ) as $segment ) {
				$x = (int) round( $segment['x0'] );
				if ( $x > $min + 0.25 * $width && $x < $min + 0.75 * $width && ! isset( $seen[ $x ] ) ) {
					$seen[ $x ]   = true;
					$starts[ $x ] = isset( $starts[ $x ] ) ? $starts[ $x ] + 1 : 1;
				}
			}
		}

		list( $best, $count ) = self::mode( $starts );

		return $count >= self::MIN_SPLIT_LINES ? (float) $best - 2 : null;
	}

	/**
	 * First and last line of the columns: the lines around those with text on
	 * both sides of the margin, as long as the spacing between lines stays
	 * normal. A header or page number set apart stays out.
	 *
	 * @param array $lines
	 * @param float $margin
	 * @param float $spacing
	 * @return array{int, int}|null
	 */
	private static function column_zone( array $lines, $margin, $spacing ) {
		$first = null;
		$last  = null;

		foreach ( $lines as $i => $line ) {
			list( $left, $right ) = self::split( $line, $margin );
			if ( self::has_text( $left ) && self::has_text( $right ) && ! self::spans( $line, $margin ) ) {
				$first = null === $first ? $i : $first;
				$last  = $i;
			}
		}

		if ( null === $first ) {
			return null;
		}

		$limit = self::ZONE_GAP * $spacing;
		$end   = count( $lines ) - 1;
		while ( $first > 0 && $lines[ $first - 1 ]['y'] - $lines[ $first ]['y'] <= $limit && ! self::spans( $lines[ $first - 1 ], $margin ) ) {
			--$first;
		}
		while ( $last < $end && $lines[ $last ]['y'] - $lines[ $last + 1 ]['y'] <= $limit && ! self::spans( $lines[ $last + 1 ], $margin ) ) {
			++$last;
		}

		return array( $first, $last );
	}

	/**
	 * The checks that keep pages getText() reads well untouched (see the
	 * class comment).
	 *
	 * @param array           $lines
	 * @param float           $margin
	 * @param array{int, int} $zone
	 * @return bool
	 */
	private static function looks_like_columns( array $lines, $margin, array $zone ) {
		$sides  = array(
			'left'  => array(),
			'right' => array(),
		);
		$starts = array(
			'left'  => array(),
			'right' => array(),
		);
		$stream = array();

		for ( $i = $zone[0]; $i <= $zone[1]; $i++ ) {
			$line = $lines[ $i ];
			if ( self::spans( $line, $margin ) ) {
				continue;
			}

			// The gap: no segment may start in the em before the right column.
			foreach ( $line['segments'] as $segment ) {
				if ( $segment['x0'] < $margin && $segment['x0'] > $margin - self::SEGMENT_GAP * $line['size'] ) {
					return false;
				}
			}

			list( $left, $right ) = self::split( $line, $margin );
			foreach ( array( 'left' => $left, 'right' => $right ) as $side => $runs ) {
				if ( ! self::has_text( $runs ) ) {
					continue;
				}
				$sides[ $side ][] = self::join_runs( $runs );
				foreach ( $runs as $run ) {
					if ( ! $run['space'] ) {
						$starts[ $side ][] = (int) round( $run['x'] );
						break;
					}
				}
				foreach ( $runs as $run ) {
					if ( ! $run['space'] ) {
						$stream[ $run['order'] ] = $side;
					}
				}
			}
		}

		foreach ( array( 'left', 'right' ) as $side ) {
			$text = implode( '', $sides[ $side ] );
			$all  = (int) preg_match_all( '/\S/u', $text );

			if ( self::median( array_map( 'mb_strlen', $sides[ $side ] ) ) < self::MIN_COLUMN_CHARS
				|| self::letters( $text, false ) < self::MIN_LETTER_SHARE * $all ) {
				return false;
			}

			list( , $aligned ) = self::mode( array_count_values( $starts[ $side ] ) );
			if ( $aligned < self::MIN_ALIGNED_SHARE * count( $starts[ $side ] ) ) {
				return false;
			}
		}

		// Only a stream that goes back and forth between the sides mixes them up.
		ksort( $stream );
		$jumps = 0;
		$prev  = null;
		foreach ( $stream as $side ) {
			if ( null !== $prev && $side !== $prev ) {
				++$jumps;
			}
			$prev = $side;
		}

		return $jumps >= 2 * self::MIN_SPLIT_LINES;
	}

	/**
	 * Whether the line crosses the gap between the columns (a title or an
	 * abstract across the page): a segment starting left of the margin that
	 * ends well past it.
	 *
	 * @param array $line
	 * @param float $margin
	 * @return bool
	 */
	private static function spans( array $line, $margin ) {
		foreach ( $line['segments'] as $segment ) {
			if ( $segment['x0'] < $margin && $segment['x1'] > $margin + 2 * $line['size'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array $line
	 * @param float $margin
	 * @return array{array, array} Runs left and right of the margin.
	 */
	private static function split( array $line, $margin ) {
		$left  = array();
		$right = array();
		foreach ( $line['runs'] as $run ) {
			if ( $run['x'] < $margin ) {
				$left[] = $run;
			} else {
				$right[] = $run;
			}
		}

		return array( $left, $right );
	}

	/**
	 * Text in reading order: lines outside the columns as they come; inside,
	 * the left column then the right one. A blank line marks a paragraph
	 * break where the vertical gap is larger than usual, except between the
	 * two columns: a sentence often runs from the bottom of one to the top of
	 * the other.
	 *
	 * @param array           $lines
	 * @param float           $margin
	 * @param array{int, int} $zone
	 * @param float           $spacing
	 * @return string
	 */
	private static function render( array $lines, $margin, array $zone, $spacing ) {
		$segments = array();
		$left     = array();
		$right    = array();

		foreach ( $lines as $i => $line ) {
			if ( $i < $zone[0] || $i > $zone[1] || self::spans( $line, $margin ) ) {
				if ( $left || $right ) {
					$segments[] = array( 'lines' => $left, 'joined' => false );
					$segments[] = array( 'lines' => $right, 'joined' => true );
					$left       = array();
					$right      = array();
				}
				$segments[] = array(
					'lines'  => array( array( $line['y'], $line['runs'] ) ),
					'joined' => false,
				);
				continue;
			}

			list( $l, $r ) = self::split( $line, $margin );
			if ( self::has_text( $l ) ) {
				$left[] = array( $line['y'], $l );
			}
			if ( self::has_text( $r ) ) {
				$right[] = array( $line['y'], $r );
			}
		}

		if ( $left || $right ) {
			$segments[] = array( 'lines' => $left, 'joined' => false );
			$segments[] = array( 'lines' => $right, 'joined' => true );
		}

		$out    = array();
		$prev_y = null;
		$gap    = self::PARAGRAPH_GAP * $spacing;

		foreach ( $segments as $segment ) {
			foreach ( $segment['lines'] as $k => $entry ) {
				list( $y, $runs ) = $entry;
				$starts_column    = 0 === $k && $segment['joined'];

				if ( null !== $prev_y && ! $starts_column && $prev_y - $y > $gap ) {
					$out[] = '';
				}

				$out[]  = self::join_runs( $runs );
				$prev_y = $y;
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * Text of the runs of one line, left to right.
	 *
	 * On a page that places letters one by one, the pieces made of spaces are
	 * the word breaks: a gap between two letters says nothing, since the real
	 * width of each letter is unknown (an "m" is much wider than an "i"), and
	 * a line of one word has no space piece at all. Elsewhere a gap wider
	 * than a fraction of the font size is a word break.
	 *
	 * @param array $runs
	 * @return string
	 */
	private static function join_runs( array $runs ) {
		$explicit = self::$glyphs || in_array( true, array_column( $runs, 'space' ), true );
		$text     = '';
		$end      = null;

		foreach ( $runs as $run ) {
			$open = '' !== $text && ! preg_match( '/\s$/u', $text );

			if ( $run['space'] ) {
				$text .= $open ? ' ' : '';
				$end   = $run['x'];
				continue;
			}

			$limit = ( $explicit ? 0.8 : 0.15 ) * $run['size'];
			if ( $open && null !== $end && $run['x'] - $end > $limit && ! preg_match( '/^\s/u', $run['text'] ) ) {
				$text .= ' ';
			}
			$text .= $run['text'];
			$end   = self::run_end( $run );
		}

		return trim( preg_replace( '/[ \t]+/u', ' ', $text ) );
	}

	/**
	 * @param array $runs
	 * @return bool Whether any run is more than spaces.
	 */
	private static function has_text( array $runs ) {
		return in_array( false, array_column( $runs, 'space' ), true );
	}

	/**
	 * @param array{x: float, size: float, text: string} $run
	 * @return float Estimated x where the run ends.
	 */
	private static function run_end( array $run ) {
		return $run['x'] + mb_strlen( $run['text'] ) * $run['size'] * self::CHAR_WIDTH;
	}

	/**
	 * The most common value, counting values within 2 of each other together.
	 *
	 * @param array<int, int> $counts Value => count.
	 * @return array{int|null, int} The value and its count.
	 */
	private static function mode( array $counts ) {
		$best       = null;
		$best_count = 0;
		foreach ( array_keys( $counts ) as $x ) {
			$count = 0;
			for ( $d = -2; $d <= 2; $d++ ) {
				$count += isset( $counts[ $x + $d ] ) ? $counts[ $x + $d ] : 0;
			}
			if ( $count > $best_count ) {
				$best       = $x;
				$best_count = $count;
			}
		}

		return array( $best, $best_count );
	}

	/**
	 * Median vertical distance between consecutive lines.
	 *
	 * @param array $lines
	 * @return float
	 */
	private static function usual_spacing( array $lines ) {
		$gaps = array();
		for ( $i = 1, $n = count( $lines ); $i < $n; $i++ ) {
			$gaps[] = $lines[ $i - 1 ]['y'] - $lines[ $i ]['y'];
		}

		return self::median( $gaps );
	}

	/**
	 * @param array<int, float|int> $values
	 * @return float
	 */
	private static function median( array $values ) {
		if ( ! $values ) {
			return 0.0;
		}
		sort( $values );

		return (float) $values[ intdiv( count( $values ), 2 ) ];
	}

	/**
	 * @param string $text
	 * @param bool   $digits Count digits too.
	 * @return int
	 */
	private static function letters( $text, $digits = true ) {
		return (int) preg_match_all( $digits ? '/[\p{L}\p{N}]/u' : '/\p{L}/u', $text );
	}
}
