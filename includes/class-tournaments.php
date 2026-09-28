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
			'knockout'    => __( 'Knockout', 'chess-army-knife' ),
		);
	}

	/**
	 * Group and knockout-stage settings for a tournament.
	 *
	 * A round-robin can be split into several groups, and can send the top
	 * `advance` players of each group into a knockout stage (0 = none). A
	 * knockout tournament always has one group and no group stage.
	 *
	 * @param array $tournament Tournament row.
	 * @return array { groups: int, advance: int }
	 */
	public static function config( array $tournament ) {
		$settings = isset( $tournament['settings'] ) && is_array( $tournament['settings'] ) ? $tournament['settings'] : array();
		if ( 'round-robin' !== $tournament['format'] ) {
			return array(
				'groups'  => 1,
				'advance' => 0,
			);
		}
		return array(
			'groups'  => max( 1, isset( $settings['groups'] ) ? (int) $settings['groups'] : 1 ),
			'advance' => max( 0, isset( $settings['advance'] ) ? (int) $settings['advance'] : 0 ),
		);
	}

	/**
	 * Whether a round-robin tournament has a knockout stage after the groups.
	 *
	 * @param array $tournament Tournament row.
	 * @return bool
	 */
	public static function has_knockout_stage( array $tournament ) {
		return 'knockout' === $tournament['format'] || self::config( $tournament )['advance'] > 0;
	}

	/**
	 * Label for a group number ("Group A").
	 *
	 * @param int $group_no Group number (1-based).
	 * @return string
	 */
	public static function group_label( $group_no ) {
		/* translators: %s: group letter, e.g. A */
		return sprintf( __( 'Group %s', 'chess-army-knife' ), chr( 64 + max( 1, min( 26, (int) $group_no ) ) ) );
	}

	/**
	 * Deal seeded players into groups "snake" style (1-2-3-4, 4-3-2-1, ...)
	 * so every group gets a similar mix of strong and weak players.
	 *
	 * @param int $count  Number of players.
	 * @param int $groups Number of groups.
	 * @return int[] Group number (1-based) for each seed, in seed order.
	 */
	public static function snake_groups( $count, $groups ) {
		$assignment = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$row          = intdiv( $i, $groups );
			$position     = $i % $groups;
			$assignment[] = ( 0 === $row % 2 ) ? $position + 1 : $groups - $position;
		}
		return $assignment;
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

		$groups  = 1;
		$advance = 0;
		if ( 'round-robin' === $format ) {
			$groups  = isset( $args['groups'] ) ? (int) $args['groups'] : 1;
			$advance = isset( $args['advance'] ) ? (int) $args['advance'] : 0;
			if ( $groups < 1 || $groups > 16 ) {
				return new WP_Error( 'tournament_groups', __( 'The number of groups must be between 1 and 16.', 'chess-army-knife' ) );
			}
			if ( $advance < 0 || ( $advance > 0 && $groups * $advance < 2 ) ) {
				return new WP_Error( 'tournament_advance', __( 'At least two players must advance to the knockout stage.', 'chess-army-knife' ) );
			}
		}

		return Chess_Army_Knife_Tournament_Store::save_tournament(
			array(
				'name'          => $name,
				'format'        => $format,
				'status'        => self::STATUS_DRAFT,
				'rating_domain' => ECF_Client::normalise_domain( isset( $args['rating_domain'] ) ? $args['rating_domain'] : 'S' ),
				'double_round'  => ( 'round-robin' === $format && ! empty( $args['double_round'] ) ) ? 1 : 0,
				'settings'      => array(
					'groups'  => $groups,
					'advance' => $advance,
				),
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
		$config  = self::config( $tournament );
		$check   = self::check_player_count( count( $entries ), $config );
		if ( is_wp_error( $check ) ) {
			return $check;
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
		$groups = ( 'round-robin' === $tournament['format'] && $config['groups'] > 1 ) ? self::snake_groups( count( $seeded ), $config['groups'] ) : array_fill( 0, count( $seeded ), 0 );

		foreach ( $seeded as $i => $entry ) {
			$seeded[ $i ]['seed']     = $i + 1;
			$seeded[ $i ]['group_no'] = $groups[ $i ];
			Chess_Army_Knife_Tournament_Store::update_entry(
				$entry['id'],
				array(
					'seed'          => $i + 1,
					'start_rating'  => $entry['start_rating'],
					'rating_source' => $entry['rating_source'],
					'group_no'      => $groups[ $i ],
				)
			);
		}

		if ( 'knockout' === $tournament['format'] ) {
			self::generate_knockout_games( $tournament_id, array_column( $seeded, 'id' ) );
		} else {
			self::generate_round_robin( $tournament, $seeded );
		}

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
	 * Check there are enough players for the tournament's format.
	 *
	 * @param int   $count  Number of entrants.
	 * @param array $config Result of config().
	 * @return true|WP_Error
	 */
	public static function check_player_count( $count, array $config ) {
		if ( $count < 2 ) {
			return new WP_Error( 'tournament_players', __( 'Add at least two players before starting.', 'chess-army-knife' ) );
		}
		if ( $config['groups'] > 1 && $count < $config['groups'] * 2 ) {
			return new WP_Error( 'tournament_groups_players', __( 'Every group needs at least two players.', 'chess-army-knife' ) );
		}
		$smallest_group = (int) floor( $count / $config['groups'] );
		if ( $config['advance'] > 0 && $config['advance'] >= $smallest_group ) {
			return new WP_Error( 'tournament_advance_players', __( 'Fewer players must advance than there are in the smallest group.', 'chess-army-knife' ) );
		}
		return true;
	}

	/**
	 * Create the group-stage games from the Berger tables.
	 *
	 * @param array   $tournament Tournament row.
	 * @param array[] $seeded     Entries in seed order, each with group_no.
	 */
	protected static function generate_round_robin( array $tournament, array $seeded ) {
		$by_group = array();
		foreach ( $seeded as $entry ) {
			$by_group[ $entry['group_no'] ][] = $entry;
		}

		foreach ( $by_group as $group_no => $members ) {
			$rounds = Chess_Army_Knife_Berger::rounds( count( $members ), (bool) $tournament['double_round'] );

			foreach ( $rounds as $round_index => $pairs ) {
				foreach ( $pairs as $board => $pair ) {
					$white = isset( $members[ $pair[0] - 1 ] ) ? $members[ $pair[0] - 1 ]['id'] : null;
					$black = isset( $members[ $pair[1] - 1 ] ) ? $members[ $pair[1] - 1 ]['id'] : null;

					Chess_Army_Knife_Tournament_Store::add_game(
						array(
							'tournament_id'  => $tournament['id'],
							'stage'          => 'main',
							'group_no'       => $group_no,
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
	}

	/**
	 * Create the knockout games for players in seed order.
	 *
	 * @param int   $tournament_id Tournament id.
	 * @param int[] $entry_ids     Entry ids in knockout seed order.
	 */
	protected static function generate_knockout_games( $tournament_id, array $entry_ids ) {
		foreach ( Chess_Army_Knife_Bracket::build( $entry_ids ) as $round => $boards ) {
			foreach ( $boards as $board => $match ) {
				Chess_Army_Knife_Tournament_Store::add_game(
					array(
						'tournament_id'  => (int) $tournament_id,
						'stage'          => 'knockout',
						'round'          => $round,
						'board'          => $board,
						'white_entry_id' => $match['white'],
						'black_entry_id' => $match['black'],
						'is_bye'         => $match['bye'] ? 1 : 0,
					)
				);
			}
		}
	}

	/**
	 * Record (or clear) a game result.
	 *
	 * In the knockout stage a drawn game creates a tie-break game (colours
	 * reversed); the first decisive game decides the tie and the winner moves
	 * on to the next round. When the last group game is played, the knockout
	 * bracket is created automatically.
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
		if ( null === $game['white_entry_id'] || null === $game['black_entry_id'] ) {
			return new WP_Error( 'game_waiting', __( 'This game is waiting for its players.', 'chess-army-knife' ) );
		}

		$tournament = Chess_Army_Knife_Tournament_Store::get_tournament( $game['tournament_id'] );
		if ( ! $tournament || self::STATUS_DRAFT === $tournament['status'] ) {
			return new WP_Error( 'tournament_not_started', __( 'This tournament has not started.', 'chess-army-knife' ) );
		}

		$result = ( null === $result || '' === $result ) ? null : (string) $result;
		if ( null !== $result && ! in_array( $result, Chess_Army_Knife_Standings::results(), true ) ) {
			return new WP_Error( 'game_result', __( 'That is not a valid result.', 'chess-army-knife' ) );
		}

		if ( 'knockout' === $game['stage'] ) {
			$outcome = self::record_knockout_result( $tournament, $game, $result );
		} else {
			$outcome = self::record_group_result( $tournament, $game, $result );
		}
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		self::refresh_status( Chess_Army_Knife_Tournament_Store::get_tournament( $tournament['id'] ) );

		$updated = Chess_Army_Knife_Tournament_Store::get_game( $game_id );
		return $updated ? $updated : $game;
	}

	/**
	 * Record a group-stage (or plain round-robin) result.
	 *
	 * @param array       $tournament Tournament row.
	 * @param array       $game       Game row.
	 * @param string|null $result     New result.
	 * @return true|WP_Error
	 */
	protected static function record_group_result( array $tournament, array $game, $result ) {
		// Changing a group result invalidates a bracket built from it, unless play has started there.
		$knockout = self::knockout_games( $tournament['id'] );
		if ( ! empty( $knockout ) ) {
			foreach ( $knockout as $knockout_game ) {
				if ( null !== $knockout_game['result'] ) {
					return new WP_Error( 'game_knockout_started', __( 'The knockout stage has started. Clear its results before changing a group result.', 'chess-army-knife' ) );
				}
			}
			Chess_Army_Knife_Tournament_Store::delete_stage_games( $tournament['id'], 'knockout' );
		}

		Chess_Army_Knife_Tournament_Store::set_result( $game['id'], $result );
		self::maybe_generate_knockout( $tournament );
		return true;
	}

	/**
	 * All knockout-stage games of a tournament.
	 *
	 * @param int $tournament_id Tournament id.
	 * @return array[]
	 */
	protected static function knockout_games( $tournament_id ) {
		return array_values(
			array_filter(
				Chess_Army_Knife_Tournament_Store::get_games( $tournament_id ),
				function ( $game ) {
					return 'knockout' === $game['stage'];
				}
			)
		);
	}

	/**
	 * The games of one tie (same round and board), in play order.
	 *
	 * @param array[] $knockout_games All knockout games.
	 * @param int     $round          Round number.
	 * @param int     $board          Board number.
	 * @return array[]
	 */
	protected static function tie_games( array $knockout_games, $round, $board ) {
		$tie = array();
		foreach ( $knockout_games as $game ) {
			if ( $game['round'] === (int) $round && $game['board'] === (int) $board ) {
				$tie[] = $game;
			}
		}
		usort(
			$tie,
			function ( $a, $b ) {
				return $a['id'] <=> $b['id'];
			}
		);
		return $tie;
	}

	/**
	 * Record a knockout result: edit the tie, create a tie-break game after a
	 * draw, and move the winner into the next round.
	 *
	 * @param array       $tournament Tournament row.
	 * @param array       $game       Game row.
	 * @param string|null $result     New result.
	 * @return true|WP_Error
	 */
	protected static function record_knockout_result( array $tournament, array $game, $result ) {
		$knockout = self::knockout_games( $tournament['id'] );
		$tie      = self::tie_games( $knockout, $game['round'], $game['board'] );

		// Only the latest played game of a tie can change: later tie-break games must be unplayed.
		$position = 0;
		foreach ( $tie as $index => $tie_game ) {
			if ( $tie_game['id'] === $game['id'] ) {
				$position = $index;
			}
		}
		foreach ( array_slice( $tie, $position + 1 ) as $later ) {
			if ( null !== $later['result'] ) {
				return new WP_Error( 'game_later_played', __( 'A later tie-break game has a result. Clear that first.', 'chess-army-knife' ) );
			}
		}

		// Work out the winner this change would produce, and whether the next round has already been played.
		$after                        = array_slice( $tie, 0, $position + 1 );
		$after[ $position ]['result'] = $result;
		$old_winner                   = Chess_Army_Knife_Bracket::tie_winner( $tie );
		$new_winner                   = Chess_Army_Knife_Bracket::tie_winner( $after );
		$total_rounds                 = self::knockout_round_count( $knockout );

		if ( $old_winner !== $new_winner && $game['round'] < $total_rounds ) {
			$parent = Chess_Army_Knife_Bracket::parent( $game['board'] );
			foreach ( self::tie_games( $knockout, $game['round'] + 1, $parent['board'] ) as $next_game ) {
				if ( null !== $next_game['result'] ) {
					return new WP_Error( 'game_next_round_played', __( 'The next round has already been played. Clear those results first.', 'chess-army-knife' ) );
				}
			}
		}

		// Apply: drop unplayed later games, save the result, and add a tie-break after a draw.
		foreach ( array_slice( $tie, $position + 1 ) as $later ) {
			Chess_Army_Knife_Tournament_Store::delete_game( $later['id'] );
		}
		Chess_Army_Knife_Tournament_Store::set_result( $game['id'], $result );

		if ( Chess_Army_Knife_Standings::DRAW === $result ) {
			Chess_Army_Knife_Tournament_Store::add_game(
				array(
					'tournament_id'  => $tournament['id'],
					'stage'          => 'knockout',
					'round'          => $game['round'],
					'board'          => $game['board'],
					'white_entry_id' => $game['black_entry_id'],
					'black_entry_id' => $game['white_entry_id'],
					'is_bye'         => 0,
				)
			);
		}

		if ( $old_winner !== $new_winner ) {
			self::place_winner( $tournament['id'], $game['round'], $game['board'], $new_winner );
		}
		self::resolve_walkovers( $tournament['id'] );

		return true;
	}

	/**
	 * Number of rounds in a tournament's knockout bracket.
	 *
	 * @param array[] $knockout_games All knockout games.
	 * @return int
	 */
	protected static function knockout_round_count( array $knockout_games ) {
		$max = 0;
		foreach ( $knockout_games as $game ) {
			$max = max( $max, $game['round'] );
		}
		return $max;
	}

	/**
	 * Put a tie's winner (or null to clear) into its slot in the next round.
	 *
	 * @param int      $tournament_id Tournament id.
	 * @param int      $round         Round of the decided tie.
	 * @param int      $board         Board of the decided tie.
	 * @param int|null $winner        Winning entry id, or null.
	 */
	protected static function place_winner( $tournament_id, $round, $board, $winner ) {
		$knockout = self::knockout_games( $tournament_id );
		if ( $round >= self::knockout_round_count( $knockout ) ) {
			return; // The final has no next round.
		}

		$parent = Chess_Army_Knife_Bracket::parent( $board );
		$next   = self::tie_games( $knockout, $round + 1, $parent['board'] );
		if ( empty( $next ) ) {
			return;
		}

		// The first game of the next tie holds the slot; any unplayed tie-break games there are now stale.
		foreach ( array_slice( $next, 1 ) as $stale ) {
			Chess_Army_Knife_Tournament_Store::delete_game( $stale['id'] );
		}
		Chess_Army_Knife_Tournament_Store::update_game( $next[0]['id'], array( $parent['slot'] . '_entry_id' => $winner ) );
	}

	/**
	 * Award walkovers: where a knockout game is ready to play but one player
	 * has withdrawn, the other player wins.
	 *
	 * @param int $tournament_id Tournament id.
	 */
	protected static function resolve_walkovers( $tournament_id ) {
		$withdrawn = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id ) as $entry ) {
			if ( 'withdrawn' === $entry['status'] ) {
				$withdrawn[ $entry['id'] ] = true;
			}
		}
		if ( empty( $withdrawn ) ) {
			return;
		}

		for ( $guard = 0; $guard < 64; $guard++ ) {
			$acted    = false;
			$knockout = self::knockout_games( $tournament_id );

			foreach ( $knockout as $game ) {
				if ( $game['is_bye'] || null !== $game['result'] || null === $game['white_entry_id'] || null === $game['black_entry_id'] ) {
					continue;
				}
				$white_out = isset( $withdrawn[ $game['white_entry_id'] ] );
				$black_out = isset( $withdrawn[ $game['black_entry_id'] ] );
				if ( $white_out === $black_out ) {
					continue; // Both present, or both withdrawn (an admin has to decide).
				}

				$tournament = Chess_Army_Knife_Tournament_Store::get_tournament( $tournament_id );
				$outcome    = self::record_knockout_result( $tournament, $game, $white_out ? Chess_Army_Knife_Standings::BLACK_WIN : Chess_Army_Knife_Standings::WHITE_WIN );
				if ( true === $outcome ) {
					$acted = true;
					break; // The bracket changed; look again from the start.
				}
			}

			if ( ! $acted ) {
				return;
			}
		}
	}

	/**
	 * Once every group game has a result, build the knockout bracket from the
	 * group qualifiers (once only).
	 *
	 * @param array $tournament Tournament row.
	 */
	protected static function maybe_generate_knockout( array $tournament ) {
		$config = self::config( $tournament );
		if ( 'round-robin' !== $tournament['format'] || $config['advance'] < 1 ) {
			return;
		}
		if ( ! empty( self::knockout_games( $tournament['id'] ) ) ) {
			return;
		}
		foreach ( self::games_to_play( $tournament['id'] ) as $game ) {
			if ( 'main' === $game['stage'] ) {
				return; // The group stage is not finished.
			}
		}

		$qualifiers = self::group_qualifiers( $tournament, $config['advance'] );
		if ( count( $qualifiers ) < 2 ) {
			return;
		}

		self::generate_knockout_games( $tournament['id'], Chess_Army_Knife_Bracket::seed_qualifiers( $qualifiers ) );
		self::resolve_walkovers( $tournament['id'] );
	}

	/**
	 * The players who qualify from each group, with their finishing place.
	 * Withdrawn players do not qualify; the next player in the group takes the place.
	 *
	 * @param array $tournament Tournament row.
	 * @param int   $advance    Players advancing from each group.
	 * @return array[] Each: id, group, place, points, tiebreak, seed.
	 */
	protected static function group_qualifiers( array $tournament, $advance ) {
		$config     = self::config( $tournament );
		$qualifiers = array();

		for ( $group = 1; $group <= max( 1, $config['groups'] ); $group++ ) {
			$group_no = ( 1 === $config['groups'] ) ? 0 : $group;
			$place    = 0;

			foreach ( self::standings( $tournament['id'], $group_no ) as $row ) {
				if ( $row['withdrawn'] ) {
					continue;
				}
				++$place;
				if ( $place > $advance ) {
					break;
				}
				$qualifiers[] = array(
					'id'       => $row['entry_id'],
					'group'    => $group,
					'place'    => $place,
					'points'   => $row['points'],
					'tiebreak' => $row['sonneborn_berger'],
					'seed'     => $row['seed'],
				);
			}
		}

		return $qualifiers;
	}

	/**
	 * Whether the tournament is finished.
	 *
	 * A knockout (or group-then-knockout) tournament ends when the final is
	 * decided; a plain round-robin ends when every game has a result.
	 *
	 * @param array $tournament Tournament row.
	 * @return bool
	 */
	protected static function is_finished( array $tournament ) {
		if ( self::has_knockout_stage( $tournament ) ) {
			$knockout = self::knockout_games( $tournament['id'] );
			if ( empty( $knockout ) ) {
				return false;
			}
			$final = self::knockout_round_count( $knockout );
			$games = self::tie_games( $knockout, $final, 1 );
			return null !== Chess_Army_Knife_Bracket::tie_winner( $games );
		}

		return 0 === count( self::games_to_play( $tournament['id'] ) ) && ! empty( Chess_Army_Knife_Tournament_Store::get_games( $tournament['id'] ) );
	}

	/**
	 * Mark a tournament complete when it is finished, and back to active if a
	 * result is later cleared.
	 *
	 * @param array $tournament Tournament row.
	 */
	protected static function refresh_status( array $tournament ) {
		$new_status = self::is_finished( $tournament ) ? self::STATUS_COMPLETE : self::STATUS_ACTIVE;

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
	 * two known players who are both still in the tournament. Bracket games
	 * still waiting for their players are not included.
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
			if ( $game['is_bye'] || null !== $game['result'] || null === $game['white_entry_id'] || null === $game['black_entry_id'] ) {
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
	 * Withdraw a player from a running tournament. Their remaining group
	 * games are dropped (the withdrawal rule, GR 6.6, is applied when
	 * standings are calculated) and knockout opponents get a walkover.
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
		self::resolve_walkovers( $tournament['id'] );
		self::maybe_generate_knockout( $tournament );
		self::refresh_status( Chess_Army_Knife_Tournament_Store::get_tournament( $tournament['id'] ) );
		return true;
	}

	/**
	 * Ranked group-stage standings.
	 *
	 * @param int      $tournament_id Tournament id.
	 * @param int|null $group_no      Restrict to one group (0 for an ungrouped round-robin); null for everyone.
	 * @return array[] Rows from Chess_Army_Knife_Standings::calculate().
	 */
	public static function standings( $tournament_id, $group_no = null ) {
		$entries = Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id );
		$games   = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_games( $tournament_id ) as $game ) {
			if ( 'main' === $game['stage'] && ( null === $group_no || $game['group_no'] === (int) $group_no ) ) {
				$games[] = $game;
			}
		}
		if ( null !== $group_no ) {
			$entries = array_values(
				array_filter(
					$entries,
					function ( $entry ) use ( $group_no ) {
						return $entry['group_no'] === (int) $group_no;
					}
				)
			);
		}

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

	/**
	 * The winner of a finished tournament: the knockout final's winner, or
	 * the top of the standings for a round-robin.
	 *
	 * @param int $tournament_id Tournament id.
	 * @return array|null The winner's entry row, or null if not finished.
	 */
	public static function champion( $tournament_id ) {
		$tournament = Chess_Army_Knife_Tournament_Store::get_tournament( $tournament_id );
		if ( ! $tournament || self::STATUS_COMPLETE !== $tournament['status'] ) {
			return null;
		}

		$winner_id = null;
		if ( self::has_knockout_stage( $tournament ) ) {
			$knockout  = self::knockout_games( $tournament_id );
			$final     = self::knockout_round_count( $knockout );
			$winner_id = Chess_Army_Knife_Bracket::tie_winner( self::tie_games( $knockout, $final, 1 ) );
		} else {
			$rows      = self::standings( $tournament_id );
			$winner_id = empty( $rows ) ? null : $rows[0]['entry_id'];
		}

		if ( null === $winner_id ) {
			return null;
		}
		return Chess_Army_Knife_Tournament_Store::get_entry( $winner_id );
	}
}
