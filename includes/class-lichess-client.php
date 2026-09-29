<?php
/**
 * Client for the public Lichess API: finds which of a list of users are
 * playing right now and fetches basic details of those games.
 *
 * Live games change by the minute, so this deliberately caches for
 * seconds rather than the usual hours: one upstream fetch is shared by
 * every visitor. Lichess asks clients to wait a full minute after an
 * HTTP 429, which is honoured with a short back-off.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Lichess_Client {

	const BASE = 'https://lichess.org';

	/** Default seconds live data is cached for. */
	const CACHE_SECONDS = 30;

	/** Seconds to stop calling Lichess after a 429. */
	const BACKOFF_SECONDS = 60;

	/** Most usernames sent per users/status request. */
	const STATUS_BATCH = 40;

	/** Most usernames accepted in one list. */
	const MAX_USERNAMES = 100;

	/** Most live games returned. */
	const MAX_GAMES = 12;

	const BACKOFF_KEY = 'chess_army_knife_lichess_backoff';

	/**
	 * Turn free text (one username per line, or comma separated) into a
	 * de-duplicated, sorted list of valid lower-case Lichess usernames.
	 *
	 * @param string $raw Raw text.
	 * @return string[]
	 */
	public static function parse_usernames( $raw ) {
		$names = array();

		foreach ( preg_split( '/[\s,]+/', (string) $raw ) as $name ) {
			$name = strtolower( trim( $name ) );
			if ( preg_match( '/^[a-z0-9][a-z0-9_-]{0,28}[a-z0-9]$/', $name ) ) {
				$names[ $name ] = $name;
			}
		}

		$names = array_values( $names );
		sort( $names );

		return array_slice( $names, 0, self::MAX_USERNAMES );
	}

	/**
	 * Seconds live data is cached for (never below 15, to protect Lichess).
	 *
	 * @return int
	 */
	public static function cache_seconds() {
		return max( 15, (int) apply_filters( 'chess_army_knife_lichess_cache_seconds', self::CACHE_SECONDS ) );
	}

	/**
	 * Raw cache key for a username list.
	 *
	 * @param string[] $usernames Parsed usernames.
	 * @return string
	 */
	public static function cache_key( array $usernames ) {
		return 'lichess_live_' . md5( implode( ',', $usernames ) );
	}

	/**
	 * Get the games currently being played by any of the users.
	 *
	 * @param string[] $usernames Parsed usernames (see parse_usernames()).
	 * @return array[]|WP_Error Normalised games; empty array when nobody is playing.
	 */
	public static function get_live_games( array $usernames ) {
		if ( empty( $usernames ) ) {
			return array();
		}

		return Chess_Army_Knife_Cache::remember(
			self::cache_key( $usernames ),
			self::cache_seconds(),
			function () use ( $usernames ) {
				return self::fetch_live_games( $usernames );
			}
		);
	}

	/**
	 * Uncached fetch: status of every user, then details of each live game.
	 *
	 * @param string[] $usernames Parsed usernames.
	 * @return array[]|WP_Error
	 */
	protected static function fetch_live_games( array $usernames ) {
		// Game id => usernames from our list who are in it.
		$playing = array();
		$errors  = array();
		$batches = 0;

		foreach ( array_chunk( $usernames, self::STATUS_BATCH ) as $batch ) {
			++$batches;
			$statuses = self::request(
				'/api/users/status',
				array(
					'ids'         => implode( ',', $batch ),
					'withGameIds' => 'true',
				)
			);

			if ( is_wp_error( $statuses ) ) {
				$errors[] = $statuses;
				continue;
			}

			foreach ( $statuses as $status ) {
				if ( ! is_array( $status ) || empty( $status['playingId'] ) || ! is_string( $status['playingId'] ) ) {
					continue;
				}
				$game_id = $status['playingId'];
				if ( ! preg_match( '/^[A-Za-z0-9]{8,12}$/', $game_id ) ) {
					continue;
				}
				$name                  = isset( $status['name'] ) ? (string) $status['name'] : (string) ( $status['id'] ?? '' );
				$playing[ $game_id ][] = $name;
			}
		}

		// Every status request failed: report it rather than "nobody online".
		if ( count( $errors ) === $batches ) {
			return $errors[0];
		}

		$games = array();

		foreach ( array_slice( $playing, 0, self::MAX_GAMES, true ) as $game_id => $members ) {
			$game = self::fetch_game( $game_id, $members );
			if ( $game ) {
				$games[] = $game;
			}
		}

		return $games;
	}

	/**
	 * Fetch and normalise one game. If its details can't be loaded the
	 * game is still returned (link and board work from the id alone).
	 *
	 * @param string   $game_id Lichess game id.
	 * @param string[] $members Usernames from our list playing in it.
	 * @return array|null Null when the game has already finished.
	 */
	protected static function fetch_game( $game_id, array $members ) {
		$raw = self::request( '/game/export/' . rawurlencode( $game_id ), array( 'moves' => 'false' ) );

		$game = array(
			'id'           => $game_id,
			'url'          => self::BASE . '/' . $game_id,
			'embed_url'    => self::BASE . '/embed/game/' . $game_id,
			'members'      => $members,
			'white'        => array(
				'name'   => '',
				'rating' => null,
			),
			'black'        => array(
				'name'   => '',
				'rating' => null,
			),
			'time_control' => '',
			'speed'        => '',
			'rated'        => false,
		);

		if ( is_wp_error( $raw ) || ! is_array( $raw ) ) {
			return $game;
		}

		if ( isset( $raw['status'] ) && ! in_array( $raw['status'], array( 'created', 'started' ), true ) ) {
			return null;
		}

		foreach ( array( 'white', 'black' ) as $colour ) {
			$player = isset( $raw['players'][ $colour ] ) && is_array( $raw['players'][ $colour ] ) ? $raw['players'][ $colour ] : array();

			$game[ $colour ]['name']   = isset( $player['user']['name'] ) ? (string) $player['user']['name'] : '';
			$game[ $colour ]['rating'] = isset( $player['rating'] ) ? (int) $player['rating'] : null;
		}

		$game['speed']        = isset( $raw['speed'] ) ? (string) $raw['speed'] : '';
		$game['rated']        = ! empty( $raw['rated'] );
		$game['time_control'] = self::format_clock( isset( $raw['clock'] ) ? $raw['clock'] : null );

		return $game;
	}

	/**
	 * Format a Lichess clock as "10+5" ("15s+0" for sub-minute starts),
	 * or an empty string for correspondence/unlimited games.
	 *
	 * @param mixed $clock Lichess "clock" object (initial, increment in seconds).
	 * @return string
	 */
	public static function format_clock( $clock ) {
		if ( ! is_array( $clock ) || ! isset( $clock['initial'] ) ) {
			return '';
		}

		$initial   = (int) $clock['initial'];
		$increment = isset( $clock['increment'] ) ? (int) $clock['increment'] : 0;

		return ( 0 === $initial % 60 ? ( $initial / 60 ) : $initial . 's' ) . '+' . $increment;
	}

	/**
	 * GET a JSON endpoint on Lichess, honouring the 429 back-off.
	 *
	 * @param string $path  Path beginning with a slash.
	 * @param array  $query Query arguments.
	 * @return array|WP_Error Decoded JSON.
	 */
	protected static function request( $path, array $query ) {
		if ( get_transient( self::BACKOFF_KEY ) ) {
			return new WP_Error( 'lichess_rate_limited', __( 'Lichess asked us to slow down; live games will return shortly.', 'chess-army-knife' ) );
		}

		$response = wp_remote_get(
			add_query_arg( $query, self::BASE . $path ),
			array(
				'timeout'    => 5,
				'user-agent' => 'chess-army-knife WordPress Plugin/' . Chess_Army_Knife_VERSION . '; ' . home_url( '/' ),
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'lichess_connection_error',
				/* translators: %s: underlying error message */
				sprintf( __( 'Could not reach Lichess (%s).', 'chess-army-knife' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code ) {
			set_transient( self::BACKOFF_KEY, 1, self::BACKOFF_SECONDS );
			return new WP_Error( 'lichess_rate_limited', __( 'Lichess asked us to slow down; live games will return shortly.', 'chess-army-knife' ) );
		}

		if ( $code >= 400 ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'lichess_http_error', sprintf( __( 'Lichess returned an error (HTTP %d).', 'chess-army-knife' ), $code ) );
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'lichess_bad_response', __( 'Lichess returned a response that could not be read.', 'chess-army-knife' ) );
		}

		return $json;
	}
}
