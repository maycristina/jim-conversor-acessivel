<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Smalot\PdfParser\Parser as PdfParser;

/**
 * Descriptive properties of the uploaded file (author, subject, keywords,
 * language), read from the PDF information dictionary or from the DOCX
 * docProps/core.xml. They feed the SEO tags and the schema.org data of the
 * page that shows the document (see JIMCA_SEO).
 *
 * Everything is read locally: no service, no cost. Whatever is missing is
 * simply left out.
 */
class JIMCA_Metadata {

	const META_KEY = '_jimca_meta';

	/** A PDF is parsed in memory: above this size the metadata is skipped instead of risking the conversion. */
	const MAX_PDF_BYTES = 10485760;

	/** Authors that word processors and scanners fill in when nobody did. */
	const PLACEHOLDER_AUTHORS = array( 'unknown', 'desconhecido', 'user', 'usuario', 'usuário', 'admin', 'administrator', 'administrador', 'owner', 'author', 'autor', 'microsoft', 'microsoft word', 'word', 'scanner', 'anonymous', 'anônimo', 'anonimo' );

	/**
	 * Languages offered when importing a file: tag => name in that language
	 * (a language is recognised by its own name, so the names are not translated).
	 *
	 * @return array<string, string>
	 */
	public static function languages() {
		return array(
			'pt-BR' => 'Português (Brasil)',
			'pt-PT' => 'Português (Portugal)',
			'en'    => 'English',
			'es'    => 'Español',
			'fr'    => 'Français',
			'de'    => 'Deutsch',
			'it'    => 'Italiano',
			'nl'    => 'Nederlands',
			'pl'    => 'Polski',
			'ru'    => 'Русский',
			'uk'    => 'Українська',
			'tr'    => 'Türkçe',
			'ar'    => 'العربية',
			'hi'    => 'हिन्दी',
			'zh-CN' => '中文 (简体)',
			'ja'    => '日本語',
			'ko'    => '한국어',
			'la'    => 'Latina',
		);
	}

	/**
	 * Language chosen in the import form.
	 *
	 * @return string A key of languages(); '' = automatic (the file's own, or the site's).
	 */
	public static function requested_language() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read inside the upload handlers, after they checked the nonce.
		$language = isset( $_POST['jimca_language'] ) ? sanitize_text_field( wp_unslash( $_POST['jimca_language'] ) ) : '';

