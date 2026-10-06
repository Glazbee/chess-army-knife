<?php
/**
 * Tests for the pure helpers of Chess_Army_Knife_Memberships.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class MembershipsTest extends Chess_Army_Knife_TestCase {

	/**
	 * @dataProvider prices
	 */
	public function test_parse_price( $text, $expected ) {
		$this->assertSame( $expected, Chess_Army_Knife_Memberships::parse_price( $text ) );
	}

	public function prices() {
		return array(
			'whole pounds'         => array( '25', 2500 ),
			'pounds and pence'     => array( '25.50', 2550 ),
			'one decimal place'    => array( '12.5', 1250 ),
			'with currency symbol' => array( '£25', 2500 ),
			'with spaces'          => array( ' 7.00 ', 700 ),
			'thousands separator'  => array( '1,000', 100000 ),
			'zero'                 => array( '0', 0 ),
			'empty'                => array( '', null ),
			'words'                => array( 'free', null ),
			'negative'             => array( '-5', null ),
			'three decimals'       => array( '5.123', null ),
			'two dots'             => array( '5.5.5', null ),
		);
	}

	/**
	 * @dataProvider formatted_prices
	 */
	public function test_format_price( $pence, $expected ) {
		$this->assertSame( $expected, Chess_Army_Knife_Memberships::format_price( $pence ) );
	}

	public function formatted_prices() {
		return array(
			'free'          => array( 0, 'Free' ),
			'whole pounds'  => array( 2500, '£25' ),
			'with pence'    => array( 1250, '£12.50' ),
			'under a pound' => array( 50, '£0.50' ),
		);
	}

	public function test_price_field_value_round_trips_through_parse_price() {
		foreach ( array( 0, 50, 1250, 2500 ) as $pence ) {
			$this->assertSame( $pence, Chess_Army_Knife_Memberships::parse_price( Chess_Army_Knife_Memberships::price_field_value( $pence ) ) );
		}
	}

	public function test_a_membership_is_priced_for_a_season() {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'Adult' );
		$type = Chess_Army_Knife_Memberships::type_data(
			(object) array(
				'ID'          => 5,
				'post_title'  => 'Adult',
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 'per season', $type['period_label'] );
		$this->assertArrayNotHasKey( 'months', $type );
	}

	public function test_is_valid_date() {
		$this->assertTrue( Chess_Army_Knife_Memberships::is_valid_date( '2028-02-29' ) );
		$this->assertFalse( Chess_Army_Knife_Memberships::is_valid_date( '2027-02-29' ) );
		$this->assertFalse( Chess_Army_Knife_Memberships::is_valid_date( '29/02/2028' ) );
		$this->assertFalse( Chess_Army_Knife_Memberships::is_valid_date( '' ) );
	}

	/**
	 * @dataProvider birthdays
	 */
	public function test_is_under_18( $born, $on, $expected ) {
		$this->assertSame( $expected, Chess_Army_Knife_Memberships::is_under_18( $born, $on ) );
	}

	public function birthdays() {
		return array(
			'child'                    => array( '2015-05-01', '2026-09-29', true ),
			'day before 18th birthday' => array( '2008-09-30', '2026-09-29', true ),
			'on 18th birthday'         => array( '2008-09-29', '2026-09-29', false ),
			'adult'                    => array( '1980-01-01', '2026-09-29', false ),
			'leap day, day before'     => array( '2008-02-29', '2026-02-28', true ),
			'leap day, on 1 March'     => array( '2008-02-29', '2026-03-01', false ),
			'invalid date of birth'    => array( 'never', '2026-09-29', false ),
			'empty date of birth'      => array( '', '2026-09-29', false ),
		);
	}

	public function test_payment_reference() {
		$this->assertSame( 'MEM-42', Chess_Army_Knife_Memberships::payment_reference( 42 ) );
	}

	public function test_payment_instructions_come_from_settings() {
		$this->assertSame( '', Chess_Army_Knife_Memberships::payment_instructions() );

		$this->set_settings( array( 'membership_payment_info' => "  Sort code 00-00-00\nAccount 1234  " ) );

		$this->assertSame( "Sort code 00-00-00\nAccount 1234", Chess_Army_Knife_Memberships::payment_instructions() );
	}

	public function test_a_member_can_have_their_own_payment_reference() {
		$this->assertSame( 'MEM-42', Chess_Army_Knife_Memberships::payment_reference( 42, array( 'payment_reference' => '' ) ) );
		$this->assertSame( 'TREASURER-7', Chess_Army_Knife_Memberships::payment_reference( 42, array( 'payment_reference' => 'TREASURER-7' ) ) );
	}

	public function test_the_prefix_is_the_clubs_to_choose() {
		$this->set_settings( array( 'membership_reference_prefix' => 'WHCC' ) );

		$this->assertSame( 'WHCC42', Chess_Army_Knife_Memberships::payment_reference( 42 ) );
		$this->assertSame( 42, Chess_Army_Knife_Memberships::member_id_of_reference( 'whcc42' ) );
		$this->assertSame( 0, Chess_Army_Knife_Memberships::member_id_of_reference( 'MEM-42' ) );
		$this->assertSame( 0, Chess_Army_Knife_Memberships::member_id_of_reference( 'WHCC' ) );
	}

	public function test_a_club_can_do_without_payment_references() {
		$this->set_settings( array( 'membership_use_references' => 0 ) );

		$this->assertSame( '', Chess_Army_Knife_Memberships::payment_reference( 42, array( 'payment_reference' => 'TREASURER-7' ) ) );
		$this->assertStringNotContainsString( 'reference', strtolower( Chess_Army_Knife_Memberships::thanks_text( 42 ) ) );
	}

	public function test_the_thank_you_is_the_clubs_own_wording_or_a_standard_one() {
		$standard = Chess_Army_Knife_Memberships::thanks_text( 42 );
		$this->assertStringContainsString( 'Your application has been received', $standard );
		$this->assertStringContainsString( 'MEM-42', $standard, 'The standard wording gives the reference for a bank transfer.' );
		$this->assertStringNotContainsString( 'MEM-', Chess_Army_Knife_Memberships::thanks_text( 0 ), 'A silent result has no reference to give.' );

		$this->set_settings( array( 'membership_thanks_message' => 'Welcome! Bank transfer? Quote {reference}. Cash is fine too.' ) );
		$this->assertSame( 'Welcome! Bank transfer? Quote MEM-42. Cash is fine too.', Chess_Army_Knife_Memberships::thanks_text( 42 ) );
	}

	public function test_the_how_to_pay_text_puts_the_members_own_reference_where_the_club_says() {
		$this->set_settings( array( 'membership_payment_info' => 'Bank transfer to 00-00-00 12345678, quoting {reference}. Or pay cash.' ) );

		$this->assertSame( 'Bank transfer to 00-00-00 12345678, quoting MEM-42. Or pay cash.', Chess_Army_Knife_Memberships::payment_instructions( 42 ) );
		$this->assertSame( 'Bank transfer to 00-00-00 12345678, quoting TREASURER-7. Or pay cash.', Chess_Army_Knife_Memberships::payment_instructions( 42, array( 'payment_reference' => 'TREASURER-7' ) ) );
		$this->assertSame( 'Bank transfer to 00-00-00 12345678, quoting your payment reference. Or pay cash.', Chess_Army_Knife_Memberships::payment_instructions(), 'With no member, it says what to quote.' );

		$this->set_settings(
			array(
				'membership_payment_info'   => 'Pay at the club{reference}.',
				'membership_use_references' => 0,
			)
		);
		$this->assertSame( 'Pay at the club.', Chess_Army_Knife_Memberships::payment_instructions( 42 ) );
	}

	public function test_instructions_without_a_placeholder_are_left_alone() {
		$this->set_settings( array( 'membership_payment_info' => 'Cash at the club.' ) );

		$this->assertSame( 'Cash at the club.', Chess_Army_Knife_Memberships::payment_instructions( 42 ) );
	}
}
