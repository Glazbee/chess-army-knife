<?php
/**
 * Keeps the plugin's secrets (the LMS API key) encrypted in the database.
 *
 * A secret in the settings option is encrypted with libsodium (an authenticated secretbox) using a
 * key made from the site's own salts in wp-config.php, and decrypted when the settings are read, so
 * the rest of the plugin never sees the difference. This protects the key in a database dump, a
 * backup or an export of the options table. It does not protect it from someone who can read both the
 * database and wp-config.php, or who can run code on the site: for that, put the key in wp-config.php
 * instead (define( 'CHESS_ARMY_KNIFE_LMS_API_KEY', '...' ); see Chess_Army_Knife_LMS_Client::api_key()),
 * where it is not in the database at all.
 *
 * If the site's salts change, a stored key can no longer be read and must be entered again.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Secrets {

	/** Marks an encrypted value, and its format. */
	const PREFIX = 'enc1:';

	/** Settings that hold a secret. */
	const FIELDS = array( 'lms_api_key' );

	/**
	 * Hook up encryption on every save of the settings, and a one-off tidy of a key saved in plain text.
	 */
	public static function init() {
		add_filter( 'pre_update_option_' . Chess_Army_Knife_Settings::OPTION, array( __CLASS__, 'protect_option' ) );
		add_action( 'admin_init', array( __CLASS__, 'migrate' ) );
	}

	/**
	 * Whether encryption is available.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_generichash' );
	}

	/**
	 * Whether a stored value is one of ours.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * The encryption key: made from the site's salts, so it is not in the database.
	 *
	 * @return string
	 */
	protected static function key() {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|chess-army-knife|secrets', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt a value for storing.
	 *
	 * @param string $plain Value.
	 * @return string The encrypted form; the value itself if it is empty, already encrypted, or encryption is not available.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || self::is_encrypted( $plain ) || ! self::available() ) {
			return $plain;
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text for storage, not obfuscation.
	}

	/**
	 * Read a stored value.
	 *
	 * @param string $stored Value from the database.
	 * @return string The secret; a value saved before encryption existed as it is; '' if it cannot be read
	 *                (damaged, or the site's salts changed).
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( ! self::is_encrypted( $stored ) ) {
			return $stored;
		}
		if ( ! self::available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Text back to binary, not obfuscation.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Encrypt the secrets in the settings as they are saved, whichever code saves them.
	 *
	 * @param mixed $value The settings about to be stored.
	 * @return mixed
	 */
	public static function protect_option( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( self::FIELDS as $field ) {
			if ( isset( $value[ $field ] ) && is_string( $value[ $field ] ) ) {
				$value[ $field ] = self::encrypt( $value[ $field ] );
			}
		}

		return $value;
	}

	/**
	 * Read the secrets in settings that came from the database.
	 *
	 * @param array $options Settings.
	 * @return array
	 */
	public static function reveal( array $options ) {
		foreach ( self::FIELDS as $field ) {
			if ( isset( $options[ $field ] ) ) {
				$options[ $field ] = self::decrypt( $options[ $field ] );
			}
		}

		return $options;
	}

	/**
	 * Encrypt a key that was saved in plain text before this existed.
	 */
	public static function migrate() {
		if ( ! self::available() ) {
			return;
		}

		$stored = get_option( Chess_Army_Knife_Settings::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return;
		}
		foreach ( self::FIELDS as $field ) {
			if ( isset( $stored[ $field ] ) && '' !== $stored[ $field ] && ! self::is_encrypted( $stored[ $field ] ) ) {
				update_option( Chess_Army_Knife_Settings::OPTION, $stored ); // The filter encrypts it.
				return;
			}
		}
	}
}

Chess_Army_Knife_Secrets::init();
