<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parts of a long document ("pages", like an e-book reader) and the search
 * inside it.
 *
 * Why: a book of 900,000 words becomes some 40,000 paragraphs. Putting them
 * all in the page makes the browser build a DOM of that size, which freezes
 * it and makes screen readers crawl. A long document is therefore cut into
 * parts of a few dozen KB at block boundaries (preferably before a heading);
 * the page carries one part and the reader moves between parts, as in an
 * e-book. Reading aloud, the contents list and highlights follow the part;
 * the search runs on the whole document at the server.
 *
 * - Short documents are shown whole, unless the shortcode says pages="yes".
 * - Above MANDATORY_BYTES the parts are mandatory (pages="no" is ignored).
 * - The parts of a document are cached (transients) per version of the
 *   document, so reading a part does not cut the whole book again.
 * - Without JavaScript the reader still moves between parts: the navigation
 *   links reload the page with ?jimca_part_{id}=N.
 */
class JIMCA_Pagination {

	/** HTML size from which a document is always shown in parts. */
	const MANDATORY_BYTES = 350000;

	/** A part is cut before a heading once it reaches this size... */
	const SOFT_BYTES = 60000;

	/** ...and at any block boundary once it reaches this one. */
	const HARD_BYTES = 120000;

	/** Seconds the cut parts stay cached. */
	const CACHE_TTL = WEEK_IN_SECONDS;

	const AJAX_PART   = 'jimca_part';
	const AJAX_SEARCH = 'jimca_search';

	/** Results returned per search request. */
	const RESULTS_PER_PAGE = 20;

	/** Searches allowed per visitor per minute. */
	const SEARCHES_PER_MINUTE = 30;

	/** Elements that have no closing tag. */
	const VOID_TAGS = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	public static function register_hooks() {
		add_action( 'wp_ajax_' . self::AJAX_PART, array( __CLASS__, 'ajax_part' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_PART, array( __CLASS__, 'ajax_part' ) );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH, array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_SEARCH, array( __CLASS__, 'ajax_search' ) );
	}

	/* ---------------------------------------------------------------
	 * Pure functions (no WordPress state): cutting and matching
	 * ------------------------------------------------------------- */

	/**
	 * Should the document be shown in parts?
	 *
	 * @param int    $bytes Size of the document HTML.
	 * @param string $pages Shortcode attribute: '' (automatic), 'yes' or 'no'.
	 * @return bool
	 */
	public static function needed( $bytes, $pages ) {
		if ( $bytes >= self::MANDATORY_BYTES ) {
			return true;
		}

		return in_array( strtolower( trim( (string) $pages ) ), array( 'yes', 'sim', 'si', 'on', '1', 'true' ), true ) && $bytes >= 2 * self::SOFT_BYTES;
	}

	/**
	 * Cuts HTML into parts at the boundaries of top-level blocks.
	 *
	 * A part is closed before a heading (h1-h3) once it has SOFT_BYTES, or
	 * before any block once it has HARD_BYTES. Nothing is cut inside a block
	 * (a list, a table, a quote), so every part is balanced HTML.
	 *
	 * @param string $html Document HTML.
	 * @param int    $soft Size from which a heading starts a new part.
	 * @param int    $hard Size from which any block starts a new part.
	 * @return string[] The parts, in order; their concatenation is $html.
	 */
	public static function split( $html, $soft = self::SOFT_BYTES, $hard = self::HARD_BYTES ) {
		$html = (string) $html;
		$len  = strlen( $html );

		if ( $len <= $hard ) {
			return array( $html );
		}

		$parts = array();
		$start = 0;
		$depth = 0;
		$pos   = 0;

		while ( $pos < $len && preg_match( '#<(/?)([a-z][a-z0-9]*)\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$tag_start = $m[0][1];
			$tag_len   = strlen( $m[0][0] );
			$closing   = '/' === $m[1][0];
			$name      = strtolower( $m[2][0] );
			$void      = in_array( $name, self::VOID_TAGS, true ) || '/>' === substr( $m[0][0], -2 );
			$pos       = $tag_start + $tag_len;

			if ( $closing ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}

			if ( 0 === $depth && $tag_start > $start ) {
				$size    = $tag_start - $start;
				$heading = 1 === preg_match( '/^h[1-3]$/', $name );

				if ( $size >= $hard || ( $size >= $soft && $heading ) ) {
					$parts[] = substr( $html, $start, $tag_start - $start );
					$start   = $tag_start;
				}
			}

			if ( ! $void ) {
				++$depth;
			}
		}

		$parts[] = substr( $html, $start );

		return $parts;
	}

	/**
	 * Title of a part: its first heading, or '' when it has none.
	 *
	 * @param string $part HTML of the part.
	 * @return string
	 */
	public static function title_of( $part ) {
		if ( ! preg_match( '#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', $part, $match ) ) {
			return '';
		}

		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Text of the blocks of a part, in order: paragraphs, list items,
	 * headings and captions (what the search looks in).
	 *
	 * @param string $part HTML of the part.
	 * @return string[]
	 */
	public static function blocks_of( $part ) {
		if ( ! preg_match_all( '#<(p|li|h[1-6]|figcaption)\b[^>]*>(.*?)</\1>#is', $part, $matches ) ) {
			return array();
		}

		$blocks = array();

		foreach ( $matches[2] as $inner ) {
			$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, 'UTF-8' ) ) );

			if ( '' !== $text ) {
				$blocks[] = $text;
			}
		}

