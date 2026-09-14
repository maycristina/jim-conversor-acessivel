<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PhpOffice\PhpWord\IOFactory;

class JIMCA_Word_Converter implements JIMCA_Converter_Interface {

	public function convert( $file_path ) {
		if ( ! class_exists( IOFactory::class ) ) {
			throw new JIMCA_Converter_Exception(
				esc_html__( 'Biblioteca phpoffice/phpword não encontrada. Rode "composer install" na pasta do plugin.', 'jim-conversor-acessivel' )
			);
		}

		try {
			$phpWord = IOFactory::load( $file_path, 'Word2007' );
			$writer  = IOFactory::createWriter( $phpWord, 'HTML' );
		} catch ( \Exception $e ) {
			throw new JIMCA_Converter_Exception(
				sprintf(
					/* translators: %s: mensagem de erro original */
					esc_html__( 'Não foi possível ler o documento Word: %s', 'jim-conversor-acessivel' ),
					esc_html( $e->getMessage() )
				)
			);
		}

		$tmp_html = wp_tempnam( 'jimca-word-' );

		try {
			$writer->save( $tmp_html );
			$full_html = file_get_contents( $tmp_html );
		} finally {
			if ( file_exists( $tmp_html ) ) {
				wp_delete_file( $tmp_html );
			}
		}

		if ( false === $full_html || '' === trim( (string) $full_html ) ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'O documento Word parece estar vazio.', 'jim-conversor-acessivel' ) );
		}

		return $this->extract_body( $full_html );
	}

	/**
	 * PhpWord gera um documento HTML completo (<html><head>...<body>...).
	 * Aqui extraímos só o conteúdo do <body> para inserir no post_content.
	 */
	private function extract_body( $full_html ) {
		$dom = new \DOMDocument();

		$previous_setting = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $full_html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		if ( null === $body ) {
			return wp_strip_all_tags( $full_html );
		}

		$inner_html = '';
		foreach ( $body->childNodes as $child ) {
			$inner_html .= $dom->saveHTML( $child );
		}

		return $inner_html;
	}
}
