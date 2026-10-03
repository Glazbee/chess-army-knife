<?php
/**
 * The club's teams: a permanent team such as "Club A", with the seasons it has
 * played beneath it.
 *
 * A team is a non-public post of type chess_army_team (17 characters; WordPress
 * allows 20). Its captain and the seasons it plays in are post meta; a
 * season is one of the team's league entries (LMS organisation, event and, if
 * it differs from the team's name, the name the LMS knows it by). The squad is a person-to-team link in its own small table. The captain and
 * the squad are personal data: they are for people with the membership
 * permission, are part of a person's data export, and are removed when the
 * person is erased. Members choose their WhatsApp groups by team id, so
 * renaming a team loses nobody's choice.
 *
 * Editing teams needs its own permission, chess_army_manage_teams, which shows
 * nothing about members beyond the squads of the teams a user can see. Anyone
 * with the membership permission has it too, and is the only one who can
 * choose who is in a squad or captains it.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Teams {

	const POST_TYPE  = 'chess_army_team';
	const CAPABILITY = 'chess_army_manage_teams';

	/** Set once the old Club Teams list has been moved onto the teams. */
	const MIGRATED_OPTION = 'Chess_Army_Knife_club_teams_migrated';
	const LEGACY_OPTION   = 'Chess_Army_Knife_club_teams';

	const META_CAPTAIN_NAME = '_chess_army_team_captain_name'; // The name of a captain who is not a member, used only while no member is the captain.
	const META_CAPTAIN      = '_chess_army_team_captain';
	const META_SEASONS      = '_chess_army_team_seasons'; // Before league entries moved onto the team: keys of Club Teams entries.
	const META_LEAGUES      = '_chess_army_team_leagues';
	const META_COLOUR       = '_chess_army_team_colour';
	const META_GROUP        = '_chess_army_team_group'; // Id of the Group (see Chess_Army_Knife_Team_Groups) the team is listed under; set from the group's screen.
	const META_GROUP_POS    = '_chess_army_team_group_pos'; // The team's place within its group, from 0.
	const META_TAG          = '_chess_army_team_tag'; // The event tag given to the team's imported fixtures.
	const META_WHATSAPP     = '_chess_army_team_whatsapp'; // The invite link of the team's WhatsApp group; only shown to members who agreed to WhatsApp and are in the squad.

	/**
	 * Hook up registration and cleanup.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'forget_team' ) );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 20 );
		add_filter( 'user_has_cap', array( __CLASS__, 'officers_manage_teams' ) );

		// The list is kept for the request (an import looks teams and clubs up for every fixture) and forgotten when one changes.
		foreach ( array( 'save_post', 'before_delete_post', 'wp_trash_post', 'untrashed_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_memo_for_post' ) );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_memo_for_meta' ), 10, 2 );
		}   }

	/**
	 * Whether the current user may edit teams and see every team.
	 *
	 * @return bool
	 */
	public static function user_can_manage() {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Anyone who manages members may manage teams too: they can already see
	 * every member. The team permission on its own never shows member details.
	 *
	 * @param array $allcaps Permissions of the user being checked.
	 * @return array
	 */
	public static function officers_manage_teams( $allcaps ) {
		if ( ! empty( $allcaps[ Chess_Army_Knife_Memberships::CAPABILITY ] ) ) {
			$allcaps[ self::CAPABILITY ] = true;
		}
		return $allcaps;
	}

	/**
	 * Register the team post type.
	 */
	public static function register() {
		wp_cache_add_non_persistent_groups( self::MEMO_GROUP ); // Kept for the request only.
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
				'show_in_menu' => false, // Listed in the plugin's menu (see Chess_Army_Knife_Menu).
				'show_in_rest' => false, // Classic editing screen: the details are plain fields.
				'supports'     => array( 'title', 'excerpt', 'page-attributes' ), // The excerpt is the description shown publicly.
				'capabilities' => Chess_Army_Knife_Memberships::post_capabilities( self::CAPABILITY ),
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
			last_played DATE NULL,
			PRIMARY KEY  (team_id,person_id),
			KEY person_id (person_id)
			) {$charset};"
		);
	}

	/* -------------------------------------------------------------
	 * Teams
	 * ------------------------------------------------------------- */

	/** Object cache group holding the list from all() for the request. */
	const MEMO_GROUP = 'chess_army_knife_memo';

	/**
	 * Forget the kept list, because a post of this type changed.
	 *
	 * @param int $post_id Post id.
	 */
	public static function flush_memo_for_post( $post_id ) {
		if ( in_array( get_post_type( $post_id ), array( self::POST_TYPE, Chess_Army_Knife_Team_Groups::POST_TYPE ), true ) ) {
			wp_cache_delete( 'teams', self::MEMO_GROUP );
		}
	}

	/**
	 * Forget the kept list, because a post of this type had a meta value changed.
	 *
	 * @param int $meta_id Meta id.
	 * @param int $post_id Post id.
	 */
	public static function flush_memo_for_meta( $meta_id, $post_id ) {
		self::flush_memo_for_post( $post_id );
	}

	/**
	 * The teams on offer, in their page order.
	 *
	 * @return array[] Each { id, name, description, captain_id, captain_name, colour, tag, group_id, group, group_blurb, group_order, group_pos, leagues, seasons }; leagues are { org, event, name } and seasons are their keys.
	 */
	public static function all() {
		$kept = wp_cache_get( 'teams', self::MEMO_GROUP );
		if ( is_array( $kept ) ) {
			return $kept;
		}

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
		$teams = array_map( array( __CLASS__, 'team_data' ), $posts );
		wp_cache_set( 'teams', $teams, self::MEMO_GROUP );

		return $teams;
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
		$name    = get_the_title( $post );
		$leagues = self::clean_leagues( get_post_meta( $post->ID, self::META_LEAGUES, true ) );
		$group   = Chess_Army_Knife_Team_Groups::get( (int) get_post_meta( $post->ID, self::META_GROUP, true ) );
		return array(
			'id'           => (int) $post->ID,
			'name'         => $name,
			'description'  => (string) $post->post_excerpt,
			'captain_id'   => (int) get_post_meta( $post->ID, self::META_CAPTAIN, true ),
			'captain_name' => trim( (string) get_post_meta( $post->ID, self::META_CAPTAIN_NAME, true ) ),
			'colour'       => (string) get_post_meta( $post->ID, self::META_COLOUR, true ),
			'tag'          => trim( (string) get_post_meta( $post->ID, self::META_TAG, true ) ),
			'whatsapp'     => (string) get_post_meta( $post->ID, self::META_WHATSAPP, true ),
			'group_id'     => $group ? $group['id'] : 0,
			'group'        => $group ? $group['name'] : '',
			'group_blurb'  => $group ? $group['blurb'] : '',
			'group_order'  => $group ? $group['order'] : 0,
			'group_pos'    => $group ? (int) get_post_meta( $post->ID, self::META_GROUP_POS, true ) : 0,
			'leagues'      => $leagues,
			'seasons'      => array_map(
				function ( $league ) use ( $name ) {
					return self::season_key( self::league_entry( $league, $name ) );
				},
				$leagues
			),
		);
	}

	/**
	 * A team's heading from a format: {team} is its name and {league} the first
	 * league it plays in, so "{league} - {team}" gives "Division 1 - Wotton Hall Lions".
	 * Separators left dangling by a team with no league are trimmed.
	 *
	 * @param array  $team   Team from all().
	 * @param string $format Format; empty means just the name.
	 * @return string
	 */
	public static function display_name( array $team, $format ) {
		$format = trim( (string) $format );
		if ( '' === $format ) {
			return $team['name'];
		}
		$league = self::division_of( $team );
		$name   = trim( str_replace( array( '{league}', '{team}' ), array( $league, $team['name'] ), $format ) );
		$name   = trim( $name, " \t-\xE2\x80\x93\xE2\x80\x94:|" );
		return '' !== $name ? $name : $team['name'];
	}

	/**
	 * The division a team plays in: the event of its first league.
	 *
	 * @param array $team Team from all().
	 * @return string Empty if the team has no league.
	 */
	public static function division_of( array $team ) {
		return $team['leagues'] ? (string) $team['leagues'][0]['event'] : '';
	}

	/**
	 * Teams grouped by division or by their Group. Divisions are in the order each first appears.
	 * Groups follow the Groups page order, after the teams that have no group. Teams with
	 * neither form a group of their own with an empty label.
	 *
	 * @param array[] $teams Teams from all().
	 * @param string  $by    'division' or 'group'.
	 * @return array[] Each { label, blurb, teams }.
	 */
	public static function group_teams( array $teams, $by ) {
		$groups = array();
		foreach ( $teams as $team ) {
			$label = 'group' === $by ? (string) $team['group'] : self::division_of( $team );
			if ( ! isset( $groups[ $label ] ) ) {
				$groups[ $label ] = array(
					'label' => $label,
					'blurb' => 'group' === $by ? (string) $team['group_blurb'] : '',
					'order' => 'group' === $by && '' !== $label ? (int) $team['group_order'] : -1,
					'seen'  => count( $groups ),
					'teams' => array(),
				);
			}
			$groups[ $label ]['teams'][] = $team;
		}

		if ( 'group' === $by ) {
			usort(
				$groups,
				function ( $a, $b ) {
					return array( $a['order'], $a['seen'] ) <=> array( $b['order'], $b['seen'] );
				}
			);
			// Within a group, teams are in the order set on the group's screen.
			foreach ( $groups as &$group ) {
				$position = array_map(
					function ( $index, $team ) {
						return array( (int) $team['group_pos'], $index );
					},
					array_keys( $group['teams'] ),
					$group['teams']
				);
				array_multisort( $position, $group['teams'] );
			}
			unset( $group );
		}

		return array_map(
			function ( $group ) {
				unset( $group['order'], $group['seen'] );
				return $group;
			},
			array_values( $groups )
		);
	}

	/**
	 * The players to show publicly for a team: its squad, with the captain marked.
	 *
	 * @param array  $team Team from all().
	 * @param string $sort 'surname' (default) or 'rating'.
	 * @return array { players: array[] of { name, nickname, captain, rating }, non_playing_captain: array { name, nickname } or empty }
	 */
	public static function public_roster( array $team, $sort = 'surname' ) {
		$squad = self::squad( $team['id'] );
		$ids   = $team['captain_id'] ? array_merge( $squad, array( $team['captain_id'] ) ) : $squad;
		return self::build_roster( Chess_Army_Knife_Membership_Store::get_members_by_ids( $ids ), $squad, $team['captain_id'], $sort, $team['captain_name'] );
	}

	/**
	 * Sort members into the squad's players and a captain outside the squad.
	 *
	 * @param array[] $members    Member rows (id, name, and ecf_rating and manual_rating if known).
	 * @param int[]   $squad      Member ids in the squad.
	 * @param int     $captain_id Captain's member id, or 0.
	 * @param string  $sort       'surname' (default) or 'rating' (highest first).
	 * @param string  $captain_name The name of a captain who is not a member; used only when there is no member captain.
	 * @return array See public_roster().
	 */
	public static function build_roster( array $members, array $squad, $captain_id, $sort = 'surname', $captain_name = '' ) {
		$roster = array(
			'players'             => array(),
			'non_playing_captain' => array(),
		);
		foreach ( $members as $member ) {
			$is_captain = (int) $member['id'] === (int) $captain_id;
			if ( in_array( (int) $member['id'], $squad, true ) ) {
				$roster['players'][] = array(
					'name'     => $member['name'],
					'nickname' => isset( $member['nickname'] ) ? $member['nickname'] : '',
					'captain'  => $is_captain,
					'rating'   => self::rating_of( $member ),
				);
			} elseif ( $is_captain ) {
				$roster['non_playing_captain'] = array(
					'name'     => $member['name'],
					'nickname' => isset( $member['nickname'] ) ? $member['nickname'] : '',
				);
			}
		}
		if ( ! $captain_id && '' !== $captain_name ) {
			$roster['non_playing_captain'] = array(
				'name'     => $captain_name,
				'nickname' => '',
			);
		}
		$roster['players'] = self::sort_players( $roster['players'], $sort );
		return $roster;
	}

	/**
	 * A member's rating: the ECF one, else the manual one, else 0.
	 *
	 * @param array $member Member row.
	 * @return int
	 */
	protected static function rating_of( array $member ) {
		if ( isset( $member['ecf_rating'] ) ) {
			return (int) $member['ecf_rating'];
		}
		return isset( $member['manual_rating'] ) ? (int) $member['manual_rating'] : 0;
	}

	/**
	 * Players by surname (the last word of the name), or by rating with the highest first. Ties go to the surname, then the full name.
	 *
	 * @param array[] $players Each { name, rating, ... }.
	 * @param string  $sort    'surname' or 'rating'.
	 * @return array[]
	 */
	public static function sort_players( array $players, $sort ) {
		usort(
			$players,
			function ( $a, $b ) use ( $sort ) {
				if ( 'rating' === $sort && $a['rating'] !== $b['rating'] ) {
					return $b['rating'] <=> $a['rating'];
				}
				$by_surname = strcasecmp( Chess_Army_Knife_Names::surname( $a['name'] ), Chess_Army_Knife_Names::surname( $b['name'] ) );
				return 0 !== $by_surname ? $by_surname : strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $players;
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
	 * The identity of a season: a league entry.
	 *
	 * @param array $club_team League entry { org, event, team }.
	 * @return string
	 */
	public static function season_key( array $club_team ) {
		return $club_team['org'] . '|' . $club_team['event'] . '|' . $club_team['team'];
	}

	/**
	 * The team that plays a season (a league entry).
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
	 * A league entry as the rest of the plugin reads it: the name is the one the
	 * LMS knows the team by, which is the team's own name unless told otherwise.
	 *
	 * @param array  $league    League { org, event, name }.
	 * @param string $team_name The team's name.
	 * @return array { org, event, team }
	 */
	protected static function league_entry( array $league, $team_name ) {
		return array(
			'org'   => $league['org'],
			'event' => $league['event'],
			'team'  => '' !== $league['name'] ? $league['name'] : $team_name,
		);
	}

	/**
	 * The league entries a team plays in this season or has played in.
	 *
	 * @param array $team Team from all().
	 * @return array[] Each { org, event, team }.
	 */
	public static function seasons_of( array $team ) {
		return array_map(
			function ( $league ) use ( $team ) {
				return self::league_entry( $league, $team['name'] );
			},
			$team['leagues']
		);
	}

	/**
	 * Every league entry of every team: what the fixtures, the league table and
	 * the carousel read to know which teams are the club's.
	 *
	 * @return array[] Each { org, event, team, team_id }.
	 */
	public static function league_entries() {
		$entries = array();
		foreach ( self::all() as $team ) {
			foreach ( self::seasons_of( $team ) as $entry ) {
				$entries[] = $entry + array( 'team_id' => $team['id'] );
			}
		}
		return $entries;
	}

	/**
	 * Clean a submitted or stored list of league entries. An entry needs an
	 * LMS organisation (digits) and an event; anything else is dropped, and so
	 * is a repeat of an entry already listed.
	 *
	 * @param mixed $raw List of { org, event, name }.
	 * @return array[] Each { org, event, name }.
	 */
	public static function clean_leagues( $raw ) {
		$leagues = array();

		foreach ( is_array( $raw ) ? $raw : array() as $league ) {
			if ( ! is_array( $league ) ) {
				continue;
			}
			$org   = isset( $league['org'] ) ? preg_replace( '/[^0-9]/', '', sanitize_text_field( (string) $league['org'] ) ) : '';
			$event = isset( $league['event'] ) ? sanitize_text_field( (string) $league['event'] ) : '';
			$name  = isset( $league['name'] ) ? sanitize_text_field( (string) $league['name'] ) : '';
			if ( '' === $org || '' === $event ) {
				continue;
			}
			$leagues[ $org . '|' . strtolower( $event ) . '|' . strtolower( $name ) ] = array(
				'org'   => $org,
				'event' => $event,
				'name'  => $name,
			);
		}
		return array_values( $leagues );
	}

	/**
	 * Give teams the league entries listed, creating a team for a name that has
	 * none yet. A team is found by its name, ignoring case.
	 *
	 * @param array[] $entries Each { org, event, team }.
	 * @return int How many teams were created.
	 */
	public static function assign_league_entries( array $entries ) {
		$teams   = array();
		$created = 0;
		foreach ( self::all() as $team ) {
			$teams[ strtolower( $team['name'] ) ] = $team;
		}

		foreach ( $entries as $entry ) {
			$name = trim( (string) $entry['team'] );
			$key  = strtolower( $name );
			if ( '' === $name ) {
				continue;
			}

			if ( ! isset( $teams[ $key ] ) ) {
				$id = wp_insert_post(
					array(
						'post_type'   => self::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => $name,
					)
				);
				if ( ! $id ) {
					continue;
				}
				++$created;
				$teams[ $key ] = self::get( $id );
			}

			self::add_league( $teams[ $key ]['id'], $entry['org'], $entry['event'], '' );
			$teams[ $key ] = self::get( $teams[ $key ]['id'] );
		}
		return $created;
	}

	/**
	 * Add a league entry to a team, unless it has it.
	 *
	 * @param int    $team_id Team id.
	 * @param string $org     LMS organisation id.
	 * @param string $event   Event or division name.
	 * @param string $name    The team's name in the LMS, or '' if it is the team's own name.
	 */
	protected static function add_league( $team_id, $org, $event, $name ) {
		$leagues   = self::clean_leagues( get_post_meta( $team_id, self::META_LEAGUES, true ) );
		$leagues[] = array(
			'org'   => $org,
			'event' => $event,
			'name'  => $name,
		);
		update_post_meta( $team_id, self::META_LEAGUES, self::clean_leagues( $leagues ) );
	}

	/**
	 * Move the old Club Teams list (and the free-text field before it) onto the
	 * teams, once. A team that was ticked for an entry keeps it, under the name
	 * the LMS knows it by; any other entry goes to the team of that name, which
	 * is created if the club has none.
	 */
	public static function maybe_migrate() {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		$entries = self::legacy_entries();
		$rest    = array();

		foreach ( $entries as $entry ) {
			$key   = self::season_key( $entry );
			$owner = null;
			foreach ( self::all() as $team ) {
				$old = get_post_meta( $team['id'], self::META_SEASONS, true );
				if ( is_array( $old ) && in_array( $key, $old, true ) ) {
					$owner = $team;
					break;
				}
			}

			if ( null !== $owner ) {
				// The LMS name is only kept when it differs from the team's own.
				$lms_name = 0 === strcasecmp( $entry['team'], $owner['name'] ) ? '' : $entry['team'];
				self::add_league( $owner['id'], $entry['org'], $entry['event'], $lms_name );
			} else {
				$rest[] = $entry;
			}
		}
		self::assign_league_entries( $rest );

		foreach ( self::all() as $team ) {
			delete_post_meta( $team['id'], self::META_SEASONS );
		}
		delete_option( self::LEGACY_OPTION );
		update_option( self::MIGRATED_OPTION, 1 );
	}

	/**
	 * The entries of the old Club Teams list: the structured list if it was ever
	 * saved, otherwise the older free-text "org | event | team" lines.
	 *
	 * @return array[] Each { org, event, team }.
	 */
	protected static function legacy_entries() {
		$saved   = get_option( self::LEGACY_OPTION, null );
		$entries = array();

		if ( null === $saved ) {
			$settings = get_option( Chess_Army_Knife_Settings::OPTION, array() );
			$raw      = is_array( $settings ) && isset( $settings['club_teams'] ) ? $settings['club_teams'] : '';
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
				$parts = array_map( 'trim', explode( '|', $line ) );
				if ( count( $parts ) >= 3 ) {
					$saved[] = array(
						'org'   => $parts[0],
						'event' => $parts[1],
						'team'  => $parts[2],
					);
				}
			}
		}

		foreach ( (array) $saved as $entry ) {
			if ( isset( $entry['org'], $entry['event'], $entry['team'] ) && '' !== trim( $entry['org'] . $entry['event'] . $entry['team'] ) ) {
				$entries[] = array(
					'org'   => $entry['org'],
					'event' => $entry['event'],
					'team'  => $entry['team'],
				);
			}
		}
		return $entries;
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
	 * Note the latest game each person has played for a team, from a league import.
	 *
	 * @param int      $team_id Team id.
	 * @param string[] $dates   Latest date played, "Y-m-d", by member id. Only a later date than the one held is kept.
	 */
	public static function record_appearances( $team_id, array $dates ) {
		global $wpdb;

		$table = self::squad_table();
		foreach ( $dates as $person_id => $date ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_played = %s WHERE team_id = %d AND person_id = %d AND ( last_played IS NULL OR last_played < %s )", $date, (int) $team_id, (int) $person_id, $date ) );
		}
	}

	/**
	 * A team's squad with when each person joined it and last played for it.
	 *
	 * @param int $team_id Team id.
	 * @return array[] Each { person_id, added, last_played }; added is "Y-m-d" and last_played is "Y-m-d" or '' if never.
	 */
	public static function squad_rows( $team_id ) {
		global $wpdb;

		$table = self::squad_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT person_id, added_at, last_played FROM {$table} WHERE team_id = %d ORDER BY person_id ASC", (int) $team_id ), ARRAY_A );

		return array_map(
			function ( $row ) {
				return array(
					'person_id'   => (int) $row['person_id'],
					'added'       => substr( (string) $row['added_at'], 0, 10 ),
					'last_played' => null === $row['last_played'] ? '' : (string) $row['last_played'],
				);
			},
			$rows
		);
	}

	/**
	 * Who in a squad has not played for the team since a date. Someone who has never played counts from
	 * the day they were added, so a person added yesterday is not listed.
	 *
	 * @param array[] $rows   Rows from squad_rows().
	 * @param string  $cutoff "Y-m-d": those last seen before this are listed.
	 * @return array[] The same rows with seen (the date counted from) added, longest away first.
	 */
	public static function not_seen_since( array $rows, $cutoff ) {
		$stale = array();
		foreach ( $rows as $row ) {
			$row['seen'] = '' !== $row['last_played'] ? $row['last_played'] : $row['added'];
			if ( $row['seen'] < $cutoff ) {
				$stale[] = $row;
			}
		}

		usort(
			$stale,
			function ( $a, $b ) {
				return strcmp( $a['seen'], $b['seen'] );
			}
		);

		return $stale;
	}

	/**
	 * A WhatsApp group invite link: only an https://chat.whatsapp.com/ address is kept.
	 *
	 * @param string $url What was entered.
	 * @return string The link, or '' if it is not one.
	 */
	public static function clean_whatsapp_link( $url ) {
		$url = trim( (string) $url );

		return preg_match( '#^https://chat\.whatsapp\.com/[A-Za-z0-9]{10,40}/?$#', $url ) ? $url : '';
	}

	/**
	 * The WhatsApp groups a person may be shown a link to: those of the teams whose squad they are in (or
	 * that they captain), and only if they agreed to WhatsApp. Anyone with a link can join the group, so
	 * it is shown nowhere else.
	 *
	 * @param array $person Member row.
	 * @return array[] Each { name, link }.
	 */
	public static function whatsapp_groups_for_person( array $person ) {
		if ( '' === (string) $person['whatsapp_consent_at'] ) {
			return array();
		}

		$groups = array();
		foreach ( self::teams_of_person( $person['id'] ) as $team ) {
			if ( '' !== $team['whatsapp'] ) {
				$groups[] = array(
					'name' => $team['name'],
					'link' => $team['whatsapp'],
				);
			}
		}

		return $groups;
	}

	/**
	 * Replace a team's squad. Only people the club holds a record of can be in it.
	 *
	 * @param int   $team_id    Team id.
	 * @param int[] $person_ids Member ids.
	 */
	public static function set_squad( $team_id, array $person_ids ) {
		$wanted = array_filter( array_unique( array_map( 'absint', $person_ids ) ) );

		// Only the changes are made, so a person who stays keeps when they were added and last played.
		self::remove_from_squad( $team_id, array_diff( self::squad( $team_id ), $wanted ) );
		self::add_to_squad( $team_id, $wanted );
	}

	/**
	 * Put people in a team's squad, leaving everyone already in it there.
	 *
	 * @param int   $team_id    Team id.
	 * @param int[] $person_ids Member ids.
	 */
	public static function add_to_squad( $team_id, array $person_ids ) {
		global $wpdb;

		$table = self::squad_table();
		$now   = current_time( 'mysql', true );
		$have  = self::squad( $team_id );

		foreach ( array_unique( array_map( 'absint', $person_ids ) ) as $person_id ) {
			if ( $person_id && ! in_array( $person_id, $have, true ) && Chess_Army_Knife_Membership_Store::get_member( $person_id ) ) {
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
	 * Take people out of a team's squad, leaving the rest of it as it is.
	 *
	 * @param int   $team_id    Team id.
	 * @param int[] $person_ids Member ids.
	 */
	public static function remove_from_squad( $team_id, array $person_ids ) {
		global $wpdb;

		foreach ( array_unique( array_map( 'absint', $person_ids ) ) as $person_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
			$wpdb->delete(
				self::squad_table(),
				array(
					'team_id'   => (int) $team_id,
					'person_id' => $person_id,
				),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Set which squads a person is in: any number of teams, or none. Who
	 * captains a team is not changed.
	 *
	 * @param int   $person_id Member id.
	 * @param int[] $team_ids  Team ids; anything that is not a team is ignored.
	 */
	public static function set_teams_of_person( $person_id, array $team_ids ) {
		$wanted = array_map( 'absint', $team_ids );

		foreach ( array_keys( self::choices() ) as $team_id ) {
			if ( in_array( $team_id, $wanted, true ) ) {
				self::add_to_squad( $team_id, array( $person_id ) );
			} else {
				self::remove_from_squad( $team_id, array( $person_id ) );
			}
		}
	}

	/**
	 * The teams whose squad a person is in (not the ones they only captain).
	 *
	 * @param int $person_id Member id.
	 * @return int[] Team ids.
	 */
	public static function squad_team_ids_of_person( $person_id ) {
		global $wpdb;

		$table = self::squad_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal and dynamic values are prepared.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT team_id FROM {$table} WHERE person_id = %d", (int) $person_id ) ) );
	}

	/**
	 * Which squads each person is in, for showing a column in the member list.
	 *
	 * @return array Team names by person id, each a list.
	 */
	public static function squad_names_by_person() {
		global $wpdb;

		$names = self::choices();
		$table = self::squad_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned custom table; the table name is internal.
		$rows   = (array) $wpdb->get_results( "SELECT person_id, team_id FROM {$table}", ARRAY_A );
		$people = array();

		foreach ( $rows as $row ) {
			if ( isset( $names[ (int) $row['team_id'] ] ) ) {
				$people[ (int) $row['person_id'] ][] = $names[ (int) $row['team_id'] ];
			}
		}
		return $people;
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
					'id'       => $team['id'],
					'name'     => $team['name'],
					'captain'  => $captain,
					'whatsapp' => $team['whatsapp'],
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
