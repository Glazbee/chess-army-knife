<?php
/**
 * Server-side render for the Tournament Standings block: a cross-table with
 * each player's points in every round and their total, in rank order.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its styling is added to the wrapper.
$attributes = Chess_Army_Knife_Templates::apply( 'tournament-standings', $attributes );

$tournament_id = isset( $attributes['tournamentId'] ) ? (int) $attributes['tournamentId'] : 0;
$tournament    = $tournament_id ? Chess_Army_Knife_Tournament_Store::get_tournament( $tournament_id ) : null;

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'tournament-standings', $attributes );
$name_style         = Chess_Army_Knife_Names::style_for( $attributes );

if ( ! $tournament ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'Tournament Standings: choose a tournament in the block settings.', 'chess-army-knife' )
	);
	return;
}

// One table per group, or a single table for everyone.
$config = Chess_Army_Knife_Tournaments::config( $tournament );
$tables = array();
if ( Chess_Army_Knife_Tournaments::STATUS_DRAFT !== $tournament['status'] && 'knockout' !== $tournament['format'] ) {
	if ( $config['groups'] > 1 ) {
		for ( $group = 1; $group <= $config['groups']; $group++ ) {
			$tables[ Chess_Army_Knife_Tournaments::group_label( $group ) ] = Chess_Army_Knife_Tournament_Summary::crosstable( $tournament, $group );
		}
	} else {
		$tables[''] = Chess_Army_Knife_Tournament_Summary::crosstable( $tournament, 0 );
	}
}
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-standings__title', $tournament['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php if ( Chess_Army_Knife_Tournaments::STATUS_DRAFT === $tournament['status'] ) : ?>
		<div class="chess-army-knife-empty"><?php esc_html_e( 'This tournament has not started yet.', 'chess-army-knife' ); ?></div>
	<?php elseif ( 'knockout' === $tournament['format'] ) : ?>
		<div class="chess-army-knife-empty"><?php esc_html_e( 'A knockout tournament has no standings table.', 'chess-army-knife' ); ?></div>
	<?php endif; ?>

	<?php foreach ( $tables as $heading => $table ) : ?>
		<?php if ( '' !== $heading ) : ?>
			<?php echo Chess_Army_Knife_A11y::heading( 1, 'cak-standings__group', $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
		<?php endif; ?>
		<?php $caption = '' !== $heading ? sprintf( /* translators: 1: tournament name, 2: group name */ __( '%1$s standings, %2$s', 'chess-army-knife' ), $tournament['name'], $heading ) : sprintf( /* translators: %s: tournament name */ __( '%s standings', 'chess-army-knife' ), $tournament['name'] ); ?>
		<div class="cak-standings__scroll cak-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr( $caption ); ?>">
			<table class="cak-standings__table">
				<caption class="cak-visually-hidden"><?php echo esc_html( $caption ); ?></caption>
				<thead>
					<tr>
						<th scope="col" class="is-numeric"><?php echo Chess_Army_Knife_A11y::abbr( '#', __( 'Rank', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
						<th scope="col"><?php esc_html_e( 'Player', 'chess-army-knife' ); ?></th>
						<?php foreach ( $table['rounds'] as $round ) : ?>
							<th scope="col" class="is-numeric"><?php echo Chess_Army_Knife_A11y::abbr( 'R' . $round, sprintf( /* translators: %d: round number */ __( 'Round %d', 'chess-army-knife' ), $round ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
						<?php endforeach; ?>
						<th scope="col" class="is-numeric"><?php esc_html_e( 'Total', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $table['rows'] as $row ) : ?>
						<tr class="<?php echo $row['withdrawn'] ? 'is-withdrawn' : ''; ?>">
							<td class="is-numeric"><?php echo esc_html( $row['rank'] ); ?></td>
							<th scope="row">
								<?php echo esc_html( Chess_Army_Knife_Names::person( $row, $name_style ) ); ?>
								<?php if ( $row['withdrawn'] ) : ?>
									<em>(<?php esc_html_e( 'withdrawn', 'chess-army-knife' ); ?>)</em>
								<?php endif; ?>
							</th>
							<?php foreach ( $row['scores'] as $score ) : ?>
								<td class="is-numeric">
									<?php
									if ( null === $score ) {
										echo Chess_Army_Knife_A11y::hidden( __( 'No game', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden().
									} else {
										echo esc_html( Chess_Army_Knife_Tournaments_Page::format_points( $score ) );
									}
									?>
								</td>
							<?php endforeach; ?>
							<td class="is-numeric"><strong><?php echo esc_html( Chess_Army_Knife_Tournaments_Page::format_points( $row['total'] ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endforeach; ?>
</div>
