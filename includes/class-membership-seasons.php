<?php
/**
 * Membership seasons: a payment is due every season, and each season's payments are kept as a ledger.
 *
 * A season has a name and a start date, and runs until the next one starts. Starting a new season marks every
 * member as not paid, so each has to pay again; the payments of the seasons before are kept, one row for each
 * member and season, which is also what shows how long someone has been a member. A member's own paid_on,
 * payment_method and payment_amount hold their payment for the current season; saving them writes the
 * ledger row for that season.
 *
 * Starting the first season counts the payments already recorded as that season's, and resets nothing.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Membership_Seasons {

	/** Holds the id of the season just started until an admin has been through the checklist (teams, unpaid members). */
	const REVIEW_OPTION = 'Chess_Army_Knife_season_review';

	const MAX_NAME_LENGTH = 60;

	/**
	 * Full name of the seasons table.
	 *
	 * @return string
	 */
	public static function seasons_table() {
		global $wpdb;
		return $wpdb->prefix . 'chess_army_knife_membership_seasons';
	}

	/**
	 * Full name of the payments table.
	 *
	 * @return string
	 */
	public static function payments_table() {
		global $wpdb;
		return $wpdb->prefix . 'chess_army_knife_membership_payments';
	}

	/**
	 * Create or upgrade the seasons and payments tables.
	 */
	public static function install_tables() {
		global $wpdb;

		$charset  = $wpdb->get_charset_collate();
		$seasons  = self::seasons_table();
		$payments = self::payments_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$seasons} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(60) NOT NULL,
			start_date DATE NOT NULL,
			end_date DATE NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY start_date (start_date)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$payments} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			season_id BIGINT(20) UNSIGNED NOT NULL,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			type_name VARCHAR(191) NOT NULL DEFAULT '',
			paid_on DATE NOT NULL,
			method VARCHAR(20) NOT NULL DEFAULT '',
			reference VARCHAR(40) NOT NULL DEFAULT '',
			amount INT(11) NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY season_member (season_id,member_id),
			KEY member_id (member_id)
			) {$charset};"
		);
	}

	/**
	 * Make the tables once on a site that was set up before they existed. The schema version is not bumped while
	 * the plugin is in development, so installs made earlier would otherwise have nowhere to keep payments.
	 */
	public static function maybe_install_tables() {
		if ( get_option( 'Chess_Army_Knife_season_tables' ) === '1' ) {
			return;
		}
		self::install_tables();
		update_option( 'Chess_Army_Knife_season_tables', '1', true );
	}

	/* -------------------------------------------------------------
	 * Seasons
	 * ------------------------------------------------------------- */

	/**
	 * Every season, the latest first.
	 *
	 * @return array[] Each { id, name, start_date, end_date ('' while it has none) }.
	 */
	public static function all() {
		global $wpdb;

		$table = self::seasons_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal.
		$rows = (array) $wpdb->get_results( "SELECT id, name, start_date, end_date FROM {$table} ORDER BY start_date DESC, id DESC", ARRAY_A );

		return array_map( array( __CLASS__, 'cast_season' ), $rows );
	}

	/**
	 * The season now running: the one that started last.
	 *
	 * @return array|null See all(); null until the first season is started.
	 */
	public static function current() {
		$seasons = self::all();
		return $seasons ? $seasons[0] : null;
	}

	/**
	 * One season.
	 *
	 * @param int $id Season id.
	 * @return array|null See all().
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::seasons_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, start_date, end_date FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );

		return $row ? self::cast_season( $row ) : null;
	}

	/**
	 * Normalise a season row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	protected static function cast_season( array $row ) {
		return array(
			'id'         => (int) $row['id'],
			'name'       => (string) $row['name'],
			'start_date' => (string) $row['start_date'],
			'end_date'   => null === $row['end_date'] ? '' : (string) $row['end_date'],
		);
	}

	/**
	 * A name to offer for a season that starts on a date: "2026/27".
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @return string
	 */
	public static function suggest_name( $start_date ) {
		$year = (int) substr( $start_date, 0, 4 );
		return $year . '/' . substr( (string) ( $year + 1 ), 2 );
	}

	/**
	 * Check the details of a new season.
	 *
	 * @param string     $name       Name typed by the admin.
	 * @param string     $start_date Start date typed by the admin, YYYY-MM-DD.
	 * @param array|null $current    The season now running, if there is one.
	 * @return array|WP_Error { name, start_date }, or the first problem found.
	 */
	public static function validate( $name, $start_date, $current ) {
		$name       = mb_substr( trim( sanitize_text_field( $name ) ), 0, self::MAX_NAME_LENGTH );
		$start_date = trim( sanitize_text_field( $start_date ) );

		if ( '' === $name ) {
			return new WP_Error( 'season_name', __( 'Please give the season a name, such as 2026/27.', 'chess-army-knife' ) );
		}
		if ( ! Chess_Army_Knife_Memberships::is_valid_date( $start_date ) ) {
			return new WP_Error( 'season_date', __( 'Please enter the start date as YYYY-MM-DD.', 'chess-army-knife' ) );
		}
		if ( $current && $start_date <= $current['start_date'] ) {
			return new WP_Error( 'season_order', __( 'The new season has to start after the current one.', 'chess-army-knife' ) );
		}

		return array(
			'name'       => $name,
			'start_date' => $start_date,
		);
	}

	/**
	 * Start a new season. The season running now ends the day before. Every member is marked as not paid, so each
	 * has to pay again, and the admin is asked to go through the checklist. The very first season counts the
	 * payments already recorded as its own and resets nothing.
	 *
	 * @param string $name       Season name.
	 * @param string $start_date First day, YYYY-MM-DD.
	 * @return int|WP_Error The new season's id, or the problem with the details.
	 */
	public static function start( $name, $start_date ) {
		global $wpdb;

		$current = self::current();
		$clean   = self::validate( $name, $start_date, $current );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( $current ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal.
			$wpdb->update( self::seasons_table(), array( 'end_date' => gmdate( 'Y-m-d', strtotime( $clean['start_date'] . ' UTC' ) - DAY_IN_SECONDS ) ), array( 'id' => $current['id'] ), array( '%s' ), array( '%d' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table; the table name is internal.
		$wpdb->insert(
			self::seasons_table(),
			array(
				'name'       => $clean['name'],
				'start_date' => $clean['start_date'],
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$id = (int) $wpdb->insert_id;

		if ( $current ) {
			Chess_Army_Knife_Membership_Store::clear_payments();
			update_option( self::REVIEW_OPTION, $id, false );
		} else {
			foreach ( Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'paid' ) ) as $member ) {
				self::record_payment( $member['id'] );
			}
		}

		do_action( 'Chess_Army_Knife_season_started', $id );
		return $id;
	}

	/* -------------------------------------------------------------
	 * The checklist after a season starts
	 * ------------------------------------------------------------- */

	/**
	 * Whether the admins still have to go through the checklist for a season they started.
	 *
	 * @return bool
	 */
	public static function review_pending() {
		return (bool) get_option( self::REVIEW_OPTION, 0 );
	}

	/**
	 * Note that the checklist has been gone through.
	 */
	public static function mark_reviewed() {
		delete_option( self::REVIEW_OPTION );
	}

	/* -------------------------------------------------------------
	 * Payments
	 * ------------------------------------------------------------- */

	/**
	 * Bring the ledger up to date with a member's payment for the current season: a payment date adds or updates
	 * their row, and no payment date takes it away. Does nothing until a season has been started.
	 *
	 * @param int $member_id Member id.
	 */
	public static function record_payment( $member_id ) {
		global $wpdb;

		$season = self::current();
		$member = $season ? Chess_Army_Knife_Membership_Store::get_member( $member_id ) : null;
		if ( ! $member ) {
			return;
		}

		$table = self::payments_table();

		if ( '' === $member['paid_on'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal.
			$wpdb->delete(
				$table,
				array(
					'season_id' => $season['id'],
					'member_id' => $member['id'],
				),
				array( '%d', '%d' )
			);
			return;
		}

		$row = array(
			'type_name'  => $member['type_name'],
			'paid_on'    => $member['paid_on'],
			'method'     => $member['payment_method'],
			// Only a bank transfer has a reference to match; the one shown to the member is kept as it was when they paid.
			'reference'  => 'bank_transfer' === $member['payment_method'] ? Chess_Army_Knife_Memberships::payment_reference( $member['id'], $member ) : '',
			'amount'     => $member['payment_amount'],
			'updated_at' => current_time( 'mysql', true ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE season_id = %d AND member_id = %d", $season['id'], $member['id'] ) );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table.
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ) );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table.
		$wpdb->insert(
			$table,
			$row + array(
				'season_id' => $season['id'],
				'member_id' => $member['id'],
			)
		);
	}

	/**
	 * The payments received for a season, for the treasurer.
	 *
	 * @param int $season_id Season id.
	 * @return array[] Each { member_id, name, nickname, type_name, paid_on, method, reference, amount (pence, or null) }, by date then name.
	 */
	public static function payments_for_season( $season_id ) {
		global $wpdb;

		$payments = self::payments_table();
		$members  = Chess_Army_Knife_Membership_Store::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom tables; the table names are internal and dynamic values are prepared.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.member_id, p.type_name, p.paid_on, p.method, p.reference, p.amount, m.name, m.nickname FROM {$payments} p LEFT JOIN {$members} m ON m.id = p.member_id WHERE p.season_id = %d ORDER BY p.paid_on ASC, m.name ASC", (int) $season_id ), ARRAY_A );

		return array_map(
			function ( $row ) {
				return array(
					'member_id' => (int) $row['member_id'],
					'name'      => null === $row['name'] ? '' : (string) $row['name'],
					'nickname'  => null === $row['nickname'] ? '' : (string) $row['nickname'],
					'type_name' => (string) $row['type_name'],
					'paid_on'   => (string) $row['paid_on'],
					'method'    => (string) $row['method'],
					'reference' => (string) $row['reference'],
					'amount'    => null === $row['amount'] ? null : (int) $row['amount'],
				);
			},
			$rows
		);
	}

	/**
	 * How many payments each season has and what they add up to.
	 *
	 * @return array[] { count, total (pence) } by season id.
	 */
	public static function totals() {
		global $wpdb;

		$table = self::payments_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal.
		$rows   = (array) $wpdb->get_results( "SELECT season_id, COUNT(*) AS payments, COALESCE(SUM(amount), 0) AS total FROM {$table} GROUP BY season_id", ARRAY_A );
		$totals = array();
		foreach ( $rows as $row ) {
			$totals[ (int) $row['season_id'] ] = array(
				'count' => (int) $row['payments'],
				'total' => (int) $row['total'],
			);
		}
		return $totals;
	}

	/**
	 * The seasons a member paid for, earliest first.
	 *
	 * @param int $member_id Member id.
	 * @return array[] Each { name, start_date, end_date ('' for none), type_name, paid_on }.
	 */
	public static function paid_seasons( $member_id ) {
		global $wpdb;

		$payments = self::payments_table();
		$seasons  = self::seasons_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom tables; the table names are internal and dynamic values are prepared.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.name, s.start_date, s.end_date, p.type_name, p.paid_on FROM {$payments} p INNER JOIN {$seasons} s ON s.id = p.season_id WHERE p.member_id = %d ORDER BY s.start_date ASC", (int) $member_id ), ARRAY_A );

		return array_map(
			function ( $row ) {
				return array(
					'name'       => (string) $row['name'],
					'start_date' => (string) $row['start_date'],
					'end_date'   => null === $row['end_date'] ? '' : (string) $row['end_date'],
					'type_name'  => (string) $row['type_name'],
					'paid_on'    => (string) $row['paid_on'],
				);
			},
			$rows
		);
	}

	/**
	 * Whether a member has ever paid, so the club's accounts still need their record.
	 *
	 * @param int $member_id Member id.
	 * @return bool
	 */
	public static function has_payments( $member_id ) {
		global $wpdb;

		$table = self::payments_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE member_id = %d LIMIT 1", (int) $member_id ) );
	}

	/**
	 * Forget a person's payments (when their record is deleted).
	 *
	 * @param int $member_id Member id.
	 */
	public static function remove_person( $member_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal.
		$wpdb->delete( self::payments_table(), array( 'member_id' => (int) $member_id ), array( '%d' ) );
	}
}

add_action( 'init', array( 'Chess_Army_Knife_Membership_Seasons', 'maybe_install_tables' ), 1 );
