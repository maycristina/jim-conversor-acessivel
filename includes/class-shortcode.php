<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [documento_acessivel id="123" tutor="yes|no"]: the accessible
 * reader (or blog-post view) of a converted document.
 */
class JIMCA_Shortcode {

	const TAG = 'documento_acessivel';

	private static $instance = null;
	/** Script and style groups already queued on this page. */
	private $loaded = array();

	/** Footer, deferred: the page is parsed and shown before the scripts run. */
	const SCRIPT_ARGS = array(
		'in_footer' => true,
		'strategy'  => 'defer',
	);

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'tutor' => '',
				'pages' => '',
			),
			$atts,
			self::TAG
		);

		$post_id = absint( $atts['id'] );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || JIMCA_Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p>' . esc_html__( 'Jim - Accessible Converter: document not found.', 'jim-conversor-acessivel' ) . '</p>';
			}
			return '';
		}

		JIMCA_Usage::register( $post->ID );

		$settings = JIMCA_Admin::get_settings();
		$mode     = JIMCA_Post_Type::get_mode( $post->ID );
		/*
		 * Language of the document (saved, else detected from its text, else
		 * the site's): lang on the text for screen readers, data-doc-lang on
		 * the reader for the reading voice. Not lang on the whole reader: its
		 * buttons are in the site's language.
		 */
		$lang_attributes = JIMCA_Language::attributes( JIMCA_Language::of_document( $post->ID, $post->post_content ) );
		// The theme already shows the page title as the <h1>: the same title again as an <h2> would be a duplicate heading.
		$title_tag = self::same_title( get_the_title( $post ), is_singular() ? get_the_title( get_queried_object_id() ) : '' ) ? 'p' : 'h2';

		// Not apply_filters( 'the_content' ): that would run do_shortcode() over
		// text extracted from an uploaded file, so a document containing
		// "[some-shortcode]" would run it. The HTML was already built and passed
		// through wp_kses_post() at conversion time.
		$word_count = (int) get_post_meta( $post->ID, '_jimca_word_count', true );
		$pager      = null;

		// The post mode is a plain page with no search and no parts: the whole document is printed.
		if ( 'reader' === $mode && JIMCA_Pagination::is_paginated( $post, $atts['pages'] ) ) {
			// A long document goes in parts (see JIMCA_Pagination): the page carries one, the others come on demand.
			$info = JIMCA_Pagination::info( $post );
			$arg  = JIMCA_Pagination::query_arg( $post->ID );
			$part = isset( $_GET[ $arg ] ) ? max( 1, min( $info['count'], absint( $_GET[ $arg ] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public navigation between parts of a document.

			$pager   = array(
				'count'  => $info['count'],
				'part'   => $part,
				'titles' => $info['titles'],
				'arg'    => $arg,
			);
			$content = JIMCA_Pagination::part( $post, $part );
			$toc     = $info['toc'];
		} else {
			$content = self::demote_headings( self::ensure_paragraphs( $post->post_content ), $post->post_content );
		}
		$uid        = 'jimca-' . $post->ID . '-' . wp_unique_id();

		if ( ! $word_count ) {
			$word_count = str_word_count( wp_strip_all_tags( $content ) );
		}
		$reading_minutes = max( 1, (int) ceil( $word_count / 200 ) );

		if ( ! $pager ) {
			list( $content, $toc ) = self::build_toc( $content, $uid );
		}

		// A document whose text came out unreadable (PDF fonts without a letter map) would only make the assistant answer from symbols.
		$tutor = JIMCA_Tutor::is_shown( $atts['tutor'] ) && ! get_post_meta( $post->ID, '_jimca_unreadable', true ) ? JIMCA_Tutor::get_settings() : null;

		$this->enqueue_assets( $mode, false !== stripos( (string) $post->post_content, '<img' ), (bool) $tutor );

		if ( $tutor ) {
			$this->enqueue_tutor_assets();
		}

		ob_start();
		include JIMCA_PLUGIN_DIR . 'templates/document-viewer.php';
		return ob_get_clean();
	}

	/**
	 * The page already has its <h1> (the theme's title). When the document has
	 * <h1> headings of its own (a Word "Heading 1", a Markdown "# Title"),
	 * every heading goes one level down, so the page keeps a single <h1> and
	 * the order of the levels is kept. Done at display time: the stored
	 * document is not changed.
	 *
	 * @param string $html          Document HTML (all of it, or a part).
	 * @param string $full_document Whole stored document, to know whether it has an <h1>.
	 * @return string
	 */
	public static function demote_headings( $html, $full_document ) {
		if ( false === stripos( (string) $full_document, '<h1' ) ) {
			return $html;
		}

		return preg_replace_callback(
			'#<(/?)h([1-6])(?=[\s>])#i',
			static function ( $match ) {
				return '<' . $match[1] . 'h' . min( 6, (int) $match[2] + 1 );
			},
			(string) $html
		);
	}

	/**
	 * Do two titles read the same? Compared without tags, entities, case and extra spaces.
	 *
	 * @param string $a
	 * @param string $b
	 * @return bool
	 */
	public static function same_title( $a, $b ) {
		$normalize = static function ( $title ) {
			return trim( preg_replace( '/\s+/u', ' ', mb_strtolower( html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' ) ) ) );
		};

		$a = $normalize( $a );

		return '' !== $a && $a === $normalize( $b );
	}

	/**
	 * Wraps text that sits outside any <p> in paragraphs.
	 *
	 * The classic editor saves a document without its <p> tags (paragraphs
	 * become blank lines) and relies on wpautop() at display time, which the
	 * shortcode skips on purpose (see render()). Without it the text is bare
	 * in the page and the reading bar finds nothing to read.
	 *
	 * Only wpautop(), not the_content: it adds <p> and runs no shortcode.
	 * Text that already has <p> is left as it is.
	 *
	 * @param string $content
	 * @return string
	 */
	public static function ensure_paragraphs( $content ) {
		return wpautop( $content, false );
	}

	/**
	 * Builds the table of contents from the document headings (<h1>–<h6>)
	 * and gives each heading an id to link to.
	 *
	 * Headings come from Word heading styles or PDF bookmarks. "Page N"
	 * markers are left out: one entry per page would be noise.
	 *
	 * Done at display time, not at conversion, so it also covers headings
	 * added later by editing the document.
	 *
	 * @param string $content
	 * @param string $uid     Unique prefix of this viewer on the page.
	 * @return array{0: string, 1: array<int, array{id: string, text: string, level: int}>}
	 */
	public static function build_toc( $content, $uid ) {
		$toc   = array();
		$count = 0;

		$content = preg_replace_callback(
			'#<h([1-6])((?:\s[^>]*)?)>(.*?)</h\1>#is',
			function ( $match ) use ( &$toc, &$count, $uid ) {
				list( $full, $level, $attributes, $inner ) = $match;

				if ( preg_match( '/\bclass\s*=\s*(["\'])[^"\']*\bjimca-page-title\b/i', $attributes ) ) {
					return $full;
				}

				$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, 'UTF-8' ) ) );
				if ( '' === $text ) {
					return $full;
				}

				if ( preg_match( '/\bid\s*=\s*(["\'])([^"\']+)\1/i', $attributes, $id_match ) ) {
					$id = $id_match[2];
				} else {
					++$count;
					$id         = $uid . '-secao-' . $count;
					$attributes = ' id="' . esc_attr( $id ) . '"' . $attributes;
				}

				$toc[] = array(
					'id'    => $id,
					'text'  => $text,
					'level' => (int) $level,
				);

				return '<h' . $level . $attributes . '>' . $inner . '</h' . $level . '>';
			},
			$content
		);

		// A single heading is not a table of contents.
		if ( count( $toc ) < 2 ) {
			$toc = array();
		}

		return array( $content, $toc );
	}

	/**
	 * Navigation between the parts of a long document: previous, "Part X of
	 * N", next. The links work without JavaScript (the page reloads on the
	 * part); pager.js turns them into in-page changes. The first one also
	 * carries the list of parts, which the script shows.
	 *
	 * @param array  $pager       'count', 'part', 'titles', 'arg'.
	 * @param string $uid         Id prefix of the viewer.
	 * @param bool   $with_select Add the "go to part" list.
	 * @return string Escaped HTML.
	 */
	public static function render_pager( array $pager, $uid, $with_select ) {
		// A link, or (at the ends) the same element switched off: pager.js turns it back on when the part changes.
		$link = static function ( $number, $rel, $label, $enabled ) use ( $pager ) {
			$ends = 'prev' === $rel ? 'data-jimca-pager-prev' : 'data-jimca-pager-next';

			if ( ! $enabled ) {
				return '<a class="jimca-pager__link is-disabled" aria-disabled="true" rel="' . esc_attr( $rel ) . '" ' . $ends . '>' . esc_html( $label ) . '</a>';
			}

			return '<a class="jimca-pager__link" rel="' . esc_attr( $rel ) . '" href="' . esc_url( add_query_arg( $pager['arg'], $number, remove_query_arg( $pager['arg'] ) ) ) . '" data-jimca-part-link="' . (int) $number . '" ' . $ends . '>' . esc_html( $label ) . '</a>';
		};

		$html  = '<nav class="jimca-pager" aria-label="' . esc_attr__( 'Parts of the document', 'jim-conversor-acessivel' ) . '" data-jimca-pager>';
		$html .= $link( $pager['part'] - 1, 'prev', __( 'Previous part', 'jim-conversor-acessivel' ), $pager['part'] > 1 );

		$html .= '<span class="jimca-pager__status" role="status" aria-live="polite" data-jimca-pager-status>' . esc_html(
			sprintf(
				/* translators: 1: number of the part being read, 2: number of parts */
				__( 'Part %1$s of %2$s', 'jim-conversor-acessivel' ),
				number_format_i18n( $pager['part'] ),
				number_format_i18n( $pager['count'] )
			)
		) . '</span>';

		$html .= $link( $pager['part'] + 1, 'next', __( 'Next part', 'jim-conversor-acessivel' ), $pager['part'] < $pager['count'] );

		if ( $with_select ) {
			$html .= '<span class="jimca-pager__jump" data-jimca-pager-jump hidden><label for="' . esc_attr( $uid ) . '-jump">' . esc_html__( 'Go to part', 'jim-conversor-acessivel' ) . '</label> ' . self::render_part_select( $pager, $uid . '-jump', false ) . '</span>';
		}

		return $html . '</nav>';
	}

	/**
	 * The "go to part" list: one option per part, named after its first heading.
	 * Shared by the navigation of the page (no JavaScript) and the popover of the bar.
	 *
	 * @param array  $pager $pager from render_pager().
	 * @param string $id    Id of the <select>; for the bar ($label false) the label is added here.
	 * @param bool   $label Add the visible label before the list.
	 * @return string Escaped HTML.
	 */
	public static function render_part_select( array $pager, $id, $label = true ) {
		$select_id = $label ? $id . '-jump' : $id;
		$html      = '';

		if ( $label ) {
			$html .= '<label for="' . esc_attr( $select_id ) . '">' . esc_html__( 'Go to part', 'jim-conversor-acessivel' ) . '</label> ';
		}

		$html .= '<select id="' . esc_attr( $select_id ) . '" data-jimca-pager-select>';

		foreach ( $pager['titles'] as $index => $title ) {
			$number = $index + 1;
			$text   = number_format_i18n( $number ) . ( '' !== $title ? ' · ' . $title : '' );
			$html  .= '<option value="' . (int) $number . '"' . selected( $pager['part'], $number, false ) . '>' . esc_html( mb_strimwidth( $text, 0, 70, '…' ) ) . '</option>';
		}

		return $html . '</select>';
	}

	/**
	 * Nested list (<ol> inside <li>) from the heading levels. A jump of levels
	 * (<h2> straight to <h4>) goes down one level only, so no item is left
	 * without a parent.
	 *
	 * @param array $toc See build_toc().
	 * @return string Escaped HTML.
	 */
	public static function render_toc_list( array $toc, $part_arg = '' ) {
		if ( ! $toc ) {
			return '';
		}

		$base = min( wp_list_pluck( $toc, 'level' ) );
		$html = '';
		$prev = -1;

		foreach ( $toc as $item ) {
			$level = min( $item['level'] - $base, $prev + 1 );

			if ( $level > $prev ) {
				$html .= ( -1 === $prev ) ? '<ol class="jimca-toc__list">' : '<ol>';
			} elseif ( $level === $prev ) {
				$html .= '</li>';
			} else {
				$html .= '</li>' . str_repeat( '</ol></li>', $prev - $level );
			}

			// In a document in parts the entry names its part: the link works without JavaScript, and the script opens that part.
			$href  = isset( $item['part'] ) && '' !== $part_arg
				? esc_url( add_query_arg( $part_arg, (int) $item['part'], remove_query_arg( $part_arg ) ) ) . '#' . esc_attr( $item['id'] )
				: '#' . esc_attr( $item['id'] );
			$extra = isset( $item['part'] ) && '' !== $part_arg ? ' data-part="' . (int) $item['part'] . '"' : '';

			$html .= '<li><a class="jimca-toc__link" href="' . $href . '" data-id="' . esc_attr( $item['id'] ) . '"' . $extra . ' data-jimca-toc-link>' . esc_html( $item['text'] ) . '</a>';
			$prev  = $level;
		}

		return $html . '</li>' . str_repeat( '</ol></li>', $prev ) . '</ol>';
	}

	/**
	 * Inline SVG icons, drawn on Material Design's 24x24 grid.
	 *
	 * Inline rather than an icon font or sprite: no extra request, the color
	 * follows the reading theme through `currentColor`, and they stay visible
	 * in the operating system's high-contrast mode, where image icons vanish.
	 * The strings are fixed literals with no dynamic part.
	 *
	 * @param string $name Icon name.
	 * @return string SVG markup, or '' for an unknown name.
	 */
	public static function get_icon( $name ) {
		$open  = '<svg class="jimca-player__icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
		$close = '</svg>';

		$icons = array(
			'menu'     => '<path d="M4 6.5h16"/><path d="M4 12h16"/><path d="M4 17.5h16"/>',
			'search'   => '<circle cx="10.5" cy="10.5" r="6"/><path d="m15 15 5.5 5.5"/>',
			'settings' => '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 13.5a7.7 7.7 0 0 0 0-3l1.9-1.5-2-3.4-2.3.9a7.6 7.6 0 0 0-2.6-1.5L14 2.5h-4l-.4 2.5a7.6 7.6 0 0 0-2.6 1.5l-2.3-.9-2 3.4L4.6 10.5a7.7 7.7 0 0 0 0 3l-1.9 1.5 2 3.4 2.3-.9a7.6 7.6 0 0 0 2.6 1.5l.4 2.5h4l.4-2.5a7.6 7.6 0 0 0 2.6-1.5l2.3.9 2-3.4Z"/>',
			'previous' => '<path d="m14.5 6-6 6 6 6"/>',
			'next'     => '<path d="m9.5 6 6 6-6 6"/>',
			'toc'      => '<path d="M4 6h16"/><path d="M8 10.5h12"/><path d="M8 15h12"/><path d="M4 19.5h16"/>',
			'close'    => '<path d="m6 6 12 12"/><path d="M18 6 6 18"/>',
			'play'     => '<path d="M8 5.5v13l10-6.5Z" fill="currentColor"/>',
			'chat'     => '<path d="M4 5.5h16v10H9l-5 4Z"/><path d="M8 9.5h8"/><path d="M8 12.5h5"/>',
			'mic'      => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0"/><path d="M12 17.5V21"/>',
			'edit'     => '<path d="M4 20h4L19 9l-4-4L4 16Z"/><path d="m13.5 6.5 4 4"/>',
			'minimize' => '<path d="M5 12h14"/>',
			'send'     => '<path d="M21 3 10 14"/><path d="m21 3-7 18-4-7-7-4Z"/>',
			'quick'    => '<path d="M5 9h14"/><path d="M5 15h14"/>',
			'download' => '<path d="M12 4v11"/><path d="m8 11 4 4 4-4"/><path d="M5 19h14"/>',
			'share'    => '<path d="M12 15V4"/><path d="m8 8 4-4 4 4"/><path d="M5 12v7h14v-7"/>',
			'pause'    => '<path d="M8 5v14" stroke-width="3.2"/><path d="M16 5v14" stroke-width="3.2"/>',
			'speed'    => '<path d="M3.5 6.5v11l7-5.5Z" fill="currentColor"/><path d="M13 6.5v11l7-5.5Z" fill="currentColor"/>',
			'stop'     => '<circle cx="12" cy="12" r="9" fill="currentColor" stroke="none"/><rect x="9" y="9" width="6" height="6" rx="1.2" fill="var(--jimca-player-surface)" stroke="none"/>',
			'hide'     => '<path d="M2.5 12S6 6.5 12 6.5c1.4 0 2.7.3 3.8.8"/><path d="M19.2 9.2c1.4 1.3 2.3 2.8 2.3 2.8S18 17.5 12 17.5c-1.6 0-3-.4-4.2-1"/><circle cx="12" cy="12" r="2.6"/><path d="m4 4 16 16"/>',
			'show'     => '<path d="M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.6"/>',
		);

		if ( ! isset( $icons[ $name ] ) ) {
			return '';
		}

		return $open . $icons[ $name ] . $close;
	}

	private function enqueue_tutor_assets() {
		static $done = false;

		if ( $done ) {
			return;
		}
		$done = true;

		wp_enqueue_script( 'jimca-tutor', jimca_asset_url( 'assets/js/tutor.js' ), array(), jimca_asset_version( 'assets/js/tutor.js' ), true );
		wp_localize_script(
			'jimca-tutor',
			'jimcaTutorI18n',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'action'      => JIMCA_Tutor::AJAX,
				'you'         => __( 'You', 'jim-conversor-acessivel' ),
				'thinking'    => __( 'Thinking…', 'jim-conversor-acessivel' ),
				'failed'      => __( 'The tutor could not answer right now. Try again in a moment.', 'jim-conversor-acessivel' ),
				'listening'   => __( 'Listening… speak your question.', 'jim-conversor-acessivel' ),
				'heard'       => __( 'Done listening. Check the question and send it.', 'jim-conversor-acessivel' ),
				'micDenied'   => __( 'The browser did not allow the microphone.', 'jim-conversor-acessivel' ),
				'cleared'     => __( 'New conversation started.', 'jim-conversor-acessivel' ),
				'empty'       => __( 'Type a question first.', 'jim-conversor-acessivel' ),
				'suggestions' => __( 'Suggestions', 'jim-conversor-acessivel' ),
				'exported'    => __( 'Conversation downloaded.', 'jim-conversor-acessivel' ),
				// The quick texts the administrator chose (Settings > JIM > Advanced); [label, text, send at once].
				'chips'       => JIMCA_Tutor::active_chips(),
			)
		);
	}

	/**
	 * Loads only what the page needs: the reader's bar and search in reader
	 * mode, the post bar in post mode, the image viewer when the document
	 * has images. Scripts are deferred (they sit in the footer already).
	 *
	 * @param string $mode       'reader' or 'post'.
	 * @param bool   $has_images The document has images.
	 * @param bool   $tutor      The AI tutor is shown (its panel uses the sizes frontend.js sets).
	 */
	private function enqueue_assets( $mode, $has_images, $tutor ) {
		$load = array(
			// Voice choice and text pieces shared by the reader and the post bar (see speech.js).
			'speech'   => 'reader' === $mode || $tutor || 'post' === $mode,
			'frontend' => 'reader' === $mode || $tutor,
			'pager'    => 'reader' === $mode,
			'post'     => 'post' === $mode,
			'lightbox' => $has_images,
		);
		$depends = array(
			'frontend' => array( 'jimca-speech' ),
			'post'     => array( 'jimca-speech' ),
		);

		if ( ! isset( $this->loaded['style'] ) ) {
			$this->loaded['style'] = true;
			wp_enqueue_style( 'jimca-frontend', jimca_asset_url( 'assets/css/frontend.css' ), array(), jimca_asset_version( 'assets/css/frontend.css' ) );
		}

		foreach ( $load as $key => $needed ) {
			if ( ! $needed || isset( $this->loaded[ $key ] ) ) {
				continue;
			}

			$this->loaded[ $key ] = true;
			$file                 = 'assets/js/' . $key . '.js';
			wp_enqueue_script( 'jimca-' . $key, jimca_asset_url( $file ), isset( $depends[ $key ] ) ? $depends[ $key ] : array(), jimca_asset_version( $file ), self::SCRIPT_ARGS );

			if ( 'pager' === $key ) {
				wp_localize_script(
					'jimca-pager',
					'jimcaPagerI18n',
					array(
						'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
						'actionPart'    => JIMCA_Pagination::AJAX_PART,
						'actionSearch'  => JIMCA_Pagination::AJAX_SEARCH,
						/* translators: 1: number of the part, 2: number of parts */
						'partOf'        => __( 'Part %1$s of %2$s', 'jim-conversor-acessivel' ),
						'loadFailed'    => __( 'Could not load this part. Try again.', 'jim-conversor-acessivel' ),
						'searching'     => __( 'Searching…', 'jim-conversor-acessivel' ),
						'tooShort'      => __( 'Type at least 2 letters.', 'jim-conversor-acessivel' ),
						'searchFailed'  => __( 'The search could not run. Try again.', 'jim-conversor-acessivel' ),
						'searchLimit'   => __( 'Too many searches in a short time. Wait a minute and try again.', 'jim-conversor-acessivel' ),
						'noResults'     => __( 'No results.', 'jim-conversor-acessivel' ),
						/* translators: %s: number of results */
						'resultsOne'    => __( '%s result.', 'jim-conversor-acessivel' ),
						/* translators: %s: number of results */
						'resultsMany'   => __( '%s results.', 'jim-conversor-acessivel' ),
						'showMore'      => __( 'Show more results', 'jim-conversor-acessivel' ),
					)
				);
			} elseif ( 'lightbox' === $key ) {
				wp_localize_script(
					'jimca-lightbox',
					'jimcaLightboxI18n',
					array(
						'open'     => __( 'Enlarge image', 'jim-conversor-acessivel' ),
						'dialog'   => __( 'Enlarged image', 'jim-conversor-acessivel' ),
						'close'    => __( 'Close', 'jim-conversor-acessivel' ),
						'previous' => __( 'Previous image', 'jim-conversor-acessivel' ),
						'next'     => __( 'Next image', 'jim-conversor-acessivel' ),
						/* translators: 1: position of the image, 2: number of images */
						'counter'  => __( 'Image %1$s of %2$s', 'jim-conversor-acessivel' ),
					)
				);
			} elseif ( 'frontend' === $key ) {
				wp_localize_script(
					'jimca-frontend',
					'jimcaFrontendI18n',
					array(
						'play'          => __( 'Listen', 'jim-conversor-acessivel' ),
						'pause'         => __( 'Pause', 'jim-conversor-acessivel' ),
						'resume'        => __( 'Resume', 'jim-conversor-acessivel' ),
						'stop'          => __( 'Stop', 'jim-conversor-acessivel' ),
						'statusReading' => __( 'Reading aloud.', 'jim-conversor-acessivel' ),
						'statusPaused'  => __( 'Reading paused.', 'jim-conversor-acessivel' ),
						'statusStopped' => __( 'Reading stopped.', 'jim-conversor-acessivel' ),
						'statusDone'    => __( 'Reading finished.', 'jim-conversor-acessivel' ),
						'unsupported'   => __( 'This browser does not support reading aloud.', 'jim-conversor-acessivel' ),
						/* translators: said before an image description when reading aloud */
						'imageLabel'    => __( 'Image:', 'jim-conversor-acessivel' ),
						'increaseFont'  => __( 'Increase text size', 'jim-conversor-acessivel' ),
						'decreaseFont'  => __( 'Decrease text size', 'jim-conversor-acessivel' ),
						'resetFont'     => __( 'Default text size', 'jim-conversor-acessivel' ),
						'contrastOn'    => __( 'Turn on high contrast', 'jim-conversor-acessivel' ),
						'contrastOff'   => __( 'Turn off high contrast', 'jim-conversor-acessivel' ),
						'voiceLabel'    => __( 'Voice', 'jim-conversor-acessivel' ),
						'rateLabel'     => __( 'Reading speed', 'jim-conversor-acessivel' ),
						'controlsHide'  => __( 'Hide controls', 'jim-conversor-acessivel' ),
						'controlsShow'  => __( 'Show controls', 'jim-conversor-acessivel' ),
						'textSize'      => __( 'Text size', 'jim-conversor-acessivel' ),
						'voiceAll'      => __( 'All voices', 'jim-conversor-acessivel' ),
						'voiceFemale'   => __( 'Female', 'jim-conversor-acessivel' ),
						'voiceMale'     => __( 'Male', 'jim-conversor-acessivel' ),
						'voiceAuto'     => __( 'Automatic', 'jim-conversor-acessivel' ),
					)
				);
			}
		}

		if ( ! isset( $this->loaded['marker'] ) ) {
			$this->loaded['marker'] = true;
			wp_enqueue_script( 'jimca-marker', jimca_asset_url( 'assets/js/marker.js' ), array(), jimca_asset_version( 'assets/js/marker.js' ), self::SCRIPT_ARGS );
				wp_localize_script(
					'jimca-marker',
					'jimcaMarkerI18n',
					array(
						'marked'  => __( 'Passage highlighted.', 'jim-conversor-acessivel' ),
						'removed' => __( 'Highlight removed.', 'jim-conversor-acessivel' ),
						'cleared' => __( 'All highlights were removed.', 'jim-conversor-acessivel' ),
						'shared'  => __( 'Image ready.', 'jim-conversor-acessivel' ),
						'saved'   => __( 'Text file downloaded.', 'jim-conversor-acessivel' ),
						'failed'  => __( 'Could not create the image.', 'jim-conversor-acessivel' ),
					)
				);
		}
	}
}
