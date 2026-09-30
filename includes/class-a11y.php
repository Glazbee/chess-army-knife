<?php
/**
 * Small pieces of accessible markup used by several blocks.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

/**
 * Each method returns escaped HTML, ready to echo.
 */
class Chess_Army_Knife_A11y {

	/**
	 * Text that only screen readers get.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function hidden( $text ) {
		return '<span class="cak-visually-hidden">' . esc_html( $text ) . '</span>';
	}

	/**
	 * A heading.
	 *
	 * @param int    $depth 0 for a block's main title, 1 for a heading within it.
	 * @param string $class CSS class.
	 * @param string $text  Plain text.
	 * @return string
	 */
	public static function heading( $depth, $class, $text ) {
		$tag = Chess_Army_Knife_Headings::tag( $depth );
		// A block's main title gets the accent underline.
		$class .= 0 === (int) $depth ? ' cak-block-title' : '';
		return '<' . $tag . ' class="' . esc_attr( $class ) . '">' . esc_html( $text ) . '</' . $tag . '>';
	}

	/**
	 * A date in the site's format, as a time element.
	 *
	 * @param string $date A date such as 2025-03-04, or a MySQL date and time.
	 * @return string Empty if there is no date.
	 */
	public static function date( $date ) {
		if ( '' === (string) $date ) {
			return '';
		}
		return '<time datetime="' . esc_attr( substr( $date, 0, 10 ) ) . '">' . esc_html( mysql2date( get_option( 'date_format' ), $date ) ) . '</time>';
	}

	/**
	 * A score: "3 – 1" to look at, "3 to 1" to hear.
	 *
	 * @param string $first  First score.
	 * @param string $second Second score.
	 * @return string
	 */
	public static function score( $first, $second ) {
		/* translators: 1: first score, 2: second score, for example "3 to 1" */
		$spoken = sprintf( __( '%1$s to %2$s', 'chess-army-knife' ), $first, $second );
		return '<span class="cak-visually-hidden">' . esc_html( $spoken ) . '</span><span aria-hidden="true">' . esc_html( $first . ' – ' . $second ) . '</span>';
	}

	/**
	 * An abbreviation that says what it stands for.
	 *
	 * @param string $short    The short form, such as "P".
	 * @param string $expanded What it stands for, such as "Played".
	 * @return string
	 */
	public static function abbr( $short, $expanded ) {
		return '<abbr title="' . esc_attr( $expanded ) . '">' . esc_html( $short ) . '</abbr>';
	}
}
