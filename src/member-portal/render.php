<?php
/**
 * Server-side render for the Member Portal block. It shows one of:
 * - the form asking for a sign-in link (and the "check your email" answer),
 * - the confirmation of a new email address, from an emailed link,
 * - the portal itself, for a signed-in session: each record under the address
 *   with its details, membership, email choices, event places and a delete option.
 * See Chess_Army_Knife_Member_Portal.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$block_title = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$page_url    = get_permalink();
$page_url    = $page_url ? $page_url : home_url( '/' );

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of an outcome or of a private link; nothing is changed until a form below is sent with its own nonce.
$token      = isset( $_GET['cak_portal'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET['cak_portal'] ) ) ) : '';
$email_link = isset( $_GET['cak_email'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET['cak_email'] ) ) ) : '';
$sent       = isset( $_GET['cak_portal_sent'] );
$message    = isset( $_GET['cak_portal_msg'] ) ? Chess_Army_Knife_Member_Portal::message( sanitize_key( wp_unslash( $_GET['cak_portal_msg'] ) ) ) : '';
$error_text = isset( $_GET['cak_portal_error'] ) ? Chess_Army_Knife_Member_Portal::error_message( sanitize_key( wp_unslash( $_GET['cak_portal_error'] ) ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$session = '' !== $token ? Chess_Army_Knife_Member_Portal::session( $token ) : null;
$change  = '' !== $email_link ? Chess_Army_Knife_Member_Portal::pending_email_change( $email_link ) : null;

$categories  = Chess_Army_Knife_Notification_Preferences::categories();
$labels      = Chess_Army_Knife_Membership_Store::status_labels() + array( Chess_Army_Knife_Membership_Store::STATUS_EXPIRED => __( 'Expired', 'chess-army-knife' ) );
$date_format = get_option( 'date_format' );
$today       = current_time( 'Y-m-d' );

/**
 * The hidden fields every portal form carries.
 *
 * @param string $action    Action of the handler.
 * @param string $token     Session token.
 * @param int    $person_id Record the form is about, or 0.
 * @param string $page_url  The page, to come back to.
 */
$cak_portal_fields = function ( $action, $token, $person_id, $page_url ) {
	?>
	<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
	<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
	<?php if ( $person_id ) : ?>
		<input type="hidden" name="person" value="<?php echo esc_attr( $person_id ); ?>" />
	<?php endif; ?>
	<input type="hidden" name="cak_redirect" value="<?php echo esc_url( $page_url ); ?>" />
	<?php wp_nonce_field( $action, Chess_Army_Knife_Member_Portal::NONCE_FIELD, false ); ?>
	<?php
};
$admin_post        = admin_url( 'admin-post.php' );

