<?php
/**
 * The Send box on the announcement edit screen: audience, kind of email, when
 * to send, and (for a team audience) the list of people who agreed to that
 * team's WhatsApp group, for sending the same message there by hand.
 *
 * Only people with the membership permission can reach announcements (the
 * post type uses it), and saving checks it again.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Announcements_Admin {

	const NONCE_ACTION = 'chess_army_knife_save_announcement';
	const NONCE_FIELD  = 'chess_army_knife_announcement_nonce';

	/**
	 * Hook up the box.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_' . Chess_Army_Knife_Announcements::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . Chess_Army_Knife_Announcements::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Add the box.
	 */
	public static function add_meta_box() {
		add_meta_box( 'chess_army_announcement_send', __( 'Send', 'chess-army-knife' ), array( __CLASS__, 'render' ), Chess_Army_Knife_Announcements::POST_TYPE, 'side', 'high' );
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post $post Announcement being edited.
	 */
	public static function render( $post ) {
		$settings = Chess_Army_Knife_Announcements::settings( $post->ID );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		if ( Chess_Army_Knife_Announcements::STATE_SENT === $settings['state'] ) {
			$result = $settings['result'];
			echo '<p><strong>' . esc_html__( 'Sent.', 'chess-army-knife' ) . '</strong> ';
			echo esc_html(
				sprintf(
					/* translators: 1: date and time, 2: number of emails queued, 3: number of people skipped */
					__( '%1$s: %2$d emails queued, %3$d people skipped (no address, or they turned these emails off).', 'chess-army-knife' ),
					isset( $result['sent_at'] ) ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $result['sent_at'] ) : '',
					isset( $result['queued'] ) ? (int) $result['queued'] : 0,
					isset( $result['skipped'] ) ? (int) $result['skipped'] : 0
				)
			);
			echo '</p><p class="description">' . esc_html__( 'Members can read it in their Member Portal. To send it again, make a new announcement.', 'chess-army-knife' ) . '</p>';
			return;
		}

		$closes_date = '' !== $settings['send_at'] ? substr( $settings['send_at'], 0, 10 ) : '';
		$closes_time = '' !== $settings['send_at'] ? substr( $settings['send_at'], 11, 5 ) : '';
		?>
		<p>
			<label for="chess_army_ann_audience"><strong><?php esc_html_e( 'Who gets it', 'chess-army-knife' ); ?></strong></label><br />
			<select id="chess_army_ann_audience" name="chess_army_ann_audience">
				<?php foreach ( Chess_Army_Knife_Announcements::audiences() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['audience'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<div>
			<strong><?php esc_html_e( 'Teams (for the squads option)', 'chess-army-knife' ); ?></strong>
			<?php foreach ( Chess_Army_Knife_Teams::choices() as $team_id => $team_name ) : ?>
				<label style="display:block"><input type="checkbox" name="chess_army_ann_teams[]" value="<?php echo esc_attr( $team_id ); ?>" <?php checked( in_array( $team_id, $settings['teams'], true ) ); ?> /> <?php echo esc_html( $team_name ); ?></label>
			<?php endforeach; ?>
		</div>
		<p>
			<label for="chess_army_ann_category"><strong><?php esc_html_e( 'Send as', 'chess-army-knife' ); ?></strong></label><br />
			<select id="chess_army_ann_category" name="chess_army_ann_category">
				<?php foreach ( Chess_Army_Knife_Announcements::categories() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['category'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description"><?php esc_html_e( 'The newsletter goes only to members who agreed to it. Club announcements go to everyone in the audience unless they turned them off.', 'chess-army-knife' ); ?></p>
		<p>
			<strong><?php esc_html_e( 'When', 'chess-army-knife' ); ?></strong><br />
			<input type="date" name="chess_army_ann_date" value="<?php echo esc_attr( $closes_date ); ?>" />
			<input type="time" name="chess_army_ann_time" value="<?php echo esc_attr( $closes_time ); ?>" />
		</p>
		<p class="description"><?php esc_html_e( 'Leave blank to send as soon as you save with "Send" ticked. A later time is sent by the hourly job.', 'chess-army-knife' ); ?></p>
		<p>
			<label><input type="checkbox" name="chess_army_ann_send" value="1" <?php checked( Chess_Army_Knife_Announcements::STATE_SCHEDULED === $settings['state'] ); ?> /> <strong><?php esc_html_e( 'Send', 'chess-army-knife' ); ?></strong></label><br />
			<span class="description">
				<?php
				echo esc_html(
					Chess_Army_Knife_Announcements::STATE_SCHEDULED === $settings['state']
						? __( 'Scheduled. Untick and save to cancel.', 'chess-army-knife' )
						: __( 'Untick to keep it as a draft. Nobody is emailed until this is ticked and you save.', 'chess-army-knife' )
				);
				?>
			</span>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %d: number of people in the audience right now */
				esc_html__( 'Right now that is %d people.', 'chess-army-knife' ),
				count( Chess_Army_Knife_Announcements::audience_people( $settings['audience'], $settings['teams'] ) )
			);
			?>
		</p>
		<?php
		// WhatsApp groups stay manual: who agreed to the chosen teams' groups.
		if ( 'teams' === $settings['audience'] && $settings['teams'] ) {
			$names = array();
			foreach ( Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'active' ) ) as $person ) {
				if ( '' !== $person['whatsapp_consent_at'] && array_intersect( $settings['teams'], $person['whatsapp_teams'] ) ) {
					$phone   = '' !== $person['phone'] ? $person['phone'] : $person['guardian_phone'];
					$names[] = $person['name'] . ( '' !== $phone ? ' — ' . $phone : '' );
				}
			}
			echo '<hr /><p><strong>' . esc_html__( 'WhatsApp (send by hand)', 'chess-army-knife' ) . '</strong></p>';
			echo $names ? '<p class="description">' . esc_html( implode( '; ', $names ) ) . '</p>' : '<p class="description">' . esc_html__( 'Nobody has agreed to these teams\' groups.', 'chess-army-knife' ) . '</p>';
		}
	}

	/**
	 * Save the box, and send if asked.
	 *
	 * @param int     $post_id Announcement id.
	 * @param WP_Post $post    Announcement.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			return;
		}
		if ( Chess_Army_Knife_Announcements::STATE_SENT === Chess_Army_Knife_Announcements::settings( $post_id )['state'] ) {
			return; // Sent: nothing more to decide.
		}

		$audience = isset( $_POST['chess_army_ann_audience'] ) ? sanitize_key( wp_unslash( $_POST['chess_army_ann_audience'] ) ) : 'members';
		$category = isset( $_POST['chess_army_ann_category'] ) ? sanitize_key( wp_unslash( $_POST['chess_army_ann_category'] ) ) : 'announcements';
		$teams    = isset( $_POST['chess_army_ann_teams'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['chess_army_ann_teams'] ) ) : array();

		update_post_meta( $post_id, Chess_Army_Knife_Announcements::META_AUDIENCE, isset( Chess_Army_Knife_Announcements::audiences()[ $audience ] ) ? $audience : 'members' );
		update_post_meta( $post_id, Chess_Army_Knife_Announcements::META_CATEGORY, isset( Chess_Army_Knife_Announcements::categories()[ $category ] ) ? $category : 'announcements' );
		update_post_meta( $post_id, Chess_Army_Knife_Announcements::META_TEAMS, array_values( array_intersect( $teams, array_keys( Chess_Army_Knife_Teams::choices() ) ) ) );

		$date = isset( $_POST['chess_army_ann_date'] ) ? sanitize_text_field( wp_unslash( $_POST['chess_army_ann_date'] ) ) : '';
		$time = isset( $_POST['chess_army_ann_time'] ) ? sanitize_text_field( wp_unslash( $_POST['chess_army_ann_time'] ) ) : '';
		update_post_meta( $post_id, Chess_Army_Knife_Announcements::META_SEND_AT, '' !== $date ? Chess_Army_Knife_Events::combine_datetime( $date, '' !== $time ? $time : '09:00' ) : '' );

		if ( empty( $_POST['chess_army_ann_send'] ) || 'auto-draft' === $post->post_status ) {
			update_post_meta( $post_id, Chess_Army_Knife_Announcements::META_STATE, '' );
			return;
		}

		$send_at = (string) get_post_meta( $post_id, Chess_Army_Knife_Announcements::META_SEND_AT, true );
		if ( '' === $send_at || $send_at <= current_time( 'mysql' ) ) {
			Chess_Army_Knife_Announcements::send( $post_id );
		} else {
			update_post_meta( $post_id, Chess_Army_Knife_Announcements::META_STATE, Chess_Army_Knife_Announcements::STATE_SCHEDULED );
		}
	}
}

Chess_Army_Knife_Announcements_Admin::init();
