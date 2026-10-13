<?php
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-ai.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * Error text from the provider (or cURL) goes to the log and to the administrator:
 * the API key must never be in it, even when a service echoes the request back.
 *
 * @covers JIMCA_AI::redact
 */
class AiErrorRedactionTest extends UnitTestCase {

	const KEY = 'sk-or-v1-AbCd1234+/EfGh5678=';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	private function redact( $text, array $headers ) {
		$method = new ReflectionMethod( 'JIMCA_AI', 'redact' );
		$method->setAccessible( true );

		return $method->invoke( null, $text, $headers );
	}

	public function test_the_key_sent_as_a_bearer_token_is_hidden() {
		$out = $this->redact( 'Bad request, got header Authorization: Bearer ' . self::KEY, array( 'Authorization' => 'Bearer ' . self::KEY ) );

		$this->assertStringNotContainsString( self::KEY, $out );
		$this->assertStringContainsString( '••••', $out );
	}

	public function test_the_anthropic_header_is_hidden_too() {
		$out = $this->redact( 'invalid x-api-key: ' . self::KEY, array( 'x-api-key' => self::KEY ) );

		$this->assertStringNotContainsString( self::KEY, $out );
	}

	public function test_url_encoded_and_json_escaped_copies_are_hidden() {
		$text = 'url: ?k=' . rawurlencode( self::KEY ) . ' json: ' . trim( json_encode( self::KEY ), '"' );
		$out  = $this->redact( $text, array( 'Authorization' => 'Bearer ' . self::KEY ) );

		$this->assertStringNotContainsString( rawurlencode( self::KEY ), $out );
		$this->assertStringNotContainsString( trim( json_encode( self::KEY ), '"' ), $out );
		$this->assertStringNotContainsString( 'AbCd1234', $out );
	}

	public function test_a_long_message_is_cut() {
		$out = $this->redact( str_repeat( 'x', 2000 ), array() );

		$this->assertSame( 301, mb_strlen( $out ) );
	}

	public function test_a_short_value_is_not_treated_as_a_key() {
		$out = $this->redact( 'model not found', array( 'Authorization' => 'Bearer ok' ) );

		$this->assertSame( 'model not found', $out );
	}

	public function test_a_server_without_a_key_changes_nothing() {
		$out = $this->redact( 'connection refused', array( 'Content-Type' => 'application/json' ) );

		$this->assertSame( 'connection refused', $out );
	}
}
