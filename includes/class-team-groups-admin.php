<?php
/**
 * Admin side of groups: the box on a group's edit screen that chooses its teams, their order, and which
 * teams are "hero" teams (a row of their own, with a short blurb).
 *
 * A group decides which teams are in it, so teams are managed here, many at once, not one by one.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Team_Groups_Admin {

	const NONCE_ACTION = 'chess_army_knife_save_group';
	const NONCE_FIELD  = 'chess_army_knife_group_nonce';

	/**
	 * Hook up the admin screen.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_' . Chess_Army_Knife_Team_Groups::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Chess_Army_Knife_Team_Groups::POST_TYPE, array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Add the box.
	 */
	public static function add_meta_boxes() {
		add_meta_box( 'chess_army_group_teams', __( 'Teams in this group', 'chess-army-knife' ), array( __CLASS__, 'render_teams' ), Chess_Army_Knife_Team_Groups::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Load the script that puts teams in order, on the group screen only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $screen || Chess_Army_Knife_Team_Groups::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_script( 'chess-army-knife-group-teams', Chess_Army_Knife_URL . 'assets/group-teams.js', array( 'jquery-ui-sortable', 'jquery-touch-punch' ), Chess_Army_Knife_VERSION, true );
	}

	/**
	 * Every team, those in this group first and in its order.
	 *
	 * @param int $group_id Group id.
	 * @return array[] Teams (see Chess_Army_Knife_Teams::all()).
	 */
	protected static function ordered_teams( $group_id ) {
		$in_group = Chess_Army_Knife_Team_Groups::teams_of( $group_id );
		$ids      = wp_list_pluck( $in_group, 'id' );
		$others   = array_filter(
			Chess_Army_Knife_Teams::all(),
			function ( $team ) use ( $ids ) {
				return ! in_array( $team['id'], $ids, true );
			}
		);
		return array_merge( $in_group, array_values( $others ) );
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post $post Group being edited.
	 */
	public static function render_teams( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$teams = self::ordered_teams( $post->ID );
		if ( ! $teams ) {
			echo '<p>' . esc_html__( 'There are no teams yet. Add teams on the Teams tab first.', 'chess-army-knife' ) . '</p>';
			return;
		}
		?>
		<p class="description"><?php esc_html_e( 'Tick the teams in this group and put them in the order they should appear: drag a team by its handle, or use its Move up and Move down buttons. A team can be in one group only: ticking a team that is in another group moves it here. A hero team gets a row to itself, with its own short blurb.', 'chess-army-knife' ); ?></p>
		<ol class="cak-group-teams" data-moved="<?php /* translators: 1: team name, 2: its new place in the list */ esc_attr_e( '%1$s is now number %2$s', 'chess-army-knife' ); ?>">
			<?php foreach ( $teams as $team ) : ?>
				<?php $here = (int) $post->ID === $team['group_id']; ?>
				<li class="cak-group-team" data-name="<?php echo esc_attr( $team['name'] ); ?>" style="border:1px solid #dcdcde;padding:8px;margin:0 0 8px">
					<span class="cak-drag-handle dashicons dashicons-menu" aria-hidden="true" style="cursor:move"></span>
					<label>
						<input type="checkbox" name="chess_army_group_teams[]" value="<?php echo esc_attr( $team['id'] ); ?>" <?php checked( $here ); ?> />
						<strong><?php echo esc_html( $team['name'] ); ?></strong>
					</label>
					<?php if ( $team['group_id'] && ! $here ) : ?>
						<span class="description">
							<?php
							/* translators: %s: name of another group */
							echo esc_html( sprintf( __( '(now in %s)', 'chess-army-knife' ), $team['group'] ) );
							?>
						</span>
					<?php endif; ?>
					<button type="button" class="button cak-move-up">
						<?php esc_html_e( 'Move up', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $team['name'] ); ?></span>
					</button>
					<button type="button" class="button cak-move-down">
						<?php esc_html_e( 'Move down', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $team['name'] ); ?></span>
					</button>
					<p>
						<label><input type="checkbox" name="chess_army_group_hero[<?php echo esc_attr( $team['id'] ); ?>]" value="1" <?php checked( $here && $team['hero'] ); ?> /> <?php esc_html_e( 'Hero team (a row of its own)', 'chess-army-knife' ); ?></label>
					</p>
					<p>
						<label for="cak-hero-blurb-<?php echo esc_attr( $team['id'] ); ?>"><?php esc_html_e( 'Hero blurb', 'chess-army-knife' ); ?></label><br />
						<textarea id="cak-hero-blurb-<?php echo esc_attr( $team['id'] ); ?>" name="chess_army_group_hero_blurb[<?php echo esc_attr( $team['id'] ); ?>]" rows="2" class="large-text"><?php echo esc_textarea( $here ? $team['hero_blurb'] : '' ); ?></textarea>
					</p>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="screen-reader-text" role="status" aria-live="polite" id="cak-group-teams-status"></p>
		<?php
	}

	/**
	 * Save the box.
	 *
	 * @param int $post_id Group post id.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$ids    = isset( $_POST['chess_army_group_teams'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['chess_army_group_teams'] ) ) : array();
		$heroes = isset( $_POST['chess_army_group_hero'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['chess_army_group_hero'] ) ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each blurb is cleaned by set_teams().
		$blurbs = isset( $_POST['chess_army_group_hero_blurb'] ) ? (array) wp_unslash( $_POST['chess_army_group_hero_blurb'] ) : array();

		$rows = array();
		foreach ( $ids as $team_id ) {
			$rows[] = array(
				'team_id' => $team_id,
				'hero'    => ! empty( $heroes[ $team_id ] ),
				'blurb'   => isset( $blurbs[ $team_id ] ) && is_string( $blurbs[ $team_id ] ) ? $blurbs[ $team_id ] : '',
			);
		}
		Chess_Army_Knife_Team_Groups::set_teams( $post_id, $rows );
	}
}

Chess_Army_Knife_Team_Groups_Admin::init();
