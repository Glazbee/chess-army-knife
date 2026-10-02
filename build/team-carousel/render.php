<?php
/**
 * Server-side render for the ECF Team Fixtures block (once a carousel: nothing moves, and every
 * team is shown, so it works without scripts and for everyone).
 *
 * Reads the LMS v2 API (see Chess_Army_Knife_League_Data). Teams come from "auto" (every team in one event), "manual"
 * (typed team names within one event) or "club-teams" (the Settings list,
 * which can span several organisations and events). Matches are fetched once
 * per unique (org, event) pair and sliced per team.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its settings override this block's own.
$attributes = Chess_Army_Knife_Templates::apply( 'team-carousel', $attributes );

$org_id           = trim( (string) Chess_Army_Knife_Settings::resolve( 'default_org_id', $attributes['orgId'] ?? '' ) );
$event_name       = trim( (string) ( $attributes['eventName'] ?? '' ) );
$team_source      = isset( $attributes['teamSource'] ) ? $attributes['teamSource'] : 'club-teams';
$manual_teams_raw = isset( $attributes['manualTeams'] ) ? (string) $attributes['manualTeams'] : '';
$block_title      = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$season           = isset( $attributes['season'] ) ? trim( (string) $attributes['season'] ) : '';
$is_carousel      = isset( $attributes['layout'] ) && 'carousel' === $attributes['layout'];
$auto_advance     = $is_carousel && ! empty( $attributes['autoAdvance'] );
$interval_seconds = isset( $attributes['intervalSeconds'] ) ? max( 5, (int) $attributes['intervalSeconds'] ) : 8;
$highlight_team   = isset( $attributes['highlightTeam'] ) ? trim( (string) $attributes['highlightTeam'] ) : '';

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'team-carousel', $attributes );

// Step 1: work out the (org, event, team) triples we need slides for,
// regardless of which source they came from.
$team_specs = array();

if ( 'club-teams' === $team_source ) {
	$configured = Chess_Army_Knife_Settings::get_club_teams();
	if ( empty( $configured ) ) {
		printf(
			'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
			wp_kses_post( $wrapper_attributes ),
			wp_kses_post(
				sprintf(
					/* translators: %s: link to the Teams screen */
					__( 'No teams are configured yet. Add your teams, with their leagues, under %s.', 'chess-army-knife' ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Teams::POST_TYPE ) ) . '">' . esc_html__( 'Teams', 'chess-army-knife' ) . '</a>'
				)
			)
		);
		return;
	}
	$team_specs = $configured;
} elseif ( 'manual' === $team_source ) {
	if ( '' === $org_id || '' === $event_name ) {
		printf(
			'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
			wp_kses_post( $wrapper_attributes ),
			esc_html__( 'ECF Team Fixtures: enter an LMS organisation ID and event name in the block settings.', 'chess-army-knife' )
		);
		return;
	}
	foreach ( preg_split( '/[\r\n,]+/', $manual_teams_raw ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line ) {
			$team_specs[] = array(
				'org'   => $org_id,
				'event' => $event_name,
				'team'  => $line,
			);
		}
	}
} else { // 'auto'
	if ( '' === $org_id || '' === $event_name ) {
		printf(
			'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
			wp_kses_post( $wrapper_attributes ),
			esc_html__( 'ECF Team Fixtures: enter an LMS organisation ID and event name in the block settings.', 'chess-army-knife' )
		);
		return;
	}
	$auto_loaded = Chess_Army_Knife_League_Data::load( $org_id, $event_name, $season );
	if ( $auto_loaded['error'] ) {
		printf(
			'<div %1$s><div class="chess-army-knife-notice">%2$s %3$s</div></div>',
			wp_kses_post( $wrapper_attributes ),
			esc_html__( 'Could not load the list of teams:', 'chess-army-knife' ),
			esc_html( $auto_loaded['error']->get_error_message() )
		);
		return;
	}
	foreach ( Chess_Army_Knife_League_Data::team_names( $auto_loaded['fixtures'] ) as $auto_team ) {
		$team_specs[] = array(
			'org'   => $org_id,
			'event' => $event_name,
			'team'  => $auto_team,
		);
	}
}

