<?php
/**
 * Tests for a team's league entries and the team permission.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class TeamLeaguesTest extends Chess_Army_Knife_TestCase {

	/** @var array Post meta by post id, then key. */
	protected $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'get_the_title' )->alias(
			function ( $post ) {
				return $post->post_title;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key ) {
				return isset( $this->meta[ $id ][ $key ] ) ? $this->meta[ $id ][ $key ] : '';
			}
		);

		$this->meta = array(
			11 => array(
				Chess_Army_Knife_Teams::META_LEAGUES => array(
					array(
						'org'   => '613',
						'event' => 'Division 1',
						'name'  => '',
					),
					array(
						'org'   => '613',
						'event' => 'Division 2',
						'name'  => 'Gloucester A',
					),
				),
			),
			12 => array(),
		);
		Functions\when( 'get_posts' )->justReturn(
			array(
				(object) array(
					'ID'           => 11,
					'post_title'   => 'Club A',
					'post_excerpt' => '',
				),
				(object) array(
					'ID'           => 12,
					'post_title'   => 'Club B',
					'post_excerpt' => '',
				),
			)
		);
	}

	public function test_anyone_who_manages_members_manages_teams() {
		$caps = Chess_Army_Knife_Teams::officers_manage_teams( array( Chess_Army_Knife_Memberships::CAPABILITY => true ) );

		$this->assertTrue( $caps[ Chess_Army_Knife_Teams::CAPABILITY ] );
	}

	public function test_the_team_permission_does_not_give_the_membership_permission() {
		$caps = Chess_Army_Knife_Teams::officers_manage_teams( array( Chess_Army_Knife_Teams::CAPABILITY => true ) );

		$this->assertArrayNotHasKey( Chess_Army_Knife_Memberships::CAPABILITY, $caps );
	}

	public function test_someone_with_no_permission_gains_none() {
		$this->assertSame( array( 'read' => true ), Chess_Army_Knife_Teams::officers_manage_teams( array( 'read' => true ) ) );
	}

	public function test_a_league_needs_an_organisation_and_an_event() {
		$clean = Chess_Army_Knife_Teams::clean_leagues(
			array(
				array(
					'org'   => '',
					'event' => 'Division 1',
				),
				array(
					'org'   => '613',
					'event' => '',
				),
				array(
					'org'   => 'ORG 613',
					'event' => ' Division 1 ',
					'name'  => '',
				),
				'not a league',
			)
		);

		$this->assertSame(
			array(
				array(
					'org'   => '613',
					'event' => 'Division 1',
					'name'  => '',
				),
			),
			$clean
		);
	}

	public function test_a_repeated_league_is_kept_once() {
		$row = array(
			'org'   => '613',
			'event' => 'Division 1',
			'name'  => '',
		);

		$this->assertCount( 1, Chess_Army_Knife_Teams::clean_leagues( array( $row, $row, array_merge( $row, array( 'event' => 'DIVISION 1' ) ) ) ) );
	}

	public function test_something_that_is_not_a_list_gives_no_leagues() {
		$this->assertSame( array(), Chess_Army_Knife_Teams::clean_leagues( '' ) );
		$this->assertSame( array(), Chess_Army_Knife_Teams::clean_leagues( null ) );
	}

	public function test_a_leagues_team_name_is_the_teams_own_unless_the_lms_uses_another() {
		$entries = Chess_Army_Knife_Teams::league_entries();

		$this->assertSame(
			array(
				array(
					'org'     => '613',
					'event'   => 'Division 1',
					'team'    => 'Club A',
					'team_id' => 11,
				),
				array(
					'org'     => '613',
					'event'   => 'Division 2',
					'team'    => 'Gloucester A',
					'team_id' => 11,
				),
			),
			$entries
		);
	}

	public function test_a_fixture_finds_the_team_that_plays_its_league_entry() {
		$team = Chess_Army_Knife_Teams::team_for_season( '613|Division 2|Gloucester A' );

		$this->assertSame( 11, $team['id'] );
		$this->assertNull( Chess_Army_Knife_Teams::team_for_season( '613|Division 2|Club B' ) );
	}

	public function test_a_team_without_leagues_has_no_entries() {
		$teams = Chess_Army_Knife_Teams::all();

		$this->assertSame( array(), $teams[1]['leagues'] );
		$this->assertSame( array(), Chess_Army_Knife_Teams::seasons_of( $teams[1] ) );
	}

	public function test_the_club_teams_the_rest_of_the_plugin_reads_are_the_league_entries() {
		Functions\when( 'current_time' )->justReturn( '2026-09-30' );

		$this->assertSame( Chess_Army_Knife_Teams::league_entries(), Chess_Army_Knife_Settings::get_club_teams() );
	}

	/**
	 * @dataProvider whatsapp_links
	 */
	public function test_only_a_whatsapp_group_invite_link_is_kept( $given, $expected ) {
		$this->assertSame( $expected, Chess_Army_Knife_Teams::clean_whatsapp_link( $given ) );
	}

	public function whatsapp_links() {
		return array(
			'an invite link'      => array( 'https://chat.whatsapp.com/AbCdEfGhIjKlMn12', 'https://chat.whatsapp.com/AbCdEfGhIjKlMn12' ),
			'padded'              => array( "  https://chat.whatsapp.com/AbCdEfGhIjKlMn12/ \n", 'https://chat.whatsapp.com/AbCdEfGhIjKlMn12/' ),
			'plain http'          => array( 'http://chat.whatsapp.com/AbCdEfGhIjKlMn12', '' ),
			'another site'        => array( 'https://example.com/AbCdEfGhIjKlMn12', '' ),
			'a look-alike host'   => array( 'https://chat.whatsapp.com.evil.example/AbCdEfGhIjKlMn12', '' ),
			'extra path or query' => array( 'https://chat.whatsapp.com/AbCdEfGhIjKlMn12?x=1', '' ),
			'a script'            => array( 'javascript:alert(1)', '' ),
			'no code'             => array( 'https://chat.whatsapp.com/', '' ),
			'blank'               => array( '', '' ),
		);
	}

	public function test_display_name_uses_the_format_and_trims_a_missing_league() {
		$with    = array(
			'name'    => 'Wotton Hall Lions',
			'leagues' => array( array( 'org' => '613', 'event' => 'Division 1', 'name' => '' ) ),
		);
		$without = array(
			'name'    => 'Wotton Hall Lions',
			'leagues' => array(),
		);

		$this->assertSame( 'Division 1 - Wotton Hall Lions', Chess_Army_Knife_Teams::display_name( $with, '{league} - {team}' ) );
		$this->assertSame( 'Wotton Hall Lions', Chess_Army_Knife_Teams::display_name( $without, '{league} - {team}' ) );
		$this->assertSame( 'Wotton Hall Lions', Chess_Army_Knife_Teams::display_name( $with, '' ) );
	}

	public function test_group_teams_by_division_keeps_first_seen_order() {
		$lions   = array( 'name' => 'Lions', 'leagues' => array( array( 'event' => 'Division 1' ) ) );
		$rhinos  = array( 'name' => 'Rhinos', 'leagues' => array( array( 'event' => 'Division 2' ) ) );
		$leopard = array( 'name' => 'Leopards', 'leagues' => array( array( 'event' => 'Division 1' ) ) );
		$none    = array( 'name' => 'Club', 'leagues' => array() );

		$groups = Chess_Army_Knife_Teams::group_teams( array( $lions, $rhinos, $leopard, $none ), 'division' );

		$this->assertSame( array( 'Division 1', 'Division 2', '' ), array_column( $groups, 'label' ) );
		$this->assertSame( array( $lions, $leopard ), $groups[0]['teams'] );
	}

	public function test_group_teams_by_group_uses_the_free_text_group() {
		$a = array( 'name' => 'A', 'group' => 'NGCA', 'leagues' => array() );
		$b = array( 'name' => 'B', 'group' => 'Internal', 'leagues' => array() );
		$c = array( 'name' => 'C', 'group' => 'NGCA', 'leagues' => array() );

		$groups = Chess_Army_Knife_Teams::group_teams( array( $a, $b, $c ), 'group' );

		$this->assertSame( array( 'NGCA', 'Internal' ), array_column( $groups, 'label' ) );
		$this->assertSame( array( $a, $c ), $groups[0]['teams'] );
	}

	public function test_build_roster_marks_the_captain_and_separates_a_non_playing_one() {
		$members = array(
			array( 'id' => 1, 'name' => 'Ann' ),
			array( 'id' => 2, 'name' => 'Bob' ),
			array( 'id' => 3, 'name' => 'Cat' ),
		);

		$playing = Chess_Army_Knife_Teams::build_roster( $members, array( 1, 2 ), 2 );
		$this->assertSame( array( false, true ), array_column( $playing['players'], 'captain' ) );
		$this->assertSame( '', $playing['non_playing_captain'] );

		$outside = Chess_Army_Knife_Teams::build_roster( $members, array( 1, 2 ), 3 );
		$this->assertCount( 2, $outside['players'] );
		$this->assertSame( 'Cat', $outside['non_playing_captain'] );
	}
}
