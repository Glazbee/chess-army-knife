<?php
/**
 * The club's officers: named positions (Chairman, Secretary...) held by club
 * members, and the history of who held what and when.
 *
 * Positions are a short, ordered list kept in an option. Every spell in a
 * position is a row in the officers table, with a start date and, once the
 * person stood down, an end date; a row without an end date is a current
 * officer. The position's name is copied onto the row, so a position that is
 * renamed or removed later does not rewrite or lose the history.
 *
 * Team captains are officers too. The captain of a team is still chosen on the
 * team (see Chess_Army_Knife_Teams), which stays the source of truth; this class
 * keeps a row in the officers table in step with it so captaincies appear in the
 * history. A captaincy row has the team's id and the team's name.
 *
 * Only names are ever shown on the website. Rows are removed when the person's
 * record is erased or deleted, like the squads.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Officers {

	const OPTION          = 'Chess_Army_Knife_officer_positions';
	const CAPTAIN_KEY     = 'captain';
	const MAX_POSITIONS   = 40;
	const MAX_NAME_LENGTH = 100;

	/**
	 * Hook up the upkeep of captaincies and the clean-up when a team goes.
	 */
	public static function init() {
		add_action( 'save_post_' . Chess_Army_Knife_Teams::POST_TYPE, array( __CLASS__, 'sync_captains' ), 20 );
		add_action( 'before_delete_post', array( __CLASS__, 'end_captaincy_of_deleted_team' ) );
	}

	/**
	 * Full name of the officers table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'chess_army_knife_officers';
	}

	/**
	 * Create or upgrade the officers table.
	 */
	public static function install_table() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			position_key VARCHAR(40) NOT NULL,
			position_name VARCHAR(191) NOT NULL DEFAULT '',
			team_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			person_id BIGINT(20) UNSIGNED NOT NULL,
			start_date DATE NOT NULL,
			end_date DATE NULL,
			PRIMARY KEY  (id),
			KEY person_id (person_id),
			KEY position_key (position_key,team_id)
			) {$charset};"
		);
	}

	/* -------------------------------------------------------------
	 * Positions
	 * ------------------------------------------------------------- */

	/**
	 * The positions, in the club's chosen order.
	 *
	 * @return array[] Each { id, name }.
	 */
	public static function positions() {
		return self::clean_positions( get_option( self::OPTION, array() ) );
	}

	/**
	 * Clean a stored or submitted list of positions. A position needs a name; a
	 * missing or repeated id is replaced by a new one, and the list is capped.
	 *
	 * @param mixed $raw List of { id, name }.
	 * @return array[] Each { id, name }.
	 */
	public static function clean_positions( $raw ) {
		$positions = array();
		$seen      = array();

		foreach ( is_array( $raw ) ? $raw : array() as $position ) {
			$name = is_array( $position ) && isset( $position['name'] ) ? trim( sanitize_text_field( (string) $position['name'] ) ) : '';
			$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::MAX_NAME_LENGTH ) : substr( $name, 0, self::MAX_NAME_LENGTH );
			if ( '' === $name || count( $positions ) >= self::MAX_POSITIONS ) {
				continue;
			}

			$id = isset( $position['id'] ) ? sanitize_key( (string) $position['id'] ) : '';
			if ( '' === $id || self::CAPTAIN_KEY === $id || isset( $seen[ $id ] ) ) {
				$id = self::new_position_id( $seen );
			}
			$seen[ $id ] = true;
			$positions[] = array(
				'id'   => $id,
				'name' => $name,
			);
		}

		return $positions;
	}

	/**
	 * A fresh position id, such as p4k9x2ab.
	 *
	 * @param array $taken Ids already in use, as keys.
	 * @return string
	 */
	protected static function new_position_id( array $taken ) {
		do {
			$id = 'p' . strtolower( wp_generate_password( 7, false ) );
		} while ( isset( $taken[ $id ] ) );
		return $id;
	}

	/**
	 * Save the list of positions. Anyone holding a position that is no longer
	 * on the list stands down today; their spell stays in the history. A position
	 * that was renamed has its name updated throughout the history.
	 *
	 * @param array[] $positions Submitted list of { id, name } (see clean_positions()).
	 */
	public static function save_positions( array $positions ) {
		global $wpdb;

		$old   = wp_list_pluck( self::positions(), 'name', 'id' );
		$new   = self::clean_positions( $positions );
		$kept  = wp_list_pluck( $new, 'name', 'id' );
		$today = current_time( 'Y-m-d' );
		$table = self::table();

		foreach ( $old as $id => $name ) {
			if ( ! isset( $kept[ $id ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET end_date = %s WHERE position_key = %s AND end_date IS NULL", $today, $id ) );
			} elseif ( $kept[ $id ] !== $name ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table.
				$wpdb->update( $table, array( 'position_name' => $kept[ $id ] ), array( 'position_key' => $id ) );
			}
		}

		update_option( self::OPTION, $new, false );
	}

	/**
	 * Move a position one place up or down the list.
	 *
	 * @param array[] $positions Positions (see positions()).
	 * @param string  $id        Id of the position to move.
	 * @param string  $direction 'up' or 'down'.
	 * @return array[] The list in its new order.
	 */
	public static function move( array $positions, $id, $direction ) {
		$ids   = array_values( wp_list_pluck( $positions, 'id' ) );
		$index = array_search( $id, $ids, true );
		$swap  = 'up' === $direction ? $index - 1 : $index + 1;

		if ( false === $index || $swap < 0 || $swap >= count( $positions ) ) {
			return $positions;
		}

		$positions           = array_values( $positions );
		$moved               = $positions[ $index ];
		$positions[ $index ] = $positions[ $swap ];
		$positions[ $swap ]  = $moved;
		return $positions;
	}

	/* -------------------------------------------------------------
	 * Terms
	 * ------------------------------------------------------------- */

	/**
	 * Make a member the holder of a position, from a date. Anyone else holding
	 * the position stays: a position such as "Committee member" can have several
	 * people, and a position with one holder is ended with end_term() first.
	 *
	 * @param string $position_id Id of a position from positions().
	 * @param int    $person_id   Member id.
	 * @param string $start_date  Date the term began, Y-m-d; today if empty or not a date.
	 * @return int The new row's id, or 0 if the position or the member is unknown, or they already hold it.
	 */
	public static function assign( $position_id, $person_id, $start_date = '' ) {
		global $wpdb;

		$names = wp_list_pluck( self::positions(), 'name', 'id' );
		$table = self::table();
		if ( ! isset( $names[ $position_id ] ) || ! Chess_Army_Knife_Membership_Store::get_member( $person_id ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$holds = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE position_key = %s AND person_id = %d AND end_date IS NULL", $position_id, (int) $person_id ) );
		if ( $holds ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table.
		$wpdb->insert(
			$table,
			array(
				'position_key'  => $position_id,
				'position_name' => $names[ $position_id ],
				'team_id'       => 0,
				'person_id'     => (int) $person_id,
				'start_date'    => self::clean_date( $start_date, current_time( 'Y-m-d' ) ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Say that someone stood down from a position they hold. A captaincy is
	 * ended by changing the team's captain, not here.
	 *
	 * @param int    $term_id  Row id.
	 * @param string $end_date Date the term ended, Y-m-d; today if empty or not a date. Never before the start.
	 * @return bool Whether a current officer was ended.
	 */
	public static function end_term( $term_id, $end_date = '' ) {
		global $wpdb;

		$term = self::get_term( $term_id );
		if ( ! $term || '' !== $term['end_date'] || self::CAPTAIN_KEY === $term['position_key'] ) {
			return false;
		}

		$end = self::clean_date( $end_date, current_time( 'Y-m-d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table.
		$wpdb->update( self::table(), array( 'end_date' => max( $end, $term['start_date'] ) ), array( 'id' => (int) $term_id ) );
		return true;
	}

	/**
	 * Read a date, or fall back.
	 *
	 * @param string $date     A date such as 2025-03-04.
	 * @param string $fallback Used if it is not a real date.
	 * @return string Y-m-d
	 */
	protected static function clean_date( $date, $fallback ) {
		$date = (string) $date;
		$time = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? strtotime( $date . ' 00:00:00 UTC' ) : false;
		return ( $time && gmdate( 'Y-m-d', $time ) === $date ) ? $date : $fallback;
	}

	/**
	 * One row of the officers table.
	 *
	 * @param int $term_id Row id.
	 * @return array|null See terms(); null if there is no such row.
	 */
	protected static function get_term( $term_id ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $term_id ), ARRAY_A );
		return $row ? self::cast_term( $row ) : null;
	}

	/**
	 * Normalise the types of a row.
	 *
	 * @param array $row Raw row.
	 * @return array Each { id, position_key, position_name, team_id, person_id, start_date, end_date ('' while current) }.
	 */
	protected static function cast_term( array $row ) {
		return array(
			'id'            => (int) $row['id'],
			'position_key'  => (string) $row['position_key'],
			'position_name' => (string) $row['position_name'],
			'team_id'       => (int) $row['team_id'],
			'person_id'     => (int) $row['person_id'],
			'start_date'    => (string) $row['start_date'],
			'end_date'      => null === $row['end_date'] ? '' : (string) $row['end_date'],
		);
	}

	/**
	 * Every term on record, current and past, most recent start first.
	 *
	 * @param bool $current_only Only the people who hold a position now.
	 * @return array[] See cast_term().
	 */
	public static function terms( $current_only = false ) {
		global $wpdb;

		$table = self::table();
		$where = $current_only ? 'WHERE end_date IS NULL' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name and clause are internal.
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY start_date DESC, id DESC", ARRAY_A );
		return array_map( array( __CLASS__, 'cast_term' ), $rows );
	}

	/**
	 * The label of a term: the position's name, or "Captain, Team A".
	 *
	 * @param array $term A term from terms().
	 * @return string
	 */
	public static function label_of_term( array $term ) {
		if ( self::CAPTAIN_KEY !== $term['position_key'] ) {
			return $term['position_name'];
		}
		/* translators: %s: the name of a team */
		return sprintf( __( 'Captain, %s', 'chess-army-knife' ), $term['position_name'] );
	}

	/**
	 * Forget a person's terms (when their record is erased or deleted).
	 *
	 * @param int $person_id Member id.
	 */
	public static function remove_person( $person_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete( self::table(), array( 'person_id' => (int) $person_id ), array( '%d' ) );
	}

	/* -------------------------------------------------------------
	 * Captains
	 * ------------------------------------------------------------- */

	/**
	 * Bring the captaincies in the history in step with the teams: a team's
	 * captain who changed has the old term ended and a new one begun today, and a
	 * captain with no term yet (set before officers were kept) has one begun today.
	 * It is safe to call at any time.
	 */
	public static function sync_captains() {
		global $wpdb;

		$table = self::table();
		$today = current_time( 'Y-m-d' );
		$open  = array();
		foreach ( self::terms( true ) as $term ) {
			if ( self::CAPTAIN_KEY === $term['position_key'] ) {
				$open[ $term['team_id'] ][] = $term;
			}
		}

		foreach ( Chess_Army_Knife_Teams::all() as $team ) {
			$has_current = false;
			foreach ( isset( $open[ $team['id'] ] ) ? $open[ $team['id'] ] : array() as $term ) {
				if ( $term['person_id'] === $team['captain_id'] ) {
					$has_current = true;
					// A renamed team keeps its history under its present name.
					if ( $term['position_name'] !== $team['name'] ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table.
						$wpdb->update( $table, array( 'position_name' => $team['name'] ), array( 'team_id' => $team['id'] ) );
					}
				} else {
					self::end_open_term( $term, $today );
				}
			}

			if ( $team['captain_id'] && ! $has_current ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table.
				$wpdb->insert(
					$table,
					array(
						'position_key'  => self::CAPTAIN_KEY,
						'position_name' => $team['name'],
						'team_id'       => $team['id'],
						'person_id'     => $team['captain_id'],
						'start_date'    => $today,
					)
				);
			}
		}
	}

	/**
	 * End a term, for a captain who has changed (never before it began).
	 *
	 * @param array  $term A term from terms().
	 * @param string $date Date it ended, Y-m-d.
	 */
	protected static function end_open_term( array $term, $date ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table.
		$wpdb->update( self::table(), array( 'end_date' => max( $date, $term['start_date'] ) ), array( 'id' => $term['id'] ) );
	}

	/**
	 * A team that is deleted has no captain any more: the captaincy ends today.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public static function end_captaincy_of_deleted_team( $post_id ) {
		if ( Chess_Army_Knife_Teams::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		foreach ( self::terms( true ) as $term ) {
			if ( self::CAPTAIN_KEY === $term['position_key'] && $term['team_id'] === (int) $post_id ) {
				self::end_open_term( $term, current_time( 'Y-m-d' ) );
			}
		}
	}

	/* -------------------------------------------------------------
	 * What the block shows
	 * ------------------------------------------------------------- */

	/**
	 * The things the block can list, in the club's default order: each position,
	 * then each team's captain.
	 *
	 * @return array[] Each { key, label, kind ('position' or 'captain') }; a captain's key is "team-" and the team id.
	 */
	public static function items() {
		$items = array();
		foreach ( self::positions() as $position ) {
			$items[] = array(
				'key'   => $position['id'],
				'label' => $position['name'],
				'kind'  => 'position',
			);
		}
		foreach ( Chess_Army_Knife_Teams::all() as $team ) {
			$items[] = array(
				'key'   => 'team-' . $team['id'],
				'label' => self::label_of_term(
					array(
						'position_key'  => self::CAPTAIN_KEY,
						'position_name' => $team['name'],
					)
				),
				'kind'  => 'captain',
			);
		}
		return $items;
	}

	/**
	 * Put the items in the order the block asks for: the keys it lists first, in
	 * its order, then anything it does not mention (a position added since) in the
	 * default order. Keys that no longer exist are ignored.
	 *
	 * @param array[] $items Items from items().
	 * @param mixed   $order List of keys.
	 * @return array[]
	 */
	public static function order_items( array $items, $order ) {
		$by_key  = array();
		$ordered = array();
		foreach ( $items as $item ) {
			$by_key[ $item['key'] ] = $item;
		}

		foreach ( is_array( $order ) ? $order : array() as $key ) {
			$key = is_string( $key ) ? $key : '';
			if ( isset( $by_key[ $key ] ) ) {
				$ordered[ $key ] = $by_key[ $key ];
				unset( $by_key[ $key ] );
			}
		}

		return array_values( $ordered + $by_key );
	}

	/**
	 * Who to show: each item with the people who hold it now.
	 *
	 * @param array $args {
	 *     @type string[] $order            Keys of items, in the order wanted (see order_items()).
	 *     @type bool     $include_captains Whether team captains are listed.
	 * }
	 * @return array[] Each { key, label, people } where people are { name, since }; items nobody holds are left out.
	 */
	public static function listing( array $args = array() ) {
		$args = $args + array(
			'order'            => array(),
			'include_captains' => true,
		);

		// Who holds what now: positions from the history, captains from the teams themselves.
		$holders = array();
		$since   = array();
		foreach ( self::terms( true ) as $term ) {
			if ( self::CAPTAIN_KEY === $term['position_key'] ) {
				$since[ 'team-' . $term['team_id'] ] = $term;
			} else {
				$holders[ $term['position_key'] ][] = $term;
			}
		}
		$captains = array();
		if ( $args['include_captains'] ) {
			foreach ( Chess_Army_Knife_Teams::all() as $team ) {
				if ( $team['captain_id'] ) {
					$captains[ 'team-' . $team['id'] ] = $team['captain_id'];
				}
			}
		}

		$ids = $captains;
		foreach ( $holders as $terms ) {
			$ids = array_merge( $ids, wp_list_pluck( $terms, 'person_id' ) );
		}
		$names = array();
		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_ids( array_unique( array_map( 'intval', $ids ) ) ) as $person ) {
			$names[ $person['id'] ] = $person['name'];
		}

		$listing = array();
		foreach ( self::order_items( self::items(), $args['order'] ) as $item ) {
			$people = array();
			if ( 'position' === $item['kind'] ) {
				// Oldest term first, so the longest-serving holder is listed first.
				foreach ( array_reverse( isset( $holders[ $item['key'] ] ) ? $holders[ $item['key'] ] : array() ) as $term ) {
					if ( isset( $names[ $term['person_id'] ] ) ) {
						$people[] = array(
							'name'  => $names[ $term['person_id'] ],
							'since' => $term['start_date'],
						);
					}
				}
			} elseif ( isset( $captains[ $item['key'] ], $names[ $captains[ $item['key'] ] ] ) ) {
				$people[] = array(
					'name'  => $names[ $captains[ $item['key'] ] ],
					'since' => isset( $since[ $item['key'] ] ) && $since[ $item['key'] ]['person_id'] === $captains[ $item['key'] ] ? $since[ $item['key'] ]['start_date'] : '',
				);
			}

			if ( $people ) {
				$listing[] = array(
					'key'    => $item['key'],
					'label'  => $item['label'],
					'people' => $people,
				);
			}
		}
		return $listing;
	}
}

Chess_Army_Knife_Officers::init();
