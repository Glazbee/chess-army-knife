<?php
/**
 * Database access for player profiles, tournaments, entrants and games.
 *
 * All queries go through $wpdb with prepared statements or the insert/update
 * helpers. Table names are built from the site prefix, never from user input.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Tournament_Store {

	/**
	 * Full table name for one of: players, tournaments, entries, games.
	 *
	 * @param string $name Short table name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'chess_army_knife_' . $name;
	}

	/**
	 * Create or upgrade the tournament tables.
	 */
	public static function install_tables() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$players = self::table( 'players' );
		$tourney = self::table( 'tournaments' );
		$entries = self::table( 'entries' );
		$games   = self::table( 'games' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$players} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			ecf_code VARCHAR(20) NOT NULL DEFAULT '',
			manual_rating INT(11) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$tourney} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			format VARCHAR(30) NOT NULL DEFAULT 'round-robin',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			rating_domain VARCHAR(2) NOT NULL DEFAULT 'S',
			double_round TINYINT(1) NOT NULL DEFAULT 0,
			settings LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			started_at DATETIME NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$entries} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			tournament_id BIGINT(20) UNSIGNED NOT NULL,
			player_id BIGINT(20) UNSIGNED NOT NULL,
			name VARCHAR(191) NOT NULL,
			ecf_code VARCHAR(20) NOT NULL DEFAULT '',
			seed INT(11) NULL,
			start_rating INT(11) NULL,
			rating_source VARCHAR(10) NOT NULL DEFAULT 'none',
			status VARCHAR(12) NOT NULL DEFAULT 'active',
			group_no INT(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY tournament_id (tournament_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$games} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			tournament_id BIGINT(20) UNSIGNED NOT NULL,
			stage VARCHAR(20) NOT NULL DEFAULT 'main',
			group_no INT(11) NOT NULL DEFAULT 0,
			round INT(11) NOT NULL,
			board INT(11) NOT NULL DEFAULT 0,
			white_entry_id BIGINT(20) UNSIGNED NULL,
			black_entry_id BIGINT(20) UNSIGNED NULL,
			result VARCHAR(10) NULL,
			is_bye TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY tournament_id (tournament_id)
			) {$charset};"
		);
	}

	/* -------------------------------------------------------------
	 * Players
	 * ------------------------------------------------------------- */

	/**
	 * All player profiles, ordered by name.
	 *
	 * @return array[]
	 */
	public static function get_players() {
		global $wpdb;
		$table = self::table( 'players' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A );
		return array_map( array( __CLASS__, 'cast_player' ), (array) $rows );
	}

	/**
	 * One player profile.
	 *
	 * @param int $id Player id.
	 * @return array|null
	 */
	public static function get_player( $id ) {
		global $wpdb;
		$table = self::table( 'players' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::cast_player( $row ) : null;
	}

	/**
	 * The saved player profile with an ECF rating code, if any.
	 *
	 * @param string $code ECF rating code.
	 * @return array|null
	 */
	public static function find_player_by_code( $code ) {
		global $wpdb;
		$table = self::table( 'players' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ecf_code = %s ORDER BY id ASC LIMIT 1", (string) $code ), ARRAY_A );
		return $row ? self::cast_player( $row ) : null;
	}

	/**
	 * Insert or update a player profile.
	 *
	 * @param array $data name, ecf_code, manual_rating (int|null), and optionally id.
	 * @return int Player id.
	 */
	public static function save_player( array $data ) {
		global $wpdb;
		$row = array(
			'name'          => $data['name'],
			'ecf_code'      => $data['ecf_code'],
			'manual_rating' => $data['manual_rating'],
		);

		if ( ! empty( $data['id'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( self::table( 'players' ), $row, array( 'id' => (int) $data['id'] ) );
			return (int) $data['id'];
		}

		$row['created_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( self::table( 'players' ), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a player profile (entries in existing tournaments keep their own name snapshot).
	 *
	 * @param int $id Player id.
	 */
	public static function delete_player( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table( 'players' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Normalise types on a player row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	protected static function cast_player( array $row ) {
		$row['id']            = (int) $row['id'];
		$row['manual_rating'] = ( null === $row['manual_rating'] ) ? null : (int) $row['manual_rating'];
		return $row;
	}

	/* -------------------------------------------------------------
	 * Tournaments
	 * ------------------------------------------------------------- */

	/**
	 * All tournaments, newest first.
	 *
	 * @return array[]
	 */
	public static function get_tournaments() {
		global $wpdb;
		$table = self::table( 'tournaments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
		return array_map( array( __CLASS__, 'cast_tournament' ), (array) $rows );
	}

	/**
	 * One tournament.
	 *
	 * @param int $id Tournament id.
	 * @return array|null
	 */
	public static function get_tournament( $id ) {
		global $wpdb;
		$table = self::table( 'tournaments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::cast_tournament( $row ) : null;
	}

	/**
	 * Insert a tournament, or update the given fields of an existing one.
	 *
	 * @param array $data Column => value. Include 'id' to update.
	 * @return int Tournament id.
	 */
	public static function save_tournament( array $data ) {
		global $wpdb;

		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$data['settings'] = wp_json_encode( $data['settings'] );
		}

		if ( ! empty( $data['id'] ) ) {
			$id = (int) $data['id'];
			unset( $data['id'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( self::table( 'tournaments' ), $data, array( 'id' => $id ) );
			return $id;
		}

		$data['created_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( self::table( 'tournaments' ), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a tournament with its entrants and games.
	 *
	 * @param int $id Tournament id.
	 */
	public static function delete_tournament( $id ) {
		global $wpdb;
		foreach ( array( 'games', 'entries' ) as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( self::table( $name ), array( 'tournament_id' => (int) $id ), array( '%d' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table( 'tournaments' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Normalise types on a tournament row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	protected static function cast_tournament( array $row ) {
		$row['id']           = (int) $row['id'];
		$row['double_round'] = (int) $row['double_round'];
		$settings            = json_decode( (string) $row['settings'], true );
		$row['settings']     = is_array( $settings ) ? $settings : array();
		return $row;
	}

	/* -------------------------------------------------------------
	 * Entries
	 * ------------------------------------------------------------- */

	/**
	 * Entrants in a tournament, in seed order (unseeded last, then by name).
	 *
	 * @param int $tournament_id Tournament id.
	 * @return array[]
	 */
	public static function get_entries( $tournament_id ) {
		global $wpdb;
		$table = self::table( 'entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tournament_id = %d ORDER BY seed IS NULL, seed ASC, name ASC", (int) $tournament_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'cast_entry' ), (array) $rows );
	}

	/**
	 * One entrant.
	 *
	 * @param int $id Entry id.
	 * @return array|null
	 */
	public static function get_entry( $id ) {
		global $wpdb;
		$table = self::table( 'entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::cast_entry( $row ) : null;
	}

	/**
	 * Add an entrant.
	 *
	 * @param array $data tournament_id, player_id, name, ecf_code.
	 * @return int Entry id.
	 */
	public static function add_entry( array $data ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( self::table( 'entries' ), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update fields on an entrant.
	 *
	 * @param int   $id   Entry id.
	 * @param array $data Column => value.
	 */
	public static function update_entry( $id, array $data ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::table( 'entries' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Remove an entrant.
	 *
	 * @param int $id Entry id.
	 */
	public static function delete_entry( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table( 'entries' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Normalise types on an entry row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	protected static function cast_entry( array $row ) {
		foreach ( array( 'id', 'tournament_id', 'player_id', 'group_no' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		foreach ( array( 'seed', 'start_rating' ) as $key ) {
			$row[ $key ] = ( null === $row[ $key ] ) ? null : (int) $row[ $key ];
		}
		return $row;
	}

	/* -------------------------------------------------------------
	 * Games
	 * ------------------------------------------------------------- */

	/**
	 * Games in a tournament, in play order.
	 *
	 * @param int $tournament_id Tournament id.
	 * @return array[]
	 */
	public static function get_games( $tournament_id ) {
		global $wpdb;
		$table = self::table( 'games' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tournament_id = %d ORDER BY CASE WHEN stage = 'knockout' THEN 1 ELSE 0 END ASC, round ASC, group_no ASC, board ASC, id ASC", (int) $tournament_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'cast_game' ), (array) $rows );
	}

	/**
	 * One game.
	 *
	 * @param int $id Game id.
	 * @return array|null
	 */
	public static function get_game( $id ) {
		global $wpdb;
		$table = self::table( 'games' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::cast_game( $row ) : null;
	}

	/**
	 * Insert a game.
	 *
	 * @param array $data Game columns.
	 * @return int Game id.
	 */
	public static function add_game( array $data ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( self::table( 'games' ), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update fields on a game (e.g. the players once a bracket slot is filled).
	 *
	 * @param int   $id   Game id.
	 * @param array $data Column => value; null values are stored as NULL.
	 */
	public static function update_game( $id, array $data ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::table( 'games' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete one game.
	 *
	 * @param int $id Game id.
	 */
	public static function delete_game( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table( 'games' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete every game of one stage in a tournament.
	 *
	 * @param int    $tournament_id Tournament id.
	 * @param string $stage         Stage, e.g. 'knockout'.
	 */
	public static function delete_stage_games( $tournament_id, $stage ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			self::table( 'games' ),
			array(
				'tournament_id' => (int) $tournament_id,
				'stage'         => $stage,
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Set (or clear, with null) a game's result.
	 *
	 * @param int         $id     Game id.
	 * @param string|null $result Result string or null.
	 */
	public static function set_result( $id, $result ) {
		global $wpdb;
		$table = self::table( 'games' );
		if ( null === $result ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET result = NULL WHERE id = %d", (int) $id ) );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'result' => $result ), array( 'id' => (int) $id ) );
	}

	/**
	 * Normalise types on a game row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	protected static function cast_game( array $row ) {
		foreach ( array( 'id', 'tournament_id', 'group_no', 'round', 'board', 'is_bye' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		foreach ( array( 'white_entry_id', 'black_entry_id' ) as $key ) {
			$row[ $key ] = ( null === $row[ $key ] ) ? null : (int) $row[ $key ];
		}
		return $row;
	}
}
