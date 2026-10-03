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

$players     = Chess_Army_Knife_Tournament_Summary::players( $tournament );
$list_labels = array(
	'S' => '',
	'R' => __( 'Rapid', 'chess-army-knife' ),
	'B' => __( 'Blitz', 'chess-army-knife' ),
);
$rating_list = Chess_Army_Knife_ECF_Client::normalise_domain( $tournament['rating_domain'] );
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
					<th scope="col"><?php esc_html_e( 'Player', 'chess-army-knife' ); ?></th>
					<th scope="col"><?php echo Chess_Army_Knife_A11y::abbr( __( 'ECF code', 'chess-army-knife' ), __( 'English Chess Federation rating code', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
					<th scope="col" class="is-numeric">
						<?php
						// A rapid or blitz tournament shows that list's rating, and says so.
						echo esc_html( ! empty( $list_labels[ $rating_list ] ) ? sprintf( /* translators: %s: rating list, such as Rapid */ __( 'ECF Rating (%s)', 'chess-army-knife' ), $list_labels[ $rating_list ] ) : __( 'ECF Rating', 'chess-army-knife' ) );
						?>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $players as $player ) : ?>
					<tr class="<?php echo $player['withdrawn'] ? 'is-withdrawn' : ''; ?>">
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
								echo esc_html( $player['rating'] );
							} elseif ( '' !== $player['ecf_code'] ) {
								esc_html_e( 'Unrated', 'chess-army-knife' );
							} else {
								echo '&mdash;<span class="cak-visually-hidden">' . esc_html__( 'No ECF code', 'chess-army-knife' ) . '</span>';
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
