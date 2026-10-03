<?php
/**
 * Server-side render for the ECF Club Results block.
 *
 * Takes the club's own current members who have an ECF rating code (never
 * anyone the club holds no record of), pulls the most recent rated games for
 * a bounded number of them and merges them into one recent results feed.
 * Opponents are not shown: they are people the club has no record of.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its settings override this block's own.
$attributes = Chess_Army_Knife_Templates::apply( 'club-results', $attributes );

$rating_domain    = Chess_Army_Knife_ECF_Client::normalise_domain( Chess_Army_Knife_Settings::resolve( 'default_domain', $attributes['domain'] ?? '', 'S' ) );
$max_players      = max( 1, (int) Chess_Army_Knife_Settings::resolve( 'default_max_players', empty( $attributes['maxPlayers'] ) ? '' : $attributes['maxPlayers'], 12 ) );
$games_per_player = isset( $attributes['gamesPerPlayer'] ) ? max( 1, (int) $attributes['gamesPerPlayer'] ) : 5;
$days_back        = max( 1, (int) Chess_Army_Knife_Settings::resolve( 'default_days_back', empty( $attributes['daysBack'] ) ? '' : $attributes['daysBack'], 60 ) );
$max_results      = isset( $attributes['maxResults'] ) ? max( 1, (int) $attributes['maxResults'] ) : 20;
$show_event       = ! isset( $attributes['showEvent'] ) || (bool) $attributes['showEvent'];
$block_title      = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'club-results', $attributes );
$name_style         = Chess_Army_Knife_Names::style_for( $attributes );

// The club's own current members with an ECF code: nobody is looked up on the ECF without a record here.
$players = array();
foreach ( Chess_Army_Knife_Membership_Store::get_players( '', true, $max_players, true ) as $member ) {
	$players[] = array(
		'code' => $member['ecf_code'],
		'name' => $member['name'],
	);
}

if ( empty( $players ) ) {
	printf(
		'<div %1$s><div class="chess-army-knife-empty">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'No current members with an ECF rating code were found.', 'chess-army-knife' )
	);
	return;
}

$cutoff = gmdate( 'Y-m-d', strtotime( '-' . $days_back . ' days' ) );

// score field convention from the ECF games API: "1" win, "5" draw
// (half point), "0" loss, "-"/"+" unplayed or administrative rows.
$result_labels = array(
	'1' => __( 'Win', 'chess-army-knife' ),
	'5' => __( 'Draw', 'chess-army-knife' ),
	'0' => __( 'Loss', 'chess-army-knife' ),
);

$results          = array();
$admin_cache_keys = array();

foreach ( $players as $player ) {
	$admin_cache_keys[] = Chess_Army_Knife_ECF_Client::cache_key_games( $player['code'], $rating_domain, $games_per_player );
	$games              = Chess_Army_Knife_ECF_Client::get_games( $player['code'], $rating_domain, $games_per_player );

	if ( is_wp_error( $games ) || empty( $games ) ) {
		continue;
	}

	foreach ( $games as $game ) {
		$score = isset( $game['score'] ) ? (string) $game['score'] : '';

		if ( ! isset( $result_labels[ $score ] ) ) {
			continue; // Skip byes, walkovers and anything we can't confidently label.
		}
		if ( empty( $game['game_date'] ) || $game['game_date'] < $cutoff ) {
			continue;
		}
		$results[] = array(
			'date'         => $game['game_date'],
			'player_name'  => $player['name'],
			'result_label' => $result_labels[ $score ],
			'result_class' => array(
				'1' => 'cak-result-win',
				'5' => 'cak-result-draw',
				'0' => 'cak-result-loss',
			)[ $score ],
			'colour'       => isset( $game['colour'] ) ? strtoupper( $game['colour'] ) : '',
			'event_name'   => isset( $game['event_name'] ) ? $game['event_name'] : '',
		);
	}
}

usort(
	$results,
	function ( $a, $b ) {
		return strcmp( $b['date'], $a['date'] );
	}
);

$results = array_slice( $results, 0, $max_results );

$heading = $block_title ? $block_title : sprintf( /* translators: %s: the club's name */ __( 'Recent results for %s', 'chess-army-knife' ), Chess_Army_Knife_Settings::club_name() );
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-club-results__title', $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php echo Chess_Army_Knife_Admin_Refresh::bar( $admin_cache_keys, __( 'Club results data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Admin_Refresh::bar(). ?>

	<?php if ( empty( $results ) ) : ?>
		<div class="chess-army-knife-empty">
			<?php esc_html_e( 'No recent rated results were found in the selected time window.', 'chess-army-knife' ); ?>
		</div>
	<?php else : ?>
		<div class="cak-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr( $heading ); ?>">
		<table class="cak-club-results__table">
			<caption class="cak-visually-hidden"><?php echo esc_html( $heading ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'chess-army-knife' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Player', 'chess-army-knife' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'chess-army-knife' ); ?></th>
					<?php
					if ( $show_event ) :
						?>
						<th scope="col"><?php esc_html_e( 'Event', 'chess-army-knife' ); ?></th><?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results as $row ) : ?>
					<tr>
						<td><?php echo Chess_Army_Knife_A11y::date( $row['date'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in date(). ?></td>
						<th scope="row"><?php echo esc_html( Chess_Army_Knife_Names::format( $row['player_name'], $name_style ) ); ?></th>
						<td><span class="<?php echo esc_attr( $row['result_class'] ); ?>"><?php echo esc_html( $row['result_label'] ); ?></span></td>
						<?php
						if ( $show_event ) :
							?>
							<td><?php echo esc_html( $row['event_name'] ); ?></td><?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
</div>
