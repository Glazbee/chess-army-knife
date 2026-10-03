<?php
/**
 * Client for the ECF (English Chess Federation) ratings public API.
 *
 * Docs: https://rating.englishchess.org.uk/help/api
 *
 * All methods return either a decoded array (the "data" payload from the
 * ECF response) or a WP_Error. Every remote call is cached via
 * Chess_Army_Knife_Cache to stay well inside the API's daily processing budget.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_ECF_Client {

	/** Which column of the ECF club list holds each rating list. */
	const ROSTER_COLUMNS = array(
		'S' => 'std',
		'R' => 'rpd',
		'B' => 'btz',
	);

	const BASE = 'https://rating.englishchess.org.uk/api';

	/**
	 * Build the raw (unhashed) cache key for a games lookup. Exposed
	 * publicly so a block's render.php can show/clear the exact cache
	 * entry backing what it just displayed.
	 *
	 * @param string $player_no ECF rating code.
	 * @param string $domain    Rating domain.
	 * @param int    $limit     Games limit used for the request.
	 * @return string
	 */
	public static function cache_key_games( $player_no, $domain, $limit ) {
		return 'ecf_games_' . self::normalise_code( $player_no ) . '_' . self::normalise_domain( $domain ) . '_' . (int) $limit;
	}

	/**
	 * Raw cache key for a player info lookup.
	 *
	 * @param string $code ECF rating code.
	 * @return string
	 */
	public static function cache_key_player( $code ) {
		return 'ecf_player_' . self::normalise_code( $code );
	}

	/**
	 * Raw cache key for a current-rating lookup.
	 *
	 * @param string $code   ECF rating code.
	 * @param string $domain Rating domain.
	 * @return string
	 */
	public static function cache_key_rating( $code, $domain ) {
		return 'ecf_rating_' . self::normalise_code( $code ) . '_' . self::normalise_domain( $domain );
	}

	/**
	 * Check that a player may be looked up. Nothing is fetched about a player
	 * the club holds no record of: the club's records are told about the code
	 * first, and either already have the person or write them down (see
	 * Chess_Army_Knife_Membership_Store::record_ecf_player()), or refuse.
	 *
	 * @param string $code Normalised ECF rating code.
	 * @return true|WP_Error
	 */
	protected static function gate( $code ) {
		/**
		 * Filter whether a player's details may be fetched from the ECF.
		 *
		 * @param true|WP_Error $allowed True to go ahead, or an error to refuse.
		 * @param string        $code    Normalised ECF rating code.
		 */
		$allowed = apply_filters( 'Chess_Army_Knife_before_ecf_player_lookup', true, $code );
		return is_wp_error( $allowed ) ? $allowed : true;
	}

	/**
	 * Fetch a player's current published rating for a rating list.
	 *
	 * @param string $code   ECF rating code.
	 * @param string $domain S, R, B, SW, RW or BW.
	 * @return array|WP_Error Data incl. revised_rating / original_rating.
	 */
	public static function get_rating( $code, $domain = 'S' ) {
		$code   = self::normalise_code( $code );
		$domain = self::normalise_domain( $domain );

		if ( '' === $code ) {
			return new WP_Error( 'ecf_bad_code', __( 'Please provide an ECF rating code.', 'chess-army-knife' ) );
		}

		$recorded = self::gate( $code );
		if ( is_wp_error( $recorded ) ) {
			return $recorded;
		}

		return Chess_Army_Knife_Cache::remember(
			self::cache_key_rating( $code, $domain ),
			self::ttl( 'rating', 12 * HOUR_IN_SECONDS ),
			function () use ( $code, $domain ) {
				return self::request(
					'/ratings',
					array(
						'player_no' => $code,
						'domain'    => $domain,
						'date'      => gmdate( 'Y-m-d' ),
					)
				);
			}
		);
	}

	/**
	 * Fetch general info for a player from their ECF rating code
	 * (e.g. "120787" for 120787J).
	 *
	 * @param string $code Rating code, letter suffix optional.
	 * @param bool   $gate False only for the lookup that records a player nobody has recorded yet.
	 * @return array|WP_Error
	 */
	public static function get_player_by_code( $code, $gate = true ) {
		$code = self::normalise_code( $code );
		if ( '' === $code ) {
			return new WP_Error( 'ecf_bad_code', __( 'Please provide an ECF rating code.', 'chess-army-knife' ) );
		}

		if ( $gate ) {
			$recorded = self::gate( $code );
			if ( is_wp_error( $recorded ) ) {
				return $recorded;
			}
		}

		$ttl = self::ttl( 'player_info', 12 * HOUR_IN_SECONDS );

		return Chess_Army_Knife_Cache::remember(
			"ecf_player_{$code}",
			$ttl,
			function () use ( $code ) {
				return self::request( '/players/rating-code', array( 'code' => $code ) );
			}
		);
	}

	/**
	 * Ratings for some of a club's players, from the ECF's club roster in one
	 * request instead of one per player.
	 *
	 * The roster lists everyone at the club, including people the club holds no
	 * record of. It is therefore fetched without being cached, and only the
	 * players asked for (those the club already has a record of) are kept: the
	 * rest of the list never leaves this method and is not stored anywhere.
	 *
	 * @param string   $club_code ECF club code, for example 4USL.
	 * @param string   $domain    Rating list: S, R or B (the roster has no online lists).
	 * @param string[] $wanted    ECF rating codes of the recorded players to keep.
	 * @return array|WP_Error Rating (int, or null if unrated) by digits of the ECF code, for each wanted player the roster lists.
	 */
	public static function get_club_ratings( $club_code, $domain, array $wanted ) {
		$club_code = strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', (string) $club_code ) );
		$domain    = self::normalise_domain( $domain );

		if ( '' === $club_code ) {
			return new WP_Error( 'ecf_bad_club', __( 'Please provide an ECF club code.', 'chess-army-knife' ) );
		}
		if ( ! isset( self::ROSTER_COLUMNS[ $domain ] ) ) {
			return new WP_Error( 'ecf_roster_domain', __( 'The ECF club list only has standard, rapid and blitz ratings.', 'chess-army-knife' ) );
		}

		$keep = array();
		foreach ( $wanted as $code ) {
			$digits = self::normalise_code( $code );
			if ( '' !== $digits ) {
				$keep[ $digits ] = true;
			}
		}

		$result = self::request( '/clubs/players', array( 'code' => $club_code ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$columns = array_flip( isset( $result['column_names'] ) ? (array) $result['column_names'] : array() );
		$code_at = isset( $columns['ECF_code'] ) ? $columns['ECF_code'] : null;
		$rate_at = isset( $columns[ self::ROSTER_COLUMNS[ $domain ] ] ) ? $columns[ self::ROSTER_COLUMNS[ $domain ] ] : null;
		if ( null === $code_at || null === $rate_at ) {
			return new WP_Error( 'ecf_bad_response', __( 'The ECF club list was not in the expected form.', 'chess-army-knife' ) );
		}

		$ratings = array();
		foreach ( isset( $result['players'] ) ? (array) $result['players'] : array() as $row ) {
			if ( ! isset( $row[ $code_at ] ) ) {
				continue;
			}
			$digits = self::normalise_code( $row[ $code_at ] );
			if ( ! isset( $keep[ $digits ] ) ) {
				continue; // Someone the club holds no record of: dropped here.
			}
			$rating             = isset( $row[ $rate_at ] ) && is_numeric( $row[ $rate_at ] ) && (int) $row[ $rate_at ] > 0 ? (int) $row[ $rate_at ] : null;
			$ratings[ $digits ] = $rating;
		}

		return $ratings;
	}

	/**
	 * Fill the cached current rating for a player from a rating already fetched,
	 * so what the blocks read is fresh without a request of their own.
	 *
	 * @param string $code   ECF rating code.
	 * @param string $domain Rating list.
	 * @param int    $rating The rating.
	 */
	public static function prime_rating( $code, $domain, $rating ) {
		$key = self::cache_key_rating( $code, $domain );

		Chess_Army_Knife_Cache::forget( $key );
		Chess_Army_Knife_Cache::remember(
			$key,
			self::ttl( 'rating', 12 * HOUR_IN_SECONDS ),
			function () use ( $rating ) {
				return array( 'revised_rating' => (int) $rating );
			}
		);
	}

	/**
	 * Fetch a player's rated games (used to build a rating-over-time
	 * chart, since the ECF API doesn't expose a direct "rating history"
	 * endpoint - each game record carries the player's rating at the time).
	 *
	 * @param string $player_no ECF rating code (numeric part).
	 * @param string $domain    One of S, R, B, SW, RW, BW.
	 * @param int    $limit     Max games to fetch (API caps this internally).
	 * @return array|WP_Error List of game rows, oldest-to-newest not guaranteed.
	 */
	public static function get_games( $player_no, $domain = 'S', $limit = 100 ) {
		$player_no = self::normalise_code( $player_no );
		$domain    = self::normalise_domain( $domain );
		$limit     = max( 1, min( 500, (int) $limit ) );

		if ( '' === $player_no ) {
			return new WP_Error( 'ecf_bad_code', __( 'Please provide an ECF rating code.', 'chess-army-knife' ) );
		}

		$recorded = self::gate( $player_no );
		if ( is_wp_error( $recorded ) ) {
			return $recorded;
		}

		$ttl = self::ttl( 'games', 6 * HOUR_IN_SECONDS );

		return Chess_Army_Knife_Cache::remember(
			self::cache_key_games( $player_no, $domain, $limit ),
			$ttl,
			function () use ( $player_no, $domain, $limit ) {
				$result = self::request(
					'/games',
					array(
						'player_no' => $player_no,
						'domain'    => $domain,
						'limit'     => $limit,
					)
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return isset( $result['games'] ) ? $result['games'] : array();
			}
		);
	}

	/**
	 * The address of a player's page on the ECF rating site, for a link to their profile.
	 *
	 * The page's address is not part of the documented API, so it can be changed with a filter.
	 *
	 * @param string $player_no ECF rating code.
	 * @return string
	 */
	public static function profile_url( $player_no ) {
		$player_no = self::normalise_code( $player_no );
		$url       = 'https://rating.englishchess.org.uk/v2/new/player.php?ECF_code=' . rawurlencode( $player_no );

		/**
		 * Filter the link to a player's page on the ECF rating site.
		 *
		 * @param string $url       Address.
		 * @param string $player_no Numeric ECF code.
		 */
		return (string) apply_filters( 'Chess_Army_Knife_ecf_profile_url', $url, $player_no );
	}

	/**
	 * Normalise a rating code: keep digits, drop any trailing check letter.
	 *
	 * @param string $code Raw code as typed by an editor user (e.g. "120787J").
	 * @return string Numeric code only.
	 */
	public static function normalise_code( $code ) {
		$code = trim( (string) $code );
		return preg_replace( '/[^0-9]/', '', $code );
	}

	/**
	 * Validate/normalise a rating domain code.
	 *
	 * @param string $domain Requested domain.
	 * @return string One of S, R, B, SW, RW, BW (defaults to S).
	 */
	public static function normalise_domain( $domain ) {
		$domain = strtoupper( trim( (string) $domain ) );
		$valid  = array( 'S', 'R', 'B', 'SW', 'RW', 'BW' );
		return in_array( $domain, $valid, true ) ? $domain : 'S';
	}

	/**
	 * Allow the cache TTL for a given data type to be filtered/overridden
	 * from the settings page or by other code.
	 *
	 * @param string $type    Cache bucket name.
	 * @param int    $default Default TTL in seconds.
	 * @return int
	 */
	protected static function ttl( $type, $default ) {
		$minutes = Chess_Army_Knife_Settings::get_cache_minutes( 'ecf_' . $type, (int) ( $default / MINUTE_IN_SECONDS ) );
		return max( 60, (int) $minutes * MINUTE_IN_SECONDS );
	}

	/**
	 * Perform the actual HTTP request against the ECF API and unwrap the
	 * standard {success, message, data} envelope.
	 *
	 * @param string $path  Path relative to BASE, e.g. "/players/rating-code".
	 * @param array  $query Query args.
	 * @return array|WP_Error The "data" payload on success.
	 */
	protected static function request( $path, $query = array() ) {
		$url = add_query_arg( array_map( 'rawurlencode', $query ), self::BASE . $path );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 12,
				'user-agent' => 'chess-army-knife WordPress Plugin/' . Chess_Army_Knife_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( null === $json && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'ecf_bad_response', __( 'The ECF ratings service returned an unreadable response.', 'chess-army-knife' ) );
		}

		if ( $code >= 400 || empty( $json['success'] ) ) {
			$message = isset( $json['message'] ) ? $json['message'] : sprintf(
				/* translators: %d: HTTP status code */
				__( 'ECF ratings service error (HTTP %d).', 'chess-army-knife' ),
				$code
			);
			return new WP_Error( 'ecf_api_error', $message, array( 'status' => $code ) );
		}

		return isset( $json['data'] ) ? $json['data'] : array();
	}
}
