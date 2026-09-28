<?php
/**
 * Server-side render for the Tournament Games to Play block.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its styling is added to the wrapper.
$attributes = Chess_Army_Knife_Templates::apply( 'tournament-games', $attributes );

$tournament_id = isset( $attributes['tournamentId'] ) ? (int) $attributes['tournamentId'] : 0;
$tournament    = $tournament_id ? Chess_Army_Knife_Tournament_Store::get_tournament( $tournament_id ) : null;

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'tournament-games', $attributes );

if ( ! $tournament ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'Tournament Games to Play: choose a tournament in the block settings.', 'chess-army-knife' )
	);
	return;
}

$sections = Chess_Army_Knife_Tournament_Summary::games_to_play( $tournament_id );
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<p class="cak-games__title"><?php echo esc_html( $tournament['name'] ); ?></p>

	<?php if ( Chess_Army_Knife_Tournaments::STATUS_DRAFT === $tournament['status'] ) : ?>
		<div class="chess-army-knife-empty"><?php esc_html_e( 'This tournament has not started yet.', 'chess-army-knife' ); ?></div>
	<?php elseif ( empty( $sections ) ) : ?>
		<div class="chess-army-knife-empty"><?php esc_html_e( 'There are no games waiting to be played.', 'chess-army-knife' ); ?></div>
	<?php endif; ?>

	<?php foreach ( $sections as $label => $games ) : ?>
		<p class="cak-games__round"><?php echo esc_html( $label ); ?></p>
		<ul class="cak-games__list">
			<?php foreach ( $games as $game ) : ?>
				<li>
					<span><?php echo esc_html( $game['white'] ); ?></span>
					<span class="cak-games__vs"><?php esc_html_e( 'vs', 'chess-army-knife' ); ?></span>
					<span><?php echo esc_html( $game['black'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endforeach; ?>
</div>
