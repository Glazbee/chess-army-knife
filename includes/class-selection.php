<?php
/**
 * Team selection for a fixture: who is available, and who is picked.
 *
 * A fixture is an event linked to a team (see Chess_Army_Knife_Events_Import).
 * The captain asks the team's squad whether they can play; each member
 * replies yes, maybe or no from a link in the email, without an account.
 * The captain then builds a line-up, board by board, from current members of
 * the squad. The line-up is a draft, seen only by the captain and officers,
 * until it is published; publishing emails the people picked, and, when an
 * earlier line-up had been published, those who were dropped.
 *
 * Replies and line-ups are data about a person: they are in the person's
 * data export, removed when the person is erased or the event is deleted, and
 * pruned with the retention period.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Selection {

	const RESPONSES = array( 'yes', 'maybe', 'no' );
	const CATEGORY  = 'fixtures';

	/**
	 * Hook up cleanup.
	 */
	public static function init() {
		add_action( 'before_delete_post', array( __CLASS__, 'forget_event' ) );
		add_action( 'Chess_Army_Knife_cleanup_cache', array( __CLASS__, 'prune' ) );
	}

	/**
	 * Full name of a table.
	 *
	 * @param string $name availability or lineups.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'chess_army_knife_' . $name;
	}

	/**
	 * Create or upgrade the tables.
	 */
	public static function install_tables() {
		global $wpdb;

		$charset      = $wpdb->get_charset_collate();
		$availability = self::table( 'availability' );
		$lineups      = self::table( 'lineups' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$availability} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			team_id BIGINT(20) UNSIGNED NOT NULL,
			person_id BIGINT(20) UNSIGNED NOT NULL,
			response VARCHAR(5) NOT NULL DEFAULT '',
			requested_at DATETIME NOT NULL,
			responded_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY fixture_person (event_id,team_id,person_id),
			KEY person_id (person_id)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$lineups} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			team_id BIGINT(20) UNSIGNED NOT NULL,
			person_id BIGINT(20) UNSIGNED NOT NULL,
			board SMALLINT(5) UNSIGNED NOT NULL,
			is_published TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY fixture_person (event_id,team_id,person_id,is_published),
			KEY person_id (person_id)
			) {$charset};"
		);
	}

	/* -------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------- */

	/**
	 * The upcoming fixtures of teams: events linked to them that have not started.
	 *
	 * @param int[] $team_ids Team ids.
	 * @return array[] Each { event (see Chess_Army_Knife_Events::data()), team (see Chess_Army_Knife_Teams::all()) }, soonest first.
	 */
	public static function fixtures( array $team_ids ) {
		$teams    = array_column( Chess_Army_Knife_Teams::all(), null, 'id' );
		$fixtures = array();

		foreach ( Chess_Army_Knife_Events::query(
			array(
				'teams' => $team_ids,
				'limit' => 0,
			)
		) as $event ) {
			foreach ( $event['teams'] as $event_team ) {
				if ( in_array( $event_team['id'], $team_ids, true ) && isset( $teams[ $event_team['id'] ] ) ) {
					$fixtures[] = array(
						'event' => $event,
						'team'  => $teams[ $event_team['id'] ],
					);
				}
			}
		}
		return $fixtures;
	}

	/**
	 * The fixture for an event and team, or null if the team is not playing in it.
	 *
	 * @param int $event_id Event id.
	 * @param int $team_id  Team id.
	 * @return array|null { event, team }
	 */
	public static function fixture( $event_id, $team_id ) {
		$post = get_post( (int) $event_id );
		$team = Chess_Army_Knife_Teams::get( (int) $team_id );
		if ( ! $post || Chess_Army_Knife_Events::POST_TYPE !== $post->post_type || ! $team ) {
			return null;
		}
		$event = Chess_Army_Knife_Events::data( $post );
		return in_array( $team['id'], wp_list_pluck( $event['teams'], 'id' ), true ) ? array(
			'event' => $event,
			'team'  => $team,
		) : null;
	}

	/**
	 * Whether a fixture can still be changed (it has not started).
	 *
	 * @param array $event Event data.
	 * @return bool
	 */
	public static function is_open( array $event ) {
		return '' === $event['start'] || current_time( 'mysql' ) < $event['start'];
	}

	/**
	 * The squad members who can be asked or picked: current members with a record.
	 *
	 * @param int $team_id Team id.
	 * @return array[] Person rows, best rated first.
	 */
	public static function pool( $team_id ) {
		$today  = current_time( 'Y-m-d' );
		$people = array();
		foreach ( Chess_Army_Knife_Teams::squad( $team_id ) as $person_id ) {
			$person = Chess_Army_Knife_Membership_Store::get_member( $person_id );
			if ( $person && Chess_Army_Knife_Membership_Store::STATUS_ACTIVE === Chess_Army_Knife_Membership_Store::effective_status( $person, $today ) ) {
				$people[] = $person;
			}
		}
		usort( $people, array( __CLASS__, 'compare_rating' ) );
		return $people;
	}

	/**
	 * The rating to order a person by: the ECF rating, or else the manual one, or 0.
	 *
	 * @param array $person Person row.
	 * @return int
	 */
	public static function rating_of( array $person ) {
		if ( null !== $person['ecf_rating'] ) {
			return (int) $person['ecf_rating'];
		}
		return null !== $person['manual_rating'] ? (int) $person['manual_rating'] : 0;
	}

	/**
	 * Sort order: higher rating first, then name.
	 *
	 * @param array $a Person row.
	 * @param array $b Person row.
	 * @return int
	 */
	public static function compare_rating( array $a, array $b ) {
		$by_rating = self::rating_of( $b ) <=> self::rating_of( $a );
		return 0 !== $by_rating ? $by_rating : strcasecmp( $a['name'], $b['name'] );
	}

	/* -------------------------------------------------------------
	 * Availability
	 * ------------------------------------------------------------- */

	/**
	 * The replies for a fixture.
	 *
	 * @param int $event_id Event id.
	 * @param int $team_id  Team id.
	 * @return array[] Rows by person id: { id, event_id, team_id, person_id, response, requested_at, responded_at }.
	 */
	public static function availability( $event_id, $team_id ) {
		global $wpdb;

		$table = self::table( 'availability' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND team_id = %d", (int) $event_id, (int) $team_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$row['id']                = (int) $row['id'];
			$row['person_id']         = (int) $row['person_id'];
			$out[ $row['person_id'] ] = $row;
		}
		return $out;
	}

	/**
	 * One reply by its id.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function availability_by_id( $id ) {
		global $wpdb;

		$table = self::table( 'availability' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		if ( $row ) {
			$row['id']        = (int) $row['id'];
			$row['event_id']  = (int) $row['event_id'];
			$row['team_id']   = (int) $row['team_id'];
			$row['person_id'] = (int) $row['person_id'];
		}
		return $row;
	}

	/**
	 * Ask the squad whether they can play. Each person is asked once; asking again
	 * only reaches people who have not replied, so nobody is emailed twice for the same answer.
	 *
	 * @param int $event_id Event id.
	 * @param int $team_id  Team id.
	 * @return int How many emails were queued.
	 */
	public static function request( $event_id, $team_id ) {
		global $wpdb;

		$fixture = self::fixture( $event_id, $team_id );
		if ( ! $fixture || ! self::is_open( $fixture['event'] ) ) {
			return 0;
		}

		$existing = self::availability( $event_id, $team_id );
		$queued   = 0;

		foreach ( self::pool( $team_id ) as $person ) {
			$row = isset( $existing[ $person['id'] ] ) ? $existing[ $person['id'] ] : null;
			if ( $row && '' !== $row['response'] ) {
				continue; // Already replied.
			}

			if ( ! $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table; the table name is internal.
				$wpdb->insert(
					self::table( 'availability' ),
					array(
						'event_id'     => (int) $event_id,
						'team_id'      => (int) $team_id,
						'person_id'    => $person['id'],
						'requested_at' => current_time( 'mysql', true ),
					)
				);
				$row = self::availability( $event_id, $team_id )[ $person['id'] ];
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
				$wpdb->update( self::table( 'availability' ), array( 'requested_at' => current_time( 'mysql', true ) ), array( 'id' => $row['id'] ), array( '%s' ), array( '%d' ) );
			}

			$subject = sprintf(
				/* translators: %s: fixture name */
				__( 'Can you play? %s', 'chess-army-knife' ),
				$fixture['event']['title']
			);
			$body = sprintf(
				/* translators: 1: person's name, 2: team name, 3: fixture, 4: date and time, 5: place, 6: link */
				__( "Hello %1\$s,\n\nThe %2\$s captain would like to know if you can play in:\n\n%3\$s\n%4\$s%5\$s\n\nPlease let them know here (yes, maybe or no):\n%6\$s", 'chess-army-knife' ),
				Chess_Army_Knife_Names::person( $person, Chess_Army_Knife_Names::site_style() ),
				$fixture['team']['name'],
				$fixture['event']['title'],
				trim( Chess_Army_Knife_Events_Display::date_label( $fixture['event'] ) . ' ' . Chess_Army_Knife_Events_Display::time_label( $fixture['event'] ) ),
				'' !== $fixture['event']['location'] ? "\n" . $fixture['event']['location'] : '',
				self::reply_url( $row )
			);
			$queued += Chess_Army_Knife_Mailer::queue( $person, self::CATEGORY, $subject, $body ) ? 1 : 0;
		}
		return $queued;
	}

	/**
	 * The signature that makes a reply link valid for one person and fixture.
	 *
	 * @param array $row Availability row.
	 * @return string
	 */
	protected static function sign( array $row ) {
		return substr( hash_hmac( 'sha256', 'avail|' . $row['id'] . '|' . $row['event_id'] . '|' . $row['team_id'] . '|' . $row['person_id'], wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * The link in an email where someone replies.
	 *
	 * @param array $row Availability row.
	 * @return string
	 */
	public static function reply_url( array $row ) {
		return add_query_arg( 'cak_avail', rawurlencode( $row['id'] . '.' . self::sign( $row ) ), home_url( '/' ) );
	}

	/**
	 * The row a reply link is for.
	 *
	 * @param string $value Value of the link's query argument.
	 * @return array|null Null if the link is not valid.
	 */
	public static function row_for_link( $value ) {
		$parts = explode( '.', (string) $value );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return null;
		}
		$row = self::availability_by_id( (int) $parts[0] );
		return $row && hash_equals( self::sign( $row ), $parts[1] ) ? $row : null;
	}

	/**
	 * Record a reply.
	 *
	 * @param int    $id       Availability row id.
	 * @param string $response yes, maybe or no.
	 * @return true|WP_Error
	 */
	public static function respond( $id, $response ) {
		global $wpdb;

		$row = self::availability_by_id( $id );
		if ( ! $row || ! in_array( $response, self::RESPONSES, true ) ) {
			return new WP_Error( 'reply', __( 'That reply could not be saved.', 'chess-army-knife' ) );
		}
		$fixture = self::fixture( $row['event_id'], $row['team_id'] );
		if ( ! $fixture || ! self::is_open( $fixture['event'] ) ) {
			return new WP_Error( 'closed', __( 'This fixture has already started.', 'chess-army-knife' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->update(
			self::table( 'availability' ),
			array(
				'response'     => $response,
				'responded_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $row['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return true;
	}

	/* -------------------------------------------------------------
	 * Line-ups
	 * ------------------------------------------------------------- */

	/**
	 * A line-up, board by board.
	 *
	 * @param int  $event_id  Event id.
	 * @param int  $team_id   Team id.
	 * @param bool $published True for the published line-up, false for the draft.
	 * @return int[] Person id by board number.
	 */
	public static function lineup( $event_id, $team_id, $published ) {
		global $wpdb;

		$table = self::table( 'lineups' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT board, person_id FROM {$table} WHERE event_id = %d AND team_id = %d AND is_published = %d ORDER BY board ASC", (int) $event_id, (int) $team_id, $published ? 1 : 0 ), ARRAY_A );
		$lineup = array();
		foreach ( (array) $rows as $row ) {
			$lineup[ (int) $row['board'] ] = (int) $row['person_id'];
		}
		return $lineup;
	}

	/**
	 * Save the draft line-up. Only current members of the squad can be picked, each once.
	 *
	 * @param int   $event_id Event id.
	 * @param int   $team_id  Team id.
	 * @param int[] $boards   Person id by board number (0 or missing for an empty board).
	 * @return int How many boards were filled.
	 */
	public static function save_draft( $event_id, $team_id, array $boards ) {
		$allowed = wp_list_pluck( self::pool( $team_id ), 'id' );
		$max     = Chess_Army_Knife_Captains::boards( $team_id );
		$clean   = array();

		foreach ( $boards as $board => $person_id ) {
			$board     = (int) $board;
			$person_id = (int) $person_id;
			if ( $board >= 1 && $board <= $max && in_array( $person_id, $allowed, true ) && ! in_array( $person_id, $clean, true ) ) {
				$clean[ $board ] = $person_id;
			}
		}

		self::replace_lineup( $event_id, $team_id, false, $clean );
		return count( $clean );
	}

	/**
	 * Suggest a line-up: everyone who said yes, then maybe, best rated first, filling the boards in order.
	 *
	 * @param int $event_id Event id.
	 * @param int $team_id  Team id.
	 * @return int[] Person id by board number.
	 */
	public static function suggest( $event_id, $team_id ) {
		$replies = self::availability( $event_id, $team_id );
		$max     = Chess_Army_Knife_Captains::boards( $team_id );
		$lineup  = array();

		foreach ( array( 'yes', 'maybe' ) as $wanted ) {
			foreach ( self::pool( $team_id ) as $person ) {
				if ( count( $lineup ) < $max && isset( $replies[ $person['id'] ] ) && $wanted === $replies[ $person['id'] ]['response'] ) {
					$lineup[ count( $lineup ) + 1 ] = $person['id'];
				}
			}
		}
		return $lineup;
	}

	/**
	 * Publish the draft: it becomes the line-up, and the people picked are emailed, and any who were dropped from an earlier published line-up.
	 *
	 * @param int $event_id Event id.
	 * @param int $team_id  Team id.
	 * @return int How many emails were queued.
	 */
	public static function publish( $event_id, $team_id ) {
		$fixture = self::fixture( $event_id, $team_id );
		if ( ! $fixture || ! self::is_open( $fixture['event'] ) ) {
			return 0;
		}

		$before = self::lineup( $event_id, $team_id, true );
		$draft  = self::lineup( $event_id, $team_id, false );
		self::replace_lineup( $event_id, $team_id, true, $draft );

		$when   = trim( Chess_Army_Knife_Events_Display::date_label( $fixture['event'] ) . ' ' . Chess_Army_Knife_Events_Display::time_label( $fixture['event'] ) );
		$queued = 0;

		foreach ( $draft as $board => $person_id ) {
			if ( isset( $before[ $board ] ) && $before[ $board ] === $person_id ) {
				continue; // Nothing changed for them.
			}
			$person = Chess_Army_Knife_Membership_Store::get_member( $person_id );
			if ( ! $person ) {
				continue;
			}
			$subject = sprintf(
				/* translators: %s: fixture name */
				__( 'You are selected: %s', 'chess-army-knife' ),
				$fixture['event']['title']
			);
			$body = sprintf(
				/* translators: 1: person's name, 2: team name, 3: board number, 4: fixture, 5: date and time, 6: place */
				__( "Hello %1\$s,\n\nYou have been picked for the %2\$s team on board %3\$d:\n\n%4\$s\n%5\$s%6\$s\n\nIf you cannot play, please tell your captain as soon as you can.", 'chess-army-knife' ),
				Chess_Army_Knife_Names::person( $person, Chess_Army_Knife_Names::site_style() ),
				$fixture['team']['name'],
				$board,
				$fixture['event']['title'],
				$when,
				'' !== $fixture['event']['location'] ? "\n" . $fixture['event']['location'] : ''
			);
			$queued += Chess_Army_Knife_Mailer::queue( $person, self::CATEGORY, $subject, $body ) ? 1 : 0;
		}

		foreach ( array_diff( $before, $draft ) as $person_id ) {
			$person = Chess_Army_Knife_Membership_Store::get_member( $person_id );
			if ( ! $person ) {
				continue;
			}
			$subject = sprintf(
				/* translators: %s: fixture name */
				__( 'Change to the line-up: %s', 'chess-army-knife' ),
				$fixture['event']['title']
			);
			$body = sprintf(
				/* translators: 1: person's name, 2: team name, 3: fixture */
				__( "Hello %1\$s,\n\nThe %2\$s line-up for %3\$s has changed and you are no longer picked. Please check with your captain if you have any questions.", 'chess-army-knife' ),
				Chess_Army_Knife_Names::person( $person, Chess_Army_Knife_Names::site_style() ),
				$fixture['team']['name'],
				$fixture['event']['title']
			);
			$queued += Chess_Army_Knife_Mailer::queue( $person, self::CATEGORY, $subject, $body ) ? 1 : 0;
		}
		return $queued;
	}

	/**
	 * Replace one line-up.
	 *
	 * @param int   $event_id  Event id.
	 * @param int   $team_id   Team id.
	 * @param bool  $published Which line-up.
	 * @param int[] $boards    Person id by board.
	 */
	protected static function replace_lineup( $event_id, $team_id, $published, array $boards ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete(
			self::table( 'lineups' ),
			array(
				'event_id'     => (int) $event_id,
				'team_id'      => (int) $team_id,
				'is_published' => $published ? 1 : 0,
			),
			array( '%d', '%d', '%d' )
		);
		foreach ( $boards as $board => $person_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table; the table name is internal.
			$wpdb->insert(
				self::table( 'lineups' ),
				array(
					'event_id'     => (int) $event_id,
					'team_id'      => (int) $team_id,
					'person_id'    => (int) $person_id,
					'board'        => (int) $board,
					'is_published' => $published ? 1 : 0,
				)
			);
		}
	}

	/**
	 * The upcoming fixtures a person has been picked for (published line-ups only).
	 *
	 * @param int $person_id Person id.
	 * @return array[] Each { event, team, board }, soonest first.
	 */
	public static function selections_for_person( $person_id ) {
		global $wpdb;

		$table = self::table( 'lineups' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event_id, team_id, board FROM {$table} WHERE person_id = %d AND is_published = 1", (int) $person_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$fixture = self::fixture( $row['event_id'], $row['team_id'] );
			if ( $fixture && self::is_open( $fixture['event'] ) ) {
				$out[] = $fixture + array( 'board' => (int) $row['board'] );
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['event']['start'], $b['event']['start'] );
			}
		);
		return $out;
	}

	/**
	 * Everything recorded about a person here, for the privacy export.
	 *
	 * @param int $person_id Person id.
	 * @return array[] Each { event_id, team_id, response, board } (board 0 for a reply only).
	 */
	public static function records_for_person( $person_id ) {
		global $wpdb;

		$availability = self::table( 'availability' );
		$lineups      = self::table( 'lineups' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom tables; the table names are internal and dynamic values are prepared.
		$replies = (array) $wpdb->get_results( $wpdb->prepare( "SELECT event_id, team_id, response FROM {$availability} WHERE person_id = %d", (int) $person_id ), ARRAY_A );
		$picked  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT event_id, team_id, board FROM {$lineups} WHERE person_id = %d AND is_published = 1", (int) $person_id ), ARRAY_A );
		// phpcs:enable

		$out = array();
		foreach ( $replies as $row ) {
			$out[ $row['event_id'] . '-' . $row['team_id'] ] = array(
				'event_id' => (int) $row['event_id'],
				'team_id'  => (int) $row['team_id'],
				'response' => $row['response'],
				'board'    => 0,
			);
		}
		foreach ( $picked as $row ) {
			$key                  = $row['event_id'] . '-' . $row['team_id'];
			$out[ $key ]          = ( isset( $out[ $key ] ) ? $out[ $key ] : array(
				'event_id' => (int) $row['event_id'],
				'team_id'  => (int) $row['team_id'],
				'response' => '',
			) ) + array( 'board' => 0 );
			$out[ $key ]['board'] = (int) $row['board'];
		}
		return array_values( $out );
	}

	/**
	 * Whether anything here is recorded about a person.
	 *
	 * @param int $person_id Person id.
	 * @return bool
	 */
	public static function person_has_records( $person_id ) {
		return (bool) self::records_for_person( $person_id );
	}

	/**
	 * Delete everything here about a person (when their record is erased).
	 *
	 * @param int $person_id Person id.
	 */
	public static function remove_person( $person_id ) {
		global $wpdb;

		foreach ( array( 'availability', 'lineups' ) as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$wpdb->delete( self::table( $name ), array( 'person_id' => (int) $person_id ), array( '%d' ) );
		}
	}

	/**
	 * Delete an event's replies and line-ups when it is deleted.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public static function forget_event( $post_id ) {
		global $wpdb;

		if ( Chess_Army_Knife_Events::POST_TYPE === get_post_type( $post_id ) ) {
			foreach ( array( 'availability', 'lineups' ) as $name ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
				$wpdb->delete( self::table( $name ), array( 'event_id' => (int) $post_id ), array( '%d' ) );
			}
		}
	}

	/**
	 * Delete old replies after the retention period. Run daily.
	 *
	 * @return int How many rows were deleted.
	 */
	public static function prune() {
		global $wpdb;

		$months = (int) Chess_Army_Knife_Settings::get_options()['member_retention_months'];
		if ( $months <= 0 ) {
			return 0;
		}

		$cutoff = strtotime( '-' . $months . ' months' );
		$table  = self::table( 'availability' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE requested_at < %s", gmdate( 'Y-m-d H:i:s', $cutoff ) ) );

		// Line-ups of fixtures that were played before the cutoff.
		$lineups = self::table( 'lineups' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal.
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT event_id FROM {$lineups}" ) as $event_id ) {
			$start = (string) get_post_meta( (int) $event_id, Chess_Army_Knife_Events::META_START, true );
			if ( '' === $start || substr( $start, 0, 10 ) < wp_date( 'Y-m-d', $cutoff ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
				$deleted += (int) $wpdb->delete( $lineups, array( 'event_id' => (int) $event_id ), array( '%d' ) );
			}
		}
		return $deleted;
	}
}

Chess_Army_Knife_Selection::init();
