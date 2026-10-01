<?php
/**
 * Server-side render for the ECF Biggest Rating Gainers block.
 *
 * For a bounded sample of the club's own current members who have an ECF
 * rating code (never anyone the club holds no record of), pulls each
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

$rating_domain = Chess_Army_Knife_ECF_Client::normalise_domain( Chess_Army_Knife_Settings::resolve( 'default_domain', $attributes['domain'] ?? '', 'S' ) );
$days_back     = max( 1, (int) Chess_Army_Knife_Settings::resolve( 'default_days_back', empty( $attributes['daysBack'] ) ? '' : $attributes['daysBack'], 60 ) );
$max_players   = max( 1, (int) Chess_Army_Knife_Settings::resolve( 'default_max_players', empty( $attributes['maxPlayers'] ) ? '' : $attributes['maxPlayers'], 12 ) );
$top_count     = isset( $attributes['topCount'] ) ? max( 1, (int) $attributes['topCount'] ) : 5;
$min_games     = isset( $attributes['minGames'] ) ? max( 1, (int) $attributes['minGames'] ) : 2;
$show_detail   = ! isset( $attributes['showDetail'] ) || (bool) $attributes['showDetail'];
$block_title   = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';

// How many recent games to pull per player when looking for the
// earliest/latest rating inside the window. Not exposed as a block
// setting (it's an internal implementation detail), but generous
// enough to comfortably cover a season's worth of club-night games.
$games_per_player = 40;

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'biggest-gainers', $attributes );

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

$movers           = array();
$admin_cache_keys = array();

foreach ( $players as $player ) {
	$admin_cache_keys[] = Chess_Army_Knife_ECF_Client::cache_key_games( $player['code'], $rating_domain, $games_per_player );
	$games              = Chess_Army_Knife_ECF_Client::get_games( $player['code'], $rating_domain, $games_per_player );

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

$heading = $block_title ? $block_title : __( 'Biggest improvers', 'chess-army-knife' );
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'ecf-gainers__title', $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php echo Chess_Army_Knife_Admin_Refresh::bar( $admin_cache_keys, __( 'Ratings data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Admin_Refresh::bar(). ?>

	<?php if ( empty( $movers ) ) : ?>
		<div class="chess-army-knife-empty">
			<?php esc_html_e( 'No players with a positive rating change and enough recent rated games were found in this window. Try increasing "days to look back" or lowering the minimum games to qualify.', 'chess-army-knife' ); ?>
		</div>
	<?php else : ?>
		<ol class="ecf-gainers__list">
			<?php foreach ( $movers as $i => $mover ) : ?>
				<li class="ecf-gainers__item">
					<span class="ecf-gainers__rank" aria-hidden="true">#<?php echo (int) ( $i + 1 ); ?></span>
					<span class="ecf-gainers__name"><?php echo esc_html( $mover['name'] ); ?></span>
					<?php
					if ( $show_detail ) :
						?>
						<span class="ecf-gainers__detail">
						<?php
						/* translators: 1: rating before, 2: rating after */
						echo esc_html( sprintf( __( 'from %1$d to %2$d', 'chess-army-knife' ), (int) $mover['from'], (int) $mover['to'] ) );
						?>
						<?php /* translators: %d: number of games */ ?>
						(<?php echo esc_html( sprintf( _n( '%d game', '%d games', $mover['games'], 'chess-army-knife' ), $mover['games'] ) ); ?>)
					</span><?php endif; ?>
					<span class="ecf-gainers__change"><?php echo Chess_Army_Knife_A11y::hidden( __( 'Gain:', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden(). ?> <?php echo esc_html( ( $mover['gain'] >= 0 ? '+' : '' ) . (int) $mover['gain'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</div>
