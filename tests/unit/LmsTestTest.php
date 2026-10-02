<?php
/**
 * Tests for the LMS "Test connection" answers.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class LmsTestTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '_n' )->alias(
			function ( $single, $plural, $number ) {
				return 1 === (int) $number ? $single : $plural;
			}
		);
	}

	public function test_a_working_key_says_connected_with_the_number_of_seasons() {
		$answer = Chess_Army_Knife_LMS_Test::describe( array( array( 'id' => 1 ), array( 'id' => 2 ) ) );

		$this->assertSame( 'ok', $answer['status'] );
		$this->assertStringContainsString( '2 seasons', $answer['message'] );
	}

	/**
	 * @dataProvider failures
	 */
	public function test_each_kind_of_failure_is_told_apart( $code, $status ) {
		$this->assertSame( $status, Chess_Army_Knife_LMS_Test::describe( new WP_Error( $code, 'Something went wrong.' ) )['status'] );
	}

	public function failures() {
		return array(
			'rejected key'  => array( 'lms_unauthorised', 'key' ),
			'no permission' => array( 'lms_forbidden', 'key' ),
			'no key'        => array( 'lms_no_api_key', 'key' ),
			'cannot reach'  => array( 'lms_connection_error', 'unreachable' ),
			'unknown org'   => array( 'lms_not_found', 'other' ),
			'server error'  => array( 'lms_http_error', 'other' ),
		);
	}

	public function test_an_unexpected_error_shows_its_own_message() {
		$this->assertSame( 'Boom.', Chess_Army_Knife_LMS_Test::describe( new WP_Error( 'lms_http_error', 'Boom.' ) )['message'] );
	}

	public function test_nothing_is_tested_without_a_key_or_an_organisation() {
		Functions\expect( 'wp_remote_get' )->never();
		Functions\when( 'get_posts' )->justReturn( array() ); // The club has no teams.

		$this->assertSame( 'no_key', Chess_Army_Knife_LMS_Test::run()['status'] );

		$this->set_settings( array( 'lms_api_key' => 'secret' ) );
		$this->assertSame( 'no_org', Chess_Army_Knife_LMS_Test::run()['status'] );
	}
}
