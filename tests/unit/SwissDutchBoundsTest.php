<?php
/**
 * Tests for the bounds that keep the Swiss pairing search fast on large score groups.
 *
 * The bounds only prune the search, so they must never exceed the best a real pairing
 * can do: each test checks them against every possible pairing of a small bracket.
 *
 * @package Chess_Army_Knife
 */

require_once __DIR__ . '/SwissDutchProbe.php';

class SwissDutchBoundsTest extends Chess_Army_Knife_TestCase {

	private $seed = 12345;

	/**
	 * A repeatable pseudo-random number in [0, $max).
	 */
	private function roll( $max ) {
		$this->seed = ( $this->seed * 1103515245 + 12345 ) & 0x7fffffff;
		return intdiv( $this->seed >> 8, 1 ) % $max;
	}

	/**
	 * Play some rounds of a Swiss and return the history the engine expects.
	 */
	private function history( $players, $rounds, $total ) {
		$ids     = range( 1, $players );
		$history = array();
		for ( $r = 1; $r <= $rounds; $r++ ) {
			$result = Chess_Army_Knife_Swiss_Dutch::pair( $ids, $ids, $history, $total );
			$this->assertNull( $result['error'] );
			$games = array();
			foreach ( $result['pairings'] as $pair ) {
				$roll    = $this->roll( 100 );
				$games[] = array(
					'white'  => $pair[0],
					'black'  => $pair[1],
					'result' => $roll < 20 ? '1/2-1/2' : ( $roll < 65 ? '1-0' : '0-1' ),
				);
			}
			$byes      = null === $result['bye'] ? array() : array(
				array(
					'player' => $result['bye'],
					'kind'   => 'pairing',
				),
			);
			$history[] = array(
				'games' => $games,
				'byes'  => $byes,
			);
		}
		return $history;
	}

	private function probe( $players, $rounds ) {
		$probe = new Swiss_Dutch_Probe();
		$probe->prepare( range( 1, $players ), $this->history( $players, $rounds, 7 ), 7 );
		return $probe;
	}

	/**
	 * Fewest C12 and C13 over every way of pairing all of some players (leaving $unpaired out).
	 */
	private function best_colour_cost( Swiss_Dutch_Probe $probe, array $players, $unpaired ) {
		$best = array( PHP_INT_MAX, PHP_INT_MAX );
		$this->match( $probe, $players, $unpaired, 0, 0, $best );
		return $best;
	}

	private function match( Swiss_Dutch_Probe $probe, array $players, $unpaired, $c12, $c13, array &$best ) {
		if ( count( $players ) === $unpaired ) {
			$best = array( min( $best[0], $c12 ), min( $best[1], $c13 ) );
			return;
		}
		$first = array_shift( $players );
		// The first player may stay out, if any are allowed to.
		if ( $unpaired > 0 ) {
			$this->match( $probe, $players, $unpaired - 1, $c12, $c13, $best );
		}
		foreach ( $players as $i => $second ) {
			if ( ! $probe->probe_can_pair( $first, $second ) ) {
				continue;
			}
			$parts = $probe->probe_components( $first, $second );
			$rest  = $players;
			unset( $rest[ $i ] );
			$this->match( $probe, array_values( $rest ), $unpaired, $c12 + $parts[2], $c13 + $parts[3], $best );
		}
	}

