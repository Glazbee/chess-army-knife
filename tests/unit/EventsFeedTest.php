<?php
/**
 * Unit tests for the iCalendar feed.
 *
 * @package Chess_Army_Knife
 */

class EventsFeedTest extends Chess_Army_Knife_TestCase {

	private function event( array $overrides = array() ) {
		return array_merge(
			array(
				'id'          => 7,
				'title'       => 'Club A v Rivals, Round 1',
				'url'         => 'https://example.test/club-events/a-v-rivals/',
				'start'       => '2026-10-05 19:30:00',
				'start_ts'    => gmmktime( 19, 30, 0, 10, 5, 2026 ),
				'end'         => '',
				'location'    => 'Town Hall; High Street',
				'tags'        => array(),
				'tournaments' => array(),
				'leagues'     => array(
					array(
						'org'   => '613',
						'event' => 'Division 1',
					),
				),
				'teams'       => array(
					array(
						'id'     => 3,
						'name'   => 'Club A',
						'colour' => '',
						'side'   => 'home',
					),
				),
			),
			$overrides
		);
	}

	public function test_text_is_escaped_for_icalendar() {
		$this->assertSame( 'a\\, b\; c\\\\d\\ne', Chess_Army_Knife_Events_Feed::escape_text( "a, b; c\\d\r\ne" ) );
	}

	public function test_long_lines_are_folded_at_75_bytes_without_splitting_characters() {
		$line   = 'SUMMARY:' . str_repeat( 'é', 60 ); // Two bytes each.
		$folded = explode( "\r\n", Chess_Army_Knife_Events_Feed::fold( $line ) );

		$this->assertGreaterThan( 1, count( $folded ) );
		foreach ( $folded as $i => $part ) {
			$this->assertLessThanOrEqual( 75, strlen( $part ) );
			$this->assertTrue( mb_check_encoding( $part, 'UTF-8' ) );
			if ( $i > 0 ) {
				$this->assertSame( ' ', $part[0] );
			}
		}
		$this->assertSame( $line, str_replace( "\r\n ", '', Chess_Army_Knife_Events_Feed::fold( $line ) ) );
	}

	public function test_a_calendar_has_one_event_per_fixture_with_times_in_utc() {
		$ics = Chess_Army_Knife_Events_Feed::build( array( $this->event() ), 'Our Club', 'example.test', gmmktime( 12, 0, 0, 9, 30, 2026 ) );

		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
		$this->assertStringContainsString( "UID:7-20261005@example.test\r\n", $ics );
		$this->assertStringContainsString( "DTSTART:20261005T193000Z\r\n", $ics );
		$this->assertStringContainsString( "DTEND:20261005T223000Z\r\n", $ics, 'No end time: three hours.' );
		$this->assertStringContainsString( 'SUMMARY:Club A v Rivals\\, Round 1', $ics );
		$this->assertStringContainsString( 'LOCATION:Town Hall\; High Street', $ics );
		$this->assertStringContainsString( 'DESCRIPTION:Club A (home)\\nDivision 1', $ics );
	}

	public function test_an_event_without_a_page_has_no_url_and_each_occurrence_its_own_uid() {
		$ics = Chess_Army_Knife_Events_Feed::build(
			array(
				$this->event( array( 'url' => '' ) ),
				$this->event(
					array(
						'url'      => '',
						'start'    => '2026-10-12 19:30:00',
						'start_ts' => gmmktime( 19, 30, 0, 10, 12, 2026 ),
					)
				),
			),
			'Our Club',
			'example.test',
			gmmktime( 12, 0, 0, 9, 30, 2026 )
		);

		$this->assertStringNotContainsString( 'URL:', $ics );
		$this->assertStringContainsString( "UID:7-20261005@example.test\r\n", $ics );
		$this->assertStringContainsString( "UID:7-20261012@example.test\r\n", $ics );
	}

	public function test_an_events_own_end_time_is_used_and_one_without_a_start_is_left_out() {
		Brain\Monkey\Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );

		$ics = Chess_Army_Knife_Events_Feed::build(
			array(
				$this->event(
					array(
						'end' => '2026-10-05 21:00:00',
					)
				),
				$this->event(
					array(
						'id'       => 8,
						'start_ts' => null,
					)
				),
			),
			'Our Club',
			'example.test',
			0
		);

		$this->assertStringContainsString( "DTEND:20261005T210000Z\r\n", $ics );
		$this->assertSame( 1, substr_count( $ics, 'BEGIN:VEVENT' ) );
	}

	public function test_a_cancelled_event_is_marked_cancelled_and_a_moved_one_says_where() {
		$cancelled = Chess_Army_Knife_Events_Feed::build(
			array(
				$this->event(
					array(
						'status'      => 'cancelled',
						'status_note' => 'Hall closed',
					)
				),
			),
			'Our Club',
			'example.test',
			0
		);
		$moved     = Chess_Army_Knife_Events_Feed::build(
			array(
				$this->event(
					array(
						'status'      => 'moved',
						'status_note' => 'now Thursday',
					)
				),
			),
			'Our Club',
			'example.test',
			0
		);
		$normal    = Chess_Army_Knife_Events_Feed::build( array( $this->event() ), 'Our Club', 'example.test', 0 );

		$this->assertStringContainsString( "STATUS:CANCELLED\r\n", $cancelled );
		$this->assertStringContainsString( 'DESCRIPTION:Cancelled: Hall closed', $cancelled );
		$this->assertStringNotContainsString( 'STATUS:', $moved );
		$this->assertStringContainsString( 'DESCRIPTION:Moved: now Thursday', $moved );
		$this->assertStringNotContainsString( 'STATUS:', $normal );
	}
}
