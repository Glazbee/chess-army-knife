<?php
/**
 * Server-side render for the Tournament Games to Play block.
 *
 * Everyone sees the games still to be played. Administrators also get a pair of
 * score selectors per game and a Save button at the top; view.js saves them
 * through the REST API.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its styling is added to the wrapper.
$attributes = Chess_Army_Knife_Templates::apply( 'tournament-games', $attributes );

$tournament_id = isset( $attributes['tournamentId'] ) ? (int) $attributes['tournamentId'] : 0;
$tournament    = $tournament_id ? Chess_Army_Knife_Tournament_Store::get_tournament( $tournament_id ) : null;

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'tournament-games', $attributes );

if ( ! $tournament ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'Tournament Games to Play: choose a tournament in the block settings.', 'chess-army-knife' )
	);
	return;
}

// Show some rounds at a time, so a big tournament does not make a page of a hundred games.
$per_page   = isset( $attributes['roundsPerPage'] ) ? max( 0, min( 100, (int) $attributes['roundsPerPage'] ) ) : 1;
$page_param = 'cak_games_' . $tournament_id;
$wanted     = isset( $_GET[ $page_param ] ) ? absint( $_GET[ $page_param ] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only choice of which page of games to show.
$games_page = Chess_Army_Knife_Tournament_Summary::paginate( Chess_Army_Knife_Tournament_Summary::games_to_play( $tournament_id ), $wanted, $per_page );
$sections   = $games_page['sections'];
$can_edit   = ! empty( $sections ) && Chess_Army_Knife_Tournaments::user_can_manage();

// The address of a page of games: the first page has no page in its address.
$page_url = function ( $number ) use ( $page_param ) {
	return $number > 1 ? add_query_arg( $page_param, $number ) : remove_query_arg( $page_param );
};

// The score a player can be given, and the label shown for it.
$score_options = array(
	''    => __( 'No result', 'chess-army-knife' ),
	'1'   => '1',
	'0.5' => '½',
	'0'   => '0',
);
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>
	<?php if ( $can_edit ) : ?>
		data-rest-url="<?php echo esc_url( rest_url( Chess_Army_Knife_Tournament_REST::NAMESPACE_V1 . '/games/results' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
		data-msg-saving="<?php esc_attr_e( 'Saving…', 'chess-army-knife' ); ?>"
		data-msg-row-saved="<?php esc_attr_e( 'Saved', 'chess-army-knife' ); ?>"
		data-msg-saved="<?php esc_attr_e( 'The results were saved.', 'chess-army-knife' ); ?>"
		data-msg-some-failed="<?php esc_attr_e( 'Some results could not be saved. They are marked below.', 'chess-army-knife' ); ?>"
		data-msg-error="<?php esc_attr_e( 'The results could not be saved. Please try again.', 'chess-army-knife' ); ?>"
		data-msg-partner="<?php /* translators: 1: player name, 2: their score */ esc_attr_e( '%1$s set to %2$s', 'chess-army-knife' ); ?>"
	<?php endif; ?>
>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-games__title', $tournament['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php if ( Chess_Army_Knife_Tournaments::STATUS_DRAFT === $tournament['status'] ) : ?>
		<div class="chess-army-knife-empty"><?php esc_html_e( 'This tournament has not started yet.', 'chess-army-knife' ); ?></div>
	<?php elseif ( empty( $sections ) ) : ?>
		<div class="chess-army-knife-empty"><?php esc_html_e( 'There are no games waiting to be played.', 'chess-army-knife' ); ?></div>
	<?php endif; ?>

	<?php if ( $can_edit ) : ?>
		<div class="cak-games__toolbar">
			<button type="button" class="cak-games__save wp-element-button" disabled><?php esc_html_e( 'Save results', 'chess-army-knife' ); ?></button>
			<span class="cak-games__status" role="status" aria-live="polite" tabindex="-1"></span>
		</div>
	<?php endif; ?>

	<?php foreach ( $sections as $label => $games ) : ?>
		<?php echo Chess_Army_Knife_A11y::heading( 1, 'cak-games__round', $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
		<ul class="cak-games__list">
			<?php foreach ( $games as $game ) : ?>
				<li class="cak-games__game<?php echo $can_edit ? '' : ' cak-games__game--readonly'; ?>" data-game-id="<?php echo esc_attr( $game['id'] ); ?>">
					<span class="cak-games__player cak-games__player--white"><?php echo esc_html( $game['white'] ); ?> <?php echo Chess_Army_Knife_A11y::hidden( __( '(White)', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden(). ?></span>
					<?php if ( $can_edit ) : ?>
						<select class="cak-games__score" data-side="white" aria-label="<?php /* translators: %s: player name */ echo esc_attr( sprintf( __( 'Score for %s', 'chess-army-knife' ), $game['white'] ) ); ?>">
							<?php foreach ( $score_options as $value => $option_label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $option_label ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<span class="cak-games__vs"><?php esc_html_e( 'vs', 'chess-army-knife' ); ?></span>
					<?php if ( $can_edit ) : ?>
						<select class="cak-games__score" data-side="black" aria-label="<?php /* translators: %s: player name */ echo esc_attr( sprintf( __( 'Score for %s', 'chess-army-knife' ), $game['black'] ) ); ?>">
							<?php foreach ( $score_options as $value => $option_label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $option_label ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<span class="cak-games__player cak-games__player--black"><?php echo esc_html( $game['black'] ); ?>
					<?php
					echo Chess_Army_Knife_A11y::hidden( __( '(Black)', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden().
					?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endforeach; ?>

	<?php if ( $games_page['pages'] > 1 ) : ?>
		<nav class="cak-games__pages" aria-label="<?php esc_attr_e( 'Pages of games', 'chess-army-knife' ); ?>">
			<?php if ( $games_page['page'] > 1 ) : ?>
				<a class="cak-games__prev" rel="prev" href="<?php echo esc_url( $page_url( $games_page['page'] - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'chess-army-knife' ); ?></a>
			<?php endif; ?>
			<span class="cak-games__page" aria-current="page">
				<?php
				$labels = $games_page['labels'];
				echo esc_html(
					sprintf(
						/* translators: 1: page, 2: number of pages, 3: the rounds shown, for example "Round 2" or "Round 2 to Round 4" */
						__( 'Page %1$d of %2$d: %3$s', 'chess-army-knife' ),
						$games_page['page'],
						$games_page['pages'],
						count( $labels ) > 1 ? sprintf( /* translators: 1: first round shown, 2: last round shown */ __( '%1$s to %2$s', 'chess-army-knife' ), reset( $labels ), end( $labels ) ) : (string) reset( $labels )
					)
				);
				?>
			</span>
			<?php if ( $games_page['page'] < $games_page['pages'] ) : ?>
				<a class="cak-games__next" rel="next" href="<?php echo esc_url( $page_url( $games_page['page'] + 1 ) ); ?>"><?php esc_html_e( 'Next', 'chess-army-knife' ); ?></a>
			<?php endif; ?>
		</nav>
		<?php if ( $can_edit ) : ?>
			<p class="cak-games__note"><?php esc_html_e( 'Save results before moving to another round.', 'chess-army-knife' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
