<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where an import goes besides the Jim documents panel.
 *
 * The converted document always stays in the panel (post type
 * `jimca_documento`): that is where it is organised, reviewed and has its
 * display mode. Optionally, the import also creates one post in a post type of
 * the site (post, page or a custom post type) with the shortcode already inside,
 * so nobody has to copy shortcodes one by one into other posts.
 *
 * Categories and tags are shared with WordPress: they are attached to the Jim
 * document and, when the chosen post type uses them, to the new post.
 *
 * Settings come from the upload form, are validated once when the upload
 * starts (see from_request()) and travel with the conversion job.
 */
class JIMCA_Destination {

	/** Meta of the Jim document: ID of the post that shows it. */
	const META_POST = '_jimca_published_post';

	/** Meta of the Jim document: why the post could not be created (escaped text). */
	const META_ERROR = '_jimca_destination_error';

	/** Meta of the created post: ID of the Jim document it shows. */
	const META_DOCUMENT = '_jimca_source_document';

	/** User meta: last choices, to pre-fill the form. */
	const PREFS_META = 'jimca_destination_prefs';

	/** Meta of the Jim document while its post waits for the scheduled time (when, event, post_type, action). */
	const META_SCHEDULED = '_jimca_scheduled';

	/** Action Scheduler hook that creates the post of a document at the scheduled time. */
	const ACTION_HOOK = 'jimca_publish_scheduled_document';

	/**
	 * Public post types that only hold layouts, templates or settings, never
	 * content a reader opens. The `jimca_destination_post_types` filter can add or remove types.
	 */
	const EXCLUDED_POST_TYPES = array( 'attachment', 'jimca_documento', 'elementor_library', 'e-landing-page', 'e-floating-buttons', 'fl-builder-template', 'et_pb_layout', 'ct_template', 'oceanwp_library', 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', 'wpcf7_contact_form' );

	/** Taxonomies shared by every Jim document and always offered. */
	const SHARED_TAXONOMIES = array( 'category', 'post_tag' );

	/** Statuses the form offers. */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/** Longest list of terms printed as checkboxes. */
	const MAX_TERMS = 200;

	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_shared_taxonomies' ), 20 );
		// Runs in the Action Scheduler queue, so it is registered on every request, not only in wp-admin.
		add_action( self::ACTION_HOOK, array( __CLASS__, 'run_scheduled' ) );
	}

