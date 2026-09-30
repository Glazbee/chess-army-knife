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
		$this->assertTrue( class_exists( 'Chess_Army_Knife_ECF_Client' ) );
		$this->assertTrue( class_exists( 'Chess_Army_Knife_LMS_Client' ) );
		$this->assertTrue( class_exists( 'Chess_Army_Knife_Cache' ) );
	}

	public function test_all_blocks_are_registered() {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array( 'rating-chart', 'club-results', 'league-table', 'team-carousel', 'biggest-gainers', 'featured-player', 'tournament-status', 'tournament-standings', 'tournament-players', 'tournament-games', 'tournament-winners', 'next-club-event', 'club-event-calendar' ) as $block ) {
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

	public function test_rest_player_search_lists_only_current_members_with_an_ecf_code() {
		global $wpdb;
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Membership_Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Chess_Army_Knife_Membership_Store::install_table();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$save = function ( $name, array $extra = array() ) {
			Chess_Army_Knife_Membership_Store::save_member(
				$extra + array(
					'name'     => $name,
					'status'   => 'active',
					'ecf_code' => '120787J',
				)
			);
		};
		$save( 'Test Player' );
		$save( 'Test Nocode', array( 'ecf_code' => '' ) );
		$save( 'Test Expired', array( 'expiry_date' => '2020-01-01' ) );
		$save( 'Test Pending', array( 'status' => 'pending' ) );
		$save( 'Test Declined', array( 'status' => 'rejected' ) );
		$save( 'Someone Else', array( 'ecf_code' => '999999A' ) );

		$request = new WP_REST_Request( 'GET', '/ecf-lms/v1/players' );
		$request->set_param( 'search', 'Test' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'code' => '120787J',
					'name' => 'Test Player',
					'club' => '',
				),
			),
			$response->get_data()
		);
	}

	public function test_rest_player_search_never_contacts_the_ecf() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$contacted = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$contacted ) {
				$contacted[] = $url;
				return new WP_Error( 'blocked', 'No requests expected.' );
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'GET', '/ecf-lms/v1/players' );
		$request->set_param( 'search', 'Smith' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $contacted );
		$this->assertFalse( method_exists( 'Chess_Army_Knife_ECF_Client', 'search_players' ), 'The ECF player search is gone, so it cannot be wired back in by accident.' );
	}

	public function test_rest_player_search_needs_a_logged_in_editor_and_a_reasonable_term() {
		$request = new WP_REST_Request( 'GET', '/ecf-lms/v1/players' );
		$request->set_param( 'search', 'Test' );

		wp_set_current_user( 0 );
		$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$one_letter = new WP_REST_Request( 'GET', '/ecf-lms/v1/players' );
		$one_letter->set_param( 'search', 'T' );
		$this->assertSame( array(), rest_do_request( $one_letter )->get_data() );
	}

	public function test_the_member_search_permission_can_be_narrowed_to_membership_officers() {
		add_filter(
			'Chess_Army_Knife_member_search_capability',
			function () {
				return Chess_Army_Knife_Memberships::CAPABILITY;
			}
		);
		$request = new WP_REST_Request( 'GET', '/ecf-lms/v1/players' );
		$request->set_param( 'search', 'Test' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	public function test_ecf_client_caches_real_http_responses() {
		$this->mock_http(
			array(
				'success' => true,
				'data'    => array( 'games' => array( array( 'id' => 1 ) ) ),
			)
		);

		Chess_Army_Knife_ECF_Client::get_games( '120787' );
		$games = Chess_Army_Knife_ECF_Client::get_games( '120787' );

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

		$result = Chess_Army_Knife_ECF_Client::get_player_by_code( '120787' );

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
