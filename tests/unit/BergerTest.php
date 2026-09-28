<?php
/**
 * Tests for Chess_Army_Knife_Berger against the FIDE Annex 1 tables.
 *
 * @package Chess_Army_Knife
 */

class BergerTest extends Chess_Army_Knife_TestCase {

	/**
	 * Format rounds like the annex: "1-8, 2-7, ...".
	 */
	private function format( array $rounds ) {
		return array_map(
			function ( $round ) {
				return implode(
					', ',
					array_map(
						function ( $pair ) {
							return $pair[0] . '-' . $pair[1];
						},
						$round
					)
				);
			},
			$rounds
		);
	}

	public function annex_sizes() {
		$tables = require dirname( __DIR__ ) . '/fixtures/berger-annex.php';
		$cases  = array();
		foreach ( array_keys( $tables ) as $size ) {
			$cases[ "{$size} players" ] = array( $size );
		}
		return $cases;
	}

	/**
	 * @dataProvider annex_sizes
	 */
	public function test_even_sizes_match_the_annex_tables( $size ) {
		$tables = require dirname( __DIR__ ) . '/fixtures/berger-annex.php';

		$this->assertSame( $tables[ $size ], $this->format( Chess_Army_Knife_Berger::rounds( $size ) ) );
	}

	public function test_odd_sizes_use_the_next_table_with_the_highest_number_as_bye() {
		$this->assertSame( Chess_Army_Knife_Berger::rounds( 6 ), Chess_Army_Knife_Berger::rounds( 5 ) );
	}

	public function test_every_pair_of_players_meets_exactly_once() {
		foreach ( array( 2, 3, 5, 8, 11, 16, 20 ) as $players ) {
			$seen = array();
			foreach ( Chess_Army_Knife_Berger::rounds( $players ) as $round ) {
				$in_round = array();
				foreach ( $round as $pair ) {
					$key = min( $pair ) . '-' . max( $pair );
					$this->assertArrayNotHasKey( $key, $seen, "{$players} players: repeated {$key}" );
					$seen[ $key ] = true;
					foreach ( $pair as $player ) {
						$this->assertArrayNotHasKey( $player, $in_round, "{$players} players: player twice in a round" );
						$in_round[ $player ] = true;
					}
				}
			}
			$size = $players + ( $players % 2 );
			$this->assertCount( $size * ( $size - 1 ) / 2, $seen, "{$players} players" );
		}
	}

	public function test_too_few_players_gives_no_rounds() {
		$this->assertSame( array(), Chess_Army_Knife_Berger::rounds( 1 ) );
		$this->assertSame( array(), Chess_Army_Knife_Berger::rounds( 0 ) );
	}

	public function test_double_round_robin_reverses_colours_and_swaps_last_two_rounds() {
		$single = Chess_Army_Knife_Berger::rounds( 4 );
		$double = Chess_Army_Knife_Berger::rounds( 4, true );

		$this->assertCount( 6, $double );
		$this->assertSame( $single[0], $double[0] );
		$this->assertSame( $single[2], $double[1] );
		$this->assertSame( $single[1], $double[2] );
		$this->assertSame( array( array( 4, 1 ), array( 3, 2 ) ), $double[3] );
	}
}
