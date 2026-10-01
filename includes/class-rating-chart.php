<?php
/**
 * Geometry for the rating chart's SVG line.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a list of ratings into SVG path data. The chart holds no text, so it
 * resizes with the page and every value is also given in a table.
 */
class Chess_Army_Knife_Rating_Chart {

	/** Width of the SVG coordinate space. */
	const WIDTH = 600;

	/** Height of the SVG coordinate space. */
	const HEIGHT = 300;

	/** Space kept clear around the line. */
	const PADDING = 12;

	/** Above this many points the dots are left out. */
	const MAX_DOTS = 60;

	/**
	 * Path data for a chart.
	 *
	 * @param float[] $values Ratings, oldest first.
	 * @return array{line: string, area: string, dots: string, grid: int[]} SVG path data and the y position of each grid line.
	 */
	public static function geometry( array $values ) {
		$values = array_values( array_map( 'floatval', $values ) );
		$count  = count( $values );
		if ( 0 === $count ) {
			return array(
				'line' => '',
				'area' => '',
				'dots' => '',
				'grid' => array(),
			);
		}

		$low    = min( $values );
		$high   = max( $values );
		$span   = $high - $low;
		$inner  = self::HEIGHT - 2 * self::PADDING;
		$wide   = self::WIDTH - 2 * self::PADDING;
		$points = array();

		foreach ( $values as $index => $value ) {
			$x = 1 === $count ? self::WIDTH / 2 : self::PADDING + $wide * $index / ( $count - 1 );
			// A flat line sits in the middle.
			$y        = $span > 0 ? self::PADDING + $inner * ( 1 - ( $value - $low ) / $span ) : self::HEIGHT / 2;
			$points[] = array( round( $x, 1 ), round( $y, 1 ) );
		}

		$line = '';
		foreach ( $points as $index => $point ) {
			$line .= ( 0 === $index ? 'M' : 'L' ) . $point[0] . ' ' . $point[1];
		}

		$last = end( $points );
		$area = $line . 'L' . $last[0] . ' ' . ( self::HEIGHT - self::PADDING ) . 'L' . $points[0][0] . ' ' . ( self::HEIGHT - self::PADDING ) . 'Z';

		// A zero-length segment with round caps draws a dot that stays round when the SVG is stretched.
		$dots = '';
		if ( $count <= self::MAX_DOTS ) {
			foreach ( $points as $point ) {
				$dots .= 'M' . $point[0] . ' ' . $point[1] . 'h0';
			}
		}

		return array(
			'line' => $line,
			'area' => $area,
			'dots' => $dots,
			'grid' => array( self::PADDING, (int) ( self::HEIGHT / 2 ), self::HEIGHT - self::PADDING ),
		);
	}
}
