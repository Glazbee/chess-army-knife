<?php
/**
 * Tests for the colour checks on templates.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class TemplatesColourTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'number_format_i18n' )->alias(
			function ( $number, $decimals = 0 ) {
				return number_format( $number, $decimals );
			}
		);
	}

	public function test_no_colours_is_fine() {
		$this->assertSame( array(), Chess_Army_Knife_Templates::colour_problems( array() ) );
	}

	public function test_readable_colours_are_fine() {
		$this->assertSame(
			array(),
			Chess_Army_Knife_Templates::colour_problems(
				array(
					'bg'     => '#ffffff',
					'text'   => '#1a1a1a',
					'accent' => '#1e3a5f',
				)
			)
		);
	}

	public function test_text_and_background_must_come_together() {
		$this->assertCount( 1, Chess_Army_Knife_Templates::colour_problems( array( 'bg' => '#ffffff' ) ) );
		$this->assertCount( 1, Chess_Army_Knife_Templates::colour_problems( array( 'text' => '#000000' ) ) );
		$this->assertCount( 1, Chess_Army_Knife_Templates::colour_problems( array( 'accent' => '#1e3a5f' ) ) );
	}

	public function test_text_needs_seven_to_one() {
		$problems = Chess_Army_Knife_Templates::colour_problems(
			array(
				'bg'   => '#ffffff',
				'text' => '#767676', // About 4.5:1: passes AA, fails AAA.
			)
		);
		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( '7:1', $problems[0] );
	}

	public function test_accent_needs_three_to_one_against_the_background() {
		$problems = Chess_Army_Knife_Templates::colour_problems(
			array(
				'bg'     => '#ffffff',
				'text'   => '#000000',
				'accent' => '#cccccc',
			)
		);
		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( '3:1', $problems[0] );
	}

	public function test_dark_themes_are_checked_the_same_way() {
		$this->assertSame(
			array(),
			Chess_Army_Knife_Templates::colour_problems(
				array(
					'bg'     => '#101820',
					'text'   => '#f5f5f5',
					'accent' => '#f2c94c',
				)
			)
		);
	}
}
