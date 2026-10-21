<?php
require_once dirname( __DIR__, 2 ) . '/admin/class-admin.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * The .htaccess of the uploads folder of Jim.
 *
 * @covers JIMCA_Admin::htaccess_content
 */
class HtaccessTest extends UnitTestCase {

	public function test_scripts_and_listing_are_always_blocked() {
		foreach ( array( true, false ) as $noindex ) {
			$rules = JIMCA_Admin::htaccess_content( $noindex );

			$this->assertStringContainsString( 'Require all denied', $rules );
			$this->assertStringContainsString( 'Deny from all', $rules );
			$this->assertStringContainsString( 'Options -Indexes', $rules );
			$this->assertStringContainsString( '(php\d?|phtml', $rules );
		}
	}

	public function test_noindex_header_only_when_asked_and_only_where_mod_headers_exists() {
		$with = JIMCA_Admin::htaccess_content( true );

		$this->assertStringContainsString( 'Header set X-Robots-Tag "noindex, nofollow"', $with );
		$this->assertMatchesRegularExpression( '/<IfModule mod_headers\.c>\s+Header set X-Robots-Tag/', $with );
		$this->assertStringNotContainsString( 'X-Robots-Tag', JIMCA_Admin::htaccess_content( false ) );
	}

	public function test_the_content_is_stable_so_it_is_rewritten_only_when_it_changes() {
		$this->assertSame( JIMCA_Admin::htaccess_content( true ), JIMCA_Admin::htaccess_content( true ) );
		$this->assertNotSame( JIMCA_Admin::htaccess_content( true ), JIMCA_Admin::htaccess_content( false ) );
	}
}
