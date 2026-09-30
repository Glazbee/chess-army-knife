<?php
/**
 * Integration tests: teams, squads and captains.
 *
 * @package Chess_Army_Knife
 */

class TeamsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		update_option(
			Chess_Army_Knife_Club_Teams_Page::OPTION,
			array(
				array(
					'org'   => '613',
					'event' => 'Division 1',
					'team'  => 'Club A',
				),
				array(
					'org'   => '613',
					'event' => 'Division 2',
					'team'  => 'Club B',
				),
				array(
					'org'   => '613',
					'event' => 'Cup',
					'team'  => 'Club A',
				),
			)
		);
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Mailer::table(), Chess_Army_Knife_Notification_Preferences::table(), Chess_Army_Knife_Teams::squad_table() ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Mailer::install_table();
		Chess_Army_Knife_Notification_Preferences::install_table();
		Chess_Army_Knife_Teams::install_table();
	}

	private function team( $name = 'Club A', array $meta = array() ) {
		return self::factory()->post->create(
			array(
				'post_type'    => Chess_Army_Knife_Teams::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_excerpt' => 'Our first team.',
				'meta_input'   => $meta,
			)
		);
	}

	private function person( $name = 'Ada Lovelace', array $extra = array() ) {
		return Chess_Army_Knife_Membership_Store::save_member(
			$extra + array(
				'name'   => $name,
				'email'  => strtolower( strtok( $name, ' ' ) ) . '@example.test',
				'status' => 'active',
			)
		);
	}

	public function test_a_squad_holds_people_the_club_has_a_record_of() {
		$team = $this->team();
		$ada  = $this->person();

		Chess_Army_Knife_Teams::set_squad( $team, array( $ada, $ada, 99999 ) );

		$this->assertSame( array( $ada ), Chess_Army_Knife_Teams::squad( $team ) );

		Chess_Army_Knife_Teams::set_squad( $team, array() );
		$this->assertSame( array(), Chess_Army_Knife_Teams::squad( $team ) );
	}

	public function test_a_person_is_found_in_squads_and_as_captain() {
		$a   = $this->team( 'Club A' );
		$b   = $this->team( 'Club B' );
		$ada = $this->person();
		Chess_Army_Knife_Teams::set_squad( $a, array( $ada ) );
		update_post_meta( $b, Chess_Army_Knife_Teams::META_CAPTAIN, $ada );

		$teams = Chess_Army_Knife_Teams::teams_of_person( $ada );

		$this->assertSame( array( 'Club A', 'Club B' ), wp_list_pluck( $teams, 'name' ) );
		$this->assertSame( array( false, true ), wp_list_pluck( $teams, 'captain' ) );
	}

	public function test_erasing_a_person_takes_them_out_of_squads_and_captaincies() {
		$team = $this->team( 'Club A', array() );
		$ada  = $this->person( 'Ada Lovelace', array( 'paid_on' => '2026-01-01' ) ); // Kept, anonymised.
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );
		update_post_meta( $team, Chess_Army_Knife_Teams::META_CAPTAIN, $ada );

		Chess_Army_Knife_Membership_Store::erase_member( $ada );

		$this->assertSame( array(), Chess_Army_Knife_Teams::squad( $team ) );
		$this->assertSame( 0, Chess_Army_Knife_Teams::get( $team )['captain_id'] );
	}

	public function test_the_export_lists_the_teams_a_person_is_in() {
		$team = $this->team( 'Club A' );
		$ada  = $this->person();
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );

		$export = Chess_Army_Knife_Membership_Privacy::export( 'ada@example.test' );

		$this->assertContains( 'chess-army-knife-teams', wp_list_pluck( $export['data'], 'group_id' ) );
		$this->assertStringContainsString( 'Club A', wp_json_encode( $export['data'] ) );
	}

	public function test_deleting_a_team_removes_its_squad() {
		$team = $this->team();
		$ada  = $this->person();
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );

		wp_delete_post( $team, true );

		$this->assertSame( array(), Chess_Army_Knife_Teams::squad( $team ) );
	}

	public function test_teams_are_created_from_the_club_teams_list_once_with_their_seasons() {
		$this->assertSame( 2, Chess_Army_Knife_Teams::create_missing_from_club_teams() );
		$this->assertSame( 0, Chess_Army_Knife_Teams::create_missing_from_club_teams() );

		$teams = Chess_Army_Knife_Teams::all();
		$by    = array_column( $teams, null, 'name' );

		$this->assertSame( array( 'Club A', 'Club B' ), array_keys( $by ) );
		$this->assertCount( 2, Chess_Army_Knife_Teams::seasons_of( $by['Club A'] ) );
		$this->assertCount( 1, Chess_Army_Knife_Teams::seasons_of( $by['Club B'] ) );
	}

	public function test_old_team_names_on_a_record_become_team_ids() {
		$a   = $this->team( 'Club A' );
		$ada = $this->person(
			'Ada Lovelace',
			array(
				'whatsapp_consent_at' => '2026-01-01 10:00:00',
				'whatsapp_teams'      => wp_json_encode( array( 'club a', 'Old Team' ) ),
			)
		);

		$this->assertSame( array( $a, 'Old Team' ), Chess_Army_Knife_Membership_Store::get_member( $ada )['whatsapp_teams'] );
	}

	public function test_renaming_a_team_keeps_members_whatsapp_choice() {
		$team = $this->team( 'Club A' );
		$ada  = $this->person(
			'Ada Lovelace',
			array(
				'whatsapp_consent_at' => '2026-01-01 10:00:00',
				'whatsapp_teams'      => wp_json_encode( array( $team ) ),
			)
		);

		wp_update_post(
			array(
				'ID'         => $team,
				'post_title' => 'Club A (Division 2)',
			)
		);

		$member = Chess_Army_Knife_Membership_Store::get_member( $ada );
		$this->assertSame( array( 'Club A (Division 2)' ), Chess_Army_Knife_Teams::labels( $member['whatsapp_teams'] ) );
	}

	public function test_the_block_shows_team_details_but_never_the_captain_or_squad() {
		$ada  = $this->person( 'Ada Lovelace' );
		$team = $this->team( 'Club A', array( Chess_Army_Knife_Teams::META_VENUE => 'Village Hall' ) );
		update_post_meta( $team, Chess_Army_Knife_Teams::META_CAPTAIN, $ada );
		update_post_meta( $team, Chess_Army_Knife_Teams::META_SEASONS, array( '613|Division 1|Club A' ) );
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );

		$html = do_blocks( '<!-- wp:chess-army-knife/team-profiles /-->' );

		$this->assertStringContainsString( 'Club A', $html );
		$this->assertStringContainsString( 'Our first team.', $html );
		$this->assertStringContainsString( 'Village Hall', $html );
		$this->assertStringContainsString( 'Division 1', $html );
		$this->assertStringNotContainsString( 'Ada', $html );
	}

	public function test_the_block_can_show_one_team() {
		$a = $this->team( 'Club A' );
		$this->team( 'Club B' );

		$html = do_blocks( '<!-- wp:chess-army-knife/team-profiles {"teamId":' . $a . '} /-->' );

		$this->assertStringContainsString( 'Club A', $html );
		$this->assertStringNotContainsString( 'Club B', $html );
	}

	public function test_saving_a_team_keeps_only_valid_choices_and_needs_permission() {
		$team = $this->team();
		$ada  = $this->person();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );

		$_POST = array(
			Chess_Army_Knife_Teams_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Teams_Admin::NONCE_ACTION ),
			'chess_army_team_venue'                   => 'Hall',
			'chess_army_team_captain'                 => (string) $ada,
			'chess_army_team_seasons'                 => array( '613|Division 1|Club A', 'made|up|season' ),
			'chess_army_team_squad'                   => array( (string) $ada, '99999' ),
		);
		Chess_Army_Knife_Teams_Admin::save( $team );
		$_POST = array();

		$data = Chess_Army_Knife_Teams::get( $team );
		$this->assertSame( 'Hall', $data['venue'] );
		$this->assertSame( $ada, $data['captain_id'] );
		$this->assertSame( array( '613|Division 1|Club A' ), $data['seasons'] );
		$this->assertSame( array( $ada ), Chess_Army_Knife_Teams::squad( $team ) );

		// Without the membership permission nothing changes.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = array(
			Chess_Army_Knife_Teams_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Teams_Admin::NONCE_ACTION ),
			'chess_army_team_venue'                   => 'Elsewhere',
		);
		Chess_Army_Knife_Teams_Admin::save( $team );
		$_POST = array();
		$this->assertSame( 'Hall', Chess_Army_Knife_Teams::get( $team )['venue'] );
	}
}
