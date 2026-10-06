<?php
/**
 * Figures about the club's people for the Memberships dashboard.
 *
 * Everything is counted from the people table and nothing is stored about
 * anyone: the result is a set of totals. They are cached for an hour (and
 * dropped whenever a record changes), so opening the dashboard costs nothing.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Member_Stats {

	const CACHE_KEY = 'member_dashboard_';

	/** Lower edges of the rating bands on the ECF scale. */
	const RATING_BANDS = array( 0, 800, 1000, 1200, 1400, 1600, 1800, 2000 );

	/**
	 * Hook up the cache clearing.
	 */
	public static function init() {
		add_action( 'Chess_Army_Knife_members_changed', array( __CLASS__, 'forget' ) );
	}

	/**
	 * Drop the cached figures.
	 */
	public static function forget() {
		Chess_Army_Knife_Cache::forget( self::CACHE_KEY . current_time( 'Y-m-d' ) );
	}

	/**
	 * The figures, cached for an hour.
	 *
	 * @return array See compute().
	 */
	public static function get() {
		$today = current_time( 'Y-m-d' );
		return Chess_Army_Knife_Cache::remember(
			self::CACHE_KEY . $today,
			HOUR_IN_SECONDS,
			function () use ( $today ) {
				$stats                = self::compute( Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'people' ) ), $today );
				$stats['renewal_due'] = count( Chess_Army_Knife_Renewal_Reminders::due( $today ) );
				$stats['as_of']       = time();
				return $stats;
			}
		);
	}

	/**
	 * Work out the figures.
	 *
	 * @param array[] $people Every person held (see Chess_Army_Knife_Membership_Store::get_members()).
	 * @param string  $today  Site-local date, Y-m-d.
	 * @return array Totals only: counts, and lists of label and count.
	 */
	public static function compute( array $people, $today ) {
		$month     = substr( $today, 0, 7 );
		$year      = substr( $today, 0, 4 );
		$bands     = self::RATING_BANDS;
		$stats     = array(
			'current'          => 0,
			'pending'          => 0,
			'unpaid'           => 0,
			'guests'           => 0,
			'joined_month'     => 0,
			'joined_year'      => 0,
			'juniors'          => 0,
			'adults'           => 0,
			'newsletter'       => 0,
			'whatsapp'         => 0,
			'with_ecf_code'    => 0,
			'without_ecf_code' => 0,
			'unrated'          => 0,
		);
		$by_type   = array();
		$by_rating = array_fill( 0, count( $bands ), 0 );

		foreach ( $people as $person ) {
			if ( Chess_Army_Knife_Membership_Store::STATUS_NONMEMBER === $person['status'] ) {
				++$stats['guests'];
				continue;
			}
			if ( Chess_Army_Knife_Membership_Store::STATUS_PENDING === $person['status'] ) {
				++$stats['pending'];
				continue;
			}
			if ( Chess_Army_Knife_Membership_Store::STATUS_ACTIVE !== $person['status'] ) {
				continue; // Declined or cancelled.
			}

			// Joined when their record was made (UTC, so a record made just after midnight may fall in the day before).
			$joined = isset( $person['created_at'] ) ? substr( (string) $person['created_at'], 0, 10 ) : '';
			if ( '' !== $joined ) {
				$stats['joined_month'] += substr( $joined, 0, 7 ) === $month ? 1 : 0;
				$stats['joined_year']  += substr( $joined, 0, 4 ) === $year ? 1 : 0;
			}

			++$stats['current'];
			if ( '' === $person['paid_on'] ) {
				++$stats['unpaid'];
			}

			$type             = '' !== $person['type_name'] ? $person['type_name'] : __( 'No type', 'chess-army-knife' );
			$by_type[ $type ] = ( isset( $by_type[ $type ] ) ? $by_type[ $type ] : 0 ) + 1;

			if ( self::is_junior( $person['date_of_birth'], $today ) ) {
				++$stats['juniors'];
			} else {
				++$stats['adults'];
			}

			$stats['newsletter'] += '' !== $person['newsletter_consent_at'] ? 1 : 0;
			$stats['whatsapp']   += '' !== $person['whatsapp_consent_at'] ? 1 : 0;

			if ( '' === $person['ecf_code'] ) {
				++$stats['without_ecf_code'];
			} else {
				++$stats['with_ecf_code'];
			}

			if ( null === $person['ecf_rating'] ) {
				++$stats['unrated'];
			} else {
				++$by_rating[ self::rating_band( $person['ecf_rating'] ) ];
			}
		}

		arsort( $by_type );

		$stats['by_type'] = array();
		foreach ( $by_type as $label => $count ) {
			$stats['by_type'][] = array( $label, $count );
		}
		$stats['by_rating'] = array();
		foreach ( $by_rating as $index => $count ) {
			$stats['by_rating'][] = array( self::rating_band_label( $index ), $count );
		}

		return $stats;
	}

	/**
	 * Whether someone born on a date is under 18 today. No date of birth counts as an adult.
	 *
	 * @param string $date_of_birth Y-m-d or ''.
	 * @param string $today         Y-m-d.
	 * @return bool
	 */
	public static function is_junior( $date_of_birth, $today ) {
		if ( ! Chess_Army_Knife_Memberships::is_valid_date( $date_of_birth ) ) {
			return false;
		}
		$eighteenth = ( (int) substr( $date_of_birth, 0, 4 ) + 18 ) . substr( $date_of_birth, 4 );
		return $eighteenth > $today;
	}

	/**
	 * The band a rating falls in.
	 *
	 * @param int $rating ECF rating.
	 * @return int Index into RATING_BANDS.
	 */
	public static function rating_band( $rating ) {
		$index = 0;
		foreach ( self::RATING_BANDS as $i => $edge ) {
			if ( $rating >= $edge ) {
				$index = $i;
			}
		}
		return $index;
	}

	/**
	 * A label for a rating band.
	 *
	 * @param int $index Index into RATING_BANDS.
	 * @return string
	 */
	public static function rating_band_label( $index ) {
		$last = count( self::RATING_BANDS ) - 1;
		if ( 0 === $index ) {
			/* translators: %d: rating */
			return sprintf( __( 'Under %d', 'chess-army-knife' ), self::RATING_BANDS[1] );
		}
		if ( $index === $last ) {
			/* translators: %d: rating */
			return sprintf( __( '%d and over', 'chess-army-knife' ), self::RATING_BANDS[ $last ] );
		}
		return self::RATING_BANDS[ $index ] . '–' . ( self::RATING_BANDS[ $index + 1 ] - 1 );
	}
}

Chess_Army_Knife_Member_Stats::init();
