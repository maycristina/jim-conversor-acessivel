<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO tags for pages that publish a document.
 *
 * Documents have no URL of their own: they appear inside the page or post
 * holding the [documento_acessivel] shortcode. So the tags (description,
 * Open Graph, Twitter Card and schema.org/Article JSON-LD) go in the <head>
 * of that page, built from the document.
 *
 * When Yoast, Rank Math, All in One SEO or SEOPress is active, Jim prints no
 * tags of its own, to avoid duplicates. Instead it hands the description and
 * the accessibility data of the document to that plugin through its filters
 * (see hook_other_plugins()). The `jimca_seo_enabled` filter can turn it off
 * or force it.
 *
 * Only the start of the content is read: the description needs a few lines,
 * and a book of hundreds of thousands of words should not be scanned on
 * every visit.
 */
class JIMCA_SEO {

	/** Characters read from the start of the content to build the description and find the image. */
	const SCAN_CHARS = 300000;

	/** Smallest share image, in pixels: social networks reject or badly crop logos and banners. */
	const MIN_IMAGE_WIDTH  = 300;
	const MIN_IMAGE_HEIGHT = 150;

	/** Longest <title> that search results show whole, in characters. */
	const TITLE_CHARS = 75;

	/** Maximum description length, in characters. */
	const DESCRIPTION_CHARS = 155;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		add_action( 'wp_head', array( $this, 'render' ), 5 );
		add_filter( 'document_title_parts', array( $this, 'shorten_document_title' ), 20 );
		$this->hook_other_plugins();
	}

	/**
	 * Keeps the <title> of a page that shows a document within what search
	 * engines show (about 75 characters): a long title goes without the site
	 * name, and is cut at a whole word when it still does not fit. The full
	 * title stays on the page. Left alone when another SEO plugin is active.
	 *
	 * @param array $parts Parts of the title: title, page, tagline, site.
	 * @return array
	 */
	public function shorten_document_title( $parts ) {
		$settings = JIMCA_Admin::get_settings();

		if ( empty( $settings['seo_enabled'] ) || self::other_seo_plugin_active() || ! is_singular() || ! self::find_document_id( get_queried_object() ) ) {
			return $parts;
		}

		return self::fit_title_parts( (array) $parts );
	}

	/**
	 * @param array $parts See shorten_document_title().
	 * @return array
	 */
	public static function fit_title_parts( array $parts ) {
		$title = isset( $parts['title'] ) ? (string) $parts['title'] : '';
		$extra = 0;

		foreach ( array( 'site', 'tagline' ) as $key ) {
			if ( ! empty( $parts[ $key ] ) ) {
				$extra += 3 + mb_strlen( (string) $parts[ $key ] );
			}
		}

		if ( mb_strlen( $title ) + $extra <= self::TITLE_CHARS ) {
			return $parts;
		}

		unset( $parts['site'], $parts['tagline'] );

		if ( mb_strlen( $title ) > self::TITLE_CHARS ) {
			$parts['title'] = self::shorten( $title, self::TITLE_CHARS - 5 );
		}

		return $parts;
	}

	/**
	 * The page's document data, once per request, for the filters of other SEO plugins.
	 *
	 * @return array|null See build(); null when this is not a page showing a document, or Jim's SEO is off.
	 */
	private function current_data() {
		static $cache = array();

		if ( ! is_singular() ) {
			return null;
		}

		$page = get_queried_object();

		if ( ! $page || empty( $page->ID ) ) {
			return null;
		}

		if ( ! array_key_exists( $page->ID, $cache ) ) {
			$settings = JIMCA_Admin::get_settings();
			$id       = ! empty( $settings['seo_enabled'] ) ? self::find_document_id( $page ) : 0;

			$cache[ $page->ID ] = $id ? self::build( $id, $page ) : null;
		}

		return $cache[ $page->ID ];
	}

	/**
	 * With another SEO plugin active Jim prints nothing, but the document is
	 * still what the page is about: give that plugin the description of the
	 * document (only where it has none of its own) and the accessibility data
	 * for its WebPage schema. Each plugin documents these filters.
	 */
	private function hook_other_plugins() {
		$description = function ( $current ) {
			$data = '' === trim( (string) $current ) ? $this->current_data() : null;

			return $data && '' !== $data['description'] ? $data['description'] : $current;
		};

		// Yoast SEO.
		add_filter( 'wpseo_metadesc', $description );
		add_filter(
			'wpseo_schema_webpage',
			function ( $piece ) {
				$data = $this->current_data();

				return $data && is_array( $piece ) ? self::merge_webpage( $piece, $data ) : $piece;
			}
		);

		// Rank Math.
		add_filter( 'rank_math/frontend/description', $description );
		add_filter(
			'rank_math/json_ld',
			function ( $graph ) {
				$data = $this->current_data();

				if ( $data && is_array( $graph ) && isset( $graph['WebPage'] ) && is_array( $graph['WebPage'] ) ) {
					$graph['WebPage'] = self::merge_webpage( $graph['WebPage'], $data );
				}

				return $graph;
			}
		);

		// All in One SEO and SEOPress.
		add_filter( 'aioseo_description', $description );
		add_filter( 'seopress_titles_desc', $description );
	}

	/**
	 * Adds the properties of the document to another plugin's WebPage node,
	 * without overwriting what that plugin already set.
	 *
	 * @param array $piece WebPage node.
	 * @param array $data  See build().
	 * @return array
	 */
	public static function merge_webpage( array $piece, array $data ) {
		$extra = self::document_properties( $data );
		unset( $extra['author'], $extra['publisher'] );

		return $piece + $extra;
	}

	/**
	 * @return bool Is another SEO plugin printing these tags?
	 */
	public static function other_seo_plugin_active() {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * @param WP_Post $post
	 * @return int First published document in the page's shortcodes; 0 when there is none.
	 */
	public static function find_document_id( $post ) {
		if ( ! $post || ! has_shortcode( (string) $post->post_content, JIMCA_Shortcode::TAG ) ) {
			return 0;
		}

		if ( ! preg_match_all( '/' . get_shortcode_regex( array( JIMCA_Shortcode::TAG ) ) . '/', (string) $post->post_content, $matches, PREG_SET_ORDER ) ) {
			return 0;
		}

		foreach ( $matches as $match ) {
			$atts = shortcode_parse_atts( $match[3] );
			$id   = isset( $atts['id'] ) ? absint( $atts['id'] ) : 0;

			if ( $id && JIMCA_Post_Type::POST_TYPE === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
				return $id;
			}
		}

		return 0;
	}

	public function render() {
		$settings = JIMCA_Admin::get_settings();

		$enabled = ! empty( $settings['seo_enabled'] ) && ! self::other_seo_plugin_active();

		/**
		 * Turns Jim's SEO tags on or off.
		 *
		 * @param bool $enabled From the setting, false when another SEO plugin is active.
		 */
		if ( ! apply_filters( 'jimca_seo_enabled', $enabled ) || ! is_singular() ) {
			return;
		}

		$page = get_queried_object();
		$id   = self::find_document_id( $page );

		if ( ! $id ) {
			return;
		}

		$data = self::build( $id, $page );

		echo "\n<!-- Jim - Accessible Converter: SEO -->\n";

		if ( '' !== $data['description'] ) {
			echo '<meta name="description" content="' . esc_attr( $data['description'] ) . '">' . "\n";
		}

		$og = array(
			'og:type'        => 'article',
			'og:title'       => $data['title'],
			'og:description' => $data['description'],
			'og:url'         => $data['url'],
			'og:site_name'   => $data['site_name'],
			'og:locale'      => $data['locale'],
		);

		if ( $data['image'] ) {
			$og['og:image']        = $data['image']['url'];
			$og['og:image:width']  = (string) $data['image']['width'];
			$og['og:image:height'] = (string) $data['image']['height'];
			$og['og:image:alt']    = $data['image']['alt'];
		}

		$og['article:published_time'] = $data['published'];
		$og['article:modified_time']  = $data['modified'];
		$og['og:updated_time']        = $data['modified'];

		foreach ( $og as $property => $content ) {
			if ( '' !== (string) $content ) {
				echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '">' . "\n";
			}
		}

		$twitter = array(
			'twitter:card'        => $data['image'] && empty( $data['image']['fallback'] ) ? 'summary_large_image' : 'summary',
			'twitter:title'       => $data['title'],
			'twitter:description' => $data['description'],
		);

		if ( $data['image'] ) {
			$twitter['twitter:image'] = $data['image']['url'];
		}

		foreach ( $twitter as $name => $content ) {
			if ( '' !== (string) $content ) {
				echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $content ) . '">' . "\n";
			}
		}

		echo '<script type="application/ld+json">' . wp_json_encode( self::json_ld( $data ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n";
	}

	/**
	 * @param int     $id   Document ID.
	 * @param WP_Post $page Page holding the shortcode.
	 * @return array Data for the tags.
	 */
	public static function build( $id, $page ) {
		$post    = get_post( $id );
		$content = substr( (string) $post->post_content, 0, self::SCAN_CHARS );
		$meta    = JIMCA_Metadata::get( $id );
		// The same language the reader marks the text with (lang): inLanguage and og:locale must not differ from it.
		$language = JIMCA_Language::of_document( $id, $post->post_content );

		$image       = self::share_image( $content );
		$words       = (int) get_post_meta( $id, '_jimca_word_count', true );
		$description = self::description( $content );

		// The subject written by the author of the file is a better summary than the first lines.
		if ( mb_strlen( $meta['subject'] ) >= 40 ) {
			$description = self::shorten( $meta['subject'] );
		}

		// Without an author in the file, the account that published the page, unless its name is no real name ("user", "admin").
		$title       = wp_strip_all_tags( get_the_title( $post ) );
		$description = self::align_with_title( $description, $title );

		$page_author = '';

		if ( $page && ! empty( $page->post_author ) ) {
			$name = (string) get_the_author_meta( 'display_name', (int) $page->post_author );

			if ( ! JIMCA_Metadata::is_generic_author( $name, (string) get_the_author_meta( 'user_login', (int) $page->post_author ) ) ) {
				$page_author = $name;
			}
		}

		return array(
			'title'       => $title,
			'description' => $description,
			'url'         => get_permalink( $page ),
			'site_name'   => get_bloginfo( 'name' ),
			'locale'      => self::og_locale( $language ),
			'language'    => $language,
			'published'   => get_post_time( 'c', true, $post ),
			'modified'    => get_post_modified_time( 'c', true, $post ),
			'words'       => $words,
			'minutes'     => $words > 0 ? max( 1, (int) ceil( $words / 200 ) ) : 0,
			'author'      => '' !== $meta['author'] ? $meta['author'] : $page_author,
			'keywords'    => $meta['keywords'],
			'reader'      => 'reader' === JIMCA_Post_Type::get_mode( $id ),
			'structure'   => self::structure( $content ),
			'image'       => $image && $image['url'] ? $image : self::site_image(),
		);
	}

	/**
	 * Share image for a document without a usable picture of its own: the
	 * icon of the site. Better than nothing, since networks and search
	 * engines expect an image.
	 *
	 * @return array{url: string, width: int, height: int, alt: string, fallback: bool}|null
	 */
	public static function site_image() {
		$url = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 512 ) : '';

		if ( '' === (string) $url ) {
			return null;
		}

		return array(
			'url'      => $url,
			'width'    => 512,
			'height'   => 512,
			'alt'      => get_bloginfo( 'name' ),
			'fallback' => true,
		);
	}

	/**
	 * Open Graph locale (ll_CC) of a language tag.
	 *
	 * @param string $tag Language tag: 'fr', 'pt-BR'.
	 * @return string
	 */
	public static function og_locale( $tag ) {
		$parts = explode( '-', str_replace( '_', '-', (string) $tag ) );
		$base  = strtolower( $parts[0] );

		if ( isset( $parts[1] ) && 2 === strlen( $parts[1] ) ) {
			return $base . '_' . strtoupper( $parts[1] );
		}

		$regions = array(
			'pt' => 'BR',
			'en' => 'US',
			'es' => 'ES',
			'fr' => 'FR',
			'de' => 'DE',
			'it' => 'IT',
			'nl' => 'NL',
			'pl' => 'PL',
			'ru' => 'RU',
			'uk' => 'UA',
			'tr' => 'TR',
			'ar' => 'AR',
			'hi' => 'IN',
			'zh' => 'CN',
			'ja' => 'JP',
			'ko' => 'KR',
			'la' => 'VA',
		);

		return $base . '_' . ( isset( $regions[ $base ] ) ? $regions[ $base ] : strtoupper( $base ) );
	}

	/**
	 * What the document markup offers, for the accessibility properties.
	 *
	 * @param string $content Start of the content.
	 * @return array{headings: int, images: int, images_missing_alt: int}
	 */
	public static function structure( $content ) {
		$headings = (int) preg_match_all( '#<h[1-6]\b(?![^>]*jimca-page-title)[^>]*>\s*[^\s<]#i', $content );
		$images   = (int) preg_match_all( '/<img\b/i', $content );
		$missing  = (int) preg_match_all( '/<img\b[^>]*(?:data-jimca-alt-missing|\balt\s*=\s*"")/i', $content );

		return array(
			'headings'           => $headings,
			'images'             => $images,
			'images_missing_alt' => $missing,
		);
	}

	/**
	 * First image of the document big enough to be a social media preview.
	 *
	 * @param string $content Start of the content.
	 * @return array{url: string, width: int, height: int, alt: string}|null
	 */
	public static function share_image( $content ) {
		if ( ! preg_match_all( '/<img\b[^>]*>/i', $content, $tags ) ) {
			return null;
		}

		foreach ( array_slice( $tags[0], 0, 20 ) as $tag ) {
			$width  = preg_match( '/\bwidth\s*=\s*"(\d+)"/i', $tag, $w ) ? (int) $w[1] : 0;
			$height = preg_match( '/\bheight\s*=\s*"(\d+)"/i', $tag, $h ) ? (int) $h[1] : 0;

			if ( $width < self::MIN_IMAGE_WIDTH || $height < self::MIN_IMAGE_HEIGHT || ! preg_match( '/\bsrc\s*=\s*"([^"]+)"/i', $tag, $src ) ) {
				continue;
			}

			$url = esc_url_raw( $src[1] );

			if ( '' === $url ) {
				continue;
			}

			return array(
				'url'    => $url,
				'width'  => $width,
				'height' => $height,
				'alt'    => ( preg_match( '/\balt\s*=\s*"([^"]*)"/i', $tag, $a ) && ! preg_match( '/data-jimca-alt-missing/', $tag ) ) ? html_entity_decode( $a[1], ENT_QUOTES, 'UTF-8' ) : '',
			);
		}

		return null;
	}

	/**
	 * Plain-text summary from the start of the document, without page markers
	 * or table-of-contents lines, cut at a whole word.
	 *
	 * @param string $html Start of the document content.
	 * @return string
	 */
	public static function description( $html ) {
		// Page markers and printed tables of contents say nothing about the content.
		$html = preg_replace( '#<h[1-6][^>]*class="[^"]*jimca-page-title[^"]*"[^>]*>.*?</h[1-6]>#is', ' ', $html );
		$html = preg_replace( '#<(ul|ol)[^>]*class="[^"]*jimca-printed-toc[^"]*"[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#<(script|style|figure|table)\b.*?</\1>#is', ' ', $html );

		/*
		 * Prefer paragraphs of real prose (see prose()): a book usually opens
		 * with a cover, credits, a data sheet and notices. Without such a
		 * paragraph, use whatever text there is.
		 */
		$text = '';

		if ( preg_match_all( '#<p\b[^>]*>(.*?)</p>#is', $html, $paragraphs ) ) {
			foreach ( $paragraphs[1] as $paragraph ) {
				$clean = self::prose( $paragraph );

				if ( mb_strlen( $clean ) >= 40 ) {
					$text .= ( '' === $text ? '' : ' ' ) . $clean;
				}

				if ( mb_strlen( $text ) > self::DESCRIPTION_CHARS ) {
					break;
				}
			}
		}

		if ( '' === $text ) {
			$text = self::clean_separators( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
		}

		return self::shorten( $text );
	}

	/**
	 * Text of a paragraph when it is running prose, '' when it is not. Decided
	 * by the shape of the text, not by words: it works for any document and
	 * language.
	 *
	 * Not prose: a banner that opens with a run of symbols (=== *** ---), a
	 * data sheet ("Key: value" lines), a paragraph with an address or e-mail
	 * (a notice, a footer), and anything with fewer than 8 words or mostly
	 * symbols and digits.
	 *
	 * @param string $paragraph_html Inner HTML of a <p>.
	 * @return string
	 */
	public static function prose( $paragraph_html ) {
		$plain = html_entity_decode( wp_strip_all_tags( preg_replace( '#<br\s*/?>#i', "\n", (string) $paragraph_html ) ), ENT_QUOTES, 'UTF-8' );
		$lines = array();

		foreach ( preg_split( '/\R/u', $plain ) as $line ) {
			$line = trim( preg_replace( '/\s+/u', ' ', $line ) );

			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		if ( ! $lines ) {
			return '';
		}

		$text = implode( ' ', $lines );

		// A banner: "*** NOTICE ***", "=====".
		if ( preg_match( '/^(?:[=*_#~\-]\s*){3,}/u', $text ) ) {
			return '';
		}

		// A data sheet: short "Key: value" lines.
		$sheet = 0;

		foreach ( $lines as $line ) {
			if ( mb_strlen( $line ) <= 80 && preg_match( '/^\p{L}[\p{L} ._-]{0,24}:\s*\S/u', $line ) ) {
				++$sheet;
			}
		}

		if ( $sheet > 0 && $sheet * 2 >= count( $lines ) ) {
			return '';
		}

		// An address or e-mail: a notice or a footer, not the subject of the document.
		if ( preg_match( '~https?://|www\.|[\w.+-]+@[\w-]+\.[\w.-]+|\b[\w-]+\.(?:org|com|net|gov|edu|io|br)\b~iu', $text ) ) {
			return '';
		}

		$text    = self::clean_separators( $text );
		$letters = (int) preg_match_all( '/\p{L}/u', $text );
		$visible = mb_strlen( preg_replace( '/\s+/u', '', $text ) );

		$words = (int) preg_match_all( '/\p{L}{2,}/u', $text );

		if ( 0 === $visible || $words < 8 || $letters / $visible < 0.75 ) {
			return '';
		}

		// A line that is not a sentence (no final punctuation) and is short is a title or a credit, not prose.
		if ( $words < 20 && ! preg_match( '/[.!?…:;»”")]$/u', $text ) ) {
			return '';
		}

		return $text;
	}

	/**
	 * Removes runs of repeated symbols used as visual dividers (=====, -----, *****, _____).
	 *
	 * @param string $text
	 * @return string
	 */
	public static function clean_separators( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', preg_replace( '/([=*_~#\-])\1{2,}/u', ' ', (string) $text ) ) );
	}

	/**
	 * A description that shares no word with the title does not match the
	 * page for search engines: lead with the title.
	 *
	 * @param string $description
	 * @param string $title
	 * @return string
	 */
	public static function align_with_title( $description, $title ) {
		$fold = static function ( $text ) {
			return mb_strtolower( remove_accents( (string) $text ) );
		};

		if ( '' === $description || ! preg_match_all( '/\p{L}{4,}/u', $fold( $title ), $words ) ) {
			return $description;
		}

		$haystack = $fold( $description );

		foreach ( $words[0] as $word ) {
			if ( false !== mb_strpos( $haystack, $word ) ) {
				return $description;
			}
		}

		return self::shorten( rtrim( self::shorten( $title, 80 ), '.…' ) . '. ' . $description );
	}


	/**
	 * Cuts a text to the description length at a whole word.
	 *
	 * @param string $text  Plain text.
	 * @param int    $limit Characters.
	 * @return string
	 */
	public static function shorten( $text, $limit = self::DESCRIPTION_CHARS ) {
		$text = trim( (string) $text );

		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $limit );
		$space = mb_strrpos( $cut, ' ' );

		return rtrim( false !== $space && $space > 60 ? mb_substr( $cut, 0, $space ) : $cut, " ,;:.-" ) . '…';
	}

	/**
	 * @param array $data See build().
	 * @return array schema.org/Article structure.
	 */
	public static function json_ld( array $data ) {
		$ld = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'headline'         => mb_substr( $data['title'], 0, 110 ),
			'description'      => $data['description'],
			'inLanguage'       => $data['language'],
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => $data['url'],
			),
			'datePublished'    => $data['published'],
			'dateModified'     => $data['modified'],
		);

		$ld += self::document_properties( $data );

		if ( $data['image'] ) {
			$ld['image'] = $data['image']['url'];
		}

		return $ld;
	}

	/**
	 * Properties that describe the document itself: who wrote it (E-E-A-T),
	 * how long it takes and how people with disabilities can use it. Used in
	 * Jim's own Article and merged into the WebPage of other SEO plugins.
	 *
	 * Only what the page really offers is declared: a feature that is not
	 * there would be a false claim to search engines.
	 *
	 * @param array $data See build().
	 * @return array
	 */
	public static function document_properties( array $data ) {
		$props = array( 'isAccessibleForFree' => true );

		if ( '' !== $data['author'] ) {
			$props['author'] = array(
				'@type' => 'Person',
				'name'  => $data['author'],
			);
		}

		$props['publisher'] = array(
			'@type' => 'Organization',
			'name'  => $data['site_name'],
		);

		if ( $data['words'] > 0 ) {
			$props['wordCount']    = $data['words'];
			$props['timeRequired'] = 'PT' . max( 1, (int) $data['minutes'] ) . 'M';
		}

		if ( ! empty( $data['keywords'] ) ) {
			$props['keywords'] = implode( ', ', $data['keywords'] );
		}

		$structure = $data['structure'];
		$features  = array( 'readingOrder' );
		$modes     = array( 'textual' );

		if ( $structure['headings'] > 0 ) {
			$features[] = 'structuralNavigation';
		}

		if ( $structure['headings'] > 1 ) {
			$features[] = 'tableOfContents';
		}

		if ( $structure['images'] > 0 ) {
			$modes[] = 'visual';

			if ( 0 === $structure['images_missing_alt'] ) {
				$features[] = 'alternativeText';
			}
		}

		$props['accessMode']           = $modes;
		$props['accessModeSufficient'] = array(
			array(
				'@type'           => 'ItemList',
				'itemListElement' => array( 'textual' ),
			),
		);

		if ( $data['reader'] ) {
			$features[] = 'displayTransformability';
			$features[] = 'highContrastDisplay';

			$props['accessibilityControl'] = array( 'fullKeyboardControl', 'fullMouseControl', 'fullTouchControl' );
		}

		$props['accessibilityFeature'] = $features;

		return $props;
	}
}
