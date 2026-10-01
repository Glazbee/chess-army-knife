<?php
/**
 * Integration tests: club events against real WordPress and database.
 *
 * @package Chess_Army_Knife
 */

class EventsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( 'games', 'entries', 'tournaments', 'members' ) as $name ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Tournament_Store::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Tournament_Store::install_tables();
	}

	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Create a published event.
	 *
	 * @param string $title Title.
	 * @param string $start Site-local "Y-m-d H:i:s", or '' for none.
	 * @param array  $meta  Other meta, keyed by meta key.
	 * @return int
	 */
	private function event( $title, $start, array $meta = array() ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
		if ( '' !== $start ) {
			update_post_meta( $id, Chess_Army_Knife_Events::META_START, $start );
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	private function titles( array $events ) {
		return wp_list_pluck( $events, 'title' );
	}

	public function test_post_type_and_tag_taxonomy_are_registered() {
		$this->assertTrue( post_type_exists( Chess_Army_Knife_Events::POST_TYPE ) );
		$this->assertTrue( taxonomy_exists( Chess_Army_Knife_Events::TAXONOMY ) );
		$this->assertFalse( is_taxonomy_hierarchical( Chess_Army_Knife_Events::TAXONOMY ), 'Tags are free-form.' );
	}

	public function test_events_come_back_soonest_first() {
		$this->event( 'Later', '2099-03-02 19:00:00' );
		$this->event( 'Sooner', '2099-03-01 19:00:00' );

		$this->assertSame( array( 'Sooner', 'Later' ), $this->titles( Chess_Army_Knife_Events::query() ) );
	}

	public function test_several_events_on_one_night_and_at_one_time_are_all_listed() {
		$this->event( 'Rapidplay', '2099-03-01 19:30:00' );
		$this->event( 'Club night', '2099-03-01 19:30:00' );
		$this->event( 'Coaching', '2099-03-01 18:00:00' );

		// Earlier start first; the same start time falls back to title order.
		$this->assertSame(
			array( 'Coaching', 'Club night', 'Rapidplay' ),
			$this->titles( Chess_Army_Knife_Events::query() )
		);
	}

	public function test_past_events_are_left_out_of_upcoming_but_can_be_asked_for() {
		$this->event( 'Ancient', '2000-01-01 19:00:00' );
		$this->event( 'Future', '2099-01-01 19:00:00' );

		$this->assertSame( array( 'Future' ), $this->titles( Chess_Army_Knife_Events::query() ) );
		$this->assertSame(
			array( 'Future', 'Ancient' ),
			$this->titles(
				Chess_Army_Knife_Events::query(
					array(
						'after' => '',
						'order' => 'DESC',
					)
				)
			)
		);
	}

	public function test_an_event_under_way_still_counts_as_upcoming() {
		$this->event( 'Started earlier', '2099-05-01 18:00:00', array( Chess_Army_Knife_Events::META_END => '2099-05-01 22:00:00' ) );
		$this->event( 'Already over', '2099-05-01 12:00:00', array( Chess_Army_Knife_Events::META_END => '2099-05-01 14:00:00' ) );

		$events = Chess_Army_Knife_Events::query( array( 'after' => '2099-05-01 20:00:00' ) );

		$this->assertSame( array( 'Started earlier' ), $this->titles( $events ) );
	}

	public function test_events_without_a_date_are_never_listed() {
		$this->event( 'Undated', '' );
		$this->event( 'Dated', '2099-01-01 19:00:00' );

		$this->assertSame( array( 'Dated' ), $this->titles( Chess_Army_Knife_Events::query( array( 'after' => '' ) ) ) );
	}

	public function test_drafts_are_not_listed() {
		$id = $this->event( 'Draft night', '2099-01-01 19:00:00' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( array(), Chess_Army_Knife_Events::query() );
	}

	public function test_limit_and_before_narrow_the_results() {
		$this->event( 'One', '2099-01-01 19:00:00' );
		$this->event( 'Two', '2099-02-01 19:00:00' );
		$this->event( 'Three', '2099-03-01 19:00:00' );

		$this->assertSame( array( 'One', 'Two' ), $this->titles( Chess_Army_Knife_Events::query( array( 'limit' => 2 ) ) ) );
		$this->assertSame( array( 'One', 'Two' ), $this->titles( Chess_Army_Knife_Events::query( array( 'before' => '2099-03-01 00:00:00' ) ) ) );
		$this->assertCount( 3, Chess_Army_Knife_Events::query( array( 'limit' => 0 ) ) );
	}

	public function test_tags_filter_and_appear_in_the_data() {
		$blitz = $this->event( 'Blitz night', '2099-01-01 19:00:00' );
		$this->event( 'Lecture', '2099-01-02 19:00:00' );
		wp_set_object_terms( $blitz, array( 'Blitz', 'Social' ), Chess_Army_Knife_Events::TAXONOMY );

		$events = Chess_Army_Knife_Events::query( array( 'tags' => array( 'blitz' ) ) );

		$this->assertSame( array( 'Blitz night' ), $this->titles( $events ) );
		$this->assertEqualsCanonicalizing( array( 'Blitz', 'Social' ), wp_list_pluck( $events[0]['tags'], 'name' ) );
		$this->assertSame( array(), Chess_Army_Knife_Events::query( array( 'tags' => array( 'nonexistent' ) ) ) );
	}

	public function test_location_falls_back_to_the_default() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'club_venue'      => 'The Village Hall',
			)
		);
		$this->event( 'Usual place', '2099-01-01 19:00:00' );
		$this->event( 'Away day', '2099-01-02 19:00:00', array( Chess_Army_Knife_Events::META_LOCATION => 'Town Library' ) );

		$events = Chess_Army_Knife_Events::query();

		$this->assertSame( 'The Village Hall', $events[0]['location'] );
		$this->assertSame( 'Town Library', $events[1]['location'] );
	}

	public function test_attached_tournaments_and_leagues_are_in_the_data() {
		$kept    = Chess_Army_Knife_Tournaments::create( array( 'name' => 'Club Championship' ) );
		$deleted = Chess_Army_Knife_Tournaments::create( array( 'name' => 'Withdrawn Cup' ) );
		$this->event(
			'Championship night',
			'2099-01-01 19:00:00',
			array(
				Chess_Army_Knife_Events::META_TOURNAMENTS => array( $kept, $deleted ),
				Chess_Army_Knife_Events::META_LEAGUES     => array( '613|Division 1', 'garbage' ),
			)
		);
		Chess_Army_Knife_Tournament_Store::delete_tournament( $deleted );

		$event = Chess_Army_Knife_Events::query()[0];

		$this->assertCount( 1, $event['tournaments'], 'A deleted tournament is skipped.' );
		$this->assertSame( 'Club Championship', $event['tournaments'][0]['name'] );
		$this->assertSame( '', $event['tournaments'][0]['url'], 'No page yet, so no link.' );
		$this->assertSame(
			array(
				array(
					'org'   => '613',
					'event' => 'Division 1',
				),
			),
			$event['leagues']
		);
	}

	public function test_a_tournament_links_to_its_page_only_once_published() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id      = Chess_Army_Knife_Tournaments::create( array( 'name' => 'Club Championship' ) );
		$page_id = Chess_Army_Knife_Tournaments::create_page( $id );
		$this->assertIsInt( $page_id );
		$this->event( 'Night', '2099-01-01 19:00:00', array( Chess_Army_Knife_Events::META_TOURNAMENTS => array( $id ) ) );

		$draft = Chess_Army_Knife_Events::query()[0];
		$this->assertSame( '', $draft['tournaments'][0]['url'], 'A draft page is not linked.' );

		wp_update_post(
			array(
				'ID'          => $page_id,
				'post_status' => 'publish',
			)
		);

		$published = Chess_Army_Knife_Events::query()[0];
		$this->assertSame( get_permalink( $page_id ), $published['tournaments'][0]['url'] );
	}

	/**
	 * Submit the details box as a user.
	 *
	 * @param int   $user_id User to act as.
	 * @param int   $post_id Event.
	 * @param array $fields  Submitted fields.
	 */
	private function submit_details( $user_id, $post_id, array $fields ) {
		wp_set_current_user( $user_id );
		$_POST = array_merge(
			array( Chess_Army_Knife_Events_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Events_Admin::NONCE_ACTION ) ),
			$fields
		);
		Chess_Army_Knife_Events_Admin::save( $post_id, get_post( $post_id ) );
	}

	public function test_saving_the_details_box_stores_the_event() {
		Chess_Army_Knife_Teams::assign_league_entries(
			array(
				array(
					'org'   => '613',
					'event' => 'Division 1',
					'team'  => 'Our A',
				),
			)
		);
		$tournament = Chess_Army_Knife_Tournaments::create( array( 'name' => 'Club Championship' ) );
		$id         = $this->event( 'Night', '' );

		$this->submit_details(
			self::factory()->user->create( array( 'role' => 'administrator' ) ),
			$id,
			array(
				'chess_army_event_date'        => '2099-10-05',
				'chess_army_event_start_time'  => '19:30',
				'chess_army_event_end_time'    => '22:00',
				'chess_army_event_location'    => ' Town Library ',
				'chess_army_event_tournaments' => array( (string) $tournament, '99999' ),
				'chess_army_event_leagues'     => array( '613|Division 1', '999|Not ours' ),
			)
		);

		$this->assertSame( '2099-10-05 19:30:00', get_post_meta( $id, Chess_Army_Knife_Events::META_START, true ) );
		$this->assertSame( '2099-10-05 22:00:00', get_post_meta( $id, Chess_Army_Knife_Events::META_END, true ) );
		$this->assertSame( 'Town Library', get_post_meta( $id, Chess_Army_Knife_Events::META_LOCATION, true ) );
		$this->assertSame( array( $tournament ), get_post_meta( $id, Chess_Army_Knife_Events::META_TOURNAMENTS, true ), 'An unknown tournament is ignored.' );
		$this->assertSame( array( '613|Division 1' ), get_post_meta( $id, Chess_Army_Knife_Events::META_LEAGUES, true ), 'A league outside the club teams is ignored.' );
	}

	public function test_an_end_time_not_after_the_start_is_dropped() {
		$id = $this->event( 'Night', '' );

		$this->submit_details(
			self::factory()->user->create( array( 'role' => 'administrator' ) ),
			$id,
			array(
				'chess_army_event_date'       => '2099-10-05',
				'chess_army_event_start_time' => '19:30',
				'chess_army_event_end_time'   => '18:00',
			)
		);

		$this->assertSame( '2099-10-05 19:30:00', get_post_meta( $id, Chess_Army_Knife_Events::META_START, true ) );
		$this->assertSame( '', get_post_meta( $id, Chess_Army_Knife_Events::META_END, true ) );
	}

	public function test_events_and_their_tags_have_no_pages_of_their_own() {
		$this->assertFalse( is_post_type_viewable( Chess_Army_Knife_Events::POST_TYPE ) );
		$this->assertFalse( is_taxonomy_viewable( Chess_Army_Knife_Events::TAXONOMY ) );
	}

	public function test_a_repeating_event_appears_once_for_each_occurrence_in_the_window() {
		$id = $this->event(
			'Club night',
			'2099-10-05 19:00:00',
			array(
				Chess_Army_Knife_Events::META_END    => '2099-10-05 22:00:00',
				Chess_Army_Knife_Events::META_REPEAT => 'weekly',
				Chess_Army_Knife_Events::META_UNTIL  => '2099-10-19',
			)
		);
		$this->event( 'Quiz', '2099-10-10 19:00:00' );

		$events = Chess_Army_Knife_Events::query(
			array(
				'after' => '2099-10-06 00:00:00',
				'limit' => 0,
			)
		);

		$this->assertSame( array( 'Quiz', 'Club night', 'Club night' ), $this->titles( $events ) );
		$this->assertSame( '2099-10-12 19:00:00', $events[1]['start'] );
		$this->assertSame( '2099-10-12 22:00:00', $events[1]['end'], 'Each occurrence lasts as long as the first.' );
		$this->assertSame( '2099-10-19 19:00:00', $events[2]['start'], 'The stop date is the last day.' );
		$this->assertSame( $id, $events[2]['id'] );
		$this->assertSame( 'weekly', $events[2]['repeat'] );
	}

	public function test_a_repeating_event_with_no_stop_date_is_limited_by_the_query() {
		$this->event(
			'Club night',
			'2099-10-05 19:00:00',
			array( Chess_Army_Knife_Events::META_REPEAT => 'weekly' )
		);

		$events = Chess_Army_Knife_Events::query( array( 'limit' => 3 ) );

		$this->assertCount( 3, $events );
		$this->assertSame( array( '2099-10-05 19:00:00', '2099-10-12 19:00:00', '2099-10-19 19:00:00' ), wp_list_pluck( $events, 'start' ) );
	}

	public function test_saving_stores_the_repeat_and_stop_date() {
		$id = $this->event( 'Night', '' );

		$this->submit_details(
			self::factory()->user->create( array( 'role' => 'administrator' ) ),
			$id,
			array(
				'chess_army_event_date'       => '2099-10-05',
				'chess_army_event_start_time' => '19:30',
				'chess_army_event_repeat'     => 'monthly',
				'chess_army_event_until'      => '2100-03-01',
			)
		);

		$this->assertSame( 'monthly', get_post_meta( $id, Chess_Army_Knife_Events::META_REPEAT, true ) );
		$this->assertSame( '2100-03-01', get_post_meta( $id, Chess_Army_Knife_Events::META_UNTIL, true ) );
	}

	public function test_a_stop_date_is_dropped_unless_the_event_repeats_and_it_is_not_before_the_start() {
		$id    = $this->event( 'Night', '' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$base  = array(
			'chess_army_event_date'       => '2099-10-05',
			'chess_army_event_start_time' => '19:30',
		);

		$this->submit_details(
			$admin,
			$id,
			$base + array(
				'chess_army_event_repeat' => '',
				'chess_army_event_until'  => '2100-03-01',
			)
		);
		$this->assertSame( '', get_post_meta( $id, Chess_Army_Knife_Events::META_UNTIL, true ), 'A one-off has no stop date.' );

		$this->submit_details(
			$admin,
			$id,
			$base + array(
				'chess_army_event_repeat' => 'weekly',
				'chess_army_event_until'  => '2099-10-01',
			)
		);
		$this->assertSame( '', get_post_meta( $id, Chess_Army_Knife_Events::META_UNTIL, true ), 'A stop date before the start is dropped.' );

		$this->submit_details( $admin, $id, $base + array( 'chess_army_event_repeat' => 'daily' ) );
		$this->assertSame( '', get_post_meta( $id, Chess_Army_Knife_Events::META_REPEAT, true ), 'An unknown repeat is dropped.' );
	}

	public function test_an_event_links_to_its_page_only_once_published() {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$this->event( 'Night', '2099-01-01 19:00:00', array( Chess_Army_Knife_Events::META_PAGE => $page ) );

		$this->assertSame( '', Chess_Army_Knife_Events::query()[0]['url'], 'A draft page is not linked.' );

		wp_update_post(
			array(
				'ID'          => $page,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( get_permalink( $page ), Chess_Army_Knife_Events::query()[0]['url'] );
	}

	public function test_saving_can_attach_a_page_or_create_a_draft_one() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$post  = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$id    = $this->event( 'Night', '' );
		$base  = array(
			'chess_army_event_date'       => '2099-10-05',
			'chess_army_event_start_time' => '19:30',
		);

		$this->submit_details( $admin, $id, $base + array( 'chess_army_event_page' => (string) $page ) );
		$this->assertSame( $page, (int) get_post_meta( $id, Chess_Army_Knife_Events::META_PAGE, true ) );

		$this->submit_details( $admin, $id, $base + array( 'chess_army_event_page' => (string) $post ) );
		$this->assertSame( '', get_post_meta( $id, Chess_Army_Knife_Events::META_PAGE, true ), 'Only a page can be attached.' );

		$this->submit_details(
			$admin,
			$id,
			$base + array(
				'chess_army_event_page'        => '0',
				'chess_army_event_create_page' => '1',
			)
		);
		$created = (int) get_post_meta( $id, Chess_Army_Knife_Events::META_PAGE, true );
		$this->assertGreaterThan( 0, $created );
		$this->assertSame( 'page', get_post_type( $created ) );
		$this->assertSame( 'draft', get_post_status( $created ) );
		$this->assertSame( 'Night', get_the_title( $created ) );
	}

	public function test_an_invalid_date_clears_the_start_so_the_event_is_not_listed() {
		$id = $this->event( 'Night', '2099-01-01 19:00:00' );

		$this->submit_details(
			self::factory()->user->create( array( 'role' => 'administrator' ) ),
			$id,
			array(
				'chess_army_event_date'       => '2099-02-30',
				'chess_army_event_start_time' => '19:30',
			)
		);

		$this->assertSame( '', get_post_meta( $id, Chess_Army_Knife_Events::META_START, true ) );
		$this->assertSame( array(), Chess_Army_Knife_Events::query() );
	}

	public function test_saving_without_a_valid_nonce_changes_nothing() {
		$id = $this->event( 'Night', '2099-01-01 19:00:00' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = array(
			Chess_Army_Knife_Events_Admin::NONCE_FIELD => 'forged',
			'chess_army_event_date'                    => '2099-12-31',
			'chess_army_event_start_time'              => '10:00',
		);
		Chess_Army_Knife_Events_Admin::save( $id, get_post( $id ) );

		$this->assertSame( '2099-01-01 19:00:00', get_post_meta( $id, Chess_Army_Knife_Events::META_START, true ) );
	}

	public function test_a_user_who_cannot_edit_the_event_cannot_change_it() {
		$id = $this->event( 'Night', '2099-01-01 19:00:00' );

		$this->submit_details(
			self::factory()->user->create( array( 'role' => 'subscriber' ) ),
			$id,
			array(
				'chess_army_event_date'       => '2099-12-31',
				'chess_army_event_start_time' => '10:00',
			)
		);

		$this->assertSame( '2099-01-01 19:00:00', get_post_meta( $id, Chess_Army_Knife_Events::META_START, true ) );
	}

	public function test_available_leagues_lists_each_division_once() {
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
				array(
					'org'   => '613',
					'event' => 'Division 2',
					'team'  => 'Our C',
				),
			)
		);

		$this->assertSame( array( '613|Division 1', '613|Division 2' ), wp_list_pluck( Chess_Army_Knife_Events_Admin::available_leagues(), 'ref' ) );
	}

	public function test_admin_list_sorts_by_date_and_keeps_undated_events() {
		set_current_screen( 'edit-' . Chess_Army_Knife_Events::POST_TYPE );
		$this->event( 'Undated', '' );
		$this->event( 'Earlier', '2099-01-01 19:00:00' );
		$this->event( 'Later', '2099-02-01 19:00:00' );

		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Make this the main query.
		$query->set( 'post_type', Chess_Army_Knife_Events::POST_TYPE );
		$query->set( 'post_status', 'publish' );
		Chess_Army_Knife_Events_Admin::sort_list( $query );

		// Newest first, and an event with no date is still shown.
		$this->assertSame( array( 'Later', 'Earlier', 'Undated' ), wp_list_pluck( $query->get_posts(), 'post_title' ) );
	}

	public function test_setup_makes_weekly_events_once() {
		$rows = array(
			array(
				'key'     => 'club_night',
				'title'   => 'Club night',
				'tag'     => 'Club night',
				'weekday' => 2,
				'start'   => '19:30',
				'end'     => '22:00',
			),
		);

		// 2099-10-01 is a Thursday, so the next Tuesday is the 6th.
		$this->assertSame( 1, Chess_Army_Knife_Setup::create_events( $rows, '2099-10-01' ) );
		$this->assertSame( 0, Chess_Army_Knife_Setup::create_events( $rows, '2099-10-01' ), 'Running setup again does not repeat it.' );

		$events = Chess_Army_Knife_Events::query( array( 'limit' => 2 ) );
		$this->assertSame( 'Club night', $events[0]['title'] );
		$this->assertSame( 'weekly', $events[0]['repeat'] );
		$this->assertSame( '2099-10-06 19:30:00', $events[0]['start'] );
		$this->assertSame( '2099-10-06 22:00:00', $events[0]['end'] );
		$this->assertSame( '2099-10-13 19:30:00', $events[1]['start'] );
		$this->assertSame( array( 'Club night' ), wp_list_pluck( $events[0]['tags'], 'name' ) );
	}

	public function test_setup_saves_the_club_details_and_keeps_other_settings() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'member_retention_months' => 36,
			)
		);

		Chess_Army_Knife_Settings::save(
			array(
				'club_name'      => 'Central Birmingham Chess Club',
				'club_venue'     => 'The Hall',
				'default_org_id' => '702',
				'lms_api_key'    => 'lmsk_x',
			)
		);

		$options = Chess_Army_Knife_Settings::get_options();
		$this->assertSame( 'Central Birmingham Chess Club', $options['club_name'] );
		$this->assertSame( 'The Hall', Chess_Army_Knife_Events::default_location() );
		$this->assertSame( '702', $options['default_org_id'] );
		$this->assertSame( 36, $options['member_retention_months'] );
		$this->assertSame( 0, $options['use_local_cache'] );
	}

	public function test_saving_stores_an_events_colour_or_clears_it() {
		$id    = $this->event( 'Night', '' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$base  = array(
			'chess_army_event_date'       => '2099-10-05',
			'chess_army_event_start_time' => '19:30',
		);

		$this->submit_details( $admin, $id, $base + array( 'chess_army_event_colour' => '#d62a2a' ) );
		$this->assertSame( '#d62a2a', get_post_meta( $id, Chess_Army_Knife_Events::META_COLOUR, true ) );
		$this->assertSame( '#d62a2a', Chess_Army_Knife_Events::data( get_post( $id ) )['colour'] );

		$this->submit_details( $admin, $id, $base + array( 'chess_army_event_colour' => 'red;x' ) );
		$this->assertSame( '', get_post_meta( $id, Chess_Army_Knife_Events::META_COLOUR, true ), 'Only a hex colour is kept.' );

		$this->submit_details(
			$admin,
			$id,
			$base + array(
				'chess_army_event_colour'         => '#d62a2a',
				'chess_army_event_colour_default' => '1',
			)
		);
		$this->assertSame( '', get_post_meta( $id, Chess_Army_Knife_Events::META_COLOUR, true ), 'The default colour box clears it.' );
	}
}
