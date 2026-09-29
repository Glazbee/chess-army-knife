<?php
/**
 * Server-side render for the Tournament Players block.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its styling is added to the wrapper.
$attributes = Chess_Army_Knife_Templates::apply( 'tournament-players', $attributes );

$tournament_id = isset( $attributes['tournamentId'] ) ? (int) $attributes['tournamentId'] : 0;
$tournament    = $tournament_id ? Chess_Army_Knife_Tournament_Store::get_tournament( $tournament_id ) : null;

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'tournament-players', $attributes );

if ( ! $tournament ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'Tournament Players: choose a tournament in the block settings.', 'chess-army-knife' )
	);
	return;
}

$players = Chess_Army_Knife_Tournament_Summary::players( $tournament );
$started = Chess_Army_Knife_Tournaments::STATUS_DRAFT !== $tournament['status'];
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<p class="cak-players__title"><?php echo esc_html( $tournament['name'] ); ?></p>

	<?php if ( empty( $players ) ) : ?>
		<div class="chess-army-knife-empty"><?php esc_html_e( 'No players have been entered yet.', 'chess-army-knife' ); ?></div>
	<?php else : ?>
		<table class="cak-players__table">
			<thead>
				<tr>
					<?php if ( $started ) : ?>
						<th class="is-numeric">#</th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Player', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'ECF code', 'chess-army-knife' ); ?></th>
					<th class="is-numeric"><?php esc_html_e( 'Rating', 'chess-army-knife' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $players as $player ) : ?>
					<tr class="<?php echo $player['withdrawn'] ? 'is-withdrawn' : ''; ?>">
						<?php if ( $started ) : ?>
							<td class="is-numeric"><?php echo esc_html( null === $player['seed'] ? '' : $player['seed'] ); ?></td>
						<?php endif; ?>
						<td>
							<?php echo esc_html( $player['name'] ); ?>
							<?php if ( $player['withdrawn'] ) : ?>
								<em>(<?php esc_html_e( 'withdrawn', 'chess-army-knife' ); ?>)</em>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $player['ecf_code'] ); ?></td>
						<td class="is-numeric">
							<?php
							if ( null !== $player['rating'] ) {
								echo esc_html( $player['rating'] . ( 'manual' === $player['source'] ? ' ' . __( '(manual)', 'chess-army-knife' ) : '' ) );
							} elseif ( 'pending' === $player['source'] ) {
								esc_html_e( 'Set at start', 'chess-army-knife' );
							} else {
								esc_html_e( 'Unrated', 'chess-army-knife' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
