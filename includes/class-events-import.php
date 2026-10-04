<?php
/**
 * Imports club events from the LMS: every fixture of the club's registered
 * teams (their league entries) becomes an event, so league matches show up
 * in the event blocks alongside club nights.
 *
 * Re-running the import is safe. Each fixture is matched to its event by a
 * key made from the league, the two teams and the date, so nothing is
 * duplicated. An imported event is refreshed while it is untouched, but is
 * left alone once someone has edited it by hand, and one that was moved to
 * the trash stays deleted.
 *
 * Fixtures are read from version 2 of the LMS API, which needs an API key
 * (Settings). A fixture without a start time starts at the usual kick-off
 * time from Settings.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Events_Import {

	const META_LMS_KEY = '_chess_army_event_lms_key';
	const META_EDITED  = '_chess_army_event_edited';
	const TAG          = 'League match';
	const ACTION       = 'chess_army_knife_import_events';
	const ACTION_OLD   = 'chess_army_knife_import_old_seasons';
	const HOOK         = 'Chess_Army_Knife_import_events'; // The daily import.
	const LAST_OPTION  = 'Chess_Army_Knife_import_last'; // What the most recent import did.

	/**
	 * Hook up the admin page and its form handler.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_' . self::ACTION_OLD, array( __CLASS__, 'handle_import_old_seasons' ) );
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedule the daily import if it is not already.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Stop the daily import (on deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The daily import. Does nothing until there is an API key and a team to import for.
	 */
	public static function run_scheduled() {
		if ( '' === Chess_Army_Knife_LMS_Client::api_key() || ! Chess_Army_Knife_Settings::get_club_teams() ) {
			return;
		}

		self::record( self::import(), 'scheduled' );
	}

	/**
	 * Run an import and remember it, as the Import now button does.
	 *
	 * @param string $source 'manual' or 'scheduled'.
	 * @return array See import().
	 */
	public static function import_and_record( $source = 'manual' ) {
		$summary = self::import();
		self::record( $summary, $source );

		return $summary;
	}

	/**
	 * Remember what an import did, for the Overview and the import screen.
	 *
	 * @param array  $summary Result of import().
	 * @param string $source  'scheduled' or 'manual'.
	 */
	protected static function record( array $summary, $source ) {
		update_option(
			self::LAST_OPTION,
			array(
				'time'    => time(),
				'source'  => $source,
				'summary' => $summary,
			),
			false
		);
	}

	/**
	 * What the most recent import did.
	 *
	 * @return array|null { time, source, summary }, or null if none has run.
	 */
	public static function last_run() {
		$last = get_option( self::LAST_OPTION, null );

		return is_array( $last ) && isset( $last['time'], $last['summary'] ) && is_array( $last['summary'] ) ? $last : null;
	}

	/**
	 * A line saying when the last import ran and how it went.
	 *
	 * @param array|null $last Result of last_run().
	 * @param int        $now  Unix time.
	 * @return string Plain text, or '' if none has run.
	 */
	public static function last_run_text( $last, $now ) {
		if ( ! is_array( $last ) ) {
			return '';
		}

		$summary = $last['summary'];
		$errors  = isset( $summary['errors'] ) ? count( (array) $summary['errors'] ) : 0;
		$when    = human_time_diff( (int) $last['time'], (int) $now );
		$how     = isset( $last['source'] ) && 'scheduled' === $last['source'] ? __( 'daily', 'chess-army-knife' ) : __( 'manual', 'chess-army-knife' );

		return sprintf(
			/* translators: 1: how long ago, 2: daily or manual, 3: new events, 4: refreshed events, 5: leagues that failed */
			__( 'Last import: %1$s ago (%2$s): %3$d created, %4$d updated, %5$d leagues failed.', 'chess-army-knife' ),
			$when,
			$how,
			(int) ( isset( $summary['created'] ) ? $summary['created'] : 0 ),
			(int) ( isset( $summary['updated'] ) ? $summary['updated'] : 0 ),
			$errors
		);
	}

	/**
	 * The date of a fixture as "Y-m-d", from the formats the LMS may use.
	 *
	 * @param string $raw Raw date, e.g. 2026-10-05, 2026-10-05T19:30:00 or 05/10/2026.
	 * @return string '' if the date can't be read.
	 */
	public static function parse_date( $raw ) {
		$raw = trim( (string) $raw );

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?![\d])/', $raw, $m ) ) {
			list( , $year, $month, $day ) = $m;
		} elseif ( preg_match( '#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})(?![\d])#', $raw, $m ) ) {
			list( , $day, $month, $year ) = $m; // UK order.
		} else {
			return '';
		}

		return checkdate( (int) $month, (int) $day, (int) $year ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : '';
	}

	/**
	 * A start time as "HH:MM", from a time on its own or one following a date.
	 *
	 * @param string $raw Raw value, e.g. 19:30, 19:30:00 or 2026-10-05 19:30.
	 * @return string '' if there is no valid time.
	 */
	public static function parse_time( $raw ) {
		if ( ! preg_match( '/(?<!\d)([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?(?!\d)/', (string) $raw, $m ) ) {
			return '';
		}

		return sprintf( '%02d:%s', (int) $m[1], $m[2] );
	}

	/**
	 * Identity of a fixture, used to recognise it on a later import.
	 *
	 * @param string $org   LMS organisation id.
	 * @param string $event LMS event / division name.
	 * @param string $home  Home team.
	 * @param string $away  Away team.
	 * @param string $date  Date, "Y-m-d".
	 * @return string
	 */
	public static function match_key( $org, $event, $home, $away, $date ) {
		return md5( strtolower( implode( '|', array( trim( $org ), trim( $event ), trim( $home ), trim( $away ), $date ) ) ) );
	}

	/**
	 * Fixtures involving a team: exact name matches, or failing that
	 * substring matches (as the Team Fixtures Carousel does).
	 *
	 * @param array[] $matches Normalised match rows.
	 * @param string  $team    Team name.
	 * @return array[]
	 */
	protected static function team_matches( array $matches, $team ) {
		$exact = array_filter(
			$matches,
			function ( $match ) use ( $team ) {
				return 0 === strcasecmp( $match['home'], $team ) || 0 === strcasecmp( $match['away'], $team );
			}
		);
		if ( $exact ) {
			return array_values( $exact );
		}

		return array_values(
			array_filter(
				$matches,
				function ( $match ) use ( $team ) {
					return '' !== $team && ( false !== stripos( $match['home'], $team ) || false !== stripos( $match['away'], $team ) );
				}
			)
		);
	}

	/**
	 * Which side of a fixture a team is on.
	 *
	 * @param array  $match Normalised match row.
	 * @param string $team  Team name, as matched by team_matches().
	 * @return string 'home' or 'away'.
	 */
	public static function side_of( array $match, $team ) {
		if ( 0 === strcasecmp( $match['home'], $team ) ) {
			return 'home';
		}
		if ( 0 === strcasecmp( $match['away'], $team ) ) {
			return 'away';
		}
		return '' !== $team && false !== stripos( $match['home'], $team ) ? 'home' : 'away';
	}

	/**
	 * Work out the events to create from the club's teams and their fixtures.
	 *
	 * @param array[] $teams              Club teams, each { org, event, team }.
	 * @param array   $matches_by_league  Normalised match rows keyed by "org|event" (lower case).
	 * @param string  $today              Today, "Y-m-d" (earlier fixtures are ignored).
	 * @param string  $default_time       Start time for a fixture with none, "HH:MM".
	 * @return array {
	 *     @type array[] $candidates Each { key, title, start, location, home_team, away_team, league, club_teams, match }, where match is the fixture's row and club_teams maps a league entry key to 'home' or 'away'.
	 *     @type int     $skipped    Fixtures ignored because their date couldn't be read.
	 * }
	 */
	public static function plan( array $teams, array $matches_by_league, $today, $default_time ) {
		$candidates = array();
		$skipped    = 0;

		foreach ( $teams as $team ) {
			$group = strtolower( Chess_Army_Knife_Events::league_ref( $team['org'], $team['event'] ) );

			foreach ( self::team_matches( isset( $matches_by_league[ $group ] ) ? $matches_by_league[ $group ] : array(), $team['team'] ) as $match ) {
				$date = self::parse_date( $match['date'] );
				if ( '' === $date ) {
					++$skipped;
					continue;
				}
				if ( $date < $today ) {
					continue;
				}

				$time = self::parse_time( $match['time'] );
				if ( '' === $time ) {
					$time = self::parse_time( $match['date'] );
				}
				$start = Chess_Army_Knife_Events::combine_datetime( $date, '' !== $time ? $time : $default_time );
				if ( '' === $start ) {
					++$skipped;
					continue;
				}

				// Two of the club's own teams meeting is one fixture, however many teams find it.
				$key = self::match_key( $team['org'], $team['event'], $match['home'], $match['away'], $date );

				$club_teams = isset( $candidates[ $key ] ) ? $candidates[ $key ]['club_teams'] : array();
				$club_teams[ Chess_Army_Knife_Teams::season_key( $team ) ] = self::side_of( $match, $team['team'] );

				$candidates[ $key ] = array(
					'key'        => $key,
					'title'      => $match['home'] . ' v ' . $match['away'],
					'start'      => $start,
					'location'   => $match['venue'],
					'home_team'  => $match['home'],
					'away_team'  => $match['away'],
					'league'     => Chess_Army_Knife_Events::league_ref( $team['org'], $team['event'] ),
					'club_teams' => $club_teams,
					'match'      => $match,
				);
			}
		}

		return array(
			'candidates' => array_values( $candidates ),
			'skipped'    => $skipped,
		);
	}

	/**
	 * The tags of a fixture: "League match", then each of the club's teams in it that has a tag of its own.
	 *
	 * @param string[] $club_teams League entry key => 'home' or 'away'.
	 * @return string[] Tag names, without repeats.
	 */
	public static function tags_for( array $club_teams ) {
		$tags = array( self::TAG );

		foreach ( array_keys( $club_teams ) as $season_key ) {
			$team = Chess_Army_Knife_Teams::team_for_season( $season_key );
			if ( $team && '' !== $team['tag'] ) {
				$tags[] = $team['tag'];
			}
		}

		return array_values( array_unique( $tags ) );
	}

	/**
	 * The players who played for each of the club's teams, from the fixtures' board results.
	 *
	 * @param array[] $teams             Club teams, each { org, event, team }.
	 * @param array   $matches_by_league Normalised match rows keyed by "org|event" (lower case).
	 * @return array[] League entry key => list of { code, name }, without repeats.
	 */
	public static function players_by_team( array $teams, array $matches_by_league ) {
		$by_team = array();

		foreach ( $teams as $team ) {
			$group = strtolower( Chess_Army_Knife_Events::league_ref( $team['org'], $team['event'] ) );
			$key   = Chess_Army_Knife_Teams::season_key( $team );

			foreach ( self::team_matches( isset( $matches_by_league[ $group ] ) ? $matches_by_league[ $group ] : array(), $team['team'] ) as $match ) {
				$side = self::side_of( $match, $team['team'] );
				$date = self::parse_date( isset( $match['date'] ) ? $match['date'] : '' );
				foreach ( isset( $match['players'] ) ? $match['players'] : array() as $player ) {
					if ( $player['side'] === $side ) {
						$code = strtoupper( $player['code'] );
						// The latest game this person played for the team.
						$player['last_played']    = max( $date, isset( $by_team[ $key ][ $code ]['last_played'] ) ? $by_team[ $key ][ $code ]['last_played'] : '' );
						$by_team[ $key ][ $code ] = $player;
					}
				}
			}
		}

		return array_map( 'array_values', $by_team );
	}

	/**
	 * Put the players who played for a team in its squad, when they are on the club's member
	 * records (matched by ECF code). Nobody is ever taken out of a squad here: that is for the
	 * admin or captain.
	 *
	 * @param array[] $players_by_team From players_by_team().
	 * @return array { added, unmatched } How many people were added to a squad, and how many players (by ECF code) are on no member record.
	 */
	protected static function sync_squads( array $players_by_team ) {
		$added     = 0;
		$unmatched = array();

		foreach ( $players_by_team as $season_key => $players ) {
			$team = Chess_Army_Knife_Teams::team_for_season( $season_key );
			if ( ! $team ) {
				continue;
			}

			$have   = Chess_Army_Knife_Teams::squad( $team['id'] );
			$new    = array();
			$latest = array();
			foreach ( $players as $player ) {
				$member = Chess_Army_Knife_Membership_Store::find_by_ecf_code( $player['code'] );
				if ( ! $member || Chess_Army_Knife_Membership_Store::STATUS_NONMEMBER === $member['status'] ) {
					$unmatched[ strtoupper( $player['code'] ) ] = true;
					continue;
				}
				if ( ! in_array( $member['id'], $have, true ) ) {
					$new[] = $member['id'];
				}
				if ( ! empty( $player['last_played'] ) ) {
					$latest[ $member['id'] ] = $player['last_played'];
				}
			}

			if ( $new ) {
				Chess_Army_Knife_Teams::add_to_squad( $team['id'], $new );
				$added += count( array_unique( $new ) );
			}
			// Everyone who played, new to the squad or not, has their latest game noted, so a squad can be tidied later.
			Chess_Army_Knife_Teams::record_appearances( $team['id'], $latest );
		}

		return array(
			'added'     => $added,
			'unmatched' => count( $unmatched ),
		);
	}

	/**
	 * A venue as event meta: only what is known, so an unknown venue changes nothing.
	 *
	 * @param string[] $venue { location, map_url, what3words }.
	 * @return string[] Meta key => value.
	 */
	protected static function venue_meta( array $venue ) {
		$meta = array();
		foreach ( array(
			Chess_Army_Knife_Events::META_LOCATION => 'location',
			Chess_Army_Knife_Events::META_MAP      => 'map_url',
			Chess_Army_Knife_Events::META_W3W      => 'what3words',
		) as $key => $field ) {
			if ( '' !== $venue[ $field ] ) {
				$meta[ $key ] = $venue[ $field ];
			}
		}

		return $meta;
	}

	/**
	 * Where a fixture is played, as far as the plugin knows: the club venue for
	 * a home game of one of its teams, or the venue of the club that hosts it for an away game.
	 *
	 * @param array $candidate A candidate from plan().
	 * @return string[] { location, map_url, what3words }; all '' if the venue is not known.
	 */
	public static function venue_for( array $candidate ) {
		$none    = array(
			'location'   => '',
			'map_url'    => '',
			'what3words' => '',
		);
		$default = Chess_Army_Knife_Events::default_venue();

		foreach ( $candidate['club_teams'] as $side ) {
			if ( 'home' !== $side ) {
				continue;
			}

			// The club's own team is at home: at the club venue.
			return $default;
		}

		$away = Chess_Army_Knife_Clubs::venue_of_team( $candidate['home_team'] );

		return $away ? $away : $none;
	}

	/**
	 * Keep a fixture's result (score and boards) on its event.
	 *
	 * @param int   $event_id  Event id.
	 * @param array $candidate A candidate from plan().
	 */
	protected static function store_result( $event_id, array $candidate ) {
		Chess_Army_Knife_Event_Results::store( $event_id, Chess_Army_Knife_Event_Results::build( $candidate['match'], array_values( $candidate['club_teams'] ) ) );
	}

	/**
	 * Fetch the fixtures of every league the club's teams are in.
	 *
	 * @param array[] $teams   Club teams.
	 * @param array   $errors      Filled with a message for each league that could not be loaded.
	 * @param bool    $key_problem Set when the LMS refuses the API key.
	 * @param string|int $season   'active' for the running season, or a season id for an earlier one.
	 * @return array Normalised match rows keyed by "org|event" (lower case).
	 */
	protected static function fetch_matches( array $teams, array &$errors, &$key_problem, $season = 'active' ) {
		$by_league = array();

		foreach ( $teams as $team ) {
			$group = strtolower( Chess_Army_Knife_Events::league_ref( $team['org'], $team['event'] ) );
			if ( isset( $by_league[ $group ] ) ) {
				continue;
			}

			// An import is a manual refresh, so don't use the cached copies.
			$rows = Chess_Army_Knife_LMS_Client::get_fixtures( $team['org'], $team['event'], true, $season );

			$by_league[ $group ] = array();
			if ( is_wp_error( $rows ) ) {
				/* translators: 1: league / division name, 2: error message */
				$errors[] = sprintf( __( '%1$s: %2$s', 'chess-army-knife' ), $team['event'], $rows->get_error_message() );
				if ( in_array( $rows->get_error_code(), array( 'lms_unauthorised', 'lms_forbidden', 'lms_no_api_key' ), true ) ) {
					$key_problem = true;
				}
				continue;
			}

			foreach ( $rows as $match ) {
				if ( '' !== $match['home'] || '' !== $match['away'] ) {
					$by_league[ $group ][] = $match;
				}
			}
		}

		return $by_league;
	}

	/**
	 * Link an event to the club teams playing in it, and record which side each is on.
	 *
	 * @param int      $post_id    Event id.
	 * @param string[] $club_teams league entry key => 'home' or 'away'.
	 * @return bool Whether anything changed.
	 */
	protected static function sync_teams( $post_id, array $club_teams ) {
		$sides = array();
		foreach ( $club_teams as $season_key => $side ) {
			$team = Chess_Army_Knife_Teams::team_for_season( $season_key );
			if ( $team ) {
				$sides[ $team['id'] ] = $side;
			}
		}
		ksort( $sides );

		$current = array_map( 'intval', get_post_meta( $post_id, Chess_Army_Knife_Events::META_TEAM, false ) );
		sort( $current );
		$stored_sides = (array) get_post_meta( $post_id, Chess_Army_Knife_Events::META_SIDES, true );
		if ( array_keys( $sides ) === $current && $stored_sides === $sides ) {
			return false;
		}

		delete_post_meta( $post_id, Chess_Army_Knife_Events::META_TEAM );
		foreach ( array_keys( $sides ) as $team_id ) {
			add_post_meta( $post_id, Chess_Army_Knife_Events::META_TEAM, $team_id );
		}
		update_post_meta( $post_id, Chess_Army_Knife_Events::META_SIDES, $sides );
		return true;
	}

	/**
	 * The event already made for a fixture, in any status (a trashed one included).
	 *
	 * @param string $key Fixture key.
	 * @return WP_Post|null
	 */
	protected static function find_event( $key ) {
		$posts = get_posts(
			array(
				'post_type'      => Chess_Army_Knife_Events::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => self::META_LMS_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Finds the one imported event with this LMS key.
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Finds the one imported event with this LMS key.
			)
		);

		return $posts ? $posts[0] : null;
	}

	/**
	 * Import the club's LMS fixtures as events.
	 *
	 * The running season brings in the upcoming fixtures and updates the squads. An earlier season
	 * brings in every fixture it had, as past events, and leaves the squads and the Clubs list alone.
	 *
	 * @param string|int $season 'active' for the running season, or the id of an earlier one.
	 * @return array {
	 *     @type int      $created   New events.
	 *     @type int      $updated   Untouched imported events that were refreshed.
	 *     @type int      $unchanged Events that already matched.
	 *     @type int      $kept      Events left alone (edited by hand, or trashed).
	 *     @type int      $squad_added       People added to a squad because they played for the team.
	 *     @type int      $players_unmatched Players (by ECF code) who are on no member record.
	 *     @type int      $unsorted  Team names seen that are not in a club yet, so their home fixtures have no venue.
	 *     @type int      $skipped   Fixtures with a date that couldn't be read.
	 *     @type string[] $errors    Leagues that couldn't be loaded.
	 *     @type bool     $key_problem Whether the LMS refused the API key (or there is none).
	 * }
	 */
	public static function import( $season = 'active' ) {
		$is_active = 'active' === $season;
		$summary   = array(
			'created'           => 0,
			'updated'           => 0,
			'unchanged'         => 0,
			'kept'              => 0,
			'unsorted'          => 0,
			'squad_added'       => 0,
			'players_unmatched' => 0,
			'skipped'           => 0,
			'errors'            => array(),
			'key_problem'       => false,
		);

		$teams = Chess_Army_Knife_Settings::get_club_teams();
		if ( empty( $teams ) ) {
			return $summary;
		}

		$options      = Chess_Army_Knife_Settings::get_options();
		$default_time = self::parse_time( $options['match_time'] );
		if ( '' === $default_time ) {
			$default_time = '19:30';
		}
		$matches = self::fetch_matches( $teams, $summary['errors'], $summary['key_problem'], $season );
		// An empty "today" keeps every fixture, so an earlier season comes in whole.
		$plan = self::plan( $teams, $matches, $is_active ? current_time( 'Y-m-d' ) : '', $default_time );

		$summary['skipped'] = $plan['skipped'];

		if ( $is_active ) {
			// Anyone who played for one of the club's teams is in that team's squad.
			$squads                       = self::sync_squads( self::players_by_team( $teams, $matches ) );
			$summary['squad_added']       = $squads['added'];
			$summary['players_unmatched'] = $squads['unmatched'];

			// Remember every team name seen, so the Sort Clubs screen can ask where they play.
			$names = array();
			foreach ( $matches as $rows ) {
				foreach ( $rows as $row ) {
					$names[] = $row['home'];
					$names[] = $row['away'];
				}
			}
			Chess_Army_Knife_Clubs::note_seen( $names );
			$summary['unsorted'] = count( Chess_Army_Knife_Clubs::unsorted() );
		}

		foreach ( $plan['candidates'] as $candidate ) {
			$existing = self::find_event( $candidate['key'] );
			$venue    = self::venue_for( $candidate );

			if ( ! $existing ) {
				$post_id = wp_insert_post(
					array(
						'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => $candidate['title'],
						'meta_input'  => array(
							self::META_LMS_KEY => $candidate['key'],
							Chess_Army_Knife_Events::META_START => $candidate['start'],
							Chess_Army_Knife_Events::META_LEAGUES => array( $candidate['league'] ),
						) + self::venue_meta( $venue ),
					),
					true
				);
				if ( ! is_wp_error( $post_id ) ) {
					wp_set_object_terms( $post_id, self::tags_for( $candidate['club_teams'] ), Chess_Army_Knife_Events::TAXONOMY );
					Chess_Army_Knife_Events::apply_type( $post_id, 'league_match' );
					self::sync_teams( $post_id, $candidate['club_teams'] );
					self::store_result( $post_id, $candidate );
					++$summary['created'];
				}
				continue;
			}

			if ( 'trash' === $existing->post_status ) {
				++$summary['kept'];
				continue;
			}

			// The result is the LMS's, not something an editor changes, so it is kept up to date even on an edited event.
			self::store_result( $existing->ID, $candidate );

			if ( get_post_meta( $existing->ID, self::META_EDITED, true ) ) {
				++$summary['kept'];
				continue;
			}

			$changed = self::sync_teams( $existing->ID, $candidate['club_teams'] );

			// Add any tag the fixture is missing; tags added by hand are never taken away.
			$has = array_map( 'strtolower', wp_list_pluck( (array) wp_get_object_terms( $existing->ID, Chess_Army_Knife_Events::TAXONOMY ), 'name' ) );
			$new = array_filter(
				self::tags_for( $candidate['club_teams'] ),
				function ( $tag ) use ( $has ) {
					return ! in_array( strtolower( $tag ), $has, true );
				}
			);
			if ( $new ) {
				wp_set_object_terms( $existing->ID, array_values( $new ), Chess_Army_Knife_Events::TAXONOMY, true );
				$changed = true;
			}
			if ( get_post_meta( $existing->ID, Chess_Army_Knife_Events::META_START, true ) !== $candidate['start'] ) {
				update_post_meta( $existing->ID, Chess_Army_Knife_Events::META_START, $candidate['start'] );
				$changed = true;
			}
			foreach ( self::venue_meta( $venue ) as $meta_key => $value ) {
				if ( get_post_meta( $existing->ID, $meta_key, true ) !== $value ) {
					update_post_meta( $existing->ID, $meta_key, $value );
					$changed = true;
				}
			}
			if ( $existing->post_title !== $candidate['title'] ) {
				wp_update_post(
					array(
						'ID'         => $existing->ID,
						'post_title' => $candidate['title'],
					)
				);
				$changed = true;
			}

			if ( $changed ) {
				++$summary['updated'];
			} else {
				++$summary['unchanged'];
			}
		}

		return $summary;
	}

	/**
	 * Handle the "Import now" button.
	 */
	public static function handle_import() {
		if ( ! Chess_Army_Knife_Teams::user_can_manage() || ! check_admin_referer( self::ACTION ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ) );
		}

		set_transient( self::result_key(), self::import_and_record( 'manual' ), MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( array( 'page' => Chess_Army_Knife_League_Games::PAGE ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * The earlier seasons the LMS has for the organisations the club's teams play in.
	 *
	 * @param array[] $teams Club teams.
	 * @return string[] Season id => name, in the order the LMS lists them. Empty if the LMS can't be read.
	 */
	public static function old_seasons( array $teams ) {
		$old = array();

		foreach ( array_unique( array_column( $teams, 'org' ) ) as $org ) {
			$seasons = Chess_Army_Knife_LMS_Client::get_seasons( $org );
			if ( is_wp_error( $seasons ) ) {
				continue;
			}
			foreach ( $seasons as $season ) {
				if ( 'old' === $season['status'] && ! isset( $old[ $season['id'] ] ) ) {
					$old[ $season['id'] ] = '' !== $season['name'] ? $season['name'] : (string) $season['id'];
				}
			}
		}

		return $old;
	}

	/**
	 * Import earlier seasons, one after the other, and add up what each did.
	 *
	 * @param int[]    $season_ids Seasons asked for. Anything that is not an earlier season of the club's leagues is ignored.
	 * @param string[] $available  Season id => name, from old_seasons().
	 * @return array The same summary as import() (without the squad figures), plus 'seasons': how many seasons were imported.
	 */
	public static function import_seasons( array $season_ids, array $available ) {
		$total = array(
			'created'     => 0,
			'updated'     => 0,
			'unchanged'   => 0,
			'kept'        => 0,
			'skipped'     => 0,
			'seasons'     => 0,
			'errors'      => array(),
			'key_problem' => false,
		);

		foreach ( array_unique( array_map( 'intval', $season_ids ) ) as $season_id ) {
			if ( ! isset( $available[ $season_id ] ) ) {
				continue;
			}

			$summary = self::import( $season_id );
			++$total['seasons'];
			foreach ( array( 'created', 'updated', 'unchanged', 'kept', 'skipped' ) as $field ) {
				$total[ $field ] += $summary[ $field ];
			}
			foreach ( $summary['errors'] as $error ) {
				/* translators: 1: season name, 2: error message */
				$total['errors'][] = sprintf( __( '%1$s, %2$s', 'chess-army-knife' ), $available[ $season_id ], $error );
			}
			$total['key_problem'] = $total['key_problem'] || $summary['key_problem'];
		}

		return $total;
	}

	/**
	 * Handle the "Import selected seasons" button.
	 */
	public static function handle_import_old_seasons() {
		if ( ! Chess_Army_Knife_Teams::user_can_manage() || ! check_admin_referer( self::ACTION_OLD ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ) );
		}

		$available = self::old_seasons( Chess_Army_Knife_Settings::get_club_teams() );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by check_admin_referer() above.
		$chosen = isset( $_POST['seasons'] ) && is_array( $_POST['seasons'] ) ? array_map( 'absint', wp_unslash( $_POST['seasons'] ) ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by check_admin_referer() above.
		if ( ! empty( $_POST['all_seasons'] ) ) {
			$chosen = array_keys( $available );
		}

		$summary = self::import_seasons( $chosen, $available );
		set_transient( self::result_key() . '_old', $summary, MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( array( 'page' => Chess_Army_Knife_League_Games::PAGE ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Transient holding the current user's last import summary.
	 *
	 * @return string
	 */
	protected static function result_key() {
		return 'chess_army_knife_import_result_' . get_current_user_id();
	}

	/**
	 * Show what the import that was just run did, once. The League games screen draws this at its top.
	 */
	public static function render_notices() {
		$result     = get_transient( self::result_key() );
		$old_result = get_transient( self::result_key() . '_old' );
		delete_transient( self::result_key() );
		delete_transient( self::result_key() . '_old' );
		?>
		<?php if ( is_array( $result ) ) : ?>
			<div class="notice notice-success"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: new events, 2: refreshed events, 3: unchanged events, 4: events left alone, 5: fixtures skipped */
						__( 'Import finished: %1$d created, %2$d updated, %3$d unchanged, %4$d left alone, %5$d skipped (date not readable).', 'chess-army-knife' ),
						$result['created'],
						$result['updated'],
						$result['unchanged'],
						$result['kept'],
						$result['skipped']
					)
				);
				?>
			</p></div>
			<?php if ( ! empty( $result['squad_added'] ) || ! empty( $result['players_unmatched'] ) ) : ?>
				<div class="notice notice-info"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: people added to squads, 2: players with no member record */
							__( 'Squads: %1$d people who played for a team were added to its squad. %2$d players are not on your member records (no matching ECF code).', 'chess-army-knife' ),
							(int) $result['squad_added'],
							(int) $result['players_unmatched']
						)
					);
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( ! empty( $result['unsorted'] ) ) : ?>
				<div class="notice notice-info"><p>
					<?php
					echo esc_html(
						/* translators: %d: number of team names */
						sprintf( _n( '%d team name has not been put in a club, so its home fixtures have no venue.', '%d team names have not been put in a club, so their home fixtures have no venue.', $result['unsorted'], 'chess-army-knife' ), $result['unsorted'] )
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Chess_Army_Knife_Clubs::PAGE ) ); ?>"><?php esc_html_e( 'Sort them into clubs', 'chess-army-knife' ); ?></a>
				</p></div>
			<?php endif; ?>
			<?php foreach ( $result['errors'] as $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endforeach; ?>
			<?php endif; ?>

		<?php if ( is_array( $old_result ) ) : ?>
			<div class="notice notice-success"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: seasons imported, 2: new events, 3: refreshed events, 4: unchanged events, 5: events left alone, 6: fixtures skipped */
						_n(
							'Earlier seasons imported (%1$d season): %2$d created, %3$d updated, %4$d unchanged, %5$d left alone, %6$d skipped (date not readable).',
							'Earlier seasons imported (%1$d seasons): %2$d created, %3$d updated, %4$d unchanged, %5$d left alone, %6$d skipped (date not readable).',
							$old_result['seasons'],
							'chess-army-knife'
						),
						$old_result['seasons'],
						$old_result['created'],
						$old_result['updated'],
						$old_result['unchanged'],
						$old_result['kept'],
						$old_result['skipped']
					)
				);
				?>
			</p></div>
			<?php foreach ( $old_result['errors'] as $error ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Draw the import controls: when it last ran, Import now and earlier seasons. The League games screen draws
	 * these below the games.
	 */
	public static function render_section() {
		$teams = Chess_Army_Knife_Settings::get_club_teams();
		?>
		<h2><?php esc_html_e( 'Import league games from the LMS', 'chess-army-knife' ); ?></h2>
		<?php $last_line = self::last_run_text( self::last_run(), time() ); ?>
		<?php if ( '' !== $last_line ) : ?>
			<p><strong><?php echo esc_html( $last_line ); ?></strong></p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'The import also runs by itself once a day, as long as there is an LMS API key and at least one team.', 'chess-army-knife' ); ?></p>

		<?php if ( '' === Chess_Army_Knife_LMS_Client::api_key() ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				printf(
					/* translators: %s: link to the Settings screen */
					wp_kses_post( __( 'Add your LMS API key on the %s screen before importing.', 'chess-army-knife' ) ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . Chess_Army_Knife_Settings::PAGE ) ) . '">' . esc_html__( 'Settings', 'chess-army-knife' ) . '</a>'
				);
				?>
			</p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Creates an event for each upcoming fixture of your club teams, tagged "League match" and linked to its league, and adds the people who played for each team to its squad. Running it again never duplicates events. An imported event you have edited, or moved to the trash, is left alone.', 'chess-army-knife' ); ?></p>
		<p>
			<?php
			printf(
				/* translators: %s: usual kick-off time */
				esc_html__( 'If the LMS does not give a start time, the usual kick-off time from Settings is used (currently %s).', 'chess-army-knife' ),
				esc_html( Chess_Army_Knife_Settings::get_options()['match_time'] )
			);
			?>
		</p>

		<?php if ( empty( $teams ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: link to the Teams screen */
					wp_kses_post( __( 'Add your teams, with the leagues they play in, on the %s screen first.', 'chess-army-knife' ) ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Teams::POST_TYPE ) ) . '">' . esc_html__( 'Teams', 'chess-army-knife' ) . '</a>'
				);
				?>
			</p>
		<?php else : ?>
			<ul class="ul-disc">
				<?php foreach ( $teams as $team ) : ?>
					<li><?php echo esc_html( $team['team'] . ' — ' . $team['event'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php submit_button( __( 'Import now', 'chess-army-knife' ) ); ?>
			</form>
			<?php self::render_old_seasons_form( $teams ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * The form for importing earlier seasons, when the LMS has any.
	 *
	 * @param array[] $teams Club teams.
	 */
	protected static function render_old_seasons_form( array $teams ) {
		if ( '' === Chess_Army_Knife_LMS_Client::api_key() ) {
			return;
		}

		$seasons = self::old_seasons( $teams );
		?>
		<h2><?php esc_html_e( 'Earlier seasons', 'chess-army-knife' ); ?></h2>
		<?php if ( empty( $seasons ) ) : ?>
			<p><?php esc_html_e( 'The LMS has no earlier seasons for your leagues.', 'chess-army-knife' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<p><?php esc_html_e( 'Bring in the fixtures of earlier seasons as past events, for your records. Each of your teams is matched to the event with the same name in that season, so a team whose league was called something else then is reported and skipped. Squads are not changed. Running it again never duplicates events.', 'chess-army-knife' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_OLD ); ?>" />
			<?php wp_nonce_field( self::ACTION_OLD ); ?>
			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Seasons to import', 'chess-army-knife' ); ?></legend>
				<?php foreach ( $seasons as $season_id => $season_name ) : ?>
					<p><label><input type="checkbox" name="seasons[]" value="<?php echo esc_attr( $season_id ); ?>" /> <?php echo esc_html( $season_name ); ?></label></p>
				<?php endforeach; ?>
				<p><label><input type="checkbox" name="all_seasons" value="1" /> <strong><?php esc_html_e( 'All earlier seasons', 'chess-army-knife' ); ?></strong></label></p>
			</fieldset>
			<?php submit_button( __( 'Import selected seasons', 'chess-army-knife' ), 'secondary' ); ?>
		</form>
		<?php
	}
}

Chess_Army_Knife_Events_Import::init();
