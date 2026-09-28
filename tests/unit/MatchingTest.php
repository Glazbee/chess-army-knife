<?php
/**
 * Tests for Chess_Army_Knife_Matching (Edmonds' blossom algorithm).
 *
 * @package Chess_Army_Knife
 */

class MatchingTest extends Chess_Army_Knife_TestCase {

	/**
	 * Slow but obviously correct maximum matching, for comparison.
	 */
	private function brute_force( array $adjacency ) {
		$count = count( $adjacency );
		$full  = ( 1 << $count ) - 1;
		$solve = function ( $mask ) use ( &$solve, $adjacency, $count, $full ) {
			if ( $mask === $full ) {
				return 0;
			}
			for ( $i = 0; $i < $count; $i++ ) {
				if ( ! ( $mask & ( 1 << $i ) ) ) {
					break;
				}
			}
			$best = $solve( $mask | ( 1 << $i ) );
			foreach ( $adjacency[ $i ] as $j ) {
				if ( ! ( $mask & ( 1 << $j ) ) ) {
					$best = max( $best, 1 + $solve( $mask | ( 1 << $i ) | ( 1 << $j ) ) );
				}
			}
			return $best;
		};
		return $solve( 0 );
	}

	public function test_agrees_with_brute_force_on_random_graphs() {
		mt_srand( 99 );
		for ( $run = 0; $run < 400; $run++ ) {
			$count     = mt_rand( 1, 10 );
			$density   = mt_rand( 10, 70 ) / 100;
			$adjacency = array_fill( 0, $count, array() );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( mt_rand( 0, 99 ) / 100 < $density ) {
						$adjacency[ $i ][] = $j;
						$adjacency[ $j ][] = $i;
					}
				}
			}

			$this->assertSame( $this->brute_force( $adjacency ), Chess_Army_Knife_Matching::max_matching( $adjacency ), "run {$run}" );
		}
	}

	public function test_odd_cycles_are_handled() {
		// A triangle with a pendant edge: the blossom case a bipartite algorithm gets wrong.
		$adjacency = array(
			array( 1, 2 ),
			array( 0, 2 ),
			array( 0, 1, 3 ),
			array( 2 ),
		);

		$this->assertSame( 2, Chess_Army_Knife_Matching::max_matching( $adjacency ) );
		$this->assertTrue( Chess_Army_Knife_Matching::has_perfect( $adjacency ) );
	}

	public function test_perfect_matching_needs_an_even_number_of_vertices() {
		$this->assertFalse( Chess_Army_Knife_Matching::has_perfect( array( array( 1 ), array( 0 ), array() ) ) );
		$this->assertTrue( Chess_Army_Knife_Matching::has_perfect( array() ) );
		$this->assertFalse( Chess_Army_Knife_Matching::has_perfect( array( array( 1 ), array( 0 ), array(), array() ) ) );
	}
}
