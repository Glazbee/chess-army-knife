<?php
/**
 * The result of a league match, kept on its event: the score and who played each board.
 *
 * The LMS import fills it in for every fixture that has been played, so past seasons keep their
 * results. Only the club's own players are kept with their ECF code and rating (the code is how
 * they are matched to a member record); an opponent is kept by name only. Someone the club was
 * asked not to record is not kept at all, and when a member is erased their name and code are
 * taken out of every result, leaving the board and its result in place.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Event_Results {

	const META = '_chess_army_event_result';

	/**
	 * A fixture's result in the form it is kept.
	 *
	 * @param array    $match      Normalised match row (see Chess_Army_Knife_LMS_Client::normalise_fixture()).
	 * @param string[] $club_sides 'home' and/or 'away': the sides the club's own teams are on.
	 * @return array|null Null until the fixture has been played. Otherwise {
	 *     @type string $home, $away             Team names.
	 *     @type string $home_score, $away_score Scores as text.
	 *     @type string $winner                  'home', 'away' or 'draw'.
	 *     @type array[] $games                  Each { board, home_colour, result, home, away }; home and away are
	 *                                           { name, code, rating } for the club's side, { name } for the other, or null.
	 * }
	 */
	public static function build( array $match, array $club_sides ) {
		if ( empty( $match['winner'] ) ) {
			return null;
		}

		$games = array();
		foreach ( isset( $match['games'] ) && is_array( $match['games'] ) ? $match['games'] : array() as $game ) {
			$entry = array(
				'board'       => (int) $game['board'],
				'home_colour' => (string) $game['home_colour'],
				'result'      => (string) $game['result'],
			);
			foreach ( array( 'home', 'away' ) as $side ) {
				$player = isset( $game[ $side ] ) && is_array( $game[ $side ] ) ? $game[ $side ] : null;
				if ( ! $player ) {
					$entry[ $side ] = null;
				} else {
					$entry[ $side ] = in_array( $side, $club_sides, true )
						? array(
							'name'   => (string) $player['name'],
							'code'   => (string) $player['code'],
							'rating' => $player['rating'],
						)
						: array( 'name' => (string) $player['name'] );
				}
			}
			$games[] = $entry;
		}

		return array(
			'home'       => (string) $match['home'],
			'away'       => (string) $match['away'],
			'home_score' => (string) $match['home_score'],
			'away_score' => (string) $match['away_score'],
			'winner'     => (string) $match['winner'],
			'games'      => $games,
		);
	}

	/**
	 * Keep an event's result, or clear it when there is none.
	 *
	 * @param int        $event_id Event id.
	 * @param array|null $result   From build().
	 */
	public static function store( $event_id, $result ) {
		if ( ! is_array( $result ) ) {
			delete_post_meta( $event_id, self::META );
			return;
		}

		// Someone who asked not to be recorded is not kept, even by name.
		foreach ( $result['games'] as $index => $game ) {
			foreach ( array( 'home', 'away' ) as $side ) {
				$player = $game[ $side ];
				if ( $player && Chess_Army_Knife_Do_Not_Record::is_blocked( isset( $player['code'] ) ? $player['code'] : '', $player['name'] ) ) {
					$result['games'][ $index ][ $side ] = array( 'name' => '' );
				}
			}
		}

		if ( get_post_meta( $event_id, self::META, true ) !== $result ) {
			update_post_meta( $event_id, self::META, $result );
		}
	}

	/**
	 * An event's result.
	 *
	 * @param int $event_id Event id.
	 * @return array|null
	 */
	public static function get( $event_id ) {
		$result = get_post_meta( $event_id, self::META, true );

		return is_array( $result ) && ! empty( $result['winner'] ) ? $result : null;
	}

	/**
	 * Whether two ECF codes are the same person's: the digits decide, as a code may or may not have its letter.
	 *
	 * @param string $a Code.
	 * @param string $b Code.
	 * @return bool
	 */
	public static function same_code( $a, $b ) {
		$a = preg_replace( '/\D/', '', (string) $a );
		$b = preg_replace( '/\D/', '', (string) $b );

		return '' !== $a && $a === $b;
	}

	/**
	 * A result with one person taken out: their name and code go, the board and its result stay.
	 *
	 * @param array  $result From build().
	 * @param string $code   Their ECF code, or ''.
	 * @param string $name   Their name.
	 * @return array
	 */
	public static function scrub( array $result, $code, $name ) {
		foreach ( $result['games'] as $index => $game ) {
			foreach ( array( 'home', 'away' ) as $side ) {
				$player = $game[ $side ];
				if ( ! $player ) {
					continue;
				}
				// A code settles it; with no code the name is all there is to go on, and only the club's own players have codes.
				$same = isset( $player['code'] ) && '' !== $player['code'] && '' !== preg_replace( '/\D/', '', (string) $code )
					? self::same_code( $player['code'], $code )
					: ( '' === preg_replace( '/\D/', '', (string) $code ) && '' !== $name && 0 === strcasecmp( Chess_Army_Knife_Names::canonical( $player['name'] ), Chess_Army_Knife_Names::canonical( $name ) ) );
				if ( $same ) {
					$result['games'][ $index ][ $side ] = array( 'name' => '' );
				}
			}
		}

		return $result;
	}

	/**
	 * Take a person out of every result (when they are erased).
	 *
	 * @param string $code ECF code, or ''.
	 * @param string $name Name.
	 */
	public static function remove_person( $code, $name ) {
		foreach ( self::event_ids() as $event_id ) {
			$result = get_post_meta( $event_id, self::META, true );
			if ( ! is_array( $result ) || empty( $result['games'] ) ) {
				continue;
			}
			$scrubbed = self::scrub( $result, $code, $name );
			if ( $scrubbed !== $result ) {
				update_post_meta( $event_id, self::META, $scrubbed );
			}
		}
	}

	/**
	 * Every event that has a result, in any status.
	 *
	 * @return int[]
	 */
	protected static function event_ids() {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Chess_Army_Knife_Events::POST_TYPE,
					'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Finds the events that have a result.
				)
			)
		);
	}

	/**
	 * The club's own players across the results, with how often each played. The name and rating are from their latest game.
	 *
	 * @param array[] $results Each { date, result }: the day of the match ("Y-m-d") and its result from build().
	 * @return array[] ECF code => { code, name, rating, games, last_played }; players with no code are left out.
	 */
	public static function club_players( array $results ) {
		usort(
			$results,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		$players = array();
		foreach ( $results as $row ) {
			foreach ( $row['result']['games'] as $game ) {
				foreach ( array( 'home', 'away' ) as $side ) {
					$player = $game[ $side ];
					if ( ! $player || empty( $player['code'] ) || '' === $player['name'] ) {
						continue;
					}
					$key = preg_replace( '/\D/', '', $player['code'] );
					if ( '' === $key ) {
						continue;
					}
					$games = isset( $players[ $key ] ) ? $players[ $key ]['games'] + 1 : 1;
					// Later games come last, so the latest name and rating win.
					$players[ $key ] = array(
						'code'        => $player['code'],
						'name'        => $player['name'],
						'rating'      => isset( $player['rating'] ) ? $player['rating'] : null,
						'games'       => $games,
						'last_played' => $row['date'],
					);
				}
			}
		}

		return $players;
	}

	/**
	 * The club's own players found in the stored results.
	 *
	 * @return array[] See club_players().
	 */
	public static function all_club_players() {
		$results = array();
		foreach ( self::event_ids() as $event_id ) {
			$result = get_post_meta( $event_id, self::META, true );
			if ( is_array( $result ) && ! empty( $result['games'] ) ) {
				$results[] = array(
					'date'   => substr( (string) get_post_meta( $event_id, Chess_Army_Knife_Events::META_START, true ), 0, 10 ),
					'result' => $result,
				);
			}
		}

		return self::club_players( $results );
	}

	/**
	 * A board's result as it is written: 1-0, 0-1 or half-half.
	 *
	 * @param string $result 'home_win', 'away_win', 'draw' or ''.
	 * @return string
	 */
	public static function result_text( $result ) {
		$text = array(
			'home_win' => '1–0',
			'away_win' => '0–1',
			'draw'     => '½–½',
		);

		return isset( $text[ $result ] ) ? $text[ $result ] : '–';
	}

	/**
	 * The match score and the boards, for an event's page.
	 *
	 * @param int $event_id Event id.
	 * @return string HTML (escaped), or '' when there is no result.
	 */
	public static function html( $event_id ) {
		$result = self::get( $event_id );
		if ( ! $result ) {
			return '';
		}

		$html  = '<div class="cak-event__result">';
		$html .= '<p class="cak-event__score">' . esc_html(
			sprintf(
				/* translators: 1: home team, 2: home score, 3: away score, 4: away team */
				__( '%1$s %2$s–%3$s %4$s', 'chess-army-knife' ),
				$result['home'],
				$result['home_score'],
				$result['away_score'],
				$result['away']
			)
		) . '</p>';

		/**
		 * Whether to list the players' names on the boards. The board results are shown either way.
		 *
		 * @param bool $show     Default true: the LMS publishes these names.
		 * @param int  $event_id Event id.
		 */
		$names = (bool) apply_filters( 'Chess_Army_Knife_event_result_show_names', true, $event_id );

		if ( $names && $result['games'] ) {
			$any_name = function ( $player ) {
				return $player && '' !== $player['name'] ? $player['name'] : __( 'A player', 'chess-army-knife' );
			};

			$html .= '<table class="cak-event__boards"><caption class="screen-reader-text">' . esc_html__( 'Results on each board', 'chess-army-knife' ) . '</caption>';
			$html .= '<thead><tr><th scope="col">' . esc_html__( 'Board', 'chess-army-knife' ) . '</th><th scope="col">' . esc_html( $result['home'] ) . '</th><th scope="col">' . esc_html__( 'Result', 'chess-army-knife' ) . '</th><th scope="col">' . esc_html( $result['away'] ) . '</th></tr></thead><tbody>';
			foreach ( $result['games'] as $game ) {
				$html .= '<tr><th scope="row">' . (int) $game['board'] . '</th><td>' . esc_html( $any_name( $game['home'] ) ) . '</td><td>' . esc_html( self::result_text( $game['result'] ) ) . '</td><td>' . esc_html( $any_name( $game['away'] ) ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}

		return $html . '</div>';
	}
}
