<?php
/**
 * The tabs that make Teams one place: a team's Details (its edit screen), Overview and Selection.
 * Each of the three screens draws the same tab bar above its own content.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Team_Tabs {

	/**
	 * Hook up the tab bar on the team edit screen, and keep the Teams menu item lit on the other tabs.
	 */
	public static function init() {
		add_action( 'edit_form_top', array( __CLASS__, 'render_on_edit_screen' ) );
		add_action( 'all_admin_notices', array( __CLASS__, 'render_on_list_screen' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
	}

	/**
	 * The tabs the current user may use for a team.
	 *
	 * @param int $team_id Team id, or 0 when no team is chosen yet.
	 * @return array[] Each { key, label, url }.
	 */
	public static function tabs( $team_id ) {
		$team_id = absint( $team_id );
		$tabs    = array();

		if ( $team_id && current_user_can( 'edit_post', $team_id ) ) {
			$tabs[] = array(
				'key'   => 'details',
				'label' => __( 'Details', 'chess-army-knife' ),
				'url'   => (string) get_edit_post_link( $team_id, 'raw' ),
			);
		} elseif ( Chess_Army_Knife_Teams::user_can_manage() ) {
			$tabs[] = array(
				'key'   => 'details',
				'label' => __( 'Details', 'chess-army-knife' ),
				'url'   => admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Teams::POST_TYPE ),
			);
		}

		if ( Chess_Army_Knife_Captains::user_can_select() ) {
			$args   = $team_id ? array( 'team' => $team_id ) : array();
			$tabs[] = array(
				'key'   => 'overview',
				'label' => __( 'Overview', 'chess-army-knife' ),
				'url'   => add_query_arg( array_merge( array( 'page' => Chess_Army_Knife_Team_Overview::PAGE ), $args ), admin_url( 'admin.php' ) ),
			);
			$tabs[] = array(
				'key'   => 'selection',
				'label' => __( 'Selection', 'chess-army-knife' ),
				'url'   => add_query_arg( array_merge( array( 'page' => Chess_Army_Knife_Selection_Page::SLUG ), $args ), admin_url( 'admin.php' ) ),
			);
		}

		return $tabs;
	}

	/**
	 * Draw the tab bar.
	 *
	 * @param int    $team_id Team id, or 0.
	 * @param string $active  Key of the tab being shown: details, overview or selection.
	 */
	public static function render( $team_id, $active ) {
		$tabs = self::tabs( $team_id );
		if ( count( $tabs ) < 2 ) {
			return;
		}
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Team sections', 'chess-army-knife' ); ?>">
			<?php foreach ( $tabs as $tab ) : ?>
				<a class="nav-tab<?php echo $active === $tab['key'] ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $tab['url'] ); ?>"<?php echo $active === $tab['key'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tab['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Draw the tab bar above an existing team's edit screen.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function render_on_edit_screen( $post ) {
		if ( ! $post ) {
			return;
		}
		if ( Chess_Army_Knife_Teams::POST_TYPE === $post->post_type ) {
			if ( 'auto-draft' !== $post->post_status ) {
				self::render( $post->ID, 'details' );
			} else {
				self::render_sections( 'teams' );
			}
		} elseif ( Chess_Army_Knife_Team_Groups::POST_TYPE === $post->post_type ) {
			self::render_sections( 'groups' );
		}
	}

	/**
	 * Draw the Teams, Groups and Leagues switch above the lists of each.
	 */
	public static function render_on_list_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'edit-' . Chess_Army_Knife_Teams::POST_TYPE === $screen->id ) {
			self::render_sections( 'teams' );
		} elseif ( $screen && 'edit-' . Chess_Army_Knife_Team_Groups::POST_TYPE === $screen->id ) {
			self::render_sections( 'groups' );
		}
	}

	/**
	 * Draw the switch between the list of teams, the list of groups and the leagues.
	 *
	 * @param string $active 'teams', 'groups' or 'leagues'.
	 */
	public static function render_sections( $active ) {
		if ( ! Chess_Army_Knife_Teams::user_can_manage() ) {
			return;
		}
		$sections = array(
			'teams'   => array( __( 'Teams', 'chess-army-knife' ), admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Teams::POST_TYPE ) ),
			'groups'  => array( __( 'Groups', 'chess-army-knife' ), admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Team_Groups::POST_TYPE ) ),
			'leagues' => array( __( 'Leagues', 'chess-army-knife' ), admin_url( 'admin.php?page=' . Chess_Army_Knife_Leagues_Page::PAGE ) ),
		);
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Teams, groups and leagues', 'chess-army-knife' ); ?>">
			<?php foreach ( $sections as $key => $section ) : ?>
				<a class="nav-tab<?php echo $active === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $section[1] ); ?>"<?php echo $active === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $section[0] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Whether the screen being shown is one of Teams' tabs that has no menu item of its own: Overview, Selection, Groups or Leagues.
	 *
	 * @return bool
	 */
	protected static function on_hidden_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides which menu item is lit.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// Groups have no menu item either: they are a tab of Teams.
		return in_array( $page, array( Chess_Army_Knife_Team_Overview::PAGE, Chess_Army_Knife_Selection_Page::SLUG, Chess_Army_Knife_Leagues_Page::PAGE ), true )
			|| ( $screen && Chess_Army_Knife_Team_Groups::POST_TYPE === $screen->post_type );
	}

	/**
	 * Keep the plugin's menu open on the Overview and Selection tabs.
	 *
	 * @param string $parent_file Parent menu slug.
	 * @return string
	 */
	public static function parent_file( $parent_file ) {
		return self::on_hidden_tab() ? Chess_Army_Knife_Menu::SLUG : $parent_file;
	}

	/**
	 * Light the Teams menu item on the Overview and Selection tabs.
	 *
	 * @param string|null $submenu_file Submenu slug.
	 * @return string|null
	 */
	public static function submenu_file( $submenu_file ) {
		if ( ! self::on_hidden_tab() ) {
			return $submenu_file;
		}
		$area = Chess_Army_Knife_Menu::area( 'teams' );
		return $area ? $area['slug'] : $submenu_file;
	}
}

Chess_Army_Knife_Team_Tabs::init();
