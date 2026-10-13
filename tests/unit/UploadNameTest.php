<?php
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-conversion-job.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * The browser sends files under a neutral name and the real one encoded
 * (base64url of its UTF-8 bytes) in "jimca_name".
 *
 * @covers JIMCA_Conversion_Job::decoded_name
 * @covers JIMCA_Conversion_Job::original_name
 * @covers JIMCA_Conversion_Job::title_for
 */
class UploadNameTest extends UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs(
			array(
				'wp_unslash'         => static function ( $value ) {
					return $value;
				},
				'sanitize_file_name' => static function ( $name ) {
					$name = str_replace( array( "'", '’' ), '', (string) $name );

					return preg_replace( '/[\s]+/', '-', trim( strtr( $name, self::ACCENTS ) ) );
				},
			)
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private static function encode( $name ) {
		return rtrim( strtr( base64_encode( $name ), '+/', '-_' ), '=' );
	}

	private static function call( $method, ...$args ) {
		$reflection = new ReflectionMethod( 'JIMCA_Conversion_Job', $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( null, ...$args );
	}

	public function test_decodes_the_real_name_with_accents_and_apostrophes() {
		$_POST['jimca_name'] = self::encode( "L'Étourdi ou les contre-temps.txt" );

		$this->assertSame( "L'Étourdi ou les contre-temps.txt", self::call( 'decoded_name', 'jim-1-abc.txt' ) );
	}

	public function test_non_latin_names_survive_the_round_trip() {
		$_POST['jimca_name'] = self::encode( 'गो-दान.txt' );

		$this->assertSame( 'गो-दान.txt', self::call( 'decoded_name', 'jim-1-abc.txt' ) );
	}

	public function test_missing_field_gives_the_neutral_name() {
		$this->assertSame( '', self::call( 'decoded_name', 'jim-1-abc.txt' ) );
		$this->assertSame( 'jim-1-abc.txt', self::call( 'original_name', 'jim-1-abc.txt' ) );
	}

	public function test_garbage_is_ignored() {
		foreach ( array( 'not base64!', '%%%', str_repeat( 'A', 701 ), self::encode( "\xff\xfe\xfd.txt" ) ) as $bad ) {
			$_POST['jimca_name'] = $bad;

			$this->assertSame( '', self::call( 'decoded_name', 'jim-1-abc.txt' ), 'ignored: ' . substr( $bad, 0, 20 ) );
		}
	}

	public function test_the_encoded_name_cannot_change_the_kind_of_file() {
		$_POST['jimca_name'] = self::encode( 'shell.php' );

		$this->assertSame( '', self::call( 'decoded_name', 'jim-1-abc.txt' ) );
		$this->assertSame( 'jim-1-abc.txt', self::call( 'original_name', 'jim-1-abc.txt' ) );
	}

	public function test_original_name_is_sanitized() {
		$_POST['jimca_name'] = self::encode( "Pêcheur d'Islande.txt" );

		$this->assertSame( 'Pecheur-dIslande.txt', self::call( 'original_name', 'jim-1-abc.txt' ) );
	}

	public function test_title_keeps_accents_and_spaces_of_the_real_name() {
		$_POST['jimca_name'] = self::encode( "Pêcheur d'Islande.txt" );

		$this->assertSame( "Pêcheur d'Islande", self::call( 'title_for', array( 'name' => 'Pecheur-dIslande.txt' ) ) );
	}

	public function test_a_typed_title_wins() {
		$_POST['jimca_name']  = self::encode( 'Real.txt' );
		$_POST['jimca_title'] = 'Meu título';

		$this->assertSame( 'Meu título', self::call( 'title_for', array( 'name' => 'Real.txt' ) ) );
	}

	public function test_without_a_real_name_the_title_is_left_empty_for_the_default() {
		$this->assertSame( '', self::call( 'title_for', array( 'name' => 'jim-1-abc.txt' ) ) );
	}
}
