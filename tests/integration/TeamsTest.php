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
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Mailer::table(), Chess_Army_Knife_Notification_Preferences::table(), Chess_Army_Knife_Teams::squad_table() ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Member_History::install_table();
		Chess_Army_Knife_Mailer::install_table();
		Chess_Army_Knife_Notification_Preferences::install_table();
		Chess_Army_Knife_Teams::install_table();
		Chess_Army_Knife_Officers::install_table();
	}

	/**
	 * The league entries of a club with two teams, Club A playing in two leagues.
	 */
	private function entries() {
		return array(
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
		);
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

	public function test_league_entries_go_to_the_team_of_that_name_and_teams_are_created_once() {
		$this->assertSame( 2, Chess_Army_Knife_Teams::assign_league_entries( $this->entries() ) );
		$this->assertSame( 0, Chess_Army_Knife_Teams::assign_league_entries( $this->entries() ), 'A second run creates nothing and adds no repeats.' );

		$by = array_column( Chess_Army_Knife_Teams::all(), null, 'name' );

		$this->assertSame( array( 'Club A', 'Club B' ), array_keys( $by ) );
		$this->assertCount( 2, Chess_Army_Knife_Teams::seasons_of( $by['Club A'] ) );
		$this->assertCount( 1, Chess_Army_Knife_Teams::seasons_of( $by['Club B'] ) );
		$this->assertCount( 3, Chess_Army_Knife_Settings::get_club_teams() );
	}

	public function test_the_old_club_teams_list_moves_onto_the_teams_once() {
		// Club A was ticked for a league the LMS knows it as "Gloucester A" in.
		$a = $this->team( 'Club A', array( Chess_Army_Knife_Teams::META_SEASONS => array( '613|Division 2|Gloucester A' ) ) );
		update_option(
			Chess_Army_Knife_Teams::LEGACY_OPTION,
			array_merge(
				$this->entries(),
				array(
					array(
						'org'   => '613',
						'event' => 'Division 2',
						'team'  => 'Gloucester A',
					),
				)
			)
		);
		delete_option( Chess_Army_Knife_Teams::MIGRATED_OPTION );

		Chess_Army_Knife_Teams::maybe_migrate();

		$by = array_column( Chess_Army_Knife_Teams::all(), null, 'name' );
		$this->assertSame( array( 'Club A', 'Club B' ), array_keys( $by ), 'Club B is created; Gloucester A is not, because Club A was linked to it.' );
		$this->assertSame( $a, $by['Club A']['id'] );
		$this->assertContains( '613|Division 2|Gloucester A', $by['Club A']['seasons'] );
		$this->assertContains( '613|Division 1|Club A', $by['Club A']['seasons'] );
		$this->assertContains( '613|Cup|Club A', $by['Club A']['seasons'] );
		$this->assertSame( array( '613|Division 2|Club B' ), $by['Club B']['seasons'] );
		$this->assertFalse( get_option( Chess_Army_Knife_Teams::LEGACY_OPTION, false ), 'The old list is gone.' );
		$this->assertSame( '', get_post_meta( $a, Chess_Army_Knife_Teams::META_SEASONS, true ) );

		Chess_Army_Knife_Teams::maybe_migrate();
		$this->assertCount( 4, Chess_Army_Knife_Settings::get_club_teams(), 'Running it again changes nothing.' );
	}

	public function test_renaming_a_team_keeps_its_squad() {
		$team = $this->team( 'Club A' );
		$ada  = $this->person( 'Ada Lovelace' );
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );

		wp_update_post(
			array(
				'ID'         => $team,
				'post_title' => 'Club A (Division 2)',
			)
		);

		$this->assertSame( array( $ada ), Chess_Army_Knife_Teams::squad( $team ) );
		$this->assertSame( 'Club A (Division 2)', Chess_Army_Knife_Teams::teams_of_person( $ada )[0]['name'] );
	}

	public function test_the_block_shows_team_details_but_never_the_captain_or_squad() {
		$ada  = $this->person( 'Ada Lovelace' );
		$team = $this->team( 'Club A' );
		update_post_meta( $team, Chess_Army_Knife_Teams::META_CAPTAIN, $ada );
		update_post_meta(
			$team,
			Chess_Army_Knife_Teams::META_LEAGUES,
			array(
				array(
					'org'   => '613',
					'event' => 'Division 1',
					'name'  => '',
				),
			)
		);
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );

		$html = do_blocks( '<!-- wp:chess-army-knife/team-profiles /-->' );

		$this->assertStringContainsString( 'Club A', $html );
		$this->assertStringContainsString( 'Our first team.', $html );
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

	private function group( $name = 'NGCA', $excerpt = 'The league.' ) {
		return self::factory()->post->create(
			array(
				'post_type'    => Chess_Army_Knife_Team_Groups::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_excerpt' => $excerpt,
			)
		);
	}

	public function test_a_group_chooses_its_teams_and_their_order() {
		$a     = $this->team( 'Club A' );
		$b     = $this->team( 'Club B' );
		$c     = $this->team( 'Club C' );
		$group = $this->group();
		$other = $this->group( 'Internal' );

		Chess_Army_Knife_Team_Groups::set_teams( $other, array( $c ) );
		Chess_Army_Knife_Team_Groups::set_teams( $group, array( $b, $c, 99999 ) );

		$this->assertSame( array( $b, $c ), wp_list_pluck( Chess_Army_Knife_Team_Groups::teams_of( $group ), 'id' ) );
		$this->assertSame( array(), Chess_Army_Knife_Team_Groups::teams_of( $other ), 'A team is in one group only.' );
		$this->assertSame( 'NGCA', Chess_Army_Knife_Teams::get( $c )['group'] );
		$this->assertSame( 0, Chess_Army_Knife_Teams::get( $a )['group_id'] );

		// Leaving the list takes a team out of the group.
		Chess_Army_Knife_Team_Groups::set_teams( $group, array( $c ) );
		$this->assertSame( 0, Chess_Army_Knife_Teams::get( $b )['group_id'] );
	}

	public function test_saving_a_group_needs_its_nonce_and_keeps_the_order_posted() {
		$a     = $this->team( 'Club A' );
		$b     = $this->team( 'Club B' );
		$group = $this->group();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wp_get_current_user()->add_cap( Chess_Army_Knife_Teams::CAPABILITY );

		$_POST = array( 'chess_army_group_teams' => array( (string) $a ) );
		Chess_Army_Knife_Team_Groups_Admin::save( $group );
		$this->assertSame( array(), Chess_Army_Knife_Team_Groups::teams_of( $group ), 'Without the nonce nothing is saved.' );

		$_POST = array(
			Chess_Army_Knife_Team_Groups_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Team_Groups_Admin::NONCE_ACTION ),
			'chess_army_group_teams' => array( (string) $b, (string) $a ),
		);
		Chess_Army_Knife_Team_Groups_Admin::save( $group );
		$_POST = array();

		$this->assertSame( array( $b, $a ), wp_list_pluck( Chess_Army_Knife_Team_Groups::teams_of( $group ), 'id' ) );
	}

	public function test_the_block_groups_teams_gives_hero_teams_a_row_and_can_drop_its_title() {
		$a     = $this->team( 'Club A' );
		$b     = $this->team( 'Club B' );
		$group = $this->group( 'North Gloucestershire Chess Association', 'About the league.' );
		Chess_Army_Knife_Team_Groups::set_teams( $group, array( $b, $a ) );

		$html = do_blocks( '<!-- wp:chess-army-knife/team-profiles {"groupBy":"group","showTitle":false,"heroTeams":[' . $b . ']} /-->' );

		$this->assertStringContainsString( 'North Gloucestershire Chess Association', $html );
		$this->assertStringContainsString( 'About the league.', $html );
		$this->assertSame( 1, substr_count( $html, 'cak-team--hero' ), 'Only the chosen team is a hero.' );
		$this->assertStringNotContainsString( 'cak-block-title', $html, 'The block title is off.' );
		$this->assertLessThan( strpos( $html, 'Club A' ), strpos( $html, 'Club B' ), 'The group puts Club B first.' );
	}

	public function test_the_block_can_hide_team_descriptions() {
		$this->team( 'Club A' );

		$html = do_blocks( '<!-- wp:chess-army-knife/team-profiles {"showDescription":false} /-->' );

		$this->assertStringContainsString( 'Club A', $html );
		$this->assertStringNotContainsString( 'Our first team.', $html );
	}

	public function test_saving_a_team_keeps_only_valid_choices_and_needs_permission() {
		$team = $this->team();
		$ada  = $this->person();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );

		$_POST = array(
			Chess_Army_Knife_Teams_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Teams_Admin::NONCE_ACTION ),
			'chess_army_team_tag'                     => 'Lions',
			'chess_army_team_captain_member'          => (string) $ada,
			'chess_army_team_squad'                   => array( (string) $ada, '99999' ),
		);
		Chess_Army_Knife_Teams_Admin::save( $team );
		$_POST = array();

		$data = Chess_Army_Knife_Teams::get( $team );
		$this->assertSame( 'Lions', $data['tag'] );
		$this->assertSame( $ada, $data['captain_id'] );
		$this->assertSame( array( $ada ), Chess_Army_Knife_Teams::squad( $team ) );

		// Without the membership permission nothing changes.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = array(
			Chess_Army_Knife_Teams_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Teams_Admin::NONCE_ACTION ),
			'chess_army_team_tag'                     => 'Elsewhere',
		);
		Chess_Army_Knife_Teams_Admin::save( $team );
		$_POST = array();
		$this->assertSame( 'Lions', Chess_Army_Knife_Teams::get( $team )['tag'] );
	}

	public function test_the_captain_is_ticked_in_the_squad_or_chosen_apart_from_it() {
		$ada  = $this->person( 'Ada Lovelace' );
		$bea  = $this->person( 'Bea Babbage' );
		$team = $this->team();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );
		$nonce = Chess_Army_Knife_Teams_Admin::NONCE_FIELD;

		// Ticked in the squad: a captain who plays. Someone outside the squad cannot be ticked.
		$_POST = array(
			$nonce                           => wp_create_nonce( Chess_Army_Knife_Teams_Admin::NONCE_ACTION ),
			'chess_army_team_squad'          => array( (string) $ada ),
			'chess_army_team_captain_member' => (string) $bea,
		);
		Chess_Army_Knife_Teams_Admin::save( $team );
		$this->assertSame( 0, Chess_Army_Knife_Teams::get( $team )['captain_id'] );

		$_POST['chess_army_team_captain_member'] = (string) $ada;
		Chess_Army_Knife_Teams_Admin::save( $team );
		$this->assertSame( $ada, Chess_Army_Knife_Teams::get( $team )['captain_id'] );

		// A captain who does not play: any member, chosen apart from the squad.
		$_POST = array(
			$nonce                                => wp_create_nonce( Chess_Army_Knife_Teams_Admin::NONCE_ACTION ),
			'chess_army_team_squad'               => array( (string) $ada ),
			'chess_army_team_captain_non_playing' => '1',
			'chess_army_team_captain_other'       => (string) $bea,
			'chess_army_team_captain_member'      => (string) $ada,
		);
		Chess_Army_Knife_Teams_Admin::save( $team );
		$data = Chess_Army_Knife_Teams::get( $team );
		$this->assertSame( $bea, $data['captain_id'], 'The ticked member is ignored while the captain does not play.' );
		$this->assertSame( '', $data['captain_name'] );

		// Or someone who is not a member, by name.
		$_POST['chess_army_team_captain_other'] = '-1';
		$_POST['chess_army_team_captain_name']  = 'Pat Parent';
		Chess_Army_Knife_Teams_Admin::save( $team );
		$_POST = array();
		$data  = Chess_Army_Knife_Teams::get( $team );
		$this->assertSame( 0, $data['captain_id'] );
		$this->assertSame( 'Pat Parent', $data['captain_name'] );

		$html = do_blocks( '<!-- wp:chess-army-knife/team-profiles {"showPlayers":true} /-->' );
		$this->assertStringContainsString( 'Non-playing captain', $html );
		$this->assertStringContainsString( 'Pat Parent', $html );
	}

	public function test_a_team_manager_edits_the_team_but_cannot_choose_the_captain_or_squad() {
		$team = $this->team();
		$ada  = $this->person();
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Teams::CAPABILITY );
		wp_set_current_user( $user );

		$_POST = array(
			Chess_Army_Knife_Teams_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Teams_Admin::NONCE_ACTION ),
			'chess_army_team_tag'                     => 'Lions',
			'chess_army_team_captain_member'          => (string) $ada,
			'chess_army_team_squad'                   => array( (string) $ada ),
		);
		Chess_Army_Knife_Teams_Admin::save( $team );
		$_POST = array();

		$data = Chess_Army_Knife_Teams::get( $team );
		$this->assertSame( 'Lions', $data['tag'] );
		$this->assertSame( 0, $data['captain_id'], 'Choosing people needs the membership permission.' );
		$this->assertSame( array(), Chess_Army_Knife_Teams::squad( $team ) );
	}

	public function test_the_leagues_page_sets_each_teams_entries_by_organisation() {
		$a = $this->team( 'Club A' );
		$b = $this->team( 'Club B' );
		update_post_meta(
			$a,
			Chess_Army_Knife_Teams::META_LEAGUES,
			array(
				array(
					'org'   => '999',
					'event' => 'Cup',
					'name'  => '',
				),
			)
		);

		$done = Chess_Army_Knife_Leagues_Page::save_from(
			array(
				'orgs'    => array( '270' ),
				'leagues' => array(
					'270' => array(
						array(
							'team'  => (string) $a,
							'event' => 'Division 1',
							'name'  => '',
						),
						array(
							'team'  => (string) $b,
							'event' => 'Division 2',
							'name'  => 'Club B Juniors',
						),
						array(
							'team'   => (string) $b,
							'event'  => 'Division 3',
							'remove' => '1',
						),
						array(
							'team'  => '0',
							'event' => 'No team',
						),
					),
				),
			)
		);

		$this->assertSame( 2, $done['changed'] );
		$this->assertSame( array( '999|Cup|Club A', '270|Division 1|Club A' ), Chess_Army_Knife_Teams::get( $a )['seasons'], 'An organisation that was not shown is left alone.' );
		$this->assertSame( array( '270|Division 2|Club B Juniors' ), Chess_Army_Knife_Teams::get( $b )['seasons'] );

		// Emptying an organisation's rows removes the entries there.
		Chess_Army_Knife_Leagues_Page::save_from( array( 'orgs' => array( '270' ) ) );
		$this->assertSame( array( '999|Cup|Club A' ), Chess_Army_Knife_Teams::get( $a )['seasons'] );
		$this->assertSame( array(), Chess_Army_Knife_Teams::get( $b )['seasons'] );
	}

	public function test_officers_manage_teams_but_team_managers_cannot_manage_members() {
		$officer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $officer )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		$manager = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $manager )->add_cap( Chess_Army_Knife_Teams::CAPABILITY );

		$this->assertTrue( user_can( $officer, Chess_Army_Knife_Teams::CAPABILITY ) );
		$this->assertTrue( user_can( $manager, Chess_Army_Knife_Teams::CAPABILITY ) );
		$this->assertFalse( user_can( $manager, Chess_Army_Knife_Memberships::CAPABILITY ), 'The team permission shows nobody\'s details.' );
	}

	public function test_a_team_manager_picks_every_team_but_a_captain_only_their_own() {
		$a = $this->team( 'Club A' );
		$b = $this->team( 'Club B' );

		$manager = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $manager )->add_cap( Chess_Army_Knife_Teams::CAPABILITY );
		$captain = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Chess_Army_Knife_Captains::set_user( $a, $captain );

		$this->assertEqualSets( array( $a, $b ), wp_list_pluck( Chess_Army_Knife_Captains::teams_for_user( $manager ), 'id' ) );
		$this->assertSame( array( $a ), wp_list_pluck( Chess_Army_Knife_Captains::teams_for_user( $captain ), 'id' ) );
	}

	public function test_the_squad_remembers_when_each_person_last_played_and_only_moves_forward() {
		$team = Chess_Army_Knife_Teams::get( $this->team() );
		$ada  = $this->person( 'Ada Lovelace' );
		$bea  = $this->person( 'Bea Babbage' );
		Chess_Army_Knife_Teams::add_to_squad( $team['id'], array( $ada, $bea ) );

		Chess_Army_Knife_Teams::record_appearances( $team['id'], array( $ada => '2026-10-05' ) );
		Chess_Army_Knife_Teams::record_appearances(
			$team['id'],
			array(
				$ada => '2026-09-01',
				$bea => 'not a date',
			)
		);

		$rows = array_column( Chess_Army_Knife_Teams::squad_rows( $team['id'] ), null, 'person_id' );
		$this->assertSame( '2026-10-05', $rows[ $ada ]['last_played'], 'An earlier date does not move it back.' );
		$this->assertSame( '', $rows[ $bea ]['last_played'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $rows[ $bea ]['added'] );
	}

	public function test_setting_a_squad_keeps_what_is_known_about_those_who_stay() {
		$team = Chess_Army_Knife_Teams::get( $this->team() );
		$ada  = $this->person( 'Ada Lovelace' );
		$bea  = $this->person( 'Bea Babbage' );
		$cy   = $this->person( 'Cy Turing' );
		Chess_Army_Knife_Teams::add_to_squad( $team['id'], array( $ada, $bea ) );
		Chess_Army_Knife_Teams::record_appearances( $team['id'], array( $ada => '2026-10-05' ) );

		Chess_Army_Knife_Teams::set_squad( $team['id'], array( $ada, $cy ) );

		$rows = array_column( Chess_Army_Knife_Teams::squad_rows( $team['id'] ), null, 'person_id' );
		$this->assertSame( array( $ada, $cy ), array_keys( $rows ) );
		$this->assertSame( '2026-10-05', $rows[ $ada ]['last_played'] );
	}

	public function test_the_review_removes_only_people_who_are_in_that_squad() {
		$lions  = Chess_Army_Knife_Teams::get( $this->team( 'Lions' ) );
		$tigers = Chess_Army_Knife_Teams::get( $this->team( 'Tigers' ) );
		$ada    = $this->person( 'Ada Lovelace' );
		$bea    = $this->person( 'Bea Babbage' );
		Chess_Army_Knife_Teams::add_to_squad( $lions['id'], array( $ada ) );
		Chess_Army_Knife_Teams::add_to_squad( $tigers['id'], array( $bea ) );

		$removed = Chess_Army_Knife_Squad_Review::remove(
			array(
				$lions['id'] => array( $ada, $bea ), // Bea is not in the Lions.
				999999       => array( $bea ),       // Not a team.
			)
		);

		$this->assertSame( 1, $removed );
		$this->assertSame( array(), Chess_Army_Knife_Teams::squad( $lions['id'] ) );
		$this->assertSame( array( $bea ), Chess_Army_Knife_Teams::squad( $tigers['id'] ), 'Her place in the Tigers is untouched.' );
	}

	public function test_a_whatsapp_link_is_shown_only_to_squad_members_who_agreed() {
		$link  = 'https://chat.whatsapp.com/AbCdEfGhIjKlMn12';
		$lions = Chess_Army_Knife_Teams::get( $this->team( 'Lions', array( Chess_Army_Knife_Teams::META_WHATSAPP => $link ) ) );
		$other = Chess_Army_Knife_Teams::get( $this->team( 'Tigers', array( Chess_Army_Knife_Teams::META_WHATSAPP => $link ) ) );
		$this->team( 'No link' );

		$agreed = $this->person( 'Ada Lovelace', array( 'whatsapp_consent_at' => '2026-01-01 10:00:00' ) );
		$silent = $this->person( 'Bea Babbage' );
		Chess_Army_Knife_Teams::add_to_squad( $lions['id'], array( $agreed, $silent ) );
		$this->assertNotNull( $other );

		$ada = Chess_Army_Knife_Membership_Store::get_member( $agreed );
		$bea = Chess_Army_Knife_Membership_Store::get_member( $silent );

		$this->assertSame(
			array(
				array(
					'name' => 'Lions',
					'link' => $link,
				),
			),
			Chess_Army_Knife_Teams::whatsapp_groups_for_person( $ada ),
			'Only her own team, not the Tigers.'
		);
		$this->assertSame( array(), Chess_Army_Knife_Teams::whatsapp_groups_for_person( $bea ), 'She did not agree to WhatsApp.' );
	}

	public function test_a_team_without_a_link_gives_nothing_even_to_someone_who_agreed() {
		$team   = Chess_Army_Knife_Teams::get( $this->team( 'No link' ) );
		$agreed = $this->person( 'Ada Lovelace', array( 'whatsapp_consent_at' => '2026-01-01 10:00:00' ) );
		Chess_Army_Knife_Teams::add_to_squad( $team['id'], array( $agreed ) );

		$this->assertSame( array(), Chess_Army_Knife_Teams::whatsapp_groups_for_person( Chess_Army_Knife_Membership_Store::get_member( $agreed ) ) );
	}
}
