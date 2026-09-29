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

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		update_option(
			Chess_Army_Knife_Club_Teams_Page::OPTION,
			array(
				array(
					'org'   => '613',
					'event' => 'Division 1',
					'team'  => 'Our A',
				),
			)
		);

		$this->lms      = array();
		$this->requests = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false === strpos( $url, '/lmsrest/league/match' ) ) {
					return $preempt;
				}
				++$this->requests;
				$name   = json_decode( $args['body'], true )['name'];
				$answer = isset( $this->lms[ $name ] ) ? $this->lms[ $name ] : array();
				if ( is_int( $answer ) ) {
					return array(
						'headers'  => array(),
						'body'     => '',
						'response' => array(
							'code'    => $answer,
							'message' => '',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'matches' => $answer ) ),
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

	private function fixture( $home, $away, $date, array $extra = array() ) {
		return array_merge(
			array(
				'date'  => $date,
				'left'  => array( 'name' => $home ),
				'right' => array( 'name' => $away ),
			),
			$extra
		);
	}

	private function imported() {
		return Chess_Army_Knife_Events::query( array( 'after' => '' ) );
	}

	public function test_fixtures_of_the_club_teams_become_events() {
		$this->lms['Division 1'] = array(
			$this->fixture( 'Our A', 'Rivals', '2099-10-05', array( 'venue' => array( 'name' => 'Town Hall' ) ) ),
			$this->fixture( 'Others', 'Elsewhere', '2099-10-05' ),
			$this->fixture( 'Rivals', 'Our A', '2099-11-02', array( 'time' => '20:00' ) ),
		);

		$summary = Chess_Army_Knife_Events_Import::import();
		$events  = $this->imported();

		$this->assertSame( 2, $summary['created'] );
		$this->assertCount( 2, $events );
		$this->assertSame( 'Our A v Rivals', $events[0]['title'] );
		$this->assertSame( '2099-10-05 19:30:00', $events[0]['start'], 'No LMS time: the usual kick-off from Settings.' );
		$this->assertSame( 'Town Hall', $events[0]['location'] );
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

		// The LMS gives a start time and a venue later.
		$this->lms['Division 1'] = array(
			$this->fixture(
				'Our A',
				'Rivals',
				'2099-10-05',
				array(
					'time'  => '20:15',
					'venue' => array( 'name' => 'Library' ),
				)
			),
		);
		$summary                 = Chess_Army_Knife_Events_Import::import();

		$event = $this->imported()[0];
		$this->assertSame( 1, $summary['updated'] );
		$this->assertSame( $id, $event['id'], 'The same event is refreshed.' );
		$this->assertSame( '2099-10-05 20:15:00', $event['start'] );
		$this->assertSame( 'Library', $event['location'] );
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
		update_option(
			Chess_Army_Knife_Club_Teams_Page::OPTION,
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

	public function test_a_manual_import_bypasses_the_lms_cache() {
		$this->lms['Division 1'] = array( $this->fixture( 'Our A', 'Rivals', '2099-10-05' ) );

		Chess_Army_Knife_Events_Import::import();
		Chess_Army_Knife_Events_Import::import();

		$this->assertSame( 2, $this->requests );
	}

	public function test_one_request_per_league_however_many_teams() {
		update_option(
			Chess_Army_Knife_Club_Teams_Page::OPTION,
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
		update_option( Chess_Army_Knife_Club_Teams_Page::OPTION, array() );

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
}
