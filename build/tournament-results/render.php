<?php
/**
 * Server-side render for the Tournament Results Entry block.
 *
 * Admin-only: visitors who cannot manage tournaments see nothing. Each
 * result is saved through the REST API by view.js.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

if ( ! Chess_Army_Knife_Tournaments::user_can_manage() ) {
	return;
}

$tournament_id  = isset( $attributes['tournamentId'] ) ? (int) $attributes['tournamentId'] : 0;
$show_completed = ! isset( $attributes['showCompleted'] ) || (bool) $attributes['showCompleted'];
$tournament     = $tournament_id ? Chess_Army_Knife_Tournament_Store::get_tournament( $tournament_id ) : null;

$wrapper_attributes = get_block_wrapper_attributes();

if ( ! $tournament ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'Tournament Results Entry: choose a tournament in the block settings.', 'chess-army-knife' )
	);
	return;
}

if ( Chess_Army_Knife_Tournaments::STATUS_DRAFT === $tournament['status'] ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'This tournament has not started yet.', 'chess-army-knife' )
	);
	return;
}

$names = array();
foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id ) as $entry ) {
	$names[ $entry['id'] ] = $entry['name'];
}

// Sections keyed by a label: group rounds and knockout rounds, each a list of games.
$sections     = array();
$all_games    = Chess_Army_Knife_Tournament_Store::get_games( $tournament_id );
$knockout_max = 0;
foreach ( $all_games as $game ) {
	if ( 'knockout' === $game['stage'] ) {
		$knockout_max = max( $knockout_max, $game['round'] );
	}
}

foreach ( $all_games as $game ) {
	// Skip byes, games still waiting for their players, and (optionally) finished games.
	if ( $game['is_bye'] || null === $game['white_entry_id'] || null === $game['black_entry_id'] ) {
		continue;
	}
	if ( ! $show_completed && null !== $game['result'] ) {
		continue;
	}

	if ( 'knockout' === $game['stage'] ) {
		$label = Chess_Army_Knife_Bracket::round_name( $game['round'], $knockout_max );
	} else {
		$label = ( $game['group_no'] ? Chess_Army_Knife_Tournaments::group_label( $game['group_no'] ) . ' — ' : '' )
			/* translators: %d: round number */
			. sprintf( __( 'Round %d', 'chess-army-knife' ), $game['round'] );
	}
	$sections[ $label ][] = $game;
}

$group_options    = Chess_Army_Knife_Tournaments_Page::result_options( false );
$knockout_options = Chess_Army_Knife_Tournaments_Page::result_options( true );
?>
<div
	<?php echo wp_kses_post( $wrapper_attributes ); ?>
	data-rest-url="<?php echo esc_url( rest_url( Chess_Army_Knife_Tournament_REST::NAMESPACE_V1 . '/games/' ) ); ?>"
	data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
>
	<div class="cak-results__title"><?php echo esc_html( $tournament['name'] ); ?></div>

	<?php if ( empty( $sections ) ) : ?>
		<p><?php esc_html_e( 'No games to show.', 'chess-army-knife' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $sections as $label => $games ) : ?>
		<div class="cak-results__round"><?php echo esc_html( $label ); ?></div>
		<?php foreach ( $games as $game ) : ?>
			<?php $options = 'knockout' === $game['stage'] ? $knockout_options : $group_options; ?>
			<div class="cak-results__game">
				<span><?php echo esc_html( isset( $names[ $game['white_entry_id'] ] ) ? $names[ $game['white_entry_id'] ] : '—' ); ?></span>
				<span class="cak-results__vs"><?php esc_html_e( 'vs', 'chess-army-knife' ); ?></span>
				<span><?php echo esc_html( isset( $names[ $game['black_entry_id'] ] ) ? $names[ $game['black_entry_id'] ] : '—' ); ?></span>
				<span>
					<select class="cak-results__select" data-game-id="<?php echo esc_attr( $game['id'] ); ?>">
						<?php foreach ( $options as $value => $option_label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $game['result'], $value ); ?>><?php echo esc_html( $option_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="cak-results__status" role="status" aria-live="polite"></span>
				</span>
			</div>
		<?php endforeach; ?>
	<?php endforeach; ?>
</div>
