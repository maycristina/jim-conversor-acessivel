<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "SEO and accessibility score" box of a document: the score, what to
 * fix, and fields to fix it on the spot.
 *
 * Each field saves by AJAX and the box comes back redrawn with the new score
 * (assets/js/seo-box.js), so the person does not have to update the whole
 * page. The author field is also part of the page's form, so it still saves
 * with "Update" (JIMCA_Post_Type::save_seo_fields()).
 */
// phpcs:disable WordPress.Security.NonceVerification.Missing -- every AJAX call checks the nonce and the capability in document() before it reads anything.
class JIMCA_Seo_Box {

	const NONCE   = 'jimca_seo_box';
	const SAVE    = 'jimca_seo_save';
	const DETECT  = 'jimca_seo_detect';
	const HEADING = 'jimca_seo_headings';

	public static function register_hooks() {
		add_action( 'wp_ajax_' . self::SAVE, array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_' . self::DETECT, array( __CLASS__, 'ajax_detect' ) );
		add_action( 'wp_ajax_' . self::HEADING, array( __CLASS__, 'ajax_headings' ) );
	}

	/**
	 * Script and texts for the edit screen of a document.
	 *
	 * @param int $post_id Document ID.
	 */
	public static function enqueue( $post_id ) {
		wp_enqueue_script( 'jimca-seo-box', jimca_asset_url( 'assets/js/seo-box.js' ), array(), jimca_asset_version( 'assets/js/seo-box.js' ), true );
		wp_localize_script(
			'jimca-seo-box',
			'jimcaSeoBox',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'doc'       => (int) $post_id,
				'actSave'   => self::SAVE,
				'actDetect' => self::DETECT,
				'actFix'    => self::HEADING,
				'working'   => __( 'Saving…', 'jim-conversor-acessivel' ),
				'failed'    => __( 'Could not save. Reload the page and try again.', 'jim-conversor-acessivel' ),
				'detected'  => __( 'Suggestions filled in. Review them and save.', 'jim-conversor-acessivel' ),
				'nothing'   => __( 'Nothing was found in the text to suggest.', 'jim-conversor-acessivel' ),
				'tooShort'  => __( 'too short', 'jim-conversor-acessivel' ),
				'tooLong'   => __( 'too long', 'jim-conversor-acessivel' ),
				'characters' => __( 'characters', 'jim-conversor-acessivel' ),
			)
		);
	}

	/**
	 * Draws the box.
	 *
	 * @param WP_Post $post The document.
	 */
	public static function render( $post ) {
		wp_nonce_field( 'jimca_save_seo', 'jimca_seo_nonce' );
		echo '<div id="jimca-seo-box" data-jimca-seo-box>';
		self::render_body( $post );
		echo '</div>';
	}

	/**
	 * Everything inside the box (what the AJAX answers send back).
	 *
	 * @param WP_Post $post The document.
	 */
	public static function render_body( $post ) {
		$facts  = JIMCA_Scorecard::facts( $post->ID );
		$result = JIMCA_Scorecard::evaluate( $facts );
		$meta   = JIMCA_Metadata::get( $post->ID );
		?>
		<p class="jimca-seo-status" role="status" aria-live="polite" tabindex="-1" data-jimca-seo-status></p>
		<p class="jimca-seo-score">
			<strong data-jimca-seo-score><?php echo esc_html( number_format_i18n( $result['score'] ) ); ?></strong>/100 · <?php echo esc_html( JIMCA_Scorecard::grade_label( $result['score'] ) ); ?>
		</p>

		<p>
			<label for="jimca_seo_author"><strong><?php esc_html_e( 'Author of the document', 'jim-conversor-acessivel' ); ?></strong></label><br>
			<input type="text" id="jimca_seo_author" name="jimca_seo_author" class="widefat" maxlength="120" value="<?php echo esc_attr( $meta['author'] ); ?>" aria-describedby="jimca_seo_author_help" data-jimca-seo-field="author">
		</p>
		<p class="jimca-seo-buttons">
			<button type="button" class="button button-small" data-jimca-fill="#jimca_seo_author" data-jimca-fill-value="<?php echo esc_attr( wp_get_current_user()->display_name ); ?>"><?php esc_html_e( 'Use my name', 'jim-conversor-acessivel' ); ?></button>
			<button type="button" class="button button-small" data-jimca-seo-detect hidden><?php esc_html_e( 'Auto-detect from text', 'jim-conversor-acessivel' ); ?></button>
			<button type="button" class="button button-primary button-small" data-jimca-seo-save="author" hidden><?php esc_html_e( 'Save author', 'jim-conversor-acessivel' ); ?></button>
		</p>
		<p class="description" id="jimca_seo_author_help"><?php esc_html_e( 'Search engines use the author to judge who is behind the text. "Save author" saves it now; the score is recalculated.', 'jim-conversor-acessivel' ); ?></p>
		<?php

		if ( ! $facts['published'] ) {
			echo '<p class="notice notice-warning inline" style="padding:8px 12px;"><strong>' . esc_html__( 'Orphan document:', 'jim-conversor-acessivel' ) . '</strong> ' . esc_html__( 'it is not on any published page, so search engines cannot find it. Paste the shortcode on a page, or use "Also publish in" when converting.', 'jim-conversor-acessivel' ) . '</p>';
		}

		foreach ( JIMCA_Scorecard::group_labels() as $group => $label ) {
			echo '<h4>' . esc_html( $label ) . '</h4><ul>';

			foreach ( $result['checks'] as $check ) {
				if ( $check['group'] !== $group ) {
					continue;
				}

				echo '<li><strong>' . ( $check['passed'] ? esc_html__( 'Passed:', 'jim-conversor-acessivel' ) : esc_html__( 'To fix:', 'jim-conversor-acessivel' ) ) . '</strong> '
					. esc_html( $check['label'] ) . ' (' . esc_html( number_format_i18n( $check['points'] ) ) . '/' . esc_html( number_format_i18n( $check['max'] ) ) . ')';

				if ( ! $check['passed'] ) {
					echo '<br><span class="description">' . esc_html( $check['hint'] ) . '</span>';
					self::render_fix( $check['id'], $post, $facts, $meta );
				}

				echo '</li>';
			}

			echo '</ul>';
		}
	}

