<?php
/**
 * Lichess Live Games block support: slide markup shared by the server
 * render and the refresh REST route.
 *
 * The public refresh route must not become an open proxy to Lichess, so
 * it never accepts a free choice of usernames: each block signs its
 * username list (and display options) when rendered, and the route only
 * serves lists carrying a valid signature.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Lichess_Live {

	const NAMESPACE_V1 = 'chess-army-knife/v1';

	/**
	 * Hook up the route.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the public refresh route.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/lichess-live',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_live_games' ),
				'permission_callback' => '__return_true', // Public data; the signature guards the input.
				'args'                => array(
					'users' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'board' => array(
						'type'    => 'string',
						'default' => '1',
					),
					'sig'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/lichess-position',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_position' ),
				'permission_callback' => '__return_true', // Public data; the signature guards the input.
				'args'                => array(
					'users' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'board' => array(
						'type'    => 'string',
						'default' => '1',
					),
					'game'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'sig'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Signature for a username list and board option.
	 *
	 * @param string[] $usernames Parsed usernames.
	 * @param bool     $show_board Whether live boards are shown.
	 * @return string
	 */
	public static function sign( array $usernames, $show_board ) {
		return wp_hash( 'lichess-live|' . implode( ',', $usernames ) . '|' . ( $show_board ? '1' : '0' ) );
	}

	/**
	 * REST callback: the current slides for a signed username list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_live_games( $request ) {
		$usernames  = Lichess_Client::parse_usernames( str_replace( ',', ' ', (string) $request['users'] ) );
		$show_board = '0' !== (string) $request['board'];

		if ( empty( $usernames ) || ! hash_equals( self::sign( $usernames, $show_board ), (string) $request['sig'] ) ) {
			return new WP_Error( 'chess_army_knife_bad_signature', __( 'Invalid request.', 'chess-army-knife' ), array( 'status' => 403 ) );
		}

		$games = Lichess_Client::get_live_games( $usernames );

		if ( is_wp_error( $games ) ) {
			return new WP_Error( 'chess_army_knife_lichess_unavailable', $games->get_error_message(), array( 'status' => 503 ) );
		}

		$slides = array();
		foreach ( $games as $game ) {
			$slides[] = array(
				'id'   => $game['id'],
				'html' => self::slide_html( $game, $show_board ),
			);
		}

		$response = new WP_REST_Response( array( 'slides' => $slides ) );
		$response->header( 'Cache-Control', 'public, max-age=' . Lichess_Client::cache_seconds() );

		return $response;
	}

	/**
	 * REST callback: the current position of one game, which must be one
	 * of the games currently live for a signed username list (so the
	 * route can't be used to look up arbitrary games).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_position( $request ) {
		$usernames  = Lichess_Client::parse_usernames( str_replace( ',', ' ', (string) $request['users'] ) );
		$show_board = '0' !== (string) $request['board'];

		if ( empty( $usernames ) || ! hash_equals( self::sign( $usernames, $show_board ), (string) $request['sig'] ) ) {
			return new WP_Error( 'chess_army_knife_bad_signature', __( 'Invalid request.', 'chess-army-knife' ), array( 'status' => 403 ) );
		}

		$games = Lichess_Client::get_live_games( $usernames );

		if ( is_wp_error( $games ) ) {
			return new WP_Error( 'chess_army_knife_lichess_unavailable', $games->get_error_message(), array( 'status' => 503 ) );
		}

		if ( ! in_array( (string) $request['game'], wp_list_pluck( $games, 'id' ), true ) ) {
			return new WP_Error( 'chess_army_knife_game_not_live', __( 'That game is not being played.', 'chess-army-knife' ), array( 'status' => 404 ) );
		}

		$position = Lichess_Client::get_position( (string) $request['game'] );

		if ( is_wp_error( $position ) ) {
			return new WP_Error( 'chess_army_knife_lichess_unavailable', $position->get_error_message(), array( 'status' => 503 ) );
		}

		$response = new WP_REST_Response( $position );
		$response->header( 'Cache-Control', 'public, max-age=' . Lichess_Client::position_cache_seconds() );

		return $response;
	}

	/**
	 * Markup for one game's carousel card.
	 *
	 * @param array $game       Normalised game from Lichess_Client.
	 * @param bool  $show_board Include a (lazy-loaded) live board.
	 * @return string Escaped HTML.
	 */
	public static function slide_html( array $game, $show_board ) {
		$meta = array_filter(
			array(
				$game['time_control'],
				ucfirst( $game['speed'] ),
				$game['rated'] ? __( 'Rated', 'chess-army-knife' ) : '',
			)
		);

		ob_start();
		?>
		<div class="ecf-lichess__slide" data-game-id="<?php echo esc_attr( $game['id'] ); ?>">
			<?php if ( $show_board ) : ?>
				<div
					class="ecf-lichess__board"
					data-game-id="<?php echo esc_attr( $game['id'] ); ?>"
					data-embed="<?php echo esc_url( $game['embed_url'] ); ?>"
					data-title="<?php esc_attr_e( 'Live Lichess game', 'chess-army-knife' ); ?>"
					role="img"
					aria-label="<?php esc_attr_e( 'Live chess board', 'chess-army-knife' ); ?>"
				></div>
			<?php endif; ?>
			<p class="ecf-lichess__players">
				<?php if ( '' === $game['white']['name'] && '' === $game['black']['name'] && $game['members'] ) : ?>
					<?php echo esc_html( implode( ', ', $game['members'] ) ); ?>
				<?php else : ?>
					<?php echo esc_html( self::player_label( $game['white'] ) ); ?>
					<span class="ecf-lichess__vs"><?php esc_html_e( 'v', 'chess-army-knife' ); ?></span>
					<?php echo esc_html( self::player_label( $game['black'] ) ); ?>
				<?php endif; ?>
			</p>
			<?php if ( $meta ) : ?>
				<p class="ecf-lichess__meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></p>
			<?php endif; ?>
			<p class="ecf-lichess__watch">
				<a href="<?php echo esc_url( $game['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Watch on Lichess', 'chess-army-knife' ); ?></a>
			</p>
		</div>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * "Name (1500)" for a player; "Anonymous" when Lichess gives no name.
	 *
	 * @param array $player Player with name and rating.
	 * @return string
	 */
	public static function player_label( array $player ) {
		$name = '' !== $player['name'] ? $player['name'] : __( 'Anonymous', 'chess-army-knife' );

		return null !== $player['rating'] ? sprintf( '%s (%d)', $name, $player['rating'] ) : $name;
	}
}

Chess_Army_Knife_Lichess_Live::init();