if ( empty( $team_specs ) ) {
	printf(
		'<div %1$s><div class="chess-army-knife-empty">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'No teams were found. If using a manual list, check the team names; otherwise check the organisation ID and event name.', 'chess-army-knife' )
	);
	return;
}

// Step 2: fetch each event's fixtures once, however many of its teams are shown.
$groups           = array();
$admin_cache_keys = array();
foreach ( $team_specs as $spec ) {
	$group_key = $spec['org'] . '|' . strtolower( $spec['event'] );
	if ( ! isset( $groups[ $group_key ] ) ) {
		$groups[ $group_key ] = Chess_Army_Knife_League_Data::load( $spec['org'], $spec['event'], $season );
		$admin_cache_keys     = array_merge( $admin_cache_keys, $groups[ $group_key ]['cache_keys'] );
	}
}

$today  = current_time( 'Y-m-d' );
$slides = array();

foreach ( $team_specs as $spec ) {
	$group = $groups[ $spec['org'] . '|' . strtolower( $spec['event'] ) ];

	if ( $group['error'] ) {
		$slides[] = array(
			'team'         => $spec['team'],
			'event'        => $spec['event'],
			'error'        => $group['error']->get_error_message(),
			'last_result'  => null,
			'next_fixture' => null,
		);
		continue;
	}

	$team_rows = Chess_Army_Knife_League_Data::team_fixtures( $group['fixtures'], $spec['team'] );
	$played    = array_values(
		array_filter(
			$team_rows,
			function ( $row ) {
				return '' !== $row['outcome'];
			}
		)
	);
	$next      = Chess_Army_Knife_League_Data::next_fixture( $team_rows, $today );
	if ( ! $next ) {
		// Nothing from today on, so show the first one that was never played (postponed, say).
		foreach ( $team_rows as $row ) {
			if ( '' === $row['outcome'] ) {
				$next = $row;
				break;
			}
		}
	}

	$slides[] = array(
		'team'         => $spec['team'],
		'event'        => $spec['event'],
		'error'        => null,
		'last_result'  => $played ? $played[ count( $played ) - 1 ] : null,
		'next_fixture' => $next,
	);
}

$heading     = $block_title ? $block_title : ( 'club-teams' === $team_source ? __( 'Our teams', 'chess-army-knife' ) : $event_name );
$multi_event = count( array_unique( wp_list_pluck( $team_specs, 'event' ) ) ) > 1;
$team_tag    = Chess_Army_Knife_Headings::tag( 1 );

/**
 * A match as a sentence, for example "Home against Bath, 3 to 1, on 4 March 2025".
 *
 * @param array $row        A row of Chess_Army_Knife_League_Data::team_fixtures().
 * @param bool  $with_score Whether to say the score.
 * @return string
 */
$match_text = function ( $row, $with_score ) {
	$text = 'home' === $row['side']
		/* translators: %s: opponent */
		? sprintf( __( 'Home against %s', 'chess-army-knife' ), $row['opponent'] )
		/* translators: %s: opponent */
		: sprintf( __( 'Away against %s', 'chess-army-knife' ), $row['opponent'] );

	if ( $with_score && '' !== $row['outcome'] ) {
		$text .= sprintf(
			/* translators: 1: this team's score, 2: the other team's score */
			', ' . __( '%1$s to %2$s', 'chess-army-knife' ),
			$row['for'],
			$row['against']
		);
	}

	return $text;
};

