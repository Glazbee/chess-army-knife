<?php
/**
 * Tests for Chess_Army_Knife_Standings.
 *
 * @package Chess_Army_Knife
 */

class StandingsTest extends Chess_Army_Knife_TestCase {

	private function entries( $count, array $withdrawn = array() ) {
		$entries = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$entries[] = array(
				'id'     => $i,
				'name'   => "Player {$i}",
				'seed'   => $i,
				'status' => in_array( $i, $withdrawn, true ) ? 'withdrawn' : 'active',
			);
		}
		return $entries;
	}

	private function game( $white, $black, $result ) {
		return array(
			'white_entry_id' => $white,
			'black_entry_id' => $black,
			'result'         => $result,
		);
	}

	public function test_points_for_each_result() {
		$this->assertSame( array( 1.0, 0.0 ), Chess_Army_Knife_Standings::points_for( '1-0' ) );
		$this->assertSame( array( 0.0, 1.0 ), Chess_Army_Knife_Standings::points_for( '0-1' ) );
		$this->assertSame( array( 0.5, 0.5 ), Chess_Army_Knife_Standings::points_for( '1/2-1/2' ) );
		$this->assertNull( Chess_Army_Knife_Standings::points_for( '' ) );
		$this->assertNull( Chess_Army_Knife_Standings::points_for( '2-0' ) );
	}

	public function test_scores_and_ranks_by_points() {
		$rows = Chess_Army_Knife_Standings::calculate(
			$this->entries( 3 ),
			array(
				$this->game( 1, 2, '1/2-1/2' ),
				$this->game( 2, 3, '1-0' ),
				$this->game( 3, 1, '0-1' ),
			)
		);

		$this->assertSame( array( 1, 2, 3 ), array_column( $rows, 'entry_id' ) );
		$this->assertSame( array( 1.5, 1.5, 0.0 ), array_column( $rows, 'points' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( $rows, 'rank' ) );
		$this->assertSame( 1, $rows[0]['won'] );
		$this->assertSame( 1, $rows[0]['drawn'] );
		$this->assertSame( 2, $rows[2]['lost'] ); // Player 3 lost both games.
	}

	public function test_unplayed_and_bye_games_are_ignored() {
		$rows = Chess_Army_Knife_Standings::calculate(
			$this->entries( 3 ),
			array(
				$this->game( 1, 2, null ),
				array(
					'white_entry_id' => 3,
					'black_entry_id' => null,
					'result'         => null,
					'is_bye'         => 1,
				),
			)
		);

		$this->assertSame( array( 0, 0, 0 ), array_column( $rows, 'played' ) );
	}

	public function test_head_to_head_breaks_a_tie_before_sonneborn_berger() {
		// Players 1 and 2 finish level on 1 point; 2 beat 1 directly.
		$rows = Chess_Army_Knife_Standings::calculate(
			$this->entries( 3 ),
			array(
				$this->game( 1, 2, '0-1' ),
				$this->game( 1, 3, '1-0' ),
				$this->game( 2, 3, '0-1' ),
			)
		);

		// Everyone has 1 point (3 beat 2, 1 beat 3, 2 beat 1): a three-way cycle,
		// so head-to-head is level and Sonneborn-Berger (all equal) then seed decides.
		$this->assertSame( array( 1, 2, 3 ), array_column( $rows, 'entry_id' ) );

		$rows = Chess_Army_Knife_Standings::calculate(
			$this->entries( 4 ),
			array(
				$this->game( 1, 2, '0-1' ), // 2 beats 1.
				$this->game( 1, 3, '1-0' ),
				$this->game( 1, 4, '1-0' ),
				$this->game( 2, 3, '0-1' ),
				$this->game( 2, 4, '1/2-1/2' ),
				$this->game( 3, 4, '1/2-1/2' ),
			)
		);
		// 1: 2 pts. 2: 1.5 pts. 3: 2 pts. 4: 1 pt.  1 and 3 tied on 2 points; 1 beat 3.
		$this->assertSame( array( 1, 3, 2, 4 ), array_column( $rows, 'entry_id' ) );
	}

	public function test_sonneborn_berger_breaks_remaining_ties() {
		// 1 and 2 both score 1.5; 1 has beaten the stronger opponent.
		$rows = Chess_Army_Knife_Standings::calculate(
			$this->entries( 4 ),
			array(
				$this->game( 1, 2, '1/2-1/2' ),
				$this->game( 1, 3, '1-0' ),
				$this->game( 1, 4, '0-1' ),
				$this->game( 2, 3, '0-1' ),
				$this->game( 2, 4, '1-0' ),
				$this->game( 3, 4, '1-0' ),
			)
		);

		// Players 1 and 2 both have 1.5 and drew each other; 1's win over the leader gives a better SB.
		$this->assertSame( array( 3, 1, 2, 4 ), array_column( $rows, 'entry_id' ) );
		$this->assertGreaterThan( $rows[2]['sonneborn_berger'], $rows[1]['sonneborn_berger'] );
	}

	public function test_withdrawal_under_half_removes_player_and_their_games() {
		$rows = Chess_Army_Knife_Standings::calculate(
			$this->entries( 3, array( 3 ) ),
			array(
				$this->game( 1, 2, '1/2-1/2' ),
				$this->game( 3, 1, '0-1' ), // Player 3 played 1 of 2 games (50%): kept.
			),
			array(
				1 => 2,
				2 => 2,
				3 => 2,
			)
		);
		$this->assertCount( 3, $rows );

		$rows = Chess_Army_Knife_Standings::calculate(
			$this->entries( 4, array( 4 ) ),
			array(
				$this->game( 1, 2, '1-0' ),
				$this->game( 4, 1, '0-1' ), // Player 4 played 1 of 3 games: dropped, and 1's win is discarded.
			),
			array(
				1 => 3,
				2 => 3,
				3 => 3,
				4 => 3,
			)
		);
		$this->assertSame( array( 1, 2, 3 ), array_column( $rows, 'entry_id' ) );
		$this->assertSame( 1.0, $rows[0]['points'] );
	}
}
