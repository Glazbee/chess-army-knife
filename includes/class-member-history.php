<?php
/**
 * Membership history: every period of membership a person has held, so the
 * club can see when someone first joined, how long they have been a member
 * and where their membership lapsed.
 *
 * A period is recorded whenever a member is saved as active with a start
 * date (approving an application, renewing, or editing by hand), one row per
 * start date. People who were members before this table existed have no rows:
 * their current dates are shown as a single period.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Member_History {

	/**
	 * Full name of the history table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'chess_army_knife_member_history';
	}

	/**
	 * Create or upgrade the history table.
	 */
	public static function install_table() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			type_name VARCHAR(191) NOT NULL DEFAULT '',
			start_date DATE NOT NULL,
			expiry_date DATE NULL,
			paid_on DATE NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY member_start (member_id,start_date)
			) {$charset};"
		);
	}

	/* -------------------------------------------------------------
	 * Recording
	 * ------------------------------------------------------------- */

	/**
	 * Note a member's current period. Does nothing unless they are active with a
	 * start date. A period that has already been noted for that start date is
	 * brought up to date (an extended expiry, a payment), so editing a member
	 * never adds a second row for the same period.
	 *
	 * @param int $member_id Member id.
	 */
	public static function record( $member_id ) {
		global $wpdb;

		$member = Chess_Army_Knife_Membership_Store::get_member( $member_id );
		if ( ! $member || Chess_Army_Knife_Membership_Store::STATUS_ACTIVE !== $member['status'] || '' === $member['start_date'] ) {
			return;
		}

		$table = self::table();
		$row   = array(
			'type_name'   => $member['type_name'],
			'expiry_date' => '' === $member['expiry_date'] ? null : $member['expiry_date'],
			'paid_on'     => '' === $member['paid_on'] ? null : $member['paid_on'],
			'updated_at'  => current_time( 'mysql', true ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE member_id = %d AND start_date = %s", (int) $member['id'], $member['start_date'] ) );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table.
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ) );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table.
		$wpdb->insert(
			$table,
			$row + array(
				'member_id'  => (int) $member['id'],
				'start_date' => $member['start_date'],
			)
		);
	}

	/**
	 * Forget a person's history (when their record is erased or deleted).
	 *
	 * @param int $member_id Member id.
	 */
	public static function remove_person( $member_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete( self::table(), array( 'member_id' => (int) $member_id ), array( '%d' ) );
	}

	/* -------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------- */

	/**
	 * The periods of membership a person has held, earliest first.
	 *
	 * @param array $member Member row.
	 * @return array[] Each { start_date, expiry_date ('' for none), type_name, paid_on }.
	 */
	public static function periods( array $member ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT type_name, start_date, expiry_date, paid_on FROM {$table} WHERE member_id = %d ORDER BY start_date ASC", (int) $member['id'] ), ARRAY_A );
		$periods = array();

		foreach ( $rows as $row ) {
			$periods[] = array(
				'start_date'  => (string) $row['start_date'],
				'expiry_date' => null === $row['expiry_date'] ? '' : (string) $row['expiry_date'],
				'type_name'   => (string) $row['type_name'],
				'paid_on'     => null === $row['paid_on'] ? '' : (string) $row['paid_on'],
			);
		}

		// Someone who joined before history was kept: their current dates are all there is.
		if ( ! $periods && '' !== $member['start_date'] && in_array( $member['status'], array( Chess_Army_Knife_Membership_Store::STATUS_ACTIVE, Chess_Army_Knife_Membership_Store::STATUS_CANCELLED ), true ) ) {
			$periods[] = array(
				'start_date'  => $member['start_date'],
				'expiry_date' => $member['expiry_date'],
				'type_name'   => $member['type_name'],
				'paid_on'     => $member['paid_on'],
			);
		}

		return $periods;
	}

	/**
	 * Work out the story of a membership from its periods.
	 *
	 * Periods that overlap or follow on the very next day are one unbroken run
	 * of membership; a gap between runs is a lapse. A period with no expiry date
	 * runs until today.
	 *
	 * @param array[] $periods Periods, earliest first (see periods()).
	 * @param string  $today   Today's site-local date, YYYY-MM-DD.
	 * @return array {
	 *     @type string|null $first_joined Start of the first period, or null if there are none.
	 *     @type string|null $continuous_since Start of the run that includes today, or null if not a member today.
	 *     @type array[]     $runs   Each { from, to } unbroken run of membership, to being '' while it has no end.
	 *     @type array[]     $lapses Each { from, to } gap between runs (the first and last days without membership).
	 *     @type int         $days   Days of membership in all, counting each day once.
	 * }
	 */
	public static function summarise( array $periods, $today ) {
		$runs = array();

		foreach ( $periods as $period ) {
			$from = $period['start_date'];
			$to   = '' === $period['expiry_date'] ? $today : $period['expiry_date'];
			if ( '' === $from || $to < $from ) {
				continue;
			}
			$open = '' === $period['expiry_date'];

			$last = count( $runs ) - 1;
			if ( $last >= 0 && $from <= self::add_days( $runs[ $last ]['to'], 1 ) ) {
				// Overlaps or follows straight on from the run before it.
				if ( $to > $runs[ $last ]['to'] ) {
					$runs[ $last ]['to']   = $to;
					$runs[ $last ]['open'] = $open;
				}
				continue;
			}
			$runs[] = array(
				'from' => $from,
				'to'   => $to,
				'open' => $open,
			);
		}

		$lapses = array();
		$days   = 0;
		foreach ( $runs as $index => $run ) {
			$days += self::days_between( $run['from'], $run['to'] ) + 1;
			if ( $index > 0 ) {
				$lapses[] = array(
					'from' => self::add_days( $runs[ $index - 1 ]['to'], 1 ),
					'to'   => self::add_days( $run['from'], -1 ),
				);
			}
		}

		$last_run = $runs ? $runs[ count( $runs ) - 1 ] : null;

		return array(
			'first_joined'     => $runs ? $runs[0]['from'] : null,
			'continuous_since' => $last_run && $last_run['to'] >= $today ? $last_run['from'] : null,
			'runs'             => array_map(
				function ( $run ) {
					return array(
						'from' => $run['from'],
						'to'   => $run['open'] ? '' : $run['to'],
					);
				},
				$runs
			),
			'lapses'           => $lapses,
			'days'             => $days,
		);
	}

	/**
	 * A length of time as text: "2 years, 3 months", "5 months" or "12 days".
	 *
	 * @param string $from First day, YYYY-MM-DD.
	 * @param string $to   Last day, YYYY-MM-DD (counted as a whole day).
	 * @return string
	 */
	public static function duration_label( $from, $to ) {
		$diff   = ( new DateTimeImmutable( $from . ' UTC' ) )->diff( ( new DateTimeImmutable( $to . ' UTC' ) )->modify( '+1 day' ) );
		$years  = (int) $diff->y;
		$months = (int) $diff->m;
		$parts  = array();

		if ( $years ) {
			/* translators: %d: number of years */
			$parts[] = sprintf( _n( '%d year', '%d years', $years, 'chess-army-knife' ), $years );
		}
		if ( $months ) {
			/* translators: %d: number of months */
			$parts[] = sprintf( _n( '%d month', '%d months', $months, 'chess-army-knife' ), $months );
		}
		if ( ! $parts ) {
			/* translators: %d: number of days */
			$parts[] = sprintf( _n( '%d day', '%d days', (int) $diff->days, 'chess-army-knife' ), (int) $diff->days );
		}
		return implode( ', ', $parts );
	}

	/**
	 * A date some days before or after another.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @param int    $days Days to add (negative to subtract).
	 * @return string YYYY-MM-DD.
	 */
	protected static function add_days( $date, $days ) {
		return gmdate( 'Y-m-d', strtotime( $date . ' UTC' ) + $days * DAY_IN_SECONDS );
	}

	/**
	 * Whole days from one date to a later one.
	 *
	 * @param string $from YYYY-MM-DD.
	 * @param string $to   YYYY-MM-DD.
	 * @return int
	 */
	protected static function days_between( $from, $to ) {
		return (int) round( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS );
	}
}
