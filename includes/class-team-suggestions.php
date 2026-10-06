<?php
/**
 * Teams that may be the club's, found in the LMS: a team in one of the leagues the club plays in (in any season) whose
 * name starts with one of the club's team name prefixes (Settings), but that is not one of its teams. The import notes
 * them as it reads fixtures, and the League games screen offers them: the admin can add them as teams, as historic
 * ones if they no longer play, or ignore them. Nothing is added by itself.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Team_Suggestions {

	const OPTION = 'Chess_Army_Knife_team_suggestions';
	const ACTION = 'chess_army_knife_suggested_teams';

	/**
	 * Hook up the forms.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * The key of a suggested team: its division and name.
	 *
	 * @param string $org   LMS organisation id.
	 * @param string $event Division name.
	 * @param string $team  Team name.
	 * @return string
	 */
	public static function key( $org, $event, $team ) {
		return $org . '|' . strtolower( $event ) . '|' . strtolower( trim( $team ) );
	}

	/**
	 * Add what an import found to what is already remembered.
	 *
	 * @param array[]  $kept         What is remembered, by key.
	 * @param array[]  $found        Each { org, event, team }, from Events_Import::possible_club_teams().
	 * @param string[] $season_names Season name by organisation id, for the season the import read.
	 * @param bool     $is_active    Whether the import read the running season.
	 * @return array[] By key: { org, event, team, seasons (names), active (seen in the running season), ignored }.
	 */
	public static function merge( array $kept, array $found, array $season_names, $is_active ) {
		foreach ( $found as $entry ) {
			$key  = self::key( $entry['org'], $entry['event'], $entry['team'] );
			$last = isset( $kept[ $key ] ) ? $kept[ $key ] : array(
				'org'     => $entry['org'],
				'event'   => $entry['event'],
				'team'    => $entry['team'],
				'seasons' => array(),
				'active'  => false,
				'ignored' => false,
			);

			$season = isset( $season_names[ $entry['org'] ] ) ? $season_names[ $entry['org'] ] : '';
			if ( '' !== $season && ! in_array( $season, $last['seasons'], true ) ) {
				$last['seasons'][] = $season;
			}
			$last['active'] = $last['active'] || $is_active;
			$kept[ $key ]   = $last;
		}

		return $kept;
	}

	/**
	 * Remember what an import found.
	 *
	 * @param array[]  $found        Each { org, event, team }.
	 * @param string[] $season_names Season name by organisation id.
	 * @param bool     $is_active    Whether the import read the running season.
	 */
	public static function note( array $found, array $season_names, $is_active ) {
		if ( ! $found ) {
			return;
		}

		$kept = get_option( self::OPTION, array() );
		update_option( self::OPTION, self::merge( is_array( $kept ) ? $kept : array(), $found, $season_names, $is_active ), false );
	}

	/**
	 * The suggestions still to answer: not ignored, and not already one of the club's league entries.
	 *
	 * @return array[] By key, by team name.
	 */
	public static function pending() {
		$kept = get_option( self::OPTION, array() );
		if ( ! is_array( $kept ) ) {
			return array();
		}

		$known = array();
		foreach ( Chess_Army_Knife_Teams::league_entries() as $entry ) {
			$known[ self::key( $entry['org'], $entry['event'], $entry['team'] ) ] = true;
		}

		$pending = array_filter(
			$kept,
			function ( $entry, $key ) use ( $known ) {
				return empty( $entry['ignored'] ) && ! isset( $known[ $key ] );
			},
			ARRAY_FILTER_USE_BOTH
		);
		uasort(
			$pending,
			function ( $a, $b ) {
				return strnatcasecmp( $a['team'], $b['team'] );
			}
		);

		return $pending;
	}

	/**
	 * The suggestions the admin ticked, only those that were offered.
	 *
	 * @return array[] By key.
	 */
	protected static function chosen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by check_admin_referer() in handle().
		$posted = isset( $_POST['teams'] ) && is_array( $_POST['teams'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['teams'] ) ) : array();

		return array_intersect_key( self::pending(), array_flip( $posted ) );
	}

	/**
	 * Add the ticked teams, or ignore them, as the button pressed says. A team found only in earlier seasons is added as
	 * a historic team.
	 */
	public static function handle() {
		if ( ! Chess_Army_Knife_Teams::user_can_manage() || ! check_admin_referer( self::ACTION ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}

		$chosen = self::chosen();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by check_admin_referer() above.
		$ignore = isset( $_POST['choice'] ) && 'ignore' === sanitize_key( wp_unslash( $_POST['choice'] ) );
		$args   = array( 'page' => Chess_Army_Knife_League_Games::PAGE );

		if ( $ignore ) {
			$kept = get_option( self::OPTION, array() );
			$kept = is_array( $kept ) ? $kept : array();
			foreach ( array_keys( $chosen ) as $key ) {
				$kept[ $key ]['ignored'] = true;
			}
			update_option( self::OPTION, $kept, false );
		} else {
			$entries = array();
			foreach ( $chosen as $entry ) {
				$entries[] = array(
					'org'      => $entry['org'],
					'event'    => $entry['event'],
					'team'     => $entry['team'],
					'historic' => empty( $entry['active'] ),
				);
			}
			$args['teams_added']   = count( $entries );
			$args['teams_created'] = Chess_Army_Knife_Teams::assign_league_entries( $entries );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Draw what was just added, and the teams still to answer.
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display; nothing is changed.
		$added   = isset( $_GET['teams_added'] ) ? absint( $_GET['teams_added'] ) : null;
		$created = isset( $_GET['teams_created'] ) ? absint( $_GET['teams_created'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( null !== $added ) :
			?>
			<div class="notice notice-success" role="status"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: league entries added, 2: teams created */
						__( 'Added %1$d league entries (%2$d new teams). Run the import again to bring their games in.', 'chess-army-knife' ),
						$added,
						$created
					)
				);
				?>
			</p></div>
			<?php
		endif;

		$pending = self::pending();
		if ( ! $pending ) {
			return;
		}
		?>
		<div class="notice notice-warning inline" id="cak-team-suggestions">
			<h3><?php esc_html_e( 'Teams that may be yours', 'chess-army-knife' ); ?></h3>
			<p><?php esc_html_e( 'These teams play in your leagues and their names start like your teams\', but you have no record of them, so their games were not imported. Add the ones that are yours: a team found only in earlier seasons is added as a historic team, which keeps its games without listing it among your current teams. Ignore any that are not.', 'chess-army-knife' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="widefat striped">
					<thead><tr>
						<td class="check-column"></td>
						<th scope="col"><?php esc_html_e( 'Team', 'chess-army-knife' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Division', 'chess-army-knife' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Seen in', 'chess-army-knife' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Will be added as', 'chess-army-knife' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $pending as $key => $entry ) : ?>
						<tr>
							<th scope="row" class="check-column"><input type="checkbox" name="teams[]" value="<?php echo esc_attr( $key ); ?>" id="cak-suggest-<?php echo esc_attr( md5( $key ) ); ?>" /><label class="screen-reader-text" for="cak-suggest-<?php echo esc_attr( md5( $key ) ); ?>"><?php echo esc_html( $entry['team'] ); ?></label></th>
							<td><?php echo esc_html( $entry['team'] ); ?></td>
							<td><?php echo esc_html( $entry['event'] ); ?></td>
							<td><?php echo esc_html( $entry['seasons'] ? implode( ', ', $entry['seasons'] ) : '–' ); ?></td>
							<td><?php echo esc_html( empty( $entry['active'] ) ? __( 'Historic team', 'chess-army-knife' ) : __( 'Current team', 'chess-army-knife' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="submit" name="choice" value="add" class="button button-primary"><?php esc_html_e( 'Add selected teams', 'chess-army-knife' ); ?></button>
					<button type="submit" name="choice" value="ignore" class="button"><?php esc_html_e( 'Ignore selected', 'chess-army-knife' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}

Chess_Army_Knife_Team_Suggestions::init();
