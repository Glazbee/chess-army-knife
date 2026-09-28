<?php
/**
 * Caching layer for every remote call this plugin makes.
 *
 * By default this stores responses in a dedicated database table
 * (wp_chess_army_knife_cache) rather than WordPress transients, so cached ECF/LMS
 * data survives object-cache evictions and isn't silently dropped by
 * memory-constrained hosting - the whole point being that a page visit
 * shouldn't trigger a fresh API call if a cached copy is still valid.
 * Site admins can switch back to plain transients from the settings page
 * if they'd rather rely on an external object cache instead.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Cache {

	const PREFIX = 'ecflms_';

	/**
	 * Get the custom table name.
	 *
	 * @return string
	 */
	protected static function table() {
		global $wpdb;
		return $wpdb->prefix . 'chess_army_knife_cache';
	}

	/**
	 * Create (or upgrade) the custom cache table. Called on activation.
	 */
	public static function install_table() {
		global $wpdb;

		$table_name      = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			cache_key VARCHAR(191) NOT NULL,
			cache_value LONGTEXT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (cache_key),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get a cached value, or compute and cache it via the callback.
	 *
	 * @param string   $key       Raw cache key (will be hashed/prefixed).
	 * @param int      $ttl       Seconds to cache for.
	 * @param callable $generator Callback that returns the value to cache.
	 * @return mixed
	 */
	public static function remember( $key, $ttl, $generator ) {
		$cache_key = self::key( $key );

		$cached = self::get( $cache_key );
		if ( null !== $cached ) {
			return $cached;
		}

		$value = call_user_func( $generator );

		// Don't cache hard failures for long - allow a quick retry,
		// but still avoid hammering the remote API on every request.
		$store_ttl = is_wp_error( $value ) ? min( $ttl, MINUTE_IN_SECONDS * 2 ) : $ttl;

		self::set( $cache_key, $value, $store_ttl );

		return $value;
	}

	/**
	 * Read a value from whichever backend is configured.
	 *
	 * @param string $cache_key Already-hashed cache key.
	 * @return mixed|null Null on cache miss.
	 */
	protected static function get( $cache_key ) {
		if ( ! self::use_db() ) {
			$value = get_transient( $cache_key );
			return ( false === $value ) ? null : $value;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cache_value, expires_at FROM " . self::table() . " WHERE cache_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cache_key
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		if ( strtotime( $row['expires_at'] . ' GMT' ) < time() ) {
			return null; // Expired; treat as a miss (row is cleaned up lazily by cron/flush).
		}

		$value = maybe_unserialize( $row['cache_value'] );
		return $value;
	}

	/**
	 * Write a value to whichever backend is configured.
	 *
	 * @param string $cache_key Already-hashed cache key.
	 * @param mixed  $value     Value to store.
	 * @param int    $ttl       Seconds until expiry.
	 */
	protected static function set( $cache_key, $value, $ttl ) {
		if ( ! self::use_db() ) {
			set_transient( $cache_key, $value, $ttl );
			return;
		}

		global $wpdb;

		$wpdb->replace(
			self::table(),
			array(
				'cache_key'   => $cache_key,
				'cache_value' => maybe_serialize( $value ),
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Whether the custom-table backend should be used. Falls back to
	 * transients automatically if the table doesn't exist yet (e.g. an
	 * upgrade from an older plugin version before activation re-runs).
	 *
	 * @return bool
	 */
	protected static function use_db() {
		if ( ! class_exists( 'Chess_Army_Knife_Settings' ) || ! Chess_Army_Knife_Settings::use_local_cache() ) {
			return false;
		}

		global $wpdb;
		static $table_exists = null;

		if ( null === $table_exists ) {
			$table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) === self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		return $table_exists;
	}

	/**
	 * Build a namespaced, length-safe cache key.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function key( $key ) {
		return self::PREFIX . md5( $key );
	}

	/**
	 * Get when a cached entry was written, if known. Only meaningful
	 * with the database-table backend (transients don't record a
	 * creation time, only an expiry) - returns null otherwise.
	 *
	 * @param string $key Raw cache key.
	 * @return int|null Unix timestamp, or null if unknown/not cached.
	 */
	public static function get_created_at( $key ) {
		if ( ! self::use_db() ) {
			return null;
		}

		global $wpdb;
		$cache_key = self::key( $key );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$created_at = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT created_at FROM ' . self::table() . ' WHERE cache_key = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cache_key
			)
		);

		return $created_at ? strtotime( $created_at . ' GMT' ) : null;
	}

	/**
	 * Remove one cached entry.
	 *
	 * @param string $key Raw key to forget.
	 */
	public static function forget( $key ) {
		$cache_key = self::key( $key );

		delete_transient( $cache_key );

		if ( self::use_db() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( self::table(), array( 'cache_key' => $cache_key ), array( '%s' ) );
		}
	}

	/**
	 * Flush every cached entry, in both backends (used on deactivation
	 * and from the settings page "clear cache" button, and useful if an
	 * admin has just switched between the two backends).
	 */
	public static function flush_all() {
		global $wpdb;

		// Transients.
		$like = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		foreach ( $options as $option_name ) {
			delete_transient( str_replace( '_transient_', '', $option_name ) );
		}

		// Custom table.
		if ( self::use_db() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Basic stats for the settings page: how many entries are currently
	 * cached, and which backend is in use.
	 *
	 * @return array{count: int, backend: string}
	 */
	public static function stats() {
		global $wpdb;

		if ( self::use_db() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE expires_at > UTC_TIMESTAMP()' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return array(
				'count'   => $count,
				'backend' => __( 'local database table', 'chess-army-knife' ),
			);
		}

		$like = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		return array(
			'count'   => $count,
			'backend' => __( 'WordPress transients', 'chess-army-knife' ),
		);
	}
}
