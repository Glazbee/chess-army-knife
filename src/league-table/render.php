<?php
/**
 * Server-side render for the ECF League Standings & Matchups block.
 *
 * Supports more than one event under the same organisation (e.g. a club
 * with teams in Division 1, 2, 3 and 4 of the same league) - the event
 * field accepts one event name per line, and each gets its own table +
 * matchups section.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its settings override this block's own.
$attributes = Chess_Army_Knife_Templates::apply( 'league-table', $attributes );

$org_id         = trim( (string) Chess_Army_Knife_Settings::resolve( 'default_org_id', $attributes['orgId'] ?? '' ) );
$event_field    = trim( (string) Chess_Army_Knife_Settings::resolve( 'default_event_name', $attributes['eventName'] ?? '' ) );
$display_mode   = isset( $attributes['displayMode'] ) ? $attributes['displayMode'] : 'both';
$max_matches    = isset( $attributes['maxMatches'] ) ? max( 1, (int) $attributes['maxMatches'] ) : 6;
$block_title    = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$highlight_team = isset( $attributes['highlightTeam'] ) ? trim( (string) $attributes['highlightTeam'] ) : '';
$debug          = ! empty( $attributes['debug'] );
$show_location  = ! empty( $attributes['showLocation'] );

$show_table   = in_array( $display_mode, array( 'both', 'table' ), true );
$show_matches = in_array( $display_mode, array( 'both', 'matches' ), true );

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'league-table', $attributes );

// One event name per line (or comma-separated) - lets one block cover
// every division a club has teams in under the same organisation.
$event_names = array();
foreach ( preg_split( '/[\r\n,]+/', $event_field ) as $line ) {
	$line = trim( $line );
	if ( '' !== $line ) {
		$event_names[] = $line;
	}
}
$event_names = array_values( array_unique( $event_names ) );

if ( '' === $org_id || empty( $event_names ) ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'ECF League Standings & Matchups: enter an LMS organisation ID and at least one event name in the block settings.', 'chess-army-knife' )
	);
	return;
}

$heading = $block_title ? $block_title : ( 1 === count( $event_names ) ? $event_names[0] : sprintf(
	/* translators: %d: number of events/divisions */
	_n( '%d division', '%d divisions', count( $event_names ), 'chess-army-knife' ),
	count( $event_names )
) );

/**
 * Whether a team name matches the "highlight" attribute (case-insensitive,
 * substring match so "Ecclesall A" matches a highlight of "Ecclesall").
 *
 * @param string $team Team name.
 * @return bool
 */
$club_teams = array_filter(
	Chess_Army_Knife_Settings::get_club_teams(),
	function ( $t ) use ( $org_id ) {
		return (string) $t['org'] === (string) $org_id;
	}
);

$is_highlighted = function ( $team, $event = '' ) use ( $highlight_team, $club_teams ) {
	if ( '' === $team ) {
		return false;
	}
	if ( '' !== $highlight_team && false !== stripos( $team, $highlight_team ) ) {
		return true;
	}
	// Automatically highlight any team with a league entry under Teams
	// for this organisation (and this event, when known).
	foreach ( $club_teams as $t ) {
		if ( '' !== $event && 0 !== strcasecmp( $t['event'], $event ) ) {
			continue;
		}
		if ( 0 === strcasecmp( $t['team'], $team ) ) {
			return true;
		}
	}
	return false;
};

/**
 * Render whatever diagnostic detail is available for a failed request:
 * the friendly message, plus the raw HTTP method/URL/status/body if the
 * client captured them - so "no data" doesn't also mean "no clues".
 *
 * @param WP_Error $error The error returned by the LMS client.
 */
$render_debug_error = function ( $error ) {
	$data = $error->get_error_data();
	echo '<p>' . esc_html( $error->get_error_message() ) . '</p>';
	if ( is_array( $data ) && isset( $data['status'] ) ) {
		printf(
			'<p>%1$s %2$s → HTTP %3$s</p>',
			esc_html( $data['method'] ?? '' ),
			esc_html( $data['url'] ?? '' ),
			esc_html( $data['status'] )
		);
		if ( ! empty( $data['body'] ) ) {
			echo '<pre>' . esc_html( $data['body'] ) . '</pre>';
		}
	}
};

