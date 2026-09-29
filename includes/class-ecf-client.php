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
	 * Build the raw (unhashed) cache key for a club roster lookup.
	 *
	 * @param string $club_code ECF club code.
	 * @return string
	 */
	public static function cache_key_club_players( $club_code ) {
		return 'ecf_club_players_' . strtoupper( trim( (string) $club_code ) );
	}

	/**
	 * Fetch general info for a player from their ECF rating code
	 * (e.g. "120787" for 120787J).
	 *
	 * @param string $code Rating code, letter suffix optional.
	 * @return array|WP_Error
	 */
	public static function get_player_by_code( $code ) {
		$code = self::normalise_code( $code );
		if ( '' === $code ) {
			return new WP_Error( 'ecf_bad_code', __( 'Please provide an ECF rating code.', 'chess-army-knife' ) );
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
	 * Search players by name (sound-alike search). Used to power the
	 * "find a player" control in the block editor.
	 *
	 * @param string $name Name or partial name.
	 * @return array|WP_Error List of matching players.
	 */
	public static function search_players( $name ) {
		$name = trim( (string) $name );
		if ( strlen( $name ) < 3 ) {
			return array();
		}

		$ttl = self::ttl( 'player_search', HOUR_IN_SECONDS );

		return Chess_Army_Knife_Cache::remember(
			'ecf_search_' . strtolower( $name ),
			$ttl,
			function () use ( $name ) {
				$result = self::request( '/players/fuzzy-names', array( 'name' => $name ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return isset( $result['players'] ) ? $result['players'] : array();
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
	 * Fetch the club roster (players + their latest ratings) for a club code.
	 *
	 * @param string $club_code ECF club code, e.g. "9BAJ".
	 * @return array|WP_Error {
	 *     @type string[] $columns Column names.
	 *     @type array[]  $players Rows matching $columns order.
	 * }
	 */
	public static function get_club_players( $club_code ) {
		$club_code = strtoupper( trim( (string) $club_code ) );
		if ( '' === $club_code ) {
			return new WP_Error( 'ecf_bad_club', __( 'Please provide an ECF club code.', 'chess-army-knife' ) );
		}

		$ttl = self::ttl( 'club_players', 12 * HOUR_IN_SECONDS );

		return Chess_Army_Knife_Cache::remember(
			self::cache_key_club_players( $club_code ),
			$ttl,
			function () use ( $club_code ) {
				$result = self::request( '/clubs/players', array( 'code' => $club_code ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return array(
					'columns' => isset( $result['column_names'] ) ? $result['column_names'] : array(),
					'players' => isset( $result['players'] ) ? $result['players'] : array(),
				);
			}
		);
	}

	/**
	 * Fetch simple club info (name etc.) from a club code.
	 *
	 * @param string $club_code ECF club code.
	 * @return array|WP_Error
	 */
	public static function get_club_info( $club_code ) {
		$club_code = strtoupper( trim( (string) $club_code ) );
		if ( '' === $club_code ) {
			return new WP_Error( 'ecf_bad_club', __( 'Please provide an ECF club code.', 'chess-army-knife' ) );
		}

		$ttl = self::ttl( 'club_info', DAY_IN_SECONDS );

		return Chess_Army_Knife_Cache::remember(
			"ecf_club_info_{$club_code}",
			$ttl,
			function () use ( $club_code ) {
				return self::request( '/clubs/code', array( 'code' => $club_code ) );
			}
		);
	}

	/**
	 * Search clubs by name/word-stem. Powers the club picker in the editor.
	 *
	 * @param string $name Name fragment.
	 * @return array|WP_Error
	 */
	public static function search_clubs( $name ) {
		$name = trim( (string) $name );
		if ( strlen( $name ) < 3 ) {
			return array();
		}

		$ttl = self::ttl( 'club_search', HOUR_IN_SECONDS );

		return Chess_Army_Knife_Cache::remember(
			'ecf_club_search_' . strtolower( $name ),
			$ttl,
			function () use ( $name ) {
				$result = self::request( '/clubs/name', array( 'name' => $name ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return isset( $result['clubs'] ) ? $result['clubs'] : $result;
			}
		);
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
