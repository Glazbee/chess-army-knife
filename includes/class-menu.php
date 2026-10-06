<?php
/**
 * The plugin's one admin menu, Chess Army Knife, and its Overview screen.
 *
 * Every screen is listed for everyone who can use the admin area. A screen a
 * user may not use explains what permission it needs, instead of being hidden
 * (see Chess_Army_Knife_Access). The list of screens below is the one place
 * that knows them all, so the menu and the Overview cannot disagree.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Menu {

	const SLUG = 'chess-army-knife';

	/**
	 * Hook up the menu, and the notice for the post type screens WordPress draws.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_accessibility_styles' ) );
		foreach ( array( 'edit.php', 'post.php', 'post-new.php' ) as $screen ) {
			add_action( 'load-' . $screen, array( __CLASS__, 'maybe_deny_post_screen' ) );
		}
	}

	/**
	 * Whether the screen being shown is one of the plugin's: its own pages, or the lists and
	 * editors of the post types it adds.
	 *
	 * @return bool
	 */
	public static function is_plugin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		if ( false !== strpos( (string) $screen->id, self::SLUG ) ) {
			return true;
		}
		return in_array(
			(string) $screen->post_type,
			array(
				Chess_Army_Knife_Teams::POST_TYPE,
				Chess_Army_Knife_Team_Groups::POST_TYPE,
				Chess_Army_Knife_Events::POST_TYPE,
				Chess_Army_Knife_Clubs::POST_TYPE,
				Chess_Army_Knife_Memberships::POST_TYPE,
				Chess_Army_Knife_Announcements::POST_TYPE,
			),
			true
		);
	}

	/**
	 * Mark the plugin's screens, so the accessibility styles reach only them.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		return self::is_plugin_screen() ? $classes . ' cak-admin-screen' : $classes;
	}

	/**
	 * Load the AAA colour, size and focus overrides on the plugin's screens.
	 */
	public static function enqueue_accessibility_styles() {
		if ( self::is_plugin_screen() ) {
			wp_enqueue_style( 'chess-army-knife-admin-accessibility', Chess_Army_Knife_URL . 'assets/admin-accessibility.css', array(), Chess_Army_Knife_VERSION );
		}
	}

	/**
	 * The permission needed to see the menu at all, which is to be signed in to
	 * the admin area: what each screen needs is explained on the screen.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filter the permission needed to see the Chess Army Knife menu. Raise it
		 * (for example to edit_posts) to hide the menu from ordinary members.
		 *
		 * @param string $capability Permission, read by default.
		 */
		return (string) apply_filters( 'Chess_Army_Knife_menu_capability', 'read' );
	}

	/**
	 * Every screen, in menu order.
	 *
	 * @return array[] Each {
	 *     @type string        $key         Short name, used to link to the screen.
	 *     @type string        $group       Heading on the Overview.
	 *     @type string        $title       Name of the screen.
	 *     @type string        $description What it is for.
	 *     @type string        $slug        Page slug, or a post type list such as edit.php?post_type=x.
	 *     @type callable|null $callback    Draws the page; null for a post type list WordPress draws.
	 *     @type string        $kind        What it needs, a key of Chess_Army_Knife_Access::requirements().
	 *     @type callable      $can         Whether the current user may use it.
	 *     @type string        $post_type   For a post type list, the post type.
	 *     @type bool          $unlisted    True for a screen opened from another's tabs, so it has no menu item.
	 * }
	 */
	public static function areas() {
		$members_group = __( 'Members', 'chess-army-knife' );
		$teams_group   = __( 'Teams', 'chess-army-knife' );
		$club_group    = __( 'Club', 'chess-army-knife' );
		$setup_group   = __( 'Setup', 'chess-army-knife' );

		$can_members = function () {
			return Chess_Army_Knife_Memberships::user_can_manage();
		};
		$can_teams   = function () {
			return Chess_Army_Knife_Teams::user_can_manage();
		};

		$captain_only = ! $can_teams() && Chess_Army_Knife_Captains::user_can_select();

		return array(
			array(
				'group'       => $members_group,
				'title'       => __( 'Members', 'chess-army-knife' ),
				'description' => __( 'Members and applications: approve, edit, bulk actions and CSV export, with Checks, Membership types, Seasons and Renewals tabs.', 'chess-army-knife' ),
				'key'         => 'members',
				'slug'        => Chess_Army_Knife_Memberships::MENU_SLUG,
				'callback'    => array( 'Chess_Army_Knife_Members_Page', 'render_page' ),
				'kind'        => 'members',
				'can'         => $can_members,
			),
			array(
				'group'       => $members_group,
				'title'       => __( 'Dashboard', 'chess-army-knife' ),
				'description' => __( 'The club at a glance: totals only, no names.', 'chess-army-knife' ),
				'key'         => 'dashboard',
				'slug'        => Chess_Army_Knife_Dashboard_Page::SLUG,
				'callback'    => array( 'Chess_Army_Knife_Dashboard_Page', 'render_page' ),
				'kind'        => 'members',
				'can'         => $can_members,
			),
			array(
				'group'       => $members_group,
				'title'       => __( 'Member Checks', 'chess-army-knife' ),
				'description' => __( 'Missing or invalid ECF codes, incomplete applications, expired memberships and duplicates.', 'chess-army-knife' ),
				'key'         => 'member_checks',
				'slug'        => Chess_Army_Knife_Member_Checks_Page::SLUG,
				'callback'    => array( 'Chess_Army_Knife_Member_Checks_Page', 'render_page' ),
				'kind'        => 'members',
				'can'         => $can_members,
				'unlisted'    => true, // A tab of Members.
			),
			array(
				'group'       => $members_group,
				'title'       => __( 'Seasons', 'chess-army-knife' ),
				'description' => __( 'Start a new season, when every member pays again, and download each season\'s payments for the treasurer.', 'chess-army-knife' ),
				'key'         => 'seasons',
				'slug'        => Chess_Army_Knife_Seasons_Page::SLUG,
				'callback'    => array( 'Chess_Army_Knife_Seasons_Page', 'render_page' ),
				'kind'        => 'members',
				'can'         => $can_members,
				'unlisted'    => true, // A tab of Members.
			),
			array(
				'group'       => $members_group,
				'title'       => __( 'Renewals', 'chess-army-knife' ),
				'description' => __( 'Who the next renewal reminders will go to.', 'chess-army-knife' ),
				'key'         => 'renewals',
				'slug'        => Chess_Army_Knife_Renewals_Page::SLUG,
				'callback'    => array( 'Chess_Army_Knife_Renewals_Page', 'render_page' ),
				'kind'        => 'members',
				'can'         => $can_members,
				'unlisted'    => true, // A tab of Members.
			),
			array(
				'group'       => $members_group,
				'title'       => __( 'Players from the LMS', 'chess-army-knife' ),
				'description' => __( 'Add the people who play for your teams, as found in the imported results, as members to confirm.', 'chess-army-knife' ),
				'key'         => 'lms_players',
				'slug'        => Chess_Army_Knife_LMS_Players::PAGE,
				'callback'    => array( 'Chess_Army_Knife_LMS_Players', 'render_page' ),
				'kind'        => 'members',
				'can'         => $can_members,
				'unlisted'    => true, // A tab of another screen.
			),
			array(
				'group'       => $members_group,
				'title'       => __( 'Do Not Record', 'chess-army-knife' ),
				'description' => __( 'People who asked to be deleted, so the plugin does not record them again by itself.', 'chess-army-knife' ),
				'key'         => 'do_not_record',
				'slug'        => Chess_Army_Knife_Do_Not_Record::PAGE,
				'callback'    => array( 'Chess_Army_Knife_Do_Not_Record', 'render_page' ),
				'kind'        => 'members',
				'can'         => $can_members,
				'unlisted'    => true, // A tab of another screen.
			),
			array(
				'group'       => $members_group,
				'title'       => __( 'Announcements', 'chess-army-knife' ),
				'description' => __( 'Messages to members, all of them or chosen teams.', 'chess-army-knife' ),
				'key'         => 'announcements',
				'slug'        => 'edit.php?post_type=' . Chess_Army_Knife_Announcements::POST_TYPE,
				'callback'    => null,
				'kind'        => 'members',
				'can'         => $can_members,
				'post_type'   => Chess_Army_Knife_Announcements::POST_TYPE,
			),
			array(
				'group'       => $members_group,
				'title'       => __( 'Membership Types', 'chess-army-knife' ),
				'description' => __( 'The memberships the club offers, with their prices and lengths.', 'chess-army-knife' ),
				'key'         => 'membership_types',
				'slug'        => 'edit.php?post_type=' . Chess_Army_Knife_Memberships::POST_TYPE,
				'callback'    => null,
				'kind'        => 'members',
				'can'         => $can_members,
				'unlisted'    => true, // A tab of Members.
				'post_type'   => Chess_Army_Knife_Memberships::POST_TYPE,
			),
			array(
				'group'       => $teams_group,
				'title'       => __( 'Teams', 'chess-army-knife' ),
				'description' => __( 'Each team\'s details and squad, its overview, and asking who can play and picking the line-up, with Groups and Leagues tabs.', 'chess-army-knife' ),
				'key'         => 'teams',
				// A captain without the team permission opens on the Overview tab; everyone else on the list of teams.
				'slug'        => $captain_only ? Chess_Army_Knife_Team_Overview::PAGE : 'edit.php?post_type=' . Chess_Army_Knife_Teams::POST_TYPE,
				'callback'    => $captain_only ? array( 'Chess_Army_Knife_Team_Overview', 'render_page' ) : null,
				'kind'        => 'teams',
				'can'         => function () use ( $can_teams ) {
					return $can_teams() || Chess_Army_Knife_Captains::user_can_select();
				},
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
			),
			array(
				'group'       => $teams_group,
				'title'       => __( 'Groups', 'chess-army-knife' ),
				'description' => __( 'Headings such as an association or "Internal teams" that teams are listed under: choose each group\'s teams and their order. A tab of Teams.', 'chess-army-knife' ),
				'key'         => 'team_groups',
				'slug'        => 'edit.php?post_type=' . Chess_Army_Knife_Team_Groups::POST_TYPE,
				'callback'    => null,
				'kind'        => 'teams',
				'can'         => $can_teams,
				'post_type'   => Chess_Army_Knife_Team_Groups::POST_TYPE,
				'unlisted'    => true, // A tab of Teams.
			),
			array(
				'group'       => $teams_group,
				'title'       => __( 'Leagues', 'chess-army-knife' ),
				'description' => __( 'Which of the club\'s teams play in which division of each LMS organisation, checked against the LMS. A tab of Teams.', 'chess-army-knife' ),
				'key'         => 'team_leagues',
				'slug'        => Chess_Army_Knife_Leagues_Page::PAGE,
				'callback'    => array( 'Chess_Army_Knife_Leagues_Page', 'render_page' ),
				'kind'        => 'teams',
				'can'         => $can_teams,
				'unlisted'    => true, // A tab of Teams.
			),
			array(
				'group'       => $teams_group,
				'title'       => __( 'Other clubs', 'chess-army-knife' ),
				'description' => __( 'Other clubs and where they play, so away fixtures get a venue, with a Sort clubs tab to say which team names in the LMS belong to one club.', 'chess-army-knife' ),
				'key'         => 'clubs',
				'slug'        => 'edit.php?post_type=' . Chess_Army_Knife_Clubs::POST_TYPE,
				'callback'    => null,
				'kind'        => 'teams',
				'can'         => $can_teams,
				'post_type'   => Chess_Army_Knife_Clubs::POST_TYPE,
			),
			array(
				'group'       => $teams_group,
				'title'       => __( 'Sort Clubs', 'chess-army-knife' ),
				'description' => __( 'Say which team names seen in the LMS belong to one club, and where it plays.', 'chess-army-knife' ),
				'key'         => 'sort_clubs',
				'slug'        => Chess_Army_Knife_Clubs::PAGE,
				'callback'    => array( 'Chess_Army_Knife_Clubs', 'render_page' ),
				'kind'        => 'teams',
				'can'         => $can_teams,
				'unlisted'    => true, // A tab of another screen.
			),
			array(
				'group'       => $teams_group,
				'title'       => __( 'Squad Review', 'chess-army-knife' ),
				'description' => __( 'See who in a squad has not played for the team lately, and tidy the squads.', 'chess-army-knife' ),
				'key'         => 'squad_review',
				'slug'        => Chess_Army_Knife_Squad_Review::PAGE,
				'callback'    => array( 'Chess_Army_Knife_Squad_Review', 'render_page' ),
				'kind'        => 'teams',
				'can'         => $can_teams,
				'unlisted'    => true, // A tab of another screen.
			),
			array(
				'group'       => $teams_group,
				'title'       => __( 'League Games', 'chess-army-knife' ),
				'description' => __( 'Fixtures brought in from the LMS, with board order and results, a search for the games a player has played in, and the import. A tab of Club Events.', 'chess-army-knife' ),
				'key'         => 'league_games',
				'slug'        => Chess_Army_Knife_League_Games::PAGE,
				'callback'    => array( 'Chess_Army_Knife_League_Games', 'render_page' ),
				'kind'        => 'teams',
				'can'         => $can_teams,
				'unlisted'    => true, // A tab of another screen.
			),
			array(
				'group'       => $club_group,
				'title'       => __( 'Officers', 'chess-army-knife' ),
				'description' => __( 'The club\'s officers: name the positions, say who holds them and see the history.', 'chess-army-knife' ),
				'key'         => 'officers',
				'slug'        => Chess_Army_Knife_Officers_Page::PAGE,
				'callback'    => array( 'Chess_Army_Knife_Officers_Page', 'render_page' ),
				'kind'        => 'members',
				'can'         => $can_members,
			),
			array(
				'group'       => $club_group,
				'title'       => __( 'Tournaments', 'chess-army-knife' ),
				'description' => __( 'Club tournaments, players and results.', 'chess-army-knife' ),
				'key'         => 'tournaments',
				'slug'        => Chess_Army_Knife_Tournaments_Page::SLUG,
				'callback'    => array( 'Chess_Army_Knife_Tournaments_Page', 'render_page' ),
				'kind'        => 'tournaments',
				'can'         => array( 'Chess_Army_Knife_Tournaments', 'user_can_manage' ),
			),
			array(
				'group'       => $club_group,
				'title'       => __( 'Club Events', 'chess-army-knife' ),
				'description' => __( 'The club\'s own events, shown in the calendar blocks, with a League games tab for fixtures from the LMS.', 'chess-army-knife' ),
				'key'         => 'club_events',
				'slug'        => 'edit.php?post_type=' . Chess_Army_Knife_Events::POST_TYPE,
				'callback'    => null,
				'kind'        => 'events',
				'can'         => function () {
					return current_user_can( 'edit_posts' );
				},
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
			),
			array(
				'group'       => $club_group,
				'title'       => __( 'Policies', 'chess-army-knife' ),
				'description' => __( 'The club data policy, safeguarding policy and privacy policy, as pages you can edit.', 'chess-army-knife' ),
				'key'         => 'policies',
				'slug'        => Chess_Army_Knife_Policies::PAGE,
				'callback'    => array( 'Chess_Army_Knife_Policies', 'render_page' ),
				'kind'        => 'pages',
				'can'         => function () {
					return current_user_can( Chess_Army_Knife_Policies::REQUIRED_CAP );
				},
				'unlisted'    => true, // A tab of another screen.
			),
			array(
				'group'       => $setup_group,
				'title'       => __( 'Setup', 'chess-army-knife' ),
				'description' => __( 'Your club\'s name, venue, regular events, and ECF and LMS details, in one place.', 'chess-army-knife' ),
				'key'         => 'setup',
				'slug'        => Chess_Army_Knife_Setup::PAGE,
				'callback'    => array( 'Chess_Army_Knife_Setup', 'render_page' ),
				'kind'        => 'settings',
				'can'         => function () {
					return current_user_can( Chess_Army_Knife_Setup::REQUIRED_CAP );
				},
			),
			array(
				'group'       => $setup_group,
				'title'       => __( 'Templates', 'chess-army-knife' ),
				'description' => __( 'Saved looks for the plugin\'s blocks.', 'chess-army-knife' ),
				'key'         => 'templates',
				'slug'        => Chess_Army_Knife_Templates::PAGE,
				'callback'    => array( 'Chess_Army_Knife_Templates', 'render_page' ),
				'kind'        => 'settings',
				'can'         => function () {
					return current_user_can( 'manage_options' );
				},
			),
			array(
				'group'       => $setup_group,
				'title'       => __( 'Settings', 'chess-army-knife' ),
				'description' => __( 'ECF and LMS settings, caching, renewal reminders and how members pay.', 'chess-army-knife' ),
				'key'         => 'settings',
				'slug'        => Chess_Army_Knife_Settings::PAGE,
				'callback'    => array( 'Chess_Army_Knife_Settings', 'render_page' ),
				'kind'        => 'settings',
				'can'         => function () {
					return current_user_can( 'manage_options' );
				},
			),
			array(
				'key'         => 'block_help',
				'group'       => __( 'Help', 'chess-army-knife' ),
				'title'       => __( 'Block Help', 'chess-army-knife' ),
				'description' => __( 'How to add the plugin\'s blocks to your pages, and what each one needs.', 'chess-army-knife' ),
				'slug'        => Chess_Army_Knife_Block_Help::PAGE,
				'callback'    => array( 'Chess_Army_Knife_Block_Help', 'render_page' ),
				'kind'        => '',
				'can'         => '__return_true',
			),
		);
	}

	/**
	 * One screen, by its key.
	 *
	 * @param string $key Key of a screen in areas().
	 * @return array|null
	 */
	public static function area( $key ) {
		foreach ( self::areas() as $area ) {
			if ( $area['key'] === $key ) {
				return $area;
			}
		}
		return null;
	}

	/**
	 * Add the menu and every screen beneath it.
	 */
	public static function register() {
		$capability = self::capability();
		$pending    = Chess_Army_Knife_Memberships::user_can_manage() ? Chess_Army_Knife_Membership_Store::count_view( 'pending' ) : 0;
		$bubble     = $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';

		add_menu_page(
			__( 'Chess Army Knife', 'chess-army-knife' ),
			__( 'Chess Army Knife', 'chess-army-knife' ) . $bubble,
			$capability,
			self::SLUG,
			array( __CLASS__, 'render_overview' ),
			'dashicons-awards',
			76
		);
		add_submenu_page( self::SLUG, __( 'Overview', 'chess-army-knife' ), __( 'Overview', 'chess-army-knife' ), $capability, self::SLUG, array( __CLASS__, 'render_overview' ) );

		foreach ( self::areas() as $area ) {
			if ( ! empty( $area['unlisted'] ) ) {
				// A tab with a screen of its own can still be opened, just not listed.
				if ( null !== $area['callback'] ) {
					add_submenu_page( null, $area['title'], $area['title'], $capability, $area['slug'], $area['callback'] );
				}
				continue;
			}
			$label = $area['title'] . ( Chess_Army_Knife_Memberships::MENU_SLUG === $area['slug'] ? $bubble : '' );
			add_submenu_page( self::SLUG, $area['title'], $label, $capability, $area['slug'], null === $area['callback'] ? '' : $area['callback'] );
		}

		// The Overview and Selection tabs of Teams have no menu item of their own.
		$tabs       = array(
			array( Chess_Army_Knife_Team_Overview::PAGE, __( 'Team Overview', 'chess-army-knife' ), array( 'Chess_Army_Knife_Team_Overview', 'render_page' ) ),
			array( Chess_Army_Knife_Selection_Page::SLUG, __( 'Team Selection', 'chess-army-knife' ), array( 'Chess_Army_Knife_Selection_Page', 'render_page' ) ),
		);
		$teams_area = self::area( 'teams' );
		foreach ( $tabs as $tab ) {
			// A captain's Teams item already is the Overview tab.
			if ( $teams_area && $tab[0] === $teams_area['slug'] ) {
				continue;
			}
			// No parent menu: the page can be opened but is not listed. (Removing a listed page makes WordPress refuse it.)
			add_submenu_page( null, $tab[1], $tab[1], $capability, $tab[0], $tab[2] );
		}
	}

	/**
	 * The address of a screen.
	 *
	 * @param array $area A screen from areas().
	 * @return string
	 */
	public static function url( array $area ) {
		return 0 === strpos( $area['slug'], 'edit.php' ) ? admin_url( $area['slug'] ) : admin_url( 'admin.php?page=' . $area['slug'] );
	}

	/**
	 * The screens a post type list, editor or new-item screen belongs to.
	 *
	 * @return array[] Screen by post type.
	 */
	protected static function post_type_areas() {
		$by_type = array();
		foreach ( self::areas() as $area ) {
			if ( isset( $area['post_type'] ) ) {
				$by_type[ $area['post_type'] ] = $area;
			}
		}
		return $by_type;
	}

	/**
	 * Explain, instead of WordPress's bare refusal, when someone opens the list
	 * or editor of one of the plugin's post types without the permission for it.
	 */
	public static function maybe_deny_post_screen() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Only decides which notice to show; nothing is changed.
		if ( isset( $_REQUEST['post'] ) ) {
			$type = get_post_type( absint( $_REQUEST['post'] ) );
		} else {
			$type = isset( $_REQUEST['post_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_type'] ) ) : 'post';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		$areas  = self::post_type_areas();
		$object = $type && isset( $areas[ $type ] ) ? get_post_type_object( $type ) : null;
		if ( ! $object || current_user_can( $object->cap->edit_posts ) ) {
			return;
		}

		Chess_Army_Knife_Access::deny_screen( $areas[ $type ]['title'], $areas[ $type ]['kind'] );
	}

	/**
	 * The Overview: every screen, what it is for, and whether the user may use it.
	 */
	public static function render_overview() {
		$groups = array();
		foreach ( self::areas() as $area ) {
			$groups[ $area['group'] ][] = $area;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Chess Army Knife', 'chess-army-knife' ); ?></h1>
			<?php echo Chess_Army_Knife_Setup::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice(). ?>
			<?php $checklist = Chess_Army_Knife_Setup_Checklist::html(); ?>
			<?php // Administrators get the whole list, which includes the policies; everyone else just the policies. ?>
			<?php echo '' !== $checklist ? $checklist : Chess_Army_Knife_Policies::attention_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in html() and attention_notice(). ?>
			<p class="description"><?php esc_html_e( 'Everything the plugin does is listed here. Some screens need a permission: the ones you cannot use say so.', 'chess-army-knife' ); ?></p>
			<?php foreach ( $groups as $group => $areas ) : ?>
				<h2><?php echo esc_html( $group ); ?></h2>
				<table class="widefat striped" style="max-width:900px">
					<tbody>
						<?php foreach ( $areas as $area ) : ?>
							<?php $allowed = (bool) call_user_func( $area['can'] ); ?>
							<tr>
								<td style="width:30%"><strong><a href="<?php echo esc_url( self::url( $area ) ); ?>"><?php echo esc_html( $area['title'] ); ?></a></strong></td>
								<td><?php echo esc_html( $area['description'] ); ?></td>
								<td style="width:25%">
									<?php
									if ( $allowed ) {
										esc_html_e( 'You can use this', 'chess-army-knife' );
									} else {
										/* translators: %s: the permission a screen needs, for example "Manage members" */
										echo esc_html( sprintf( __( 'Needs: %s', 'chess-army-knife' ), Chess_Army_Knife_Access::label( $area['kind'] ) ) );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
		<?php
	}
}

Chess_Army_Knife_Menu::init();
