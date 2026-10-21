<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO and accessibility scorecard of a document, from 0 to 100.
 *
 * Twelve fixed checks in four groups, all computed on the server from the
 * document itself: no service, no cost, same result every time. It only looks
 * at what Jim controls (the document and the page that shows it), not at the
 * rest of the site.
 *
 * evaluate() is pure (facts in, result out); facts() gathers the facts.
 */
class JIMCA_Scorecard {

	const GROUP_METADATA  = 'metadata';
	const GROUP_STRUCTURE = 'structure';
	const GROUP_CONTENT   = 'content';
	const GROUP_PUBLISH   = 'publish';

	/** Fewer words than this and a page is "thin content" for search engines. */
	const MIN_WORDS = 300;

	/** Shortest description that still says something. */
	const MIN_DESCRIPTION = 70;

	/**
	 * @param array $facts See facts().
	 * @return array{score: int, checks: array<int, array{id: string, group: string, label: string, hint: string, max: int, points: int, passed: bool}>}
	 */
	public static function evaluate( array $facts ) {
		$levels = $facts['heading_levels'];
		$jumps  = 0;

		for ( $i = 1, $n = count( $levels ); $i < $n; $i++ ) {
			if ( $levels[ $i ] > $levels[ $i - 1 ] + 1 ) {
				++$jumps;
			}
		}

		$title_length = mb_strlen( $facts['title'] );

		$checks = array(
			self::check( 'title', self::GROUP_METADATA, __( 'Document has a clear title', 'jim-conversor-acessivel' ), __( 'Use a title between 10 and 70 characters.', 'jim-conversor-acessivel' ), 5, $title_length >= 10 && $title_length <= 70 ? 5 : 0 ),
			self::check( 'description', self::GROUP_METADATA, __( 'A summary for search results is available', 'jim-conversor-acessivel' ), __( 'The document needs at least a few full sentences, or a Subject in the file properties.', 'jim-conversor-acessivel' ), 10, mb_strlen( $facts['description'] ) >= self::MIN_DESCRIPTION ? 10 : 0 ),
			self::check( 'author', self::GROUP_METADATA, __( 'Author is known', 'jim-conversor-acessivel' ), __( 'Fill in the Author in the file properties before converting. Search engines use it to judge expertise.', 'jim-conversor-acessivel' ), 10, '' !== $facts['author'] ? 10 : 0 ),

			self::check( 'headings', self::GROUP_STRUCTURE, __( 'Has headings for navigation', 'jim-conversor-acessivel' ), __( 'Two or more headings give the document a table of contents.', 'jim-conversor-acessivel' ), 10, count( $levels ) >= 2 ? 10 : 0 ),
			self::check( 'hierarchy', self::GROUP_STRUCTURE, __( 'Heading levels do not skip', 'jim-conversor-acessivel' ), __( 'Go from a heading to the next level down (for example H2 then H3, not H2 then H4). Use "Conversion review" to fix it.', 'jim-conversor-acessivel' ), 15, 0 === $jumps ? 15 : ( $jumps <= 2 ? 8 : 0 ) ),

			self::check( 'length', self::GROUP_CONTENT, __( 'Enough text', 'jim-conversor-acessivel' ), __( 'Pages with fewer than 300 words are seen as thin content.', 'jim-conversor-acessivel' ), 10, $facts['words'] >= self::MIN_WORDS ? 10 : 0 ),
			self::check( 'readable', self::GROUP_CONTENT, __( 'Text is readable', 'jim-conversor-acessivel' ), __( 'The text came out as symbols (a PDF font problem). Convert another version of the file.', 'jim-conversor-acessivel' ), 5, $facts['unreadable'] ? 0 : 5 ),
			self::check( 'alt', self::GROUP_CONTENT, __( 'Images have a description', 'jim-conversor-acessivel' ), __( 'Describe the images that have no alternative text in the document editor.', 'jim-conversor-acessivel' ), 10, 0 === $facts['images_missing_alt'] ? 10 : 0 ),

			self::check( 'published', self::GROUP_PUBLISH, __( 'Shown on a published page', 'jim-conversor-acessivel' ), __( 'The document has no URL of its own. Put its shortcode on a published page, or use "Also publish in".', 'jim-conversor-acessivel' ), 15, $facts['published'] ? 15 : 0 ),
			self::check( 'indexable', self::GROUP_PUBLISH, __( 'Page can be indexed', 'jim-conversor-acessivel' ), __( 'The site or the page asks search engines not to index it (noindex).', 'jim-conversor-acessivel' ), 5, $facts['indexable'] ? 5 : 0 ),
			self::check( 'seo_tags', self::GROUP_PUBLISH, __( 'Search and sharing tags are on', 'jim-conversor-acessivel' ), __( 'Turn on "SEO tags" in Settings, or use an SEO plugin.', 'jim-conversor-acessivel' ), 5, $facts['seo_active'] ? 5 : 0 ),
		);

		$score = 0;

		foreach ( $checks as $check ) {
			$score += $check['points'];
		}

		// A page with almost no text cannot rank whatever else it has: cap the grade.
		if ( $facts['words'] < self::MIN_WORDS ) {
			$score = min( $score, 70 );
		}

		return array(
			'score'  => $score,
			'checks' => $checks,
		);
	}

