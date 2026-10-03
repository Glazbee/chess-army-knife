<?php
/**
 * Standings calculation for a set of entrants and their games.
 *
 * Pure logic with no WordPress dependencies, so it can be unit-tested.
 * Scoring is 1 / 1/2 / 0. Ties are broken by head-to-head points among the
 * tied players, then Sonneborn-Berger, then wins, then seed.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Standings {

	const WHITE_WIN = '1-0';
	const BLACK_WIN = '0-1';
	const DRAW      = '1/2-1/2';

	/** White wins because black did not play (a forfeit). */
	const WHITE_FORFEIT_WIN = '+-';

	/** Black wins because white did not play (a forfeit). */
	const BLACK_FORFEIT_WIN = '-+';

	/** Result stored on a bye game the player asked for: half a point. */
	const HALF_POINT_BYE = 'bye-half';

	/** Result stored on a bye game the player asked for: no points. */
	const ZERO_POINT_BYE = 'bye-zero';

	/**
	 * Valid game results.
	 *
	 * @return string[]
	 */
	public static function results() {
		return array( self::WHITE_WIN, self::BLACK_WIN, self::DRAW, self::WHITE_FORFEIT_WIN, self::BLACK_FORFEIT_WIN );
	}

	/**
	 * Whether a result is a forfeit (the game was not actually played).
	 *
	 * @param string|null $result Result.
	 * @return bool
	 */
	public static function is_forfeit( $result ) {
		return self::WHITE_FORFEIT_WIN === $result || self::BLACK_FORFEIT_WIN === $result;
	}

	/**
	 * Points scored by white and black for a result.
	 *
	 * @param string $result One of results().
	 * @return float[]|null [ white, black ], or null if the result is unknown.
	 */
	public static function points_for( $result ) {
		switch ( $result ) {
			case self::WHITE_WIN:
			case self::WHITE_FORFEIT_WIN:
				return array( 1.0, 0.0 );
			case self::BLACK_WIN:
			case self::BLACK_FORFEIT_WIN:
				return array( 0.0, 1.0 );
			case self::DRAW:
				return array( 0.5, 0.5 );
		}
		return null;
	}

	/**
	 * Calculate ranked standings.
	 *
	 * @param array[] $entries Each: id, name, seed, status ('active' or 'withdrawn').
	 * @param array[] $games   Each: white_entry_id, black_entry_id, result, and optionally is_bye.
	 * @param int[]   $scheduled_games Optional map of entry id => games scheduled, for the withdrawal rule.
	 * @param array   $options Optional: swiss (bool) ranks by Buchholz then Sonneborn-Berger instead of
	 *                         head-to-head; bye_points (float) is what a bye game (no black player) scores
	 *                         (a requested half-point or zero-point bye scores its own points); rounds (int)
	 *                         is the tournament length, and turns on the FIDE virtual-opponent rule for the
	 *                         Buchholz of unplayed games (needs a round number on each game).
	 * @return array[] Ranked rows: entry_id, name, seed, rank, played, won, drawn, lost, points, buchholz,
	 *                 sonneborn_berger, withdrawn, excluded.
	 */
	public static function calculate( array $entries, array $games, array $scheduled_games = array(), array $options = array() ) {
		$swiss      = ! empty( $options['swiss'] );
		$bye_points = isset( $options['bye_points'] ) ? (float) $options['bye_points'] : 0.0;
		$rounds     = isset( $options['rounds'] ) ? (int) $options['rounds'] : 0;

		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[ $entry['id'] ] = array(
				'entry_id'         => (int) $entry['id'],
				'name'             => $entry['name'],
				'nickname'         => isset( $entry['nickname'] ) ? $entry['nickname'] : '',
				'seed'             => isset( $entry['seed'] ) ? (int) $entry['seed'] : 0,
				'played'           => 0,
				'won'              => 0,
				'drawn'            => 0,
				'lost'             => 0,
				'points'           => 0.0,
				'buchholz'         => 0.0,
				'sonneborn_berger' => 0.0,
				'withdrawn'        => isset( $entry['status'] ) && 'withdrawn' === $entry['status'],
				'excluded'         => false,
			);
		}

		// Collect the games that have a usable result.
		$valid_games = array();
		$played      = array();
		foreach ( $games as $game ) {
			$white = isset( $game['white_entry_id'] ) ? (int) $game['white_entry_id'] : 0;
			$black = isset( $game['black_entry_id'] ) ? (int) $game['black_entry_id'] : 0;
			$score = isset( $game['result'] ) ? self::points_for( $game['result'] ) : null;
			if ( ! $white || ! $black || null === $score || ! isset( $rows[ $white ], $rows[ $black ] ) ) {
				continue;
			}
			$valid_games[]    = array( $white, $black, $score[0], $score[1], self::is_forfeit( $game['result'] ) );
			$played[ $white ] = ( isset( $played[ $white ] ) ? $played[ $white ] : 0 ) + 1;
			$played[ $black ] = ( isset( $played[ $black ] ) ? $played[ $black ] : 0 ) + 1;
		}

		// A bye (no opponent) scores the configured bye points, unless the player asked for a lesser one.
		$bye_score = array();
		foreach ( $games as $game ) {
			$white = isset( $game['white_entry_id'] ) ? (int) $game['white_entry_id'] : 0;
			if ( empty( $game['is_bye'] ) || ! $white || ! empty( $game['black_entry_id'] ) || ! isset( $rows[ $white ] ) ) {
				continue;
			}
			$result = isset( $game['result'] ) ? $game['result'] : null;
			if ( self::HALF_POINT_BYE === $result && $swiss ) {
				$earned = 0.5;
			} elseif ( self::ZERO_POINT_BYE === $result && $swiss ) {
				$earned = 0.0;
			} else {
				$earned = $bye_points;
			}
			$rows[ $white ]['points'] += $earned;
			if ( isset( $game['round'] ) ) {
				$bye_score[ $white ][ (int) $game['round'] ] = $earned;
			}
		}

		// A withdrawn player who completed under half of their games is dropped from the final
		// standings, and their games no longer count for their opponents either (GR 6.6).
		foreach ( $rows as $id => $row ) {
			$done = isset( $played[ $id ] ) ? $played[ $id ] : 0;
			if ( $row['withdrawn'] && isset( $scheduled_games[ $id ] ) && $scheduled_games[ $id ] > 0 && $done * 2 < $scheduled_games[ $id ] ) {
				$rows[ $id ]['excluded'] = true;
			}
		}

		$played_games = array();
		foreach ( $valid_games as $game ) {
			list( $white, $black, $white_score, $black_score ) = $game;
			if ( $rows[ $white ]['excluded'] || $rows[ $black ]['excluded'] ) {
				continue;
			}
			$played_games[] = $game;

			++$rows[ $white ]['played'];
			++$rows[ $black ]['played'];
			$rows[ $white ]['points'] += $white_score;
			$rows[ $black ]['points'] += $black_score;
			self::tally( $rows[ $white ], $white_score );
			self::tally( $rows[ $black ], $black_score );
		}

		// Sonneborn-Berger: points of each beaten opponent plus half the points of each drawn one.
		// Buchholz: the total points of every opponent actually played (forfeits do not count),
		// plus a virtual opponent for every unplayed game when the tournament length is known.
		if ( $swiss && $rounds > 0 ) {
			self::add_virtual_opponents( $rows, $games, $bye_score, $rounds );
		}
		foreach ( $played_games as $game ) {
			list( $white, $black, $white_score, $black_score, $forfeit ) = array_pad( $game, 5, false );
			if ( $forfeit ) {
				continue;
			}
			$rows[ $white ]['sonneborn_berger'] += $white_score * $rows[ $black ]['points'];
			$rows[ $black ]['sonneborn_berger'] += $black_score * $rows[ $white ]['points'];
			$rows[ $white ]['buchholz']         += $rows[ $black ]['points'];
			$rows[ $black ]['buchholz']         += $rows[ $white ]['points'];
		}

		$ranked = array_values(
			array_filter(
				$rows,
				function ( $row ) {
					return ! $row['excluded'];
				}
			)
		);

		if ( $swiss ) {
			// Swiss: points, then Buchholz, Sonneborn-Berger, wins and finally the starting rank.
			usort(
				$ranked,
				function ( $a, $b ) {
					foreach ( array( 'points', 'buchholz', 'sonneborn_berger', 'won' ) as $field ) {
						if ( $a[ $field ] !== $b[ $field ] ) {
							return $b[ $field ] <=> $a[ $field ];
						}
					}
					return $a['seed'] <=> $b['seed'];
				}
			);
		} else {
			usort(
				$ranked,
				function ( $a, $b ) {
					if ( $a['points'] !== $b['points'] ) {
						return $b['points'] <=> $a['points'];
					}
					return 0;
				}
			);

			$ranked = self::break_ties( $ranked, $played_games );
		}

		foreach ( $ranked as $i => $row ) {
			$ranked[ $i ]['rank'] = $i + 1;
		}

		return $ranked;
	}

	/**
	 * Add the Buchholz of unplayed games: byes, forfeits and rounds a player sat out.
	 *
	 * FIDE Tie-Break Regulations, unplayed games: the player is treated as having met a virtual
	 * opponent who starts the round on the player's own score, scores the opposite of the player's
	 * result in that round, and draws every later round: SVO = SP + (1 - RES) + 0.5 * (rounds - round).
	 * Rounds that are still being played (a game without a result) are left out.
	 *
	 * @param array[] $rows      Standings rows keyed by entry id (by reference); buchholz is added to.
	 * @param array[] $games     Games, each with a round number.
	 * @param array   $bye_score Entry id => round => points earned by a bye.
	 * @param int     $rounds    Tournament length.
	 */
	protected static function add_virtual_opponents( array &$rows, array $games, array $bye_score, $rounds ) {
		$earned  = array(); // Entry id => round => points scored.
		$decided = array(); // Entry id => round => true when the game was played to a result.
		$open    = array(); // Entry id => round => true when the game has no result yet.
		$last    = 0;

		foreach ( $games as $game ) {
			$round = isset( $game['round'] ) ? (int) $game['round'] : 0;
			$last  = max( $last, $round );
			$white = isset( $game['white_entry_id'] ) ? (int) $game['white_entry_id'] : 0;
			$black = isset( $game['black_entry_id'] ) ? (int) $game['black_entry_id'] : 0;
			if ( ! $round || ! $white || ! $black ) {
				continue;
			}

			$score = isset( $game['result'] ) ? self::points_for( $game['result'] ) : null;
			if ( null === $score ) {
				$open[ $white ][ $round ] = true;
				$open[ $black ][ $round ] = true;
				continue;
			}
			$earned[ $white ][ $round ] = $score[0];
			$earned[ $black ][ $round ] = $score[1];
			if ( ! self::is_forfeit( $game['result'] ) ) {
				$decided[ $white ][ $round ] = true;
				$decided[ $black ][ $round ] = true;
			}
		}
		foreach ( $bye_score as $id => $by_round ) {
			foreach ( $by_round as $round => $points ) {
				$earned[ $id ][ $round ] = $points;
				$last                    = max( $last, $round );
			}
		}

		foreach ( $rows as $id => $row ) {
			$before = 0.0;
			for ( $round = 1; $round <= $last; $round++ ) {
				$result = isset( $earned[ $id ][ $round ] ) ? $earned[ $id ][ $round ] : 0.0;
				if ( ! isset( $decided[ $id ][ $round ] ) && ! isset( $open[ $id ][ $round ] ) ) {
					$rows[ $id ]['buchholz'] += $before + ( 1 - $result ) + 0.5 * ( $rounds - $round );
				}
				$before += $result;
			}
		}
	}

	/**
	 * Add one game's outcome to a row's win/draw/loss tally.
	 *
	 * @param array $row   Standings row (by reference).
	 * @param float $score Points scored in the game.
	 */
	protected static function tally( array &$row, $score ) {
		if ( 1.0 === $score ) {
			++$row['won'];
		} elseif ( 0.5 === $score ) {
			++$row['drawn'];
		} else {
			++$row['lost'];
		}
	}

	/**
	 * Re-order runs of equal points using the tie-break rules.
	 *
	 * @param array[] $ranked       Rows sorted by points.
	 * @param array[] $played_games Each: [ white id, black id, white pts, black pts ].
	 * @return array[]
	 */
	protected static function break_ties( array $ranked, array $played_games ) {
		$result = array();
		$i      = 0;
		$count  = count( $ranked );

		while ( $i < $count ) {
			$j = $i;
			while ( $j + 1 < $count && $ranked[ $j + 1 ]['points'] === $ranked[ $i ]['points'] ) {
				++$j;
			}
			$group = array_slice( $ranked, $i, $j - $i + 1 );

			if ( count( $group ) > 1 ) {
				$ids  = array_column( $group, 'entry_id' );
				$head = array_fill_keys( $ids, 0.0 );
				foreach ( $played_games as $game ) {
					if ( in_array( $game[0], $ids, true ) && in_array( $game[1], $ids, true ) ) {
						$head[ $game[0] ] += $game[2];
						$head[ $game[1] ] += $game[3];
					}
				}
				usort(
					$group,
					function ( $a, $b ) use ( $head ) {
						if ( $head[ $a['entry_id'] ] !== $head[ $b['entry_id'] ] ) {
							return $head[ $b['entry_id'] ] <=> $head[ $a['entry_id'] ];
						}
						if ( $a['sonneborn_berger'] !== $b['sonneborn_berger'] ) {
							return $b['sonneborn_berger'] <=> $a['sonneborn_berger'];
						}
						if ( $a['won'] !== $b['won'] ) {
							return $b['won'] <=> $a['won'];
						}
						return $a['seed'] <=> $b['seed'];
					}
				);
			}

			$result = array_merge( $result, $group );
			$i      = $j + 1;
		}

		return $result;
	}
}
