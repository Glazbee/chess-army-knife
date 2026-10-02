<?php
/**
 * Tests for working out who has not played for a team lately.
 *
 * @package Chess_Army_Knife
 */

class SquadReviewTest extends Chess_Army_Knife_TestCase {

	/**
	 * @dataProvider cutoffs
	 */
	public function test_the_cutoff_is_a_number_of_months_back( $today, $months, $expected ) {
		$this->assertSame( $expected, Chess_Army_Knife_Squad_Review::cutoff( $today, $months ) );
	}

	public function cutoffs() {
		return array(
			'a year'               => array( '2026-10-02', 12, '2025-10-02' ),
			'across a new year'    => array( '2026-02-10', 3, '2025-11-10' ),
			'end of a short month' => array( '2026-03-31', 1, '2026-02-28' ),
			'at least a month'     => array( '2026-10-02', 0, '2026-09-02' ),
			'at most five years'   => array( '2026-10-02', 500, '2021-10-02' ),
		);
	}

	private function row( $person, $added, $last = '' ) {
		return array(
			'person_id'   => $person,
			'added'       => $added,
			'last_played' => $last,
		);
	}

	public function test_a_squad_member_is_listed_when_they_have_not_played_since_the_cutoff() {
		$rows = array(
			$this->row( 1, '2024-09-01', '2026-09-20' ), // Played lately.
			$this->row( 2, '2024-09-01', '2025-01-10' ), // Played, but long ago.
			$this->row( 3, '2024-09-01' ),               // Added long ago, never played.
			$this->row( 4, '2026-09-30' ),               // Added yesterday: too new to judge.
		);

		$stale = Chess_Army_Knife_Teams::not_seen_since( $rows, '2025-10-02' );

		$this->assertSame( array( 3, 2 ), array_column( $stale, 'person_id' ), 'The longest away first.' );
		$this->assertSame( '2024-09-01', $stale[0]['seen'], 'Never played: counted from when they were added.' );
	}

	public function test_nobody_is_listed_when_everyone_has_played_lately() {
		$this->assertSame( array(), Chess_Army_Knife_Teams::not_seen_since( array( $this->row( 1, '2024-01-01', '2026-09-20' ) ), '2025-10-02' ) );
		$this->assertSame( array(), Chess_Army_Knife_Teams::not_seen_since( array(), '2025-10-02' ) );
	}
}
