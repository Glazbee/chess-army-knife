<?php
/**
 * Tests for Chess_Army_Knife_Rating_Chart.
 *
 * @package Chess_Army_Knife
 */

class RatingChartTest extends Chess_Army_Knife_TestCase {

	public function test_no_values_give_no_chart() {
		$this->assertSame( '', Chess_Army_Knife_Rating_Chart::geometry( array() )['line'] );
	}

	public function test_highest_rating_is_at_the_top_and_lowest_at_the_bottom() {
		$chart = Chess_Army_Knife_Rating_Chart::geometry( array( 1500, 1600 ) );
		$pad   = Chess_Army_Knife_Rating_Chart::PADDING;
		$low   = Chess_Army_Knife_Rating_Chart::HEIGHT - $pad;

		$this->assertSame( "M{$pad} {$low}L" . ( Chess_Army_Knife_Rating_Chart::WIDTH - $pad ) . " {$pad}", $chart['line'] );
	}

	public function test_flat_line_sits_in_the_middle() {
		$chart = Chess_Army_Knife_Rating_Chart::geometry( array( 1500, 1500, 1500 ) );
		$this->assertStringContainsString( ' 150', $chart['line'] );
		$this->assertStringNotContainsString( ' 12', $chart['line'] );
	}

	public function test_one_value_is_centred() {
		$chart = Chess_Army_Knife_Rating_Chart::geometry( array( 1500 ) );
		$this->assertSame( 'M300 150', $chart['line'] );
	}

	public function test_area_is_closed_along_the_bottom() {
		$chart = Chess_Army_Knife_Rating_Chart::geometry( array( 1500, 1600 ) );
		$this->assertStringEndsWith( 'Z', $chart['area'] );
		$this->assertStringStartsWith( $chart['line'], $chart['area'] );
	}

	public function test_dots_are_left_out_of_a_long_series() {
		$this->assertNotSame( '', Chess_Army_Knife_Rating_Chart::geometry( range( 1, 10 ) )['dots'] );
		$this->assertSame( '', Chess_Army_Knife_Rating_Chart::geometry( range( 1, 100 ) )['dots'] );
	}
}
