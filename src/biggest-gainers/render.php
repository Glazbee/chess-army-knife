<?php
/**
 * Server-side render for the ECF Biggest Rating Gainers block.
 *
 * For a bounded, rating-ordered sample of a club's roster, pulls each
 * player's recent games, finds the earliest and latest rating recorded
 * within the look-back window, and ranks players by that change.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its settings override this block's own.
$attributes = Chess_Army_Knife_Templates::apply( 'biggest-gainers', $attributes );

$club_code   = strtoupper( trim( Chess_Army_Knife_Settings::resolve( 'default_club_code', $attributes['clubCode'] ?? '' ) ) );
$club_name   = isset( $attributes['clubName'] ) ? $attributes['clubName'] : '';
$domain      = ECF_Client::normalise_domain( Chess_Army_Knife_Settings::resolve( 'default_domain', $attributes['domain'] ?? '', 'S' ) );
$days_back   = max( 1, (int) Chess_Army_Knife_Settings::resolve( 'default_days_back', empty( $attributes['daysBack'] ) ? '' : $attributes['daysBack'], 60 ) );
$max_players = max( 1, (int) Chess_Army_Knife_Settings::resolve( 'default_max_players', empty( $attributes['maxPlayers'] ) ? '' : $attributes['maxPlayers'], 12 ) );
$top_count   = isset( $attributes['topCount'] ) ? max( 1, (int) $attributes['topCount'] ) : 5;
$min_games   = isset( $attributes['minGames'] ) ? max( 1, (int) $attributes['minGames'] ) : 2;
$show_detail = ! isset( $attributes['showDetail'] ) || (bool) $attributes['showDetail'];
$title       = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';

// How many recent games to pull per player when looking for the
// earliest/latest rating inside the window. Not exposed as a block
// setting (it's an internal implementation detail), but generous
// enough to comfortably cover a season's worth of club-night games.
$games_per_player = 40;

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'biggest-gainers', $attributes );

if ( '' === $club_code ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'ECF Biggest Rating Gainers: no club selected yet. Edit this block and search for a club, or set a site-wide default club on the settings page.', 'chess-army-knife' )
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

$movers           = array();
$admin_cache_keys = array( ECF_Client::cache_key_club_players( $club_code ) );

foreach ( $players as $player ) {
	$admin_cache_keys[] = ECF_Client::cache_key_games( $player['code'], $domain, $games_per_player );
	$games              = ECF_Client::get_games( $player['code'], $domain, $games_per_player );

	if ( is_wp_error( $games ) || empty( $games ) ) {
		continue;
	}

	// Only games inside the look-back window with a recorded rating snapshot.
	$points = array();
	foreach ( $games as $game ) {
		if ( empty( $game['game_date'] ) || $game['game_date'] < $cutoff ) {
			continue;
		}
		if ( '' === $game['player_rating'] || null === $game['player_rating'] ) {
			continue;
		}
		$points[] = array(
			'date'   => $game['game_date'],
			'rating' => (float) $game['player_rating'],
		);
	}

	if ( count( $points ) < $min_games ) {
		continue; // Not enough recent games to call this a meaningful trend.
	}

	usort(
		$points,
		function ( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		}
	);

	$from = reset( $points )['rating'];
	$to   = end( $points )['rating'];
	$gain = $to - $from;

	if ( $gain <= 0 ) {
		continue; // "Biggest gainers" should only ever show improvers, not flat/declining ratings.
	}

	$movers[] = array(
		'name'  => $player['name'],
		'from'  => $from,
		'to'    => $to,
		'gain'  => $gain,
		'games' => count( $points ),
	);
}

usort(
	$movers,
	function ( $a, $b ) {
		return $b['gain'] <=> $a['gain'];
	}
);

$movers = array_slice( $movers, 0, $top_count );

$heading = $title ? $title : sprintf(
	/* translators: %s: club name */
	__( 'Biggest improvers — %s', 'chess-army-knife' ),
	$club_name ? $club_name : $club_code
);
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<p class="ecf-gainers__title"><?php echo esc_html( $heading ); ?></p>

	<?php echo Chess_Army_Knife_Admin_Refresh::bar( $admin_cache_keys, __( 'Ratings data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	<?php if ( empty( $movers ) ) : ?>
		<div class="chess-army-knife-empty">
			<?php esc_html_e( 'No players with a positive rating change and enough recent rated games were found in this window. Try increasing "days to look back" or lowering the minimum games to qualify.', 'chess-army-knife' ); ?>
		</div>
	<?php else : ?>
		<ol class="ecf-gainers__list">
			<?php foreach ( $movers as $i => $mover ) : ?>
				<li class="ecf-gainers__item">
					<span class="ecf-gainers__rank">#<?php echo (int) ( $i + 1 ); ?></span>
					<span class="ecf-gainers__name"><?php echo esc_html( $mover['name'] ); ?></span>
					<?php
					if ( $show_detail ) :
						?>
						<span class="ecf-gainers__detail">
						<?php echo esc_html( (int) $mover['from'] . ' → ' . (int) $mover['to'] ); ?>
						<?php /* translators: %d: number of games */ ?>
						(<?php echo esc_html( sprintf( _n( '%d game', '%d games', $mover['games'], 'chess-army-knife' ), $mover['games'] ) ); ?>)
					</span><?php endif; ?>
					<span class="ecf-gainers__change"><?php echo esc_html( ( $mover['gain'] >= 0 ? '+' : '' ) . (int) $mover['gain'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</div>
