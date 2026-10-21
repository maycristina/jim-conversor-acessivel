<?php
/**
 * Creates the pages the SEO audit looks at (see .github/workflows/jim-seo-audit.yml).
 *
 * Run inside WordPress: wp eval-file bin/seo-fixture.php
 *
 * Two documents, each shown by a published page with its shortcode:
 *  - /documento-leitor/ in reader mode (default), long enough to be split in parts;
 *  - /documento-post/ in "post" mode, which must have no search and no parts.
 * Prints the two URLs, one per line.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$jimca_section = static function ( $n ) {
	$html = '<h2>Seção ' . $n . '</h2>';

	for ( $i = 1; $i <= 6; $i++ ) {
		$html .= '<p>Este é o parágrafo ' . $i . ' da seção ' . $n . ', escrito para ter texto suficiente: o documento de teste do Jim precisa de várias frases completas para que a descrição, o tempo de leitura e a divisão em partes sejam exercitados de verdade pela auditoria de SEO.</p>';
	}

	return $html . '<h3>Detalhes da seção ' . $n . '</h3><p>Texto de detalhe da seção ' . $n . ' com mais algumas palavras para a hierarquia de títulos.</p>';
};

$jimca_build = static function ( $title, $sections, $mode, $slug ) use ( $jimca_section ) {
	$html = '';

	for ( $n = 1; $n <= $sections; $n++ ) {
		$html .= $jimca_section( $n );
	}

	$doc = wp_insert_post(
		array(
			'post_type'    => JIMCA_Post_Type::POST_TYPE,
			'post_title'   => $title,
			'post_content' => wp_kses_post( $html ),
			'post_status'  => 'publish',
		)
	);

	update_post_meta( $doc, '_jimca_word_count', (int) preg_match_all( '/\p{L}+/u', wp_strip_all_tags( $html ) ) );
	update_post_meta(
		$doc,
		JIMCA_Metadata::META_KEY,
		array(
			'author'   => 'Maria Silva',
			'subject'  => 'Documento de teste usado pela auditoria de SEO do plugin Jim, com autor, assunto e palavras-chave.',
			'keywords' => array( 'jim', 'acessibilidade', 'seo' ),
			'language' => 'pt-BR',
		)
	);

	if ( 'post' === $mode ) {
		update_post_meta( $doc, JIMCA_Post_Type::MODE_META, 'post' );
	}

	$page = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => '[' . JIMCA_Shortcode::TAG . ' id="' . $doc . '"]',
			'post_status'  => 'publish',
		)
	);

	return get_permalink( $page );
};

// Around 2 KB per section: 200 sections pass the 350 KB from which a document is split in parts (JIMCA_Pagination::MANDATORY_BYTES).
echo $jimca_build( 'Documento de teste no modo leitor', 200, 'reader', 'documento-leitor' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
echo $jimca_build( 'Documento de teste no modo post', 200, 'post', 'documento-post' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
