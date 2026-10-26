<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-converter-interface.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-language.php';
require_once dirname( __DIR__, 2 ) . '/includes/converters/class-pdf-converter.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * Joining the lines of a PDF paragraph in Portuguese, English, Spanish,
 * German, Russian, Chinese and Hindi: Unicode properties instead of the
 * Latin alphabet, and each language's hyphen rules only in that language.
 *
 * @covers JIMCA_Pdf_Converter::join_lines
 * @covers JIMCA_Pdf_Converter::join_cut_words
 * @covers JIMCA_Pdf_Converter::keeps_hyphen
 */
class PdfMultilingualTest extends UnitTestCase {

	/**
	 * @param string $before
	 * @param string $after
	 * @param string $language
	 * @return string
	 */
	private static function join( $before, $after, $language ) {
		$method = new ReflectionMethod( 'JIMCA_Pdf_Converter', 'join_lines' );
		$method->setAccessible( true );

		return $method->invoke( null, $before, $after, $language );
	}

	/**
	 * Paragraphs that reconstruct_paragraphs() builds from page text.
	 *
	 * @param string $text     Lines of a page.
	 * @param string $language Language subtag.
	 * @return string[]
	 */
	private static function paragraphs( $text, $language ) {
		$converter = new JIMCA_Pdf_Converter( null, array( 'language' => $language ) );
		$method    = new ReflectionMethod( 'JIMCA_Pdf_Converter', 'reconstruct_paragraphs' );
		$method->setAccessible( true );

		return $method->invoke( $converter, $text );
	}

	public function test_portuguese_keeps_its_pronouns_and_prefixes() {
		$this->assertSame( 'encontrá-lo', self::join( 'encontrá-', 'lo', 'pt' ) );
		$this->assertSame( 'chama-se', self::join( 'chama-', 'se', 'pt' ) );
		$this->assertSame( 'pós-graduação', self::join( 'pós-', 'graduação', 'pt' ) );
		$this->assertSame( 'acessibilidade', self::join( 'acessi-', 'bilidade', 'pt' ) );
	}

	public function test_english_does_not_take_portuguese_pronouns() {
		$this->assertSame( 'welcome', self::join( 'welco-', 'me', 'en' ) );
		$this->assertSame( 'complete', self::join( 'comple-', 'te', 'en' ) );
		$this->assertSame( 'purpose', self::join( 'purpo-', 'se', 'en' ) );
		$this->assertSame( 'accessibility', self::join( 'accessi-', 'bility', 'en' ) );
		// The same words in Portuguese mode keep the hyphen: the language decides.
		$this->assertSame( 'welco-me', self::join( 'welco-', 'me', 'pt' ) );
	}

	public function test_spanish_does_not_take_portuguese_pronouns_or_prefixes() {
		$this->assertSame( 'modelo', self::join( 'mode-', 'lo', 'es' ) );
		$this->assertSame( 'guardarropa', self::join( 'guarda-', 'rropa', 'es' ) );
		$this->assertSame( 'corazón', self::join( 'cora-', 'zón', 'es' ) );
	}

	public function test_unknown_language_runs_only_the_universal_rules() {
		$this->assertSame( 'modelo', self::join( 'mode-', 'lo', '' ) );
		$this->assertSame( 'e-mail', self::join( 'e-', 'mail', '' ) );
	}

	public function test_german_compound_before_a_capital_keeps_the_hyphen_with_no_space() {
		$this->assertSame( 'Software-Entwicklung', self::join( 'Software-', 'Entwicklung', 'de' ) );
		$this->assertSame( 'die Software-Entwicklung', self::join( 'die Software-', 'Entwicklung', 'de' ) );
		// Lowercase continues a cut word, ß and umlauts included (\p{Ll}).
		$this->assertSame( 'Straße', self::join( 'Stra-', 'ße', 'de' ) );
		$this->assertSame( 'Mädchen', self::join( 'Mäd-', 'chen', 'de' ) );
		$this->assertSame( 'Übersetzung', self::join( 'Überset-', 'zung', 'de' ) );
	}

