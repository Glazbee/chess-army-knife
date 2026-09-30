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

	/** Someone the club holds details for who is not a member, such as a tournament guest. */
	const STATUS_NONMEMBER = 'nonmember';

	/** Shown for an active member whose expiry date has passed. */
	const STATUS_EXPIRED = 'expired';

	/** The lowest rating that can be entered by hand for someone without an ECF rating. */
	const MIN_MANUAL_RATING = 1300;
	const MAX_MANUAL_RATING = 4000;

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
			guardian_email VARCHAR(191) NOT NULL DEFAULT '',
			guardian_phone VARCHAR(40) NOT NULL DEFAULT '',
			ecf_code VARCHAR(20) NOT NULL DEFAULT '',
			manual_rating INT(11) NULL,
			ecf_rating INT(11) NULL,
			ecf_rating_domain VARCHAR(2) NOT NULL DEFAULT '',
			ecf_checked_at DATETIME NULL,
			membership_type_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			type_name VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(12) NOT NULL DEFAULT 'pending',
			source VARCHAR(10) NOT NULL DEFAULT 'form',
			start_date DATE NULL,
			expiry_date DATE NULL,
			payment_method VARCHAR(20) NOT NULL DEFAULT '',
			paid_on DATE NULL,
			notes TEXT NULL,
			consent_at DATETIME NULL,
			newsletter_consent_at DATETIME NULL,
			whatsapp_consent_at DATETIME NULL,
			whatsapp_teams TEXT NULL,
			renewal_reminder VARCHAR(24) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY email (email),
			KEY guardian_email (guardian_email),
			KEY ecf_checked_at (ecf_checked_at)
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
			self::STATUS_NONMEMBER => __( 'Not a member', 'chess-army-knife' ),
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
	 * The public form protects juniors (under 18): it asks for a parent or
	 * guardian's details and the junior's date of birth, and keeps the junior's
	 * own email address and phone number only if the parent says the club may
	 * contact the junior directly. An adult's date of birth and any guardian
	 * details are not kept.
	 *
	 * @param array $input    Raw (unslashed) form values.
	 * @param bool  $is_admin True for the admin form, which may also set the status,
	 *                        dates, payment and notes, and stores what it is given
	 *                        (someone can be added without an email address). False
	 *                        for the public application form.
	 * @return array|WP_Error Column => value, or the first problem found.
	 */
	public static function sanitize_member( array $input, $is_admin ) {
		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'member_name', __( 'Please enter a name.', 'chess-army-knife' ) );
		}

		$email          = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';
		$guardian_email = isset( $input['guardian_email'] ) ? sanitize_email( $input['guardian_email'] ) : '';
		if ( ( '' !== $email && ! is_email( $email ) ) || ( '' !== $guardian_email && ! is_email( $guardian_email ) ) ) {
			return new WP_Error( 'member_email', __( 'Please enter a valid email address.', 'chess-army-knife' ) );
		}

		$today         = current_time( 'Y-m-d' );
		$date_of_birth = self::clean_date( $input, 'date_of_birth' );
		if ( null === $date_of_birth || ( '' !== $date_of_birth && $date_of_birth > $today ) ) {
			return new WP_Error( 'member_dob', __( 'Please enter a valid date of birth.', 'chess-army-knife' ) );
		}

		$type_id = isset( $input['membership_type_id'] ) ? absint( $input['membership_type_id'] ) : 0;
		$type    = $type_id ? Chess_Army_Knife_Memberships::get_type( $type_id ) : null;
		// The public can only choose a type on offer; an admin may keep a type that is no longer offered.
		if ( ( ! $is_admin && ( ! $type || 'publish' !== $type['status'] ) ) || ( $type_id && ! $type ) ) {
			return new WP_Error( 'member_type', __( 'Please choose a membership type.', 'chess-army-knife' ) );
		}

		$phone          = isset( $input['phone'] ) ? sanitize_text_field( $input['phone'] ) : '';
		$guardian_name  = isset( $input['guardian_name'] ) ? sanitize_text_field( $input['guardian_name'] ) : '';
		$guardian_phone = isset( $input['guardian_phone'] ) ? sanitize_text_field( $input['guardian_phone'] ) : '';

		if ( ! $is_admin ) {
			// Someone whose date of birth says they are under 18 is a junior even if the box was not ticked.
			$junior = ! empty( $input['is_junior'] ) || Chess_Army_Knife_Memberships::is_under_18( $date_of_birth, $today );

			if ( $junior ) {
				if ( '' === $date_of_birth ) {
					return new WP_Error( 'member_dob', __( 'Please enter the junior\'s date of birth.', 'chess-army-knife' ) );
				}
				if ( '' === $guardian_name || '' === $guardian_email ) {
					return new WP_Error( 'member_guardian', __( 'Please give a parent or guardian\'s name and email address.', 'chess-army-knife' ) );
				}
				// The parent is the contact unless they have said the junior may be contacted directly.
				if ( empty( $input['junior_contact'] ) ) {
					$email = '';
					$phone = '';
				}
			} else {
				if ( '' === $email ) {
					return new WP_Error( 'member_email', __( 'Please enter a valid email address.', 'chess-army-knife' ) );
				}
				// Only needed for juniors, so not kept.
				$date_of_birth  = '';
				$guardian_name  = '';
				$guardian_email = '';
				$guardian_phone = '';
			}
		}

		// A WhatsApp group shows a phone number to the other members, so there must be one to add.
		if ( ! empty( $input['whatsapp'] ) && '' === $phone . $guardian_phone ) {
			return new WP_Error( 'member_whatsapp', __( 'Please give a phone number to be added to the WhatsApp group.', 'chess-army-knife' ) );
		}

		$now    = current_time( 'mysql', true );
		$member = array(
			'name'                  => $name,
			'email'                 => $email,
			'phone'                 => $phone,
			'date_of_birth'         => '' === $date_of_birth ? null : $date_of_birth,
			'guardian_name'         => $guardian_name,
			'guardian_email'        => $guardian_email,
			'guardian_phone'        => $guardian_phone,
			'ecf_code'              => isset( $input['ecf_code'] ) ? strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', $input['ecf_code'] ) ) : '',
			'membership_type_id'    => $type_id,
			'type_name'             => $type ? $type['name'] : '',
			// Optional extras, each agreed separately; blank means not agreed.
			'newsletter_consent_at' => ! empty( $input['newsletter'] ) ? $now : null,
			'whatsapp_consent_at'   => ! empty( $input['whatsapp'] ) ? $now : null,
			// Which teams' groups, only for someone who agreed to WhatsApp, and only teams the club has.
			'whatsapp_teams'        => ! empty( $input['whatsapp'] ) ? self::clean_teams( isset( $input['whatsapp_teams'] ) ? $input['whatsapp_teams'] : array() ) : '',
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

		$rating = null;
		if ( isset( $input['manual_rating'] ) && '' !== trim( (string) $input['manual_rating'] ) ) {
			$rating = (int) $input['manual_rating'];
			if ( $rating < self::MIN_MANUAL_RATING || $rating > self::MAX_MANUAL_RATING ) {
				/* translators: 1: lowest manual rating, 2: highest manual rating */
				return new WP_Error( 'member_rating', sprintf( __( 'A manual rating must be between %1$d and %2$d.', 'chess-army-knife' ), self::MIN_MANUAL_RATING, self::MAX_MANUAL_RATING ) );
			}
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
			'manual_rating'  => $rating,
		);
	}

	/**
	 * The address to write to a member: their own, or a parent or guardian's for a junior.
	 *
	 * @param array $member Member row.
	 * @return string '' if there is none.
	 */
	public static function contact_email( array $member ) {
		return '' !== $member['email'] ? $member['email'] : $member['guardian_email'];
	}

	/**
	 * Keep only the teams the club has, as the text stored for them.
	 *
	 * @param mixed $submitted Team ids ticked on a form.
	 * @return string JSON list of team ids, or '' for none.
	 */
	public static function clean_teams( $submitted ) {
		$allowed = array_keys( Chess_Army_Knife_Teams::choices() );
		$teams   = array();

		foreach ( (array) $submitted as $team ) {
			$team = absint( $team );
			if ( in_array( $team, $allowed, true ) ) {
				$teams[ $team ] = $team;
			}
		}

		return $teams ? wp_json_encode( array_values( $teams ) ) : '';
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
			'all'       => __( 'All members and applications', 'chess-army-knife' ),
			'active'    => __( 'Current members', 'chess-army-knife' ),
			'pending'   => __( 'Pending applications', 'chess-army-knife' ),
			'expired'   => __( 'Expired', 'chess-army-knife' ),
			'closed'    => __( 'Declined or cancelled', 'chess-army-knife' ),
			'nonmember' => __( 'Not members', 'chess-army-knife' ),
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
			case 'nonmember':
				return array( "status = 'nonmember'", array() );
			case 'people':
				return array( '1 = 1', array() ); // Everyone held, members or not; not a list an admin picks.
		}
		// Members and applications: people who are not members stay out of the club's lists and counts.
		return array( "status <> 'nonmember'", array() );
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
			$condition .= ' AND ( name LIKE %s OR email LIKE %s OR guardian_email LIKE %s )';
			$values[]   = $like;
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

	/**
	 * The people who can play in the club's tournaments and be chosen in the
	 * blocks: current members and people held as not being members (guests).
	 * Applicants, lapsed members and those who left are not offered.
	 *
	 * @param string $term  Part of a name, or '' for everyone.
	 * @param bool   $coded True to only include people with an ECF rating code.
	 * @param int    $limit Most people to return (0 for no limit).
	 * @param bool   $members_only True to leave out guests who are not members.
	 * @return array[] Each person row (see get_members()), by name.
	 */
	public static function get_players( $term = '', $coded = false, $limit = 0, $members_only = false ) {
		global $wpdb;

		$table  = self::table();
		$where  = $members_only ? "( status = 'active' AND ( expiry_date IS NULL OR expiry_date >= %s ) )" : "( status = 'nonmember' OR ( status = 'active' AND ( expiry_date IS NULL OR expiry_date >= %s ) ) )";
		$values = array( current_time( 'Y-m-d' ) );

		if ( $coded ) {
			$where .= " AND ecf_code <> ''";
		}
		if ( '' !== trim( (string) $term ) ) {
			$where   .= ' AND name LIKE %s';
			$values[] = '%' . $wpdb->esc_like( trim( (string) $term ) ) . '%';
		}

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY name ASC";
		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d';
			$values[] = (int) $limit;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned custom table; the table name and condition are built above from fixed strings and every value is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		return array_map( array( __CLASS__, 'cast_member' ), (array) $rows );
	}

	/**
	 * Search the players by name, for the block and tournament pickers, in
	 * place of searching the ECF's whole database. Only people with an ECF
	 * rating code are offered.
	 *
	 * @param string $term  Part of a name.
	 * @param int    $limit Most people to return.
	 * @return array[]
	 */
	public static function search_players( $term, $limit = 20 ) {
		if ( strlen( trim( (string) $term ) ) < 2 ) {
			return array();
		}
		return self::get_players( $term, true, max( 1, (int) $limit ) );
	}

	/**
	 * Whether a record is someone who can play: a current member, or a guest.
	 *
	 * @param array $member Member row.
	 * @return bool
	 */
	public static function can_play( array $member ) {
		return self::STATUS_NONMEMBER === $member['status'] || self::STATUS_ACTIVE === self::effective_status( $member, current_time( 'Y-m-d' ) );
	}

	/**
	 * The record that has an ECF rating code. The ECF's own lookups use just the
	 * digits, so a code without its letter (120787) finds one stored with it
	 * (120787J), and a code with a letter also finds one stored without.
	 *
	 * @param string $ecf_code ECF rating code.
	 * @return array|null
	 */
	public static function find_by_ecf_code( $ecf_code ) {
		global $wpdb;

		$ecf_code = strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', (string) $ecf_code ) );
		if ( '' === $ecf_code ) {
			return null;
		}

		$digits = preg_replace( '/\D/', '', $ecf_code );
		$table  = self::table();
		$where  = 'ecf_code = %s';
		$values = array( $ecf_code );

		if ( '' !== $digits && $digits !== $ecf_code ) {
			$where   .= ' OR ecf_code = %s';
			$values[] = $digits;
		} elseif ( $digits === $ecf_code ) {
			$where   .= ' OR ( ecf_code LIKE %s AND CHAR_LENGTH( ecf_code ) = %d )';
			$values[] = $wpdb->esc_like( $digits ) . '_';
			$values[] = strlen( $digits ) + 1;
		}

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned custom table; the table name and condition are built above from fixed strings and every value is a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $values ), ARRAY_A );
		return $row ? self::cast_member( $row ) : null;
	}

	/**
	 * Record someone who is not a member, such as a tournament guest.
	 *
	 * @param array $data name, and optionally ecf_code and manual_rating.
	 * @return int Record id, or 0 if there was no name.
	 */
	public static function add_guest( array $data ) {
		$name = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
		if ( '' === $name ) {
			return 0;
		}

		return self::save_member(
			array(
				'name'          => $name,
				'ecf_code'      => isset( $data['ecf_code'] ) ? strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', (string) $data['ecf_code'] ) ) : '',
				'manual_rating' => isset( $data['manual_rating'] ) ? $data['manual_rating'] : null,
				'status'        => self::STATUS_NONMEMBER,
				'source'        => self::SOURCE_MANUAL,
			)
		);
	}

	/**
	 * Make sure the club has a record for someone who takes part in something
	 * but may not be a member: their existing record if there is one (found by
	 * ECF code, or by name when there is no code), otherwise a new one marked as
	 * not a member. A guest's record is touched so it is not deleted as old
	 * while they are still being used.
	 *
	 * @param string   $name          Name.
	 * @param string   $ecf_code      ECF rating code, or ''.
	 * @param int|null $manual_rating Rating to use when there is no ECF rating.
	 * @return int Record id, or 0 if there was no name.
	 */
	public static function ensure_person( $name, $ecf_code, $manual_rating = null ) {
		global $wpdb;

		$name     = trim( (string) $name );
		$ecf_code = strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', (string) $ecf_code ) );
		if ( '' === $name ) {
			return 0;
		}

		$row = null;
		if ( '' !== $ecf_code ) {
			$row = self::find_by_ecf_code( $ecf_code );
		} else {
			$table = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$found = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE name = %s ORDER BY id ASC LIMIT 1", $name ), ARRAY_A );
			$row   = $found ? self::cast_member( $found ) : null;
		}

		if ( $row ) {
			if ( self::STATUS_NONMEMBER === $row['status'] ) {
				$update = array( 'id' => $row['id'] ); // Refreshes when it was last changed.
				if ( null === $row['manual_rating'] && null !== $manual_rating ) {
					$update['manual_rating'] = $manual_rating;
				}
				self::save_member( $update );
			}
			return $row['id'];
		}

		return self::add_guest(
			array(
				'name'          => $name,
				'ecf_code'      => $ecf_code,
				'manual_rating' => $manual_rating,
			)
		);
	}

	/**
	 * Record a player the ECF has told us about, when their code is used
	 * somewhere but nobody has been recorded under it. This is the one way the
	 * club comes to hold details of someone it did not enter itself, so nothing
	 * is ever shown or fetched about a player without a record. The name is
	 * looked up from the ECF once and kept.
	 *
	 * @param string $ecf_code ECF rating code.
	 * @return int|WP_Error Record id, or an error if the ECF does not know the code.
	 */
	public static function record_ecf_player( $ecf_code ) {
		$existing = self::find_by_ecf_code( $ecf_code );
		if ( $existing ) {
			return $existing['id'];
		}

		$player = Chess_Army_Knife_ECF_Client::get_player_by_code( $ecf_code, false );
		if ( is_wp_error( $player ) ) {
			return $player;
		}

		$name = Chess_Army_Knife_LMS_Client::pick( is_array( $player ) ? $player : array(), array( 'full_name', 'name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'ecf_no_name', __( 'The ECF did not give a name for that rating code.', 'chess-army-knife' ) );
		}

		return self::ensure_person( $name, $ecf_code );
	}

	/**
	 * The ECF client asks this before it fetches anything about a player (see
	 * Chess_Army_Knife_ECF_Client::gate()): the player is recorded first.
	 *
	 * @param true|WP_Error $allowed Result so far.
	 * @param string        $code    ECF rating code.
	 * @return true|WP_Error
	 */
	public static function allow_ecf_lookup( $allowed, $code ) {
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$recorded = self::record_ecf_player( $code );
		return is_wp_error( $recorded ) ? $recorded : true;
	}

	/**
	 * Current members with an ECF rating code whose rating is due a refresh: never
	 * checked, or last checked before a cutoff. The least recently checked come
	 * first, so a batch at a time works through everyone. Guests are not refreshed
	 * in the background; a rating is fetched for them when a tournament starts.
	 *
	 * @param string $cutoff_utc Checked before this UTC "Y-m-d H:i:s", or never.
	 * @param int    $limit      Most members to return.
	 * @return array[]
	 */
	public static function get_due_for_rating( $cutoff_utc, $limit ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE ecf_code <> '' AND status = 'active' AND ( expiry_date IS NULL OR expiry_date >= %s ) AND ( ecf_checked_at IS NULL OR ecf_checked_at < %s ) ORDER BY ecf_checked_at IS NULL DESC, ecf_checked_at ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is internal.
				current_time( 'Y-m-d' ),
				$cutoff_utc,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'cast_member' ), (array) $rows );
	}

	/**
	 * Note the outcome of checking someone's ECF rating. This deliberately does
	 * not touch when the record was last changed, so a background refresh never
	 * keeps an old record alive past its retention period.
	 *
	 * @param int      $id     Member id.
	 * @param int|null $rating Latest rating, or null if the ECF gave none (an earlier rating is then kept).
	 * @param string   $domain Rating list the rating is from, for example S.
	 */
	public static function record_rating_check( $id, $rating, $domain ) {
		global $wpdb;

		$data = array( 'ecf_checked_at' => current_time( 'mysql', true ) );
		if ( null !== $rating ) {
			$data['ecf_rating']        = (int) $rating;
			$data['ecf_rating_domain'] = (string) $domain;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Forget a person's stored ECF rating, for example when their code changes.
	 *
	 * @param int $id Member id.
	 */
	public static function clear_rating( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->update(
			self::table(),
			array(
				'ecf_rating'        => null,
				'ecf_rating_domain' => '',
				'ecf_checked_at'    => null,
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Current members whose membership ends within a range of dates.
	 *
	 * @param string $from First expiry date (Y-m-d).
	 * @param string $to   Last expiry date (Y-m-d).
	 * @return array[]
	 */
	public static function get_expiring( $from, $to ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND expiry_date BETWEEN %s AND %s ORDER BY expiry_date ASC, name ASC", self::STATUS_ACTIVE, $from, $to ), ARRAY_A );
		return array_map( array( __CLASS__, 'cast_member' ), (array) $rows );
	}

	/**
	 * Renew a member: the membership runs on for another period of its type.
	 * It starts the day after the current one ends, or today if that has already
	 * passed. The payment is noted as received today.
	 *
	 * @param int         $id    Member id.
	 * @param string|null $today Site-local date (Y-m-d); today by default.
	 * @return string The new expiry date, or '' if the member cannot be renewed (not a current or lapsed member, or a type that does not expire).
	 */
	public static function renew_member( $id, $today = null ) {
		$member = self::get_member( $id );
		$today  = $today ? $today : current_time( 'Y-m-d' );
		$type   = $member ? Chess_Army_Knife_Memberships::get_type( $member['membership_type_id'] ) : null;

		if ( ! $member || ! $type || $type['months'] <= 0 || self::STATUS_ACTIVE !== $member['status'] ) {
			return '';
		}

		$start = $today;
		if ( $member['expiry_date'] >= $today ) {
			$start = gmdate( 'Y-m-d', strtotime( $member['expiry_date'] . ' UTC' ) + DAY_IN_SECONDS );
		}
		$expiry = Chess_Army_Knife_Memberships::expiry_from( $start, $type['months'] );

		self::save_member(
			array(
				'id'          => (int) $id,
				'expiry_date' => $expiry,
				'paid_on'     => $today,
			)
		);
		return $expiry;
	}

	/**
	 * Everything held under an email address, for the privacy tools. A junior's record is found by their parent or guardian's address as well as their own.
	 *
	 * @param string $email Email address.
	 * @return array[]
	 */
	public static function get_members_by_email( $email ) {
		global $wpdb;

		if ( '' === trim( (string) $email ) ) {
			return array();
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s OR guardian_email = %s ORDER BY id ASC", trim( (string) $email ), trim( (string) $email ) ), ARRAY_A );
		return array_map( array( __CLASS__, 'cast_member' ), (array) $rows );
	}

	/**
	 * Members whose details the club no longer has a reason to keep: applications
	 * and memberships that ended before the cutoff. A current member, or one with
	 * no expiry date, is never included. Records already stripped of personal
	 * details are left out.
	 *
	 * @param string $cutoff_utc  Applications last changed before this UTC "Y-m-d H:i:s" are included.
	 * @param string $cutoff_date Memberships that expired before this site-local date are included.
	 * @return array[]
	 */
	public static function get_stale_members( $cutoff_utc, $cutoff_date ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE name <> %s AND ( ( status IN ( 'pending', 'rejected', 'cancelled', 'nonmember' ) AND updated_at < %s ) OR ( status = 'active' AND expiry_date IS NOT NULL AND expiry_date < %s ) ) ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is internal.
				self::erased_name(),
				$cutoff_utc,
				$cutoff_date
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'cast_member' ), (array) $rows );
	}

	/**
	 * The name given to a record whose personal details have been erased.
	 *
	 * @return string
	 */
	public static function erased_name() {
		return __( 'Erased member', 'chess-army-knife' );
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
			do_action( 'Chess_Army_Knife_members_changed' );
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
		do_action( 'Chess_Army_Knife_members_changed' );
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
	 * Erase a person's details. A record with a payment on it is kept without
	 * the person's details, because the club may need to keep its accounts, and
	 * so is one tagged in photos or entered in a tournament, so those photos can
	 * still be found and reviewed and the tournament still adds up; any other
	 * record is deleted.
	 *
	 * @param int $id Member id.
	 * @return string 'deleted', 'anonymised', or '' if there is no such member.
	 */
	public static function erase_member( $id ) {
		$member = self::get_member( $id );
		if ( ! $member ) {
			return '';
		}

		// A record is kept, without personal details, while it has a payment on it or is tagged
		// in photos: the photos may show other people, so someone has to review them by hand.
		// What they were emailed, and their email choices, are never kept.
		Chess_Army_Knife_Mailer::remove_person( $id );
		Chess_Army_Knife_Notification_Preferences::remove_person( $id );
		Chess_Army_Knife_Teams::remove_person( $id );

		if ( '' === $member['paid_on'] && ! Chess_Army_Knife_Member_Photos::photo_ids( $id ) && ! Chess_Army_Knife_Tournament_Store::person_has_entries( $id ) && ! Chess_Army_Knife_Event_Registrations::person_has_registrations( $id ) ) {
			self::delete_member( $id );
			return 'deleted';
		}

		self::save_member(
			array(
				'id'                    => $member['id'],
				'name'                  => self::erased_name(),
				'email'                 => '',
				'phone'                 => '',
				'date_of_birth'         => null,
				'guardian_name'         => '',
				'guardian_email'        => '',
				'guardian_phone'        => '',
				'ecf_code'              => '',
				'manual_rating'         => null,
				'ecf_rating'            => null,
				'ecf_rating_domain'     => '',
				'ecf_checked_at'        => null,
				'notes'                 => '',
				'consent_at'            => null,
				'newsletter_consent_at' => null,
				'whatsapp_consent_at'   => null,
				'whatsapp_teams'        => '',
				'renewal_reminder'      => '',
			)
		);
		return 'anonymised';
	}

	/**
	 * Delete a member or application.
	 *
	 * @param int $id Member id.
	 */
	public static function delete_member( $id ) {
		global $wpdb;
		Chess_Army_Knife_Member_Photos::remove_member( $id );
		Chess_Army_Knife_Mailer::remove_person( $id );
		Chess_Army_Knife_Notification_Preferences::remove_person( $id );
		Chess_Army_Knife_Teams::remove_person( $id );
		Chess_Army_Knife_Event_Registrations::remove_person( $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
		do_action( 'Chess_Army_Knife_members_changed' );
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
		$row['manual_rating']      = null === $row['manual_rating'] ? null : (int) $row['manual_rating'];
		$row['ecf_rating']         = null === $row['ecf_rating'] ? null : (int) $row['ecf_rating'];
		$row['ecf_checked_at']     = null === $row['ecf_checked_at'] ? '' : (string) $row['ecf_checked_at'];
		$teams                     = json_decode( (string) $row['whatsapp_teams'], true );
		$row['whatsapp_teams']     = is_array( $teams ) ? Chess_Army_Knife_Teams::normalise_ids( $teams ) : array();

		foreach ( array( 'date_of_birth', 'start_date', 'expiry_date', 'paid_on', 'notes', 'consent_at', 'newsletter_consent_at', 'whatsapp_consent_at' ) as $key ) {
			$row[ $key ] = null === $row[ $key ] ? '' : (string) $row[ $key ];
		}

		return $row;
	}
}

add_filter( 'Chess_Army_Knife_before_ecf_player_lookup', array( 'Chess_Army_Knife_Membership_Store', 'allow_ecf_lookup' ), 10, 2 );