		return $blocks;
	}

	/**
	 * Lowercase, no accents: "Moisés" matches "moises" and "MOISÉS".
	 *
	 * @param string $text
	 * @return string
	 */
	public static function normalize( $text ) {
		return mb_strtolower( remove_accents( (string) $text ) );
	}

	/**
	 * Blocks of a part that contain the query.
	 *
	 * @param string[] $blocks Text of each block (blocks_of()).
	 * @param string   $needle Normalized query.
	 * @return array<int, array{block: string, before: string, match: string, after: string, dup: int}>
	 */
	public static function find_in_blocks( array $blocks, $needle ) {
		$found = array();
		$seen  = array();

		foreach ( $blocks as $block ) {
			$prefix = mb_substr( $block, 0, 60 );
			$dup    = isset( $seen[ $prefix ] ) ? $seen[ $prefix ] : 0;

			$seen[ $prefix ] = $dup + 1;

			$position = mb_strpos( self::normalize( $block ), $needle );

			if ( false === $position ) {
				continue;
			}

			$length = mb_strlen( $needle );
			$from   = max( 0, $position - 70 );

			$found[] = array(
				// Where the browser finds the block again: its first characters and how many earlier blocks start the same way.
				'block'  => $prefix,
				'dup'    => $dup,
				'before' => ( $from > 0 ? '…' : '' ) . mb_substr( $block, $from, $position - $from ),
				'match'  => mb_substr( $block, $position, $length ),
				'after'  => mb_substr( $block, $position + $length, 90 ) . ( mb_strlen( $block ) > $position + $length + 90 ? '…' : '' ),
			);
		}

		return $found;
	}

	/* ---------------------------------------------------------------
	 * Cache of the parts of a document
	 * ------------------------------------------------------------- */

	/**
	 * Key of a version of the document: changes when it is edited or the plugin is updated.
	 *
	 * @param WP_Post $post
	 * @return string
	 */
	private static function version_of( WP_Post $post ) {
		return substr( md5( $post->post_modified_gmt . '|' . JIMCA_VERSION . '|' . strlen( $post->post_content ) ), 0, 12 );
	}

	private static function key( WP_Post $post, $suffix ) {
		return 'jimca_pg_' . $post->ID . '_' . self::version_of( $post ) . '_' . $suffix;
	}

	/**
	 * What the page needs to show the document in parts: how many, their
	 * titles and the contents list with the part of each entry. Cuts and
	 * caches the document the first time.
	 *
	 * @param WP_Post $post
	 * @return array{count: int, titles: string[], toc: array}
	 */
	public static function info( WP_Post $post ) {
		$info = get_transient( self::key( $post, 'info' ) );

		if ( is_array( $info ) && isset( $info['count'], $info['titles'], $info['toc'] ) ) {
			return $info;
		}

		JIMCA_Limits::raise_for_conversion();

		// Stable heading ids (not tied to one viewer on the page), so a part fetched later links to the same ids.
		list( $html, $toc ) = JIMCA_Shortcode::build_toc( JIMCA_Shortcode::ensure_paragraphs( $post->post_content ), 'jimca-' . $post->ID );

		$parts  = self::split( $html );
		$titles = array();
		$owner  = array();

		foreach ( $parts as $index => $part ) {
			$number   = $index + 1;
			$titles[] = self::title_of( $part );

			if ( preg_match_all( '/<h[1-6]\b[^>]*\sid="([^"]+)"/i', $part, $ids ) ) {
				foreach ( $ids[1] as $id ) {
					$owner[ $id ] = $number;
				}
			}

			set_transient( self::key( $post, (string) $number ), $part, self::CACHE_TTL );
		}

		foreach ( $toc as $i => $item ) {
			$toc[ $i ]['part'] = isset( $owner[ $item['id'] ] ) ? $owner[ $item['id'] ] : 1;
		}

		$info = array(
			'count'  => count( $parts ),
			'titles' => $titles,
			'toc'    => $toc,
		);

		set_transient( self::key( $post, 'info' ), $info, self::CACHE_TTL );

		return $info;
	}

	/**
	 * HTML of one part (1-based).
	 *
	 * @param WP_Post $post
	 * @param int     $number
	 * @return string '' when there is no such part.
	 */
	public static function part( WP_Post $post, $number ) {
		$info   = self::info( $post );
		$number = (int) $number;

		if ( $number < 1 || $number > $info['count'] ) {
			return '';
		}

		$html = get_transient( self::key( $post, (string) $number ) );

		if ( false === $html ) {
			// Evicted from the cache: cut again.
			delete_transient( self::key( $post, 'info' ) );
			self::info( $post );
			$html = get_transient( self::key( $post, (string) $number ) );
		}

		return is_string( $html ) ? JIMCA_Shortcode::demote_headings( $html, (string) $post->post_content ) : '';
	}

	/**
	 * Is this document (HTML size, shortcode attribute) shown in parts?
	 *
	 * @param WP_Post $post
	 * @param string  $pages Shortcode attribute.
	 * @return bool
	 */
	public static function is_paginated( WP_Post $post, $pages ) {
		return self::needed( strlen( $post->post_content ), $pages );
	}

	/**
	 * Query argument that carries the part for the no-JavaScript links.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public static function query_arg( $post_id ) {
		return 'jimca_part_' . (int) $post_id;
	}

	/* ---------------------------------------------------------------
	 * AJAX: a part, and the search
	 * ------------------------------------------------------------- */

	/**
	 * The document if it can be read by anyone: published, ours, not protected.
	 *
	 * @return WP_Post
	 */
	private static function readable_document() {
		$post_id = isset( $_REQUEST['doc'] ) ? absint( $_REQUEST['doc'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only content of a published document.
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || JIMCA_Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			wp_send_json_error( null, 404 );
		}

		return $post;
	}

	public static function ajax_part() {
		$post   = self::readable_document();
		$number = isset( $_REQUEST['part'] ) ? absint( $_REQUEST['part'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see readable_document().
		$info   = self::info( $post );
		$html   = self::part( $post, $number );

		if ( '' === $html ) {
			wp_send_json_error( null, 404 );
		}

		wp_send_json_success(
			array(
				'html'  => wp_kses_post( $html ),
				'part'  => $number,
				'count' => $info['count'],
			)
		);
	}

	public static function ajax_search() {
		$post   = self::readable_document();
		$query  = isset( $_REQUEST['q'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see readable_document().
		$offset = isset( $_REQUEST['offset'] ) ? absint( $_REQUEST['offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see readable_document().
		$needle = self::normalize( trim( $query ) );

		if ( mb_strlen( $needle ) < 2 ) {
			wp_send_json_error( array( 'message' => 'short' ), 400 );
		}

		if ( ! self::spend_search() ) {
			wp_send_json_error( array( 'message' => 'rate' ), 429 );
		}

		wp_send_json_success( self::search( $post, $needle, $offset ) );
	}

	/**
	 * Counts one search of this visitor; false when they searched too much.
	 * The visitor is a hash of the address, kept for a minute.
	 *
	 * @return bool
	 */
	private static function spend_search() {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = 'jimca_sr_' . md5( wp_hash( $ip ) );
		$count = (int) get_transient( $key );

		if ( $count >= self::SEARCHES_PER_MINUTE ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Looks for a normalized query in the whole document.
	 *
	 * @param WP_Post $post
	 * @param string  $needle Normalized query.
	 * @param int     $offset Results to skip (for "show more").
	 * @return array{total: int, results: array}
	 */
	public static function search( WP_Post $post, $needle, $offset = 0 ) {
		$info    = self::info( $post );
		$total   = 0;
		$results = array();

		for ( $number = 1; $number <= $info['count']; $number++ ) {
			$hits = self::find_in_blocks( self::blocks_of( self::part( $post, $number ) ), $needle );

			foreach ( $hits as $hit ) {
				if ( $total >= $offset && count( $results ) < self::RESULTS_PER_PAGE ) {
					$hit['part']  = $number;
					$hit['title'] = $info['titles'][ $number - 1 ];
					$results[]    = $hit;
				}

				++$total;
			}
		}

		return array(
			'total'   => $total,
			'results' => $results,
		);
	}
}
