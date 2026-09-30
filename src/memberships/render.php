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
$show_description = ! isset( $attributes['showDescription'] ) || ! empty( $attributes['showDescription'] );
$show_price       = ! isset( $attributes['showPrice'] ) || ! empty( $attributes['showPrice'] );
$show_payment     = ! isset( $attributes['showPaymentInfo'] ) || ! empty( $attributes['showPaymentInfo'] );
$join_url         = isset( $attributes['joinUrl'] ) ? esc_url_raw( trim( (string) $attributes['joinUrl'] ) ) : '';
$membership_types = Chess_Army_Knife_Memberships::types();
$payment_text     = $show_payment ? Chess_Army_Knife_Memberships::payment_instructions() : '';
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<p class="cak-memberships__heading"><?php echo esc_html( '' !== $block_title ? $block_title : __( 'Membership', 'chess-army-knife' ) ); ?></p>

	<?php if ( empty( $membership_types ) ) : ?>
		<div class="chess-army-knife-empty"><?php echo esc_html( '' !== $empty_message ? $empty_message : __( 'No memberships are available at the moment.', 'chess-army-knife' ) ); ?></div>
	<?php else : ?>
		<div class="cak-memberships__list">
			<?php foreach ( $membership_types as $membership_type ) : ?>
				<div class="cak-membership">
					<p class="cak-membership__name"><?php echo esc_html( $membership_type['name'] ); ?></p>
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
						<p class="cak-membership__join"><a href="<?php echo esc_url( add_query_arg( 'membership_type', $membership_type['id'], $join_url ) . '#' . Chess_Army_Knife_Membership_Form::ANCHOR ); ?>"><?php esc_html_e( 'Apply for this membership', 'chess-army-knife' ); ?></a></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $payment_text ) : ?>
		<div class="cak-memberships__payment">
			<p class="cak-memberships__payment-heading"><?php esc_html_e( 'How to pay', 'chess-army-knife' ); ?></p>
			<?php echo wp_kses_post( wpautop( esc_html( $payment_text ) ) ); ?>
		</div>
	<?php endif; ?>
</div>
