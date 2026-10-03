<?php
/**
 * Server-side render for the Membership Application Form block. Shows the
 * form, or after it is sent a thank-you with how to pay. The form posts to
 * admin-post.php (see Chess_Army_Knife_Membership_Form) and comes back here.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$block_title  = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$intro_text   = Chess_Army_Knife_Settings::with_club( isset( $attributes['introText'] ) ? trim( (string) $attributes['introText'] ) : '' );
$consent_text = Chess_Army_Knife_Settings::with_club( isset( $attributes['consentText'] ) ? trim( (string) $attributes['consentText'] ) : '' );

$membership_types = Chess_Army_Knife_Memberships::types();
$page_url         = get_permalink();

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the outcome of a submission; nothing is changed.
$applied_id = isset( $_GET['cak_membership_applied'] ) ? absint( $_GET['cak_membership_applied'] ) : null;
$error_code = isset( $_GET['cak_membership_error'] ) ? sanitize_key( wp_unslash( $_GET['cak_membership_error'] ) ) : '';
$chosen_id  = isset( $_GET['membership_type'] ) ? absint( $_GET['membership_type'] ) : 0;
if ( Chess_Army_Knife_Form_State::has_values() ) {
	$chosen_id = absint( Chess_Army_Knife_Form_State::value( 'membership_type_id' ) );
}
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$payment_text = Chess_Army_Knife_Memberships::payment_instructions();

// The field each error is about, so the message can link to it and the field can point back.
$error_fields = array(
	'member_name'     => array( 'cak-member-name', __( 'Full name', 'chess-army-knife' ) ),
	'member_email'    => array( 'cak-member-email', __( 'Email address', 'chess-army-knife' ) ),
	'member_dob'      => array( 'cak-member-dob', __( 'Junior\'s date of birth', 'chess-army-knife' ) ),
	'member_type'     => array( 'cak-member-type', __( 'Membership', 'chess-army-knife' ) ),
	'member_whatsapp' => array( 'cak-member-phone', __( 'Phone', 'chess-army-knife' ) ),
	'member_guardian' => array( 'cak-member-guardian', __( 'Parent or guardian\'s name', 'chess-army-knife' ) ),
	'consent'         => array( 'cak-member-consent', __( 'I have read how the club uses these details', 'chess-army-knife' ) ),
);
$error_field  = isset( $error_fields[ $error_code ] ) ? $error_fields[ $error_code ] : array( '', '' );
$notice_id    = 'cak-membership-error';
$attrs        = function ( $field_id, $hint_id = '' ) use ( $error_field, $notice_id ) {
	return Chess_Army_Knife_A11y::field_attrs( $field_id, $error_field[0], $notice_id, $hint_id );
};
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( Chess_Army_Knife_Templates::wrapper_attributes( 'membership-form', $attributes, array( 'id' => Chess_Army_Knife_Membership_Form::ANCHOR ) ) ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-membership-form__heading', '' !== $block_title ? $block_title : sprintf( /* translators: %s: the club's name */ __( 'Join %s', 'chess-army-knife' ), Chess_Army_Knife_Settings::club_name() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php if ( null !== $applied_id ) : ?>
		<div id="cak-membership-done" class="cak-form-notice cak-form-notice--success" role="status" tabindex="-1">
			<p><?php esc_html_e( 'Thank you! Your application has been received and the club will be in touch once it has been reviewed.', 'chess-army-knife' ); ?></p>
			<?php if ( $applied_id > 0 ) : ?>
				<p>
					<?php
					/* translators: %s: payment reference, for example MEM-12 */
					echo esc_html( sprintf( __( 'Your payment reference is %s.', 'chess-army-knife' ), Chess_Army_Knife_Memberships::payment_reference( $applied_id ) ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $payment_text ) : ?>
				<p><strong><?php esc_html_e( 'How to pay', 'chess-army-knife' ); ?></strong></p>
				<?php echo wp_kses_post( wpautop( esc_html( $payment_text ) ) ); ?>
			<?php endif; ?>
		</div>
	<?php elseif ( empty( $membership_types ) ) : ?>
		<div class="chess-army-knife-empty"><?php esc_html_e( 'Membership applications are not open at the moment.', 'chess-army-knife' ); ?></div>
	<?php else : ?>
		<?php if ( '' !== $intro_text ) : ?>
			<p class="cak-membership-form__intro"><?php echo esc_html( $intro_text ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $error_code ) : ?>
			<?php echo Chess_Army_Knife_A11y::notice( 'error', $notice_id, Chess_Army_Knife_Membership_Form::error_message( $error_code ), $error_field[0], $error_field[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice(). ?>
		<?php endif; ?>

		<form class="cak-membership-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Chess_Army_Knife_Membership_Form::ACTION ); ?>" />
			<input type="hidden" name="cak_redirect" value="<?php echo esc_url( $page_url ? $page_url : home_url( '/' ) ); ?>" />
			<?php wp_nonce_field( Chess_Army_Knife_Membership_Form::ACTION, Chess_Army_Knife_Membership_Form::NONCE_FIELD, false ); ?>

			<p class="cak-membership-form__hint"><?php esc_html_e( 'Fields marked (required) must be filled in.', 'chess-army-knife' ); ?></p>
			<p>
				<label for="cak-member-type"><?php esc_html_e( 'Membership', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::required(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in required(). ?></label>
				<select id="cak-member-type" name="membership_type_id" required <?php echo $attrs( 'cak-member-type' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?>>
					<option value=""><?php esc_html_e( 'Choose a membership', 'chess-army-knife' ); ?></option>
					<?php foreach ( $membership_types as $membership_type ) : ?>
						<option value="<?php echo esc_attr( $membership_type['id'] ); ?>" <?php selected( $chosen_id, $membership_type['id'] ); ?> data-junior="<?php echo $membership_type['is_junior'] ? '1' : '0'; ?>">
							<?php echo esc_html( $membership_type['name'] . ' (' . $membership_type['price_label'] . ( $membership_type['price'] > 0 ? ' ' . $membership_type['period_label'] : '' ) . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
			<fieldset class="cak-membership-form__group">
				<legend><?php esc_html_e( 'About the member', 'chess-army-knife' ); ?></legend>
				<p>
					<label for="cak-member-name"><?php esc_html_e( 'Full name', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::required(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in required(). ?></label>
					<input type="text" id="cak-member-name" name="name" value="<?php echo esc_attr( Chess_Army_Knife_Form_State::value( 'name' ) ); ?>" autocomplete="name" required <?php echo $attrs( 'cak-member-name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?> />
				</p>
				<p>
					<label for="cak-member-email"><?php esc_html_e( 'Email address (adults)', 'chess-army-knife' ); ?></label>
					<input type="email" id="cak-member-email" name="email" value="<?php echo esc_attr( Chess_Army_Knife_Form_State::value( 'email' ) ); ?>" autocomplete="email" <?php echo $attrs( 'cak-member-email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?> />
				</p>
				<p>
					<label for="cak-member-phone"><?php esc_html_e( 'Phone (optional, adults)', 'chess-army-knife' ); ?></label>
					<input type="tel" id="cak-member-phone" name="phone" value="<?php echo esc_attr( Chess_Army_Knife_Form_State::value( 'phone' ) ); ?>" autocomplete="tel" <?php echo $attrs( 'cak-member-phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?> />
				</p>
				<p>
					<label for="cak-member-ecf"><?php esc_html_e( 'ECF rating code (if you have one)', 'chess-army-knife' ); ?></label>
					<span id="cak-member-ecf-hint" class="cak-membership-form__hint"><?php esc_html_e( 'Six numbers and a letter, for example 123456A. Leave it empty if you do not have one.', 'chess-army-knife' ); ?></span>
					<input type="text" id="cak-member-ecf" name="ecf_code" aria-describedby="cak-member-ecf-hint" value="<?php echo esc_attr( Chess_Army_Knife_Form_State::value( 'ecf_code' ) ); ?>" />
				</p>
			</fieldset>

			<?php // Scripts hide this unless a junior type is chosen (see view.js); without a script it always shows. ?>
			<fieldset class="cak-membership-form__group" id="cak-junior-section" aria-describedby="cak-junior-hint">
				<legend><?php esc_html_e( 'Juniors (under 18)', 'chess-army-knife' ); ?></legend>
				<p id="cak-junior-hint" class="cak-membership-form__hint"><?php esc_html_e( 'A parent or guardian must fill in this form for a junior. We write to the parent or guardian. Leave the email and phone boxes above empty, unless you tick the last box below to say we may contact the junior.', 'chess-army-knife' ); ?></p>
				<p class="cak-membership-form__check" data-cak-junior-tick>
					<label><input type="checkbox" name="is_junior" value="1" <?php checked( Chess_Army_Knife_Form_State::checked( 'is_junior' ) ); ?> /> <?php esc_html_e( 'This application is for someone under 18', 'chess-army-knife' ); ?></label>
				</p>
				<p>
					<label for="cak-member-dob"><?php esc_html_e( 'Junior\'s date of birth', 'chess-army-knife' ); ?></label>
					<input type="date" id="cak-member-dob" name="date_of_birth" value="<?php echo esc_attr( Chess_Army_Knife_Form_State::value( 'date_of_birth' ) ); ?>"  <?php echo $attrs( 'cak-member-dob' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?>/>
				</p>
				<p>
					<label for="cak-member-guardian"><?php esc_html_e( 'Parent or guardian\'s name', 'chess-army-knife' ); ?></label>
					<input type="text" id="cak-member-guardian" name="guardian_name" value="<?php echo esc_attr( Chess_Army_Knife_Form_State::value( 'guardian_name' ) ); ?>"  <?php echo $attrs( 'cak-member-guardian' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?>/>
				</p>
				<p>
					<label for="cak-member-guardian-email"><?php esc_html_e( 'Parent or guardian\'s email address', 'chess-army-knife' ); ?></label>
					<input type="email" id="cak-member-guardian-email" name="guardian_email" value="<?php echo esc_attr( Chess_Army_Knife_Form_State::value( 'guardian_email' ) ); ?>" />
				</p>
				<p>
					<label for="cak-member-guardian-phone"><?php esc_html_e( 'Parent or guardian\'s phone (optional)', 'chess-army-knife' ); ?></label>
					<input type="tel" id="cak-member-guardian-phone" name="guardian_phone" value="<?php echo esc_attr( Chess_Army_Knife_Form_State::value( 'guardian_phone' ) ); ?>" />
				</p>
				<p class="cak-membership-form__check">
					<label><input type="checkbox" name="junior_contact" value="1" <?php checked( Chess_Army_Knife_Form_State::checked( 'junior_contact' ) ); ?> /> <?php esc_html_e( 'I am the parent or guardian. The club may also contact the junior, using the email address and phone number entered above.', 'chess-army-knife' ); ?></label>
				</p>
			</fieldset>

			<fieldset class="cak-membership-form__group" aria-describedby="cak-optional-hint">
				<legend><?php esc_html_e( 'Optional: club news and WhatsApp', 'chess-army-knife' ); ?></legend>
				<p id="cak-optional-hint" class="cak-membership-form__hint"><?php esc_html_e( 'These are separate choices. You can say no to both and still be a member, and change your mind at any time.', 'chess-army-knife' ); ?></p>
				<p class="cak-membership-form__check">
					<label><input type="checkbox" name="newsletter" value="1" <?php checked( Chess_Army_Knife_Form_State::checked( 'newsletter' ) ); ?> /> <?php esc_html_e( 'Yes, email me the club newsletter', 'chess-army-knife' ); ?></label>
				</p>
				<p class="cak-membership-form__check">
					<label><input type="checkbox" name="whatsapp" value="1" <?php checked( Chess_Army_Knife_Form_State::checked( 'whatsapp' ) ); ?> /> <?php esc_html_e( 'Yes, add me (or my junior) to the WhatsApp group of the team(s) the club puts me in. Everyone in the group can see the name and phone number.', 'chess-army-knife' ); ?></label>
				</p>
			</fieldset>

			<?php // Hidden from people; a bot that fills in every field gives itself away. ?>
			<p class="cak-membership-form__trap" inert>
				<label for="cak-member-website"><?php esc_html_e( 'Leave this field empty', 'chess-army-knife' ); ?></label>
				<input type="text" id="cak-member-website" name="<?php echo esc_attr( Chess_Army_Knife_Membership_Form::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
			</p>

			<p class="cak-membership-form__hint">
				<?php esc_html_e( 'The club uses these details to run your membership, and gives your name and ECF rating code to the English Chess Federation so your games can be rated.', 'chess-army-knife' ); ?>
				<?php
				// The club data policy page if it is published, otherwise the site's privacy policy.
				$policy_url = Chess_Army_Knife_Policies::url( 'data' );
				$policy_url = '' !== $policy_url ? $policy_url : (string) get_privacy_policy_url();
				?>
				<?php if ( '' !== $policy_url ) : ?>
					<a href="<?php echo esc_url( $policy_url ); ?>"><?php esc_html_e( 'Read how we handle your data', 'chess-army-knife' ); ?></a>
				<?php endif; ?>
			</p>
			<p class="cak-membership-form__consent">
				<label>
					<input type="checkbox" id="cak-member-consent" name="consent" value="1" required <?php echo $attrs( 'cak-member-consent' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?> />
					<?php echo esc_html( '' !== $consent_text ? $consent_text : sprintf( /* translators: %s: the club's name */ __( 'I have read how %s uses these details. If this is for someone under 18, I am their parent or guardian, and I agree for them.', 'chess-army-knife' ), Chess_Army_Knife_Settings::club_name() ) ); ?><?php echo Chess_Army_Knife_A11y::required(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in required(). ?>
				</label>
			</p>

			<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Send application', 'chess-army-knife' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
