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
 * Nothing is printed when Yoast, Rank Math, All in One SEO or SEOPress is
 * active, to avoid duplicate tags. The `jimca_seo_enabled` filter can turn it
 * off or force it.
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

		foreach ( $og as $property => $content ) {
			if ( '' !== (string) $content ) {
				echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '">' . "\n";
			}
		}

		$twitter = array(
			'twitter:card'        => $data['image'] ? 'summary_large_image' : 'summary',
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

		$image = self::share_image( $content );

		return array(
			'title'       => wp_strip_all_tags( get_the_title( $post ) ),
			'description' => self::description( $content ),
			'url'         => get_permalink( $page ),
			'site_name'   => get_bloginfo( 'name' ),
			'locale'      => get_locale(),
			'language'    => get_bloginfo( 'language' ),
			'published'   => get_post_time( 'c', true, $post ),
			'modified'    => get_post_modified_time( 'c', true, $post ),
			'words'       => (int) get_post_meta( $id, '_jimca_word_count', true ),
			'image'       => $image && $image['url'] ? $image : null,
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
		 * Prefer real paragraphs (40 characters or more): a book usually opens
		 * with a cover, images, credits and loose titles. Without such a
		 * paragraph, use whatever text there is.
		 */
		$text = '';

		if ( preg_match_all( '#<p\b[^>]*>(.*?)</p>#is', $html, $paragraphs ) ) {
			foreach ( $paragraphs[1] as $paragraph ) {
				$clean = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $paragraph ), ENT_QUOTES, 'UTF-8' ) ) );

				if ( mb_strlen( $clean ) >= 40 ) {
					$text .= ( '' === $text ? '' : ' ' ) . $clean;
				}

				if ( mb_strlen( $text ) > self::DESCRIPTION_CHARS ) {
					break;
				}
			}
		}

		if ( '' === $text ) {
			$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
		}

		if ( '' === $text ) {
			return '';
		}

		if ( mb_strlen( $text ) <= self::DESCRIPTION_CHARS ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, self::DESCRIPTION_CHARS );
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
			'isAccessibleForFree' => true,
			'publisher'        => array(
				'@type' => 'Organization',
				'name'  => $data['site_name'],
			),
		);

		if ( $data['words'] > 0 ) {
			$ld['wordCount'] = $data['words'];
		}

		if ( $data['image'] ) {
			$ld['image'] = $data['image']['url'];
		}

		return $ld;
	}
}
