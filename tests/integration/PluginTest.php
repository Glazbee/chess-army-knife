<?php
/**
 * Integration tests: the plugin running inside real WordPress.
 *
 * @package Chess_Army_Knife
 */

class PluginTest extends WP_UnitTestCase {

	/** @var array Captured outgoing HTTP requests. */
	private $http_requests = array();

	public function set_up() {
		parent::set_up();
		// Use transients so results don't depend on the custom cache table.
		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$this->http_requests = array();
	}

	/**
	 * Short-circuit outgoing HTTP calls with a canned JSON response.
	 */
	private function mock_http( $body, $status = 200 ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $body, $status ) {
				$this->http_requests[] = $url;
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	public function test_plugin_loads_its_classes_and_constants() {
		$this->assertTrue( defined( 'Chess_Army_Knife_VERSION' ) );
		$this->assertTrue( class_exists( 'ECF_Client' ) );
		$this->assertTrue( class_exists( 'LMS_Client' ) );
		$this->assertTrue( class_exists( 'Chess_Army_Knife_Cache' ) );
	}

	public function test_all_blocks_are_registered() {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array( 'rating-chart', 'club-results', 'league-table', 'team-carousel', 'biggest-gainers', 'featured-player', 'tournament-results', 'tournament-status', 'tournament-games', 'tournament-winners' ) as $block ) {
			$this->assertTrue( $registry->is_registered( "chess-army-knife/{$block}" ), $block );
		}
	}

	public function test_rest_routes_are_registered() {
		$routes = rest_get_server()->get_routes();

		foreach ( array( 'players', 'clubs', 'templates', 'defaults' ) as $route ) {
			$this->assertArrayHasKey( "/ecf-lms/v1/{$route}", $routes, $route );
		}
	}

	public function test_rest_endpoints_reject_logged_out_visitors() {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/ecf-lms/v1/defaults' ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_rest_defaults_available_to_editors() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'   => 0,
				'default_club_code' => '9BAJ',
			)
		);

		$response = rest_do_request( new WP_REST_Request( 'GET', '/ecf-lms/v1/defaults' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '9BAJ', $response->get_data()['clubCode'] );
	}

	public function test_rest_player_search_returns_normalised_suggestions() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->mock_http(
			array(
				'success' => true,
				'data'    => array(
					'players' => array(
						array(
							'ECF_code'  => '120787J',
							'full_name' => 'Test Player',
							'club_name' => 'Test Club',
						),
						array( 'full_name' => 'No Code' ),
					),
				),
			)
		);

		$request = new WP_REST_Request( 'GET', '/ecf-lms/v1/players' );
		$request->set_param( 'search', 'Test' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'code' => '120787J',
					'name' => 'Test Player',
					'club' => 'Test Club',
				),
			),
			$response->get_data()
		);
	}

	public function test_ecf_client_caches_real_http_responses() {
		$this->mock_http(
			array(
				'success' => true,
				'data'    => array( 'games' => array( array( 'id' => 1 ) ) ),
			)
		);

		ECF_Client::get_games( '120787' );
		$games = ECF_Client::get_games( '120787' );

		$this->assertSame( array( array( 'id' => 1 ) ), $games );
		$this->assertCount( 1, $this->http_requests );
	}

	public function test_ecf_client_reports_api_errors() {
		$this->mock_http(
			array(
				'success' => false,
				'message' => 'Nope',
			),
			500
		);

		$result = ECF_Client::get_player_by_code( '120787' );

		$this->assertWPError( $result );
		$this->assertSame( 'ecf_api_error', $result->get_error_code() );
	}

	public function test_featured_player_block_renders_without_ecf_code() {
		$html = do_blocks( '<!-- wp:chess-army-knife/featured-player {"playerName":"Jane Doe","blurb":"Club champion"} /-->' );

		$this->assertStringContainsString( 'Jane Doe', $html );
		$this->assertStringContainsString( 'Club champion', $html );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_block_shows_notice_when_unconfigured() {
		$html = do_blocks( '<!-- wp:chess-army-knife/featured-player /-->' );

		$this->assertStringContainsString( 'chess-army-knife-notice', $html );
	}

	public function test_settings_are_sanitised_on_save() {
		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$clean = Chess_Army_Knife_Settings::sanitize(
			array(
				'default_club_code' => ' 9baj <b>',
				'default_org_id'    => '12ab',
			)
		);

		$this->assertSame( '9BAJ', $clean['default_club_code'] );
		$this->assertSame( '12', $clean['default_org_id'] );
	}
}
