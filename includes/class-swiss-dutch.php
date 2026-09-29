<?php
/**
 * FIDE (Dutch) system pairing engine (FIDE Handbook C.04.3).
 *
 * Pure logic with no WordPress dependencies. It follows the FIDE text:
 * players are paired one score bracket at a time, from the top; unpaired
 * players float down into the next bracket; within a bracket candidate
 * pairings are generated in the prescribed order (S1/S2 subgroups,
 * transpositions, exchanges) and the best is chosen by criteria C1-C21.
 * Colours are then allocated by Article 5.
 *
 * Scores are held in half points (a win is 2) to avoid floating point.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Swiss_Dutch {

	const ABSOLUTE = 3;
	const STRONG   = 2;
	const MILD     = 1;

	/** Candidates evaluated per bracket before settling for the best found so far. */
	const CANDIDATE_LIMIT = 40000;

	/** Largest number of exchanges considered per exchange size. */
	const EXCHANGE_LIMIT = 20000;

	/** Brackets with more residents than this skip the exact optimisation and rely on the search budget. */
	const OPTIMISE_LIMIT = 16;

	/** Partial pairings one round may score, over all its brackets, before the search settles for the best found. */
	const SEARCH_LIMIT = 60000;

	/** Partial pairings one bracket spends aiming for the colour lower bound before trying something else. */
	const AIM_LIMIT = 15000;

	/** Largest bracket the exact optimisation is still tried on, as a fallback (it takes 2^n steps). */
	const FALLBACK_OPTIMISE_LIMIT = 19;

	/** Radix used to pack the additive criteria into one integer. */
	const RADIX = 64;

	protected $ids           = array();
	protected $count         = 0;
	protected $score         = array();
	protected $met           = array();
	protected $colours       = array();
	protected $round_colour  = array();
	protected $pab_blocked   = array();
	protected $unplayed      = array();
	protected $float         = array();
	protected $pref          = array();
	protected $strength      = array();
	protected $diff          = array();
	protected $top           = array();
	protected $round         = 1;
	protected $final_round   = false;
	protected $initial_white = true;
	protected $memo          = array(
		'pair'     => array(),
		'alloc'    => array(),
		'complete' => array(),
		'next'     => array(),
		'parts'    => array(),
		'exchange' => array(),
	);
	protected $next_context  = null;
	protected $truncated     = false;
	protected $pab_score     = null;
	protected $plan          = null;
	protected $budget        = 0;

	/**
	 * Pair the next round.
	 *
	 * @param array $all_ids      Every player id, in tournament pairing number (TPN) order: index 0 is TPN 1.
	 * @param array $active_ids   Ids of the players to pair this round (others are withdrawn or absent).
	 * @param array $rounds       Completed rounds. Each: games (white, black, result '1-0'|'0-1'|'1/2-1/2'|null,
	 *                            forfeit null|'white'|'black'|'both') and byes (player, kind 'pairing'|'full'|'half'|'zero').
	 * @param int   $total_rounds Number of rounds in the tournament (needed for the final-round rules).
	 * @param array $options      initial_white (bool, default true): the colour drawn for the initial-colour rule;
	 *                            search_limit (int): partial pairings to score before settling for the best found.
	 * @return array pairings (list of [white id, black id]), bye (id or null), error (string or null), truncated (bool).
	 */
	public static function pair( array $all_ids, array $active_ids, array $rounds, $total_rounds, array $options = array() ) {
		$engine         = new self();
		$engine->budget = isset( $options['search_limit'] ) ? (int) $options['search_limit'] : self::SEARCH_LIMIT;
		return $engine->run( $all_ids, $active_ids, $rounds, (int) $total_rounds, $options );
	}

	/**
	 * Run the pairing.
	 *
	 * @param array $all_ids      All player ids in TPN order.
	 * @param array $active_ids   Players to pair.
	 * @param array $rounds       Completed rounds.
	 * @param int   $total_rounds Tournament length.
	 * @param array $options      Options.
	 * @return array
	 */
	protected function run( array $all_ids, array $active_ids, array $rounds, $total_rounds, array $options ) {
		$this->build( $all_ids, $rounds, $total_rounds, $options );

		$index  = array_flip( $this->ids );
		$active = array();
		foreach ( $active_ids as $id ) {
			if ( isset( $index[ $id ] ) ) {
				$active[] = $index[ $id ];
			}
		}
		usort( $active, array( $this, 'compare_rank' ) );

		$result = array(
			'pairings'  => array(),
			'bye'       => null,
			'error'     => null,
			'truncated' => false,
		);

		if ( count( $active ) < 2 ) {
			if ( 1 === count( $active ) ) {
				$result['bye'] = $this->ids[ $active[0] ];
			}
			return $result;
		}

		if ( ! $this->completable( $active ) ) {
			$result['error'] = 'no_complete_pairing';
			return $result;
		}

		// C5: the pairing-allocated bye goes to a player with the lowest score that still allows a full pairing.
		$this->pab_score = 1 === count( $active ) % 2 ? $this->lowest_bye_score( $active ) : null;

		$groups = array();
		foreach ( $active as $player ) {
			$groups[ $this->score[ $player ] ][] = $player;
		}
		krsort( $groups );
		$groups = array_values( $groups );

		$mdps  = array();
		$pairs = array();

		$group_count = count( $groups );
		foreach ( $groups as $g => $group ) {
			$lower = array();
			for ( $h = $g + 1; $h < $group_count; $h++ ) {
				$lower = array_merge( $lower, $groups[ $h ] );
			}

			$next = null;
			if ( isset( $groups[ $g + 1 ] ) ) {
				$after = array();
				for ( $h = $g + 2; $h < $group_count; $h++ ) {
					$after = array_merge( $after, $groups[ $h ] );
				}
				$next = array(
					'id'    => $g + 1,
					'res'   => $groups[ $g + 1 ],
					'lower' => $after,
				);
			}

			$solution = $this->solve_bracket( $mdps, $group, $lower, $next );
			if ( null === $solution && ! empty( $mdps ) ) {
				// Moved-down players could not all be paired against residents: fall back to one mixed bracket.
				$merged = array_merge( $mdps, $group );
				usort( $merged, array( $this, 'compare_rank' ) );
				$solution = $this->solve_bracket( array(), $merged, $lower, $next );
			}
			if ( null === $solution ) {
				$result['error'] = 'bracket_failed';
				return $result;
			}

			$pairs = array_merge( $pairs, $solution['pairs'] );
			$mdps  = $solution['down'];
		}

		if ( count( $mdps ) > 1 ) {
			$result['error'] = 'unpaired_players';
			return $result;
		}

		foreach ( $pairs as $pair ) {
			list( $white, $black ) = $this->alloc( $pair[0], $pair[1] );
			$result['pairings'][]  = array( $this->ids[ $white ], $this->ids[ $black ] );
		}
		if ( 1 === count( $mdps ) ) {
			$result['bye'] = $this->ids[ $mdps[0] ];
		}
		$result['truncated'] = $this->truncated;

		return $result;
	}

	/* -------------------------------------------------------------
	 * Player state
	 * ------------------------------------------------------------- */

	/**
	 * Derive every player's score, opponents, colours and float history.
	 *
	 * @param array $all_ids      Player ids in TPN order.
	 * @param array $rounds       Completed rounds.
	 * @param int   $total_rounds Tournament length.
	 * @param array $options      Options.
	 */
	protected function build( array $all_ids, array $rounds, $total_rounds, array $options ) {
		$this->ids           = array_values( $all_ids );
		$this->count         = count( $this->ids );
		$this->round         = count( $rounds ) + 1;
		$this->final_round   = $total_rounds > 0 && $this->round >= $total_rounds;
		$this->initial_white = ! isset( $options['initial_white'] ) || (bool) $options['initial_white'];

		$index = array_flip( $this->ids );
		for ( $i = 0; $i < $this->count; $i++ ) {
			$this->score[ $i ]        = 0;
			$this->met[ $i ]          = array();
			$this->colours[ $i ]      = array();
			$this->round_colour[ $i ] = array();
			$this->pab_blocked[ $i ]  = false;
			$this->unplayed[ $i ]     = 0;
			$this->float[ $i ]        = array();
		}

		foreach ( $rounds as $r => $round ) {
			$number  = $r + 1;
			$before  = $this->score;
			$present = array();

			foreach ( isset( $round['games'] ) ? $round['games'] : array() as $game ) {
				if ( ! isset( $index[ $game['white'] ], $index[ $game['black'] ] ) ) {
					continue;
				}
				$white = $index[ $game['white'] ];
				$black = $index[ $game['black'] ];

				$present[ $white ]             = true;
				$present[ $black ]             = true;
				$this->met[ $white ][ $black ] = true;
				$this->met[ $black ][ $white ] = true;

				$result = isset( $game['result'] ) ? $game['result'] : null;
				$points = array( 0, 0 );
				if ( '1-0' === $result ) {
					$points = array( 2, 0 );
				} elseif ( '0-1' === $result ) {
					$points = array( 0, 2 );
				} elseif ( '1/2-1/2' === $result ) {
					$points = array( 1, 1 );
				}
				$this->score[ $white ] += $points[0];
				$this->score[ $black ] += $points[1];

				if ( ! empty( $game['forfeit'] ) ) {
					// Not played: no colours, both count an unplayed game; a winner scored without playing.
					++$this->unplayed[ $white ];
					++$this->unplayed[ $black ];
					if ( 2 === $points[0] ) {
						$this->pab_blocked[ $white ]      = true;
						$this->float[ $white ][ $number ] = 'down';
					}
					if ( 2 === $points[1] ) {
						$this->pab_blocked[ $black ]      = true;
						$this->float[ $black ][ $number ] = 'down';
					}
					continue;
				}

				$this->colours[ $white ][]               = 'W';
				$this->colours[ $black ][]               = 'B';
				$this->round_colour[ $white ][ $number ] = 'W';
				$this->round_colour[ $black ][ $number ] = 'B';

				if ( $before[ $white ] !== $before[ $black ] ) {
					$white_higher = $before[ $white ] > $before[ $black ] || ( $before[ $white ] === $before[ $black ] && $white < $black );
					$this->float[ $white_higher ? $white : $black ][ $number ] = 'down';
					$this->float[ $white_higher ? $black : $white ][ $number ] = 'up';
				}
			}

			foreach ( isset( $round['byes'] ) ? $round['byes'] : array() as $bye ) {
				if ( ! isset( $index[ $bye['player'] ] ) ) {
					continue;
				}
				$player             = $index[ $bye['player'] ];
				$present[ $player ] = true;
				$kind               = isset( $bye['kind'] ) ? $bye['kind'] : 'pairing';
				$points             = array(
					'pairing' => 2,
					'full'    => 2,
					'half'    => 1,
					'zero'    => 0,
				);
				$earned             = isset( $points[ $kind ] ) ? $points[ $kind ] : 0;

				$this->score[ $player ] += $earned;
				++$this->unplayed[ $player ];
				if ( $earned >= 2 ) {
					$this->pab_blocked[ $player ] = true;
				}
				if ( $earned > 0 ) {
					$this->float[ $player ][ $number ] = 'down';
				}
			}

			for ( $i = 0; $i < $this->count; $i++ ) {
				if ( ! isset( $present[ $i ] ) ) {
					++$this->unplayed[ $i ];
				}
			}
		}

		for ( $i = 0; $i < $this->count; $i++ ) {
			$this->derive_colour_preference( $i );
			// Topscorers only matter when pairing the final round: over 50% of the possible score.
			$this->top[ $i ] = $this->final_round && $this->score[ $i ] > ( $total_rounds - 1 );
		}
	}

	/**
	 * Work out a player's colour difference and colour preference.
	 *
	 * @param int $i Player index.
	 */
	protected function derive_colour_preference( $i ) {
		$colours = $this->colours[ $i ];
		$count   = count( $colours );
		$white   = count( array_keys( $colours, 'W', true ) );

		$this->diff[ $i ]     = $white - ( $count - $white );
		$this->pref[ $i ]     = null;
		$this->strength[ $i ] = 0;

		if ( 0 === $count ) {
			return;
		}

		$absolute = null;
		if ( $this->diff[ $i ] > 1 ) {
			$absolute = 'B';
		} elseif ( $this->diff[ $i ] < -1 ) {
			$absolute = 'W';
		} elseif ( $count >= 2 && $colours[ $count - 1 ] === $colours[ $count - 2 ] ) {
			$absolute = 'W' === $colours[ $count - 1 ] ? 'B' : 'W';
		}

		if ( null !== $absolute ) {
			$this->pref[ $i ]     = $absolute;
			$this->strength[ $i ] = self::ABSOLUTE;
		} elseif ( 1 === $this->diff[ $i ] ) {
			$this->pref[ $i ]     = 'B';
			$this->strength[ $i ] = self::STRONG;
		} elseif ( -1 === $this->diff[ $i ] ) {
			$this->pref[ $i ]     = 'W';
			$this->strength[ $i ] = self::STRONG;
		} else {
			$this->pref[ $i ]     = 'W' === $colours[ $count - 1 ] ? 'B' : 'W';
			$this->strength[ $i ] = self::MILD;
		}
	}

	/**
	 * Order players by score (high first), then by pairing number.
	 *
	 * @param int $a Player index.
	 * @param int $b Player index.
	 * @return int
	 */
	public function compare_rank( $a, $b ) {
		if ( $this->score[ $a ] !== $this->score[ $b ] ) {
			return $this->score[ $b ] <=> $this->score[ $a ];
		}
		return $a <=> $b;
	}

	/**
	 * Whether two players may be paired (criteria C1 and C3).
	 *
	 * @param int $i Player index.
	 * @param int $j Player index.
	 * @return bool
	 */
	protected function can_pair( $i, $j ) {
		$key = $i < $j ? $i * $this->count + $j : $j * $this->count + $i;
		if ( isset( $this->memo['pair'][ $key ] ) ) {
			return $this->memo['pair'][ $key ];
		}

		$ok = ! isset( $this->met[ $i ][ $j ] );
		if ( $ok
			&& self::ABSOLUTE === $this->strength[ $i ] && self::ABSOLUTE === $this->strength[ $j ]
			&& $this->pref[ $i ] === $this->pref[ $j ]
			&& ! $this->top[ $i ] && ! $this->top[ $j ] ) {
			$ok = false;
		}

		$this->memo['pair'][ $key ] = $ok;
		return $ok;
	}

	/**
	 * Allocate colours to a pair (Article 5).
	 *
	 * @param int $a Player index.
	 * @param int $b Player index.
	 * @return int[] [ white, black ]
	 */
	protected function alloc( $a, $b ) {
		$key = $a * $this->count + $b;
		if ( isset( $this->memo['alloc'][ $key ] ) ) {
			return $this->memo['alloc'][ $key ];
		}

		$result = $this->allocate_colours( $a, $b );

		$this->memo['alloc'][ $key ]                   = $result;
		$this->memo['alloc'][ $b * $this->count + $a ] = $result;
		return $result;
	}

	/**
	 * Colour allocation rules 5.2.1 to 5.2.5.
	 *
	 * @param int $a Player index.
	 * @param int $b Player index.
	 * @return int[] [ white, black ]
	 */
	protected function allocate_colours( $a, $b ) {
		$pa = $this->pref[ $a ];
		$pb = $this->pref[ $b ];

		// 5.2.1: grant both colour preferences.
		if ( null !== $pa && ( null === $pb || $pa !== $pb ) ) {
			return 'W' === $pa ? array( $a, $b ) : array( $b, $a );
		}
		if ( null === $pa && null !== $pb ) {
			return 'W' === $pb ? array( $b, $a ) : array( $a, $b );
		}

		// 5.2.2: the stronger preference wins; two absolute preferences go by the wider colour difference.
		if ( null !== $pa ) {
			$sa = $this->strength[ $a ];
			$sb = $this->strength[ $b ];
			if ( $sa !== $sb ) {
				$winner = $sa > $sb ? $a : $b;
				$loser  = $sa > $sb ? $b : $a;
				return 'W' === $this->pref[ $winner ] ? array( $winner, $loser ) : array( $loser, $winner );
			}
			if ( self::ABSOLUTE === $sa && abs( $this->diff[ $a ] ) !== abs( $this->diff[ $b ] ) ) {
				$winner = abs( $this->diff[ $a ] ) > abs( $this->diff[ $b ] ) ? $a : $b;
				$loser  = $winner === $a ? $b : $a;
				return 'W' === $this->pref[ $winner ] ? array( $winner, $loser ) : array( $loser, $winner );
			}
		}

		// 5.2.3: alternate the colours from the most recent round in which they had different colours.
		for ( $r = $this->round - 1; $r >= 1; $r-- ) {
			$ca = isset( $this->round_colour[ $a ][ $r ] ) ? $this->round_colour[ $a ][ $r ] : null;
			$cb = isset( $this->round_colour[ $b ][ $r ] ) ? $this->round_colour[ $b ][ $r ] : null;
			if ( null !== $ca && null !== $cb && $ca !== $cb ) {
				return 'W' === $ca ? array( $b, $a ) : array( $a, $b );
			}
		}

		// 5.2.4: the preference of the higher ranked player.
		$higher = $this->compare_rank( $a, $b ) <= 0 ? $a : $b;
		$lower  = $higher === $a ? $b : $a;
		if ( null !== $this->pref[ $higher ] ) {
			return 'W' === $this->pref[ $higher ] ? array( $higher, $lower ) : array( $lower, $higher );
		}

		// 5.2.5: the higher ranked player gets the initial colour if their pairing number is odd.
		$odd          = 1 === ( $higher + 1 ) % 2;
		$higher_white = $odd ? $this->initial_white : ! $this->initial_white;
		return $higher_white ? array( $higher, $lower ) : array( $lower, $higher );
	}

	/* -------------------------------------------------------------
	 * Completion (C4)
	 * ------------------------------------------------------------- */

	/**
	 * Whether a set of players can all be paired (one may get the bye if the
	 * number is odd) without breaking the absolute criteria.
	 *
	 * @param int[] $players Player indexes.
	 * @return bool
	 */
	protected function completable( array $players ) {
		$total = count( $players );
		if ( 0 === $total ) {
			return true;
		}

		$sorted = $players;
		sort( $sorted );
		$key = implode( ',', $sorted );
		if ( isset( $this->memo['complete'][ $key ] ) ) {
			return $this->memo['complete'][ $key ];
		}

		$adjacency = array_fill( 0, $total, array() );
		for ( $x = 0; $x < $total; $x++ ) {
			for ( $y = $x + 1; $y < $total; $y++ ) {
				if ( $this->can_pair( $sorted[ $x ], $sorted[ $y ] ) ) {
					$adjacency[ $x ][] = $y;
					$adjacency[ $y ][] = $x;
				}
			}
		}

		if ( 1 === $total % 2 ) {
			// A dummy vertex stands for the pairing-allocated bye.
			$adjacency[ $total ] = array();
			for ( $x = 0; $x < $total; $x++ ) {
				if ( ! $this->pab_blocked[ $sorted[ $x ] ] && ( null === $this->pab_score || $this->score[ $sorted[ $x ] ] === $this->pab_score ) ) {
					$adjacency[ $x ][]     = $total;
					$adjacency[ $total ][] = $x;
				}
			}
		}

		$ok = Chess_Army_Knife_Matching::has_perfect( $adjacency );

		$this->memo['complete'][ $key ] = $ok;
		return $ok;
	}

	/**
	 * The lowest score a pairing-allocated bye can go to while everyone else
	 * can still be paired (criterion C5).
	 *
	 * @param int[] $players Players to be paired (an odd number).
	 * @return int|null Half-point score, or null if nobody can take it.
	 */
	protected function lowest_bye_score( array $players ) {
		$best = null;
		foreach ( $players as $candidate ) {
			if ( $this->pab_blocked[ $candidate ] ) {
				continue;
			}
			if ( null !== $best && $this->score[ $candidate ] >= $best ) {
				continue;
			}
			$rest = array_values( array_diff( $players, array( $candidate ) ) );
			if ( $this->completable_without_bye( $rest ) ) {
				$best = $this->score[ $candidate ];
			}
		}
		return $best;
	}

	/**
	 * Whether an even-sized group of players can all be paired.
	 *
	 * @param int[] $players Player indexes.
	 * @return bool
	 */
	protected function completable_without_bye( array $players ) {
		$total     = count( $players );
		$adjacency = array_fill( 0, max( 1, $total ), array() );
		for ( $x = 0; $x < $total; $x++ ) {
			for ( $y = $x + 1; $y < $total; $y++ ) {
				if ( $this->can_pair( $players[ $x ], $players[ $y ] ) ) {
					$adjacency[ $x ][] = $y;
					$adjacency[ $y ][] = $x;
				}
			}
		}
		return 0 === $total || Chess_Army_Knife_Matching::has_perfect( $adjacency );
	}

	/* -------------------------------------------------------------
	 * Brackets
	 * ------------------------------------------------------------- */

	/**
	 * Pair one bracket: moved-down players from above plus one score group.
	 *
	 * @param int[]      $mdps      Moved-down players, in rank order.
	 * @param int[]      $residents The score group's own players, in rank order.
	 * @param int[]      $lower     Everyone in lower score groups.
	 * @param array|null $next      The next bracket (id, res, lower), or null if this is the last.
	 * @return array|null pairs (list of [a, b]) and down (players moving to the next bracket), or null.
	 */
	protected function solve_bracket( array $mdps, array $residents, array $lower, $next ) {
		$this->next_context = $next;

		$m0    = count( $mdps );
		$r     = count( $residents );
		$total = $m0 + $r;

		// MaxPairs (C6) and the number of moved-down players paired (M1): as many pairs as
		// possible, and within that as many moved-down players paired as possible.
		for ( $k = intdiv( $total, 2 ); $k >= 0; $k-- ) {
			$m_max = min( $m0, $k, $r );
			$m_min = max( 0, 2 * $k - $r );
			for ( $m = $m_max; $m >= $m_min; $m-- ) {
				$records = $this->down_sets( $mdps, $residents, $lower, $k, $m, 0 );
				if ( ! empty( $records ) ) {
					return $this->best_candidate( $mdps, $residents, $lower, $k, $m, $records );
				}
			}
		}

		return null;
	}

	/**
	 * Sets of players who could be left unpaired (the downfloaters) for a
	 * bracket with k pairs, m of them with moved-down players, such that the
	 * rest of the bracket can be paired and everyone below can be completed.
	 *
	 * A limbo set is only valid if it is the best available for C7: among the
	 * limbo sets that work, the one with the lowest scores.
	 *
	 * @param int[] $mdps      Moved-down players.
	 * @param int[] $residents Resident players.
	 * @param int[] $lower     Players in lower groups.
	 * @param int   $k         Number of pairs.
	 * @param int   $m         Moved-down players paired (against residents).
	 * @param int   $limit     Stop after this many downfloater sets per limbo set (0 for all).
	 * @return array[] Each: down (rank order), limbo, key.
	 */
	protected function down_sets( array $mdps, array $residents, array $lower, $k, $m, $limit ) {
		$m0   = count( $mdps );
		$r    = count( $residents );
		$left = $r + $m - 2 * $k;
		if ( $left < 0 || $m > $m0 || $m > $r ) {
			return array();
		}

		$records = array();
		foreach ( $this->limbo_sets( $mdps, $m0 - $m ) as $limbo ) {
			$paired_mdps = array_values( array_diff( $mdps, $limbo ) );
			$found       = 0;

			foreach ( $this->combinations( $residents, $left ) as $leftover ) {
				$rest = array_merge( $paired_mdps, array_values( array_diff( $residents, $leftover ) ) );
				if ( ! $this->matchable( $rest, $paired_mdps ) ) {
					continue;
				}

				$down = array_merge( $limbo, $leftover );
				usort( $down, array( $this, 'compare_rank' ) );
				if ( ! $this->completable( array_merge( $down, $lower ) ) ) {
					continue;
				}

				$records[] = array(
					'down'  => $down,
					'limbo' => $limbo,
					'key'   => implode( ',', $down ),
				);
				if ( $limit > 0 && ++$found >= $limit ) {
					break;
				}
			}
		}

		if ( $m0 - $m > 0 && count( $records ) > 1 ) {
			$best_scores = null;
			$kept        = array();
			foreach ( $records as $record ) {
				$scores = array();
				foreach ( $record['limbo'] as $player ) {
					$scores[] = $this->score[ $player ];
				}
				rsort( $scores );
				$order = null === $best_scores ? -1 : $this->compare_vectors( $scores, $best_scores );
				if ( $order < 0 ) {
					$best_scores = $scores;
					$kept        = array();
				}
				if ( $order <= 0 ) {
					$kept[] = $record;
				}
			}
			$records = $kept;
		}

		return $records;
	}

	/**
	 * The possible sets of moved-down players left in limbo, in the order of
	 * Article 4.4.2: the set of paired moved-down players (S1) with the smallest
	 * differing pairing numbers comes first.
	 *
	 * @param int[] $mdps Moved-down players in rank order.
	 * @param int   $size Number of players to leave in limbo.
	 * @return int[][]
	 */
	protected function limbo_sets( array $mdps, $size ) {
		$sets = array();
		foreach ( $this->combinations( $mdps, count( $mdps ) - $size ) as $paired ) {
			$sets[] = array_values( array_diff( $mdps, $paired ) );
		}
		return $sets;
	}

	/**
	 * Whether a group of players can be completely paired inside the bracket:
	 * moved-down players only against residents, residents against anyone.
	 *
	 * @param int[] $players Players to pair.
	 * @param int[] $mdps    Those of them that are moved-down players.
	 * @return bool
	 */
	protected function matchable( array $players, array $mdps ) {
		$total = count( $players );
		if ( 0 === $total ) {
			return true;
		}
		if ( 1 === $total % 2 ) {
			return false;
		}

		$moved     = array_flip( $mdps );
		$adjacency = array_fill( 0, $total, array() );
		for ( $x = 0; $x < $total; $x++ ) {
			for ( $y = $x + 1; $y < $total; $y++ ) {
				if ( isset( $moved[ $players[ $x ] ], $moved[ $players[ $y ] ] ) ) {
					continue;
				}
				if ( $this->can_pair( $players[ $x ], $players[ $y ] ) ) {
					$adjacency[ $x ][] = $y;
					$adjacency[ $y ][] = $x;
				}
			}
		}
		return Chess_Army_Knife_Matching::has_perfect( $adjacency );
	}

	/**
	 * How good it is to leave a set of players unpaired: the best the next
	 * bracket can then do (C5, C6, C7) - criterion C8. Smaller is better.
	 *
	 * @param int[] $down Downfloaters, in rank order.
	 * @return int[]
	 */
	protected function next_vector( array $down ) {
		$next = $this->next_context;
		if ( null === $next ) {
			return array( 0 );
		}

		$key = implode( ',', $down ) . '|' . $next['id'];
		if ( isset( $this->memo['next'][ $key ] ) ) {
			return $this->memo['next'][ $key ];
		}

		$m0     = count( $down );
		$r      = count( $next['res'] );
		$total  = $m0 + $r;
		$result = array( PHP_INT_MAX );

		for ( $k = intdiv( $total, 2 ); $k >= 0; $k-- ) {
			$m_max = min( $m0, $k, $r );
			$m_min = max( 0, 2 * $k - $r );
			for ( $m = $m_max; $m >= $m_min; $m-- ) {
				$records = $this->down_sets( $down, $next['res'], $next['lower'], $k, $m, 1 );
				if ( empty( $records ) ) {
					continue;
				}
				$leaving = $records[0]['down'];
				$scores  = array();
				foreach ( $leaving as $player ) {
					$scores[] = $this->score[ $player ];
				}
				rsort( $scores );
				$pab    = ( empty( $next['lower'] ) && 1 === count( $leaving ) ) ? $this->score[ $leaving[0] ] : 0;
				$result = array_merge( array( $pab, count( $leaving ) ), $scores );
				break 2;
			}
		}

		$this->memo['next'][ $key ] = $result;
		return $result;
	}

	/**
	 * Choose the best candidate among all pairings with k pairs.
	 *
	 * The downfloaters are decided first (C8, then C9), then the pairs
	 * themselves by the colour and float criteria C10-C21, taking the first
	 * in the sequence of Article 3/4 when several are equally good.
	 *
	 * The criteria add up pair by pair, so the best achievable score is found
	 * exactly by dynamic programming over subsets of residents. The search in
	 * FIDE order then only follows branches that can still reach that score
	 * and stops at the first candidate that does: the earliest in the sequence.
	 * Very large brackets fall back to a bounded search.
	 *
	 * @param int[]   $mdps      Moved-down players.
	 * @param int[]   $residents Residents.
	 * @param int[]   $lower     Players in lower groups.
	 * @param int     $k         Number of pairs.
	 * @param int     $m         Moved-down players paired.
	 * @param array[] $records   Feasible downfloater sets.
	 * @return array|null pairs and down.
	 */
	protected function best_candidate( array $mdps, array $residents, array $lower, $k, $m, array $records ) {
		// Rank the downfloater sets.
		$best_key = null;
		$allowed  = array();
		foreach ( $records as $record ) {
			$c9  = ( empty( $lower ) && 1 === count( $record['down'] ) ) ? $this->unplayed[ $record['down'][0] ] : 0;
			$key = array_merge( $this->next_vector( $record['down'] ), array( $c9 ) );

			$order = null === $best_key ? -1 : $this->compare_vectors( $key, $best_key );
			if ( $order < 0 ) {
				$best_key = $key;
				$allowed  = array();
			}
			if ( $order <= 0 ) {
				$allowed[ $record['key'] ] = $record;
			}
		}

		// Players who are in every allowed downfloater set can never be paired.
		$forbid = null;
		foreach ( $allowed as $record ) {
			$forbid = null === $forbid ? array_flip( $record['down'] ) : array_intersect_key( $forbid, array_flip( $record['down'] ) );
		}
		$forbid = null === $forbid ? array() : $forbid;

		$allowed_limbos = array();
		foreach ( $allowed as $record ) {
			$allowed_limbos[ implode( ',', $record['limbo'] ) ] = true;
		}

		$width = count( $mdps );

		// Exact optimum, when the bracket is small enough.
		$optimum = null;
		$plan    = null;
		if ( PHP_INT_SIZE >= 8 && count( $residents ) <= self::OPTIMISE_LIMIT ) {
			$plan    = $this->optimisation_plan( $mdps, $residents, $k, $m, $allowed, $allowed_limbos, $forbid, $width );
			$optimum = null === $plan ? null : $plan['optimum'];
		}

		if ( null === $optimum ) {
			// No exact optimum yet. First aim for a candidate that meets the colour lower bound, which
			// is then the best there is and the first in FIDE order.
			$target       = array_merge( array( 0, 0 ), $this->colour_bound( array(), array_merge( $residents, $mdps ), $k ) );
			$was_cut      = $this->truncated;
			$total        = $this->budget;
			$this->budget = min( $total, self::AIM_LIMIT );
			$best         = $this->search_candidates( $mdps, $residents, $k, $m, $allowed, $allowed_limbos, $forbid, $width, $target, null );
			$this->budget = $total - ( min( $total, self::AIM_LIMIT ) - $this->budget );
			$settled      = null !== $best && ! $this->truncated;

			// That did not settle it (the bound cannot be reached, or the search ran out): a bracket
			// small enough is optimised exactly after all.
			if ( ! $settled && PHP_INT_SIZE >= 8 && count( $residents ) <= self::FALLBACK_OPTIMISE_LIMIT ) {
				$this->truncated = $was_cut;
				$plan            = $this->optimisation_plan( $mdps, $residents, $k, $m, $allowed, $allowed_limbos, $forbid, $width );
				if ( null !== $plan ) {
					$exact = $this->search_candidates( $mdps, $residents, $k, $m, $allowed, $allowed_limbos, $forbid, $width, $plan['optimum'], $plan );
					if ( null !== $exact ) {
						$best    = $exact;
						$settled = ! $this->truncated;
					}
				}
			}

			// Otherwise settle for the best found: what the aim found, or failing that the best of a plain search.
			if ( ! $settled && null === $best ) {
				$this->truncated = $was_cut;
				$best            = $this->search_candidates( $mdps, $residents, $k, $m, $allowed, $allowed_limbos, $forbid, $width, null, null );
			}
			if ( ! $settled ) {
				$this->truncated = true;
			}
		} else {
			$best = $this->search_candidates( $mdps, $residents, $k, $m, $allowed, $allowed_limbos, $forbid, $width, $optimum, $plan );
		}

		if ( null === $best ) {
			return null;
		}

		return array(
			'pairs' => $best['pairs'],
			'down'  => $best['down'],
		);
	}

	/**
	 * Search a bracket's candidates in FIDE order for the best one.
	 *
	 * With an exact optimum the search stops at the first candidate that reaches
	 * it. Without one it aims for the lower bound on the colour criteria (see
	 * colour_bound()): candidates that reach it are kept while the later criteria
	 * are improved on, and the search stops when nothing can beat what it has. A round has a fixed budget of partial pairings to
	 * score: once it is spent the search settles for the best candidate found
	 * (the first candidates in FIDE order are the ones tried first).
	 *
	 * @param int[]      $mdps           Moved-down players.
	 * @param int[]      $residents      Residents.
	 * @param int        $k              Number of pairs.
	 * @param int        $m              Moved-down players paired.
	 * @param array[]    $allowed        Allowed downfloater sets, by key.
	 * @param array      $allowed_limbos Allowed limbo sets, by key.
	 * @param array      $forbid         Players that must not be paired.
	 * @param int        $width          Padding width for score-difference lists.
	 * @param int[]|null $optimum        The exact best score, or a score to aim for (when there is no plan).
	 * @param array|null $plan           Optimisation plan, if any.
	 * @return array|null The best candidate.
	 */
	protected function search_candidates( array $mdps, array $residents, $k, $m, array $allowed, array $allowed_limbos, array $forbid, $width, $optimum, $plan ) {
		$best       = null;
		$best_score = null;
		$evaluated  = 0;

		// Without an exact optimum, the best possible score is at least this (colour criteria C12 and C13 only).
		$floor = array( 0, 0 );
		if ( null === $optimum ) {
			$floor = array_merge( $floor, $this->colour_bound( array(), array_merge( $residents, $mdps ), $k ) );
		}

		// Bound: can a partial pairing still reach the optimum (or beat the best so far)? $rest, when
		// given, holds the players still to be paired in the current S1/S2 split.
		$bound = function ( array $pairs, array $mdp_pairs, array $limbo, $resident_score, $remaining, $rest = null ) use ( &$best_score, &$evaluated, $width, $optimum, $plan, $residents, $mdps, $k ) {
			if ( null === $optimum && null === $best_score ) {
				return false;
			}
			// Out of budget: with something to fall back on, stop exploring. When aiming for a score
			// nothing is being kept, so give up and let the caller settle for the first pairing.
			if ( $this->budget <= 0 && ( null !== $best_score || ( null !== $optimum && null === $plan ) ) ) {
				throw new RuntimeException( 'Swiss search budget spent' );
			}
			--$this->budget;
			$partial = $this->evaluate(
				array(
					'pairs'          => $pairs,
					'mdp_pairs'      => $mdp_pairs,
					'left'           => array(),
					'limbo'          => $limbo,
					'resident_score' => $resident_score,
				),
				$width
			);
			if ( null !== $optimum ) {
				if ( null !== $remaining ) {
					$partial = $this->add_parts( $partial, $this->completion_bound( $limbo, $remaining ) );
				} elseif ( null === $plan ) {
					// Aiming for the colour lower bound: the later criteria only have to beat the best found.
					$colour      = null === $rest ? $this->colour_bound( $pairs, array_merge( $residents, array_diff( $mdps, $limbo ) ), $k ) : $this->split_colour_bound( $rest['s1'], $rest['s2'] );
					$partial[2] += $colour[0];
					$partial[3] += $colour[1];
					if ( $this->compare_vectors( array_slice( $partial, 0, 4 ), $optimum ) > 0 ) {
						return true;
					}
					return null !== $best_score && $this->compare_vectors( $partial, $best_score ) >= 0;
				}
				return $this->compare_vectors( $partial, $optimum ) > 0;
			}
			$colour      = null === $rest ? $this->colour_bound( $pairs, array_merge( $residents, array_diff( $mdps, $limbo ) ), $k ) : $this->split_colour_bound( $rest['s1'], $rest['s2'] );
			$partial[2] += $colour[0];
			$partial[3] += $colour[1];
			return $this->compare_vectors( $partial, $best_score ) >= 0;
		};

		// With a score to aim for and no plan, only splits of the bracket that could reach it are tried.
		$aim = null !== $optimum && null === $plan ? array( $optimum[2], $optimum[3] ) : null;

		try {
			foreach ( $this->candidates( $mdps, $residents, $k, $m, $allowed_limbos, $forbid, $bound, $plan, $aim ) as $candidate ) {
				if ( ! isset( $allowed[ implode( ',', $candidate['down'] ) ] ) ) {
					continue;
				}

				$vector = $this->evaluate( $candidate, $width );
				if ( null === $best_score || $this->compare_vectors( $vector, $best_score ) < 0 ) {
					$best       = $candidate;
					$best_score = $vector;
					if ( 0 === array_sum( $vector ) ) {
						break; // Perfect: every colour and float criterion is met.
					}
					if ( null !== $optimum && 0 === $this->compare_vectors( $vector, $optimum ) ) {
						break; // The earliest candidate in the sequence that reaches the optimum.
					}
					if ( null === $optimum && 0 === $this->compare_vectors( $vector, $floor ) ) {
						break; // No candidate can score better.
					}
				}

				if ( ++$evaluated >= self::CANDIDATE_LIMIT ) {
					$this->truncated = true;
					break;
				}
			}
		} catch ( RuntimeException $spent ) {
			$this->truncated = true;
		}

		return $best;
	}

	/**
	 * Lower bounds on the colour criteria C12 (players not given the colour they
	 * are due) and C13 (those of them with a strong preference) for the residents
	 * still unpaired after some pairs.
	 *
	 * A player due White paired with one due Black, or with one with no
	 * preference, gets the colour due; two players due the same colour cannot
	 * both. So the bound counts the surplus of one colour among those left to
	 * pair, less those who will not be paired at all and those who have no
	 * preference to absorb it, and every two of the rest cost a violation.
	 *
	 * @param array $pairs   Pairs chosen so far (players in them are used up).
	 * @param int[] $players The residents and the moved-down players not left in limbo.
	 * @param int   $k       Number of pairs the bracket makes.
	 * @return int[] Bounds for C12 and C13.
	 */
	protected function colour_bound( array $pairs, array $players, $k ) {
		$used = array();
		foreach ( $pairs as $pair ) {
			$used[ $pair[0] ] = true;
			$used[ $pair[1] ] = true;
		}

		$white  = 0;
		$black  = 0;
		$none   = 0;
		$left   = 0;
		$others = 0; // Players with a preference that is not a strong one.
		foreach ( $players as $player ) {
			if ( isset( $used[ $player ] ) ) {
				continue;
			}
			++$left;
			if ( 'W' === $this->pref[ $player ] ) {
				++$white;
			} elseif ( 'B' === $this->pref[ $player ] ) {
				++$black;
			} else {
				++$none;
				continue;
			}
			if ( self::STRONG !== $this->strength[ $player ] ) {
				++$others;
			}
		}

		$unpaired = $left - 2 * ( $k - count( $pairs ) ); // Players that end up unpaired.
		$surplus  = abs( $white - $black ) - $unpaired - $none;
		$c12      = $surplus > 0 ? intdiv( $surplus + 1, 2 ) : 0;
		return array( $c12, max( 0, $c12 - $others ) );
	}

	/**
	 * The fewest colour violations for a split, from how many players of S1 and of S2
	 * are due White, due Black or have no preference (every player of S1 gets a partner
	 * from S2).
	 *
	 * @param int[] $one Counts for S1, keyed 'W', 'B' and ''.
	 * @param int[] $two Counts for S2.
	 * @return int
	 */
	protected function split_violations( array $one, array $two ) {
		$short_white = max( 0, $one['W'] - $two['B'] );
		$short_black = max( 0, $one['B'] - $two['W'] );
		$free        = min( $two[''], $short_white + $short_black );
		$fine        = min( $one['W'], $two['B'] ) + min( $one['B'], $two['W'] ) + $free + $one[''];
		return $one['W'] + $one['B'] + $one[''] - $fine;
	}

	/**
	 * The same bounds for a search inside one S1/S2 split, where every player of S1
	 * still to be paired takes a partner from S2 (and the rest of S2 downfloats).
	 *
	 * The most pairs that can go without a violation are found by matching the
	 * players of S1 due White with those of S2 due Black, and the reverse, and
	 * letting those of S2 with no preference stand in for whoever is short.
	 *
	 * @param int[] $s1 Players of S1 still to be paired.
	 * @param int[] $s2 Players of S2 still available.
	 * @return int[] Bounds for C12 and C13.
	 */
	protected function split_colour_bound( array $s1, array $s2 ) {
		$count  = array(
			1 => array(
				'W' => 0,
				'B' => 0,
				''  => 0,
			),
			2 => array(
				'W' => 0,
				'B' => 0,
				''  => 0,
			),
		);
		$others = 0;
		foreach ( array(
			1 => $s1,
			2 => $s2,
		) as $side => $players ) {
			foreach ( $players as $player ) {
				$pref = null === $this->pref[ $player ] ? '' : $this->pref[ $player ];
				++$count[ $side ][ $pref ];
				if ( '' !== $pref && self::STRONG !== $this->strength[ $player ] ) {
					++$others;
				}
			}
		}

		$c12 = $this->split_violations( $count[1], $count[2] );

		return array( $c12, max( 0, $c12 - $others ) );
	}

	/**
	 * Generate the candidate pairings of a bracket in the order laid down by
	 * the FIDE text (Articles 3.6, 3.7 and 4).
	 *
	 * @param int[]      $mdps           Moved-down players.
	 * @param int[]      $residents      Residents.
	 * @param int        $k              Number of pairs.
	 * @param int        $m              Moved-down players paired.
	 * @param array      $allowed_limbos Allowed limbo sets, by key.
	 * @param array      $forbid         Players that must not be paired.
	 * @param callable   $bound          Returns true if a partial pairing cannot win.
	 * @param array|null $plan           Optimisation plan (see optimisation_plan()), if any.
	 * @param int[]|null $aim            C12 and C13 to aim for: splits that cannot reach them are skipped.
	 * @return Generator
	 */
	protected function candidates( array $mdps, array $residents, $k, $m, array $allowed_limbos, array $forbid, $bound, $plan, $aim = null ) {
		if ( empty( $mdps ) ) {
			$prune = function ( array $pairs, $rest = null ) use ( $bound, $plan ) {
				return $bound( $pairs, array(), array(), 0, null === $plan ? null : $this->remaining_mask( $pairs, array() ), $rest );
			};
			foreach ( $this->homogeneous( $residents, $k, $forbid, $prune, $aim ) as $found ) {
				yield array(
					'pairs'     => $found['pairs'],
					'mdp_pairs' => array(),
					'left'      => $found['down'],
					'down'      => $found['down'],
					'limbo'     => array(),
				);
			}
			return;
		}

		$resident_score = $this->score[ $residents[0] ];

		foreach ( $this->limbo_sets( $mdps, count( $mdps ) - $m ) as $limbo ) {
			if ( ! isset( $allowed_limbos[ implode( ',', $limbo ) ] ) ) {
				continue;
			}
			$s1 = array_values( array_diff( $mdps, $limbo ) );

			$prune_prefix = function ( array $pairs ) use ( $bound, $limbo, $resident_score ) {
				return $bound( $pairs, $pairs, $limbo, $resident_score, null );
			};

			foreach ( $this->moved_down_pairings( $s1, $residents, $forbid, $prune_prefix ) as $prefix ) {
				$remainder = array_values( array_diff( $residents, $prefix['used'] ) );

				$prune = function ( array $pairs, $rest = null ) use ( $bound, $prefix, $limbo, $resident_score, $plan ) {
					$remaining = null === $plan ? null : $this->remaining_mask( $pairs, $prefix['pairs'] );
					return $bound( array_merge( $prefix['pairs'], $pairs ), $prefix['pairs'], $limbo, $resident_score, $remaining, $rest );
				};

				// What the remainder may still cost in colour criteria, once the moved-down pairs have taken theirs.
				$remainder_aim = $aim;
				if ( null !== $aim ) {
					foreach ( $prefix['pairs'] as $pair ) {
						$parts             = $this->pair_components( $pair[0], $pair[1] );
						$remainder_aim[0] -= $parts[2];
						$remainder_aim[1] -= $parts[3];
					}
					if ( $remainder_aim[0] < 0 || $remainder_aim[1] < 0 ) {
						continue;
					}
				}

				foreach ( $this->homogeneous( $remainder, $k - $m, $forbid, $prune, $remainder_aim ) as $found ) {
					$down = array_merge( $limbo, $found['down'] );
					usort( $down, array( $this, 'compare_rank' ) );
					yield array(
						'pairs'          => array_merge( $prefix['pairs'], $found['pairs'] ),
						'mdp_pairs'      => $prefix['pairs'],
						'left'           => $found['down'],
						'down'           => $down,
						'limbo'          => $limbo,
						'resident_score' => $resident_score,
					);
				}
			}
		}
	}

	/**
	 * Pair the moved-down players (S1) against residents in transposition order.
	 *
	 * @param int[]    $s1        Moved-down players to pair, in rank order.
	 * @param int[]    $residents Residents in rank order (S2).
	 * @param array    $forbid    Players that must not be paired.
	 * @param callable $prune     Returns true if a partial list of pairs cannot win.
	 * @return Generator Yields pairs and the residents used.
	 */
	protected function moved_down_pairings( array $s1, array $residents, array $forbid, $prune ) {
		foreach ( $s1 as $player ) {
			if ( isset( $forbid[ $player ] ) ) {
				return;
			}
		}
		yield from $this->extend_pairing( $s1, $residents, $forbid, $prune, 0, array(), array() );
	}

	/**
	 * Depth-first search over the residents each S1 player could be paired with.
	 *
	 * @param int[]    $s1        S1 players.
	 * @param int[]    $residents Candidate partners.
	 * @param array    $forbid    Players that must not be paired.
	 * @param callable $prune     Returns true if a partial list of pairs cannot win.
	 * @param int      $i         Position in S1.
	 * @param array    $pairs     Pairs so far.
	 * @param array    $used      Partners used so far.
	 * @return Generator
	 */
	protected function extend_pairing( array $s1, array $residents, array $forbid, $prune, $i, array $pairs, array $used ) {
		if ( count( $s1 ) === $i ) {
			yield array(
				'pairs' => $pairs,
				'used'  => array_keys( $used ),
			);
			return;
		}

		foreach ( $residents as $partner ) {
			if ( isset( $used[ $partner ] ) || isset( $forbid[ $partner ] ) || ! $this->can_pair( $s1[ $i ], $partner ) ) {
				continue;
			}
			$pairs_next   = $pairs;
			$pairs_next[] = array( $s1[ $i ], $partner );
			if ( $prune( $pairs_next ) ) {
				continue;
			}
			$used_next             = $used;
			$used_next[ $partner ] = true;
			yield from $this->extend_pairing( $s1, $residents, $forbid, $prune, $i + 1, $pairs_next, $used_next );
		}
	}

	/**
	 * Candidates for a homogeneous bracket (or remainder): S1 is the first n1
	 * players; transpositions of S2 are tried, then exchanges between S1 and S2.
	 *
	 * @param int[]    $list   Players in rank order.
	 * @param int      $n1     Number of pairs.
	 * @param array    $forbid Players that must not be paired.
	 * @param callable $prune  Returns true if a partial list of pairs cannot win.
	 * @return Generator Yields pairs and down.
	 */
	protected function homogeneous( array $list, $n1, array $forbid, $prune, $aim = null ) {
		if ( 0 === $n1 ) {
			yield array(
				'pairs' => array(),
				'down'  => $list,
			);
			return;
		}

		$total = count( $list );
		$s1    = range( 0, $n1 - 1 );
		$s2    = range( $n1, $total - 1 );

		yield from $this->transpositions( $list, $s1, $s2, $forbid, $prune );

		$max_size = min( $n1, count( $s2 ) );
		for ( $size = 1; $size <= $max_size; $size++ ) {
			$exchanges = null === $aim ? $this->exchanges( $s1, $s2, $size ) : $this->reaching_exchanges( $list, $s1, $s2, $size, $aim );
			foreach ( $exchanges as $exchange ) {
				$new_s1 = array_merge( array_diff( $s1, $exchange['a'] ), $exchange['b'] );
				$new_s2 = array_merge( array_diff( $s2, $exchange['b'] ), $exchange['a'] );
				sort( $new_s1 );
				sort( $new_s2 );
				yield from $this->transpositions( $list, $new_s1, $new_s2, $forbid, $prune );
			}
		}
	}

	/**
	 * The exchanges of a given size, in the same order as exchanges(), that leave
	 * a split of the bracket whose colour lower bounds do not exceed an aim.
	 *
	 * Only how many players due White, due Black and with no preference move each
	 * way matters for the bound, so those combinations are tried first and only
	 * the exchanges of the ones that pass are listed.
	 *
	 * @param int[] $list Players in rank order.
	 * @param int[] $s1   Positions in S1.
	 * @param int[] $s2   Positions in S2.
	 * @param int   $size Number of players swapped each way.
	 * @param int[] $aim  Largest C12 and C13 bounds allowed.
	 * @return array[] Each: a (moved from S1), b (moved from S2).
	 */
	protected function reaching_exchanges( array $list, array $s1, array $s2, $size, array $aim ) {
		$kinds = array( 'W', 'B', '' );

		$group  = array(
			1 => array(
				'W' => array(),
				'B' => array(),
				''  => array(),
			),
			2 => array(
				'W' => array(),
				'B' => array(),
				''  => array(),
			),
		);
		$others = 0;
		foreach ( array(
			1 => $s1,
			2 => $s2,
		) as $side => $positions ) {
			foreach ( $positions as $position ) {
				$player                    = $list[ $position ];
				$pref                      = null === $this->pref[ $player ] ? '' : $this->pref[ $player ];
				$group[ $side ][ $pref ][] = $position;
				if ( '' !== $pref && self::STRONG !== $this->strength[ $player ] ) {
					++$others;
				}
			}
		}

		$found = array();
		// How many of each kind leave S1 (out) and S2 (in).
		foreach ( $this->splits_of( $size, array( count( $group[1]['W'] ), count( $group[1]['B'] ), count( $group[1][''] ) ) ) as $out ) {
			foreach ( $this->splits_of( $size, array( count( $group[2]['W'] ), count( $group[2]['B'] ), count( $group[2][''] ) ) ) as $in ) {
				$after = array(
					1 => array(),
					2 => array(),
				);
				foreach ( $kinds as $i => $kind ) {
					$after[1][ $kind ] = count( $group[1][ $kind ] ) - $out[ $i ] + $in[ $i ];
					$after[2][ $kind ] = count( $group[2][ $kind ] ) - $in[ $i ] + $out[ $i ];
				}
				$c12 = $this->split_violations( $after[1], $after[2] );
				if ( $c12 > $aim[0] || max( 0, $c12 - $others ) > $aim[1] ) {
					continue;
				}

				// Every choice of players for this combination.
				$sets_out = array( array() );
				$sets_in  = array( array() );
				foreach ( $kinds as $i => $kind ) {
					$sets_out = $this->extend_sets( $sets_out, $group[1][ $kind ], $out[ $i ] );
					$sets_in  = $this->extend_sets( $sets_in, $group[2][ $kind ], $in[ $i ] );
				}
				foreach ( $sets_out as $a ) {
					foreach ( $sets_in as $b ) {
						sort( $a );
						sort( $b );
						$found[] = array(
							'a'   => $a,
							'b'   => $b,
							'gap' => array_sum( $b ) - array_sum( $a ),
						);
						if ( count( $found ) > self::EXCHANGE_LIMIT ) {
							$this->truncated = true;
							break 4;
						}
					}
				}
			}
		}

		usort( $found, array( $this, 'compare_exchanges' ) );
		return $found;
	}

	/**
	 * The ways to take a number of players from three groups.
	 *
	 * @param int   $size Players to take in all.
	 * @param int[] $room How many each group has.
	 * @return int[][] Counts to take from each group.
	 */
	protected function splits_of( $size, array $room ) {
		$splits = array();
		for ( $x = min( $size, $room[0] ); $x >= 0; $x-- ) {
			for ( $y = min( $size - $x, $room[1] ); $y >= 0; $y-- ) {
				$z = $size - $x - $y;
				if ( $z <= $room[2] ) {
					$splits[] = array( $x, $y, $z );
				}
			}
		}
		return $splits;
	}

	/**
	 * Every way of adding a choice of $size players from a group to each set.
	 *
	 * @param array[] $sets  Sets so far.
	 * @param int[]   $group Positions to choose from.
	 * @param int     $size  How many to choose.
	 * @return array[]
	 */
	protected function extend_sets( array $sets, array $group, $size ) {
		if ( 0 === $size ) {
			return $sets;
		}
		$extended = array();
		foreach ( $sets as $set ) {
			foreach ( $this->combinations( $group, $size ) as $choice ) {
				$extended[] = array_merge( $set, $choice );
			}
		}
		return $extended;
	}

	/**
	 * Order of two exchanges (Article 4.3.2): the smaller difference between the
	 * numbers of the players swapped, then the last players of S1 to move.
	 *
	 * @param array $x Exchange with a, b and gap.
	 * @param array $y Exchange with a, b and gap.
	 * @return int
	 */
	protected function compare_exchanges( $x, $y ) {
		if ( $x['gap'] !== $y['gap'] ) {
			return $x['gap'] <=> $y['gap'];
		}
		$xa = array_reverse( $x['a'] );
		$ya = array_reverse( $y['a'] );
		foreach ( $xa as $i => $value ) {
			if ( $value !== $ya[ $i ] ) {
				return $ya[ $i ] <=> $value;
			}
		}
		foreach ( $x['b'] as $i => $value ) {
			if ( $value !== $y['b'][ $i ] ) {
				return $value <=> $y['b'][ $i ];
			}
		}
		return 0;
	}

	/**
	 * All exchanges of a given size, sorted as in Article 4.3.2.
	 *
	 * @param int[] $s1   Positions in S1.
	 * @param int[] $s2   Positions in S2.
	 * @param int   $size Number of players swapped each way.
	 * @return array[] Each: a (moved from S1), b (moved from S2).
	 */
	protected function exchanges( array $s1, array $s2, $size ) {
		$memo_key = count( $s1 ) . '|' . count( $s2 ) . '|' . $size;
		if ( isset( $this->memo['exchange'][ $memo_key ] ) ) {
			return $this->memo['exchange'][ $memo_key ];
		}

		$found = array();
		foreach ( $this->combinations( $s1, $size ) as $a ) {
			foreach ( $this->combinations( $s2, $size ) as $b ) {
				$found[] = array(
					'a'   => $a,
					'b'   => $b,
					'gap' => array_sum( $b ) - array_sum( $a ),
				);
				if ( count( $found ) > self::EXCHANGE_LIMIT ) {
					$this->truncated = true;
					break 2;
				}
			}
		}

		usort( $found, array( $this, 'compare_exchanges' ) );

		$this->memo['exchange'][ $memo_key ] = $found;
		return $found;
	}

	/**
	 * Transpositions of S2: every ordered choice of partners for the players
	 * of S1, in lexicographic order, skipping illegal pairs.
	 *
	 * @param int[]    $list   Players in rank order.
	 * @param int[]    $s1     Positions in S1.
	 * @param int[]    $s2     Positions in S2.
	 * @param array    $forbid Players that must not be paired.
	 * @param callable $prune  Returns true if a partial list of pairs cannot win.
	 * @return Generator
	 */
	protected function transpositions( array $list, array $s1, array $s2, array $forbid, $prune ) {
		foreach ( $s1 as $position ) {
			if ( isset( $forbid[ $list[ $position ] ] ) ) {
				return;
			}
		}

		// Can anything in this S1/S2 split still win?
		$rest = array(
			's1' => array(),
			's2' => array(),
		);
		foreach ( $s1 as $position ) {
			$rest['s1'][] = $list[ $position ];
		}
		foreach ( $s2 as $position ) {
			$rest['s2'][] = $list[ $position ];
		}
		if ( ! $this->can_fill( $rest['s1'], $rest['s2'], $forbid ) || $prune( array(), $rest ) ) {
			return;
		}

		yield from $this->transposition_step( $list, $s1, $s2, $forbid, $prune, 0, array(), array() );
	}

	/**
	 * Whether every one of some players can be given a different partner from
	 * another list, with nobody meeting an opponent they have played (a bipartite
	 * matching, found by augmenting paths).
	 *
	 * @param int[] $left   Players to be given a partner.
	 * @param int[] $right  Possible partners.
	 * @param array $forbid Players that must not be paired.
	 * @return bool
	 */
	protected function can_fill( array $left, array $right, array $forbid ) {
		if ( count( $left ) > count( $right ) ) {
			return false;
		}

		$options = array();
		foreach ( $left as $i => $player ) {
			$options[ $i ] = array();
			foreach ( $right as $j => $partner ) {
				if ( ! isset( $forbid[ $partner ] ) && $this->can_pair( $player, $partner ) ) {
					$options[ $i ][] = $j;
				}
			}
			if ( empty( $options[ $i ] ) ) {
				return false;
			}
		}

		$taken = array(); // Partner index => the index in $left holding it.
		foreach ( array_keys( $left ) as $i ) {
			$seen = array();
			if ( ! $this->augment( $i, $options, $taken, $seen ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Find a partner for one player, moving those already matched if need be.
	 *
	 * @param int   $i       Index of the player to match.
	 * @param array $options Partner indexes each player may have.
	 * @param array $taken   Partner index => player index holding it (by reference).
	 * @param array $seen    Partners already tried in this search (by reference).
	 * @return bool
	 */
	protected function augment( $i, array $options, array &$taken, array &$seen ) {
		foreach ( $options[ $i ] as $j ) {
			if ( isset( $seen[ $j ] ) ) {
				continue;
			}
			$seen[ $j ] = true;
			if ( ! isset( $taken[ $j ] ) || $this->augment( $taken[ $j ], $options, $taken, $seen ) ) {
				$taken[ $j ] = $i;
				return true;
			}
		}
		return false;
	}

	/**
	 * One level of the transposition search.
	 *
	 * @param int[]    $list   Players in rank order.
	 * @param int[]    $s1     Positions in S1.
	 * @param int[]    $s2     Positions in S2.
	 * @param array    $forbid Players that must not be paired.
	 * @param callable $prune  Returns true if a partial list of pairs cannot win.
	 * @param int      $i      Position in S1 being paired.
	 * @param array    $pairs  Pairs chosen so far.
	 * @param array    $used   Set of partner positions used.
	 * @return Generator
	 */
	protected function transposition_step( array $list, array $s1, array $s2, array $forbid, $prune, $i, array $pairs, array $used ) {
		$s1_count = count( $s1 );
		if ( $s1_count === $i ) {
			$down = array();
			foreach ( $s2 as $position ) {
				if ( ! isset( $used[ $position ] ) ) {
					$down[] = $list[ $position ];
				}
			}
			yield array(
				'pairs' => $pairs,
				'down'  => $down,
			);
			return;
		}

		$player = $list[ $s1[ $i ] ];
		foreach ( $s2 as $position ) {
			if ( isset( $used[ $position ] ) ) {
				continue;
			}
			$partner = $list[ $position ];
			if ( isset( $forbid[ $partner ] ) || ! $this->can_pair( $player, $partner ) ) {
				continue;
			}
			$pairs_next   = $pairs;
			$pairs_next[] = array( $player, $partner );

			$used_next              = $used;
			$used_next[ $position ] = true;

			// The players of S1 and S2 that are left once this pair is made.
			$rest = array(
				's1' => array(),
				's2' => array(),
			);
			for ( $j = $i + 1; $j < $s1_count; $j++ ) {
				$rest['s1'][] = $list[ $s1[ $j ] ];
			}
			foreach ( $s2 as $other ) {
				if ( ! isset( $used_next[ $other ] ) ) {
					$rest['s2'][] = $list[ $other ];
				}
			}
			// Prune on the score, and on whether the rest of S1 can still all be given a partner.
			if ( $prune( $pairs_next, $rest ) || ! $this->can_fill( $rest['s1'], $rest['s2'], $forbid ) ) {
				continue;
			}
			yield from $this->transposition_step( $list, $s1, $s2, $forbid, $prune, $i + 1, $pairs_next, $used_next );
		}
	}

	/**
	 * The colour criteria C10 to C13 contributed by pairing two players.
	 *
	 * @param int $a Player index.
	 * @param int $b Player index.
	 * @return int[] c10, c11, c12, c13.
	 */
	protected function pair_components( $a, $b ) {
		$key = $a < $b ? $a * $this->count + $b : $b * $this->count + $a;
		if ( isset( $this->memo['parts'][ $key ] ) ) {
			return $this->memo['parts'][ $key ];
		}

		list( $white, $black ) = $this->alloc( $a, $b );
		$assigned              = array(
			$white => 'W',
			$black => 'B',
		);
		$opponent              = array(
			$white => $black,
			$black => $white,
		);

		$c10 = 0;
		$c11 = 0;
		$c12 = 0;
		$c13 = 0;
		foreach ( $assigned as $player => $colour ) {
			$wanted = $this->pref[ $player ];
			if ( null !== $wanted && $wanted !== $colour ) {
				++$c12;
				if ( self::STRONG === $this->strength[ $player ] ) {
					++$c13;
				}
			}

			if ( $this->final_round && ( $this->top[ $player ] || $this->top[ $opponent[ $player ] ] ) ) {
				if ( abs( $this->diff[ $player ] + ( 'W' === $colour ? 1 : -1 ) ) > 2 ) {
					++$c10;
				}
				$history = $this->colours[ $player ];
				$length  = count( $history );
				if ( $length >= 2 && $history[ $length - 1 ] === $colour && $history[ $length - 2 ] === $colour ) {
					++$c11;
				}
			}
		}

		$this->memo['parts'][ $key ] = array( $c10, $c11, $c12, $c13 );
		return $this->memo['parts'][ $key ];
	}

	/**
	 * Add criteria counts (c10, c11, c12, c13, c14, c16) into a full score vector.
	 *
	 * @param int[] $vector Score vector from evaluate().
	 * @param int[] $parts  Six counts.
	 * @return int[]
	 */
	protected function add_parts( array $vector, array $parts ) {
		foreach ( array( 0, 1, 2, 3, 4, 6 ) as $slot => $position ) {
			$vector[ $position ] += $parts[ $slot ];
		}
		return $vector;
	}

	/**
	 * Unpack an integer holding the six additive criteria.
	 *
	 * @param int $packed Packed value.
	 * @return int[] c10, c11, c12, c13, c14, c16.
	 */
	protected function unpack_parts( $packed ) {
		$parts = array();
		for ( $i = 5; $i >= 0; $i-- ) {
			$parts[ $i ] = $packed % self::RADIX;
			$packed      = intdiv( $packed, self::RADIX );
		}
		ksort( $parts );
		return array_values( $parts );
	}

	/**
	 * Prepare the exact optimisation of a bracket: the best score over all
	 * pairings of the residents (dynamic programming over subsets), and the
	 * overall best score once moved-down players are paired first.
	 *
	 * @param int[]   $mdps           Moved-down players.
	 * @param int[]   $residents      Residents.
	 * @param int     $k              Number of pairs.
	 * @param int     $m              Moved-down players paired.
	 * @param array[] $allowed        Allowed downfloater sets.
	 * @param array   $allowed_limbos Allowed limbo sets, by key.
	 * @param array   $forbid         Players that must not be paired.
	 * @param int     $width          Padding width for score-difference lists.
	 * @return array|null optimum (score vector) and the tables used to bound the search.
	 */
	protected function optimisation_plan( array $mdps, array $residents, $k, $m, array $allowed, array $allowed_limbos, array $forbid, $width ) {
		$count = count( $residents );
		$size  = 1 << $count;
		$radix = self::RADIX;
		$inf   = PHP_INT_MAX;

		$bit = array();
		foreach ( $residents as $index => $player ) {
			$bit[ $player ] = $index;
		}

		// Packed cost of pairing two residents, and of leaving one unpaired.
		$pair_cost = array();
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( isset( $forbid[ $residents[ $i ] ] ) || isset( $forbid[ $residents[ $j ] ] ) || ! $this->can_pair( $residents[ $i ], $residents[ $j ] ) ) {
					$pair_cost[ $i ][ $j ] = $inf;
					continue;
				}
				$parts                 = $this->pair_components( $residents[ $i ], $residents[ $j ] );
				$pair_cost[ $i ][ $j ] = ( ( ( $parts[0] * $radix + $parts[1] ) * $radix + $parts[2] ) * $radix + $parts[3] ) * $radix * $radix;
			}
		}
		$leave_cost = array();
		foreach ( $residents as $index => $player ) {
			$down_previous        = isset( $this->float[ $player ][ $this->round - 1 ] ) && 'down' === $this->float[ $player ][ $this->round - 1 ] ? 1 : 0;
			$down_before          = isset( $this->float[ $player ][ $this->round - 2 ] ) && 'down' === $this->float[ $player ][ $this->round - 2 ] ? 1 : 0;
			$leave_cost[ $index ] = $down_previous * $radix + $down_before;
		}

		// f[mask]: cheapest way to pair up exactly the residents in mask.
		$trailing = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$trailing[ 1 << $i ] = $i;
		}
		$pop  = array_fill( 0, $size, 0 );
		$f    = array_fill( 0, $size, $inf );
		$f[0] = 0;
		for ( $mask = 1; $mask < $size; $mask++ ) {
			$pop[ $mask ] = $pop[ $mask >> 1 ] + ( $mask & 1 );
			if ( 1 === ( $pop[ $mask ] & 1 ) ) {
				continue;
			}
			$low  = $mask & -$mask;
			$a    = $trailing[ $low ];
			$rest = $mask ^ $low;
			$best = $inf;
			for ( $others = $rest; $others; ) {
				$lowest  = $others & -$others;
				$others ^= $lowest;
				$b       = $trailing[ $lowest ];
				$cost    = $pair_cost[ $a ][ $b ];
				if ( $inf === $cost ) {
					continue;
				}
				$tail = $f[ $rest ^ $lowest ];
				if ( $inf !== $tail && $cost + $tail < $best ) {
					$best = $cost + $tail;
				}
			}
			$f[ $mask ] = $best;
		}

		// The leftover masks each allowed limbo set can combine with.
		$leftovers = array();
		foreach ( $allowed as $record ) {
			$mask = 0;
			foreach ( array_diff( $record['down'], $record['limbo'] ) as $player ) {
				$mask |= 1 << $bit[ $player ];
			}
			$leftovers[ implode( ',', $record['limbo'] ) ][] = $mask;
		}

		$plan = array(
			'bit'       => $bit,
			'trailing'  => $trailing,
			'f'         => $f,
			'leave'     => $leave_cost,
			'leftovers' => $leftovers,
			'full'      => $size - 1,
			'best'      => array(),
			'optimum'   => null,
		);

		$this->plan = $plan;

		// Best overall score: every way of pairing the moved-down players, plus the best remainder.
		$optimum        = null;
		$none           = function () {
			return false;
		};
		$limbos         = empty( $mdps ) ? array( array() ) : $this->limbo_sets( $mdps, count( $mdps ) - $m );
		$resident_score = empty( $residents ) ? 0 : $this->score[ $residents[0] ];
		foreach ( $limbos as $limbo ) {
			if ( ! empty( $mdps ) && ! isset( $allowed_limbos[ implode( ',', $limbo ) ] ) ) {
				continue;
			}
			$s1 = array_values( array_diff( $mdps, $limbo ) );
			foreach ( $this->moved_down_pairings( $s1, $residents, $forbid, $none ) as $prefix ) {
				$used = 0;
				foreach ( $prefix['used'] as $player ) {
					$used |= 1 << $bit[ $player ];
				}
				$remaining  = $plan['full'] ^ $used;
				$completion = $this->completion_cost( $limbo, $remaining );
				if ( $inf === $completion ) {
					continue;
				}
				$vector = $this->evaluate(
					array(
						'pairs'          => $prefix['pairs'],
						'mdp_pairs'      => $prefix['pairs'],
						'left'           => array(),
						'limbo'          => $limbo,
						'resident_score' => $resident_score,
					),
					$width
				);
				$vector = $this->add_parts( $vector, $this->unpack_parts( $completion ) );
				if ( null === $optimum || $this->compare_vectors( $vector, $optimum ) < 0 ) {
					$optimum = $vector;
				}
			}
		}

		if ( null === $optimum ) {
			$this->plan = null;
			return null;
		}
		$this->plan['optimum'] = $optimum;
		return $this->plan;
	}

	/**
	 * Cheapest packed cost of completing a bracket from the residents still
	 * unpaired: choose which to leave unpaired (an allowed downfloater set) and
	 * pair up the rest.
	 *
	 * @param array $plan      Optimisation plan.
	 * @param int[] $limbo     Moved-down players in limbo.
	 * @param int   $remaining Bit mask of unpaired residents.
	 * @return int Packed cost, or PHP_INT_MAX if impossible.
	 */
	protected function completion_cost( array $limbo, $remaining ) {
		$plan     = &$this->plan;
		$key      = implode( ',', $limbo );
		$cache_id = $key . '|' . $remaining;
		if ( isset( $plan['best'][ $cache_id ] ) ) {
			return $plan['best'][ $cache_id ];
		}

		$best = PHP_INT_MAX;
		if ( isset( $plan['leftovers'][ $key ] ) ) {
			foreach ( $plan['leftovers'][ $key ] as $leftover ) {
				if ( ( $leftover & $remaining ) !== $leftover ) {
					continue;
				}
				$tail = $plan['f'][ $remaining ^ $leftover ];
				if ( PHP_INT_MAX === $tail ) {
					continue;
				}
				$leave = 0;
				for ( $bits = $leftover; $bits; ) {
					$lowest = $bits & -$bits;
					$bits  ^= $lowest;
					$leave += $plan['leave'][ $plan['trailing'][ $lowest ] ];
				}
				if ( $tail + $leave < $best ) {
					$best = $tail + $leave;
				}
			}
		}

		$plan['best'][ $cache_id ] = $best;
		return $best;
	}

	/**
	 * Lower bound for what is still to come while a search is part-way
	 * through: the exact cheapest completion, as counts to add to the score.
	 *
	 * @param array $plan      Optimisation plan.
	 * @param int[] $limbo     Moved-down players in limbo.
	 * @param int   $remaining Bit mask of unpaired residents.
	 * @return int[] Six counts; a large value if no completion exists.
	 */
	protected function completion_bound( array $limbo, $remaining ) {
		$cost = $this->completion_cost( $limbo, $remaining );
		if ( PHP_INT_MAX === $cost ) {
			return array( 99999, 0, 0, 0, 0, 0 );
		}
		return $this->unpack_parts( $cost );
	}

	/**
	 * Bit mask of the residents not yet paired, given the pairs chosen so far.
	 *
	 * @param array $plan         Optimisation plan.
	 * @param array $pairs        Remainder pairs chosen so far.
	 * @param array $prefix_pairs Moved-down pairs (their residents are used up too).
	 * @return int
	 */
	protected function remaining_mask( array $pairs, array $prefix_pairs ) {
		$plan = $this->plan;
		$mask = $plan['full'];
		foreach ( $pairs as $pair ) {
			$mask &= ~( 1 << $plan['bit'][ $pair[0] ] );
			$mask &= ~( 1 << $plan['bit'][ $pair[1] ] );
		}
		foreach ( $prefix_pairs as $pair ) {
			$mask &= ~( 1 << $plan['bit'][ $pair[1] ] );
		}
		return $mask;
	}

	/**
	 * All k-element subsets of a list, in lexicographic order of position.
	 *
	 * @param array $items List of values.
	 * @param int   $size  Subset size.
	 * @return Generator
	 */
	protected function combinations( array $items, $size ) {
		$items = array_values( $items );
		$total = count( $items );
		if ( $size > $total || $size < 0 ) {
			return;
		}
		if ( 0 === $size ) {
			yield array();
			return;
		}

		$index = range( 0, $size - 1 );
		while ( true ) {
			$subset = array();
			foreach ( $index as $position ) {
				$subset[] = $items[ $position ];
			}
			yield $subset;

			$i = $size - 1;
			while ( $i >= 0 && $index[ $i ] === $total - $size + $i ) {
				--$i;
			}
			if ( $i < 0 ) {
				return;
			}
			++$index[ $i ];
			for ( $j = $i + 1; $j < $size; $j++ ) {
				$index[ $j ] = $index[ $j - 1 ] + 1;
			}
		}
	}

	/* -------------------------------------------------------------
	 * Candidate quality (C10-C21)
	 * ------------------------------------------------------------- */

	/**
	 * Score a candidate on criteria C10 to C21 (lower is better).
	 *
	 * @param array $candidate Candidate with pairs, mdp_pairs and left.
	 * @param int   $width     Length to pad the score-difference lists to.
	 * @return int[]
	 */
	protected function evaluate( array $candidate, $width ) {
		$c10 = 0;
		$c11 = 0;
		$c12 = 0;
		$c13 = 0;
		foreach ( $candidate['pairs'] as $pair ) {
			$parts = $this->pair_components( $pair[0], $pair[1] );
			$c10  += $parts[0];
			$c11  += $parts[1];
			$c12  += $parts[2];
			$c13  += $parts[3];
		}

		$previous = $this->round - 1;
		$before   = $this->round - 2;

		$c14 = 0;
		$c16 = 0;
		foreach ( $candidate['left'] as $player ) {
			if ( isset( $this->float[ $player ][ $previous ] ) && 'down' === $this->float[ $player ][ $previous ] ) {
				++$c14;
			}
			if ( isset( $this->float[ $player ][ $before ] ) && 'down' === $this->float[ $player ][ $before ] ) {
				++$c16;
			}
		}

		$c15 = 0;
		$c17 = 0;
		$c18 = array();
		$c19 = array();
		$c20 = array();
		$c21 = array();
		foreach ( $candidate['mdp_pairs'] as $pair ) {
			list( $mover, $resident ) = $pair;
			$gap                      = $this->score[ $mover ] - $this->score[ $resident ];

			if ( isset( $this->float[ $resident ][ $previous ] ) && 'up' === $this->float[ $resident ][ $previous ] ) {
				++$c15;
				$c19[] = $gap;
			}
			if ( isset( $this->float[ $resident ][ $before ] ) && 'up' === $this->float[ $resident ][ $before ] ) {
				++$c17;
				$c21[] = $gap;
			}
		}

		// C18 and C20: a moved-down player who already floated down should not float again, so
		// those left in limbo count against the candidate, by how far above the residents they are.
		if ( ! empty( $candidate['limbo'] ) ) {
			$resident_score = $candidate['resident_score'];
			foreach ( $candidate['limbo'] as $player ) {
				if ( isset( $this->float[ $player ][ $previous ] ) && 'down' === $this->float[ $player ][ $previous ] ) {
					$c18[] = $this->score[ $player ] - $resident_score;
				}
				if ( isset( $this->float[ $player ][ $before ] ) && 'down' === $this->float[ $player ][ $before ] ) {
					$c20[] = $this->score[ $player ] - $resident_score;
				}
			}
		}

		$vector = array( $c10, $c11, $c12, $c13, $c14, $c15, $c16, $c17 );
		foreach ( array( $c18, $c19, $c20, $c21 ) as $gaps ) {
			rsort( $gaps );
			$vector = array_merge( $vector, array_pad( $gaps, $width, 0 ) );
		}
		return $vector;
	}

	/**
	 * Compare two score vectors element by element.
	 *
	 * @param int[] $a First vector.
	 * @param int[] $b Second vector.
	 * @return int Negative if $a is better (smaller).
	 */
	protected function compare_vectors( array $a, array $b ) {
		$length = max( count( $a ), count( $b ) );
		for ( $i = 0; $i < $length; $i++ ) {
			$x = isset( $a[ $i ] ) ? $a[ $i ] : 0;
			$y = isset( $b[ $i ] ) ? $b[ $i ] : 0;
			if ( $x !== $y ) {
				return $x <=> $y;
			}
		}
		return 0;
	}
}
