<?php
/**
 * What a person has agreed to be emailed about.
 *
 * Each kind of email is a category. The newsletter is opt-in and uses the
 * consent already recorded on the member. The others are service messages the
 * club sends to its members, so they are on unless the person has turned them
 * off; only those opt-outs are stored, with the time they were made.
 *
 * Every email carries a link that turns its category off. The link is signed
 * for one person and one category, so it needs no login and cannot be used for
 * anyone else. Opening it shows a button; nothing changes until it is pressed
 * (or the address is POSTed to, as mail programs do for one-click unsubscribe),
 * so a mail scanner opening links does not unsubscribe anyone.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Notification_Preferences {

	const NEWSLETTER = 'newsletter';
	const QUERY_VAR  = 'cak_u';

	/**
	 * Hook up the unsubscribe link.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_handle_unsubscribe' ) );
	}

	/**
	 * Full name of the opt-outs table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'chess_army_knife_optouts';
	}

	/**
	 * Create or upgrade the opt-outs table.
	 */
	public static function install_table() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
			person_id BIGINT(20) UNSIGNED NOT NULL,
			category VARCHAR(40) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (person_id,category)
			) {$charset};"
		);
	}

	/**
	 * The kinds of email the club can send, with their labels.
	 *
	 * @return string[] Category => label.
	 */
	public static function categories() {
		$categories = array(
			self::NEWSLETTER => __( 'Newsletter', 'chess-army-knife' ),
			'announcements'  => __( 'Club announcements', 'chess-army-knife' ),
			'event_notices'  => __( 'Event notices', 'chess-army-knife' ),
			'fixtures'       => __( 'Fixture and availability requests', 'chess-army-knife' ),
			'renewals'       => __( 'Membership renewal reminders', 'chess-army-knife' ),
		);

		/**
		 * Filter the kinds of email the club can send (category key => label).
		 *
		 * @param string[] $categories Categories.
		 */
		return apply_filters( 'Chess_Army_Knife_notification_categories', $categories );
	}

	/**
	 * Whether a person may be emailed in a category.
	 *
	 * @param array  $member   Member row.
	 * @param string $category Category key.
	 * @return bool
	 */
	public static function allows( array $member, $category ) {
		if ( ! isset( self::categories()[ $category ] ) ) {
			return false;
		}
		if ( self::NEWSLETTER === $category ) {
			return '' !== $member['newsletter_consent_at'];
		}
		return ! in_array( $category, self::get_opt_outs( $member['id'] ), true );
	}

	/**
	 * The categories a person has turned off.
	 *
	 * @param int $person_id Member id.
	 * @return string[]
	 */
	public static function get_opt_outs( $person_id ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT category FROM {$table} WHERE person_id = %d ORDER BY category ASC", (int) $person_id ) ) );
	}

	/**
	 * Turn a category off for a person. The newsletter is withdrawn on the member's record.
	 *
	 * @param int    $person_id Member id.
	 * @param string $category  Category key.
	 * @return bool False if the category is unknown.
	 */
	public static function opt_out( $person_id, $category ) {
		global $wpdb;

		if ( ! isset( self::categories()[ $category ] ) ) {
			return false;
		}
		if ( self::NEWSLETTER === $category ) {
			Chess_Army_Knife_Membership_Store::save_member(
				array(
					'id'                    => (int) $person_id,
					'newsletter_consent_at' => null,
				)
			);
			return true;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (person_id, category, created_at) VALUES (%d, %s, %s)", (int) $person_id, $category, current_time( 'mysql', true ) ) );
		return true;
	}

	/**
	 * Turn a category back on for a person. This is only for the person's own choice: the newsletter is given again with a new consent time.
	 *
	 * @param int    $person_id Member id.
	 * @param string $category  Category key.
	 * @return bool False if the category is unknown.
	 */
	public static function opt_in( $person_id, $category ) {
		global $wpdb;

		if ( ! isset( self::categories()[ $category ] ) ) {
			return false;
		}
		if ( self::NEWSLETTER === $category ) {
			Chess_Army_Knife_Membership_Store::save_member(
				array(
					'id'                    => (int) $person_id,
					'newsletter_consent_at' => current_time( 'mysql', true ),
				)
			);
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete(
			self::table(),
			array(
				'person_id' => (int) $person_id,
				'category'  => $category,
			),
			array( '%d', '%s' )
		);
		return true;
	}

	/**
	 * Forget a person's opt-outs (when their record is erased).
	 *
	 * @param int $person_id Member id.
	 */
	public static function remove_person( $person_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete( self::table(), array( 'person_id' => (int) $person_id ), array( '%d' ) );
	}

	/* -------------------------------------------------------------
	 * Unsubscribe link
	 * ------------------------------------------------------------- */

	/**
	 * The signature that makes an unsubscribe link valid for one person and category.
	 *
	 * @param int    $person_id Member id.
	 * @param string $category  Category key.
	 * @return string
	 */
	protected static function sign( $person_id, $category ) {
		return substr( hash_hmac( 'sha256', 'unsubscribe|' . (int) $person_id . '|' . $category, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * The link in an email that turns one category off for one person.
	 *
	 * @param int    $person_id Member id.
	 * @param string $category  Category key.
	 * @return string
	 */
	public static function unsubscribe_url( $person_id, $category ) {
		return add_query_arg(
			self::QUERY_VAR,
			rawurlencode( (int) $person_id . '.' . $category . '.' . self::sign( $person_id, $category ) ),
			home_url( '/' )
		);
	}

	/**
	 * Work out whom and what an unsubscribe link is for.
	 *
	 * @param string $value Value of the link's query argument.
	 * @return array|null { person_id, category }, or null if the link is not valid.
	 */
	public static function parse_unsubscribe( $value ) {
		$parts = explode( '.', (string) $value );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! isset( self::categories()[ $parts[1] ] ) ) {
			return null;
		}
		if ( ! hash_equals( self::sign( (int) $parts[0], $parts[1] ), $parts[2] ) ) {
			return null;
		}
		return array(
			'person_id' => (int) $parts[0],
			'category'  => $parts[1],
		);
	}

	/**
	 * Show the unsubscribe page, and act when it is confirmed.
	 */
	public static function maybe_handle_unsubscribe() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The signed link itself is the authority; nothing is changed by a GET.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The signed link is checked in parse_unsubscribe().
		$link = self::parse_unsubscribe( sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) );
		if ( ! $link ) {
			wp_die( esc_html__( 'This link is not valid.', 'chess-army-knife' ), '', array( 'response' => 400 ) );
		}

		$label = self::categories()[ $link['category'] ];

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			self::opt_out( $link['person_id'], $link['category'] );
			/* translators: %s: kind of email, for example "Newsletter" */
			wp_die( esc_html( sprintf( __( 'Done. You will no longer receive this kind of email from the club (%s).', 'chess-army-knife' ), $label ) ), esc_html__( 'Unsubscribed', 'chess-army-knife' ), array( 'response' => 200 ) );
		}

		$form = '<form method="post" action="' . esc_url( self::unsubscribe_url( $link['person_id'], $link['category'] ) ) . '"><p>'
			/* translators: %s: kind of email, for example "Newsletter" */
			. esc_html( sprintf( __( 'Stop receiving this kind of email from the club: %s.', 'chess-army-knife' ), $label ) )
			. '</p><p><button type="submit" class="button">' . esc_html__( 'Unsubscribe', 'chess-army-knife' ) . '</button></p></form>';
		wp_die(
			wp_kses(
				$form,
				array_merge(
					wp_kses_allowed_html( 'post' ),
					array(
						'form'   => array(
							'method' => true,
							'action' => true,
						),
						'button' => array(
							'type'  => true,
							'class' => true,
						),
					)
				)
			),
			esc_html__( 'Unsubscribe', 'chess-army-knife' ),
			array( 'response' => 200 )
		);
	}
}

Chess_Army_Knife_Notification_Preferences::init();
