<?php
/**
 * Payment reminders: a daily job emails the current members who have not yet
 * paid for the season now running.
 *
 * The schedule is a list of days since the season started (0,14,28 is the
 * day it starts and two and four weeks later). Each member gets at most one
 * email per stage for one season: the stage last sent is written on their
 * record as "season|stage", so nobody is emailed twice, and a new season
 * starts a fresh cycle. A member who is still unpaid part-way through the
 * schedule is sent the stage they are in, not the ones already gone.
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
	const DEFAULT_SCHEDULE = '0,14,28';

	/** The last stage, missed by more than this many days (for example the job was off), is skipped, not sent late. */
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
	 * @param string $text For example "0, 14, 28".
	 * @return int[] Days since the season started, earliest first, without duplicates; the default if none are valid.
	 */
	public static function parse_schedule( $text ) {
		$days = array();
		foreach ( explode( ',', (string) $text ) as $part ) {
			$part = trim( $part );
			if ( preg_match( '/^\d+$/', $part ) ) {
				$days[] = min( 365, (int) $part );
			}
		}
		$days = array_slice( array_unique( $days ), 0, 6 );
		if ( ! $days ) {
			return self::parse_schedule( self::DEFAULT_SCHEDULE );
		}
		sort( $days );
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
	 * The stage an unpaid member is in: the latest one already reached.
	 *
	 * @param int   $days_in  Days from the first day of the season to today.
	 * @param int[] $schedule Days from parse_schedule().
	 * @return int|null The stage, or null if the first has not been reached or the last was missed by too long ago.
	 */
	public static function stage_for( $days_in, array $schedule ) {
		$reached = array_filter(
			$schedule,
			function ( $stage ) use ( $days_in ) {
				return $days_in >= $stage;
			}
		);
		if ( ! $reached ) {
			return null;
		}
		$stage = max( $reached );
		// An earlier stage stays current until the next is reached; only the last can be missed for good.
		return max( $schedule ) === $stage && $days_in > $stage + self::GRACE_DAYS ? null : $stage;
	}

	/**
	 * Whether a member has already been sent this stage, or a later one, for the season.
	 *
	 * @param string $marker    Value stored on the record: "season|stage".
	 * @param int    $season_id Season now running.
	 * @param int    $stage     Stage due now.
	 * @return bool
	 */
	public static function already_sent( $marker, $season_id, $stage ) {
		$parts = explode( '|', (string) $marker );
		return 2 === count( $parts ) && (int) $parts[0] === (int) $season_id && (int) $parts[1] >= $stage;
	}

	/**
	 * Who should be emailed now.
	 *
	 * @param string|null $today Site-local date (Y-m-d); today by default.
	 * @return array[] Each { member, stage, season }.
	 */
	public static function due( $today = null ) {
		$today  = $today ? $today : current_time( 'Y-m-d' );
		$season = Chess_Army_Knife_Membership_Seasons::current();
		if ( ! $season ) {
			return array();
		}

		$days_in = (int) round( ( strtotime( $today . ' UTC' ) - strtotime( $season['start_date'] . ' UTC' ) ) / DAY_IN_SECONDS );
		$stage   = self::stage_for( $days_in, self::schedule_days() );
		if ( null === $stage ) {
			return array();
		}

		$due = array();
		foreach ( Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'unpaid' ) ) as $member ) {
			if ( ! self::already_sent( $member['renewal_reminder'], $season['id'], $stage ) ) {
				$due[] = array(
					'member' => $member,
					'stage'  => $stage,
					'season' => $season,
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
			$message = self::compose( $item['member'], $item['season'] );
			if ( Chess_Army_Knife_Mailer::queue( $item['member'], self::CATEGORY, $message['subject'], $message['body'] ) ) {
				++$queued;
			}
			// Noted even if nobody could be written to (no address, or reminders turned off), so it is not retried every day.
			Chess_Army_Knife_Membership_Store::save_member(
				array(
					'id'               => $item['member']['id'],
					'renewal_reminder' => $item['season']['id'] . '|' . $item['stage'],
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
			'subject' => __( 'Membership payment for {name}', 'chess-army-knife' ),
			'body'    => __( "Hello,\n\nThe {type} membership for {name} {when}.\n\n{payment}", 'chess-army-knife' ),
		);
	}

	/**
	 * Write the email for a member.
	 *
	 * @param array $member Member row.
	 * @param array $season The season now running (see Chess_Army_Knife_Membership_Seasons::current()).
	 * @return array { subject, body }
	 */
	public static function compose( array $member, array $season ) {
		$options = Chess_Army_Knife_Settings::get_options();
		$default = self::default_text();
		$subject = '' !== trim( $options['renewal_reminder_subject'] ) ? $options['renewal_reminder_subject'] : $default['subject'];
		$body    = '' !== trim( $options['renewal_reminder_message'] ) ? $options['renewal_reminder_message'] : $default['body'];
		/* translators: %s: name of the season, for example 2026/27 */
		$when = sprintf( __( 'has not been paid for the %s season', 'chess-army-knife' ), $season['name'] );

		$reference    = Chess_Army_Knife_Memberships::payment_reference( $member['id'], $member );
		$instructions = Chess_Army_Knife_Memberships::payment_instructions( $member['id'], $member );
		// Instructions that already say where the reference goes need no sentence added about it.
		$says_reference = false !== strpos( (string) Chess_Army_Knife_Settings::get_options()['membership_payment_info'], '{reference}' );
		if ( '' !== $instructions && '' !== $reference && ! $says_reference ) {
			/* translators: 1: the club's payment instructions, 2: the member's payment reference */
			$payment = sprintf( __( "Please pay as follows:\n\n%1\$s\n\nIf you pay by bank transfer, please quote this reference: %2\$s", 'chess-army-knife' ), $instructions, $reference );
		} elseif ( '' !== $instructions ) {
			/* translators: %s: the club's payment instructions */
			$payment = sprintf( __( "Please pay as follows:\n\n%s", 'chess-army-knife' ), $instructions );
		} elseif ( '' !== $reference ) {
			/* translators: %s: the member's payment reference */
			$payment = sprintf( __( 'To pay, please speak to a club officer. If you pay by bank transfer, please quote this reference: %s', 'chess-army-knife' ), $reference );
		} else {
			$payment = __( 'To pay, please speak to a club officer.', 'chess-army-knife' );
		}

		$replace = array(
			'{name}'      => Chess_Army_Knife_Names::person( $member, Chess_Army_Knife_Names::site_style() ),
			'{type}'      => '' !== $member['type_name'] ? $member['type_name'] : __( 'club', 'chess-army-knife' ),
			'{season}'    => $season['name'],
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
