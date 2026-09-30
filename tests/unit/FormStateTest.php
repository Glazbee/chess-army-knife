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

		$this->forget_loaded_values();
	}

	/**
	 * Forget values loaded earlier, as a new request would.
	 */
	protected function forget_loaded_values() {
		$property = new ReflectionProperty( Chess_Army_Knife_Form_State::class, 'values' );
		$property->setAccessible( true );
		$property->setValue( null, null );
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

		$this->forget_loaded_values();
		Chess_Army_Knife_Form_State::save( array( 'name' => 'Ann' ) );
		$_GET[ Chess_Army_Knife_Form_State::PARAM ] = 'abc123';
		$this->assertFalse( Chess_Army_Knife_Form_State::checked( 'consent', '1', true ) );
	}
}