// The field each error is about, so the message can link to it and the field can point back.
$error_code   = isset( $_GET['cak_portal_error'] ) ? sanitize_key( wp_unslash( $_GET['cak_portal_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the outcome of a submission.
$kept_id      = (int) Chess_Army_Knife_Form_State::value( 'person' );
$new_field_id = 'cak-new-' . $kept_id . '-' . sanitize_key( Chess_Army_Knife_Form_State::value( 'field' ) );
$error_fields = array(
	'email'      => $session ? array( $new_field_id, __( 'New address', 'chess-army-knife' ) ) : array( 'cak-portal-email', __( 'Email address', 'chess-army-knife' ) ),
	'same_email' => array( $new_field_id, __( 'New address', 'chess-army-knife' ) ),
	'name'       => array( 'cak-name-' . $kept_id, __( 'Name', 'chess-army-knife' ) ),
	'guardian'   => array( 'cak-gname-' . $kept_id, __( 'Parent or guardian name', 'chess-army-knife' ) ),
	'confirm'    => array( 'cak-delete-' . $kept_id, __( 'Yes, delete these details', 'chess-army-knife' ) ),
);
$error_field  = isset( $error_fields[ $error_code ] ) ? $error_fields[ $error_code ] : array( '', '' );
$notice_id    = 'cak-portal-notice';
$attrs        = function ( $field_id ) use ( $error_field, $notice_id ) {
	return Chess_Army_Knife_A11y::field_attrs( $field_id, $error_field[0], $notice_id );
};
$heading      = function ( $depth, $text ) {
	return Chess_Army_Knife_A11y::heading( $depth, 'cak-portal__subheading', $text );
};
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( Chess_Army_Knife_Templates::wrapper_attributes( 'member-portal', $attributes, array( 'id' => Chess_Army_Knife_Member_Portal::ANCHOR ) ) ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-portal__heading', '' !== $block_title ? $block_title : __( 'My membership', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php if ( '' !== $error_text ) : ?>
		<?php echo Chess_Army_Knife_A11y::notice( 'error', $notice_id, $error_text, $error_field[0], $error_field[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice(). ?>
	<?php elseif ( '' !== $message ) : ?>
		<?php echo Chess_Army_Knife_A11y::notice( 'success', $notice_id, $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice(). ?>
	<?php elseif ( $sent ) : ?>
		<?php echo Chess_Army_Knife_A11y::notice( 'success', $notice_id, __( 'Thank you. If the club holds details under that email address, we have emailed you a link. It can take a few minutes to arrive, so check your junk folder too.', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice(). ?>
	<?php elseif ( '' !== $token && ! $session ) : ?>
		<?php echo Chess_Army_Knife_A11y::notice( 'error', $notice_id, Chess_Army_Knife_Member_Portal::error_message( 'link' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice(). ?>
	<?php endif; ?>

	<?php if ( $change ) : ?>
		<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
			<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_EMAIL_CONFIRM, $email_link, 0, $page_url ); ?>
			<p>
				<?php
				/* translators: %s: the new email address */
				echo esc_html( sprintf( __( 'Use %s as the email address the club holds for you?', 'chess-army-knife' ), $change['new'] ) );
				?>
			</p>
			<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Confirm my new address', 'chess-army-knife' ); ?></button></p>
		</form>
	<?php elseif ( $session ) : ?>
		<?php if ( $session['expires'] > 0 ) : ?>
			<div
				class="cak-portal__session"
				data-cak-remaining="<?php echo esc_attr( max( 0, $session['expires'] - time() ) ); ?>"
				data-cak-warn="<?php echo esc_attr( Chess_Army_Knife_Member_Portal::WARN_SECONDS ); ?>"
				data-msg-warning="<?php /* translators: %d: minutes left */ esc_attr_e( 'Your session ends in %d minutes. Use the button "Keep me signed in" to carry on.', 'chess-army-knife' ); ?>"
				data-msg-ended="<?php esc_attr_e( 'Your session has ended, and changes you had not saved are lost. Ask for a new link to sign in again.', 'chess-army-knife' ); ?>"
			>
				<p>
					<?php
					/* translators: %s: the time the session ends, such as 14:30 */
					echo esc_html( sprintf( __( 'You are signed in until %s. Saving anything, or the button below, starts the hour again.', 'chess-army-knife' ), wp_date( get_option( 'time_format' ), $session['expires'] ) ) );
					?>
				</p>
				<p class="cak-portal__session-warning" role="alert"></p>
				<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
					<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_EXTEND, $token, 0, $page_url ); ?>
					<button type="submit" class="wp-element-button is-style-outline"><?php esc_html_e( 'Keep me signed in', 'chess-army-knife' ); ?></button>
				</form>
			</div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Here is what the club holds under your email address. You can correct it, choose what you are emailed about, or delete it.', 'chess-army-knife' ); ?></p>

		<?php foreach ( $session['people'] as $person ) : ?>
			<?php
			// Fill the form back in if this person's details were the ones that came back with an error.
			$kept_person   = Chess_Army_Knife_Form_State::has_values() && (int) Chess_Army_Knife_Form_State::value( 'person' ) === (int) $person['id'];
			$person_status = Chess_Army_Knife_Membership_Store::effective_status( $person, $today );
			$is_junior     = Chess_Army_Knife_Member_Portal::is_junior( $person );
			$has_parent    = '' !== $person['guardian_name'] . $person['guardian_email'];
			$teams         = Chess_Army_Knife_Teams::teams_of_person( $person['id'] );
			$picked        = Chess_Army_Knife_Selection::selections_for_person( $person['id'] );
			?>
			<section class="cak-portal__person">
				<?php echo $heading( 1, $person['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

				<?php echo $heading( 2, __( 'Membership', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
				<dl class="cak-portal__summary">
					<dt><?php esc_html_e( 'Status', 'chess-army-knife' ); ?></dt>
					<dd><?php echo esc_html( isset( $labels[ $person_status ] ) ? $labels[ $person_status ] : $person_status ); ?></dd>
					<?php if ( '' !== $person['type_name'] ) : ?>
						<dt><?php esc_html_e( 'Type', 'chess-army-knife' ); ?></dt>
						<dd><?php echo esc_html( $person['type_name'] ); ?></dd>
					<?php endif; ?>
					<?php if ( '' !== $person['expiry_date'] ) : ?>
						<dt><?php esc_html_e( 'Last day', 'chess-army-knife' ); ?></dt>
						<dd><?php echo esc_html( mysql2date( $date_format, $person['expiry_date'] ) ); ?></dd>
					<?php endif; ?>
					<?php if ( Chess_Army_Knife_Membership_Store::STATUS_NONMEMBER !== $person['status'] ) : ?>
						<dt><?php esc_html_e( 'Payment reference', 'chess-army-knife' ); ?></dt>
						<dd><?php echo esc_html( Chess_Army_Knife_Memberships::payment_reference( $person['id'] ) ); ?></dd>
					<?php endif; ?>
					<?php if ( $teams ) : ?>
						<dt><?php esc_html_e( 'Teams', 'chess-army-knife' ); ?></dt>
						<dd><?php echo esc_html( implode( ', ', wp_list_pluck( $teams, 'name' ) ) ); ?></dd>
					<?php endif; ?>
					<?php if ( null !== $person['ecf_rating'] ) : ?>
						<dt><?php esc_html_e( 'ECF rating', 'chess-army-knife' ); ?></dt>
						<dd><?php echo esc_html( (string) $person['ecf_rating'] ); ?></dd>
					<?php endif; ?>
				</dl>
				<?php $whatsapp_groups = Chess_Army_Knife_Teams::whatsapp_groups_for_person( $person ); ?>
				<?php if ( $whatsapp_groups ) : ?>
					<?php echo $heading( 2, __( 'WhatsApp groups', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
					<p><?php esc_html_e( 'You agreed to be added to the WhatsApp groups of your teams. You can join with these links. Please do not pass them on: anyone with the link can join, and everyone in a group can see members\' names and phone numbers.', 'chess-army-knife' ); ?></p>
					<ul class="cak-portal__whatsapp">
						<?php foreach ( $whatsapp_groups as $group ) : ?>
							<li><a href="<?php echo esc_url( $group['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sprintf( /* translators: %s: team name */ __( 'Join the %s WhatsApp group', 'chess-army-knife' ), $group['name'] ) ); ?> <?php echo Chess_Army_Knife_A11y::hidden( __( '(opens in a new tab)', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden(). ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( in_array( $person_status, array( Chess_Army_Knife_Membership_Store::STATUS_ACTIVE, Chess_Army_Knife_Membership_Store::STATUS_EXPIRED ), true ) && '' !== Chess_Army_Knife_Memberships::payment_instructions() ) : ?>
					<p class="cak-portal__pay"><?php echo esc_html( Chess_Army_Knife_Memberships::payment_instructions() ); ?></p>
				<?php endif; ?>

				<?php echo $heading( 2, __( 'Your details', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
				<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
					<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_DETAILS, $token, $person['id'], $page_url ); ?>
					<p class="cak-portal__field"><label for="cak-name-<?php echo esc_attr( $person['id'] ); ?>"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::required(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in required(). ?></label><input type="text" id="cak-name-<?php echo esc_attr( $person['id'] ); ?>" name="name" value="<?php echo esc_attr( $kept_person ? Chess_Army_Knife_Form_State::value( 'name', $person['name'] ) : $person['name'] ); ?>" autocomplete="name" required <?php echo $attrs( 'cak-name-' . $person['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?> /></p>
					<?php if ( ! $is_junior || '' !== $person['phone'] ) : ?>
						<p class="cak-portal__field"><label for="cak-phone-<?php echo esc_attr( $person['id'] ); ?>"><?php esc_html_e( 'Phone', 'chess-army-knife' ); ?></label><input type="tel" id="cak-phone-<?php echo esc_attr( $person['id'] ); ?>" name="phone" value="<?php echo esc_attr( $kept_person ? Chess_Army_Knife_Form_State::value( 'phone', $person['phone'] ) : $person['phone'] ); ?>" autocomplete="tel" /></p>
					<?php endif; ?>
					<p class="cak-portal__field"><label for="cak-ecf-<?php echo esc_attr( $person['id'] ); ?>"><?php esc_html_e( 'ECF rating code', 'chess-army-knife' ); ?></label><input type="text" id="cak-ecf-<?php echo esc_attr( $person['id'] ); ?>" name="ecf_code" value="<?php echo esc_attr( $kept_person ? Chess_Army_Knife_Form_State::value( 'ecf_code', $person['ecf_code'] ) : $person['ecf_code'] ); ?>" /></p>
					<?php if ( $has_parent ) : ?>
						<p class="cak-portal__field"><label for="cak-gname-<?php echo esc_attr( $person['id'] ); ?>"><?php esc_html_e( 'Parent or guardian name', 'chess-army-knife' ); ?></label><input type="text" id="cak-gname-<?php echo esc_attr( $person['id'] ); ?>" name="guardian_name" value="<?php echo esc_attr( $kept_person ? Chess_Army_Knife_Form_State::value( 'guardian_name', $person['guardian_name'] ) : $person['guardian_name'] ); ?>" <?php echo $attrs( 'cak-gname-' . $person['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?> /></p>
						<p class="cak-portal__field"><label for="cak-gphone-<?php echo esc_attr( $person['id'] ); ?>"><?php esc_html_e( 'Parent or guardian phone', 'chess-army-knife' ); ?></label><input type="tel" id="cak-gphone-<?php echo esc_attr( $person['id'] ); ?>" name="guardian_phone" value="<?php echo esc_attr( $kept_person ? Chess_Army_Knife_Form_State::value( 'guardian_phone', $person['guardian_phone'] ) : $person['guardian_phone'] ); ?>" /></p>
					<?php endif; ?>
					<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Save my details', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::hidden( sprintf( /* translators: %s: person's name */ __( ' for %s', 'chess-army-knife' ), $person['name'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden(). ?></button></p>
				</form>

				<?php echo $heading( 2, __( 'Email address', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
				<?php foreach ( Chess_Army_Knife_Member_Portal::EMAIL_FIELDS as $email_field ) : ?>
					<?php if ( '' !== $person[ $email_field ] ) : ?>
						<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
							<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_EMAIL, $token, $person['id'], $page_url ); ?>
							<input type="hidden" name="field" value="<?php echo esc_attr( $email_field ); ?>" />
							<p>
								<?php
								echo esc_html(
									'guardian_email' === $email_field
										/* translators: %s: email address */
										? sprintf( __( 'Parent or guardian email: %s', 'chess-army-knife' ), $person[ $email_field ] )
										/* translators: %s: email address */
										: sprintf( __( 'Email: %s', 'chess-army-knife' ), $person[ $email_field ] )
								);
								?>
							</p>
							<p class="cak-portal__field"><label for="cak-new-<?php echo esc_attr( $person['id'] . '-' . $email_field ); ?>"><?php esc_html_e( 'New address', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::required(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in required(). ?></label><input type="email" id="cak-new-<?php echo esc_attr( $person['id'] . '-' . $email_field ); ?>" name="new_email" value="<?php echo esc_attr( $kept_person && Chess_Army_Knife_Form_State::value( 'field' ) === $email_field ? Chess_Army_Knife_Form_State::value( 'new_email' ) : '' ); ?>" autocomplete="email" required <?php echo $attrs( 'cak-new-' . $person['id'] . '-' . $email_field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?> /></p>
							<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Change address', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::hidden( sprintf( /* translators: %s: person's name */ __( ' for %s', 'chess-army-knife' ), $person['name'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden(). ?></button> <span class="cak-portal__hint"><?php esc_html_e( 'We will email the new address a link to confirm.', 'chess-army-knife' ); ?></span></p>
						</form>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php echo $heading( 2, __( 'What we may email you', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
				<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
					<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_CHOICES, $token, $person['id'], $page_url ); ?>
					<fieldset class="cak-portal__choices">
					<legend class="cak-visually-hidden"><?php echo esc_html( sprintf( /* translators: %s: person's name */ __( 'Email choices for %s', 'chess-army-knife' ), $person['name'] ) ); ?></legend>
					<?php foreach ( $categories as $category => $category_label ) : ?>
						<p class="cak-portal__check"><label><input type="checkbox" name="category[<?php echo esc_attr( $category ); ?>]" value="1" <?php checked( Chess_Army_Knife_Notification_Preferences::allows( $person, $category ) ); ?> /> <?php echo esc_html( $category_label ); ?></label></p>
					<?php endforeach; ?>
					<p class="cak-portal__check"><label><input type="checkbox" name="whatsapp" value="1" <?php checked( '' !== $person['whatsapp_consent_at'] ); ?> /> <?php esc_html_e( 'Add me to the WhatsApp group of the team(s) the club has me in (my phone number is visible to the group)', 'chess-army-knife' ); ?></label></p>
					</fieldset>
					<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Save my choices', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::hidden( sprintf( /* translators: %s: person's name */ __( ' for %s', 'chess-army-knife' ), $person['name'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden(). ?></button></p>
				</form>

				<?php if ( $picked ) : ?>
					<?php echo $heading( 2, __( 'Fixtures you are picked for', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
					<ul>
						<?php foreach ( $picked as $item ) : ?>
							<li>
								<?php
								/* translators: 1: fixture, 2: date, 3: team, 4: board number */
								echo esc_html( sprintf( __( '%1$s, %2$s (%3$s, board %4$d)', 'chess-army-knife' ), $item['event']['title'], Chess_Army_Knife_Events_Display::date_label( $item['event'] ), $item['team']['name'], $item['board'] ) );
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<div class="cak-portal__danger">
					<?php echo $heading( 2, __( 'Delete my details', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
					<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
						<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_DELETE, $token, $person['id'], $page_url ); ?>
						<p><?php esc_html_e( 'This deletes your details now and ends your membership. You cannot undo it. If a payment, tournament or event links your record to the club\'s accounts, the record stays, but with no personal details.', 'chess-army-knife' ); ?></p>
						<p class="cak-portal__check"><label><input type="checkbox" id="cak-delete-<?php echo esc_attr( $person['id'] ); ?>" name="confirm" value="1" required <?php echo $attrs( 'cak-delete-' . $person['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?> /> <?php esc_html_e( 'Yes, delete these details', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::required(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in required(). ?></label></p>
						<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Delete my details', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::hidden( sprintf( /* translators: %s: person's name */ __( ' for %s', 'chess-army-knife' ), $person['name'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden(). ?></button></p>
					</form>
				</div>
			</section>
		<?php endforeach; ?>

		<?php
		$cak_seen_announcements = array();
		foreach ( $session['people'] as $person ) {
			foreach ( Chess_Army_Knife_Announcements::for_person( $person, 10 ) as $announcement ) {
				$cak_seen_announcements[ $announcement['id'] ] = $announcement;
			}
		}
		uasort(
			$cak_seen_announcements,
			function ( $a, $b ) {
				return strcmp( $b['sent_at'], $a['sent_at'] );
			}
		);
		?>
		<?php if ( $cak_seen_announcements ) : ?>
			<section class="cak-portal__announcements">
				<?php echo $heading( 1, sprintf( /* translators: %s: the club's name */ __( '%s announcements', 'chess-army-knife' ), Chess_Army_Knife_Settings::club_name() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
				<?php foreach ( array_slice( $cak_seen_announcements, 0, 10 ) as $announcement ) : ?>
					<article class="cak-portal__announcement">
						<?php echo $heading( 2, $announcement['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
						<p class="cak-portal__date"><?php echo esc_html( mysql2date( $date_format, $announcement['sent_at'] ) ); ?></p>
						<?php echo $announcement['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered with wp_kses_post() when built. ?>
					</article>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
			<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_SIGNOUT, $token, 0, $page_url ); ?>
			<p><button type="submit" class="wp-element-button is-style-outline"><?php esc_html_e( 'Sign out', 'chess-army-knife' ); ?></button></p>
		</form>
	<?php elseif ( ! $sent ) : ?>
		<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
			<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_LINK, '', 0, $page_url ); ?>
			<p class="cak-portal__hp" inert><label>URL <input type="text" name="<?php echo esc_attr( Chess_Army_Knife_Member_Portal::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off" /></label></p>
			<p><?php esc_html_e( 'Enter the email address the club has for you (a junior\'s parent or guardian uses theirs) and we will email you a private link.', 'chess-army-knife' ); ?></p>
			<p class="cak-portal__field"><label for="cak-portal-email"><?php esc_html_e( 'Email address', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::required(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in required(). ?></label><input type="email" id="cak-portal-email" name="email" value="<?php echo esc_attr( Chess_Army_Knife_Form_State::value( 'email' ) ); ?>" autocomplete="email" required <?php echo $attrs( 'cak-portal-email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?> /></p>
			<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Email me a link', 'chess-army-knife' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
