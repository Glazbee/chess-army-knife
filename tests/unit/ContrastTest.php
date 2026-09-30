<?php
/**
 * Tests for Chess_Army_Knife_Contrast.
 *
 * @package Chess_Army_Knife
 */

class ContrastTest extends Chess_Army_Knife_TestCase {

	public function test_black_on_white_is_the_maximum_ratio() {
		$this->assertEqualsWithDelta( 21.0, Chess_Army_Knife_Contrast::ratio( '#000000', '#ffffff' ), 0.01 );
	}

	public function test_same_colour_is_one() {
		$this->assertEqualsWithDelta( 1.0, Chess_Army_Knife_Contrast::ratio( '#336699', '#336699' ), 0.001 );
	}

	public function test_order_does_not_matter_and_short_hex_works() {
		$this->assertSame( Chess_Army_Knife_Contrast::ratio( '#fff', '#1e3a5f' ), Chess_Army_Knife_Contrast::ratio( '#1e3a5f', '#ffffff' ) );
	}

	public function test_known_ratio() {
		// The default accent on white is about 11.5:1.
		$this->assertEqualsWithDelta( 11.5, Chess_Army_Knife_Contrast::ratio( '#1e3a5f', '#ffffff' ), 0.05 );
	}

	public function test_meets_uses_the_minimum() {
		$this->assertTrue( Chess_Army_Knife_Contrast::meets( '#1e3a5f', '#ffffff' ) );
		// #666 on white is about 5.7:1: fine for AA, not for AAA.
		$this->assertFalse( Chess_Army_Knife_Contrast::meets( '#666666', '#ffffff' ) );
		$this->assertTrue( Chess_Army_Knife_Contrast::meets( '#666666', '#ffffff', 4.5 ) );
	}

	public function test_invalid_colours_never_pass() {
		$this->assertNull( Chess_Army_Knife_Contrast::ratio( 'red', '#ffffff' ) );
		$this->assertFalse( Chess_Army_Knife_Contrast::meets( '', '#ffffff' ) );
		$this->assertFalse( Chess_Army_Knife_Contrast::meets( '#12345', '#ffffff' ) );
	}
}
