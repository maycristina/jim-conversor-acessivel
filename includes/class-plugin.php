<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe principal: orquestra o carregamento dos demais componentes.
 */
class JIMCA_Plugin {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		// Since WordPress 4.6, translations for plugins hosted on WordPress.org
		// are loaded automatically; load_plugin_textdomain() is not needed here.
		JIMCA_Post_Type::get_instance()->init();
		JIMCA_Shortcode::get_instance()->init();
		JIMCA_Install_Badge::get_instance()->init();

		if ( is_admin() ) {
			JIMCA_Admin::get_instance()->init();
		}
	}
}
