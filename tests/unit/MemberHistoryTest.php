<?php
/**
 * Tests for the membership history summary.
 *
 * @package Chess_Army_Knife
 */

class MemberHistoryTest extends Chess_Army_Knife_TestCase {

	const TODAY = '2026-09-30';

	protected function period( $start, $expiry ) {
		return array(
			'start_date'  => $start,
			'expiry_date' => $expiry,
			'type_name'   => 'Adult',
			'paid_on'     => '',
		);
	}

	public function test_no_periods_means_no_history() {
		$summary = Chess_Army_Knife_Member_History::summarise( array(), self::TODAY );

		$this->assertNull( $summary['first_joined'] );
		$this->assertNull( $summary['continuous_since'] );
		$this->assertSame( array(), $summary['lapses'] );
		$this->assertSame( 0, $summary['days'] );
	}

	public function test_renewals_that_follow_on_are_one_unbroken_membership() {
		$summary = Chess_Army_Knife_Member_History::summarise(
			array(
				$this->period( '2024-10-01', '2025-09-30' ),
				$this->period( '2025-10-01', '2026-09-30' ),
				$this->period( '2026-10-01', '2027-09-30' ),
			),
			self::TODAY
		);

		$this->assertSame( '2024-10-01', $summary['first_joined'] );
		$this->assertSame( '2024-10-01', $summary['continuous_since'] );
		$this->assertSame( array(), $summary['lapses'] );
		$this->assertCount( 1, $summary['runs'] );
		$this->assertSame( 1095, $summary['days'] );
	}

	public function test_a_gap_between_periods_is_a_lapse() {
		$summary = Chess_Army_Knife_Member_History::summarise(
			array(
				$this->period( '2022-09-01', '2023-08-31' ),
				$this->period( '2024-01-15', '2025-01-14' ),
				$this->period( '2025-09-01', '2026-08-31' ),
			),
			self::TODAY
		);

		$this->assertSame( '2022-09-01', $summary['first_joined'] );
		$this->assertNull( $summary['continuous_since'], 'The last period has ended.' );
		$this->assertSame(
			array(
				array(
					'from' => '2023-09-01',
					'to'   => '2024-01-14',
				),
				array(
					'from' => '2025-01-15',
					'to'   => '2025-08-31',
				),
			),
			$summary['lapses']
		);
	}

	public function test_continuous_since_is_the_start_of_the_current_run() {
		$summary = Chess_Army_Knife_Member_History::summarise(
			array(
				$this->period( '2020-01-01', '2020-12-31' ),
				$this->period( '2025-10-01', '2026-09-30' ),
			),
			self::TODAY
		);

		$this->assertSame( '2020-01-01', $summary['first_joined'] );
		$this->assertSame( '2025-10-01', $summary['continuous_since'] );
	}

	public function test_overlapping_periods_count_each_day_once() {
		$summary = Chess_Army_Knife_Member_History::summarise(
			array(
				$this->period( '2026-01-01', '2026-12-31' ),
				$this->period( '2026-06-01', '2027-05-31' ),
			),
			self::TODAY
		);

		$this->assertCount( 1, $summary['runs'] );
		$this->assertSame( 516, $summary['days'] ); // 1 Jan 2026 to 31 May 2027.
		$this->assertSame( array(), $summary['lapses'] );
	}

	public function test_a_period_with_no_expiry_runs_until_today() {
		$summary = Chess_Army_Knife_Member_History::summarise( array( $this->period( '2026-09-01', '' ) ), self::TODAY );

		$this->assertSame( '2026-09-01', $summary['continuous_since'] );
		$this->assertSame( 30, $summary['days'] );
		$this->assertSame(
			array(
				array(
					'from' => '2026-09-01',
					'to'   => '',
				),
			),
			$summary['runs']
		);
	}

	public function test_durations_read_as_years_months_or_days() {
		$this->assertSame( '1 year', Chess_Army_Knife_Member_History::duration_label( '2025-10-01', '2026-09-30' ) );
		$this->assertSame( '2 years, 3 months', Chess_Army_Knife_Member_History::duration_label( '2023-07-01', '2025-09-30' ) );
		$this->assertSame( '5 months', Chess_Army_Knife_Member_History::duration_label( '2026-01-01', '2026-05-31' ) );
		$this->assertSame( '12 days', Chess_Army_Knife_Member_History::duration_label( '2026-09-01', '2026-09-12' ) );
	}
}
