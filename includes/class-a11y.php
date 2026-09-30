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

	/**
	 * The mark beside a label that says the field must be filled in.
	 *
	 * @return string
	 */
	public static function required() {
		return ' <span class="cak-required">' . esc_html__( '(required)', 'chess-army-knife' ) . '</span>';
	}

	/**
	 * The message at the top of a form after it was sent. It takes focus when the page
	 * loads (see shared/focus-notice.js) so a screen reader reads it at once.
	 *
	 * @param string $type        'error' or 'success'.
	 * @param string $id          Element id, so a field can point to it.
	 * @param string $message     Plain text.
	 * @param string $field_id    Id of the field the problem is about, to link to it.
	 * @param string $field_label Name of that field.
	 * @return string
	 */
	public static function notice( $type, $id, $message, $field_id = '', $field_label = '' ) {
		$error = 'error' === $type;
		$html  = '<div id="' . esc_attr( $id ) . '" class="cak-form-notice cak-form-notice--' . esc_attr( $type ) . '" role="' . ( $error ? 'alert' : 'status' ) . '" tabindex="-1">';
		$html .= '<p>' . ( $error ? '<strong>' . esc_html__( 'Problem:', 'chess-army-knife' ) . '</strong> ' : '' ) . esc_html( $message ) . '</p>';
		if ( $error && '' !== $field_id ) {
			/* translators: %s: the name of a form field */
			$html .= '<p><a href="#' . esc_attr( $field_id ) . '">' . esc_html( sprintf( __( 'Go to the field: %s', 'chess-army-knife' ), $field_label ) ) . '</a></p>';
		}
		return $html . '</div>';
	}

	/**
	 * Attributes that tie a field to the error message about it.
	 *
	 * @param string $field_id       Id of this field.
	 * @param string $error_field_id Id of the field the error is about.
	 * @param string $notice_id      Id of the error message.
	 * @param string $hint_id        Id of a hint for this field, if it has one.
	 * @return string Attributes to echo inside the tag; already escaped.
	 */
	public static function field_attrs( $field_id, $error_field_id, $notice_id, $hint_id = '' ) {
		$described = array_filter( array( $field_id === $error_field_id ? $notice_id : '', $hint_id ) );
		$attrs     = $described ? ' aria-describedby="' . esc_attr( implode( ' ', $described ) ) . '"' : '';
		return $attrs . ( $field_id === $error_field_id ? ' aria-invalid="true"' : '' );
	}
}
