<?php
use Brain\Monkey\Filters;

require_once dirname( __DIR__, 2 ) . '/includes/class-security.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * @covers JIMCA_Security
 */
class SecurityHeadersTest extends UnitTestCase {

	public function test_by_default_only_the_same_site_may_frame_the_pages() {
		Filters\expectApplied( 'jimca_frame_ancestors' )->once()->andReturnFirstArg();

		$this->assertSame(
			array(
				'Content-Security-Policy' => "frame-ancestors 'self'",
				'X-Frame-Options'         => 'SAMEORIGIN',
			),
			JIMCA_Security::headers()
		);
	}

	public function test_a_listed_site_may_frame_and_the_old_header_is_not_sent() {
		Filters\expectApplied( 'jimca_frame_ancestors' )->once()->andReturn( "'self' https://campus.example.com" );

		$this->assertSame(
			array( 'Content-Security-Policy' => "frame-ancestors 'self' https://campus.example.com" ),
			JIMCA_Security::headers()
		);
	}

	public function test_an_empty_value_turns_the_header_off() {
		Filters\expectApplied( 'jimca_frame_ancestors' )->once()->andReturn( '' );

		$this->assertSame( array(), JIMCA_Security::headers() );
	}

	public function test_nothing_can_break_out_of_the_header_line() {
		Filters\expectApplied( 'jimca_frame_ancestors' )->once()->andReturn( "'self'\r\nSet-Cookie: x=1; script-src *" );

		$value = JIMCA_Security::headers()['Content-Security-Policy'];

		$this->assertStringNotContainsString( "\n", $value );
		$this->assertStringNotContainsString( "\r", $value );
		$this->assertStringNotContainsString( ';', $value );
	}

	public function test_a_filter_returning_a_non_string_falls_back_to_the_default() {
		Filters\expectApplied( 'jimca_frame_ancestors' )->once()->andReturn( array( 'x' ) );

		$this->assertSame( "'self'", JIMCA_Security::frame_ancestors() );
	}
}
