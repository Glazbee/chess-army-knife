<?php
/**
 * Heading levels for the blocks' titles.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every block titles itself with a real heading (WCAG 1.3.1, 2.4.10). Which
 * level depends on where the site puts the block, so the level of a block's
 * main title is one setting for the whole site, and sub-headings follow it.
 */
class Chess_Army_Knife_Headings {

	/** Option holding the level of a block's main title. */
	const OPTION = 'Chess_Army_Knife_heading_level';

	/**
	 * The level of a block's main title, 1 to 5.
	 *
	 * @return int
	 */
	public static function base() {
		/**
		 * Filter the heading level used for a block's main title.
		 *
		 * @param int $level Level from 1 to 5; 2 unless changed under Settings.
		 */
		$level = (int) apply_filters( 'Chess_Army_Knife_heading_level', (int) get_option( self::OPTION, 2 ) );
		return max( 1, min( 5, $level ) );
	}

	/**
	 * The tag for a heading.
	 *
	 * @param int $depth 0 for a block's main title, 1 for a heading within it, and so on.
	 * @return string h1 to h6.
	 */
	public static function tag( $depth = 0 ) {
		return 'h' . min( 6, self::base() + max( 0, (int) $depth ) );
	}
}
