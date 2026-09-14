<?php
/**
 * Plugin Name:       Jim - Conversor Acessível
 * Plugin URI:         https://maycristina.github.io/jim-conversor-acessivel/
 * Description:       Converts PDF, Word and TXT files into responsive, accessible pages (with text-to-speech) and lets you publish them via shortcode.
 * Version:            1.0.0
 * Requires at least:  6.0
 * Requires PHP:       7.4
 * Author:             Mayara Nascimento
 * Author URI:         https://github.com/maycristina
 * License:            GPL v2 or later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        jim-conversor-acessivel
 * Domain Path:        /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Acesso direto não permitido.
}

define( 'JIMCA_VERSION', '1.0.0' );
define( 'JIMCA_PLUGIN_FILE', __FILE__ );
define( 'JIMCA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JIMCA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JIMCA_PLUGIN_SLUG', 'jim-conversor-acessivel' );

/**
 * Endereços públicos do projeto, usados nos links da tela de Plugins e na
 * página de Tutorial. Ficam em constantes para existir um único lugar a
 * mudar quando algum deles mudar.
 */
define( 'JIMCA_REPO_URL', 'https://github.com/maycristina/jim-conversor-acessivel' );
define( 'JIMCA_SITE_URL', 'https://maycristina.github.io/jim-conversor-acessivel/' );
define( 'JIMCA_AUTHOR_URL', 'https://github.com/maycristina' );

/**
 * Autoload das dependências do Composer (smalot/pdfparser, phpoffice/phpword).
 * Necessário rodar `composer install` na pasta do plugin antes de usar.
 */
$jimca_autoload = JIMCA_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $jimca_autoload ) ) {
	require_once $jimca_autoload;
}

/**
 * Autoload simples das classes internas do plugin (prefixo JIMCA_).
 */
spl_autoload_register(
	function ( $class_name ) {
		if ( strpos( $class_name, 'JIMCA_' ) !== 0 ) {
			return;
		}

		$relative = strtolower( str_replace( '_', '-', substr( $class_name, 6 ) ) );
		$paths    = array(
			JIMCA_PLUGIN_DIR . 'includes/class-' . $relative . '.php',
			JIMCA_PLUGIN_DIR . 'includes/converters/class-' . $relative . '.php',
			JIMCA_PLUGIN_DIR . 'admin/class-' . $relative . '.php',
		);

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

register_activation_hook( JIMCA_PLUGIN_FILE, array( 'JIMCA_Activator', 'activate' ) );
register_deactivation_hook( JIMCA_PLUGIN_FILE, array( 'JIMCA_Deactivator', 'deactivate' ) );

/**
 * Inicializa o plugin depois que todos os plugins foram carregados.
 */
function jimca_run_plugin() {
	if ( ! file_exists( JIMCA_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'Jim - Conversor Acessível: as dependências do Composer não foram instaladas. Rode "composer install" na pasta do plugin.', 'jim-conversor-acessivel' );
				echo '</p></div>';
			}
		);
	}

	JIMCA_Plugin::get_instance()->init();
}
add_action( 'plugins_loaded', 'jimca_run_plugin' );
