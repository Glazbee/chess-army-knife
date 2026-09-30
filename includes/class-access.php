<?php
/**
 * What to show someone who opens a screen they may not use. Every screen in
 * the plugin's menu is listed for everyone, so the screens themselves explain,
 * in plain words, what permission they need and how to get it, instead of
 * hiding or failing with a bare error.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Access {

	/**
	 * What each kind of screen needs, and how someone gets it.
	 *
	 * @return array[] Each { needs, how } by kind.
	 */
	public static function requirements() {
		$profile = __( 'Ask a club administrator to give you access: they can do it on your profile under Users.', 'chess-army-knife' );

		return array(
			'members'     => array(
				'needs' => __( 'This shows members\' personal details, so it is only for people with the "manage members" permission.', 'chess-army-knife' ),
				'how'   => $profile,
			),
			'teams'       => array(
				'needs' => __( 'This is only for people with the "club teams" permission (or the "manage members" permission).', 'chess-army-knife' ),
				'how'   => $profile,
			),
			'selection'   => array(
				'needs' => __( 'This is for team captains, who need a website login set on their team, and for people with the "club teams" permission.', 'chess-army-knife' ),
				'how'   => __( 'Ask a club officer to set you as your team\'s captain, with your website login.', 'chess-army-knife' ),
			),
			'tournaments' => array(
				'needs' => __( 'Managing tournaments needs an administrator account.', 'chess-army-knife' ),
				'how'   => $profile,
			),
			'settings'    => array(
				'needs' => __( 'The plugin\'s settings need an administrator account.', 'chess-army-knife' ),
				'how'   => $profile,
			),
			'events'      => array(
				'needs' => __( 'Club events can be edited by editors, authors and administrators.', 'chess-army-knife' ),
				'how'   => $profile,
			),
		);
	}

	/**
	 * A short label for what a kind of screen needs, for the overview.
	 *
	 * @param string $kind Kind, a key of requirements().
	 * @return string
	 */
	public static function label( $kind ) {
		$labels = array(
			'members'     => __( 'Manage members', 'chess-army-knife' ),
			'teams'       => __( 'Club teams', 'chess-army-knife' ),
			'selection'   => __( 'Captain, or club teams', 'chess-army-knife' ),
			'tournaments' => __( 'Administrator', 'chess-army-knife' ),
			'settings'    => __( 'Administrator', 'chess-army-knife' ),
			'events'      => __( 'Editor or above', 'chess-army-knife' ),
		);
		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : '';
	}

	/**
	 * The notice itself, on a page of its own.
	 *
	 * @param string $title Name of the screen.
	 * @param string $kind  What the screen needs, a key of requirements().
	 */
	public static function render_notice( $title, $kind ) {
		$requirements = self::requirements();
		$requirement  = isset( $requirements[ $kind ] ) ? $requirements[ $kind ] : $requirements['members'];
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( 'You do not have permission to use this page.', 'chess-army-knife' ); ?></strong></p>
				<p><?php echo esc_html( $requirement['needs'] ); ?></p>
				<p><?php echo esc_html( $requirement['how'] ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Carry on only if the user may use the screen; otherwise show them why not.
	 *
	 * @param bool   $allowed Whether the user may use the screen.
	 * @param string $title   Name of the screen.
	 * @param string $kind    What the screen needs, a key of requirements().
	 * @return bool True if the screen should go on to show its content.
	 */
	public static function render_unless( $allowed, $title, $kind ) {
		if ( $allowed ) {
			return true;
		}
		self::render_notice( $title, $kind );
		return false;
	}

	/**
	 * Show the notice on a whole admin screen, for a WordPress screen the plugin
	 * does not draw itself (the lists and editors of its post types), and stop.
	 *
	 * @param string $title Name of the screen.
	 * @param string $kind  What the screen needs, a key of requirements().
	 */
	public static function deny_screen( $title, $kind ) {
		$GLOBALS['title'] = $title; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The admin header reads the screen's title from here.

		require_once ABSPATH . 'wp-admin/admin-header.php';
		self::render_notice( $title, $kind );
		require_once ABSPATH . 'wp-admin/admin-footer.php';
		exit;
	}
}
