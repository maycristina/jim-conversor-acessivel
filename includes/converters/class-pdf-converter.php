<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Smalot\PdfParser\Parser as PdfParser;

class JIMCA_Pdf_Converter implements JIMCA_Converter_Interface {

	public function convert( $file_path ) {
		if ( ! class_exists( PdfParser::class ) ) {
			throw new JIMCA_Converter_Exception(
				esc_html__( 'Biblioteca smalot/pdfparser não encontrada. Rode "composer install" na pasta do plugin.', 'jim-conversor-acessivel' )
			);
		}

		try {
			$parser  = new PdfParser();
			$pdf     = $parser->parseFile( $file_path );
			$pages   = $pdf->getPages();
		} catch ( \Exception $e ) {
			throw new JIMCA_Converter_Exception(
				sprintf(
					/* translators: %s: mensagem de erro original */
					esc_html__( 'Não foi possível ler o PDF: %s', 'jim-conversor-acessivel' ),
					esc_html( $e->getMessage() )
				)
			);
		}

		if ( empty( $pages ) ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'O PDF não contém texto extraível (pode ser um PDF escaneado/apenas imagem).', 'jim-conversor-acessivel' ) );
		}

		$html = '';

		foreach ( $pages as $index => $page ) {
			$paragraphs = $this->reconstruct_paragraphs( $page->getText() );

			if ( count( $pages ) > 1 ) {
				$html .= '<h2 class="jimca-page-title">' . sprintf(
					/* translators: %d: número da página */
					esc_html__( 'Página %d', 'jim-conversor-acessivel' ),
					$index + 1
				) . '</h2>' . "\n";
			}

			foreach ( $paragraphs as $paragraph ) {
				$html .= '<p>' . esc_html( $paragraph ) . '</p>' . "\n";
			}
		}

		return $html;
	}

	/**
	 * PDFs não têm o conceito de "parágrafo": o texto é posicionado por
	 * coordenadas, e getText() devolve uma linha por quebra visual (não por
	 * quebra de parágrafo). Sem isso, cada página vira um único bloco de
	 * texto corrido. Aqui juntamos linhas quebradas (que não terminam em
	 * pontuação final) na mesma frase, e só fechamos o parágrafo quando a
	 * linha acumulada termina em pontuação final ou há uma linha em branco.
	 *
	 * @return string[] Parágrafos já normalizados (sem quebras internas).
	 */
	private function reconstruct_paragraphs( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$lines = explode( "\n", $text );

		$terminal_punctuation = array( '.', '!', '?', ':', '"', '”', '»' );
		$paragraphs           = array();
		$buffer               = '';

		foreach ( $lines as $raw_line ) {
			$line = trim( $raw_line );

			if ( '' === $line ) {
				if ( '' !== $buffer ) {
					$paragraphs[] = $buffer;
					$buffer       = '';
				}
				continue;
			}

			$buffer = ( '' === $buffer ) ? $line : $buffer . ' ' . $line;

			$last_char = mb_substr( rtrim( $buffer ), -1 );
			if ( in_array( $last_char, $terminal_punctuation, true ) ) {
				$paragraphs[] = $buffer;
				$buffer       = '';
			}
		}

		if ( '' !== $buffer ) {
			$paragraphs[] = $buffer;
		}

		return array_map(
			function ( $paragraph ) {
				return trim( preg_replace( '/\s+/u', ' ', $paragraph ) );
			},
			$paragraphs
		);
	}
}
