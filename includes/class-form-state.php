<?php
/**
 * Keeps what a visitor typed when a form comes back with an error.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

/**
 * The forms post to admin-post.php and redirect back, so after an error the
 * page would be empty. The values are kept for a few minutes under an
 * unguessable key passed in the address, and filled back in (WCAG 3.3.4, 3.3.7).
 */
class Chess_Army_Knife_Form_State {

	/** Query argument holding the key. */
	const PARAM = 'cak_form';

	/** How long the values are kept, in seconds. */
	const TTL = 900;

	/** Transient name prefix. */
	const PREFIX = 'Chess_Army_Knife_form_';

	/** The most fields, and the longest value, that are kept. */
	const MAX_FIELDS = 60;
	const MAX_LENGTH = 2000;

	/** The most times one visitor's values are kept in an hour, so a bot cannot fill the options table. */
	const MAX_SAVES_PER_HOUR = 10;

	/**
	 * Keep the values of a submitted form.
	 *
	 * @param array $input Raw (unslashed) form values.
	 * @return string The key to pass back in the address, or '' if this visitor has had their values kept too often.
	 */
	public static function save( array $input ) {
		$counter = 'Chess_Army_Knife_form_saves_' . md5( Chess_Army_Knife_Member_Requests::visitor_address() );
		if ( (int) get_transient( $counter ) >= self::MAX_SAVES_PER_HOUR ) {
			return '';
		}
		set_transient( $counter, (int) get_transient( $counter ) + 1, HOUR_IN_SECONDS );

		$keep = array();
		foreach ( array_slice( $input, 0, self::MAX_FIELDS, true ) as $name => $value ) {
			$name = (string) $name;
			// Nothing that authorises an action, and nothing a bot filled in.
			if ( preg_match( '/nonce|^action$|^token$|^cak_redirect$|^_wp|^cak_url$|^cak_website$/', $name ) ) {
				continue;
			}
			$keep[ $name ] = self::clean( $value );
		}

		$key = wp_generate_password( 20, false );
		set_transient( self::PREFIX . $key, $keep, self::TTL );
		return $key;
	}

	/**
	 * Strip a value to plain text, whatever nesting it has.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	protected static function clean( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'clean' ), $value );
		}
		return mb_substr( sanitize_textarea_field( (string) $value ), 0, self::MAX_LENGTH );
	}

	/**
	 * The address argument for a redirect after an error: the key, or nothing if no values were kept.
	 *
	 * @param array $input Raw (unslashed) form values.
	 * @return array Query arguments to add.
	 */
	public static function redirect_args( array $input ) {
		$key = self::save( $input );
		return '' === $key ? array() : array( self::PARAM => $key );
	}

	/**
	 * The values kept for this request, or none.
	 *
	 * @return array
	 */
	public static function all() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only fills in a form; nothing is changed.
		$key    = isset( $_GET[ self::PARAM ] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) ) : '';
		$stored = '' === $key ? false : get_transient( self::PREFIX . $key );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * A kept text value.
	 *
	 * @param string $name    Field name.
	 * @param string $default Value when nothing was kept.
	 * @return string
	 */
	public static function value( $name, $default = '' ) {
		$all = self::all();
		return isset( $all[ $name ] ) && is_string( $all[ $name ] ) ? $all[ $name ] : $default;
	}

	/**
	 * Whether anything was kept.
	 *
	 * @return bool
	 */
	public static function has_values() {
		return array() !== self::all();
	}

	/**
	 * Whether a checkbox or radio was ticked, or was by default when nothing was kept.
	 *
	 * @param string          $name    Field name without any [].
	 * @param string          $value   The option's value.
	 * @param bool            $default Whether it is ticked when nothing was kept.
	 * @param string|int|null $index   Key of an array field such as newsletter[12].
	 * @return bool
	 */
	public static function checked( $name, $value = '1', $default = false, $index = null ) {
		if ( ! self::has_values() ) {
			return $default;
		}
		$all = self::all();
		if ( ! isset( $all[ $name ] ) ) {
			return false;
		}
		$kept = $all[ $name ];
		if ( null !== $index ) {
			$kept = is_array( $kept ) && isset( $kept[ $index ] ) ? $kept[ $index ] : null;
		}
		if ( is_array( $kept ) ) {
			return in_array( (string) $value, array_map( 'strval', $kept ), true );
		}
		return null !== $kept && (string) $value === (string) $kept;
	}
}
