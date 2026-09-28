<?php
/**
 * "Tournaments" admin page: create tournaments, enter players, start them,
 * record results and view standings.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Tournaments_Page {

	const SLUG = 'chess-army-knife-tournaments';

	/**
	 * Boot the admin page and its form handlers.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		foreach ( array( 'create', 'add_player', 'remove_player', 'start', 'save_results', 'withdraw', 'delete' ) as $action ) {
			add_action( 'admin_post_chess_army_knife_tournament_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Register the submenu page.
	 */
	public static function add_menu() {
		add_submenu_page(
			'chess-army-knife',
			__( 'Tournaments', 'chess-army-knife' ),
			__( 'Tournaments', 'chess-army-knife' ),
			Chess_Army_Knife_Tournaments::capability(),
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/* -------------------------------------------------------------
	 * Form handlers
	 * ------------------------------------------------------------- */

	/**
	 * Check permission and nonce for a handler.
	 *
	 * @param string $action Short action name (also the nonce action suffix).
	 */
	protected static function authorise( $action ) {
		if ( ! Chess_Army_Knife_Tournaments::user_can_manage() || ! check_admin_referer( 'chess_army_knife_tournament_' . $action ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ) );
		}
	}

	/**
	 * Remember a message to show after the redirect, then redirect and exit.
	 *
	 * @param true|WP_Error|int $outcome       Result of the operation.
	 * @param string            $success_text  Message on success.
	 * @param int               $tournament_id Tournament to return to (0 for the list).
	 */
	protected static function finish( $outcome, $success_text, $tournament_id = 0 ) {
		$is_error = is_wp_error( $outcome );
		set_transient(
			'chess_army_knife_notice_' . get_current_user_id(),
			array(
				'type'    => $is_error ? 'error' : 'success',
				'message' => $is_error ? $outcome->get_error_message() : $success_text,
			),
			MINUTE_IN_SECONDS
		);

		$args = array( 'page' => self::SLUG );
		if ( $tournament_id ) {
			$args['tournament'] = (int) $tournament_id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Create a tournament.
	 */
	public static function handle_create() {
		self::authorise( 'create' );

		$result = Chess_Army_Knife_Tournaments::create(
			array(
				'name'          => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'format'        => isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : '',
				'rating_domain' => isset( $_POST['rating_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['rating_domain'] ) ) : 'S',
				'double_round'  => ! empty( $_POST['double_round'] ),
				'groups'        => isset( $_POST['groups'] ) ? (int) $_POST['groups'] : 1,
				'advance'       => isset( $_POST['advance'] ) ? (int) $_POST['advance'] : 0,
			)
		);

		self::finish( $result, __( 'Tournament created. Add players, then start it.', 'chess-army-knife' ), is_wp_error( $result ) ? 0 : $result );
	}

	/**
	 * Add a player to a tournament.
	 */
	public static function handle_add_player() {
		self::authorise( 'add_player' );

		$tournament_id = isset( $_POST['tournament_id'] ) ? (int) $_POST['tournament_id'] : 0;
		$player_id     = isset( $_POST['player_id'] ) ? (int) $_POST['player_id'] : 0;

		self::finish( Chess_Army_Knife_Tournaments::add_player( $tournament_id, $player_id ), __( 'Player added.', 'chess-army-knife' ), $tournament_id );
	}

	/**
	 * Remove a player from a draft tournament.
	 */
	public static function handle_remove_player() {
		self::authorise( 'remove_player' );

		$tournament_id = isset( $_GET['tournament_id'] ) ? (int) $_GET['tournament_id'] : 0;
		$entry_id      = isset( $_GET['entry_id'] ) ? (int) $_GET['entry_id'] : 0;

		self::finish( Chess_Army_Knife_Tournaments::remove_player( $tournament_id, $entry_id ), __( 'Player removed.', 'chess-army-knife' ), $tournament_id );
	}

	/**
	 * Start a tournament.
	 */
	public static function handle_start() {
		self::authorise( 'start' );

		$tournament_id = isset( $_POST['tournament_id'] ) ? (int) $_POST['tournament_id'] : 0;

		self::finish( Chess_Army_Knife_Tournaments::start( $tournament_id ), __( 'Tournament started. Ratings were recorded and pairings generated.', 'chess-army-knife' ), $tournament_id );
	}

	/**
	 * Save every result submitted for a round.
	 */
	public static function handle_save_results() {
		self::authorise( 'save_results' );

		$tournament_id = isset( $_POST['tournament_id'] ) ? (int) $_POST['tournament_id'] : 0;
		$results       = isset( $_POST['results'] ) && is_array( $_POST['results'] ) ? wp_unslash( $_POST['results'] ) : array();
		$outcome       = true;

		foreach ( $results as $game_id => $result ) {
			$game = Chess_Army_Knife_Tournament_Store::get_game( (int) $game_id );
			$new  = sanitize_text_field( $result );
			if ( ! $game || $game['tournament_id'] !== $tournament_id || (string) $game['result'] === $new ) {
				continue;
			}
			$saved = Chess_Army_Knife_Tournaments::record_result( $game['id'], $new );
			if ( is_wp_error( $saved ) ) {
				$outcome = $saved;
				break;
			}
		}

		self::finish( $outcome, __( 'Results saved.', 'chess-army-knife' ), $tournament_id );
	}

	/**
	 * Withdraw a player from a running tournament.
	 */
	public static function handle_withdraw() {
		self::authorise( 'withdraw' );

		$tournament_id = isset( $_GET['tournament_id'] ) ? (int) $_GET['tournament_id'] : 0;
		$entry_id      = isset( $_GET['entry_id'] ) ? (int) $_GET['entry_id'] : 0;

		self::finish( Chess_Army_Knife_Tournaments::withdraw( $entry_id ), __( 'Player withdrawn.', 'chess-army-knife' ), $tournament_id );
	}

	/**
	 * Delete a tournament.
	 */
	public static function handle_delete() {
		self::authorise( 'delete' );

		Chess_Army_Knife_Tournament_Store::delete_tournament( isset( $_GET['tournament_id'] ) ? (int) $_GET['tournament_id'] : 0 );

		self::finish( true, __( 'Tournament deleted.', 'chess-army-knife' ) );
	}

	/* -------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------- */

	/**
	 * Build a nonce-protected admin-post link.
	 *
	 * @param string $action Short action name.
	 * @param array  $args   Extra query args.
	 * @return string
	 */
	protected static function action_url( $action, array $args ) {
		$args['action'] = 'chess_army_knife_tournament_' . $action;
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'chess_army_knife_tournament_' . $action );
	}

	/**
	 * Print the one-off notice left by a handler, if any.
	 */
	protected static function render_notice() {
		$key    = 'chess_army_knife_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			Chess_Army_Knife_Tournaments::STATUS_DRAFT    => __( 'Not started', 'chess-army-knife' ),
			Chess_Army_Knife_Tournaments::STATUS_ACTIVE   => __( 'In progress', 'chess-army-knife' ),
			Chess_Army_Knife_Tournaments::STATUS_COMPLETE => __( 'Complete', 'chess-army-knife' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Render the admin page (list or single tournament).
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Tournaments::user_can_manage() ) {
			return;
		}

		echo '<div class="wrap">';
		self::render_notice();

		$tournament = isset( $_GET['tournament'] ) ? Chess_Army_Knife_Tournament_Store::get_tournament( (int) $_GET['tournament'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $tournament ) {
			self::render_tournament( $tournament );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * Render the tournament list and the "create" form.
	 */
	protected static function render_list() {
		$tournaments = Chess_Army_Knife_Tournament_Store::get_tournaments();
		?>
		<h1><?php esc_html_e( 'Tournaments', 'chess-army-knife' ); ?></h1>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Format', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Status', 'chess-army-knife' ); ?></th>
					<th style="width:100px;"></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $tournaments ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No tournaments yet.', 'chess-army-knife' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $tournaments as $tournament ) : ?>
					<?php $formats = Chess_Army_Knife_Tournaments::formats(); ?>
					<tr>
						<td><?php echo esc_html( $tournament['name'] ); ?></td>
						<td><?php echo esc_html( isset( $formats[ $tournament['format'] ] ) ? $formats[ $tournament['format'] ] : $tournament['format'] ); ?></td>
						<td><?php echo esc_html( self::status_label( $tournament['status'] ) ); ?></td>
						<td><a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page'       => self::SLUG,
									'tournament' => $tournament['id'],
								),
								admin_url( 'admin.php' )
							)
						);
						?>
										"><?php esc_html_e( 'Manage', 'chess-army-knife' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Create a tournament', 'chess-army-knife' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="chess_army_knife_tournament_create" />
			<?php wp_nonce_field( 'chess_army_knife_tournament_create' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="name"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></label></th>
					<td><input type="text" id="name" name="name" class="regular-text" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="format"><?php esc_html_e( 'Format', 'chess-army-knife' ); ?></label></th>
					<td>
						<select id="format" name="format">
							<?php foreach ( Chess_Army_Knife_Tournaments::formats() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rating_domain"><?php esc_html_e( 'Rating list for seeding', 'chess-army-knife' ); ?></label></th>
					<td>
						<select id="rating_domain" name="rating_domain">
							<option value="S"><?php esc_html_e( 'Standard', 'chess-army-knife' ); ?></option>
							<option value="R"><?php esc_html_e( 'Rapid', 'chess-army-knife' ); ?></option>
							<option value="B"><?php esc_html_e( 'Blitz', 'chess-army-knife' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Double round-robin', 'chess-army-knife' ); ?></th>
					<td><label><input type="checkbox" name="double_round" value="1" /> <?php esc_html_e( 'Everyone plays everyone twice, once with each colour', 'chess-army-knife' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="groups"><?php esc_html_e( 'Groups', 'chess-army-knife' ); ?></label></th>
					<td>
						<input type="number" id="groups" name="groups" min="1" max="16" value="1" class="small-text" />
						<p class="description"><?php esc_html_e( 'Round-robin only. Split the players into this many groups, dealt out by seed so each group has a similar mix of strengths.', 'chess-army-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="advance"><?php esc_html_e( 'Players advancing to a knockout stage', 'chess-army-knife' ); ?></label></th>
					<td>
						<input type="number" id="advance" name="advance" min="0" value="0" class="small-text" />
						<p class="description"><?php esc_html_e( 'Round-robin only. How many players from each group go into a knockout stage (0 for none). For example, 4 groups with 2 advancing gives an 8-player knockout. Knockout tournaments ignore the group settings.', 'chess-army-knife' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Create tournament', 'chess-army-knife' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render a single tournament.
	 *
	 * @param array $tournament Tournament row.
	 */
	protected static function render_tournament( array $tournament ) {
		$id      = $tournament['id'];
		$entries = Chess_Army_Knife_Tournament_Store::get_entries( $id );
		$back    = add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
		$draft   = Chess_Army_Knife_Tournaments::STATUS_DRAFT === $tournament['status'];
		$config  = Chess_Army_Knife_Tournaments::config( $tournament );
		$grouped = $config['groups'] > 1 && ! $draft;
		$formats = Chess_Army_Knife_Tournaments::formats();
		?>
		<h1><?php echo esc_html( $tournament['name'] ); ?></h1>
		<p>
			<a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'All tournaments', 'chess-army-knife' ); ?></a>
			&middot; <?php echo esc_html( self::status_label( $tournament['status'] ) ); ?>
			&middot; <?php echo esc_html( isset( $formats[ $tournament['format'] ] ) ? $formats[ $tournament['format'] ] : $tournament['format'] ); ?>
			<?php if ( $config['groups'] > 1 || $config['advance'] > 0 ) : ?>
				<?php
				echo esc_html(
					' ('
					/* translators: 1: number of groups, 2: players advancing per group */
					. sprintf( __( '%1$d group(s), top %2$d advance', 'chess-army-knife' ), $config['groups'], $config['advance'] )
					. ')'
				);
				?>
			<?php endif; ?>
			&middot;
			<a
				href="<?php echo esc_url( self::action_url( 'delete', array( 'tournament_id' => $id ) ) ); ?>"
				onclick="return confirm('<?php echo esc_js( __( 'Delete this tournament and all its games?', 'chess-army-knife' ) ); ?>');"
			><?php esc_html_e( 'Delete', 'chess-army-knife' ); ?></a>
		</p>

		<h2><?php esc_html_e( 'Players', 'chess-army-knife' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:60px;"><?php esc_html_e( 'Seed', 'chess-army-knife' ); ?></th>
					<?php if ( $grouped ) : ?>
						<th style="width:90px;"><?php esc_html_e( 'Group', 'chess-army-knife' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Rating at start', 'chess-army-knife' ); ?></th>
					<th style="width:120px;"></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $entries ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No players entered yet.', 'chess-army-knife' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo null === $entry['seed'] ? '&mdash;' : esc_html( $entry['seed'] ); ?></td>
						<?php if ( $grouped ) : ?>
							<td><?php echo $entry['group_no'] ? esc_html( chr( 64 + $entry['group_no'] ) ) : '&mdash;'; ?></td>
						<?php endif; ?>
						<td>
							<?php echo esc_html( $entry['name'] ); ?>
							<?php if ( 'withdrawn' === $entry['status'] ) : ?>
								<em>(<?php esc_html_e( 'withdrawn', 'chess-army-knife' ); ?>)</em>
							<?php endif; ?>
						</td>
						<td>
							<?php
							if ( $draft ) {
								echo '&mdash;';
							} elseif ( null === $entry['start_rating'] ) {
								esc_html_e( 'Unrated', 'chess-army-knife' );
							} else {
								echo esc_html( $entry['start_rating'] . ( 'manual' === $entry['rating_source'] ? ' ' . __( '(manual)', 'chess-army-knife' ) : '' ) );
							}
							?>
						</td>
						<td>
							<?php if ( $draft ) : ?>
								<a href="
								<?php
								echo esc_url(
									self::action_url(
										'remove_player',
										array(
											'tournament_id' => $id,
											'entry_id' => $entry['id'],
										)
									)
								);
								?>
											"><?php esc_html_e( 'Remove', 'chess-army-knife' ); ?></a>
							<?php elseif ( Chess_Army_Knife_Tournaments::STATUS_ACTIVE === $tournament['status'] && 'active' === $entry['status'] ) : ?>
								<a
									href="
									<?php
									echo esc_url(
										self::action_url(
											'withdraw',
											array(
												'tournament_id' => $id,
												'entry_id' => $entry['id'],
											)
										)
									);
									?>
											"
									onclick="return confirm('<?php echo esc_js( __( 'Withdraw this player? Their unplayed games will be dropped.', 'chess-army-knife' ) ); ?>');"
								><?php esc_html_e( 'Withdraw', 'chess-army-knife' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $draft ) : ?>
			<?php self::render_draft_controls( $tournament, $entries ); ?>
		<?php else : ?>
			<?php self::render_standings( $tournament ); ?>
			<?php self::render_games( $tournament, $entries ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Controls shown before a tournament starts: add players, start.
	 *
	 * @param array   $tournament Tournament row.
	 * @param array[] $entries    Current entrants.
	 */
	protected static function render_draft_controls( array $tournament, array $entries ) {
		$entered   = array_column( $entries, 'player_id' );
		$available = array_filter(
			Chess_Army_Knife_Tournament_Store::get_players(),
			function ( $player ) use ( $entered ) {
				return ! in_array( $player['id'], $entered, true );
			}
		);
		?>
		<h2><?php esc_html_e( 'Add a player', 'chess-army-knife' ); ?></h2>
		<?php if ( empty( $available ) ) : ?>
			<p>
				<?php esc_html_e( 'No more saved players to add.', 'chess-army-knife' ); ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => Chess_Army_Knife_Players_Page::SLUG ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Manage players', 'chess-army-knife' ); ?></a>
			</p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="chess_army_knife_tournament_add_player" />
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament['id'] ); ?>" />
				<?php wp_nonce_field( 'chess_army_knife_tournament_add_player' ); ?>
				<select name="player_id">
					<?php foreach ( $available as $player ) : ?>
						<option value="<?php echo esc_attr( $player['id'] ); ?>"><?php echo esc_html( $player['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Add player', 'chess-army-knife' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Start the tournament', 'chess-army-knife' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Starting fetches each player\'s current ECF rating (or uses their manual rating), seeds the players and generates the pairings. Later rating changes do not affect seeding. Players can no longer be added or removed afterwards.', 'chess-army-knife' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="chess_army_knife_tournament_start" />
			<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament['id'] ); ?>" />
			<?php wp_nonce_field( 'chess_army_knife_tournament_start' ); ?>
			<?php submit_button( __( 'Start tournament', 'chess-army-knife' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Standings tables: one per group, or a single table. Not shown for a
	 * pure knockout tournament, which has a bracket instead.
	 *
	 * @param array $tournament Tournament row.
	 */
	protected static function render_standings( array $tournament ) {
		if ( 'knockout' === $tournament['format'] ) {
			return;
		}

		$config = Chess_Army_Knife_Tournaments::config( $tournament );
		if ( $config['groups'] > 1 ) {
			for ( $group = 1; $group <= $config['groups']; $group++ ) {
				self::render_standings_table(
					Chess_Army_Knife_Tournaments::standings( $tournament['id'], $group ),
					Chess_Army_Knife_Tournaments::group_label( $group ),
					$config['advance']
				);
			}
			return;
		}

		self::render_standings_table( Chess_Army_Knife_Tournaments::standings( $tournament['id'], 0 ), __( 'Standings', 'chess-army-knife' ), $config['advance'] );
	}

	/**
	 * One standings table.
	 *
	 * @param array[] $rows    Ranked rows.
	 * @param string  $heading Heading text.
	 * @param int     $advance Number of top places that advance (highlighted); 0 for none.
	 */
	protected static function render_standings_table( array $rows, $heading, $advance ) {
		?>
		<h2><?php echo esc_html( $heading ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:50px;">#</th>
					<th><?php esc_html_e( 'Player', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Played', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'W', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'D', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'L', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Points', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'SB', 'chess-army-knife' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr<?php echo ( $advance > 0 && $row['rank'] <= $advance ) ? ' style="font-weight:600;"' : ''; ?>>
						<td><?php echo esc_html( $row['rank'] ); ?></td>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td><?php echo esc_html( $row['played'] ); ?></td>
						<td><?php echo esc_html( $row['won'] ); ?></td>
						<td><?php echo esc_html( $row['drawn'] ); ?></td>
						<td><?php echo esc_html( $row['lost'] ); ?></td>
						<td><strong><?php echo esc_html( self::format_points( $row['points'] ) ); ?></strong></td>
						<td><?php echo esc_html( self::format_points( $row['sonneborn_berger'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $advance > 0 ) : ?>
			<p class="description">
				<?php
				/* translators: %d: number of players advancing */
				echo esc_html( sprintf( _n( 'The top %d player advances to the knockout stage.', 'The top %d players advance to the knockout stage.', $advance, 'chess-army-knife' ), $advance ) );
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Format a points value, showing halves as ½.
	 *
	 * @param float $points Points.
	 * @return string
	 */
	public static function format_points( $points ) {
		$whole = (int) floor( $points );
		$half  = ( $points - $whole ) >= 0.5;
		if ( ! $half ) {
			return (string) $whole;
		}
		return ( 0 === $whole ? '' : $whole ) . '½';
	}

	/**
	 * Result choices for a game.
	 *
	 * @param bool $knockout Whether the game is in the knockout stage (a draw leads to a tie-break game).
	 * @return array Value => label.
	 */
	public static function result_options( $knockout ) {
		return array(
			''        => __( 'Not played', 'chess-army-knife' ),
			'1-0'     => '1-0',
			'0-1'     => '0-1',
			'1/2-1/2' => $knockout ? __( '½-½ (tie-break follows)', 'chess-army-knife' ) : '½-½',
		);
	}

	/**
	 * Games grouped by stage and round, each with a result selector.
	 *
	 * @param array   $tournament Tournament row.
	 * @param array[] $entries    Entrants.
	 */
	protected static function render_games( array $tournament, array $entries ) {
		$names = array();
		foreach ( $entries as $entry ) {
			$names[ $entry['id'] ] = $entry['name'];
		}

		$group_rounds = array();
		$knockout     = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_games( $tournament['id'] ) as $game ) {
			if ( 'knockout' === $game['stage'] ) {
				$knockout[ $game['round'] ][] = $game;
			} else {
				$group_rounds[ $game['group_no'] ][ $game['round'] ][] = $game;
			}
		}

		$config = Chess_Army_Knife_Tournaments::config( $tournament );

		foreach ( $group_rounds as $group_no => $rounds ) {
			?>
			<h2>
				<?php
				echo esc_html( $group_no ? Chess_Army_Knife_Tournaments::group_label( $group_no ) . ' — ' . __( 'Rounds', 'chess-army-knife' ) : __( 'Rounds', 'chess-army-knife' ) );
				?>
			</h2>
			<?php
			foreach ( $rounds as $round => $games ) {
				/* translators: %d: round number */
				self::render_round_form( $tournament, $games, sprintf( __( 'Round %d', 'chess-army-knife' ), $round ), $names, false );
			}
		}

		if ( ! empty( $knockout ) ) {
			$total = max( array_keys( $knockout ) );
			?>
			<h2><?php esc_html_e( 'Knockout', 'chess-army-knife' ); ?></h2>
			<?php
			foreach ( $knockout as $round => $games ) {
				self::render_round_form( $tournament, $games, Chess_Army_Knife_Bracket::round_name( $round, $total ), $names, true );
			}
		} elseif ( $config['advance'] > 0 ) {
			?>
			<h2><?php esc_html_e( 'Knockout', 'chess-army-knife' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The knockout bracket is created automatically when every group game has a result.', 'chess-army-knife' ); ?></p>
			<?php
		}
	}

	/**
	 * One round's games with a single "Save results" form.
	 *
	 * @param array    $tournament Tournament row.
	 * @param array[]  $games      Games in the round.
	 * @param string   $heading    Round heading.
	 * @param string[] $names      Entry id => name.
	 * @param bool     $knockout   Whether this is a knockout round.
	 */
	protected static function render_round_form( array $tournament, array $games, $heading, array $names, $knockout ) {
		$options = self::result_options( $knockout );

		// A tie's later games are tie-break games.
		$seen = array();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="chess_army_knife_tournament_save_results" />
			<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament['id'] ); ?>" />
			<?php wp_nonce_field( 'chess_army_knife_tournament_save_results' ); ?>
			<h3><?php echo esc_html( $heading ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<tbody>
					<?php foreach ( $games as $game ) : ?>
						<?php
						$tie_key          = $game['board'];
						$tiebreak         = $knockout && isset( $seen[ $tie_key ] );
						$seen[ $tie_key ] = true;
						$waiting          = null === $game['white_entry_id'] || null === $game['black_entry_id'];
						?>
						<tr>
							<td>
								<?php echo esc_html( isset( $names[ $game['white_entry_id'] ] ) ? $names[ $game['white_entry_id'] ] : ( $knockout && ! $game['is_bye'] ? __( 'To be decided', 'chess-army-knife' ) : '—' ) ); ?>
								<span class="description"><?php esc_html_e( '(White)', 'chess-army-knife' ); ?></span>
							</td>
							<td>
								<?php echo esc_html( isset( $names[ $game['black_entry_id'] ] ) ? $names[ $game['black_entry_id'] ] : ( $knockout && ! $game['is_bye'] ? __( 'To be decided', 'chess-army-knife' ) : '—' ) ); ?>
								<span class="description"><?php esc_html_e( '(Black)', 'chess-army-knife' ); ?></span>
								<?php if ( $tiebreak ) : ?>
									<em><?php esc_html_e( 'Tie-break', 'chess-army-knife' ); ?></em>
								<?php endif; ?>
							</td>
							<td style="width:220px;">
								<?php if ( $game['is_bye'] ) : ?>
									<em><?php esc_html_e( 'Bye', 'chess-army-knife' ); ?></em>
								<?php elseif ( $waiting ) : ?>
									<em><?php esc_html_e( 'Waiting for players', 'chess-army-knife' ); ?></em>
								<?php else : ?>
									<select name="results[<?php echo esc_attr( $game['id'] ); ?>]">
										<?php foreach ( $options as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $game['result'], $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Save results', 'chess-army-knife' ), 'secondary', 'submit', true ); ?>
		</form>
		<?php
	}
}

Chess_Army_Knife_Tournaments_Page::init();
