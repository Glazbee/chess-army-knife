<?php
/**
 * Admin side of teams: the details and squad boxes on the team edit screen.
 *
 * Editing a team needs the team permission, which also guards the team post
 * type itself. Squads and captains are personal data: choosing who is in one
 * needs the membership permission, and someone with only the team permission
 * sees no more than the names of the people already in each squad.
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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Load the script that moves people between the lists, on the team screen only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $screen || Chess_Army_Knife_Teams::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_script( 'chess-army-knife-squad-picker', Chess_Army_Knife_URL . 'assets/squad-picker.js', array( 'jquery-ui-sortable', 'jquery-touch-punch' ), Chess_Army_Knife_VERSION, true );
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
		$venue    = (string) get_post_meta( $post->ID, Chess_Army_Knife_Teams::META_VENUE, true );
		$captain  = (int) get_post_meta( $post->ID, Chess_Army_Knife_Teams::META_CAPTAIN, true );
		$colour   = (string) get_post_meta( $post->ID, Chess_Army_Knife_Teams::META_COLOUR, true );
		$tag      = (string) get_post_meta( $post->ID, Chess_Army_Knife_Teams::META_TAG, true );
		$whatsapp = (string) get_post_meta( $post->ID, Chess_Army_Knife_Teams::META_WHATSAPP, true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="chess_army_team_venue"><?php esc_html_e( 'Home venue', 'chess-army-knife' ); ?></label></th>
				<td><input type="text" id="chess_army_team_venue" name="chess_army_team_venue" value="<?php echo esc_attr( $venue ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Group', 'chess-army-knife' ); ?></th>
				<td>
					<?php $current = Chess_Army_Knife_Teams::get( $post->ID ); ?>
					<?php echo esc_html( $current && '' !== $current['group'] ? $current['group'] : __( 'None', 'chess-army-knife' ) ); ?>
					<p class="description"><?php esc_html_e( 'A group chooses its teams: add this team to a group on the Groups tab of Teams.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_team_tag"><?php esc_html_e( 'Calendar tag', 'chess-army-knife' ); ?></label></th>
				<td>
					<input type="text" id="chess_army_team_tag" name="chess_army_team_tag" value="<?php echo esc_attr( $tag ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Lions', 'chess-army-knife' ); ?>" />
					<p class="description"><?php esc_html_e( 'Added to this team\'s imported fixtures, besides "League match", so a calendar can show only this team\'s matches and key them by colour. Leave blank for none.', 'chess-army-knife' ); ?></p>
				</td>
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
				<th scope="row"><label for="chess_army_team_whatsapp"><?php esc_html_e( 'WhatsApp group invite link', 'chess-army-knife' ); ?></label></th>
				<td>
					<input type="url" id="chess_army_team_whatsapp" name="chess_army_team_whatsapp" value="<?php echo esc_attr( $whatsapp ); ?>" class="regular-text" placeholder="https://chat.whatsapp.com/..." aria-describedby="chess_army_team_whatsapp_help" />
					<p class="description" id="chess_army_team_whatsapp_help"><?php esc_html_e( 'Optional. Shown in the Member Portal only to members in this team\'s squad who agreed to WhatsApp groups. Anyone who has the link can join the group, and everyone in a group can see members\' names and phone numbers, so share it with care. The plugin never contacts WhatsApp.', 'chess-army-knife' ); ?></p>
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
					<?php if ( Chess_Army_Knife_Memberships::user_can_manage() ) : ?>
						<select id="chess_army_team_captain" name="chess_army_team_captain">
							<option value="0"><?php esc_html_e( 'None', 'chess-army-knife' ); ?></option>
							<?php foreach ( self::candidates( array_filter( array( $captain ) ) ) as $person ) : ?>
								<option value="<?php echo esc_attr( $person['id'] ); ?>" <?php selected( $captain, $person['id'] ); ?>><?php echo esc_html( $person['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Shown on the website in the Club Officers block, where team captains are listed as officers, and marked in the squad list of the Club Teams block.', 'chess-army-knife' ); ?></p>
					<?php else : ?>
						<?php $captain_person = $captain ? Chess_Army_Knife_Membership_Store::get_member( $captain ) : null; ?>
						<?php echo esc_html( $captain_person ? $captain_person['name'] : __( 'None', 'chess-army-knife' ) ); ?>
						<p class="description"><?php esc_html_e( 'Only someone who can manage members can change this.', 'chess-army-knife' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'League entries', 'chess-army-knife' ); ?></th>
				<td>
					<?php
					$leagues = Chess_Army_Knife_Teams::clean_leagues( get_post_meta( $post->ID, Chess_Army_Knife_Teams::META_LEAGUES, true ) );
					$rows    = count( $leagues ) + 3; // A few blank rows to add to.
					?>
					<table class="widefat striped" style="max-width:720px">
<caption class="screen-reader-text"><?php esc_html_e( 'League entries', 'chess-army-knife' ); ?></caption>
						<thead>
							<tr>
								<th><?php esc_html_e( 'LMS organisation ID', 'chess-army-knife' ); ?></th>
								<th><?php esc_html_e( 'Event / division', 'chess-army-knife' ); ?></th>
								<th><?php esc_html_e( 'Name in the LMS, if different', 'chess-army-knife' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php for ( $row = 0; $row < $rows; $row++ ) : ?>
								<?php $league = isset( $leagues[ $row ] ) ? $leagues[ $row ] : array_fill_keys( array( 'org', 'event', 'name' ), '' ); ?>
								<tr>
									<td><input type="text" name="chess_army_team_leagues[<?php echo esc_attr( $row ); ?>][org]" value="<?php echo esc_attr( $league['org'] ); ?>" class="small-text" inputmode="numeric" placeholder="270" aria-label="<?php esc_attr_e( 'LMS organisation ID', 'chess-army-knife' ); ?>" /></td>
									<td><input type="text" name="chess_army_team_leagues[<?php echo esc_attr( $row ); ?>][event]" value="<?php echo esc_attr( $league['event'] ); ?>" class="regular-text" placeholder="Division 1" aria-label="<?php esc_attr_e( 'Event / division', 'chess-army-knife' ); ?>" /></td>
									<td><input type="text" name="chess_army_team_leagues[<?php echo esc_attr( $row ); ?>][name]" value="<?php echo esc_attr( $league['name'] ); ?>" class="regular-text" aria-label="<?php esc_attr_e( 'Name in the LMS, if different', 'chess-army-knife' ); ?>" /></td>
								</tr>
							<?php endfor; ?>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'The leagues this team plays in, one row for each season. Import Events reads the fixtures of these and links them to this team. Clear a row to remove it; save to get more blank rows. Leave the last column empty when the LMS uses this team\'s name.', 'chess-army-knife' ); ?></p>
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

		// Without the membership permission the squad can be seen but not changed: choosing from every member would show all of them.
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			?>
			<p class="description"><?php esc_html_e( 'The people in this team. The squad is private. Only someone who can manage members can change it.', 'chess-army-knife' ); ?></p>
			<ul>
				<?php foreach ( Chess_Army_Knife_Membership_Store::get_members_by_ids( $squad ) as $person ) : ?>
					<li><?php echo esc_html( $person['name'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php
			return;
		}
		$squad_ids = $squad;
		$people    = self::candidates( $squad_ids );
		$also      = Chess_Army_Knife_Teams::squad_names_by_person();
		$own       = Chess_Army_Knife_Teams::get( $post->ID );
		$in_squad  = array_values(
			array_filter(
				$people,
				function ( $person ) use ( $squad_ids ) {
					return in_array( $person['id'], $squad_ids, true );
				}
			)
		);
		$others    = array_values(
			array_filter(
				$people,
				function ( $person ) use ( $squad_ids ) {
					return ! in_array( $person['id'], $squad_ids, true );
				}
			)
		);
		?>
		<p class="description"><?php esc_html_e( 'Current members. The squad is private: it is used for team lists and fixture availability. Someone can be in more than one team\'s squad. Drag people from the left into this squad on the right, or use the arrow buttons.', 'chess-army-knife' ); ?></p>
		<div class="cak-squad-columns" data-added="<?php /* translators: %s: member name */ esc_attr_e( '%s added to the squad', 'chess-army-knife' ); ?>" data-removed="<?php /* translators: %s: member name */ esc_attr_e( '%s removed from the squad', 'chess-army-knife' ); ?>" style="display:flex;gap:16px;flex-wrap:wrap">
			<div style="flex:1;min-width:240px">
				<h3 id="cak-squad-available-title"><?php esc_html_e( 'Members not in this squad', 'chess-army-knife' ); ?></h3>
				<p>
					<label for="cak-squad-filter" class="screen-reader-text"><?php esc_html_e( 'Find a member', 'chess-army-knife' ); ?></label>
					<input type="search" id="cak-squad-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Find a member', 'chess-army-knife' ); ?>" />
				</p>
				<ul class="cak-squad-available" aria-labelledby="cak-squad-available-title" style="list-style:none;margin:0;padding:8px;min-height:48px;max-height:420px;overflow:auto;border:1px dashed #c3c4c7">
					<?php foreach ( $others as $person ) : ?>
						<?php self::render_person( $person, false, $also ); ?>
					<?php endforeach; ?>
				</ul>
			</div>
			<div style="flex:1;min-width:240px">
				<h3 id="cak-squad-members-title">
					<?php
					/* translators: %s: team name */
					echo esc_html( sprintf( __( 'In the %s squad', 'chess-army-knife' ), $own ? $own['name'] : $post->post_title ) );
					?>
				</h3>
				<ul class="cak-squad-members" aria-labelledby="cak-squad-members-title" style="list-style:none;margin:0;padding:8px;min-height:48px;max-height:420px;overflow:auto;border:1px solid #8c8f94">
					<?php foreach ( $in_squad as $person ) : ?>
						<?php self::render_person( $person, true, $also ); ?>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<p class="screen-reader-text" role="status" aria-live="polite" id="cak-squad-status"></p>
		<?php
	}

	/**
	 * One member in either squad list. The script shows the button that fits the list the member is in, and
	 * a member not in the squad has their field switched off so they are not saved.
	 *
	 * @param array $person   Member row.
	 * @param bool  $in_squad Whether they are in the squad being edited.
	 * @param array $also     Names of the teams each member is in, by member id (see Chess_Army_Knife_Teams::squad_names_by_person()).
	 */
	protected static function render_person( array $person, $in_squad, array $also ) {
		$name  = $person['name'];
		$teams = isset( $also[ $person['id'] ] ) ? $also[ $person['id'] ] : array();
		?>
		<li class="cak-squad-person" data-name="<?php echo esc_attr( $name ); ?>" style="border:1px solid #dcdcde;background:#fff;padding:6px 8px;margin:0 0 6px;display:flex;align-items:center;gap:6px">
			<span class="cak-drag-handle dashicons dashicons-menu" aria-hidden="true" style="cursor:move"></span>
			<input type="hidden" name="chess_army_team_squad[]" value="<?php echo esc_attr( $person['id'] ); ?>" <?php disabled( ! $in_squad ); ?> />
			<span style="flex:1">
				<?php echo esc_html( $name ); ?>
				<?php if ( $teams ) : ?>
					<span class="description">
						<?php
						/* translators: %s: names of teams */
						echo esc_html( sprintf( __( '(also in %s)', 'chess-army-knife' ), implode( ', ', $teams ) ) );
						?>
					</span>
				<?php endif; ?>
			</span>
			<button type="button" class="button cak-remove" <?php echo $in_squad ? '' : 'style="display:none"'; ?>>
				<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php /* translators: %s: member name */ echo esc_html( sprintf( __( 'Remove %s from the squad', 'chess-army-knife' ), $name ) ); ?></span>
			</button>
			<button type="button" class="button cak-add" <?php echo $in_squad ? 'style="display:none"' : ''; ?>>
				<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php /* translators: %s: member name */ echo esc_html( sprintf( __( 'Add %s to the squad', 'chess-army-knife' ), $name ) ); ?></span>
			</button>
		</li>
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
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, Chess_Army_Knife_Teams::META_TAG, isset( $_POST['chess_army_team_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['chess_army_team_tag'] ) ) : '' );
		update_post_meta( $post_id, Chess_Army_Knife_Teams::META_WHATSAPP, isset( $_POST['chess_army_team_whatsapp'] ) ? Chess_Army_Knife_Teams::clean_whatsapp_link( sanitize_text_field( wp_unslash( $_POST['chess_army_team_whatsapp'] ) ) ) : '' );
		update_post_meta( $post_id, Chess_Army_Knife_Teams::META_VENUE, isset( $_POST['chess_army_team_venue'] ) ? sanitize_text_field( wp_unslash( $_POST['chess_army_team_venue'] ) ) : '' );

		$colour = ! empty( $_POST['chess_army_team_no_colour'] ) || ! isset( $_POST['chess_army_team_colour'] ) ? '' : (string) sanitize_hex_color( wp_unslash( $_POST['chess_army_team_colour'] ) );
		update_post_meta( $post_id, Chess_Army_Knife_Teams::META_COLOUR, $colour );

		update_post_meta( $post_id, Chess_Army_Knife_Captains::META_BOARDS, isset( $_POST['chess_army_team_boards'] ) ? max( 1, min( Chess_Army_Knife_Captains::MAX_BOARDS, absint( $_POST['chess_army_team_boards'] ) ) ) : Chess_Army_Knife_Captains::DEFAULT_BOARDS );

		// Giving someone the captain's permission needs the power to promote users.
		if ( current_user_can( 'promote_users' ) && isset( $_POST['chess_army_team_captain_user'] ) ) {
			$user_id = absint( $_POST['chess_army_team_captain_user'] );
			Chess_Army_Knife_Captains::set_user( $post_id, $user_id && get_userdata( $user_id ) ? $user_id : 0 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each entry is cleaned by clean_leagues().
		$leagues = isset( $_POST['chess_army_team_leagues'] ) && is_array( $_POST['chess_army_team_leagues'] ) ? wp_unslash( $_POST['chess_army_team_leagues'] ) : array();
		update_post_meta( $post_id, Chess_Army_Knife_Teams::META_LEAGUES, Chess_Army_Knife_Teams::clean_leagues( $leagues ) );

		// The captain and the squad are people's details: only someone who manages members may choose them.
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			return;
		}

		// Only someone the club holds a record of can captain a team.
		$captain = isset( $_POST['chess_army_team_captain'] ) ? absint( $_POST['chess_army_team_captain'] ) : 0;
		update_post_meta( $post_id, Chess_Army_Knife_Teams::META_CAPTAIN, $captain && Chess_Army_Knife_Membership_Store::get_member( $captain ) ? $captain : 0 );

		$squad = isset( $_POST['chess_army_team_squad'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['chess_army_team_squad'] ) ) : array();
		Chess_Army_Knife_Teams::set_squad( $post_id, $squad );
	}
}

Chess_Army_Knife_Teams_Admin::init();
