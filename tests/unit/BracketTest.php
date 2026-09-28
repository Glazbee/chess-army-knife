<?php
/**
 * Tests for Chess_Army_Knife_Bracket.
 *
 * @package Chess_Army_Knife
 */

class BracketTest extends Chess_Army_Knife_TestCase {

	public function test_size_and_round_count() {
		$this->assertSame( 2, Chess_Army_Knife_Bracket::size( 2 ) );
		$this->assertSame( 4, Chess_Army_Knife_Bracket::size( 3 ) );
		$this->assertSame( 8, Chess_Army_Knife_Bracket::size( 8 ) );
		$this->assertSame( 16, Chess_Army_Knife_Bracket::size( 9 ) );
		$this->assertSame( 1, Chess_Army_Knife_Bracket::round_count( 2 ) );
		$this->assertSame( 3, Chess_Army_Knife_Bracket::round_count( 6 ) );
		$this->assertSame( 4, Chess_Army_Knife_Bracket::round_count( 16 ) );
	}

	public function test_standard_seeding_order() {
		$this->assertSame( array( 1, 2 ), Chess_Army_Knife_Bracket::order( 2 ) );
		$this->assertSame( array( 1, 4, 2, 3 ), Chess_Army_Knife_Bracket::order( 4 ) );
		$this->assertSame( array( 1, 8, 4, 5, 2, 7, 3, 6 ), Chess_Army_Knife_Bracket::order( 8 ) );
		$this->assertSame( array( 1, 16, 8, 9, 4, 13, 5, 12, 2, 15, 7, 10, 3, 14, 6, 11 ), Chess_Army_Knife_Bracket::order( 16 ) );
	}

	public function test_top_two_seeds_can_only_meet_in_the_final() {
		foreach ( array( 4, 8, 16, 32 ) as $size ) {
			$order = Chess_Army_Knife_Bracket::order( $size );
			$half  = $size / 2;
			$this->assertLessThan( $half, array_search( 1, $order, true ) );
			$this->assertGreaterThanOrEqual( $half, array_search( 2, $order, true ) );
		}
	}

