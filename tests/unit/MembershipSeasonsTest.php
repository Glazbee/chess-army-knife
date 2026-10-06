<?php
/**
 * Tests for checking the details of a new membership season.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class MembershipSeasonsTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
	}

	protected function season( array $fields = array() ) {
		return $fields + array(
			'id'         => 3,
			'name'       => '2025/26',
			'start_date' => '2025-09-01',
			'end_date'   => '',
		);
	}

	public function test_a_name_and_start_date_make_a_season() {
		$this->assertSame(
			array(
				'name'       => '2026/27',
				'start_date' => '2026-09-01',
			),
			Chess_Army_Knife_Membership_Seasons::validate( ' 2026/27 ', '2026-09-01', $this->season() )
		);
	}

	public function test_the_first_season_can_start_on_any_date() {
		$this->assertIsArray( Chess_Army_Knife_Membership_Seasons::validate( '2026/27', '2020-01-01', null ) );
	}

	/**
	 * @dataProvider invalid_seasons
	 */
	public function test_invalid_details_are_rejected( $name, $start, $code ) {
		$result = Chess_Army_Knife_Membership_Seasons::validate( $name, $start, $this->season() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function invalid_seasons() {
		return array(
			'no name'                   => array( '  ', '2026-09-01', 'season_name' ),
			'not a date'                => array( '2026/27', 'September', 'season_date' ),
			'impossible date'           => array( '2026/27', '2026-02-30', 'season_date' ),
			'no date'                   => array( '2026/27', '', 'season_date' ),
			'same day as the current'   => array( '2026/27', '2025-09-01', 'season_order' ),
			'before the current season' => array( '2026/27', '2025-08-31', 'season_order' ),
		);
	}

	public function test_a_long_name_is_cut_to_fit() {
		$result = Chess_Army_Knife_Membership_Seasons::validate( str_repeat( 'a', 100 ), '2026-09-01', null );

		$this->assertSame( Chess_Army_Knife_Membership_Seasons::MAX_NAME_LENGTH, strlen( $result['name'] ) );
	}

	public function test_the_suggested_name_runs_from_the_start_year_to_the_next() {
		$this->assertSame( '2026/27', Chess_Army_Knife_Membership_Seasons::suggest_name( '2026-09-01' ) );
		$this->assertSame( '2099/00', Chess_Army_Knife_Membership_Seasons::suggest_name( '2099-09-01' ) );
	}
}
