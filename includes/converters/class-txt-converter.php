<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JIMCA_Txt_Converter implements JIMCA_Converter_Interface {

	public function convert( $file_path ) {
		$contents = file_get_contents( $file_path );

		if ( false === $contents ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'Could not read the TXT file.', 'jim-conversor-acessivel' ) );
		}

		// Plain-text files are often saved as Windows-1252 rather than UTF-8.
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
			throw new JIMCA_Converter_Exception( esc_html__( 'The TXT file is empty.', 'jim-conversor-acessivel' ) );
		}

		return $html;
	}
}
