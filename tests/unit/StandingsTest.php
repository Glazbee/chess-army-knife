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

	public function test_forfeit_results_score_like_wins_but_do_not_feed_tie_breaks() {
		$this->assertSame( array( 1.0, 0.0 ), Chess_Army_Knife_Standings::points_for( '+-' ) );
		$this->assertSame( array( 0.0, 1.0 ), Chess_Army_Knife_Standings::points_for( '-+' ) );
		$this->assertTrue( Chess_Army_Knife_Standings::is_forfeit( '+-' ) );
		$this->assertFalse( Chess_Army_Knife_Standings::is_forfeit( '1-0' ) );

		$rows = Chess_Army_Knife_Standings::calculate(
			$this->entries( 2 ),
			array( $this->game( 1, 2, '+-' ) ),
			array(),
			array( 'swiss' => true )
		);

		$this->assertSame( 1.0, $rows[0]['points'] );
		$this->assertSame( 1, $rows[0]['won'] );
		$this->assertSame( 0.0, $rows[0]['buchholz'] );
		$this->assertSame( 0.0, $rows[0]['sonneborn_berger'] );
	}

	public function test_swiss_byes_score_the_configured_points() {
		$bye  = array(
			'white_entry_id' => 3,
			'black_entry_id' => null,
			'result'         => null,
			'is_bye'         => 1,
		);
		$rows = Chess_Army_Knife_Standings::calculate(
			$this->entries( 3 ),
			array( $this->game( 1, 2, '1/2-1/2' ), $bye ),
			array(),
			array(
				'swiss'      => true,
				'bye_points' => 1.0,
			)
		);

		$this->assertSame( 3, $rows[0]['entry_id'] ); // 1 point beats two draws' half points.
		$this->assertSame( 1.0, $rows[0]['points'] );

		// Without bye points (round-robin) a bye scores nothing.
		$rows = Chess_Army_Knife_Standings::calculate( $this->entries( 3 ), array( $bye ) );
		$this->assertSame( 0.0, $rows[0]['points'] );
	}

	public function test_swiss_ranks_by_points_then_buchholz_then_sonneborn_berger() {
		// Players 1 and 2 both score 1; player 1 beat the stronger opponent, so has the better Buchholz.
		$games = array(
			$this->game( 1, 3, '1-0' ),
			$this->game( 2, 4, '1-0' ),
			$this->game( 3, 5, '1-0' ),
			$this->game( 6, 4, '1-0' ),
		);
		$rows  = Chess_Army_Knife_Standings::calculate( $this->entries( 6 ), $games, array(), array( 'swiss' => true ) );

		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ $row['entry_id'] ] = $row;
		}
		// Player 1 (beat 3, who scored 1) outranks player 2 (beat 4, who scored 0).
		$this->assertGreaterThan( $by_id[2]['buchholz'], $by_id[1]['buchholz'] );
		$this->assertLessThan( $by_id[2]['rank'], $by_id[1]['rank'] );
	}
}
