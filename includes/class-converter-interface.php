<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface JIMCA_Converter_Interface {

	/**
	 * @param string $file_path Absolute path of the uploaded file.
	 * @return string Body HTML (no <html>/<body>), to be passed through wp_kses_post().
	 *
	 * @throws JIMCA_Converter_Exception When the file cannot be read or converted.
	 */
	public function convert( $file_path );
}
