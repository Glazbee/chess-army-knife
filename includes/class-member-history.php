<?php
/**
 * Membership history: every period of membership a person has held, so the
 * club can see when someone first joined, how long they have been a member
 * and where their membership lapsed.
 *
 * A period is a season the person paid for (see Chess_Army_Knife_Membership_Seasons).
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Member_History {

	/* -------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------- */

	/**
	 * The periods of membership a person has held, earliest first: each season they paid for.
	 *
	 * @param array $member Member row.
	 * @return array[] Each { season (its name), start_date, expiry_date ('' while the season has no end), type_name, paid_on }.
	 */
	public static function periods( array $member ) {
		$periods = array();

		foreach ( Chess_Army_Knife_Membership_Seasons::paid_seasons( $member['id'] ) as $season ) {
			$periods[] = array(
				'season'      => $season['name'],
				'start_date'  => $season['start_date'],
				// Membership carries on over the summer: it runs until the day before the next season starts.
				'expiry_date' => '' === $season['next_start'] ? '' : gmdate( 'Y-m-d', strtotime( $season['next_start'] . ' UTC' ) - DAY_IN_SECONDS ),
				'type_name'   => $season['type_name'],
				'paid_on'     => $season['paid_on'],
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
