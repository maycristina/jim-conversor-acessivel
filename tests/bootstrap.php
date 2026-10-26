<?php
/**
 * Testes unitários: sem WordPress carregado. As funções do WordPress que o
 * código chama são simuladas em cada teste com o Brain Monkey.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WEEK_IN_SECONDS', 604800 );

// The plugin's autoloader (jim-conversor-acessivel.php): JIMCA_Foo_Bar is class-foo-bar.php.
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'JIMCA_' ) ) {
			return;
		}

		$relative = strtolower( str_replace( '_', '-', substr( $class_name, 6 ) ) );

		foreach ( array( 'includes/', 'includes/converters/', 'admin/' ) as $dir ) {
			$path = ABSPATH . $dir . 'class-' . $relative . '.php';
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);
