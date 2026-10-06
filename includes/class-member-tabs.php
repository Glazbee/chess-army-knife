<?php
/**
 * The tabs that make Members one place: the members themselves, Checks, Types, Seasons, Renewals, Players from the LMS and Do not record. Each screen draws
 * the same tab bar above its own content, and only Members has a menu item.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Member_Tabs {

	/**
	 * Hook up the tab bar on the membership types' screens, and keep the Members menu item lit on the other tabs.
	 */
	public static function init() {
		add_action( 'edit_form_top', array( __CLASS__, 'render_on_edit_screen' ) );
		add_action( 'all_admin_notices', array( __CLASS__, 'render_on_list_screen' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
	}

	/**
	 * The tabs, by key.
	 *
	 * @return array[] Each key: { label, url }.
	 */
	public static function tabs() {
		return array(
			'members'  => array( __( 'Members', 'chess-army-knife' ), admin_url( 'admin.php?page=' . Chess_Army_Knife_Memberships::MENU_SLUG ) ),
			'checks'   => array( __( 'Checks', 'chess-army-knife' ), admin_url( 'admin.php?page=' . Chess_Army_Knife_Member_Checks_Page::SLUG ) ),
			'types'    => array( __( 'Membership types', 'chess-army-knife' ), admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Memberships::POST_TYPE ) ),
			'seasons'  => array( __( 'Seasons', 'chess-army-knife' ), admin_url( 'admin.php?page=' . Chess_Army_Knife_Seasons_Page::SLUG ) ),
			'renewals' => array( __( 'Renewals', 'chess-army-knife' ), admin_url( 'admin.php?page=' . Chess_Army_Knife_Renewals_Page::SLUG ) ),
			'players'  => array( __( 'Players from the LMS', 'chess-army-knife' ), admin_url( 'admin.php?page=' . Chess_Army_Knife_LMS_Players::PAGE ) ),
			'dnr'      => array( __( 'Do not record', 'chess-army-knife' ), admin_url( 'admin.php?page=' . Chess_Army_Knife_Do_Not_Record::PAGE ) ),
		);
	}

	/**
	 * Draw the tab bar.
	 *
	 * @param string $active Key of the tab being shown.
	 */
	public static function render( $active ) {
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			return;
		}
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Members sections', 'chess-army-knife' ); ?>">
			<?php foreach ( self::tabs() as $key => $tab ) : ?>
				<a class="nav-tab<?php echo $active === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $tab[1] ); ?>"<?php echo $active === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tab[0] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Draw the tab bar above a membership type's edit screen.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function render_on_edit_screen( $post ) {
		if ( $post && Chess_Army_Knife_Memberships::POST_TYPE === $post->post_type ) {
			self::render( 'types' );
		}
	}

	/**
	 * Draw the tab bar above the list of membership types.
	 */
	public static function render_on_list_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'edit-' . Chess_Army_Knife_Memberships::POST_TYPE === $screen->id ) {
			self::render( 'types' );
		}
	}

	/**
	 * Whether the screen being shown is one of Members' tabs that has no menu item of its own.
	 *
	 * @return bool
	 */
	protected static function on_hidden_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides which menu item is lit.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return in_array( $page, array( Chess_Army_Knife_Member_Checks_Page::SLUG, Chess_Army_Knife_Renewals_Page::SLUG, Chess_Army_Knife_Seasons_Page::SLUG, Chess_Army_Knife_LMS_Players::PAGE, Chess_Army_Knife_Do_Not_Record::PAGE ), true )
			|| ( $screen && Chess_Army_Knife_Memberships::POST_TYPE === $screen->post_type );
	}

	/**
	 * Keep the plugin's menu open on the tabs without a menu item.
	 *
	 * @param string $parent_file Parent menu slug.
	 * @return string
	 */
	public static function parent_file( $parent_file ) {
		return self::on_hidden_tab() ? Chess_Army_Knife_Menu::SLUG : $parent_file;
	}

	/**
	 * Light the Members menu item on the tabs without one.
	 *
	 * @param string|null $submenu_file Submenu slug.
	 * @return string|null
	 */
	public static function submenu_file( $submenu_file ) {
		return self::on_hidden_tab() ? Chess_Army_Knife_Memberships::MENU_SLUG : $submenu_file;
	}
}

Chess_Army_Knife_Member_Tabs::init();
