<?php
/**
 * Server-side render for the Tournament Past Winners block.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its styling is added to the wrapper.
$attributes = Chess_Army_Knife_Templates::apply( 'tournament-winners', $attributes );

$block_title = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$limit       = isset( $attributes['limit'] ) ? max( 1, (int) $attributes['limit'] ) : 10;
$rows        = Chess_Army_Knife_Tournament_Summary::winners( $limit );

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'tournament-winners', $attributes );
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<p class="cak-winners__title"><?php echo esc_html( '' !== $block_title ? $block_title : __( 'Past winners', 'chess-army-knife' ) ); ?></p>

	<?php if ( empty( $rows ) ) : ?>
		<div class="chess-army-knife-empty"><?php esc_html_e( 'No tournaments have finished yet.', 'chess-army-knife' ); ?></div>
	<?php else : ?>
		<table class="cak-winners__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tournament', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Winner', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Finished', 'chess-army-knife' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $finished = strtotime( $row['completed_at'] . ' UTC' ); ?>
					<tr>
						<td><?php echo esc_html( $row['tournament'] ); ?></td>
						<td><?php echo esc_html( $row['winner'] ); ?></td>
						<td><?php echo esc_html( $finished ? wp_date( get_option( 'date_format' ), $finished ) : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
