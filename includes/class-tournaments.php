<?php
/**
 * Tournament business logic: creating tournaments, entering players,
 * starting (rating snapshot, seeding, pairings), recording results and
 * calculating standings.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Tournaments {

	const STATUS_DRAFT    = 'draft';
	const STATUS_ACTIVE   = 'active';
	const STATUS_COMPLETE = 'complete';

	/**
	 * Supported tournament formats (slug => label).
	 *
	 * @return array
	 */
	public static function formats() {
		return array(
			'round-robin' => __( 'Round-robin', 'chess-army-knife' ),
		);
	}

	/**
	 * The capability required to manage tournaments. Filterable so a site
	 * can grant this to another role.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filter the capability required to manage tournaments.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'Chess_Army_Knife_manage_tournaments_cap', 'manage_options' );
	}

	/**
	 * Whether the current user may create tournaments and enter results.
	 *
	 * @return bool
	 */
	public static function user_can_manage() {
		return current_user_can( self::capability() );
	}

	/**
	 * Create a tournament in draft status.
	 *
	 * @param array $args name, format, rating_domain, double_round.
	 * @return int|WP_Error Tournament id.
	 */
	public static function create( array $args ) {
		$name = isset( $args['name'] ) ? trim( (string) $args['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'tournament_name', __( 'Please give the tournament a name.', 'chess-army-knife' ) );
		}

		$format = isset( $args['format'] ) ? (string) $args['format'] : 'round-robin';
		if ( ! isset( self::formats()[ $format ] ) ) {
			return new WP_Error( 'tournament_format', __( 'Unknown tournament format.', 'chess-army-knife' ) );
		}

		return Chess_Army_Knife_Tournament_Store::save_tournament(
			array(
				'name'          => $name,
				'format'        => $format,
				'status'        => self::STATUS_DRAFT,
				'rating_domain' => ECF_Client::normalise_domain( isset( $args['rating_domain'] ) ? $args['rating_domain'] : 'S' ),
				'double_round'  => empty( $args['double_round'] ) ? 0 : 1,
			)
		);
	}

	/**
	 * Enter a saved player profile in a draft tournament.
	 *
	 * @param int $tournament_id Tournament id.
	 * @param int $player_id     Player profile id.
	 * @return int|WP_Error Entry id.
	 */
	public static function add_player( $tournament_id, $player_id ) {
		$tournament = Chess_Army_Knife_Tournament_Store::get_tournament( $tournament_id );
		$player     = Chess_Army_Knife_Tournament_Store::get_player( $player_id );

		if ( ! $tournament || ! $player ) {
			return new WP_Error( 'tournament_missing', __( 'Tournament or player not found.', 'chess-army-knife' ) );
		}
		if ( self::STATUS_DRAFT !== $tournament['status'] ) {
			return new WP_Error( 'tournament_started', __( 'Players can only be added before the tournament starts.', 'chess-army-knife' ) );
		}
		foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id ) as $entry ) {
			if ( $entry['player_id'] === (int) $player_id ) {
				return new WP_Error( 'tournament_duplicate', __( 'That player is already entered.', 'chess-army-knife' ) );
			}
		}

		return Chess_Army_Knife_Tournament_Store::add_entry(
			array(
				'tournament_id' => (int) $tournament_id,
				'player_id'     => (int) $player_id,
				'name'          => $player['name'],
				'ecf_code'      => $player['ecf_code'],
			)
		);
	}

	/**
	 * Remove an entrant from a draft tournament.
	 *
	 * @param int $tournament_id Tournament id.
	 * @param int $entry_id      Entry id.
	 * @return true|WP_Error
	 */
	public static function remove_player( $tournament_id, $entry_id ) {
		$tournament = Chess_Army_Knife_Tournament_Store::get_tournament( $tournament_id );
		if ( ! $tournament || self::STATUS_DRAFT !== $tournament['status'] ) {
			return new WP_Error( 'tournament_started', __( 'Players can only be removed before the tournament starts.', 'chess-army-knife' ) );
		}
		foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id ) as $entry ) {
			if ( $entry['id'] === (int) $entry_id ) {
				Chess_Army_Knife_Tournament_Store::delete_entry( $entry_id );
				return true;
			}
		}
		return new WP_Error( 'tournament_missing', __( 'That player is not in this tournament.', 'chess-army-knife' ) );
	}

	/**
	 * Look up a player's current rating for seeding, bypassing the cache so
	 * the snapshot reflects the rating at the moment the tournament starts.
	 *
	 * @param array  $player Player profile (ecf_code, manual_rating).
	 * @param string $domain Rating domain.
	 * @return array { rating: int|null, source: 'ecf'|'manual'|'none' }
	 */
	public static function lookup_rating( array $player, $domain ) {
		$code = ECF_Client::normalise_code( $player['ecf_code'] );

		if ( '' !== $code ) {
			Chess_Army_Knife_Cache::forget( ECF_Client::cache_key_rating( $code, $domain ) );
			$data = ECF_Client::get_rating( $code, $domain );
			if ( ! is_wp_error( $data ) && is_array( $data ) ) {
				foreach ( array( 'revised_rating', 'original_rating', 'rating' ) as $key ) {
					if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) && (int) $data[ $key ] > 0 ) {
						return array(
							'rating' => (int) $data[ $key ],
							'source' => 'ecf',
						);
					}
				}
			}
		}

		if ( isset( $player['manual_rating'] ) && null !== $player['manual_rating'] ) {
			return array(
				'rating' => (int) $player['manual_rating'],
				'source' => 'manual',
			);
		}

		return array(
			'rating' => null,
			'source' => 'none',
		);
	}

	/**
	 * Order entrants for seeding: highest rating first, unrated last, then by name.
	 *
	 * @param array[] $entries Entries with start_rating and name.
	 * @return array[]
	 */
	public static function seed_order( array $entries ) {
		usort(
			$entries,
			function ( $a, $b ) {
				$rating_a = null === $a['start_rating'] ? -1 : $a['start_rating'];
				$rating_b = null === $b['start_rating'] ? -1 : $b['start_rating'];
				if ( $rating_a !== $rating_b ) {
					return $rating_b <=> $rating_a;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $entries;
	}

	/**
	 * Start a tournament: snapshot ratings, assign seeds, generate pairings.
	 *
	 * @param int $tournament_id Tournament id.
	 * @return true|WP_Error
	 */
	public static function start( $tournament_id ) {
		$tournament = Chess_Army_Knife_Tournament_Store::get_tournament( $tournament_id );
		if ( ! $tournament ) {
			return new WP_Error( 'tournament_missing', __( 'Tournament not found.', 'chess-army-knife' ) );
		}
		if ( self::STATUS_DRAFT !== $tournament['status'] ) {
			return new WP_Error( 'tournament_started', __( 'This tournament has already started.', 'chess-army-knife' ) );
		}

		$entries = Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id );
		if ( count( $entries ) < 2 ) {
			return new WP_Error( 'tournament_players', __( 'Add at least two players before starting.', 'chess-army-knife' ) );
		}

		// Snapshot ratings now; later rating changes never affect seeding.
		foreach ( $entries as $i => $entry ) {
			$player = Chess_Army_Knife_Tournament_Store::get_player( $entry['player_id'] );
			$rating = self::lookup_rating(
				array(
					'ecf_code'      => $entry['ecf_code'],
					'manual_rating' => $player ? $player['manual_rating'] : null,
				),
				$tournament['rating_domain']
			);

			$entries[ $i ]['start_rating']  = $rating['rating'];
			$entries[ $i ]['rating_source'] = $rating['source'];
		}

		$seeded = self::seed_order( $entries );
		foreach ( $seeded as $i => $entry ) {
			$seeded[ $i ]['seed'] = $i + 1;
			Chess_Army_Knife_Tournament_Store::update_entry(
				$entry['id'],
				array(
					'seed'          => $i + 1,
					'start_rating'  => $entry['start_rating'],
					'rating_source' => $entry['rating_source'],
				)
			);
		}

		self::generate_round_robin( $tournament, $seeded );

		Chess_Army_Knife_Tournament_Store::save_tournament(
			array(
				'id'         => $tournament_id,
				'status'     => self::STATUS_ACTIVE,
				'started_at' => current_time( 'mysql', true ),
			)
		);

		return true;
	}

	/**
	 * Create the games for a round-robin from the Berger tables.
	 *
	 * @param array   $tournament Tournament row.
	 * @param array[] $seeded     Entries in seed order (index 0 = seed 1).
	 */
	protected static function generate_round_robin( array $tournament, array $seeded ) {
		$rounds = Chess_Army_Knife_Berger::rounds( count( $seeded ), (bool) $tournament['double_round'] );

		foreach ( $rounds as $round_index => $pairs ) {
			foreach ( $pairs as $board => $pair ) {
				$white = isset( $seeded[ $pair[0] - 1 ] ) ? $seeded[ $pair[0] - 1 ]['id'] : null;
				$black = isset( $seeded[ $pair[1] - 1 ] ) ? $seeded[ $pair[1] - 1 ]['id'] : null;

				Chess_Army_Knife_Tournament_Store::add_game(
					array(
						'tournament_id'  => $tournament['id'],
						'stage'          => 'main',
						'round'          => $round_index + 1,
						'board'          => $board + 1,
						'white_entry_id' => $white,
						'black_entry_id' => $black,
						'is_bye'         => ( null === $white || null === $black ) ? 1 : 0,
					)
				);
			}
		}
	}

	/**
	 * Record (or clear) a game result.
	 *
	 * @param int         $game_id Game id.
	 * @param string|null $result  '1-0', '0-1', '1/2-1/2', or ''/null to clear.
	 * @return array|WP_Error The updated game.
	 */
	public static function record_result( $game_id, $result ) {
		$game = Chess_Army_Knife_Tournament_Store::get_game( $game_id );
		if ( ! $game ) {
			return new WP_Error( 'game_missing', __( 'Game not found.', 'chess-army-knife' ) );
		}
		if ( $game['is_bye'] ) {
			return new WP_Error( 'game_bye', __( 'A bye has no result.', 'chess-army-knife' ) );
		}

		$tournament = Chess_Army_Knife_Tournament_Store::get_tournament( $game['tournament_id'] );
		if ( ! $tournament || self::STATUS_DRAFT === $tournament['status'] ) {
			return new WP_Error( 'tournament_not_started', __( 'This tournament has not started.', 'chess-army-knife' ) );
		}

		$result = ( null === $result || '' === $result ) ? null : (string) $result;
		if ( null !== $result && ! in_array( $result, Chess_Army_Knife_Standings::results(), true ) ) {
			return new WP_Error( 'game_result', __( 'That is not a valid result.', 'chess-army-knife' ) );
		}

		Chess_Army_Knife_Tournament_Store::set_result( $game_id, $result );
		self::refresh_status( $tournament );

		return Chess_Army_Knife_Tournament_Store::get_game( $game_id );
	}

	/**
	 * Mark a tournament complete when every playable game has a result, and
	 * back to active if a result is later cleared.
	 *
	 * @param array $tournament Tournament row.
	 */
	protected static function refresh_status( array $tournament ) {
		$outstanding = count( self::games_to_play( $tournament['id'] ) );
		$new_status  = ( 0 === $outstanding ) ? self::STATUS_COMPLETE : self::STATUS_ACTIVE;

		if ( $new_status === $tournament['status'] ) {
			return;
		}

		Chess_Army_Knife_Tournament_Store::save_tournament(
			array(
				'id'           => $tournament['id'],
				'status'       => $new_status,
				'completed_at' => self::STATUS_COMPLETE === $new_status ? current_time( 'mysql', true ) : null,
			)
		);
	}

	/**
	 * Games still to be played: real games (not byes) with no result, between
	 * players who are both still in the tournament.
	 *
	 * @param int $tournament_id Tournament id.
	 * @return array[]
	 */
	public static function games_to_play( $tournament_id ) {
		$withdrawn = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id ) as $entry ) {
			if ( 'withdrawn' === $entry['status'] ) {
				$withdrawn[ $entry['id'] ] = true;
			}
		}

		$games = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_games( $tournament_id ) as $game ) {
			if ( $game['is_bye'] || null !== $game['result'] ) {
				continue;
			}
			if ( isset( $withdrawn[ $game['white_entry_id'] ] ) || isset( $withdrawn[ $game['black_entry_id'] ] ) ) {
				continue;
			}
			$games[] = $game;
		}
		return $games;
	}

	/**
	 * Withdraw a player from a running tournament. Their remaining games are
	 * dropped from the to-play list; the withdrawal rule (GR 6.6) is applied
	 * when standings are calculated.
	 *
	 * @param int $entry_id Entry id.
	 * @return true|WP_Error
	 */
	public static function withdraw( $entry_id ) {
		$entry      = Chess_Army_Knife_Tournament_Store::get_entry( $entry_id );
		$tournament = $entry ? Chess_Army_Knife_Tournament_Store::get_tournament( $entry['tournament_id'] ) : null;

		if ( ! $tournament || self::STATUS_ACTIVE !== $tournament['status'] ) {
			return new WP_Error( 'tournament_not_active', __( 'Players can only withdraw from a tournament in progress.', 'chess-army-knife' ) );
		}

		Chess_Army_Knife_Tournament_Store::update_entry( $entry_id, array( 'status' => 'withdrawn' ) );
		self::refresh_status( $tournament );
		return true;
	}

	/**
	 * Ranked standings for a tournament.
	 *
	 * @param int $tournament_id Tournament id.
	 * @return array[] Rows from Chess_Army_Knife_Standings::calculate().
	 */
	public static function standings( $tournament_id ) {
		$entries = Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id );
		$games   = Chess_Army_Knife_Tournament_Store::get_games( $tournament_id );

		$scheduled = array();
		foreach ( $games as $game ) {
			if ( $game['is_bye'] ) {
				continue;
			}
			foreach ( array( $game['white_entry_id'], $game['black_entry_id'] ) as $entry_id ) {
				$scheduled[ $entry_id ] = ( isset( $scheduled[ $entry_id ] ) ? $scheduled[ $entry_id ] : 0 ) + 1;
			}
		}

		return Chess_Army_Knife_Standings::calculate( $entries, $games, $scheduled );
	}
}
