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
 * Teams are permanent, but their squads are for a season: when a season ends the squads are kept for it (see
 * Chess_Army_Knife_Teams::archive_squads()), and the new season's squads start as they were unless the admin
 * empties them.
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
			lms_seasons TEXT NULL,
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
		if ( get_option( 'Chess_Army_Knife_season_tables' ) === '3' ) {
			return;
		}
		self::install_tables();
		Chess_Army_Knife_Teams::install_table(); // Adds the table that keeps the squads of past seasons.
		update_option( 'Chess_Army_Knife_season_tables', '3', true );
	}

	/* -------------------------------------------------------------
	 * Seasons
	 * ------------------------------------------------------------- */

	/**
	 * Every season, the latest first.
	 *
	 * @return array[] Each { id, name, start_date, end_date ('' while it has none), lms_seasons (LMS season names) }.
	 */
	public static function all() {
		global $wpdb;

		$table = self::seasons_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal.
		$rows = (array) $wpdb->get_results( "SELECT id, name, start_date, end_date, lms_seasons FROM {$table} ORDER BY start_date DESC, id DESC", ARRAY_A );

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
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, start_date, end_date, lms_seasons FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );

		return $row ? self::cast_season( $row ) : null;
	}

	/**
	 * Normalise a season row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	protected static function cast_season( array $row ) {
		$lms = isset( $row['lms_seasons'] ) ? json_decode( (string) $row['lms_seasons'], true ) : array();

		return array(
			'id'          => (int) $row['id'],
			'name'        => (string) $row['name'],
			'start_date'  => (string) $row['start_date'],
			'end_date'    => null === $row['end_date'] ? '' : (string) $row['end_date'],
			'lms_seasons' => is_array( $lms ) ? array_values( array_map( 'strval', $lms ) ) : array(),
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
	 * @param string     $end_date   Last day typed by the admin, YYYY-MM-DD, or '' to leave it open.
	 * @return array|WP_Error { name, start_date, end_date }, or the first problem found.
	 */
	public static function validate( $name, $start_date, $current, $end_date = '' ) {
		$name       = mb_substr( trim( sanitize_text_field( $name ) ), 0, self::MAX_NAME_LENGTH );
		$start_date = trim( sanitize_text_field( $start_date ) );
		$end_date   = trim( sanitize_text_field( $end_date ) );

		if ( '' === $name ) {
			return new WP_Error( 'season_name', __( 'Please give the season a name, such as 2026/27.', 'chess-army-knife' ) );
		}
		if ( ! Chess_Army_Knife_Memberships::is_valid_date( $start_date ) ) {
			return new WP_Error( 'season_date', __( 'Please enter the start date as YYYY-MM-DD.', 'chess-army-knife' ) );
		}
		if ( $current && $start_date <= $current['start_date'] ) {
			return new WP_Error( 'season_order', __( 'The new season has to start after the current one.', 'chess-army-knife' ) );
		}

		if ( '' !== $end_date && ( ! Chess_Army_Knife_Memberships::is_valid_date( $end_date ) || $end_date < $start_date ) ) {
			return new WP_Error( 'season_end', __( 'The last day has to be a date on or after the first day.', 'chess-army-knife' ) );
		}

		return array(
			'name'       => $name,
			'start_date' => $start_date,
			'end_date'   => $end_date,
		);
	}

	/**
	 * Start a new season. The season running now ends the day before. Every member is marked as not paid, so each
	 * has to pay again, and the admin is asked to go through the checklist. The very first season counts the
	 * payments already recorded as its own and resets nothing.
	 *
	 * @param string $name       Season name.
	 * @param string $start_date First day, YYYY-MM-DD.
	 * @param array  $options    {
	 *     Optional.
	 *
	 *     @type bool     $empty_squads True to take everyone out of every squad, so each team starts from scratch.
	 *                                  By default the squads carry on, to be reviewed.
	 *     @type string   $end_date     The planned last day, YYYY-MM-DD, for events that only run in season time.
	 *                                  By default the season runs until the next one starts.
	 *     @type string[] $lms_seasons  Names of the LMS seasons that belong to it.
	 * }
	 * @return int|WP_Error The new season's id, or the problem with the details.
	 */
	public static function start( $name, $start_date, array $options = array() ) {
		global $wpdb;

		$options = $options + array(
			'empty_squads' => false,
			'end_date'     => '',
			'lms_seasons'  => array(),
		);
		$current = self::current();
		$clean   = self::validate( $name, $start_date, $current, $options['end_date'] );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		// The season before ends the day before, unless it already has an earlier planned last day.
		$day_before = gmdate( 'Y-m-d', strtotime( $clean['start_date'] . ' UTC' ) - DAY_IN_SECONDS );
		if ( $current && ( '' === $current['end_date'] || $current['end_date'] > $day_before ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal.
			$wpdb->update( self::seasons_table(), array( 'end_date' => $day_before ), array( 'id' => $current['id'] ), array( '%s' ), array( '%d' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table; the table name is internal.
		$wpdb->insert(
			self::seasons_table(),
			array(
				'name'        => $clean['name'],
				'start_date'  => $clean['start_date'],
				'end_date'    => '' === $clean['end_date'] ? null : $clean['end_date'],
				'lms_seasons' => wp_json_encode( self::clean_lms_names( $options['lms_seasons'] ) ),
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		$id = (int) $wpdb->insert_id;
		self::take_lms_seasons_from_others( $id, self::clean_lms_names( $options['lms_seasons'] ) );
		self::forget_windows();

		if ( $current ) {
			// The squads as they stood are kept for the season that ended.
			Chess_Army_Knife_Teams::archive_squads( $current['id'] );
			if ( $options['empty_squads'] ) {
				Chess_Army_Knife_Teams::clear_squads();
			}
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
	 * Dates
	 * ------------------------------------------------------------- */

	/** @var array[]|null The seasons' date ranges kept for the request, or null until read. */
	protected static $windows = null;

	/**
	 * Forget the kept date ranges, because a season changed.
	 */
	public static function forget_windows() {
		self::$windows = null;
	}

	/**
	 * Whether a date falls in season time: on or after a season's first day and, if it has a planned last day,
	 * on or before it. Before any season has been started there is no season time to be outside of, so every
	 * date counts.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return bool
	 */
	public static function contains_date( $date ) {
		if ( null === self::$windows ) {
			self::$windows = array();
			foreach ( self::all() as $season ) {
				self::$windows[] = array( $season['start_date'], $season['end_date'] );
			}
		}

		if ( ! self::$windows ) {
			return true;
		}
		foreach ( self::$windows as $window ) {
			if ( $date >= $window[0] && ( '' === $window[1] || $date <= $window[1] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Change a season's planned last day and the LMS seasons that belong to it.
	 *
	 * @param int      $id          Season id.
	 * @param string   $end_date    Last day, YYYY-MM-DD, or '' to leave it open (it then runs until the next season starts).
	 * @param string[] $lms_seasons LMS season names.
	 * @return true|WP_Error
	 */
	public static function update_details( $id, $end_date, array $lms_seasons ) {
		global $wpdb;

		$season = self::get( $id );
		if ( ! $season ) {
			return new WP_Error( 'season_missing', __( 'That season could not be found.', 'chess-army-knife' ) );
		}

		$end_date = trim( sanitize_text_field( $end_date ) );
		if ( '' !== $end_date && ( ! Chess_Army_Knife_Memberships::is_valid_date( $end_date ) || $end_date < $season['start_date'] ) ) {
			return new WP_Error( 'season_end', __( 'The last day has to be a date on or after the first day.', 'chess-army-knife' ) );
		}

		$names = self::clean_lms_names( $lms_seasons );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal.
		$wpdb->update(
			self::seasons_table(),
			array(
				'end_date'    => '' === $end_date ? null : $end_date,
				'lms_seasons' => wp_json_encode( $names ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		self::take_lms_seasons_from_others( (int) $id, $names );
		self::forget_windows();
		return true;
	}

	/* -------------------------------------------------------------
	 * LMS seasons
	 * ------------------------------------------------------------- */

	/**
	 * Tidy a list of LMS season names.
	 *
	 * @param array $names Names, as typed or ticked.
	 * @return string[] Unique, non-empty names.
	 */
	protected static function clean_lms_names( array $names ) {
		$clean = array();
		foreach ( $names as $name ) {
			$name = mb_substr( trim( sanitize_text_field( (string) $name ) ), 0, 100 );
			if ( '' !== $name ) {
				$clean[] = $name;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * An LMS season belongs to one season of ours, so choosing it for one takes it from any other.
	 *
	 * @param int      $id    The season that now has the names.
	 * @param string[] $names LMS season names.
	 */
	protected static function take_lms_seasons_from_others( $id, array $names ) {
		global $wpdb;

		if ( ! $names ) {
			return;
		}
		foreach ( self::all() as $season ) {
			$kept = array_values( array_diff( $season['lms_seasons'], $names ) );
			if ( $season['id'] !== (int) $id && count( $kept ) !== count( $season['lms_seasons'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal.
				$wpdb->update( self::seasons_table(), array( 'lms_seasons' => wp_json_encode( $kept ) ), array( 'id' => $season['id'] ), array( '%s' ), array( '%d' ) );
			}
		}
	}

	/**
	 * The season of ours that an LMS season belongs to.
	 *
	 * @param string $lms_name The LMS's name for the season, such as "2025-2026".
	 * @return array|null See all(); null if none has been chosen for it.
	 */
	public static function for_lms_season( $lms_name ) {
		foreach ( self::all() as $season ) {
			if ( in_array( (string) $lms_name, $season['lms_seasons'], true ) ) {
				return $season;
			}
		}
		return null;
	}

	/**
	 * The LMS seasons that can be chosen: those the LMS lists for the organisations the club's teams play in, and
	 * those kept on the games already imported, so a season the LMS no longer lists can still be chosen.
	 *
	 * @return bool[] Whether the LMS says it is the running season, by season name, the running one first and then the latest names first.
	 */
	public static function available_lms_seasons() {
		global $wpdb;

		$names = array();

		$orgs = array();
		foreach ( Chess_Army_Knife_Teams::all( true ) as $team ) {
			foreach ( $team['leagues'] as $league ) {
				$orgs[ (string) $league['org'] ] = true;
			}
		}
		foreach ( array_keys( $orgs ) as $org ) {
			$found = Chess_Army_Knife_LMS_Client::get_seasons( $org );
			foreach ( is_wp_error( $found ) ? array() : $found as $season ) {
				if ( '' !== $season['name'] ) {
					$names[ $season['name'] ] = ! empty( $names[ $season['name'] ] ) || 'active' === $season['status'];
				}
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No WordPress API lists the distinct values of a meta key.
		$kept = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", Chess_Army_Knife_Events_Import::META_SEASON ) );
		foreach ( $kept as $name ) {
			if ( ! isset( $names[ $name ] ) ) {
				$names[ $name ] = false;
			}
		}

		uksort(
			$names,
			function ( $a, $b ) use ( $names ) {
				if ( $names[ $a ] !== $names[ $b ] ) {
					return $names[ $a ] ? -1 : 1;
				}
				return strnatcasecmp( $b, $a );
			}
		);
		return $names;
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
	 * @return array[] Each { name, start_date, end_date ('' for none), next_start (first day of the season after, or ''), type_name, paid_on }.
	 */
	public static function paid_seasons( $member_id ) {
		global $wpdb;

		$payments = self::payments_table();
		$seasons  = self::seasons_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom tables; the table names are internal and dynamic values are prepared.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.name, s.start_date, s.end_date, ( SELECT MIN(n.start_date) FROM {$seasons} n WHERE n.start_date > s.start_date ) AS next_start, p.type_name, p.paid_on FROM {$payments} p INNER JOIN {$seasons} s ON s.id = p.season_id WHERE p.member_id = %d ORDER BY s.start_date ASC", (int) $member_id ), ARRAY_A );

		return array_map(
			function ( $row ) {
				return array(
					'name'       => (string) $row['name'],
					'start_date' => (string) $row['start_date'],
					'end_date'   => null === $row['end_date'] ? '' : (string) $row['end_date'],
					'next_start' => null === $row['next_start'] ? '' : (string) $row['next_start'],
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
