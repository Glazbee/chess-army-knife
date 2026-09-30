<?php
/**
 * Tests for the pure helpers of Chess_Army_Knife_Memberships.
 *
 * @package Chess_Army_Knife
 */

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

	/**
	 * @dataProvider periods
	 */
	public function test_period_label( $months, $expected ) {
		$this->assertSame( $expected, Chess_Army_Knife_Memberships::period_label( $months ) );
	}

	public function periods() {
		return array(
			'no expiry' => array( 0, 'one-off' ),
			'month'     => array( 1, 'per month' ),
			'year'      => array( 12, 'per year' ),
			'season'    => array( 9, 'for 9 months' ),
		);
	}

	/**
	 * @dataProvider expiries
	 */
	public function test_expiry_from( $start, $months, $expected ) {
		$this->assertSame( $expected, Chess_Army_Knife_Memberships::expiry_from( $start, $months ) );
	}

	public function expiries() {
		return array(
			'a year runs to the day before' => array( '2026-09-01', 12, '2027-08-31' ),
			'mid-year start'                => array( '2026-09-29', 12, '2027-09-28' ),
			'first of the year'             => array( '2026-01-01', 12, '2026-12-31' ),
			'a month'                       => array( '2026-10-15', 1, '2026-11-14' ),
			'crossing a year end'           => array( '2026-12-15', 3, '2027-03-14' ),
			'31 January plus a month'       => array( '2026-01-31', 1, '2026-02-27' ),
			'31 January plus a month, leap' => array( '2028-01-31', 1, '2028-02-28' ),
			'no expiry'                     => array( '2026-09-01', 0, '' ),
			'invalid start'                 => array( 'soon', 12, '' ),
			'impossible start'              => array( '2026-02-30', 12, '' ),
		);
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
}
