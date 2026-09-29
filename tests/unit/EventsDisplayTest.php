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
}
