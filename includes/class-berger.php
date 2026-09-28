<?php
/**
 * Berger tables for round-robin pairings (FIDE General Regulations, Annex 1).
 *
 * The tables are generated rather than stored: the highest-numbered player
 * is fixed, and the others rotate. Players are numbered 1..n by seed. With an
 * odd number of players the highest number is a bye.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Berger {

	/**
	 * Build the rounds for a round-robin.
	 *
	 * @param int  $players Number of players (2 or more).
	 * @param bool $double  Whether to play two cycles (colours reversed in the second).
	 * @return array[] Rounds; each round is a list of [ white, black ] pairs. A pair
	 *                 containing a number above $players is a bye for the other player.
	 */
	public static function rounds( $players, $double = false ) {
		$players = (int) $players;
		if ( $players < 2 ) {
			return array();
		}

		$size   = $players + ( $players % 2 );
		$cycle  = self::single_cycle( $size );
		$rounds = $cycle;

		if ( $double ) {
			// Reverse the last two rounds of the first cycle to avoid three
			// consecutive games with the same colour (Annex 1 recommendation).
			$count = count( $cycle );
			if ( $count >= 2 ) {
				$swap                = $cycle[ $count - 1 ];
				$cycle[ $count - 1 ] = $cycle[ $count - 2 ];
				$cycle[ $count - 2 ] = $swap;
			}
			$rounds = $cycle;
			foreach ( $cycle as $round ) {
				$reversed = array();
				foreach ( $round as $pair ) {
					$reversed[] = array( $pair[1], $pair[0] );
				}
				$rounds[] = $reversed;
			}
		}

		return $rounds;
	}

	/**
	 * One cycle of an even-sized table, in the order printed in Annex 1.
	 *
	 * @param int $size Even number of players.
	 * @return array[]
	 */
	protected static function single_cycle( $size ) {
		$rounds = array();
		$modulo = $size - 1;
		$half   = $size / 2;

		for ( $round = 1; $round <= $modulo; $round++ ) {
			// The opponent of the fixed (highest) player this round.
			$opponent = ( 1 === $round % 2 ) ? ( $round + 1 ) / 2 : $half + $round / 2;

			// The fixed player has Black in odd rounds and White in even rounds.
			$pairs = array( ( 1 === $round % 2 ) ? array( $opponent, $size ) : array( $size, $opponent ) );

			// Other players pair up so that both numbers sum to twice the opponent (mod size - 1);
			// the one closer after the opponent (going forwards) has White.
			for ( $distance = 1; $distance < $half; $distance++ ) {
				$white   = self::wrap( $opponent + $distance, $modulo );
				$black   = self::wrap( $opponent - $distance, $modulo );
				$pairs[] = array( $white, $black );
			}

			$rounds[] = $pairs;
		}

		return $rounds;
	}

	/**
	 * Wrap a number into 1..$modulo.
	 *
	 * @param int $number Any integer.
	 * @param int $modulo Upper bound.
	 * @return int
	 */
	protected static function wrap( $number, $modulo ) {
		return ( ( $number - 1 ) % $modulo + $modulo ) % $modulo + 1;
	}
}
