<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-scorecard.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * @covers JIMCA_Scorecard
 */
class ScorecardTest extends UnitTestCase {

	private function facts( array $override = array() ) {
		return array_merge(
			array(
				'title'              => 'Edital de licitação 2026',
				'description'        => str_repeat( 'Resumo do documento. ', 6 ),
				'author'             => 'Maria Silva',
				'heading_levels'     => array( 2, 3, 3, 2 ),
				'words'              => 4000,
				'unreadable'         => false,
				'images_missing_alt' => 0,
				'published'          => true,
				'indexable'          => true,
				'seo_active'         => true,
			),
			$override
		);
	}

	private function point( array $result, $id ) {
		foreach ( $result['checks'] as $check ) {
			if ( $id === $check['id'] ) {
				return $check['points'];
			}
		}
		return null;
	}

	public function test_a_complete_document_scores_100() {
		$result = JIMCA_Scorecard::evaluate( $this->facts() );

		$this->assertSame( 100, $result['score'] );
		$this->assertSame( 100, array_sum( array_column( $result['checks'], 'max' ) ) );
	}

	public function test_orphan_document_loses_publication_points() {
		$result = JIMCA_Scorecard::evaluate( $this->facts( array( 'published' => false ) ) );

		$this->assertSame( 85, $result['score'] );
		$this->assertSame( 0, $this->point( $result, 'published' ) );
	}

	public function test_skipped_heading_levels_are_penalised() {
		$one   = JIMCA_Scorecard::evaluate( $this->facts( array( 'heading_levels' => array( 2, 4, 3 ) ) ) );
		$three = JIMCA_Scorecard::evaluate( $this->facts( array( 'heading_levels' => array( 2, 4, 2, 4, 2, 4 ) ) ) );

		$this->assertSame( 8, $this->point( $one, 'hierarchy' ) );
		$this->assertSame( 0, $this->point( $three, 'hierarchy' ) );
	}

	public function test_thin_content_caps_the_score() {
		$result = JIMCA_Scorecard::evaluate( $this->facts( array( 'words' => 120 ) ) );

		$this->assertSame( 70, $result['score'] );
	}

	public function test_missing_alt_text_and_unknown_author() {
		$result = JIMCA_Scorecard::evaluate( $this->facts( array( 'images_missing_alt' => 2, 'author' => '' ) ) );

		$this->assertSame( 80, $result['score'] );
	}

	public function test_grade_labels() {
		$this->assertSame( 'Good', JIMCA_Scorecard::grade_label( 90 ) );
		$this->assertSame( 'Needs attention', JIMCA_Scorecard::grade_label( 70 ) );
		$this->assertSame( 'Poor', JIMCA_Scorecard::grade_label( 30 ) );
	}
}
