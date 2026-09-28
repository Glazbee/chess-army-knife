<?php
/**
 * "Club Teams" admin page: a proper add/edit/delete list of the teams
 * the club has entered (organisation + event/division + team name),
 * replacing the earlier free-text "one per line" field on the main
 * Settings page. This is what the Team Fixtures Carousel block reads
 * from when its source is set to "My club's teams".
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Club_Teams_Page {

	const OPTION = 'Chess_Army_Knife_club_teams';

	/**
	 * Boot the admin page and its form handlers.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_ecf_lms_add_team', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_ecf_lms_delete_team', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Register the submenu page under the plugin's top-level menu.
	 */
	public static function add_menu() {
		add_submenu_page(
			'chess-army-knife',
			__( 'Club Teams', 'chess-army-knife' ),
			__( 'Club Teams', 'chess-army-knife' ),
			'manage_options',
			'chess-army-knife-club-teams',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Get the configured teams, migrating the old free-text "org |
	 * event | team" per-line Settings field into this structured list
	 * the first time it's needed (one-off, only if this option has
	 * never been saved before).
	 *
	 * @return array[] List of { org, event, team }, each also carrying its own array index as 'id'.
	 */
	public static function get_teams() {
		$teams = get_option( self::OPTION, null );

		if ( null === $teams ) {
			$teams = self::migrate_legacy_text();
			update_option( self::OPTION, $teams );
		}

		$out = array();
		foreach ( (array) $teams as $i => $team ) {
			if ( ! isset( $team['org'], $team['event'], $team['team'] ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $i,
				'org'   => $team['org'],
				'event' => $team['event'],
				'team'  => $team['team'],
			);
		}
		return $out;
	}

	/**
	 * One-off migration from the old Chess_Army_Knife_Settings "club_teams" raw
	 * textarea ("org | event | team" per line) into the structured shape.
	 *
	 * @return array[]
	 */
	protected static function migrate_legacy_text() {
		$settings = get_option( Chess_Army_Knife_Settings::OPTION, array() );
		$raw      = is_array( $settings ) && isset( $settings['club_teams'] ) ? $settings['club_teams'] : '';

		$teams = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) < 3 || '' === $parts[0] || '' === $parts[1] || '' === $parts[2] ) {
				continue;
			}
			$teams[] = array(
				'org'   => $parts[0],
				'event' => $parts[1],
				'team'  => $parts[2],
			);
		}
		return $teams;
	}

	/**
	 * Handle the "Add team" form submission.
	 */
	public static function handle_add() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ecf_lms_add_team' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ) );
		}

		$org   = isset( $_POST['org'] ) ? preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['org'] ) ) : '';
		$event = isset( $_POST['event'] ) ? sanitize_text_field( wp_unslash( $_POST['event'] ) ) : '';
		$team  = isset( $_POST['team'] ) ? sanitize_text_field( wp_unslash( $_POST['team'] ) ) : '';

		$redirect_args = array( 'page' => 'chess-army-knife-club-teams' );

		if ( '' === $org || '' === $event || '' === $team ) {
			$redirect_args['ecf_lms_team_error'] = '1';
		} else {
			$teams   = get_option( self::OPTION, array() );
			$teams[] = array( 'org' => $org, 'event' => $event, 'team' => $team );
			update_option( self::OPTION, array_values( $teams ) );
			$redirect_args['ecf_lms_team_added'] = '1';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle a "delete team" link.
	 */
	public static function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ecf_lms_delete_team' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ) );
		}

		$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : -1;
		$teams = get_option( self::OPTION, array() );

		if ( isset( $teams[ $id ] ) ) {
			unset( $teams[ $id ] );
			update_option( self::OPTION, array_values( $teams ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'chess-army-knife-club-teams', 'ecf_lms_team_deleted' => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$teams = self::get_teams();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Club Teams', 'chess-army-knife' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'List every team your club has entered, across any number of divisions or organisations - including more than one team in the same division. The Team Fixtures Carousel block can read this list directly, so it only has to be maintained here.', 'chess-army-knife' ); ?>
			</p>

			<?php if ( isset( $_GET['ecf_lms_team_added'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Team added.', 'chess-army-knife' ); ?></p></div>
			<?php elseif ( isset( $_GET['ecf_lms_team_deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Team removed.', 'chess-army-knife' ); ?></p></div>
			<?php elseif ( isset( $_GET['ecf_lms_team_error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Please fill in organisation ID, event name and team name.', 'chess-army-knife' ); ?></p></div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Organisation ID', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Event / division', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Team name', 'chess-army-knife' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Action', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $teams ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No teams added yet.', 'chess-army-knife' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $teams as $team ) : ?>
							<tr>
								<td><?php echo esc_html( $team['org'] ); ?></td>
								<td><?php echo esc_html( $team['event'] ); ?></td>
								<td><?php echo esc_html( $team['team'] ); ?></td>
								<td>
									<a
										href="<?php
										echo esc_url(
											wp_nonce_url(
												add_query_arg(
													array(
														'action' => 'ecf_lms_delete_team',
														'id'     => $team['id'],
													),
													admin_url( 'admin-post.php' )
												),
												'ecf_lms_delete_team'
											)
										);
										?>"
										onclick="return confirm('<?php echo esc_js( __( 'Remove this team?', 'chess-army-knife' ) ); ?>');"
									><?php esc_html_e( 'Remove', 'chess-army-knife' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Add a team', 'chess-army-knife' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ecf_lms_add_team" />
				<?php wp_nonce_field( 'ecf_lms_add_team' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="org"><?php esc_html_e( 'Organisation ID', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="org" name="org" class="regular-text" placeholder="e.g. 270" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="event"><?php esc_html_e( 'Event / division name', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="event" name="event" class="regular-text" placeholder="e.g. Division 1" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="team"><?php esc_html_e( 'Team name', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="team" name="team" class="regular-text" placeholder="e.g. Gloucester A" required /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Add team', 'chess-army-knife' ) ); ?>
			</form>
		</div>
		<?php
	}
}

Chess_Army_Knife_Club_Teams_Page::init();
