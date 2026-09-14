<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JIMCA_Txt_Converter implements JIMCA_Converter_Interface {

	public function convert( $file_path ) {
		$contents = file_get_contents( $file_path );

		if ( false === $contents ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'Não foi possível ler o arquivo TXT.', 'jim-conversor-acessivel' ) );
		}

		// Garante UTF-8 (arquivos .txt são comuns em Latin-1/Windows-1252).
		if ( ! mb_check_encoding( $contents, 'UTF-8' ) ) {
			$contents = mb_convert_encoding( $contents, 'UTF-8', 'Windows-1252' );
		}

		$contents   = str_replace( array( "\r\n", "\r" ), "\n", $contents );
		$paragraphs = preg_split( '/\n\s*\n/', trim( $contents ) );

		$html = '';
		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}
			$html .= '<p>' . nl2br( esc_html( $paragraph ) ) . '</p>' . "\n";
		}

		if ( '' === $html ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'O arquivo TXT está vazio.', 'jim-conversor-acessivel' ) );
		}

		return $html;
	}
}
