<?php
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-shortcode.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-scorecard.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-usage.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * Which documents a text shows: the exact id, in content and in page builder JSON.
 *
 * @covers JIMCA_Usage::ids_in
 */
class UsageTest extends UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The regex WordPress builds for one shortcode tag (get_shortcode_regex()).
		Functions\when( 'get_shortcode_regex' )->justReturn(
			'\[(\[?)(documento_acessivel)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)'
		);
		Functions\when( 'shortcode_parse_atts' )->alias(
			static function ( $text ) {
				return preg_match( '/id=["\']?(\d+)/', (string) $text, $m ) ? array( 'id' => $m[1] ) : array();
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
	}

	public function test_finds_the_ids_of_every_shortcode_of_the_text() {
		$content = '<p>[documento_acessivel id="17"]</p> texto [documento_acessivel id="179" tutor="yes"] [documento_acessivel id=\'5\']';

		$this->assertSame( array( 17, 179, 5 ), JIMCA_Usage::ids_in( $content ) );
	}

	public function test_an_id_that_only_starts_like_another_is_not_it() {
		$ids = JIMCA_Usage::ids_in( '[documento_acessivel id="179"]' );

		$this->assertNotContains( 17, $ids );
		$this->assertContains( 179, $ids );
	}

	public function test_finds_the_shortcode_inside_page_builder_json() {
		$json = '[{"id":"a1","settings":{"shortcode":"[documento_acessivel id=\"42\"]"}}]';

		$this->assertSame( array( 42 ), JIMCA_Usage::ids_in( $json ) );
	}

	public function test_text_without_the_shortcode_has_no_ids() {
		$this->assertSame( array(), JIMCA_Usage::ids_in( '<p>nada aqui</p> [outro id="3"]' ) );
		$this->assertSame( array(), JIMCA_Usage::ids_in( '' ) );
	}

	public function test_repeated_ids_count_once() {
		$this->assertSame( array( 9 ), JIMCA_Usage::ids_in( '[documento_acessivel id="9"] [documento_acessivel id="9"]' ) );
	}
}
