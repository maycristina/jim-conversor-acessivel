<?php
/**
 * Accessible document viewer.
 *
 * Variables set by JIMCA_Shortcode::render():
 *
 * @var WP_Post    $post
 * @var string     $content
 * @var int        $word_count
 * @var string     $uid
 * @var array      $settings
 * @var int        $reading_minutes Estimated reading time, in minutes.
 * @var string     $mode            'reader' (with the reading bar) or 'post' (plain HTML page).
 * @var array      $toc             Document headings (see JIMCA_Shortcode::build_toc()); empty = no table of contents.
 * @var array|null $tutor           AI tutor settings (see JIMCA_Tutor::get_settings()); null = no tutor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Speeds in the speed menu. The Web Speech API accepts 0.1 to 10, but below
 * 0.5 and above 2 most voices become unintelligible.
 */
$jimca_rates = array( '0.5', '0.75', '1', '1.25', '1.5', '2' );

$jimca_current_rate = (string) ( isset( $settings['tts_default_rate'] ) ? $settings['tts_default_rate'] : 1 );
?>
<section class="jimca-viewer jimca-theme-<?php echo esc_attr( $settings['theme'] ); ?> jimca-mode-<?php echo esc_attr( $mode ); ?>" id="<?php echo esc_attr( $uid ); ?>" data-jimca-viewer data-jimca-doc="<?php echo esc_attr( $post->ID ); ?>" aria-labelledby="<?php echo esc_attr( $uid ); ?>-title">

	<a class="jimca-skip-link jimca-visually-hidden" href="#<?php echo esc_attr( $uid ); ?>-content">
		<?php esc_html_e( 'Skip to the document content', 'jim-conversor-acessivel' ); ?>
	</a>

	<h2 id="<?php echo esc_attr( $uid ); ?>-title" class="jimca-title"><?php echo esc_html( get_the_title( $post ) ); ?></h2>

	<?php if ( 'reader' === $mode ) : ?>
	<p class="jimca-meta">
		<?php
		printf(
			/* translators: %s: approximate number of words */
			esc_html__( 'About %s words.', 'jim-conversor-acessivel' ),
			esc_html( number_format_i18n( $word_count ) )
		);
		?>
	</p>
	<?php endif; ?>

	<?php if ( 'post' === $mode ) : ?>
	<?php
	/*
	 * Post bar: reading time on the left; listen and share on the right. The
	 * buttons stay hidden until post.js runs: without JS they do nothing.
	 */
	?>
	<div class="jimca-postbar" data-jimca-postbar
		data-rate="<?php echo esc_attr( isset( $settings['tts_default_rate'] ) ? $settings['tts_default_rate'] : 1 ); ?>"
		data-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
		data-listen="<?php esc_attr_e( 'Listen', 'jim-conversor-acessivel' ); ?>"
		data-pause="<?php esc_attr_e( 'Pause', 'jim-conversor-acessivel' ); ?>"
		data-resume="<?php esc_attr_e( 'Resume', 'jim-conversor-acessivel' ); ?>"
		data-copied="<?php esc_attr_e( 'Link copied.', 'jim-conversor-acessivel' ); ?>"
		data-copy-failed="<?php esc_attr_e( 'Could not copy the link.', 'jim-conversor-acessivel' ); ?>"
		data-unsupported="<?php esc_attr_e( 'This browser does not support reading aloud.', 'jim-conversor-acessivel' ); ?>"
		data-done="<?php esc_attr_e( 'Reading finished.', 'jim-conversor-acessivel' ); ?>">
		<p class="jimca-postbar__time">
			<?php
			printf(
				/* translators: %s: minutes of reading */
				esc_html__( '%s min read', 'jim-conversor-acessivel' ),
				esc_html( number_format_i18n( $reading_minutes ) )
			);
			?>
		</p>
		<div class="jimca-postbar__actions" data-jimca-postbar-actions hidden>
			<button type="button" class="jimca-postbar__btn" data-jimca-post-listen aria-pressed="false">
				<span data-jimca-icon="play"><?php echo JIMCA_Shortcode::get_icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?></span>
				<span data-jimca-icon="pause" hidden><?php echo JIMCA_Shortcode::get_icon( 'pause' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?></span>
				<span class="jimca-visually-hidden" data-jimca-post-listen-label><?php esc_html_e( 'Listen', 'jim-conversor-acessivel' ); ?></span>
			</button>
			<button type="button" class="jimca-postbar__btn" data-jimca-post-share>
				<?php echo JIMCA_Shortcode::get_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
				<span class="jimca-visually-hidden"><?php esc_html_e( 'Share', 'jim-conversor-acessivel' ); ?></span>
			</button>
		</div>
		<p class="jimca-visually-hidden" role="status" aria-live="polite" data-jimca-post-status></p>
	</div>
	<?php endif; ?>

	<?php if ( 'reader' === $mode ) : ?>
	<?php
	/*
	 * Reading player.
	 *
	 * Early in the HTML on purpose: it floats at the bottom of the screen
	 * (CSS), but keyboard and screen reader users reach it right after the
	 * title, not after going through a whole book.
	 *
	 * Hidden until the JS reveals it: without JS the controls do nothing, and
	 * an inert floating bar would only cover the text.
	 */
	?>
	<div class="jimca-player" data-jimca-player hidden>

		<div class="jimca-player__bar" role="toolbar" aria-label="<?php esc_attr_e( 'Reading controls', 'jim-conversor-acessivel' ); ?>" data-jimca-player-bar>

			<?php if ( $toc ) : ?>
				<?php // Only when the document has headings. ?>
				<div class="jimca-player__slot">
					<button
						type="button"
						class="jimca-player__btn"
						data-jimca-toc-toggle
						aria-expanded="false"
						aria-controls="<?php echo esc_attr( $uid ); ?>-toc"
					>
						<?php echo JIMCA_Shortcode::get_icon( 'toc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
						<span class="jimca-visually-hidden"><?php esc_html_e( 'Contents', 'jim-conversor-acessivel' ); ?></span>
					</button>
				</div>
			<?php endif; ?>

			<div class="jimca-player__slot">
				<button
					type="button"
					class="jimca-player__btn"
					data-jimca-menu-trigger="font"
					aria-haspopup="true"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $uid ); ?>-menu-font"
				>
					<?php echo JIMCA_Shortcode::get_icon( 'font' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Text size and theme', 'jim-conversor-acessivel' ); ?></span>
				</button>

				<div class="jimca-player__menu" id="<?php echo esc_attr( $uid ); ?>-menu-font" role="menu" aria-label="<?php esc_attr_e( 'Text appearance', 'jim-conversor-acessivel' ); ?>" data-jimca-menu="font" hidden>
					<div role="group" aria-label="<?php esc_attr_e( 'Text size', 'jim-conversor-acessivel' ); ?>">
						<button type="button" role="menuitem" class="jimca-player__menuitem" data-jimca-font="increase" aria-label="<?php esc_attr_e( 'A+ — increase text size', 'jim-conversor-acessivel' ); ?>">A+</button>
						<button type="button" role="menuitem" class="jimca-player__menuitem" data-jimca-font="decrease" aria-label="<?php esc_attr_e( 'A- — decrease text size', 'jim-conversor-acessivel' ); ?>">A-</button>
						<button type="button" role="menuitem" class="jimca-player__menuitem" data-jimca-font="reset" aria-label="<?php esc_attr_e( 'A — default text size', 'jim-conversor-acessivel' ); ?>">A</button>
					</div>

					<hr class="jimca-player__divider">

					<div role="group" aria-label="<?php esc_attr_e( 'Reading theme', 'jim-conversor-acessivel' ); ?>">
						<?php foreach ( JIMCA_Admin::get_available_themes() as $jimca_theme_key => $jimca_theme_label ) : ?>
							<button
								type="button"
								role="menuitemradio"
								class="jimca-player__menuitem"
								data-jimca-theme="<?php echo esc_attr( $jimca_theme_key ); ?>"
								aria-checked="<?php echo esc_attr( $settings['theme'] === $jimca_theme_key ? 'true' : 'false' ); ?>"
							><?php echo esc_html( $jimca_theme_label ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<div class="jimca-player__slot">
				<button type="button" class="jimca-player__btn" data-jimca-contrast-toggle aria-pressed="false">
					<?php echo JIMCA_Shortcode::get_icon( 'contrast' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'High contrast', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>

			<div class="jimca-player__slot">
				<button
					type="button"
					class="jimca-player__btn"
					data-jimca-menu-trigger="voice"
					aria-haspopup="true"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $uid ); ?>-menu-voice"
				>
					<?php echo JIMCA_Shortcode::get_icon( 'voice' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Voice', 'jim-conversor-acessivel' ); ?></span>
				</button>

				<?php // Filled by the JS: the voices are the browser's. ?>
				<div class="jimca-player__menu" id="<?php echo esc_attr( $uid ); ?>-menu-voice" role="menu" aria-label="<?php esc_attr_e( 'Voice', 'jim-conversor-acessivel' ); ?>" data-jimca-menu="voice" hidden></div>
			</div>

			<div class="jimca-player__slot">
				<button type="button" class="jimca-player__btn jimca-player__btn--play" data-jimca-play aria-pressed="false">
					<?php
					/*
					 * Both icons are in the HTML and the JS only toggles which one
					 * shows, so no SVG is built in JavaScript and the right icon
					 * appears without a jump.
					 */
					?>
					<span class="jimca-player__icon-slot" data-jimca-icon="play">
						<?php echo JIMCA_Shortcode::get_icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					</span>
					<span class="jimca-player__icon-slot" data-jimca-icon="pause" hidden>
						<?php echo JIMCA_Shortcode::get_icon( 'pause' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					</span>
					<span class="jimca-visually-hidden" data-jimca-play-label><?php esc_html_e( 'Listen', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>

			<div class="jimca-player__slot">
				<button
					type="button"
					class="jimca-player__btn"
					data-jimca-menu-trigger="speed"
					aria-haspopup="true"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $uid ); ?>-menu-speed"
				>
					<?php echo JIMCA_Shortcode::get_icon( 'speed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Reading speed', 'jim-conversor-acessivel' ); ?></span>
				</button>

				<div class="jimca-player__menu" id="<?php echo esc_attr( $uid ); ?>-menu-speed" role="menu" aria-label="<?php esc_attr_e( 'Reading speed', 'jim-conversor-acessivel' ); ?>" data-jimca-menu="speed" hidden>
					<?php foreach ( $jimca_rates as $jimca_rate ) : ?>
						<button
							type="button"
							role="menuitemradio"
							class="jimca-player__menuitem"
							data-jimca-rate="<?php echo esc_attr( $jimca_rate ); ?>"
							aria-checked="<?php echo esc_attr( $jimca_current_rate === $jimca_rate ? 'true' : 'false' ); ?>"
						><?php echo esc_html( number_format_i18n( (float) $jimca_rate, ( (float) $jimca_rate === floor( (float) $jimca_rate ) ) ? 0 : 2 ) . '×' ); ?></button>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="jimca-player__slot">
				<button type="button" class="jimca-player__btn" data-jimca-stop disabled>
					<?php echo JIMCA_Shortcode::get_icon( 'stop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Stop', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>

			<div class="jimca-player__slot">
				<button type="button" class="jimca-player__btn" data-jimca-hide>
					<?php echo JIMCA_Shortcode::get_icon( 'hide' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Hide controls', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>
		</div>

		<?php // Brings the bar back after it was hidden, in the same corner. ?>
		<button type="button" class="jimca-player__restore" data-jimca-show hidden>
			<?php echo JIMCA_Shortcode::get_icon( 'show' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
			<span class="jimca-visually-hidden"><?php esc_html_e( 'Show reading controls', 'jim-conversor-acessivel' ); ?></span>
		</button>

		<p class="jimca-status jimca-visually-hidden" role="status" aria-live="polite" data-jimca-status></p>
	</div>
	<?php endif; ?>

	<?php if ( $toc ) : ?>
		<?php
		/*
		 * Table of contents. Without JS it is a plain list of links before the
		 * text. With JS it becomes a side panel opened from the reading bar
		 * (see setupToc() in frontend.js).
		 */
		?>
		<nav class="jimca-toc" id="<?php echo esc_attr( $uid ); ?>-toc" aria-labelledby="<?php echo esc_attr( $uid ); ?>-toc-title" data-jimca-toc>
			<div class="jimca-toc__header">
				<h2 class="jimca-toc__title" id="<?php echo esc_attr( $uid ); ?>-toc-title"><?php esc_html_e( 'Contents', 'jim-conversor-acessivel' ); ?></h2>
				<button type="button" class="jimca-player__btn jimca-toc__close" data-jimca-toc-close hidden>
					<?php echo JIMCA_Shortcode::get_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Close contents', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>
			<?php echo JIMCA_Shortcode::render_toc_list( $toc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr()/esc_html() in render_toc_list(). ?>
		</nav>
	<?php endif; ?>

	<div id="<?php echo esc_attr( $uid ); ?>-content" class="jimca-content" tabindex="-1" data-jimca-content>
		<?php echo wp_kses_post( $content ); ?>
	</div>

	<?php if ( $tutor ) : ?>
		<?php // Readers who hid the tutor bring it back here, at the end of the document (tutor.js). ?>
		<p class="jimca-tutor-restore" data-jimca-tutor-restore hidden>
			<button type="button" class="jimca-tutor-restore__btn">
				<?php echo JIMCA_Shortcode::get_icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
				<?php
				/* translators: %s: tutor name */
				printf( esc_html__( 'Show the tutor (%s)', 'jim-conversor-acessivel' ), esc_html( $tutor['name'] ) );
				?>
			</button>
		</p>
	<?php endif; ?>

	<?php
	/*
	 * Highlighter. Shown only while text is selected, and only with
	 * JavaScript (marker.js); highlights stay in the reader's browser.
	 */
	?>
	<div class="jimca-marker" data-jimca-marker role="toolbar" aria-label="<?php esc_attr_e( 'Text highlighter', 'jim-conversor-acessivel' ); ?>" hidden>
		<span class="jimca-marker__label"><?php esc_html_e( 'Highlight passage:', 'jim-conversor-acessivel' ); ?></span>
		<?php
		$jimca_marker_colors = array(
			'yellow' => __( 'Highlight in yellow', 'jim-conversor-acessivel' ),
			'green'  => __( 'Highlight in green', 'jim-conversor-acessivel' ),
			'blue'   => __( 'Highlight in blue', 'jim-conversor-acessivel' ),
			'pink'   => __( 'Highlight in pink', 'jim-conversor-acessivel' ),
		);
		foreach ( $jimca_marker_colors as $jimca_marker_key => $jimca_marker_label ) :
			?>
			<button type="button" class="jimca-marker__swatch jimca-marker__swatch--<?php echo esc_attr( $jimca_marker_key ); ?>" data-jimca-mark="<?php echo esc_attr( $jimca_marker_key ); ?>">
				<span class="jimca-visually-hidden"><?php echo esc_html( $jimca_marker_label ); ?></span>
			</button>
		<?php endforeach; ?>
		<button type="button" class="jimca-marker__text" data-jimca-marker-remove hidden><?php esc_html_e( 'Remove highlight', 'jim-conversor-acessivel' ); ?></button>
		<button type="button" class="jimca-marker__text" data-jimca-marker-clear hidden><?php esc_html_e( 'Clear all', 'jim-conversor-acessivel' ); ?></button>
		<span class="jimca-visually-hidden" role="status" aria-live="polite" data-jimca-marker-status></span>
	</div>

	<?php if ( $tutor ) : ?>
		<div class="jimca-tutor" data-jimca-tutor data-doc="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( JIMCA_Tutor::nonce_action( $post->ID ) ) ); ?>" data-greeting="<?php echo esc_attr( $tutor['greeting'] ); ?>" data-name="<?php echo esc_attr( $tutor['name'] ); ?>" hidden>
			<button type="button" class="jimca-tutor__fab" data-jimca-tutor-open aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-tutor">
				<?php echo JIMCA_Shortcode::get_icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
				<span class="jimca-visually-hidden">
					<?php
					/* translators: %s: tutor name */
					printf( esc_html__( 'Ask the tutor (%s)', 'jim-conversor-acessivel' ), esc_html( $tutor['name'] ) );
					?>
				</span>
			</button>

			<section class="jimca-tutor__panel" id="<?php echo esc_attr( $uid ); ?>-tutor" role="dialog" aria-modal="false" aria-labelledby="<?php echo esc_attr( $uid ); ?>-tutor-title" data-jimca-tutor-panel hidden>
				<div class="jimca-tutor__header">
					<h2 class="jimca-tutor__title" id="<?php echo esc_attr( $uid ); ?>-tutor-title"><?php echo esc_html( $tutor['name'] ); ?></h2>
					<?php
					// The title attribute is the tooltip for mouse users; the hidden text names the button for everyone else.
					$jimca_tutor_actions = array(
						'clear' => array( 'edit', __( 'New conversation', 'jim-conversor-acessivel' ) ),
						'hide'  => array( 'minimize', __( 'Hide the tutor on this page', 'jim-conversor-acessivel' ) ),
						'close' => array( 'close', __( 'Close', 'jim-conversor-acessivel' ) ),
					);
					foreach ( $jimca_tutor_actions as $jimca_action => $jimca_action_data ) :
						?>
						<button type="button" class="jimca-tutor__icon-btn" data-jimca-tutor-<?php echo esc_attr( $jimca_action ); ?> title="<?php echo esc_attr( $jimca_action_data[1] ); ?>">
							<?php echo JIMCA_Shortcode::get_icon( $jimca_action_data[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
							<span class="jimca-visually-hidden"><?php echo esc_html( $jimca_action_data[1] ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>

				<div class="jimca-tutor__log" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Conversation with the tutor', 'jim-conversor-acessivel' ); ?>" tabindex="0" data-jimca-tutor-log></div>

				<form class="jimca-tutor__form" data-jimca-tutor-form>
					<label class="jimca-visually-hidden" for="<?php echo esc_attr( $uid ); ?>-tutor-question"><?php esc_html_e( 'Your question', 'jim-conversor-acessivel' ); ?></label>
					<textarea class="jimca-tutor__input" id="<?php echo esc_attr( $uid ); ?>-tutor-question" rows="1" maxlength="<?php echo esc_attr( JIMCA_Tutor::MAX_QUESTION_CHARS ); ?>" placeholder="<?php esc_attr_e( 'Ask about the document', 'jim-conversor-acessivel' ); ?>" data-jimca-tutor-input></textarea>
					<button type="button" class="jimca-tutor__icon-btn" data-jimca-tutor-mic aria-pressed="false" hidden>
						<?php echo JIMCA_Shortcode::get_icon( 'mic' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
						<span class="jimca-visually-hidden"><?php esc_html_e( 'Ask by voice', 'jim-conversor-acessivel' ); ?></span>
					</button>
					<button type="submit" class="jimca-tutor__icon-btn jimca-tutor__send">
						<?php echo JIMCA_Shortcode::get_icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
						<span class="jimca-visually-hidden"><?php esc_html_e( 'Send question', 'jim-conversor-acessivel' ); ?></span>
					</button>
				</form>

				<p class="jimca-tutor__note">
					<?php
					/* translators: %s: AI provider or service name */
					printf( esc_html__( 'AI-generated answers may contain errors. Your questions and parts of the document are sent to %s.', 'jim-conversor-acessivel' ), esc_html( JIMCA_AI::active_label() ) );
					?>
				</p>
				<p class="jimca-visually-hidden" role="status" aria-live="polite" data-jimca-tutor-status></p>
			</section>
		</div>
	<?php endif; ?>
</section>
