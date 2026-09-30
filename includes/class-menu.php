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
		foreach ( array( 'edit.php', 'post.php', 'post-new.php' ) as $screen ) {
			add_action( 'load-' . $screen, array( __CLASS__, 'maybe_deny_post_screen' ) );
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
	 *     @type string        $group       Heading on the Overview.
	 *     @type string        $title       Name of the screen.
	 *     @type string        $description What it is for.
	 *     @type string        $slug        Page slug, or a post type list such as edit.php?post_type=x.
	 *     @type callable|null $callback    Draws the page; null for a post type list WordPress draws.
	 *     @type string        $kind        What it needs, a key of Chess_Army_Knife_Access::requirements().
	 *     @type callable      $can         Whether the current user may use it.
	 *     @type string        $post_type   For a post type list, the post type.
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

		return array(
			array(
				'group'       => $members_group,
				'title'       => __( 'Members', 'chess-army-knife' ),
				'description' => __( 'Members and applications: approve, edit, bulk actions and CSV export.', 'chess-army-knife' ),
				'slug'        => Chess_Army_Knife_Memberships::MENU_SLUG,
				'callback'    => array( 'Chess_Army_Knife_Members_Page', 'render_page' ),
				'kind'        => 'members',
				'can'         => $can_members,
			),
			array(
				'group'       => $members_group,
				'title'       => __( 'Dashboard', 'chess-army-knife' ),
				'description' => __( 'The club at a glance: totals only, no names.', 'chess-army-knife' ),
				'slug'        => Chess_Army_Knife_Dashboard_Page::SLUG,
				'callback'    => array( 'Chess_Army_Knife_Dashboard_Page', 'render_page' ),
				'kind'        => 'members',
				'can'         => $can_members,
			),
			array(
				'group'       => $members_group,
				'title'       => __( 'Member Checks', 'chess-army-knife' ),
				'description' => __( 'Missing or invalid ECF codes, incomplete applications, expired memberships and duplicates.', 'chess-army-knife' ),
				'slug'        => Chess_Army_Knife_Member_Checks_Page::SLUG,
				'callback'    => array( 'Chess_Army_Knife_Member_Checks_Page', 'render_page' ),
				'kind'        => 'members',
				'can'         => $can_members,
			),
			array(
				'group'       => $members_group,
				'title'       => __( 'Renewals', 'chess-army-knife' ),
				'description' => __( 'Who the next renewal reminders will go to.', 'chess-army-knife' ),
				'slug'        => Chess_Army_Knife_Renewals_Page::SLUG,
				'callback'    => array( 'Chess_Army_Knife_Renewals_Page', 'render_page' ),
				'kind'        => 'members',
				'can'         => $can_members,
			),
			array(
				'group'       => $members_group,
				'title'       => __( 'Announcements', 'chess-army-knife' ),
				'description' => __( 'Messages to members, all of them or chosen teams.', 'chess-army-knife' ),
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
				'slug'        => 'edit.php?post_type=' . Chess_Army_Knife_Memberships::POST_TYPE,
				'callback'    => null,
				'kind'        => 'members',
				'can'         => $can_members,
				'post_type'   => Chess_Army_Knife_Memberships::POST_TYPE,
			),
			array(
				'group'       => $teams_group,
				'title'       => __( 'Teams', 'chess-army-knife' ),
				'description' => __( 'The club\'s teams, their league entries and squads.', 'chess-army-knife' ),
				'slug'        => 'edit.php?post_type=' . Chess_Army_Knife_Teams::POST_TYPE,
				'callback'    => null,
				'kind'        => 'teams',
				'can'         => $can_teams,
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
			),
			array(
				'group'       => $teams_group,
				'title'       => __( 'Team Selection', 'chess-army-knife' ),
				'description' => __( 'Ask the squad who can play and publish the line-up.', 'chess-army-knife' ),
				'slug'        => Chess_Army_Knife_Selection_Page::SLUG,
				'callback'    => array( 'Chess_Army_Knife_Selection_Page', 'render_page' ),
				'kind'        => 'selection',
				'can'         => array( 'Chess_Army_Knife_Captains', 'user_can_select' ),
			),
			array(
				'group'       => $teams_group,
				'title'       => __( 'Import Events', 'chess-army-knife' ),
				'description' => __( 'Bring league fixtures in from the LMS.', 'chess-army-knife' ),
				'slug'        => Chess_Army_Knife_Events_Import::PAGE,
				'callback'    => array( 'Chess_Army_Knife_Events_Import', 'render_page' ),
				'kind'        => 'teams',
				'can'         => $can_teams,
			),
			array(
				'group'       => $club_group,
				'title'       => __( 'Tournaments', 'chess-army-knife' ),
				'description' => __( 'Club tournaments, players and results.', 'chess-army-knife' ),
				'slug'        => Chess_Army_Knife_Tournaments_Page::SLUG,
				'callback'    => array( 'Chess_Army_Knife_Tournaments_Page', 'render_page' ),
				'kind'        => 'tournaments',
				'can'         => array( 'Chess_Army_Knife_Tournaments', 'user_can_manage' ),
			),
			array(
				'group'       => $club_group,
				'title'       => __( 'Club Events', 'chess-army-knife' ),
				'description' => __( 'Club nights and events, shown in the calendar blocks.', 'chess-army-knife' ),
				'slug'        => 'edit.php?post_type=' . Chess_Army_Knife_Events::POST_TYPE,
				'callback'    => null,
				'kind'        => 'events',
				'can'         => function () {
					return current_user_can( 'edit_posts' );
				},
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
			),
			array(
				'group'       => $setup_group,
				'title'       => __( 'Templates', 'chess-army-knife' ),
				'description' => __( 'Saved looks for the plugin\'s blocks.', 'chess-army-knife' ),
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
				'slug'        => Chess_Army_Knife_Settings::PAGE,
				'callback'    => array( 'Chess_Army_Knife_Settings', 'render_page' ),
				'kind'        => 'settings',
				'can'         => function () {
					return current_user_can( 'manage_options' );
				},
			),
		);
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
			$label = $area['title'] . ( Chess_Army_Knife_Memberships::MENU_SLUG === $area['slug'] ? $bubble : '' );
			add_submenu_page( self::SLUG, $area['title'], $label, $capability, $area['slug'], null === $area['callback'] ? '' : $area['callback'] );
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
