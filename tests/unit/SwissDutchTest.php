<?php
/**
 * Tests for the FIDE (Dutch) system pairing engine.
 *
 * @package Chess_Army_Knife
 */

class SwissDutchTest extends Chess_Army_Knife_TestCase {

	/**
	 * Pair a round for a list of player ids in pairing-number order.
	 */
	private function pair( array $players, array $rounds = array(), $total = 5, array $active = null ) {
		return Chess_Army_Knife_Swiss_Dutch::pair( $players, null === $active ? $players : $active, $rounds, $total );
	}

	private function labels( array $result ) {
		$labels = array();
		foreach ( $result['pairings'] as $pair ) {
			$labels[] = $pair[0] . '>' . $pair[1];
		}
		sort( $labels );
		return $labels;
	}

	public function test_first_round_pairs_top_half_against_bottom_half_with_alternating_colours() {
		$result = $this->pair( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h' ) );

		// 1v5, 2v6, 3v7, 4v8. The higher seed of an odd-numbered board gets White, of an even one Black.
		$this->assertSame( array( 'a>e', 'f>b', 'c>g', 'h>d' ), $this->sorted_by_first( $result ) );
		$this->assertNull( $result['bye'] );
		$this->assertNull( $result['error'] );
	}

	/**
	 * Pairings in board order (by the seed of the higher-ranked player).
	 */
	private function sorted_by_first( array $result ) {
		$order  = array_flip( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j' ) );
		$labels = array();
		foreach ( $result['pairings'] as $pair ) {
			$top            = min( $order[ $pair[0] ], $order[ $pair[1] ] );
			$labels[ $top ] = $pair[0] . '>' . $pair[1];
		}
		ksort( $labels );
		return array_values( $labels );
	}

	public function test_an_odd_field_gives_the_bye_to_the_lowest_ranked_player_in_round_one() {
		$result = $this->pair( array( 'a', 'b', 'c', 'd', 'e' ) );

		$this->assertSame( 'e', $result['bye'] );
		$this->assertCount( 2, $result['pairings'] );
	}

	public function test_a_player_never_gets_a_second_pairing_allocated_bye() {
		$rounds = array(
			array(
				'games' => array(
					array(
						'white'  => 'a',
						'black'  => 'c',
						'result' => '1-0',
					),
					array(
						'white'  => 'd',
						'black'  => 'b',
						'result' => '1-0',
					),
				),
				'byes'  => array(
					array(
						'player' => 'e',
						'kind'   => 'pairing',
					),
				),
			),
		);

		$result = $this->pair( array( 'a', 'b', 'c', 'd', 'e' ), $rounds );

		$this->assertNotSame( 'e', $result['bye'] );
		$this->assertNotNull( $result['bye'] );
	}

	public function test_players_who_have_met_are_not_paired_again() {
		$rounds = array(
			array(
				'games' => array(
					array(
						'white'  => 'a',
						'black'  => 'c',
						'result' => '1-0',
					),
					array(
						'white'  => 'b',
						'black'  => 'd',
						'result' => '1-0',
					),
				),
				'byes'  => array(),
			),
		);

		$result = $this->pair( array( 'a', 'b', 'c', 'd' ), $rounds );

		// Winners a and b meet, and so do losers c and d.
		$this->assertSame( array( 'a>b', 'c>d' ), $this->labels_by_pair( $result, array( 'a', 'b', 'c', 'd' ) ) );
	}

	/**
	 * Pairings as "higher>lower" by the given order, ignoring colours.
	 */
	private function labels_by_pair( array $result, array $order ) {
		$order  = array_flip( $order );
		$labels = array();
		foreach ( $result['pairings'] as $pair ) {
			$labels[] = $order[ $pair[0] ] < $order[ $pair[1] ] ? $pair[0] . '>' . $pair[1] : $pair[1] . '>' . $pair[0];
		}
		sort( $labels );
		return $labels;
	}

	public function test_a_tournament_that_cannot_be_completed_reports_an_error() {
		// Three players who have all played each other cannot be paired again.
		$rounds = array(
			array(
				'games' => array(
					array(
						'white'  => 'a',
						'black'  => 'b',
						'result' => '1-0',
					),
				),
				'byes'  => array(
					array(
						'player' => 'c',
						'kind'   => 'pairing',
					),
				),
			),
			array(
				'games' => array(
					array(
						'white'  => 'a',
						'black'  => 'c',
						'result' => '1-0',
					),
				),
				'byes'  => array(
					array(
						'player' => 'b',
						'kind'   => 'pairing',
					),
				),
			),
			array(
				'games' => array(
					array(
						'white'  => 'b',
						'black'  => 'c',
						'result' => '1-0',
					),
				),
				'byes'  => array(
					array(
						'player' => 'a',
						'kind'   => 'pairing',
					),
				),
			),
		);

		$result = $this->pair( array( 'a', 'b', 'c' ), $rounds );

		$this->assertSame( 'no_complete_pairing', $result['error'] );
		$this->assertSame( array(), $result['pairings'] );
	}

	public function test_withdrawn_players_are_left_out_of_the_pairing() {
		$rounds = array(
			array(
				'games' => array(
					array(
						'white'  => 'a',
						'black'  => 'e',
						'result' => '1-0',
					),
					array(
						'white'  => 'f',
						'black'  => 'b',
						'result' => '0-1',
					),
					array(
						'white'  => 'c',
						'black'  => 'g',
						'result' => '1-0',
					),
					array(
						'white'  => 'h',
						'black'  => 'd',
						'result' => '0-1',
					),
				),
				'byes'  => array(),
			),
		);

		$result = $this->pair( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h' ), $rounds, 5, array( 'a', 'b', 'c', 'd', 'e', 'f', 'g' ) );

		$paired = array();
		foreach ( $result['pairings'] as $pair ) {
			$paired = array_merge( $paired, $pair );
		}
		$this->assertNotContains( 'h', $paired );
		$this->assertNotNull( $result['bye'] );
		$this->assertCount( 7, array_merge( $paired, array( $result['bye'] ) ) );
	}

	public function test_a_single_player_gets_the_bye_and_nobody_gets_no_pairing() {
		$this->assertSame( 'a', $this->pair( array( 'a' ) )['bye'] );
		$this->assertSame( array(), $this->pair( array() )['pairings'] );
	}

	public function golden_cases() {
		$cases = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/swiss-dutch-golden.json' ), true );
		$out   = array();
		foreach ( $cases as $case ) {
			$out[ $case['id'] ] = array( $case );
		}
		return $out;
	}

	/**
	 * Every golden case was paired identically by this engine and by an independent
	 * implementation of the FIDE Dutch rules (@echecs/swiss, MIT licensed), which is
	 * itself checked against bbpPairings. See tests/fixtures/README.md.
	 *
	 * @dataProvider golden_cases
	 */
	public function test_matches_the_independent_reference_implementation( array $case ) {
		$result = Chess_Army_Knife_Swiss_Dutch::pair( $case['players'], $case['active'], $case['rounds'], $case['total_rounds'] );

		$this->assertNull( $result['error'] );
		$this->assertSame( $case['pairings'], $this->labels( $result ), $case['id'] );
		$this->assertSame( $case['bye'], $result['bye'], $case['id'] . ' bye' );
	}

	public function forfeit_cases() {
		$cases = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/swiss-dutch-forfeits.json' ), true );
		$out   = array();
		foreach ( $cases as $case ) {
			$out[ $case['id'] ] = array( $case );
		}
		return $out;
	}

	/**
	 * Rounds that follow forfeits and half-point or zero-point byes, paired identically by this engine
	 * and by @echecs/swiss (see tests/fixtures/README.md).
	 *
	 * @dataProvider forfeit_cases
	 */
	public function test_rounds_after_forfeits_and_requested_byes_match_the_reference_implementation( array $case ) {
		$result = Chess_Army_Knife_Swiss_Dutch::pair( $case['players'], $case['active'], $case['rounds'], $case['total_rounds'] );

		$this->assertNull( $result['error'] );
		$this->assertSame( $case['pairings'], $this->labels( $result ), $case['id'] );
		$this->assertSame( $case['bye'], $result['bye'], $case['id'] . ' bye' );
	}

	public function test_players_whose_game_was_forfeited_may_be_paired_again() {
		// Every other pairing has been played, and the only games left to repeat were forfeited.
		$rounds = array(
			array(
				'games' => array(
					array(
						'white'  => 'p1',
						'black'  => 'p3',
						'result' => '1-0',
					),
					array(
						'white'  => 'p4',
						'black'  => 'p2',
						'result' => '0-1',
					),
				),
				'byes'  => array(),
			),
			array(
				'games' => array(
					array(
						'white'   => 'p2',
						'black'   => 'p1',
						'result'  => '1-0',
						'forfeit' => 'black',
					),
					array(
						'white'   => 'p3',
						'black'   => 'p4',
						'result'  => '1-0',
						'forfeit' => 'black',
					),
				),
				'byes'  => array(),
			),
		);

		$result = $this->pair( array( 'p1', 'p2', 'p3', 'p4' ), $rounds, 3 );

		$this->assertNull( $result['error'] );
		$this->assertSame( array( 'p2>p1', 'p3>p4' ), $this->labels( $result ) );
	}

	public function large_cases() {
		$cases = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/swiss-dutch-large.json' ), true );
		$out   = array();
		foreach ( $cases as $case ) {
			$out[ $case['id'] ] = array( $case );
		}
		return $out;
	}

	/**
	 * Rounds in fields of 44 to 64 players with a score group of 17 to 19 players, larger than the
	 * exact optimisation handles directly. The expected pairings come from the exact search. See
	 * tests/fixtures/README.md.
	 *
	 * @dataProvider large_cases
	 */
	public function test_large_score_groups_are_paired_as_the_exact_search_pairs_them( array $case ) {
		set_time_limit( 120 );

		$result = Chess_Army_Knife_Swiss_Dutch::pair( $case['players'], $case['active'], $case['rounds'], $case['total_rounds'] );

		$this->assertNull( $result['error'] );
		$this->assertFalse( $result['truncated'], $case['id'] . ' was cut short' );
		$this->assertSame( $case['pairings'], $this->labels( $result ), $case['id'] );
		$this->assertSame( $case['bye'], $result['bye'], $case['id'] . ' bye' );
	}

	/**
	 * Simulate whole tournaments and check the rules that must always hold.
	 */
	public function test_simulated_tournaments_always_produce_legal_pairings() {
		mt_srand( 4242 );

		for ( $run = 0; $run < 25; $run++ ) {
			$count   = mt_rand( 4, 15 );
			$rounds  = mt_rand( 3, min( 7, $count - 1 ) );
			$players = array();
			for ( $i = 1; $i <= $count; $i++ ) {
				$players[] = 'p' . $i;
			}

			$history = array();
			$met     = array();
			$byes    = array();

			for ( $round = 1; $round <= $rounds; $round++ ) {
				$result = Chess_Army_Knife_Swiss_Dutch::pair( $players, $players, $history, $rounds );

				if ( null !== $result['error'] ) {
					break; // A round that genuinely cannot be completed; nothing to check.
				}

				$seen = array();
				foreach ( $result['pairings'] as $pair ) {
					$this->assertArrayNotHasKey( $pair[0] . '|' . $pair[1], $met, "run {$run} round {$round}: repeat pairing" );
					$met[ $pair[0] . '|' . $pair[1] ] = true;
					$met[ $pair[1] . '|' . $pair[0] ] = true;
					foreach ( $pair as $player ) {
						$this->assertArrayNotHasKey( $player, $seen, "run {$run} round {$round}: player twice" );
						$seen[ $player ] = true;
					}
				}
				if ( null !== $result['bye'] ) {
					$this->assertArrayNotHasKey( $result['bye'], $seen );
					$this->assertArrayNotHasKey( $result['bye'], $byes, "run {$run} round {$round}: second bye" );
					$byes[ $result['bye'] ] = true;
					$seen[ $result['bye'] ] = true;
				}
				$this->assertCount( $count, $seen, "run {$run} round {$round}: not everyone was paired" );
				$this->assertSame( $count % 2, null === $result['bye'] ? 0 : 1 );

				$games = array();
				foreach ( $result['pairings'] as $pair ) {
					$roll    = mt_rand( 0, 9 );
					$games[] = array(
						'white'  => $pair[0],
						'black'  => $pair[1],
						'result' => $roll < 4 ? '1-0' : ( $roll < 8 ? '0-1' : '1/2-1/2' ),
					);
				}
				$history[] = array(
					'games' => $games,
					'byes'  => null === $result['bye'] ? array() : array(
						array(
							'player' => $result['bye'],
							'kind'   => 'pairing',
						),
					),
				);
			}
		}
	}
}
