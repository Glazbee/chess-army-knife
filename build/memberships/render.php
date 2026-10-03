<?php
/**
 * Server-side render for the Club Memberships block: each membership type
 * on offer with its price and description, and how to pay.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$block_title      = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$empty_message    = isset( $attributes['emptyMessage'] ) ? trim( (string) $attributes['emptyMessage'] ) : '';
$empty_message    = Chess_Army_Knife_Settings::with_club( $empty_message );
$show_description = ! isset( $attributes['showDescription'] ) || ! empty( $attributes['showDescription'] );
$show_price       = ! isset( $attributes['showPrice'] ) || ! empty( $attributes['showPrice'] );
$show_payment     = ! isset( $attributes['showPaymentInfo'] ) || ! empty( $attributes['showPaymentInfo'] );
$join_url         = isset( $attributes['joinUrl'] ) ? esc_url_raw( trim( (string) $attributes['joinUrl'] ) ) : '';
$membership_types = Chess_Army_Knife_Memberships::types();
$payment_text     = $show_payment ? Chess_Army_Knife_Memberships::payment_instructions() : '';
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( Chess_Army_Knife_Templates::wrapper_attributes( 'memberships', $attributes ) ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-memberships__heading', '' !== $block_title ? $block_title : __( 'Membership', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php if ( empty( $membership_types ) ) : ?>
		<div class="chess-army-knife-empty"><?php echo esc_html( '' !== $empty_message ? $empty_message : __( 'No memberships are available at the moment.', 'chess-army-knife' ) ); ?></div>
	<?php else : ?>
		<ul class="cak-memberships__list">
			<?php foreach ( $membership_types as $membership_type ) : ?>
				<li class="cak-membership">
					<?php echo Chess_Army_Knife_A11y::heading( 1, 'cak-membership__name', $membership_type['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
					<?php if ( $show_price ) : ?>
						<p class="cak-membership__price">
							<span class="cak-membership__amount"><?php echo esc_html( $membership_type['price_label'] ); ?></span>
							<?php if ( $membership_type['price'] > 0 ) : ?>
								<span class="cak-membership__period"><?php echo esc_html( $membership_type['period_label'] ); ?></span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( $show_description && '' !== $membership_type['description'] ) : ?>
						<p class="cak-membership__description"><?php echo esc_html( $membership_type['description'] ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $join_url ) : ?>
						<p class="cak-membership__join"><a href="<?php echo esc_url( add_query_arg( 'membership_type', $membership_type['id'], $join_url ) . '#' . Chess_Army_Knife_Membership_Form::ANCHOR ); ?>"><?php esc_html_e( 'Apply for this membership', 'chess-army-knife' ); ?><?php echo Chess_Army_Knife_A11y::hidden( ': ' . $membership_type['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden(). ?></a></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( '' !== $payment_text ) : ?>
		<div class="cak-memberships__payment">
			<?php echo Chess_Army_Knife_A11y::heading( 1, 'cak-memberships__payment-heading', __( 'How to pay', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
			<?php echo wp_kses_post( wpautop( esc_html( $payment_text ) ) ); ?>
		</div>
	<?php endif; ?>
</div>
