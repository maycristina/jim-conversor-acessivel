<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [documento_acessivel id="123"] — exibe a página responsiva e
 * acessível (WCAG 2.1 AA) com leitor de voz via Web Speech API.
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
				'id' => 0,
			),
			$atts,
			self::TAG
		);

		$post_id = absint( $atts['id'] );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || JIMCA_Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p>' . esc_html__( 'Jim - Conversor Acessível: documento não encontrado.', 'jim-conversor-acessivel' ) . '</p>';
			}
			return '';
		}

		$this->enqueue_assets();

		$settings = JIMCA_Admin::get_settings();
		// Não usamos apply_filters( 'the_content', ... ) de propósito: isso rodaria
		// do_shortcode() sobre texto extraído de um arquivo enviado, permitindo que
		// um documento com algo como "[algum-shortcode]" execute shortcodes do site
		// sem intenção. O HTML já vem pronto (com <p>) dos conversores e sanitizado
		// com wp_kses_post() no momento da conversão.
		$content    = $post->post_content;
		$word_count = (int) get_post_meta( $post->ID, '_jimca_word_count', true );
		$uid        = 'jimca-' . $post->ID . '-' . wp_unique_id();

		ob_start();
		include JIMCA_PLUGIN_DIR . 'templates/document-viewer.php';
		return ob_get_clean();
	}

	/**
	 * Ícones do player, em SVG inline.
	 *
	 * Inline (e não um arquivo de fonte ou sprite) por três motivos: não
	 * custa uma requisição a mais, herda a cor do tema de leitura via
	 * `currentColor`, e continua visível no modo de alto contraste do
	 * sistema operacional — ícones em imagem somem nesse modo.
	 *
	 * Todos os ícones são desenhados na grade de 24x24 do Material Design.
	 * As strings são literais fixas, sem nenhuma parte dinâmica.
	 *
	 * @param string $name Nome do ícone.
	 * @return string Markup SVG, ou string vazia se o nome não existir.
	 */
	public static function get_icon( $name ) {
		$open  = '<svg class="jimca-player__icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
		$close = '</svg>';

		$icons = array(
			// Letra "A" — tamanho do texto.
			'font'     => '<path d="M12 4 5.5 20"/><path d="m12 4 6.5 16"/><path d="M8 15h8"/>',

			// Círculo meio preenchido — alto contraste.
			'contrast' => '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18Z" fill="currentColor" stroke="none"/>',

			// Paleta — tema de leitura.
			'theme'    => '<path d="M12 3a9 9 0 0 0 0 18c.8 0 1.5-.7 1.5-1.5 0-.4-.2-.8-.4-1-.3-.3-.4-.6-.4-1 0-.8.7-1.5 1.5-1.5H16a5 5 0 0 0 5-5c0-4.4-4-8-9-8Z"/><circle cx="7.5" cy="12" r="1.1" fill="currentColor" stroke="none"/><circle cx="9.8" cy="7.8" r="1.1" fill="currentColor" stroke="none"/><circle cx="14.6" cy="7.8" r="1.1" fill="currentColor" stroke="none"/><circle cx="17.5" cy="11" r="1.1" fill="currentColor" stroke="none"/>',

			// Pessoa com ondas sonoras — escolha de voz.
			'voice'    => '<circle cx="8.5" cy="7.5" r="3"/><path d="M2.5 20a6 6 0 0 1 12 0"/><path d="M17.5 9.2a4 4 0 0 1 0 5.6"/><path d="M20 6.5a8 8 0 0 1 0 11"/>',

			// Triângulo — iniciar leitura.
			'play'     => '<path d="M8 5.5v13l10-6.5Z" fill="currentColor"/>',

			// Duas barras — pausar.
			'pause'    => '<path d="M8 5v14" stroke-width="3.2"/><path d="M16 5v14" stroke-width="3.2"/>',

			// Dois triângulos — velocidade da leitura.
			'speed'    => '<path d="M3.5 6.5v11l7-5.5Z" fill="currentColor"/><path d="M13 6.5v11l7-5.5Z" fill="currentColor"/>',

			// Quadrado dentro de um círculo — parar.
			'stop'     => '<circle cx="12" cy="12" r="9" fill="currentColor" stroke="none"/><rect x="9" y="9" width="6" height="6" rx="1.2" fill="var(--jimca-player-surface)" stroke="none"/>',

			// Olho cortado — ocultar os controles.
			'hide'     => '<path d="M2.5 12S6 6.5 12 6.5c1.4 0 2.7.3 3.8.8"/><path d="M19.2 9.2c1.4 1.3 2.3 2.8 2.3 2.8S18 17.5 12 17.5c-1.6 0-3-.4-4.2-1"/><circle cx="12" cy="12" r="2.6"/><path d="m4 4 16 16"/>',

			// Olho — mostrar os controles de novo.
			'show'     => '<path d="M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.6"/>',
		);

		if ( ! isset( $icons[ $name ] ) ) {
			return '';
		}

		return $open . $icons[ $name ] . $close;
	}

	private function enqueue_assets() {
		if ( $this->rendered_on_page ) {
			return;
		}
		$this->rendered_on_page = true;

		wp_enqueue_style( 'jimca-frontend', JIMCA_PLUGIN_URL . 'assets/css/frontend.css', array(), JIMCA_VERSION );
		wp_enqueue_script( 'jimca-frontend', JIMCA_PLUGIN_URL . 'assets/js/frontend.js', array(), JIMCA_VERSION, true );

		wp_localize_script(
			'jimca-frontend',
			'jimcaFrontendI18n',
			array(
				'play'          => __( 'Ouvir', 'jim-conversor-acessivel' ),
				'pause'         => __( 'Pausar', 'jim-conversor-acessivel' ),
				'resume'        => __( 'Continuar', 'jim-conversor-acessivel' ),
				'stop'          => __( 'Parar', 'jim-conversor-acessivel' ),
				'statusReading' => __( 'Lendo em voz alta.', 'jim-conversor-acessivel' ),
				'statusPaused'  => __( 'Leitura pausada.', 'jim-conversor-acessivel' ),
				'statusStopped' => __( 'Leitura interrompida.', 'jim-conversor-acessivel' ),
				'statusDone'    => __( 'Leitura concluída.', 'jim-conversor-acessivel' ),
				'unsupported'   => __( 'Este navegador não tem suporte à leitura em voz alta.', 'jim-conversor-acessivel' ),
				'increaseFont'  => __( 'Aumentar tamanho do texto', 'jim-conversor-acessivel' ),
				'decreaseFont'  => __( 'Diminuir tamanho do texto', 'jim-conversor-acessivel' ),
				'resetFont'     => __( 'Tamanho de texto padrão', 'jim-conversor-acessivel' ),
				'contrastOn'    => __( 'Ativar alto contraste', 'jim-conversor-acessivel' ),
				'contrastOff'   => __( 'Desativar alto contraste', 'jim-conversor-acessivel' ),
				'voiceLabel'    => __( 'Voz', 'jim-conversor-acessivel' ),
				'rateLabel'     => __( 'Velocidade da leitura', 'jim-conversor-acessivel' ),
				'controlsHide'  => __( 'Ocultar controles', 'jim-conversor-acessivel' ),
				'controlsShow'  => __( 'Mostrar controles', 'jim-conversor-acessivel' ),
				'menuClose'     => __( 'Fechar menu', 'jim-conversor-acessivel' ),
			)
		);
	}
}
