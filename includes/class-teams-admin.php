<?php
/**
 * Admin side of teams: the details and squad boxes on the team edit screen,
 * and the button that creates teams from the Club Teams list.
 *
 * Squads and captains are personal data, so everything here needs the
 * membership permission, which also guards the team post type itself.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Teams_Admin {

	const NONCE_ACTION = 'chess_army_knife_save_team';
	const NONCE_FIELD  = 'chess_army_knife_team_nonce';

	/**
	 * Hook up the admin screens.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_' . Chess_Army_Knife_Teams::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Chess_Army_Knife_Teams::POST_TYPE, array( __CLASS__, 'save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_offer_creation' ) );
		add_action( 'admin_post_chess_army_knife_create_teams', array( __CLASS__, 'handle_create' ) );
	}

	/**
	 * Add the boxes.
	 */
	public static function add_meta_boxes() {
		add_meta_box( 'chess_army_team_details', __( 'Team details', 'chess-army-knife' ), array( __CLASS__, 'render_details' ), Chess_Army_Knife_Teams::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'chess_army_team_squad', __( 'Squad', 'chess-army-knife' ), array( __CLASS__, 'render_squad' ), Chess_Army_Knife_Teams::POST_TYPE, 'normal', 'default' );
	}

	/**
	 * Current members, for choosing a captain or squad. The people already
	 * chosen stay in the list even if their membership has since lapsed, so
	 * saving the team does not drop them unnoticed.
	 *
	 * @param int[] $chosen Member ids already chosen.
	 * @return array[] Person rows.
	 */
	protected static function candidates( array $chosen ) {
		$people = Chess_Army_Knife_Membership_Store::get_players( '', false, 0, true );
		$have   = wp_list_pluck( $people, 'id' );

		foreach ( $chosen as $id ) {
			$person = in_array( $id, $have, true ) ? null : Chess_Army_Knife_Membership_Store::get_member( $id );
			if ( $person ) {
				$people[] = $person;
			}
		}
		return $people;
	}

	/**
	 * Render the details box: venue, captain and the seasons the team plays in.
	 *
	 * @param WP_Post $post Team being edited.
	 */
	public static function render_details( $post ) {
		$venue   = (string) get_post_meta( $post->ID, Chess_Army_Knife_Teams::META_VENUE, true );
		$captain = (int) get_post_meta( $post->ID, Chess_Army_Knife_Teams::META_CAPTAIN, true );
		$colour  = (string) get_post_meta( $post->ID, Chess_Army_Knife_Teams::META_COLOUR, true );
		$seasons = get_post_meta( $post->ID, Chess_Army_Knife_Teams::META_SEASONS, true );
		$seasons = is_array( $seasons ) ? $seasons : array();

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="chess_army_team_venue"><?php esc_html_e( 'Home venue', 'chess-army-knife' ); ?></label></th>
				<td><input type="text" id="chess_army_team_venue" name="chess_army_team_venue" value="<?php echo esc_attr( $venue ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_team_colour"><?php esc_html_e( 'Calendar colour', 'chess-army-knife' ); ?></label></th>
				<td>
					<input type="color" id="chess_army_team_colour" name="chess_army_team_colour" value="<?php echo esc_attr( '' !== $colour ? $colour : '#2a78d6' ); ?>" />
					<label><input type="checkbox" name="chess_army_team_no_colour" value="1" <?php checked( '' === $colour ); ?> /> <?php esc_html_e( 'No colour', 'chess-army-knife' ); ?></label>
					<p class="description"><?php esc_html_e( 'Marks this team\'s fixtures on the calendar. The team name is always shown too.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_team_boards"><?php esc_html_e( 'Boards', 'chess-army-knife' ); ?></label></th>
				<td><input type="number" min="1" max="<?php echo esc_attr( Chess_Army_Knife_Captains::MAX_BOARDS ); ?>" id="chess_army_team_boards" name="chess_army_team_boards" value="<?php echo esc_attr( Chess_Army_Knife_Captains::boards( $post->ID ) ); ?>" class="small-text" /> <span class="description"><?php esc_html_e( 'How many players the team fields. Used when the captain builds a line-up.', 'chess-army-knife' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_team_captain_user"><?php esc_html_e( 'Captain\'s website login', 'chess-army-knife' ); ?></label></th>
				<td>
					<?php if ( current_user_can( 'promote_users' ) ) : ?>
						<?php
						wp_dropdown_users(
							array(
								'name'              => 'chess_army_team_captain_user',
								'id'                => 'chess_army_team_captain_user',
								'selected'          => Chess_Army_Knife_Captains::user_of_team( $post->ID ),
								'show_option_none'  => __( 'None', 'chess-army-knife' ),
								'option_none_value' => 0,
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'This user can use Team Selection for this team only: see the squad, ask who can play and publish line-ups. Needs a website account.', 'chess-army-knife' ); ?></p>
					<?php else : ?>
						<?php $captain_user = get_userdata( Chess_Army_Knife_Captains::user_of_team( $post->ID ) ); ?>
						<?php echo esc_html( $captain_user ? $captain_user->display_name : __( 'None', 'chess-army-knife' ) ); ?>
						<p class="description"><?php esc_html_e( 'Only someone who can promote users can change this.', 'chess-army-knife' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_team_captain"><?php esc_html_e( 'Captain', 'chess-army-knife' ); ?></label></th>
				<td>
					<select id="chess_army_team_captain" name="chess_army_team_captain">
						<option value="0"><?php esc_html_e( 'None', 'chess-army-knife' ); ?></option>
						<?php foreach ( self::candidates( array_filter( array( $captain ) ) ) as $person ) : ?>
							<option value="<?php echo esc_attr( $person['id'] ); ?>" <?php selected( $captain, $person['id'] ); ?>><?php echo esc_html( $person['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Only visible here, to people who can manage members; it is not shown on the website.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Seasons', 'chess-army-knife' ); ?></th>
				<td>
					<?php $club_teams = Chess_Army_Knife_Settings::get_club_teams(); ?>
					<?php if ( ! $club_teams ) : ?>
						<p class="description"><?php esc_html_e( 'Add the club\'s league entries on the Club Teams page, then tick the ones that belong to this team. Fixtures are linked to teams when Import Events is run.', 'chess-army-knife' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $club_teams as $club_team ) : ?>
						<?php $key = Chess_Army_Knife_Teams::season_key( $club_team ); ?>
						<label style="display:block">
							<input type="checkbox" name="chess_army_team_seasons[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $seasons, true ) ); ?> />
							<?php echo esc_html( $club_team['team'] . ' — ' . $club_team['event'] ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'The excerpt is the description shown on the website. Use Order in the Page Attributes box to arrange teams.', 'chess-army-knife' ); ?></p>
		<?php
	}

	/**
	 * Render the squad box.
	 *
	 * @param WP_Post $post Team being edited.
	 */
	public static function render_squad( $post ) {
		$squad = Chess_Army_Knife_Teams::squad( $post->ID );
		?>
		<p class="description"><?php esc_html_e( 'Current members in this team. The squad is private: it is used for team lists and, later, fixture availability.', 'chess-army-knife' ); ?></p>
		<div style="max-height:260px;overflow:auto;border:1px solid #dcdcde;padding:8px">
			<?php foreach ( self::candidates( $squad ) as $person ) : ?>
				<label style="display:block">
					<input type="checkbox" name="chess_army_team_squad[]" value="<?php echo esc_attr( $person['id'] ); ?>" <?php checked( in_array( $person['id'], $squad, true ) ); ?> />
					<?php echo esc_html( $person['name'] ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Save the boxes.
	 *
	 * @param int $post_id Team post id.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			return;
		}

		update_post_meta( $post_id, Chess_Army_Knife_Teams::META_VENUE, isset( $_POST['chess_army_team_venue'] ) ? sanitize_text_field( wp_unslash( $_POST['chess_army_team_venue'] ) ) : '' );

		$colour = ! empty( $_POST['chess_army_team_no_colour'] ) || ! isset( $_POST['chess_army_team_colour'] ) ? '' : (string) sanitize_hex_color( wp_unslash( $_POST['chess_army_team_colour'] ) );
		update_post_meta( $post_id, Chess_Army_Knife_Teams::META_COLOUR, $colour );

		update_post_meta( $post_id, Chess_Army_Knife_Captains::META_BOARDS, isset( $_POST['chess_army_team_boards'] ) ? max( 1, min( Chess_Army_Knife_Captains::MAX_BOARDS, absint( $_POST['chess_army_team_boards'] ) ) ) : Chess_Army_Knife_Captains::DEFAULT_BOARDS );

		// Giving someone the captain's permission needs the power to promote users.
		if ( current_user_can( 'promote_users' ) && isset( $_POST['chess_army_team_captain_user'] ) ) {
			$user_id = absint( $_POST['chess_army_team_captain_user'] );
			Chess_Army_Knife_Captains::set_user( $post_id, $user_id && get_userdata( $user_id ) ? $user_id : 0 );
		}

		// Only someone the club holds a record of can captain a team.
		$captain = isset( $_POST['chess_army_team_captain'] ) ? absint( $_POST['chess_army_team_captain'] ) : 0;
		update_post_meta( $post_id, Chess_Army_Knife_Teams::META_CAPTAIN, $captain && Chess_Army_Knife_Membership_Store::get_member( $captain ) ? $captain : 0 );

		// Only entries that are on the Club Teams list are kept.
		$valid   = array_map( array( 'Chess_Army_Knife_Teams', 'season_key' ), Chess_Army_Knife_Settings::get_club_teams() );
		$seasons = isset( $_POST['chess_army_team_seasons'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['chess_army_team_seasons'] ) ) : array();
		update_post_meta( $post_id, Chess_Army_Knife_Teams::META_SEASONS, array_values( array_intersect( $valid, $seasons ) ) );

		$squad = isset( $_POST['chess_army_team_squad'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['chess_army_team_squad'] ) ) : array();
		Chess_Army_Knife_Teams::set_squad( $post_id, $squad );
	}

	/**
	 * On the Teams list, offer to create teams from the Club Teams list.
	 */
	public static function maybe_offer_creation() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . Chess_Army_Knife_Teams::POST_TYPE !== $screen->id || ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice.
		if ( isset( $_GET['teams_created'] ) ) {
			/* translators: %d: number of teams */
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d team created.', '%d teams created.', absint( $_GET['teams_created'] ), 'chess-army-knife' ), absint( $_GET['teams_created'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$existing = array_map( 'strtolower', Chess_Army_Knife_Teams::choices() );
		$missing  = 0;
		$seen     = array();
		foreach ( Chess_Army_Knife_Settings::get_club_teams() as $club_team ) {
			$name = strtolower( trim( (string) $club_team['team'] ) );
			if ( '' !== $name && ! in_array( $name, $existing, true ) && ! isset( $seen[ $name ] ) ) {
				$seen[ $name ] = true;
				++$missing;
			}
		}
		if ( ! $missing ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s" class="button">%3$s</a></p></div>',
			/* translators: %d: number of team names */
			esc_html( sprintf( _n( '%d team on your Club Teams list has no team here yet.', '%d teams on your Club Teams list have no team here yet.', $missing, 'chess-army-knife' ), $missing ) ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=chess_army_knife_create_teams' ), 'chess_army_knife_create_teams' ) ),
			esc_html__( 'Create them', 'chess-army-knife' )
		);
	}

	/**
	 * Create the missing teams.
	 */
	public static function handle_create() {
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'chess_army_knife_create_teams' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'     => Chess_Army_Knife_Teams::POST_TYPE,
					'teams_created' => Chess_Army_Knife_Teams::create_missing_from_club_teams(),
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}

Chess_Army_Knife_Teams_Admin::init();
