<?php
/**
 * Server-side render for the ECF Rating Chart block.
 *
 * @package Chess_Army_Knife
 *
 * @var array  $attributes Block attributes.
 * @var string $content    Block inner content (unused, dynamic block).
 * @var WP_Block $block    Block instance.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its settings override this block's own.
$attributes = Chess_Army_Knife_Templates::apply( 'rating-chart', $attributes );

$player_code = isset( $attributes['playerCode'] ) ? ECF_Client::normalise_code( $attributes['playerCode'] ) : '';
$player_name = isset( $attributes['playerName'] ) ? $attributes['playerName'] : '';
$domain      = ECF_Client::normalise_domain( Chess_Army_Knife_Settings::resolve( 'default_domain', $attributes['domain'] ?? '', 'S' ) );
$games_limit = isset( $attributes['gamesLimit'] ) ? (int) $attributes['gamesLimit'] : 100;
$title       = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$height      = isset( $attributes['height'] ) ? max( 120, (int) $attributes['height'] ) : 320;
$show_stats  = ! isset( $attributes['showStats'] ) || (bool) $attributes['showStats'];
$line_color  = ! empty( $attributes['lineColor'] ) ? $attributes['lineColor'] : '#1e3a5f';

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'rating-chart', $attributes );

if ( '' === $player_code ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'ECF Rating Chart: no player selected yet. Edit this block and search for a player.', 'chess-army-knife' )
	);
	return;
}

$games = ECF_Client::get_games( $player_code, $domain, $games_limit );

if ( is_wp_error( $games ) ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s %3$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'Could not load rating data from the ECF API:', 'chess-army-knife' ),
		esc_html( $games->get_error_message() )
	);
	return;
}

// Only games with a recorded player_rating snapshot are usable data points.
$points = array();
foreach ( (array) $games as $game ) {
	if ( empty( $game['game_date'] ) || '' === $game['player_rating'] || null === $game['player_rating'] ) {
		continue;
	}
	$points[] = array(
		'date'   => $game['game_date'],
		'rating' => (float) $game['player_rating'],
		'event'  => isset( $game['event_name'] ) ? $game['event_name'] : '',
	);
}

// The API returns games newest-first; sort ascending for a left-to-right timeline
// and collapse to the last rating recorded on each date.
usort(
	$points,
	function ( $a, $b ) {
		return strcmp( $a['date'], $b['date'] );
	}
);

$by_date = array();
foreach ( $points as $point ) {
	$by_date[ $point['date'] ] = $point; // Last one wins (chronologically last game that day).
}
$points = array_values( $by_date );

if ( empty( $points ) ) {
	printf(
		'<div %1$s><div class="chess-army-knife-empty">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'No rated games with a recorded rating were found for this player and list.', 'chess-army-knife' )
	);
	return;
}

$labels = wp_list_pluck( $points, 'date' );
$values = wp_list_pluck( $points, 'rating' );

$current = end( $values );
$first   = reset( $values );
$peak    = max( $values );
$low     = min( $values );
$change  = $current - $first;

$domain_labels = array(
	'S'  => __( 'Standard', 'chess-army-knife' ),
	'R'  => __( 'Rapid', 'chess-army-knife' ),
	'B'  => __( 'Blitz', 'chess-army-knife' ),
	'SW' => __( 'Online Standard', 'chess-army-knife' ),
	'RW' => __( 'Online Rapid', 'chess-army-knife' ),
	'BW' => __( 'Online Blitz', 'chess-army-knife' ),
);

$heading = $title ? $title : sprintf(
	/* translators: 1: player name, 2: rating list e.g. Standard */
	__( '%1$s — %2$s rating', 'chess-army-knife' ),
	$player_name ? $player_name : $player_code,
	isset( $domain_labels[ $domain ] ) ? $domain_labels[ $domain ] : $domain
);

$chart_payload = array(
	'labels'       => $labels,
	'values'       => $values,
	'color'        => $line_color,
	'unratedLabel' => isset( $domain_labels[ $domain ] ) ? $domain_labels[ $domain ] : __( 'Rating', 'chess-army-knife' ),
);

$canvas_id = 'ecf-rating-chart-' . wp_unique_id();
$admin_cache_key = ECF_Client::cache_key_games( $player_code, $domain, $games_limit );
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<p class="ecf-rating-chart__title"><?php echo esc_html( $heading ); ?></p>

	<?php echo Chess_Army_Knife_Admin_Refresh::bar( array( $admin_cache_key ), __( 'Games data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	<div class="ecf-rating-chart__canvas-wrap" data-ecf-rating-chart style="height: <?php echo (int) $height; ?>px;">
		<canvas id="<?php echo esc_attr( $canvas_id ); ?>"></canvas>
		<script type="application/json"><?php echo wp_json_encode( $chart_payload ); ?></script>
	</div>

	<?php if ( $show_stats ) : ?>
		<div class="ecf-rating-chart__stats">
			<div>
				<?php esc_html_e( 'Current', 'chess-army-knife' ); ?>
				<strong><?php echo esc_html( (int) $current ); ?></strong>
			</div>
			<div>
				<?php esc_html_e( 'Peak', 'chess-army-knife' ); ?>
				<strong><?php echo esc_html( (int) $peak ); ?></strong>
			</div>
			<div>
				<?php esc_html_e( 'Lowest', 'chess-army-knife' ); ?>
				<strong><?php echo esc_html( (int) $low ); ?></strong>
			</div>
			<div>
				<?php esc_html_e( 'Change over period', 'chess-army-knife' ); ?>
				<strong class="<?php echo esc_attr( $change >= 0 ? 'is-up' : 'is-down' ); ?>">
					<?php echo esc_html( ( $change >= 0 ? '+' : '' ) . (int) $change ); ?>
				</strong>
			</div>
		</div>
	<?php endif; ?>
</div>
