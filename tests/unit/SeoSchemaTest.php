<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-seo.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-metadata.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * @covers JIMCA_SEO::document_properties
 * @covers JIMCA_SEO::json_ld
 * @covers JIMCA_SEO::merge_webpage
 * @covers JIMCA_SEO::structure
 * @covers JIMCA_SEO::shorten
 * @covers JIMCA_Metadata
 */
class SeoSchemaTest extends UnitTestCase {

	private function data( array $override = array() ) {
		return array_merge(
			array(
				'title'       => 'Edital 1/2026',
				'description' => 'Resumo.',
				'url'         => 'https://example.org/edital/',
				'site_name'   => 'Prefeitura',
				'language'    => 'pt-BR',
				'published'   => '2026-10-01T10:00:00+00:00',
				'modified'    => '2026-10-02T10:00:00+00:00',
				'words'       => 3450,
				'minutes'     => 18,
				'author'      => 'Maria Silva',
				'keywords'    => array( 'edital', 'licitação' ),
				'reader'      => true,
				'structure'   => array(
					'headings'           => 5,
					'images'             => 2,
					'images_missing_alt' => 0,
				),
				'image'       => null,
			),
			$override
		);
	}

	public function test_declares_only_features_the_page_has() {
		$props = JIMCA_SEO::document_properties( $this->data() );

		$this->assertSame(
			array( 'readingOrder', 'structuralNavigation', 'tableOfContents', 'alternativeText', 'displayTransformability', 'highContrastDisplay' ),
			$props['accessibilityFeature']
		);
		$this->assertSame( array( 'textual', 'visual' ), $props['accessMode'] );
		$this->assertContains( 'fullKeyboardControl', $props['accessibilityControl'] );
	}

	public function test_post_mode_without_images_declares_less() {
		$props = JIMCA_SEO::document_properties(
			$this->data(
				array(
					'reader'    => false,
					'structure' => array(
						'headings'           => 1,
						'images'             => 0,
						'images_missing_alt' => 0,
					),
				)
			)
		);

		$this->assertSame( array( 'readingOrder', 'structuralNavigation' ), $props['accessibilityFeature'] );
		$this->assertSame( array( 'textual' ), $props['accessMode'] );
		$this->assertArrayNotHasKey( 'accessibilityControl', $props );
	}

	public function test_images_without_alt_text_do_not_claim_alternative_text() {
		$props = JIMCA_SEO::document_properties(
			$this->data(
				array(
					'structure' => array(
						'headings'           => 0,
						'images'             => 3,
						'images_missing_alt' => 1,
					),
				)
			)
		);

		$this->assertNotContains( 'alternativeText', $props['accessibilityFeature'] );
	}

	public function test_author_reading_time_and_keywords() {
		$ld = JIMCA_SEO::json_ld( $this->data() );

		$this->assertSame( 'Article', $ld['@type'] );
		$this->assertSame( array( '@type' => 'Person', 'name' => 'Maria Silva' ), $ld['author'] );
		$this->assertSame( 'PT18M', $ld['timeRequired'] );
		$this->assertSame( 'edital, licitação', $ld['keywords'] );
		$this->assertSame( 3450, $ld['wordCount'] );
	}

	public function test_no_author_no_keywords_no_words() {
		$ld = JIMCA_SEO::json_ld( $this->data( array( 'author' => '', 'keywords' => array(), 'words' => 0, 'minutes' => 0 ) ) );

		$this->assertArrayNotHasKey( 'author', $ld );
		$this->assertArrayNotHasKey( 'keywords', $ld );
		$this->assertArrayNotHasKey( 'timeRequired', $ld );
	}

