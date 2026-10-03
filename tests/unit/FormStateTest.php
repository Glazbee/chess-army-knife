<?php
/**
 * Tests for Chess_Army_Knife_Form_State.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class FormStateTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_generate_password' )->justReturn( 'abc123' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	public function test_nothing_is_kept_without_a_key() {
		$this->assertFalse( Chess_Army_Knife_Form_State::has_values() );
		$this->assertSame( 'fallback', Chess_Army_Knife_Form_State::value( 'name', 'fallback' ) );
	}

	public function test_typed_values_come_back() {
		$key = Chess_Army_Knife_Form_State::save(
			array(
				'name'  => 'Ann Example',
				'email' => 'ann@example.test',
			)
		);
		$this->assertSame( 'abc123', $key );

		$_GET[ Chess_Army_Knife_Form_State::PARAM ] = $key;
		$this->assertSame( 'Ann Example', Chess_Army_Knife_Form_State::value( 'name' ) );
		$this->assertTrue( Chess_Army_Knife_Form_State::has_values() );
	}

	public function test_nonces_tokens_and_honeypots_are_not_kept() {
		Chess_Army_Knife_Form_State::save(
			array(
				'name'                          => 'Ann',
				'chess_army_knife_portal_nonce' => 'secret',
				'token'                         => 'session',
				'action'                        => 'do_it',
				'cak_redirect'                  => 'https://example.test/',
				'cak_url'                       => 'bot',
				'cak_website'                   => 'bot',
			)
		);

		$this->assertSame( array( 'name' => 'Ann' ), $this->transients['Chess_Army_Knife_form_abc123']['value'] );
	}

	public function test_values_are_kept_for_a_short_time() {
		Chess_Army_Knife_Form_State::save( array( 'name' => 'Ann' ) );
		$this->assertSame( 900, $this->transients['Chess_Army_Knife_form_abc123']['ttl'] );
	}

	public function test_a_key_with_odd_characters_finds_nothing() {
		$_GET[ Chess_Army_Knife_Form_State::PARAM ] = '../etc';
		$this->assertFalse( Chess_Army_Knife_Form_State::has_values() );
	}

	public function test_checkboxes_radios_and_lists() {
		Chess_Army_Knife_Form_State::save(
			array(
				'newsletter'     => '1',
				'choice'         => 'export',
				'whatsapp_teams' => array( '3', '7' ),
				'teams'          => array( '12' => array( '5' ) ),
			)
		);
		$_GET[ Chess_Army_Knife_Form_State::PARAM ] = 'abc123';

		$this->assertTrue( Chess_Army_Knife_Form_State::checked( 'newsletter' ) );
		$this->assertFalse( Chess_Army_Knife_Form_State::checked( 'whatsapp' ) );
		$this->assertTrue( Chess_Army_Knife_Form_State::checked( 'choice', 'export' ) );
		$this->assertFalse( Chess_Army_Knife_Form_State::checked( 'choice', 'erase' ) );
		$this->assertTrue( Chess_Army_Knife_Form_State::checked( 'whatsapp_teams', '7' ) );
		$this->assertFalse( Chess_Army_Knife_Form_State::checked( 'whatsapp_teams', '4' ) );
		$this->assertTrue( Chess_Army_Knife_Form_State::checked( 'teams', '5', false, 12 ) );
	}

	public function test_default_applies_only_when_nothing_was_kept() {
		$this->assertTrue( Chess_Army_Knife_Form_State::checked( 'consent', '1', true ) );

		Chess_Army_Knife_Form_State::save( array( 'name' => 'Ann' ) );
		$_GET[ Chess_Army_Knife_Form_State::PARAM ] = 'abc123';
		$this->assertFalse( Chess_Army_Knife_Form_State::checked( 'consent', '1', true ) );
	}

	public function test_one_visitor_cannot_keep_values_more_than_the_limit_in_an_hour() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		for ( $i = 0; $i < Chess_Army_Knife_Form_State::MAX_SAVES_PER_HOUR; $i++ ) {
			$this->assertSame( 'abc123', Chess_Army_Knife_Form_State::save( array( 'name' => 'Ann' ) ) );
		}

		$this->assertSame( '', Chess_Army_Knife_Form_State::save( array( 'name' => 'Ann' ) ) );
		$this->assertSame( array(), Chess_Army_Knife_Form_State::redirect_args( array( 'name' => 'Ann' ) ) );
	}

	public function test_redirect_args_carry_the_key() {
		$this->assertSame( array( Chess_Army_Knife_Form_State::PARAM => 'abc123' ), Chess_Army_Knife_Form_State::redirect_args( array( 'name' => 'Ann' ) ) );
	}

	public function test_long_values_and_floods_of_fields_are_cut_down() {
		$input = array( 'bio' => str_repeat( 'x', Chess_Army_Knife_Form_State::MAX_LENGTH + 500 ) );
		for ( $i = 0; $i < Chess_Army_Knife_Form_State::MAX_FIELDS + 20; $i++ ) {
			$input[ 'field' . $i ] = 'y';
		}

		Chess_Army_Knife_Form_State::save( $input );
		$kept = $this->transients['Chess_Army_Knife_form_abc123']['value'];

		$this->assertCount( Chess_Army_Knife_Form_State::MAX_FIELDS, $kept );
		$this->assertSame( Chess_Army_Knife_Form_State::MAX_LENGTH, strlen( $kept['bio'] ) );
	}
}
