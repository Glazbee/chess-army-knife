<?php
/**
 * Registers the compiled blocks and a couple of small REST endpoints
 * that back the "search for a player/club" controls in the editor.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register all blocks from their compiled build/ directories.
 */
function Chess_Army_Knife_register() {
	$blocks_dir = Chess_Army_Knife_DIR . 'build/';

	foreach ( array( 'rating-chart', 'club-results', 'league-table', 'team-carousel', 'biggest-gainers', 'featured-player', 'tournament-results' ) as $block ) {
		$path = $blocks_dir . $block;
		if ( file_exists( $path . '/block.json' ) ) {
			register_block_type( $path );
		}
	}
}
add_action( 'init', 'Chess_Army_Knife_register' );

/**
 * Register a small proxy REST API so the block editor can search ECF
 * players/clubs without needing direct browser-to-ECF requests (which
 * may be blocked by CORS) and without exposing raw API internals.
 */
function Chess_Army_Knife_register_rest_routes() {
	register_rest_route(
		'ecf-lms/v1',
		'/players',
		array(
			'methods'             => 'GET',
			'callback'            => 'Chess_Army_Knife_rest_search_players',
			'permission_callback' => 'Chess_Army_Knife_rest_editor_permission',
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
 * REST callback: player name search, normalised into a flat list of
 * {code, name, club} suggestions for the editor's autocomplete control.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function Chess_Army_Knife_rest_search_players( WP_REST_Request $request ) {
	$results = ECF_Client::search_players( $request->get_param( 'search' ) );

	if ( is_wp_error( $results ) ) {
		return $results;
	}

	$suggestions = array();
	foreach ( (array) $results as $player ) {
		$code = isset( $player['ECF_code'] ) ? $player['ECF_code'] : ( isset( $player['player_no'] ) ? $player['player_no'] : '' );
		$name = isset( $player['full_name'] ) ? $player['full_name'] : ( isset( $player['name'] ) ? $player['name'] : '' );
		$club = isset( $player['club_name'] ) ? $player['club_name'] : '';

		if ( '' === $code || '' === $name ) {
			continue;
		}

		$suggestions[] = array(
			'code' => (string) $code,
			'name' => (string) $name,
			'club' => (string) $club,
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
	$results = ECF_Client::search_clubs( $request->get_param( 'search' ) );

	if ( is_wp_error( $results ) ) {
		return $results;
	}

	$suggestions = array();
	foreach ( (array) $results as $club ) {
		$code = LMS_Client::pick( $club, array( 'club_code', 'code' ) );
		$name = LMS_Client::pick( $club, array( 'club_name', 'name' ) );

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
