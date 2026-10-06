<?php
/**
 * Team Overview: one screen for a team's officers with everything about the team in one place: its
 * details, league entries, squad, next fixtures, and who has not replied to the availability request
 * for the next one. It only gathers what Teams, Selection and Events already know; nothing here is
 * stored or shown on the website.
 *
 * Captains who are not administrators see only their own team, as on the Team Selection screen.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Team_Overview {

	const PAGE = 'chess-army-knife-team-overview';

	/** Fixtures listed. */
	const FIXTURES = 5;

	/**
	 * How a squad answered an availability request.
	 *
	 * @param int[]   $squad_ids    Ids of the people asked.
	 * @param array[] $availability Rows by person id (see Chess_Army_Knife_Selection::availability()), each with a response.
	 * @return array { yes, maybe, no: counts; waiting: ids of people asked who have not replied (including those not yet asked) }
	 */
	public static function reply_counts( array $squad_ids, array $availability ) {
		$counts = array(
			'yes'     => 0,
			'maybe'   => 0,
			'no'      => 0,
			'waiting' => array(),
		);

		foreach ( $squad_ids as $person_id ) {
			$response = isset( $availability[ $person_id ]['response'] ) ? (string) $availability[ $person_id ]['response'] : '';
			if ( isset( $counts[ $response ] ) && 'waiting' !== $response ) {
				++$counts[ $response ];
			} else {
				$counts['waiting'][] = (int) $person_id;
			}
		}

		return $counts;
	}

	/**
	 * Draw the screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Captains::user_can_select(), __( 'Team Overview', 'chess-army-knife' ), 'selection' ) ) {
			return;
		}

		$teams = Chess_Army_Knife_Captains::teams_for_user();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Teams', 'chess-army-knife' ); ?></h1>
		<?php
		if ( ! $teams ) {
			Chess_Army_Knife_Team_Tabs::render( 0, 'overview' );
			echo '<p>' . esc_html__( 'There are no teams for you to look after yet.', 'chess-army-knife' ) . '</p></div>';
			return;
		}

		$wanted = isset( $_GET['team'] ) ? absint( $_GET['team'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only choice of what to show.
		$team   = $teams[0];
		foreach ( $teams as $candidate ) {
			if ( $candidate['id'] === $wanted ) {
				$team = $candidate;
			}
		}

		Chess_Army_Knife_Team_Tabs::render( $team['id'], 'overview' );

		if ( count( $teams ) > 1 ) :
			?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<label for="cak-overview-team"><?php esc_html_e( 'Team', 'chess-army-knife' ); ?></label>
				<select id="cak-overview-team" name="team">
					<?php foreach ( $teams as $option ) : ?>
						<option value="<?php echo (int) $option['id']; ?>" <?php selected( $team['id'], $option['id'] ); ?>><?php echo esc_html( $option['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Show', 'chess-army-knife' ); ?></button>
			</form>
			<?php
		endif;

		$captain = $team['captain_id'] ? Chess_Army_Knife_Membership_Store::get_member( $team['captain_id'] ) : null;
		?>
			<h2><?php echo esc_html( $team['name'] ); ?></h2>
			<table class="widefat striped" style="max-width:700px">
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'Captain', 'chess-army-knife' ); ?></th><td><?php echo $captain ? esc_html( $captain['name'] ) : ( '' !== $team['captain_name'] ? esc_html( $team['captain_name'] ) : '&mdash;' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Calendar tag', 'chess-army-knife' ); ?></th><td><?php echo '' !== $team['tag'] ? esc_html( $team['tag'] ) : '&mdash;'; ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Boards', 'chess-army-knife' ); ?></th><td><?php echo (int) Chess_Army_Knife_Captains::boards( $team['id'] ); ?></td></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Leagues', 'chess-army-knife' ); ?></th>
						<td>
							<?php if ( ! $team['leagues'] ) : ?>
								&mdash;
							<?php endif; ?>
							<?php foreach ( $team['leagues'] as $league ) : ?>
								<?php echo esc_html( $league['event'] . ' (LMS ' . $league['org'] . ')' ); ?><br />
							<?php endforeach; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php
			$pool     = Chess_Army_Knife_Selection::pool( $team['id'] );
			$pool_ids = wp_list_pluck( $pool, 'id' );
			$events   = Chess_Army_Knife_Events::query(
				array(
					'teams' => array( $team['id'] ),
					'limit' => self::FIXTURES,
				)
			);
			?>
			<h3><?php esc_html_e( 'Next fixtures', 'chess-army-knife' ); ?></h3>
			<?php if ( ! $events ) : ?>
				<p><?php esc_html_e( 'No fixtures are coming up. Run the League games import to fetch them.', 'chess-army-knife' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:700px">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When', 'chess-army-knife' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Fixture', 'chess-army-knife' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Replies', 'chess-army-knife' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $events as $index => $event ) : ?>
							<?php $counts = self::reply_counts( $pool_ids, Chess_Army_Knife_Selection::availability( $event['id'], $team['id'] ) ); ?>
							<tr>
								<td><?php echo esc_html( trim( Chess_Army_Knife_Events_Display::date_label( $event ) . ' ' . Chess_Army_Knife_Events_Display::start_time_label( $event ) ) ); ?></td>
								<td>
									<?php echo esc_html( $event['title'] ); ?>
									<?php $status = Chess_Army_Knife_Events_Display::status_text( $event ); ?>
									<?php if ( '' !== $status ) : ?>
										<strong>(<?php echo esc_html( $status ); ?>)</strong>
									<?php endif; ?>
									<br /><a href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'page'  => Chess_Army_Knife_Selection_Page::SLUG,
												'event' => $event['id'],
												'team'  => $team['id'],
											),
											admin_url( 'admin.php' )
										)
									);
									?>
													"><?php esc_html_e( 'Ask who can play, or pick the line-up', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $event['title'] ); ?></span></a>
								</td>
								<td>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: yes, 2: maybe, 3: no, 4: not replied */
											__( '%1$d yes, %2$d maybe, %3$d no, %4$d not replied', 'chess-army-knife' ),
											$counts['yes'],
											$counts['maybe'],
											$counts['no'],
											count( $counts['waiting'] )
										)
									);
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php
			if ( $events ) :
				$waiting = self::reply_counts( $pool_ids, Chess_Army_Knife_Selection::availability( $events[0]['id'], $team['id'] ) )['waiting'];
				?>
				<h3><?php esc_html_e( 'Not yet replied for the next fixture', 'chess-army-knife' ); ?></h3>
				<?php if ( ! $waiting ) : ?>
					<p><?php esc_html_e( 'Everyone in the squad has replied.', 'chess-army-knife' ); ?></p>
				<?php else : ?>
					<ul class="ul-disc">
						<?php foreach ( $pool as $person ) : ?>
							<?php if ( in_array( $person['id'], $waiting, true ) ) : ?>
								<li><?php echo esc_html( $person['name'] ); ?></li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Squad', 'chess-army-knife' ); ?></h3>
			<?php if ( ! $pool ) : ?>
				<p><?php esc_html_e( 'There is nobody in the squad who is a current member.', 'chess-army-knife' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:700px">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Rating', 'chess-army-knife' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pool as $person ) : ?>
							<?php $rating = Chess_Army_Knife_Selection::rating_of( $person ); ?>
							<tr>
								<td><?php echo esc_html( $person['name'] ); ?></td>
								<td><?php echo $rating ? esc_html( $rating ) : '&mdash;'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( Chess_Army_Knife_Teams::user_can_manage() ) : ?>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Chess_Army_Knife_Squad_Review::PAGE ) ); ?>"><?php esc_html_e( 'Tidy the squads: who has not played lately', 'chess-army-knife' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
