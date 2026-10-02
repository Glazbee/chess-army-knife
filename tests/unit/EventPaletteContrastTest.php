<?php
/**
 * The calendar bubbles tint the page with an event's colour (14%, and 26% on hover) and keep the
 * theme's own text colour. This checks that the colours the plugin hands out leave black text on a
 * light page, and white text on a dark one, readable to WCAG AAA (7:1), and that each dot stands out
 * from the page (3:1, which only matters if a person picks a colour that is too pale: the dots are
 * decorative, as every event says what it is in words).
 *
 * @package Chess_Army_Knife
 */

class EventPaletteContrastTest extends Chess_Army_Knife_TestCase {

	/** Tint strengths used by the bubbles (see club-event-calendar/style.scss). */
	const TINTS = array( 0.14, 0.26 );

	public function pages() {
		return array(
			'light page, black text'     => array( '#ffffff', '#000000' ),
			'dark page, white text'      => array( '#000000', '#ffffff' ),
			'dark grey page, white text' => array( '#1d2327', '#ffffff' ),
			'light page, dark grey text' => array( '#ffffff', '#1d2327' ),
			'light grey page, dark text' => array( '#f6f7f7', '#1d2327' ),
		);
	}

	/**
	 * @dataProvider pages
	 */
	public function test_text_stays_readable_on_every_palette_tint( $page, $text ) {
		foreach ( Chess_Army_Knife_Events::tag_palette() as $colour ) {
			foreach ( self::TINTS as $strength ) {
				$background = Chess_Army_Knife_Contrast::blend( $colour, $page, $strength );

				$this->assertTrue( Chess_Army_Knife_Contrast::meets( $text, $background, Chess_Army_Knife_Contrast::TEXT ), "$text on $colour at " . ( $strength * 100 ) . "% over $page" );
			}
		}
	}

	public function test_each_palette_dot_stands_out_from_a_light_and_a_dark_page() {
		foreach ( Chess_Army_Knife_Events::tag_palette() as $colour ) {
			$this->assertTrue( Chess_Army_Knife_Contrast::meets( $colour, '#ffffff', Chess_Army_Knife_Contrast::GRAPHIC ), "$colour on white" );
			$this->assertTrue( Chess_Army_Knife_Contrast::meets( $colour, '#000000', Chess_Army_Knife_Contrast::GRAPHIC ), "$colour on black" );
		}
	}
}
