<?php
/**
 * Integration tests: importing club events from the LMS.
 *
 * @package Chess_Army_Knife
 */

class EventsImportTest extends WP_UnitTestCase {

	/** @var array LMS answers keyed by event name: a list of fixtures, or an int HTTP error status. */
	private $lms = array();

	/** @var int Requests made to the LMS. */
	private $requests = 0;

	public function set_up() {
		parent::set_up();

		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'lms_api_key'     => 'lmsk_test',
			)
		);
		Chess_Army_Knife_Teams::assign_league_entries(
			array(
				array(
					'org'   => '613',
					'event' => 'Division 1',
					'team'  => 'Our A',
				),
			)
		);

		global $wpdb;
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Teams::squad_table() ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Teams::install_table();

		$this->lms      = array();
		$this->requests = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$pos = strpos( $url, '/lmsrest/v2/' );
				if ( false === $pos ) {
					return $preempt;
				}
				$path  = substr( $url, $pos + strlen( '/lmsrest/v2/' ) );
				$names = array_keys( $this->lms );

				if ( 'org/613/seasons' === $path ) {
					return $this->answer(
						200,
						array(
							'seasons' => array(
								array(
									'id'     => 1,
									'name'   => '2099-00',
									'status' => 'active',
								),
							),
						)
					);
				}
				if ( 'season/1/events' === $path ) {
					$events = array();
					foreach ( $names as $index => $name ) {
						$events[] = array(
							'id'   => $index + 1,
							'name' => $name,
							'type' => 'team_league',
						);
					}
					return $this->answer( 200, array( 'events' => $events ) );
				}
				if ( preg_match( '#^event/(\d+)/results$#', $path, $m ) ) {
					++$this->requests;
					$answer = $this->lms[ $names[ (int) $m[1] - 1 ] ];
					return is_int( $answer ) ? $this->answer( $answer, array( 'error' => 'failed' ) ) : $this->answer( 200, array( 'fixtures' => $answer ) );
				}
				return $this->answer( 404, array( 'error' => 'Not found' ) );
			},
			10,
			3
		);
	}

	private function answer( $code, array $body ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function fixture( $home, $away, $date, array $extra = array() ) {
		return array_merge(
			array(
				'date'      => $date,
				'time'      => null,
				'home_team' => $home,
				'away_team' => $away,
				'games'     => array(),
			),
			$extra
		);
	}

	private function imported() {
		return Chess_Army_Knife_Events::query( array( 'after' => '' ) );
	}

	public function test_fixtures_of_the_club_teams_become_events() {
		$this->lms['Division 1'] = array(
			$this->fixture( 'Our A', 'Rivals', '2099-10-05', array() ),
			$this->fixture( 'Others', 'Elsewhere', '2099-10-05' ),
			$this->fixture( 'Rivals', 'Our A', '2099-11-02', array( 'time' => '20:00' ) ),
		);

		$summary = Chess_Army_Knife_Events_Import::import();
		$events  = $this->imported();

		$this->assertSame( 2, $summary['created'] );
		$this->assertCount( 2, $events );
		$this->assertSame( 'Our A v Rivals', $events[0]['title'] );
		$this->assertSame( '2099-10-05 19:30:00', $events[0]['start'], 'No LMS time: the usual kick-off from Settings.' );
		$this->assertSame( array( 'League match' ), wp_list_pluck( $events[0]['tags'], 'name' ) );
		$this->assertSame(
			array(
				array(
					'org'   => '613',
					'event' => 'Division 1',
				),
			),
			$events[0]['leagues']
		);
		$this->assertSame( '2099-11-02 20:00:00', $events[1]['start'] );
	}

	public function test_the_usual_kick_off_time_setting_is_used() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'lms_api_key'     => 'lmsk_test',
				'match_time'      => '18:45',
			)
		);
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );

		Chess_Army_Knife_Events_Import::import();

		$this->assertSame( '2099-10-05 18:45:00', $this->imported()[0]['start'] );
	}

	public function test_importing_again_never_duplicates() {
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );

		Chess_Army_Knife_Events_Import::import();
		$second = Chess_Army_Knife_Events_Import::import();

		$this->assertSame( 0, $second['created'] );
		$this->assertSame( 1, $second['unchanged'] );
		$this->assertCount( 1, $this->imported() );
	}

	public function test_an_untouched_event_follows_a_change_in_the_lms() {
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );
		Chess_Army_Knife_Events_Import::import();
		$id = $this->imported()[0]['id'];

		// The LMS gives a start time later.
		$this->lms['Division 1'] = array(
			$this->fixture(
				'Our A',
				'Rivals',
				'2099-10-05',
				array( 'time' => '20:15' )
			),
		);
		$summary                 = Chess_Army_Knife_Events_Import::import();

		$event = $this->imported()[0];
		$this->assertSame( 1, $summary['updated'] );
		$this->assertSame( $id, $event['id'], 'The same event is refreshed.' );
		$this->assertSame( '2099-10-05 20:15:00', $event['start'] );
	}

	public function test_an_event_edited_by_hand_is_left_alone() {
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );
		Chess_Army_Knife_Events_Import::import();
		$id = $this->imported()[0]['id'];

		// An editor changes the time in the admin.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = array(
			Chess_Army_Knife_Events_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Events_Admin::NONCE_ACTION ),
			'chess_army_event_date'                    => '2099-10-05',
			'chess_army_event_start_time'              => '21:00',
		);
		Chess_Army_Knife_Events_Admin::save( $id, get_post( $id ) );
		$_POST = array();

		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05', array( 'time' => '20:15' ) ) );
		$summary                 = Chess_Army_Knife_Events_Import::import();

		$this->assertSame( 1, $summary['kept'] );
		$this->assertSame( '2099-10-05 21:00:00', $this->imported()[0]['start'] );
	}

	public function test_an_event_moved_to_the_trash_stays_deleted() {
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );
		Chess_Army_Knife_Events_Import::import();
		wp_trash_post( $this->imported()[0]['id'] );

		$summary = Chess_Army_Knife_Events_Import::import();

		$this->assertSame( 0, $summary['created'] );
		$this->assertSame( 1, $summary['kept'] );
		$this->assertSame( array(), $this->imported() );
	}

	public function test_a_rescheduled_fixture_is_a_new_event() {
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );
		Chess_Army_Knife_Events_Import::import();

		// The fixture key includes the date, so a move to another date is treated as a new fixture.
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-12' ) );
		$summary                 = Chess_Army_Knife_Events_Import::import();

		$this->assertSame( 1, $summary['created'] );
	}

	public function test_a_league_that_fails_to_load_is_reported_and_others_still_import() {
		Chess_Army_Knife_Teams::assign_league_entries(
			array(
				array(
					'org'   => '613',
					'event' => 'Division 1',
					'team'  => 'Our A',
				),
				array(
					'org'   => '613',
					'event' => 'Division 2',
					'team'  => 'Our B',
				),
			)
		);
		$this->lms['Division 1'] = 500;
		$this->lms['Division 2'] = array( $this->fixture( 'Our B', 'Others', '2099-10-05' ) );

		$summary = Chess_Army_Knife_Events_Import::import();

		$this->assertSame( 1, $summary['created'] );
		$this->assertCount( 1, $summary['errors'] );
		$this->assertStringContainsString( 'Division 1', $summary['errors'][0] );
	}

	public function test_without_an_api_key_nothing_is_requested_and_the_error_says_why() {
		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );

		$summary = Chess_Army_Knife_Events_Import::import();

		$this->assertSame( 0, $summary['created'] );
		$this->assertSame( 0, $this->requests );
		$this->assertCount( 1, $summary['errors'] );
		$this->assertStringContainsString( 'API key', $summary['errors'][0] );
	}

	public function test_a_manual_import_bypasses_the_lms_cache() {
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );

		Chess_Army_Knife_Events_Import::import();
		Chess_Army_Knife_Events_Import::import();

		$this->assertSame( 2, $this->requests );
	}

	public function test_one_request_per_league_however_many_teams() {
		Chess_Army_Knife_Teams::assign_league_entries(
			array(
				array(
					'org'   => '613',
					'event' => 'Division 1',
					'team'  => 'Our A',
				),
				array(
					'org'   => '613',
					'event' => 'Division 1',
					'team'  => 'Our B',
				),
			)
		);
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Our B', '2099-10-05' ) );

		$summary = Chess_Army_Knife_Events_Import::import();

		$this->assertSame( 1, $this->requests );
		$this->assertSame( 1, $summary['created'], 'Our A v Our B is one fixture.' );
	}

	public function test_without_club_teams_nothing_is_requested() {
		Chess_Army_Knife_Teams::install_table(); // Deleting a team clears its squad.
		foreach ( Chess_Army_Knife_Teams::all() as $club_team ) {
			wp_delete_post( $club_team['id'], true );
		}

		$summary = Chess_Army_Knife_Events_Import::import();

		$this->assertSame( 0, $this->requests );
		$this->assertSame( 0, $summary['created'] );
	}

	public function test_imported_events_appear_in_the_calendar_block() {
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );
		Chess_Army_Knife_Events_Import::import();

		$html = do_blocks( '<!-- wp:chess-army-knife/club-event-calendar {"tags":["league-match"]} /-->' );

		$this->assertStringContainsString( 'Our A v Rivals', $html );
		$this->assertStringContainsString( 'Division 1', $html );
	}

	public function test_imported_fixtures_are_linked_to_their_team_with_the_side_and_relinked_on_the_next_import() {
		$this->lms['Division 1'] = array(
			$this->fixture( 'Our A', 'Rivals', '2099-10-05' ),
			$this->fixture( 'Rivals', 'Our A', '2099-11-02' ),
		);

		// Imported while the league belongs to a team called Our A: linked to that team.
		Chess_Army_Knife_Events_Import::import();
		$this->assertSame( 'Our A', $this->imported()[0]['teams'][0]['name'] );

		// The league moves to Club A, which the LMS knows as Our A.
		Chess_Army_Knife_Teams::install_table(); // Deleting a team clears its squad.
		foreach ( Chess_Army_Knife_Teams::all() as $old_team ) {
			wp_delete_post( $old_team['id'], true );
		}
		$team = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Club A',
				'meta_input'  => array(
					Chess_Army_Knife_Teams::META_LEAGUES => array(
						array(
							'org'   => '613',
							'event' => 'Division 1',
							'name'  => 'Our A',
						),
					),
					Chess_Army_Knife_Teams::META_COLOUR  => '#2a78d6',
				),
			)
		);

		$summary = Chess_Army_Knife_Events_Import::import();
		$events  = $this->imported();

		$this->assertSame( 2, $summary['updated'] );
		$this->assertSame( 'home', $events[0]['teams'][0]['side'] );
		$this->assertSame( 'away', $events[1]['teams'][0]['side'] );
		$this->assertSame( $team, $events[0]['teams'][0]['id'] );
		$this->assertSame( '#2a78d6', $events[0]['teams'][0]['colour'] );

		$again = Chess_Army_Knife_Events_Import::import();
		$this->assertSame( 0, $again['updated'] );
		$this->assertCount( 1, get_post_meta( $events[0]['id'], Chess_Army_Knife_Events::META_TEAM, false ), 'Links are not duplicated.' );
	}

	private function event_titled( $title ) {
		foreach ( $this->imported() as $event ) {
			if ( $title === $event['title'] ) {
				return $event;
			}
		}
		$this->fail( 'No event called ' . $title );
	}

	public function test_a_home_fixture_is_held_at_the_teams_venue_and_an_away_one_has_none_until_the_club_is_known() {
		$team_id = Chess_Army_Knife_Teams::all()[0]['id'];
		update_post_meta( $team_id, Chess_Army_Knife_Teams::META_VENUE, 'Our Hall, High Street' );
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'lms_api_key'     => 'lmsk_test',
				'club_venue'      => 'The Club Room',
			)
		);
		$this->lms['Division 1'] = array(
			$this->fixture( 'Our A', 'Stroud Otters', '2099-10-05' ),
			$this->fixture( 'Stroud Otters', 'Our A', '2099-11-02' ),
		);

		$summary = Chess_Army_Knife_Events_Import::import();

		$this->assertSame( 'Our Hall, High Street', $this->event_titled( 'Our A v Stroud Otters' )['location'] );
		$away = $this->event_titled( 'Stroud Otters v Our A' );
		$this->assertSame( '', $away['location'], 'An away fixture is not at our club venue.' );
		$this->assertSame( 1, $summary['unsorted'], 'Stroud Otters is waiting to be put in a club.' );
		$this->assertSame( array( 'Stroud Otters' ), Chess_Army_Knife_Clubs::unsorted() );

		// The admin says where Stroud play; the next import gives the away fixture that venue.
		Chess_Army_Knife_Clubs::add_teams_to_club(
			array( 'Stroud Otters' ),
			0,
			'Stroud Chess Club',
			array(
				'venue'      => 'Stroud Library',
				'map_url'    => 'https://maps.app.goo.gl/stroud',
				'what3words' => 'index.home.raft',
			)
		);
		$summary = Chess_Army_Knife_Events_Import::import();

		$away = $this->event_titled( 'Stroud Otters v Our A' );
		$this->assertSame( 1, $summary['updated'] );
		$this->assertSame( 'Stroud Library', $away['location'] );
		$this->assertSame( 'https://maps.app.goo.gl/stroud', $away['map_url'] );
		$this->assertSame( 'index.home.raft', $away['what3words'] );
		$this->assertSame( 0, $summary['unsorted'] );
	}

	public function test_an_event_edited_by_hand_keeps_its_own_location_when_a_venue_is_learned() {
		$this->lms['Division 1'] = array( $this->fixture( 'Stroud Otters', 'Our A', '2099-10-05' ) );
		Chess_Army_Knife_Events_Import::import();
		$id = $this->imported()[0]['id'];
		update_post_meta( $id, Chess_Army_Knife_Events_Import::META_EDITED, 1 );
		update_post_meta( $id, Chess_Army_Knife_Events::META_LOCATION, 'Changed by hand' );

		Chess_Army_Knife_Clubs::add_teams_to_club(
			array( 'Stroud Otters' ),
			0,
			'Stroud',
			array(
				'venue'      => 'Stroud Library',
				'map_url'    => '',
				'what3words' => '',
			)
		);
		Chess_Army_Knife_Events_Import::import();

		$this->assertSame( 'Changed by hand', $this->imported()[0]['location'] );
	}

	public function test_a_club_is_found_by_any_of_its_team_names_and_keeps_its_venue_when_teams_are_added() {
		$club = Chess_Army_Knife_Clubs::add_teams_to_club(
			array( 'Stroud Otters' ),
			0,
			'Stroud',
			array(
				'venue'      => 'Stroud Library',
				'map_url'    => '',
				'what3words' => '',
			)
		);
		Chess_Army_Knife_Clubs::note_seen( array( 'Stroud Otters', 'stroud badgers', 'Our A' ) );

		$this->assertSame( 'Stroud', Chess_Army_Knife_Clubs::find_by_team( 'STROUD OTTERS' )['name'] );
		$this->assertNull( Chess_Army_Knife_Clubs::find_by_team( 'Stroud Badgers' ) );
		$this->assertSame( array( 'stroud badgers' ), Chess_Army_Knife_Clubs::unsorted(), 'Our own team and a sorted team are not waiting.' );

		Chess_Army_Knife_Clubs::add_teams_to_club(
			array( 'stroud badgers' ),
			$club,
			'Ignored',
			array(
				'venue'      => 'Ignored',
				'map_url'    => '',
				'what3words' => '',
			)
		);

		$this->assertSame( 'Stroud Library', Chess_Army_Knife_Clubs::venue_of_team( 'Stroud Badgers' )['location'] );
		$this->assertSame( array(), Chess_Army_Knife_Clubs::unsorted() );
	}

	public function test_a_fixture_is_tagged_league_match_and_with_its_teams_own_tag() {
		$team_id = Chess_Army_Knife_Teams::all()[0]['id'];
		update_post_meta( $team_id, Chess_Army_Knife_Teams::META_TAG, 'Lions' );
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );

		Chess_Army_Knife_Events_Import::import();

		$tags = wp_list_pluck( $this->imported()[0]['tags'], 'name' );
		sort( $tags );
		$this->assertSame( array( 'League match', 'Lions' ), $tags );
		$this->assertCount( 1, Chess_Army_Knife_Events::query( array( 'tags' => array( 'lions' ) ) ), 'A calendar can show just this team.' );
		$this->assertCount( 0, Chess_Army_Knife_Events::query( array( 'tags' => array( 'zebras' ) ) ) );
	}

	public function test_a_tag_added_to_a_team_later_reaches_its_existing_events_without_removing_others() {
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );
		Chess_Army_Knife_Events_Import::import();
		$id = $this->imported()[0]['id'];
		wp_set_object_terms( $id, array( 'Juniors' ), Chess_Army_Knife_Events::TAXONOMY, true );

		update_post_meta( Chess_Army_Knife_Teams::all()[0]['id'], Chess_Army_Knife_Teams::META_TAG, 'Lions' );
		$summary = Chess_Army_Knife_Events_Import::import();

		$tags = wp_list_pluck( $this->imported()[0]['tags'], 'name' );
		sort( $tags );
		$this->assertSame( array( 'Juniors', 'League match', 'Lions' ), $tags );
		$this->assertSame( 1, $summary['updated'] );

		$again = Chess_Army_Knife_Events_Import::import();
		$this->assertSame( 0, $again['updated'], 'Nothing more to add.' );
	}

	private function player( $code, $name ) {
		return array(
			'lms_id'      => 1,
			'rating_code' => $code,
			'name'        => $name,
		);
	}

	private function member_with_code( $name, $code, $status = 'active' ) {
		return Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'     => $name,
				'email'    => strtolower( strtok( $name, ' ' ) ) . '@example.test',
				'status'   => $status,
				'ecf_code' => $code,
			)
		);
	}

	public function test_players_who_played_for_a_team_are_put_in_its_squad_by_their_ecf_code() {
		$ada   = $this->member_with_code( 'Ada Lovelace', '123456A' );
		$bea   = $this->member_with_code( 'Bea Babbage', '222222B' );
		$guest = $this->member_with_code( 'Gus Guest', '333333C', Chess_Army_Knife_Membership_Store::STATUS_NONMEMBER );
		$team  = Chess_Army_Knife_Teams::all()[0]['id'];
		Chess_Army_Knife_Teams::set_squad( $team, array( $bea ) );
		$this->lms['Division 1'] = array(
			$this->fixture(
				'Our A',
				'Rivals',
				'2099-10-05',
				array(
					'games' => array(
						array(
							'board'       => 1,
							'home_player' => $this->player( '123456A', 'Ada' ),
							'away_player' => $this->player( '999999Z', 'Their player' ),
						),
						array(
							'board'       => 2,
							'home_player' => $this->player( '333333C', 'Gus' ),
							'away_player' => $this->player( '888888Y', 'Another of theirs' ),
						),
						array(
							'board'       => 3,
							'home_player' => $this->player( '777777X', 'Not on file' ),
							'away_player' => $this->player( '666666W', 'Theirs again' ),
						),
					),
				)
			),
		);

		$summary = Chess_Army_Knife_Events_Import::import();

		$this->assertEqualsCanonicalizing( array( $ada, $bea ), Chess_Army_Knife_Teams::squad( $team ), 'Ada is added; Bea stays; a guest is not.' );
		$this->assertSame( 1, $summary['squad_added'] );
		$this->assertSame( 2, $summary['players_unmatched'], 'The guest and the player with no record.' );
		$this->assertNotContains( $guest, Chess_Army_Knife_Teams::squad( $team ) );

		$again = Chess_Army_Knife_Events_Import::import();
		$this->assertSame( 0, $again['squad_added'], 'Importing again adds nobody twice.' );
	}

	public function test_a_player_in_two_of_our_teams_is_in_both_squads_and_import_never_removes_anyone() {
		Chess_Army_Knife_Teams::assign_league_entries(
			array(
				array(
					'org'   => '613',
					'event' => 'Division 1',
					'team'  => 'Our A',
				),
				array(
					'org'   => '613',
					'event' => 'Division 2',
					'team'  => 'Our B',
				),
			)
		);
		$ada     = $this->member_with_code( 'Ada Lovelace', '123456A' );
		$old     = $this->member_with_code( 'Old Timer', '444444D' );
		$by_name = array_column( Chess_Army_Knife_Teams::all(), 'id', 'name' );
		Chess_Army_Knife_Teams::set_squad( $by_name['Our B'], array( $old ) );
		$this->lms['Division 1'] = array(
			$this->fixture(
				'Our A',
				'Rivals',
				'2099-10-05',
				array(
					'games' => array(
						array(
							'board'       => 1,
							'home_player' => $this->player( '123456A', 'Ada' ),
							'away_player' => $this->player( '9', 'X' ),
						),
					),
				)
			),
		);
		$this->lms['Division 2'] = array(
			$this->fixture(
				'Rivals',
				'Our B',
				'2099-10-05',
				array(
					'games' => array(
						array(
							'board'       => 1,
							'home_player' => $this->player( '9', 'Y' ),
							'away_player' => $this->player( '123456A', 'Ada' ),
						),
					),
				)
			),
		);

		Chess_Army_Knife_Events_Import::import();

		$this->assertEqualsCanonicalizing( array( $by_name['Our A'], $by_name['Our B'] ), Chess_Army_Knife_Teams::squad_team_ids_of_person( $ada ) );
		$this->assertContains( $old, Chess_Army_Knife_Teams::squad( $by_name['Our B'] ), 'Nobody is taken out of a squad by an import.' );
	}

	public function test_the_daily_import_does_nothing_without_a_key_and_records_what_it_did_with_one() {
		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		Chess_Army_Knife_Events_Import::run_scheduled();
		$this->assertNull( Chess_Army_Knife_Events_Import::last_run(), 'No key: nothing to record.' );

		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'lms_api_key'     => 'lmsk_test',
			)
		);
		Chess_Army_Knife_Events_Import::run_scheduled();
		$last = Chess_Army_Knife_Events_Import::last_run();

		$this->assertNotNull( $last );
		$this->assertSame( 'scheduled', $last['source'] );
		$this->assertArrayHasKey( 'created', $last['summary'] );
	}

	public function test_the_daily_import_is_scheduled_and_unscheduled() {
		Chess_Army_Knife_Events_Import::schedule();
		$this->assertNotFalse( wp_next_scheduled( Chess_Army_Knife_Events_Import::HOOK ) );

		Chess_Army_Knife_Events_Import::unschedule();
		$this->assertFalse( wp_next_scheduled( Chess_Army_Knife_Events_Import::HOOK ) );
	}

	public function test_the_overview_lists_what_is_not_set_up_for_an_administrator_only() {
		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', Chess_Army_Knife_Setup_Checklist::html() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = Chess_Army_Knife_Setup_Checklist::html();
		$this->assertStringContainsString( 'There is no LMS API key', $html );
		$this->assertStringContainsString( 'No tournament has been created yet', $html );
		$this->assertStringNotContainsString( 'No teams have been added', $html, 'set_up gave the club a team.' );
	}

	public function test_the_lms_test_button_is_for_administrators_and_tests_the_saved_key() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', Chess_Army_Knife_LMS_Test::panel_html() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'Test the LMS connection', Chess_Army_Knife_LMS_Test::panel_html() );

		$this->lms = array(); // The LMS lists the organisation's seasons, as set_up() arranged.
		$this->assertSame( 'ok', Chess_Army_Knife_LMS_Test::run()['status'] );
	}

	public function test_setup_adds_the_first_team_with_its_league_and_fetches_its_fixtures() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'lms_api_key'     => 'lmsk_test',
				'default_org_id'  => '613',
			)
		);
		$this->lms['Division 2'] = array( $this->fixture( 'Our New Team', 'Rivals', '2099-10-05' ) );

		$result = Chess_Army_Knife_Setup::add_first_team( 'Our New Team', 'Division 2', true );

		$this->assertArrayNotHasKey( 'problem', $result );
		$this->assertSame( 1, $result['created'] );
		$names = wp_list_pluck( Chess_Army_Knife_Teams::all(), 'name' );
		$this->assertContains( 'Our New Team', $names );
		$this->assertNotNull( Chess_Army_Knife_Events_Import::last_run(), 'The import is recorded like any other.' );
	}

	public function test_setup_says_what_is_missing_instead_of_adding_half_a_team() {
		$this->assertNull( Chess_Army_Knife_Setup::add_first_team( '', 'Division 2', true ), 'No team given: nothing to do.' );

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$no_org = Chess_Army_Knife_Setup::add_first_team( 'Lonely Team', 'Division 2', true );
		$this->assertArrayHasKey( 'problem', $no_org );
		$this->assertNotContains( 'Lonely Team', wp_list_pluck( Chess_Army_Knife_Teams::all(), 'name' ) );

		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'default_org_id'  => '613',
			)
		);
		$no_key = Chess_Army_Knife_Setup::add_first_team( 'Keyless Team', 'Division 2', true );
		$this->assertArrayHasKey( 'problem', $no_key );
		$this->assertContains( 'Keyless Team', wp_list_pluck( Chess_Army_Knife_Teams::all(), 'name' ), 'The team is added; only the fetch is skipped.' );
		$this->assertStringContainsString( 'no LMS API key', $no_key['problem'] );
	}

	public function test_a_list_of_clubs_is_added_and_a_second_paste_updates_instead_of_duplicating() {
		$first = Chess_Army_Knife_Clubs::import_rows(
			Chess_Army_Knife_Clubs::parse_csv( "Stroud,Stroud Badgers,The Library\nCheltenham,Cheltenham Knights;Cheltenham Rooks\n" )['rows']
		);
		$this->assertSame( 2, $first['created'] );

		$second = Chess_Army_Knife_Clubs::import_rows(
			Chess_Army_Knife_Clubs::parse_csv( "stroud,Stroud Hedgehogs,,,index.home.raft\n" )['rows']
		);
		$this->assertSame( 1, $second['updated'] );
		$this->assertSame( 0, $second['created'] );

		$stroud = Chess_Army_Knife_Clubs::find_by_team( 'Stroud Hedgehogs' );
		$this->assertSame( 'Stroud', $stroud['name'] );
		$this->assertSame( 'The Library', $stroud['venue'], 'A blank venue in the second list does not erase the first.' );
		$this->assertSame( 'index.home.raft', $stroud['what3words'] );
		$this->assertEqualsCanonicalizing( array( 'Stroud Badgers', 'Stroud Hedgehogs' ), $stroud['teams'] );
		$this->assertCount( 2, Chess_Army_Knife_Clubs::all() );
	}

	public function test_imported_fixtures_are_league_matches_and_setup_events_get_their_types() {
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );
		Chess_Army_Knife_Events_Import::import();
		$this->assertSame( 'league_match', $this->imported()[0]['type'] );

		Chess_Army_Knife_Setup::create_events(
			array(
				array(
					'key'     => 'club_night',
					'title'   => 'Club night',
					'tag'     => 'Club night',
					'type'    => 'club_night',
					'weekday' => 2,
					'start'   => '19:30',
					'end'     => '',
				),
			),
			'2099-01-01'
		);
		$types = wp_list_pluck(
			Chess_Army_Knife_Events::query(
				array(
					'after'  => '',
					'limit'  => 0,
					'before' => '2099-02-01 00:00:00',
				)
			),
			'type'
		);
		$this->assertContains( 'club_night', $types );
	}
}