	public function test_eight_player_first_round_is_one_v_eight() {
		$rounds = Chess_Army_Knife_Bracket::build( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h' ) );

		$first = array_map(
			function ( $match ) {
				return $match['white'] . $match['black'];
			},
			$rounds[1]
		);
		$this->assertSame(
			array(
				1 => 'ah',
				2 => 'de',
				3 => 'bg',
				4 => 'cf',
			),
			$first
		);
		$this->assertCount( 4, $rounds[1] );
		$this->assertCount( 2, $rounds[2] );
		$this->assertCount( 1, $rounds[3] );
		$this->assertNull( $rounds[2][1]['white'] );
	}

	public function test_top_seeds_receive_byes_when_not_a_power_of_two() {
		$rounds = Chess_Army_Knife_Bracket::build( array( 'a', 'b', 'c', 'd', 'e', 'f' ) );

		// Seeds 1 and 2 have byes and are already in round 2.
		$this->assertTrue( $rounds[1][1]['bye'] );
		$this->assertSame( 'a', $rounds[1][1]['white'] );
		$this->assertTrue( $rounds[1][3]['bye'] );
		$this->assertSame( 'b', $rounds[1][3]['white'] );
		$this->assertSame( 'a', $rounds[2][1]['white'] );
		$this->assertSame( 'b', $rounds[2][2]['white'] );

		// Real round-1 games: 4v5 and 3v6.
		$this->assertSame( array( 'd', 'e' ), array( $rounds[1][2]['white'], $rounds[1][2]['black'] ) );
		$this->assertSame( array( 'c', 'f' ), array( $rounds[1][4]['white'], $rounds[1][4]['black'] ) );
	}

	public function test_three_players_give_seed_one_a_bye() {
		$rounds = Chess_Army_Knife_Bracket::build( array( 'a', 'b', 'c' ) );

		$this->assertTrue( $rounds[1][1]['bye'] );
		$this->assertSame( 'a', $rounds[2][1]['white'] );
		$this->assertSame( array( 'b', 'c' ), array( $rounds[1][2]['white'], $rounds[1][2]['black'] ) );
	}

	public function test_two_players_play_a_single_final() {
		$rounds = Chess_Army_Knife_Bracket::build( array( 'a', 'b' ) );

		$this->assertCount( 1, $rounds );
		$this->assertSame( array( 'a', 'b' ), array( $rounds[1][1]['white'], $rounds[1][1]['black'] ) );
	}

	public function test_too_few_players_gives_no_bracket() {
		$this->assertSame( array(), Chess_Army_Knife_Bracket::build( array( 'a' ) ) );
	}

	public function test_winners_feed_the_next_round_alternating_colours() {
		$this->assertSame(
			array(
				'board' => 1,
				'slot'  => 'white',
			),
			Chess_Army_Knife_Bracket::parent( 1 )
		);
		$this->assertSame(
			array(
				'board' => 1,
				'slot'  => 'black',
			),
			Chess_Army_Knife_Bracket::parent( 2 )
		);
		$this->assertSame(
			array(
				'board' => 3,
				'slot'  => 'white',
			),
			Chess_Army_Knife_Bracket::parent( 5 )
		);
	}

	private function game( $white, $black, $result ) {
		return array(
			'white_entry_id' => $white,
			'black_entry_id' => $black,
			'result'         => $result,
		);
	}

	public function test_tie_winner_is_the_first_decisive_game() {
		$this->assertNull( Chess_Army_Knife_Bracket::tie_winner( array() ) );
		$this->assertNull( Chess_Army_Knife_Bracket::tie_winner( array( $this->game( 1, 2, null ) ) ) );
		$this->assertSame( 1, Chess_Army_Knife_Bracket::tie_winner( array( $this->game( 1, 2, '1-0' ) ) ) );
		$this->assertSame( 2, Chess_Army_Knife_Bracket::tie_winner( array( $this->game( 1, 2, '0-1' ) ) ) );

		// A draw needs a tie-break; colours are reversed in it.
		$this->assertNull( Chess_Army_Knife_Bracket::tie_winner( array( $this->game( 1, 2, '1/2-1/2' ), $this->game( 2, 1, null ) ) ) );
		$this->assertSame( 1, Chess_Army_Knife_Bracket::tie_winner( array( $this->game( 1, 2, '1/2-1/2' ), $this->game( 2, 1, '0-1' ) ) ) );
	}

	public function test_round_names() {
		$this->assertSame( 'Final', Chess_Army_Knife_Bracket::round_name( 3, 3 ) );
		$this->assertSame( 'Semi-finals', Chess_Army_Knife_Bracket::round_name( 2, 3 ) );
		$this->assertSame( 'Quarter-finals', Chess_Army_Knife_Bracket::round_name( 1, 3 ) );
		$this->assertSame( 'Round of 16', Chess_Army_Knife_Bracket::round_name( 1, 4 ) );
	}

	/**
	 * Build qualifiers for $groups groups each sending $advance players.
	 */
	private function qualifiers( $groups, $advance ) {
		$out = array();
		$id  = 1;
		for ( $group = 1; $group <= $groups; $group++ ) {
			for ( $place = 1; $place <= $advance; $place++ ) {
				$out[] = array(
					'id'       => $id++,
					'group'    => $group,
					'place'    => $place,
					'points'   => (float) ( 10 - $place - $group * 0.1 ),
					'tiebreak' => 0.0,
					'seed'     => $id,
				);
			}
		}
		return $out;
	}

	private function group_of( array $qualifiers, $id ) {
		foreach ( $qualifiers as $q ) {
			if ( $q['id'] === $id ) {
				return $q['group'];
			}
		}
		return null;
	}

	public function test_group_winners_take_the_top_seeds() {
		$qualifiers = $this->qualifiers( 4, 2 );
		$seeded     = Chess_Army_Knife_Bracket::seed_qualifiers( $qualifiers );

		$top_four_places = array();
		foreach ( array_slice( $seeded, 0, 4 ) as $id ) {
			foreach ( $qualifiers as $q ) {
				if ( $q['id'] === $id ) {
					$top_four_places[] = $q['place'];
				}
			}
		}
		$this->assertSame( array( 1, 1, 1, 1 ), $top_four_places );
		// Winners keep their earned order (best record first).
		$this->assertSame( array( 1, 3, 5, 7 ), array_slice( $seeded, 0, 4 ) );
	}

	public function test_no_first_round_rematches_between_group_mates() {
		foreach ( array( array( 4, 2 ), array( 2, 2 ), array( 3, 2 ), array( 4, 4 ), array( 8, 2 ), array( 2, 4 ) ) as $case ) {
			list( $groups, $advance ) = $case;
			$qualifiers               = $this->qualifiers( $groups, $advance );
			$seeded                   = Chess_Army_Knife_Bracket::seed_qualifiers( $qualifiers );

			$this->assertCount( $groups * $advance, $seeded );
			$this->assertCount( $groups * $advance, array_unique( $seeded ) );

			$rounds = Chess_Army_Knife_Bracket::build( $seeded );
			foreach ( $rounds[1] as $match ) {
				if ( $match['bye'] ) {
					continue;
				}
				$this->assertNotSame(
					$this->group_of( $qualifiers, $match['white'] ),
					$this->group_of( $qualifiers, $match['black'] ),
					"{$groups} groups x {$advance}: same-group first-round match"
				);
			}
		}
	}

	public function test_group_mates_are_placed_in_opposite_halves_when_possible() {
		$qualifiers = $this->qualifiers( 4, 2 );
		$seeded     = Chess_Army_Knife_Bracket::seed_qualifiers( $qualifiers );
		$rounds     = Chess_Army_Knife_Bracket::build( $seeded );

		// Which half of the bracket (boards 1-2 or 3-4 in round 1) each player is in.
		$half_of = array();
		foreach ( $rounds[1] as $board => $match ) {
			$half_of[ $match['white'] ] = $board <= 2 ? 0 : 1;
			$half_of[ $match['black'] ] = $board <= 2 ? 0 : 1;
		}
		for ( $group = 1; $group <= 4; $group++ ) {
			$members = array();
			foreach ( $qualifiers as $q ) {
				if ( $group === $q['group'] ) {
					$members[] = $half_of[ $q['id'] ];
				}
			}
			sort( $members );
			$this->assertSame( array( 0, 1 ), $members, "group {$group} should be split across halves" );
		}
	}

	public function test_a_single_group_cannot_avoid_rematches_but_still_returns_everyone() {
		$seeded = Chess_Army_Knife_Bracket::seed_qualifiers( $this->qualifiers( 1, 4 ) );

		$this->assertCount( 4, $seeded );
	}
}