	public function test_the_colour_bound_never_exceeds_the_best_pairing() {
		$checked = 0;
		foreach ( array( 2, 3, 4 ) as $rounds ) {
			$probe = $this->probe( 24, $rounds );
			for ( $trial = 0; $trial < 12; $trial++ ) {
				// A bracket of 8 to 11 players, with 0 or 1 of them left out.
				$size     = 8 + $this->roll( 4 );
				$unpaired = $size % 2;
				$players  = array();
				while ( count( $players ) < $size ) {
					$players[ $this->roll( 24 ) ] = true;
				}
				$players = array_keys( $players );

				$best = $this->best_colour_cost( $probe, $players, $unpaired );
				if ( PHP_INT_MAX === $best[0] ) {
					continue; // Nobody can be paired: no bound to check.
				}
				$bound = $probe->probe_colour_bound( array(), $players, intdiv( $size - $unpaired, 2 ) );

				$this->assertLessThanOrEqual( $best[0], $bound[0], 'C12 bound, rounds ' . $rounds . ', ' . implode( ',', $players ) );
				$this->assertLessThanOrEqual( $best[1], $bound[1], 'C13 bound, rounds ' . $rounds . ', ' . implode( ',', $players ) );
				++$checked;
			}
		}
		$this->assertGreaterThan( 10, $checked );
	}

	/**
	 * Fewest C12 and C13 when each of $s1 takes a different partner from $s2.
	 */
	private function best_split_cost( Swiss_Dutch_Probe $probe, array $s1, array $s2, $c12 = 0, $c13 = 0 ) {
		if ( empty( $s1 ) ) {
			return array( $c12, $c13 );
		}
		$best   = array( PHP_INT_MAX, PHP_INT_MAX );
		$player = array_shift( $s1 );
		foreach ( $s2 as $i => $partner ) {
			if ( ! $probe->probe_can_pair( $player, $partner ) ) {
				continue;
			}
			$parts = $probe->probe_components( $player, $partner );
			$rest  = $s2;
			unset( $rest[ $i ] );
			$found = $this->best_split_cost( $probe, $s1, array_values( $rest ), $c12 + $parts[2], $c13 + $parts[3] );
			$best  = array( min( $best[0], $found[0] ), min( $best[1], $found[1] ) );
		}
		return $best;
	}

	public function test_the_split_bound_never_exceeds_the_best_partnering() {
		$checked = 0;
		foreach ( array( 2, 3, 4 ) as $rounds ) {
			$probe = $this->probe( 24, $rounds );
			for ( $trial = 0; $trial < 15; $trial++ ) {
				$pool = array();
				while ( count( $pool ) < 10 ) {
					$pool[ $this->roll( 24 ) ] = true;
				}
				$pool = array_keys( $pool );
				$s1   = array_slice( $pool, 0, 4 );
				$s2   = array_slice( $pool, 4, 6 );

				$best = $this->best_split_cost( $probe, $s1, $s2 );
				if ( PHP_INT_MAX === $best[0] ) {
					continue;
				}
				$bound = $probe->probe_split_bound( $s1, $s2 );

				$this->assertLessThanOrEqual( $best[0], $bound[0], 'C12 split bound, rounds ' . $rounds );
				$this->assertLessThanOrEqual( $best[1], $bound[1], 'C13 split bound, rounds ' . $rounds );
				++$checked;
			}
		}
		$this->assertGreaterThan( 10, $checked );
	}

	public function test_reaching_exchanges_are_the_exchanges_that_can_meet_the_aim_in_the_same_order() {
		$probe = $this->probe( 24, 3 );
		$list  = array();
		while ( count( $list ) < 10 ) {
			$list[ $this->roll( 24 ) ] = true;
		}
		$list = array_keys( $list );
		$s1   = range( 0, 4 );
		$s2   = range( 5, 9 );

		$compared = 0;
		foreach ( array( 1, 2, 3 ) as $size ) {
			foreach ( array( array( 0, 0 ), array( 1, 1 ), array( 2, 1 ), array( 3, 3 ) ) as $aim ) {
				$expected = array();
				foreach ( $probe->probe_exchanges( $list, $s1, $s2, $size ) as $exchange ) {
					$new_s1 = array_merge( array_diff( $s1, $exchange['a'] ), $exchange['b'] );
					$new_s2 = array_merge( array_diff( $s2, $exchange['b'] ), $exchange['a'] );
					sort( $new_s1 );
					sort( $new_s2 );
					$bound = $probe->probe_split_bound(
						array_map(
							function ( $position ) use ( $list ) {
								return $list[ $position ];
							},
							$new_s1
						),
						array_map(
							function ( $position ) use ( $list ) {
								return $list[ $position ];
							},
							$new_s2
						)
					);
					if ( $bound[0] <= $aim[0] && $bound[1] <= $aim[1] ) {
						$expected[] = $exchange;
					}
				}

				$this->assertSame( $expected, $probe->probe_exchanges( $list, $s1, $s2, $size, $aim ), "size {$size}, aim {$aim[0]},{$aim[1]}" );
				$compared += count( $expected );
			}
		}
		$this->assertGreaterThan( 0, $compared );
	}

