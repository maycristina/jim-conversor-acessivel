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
 * @var string     $title_tag       'h2', or 'p' when the title repeats the page's own (see JIMCA_Shortcode::same_title()).
 * @var string     $doc_lang        Language tag of the document when it differs from the page; '' = same as the page.
 * @var array|null $pager           Long document shown in parts: count, part, titles, arg (see JIMCA_Pagination); null = whole.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$jimca_current_rate = (string) ( isset( $settings['tts_default_rate'] ) ? $settings['tts_default_rate'] : 1 );

// "1×", "1.25×": the speed as the button shows it (no trailing zeros).
$jimca_rate_text = rtrim( rtrim( number_format_i18n( (float) $jimca_current_rate, 2 ), '0' ), '.,' ) . '×';
?>
<section class="jimca-viewer jimca-theme-<?php echo esc_attr( $settings['theme'] ); ?> jimca-mode-<?php echo esc_attr( $mode ); ?>" id="<?php echo esc_attr( $uid ); ?>" data-jimca-viewer<?php echo '' !== $doc_lang ? ' data-jimca-lang="' . esc_attr( $doc_lang ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute built with esc_attr(). ?> data-jimca-doc="<?php echo esc_attr( $post->ID ); ?>" data-jimca-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"<?php echo $pager ? ' data-jimca-parts="' . esc_attr( $pager['count'] ) . '" data-jimca-part="' . esc_attr( $pager['part'] ) . '" data-jimca-part-arg="' . esc_attr( $pager['arg'] ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes built with esc_attr(). ?> aria-labelledby="<?php echo esc_attr( $uid ); ?>-title">

	<?php
	/*
	 * Skip links (WCAG 2.4.1 Bypass Blocks; W3C in-page navigation, WebAIM
	 * "Skip Navigation Links"): the first Tab stops of the viewer, hidden
	 * until they receive focus. "Reading controls" is revealed by the JS
	 * (without JS the bar does not exist); the contents link is for the no-JS
	 * list only, since with JS the contents open from the bar.
	 */
	?>
	<nav class="jimca-skip" aria-label="<?php esc_attr_e( 'Skip links', 'jim-conversor-acessivel' ); ?>">
		<a class="jimca-skip__link" href="#<?php echo esc_attr( $uid ); ?>-content">
			<?php esc_html_e( 'Skip to the document content', 'jim-conversor-acessivel' ); ?>
		</a>
		<?php if ( 'reader' === $mode ) : ?>
		<a class="jimca-skip__link" href="#<?php echo esc_attr( $uid ); ?>-controls" data-jimca-skip-controls hidden>
			<?php esc_html_e( 'Skip to the reading controls', 'jim-conversor-acessivel' ); ?>
		</a>
		<?php endif; ?>
		<?php if ( $toc ) : ?>
		<a class="jimca-skip__link" href="#<?php echo esc_attr( $uid ); ?>-toc" data-jimca-skip-toc>
			<?php esc_html_e( 'Skip to the contents list', 'jim-conversor-acessivel' ); ?>
		</a>
		<?php endif; ?>
	</nav>

	<<?php echo esc_html( $title_tag ); ?> id="<?php echo esc_attr( $uid ); ?>-title" class="jimca-title"><?php echo esc_html( get_the_title( $post ) ); ?></<?php echo esc_html( $title_tag ); ?>>

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

		<div id="<?php echo esc_attr( $uid ); ?>-controls" tabindex="-1" class="jimca-player__bar<?php echo $pager ? ' jimca-player__bar--paged' : ''; ?>" role="toolbar" aria-label="<?php esc_attr_e( 'Reading controls', 'jim-conversor-acessivel' ); ?>" data-jimca-player-bar>

			<?php // Slides behind the button whose popover is open (frontend.js). Decoration only. ?>
			<span class="jimca-player__blob" data-jimca-blob aria-hidden="true"></span>

			<?php
			/*
			 * Order of the bar: navigation menu (search, contents), settings (voice
			 * and theme), text size, play/pause, speed, stop, parts (only in a
			 * document in parts) and hide. Popovers open upwards from their button.
			 */
			?>
			<div class="jimca-player__side jimca-player__side--start">
			<div class="jimca-player__slot">
				<button
					type="button"
					class="jimca-player__btn"
					data-jimca-menu-trigger="nav"
					aria-haspopup="true"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $uid ); ?>-menu-nav"
				>
					<?php echo JIMCA_Shortcode::get_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Navigation', 'jim-conversor-acessivel' ); ?></span>
				</button>

				<div class="jimca-player__menu jimca-player__menu--start" id="<?php echo esc_attr( $uid ); ?>-menu-nav" role="menu" aria-label="<?php esc_attr_e( 'Navigation', 'jim-conversor-acessivel' ); ?>" data-jimca-menu="nav" hidden>
					<button type="button" role="menuitem" class="jimca-player__menuitem" data-jimca-search-open>
						<?php echo JIMCA_Shortcode::get_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
						<?php esc_html_e( 'Search in the document', 'jim-conversor-acessivel' ); ?>
					</button>
					<?php if ( $toc ) : // Only when the document has headings. ?>
					<button type="button" role="menuitem" class="jimca-player__menuitem" data-jimca-toc-toggle aria-controls="<?php echo esc_attr( $uid ); ?>-toc">
						<?php echo JIMCA_Shortcode::get_icon( 'toc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
						<?php esc_html_e( 'Contents', 'jim-conversor-acessivel' ); ?>
					</button>
					<?php endif; ?>
				</div>
			</div>

			<div class="jimca-player__slot">
				<button
					type="button"
					class="jimca-player__btn"
					data-jimca-menu-trigger="settings"
					aria-haspopup="true"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $uid ); ?>-menu-settings"
				>
					<?php echo JIMCA_Shortcode::get_icon( 'settings' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Settings', 'jim-conversor-acessivel' ); ?></span>
				</button>

				<div class="jimca-player__menu jimca-player__menu--panel" id="<?php echo esc_attr( $uid ); ?>-menu-settings" role="group" aria-label="<?php esc_attr_e( 'Settings', 'jim-conversor-acessivel' ); ?>" data-jimca-menu="settings" hidden>
					<?php // The voice controls are filled by the JS: the voices are the browser's. ?>
					<fieldset class="jimca-player__fieldset" data-jimca-voice-group hidden>
						<legend><?php esc_html_e( 'Voice', 'jim-conversor-acessivel' ); ?></legend>
						<label for="<?php echo esc_attr( $uid ); ?>-voice-lang"><?php esc_html_e( 'Language', 'jim-conversor-acessivel' ); ?></label>
						<select id="<?php echo esc_attr( $uid ); ?>-voice-lang" data-jimca-voice-lang></select>
						<span data-jimca-voice-kind-wrap hidden>
							<label for="<?php echo esc_attr( $uid ); ?>-voice-kind"><?php esc_html_e( 'Type of voice', 'jim-conversor-acessivel' ); ?></label>
							<select id="<?php echo esc_attr( $uid ); ?>-voice-kind" data-jimca-voice-kind></select>
						</span>
						<label for="<?php echo esc_attr( $uid ); ?>-voice-name"><?php esc_html_e( 'Narrator', 'jim-conversor-acessivel' ); ?></label>
						<select id="<?php echo esc_attr( $uid ); ?>-voice-name" data-jimca-voice-name></select>
					</fieldset>

					<fieldset class="jimca-player__fieldset jimca-player__fieldset--themes">
						<legend><?php esc_html_e( 'Theme', 'jim-conversor-acessivel' ); ?></legend>
						<?php
						$jimca_themes                  = JIMCA_Admin::get_available_themes();
						$jimca_themes['high-contrast'] = __( 'High contrast', 'jim-conversor-acessivel' );
						foreach ( $jimca_themes as $jimca_theme_key => $jimca_theme_label ) :
							?>
							<label class="jimca-player__choice">
								<input type="radio" name="<?php echo esc_attr( $uid ); ?>-theme" value="<?php echo esc_attr( $jimca_theme_key ); ?>" data-jimca-theme<?php checked( $settings['theme'], $jimca_theme_key ); ?>>
								<span><?php echo esc_html( $jimca_theme_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</div>
			</div>

			<div class="jimca-player__slot">
				<button
					type="button"
					class="jimca-player__btn jimca-player__btn--text"
					data-jimca-menu-trigger="font"
					aria-haspopup="true"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $uid ); ?>-menu-font"
				>
					<span class="jimca-player__glyph" aria-hidden="true">Aa</span>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Text size', 'jim-conversor-acessivel' ); ?></span>
				</button>

				<div class="jimca-player__menu jimca-player__menu--row" id="<?php echo esc_attr( $uid ); ?>-menu-font" role="group" aria-label="<?php esc_attr_e( 'Text size', 'jim-conversor-acessivel' ); ?>" data-jimca-menu="font" hidden>
					<button type="button" class="jimca-player__step" data-jimca-font="decrease" aria-label="<?php esc_attr_e( 'Decrease text size', 'jim-conversor-acessivel' ); ?>"><span aria-hidden="true">A-</span></button>
					<button type="button" class="jimca-player__value" data-jimca-font="reset" data-jimca-font-value aria-label="<?php echo esc_attr( __( 'Default text size', 'jim-conversor-acessivel' ) . ' (100%)' ); ?>">100%</button>
					<button type="button" class="jimca-player__step jimca-player__step--big" data-jimca-font="increase" aria-label="<?php esc_attr_e( 'Increase text size', 'jim-conversor-acessivel' ); ?>"><span aria-hidden="true">A+</span></button>
				</div>
			</div>
			</div>

			<div class="jimca-player__slot">
				<?php
				/*
				 * Play and pause are one button (WCAG media control): the icon and the
				 * name swap together, so there is no aria-pressed on top of the name.
				 * Both icons are in the HTML and the JS only toggles which one shows,
				 * so no SVG is built in JavaScript and the right icon appears without
				 * a jump.
				 */
				?>
				<button type="button" class="jimca-player__btn jimca-player__btn--play" data-jimca-play>
					<span class="jimca-player__icon-slot" data-jimca-icon="play">
						<?php echo JIMCA_Shortcode::get_icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					</span>
					<span class="jimca-player__icon-slot" data-jimca-icon="pause" hidden>
						<?php echo JIMCA_Shortcode::get_icon( 'pause' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					</span>
					<span class="jimca-visually-hidden" data-jimca-play-label><?php esc_html_e( 'Listen', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>

			<div class="jimca-player__side jimca-player__side--end">
			<div class="jimca-player__slot">
				<?php // One click moves to the next speed (1x, 1.25x, 1.5x, 2x, back to 1x): no menu. ?>
				<button type="button" class="jimca-player__btn jimca-player__btn--speed" data-jimca-speed data-rate="<?php echo esc_attr( $jimca_current_rate ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: current reading speed, such as 1.5× */ __( 'Reading speed: %s', 'jim-conversor-acessivel' ), $jimca_rate_text ) ); ?>">
					<?php echo JIMCA_Shortcode::get_icon( 'speed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-player__rate" aria-hidden="true" data-jimca-speed-value><?php echo esc_html( $jimca_rate_text ); ?></span>
				</button>
			</div>

			<div class="jimca-player__slot">
				<button type="button" class="jimca-player__btn" data-jimca-stop disabled>
					<?php echo JIMCA_Shortcode::get_icon( 'stop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Stop', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>

			<?php if ( $pager ) : ?>
			<?php // Only a document in parts has this: previous, "2/93" (opens the list of parts) and next. ?>
			<div class="jimca-player__slot jimca-player__pager" role="group" aria-label="<?php esc_attr_e( 'Parts of the document', 'jim-conversor-acessivel' ); ?>" data-jimca-pager>
				<button type="button" class="jimca-player__btn jimca-player__btn--small<?php echo $pager['part'] > 1 ? '' : ' is-disabled'; ?>" data-jimca-pager-prev<?php echo $pager['part'] > 1 ? ' data-jimca-part-link="' . (int) ( $pager['part'] - 1 ) . '"' : ' aria-disabled="true"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integers and fixed text. ?>>
					<?php echo JIMCA_Shortcode::get_icon( 'previous' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Previous part', 'jim-conversor-acessivel' ); ?></span>
				</button>

				<button
					type="button"
					class="jimca-player__btn jimca-player__btn--count"
					data-jimca-menu-trigger="pager"
					aria-haspopup="true"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $uid ); ?>-menu-pager"
				>
					<span data-jimca-pager-count><?php echo esc_html( number_format_i18n( $pager['part'] ) . '/' . number_format_i18n( $pager['count'] ) ); ?></span>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Go to part', 'jim-conversor-acessivel' ); ?></span>
				</button>

				<button type="button" class="jimca-player__btn jimca-player__btn--small<?php echo $pager['part'] < $pager['count'] ? '' : ' is-disabled'; ?>" data-jimca-pager-next<?php echo $pager['part'] < $pager['count'] ? ' data-jimca-part-link="' . (int) ( $pager['part'] + 1 ) . '"' : ' aria-disabled="true"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integers and fixed text. ?>>
					<?php echo JIMCA_Shortcode::get_icon( 'next' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Next part', 'jim-conversor-acessivel' ); ?></span>
				</button>

				<div class="jimca-player__menu jimca-player__menu--panel" id="<?php echo esc_attr( $uid ); ?>-menu-pager" role="group" aria-label="<?php esc_attr_e( 'Go to part', 'jim-conversor-acessivel' ); ?>" data-jimca-menu="pager" hidden>
					<?php echo JIMCA_Shortcode::render_part_select( $pager, $uid . '-bar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr()/esc_html() in render_part_select(). ?>
				</div>

				<?php // Said aloud when the part changes (the visible "2/93" is not a live region). ?>
				<span class="jimca-visually-hidden" role="status" aria-live="polite" data-jimca-pager-status><?php echo esc_html( sprintf( /* translators: 1: number of the part being read, 2: number of parts */ __( 'Part %1$s of %2$s', 'jim-conversor-acessivel' ), number_format_i18n( $pager['part'] ), number_format_i18n( $pager['count'] ) ) ); ?></span>
			</div>
			<?php endif; ?>

			<div class="jimca-player__slot">
				<button type="button" class="jimca-player__btn" data-jimca-hide>
					<?php echo JIMCA_Shortcode::get_icon( 'hide' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Hide controls', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>
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
			<?php echo JIMCA_Shortcode::render_toc_list( $toc, $pager ? $pager['arg'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr()/esc_html() in render_toc_list(). ?>
		</nav>
	<?php endif; ?>

	<?php if ( $pager || 'reader' === $mode ) : ?>
		<?php
		/*
		 * Search in the document (pager.js shows it; it needs JavaScript). In a
		 * document in parts it searches the whole document at the server and
		 * opens the part of the result.
		 */
		?>
		<form class="jimca-search" role="search" aria-label="<?php esc_attr_e( 'Search in the document', 'jim-conversor-acessivel' ); ?>" data-jimca-search hidden>
			<div class="jimca-search__head">
				<label class="jimca-search__label" for="<?php echo esc_attr( $uid ); ?>-search"><?php esc_html_e( 'Search in the document', 'jim-conversor-acessivel' ); ?></label>
				<?php // With the reading bar the form is a panel over the text; this closes it (frontend.js). ?>
				<button type="button" class="jimca-search__close" data-jimca-search-close hidden>
					<?php echo JIMCA_Shortcode::get_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Close', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>
			<span class="jimca-search__row">
				<input type="search" class="jimca-search__input" id="<?php echo esc_attr( $uid ); ?>-search" autocomplete="off" spellcheck="false" data-jimca-search-input>
				<button type="submit" class="jimca-search__button"><?php esc_html_e( 'Search', 'jim-conversor-acessivel' ); ?></button>
			</span>
			<p class="jimca-search__status" role="status" aria-live="polite" data-jimca-search-status></p>
			<div class="jimca-search__results" data-jimca-search-results></div>
		</form>
	<?php endif; ?>

	<?php if ( $pager ) : ?>
		<?php echo JIMCA_Shortcode::render_pager( $pager, $uid, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr()/esc_html() in render_pager(). ?>
	<?php endif; ?>

	<div id="<?php echo esc_attr( $uid ); ?>-content" class="jimca-content" tabindex="-1" data-jimca-content<?php echo '' !== $doc_lang ? ' lang="' . esc_attr( $doc_lang ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute built with esc_attr(). ?>>
		<?php echo wp_kses_post( $content ); ?>
	</div>

	<?php if ( $pager ) : ?>
		<?php echo JIMCA_Shortcode::render_pager( $pager, $uid . '-b', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr()/esc_html() in render_pager(). ?>
	<?php endif; ?>

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
		<button type="button" class="jimca-marker__text jimca-marker__action" data-jimca-share-image>
			<?php echo JIMCA_Shortcode::get_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
			<?php esc_html_e( 'Share as image', 'jim-conversor-acessivel' ); ?>
		</button>
		<button type="button" class="jimca-marker__text jimca-marker__action" data-jimca-download-text>
			<?php echo JIMCA_Shortcode::get_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
			<?php esc_html_e( 'Download as text', 'jim-conversor-acessivel' ); ?>
		</button>
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
					<span class="jimca-tutor__orb jimca-tutor__orb--mini" aria-hidden="true"></span>
					<h2 class="jimca-tutor__title" id="<?php echo esc_attr( $uid ); ?>-tutor-title"><?php echo esc_html( $tutor['name'] ); ?></h2>
					<?php
					// The title attribute is the tooltip for mouse users; the hidden text names the button for everyone else.
					$jimca_tutor_actions = array(
						'export' => array( 'download', __( 'Download the conversation', 'jim-conversor-acessivel' ) ),
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
					<div class="jimca-tutor__quick" id="<?php echo esc_attr( $uid ); ?>-tutor-quick" role="menu" aria-label="<?php esc_attr_e( 'Quick texts', 'jim-conversor-acessivel' ); ?>" data-jimca-tutor-quick-menu hidden></div>
					<textarea class="jimca-tutor__input" id="<?php echo esc_attr( $uid ); ?>-tutor-question" rows="1" maxlength="<?php echo esc_attr( JIMCA_Tutor::MAX_QUESTION_CHARS ); ?>" placeholder="<?php esc_attr_e( 'Ask about the document', 'jim-conversor-acessivel' ); ?>" data-jimca-tutor-input></textarea>
					<div class="jimca-tutor__actions">
						<button type="button" class="jimca-tutor__icon-btn jimca-tutor__quick-btn" data-jimca-tutor-quick aria-haspopup="menu" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-tutor-quick" hidden>
							<?php echo JIMCA_Shortcode::get_icon( 'quick' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
							<span class="jimca-visually-hidden"><?php esc_html_e( 'Quick texts', 'jim-conversor-acessivel' ); ?></span>
						</button>
						<button type="button" class="jimca-tutor__icon-btn" data-jimca-tutor-mic aria-pressed="false" hidden>
							<?php echo JIMCA_Shortcode::get_icon( 'mic' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
							<span class="jimca-visually-hidden"><?php esc_html_e( 'Ask by voice', 'jim-conversor-acessivel' ); ?></span>
						</button>
						<button type="submit" class="jimca-tutor__icon-btn jimca-tutor__send">
							<?php echo JIMCA_Shortcode::get_icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literal from JIMCA_Shortcode::get_icon(). ?>
							<span class="jimca-visually-hidden"><?php esc_html_e( 'Send question', 'jim-conversor-acessivel' ); ?></span>
						</button>
					</div>
				</form>

				<p class="jimca-tutor__note">
					<?php esc_html_e( "Jim AI: may make mistakes. Don't type personal data. Your messages are processed by an external service.", 'jim-conversor-acessivel' ); ?>
				</p>
				<p class="jimca-visually-hidden" role="status" aria-live="polite" data-jimca-tutor-status></p>
			</section>
		</div>
	<?php endif; ?>
</section>
