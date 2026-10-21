<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-seo.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-metadata.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-autofill.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * Suggestions drawn from the text of a document.
 *
 * @covers JIMCA_Autofill
 */
class AutofillTest extends UnitTestCase {

	public function test_author_from_an_author_line() {
		$html = '<p>Title: Um documento qualquer<br />Authors: Ricardo Diogo and Tiago Tejo<br />Date: 2026</p><p>Texto do documento com várias palavras para ter corpo.</p>';

		$this->assertSame( 'Ricardo Diogo and Tiago Tejo', JIMCA_Autofill::detect_author( $html ) );
	}

	public function test_author_labels_in_other_languages() {
		$this->assertSame( 'Maria Silva', JIMCA_Autofill::detect_author( '<p>Autor: Maria Silva</p>' ) );
		$this->assertSame( 'Maria Silva', JIMCA_Autofill::detect_author( '<p>Escrito por - Maria Silva</p>' ) );
		$this->assertSame( 'John Smith', JIMCA_Autofill::detect_author( '<p>Written by: John Smith.</p>' ) );
		$this->assertSame( 'Ana Costa', JIMCA_Autofill::detect_author( '<p>Por: Ana Costa</p>' ) );
	}

	public function test_by_and_por_need_a_colon_so_a_sentence_is_not_an_author() {
		$this->assertSame( '', JIMCA_Autofill::detect_author( '<p>Por que o céu é azul? Porque a luz se espalha.</p>' ) );
		$this->assertSame( '', JIMCA_Autofill::detect_author( '<p>By the time we arrived the doors were closed.</p>' ) );
	}

	public function test_a_generic_name_is_not_an_author() {
		$this->assertSame( '', JIMCA_Autofill::detect_author( '<p>Author: admin</p>' ) );
		$this->assertSame( '', JIMCA_Autofill::detect_author( '<p>Author: ??</p>' ) );
	}

	public function test_author_is_read_only_from_the_start_of_the_text() {
		$html = str_repeat( '<p>' . str_repeat( 'Palavra ', 40 ) . '</p>', 8 ) . '<p>Author: Muito Longe</p>';

		$this->assertSame( '', JIMCA_Autofill::detect_author( $html ) );
	}

	public function test_title_from_a_title_line_within_ten_and_seventy_characters() {
		$this->assertSame( 'Guia de leitura acessível', JIMCA_Autofill::detect_title( '<p>Título: Guia de leitura acessível</p><p>Texto.</p>' ) );
	}

	public function test_title_falls_back_to_the_first_heading_then_the_first_line() {
		$this->assertSame( 'Relatório anual da biblioteca', JIMCA_Autofill::detect_title( '<h1>Relatório anual da biblioteca</h1><p>Texto.</p>' ) );
		$this->assertSame( 'Uma primeira linha que serve de título', JIMCA_Autofill::detect_title( '<p>Uma primeira linha que serve de título</p><p>Texto do corpo.</p>' ) );
	}

	public function test_a_long_title_is_cut_at_a_word_to_seventy_characters() {
		$title = JIMCA_Autofill::fit_title( 'Manual de atendimento ao cidadão com orientações completas para todos os setores da prefeitura' );

		$this->assertLessThanOrEqual( 70, mb_strlen( $title ) );
		$this->assertGreaterThanOrEqual( 10, mb_strlen( $title ) );
		$this->assertStringStartsWith( 'Manual de atendimento', $title );
		$this->assertSame( '', JIMCA_Autofill::fit_title( 'Curto' ) );
	}

	public function test_the_current_title_is_not_suggested_again() {
		$this->assertSame( '', JIMCA_Autofill::detect_title( '<h1>Relatório anual da biblioteca</h1>', 'Relatório anual da biblioteca' ) );
	}

	public function test_summary_from_a_summary_line_or_the_first_prose_paragraph() {
		$this->assertSame(
			'Este documento descreve as regras do concurso e os prazos de inscrição.',
			JIMCA_Autofill::detect_summary( '<p>Resumo: Este documento descreve as regras do concurso e os prazos de inscrição.</p>' )
		);

		$prose = JIMCA_Autofill::detect_summary( '<p>Título curto</p><p>O concurso abre inscrições em março e termina em abril, com provas aplicadas em todas as capitais do país.</p>' );
		$this->assertStringStartsWith( 'O concurso abre inscrições', $prose );
		$this->assertLessThanOrEqual( JIMCA_SEO::DESCRIPTION_CHARS + 1, mb_strlen( $prose ) );
	}

	public function test_headings_from_numbered_and_chapter_lines() {
		$body = 'Este é um parágrafo comprido de texto corrido, com várias frases completas, que ocupa espaço suficiente para ser prosa.';
		$html = "<p>1. Introdução</p><p>$body</p><p>1.1 Contexto</p><p>$body</p><p>Capítulo 2</p><p>$body</p><p>$body</p><p>$body</p><p>$body</p>";

		$fixed = JIMCA_Autofill::fix_headings( $html );

		$this->assertSame( 3, $fixed['count'] );
		$this->assertStringContainsString( '<h2>1. Introdução</h2>', $fixed['html'] );
		$this->assertStringContainsString( '<h3>1.1 Contexto</h3>', $fixed['html'] );
		$this->assertStringContainsString( '<h2>Capítulo 2</h2>', $fixed['html'] );
		$this->assertSame( 6, substr_count( $fixed['html'], '<p>' ) );
	}

	public function test_headings_from_capitals_and_short_lines_that_open_a_paragraph() {
		$body = 'Este é um parágrafo comprido de texto corrido, com várias frases completas, que ocupa espaço suficiente para ser prosa.';
		$html = "<p>$body</p><p>MÉTODO</p><p>$body</p><p>Resultados obtidos</p><p>$body</p><p>$body</p><p>$body</p><p>$body</p>";

		$fixed = JIMCA_Autofill::fix_headings( $html );

		$this->assertSame( 2, $fixed['count'] );
		$this->assertStringContainsString( '<h2>MÉTODO</h2>', $fixed['html'] );
		$this->assertStringContainsString( '<h2>Resultados obtidos</h2>', $fixed['html'] );
	}

	public function test_sentences_and_multi_line_blocks_are_not_headings() {
		$html = '<p>Isto é uma frase curta.</p><p>Linha um<br />Linha dois</p><p>Pergunta curta?</p><p>Texto qualquer que não termina com ponto final mas é longo demais para ser um título de seção, porque passa de oitenta caracteres.</p>';

		$this->assertSame( 0, JIMCA_Autofill::fix_headings( $html )['count'] );
	}

	public function test_a_poem_is_left_alone() {
		$html = '';
		foreach ( array( 'Suis en amour avec le jour', 'Le soleil dort sur la rivière', 'Les arbres chantent doucement', 'Et la nuit tombe sur la terre', 'Je marche sans rien dire encore', 'Le vent murmure à mon oreille' ) as $verse ) {
			$html .= '<p>' . $verse . '</p>';
		}

		$fixed = JIMCA_Autofill::fix_headings( $html );

		$this->assertSame( 0, $fixed['count'] );
		$this->assertSame( $html, $fixed['html'] );
	}
}
