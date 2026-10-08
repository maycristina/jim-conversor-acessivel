<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [jimca_instalacoes]: the plugin's active install count, from the
 * WordPress.org API (https://wordpress.org/plugins/jim-conversor-acessivel/).
 * If the API does not answer, the shortcode shows nothing (administrators see
 * a note).
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
				'formato' => 'texto', // "texto" or "numero". No image badge: loading one from a third party would send the visitor's IP there.
			),
			$atts,
			self::TAG
		);

		$slug = JIMCA_Admin::get_settings()['wporg_slug'];
		$data = $this->get_active_installs( $slug );

		if ( null === $data ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="jimca-installs-notice">' . esc_html__( 'Install counter: this plugin has not been published on WordPress.org yet (visible to administrators only).', 'jim-conversor-acessivel' ) . '</p>';
			}
			return '';
		}

		if ( 'numero' === $atts['formato'] ) {
			return esc_html( number_format_i18n( $data ) );
		}

		return '<span class="jimca-installs-count">' . sprintf(
			/* translators: %s: number of active installs, formatted */
			esc_html__( '%s active installs', 'jim-conversor-acessivel' ),
			esc_html( number_format_i18n( $data ) )
		) . '</span>';
	}

	/**
	 * @param string $slug
	 * @return int|null Active installs, or null when unavailable or not published.
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
