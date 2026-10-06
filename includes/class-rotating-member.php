<?php
/**
 * Picking a club member for the Featured Player and Rating Chart blocks. A block either shows one member the
 * editor chose, or rotates: every so often it moves on to another current member whose rating has risen over
 * the period chosen. Only the club's own current members are ever used, so nobody is looked up on the ECF
 * without a record here.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Rotating_Member {

	const NONE  = 'none';
	const HOUR  = 'hour';
	const DAY   = 'day';
	const WEEK  = 'week';
	const MONTH = 'month';

	/** Recent games asked for per member when looking for their rating change. */
	const GAMES_PER_MEMBER = 40;

	/** Most members checked, so the ECF's daily allowance is not used up by a big club. */
	const DEFAULT_POOL_LIMIT = 50;

	/** How long the list of members with growth is kept: a day, as a rise that shows late hardly matters when a block moves on at most weekly. */
	const CACHE_SECONDS = DAY_IN_SECONDS;

	/**
	 * How often a block can move on to another member.
	 *
	 * @return string[] Value => label.
	 */
	public static function rotations() {
		return array(
			self::NONE  => __( 'Never: show the member I choose', 'chess-army-knife' ),
			self::HOUR  => __( 'Every hour', 'chess-army-knife' ),
			self::DAY   => __( 'Every day', 'chess-army-knife' ),
			self::WEEK  => __( 'Every week (from Monday)', 'chess-army-knife' ),
			self::MONTH => __( 'Every month', 'chess-army-knife' ),
		);
	}

	/**
	 * A block's rotation setting, with anything unknown meaning no rotation.
	 *
	 * @param mixed $value Value from the block.
	 * @return string
	 */
	public static function clean_rotation( $value ) {
		return is_string( $value ) && isset( self::rotations()[ $value ] ) ? $value : self::NONE;
	}

	/**
	 * The number of the period a moment falls in: it goes up by one each time the block should move on.
	 *
	 * @param string $rotation  One of the rotations.
	 * @param int    $timestamp Site-local time as a Unix timestamp (see current_time( 'timestamp' )).
	 * @return int
	 */
	public static function slot( $rotation, $timestamp ) {
		$timestamp = (int) $timestamp;
		switch ( $rotation ) {
			case self::HOUR:
				return (int) floor( $timestamp / HOUR_IN_SECONDS );
			case self::DAY:
				return (int) floor( $timestamp / DAY_IN_SECONDS );
			case self::WEEK:
				// 1 January 1970 was a Thursday, so weeks that begin on a Monday are 4 days later.
				return (int) floor( ( $timestamp - 4 * DAY_IN_SECONDS ) / WEEK_IN_SECONDS );
			case self::MONTH:
				return (int) gmdate( 'Y', $timestamp ) * 12 + (int) gmdate( 'n', $timestamp );
		}
		return 0;
	}

	/**
	 * Now on the site's own clock, as a timestamp that reads as local time when formatted with gmdate(), so a
	 * day or week begins at the site's midnight rather than UTC's.
	 *
	 * @return int
	 */
	public static function local_time() {
		return time() + wp_timezone()->getOffset( new DateTimeImmutable( 'now' ) );
	}

	/**
	 * A block's shuffle seed: the text given with the site's secret, so nobody can work out who is next.
	 *
	 * @param string $text Text that sets the block apart, such as the block and rating list.
	 * @return string
	 */
	public static function salt( $text ) {
		return hash( 'sha256', wp_salt( 'auth' ) . $text );
	}

	/**
	 * Pick whose turn it is. The members are put in a random order, and take turns in it, so nobody
	 * comes round again until everyone has had one. Each time the turns run out they are shuffled again.
	 * The shuffle depends only on the seed, so every visitor sees the same member in the same period.
	 *
	 * @param array[] $members   Candidates, each with a code.
	 * @param string  $rotation  One of the rotations other than none.
	 * @param string  $salt      The shuffle seed (see salt()).
	 * @param int     $timestamp Site-local time as a Unix timestamp.
	 * @return array|null The member, or null if there are none.
	 */
	public static function pick( array $members, $rotation, $salt, $timestamp ) {
		$members = array_values( $members );
		$count   = count( $members );
		if ( ! $count ) {
			return null;
		}

		$slot  = self::slot( $rotation, $timestamp );
		$round = intdiv( $slot, $count );
		usort(
			$members,
			function ( $a, $b ) use ( $salt, $round ) {
				return strcmp( hash( 'sha256', $salt . '|' . $round . '|' . $a['code'] ), hash( 'sha256', $salt . '|' . $round . '|' . $b['code'] ) );
			}
		);
		return $members[ $slot % $count ];
	}

	/**
	 * A member's rating change from their recent games: the first and last rating recorded since a date.
	 *
	 * @param array  $games     Games from the ECF client: game_date, player_rating.
	 * @param string $cutoff    Earliest game date to count, Y-m-d.
	 * @param int    $min_games Fewest games with a rating that make a trend.
	 * @return array|null { from, to, gain, games }, or null if there are too few games.
	 */
	public static function growth_from_games( array $games, $cutoff, $min_games ) {
		$points = array();
		foreach ( $games as $game ) {
			if ( empty( $game['game_date'] ) || $game['game_date'] < $cutoff || ! isset( $game['player_rating'] ) || '' === $game['player_rating'] ) {
				continue;
			}
			$points[] = array(
				'date'   => $game['game_date'],
				'rating' => (float) $game['player_rating'],
			);
		}
		if ( count( $points ) < max( 1, (int) $min_games ) ) {
			return null;
		}

		usort(
			$points,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);
		$from = reset( $points )['rating'];
		$to   = end( $points )['rating'];

		return array(
			'from'  => $from,
			'to'    => $to,
			'gain'  => $to - $from,
			'games' => count( $points ),
		);
	}

	/**
	 * Whether a member is a current member: active, and not past their expiry date.
	 *
	 * @param array $member Member row.
	 * @return bool
	 */
	public static function is_current( array $member ) {
		return Chess_Army_Knife_Membership_Store::STATUS_ACTIVE === $member['status'];
	}

	/**
	 * The current member with an ECF code, or null for anyone else (a guest, a lapsed member, or no record).
	 *
	 * @param string $code ECF rating code.
	 * @return array|null Member row.
	 */
	public static function current_member_by_code( $code ) {
		$member = Chess_Army_Knife_Membership_Store::find_by_ecf_code( $code );
		return $member && self::is_current( $member ) ? $member : null;
	}

	/**
	 * Current members whose rating has risen over the period, in name order. The ratings come from the
	 * ECF client's own cache of each member's games, and the answer is kept for six hours.
	 *
	 * @param string $domain    Rating list.
	 * @param int    $days_back Days to look back.
	 * @param int    $min_games Fewest games that make a trend.
	 * @return array[] Each { code, name, nickname, from, to, gain, games }.
	 */
	public static function growing_members( $domain, $days_back, $min_games ) {
		$days_back = max( 1, (int) $days_back );
		$min_games = max( 1, (int) $min_games );
		$key       = 'rotating_members|' . $domain . '|' . $days_back . '|' . $min_games . '|' . current_time( 'Y-m-d' );

		return Chess_Army_Knife_Cache::remember(
			$key,
			self::CACHE_SECONDS,
			function () use ( $domain, $days_back, $min_games ) {
				/**
				 * Filter how many members are checked for rating growth.
				 *
				 * @param int $limit Most members to check.
				 */
				$limit  = max( 1, (int) apply_filters( 'Chess_Army_Knife_rotation_pool_limit', self::DEFAULT_POOL_LIMIT ) );
				$cutoff = gmdate( 'Y-m-d', strtotime( '-' . $days_back . ' days' ) );
				$found  = array();

				foreach ( Chess_Army_Knife_Membership_Store::get_players( '', true, $limit, true ) as $member ) {
					$games = Chess_Army_Knife_ECF_Client::get_games( $member['ecf_code'], $domain, self::GAMES_PER_MEMBER );
					if ( is_wp_error( $games ) || empty( $games ) ) {
						continue;
					}
					$growth = self::growth_from_games( $games, $cutoff, $min_games );
					if ( $growth && $growth['gain'] > 0 ) {
						$found[] = array(
							'code'     => $member['ecf_code'],
							'name'     => $member['name'],
							'nickname' => $member['nickname'],
						) + $growth;
					}
				}
				return $found;
			}
		);
	}
}
