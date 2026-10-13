<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Base dos testes: liga o Brain Monkey e simula as funções puras do WordPress
 * que o código do plugin usa para tratar texto.
 */
abstract class UnitTestCase extends TestCase {

	/** Accented letters and their plain form (strtr() with a string pair would work on bytes, not letters). */
	const ACCENTS = array(
		'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
		'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
		'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
		'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
		'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
		'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Ñ' => 'N',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'wp_strip_all_tags'       => static function ( $text ) {
					return trim( strip_tags( (string) $text ) );
				},
				'remove_accents'          => static function ( $text ) {
					return strtr( (string) $text, self::ACCENTS );
				},
				'sanitize_text_field'     => static function ( $text ) {
					return trim( strip_tags( (string) $text ) );
				},
				'sanitize_textarea_field' => static function ( $text ) {
					return trim( strip_tags( (string) $text ) );
				},
				'__'                      => static function ( $text ) {
					return $text;
				},
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
