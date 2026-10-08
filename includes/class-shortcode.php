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
	private $rendered_on_page = false;

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

		$this->enqueue_assets();

		$settings = JIMCA_Admin::get_settings();
		$mode     = JIMCA_Post_Type::get_mode( $post->ID );
		// Not apply_filters( 'the_content' ): that would run do_shortcode() over
		// text extracted from an uploaded file, so a document containing
		// "[some-shortcode]" would run it. The HTML was already built and passed
		// through wp_kses_post() at conversion time.
		$content    = $post->post_content;
		$word_count = (int) get_post_meta( $post->ID, '_jimca_word_count', true );
		$uid        = 'jimca-' . $post->ID . '-' . wp_unique_id();

		if ( ! $word_count ) {
			$word_count = str_word_count( wp_strip_all_tags( $content ) );
		}
		$reading_minutes = max( 1, (int) ceil( $word_count / 200 ) );

		list( $content, $toc ) = self::build_toc( $content, $uid );

		$tutor = JIMCA_Tutor::is_shown( $atts['tutor'] ) ? JIMCA_Tutor::get_settings() : null;

		if ( $tutor ) {
			$this->enqueue_tutor_assets();
		}

		ob_start();
		include JIMCA_PLUGIN_DIR . 'templates/document-viewer.php';
		return ob_get_clean();
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
	 * Nested list (<ol> inside <li>) from the heading levels. A jump of levels
	 * (<h2> straight to <h4>) goes down one level only, so no item is left
	 * without a parent.
	 *
	 * @param array $toc See build_toc().
	 * @return string Escaped HTML.
	 */
	public static function render_toc_list( array $toc ) {
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

			$html .= '<li><a class="jimca-toc__link" href="#' . esc_attr( $item['id'] ) . '" data-jimca-toc-link>' . esc_html( $item['text'] ) . '</a>';
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
			'toc'      => '<path d="M4 6h16"/><path d="M8 10.5h12"/><path d="M8 15h12"/><path d="M4 19.5h16"/>',
			'close'    => '<path d="m6 6 12 12"/><path d="M18 6 6 18"/>',
			'font'     => '<path d="M12 4 5.5 20"/><path d="m12 4 6.5 16"/><path d="M8 15h8"/>',
			'contrast' => '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18Z" fill="currentColor" stroke="none"/>',
			'theme'    => '<path d="M12 3a9 9 0 0 0 0 18c.8 0 1.5-.7 1.5-1.5 0-.4-.2-.8-.4-1-.3-.3-.4-.6-.4-1 0-.8.7-1.5 1.5-1.5H16a5 5 0 0 0 5-5c0-4.4-4-8-9-8Z"/><circle cx="7.5" cy="12" r="1.1" fill="currentColor" stroke="none"/><circle cx="9.8" cy="7.8" r="1.1" fill="currentColor" stroke="none"/><circle cx="14.6" cy="7.8" r="1.1" fill="currentColor" stroke="none"/><circle cx="17.5" cy="11" r="1.1" fill="currentColor" stroke="none"/>',
			'voice'    => '<circle cx="8.5" cy="7.5" r="3"/><path d="M2.5 20a6 6 0 0 1 12 0"/><path d="M17.5 9.2a4 4 0 0 1 0 5.6"/><path d="M20 6.5a8 8 0 0 1 0 11"/>',
			'play'     => '<path d="M8 5.5v13l10-6.5Z" fill="currentColor"/>',
			'chat'     => '<path d="M4 5.5h16v10H9l-5 4Z"/><path d="M8 9.5h8"/><path d="M8 12.5h5"/>',
			'mic'      => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0"/><path d="M12 17.5V21"/>',
			'edit'     => '<path d="M4 20h4L19 9l-4-4L4 16Z"/><path d="m13.5 6.5 4 4"/>',
			'minimize' => '<path d="M5 12h14"/>',
			'send'     => '<path d="M21 3 10 14"/><path d="m21 3-7 18-4-7-7-4Z"/>',
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

		wp_enqueue_script( 'jimca-tutor', JIMCA_PLUGIN_URL . 'assets/js/tutor.js', array(), jimca_asset_version( 'assets/js/tutor.js' ), true );
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
				'chips'       => array(
					array( __( 'Explain a word', 'jim-conversor-acessivel' ), __( 'Explain the word: ', 'jim-conversor-acessivel' ) ),
					array( __( 'Summarize a part', 'jim-conversor-acessivel' ), __( 'Summarize the part about: ', 'jim-conversor-acessivel' ) ),
					array( __( 'Give an example', 'jim-conversor-acessivel' ), __( 'Give an example of: ', 'jim-conversor-acessivel' ) ),
				),
			)
		);
	}

	private function enqueue_assets() {
		if ( $this->rendered_on_page ) {
			return;
		}
		$this->rendered_on_page = true;

		wp_enqueue_style( 'jimca-frontend', JIMCA_PLUGIN_URL . 'assets/css/frontend.css', array(), jimca_asset_version( 'assets/css/frontend.css' ) );
		wp_enqueue_script( 'jimca-frontend', JIMCA_PLUGIN_URL . 'assets/js/frontend.js', array(), jimca_asset_version( 'assets/js/frontend.js' ), true );
		wp_enqueue_script( 'jimca-post', JIMCA_PLUGIN_URL . 'assets/js/post.js', array(), jimca_asset_version( 'assets/js/post.js' ), true );
		wp_enqueue_script( 'jimca-lightbox', JIMCA_PLUGIN_URL . 'assets/js/lightbox.js', array(), jimca_asset_version( 'assets/js/lightbox.js' ), true );
		wp_enqueue_script( 'jimca-marker', JIMCA_PLUGIN_URL . 'assets/js/marker.js', array(), jimca_asset_version( 'assets/js/marker.js' ), true );
		wp_localize_script(
			'jimca-marker',
			'jimcaMarkerI18n',
			array(
				'marked'  => __( 'Passage highlighted.', 'jim-conversor-acessivel' ),
				'removed' => __( 'Highlight removed.', 'jim-conversor-acessivel' ),
				'cleared' => __( 'All highlights were removed.', 'jim-conversor-acessivel' ),
			)
		);
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
				'menuClose'     => __( 'Close menu', 'jim-conversor-acessivel' ),
			)
		);
	}
}
