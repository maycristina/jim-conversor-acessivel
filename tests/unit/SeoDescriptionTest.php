<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-seo.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * @covers JIMCA_SEO::description
 */
class SeoDescriptionTest extends UnitTestCase {

	public function test_empty_content_gives_empty_description() {
		$this->assertSame( '', JIMCA_SEO::description( '' ) );
	}

	public function test_prefers_real_paragraphs_over_loose_titles() {
		$html = '<h2>Cover</h2><p>Short.</p><p>This paragraph is long enough to be used as the summary of the document.</p>';

		$this->assertSame( 'This paragraph is long enough to be used as the summary of the document.', JIMCA_SEO::description( $html ) );
	}

	public function test_skips_page_markers_and_printed_table_of_contents() {
		$html = '<h2 class="jimca-page-title">Page 3</h2>'
			. '<ul class="jimca-printed-toc"><li>Chapter 1 ........ 4</li></ul>'
			. '<p>The real content starts here and goes on for more than forty characters.</p>';

		$this->assertSame( 'The real content starts here and goes on for more than forty characters.', JIMCA_SEO::description( $html ) );
	}

	public function test_falls_back_to_any_text_without_long_paragraphs() {
		$this->assertSame( 'Just a title', JIMCA_SEO::description( '<h1>Just a title</h1>' ) );
	}

	public function test_decodes_entities() {
		$html = '<p>Tom &amp; Jerry appear together in this long enough sentence for a summary.</p>';

		$this->assertStringStartsWith( 'Tom & Jerry', JIMCA_SEO::description( $html ) );
	}

	public function test_long_text_is_cut_at_a_whole_word_with_ellipsis() {
		$html        = '<p>' . str_repeat( 'palavra ', 200 ) . '</p>';
		$description = JIMCA_SEO::description( $html );

		$this->assertStringEndsWith( 'palavra…', $description );
		$this->assertLessThanOrEqual( JIMCA_SEO::DESCRIPTION_CHARS + 1, mb_strlen( $description ) );
	}
}
