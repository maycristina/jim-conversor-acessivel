<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface JIMCA_Converter_Interface {

	/**
	 * Converte o arquivo em $file_path e devolve HTML (apenas o miolo, sem <html>/<body>).
	 *
	 * @param string $file_path Caminho absoluto do arquivo temporário enviado.
	 * @return string HTML já pronto para wp_kses_post().
	 *
	 * @throws JIMCA_Converter_Exception Quando o arquivo não pode ser lido/convertido.
	 */
	public function convert( $file_path );
}
