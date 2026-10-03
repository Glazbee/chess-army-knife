<?php
/**
 * Squad Review: who is in a squad but has not played for the team lately. Import Events only ever
 * adds people to a squad, so squads grow; this screen lists those who have not appeared for the team
 * since a date (a year by default) and lets an officer take them out in one go.
 *
 * "Played" comes from the board results of the league import. A person added by hand who has not
 * played yet counts from the day they were added, so nobody is listed straight away.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Squad_Review {

	const PAGE   = 'chess-army-knife-squad-review';
	const ACTION = 'chess_army_knife_squad_review';

	/** Months looked back by default. */
	const DEFAULT_MONTHS = 12;

	/**
	 * Hook up the form.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * The date a number of months before today.
	 *
	 * @param string $today  "Y-m-d".
	 * @param int    $months Months back, 1 to 60.
	 * @return string "Y-m-d".
	 */
	public static function cutoff( $today, $months ) {
		$months                     = max( 1, min( 60, (int) $months ) );
		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $today ) );

		$index = $year * 12 + ( $month - 1 ) - $months;
		$y     = intdiv( $index, 12 );
		$m     = $index % 12 + 1;
		$last  = (int) gmdate( 't', gmmktime( 12, 0, 0, $m, 1, $y ) );

		return sprintf( '%04d-%02d-%02d', $y, $m, min( $day, $last ) );
	}

	/**
	 * Take people out of squads.
	 *
	 * @param array $posted Team id => member ids. Only people who are in that team's squad are touched.
	 * @return int How many people were taken out.
	 */
	public static function remove( array $posted ) {
		$removed = 0;

		foreach ( $posted as $team_id => $person_ids ) {
			$team = Chess_Army_Knife_Teams::get( absint( $team_id ) );
			if ( ! $team || ! is_array( $person_ids ) ) {
				continue;
			}
			$ids = array_values( array_intersect( array_map( 'absint', $person_ids ), Chess_Army_Knife_Teams::squad( $team['id'] ) ) );
			Chess_Army_Knife_Teams::remove_from_squad( $team['id'], $ids );
			$removed += count( $ids );
		}

		return $removed;
	}

	/**
	 * Remove the ticked people from their squads.
	 */
	public static function handle() {
		if ( ! Chess_Army_Knife_Teams::user_can_manage() || ! check_admin_referer( self::ACTION ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}

		$posted  = isset( $_POST['remove'] ) && is_array( $_POST['remove'] ) ? wp_unslash( $_POST['remove'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each id is made a whole number in remove().
		$removed = self::remove( $posted );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE,
					'removed' => $removed,
					'months'  => isset( $_POST['months'] ) ? max( 1, min( 60, absint( $_POST['months'] ) ) ) : self::DEFAULT_MONTHS,
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
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Teams::user_can_manage(), __( 'Squad Review', 'chess-army-knife' ), 'teams' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display; nothing is changed.
		$months  = isset( $_GET['months'] ) ? max( 1, min( 60, absint( $_GET['months'] ) ) ) : self::DEFAULT_MONTHS;
		$removed = isset( $_GET['removed'] ) ? absint( $_GET['removed'] ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$cutoff      = self::cutoff( current_time( 'Y-m-d' ), $months );
		$date_format = get_option( 'date_format' );
		$any         = false;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Teams', 'chess-army-knife' ); ?></h1>
			<?php Chess_Army_Knife_Team_Tabs::render_sections( 'review' ); ?>
			<p><?php esc_html_e( 'Importing fixtures adds people to a squad when they play, and never takes anyone out. Here are the people who have not played for a team lately, so you can tidy the squads. Taking someone out of a squad changes nothing else: they stay a member, and can be put back from the Teams or Members screen.', 'chess-army-knife' ); ?></p>

			<?php if ( null !== $removed ) : ?>
				<div class="notice notice-success" role="status"><p><?php echo esc_html( sprintf( /* translators: %d: number of people */ _n( '%d person was taken out of a squad.', '%d people were taken out of squads.', $removed, 'chess-army-knife' ), $removed ) ); ?></p></div>
			<?php endif; ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<p>
					<label for="cak-squad-months"><?php esc_html_e( 'Show people who have not played for', 'chess-army-knife' ); ?></label>
					<input type="number" id="cak-squad-months" name="months" value="<?php echo esc_attr( $months ); ?>" min="1" max="60" class="small-text" />
					<?php esc_html_e( 'months', 'chess-army-knife' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Show', 'chess-army-knife' ); ?></button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<input type="hidden" name="months" value="<?php echo esc_attr( $months ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>

				<?php foreach ( Chess_Army_Knife_Teams::all() as $team ) : ?>
					<?php
					$stale = Chess_Army_Knife_Teams::not_seen_since( Chess_Army_Knife_Teams::squad_rows( $team['id'] ), $cutoff );
					if ( ! $stale ) {
						continue;
					}
					$any = true;
					?>
					<h2><?php echo esc_html( $team['name'] ); ?></h2>
					<table class="widefat striped" style="max-width:700px">
						<caption class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: team name */ __( 'Squad members of %s who have not played lately', 'chess-army-knife' ), $team['name'] ) ); ?></caption>
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Take out', 'chess-army-knife' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last played for the team', 'chess-army-knife' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $stale as $row ) : ?>
								<?php $member = Chess_Army_Knife_Membership_Store::get_member( $row['person_id'] ); ?>
								<tr>
									<td>
										<input type="checkbox" id="cak-sq-<?php echo (int) $team['id']; ?>-<?php echo (int) $row['person_id']; ?>" name="remove[<?php echo (int) $team['id']; ?>][]" value="<?php echo (int) $row['person_id']; ?>" />
										<label class="screen-reader-text" for="cak-sq-<?php echo (int) $team['id']; ?>-<?php echo (int) $row['person_id']; ?>"><?php echo esc_html( sprintf( /* translators: 1: person, 2: team */ __( 'Take %1$s out of %2$s', 'chess-army-knife' ), $member ? $member['name'] : __( 'this person', 'chess-army-knife' ), $team['name'] ) ); ?></label>
									</td>
									<td><?php echo esc_html( $member ? $member['name'] : Chess_Army_Knife_Membership_Store::erased_name() ); ?></td>
									<td>
										<?php
										if ( '' !== $row['last_played'] ) {
											echo esc_html( mysql2date( $date_format, $row['last_played'] ) );
										} else {
											/* translators: %s: the date the person was added to the squad */
											echo esc_html( sprintf( __( 'Not since they were added, %s', 'chess-army-knife' ), mysql2date( $date_format, $row['added'] ) ) );
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>

				<?php if ( $any ) : ?>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Take the ticked people out of their squads', 'chess-army-knife' ); ?></button></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Nobody has been away from a squad that long.', 'chess-army-knife' ); ?></p>
				<?php endif; ?>
			</form>
			<p class="description"><?php esc_html_e( 'Appearances come from the board results of the league import, so run Import Events first. A person added by hand who has not played yet is counted from the day they were added.', 'chess-army-knife' ); ?></p>
		</div>
		<?php
	}
}

Chess_Army_Knife_Squad_Review::init();
