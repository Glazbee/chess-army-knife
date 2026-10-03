<?php
/**
 * Players from the LMS: the club's own players found in the league results the import has kept, who
 * are not on a membership record yet. An admin setting the plugin up can tick them and add them as
 * pending members in one go, then confirm each membership from the Members screen.
 *
 * Nothing is added by itself. A person who asked not to be recorded is never listed, and the people
 * added have only the name and ECF code the LMS gave, and their consent to the club holding their details is
 * taken as given (the club is choosing to add its own players). Newsletter and WhatsApp consent are never assumed.
 * Contact details and the membership itself are filled in when the admin confirms them.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_LMS_Players {

	const PAGE   = 'chess-army-knife-lms-players';
	const ACTION = 'chess_army_knife_lms_players';

	/**
	 * Hook up the form.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * The players in the results who have no membership record and may be recorded.
	 *
	 * @return array[] ECF code (digits) => { code, name, rating, games, last_played }, most games first.
	 */
	public static function candidates() {
		$found = array();

		foreach ( Chess_Army_Knife_Event_Results::all_club_players() as $key => $player ) {
			if ( Chess_Army_Knife_Membership_Store::find_by_ecf_code( $player['code'] ) || Chess_Army_Knife_Do_Not_Record::is_blocked( $player['code'], $player['name'] ) ) {
				continue;
			}
			$found[ $key ] = $player;
		}

		uasort(
			$found,
			function ( $a, $b ) {
				return $b['games'] === $a['games'] ? strcasecmp( $a['name'], $b['name'] ) : $b['games'] - $a['games'];
			}
		);

		return $found;
	}

	/**
	 * Add players as pending members.
	 *
	 * @param string[] $keys       Keys of the players asked for.
	 * @param array[]  $candidates From candidates(). Anything not in it is ignored.
	 * @return int How many people were added.
	 */
	public static function add( array $keys, array $candidates ) {
		$added = 0;

		foreach ( array_unique( $keys ) as $key ) {
			if ( ! isset( $candidates[ $key ] ) ) {
				continue;
			}
			$player = $candidates[ $key ];

			Chess_Army_Knife_Membership_Store::save_member(
				array(
					'name'       => sanitize_text_field( $player['name'] ),
					'ecf_code'   => Chess_Army_Knife_Do_Not_Record::clean_code( $player['code'] ),
					'status'     => Chess_Army_Knife_Membership_Store::STATUS_PENDING,
					'source'     => Chess_Army_Knife_Membership_Store::SOURCE_MANUAL,
					// Joining the club's team is taken as agreeing to the club holding their details; the extras (newsletter, WhatsApp) are not.
					'consent_at' => current_time( 'mysql', true ),
				)
			);
			++$added;
		}

		return $added;
	}

	/**
	 * Handle the "Add as members" button.
	 */
	public static function handle() {
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() || ! check_admin_referer( self::ACTION ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by check_admin_referer() above.
		$keys = isset( $_POST['players'] ) && is_array( $_POST['players'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['players'] ) ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by check_admin_referer() above.
		$all        = ! empty( $_POST['all_players'] );
		$candidates = self::candidates();

		$added = self::add( $all ? array_keys( $candidates ) : $keys, $candidates );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::PAGE,
					'added' => $added,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Draw the screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Memberships::user_can_manage(), __( 'Players from the LMS', 'chess-army-knife' ), 'members' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display; nothing is changed.
		$added       = isset( $_GET['added'] ) ? absint( $_GET['added'] ) : null;
		$candidates  = self::candidates();
		$date_format = get_option( 'date_format' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Members', 'chess-army-knife' ); ?></h1>
			<?php Chess_Army_Knife_Member_Tabs::render( 'players' ); ?>
			<p><?php esc_html_e( 'These people played for your teams in the results Import Events has kept, and are not on your membership records. Add the ones who are your members: each is added as a pending member with just the name and ECF code the LMS holds, and their consent to the club holding those details is recorded as given. You then confirm their membership and add their contact details from the Members screen. Newsletter and WhatsApp choices are not assumed: they stay off until the person agrees. Nobody is added until you tick them.', 'chess-army-knife' ); ?></p>

			<?php if ( null !== $added ) : ?>
				<div class="notice notice-success" role="status"><p>
					<?php echo esc_html( sprintf( /* translators: %d: number of people */ _n( '%d person was added as a pending member.', '%d people were added as pending members.', $added, 'chess-army-knife' ), $added ) ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Chess_Army_Knife_Memberships::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Go to Members', 'chess-army-knife' ); ?></a>
				</p></div>
			<?php endif; ?>

			<?php if ( empty( $candidates ) ) : ?>
				<p><?php esc_html_e( 'There is nobody to add. Import Events keeps the results of the fixtures it brings in, so run it (and import earlier seasons if you want the history) and come back; anyone who played and is already a member is not listed.', 'chess-army-knife' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
					<?php wp_nonce_field( self::ACTION ); ?>
					<table class="widefat striped" style="max-width:800px">
						<caption class="screen-reader-text"><?php esc_html_e( 'Players found in the LMS results who are not on your membership records', 'chess-army-knife' ); ?></caption>
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Add', 'chess-army-knife' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></th>
								<th scope="col"><?php esc_html_e( 'ECF code', 'chess-army-knife' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Rating', 'chess-army-knife' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Games', 'chess-army-knife' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last played', 'chess-army-knife' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $candidates as $key => $player ) : ?>
								<tr>
									<td>
										<input type="checkbox" id="cak-lp-<?php echo esc_attr( $key ); ?>" name="players[]" value="<?php echo esc_attr( $key ); ?>" />
										<label class="screen-reader-text" for="cak-lp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( sprintf( /* translators: %s: player */ __( 'Add %s as a member', 'chess-army-knife' ), $player['name'] ) ); ?></label>
									</td>
									<td><?php echo esc_html( $player['name'] ); ?></td>
									<td><?php echo esc_html( $player['code'] ); ?></td>
									<td><?php echo esc_html( null !== $player['rating'] ? (string) $player['rating'] : '–' ); ?></td>
									<td><?php echo (int) $player['games']; ?></td>
									<td><?php echo esc_html( '' !== $player['last_played'] ? mysql2date( $date_format, $player['last_played'] ) : '–' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p><label><input type="checkbox" name="all_players" value="1" /> <?php esc_html_e( 'Add everyone in the list', 'chess-army-knife' ); ?></label></p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add the ticked people as pending members', 'chess-army-knife' ); ?></button></p>
				</form>
				<p class="description"><?php esc_html_e( 'They join their teams\' squads the next time Import Events runs, if they have played this season. Someone who is not a member of the club (a loan player, say) does not need adding.', 'chess-army-knife' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

Chess_Army_Knife_LMS_Players::init();
