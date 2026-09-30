<?php
/**
 * The club's teams: a permanent team such as "Club A", with the seasons it has
 * played beneath it.
 *
 * A team is a non-public post of type chess_army_team (17 characters; WordPress
 * allows 20). Its venue, captain and the seasons it plays in are post meta; a
 * season is an entry of the Club Teams list (league and team name) and is
 * linked by a key rather than copied, so the league details stay in one place.
 * The squad is a person-to-team link in its own small table. The captain and
 * the squad are personal data: they are for people with the membership
 * permission, are part of a person's data export, and are removed when the
 * person is erased. Members choose their WhatsApp groups by team id, so
 * renaming a team loses nobody's choice.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Teams {

	const POST_TYPE = 'chess_army_team';

	const META_VENUE   = '_chess_army_team_venue';
	const META_CAPTAIN = '_chess_army_team_captain';
	const META_SEASONS = '_chess_army_team_seasons';
	const META_COLOUR  = '_chess_army_team_colour';

	/**
	 * Hook up registration and cleanup.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'forget_team' ) );
	}

	/**
	 * Register the team post type, under the Memberships menu.
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Teams', 'chess-army-knife' ),
					'singular_name'      => __( 'Team', 'chess-army-knife' ),
					'add_new'            => __( 'Add Team', 'chess-army-knife' ),
					'add_new_item'       => __( 'Add New Team', 'chess-army-knife' ),
					'edit_item'          => __( 'Edit Team', 'chess-army-knife' ),
					'search_items'       => __( 'Search Teams', 'chess-army-knife' ),
					'not_found'          => __( 'No teams found.', 'chess-army-knife' ),
					'not_found_in_trash' => __( 'No teams found in the Trash.', 'chess-army-knife' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => Chess_Army_Knife_Memberships::MENU_SLUG,
				'show_in_rest' => false, // Classic editing screen: the details are plain fields.
				'supports'     => array( 'title', 'excerpt', 'page-attributes' ), // The excerpt is the description shown publicly.
				'capabilities' => Chess_Army_Knife_Memberships::post_capabilities(),
			)
		);
	}

	/**
	 * Full name of the squad table.
	 *
	 * @return string
	 */
	public static function squad_table() {
		global $wpdb;
		return $wpdb->prefix . 'chess_army_knife_squad';
	}

	/**
	 * Create or upgrade the squad table.
	 */
	public static function install_table() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = self::squad_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
			team_id BIGINT(20) UNSIGNED NOT NULL,
			person_id BIGINT(20) UNSIGNED NOT NULL,
			added_at DATETIME NOT NULL,
			PRIMARY KEY  (team_id,person_id),
			KEY person_id (person_id)
			) {$charset};"
		);
	}

	/* -------------------------------------------------------------
	 * Teams
	 * ------------------------------------------------------------- */

	/**
	 * The teams on offer, in their page order.
	 *
	 * @return array[] Each { id, name, description, venue, captain_id, seasons }.
	 */
	public static function all() {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
		return array_map( array( __CLASS__, 'team_data' ), $posts );
	}

	/**
	 * One published team.
	 *
	 * @param int $id Team id.
	 * @return array|null See all(); null if there is no such team.
	 */
	public static function get( $id ) {
		$post = get_post( (int) $id );
		return ( $post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) ? self::team_data( $post ) : null;
	}

	/**
	 * The fields of a team post.
	 *
	 * @param WP_Post $post Team post.
	 * @return array
	 */
	protected static function team_data( $post ) {
		$seasons = get_post_meta( $post->ID, self::META_SEASONS, true );
		return array(
			'id'          => (int) $post->ID,
			'name'        => get_the_title( $post ),
			'description' => (string) $post->post_excerpt,
			'venue'       => (string) get_post_meta( $post->ID, self::META_VENUE, true ),
			'captain_id'  => (int) get_post_meta( $post->ID, self::META_CAPTAIN, true ),
			'colour'      => (string) get_post_meta( $post->ID, self::META_COLOUR, true ),
			'seasons'     => is_array( $seasons ) ? array_values( array_map( 'strval', $seasons ) ) : array(),
		);
	}

	/**
	 * The teams a member can choose, for WhatsApp groups.
	 *
	 * @return string[] Team name by team id.
	 */
	public static function choices() {
		$choices = array();
		foreach ( self::all() as $team ) {
			$choices[ $team['id'] ] = $team['name'];
		}
		return $choices;
	}

	/**
	 * The identity of a season: an entry of the Club Teams list.
	 *
	 * @param array $club_team Club Teams entry { org, event, team }.
	 * @return string
	 */
	public static function season_key( array $club_team ) {
		return $club_team['org'] . '|' . $club_team['event'] . '|' . $club_team['team'];
	}

	/**
	 * The team that plays a season (an entry of the Club Teams list).
	 *
	 * @param string $season_key Key from season_key().
	 * @return array|null The team (see all()), or null if no team has that season.
	 */
	public static function team_for_season( $season_key ) {
		foreach ( self::all() as $team ) {
			if ( in_array( $season_key, $team['seasons'], true ) ) {
				return $team;
			}
		}
		return null;
	}

	/**
	 * The Club Teams entries a team plays in this season or has played in.
	 *
	 * @param array $team Team from all().
	 * @return array[] Each { org, event, team }.
	 */
	public static function seasons_of( array $team ) {
		$seasons = array();
		foreach ( Chess_Army_Knife_Settings::get_club_teams() as $club_team ) {
			if ( in_array( self::season_key( $club_team ), $team['seasons'], true ) ) {
				$seasons[] = $club_team;
			}
		}
		return $seasons;
	}

	/**
	 * Names of the teams with the given ids, for showing choices.
	 *
	 * @param array $ids Team ids; anything that is no longer a team (or is an old team name) is shown as it is.
	 * @return string[]
	 */
	public static function labels( array $ids ) {
		$choices = self::choices();
		$labels  = array();
		foreach ( $ids as $id ) {
			$labels[] = is_int( $id ) && isset( $choices[ $id ] ) ? $choices[ $id ] : (string) $id;
		}
		return $labels;
	}

	/**
	 * Turn a stored list of teams into ids. Earlier versions stored team names:
	 * a name that matches a team becomes its id, and one that does not is kept as it is.
	 *
	 * @param array $stored Stored list.
	 * @return array Ids (int), and any names that matched no team.
	 */
	public static function normalise_ids( array $stored ) {
		$by_name = null;
		$out     = array();

		foreach ( $stored as $item ) {
			if ( is_int( $item ) || ( is_string( $item ) && ctype_digit( $item ) ) ) {
				$out[] = (int) $item;
				continue;
			}
			if ( null === $by_name ) {
				$by_name = array_flip( array_map( 'strtolower', self::choices() ) );
			}
			$key   = strtolower( (string) $item );
			$out[] = isset( $by_name[ $key ] ) ? (int) $by_name[ $key ] : (string) $item;
		}
		return array_values( array_unique( $out, SORT_REGULAR ) );
	}

	/**
	 * Create a team for each team name on the Club Teams list that has none yet,
	 * linked to its entries on that list.
	 *
	 * @return int How many teams were created.
	 */
	public static function create_missing_from_club_teams() {
		$existing = array_map( 'strtolower', self::choices() );
		$names    = array();

		foreach ( Chess_Army_Knife_Settings::get_club_teams() as $club_team ) {
			$name = trim( (string) $club_team['team'] );
			if ( '' !== $name && ! in_array( strtolower( $name ), $existing, true ) ) {
				$names[ $name ][] = self::season_key( $club_team );
			}
		}

		$created = 0;
		foreach ( $names as $name => $keys ) {
			$id       = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $name,
					'meta_input'  => array( self::META_SEASONS => $keys ),
				)
			);
			$created += $id ? 1 : 0;
		}
		return $created;
	}

	/* -------------------------------------------------------------
	 * Squad
	 * ------------------------------------------------------------- */

	/**
	 * The people in a team's squad.
	 *
	 * @param int $team_id Team id.
	 * @return int[] Member ids.
	 */
	public static function squad( $team_id ) {
		global $wpdb;

		$table = self::squad_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT person_id FROM {$table} WHERE team_id = %d ORDER BY person_id ASC", (int) $team_id ) ) );
	}

	/**
	 * Replace a team's squad. Only people the club holds a record of can be in it.
	 *
	 * @param int   $team_id    Team id.
	 * @param int[] $person_ids Member ids.
	 */
	public static function set_squad( $team_id, array $person_ids ) {
		global $wpdb;

		$table = self::squad_table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete( $table, array( 'team_id' => (int) $team_id ), array( '%d' ) );
		foreach ( array_unique( array_map( 'absint', $person_ids ) ) as $person_id ) {
			if ( $person_id && Chess_Army_Knife_Membership_Store::get_member( $person_id ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table; the table name is internal.
				$wpdb->insert(
					$table,
					array(
						'team_id'   => (int) $team_id,
						'person_id' => $person_id,
						'added_at'  => $now,
					)
				);
			}
		}
	}

	/**
	 * The teams a person is in the squad of, or captains.
	 *
	 * @param int $person_id Member id.
	 * @return array[] Each { id, name, captain }.
	 */
	public static function teams_of_person( $person_id ) {
		global $wpdb;

		$table = self::squad_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$in_squad = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT team_id FROM {$table} WHERE person_id = %d", (int) $person_id ) ) );
		$teams    = array();

		foreach ( self::all() as $team ) {
			$captain = (int) $person_id === $team['captain_id'];
			if ( $captain || in_array( $team['id'], $in_squad, true ) ) {
				$teams[] = array(
					'id'      => $team['id'],
					'name'    => $team['name'],
					'captain' => $captain,
				);
			}
		}
		return $teams;
	}

	/**
	 * Take a person out of every squad and captaincy (when their record is erased).
	 *
	 * @param int $person_id Member id.
	 */
	public static function remove_person( $person_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$wpdb->delete( self::squad_table(), array( 'person_id' => (int) $person_id ), array( '%d' ) );
		delete_metadata( 'post', 0, self::META_CAPTAIN, (int) $person_id, true );
	}

	/**
	 * Forget a team's squad when the team is deleted.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public static function forget_team( $post_id ) {
		global $wpdb;

		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$wpdb->delete( self::squad_table(), array( 'team_id' => (int) $post_id ), array( '%d' ) );
		}
	}
}

Chess_Army_Knife_Teams::init();
