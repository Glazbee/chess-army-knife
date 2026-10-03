<?php
/**
 * Server-side render for the Club Teams block: each team's name, description,
 * home venue and (optionally) leagues. The squad and captain are private and never shown.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$block_title  = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$team_id      = isset( $attributes['teamId'] ) ? absint( $attributes['teamId'] ) : 0;
$teams        = array_filter(
	Chess_Army_Knife_Teams::all(),
	function ( $team ) use ( $team_id ) {
		return ! $team_id || $team['id'] === $team_id;
	}
);
$columns      = isset( $attributes['columns'] ) ? max( 1, min( 4, absint( $attributes['columns'] ) ) ) : 1;
$name_format  = isset( $attributes['nameFormat'] ) ? sanitize_text_field( (string) $attributes['nameFormat'] ) : '';
$group_by     = isset( $attributes['groupBy'] ) && in_array( $attributes['groupBy'], array( 'group', 'division' ), true ) ? $attributes['groupBy'] : '';
$show_players = ! empty( $attributes['showPlayers'] );
$show_leagues = ! isset( $attributes['showLeagues'] ) || $attributes['showLeagues'];
$groups       = '' !== $group_by ? Chess_Army_Knife_Teams::group_teams( $teams, $group_by ) : array(
	array(
		'label' => '',
		'teams' => $teams,
	),
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
	<?php foreach ( $groups as $group ) : ?>
		<?php if ( '' !== $group['label'] ) : ?>
			<?php echo Chess_Army_Knife_A11y::heading( 1, 'cak-team-profiles__group', $group['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
		<?php endif; ?>
		<div class="cak-team-profiles__grid" style="--cak-team-columns:<?php echo esc_attr( $columns ); ?>">
			<?php foreach ( $group['teams'] as $team ) : ?>
				<div class="cak-team">
					<?php echo Chess_Army_Knife_A11y::heading( '' !== $group_by ? 2 : 1, 'cak-team__name', Chess_Army_Knife_Teams::display_name( $team, $name_format ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
					<?php if ( '' !== $team['description'] ) : ?>
						<p class="cak-team__description"><?php echo esc_html( $team['description'] ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $team['venue'] ) : ?>
						<p><strong><?php esc_html_e( 'Home venue:', 'chess-army-knife' ); ?></strong> <?php echo esc_html( $team['venue'] ); ?></p>
					<?php endif; ?>
					<?php $roster = $show_players ? Chess_Army_Knife_Teams::public_roster( $team ) : array(); ?>
					<?php if ( ! empty( $roster['players'] ) ) : ?>
						<p class="cak-team__players-label"><strong><?php esc_html_e( 'Player list', 'chess-army-knife' ); ?></strong></p>
						<ul class="cak-team__players">
							<?php foreach ( $roster['players'] as $player ) : ?>
								<li><?php echo esc_html( $player['name'] ); ?><?php echo $player['captain'] ? ' <strong>' . esc_html__( '(Captain)', 'chess-army-knife' ) . '</strong>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( ! empty( $roster['non_playing_captain'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Non-playing captain', 'chess-army-knife' ); ?></strong> - <?php echo esc_html( $roster['non_playing_captain'] ); ?></p>
					<?php endif; ?>
					<?php $seasons = $show_leagues ? Chess_Army_Knife_Teams::seasons_of( $team ) : array(); ?>
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
	<?php endforeach; ?>
</div>
