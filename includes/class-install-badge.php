<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [jimca_instalacoes] — mostra o número de instalações ativas
 * reportado pela API oficial do WordPress.org para este plugin.
 *
 * Só retorna dados reais depois que o plugin for publicado no diretório
 * do WordPress.org (https://wordpress.org/plugins/). Antes disso a API
 * não encontra o slug e o shortcode não exibe nada no site (mas mostra
 * um aviso para administradores logados).
 */
class JIMCA_Install_Badge {

	const TAG            = 'jimca_instalacoes';
	const TRANSIENT_TTL  = 12 * HOUR_IN_SECONDS;

	private static $instance = null;

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
				'formato' => 'texto', // texto|numero|badge
			),
			$atts,
			self::TAG
		);

		$slug = JIMCA_Admin::get_settings()['wporg_slug'];
		$data = $this->get_active_installs( $slug );

		if ( 'badge' === $atts['formato'] ) {
			return $this->render_badge_image( $slug );
		}

		if ( null === $data ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="jimca-installs-notice">' . esc_html__( 'Contador de instalações: este plugin ainda não foi publicado no WordPress.org (visível só para administradores).', 'jim-conversor-acessivel' ) . '</p>';
			}
			return '';
		}

		if ( 'numero' === $atts['formato'] ) {
			return esc_html( number_format_i18n( $data ) );
		}

		return '<span class="jimca-installs-count">' . sprintf(
			/* translators: %s: número de instalações ativas, formatado */
			esc_html__( '%s instalações ativas', 'jim-conversor-acessivel' ),
			esc_html( number_format_i18n( $data ) )
		) . '</span>';
	}

	private function render_badge_image( $slug ) {
		$url = 'https://img.shields.io/wordpress/plugin/installs/' . rawurlencode( $slug ) . '.svg';
		return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'Número de instalações ativas no WordPress.org', 'jim-conversor-acessivel' ) . '" loading="lazy">';
	}

	/**
	 * @param string $slug
	 * @return int|null Número de instalações ativas, ou null se indisponível/não publicado.
	 */
	public function get_active_installs( $slug ) {
		$cache_key = 'jimca_active_installs_' . $slug;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return ( -1 === $cached ) ? null : (int) $cached;
		}

		$url = add_query_arg(
			array(
				'action'  => 'plugin_information',
				'request' => array(
					'slug'   => $slug,
					'fields' => array( 'active_installs' => true ),
				),
			),
			'https://api.wordpress.org/plugins/info/1.2/'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 8 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, -1, self::TRANSIENT_TTL );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body ) || ! isset( $body['active_installs'] ) ) {
			set_transient( $cache_key, -1, self::TRANSIENT_TTL );
			return null;
		}

		$installs = (int) $body['active_installs'];
		set_transient( $cache_key, $installs, self::TRANSIENT_TTL );

		return $installs;
	}
}
