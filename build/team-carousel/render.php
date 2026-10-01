<?php
/**
 * Server-side render for the ECF Team Fixtures block (once a carousel: nothing moves, and every
 * team is shown, so it works without scripts and for everyone).
 *
 * Teams come from "auto" (every team in one event's league table), "manual"
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
$event_name       = trim( (string) Chess_Army_Knife_Settings::resolve( 'default_event_name', $attributes['eventName'] ?? '' ) );
$team_source      = isset( $attributes['teamSource'] ) ? $attributes['teamSource'] : 'club-teams';
$manual_teams_raw = isset( $attributes['manualTeams'] ) ? (string) $attributes['manualTeams'] : '';
$block_title      = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$show_location    = ! empty( $attributes['showLocation'] );
$is_carousel      = isset( $attributes['layout'] ) && 'carousel' === $attributes['layout'];
$auto_advance     = $is_carousel && ! empty( $attributes['autoAdvance'] );
$interval_seconds = isset( $attributes['intervalSeconds'] ) ? max( 5, (int) $attributes['intervalSeconds'] ) : 8;
$highlight_team   = isset( $attributes['highlightTeam'] ) ? trim( (string) $attributes['highlightTeam'] ) : '';

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'team-carousel', $attributes );

// Step 1: work out the (org, event, team) triples we need slides for,
// regardless of which source they came from.
$team_specs      = array();
$table_cache_key = null; // Only set (and shown in debug/admin bar) for "auto".

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
	$table_raw       = Chess_Army_Knife_LMS_Client::get_table( $org_id, $event_name );
	$table_cache_key = Chess_Army_Knife_LMS_Client::cache_key( 'table', $org_id, $event_name );
	if ( is_wp_error( $table_raw ) ) {
		printf(
			'<div %1$s><div class="chess-army-knife-notice">%2$s %3$s</div></div>',
			wp_kses_post( $wrapper_attributes ),
			esc_html__( 'Could not load the list of teams from the league table:', 'chess-army-knife' ),
			esc_html( $table_raw->get_error_message() )
		);
		return;
	}
	foreach ( Chess_Army_Knife_LMS_Client::find_rows( $table_raw, array( 'table' ) ) as $raw_row ) {
		$normalised = Chess_Army_Knife_LMS_Client::normalise_table_row( $raw_row );
		if ( $normalised && '' !== $normalised['team'] ) {
			$team_specs[] = array(
				'org'   => $org_id,
				'event' => $event_name,
				'team'  => $normalised['team'],
			);
		}
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

// Step 2: fetch matches once per unique (org, event) pair.
$groups = array();
foreach ( $team_specs as $spec ) {
	$group_key = $spec['org'] . '|' . strtolower( $spec['event'] );
	if ( ! isset( $groups[ $group_key ] ) ) {
		$groups[ $group_key ] = array(
			'org'   => $spec['org'],
			'event' => $spec['event'],
			'raw'   => null,
			'error' => null,
			'rows'  => array(),
		);
	}
}

$admin_cache_keys = array();
if ( $table_cache_key ) {
	$admin_cache_keys[] = $table_cache_key;
}

foreach ( $groups as $group_key => &$group ) {
	$group['raw']       = Chess_Army_Knife_LMS_Client::get_matches( $group['org'], $group['event'] );
	$admin_cache_keys[] = Chess_Army_Knife_LMS_Client::cache_key( 'match', $group['org'], $group['event'] );

	if ( is_wp_error( $group['raw'] ) ) {
		$group['error'] = $group['raw'];
		continue;
	}
	foreach ( Chess_Army_Knife_LMS_Client::find_rows( $group['raw'], array( 'matches' ) ) as $raw_row ) {
		$normalised = Chess_Army_Knife_LMS_Client::normalise_match_row( $raw_row );
		if ( $normalised && ( '' !== $normalised['home'] || '' !== $normalised['away'] ) ) {
			$group['rows'][] = $normalised;
		}
	}
}
unset( $group );

/**
 * Find matches involving a team name (case-insensitive; falls back to
 * a substring match if no exact match is found, to tolerate minor
 * naming differences between the table/settings and the match list).
 *
 * @param array  $matches All normalised matches for this team's group.
 * @param string $team    Team name to look for.
 * @return array
 */
$find_team_matches = function ( $matches, $team ) {
	$exact = array_values(
		array_filter(
			$matches,
			function ( $team_match ) use ( $team ) {
				return 0 === strcasecmp( $team_match['home'], $team ) || 0 === strcasecmp( $team_match['away'], $team );
			}
		)
	);
	if ( ! empty( $exact ) ) {
		return $exact;
	}
	return array_values(
		array_filter(
			$matches,
			function ( $team_match ) use ( $team ) {
				return false !== stripos( $team_match['home'], $team ) || false !== stripos( $team_match['away'], $team );
			}
		)
	);
};

/**
 * Render one side (opponent + venue) of a match row from a team's
 * point of view.
 *
 * @param array  $match Normalised match row.
 * @param string $team  The team we're rendering the slide for.
 * @return array{opponent: string, venue: string}
 */
