<?php
/**
 * Admin side of groups: the box on a group's edit screen that chooses its teams and puts them in order.
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
	 * One team in either list. The same row serves both: the script shows the buttons that fit the list
	 * it is in, and a team not in the group has its field switched off so it is not saved.
	 *
	 * @param array $team     Team (see Chess_Army_Knife_Teams::all()).
	 * @param bool  $in_group Whether it is in the group being edited.
	 */
	protected static function render_team( array $team, $in_group ) {
		$name = $team['name'];
		?>
		<li class="cak-group-team" data-name="<?php echo esc_attr( $name ); ?>" style="border:1px solid #dcdcde;background:#fff;padding:6px 8px;margin:0 0 6px;display:flex;align-items:center;gap:6px">
			<span class="cak-drag-handle dashicons dashicons-menu" aria-hidden="true" style="cursor:move"></span>
			<input type="hidden" name="chess_army_group_teams[]" value="<?php echo esc_attr( $team['id'] ); ?>" <?php disabled( ! $in_group ); ?> />
			<span style="flex:1">
				<strong><?php echo esc_html( $name ); ?></strong>
				<?php if ( ! $in_group && $team['group_id'] ) : ?>
					<span class="description">
						<?php
						/* translators: %s: name of another group */
						echo esc_html( sprintf( __( '(now in %s)', 'chess-army-knife' ), $team['group'] ) );
						?>
					</span>
				<?php endif; ?>
			</span>
			<button type="button" class="button cak-move-up" <?php echo $in_group ? '' : 'style="display:none"'; ?>>
				<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php /* translators: %s: team name */ echo esc_html( sprintf( __( 'Move %s up', 'chess-army-knife' ), $name ) ); ?></span>
			</button>
			<button type="button" class="button cak-move-down" <?php echo $in_group ? '' : 'style="display:none"'; ?>>
				<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php /* translators: %s: team name */ echo esc_html( sprintf( __( 'Move %s down', 'chess-army-knife' ), $name ) ); ?></span>
			</button>
			<button type="button" class="button cak-remove" <?php echo $in_group ? '' : 'style="display:none"'; ?>>
				<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php /* translators: %s: team name */ echo esc_html( sprintf( __( 'Remove %s from the group', 'chess-army-knife' ), $name ) ); ?></span>
			</button>
			<button type="button" class="button cak-add" <?php echo $in_group ? 'style="display:none"' : ''; ?>>
				<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php /* translators: %s: team name */ echo esc_html( sprintf( __( 'Add %s to the group', 'chess-army-knife' ), $name ) ); ?></span>
			</button>
		</li>
		<?php
	}

	/**
	 * Render the box: the teams that are not in the group on the left, those that are on the right.
	 *
	 * @param WP_Post $post Group being edited.
	 */
	public static function render_teams( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$in_group = Chess_Army_Knife_Team_Groups::teams_of( $post->ID );
		$ids      = wp_list_pluck( $in_group, 'id' );
		$others   = array_values(
			array_filter(
				Chess_Army_Knife_Teams::all(),
				function ( $team ) use ( $ids ) {
					return ! in_array( $team['id'], $ids, true );
				}
			)
		);
		?>
		<p class="description"><?php esc_html_e( 'Drag teams from the left into the group on the right, and drag them up and down to put them in order. Or use the arrow buttons. A team can be in one group only: adding a team that is in another group moves it here.', 'chess-army-knife' ); ?></p>
		<div class="cak-group-columns" style="display:flex;gap:16px;flex-wrap:wrap" data-added="<?php /* translators: %s: team name */ esc_attr_e( '%s added to the group', 'chess-army-knife' ); ?>" data-removed="<?php /* translators: %s: team name */ esc_attr_e( '%s removed from the group', 'chess-army-knife' ); ?>" data-moved="<?php /* translators: 1: team name, 2: its new place in the list */ esc_attr_e( '%1$s is now number %2$s', 'chess-army-knife' ); ?>">
			<div style="flex:1;min-width:240px">
				<h3 id="cak-group-available-title"><?php esc_html_e( 'Teams not in this group', 'chess-army-knife' ); ?></h3>
				<ul class="cak-group-available" aria-labelledby="cak-group-available-title" style="list-style:none;margin:0;padding:8px;min-height:48px;border:1px dashed #c3c4c7">
					<?php foreach ( $others as $team ) : ?>
						<?php self::render_team( $team, false ); ?>
					<?php endforeach; ?>
				</ul>
			</div>
			<div style="flex:1;min-width:240px">
				<h3 id="cak-group-teams-title"><?php esc_html_e( 'Teams in this group', 'chess-army-knife' ); ?></h3>
				<ol class="cak-group-teams" aria-labelledby="cak-group-teams-title" style="list-style:none;margin:0;padding:8px;min-height:48px;border:1px solid #8c8f94">
					<?php foreach ( $in_group as $team ) : ?>
						<?php self::render_team( $team, true ); ?>
					<?php endforeach; ?>
				</ol>
			</div>
		</div>
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

		$ids = isset( $_POST['chess_army_group_teams'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['chess_army_group_teams'] ) ) : array();

		Chess_Army_Knife_Team_Groups::set_teams( $post_id, $ids );
	}
}

Chess_Army_Knife_Team_Groups_Admin::init();
