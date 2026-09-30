<?php
/**
 * Tests for the checks Chess_Army_Knife_Membership_Form makes before saving an application.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class MembershipFormTest extends Chess_Army_Knife_TestCase {

	/** @var bool Whether the fake nonce check passes. */
	private $nonce_valid = true;

	protected function setUp(): void {
		parent::setUp();
		$this->nonce_valid = true;

		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->alias(
			function () {
				return $this->nonce_valid;
			}
		);
	}

	private function input( array $overrides = array() ) {
		return $overrides + array(
			Chess_Army_Knife_Membership_Form::NONCE_FIELD => 'abc123',
			'consent'                                     => '1',
		);
	}

	public function test_a_bad_nonce_is_rejected() {
		$this->nonce_valid = false;

		$result = Chess_Army_Knife_Membership_Form::submit( $this->input() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'expired', $result->get_error_code() );
	}

	public function test_a_missing_nonce_is_rejected() {
		$this->nonce_valid = false;

		$result = Chess_Army_Knife_Membership_Form::submit( array( 'consent' => '1' ) );

		$this->assertSame( 'expired', $result->get_error_code() );
	}

	public function test_a_filled_honeypot_is_dropped_without_saving() {
		// Nothing else is stubbed: reaching the store or the transient would fail the test.
		$result = Chess_Army_Knife_Membership_Form::submit( $this->input( array( Chess_Army_Knife_Membership_Form::HONEYPOT => 'http://spam.test' ) ) );

		$this->assertSame( 0, $result );
	}

	public function test_too_many_recent_applications_are_throttled() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->transients[ 'chess_army_knife_apply_' . md5( '203.0.113.9' ) ] = array(
			'value' => Chess_Army_Knife_Membership_Form::MAX_PER_HOUR,
			'ttl'   => HOUR_IN_SECONDS,
		);

		$result = Chess_Army_Knife_Membership_Form::submit( $this->input() );

		$this->assertSame( 'throttled', $result->get_error_code() );
	}

	public function test_consent_is_required() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

		$result = Chess_Army_Knife_Membership_Form::submit( $this->input( array( 'consent' => '' ) ) );

		$this->assertSame( 'consent', $result->get_error_code() );
	}

	public function test_invalid_details_come_back_as_the_stores_error() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
		Functions\when( 'sanitize_email' )->alias( 'trim' );

		$result = Chess_Army_Knife_Membership_Form::submit( $this->input( array( 'name' => '' ) ) );

		$this->assertSame( 'member_name', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'chess_army_knife_apply_' . md5( '203.0.113.11' ), $this->transients, 'A rejected form does not count towards the limit.' );
	}

	public function test_every_error_the_form_can_return_has_a_message() {
		foreach ( array( 'expired', 'throttled', 'consent', 'member_name', 'member_email', 'member_dob', 'member_type' ) as $code ) {
			$this->assertNotSame( 'Something went wrong. Please try again.', Chess_Army_Knife_Membership_Form::error_message( $code ), $code );
		}
		$this->assertSame( 'Something went wrong. Please try again.', Chess_Army_Knife_Membership_Form::error_message( 'nonsense' ) );
	}
}
