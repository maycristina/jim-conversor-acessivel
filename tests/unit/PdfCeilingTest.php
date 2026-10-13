<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-limits.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * The biggest PDF a PHP memory limit can convert. The factor comes from
 * measurements with the plugin's converter (see JIMCA_Limits::PDF_MEMORY_FACTOR).
 *
 * @covers JIMCA_Limits::pdf_ceiling_for
 */
class PdfCeilingTest extends UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'MB_IN_BYTES' ) ) {
			define( 'MB_IN_BYTES', 1048576 );
		}
	}

	public function test_unlimited_memory_has_no_ceiling() {
		$this->assertSame( 0, JIMCA_Limits::pdf_ceiling_for( -1 ) );
		$this->assertSame( 0, JIMCA_Limits::pdf_ceiling_for( 0 ) );
	}

	public function test_the_default_admin_memory_converts_what_was_measured() {
		$ceiling = JIMCA_Limits::pdf_ceiling_for( 256 * MB_IN_BYTES );

		// Measured: a 25 MB PDF peaked at 84 MB and a 51 MB one at 248 MB (of 256).
		$this->assertGreaterThan( 25 * MB_IN_BYTES, $ceiling );
		$this->assertLessThan( 51 * MB_IN_BYTES, $ceiling, 'the 51 MB PDF left no room: it must be refused' );
	}

	public function test_small_hosting_gets_a_proportionally_smaller_ceiling() {
		$this->assertLessThan( JIMCA_Limits::pdf_ceiling_for( 256 * MB_IN_BYTES ), JIMCA_Limits::pdf_ceiling_for( 128 * MB_IN_BYTES ) );
		$this->assertLessThan( JIMCA_Limits::pdf_ceiling_for( 512 * MB_IN_BYTES ), JIMCA_Limits::pdf_ceiling_for( 256 * MB_IN_BYTES ) );
	}

	public function test_a_tiny_memory_limit_still_accepts_small_pdfs() {
		$this->assertSame( 2 * MB_IN_BYTES, JIMCA_Limits::pdf_ceiling_for( 24 * MB_IN_BYTES ) );
	}
}
