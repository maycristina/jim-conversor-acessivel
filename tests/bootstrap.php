<?php
/**
 * Testes unitários: sem WordPress carregado. As funções do WordPress que o
 * código chama são simuladas em cada teste com o Brain Monkey.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WEEK_IN_SECONDS', 604800 );
