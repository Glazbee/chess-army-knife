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
		global $wpdb;
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Membership_Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Membership_Seasons::install_tables();
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

		foreach ( array( 'players', 'templates', 'defaults' ) as $route ) {
			$this->assertArrayHasKey( "/chess-army-knife/v1/{$route}", $routes, $route );
		}
	}

	public function test_rest_endpoints_reject_logged_out_visitors() {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/chess-army-knife/v1/defaults' ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_rest_defaults_available_to_editors() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'default_org_id'  => '613',
			)
		);

		$response = rest_do_request( new WP_REST_Request( 'GET', '/chess-army-knife/v1/defaults' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '613', $response->get_data()['orgId'] );
		$this->assertArrayNotHasKey( 'clubCode', $response->get_data(), 'Club lookups are gone: the roster is the club\'s own records.' );
	}

	public function test_rest_player_search_lists_only_current_members_with_an_ecf_code() {
		global $wpdb;
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Membership_Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Membership_Seasons::install_tables();
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
		$save( 'Test Cancelled', array( 'status' => 'cancelled' ) );
		$save( 'Test Pending', array( 'status' => 'pending' ) );
		$save( 'Test Declined', array( 'status' => 'rejected' ) );
		$save( 'Someone Else', array( 'ecf_code' => '999999A' ) );

		$request = new WP_REST_Request( 'GET', '/chess-army-knife/v1/players' );
		$request->set_param( 'search', 'Test' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'code' => '120787J',
					'name' => 'Player, Test',
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

		$request = new WP_REST_Request( 'GET', '/chess-army-knife/v1/players' );
		$request->set_param( 'search', 'Smith' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $contacted );
		$this->assertFalse( method_exists( 'Chess_Army_Knife_ECF_Client', 'search_players' ), 'The ECF player search is gone, so it cannot be wired back in by accident.' );
	}

	public function test_rest_player_search_needs_a_logged_in_editor_and_a_reasonable_term() {
		$request = new WP_REST_Request( 'GET', '/chess-army-knife/v1/players' );
		$request->set_param( 'search', 'Test' );

		wp_set_current_user( 0 );
		$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$one_letter = new WP_REST_Request( 'GET', '/chess-army-knife/v1/players' );
		$one_letter->set_param( 'search', 'T' );
		$this->assertSame( array(), rest_do_request( $one_letter )->get_data() );
	}

	public function test_the_member_search_is_for_editors_not_contributors_or_authors() {
		$request = new WP_REST_Request( 'GET', '/chess-army-knife/v1/players' );
		$request->set_param( 'search', 'Test' );

		foreach ( array( 'contributor', 'author' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertSame( 403, rest_do_request( $request )->get_status(), $role . ' can see members\' names and ECF codes.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
	}

	public function test_the_member_search_permission_can_be_narrowed_to_membership_officers() {
		add_filter(
			'Chess_Army_Knife_member_search_capability',
			function () {
				return Chess_Army_Knife_Memberships::CAPABILITY;
			}
		);
		$request = new WP_REST_Request( 'GET', '/chess-army-knife/v1/players' );
		$request->set_param( 'search', 'Test' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	public function test_ecf_client_caches_real_http_responses() {
		Chess_Army_Knife_Membership_Store::add_guest(
			array(
				'name'     => 'Recorded Player',
				'ecf_code' => '120787J',
			)
		);
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

	public function test_an_unrecorded_player_is_written_down_before_anything_is_fetched_about_them() {
		$this->mock_http(
			array(
				'success' => true,
				'data'    => array(
					'full_name' => 'Newly Seen',
					'games'     => array( array( 'id' => 7 ) ),
				),
			)
		);
		$this->assertNull( Chess_Army_Knife_Membership_Store::find_by_ecf_code( '120787J' ) );

		$games = Chess_Army_Knife_ECF_Client::get_games( '120787J' );

		$this->assertSame( array( array( 'id' => 7 ) ), $games );
		$person = Chess_Army_Knife_Membership_Store::find_by_ecf_code( '120787J' );
		$this->assertSame( 'Seen, Newly', $person['name'] );
		$this->assertSame( 'nonmember', $person['status'], 'Recorded, but not as a member.' );
		$this->assertSame( array(), Chess_Army_Knife_Membership_Store::get_members(), 'And left out of the member lists.' );
		$this->assertCount( 2, $this->http_requests, 'One request to learn who they are, one for their games.' );
	}

	public function test_a_player_already_recorded_is_not_looked_up_again() {
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'     => 'Real Member',
				'status'   => 'active',
				'ecf_code' => '120787J',
			)
		);
		$this->mock_http(
			array(
				'success' => true,
				'data'    => array( 'games' => array() ),
			)
		);

		Chess_Army_Knife_ECF_Client::get_games( '120787' ); // Digits only: the same person.

		$this->assertCount( 1, $this->http_requests );
		$this->assertCount( 1, Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'people' ) ) );
	}

	public function test_nothing_is_fetched_or_recorded_for_a_code_the_ecf_does_not_know() {
		$this->mock_http(
			array(
				'success' => false,
				'message' => 'No such player.',
			),
			404
		);

		$this->assertWPError( Chess_Army_Knife_ECF_Client::get_games( '999999X' ) );
		$this->assertWPError( Chess_Army_Knife_ECF_Client::get_rating( '999999X' ) );

		$this->assertNull( Chess_Army_Knife_Membership_Store::find_by_ecf_code( '999999X' ) );
		$this->assertCount( 1, $this->http_requests, 'Only the lookup of who they are.' );
	}

	public function test_a_lookup_without_a_name_in_the_answer_records_nobody() {
		$this->mock_http(
			array(
				'success' => true,
				'data'    => array( 'games' => array() ),
			)
		);

		$result = Chess_Army_Knife_ECF_Client::get_rating( '120787J' );

		$this->assertWPError( $result );
		$this->assertSame( 'ecf_no_name', $result->get_error_code() );
		$this->assertSame( array(), Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'people' ) ) );
	}

	public function test_the_club_roster_blocks_only_ever_use_current_members_with_a_code() {
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'     => 'Member One',
				'status'   => 'active',
				'ecf_code' => '111111A',
			)
		);
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'     => 'Guest Two',
				'status'   => 'nonmember',
				'ecf_code' => '222222B',
			)
		);
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'     => 'Lapsed Three',
				'status'   => 'cancelled',
				'ecf_code' => '333333C',
			)
		);
		$this->mock_http(
			array(
				'success' => true,
				'data'    => array(
					'games' => array(
						array(
							'game_date'       => gmdate( 'Y-m-d' ),
							'score'           => '1',
							'opponent_name'   => 'Secret Opponent',
							'opponent_rating' => 1234,
							'colour'          => 'W',
							'event_name'      => 'League',
						),
					),
				),
			)
		);

		$html = do_blocks( '<!-- wp:chess-army-knife/club-results /-->' );

		$this->assertStringContainsString( 'Member One', $html );
		$this->assertStringNotContainsString( 'Guest Two', $html );
		$this->assertStringNotContainsString( 'Lapsed Three', $html );
		$this->assertStringNotContainsString( 'Secret Opponent', $html, 'Opponents are people the club holds no record of.' );
		$this->assertStringNotContainsString( '1234', $html );
		foreach ( $this->http_requests as $url ) {
			$this->assertStringNotContainsString( '/clubs/', $url, 'The ECF club roster is never fetched.' );
			$this->assertStringNotContainsString( '222222', $url );
			$this->assertStringNotContainsString( '333333', $url );
		}
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

	public function test_featured_player_block_shows_a_current_member_with_their_own_blurb() {
		$this->mock_http( array() );
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'     => 'Jane Doe',
				'status'   => 'active',
				'ecf_code' => '120787J',
				'blurb'    => 'Club champion',
			)
		);

		$html = do_blocks( '<!-- wp:chess-army-knife/featured-player {"playerCode":"120787J"} /-->' );

		$this->assertStringContainsString( 'Jane Doe', $html, 'The name is written the way the site chooses.' );
		$this->assertStringContainsString( 'Featured Player', $html, 'The default title.' );
		$this->assertStringContainsString( 'Club champion', $html, 'The member\'s own blurb.' );
	}

	public function test_featured_player_block_only_features_current_members() {
		$this->mock_http( array() );
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'     => 'Gary Guest',
				'status'   => 'nonmember',
				'ecf_code' => '555555K',
			)
		);

		$html = do_blocks( '<!-- wp:chess-army-knife/featured-player {"playerCode":"555555K","playerName":"Gary Guest"} /-->' );

		$this->assertStringNotContainsString( 'Gary Guest', $html );
		$this->assertStringContainsString( 'chess-army-knife-notice', $html );
	}

	public function test_block_shows_notice_when_unconfigured() {
		$html = do_blocks( '<!-- wp:chess-army-knife/featured-player /-->' );

		$this->assertStringContainsString( 'chess-army-knife-notice', $html );
	}

	public function test_settings_are_sanitised_on_save() {
		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$clean = Chess_Army_Knife_Settings::sanitize(
			array(
				'default_org_id' => '12ab',
			)
		);

		$this->assertSame( '12', $clean['default_org_id'] );
	}
}
