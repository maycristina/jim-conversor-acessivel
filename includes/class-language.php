<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language of a document's text, as a BCP-47 tag, found locally from a
 * sample of the text: no service, no cost.
 *
 * Scripts that only a few languages use decide at once (Han and Kana,
 * Devanagari, Cyrillic). Latin-script text is told apart by counting short
 * words that are frequent in one language and rare in the others.
 *
 * The tag marks the text for screen readers (lang, WCAG 3.1.2), picks the
 * reading voice and turns the Portuguese hyphenation rules on or off (see
 * JIMCA_Pdf_Converter).
 */
class JIMCA_Language {

	/** Characters of the text looked at: the start of a document tells its language. */
	const SAMPLE = 20000;

	/** Share of the letters a non-Latin script needs: a Portuguese text quoting a few Chinese characters stays Portuguese. */
	const SCRIPT_SHARE = 0.3;

	/** Matches of a language's words needed to name it. */
	const MIN_MATCHES = 3;

	/**
	 * Words frequent in one language and rare in the others (no "de", "que",
	 * "a": shared by several).
	 */
	const WORDS = array(
		'pt-BR' => array( 'não', 'você', 'são', 'também', 'então', 'muito', 'isso', 'pelo', 'pela', 'uma', 'ao', 'às', 'foi', 'já', 'até', 'ele', 'ela', 'seu', 'sua', 'das', 'nós', 'pelos', 'estão', 'mesmo' ),
		'en-US' => array( 'the', 'and', 'of', 'to', 'is', 'that', 'with', 'was', 'for', 'it', 'are', 'this', 'have', 'from', 'which', 'be', 'not', 'by', 'they', 'you', 'were', 'has' ),
		'es-ES' => array( 'el', 'los', 'las', 'del', 'y', 'pero', 'muy', 'también', 'cuando', 'sus', 'hay', 'esto', 'eso', 'ese', 'esa', 'donde', 'qué', 'fue', 'han', 'él' ),
		'de-DE' => array( 'der', 'die', 'und', 'das', 'ist', 'nicht', 'ein', 'eine', 'zu', 'mit', 'sich', 'den', 'dem', 'auch', 'auf', 'ich', 'war', 'wie', 'aber', 'sie', 'wir', 'ihr' ),
		'fr-FR' => array( 'le', 'les', 'des', 'et', 'est', 'une', 'du', 'pas', 'qui', 'dans', 'pour', 'avec', 'ce', 'je', 'il', 'sont', 'ne', 'nous', 'vous', 'elle', 'sur', 'aux' ),
	);

	/**
	 * @param string $text Plain text or HTML.
	 * @return string BCP-47 tag (pt-BR, en-US, es-ES, de-DE, fr-FR, ru-RU, zh-CN, ja-JP, hi-IN), or '' when unsure.
	 */
	public static function detect( $text ) {
		$text   = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
		$length = mb_strlen( $text );

		if ( $length <= self::SAMPLE ) {
			return self::detect_sample( $text );
		}

		/*
		 * A long text votes in five stretches spread from 10% to 90% of it:
		 * the front matter of a book (a license, a translator's preface) and
		 * its back matter (notes, a glossary) are often in another language,
		 * and the body in the middle wins.
		 */
		$votes = array();
		$piece = intdiv( self::SAMPLE, 5 );

		foreach ( array( 0.1, 0.3, 0.5, 0.7, 0.9 ) as $at ) {
			$tag = self::detect_sample( mb_substr( $text, (int) ( $at * $length ) - intdiv( $piece, 2 ), $piece ) );

			if ( '' !== $tag ) {
				$votes[ $tag ] = isset( $votes[ $tag ] ) ? $votes[ $tag ] + 1 : 1;
			}
		}

		arsort( $votes );
		$counts = array_values( $votes );

		// A tie says nothing.
		return $counts && ( 1 === count( $counts ) || $counts[0] > $counts[1] ) ? (string) array_key_first( $votes ) : '';
	}

