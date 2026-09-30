<?php
/**
 * Server-side render for the Event Registration block: the form to register
 * for an event, or the confirmation or cancellation page reached from an
 * emailed link. See Chess_Army_Knife_Event_Registration_Form.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$block_title = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$event_id    = isset( $attributes['eventId'] ) ? absint( $attributes['eventId'] ) : 0;
if ( ! $event_id && Chess_Army_Knife_Events::POST_TYPE === get_post_type() ) {
	$event_id = (int) get_the_ID();
}
$event_post = $event_id ? get_post( $event_id ) : null;
if ( ! $event_post || Chess_Army_Knife_Events::POST_TYPE !== $event_post->post_type ) {
	return;
}

$settings = Chess_Army_Knife_Event_Registrations::settings( $event_id );
if ( ! $settings['enabled'] ) {
	return;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the outcome of a submission and of emailed private links; nothing is changed here.
$token       = isset( $_GET['cak_reg'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET['cak_reg'] ) ) ) : '';
$cancel_link = isset( $_GET['cak_cancel'] ) ? sanitize_text_field( wp_unslash( $_GET['cak_cancel'] ) ) : '';
$sent        = isset( $_GET['cak_reg_sent'] );
$done        = isset( $_GET['cak_reg_done'] ) ? sanitize_key( wp_unslash( $_GET['cak_reg_done'] ) ) : '';
$cancelled   = isset( $_GET['cak_cancel_done'] );
$error_text  = isset( $_GET['cak_reg_error'] ) ? Chess_Army_Knife_Event_Registration_Form::error_message( sanitize_key( wp_unslash( $_GET['cak_reg_error'] ) ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$page_url = get_permalink( $event_post );
$pending  = '' !== $token ? Chess_Army_Knife_Event_Registration_Form::pending( $token ) : null;
$cancel   = '' !== $cancel_link ? Chess_Army_Knife_Event_Registration_Form::registration_for_link( $cancel_link ) : null;
if ( $pending && $pending['event_id'] !== $event_id ) {
	$pending = null;
}
if ( $cancel && $cancel['event_id'] !== $event_id ) {
	$cancel = null;
}

$is_open = Chess_Army_Knife_Event_Registrations::is_open( $event_id );
$left    = Chess_Army_Knife_Event_Registrations::places_left( $event_id );
$full    = null !== $left && $left < 1;
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?> id="<?php echo esc_attr( Chess_Army_Knife_Event_Registration_Form::ANCHOR ); ?>">
	<h2 class="cak-registration__heading"><?php echo esc_html( '' !== $block_title ? $block_title : __( 'Register', 'chess-army-knife' ) ); ?></h2>

	<?php if ( '' !== $error_text ) : ?>
		<p class="cak-registration__notice is-error" role="alert"><?php echo esc_html( $error_text ); ?></p>
	<?php elseif ( $sent ) : ?>
		<p class="cak-registration__notice" role="status"><?php esc_html_e( 'Thank you. If the details were right, we have emailed you a link to confirm your place. It works for 24 hours.', 'chess-army-knife' ); ?></p>
	<?php elseif ( 'waiting' === $done ) : ?>
		<p class="cak-registration__notice" role="status"><?php esc_html_e( 'The event is full, so you are on the waiting list. We will email you if a place becomes free.', 'chess-army-knife' ); ?></p>
	<?php elseif ( '' !== $done ) : ?>
		<p class="cak-registration__notice" role="status"><?php esc_html_e( 'You are registered. We have emailed you the details, with a link to cancel if your plans change.', 'chess-army-knife' ); ?></p>
	<?php elseif ( $cancelled ) : ?>
		<p class="cak-registration__notice" role="status"><?php esc_html_e( 'Your registration has been cancelled.', 'chess-army-knife' ); ?></p>
	<?php endif; ?>

	<?php if ( $pending ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Chess_Army_Knife_Event_Registration_Form::ACTION_CONFIRM ); ?>" />
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
			<input type="hidden" name="cak_redirect" value="<?php echo esc_url( $page_url ); ?>" />
			<?php wp_nonce_field( Chess_Army_Knife_Event_Registration_Form::ACTION_CONFIRM, Chess_Army_Knife_Event_Registration_Form::NONCE_FIELD ); ?>
			<?php if ( $pending['people'] ) : ?>
				<p><?php esc_html_e( 'Who is coming?', 'chess-army-knife' ); ?></p>
				<?php foreach ( $pending['people'] as $person ) : ?>
					<p class="cak-registration__field">
						<label>
							<input type="checkbox" name="people[<?php echo esc_attr( $person['id'] ); ?>]" value="1" checked="checked" />
							<?php echo esc_html( $person['name'] ); ?>
						</label>
						<?php if ( $settings['max_guests'] > 0 ) : ?>
							<label><?php esc_html_e( 'Guests with them', 'chess-army-knife' ); ?>
								<input type="number" min="0" max="<?php echo esc_attr( $settings['max_guests'] ); ?>" name="guests[<?php echo esc_attr( $person['id'] ); ?>]" value="<?php echo esc_attr( min( $settings['max_guests'], $pending['guests'] ) ); ?>" />
							</label>
						<?php endif; ?>
					</p>
				<?php endforeach; ?>
			<?php else : ?>
				<p>
					<?php
					/* translators: 1: name, 2: email address */
					echo esc_html( sprintf( __( 'Register %1$s (%2$s) as a guest of the club?', 'chess-army-knife' ), $pending['name'], $pending['email'] ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( $full ) : ?>
				<p><?php esc_html_e( 'The event is full: you will be put on the waiting list.', 'chess-army-knife' ); ?></p>
			<?php endif; ?>
			<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Confirm', 'chess-army-knife' ); ?></button></p>
		</form>
	<?php elseif ( $cancel ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Chess_Army_Knife_Event_Registration_Form::ACTION_CANCEL ); ?>" />
			<input type="hidden" name="cancel" value="<?php echo esc_attr( $cancel_link ); ?>" />
			<input type="hidden" name="cak_redirect" value="<?php echo esc_url( $page_url ); ?>" />
			<?php wp_nonce_field( Chess_Army_Knife_Event_Registration_Form::ACTION_CANCEL, Chess_Army_Knife_Event_Registration_Form::NONCE_FIELD ); ?>
			<p><?php esc_html_e( 'Cancel this registration?', 'chess-army-knife' ); ?></p>
			<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Cancel my registration', 'chess-army-knife' ); ?></button></p>
		</form>
	<?php elseif ( ! $is_open ) : ?>
		<p><?php esc_html_e( 'Registration for this event is closed.', 'chess-army-knife' ); ?></p>
	<?php else : ?>
		<?php if ( null !== $left ) : ?>
			<p class="cak-registration__places">
				<?php
				echo esc_html(
					$full
						? __( 'The event is full. You can join the waiting list.', 'chess-army-knife' )
						/* translators: %d: number of places left */
						: sprintf( _n( '%d place left.', '%d places left.', $left, 'chess-army-knife' ), $left )
				);
				?>
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Chess_Army_Knife_Event_Registration_Form::ACTION_REQUEST ); ?>" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
			<input type="hidden" name="cak_redirect" value="<?php echo esc_url( $page_url ); ?>" />
			<?php wp_nonce_field( Chess_Army_Knife_Event_Registration_Form::ACTION_REQUEST, Chess_Army_Knife_Event_Registration_Form::NONCE_FIELD ); ?>
			<p class="cak-registration__hp" aria-hidden="true"><label>URL <input type="text" name="<?php echo esc_attr( Chess_Army_Knife_Event_Registration_Form::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off" /></label></p>
			<p class="cak-registration__field"><label for="cak-reg-name"><?php esc_html_e( 'Your name', 'chess-army-knife' ); ?></label><input type="text" id="cak-reg-name" name="name" required /></p>
			<p class="cak-registration__field"><label for="cak-reg-email"><?php esc_html_e( 'Email address', 'chess-army-knife' ); ?></label><input type="email" id="cak-reg-email" name="email" required /></p>
			<?php if ( $settings['max_guests'] > 0 ) : ?>
				<p class="cak-registration__field"><label for="cak-reg-guests"><?php esc_html_e( 'Guests you are bringing', 'chess-army-knife' ); ?></label><input type="number" id="cak-reg-guests" name="guests" min="0" max="<?php echo esc_attr( $settings['max_guests'] ); ?>" value="0" /></p>
			<?php endif; ?>
			<p class="cak-registration__check"><label><input type="checkbox" name="consent" value="1" required /> <?php esc_html_e( 'The club may keep my name and email address to manage this registration, as set out in its data policy.', 'chess-army-knife' ); ?></label></p>
			<p><button type="submit" class="wp-element-button"><?php echo esc_html( $full ? __( 'Join the waiting list', 'chess-army-knife' ) : __( 'Register', 'chess-army-knife' ) ); ?></button></p>
			<p class="description"><?php esc_html_e( 'We will email you a link to confirm your place.', 'chess-army-knife' ); ?></p>
		</form>
	<?php endif; ?>
</div>
