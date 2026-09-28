<?php
/**
 * Server-side render for the Tournament Status block.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its styling is added to the wrapper.
$attributes = Chess_Army_Knife_Templates::apply( 'tournament-status', $attributes );

$tournament_id = isset( $attributes['tournamentId'] ) ? (int) $attributes['tournamentId'] : 0;
$tournament    = $tournament_id ? Chess_Army_Knife_Tournament_Store::get_tournament( $tournament_id ) : null;

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'tournament-status', $attributes );

if ( ! $tournament ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'Tournament Status: choose a tournament in the block settings.', 'chess-army-knife' )
	);
	return;
}

$summary = Chess_Army_Knife_Tournament_Summary::status( $tournament );
$started = Chess_Army_Knife_Tournaments::STATUS_DRAFT !== $summary['status'];
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<p class="cak-status__title"><?php echo esc_html( $tournament['name'] ); ?></p>
	<dl class="cak-status__list">
		<dt><?php esc_html_e( 'Status', 'chess-army-knife' ); ?></dt>
		<dd><?php echo esc_html( $summary['status_label'] ); ?></dd>

		<dt><?php esc_html_e( 'Format', 'chess-army-knife' ); ?></dt>
		<dd><?php echo esc_html( $summary['format_label'] ); ?></dd>

		<dt><?php esc_html_e( 'Players', 'chess-army-knife' ); ?></dt>
		<dd><?php echo esc_html( $summary['players'] ); ?></dd>

		<?php if ( $started && $summary['round'] > 0 && 'swiss' === $tournament['format'] ) : ?>
			<dt><?php esc_html_e( 'Round', 'chess-army-knife' ); ?></dt>
			<dd>
				<?php
				/* translators: 1: current round, 2: total rounds */
				echo esc_html( sprintf( __( '%1$d of %2$d', 'chess-army-knife' ), $summary['round'], $summary['rounds'] ) );
				?>
			</dd>
		<?php endif; ?>

		<?php if ( $started ) : ?>
			<dt><?php esc_html_e( 'Games played', 'chess-army-knife' ); ?></dt>
			<dd>
				<?php
				/* translators: 1: games played, 2: games scheduled so far */
				echo esc_html( sprintf( __( '%1$d of %2$d', 'chess-army-knife' ), $summary['games_played'], $summary['games_total'] ) );
				?>
			</dd>
		<?php endif; ?>

		<?php if ( null !== $summary['champion'] ) : ?>
			<dt><?php esc_html_e( 'Winner', 'chess-army-knife' ); ?></dt>
			<dd><?php echo esc_html( $summary['champion'] ); ?></dd>
		<?php endif; ?>
	</dl>
</div>
