<?php
/**
 * Server-side render for the Team Results block: one team's league position,
 * next fixture and results, with the boards of each match. Reads the LMS v2
 * API (see Chess_Army_Knife_League_Data).
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$org_id      = trim( (string) Chess_Army_Knife_Settings::resolve( 'default_org_id', isset( $attributes['orgId'] ) ? $attributes['orgId'] : '' ) );
$event_name  = isset( $attributes['eventName'] ) ? trim( (string) $attributes['eventName'] ) : '';
$team_name   = isset( $attributes['team'] ) ? trim( (string) $attributes['team'] ) : '';
$season      = isset( $attributes['season'] ) ? trim( (string) $attributes['season'] ) : '';
$block_title = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$show_boards = ! isset( $attributes['showBoards'] ) || $attributes['showBoards'];

// With no team typed, use the first of the club's own teams (and its league).
if ( '' === $team_name ) {
	$own_teams = Chess_Army_Knife_Settings::get_club_teams();
	if ( $own_teams ) {
		$team_name  = $own_teams[0]['team'];
		$org_id     = '' !== $org_id && '' !== $event_name ? $org_id : (string) $own_teams[0]['org'];
		$event_name = '' !== $event_name ? $event_name : (string) $own_teams[0]['event'];
	}
}

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'team-page', $attributes );

if ( '' === $team_name || '' === $org_id || '' === $event_name ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'Team Results: enter a team name, an LMS organisation ID and an event name in the block settings.', 'chess-army-knife' )
	);
	return;
}

$loaded = Chess_Army_Knife_League_Data::load( $org_id, $event_name, $season );
if ( $loaded['error'] ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s %3$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'Could not load the team\'s results:', 'chess-army-knife' ),
		esc_html( $loaded['error']->get_error_message() )
	);
	return;
}

$lms_name = Chess_Army_Knife_League_Data::lms_name( $loaded['fixtures'], $team_name );
if ( '' === $lms_name ) {
	printf(
		'<div %1$s><div class="chess-army-knife-empty">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'That team is not in this event. Check the team name and the event.', 'chess-army-knife' )
	);
	return;
}

$today      = current_time( 'Y-m-d' );
$date_fmt   = get_option( 'date_format' );
$team_rows  = Chess_Army_Knife_League_Data::team_fixtures( $loaded['fixtures'], $lms_name );
$next       = Chess_Army_Knife_League_Data::next_fixture( $team_rows, $today );
$standing   = null;
$league_len = 0;
foreach ( Chess_Army_Knife_League_Data::standings( $loaded['fixtures'] ) as $table_row ) {
	++$league_len;
	if ( $table_row['team'] === $lms_name ) {
		$standing = $table_row;
	}
}

$score = array( 'Chess_Army_Knife_LMS_Client', 'format_score' );
$words = array(
	'won'   => __( 'Won', 'chess-army-knife' ),
	'drawn' => __( 'Drawn', 'chess-army-knife' ),
	'lost'  => __( 'Lost', 'chess-army-knife' ),
);

/**
 * A player and rating as text.
 *
 * @param string   $name   Player name, or '' for a default.
 * @param int|null $rating Rating, or null if unrated.
 * @return string
 */
