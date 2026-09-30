<?php
/**
 * Integration tests: knockout tournaments and round-robin groups with a knockout stage.
 *
 * @package Chess_Army_Knife
 */

class KnockoutTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( 'games', 'entries', 'tournaments', 'members' ) as $name ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Tournament_Store::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Tournament_Store::install_tables();
	}

	/**
	 * Create and start a tournament with $count players seeded by manual rating
	 * (Player 1 is the top seed). No ECF lookups are made.
	 *
	 * @return int Tournament id.
	 */
	private function started( $count, array $args = array() ) {
		$id = Chess_Army_Knife_Tournaments::create( array_merge( array( 'name' => 'Cup' ), $args ) );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		for ( $i = 1; $i <= $count; $i++ ) {
			$player = Chess_Army_Knife_Membership_Store::add_guest(
				array(
					'name'          => "Player {$i}",
					'ecf_code'      => '',
					'manual_rating' => 2200 - $i * 10,
				)
			);
			Chess_Army_Knife_Tournaments::add_player( $id, $player );
		}

		$started = Chess_Army_Knife_Tournaments::start( $id );
		$this->assertTrue( $started, is_wp_error( $started ) ? $started->get_error_message() : '' );
		return $id;
	}

	/**
	 * Entry rows keyed by seed.
	 */
	private function seeds( $tournament_id ) {
		$by_seed = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id ) as $entry ) {
			$by_seed[ $entry['seed'] ] = $entry;
		}
		return $by_seed;
	}

	/**
	 * Knockout games of a round in play order.
	 */
	private function round( $tournament_id, $round ) {
		return array_values(
			array_filter(
				Chess_Army_Knife_Tournament_Store::get_games( $tournament_id ),
				function ( $game ) use ( $round ) {
					return 'knockout' === $game['stage'] && $game['round'] === $round;
				}
			)
		);
	}

	private function play( $game_id, $result ) {
		$updated = Chess_Army_Knife_Tournaments::record_result( $game_id, $result );
		$this->assertNotWPError( $updated );
		return $updated;
	}

	public function test_eight_player_knockout_uses_standard_seeding() {
		$id    = $this->started( 8, array( 'format' => 'knockout' ) );
		$seeds = $this->seeds( $id );

		$games = $this->round( $id, 1 );
		$this->assertCount( 4, $games );

		$pairs = array();
		foreach ( $games as $game ) {
			$pairs[] = array( $game['white_entry_id'], $game['black_entry_id'] );
		}
		// 1v8, 4v5, 2v7, 3v6.
		$this->assertSame(
			array(
				array( $seeds[1]['id'], $seeds[8]['id'] ),
				array( $seeds[4]['id'], $seeds[5]['id'] ),
				array( $seeds[2]['id'], $seeds[7]['id'] ),
				array( $seeds[3]['id'], $seeds[6]['id'] ),
			),
			$pairs
		);

		$this->assertCount( 2, $this->round( $id, 2 ) );
		$this->assertCount( 1, $this->round( $id, 3 ) );
		$this->assertCount( 4, Chess_Army_Knife_Tournaments::games_to_play( $id ) ); // Later rounds are not playable yet.
	}

	public function test_winners_advance_and_the_final_completes_the_tournament() {
		$id    = $this->started( 8, array( 'format' => 'knockout' ) );
		$seeds = $this->seeds( $id );

		foreach ( $this->round( $id, 1 ) as $game ) {
			$this->play( $game['id'], '1-0' ); // The higher seed (white) wins each game.
		}

		$semis = $this->round( $id, 2 );
		$this->assertSame( array( $seeds[1]['id'], $seeds[4]['id'] ), array( $semis[0]['white_entry_id'], $semis[0]['black_entry_id'] ) );
		$this->assertSame( array( $seeds[2]['id'], $seeds[3]['id'] ), array( $semis[1]['white_entry_id'], $semis[1]['black_entry_id'] ) );

		$this->play( $semis[0]['id'], '1-0' );
		$this->play( $semis[1]['id'], '0-1' ); // Seed 3 beats seed 2.

		$final = $this->round( $id, 3 )[0];
		$this->assertSame( array( $seeds[1]['id'], $seeds[3]['id'] ), array( $final['white_entry_id'], $final['black_entry_id'] ) );
		$this->assertSame( 'active', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
		$this->assertNull( Chess_Army_Knife_Tournaments::champion( $id ) );

		$this->play( $final['id'], '0-1' );

		$this->assertSame( 'complete', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
		$this->assertSame( $seeds[3]['id'], Chess_Army_Knife_Tournaments::champion( $id )['id'] );
	}

	public function test_top_seeds_get_byes_when_the_field_is_not_a_power_of_two() {
		$id    = $this->started( 6, array( 'format' => 'knockout' ) );
		$seeds = $this->seeds( $id );

		$round_1 = $this->round( $id, 1 );
		$byes    = array_filter(
			$round_1,
			function ( $game ) {
				return $game['is_bye'];
			}
		);
		$this->assertCount( 2, $byes );
		$this->assertCount( 2, Chess_Army_Knife_Tournaments::games_to_play( $id ) );

		$semis = $this->round( $id, 2 );
		$this->assertSame( $seeds[1]['id'], $semis[0]['white_entry_id'] );
		$this->assertSame( $seeds[2]['id'], $semis[1]['white_entry_id'] );

		// A bye cannot be given a result.
		$this->assertWPError( Chess_Army_Knife_Tournaments::record_result( reset( $byes )['id'], '1-0' ) );
	}

	public function test_a_draw_creates_a_tie_break_game_with_colours_reversed() {
		$id    = $this->started( 2, array( 'format' => 'knockout' ) );
		$seeds = $this->seeds( $id );
		$final = $this->round( $id, 1 )[0];

		$this->play( $final['id'], '1/2-1/2' );

		$games = $this->round( $id, 1 );
		$this->assertCount( 2, $games );
		$this->assertSame( $seeds[1]['id'], $final['white_entry_id'] );
		$this->assertSame( $seeds[2]['id'], $games[1]['white_entry_id'] );
		$this->assertSame( $seeds[1]['id'], $games[1]['black_entry_id'] );
		$this->assertSame( 'active', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
		$this->assertCount( 1, Chess_Army_Knife_Tournaments::games_to_play( $id ) );

		// A drawn tie-break needs another one; then a decisive result ends it.
		$this->play( $games[1]['id'], '1/2-1/2' );
		$this->assertCount( 3, $this->round( $id, 1 ) );
		$this->play( $this->round( $id, 1 )[2]['id'], '1-0' ); // Seed 1 (white again in game 3) wins.

		$this->assertSame( 'complete', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
		$this->assertSame( $seeds[1]['id'], Chess_Army_Knife_Tournaments::champion( $id )['id'] );
	}

	public function test_changing_a_drawn_result_removes_the_unplayed_tie_break() {
		$id   = $this->started( 2, array( 'format' => 'knockout' ) );
		$game = $this->round( $id, 1 )[0];

		$this->play( $game['id'], '1/2-1/2' );
		$this->assertCount( 2, $this->round( $id, 1 ) );

		$this->play( $game['id'], '1-0' );

		$this->assertCount( 1, $this->round( $id, 1 ) );
		$this->assertSame( 'complete', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
	}

	public function test_an_earlier_game_cannot_change_once_a_later_tie_break_is_played() {
		$id   = $this->started( 2, array( 'format' => 'knockout' ) );
		$game = $this->round( $id, 1 )[0];
		$this->play( $game['id'], '1/2-1/2' );
		$this->play( $this->round( $id, 1 )[1]['id'], '1-0' );

		$this->assertWPError( Chess_Army_Knife_Tournaments::record_result( $game['id'], '0-1' ) );
	}

	public function test_correcting_a_result_updates_the_next_round_until_it_is_played() {
		$id    = $this->started( 4, array( 'format' => 'knockout' ) );
		$seeds = $this->seeds( $id );
		$round = $this->round( $id, 1 ); // 1v4 and 2v3.

		$this->play( $round[0]['id'], '1-0' );
		$this->assertSame( $seeds[1]['id'], $this->round( $id, 2 )[0]['white_entry_id'] );

		// Correct the result: seed 4 actually won.
		$this->play( $round[0]['id'], '0-1' );
		$this->assertSame( $seeds[4]['id'], $this->round( $id, 2 )[0]['white_entry_id'] );

		// Clearing it empties the slot again.
		$this->play( $round[0]['id'], '' );
		$this->assertNull( $this->round( $id, 2 )[0]['white_entry_id'] );

		// Once the final is played, earlier results are locked until it is cleared.
		$this->play( $round[0]['id'], '1-0' );
		$this->play( $round[1]['id'], '1-0' );
		$final = $this->round( $id, 2 )[0];
		$this->play( $final['id'], '1-0' );

		$this->assertWPError( Chess_Army_Knife_Tournaments::record_result( $round[0]['id'], '0-1' ) );
		$this->play( $final['id'], '' );
		$this->play( $round[0]['id'], '0-1' );
		$this->assertSame( $seeds[4]['id'], $this->round( $id, 2 )[0]['white_entry_id'] );
	}

	public function test_games_still_waiting_for_players_cannot_be_recorded() {
		$id    = $this->started( 4, array( 'format' => 'knockout' ) );
		$final = $this->round( $id, 2 )[0];

		$this->assertSame( 'game_waiting', Chess_Army_Knife_Tournaments::record_result( $final['id'], '1-0' )->get_error_code() );
	}

	public function test_withdrawing_gives_the_opponent_a_walkover() {
		$id    = $this->started( 4, array( 'format' => 'knockout' ) );
		$seeds = $this->seeds( $id );

		$this->assertTrue( Chess_Army_Knife_Tournaments::withdraw( $seeds[4]['id'] ) ); // Seed 4 was due to play seed 1.

		$first = $this->round( $id, 1 )[0];
		$this->assertSame( '1-0', $first['result'] );
		$this->assertSame( $seeds[1]['id'], $this->round( $id, 2 )[0]['white_entry_id'] );
	}

	public function test_a_withdrawn_player_who_reaches_a_game_forfeits_it() {
		$id    = $this->started( 4, array( 'format' => 'knockout' ) );
		$seeds = $this->seeds( $id );
		$round = $this->round( $id, 1 );

		$this->play( $round[0]['id'], '1-0' ); // Seed 1 through.
		Chess_Army_Knife_Tournaments::withdraw( $seeds[2]['id'] ); // Seed 2 withdraws before their game.

		// Seed 3 wins by walkover and meets seed 1 in the final.
		$this->assertSame( '0-1', $this->round( $id, 1 )[1]['result'] );
		$final = $this->round( $id, 2 )[0];
		$this->assertSame( array( $seeds[1]['id'], $seeds[3]['id'] ), array( $final['white_entry_id'], $final['black_entry_id'] ) );
	}

	public function test_creation_rules_for_groups_and_advancing() {
		$this->assertWPError(
			Chess_Army_Knife_Tournaments::create(
				array(
					'name'   => 'X',
					'groups' => 0,
				)
			)
		);
		$this->assertWPError(
			Chess_Army_Knife_Tournaments::create(
				array(
					'name'    => 'X',
					'groups'  => 1,
					'advance' => 1,
				)
			)
		);
		$this->assertIsInt(
			Chess_Army_Knife_Tournaments::create(
				array(
					'name'    => 'X',
					'groups'  => 4,
					'advance' => 2,
				)
			)
		);
	}

	public function test_starting_checks_group_sizes() {
		$id = Chess_Army_Knife_Tournaments::create(
			array(
				'name'    => 'X',
				'groups'  => 4,
				'advance' => 2,
			)
		);
		for ( $i = 1; $i <= 8; $i++ ) {
			Chess_Army_Knife_Tournaments::add_player(
				$id,
				Chess_Army_Knife_Membership_Store::add_guest(
					array(
						'name'          => "P{$i}",
						'ecf_code'      => '',
						'manual_rating' => null,
					)
				)
			);
		}

		$this->assertWPError( Chess_Army_Knife_Tournaments::start( $id ) ); // Groups of 2 cannot send 2 on.
	}

	/**
	 * Group stage for 8 players in 2 groups of 4, top 2 advancing.
	 */
	private function groups_tournament() {
		return $this->started(
			8,
			array(
				'groups'  => 2,
				'advance' => 2,
			)
		);
	}

	private function group_games( $tournament_id ) {
		return array_values(
			array_filter(
				Chess_Army_Knife_Tournament_Store::get_games( $tournament_id ),
				function ( $game ) {
					return 'main' === $game['stage'];
				}
			)
		);
	}

	public function test_players_are_split_into_groups_and_each_group_plays_a_round_robin() {
		$id    = $this->groups_tournament();
		$seeds = $this->seeds( $id );

		$this->assertSame( array( 1, 2, 2, 1, 1, 2, 2, 1 ), array_values( wp_list_pluck( $seeds, 'group_no' ) ) );
		$games = $this->group_games( $id );
		$this->assertCount( 12, $games ); // Two groups of 4: 6 games each.
		$this->assertCount(
			6,
			array_filter(
				$games,
				function ( $game ) {
					return 1 === $game['group_no'];
				}
			)
		);
		$this->assertSame( array(), $this->round( $id, 1 ) ); // No bracket yet.
	}

	public function test_the_knockout_is_created_when_the_last_group_game_is_played() {
		$id    = $this->groups_tournament();
		$seeds = $this->seeds( $id );

		$games = $this->group_games( $id );
		$last  = array_pop( $games );
		foreach ( $games as $game ) {
			$this->play( $game['id'], '1-0' );
		}
		$this->assertSame( array(), $this->round( $id, 1 ) );

		$this->play( $last['id'], '1-0' );

		$knockout = $this->round( $id, 1 );
		$this->assertCount( 2, $knockout ); // 4 qualifiers: semi-finals.
		$this->assertCount( 1, $this->round( $id, 2 ) );
		$this->assertSame( 'active', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );

		// Group winners meet the other group's runner-up.
		$group_of = wp_list_pluck( array_values( $seeds ), 'group_no', 'id' );
		foreach ( $knockout as $game ) {
			$this->assertNotSame( $group_of[ $game['white_entry_id'] ], $group_of[ $game['black_entry_id'] ] );
		}

		// Play it out.
		foreach ( $knockout as $game ) {
			$this->play( $game['id'], '1-0' );
		}
		$this->play( $this->round( $id, 2 )[0]['id'], '1-0' );

		$this->assertSame( 'complete', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
		$this->assertNotNull( Chess_Army_Knife_Tournaments::champion( $id ) );
	}

	public function test_changing_a_group_result_rebuilds_an_unplayed_bracket() {
		$id    = $this->groups_tournament();
		$games = $this->group_games( $id );
		foreach ( $games as $game ) {
			$this->play( $game['id'], '1-0' );
		}
		$this->assertCount( 2, $this->round( $id, 1 ) );

		$this->play( $games[0]['id'], '' ); // A result is withdrawn: the group stage is unfinished again.
		$this->assertSame( array(), $this->round( $id, 1 ) );

		$this->play( $games[0]['id'], '1-0' ); // Finished again: the bracket comes back.
		$this->assertCount( 2, $this->round( $id, 1 ) );
	}

	public function test_a_group_result_cannot_change_after_the_knockout_has_started() {
		$id    = $this->groups_tournament();
		$games = $this->group_games( $id );
		foreach ( $games as $game ) {
			$this->play( $game['id'], '1-0' );
		}
		$this->play( $this->round( $id, 1 )[0]['id'], '1-0' );

		$this->assertSame( 'game_knockout_started', Chess_Army_Knife_Tournaments::record_result( $games[0]['id'], '0-1' )->get_error_code() );
	}

	public function test_withdrawn_players_do_not_qualify_from_their_group() {
		$id    = $this->groups_tournament();
		$seeds = $this->seeds( $id );

		// Seed 1 (group A's top seed) withdraws at once; the rest of the group stage is played.
		Chess_Army_Knife_Tournaments::withdraw( $seeds[1]['id'] );
		foreach ( $this->group_games( $id ) as $game ) {
			if ( null === $game['result'] && ! $game['is_bye'] && $seeds[1]['id'] !== $game['white_entry_id'] && $seeds[1]['id'] !== $game['black_entry_id'] ) {
				$this->play( $game['id'], '1-0' );
			}
		}

		$in_bracket = array();
		foreach ( $this->round( $id, 1 ) as $game ) {
			$in_bracket[] = $game['white_entry_id'];
			$in_bracket[] = $game['black_entry_id'];
		}
		$this->assertCount( 4, $in_bracket );
		$this->assertNotContains( $seeds[1]['id'], $in_bracket );
	}

	public function test_plain_round_robin_with_top_players_advancing_from_one_group() {
		$id = $this->started(
			6,
			array(
				'groups'  => 1,
				'advance' => 4,
			)
		);
		foreach ( $this->group_games( $id ) as $game ) {
			$this->play( $game['id'], '1-0' );
		}

		$this->assertCount( 2, $this->round( $id, 1 ) ); // Top 4 of the league play semi-finals.
		$this->assertSame( 'active', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
	}

	public function test_rest_result_flags_when_the_page_needs_to_reload() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id    = $this->started( 4, array( 'format' => 'knockout' ) );
		$first = $this->round( $id, 1 )[0];

		$request = new WP_REST_Request( 'POST', '/chess-army-knife/v1/games/' . $first['id'] . '/result' );
		$request->set_param( 'result', '1-0' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['reload'] ); // A winner moved into the next round.
	}

	public function test_rest_result_for_a_group_game_only_reloads_when_the_bracket_appears() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id    = $this->groups_tournament();
		$games = $this->group_games( $id );
		$last  = array_pop( $games );
		foreach ( $games as $game ) {
			$this->play( $game['id'], '1-0' );
		}

		$request = new WP_REST_Request( 'POST', '/chess-army-knife/v1/games/' . $games[0]['id'] . '/result' );
		$request->set_param( 'result', '0-1' );
		$this->assertFalse( rest_do_request( $request )->get_data()['reload'] );

		$request = new WP_REST_Request( 'POST', '/chess-army-knife/v1/games/' . $last['id'] . '/result' );
		$request->set_param( 'result', '1-0' );
		$this->assertTrue( rest_do_request( $request )->get_data()['reload'] ); // The knockout was created.
	}

	public function test_games_block_shows_knockout_rounds_but_hides_games_awaiting_players() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = $this->started( 4, array( 'format' => 'knockout' ) );

		$html = do_blocks( '<!-- wp:chess-army-knife/tournament-games {"tournamentId":' . $id . '} /-->' );

		$this->assertStringContainsString( 'Semi-finals', $html );
		$this->assertStringNotContainsString( '>Final<', $html );
		$this->assertSame( 2, substr_count( $html, 'data-game-id' ) ); // Only the two semi-finals are ready to play.
	}
}
