<?php
/**
 * Admin side of memberships that isn't the member list: the Memberships menu,
 * the "Membership details" box on the membership type edit screen, and the
 * profile setting that gives a user permission to manage members.
 *
 * Members and applications contain personal details, so managing them needs
 * the chess_army_manage_memberships permission. It is deliberately not part
 * of the Administrator role: an administrator with the "promote users"
 * permission ticks it on the profile of each person who should have it.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Memberships_Admin {

	const NONCE_ACTION  = 'chess_army_knife_save_membership_type';
	const NONCE_FIELD   = 'chess_army_knife_membership_type_nonce';
	const PROFILE_FIELD = 'chess_army_knife_can_manage_memberships';
	const TEAMS_FIELD   = 'chess_army_knife_can_manage_teams';

	/**
	 * Hook up the admin screens.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_' . Chess_Army_Knife_Memberships::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . Chess_Army_Knife_Memberships::POST_TYPE, array( __CLASS__, 'save' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_field' ) );
	}

	/* -------------------------------------------------------------
	 * Membership type details
	 * ------------------------------------------------------------- */

	/**
	 * Add the details box.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'chess_army_membership_details',
			__( 'Membership details', 'chess-army-knife' ),
			array( __CLASS__, 'render_meta_box' ),
			Chess_Army_Knife_Memberships::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the details box.
	 *
	 * @param WP_Post $post Membership type being edited.
	 */
	public static function render_meta_box( $post ) {
		$description = (string) get_post_meta( $post->ID, Chess_Army_Knife_Memberships::META_DESCRIPTION, true );
		$price       = get_post_meta( $post->ID, Chess_Army_Knife_Memberships::META_PRICE, true );
		$months      = get_post_meta( $post->ID, Chess_Army_Knife_Memberships::META_MONTHS, true );
		$is_junior   = '1' === (string) get_post_meta( $post->ID, Chess_Army_Knife_Memberships::META_JUNIOR, true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="chess_army_membership_description"><?php esc_html_e( 'Description', 'chess-army-knife' ); ?></label></th>
				<td>
					<textarea id="chess_army_membership_description" name="chess_army_membership_description" rows="3" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Shown to visitors, for example who it is for and what it includes.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_membership_price"><?php echo esc_html( sprintf( /* translators: %s: currency symbol */ __( 'Price (%s)', 'chess-army-knife' ), Chess_Army_Knife_Memberships::currency_symbol() ) ); ?></label></th>
				<td>
					<input type="text" id="chess_army_membership_price" name="chess_army_membership_price" value="<?php echo esc_attr( '' === $price ? '' : Chess_Army_Knife_Memberships::price_field_value( $price ) ); ?>" class="small-text" inputmode="decimal" placeholder="25.00" />
					<p class="description"><?php esc_html_e( 'Leave blank or enter 0 for a free membership.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_membership_months"><?php esc_html_e( 'Length (months)', 'chess-army-knife' ); ?></label></th>
				<td>
					<input type="number" id="chess_army_membership_months" name="chess_army_membership_months" value="<?php echo esc_attr( '' === $months ? 12 : (int) $months ); ?>" min="0" max="120" class="small-text" />
					<p class="description"><?php esc_html_e( 'How long a membership lasts once approved, used to suggest its expiry date. 12 is a year; 0 means it does not expire.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Juniors', 'chess-army-knife' ); ?></th>
				<td>
					<label for="chess_army_membership_junior"><input type="checkbox" id="chess_army_membership_junior" name="chess_army_membership_junior" value="1" <?php checked( $is_junior ); ?> /> <?php esc_html_e( 'Is a junior membership', 'chess-army-knife' ); ?></label>
					<p class="description"><?php esc_html_e( 'The application form asks for a date of birth and a parent or guardian\'s details only when a junior membership is chosen.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Publish a membership type to advertise it and offer it on the application form; keep it as a draft to hide it. Use Order in the Page Attributes box to arrange them.', 'chess-army-knife' ); ?></p>
		<?php
	}

	/**
	 * Save the details box.
	 *
	 * @param int $post_id Membership type id.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, Chess_Army_Knife_Memberships::META_DESCRIPTION, isset( $_POST['chess_army_membership_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['chess_army_membership_description'] ) ) : '' );

		// A price that can't be read is left as it was, rather than becoming free by mistake.
		$price = isset( $_POST['chess_army_membership_price'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['chess_army_membership_price'] ) ) ) : '';
		if ( '' === $price ) {
			update_post_meta( $post_id, Chess_Army_Knife_Memberships::META_PRICE, 0 );
		} elseif ( null !== Chess_Army_Knife_Memberships::parse_price( $price ) ) {
			update_post_meta( $post_id, Chess_Army_Knife_Memberships::META_PRICE, Chess_Army_Knife_Memberships::parse_price( $price ) );
		}

		$months = isset( $_POST['chess_army_membership_months'] ) ? absint( $_POST['chess_army_membership_months'] ) : 12;
		update_post_meta( $post_id, Chess_Army_Knife_Memberships::META_MONTHS, min( 120, $months ) );

		update_post_meta( $post_id, Chess_Army_Knife_Memberships::META_JUNIOR, empty( $_POST['chess_army_membership_junior'] ) ? '0' : '1' );
	}

	/* -------------------------------------------------------------
	 * Permission
	 * ------------------------------------------------------------- */

	/**
	 * Show the permission checkbox on a user's profile to those who may change it.
	 *
	 * @param WP_User $user User being viewed.
	 */
	public static function render_profile_field( $user ) {
		if ( ! current_user_can( 'promote_users' ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Chess Army Knife', 'chess-army-knife' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Club memberships', 'chess-army-knife' ); ?></th>
				<td>
					<label for="<?php echo esc_attr( self::PROFILE_FIELD ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( self::PROFILE_FIELD ); ?>" name="<?php echo esc_attr( self::PROFILE_FIELD ); ?>" value="1" <?php checked( user_can( $user, Chess_Army_Knife_Memberships::CAPABILITY ) ); ?> />
						<?php esc_html_e( 'Can view and manage members, applications and membership types', 'chess-army-knife' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Members share personal details, so this is not given to every administrator automatically.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Club teams', 'chess-army-knife' ); ?></th>
				<td>
					<label for="<?php echo esc_attr( self::TEAMS_FIELD ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( self::TEAMS_FIELD ); ?>" name="<?php echo esc_attr( self::TEAMS_FIELD ); ?>" value="1" <?php checked( user_can( $user, Chess_Army_Knife_Teams::CAPABILITY ) ); ?> <?php disabled( user_can( $user, Chess_Army_Knife_Memberships::CAPABILITY ) ); ?> />
						<?php esc_html_e( 'Can edit teams and their leagues, import fixtures and pick every team', 'chess-army-knife' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'This shows nothing about members beyond the people in each team\'s squad. Anyone who can manage members can do this already. A team\'s captain does not need it: choosing a captain\'s website login on the team lets them pick that team only.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the permission checkbox.
	 *
	 * @param int $user_id User being saved.
	 */
	public static function save_profile_field( $user_id ) {
		if ( ! current_user_can( 'promote_users' ) || ! check_admin_referer( 'update-user_' . $user_id ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		if ( ! empty( $_POST[ self::PROFILE_FIELD ] ) ) {
			$user->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		} else {
			$user->remove_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		}

		// The box is disabled, so not sent, for someone who manages members: their own permission already covers teams.
		if ( empty( $_POST[ self::PROFILE_FIELD ] ) ) {
			if ( ! empty( $_POST[ self::TEAMS_FIELD ] ) ) {
				$user->add_cap( Chess_Army_Knife_Teams::CAPABILITY );
			} else {
				$user->remove_cap( Chess_Army_Knife_Teams::CAPABILITY );
			}
		}
	}
}

Chess_Army_Knife_Memberships_Admin::init();
