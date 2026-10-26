<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-converter.php';
require_once __DIR__ . '/UnitTestCase.php';

use Brain\Monkey\Functions;

/**
 * @covers JIMCA_Converter::count_words
 */
class WordCountTest extends UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
	}

	public function test_counts_words_of_the_text() {
		$this->assertSame( 5, JIMCA_Converter::count_words( "<h2>Um título</h2>\n<p>Três palavras aqui.</p>" ) );
	}

	/*
	 * A PDF without a text layer (scanned, or with the letters drawn as
	 * curves by "Microsoft Print to PDF") gives only the page markers and
	 * images: it must count as no text, so the upload is refused.
	 */
	public function test_page_markers_are_not_words() {
		$html = '<h2 class="jimca-page-title">Página 1</h2>' . "\n"
			. '<figure class="jimca-figure"><img src="a.jpg" alt="Imagem 1 na página 1 (sem descrição)"></figure>' . "\n"
			. '<h2 class="jimca-page-title">Página 2</h2>' . "\n";

		$this->assertSame( 0, JIMCA_Converter::count_words( $html ) );
	}

	public function test_page_markers_do_not_add_to_a_real_count() {
		$html = '<h2 class="jimca-page-title">Page 1</h2><p>Hello world.</p><h2 class="jimca-page-title">Page 2</h2><p>Again.</p>';

		$this->assertSame( 3, JIMCA_Converter::count_words( $html ) );
	}

	public function test_a_heading_that_is_not_a_marker_counts() {
		$this->assertSame( 2, JIMCA_Converter::count_words( '<h2 class="other">Página um</h2>' ) );
	}
}
