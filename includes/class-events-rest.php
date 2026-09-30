<?php
/**
 * Public REST route that serves one month of the Club Event Calendar's
 * month grid, so the block can move between months without a page load.
 *
 * It only reads the site's own published events, so it needs no sign-in.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Events_REST {

	const NAMESPACE_V1 = 'chess-army-knife/v1';

	/** How many months either side of this one can be viewed. */
	const MAX_MONTHS_AWAY = 60;

	/**
	 * Hook up the route.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/events-month',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'month' ),
				'permission_callback' => '__return_true', // Public data.
				'args'                => array(
					'month'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'tags'      => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'location'  => array(
						'type'    => 'string',
						'default' => '1',
					),
					'teams'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'venue'     => array(
						'type'              => 'string',
						'default'           => 'all',
						'sanitize_callback' => 'sanitize_key',
					),
					'showteams' => array(
						'type'    => 'string',
						'default' => '1',
					),
				),
			)
		);
	}

	/**
	 * Months from the current month, for limiting how far the grid can be moved.
	 *
	 * @param int $year  Year.
	 * @param int $month Month, 1-12.
	 * @return int
	 */
	protected static function months_from_now( $year, $month ) {
		$now = Chess_Army_Knife_Events_Display::parse_month( current_time( 'Y-m' ) );

		return ( $year * 12 + $month ) - ( $now[0] * 12 + $now[1] );
	}

	/**
	 * Serve a month.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function month( $request ) {
		$parsed = Chess_Army_Knife_Events_Display::parse_month( (string) $request['month'] );

		if ( ! $parsed || abs( self::months_from_now( $parsed[0], $parsed[1] ) ) > self::MAX_MONTHS_AWAY ) {
			return new WP_Error( 'chess_army_knife_bad_month', __( 'That month is not available.', 'chess-army-knife' ), array( 'status' => 400 ) );
		}

		list( $year, $month ) = $parsed;

		$tags     = Chess_Army_Knife_Events_Display::tag_slugs( explode( ',', (string) $request['tags'] ) );
		$team_ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $request['teams'] ) ) ) );
		$options  = array(
			'show_location' => '0' !== (string) $request['location'],
			'show_teams'    => '0' !== (string) $request['showteams'],
		);

		$response = new WP_REST_Response(
			array(
				'month' => sprintf( '%04d-%02d', $year, $month ),
				'label' => Chess_Army_Knife_Events_Display::month_label( $year, $month ),
				'prev'  => Chess_Army_Knife_Events_Display::shift_month( $year, $month, -1 ),
				'next'  => Chess_Army_Knife_Events_Display::shift_month( $year, $month, 1 ),
				'html'  => Chess_Army_Knife_Events_Display::month_html( $year, $month, Chess_Army_Knife_Events_Display::month_events( $year, $month, $tags, $team_ids, (string) $request['venue'] ), $options ),
			)
		);
		$response->header( 'Cache-Control', 'public, max-age=60' );

		return $response;
	}
}

Chess_Army_Knife_Events_REST::init();
