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
}
