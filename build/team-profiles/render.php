<?php
/**
 * Server-side render for the Club Teams block: each team's name, description,
 * home venue and leagues. The squad and captain are private and never shown.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$block_title = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$team_id     = isset( $attributes['teamId'] ) ? absint( $attributes['teamId'] ) : 0;
$teams       = array_filter(
	Chess_Army_Knife_Teams::all(),
	function ( $team ) use ( $team_id ) {
		return ! $team_id || $team['id'] === $team_id;
	}
);
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( Chess_Army_Knife_Templates::wrapper_attributes( 'team-profiles', $attributes ) ); ?>>
	<?php if ( 0 === $team_id ) : ?>
		<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-team-profiles__heading', '' !== $block_title ? $block_title : sprintf( /* translators: %s: the club's name */ __( '%s teams', 'chess-army-knife' ), Chess_Army_Knife_Settings::club_name() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
	<?php endif; ?>
	<?php if ( ! $teams ) : ?>
		<p><?php esc_html_e( 'No teams to show yet.', 'chess-army-knife' ); ?></p>
	<?php endif; ?>
	<?php foreach ( $teams as $team ) : ?>
		<div class="cak-team">
			<?php echo Chess_Army_Knife_A11y::heading( 1, 'cak-team__name', $team['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
			<?php if ( '' !== $team['description'] ) : ?>
				<p><?php echo esc_html( $team['description'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $team['venue'] ) : ?>
				<p><strong><?php esc_html_e( 'Home venue:', 'chess-army-knife' ); ?></strong> <?php echo esc_html( $team['venue'] ); ?></p>
			<?php endif; ?>
			<?php $seasons = Chess_Army_Knife_Teams::seasons_of( $team ); ?>
			<?php if ( $seasons ) : ?>
				<p class="cak-team__leagues-label"><?php esc_html_e( 'Leagues:', 'chess-army-knife' ); ?></p>
				<ul>
					<?php foreach ( $seasons as $season ) : ?>
						<li><?php echo esc_html( $season['event'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
