<?php
require_once dirname( __DIR__, 2 ) . '/includes/class-tutor.php';
require_once __DIR__ . '/UnitTestCase.php';

/**
 * @covers JIMCA_Tutor::sanitize
 */
class TutorSettingsTest extends UnitTestCase {

	public function test_invalid_input_gives_defaults_and_the_tutor_has_no_switch_of_its_own() {
		$out = JIMCA_Tutor::sanitize( 'not an array' );

		$this->assertTrue( $out['enabled'], 'the AI assistant switch is the only one' );
		$this->assertTrue( $out['default_on'] );
		$this->assertSame( 'everyone', $out['audience'] );
		$this->assertSame( 20, $out['per_hour'] );
	}

	public function test_limits_are_kept_inside_their_ranges() {
		$low  = JIMCA_Tutor::sanitize(
			array(
				'per_hour' => -5,
				'per_day'  => 0,
			)
		);
		$high = JIMCA_Tutor::sanitize(
			array(
				'per_hour' => 9999,
				'per_day'  => 9999999,
			)
		);

		$this->assertSame( 1, $low['per_hour'] );
		$this->assertSame( 1, $low['per_day'] );
		$this->assertSame( 500, $high['per_hour'] );
		$this->assertSame( 100000, $high['per_day'] );
	}

	public function test_audience_accepts_only_known_values() {
		$this->assertSame( 'logged_in', JIMCA_Tutor::sanitize( array( 'audience' => 'logged_in' ) )['audience'] );
		$this->assertSame( 'everyone', JIMCA_Tutor::sanitize( array( 'audience' => 'hackers' ) )['audience'] );
	}

	public function test_text_left_as_the_default_is_stored_empty() {
		$defaults = JIMCA_Tutor::defaults();
		$out      = JIMCA_Tutor::sanitize(
			array(
				'greeting' => $defaults['greeting'],
				'name'     => 'Professor Jim',
			)
		);

		$this->assertSame( '', $out['greeting'] );
		$this->assertSame( 'Professor Jim', $out['name'] );
	}

	public function test_name_is_cut_at_sixty_characters_and_stripped_of_tags() {
		$out = JIMCA_Tutor::sanitize( array( 'name' => '<b>' . str_repeat( 'a', 100 ) . '</b>' ) );

		$this->assertSame( 60, mb_strlen( $out['name'] ) );
		$this->assertStringNotContainsString( '<', $out['name'] );
	}

	/* ---------------------------------------------------------------
	 * Quick texts
	 * ------------------------------------------------------------- */

	private function stored( $option ) {
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( $option );
	}

	public function test_the_standard_quick_texts_are_used_until_someone_changes_them() {
		$this->stored( array() );

		$settings = JIMCA_Tutor::get_settings();

		$this->assertTrue( $settings['chips_on'] );
		$this->assertCount( 5, $settings['chips'] );
		$this->assertCount( 5, JIMCA_Tutor::active_chips() );
		$this->assertSame( array( 'Challenge me' ), array( JIMCA_Tutor::active_chips()[3][0] ) );
		$this->assertTrue( JIMCA_Tutor::active_chips()[3][2], 'the challenge is sent at once' );
		$this->assertCount( 2, JIMCA_Tutor::active_chips()[0], 'the others only fill the field' );
	}

	public function test_the_form_sending_the_standard_list_stores_it_empty() {
		$rows = array();
		foreach ( JIMCA_Tutor::default_chips() as $chip ) {
			$rows[] = $chip;
		}

		$out = JIMCA_Tutor::sanitize(
			array(
				'chips_on' => '1',
				'chips'    => $rows,
			)
		);

		$this->assertSame( array(), $out['chips'] );
		$this->assertTrue( $out['chips_on'] );
	}

	public function test_a_quick_text_can_be_hidden_renamed_added_and_removed() {
		$out = JIMCA_Tutor::sanitize(
			array(
				'chips_on' => '1',
				'chips'    => array(
					array(
						'on'    => '1',
						'label' => 'Resumir',
						'text'  => 'Resuma: ',
					),
					array(
						'label' => 'Escondido',
						'text'  => 'Texto escondido',
					),
					array(
						'on'    => '1',
						'label' => 'Sem texto',
						'text'  => '',
					),
					array(
						'on'    => '1',
						'label' => '',
						'text'  => 'Sem nome',
					),
					array(
						'on'    => '1',
						'label' => 'Desafio',
						'text'  => 'Faça uma pergunta.',
						'send'  => '1',
					),
				),
			)
		);

		$this->assertCount( 3, $out['chips'], 'rows without a name or a text are dropped' );
		$this->assertSame( 'Resuma: ', $out['chips'][0]['text'], 'the space after the colon is kept' );
		$this->assertFalse( $out['chips'][1]['on'], 'a row not ticked is kept but hidden' );
		$this->assertTrue( $out['chips'][2]['send'] );

		$this->stored( array( 'chips' => $out['chips'] ) );

		$active = JIMCA_Tutor::active_chips();
		$this->assertCount( 2, $active );
		$this->assertSame( 'Resumir', $active[0][0] );
		$this->assertSame( 'Desafio', $active[1][0] );
	}

	public function test_all_quick_texts_can_be_turned_off() {
		$out = JIMCA_Tutor::sanitize( array( 'chips' => JIMCA_Tutor::default_chips() ) );

		$this->assertFalse( $out['chips_on'], 'the box is not ticked' );

		$this->stored( array( 'chips_on' => false ) );
		$this->assertSame( array(), JIMCA_Tutor::active_chips() );
	}

	public function test_restoring_the_standard_list_wins_over_the_rows_sent() {
		$out = JIMCA_Tutor::sanitize(
			array(
				'chips_on'    => '1',
				'chips_reset' => '1',
				'chips'       => array(
					array(
						'on'    => '1',
						'label' => 'Meu',
						'text'  => 'Meu texto',
					),
				),
			)
		);

		$this->assertSame( array(), $out['chips'] );
	}

	public function test_the_list_is_limited_and_the_labels_cut() {
		$rows = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$rows[] = array(
				'on'    => '1',
				'label' => str_repeat( 'a', 100 ),
				'text'  => str_repeat( 'b', 900 ),
			);
		}

		$out = JIMCA_Tutor::sanitize( array( 'chips' => $rows ) );

		$this->assertCount( JIMCA_Tutor::MAX_CHIPS, $out['chips'] );
		$this->assertSame( 40, mb_strlen( $out['chips'][0]['label'] ) );
		$this->assertSame( 500, mb_strlen( $out['chips'][0]['text'] ) );
	}
}
