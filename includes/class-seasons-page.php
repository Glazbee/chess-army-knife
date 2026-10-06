<?php
/**
 * The Members > Seasons screen: start a new season, go through what to do next, and download each season's
 * payments for the treasurer.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Seasons_Page {

	const SLUG = 'chess-army-knife-seasons';

	/**
	 * Hook up the screen's actions.
	 */
	public static function init() {
		add_action( 'admin_post_chess_army_knife_start_season', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_chess_army_knife_season_reviewed', array( __CLASS__, 'handle_reviewed' ) );
		add_action( 'admin_post_chess_army_knife_export_payments', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Stop anyone without the membership permission.
	 */
	protected static function require_permission() {
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * The address of this screen.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	protected static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Start a season from the form.
	 */
	public static function handle_start() {
		self::require_permission();
		check_admin_referer( 'chess_army_knife_start_season' );

		$name  = isset( $_POST['season_name'] ) ? sanitize_text_field( wp_unslash( $_POST['season_name'] ) ) : '';
		$start = isset( $_POST['season_start'] ) ? sanitize_text_field( wp_unslash( $_POST['season_start'] ) ) : '';

		// Starting a season marks everyone as unpaid, so it has to be confirmed.
		if ( Chess_Army_Knife_Membership_Seasons::current() && empty( $_POST['season_confirm'] ) ) {
			wp_safe_redirect( self::url( array( 'error' => 'season_confirm' ) ) );
			exit;
		}

		$result = Chess_Army_Knife_Membership_Seasons::start( $name, $start, ! empty( $_POST['season_empty_squads'] ) );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::url( array( 'error' => $result->get_error_code() ) ) );
			exit;
		}

		wp_safe_redirect( self::url( array( 'started' => 1 ) ) );
		exit;
	}

	/**
	 * Note that the checklist after a new season has been gone through.
	 */
	public static function handle_reviewed() {
		self::require_permission();
		check_admin_referer( 'chess_army_knife_season_reviewed' );

		Chess_Army_Knife_Membership_Seasons::mark_reviewed();

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Download a season's payments.
	 */
	public static function handle_export() {
		self::require_permission();

		$id = isset( $_GET['season'] ) ? absint( $_GET['season'] ) : 0;
		check_admin_referer( 'chess_army_knife_export_payments_' . $id );

		$season = Chess_Army_Knife_Membership_Seasons::get( $id );
		if ( ! $season ) {
			wp_safe_redirect( self::url( array( 'error' => 'season_missing' ) ) );
			exit;
		}

		Chess_Army_Knife_Payment_Export::download( $season );
	}

	/**
	 * The notice for the outcome of an action, from the address.
	 *
	 * @return array|null { type, message }, or null if there is nothing to say.
	 */
	protected static function notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen state; nothing is changed.
		if ( isset( $_GET['started'] ) ) {
			return array( 'success', __( 'The new season has started. Everyone is marked as not paid.', 'chess-army-knife' ) );
		}
		if ( isset( $_GET['error'] ) ) {
			$errors = array(
				'season_name'    => __( 'Please give the season a name, such as 2026/27.', 'chess-army-knife' ),
				'season_date'    => __( 'Please enter the start date as YYYY-MM-DD.', 'chess-army-knife' ),
				'season_order'   => __( 'The new season has to start after the current one.', 'chess-army-knife' ),
				'season_confirm' => __( 'Please tick the box to confirm that everyone should be marked as not paid.', 'chess-army-knife' ),
				'season_missing' => __( 'That season could not be found.', 'chess-army-knife' ),
			);
			$code   = sanitize_key( wp_unslash( $_GET['error'] ) );
			if ( isset( $errors[ $code ] ) ) {
				return array( 'error', $errors[ $code ] );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return null;
	}

	/**
	 * Render the screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Memberships::user_can_manage(), __( 'Seasons', 'chess-army-knife' ), 'members' ) ) {
			return;
		}

		$notice  = self::notice();
		$seasons = Chess_Army_Knife_Membership_Seasons::all();
		$current = $seasons ? $seasons[0] : null;
		$totals  = Chess_Army_Knife_Membership_Seasons::totals();
		$format  = get_option( 'date_format' );
		$today   = current_time( 'Y-m-d' );
		$unpaid  = Chess_Army_Knife_Membership_Store::count_view( 'unpaid' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Members', 'chess-army-knife' ); ?></h1>
			<?php Chess_Army_Knife_Member_Tabs::render( 'seasons' ); ?>
			<?php
			if ( $notice ) {
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
			}
			?>
			<p><?php esc_html_e( 'Membership is paid for each season. Starting a new season marks every member as not paid, so each has to pay again, and keeps the payments of the seasons before. How long someone has been a member comes from the seasons they have paid for.', 'chess-army-knife' ); ?></p>

			<?php if ( Chess_Army_Knife_Membership_Seasons::review_pending() ) : ?>
				<div class="notice notice-info inline">
					<h2><?php esc_html_e( 'Now that the season has started', 'chess-army-knife' ); ?></h2>
					<ol>
						<li>
							<?php esc_html_e( 'Check your teams: players and captains may have changed.', 'chess-army-knife' ); ?>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Teams::POST_TYPE ) ); ?>"><?php esc_html_e( 'Go to Teams', 'chess-army-knife' ); ?></a>
						</li>
						<li>
							<?php
							/* translators: %d: number of members who have not paid */
							echo esc_html( sprintf( _n( '%d member has not paid for the new season.', '%d members have not paid for the new season.', $unpaid, 'chess-army-knife' ), $unpaid ) );
							?>
							<a href="<?php echo esc_url( add_query_arg( 'view', 'unpaid', admin_url( 'admin.php?page=' . Chess_Army_Knife_Memberships::MENU_SLUG ) ) ); ?>"><?php esc_html_e( 'See who', 'chess-army-knife' ); ?></a>
						</li>
					</ol>
					<p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=chess_army_knife_season_reviewed' ), 'chess_army_knife_season_reviewed' ) ); ?>" class="button"><?php esc_html_e( 'Done', 'chess-army-knife' ); ?></a></p>
				</div>
			<?php endif; ?>

			<h2><?php echo $current ? esc_html__( 'Start a new season', 'chess-army-knife' ) : esc_html__( 'Start your first season', 'chess-army-knife' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="chess_army_knife_start_season" />
				<?php wp_nonce_field( 'chess_army_knife_start_season' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="season_name"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="season_name" name="season_name" class="regular-text" maxlength="<?php echo esc_attr( Chess_Army_Knife_Membership_Seasons::MAX_NAME_LENGTH ); ?>" value="<?php echo esc_attr( Chess_Army_Knife_Membership_Seasons::suggest_name( $today ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="season_start"><?php esc_html_e( 'First day', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="date" id="season_start" name="season_start" value="<?php echo esc_attr( $today ); ?>" />
							<p class="description"><?php esc_html_e( 'The season before ends the day before this.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<?php if ( $current ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Confirm', 'chess-army-knife' ); ?></th>
							<td>
								<label for="season_confirm"><input type="checkbox" id="season_confirm" name="season_confirm" value="1" /> <?php esc_html_e( 'Mark every member as not paid. Payments already recorded stay in the earlier season.', 'chess-army-knife' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Teams', 'chess-army-knife' ); ?></th>
							<td>
								<label for="season_empty_squads"><input type="checkbox" id="season_empty_squads" name="season_empty_squads" value="1" /> <?php esc_html_e( 'Start every squad empty', 'chess-army-knife' ); ?></label>
								<p class="description"><?php esc_html_e( 'The squads as they are now are kept for the season that ends. Unless you tick this, they carry on into the new season for you to review.', 'chess-army-knife' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
				</table>
				<?php if ( ! $current ) : ?>
					<p class="description"><?php esc_html_e( 'Payments already recorded on members are counted as paid for this first season, and nobody is marked as not paid.', 'chess-army-knife' ); ?></p>
				<?php endif; ?>
				<?php submit_button( $current ? __( 'Start new season', 'chess-army-knife' ) : __( 'Start first season', 'chess-army-knife' ), 'primary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Seasons', 'chess-army-knife' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<caption class="screen-reader-text"><?php esc_html_e( 'Membership seasons', 'chess-army-knife' ); ?></caption>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Season', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'First day', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Last day', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Payments', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Received', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'For the treasurer', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $seasons ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No season has been started yet.', 'chess-army-knife' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $seasons as $season ) : ?>
						<?php
						$total = isset( $totals[ $season['id'] ] ) ? $totals[ $season['id'] ] : array(
							'count' => 0,
							'total' => 0,
						);
						?>
						<tr>
							<td><?php echo esc_html( $season['name'] ); ?><?php echo $current && $season['id'] === $current['id'] ? ' <span class="description">' . esc_html__( '(current)', 'chess-army-knife' ) . '</span>' : ''; ?></td>
							<td><?php echo esc_html( mysql2date( $format, $season['start_date'] ) ); ?></td>
							<td><?php echo '' === $season['end_date'] ? esc_html__( 'Until the next season', 'chess-army-knife' ) : esc_html( mysql2date( $format, $season['end_date'] ) ); ?></td>
							<td><?php echo esc_html( (string) $total['count'] ); ?></td>
							<td><?php echo esc_html( Chess_Army_Knife_Memberships::currency_symbol() . number_format_i18n( $total['total'] / 100, 2 ) ); ?></td>
							<td>
								<?php if ( $total['count'] ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=chess_army_knife_export_payments&season=' . $season['id'] ), 'chess_army_knife_export_payments_' . $season['id'] ) ); ?>"><?php esc_html_e( 'Download payments (CSV)', 'chess-army-knife' ); ?></a>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'The file has each payment\'s nickname, last name, date, type, bank transfer reference and amount, and opens in Excel.', 'chess-army-knife' ); ?></p>

			<?php if ( $seasons ) : ?>
				<h2><?php esc_html_e( 'Squads by season', 'chess-army-knife' ); ?></h2>
				<?php foreach ( $seasons as $season ) : ?>
					<?php self::render_squads( $season, $season['id'] === $current['id'] ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The squads of a season: the teams as they stand now for the season running, or as they stood when an earlier
	 * season ended.
	 *
	 * @param array $season     Season (see Chess_Army_Knife_Membership_Seasons::all()).
	 * @param bool  $is_current Whether it is the season running.
	 */
	protected static function render_squads( array $season, $is_current ) {
		$teams  = Chess_Army_Knife_Teams::choices();
		$squads = array();
		if ( $is_current ) {
			foreach ( array_keys( $teams ) as $team_id ) {
				$squads[ $team_id ] = Chess_Army_Knife_Teams::squad( $team_id );
			}
		} else {
			$squads = Chess_Army_Knife_Teams::squads_of_season( $season['id'] );
		}

		$ids = array();
		foreach ( $squads as $team_id => $people ) {
			$ids = array_merge( $ids, isset( $teams[ $team_id ] ) ? $people : array() );
		}
		$names = array();
		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_ids( $ids ) as $member ) {
			$names[ $member['id'] ] = Chess_Army_Knife_Names::person( $member, Chess_Army_Knife_Names::site_style() );
		}
		?>
		<details>
			<summary><?php echo esc_html( $season['name'] . ( $is_current ? ' ' . __( '(current)', 'chess-army-knife' ) : '' ) ); ?></summary>
			<?php
			$shown = false;
			foreach ( $teams as $team_id => $team_name ) {
				if ( empty( $squads[ $team_id ] ) ) {
					continue;
				}
				$shown = true;
				$list  = array();
				foreach ( $squads[ $team_id ] as $person_id ) {
					if ( isset( $names[ $person_id ] ) ) {
						$list[] = $names[ $person_id ];
					}
				}
				sort( $list );
				echo '<p><strong>' . esc_html( $team_name ) . '</strong> (' . esc_html( (string) count( $list ) ) . '): ' . esc_html( implode( ', ', $list ) ) . '</p>';
			}
			if ( ! $shown ) {
				echo '<p class="description">' . esc_html__( 'No squads were kept for this season.', 'chess-army-knife' ) . '</p>';
			}
			?>
		</details>
		<?php
	}
}

Chess_Army_Knife_Seasons_Page::init();
