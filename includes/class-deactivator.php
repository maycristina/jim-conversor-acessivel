<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JIMCA_Deactivator {

	public static function deactivate() {
		flush_rewrite_rules();
	}
}