	/**
	 * The way to fix a failed check, under it.
	 *
	 * @param string  $id    Check id.
	 * @param WP_Post $post  The document.
	 * @param array   $facts See JIMCA_Scorecard::facts().
	 * @param array   $meta  See JIMCA_Metadata::get().
	 */
	private static function render_fix( $id, $post, array $facts, array $meta ) {
		if ( 'title' === $id ) {
			self::render_field( 'title', __( 'Title', 'jim-conversor-acessivel' ), $facts['title'], JIMCA_Autofill::TITLE_MAX, JIMCA_Autofill::TITLE_MIN, false );
		} elseif ( 'description' === $id ) {
			self::render_field( 'summary', __( 'Summary for search results', 'jim-conversor-acessivel' ), $meta['subject'], JIMCA_SEO::DESCRIPTION_CHARS, JIMCA_Scorecard::MIN_DESCRIPTION, true );
		} elseif ( 'author' === $id ) {
			echo '<br><a href="#jimca_seo_author">' . esc_html__( 'Fill in the author above', 'jim-conversor-acessivel' ) . '</a>';
		} elseif ( 'headings' === $id ) {
			echo '<p class="jimca-seo-buttons"><button type="button" class="button button-primary button-small" data-jimca-seo-headings hidden>' . esc_html__( 'Auto-fix headings', 'jim-conversor-acessivel' ) . '</button></p>';
			echo '<a href="#jimca_documento_audit">' . esc_html__( 'Open the conversion review', 'jim-conversor-acessivel' ) . '</a>'
				. ' · <a href="#postdivrich">' . esc_html__( 'Edit the content', 'jim-conversor-acessivel' ) . '</a>';
		}
	}

	/**
	 * A field with a live character counter and its own save button.
	 */
	private static function render_field( $field, $label, $value, $max, $min, $multiline ) {
		$id      = 'jimca_seo_' . $field;
		$counter = $id . '_count';
		?>
		<span class="jimca-seo-fix">
			<label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label>
			<?php if ( $multiline ) : ?>
				<textarea id="<?php echo esc_attr( $id ); ?>" class="widefat" rows="3" maxlength="<?php echo (int) $max * 2; ?>" aria-describedby="<?php echo esc_attr( $counter ); ?>" data-jimca-seo-field="<?php echo esc_attr( $field ); ?>" data-jimca-min="<?php echo (int) $min; ?>" data-jimca-max="<?php echo (int) $max; ?>"><?php echo esc_textarea( $value ); ?></textarea>
			<?php else : ?>
				<input type="text" id="<?php echo esc_attr( $id ); ?>" class="widefat" value="<?php echo esc_attr( $value ); ?>" aria-describedby="<?php echo esc_attr( $counter ); ?>" data-jimca-seo-field="<?php echo esc_attr( $field ); ?>" data-jimca-min="<?php echo (int) $min; ?>" data-jimca-max="<?php echo (int) $max; ?>">
			<?php endif; ?>
			<span class="description" id="<?php echo esc_attr( $counter ); ?>" data-jimca-count-for="<?php echo esc_attr( $field ); ?>">
				<?php
				/* translators: 1: characters typed, 2: shortest length, 3: longest length */
				echo esc_html( sprintf( __( '%1$s characters (use %2$s to %3$s)', 'jim-conversor-acessivel' ), number_format_i18n( mb_strlen( $value ) ), $min, $max ) );
				?>
			</span>
			<button type="button" class="button button-primary button-small" data-jimca-seo-save="<?php echo esc_attr( $field ); ?>" hidden><?php esc_html_e( 'Save', 'jim-conversor-acessivel' ); ?></button>
		</span>
		<?php
	}

