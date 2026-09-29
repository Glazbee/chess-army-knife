<?php
/**
 * Single-elimination bracket logic.
 *
 * Pure functions with no WordPress dependencies. Players are identified by
 * an id and passed in seed order (index 0 = seed 1).
 *
 * Standard seeding is used: in a bracket of 8 the first round is 1v8, 4v5,
 * 2v7 and 3v6, so the top seeds meet only in the later rounds and seeds 1
 * and 2 can only meet in the final. Where the field is not a power of two,
 * the top seeds receive first-round byes.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Bracket {

	/**
	 * Smallest power of two that holds the given number of players.
	 *
	 * @param int $players Number of players.
	 * @return int
	 */
	public static function size( $players ) {
		$size = 2;
		while ( $size < $players ) {
			$size *= 2;
		}
		return $size;
	}

	/**
	 * Number of knockout rounds for a field.
	 *
	 * @param int $players Number of players (2 or more).
	 * @return int
	 */
	public static function round_count( $players ) {
		return (int) round( log( self::size( $players ), 2 ) );
	}

	/**
	 * Seed numbers in bracket order: consecutive pairs meet in round 1.
	 *
	 * @param int $size Bracket size (power of two).
	 * @return int[] E.g. 8 => 1, 8, 4, 5, 2, 7, 3, 6.
	 */
	public static function order( $size ) {
		$order = array( 1, 2 );
		$count = 2;
		while ( $count < $size ) {
			$next = $count * 2;
			$new  = array();
			foreach ( $order as $seed ) {
				$new[] = $seed;
				$new[] = $next + 1 - $seed;
			}
			$order = $new;
			$count = $next;
		}
		return $order;
	}

	/**
	 * Build the rounds of a bracket.
	 *
	 * Round 1 byes are advanced straight into round 2. Later rounds are
	 * created empty and filled as winners are decided.
	 *
	 * @param array $seeded Player ids in seed order (at least 2).
	 * @return array[] rounds[ round ][ board ] = { white, black, bye } (1-based keys); white/black are ids or null.
	 */
	public static function build( array $seeded ) {
		$seeded = array_values( $seeded );
		$count  = count( $seeded );
		if ( $count < 2 ) {
			return array();
		}

		$size   = self::size( $count );
		$total  = self::round_count( $count );
		$order  = self::order( $size );
		$rounds = array();

		for ( $round = 1; $round <= $total; $round++ ) {
			$boards = $size / pow( 2, $round );
			for ( $board = 1; $board <= $boards; $board++ ) {
				$rounds[ $round ][ $board ] = array(
					'white' => null,
					'black' => null,
					'bye'   => false,
				);
			}
		}

		for ( $board = 1; $board <= $size / 2; $board++ ) {
			$high = $order[ ( $board - 1 ) * 2 ];
			$low  = $order[ ( $board - 1 ) * 2 + 1 ];

			$high_id = isset( $seeded[ $high - 1 ] ) ? $seeded[ $high - 1 ] : null;
			$low_id  = isset( $seeded[ $low - 1 ] ) ? $seeded[ $low - 1 ] : null;

			if ( null !== $high_id && null !== $low_id ) {
				$rounds[1][ $board ]['white'] = $high_id;
				$rounds[1][ $board ]['black'] = $low_id;
			} else {
				// A bye: the present player advances without playing.
				$rounds[1][ $board ]['white'] = null !== $high_id ? $high_id : $low_id;
				$rounds[1][ $board ]['bye']   = true;

				if ( $total >= 2 ) {
					$parent = self::parent( $board );
					$rounds[2][ $parent['board'] ][ $parent['slot'] ] = $rounds[1][ $board ]['white'];
				}
			}
		}

		return $rounds;
	}

	/**
	 * Where the winner of a board goes in the next round.
	 *
	 * @param int $board Board number in its round (1-based).
	 * @return array { board: int, slot: 'white'|'black' }
	 */
	public static function parent( $board ) {
		return array(
			'board' => (int) ceil( $board / 2 ),
			'slot'  => ( 1 === $board % 2 ) ? 'white' : 'black',
		);
	}

	/**
	 * Winner of a tie. A tie is one or more games between the same two
	 * players (a drawn game is followed by a tie-break game); the first
	 * decisive game decides it.
	 *
	 * @param array[] $games In play order; each with white_entry_id, black_entry_id, result.
	 * @return int|null Winning entry id, or null if not yet decided.
	 */
	public static function tie_winner( array $games ) {
		foreach ( $games as $game ) {
			if ( Chess_Army_Knife_Standings::WHITE_WIN === $game['result'] ) {
				return (int) $game['white_entry_id'];
			}
			if ( Chess_Army_Knife_Standings::BLACK_WIN === $game['result'] ) {
				return (int) $game['black_entry_id'];
			}
		}
		return null;
	}

	/**
	 * A readable name for a knockout round.
	 *
	 * @param int $round Round number (1-based).
	 * @param int $total Total number of rounds.
	 * @return string
	 */
	public static function round_name( $round, $total ) {
		$remaining = $total - $round;
		if ( 0 === $remaining ) {
			return __( 'Final', 'chess-army-knife' );
		}
		if ( 1 === $remaining ) {
			return __( 'Semi-finals', 'chess-army-knife' );
		}
		if ( 2 === $remaining ) {
			return __( 'Quarter-finals', 'chess-army-knife' );
		}
		/* translators: %d: number of players left in this round */
		return sprintf( __( 'Round of %d', 'chess-army-knife' ), pow( 2, $remaining + 1 ) );
	}

	/**
	 * Order the qualifiers from a group stage into knockout seeds, keeping
	 * players from the same group apart for as long as possible.
	 *
	 * Qualifiers are ranked by finishing place first (all group winners, then
	 * all runners-up, ...), then by points, tie-break score and start seed.
	 * Players of the same place are then swapped between bracket positions to
	 * minimise group rematches: a rematch in round 1 costs most, one in the
	 * semi-finals less, and so on. Only equal-place players below the group winners are swapped, so
	 * group winners always keep their earned seeds.
	 *
	 * @param array[] $qualifiers Each: id, group, place (1-based), points, tiebreak, seed.
	 * @return int[] Entry ids in knockout seed order.
	 */
	public static function seed_qualifiers( array $qualifiers ) {
		usort(
			$qualifiers,
			function ( $a, $b ) {
				if ( $a['place'] !== $b['place'] ) {
					return $a['place'] <=> $b['place'];
				}
				if ( $a['points'] !== $b['points'] ) {
					return $b['points'] <=> $a['points'];
				}
				if ( $a['tiebreak'] !== $b['tiebreak'] ) {
					return $b['tiebreak'] <=> $a['tiebreak'];
				}
				return $a['seed'] <=> $b['seed'];
			}
		);

		$count = count( $qualifiers );
		if ( $count < 4 ) {
			return array_column( $qualifiers, 'id' );
		}

		$size  = self::size( $count );
		$total = self::round_count( $count );

		// Bracket position (0-based) of each seed.
		$position = array();
		foreach ( self::order( $size ) as $index => $seed ) {
			$position[ $seed ] = $index;
		}

		// Cost of a seeding: for each pair from the same group, 2^(total - round of first possible meeting).
		$cost = function ( array $list ) use ( $position, $total ) {
			$sum = 0;
			$n   = count( $list );
			for ( $i = 0; $i < $n; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					if ( $list[ $i ]['group'] !== $list[ $j ]['group'] ) {
						continue;
					}
					$p     = $position[ $i + 1 ];
					$q     = $position[ $j + 1 ];
					$round = 1;
					while ( intdiv( $p, pow( 2, $round ) ) !== intdiv( $q, pow( 2, $round ) ) ) {
						++$round;
					}
					$sum += pow( 2, $total - $round );
				}
			}
			return $sum;
		};

		$list    = $qualifiers;
		$current = $cost( $list );

		for ( $guard = 0; $guard < 200 && $current > 0; $guard++ ) {
			$best      = null;
			$best_cost = $current;
			$best_gap  = PHP_INT_MAX;

			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( 1 === $list[ $i ]['place'] || $list[ $i ]['place'] !== $list[ $j ]['place'] || $list[ $i ]['group'] === $list[ $j ]['group'] ) {
						continue;
					}
					$trial       = $list;
					$trial[ $i ] = $list[ $j ];
					$trial[ $j ] = $list[ $i ];
					$trial_cost  = $cost( $trial );
					$gap         = $j - $i;
					if ( $trial_cost < $best_cost || ( $trial_cost === $best_cost && null !== $best && $gap < $best_gap ) ) {
						$best      = $trial;
						$best_cost = $trial_cost;
						$best_gap  = $gap;
					}
				}
			}

			if ( null === $best || $best_cost >= $current ) {
				break; // No swap helps (e.g. a single group): accept what we have.
			}
			$list    = $best;
			$current = $best_cost;
		}

		return array_column( $list, 'id' );
	}
}
