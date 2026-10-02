<?php
/**
 * Tests for the pure parts of Chess_Army_Knife_Setup.
 *
 * @package Chess_Army_Knife
 */

class SetupTest extends Chess_Army_Knife_TestCase {

	/**
	 * @dataProvider weekdays
	 */
	public function test_next_weekday_is_the_first_matching_day_on_or_after_today( $weekday, $expected ) {
		// 2026-10-01 is a Thursday.
		$this->assertSame( $expected, Chess_Army_Knife_Setup::next_weekday( '2026-10-01', $weekday ) );
	}

	public function weekdays() {
		return array(
			'today'          => array( 4, '2026-10-01' ),
			'tomorrow'       => array( 5, '2026-10-02' ),
			'next week'      => array( 3, '2026-10-07' ),
			'sunday'         => array( 0, '2026-10-04' ),
			'across a month' => array( 2, '2026-10-06' ),
		);
	}

	public function test_the_presets_are_the_regular_events_and_leave_tournaments_out() {
		$presets = Chess_Army_Knife_Setup::event_presets();

		$this->assertSame( array( 'club_night', 'coaching', 'competitive' ), array_keys( $presets ) );
		foreach ( $presets as $preset ) {
			$this->assertGreaterThanOrEqual( 0, $preset['weekday'] );
			$this->assertLessThanOrEqual( 6, $preset['weekday'] );
			$this->assertMatchesRegularExpression( '/^\d\d:\d\d$/', $preset['start'] );
		}
	}
}
