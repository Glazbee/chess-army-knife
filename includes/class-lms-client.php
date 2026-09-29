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

class LMS_Client {

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
					Chess_Army_Knife_Settings::get_options()['lms_base_url'] ?: self::BASE,
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
