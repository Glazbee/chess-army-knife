<?php
/**
 * Tests for the pure helpers of Chess_Army_Knife_Events.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class EventsTest extends Chess_Army_Knife_TestCase {

	public function test_combine_datetime_joins_a_valid_date_and_time() {
		$this->assertSame( '2026-10-05 19:30:00', Chess_Army_Knife_Events::combine_datetime( '2026-10-05', '19:30' ) );
		$this->assertSame( '2026-02-28 00:00:00', Chess_Army_Knife_Events::combine_datetime( '2026-02-28', '00:00' ) );
	}

	/**
	 * @dataProvider invalid_datetimes
	 */
	public function test_combine_datetime_rejects_invalid_input( $date, $time ) {
		$this->assertSame( '', Chess_Army_Knife_Events::combine_datetime( $date, $time ) );
	}

	public function invalid_datetimes() {
		return array(
			'empty date'          => array( '', '19:30' ),
			'empty time'          => array( '2026-10-05', '' ),
			'not a date'          => array( 'tomorrow', '19:30' ),
			'impossible date'     => array( '2026-02-30', '19:30' ),
			'wrong date format'   => array( '05/10/2026', '19:30' ),
			'hour out of range'   => array( '2026-10-05', '24:00' ),
			'minute out of range' => array( '2026-10-05', '19:60' ),
			'with seconds'        => array( '2026-10-05', '19:30:00' ),
			'not a time'          => array( '2026-10-05', 'noon' ),
		);
	}

	public function test_to_timestamp_uses_the_site_timezone() {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'Europe/London' ) );

		// BST (UTC+1) in October before the clocks change; GMT in December.
		$this->assertSame( gmmktime( 18, 30, 0, 10, 5, 2026 ), Chess_Army_Knife_Events::to_timestamp( '2026-10-05 19:30:00' ) );
		$this->assertSame( gmmktime( 19, 30, 0, 12, 5, 2026 ), Chess_Army_Knife_Events::to_timestamp( '2026-12-05 19:30:00' ) );
	}

	public function test_to_timestamp_returns_null_for_garbage() {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );

		$this->assertNull( Chess_Army_Knife_Events::to_timestamp( '' ) );
		$this->assertNull( Chess_Army_Knife_Events::to_timestamp( 'soon' ) );
	}

	public function test_league_references_round_trip() {
		$ref = Chess_Army_Knife_Events::league_ref( ' 613 ', ' Division 1 ' );

		$this->assertSame( '613|Division 1', $ref );
		$this->assertSame(
			array(
				'org'   => '613',
				'event' => 'Division 1',
			),
			Chess_Army_Knife_Events::parse_league_ref( $ref )
		);
	}

	public function test_league_reference_keeps_a_pipe_in_the_event_name() {
		$this->assertSame(
			array(
				'org'   => '7',
				'event' => 'Open | Section A',
			),
			Chess_Army_Knife_Events::parse_league_ref( '7|Open | Section A' )
		);
	}

	/**
	 * @dataProvider invalid_league_refs
	 */
	public function test_invalid_league_references_are_rejected( $ref ) {
		$this->assertNull( Chess_Army_Knife_Events::parse_league_ref( $ref ) );
	}

	public function invalid_league_refs() {
		return array(
			'no separator'  => array( '613 Division 1' ),
			'no org'        => array( '|Division 1' ),
			'non numeric'   => array( 'abc|Division 1' ),
			'no event name' => array( '613|' ),
			'blank'         => array( '' ),
		);
	}

	public function test_default_location_comes_from_settings() {
		$this->set_settings( array( 'club_venue' => 'The Village Hall' ) );

		$this->assertSame( 'The Village Hall', Chess_Army_Knife_Events::default_location() );
	}

	public function test_default_location_is_empty_until_set() {
		$this->assertSame( '', Chess_Army_Knife_Events::default_location() );
	}

	public function test_default_time_is_filterable_but_starts_at_seven() {
		$this->assertSame( '19:00', Chess_Army_Knife_Events::default_time() );
	}

	public function test_a_one_off_event_has_one_occurrence_if_in_the_window() {
		$first = '2026-10-05 19:00:00';

		$this->assertSame( array( $first ), Chess_Army_Knife_Events::occurrence_starts( $first, '', '', '2026-10-01 00:00:00', '2026-11-01 00:00:00' ) );
		$this->assertSame( array(), Chess_Army_Knife_Events::occurrence_starts( $first, '', '', '2026-10-06 00:00:00', '' ) );
		$this->assertSame( array(), Chess_Army_Knife_Events::occurrence_starts( $first, '', '', '', '2026-10-05 19:00:00' ), 'The end of the window is exclusive.' );
	}

	public function test_weekly_events_repeat_on_the_same_weekday() {
		$starts = Chess_Army_Knife_Events::occurrence_starts( '2026-10-05 19:00:00', 'weekly', '', '2026-10-10 00:00:00', '2026-11-02 00:00:00' );

		$this->assertSame( array( '2026-10-12 19:00:00', '2026-10-19 19:00:00', '2026-10-26 19:00:00' ), $starts );
	}

	public function test_a_stop_date_is_the_last_day_an_event_can_happen() {
		$starts = Chess_Army_Knife_Events::occurrence_starts( '2026-10-05 19:00:00', 'weekly', '2026-10-12', '', '2027-01-01 00:00:00' );

		$this->assertSame( array( '2026-10-05 19:00:00', '2026-10-12 19:00:00' ), $starts );
	}

	public function test_monthly_events_use_the_last_day_of_a_shorter_month() {
		$starts = Chess_Army_Knife_Events::occurrence_starts( '2026-01-31 18:30:00', 'monthly', '', '', '2026-05-01 00:00:00' );

		$this->assertSame( array( '2026-01-31 18:30:00', '2026-02-28 18:30:00', '2026-03-31 18:30:00', '2026-04-30 18:30:00' ), $starts );
	}

	public function test_annual_events_keep_their_date_and_move_a_leap_day() {
		$this->assertSame(
			array( '2027-03-14 10:00:00', '2028-03-14 10:00:00' ),
			Chess_Army_Knife_Events::occurrence_starts( '2026-03-14 10:00:00', 'annually', '', '2027-01-01 00:00:00', '2029-01-01 00:00:00' )
		);
		$this->assertSame(
			array( '2027-02-28 10:00:00', '2028-02-29 10:00:00' ),
			Chess_Army_Knife_Events::occurrence_starts( '2024-02-29 10:00:00', 'annually', '', '2027-01-01 00:00:00', '2029-01-01 00:00:00' )
		);
	}

	public function test_a_repeating_event_with_no_end_is_worked_out_a_year_ahead() {
		$starts = Chess_Army_Knife_Events::occurrence_starts( '2026-10-05 19:00:00', 'weekly', '', '2026-10-05 00:00:00', '' );

		$this->assertCount( 53, $starts );
		$this->assertSame( '2026-10-05 19:00:00', $starts[0] );
	}

	public function test_an_unknown_repeat_is_a_one_off_and_a_bad_start_has_no_occurrences() {
		$this->assertSame( array( '2026-10-05 19:00:00' ), Chess_Army_Knife_Events::occurrence_starts( '2026-10-05 19:00:00', 'daily', '', '', '' ) );
		$this->assertSame( array(), Chess_Army_Knife_Events::occurrence_starts( 'soon', 'weekly', '', '', '' ) );
	}

	public function test_a_tag_without_a_chosen_colour_gets_a_steady_one_from_the_palette() {
		Brain\Monkey\Functions\when( 'get_term_meta' )->justReturn( '' );
		Brain\Monkey\Functions\when( 'sanitize_hex_color' )->alias(
			function ( $colour ) {
				return preg_match( '/^#[0-9a-f]{6}$/i', $colour ) ? $colour : '';
			}
		);
		$term = (object) array(
			'term_id' => 5,
			'slug'    => 'club-night',
		);

		$colour = Chess_Army_Knife_Events::tag_colour( $term );

		$this->assertContains( $colour, Chess_Army_Knife_Events::tag_palette() );
		$this->assertSame( $colour, Chess_Army_Knife_Events::tag_colour( $term ), 'The same tag always has the same colour.' );
	}

	public function test_a_chosen_tag_colour_wins_and_an_invalid_one_is_ignored() {
		Brain\Monkey\Functions\when( 'sanitize_hex_color' )->alias(
			function ( $colour ) {
				return preg_match( '/^#[0-9a-f]{6}$/i', $colour ) ? $colour : '';
			}
		);
		$term = (object) array(
			'term_id' => 5,
			'slug'    => 'club-night',
		);

		Brain\Monkey\Functions\when( 'get_term_meta' )->justReturn( '#123456' );
		$this->assertSame( '#123456', Chess_Army_Knife_Events::tag_colour( $term ) );

		Brain\Monkey\Functions\when( 'get_term_meta' )->justReturn( 'red;x' );
		$this->assertContains( Chess_Army_Knife_Events::tag_colour( $term ), Chess_Army_Knife_Events::tag_palette() );
	}

	/**
	 * @dataProvider what3words
	 */
	public function test_what3words_addresses_are_cleaned( $raw, $expected ) {
		$this->assertSame( $expected, Chess_Army_Knife_Events::clean_what3words( $raw ) );
	}

	public function what3words() {
		return array(
			'plain'      => array( 'index.home.raft', 'index.home.raft' ),
			'slashes'    => array( '///Index.Home.Raft', 'index.home.raft' ),
			'link'       => array( 'https://what3words.com/index.home.raft', 'index.home.raft' ),
			'padded'     => array( '  index.home.raft ', 'index.home.raft' ),
			'accented'   => array( 'café.été.à', 'café.été.à' ),
			'two words'  => array( 'index.home', '' ),
			'four words' => array( 'a.b.c.d', '' ),
			'sentence'   => array( 'the village hall', '' ),
			'empty'      => array( '', '' ),
		);
	}

	public function test_map_links_must_be_web_addresses() {
		Brain\Monkey\Functions\when( 'esc_url_raw' )->alias(
			function ( $url ) {
				return preg_match( '#^https?://#', $url ) ? $url : '';
			}
		);

		$this->assertSame( 'https://maps.app.goo.gl/abc', Chess_Army_Knife_Events::clean_map_url( ' https://maps.app.goo.gl/abc ' ) );
		$this->assertSame( '', Chess_Army_Knife_Events::clean_map_url( 'javascript:alert(1)' ) );
	}

	public function test_skipped_dates_leave_out_those_occurrences_only() {
		$starts = Chess_Army_Knife_Events::occurrence_starts( '2026-10-05 19:00:00', 'weekly', '', '', '2026-10-27 00:00:00', array( '2026-10-12', '2026-12-25' ) );

		$this->assertSame( array( '2026-10-05 19:00:00', '2026-10-19 19:00:00', '2026-10-26 19:00:00' ), $starts );
	}

	public function test_typed_dates_are_cleaned_sorted_and_deduplicated() {
		$dates = Chess_Army_Knife_Events::parse_dates( "2026-12-28\n2026-12-25, nonsense 2026-02-30 2026-12-25;2026-1-1" );

		$this->assertSame( array( '2026-12-25', '2026-12-28' ), $dates );
	}
}
