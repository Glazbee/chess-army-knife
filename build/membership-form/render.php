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
$intro_text   = isset( $attributes['introText'] ) ? trim( (string) $attributes['introText'] ) : '';
$consent_text = isset( $attributes['consentText'] ) ? trim( (string) $attributes['consentText'] ) : '';

$membership_types = Chess_Army_Knife_Memberships::types();
$page_url         = get_permalink();

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the outcome of a submission; nothing is changed.
$applied_id = isset( $_GET['cak_membership_applied'] ) ? absint( $_GET['cak_membership_applied'] ) : null;
$error_code = isset( $_GET['cak_membership_error'] ) ? sanitize_key( wp_unslash( $_GET['cak_membership_error'] ) ) : '';
$chosen_id  = isset( $_GET['membership_type'] ) ? absint( $_GET['membership_type'] ) : 0;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$payment_text = Chess_Army_Knife_Memberships::payment_instructions();
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes( array( 'id' => Chess_Army_Knife_Membership_Form::ANCHOR ) ) ); ?>>
	<p class="cak-membership-form__heading"><?php echo esc_html( '' !== $block_title ? $block_title : __( 'Apply for membership', 'chess-army-knife' ) ); ?></p>

	<?php if ( null !== $applied_id ) : ?>
		<div class="cak-membership-form__notice cak-membership-form__notice--success" role="status">
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
			<div class="cak-membership-form__notice cak-membership-form__notice--error" role="alert">
				<p><?php echo esc_html( Chess_Army_Knife_Membership_Form::error_message( $error_code ) ); ?></p>
			</div>
		<?php endif; ?>

		<form class="cak-membership-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Chess_Army_Knife_Membership_Form::ACTION ); ?>" />
			<input type="hidden" name="cak_redirect" value="<?php echo esc_url( $page_url ? $page_url : home_url( '/' ) ); ?>" />
			<?php wp_nonce_field( Chess_Army_Knife_Membership_Form::ACTION, Chess_Army_Knife_Membership_Form::NONCE_FIELD, false ); ?>

			<p>
				<label for="cak-member-type"><?php esc_html_e( 'Membership', 'chess-army-knife' ); ?></label>
				<select id="cak-member-type" name="membership_type_id" required>
					<option value=""><?php esc_html_e( 'Choose a membership', 'chess-army-knife' ); ?></option>
					<?php foreach ( $membership_types as $membership_type ) : ?>
						<option value="<?php echo esc_attr( $membership_type['id'] ); ?>" <?php selected( $chosen_id, $membership_type['id'] ); ?>>
							<?php echo esc_html( $membership_type['name'] . ' (' . $membership_type['price_label'] . ( $membership_type['price'] > 0 ? ' ' . $membership_type['period_label'] : '' ) . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="cak-member-name"><?php esc_html_e( 'Full name', 'chess-army-knife' ); ?></label>
				<input type="text" id="cak-member-name" name="name" autocomplete="name" required />
			</p>
			<p>
				<label for="cak-member-email"><?php esc_html_e( 'Email address', 'chess-army-knife' ); ?></label>
				<input type="email" id="cak-member-email" name="email" autocomplete="email" required />
			</p>
			<p>
				<label for="cak-member-phone"><?php esc_html_e( 'Phone (optional)', 'chess-army-knife' ); ?></label>
				<input type="tel" id="cak-member-phone" name="phone" autocomplete="tel" />
			</p>
			<p>
				<label for="cak-member-dob"><?php esc_html_e( 'Date of birth (optional, needed for juniors)', 'chess-army-knife' ); ?></label>
				<input type="date" id="cak-member-dob" name="date_of_birth" autocomplete="bday" />
			</p>
			<p>
				<label for="cak-member-guardian"><?php esc_html_e( 'Parent or guardian (for juniors)', 'chess-army-knife' ); ?></label>
				<input type="text" id="cak-member-guardian" name="guardian_name" />
			</p>
			<p>
				<label for="cak-member-ecf"><?php esc_html_e( 'ECF rating code (optional)', 'chess-army-knife' ); ?></label>
				<input type="text" id="cak-member-ecf" name="ecf_code" />
			</p>

			<?php // Hidden from people; a bot that fills in every field gives itself away. ?>
			<p class="cak-membership-form__trap" aria-hidden="true">
				<label for="cak-member-website"><?php esc_html_e( 'Leave this field empty', 'chess-army-knife' ); ?></label>
				<input type="text" id="cak-member-website" name="<?php echo esc_attr( Chess_Army_Knife_Membership_Form::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
			</p>

			<p class="cak-membership-form__consent">
				<label>
					<input type="checkbox" name="consent" value="1" required />
					<?php echo esc_html( '' !== $consent_text ? $consent_text : __( 'I agree to the club keeping these details to manage my membership.', 'chess-army-knife' ) ); ?>
				</label>
			</p>

			<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Send application', 'chess-army-knife' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