	private static function check( $id, $group, $label, $hint, $max, $points ) {
		return array(
			'id'     => $id,
			'group'  => $group,
			'label'  => $label,
			'hint'   => $hint,
			'max'    => $max,
			'points' => $points,
			'passed' => $points >= $max,
		);
	}

	/**
	 * @param int $post_id Document ID.
	 * @return array Facts for evaluate().
	 */
	public static function facts( $post_id ) {
		$post    = get_post( $post_id );
		$content = substr( (string) $post->post_content, 0, JIMCA_SEO::SCAN_CHARS );
		$meta    = JIMCA_Metadata::get( $post_id );
		$words   = (int) get_post_meta( $post_id, '_jimca_word_count', true );
		$host    = self::host_page( $post_id );

		$description = mb_strlen( $meta['subject'] ) >= 40 ? $meta['subject'] : JIMCA_SEO::description( $content );
		$settings    = JIMCA_Admin::get_settings();

		preg_match_all( '#<h([1-6])\b(?![^>]*jimca-page-title)[^>]*>\s*[^\s<]#i', $content, $headings );
		$structure = JIMCA_SEO::structure( $content );

		return array(
			'title'              => wp_strip_all_tags( get_the_title( $post ) ),
			'description'        => $description,
			'author'             => $meta['author'],
			'heading_levels'     => array_map( 'intval', $headings[1] ),
			'words'              => $words ? $words : (int) str_word_count( wp_strip_all_tags( $content ) ),
			'unreadable'         => (bool) get_post_meta( $post_id, '_jimca_unreadable', true ),
			'images_missing_alt' => $structure['images_missing_alt'],
			'published'          => $host > 0,
			'indexable'          => self::is_indexable( $host ),
			'seo_active'         => ! empty( $settings['seo_enabled'] ) || JIMCA_SEO::other_seo_plugin_active(),
		);
	}

	/**
	 * First published post or page whose content has the document's shortcode.
	 *
	 * @param int $post_id Document ID.
	 * @return int 0 when the document is shown nowhere (an orphan).
	 */
	public static function host_page( $post_id ) {
		$post_id = (int) $post_id;

		$state = JIMCA_Destination::state( $post_id );
		if ( 'post' === $state['kind'] && 'publish' === get_post_status( $state['post_id'] ) ) {
			return (int) $state['post_id'];
		}

		foreach ( JIMCA_Usage::hosts( $post_id ) as $host ) {
			if ( 'publish' === $host->post_status ) {
				return (int) $host->ID;
			}
		}

		return 0;
	}

	/**
	 * @param string $content Post content.
	 * @return int[] Documents whose shortcode is in the content.
	 */
	public static function shortcode_ids( $content ) {
		$ids = array();

		if ( preg_match_all( '/' . get_shortcode_regex( array( JIMCA_Shortcode::TAG ) ) . '/', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$atts = shortcode_parse_atts( $match[3] );

				if ( ! empty( $atts['id'] ) ) {
					$ids[] = absint( $atts['id'] );
				}
			}
		}

		return $ids;
	}

	/**
	 * Can search engines index the page? The whole site hidden from them, or
	 * a noindex set in Yoast or Rank Math, makes the document invisible.
	 *
	 * @param int $host_id Page showing the document; 0 = none.
	 * @return bool
	 */
	public static function is_indexable( $host_id ) {
		if ( ! get_option( 'blog_public' ) ) {
			return false;
		}

		if ( $host_id <= 0 ) {
			return true;
		}

		if ( '1' === (string) get_post_meta( $host_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return false;
		}

		$robots = get_post_meta( $host_id, 'rank_math_robots', true );

		return ! ( is_array( $robots ) && in_array( 'noindex', $robots, true ) );
	}

	/**
	 * @return array<string, string> Group id => label.
	 */
	public static function group_labels() {
		return array(
			self::GROUP_METADATA  => __( 'Metadata', 'jim-conversor-acessivel' ),
			self::GROUP_STRUCTURE => __( 'Structure', 'jim-conversor-acessivel' ),
			self::GROUP_CONTENT   => __( 'Content', 'jim-conversor-acessivel' ),
			self::GROUP_PUBLISH   => __( 'Publication', 'jim-conversor-acessivel' ),
		);
	}

	/**
	 * @param int $score 0 to 100.
	 * @return string Grade in words, so the number is never the only signal.
	 */
	public static function grade_label( $score ) {
		if ( $score >= 85 ) {
			return __( 'Good', 'jim-conversor-acessivel' );
		}

		return $score >= 60 ? __( 'Needs attention', 'jim-conversor-acessivel' ) : __( 'Poor', 'jim-conversor-acessivel' );
	}
}