	/**
	 * @param string $sample Plain text, whitespace collapsed, at most SAMPLE characters.
	 * @return string See detect().
	 */
	private static function detect_sample( $sample ) {
		$letters = (int) preg_match_all( '/\p{L}/u', $sample );

		if ( 0 === $letters ) {
			return '';
		}

		/*
		 * Letters only ((?=\p{L})): newer PCRE versions match \p{Han},
		 * \p{Hiragana} and \p{Devanagari} by Script Extensions, which include
		 * shared punctuation ("。", "「", "।"), and older ones do not. Without
		 * this, a Chinese text full of "。" counted as Japanese on PHP 8.2.
		 * Kana is only Japanese; Han alone is read as Chinese.
		 */
		$kana = (int) preg_match_all( '/(?=\p{L})[\p{Hiragana}\p{Katakana}]/u', $sample );
		$han  = (int) preg_match_all( '/(?=\p{L})\p{Han}/u', $sample );

		if ( $kana + $han >= self::SCRIPT_SHARE * $letters ) {
			return $kana >= 0.1 * ( $kana + $han ) ? 'ja-JP' : 'zh-CN';
		}

		foreach ( array(
			'hi-IN' => '/(?=\p{L})\p{Devanagari}/u',
			'ru-RU' => '/(?=\p{L})\p{Cyrillic}/u',
		) as $tag => $script ) {
			if ( (int) preg_match_all( $script, $sample ) >= self::SCRIPT_SHARE * $letters ) {
				return $tag;
			}
		}

		$counts = array_count_values( preg_split( '/[^\p{L}]+/u', mb_strtolower( $sample ), -1, PREG_SPLIT_NO_EMPTY ) );
		$scores = array();

		foreach ( self::WORDS as $tag => $words ) {
			$scores[ $tag ] = 0;
			foreach ( $words as $word ) {
				$scores[ $tag ] += isset( $counts[ $word ] ) ? $counts[ $word ] : 0;
			}
		}

		arsort( $scores );
		$counts = array_values( $scores );

		// A tie says nothing.
		return $counts[0] >= self::MIN_MATCHES && $counts[0] > $counts[1] ? (string) array_key_first( $scores ) : '';
	}

	/**
	 * The language a document is shown in: the one saved with it (chosen on
	 * import, read from the file or detected on conversion), else detected
	 * from its text (documents converted before detection existed), else the
	 * site's.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $content Its HTML.
	 * @return string BCP-47 tag, never empty.
	 */
	public static function of_document( $post_id, $content ) {
		$saved = JIMCA_Metadata::get( $post_id )['language'];

		if ( '' !== $saved ) {
			return $saved;
		}

		// Runs while the page is built: a book's first 300 KB are plenty and keep it fast.
		$detected = self::detect( mb_strcut( (string) $content, 0, 300000, 'UTF-8' ) );

		return '' !== $detected ? $detected : self::site();
	}

	/**
	 * Language attributes of the reader (WCAG 3.1.2 Language of Parts).
	 *
	 * @param string $tag BCP-47 tag of the document.
	 * @return array{lang: string, data-doc-lang: string} lang: on the text,
	 *         for screen readers; data-doc-lang: on the reader, for the reading
	 *         voice. Both the full tag ("pt-BR"): screen readers tell pt-BR from
	 *         pt-PT, and it matches the inLanguage of the page's schema.org data
	 *         (see JIMCA_SEO).
	 */
	public static function attributes( $tag ) {
		return array(
			'lang'          => (string) $tag,
			'data-doc-lang' => (string) $tag,
		);
	}

	/**
	 * @return string The site's language as a BCP-47 tag (pt_BR -> pt-BR); pt-BR when unknown.
	 */
	public static function site() {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';

		return '' !== $locale ? str_replace( '_', '-', $locale ) : 'pt-BR';
	}

	/**
	 * @param string $tag BCP-47 tag.
	 * @return string The language subtag in lowercase ("pt-BR" -> "pt").
	 */
	public static function primary( $tag ) {
		return strtolower( (string) strtok( str_replace( '_', '-', (string) $tag ), '-' ) );
	}
}
