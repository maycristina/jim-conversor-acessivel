<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-pagination.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * Long documents in parts and the search inside them.
 *
 * @covers JIMCA_Pagination
 */
class PaginationTest extends UnitTestCase {

	/** A book: $chapters chapters, each a heading and $paragraphs paragraphs. */
	private static function book( $chapters, $paragraphs, $width = 400 ) {
		$html = '';

		for ( $c = 1; $c <= $chapters; $c++ ) {
			$html .= '<h2 id="c' . $c . '">Capítulo ' . $c . '</h2>';

			for ( $p = 1; $p <= $paragraphs; $p++ ) {
				$html .= '<p>' . $c . '.' . $p . ' ' . str_repeat( 'palavra ', (int) ( $width / 8 ) ) . '</p>' . "\n";
			}
		}

		return $html;
	}

	public function test_a_short_document_stays_whole() {
		$html = self::book( 3, 5 );

		$this->assertSame( array( $html ), JIMCA_Pagination::split( $html ) );
	}

	public function test_the_parts_put_together_give_back_the_document() {
		$html  = self::book( 60, 60 );
		$parts = JIMCA_Pagination::split( $html );

		$this->assertGreaterThan( 3, count( $parts ) );
		$this->assertSame( $html, implode( '', $parts ) );
	}

	public function test_parts_are_cut_before_a_heading_and_have_a_bounded_size() {
		$parts = JIMCA_Pagination::split( self::book( 60, 60 ) );

		foreach ( $parts as $part ) {
			$this->assertLessThanOrEqual( JIMCA_Pagination::HARD_BYTES + 4000, strlen( $part ), 'no part grows past the hard limit (plus one block)' );
		}

		foreach ( array_slice( $parts, 1 ) as $part ) {
			$this->assertStringStartsWith( '<h2', ltrim( $part ), 'a part starts at a chapter while chapters are small' );
		}
	}

	public function test_a_block_is_never_cut_in_the_middle() {
		$list = '<ul>' . str_repeat( '<li>' . str_repeat( 'item ', 40 ) . '</li>', 800 ) . '</ul>';
		$html = self::book( 2, 5 ) . $list . self::book( 2, 5 );

		foreach ( JIMCA_Pagination::split( $html ) as $part ) {
			$this->assertSame( substr_count( $part, '<ul>' ), substr_count( $part, '</ul>' ), 'every part is balanced' );
			$this->assertSame( substr_count( $part, '<li>' ), substr_count( $part, '</li>' ) );
		}
	}

	public function test_long_text_without_headings_is_still_cut_at_paragraph_boundaries() {
		$html  = str_repeat( '<p>' . str_repeat( 'texto ', 100 ) . '</p>', 600 );
		$parts = JIMCA_Pagination::split( $html );

		$this->assertGreaterThan( 1, count( $parts ) );

		foreach ( $parts as $part ) {
			$this->assertSame( substr_count( $part, '<p>' ), substr_count( $part, '</p>' ) );
		}
	}

	public function test_void_elements_do_not_confuse_the_depth() {
		$html = str_repeat( '<p>linha<br>outra<br />linha</p><hr><p><img src="a.png" alt="x"></p>', 4000 );

		foreach ( JIMCA_Pagination::split( $html ) as $part ) {
			$this->assertSame( substr_count( $part, '<p>' ), substr_count( $part, '</p>' ) );
		}
	}

	public function test_long_documents_are_always_in_parts_and_short_ones_only_when_asked() {
		$this->assertTrue( JIMCA_Pagination::needed( JIMCA_Pagination::MANDATORY_BYTES, '' ) );
		$this->assertTrue( JIMCA_Pagination::needed( JIMCA_Pagination::MANDATORY_BYTES + 1, 'no' ), 'pages="no" does not undo the mandatory case' );
		$this->assertFalse( JIMCA_Pagination::needed( 100000, '' ) );
		$this->assertTrue( JIMCA_Pagination::needed( 200000, 'yes' ) );
		$this->assertFalse( JIMCA_Pagination::needed( 1000, 'yes' ), 'a very short text has nothing to split' );
	}

	public function test_the_title_of_a_part_is_its_first_heading() {
		$this->assertSame( 'Gênesis 1', JIMCA_Pagination::title_of( '<p>antes</p><h2 id="x">Gênesis <em>1</em></h2><h3>Outro</h3>' ) );
		$this->assertSame( '', JIMCA_Pagination::title_of( '<p>sem título</p>' ) );
	}

	public function test_blocks_are_the_text_of_paragraphs_items_and_headings() {
		$blocks = JIMCA_Pagination::blocks_of( '<h2>Título</h2><p>Um <b>texto</b>  com   espaços.</p><ul><li>Item um</li></ul><p>&nbsp;</p>' );

		$this->assertSame( array( 'Título', 'Um texto com espaços.', 'Item um' ), $blocks );
	}

	public function test_search_ignores_case_and_accents() {
		$blocks = array( 'No princípio Deus criou os céus', 'E disse MOISÉS ao povo', 'Nada aqui' );

		$this->assertCount( 1, JIMCA_Pagination::find_in_blocks( $blocks, JIMCA_Pagination::normalize( 'moises' ) ) );
		$this->assertCount( 1, JIMCA_Pagination::find_in_blocks( $blocks, JIMCA_Pagination::normalize( 'CÉUS' ) ) );
		$this->assertCount( 1, JIMCA_Pagination::find_in_blocks( $blocks, JIMCA_Pagination::normalize( 'princípio deus' ) ), 'a phrase' );
		$this->assertCount( 0, JIMCA_Pagination::find_in_blocks( $blocks, JIMCA_Pagination::normalize( 'abraão' ) ) );
	}

	public function test_a_result_has_the_text_around_the_match_and_a_way_back_to_the_block() {
		$text = str_repeat( 'a ', 80 ) . 'Moisés falou' . str_repeat( ' b', 80 );
		$hit  = JIMCA_Pagination::find_in_blocks( array( $text ), 'moises' )[0];

		$this->assertSame( 'Moisés', $hit['match'], 'the match keeps the document spelling' );
		$this->assertStringStartsWith( '…', $hit['before'] );
		$this->assertStringEndsWith( '…', $hit['after'] );
		$this->assertSame( mb_substr( $text, 0, 60 ), $hit['block'] );
		$this->assertSame( 0, $hit['dup'] );
	}

	public function test_blocks_that_start_alike_are_told_apart_by_how_many_came_before() {
		$blocks = array( 'Amém. primeiro', 'Outro texto', 'Amém. primeiro', 'Amém. primeiro' );
		$hits   = JIMCA_Pagination::find_in_blocks( $blocks, 'amem' );

		$this->assertSame( array( 0, 1, 2 ), array_column( $hits, 'dup' ) );
	}
}
