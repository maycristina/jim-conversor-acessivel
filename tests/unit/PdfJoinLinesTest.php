<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-converter-interface.php';
require_once dirname( __DIR__, 2 ) . '/includes/converters/class-pdf-converter.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * How two lines of a PDF paragraph are joined, around a hyphen at the end
 * of the first one.
 *
 * @covers JIMCA_Pdf_Converter::join_lines
 */
class PdfJoinLinesTest extends UnitTestCase {

	/**
	 * @param string $before
	 * @param string $after
	 * @param string $language Language subtag of the text.
	 * @return string
	 */
	private static function join( $before, $after, $language = 'pt' ) {
		$method = new ReflectionMethod( 'JIMCA_Pdf_Converter', 'join_lines' );
		$method->setAccessible( true );

		return $method->invoke( null, $before, $after, $language );
	}

	public function test_lines_without_a_hyphen_are_joined_with_a_space() {
		$this->assertSame( 'sem interrupções e unir', self::join( 'sem interrupções', 'e unir' ) );
	}

	public function test_a_word_cut_at_the_end_of_the_line_is_joined_without_the_hyphen() {
		$this->assertSame( 'palavras translineadas', self::join( 'palavras trans-', 'lineadas' ) );
		$this->assertSame( 'a acessibilidade', self::join( 'a acessi-', 'bilidade' ) );
	}

	public function test_enclitic_pronouns_keep_the_hyphen() {
		$this->assertSame( 'encontrá-lo de novo', self::join( 'encontrá-', 'lo de novo' ) );
		$this->assertSame( 'guardá-la bem.', self::join( 'guardá-', 'la bem.' ) );
		$this->assertSame( 'dá-se-lhe', self::join( 'dá-', 'se-lhe' ) );
		$this->assertSame( 'entregar-lhe', self::join( 'entregar-', 'lhe' ) );
		$this->assertSame( 'dá-nos', self::join( 'dá-', 'nos' ) );
		$this->assertSame( 'vê-se', self::join( 'vê-', 'se' ) );
		// Found in real books: a two-letter part without an accent is a cut word, and "lhes" ends "detalhes".
		$this->assertSame( 'pelo menos', self::join( 'pelo me-', 'nos' ) );
		$this->assertSame( 'o nome', self::join( 'o no-', 'me' ) );
		$this->assertSame( 'os detalhes', self::join( 'os deta-', 'lhes' ) );
		$this->assertSame( 'assisti-los', self::join( 'assisti-', 'los' ) );
		$this->assertSame( 'fazemo-lo', self::join( 'fazemo-', 'lo' ) );
		$this->assertSame( 'fazê-la', self::join( 'fazê-', 'la' ) );
		// -lo/-la/-los/-las only after a verb that lost its r, s or z: these are cut words.
		$this->assertSame( 'os capítulos', self::join( 'os capítu-', 'los' ) );
		$this->assertSame( 'dos símbolos', self::join( 'dos símbo-', 'los' ) );
		// "lada" only starts like "la": the cut word is joined.
		$this->assertSame( 'a salada', self::join( 'a sa-', 'lada' ) );
		// The price of having no dictionary: a cut word whose second half is a pronoun keeps the hyphen.
		$this->assertSame( 'pas-se', self::join( 'pas-', 'se' ) );
	}

	public function test_hyphenated_prefixes_keep_the_hyphen() {
		$this->assertSame( 'de pós-graduação', self::join( 'de pós-', 'graduação' ) );
		$this->assertSame( 'os recém-chegados', self::join( 'os recém-', 'chegados' ) );
		$this->assertSame( 'um guarda-chuva', self::join( 'um guarda-', 'chuva' ) );
		// Not "ex-": words starting with "ex" are cut right after it far more often.
		$this->assertSame( 'instruções explícitas', self::join( 'instruções ex-', 'plícitas' ) );
		$this->assertSame( 'existem', self::join( 'ex-', 'istem' ) );
		$this->assertSame( 'Pré-escola', self::join( 'Pré-', 'escola' ) );
		$this->assertSame( 'o vice-reitor', self::join( 'o vice-', 'reitor' ) );
		// Only the whole word is a prefix: "aguarda-" + "va" is "aguardava".
		$this->assertSame( 'ela aguardava', self::join( 'ela aguarda-', 'va' ) );
	}

