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
	 * @return array[] Each { id, date ("Y-m-d"), start, title, result (see Event_Results::get()), sides (the sides the club's teams are on) }.
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
	 * The club's players whose name, or whose membership record's name, has a piece of text in it. Each is matched
	 * to a member by ECF code.
	 *
	 * @param array[]  $games   From all().
	 * @param string   $term    Part of a name.
	 * @param array[]  $members Member rows with an ECF code (see Membership_Store::get_players()).
	 * @return array[] ECF code (digits) => { code, name, rating, games, last_played, member }, by name. member is the
	 *                 name on the membership record, or '' when no record has the code.
	 */
	public static function find_players( array $games, $term, array $members = array() ) {
		$term = trim( (string) $term );
		if ( '' === $term ) {
			return array();
		}

		$found = array();
		foreach ( Chess_Army_Knife_Event_Results::club_players( self::result_rows( $games ) ) as $key => $player ) {
			$player['member'] = '';
			foreach ( $members as $member ) {
				if ( Chess_Army_Knife_Event_Results::same_code( $member['ecf_code'], $player['code'] ) ) {
					$player['member'] = (string) $member['name'];
					break;
				}
			}
			if ( false !== stripos( $player['name'], $term ) || ( '' !== $player['member'] && false !== stripos( $player['member'], $term ) ) ) {
				$found[ $key ] = $player;
			}
		}
		uasort(
			$found,
			function ( $a, $b ) {
				return strcasecmp( '' !== $a['member'] ? $a['member'] : $a['name'], '' !== $b['member'] ? $b['member'] : $b['name'] );
			}
		);

		return $found;
	}

	/**
	 * The season a date falls in. A season runs from 1 August to 31 July.
	 *
	 * @param string $date "Y-m-d".
	 * @return string The season as "2025-26", or '' if the date is not readable.
	 */
	public static function season_of( $date ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-\d{2}/', (string) $date, $parts ) ) {
			return '';
		}
		$start = (int) $parts[1] - ( (int) $parts[2] < 8 ? 1 : 0 );

		return sprintf( '%d-%02d', $start, ( $start + 1 ) % 100 );
	}

	/**
	 * The seasons the games fall in, newest first.
	 *
	 * @param array[] $games From all().
	 * @return int[] Season ("2025-26") => number of games.
	 */
	public static function seasons( array $games ) {
		$seasons = array();
		foreach ( $games as $game ) {
			$season = self::season_of( $game['date'] );
			if ( '' !== $season ) {
				$seasons[ $season ] = isset( $seasons[ $season ] ) ? $seasons[ $season ] + 1 : 1;
			}
		}
		krsort( $seasons );

		return $seasons;
	}

	/**
	 * The games of one season.
	 *
	 * @param array[] $games  From all().
	 * @param string  $season A season such as "2025-26", or 'all'.
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
					return self::season_of( $game['date'] ) === $season;
				}
			)
		);
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
	 * @param string  $opponent Part of the other team's name, or ''.
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
					if ( '' === $opponent ) {
						return true;
					}
					return false !== stripos( self::opponent_of( $game ), $opponent );
				}
			)
		);
	}

	/**
	 * Every board a player played, newest first.
	 *
	 * @param array[] $games From all().
	 * @param string  $code  The player's ECF code.
	 * @return array[] Each { id, date, title, score, board, colour ('White', 'Black' or ''), opponent, outcome ('win', 'draw', 'loss' or '') }.
	 */
	public static function boards_of( array $games, $code ) {
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
	 * The colour a side had on a board.
	 *
	 * @param string $side        'home' or 'away'.
	 * @param string $home_colour The home player's colour, 'W' or 'B'.
	 * @return string 'White', 'Black' or '' when it is not known.
	 */
	public static function colour_of( $side, $home_colour ) {
		$home_colour = strtoupper( $home_colour );
		if ( ! in_array( $home_colour, array( 'W', 'B' ), true ) ) {
			return '';
		}
		$white = 'home' === $side ? 'W' === $home_colour : 'B' === $home_colour;

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
			'player'   => isset( $_GET['player'] ) ? sanitize_text_field( wp_unslash( $_GET['player'] ) ) : '',
			'opponent' => isset( $_GET['opponent'] ) ? sanitize_text_field( wp_unslash( $_GET['opponent'] ) ) : '',
			'outcome'  => isset( $_GET['outcome'] ) ? sanitize_key( wp_unslash( $_GET['outcome'] ) ) : '',
		);
		$season  = isset( $_GET['season'] ) ? sanitize_text_field( wp_unslash( $_GET['season'] ) ) : '';
		$code    = isset( $_GET['code'] ) ? preg_replace( '/[^0-9A-Za-z]/', '', sanitize_text_field( wp_unslash( $_GET['code'] ) ) ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $filters['outcome'], array( 'win', 'draw', 'loss' ), true ) ) {
			$filters['outcome'] = '';
		}

		$all     = self::all();
		$seasons = self::seasons( $all );

		// A season not asked for (or not known) is the latest, unless a player is being looked for, who may have played in any.
		if ( 'all' !== $season && ! isset( $seasons[ $season ] ) ) {
			$season = '' === $filters['player'] && $seasons ? (string) key( $seasons ) : 'all';
		}
		$filters['season'] = $season;

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
			<?php self::render_search_form( $filters, '' !== $code ); ?>

			<?php if ( '' !== $code ) : ?>
				<?php self::render_player_games( $all, $games, $code ); ?>
			<?php elseif ( '' !== $filters['player'] ) : ?>
				<?php self::render_player_list( $in_season, $filters ); ?>
			<?php else : ?>
				<?php self::render_games( $games, $paged, $filters ); ?>
			<?php endif; ?>

			<?php Chess_Army_Knife_Events_Import::render_section(); ?>
		</div>
		<?php
	}

	/**
	 * Whether a player, opponent or result is being searched for.
	 *
	 * @param string[] $filters As searched.
	 * @return bool
	 */
	protected static function is_search( array $filters ) {
		return '' !== $filters['player'] || '' !== $filters['opponent'] || '' !== $filters['outcome'];
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
			$links[ $season ] = array( $season, $count );
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
	 * Draw the search form: a player, a team played and how the match ended.
	 *
	 * @param string[] $filters   player, opponent and outcome as searched.
	 * @param bool     $searching Whether a search is showing, so there is a way back to every game.
	 */
	protected static function render_search_form( array $filters, $searching ) {
		$outcomes = array(
			''     => __( 'Any result', 'chess-army-knife' ),
			'win'  => __( 'Won', 'chess-army-knife' ),
			'draw' => __( 'Drawn', 'chess-army-knife' ),
			'loss' => __( 'Lost', 'chess-army-knife' ),
		);
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="hidden" name="season" value="<?php echo esc_attr( $filters['season'] ); ?>" />
			<p>
				<label for="cak-league-player"><?php esc_html_e( 'Player', 'chess-army-knife' ); ?></label>
				<input type="search" id="cak-league-player" name="player" value="<?php echo esc_attr( $filters['player'] ); ?>" class="regular-text" />
				<label for="cak-league-opponent"><?php esc_html_e( 'Played against', 'chess-army-knife' ); ?></label>
				<input type="search" id="cak-league-opponent" name="opponent" value="<?php echo esc_attr( $filters['opponent'] ); ?>" class="regular-text" />
				<label for="cak-league-outcome"><?php esc_html_e( 'Match result', 'chess-army-knife' ); ?></label>
				<select id="cak-league-outcome" name="outcome">
					<?php foreach ( $outcomes as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $filters['outcome'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'chess-army-knife' ); ?></button>
				<?php if ( $searching || self::is_search( $filters ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Show all games', 'chess-army-knife' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Draw the players a name search found, each linking to their games.
	 *
	 * @param array[]  $games   Every game, from all().
	 * @param string[] $filters player, opponent and outcome as searched.
	 */
	protected static function render_player_list( array $games, array $filters ) {
		$found = self::find_players( $games, $filters['player'], Chess_Army_Knife_Membership_Store::get_players( '', true ) );
		if ( ! $found ) {
			echo '<p>' . esc_html__( 'No player with that name has played in a league game.', 'chess-army-knife' ) . '</p>';
			return;
		}
		?>
		<ul>
			<?php foreach ( $found as $player ) : ?>
				<?php $url = add_query_arg( array_merge( array( 'page' => self::PAGE ), $filters, array( 'code' => $player['code'] ) ), admin_url( 'admin.php' ) ); ?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( '' !== $player['member'] ? $player['member'] : $player['name'] ); ?></a>
					<?php
					echo esc_html(
						'' !== $player['member']
							? __( '(member)', 'chess-army-knife' )
							: __( '(no membership record)', 'chess-army-knife' )
					);
					echo ' ';
					echo esc_html(
						sprintf(
							/* translators: %d: number of games */
							_n( '%d game', '%d games', $player['games'], 'chess-army-knife' ),
							$player['games']
						)
					);
					?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Draw one player's boards, within the games the search left in.
	 *
	 * @param array[] $all   Every game, from all().
	 * @param array[] $games The games left after the opponent and result were chosen.
	 * @param string  $code  ECF code of the player.
	 */
	protected static function render_player_games( array $all, array $games, $code ) {
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

		$boards = self::boards_of( $games, $code );
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
