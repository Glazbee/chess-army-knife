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

		$this->assertStringContainsString( '<caption class="screen-reader-text">October 2026</caption>', $html );
		$this->assertStringContainsString( '<th scope="col">Mon</th>', $html );
		$this->assertLessThan( strpos( $html, '<th scope="col">Tue</th>' ), strpos( $html, '<th scope="col">Mon</th>' ) );
		$this->assertStringContainsString( 'cak-month__day has-events is-today', $html );
		$this->assertStringContainsString( '<span class="cak-month__daynum">5</span><ul class="cak-month__events">', $html );
		$this->assertStringContainsString( 'Club &lt;night&gt;', $html );
		$this->assertStringNotContainsString( '<night>', $html );
		$this->assertStringContainsString( 'title="Town &quot;Hall&quot;"', $html );
		$this->assertSame( 1, substr_count( $html, 'cak-month__event"' ), 'Only the one event is shown.' );
	}

	public function test_month_html_hides_the_tooltip_when_location_is_off() {
		Functions\when( 'current_time' )->justReturn( '2026-10-05' );
		Functions\when( 'esc_url' )->returnArg();

		$html = Chess_Army_Knife_Events_Display::month_html( 2026, 10, array( $this->event() ), array( 'show_location' => false ) );

		$this->assertStringNotContainsString( 'title=', $html );
	}

	public function test_month_html_with_no_events_is_still_a_full_grid() {
		Functions\when( 'current_time' )->justReturn( '2026-09-01' );

		$html = Chess_Army_Knife_Events_Display::month_html( 2026, 10, array(), array() );

		$this->assertSame( 31, substr_count( $html, 'cak-month__daynum' ) );
		$this->assertStringNotContainsString( 'has-events', $html );
	}
}
