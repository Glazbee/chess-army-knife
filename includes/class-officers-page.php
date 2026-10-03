<?php
/**
 * The Officers screen: name the club's positions and put them in order, say
 * who holds each, and read the history of who held what.
 *
 * Choosing people needs the membership permission, because the list of
 * members to choose from is members' details. Team captains are shown here but
 * are chosen on each team.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Officers_Page {

	const PAGE           = 'chess-army-knife-officers';
	const ACTION_SAVE    = 'chess_army_knife_save_officer_positions';
	const ACTION_ASSIGN  = 'chess_army_knife_assign_officer';
	const ACTION_END     = 'chess_army_knife_end_officer_term';
	const BLANK_POSITION = 3;

	/**
	 * Hook up the screen's forms.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_ASSIGN, array( __CLASS__, 'handle_assign' ) );
		add_action( 'admin_post_' . self::ACTION_END, array( __CLASS__, 'handle_end' ) );
	}

	/**
	 * Go back to the screen, with a note of what happened.
	 *
	 * @param string $done What happened: saved, assigned, ended or a problem code.
	 */
	protected static function back( $done ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE,
					'cak_off_done' => $done,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Save the names and order of the positions.
	 */
	public static function handle_save() {
		check_admin_referer( self::ACTION_SAVE );
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each entry is cleaned by clean_positions().
		$posted    = isset( $_POST['positions'] ) && is_array( $_POST['positions'] ) ? wp_unslash( $_POST['positions'] ) : array();
		$positions = array();
		foreach ( $posted as $row ) {
			// A ticked box removes the position; the name is what is kept.
			if ( is_array( $row ) && empty( $row['remove'] ) ) {
				$positions[] = $row;
			}
		}

		// One of the up and down buttons moves a position before saving.
		$move = isset( $_POST['move'] ) ? sanitize_text_field( wp_unslash( $_POST['move'] ) ) : '';
		if ( preg_match( '/^([a-z0-9_-]+):(up|down)$/', $move, $parts ) ) {
			$positions = Chess_Army_Knife_Officers::move( Chess_Army_Knife_Officers::clean_positions( $positions ), $parts[1], $parts[2] );
		}

		Chess_Army_Knife_Officers::save_positions( $positions );
		self::back( 'saved' );
	}

	/**
	 * Give a member a position.
	 */
	public static function handle_assign() {
		check_admin_referer( self::ACTION_ASSIGN );
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}

		$position = isset( $_POST['position'] ) ? sanitize_key( wp_unslash( $_POST['position'] ) ) : '';
		$person   = isset( $_POST['person'] ) ? absint( $_POST['person'] ) : 0;
		$start    = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';

		self::back( Chess_Army_Knife_Officers::assign( $position, $person, $start ) ? 'assigned' : 'notassigned' );
	}

	/**
	 * Record that an officer stood down.
	 */
	public static function handle_end() {
		check_admin_referer( self::ACTION_END );
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}

		$term = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$end  = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

		self::back( Chess_Army_Knife_Officers::end_term( $term, $end ) ? 'ended' : 'notended' );
	}

	/**
	 * The notice for the outcome of a form.
	 *
	 * @param string $done Outcome code.
	 */
	protected static function render_notice( $done ) {
		$messages = array(
			'saved'       => array( 'success', __( 'The positions are saved.', 'chess-army-knife' ) ),
			'assigned'    => array( 'success', __( 'The officer is added.', 'chess-army-knife' ) ),
			'ended'       => array( 'success', __( 'The term is ended. It stays in the history.', 'chess-army-knife' ) ),
			'notassigned' => array( 'error', __( 'Nobody was added. Choose a position and a member, check that they do not already hold it, and use a date that is not in the future.', 'chess-army-knife' ) ),
			'notended'    => array( 'error', __( 'That term could not be ended. Use a date that is not in the future. A captain is changed on the team.', 'chess-army-knife' ) ),
		);
		if ( isset( $messages[ $done ] ) ) {
			printf(
				'<div class="notice notice-%1$s" role="%2$s"><p>%3$s</p></div>',
				esc_attr( $messages[ $done ][0] ),
				'error' === $messages[ $done ][0] ? 'alert' : 'status',
				esc_html( $messages[ $done ][1] )
			);
		}
	}

	/**
	 * Draw the Officers screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Memberships::user_can_manage(), __( 'Officers', 'chess-army-knife' ), 'members' ) ) {
			return;
		}

		// Captains chosen on the teams since the last visit join the history.
		Chess_Army_Knife_Officers::sync_captains();

		$done      = isset( $_GET['cak_off_done'] ) ? sanitize_key( wp_unslash( $_GET['cak_off_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the outcome of a form.
		$positions = Chess_Army_Knife_Officers::positions();
		$last_row  = count( $positions ) - 1;
		$row_count = $last_row + 1 + self::BLANK_POSITION;
		$terms     = Chess_Army_Knife_Officers::terms();
		$people    = array();
		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_ids( wp_list_pluck( $terms, 'person_id' ) ) as $person ) {
			$people[ $person['id'] ] = $person['name'];
		}
		$current = array_filter(
			$terms,
			function ( $term ) {
				return '' === $term['end_date'];
			}
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Officers', 'chess-army-knife' ); ?></h1>
			<p><?php esc_html_e( 'The people who run the club. Name the positions, put them in order, and say who holds each. The Club Officers block shows the current officers on a page; only their positions and names are shown (and, if you choose, since when), so check that each person is happy to be named on the website. Every change is kept in the history below.', 'chess-army-knife' ); ?></p>
			<?php self::render_notice( $done ); ?>

			<h2><?php esc_html_e( 'Positions', 'chess-army-knife' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>
				<?php // The first submit button is what the Enter key presses, so it must be the one that saves, not a move button. ?>
				<button type="submit" class="screen-reader-text" tabindex="-1" aria-hidden="true"><?php esc_html_e( 'Save positions', 'chess-army-knife' ); ?></button>
				<table class="widefat striped" style="max-width:720px">
					<caption class="screen-reader-text"><?php esc_html_e( 'Positions, in the order the block lists them by default', 'chess-army-knife' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Position', 'chess-army-knife' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Move', 'chess-army-knife' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Remove', 'chess-army-knife' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php for ( $row = 0; $row < $row_count; $row++ ) : ?>
							<?php
							$position = isset( $positions[ $row ] ) ? $positions[ $row ] : array(
								'id'   => '',
								'name' => '',
							);
							?>
							<tr>
								<td>
									<input type="hidden" name="positions[<?php echo esc_attr( $row ); ?>][id]" value="<?php echo esc_attr( $position['id'] ); ?>" />
									<input type="text" name="positions[<?php echo esc_attr( $row ); ?>][name]" value="<?php echo esc_attr( $position['name'] ); ?>" class="regular-text" maxlength="<?php echo esc_attr( Chess_Army_Knife_Officers::MAX_NAME_LENGTH ); ?>" placeholder="<?php echo esc_attr( '' === $position['id'] ? __( 'Add a position, e.g. Chairman', 'chess-army-knife' ) : '' ); ?>" aria-label="<?php esc_attr_e( 'Position name', 'chess-army-knife' ); ?>" />
								</td>
								<td>
									<?php if ( '' !== $position['id'] ) : ?>
										<?php
										/* translators: %s: name of a position */
										$up_label = sprintf( __( 'Move %s up', 'chess-army-knife' ), $position['name'] );
										/* translators: %s: name of a position */
										$down_label = sprintf( __( 'Move %s down', 'chess-army-knife' ), $position['name'] );
										?>
										<button type="submit" name="move" value="<?php echo esc_attr( $position['id'] . ':up' ); ?>" class="button" <?php disabled( 0 === $row ); ?>><span aria-hidden="true">&uarr;</span><span class="screen-reader-text"><?php echo esc_html( $up_label ); ?></span></button>
										<button type="submit" name="move" value="<?php echo esc_attr( $position['id'] . ':down' ); ?>" class="button" <?php disabled( $last_row === $row ); ?>><span aria-hidden="true">&darr;</span><span class="screen-reader-text"><?php echo esc_html( $down_label ); ?></span></button>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( '' !== $position['id'] ) : ?>
										<input type="checkbox" name="positions[<?php echo esc_attr( $row ); ?>][remove]" value="1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: name of a position */ __( 'Remove %s', 'chess-army-knife' ), $position['name'] ) ); ?>" />
									<?php endif; ?>
								</td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'This is the default order. Each block can be given its own order by dragging the positions in the editor. Removing a position, or clearing its name, ends the term of everyone who holds it; they stay in the history. Save to get more blank rows.', 'chess-army-knife' ); ?></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save positions', 'chess-army-knife' ); ?></button></p>
			</form>

			<h2><?php esc_html_e( 'Who holds each position', 'chess-army-knife' ); ?></h2>
			<?php if ( ! $positions ) : ?>
				<p><?php esc_html_e( 'Add a position above first.', 'chess-army-knife' ); ?></p>
			<?php else : ?>
				<?php self::render_assign_form( $positions ); ?>
				<table class="widefat striped" style="max-width:720px;margin-top:1em">
					<caption class="screen-reader-text"><?php esc_html_e( 'Current officers', 'chess-army-knife' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Position', 'chess-army-knife' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Since', 'chess-army-knife' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Stand down', 'chess-army-knife' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $current as $term ) : ?>
							<?php $name = isset( $people[ $term['person_id'] ] ) ? $people[ $term['person_id'] ] : ''; ?>
							<tr>
								<td><?php echo esc_html( Chess_Army_Knife_Officers::label_of_term( $term ) ); ?></td>
								<td><?php echo esc_html( $name ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $term['start_date'] ) ); ?></td>
								<td>
									<?php if ( Chess_Army_Knife_Officers::CAPTAIN_KEY === $term['position_key'] ) : ?>
										<?php esc_html_e( 'Change the captain on the team', 'chess-army-knife' ); ?>
									<?php else : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_END ); ?>" />
											<input type="hidden" name="term" value="<?php echo esc_attr( $term['id'] ); ?>" />
											<?php wp_nonce_field( self::ACTION_END ); ?>
											<label>
												<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: 1: person's name, 2: position */ __( 'Date %1$s stood down as %2$s', 'chess-army-knife' ), $name, $term['position_name'] ) ); ?></span>
												<input type="date" name="end_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
											</label>
											<button type="submit" class="button"><?php esc_html_e( 'Stand down', 'chess-army-knife' ); ?></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						<?php if ( ! $current ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'Nobody holds a position yet.', 'chess-army-knife' ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the Teams screen */
					esc_html__( 'Team captains are officers too. They are chosen on each team (%s) and appear here and in the block by themselves.', 'chess-army-knife' ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Teams::POST_TYPE ) ) . '">' . esc_html__( 'Teams', 'chess-army-knife' ) . '</a>'
				);
				?>
			</p>

			<h2><?php esc_html_e( 'History', 'chess-army-knife' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<caption class="screen-reader-text"><?php esc_html_e( 'Everyone who has held a position, most recent first', 'chess-army-knife' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Position', 'chess-army-knife' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></th>
						<th scope="col"><?php esc_html_e( 'From', 'chess-army-knife' ); ?></th>
						<th scope="col"><?php esc_html_e( 'To', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $terms as $term ) : ?>
						<tr>
							<td><?php echo esc_html( Chess_Army_Knife_Officers::label_of_term( $term ) ); ?></td>
							<td><?php echo esc_html( isset( $people[ $term['person_id'] ] ) ? $people[ $term['person_id'] ] : '' ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $term['start_date'] ) ); ?></td>
							<td><?php echo esc_html( '' === $term['end_date'] ? __( 'Present', 'chess-army-knife' ) : mysql2date( get_option( 'date_format' ), $term['end_date'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $terms ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No history yet.', 'chess-army-knife' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'If someone\'s record is deleted or erased, their entries are removed from this history too.', 'chess-army-knife' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The form that gives a member a position.
	 *
	 * @param array[] $positions Positions.
	 */
	protected static function render_assign_form( array $positions ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_ASSIGN ); ?>" />
			<?php wp_nonce_field( self::ACTION_ASSIGN ); ?>
			<p>
				<label for="cak-officer-position"><?php esc_html_e( 'Position', 'chess-army-knife' ); ?></label>
				<select id="cak-officer-position" name="position" required>
					<?php foreach ( $positions as $position ) : ?>
						<option value="<?php echo esc_attr( $position['id'] ); ?>"><?php echo esc_html( $position['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="cak-officer-person"><?php esc_html_e( 'Member', 'chess-army-knife' ); ?></label>
				<select id="cak-officer-person" name="person" required>
					<option value=""><?php esc_html_e( 'Choose a member', 'chess-army-knife' ); ?></option>
					<?php foreach ( Chess_Army_Knife_Membership_Store::get_players( '', false, 0, true ) as $person ) : ?>
						<option value="<?php echo esc_attr( $person['id'] ); ?>"><?php echo esc_html( $person['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="cak-officer-start"><?php esc_html_e( 'Since', 'chess-army-knife' ); ?></label>
				<input type="date" id="cak-officer-start" name="start_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Add officer', 'chess-army-knife' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'A position can have more than one holder, for example committee members. To replace someone, stand them down below and add the new officer.', 'chess-army-knife' ); ?></p>
		</form>
		<?php
	}
}

Chess_Army_Knife_Officers_Page::init();
