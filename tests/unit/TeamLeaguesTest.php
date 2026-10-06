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
					'org'      => '613',
					'event'    => 'Division 1',
					'team'     => 'Club A',
					'team_id'  => 11,
					'historic' => false,
				),
				array(
					'org'      => '613',
					'event'    => 'Division 2',
					'team'     => 'Gloucester A',
					'team_id'  => 11,
					'historic' => false,
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

	/**
	 * A team as all() returns it, with at most one league.
	 *
	 * @param string $name  Team name.
	 * @param string $event Division of its league, or '' for none.
	 * @param string $group Name of its group.
	 * @param int    $order Page order of its group.
	 * @param int    $position Its place within the group.
	 * @return array
	 */
	protected function team( $name, $event = '', $group = '', $order = 0, $position = 0 ) {
		$leagues = array();
		if ( '' !== $event ) {
			$leagues[] = array(
				'org'   => '613',
				'event' => $event,
				'name'  => '',
			);
		}
		return array(
			'name'        => $name,
			'group'       => $group,
			'group_blurb' => '',
			'group_order' => $order,
			'group_pos'   => $position,
			'leagues'     => $leagues,
		);
	}

	/**
	 * A member row.
	 *
	 * @param int    $id   Member id.
	 * @param string $name Member name.
	 * @return array
	 */
	protected function member( $id, $name ) {
		return array(
			'id'   => $id,
			'name' => $name,
		);
	}

	public function test_display_name_uses_the_format_and_trims_a_missing_league() {
		$with    = $this->team( 'Wotton Hall Lions', 'Division 1' );
		$without = $this->team( 'Wotton Hall Lions' );

		$this->assertSame( 'Division 1 - Wotton Hall Lions', Chess_Army_Knife_Teams::display_name( $with, '{league} - {team}' ) );
		$this->assertSame( 'Wotton Hall Lions', Chess_Army_Knife_Teams::display_name( $without, '{league} - {team}' ) );
		$this->assertSame( 'Wotton Hall Lions', Chess_Army_Knife_Teams::display_name( $with, '' ) );
	}

	public function test_group_teams_by_division_keeps_first_seen_order() {
		$lions   = $this->team( 'Lions', 'Division 1' );
		$rhinos  = $this->team( 'Rhinos', 'Division 2' );
		$leopard = $this->team( 'Leopards', 'Division 1' );
		$none    = $this->team( 'Club' );

		$groups = Chess_Army_Knife_Teams::group_teams( array( $lions, $rhinos, $leopard, $none ), 'division' );

		$this->assertSame( array( 'Division 1', 'Division 2', '' ), array_column( $groups, 'label' ) );
		$this->assertSame( array( $lions, $leopard ), $groups[0]['teams'] );
	}

	public function test_group_teams_by_group_puts_ungrouped_first_then_follows_the_group_order() {
		$a = $this->team( 'A', '', 'NGCA', 2 );
		$b = $this->team( 'B', '', 'Internal', 1 );
		$c = $this->team( 'C', '', 'NGCA', 2 );
		$d = $this->team( 'D' );

		$groups = Chess_Army_Knife_Teams::group_teams( array( $a, $b, $c, $d ), 'group' );

		$this->assertSame( array( '', 'Internal', 'NGCA' ), array_column( $groups, 'label' ) );
		$this->assertSame( array( $a, $c ), $groups[2]['teams'] );
	}

	public function test_group_teams_orders_teams_within_a_group_by_their_place() {
		$first  = $this->team( 'Zebras', '', 'NGCA', 1, 0 );
		$second = $this->team( 'Aardvarks', '', 'NGCA', 1, 1 );
		$third  = $this->team( 'Moles', '', 'NGCA', 1, 2 );

		// Handed over in page order, not the group's order.
		$groups = Chess_Army_Knife_Teams::group_teams( array( $third, $first, $second ), 'group' );

		$this->assertSame( array( $first, $second, $third ), $groups[0]['teams'] );
	}

	public function test_build_roster_marks_the_captain_and_separates_a_non_playing_one() {
		$members = array( $this->member( 1, 'Ann' ), $this->member( 2, 'Bob' ), $this->member( 3, 'Cat' ) );

		$playing = Chess_Army_Knife_Teams::build_roster( $members, array( 1, 2 ), 2 );
		$this->assertSame( array( false, true ), array_column( $playing['players'], 'captain' ) );
		$this->assertSame( array(), $playing['non_playing_captain'] );

		$outside = Chess_Army_Knife_Teams::build_roster( $members, array( 1, 2 ), 3 );
		$this->assertCount( 2, $outside['players'] );
		$this->assertSame( 'Cat', $outside['non_playing_captain']['name'] );
	}

	public function test_a_captain_who_is_not_a_member_is_the_non_playing_captain() {
		$members = array( $this->member( 1, 'Ann' ) );

		$roster = Chess_Army_Knife_Teams::build_roster( $members, array( 1 ), 0, 'surname', 'Pat Parent' );
		$this->assertSame( 'Pat Parent', $roster['non_playing_captain']['name'] );
		$this->assertCount( 1, $roster['players'] );

		// A member as captain wins over the typed name.
		$roster = Chess_Army_Knife_Teams::build_roster( $members, array( 1 ), 1, 'surname', 'Pat Parent' );
		$this->assertSame( array(), $roster['non_playing_captain'] );
		$this->assertTrue( $roster['players'][0]['captain'] );
	}

	public function test_build_roster_sorts_by_surname_by_default() {
		$members = array( $this->member( 1, 'Zack Norris' ), $this->member( 2, 'Ian Robson' ), $this->member( 3, 'Mike Ashworth' ) );

		$roster = Chess_Army_Knife_Teams::build_roster( $members, array( 1, 2, 3 ), 0 );

		$this->assertSame( array( 'Mike Ashworth', 'Zack Norris', 'Ian Robson' ), array_column( $roster['players'], 'name' ) );
	}

	public function test_build_roster_can_sort_by_rating_with_unrated_players_last() {
		$members = array(
			array(
				'id'            => 1,
				'name'          => 'Ann Able',
				'ecf_rating'    => 1500,
				'manual_rating' => null,
			),
			array(
				'id'            => 2,
				'name'          => 'Bob Baker',
				'ecf_rating'    => null,
				'manual_rating' => 1800,
			),
			array(
				'id'   => 3,
				'name' => 'Cat Cole',
			),
			array(
				'id'         => 4,
				'name'       => 'Dan Dyer',
				'ecf_rating' => 1500,
			),
		);

		$roster = Chess_Army_Knife_Teams::build_roster( $members, array( 1, 2, 3, 4 ), 0, 'rating' );

		$this->assertSame( array( 'Bob Baker', 'Ann Able', 'Dan Dyer', 'Cat Cole' ), array_column( $roster['players'], 'name' ) );
		$this->assertSame( array( 1800, 1500, 1500, 0 ), array_column( $roster['players'], 'rating' ) );
	}

	public function test_the_leagues_page_merges_rows_into_each_teams_entries() {
		$current = array(
			11 => array(
				array(
					'org'   => '999',
					'event' => 'Cup',
					'name'  => '',
				),
				array(
					'org'   => '270',
					'event' => 'Old division',
					'name'  => '',
				),
			),
			12 => array(),
		);
		$rows    = array(
			array(
				'org'     => '270',
				'team_id' => 12,
				'event'   => 'Division 1',
				'name'    => '',
			),
			array(
				'org'     => '270',
				'team_id' => 12,
				'event'   => '',
				'name'    => 'No event is dropped',
			),
			array(
				'org'     => '555',
				'team_id' => 12,
				'event'   => 'Not a shown organisation',
				'name'    => '',
			),
			array(
				'org'     => '270',
				'team_id' => 77,
				'event'   => 'Not a team',
				'name'    => '',
			),
		);

		$merged = Chess_Army_Knife_Leagues_Page::merge( $current, array( '270' ), $rows );

		$this->assertSame( array( '999' ), array_column( $merged[11], 'org' ), 'The shown organisation is replaced, the rest kept.' );
		$this->assertSame( array( 'Division 1' ), array_column( $merged[12], 'event' ) );
		$this->assertArrayNotHasKey( 77, $merged );
	}

	public function test_divisions_are_listed_in_natural_alphabetical_order() {
		$sorted = Chess_Army_Knife_Leagues_Page::sort_divisions( array( 'Division 10', 'Division 2', 'cup', 'Division 1', 'Division 2' ) );

		$this->assertSame( array( 'cup', 'Division 1', 'Division 2', 'Division 10' ), $sorted );
	}

	public function test_the_teams_list_shows_current_teams_unless_another_scope_is_asked_for() {
		$this->assertSame( 'current', Chess_Army_Knife_Teams::clean_scope( '' ) );
		$this->assertSame( 'current', Chess_Army_Knife_Teams::clean_scope( 'nonsense' ) );
		$this->assertSame( 'historic', Chess_Army_Knife_Teams::clean_scope( 'historic' ) );
		$this->assertSame( 'all', Chess_Army_Knife_Teams::clean_scope( 'all' ) );
	}

	public function test_each_scope_picks_its_teams_by_the_historic_flag() {
		$this->assertSame( array(), Chess_Army_Knife_Teams::scope_meta_query( 'all' ) );

		$historic = Chess_Army_Knife_Teams::scope_meta_query( 'historic' );
		$this->assertSame( '1', $historic[0]['value'] );

		// A team never marked historic has no flag at all, so it counts as current.
		$current = Chess_Army_Knife_Teams::scope_meta_query( 'whatever' );
		$this->assertSame( 'OR', $current['relation'] );
		$this->assertSame( 'NOT EXISTS', $current[0]['compare'] );
	}
}
