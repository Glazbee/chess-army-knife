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
