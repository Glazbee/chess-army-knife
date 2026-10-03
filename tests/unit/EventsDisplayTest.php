<?php
/**
 * Tests for Chess_Army_Knife_Events_Display.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class EventsDisplayTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->options['date_format'] = 'j F Y';
		$this->options['time_format'] = 'H:i';
		Functions\when( 'wp_date' )->alias(
			function ( $format, $timestamp ) {
				return gmdate( $format, $timestamp );
			}
		);
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );
		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' );
			}
		);
	}

	private function event( array $overrides = array() ) {
		return array_merge(
			array(
				'title'       => 'Club night',
				'url'         => 'https://example.test/event',
				'start'       => '2026-10-05 19:30:00',
				'start_ts'    => gmmktime( 19, 30, 0, 10, 5, 2026 ),
				'end'         => '',
				'location'    => 'The Village Hall',
				'tags'        => array(),
				'tournaments' => array(),
				'leagues'     => array(),
				'teams'       => array(),
			),
			$overrides
		);
	}

	public function test_date_and_time_labels() {
		$event = $this->event();

		$this->assertSame( '5 October 2026', Chess_Army_Knife_Events_Display::date_label( $event ) );
		$this->assertSame( '19:30', Chess_Army_Knife_Events_Display::time_label( $event ) );
	}

	public function test_time_label_shows_the_end_time_when_there_is_one() {
		$event = $this->event( array( 'end' => '2026-10-05 22:00:00' ) );

		$this->assertSame( '19:30 – 22:00', Chess_Army_Knife_Events_Display::time_label( $event ) );
	}

	public function test_labels_are_empty_for_an_event_without_a_valid_start() {
		$event = $this->event(
			array(
				'start'    => '',
				'start_ts' => null,
			)
		);

		$this->assertSame( '', Chess_Army_Knife_Events_Display::date_label( $event ) );
		$this->assertSame( '', Chess_Army_Knife_Events_Display::time_label( $event ) );
	}

	public function test_tag_slugs_are_cleaned() {
		$this->assertSame(
			array( 'in-house', 'blitz' ),
			Chess_Army_Knife_Events_Display::tag_slugs( array( 'In House', '', 'blitz', '!!' ) )
		);
		$this->assertSame( array(), Chess_Army_Knife_Events_Display::tag_slugs( null ) );
	}

	public function test_options_are_on_unless_switched_off() {
		$this->assertSame(
			array(
				'show_location' => true,
				'show_tags'     => true,
				'show_links'    => true,
				'show_teams'    => true,
			),
			Chess_Army_Knife_Events_Display::options( array() )
		);
		$this->assertFalse( Chess_Army_Knife_Events_Display::options( array( 'showTags' => false ) )['show_tags'] );
	}

	public function test_details_show_location_links_and_tags_and_escape_them() {
		$event = $this->event(
			array(
				'location'    => 'Town <Library>',
				'tags'        => array(
					array(
						'name' => 'In-house',
						'slug' => 'in-house',
						'url'  => '',
					),
				),
				'tournaments' => array(
					array(
						'id'   => 1,
						'name' => 'Club <b>Championship</b>',
						'url'  => 'https://example.test/champ',
					),
					array(
						'id'   => 2,
						'name' => 'Blitz Cup',
						'url'  => '',
					),
				),
				'leagues'     => array(
					array(
						'org'   => '613',
						'event' => 'Division 1',
					),
				),
			)
		);

		$html = Chess_Army_Knife_Events_Display::details_html(
			$event,
			array(
				'show_location' => true,
				'show_tags'     => true,
				'show_links'    => true,
			)
		);

		$this->assertStringContainsString( 'Town &lt;Library&gt;', $html );
		$this->assertStringNotContainsString( '<Library>', $html );
		$this->assertStringContainsString( '<a href="https://example.test/champ">Club &lt;b&gt;Championship&lt;/b&gt;</a>', $html );
		$this->assertStringContainsString( 'Blitz Cup', $html );
		$this->assertStringNotContainsString( 'href=""', $html, 'A tournament without a page is not linked.' );
		$this->assertStringContainsString( 'Division 1', $html );
		$this->assertStringContainsString( '>In-house<', $html );
	}

	public function test_details_respect_the_display_options() {
		$event = $this->event(
			array(
				'tags'    => array(
					array(
						'name' => 'Blitz',
						'slug' => 'blitz',
						'url'  => '',
					),
				),
				'leagues' => array(
					array(
						'org'   => '613',
						'event' => 'Division 1',
					),
				),
			)
		);

		$html = Chess_Army_Knife_Events_Display::details_html(
			$event,
			array(
				'show_location' => false,
				'show_tags'     => false,
				'show_links'    => false,
			)
		);

		$this->assertSame( '', $html );
	}

	public function test_details_omit_an_empty_location() {
		$html = Chess_Army_Knife_Events_Display::details_html(
			$this->event( array( 'location' => '' ) ),
			array( 'show_location' => true )
		);

		$this->assertSame( '', $html );
	}

	public function test_parse_month() {
		$this->assertSame( array( 2026, 10 ), Chess_Army_Knife_Events_Display::parse_month( '2026-10' ) );
		$this->assertSame( array( 2026, 1 ), Chess_Army_Knife_Events_Display::parse_month( '2026-01' ) );

		foreach ( array( '2026-13', '2026-00', '2026-1', '26-10', '2026-10-05', 'October', '' ) as $bad ) {
			$this->assertNull( Chess_Army_Knife_Events_Display::parse_month( $bad ), $bad );
		}
	}

	public function test_shift_month_crosses_year_boundaries() {
		$this->assertSame( '2026-11', Chess_Army_Knife_Events_Display::shift_month( 2026, 10, 1 ) );
		$this->assertSame( '2027-01', Chess_Army_Knife_Events_Display::shift_month( 2026, 12, 1 ) );
		$this->assertSame( '2025-12', Chess_Army_Knife_Events_Display::shift_month( 2026, 1, -1 ) );
		$this->assertSame( '2024-03', Chess_Army_Knife_Events_Display::shift_month( 2026, 3, -24 ) );
	}

	public function test_month_weeks_start_on_the_chosen_day() {
		// 1 October 2026 is a Thursday.
		$monday = Chess_Army_Knife_Events_Display::month_weeks( 2026, 10, 1 );
		$sunday = Chess_Army_Knife_Events_Display::month_weeks( 2026, 10, 0 );

		$this->assertSame( array( null, null, null, 1, 2, 3, 4 ), $monday[0] );
		$this->assertSame( array( null, null, null, null, 1, 2, 3 ), $sunday[0] );
		$this->assertCount( 5, $monday );
		$this->assertSame( array( 26, 27, 28, 29, 30, 31, null ), $monday[4] );
	}

	public function test_month_weeks_covers_every_day_once_in_whole_weeks() {
		foreach ( array( array( 2026, 2 ), array( 2028, 2 ), array( 2026, 12 ), array( 2026, 5 ) ) as $ym ) {
			foreach ( range( 0, 6 ) as $start ) {
				$weeks = Chess_Army_Knife_Events_Display::month_weeks( $ym[0], $ym[1], $start );
				$days  = array_filter( array_merge( ...$weeks ) );

				$this->assertSame( (int) gmdate( 't', gmmktime( 0, 0, 0, $ym[1], 1, $ym[0] ) ), count( $days ) );
				$this->assertSame( range( 1, count( $days ) ), array_values( $days ) );
				foreach ( $weeks as $week ) {
					$this->assertCount( 7, $week );
				}
			}
		}
	}

	public function test_a_four_week_month_has_four_rows() {
		// February 2026 starts on a Sunday and has 28 days.
		$this->assertCount( 4, Chess_Army_Knife_Events_Display::month_weeks( 2026, 2, 0 ) );
		$this->assertCount( 5, Chess_Army_Knife_Events_Display::month_weeks( 2026, 2, 1 ) );
	}

	public function test_month_html_places_events_on_their_day_and_marks_today() {
		$this->options['start_of_week'] = 1;
		Functions\when( 'current_time' )->justReturn( '2026-10-05' );
		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_url' )->returnArg();

		$html = Chess_Army_Knife_Events_Display::month_html(
			2026,
			10,
			array(
				$this->event(
					array(
						'title'    => 'Club <night>',
						'url'      => 'https://example.test/night',
						'start'    => '2026-10-05 19:30:00',
						'location' => 'Town "Hall"',
					)
				),
			),
			array( 'show_location' => true )
		);

		$this->assertStringContainsString( '<caption class="cak-visually-hidden">October 2026</caption>', $html );
		$this->assertStringContainsString( '<th scope="col"><abbr title="Monday">Mon</abbr></th>', $html );
		$this->assertLessThan( strpos( $html, '<abbr title="Tuesday">Tue</abbr>' ), strpos( $html, '<abbr title="Monday">Mon</abbr>' ) );
		$this->assertStringContainsString( 'cak-month__day has-events is-today', $html );
		$this->assertStringContainsString( '<span class="cak-month__daynum"><span aria-hidden="true">5</span>', $html );
		$this->assertStringContainsString( '<span class="cak-visually-hidden">', $html );
		$this->assertStringContainsString( 'Club &lt;night&gt;', $html );
		$this->assertStringNotContainsString( '<night>', $html );
		// The bubble shows the time and title; the rest is in its panel.
		$this->assertStringContainsString( '<span class="cak-bubble__time">19:30</span> <span class="cak-bubble__title">Club &lt;night&gt;</span>', $html );
		$this->assertStringContainsString( '<p class="cak-event__location">Town &quot;Hall&quot;</p>', $html );
		$this->assertStringContainsString( '<p class="cak-bubble__when">5 October 2026, 19:30</p>', $html );
		$this->assertStringContainsString( '<a href="https://example.test/night">More about this event</a>', $html );
		$this->assertStringNotContainsString( 'title="Town', $html );
		// The event is in the table and, for a phone, in the list of days.
		$this->assertSame( 2, substr_count( $html, 'cak-month__event"' ) );
	}

	public function test_month_html_leaves_the_location_out_when_it_is_off() {
		Functions\when( 'current_time' )->justReturn( '2026-10-05' );
		Functions\when( 'esc_url' )->returnArg();

		$html = Chess_Army_Knife_Events_Display::month_html( 2026, 10, array( $this->event() ), array( 'show_location' => false ) );

		$this->assertStringNotContainsString( 'cak-event__location', $html );
	}

	public function test_an_event_without_a_page_has_no_link_in_its_bubble() {
		Functions\when( 'current_time' )->justReturn( '2026-10-05' );
		Functions\when( 'esc_url' )->returnArg();

		$html = Chess_Army_Knife_Events_Display::bubble_html( $this->event( array( 'url' => '' ) ), array() );

		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringContainsString( 'Club night', $html );
	}

	public function test_month_html_with_no_events_is_still_a_full_grid() {
		Functions\when( 'current_time' )->justReturn( '2026-09-01' );

		$html = Chess_Army_Knife_Events_Display::month_html( 2026, 10, array(), array() );

		$this->assertSame( 31, substr_count( $html, 'class="cak-month__daynum"' ) );
		$this->assertStringNotContainsString( 'has-events', $html );
	}

	private function team_event( array $teams ) {
		return $this->event( array( 'teams' => $teams ) );
	}

	private function side( $id, $name, $side, $colour = '' ) {
		return array(
			'id'     => $id,
			'name'   => $name,
			'colour' => $colour,
			'side'   => $side,
		);
	}

	public function test_a_fixtures_teams_are_labelled_with_their_side() {
		$this->assertSame( '', Chess_Army_Knife_Events_Display::team_label( $this->event() ) );
		$this->assertSame( 'Club A (home)', Chess_Army_Knife_Events_Display::team_label( $this->team_event( array( $this->side( 1, 'Club A', 'home' ) ) ) ) );
		$this->assertSame( 'Club A (home), Club B (away)', Chess_Army_Knife_Events_Display::team_label( $this->team_event( array( $this->side( 1, 'Club A', 'home' ), $this->side( 2, 'Club B', 'away' ) ) ) ) );
	}

	public function test_the_side_of_a_fixture() {
		$this->assertSame( '', Chess_Army_Knife_Events_Display::side_of_event( $this->event() ) );
		$this->assertSame( 'away', Chess_Army_Knife_Events_Display::side_of_event( $this->team_event( array( $this->side( 1, 'A', 'away' ) ) ) ) );
		$this->assertSame( 'derby', Chess_Army_Knife_Events_Display::side_of_event( $this->team_event( array( $this->side( 1, 'A', 'home' ), $this->side( 2, 'B', 'away' ) ) ) ) );
	}

	public function test_home_and_away_filters() {
		$home  = $this->team_event( array( $this->side( 1, 'A', 'home' ) ) );
		$away  = $this->team_event( array( $this->side( 1, 'A', 'away' ) ) );
		$other = $this->team_event( array( $this->side( 2, 'B', 'home' ) ) );
		$night = $this->event();
		$all   = array( $home, $away, $other, $night );

		$this->assertSame( $all, Chess_Army_Knife_Events_Display::filter_by_venue( $all, 'all' ) );
		$this->assertSame( array( $home, $other ), Chess_Army_Knife_Events_Display::filter_by_venue( $all, 'home' ), 'A club night is neither home nor away.' );
		$this->assertSame( array( $away ), Chess_Army_Knife_Events_Display::filter_by_venue( $all, 'away' ) );
		$this->assertSame( array( $home ), Chess_Army_Knife_Events_Display::filter_by_venue( $all, 'home', array( 1 ) ), 'Only the chosen teams count.' );
	}

	public function test_a_team_colour_is_passed_on_only_if_valid() {
		Functions\when( 'sanitize_hex_color' )->alias(
			function ( $colour ) {
				return preg_match( '/^#[0-9a-f]{6}$/i', $colour ) ? $colour : '';
			}
		);

		$this->assertSame( ' style="--cak-event-colour:#2a78d6"', Chess_Army_Knife_Events_Display::colour_style( $this->team_event( array( $this->side( 1, 'A', 'home', '#2a78d6' ) ) ) ) );
		$this->assertSame( '', Chess_Army_Knife_Events_Display::colour_style( $this->team_event( array( $this->side( 1, 'A', 'home', 'red;x' ) ) ) ) );
		$this->assertSame( '', Chess_Army_Knife_Events_Display::colour_style( $this->event() ) );
	}

	public function test_details_show_the_team_unless_switched_off() {
		$event = $this->team_event( array( $this->side( 1, 'Club A', 'away' ) ) );

		$this->assertStringContainsString( 'Club A (away)', Chess_Army_Knife_Events_Display::details_html( $event, array( 'show_teams' => true ) ) );
		$this->assertStringNotContainsString( 'Club A', Chess_Army_Knife_Events_Display::details_html( $event, array( 'show_teams' => false ) ) );
	}

	public function test_an_events_own_colour_wins_over_its_teams_and_is_used_without_a_team() {
		Functions\when( 'sanitize_hex_color' )->alias(
			function ( $colour ) {
				return preg_match( '/^#[0-9a-f]{6}$/i', $colour ) ? $colour : '';
			}
		);
		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );

		$own = $this->event( array( 'colour' => '#d62a2a' ) );
		$this->assertSame( ' style="--cak-event-colour:#d62a2a"', Chess_Army_Knife_Events_Display::colour_style( $own ) );

		$both = $this->event(
			array(
				'colour' => '#d62a2a',
				'teams'  => array( $this->side( 1, 'A', 'home', '#2a78d6' ) ),
			)
		);
		$this->assertSame( ' style="--cak-event-colour:#d62a2a"', Chess_Army_Knife_Events_Display::colour_style( $both ) );

		$team_only = $this->team_event( array( $this->side( 1, 'A', 'home', '#2a78d6' ) ) );
		$this->assertSame( '', Chess_Army_Knife_Events_Display::colour_style( $team_only, false ), 'A team colour is not used when team names are hidden.' );
		$this->assertSame( ' style="--cak-event-colour:#d62a2a"', Chess_Army_Knife_Events_Display::colour_style( $both, false ) );
		$this->assertSame( '', Chess_Army_Knife_Events_Display::colour_style( $this->event( array( 'colour' => 'red;x' ) ) ) );
	}

	public function test_a_title_links_to_the_events_page_only_if_it_has_one() {
		Functions\when( 'esc_url' )->returnArg();

		$this->assertSame( '<a href="https://example.test/event">Club night</a>', Chess_Army_Knife_Events_Display::title_html( $this->event() ) );
		$this->assertSame( 'Club night', Chess_Army_Knife_Events_Display::title_html( $this->event( array( 'url' => '' ) ) ) );
	}

	public function test_an_event_takes_its_first_tags_colour_when_it_has_no_other() {
		Functions\when( 'sanitize_hex_color' )->alias(
			function ( $colour ) {
				return preg_match( '/^#[0-9a-f]{6}$/i', $colour ) ? $colour : '';
			}
		);
		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );

		$tagged = $this->event(
			array(
				'tags' => array(
					array(
						'name'   => 'Coaching',
						'slug'   => 'coaching',
						'colour' => '#2a9d5c',
					),
					array(
						'name'   => 'Juniors',
						'slug'   => 'juniors',
						'colour' => '#d62a8f',
					),
				),
			)
		);
		$this->assertSame( ' style="--cak-event-colour:#2a9d5c"', Chess_Army_Knife_Events_Display::colour_style( $tagged ) );

		$own           = $tagged;
		$own['colour'] = '#d62a2a';
		$this->assertSame( ' style="--cak-event-colour:#d62a2a"', Chess_Army_Knife_Events_Display::colour_style( $own ), 'Its own colour comes first.' );

		$team          = $tagged;
		$team['teams'] = array( $this->side( 1, 'A', 'home', '#2a78d6' ) );
		$this->assertSame( ' style="--cak-event-colour:#2a78d6"', Chess_Army_Knife_Events_Display::colour_style( $team ), 'A team colour comes before a tag colour.' );
		$this->assertSame( ' style="--cak-event-colour:#2a9d5c"', Chess_Army_Knife_Events_Display::colour_style( $team, false ) );
	}

	public function test_the_key_lists_each_tag_with_its_colour() {
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );
		Functions\when( 'get_term_meta' )->justReturn( '#2a9d5c' );
		Functions\when( 'get_terms' )->justReturn(
			array(
				(object) array(
					'term_id' => 3,
					'name'    => 'Coaching',
					'slug'    => 'coaching',
				),
			)
		);

		$html = Chess_Army_Knife_Events_Display::key_html();

		$this->assertStringContainsString( 'style="--cak-event-colour:#2a9d5c"', $html );
		$this->assertStringContainsString( 'Coaching', $html );

		Functions\when( 'get_terms' )->justReturn( array() );
		$this->assertSame( '', Chess_Army_Knife_Events_Display::key_html(), 'No tags, no key.' );
	}

	public function test_the_location_links_to_a_map_and_what3words_when_they_are_known() {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );

		$html = Chess_Army_Knife_Events_Display::location_html(
			$this->event(
				array(
					'location'   => 'Town Hall',
					'map_url'    => 'https://maps.app.goo.gl/abc',
					'what3words' => 'index.home.raft',
				)
			)
		);

		$this->assertStringContainsString( 'Town Hall <a class="cak-event__map" href="https://maps.app.goo.gl/abc" aria-label="Map of Town Hall">Map</a>', $html );
		$this->assertStringContainsString( 'href="https://what3words.com/index.home.raft"', $html );
		$this->assertStringContainsString( '>///index.home.raft</a>', $html );
	}

	public function test_a_location_with_only_a_name_is_plain_text_and_none_is_nothing() {
		$this->assertSame( '<p class="cak-event__location">Town Hall</p>', Chess_Army_Knife_Events_Display::location_html( $this->event( array( 'location' => 'Town Hall' ) ) ) );
		$this->assertSame( '', Chess_Army_Knife_Events_Display::location_html( $this->event( array( 'location' => '' ) ) ) );
	}

	public function test_status_text_names_a_cancelled_or_moved_event() {
		$this->assertSame( '', Chess_Army_Knife_Events_Display::status_text( $this->event() ) );
		$this->assertSame( '', Chess_Army_Knife_Events_Display::status_text( $this->event( array( 'status' => 'bogus' ) ) ) );
		$this->assertSame( 'Cancelled', Chess_Army_Knife_Events_Display::status_text( $this->event( array( 'status' => 'cancelled' ) ) ) );
		$this->assertSame(
			'Moved: to Thursday',
			Chess_Army_Knife_Events_Display::status_text(
				$this->event(
					array(
						'status'      => 'moved',
						'status_note' => ' to Thursday ',
					)
				)
			)
		);
	}

	public function test_there_is_no_calendar_link_for_a_cancelled_event() {
		$this->assertSame(
			'',
			Chess_Army_Knife_Events_Display::calendar_link_html(
				$this->event(
					array(
						'id'     => 4,
						'status' => 'cancelled',
					)
				)
			)
		);
		$this->assertSame( '', Chess_Army_Knife_Events_Display::calendar_link_html( $this->event() ), 'An event with no id cannot be fetched.' );
	}
}