$date_format = get_option( 'date_format' );
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'ecf-carousel__title', $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php echo Chess_Army_Knife_Admin_Refresh::bar( $admin_cache_keys, __( 'Fixtures data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Admin_Refresh::bar(). ?>

	<?php if ( $is_carousel ) : ?>
		<div
			class="ecf-carousel"
			data-cak-carousel
			data-auto="<?php echo $auto_advance ? '1' : '0'; ?>"
			data-interval="<?php echo (int) $interval_seconds; ?>"
			role="group"
			aria-roledescription="<?php esc_attr_e( 'carousel', 'chess-army-knife' ); ?>"
			aria-label="<?php echo esc_attr( $heading ); ?>"
			data-msg-previous="<?php esc_attr_e( 'Previous team', 'chess-army-knife' ); ?>"
			data-msg-next="<?php esc_attr_e( 'Next team', 'chess-army-knife' ); ?>"
			data-msg-pause="<?php esc_attr_e( 'Pause moving on by itself', 'chess-army-knife' ); ?>"
			data-msg-play="<?php esc_attr_e( 'Start moving on by itself', 'chess-army-knife' ); ?>"
			data-msg-show="<?php /* translators: %s: team name */ esc_attr_e( 'Show %s', 'chess-army-knife' ); ?>"
			data-msg-slide="<?php /* translators: 1: position, 2: number of teams, 3: team name */ esc_attr_e( 'Team %1$s of %2$s: %3$s', 'chess-army-knife' ); ?>"
			data-msg-slide-word="<?php esc_attr_e( 'team', 'chess-army-knife' ); ?>"
			data-msg-controls="<?php esc_attr_e( 'Carousel controls', 'chess-army-knife' ); ?>"
		>
	<?php endif; ?>
	<ul class="ecf-carousel__list">
		<?php foreach ( $slides as $slide ) : ?>
			<?php $is_highlighted = '' !== $highlight_team && false !== stripos( $slide['team'], $highlight_team ); ?>
			<li class="ecf-carousel__slide<?php echo $is_highlighted ? ' is-highlighted' : ''; ?>">
				<<?php echo esc_attr( $team_tag ); ?> class="ecf-carousel__team-name">
					<?php echo esc_html( $slide['team'] ); ?>
					<?php if ( $is_highlighted ) : ?>
						<span class="cak-visually-hidden"><?php esc_html_e( '(our team)', 'chess-army-knife' ); ?></span>
					<?php endif; ?>
					<?php if ( $multi_event ) : ?>
						<span class="ecf-carousel__event-name"><?php echo esc_html( $slide['event'] ); ?></span>
					<?php endif; ?>
				</<?php echo esc_attr( $team_tag ); ?>>

				<?php if ( $slide['error'] ) : ?>
					<p class="ecf-carousel__row"><?php echo esc_html( $slide['error'] ); ?></p>
				<?php else : ?>
					<dl class="ecf-carousel__details">
						<dt><?php esc_html_e( 'Last result', 'chess-army-knife' ); ?></dt>
						<dd>
							<?php if ( $slide['last_result'] ) : ?>
								<?php echo esc_html( $match_text( $slide['last_result'], true ) ); ?>
								<?php if ( '' !== $slide['last_result']['fixture']['date'] ) : ?>
									<time datetime="<?php echo esc_attr( $slide['last_result']['fixture']['date'] ); ?>"><?php echo esc_html( mysql2date( $date_format, $slide['last_result']['fixture']['date'] ) ); ?></time>
								<?php endif; ?>
							<?php else : ?>
								<?php esc_html_e( 'None played yet', 'chess-army-knife' ); ?>
							<?php endif; ?>
						</dd>
						<dt><?php esc_html_e( 'Next fixture', 'chess-army-knife' ); ?></dt>
						<dd>
							<?php if ( $slide['next_fixture'] ) : ?>
								<?php echo esc_html( $match_text( $slide['next_fixture'], false ) ); ?>
								<?php if ( '' !== $slide['next_fixture']['fixture']['date'] ) : ?>
									<time datetime="<?php echo esc_attr( $slide['next_fixture']['fixture']['date'] ); ?>"><?php echo esc_html( mysql2date( $date_format, $slide['next_fixture']['fixture']['date'] ) ); ?></time>
								<?php endif; ?>
							<?php else : ?>
								<?php esc_html_e( 'None scheduled', 'chess-army-knife' ); ?>
							<?php endif; ?>
						</dd>
					</dl>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php if ( $is_carousel ) : ?>
		</div>
	<?php endif; ?>
</div>