$describe_side = function ( $match, $team ) {
	$is_home  = 0 === strcasecmp( $match['home'], $team ) || false !== stripos( $match['home'], $team );
	$opponent = $is_home ? $match['away'] : $match['home'];
	$venue    = $is_home ? __( 'Home', 'chess-army-knife' ) : __( 'Away', 'chess-army-knife' );
	return array(
		'opponent' => $opponent,
		'venue'    => $venue,
		'location' => isset( $match['venue'] ) ? $match['venue'] : '',
	);
};

$today  = current_time( 'Y-m-d' );
$slides = array();

foreach ( $team_specs as $spec ) {
	$group_key = $spec['org'] . '|' . strtolower( $spec['event'] );
	$group     = $groups[ $group_key ];

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

	$team_matches = $find_team_matches( $group['rows'], $spec['team'] );

	$results  = array();
	$fixtures = array();
	foreach ( $team_matches as $team_match ) {
		$has_score = '' !== $team_match['home_score'] || '' !== $team_match['away_score'] || '' !== $team_match['result_text'];
		if ( $has_score ) {
			$results[] = $team_match;
		} else {
			$fixtures[] = $team_match;
		}
	}

	usort(
		$results,
		function ( $a, $b ) {
			return strcmp( $b['date'], $a['date'] );
		}
	);
	usort(
		$fixtures,
		function ( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		}
	);

	$next_fixture = null;
	foreach ( $fixtures as $f ) {
		if ( '' === $f['date'] || $f['date'] >= $today ) {
			$next_fixture = $f;
			break;
		}
	}
	if ( ! $next_fixture && ! empty( $fixtures ) ) {
		$next_fixture = $fixtures[0];
	}

	$slides[] = array(
		'team'         => $spec['team'],
		'event'        => $spec['event'],
		'error'        => null,
		'last_result'  => ! empty( $results ) ? $results[0] : null,
		'next_fixture' => $next_fixture,
	);
}

$heading     = $block_title ? $block_title : ( 'club-teams' === $team_source ? __( 'Our teams', 'chess-army-knife' ) : $event_name );
$multi_event = count( array_unique( wp_list_pluck( $team_specs, 'event' ) ) ) > 1;
$team_tag    = Chess_Army_Knife_Headings::tag( 1 );

/**
 * A match as a sentence, for example "Home against Bath, 3 to 1, on 4 March 2025".
 *
 * @param array $side       The side from $describe_side().
 * @param array $match      The normalised match row.
 * @param bool  $with_score Whether to say the score.
 * @return string
 */
$match_text = function ( $side, $match, $with_score ) {
	$text = __( 'Home', 'chess-army-knife' ) === $side['venue']
		/* translators: %s: opponent */
		? sprintf( __( 'Home against %s', 'chess-army-knife' ), $side['opponent'] )
		/* translators: %s: opponent */
		: sprintf( __( 'Away against %s', 'chess-army-knife' ), $side['opponent'] );

	if ( $with_score ) {
		$score = ( '' !== $match['home_score'] || '' !== $match['away_score'] )
			/* translators: 1: home score, 2: away score */
			? sprintf( __( '%1$s to %2$s', 'chess-army-knife' ), $match['home_score'], $match['away_score'] )
			: $match['result_text'];
		if ( '' !== $score ) {
			$text .= ', ' . $score;
		}
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
								<?php $side = $describe_side( $slide['last_result'], $slide['team'] ); ?>
								<?php echo esc_html( $match_text( $side, $slide['last_result'], true ) ); ?>
								<?php if ( '' !== $slide['last_result']['date'] ) : ?>
									<time datetime="<?php echo esc_attr( $slide['last_result']['date'] ); ?>"><?php echo esc_html( mysql2date( $date_format, $slide['last_result']['date'] ) ); ?></time>
								<?php endif; ?>
								<?php if ( $show_location && '' !== $side['location'] ) : ?>
									<span class="ecf-carousel__location"><?php echo esc_html( $side['location'] ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<?php esc_html_e( 'None played yet', 'chess-army-knife' ); ?>
							<?php endif; ?>
						</dd>
						<dt><?php esc_html_e( 'Next fixture', 'chess-army-knife' ); ?></dt>
						<dd>
							<?php if ( $slide['next_fixture'] ) : ?>
								<?php $side = $describe_side( $slide['next_fixture'], $slide['team'] ); ?>
								<?php echo esc_html( $match_text( $side, $slide['next_fixture'], false ) ); ?>
								<?php if ( '' !== $slide['next_fixture']['date'] ) : ?>
									<time datetime="<?php echo esc_attr( $slide['next_fixture']['date'] ); ?>"><?php echo esc_html( mysql2date( $date_format, $slide['next_fixture']['date'] ) ); ?></time>
								<?php endif; ?>
								<?php if ( $show_location && '' !== $side['location'] ) : ?>
									<span class="ecf-carousel__location"><?php echo esc_html( $side['location'] ); ?></span>
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
