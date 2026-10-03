<?php
/**
 * Integration tests: club officers, their history, team captains and the block.
 *
 * @package Chess_Army_Knife
 */

class OfficersTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		delete_option( Chess_Army_Knife_Officers::OPTION );
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Officers::table(), Chess_Army_Knife_Teams::squad_table() ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Member_History::install_table();
		Chess_Army_Knife_Mailer::install_table();
		Chess_Army_Knife_Notification_Preferences::install_table();
		Chess_Army_Knife_Teams::install_table();
		Chess_Army_Knife_Officers::install_table();
	}

	private function person( $name ) {
		return Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'   => $name,
				'email'  => strtolower( strtok( $name, ' ' ) ) . '@example.test',
				'status' => 'active',
			)
		);
	}

	private function team( $name = 'Club A', array $meta = array() ) {
		return self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
				'meta_input'  => $meta,
			)
		);
	}

	/**
	 * Save a list of position names and return their ids, by name.
	 */
	private function positions( array $names ) {
		Chess_Army_Knife_Officers::save_positions(
			array_map(
				function ( $name ) {
					return array( 'name' => $name );
				},
				$names
			)
		);
		return wp_list_pluck( Chess_Army_Knife_Officers::positions(), 'id', 'name' );
	}

	public function test_positions_keep_their_order() {
		$this->positions( array( 'Chairman', 'Secretary', 'Treasurer' ) );

		$this->assertSame( array( 'Chairman', 'Secretary', 'Treasurer' ), wp_list_pluck( Chess_Army_Knife_Officers::positions(), 'name' ) );
	}

	public function test_a_member_can_hold_a_position_and_is_listed_by_the_block() {
		$ids = $this->positions( array( 'Chairman' ) );
		$ada = $this->person( 'Ada Lovelace' );

		$this->assertGreaterThan( 0, Chess_Army_Knife_Officers::assign( $ids['Chairman'], $ada, '2024-03-12' ) );
		$html = do_blocks( '<!-- wp:chess-army-knife/officers /-->' );

		$this->assertStringContainsString( 'Chairman', $html );
		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringNotContainsString( 'since', $html, 'Tenure is optional.' );
		$this->assertStringContainsString( 'since', do_blocks( '<!-- wp:chess-army-knife/officers {"showTenure":true} /-->' ) );
	}

	public function test_only_people_on_the_clubs_records_can_hold_a_position_and_not_twice() {
		$ids = $this->positions( array( 'Chairman' ) );
		$ada = $this->person( 'Ada Lovelace' );

		$this->assertSame( 0, Chess_Army_Knife_Officers::assign( $ids['Chairman'], 99999 ) );
		$this->assertSame( 0, Chess_Army_Knife_Officers::assign( 'nope', $ada ) );
		$this->assertGreaterThan( 0, Chess_Army_Knife_Officers::assign( $ids['Chairman'], $ada ) );
		$this->assertSame( 0, Chess_Army_Knife_Officers::assign( $ids['Chairman'], $ada ), 'Already holds it.' );
	}

	public function test_standing_down_keeps_the_history_but_leaves_the_block() {
		$ids  = $this->positions( array( 'Chairman' ) );
		$ada  = $this->person( 'Ada Lovelace' );
		$term = Chess_Army_Knife_Officers::assign( $ids['Chairman'], $ada, '2020-01-01' );

		$this->assertTrue( Chess_Army_Knife_Officers::end_term( $term, '2023-06-30' ) );
		$this->assertFalse( Chess_Army_Knife_Officers::end_term( $term ), 'Already ended.' );

		$terms = Chess_Army_Knife_Officers::terms();
		$this->assertCount( 1, $terms );
		$this->assertSame( '2023-06-30', $terms[0]['end_date'] );
		$this->assertStringNotContainsString( 'Ada Lovelace', do_blocks( '<!-- wp:chess-army-knife/officers /-->' ) );
	}

	public function test_a_term_never_ends_before_it_began() {
		$ids  = $this->positions( array( 'Chairman' ) );
		$term = Chess_Army_Knife_Officers::assign( $ids['Chairman'], $this->person( 'Ada Lovelace' ), '2024-05-01' );

		Chess_Army_Knife_Officers::end_term( $term, '2020-01-01' );

		$this->assertSame( '2024-05-01', Chess_Army_Knife_Officers::terms()[0]['end_date'] );
	}

	public function test_a_position_can_have_several_holders() {
		$ids = $this->positions( array( 'Committee member' ) );
		Chess_Army_Knife_Officers::assign( $ids['Committee member'], $this->person( 'Ada Lovelace' ) );
		Chess_Army_Knife_Officers::assign( $ids['Committee member'], $this->person( 'Alan Turing' ) );

		$html = do_blocks( '<!-- wp:chess-army-knife/officers /-->' );

		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringContainsString( 'Alan Turing', $html );
	}

	public function test_renaming_a_position_renames_it_in_the_history_and_removing_it_ends_its_terms() {
		$ids = $this->positions( array( 'Chairman', 'Secretary' ) );
		Chess_Army_Knife_Officers::assign( $ids['Chairman'], $this->person( 'Ada Lovelace' ) );
		Chess_Army_Knife_Officers::assign( $ids['Secretary'], $this->person( 'Alan Turing' ) );

		Chess_Army_Knife_Officers::save_positions(
			array(
				array(
					'id'   => $ids['Chairman'],
					'name' => 'Chair',
				),
			)
		);

		$by_person = wp_list_pluck( Chess_Army_Knife_Officers::terms(), 'position_name', 'person_id' );
		$this->assertContains( 'Chair', $by_person );
		$this->assertContains( 'Secretary', $by_person, 'A removed position stays in the history under its name.' );
		$this->assertCount( 1, Chess_Army_Knife_Officers::terms( true ), 'Only the chair is still current.' );
	}

	public function test_the_block_follows_its_own_order() {
		$ids = $this->positions( array( 'Chairman', 'Secretary' ) );
		Chess_Army_Knife_Officers::assign( $ids['Chairman'], $this->person( 'Ada Lovelace' ) );
		Chess_Army_Knife_Officers::assign( $ids['Secretary'], $this->person( 'Alan Turing' ) );

		$default = do_blocks( '<!-- wp:chess-army-knife/officers /-->' );
		$custom  = do_blocks( '<!-- wp:chess-army-knife/officers {"order":["' . $ids['Secretary'] . '"]} /-->' );

		$this->assertLessThan( strpos( $default, 'Secretary' ), strpos( $default, 'Chairman' ) );
		$this->assertLessThan( strpos( $custom, 'Chairman' ), strpos( $custom, 'Secretary' ) );
	}

	public function test_team_captains_are_officers_and_enter_the_history() {
		$ada  = $this->person( 'Ada Lovelace' );
		$alan = $this->person( 'Alan Turing' );
		$team = $this->team( 'Club A', array( Chess_Army_Knife_Teams::META_CAPTAIN => $ada ) );

		$html = do_blocks( '<!-- wp:chess-army-knife/officers {"includeCaptains":true} /-->' );
		$this->assertStringContainsString( 'Captain, Club A', $html );
		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringNotContainsString( 'Ada Lovelace', do_blocks( '<!-- wp:chess-army-knife/officers /-->' ), 'Captains are not named unless the block is told to.' );

		// A new captain: the old captaincy ends and a new one begins.
		update_post_meta( $team, Chess_Army_Knife_Teams::META_CAPTAIN, $alan );
		Chess_Army_Knife_Officers::sync_captains();
		Chess_Army_Knife_Officers::sync_captains();

		$terms = Chess_Army_Knife_Officers::terms();
		$this->assertCount( 2, $terms, 'Syncing twice adds nothing.' );
		$this->assertCount( 1, Chess_Army_Knife_Officers::terms( true ) );
		$this->assertSame( $alan, Chess_Army_Knife_Officers::terms( true )[0]['person_id'] );
	}

	public function test_a_captaincy_ends_when_the_team_is_trashed_and_the_captain_is_renamed_with_the_team() {
		$team = $this->team( 'Club A', array( Chess_Army_Knife_Teams::META_CAPTAIN => $this->person( 'Ada Lovelace' ) ) );
		Chess_Army_Knife_Officers::sync_captains();

		wp_update_post(
			array(
				'ID'         => $team,
				'post_title' => 'Club B',
			)
		);
		Chess_Army_Knife_Officers::sync_captains();
		$this->assertSame( 'Club B', Chess_Army_Knife_Officers::terms()[0]['position_name'] );

		wp_trash_post( $team );
		Chess_Army_Knife_Officers::sync_captains();
		$this->assertCount( 0, Chess_Army_Knife_Officers::terms( true ) );
		$this->assertCount( 1, Chess_Army_Knife_Officers::terms() );
	}

	public function test_a_team_without_a_captain_still_has_its_history_renamed() {
		$ada  = $this->person( 'Ada Lovelace' );
		$team = $this->team( 'Club A', array( Chess_Army_Knife_Teams::META_CAPTAIN => $ada ) );
		Chess_Army_Knife_Officers::sync_captains();
		update_post_meta( $team, Chess_Army_Knife_Teams::META_CAPTAIN, 0 );
		Chess_Army_Knife_Officers::sync_captains();
		wp_update_post(
			array(
				'ID'         => $team,
				'post_title' => 'Club B',
			)
		);
		Chess_Army_Knife_Officers::sync_captains();

		$this->assertSame( 'Club B', Chess_Army_Knife_Officers::terms()[0]['position_name'] );
	}

	public function test_dates_in_the_future_or_not_real_are_refused() {
		$ids    = $this->positions( array( 'Chairman' ) );
		$ada    = $this->person( 'Ada Lovelace' );
		$future = gmdate( 'Y-m-d', time() + 3 * DAY_IN_SECONDS );

		$this->assertSame( 0, Chess_Army_Knife_Officers::assign( $ids['Chairman'], $ada, $future ) );
		$this->assertSame( 0, Chess_Army_Knife_Officers::assign( $ids['Chairman'], $ada, '2025-02-30' ) );

		$term = Chess_Army_Knife_Officers::assign( $ids['Chairman'], $ada, '2020-01-01' );
		$this->assertGreaterThan( 0, $term );
		$this->assertFalse( Chess_Army_Knife_Officers::end_term( $term, $future ) );
		$this->assertFalse( Chess_Army_Knife_Officers::end_term( $term, 'next week' ) );
		$this->assertCount( 1, Chess_Army_Knife_Officers::terms( true ) );
		$this->assertTrue( Chess_Army_Knife_Officers::end_term( $term, '' ), 'No date means today.' );
	}

	public function test_a_captaincy_cannot_be_ended_as_an_officer_term() {
		$this->team( 'Club A', array( Chess_Army_Knife_Teams::META_CAPTAIN => $this->person( 'Ada Lovelace' ) ) );
		Chess_Army_Knife_Officers::sync_captains();

		$this->assertFalse( Chess_Army_Knife_Officers::end_term( Chess_Army_Knife_Officers::terms()[0]['id'] ) );
	}

	public function test_deleting_a_team_ends_its_captaincy() {
		$team = $this->team( 'Club A', array( Chess_Army_Knife_Teams::META_CAPTAIN => $this->person( 'Ada Lovelace' ) ) );
		Chess_Army_Knife_Officers::sync_captains();

		wp_delete_post( $team, true );

		$this->assertCount( 0, Chess_Army_Knife_Officers::terms( true ) );
		$this->assertCount( 1, Chess_Army_Knife_Officers::terms() );
	}

	public function test_erasing_a_person_removes_them_from_the_officers_and_history() {
		$ids = $this->positions( array( 'Chairman' ) );
		$ada = $this->person( 'Ada Lovelace' );
		Chess_Army_Knife_Officers::assign( $ids['Chairman'], $ada );

		Chess_Army_Knife_Membership_Store::erase_member( $ada );

		$this->assertSame( array(), Chess_Army_Knife_Officers::terms() );
		$this->assertStringNotContainsString( 'Ada Lovelace', do_blocks( '<!-- wp:chess-army-knife/officers /-->' ) );
	}

	public function test_the_block_says_so_when_there_are_no_officers() {
		$this->assertStringContainsString( 'No officers to show yet.', do_blocks( '<!-- wp:chess-army-knife/officers /-->' ) );
	}

	public function test_the_screen_needs_the_membership_permission() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		ob_start();
		Chess_Army_Knife_Officers_Page::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'You do not have permission', $html );
	}

	public function test_the_screen_lists_positions_and_history_for_those_who_may() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );
		$ids = $this->positions( array( 'Chairman' ) );
		Chess_Army_Knife_Officers::assign( $ids['Chairman'], $this->person( 'Ada Lovelace' ) );

		ob_start();
		Chess_Army_Knife_Officers_Page::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Chairman', $html );
		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringContainsString( 'History', $html );
	}

	public function test_the_editor_is_offered_the_items_without_any_names() {
		$ids = $this->positions( array( 'Chairman' ) );
		Chess_Army_Knife_Officers::assign( $ids['Chairman'], $this->person( 'Ada Lovelace' ) );
		$this->team( 'Club A' );

		$items = Chess_Army_Knife_Officers::items();

		$this->assertSame( array( 'position', 'captain' ), wp_list_pluck( $items, 'kind' ) );
		$this->assertStringNotContainsString( 'Ada', wp_json_encode( $items ) );
	}
}
