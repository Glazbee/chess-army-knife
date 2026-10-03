<?php
/**
 * How people's names are written on the website: "Firstname Surname" or "Surname, Firstname Initials". The
 * club chooses one for the whole site (Settings), and each block can use the other. A member can have a
 * nickname, which is used in place of their first name.
 *
 * Names are stored as the club or the ECF gave them, in either order ("Ada Lovelace" or "Lovelace, Ada"), and
 * rewritten only when shown.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Names {

	const FIRST_SURNAME = 'first_surname';
	const SURNAME_FIRST = 'surname_first';

	/** Words that belong to the surname after them ("de Mello", "van der Berg"). */
	const PARTICLES = array( 'de', 'da', 'di', 'do', 'dos', 'du', 'del', 'della', 'van', 'von', 'der', 'den', 'ten', 'ter', 'le', 'la', 'el', 'al', 'bin', 'ibn', 'st', 'mc', 'mac' );

	/**
	 * The ways a name can be written.
	 *
	 * @return string[] Label by key.
	 */
	public static function styles() {
		return array(
			self::FIRST_SURNAME => __( 'Firstname Surname', 'chess-army-knife' ),
			self::SURNAME_FIRST => __( 'Surname, Firstname Initials', 'chess-army-knife' ),
		);
	}

	/**
	 * Whether a value is one of the ways a name can be written.
	 *
	 * @param mixed $style Value to test.
	 * @return bool
	 */
	public static function is_style( $style ) {
		return is_string( $style ) && isset( self::styles()[ $style ] );
	}

	/**
	 * The way names are written across the site, from Settings.
	 *
	 * @return string
	 */
	public static function site_style() {
		$style = Chess_Army_Knife_Settings::get_options()['name_format'];
		return self::is_style( $style ) ? $style : self::FIRST_SURNAME;
	}

	/**
	 * The way a block writes names: its own choice, else the site's.
	 *
	 * @param array $attributes Block attributes; nameFormat is empty to follow the site.
	 * @return string
	 */
	public static function style_for( array $attributes ) {
		return isset( $attributes['nameFormat'] ) && self::is_style( $attributes['nameFormat'] ) ? $attributes['nameFormat'] : self::site_style();
	}

	/**
	 * Split a name into first name, middle names and surname.
	 *
	 * "Lovelace, Ada Augusta" and "Ada Augusta Lovelace" both give Ada / Augusta / Lovelace. Words such as "de"
	 * and "van" stay with the surname after them. A single word is a first name with no surname.
	 *
	 * @param string $name Full name.
	 * @return array { first: string, middle: string[], surname: string }
	 */
	public static function parse( $name ) {
		$name = trim( preg_replace( '/\s+/', ' ', (string) $name ) );

		if ( false !== strpos( $name, ',' ) ) {
			list( $surname, $forenames ) = array_map( 'trim', explode( ',', $name, 2 ) );
			$words                       = '' === $forenames ? array() : explode( ' ', $forenames );
			return array(
				'first'   => $words ? array_shift( $words ) : '',
				'middle'  => $words,
				'surname' => $surname,
			);
		}

		$words = '' === $name ? array() : explode( ' ', $name );
		if ( count( $words ) < 2 ) {
			return array(
				'first'   => $name,
				'middle'  => array(),
				'surname' => '',
			);
		}

		$first   = array_shift( $words );
		$surname = array( array_pop( $words ) );
		while ( $words && in_array( strtolower( rtrim( end( $words ), '.' ) ), self::PARTICLES, true ) ) {
			array_unshift( $surname, array_pop( $words ) );
		}

		return array(
			'first'   => $first,
			'middle'  => $words,
			'surname' => implode( ' ', $surname ),
		);
	}

	/**
	 * A surname, for sorting.
	 *
	 * @param string $name Full name.
	 * @return string
	 */
	public static function surname( $name ) {
		$parts = self::parse( $name );
		return '' !== $parts['surname'] ? $parts['surname'] : $parts['first'];
	}

	/**
	 * A name written in a style.
	 *
	 * @param string $name     Full name, in either order.
	 * @param string $style    FIRST_SURNAME or SURNAME_FIRST.
	 * @param string $nickname Used in place of the first name when not empty.
	 * @return string
	 */
	public static function format( $name, $style, $nickname = '' ) {
		$parts = self::parse( $name );
		if ( '' === $parts['surname'] ) {
			return trim( (string) $name );
		}

		$first = '' !== trim( (string) $nickname ) ? trim( (string) $nickname ) : $parts['first'];

		if ( self::SURNAME_FIRST === $style ) {
			$initials = array_map(
				function ( $word ) {
					return mb_strtoupper( mb_substr( $word, 0, 1 ) );
				},
				$parts['middle']
			);
			return trim( $parts['surname'] . ', ' . $first . ( $initials ? ' ' . implode( ' ', $initials ) : '' ) );
		}

		return trim( $first . ' ' . $parts['surname'] );
	}

	/**
	 * A person's name in a style, using their nickname if they have one.
	 *
	 * @param array  $person Row with name and, optionally, nickname.
	 * @param string $style  FIRST_SURNAME or SURNAME_FIRST.
	 * @return string
	 */
	public static function person( array $person, $style ) {
		return self::format( isset( $person['name'] ) ? $person['name'] : '', $style, isset( $person['nickname'] ) ? $person['nickname'] : '' );
	}
}
