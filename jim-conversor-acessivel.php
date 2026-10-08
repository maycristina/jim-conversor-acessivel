<?php
/**
 * Plugin Name:       Jim - Accessible Converter
 * Plugin URI:         https://jim.mabo.cc/
 * Description:       Converts PDF, Word, Markdown and TXT files into responsive, accessible pages (with text-to-speech) and lets you publish them via shortcode.
 * Version:            2.0.0
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
	exit;
}

define( 'JIMCA_VERSION', '2.0.0' );
define( 'JIMCA_PLUGIN_FILE', __FILE__ );
define( 'JIMCA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JIMCA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JIMCA_PLUGIN_SLUG', 'jim-conversor-acessivel' );

/**
 * The "?ver=" of a plugin CSS/JS file: plugin version plus the file's
 * modification time, so a changed file reaches browsers and server/CDN
 * caches without bumping the plugin version.
 *
 * @param string $relative Path relative to the plugin folder, e.g. "assets/css/frontend.css".
 * @return string
 */
function jimca_asset_version( $relative ) {
	$mtime = @filemtime( JIMCA_PLUGIN_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file just falls back to the plugin version.

	return $mtime ? JIMCA_VERSION . '.' . $mtime : JIMCA_VERSION;
}

/**
 * Public project addresses, used on the Plugins screen and the Tutorial page.
 */
define( 'JIMCA_REPO_URL', 'https://github.com/maycristina/jim-conversor-acessivel' );
define( 'JIMCA_SITE_URL', 'https://jim.mabo.cc/' );
define( 'JIMCA_AUTHOR_URL', 'https://github.com/maycristina' );

/**
 * Composer dependencies (smalot/pdfparser, phpoffice/phpword). The release
 * zip ships vendor/; from the repository, run `composer install --no-dev`.
 */
$jimca_autoload = JIMCA_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $jimca_autoload ) ) {
	require_once $jimca_autoload;
}

/**
 * Plugin classes: JIMCA_Foo_Bar lives in class-foo-bar.php under includes/,
 * includes/converters/ or admin/.
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

function jimca_run_plugin() {
	if ( ! file_exists( JIMCA_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
		add_action(
			'admin_notices',
			function () {
				// Only for whoever can fix it (reinstall the plugin), and only on the Plugins screen and Jim's own screens.
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				$screen = get_current_screen();
				if ( ! $screen || ( 'plugins' !== $screen->id && false === strpos( $screen->id, 'jimca' ) ) ) {
					return;
				}
				echo '<div class="notice notice-error is-dismissible"><p>';
				echo esc_html__( 'Jim - Accessible Converter: the vendor/ folder (PDF and Word libraries) is missing from the plugin. Reinstall the plugin from the official package; if you use the repository code, run "composer install --no-dev" in the plugin folder.', 'jim-conversor-acessivel' );
				echo '</p></div>';
			}
		);
	}

	JIMCA_Plugin::get_instance()->init();
}
add_action( 'plugins_loaded', 'jimca_run_plugin' );
