<?php
/**
 * The data work for the league blocks, from the fixtures the LMS v2 API gives
 * (see Chess_Army_Knife_LMS_Client::get_fixtures()): the league table, one
 * team's results and its next fixture. The v2 API has no table of its own, so
 * the table is worked out here from the results. Nothing here needs WordPress,
 * so it is tested on its own.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_League_Data {

	/**
	 * Match points for a win, a draw and a defeat. Leagues differ, so this is
	 * filterable (see points_system()); two for a win and one for a draw is the usual.
	 *
	 * @return int[]|float[] Keys win, draw, loss.
	 */
	public static function points_system() {
		$points = array(
			'win'  => 2,
			'draw' => 1,
			'loss' => 0,
		);

		/**
		 * Filter the match points the league table gives.
		 *
		 * @param array $points Keys win, draw and loss.
		 */
		$filtered = apply_filters( 'Chess_Army_Knife_league_points', $points );

		return is_array( $filtered ) ? array_merge( $points, $filtered ) : $points;
	}

	/**
	 * Whether a fixture has been played: it has a result.
	 *
	 * @param array $fixture Row from normalise_fixture().
	 * @return bool
	 */
	public static function is_played( array $fixture ) {
		return in_array( $fixture['winner'], array( 'home', 'away', 'draw' ), true ) && '' !== $fixture['home_score'] && '' !== $fixture['away_score'];
	}

	/**
	 * The league table, worked out from the fixtures.
	 *
	 * @param array[] $fixtures Rows from normalise_fixture().
	 * @param array   $points   Keys win, draw, loss (see points_system()).
	 * @return array[] Best first, each { position, team, played, won, drawn, lost, points, for, against }.
	 *                 for and against are the board points won and conceded.
	 */
	public static function standings( array $fixtures, array $points = array() ) {
		$points = $points + self::points_system();
		$table  = array();

		foreach ( $fixtures as $fixture ) {
			// A team is in the table as soon as it has a fixture, so an unplayed league is not empty.
			foreach ( array( $fixture['home'], $fixture['away'] ) as $team ) {
				if ( '' !== $team && ! isset( $table[ $team ] ) ) {
					$table[ $team ] = array(
						'team'    => $team,
						'played'  => 0,
						'won'     => 0,
						'drawn'   => 0,
						'lost'    => 0,
						'points'  => 0,
						'for'     => 0.0,
						'against' => 0.0,
					);
				}
			}
			if ( ! self::is_played( $fixture ) || '' === $fixture['home'] || '' === $fixture['away'] ) {
				continue;
			}

			foreach ( array(
				'home' => array( $fixture['home'], (float) $fixture['home_score'], (float) $fixture['away_score'] ),
				'away' => array( $fixture['away'], (float) $fixture['away_score'], (float) $fixture['home_score'] ),
			) as $side => $row ) {
				list( $team, $for, $against ) = $row;

				++$table[ $team ]['played'];
				$table[ $team ]['for']     += $for;
				$table[ $team ]['against'] += $against;

				if ( 'draw' === $fixture['winner'] ) {
					++$table[ $team ]['drawn'];
					$table[ $team ]['points'] += $points['draw'];
				} elseif ( $side === $fixture['winner'] ) {
					++$table[ $team ]['won'];
					$table[ $team ]['points'] += $points['win'];
				} else {
					++$table[ $team ]['lost'];
					$table[ $team ]['points'] += $points['loss'];
				}
			}
		}

		$rows = array_values( $table );
		usort(
			$rows,
			function ( $a, $b ) {
				// Match points, then board points won, then name so the order is steady.
				return array( $b['points'], $b['for'], $a['team'] ) <=> array( $a['points'], $a['for'], $b['team'] );
			}
		);

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['position'] = $i + 1;
		}

		return $rows;
	}

	/**
	 * Whether a fixture is one of a team's.
	 *
	 * @param array  $fixture Row from normalise_fixture().
	 * @param string $team    Team name; case and extra spaces are ignored.
	 * @return string 'home', 'away' or '' if the team is not in it.
	 */
	public static function side_of( array $fixture, $team ) {
		$clean = function ( $name ) {
			return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $name ) ) );
		};

		if ( $clean( $fixture['home'] ) === $clean( $team ) ) {
			return 'home';
		}

		return $clean( $fixture['away'] ) === $clean( $team ) ? 'away' : '';
	}

	/**
	 * One team's fixtures from its point of view.
	 *
	 * @param array[] $fixtures Rows from normalise_fixture().
	 * @param string  $team     Team name.
	 * @return array[] Soonest first, each { fixture, side, opponent, for, against, outcome }.
	 *                 outcome is 'won', 'drawn', 'lost' or '' if it has not been played.
	 */
	public static function team_fixtures( array $fixtures, $team ) {
		$rows = array();

		foreach ( $fixtures as $fixture ) {
			$side = self::side_of( $fixture, $team );
			if ( '' === $side ) {
				continue;
			}

			$played  = self::is_played( $fixture );
			$mine    = 'home' === $side ? $fixture['home_score'] : $fixture['away_score'];
			$theirs  = 'home' === $side ? $fixture['away_score'] : $fixture['home_score'];
			$outcome = '';
			if ( $played ) {
				$outcome = 'draw' === $fixture['winner'] ? 'drawn' : ( $side === $fixture['winner'] ? 'won' : 'lost' );
			}

			$rows[] = array(
				'fixture'  => $fixture,
				'side'     => $side,
				'opponent' => 'home' === $side ? $fixture['away'] : $fixture['home'],
				'for'      => $played ? $mine : '',
				'against'  => $played ? $theirs : '',
				'outcome'  => $outcome,
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( $a['fixture']['date'] . $a['fixture']['time'], $b['fixture']['date'] . $b['fixture']['time'] );
			}
		);

		return $rows;
	}

	/**
	 * A team's next fixture: the first one not yet played that is today or later.
	 *
	 * @param array[] $team_fixtures Rows from team_fixtures().
	 * @param string  $today         "Y-m-d".
	 * @return array|null A row of team_fixtures(), or null if there is none (the season is over, or the rest are unscheduled).
	 */
	public static function next_fixture( array $team_fixtures, $today ) {
		foreach ( $team_fixtures as $row ) {
			if ( '' === $row['outcome'] && '' !== $row['fixture']['date'] && substr( $row['fixture']['date'], 0, 10 ) >= $today ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * How a team's boards went in a fixture, board by board, for a team page.
	 *
	 * @param array  $fixture Row from normalise_fixture().
	 * @param string $side    'home' or 'away': the team to show.
	 * @return array[] Each { board, colour, player, rating, opponent, opponent_rating, result }.
	 *                 colour is 'W' or 'B' for the team's player; result is 'win', 'draw', 'loss' or ''.
	 */
	public static function boards_for_side( array $fixture, $side ) {
		$other  = 'home' === $side ? 'away' : 'home';
		$boards = array();

		foreach ( $fixture['games'] as $game ) {
			$mine   = $game[ $side ];
			$theirs = $game[ $other ];
			$colour = 'home' === $side ? $game['home_colour'] : ( 'W' === $game['home_colour'] ? 'B' : ( 'B' === $game['home_colour'] ? 'W' : '' ) );

			$result = '';
			if ( 'draw' === $game['result'] ) {
				$result = 'draw';
			} elseif ( '' !== $game['result'] ) {
				$result = $game['result'] === $side . '_win' ? 'win' : 'loss';
			}

			$boards[] = array(
				'board'           => $game['board'],
				'colour'          => $colour,
				'player'          => $mine ? $mine['name'] : '',
				'rating'          => $mine ? $mine['rating'] : null,
				'opponent'        => $theirs ? $theirs['name'] : '',
				'opponent_rating' => $theirs ? $theirs['rating'] : null,
				'result'          => $result,
			);
		}

		return $boards;
	}
}
