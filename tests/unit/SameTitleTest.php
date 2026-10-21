<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-shortcode.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * @covers JIMCA_Shortcode::same_title
 */
class SameTitleTest extends UnitTestCase {

	public function test_same_title_ignores_case_tags_entities_and_spaces() {
		$this->assertTrue( JIMCA_Shortcode::same_title( 'Jusqu&#8217;à l&rsquo;extrême  regard', 'jusqu’à l’extrême regard' ) );
		$this->assertTrue( JIMCA_Shortcode::same_title( '<em>Edital</em> 1/2026', 'EDITAL 1/2026' ) );
	}

	public function test_different_or_empty_titles_are_not_the_same() {
		$this->assertFalse( JIMCA_Shortcode::same_title( 'Edital 1', 'Edital 2' ) );
		$this->assertFalse( JIMCA_Shortcode::same_title( '', '' ) );
		$this->assertFalse( JIMCA_Shortcode::same_title( 'Edital', '' ) );
	}

	public function test_headings_go_one_level_down_when_the_document_has_an_h1() {
		$html = '<h1>Title</h1><p>Text</p><h2 id="a">One</h2><h6>Deep</h6><hr><header>x</header>';

		$this->assertSame(
			'<h2>Title</h2><p>Text</p><h3 id="a">One</h3><h6>Deep</h6><hr><header>x</header>',
			JIMCA_Shortcode::demote_headings( $html, $html )
		);
		$this->assertSame( '</h3>', JIMCA_Shortcode::demote_headings( '</h2>', '<h1>x</h1>' ) );
	}

	public function test_headings_stay_when_the_document_has_no_h1() {
		$html = '<h2>One</h2><h3>Two</h3>';

		$this->assertSame( $html, JIMCA_Shortcode::demote_headings( $html, $html ) );
	}
}