	public function test_german_suspended_hyphen_stays_on_the_same_line() {
		$method = new ReflectionMethod( 'JIMCA_Pdf_Converter', 'join_cut_words' );
		$method->setAccessible( true );

		$this->assertSame( 'Ein- und Ausgang', $method->invoke( null, 'Ein- und Ausgang', 'de' ) );
		$this->assertSame( 'Vor- oder Nachteil', $method->invoke( null, 'Vor- oder Nachteil', 'de' ) );
		$this->assertSame( 'die Entwicklung', $method->invoke( null, 'die Entwick- lung', 'de' ) );
	}

	public function test_russian_cyrillic_words_are_joined() {
		$this->assertSame( 'книга', self::join( 'кни-', 'га', 'ru' ) );
		$this->assertSame( 'получить', self::join( 'по-', 'лучить', 'ru' ), 'по- is a prefix of ordinary words too' );
		$this->assertSame( 'известный', self::join( 'из-', 'вестный', 'ru' ) );
		$this->assertSame( 'лето', self::join( 'ле-', 'то', 'ru' ), '-то only after the words it goes with' );
		$this->assertSame( 'рука', self::join( 'ру-', 'ка', 'ru' ), '-ка only after an imperative' );
	}

	public function test_russian_particles_and_prefixes_keep_the_hyphen() {
		$this->assertSame( 'кто-то', self::join( 'кто-', 'то', 'ru' ) );
		$this->assertSame( 'где-нибудь', self::join( 'где-', 'нибудь', 'ru' ) );
		$this->assertSame( 'какой-либо', self::join( 'какой-', 'либо', 'ru' ) );
		$this->assertSame( 'всё-таки', self::join( 'всё-', 'таки', 'ru' ) );
		$this->assertSame( 'дай-ка', self::join( 'дай-', 'ка', 'ru' ) );
		$this->assertSame( 'смотри-ка', self::join( 'смотри-', 'ка', 'ru' ) );
		$this->assertSame( 'по-русски', self::join( 'по-', 'русски', 'ru' ) );
		$this->assertSame( 'по-моему', self::join( 'по-', 'моему', 'ru' ) );
		$this->assertSame( 'из-за', self::join( 'из-', 'за', 'ru' ) );
		$this->assertSame( 'из-под', self::join( 'из-', 'под', 'ru' ) );
		// Portuguese rules do not run in Russian, nor Russian ones in Portuguese.
		$this->assertSame( 'кто-то', self::join( 'кто-', 'то', 'ru' ) );
		$this->assertSame( 'ктото', self::join( 'кто-', 'то', 'pt' ) );
	}

	public function test_chinese_lines_join_with_no_space() {
		$this->assertSame( '这是一个关于阅读的句子', self::join( '这是一个关于', '阅读的句子', 'zh' ) );
		$this->assertSame( '他说：「你好。」', self::join( '他说：', '「你好。」', 'zh' ) );
		$this->assertSame( 'カタカナとひらがな', self::join( 'カタカナと', 'ひらがな', 'ja' ) );
		// Chinese next to Latin text keeps the space.
		$this->assertSame( 'PDF 文件', self::join( 'PDF', '文件', 'zh' ) );
	}

	public function test_hindi_lines_join_with_a_space_and_keep_their_vowel_signs() {
		$this->assertSame( 'यह एक वाक्य है।', self::join( 'यह एक', 'वाक्य है।', 'hi' ) );
	}

