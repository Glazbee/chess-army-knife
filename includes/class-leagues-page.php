<?php
/**
 * The Leagues tab of Teams: the club's league entries by LMS organisation. For each organisation it lists
 * which of the club's teams play in which division, and can check each entry against the LMS.
 *
 * The entries are stored on the teams (see Chess_Army_Knife_Teams::META_LEAGUES); this page is another
 * way to edit them, organisation by organisation, so a whole league can be set up in one place.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Leagues_Page {

	const PAGE   = 'chess-army-knife-leagues';
	const ACTION = 'chess_army_knife_leagues_save';

	/**
	 * Hook up the save and the script.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Load the script that adds rows, on this page only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_script( 'chess-army-knife-leagues', Chess_Army_Knife_URL . 'assets/leagues.js', array(), Chess_Army_Knife_VERSION, true );
	}

	/**
	 * The new league entries of each team after a save.
	 *
	 * The page shows every organisation the club has entries in, so for an organisation that was shown, the
	 * rows submitted replace the team's entries there; entries in an organisation that was not shown stay.
	 *
	 * @param array[] $current Each team's entries now, by team id: lists of { org, event, name }.
	 * @param string[] $orgs   The organisations the page showed.
	 * @param array[]  $rows   Rows submitted, each { org, team_id, event, name }.
	 * @return array[] Each team's new entries, by team id, cleaned.
	 */
	public static function merge( array $current, array $orgs, array $rows ) {
		$result = array();
		foreach ( $current as $team_id => $leagues ) {
			$result[ $team_id ] = array_values(
				array_filter(
					$leagues,
					function ( $league ) use ( $orgs ) {
						return ! in_array( (string) $league['org'], $orgs, true );
					}
				)
			);
		}

		foreach ( $rows as $row ) {
			$team_id = isset( $row['team_id'] ) ? absint( $row['team_id'] ) : 0;
			$org     = isset( $row['org'] ) ? preg_replace( '/[^0-9]/', '', (string) $row['org'] ) : '';
			if ( ! isset( $result[ $team_id ] ) || ! in_array( $org, $orgs, true ) ) {
				continue;
			}
			$result[ $team_id ][] = array(
				'org'   => $org,
				'event' => isset( $row['event'] ) ? (string) $row['event'] : '',
				'name'  => isset( $row['name'] ) ? (string) $row['name'] : '',
			);
		}

		return array_map( array( 'Chess_Army_Knife_Teams', 'clean_leagues' ), $result );
	}

	/**
	 * The club's league entries by organisation.
	 *
	 * @return array[] Each organisation's rows { team_id, event, name }, by organisation, lowest first.
	 */
	public static function entries_by_org() {
		$by_org = array();
		foreach ( Chess_Army_Knife_Teams::all() as $team ) {
			foreach ( $team['leagues'] as $league ) {
				$by_org[ $league['org'] ][] = array(
					'team_id' => $team['id'],
					'event'   => $league['event'],
					'name'    => $league['name'],
				);
			}
		}
		ksort( $by_org, SORT_NUMERIC );
		return $by_org;
	}

	/**
	 * Save what the page submitted. Returns how many teams' entries changed.
	 *
	 * @param array $post Unslashed form values.
	 * @return array { orgs: string[] shown, changed: int }
	 */
	public static function save_from( array $post ) {
		$orgs = array();
		foreach ( isset( $post['orgs'] ) ? (array) $post['orgs'] : array() as $org ) {
			$org = preg_replace( '/[^0-9]/', '', (string) $org );
			if ( '' !== $org ) {
				$orgs[ $org ] = $org;
			}
		}

		$rows = array();
		foreach ( isset( $post['leagues'] ) && is_array( $post['leagues'] ) ? $post['leagues'] : array() as $org => $list ) {
			foreach ( is_array( $list ) ? $list : array() as $row ) {
				if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
					continue;
				}
				$rows[] = array(
					'org'     => (string) $org,
					'team_id' => isset( $row['team'] ) ? absint( $row['team'] ) : 0,
					'event'   => isset( $row['event'] ) ? sanitize_text_field( (string) $row['event'] ) : '',
					'name'    => isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '',
				);
			}
		}

		$current = array();
		foreach ( Chess_Army_Knife_Teams::all() as $team ) {
			$current[ $team['id'] ] = $team['leagues'];
		}

		$changed = 0;
		foreach ( self::merge( $current, array_values( $orgs ), $rows ) as $team_id => $leagues ) {
			if ( $leagues !== $current[ $team_id ] ) {
				update_post_meta( $team_id, Chess_Army_Knife_Teams::META_LEAGUES, $leagues );
				++$changed;
			}
		}

		return array(
			'orgs'    => array_values( $orgs ),
			'changed' => $changed,
		);
	}

	/**
	 * Handle the form: save, then show the page again, checking against the LMS if asked.
	 */
	public static function handle_save() {
		if ( ! Chess_Army_Knife_Teams::user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is cleaned in save_from().
		$post = wp_unslash( $_POST );
		$done = self::save_from( $post );

		$new_org = isset( $post['new_org'] ) ? preg_replace( '/[^0-9]/', '', (string) $post['new_org'] ) : '';
		$args    = array(
			'page'    => self::PAGE,
			'saved'   => $done['changed'],
			'add_org' => $new_org,
			'check'   => ! empty( $post['check'] ) ? 1 : 0,
		);
		wp_safe_redirect( add_query_arg( array_filter( $args, 'strlen' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Check one entry against the LMS.
	 *
	 * @param string $org      Organisation id.
	 * @param string $event    Event / division name.
	 * @param string $name     The name the LMS knows the team by.
	 * @param array  $memo     Events already loaded this request, by organisation and event (kept between calls).
	 * @return array { ok: bool, message: string }
	 */
	public static function check_entry( $org, $event, $name, array &$memo ) {
		$key = $org . '|' . strtolower( $event );
		if ( ! isset( $memo[ $key ] ) ) {
			$memo[ $key ] = Chess_Army_Knife_League_Data::load( $org, $event );
		}
		$loaded = $memo[ $key ];

		if ( $loaded['error'] ) {
			return array(
				'ok'      => false,
				'message' => $loaded['error']->get_error_message(),
			);
		}

		$found = Chess_Army_Knife_League_Data::lms_name( $loaded['fixtures'], $name );
		if ( '' === $found ) {
			return array(
				'ok'      => false,
				'message' => __( 'The division is in the LMS, but no team with this name is in it yet. The LMS may not have its fixtures, or the name needs setting under "Name in the LMS".', 'chess-army-knife' ),
			);
		}

		return array(
			'ok'      => true,
			/* translators: %s: the team's name in the LMS */
			'message' => sprintf( __( 'Found in the LMS as "%s".', 'chess-army-knife' ), $found ),
		);
	}

	/**
	 * One editable row of an organisation's table.
	 *
	 * @param string $org    Organisation id.
	 * @param string $index  Row key.
	 * @param array  $row    { team_id, event, name }.
	 * @param array  $status Result of check_entry(), or empty.
	 * @param string $list   Id of the datalist of this organisation's divisions.
	 */
	protected static function render_row( $org, $index, array $row, array $status, $list ) {
		$base = 'leagues[' . $org . '][' . $index . ']';
		?>
		<tr class="cak-league-row">
			<td>
				<label class="screen-reader-text" for="cak-league-team-<?php echo esc_attr( $org . '-' . $index ); ?>"><?php esc_html_e( 'Team', 'chess-army-knife' ); ?></label>
				<select id="cak-league-team-<?php echo esc_attr( $org . '-' . $index ); ?>" name="<?php echo esc_attr( $base ); ?>[team]">
					<option value="0"><?php esc_html_e( 'Choose a team…', 'chess-army-knife' ); ?></option>
					<?php foreach ( Chess_Army_Knife_Teams::all() as $team ) : ?>
						<option value="<?php echo esc_attr( $team['id'] ); ?>" <?php selected( $row['team_id'], $team['id'] ); ?>><?php echo esc_html( $team['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<label class="screen-reader-text" for="cak-league-event-<?php echo esc_attr( $org . '-' . $index ); ?>"><?php esc_html_e( 'Division', 'chess-army-knife' ); ?></label>
				<input type="text" id="cak-league-event-<?php echo esc_attr( $org . '-' . $index ); ?>" name="<?php echo esc_attr( $base ); ?>[event]" value="<?php echo esc_attr( $row['event'] ); ?>" class="regular-text" list="<?php echo esc_attr( $list ); ?>" placeholder="Division 1" />
			</td>
			<td>
				<label class="screen-reader-text" for="cak-league-name-<?php echo esc_attr( $org . '-' . $index ); ?>"><?php esc_html_e( 'Name in the LMS, if different', 'chess-army-knife' ); ?></label>
				<input type="text" id="cak-league-name-<?php echo esc_attr( $org . '-' . $index ); ?>" name="<?php echo esc_attr( $base ); ?>[name]" value="<?php echo esc_attr( $row['name'] ); ?>" class="regular-text" />
			</td>
			<td>
				<?php if ( $status ) : ?>
					<strong><?php echo esc_html( $status['ok'] ? __( 'Found', 'chess-army-knife' ) : __( 'Not found', 'chess-army-knife' ) ); ?></strong>
					<br /><span class="description"><?php echo esc_html( $status['message'] ); ?></span>
				<?php else : ?>
					&mdash;
				<?php endif; ?>
			</td>
			<td>
				<label><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[remove]" value="1" /> <?php esc_html_e( 'Remove', 'chess-army-knife' ); ?></label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Draw the page.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Teams::user_can_manage(), __( 'Leagues', 'chess-army-knife' ), 'teams' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen state; nothing is changed.
		$check = ! empty( $_GET['check'] );
		$added = isset( $_GET['add_org'] ) ? preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_GET['add_org'] ) ) ) : '';
		$saved = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$by_org = self::entries_by_org();
		if ( '' !== $added && ! isset( $by_org[ $added ] ) ) {
			$by_org[ $added ] = array();
			ksort( $by_org, SORT_NUMERIC );
		}
		$memo = array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Teams', 'chess-army-knife' ); ?></h1>
			<?php Chess_Army_Knife_Team_Tabs::render_sections( 'leagues' ); ?>
			<?php if ( null !== $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Leagues saved.', 'chess-army-knife' ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'For each LMS organisation, say which of the club\'s teams play in which division. Import Events, the fixtures carousel and league table highlighting all read these entries. A team can play in more than one division, and in more than one organisation.', 'chess-army-knife' ); ?></p>
			<?php if ( ! Chess_Army_Knife_Teams::all() ) : ?>
				<p><?php esc_html_e( 'There are no teams yet. Add teams on the Teams tab first.', 'chess-army-knife' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>

				<?php foreach ( $by_org as $org => $rows ) : ?>
					<?php
					$org    = (string) $org;
					$list   = 'cak-divisions-' . $org;
					$events = Chess_Army_Knife_LMS_Client::get_event_names( $org );
					$rows[] = array(
						'team_id' => 0,
						'event'   => '',
						'name'    => '',
					); // One blank row to add to.
					?>
					<input type="hidden" name="orgs[]" value="<?php echo esc_attr( $org ); ?>" />
					<h2>
						<?php
						/* translators: %s: LMS organisation id */
						echo esc_html( sprintf( __( 'LMS organisation %s', 'chess-army-knife' ), $org ) );
						?>
					</h2>
					<?php if ( ! is_wp_error( $events ) && $events ) : ?>
						<datalist id="<?php echo esc_attr( $list ); ?>">
							<?php foreach ( $events as $event_name ) : ?>
								<option value="<?php echo esc_attr( $event_name ); ?>"></option>
							<?php endforeach; ?>
						</datalist>
					<?php endif; ?>
					<table class="widefat striped cak-league-table" style="max-width:1100px">
						<caption class="screen-reader-text">
							<?php
							/* translators: %s: LMS organisation id */
							echo esc_html( sprintf( __( 'Teams in LMS organisation %s', 'chess-army-knife' ), $org ) );
							?>
						</caption>
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Team', 'chess-army-knife' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Division', 'chess-army-knife' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Name in the LMS, if different', 'chess-army-knife' ); ?></th>
								<th scope="col"><?php esc_html_e( 'LMS check', 'chess-army-knife' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'chess-army-knife' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $index => $row ) : ?>
								<?php
								$status = array();
								if ( $check && $row['team_id'] && '' !== $row['event'] ) {
									$team   = Chess_Army_Knife_Teams::get( $row['team_id'] );
									$status = $team ? self::check_entry( $org, $row['event'], '' !== $row['name'] ? $row['name'] : $team['name'], $memo ) : array();
								}
								self::render_row( $org, (string) $index, $row, $status, $list );
								?>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<button type="button" class="button cak-league-add" data-next="<?php echo esc_attr( count( $rows ) ); ?>"><?php esc_html_e( 'Add another team', 'chess-army-knife' ); ?></button>
					</p>
				<?php endforeach; ?>

				<?php if ( ! $by_org ) : ?>
					<p><?php esc_html_e( 'No league entries yet. Add an LMS organisation to start.', 'chess-army-knife' ); ?></p>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Add an organisation', 'chess-army-knife' ); ?></h2>
				<p>
					<label for="cak-new-org"><?php esc_html_e( 'LMS organisation ID', 'chess-army-knife' ); ?></label>
					<input type="text" id="cak-new-org" name="new_org" class="small-text" inputmode="numeric" placeholder="270" />
				</p>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save leagues', 'chess-army-knife' ); ?></button>
					<button type="submit" class="button" name="check" value="1"><?php esc_html_e( 'Save and check with the LMS', 'chess-army-knife' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'Checking looks each entry up in the LMS (results are kept for a while, so repeated checks are quick). It needs the LMS API key under Settings.', 'chess-army-knife' ); ?></p>
			</form>
		</div>
		<?php
	}
}

Chess_Army_Knife_Leagues_Page::init();
