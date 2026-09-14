<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Escolhe o conversor certo com base na extensão do arquivo e devolve
 * HTML sanitizado pronto para ser salvo em post_content.
 */
class JIMCA_Converter {

	/**
	 * Extensões do PHP exigidas por cada formato. Verificamos antes de
	 * chamar a biblioteca de leitura: em servidores sem `zip` (DOCX) ou
	 * `zlib` (PDF), a biblioteca quebraria com um erro fatal cru, e o
	 * usuário veria só uma tela de erro em admin-post.php em vez de saber
	 * o que precisa ser habilitado.
	 *
	 * @var array<string, array<int, string>>
	 */
	private static $required_extensions = array(
		'pdf'  => array( 'zlib' ),
		'docx' => array( 'zip', 'dom' ),
		'txt'  => array(),
	);

	/**
	 * @param string $extension Extensão em minúsculas.
	 * @throws JIMCA_Converter_Exception Se faltar alguma extensão do PHP.
	 */
	private static function assert_php_extensions( $extension ) {
		if ( ! isset( self::$required_extensions[ $extension ] ) ) {
			return;
		}

		$missing = array();

		foreach ( self::$required_extensions[ $extension ] as $required ) {
			if ( ! extension_loaded( $required ) ) {
				$missing[] = $required;
			}
		}

		if ( ! empty( $missing ) ) {
			throw new JIMCA_Converter_Exception(
				sprintf(
					/* translators: 1: extensão do arquivo (ex.: docx), 2: lista de extensões do PHP que faltam */
					esc_html__( 'Este servidor não tem as extensões do PHP necessárias para ler arquivos .%1$s: %2$s. Peça à sua hospedagem para habilitá-las.', 'jim-conversor-acessivel' ),
					sanitize_key( $extension ),
					implode( ', ', array_map( 'sanitize_key', $missing ) )
				)
			);
		}
	}

	/**
	 * @param string $file_path Caminho absoluto do arquivo temporário.
	 * @param string $extension Extensão em minúsculas (pdf|docx|txt).
	 * @return string HTML sanitizado.
	 *
	 * @throws JIMCA_Converter_Exception
	 */
	public static function convert( $file_path, $extension ) {
		$extension = strtolower( $extension );

		self::assert_php_extensions( $extension );

		switch ( $extension ) {
			case 'pdf':
				$converter = new JIMCA_Pdf_Converter();
				break;
			case 'docx':
				$converter = new JIMCA_Word_Converter();
				break;
			case 'txt':
				$converter = new JIMCA_Txt_Converter();
				break;
			case 'doc':
				throw new JIMCA_Converter_Exception(
					esc_html__( 'Arquivos .doc (Word 97-2003) não são suportados. Salve o documento como .docx e envie novamente.', 'jim-conversor-acessivel' )
				);
			default:
				throw new JIMCA_Converter_Exception(
					sprintf(
						/* translators: %s: extensão do arquivo */
						esc_html__( 'Tipo de arquivo não suportado: %s', 'jim-conversor-acessivel' ),
						sanitize_key( $extension )
					)
				);
		}

		$html = $converter->convert( $file_path );

		return wp_kses_post( $html );
	}
}
