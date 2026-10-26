<?php
require_once dirname( __DIR__, 2 ) . '/includes/converters/class-pdf-layout.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * Column detection on hand-made text pieces (no PDF): each test isolates one
 * of the checks that keep pages getText() reads well untouched.
 *
 * @covers JIMCA_Pdf_Layout
 */
class PdfLayoutTest extends UnitTestCase {

	const LEFT = array(
		'A leitura em voz alta depende da ordem',
		'do texto, e quando a ordem se perde a',
		'frase deixa de fazer sentido para quem',
		'escuta o documento inteiro com calma.',
		'Artigos usam duas colunas para poupar',
		'papel, mas na tela as colunas viram um',
	);

	const RIGHT = array(
		'desafio, porque o arquivo guarda cada',
		'linha em uma posição e não diz qual',
		'coluna vem primeiro nem onde termina.',
		'Alguns programas gravam a esquerda e',
		'depois a direita; outros gravam linha',
		'por linha, como se fosse uma coluna.',
	);

	/**
	 * Two columns written line by line across the page: left line 1, right
	 * line 1, left line 2...
	 *
	 * @param bool $interleaved False: the left column, then the right one.
	 * @param int  $flip        -1 for a page whose y grows downwards.
	 * @return array
	 */
	private static function two_columns( $interleaved = true, $flip = 1 ) {
		$pieces = array();
		foreach ( self::LEFT as $i => $text ) {
			$pieces[] = array( $interleaved ? 2 * $i : $i, 50, 700 - 13 * $i, $text );
			$pieces[] = array( $interleaved ? 2 * $i + 1 : 100 + $i, 310, 700 - 13 * $i, self::RIGHT[ $i ] );
		}
		usort(
			$pieces,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);

		return self::runs(
			array_map(
				static function ( $p ) use ( $flip ) {
					return array( $p[1], $flip * $p[2], $p[3] );
				},
				$pieces
			)
		);
	}

	/**
	 * @param array $pieces [x, y, text] or [x, y, text, size].
	 * @return array Runs as JIMCA_Pdf_Layout::runs() returns them.
	 */
	private static function runs( array $pieces ) {
		$runs = array();
		foreach ( $pieces as $i => $p ) {
			$runs[] = array(
				'x'     => (float) $p[0],
				'y'     => (float) $p[1],
				'size'  => isset( $p[3] ) ? (float) $p[3] : 10.0,
				'text'  => $p[2],
				'space' => '' === trim( $p[2] ),
				'order' => $i,
			);
		}

		return $runs;
	}

	public function test_interleaved_columns_are_read_one_after_the_other() {
		$text = JIMCA_Pdf_Layout::reading_order( self::two_columns() );

		$this->assertSame( implode( "\n", array_merge( self::LEFT, self::RIGHT ) ), $text );
	}

	public function test_columns_written_one_after_the_other_are_left_alone() {
		// getText() already reads them in order.
		$this->assertNull( JIMCA_Pdf_Layout::reading_order( self::two_columns( false ) ) );
	}

	public function test_a_page_drawn_upside_down_is_read_top_to_bottom() {
		$text = JIMCA_Pdf_Layout::reading_order( self::two_columns( true, -1 ) );

		$this->assertSame( implode( "\n", array_merge( self::LEFT, self::RIGHT ) ), $text );
	}

	public function test_one_column_is_left_alone() {
		$pieces = array();
		foreach ( array_merge( self::LEFT, self::RIGHT ) as $i => $text ) {
			$pieces[] = array( 50, 700 - 13 * $i, $text . ' ' . $text );
		}

		$this->assertNull( JIMCA_Pdf_Layout::reading_order( self::runs( $pieces ) ) );
	}

