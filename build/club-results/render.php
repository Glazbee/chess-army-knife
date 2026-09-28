<?php
/**
 * Server-side render for the ECF Club Results block.
 *
 * Pulls the club roster, then the most recent rated games for a bounded
 * number of that roster's players, and merges them into one recent
 * results feed for the club.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its settings override this block's own.
$attributes = Chess_Army_Knife_Templates::apply( 'club-results', $attributes );

$club_code            = strtoupper( trim( Chess_Army_Knife_Settings::resolve( 'default_club_code', $attributes['clubCode'] ?? '' ) ) );
$club_name            = isset( $attributes['clubName'] ) ? $attributes['clubName'] : '';
$domain               = ECF_Client::normalise_domain( Chess_Army_Knife_Settings::resolve( 'default_domain', $attributes['domain'] ?? '', 'S' ) );
$max_players          = max( 1, (int) Chess_Army_Knife_Settings::resolve( 'default_max_players', empty( $attributes['maxPlayers'] ) ? '' : $attributes['maxPlayers'], 12 ) );
$games_per_player     = isset( $attributes['gamesPerPlayer'] ) ? max( 1, (int) $attributes['gamesPerPlayer'] ) : 5;
$days_back            = max( 1, (int) Chess_Army_Knife_Settings::resolve( 'default_days_back', empty( $attributes['daysBack'] ) ? '' : $attributes['daysBack'], 60 ) );
$max_results          = isset( $attributes['maxResults'] ) ? max( 1, (int) $attributes['maxResults'] ) : 20;
$show_event           = ! isset( $attributes['showEvent'] ) || (bool) $attributes['showEvent'];
$title                = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$show_opponent_rating = ! isset( $attributes['showOpponentRating'] ) || (bool) $attributes['showOpponentRating'];

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'club-results', $attributes );

if ( '' === $club_code ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'ECF Club Results: no club selected yet. Edit this block and search for a club.', 'chess-army-knife' )
	);
	return;
}

$roster = ECF_Client::get_club_players( $club_code );

if ( is_wp_error( $roster ) ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s %3$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'Could not load the club roster from the ECF API:', 'chess-army-knife' ),
		esc_html( $roster->get_error_message() )
	);
	return;
}

$columns = isset( $roster['columns'] ) ? $roster['columns'] : array();
$rows    = isset( $roster['players'] ) ? $roster['players'] : array();

if ( empty( $rows ) || empty( $columns ) ) {
	printf(
		'<div %1$s><div class="chess-army-knife-empty">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'No players were found for this club code.', 'chess-army-knife' )
	);
	return;
}

$idx = array_flip( $columns );

$rating_column = array(
	'S' => 'std',
	'R' => 'rpd',
	'B' => 'btz',
)[ $domain ] ?? 'std';

$code_idx   = $idx['ECF_code'] ?? null;
$name_idx   = $idx['full_name'] ?? null;
$rating_idx = $idx[ $rating_column ] ?? null;

// Build a normalised, rating-sorted roster and take the top N players
// (most active/highest-rated members are the most useful sample and
// keeps the number of downstream API calls bounded and predictable).
$players = array();
foreach ( $rows as $row ) {
	if ( null === $code_idx || null === $name_idx || ! isset( $row[ $code_idx ] ) ) {
		continue;
	}
	$players[] = array(
		'code'   => (string) $row[ $code_idx ],
		'name'   => (string) $row[ $name_idx ],
		'rating' => ( null !== $rating_idx && isset( $row[ $rating_idx ] ) ) ? (int) $row[ $rating_idx ] : 0,
	);
}

usort(
	$players,
	function ( $a, $b ) {
		return $b['rating'] <=> $a['rating'];
	}
);

$players = array_slice( $players, 0, $max_players );

if ( empty( $players ) ) {
	printf(
		'<div %1$s><div class="chess-army-knife-empty">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'No players with a usable ECF code were found for this club.', 'chess-army-knife' )
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
$admin_cache_keys = array( ECF_Client::cache_key_club_players( $club_code ) );

foreach ( $players as $player ) {
	$admin_cache_keys[] = ECF_Client::cache_key_games( $player['code'], $domain, $games_per_player );
	$games              = ECF_Client::get_games( $player['code'], $domain, $games_per_player );

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
		if ( empty( $game['opponent_name'] ) ) {
			continue;
		}

		$results[] = array(
			'date'            => $game['game_date'],
			'player_name'     => $player['name'],
			'opponent_name'   => $game['opponent_name'],
			'opponent_rating' => isset( $game['opponent_rating'] ) ? $game['opponent_rating'] : '',
			'result_label'    => $result_labels[ $score ],
			'result_class'    => array(
				'1' => 'ecf-result-win',
				'5' => 'ecf-result-draw',
				'0' => 'ecf-result-loss',
			)[ $score ],
			'colour'          => isset( $game['colour'] ) ? strtoupper( $game['colour'] ) : '',
			'event_name'      => isset( $game['event_name'] ) ? $game['event_name'] : '',
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

$heading = $title ? $title : sprintf(
	/* translators: %s: club name */
	__( 'Recent results — %s', 'chess-army-knife' ),
	$club_name ? $club_name : $club_code
);
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<p class="ecf-club-results__title"><?php echo esc_html( $heading ); ?></p>

	<?php echo Chess_Army_Knife_Admin_Refresh::bar( $admin_cache_keys, __( 'Club results data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	<?php if ( empty( $results ) ) : ?>
		<div class="chess-army-knife-empty">
			<?php esc_html_e( 'No recent rated results were found for this club in the selected time window.', 'chess-army-knife' ); ?>
		</div>
	<?php else : ?>
		<table class="ecf-club-results__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Player', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Result', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Opponent', 'chess-army-knife' ); ?></th>
					<?php
					if ( $show_event ) :
						?>
						<th><?php esc_html_e( 'Event', 'chess-army-knife' ); ?></th><?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results as $row ) : ?>
					<tr>
						<td><?php echo esc_html( gmdate( 'd M Y', strtotime( $row['date'] ) ) ); ?></td>
						<td><?php echo esc_html( $row['player_name'] ); ?></td>
						<td><span class="<?php echo esc_attr( $row['result_class'] ); ?>"><?php echo esc_html( $row['result_label'] ); ?></span></td>
						<td>
							<?php
							echo esc_html( $row['opponent_name'] );
							if ( $show_opponent_rating && '' !== $row['opponent_rating'] ) {
								echo ' (' . esc_html( $row['opponent_rating'] ) . ')';
							}
							?>
						</td>
						<?php
						if ( $show_event ) :
							?>
							<td><?php echo esc_html( $row['event_name'] ); ?></td><?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