	public function test_can_fill_agrees_with_trying_every_assignment() {
		$probe = $this->probe( 24, 4 );
		for ( $trial = 0; $trial < 25; $trial++ ) {
			$pool = array();
			while ( count( $pool ) < 9 ) {
				$pool[ $this->roll( 24 ) ] = true;
			}
			$pool  = array_keys( $pool );
			$left  = array_slice( $pool, 0, 4 );
			$right = array_slice( $pool, 4, 5 );

			$exists = PHP_INT_MAX !== $this->best_split_cost( $probe, $left, $right )[0];
			$this->assertSame( $exists, $probe->probe_can_fill( $left, $right ), implode( ',', $pool ) );
		}
	}

	/**
	 * Check a set of pairings covers everyone once, with nobody meeting twice.
	 */
	private function assert_valid_round( array $result, array $ids, array $history ) {
		$this->assertNull( $result['error'] );
		$seen = array();
		foreach ( $result['pairings'] as $pair ) {
			$seen[] = $pair[0];
			$seen[] = $pair[1];
			foreach ( $history as $round ) {
				foreach ( $round['games'] as $game ) {
					$this->assertFalse( array( $game['white'], $game['black'] ) === array( $pair[0], $pair[1] ) || array( $game['white'], $game['black'] ) === array( $pair[1], $pair[0] ), 'repeat pairing' );
				}
			}
		}
		if ( null !== $result['bye'] ) {
			$seen[] = $result['bye'];
		}
		sort( $seen );
		$this->assertSame( $ids, $seen );
	}

	public function test_a_large_second_round_is_paired_and_valid() {
		// Rounds like this one (a top score group of 17 or more) used to take minutes.
		set_time_limit( 120 );

		foreach ( array( 38, 44, 60 ) as $players ) {
			$this->seed = 99 + $players;
			$history    = $this->history( $players, 1, 7 );
			$ids        = range( 1, $players );

			$this->assert_valid_round( Chess_Army_Knife_Swiss_Dutch::pair( $ids, $ids, $history, 7 ), $ids, $history );
		}
	}

	public function test_a_large_field_is_paired_through_every_round() {
		set_time_limit( 120 );

		$players    = 50;
		$ids        = range( 1, $players );
		$history    = array();
		$this->seed = 4321;
		for ( $r = 1; $r <= 7; $r++ ) {
			$result = Chess_Army_Knife_Swiss_Dutch::pair( $ids, $ids, $history, 7 );
			$this->assert_valid_round( $result, $ids, $history );
			$games = array();
			foreach ( $result['pairings'] as $pair ) {
				$roll    = $this->roll( 100 );
				$games[] = array(
					'white'  => $pair[0],
					'black'  => $pair[1],
					'result' => $roll < 20 ? '1/2-1/2' : ( $roll < 65 ? '1-0' : '0-1' ),
				);
			}
			$history[] = array(
				'games' => $games,
				'byes'  => array(),
			);
		}
	}

	public function test_a_spent_search_budget_settles_for_a_valid_pairing() {
		$players    = 44;
		$ids        = range( 1, $players );
		$this->seed = 7;
		$history    = $this->history( $players, 1, 7 );

		$result = Chess_Army_Knife_Swiss_Dutch::pair( $ids, $ids, $history, 7, array( 'search_limit' => 1 ) );

		$this->assert_valid_round( $result, $ids, $history );
	}
}
