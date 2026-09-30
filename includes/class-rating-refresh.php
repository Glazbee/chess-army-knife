<?php
/**
 * Keeps every current member's ECF rating up to date in the background.
 *
 * An hourly job works through the members who have an ECF rating code, least
 * recently checked first, a small batch at a time. That keeps the load on the
 * ECF's API low and predictable (it limits how much processing one site may use
 * per day), and means visitors never wait for a rating to be fetched: the
 * cached copy the blocks read is always freshly filled. The latest rating is
 * also kept on the member's record.
 *
 * Only people the club holds a record of are ever looked up, and only current
 * members are refreshed this way.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Rating_Refresh {

	const HOOK = 'Chess_Army_Knife_refresh_ratings';

	/** Stop the run after this many members in a row could not be checked: the ECF is likely down or limiting us. */
	const MAX_FAILURES_IN_A_ROW = 3;

	/**
	 * Hook up the job and make sure it is scheduled.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedule the hourly job if it is not already.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Stop the job (on deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * How many members one run checks.
	 *
	 * @return int
	 */
	public static function batch_size() {
		/**
		 * Filter how many members' ratings one hourly run refreshes. Every member
		 * is checked once per cache period, so this only needs to be high enough
		 * to get through the membership in that time.
		 *
		 * @param int $size Members per run, 20 by default.
		 */
		return max( 1, (int) apply_filters( 'Chess_Army_Knife_rating_refresh_batch', 20 ) );
	}

	/**
	 * Refresh the members whose rating is due.
	 *
	 * @param int|null $limit Most members to check, or null for the usual batch.
	 * @return array { checked, updated, failed } counts.
	 */
	public static function run( $limit = null ) {
		$options = Chess_Army_Knife_Settings::get_options();
		$domain  = Chess_Army_Knife_ECF_Client::normalise_domain( $options['default_domain'] );
		$minutes = max( 5, (int) $options['cache_ecf_minutes'] );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS );

		$result   = array(
			'checked' => 0,
			'updated' => 0,
			'failed'  => 0,
		);
		$in_a_row = 0;
		$due      = Chess_Army_Knife_Membership_Store::get_due_for_rating( $cutoff, null === $limit ? self::batch_size() : (int) $limit );

		foreach ( $due as $member ) {
			$code = Chess_Army_Knife_ECF_Client::normalise_code( $member['ecf_code'] );

			// Fetch fresh rather than reuse the cache, so the cache is refilled with today's rating.
			Chess_Army_Knife_Cache::forget( Chess_Army_Knife_ECF_Client::cache_key_rating( $code, $domain ) );
			$data = Chess_Army_Knife_ECF_Client::get_rating( $code, $domain );

			++$result['checked'];

			if ( is_wp_error( $data ) ) {
				// Noted as checked so a code the ECF rejects is not retried every hour.
				Chess_Army_Knife_Membership_Store::record_rating_check( $member['id'], null, $domain );
				++$result['failed'];
				++$in_a_row;
				if ( $in_a_row >= self::MAX_FAILURES_IN_A_ROW ) {
					break;
				}
				continue;
			}

			$in_a_row = 0;
			$rating   = Chess_Army_Knife_Tournaments::rating_from_data( $data );
			Chess_Army_Knife_Membership_Store::record_rating_check( $member['id'], $rating, $domain );
			if ( null !== $rating ) {
				++$result['updated'];
			}
		}

		return $result;
	}
}

Chess_Army_Knife_Rating_Refresh::init();
