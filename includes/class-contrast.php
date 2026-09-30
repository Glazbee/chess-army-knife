<?php
/**
 * WCAG contrast ratios between two colours.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out the WCAG 2.2 contrast ratio of hex colours, so the plugin can
 * refuse colour choices that would be unreadable.
 */
class Chess_Army_Knife_Contrast {

	/** Minimum ratio for normal text at WCAG AAA. */
	const TEXT = 7.0;

	/** Minimum ratio for graphics and interface parts (WCAG 1.4.11). */
	const GRAPHIC = 3.0;

	/**
	 * Relative luminance of a hex colour.
	 *
	 * @param string $hex Colour such as #1e3a5f or #fff.
	 * @return float|null Luminance from 0 to 1, or null if the colour is not valid hex.
	 */
	public static function luminance( $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return null;
		}

		$channels = array();
		foreach ( str_split( $hex, 2 ) as $pair ) {
			$value      = hexdec( $pair ) / 255;
			$channels[] = $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Contrast ratio of two colours, from 1 to 21.
	 *
	 * @param string $first  Hex colour.
	 * @param string $second Hex colour.
	 * @return float|null Ratio, or null if either colour is not valid hex.
	 */
	public static function ratio( $first, $second ) {
		$a = self::luminance( $first );
		$b = self::luminance( $second );
		if ( null === $a || null === $b ) {
			return null;
		}

		return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
	}

	/**
	 * Whether two colours are at least a given ratio apart.
	 *
	 * @param string $first   Hex colour.
	 * @param string $second  Hex colour.
	 * @param float  $minimum Ratio required, such as self::TEXT.
	 * @return bool False if the ratio is too low or a colour is not valid.
	 */
	public static function meets( $first, $second, $minimum = self::TEXT ) {
		$ratio = self::ratio( $first, $second );
		return null !== $ratio && $ratio >= $minimum;
	}
}