	public function test_a_copyright_page_in_english_does_not_settle_a_portuguese_book_as_english() {
		$converter = new JIMCA_Pdf_Converter();
		$learn     = new ReflectionMethod( 'JIMCA_Pdf_Converter', 'learn_language' );
		$learn->setAccessible( true );
		$language = new ReflectionProperty( 'JIMCA_Pdf_Converter', 'language' );
		$language->setAccessible( true );

		$learn->invoke( $converter, 'Copyright 2024. All rights reserved. No part of this book may be reproduced, stored or sent in any form without the permission of the publisher, and this is the law.' );
		$this->assertSame( 'en', $language->getValue( $converter ), 'the first page alone says English' );

		$chapter = 'A biblioteca da cidade não abre aos domingos, mas também recebe os leitores à noite. Ela foi reformada e já está pronta para os estudantes, que são muitos. ';
		for ( $page = 0; $page < 6; $page++ ) {
			$learn->invoke( $converter, str_repeat( $chapter, 3 ) );
		}
		$this->assertSame( 'pt', $language->getValue( $converter ), 'the chapters that follow settle it as Portuguese' );

		// Settled: later pages in another language do not change it.
		$learn->invoke( $converter, str_repeat( 'The end. This is the last page of the book and it is in English. ', 40 ) );
		$this->assertSame( 'pt', $language->getValue( $converter ) );
	}

	public function test_a_table_of_contents_in_capitals_does_not_settle_the_language_as_unknown() {
		// Found in a real document (a university regulation): cover and contents in capitals, then the text.
		$converter = new JIMCA_Pdf_Converter();
		$learn     = new ReflectionMethod( 'JIMCA_Pdf_Converter', 'learn_language' );
		$learn->setAccessible( true );
		$language = new ReflectionProperty( 'JIMCA_Pdf_Converter', 'language' );
		$language->setAccessible( true );

		$learn->invoke( $converter, str_repeat( "CAPÍTULO II – DA DEFESA DO TRABALHO DE CONCLUSÃO DE CURSO ............26\nSEÇÃO I – DO AGENDAMENTO ............26\n", 30 ) );
		$this->assertSame( '', $language->getValue( $converter ), 'the contents alone say nothing' );

		$learn->invoke( $converter, str_repeat( 'O estudante não pode defender o trabalho sem a autorização do orientador, que também assina a ata da banca. ', 10 ) );
		$this->assertSame( 'pt', $language->getValue( $converter ), 'the text that follows is still read' );
	}

	public function test_a_language_chosen_on_import_is_never_overridden() {
		$converter = new JIMCA_Pdf_Converter( null, array( 'language' => 'pt-BR' ) );
		$learn     = new ReflectionMethod( 'JIMCA_Pdf_Converter', 'learn_language' );
		$learn->setAccessible( true );
		$language = new ReflectionProperty( 'JIMCA_Pdf_Converter', 'language' );
		$language->setAccessible( true );

		$learn->invoke( $converter, str_repeat( 'This page is in English and it has the words that tell it. ', 20 ) );
		$this->assertSame( 'pt', $language->getValue( $converter ) );
	}

	public function test_full_stops_of_every_script_end_a_paragraph() {
		$this->assertSame( array( 'Primeira frase.', 'Segunda frase.' ), self::paragraphs( "Primeira frase.\nSegunda frase.", 'pt' ) );
		$this->assertSame( array( '第一句话。', '第二句话！', '第三句话？' ), self::paragraphs( "第一句话。\n第二句话！\n第三句话？", 'zh' ) );
		$this->assertSame( array( 'यह पहला वाक्य है।', 'यह दूसरा है॥' ), self::paragraphs( "यह पहला वाक्य है।\nयह दूसरा है॥", 'hi' ) );
		// A Chinese sentence cut across lines is joined with no space.
		$this->assertSame( array( '这是一个很长的句子。' ), self::paragraphs( "这是一个\n很长的句子。", 'zh' ) );
		$this->assertSame( array( 'Это первое предложение.', 'Это второе!' ), self::paragraphs( "Это первое\nпредложение.\nЭто второе!", 'ru' ) );
	}
}
