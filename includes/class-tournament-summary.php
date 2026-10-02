<?php
/**
 * Read-only summaries of tournaments for the public blocks: status, games
 * still to play, and past winners.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Tournament_Summary {

	/**
	 * Headline facts about one tournament.
	 *
	 * @param array $tournament Tournament row.
	 * @return array { status, status_label, format_label, players, round, rounds, games_played, games_total, champion }
	 */
	public static function status( array $tournament ) {
		$formats = Chess_Army_Knife_Tournaments::formats();
		$config  = Chess_Army_Knife_Tournaments::config( $tournament );

		$games_played = 0;
		$games_total  = 0;
		foreach ( Chess_Army_Knife_Tournament_Store::get_games( $tournament['id'] ) as $game ) {
			// Byes and bracket games still waiting for their players are not real games yet.
			if ( $game['is_bye'] || null === $game['white_entry_id'] || null === $game['black_entry_id'] ) {
				continue;
			}
			++$games_total;
			if ( null !== $game['result'] ) {
				++$games_played;
			}
		}

		$champion = Chess_Army_Knife_Tournaments::champion( $tournament['id'] );

		return array(
			'status'       => $tournament['status'],
			'status_label' => Chess_Army_Knife_Tournaments_Page::status_label( $tournament['status'] ),
			'format_label' => isset( $formats[ $tournament['format'] ] ) ? $formats[ $tournament['format'] ] : $tournament['format'],
			'players'      => count( Chess_Army_Knife_Tournament_Store::get_entries( $tournament['id'] ) ),
			'round'        => Chess_Army_Knife_Tournaments::current_round( $tournament['id'] ),
			'rounds'       => $config['rounds'],
			'games_played' => $games_played,
			'games_total'  => $games_total,
			'champion'     => $champion ? $champion['name'] : null,
		);
	}

	/**
	 * The players in a tournament with their ratings, for the public players list.
	 *
	 * Once started the rating is the one recorded at the start. Before that a
	 * player's rating is not known (it is fetched when the tournament starts),
	 * so only a manual rating is shown and no ECF request is made for the page.
	 *
	 * @param array $tournament Tournament row.
	 * @return array[] Each: seed, name, ecf_code, rating, source ('ecf'|'manual'|'none'|'pending'), withdrawn.
	 */
	public static function players( array $tournament ) {
		$started = Chess_Army_Knife_Tournaments::STATUS_DRAFT !== $tournament['status'];
		$rows    = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $tournament['id'] ) as $entry ) {
			$rating = $entry['start_rating'];
			$source = $entry['rating_source'];

			if ( ! $started ) {
				$person = Chess_Army_Knife_Membership_Store::get_member( $entry['player_id'] );
				$manual = $person ? $person['manual_rating'] : null;
				if ( '' !== $entry['ecf_code'] ) {
					$rating = null;
					$source = 'pending';
				} elseif ( null !== $manual ) {
					$rating = $manual;
					$source = 'manual';
				} else {
					$source = 'none';
				}
			}

			$rows[] = array(
				'seed'      => $entry['seed'],
				'name'      => $entry['name'],
				'ecf_code'  => $entry['ecf_code'],
				'rating'    => $rating,
				'source'    => $source,
				'withdrawn' => 'withdrawn' === $entry['status'],
			);
		}
		return $rows;
	}

	/**
	 * Games still to be played, grouped under a round label.
	 *
	 * @param int $tournament_id Tournament id.
	 * @return array[] Label => list of { id, white, black } (game id and player names).
	 */
	public static function games_to_play( $tournament_id ) {
		$names = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id ) as $entry ) {
			$names[ $entry['id'] ] = $entry['name'];
		}

		$knockout_max = 0;
		foreach ( Chess_Army_Knife_Tournament_Store::get_games( $tournament_id ) as $game ) {
			if ( 'knockout' === $game['stage'] ) {
				$knockout_max = max( $knockout_max, $game['round'] );
			}
		}

		$sections = array();
		foreach ( Chess_Army_Knife_Tournaments::games_to_play( $tournament_id ) as $game ) {
			if ( 'knockout' === $game['stage'] ) {
				$label = Chess_Army_Knife_Bracket::round_name( $game['round'], $knockout_max );
			} else {
				$label = ( $game['group_no'] ? Chess_Army_Knife_Tournaments::group_label( $game['group_no'] ) . ' — ' : '' )
					/* translators: %d: round number */
					. sprintf( __( 'Round %d', 'chess-army-knife' ), $game['round'] );
			}
			$sections[ $label ][] = array(
				'id'    => $game['id'],
				'white' => isset( $names[ $game['white_entry_id'] ] ) ? $names[ $game['white_entry_id'] ] : '—',
				'black' => isset( $names[ $game['black_entry_id'] ] ) ? $names[ $game['black_entry_id'] ] : '—',
			);
		}
		return $sections;
	}

	/**
	 * One page of the games to play: whole rounds, so a round is never split across pages.
	 *
	 * @param array[] $sections    From games_to_play(): round label => games.
	 * @param int     $page        Page wanted, from 1; one that is out of range is moved to the nearest page.
	 * @param int     $per_page    Rounds on a page; 0 or less for all of them on one page.
	 * @return array { sections, page, pages, rounds, labels } where rounds is how many there are in all and labels are those shown.
	 */
	public static function paginate( array $sections, $page, $per_page ) {
		$rounds   = count( $sections );
		$per_page = (int) $per_page;
		$pages    = $per_page > 0 ? max( 1, (int) ceil( $rounds / $per_page ) ) : 1;
		$page     = max( 1, min( $pages, (int) $page ) );
		$shown    = $per_page > 0 ? array_slice( $sections, ( $page - 1 ) * $per_page, $per_page, true ) : $sections;

		return array(
			'sections' => $shown,
			'page'     => $page,
			'pages'    => $pages,
			'rounds'   => $rounds,
			'labels'   => array_keys( $shown ),
		);
	}

	/**
	 * A cross-table of the main stage: one row per player in rank order, with
	 * the points scored in each round and the total.
	 *
	 * @param array    $tournament Tournament row.
	 * @param int|null $group_no   One group (0 for an ungrouped tournament); null for everyone.
	 * @return array { rounds: int[], rows: array[] } Each row: rank, name, withdrawn, total, scores (round => points, or null when not played yet).
	 */
	public static function crosstable( array $tournament, $group_no = null ) {
		$swiss  = 'swiss' === $tournament['format'];
		$scores = array();
		$rounds = array();

		foreach ( Chess_Army_Knife_Tournament_Store::get_games( $tournament['id'] ) as $game ) {
			if ( 'main' !== $game['stage'] || ( null !== $group_no && $game['group_no'] !== (int) $group_no ) ) {
				continue;
			}
			$rounds[ $game['round'] ] = $game['round'];

			if ( $game['is_bye'] ) {
				// A Swiss bye scores a point, or less if the player asked for it; a round-robin bye scores nothing.
				if ( $swiss && $game['white_entry_id'] ) {
					$byes = array(
						Chess_Army_Knife_Standings::HALF_POINT_BYE => 0.5,
						Chess_Army_Knife_Standings::ZERO_POINT_BYE => 0.0,
					);
					$scores[ $game['white_entry_id'] ][ $game['round'] ] = isset( $byes[ $game['result'] ] ) ? $byes[ $game['result'] ] : 1.0;
				}
				continue;
			}

			$points = null === $game['result'] ? null : Chess_Army_Knife_Standings::points_for( $game['result'] );
			if ( null !== $points ) {
				$scores[ $game['white_entry_id'] ][ $game['round'] ] = $points[0];
				$scores[ $game['black_entry_id'] ][ $game['round'] ] = $points[1];
			}
		}
		sort( $rounds );

		$rows = array();
		foreach ( Chess_Army_Knife_Tournaments::standings( $tournament['id'], $group_no ) as $standing ) {
			$cells = array();
			foreach ( $rounds as $round ) {
				$cells[ $round ] = isset( $scores[ $standing['entry_id'] ][ $round ] ) ? $scores[ $standing['entry_id'] ][ $round ] : null;
			}
			$rows[] = array(
				'rank'      => $standing['rank'],
				'name'      => $standing['name'],
				'withdrawn' => $standing['withdrawn'],
				'total'     => $standing['points'],
				'scores'    => $cells,
			);
		}

		return array(
			'rounds' => $rounds,
			'rows'   => $rows,
		);
	}

	/**
	 * Winners of completed tournaments, most recently finished first.
	 *
	 * @param int $limit Maximum number of tournaments (0 for all).
	 * @return array[] Each: { tournament, winner, completed_at }.
	 */
	public static function winners( $limit = 0 ) {
		$rows = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_tournaments() as $tournament ) {
			if ( Chess_Army_Knife_Tournaments::STATUS_COMPLETE !== $tournament['status'] ) {
				continue;
			}
			$champion = Chess_Army_Knife_Tournaments::champion( $tournament['id'] );
			if ( ! $champion ) {
				continue;
			}
			$rows[] = array(
				'tournament'   => $tournament['name'],
				'winner'       => $champion['name'],
				'completed_at' => (string) $tournament['completed_at'],
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( $b['completed_at'], $a['completed_at'] );
			}
		);

		return $limit > 0 ? array_slice( $rows, 0, $limit ) : $rows;
	}
}