// Fetch everything up front, one event at a time.
$events = array();
foreach ( $event_names as $event_name ) {
	$entry = array(
		'name'        => $event_name,
		'table_raw'   => null,
		'table_rows'  => array(),
		'table_error' => null,
		'match_raw'   => null,
		'match_rows'  => array(),
		'match_error' => null,
	);

	if ( $show_table ) {
		$entry['table_raw'] = Chess_Army_Knife_LMS_Client::get_table( $org_id, $event_name );
		if ( is_wp_error( $entry['table_raw'] ) ) {
			$entry['table_error'] = $entry['table_raw'];
		} else {
			foreach ( Chess_Army_Knife_LMS_Client::find_rows( $entry['table_raw'], array( 'table' ) ) as $raw_row ) {
				$normalised = Chess_Army_Knife_LMS_Client::normalise_table_row( $raw_row );
				if ( $normalised && '' !== $normalised['team'] ) {
					$entry['table_rows'][] = $normalised;
				}
			}
		}
	}

	if ( $show_matches ) {
		$entry['match_raw'] = Chess_Army_Knife_LMS_Client::get_matches( $org_id, $event_name );
		if ( is_wp_error( $entry['match_raw'] ) ) {
			$entry['match_error'] = $entry['match_raw'];
		} else {
			foreach ( Chess_Army_Knife_LMS_Client::find_rows( $entry['match_raw'], array( 'matches' ) ) as $raw_row ) {
				$normalised = Chess_Army_Knife_LMS_Client::normalise_match_row( $raw_row );
				if ( $normalised && ( '' !== $normalised['home'] || '' !== $normalised['away'] ) ) {
					$entry['match_rows'][] = $normalised;
				}
			}
			usort(
				$entry['match_rows'],
				function ( $a, $b ) {
					return strcmp( $b['date'], $a['date'] );
				}
			);
			$entry['match_rows'] = array_slice( $entry['match_rows'], 0, $max_matches );
		}
	}

	$events[] = $entry;
}

