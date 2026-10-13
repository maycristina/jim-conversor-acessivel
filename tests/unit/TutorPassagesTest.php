<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-tutor.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * @covers JIMCA_Tutor::relevant_passages
 */
class TutorPassagesTest extends UnitTestCase {

	public function test_short_document_goes_whole() {
		$text = JIMCA_Tutor::relevant_passages( '<p>Hammurabi was a king.</p><p>He wrote laws.</p>', 'who?' );

		$this->assertSame( "Hammurabi was a king.\nHe wrote laws.", $text );
	}

	public function test_long_document_sends_the_passages_that_match_the_question() {
		$filler = '';

		for ( $i = 0; $i < 60; $i++ ) {
			$filler .= '<p>' . str_repeat( 'Lorem ipsum dolor sit amet consectetur. ', 8 ) . "Seção $i.</p>";
		}

		$html = $filler . '<p>Hammurabi governou a Babilônia e mandou gravar o famoso código de leis.</p>' . $filler;
		$text = JIMCA_Tutor::relevant_passages( $html, 'Quem foi Hammurabi?' );

		$this->assertStringContainsString( 'Hammurabi governou', $text );
		$this->assertLessThanOrEqual( JIMCA_Tutor::CONTEXT_CHARS + 200, mb_strlen( $text ) );
	}

	public function test_question_without_usable_words_sends_the_start_of_the_document() {
		$html = '<p>' . str_repeat( 'abcdefghij ', 1000 ) . '</p>';
		$text = JIMCA_Tutor::relevant_passages( $html, '?' );

		$this->assertSame( JIMCA_Tutor::CONTEXT_CHARS, mb_strlen( $text ) );
	}
}
