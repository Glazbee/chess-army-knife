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
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<?php if ( 0 === $team_id ) : ?>
		<h2 class="cak-team-profiles__heading"><?php echo esc_html( '' !== $block_title ? $block_title : __( 'Our teams', 'chess-army-knife' ) ); ?></h2>
	<?php endif; ?>
	<?php if ( ! $teams ) : ?>
		<p><?php esc_html_e( 'No teams to show yet.', 'chess-army-knife' ); ?></p>
	<?php endif; ?>
	<?php foreach ( $teams as $team ) : ?>
		<div class="cak-team">
			<h3><?php echo esc_html( $team['name'] ); ?></h3>
			<?php if ( '' !== $team['description'] ) : ?>
				<p><?php echo esc_html( $team['description'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $team['venue'] ) : ?>
				<p><strong><?php esc_html_e( 'Home venue:', 'chess-army-knife' ); ?></strong> <?php echo esc_html( $team['venue'] ); ?></p>
			<?php endif; ?>
			<?php $seasons = Chess_Army_Knife_Teams::seasons_of( $team ); ?>
			<?php if ( $seasons ) : ?>
				<ul>
					<?php foreach ( $seasons as $season ) : ?>
						<li><?php echo esc_html( $season['event'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
