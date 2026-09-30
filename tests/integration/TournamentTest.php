<?php
/**
 * Integration tests: tournaments running against real WordPress and database.
 *
 * @package Chess_Army_Knife
 */

class TournamentTest extends WP_UnitTestCase {

	/** @var int[] Player ids keyed by name. */
	private $players = array();

	public function set_up() {
		parent::set_up();
		global $wpdb;

		// Use transients for the ECF cache, and start each test with fresh (temporary) tables.
		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( 'games', 'entries', 'tournaments', 'members' ) as $name ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Tournament_Store::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Tournament_Store::install_tables();
	}

	/**
	 * Answer ECF rating requests from a code => rating map.
	 */
	private function mock_ratings( array $ratings ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $ratings ) {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$code = isset( $query['player_no'] ) ? $query['player_no'] : '';
				if ( ! isset( $ratings[ $code ] ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'success' => false,
								'message' => 'Not found',
							)
						),
						'response' => array(
							'code'    => 404,
							'message' => '',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'success' => true,
							'data'    => array( 'revised_rating' => $ratings[ $code ] ),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	private function add_player( $name, $code = '', $manual = null ) {
		$id                     = Chess_Army_Knife_Membership_Store::add_guest(
			array(
				'name'          => $name,
				'ecf_code'      => $code,
				'manual_rating' => $manual,
			)
		);
		$this->players[ $name ] = $id;
		return $id;
	}

	private function create_tournament( array $args = array() ) {
		return Chess_Army_Knife_Tournaments::create( array_merge( array( 'name' => 'Club Championship' ), $args ) );
	}

	/**
	 * A draft tournament with four players (ratings by ECF code or manual).
	 */
	private function four_player_tournament() {
		$this->mock_ratings(
			array(
				'100001' => 1900,
				'100002' => 2100,
				'100003' => 1700,
			)
		);
		$id = $this->create_tournament();
		foreach ( array(
			$this->add_player( 'Alice', '100001A' ),
			$this->add_player( 'Bob', '100002B' ),
			$this->add_player( 'Cara', '100003C' ),
			$this->add_player( 'Dan', '', 1500 ),
		) as $player_id ) {
			$this->assertIsInt( Chess_Army_Knife_Tournaments::add_player( $id, $player_id ) );
		}
		return $id;
	}

	public function test_a_players_name_and_code_are_read_from_their_record_never_copied() {
		$tournament = $this->create_tournament();
		$id         = $this->add_player( 'Alice', '100001A', 1650 );
		Chess_Army_Knife_Tournaments::add_player( $tournament, $id );

		$entry = Chess_Army_Knife_Tournament_Store::get_entries( $tournament )[0];
		$this->assertSame( 'Alice', $entry['name'] );
		$this->assertSame( '100001A', $entry['ecf_code'] );
		$this->assertSame( $id, $entry['player_id'] );

		// Correcting the record corrects every tournament: there is only one copy.
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'id'   => $id,
				'name' => 'Alice Smith',
			)
		);
		$this->assertSame( 'Alice Smith', Chess_Army_Knife_Tournament_Store::get_entries( $tournament )[0]['name'] );
		$this->assertSame( 'Alice Smith', Chess_Army_Knife_Tournament_Store::get_entry( $entry['id'] )['name'] );
	}