	public function test_o_a_os_as_keep_the_hyphen_only_after_a_gerund() {
		$this->assertSame( 'executando-os', self::join( 'executando-', 'os' ) );
		$this->assertSame( 'fazendo-a agora', self::join( 'fazendo-', 'a agora' ) );
		$this->assertSame( 'mostrando-o', self::join( 'mostrando-', 'o' ) );
		$this->assertSame( 'deixando-as.', self::join( 'deixando-', 'as.' ) );
		// No word ends in "ndoos": joining is never right there. Elsewhere it is a cut word.
		$this->assertSame( 'os vários', self::join( 'os vári-', 'os' ) );
		$this->assertSame( 'as próprias', self::join( 'as própri-', 'as' ) );
		// Only the whole word: "ostras" is not "os".
		$this->assertSame( 'comendoostras', self::join( 'comendo-', 'ostras' ) );
	}

	public function test_one_letter_before_the_hyphen_is_never_a_cut() {
		// Hyphenation leaves at least two letters before the break.
		$this->assertSame( 'os e-mails', self::join( 'os e-', 'mails' ) );
		$this->assertSame( 'um e-book', self::join( 'um e-', 'book' ) );
	}

	public function test_auto_and_anti_keep_the_hyphen_only_before_h_or_the_same_vowel() {
		$this->assertSame( 'a auto-observação', self::join( 'a auto-', 'observação' ) );
		$this->assertSame( 'o anti-inflamatório', self::join( 'o anti-', 'inflamatório' ) );
		$this->assertSame( 'anti-higiênico', self::join( 'anti-', 'higiênico' ) );
		$this->assertSame( 'o automóvel', self::join( 'o auto-', 'móvel' ) );
		$this->assertSame( 'o prédio antigo', self::join( 'o prédio anti-', 'go' ) );
	}

	public function test_words_cut_on_the_same_line_are_joined() {
		$method = new ReflectionMethod( 'JIMCA_Pdf_Converter', 'join_cut_words' );
		$method->setAccessible( true );
		$join = static function ( $line ) use ( $method ) {
			return $method->invoke( null, $line, 'pt' );
		};

		$this->assertSame( 'Essa complexidade, aliás, tem um nome', $join( 'Essa com- plexidade, aliás, tem um nome' ) );
		$this->assertSame( 'geralmente e provavelmente', $join( 'geral- mente e provavel- mente' ) );
		$this->assertSame( 'para testá-lo depois e a pós-graduação', $join( 'para testá- lo depois e a pós- graduação' ) );
		$this->assertSame( 'executando-os e substituindo-os', $join( 'executando- os e substituindo- os' ) );
		$this->assertSame( 'fazendo-a com calma', $join( 'fazendo- a com calma' ) );
		// A lone "o" or "a" after anything but a gerund is left as it is.
		$this->assertSame( 'pré- a pós', $join( 'pré- a pós' ) );
		$this->assertSame( 'e- o', $join( 'e- o' ) );
		// Suspended hyphens stay: "pré- e pós-operatório", "micro- ou macroeconomia".
		$this->assertSame( 'no pré- e pós-operatório', $join( 'no pré- e pós-operatório' ) );
		$this->assertSame( 'micro- ou macroeconomia', $join( 'micro- ou macroeconomia' ) );
		$this->assertSame( 'pre- and post-war', $join( 'pre- and post-war' ) );
		$this->assertSame( 'nem micro- nem macro', $join( 'nem micro- nem macro' ) );
		// Numbers, capitals, a dash between spaces and one-letter words are left alone.
		$this->assertSame( 'de 2010- 2012', $join( 'de 2010- 2012' ) );
		$this->assertSame( 'Rio- Grande', $join( 'Rio- Grande' ) );
		$this->assertSame( 'ela disse - depois', $join( 'ela disse - depois' ) );
	}

	public function test_a_capital_letter_or_a_dash_is_not_a_cut_word() {
		// Before a capital letter the hyphen is real: kept, with no space (see PdfMultilingualTest).
		$this->assertSame( 'Rio-Grande', self::join( 'Rio-', 'Grande' ) );
		$this->assertSame( 'em 2010- 2012', self::join( 'em 2010-', '2012' ) );
		$this->assertSame( 'ela disse — depois', self::join( 'ela disse —', 'depois' ) );
	}
}