	/**
	 * The document of an AJAX call, after the checks every call needs.
	 *
	 * @return WP_Post
	 */
	private static function document() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$id   = isset( $_POST['doc'] ) ? absint( $_POST['doc'] ) : 0;
		$post = $id ? get_post( $id ) : null;

		if ( ! $post || JIMCA_Post_Type::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot edit this document.', 'jim-conversor-acessivel' ) ), 403 );
		}

		return $post;
	}

	/**
	 * Redrawn box and a message, as the answer of a call.
	 *
	 * @param WP_Post $post    The document.
	 * @param string  $message What was done.
	 * @param array   $extra   More data for the script.
	 */
	private static function answer( $post, $message, array $extra = array() ) {
		clean_post_cache( $post->ID );
		ob_start();
		self::render_body( get_post( $post->ID ) );

		wp_send_json_success(
			array_merge(
				array(
					'message' => $message,
					'html'    => ob_get_clean(),
				),
				$extra
			)
		);
	}

	public static function ajax_save() {
		$post  = self::document();
		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$value = isset( $_POST['value'] ) ? sanitize_textarea_field( wp_unslash( $_POST['value'] ) ) : '';
		$meta  = get_post_meta( $post->ID, JIMCA_Metadata::META_KEY, true );
		$meta  = is_array( $meta ) ? $meta : array();
		$extra = array();

		if ( 'author' === $field ) {
			$value = mb_substr( trim( preg_replace( '/\s+/u', ' ', $value ) ), 0, 120 );

			if ( '' === $value ) {
				unset( $meta['author'] );
			} else {
				$meta['author'] = $value;
			}

			self::save_meta( $post->ID, $meta );
			$message = '' === $value ? __( 'Author removed.', 'jim-conversor-acessivel' ) : __( 'Author saved.', 'jim-conversor-acessivel' );
		} elseif ( 'summary' === $field ) {
			$value = JIMCA_SEO::shorten( trim( preg_replace( '/\s+/u', ' ', $value ) ), JIMCA_Autofill::SUMMARY_MAX );

			if ( '' === $value ) {
				unset( $meta['subject'] );
			} else {
				$meta['subject'] = $value;
			}

			self::save_meta( $post->ID, $meta );
			$message = __( 'Summary saved.', 'jim-conversor-acessivel' );
		} elseif ( 'title' === $field ) {
			$value = trim( preg_replace( '/\s+/u', ' ', $value ) );

			if ( '' === $value ) {
				wp_send_json_error( array( 'message' => __( 'The title cannot be empty.', 'jim-conversor-acessivel' ) ), 400 );
			}

			wp_update_post(
				array(
					'ID'         => $post->ID,
					'post_title' => $value,
				)
			);
			$extra['title'] = $value;
			$message        = __( 'Title saved.', 'jim-conversor-acessivel' );
		} else {
			wp_send_json_error( array( 'message' => __( 'Unknown field.', 'jim-conversor-acessivel' ) ), 400 );
		}

		self::answer( $post, $message, $extra );
	}

	private static function save_meta( $post_id, array $meta ) {
		if ( $meta ) {
			update_post_meta( $post_id, JIMCA_Metadata::META_KEY, $meta );
		} else {
			delete_post_meta( $post_id, JIMCA_Metadata::META_KEY );
		}
	}

	/** Suggestions from the text; nothing is saved. */
	public static function ajax_detect() {
		$post = self::document();

		wp_send_json_success(
			array(
				'author'  => JIMCA_Autofill::detect_author( $post->post_content ),
				'title'   => JIMCA_Autofill::detect_title( $post->post_content, $post->post_title ),
				'summary' => JIMCA_Autofill::detect_summary( $post->post_content ),
			)
		);
	}

	/**
	 * Turns section lines into headings in the content the person has in the
	 * editor (it may have unsaved changes), saves it and sends it back so the
	 * editor shows the same text.
	 */
	public static function ajax_headings() {
		$post = self::document();

		// The editor's content, so unsaved edits are not lost; the document's own when none is sent.
		$content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : $post->post_content; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through wp_kses_post(), the filter the converter uses.
		$fixed   = JIMCA_Autofill::fix_headings( $content );

		if ( 0 === $fixed['count'] ) {
			wp_send_json_error( array( 'message' => __( 'No lines that look like section titles were found. Use the conversion review or edit the content.', 'jim-conversor-acessivel' ) ), 200 );
		}

		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $fixed['html'],
			)
		);

		self::answer(
			$post,
			/* translators: %s: number of headings made */
			sprintf( _n( '%s heading created. The previous text is in the revisions.', '%s headings created. The previous text is in the revisions.', $fixed['count'], 'jim-conversor-acessivel' ), number_format_i18n( $fixed['count'] ) ),
			array( 'content' => $fixed['html'] )
		);
	}
}
// phpcs:enable WordPress.Security.NonceVerification.Missing
