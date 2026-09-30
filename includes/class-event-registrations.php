<?php
/**
 * Registrations for club events: who is coming, waiting for a place, and
 * (afterwards) who turned up.
 *
 * An event can take registrations (set on the event), with an optional
 * capacity and closing time. Capacity counts places, so a person who brings
 * guests uses one place for themselves and one for each guest. When an event
 * is full people join a waiting list, and the first that fits is moved up when
 * a place is freed. Guests who come with a registrant are only a number; a
 * person who is not a member is recorded as a guest in the people table, so
 * nothing about a person exists without a record there.
 *
 * A registration is personal data: it is in the person's data export, is
 * removed when its event is deleted, and a record with registrations is kept
 * (anonymised) when its person is erased, so past attendance still adds up,
 * in the same way as tournament entries. Old registrations are purged with
 * the retention period.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Event_Registrations {

	const META_ENABLED    = '_chess_army_event_reg_enabled';
	const META_CAPACITY   = '_chess_army_event_reg_capacity';
	const META_CLOSES     = '_chess_army_event_reg_closes';
	const META_MAX_GUESTS = '_chess_army_event_reg_max_guests';

	const STATUS_REGISTERED = 'registered';
	const STATUS_WAITING    = 'waiting';

	/**
	 * Hook up cleanup and retention.
	 */
	public static function init() {
		add_action( 'before_delete_post', array( __CLASS__, 'forget_event' ) );
		add_action( 'Chess_Army_Knife_cleanup_cache', array( __CLASS__, 'prune' ) );
	}

	/**
	 * Full name of the registrations table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'chess_army_knife_registrations';
	}

	/**
	 * Create or upgrade the registrations table.
	 */
	public static function install_table() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			person_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(10) NOT NULL DEFAULT 'registered',
			guests TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			attended TINYINT(1) NOT NULL DEFAULT 0,
			registered_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_person (event_id,person_id),
			KEY person_id (person_id)
			) {$charset};"
		);
	}

	/* -------------------------------------------------------------
	 * Event settings
	 * ------------------------------------------------------------- */

	/**
	 * How an event takes registrations.
	 *
	 * @param int $event_id Event id.
	 * @return array { enabled, capacity (0 = unlimited), closes (site-local "Y-m-d H:i:s" or ''), max_guests }.
	 */
	public static function settings( $event_id ) {
		return array(
			'enabled'    => (bool) get_post_meta( $event_id, self::META_ENABLED, true ),
			'capacity'   => (int) get_post_meta( $event_id, self::META_CAPACITY, true ),
			'closes'     => (string) get_post_meta( $event_id, self::META_CLOSES, true ),
			'max_guests' => (int) get_post_meta( $event_id, self::META_MAX_GUESTS, true ),
		);
	}

	/**
	 * Whether a published event is taking registrations now.
	 *
	 * @param int         $event_id Event id.
	 * @param string|null $now      Site-local "Y-m-d H:i:s"; now by default.
	 * @return bool
	 */
	public static function is_open( $event_id, $now = null ) {
		$post = get_post( $event_id );
		if ( ! $post || Chess_Army_Knife_Events::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}

		$settings = self::settings( $event_id );
		$now      = $now ? $now : current_time( 'mysql' );
		$start    = (string) get_post_meta( $event_id, Chess_Army_Knife_Events::META_START, true );
		$limit    = '' !== $settings['closes'] ? $settings['closes'] : $start; // With no closing time, registration ends when the event starts.

		return $settings['enabled'] && ( '' === $limit || $now < $limit );
	}

	/* -------------------------------------------------------------
	 * Registrations
	 * ------------------------------------------------------------- */

	/**
	 * One registration.
	 *
	 * @param int $event_id  Event id.
	 * @param int $person_id Person id.
	 * @return array|null { id, event_id, person_id, status, guests, attended, registered_at }.
	 */
	public static function get( $event_id, $person_id ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND person_id = %d", (int) $event_id, (int) $person_id ), ARRAY_A );
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * One registration by its id.
	 *
	 * @param int $id Registration id.
	 * @return array|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * An event's registrations, in the order people registered.
	 *
	 * @param int $event_id Event id.
	 * @return array[]
	 */
	public static function for_event( $event_id ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY registered_at ASC, id ASC", (int) $event_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'cast' ), (array) $rows );
	}

	/**
	 * A person's registrations, soonest event first is left to the caller.
	 *
	 * @param int $person_id Person id.
	 * @return array[]
	 */
	public static function for_person( $person_id ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE person_id = %d ORDER BY registered_at DESC", (int) $person_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'cast' ), (array) $rows );
	}

	/**
	 * Places taken: each registered person and their guests.
	 *
	 * @param int $event_id Event id.
	 * @return int
	 */
	public static function places_taken( $event_id ) {
		$taken = 0;
		foreach ( self::for_event( $event_id ) as $registration ) {
			if ( self::STATUS_REGISTERED === $registration['status'] ) {
				$taken += 1 + $registration['guests'];
			}
		}
		return $taken;
	}

	/**
	 * Places left, or null if the event has no capacity.
	 *
	 * @param int $event_id Event id.
	 * @return int|null
	 */
	public static function places_left( $event_id ) {
		$capacity = self::settings( $event_id )['capacity'];
		return $capacity > 0 ? max( 0, $capacity - self::places_taken( $event_id ) ) : null;
	}

	/**
	 * Register a person, or change their guests if they are already registered.
	 * A full event puts them on the waiting list.
	 *
	 * @param int  $event_id  Event id.
	 * @param int  $person_id Person id.
	 * @param int  $guests    Guests coming with them.
	 * @param bool $force     True for an officer adding someone: ignores the closing time and capacity.
	 * @return string|WP_Error 'registered' or 'waiting', or an error if the event is not open or the person is unknown.
	 */
	public static function register( $event_id, $person_id, $guests = 0, $force = false ) {
		global $wpdb;

		if ( ! Chess_Army_Knife_Membership_Store::get_member( $person_id ) ) {
			return new WP_Error( 'person', __( 'There is no such person.', 'chess-army-knife' ) );
		}
		if ( ! $force && ! self::is_open( $event_id ) ) {
			return new WP_Error( 'closed', __( 'Registration for this event is closed.', 'chess-army-knife' ) );
		}

		$settings = self::settings( $event_id );
		$guests   = max( 0, min( $settings['max_guests'], (int) $guests ) );
		$existing = self::get( $event_id, $person_id );
		$capacity = $settings['capacity'];

		// Room is worked out without this person's own current places.
		$taken = self::places_taken( $event_id ) - ( $existing && self::STATUS_REGISTERED === $existing['status'] ? 1 + $existing['guests'] : 0 );
		$fits  = $force || $capacity <= 0 || $taken + 1 + $guests <= $capacity;
		$keeps = $existing && self::STATUS_REGISTERED === $existing['status']; // Someone already in keeps their place when they change guests only if it still fits.
		$state = $fits ? self::STATUS_REGISTERED : self::STATUS_WAITING;

		if ( $keeps && ! $fits ) {
			return new WP_Error( 'full', __( 'There is not room for that many guests.', 'chess-army-knife' ) );
		}

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$wpdb->update(
				self::table(),
				array(
					'status' => $state,
					'guests' => $guests,
				),
				array( 'id' => $existing['id'] ),
				array( '%s', '%d' ),
				array( '%d' )
			);
			return $state;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table; the table name is internal.
		$wpdb->insert(
			self::table(),
			array(
				'event_id'      => (int) $event_id,
				'person_id'     => (int) $person_id,
				'status'        => $state,
				'guests'        => $guests,
				'registered_at' => current_time( 'mysql', true ),
			)
		);
		return $state;
	}

	/**
	 * Cancel a registration. The row is deleted, so nothing about the person is kept, and the waiting list moves up.
	 *
	 * @param int $id Registration id.
	 * @return int[] Person ids moved up from the waiting list.
	 */
	public static function cancel( $id ) {
		global $wpdb;

		$registration = self::get_by_id( $id );
		if ( ! $registration ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );

		return self::STATUS_REGISTERED === $registration['status'] ? self::promote_waiting( $registration['event_id'] ) : array();
	}

	/**
	 * Move people from the waiting list into free places, first come first served.
	 * Someone whose party does not fit yet is passed over for a smaller one.
	 *
	 * @param int $event_id Event id.
	 * @return int[] Person ids moved up.
	 */
	public static function promote_waiting( $event_id ) {
		global $wpdb;

		$left     = self::places_left( $event_id );
		$promoted = array();

		foreach ( self::for_event( $event_id ) as $registration ) {
			if ( self::STATUS_WAITING !== $registration['status'] ) {
				continue;
			}
			$needs = 1 + $registration['guests'];
			if ( null !== $left && $needs > $left ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$wpdb->update( self::table(), array( 'status' => self::STATUS_REGISTERED ), array( 'id' => $registration['id'] ), array( '%s' ), array( '%d' ) );
			$promoted[] = $registration['person_id'];
			$left       = null === $left ? null : $left - $needs;
		}

		return $promoted;
	}

	/**
	 * Record who attended an event.
	 *
	 * @param int   $event_id   Event id.
	 * @param int[] $attended   Person ids that came; every other registered person is marked as not attending.
	 */
	public static function set_attendance( $event_id, array $attended ) {
		global $wpdb;

		$attended = array_map( 'absint', $attended );
		foreach ( self::for_event( $event_id ) as $registration ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$wpdb->update( self::table(), array( 'attended' => in_array( $registration['person_id'], $attended, true ) ? 1 : 0 ), array( 'id' => $registration['id'] ), array( '%d' ), array( '%d' ) );
		}
	}

	/**
	 * Normalise the types of a row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	protected static function cast( array $row ) {
		foreach ( array( 'id', 'event_id', 'person_id', 'guests' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['attended'] = (bool) $row['attended'];
		return $row;
	}

	/* -------------------------------------------------------------
	 * Privacy
	 * ------------------------------------------------------------- */

	/**
	 * Whether a person has any registration (so their record is kept, without details, when erased).
	 *
	 * @param int $person_id Person id.
	 * @return bool
	 */
	public static function person_has_registrations( $person_id ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE person_id = %d", (int) $person_id ) );
	}

	/**
	 * Delete a person's registrations (a record deleted outright).
	 *
	 * @param int $person_id Person id.
	 */
	public static function remove_person( $person_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete( self::table(), array( 'person_id' => (int) $person_id ), array( '%d' ) );
	}

	/**
	 * Delete an event's registrations when the event is deleted.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public static function forget_event( $post_id ) {
		global $wpdb;

		if ( Chess_Army_Knife_Events::POST_TYPE === get_post_type( $post_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$wpdb->delete( self::table(), array( 'event_id' => (int) $post_id ), array( '%d' ) );
		}
	}

	/**
	 * Delete registrations older than the retention period. Run daily.
	 *
	 * @return int How many were deleted.
	 */
	public static function prune() {
		global $wpdb;

		$months = (int) Chess_Army_Knife_Settings::get_options()['member_retention_months'];
		if ( $months <= 0 ) {
			return 0;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE registered_at < %s", gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) ) ) );
	}
}

Chess_Army_Knife_Event_Registrations::init();
