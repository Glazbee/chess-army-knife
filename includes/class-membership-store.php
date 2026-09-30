<?php
/**
 * Database access for club members and membership applications.
 *
 * Applications from the public form and members added by hand share one
 * table: an application is a member whose status is "pending" until it is
 * approved. All queries go through $wpdb with prepared statements or the
 * insert/update helpers. The table name is built from the site prefix, never
 * from user input.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Membership_Store {

	const STATUS_PENDING   = 'pending';
	const STATUS_ACTIVE    = 'active';
	const STATUS_REJECTED  = 'rejected';
	const STATUS_CANCELLED = 'cancelled';

	/** Shown for an active member whose expiry date has passed. */
	const STATUS_EXPIRED = 'expired';

	const SOURCE_FORM   = 'form';
	const SOURCE_MANUAL = 'manual';

	/**
	 * Full name of the members table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'chess_army_knife_members';
	}

	/**
	 * Create or upgrade the members table.
	 */
	public static function install_table() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			email VARCHAR(191) NOT NULL DEFAULT '',
			phone VARCHAR(40) NOT NULL DEFAULT '',
			date_of_birth DATE NULL,
			guardian_name VARCHAR(191) NOT NULL DEFAULT '',
			ecf_code VARCHAR(20) NOT NULL DEFAULT '',
			membership_type_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			type_name VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(12) NOT NULL DEFAULT 'pending',
			source VARCHAR(10) NOT NULL DEFAULT 'form',
			start_date DATE NULL,
			expiry_date DATE NULL,
			payment_method VARCHAR(20) NOT NULL DEFAULT '',
			paid_on DATE NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY email (email)
			) {$charset};"
		);
	}

	/**
	 * The statuses a member can be given by hand, with their labels.
	 *
	 * @return string[]
	 */
	public static function status_labels() {
		return array(
			self::STATUS_PENDING   => __( 'Pending', 'chess-army-knife' ),
			self::STATUS_ACTIVE    => __( 'Active', 'chess-army-knife' ),
			self::STATUS_REJECTED  => __( 'Declined', 'chess-army-knife' ),
			self::STATUS_CANCELLED => __( 'Cancelled', 'chess-army-knife' ),
		);
	}

	/**
	 * A member's status as people would describe it: an active member whose
	 * expiry date has passed is expired.
	 *
	 * @param array  $member Member row.
	 * @param string $today  Today's site-local date, YYYY-MM-DD.
	 * @return string One of the STATUS_ constants.
	 */
	public static function effective_status( array $member, $today ) {
		if ( self::STATUS_ACTIVE === $member['status'] && '' !== (string) $member['expiry_date'] && $member['expiry_date'] < $today ) {
			return self::STATUS_EXPIRED;
		}
		return $member['status'];
	}

	/* -------------------------------------------------------------
	 * Input
	 * ------------------------------------------------------------- */

	/**
	 * Validate and clean submitted member details.
	 *
	 * @param array $input    Raw (unslashed) form values.
	 * @param bool  $is_admin True for the admin form, which may also set the status,
	 *                        dates, payment and notes and add someone without an email
	 *                        address. False for the public application form.
	 * @return array|WP_Error Column => value, or the first problem found.
	 */
	public static function sanitize_member( array $input, $is_admin ) {
		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'member_name', __( 'Please enter a name.', 'chess-army-knife' ) );
		}

		$email = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';
		if ( ( '' === $email && ! $is_admin ) || ( '' !== $email && ! is_email( $email ) ) ) {
			return new WP_Error( 'member_email', __( 'Please enter a valid email address.', 'chess-army-knife' ) );
		}

		$date_of_birth = self::clean_date( $input, 'date_of_birth' );
		if ( null === $date_of_birth || ( '' !== $date_of_birth && $date_of_birth > current_time( 'Y-m-d' ) ) ) {
			return new WP_Error( 'member_dob', __( 'Please enter a valid date of birth.', 'chess-army-knife' ) );
		}

		$type_id = isset( $input['membership_type_id'] ) ? absint( $input['membership_type_id'] ) : 0;
		$type    = $type_id ? Chess_Army_Knife_Memberships::get_type( $type_id ) : null;
		// The public can only choose a type on offer; an admin may keep a type that is no longer offered.
		if ( ( ! $is_admin && ( ! $type || 'publish' !== $type['status'] ) ) || ( $type_id && ! $type ) ) {
			return new WP_Error( 'member_type', __( 'Please choose a membership type.', 'chess-army-knife' ) );
		}

		$member = array(
			'name'               => $name,
			'email'              => $email,
			'phone'              => isset( $input['phone'] ) ? sanitize_text_field( $input['phone'] ) : '',
			'date_of_birth'      => '' === $date_of_birth ? null : $date_of_birth,
			'guardian_name'      => isset( $input['guardian_name'] ) ? sanitize_text_field( $input['guardian_name'] ) : '',
			'ecf_code'           => isset( $input['ecf_code'] ) ? strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', $input['ecf_code'] ) ) : '',
			'membership_type_id' => $type_id,
			'type_name'          => $type ? $type['name'] : '',
		);

		if ( ! $is_admin ) {
			return $member;
		}

		$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : self::STATUS_ACTIVE;
		if ( ! isset( self::status_labels()[ $status ] ) ) {
			return new WP_Error( 'member_status', __( 'Please choose a status.', 'chess-army-knife' ) );
		}

		$start   = self::clean_date( $input, 'start_date' );
		$expiry  = self::clean_date( $input, 'expiry_date' );
		$paid_on = self::clean_date( $input, 'paid_on' );
		if ( null === $start || null === $expiry || null === $paid_on ) {
			return new WP_Error( 'member_date', __( 'Please enter dates as YYYY-MM-DD.', 'chess-army-knife' ) );
		}
		if ( '' !== $start && '' !== $expiry && $expiry < $start ) {
			return new WP_Error( 'member_date_order', __( 'The expiry date cannot be before the start date.', 'chess-army-knife' ) );
		}

		$method = isset( $input['payment_method'] ) ? sanitize_key( $input['payment_method'] ) : '';
		if ( '' !== $method && ! isset( Chess_Army_Knife_Memberships::payment_methods()[ $method ] ) ) {
			$method = '';
		}

		return $member + array(
			'status'         => $status,
			'start_date'     => '' === $start ? null : $start,
			'expiry_date'    => '' === $expiry ? null : $expiry,
			'payment_method' => $method,
			'paid_on'        => '' === $paid_on ? null : $paid_on,
			'notes'          => isset( $input['notes'] ) ? sanitize_textarea_field( $input['notes'] ) : '',
		);
	}

	/**
	 * One optional date field from submitted values.
	 *
	 * @param array  $input Raw values.
	 * @param string $key   Field name.
	 * @return string|null The date, '' if the field is blank, null if it is not a valid date.
	 */
	protected static function clean_date( array $input, $key ) {
		$value = isset( $input[ $key ] ) ? trim( sanitize_text_field( $input[ $key ] ) ) : '';

		if ( '' === $value ) {
			return '';
		}
		return Chess_Army_Knife_Memberships::is_valid_date( $value ) ? $value : null;
	}

	/* -------------------------------------------------------------
	 * Queries
	 * ------------------------------------------------------------- */

	/**
	 * The lists an admin can look at.
	 *
	 * @return string[] Label for each view, keyed by view.
	 */
	public static function view_labels() {
		return array(
			'all'     => __( 'All', 'chess-army-knife' ),
			'active'  => __( 'Current members', 'chess-army-knife' ),
			'pending' => __( 'Pending applications', 'chess-army-knife' ),
			'expired' => __( 'Expired', 'chess-army-knife' ),
			'closed'  => __( 'Declined or cancelled', 'chess-army-knife' ),
		);
	}

	/**
	 * The SQL condition for a view.
	 *
	 * @param string $view  A key of view_labels().
	 * @param string $today Today's site-local date, YYYY-MM-DD.
	 * @return array { condition, values } for $wpdb->prepare().
	 */
	protected static function view_condition( $view, $today ) {
		switch ( $view ) {
			case 'active':
				return array( "status = 'active' AND ( expiry_date IS NULL OR expiry_date >= %s )", array( $today ) );
			case 'pending':
				return array( "status = 'pending'", array() );
			case 'expired':
				return array( "status = 'active' AND expiry_date < %s", array( $today ) );
			case 'closed':
				return array( "status IN ( 'rejected', 'cancelled' )", array() );
		}
		return array( '1 = 1', array() );
	}

	/**
	 * Members and applications.
	 *
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type string $view   A key of view_labels(). Default 'all'.
	 *     @type string $search Part of a name or email address.
	 * }
	 * @return array[]
	 */
	public static function get_members( array $args = array() ) {
		global $wpdb;

		$args  = wp_parse_args(
			$args,
			array(
				'view'   => 'all',
				'search' => '',
			)
		);
		$table = self::table();

		list( $condition, $values ) = self::view_condition( $args['view'], current_time( 'Y-m-d' ) );

		if ( '' !== $args['search'] ) {
			$like       = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$condition .= ' AND ( name LIKE %s OR email LIKE %s )';
			$values[]   = $like;
			$values[]   = $like;
		}

		$order = 'pending' === $args['view'] ? 'created_at ASC' : 'name ASC'; // Oldest application first.
		$sql   = "SELECT * FROM {$table} WHERE {$condition} ORDER BY {$order}";
		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The table name and condition are built above from fixed strings; every value is a placeholder.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned custom table; prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( __CLASS__, 'cast_member' ), (array) $rows );
	}

	/**
	 * How many members and applications a view holds.
	 *
	 * @param string $view A key of view_labels().
	 * @return int
	 */
	public static function count_view( $view ) {
		global $wpdb;

		$table                      = self::table();
		list( $condition, $values ) = self::view_condition( $view, current_time( 'Y-m-d' ) );
		$sql                        = "SELECT COUNT(*) FROM {$table} WHERE {$condition}";
		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The table name and condition are built above from fixed strings; every value is a placeholder.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned custom table; prepared above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * One member or application.
	 *
	 * @param int $id Member id.
	 * @return array|null
	 */
	public static function get_member( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::cast_member( $row ) : null;
	}

	/* -------------------------------------------------------------
	 * Changes
	 * ------------------------------------------------------------- */

	/**
	 * Add a member or application, or update one.
	 *
	 * @param array $data Columns from sanitize_member(), and 'id' to update. A new
	 *                    member also takes 'source' (form by default) and, if it has
	 *                    no 'status', starts pending.
	 * @return int Member id.
	 */
	public static function save_member( array $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		if ( ! empty( $data['id'] ) ) {
			$id = (int) $data['id'];
			unset( $data['id'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$wpdb->update( self::table(), $data, array( 'id' => $id ) );
			return $id;
		}

		unset( $data['id'] );
		$data              += array(
			'status' => self::STATUS_PENDING,
			'source' => self::SOURCE_FORM,
		);
		$data['created_at'] = $data['updated_at'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Approve an application: the membership starts today unless it already
	 * has a start date, and ends after the length of its type unless it
	 * already has an expiry date.
	 *
	 * @param int $id Member id.
	 * @return bool False if there is no such member.
	 */
	public static function approve( $id ) {
		$member = self::get_member( $id );
		if ( ! $member ) {
			return false;
		}

		$start = $member['start_date'] ? $member['start_date'] : current_time( 'Y-m-d' );
		$type  = Chess_Army_Knife_Memberships::get_type( $member['membership_type_id'] );

		self::save_member(
			array(
				'id'          => $member['id'],
				'status'      => self::STATUS_ACTIVE,
				'start_date'  => $start,
				'expiry_date' => $member['expiry_date'] ? $member['expiry_date'] : ( Chess_Army_Knife_Memberships::expiry_from( $start, $type ? $type['months'] : 0 ) ?: null ),
			)
		);
		return true;
	}

	/**
	 * Change only a member's status.
	 *
	 * @param int    $id     Member id.
	 * @param string $status One of the STATUS_ constants.
	 */
	public static function set_status( $id, $status ) {
		self::save_member(
			array(
				'id'     => (int) $id,
				'status' => $status,
			)
		);
	}

	/**
	 * Delete a member or application.
	 *
	 * @param int $id Member id.
	 */
	public static function delete_member( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Normalise types on a member row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	protected static function cast_member( array $row ) {
		$row['id']                 = (int) $row['id'];
		$row['membership_type_id'] = (int) $row['membership_type_id'];

		foreach ( array( 'date_of_birth', 'start_date', 'expiry_date', 'paid_on', 'notes' ) as $key ) {
			$row[ $key ] = null === $row[ $key ] ? '' : (string) $row[ $key ];
		}

		return $row;
	}
}
