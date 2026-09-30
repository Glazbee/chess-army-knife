<?php
/**
 * Email to members: a queue, a send log, and the rules for who may be written to.
 *
 * Features ask the mailer to write to a person in a category (see
 * Chess_Army_Knife_Notification_Preferences). The mailer checks the person
 * wants that kind of email, finds the address (a junior's goes to their parent
 * or guardian, see Chess_Army_Knife_Membership_Store::contact_email()), queues
 * the message and sends queued mail in small batches through wp_mail(), so a
 * large mailing never blocks a page or overwhelms the site's mail set-up.
 *
 * Addresses are never stored here, only the person's id, so erasing a person
 * leaves nothing to find. Once a message is sent its text is cleared and only
 * the subject and time stay in the log, which is pruned after a while.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Mailer {

	const HOOK         = 'Chess_Army_Knife_send_mail';
	const MAX_ATTEMPTS = 3;

	const STATUS_QUEUED  = 'queued';
	const STATUS_SENT    = 'sent';
	const STATUS_FAILED  = 'failed';
	const STATUS_SKIPPED = 'skipped';

	/**
	 * Hook up the sending job and the log pruning.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'Chess_Army_Knife_cleanup_cache', array( __CLASS__, 'prune_log' ) );
	}

	/**
	 * Full name of the mail table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'chess_army_knife_mail';
	}

	/**
	 * Create or upgrade the mail table.
	 */
	public static function install_table() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			person_id BIGINT(20) UNSIGNED NOT NULL,
			category VARCHAR(40) NOT NULL,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			message LONGTEXT NULL,
			status VARCHAR(10) NOT NULL DEFAULT 'queued',
			attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			queued_at DATETIME NOT NULL,
			sent_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY person_id (person_id)
			) {$charset};"
		);
	}

	/**
	 * Schedule the hourly sending job if it is not already.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Stop the sending job (on deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * How many messages one run sends.
	 *
	 * @return int
	 */
	public static function batch_size() {
		/**
		 * Filter how many queued emails one run sends.
		 *
		 * @param int $size Batch size.
		 */
		return max( 1, (int) apply_filters( 'Chess_Army_Knife_mail_batch', 50 ) );
	}

	/**
	 * Queue a message to a person.
	 *
	 * @param array  $member   Member row.
	 * @param string $category Category key, see Chess_Army_Knife_Notification_Preferences::categories().
	 * @param string $subject  Subject, plain text.
	 * @param string $message  Body, plain text. A footer with the unsubscribe link is added when it is sent.
	 * @return int Id in the queue, or 0 if the person cannot or does not want to be written to.
	 */
	public static function queue( array $member, $category, $subject, $message ) {
		global $wpdb;

		if ( '' === Chess_Army_Knife_Membership_Store::contact_email( $member ) || ! Chess_Army_Knife_Notification_Preferences::allows( $member, $category ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table; the table name is internal.
		$wpdb->insert(
			self::table(),
			array(
				'person_id' => (int) $member['id'],
				'category'  => $category,
				'subject'   => mb_substr( wp_strip_all_tags( $subject ), 0, 255 ),
				'message'   => $message,
				'status'    => self::STATUS_QUEUED,
				'queued_at' => current_time( 'mysql', true ),
			)
		);

		// Send soon rather than waiting for the hourly run.
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );

		return (int) $wpdb->insert_id;
	}

	/**
	 * The message as sent: the text, then a footer saying why and how to stop.
	 *
	 * @param string $message      Body.
	 * @param string $category_label Label of the kind of email.
	 * @param string $unsubscribe  Unsubscribe link.
	 * @param string $site         Site name.
	 * @return string
	 */
	public static function build_body( $message, $category_label, $unsubscribe, $site ) {
		/* translators: 1: site name, 2: kind of email, 3: unsubscribe link */
		$footer = sprintf( __( "You are receiving this because of your connection with %1\$s (%2\$s).\nTo stop emails like this: %3\$s", 'chess-army-knife' ), $site, $category_label, $unsubscribe );
		return rtrim( $message ) . "\n\n-- \n" . $footer . "\n";
	}

	/**
	 * Send a batch of queued messages. Runs from cron.
	 *
	 * @return int How many were sent.
	 */
	public static function run() {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d", self::STATUS_QUEUED, self::batch_size() ), ARRAY_A );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$sent = 0;

		foreach ( (array) $rows as $row ) {
			$member = Chess_Army_Knife_Membership_Store::get_member( (int) $row['person_id'] );
			$to     = $member ? Chess_Army_Knife_Membership_Store::contact_email( $member ) : '';

			// Checked again now: the person may have unsubscribed, or been erased, since it was queued.
			if ( '' === $to || ! Chess_Army_Knife_Notification_Preferences::allows( $member, $row['category'] ) ) {
				self::finish( $row['id'], self::STATUS_SKIPPED );
				continue;
			}

			$unsubscribe = Chess_Army_Knife_Notification_Preferences::unsubscribe_url( $member['id'], $row['category'] );
			$label       = Chess_Army_Knife_Notification_Preferences::categories()[ $row['category'] ];

			if ( wp_mail( $to, $row['subject'], self::build_body( (string) $row['message'], $label, $unsubscribe, $site ) ) ) {
				self::finish( $row['id'], self::STATUS_SENT );
				++$sent;
			} elseif ( (int) $row['attempts'] + 1 >= self::MAX_ATTEMPTS ) {
				self::finish( $row['id'], self::STATUS_FAILED, (int) $row['attempts'] + 1 );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
				$wpdb->update( $table, array( 'attempts' => (int) $row['attempts'] + 1 ), array( 'id' => (int) $row['id'] ), array( '%d' ), array( '%d' ) );
			}
		}

		return $sent;
	}

	/**
	 * Close a queued message: its text is no longer needed.
	 *
	 * @param int    $id       Queue id.
	 * @param string $status   New status.
	 * @param int    $attempts Attempts made, if it changed.
	 */
	protected static function finish( $id, $status, $attempts = null ) {
		global $wpdb;

		$data = array(
			'status'  => $status,
			'message' => null,
			'sent_at' => self::STATUS_SENT === $status ? current_time( 'mysql', true ) : null,
		);
		if ( null !== $attempts ) {
			$data['attempts'] = $attempts;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * What has been sent to (or queued for) a person, newest first.
	 *
	 * @param int $person_id Member id.
	 * @return array[] Each { id, category, subject, status, queued_at, sent_at }.
	 */
	public static function get_log_for_person( $person_id ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, category, subject, status, queued_at, sent_at FROM {$table} WHERE person_id = %d ORDER BY id DESC", (int) $person_id ), ARRAY_A );
	}

	/**
	 * Forget everything logged for a person (when their record is erased).
	 *
	 * @param int $person_id Member id.
	 */
	public static function remove_person( $person_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete( self::table(), array( 'person_id' => (int) $person_id ), array( '%d' ) );
	}

	/**
	 * Delete finished log rows older than the retention period. Run daily.
	 *
	 * @return int How many rows were deleted.
	 */
	public static function prune_log() {
		global $wpdb;

		/**
		 * Filter how many days the send log is kept.
		 *
		 * @param int $days Days.
		 */
		$days  = max( 1, (int) apply_filters( 'Chess_Army_Knife_mail_log_days', 180 ) );
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status <> %s AND queued_at < %s", self::STATUS_QUEUED, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
}

Chess_Army_Knife_Mailer::init();