	/**
	 * Categories and tags of the site also classify Jim documents, so the
	 * panel can be filtered by them (admin column and list filter come with it).
	 */
	public static function register_shared_taxonomies() {
		foreach ( self::SHARED_TAXONOMIES as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				register_taxonomy_for_object_type( $taxonomy, JIMCA_Post_Type::POST_TYPE );
			}
		}
	}

	/* ---------------------------------------------------------------
	 * Choices
	 * ------------------------------------------------------------- */

	/**
	 * Post types the current user may create content in: posts, pages and the
	 * custom post types that have a public page and the editor. Types that only
	 * exist to store settings or layouts (field groups, templates, orders,
	 * forms...) are not public or have no editor, and a shortcode in them would
	 * never reach a reader.
	 *
	 * @return array<string, WP_Post_Type> Slug => type; posts and pages first.
	 */
	public static function available_post_types() {
		$types = array();

		foreach ( get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' ) as $slug => $type ) {
			if ( in_array( $slug, self::EXCLUDED_POST_TYPES, true ) || ! post_type_supports( $slug, 'editor' ) ) {
				continue;
			}

			if ( ! current_user_can( $type->cap->create_posts ) ) {
				continue;
			}

			$types[ $slug ] = $type;
		}

		uasort( $types, array( __CLASS__, 'compare_post_types' ) );

		/**
		 * Post types offered as destination of an import.
		 *
		 * @param array<string, WP_Post_Type> $types
		 */
		return (array) apply_filters( 'jimca_destination_post_types', $types );
	}

	/** Posts, then pages, then the custom post types by name. */
	private static function compare_post_types( $a, $b ) {
		$rank   = array(
			'post' => 0,
			'page' => 1,
		);
		$rank_a = isset( $rank[ $a->name ] ) ? $rank[ $a->name ] : 2;
		$rank_b = isset( $rank[ $b->name ] ) ? $rank[ $b->name ] : 2;

		if ( $rank_a !== $rank_b ) {
			return $rank_a - $rank_b;
		}

		return strcasecmp( $a->labels->name, $b->labels->name );
	}

	/** @return array<string, string> Status => label. */
	public static function status_labels() {
		return array(
			'publish' => __( 'Publish now', 'jim-conversor-acessivel' ),
			'draft'   => __( 'Draft', 'jim-conversor-acessivel' ),
			'pending' => __( 'Pending review', 'jim-conversor-acessivel' ),
			'private' => __( 'Private', 'jim-conversor-acessivel' ),
			'future'  => __( 'Schedule', 'jim-conversor-acessivel' ),
		);
	}

	/**
	 * Taxonomies of a post type beyond categories and tags.
	 *
	 * @param string $post_type
	 * @return array<string, WP_Taxonomy>
	 */
	private static function extra_taxonomies( $post_type ) {
		$taxonomies = array();

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $slug => $taxonomy ) {
			if ( in_array( $slug, self::SHARED_TAXONOMIES, true ) || ! $taxonomy->public || ! $taxonomy->show_ui || 'post_format' === $slug ) {
				continue;
			}
			if ( current_user_can( $taxonomy->cap->assign_terms ) ) {
				$taxonomies[ $slug ] = $taxonomy;
			}
		}

		return $taxonomies;
	}

	/* ---------------------------------------------------------------
	 * Reading the form
	 * ------------------------------------------------------------- */

	/**
	 * Reads and validates the destination fields of the upload form. The caller
	 * has already checked the nonce and the upload capability.
	 *
	 * @return array Empty when nothing was asked for. Otherwise: post_type
	 *               (string, '' = only the panel), status, start (timestamp, 0 =
	 *               none), interval (hours between files), terms (taxonomy =>
	 *               IDs or names).
	 * @throws JIMCA_Converter_Exception When the schedule is invalid.
	 */
	public static function from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the callers check the upload nonce.
		$post_type = isset( $_POST['jimca_dest_type'] ) ? sanitize_key( wp_unslash( $_POST['jimca_dest_type'] ) ) : '';
		$types     = self::available_post_types();

		if ( ! isset( $types[ $post_type ] ) ) {
			$post_type = '';
		}

		$mode = isset( $_POST['jimca_display_mode'] ) ? sanitize_key( wp_unslash( $_POST['jimca_display_mode'] ) ) : 'reader';
		$mode = isset( JIMCA_Admin::get_display_modes()[ $mode ] ) ? $mode : 'reader';

		$destination = array(
			'post_type'    => $post_type,
			'status'       => 'publish',
			'start'        => 0,
			'interval'     => 0,
			'terms'        => array(),
			'display_mode' => $mode,
		);

		if ( '' !== $post_type ) {
			$type   = $types[ $post_type ];
			$status = isset( $_POST['jimca_dest_status'] ) ? sanitize_key( wp_unslash( $_POST['jimca_dest_status'] ) ) : 'publish';
			$status = in_array( $status, self::STATUSES, true ) ? $status : 'publish';

			// Whoever cannot publish in this type sends the content for review instead.
			if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
				$status = 'pending';
			}

			$destination['status'] = $status;

			if ( 'future' === $status ) {
				$raw   = isset( $_POST['jimca_dest_date'] ) ? sanitize_text_field( wp_unslash( $_POST['jimca_dest_date'] ) ) : '';
				$start = self::parse_local_datetime( $raw );

					if ( $start <= time() ) {
					throw new JIMCA_Converter_Exception(
						sprintf(
							/* translators: 1: current date and time of the site, 2: timezone of the site */
							esc_html__( 'To schedule, choose a date and time in the future, by the site clock: it is now %1$s (%2$s).', 'jim-conversor-acessivel' ),
							esc_html( wp_date( 'Y-m-d H:i' ) ),
							esc_html( wp_timezone_string() )
						)
					);
				}

				$destination['start']    = $start;
				$destination['event']    = self::event_name();
				$destination['interval'] = isset( $_POST['jimca_dest_interval'] ) ? min( 24 * 365, absint( $_POST['jimca_dest_interval'] ) ) : 0;
			}
		}

		$destination['terms'] = self::read_terms( $post_type );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Nothing beyond what an import always did: the reader mode, no post, no terms.
		if ( '' === $post_type && ! $destination['terms'] && 'reader' === $mode ) {
			self::remember( $destination );
			return array();
		}

		self::remember( $destination );

		return $destination;
	}

	/**
	 * @param string $raw Value of a datetime-local field, in the site's timezone.
	 * @return int Timestamp; 0 if it cannot be read.
	 */
	private static function parse_local_datetime( $raw ) {
		if ( '' === $raw ) {
			return 0;
		}

		try {
			return (int) ( new DateTimeImmutable( $raw, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/**
	 * Terms chosen in the form, for the shared taxonomies and for those of the
	 * chosen post type. Ids for hierarchical taxonomies (checkboxes), names for
	 * the others (text, separated by commas).
	 *
	 * @param string $post_type
	 * @return array<string, array>
	 */
	private static function read_terms( $post_type ) {
		$wanted = array();

		foreach ( self::SHARED_TAXONOMIES as $slug ) {
			if ( taxonomy_exists( $slug ) ) {
				$wanted[ $slug ] = get_taxonomy( $slug );
			}
		}

		if ( '' !== $post_type ) {
			$wanted += self::extra_taxonomies( $post_type );
		}

		$terms = array();

		foreach ( $wanted as $slug => $taxonomy ) {
			if ( ! current_user_can( $taxonomy->cap->assign_terms ) ) {
				continue;
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by the callers; each value is sanitized below.
			$raw = isset( $_POST['jimca_dest_terms'][ $slug ] ) ? wp_unslash( $_POST['jimca_dest_terms'][ $slug ] ) : array();
			// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( $taxonomy->hierarchical ) {
				$ids = array();

				foreach ( (array) $raw as $id ) {
					$id = absint( $id );
					if ( $id && term_exists( $id, $slug ) ) {
						$ids[] = $id;
					}
				}

				if ( $ids ) {
					$terms[ $slug ] = array_values( array_unique( $ids ) );
				}
				continue;
			}

			$names = array();

			foreach ( explode( ',', is_array( $raw ) ? '' : (string) $raw ) as $name ) {
				$name = trim( sanitize_text_field( $name ) );

				if ( '' === $name ) {
					continue;
				}

				// A new term is created only by whoever may create terms of this taxonomy.
				if ( ! term_exists( $name, $slug ) && ! current_user_can( $taxonomy->cap->edit_terms ) ) {
					continue;
				}

				$names[] = $name;
			}

			if ( $names ) {
				$terms[ $slug ] = array_values( array_unique( $names ) );
			}
		}

		return $terms;
	}

	/**
	 * Keeps the last choices of this user (not the terms or dates) for the
	 * next import.
	 *
	 * @param array $destination
	 */
	private static function remember( array $destination ) {
		update_user_meta(
			get_current_user_id(),
			self::PREFS_META,
			array(
				'post_type'    => $destination['post_type'],
				'status'       => $destination['status'],
				'interval'     => $destination['interval'],
				'display_mode' => $destination['display_mode'],
			)
		);
	}

	/* ---------------------------------------------------------------
	 * Applying it to a converted document
	 * ------------------------------------------------------------- */

	/**
	 * Classifies the Jim document and creates its post. A failure here never
	 * undoes the conversion: the document is already in the panel; the reason
	 * is stored and shown in the notice.
	 *
	 * @param int    $document_id Jim document.
	 * @param array  $destination from_request().
	 * @param int    $index       Position of this file among those of the upload (spaces the schedule).
	 * @param string $file_name   Original file name; its name without extension becomes the slug.
	 * @param string $title
	 */
	public static function apply( $document_id, array $destination, $index, $file_name, $title ) {
		if ( ! $destination ) {
			return;
		}

		// Reader is the default and is stored as no meta (see JIMCA_Post_Type::get_mode()).
		if ( isset( $destination['display_mode'] ) && 'post' === $destination['display_mode'] ) {
			update_post_meta( $document_id, JIMCA_Post_Type::MODE_META, 'post' );
		}

		$terms = isset( $destination['terms'] ) ? (array) $destination['terms'] : array();

		self::set_terms( $document_id, $terms, get_object_taxonomies( JIMCA_Post_Type::POST_TYPE ) );

		if ( empty( $destination['post_type'] ) ) {
			return;
		}

		$when = self::scheduled_time( $destination, (int) $index );

		// A scheduled publication becomes an Action Scheduler event: it can be seen, run now or cancelled there.
		if ( $when && self::scheduler_available() && self::schedule_post( $document_id, $destination, $when, $terms, $file_name, $title ) ) {
			return;
		}

		self::publish_now( $document_id, $destination, $terms, $file_name, $title, $when );
	}

	/**
	 * Creates the post right away. A "future" status without the scheduler
	 * (library missing) is left to WordPress's own publishing of future posts.
	 *
	 * @param int    $document_id
	 * @param array  $destination
	 * @param array  $terms
	 * @param string $file_name
	 * @param string $title
	 * @param int    $when        Timestamp for the "future" status; 0 otherwise.
	 */
	private static function publish_now( $document_id, array $destination, array $terms, $file_name, $title, $when ) {
		$post_id = self::create_post( $document_id, $destination, self::slug_of( $file_name ), $title, $terms, get_current_user_id(), true, $when );

		if ( is_wp_error( $post_id ) ) {
			update_post_meta( $document_id, self::META_ERROR, esc_html( $post_id->get_error_message() ) );
			JIMCA_Log::add( 'warning', 'destination.failed', sprintf( 'Document #%d is in the panel, but the post was not created: %s', $document_id, wp_strip_all_tags( $post_id->get_error_message() ) ) );
			return;
		}

		update_post_meta( $document_id, self::META_POST, $post_id );
		JIMCA_Log::add( 'info', 'destination.done', sprintf( 'Document #%d published in %s #%d (%s).', $document_id, $destination['post_type'], $post_id, get_post_status( $post_id ) ) );
	}

	/**
	 * @param string $file_name Original file name.
	 * @return string The name without extension, as a slug.
	 */
	private static function slug_of( $file_name ) {
		return sanitize_title( pathinfo( $file_name, PATHINFO_FILENAME ) );
	}

	/**
	 * @param int      $document_id
	 * @param array    $destination post_type and status (the status of the post that will exist).
	 * @param string   $slug        Address of the post; '' lets WordPress make one from the title.
	 * @param string   $title
	 * @param array    $terms       Taxonomy => ids or names.
	 * @param int      $author      User that will own the post.
	 * @param bool     $check_user  Check the current user may create this type (not in the queue, where nobody is logged in).
	 * @param int      $future_at   Timestamp for WordPress's own scheduling; 0 for none.
	 * @return int|WP_Error ID of the new post.
	 */
	private static function create_post( $document_id, array $destination, $slug, $title, array $terms, $author, $check_user, $future_at = 0 ) {
		$post_type = $destination['post_type'];
		$type      = get_post_type_object( $post_type );

		if ( ! $type || ( $check_user ? ! isset( self::available_post_types()[ $post_type ] ) : ! $type->public ) ) {
			return new WP_Error( 'jimca_destination_type', __( 'You cannot create content in the chosen post type.', 'jim-conversor-acessivel' ) );
		}

		$shortcode = '[' . JIMCA_Shortcode::TAG . ' id="' . (int) $document_id . '"]';
		$content   = $shortcode;

		// A shortcode block in the block editor; plain shortcode in the classic one.
		if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post_type ) ) {
			$content = "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->";
		}

		$args = array(
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $destination['status'],
			'post_author'  => (int) $author,
		);

		if ( '' !== $slug ) {
			// WordPress adds -2, -3... if another item of this type already uses it.
			$args['post_name'] = $slug;
		}

		if ( 'future' === $destination['status'] && $future_at ) {
			$args['post_date']     = wp_date( 'Y-m-d H:i:s', $future_at );
			$args['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $future_at );
		}

		$post_id = wp_insert_post( wp_slash( $args ), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_DOCUMENT, (int) $document_id );
		self::set_terms( $post_id, $terms, get_object_taxonomies( $post_type ) );

		return (int) $post_id;
	}

	/* ---------------------------------------------------------------
	 * Scheduling (Action Scheduler)
	 * ------------------------------------------------------------- */

	/** @return bool Is the Action Scheduler library loaded and ready? */
	public static function scheduler_available() {
		return function_exists( 'as_schedule_single_action' ) && class_exists( 'ActionScheduler' ) && ActionScheduler::is_initialized();
	}

	/**
	 * Name that ties together the scheduled publications of one import: it is
	 * the group of the events and part of their arguments, so it can be found
	 * in Scheduled Actions by searching for it.
	 *
	 * @return string
	 */
	private static function event_name() {
		return sprintf( 'Jim import %s #%s', wp_date( 'Y-m-d H:i' ), strtolower( wp_generate_password( 4, false ) ) );
	}

	/**
	 * @param array $destination
	 * @param int   $index       Position of the file among those of the upload.
	 * @return int Timestamp the file's post is to be published; 0 if the import is not scheduled.
	 */
	private static function scheduled_time( array $destination, $index ) {
		if ( empty( $destination['status'] ) || 'future' !== $destination['status'] || empty( $destination['start'] ) ) {
			return 0;
		}

		return (int) $destination['start'] + $index * (int) ( isset( $destination['interval'] ) ? $destination['interval'] : 0 ) * HOUR_IN_SECONDS;
	}

	/**
	 * Schedules the creation of the document's post.
	 *
	 * @return bool False when the scheduler refused it.
	 */
	private static function schedule_post( $document_id, array $destination, $when, array $terms, $file_name, $title ) {
		$post_type = $destination['post_type'];
		$event     = ! empty( $destination['event'] ) ? (string) $destination['event'] : self::event_name();
		$args      = array(
			'event'     => $event,
			'document'  => (int) $document_id,
			'title'     => $title,
			'post_type' => $post_type,
			'slug'      => self::slug_of( $file_name ),
			'author'    => get_current_user_id(),
			'terms'     => array_intersect_key( $terms, array_flip( get_object_taxonomies( $post_type ) ) ),
		);

		$action_id = as_schedule_single_action( $when, self::ACTION_HOOK, array( $args ), $event );

		if ( ! $action_id ) {
			return false;
		}

		update_post_meta(
			$document_id,
			self::META_SCHEDULED,
			array(
				'when'      => (int) $when,
				'event'     => $event,
				'post_type' => $post_type,
				'action'    => (int) $action_id,
			)
		);

		JIMCA_Log::add( 'info', 'destination.scheduled', sprintf( 'Document #%d: post in %s scheduled for %s (event "%s").', $document_id, $post_type, wp_date( 'Y-m-d H:i', $when ), $event ) );

		return true;
	}

	/**
	 * Action Scheduler callback: creates and publishes the post of a document.
	 * An exception makes the action show as failed in Scheduled Actions.
	 *
	 * @param array $args Arguments stored by schedule_post().
	 * @throws \Exception When the post cannot be created.
	 */
	public static function run_scheduled( $args ) {
		$args        = is_array( $args ) ? $args : array();
		$document_id = isset( $args['document'] ) ? (int) $args['document'] : 0;

		// The document was deleted after it was scheduled: there is nothing to publish.
		if ( ! $document_id || JIMCA_Post_Type::POST_TYPE !== get_post_type( $document_id ) ) {
			return;
		}

		$existing = (int) get_post_meta( $document_id, self::META_POST, true );

		if ( $existing && get_post( $existing ) ) {
			delete_post_meta( $document_id, self::META_SCHEDULED );
			return;
		}

		$author = isset( $args['author'] ) ? (int) $args['author'] : 0;

		if ( ! $author || ! get_userdata( $author ) ) {
			$author = (int) get_post_field( 'post_author', $document_id );
		}

		$post_id = self::create_post(
			$document_id,
			array(
				'post_type' => isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : '',
				'status'    => 'publish',
			),
			isset( $args['slug'] ) ? sanitize_title( $args['slug'] ) : '',
			get_the_title( $document_id ),
			isset( $args['terms'] ) && is_array( $args['terms'] ) ? $args['terms'] : array(),
			$author,
			false
		);

		if ( is_wp_error( $post_id ) ) {
			update_post_meta( $document_id, self::META_ERROR, esc_html( $post_id->get_error_message() ) );
			JIMCA_Log::add( 'error', 'destination.failed', sprintf( 'Scheduled post of document #%d was not created: %s', $document_id, wp_strip_all_tags( $post_id->get_error_message() ) ) );
			throw new \Exception( wp_strip_all_tags( $post_id->get_error_message() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- shown only in the Scheduled Actions log.
		}

		delete_post_meta( $document_id, self::META_SCHEDULED );
		delete_post_meta( $document_id, self::META_ERROR );
		update_post_meta( $document_id, self::META_POST, $post_id );
		JIMCA_Log::add( 'info', 'destination.done', sprintf( 'Document #%d published in %s #%d (scheduled).', $document_id, get_post_type( $post_id ), $post_id ) );
	}

	/**
	 * Where Scheduled Actions is in wp-admin.
	 *
	 * @param string $search Event name to filter by.
	 * @return array{0: string, 1: string} Translated menu path and URL.
	 */
	private static function scheduler_location( $search ) {
		if ( class_exists( 'WooCommerce' ) ) {
			return array(
				__( 'WooCommerce > Status > Scheduled Actions', 'jim-conversor-acessivel' ),
				add_query_arg(
					array(
						'page' => 'wc-status',
						'tab'  => 'action-scheduler',
						's'    => $search,
					),
					admin_url( 'admin.php' )
				),
			);
		}

		return array(
			__( 'Tools > Scheduled Actions', 'jim-conversor-acessivel' ),
			add_query_arg(
				array(
					'page' => 'action-scheduler',
					's'    => $search,
				),
				admin_url( 'tools.php' )
			),
		);
	}

	/**
	 * The menu path of Scheduled Actions, as a link for whoever may open it
	 * (administrators), as plain text for everyone else.
	 *
	 * @param string $search Event name to filter by; '' for the whole list.
	 * @return string HTML.
	 */
	public static function scheduler_menu_html( $search = '' ) {
		list( $label, $url ) = self::scheduler_location( $search );

		return current_user_can( 'manage_options' ) ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
	}

	/**
	 * Notice for the events created by an import: their name and where to
	 * find or cancel them. Empty when nothing was scheduled.
	 *
	 * @param int[] $document_ids Documents of the import.
	 * @return string HTML.
	 */
	public static function event_notice_html( array $document_ids ) {
		$events = array();

		foreach ( $document_ids as $document_id ) {
			$scheduled = get_post_meta( $document_id, self::META_SCHEDULED, true );

			if ( is_array( $scheduled ) && ! empty( $scheduled['event'] ) ) {
				$events[ (string) $scheduled['event'] ] = true;
			}
		}

		$html = '';

		foreach ( array_keys( $events ) as $event ) {
			$where = self::scheduler_menu_html( $event );

			$html .= ( '' === $html ? '' : '<br>' ) . sprintf(
				/* translators: 1: name of the event, 2: menu path of Scheduled Actions (a link for administrators) */
				esc_html__( 'Posts loaded successfully: event "%1$s" was created. To learn more or cancel, go to %2$s and look for "%1$s".', 'jim-conversor-acessivel' ),
				esc_html( $event ),
				$where
			);
		}

		return $html;
	}

	/**
	 * Where the document's post stands.
	 *
	 * @param int $document_id
	 * @return array{kind: string, post_id?: int, when?: int, event?: string, post_type?: string, message?: string}
	 *         kind: 'post', 'scheduled', 'canceled', 'failed', 'removed', 'error' or 'none'.
	 */
	public static function state( $document_id ) {
		$post_id = (int) get_post_meta( $document_id, self::META_POST, true );

		if ( $post_id && get_post( $post_id ) ) {
			return array(
				'kind'    => 'post',
				'post_id' => $post_id,
			);
		}

		$scheduled = get_post_meta( $document_id, self::META_SCHEDULED, true );

		if ( is_array( $scheduled ) && ! empty( $scheduled['when'] ) ) {
			$status = self::action_status( isset( $scheduled['action'] ) ? (int) $scheduled['action'] : 0 );
			$kind   = 'scheduled';

			if ( 'canceled' === $status ) {
				$kind = 'canceled';
			} elseif ( 'failed' === $status ) {
				$kind = 'failed';
			} elseif ( 'complete' === $status ) {
				$kind = 'removed';
			}

			return array(
				'kind'      => $kind,
				'when'      => (int) $scheduled['when'],
				'event'     => isset( $scheduled['event'] ) ? (string) $scheduled['event'] : '',
				'post_type' => isset( $scheduled['post_type'] ) ? (string) $scheduled['post_type'] : '',
			);
		}

		$error = get_post_meta( $document_id, self::META_ERROR, true );

		if ( is_string( $error ) && '' !== $error ) {
			return array(
				'kind'    => 'error',
				'message' => $error,
			);
		}

		return array( 'kind' => 'none' );
	}

	/**
	 * @param int $action_id
	 * @return string Action Scheduler status; 'canceled' when the action no longer exists; '' if unknown.
	 */
	private static function action_status( $action_id ) {
		if ( ! $action_id || ! self::scheduler_available() ) {
			return '';
		}

		try {
			return (string) ActionScheduler::store()->get_status( $action_id );
		} catch ( \Exception $e ) {
			return 'canceled';
		}
	}

	/**
	 * Short text for the documents list and the details box: the post, or
	 * when it will be published, or why it is not.
	 *
	 * @param array $state state().
	 * @return string Text, not escaped.
	 */
	public static function state_label( array $state ) {
		$type  = ! empty( $state['post_type'] ) ? get_post_type_object( $state['post_type'] ) : null;
		$name  = $type ? $type->labels->name : '';
		$until = ! empty( $state['when'] ) ? wp_date( 'Y-m-d H:i', $state['when'] ) : '';

		switch ( $state['kind'] ) {
			case 'scheduled':
				/* translators: 1: post type name (e.g. Pages), 2: date and time */
				return sprintf( __( '%1$s: scheduled for %2$s', 'jim-conversor-acessivel' ), $name, $until );
			case 'canceled':
				/* translators: 1: post type name (e.g. Pages), 2: date and time */
				return sprintf( __( '%1$s: schedule for %2$s was cancelled', 'jim-conversor-acessivel' ), $name, $until );
			case 'failed':
				/* translators: 1: post type name (e.g. Pages), 2: date and time */
				return sprintf( __( '%1$s: scheduled publication for %2$s failed', 'jim-conversor-acessivel' ), $name, $until );
			case 'removed':
				/* translators: %s: post type name (e.g. Pages) */
				return sprintf( __( '%s: the post no longer exists', 'jim-conversor-acessivel' ), $name );
		}

		return '';
	}

	/**
	 * @param int      $object_id
	 * @param array    $terms     Taxonomy => ids or names.
	 * @param string[] $allowed   Taxonomies this object can have.
	 */
	private static function set_terms( $object_id, array $terms, array $allowed ) {
		foreach ( $terms as $taxonomy => $list ) {
			if ( in_array( $taxonomy, $allowed, true ) && $list ) {
				wp_set_object_terms( $object_id, $list, $taxonomy, false );
			}
		}
	}

	/* ---------------------------------------------------------------
	 * Showing it
	 * ------------------------------------------------------------- */

	/**
	 * Sentence for the notice after a conversion: where the post is, or why
	 * it was not created. Empty when the import had no destination.
	 *
	 * @param int $document_id
	 * @return string HTML.
	 */
	public static function notice_html( $document_id ) {
		$post_id = (int) get_post_meta( $document_id, self::META_POST, true );

		if ( $post_id && get_post( $post_id ) ) {
			$link = '<a href="' . esc_url( get_edit_post_link( $post_id, '' ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a>';

			return ' ' . sprintf(
				/* translators: 1: link to the new post, 2: its status (e.g. "Draft") */
				esc_html__( 'Post created: %1$s (%2$s).', 'jim-conversor-acessivel' ),
				$link,
				esc_html( self::status_label_of( $post_id ) )
			);
		}

		$state = self::state( $document_id );

		if ( in_array( $state['kind'], array( 'scheduled', 'canceled', 'failed', 'removed' ), true ) ) {
			return ' ' . esc_html( self::state_label( $state ) ) . '.';
		}

		if ( 'error' === $state['kind'] ) {
			return ' ' . sprintf(
				/* translators: %s: reason */
				esc_html__( 'The document is in the Jim panel, but the post was not created: %s', 'jim-conversor-acessivel' ),
				$state['message']
			);
		}

		return '';
	}

	/**
	 * @param int $post_id
	 * @return string Translated status, with the date when scheduled.
	 */
	public static function status_label_of( $post_id ) {
		$status = get_post_status( $post_id );
		$labels = self::status_labels();
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;

		if ( 'future' === $status ) {
			/* translators: %s: date and time the post will be published */
			$label = sprintf( __( 'Scheduled for %s', 'jim-conversor-acessivel' ), get_the_date( 'Y-m-d H:i', $post_id ) );
		}

		return $label;
	}

	/* ---------------------------------------------------------------
	 * Form
	 * ------------------------------------------------------------- */

	/**
	 * Rows of the upload form (inside its table). assets/js/admin.js shows the
	 * rows that apply to the chosen post type; without JavaScript everything
	 * stays visible and the server reads only what matches the chosen type.
	 */
	public static function render_form_rows() {
		$types = self::available_post_types();
		$prefs = get_user_meta( get_current_user_id(), self::PREFS_META, true );
		$prefs = wp_parse_args(
			is_array( $prefs ) ? $prefs : array(),
			array(
				'post_type'    => '',
				'status'       => 'publish',
				'interval'     => 0,
				'display_mode' => 'reader',
			)
		);

		if ( ! isset( $types[ $prefs['post_type'] ] ) ) {
			$prefs['post_type'] = '';
		}

		$modes = JIMCA_Admin::get_display_modes();

		if ( ! isset( $modes[ $prefs['display_mode'] ] ) ) {
			$prefs['display_mode'] = 'reader';
		}
		?>
		<tr>
			<th scope="row" id="jimca_display_mode_label"><?php esc_html_e( 'Display mode', 'jim-conversor-acessivel' ); ?></th>
			<td>
				<?php // Named by the row header, so a screen reader does not read it twice. ?>
				<fieldset aria-labelledby="jimca_display_mode_label" aria-describedby="jimca_display_mode_help">
					<?php foreach ( $modes as $key => $label ) : ?>
						<label>
							<input type="radio" name="jimca_display_mode" value="<?php echo esc_attr( $key ); ?>" <?php checked( $prefs['display_mode'], $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</label><br>
					<?php endforeach; ?>
				</fieldset>
				<p class="description" id="jimca_display_mode_help"><?php esc_html_e( 'How the document looks on the page where you publish it: Accessible reader (reading bar, voice and themes) or Standard blog (a plain blog post with the converted text). You can change it later in the documents list (Quick Edit).', 'jim-conversor-acessivel' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="jimca_dest_type"><?php esc_html_e( 'Also publish in', 'jim-conversor-acessivel' ); ?></label></th>
			<td>
				<select id="jimca_dest_type" name="jimca_dest_type" aria-describedby="jimca_dest_type_help">
					<option value=""><?php esc_html_e( 'Nowhere: only in the Jim documents panel', 'jim-conversor-acessivel' ); ?></option>
					<?php foreach ( $types as $slug => $type ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $prefs['post_type'], $slug ); ?>><?php echo esc_html( $type->labels->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description" id="jimca_dest_type_help">
					<?php esc_html_e( 'Creates one post for each file, with the shortcode already inside, so you do not have to copy and paste them. The document stays in the Jim panel either way. The address (slug) of each post is the file name: "annual-report.pdf" becomes "annual-report".', 'jim-conversor-acessivel' ); ?>
				</p>
			</td>
		</tr>
		<tr data-jimca-dest-only>
			<th scope="row"><label for="jimca_dest_status"><?php esc_html_e( 'Publication status', 'jim-conversor-acessivel' ); ?></label></th>
			<td>
				<select id="jimca_dest_status" name="jimca_dest_status">
					<?php foreach ( self::status_labels() as $status => $label ) : ?>
						<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $prefs['status'], $status ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr data-jimca-dest-schedule>
			<th scope="row"><label for="jimca_dest_date"><?php esc_html_e( 'Publish on', 'jim-conversor-acessivel' ); ?></label></th>
			<td>
				<input type="datetime-local" id="jimca_dest_date" name="jimca_dest_date" min="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i' ) ); ?>" aria-describedby="jimca_dest_date_help">
				<p class="description" id="jimca_dest_date_help">
					<?php
					printf(
						/* translators: 1: timezone of the site, e.g. "UTC-3", 2: current date and time of the site */
						esc_html__( 'Date and time by the site clock, not by your computer: timezone %1$s, now %2$s.', 'jim-conversor-acessivel' ),
						esc_html( wp_timezone_string() ),
						esc_html( wp_date( 'Y-m-d H:i' ) )
					);
					?>
				</p>
				<p>
					<label for="jimca_dest_interval"><?php esc_html_e( 'Hours between one file and the next', 'jim-conversor-acessivel' ); ?></label><br>
					<input type="number" id="jimca_dest_interval" name="jimca_dest_interval" min="0" max="8760" step="1" value="<?php echo esc_attr( (int) $prefs['interval'] ); ?>" class="small-text" aria-describedby="jimca_dest_interval_help">
				</p>
				<p class="description" id="jimca_dest_interval_help"><?php esc_html_e( 'When you send several files, each one is scheduled this many hours after the previous one. Use 0 to schedule all of them at the same time.', 'jim-conversor-acessivel' ); ?></p>
				<?php if ( self::scheduler_available() ) : ?>
					<p class="description"><?php esc_html_e( 'Each scheduled post is an event of Action Scheduler: it is created at the chosen time, and you can see or cancel it under Tools > Scheduled Actions.', 'jim-conversor-acessivel' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		foreach ( self::SHARED_TAXONOMIES as $slug ) {
			$taxonomy = get_taxonomy( $slug );

			if ( $taxonomy && current_user_can( $taxonomy->cap->assign_terms ) ) {
				self::render_taxonomy_row( $taxonomy, '' );
			}
		}

		foreach ( array_keys( $types ) as $slug ) {
			foreach ( self::extra_taxonomies( $slug ) as $taxonomy ) {
				self::render_taxonomy_row( $taxonomy, $slug );
			}
		}
	}

	/**
	 * One taxonomy: checkboxes (hierarchical) or text separated by commas.
	 *
	 * @param WP_Taxonomy $taxonomy
	 * @param string      $post_type '' = always shown; otherwise only for that post type.
	 */
	private static function render_taxonomy_row( $taxonomy, $post_type ) {
		$id   = 'jimca_dest_terms_' . ( '' === $post_type ? '' : $post_type . '_' ) . $taxonomy->name;
		$name = 'jimca_dest_terms[' . $taxonomy->name . ']';
		$attr = '' === $post_type ? '' : ' data-jimca-dest-type="' . esc_attr( $post_type ) . '"';
		?>
		<tr<?php echo $attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() above. ?>>
			<?php if ( $taxonomy->hierarchical ) : ?>
				<?php $terms = get_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => false, 'number' => self::MAX_TERMS, 'orderby' => 'name' ) ); ?>
				<th scope="row" id="<?php echo esc_attr( $id ); ?>_label"><?php echo esc_html( $taxonomy->labels->name ); ?></th>
				<td>
					<?php // Named by the row header, so a screen reader does not read it twice. ?>
					<fieldset aria-labelledby="<?php echo esc_attr( $id ); ?>_label">
						<?php if ( is_wp_error( $terms ) || ! $terms ) : ?>
							<p class="description"><?php esc_html_e( 'None created yet.', 'jim-conversor-acessivel' ); ?></p>
						<?php else : ?>
							<div class="jimca-dest-checklist">
								<?php foreach ( $terms as $term ) : ?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $term->term_id ); ?>">
										<?php echo esc_html( $term->name ); ?>
									</label><br>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</fieldset>
				</td>
			<?php else : ?>
				<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $taxonomy->labels->name ); ?></label></th>
				<td>
					<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="regular-text" aria-describedby="<?php echo esc_attr( $id ); ?>_help">
					<p class="description" id="<?php echo esc_attr( $id ); ?>_help"><?php esc_html_e( 'Separate with commas. Names that do not exist yet are created (if you may create them).', 'jim-conversor-acessivel' ); ?></p>
				</td>
			<?php endif; ?>
		</tr>
		<?php
	}
}
