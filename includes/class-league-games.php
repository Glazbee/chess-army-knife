<?php
/**
 * League games: the fixtures brought in from the LMS, apart from the club's own events.
 *
 * A league game is an event (so it still shows in the calendar, the feed and the blocks) that
 * the LMS import made, which is the one with an LMS key. The Club events list leaves them out, so
 * it holds only what the club made itself, and this screen lists them instead. Each played game
 * shows its board order and results, and a search of the club's players shows the games each one
 * has played in. The players are found in the kept results by ECF code (see Event_Results), so
 * nobody on the Do Not Record list can appear.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_League_Games {

	const PAGE     = 'chess-army-knife-league-games';
	const PER_PAGE = 30;

	/**
	 * Hook up the Club events list, which leaves league games out.
	 */
	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_from_club_events' ), 20 ); // After the list is sorted.
		add_action( 'add_meta_boxes_' . Chess_Army_Knife_Events::POST_TYPE, array( __CLASS__, 'add_result_box' ) );
		add_action( 'save_post_' . Chess_Army_Knife_Events::POST_TYPE, array( __CLASS__, 'save_result' ) );
	}

	/**
	 * Add the box for correcting a league game's result, on the edit screen of a game that has one.
	 *
	 * @param WP_Post $post The event being edited.
	 */
	public static function add_result_box( $post ) {
		if ( ! Chess_Army_Knife_Event_Results::get( $post->ID ) ) {
			return;
		}
		add_meta_box( 'cak-league-result', __( 'Result', 'chess-army-knife' ), array( __CLASS__, 'render_result_box' ), Chess_Army_Knife_Events::POST_TYPE, 'normal', 'default' );
	}

	/**
	 * Draw the box: the score and who won each board. The players and colours come from the LMS and are not editable.
	 *
	 * @param WP_Post $post The event being edited.
	 */
	public static function render_result_box( $post ) {
		$result = Chess_Army_Knife_Event_Results::get( $post->ID );
		if ( ! $result ) {
			return;
		}
		wp_nonce_field( 'cak_league_result_' . $post->ID, 'cak_league_result_nonce' );

		$outcomes = array(
			''         => __( 'Not played', 'chess-army-knife' ),
			'home_win' => sprintf( /* translators: %s: home team */ __( '%s won', 'chess-army-knife' ), $result['home'] ),
			'draw'     => __( 'Draw', 'chess-army-knife' ),
			'away_win' => sprintf( /* translators: %s: away team */ __( '%s won', 'chess-army-knife' ), $result['away'] ),
		);
		$name_of  = function ( $player ) {
			return $player && '' !== $player['name'] ? $player['name'] : __( 'Default', 'chess-army-knife' );
		};
		?>
		<p class="description"><?php esc_html_e( 'Correct the result if the LMS has changed it since it was imported. The import will then leave this result alone.', 'chess-army-knife' ); ?></p>
		<p>
			<label for="cak-result-home"><?php echo esc_html( $result['home'] ); ?></label>
			<input type="text" id="cak-result-home" name="cak_result_home_score" value="<?php echo esc_attr( $result['home_score'] ); ?>" size="4" />
			–
			<input type="text" id="cak-result-away" name="cak_result_away_score" value="<?php echo esc_attr( $result['away_score'] ); ?>" size="4" />
			<label for="cak-result-away"><?php echo esc_html( $result['away'] ); ?></label>
		</p>
		<?php if ( $result['games'] ) : ?>
			<table class="widefat striped">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Board', 'chess-army-knife' ); ?></th>
					<th scope="col"><?php echo esc_html( $result['home'] ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'chess-army-knife' ); ?></th>
					<th scope="col"><?php echo esc_html( $result['away'] ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $result['games'] as $index => $game ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( (string) $game['board'] ); ?></th>
						<td><?php echo esc_html( $name_of( $game['home'] ) ); ?></td>
						<td>
							<label class="screen-reader-text" for="cak-board-<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html( sprintf( /* translators: %d: board number */ __( 'Result on board %d', 'chess-army-knife' ), $game['board'] ) ); ?></label>
							<select id="cak-board-<?php echo esc_attr( (string) $index ); ?>" name="cak_result_boards[<?php echo esc_attr( (string) $index ); ?>]">
								<?php foreach ( $outcomes as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $game['result'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><?php echo esc_html( $name_of( $game['away'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the corrected result.
	 *
	 * @param int $post_id Event id.
	 */
	public static function save_result( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['cak_league_result_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cak_league_result_nonce'] ) ), 'cak_league_result_' . $post_id ) ) {
			return;
		}
		$result = Chess_Army_Knife_Event_Results::get( $post_id );
		if ( ! $result || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$boards = isset( $_POST['cak_result_boards'] ) && is_array( $_POST['cak_result_boards'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['cak_result_boards'] ) ) : array();
		$edited = Chess_Army_Knife_Event_Results::apply_edit(
			$result,
			array(
				'home_score' => isset( $_POST['cak_result_home_score'] ) ? sanitize_text_field( wp_unslash( $_POST['cak_result_home_score'] ) ) : '',
				'away_score' => isset( $_POST['cak_result_away_score'] ) ? sanitize_text_field( wp_unslash( $_POST['cak_result_away_score'] ) ) : '',
				'boards'     => $boards,
			)
		);

		if ( $edited !== $result ) {
			Chess_Army_Knife_Event_Results::store( $post_id, $edited );
			update_post_meta( $post_id, Chess_Army_Knife_Event_Results::META_EDITED, 1 );
		}
	}

	/**
	 * Leave the league games out of the Club events list.
	 *
	 * @param WP_Query $query The query.
	 */
	public static function hide_from_club_events( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || Chess_Army_Knife_Events::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( 'trash' === $query->get( 'post_status' ) ) {
			return; // The Trash holds league games too, so they can be restored or deleted for good.
		}

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => Chess_Army_Knife_Events_Import::META_LMS_KEY,
				'compare' => 'NOT EXISTS',
			),
		);
		$existing   = $query->get( 'meta_query' );
		if ( is_array( $existing ) && $existing ) {
			$meta_query[] = $existing;
		}
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Every league game that has been played, newest first.
	 *
	 * @return array[] Each { id, date ("Y-m-d"), start, title, season (the LMS's name for it, or '' if none was kept), result (see Event_Results::get()), sides (the sides the club's teams are on) }.
	 */
	public static function all() {
		$posts = get_posts(
			array(
				'post_type'      => Chess_Army_Knife_Events::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => Chess_Army_Knife_Events_Import::META_LMS_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Finds the imported league games.
			)
		);

		$games = array();
		foreach ( $posts as $post ) {
			$result = Chess_Army_Knife_Event_Results::get( $post->ID );
			if ( ! $result ) {
				continue; // Nothing to show until it has been played.
			}
			$start   = (string) get_post_meta( $post->ID, Chess_Army_Knife_Events::META_START, true );
			$games[] = array(
				'id'     => (int) $post->ID,
				'date'   => substr( $start, 0, 10 ),
				'start'  => $start,
				'title'  => get_the_title( $post ),
				'result' => $result,
				'season' => (string) get_post_meta( $post->ID, Chess_Army_Knife_Events_Import::META_SEASON, true ),
				'sides'  => array_values( array_unique( array_filter( (array) get_post_meta( $post->ID, Chess_Army_Knife_Events::META_SIDES, true ) ) ) ),
			);
		}

		usort(
			$games,
			function ( $a, $b ) {
				return strcmp( $b['start'], $a['start'] );
			}
		);

		return $games;
	}

	/**
	 * The seasons the games fall in, the one with the latest game first. Games imported before the season was
	 * kept have none, and come last under the key ''.
	 *
	 * @param array[] $games From all().
	 * @return int[] Season name => number of games.
	 */
	public static function seasons( array $games ) {
		$counts = array();
		$latest = array();
		foreach ( $games as $game ) {
			$season            = (string) $game['season'];
			$counts[ $season ] = isset( $counts[ $season ] ) ? $counts[ $season ] + 1 : 1;
			$latest[ $season ] = isset( $latest[ $season ] ) ? max( $latest[ $season ], $game['start'] ) : $game['start'];
		}

		uksort(
			$counts,
			function ( $a, $b ) use ( $latest ) {
				if ( '' === (string) $a || '' === (string) $b ) {
					return '' === (string) $a ? 1 : -1; // No season goes last.
				}

				return strcmp( $latest[ $b ], $latest[ $a ] );
			}
		);

		return $counts;
	}

	/**
	 * The games of one season.
	 *
	 * @param array[] $games  From all().
	 * @param string  $season A season name, '' for games with no season kept, or 'all'.
	 * @return array[]
	 */
	public static function filter_season( array $games, $season ) {
		if ( 'all' === $season ) {
			return $games;
		}

		return array_values(
			array_filter(
				$games,
				function ( $game ) use ( $season ) {
					return (string) $game['season'] === (string) $season;
				}
			)
		);
	}

	/**
	 * Every team the club has played, for the "Played against" list.
	 *
	 * @param array[] $games From all().
	 * @return string[] Team names, alphabetical.
	 */
	public static function opponents( array $games ) {
		$names = array();
		foreach ( $games as $game ) {
			$name = self::opponent_of( $game );
			if ( '' !== $name ) {
				$names[ strtolower( $name ) ] = $name;
			}
		}
		natcasesort( $names );

		return array_values( $names );
	}

	/**
	 * The club's players who appear in the results, for the "Player" list. Each is matched to a member by ECF code,
	 * and shown by the member's name when there is one.
	 *
	 * @param array[] $games   From all().
	 * @param array[] $members Member rows with an ECF code (see Membership_Store::get_players()).
	 * @return string[] ECF code (digits) => name, alphabetical.
	 */
	public static function players( array $games, array $members ) {
		$names = array();
		foreach ( Chess_Army_Knife_Event_Results::club_players( self::result_rows( $games ) ) as $key => $player ) {
			$names[ $key ] = $player['name'];
			foreach ( $members as $member ) {
				if ( Chess_Army_Knife_Event_Results::same_code( $member['ecf_code'], $player['code'] ) ) {
					$names[ $key ] = (string) $member['name'];
					break;
				}
			}
		}
		natcasesort( $names );

		return $names;
	}

	/**
	 * A match's score with the home team on the left, such as "3 - 1".
	 *
	 * @param array $game A game from all().
	 * @return string '' until it has been played.
	 */
	public static function score_text( array $game ) {
		return $game['result'] ? sprintf( '%s - %s', $game['result']['home_score'], $game['result']['away_score'] ) : '';
	}

	/**
	 * The side the club is on, or '' when it is not known. When two of its teams met, the team on the left (home) is
	 * the club's side and the other is the opponent.
	 *
	 * @param array $game A game from all().
	 * @return string 'home', 'away' or ''.
	 */
	protected static function club_side( array $game ) {
		if ( in_array( 'home', $game['sides'], true ) ) {
			return 'home';
		}

		return in_array( 'away', $game['sides'], true ) ? 'away' : '';
	}

	/**
	 * How a match ended for the club.
	 *
	 * @param array $game A game from all().
	 * @return string 'win', 'draw', 'loss', or '' if it is not played or the club's side is not known.
	 */
	public static function club_outcome( array $game ) {
		$side = self::club_side( $game );
		if ( ! $game['result'] || '' === $side ) {
			return '';
		}
		if ( 'draw' === $game['result']['winner'] ) {
			return 'draw';
		}

		return $side === $game['result']['winner'] ? 'win' : 'loss';
	}

	/**
	 * The team the club played in a match.
	 *
	 * @param array $game A game from all().
	 * @return string '' when it is not played or the club's side is not known.
	 */
	public static function opponent_of( array $game ) {
		$side = self::club_side( $game );
		if ( ! $game['result'] || '' === $side ) {
			return '';
		}

		return (string) $game['result'][ 'home' === $side ? 'away' : 'home' ];
	}

	/**
	 * The games against a team, and/or that ended a given way for the club.
	 *
	 * @param array[] $games    From all().
	 * @param string  $opponent The other team's name, or ''.
	 * @param string  $outcome  'win', 'draw', 'loss', or '' for any.
	 * @return array[]
	 */
	public static function filter_games( array $games, $opponent, $outcome ) {
		$opponent = trim( (string) $opponent );

		return array_values(
			array_filter(
				$games,
				function ( $game ) use ( $opponent, $outcome ) {
					if ( '' !== $outcome && self::club_outcome( $game ) !== $outcome ) {
						return false;
					}

					return '' === $opponent || 0 === strcasecmp( self::opponent_of( $game ), $opponent );
				}
			)
		);
	}

	/**
	 * Every board a player played, newest first.
	 *
	 * @param array[] $games From all().
	 * @param string  $code  The player's ECF code.
	 * @param string  $colour 'white' or 'black' to leave out the boards played with the other colour, or '' for both.
	 * @return array[] Each { id, date, title, score, board, colour ('White', 'Black' or ''), opponent, outcome ('win', 'draw', 'loss' or '') }.
	 */
	public static function boards_of( array $games, $code, $colour = '' ) {
		$boards = array();

		foreach ( $games as $game ) {
			if ( ! $game['result'] ) {
				continue;
			}
			foreach ( $game['result']['games'] as $board ) {
				foreach ( array( 'home', 'away' ) as $side ) {
					$player = $board[ $side ];
					if ( ! $player || empty( $player['code'] ) || ! Chess_Army_Knife_Event_Results::same_code( $player['code'], $code ) ) {
						continue;
					}
					$white = self::is_white( $side, (string) $board['home_colour'] );
					if ( ( 'white' === $colour && true !== $white ) || ( 'black' === $colour && false !== $white ) ) {
						continue;
					}
					$other    = 'home' === $side ? 'away' : 'home';
					$boards[] = array(
						'id'       => $game['id'],
						'date'     => $game['date'],
						'title'    => $game['title'],
						'score'    => self::score_text( $game ),
						'board'    => (int) $board['board'],
						'colour'   => self::colour_of( $side, (string) $board['home_colour'] ),
						'opponent' => $board[ $other ] ? (string) $board[ $other ]['name'] : '',
						'outcome'  => self::outcome_of( $side, (string) $board['result'] ),
					);
				}
			}
		}

		return $boards;
	}

	/**
	 * Whether a side had White on a board.
	 *
	 * @param string $side        'home' or 'away'.
	 * @param string $home_colour The home player's colour, 'W' or 'B'.
	 * @return bool|null Null when the colour is not known.
	 */
	public static function is_white( $side, $home_colour ) {
		$home_colour = strtoupper( $home_colour );
		if ( ! in_array( $home_colour, array( 'W', 'B' ), true ) ) {
			return null;
		}

		return 'home' === $side ? 'W' === $home_colour : 'B' === $home_colour;
	}

	/**
	 * The colour a side had on a board.
	 *
	 * @param string $side        'home' or 'away'.
	 * @param string $home_colour The home player's colour, 'W' or 'B'.
	 * @return string 'White', 'Black' or '' when it is not known.
	 */
	public static function colour_of( $side, $home_colour ) {
		$white = self::is_white( $side, $home_colour );
		if ( null === $white ) {
			return '';
		}

		return $white ? __( 'White', 'chess-army-knife' ) : __( 'Black', 'chess-army-knife' );
	}

	/**
	 * How a board ended for a side.
	 *
	 * @param string $side   'home' or 'away'.
	 * @param string $result 'home_win', 'away_win', 'draw' or ''.
	 * @return string 'win', 'draw', 'loss' or '' when there is no result.
	 */
	public static function outcome_of( $side, $result ) {
		if ( 'draw' === $result ) {
			return 'draw';
		}
		if ( ! in_array( $result, array( 'home_win', 'away_win' ), true ) ) {
			return '';
		}

		return $side . '_win' === $result ? 'win' : 'loss';
	}

	/**
	 * Count a player's wins, draws and losses.
	 *
	 * @param array[] $boards From boards_of().
	 * @return int[] { win, draw, loss }.
	 */
	public static function tally( array $boards ) {
		$tally = array(
			'win'  => 0,
			'draw' => 0,
			'loss' => 0,
		);
		foreach ( $boards as $board ) {
			if ( isset( $tally[ $board['outcome'] ] ) ) {
				++$tally[ $board['outcome'] ];
			}
		}

		return $tally;
	}

	/**
	 * The words for a board's outcome.
	 *
	 * @param string $outcome From boards_of().
	 * @return string
	 */
	protected static function outcome_label( $outcome ) {
		$labels = array(
			'win'  => __( 'Won', 'chess-army-knife' ),
			'draw' => __( 'Drew', 'chess-army-knife' ),
			'loss' => __( 'Lost', 'chess-army-knife' ),
		);

		return isset( $labels[ $outcome ] ) ? $labels[ $outcome ] : '–';
	}

	/**
	 * Draw the screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Teams::user_can_manage(), __( 'League Games', 'chess-army-knife' ), 'teams' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display; nothing is changed.
		$filters = array(
			'player'   => isset( $_GET['player'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_GET['player'] ) ) ) : '', // The player's ECF code.
			'colour'   => isset( $_GET['colour'] ) ? sanitize_key( wp_unslash( $_GET['colour'] ) ) : '',
			'opponent' => isset( $_GET['opponent'] ) ? sanitize_text_field( wp_unslash( $_GET['opponent'] ) ) : '',
			'outcome'  => isset( $_GET['outcome'] ) ? sanitize_key( wp_unslash( $_GET['outcome'] ) ) : '',
		);
		$season  = isset( $_GET['season'] ) ? sanitize_text_field( wp_unslash( $_GET['season'] ) ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $filters['outcome'], array( 'win', 'draw', 'loss' ), true ) ) {
			$filters['outcome'] = '';
		}
		if ( ! in_array( $filters['colour'], array( 'white', 'black' ), true ) ) {
			$filters['colour'] = '';
		}

		$all     = self::all();
		$seasons = self::seasons( $all );

		// A season not asked for (or not known) is the latest, unless a player is being looked for, who may have played in any.
		if ( 'none' === $season ) {
			$season = '';
		}
		if ( 'all' !== $season && ! isset( $seasons[ $season ] ) ) {
			$season = '' === $filters['player'] && $seasons ? (string) key( $seasons ) : 'all';
		}
		$filters['season'] = '' === $season ? 'none' : $season; // As it goes in a link.

		$in_season = self::filter_season( $all, $season );
		$games     = self::filter_games( $in_season, $filters['opponent'], $filters['outcome'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Club events', 'chess-army-knife' ); ?></h1>
			<?php Chess_Army_Knife_Section_Tabs::render( 'events', 'league' ); ?>
			<?php Chess_Army_Knife_Events_Import::render_notices(); ?>
			<h2><?php esc_html_e( 'League games', 'chess-army-knife' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Fixtures brought in from the LMS, with the board order and results of those that have been played. They show in the calendar with the club\'s own events.', 'chess-army-knife' ); ?></p>

			<?php self::render_season_links( $all, $seasons, $filters ); ?>
			<?php self::render_search_form( $filters, $all ); ?>

			<?php if ( '' !== $filters['player'] ) : ?>
				<?php self::render_player_games( $all, $games, $filters['player'], $filters['colour'] ); ?>
			<?php else : ?>
				<?php self::render_games( $games, $paged, $filters ); ?>
			<?php endif; ?>

			<?php Chess_Army_Knife_Events_Import::render_section(); ?>
		</div>
		<?php
	}

	/**
	 * Whether a player, colour, opponent or result is being searched for.
	 *
	 * @param string[] $filters As searched.
	 * @return bool
	 */
	protected static function is_search( array $filters ) {
		return '' !== $filters['player'] || '' !== $filters['colour'] || '' !== $filters['opponent'] || '' !== $filters['outcome'];
	}

	/**
	 * Draw the links that pick a season, or all of them.
	 *
	 * @param array[]  $all      Every game, from all().
	 * @param int[]    $seasons  From seasons().
	 * @param string[] $filters  As searched; the season is the one showing.
	 */
	protected static function render_season_links( array $all, array $seasons, array $filters ) {
		if ( ! $seasons ) {
			return;
		}

		$links = array( 'all' => array( __( 'All seasons', 'chess-army-knife' ), count( $all ) ) );
		foreach ( $seasons as $season => $count ) {
			$links[ '' === (string) $season ? 'none' : $season ] = array( '' === (string) $season ? __( 'No season recorded', 'chess-army-knife' ) : $season, $count );
		}
		?>
		<nav aria-label="<?php esc_attr_e( 'Seasons', 'chess-army-knife' ); ?>">
			<ul class="subsubsub">
				<?php foreach ( $links as $season => $link ) : ?>
					<?php $url = add_query_arg( array_merge( array( 'page' => self::PAGE ), array_filter( $filters ), array( 'season' => $season ) ), admin_url( 'admin.php' ) ); ?>
					<li>
						<a href="<?php echo esc_url( $url ); ?>"<?php echo (string) $season === (string) $filters['season'] ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $link[0] ); ?> <span class="count">(<?php echo esc_html( (string) $link[1] ); ?>)</span></a><?php echo array_key_last( $links ) === $season ? '' : ' |'; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<br class="clear" />
		<?php
	}

	/**
	 * Draw the search box: a member, the colour they had, a team played and how the match ended.
	 *
	 * @param string[] $filters player, colour, opponent and outcome as searched, and the season showing.
	 * @param array[]  $all     Every game, from all(), which the lists are made from.
	 */
	protected static function render_search_form( array $filters, array $all ) {
		$outcomes = array(
			''     => __( 'Any result', 'chess-army-knife' ),
			'win'  => __( 'Won', 'chess-army-knife' ),
			'draw' => __( 'Drawn', 'chess-army-knife' ),
			'loss' => __( 'Lost', 'chess-army-knife' ),
		);
		$colours  = array(
			''      => __( 'Either colour', 'chess-army-knife' ),
			'white' => __( 'White', 'chess-army-knife' ),
			'black' => __( 'Black', 'chess-army-knife' ),
		);
		$players  = self::players( $all, Chess_Army_Knife_Membership_Store::get_players( '', true ) );
		?>
		<div class="card" style="max-width: none;">
			<h3 style="margin-top: 0;"><?php esc_html_e( 'Search league games', 'chess-army-knife' ); ?></h3>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<input type="hidden" name="season" value="<?php echo esc_attr( $filters['season'] ); ?>" />
				<p>
					<label for="cak-league-player"><?php esc_html_e( 'Player', 'chess-army-knife' ); ?></label>
					<select id="cak-league-player" name="player">
						<option value=""><?php esc_html_e( 'Any player', 'chess-army-knife' ); ?></option>
						<?php foreach ( $players as $code => $name ) : ?>
							<option value="<?php echo esc_attr( (string) $code ); ?>"<?php selected( $filters['player'], (string) $code ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<label for="cak-league-colour"><?php esc_html_e( 'Played as', 'chess-army-knife' ); ?></label>
					<select id="cak-league-colour" name="colour">
						<?php foreach ( $colours as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $filters['colour'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="cak-league-opponent"><?php esc_html_e( 'Played against', 'chess-army-knife' ); ?></label>
					<select id="cak-league-opponent" name="opponent">
						<option value=""><?php esc_html_e( 'Any team', 'chess-army-knife' ); ?></option>
						<?php foreach ( self::opponents( $all ) as $team ) : ?>
							<option value="<?php echo esc_attr( $team ); ?>"<?php selected( $filters['opponent'], $team ); ?>><?php echo esc_html( $team ); ?></option>
						<?php endforeach; ?>
					</select>
					<label for="cak-league-outcome"><?php esc_html_e( 'Match result', 'chess-army-knife' ); ?></label>
					<select id="cak-league-outcome" name="outcome">
						<?php foreach ( $outcomes as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $filters['outcome'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Search', 'chess-army-knife' ); ?></button>
					<?php if ( self::is_search( $filters ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Clear search', 'chess-army-knife' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Draw one player's boards, within the games the search left in.
	 *
	 * @param array[] $all   Every game, from all().
	 * @param array[] $games The games left after the opponent and result were chosen.
	 * @param string  $code   ECF code of the player.
	 * @param string  $colour 'white', 'black' or '' for the boards to show.
	 */
	protected static function render_player_games( array $all, array $games, $code, $colour ) {
		$name = '';
		foreach ( Chess_Army_Knife_Event_Results::club_players( self::result_rows( $all ) ) as $player ) {
			if ( Chess_Army_Knife_Event_Results::same_code( $player['code'], $code ) ) {
				$name = $player['name'];
			}
		}
		$record = Chess_Army_Knife_Membership_Store::find_by_ecf_code( $code );
		if ( $record ) {
			$name = $record['name'];
		}

		$boards = self::boards_of( $games, $code, $colour );
		?>
		<h3><?php echo esc_html( $name ); ?></h3>
		<?php
		if ( ! $boards ) {
			echo '<p>' . esc_html__( 'No games found for that player.', 'chess-army-knife' ) . '</p>';
			return;
		}

		$tally       = self::tally( $boards );
		$date_format = get_option( 'date_format' );
		?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: boards played, 2: won, 3: drawn, 4: lost */
					_n( '%1$d game: %2$d won, %3$d drawn, %4$d lost.', '%1$d games: %2$d won, %3$d drawn, %4$d lost.', count( $boards ), 'chess-army-knife' ),
					count( $boards ),
					$tally['win'],
					$tally['draw'],
					$tally['loss']
				)
			);
			?>
		</p>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Date', 'chess-army-knife' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Match', 'chess-army-knife' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Score', 'chess-army-knife' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Board', 'chess-army-knife' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Colour', 'chess-army-knife' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Opponent', 'chess-army-knife' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'chess-army-knife' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $boards as $board ) : ?>
				<tr>
					<td><?php echo esc_html( self::date_text( $board['date'], $date_format ) ); ?></td>
					<td><?php echo esc_html( $board['title'] ); ?></td>
					<td><?php echo esc_html( $board['score'] ); ?></td>
					<td><?php echo esc_html( (string) $board['board'] ); ?></td>
					<td><?php echo esc_html( '' !== $board['colour'] ? $board['colour'] : '–' ); ?></td>
					<td><?php echo esc_html( '' !== $board['opponent'] ? $board['opponent'] : __( 'Default', 'chess-army-knife' ) ); ?></td>
					<td><?php echo esc_html( self::outcome_label( $board['outcome'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The played games in the form Event_Results::club_players() reads.
	 *
	 * @param array[] $games From all().
	 * @return array[] Each { date, result }.
	 */
	public static function result_rows( array $games ) {
		$rows = array();
		foreach ( $games as $game ) {
			if ( $game['result'] ) {
				$rows[] = array(
					'date'   => $game['date'],
					'result' => $game['result'],
				);
			}
		}

		return $rows;
	}

	/**
	 * A "Y-m-d" date as the site writes dates.
	 *
	 * @param string $date        "Y-m-d", or ''.
	 * @param string $date_format A PHP date format.
	 * @return string
	 */
	protected static function date_text( $date, $date_format ) {
		$timestamp = Chess_Army_Knife_Events::to_timestamp( $date . ' 12:00:00' );

		return $timestamp ? wp_date( $date_format, $timestamp ) : __( 'No date set', 'chess-army-knife' );
	}

	/**
	 * Draw the list of games, a page at a time, with the boards of the ones played.
	 *
	 * @param array[] $games From all().
	 * @param int     $paged   Page number.
	 * @param array   $filters Search in force, kept in the page links.
	 */
	protected static function render_games( array $games, $paged, array $filters ) {
		if ( ! $games ) {
			echo '<p>' . esc_html( self::is_search( $filters ) ? __( 'No league games match that search.', 'chess-army-knife' ) : __( 'No league games have been played yet. Results arrive when the import below is run (it also runs by itself once a day).', 'chess-army-knife' ) ) . '</p>';
			return;
		}

		$pages       = (int) ceil( count( $games ) / self::PER_PAGE );
		$paged       = min( $paged, $pages );
		$date_format = get_option( 'date_format' );
		?>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Date', 'chess-army-knife' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Match', 'chess-army-knife' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'chess-army-knife' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Boards', 'chess-army-knife' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Edit', 'chess-army-knife' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( array_slice( $games, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $game ) : ?>
				<tr>
					<td><?php echo esc_html( self::date_text( $game['date'], $date_format ) ); ?></td>
					<td><?php echo esc_html( $game['title'] ); ?></td>
					<td><?php echo esc_html( self::score_text( $game ) ); ?></td>
					<td>
						<details>
							<summary><?php esc_html_e( 'Show boards', 'chess-army-knife' ); ?></summary>
							<?php echo Chess_Army_Knife_Event_Results::html( $game['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in html(). ?>
						</details>
					</td>
					<td>
						<?php if ( current_user_can( 'edit_post', $game['id'] ) ) : ?>
							<a href="<?php echo esc_url( (string) get_edit_post_link( $game['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $game['title'] ); ?></span></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$links = paginate_links(
			array(
				'base'      => add_query_arg( array_merge( array( 'page' => self::PAGE ), array_filter( $filters ), array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => __( 'Previous', 'chess-army-knife' ),
				'next_text' => __( 'Next', 'chess-army-knife' ),
			)
		);
		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}
}

Chess_Army_Knife_League_Games::init();
