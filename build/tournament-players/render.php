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
$name_style         = Chess_Army_Knife_Names::style_for( $attributes );

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
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-players__title', $tournament['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php if ( empty( $players ) ) : ?>
		<div class="chess-army-knife-empty"><?php esc_html_e( 'No players have been entered yet.', 'chess-army-knife' ); ?></div>
	<?php else : ?>
		<div class="cak-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr( $tournament['name'] ); ?>">
		<table class="cak-players__table">
			<caption class="cak-visually-hidden"><?php echo esc_html( sprintf( /* translators: %s: tournament name */ __( 'Players in %s', 'chess-army-knife' ), $tournament['name'] ) ); ?></caption>
			<thead>
				<tr>
					<?php if ( $started ) : ?>
						<th scope="col" class="is-numeric"><?php echo Chess_Army_Knife_A11y::abbr( '#', __( 'Seed', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'Player', 'chess-army-knife' ); ?></th>
					<th scope="col"><?php echo Chess_Army_Knife_A11y::abbr( __( 'ECF code', 'chess-army-knife' ), __( 'English Chess Federation rating code', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
					<th scope="col" class="is-numeric"><?php esc_html_e( 'Rating', 'chess-army-knife' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $players as $player ) : ?>
					<tr class="<?php echo $player['withdrawn'] ? 'is-withdrawn' : ''; ?>">
						<?php if ( $started ) : ?>
							<td class="is-numeric"><?php echo esc_html( null === $player['seed'] ? '' : $player['seed'] ); ?></td>
						<?php endif; ?>
						<th scope="row">
							<?php echo esc_html( Chess_Army_Knife_Names::person( $player, $name_style ) ); ?>
							<?php if ( $player['withdrawn'] ) : ?>
								<em>(<?php esc_html_e( 'withdrawn', 'chess-army-knife' ); ?>)</em>
							<?php endif; ?>
						</th>
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
		</div>
	<?php endif; ?>
</div>
