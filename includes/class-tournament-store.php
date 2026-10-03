<?php
/**
 * Database access for tournaments, entrants and games.
 *
 * Nobody's details are stored here: an entrant is a reference to a person in
 * the club's people table (see Chess_Army_Knife_Membership_Store), whose name
 * and ECF code are read from there, so everything held about a player is in
 * one place. All queries go through $wpdb with prepared statements or the insert/update
 * helpers. Table names are built from the site prefix, never from user input.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Tournament_Store {

	/**
	 * Full table name for one of: tournaments, entries, games (or members, the people table).
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
		$tourney = self::table( 'tournaments' );
		$entries = self::table( 'entries' );
		$games   = self::table( 'games' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Entrants point at people, so their table has to exist too.
		Chess_Army_Knife_Membership_Store::install_table();

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
			player_name VARCHAR(191) NULL,
			seed INT(11) NULL,
			start_rating INT(11) NULL,
			rating_source VARCHAR(10) NOT NULL DEFAULT 'none',
			status VARCHAR(12) NOT NULL DEFAULT 'active',
			group_no INT(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY tournament_id (tournament_id),
			KEY player_id (player_id)
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$wpdb->update( self::table( 'tournaments' ), $data, array( 'id' => $id ) );
			return $id;
		}

		$data['created_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$wpdb->delete( self::table( $name ), array( 'tournament_id' => (int) $id ), array( '%d' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
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
	 * The columns and join that give an entrant their person's name and ECF code.
	 *
	 * @return string SQL that selects entries (alias e) with the person (alias p).
	 */
	protected static function entry_select() {
		// An entry with no person (player_id 0) keeps the name it was given, and has no ECF code or any link to a record.
		return 'SELECT e.*, COALESCE( p.name, e.player_name ) AS name, p.ecf_code AS ecf_code FROM ' . self::table( 'entries' ) . ' e LEFT JOIN ' . self::table( 'members' ) . ' p ON p.id = e.player_id';
	}

	/**
	 * Entrants in a tournament, in seed order (unseeded last, then by name).
	 *
	 * @param int $tournament_id Tournament id.
	 * @return array[] Each entry with its person's name and ecf_code.
	 */
	public static function get_entries( $tournament_id ) {
		global $wpdb;
		$select = self::entry_select();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom tables; the table names are internal and dynamic values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "{$select} WHERE e.tournament_id = %d ORDER BY e.seed IS NULL, e.seed ASC, COALESCE( p.name, e.player_name ) ASC", (int) $tournament_id ), ARRAY_A );
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
		$select = self::entry_select();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom tables; the table names are internal and dynamic values are prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "{$select} WHERE e.id = %d", (int) $id ), ARRAY_A );
		return $row ? self::cast_entry( $row ) : null;
	}

	/**
	 * Whether a person is entered in any tournament.
	 *
	 * @param int $person_id Person id.
	 * @return bool
	 */
	public static function person_has_entries( $person_id ) {
		global $wpdb;
		$table = self::table( 'entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE player_id = %d", (int) $person_id ) );
	}

	/**
	 * The tournaments a person is entered in, newest first, for the privacy tools.
	 *
	 * @param int $person_id Person id.
	 * @return array[] Each { id, name, status }.
	 */
	public static function get_tournaments_for_person( $person_id ) {
		global $wpdb;
		$entries = self::table( 'entries' );
		$tourney = self::table( 'tournaments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom tables; the table names are internal and dynamic values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.name, t.status FROM {$entries} e INNER JOIN {$tourney} t ON t.id = e.tournament_id WHERE e.player_id = %d ORDER BY t.id DESC", (int) $person_id ), ARRAY_A );
		return array_map(
			function ( $row ) {
				$row['id'] = (int) $row['id'];
				return $row;
			},
			(array) $rows
		);
	}

	/**
	 * Unlink a person from the tournaments they are entered in, when their record is deleted.
	 * In a tournament that has started the entry stays, with the name it was played under and no
	 * link to any record, so results and standings still add up and the person can no longer be
	 * found from them. In a draft tournament nothing has been played, so the entry is removed.
	 *
	 * @param int    $person_id Person id.
	 * @param string $name      The name to keep on entries that stay.
	 */
	public static function unlink_person( $person_id, $name ) {
		global $wpdb;

		$entries = self::table( 'entries' );
		$tourney = self::table( 'tournaments' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom tables; the table names are internal and dynamic values are prepared.
		$wpdb->query( $wpdb->prepare( "DELETE e FROM {$entries} e INNER JOIN {$tourney} t ON t.id = e.tournament_id WHERE e.player_id = %d AND t.status = %s", (int) $person_id, 'draft' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$entries} SET player_name = %s, player_id = 0 WHERE player_id = %d", (string) $name, (int) $person_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Add an entrant.
	 *
	 * @param array $data tournament_id and player_id (the person's id).
	 * @return int Entry id.
	 */
	public static function add_entry( array $data ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->update( self::table( 'entries' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Remove an entrant.
	 *
	 * @param int $id Entry id.
	 */
	public static function delete_entry( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete( self::table( 'entries' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Replace the name on an entry that has no member record behind it.
	 *
	 * @param int    $id   Entry id.
	 * @param string $name The name to leave there.
	 * @return bool Whether an entry was changed. An entry linked to a record is never changed here.
	 */
	public static function rename_unlinked_entry( $id, $name ) {
		global $wpdb;

		$entries = self::table( 'entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$entries} SET player_name = %s WHERE id = %d AND player_id = 0 AND player_name IS NOT NULL AND player_name <> %s", (string) $name, (int) $id, (string) $name ) );

		return (bool) $changed;
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
		// A person who was deleted outright leaves the entry standing, without their details.
		$row['name']     = null === $row['name'] ? Chess_Army_Knife_Membership_Store::erased_name() : $row['name'];
		$row['ecf_code'] = null === $row['ecf_code'] ? '' : $row['ecf_code'];
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->update( self::table( 'games' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete one game.
	 *
	 * @param int $id Game id.
	 */
	public static function delete_game( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET result = NULL WHERE id = %d", (int) $id ) );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
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
