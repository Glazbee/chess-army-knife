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
	 * Games still to be played, grouped under a round label.
	 *
	 * @param int $tournament_id Tournament id.
	 * @return array[] Label => list of { white, black } names.
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
				'white' => isset( $names[ $game['white_entry_id'] ] ) ? $names[ $game['white_entry_id'] ] : '—',
				'black' => isset( $names[ $game['black_entry_id'] ] ) ? $names[ $game['black_entry_id'] ] : '—',
			);
		}
		return $sections;
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
