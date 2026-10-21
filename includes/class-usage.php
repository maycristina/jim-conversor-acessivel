<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where each document is shown.
 *
 * A document has no address of its own: it appears in the pages, posts and
 * custom post types that hold its [documento_acessivel id="N"] shortcode. To
 * list those pages without searching the whole database every time the
 * documents table opens, the references are kept in two post meta fields and
 * updated when a page is saved:
 *
 *  - `_jimca_usado_em` on the document: IDs of the pages that show it;
 *  - `_jimca_documentos_usados` on the page: IDs of the documents it shows
 *    (to know what to remove when the shortcode is taken out).
 *
 * A document never scanned has no `_jimca_usado_em`: the first time it is
 * asked about, one search finds its pages (including page builders that keep
 * their content in post meta, such as Elementor) and fills the field.
 * Rendering the shortcode also registers the page it is shown on.
 */
class JIMCA_Usage {

	const META_USED_IN = '_jimca_usado_em';
	const META_USES    = '_jimca_documentos_usados';

	/** Statuses of pages that count as "using" a document. */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/** Most pages listed for one document. */
	const MAX_HOSTS = 100;

	public static function register_hooks() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_delete' ) );
		// Elementor saves its content in post meta after save_post has run.
		add_action( 'elementor/document/after_save', array( __CLASS__, 'on_builder_save' ) );
	}

	/**
	 * Documents whose shortcode is in a text.
	 *
	 * @param string $content Post content, or a page builder's JSON.
	 * @return int[]
	 */
	public static function ids_in( $content ) {
		// Page builders store the shortcode inside JSON, with escaped quotes.
		$content = str_replace( '\\"', '"', (string) $content );

		if ( false === stripos( $content, '[' . JIMCA_Shortcode::TAG ) ) {
			return array();
		}

		return array_values( array_unique( JIMCA_Scorecard::shortcode_ids( $content ) ) );
	}

	/**
	 * Documents shown by a page: its content and the post meta of page builders.
	 *
	 * @param WP_Post $post
	 * @return int[]
	 */
	public static function documents_of( WP_Post $post ) {
		$ids = self::ids_in( $post->post_content );

		$builder = get_post_meta( $post->ID, '_elementor_data', true );
		if ( is_string( $builder ) && '' !== $builder ) {
			$ids = array_merge( $ids, self::ids_in( $builder ) );
		}

		/**
		 * Other places a page can keep a shortcode (another page builder's field).
		 *
		 * @param int[]   $ids  Documents found.
		 * @param WP_Post $post The page.
		 */
		$ids = (array) apply_filters( 'jimca_usage_documents_of', $ids, $post );

		$found = array();
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( $id && JIMCA_Post_Type::POST_TYPE === get_post_type( $id ) ) {
				$found[] = $id;
			}
		}

		return $found;
	}

	public static function on_save( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || JIMCA_Post_Type::POST_TYPE === $post->post_type ) {
			return;
		}

		self::sync( $post );
	}

	/**
	 * @param int|WP_Post $document Elementor's document object, or a post.
	 */
	public static function on_builder_save( $document ) {
		$post = is_object( $document ) && method_exists( $document, 'get_post' ) ? $document->get_post() : null;

		if ( $post instanceof WP_Post ) {
			self::sync( $post );
		}
	}

	/** A page that goes to the trash or is deleted no longer shows anything. */
	public static function on_delete( $post_id ) {
		$post = get_post( $post_id );

		if ( $post && JIMCA_Post_Type::POST_TYPE !== $post->post_type ) {
			self::sync( $post, true );
		}
	}

	/**
	 * Brings the references of a page up to date.
	 *
	 * @param WP_Post $post  The page.
	 * @param bool    $empty Treat the page as showing nothing (deleted, trashed).
	 */
	public static function sync( WP_Post $post, $empty = false ) {
		$now = ( $empty || ! in_array( $post->post_status, self::STATUSES, true ) ) ? array() : self::documents_of( $post );
		$was = array_map( 'absint', (array) get_post_meta( $post->ID, self::META_USES, true ) );

		foreach ( array_diff( $was, $now ) as $document ) {
			self::remove( $document, $post->ID );
		}

		foreach ( $now as $document ) {
			self::add( $document, $post->ID );
		}

		if ( $now ) {
			update_post_meta( $post->ID, self::META_USES, $now );
		} elseif ( $was ) {
			delete_post_meta( $post->ID, self::META_USES );
		}
	}

	private static function stored( $document ) {
		$hosts = get_post_meta( $document, self::META_USED_IN, true );

		return is_array( $hosts ) ? array_values( array_unique( array_map( 'absint', $hosts ) ) ) : array();
	}

	public static function add( $document, $host ) {
		$scanned = metadata_exists( 'post', $document, self::META_USED_IN );
		// A document never scanned gets its list from the database first, so the page added now is not mistaken for the only one.
		$hosts = $scanned ? self::stored( $document ) : self::scan( $document );

		if ( ! $scanned || ! in_array( (int) $host, $hosts, true ) ) {
			$hosts[] = (int) $host;
			update_post_meta( $document, self::META_USED_IN, array_values( array_unique( $hosts ) ) );
		}
	}

	public static function remove( $document, $host ) {
		if ( ! metadata_exists( 'post', $document, self::META_USED_IN ) ) {
			return;
		}

		update_post_meta( $document, self::META_USED_IN, array_values( array_diff( self::stored( $document ), array( (int) $host ) ) ) );
	}

	/**
	 * Called when the shortcode is shown: the page it is shown on is a place where the document is used.
	 *
	 * @param int $document Document ID.
	 */
	public static function register( $document ) {
		$host = is_singular() ? (int) get_queried_object_id() : 0;

		if ( $host && $host !== (int) $document && ! in_array( $host, self::stored( $document ), true ) ) {
			self::add( $document, $host );
		}
	}

	/**
	 * Pages that show a document, for the documents table and the scorecard.
	 *
	 * @param int $document Document ID.
	 * @return WP_Post[] Existing pages in a usable status, published first.
	 */
	public static function hosts( $document ) {
		$document = (int) $document;

		if ( ! metadata_exists( 'post', $document, self::META_USED_IN ) ) {
			// Never scanned (a document made before this existed): one search, then it is remembered.
			update_post_meta( $document, self::META_USED_IN, self::scan( $document ) );
		}

		$hosts = array();

		foreach ( self::stored( $document ) as $id ) {
			$post = get_post( $id );

			if ( $post && in_array( $post->post_status, self::STATUSES, true ) && JIMCA_Post_Type::POST_TYPE !== $post->post_type ) {
				$hosts[] = $post;
			}
		}

		usort(
			$hosts,
			static function ( $a, $b ) {
				return ( 'publish' === $b->post_status ) <=> ( 'publish' === $a->post_status );
			}
		);

		return $hosts;
	}

	/**
	 * Searches the database for the pages that hold a document's shortcode:
	 * a cheap filter in SQL (LIKE), the exact check in PHP, so id="17" is
	 * never taken for id="179".
	 *
	 * @param int $document Document ID.
	 * @return int[]
	 */
	public static function scan( $document ) {
		global $wpdb;

		$document = (int) $document;
		$tag      = '%' . $wpdb->esc_like( '[' . JIMCA_Shortcode::TAG ) . '%';
		$number   = '%' . $wpdb->esc_like( (string) $document ) . '%';
		$status   = "'" . implode( "','", self::STATUSES ) . "'";
		$found    = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one search per document, the first time it is asked about; the statuses are fixed words.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status IN ({$status}) AND post_type NOT IN ('revision', 'jimca_documento') AND post_content LIKE %s AND post_content LIKE %s LIMIT %d",
				$tag,
				$number,
				self::MAX_HOSTS
			)
		);

		foreach ( (array) $rows as $row ) {
			if ( in_array( $document, self::ids_in( (string) $row->post_content ), true ) ) {
				$found[] = (int) $row->ID;
			}
		}

		// Page builders keep the shortcode in post meta.
		$metas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_status IN ({$status}) AND p.post_type NOT IN ('revision', 'jimca_documento') AND m.meta_key = '_elementor_data' AND m.meta_value LIKE %s AND m.meta_value LIKE %s LIMIT %d",
				$tag,
				$number,
				self::MAX_HOSTS
			)
		);
		// phpcs:enable

		foreach ( (array) $metas as $row ) {
			if ( in_array( $document, self::ids_in( (string) $row->meta_value ), true ) ) {
				$found[] = (int) $row->post_id;
			}
		}

		return array_values( array_unique( $found ) );
	}
}