$admin_cache_keys = array();
foreach ( $event_names as $event_name ) {
	if ( $show_table ) {
		$admin_cache_keys[] = Chess_Army_Knife_LMS_Client::cache_key( 'table', $org_id, $event_name );
	}
	if ( $show_matches ) {
		$admin_cache_keys[] = Chess_Army_Knife_LMS_Client::cache_key( 'match', $org_id, $event_name );
	}
}
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<p class="ecf-league__title"><?php echo esc_html( $heading ); ?></p>

	<?php echo Chess_Army_Knife_Admin_Refresh::bar( $admin_cache_keys, __( 'League data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Admin_Refresh::bar(). ?>

	<?php foreach ( $events as $event ) : ?>
		<?php if ( count( $events ) > 1 ) : ?>
			<h3 class="ecf-league__event-heading"><?php echo esc_html( $event['name'] ); ?></h3>
		<?php endif; ?>

		<?php if ( $show_table ) : ?>
			<?php if ( $event['table_error'] ) : ?>
				<div class="chess-army-knife-notice">
					<?php esc_html_e( 'Could not load the league table:', 'chess-army-knife' ); ?> <?php echo esc_html( $event['table_error']->get_error_message() ); ?>
				</div>
			<?php elseif ( empty( $event['table_rows'] ) ) : ?>
				<div class="chess-army-knife-empty"><?php esc_html_e( 'No table rows were found for this event.', 'chess-army-knife' ); ?></div>
			<?php else : ?>
				<table class="ecf-league__table">
					<thead>
						<tr>
							<th class="is-numeric">#</th>
							<th><?php esc_html_e( 'Team', 'chess-army-knife' ); ?></th>
							<th class="is-numeric"><?php esc_html_e( 'P', 'chess-army-knife' ); ?></th>
							<th class="is-numeric"><?php esc_html_e( 'W', 'chess-army-knife' ); ?></th>
							<th class="is-numeric"><?php esc_html_e( 'D', 'chess-army-knife' ); ?></th>
							<th class="is-numeric"><?php esc_html_e( 'L', 'chess-army-knife' ); ?></th>
							<th class="is-numeric"><?php esc_html_e( 'Pts', 'chess-army-knife' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $event['table_rows'] as $i => $row ) : ?>
							<tr class="<?php echo $is_highlighted( $row['team'], $event['name'] ) ? 'is-highlighted' : ''; ?>">
								<td class="is-numeric"><?php echo esc_html( '' !== $row['position'] ? $row['position'] : ( $i + 1 ) ); ?></td>
								<td><?php echo esc_html( $row['team'] ); ?></td>
								<td class="is-numeric"><?php echo esc_html( $row['played'] ); ?></td>
								<td class="is-numeric"><?php echo esc_html( $row['won'] ); ?></td>
								<td class="is-numeric"><?php echo esc_html( $row['drawn'] ); ?></td>
								<td class="is-numeric"><?php echo esc_html( $row['lost'] ); ?></td>
								<td class="is-numeric"><?php echo esc_html( $row['points'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $show_matches ) : ?>
			<p class="ecf-league__subheading"><?php esc_html_e( 'Matchups', 'chess-army-knife' ); ?></p>
			<?php if ( $event['match_error'] ) : ?>
				<div class="chess-army-knife-notice">
					<?php esc_html_e( 'Could not load matchups:', 'chess-army-knife' ); ?> <?php echo esc_html( $event['match_error']->get_error_message() ); ?>
				</div>
			<?php elseif ( empty( $event['match_rows'] ) ) : ?>
				<div class="chess-army-knife-empty"><?php esc_html_e( 'No matchups were found for this event.', 'chess-army-knife' ); ?></div>
			<?php else : ?>
				<ul class="ecf-league__matches">
					<?php foreach ( $event['match_rows'] as $row ) : ?>
						<?php
						$highlighted = $is_highlighted( $row['home'], $event['name'] ) || $is_highlighted( $row['away'], $event['name'] );
						$has_score   = '' !== $row['home_score'] || '' !== $row['away_score'];
						?>
						<li>
							<?php if ( '' !== $row['date'] ) : ?>
								<span class="ecf-league__match-date"><?php echo esc_html( $row['date'] ); ?></span>
							<?php endif; ?>
							<span class="ecf-league__match-teams <?php echo $highlighted ? 'is-highlighted' : ''; ?>">
								<?php echo esc_html( $row['home'] ); ?> v <?php echo esc_html( $row['away'] ); ?>
							</span>
							<?php if ( $show_location && '' !== $row['venue'] ) : ?>
								<span class=\"ecf-league__match-venue\"><?php echo esc_html( $row['venue'] ); ?></span>
							<?php endif; ?>
							<span class="ecf-league__match-score">
								<?php
								if ( $has_score ) {
									echo esc_html( $row['home_score'] . ' – ' . $row['away_score'] );
								} elseif ( '' !== $row['result_text'] ) {
									echo esc_html( $row['result_text'] );
								} else {
									esc_html_e( 'TBC', 'chess-army-knife' );
								}
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php endif; ?>
	<?php endforeach; ?>

	<?php if ( $debug ) : ?>
		<details class="ecf-league__debug">
			<summary><?php esc_html_e( 'Raw LMS API data (debug)', 'chess-army-knife' ); ?></summary>
			<?php foreach ( $events as $event ) : ?>
				<p><strong><?php echo esc_html( $event['name'] ); ?> — table.json</strong></p>
				<?php if ( $show_table ) : ?>
					<?php if ( $event['table_error'] ) : ?>
						<?php $render_debug_error( $event['table_error'] ); ?>
					<?php else : ?>
						<pre><?php echo esc_html( wp_json_encode( $event['table_raw'], JSON_PRETTY_PRINT ) ); ?></pre>
					<?php endif; ?>
				<?php endif; ?>

				<p><strong><?php echo esc_html( $event['name'] ); ?> — match.json</strong></p>
				<?php if ( $show_matches ) : ?>
					<?php if ( $event['match_error'] ) : ?>
						<?php $render_debug_error( $event['match_error'] ); ?>
					<?php else : ?>
						<pre><?php echo esc_html( wp_json_encode( $event['match_raw'], JSON_PRETTY_PRINT ) ); ?></pre>
					<?php endif; ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</details>
	<?php endif; ?>
</div>
