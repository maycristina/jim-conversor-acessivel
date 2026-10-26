<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists in PDF text. A PDF has no list markup: an item is a line that starts
 * with a bullet or a number. Without this, the items of a list without final
 * punctuation are joined into one paragraph, and screen readers cannot
 * announce "list, 3 items".
 *
 * Only the start of a paragraph is looked at (see
 * JIMCA_Pdf_Converter::reconstruct_paragraphs(), which starts a new paragraph
 * at each item), and a candidate becomes a list only when it is confirmed by
 * its neighbours: at least two items in a row (or a lone "a)" right under a
 * numbered list), and numbers or letters in sequence. A numbered heading
 * alone ("1. Introduction") stays a paragraph.
 */
class JIMCA_Pdf_Lists {

	/**
	 * Bullet glyphs: the Unicode bullets, and the private-use characters that
	 * the Symbol and Wingdings fonts map theirs to (Word writes U+F0B7,
	 * U+F0A7, U+F0D8...). Dashes are left out on purpose: novels open
	 * dialogue lines with them.
	 */
	const BULLETS = '•◦▪▫■□●○‣⁃➢➣➤►▸▹➔→✓✔❖◆◇·\x{F000}-\x{F0FF}';

	/** Text before an item that may be a heading glued to it (no final punctuation): at most this long. */
	const MAX_LABEL_LENGTH = 60;

	/**
	 * @param string $line Start of a line or paragraph.
	 * @return array{kind: string, family: string, value: int, text: string}|null
	 *         kind: ul or ol. family: items of the same list share it. text:
	 *         the item text (bullets dropped; numbers kept, since the
	 *         read-aloud player reads the text, not the list numbering).
	 */
	public static function marker( $line ) {
		$line = trim( $line );

		if ( preg_match( '/^[' . self::BULLETS . ']\s*(\S.*)$/u', $line, $m ) ) {
			return array(
				'kind'   => 'ul',
				'family' => 'bullet',
				'value'  => 0,
				'text'   => $m[1],
			);
		}

		// "1." or "1)". Two digits at most: "2024. It was" is a year ending a sentence.
		if ( preg_match( '/^(\d{1,2})([.)])\s+\S/u', $line, $m ) ) {
			return array(
				'kind'   => 'ol',
				'family' => 'number' . $m[2],
				'value'  => (int) $m[1],
				'text'   => $line,
			);
		}

		// "a)" only: "A. Silva" is an initial.
		if ( preg_match( '/^([a-zA-Z])\)\s+\S/u', $line, $m ) ) {
			$lower = strtolower( $m[1] );

			return array(
				'kind'   => 'ol',
				'family' => $lower === $m[1] ? 'lower' : 'upper',
				'value'  => ord( $lower ) - ord( 'a' ) + 1,
				'text'   => $line,
			);
		}

		return null;
	}

	/**
	 * Whether a line with this marker starts a new paragraph, ending the
	 * text before it ($buffer). A bullet always does. A number does after
	 * another item, or after a short line that may be a heading; after
	 * running text it may be part of the sentence ("...in chapter\n2. Then").
	 *
	 * @param array  $marker  See marker().
	 * @param string $buffer  Text of the paragraph so far.
	 * @param bool   $in_item Whether that paragraph is a list item.
	 * @return bool
	 */
	public static function starts_item( array $marker, $buffer, $in_item ) {
		return 'ul' === $marker['kind'] || $in_item || mb_strlen( $buffer ) <= self::MAX_LABEL_LENGTH;
	}

	/**
	 * Whether a line without a marker continues the list item before it. A
	 * wrapped item goes on in lowercase; a capital letter starts the text
	 * after the list.
	 *
	 * @param string $line
	 * @return bool
	 */
	public static function continues_item( $line ) {
		return (bool) preg_match( '/^\p{Ll}/u', trim( $line ) );
	}

	/**
	 * Turns runs of 'li' blocks into 'list' blocks, or back into paragraphs
	 * when they are not confirmed.
	 *
	 * Lists are flat: a lettered sublist between items 2 and 3 becomes its own
	 * list, and item 3 a list that starts at 3. The read-aloud player reads
	 * each <li> with all its text, so a nested list would be read twice.
	 *
	 * @param array $blocks Blocks of a page; 'li' blocks carry 'text' (the
	 *                      whole paragraph) and 'marker' (see marker()).
	 * @return array The blocks, with 'list' blocks: kind (ul or ol) and items (texts).
	 */
	public static function group( array $blocks ) {
		$out = array();
		$run = array();

		foreach ( $blocks as $block ) {
			if ( 'li' === $block['type'] ) {
				$run[] = $block;
				continue;
			}

			$out = array_merge( $out, self::confirm( $run ) );
			$run = array();
			$out[] = $block;
		}

		return array_merge( $out, self::confirm( $run ) );
	}

	/**
	 * @param array $run Consecutive 'li' blocks.
	 * @return array
	 */
	private static function confirm( array $run ) {
		$out      = array();
		$last     = array(); // Family => last value seen in this run.
		$numbered = false;   // Whether the group just before is a confirmed numbered list.

		foreach ( self::by_family( $run ) as $items ) {
			$family  = $items[0]['marker']['family'];
			$values  = array_column( array_column( $items, 'marker' ), 'value' );
			$follows = isset( $last[ $family ] ) && $last[ $family ] + 1 === $values[0];
			// A lone "a)" right under a numbered item is a sub-item, not a stray letter.
			$sub_item = $numbered && 1 === $values[0] && in_array( $family, array( 'lower', 'upper' ), true );
			$ok       = count( $items ) >= 2 || $follows || $sub_item;

			if ( 'ol' === $items[0]['marker']['kind'] ) {
				for ( $i = 1, $n = count( $values ); $i < $n && $ok; $i++ ) {
					$ok = $values[ $i - 1 ] + 1 === $values[ $i ];
				}
				$last[ $family ] = end( $values );
			}

			$numbered = $ok && 0 === strpos( $family, 'number' );

			if ( ! $ok ) {
				foreach ( $items as $item ) {
					$out[] = array(
						'type' => 'p',
						'text' => $item['text'],
					);
				}
				continue;
			}

			$out[] = array(
				'type'  => 'list',
				'kind'  => $items[0]['marker']['kind'],
				'items' => array_column( array_column( $items, 'marker' ), 'text' ),
			);
		}

		return $out;
	}

	/**
	 * @param array $run
	 * @return array[] The run cut where the family changes.
	 */
	private static function by_family( array $run ) {
		$groups = array();
		$prev   = null;

		foreach ( $run as $block ) {
			if ( $block['marker']['family'] !== $prev ) {
				$groups[] = array();
				$prev     = $block['marker']['family'];
			}
			$groups[ count( $groups ) - 1 ][] = $block;
		}

		return $groups;
	}
}
