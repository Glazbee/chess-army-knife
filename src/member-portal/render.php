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

$team_choices = Chess_Army_Knife_Teams::choices();
$categories   = Chess_Army_Knife_Notification_Preferences::categories();
$labels       = Chess_Army_Knife_Membership_Store::status_labels() + array( Chess_Army_Knife_Membership_Store::STATUS_EXPIRED => __( 'Expired', 'chess-army-knife' ) );
$date_format  = get_option( 'date_format' );
$today        = current_time( 'Y-m-d' );

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
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes( array( 'id' => Chess_Army_Knife_Member_Portal::ANCHOR ) ) ); ?>>
	<h2 class="cak-portal__heading"><?php echo esc_html( '' !== $block_title ? $block_title : __( 'My membership', 'chess-army-knife' ) ); ?></h2>

	<?php if ( '' !== $error_text ) : ?>
		<p class="cak-portal__notice is-error" role="alert"><?php echo esc_html( $error_text ); ?></p>
	<?php elseif ( '' !== $message ) : ?>
		<p class="cak-portal__notice" role="status"><?php echo esc_html( $message ); ?></p>
	<?php elseif ( $sent ) : ?>
		<p class="cak-portal__notice" role="status"><?php esc_html_e( 'Thank you. If the club holds details under that email address, we have emailed you a link. It can take a few minutes to arrive, so check your junk folder too.', 'chess-army-knife' ); ?></p>
	<?php elseif ( '' !== $token && ! $session ) : ?>
		<p class="cak-portal__notice is-error" role="alert"><?php echo esc_html( Chess_Army_Knife_Member_Portal::error_message( 'link' ) ); ?></p>
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
		<p><?php esc_html_e( 'Here is what the club holds under your email address. You can correct it, choose what you are emailed about, or delete it.', 'chess-army-knife' ); ?></p>

		<?php foreach ( $session['people'] as $person ) : ?>
			<?php
			$person_status = Chess_Army_Knife_Membership_Store::effective_status( $person, $today );
			$is_junior     = Chess_Army_Knife_Member_Portal::is_junior( $person );
			$has_parent    = '' !== $person['guardian_name'] . $person['guardian_email'];
			$teams         = Chess_Army_Knife_Teams::teams_of_person( $person['id'] );
			$events        = array();
			foreach ( Chess_Army_Knife_Event_Registrations::for_person( $person['id'] ) as $registration ) {
				$event_post = get_post( $registration['event_id'] );
				if ( $event_post && 'publish' === $event_post->post_status && substr( (string) get_post_meta( $event_post->ID, Chess_Army_Knife_Events::META_START, true ), 0, 10 ) >= $today ) {
					$events[] = array(
						'post'         => $event_post,
						'registration' => $registration,
					);
				}
			}
			?>
			<section class="cak-portal__person">
				<h3><?php echo esc_html( $person['name'] ); ?></h3>

				<h4><?php esc_html_e( 'Membership', 'chess-army-knife' ); ?></h4>
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
				<?php if ( in_array( $person_status, array( Chess_Army_Knife_Membership_Store::STATUS_ACTIVE, Chess_Army_Knife_Membership_Store::STATUS_EXPIRED ), true ) && '' !== Chess_Army_Knife_Memberships::payment_instructions() ) : ?>
					<p class="cak-portal__pay"><?php echo esc_html( Chess_Army_Knife_Memberships::payment_instructions() ); ?></p>
				<?php endif; ?>

				<h4><?php esc_html_e( 'Your details', 'chess-army-knife' ); ?></h4>
				<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
					<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_DETAILS, $token, $person['id'], $page_url ); ?>
					<p class="cak-portal__field"><label for="cak-name-<?php echo esc_attr( $person['id'] ); ?>"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></label><input type="text" id="cak-name-<?php echo esc_attr( $person['id'] ); ?>" name="name" value="<?php echo esc_attr( $person['name'] ); ?>" required /></p>
					<?php if ( ! $is_junior || '' !== $person['phone'] ) : ?>
						<p class="cak-portal__field"><label for="cak-phone-<?php echo esc_attr( $person['id'] ); ?>"><?php esc_html_e( 'Phone', 'chess-army-knife' ); ?></label><input type="tel" id="cak-phone-<?php echo esc_attr( $person['id'] ); ?>" name="phone" value="<?php echo esc_attr( $person['phone'] ); ?>" /></p>
					<?php endif; ?>
					<p class="cak-portal__field"><label for="cak-ecf-<?php echo esc_attr( $person['id'] ); ?>"><?php esc_html_e( 'ECF rating code', 'chess-army-knife' ); ?></label><input type="text" id="cak-ecf-<?php echo esc_attr( $person['id'] ); ?>" name="ecf_code" value="<?php echo esc_attr( $person['ecf_code'] ); ?>" /></p>
					<?php if ( $has_parent ) : ?>
						<p class="cak-portal__field"><label for="cak-gname-<?php echo esc_attr( $person['id'] ); ?>"><?php esc_html_e( 'Parent or guardian name', 'chess-army-knife' ); ?></label><input type="text" id="cak-gname-<?php echo esc_attr( $person['id'] ); ?>" name="guardian_name" value="<?php echo esc_attr( $person['guardian_name'] ); ?>" /></p>
						<p class="cak-portal__field"><label for="cak-gphone-<?php echo esc_attr( $person['id'] ); ?>"><?php esc_html_e( 'Parent or guardian phone', 'chess-army-knife' ); ?></label><input type="tel" id="cak-gphone-<?php echo esc_attr( $person['id'] ); ?>" name="guardian_phone" value="<?php echo esc_attr( $person['guardian_phone'] ); ?>" /></p>
					<?php endif; ?>
					<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Save my details', 'chess-army-knife' ); ?></button></p>
				</form>

				<h4><?php esc_html_e( 'Email address', 'chess-army-knife' ); ?></h4>
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
							<p class="cak-portal__field"><label for="cak-new-<?php echo esc_attr( $person['id'] . '-' . $email_field ); ?>"><?php esc_html_e( 'New address', 'chess-army-knife' ); ?></label><input type="email" id="cak-new-<?php echo esc_attr( $person['id'] . '-' . $email_field ); ?>" name="new_email" required /></p>
							<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Change address', 'chess-army-knife' ); ?></button> <span class="description"><?php esc_html_e( 'We will email the new address a link to confirm.', 'chess-army-knife' ); ?></span></p>
						</form>
					<?php endif; ?>
				<?php endforeach; ?>

				<h4><?php esc_html_e( 'What we may email you', 'chess-army-knife' ); ?></h4>
				<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
					<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_CHOICES, $token, $person['id'], $page_url ); ?>
					<?php foreach ( $categories as $category => $category_label ) : ?>
						<p class="cak-portal__check"><label><input type="checkbox" name="category[<?php echo esc_attr( $category ); ?>]" value="1" <?php checked( Chess_Army_Knife_Notification_Preferences::allows( $person, $category ) ); ?> /> <?php echo esc_html( $category_label ); ?></label></p>
					<?php endforeach; ?>
					<p class="cak-portal__check"><label><input type="checkbox" name="whatsapp" value="1" <?php checked( '' !== $person['whatsapp_consent_at'] ); ?> /> <?php esc_html_e( 'Add me to the WhatsApp group for the team(s) ticked below (my phone number is visible to the group)', 'chess-army-knife' ); ?></label></p>
					<?php foreach ( $team_choices as $team_id => $team_name ) : ?>
						<p class="cak-portal__check"><label><input type="checkbox" name="teams[]" value="<?php echo esc_attr( $team_id ); ?>" <?php checked( in_array( $team_id, $person['whatsapp_teams'], true ) ); ?> /> <?php echo esc_html( $team_name ); ?></label></p>
					<?php endforeach; ?>
					<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Save my choices', 'chess-army-knife' ); ?></button></p>
				</form>

				<?php if ( $events ) : ?>
					<h4><?php esc_html_e( 'Events you are registered for', 'chess-army-knife' ); ?></h4>
					<ul>
						<?php foreach ( $events as $item ) : ?>
							<li>
								<?php echo esc_html( get_the_title( $item['post'] ) ); ?>
								(<?php echo esc_html( Chess_Army_Knife_Event_Registrations::STATUS_WAITING === $item['registration']['status'] ? __( 'waiting list', 'chess-army-knife' ) : __( 'registered', 'chess-army-knife' ) ); ?>)
								<a href="<?php echo esc_url( Chess_Army_Knife_Event_Registration_Form::cancel_url( $item['registration'] ) ); ?>"><?php esc_html_e( 'Cancel', 'chess-army-knife' ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<div class="cak-portal__danger">
					<h4><?php esc_html_e( 'Delete my details', 'chess-army-knife' ); ?></h4>
					<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
						<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_DELETE, $token, $person['id'], $page_url ); ?>
						<p><?php esc_html_e( 'This deletes your details now and ends your membership. It cannot be undone. If a payment, tournament or event ties your record to the club\'s accounts, the record stays but without any personal details.', 'chess-army-knife' ); ?></p>
						<p class="cak-portal__check"><label><input type="checkbox" name="confirm" value="1" required /> <?php esc_html_e( 'Yes, delete these details', 'chess-army-knife' ); ?></label></p>
						<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Delete my details', 'chess-army-knife' ); ?></button></p>
					</form>
				</div>
			</section>
		<?php endforeach; ?>

		<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
			<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_SIGNOUT, $token, 0, $page_url ); ?>
			<p><button type="submit" class="wp-element-button is-style-outline"><?php esc_html_e( 'Sign out', 'chess-army-knife' ); ?></button></p>
		</form>
	<?php elseif ( ! $sent ) : ?>
		<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
			<?php $cak_portal_fields( Chess_Army_Knife_Member_Portal::ACTION_LINK, '', 0, $page_url ); ?>
			<p class="cak-portal__hp" aria-hidden="true"><label>URL <input type="text" name="<?php echo esc_attr( Chess_Army_Knife_Member_Portal::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off" /></label></p>
			<p><?php esc_html_e( 'Enter the email address the club has for you (a junior\'s parent or guardian uses theirs) and we will email you a private link.', 'chess-army-knife' ); ?></p>
			<p class="cak-portal__field"><label for="cak-portal-email"><?php esc_html_e( 'Email address', 'chess-army-knife' ); ?></label><input type="email" id="cak-portal-email" name="email" required /></p>
			<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Email me a link', 'chess-army-knife' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
