<?php
/**
 * Server-side render for the ECF Team Fixtures Carousel block.
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
$auto_advance     = ! isset( $attributes['autoAdvance'] ) || (bool) $attributes['autoAdvance'];
$interval_seconds = isset( $attributes['intervalSeconds'] ) ? max( 3, (int) $attributes['intervalSeconds'] ) : 6;
$show_location    = ! empty( $attributes['showLocation'] );
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
			esc_html__( 'ECF Team Fixtures Carousel: enter an LMS organisation ID and event name in the block settings.', 'chess-army-knife' )
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
			esc_html__( 'ECF Team Fixtures Carousel: enter an LMS organisation ID and event name in the block settings.', 'chess-army-knife' )
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
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<p class="ecf-carousel__title"><?php echo esc_html( $heading ); ?></p>

	<?php echo Chess_Army_Knife_Admin_Refresh::bar( $admin_cache_keys, __( 'Fixtures data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Admin_Refresh::bar(). ?>

	<div
		class="ecf-carousel"
		data-ecf-carousel
		data-auto-advance="<?php echo $auto_advance ? '1' : '0'; ?>"
		data-interval="<?php echo (int) $interval_seconds; ?>"
	>
		<div class="ecf-carousel__track">
			<?php foreach ( $slides as $i => $slide ) : ?>
				<?php
				$is_highlighted = '' !== $highlight_team && false !== stripos( $slide['team'], $highlight_team );
				$classes        = array( 'ecf-carousel__slide' );
				if ( 0 === $i ) {
					$classes[] = 'is-active';
				}
				if ( $is_highlighted ) {
					$classes[] = 'is-highlighted';
				}
				?>
				<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
					<p class="ecf-carousel__team-name">
						<?php echo esc_html( $slide['team'] ); ?>
						<?php if ( $multi_event ) : ?>
							<span class="ecf-carousel__event-name"><?php echo esc_html( $slide['event'] ); ?></span>
						<?php endif; ?>
					</p>

					<?php if ( $slide['error'] ) : ?>
						<p class="ecf-carousel__row">
							<span class="ecf-carousel__value"><?php echo esc_html( $slide['error'] ); ?></span>
						</p>
					<?php else : ?>
						<?php if ( $slide['last_result'] ) : ?>
							<?php $side = $describe_side( $slide['last_result'], $slide['team'] ); ?>
							<p class="ecf-carousel__row">
								<span class="ecf-carousel__label"><?php esc_html_e( 'Last result:', 'chess-army-knife' ); ?></span>
								<span class="ecf-carousel__value">
									<?php echo esc_html( $side['venue'] . ' v ' . $side['opponent'] ); ?>
									<?php if ( $show_location && '' !== $side['location'] ) : ?>
										<span class="ecf-carousel__location">@ <?php echo esc_html( $side['location'] ); ?></span>
									<?php endif; ?>
									<?php
									$score = ( '' !== $slide['last_result']['home_score'] || '' !== $slide['last_result']['away_score'] )
										? $slide['last_result']['home_score'] . ' – ' . $slide['last_result']['away_score']
										: $slide['last_result']['result_text'];
									if ( $score ) {
										echo ' (' . esc_html( $score ) . ')';
									}
									if ( '' !== $slide['last_result']['date'] ) {
										echo ' — ' . esc_html( $slide['last_result']['date'] );
									}
									?>
								</span>
							</p>
						<?php else : ?>
							<p class="ecf-carousel__row">
								<span class="ecf-carousel__label"><?php esc_html_e( 'Last result:', 'chess-army-knife' ); ?></span>
								<span class="ecf-carousel__value"><?php esc_html_e( 'None played yet', 'chess-army-knife' ); ?></span>
							</p>
						<?php endif; ?>

						<?php if ( $slide['next_fixture'] ) : ?>
							<?php $side = $describe_side( $slide['next_fixture'], $slide['team'] ); ?>
							<p class="ecf-carousel__row">
								<span class="ecf-carousel__label"><?php esc_html_e( 'Next fixture:', 'chess-army-knife' ); ?></span>
								<span class="ecf-carousel__value">
									<?php echo esc_html( $side['venue'] . ' v ' . $side['opponent'] ); ?>
									<?php if ( $show_location && '' !== $side['location'] ) : ?>
										<span class="ecf-carousel__location">@ <?php echo esc_html( $side['location'] ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== $slide['next_fixture']['date'] ) : ?>
										— <?php echo esc_html( $slide['next_fixture']['date'] ); ?>
									<?php endif; ?>
								</span>
							</p>
						<?php else : ?>
							<p class="ecf-carousel__row">
								<span class="ecf-carousel__label"><?php esc_html_e( 'Next fixture:', 'chess-army-knife' ); ?></span>
								<span class="ecf-carousel__value"><?php esc_html_e( 'None scheduled', 'chess-army-knife' ); ?></span>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( count( $slides ) > 1 ) : ?>
			<button type="button" class="ecf-carousel__nav ecf-carousel__prev" aria-label="<?php esc_attr_e( 'Previous team', 'chess-army-knife' ); ?>">‹</button>
			<button type="button" class="ecf-carousel__nav ecf-carousel__next" aria-label="<?php esc_attr_e( 'Next team', 'chess-army-knife' ); ?>">›</button>
			<div class="ecf-carousel__dots">
				<?php foreach ( $slides as $i => $slide ) : ?>
					<button
						type="button"
						class="ecf-carousel__dot <?php echo 0 === $i ? 'is-active' : ''; ?>"
						aria-label="<?php echo esc_attr( $slide['team'] ); ?>"
					></button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
