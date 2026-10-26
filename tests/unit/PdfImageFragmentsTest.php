<?php
require_once dirname( __DIR__, 2 ) . '/includes/converters/class-pdf-image-extractor.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * @covers JIMCA_Pdf_Image_Extractor::fragment_sizes
 */
class PdfImageFragmentsTest extends UnitTestCase {

	/*
	 * Sizes of the different images of a web page printed with "Microsoft
	 * Print to PDF": text lines turned into wide strips, carousel arrows and
	 * pieces of colored code ("</"), and the browser's broken-image icon.
	 */
	public function test_printed_web_page() {
		$sizes = array(
			array( 4491, 219 ),
			array( 4491, 219 ),
			array( 101, 100 ),
			array( 101, 101 ),
			array( 96, 81 ),
			array( 97, 81 ),
			array( 97, 81 ),
		);
		for ( $i = 0; $i < 10; $i++ ) {
			$sizes[] = array( 39, 56 );
		}

		$fragments = JIMCA_Pdf_Image_Extractor::fragment_sizes( $sizes );

		$this->assertArrayHasKey( '39x56', $fragments );
		$this->assertArrayHasKey( '96x81', $fragments, 'one pixel apart from two 97x81: the same fragment' );
		$this->assertArrayHasKey( '97x81', $fragments );
		$this->assertArrayNotHasKey( '4491x219', $fragments, 'large images are never fragments' );
		$this->assertArrayNotHasKey( '101x100', $fragments, 'more than FRAGMENT_SIDE' );
	}

	public function test_a_few_small_images_of_the_same_size_stay() {
		$fragments = JIMCA_Pdf_Image_Extractor::fragment_sizes( array( array( 64, 64 ), array( 64, 64 ), array( 80, 40 ) ) );

		$this->assertSame( array(), $fragments );
	}

	public function test_sizes_further_apart_than_the_tolerance_do_not_add_up() {
		$fragments = JIMCA_Pdf_Image_Extractor::fragment_sizes( array( array( 50, 50 ), array( 50, 50 ), array( 53, 50 ) ) );

		$this->assertSame( array(), $fragments );
	}
}
