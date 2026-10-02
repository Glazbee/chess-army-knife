<?php
/**
 * Server-side render for the ECF League Standings & Matchups block.
 *
 * Reads the LMS v2 API: the table is worked out from the results (see
 * Chess_Army_Knife_League_Data), and a season can be chosen to show an earlier one.
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
$event_field    = trim( (string) ( $attributes['eventName'] ?? '' ) );
$display_mode   = isset( $attributes['displayMode'] ) ? $attributes['displayMode'] : 'both';
$max_matches    = isset( $attributes['maxMatches'] ) ? max( 1, (int) $attributes['maxMatches'] ) : 6;
$block_title    = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$highlight_team = isset( $attributes['highlightTeam'] ) ? trim( (string) $attributes['highlightTeam'] ) : '';
$debug          = ! empty( $attributes['debug'] );
$season         = isset( $attributes['season'] ) ? trim( (string) $attributes['season'] ) : '';

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
$today            = current_time( 'Y-m-d' );
$events           = array();
$admin_cache_keys = array();
foreach ( $event_names as $event_name ) {
	$loaded = Chess_Army_Knife_League_Data::load( $org_id, $event_name, $season );

	$events[] = array(
		'name'       => $event_name,
		'error'      => $loaded['error'],
		'fixtures'   => $loaded['fixtures'],
		'table_rows' => Chess_Army_Knife_League_Data::standings( $loaded['fixtures'] ),
		'match_rows' => Chess_Army_Knife_League_Data::matchups( $loaded['fixtures'], $max_matches, $today ),
	);

	$admin_cache_keys = array_merge( $admin_cache_keys, $loaded['cache_keys'] );
}
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'ecf-league__title', $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php echo Chess_Army_Knife_Admin_Refresh::bar( $admin_cache_keys, __( 'League data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Admin_Refresh::bar(). ?>

	<?php foreach ( $events as $event ) : ?>
		<?php if ( count( $events ) > 1 ) : ?>
			<?php echo Chess_Army_Knife_A11y::heading( 1, 'ecf-league__event-heading', $event['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
		<?php endif; ?>

		<?php if ( $show_table ) : ?>
			<?php if ( $event['error'] ) : ?>
				<div class="chess-army-knife-notice">
					<?php esc_html_e( 'Could not load the league table:', 'chess-army-knife' ); ?> <?php echo esc_html( $event['error']->get_error_message() ); ?>
				</div>
			<?php elseif ( empty( $event['table_rows'] ) ) : ?>
				<div class="chess-army-knife-empty"><?php esc_html_e( 'No table rows were found for this event.', 'chess-army-knife' ); ?></div>
			<?php else : ?>
				<div class="cak-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: event or division name */ __( 'League table: %s', 'chess-army-knife' ), $event['name'] ) ); ?>">
				<table class="ecf-league__table">
					<caption class="cak-visually-hidden"><?php echo esc_html( sprintf( /* translators: %s: event or division name */ __( 'League table: %s', 'chess-army-knife' ), $event['name'] ) ); ?></caption>
					<thead>
						<tr>
							<th scope="col" class="is-numeric"><?php echo Chess_Army_Knife_A11y::abbr( '#', __( 'Position', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
							<th scope="col"><?php esc_html_e( 'Team', 'chess-army-knife' ); ?></th>
							<th scope="col" class="is-numeric"><?php echo Chess_Army_Knife_A11y::abbr( __( 'P', 'chess-army-knife' ), __( 'Played', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
							<th scope="col" class="is-numeric"><?php echo Chess_Army_Knife_A11y::abbr( __( 'W', 'chess-army-knife' ), __( 'Won', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
							<th scope="col" class="is-numeric"><?php echo Chess_Army_Knife_A11y::abbr( __( 'D', 'chess-army-knife' ), __( 'Drawn', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
							<th scope="col" class="is-numeric"><?php echo Chess_Army_Knife_A11y::abbr( __( 'L', 'chess-army-knife' ), __( 'Lost', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
							<th scope="col" class="is-numeric"><?php echo Chess_Army_Knife_A11y::abbr( __( 'F', 'chess-army-knife' ), __( 'Board points for', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
							<th scope="col" class="is-numeric"><?php echo Chess_Army_Knife_A11y::abbr( __( 'A', 'chess-army-knife' ), __( 'Board points against', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
							<th scope="col" class="is-numeric"><?php echo Chess_Army_Knife_A11y::abbr( __( 'Pts', 'chess-army-knife' ), __( 'Match points', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in abbr(). ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $event['table_rows'] as $row ) : ?>
							<tr class="<?php echo $is_highlighted( $row['team'], $event['name'] ) ? 'is-highlighted' : ''; ?>">
								<td class="is-numeric"><?php echo esc_html( $row['position'] ); ?></td>
								<th scope="row">
									<?php echo esc_html( $row['team'] ); ?>
									<?php
									if ( $is_highlighted( $row['team'], $event['name'] ) ) {
										echo Chess_Army_Knife_A11y::hidden( __( '(our team)', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden().
									}
									?>
								</th>
								<td class="is-numeric"><?php echo esc_html( $row['played'] ); ?></td>
								<td class="is-numeric"><?php echo esc_html( $row['won'] ); ?></td>
								<td class="is-numeric"><?php echo esc_html( $row['drawn'] ); ?></td>
								<td class="is-numeric"><?php echo esc_html( $row['lost'] ); ?></td>
								<td class="is-numeric"><?php echo esc_html( Chess_Army_Knife_LMS_Client::format_score( $row['for'] ) ); ?></td>
								<td class="is-numeric"><?php echo esc_html( Chess_Army_Knife_LMS_Client::format_score( $row['against'] ) ); ?></td>
								<td class="is-numeric"><?php echo esc_html( Chess_Army_Knife_LMS_Client::format_score( $row['points'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p class="ecf-league__legend"><?php esc_html_e( 'P played, W won, D drawn, L lost, F and A board points for and against, Pts match points (1 for a win, ½ for a draw).', 'chess-army-knife' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $show_matches ) : ?>
			<?php echo Chess_Army_Knife_A11y::heading( count( $events ) > 1 ? 2 : 1, 'ecf-league__subheading', __( 'Matchups', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
			<?php if ( $event['error'] ) : ?>
				<div class="chess-army-knife-notice">
					<?php esc_html_e( 'Could not load matchups:', 'chess-army-knife' ); ?> <?php echo esc_html( $event['error']->get_error_message() ); ?>
				</div>
			<?php elseif ( empty( $event['match_rows'] ) ) : ?>
				<div class="chess-army-knife-empty"><?php esc_html_e( 'No matchups were found for this event.', 'chess-army-knife' ); ?></div>
			<?php else : ?>
				<ul class="ecf-league__matches">
					<?php foreach ( $event['match_rows'] as $row ) : ?>
						<?php
						$highlighted = $is_highlighted( $row['home'], $event['name'] ) || $is_highlighted( $row['away'], $event['name'] );
						$has_score   = Chess_Army_Knife_League_Data::is_played( $row );
						?>
						<li>
							<?php if ( '' !== $row['date'] ) : ?>
								<span class="ecf-league__match-date"><?php echo Chess_Army_Knife_A11y::date( $row['date'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in date(). ?></span>
							<?php endif; ?>
							<span class="ecf-league__match-teams <?php echo $highlighted ? 'is-highlighted' : ''; ?>">
								<?php echo esc_html( $row['home'] ); ?> <?php echo esc_html_x( 'against', 'Home team against away team', 'chess-army-knife' ); ?> <?php echo esc_html( $row['away'] ); ?>
								<?php
								if ( $highlighted ) {
									echo Chess_Army_Knife_A11y::hidden( __( '(our team)', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden().
								}
								?>
							</span>
							<span class="ecf-league__match-score">
								<?php
								if ( $has_score ) {
									echo Chess_Army_Knife_A11y::score( $row['home_score'], $row['away_score'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in score().
								} elseif ( '' !== $row['time'] ) {
									echo esc_html( $row['time'] );
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
			<summary><?php esc_html_e( 'LMS data as read (debug)', 'chess-army-knife' ); ?></summary>
			<?php foreach ( $events as $event ) : ?>
				<p><strong><?php echo esc_html( $event['name'] ); ?></strong></p>
				<?php if ( $event['error'] ) : ?>
					<?php $render_debug_error( $event['error'] ); ?>
				<?php else : ?>
					<pre><?php echo esc_html( wp_json_encode( $event['fixtures'], JSON_PRETTY_PRINT ) ); ?></pre>
				<?php endif; ?>
			<?php endforeach; ?>
		</details>
	<?php endif; ?>
</div>
