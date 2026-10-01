<?php
/**
 * Registers the compiled blocks and a couple of small REST endpoints
 * that back the "find a member" control in the editor.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register all blocks from their compiled build/ directories.
 */
function Chess_Army_Knife_register() {
	$blocks_dir = Chess_Army_Knife_DIR . 'build/';

	foreach ( array( 'rating-chart', 'club-results', 'league-table', 'team-carousel', 'biggest-gainers', 'featured-player', 'tournament-status', 'tournament-standings', 'tournament-players', 'tournament-games', 'tournament-winners', 'next-club-event', 'club-event-calendar', 'memberships', 'membership-form', 'my-data', 'team-profiles', 'event-registration', 'member-portal' ) as $block ) {
		$path = $blocks_dir . $block;
		if ( file_exists( $path . '/block.json' ) ) {
			register_block_type( $path );
		}
	}
}
add_action( 'init', 'Chess_Army_Knife_register' );

/**
 * Register the small REST API behind the editor's controls: searching the
 * club's own people (players), templates and site defaults.
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
		'/teams',
		array(
			'methods'             => 'GET',
			'callback'            => 'Chess_Army_Knife_rest_get_teams',
			'permission_callback' => 'Chess_Army_Knife_rest_editor_permission',
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
}
add_action( 'rest_api_init', 'Chess_Army_Knife_register_rest_routes' );

/**
 * The teams' ids and names, for the Club Teams block's team picker.
 *
 * @return array[]
 */
function Chess_Army_Knife_rest_get_teams() {
	$teams = array();
	foreach ( Chess_Army_Knife_Teams::choices() as $id => $name ) {
		$teams[] = array(
			'id'   => $id,
			'name' => $name,
		);
	}
	return $teams;
}

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
 * player picker and the tournament screens. Current members, and guests recorded as not being members, with an ECF
 * rating code are offered, as {code, name, club} suggestions (the club field
 * says "not a club member" for a guest and is blank for a member). The ECF's own database is
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
			'club' => Chess_Army_Knife_Membership_Store::STATUS_NONMEMBER === $member['status'] ? __( 'not a club member', 'chess-army-knife' ) : '',
		);
	}

	return rest_ensure_response( $suggestions );
}

/**
 * Tell the stylesheets whether high contrast is forced on or off. When it is neither, the
 * stylesheets follow the visitor's own device setting (prefers-contrast).
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function Chess_Army_Knife_contrast_body_class( $classes ) {
	$mode = Chess_Army_Knife_Settings::get_options()['contrast_mode'];
	if ( 'always' === $mode ) {
		$classes[] = 'cak-contrast-always';
	} elseif ( 'off' === $mode ) {
		$classes[] = 'cak-contrast-off';
	}
	return $classes;
}
add_filter( 'body_class', 'Chess_Army_Knife_contrast_body_class' );
