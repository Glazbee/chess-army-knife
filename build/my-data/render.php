<?php
/**
 * Server-side render for the Manage My Data block. It shows one of:
 * - the form asking what the visitor wants to do,
 * - a plain "check your email" message, the same whether or not the address is on file,
 * - after following the emailed link, the members under that address with their
 *   newsletter and WhatsApp choices,
 * - the confirmation that the choices were saved.
 * See Chess_Army_Knife_Member_Requests.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$block_title = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$page_url    = get_permalink();

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of an outcome or of a private link; nothing is changed until the form below is sent with its own nonce.
$sent       = isset( $_GET['cak_data_sent'] );
$done       = isset( $_GET['cak_data_done'] );
$error_code = isset( $_GET['cak_data_error'] ) ? sanitize_key( wp_unslash( $_GET['cak_data_error'] ) ) : '';
$token      = isset( $_GET['cak_withdraw'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET['cak_withdraw'] ) ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$team_choices = Chess_Army_Knife_Teams::choices();
$found        = '' !== $token ? Chess_Army_Knife_Member_Requests::people_for_token( $token ) : null;

$error_fields = array(
	'email'  => array( 'cak-data-email', __( 'The email address the club holds for you', 'chess-army-knife' ) ),
	'choice' => array( 'cak-data-choice-withdraw', __( 'What would you like to do?', 'chess-army-knife' ) ),
);
$error_field  = isset( $error_fields[ $error_code ] ) ? $error_fields[ $error_code ] : array( '', '' );
$notice_id    = 'cak-data-notice';
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes( array( 'id' => Chess_Army_Knife_Member_Requests::ANCHOR ) ) ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-my-data__heading', '' !== $block_title ? $block_title : __( 'Manage my data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php if ( '' !== $error_code ) : ?>
		<?php echo Chess_Army_Knife_A11y::notice( 'error', $notice_id, Chess_Army_Knife_Member_Requests::error_message( $error_code ), $error_field[0], $error_field[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice(). ?>
	<?php endif; ?>

	<?php if ( $done ) : ?>
		<?php echo Chess_Army_Knife_A11y::notice( 'success', $notice_id, __( 'Thank you. Your choices have been saved.', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice(). ?>
	<?php elseif ( $sent ) : ?>
		<?php echo Chess_Army_Knife_A11y::notice( 'success', $notice_id, __( 'Thank you. If the club holds details under that email address, we have emailed you. Please follow the link in the email to carry on. It can take a few minutes to arrive, so check your junk folder too.', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice(). ?>
	<?php elseif ( '' !== $token && ! $found ) : ?>
		<?php echo Chess_Army_Knife_A11y::notice( 'error', $notice_id, Chess_Army_Knife_Member_Requests::error_message( 'link' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice(). ?>
	<?php endif; ?>

	<?php if ( $found && ! $done ) : ?>
		<form class="cak-my-data__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Chess_Army_Knife_Member_Requests::ACTION_WITHDRAW ); ?>" />
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
			<input type="hidden" name="cak_redirect" value="<?php echo esc_url( $page_url ? $page_url : home_url( '/' ) ); ?>" />
			<?php wp_nonce_field( Chess_Army_Knife_Member_Requests::ACTION_WITHDRAW, Chess_Army_Knife_Member_Requests::NONCE_FIELD, false ); ?>

			<p><?php esc_html_e( 'Tick what you are happy for the club to do. Untick anything you would like to stop. You stay a member either way.', 'chess-army-knife' ); ?></p>
			<?php foreach ( $found['people'] as $person ) : ?>
				<fieldset class="cak-my-data__person">
					<legend><?php echo esc_html( $person['name'] ); ?></legend>
					<p>
						<label>
							<input type="checkbox" name="newsletter[<?php echo esc_attr( $person['id'] ); ?>]" value="1" <?php checked( '' !== $person['newsletter_consent_at'] ); ?> />
							<?php esc_html_e( 'Send the club newsletter by email', 'chess-army-knife' ); ?>
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" name="whatsapp[<?php echo esc_attr( $person['id'] ); ?>]" value="1" <?php checked( '' !== $person['whatsapp_consent_at'] ); ?> />
							<?php esc_html_e( 'Add to the WhatsApp group for the team(s) ticked below (the phone number is visible to the group)', 'chess-army-knife' ); ?>
						</label>
					</p>
					<?php foreach ( $team_choices as $team_id => $team_name ) : ?>
						<p class="cak-my-data__team">
							<label>
								<input type="checkbox" name="teams[<?php echo esc_attr( $person['id'] ); ?>][]" value="<?php echo esc_attr( $team_id ); ?>" <?php checked( in_array( $team_id, $person['whatsapp_teams'], true ) ); ?> />
								<?php echo esc_html( $team_name ); ?>
							</label>
						</p>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>
			<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Save my choices', 'chess-army-knife' ); ?></button></p>
		</form>
	<?php elseif ( ! $done && ! $sent ) : ?>
		<form class="cak-my-data__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Chess_Army_Knife_Member_Requests::ACTION_REQUEST ); ?>" />
			<input type="hidden" name="cak_redirect" value="<?php echo esc_url( $page_url ? $page_url : home_url( '/' ) ); ?>" />
			<?php wp_nonce_field( Chess_Army_Knife_Member_Requests::ACTION_REQUEST, Chess_Army_Knife_Member_Requests::NONCE_FIELD, false ); ?>

			<p><?php esc_html_e( 'You can change your choices, ask for a copy of your details, or ask us to delete them. For a junior, use their parent or guardian\'s email address. We will email you a link to check it is you.', 'chess-army-knife' ); ?></p>

			<p>
				<label for="cak-data-email"><?php esc_html_e( 'The email address the club holds for you', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::required(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in required(). ?></label>
				<input type="email" id="cak-data-email" name="email" value="<?php echo esc_attr( Chess_Army_Knife_Form_State::value( 'email' ) ); ?>" autocomplete="email" required <?php echo Chess_Army_Knife_A11y::field_attrs( 'cak-data-email', $error_field[0], $notice_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_attrs(). ?> />
			</p>

			<fieldset class="cak-my-data__choices">
				<legend><?php esc_html_e( 'What would you like to do?', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::required(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in required(). ?></legend>
				<p><label><input type="radio" id="cak-data-choice-withdraw" name="choice" value="withdraw" <?php checked( Chess_Army_Knife_Form_State::checked( 'choice', 'withdraw' ) ); ?> required /> <?php esc_html_e( 'Change my newsletter and WhatsApp choices', 'chess-army-knife' ); ?></label></p>
				<p><label><input type="radio" name="choice" value="export" <?php checked( Chess_Army_Knife_Form_State::checked( 'choice', 'export' ) ); ?> /> <?php esc_html_e( 'Send me a copy of my details', 'chess-army-knife' ); ?></label></p>
				<p><label><input type="radio" name="choice" value="erase" <?php checked( Chess_Army_Knife_Form_State::checked( 'choice', 'erase' ) ); ?> /> <?php esc_html_e( 'Delete my details (this ends my membership)', 'chess-army-knife' ); ?></label></p>
			</fieldset>

			<?php // Hidden from people; a bot that fills in every field gives itself away. ?>
			<p class="cak-my-data__trap" inert>
				<label for="cak-data-url"><?php esc_html_e( 'Leave this field empty', 'chess-army-knife' ); ?></label>
				<input type="text" id="cak-data-url" name="<?php echo esc_attr( Chess_Army_Knife_Member_Requests::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
			</p>

			<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Email me a link to continue', 'chess-army-knife' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
