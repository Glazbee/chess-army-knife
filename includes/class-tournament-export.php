<?php
/**
 * Export of one tournament to CSV: a row for each player with their starting rating, seed and status, then
 * for each round the colour, the opponent and the result, and the total points. Only games with a result are
 * written. Names are written as they are stored ("Surname, Firstname"), which sorts well in a spreadsheet.
 * ECF codes are personal data, so the file is only given to someone who may manage tournaments, with a nonce,
 * and holds nothing else about a person (no notes, contact details or date of birth).
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Tournament_Export {

	/**
	 * The rounds that have games, in order: the main stage, then the knockout.
	 *
	 * @param array[] $games Game rows.
	 * @return array[] Each { stage, round, label }.
	 */
	public static function rounds( array $games ) {
		$main     = array();
		$knockout = array();
		foreach ( $games as $game ) {
			if ( 'knockout' === $game['stage'] ) {
				$knockout[ $game['round'] ] = true;
			} else {
				$main[ $game['round'] ] = true;
			}
		}
		ksort( $main );
		ksort( $knockout );

		$rounds = array();
		foreach ( array_keys( $main ) as $round ) {
			$rounds[] = array(
				'stage' => 'main',
				'round' => $round,
				/* translators: %d: round number */
				'label' => sprintf( __( 'Round %d', 'chess-army-knife' ), $round ),
			);
		}
		$total = $knockout ? max( array_keys( $knockout ) ) : 0;
		foreach ( array_keys( $knockout ) as $round ) {
			$rounds[] = array(
				'stage' => 'knockout',
				'round' => $round,
				'label' => Chess_Army_Knife_Bracket::round_name( $round, $total ),
			);
		}
		return $rounds;
	}

	/**
	 * The heading row.
	 *
	 * @param array[] $rounds Rounds from rounds().
	 * @return string[]
	 */
	public static function header( array $rounds ) {
		$header = array(
			__( 'Name', 'chess-army-knife' ),
			__( 'ECF code', 'chess-army-knife' ),
			__( 'Starting rating', 'chess-army-knife' ),
			__( 'Rating source', 'chess-army-knife' ),
			__( 'Seed', 'chess-army-knife' ),
			__( 'Status', 'chess-army-knife' ),
		);
		foreach ( $rounds as $round ) {
			/* translators: %s: round name, for example "Round 2" */
			$header[] = sprintf( __( '%s colour', 'chess-army-knife' ), $round['label'] );
			/* translators: %s: round name, for example "Round 2" */
			$header[] = sprintf( __( '%s opponent', 'chess-army-knife' ), $round['label'] );
			/* translators: %s: round name, for example "Round 2" */
			$header[] = sprintf( __( '%s result', 'chess-army-knife' ), $round['label'] );
		}
		$header[] = __( 'Total points', 'chess-army-knife' );
		return $header;
	}

	/**
	 * What one player did in a game: their colour, opponent and points.
	 *
	 * @param array   $game           Game row.
	 * @param int     $entry_id       The player's entry id.
	 * @param string[] $names         Entry id => name.
	 * @param float   $swiss_bye_pts  Points for a bye that was not asked for.
	 * @return array|null { colour, opponent, points, text } or null if the game has no result or does not involve the player.
	 */
	protected static function cell_for( array $game, $entry_id, array $names, $swiss_bye_pts ) {
		if ( null === $game['result'] || '' === (string) $game['result'] ) {
			return null;
		}

		if ( $game['is_bye'] ) {
			if ( $game['white_entry_id'] !== $entry_id ) {
				return null;
			}
			if ( Chess_Army_Knife_Standings::HALF_POINT_BYE === $game['result'] ) {
				$points = 0.5;
			} elseif ( Chess_Army_Knife_Standings::ZERO_POINT_BYE === $game['result'] ) {
				$points = 0.0;
			} else {
				$points = $swiss_bye_pts;
			}
			return array(
				'colour'   => '',
				'opponent' => __( 'Bye', 'chess-army-knife' ),
				'points'   => $points,
				'text'     => self::points_text( $points ),
			);
		}

		$scores = Chess_Army_Knife_Standings::points_for( $game['result'] );
		if ( null === $scores ) {
			return null;
		}
		if ( $game['white_entry_id'] === $entry_id ) {
			$colour = 'W';
			$other  = $game['black_entry_id'];
			$points = $scores[0];
		} elseif ( $game['black_entry_id'] === $entry_id ) {
			$colour = 'B';
			$other  = $game['white_entry_id'];
			$points = $scores[1];
		} else {
			return null;
		}

		return array(
			'colour'   => $colour,
			'opponent' => isset( $names[ $other ] ) ? $names[ $other ] : '',
			'points'   => $points,
			'text'     => self::points_text( $points ) . ( Chess_Army_Knife_Standings::is_forfeit( $game['result'] ) ? ' ' . __( '(forfeit)', 'chess-army-knife' ) : '' ),
		);
	}

	/**
	 * Points as a plain number for a spreadsheet: 0, 0.5 or 1.
	 *
	 * @param float $points Points.
	 * @return string
	 */
	protected static function points_text( $points ) {
		return rtrim( rtrim( number_format( (float) $points, 1, '.', '' ), '0' ), '.' );
	}

	/**
	 * Every row of the file, as text cells, heading first.
	 *
	 * @param array   $tournament Tournament row.
	 * @param array[] $entries    Entrants.
	 * @param array[] $games      Games.
	 * @return array[]
	 */
	public static function rows( array $tournament, array $entries, array $games ) {
		$rounds = self::rows_rounds( $games );
		$names  = array();
		foreach ( $entries as $entry ) {
			$names[ $entry['id'] ] = $entry['name'];
		}
		// A Swiss bye that was not asked for scores a point; in other formats it scores nothing (as in the standings).
		$bye_points = 'swiss' === $tournament['format'] ? 1.0 : 0.0;
		$sources    = array(
			'ecf'    => __( 'ECF rating list', 'chess-army-knife' ),
			'manual' => __( 'Manual', 'chess-army-knife' ),
			'none'   => __( 'No rating', 'chess-army-knife' ),
		);

		$rows = array( self::header( $rounds ) );
		foreach ( $entries as $entry ) {
			$row    = array(
				$entry['name'],
				(string) $entry['ecf_code'],
				null === $entry['start_rating'] ? '' : (string) $entry['start_rating'],
				null === $entry['start_rating'] ? '' : ( isset( $sources[ $entry['rating_source'] ] ) ? $sources[ $entry['rating_source'] ] : (string) $entry['rating_source'] ),
				null === $entry['seed'] ? '' : (string) $entry['seed'],
				'withdrawn' === $entry['status'] ? __( 'Withdrawn', 'chess-army-knife' ) : __( 'Playing', 'chess-army-knife' ),
			);
			$points = 0.0;

			foreach ( $rounds as $round ) {
				$cells = array();
				foreach ( $games as $game ) {
					if ( $game['stage'] === $round['stage'] && $game['round'] === $round['round'] ) {
						$cell = self::cell_for( $game, $entry['id'], $names, $bye_points );
						if ( $cell ) {
							$cells[] = $cell;
							$points += $cell['points'];
						}
					}
				}
				// A tie-break game in the knockout gives a second cell in the same round.
				$row[] = implode( ' / ', array_column( $cells, 'colour' ) );
				$row[] = implode( ' / ', array_column( $cells, 'opponent' ) );
				$row[] = implode( ' / ', array_column( $cells, 'text' ) );
			}
			$row[]  = self::points_text( $points );
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * The rounds to write: the ones that have a game with a result.
	 *
	 * @param array[] $games Games.
	 * @return array[]
	 */
	protected static function rows_rounds( array $games ) {
		$played = array_values(
			array_filter(
				$games,
				function ( $game ) {
					return null !== $game['result'] && '' !== (string) $game['result'];
				}
			)
		);
		return self::rounds( $played );
	}

	/**
	 * The whole file as text.
	 *
	 * @param array   $tournament Tournament row.
	 * @param array[] $entries    Entrants.
	 * @param array[] $games      Games.
	 * @return string CSV, starting with a byte order mark so Excel reads it as UTF-8.
	 */
	public static function to_csv( array $tournament, array $entries, array $games ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- An in-memory stream to build the CSV text, not a file.
		$out = fopen( 'php://temp', 'r+' );
		foreach ( self::rows( $tournament, $entries, $games ) as $row ) {
			fputcsv( $out, array_map( array( 'Chess_Army_Knife_Member_Export', 'safe_cell' ), $row ), ',', '"', '' );
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the in-memory stream above.

		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Send a tournament to the browser as a download, and stop.
	 *
	 * @param array $tournament Tournament row.
	 */
	public static function download( array $tournament ) {
		$csv = self::to_csv(
			$tournament,
			Chess_Army_Knife_Tournament_Store::get_entries( $tournament['id'] ),
			Chess_Army_Knife_Tournament_Store::get_games( $tournament['id'] )
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'tournament-' . $tournament['name'] . '-' . current_time( 'Y-m-d' ) ) . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV file, not HTML; every cell is made safe for spreadsheets above.
		exit;
	}
}
