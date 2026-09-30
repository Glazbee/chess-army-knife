<?php
/**
 * The Registration box on the event edit screen: whether the event takes
 * registrations, its capacity and closing time, and (for people who can manage
 * members) the list of who has registered, with attendance.
 *
 * Who registered is personal data, so the names are shown, and people added,
 * removed or marked as attending, only with the membership permission. Anyone
 * who can edit the event sees the totals and can change the settings.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Event_Registrations_Admin {

	const NONCE_ACTION = 'chess_army_knife_save_event_registration';
	const NONCE_FIELD  = 'chess_army_knife_event_registration_nonce';

	/**
	 * Hook up the box.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_' . Chess_Army_Knife_Events::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . Chess_Army_Knife_Events::POST_TYPE, array( __CLASS__, 'save' ) );
	}

	/**
	 * Add the box.
	 */
	public static function add_meta_box() {
		add_meta_box( 'chess_army_event_registration', __( 'Registration', 'chess-army-knife' ), array( __CLASS__, 'render' ), Chess_Army_Knife_Events::POST_TYPE, 'normal', 'default' );
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post $post Event being edited.
	 */
	public static function render( $post ) {
		$settings = Chess_Army_Knife_Event_Registrations::settings( $post->ID );
		$closes   = $settings['closes'];
		$rows     = Chess_Army_Knife_Event_Registrations::for_event( $post->ID );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<p>
			<label><input type="checkbox" name="chess_army_reg_enabled" value="1" <?php checked( $settings['enabled'] ); ?> /> <?php esc_html_e( 'Take registrations for this event', 'chess-army-knife' ); ?></label>
		</p>
		<p class="description"><?php esc_html_e( 'Show the registration form with the Event Registration block on the event\'s page. Until the closing time (or, if there is none, the start of the event) people can register and cancel.', 'chess-army-knife' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="chess_army_reg_capacity"><?php esc_html_e( 'Places', 'chess-army-knife' ); ?></label></th>
				<td>
					<input type="number" min="0" max="10000" id="chess_army_reg_capacity" name="chess_army_reg_capacity" value="<?php echo esc_attr( $settings['capacity'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( '0 means no limit. A person and each guest they bring use a place; when it is full people join a waiting list.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_reg_max_guests"><?php esc_html_e( 'Guests each', 'chess-army-knife' ); ?></label></th>
				<td><input type="number" min="0" max="10" id="chess_army_reg_max_guests" name="chess_army_reg_max_guests" value="<?php echo esc_attr( $settings['max_guests'] ); ?>" class="small-text" /> <span class="description"><?php esc_html_e( 'Most guests one person can bring; 0 for none.', 'chess-army-knife' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_reg_closes_date"><?php esc_html_e( 'Closes', 'chess-army-knife' ); ?></label></th>
				<td>
					<input type="date" id="chess_army_reg_closes_date" name="chess_army_reg_closes_date" value="<?php echo esc_attr( '' !== $closes ? substr( $closes, 0, 10 ) : '' ); ?>" />
					<input type="time" name="chess_army_reg_closes_time" value="<?php echo esc_attr( '' !== $closes ? substr( $closes, 11, 5 ) : '' ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. Leave blank to close when the event starts.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
		</table>

		<?php
		$taken = Chess_Army_Knife_Event_Registrations::places_taken( $post->ID );
		$wait  = count(
			array_filter(
				$rows,
				function ( $row ) {
					return Chess_Army_Knife_Event_Registrations::STATUS_WAITING === $row['status'];
				}
			)
		);
		?>
		<p>
			<strong>
				<?php
				/* translators: 1: places taken, 2: people on the waiting list */
				echo esc_html( sprintf( __( '%1$d places taken, %2$d on the waiting list.', 'chess-army-knife' ), $taken, $wait ) );
				?>
			</strong>
		</p>

		<?php if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) : ?>
			<p class="description"><?php esc_html_e( 'Who has registered is only visible to people who can manage members.', 'chess-army-knife' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<?php if ( $rows ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Status', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Guests', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Came', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Remove', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php $person = Chess_Army_Knife_Membership_Store::get_member( $row['person_id'] ); ?>
						<tr>
							<td><?php echo esc_html( $person ? $person['name'] : '' ); ?></td>
							<td><?php echo esc_html( Chess_Army_Knife_Event_Registrations::STATUS_WAITING === $row['status'] ? __( 'Waiting list', 'chess-army-knife' ) : __( 'Registered', 'chess-army-knife' ) ); ?></td>
							<td><?php echo esc_html( $row['guests'] ); ?></td>
							<td><input type="checkbox" name="chess_army_reg_attended[]" value="<?php echo esc_attr( $row['person_id'] ); ?>" <?php checked( $row['attended'] ); ?> aria-label="<?php esc_attr_e( 'Came', 'chess-army-knife' ); ?>" /></td>
							<td><input type="checkbox" name="chess_army_reg_remove[]" value="<?php echo esc_attr( $row['id'] ); ?>" aria-label="<?php esc_attr_e( 'Remove', 'chess-army-knife' ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<p>
			<label for="chess_army_reg_add"><?php esc_html_e( 'Add a member or guest', 'chess-army-knife' ); ?></label>
			<select id="chess_army_reg_add" name="chess_army_reg_add">
				<option value="0"><?php esc_html_e( 'Choose…', 'chess-army-knife' ); ?></option>
				<?php foreach ( Chess_Army_Knife_Membership_Store::get_players() as $person ) : ?>
					<option value="<?php echo esc_attr( $person['id'] ); ?>"><?php echo esc_html( $person['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Save the box.
	 *
	 * @param int $post_id Event id.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, Chess_Army_Knife_Event_Registrations::META_ENABLED, ! empty( $_POST['chess_army_reg_enabled'] ) ? 1 : 0 );
		update_post_meta( $post_id, Chess_Army_Knife_Event_Registrations::META_CAPACITY, isset( $_POST['chess_army_reg_capacity'] ) ? min( 10000, absint( $_POST['chess_army_reg_capacity'] ) ) : 0 );
		update_post_meta( $post_id, Chess_Army_Knife_Event_Registrations::META_MAX_GUESTS, isset( $_POST['chess_army_reg_max_guests'] ) ? min( 10, absint( $_POST['chess_army_reg_max_guests'] ) ) : 0 );

		$date = isset( $_POST['chess_army_reg_closes_date'] ) ? sanitize_text_field( wp_unslash( $_POST['chess_army_reg_closes_date'] ) ) : '';
		$time = isset( $_POST['chess_army_reg_closes_time'] ) ? sanitize_text_field( wp_unslash( $_POST['chess_army_reg_closes_time'] ) ) : '';
		update_post_meta( $post_id, Chess_Army_Knife_Event_Registrations::META_CLOSES, Chess_Army_Knife_Events::combine_datetime( $date, '' !== $time ? $time : '00:00' ) );

		// Who is registered is personal data: only people who can manage members may change it.
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			return;
		}

		foreach ( isset( $_POST['chess_army_reg_remove'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['chess_army_reg_remove'] ) ) : array() as $registration_id ) {
			$registration = Chess_Army_Knife_Event_Registrations::get_by_id( $registration_id );
			if ( $registration && $registration['event_id'] === (int) $post_id ) {
				Chess_Army_Knife_Event_Registrations::cancel( $registration_id );
			}
		}

		Chess_Army_Knife_Event_Registrations::set_attendance( $post_id, isset( $_POST['chess_army_reg_attended'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['chess_army_reg_attended'] ) ) : array() );

		$add = isset( $_POST['chess_army_reg_add'] ) ? absint( $_POST['chess_army_reg_add'] ) : 0;
		if ( $add ) {
			Chess_Army_Knife_Event_Registrations::register( $post_id, $add, 0, true );
		}
	}
}

Chess_Army_Knife_Event_Registrations_Admin::init();
