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

$player_code   = isset( $attributes['playerCode'] ) ? Chess_Army_Knife_ECF_Client::normalise_code( $attributes['playerCode'] ) : '';
$player_name   = isset( $attributes['playerName'] ) ? $attributes['playerName'] : '';
$rating_domain = Chess_Army_Knife_ECF_Client::normalise_domain( Chess_Army_Knife_Settings::resolve( 'default_domain', $attributes['domain'] ?? '', 'S' ) );
$games_limit   = isset( $attributes['gamesLimit'] ) ? (int) $attributes['gamesLimit'] : 100;
$block_title   = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$height        = isset( $attributes['height'] ) ? max( 120, (int) $attributes['height'] ) : 320;
$show_stats    = ! isset( $attributes['showStats'] ) || (bool) $attributes['showStats'];
$line_color    = isset( $attributes['lineColor'] ) ? (string) $attributes['lineColor'] : '';

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'rating-chart', $attributes );

if ( '' === $player_code ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'ECF Rating Chart: no player selected yet. Edit this block and search for a player.', 'chess-army-knife' )
	);
	return;
}

$games = Chess_Army_Knife_ECF_Client::get_games( $player_code, $rating_domain, $games_limit );

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

$heading = $block_title ? $block_title : sprintf(
	/* translators: 1: player name, 2: rating list e.g. Standard */
	__( '%1$s — %2$s rating', 'chess-army-knife' ),
	$player_name ? $player_name : $player_code,
	isset( $domain_labels[ $rating_domain ] ) ? $domain_labels[ $rating_domain ] : $rating_domain
);

// The chart is decorative: the summary below it and the table carry the same information.
// The chosen line colour, or else the accent from the theme (or template), or else the text colour.
$line_colour = sanitize_hex_color( $line_color );
if ( ! $line_colour ) {
	$line_colour = 'var(--cak-accent, currentColor)';
}

$date_format = get_option( 'date_format' );
$format_date = function ( $date ) use ( $date_format ) {
	return mysql2date( $date_format, $date );
};

$first_point = reset( $points );
$last_point  = end( $points );
$peak_point  = $points[ array_search( $peak, $values, true ) ];
$low_point   = $points[ array_search( $low, $values, true ) ];

$list_label = isset( $domain_labels[ $rating_domain ] ) ? $domain_labels[ $rating_domain ] : __( 'Rating', 'chess-army-knife' );
if ( 1 === count( $points ) ) {
	$summary = sprintf(
		/* translators: 1: rating list e.g. Standard, 2: date, 3: rating */
		__( '%1$s rating: one rating, on %2$s, of %3$d.', 'chess-army-knife' ),
		$list_label,
		$format_date( $first_point['date'] ),
		(int) $first
	);
} else {
	$summary = sprintf(
		/* translators: 1: rating list e.g. Standard, 2: number of ratings, 3: first date, 4: last date, 5: first rating, 6: last rating, 7: highest rating, 8: its date, 9: lowest rating, 10: its date */
		__( '%1$s rating: %2$d ratings from %3$s to %4$s. It went from %5$d to %6$d. The highest was %7$d on %8$s and the lowest was %9$d on %10$s.', 'chess-army-knife' ),
		$list_label,
		count( $points ),
		$format_date( $first_point['date'] ),
		$format_date( $last_point['date'] ),
		(int) $first,
		(int) $current,
		(int) $peak,
		$format_date( $peak_point['date'] ),
		(int) $low,
		$format_date( $low_point['date'] )
	);
}

$geometry        = Chess_Army_Knife_Rating_Chart::geometry( $values );
$admin_cache_key = Chess_Army_Knife_ECF_Client::cache_key_games( $player_code, $rating_domain, $games_limit );
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-rating-chart__title', $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php echo Chess_Army_Knife_Admin_Refresh::bar( array( $admin_cache_key ), __( 'Games data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Admin_Refresh::bar(). ?>

	<figure class="cak-rating-chart__figure">
		<svg
			class="cak-rating-chart__svg"
			style="height: <?php echo (int) $height; ?>px; color: <?php echo esc_attr( $line_colour ); ?>;"
			viewBox="0 0 <?php echo (int) Chess_Army_Knife_Rating_Chart::WIDTH; ?> <?php echo (int) Chess_Army_Knife_Rating_Chart::HEIGHT; ?>"
			preserveAspectRatio="none"
			aria-hidden="true"
			focusable="false"
		>
			<?php foreach ( $geometry['grid'] as $grid_y ) : ?>
				<line class="cak-rating-chart__grid" x1="0" x2="<?php echo (int) Chess_Army_Knife_Rating_Chart::WIDTH; ?>" y1="<?php echo (int) $grid_y; ?>" y2="<?php echo (int) $grid_y; ?>" vector-effect="non-scaling-stroke" />
			<?php endforeach; ?>
			<path class="cak-rating-chart__area" d="<?php echo esc_attr( $geometry['area'] ); ?>" />
			<path class="cak-rating-chart__line" d="<?php echo esc_attr( $geometry['line'] ); ?>" vector-effect="non-scaling-stroke" />
			<?php if ( '' !== $geometry['dots'] ) : ?>
				<path class="cak-rating-chart__dots" d="<?php echo esc_attr( $geometry['dots'] ); ?>" vector-effect="non-scaling-stroke" />
			<?php endif; ?>
		</svg>
		<figcaption class="cak-rating-chart__caption"><?php echo esc_html( $summary ); ?></figcaption>
	</figure>

	<details class="cak-rating-chart__data">
		<summary><?php esc_html_e( 'Show the ratings as a table', 'chess-army-knife' ); ?></summary>
		<div class="cak-rating-chart__scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Ratings table', 'chess-army-knife' ); ?>">
			<table class="cak-rating-chart__table">
				<caption><?php echo esc_html( $heading ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'chess-army-knife' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Rating', 'chess-army-knife' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Event', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_reverse( $points ) as $point ) : ?>
						<tr>
							<th scope="row"><time datetime="<?php echo esc_attr( $point['date'] ); ?>"><?php echo esc_html( $format_date( $point['date'] ) ); ?></time></th>
							<td><?php echo esc_html( (int) $point['rating'] ); ?></td>
							<td><?php echo esc_html( $point['event'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</details>

	<?php if ( $show_stats ) : ?>
		<dl class="cak-rating-chart__stats">
			<div>
				<dt><?php esc_html_e( 'Current', 'chess-army-knife' ); ?></dt>
				<dd><?php echo esc_html( (int) $current ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Peak', 'chess-army-knife' ); ?></dt>
				<dd><?php echo esc_html( (int) $peak ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Lowest', 'chess-army-knife' ); ?></dt>
				<dd><?php echo esc_html( (int) $low ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Change over period', 'chess-army-knife' ); ?></dt>
				<dd class="<?php echo esc_attr( $change >= 0 ? 'is-up' : 'is-down' ); ?>">
					<?php echo esc_html( ( $change >= 0 ? '+' : '' ) . (int) $change ); ?>
				</dd>
			</div>
		</dl>
	<?php endif; ?>
</div>
