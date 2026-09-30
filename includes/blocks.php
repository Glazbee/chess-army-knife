<?php
/**
 * Registers the compiled blocks and a couple of small REST endpoints
 * that back the "find a member/club" controls in the editor.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register all blocks from their compiled build/ directories.
 */
function Chess_Army_Knife_register() {
	$blocks_dir = Chess_Army_Knife_DIR . 'build/';

	foreach ( array( 'rating-chart', 'club-results', 'league-table', 'team-carousel', 'biggest-gainers', 'featured-player', 'tournament-status', 'tournament-standings', 'tournament-players', 'tournament-games', 'tournament-winners', 'next-club-event', 'club-event-calendar', 'memberships', 'membership-form', 'data-policy' ) as $block ) {
		$path = $blocks_dir . $block;
		if ( file_exists( $path . '/block.json' ) ) {
			register_block_type( $path );
		}
	}
}
add_action( 'init', 'Chess_Army_Knife_register' );

/**
 * Register the small REST API behind the editor's search controls: the
 * club's own members (players) and, through a proxy that avoids browser CORS
 * problems and hides raw API internals, ECF clubs.
 */
function Chess_Army_Knife_register_rest_routes() {
	register_rest_route(
		'ecf-lms/v1',
		'/players',
		array(
			'methods'             => 'GET',
			'callback'            => 'Chess_Army_Knife_rest_search_players',
			'permission_callback' => 'Chess_Army_Knife_rest_member_search_permission',
			'args'                => array(
				'search' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);

	register_rest_route(
		'ecf-lms/v1',
		'/templates',
		array(
			'methods'             => 'GET',
			'callback'            => 'Chess_Army_Knife_rest_get_templates',
			'permission_callback' => 'Chess_Army_Knife_rest_editor_permission',
			'args'                => array(
				'block' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	register_rest_route(
		'ecf-lms/v1',
		'/defaults',
		array(
			'methods'             => 'GET',
			'callback'            => 'Chess_Army_Knife_rest_get_defaults',
			'permission_callback' => 'Chess_Army_Knife_rest_editor_permission',
		)
	);

	register_rest_route(
		'ecf-lms/v1',
		'/clubs',
		array(
			'methods'             => 'GET',
			'callback'            => 'Chess_Army_Knife_rest_search_clubs',
			'permission_callback' => 'Chess_Army_Knife_rest_editor_permission',
			'args'                => array(
				'search' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'Chess_Army_Knife_register_rest_routes' );

/**
 * Only logged-in users who can edit posts should be able to use the
 * editor-side search helper endpoints.
 *
 * @return bool
 */
function Chess_Army_Knife_rest_editor_permission() {
	return current_user_can( 'edit_posts' );
}

/**
 * REST callback: templates available for one block type, for the
 * template picker in each block's sidebar.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function Chess_Army_Knife_rest_get_templates( WP_REST_Request $request ) {
	return rest_ensure_response( Chess_Army_Knife_Templates::list_for_block( $request->get_param( 'block' ) ) );
}

/**
 * REST callback: the plugin's global default values, so the block
 * editor can tell whether a field left blank will actually resolve to
 * something at render time (and skip an unnecessary "please configure
 * this" placeholder if so).
 *
 * @return WP_REST_Response
 */
function Chess_Army_Knife_rest_get_defaults() {
	$options = Chess_Army_Knife_Settings::get_options();

	return rest_ensure_response(
		array(
			'clubCode'  => $options['default_club_code'],
			'orgId'     => $options['default_org_id'],
			'eventName' => $options['default_event_name'],
			'domain'    => $options['default_domain'],
		)
	);
}

/**
 * Who may search the club's members from the editor and the tournament
 * screens. The default matches the other editor helpers; a club that wants
 * only its membership officers to do this can return their permission.
 *
 * @return bool
 */
function Chess_Army_Knife_rest_member_search_permission() {
	/**
	 * Filter the capability needed to search club members by name.
	 *
	 * @param string $capability Capability name, edit_posts by default.
	 */
	return current_user_can( (string) apply_filters( 'Chess_Army_Knife_member_search_capability', 'edit_posts' ) );
}

/**
 * REST callback: search the club's own members by name, for the editor's
 * player picker and the tournament screens. Only current members with an ECF
 * rating code are offered, as {code, name, club} suggestions (the club is
 * always blank: everyone listed is a member here). The ECF's own database is
 * deliberately not searched, so the club only ever handles people it holds a
 * record for and can account for in a subject access request.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function Chess_Army_Knife_rest_search_players( WP_REST_Request $request ) {
	$suggestions = array();
	foreach ( Chess_Army_Knife_Membership_Store::search_players( (string) $request->get_param( 'search' ) ) as $member ) {
		$suggestions[] = array(
			'code' => $member['ecf_code'],
			'name' => $member['name'],
			'club' => '',
		);
	}

	return rest_ensure_response( $suggestions );
}

/**
 * REST callback: club name search, normalised for the editor's club picker.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function Chess_Army_Knife_rest_search_clubs( WP_REST_Request $request ) {
	$results = Chess_Army_Knife_ECF_Client::search_clubs( $request->get_param( 'search' ) );

	if ( is_wp_error( $results ) ) {
		return $results;
	}

	$suggestions = array();
	foreach ( (array) $results as $club ) {
		$code = Chess_Army_Knife_LMS_Client::pick( $club, array( 'club_code', 'code' ) );
		$name = Chess_Army_Knife_LMS_Client::pick( $club, array( 'club_name', 'name' ) );

		if ( '' === $code || '' === $name ) {
			continue;
		}

		$suggestions[] = array(
			'code' => (string) $code,
			'name' => (string) $name,
		);
	}

	return rest_ensure_response( $suggestions );
}
