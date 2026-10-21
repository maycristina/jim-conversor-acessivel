<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-seo.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * The description is chosen by the shape of the text, not by words.
 *
 * @covers JIMCA_SEO::prose
 * @covers JIMCA_SEO::description
 * @covers JIMCA_SEO::clean_separators
 * @covers JIMCA_SEO::align_with_title
 * @covers JIMCA_SEO::fit_title_parts
 */
class SeoDescriptionStructureTest extends UnitTestCase {

	public function test_description_skips_data_sheets_banners_and_notices() {
		$html = '<p>The Project Gutenberg eBook of Candide, by Voltaire</p>'
			. '<p>This eBook is for the use of anyone anywhere and may be copied at www.gutenberg.org, under its terms.</p>'
			. '<p>*** This is a COPYRIGHTED notice and follows below, please read it with care ***</p>'
			. '<p>Title: Candide<br />Author: Voltaire<br />Release date: October 1, 2003 [eBook #4565]<br />Language: French</p>'
			. '<p>=========================================</p>'
			. '<p>Il y avait en Westphalie, dans le château de monsieur le baron de Thunder-ten-tronckh, un jeune garçon.</p>';

		$this->assertStringStartsWith( 'Il y avait en Westphalie', JIMCA_SEO::description( $html ) );
	}

	public function test_prose_rules() {
		$this->assertSame( '', JIMCA_SEO::prose( 'Release date: October 1, 2003<br>Language: French' ) );
		$this->assertSame( '', JIMCA_SEO::prose( '***** One two three four five six seven eight nine *****' ) );
		$this->assertSame( '', JIMCA_SEO::prose( 'Write to us at contact@example.org about any of these several matters.' ) );
		$this->assertSame( '', JIMCA_SEO::prose( 'Only a few words here.' ) );
		$this->assertSame( '', JIMCA_SEO::prose( '1234 5678 9012 3456 7890 1234 5678 9012 3456 7890' ) );
	}

	public function test_prose_that_mentions_a_licence_is_kept() {
		$this->assertStringContainsString( 'licença', JIMCA_SEO::prose( 'O autor concedeu uma licença aberta para a reprodução deste texto em qualquer meio.' ) );
	}

	public function test_a_long_note_is_prose_not_a_data_sheet() {
		$this->assertNotSame( '', JIMCA_SEO::prose( 'Nota: este documento reúne as regras do concurso e deve ser lido com atenção por todos os candidatos inscritos.' ) );
	}

	public function test_separators_are_removed() {
		$this->assertSame( 'Antes depois', JIMCA_SEO::clean_separators( 'Antes ======== depois -----' ) );
		$this->assertSame( 'Um - dois', JIMCA_SEO::clean_separators( 'Um - dois' ) );
	}

	public function test_description_without_title_words_leads_with_the_title() {
		$this->assertStringStartsWith( 'Regras do concurso. ', JIMCA_SEO::align_with_title( 'Texto que fala de outra coisa totalmente diferente.', 'Regras do concurso' ) );
		$this->assertSame( 'O concurso tem regras claras.', JIMCA_SEO::align_with_title( 'O concurso tem regras claras.', 'Regras do concurso' ) );
		$this->assertSame( 'Qualquer.', JIMCA_SEO::align_with_title( 'Qualquer.', 'Eu' ) );
	}

	public function test_long_titles_lose_the_site_name_first() {
		$short = JIMCA_SEO::fit_title_parts(
			array(
				'title' => 'Edital 2026',
				'site'  => 'Prefeitura',
			)
		);
		$this->assertSame( 'Prefeitura', $short['site'] );

		$medium = JIMCA_SEO::fit_title_parts(
			array(
				'title' => 'Um título de sessenta e poucos caracteres, bem comprido ainda',
				'site'  => 'Prefeitura Municipal',
			)
		);
		$this->assertArrayNotHasKey( 'site', $medium );
		$this->assertStringStartsWith( 'Um título de sessenta', $medium['title'] );

		$long = JIMCA_SEO::fit_title_parts(
			array(
				'title' => str_repeat( 'Título longo ', 8 ),
				'site'  => 'Prefeitura Municipal',
			)
		);
		$this->assertArrayNotHasKey( 'site', $long );
		$this->assertLessThanOrEqual( JIMCA_SEO::TITLE_CHARS, mb_strlen( $long['title'] ) );
	}

	public function test_og_locale_follows_the_document_language() {
		$this->assertSame( 'fr_FR', JIMCA_SEO::og_locale( 'fr' ) );
		$this->assertSame( 'pt_BR', JIMCA_SEO::og_locale( 'pt-BR' ) );
		$this->assertSame( 'zh_CN', JIMCA_SEO::og_locale( 'zh-CN' ) );
		$this->assertSame( 'en_US', JIMCA_SEO::og_locale( 'en' ) );
		$this->assertSame( 'xx_XX', JIMCA_SEO::og_locale( 'xx' ) );
	}
}
