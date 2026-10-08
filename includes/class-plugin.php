<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin components and loads the bundled translations.
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

	const TEXT_DOMAIN = 'jim-conversor-acessivel';

	/**
	 * Languages that ship in /languages: locale code of the file => name.
	 * Names are written in each language itself, so they need no translation.
	 *
	 * @return array<string, string>
	 */
	public static function bundled_languages_map() {
		return array(
			'pt_BR' => 'Português (Brasil)',
			'es_ES' => 'Español',
			'fr_FR' => 'Français',
			'zh_CN' => '简体中文',
			'hi_IN' => 'हिन्दी',
			'ru_RU' => 'Русский',
			'de_DE' => 'Deutsch',
		);
	}

	/** @return string[] Names of all languages, English first. */
	public static function bundled_languages() {
		return array_merge( array( 'English' ), array_values( self::bundled_languages_map() ) );
	}

	/**
	 * Which bundled file serves a site/user locale, or '' for English.
	 * Regional variants share a file (es_MX -> es_ES, pt_PT -> pt_BR, de_AT -> de_DE).
	 * Chinese is matched exactly: the bundled text is Simplified, so zh_TW/zh_HK get English.
	 *
	 * @param string $locale WordPress locale, e.g. "es_MX".
	 * @return string
	 */
	public static function bundled_file_locale( $locale ) {
		$map = self::bundled_languages_map();

		if ( isset( $map[ $locale ] ) ) {
			return $locale;
		}

		foreach ( array( 'pt' => 'pt_BR', 'es' => 'es_ES', 'fr' => 'fr_FR', 'hi' => 'hi_IN', 'ru' => 'ru_RU', 'de' => 'de_DE' ) as $prefix => $file_locale ) {
			if ( 0 === strpos( $locale, $prefix . '_' ) ) {
				return $file_locale;
			}
		}

		return 'zh_SG' === $locale ? 'zh_CN' : '';
	}

	/**
	 * Source strings are in English. The translations in /languages are
	 * loaded only when WordPress.org has no official translation installed
	 * for the language; official ones take precedence.
	 */
	public function load_bundled_translations() {
		$locale      = determine_locale();
		$file_locale = self::bundled_file_locale( $locale );

		if ( '' === $file_locale ) {
			return;
		}

		if ( file_exists( WP_LANG_DIR . '/plugins/' . self::TEXT_DOMAIN . '-' . $locale . '.mo' ) ) {
			return;
		}

		load_textdomain( self::TEXT_DOMAIN, JIMCA_PLUGIN_DIR . 'languages/' . self::TEXT_DOMAIN . '-' . $file_locale . '.mo', $locale );
	}

	public function init() {
		add_action( 'init', array( $this, 'load_bundled_translations' ), 0 );

		JIMCA_Post_Type::get_instance()->init();
		JIMCA_Shortcode::get_instance()->init();
		JIMCA_Install_Badge::get_instance()->init();
		JIMCA_SEO::get_instance()->init();
		JIMCA_Tutor::register_hooks();

		if ( is_admin() ) {
			JIMCA_Admin::get_instance()->init();
		}
	}
}
