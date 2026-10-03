<?php
/**
 * Renewal reminders: a daily job emails current members as their membership
 * approaches its last day, and just after it.
 *
 * The schedule is a list of days relative to the last day of membership
 * (30,7,0,-7 is 30 and 7 days before, the day itself and 7 days after). Each
 * member gets at most one email per stage for one expiry date: the stage last
 * sent is written on their record as "expiry|stage", so nobody is emailed
 * twice, and renewing (which moves the expiry date) starts a fresh cycle. A
 * member who joins part-way through the schedule is sent the stage they are
 * in, not the ones already gone.
 *
 * These are service messages about the membership, so they do not need the
 * newsletter opt-in, but the mailer honours each person's choice to stop them.
 * Payment is never taken on the site: the email carries the club's payment
 * instructions and the member's payment reference.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Renewal_Reminders {

	const HOOK             = 'Chess_Army_Knife_send_renewal_reminders';
	const CATEGORY         = 'renewals';
	const DEFAULT_SCHEDULE = '30,7,0,-7';

	/** A stage missed by more than this many days (for example the job was off) is skipped, not sent late. */
	const GRACE_DAYS = 7;

	/**
	 * Hook up the daily job.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedule the daily job if it is not already.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Stop the job (on deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Whether the club has turned reminders on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$options = Chess_Army_Knife_Settings::get_options();
		return ! empty( $options['renewal_reminders_enabled'] );
	}

	/**
	 * Read a schedule typed as a comma-separated list of days.
	 *
	 * @param string $text For example "30, 7, 0, -7".
	 * @return int[] Days, latest stage last (largest first), without duplicates; the default if none are valid.
	 */
	public static function parse_schedule( $text ) {
		$days = array();
		foreach ( explode( ',', (string) $text ) as $part ) {
			$part = trim( $part );
			if ( preg_match( '/^-?\d+$/', $part ) ) {
				$days[] = max( -90, min( 365, (int) $part ) );
			}
		}
		$days = array_slice( array_unique( $days ), 0, 6 );
		if ( ! $days ) {
			return self::parse_schedule( self::DEFAULT_SCHEDULE );
		}
		rsort( $days );
		return $days;
	}

	/**
	 * The schedule the club has set.
	 *
	 * @return int[]
	 */
	public static function schedule_days() {
		$options = Chess_Army_Knife_Settings::get_options();
		return self::parse_schedule( $options['renewal_reminder_days'] );
	}

	/**
	 * The stage a member is in: the latest one already reached.
	 *
	 * @param int   $days_left Days from today to the last day of membership (negative once it has passed).
	 * @param int[] $schedule  Days from parse_schedule().
	 * @return int|null The stage, or null if the first has not been reached or the last was missed by too long ago.
	 */
	public static function stage_for( $days_left, array $schedule ) {
		$reached = array_filter(
			$schedule,
			function ( $stage ) use ( $days_left ) {
				return $days_left <= $stage;
			}
		);
		if ( ! $reached ) {
			return null;
		}
		$stage = min( $reached );
		// An earlier stage stays current until the next is reached; only the last can be missed for good.
		return min( $schedule ) === $stage && $days_left < $stage - self::GRACE_DAYS ? null : $stage;
	}

	/**
	 * Whether a member has already been sent this stage, or a later one, for their current expiry date.
	 *
	 * @param string $marker Value stored on the record: "expiry|stage".
	 * @param string $expiry Current expiry date.
	 * @param int    $stage  Stage due now.
	 * @return bool
	 */
	public static function already_sent( $marker, $expiry, $stage ) {
		$parts = explode( '|', (string) $marker );
		return 2 === count( $parts ) && $parts[0] === $expiry && (int) $parts[1] <= $stage;
	}

	/**
	 * Who should be emailed now.
	 *
	 * @param string|null $today Site-local date (Y-m-d); today by default.
	 * @return array[] Each { member, stage, days_left }.
	 */
	public static function due( $today = null ) {
		$today    = $today ? $today : current_time( 'Y-m-d' );
		$schedule = self::schedule_days();
		$from     = gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) + ( min( $schedule ) - self::GRACE_DAYS ) * DAY_IN_SECONDS );
		$to       = gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) + max( $schedule ) * DAY_IN_SECONDS );
		$due      = array();

		foreach ( Chess_Army_Knife_Membership_Store::get_expiring( $from, $to ) as $member ) {
			$days_left = (int) round( ( strtotime( $member['expiry_date'] . ' UTC' ) - strtotime( $today . ' UTC' ) ) / DAY_IN_SECONDS );
			$stage     = self::stage_for( $days_left, $schedule );

			if ( null !== $stage && ! self::already_sent( $member['renewal_reminder'], $member['expiry_date'], $stage ) ) {
				$due[] = array(
					'member'    => $member,
					'stage'     => $stage,
					'days_left' => $days_left,
				);
			}
		}
		return $due;
	}

	/**
	 * Queue the reminders that are due. Runs daily.
	 *
	 * @return int How many were queued.
	 */
	public static function run() {
		if ( ! self::is_enabled() ) {
			return 0;
		}

		$queued = 0;
		foreach ( self::due() as $item ) {
			$message = self::compose( $item['member'], $item['days_left'] );
			if ( Chess_Army_Knife_Mailer::queue( $item['member'], self::CATEGORY, $message['subject'], $message['body'] ) ) {
				++$queued;
			}
			// Noted even if nobody could be written to (no address, or reminders turned off), so it is not retried every day.
			Chess_Army_Knife_Membership_Store::save_member(
				array(
					'id'               => $item['member']['id'],
					'renewal_reminder' => $item['member']['expiry_date'] . '|' . $item['stage'],
				)
			);
		}
		return $queued;
	}

	/**
	 * The wording used when the club has not written its own.
	 *
	 * @return string[] { subject, body }
	 */
	public static function default_text() {
		return array(
			/* translators: %s: name of the member the membership is for */
			'subject' => __( 'Membership renewal for {name}', 'chess-army-knife' ),
			'body'    => __( "Hello,\n\nThe {type} membership for {name} {when}.\n\n{payment}", 'chess-army-knife' ),
		);
	}

	/**
	 * Write the email for a member.
	 *
	 * @param array $member    Member row.
	 * @param int   $days_left Days to the last day of membership (negative once passed).
	 * @return array { subject, body }
	 */
	public static function compose( array $member, $days_left ) {
		$options = Chess_Army_Knife_Settings::get_options();
		$default = self::default_text();
		$subject = '' !== trim( $options['renewal_reminder_subject'] ) ? $options['renewal_reminder_subject'] : $default['subject'];
		$body    = '' !== trim( $options['renewal_reminder_message'] ) ? $options['renewal_reminder_message'] : $default['body'];
		$date    = mysql2date( get_option( 'date_format' ), $member['expiry_date'] );
		$days    = (int) $days_left;

		if ( $days > 1 ) {
			/* translators: 1: date, 2: number of days */
			$when = sprintf( __( 'runs out on %1$s (in %2$d days)', 'chess-army-knife' ), $date, $days );
		} elseif ( 1 === $days ) {
			/* translators: %s: date */
			$when = sprintf( __( 'runs out tomorrow, %s', 'chess-army-knife' ), $date );
		} elseif ( 0 === $days ) {
			/* translators: %s: date */
			$when = sprintf( __( 'runs out today, %s', 'chess-army-knife' ), $date );
		} else {
			/* translators: %s: date */
			$when = sprintf( __( 'ran out on %s', 'chess-army-knife' ), $date );
		}

		$reference    = Chess_Army_Knife_Memberships::payment_reference( $member['id'], $member );
		$instructions = Chess_Army_Knife_Memberships::payment_instructions( $member['id'], $member );
		// Instructions that already say where the reference goes need no sentence added about it.
		$says_reference = false !== strpos( (string) Chess_Army_Knife_Settings::get_options()['membership_payment_info'], '{reference}' );
		if ( '' !== $instructions && '' !== $reference && ! $says_reference ) {
			/* translators: 1: the club's payment instructions, 2: the member's payment reference */
			$payment = sprintf( __( "To renew, please pay as follows:\n\n%1\$s\n\nIf you pay by bank transfer, please quote this reference: %2\$s", 'chess-army-knife' ), $instructions, $reference );
		} elseif ( '' !== $instructions ) {
			/* translators: %s: the club's payment instructions */
			$payment = sprintf( __( "To renew, please pay as follows:\n\n%s", 'chess-army-knife' ), $instructions );
		} elseif ( '' !== $reference ) {
			/* translators: %s: the member's payment reference */
			$payment = sprintf( __( 'To renew, please speak to a club officer. If you pay by bank transfer, please quote this reference: %s', 'chess-army-knife' ), $reference );
		} else {
			$payment = __( 'To renew, please speak to a club officer.', 'chess-army-knife' );
		}

		$replace = array(
			'{name}'      => Chess_Army_Knife_Names::person( $member, Chess_Army_Knife_Names::site_style() ),
			'{type}'      => '' !== $member['type_name'] ? $member['type_name'] : __( 'club', 'chess-army-knife' ),
			'{expiry}'    => $date,
			'{when}'      => $when,
			'{reference}' => $reference,
			'{payment}'   => $payment,
		);

		return array(
			'subject' => strtr( $subject, $replace ),
			'body'    => strtr( $body, $replace ),
		);
	}
}

Chess_Army_Knife_Renewal_Reminders::init();