	public function test_a_table_of_short_cells_stays_row_by_row() {
		$pieces = array();
		foreach ( array( 'Ana Souza', 'Davi Lima', 'Clara Nunes', 'Bruno Alves', 'Rita Gomes', 'Pedro Rocha' ) as $i => $name ) {
			$pieces[] = array( 50, 700 - 16 * $i, $name );
			$pieces[] = array( 250, 700 - 16 * $i, 'Recife' );
			$pieces[] = array( 420, 700 - 16 * $i, (string) ( 10 + $i ) );
		}

		$this->assertNull( JIMCA_Pdf_Layout::reading_order( self::runs( $pieces ) ) );
	}

	public function test_dot_leaders_of_a_printed_table_of_contents_are_not_a_column() {
		$pieces = array();
		foreach ( array( 'Capítulo um: a ordem do texto', 'Capítulo dois: as colunas', 'Capítulo três: as tabelas', 'Capítulo quatro: as listas', 'Capítulo cinco: o rodapé', 'Capítulo seis: a revisão' ) as $i => $title ) {
			$pieces[] = array( 50, 700 - 14 * $i, $title );
			$pieces[] = array( 300, 700 - 14 * $i, str_repeat( '.', 40 ) . ' ' . ( 3 + 4 * $i ) );
		}

		$this->assertNull( JIMCA_Pdf_Layout::reading_order( self::runs( $pieces ) ) );
	}

	public function test_title_above_and_page_number_below_keep_their_place() {
		$runs = self::two_columns();
		array_unshift( $runs, self::runs( array( array( 50, 760, 'Leitura em duas colunas: um teste do Jim', 16 ) ) )[0] );
		$runs[] = self::runs( array( array( 295, 40, '7' ) ) )[0];
		foreach ( $runs as $i => &$run ) {
			$run['order'] = $i;
		}
		unset( $run );

		$lines = explode( "\n", JIMCA_Pdf_Layout::reading_order( $runs ) );

		$this->assertSame( 'Leitura em duas colunas: um teste do Jim', $lines[0] );
		$this->assertSame( '', $lines[1], 'the gap under the title ends a paragraph' );
		$this->assertSame( self::LEFT[0], $lines[2] );
		$this->assertSame( '7', end( $lines ) );
	}

	public function test_letters_placed_one_by_one_keep_their_words() {
		// "m" is much wider than the estimated average: only the space pieces break words.
		$pieces = array();
		$advance = array(
			'm' => 8.3,
			'i' => 2.2,
			' ' => 2.8,
		);
		foreach ( self::two_columns() as $run ) {
			$x = $run['x'];
			foreach ( preg_split( '//u', $run['text'], -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
				$pieces[] = array( $x, $run['y'], $ch );
				$x       += isset( $advance[ $ch ] ) ? $advance[ $ch ] : 5.5;
			}
		}

		$text = JIMCA_Pdf_Layout::reading_order( self::runs( $pieces ) );

		$this->assertSame( implode( "\n", array_merge( self::LEFT, self::RIGHT ) ), $text );
	}

	public function test_runs_reads_positions_sizes_and_spaces_and_skips_rotated_text() {
		$runs = JIMCA_Pdf_Layout::runs(
			array(
				array( array( '1', '0', '0', '1', '72', '700' ), 'Texto', 'F1', '12' ),
				array( array( '1', '0', '0', '1', '110', '700' ), ' ', 'F1', '12' ),
				array( array( '0', '1', '-1', '0', '20', '400' ), 'Rotated', 'F1', '12' ),
				array( array( '2', '0', '0', '2', '72', '650' ), 'Scaled', 'F1', '9' ),
			)
		);

		$this->assertCount( 3, $runs );
		$this->assertSame( array( 72.0, 700.0, 12.0, false ), array( $runs[0]['x'], $runs[0]['y'], $runs[0]['size'], $runs[0]['space'] ) );
		$this->assertTrue( $runs[1]['space'] );
		$this->assertSame( 18.0, $runs[2]['size'], 'font size times the text matrix scale' );
	}
}
