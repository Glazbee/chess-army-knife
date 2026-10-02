<?php
/**
 * Client for the ECF League Management System (LMS) REST service.
 *
 * Docs: https://lms.englishchess.org.uk/lms/node/34
 *
 * ECF describes this API as "experimental" and its field-level shape
 * isn't formally documented, so every accessor here is deliberately
 * defensive: it tries several plausible key names for each value and
 * falls back gracefully rather than fatally erroring. Blocks that use
 * this client also support a "debug" mode that prints the raw decoded
 * JSON so a site admin can see exactly what their organisation's LMS
 * instance returns and, if needed, this parser can be tightened up.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_LMS_Client {

	/**
	 * The ECF's own API docs (lms.englishchess.org.uk/lms/node/34) point
	 * requests at "ecflms.org.uk", but that host no longer resolves -
	 * everything else the LMS publishes (org pages, plain pages, help
	 * pages) lives under lms.englishchess.org.uk, which is what actually
	 * works. We default to that, but still try the documented legacy
	 * host as a fallback in case DNS is restored, and let a site admin
	 * override this entirely from Settings if the ECF moves things again.
	 */
	const BASE        = 'https://lms.englishchess.org.uk/lms/lmsrest/league';
	const LEGACY_BASE = 'https://ecflms.org.uk/lms/lmsrest/league';

	/**
	 * Version 2 of the API: read-only JSON over GET, with an API key. It lists
	 * an organisation's seasons, a season's events and an event's fixtures with
	 * their dates and times. See get_fixtures().
	 */
	const V2_BASE = 'https://lms.englishchess.org.uk/lms/lmsrest/v2';

	/** How long seasons and the events in them are cached: they rarely change. */
	const V2_STRUCTURE_TTL = 6 * HOUR_IN_SECONDS;

	/** How long the results of an earlier season are cached: they no longer change. */
	const V2_HISTORY_TTL = WEEK_IN_SECONDS;

	/**
	 * Fetch the league table (or knockout table / individual standings)
	 * for a named event within an organisation.
	 *
	 * @param string $org        Numeric organisation id.
	 * @param string $event_name Exact event name, e.g. "Division 1".
	 * @return array|WP_Error Raw decoded JSON.
	 */
	public static function get_table( $org, $event_name ) {
		return self::cached_request( 'table', $org, $event_name );
	}

	/**
	 * Fetch match cards for a team event, or round results for an
	 * individual event.
	 *
	 * @param string $org        Numeric organisation id.
	 * @param string $event_name Exact event name.
	 * @return array|WP_Error Raw decoded JSON.
	 */
	public static function get_matches( $org, $event_name ) {
		return self::cached_request( 'match', $org, $event_name );
	}

	/**
	 * Fetch fixtures (or rounds/dates for an individual event).
	 *
	 * @param string $org        Numeric organisation id.
	 * @param string $event_name Exact event name.
	 * @return array|WP_Error Raw decoded JSON.
	 */
	public static function get_events( $org, $event_name ) {
		return self::cached_request( 'event', $org, $event_name );
	}

	/**
	 * Fetch fixtures for a club (by ECF club code) across an organisation.
	 *
	 * @param string $org       Numeric organisation id.
	 * @param string $club_code ECF club code.
	 * @return array|WP_Error Raw decoded JSON.
	 */
	public static function get_club_fixtures( $org, $club_code ) {
		return self::cached_request( 'club', $org, $club_code );
	}

	/**
	 * Build the raw (unhashed) cache key used for a given LMS request.
	 * Exposed publicly so render.php files can show/clear the exact
	 * cache entries backing what they just displayed (the "last
	 * refreshed" / "refresh now" admin control).
	 *
	 * @param string $endpoint One of table, match, event, club.
	 * @param string $org      Numeric organisation id.
	 * @param string $name     Event name or club code.
	 * @return string
	 */
	public static function cache_key( $endpoint, $org, $name ) {
		return "lms2_{$endpoint}_" . trim( (string) $org ) . '_' . strtolower( trim( (string) $name ) );
	}

	/**
	 * Shared cache + request plumbing for the four LMS endpoints, which
	 * all share the same org/name query-string shape.
	 *
	 * @param string $endpoint One of table, match, event, club.
	 * @param string $org      Numeric organisation id.
	 * @param string $name     Event name or club code.
	 * @return array|WP_Error
	 */
	protected static function cached_request( $endpoint, $org, $name ) {
		$org  = trim( (string) $org );
		$name = trim( (string) $name );

		if ( '' === $org || '' === $name ) {
			return new WP_Error( 'lms_missing_params', __( 'An LMS organisation ID and event/club name are required.', 'chess-army-knife' ) );
		}

		$minutes = Chess_Army_Knife_Settings::get_effective_lms_cache_minutes( 'lms_' . $endpoint, 30 );
		$ttl     = max( 60, (int) $minutes * MINUTE_IN_SECONDS );

		return Chess_Army_Knife_Cache::remember(
			self::cache_key( $endpoint, $org, $name ),
			$ttl,
			function () use ( $endpoint, $org, $name ) {
				return self::request( $endpoint, $org, $name );
			}
		);
	}

	/**
	 * Perform the actual HTTP request, trying the configured/primary base
	 * URL first and falling back to the legacy host if the primary one
	 * fails to resolve or connect (not for ordinary 4xx/5xx API errors,
	 * which are meaningful and shouldn't trigger a pointless second call).
	 *
	 * @param string $endpoint One of table, match, event, club.
	 * @param string $org      Numeric organisation id.
	 * @param string $name     Event name or club code (org's "name" param).
	 * @return array|WP_Error
	 */
	protected static function request( $endpoint, $org, $name ) {
		$bases = array_unique(
			array_filter(
				array(
					self::BASE,
					self::LEGACY_BASE,
				)
			)
		);

		$last_error = null;

		foreach ( $bases as $base ) {
			$result = self::do_request( rtrim( $base, '/' ), $endpoint, $org, $name );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last_error = $result;

			// Only fall through to the next host on a connection-level
			// failure (DNS, timeout, refused). A well-formed error
			// response from a reachable host is authoritative - stop.
			if ( 'lms_connection_error' !== $result->get_error_code() ) {
				return $result;
			}
		}

		return $last_error;
	}

	/**
	 * Issue a single request against a specific base URL, trying a short
	 * sequence of request shapes until one is accepted.
	 *
	 * The ECF's own docs list several acceptable request Content-Types,
	 * which only make sense for a request body - confirmed by a plain
	 * GET returning HTTP 405 (Method Not Allowed). But WordPress's
	 * default POST encoding (form-urlencoded, via an array body) was
	 * then rejected with HTTP 415 (Unsupported Media Type): this LMS
	 * instance runs on Drupal 10, whose core REST module is commonly
	 * configured to accept only `application/json` bodies even though
	 * the docs page (which predates this Drupal version) lists more.
	 * So JSON is tried first, with form-urlencoded and a final GET as
	 * fallbacks in case a particular LMS instance differs.
	 *
	 * @param string $base     Base URL, no trailing slash.
	 * @param string $endpoint One of table, match, event, club.
	 * @param string $org      Numeric organisation id.
	 * @param string $name     Event name or club code.
	 * @return array|WP_Error
	 */
	protected static function do_request( $base, $endpoint, $org, $name ) {
		// The service parses whatever follows "league/" as a literal
		// "type" parameter - sending "table.json" gets rejected with
		// {"error":"invalid type table.json"}. The bare type name
		// (table, match, event, club) is what it actually expects; JSON
		// is presumably the default/only format, agreed via the Accept
		// header rather than a URL suffix.
		$url = $base . '/' . $endpoint;

		// Only move on to the next shape for errors that a different
		// request shape might plausibly fix (connection issues, wrong
		// method, wrong content type) - not for a well-formed "not
		// found"/validation-type error, which every shape would hit
		// identically.
		$retryable = array( 'lms_connection_error', 'lms_method_error', 'lms_media_type_error' );

		$attempts   = array(
			array( 'POST', 'json' ),
			array( 'POST', 'form' ),
			array( 'GET', null ),
		);
		$last_error = null;

		foreach ( $attempts as list( $method, $format ) ) {
			$result = self::attempt( $method, $format, $url, $org, $name );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last_error = $result;

			if ( ! in_array( $result->get_error_code(), $retryable, true ) ) {
				return $result;
			}
		}

		return $last_error;
	}

	/**
	 * Issue a single HTTP request with a given method/body shape.
	 *
	 * @param string      $method GET or POST.
	 * @param string|null $format 'json', 'form', or null (no body - GET).
	 * @param string      $url    Full endpoint URL (no query string for POST).
	 * @param string      $org    Numeric organisation id.
	 * @param string      $name   Event name or club code.
	 * @return array|WP_Error
	 */
	protected static function attempt( $method, $format, $url, $org, $name ) {
		$args = array(
			'method'     => $method,
			'timeout'    => 15,
			'user-agent' => 'chess-army-knife WordPress Plugin/' . Chess_Army_Knife_VERSION . '; ' . home_url( '/' ),
			'headers'    => array( 'Accept' => 'application/json' ),
		);

		if ( 'json' === $format ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode(
				array(
					'org'  => $org,
					'name' => $name,
				)
			);
		} elseif ( 'form' === $format ) {
			$args['body'] = array(
				'org'  => $org,
				'name' => $name,
			);
		} else {
			$url = add_query_arg(
				array(
					'org'  => rawurlencode( $org ),
					'name' => rawurlencode( $name ),
				),
				$url
			);
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			// wp_remote_request wraps cURL/DNS failures as a WP_Error whose
			// message includes the underlying reason (e.g. "Could not
			// resolve host"). Treat any transport-level failure here as
			// a signal to try the next candidate shape/host.
			return new WP_Error(
				'lms_connection_error',
				sprintf(
					/* translators: 1: URL tried, 2: underlying error message */
					__( 'Could not reach the LMS service at %1$s (%2$s).', 'chess-army-knife' ),
					$url,
					$response->get_error_message()
				),
				array(
					'method' => $method,
					'url'    => $url,
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$debug = array(
			'method' => $method . ( $format ? " ({$format})" : '' ),
			'url'    => $url,
			'status' => $code,
			'body'   => mb_substr( (string) $body, 0, 4000 ),
		);

		if ( 405 === (int) $code ) {
			return new WP_Error(
				'lms_method_error',
				sprintf(
					/* translators: 1: HTTP method, 2: HTTP status code */
					__( 'The LMS service rejected a %1$s request (HTTP %2$d).', 'chess-army-knife' ),
					$method,
					$code
				),
				$debug
			);
		}

		if ( 415 === (int) $code ) {
			return new WP_Error(
				'lms_media_type_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The LMS service rejected the request body format (HTTP %d).', 'chess-army-knife' ),
					$code
				),
				$debug
			);
		}

		if ( $code >= 400 ) {
			return new WP_Error(
				'lms_http_error',
				sprintf(
					/* translators: 1: HTTP method, 2: HTTP status code */
					__( 'The LMS service returned an error for a %1$s request (HTTP %2$d). Double-check the organisation ID and the exact event/club name.', 'chess-army-knife' ),
					$method,
					$code
				),
				$debug
			);
		}

		$json = json_decode( $body, true );

		if ( null === $json && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'lms_bad_response', __( 'The LMS service returned a response that could not be parsed as JSON.', 'chess-army-knife' ), $debug );
		}

		// The service reports problems as a 200 response containing
		// {"error": "..."} (e.g. "invalid type table.json"). Surface those
		// as errors so they get the short error-cache lifetime instead of
		// being cached as if they were real league data.
		if ( is_array( $json ) && isset( $json['error'] ) && is_string( $json['error'] ) && count( $json ) <= 2 ) {
			return new WP_Error(
				'lms_api_error',
				sprintf(
					/* translators: %s: error text returned by the LMS */
					__( 'The LMS service reported an error: %s', 'chess-army-knife' ),
					$json['error']
				),
				$debug
			);
		}

		if ( empty( $json ) ) {
			return new WP_Error( 'lms_empty', __( 'No data was found. Check the organisation ID and that the event/club name matches exactly (case and spacing included).', 'chess-army-knife' ), $debug );
		}

		return $json;
	}

	/*
	---------------------------------------------------------------
	 * LMS v2 API.
	 * ------------------------------------------------------------- */

	/**
	 * The v2 base URL.
	 *
	 * @return string No trailing slash.
	 */
	public static function v2_base() {
		return self::V2_BASE;
	}

	/**
	 * The API key from Settings.
	 *
	 * @return string
	 */
	public static function api_key() {
		return trim( (string) Chess_Army_Knife_Settings::get_options()['lms_api_key'] );
	}

	/**
	 * An organisation's seasons, newest first as the LMS lists them.
	 *
	 * @param string $org     Numeric organisation id.
	 * @param bool   $refresh Skip the cached copy.
	 * @return array[]|WP_Error Each { id, name, status }; status is "active" for the running season, "old" otherwise.
	 */
	public static function get_seasons( $org, $refresh = false ) {
		$org = trim( (string) $org );
		if ( '' === $org ) {
			return new WP_Error( 'lms_missing_params', __( 'An LMS organisation ID and event/club name are required.', 'chess-army-knife' ) );
		}
		if ( '' === self::api_key() ) {
			return new WP_Error( 'lms_no_api_key', __( 'Add your LMS API key under Settings to read fixtures from the LMS.', 'chess-army-knife' ) );
		}

		$data = self::v2_get( 'org/' . rawurlencode( $org ) . '/seasons', self::V2_STRUCTURE_TTL, $refresh );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$seasons = array();
		foreach ( isset( $data['seasons'] ) && is_array( $data['seasons'] ) ? $data['seasons'] : array() as $season ) {
			if ( isset( $season['id'] ) ) {
				$seasons[] = array(
					'id'     => (int) $season['id'],
					'name'   => isset( $season['name'] ) ? (string) $season['name'] : '',
					'status' => isset( $season['status'] ) ? (string) $season['status'] : '',
				);
			}
		}

		return $seasons;
	}

	/**
	 * Which seasons a season choice means.
	 *
	 * @param array[]         $seasons Seasons from get_seasons().
	 * @param string|int|null $choice  'active' (or empty) for the running season, or a season id or name such as "2025-2026".
	 * @return int[] Season ids, in the order given.
	 */
	public static function seasons_matching( array $seasons, $choice ) {
		$choice = trim( (string) $choice );
		$ids    = array();

		foreach ( $seasons as $season ) {
			if ( '' === $choice || 'active' === strtolower( $choice ) ) {
				$match = 'active' === $season['status'];
			} else {
				$match = (string) $season['id'] === $choice || self::same_name( $season['name'], $choice );
			}
			if ( $match ) {
				$ids[] = (int) $season['id'];
			}
		}

		return $ids;
	}

	/**
	 * The fixtures of a team event, as the rows the rest of the plugin reads
	 * (see normalise_fixture()). The event is found by name: the
	 * organisation's active season (or the season asked for), then the event in
	 * it with that name.
	 *
	 * @param string          $org        Numeric organisation id.
	 * @param string          $event_name Event name, e.g. "Division One"; case and extra spaces are ignored.
	 * @param bool            $refresh    Skip the cached copies, for a manual refresh.
	 * @param string|int|null $season     'active' for the running season, or a season id or name for an earlier one.
	 * @return array[]|WP_Error Normalised rows (empty for an event that has no fixtures, such as an individual one).
	 */
	public static function get_fixtures( $org, $event_name, $refresh = false, $season = 'active' ) {
		$org        = trim( (string) $org );
		$event_name = trim( (string) $event_name );

		if ( '' === $org || '' === $event_name ) {
			return new WP_Error( 'lms_missing_params', __( 'An LMS organisation ID and event/club name are required.', 'chess-army-knife' ) );
		}

		$found = self::find_event( $org, $event_name, $season, $refresh );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$event_id  = $found['event_id'];
		$is_active = $found['is_active'];

		// A finished season does not change, so its results are kept for a week.
		$minutes = Chess_Army_Knife_Settings::get_effective_lms_cache_minutes( 'lms_results', 30 );
		$ttl     = $is_active ? max( 60, (int) $minutes * MINUTE_IN_SECONDS ) : self::V2_HISTORY_TTL;
		$results = self::v2_get( 'event/' . $event_id . '/results', $ttl, $refresh );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$rows = array();
		foreach ( isset( $results['fixtures'] ) && is_array( $results['fixtures'] ) ? $results['fixtures'] : array() as $fixture ) {
			$row = self::normalise_fixture( $fixture );
			if ( $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Find an event by name in a season.
	 *
	 * @param string          $org        Numeric organisation id.
	 * @param string          $event_name Event name.
	 * @param string|int|null $season     'active', or a season id or name.
	 * @param bool            $refresh    Skip the cached copies.
	 * @return array|WP_Error { event_id, is_active, structure_keys }: structure_keys are the cache keys of the
	 *                        seasons and events lists read to find it.
	 */
	protected static function find_event( $org, $event_name, $season, $refresh = false ) {
		$seasons = self::get_seasons( $org, $refresh );
		if ( is_wp_error( $seasons ) ) {
			return $seasons;
		}

		$is_active  = '' === trim( (string) $season ) || 'active' === strtolower( trim( (string) $season ) );
		$season_ids = self::seasons_matching( $seasons, $season );
		$keys       = array( self::v2_key( 'org/' . rawurlencode( trim( (string) $org ) ) . '/seasons' ) );

		foreach ( $season_ids as $season_id ) {
			$path   = 'season/' . $season_id . '/events';
			$keys[] = self::v2_key( $path );
			$events = self::v2_get( $path, self::V2_STRUCTURE_TTL, $refresh );
			if ( is_wp_error( $events ) ) {
				return $events;
			}
			foreach ( isset( $events['events'] ) && is_array( $events['events'] ) ? $events['events'] : array() as $event ) {
				if ( isset( $event['id'], $event['name'] ) && self::same_name( $event['name'], $event_name ) ) {
					return array(
						'event_id'       => (int) $event['id'],
						'is_active'      => $is_active,
						'structure_keys' => $keys,
					);
				}
			}
		}

		return new WP_Error(
			'lms_event_not_found',
			sprintf(
				/* translators: %s: event / division name */
				__( 'No event called "%s" was found in the season. Check the organisation ID and that the name matches the LMS.', 'chess-army-knife' ),
				$event_name
			)
		);
	}

	/**
	 * The cache keys behind an event's fixtures, for the admin "refresh now" bar.
	 *
	 * @param string          $org        Numeric organisation id.
	 * @param string          $event_name Event name.
	 * @param string|int|null $season     'active', or a season id or name.
	 * @return string[] Raw cache keys; the lists of seasons and events, and the results if the event is found.
	 */
	public static function fixture_cache_keys( $org, $event_name, $season = 'active' ) {
		$found = self::find_event( $org, $event_name, $season );
		if ( is_wp_error( $found ) ) {
			return array( self::v2_key( 'org/' . rawurlencode( trim( (string) $org ) ) . '/seasons' ) );
		}

		return array_merge( $found['structure_keys'], array( self::v2_key( 'event/' . $found['event_id'] . '/results' ) ) );
	}

	/**
	 * Whether two event names are the same, ignoring case and extra spaces.
	 *
	 * @param string $a Name.
	 * @param string $b Name.
	 * @return bool
	 */
	protected static function same_name( $a, $b ) {
		$clean = function ( $name ) {
			return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $name ) ) );
		};

		return $clean( $a ) === $clean( $b );
	}

	/**
	 * One v2 fixture as a match row (see normalise_match_row()), with the players who
	 * played (side, ECF code, name). The v2 API gives no venue, so that is left empty.
	 *
	 * @param mixed $fixture Entry of the results' "fixtures" list.
	 * @return array|null Null if it is not a fixture. winner is 'home', 'away' or 'draw', and '' until it is played (scores are then ''
	 *                    too);
	 *                    home_colour in each of the games is 'W' or 'B'.
	 */
	public static function normalise_fixture( $fixture ) {
		if ( ! is_array( $fixture ) ) {
			return null;
		}

		// The players who played each board, by the ECF rating code the LMS holds for them.
		$players = array();
		foreach ( isset( $fixture['games'] ) && is_array( $fixture['games'] ) ? $fixture['games'] : array() as $game ) {
			foreach ( array(
				'home' => 'home_player',
				'away' => 'away_player',
			) as $side => $key ) {
				$player = isset( $game[ $key ] ) && is_array( $game[ $key ] ) ? $game[ $key ] : null;
				$code   = $player ? trim( (string) self::pick( $player, array( 'rating_code' ), '' ) ) : '';
				// A negative id is a bye or default, not a person.
				if ( '' === $code || (int) self::pick( $player, array( 'lms_id' ), 0 ) < 0 ) {
					continue;
				}
				$players[] = array(
					'side' => $side,
					'code' => $code,
					'name' => (string) self::pick( $player, array( 'name' ), '' ),
				);
			}
		}

		// An unplayed fixture arrives with scores of 0 and a winner of "unknown", which is not a result.
		$winner = (string) self::pick( $fixture, array( 'winner' ), '' );
		$winner = in_array( $winner, array( 'home', 'away', 'draw' ), true ) ? $winner : '';

		return array(
			'fixture_id'  => (int) self::pick( $fixture, array( 'fixture_id' ), 0 ),
			'players'     => $players,
			'games'       => self::normalise_games( isset( $fixture['games'] ) ? $fixture['games'] : array() ),
			'venue'       => '',
			'date'        => (string) self::pick( $fixture, array( 'date' ), '' ),
			'time'        => (string) self::pick( $fixture, array( 'time' ), '' ),
			'home'        => (string) self::pick( $fixture, array( 'home_team' ), '' ),
			'away'        => (string) self::pick( $fixture, array( 'away_team' ), '' ),
			'home_score'  => '' === $winner ? '' : self::format_score( self::pick( $fixture, array( 'home_score' ), null ) ),
			'away_score'  => '' === $winner ? '' : self::format_score( self::pick( $fixture, array( 'away_score' ), null ) ),
			'winner'      => $winner,
			'result_text' => '',
		);
	}

	/**
	 * A score as text: 3, 2.5, or '' when there is none yet.
	 *
	 * @param mixed $score Number from the LMS (it sends whole scores as integers and half scores as decimals).
	 * @return string
	 */
	public static function format_score( $score ) {
		if ( ! is_numeric( $score ) ) {
			return '';
		}

		return rtrim( rtrim( number_format( (float) $score, 1, '.', '' ), '0' ), '.' );
	}

	/**
	 * The boards of a fixture.
	 *
	 * @param mixed $games The fixture's "games" list.
	 * @return array[] Each { board, home_colour, result, home, away }; home and away are
	 *                 { name, code, rating } or null where there was no player (a default).
	 *                 result is 'home_win', 'away_win', 'draw' or '' (not played).
	 */
	public static function normalise_games( $games ) {
		$boards = array();

		foreach ( is_array( $games ) ? $games : array() as $game ) {
			if ( ! is_array( $game ) ) {
				continue;
			}
			$result   = (string) self::pick( $game, array( 'result' ), '' );
			$boards[] = array(
				'board'       => (int) self::pick( $game, array( 'board' ), 0 ),
				'home_colour' => (string) self::pick( $game, array( 'home_colour' ), '' ),
				'result'      => in_array( $result, array( 'home_win', 'away_win', 'draw' ), true ) ? $result : '',
				'home'        => self::normalise_board_player( isset( $game['home_player'] ) ? $game['home_player'] : null ),
				'away'        => self::normalise_board_player( isset( $game['away_player'] ) ? $game['away_player'] : null ),
			);
		}

		return $boards;
	}

	/**
	 * One player on a board.
	 *
	 * @param mixed $player The "home_player" or "away_player" entry.
	 * @return array|null { name, code, rating }; rating is an int or null (unrated). Null for a default or no player.
	 */
	protected static function normalise_board_player( $player ) {
		// A negative id is a default or bye, not a person.
		if ( ! is_array( $player ) || (int) self::pick( $player, array( 'lms_id' ), 0 ) < 0 ) {
			return null;
		}

		$rating = isset( $player['rating'] ) && is_numeric( $player['rating'] ) ? (int) $player['rating'] : null;

		return array(
			'name'   => (string) self::pick( $player, array( 'name' ), '' ),
			'code'   => trim( (string) self::pick( $player, array( 'rating_code' ), '' ) ),
			'rating' => $rating,
		);
	}

	/**
	 * The cache key of a v2 path.
	 *
	 * @param string $path Path under the v2 base.
	 * @return string
	 */
	protected static function v2_key( $path ) {
		return 'lms2_v2_' . $path;
	}

	/**
	 * GET a v2 path, cached.
	 *
	 * @param string $path    Path under the v2 base, e.g. "season/3/events".
	 * @param int    $ttl     Seconds to cache for.
	 * @param bool   $refresh Forget any cached copy first.
	 * @return array|WP_Error Decoded JSON.
	 */
	protected static function v2_get( $path, $ttl, $refresh = false ) {
		$key = self::v2_key( $path );

		if ( $refresh ) {
			Chess_Army_Knife_Cache::forget( $key );
		}

		return Chess_Army_Knife_Cache::remember(
			$key,
			$ttl,
			function () use ( $path ) {
				return self::v2_request( $path );
			}
		);
	}

	/**
	 * Make one v2 request.
	 *
	 * @param string $path Path under the v2 base.
	 * @return array|WP_Error Decoded JSON.
	 */
	protected static function v2_request( $path ) {
		$url      = self::v2_base() . '/' . $path;
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'chess-army-knife WordPress Plugin/' . Chess_Army_Knife_VERSION . '; ' . home_url( '/' ),
				'headers'    => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . self::api_key(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'lms_connection_error',
				sprintf(
					/* translators: 1: URL tried, 2: underlying error message */
					__( 'Could not reach the LMS service at %1$s (%2$s).', 'chess-army-knife' ),
					$url,
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = array( 'status' => $code );

		if ( 401 === $code ) {
			return new WP_Error( 'lms_unauthorised', __( 'The LMS did not accept the API key. Check it under Settings.', 'chess-army-knife' ), $data );
		}
		if ( 403 === $code ) {
			return new WP_Error( 'lms_forbidden', __( 'The LMS API key does not have permission to see that data.', 'chess-army-knife' ), $data );
		}
		if ( 404 === $code ) {
			return new WP_Error( 'lms_not_found', __( 'The LMS could not find that organisation, season or event.', 'chess-army-knife' ), $data );
		}
		if ( $code >= 400 ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'lms_http_error', sprintf( __( 'The LMS service returned an error (HTTP %d).', 'chess-army-knife' ), $code ), $data );
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'lms_bad_response', __( 'The LMS service returned a response that could not be parsed as JSON.', 'chess-army-knife' ), $data );
		}

		return $json;
	}

	/*
	---------------------------------------------------------------
	 * Generic helpers for pulling values out of loosely-shaped LMS
	 * JSON. LMS rows are typically associative arrays, but the exact
	 * key names can vary by field/version, so each helper tries a
	 * short list of plausible candidates and returns the first hit.
	 * ------------------------------------------------------------- */

	/**
	 * Try several possible array keys and return the first that exists
	 * and is non-null/non-empty-string.
	 *
	 * @param array $row       Associative array.
	 * @param array $candidates Ordered list of key names to try.
	 * @param mixed $fallback  Value to return if none match.
	 * @return mixed
	 */
	public static function pick( $row, $candidates, $fallback = '' ) {
		if ( ! is_array( $row ) ) {
			return $fallback;
		}
		foreach ( $candidates as $key ) {
			if ( isset( $row[ $key ] ) && '' !== $row[ $key ] ) {
				return $row[ $key ];
			}
		}
		return $fallback;
	}

	/**
	 * Find the first array of "rows" inside an LMS JSON payload,
	 * regardless of what top-level key it's nested under (e.g. "table",
	 * "rows", "data", "entries", or - if the payload itself is already
	 * a plain list - the payload itself).
	 *
	 * @param array $payload Decoded JSON.
	 * @param array $keys    Candidate wrapper keys to look for first.
	 * @return array List of row arrays (possibly empty).
	 */
	public static function find_rows( $payload, $keys = array() ) {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$default_keys = array_merge( $keys, array( 'table', 'rows', 'data', 'entries', 'standings', 'matches', 'events', 'fixtures', 'results', 'items', 'list' ) );

		foreach ( $default_keys as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) && self::is_list( $payload[ $key ] ) ) {
				return $payload[ $key ];
			}
		}

		// If the payload itself looks like a plain numeric-indexed list, use it directly.
		if ( self::is_list( $payload ) ) {
			return $payload;
		}

		// Last resort: look one level deeper for the first list of arrays we can find.
		foreach ( $payload as $value ) {
			if ( is_array( $value ) && self::is_list( $value ) && ! empty( $value ) && is_array( reset( $value ) ) ) {
				return $value;
			}
		}

		return array();
	}

	/**
	 * Whether an array is a plain sequential ("list") array as opposed
	 * to an associative one.
	 *
	 * @param array $arr Array to test.
	 * @return bool
	 */
	public static function is_list( $arr ) {
		if ( ! is_array( $arr ) ) {
			return false;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Normalise one league-table row into a flat, predictable shape.
	 *
	 * LMS table rows may either be flat (position/team/p/w/d/l/pts at
	 * the top level) or nested under an "entry" object (mirroring the
	 * underlying league-software data model, where a team's stats sit
	 * inside entry.team / entry.p / entry.w etc). This handles both.
	 *
	 * @param array $row Raw row from the table.json payload.
	 * @return array {
	 *     @type string $position
	 *     @type string $team
	 *     @type string $played
	 *     @type string $won
	 *     @type string $drawn
	 *     @type string $lost
	 *     @type string $points
	 * }
	 */
	public static function normalise_table_row( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		// Some payloads nest the actual stats one level down under "entry".
		$entry = ( isset( $row['entry'] ) && is_array( $row['entry'] ) ) ? $row['entry'] : $row;

		$team_field = self::pick( $entry, array( 'team', 'team_name', 'name', 'club', 'entry_name' ), null );
		if ( is_array( $team_field ) ) {
			$team = self::pick( $team_field, array( 'name', 'team_name', 'title' ), '' );
		} else {
			$team = (string) $team_field;
		}

		return array(
			'position' => (string) self::pick( $row, array( 'position', 'pos', 'rank', 'place' ), self::pick( $entry, array( 'position', 'pos', 'rank' ), '' ) ),
			'team'     => $team,
			'played'   => (string) self::pick( $entry, array( 'p', 'played', 'games_played', 'pld' ), '' ),
			'won'      => (string) self::pick( $entry, array( 'w', 'won', 'wins' ), '' ),
			'drawn'    => (string) self::pick( $entry, array( 'd', 'drawn', 'draws' ), '' ),
			'lost'     => (string) self::pick( $entry, array( 'l', 'lost', 'losses' ), '' ),
			'points'   => (string) self::pick( $entry, array( 'pts', 'points', 'score', 'match_points' ), '' ),
		);
	}

	/**
	 * Normalise one match/fixture row into a flat, predictable shape.
	 *
	 * @param array $row Raw row from the match.json or event.json payload.
	 * @return array {
	 *     @type string $date
	 *     @type string $home
	 *     @type string $away
	 *     @type string $home_score
	 *     @type string $away_score
	 *     @type string $result_text Fallback single string result, if scores aren't split out.
	 * }
	 */
	public static function normalise_match_row( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$home_field = self::pick( $row, array( 'left', 'home', 'team1', 'home_team' ), null );
		$away_field = self::pick( $row, array( 'right', 'away', 'team2', 'away_team' ), null );

		$home = is_array( $home_field ) ? self::pick( $home_field, array( 'name', 'team_name' ), '' ) : (string) $home_field;
		$away = is_array( $away_field ) ? self::pick( $away_field, array( 'name', 'team_name' ), '' ) : (string) $away_field;

		$date = self::pick( $row, array( 'date', 'match_date', 'event_date', 'played_date', 'datetime' ), '' );

		$venue_field = self::pick( $row, array( 'venue', 'location', 'ground', 'venue_name', 'address' ), '' );
		$venue       = is_array( $venue_field ) ? self::pick( $venue_field, array( 'name', 'title', 'address' ), '' ) : $venue_field;

		return array(
			'venue'       => (string) $venue,
			'date'        => (string) $date,
			'time'        => (string) self::pick( $row, array( 'time', 'start_time', 'kick_off', 'kickoff', 'match_time' ), '' ),
			'home'        => (string) $home,
			'away'        => (string) $away,
			'home_score'  => (string) self::pick( $row, array( 'left_score', 'home_score', 'score1', 'left_points' ), '' ),
			'away_score'  => (string) self::pick( $row, array( 'right_score', 'away_score', 'score2', 'right_points' ), '' ),
			'result_text' => (string) self::pick( $row, array( 'result', 'score', 'result_text' ), '' ),
		);
	}
}