$player_text = function ( $name, $rating ) {
	if ( '' === $name ) {
		return __( 'Default', 'chess-army-knife' );
	}

	/* translators: 1: player name, 2: rating */
	return null === $rating ? sprintf( __( '%1$s (unrated)', 'chess-army-knife' ), $name ) : sprintf( __( '%1$s (%2$d)', 'chess-army-knife' ), $name, $rating );
};
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-team__title', '' !== $block_title ? $block_title : $lms_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
	<p class="cak-team__event"><?php echo esc_html( $event_name ); ?></p>

	<?php echo Chess_Army_Knife_Admin_Refresh::bar( $loaded['cache_keys'], __( 'Team data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Admin_Refresh::bar(). ?>

	<?php if ( $standing ) : ?>
		<dl class="cak-team__standing">
			<div><dt><?php esc_html_e( 'Position', 'chess-army-knife' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: position, 2: number of teams */ __( '%1$d of %2$d', 'chess-army-knife' ), $standing['position'], $league_len ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Played', 'chess-army-knife' ); ?></dt><dd><?php echo esc_html( $standing['played'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Won', 'chess-army-knife' ); ?></dt><dd><?php echo esc_html( $standing['won'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Drawn', 'chess-army-knife' ); ?></dt><dd><?php echo esc_html( $standing['drawn'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Lost', 'chess-army-knife' ); ?></dt><dd><?php echo esc_html( $standing['lost'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Match points', 'chess-army-knife' ); ?></dt><dd><?php echo esc_html( call_user_func( $score, $standing['points'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Board points', 'chess-army-knife' ); ?></dt><dd><?php echo esc_html( call_user_func( $score, $standing['for'] ) . ' – ' . call_user_func( $score, $standing['against'] ) ); ?></dd></div>
		</dl>
	<?php endif; ?>

	<p class="cak-team__next">
		<strong><?php esc_html_e( 'Next fixture:', 'chess-army-knife' ); ?></strong>
		<?php if ( $next ) : ?>
			<?php
			echo esc_html(
				'home' === $next['side']
					/* translators: %s: opponent */
					? sprintf( __( 'Home against %s', 'chess-army-knife' ), $next['opponent'] )
					/* translators: %s: opponent */
					: sprintf( __( 'Away against %s', 'chess-army-knife' ), $next['opponent'] )
			);
			?>
			<?php echo Chess_Army_Knife_A11y::date( $next['fixture']['date'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in date(). ?>
			<?php echo esc_html( $next['fixture']['time'] ); ?>
		<?php else : ?>
			<?php esc_html_e( 'None scheduled', 'chess-army-knife' ); ?>
		<?php endif; ?>
	</p>

	<?php echo Chess_Army_Knife_A11y::heading( 1, 'cak-team__subheading', __( 'Results and fixtures', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
	<ol class="cak-team__results">
		<?php foreach ( $team_rows as $row ) : ?>
			<?php $fixture = $row['fixture']; ?>
			<li class="cak-team__result">
				<?php if ( '' !== $fixture['date'] ) : ?>
					<?php echo Chess_Army_Knife_A11y::date( $fixture['date'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in date(). ?>
				<?php endif; ?>
				<?php
				echo esc_html(
					'home' === $row['side']
						/* translators: %s: opponent */
						? sprintf( __( 'Home against %s', 'chess-army-knife' ), $row['opponent'] )
						/* translators: %s: opponent */
						: sprintf( __( 'Away against %s', 'chess-army-knife' ), $row['opponent'] )
				);
				?>
				<?php if ( '' !== $row['outcome'] ) : ?>
					<span class="cak-team__outcome"><?php echo esc_html( $words[ $row['outcome'] ] ); ?></span>
					<?php echo Chess_Army_Knife_A11y::score( $row['for'], $row['against'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in score(). ?>
				<?php else : ?>
					<span class="cak-team__outcome"><?php echo esc_html( '' !== $fixture['time'] ? $fixture['time'] : __( 'Not played yet', 'chess-army-knife' ) ); ?></span>
				<?php endif; ?>

				<?php if ( $show_boards && $fixture['games'] ) : ?>
					<details class="cak-team__boards">
						<summary><?php esc_html_e( 'Board by board', 'chess-army-knife' ); ?></summary>
						<table>
							<caption class="cak-visually-hidden"><?php echo esc_html( sprintf( /* translators: %s: opponent */ __( 'Boards against %s', 'chess-army-knife' ), $row['opponent'] ) ); ?></caption>
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Board', 'chess-army-knife' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Colour', 'chess-army-knife' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Our player', 'chess-army-knife' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Opponent', 'chess-army-knife' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Result', 'chess-army-knife' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( Chess_Army_Knife_League_Data::boards_for_side( $fixture, $row['side'] ) as $board ) : ?>
									<tr>
										<td><?php echo esc_html( $board['board'] ); ?></td>
										<td><?php echo esc_html( 'W' === $board['colour'] ? __( 'White', 'chess-army-knife' ) : ( 'B' === $board['colour'] ? __( 'Black', 'chess-army-knife' ) : '' ) ); ?></td>
										<td><?php echo esc_html( $player_text( $board['player'], $board['rating'] ) ); ?></td>
										<td><?php echo esc_html( $player_text( $board['opponent'], $board['opponent_rating'] ) ); ?></td>
										<td>
											<?php
											$board_words = array(
												'win'  => __( 'Won', 'chess-army-knife' ),
												'draw' => __( 'Drawn', 'chess-army-knife' ),
												'loss' => __( 'Lost', 'chess-army-knife' ),
											);
											echo esc_html( isset( $board_words[ $board['result'] ] ) ? $board_words[ $board['result'] ] : '' );
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</details>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</div>
