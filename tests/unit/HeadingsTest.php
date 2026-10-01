<?php
/**
 * Tests for Chess_Army_Knife_Headings.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class HeadingsTest extends Chess_Army_Knife_TestCase {

	public function test_titles_are_h2_by_default() {
		$this->assertSame( 'h2', Chess_Army_Knife_Headings::tag() );
		$this->assertSame( 'h3', Chess_Army_Knife_Headings::tag( 1 ) );
	}

	public function test_the_site_setting_moves_every_level() {
		$this->set_settings( array( 'heading_level' => 3 ) );
		$this->assertSame( 'h3', Chess_Army_Knife_Headings::tag() );
		$this->assertSame( 'h4', Chess_Army_Knife_Headings::tag( 1 ) );
	}

	public function test_levels_stay_between_h1_and_h6() {
		$this->set_settings( array( 'heading_level' => 99 ) );
		$this->assertSame( 'h5', Chess_Army_Knife_Headings::tag() );
		$this->assertSame( 'h6', Chess_Army_Knife_Headings::tag( 5 ) );
		$this->set_settings( array( 'heading_level' => 0 ) );
		$this->assertSame( 'h1', Chess_Army_Knife_Headings::tag() );
	}
}