	public function test_a_tournament_holds_no_personal_details_of_its_own() {
		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Chess_Army_Knife_Tournament_Store::table( 'entries' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test inspects the plugin's own table.

		$this->assertNotContains( 'name', $columns );
		$this->assertNotContains( 'ecf_code', $columns );
		$this->assertEmpty( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', '%chess_army_knife_players' ) ), 'There is no separate table of player profiles.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test inspects the plugin's own tables.
	}

	public function test_erasing_a_player_entered_in_a_tournament_keeps_the_tournament_intact() {
		$tournament = $this->create_tournament();
		$id         = $this->add_player( 'Alice', '100001A', 1650 );
		Chess_Army_Knife_Tournaments::add_player( $tournament, $id );

		$this->assertSame( 'anonymised', Chess_Army_Knife_Membership_Store::erase_member( $id ) );

		$entries = Chess_Army_Knife_Tournament_Store::get_entries( $tournament );
		$this->assertCount( 1, $entries );
		$this->assertSame( Chess_Army_Knife_Membership_Store::erased_name(), $entries[0]['name'] );
		$this->assertSame( '', $entries[0]['ecf_code'] );
		$this->assertNull( Chess_Army_Knife_Membership_Store::get_member( $id )['manual_rating'] );
	}

	public function test_a_person_deleted_outright_still_leaves_a_readable_entry() {
		$tournament = $this->create_tournament();
		$id         = $this->add_player( 'Alice' );
		Chess_Army_Knife_Tournaments::add_player( $tournament, $id );

		Chess_Army_Knife_Membership_Store::delete_member( $id );

		$this->assertSame( Chess_Army_Knife_Membership_Store::erased_name(), Chess_Army_Knife_Tournament_Store::get_entries( $tournament )[0]['name'] );
	}

	public function test_only_current_members_and_guests_can_be_entered() {
		$tournament = $this->create_tournament();
		$applicant  = Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'   => 'Applicant',
				'status' => 'pending',
			)
		);
		$lapsed     = Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'        => 'Lapsed',
				'status'      => 'active',
				'expiry_date' => '2020-01-01',
			)
		);
		$current    = Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'   => 'Current',
				'status' => 'active',
			)
		);

		$this->assertSame( 'tournament_ineligible', Chess_Army_Knife_Tournaments::add_player( $tournament, $applicant )->get_error_code() );
		$this->assertSame( 'tournament_ineligible', Chess_Army_Knife_Tournaments::add_player( $tournament, $lapsed )->get_error_code() );
		$this->assertIsInt( Chess_Army_Knife_Tournaments::add_player( $tournament, $current ) );
		$this->assertIsInt( Chess_Army_Knife_Tournaments::add_player( $tournament, $this->add_player( 'Guest' ) ) );
	}

	public function test_create_validates_name_and_format() {
		$this->assertWPError( Chess_Army_Knife_Tournaments::create( array( 'name' => '  ' ) ) );
		$this->assertWPError(
			Chess_Army_Knife_Tournaments::create(
				array(
					'name'   => 'X',
					'format' => 'made-up',
				)
			)
		);
	}

	public function test_cannot_enter_the_same_player_twice() {
		$id     = $this->create_tournament();
		$player = $this->add_player( 'Alice' );

		Chess_Army_Knife_Tournaments::add_player( $id, $player );

		$this->assertWPError( Chess_Army_Knife_Tournaments::add_player( $id, $player ) );
	}

	public function test_cannot_start_with_fewer_than_two_players() {
		$id = $this->create_tournament();
		Chess_Army_Knife_Tournaments::add_player( $id, $this->add_player( 'Alice' ) );

		$this->assertWPError( Chess_Army_Knife_Tournaments::start( $id ) );
	}

	public function test_start_snapshots_ratings_seeds_and_generates_pairings() {
		$id = $this->four_player_tournament();

		$this->assertTrue( Chess_Army_Knife_Tournaments::start( $id ) );

		$entries = Chess_Army_Knife_Tournament_Store::get_entries( $id );
		$this->assertSame( array( 'Bob', 'Alice', 'Cara', 'Dan' ), wp_list_pluck( $entries, 'name' ) );
		$this->assertSame( array( 1, 2, 3, 4 ), wp_list_pluck( $entries, 'seed' ) );
		$this->assertSame( array( 2100, 1900, 1700, 1500 ), wp_list_pluck( $entries, 'start_rating' ) );
		$this->assertSame( array( 'ecf', 'ecf', 'ecf', 'manual' ), wp_list_pluck( $entries, 'rating_source' ) );

		$games = Chess_Army_Knife_Tournament_Store::get_games( $id );
		$this->assertCount( 6, $games ); // 4 players: 3 rounds of 2 games.
		$this->assertSame( array( 1, 1, 2, 2, 3, 3 ), wp_list_pluck( $games, 'round' ) );

		// Round 1 is seed 1 v seed 4 and seed 2 v seed 3 (Berger table).
		$this->assertSame( $entries[0]['id'], $games[0]['white_entry_id'] );
		$this->assertSame( $entries[3]['id'], $games[0]['black_entry_id'] );

		$this->assertSame( 'active', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
		$this->assertWPError( Chess_Army_Knife_Tournaments::start( $id ) );
	}

	public function test_missing_ecf_rating_falls_back_to_manual_then_unrated() {
		$this->mock_ratings( array() ); // The ECF knows nobody.
		$id = $this->create_tournament();
		Chess_Army_Knife_Tournaments::add_player( $id, $this->add_player( 'Alice', '999999Z', 1400 ) );
		Chess_Army_Knife_Tournaments::add_player( $id, $this->add_player( 'Bob', '888888Y' ) );

		Chess_Army_Knife_Tournaments::start( $id );

		$entries = Chess_Army_Knife_Tournament_Store::get_entries( $id );
		$this->assertSame( 'Alice', $entries[0]['name'] );
		$this->assertSame( 'manual', $entries[0]['rating_source'] );
		$this->assertNull( $entries[1]['start_rating'] );
		$this->assertSame( 'none', $entries[1]['rating_source'] );
	}

	public function test_ratings_changing_after_start_do_not_change_seeding() {
		$id = $this->four_player_tournament();
		Chess_Army_Knife_Tournaments::start( $id );

		// Alice's ECF rating jumps past Bob's after the tournament has started.
		remove_all_filters( 'pre_http_request' );
		$this->mock_ratings( array( '100001' => 2500 ) );
		Chess_Army_Knife_Cache::forget( Chess_Army_Knife_ECF_Client::cache_key_rating( '100001', 'S' ) );

		$entries = Chess_Army_Knife_Tournament_Store::get_entries( $id );
		$this->assertSame( 'Bob', $entries[0]['name'] );
		$this->assertSame( 1900, $entries[1]['start_rating'] );
	}

	public function test_recording_results_updates_standings_and_completes_the_tournament() {
		$id = $this->four_player_tournament();
		Chess_Army_Knife_Tournaments::start( $id );

		$this->assertCount( 6, Chess_Army_Knife_Tournaments::games_to_play( $id ) );

		foreach ( Chess_Army_Knife_Tournament_Store::get_games( $id ) as $game ) {
			$updated = Chess_Army_Knife_Tournaments::record_result( $game['id'], '1-0' );
			$this->assertSame( '1-0', $updated['result'] );
		}

		$this->assertSame( 'complete', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
		$this->assertCount( 0, Chess_Army_Knife_Tournaments::games_to_play( $id ) );

		$standings = Chess_Army_Knife_Tournaments::standings( $id );
		$this->assertCount( 4, $standings );
		$this->assertSame( 6.0, array_sum( wp_list_pluck( $standings, 'points' ) ) );

		// Clearing a result reopens the tournament.
		$first = Chess_Army_Knife_Tournament_Store::get_games( $id )[0];
		Chess_Army_Knife_Tournaments::record_result( $first['id'], '' );
		$this->assertSame( 'active', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
	}

	public function test_invalid_results_and_unstarted_tournaments_are_rejected() {
		$id = $this->four_player_tournament();
		Chess_Army_Knife_Tournaments::start( $id );
		$game = Chess_Army_Knife_Tournament_Store::get_games( $id )[0];

		$this->assertWPError( Chess_Army_Knife_Tournaments::record_result( $game['id'], '2-0' ) );
		$this->assertWPError( Chess_Army_Knife_Tournaments::record_result( 999999, '1-0' ) );
		$this->assertNull( Chess_Army_Knife_Tournament_Store::get_game( $game['id'] )['result'] );
	}

	public function test_odd_player_count_creates_byes_that_need_no_result() {
		$id = $this->create_tournament();
		foreach ( array( 'A', 'B', 'C' ) as $name ) {
			Chess_Army_Knife_Tournaments::add_player( $id, $this->add_player( $name, '', 1500 ) );
		}
		Chess_Army_Knife_Tournaments::start( $id );

		$games = Chess_Army_Knife_Tournament_Store::get_games( $id );
		$byes  = array_filter(
			$games,
			function ( $game ) {
				return $game['is_bye'];
			}
		);
		$this->assertCount( 3, $byes );
		$this->assertCount( 3, Chess_Army_Knife_Tournaments::games_to_play( $id ) );

		$bye = reset( $byes );
		$this->assertWPError( Chess_Army_Knife_Tournaments::record_result( $bye['id'], '1-0' ) );
	}

	public function test_withdrawing_drops_unplayed_games() {
		$id = $this->four_player_tournament();
		Chess_Army_Knife_Tournaments::start( $id );
		$dan = Chess_Army_Knife_Tournament_Store::get_entries( $id )[3];

		$this->assertTrue( Chess_Army_Knife_Tournaments::withdraw( $dan['id'] ) );

		$this->assertCount( 3, Chess_Army_Knife_Tournaments::games_to_play( $id ) ); // Dan's 3 games are gone.
	}

	public function test_rest_result_endpoint_requires_permission() {
		$id = $this->four_player_tournament();
		Chess_Army_Knife_Tournaments::start( $id );
		$game = Chess_Army_Knife_Tournament_Store::get_games( $id )[0];

		$request = new WP_REST_Request( 'POST', '/chess-army-knife/v1/games/' . $game['id'] . '/result' );
		$request->set_param( 'result', '1-0' );

		// Logged out and subscribers are refused.
		$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		// Administrators can record results.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1-0', $response->get_data()['result'] );

		$bad = new WP_REST_Request( 'POST', '/chess-army-knife/v1/games/' . $game['id'] . '/result' );
		$bad->set_param( 'result', 'nonsense' );
		$this->assertSame( 400, rest_do_request( $bad )->get_status() );

		$missing = new WP_REST_Request( 'POST', '/chess-army-knife/v1/games/999999/result' );
		$missing->set_param( 'result', '1-0' );
		$this->assertSame( 404, rest_do_request( $missing )->get_status() );
	}

	public function test_games_block_lets_only_managers_enter_scores() {
		$id = $this->four_player_tournament();
		Chess_Army_Knife_Tournaments::start( $id );
		$block = '<!-- wp:chess-army-knife/tournament-games {"tournamentId":' . $id . '} /-->';

		// Everyone sees the games, but only as text.
		$html = do_blocks( $block );
		$this->assertStringContainsString( 'Club Championship', $html );
		$this->assertStringContainsString( 'Round 1', $html );
		$this->assertStringNotContainsString( '<select', $html );
		$this->assertStringNotContainsString( 'cak-games__save', $html );
		$this->assertStringNotContainsString( 'data-nonce', $html );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertStringNotContainsString( '<select', do_blocks( $block ) );

		// Administrators get two score selectors per game and one Save button.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = do_blocks( $block );
		$this->assertSame( 12, substr_count( $html, 'data-side=' ) ); // Six games, two selectors each.
		$this->assertSame( 1, substr_count( $html, 'class="cak-games__save' ) );
		$this->assertStringContainsString( 'data-nonce', $html );
		$this->assertStringContainsString( '/games/results', $html );
	}

	public function test_games_block_explains_unstarted_and_unconfigured_states() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertStringContainsString( 'choose a tournament', do_blocks( '<!-- wp:chess-army-knife/tournament-games /-->' ) );

		$id   = $this->create_tournament();
		$html = do_blocks( '<!-- wp:chess-army-knife/tournament-games {"tournamentId":' . $id . '} /-->' );
		$this->assertStringContainsString( 'not started', $html );
		$this->assertStringNotContainsString( 'cak-games__save', $html );
	}

	public function test_several_results_can_be_saved_in_one_request() {
		$id = $this->four_player_tournament();
		Chess_Army_Knife_Tournaments::start( $id );
		$games = Chess_Army_Knife_Tournaments::games_to_play( $id );

		$request = new WP_REST_Request( 'POST', '/chess-army-knife/v1/games/results' );
		$request->set_param(
			'results',
			array(
				$games[0]['id'] => '1-0',
				$games[1]['id'] => '1/2-1/2',
				$games[2]['id'] => 'nonsense',
				999999          => '1-0',
			)
		);

		// Logged out and subscribers are refused.
		$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing( array( $games[0]['id'], $games[1]['id'] ), $data['saved'] );
		$this->assertCount( 2, (array) $data['errors'] ); // The bad result and the missing game.
		$this->assertArrayHasKey( $games[2]['id'], (array) $data['errors'] );
		$this->assertCount( 4, Chess_Army_Knife_Tournaments::games_to_play( $id ) );
		$this->assertSame( '1-0', Chess_Army_Knife_Tournament_Store::get_game( $games[0]['id'] )['result'] );

		$empty = new WP_REST_Request( 'POST', '/chess-army-knife/v1/games/results' );
		$empty->set_param( 'results', array() );
		$this->assertSame( 400, rest_do_request( $empty )->get_status() );
	}

	public function test_deleting_a_tournament_removes_its_entries_and_games() {
		$id = $this->four_player_tournament();
		Chess_Army_Knife_Tournaments::start( $id );

		Chess_Army_Knife_Tournament_Store::delete_tournament( $id );

		$this->assertNull( Chess_Army_Knife_Tournament_Store::get_tournament( $id ) );
		$this->assertSame( array(), Chess_Army_Knife_Tournament_Store::get_entries( $id ) );
		$this->assertSame( array(), Chess_Army_Knife_Tournament_Store::get_games( $id ) );
	}
}
