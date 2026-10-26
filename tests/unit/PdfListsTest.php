<?php
require_once dirname( __DIR__, 2 ) . '/includes/converters/class-pdf-lists.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * @covers JIMCA_Pdf_Lists
 */
class PdfListsTest extends UnitTestCase {

	/**
	 * @param string ...$texts Paragraphs.
	 * @return array Blocks as JIMCA_Pdf_Converter::blocks_for() makes them.
	 */
	private static function blocks( ...$texts ) {
		return array_map(
			static function ( $text ) {
				$marker = JIMCA_Pdf_Lists::marker( $text );

				return null === $marker
					? array(
						'type' => 'p',
						'text' => $text,
					)
					: array(
						'type'   => 'li',
						'text'   => $text,
						'marker' => $marker,
					);
			},
			$texts
		);
	}

	/**
	 * @param array $blocks
	 * @return string[] "ul: a | b", "ol: 1. a | 2. b" or "p: text" per block.
	 */
	private static function summary( array $blocks ) {
		return array_map(
			static function ( $block ) {
				return 'list' === $block['type'] ? $block['kind'] . ': ' . implode( ' | ', $block['items'] ) : $block['type'] . ': ' . $block['text'];
			},
			$blocks
		);
	}

	public function test_markers() {
		$this->assertSame( 'Fones de ouvido', JIMCA_Pdf_Lists::marker( '• Fones de ouvido' )['text'] );
		$this->assertSame( 'Item do Word', JIMCA_Pdf_Lists::marker( "\u{F0B7}\tItem do Word" )['text'], 'Symbol font bullet (private use)' );
		$this->assertSame( 'ul', JIMCA_Pdf_Lists::marker( '▪Sem espaço' )['kind'] );
		$this->assertSame( 2, JIMCA_Pdf_Lists::marker( '2) Envie o arquivo' )['value'] );
		$this->assertSame( 'number.', JIMCA_Pdf_Lists::marker( '12. Último passo' )['family'] );
		$this->assertSame( 3, JIMCA_Pdf_Lists::marker( 'c) Terceira opção' )['value'] );
	}

	public function test_lines_that_are_not_list_items() {
		foreach ( array(
			'— Você leu? — perguntou Ana.',
			'– Li, sim.',
			'- traço comum',
			'A. Silva escreveu o prefácio.',
			'2024. Foi o ano da reforma.',
			'1.1 Introdução',
			'1 No começo havia o silêncio.',
			'Texto comum.',
		) as $line ) {
			$this->assertNull( JIMCA_Pdf_Lists::marker( $line ), $line );
		}
	}

	public function test_two_bullets_make_a_list() {
		$this->assertSame(
			array( 'p: Materiais:', 'ul: Livros | Fones', 'p: Depois.' ),
			self::summary( JIMCA_Pdf_Lists::group( self::blocks( 'Materiais:', '• Livros', '• Fones', 'Depois.' ) ) )
		);
	}

	public function test_a_numbered_heading_alone_stays_a_paragraph() {
		$this->assertSame(
			array( 'p: 1. Introdução', 'p: Texto.' ),
			self::summary( JIMCA_Pdf_Lists::group( self::blocks( '1. Introdução', 'Texto.' ) ) )
		);
	}

	public function test_a_lettered_sublist_splits_the_numbered_list_in_flat_lists() {
		$this->assertSame(
			array( 'ol: 1. Abra | 2. Envie', 'ol: a) PDF | b) DOCX', 'ol: 3. Confira' ),
			self::summary( JIMCA_Pdf_Lists::group( self::blocks( '1. Abra', '2. Envie', 'a) PDF', 'b) DOCX', '3. Confira' ) ) )
		);
	}

	public function test_a_lone_letter_right_under_a_numbered_list_is_a_sub_item() {
		$this->assertSame(
			array( 'ol: 1. Um | 2. Dois', 'ol: a) Subitem', 'p: Fim.' ),
			self::summary( JIMCA_Pdf_Lists::group( self::blocks( '1. Um', '2. Dois', 'a) Subitem', 'Fim.' ) ) )
		);
	}

	public function test_a_lone_letter_elsewhere_stays_a_paragraph() {
		$this->assertSame(
			array( 'ul: Livros | Fones', 'p: a) Solto' ),
			self::summary( JIMCA_Pdf_Lists::group( self::blocks( '• Livros', '• Fones', 'a) Solto' ) ) ),
			'after bullets'
		);
		$this->assertSame(
			array( 'ol: 1. Um | 2. Dois', 'p: b) Segundo' ),
			self::summary( JIMCA_Pdf_Lists::group( self::blocks( '1. Um', '2. Dois', 'b) Segundo' ) ) ),
			'a sub-item starts at a)'
		);
	}

	public function test_numbers_out_of_sequence_are_not_a_list() {
		$this->assertSame(
			array( 'p: 1. Um', 'p: 3. Três' ),
			self::summary( JIMCA_Pdf_Lists::group( self::blocks( '1. Um', '3. Três' ) ) )
		);
	}

	public function test_a_list_continued_on_the_next_page_may_start_past_one() {
		$this->assertSame(
			array( 'ol: 4. Quarto | 5. Quinto' ),
			self::summary( JIMCA_Pdf_Lists::group( self::blocks( '4. Quarto', '5. Quinto' ) ) )
		);
	}

	public function test_when_a_line_starts_or_continues_an_item() {
		$number = JIMCA_Pdf_Lists::marker( '2. Depois' );

		$this->assertTrue( JIMCA_Pdf_Lists::starts_item( JIMCA_Pdf_Lists::marker( '• Item' ), str_repeat( 'texto longo ', 20 ), false ), 'a bullet always does' );
		$this->assertTrue( JIMCA_Pdf_Lists::starts_item( $number, 'Passos', false ), 'after a short heading' );
		$this->assertTrue( JIMCA_Pdf_Lists::starts_item( $number, str_repeat( 'item longo ', 20 ), true ), 'after another item' );
		$this->assertFalse( JIMCA_Pdf_Lists::starts_item( $number, str_repeat( 'texto corrido ', 10 ) . 'no capítulo', false ), 'inside a sentence' );

		$this->assertTrue( JIMCA_Pdf_Lists::continues_item( 'regulável para cada leitor' ) );
		$this->assertFalse( JIMCA_Pdf_Lists::continues_item( 'Depois da lista, um parágrafo.' ) );
	}
}