	public function test_merge_keeps_what_the_other_plugin_set() {
		$merged = JIMCA_SEO::merge_webpage(
			array(
				'@type'     => 'WebPage',
				'wordCount' => 10,
				'author'    => array( '@id' => '#person' ),
			),
			$this->data()
		);

		$this->assertSame( 10, $merged['wordCount'] );
		$this->assertSame( array( '@id' => '#person' ), $merged['author'] );
		$this->assertArrayHasKey( 'accessibilityFeature', $merged );
		$this->assertArrayNotHasKey( 'publisher', $merged );
	}

	public function test_structure_counts_headings_and_images() {
		$html = '<h2 class="jimca-page-title">Page 1</h2><h2>Intro</h2><h3></h3><h3>Part</h3>'
			. '<img src="a.png" alt="A"><img src="b.png" alt=""><img src="c.png" alt="x" data-jimca-alt-missing>';

		$this->assertSame(
			array(
				'headings'           => 2,
				'images'             => 3,
				'images_missing_alt' => 2,
			),
			JIMCA_SEO::structure( $html )
		);
	}

	public function test_shorten_cuts_at_a_word() {
		$text = str_repeat( 'palavra ', 40 );

		$this->assertLessThanOrEqual( JIMCA_SEO::DESCRIPTION_CHARS + 1, mb_strlen( JIMCA_SEO::shorten( $text ) ) );
		$this->assertSame( 'Curto', JIMCA_SEO::shorten( ' Curto ' ) );
	}

	public function test_metadata_ignores_placeholder_authors_and_cleans_keywords() {
		$meta = JIMCA_Metadata::clean(
			array(
				'author'   => 'Microsoft Word',
				'subject'  => "  Um  assunto\n",
				'keywords' => 'a; b, A,, c',
				'language' => 'pt-BR',
			)
		);

		$this->assertSame( '', $meta['author'] );
		$this->assertSame( 'Um assunto', $meta['subject'] );
		$this->assertSame( array( 'a', 'b', 'c' ), $meta['keywords'] );
		$this->assertSame( 'pt-BR', $meta['language'] );
	}

	public function test_metadata_rejects_a_bad_language_tag() {
		$this->assertSame( '', JIMCA_Metadata::clean( array( 'language' => 'português do brasil' ) )['language'] );
	}

	public function test_reads_docx_core_properties() {
		$xml  = '<cp:coreProperties xmlns:cp="x" xmlns:dc="y"><dc:title>T</dc:title><dc:creator>Ana &amp; Bia</dc:creator>'
			. '<cp:keywords>x, y</cp:keywords><dc:subject>Sobre</dc:subject><dc:language>pt-BR</dc:language></cp:coreProperties>';
		$meta = JIMCA_Metadata::clean( JIMCA_Metadata::parse_core_xml( $xml ) );

		$this->assertSame( 'Ana & Bia', $meta['author'] );
		$this->assertSame( array( 'x', 'y' ), $meta['keywords'] );
		$this->assertSame( 'Sobre', $meta['subject'] );
	}

	public function test_generic_authors_are_not_authors() {
		$this->assertTrue( JIMCA_Metadata::is_generic_author( 'user' ) );
		$this->assertTrue( JIMCA_Metadata::is_generic_author( 'Administrador' ) );
		$this->assertTrue( JIMCA_Metadata::is_generic_author( 'Maria123', 'maria123' ) );
		$this->assertTrue( JIMCA_Metadata::is_generic_author( '' ) );
		$this->assertFalse( JIMCA_Metadata::is_generic_author( 'Maria Silva', 'maria' ) );
	}

	public function test_language_list_has_the_site_languages_and_valid_tags() {
		$languages = JIMCA_Metadata::languages();

		foreach ( array( 'pt-BR', 'en', 'es', 'fr', 'de', 'ru', 'zh-CN', 'hi' ) as $tag ) {
			$this->assertArrayHasKey( $tag, $languages );
		}
		foreach ( array_keys( $languages ) as $tag ) {
			$this->assertSame( $tag, JIMCA_Metadata::clean( array( 'language' => $tag ) )['language'] );
		}
	}
}
