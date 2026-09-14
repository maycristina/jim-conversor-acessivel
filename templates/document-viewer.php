<?php
/**
 * Template do visualizador de documento acessível.
 *
 * Variáveis disponíveis (definidas em JIMCA_Shortcode::render):
 *
 * @var WP_Post $post
 * @var string  $content
 * @var int     $word_count
 * @var string  $uid
 * @var array   $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Velocidades oferecidas no menu de velocidade. A Web Speech API aceita de
 * 0.1 a 10, mas abaixo de 0.5 e acima de 2 a maioria das vozes fica
 * ininteligível — que é justamente o contrário do objetivo aqui.
 */
$jimca_rates = array( '0.5', '0.75', '1', '1.25', '1.5', '2' );

$jimca_current_rate = (string) ( isset( $settings['tts_default_rate'] ) ? $settings['tts_default_rate'] : 1 );
?>
<section class="jimca-viewer jimca-theme-<?php echo esc_attr( $settings['theme'] ); ?>" id="<?php echo esc_attr( $uid ); ?>" data-jimca-viewer aria-labelledby="<?php echo esc_attr( $uid ); ?>-title">

	<a class="jimca-skip-link jimca-visually-hidden" href="#<?php echo esc_attr( $uid ); ?>-content">
		<?php esc_html_e( 'Pular para o conteúdo do documento', 'jim-conversor-acessivel' ); ?>
	</a>

	<h2 id="<?php echo esc_attr( $uid ); ?>-title" class="jimca-title"><?php echo esc_html( get_the_title( $post ) ); ?></h2>

	<p class="jimca-meta">
		<?php
		printf(
			/* translators: %s: número aproximado de palavras */
			esc_html__( 'Aproximadamente %s palavras.', 'jim-conversor-acessivel' ),
			esc_html( number_format_i18n( $word_count ) )
		);
		?>
	</p>

	<?php
	/*
	 * Player de leitura.
	 *
	 * Fica cedo no HTML de propósito: visualmente ele flutua no rodapé da
	 * tela (CSS), mas quem navega por teclado ou leitor de tela chega nele
	 * logo depois do título, e não depois de atravessar um livro inteiro.
	 *
	 * Começa oculto e só é revelado pelo JS: sem JS os controles não fazem
	 * nada, e uma barra flutuante inerte só cobriria o texto.
	 */
	?>
	<div class="jimca-player" data-jimca-player hidden>

		<div class="jimca-player__bar" role="toolbar" aria-label="<?php esc_attr_e( 'Controles de leitura', 'jim-conversor-acessivel' ); ?>" data-jimca-player-bar>

			<?php // 1. Tamanho do texto. ?>
			<div class="jimca-player__slot">
				<button
					type="button"
					class="jimca-player__btn"
					data-jimca-menu-trigger="font"
					aria-haspopup="true"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $uid ); ?>-menu-font"
				>
					<?php echo JIMCA_Shortcode::get_icon( 'font' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literal fixo definido em JIMCA_Shortcode::get_icon(), sem nenhuma parte dinâmica. ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Tamanho do texto e tema', 'jim-conversor-acessivel' ); ?></span>
				</button>

				<div class="jimca-player__menu" id="<?php echo esc_attr( $uid ); ?>-menu-font" role="menu" aria-label="<?php esc_attr_e( 'Aparência do texto', 'jim-conversor-acessivel' ); ?>" data-jimca-menu="font" hidden>
					<div role="group" aria-label="<?php esc_attr_e( 'Tamanho do texto', 'jim-conversor-acessivel' ); ?>">
						<button type="button" role="menuitem" class="jimca-player__menuitem" data-jimca-font="increase" aria-label="<?php esc_attr_e( 'A+ — aumentar tamanho do texto', 'jim-conversor-acessivel' ); ?>">A+</button>
						<button type="button" role="menuitem" class="jimca-player__menuitem" data-jimca-font="decrease" aria-label="<?php esc_attr_e( 'A- — diminuir tamanho do texto', 'jim-conversor-acessivel' ); ?>">A-</button>
						<button type="button" role="menuitem" class="jimca-player__menuitem" data-jimca-font="reset" aria-label="<?php esc_attr_e( 'A — tamanho de texto padrão', 'jim-conversor-acessivel' ); ?>">A</button>
					</div>

					<hr class="jimca-player__divider">

					<div role="group" aria-label="<?php esc_attr_e( 'Tema de leitura', 'jim-conversor-acessivel' ); ?>">
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

			<?php // 2. Alto contraste. ?>
			<div class="jimca-player__slot">
				<button type="button" class="jimca-player__btn" data-jimca-contrast-toggle aria-pressed="false">
					<?php echo JIMCA_Shortcode::get_icon( 'contrast' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literal fixo, ver acima. ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Alto contraste', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>

			<?php // 3. Voz. ?>
			<div class="jimca-player__slot">
				<button
					type="button"
					class="jimca-player__btn"
					data-jimca-menu-trigger="voice"
					aria-haspopup="true"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $uid ); ?>-menu-voice"
				>
					<?php echo JIMCA_Shortcode::get_icon( 'voice' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literal fixo, ver acima. ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Voz', 'jim-conversor-acessivel' ); ?></span>
				</button>

				<?php // Preenchido pelo JS: as vozes disponíveis são as do navegador. ?>
				<div class="jimca-player__menu" id="<?php echo esc_attr( $uid ); ?>-menu-voice" role="menu" aria-label="<?php esc_attr_e( 'Voz', 'jim-conversor-acessivel' ); ?>" data-jimca-menu="voice" hidden></div>
			</div>

			<?php // 4. Ouvir / Pausar. ?>
			<div class="jimca-player__slot">
				<button type="button" class="jimca-player__btn jimca-player__btn--play" data-jimca-play aria-pressed="false">
					<?php
					/*
					 * Os dois ícones já vêm no HTML e o JS apenas alterna qual
					 * está visível — assim ele não precisa montar SVG em
					 * JavaScript, e o ícone certo aparece sem nenhum salto.
					 */
					?>
					<span class="jimca-player__icon-slot" data-jimca-icon="play">
						<?php echo JIMCA_Shortcode::get_icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literal fixo, ver acima. ?>
					</span>
					<span class="jimca-player__icon-slot" data-jimca-icon="pause" hidden>
						<?php echo JIMCA_Shortcode::get_icon( 'pause' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literal fixo, ver acima. ?>
					</span>
					<span class="jimca-visually-hidden" data-jimca-play-label><?php esc_html_e( 'Ouvir', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>

			<?php // 5. Velocidade. ?>
			<div class="jimca-player__slot">
				<button
					type="button"
					class="jimca-player__btn"
					data-jimca-menu-trigger="speed"
					aria-haspopup="true"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $uid ); ?>-menu-speed"
				>
					<?php echo JIMCA_Shortcode::get_icon( 'speed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literal fixo, ver acima. ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Velocidade da leitura', 'jim-conversor-acessivel' ); ?></span>
				</button>

				<div class="jimca-player__menu" id="<?php echo esc_attr( $uid ); ?>-menu-speed" role="menu" aria-label="<?php esc_attr_e( 'Velocidade da leitura', 'jim-conversor-acessivel' ); ?>" data-jimca-menu="speed" hidden>
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

			<?php // 6. Parar. ?>
			<div class="jimca-player__slot">
				<button type="button" class="jimca-player__btn" data-jimca-stop disabled>
					<?php echo JIMCA_Shortcode::get_icon( 'stop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literal fixo, ver acima. ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Parar', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>

			<?php // 7. Ocultar os controles. ?>
			<div class="jimca-player__slot">
				<button type="button" class="jimca-player__btn" data-jimca-hide>
					<?php echo JIMCA_Shortcode::get_icon( 'hide' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literal fixo, ver acima. ?>
					<span class="jimca-visually-hidden"><?php esc_html_e( 'Ocultar controles', 'jim-conversor-acessivel' ); ?></span>
				</button>
			</div>
		</div>

		<?php // Volta a barra depois de oculta. Substitui a barra inteira, no mesmo canto. ?>
		<button type="button" class="jimca-player__restore" data-jimca-show hidden>
			<?php echo JIMCA_Shortcode::get_icon( 'show' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literal fixo, ver acima. ?>
			<span class="jimca-visually-hidden"><?php esc_html_e( 'Mostrar controles de leitura', 'jim-conversor-acessivel' ); ?></span>
		</button>

		<p class="jimca-status jimca-visually-hidden" role="status" aria-live="polite" data-jimca-status></p>
	</div>

	<div id="<?php echo esc_attr( $uid ); ?>-content" class="jimca-content" tabindex="-1" data-jimca-content>
		<?php echo wp_kses_post( $content ); ?>
	</div>
</section>