		return isset( self::languages()[ $language ] ) ? $language : '';
	}

	/**
	 * Author typed in the import form ("default author of the batch").
	 *
	 * @return string '' when empty.
	 */
	public static function requested_author() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read inside the upload handlers, after they checked the nonce.
		$author = isset( $_POST['jimca_author'] ) ? sanitize_text_field( wp_unslash( $_POST['jimca_author'] ) ) : '';

		return mb_substr( $author, 0, 120 );
	}

	/**
	 * Is this name no real author? WordPress user names ("user", "admin") and
	 * the login of the account that published the page say nothing about who
	 * wrote the document.
	 *
	 * @param string $name  Name to check.
	 * @param string $login Login of the account the name came from.
	 * @return bool
	 */
	public static function is_generic_author( $name, $login = '' ) {
		$name = mb_strtolower( trim( (string) $name ) );

		return '' === $name || in_array( $name, self::PLACEHOLDER_AUTHORS, true ) || ( '' !== $login && mb_strtolower( $login ) === $name );
	}

	/**
	 * @param string $file      Absolute path of the uploaded file.
	 * @param string $extension Lowercase extension.
	 * @return array{author: string, subject: string, keywords: string[], language: string} Empty values when unknown.
	 */
	public static function read( $file, $extension ) {
		$raw = array();

		try {
			if ( 'pdf' === $extension ) {
				$raw = self::read_pdf( $file );
			} elseif ( 'docx' === $extension ) {
				$raw = self::read_docx( $file );
			}
		} catch ( \Throwable $e ) {
			$raw = array();
		}

		return self::clean( $raw );
	}

	/**
	 * @param array $raw 'author', 'subject', 'keywords' (string), 'language'.
	 * @return array{author: string, subject: string, keywords: string[], language: string}
	 */
	public static function clean( array $raw ) {
		$author = self::text( isset( $raw['author'] ) ? $raw['author'] : '', 120 );

		if ( self::is_generic_author( $author ) ) {
			$author = '';
		}

		$keywords = array();
		$source   = isset( $raw['keywords'] ) ? (string) $raw['keywords'] : '';

		foreach ( preg_split( '/[;,\n]+/u', $source ) as $word ) {
			$word = self::text( $word, 60 );

			if ( '' !== $word && ! in_array( mb_strtolower( $word ), array_map( 'mb_strtolower', $keywords ), true ) ) {
				$keywords[] = $word;
			}

			if ( count( $keywords ) >= 10 ) {
				break;
			}
		}

		$language = isset( $raw['language'] ) ? trim( (string) $raw['language'] ) : '';

		return array(
			'author'   => $author,
			'subject'  => self::text( isset( $raw['subject'] ) ? $raw['subject'] : '', 300 ),
			'keywords' => $keywords,
			'language' => preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language ) ? $language : '',
		);
	}

	/**
	 * @param mixed $value
	 * @param int   $max   Characters.
	 * @return string One line of plain text.
	 */
	private static function text( $value, $max ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
		}

		$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) );

		return mb_substr( $value, 0, $max );
	}

	private static function read_pdf( $file ) {
		if ( ! class_exists( PdfParser::class ) || ! is_readable( $file ) || filesize( $file ) > self::MAX_PDF_BYTES ) {
			return array();
		}

		$details = ( new PdfParser() )->parseFile( $file )->getDetails();

		return array(
			'author'   => isset( $details['Author'] ) ? $details['Author'] : '',
			'subject'  => isset( $details['Subject'] ) ? $details['Subject'] : '',
			'keywords' => isset( $details['Keywords'] ) ? $details['Keywords'] : '',
		);
	}

	private static function read_docx( $file ) {
		if ( ! class_exists( 'ZipArchive' ) || ! is_readable( $file ) ) {
			return array();
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $file ) ) {
			return array();
		}

		$xml = $zip->getFromName( 'docProps/core.xml', 65536 );
		$zip->close();

		if ( ! is_string( $xml ) ) {
			return array();
		}

		return self::parse_core_xml( $xml );
	}

	/**
	 * @param string $xml Content of docProps/core.xml.
	 * @return array 'author', 'subject', 'keywords', 'language'.
	 */
	public static function parse_core_xml( $xml ) {
		$tag = static function ( $name ) use ( $xml ) {
			return preg_match( '#<' . $name . '(?:\s[^>]*)?>(.*?)</' . $name . '>#is', $xml, $m ) ? html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) : '';
		};

		return array(
			'author'   => $tag( 'dc:creator' ),
			'subject'  => $tag( 'dc:subject' ),
			'keywords' => $tag( 'cp:keywords' ),
			'language' => $tag( 'dc:language' ),
		);
	}

	/**
	 * @param int $post_id Document ID.
	 * @return array{author: string, subject: string, keywords: string[], language: string}
	 */
	public static function get( $post_id ) {
		$meta = get_post_meta( $post_id, self::META_KEY, true );

		return self::clean(
			is_array( $meta ) ? array(
				'author'   => isset( $meta['author'] ) ? $meta['author'] : '',
				'subject'  => isset( $meta['subject'] ) ? $meta['subject'] : '',
				'keywords' => isset( $meta['keywords'] ) ? implode( ',', (array) $meta['keywords'] ) : '',
				'language' => isset( $meta['language'] ) ? $meta['language'] : '',
			) : array()
		);
	}
}
