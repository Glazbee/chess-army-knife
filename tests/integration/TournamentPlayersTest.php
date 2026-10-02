<?php
/**
 * Integration tests: choosing players in bulk, and a tournament's own page and players list.
 *
 * @package Chess_Army_Knife
 */

class TournamentPlayersTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( 'games', 'entries', 'tournaments', 'members' ) as $name ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Tournament_Store::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Tournament_Store::install_tables();
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Membership_Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Member_History::install_table();
	}

	private function tournament() {
		$id = Chess_Army_Knife_Tournaments::create( array( 'name' => "Blitz Night's Cup" ) );
		$this->assertIsInt( $id );
		return $id;
	}

	private function saved_player( $name, $code = '', $rating = null ) {
		return Chess_Army_Knife_Membership_Store::add_guest(
			array(
				'name'          => $name,
				'ecf_code'      => $code,
				'manual_rating' => $rating,
			)
		);
	}

	public function test_a_new_tournament_player_who_is_not_a_member_gets_a_record_marked_as_such() {
		$outcome = Chess_Army_Knife_Player_Selector::enter_players(
			$this->tournament(),
			array(
				'new_players' => array(
					array(
						'name'     => 'Guest Player',
						'ecf_code' => '555555k',
					),
					array( 'name' => 'Nocode Guest' ),
				),
			)
		);

		$this->assertSame( 2, $outcome['added'] );
		$guests = Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'nonmember' ) );
		$this->assertSame( array( 'Guest Player', 'Nocode Guest' ), wp_list_pluck( $guests, 'name' ) );
		$this->assertSame( '555555K', $guests[0]['ecf_code'] );
		$this->assertSame( 'manual', $guests[0]['source'] );
		$this->assertSame( array(), Chess_Army_Knife_Membership_Store::get_members(), 'Guests are not in the member list.' );
	}

	public function test_a_tournament_player_who_is_already_a_member_does_not_get_a_second_record() {
		$member = Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'     => 'Alice Member',
				'ecf_code' => '120787J',
				'status'   => 'active',
			)
		);

		Chess_Army_Knife_Player_Selector::enter_players(
			$this->tournament(),
			array(
				'new_players' => array(
					array(
						'name'     => 'Alice Member',
						'ecf_code' => '120787J',
					),
				),
			)
		);

		$this->assertSame( array( $member ), wp_list_pluck( Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'people' ) ), 'id' ) );
		$this->assertSame( 'active', Chess_Army_Knife_Membership_Store::get_member( $member )['status'], 'A member stays a member.' );
	}

	public function test_a_person_can_be_found_by_their_ecf_code_with_or_without_the_letter() {
		$id = $this->saved_player( 'Alice', '120787J' );

		$this->assertSame( $id, Chess_Army_Knife_Membership_Store::find_by_ecf_code( '120787J' )['id'] );
		$this->assertSame( $id, Chess_Army_Knife_Membership_Store::find_by_ecf_code( '120787' )['id'], 'The ECF lookups use only the digits.' );
		$this->assertNull( Chess_Army_Knife_Membership_Store::find_by_ecf_code( '999999X' ) );
		$this->assertNull( Chess_Army_Knife_Membership_Store::find_by_ecf_code( '1207870' ), 'A longer code is a different person.' );
		$this->assertNull( Chess_Army_Knife_Membership_Store::find_by_ecf_code( '' ) );
	}

	public function test_several_players_are_entered_at_once_and_new_ones_are_saved() {
		$tournament = $this->tournament();
		$alice      = $this->saved_player( 'Alice', '120787J' );
		$bob        = $this->saved_player( 'Bob', '', 1450 );

		$outcome = Chess_Army_Knife_Player_Selector::enter_players(
			$tournament,
			array(
				'player_ids'  => array( (string) $alice, (string) $bob, (string) $bob ),
				'new_players' => array(
					array(
						'name'     => 'Cy From ECF',
						'ecf_code' => '555555K',
					),
					// Same ECF code as a saved profile: reuse it, never enter Alice twice.
					array(
						'name'     => 'Alice',
						'ecf_code' => '120787J',
					),
					array(
						'name'          => 'Di Manual',
						'manual_rating' => '1500',
					),
				),
			)
		);

		$this->assertSame( 4, $outcome['added'] );
		$this->assertSame( array(), $outcome['errors'] );
		$this->assertTrue( Chess_Army_Knife_Player_Selector::outcome( $outcome ) );
		$this->assertCount( 4, Chess_Army_Knife_Tournament_Store::get_entries( $tournament ) );

		$cy = Chess_Army_Knife_Membership_Store::find_by_ecf_code( '555555K' );
		$this->assertSame( 'Cy From ECF', $cy['name'] );
		$this->assertCount( 4, Chess_Army_Knife_Membership_Store::get_players() ); // Alice, Bob, Cy and Di: the duplicate Alice reuses her profile.
	}

	public function test_invalid_new_players_are_reported_and_the_rest_are_still_added() {
		$tournament = $this->tournament();

		$outcome = Chess_Army_Knife_Player_Selector::enter_players(
			$tournament,
			array(
				'new_players' => array(
					array(
						'name'          => 'Too Low',
						'manual_rating' => '800',
					),
					array(
						'name'     => 'Fine',
						'ecf_code' => '111111A',
					),
				),
			)
		);

		$this->assertSame( 1, $outcome['added'] );
		$this->assertCount( 1, $outcome['errors'] );
		$this->assertWPError( Chess_Army_Knife_Player_Selector::outcome( $outcome ) );
	}

	public function test_players_cannot_be_added_once_the_tournament_has_started() {
		$tournament = $this->tournament();
		$ids        = array();
		foreach ( array( 'A', 'B', 'C' ) as $name ) {
			$ids[] = $this->saved_player( $name, '', 1500 );
		}
		Chess_Army_Knife_Player_Selector::enter_players( $tournament, array( 'player_ids' => $ids ) );
		Chess_Army_Knife_Tournaments::start( $tournament );

		$outcome = Chess_Army_Knife_Player_Selector::enter_players( $tournament, array( 'player_ids' => array( $this->saved_player( 'Late', '', 1500 ) ) ) );

		$this->assertSame( 0, $outcome['added'] );
		$this->assertNotEmpty( $outcome['errors'] );
	}

	public function test_a_tournament_page_is_created_once_as_a_draft() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$tournament = $this->tournament();

		$page_id = Chess_Army_Knife_Tournaments::create_page( $tournament );
		$this->assertIsInt( $page_id, is_wp_error( $page_id ) ? $page_id->get_error_message() : '' );

		$page = get_post( $page_id );
		$this->assertSame( 'page', $page->post_type );
		$this->assertSame( 'draft', $page->post_status );
		$this->assertSame( "Blitz Night's Cup", $page->post_title );
		$this->assertStringContainsString( 'wp:chess-army-knife/tournament-players', $page->post_content );

		$row = Chess_Army_Knife_Tournament_Store::get_tournament( $tournament );
		$this->assertSame( $page_id, Chess_Army_Knife_Tournaments::get_page( $row )->ID );
		$this->assertWPError( Chess_Army_Knife_Tournaments::create_page( $tournament ) );

		// A trashed page no longer counts, so a new one can be made.
		wp_trash_post( $page_id );
		$this->assertNull( Chess_Army_Knife_Tournaments::get_page( Chess_Army_Knife_Tournament_Store::get_tournament( $tournament ) ) );
		$this->assertIsInt( Chess_Army_Knife_Tournaments::create_page( $tournament ) );
	}

	public function test_creating_a_page_needs_permission() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertWPError( Chess_Army_Knife_Tournaments::create_page( $this->tournament() ) );
		$this->assertWPError( Chess_Army_Knife_Tournaments::create_page( 9999 ) );
	}

	public function test_the_players_block_lists_ratings_before_and_after_the_start() {
		$tournament = $this->tournament();
		Chess_Army_Knife_Player_Selector::enter_players(
			$tournament,
			array(
				'player_ids' => array( $this->saved_player( 'Manual Mo', '', 1450 ), $this->saved_player( 'Coded Cat', '120787J' ), $this->saved_player( 'Unrated Ur' ) ),
			)
		);
		$markup = '<!-- wp:chess-army-knife/tournament-players {"tournamentId":' . $tournament . '} /-->';

		$html = do_blocks( $markup );
		$this->assertStringContainsString( 'Manual Mo', $html );
		$this->assertStringContainsString( '1450 (manual)', $html );
		$this->assertStringContainsString( '120787J', $html );
		$this->assertStringContainsString( 'Set at start', $html );
		$this->assertStringContainsString( 'Unrated', $html );

		// After the start the recorded rating is shown (the ECF lookup fails offline, leaving the player unrated).
		Chess_Army_Knife_Tournaments::start( $tournament );
		$html = do_blocks( $markup );
		$this->assertStringContainsString( '1450 (manual)', $html );
		$this->assertStringNotContainsString( 'Set at start', $html );
	}

	public function test_the_players_block_asks_for_a_tournament() {
		$this->assertStringContainsString( 'choose a tournament', do_blocks( '<!-- wp:chess-army-knife/tournament-players {} /-->' ) );
	}

	public function test_a_name_left_on_a_result_can_be_replaced_but_only_if_no_record_is_behind_it() {
		$tournament = $this->tournament();
		$person     = $this->saved_player( 'Linked Person', '111111A', 1500 );
		Chess_Army_Knife_Tournaments::add_player( $tournament, $person );
		Chess_Army_Knife_Tournaments::add_unlinked_player( $tournament, 'Gone Person', 1400 );

		$by_name = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $tournament ) as $entry ) {
			$by_name[ $entry['name'] ] = $entry;
		}
		$this->assertArrayHasKey( 'Gone Person', $by_name );
		$this->assertSame( 0, $by_name['Gone Person']['player_id'] );

		$linked = Chess_Army_Knife_Tournaments::anonymise_entry( $tournament, $by_name['Linked Person']['id'] );
		$this->assertWPError( $linked );
		$this->assertSame( 'entry_linked', $linked->get_error_code() );

		$this->assertTrue( Chess_Army_Knife_Tournaments::anonymise_entry( $tournament, $by_name['Gone Person']['id'] ) );

		$names = wp_list_pluck( Chess_Army_Knife_Tournament_Store::get_entries( $tournament ), 'name' );
		$this->assertNotContains( 'Gone Person', $names );
		$this->assertContains( 'Linked Person', $names );
		$this->assertContains( 'Anonymous player ' . $by_name['Gone Person']['id'], $names );

		$this->assertWPError( Chess_Army_Knife_Tournaments::anonymise_entry( $tournament, 999999 ) );
	}

	public function test_a_tournament_can_be_put_on_the_calendar_once_with_its_page_attached() {
		$tournament = $this->tournament();
		$this->assertSame( 0, Chess_Army_Knife_Events::for_tournament( $tournament ) );
		$page = Chess_Army_Knife_Tournaments::create_page( $tournament );
		$this->assertIsInt( $page );

		$event = Chess_Army_Knife_Tournaments::create_event( $tournament, '2099-05-04', '10:00' );

		$this->assertIsInt( $event );
		$data = Chess_Army_Knife_Events::details( $event );
		$this->assertSame( "Blitz Night's Cup", $data['title'] );
		$this->assertSame( '2099-05-04 10:00:00', $data['start'] );
		$this->assertSame( $tournament, $data['tournaments'][0]['id'] );
		$this->assertSame( $event, Chess_Army_Knife_Events::for_tournament( $tournament ) );
		$this->assertSame( (int) $page, (int) get_post_meta( $event, Chess_Army_Knife_Events::META_PAGE, true ) );
		$this->assertSame( array( 'Tournament' ), wp_list_pluck( $data['tags'], 'name' ) );

		$again = Chess_Army_Knife_Tournaments::create_event( $tournament, '2099-06-01', '10:00' );
		$this->assertWPError( $again );
		$this->assertSame( 'event_exists', $again->get_error_code() );
	}

	public function test_putting_a_tournament_on_the_calendar_needs_a_real_date_and_tournament() {
		$tournament = $this->tournament();

		$this->assertSame( 'event_date', Chess_Army_Knife_Tournaments::create_event( $tournament, 'next friday', '10:00' )->get_error_code() );
		$this->assertSame( 'event_date', Chess_Army_Knife_Tournaments::create_event( $tournament, '2099-05-04', '25:99' )->get_error_code() );
		$this->assertSame( 'tournament_missing', Chess_Army_Knife_Tournaments::create_event( 999999, '2099-05-04', '10:00' )->get_error_code() );
		$this->assertSame( 0, Chess_Army_Knife_Events::for_tournament( $tournament ), 'Nothing was made.' );
	}
}
