<?php
/**
 * Tabs for the groups of screens that share one menu item: Other clubs (and Sort clubs), Club events (and Import
 * events), and Settings (and Policies). Each screen draws the same tab bar above its own content, and only the
 * first tab of a group has a menu item. (Teams and Members have tabs of their own, in Team_Tabs and Member_Tabs.)
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Section_Tabs {

	/**
	 * Hook up the tab bar on the post type screens of a group, and keep the group's menu item lit on its other tabs.
	 */
	public static function init() {
		add_action( 'edit_form_top', array( __CLASS__, 'render_on_edit_screen' ) );
		add_action( 'all_admin_notices', array( __CLASS__, 'render_on_list_screen' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
	}

	/**
	 * The groups of screens.
	 *
	 * @return array[] By group key: { listed: the menu item's slug, tabs: by key { label, url, can, page or post_type, unlisted } }.
	 */
	public static function groups() {
		$can_teams = function () {
			return Chess_Army_Knife_Teams::user_can_manage();
		};

		return array(
			'clubs'    => array(
				'listed' => 'edit.php?post_type=' . Chess_Army_Knife_Clubs::POST_TYPE,
				'tabs'   => array(
					'clubs' => array(
						'label'     => __( 'Other clubs', 'chess-army-knife' ),
						'url'       => admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Clubs::POST_TYPE ),
						'can'       => $can_teams,
						'post_type' => Chess_Army_Knife_Clubs::POST_TYPE,
					),
					'sort'  => array(
						'label'    => __( 'Sort clubs', 'chess-army-knife' ),
						'url'      => admin_url( 'admin.php?page=' . Chess_Army_Knife_Clubs::PAGE ),
						'can'      => $can_teams,
						'page'     => Chess_Army_Knife_Clubs::PAGE,
						'unlisted' => true,
					),
				),
			),
			'events'   => array(
				'listed' => 'edit.php?post_type=' . Chess_Army_Knife_Events::POST_TYPE,
				'tabs'   => array(
					'events' => array(
						'label'     => __( 'Club events', 'chess-army-knife' ),
						'url'       => admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Events::POST_TYPE ),
						'can'       => function () {
							return current_user_can( 'edit_posts' );
						},
						'post_type' => Chess_Army_Knife_Events::POST_TYPE,
					),
					'import' => array(
						'label'    => __( 'Import events', 'chess-army-knife' ),
						'url'      => admin_url( 'admin.php?page=' . Chess_Army_Knife_Events_Import::PAGE ),
						'can'      => $can_teams,
						'page'     => Chess_Army_Knife_Events_Import::PAGE,
						'unlisted' => true,
					),
				),
			),
			'settings' => array(
				'listed' => Chess_Army_Knife_Settings::PAGE,
				'tabs'   => array(
					'settings' => array(
						'label' => __( 'Settings', 'chess-army-knife' ),
						'url'   => admin_url( 'admin.php?page=' . Chess_Army_Knife_Settings::PAGE ),
						'can'   => function () {
							return current_user_can( 'manage_options' );
						},
						'page'  => Chess_Army_Knife_Settings::PAGE,
					),
					'policies' => array(
						'label'    => __( 'Policies', 'chess-army-knife' ),
						'url'      => admin_url( 'admin.php?page=' . Chess_Army_Knife_Policies::PAGE ),
						'can'      => function () {
							return current_user_can( Chess_Army_Knife_Policies::REQUIRED_CAP );
						},
						'page'     => Chess_Army_Knife_Policies::PAGE,
						'unlisted' => true,
					),
				),
			),
		);
	}

	/**
	 * Draw a group's tab bar, if the user may use more than one of its tabs.
	 *
	 * @param string $group  Group key.
	 * @param string $active Key of the tab being shown.
	 */
	public static function render( $group, $active ) {
		$groups = self::groups();
		if ( ! isset( $groups[ $group ] ) ) {
			return;
		}

		$tabs = array_filter(
			$groups[ $group ]['tabs'],
			function ( $tab ) {
				return (bool) call_user_func( $tab['can'] );
			}
		);
		if ( count( $tabs ) < 2 ) {
			return;
		}
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Sections', 'chess-army-knife' ); ?>">
			<?php foreach ( $tabs as $key => $tab ) : ?>
				<a class="nav-tab<?php echo $active === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $tab['url'] ); ?>"<?php echo $active === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tab['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * The group and tab a post type belongs to.
	 *
	 * @param string $post_type Post type.
	 * @return array|null { group, tab } or null.
	 */
	protected static function for_post_type( $post_type ) {
		foreach ( self::groups() as $group => $details ) {
			foreach ( $details['tabs'] as $key => $tab ) {
				if ( isset( $tab['post_type'] ) && $tab['post_type'] === $post_type ) {
					return array(
						'group' => $group,
						'tab'   => $key,
					);
				}
			}
		}
		return null;
	}

	/**
	 * Draw the tab bar above a post of a group's post type.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function render_on_edit_screen( $post ) {
		$found = $post ? self::for_post_type( $post->post_type ) : null;
		if ( $found ) {
			self::render( $found['group'], $found['tab'] );
		}
	}

	/**
	 * Draw the tab bar above the list of a group's post type.
	 */
	public static function render_on_list_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$found  = $screen && 0 === strpos( (string) $screen->id, 'edit-' ) ? self::for_post_type( substr( $screen->id, 5 ) ) : null;
		if ( $found ) {
			self::render( $found['group'], $found['tab'] );
		}
	}

	/**
	 * The group whose tab without a menu item is being shown.
	 *
	 * @return string Group key, or '' if none.
	 */
	protected static function hidden_tab_group() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides which menu item is lit.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		foreach ( self::groups() as $group => $details ) {
			foreach ( $details['tabs'] as $tab ) {
				if ( ! empty( $tab['unlisted'] ) && isset( $tab['page'] ) && $tab['page'] === $page ) {
					return $group;
				}
			}
		}
		return '';
	}

	/**
	 * Keep the plugin's menu open on a tab without a menu item.
	 *
	 * @param string $parent_file Parent menu slug.
	 * @return string
	 */
	public static function parent_file( $parent_file ) {
		return '' !== self::hidden_tab_group() ? Chess_Army_Knife_Menu::SLUG : $parent_file;
	}

	/**
	 * Light the group's menu item on a tab without one.
	 *
	 * @param string|null $submenu_file Submenu slug.
	 * @return string|null
	 */
	public static function submenu_file( $submenu_file ) {
		$group = self::hidden_tab_group();
		return '' !== $group ? self::groups()[ $group ]['listed'] : $submenu_file;
	}
}

Chess_Army_Knife_Section_Tabs::init();
