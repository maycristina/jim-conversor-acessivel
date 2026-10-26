<?php
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-language.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-metadata.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * Language of a document's text (BCP-47) and the attributes it gives the
 * reader: lang on the text (WCAG 3.1.2) and data-doc-lang for the voice.
 *
 * @covers JIMCA_Language
 */
class LanguageTest extends UnitTestCase {

	const SAMPLES = array(
		'pt-BR' => 'A biblioteca da cidade não abre aos domingos, mas também recebe os leitores à noite. Ela foi reformada e já está pronta para os estudantes, que são muitos.',
		'en-US' => 'The library of the city is not open on Sundays, but it is open in the evening. It was renovated and they say that this is the best place for students.',
		'es-ES' => 'La biblioteca de la ciudad no abre los domingos, pero también recibe a los lectores por la noche. Fue renovada y hay muchos estudiantes en el barrio.',
		'de-DE' => 'Die Bibliothek der Stadt ist sonntags nicht geöffnet, aber sie ist auch am Abend offen. Sie war lange zu und ich habe mich auf die Eröffnung gefreut.',
		'fr-FR' => 'La bibliothèque de la ville est fermée le dimanche, mais elle est ouverte le soir. Elle a été rénovée et les étudiants sont nombreux dans le quartier.',
		'ru-RU' => 'Городская библиотека не работает по воскресеньям, но открыта по вечерам. Её недавно отремонтировали, и студентов там теперь много.',
		'zh-CN' => '城市图书馆星期天不开门，但是晚上开放。图书馆刚刚装修好，现在有很多学生来这里读书。',
		'ja-JP' => '市の図書館は日曜日は休みですが、夜は開いています。最近改装されて、学生がたくさん来ています。',
		'hi-IN' => 'शहर का पुस्तकालय रविवार को बंद रहता है, लेकिन शाम को खुला रहता है। हाल ही में इसकी मरम्मत हुई है और अब यहाँ बहुत छात्र आते हैं।',
	);

	public function test_each_language_is_detected() {
		foreach ( self::SAMPLES as $tag => $text ) {
			$this->assertSame( $tag, JIMCA_Language::detect( $text ), $tag );
		}
	}

	public function test_html_is_read_as_text() {
		$this->assertSame( 'en-US', JIMCA_Language::detect( '<h2>The library</h2><p>' . self::SAMPLES['en-US'] . '</p>' ) );
	}

	public function test_a_few_characters_of_another_script_do_not_change_the_language() {
		$text = self::SAMPLES['pt-BR'] . ' O nome do livro em chinês é 图书馆, e em russo, библиотека.';

		$this->assertSame( 'pt-BR', JIMCA_Language::detect( $text ) );
	}

	public function test_chinese_punctuation_alone_does_not_make_a_text_japanese() {
		// Newer PCRE versions count "。" and "「」" as Hiragana/Katakana (Script Extensions).
		$this->assertSame( 'zh-CN', JIMCA_Language::detect( str_repeat( '「天下之事。」', 20 ) ) );
	}

	public function test_too_little_text_says_nothing() {
		$this->assertSame( '', JIMCA_Language::detect( '' ) );
		$this->assertSame( '', JIMCA_Language::detect( 'Relatório 2025' ) );
		$this->assertSame( '', JIMCA_Language::detect( '12 34 56' ) );
	}

	public function test_a_long_text_is_decided_by_its_body_not_its_front_matter() {
		// An English license before a long Spanish text, as in old ebook editions.
		$text = str_repeat( self::SAMPLES['en-US'] . ' ', 20 ) . str_repeat( self::SAMPLES['es-ES'] . ' ', 300 );

		$this->assertSame( 'es-ES', JIMCA_Language::detect( $text ) );
	}

	public function test_primary_subtag() {
		$this->assertSame( 'pt', JIMCA_Language::primary( 'pt-BR' ) );
		$this->assertSame( 'zh', JIMCA_Language::primary( 'zh_CN' ) );
		$this->assertSame( 'en', JIMCA_Language::primary( 'EN' ) );
		$this->assertSame( '', JIMCA_Language::primary( '' ) );
	}

	public function test_reader_attributes_carry_the_full_tag() {
		// The full tag on the text too: screen readers tell pt-BR from pt-PT, and bin/seo-audit.js requires lang === inLanguage.
		foreach ( array( 'pt-BR', 'pt-PT', 'en-US', 'es-ES', 'de-DE', 'ru-RU', 'zh-CN', 'hi-IN' ) as $tag ) {
			$this->assertSame(
				array(
					'lang'          => $tag,
					'data-doc-lang' => $tag,
				),
				JIMCA_Language::attributes( $tag ),
				$tag
			);
		}
	}

	public function test_document_language_saved_wins_over_detection() {
		Functions\when( 'get_post_meta' )->justReturn( array( 'language' => 'pt-PT' ) );

		$this->assertSame( 'pt-PT', JIMCA_Language::of_document( 7, '<p>' . self::SAMPLES['en-US'] . '</p>' ) );
	}

	public function test_document_without_a_saved_language_is_detected_from_its_text() {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$this->assertSame( 'ru-RU', JIMCA_Language::of_document( 7, '<p>' . self::SAMPLES['ru-RU'] . '</p>' ) );
	}

	public function test_document_that_says_nothing_falls_back_to_the_site_language() {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_locale' )->justReturn( 'de_DE' );

		$this->assertSame( 'de-DE', JIMCA_Language::of_document( 7, '<p>2025</p>' ) );
	}

	public function test_the_template_puts_lang_on_the_text_and_data_doc_lang_on_the_reader() {
		$template = (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/document-viewer.php' );

		// On the reader (the voice reads it) and on the text only: the buttons are in the site's language.
		// The <section> tag is one line, with PHP echo tags inside it (whose closing tag has a ">").
		$this->assertMatchesRegularExpression( '/<section class="jimca-viewer[^\n]*data-doc-lang="<\?php echo esc_attr\( \$lang_attributes\[\'data-doc-lang\'\] \); \?>"/', $template );
		$this->assertMatchesRegularExpression( '/data-jimca-content lang="<\?php echo esc_attr\( \$lang_attributes\[\'lang\'\] \); \?>"/', $template );
		$this->assertDoesNotMatchRegularExpression( '/<section class="jimca-viewer[^\n]*\slang=/', $template );
	}
}
