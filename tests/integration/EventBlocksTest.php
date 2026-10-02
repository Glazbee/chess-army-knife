<?php
/**
 * Integration tests: the Next Club Event and Club Event Calendar blocks.
 *
 * @package Chess_Army_Knife
 */

class EventBlocksTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( 'games', 'entries', 'tournaments', 'members' ) as $name ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Tournament_Store::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Tournament_Store::install_tables();
	}

	/**
	 * Create a published event.
	 *
	 * @param string   $title Title.
	 * @param string   $start Site-local "Y-m-d H:i:s".
	 * @param string[] $tags  Tag names.
	 * @param array    $meta  Other meta.
	 * @return int
	 */
	private function event( $title, $start, array $tags = array(), array $meta = array() ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, Chess_Army_Knife_Events::META_START, $start );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		if ( $tags ) {
			wp_set_object_terms( $id, $tags, Chess_Army_Knife_Events::TAXONOMY );
		}
		return $id;
	}

	private function render( $block, array $attributes = array() ) {
		return do_blocks( '<!-- wp:chess-army-knife/' . $block . ' ' . wp_json_encode( (object) $attributes ) . ' /-->' );
	}

	public function test_next_event_shows_the_soonest_upcoming_event() {
		$this->event( 'Ancient', '2000-01-01 19:00:00' );
		$this->event( 'Later', '2099-02-01 19:00:00' );
		$this->event( 'Sooner', '2099-01-01 19:30:00', array(), array( Chess_Army_Knife_Events::META_LOCATION => 'Town Library' ) );

		$html = $this->render( 'next-club-event' );

		$this->assertStringContainsString( 'Sooner', $html );
		$this->assertStringContainsString( 'Town Library', $html );
		$this->assertStringNotContainsString( 'Later', $html );
		$this->assertStringNotContainsString( 'Ancient', $html );
	}

	public function test_next_event_can_show_three_events() {
		foreach ( array( 'One', 'Two', 'Three', 'Four' ) as $i => $title ) {
			$this->event( 'Night ' . $title, '2099-01-0' . ( $i + 1 ) . ' 19:00:00' );
		}

		$html = $this->render( 'next-club-event', array( 'show' => 'three' ) );

		$this->assertStringContainsString( 'Night One', $html );
		$this->assertStringContainsString( 'Night Three', $html );
		$this->assertStringNotContainsString( 'Night Four', $html );
	}

	public function test_next_event_can_show_today_and_tomorrow_only() {
		$today = current_time( 'Y-m-d' );
		$day   = function ( $offset ) use ( $today ) {
			return gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) + $offset * DAY_IN_SECONDS );
		};
		$this->event( 'Earlier today', $today . ' 00:01:00' );
		$this->event( 'Tomorrow night', $day( 1 ) . ' 19:00:00' );
		$this->event( 'The day after', $day( 2 ) . ' 00:00:00' );

		$html = $this->render( 'next-club-event', array( 'show' => 'today' ) );

		$this->assertStringContainsString( 'Earlier today', $html );
		$this->assertStringContainsString( 'Tomorrow night', $html );
		$this->assertStringNotContainsString( 'The day after', $html );
	}

	public function test_a_cancelled_event_says_so_and_has_no_calendar_link() {
		$this->event(
			'Club night',
			'2099-01-01 19:00:00',
			array(),
			array(
				Chess_Army_Knife_Events::META_STATUS      => 'cancelled',
				Chess_Army_Knife_Events::META_STATUS_NOTE => 'Hall closed',
			)
		);
		$this->event( 'Blitz', '2099-01-02 19:00:00' );

		$html = $this->render( 'next-club-event', array( 'show' => 'three' ) );

		$this->assertStringContainsString( 'Cancelled: Hall closed', $html );
		$this->assertSame( 1, substr_count( $html, 'Add to my calendar' ), 'Only the event going ahead can be added.' );
	}

	public function test_skipped_dates_are_left_out_of_a_repeating_event() {
		$this->event(
			'Weekly club night',
			'2099-01-05 19:00:00',
			array(),
			array(
				Chess_Army_Knife_Events::META_REPEAT => 'weekly',
				Chess_Army_Knife_Events::META_SKIP   => array( '2099-01-12' ),
			)
		);

		$starts = wp_list_pluck( Chess_Army_Knife_Events::query( array( 'limit' => 3 ) ), 'start' );

		$this->assertSame( array( '2099-01-05 19:00:00', '2099-01-19 19:00:00', '2099-01-26 19:00:00' ), $starts );
	}

	public function test_one_occurrence_can_be_fetched_for_the_calendar_link() {
		$id = $this->event(
			'Weekly club night',
			'2099-01-05 19:00:00',
			array(),
			array(
				Chess_Army_Knife_Events::META_REPEAT => 'weekly',
				Chess_Army_Knife_Events::META_SKIP   => array( '2099-01-12' ),
			)
		);

		$this->assertSame( '2099-01-19 19:00:00', Chess_Army_Knife_Events::get_occurrence( $id, '2099-01-19 19:00:00' )['start'] );
		$this->assertNull( Chess_Army_Knife_Events::get_occurrence( $id, '2099-01-12 19:00:00' ), 'A skipped date.' );
		$this->assertNull( Chess_Army_Knife_Events::get_occurrence( $id, '2099-01-13 19:00:00' ), 'Not a date it happens.' );
		$this->assertNull( Chess_Army_Knife_Events::get_occurrence( 0, '2099-01-05 19:00:00' ) );
		$this->assertNull( Chess_Army_Knife_Events::get_occurrence( self::factory()->post->create(), '2099-01-05 19:00:00' ), 'Not an event.' );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		$this->assertNull( Chess_Army_Knife_Events::get_occurrence( $id, '2099-01-05 19:00:00' ), 'Not published.' );
	}

	public function test_next_event_can_be_limited_to_a_tag() {
		$this->event( 'Open tournament', '2099-01-01 19:00:00', array( 'Tournament' ) );
		$this->event( 'Club night', '2099-01-08 19:00:00', array( 'In-house', 'Social' ) );
		$this->event( 'Club blitz', '2099-01-15 19:00:00', array( 'In-house' ) );

		$everything = $this->render( 'next-club-event' );
		$in_house   = $this->render( 'next-club-event', array( 'tags' => array( 'in-house' ) ) );

		$this->assertStringContainsString( 'Open tournament', $everything );
		$this->assertStringContainsString( 'Club night', $in_house );
		$this->assertStringNotContainsString( 'Open tournament', $in_house );
		$this->assertStringNotContainsString( 'Club blitz', $in_house, 'Only the next one is shown.' );
	}

	public function test_next_event_says_so_when_there_is_none_for_the_tag() {
		$this->event( 'Open tournament', '2099-01-01 19:00:00', array( 'Tournament' ) );

		$default = $this->render( 'next-club-event', array( 'tags' => array( 'in-house' ) ) );
		$custom  = $this->render(
			'next-club-event',
			array(
				'tags'         => array( 'in-house' ),
				'emptyMessage' => 'Nothing planned yet!',
			)
		);

		$this->assertStringContainsString( 'No upcoming events.', $default );
		$this->assertStringContainsString( 'Nothing planned yet!', $custom );
		$this->assertStringNotContainsString( 'Open tournament', $default );
	}

	public function test_next_event_uses_the_default_location_and_can_hide_details() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'club_venue'      => 'The Village Hall',
			)
		);
		$this->event( 'Club night', '2099-01-01 19:00:00', array( 'In-house' ) );

		$shown  = $this->render( 'next-club-event' );
		$hidden = $this->render(
			'next-club-event',
			array(
				'showLocation' => false,
				'showTags'     => false,
			)
		);

		$this->assertStringContainsString( 'The Village Hall', $shown );
		$this->assertStringContainsString( 'In-house', $shown );
		$this->assertStringNotContainsString( 'The Village Hall', $hidden );
		$this->assertStringNotContainsString( 'cak-event__tag', $hidden );
	}

	public function test_event_titles_are_escaped() {
		$this->event( '<script>alert(1)</script> night', '2099-01-01 19:00:00' );

		$html = $this->render( 'next-club-event' );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_calendar_groups_events_by_date_including_same_night_and_same_time() {
		$this->event( 'Coaching', '2099-03-01 18:00:00' );
		$this->event( 'Rapidplay', '2099-03-01 19:30:00' );
		$this->event( 'Club night', '2099-03-01 19:30:00' );
		$this->event( 'Quiz', '2099-03-08 19:30:00' );

		$html = $this->render( 'club-event-calendar' );

		$this->assertSame( 2, substr_count( $html, 'class="cak-calendar__date"' ), 'One heading per date.' );
		$this->assertLessThan( strpos( $html, 'Club night' ), strpos( $html, 'Coaching' ) );
		$this->assertLessThan( strpos( $html, 'Rapidplay' ), strpos( $html, 'Club night' ), 'Same start time falls back to title order.' );
		$this->assertLessThan( strpos( $html, 'Quiz' ), strpos( $html, 'Rapidplay' ) );
	}

	public function test_calendar_filters_by_tag_and_respects_the_count() {
		$this->event( 'Night one', '2099-01-01 19:00:00', array( 'In-house' ) );
		$this->event( 'Night two', '2099-01-08 19:00:00', array( 'In-house' ) );
		$this->event( 'Night three', '2099-01-15 19:00:00', array( 'In-house' ) );
		$this->event( 'Open', '2099-01-02 19:00:00', array( 'Tournament' ) );

		$html = $this->render(
			'club-event-calendar',
			array(
				'tags'  => array( 'in-house' ),
				'count' => 2,
			)
		);

		$this->assertStringContainsString( 'Night one', $html );
		$this->assertStringContainsString( 'Night two', $html );
		$this->assertStringNotContainsString( 'Night three', $html );
		$this->assertStringNotContainsString( 'Open', $html );
	}

	public function test_calendar_shows_a_message_when_empty() {
		$this->assertStringContainsString( 'No upcoming events.', $this->render( 'club-event-calendar' ) );
	}

	public function test_calendar_lists_attached_tournaments_and_leagues() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$tournament = Chess_Army_Knife_Tournaments::create( array( 'name' => 'Club Championship' ) );
		$this->event(
			'Championship night',
			'2099-01-01 19:00:00',
			array(),
			array(
				Chess_Army_Knife_Events::META_TOURNAMENTS => array( $tournament ),
				Chess_Army_Knife_Events::META_LEAGUES     => array( '613|Division 1' ),
			)
		);

		$shown  = $this->render( 'club-event-calendar' );
		$hidden = $this->render( 'club-event-calendar', array( 'showLinks' => false ) );

		$this->assertStringContainsString( 'Club Championship', $shown );
		$this->assertStringContainsString( 'Division 1', $shown );
		$this->assertStringNotContainsString( 'Club Championship', $hidden );
	}

	public function test_the_details_block_finds_the_event_attached_to_its_page() {
		$page  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$other = $this->event( 'Not this one', '2099-03-01 19:00:00' );
		$event = $this->event(
			'Summer Blitz',
			'2099-02-01 19:30:00',
			array( 'Blitz' ),
			array(
				Chess_Army_Knife_Events::META_PAGE     => $page,
				Chess_Army_Knife_Events::META_LOCATION => 'The Library',
			)
		);
		$this->assertSame( $event, Chess_Army_Knife_Events::event_for_page( $page ) );
		$this->assertSame( 0, Chess_Army_Knife_Events::event_for_page( 0 ) );
		$this->assertNotSame( $other, Chess_Army_Knife_Events::event_for_page( $page ) );

		$GLOBALS['post'] = get_post( $page ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The block reads the page being viewed.
		setup_postdata( $GLOBALS['post'] );
		$html = $this->render( 'club-event-details' );

		$this->assertStringContainsString( 'The Library', $html );
		$this->assertStringContainsString( 'Blitz', $html );
		$this->assertStringContainsString( 'Add to my calendar', $html );
		$this->assertStringNotContainsString( 'Not this one', $html );
	}

	public function test_the_details_block_shows_a_chosen_event_and_says_nothing_to_visitors_when_there_is_none() {
		$event = $this->event( 'Chosen', '2099-02-01 19:30:00', array(), array( Chess_Army_Knife_Events::META_LOCATION => 'Chosen Hall' ) );

		$this->assertStringContainsString( 'Chosen Hall', $this->render( 'club-event-details', array( 'eventId' => $event ) ) );

		$page            = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$GLOBALS['post'] = get_post( $page ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The block reads the page being viewed.
		setup_postdata( $GLOBALS['post'] );

		wp_set_current_user( 0 );
		$this->assertSame( '', trim( $this->render( 'club-event-details' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertStringContainsString( 'no event is attached', $this->render( 'club-event-details' ) );
	}

	public function test_a_draft_event_is_not_shown_by_the_details_block() {
		$event = $this->event( 'Secret', '2099-02-01 19:30:00' );
		wp_update_post(
			array(
				'ID'          => $event,
				'post_status' => 'draft',
			)
		);

		$this->assertNull( Chess_Army_Knife_Events::details( $event ) );
		$this->assertNull( Chess_Army_Knife_Events::details( 0 ) );
	}

	public function test_the_details_of_a_repeating_event_are_for_its_next_date() {
		$event = $this->event(
			'Weekly night',
			'2020-01-06 19:00:00',
			array(),
			array(
				Chess_Army_Knife_Events::META_REPEAT => 'weekly',
				Chess_Army_Knife_Events::META_SKIP   => array(),
			)
		);

		$details = Chess_Army_Knife_Events::details( $event );

		$this->assertGreaterThanOrEqual( current_time( 'Y-m-d' ), substr( $details['start'], 0, 10 ) );
		$this->assertSame( '19:00:00', substr( $details['start'], 11 ) );
	}
}
