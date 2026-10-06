<?php
/**
 * The Leagues tab of Teams: the club's league entries by LMS organisation. For each organisation the divisions
 * are listed as the LMS has them, and the club's teams are dragged into the division they play in (or moved
 * up and down with buttons). A team plays in one division of an organisation.
 *
 * The entries are stored on the teams (see Chess_Army_Knife_Teams::META_LEAGUES); this page is another way to
 * edit them, so a whole league can be set up in one place. Where a team's name is not one the LMS has, the page
 * asks which LMS team is meant, from the teams in that division, rather than taking a typed name.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Leagues_Page {

	const PAGE      = 'chess-army-knife-leagues';
	const ACTION    = 'chess_army_knife_leagues_save';
	const ORG_NAMES = 'Chess_Army_Knife_lms_org_names'; // Names the admin gave organisations the LMS did not name.

	/**
	 * Hook up the save and the script.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Load the script that moves teams between divisions, on this page only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_script( 'chess-army-knife-leagues', Chess_Army_Knife_URL . 'assets/leagues.js', array( 'jquery-ui-sortable', 'jquery-touch-punch' ), Chess_Army_Knife_VERSION, true );
	}

	/**
	 * The new league entries of each team after a save.
	 *
	 * The page shows every organisation the club has entries in, so for an organisation that was shown, the
	 * rows submitted replace the team's entries there; entries in an organisation that was not shown stay.
	 *
	 * @param array[]  $current Each team's entries now, by team id: lists of { org, event, name }.
	 * @param string[] $orgs    The organisations the page showed.
	 * @param array[]  $rows    Rows submitted, each { org, team_id, event, name }.
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
	 * The club's league entries by organisation: the division each team plays in there. A team that has more
	 * than one entry in an organisation shows its first.
	 *
	 * @return array[] By organisation (lowest first), then team id: { event, name }.
	 */
	public static function entries_by_org() {
		$by_org = array();
		foreach ( Chess_Army_Knife_Teams::all() as $team ) {
			foreach ( $team['leagues'] as $league ) {
				if ( ! isset( $by_org[ $league['org'] ][ $team['id'] ] ) ) {
					$by_org[ $league['org'] ][ $team['id'] ] = array(
						'event' => $league['event'],
						'name'  => $league['name'],
					);
				}
			}
		}
		ksort( $by_org, SORT_NUMERIC );
		return $by_org;
	}

	/**
	 * Division names in natural, alphabetical order, so Division 2 comes before Division 10.
	 *
	 * @param string[] $divisions Division names.
	 * @return string[]
	 */
	public static function sort_divisions( array $divisions ) {
		$divisions = array_values( array_unique( $divisions ) );
		usort( $divisions, 'strnatcasecmp' );
		return $divisions;
	}

	/**
	 * The names the admin gave organisations.
	 *
	 * @return string[] Name by organisation id.
	 */
	public static function saved_org_names() {
		$names = get_option( self::ORG_NAMES, array() );
		return is_array( $names ) ? $names : array();
	}

	/**
	 * An organisation's name: the LMS's if it gives one, else the one the admin typed, else ''.
	 *
	 * @param string $org Organisation id.
	 * @return string
	 */
	public static function org_name( $org ) {
		$name = Chess_Army_Knife_LMS_Client::get_org_name( $org );
		if ( '' !== $name ) {
			return $name;
		}
		$saved = self::saved_org_names();
		return isset( $saved[ $org ] ) ? (string) $saved[ $org ] : '';
	}

	/**
	 * Save what the page submitted. Returns how many teams' entries changed.
	 *
	 * @param array $post Unslashed form values: orgs[], leagues[org][team id][event|name], org_names[org].
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
		foreach ( isset( $post['leagues'] ) && is_array( $post['leagues'] ) ? $post['leagues'] : array() as $org => $teams ) {
			foreach ( is_array( $teams ) ? $teams : array() as $team_id => $entry ) {
				$event = is_array( $entry ) && isset( $entry['event'] ) ? sanitize_text_field( (string) $entry['event'] ) : '';
				if ( '' === $event ) {
					continue; // Not in a division here.
				}
				$rows[] = array(
					'org'     => (string) $org,
					'team_id' => absint( $team_id ),
					'event'   => $event,
					'name'    => isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : '',
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

		// Names for organisations the LMS did not name.
		$names = self::saved_org_names();
		foreach ( isset( $post['org_names'] ) && is_array( $post['org_names'] ) ? $post['org_names'] : array() as $org => $name ) {
			$org  = preg_replace( '/[^0-9]/', '', (string) $org );
			$name = sanitize_text_field( (string) $name );
			if ( '' === $org ) {
				continue;
			}
			if ( '' === $name ) {
				unset( $names[ $org ] );
			} else {
				$names[ $org ] = $name;
			}
		}
		update_option( self::ORG_NAMES, $names, false );

		return array(
			'orgs'    => array_values( $orgs ),
			'changed' => $changed,
		);
	}

	/**
	 * Handle the form: save, then show the page again.
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
		);
		wp_safe_redirect( add_query_arg( array_filter( $args, 'strlen' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * One team in a list: its name, buttons to move it to the division above or below (the keyboard way to do what
	 * dragging does), and, once it is in a division, a prompt to say which LMS team it is if the LMS has no team of its name.
	 *
	 * @param string $org       Organisation id.
	 * @param array  $team      Team (see Chess_Army_Knife_Teams::all()).
	 * @param string $division  The division it is in, or '' if none.
	 * @param string $lms_name  The LMS name saved for it, or ''.
	 * @param array  $match     {names: string[] the LMS teams in its division, matched: bool, note: string}, or empty.
	 */
	protected static function render_team( $org, array $team, $division, $lms_name, array $match ) {
		$field = 'leagues[' . $org . '][' . $team['id'] . ']';
		$id    = 'cak-league-' . $org . '-' . $team['id'];
		?>
		<li class="cak-league-team" data-team="<?php echo esc_attr( $team['id'] ); ?>" style="border:1px solid #dcdcde;background:#fff;padding:6px 8px;margin:0 0 6px">
			<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
				<span class="cak-drag-handle dashicons dashicons-menu" aria-hidden="true" style="cursor:move"></span>
				<strong style="flex:1"><?php echo esc_html( $team['name'] ); ?></strong>
				<input type="hidden" class="cak-division-value" name="<?php echo esc_attr( $field ); ?>[event]" value="<?php echo esc_attr( $division ); ?>" />
				<button type="button" class="button cak-move-up">
					<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php /* translators: %s: team name */ echo esc_html( sprintf( __( 'Move %s up', 'chess-army-knife' ), $team['name'] ) ); ?></span>
				</button>
				<button type="button" class="button cak-move-down">
					<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php /* translators: %s: team name */ echo esc_html( sprintf( __( 'Move %s down', 'chess-army-knife' ), $team['name'] ) ); ?></span>
				</button>
			</div>
			<div class="cak-match">
				<?php if ( $match && $match['note'] ) : ?>
					<p class="description"><?php echo esc_html( $match['note'] ); ?></p>
				<?php elseif ( $match && $match['names'] && ( ! $match['matched'] || '' !== $lms_name ) ) : ?>
					<label for="<?php echo esc_attr( $id ); ?>-name">
						<?php echo esc_html( $match['matched'] ? __( 'The LMS team this is', 'chess-army-knife' ) : __( 'The LMS has no team called this. Select the team you\'re referring to:', 'chess-army-knife' ) ); ?>
					</label>
					<select id="<?php echo esc_attr( $id ); ?>-name" name="<?php echo esc_attr( $field ); ?>[name]">
						<option value=""><?php echo esc_html( $match['matched'] ? __( 'The team with the same name', 'chess-army-knife' ) : __( 'Select the team…', 'chess-army-knife' ) ); ?></option>
						<?php foreach ( $match['names'] as $lms_team ) : ?>
							<option value="<?php echo esc_attr( $lms_team ); ?>" <?php selected( $lms_name, $lms_team ); ?>><?php echo esc_html( $lms_team ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
			</div>
		</li>
		<?php
	}

	/**
	 * How a team in a division matches the LMS: the LMS's teams in that division, and whether one is the team.
	 *
	 * @param string $org      Organisation id.
	 * @param string $event    Division name.
	 * @param string $name     The name to look for: the saved LMS name, else the team's own.
	 * @param array  $memo     Divisions already loaded this request (kept between calls).
	 * @return array { names: string[], matched: bool, note: string }
	 */
	public static function match_in_division( $org, $event, $name, array &$memo ) {
		$key = $org . '|' . strtolower( $event );
		if ( ! isset( $memo[ $key ] ) ) {
			$memo[ $key ] = Chess_Army_Knife_League_Data::load( $org, $event );
		}
		$loaded = $memo[ $key ];

		if ( $loaded['error'] ) {
			return array(
				'names'   => array(),
				'matched' => false,
				'note'    => $loaded['error']->get_error_message(),
			);
		}

		$names = Chess_Army_Knife_League_Data::team_names( $loaded['fixtures'] );
		return array(
			'names'   => $names,
			'matched' => '' !== Chess_Army_Knife_League_Data::lms_name( $loaded['fixtures'], $name ),
			'note'    => $names ? '' : __( 'The LMS has no fixtures for this division yet, so the team cannot be matched. Check back once they have been announced.', 'chess-army-knife' ),
		);
	}

	/**
	 * Draw the page.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Teams::user_can_manage(), __( 'Leagues', 'chess-army-knife' ), 'teams' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen state; nothing is changed.
		$added = isset( $_GET['add_org'] ) ? preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_GET['add_org'] ) ) ) : '';
		$saved = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$by_org = self::entries_by_org();
		if ( '' !== $added && ! isset( $by_org[ $added ] ) ) {
			$by_org[ $added ] = array();
			ksort( $by_org, SORT_NUMERIC );
		}
		$teams = Chess_Army_Knife_Teams::all();
		$memo  = array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Teams', 'chess-army-knife' ); ?></h1>
			<?php Chess_Army_Knife_Team_Tabs::render_sections( 'leagues' ); ?>
			<?php if ( null !== $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Leagues saved.', 'chess-army-knife' ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'For each LMS organisation, put the club\'s teams in the division they play in: drag a team into a division, or use the arrow buttons to move it up or down. A team plays in one division of an organisation. The League games import, the fixtures carousel and league table highlighting all read these entries.', 'chess-army-knife' ); ?></p>
			<?php if ( ! $teams ) : ?>
				<p><?php esc_html_e( 'There are no teams yet. Add teams on the Teams tab first.', 'chess-army-knife' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>

				<?php foreach ( $by_org as $org => $assigned ) : ?>
					<?php
					$org       = (string) $org;
					$events    = Chess_Army_Knife_LMS_Client::get_season_events( $org );
					$season    = is_wp_error( $events ) ? '' : $events['season'];
					$ids       = array(); // LMS event id by division name, for the link to its fixtures.
					$divisions = array();
					foreach ( is_wp_error( $events ) ? array() : $events['events'] as $event ) {
						$ids[ $event['name'] ] = $event['id'];
						$divisions[]           = $event['name'];
					}
					// A division a team is already in stays listed even if the LMS no longer has it.
					$missing = array();
					foreach ( $assigned as $entry ) {
						if ( ! in_array( $entry['event'], $divisions, true ) && ! in_array( $entry['event'], $missing, true ) ) {
							$missing[] = $entry['event'];
						}
					}
					$divisions = self::sort_divisions( array_merge( $divisions, $missing ) );
					$name      = self::org_name( $org );
					$by_div    = array_fill_keys( $divisions, array() );
					$free      = array();
					foreach ( $teams as $team ) {
						if ( isset( $assigned[ $team['id'] ] ) ) {
							$by_div[ $assigned[ $team['id'] ]['event'] ][] = $team;
						} else {
							$free[] = $team;
						}
					}
					?>
					<input type="hidden" name="orgs[]" value="<?php echo esc_attr( $org ); ?>" />
					<?php $lms_named = '' !== Chess_Army_Knife_LMS_Client::get_org_name( $org ); ?>
					<h2 class="cak-org-title">
						<?php echo esc_html( '' !== $name ? $name : __( 'LMS organisation', 'chess-army-knife' ) ); ?>
						<span class="cak-org-id" style="color:#0a4b78">(<?php echo esc_html( $org ); ?>)</span>
						<?php if ( ! $lms_named && '' !== $name ) : ?>
							<button type="button" class="button cak-edit-org-name" aria-expanded="false" aria-controls="cak-org-name-row-<?php echo esc_attr( $org ); ?>">
								<span class="dashicons dashicons-edit" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Edit the organisation name', 'chess-army-knife' ); ?></span>
							</button>
						<?php endif; ?>
					</h2>
					<?php if ( ! $lms_named ) : ?>
						<p id="cak-org-name-row-<?php echo esc_attr( $org ); ?>" <?php echo '' !== $name ? 'style="display:none"' : ''; ?>>
							<label for="cak-org-name-<?php echo esc_attr( $org ); ?>"><?php esc_html_e( 'Organisation name', 'chess-army-knife' ); ?></label>
							<input type="text" id="cak-org-name-<?php echo esc_attr( $org ); ?>" name="org_names[<?php echo esc_attr( $org ); ?>]" value="<?php echo esc_attr( $name ); ?>" class="regular-text" />
							<span class="description"><?php esc_html_e( 'The LMS did not give a name, so you can type one for your own reference.', 'chess-army-knife' ); ?></span>
						</p>
					<?php endif; ?>
					<?php if ( is_wp_error( $events ) ) : ?>
						<div class="notice notice-warning inline"><p><?php echo esc_html( $events->get_error_message() ); ?></p></div>
					<?php else : ?>
						<p>
							<?php
							echo esc_html(
								'' !== $season
									/* translators: %s: name of the LMS season, for example 2026-27 */
									? sprintf( __( 'Divisions are those the LMS lists for the %s season.', 'chess-army-knife' ), $season )
									: __( 'Divisions are those the LMS lists for the current season.', 'chess-army-knife' )
							);
							?>
							<?php esc_html_e( 'The LMS cannot say which teams are in a division until its fixtures have been entered, so a team can be matched to the LMS only then. Check back once the fixtures have been announced.', 'chess-army-knife' ); ?>
						</p>
					<?php endif; ?>
					<?php if ( ! is_wp_error( $events ) && ! $divisions ) : ?>
						<div class="notice notice-warning inline"><p><?php esc_html_e( 'The LMS lists no divisions for this organisation this season.', 'chess-army-knife' ); ?></p></div>
					<?php endif; ?>

					<div class="cak-org" style="display:flex;gap:24px;flex-wrap:wrap;max-width:1100px" data-moved="<?php /* translators: 1: team name, 2: division name */ esc_attr_e( '%1$s is now in %2$s', 'chess-army-knife' ); ?>" data-none="<?php esc_attr_e( 'not in this organisation', 'chess-army-knife' ); ?>">
						<div style="flex:1;min-width:220px">
							<h3 id="cak-free-<?php echo esc_attr( $org ); ?>"><?php esc_html_e( 'Teams not in a division', 'chess-army-knife' ); ?></h3>
							<ul class="cak-division-list" data-division="" aria-labelledby="cak-free-<?php echo esc_attr( $org ); ?>" style="list-style:none;margin:0;padding:8px;min-height:48px;border:1px dashed #c3c4c7">
								<?php foreach ( $free as $team ) : ?>
									<?php self::render_team( $org, $team, '', '', array() ); ?>
								<?php endforeach; ?>
							</ul>
						</div>
						<div style="flex:2;min-width:300px">
							<?php foreach ( $divisions as $index => $division ) : ?>
								<h3 id="cak-div-<?php echo esc_attr( $org . '-' . $index ); ?>">
									<?php echo esc_html( $division ); ?>
									<?php if ( isset( $ids[ $division ] ) ) : ?>
										<a class="cak-lms-link" style="font-size:13px;font-weight:400" href="<?php echo esc_url( Chess_Army_Knife_LMS_Client::event_fixtures_url( $ids[ $division ] ) ); ?>" target="_blank" rel="noopener noreferrer">
											<?php esc_html_e( 'Check the fixtures on the LMS', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( sprintf( /* translators: %s: division name */ __( 'for %s (opens in a new tab)', 'chess-army-knife' ), $division ) ); ?></span>
										</a>
									<?php endif; ?>
								</h3>
								<ul class="cak-division-list" data-division="<?php echo esc_attr( $division ); ?>" aria-labelledby="cak-div-<?php echo esc_attr( $org . '-' . $index ); ?>" style="list-style:none;margin:0 0 16px;padding:8px;min-height:48px;border:1px solid #8c8f94">
									<?php foreach ( $by_div[ $division ] as $team ) : ?>
										<?php
										$lms_name = $assigned[ $team['id'] ]['name'];
										$match    = self::match_in_division( $org, $division, '' !== $lms_name ? $lms_name : $team['name'], $memo );
										self::render_team( $org, $team, $division, $lms_name, $match );
										?>
									<?php endforeach; ?>
								</ul>
							<?php endforeach; ?>
						</div>
					</div>
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
				</p>
				<p class="description"><?php esc_html_e( 'The divisions and their teams come from the LMS (they need the LMS API key under Settings), and are kept for a while. After moving a team to another division, save to match it against that division\'s teams.', 'chess-army-knife' ); ?></p>
			</form>
		</div>
		<?php
	}
}

Chess_Army_Knife_Leagues_Page::init();
