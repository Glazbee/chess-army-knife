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
	const PAGE         = 'chess-army-knife-import-events';

	/**
	 * Hook up the admin page and its form handler.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_import' ) );
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
	 *     @type array[] $candidates Each { key, title, start, location, league, club_teams }, where club_teams maps a league entry key to 'home' or 'away'.
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
					'league'     => Chess_Army_Knife_Events::league_ref( $team['org'], $team['event'] ),
					'club_teams' => $club_teams,
				);
			}
		}

		return array(
			'candidates' => array_values( $candidates ),
			'skipped'    => $skipped,
		);
	}

	/**
	 * Fetch the fixtures of every league the club's teams are in.
	 *
	 * @param array[] $teams   Club teams.
	 * @param array   $errors  Filled with a message for each league that could not be loaded.
	 * @return array Normalised match rows keyed by "org|event" (lower case).
	 */
	protected static function fetch_matches( array $teams, array &$errors ) {
		$by_league = array();

		foreach ( $teams as $team ) {
			$group = strtolower( Chess_Army_Knife_Events::league_ref( $team['org'], $team['event'] ) );
			if ( isset( $by_league[ $group ] ) ) {
				continue;
			}

			// An import is a manual refresh, so don't use the cached copies.
			$rows = Chess_Army_Knife_LMS_Client::get_fixtures( $team['org'], $team['event'], true );

			$by_league[ $group ] = array();
			if ( is_wp_error( $rows ) ) {
				/* translators: 1: league / division name, 2: error message */
				$errors[] = sprintf( __( '%1$s: %2$s', 'chess-army-knife' ), $team['event'], $rows->get_error_message() );
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
	 * @return array {
	 *     @type int      $created   New events.
	 *     @type int      $updated   Untouched imported events that were refreshed.
	 *     @type int      $unchanged Events that already matched.
	 *     @type int      $kept      Events left alone (edited by hand, or trashed).
	 *     @type int      $skipped   Fixtures with a date that couldn't be read.
	 *     @type string[] $errors    Leagues that couldn't be loaded.
	 * }
	 */
	public static function import() {
		$summary = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'kept'      => 0,
			'skipped'   => 0,
			'errors'    => array(),
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
		$matches = self::fetch_matches( $teams, $summary['errors'] );
		$plan    = self::plan( $teams, $matches, current_time( 'Y-m-d' ), $default_time );

		$summary['skipped'] = $plan['skipped'];

		foreach ( $plan['candidates'] as $candidate ) {
			$existing = self::find_event( $candidate['key'] );

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
						) + ( '' !== $candidate['location'] ? array( Chess_Army_Knife_Events::META_LOCATION => $candidate['location'] ) : array() ),
					),
					true
				);
				if ( ! is_wp_error( $post_id ) ) {
					wp_set_object_terms( $post_id, array( self::TAG ), Chess_Army_Knife_Events::TAXONOMY );
					self::sync_teams( $post_id, $candidate['club_teams'] );
					++$summary['created'];
				}
				continue;
			}

			if ( 'trash' === $existing->post_status || get_post_meta( $existing->ID, self::META_EDITED, true ) ) {
				++$summary['kept'];
				continue;
			}

			$changed = self::sync_teams( $existing->ID, $candidate['club_teams'] );
			if ( get_post_meta( $existing->ID, Chess_Army_Knife_Events::META_START, true ) !== $candidate['start'] ) {
				update_post_meta( $existing->ID, Chess_Army_Knife_Events::META_START, $candidate['start'] );
				$changed = true;
			}
			if ( '' !== $candidate['location'] && get_post_meta( $existing->ID, Chess_Army_Knife_Events::META_LOCATION, true ) !== $candidate['location'] ) {
				update_post_meta( $existing->ID, Chess_Army_Knife_Events::META_LOCATION, $candidate['location'] );
				$changed = true;
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

		set_transient( self::result_key(), self::import(), MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) ) );
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
	 * Render the import page.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Teams::user_can_manage(), __( 'Import Events from LMS', 'chess-army-knife' ), 'teams' ) ) {
			return;
		}

		$teams  = Chess_Army_Knife_Settings::get_club_teams();
		$result = get_transient( self::result_key() );
		delete_transient( self::result_key() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Events from LMS', 'chess-army-knife' ); ?></h1>

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
				<?php foreach ( $result['errors'] as $error ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
				<?php endforeach; ?>
			<?php endif; ?>

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

			<p><?php esc_html_e( 'Creates an event for each upcoming fixture of your club teams, tagged "League match" and linked to its league. Running it again never duplicates events. An imported event you have edited, or moved to the trash, is left alone.', 'chess-army-knife' ); ?></p>
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
			<?php endif; ?>
		</div>
		<?php
	}
}

Chess_Army_Knife_Events_Import::init();
