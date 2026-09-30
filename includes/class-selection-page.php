<?php
/**
 * The Team Selection screen: a captain (or an officer) sees the upcoming
 * fixtures of their teams, asks the squad whether they can play, sees the
 * replies, builds the line-up board by board and publishes it.
 *
 * Squads, replies and line-ups are personal data, so the screen is open only
 * to captains of the team (see Chess_Army_Knife_Captains) and people with the
 * membership permission, and each action checks the team again.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Selection_Page {

	const SLUG = 'chess-army-knife-selection';

	/**
	 * Hook up the screen and its actions.
	 */
	public static function init() {
		add_action( 'admin_post_chess_army_knife_selection_request', array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_post_chess_army_knife_selection_lineup', array( __CLASS__, 'handle_lineup' ) );
	}

	/**
	 * The address of a screen.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	protected static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Check that the current user may work on a fixture, and return it.
	 *
	 * @param int $event_id Event id.
	 * @param int $team_id  Team id.
	 * @return array The fixture (see Chess_Army_Knife_Selection::fixture()).
	 */
	protected static function require_fixture( $event_id, $team_id ) {
		$fixture = Chess_Army_Knife_Selection::fixture( $event_id, $team_id );
		if ( ! $fixture || ! Chess_Army_Knife_Captains::can_manage_team( $team_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), '', array( 'response' => 403 ) );
		}
		return $fixture;
	}

	/**
	 * Ask the squad, or remind those who have not replied.
	 */
	public static function handle_request() {
		$event_id = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		$team_id  = isset( $_POST['team'] ) ? absint( $_POST['team'] ) : 0;
		check_admin_referer( 'chess_army_knife_selection_request_' . $event_id . '_' . $team_id );
		self::require_fixture( $event_id, $team_id );

		$queued = Chess_Army_Knife_Selection::request( $event_id, $team_id );

		wp_safe_redirect(
			self::url(
				array(
					'event' => $event_id,
					'team'  => $team_id,
					'asked' => $queued,
				)
			)
		);
		exit;
	}

	/**
	 * Save, suggest or publish the line-up.
	 */
	public static function handle_lineup() {
		$event_id = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		$team_id  = isset( $_POST['team'] ) ? absint( $_POST['team'] ) : 0;
		check_admin_referer( 'chess_army_knife_selection_lineup_' . $event_id . '_' . $team_id );
		$fixture = self::require_fixture( $event_id, $team_id );

		$button = isset( $_POST['lineup_action'] ) ? sanitize_key( wp_unslash( $_POST['lineup_action'] ) ) : 'save';
		$args   = array(
			'event' => $event_id,
			'team'  => $team_id,
		);

		if ( ! Chess_Army_Knife_Selection::is_open( $fixture['event'] ) ) {
			$args['error'] = 'started';
		} elseif ( 'suggest' === $button ) {
			Chess_Army_Knife_Selection::save_draft( $event_id, $team_id, Chess_Army_Knife_Selection::suggest( $event_id, $team_id ) );
			$args['saved'] = 'suggested';
		} else {
			$boards = array();
			$posted = isset( $_POST['board'] ) && is_array( $_POST['board'] ) ? array_map( 'absint', wp_unslash( $_POST['board'] ) ) : array();
			foreach ( $posted as $board => $person_id ) {
				$boards[ absint( $board ) ] = $person_id;
			}
			Chess_Army_Knife_Selection::save_draft( $event_id, $team_id, $boards );
			if ( 'publish' === $button ) {
				$args['published'] = Chess_Army_Knife_Selection::publish( $event_id, $team_id );
			} else {
				$args['saved'] = 'draft';
			}
		}

		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Captains::user_can_select(), __( 'Team Selection', 'chess-army-knife' ), 'selection' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state; nothing is changed.
		if ( isset( $_GET['event'], $_GET['team'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state; nothing is changed.
			self::render_fixture( self::require_fixture( absint( $_GET['event'] ), absint( $_GET['team'] ) ) );
			return;
		}
		self::render_list();
	}

	/**
	 * The upcoming fixtures of the teams the user may pick.
	 */
	protected static function render_list() {
		$teams    = Chess_Army_Knife_Captains::teams_for_user();
		$fixtures = Chess_Army_Knife_Selection::fixtures( wp_list_pluck( $teams, 'id' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Team Selection', 'chess-army-knife' ); ?></h1>
			<?php if ( ! $teams ) : ?>
				<p><?php esc_html_e( 'You do not captain any team yet. Ask a club officer to set you as a team\'s captain.', 'chess-army-knife' ); ?></p>
			<?php endif; ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Fixture', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Team', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Replies', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Line-up', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $fixtures ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No upcoming fixtures. Fixtures come from Import Events once your teams are linked to their leagues.', 'chess-army-knife' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $fixtures as $item ) : ?>
						<?php
						$event   = $item['event'];
						$team    = $item['team'];
						$replies = Chess_Army_Knife_Selection::availability( $event['id'], $team['id'] );
						$counts  = array_count_values( wp_list_pluck( $replies, 'response' ) );
						$draft   = Chess_Army_Knife_Selection::lineup( $event['id'], $team['id'], false );
						$live    = Chess_Army_Knife_Selection::lineup( $event['id'], $team['id'], true );
						?>
						<tr>
							<td><?php echo esc_html( Chess_Army_Knife_Events_Display::date_label( $event ) . ' ' . Chess_Army_Knife_Events_Display::time_label( $event ) ); ?></td>
							<td><a href="
							<?php
							echo esc_url(
								self::url(
									array(
										'event' => $event['id'],
										'team'  => $team['id'],
									)
								)
							);
							?>
											"><?php echo esc_html( $event['title'] ); ?></a></td>
							<td><?php echo esc_html( $team['name'] ); ?></td>
							<td>
								<?php
								/* translators: 1: number saying yes, 2: maybe, 3: no, 4: not replied */
								echo esc_html( sprintf( __( '%1$d yes, %2$d maybe, %3$d no, %4$d waiting', 'chess-army-knife' ), isset( $counts['yes'] ) ? $counts['yes'] : 0, isset( $counts['maybe'] ) ? $counts['maybe'] : 0, isset( $counts['no'] ) ? $counts['no'] : 0, isset( $counts[''] ) ? $counts[''] : 0 ) );
								?>
							</td>
							<td>
								<?php
								if ( $live ) {
									esc_html_e( 'Published', 'chess-army-knife' );
								} elseif ( $draft ) {
									esc_html_e( 'Draft', 'chess-army-knife' );
								} else {
									echo '&mdash;';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * One fixture: replies and line-up.
	 *
	 * @param array $fixture { event, team }.
	 */
	protected static function render_fixture( array $fixture ) {
		$event   = $fixture['event'];
		$team    = $fixture['team'];
		$pool    = Chess_Army_Knife_Selection::pool( $team['id'] );
		$replies = Chess_Army_Knife_Selection::availability( $event['id'], $team['id'] );
		$draft   = Chess_Army_Knife_Selection::lineup( $event['id'], $team['id'], false );
		$live    = Chess_Army_Knife_Selection::lineup( $event['id'], $team['id'], true );
		$boards  = Chess_Army_Knife_Captains::boards( $team['id'] );
		$open    = Chess_Army_Knife_Selection::is_open( $event );
		$names   = wp_list_pluck( $pool, 'name', 'id' );
		$labels  = array(
			'yes'   => __( 'Yes', 'chess-army-knife' ),
			'maybe' => __( 'Maybe', 'chess-army-knife' ),
			'no'    => __( 'No', 'chess-army-knife' ),
			''      => __( 'No reply yet', 'chess-army-knife' ),
		);
		$asked   = false;
		$waiting = 0;
		foreach ( $pool as $person ) {
			if ( isset( $replies[ $person['id'] ] ) ) {
				$asked    = true;
				$waiting += '' === $replies[ $person['id'] ]['response'] ? 1 : 0;
			} else {
				++$waiting;
			}
		}
		// A draft that differs from what was published has changes that have not been sent.
		$unpublished = $draft !== $live;
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $event['title'] ); ?></h1>
			<p>
				<a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All fixtures', 'chess-army-knife' ); ?></a>
				&middot; <?php echo esc_html( $team['name'] . ' — ' . Chess_Army_Knife_Events_Display::date_label( $event ) . ' ' . Chess_Army_Knife_Events_Display::time_label( $event ) ); ?>
				<?php echo '' !== $event['location'] ? '&middot; ' . esc_html( $event['location'] ) : ''; ?>
			</p>

			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notices.
			if ( isset( $_GET['asked'] ) ) {
				/* translators: %d: number of emails */
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d email queued.', '%d emails queued.', absint( $_GET['asked'] ), 'chess-army-knife' ), absint( $_GET['asked'] ) ) ) );
			} elseif ( isset( $_GET['published'] ) ) {
				/* translators: %d: number of emails */
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( 'Line-up published. %d email queued.', 'Line-up published. %d emails queued.', absint( $_GET['published'] ), 'chess-army-knife' ), absint( $_GET['published'] ) ) ) );
			} elseif ( isset( $_GET['saved'] ) ) {
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( 'suggested' === $_GET['saved'] ? __( 'Suggested line-up saved as a draft. Check it, then publish.', 'chess-army-knife' ) : __( 'Draft saved. Nobody has been told.', 'chess-army-knife' ) ) );
			} elseif ( isset( $_GET['error'] ) ) {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'This fixture has already started.', 'chess-army-knife' ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			?>

			<h2><?php esc_html_e( 'Availability', 'chess-army-knife' ); ?></h2>
			<?php if ( ! $pool ) : ?>
				<p><?php esc_html_e( 'This team\'s squad has no current members. Ask a club officer to add them on the team\'s page under Teams.', 'chess-army-knife' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:640px">
					<thead><tr><th><?php esc_html_e( 'Player', 'chess-army-knife' ); ?></th><th><?php esc_html_e( 'Rating', 'chess-army-knife' ); ?></th><th><?php esc_html_e( 'Reply', 'chess-army-knife' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $pool as $person ) : ?>
							<?php $reply = isset( $replies[ $person['id'] ] ) ? $replies[ $person['id'] ]['response'] : ''; ?>
							<tr>
								<td><?php echo esc_html( $person['name'] ); ?></td>
								<td><?php echo esc_html( (string) Chess_Army_Knife_Selection::rating_of( $person ) ); ?></td>
								<td><?php echo esc_html( $labels[ $reply ] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $open && $waiting > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="chess_army_knife_selection_request" />
						<input type="hidden" name="event" value="<?php echo esc_attr( $event['id'] ); ?>" />
						<input type="hidden" name="team" value="<?php echo esc_attr( $team['id'] ); ?>" />
						<?php wp_nonce_field( 'chess_army_knife_selection_request_' . $event['id'] . '_' . $team['id'] ); ?>
						<p><button type="submit" class="button"><?php echo esc_html( $asked ? __( 'Remind those who have not replied', 'chess-army-knife' ) : __( 'Ask the squad if they can play', 'chess-army-knife' ) ); ?></button></p>
					</form>
				<?php endif; ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Line-up', 'chess-army-knife' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Only you and club officers can see the draft. Players are told only when you publish.', 'chess-army-knife' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="chess_army_knife_selection_lineup" />
				<input type="hidden" name="event" value="<?php echo esc_attr( $event['id'] ); ?>" />
				<input type="hidden" name="team" value="<?php echo esc_attr( $team['id'] ); ?>" />
				<?php wp_nonce_field( 'chess_army_knife_selection_lineup_' . $event['id'] . '_' . $team['id'] ); ?>
				<table class="form-table" role="presentation">
					<?php for ( $board = 1; $board <= $boards; $board++ ) : ?>
						<tr>
							<th scope="row"><label for="cak-board-<?php echo esc_attr( $board ); ?>"><?php /* translators: %d: board number */ echo esc_html( sprintf( __( 'Board %d', 'chess-army-knife' ), $board ) ); ?></label></th>
							<td>
								<select id="cak-board-<?php echo esc_attr( $board ); ?>" name="board[<?php echo esc_attr( $board ); ?>]">
									<option value="0"><?php esc_html_e( '— empty —', 'chess-army-knife' ); ?></option>
									<?php foreach ( $pool as $person ) : ?>
										<?php $reply = isset( $replies[ $person['id'] ] ) ? $replies[ $person['id'] ]['response'] : ''; ?>
										<option value="<?php echo esc_attr( $person['id'] ); ?>" <?php selected( isset( $draft[ $board ] ) ? $draft[ $board ] : 0, $person['id'] ); ?>>
											<?php echo esc_html( $person['name'] . ' (' . Chess_Army_Knife_Selection::rating_of( $person ) . ') — ' . $labels[ $reply ] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( isset( $live[ $board ] ) ) : ?>
									<span class="description">
										<?php
										/* translators: %s: player's name */
										echo esc_html( sprintf( __( 'Published: %s', 'chess-army-knife' ), isset( $names[ $live[ $board ] ] ) ? $names[ $live[ $board ] ] : '' ) );
										?>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endfor; ?>
				</table>
				<?php if ( $unpublished && $live ) : ?>
					<p class="description"><?php esc_html_e( 'The draft differs from the published line-up. Publishing tells the players who were added or dropped.', 'chess-army-knife' ); ?></p>
				<?php endif; ?>
				<?php if ( $open ) : ?>
					<p>
						<button type="submit" name="lineup_action" value="save" class="button"><?php esc_html_e( 'Save draft', 'chess-army-knife' ); ?></button>
						<button type="submit" name="lineup_action" value="suggest" class="button"><?php esc_html_e( 'Fill from replies (best rated first)', 'chess-army-knife' ); ?></button>
						<button type="submit" name="lineup_action" value="publish" class="button button-primary"><?php esc_html_e( 'Publish and tell the players', 'chess-army-knife' ); ?></button>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'This fixture has started, so the line-up can no longer be changed.', 'chess-army-knife' ); ?></p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}
}

Chess_Army_Knife_Selection_Page::init();
