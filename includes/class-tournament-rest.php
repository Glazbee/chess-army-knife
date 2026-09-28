<?php
/**
 * REST endpoints for tournaments: the editor's tournament picker and
 * saving results from the Tournament Games to Play block.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Tournament_REST {

	const NAMESPACE_V1 = 'chess-army-knife/v1';

	/** Most results accepted in one request. */
	const MAX_BATCH = 200;

	/**
	 * Hook up the routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/tournaments',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_tournaments' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/games/results',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_results' ),
				'permission_callback' => array( 'Chess_Army_Knife_Tournaments', 'user_can_manage' ),
				'args'                => array(
					'results' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/games/(?P<id>\d+)/result',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_result' ),
				'permission_callback' => array( 'Chess_Army_Knife_Tournaments', 'user_can_manage' ),
				'args'                => array(
					'id'     => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'result' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * List tournaments for the block editor's picker.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_tournaments() {
		$out = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_tournaments() as $tournament ) {
			$out[] = array(
				'id'     => $tournament['id'],
				'name'   => $tournament['name'],
				'status' => $tournament['status'],
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Record the results of several games at once, in game order (an earlier
	 * knockout round has to be recorded before a later one).
	 *
	 * @param WP_REST_Request $request Request with results: game id => result.
	 * @return WP_REST_Response|WP_Error { saved: int[], errors: game id => message, reload: bool }
	 */
	public static function set_results( WP_REST_Request $request ) {
		$results = $request['results'];
		if ( ! is_array( $results ) || empty( $results ) || count( $results ) > self::MAX_BATCH ) {
			return new WP_Error( 'results_invalid', __( 'Choose at least one result to save.', 'chess-army-knife' ), array( 'status' => 400 ) );
		}

		$ids = array_map( 'absint', array_keys( $results ) );
		sort( $ids );

		$saved  = array();
		$errors = array();
		foreach ( $ids as $id ) {
			$result = isset( $results[ $id ] ) ? sanitize_text_field( (string) $results[ $id ] ) : '';
			$game   = Chess_Army_Knife_Tournaments::record_result( $id, $result );
			if ( is_wp_error( $game ) ) {
				$errors[ $id ] = $game->get_error_message();
			} else {
				$saved[] = $id;
			}
		}

		return rest_ensure_response(
			array(
				'saved'  => $saved,
				'errors' => (object) $errors,
			)
		);
	}

	/**
	 * Record a game result.
	 *
	 * @param WP_REST_Request $request Request with id and result ('' clears it).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_result( WP_REST_Request $request ) {
		$before = Chess_Army_Knife_Tournament_Store::get_game( (int) $request['id'] );
		$count  = $before ? count( Chess_Army_Knife_Tournament_Store::get_games( $before['tournament_id'] ) ) : 0;

		$game = Chess_Army_Knife_Tournaments::record_result( (int) $request['id'], $request['result'] );

		if ( is_wp_error( $game ) ) {
			$status = in_array( $game->get_error_code(), array( 'game_missing' ), true ) ? 404 : 400;
			return new WP_Error( $game->get_error_code(), $game->get_error_message(), array( 'status' => $status ) );
		}

		$tournament = Chess_Army_Knife_Tournament_Store::get_tournament( $game['tournament_id'] );

		// Knockout results move players between games and a draw adds a tie-break game, and
		// the last group game creates the bracket: the page should reload to show that.
		$reload = 'knockout' === $game['stage'] || count( Chess_Army_Knife_Tournament_Store::get_games( $game['tournament_id'] ) ) !== $count;

		return rest_ensure_response(
			array(
				'id'                => $game['id'],
				'result'            => $game['result'],
				'tournament_status' => $tournament ? $tournament['status'] : '',
				'reload'            => $reload,
			)
		);
	}
}

Chess_Army_Knife_Tournament_REST::init();
