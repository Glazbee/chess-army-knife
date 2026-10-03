<?php
/**
 * Tests for how pages opened from an emailed link are recognised.
 *
 * @package Chess_Army_Knife
 */

class MemberRequestsTest extends Chess_Army_Knife_TestCase {

	/**
	 * @dataProvider token_queries
	 */
	public function test_a_page_with_a_token_in_its_address_is_recognised( array $query, $expected ) {
		$this->assertSame( $expected, Chess_Army_Knife_Member_Requests::is_token_page( $query ) );
	}

	public function token_queries() {
		return array(
			'portal link'        => array( array( 'cak_portal' => 'abc123' ), true ),
			'email confirmation' => array( array( 'cak_email' => 'abc123' ), true ),
			'withdraw link'      => array( array( 'cak_withdraw' => 'abc123' ), true ),
			'an empty token'     => array( array( 'cak_portal' => '' ), false ),
			'an ordinary page'   => array( array( 'page_id' => '5' ), false ),
			'no query'           => array( array(), false ),
		);
	}

	public function test_a_token_is_stored_under_a_hash_of_it() {
		$key = Chess_Army_Knife_Member_Requests::token_key( 'chess_army_knife_portal_', 'abc123' );

		$this->assertMatchesRegularExpression( '/^chess_army_knife_portal_[0-9a-f]{64}$/', $key );
		$this->assertStringNotContainsString( 'abc123', $key );
		$this->assertSame( $key, Chess_Army_Knife_Member_Requests::token_key( 'chess_army_knife_portal_', 'abc-123 ' ), 'Characters a token cannot have are ignored, as when it is read.' );
		$this->assertNotSame( $key, Chess_Army_Knife_Member_Requests::token_key( 'chess_army_knife_portal_', 'abc124' ) );
		$this->assertNotSame( $key, Chess_Army_Knife_Member_Requests::token_key( 'chess_army_knife_withdraw_', 'abc123' ) );
	}

	public function test_mail_that_answers_a_request_is_sent_after_the_reply() {
		$sent = array();
		Brain\Monkey\Functions\when( 'wp_mail' )->alias(
			function ( $to ) use ( &$sent ) {
				$sent[] = $to;
				return true;
			}
		);
		Brain\Monkey\Actions\expectAdded( 'shutdown' )->once();

		Chess_Army_Knife_Member_Requests::send_after_response( 'ada@example.test', 'Subject', 'Body' );
		Chess_Army_Knife_Member_Requests::send_after_response( 'charles@example.test', 'Subject', 'Body' );
		$this->assertSame( array(), $sent, 'Nothing is sent while the visitor is being answered.' );

		// The shutdown action sends both, and leaves nothing for a later one.
		Chess_Army_Knife_Member_Requests::send_outbox();
		Chess_Army_Knife_Member_Requests::send_outbox();
		$this->assertSame( array( 'ada@example.test', 'charles@example.test' ), $sent );
	}

	public function test_mail_is_sent_at_once_when_the_filter_turns_deferring_off() {
		Brain\Monkey\Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'Chess_Army_Knife_send_mail_after_response' === $hook ? false : $value;
			}
		);
		$sent = array();
		Brain\Monkey\Functions\when( 'wp_mail' )->alias(
			function ( $to ) use ( &$sent ) {
				$sent[] = $to;
				return true;
			}
		);

		Chess_Army_Knife_Member_Requests::send_after_response( 'ada@example.test', 'Subject', 'Body' );

		$this->assertSame( array( 'ada@example.test' ), $sent );
	}

	public function test_the_visitors_address_can_be_supplied_by_a_filter() {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		Brain\Monkey\Functions\when( 'sanitize_text_field' )->returnArg();
		Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();
		$this->assertSame( '10.0.0.1', Chess_Army_Knife_Member_Requests::visitor_address() );

		Brain\Monkey\Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'Chess_Army_Knife_visitor_address' === $hook ? '198.51.100.7' : $value;
			}
		);
		$this->assertSame( '198.51.100.7', Chess_Army_Knife_Member_Requests::visitor_address() );
		$this->assertSame( 'chess_army_knife_data_ip_' . md5( '198.51.100.7' ), Chess_Army_Knife_Member_Requests::visitor_key() );
	}
}
