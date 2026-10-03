<?php
/**
 * The "not set up yet" panel on the Overview: what the plugin still needs
 * (an LMS API key, a team, a first import, ...), each with a link to the fix.
 * What is missing is worked out from a handful of facts by items(), so it can
 * be tested without WordPress; facts() reads them from the site.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Setup_Checklist {

	/**
	 * What is not set up, or has gone wrong.
	 *
	 * @param array $facts {
	 *     @type bool       $lms_key           Whether an LMS API key is saved.
	 *     @type int        $teams             Teams the club has.
	 *     @type int        $league_teams      Teams that are in a league (so can be imported).
	 *     @type array|null $last_import       Result of Chess_Army_Knife_Events_Import::last_run().
	 *     @type int        $unsorted_clubs    Team names not yet put in a club.
	 *     @type int        $tournaments       Tournaments created.
	 *     @type int        $policies_attention Policies that are undecided or unreviewed.
	 * }
	 * @return array[] Each { key, message, link }: link is a screen name for url().
	 */
	public static function items( array $facts ) {
		$items = array();
		$last  = isset( $facts['last_import'] ) && is_array( $facts['last_import'] ) ? $facts['last_import'] : null;

		if ( empty( $facts['lms_key'] ) ) {
			$items[] = array(
				'key'     => 'lms_key',
				'message' => __( 'There is no LMS API key, so league fixtures cannot be imported.', 'chess-army-knife' ),
				'link'    => 'settings',
			);
		} elseif ( $last && ! empty( $last['summary']['key_problem'] ) ) {
			$items[] = array(
				'key'     => 'lms_key_rejected',
				'message' => __( 'The LMS did not accept your API key at the last import. Check it.', 'chess-army-knife' ),
				'link'    => 'settings',
			);
		}

		if ( empty( $facts['teams'] ) ) {
			$items[] = array(
				'key'     => 'teams',
				'message' => __( 'No teams have been added.', 'chess-army-knife' ),
				'link'    => 'teams',
			);
		} elseif ( empty( $facts['league_teams'] ) ) {
			$items[] = array(
				'key'     => 'leagues',
				'message' => __( 'None of your teams has a league entry, so there is nothing to import.', 'chess-army-knife' ),
				'link'    => 'teams',
			);
		} elseif ( ! $last && ! empty( $facts['lms_key'] ) ) {
			$items[] = array(
				'key'     => 'first_import',
				'message' => __( 'Fixtures have not been imported yet.', 'chess-army-knife' ),
				'link'    => 'import',
			);
		}

		if ( $last && empty( $last['summary']['key_problem'] ) && ! empty( $last['summary']['errors'] ) ) {
			$items[] = array(
				'key'     => 'import_errors',
				'message' => sprintf(
					/* translators: %d: number of leagues */
					_n( '%d league could not be loaded at the last import.', '%d leagues could not be loaded at the last import.', count( $last['summary']['errors'] ), 'chess-army-knife' ),
					count( $last['summary']['errors'] )
				),
				'link'    => 'import',
			);
		}

		if ( ! empty( $facts['unsorted_clubs'] ) ) {
			$items[] = array(
				'key'     => 'clubs',
				'message' => sprintf(
					/* translators: %d: number of team names */
					_n( '%d team name has not been put in a club, so its home fixtures have no venue.', '%d team names have not been put in a club, so their home fixtures have no venue.', (int) $facts['unsorted_clubs'], 'chess-army-knife' ),
					(int) $facts['unsorted_clubs']
				),
				'link'    => 'clubs',
			);
		}

		if ( empty( $facts['tournaments'] ) ) {
			$items[] = array(
				'key'     => 'tournaments',
				'message' => __( 'No tournament has been created yet.', 'chess-army-knife' ),
				'link'    => 'tournaments',
			);
		}

		if ( ! empty( $facts['policies_attention'] ) ) {
			$items[] = array(
				'key'     => 'policies',
				'message' => sprintf(
					/* translators: %d: number of policies */
					_n( '%d policy is not finished.', '%d policies are not finished.', (int) $facts['policies_attention'], 'chess-army-knife' ),
					(int) $facts['policies_attention']
				),
				'link'    => 'policies',
			);
		}

		return $items;
	}

	/**
	 * Read the facts items() needs from the site.
	 *
	 * @return array
	 */
	public static function facts() {
		$teams     = Chess_Army_Knife_Teams::all();
		$in_league = array_filter(
			$teams,
			function ( $team ) {
				return ! empty( $team['leagues'] );
			}
		);

		return array(
			'lms_key'            => '' !== Chess_Army_Knife_LMS_Client::api_key(),
			'teams'              => count( $teams ),
			'league_teams'       => count( $in_league ),
			'last_import'        => Chess_Army_Knife_Events_Import::last_run(),
			'unsorted_clubs'     => count( Chess_Army_Knife_Clubs::unsorted() ),
			'tournaments'        => count( Chess_Army_Knife_Tournament_Store::get_tournaments() ),
			'policies_attention' => Chess_Army_Knife_Policies::attention_count(),
		);
	}

	/**
	 * The address of the screen that fixes an item.
	 *
	 * @param string $link Screen name from items().
	 * @return string
	 */
	protected static function url( $link ) {
		$pages = array(
			'settings'    => admin_url( 'admin.php?page=' . Chess_Army_Knife_Settings::PAGE ),
			'teams'       => admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Teams::POST_TYPE ),
			'import'      => admin_url( 'admin.php?page=' . Chess_Army_Knife_Events_Import::PAGE ),
			'clubs'       => admin_url( 'admin.php?page=' . Chess_Army_Knife_Clubs::PAGE ),
			'tournaments' => admin_url( 'admin.php?page=' . Chess_Army_Knife_Tournaments_Page::SLUG ),
			'policies'    => Chess_Army_Knife_Policies::screen_url(),
		);

		return isset( $pages[ $link ] ) ? $pages[ $link ] : '';
	}

	/**
	 * The panel for the top of the Overview.
	 *
	 * @return string Escaped HTML, or '' if there is nothing to do or the user cannot change settings.
	 */
	public static function html() {
		if ( ! current_user_can( Chess_Army_Knife_Setup::REQUIRED_CAP ) ) {
			return '';
		}

		$items = self::items( self::facts() );
		if ( ! $items ) {
			return '';
		}

		$html = '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Still to do', 'chess-army-knife' ) . '</strong></p><ul class="ul-disc">';
		foreach ( $items as $item ) {
			$html .= '<li>' . esc_html( $item['message'] ) . ' <a href="' . esc_url( self::url( $item['link'] ) ) . '">' . esc_html__( 'Fix this', 'chess-army-knife' ) . '</a></li>';
		}

		return $html . '</ul></div>';
	}
}
