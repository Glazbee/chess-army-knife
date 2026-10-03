<?php
/**
 * Server-side render for the Club Officers block: each position with the name
 * of whoever holds it now, and team captains. Only positions and names are
 * shown, and when they began if the block is set to.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$attributes   = Chess_Army_Knife_Templates::apply( 'officers', $attributes );
$block_title  = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$show_tenure  = ! empty( $attributes['showTenure'] );
$with_captain = ! empty( $attributes['includeCaptains'] );
$officers     = Chess_Army_Knife_Officers::listing(
	array(
		'order'            => isset( $attributes['order'] ) ? $attributes['order'] : array(),
		'include_captains' => $with_captain,
	)
);
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( Chess_Army_Knife_Templates::wrapper_attributes( 'officers', $attributes ) ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-officers__heading', '' !== $block_title ? $block_title : sprintf( /* translators: %s: the club's name */ __( '%s officers', 'chess-army-knife' ), Chess_Army_Knife_Settings::club_name() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
	<?php if ( ! $officers ) : ?>
		<p><?php esc_html_e( 'No officers to show yet.', 'chess-army-knife' ); ?></p>
	<?php else : ?>
		<dl class="cak-officers__list">
			<?php foreach ( $officers as $officer ) : ?>
				<div class="cak-officer">
					<dt class="cak-officer__position"><?php echo esc_html( $officer['label'] ); ?></dt>
					<?php foreach ( $officer['people'] as $person ) : ?>
						<dd class="cak-officer__name">
							<?php echo esc_html( $person['name'] ); ?>
							<?php if ( $show_tenure && '' !== $person['since'] ) : ?>
								<span class="cak-officer__tenure">
									<?php
									/* translators: %s: the date someone took up a position */
									echo wp_kses( sprintf( esc_html__( 'since %s', 'chess-army-knife' ), Chess_Army_Knife_A11y::date( $person['since'] ) ), array( 'time' => array( 'datetime' => true ) ) );
									?>
								</span>
							<?php endif; ?>
						</dd>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>
</div>
