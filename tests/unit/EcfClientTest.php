<?php
/**
 * Tests for ECF_Client.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class EcfClientTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Use transients so no database is needed.
		$this->set_settings( array( 'use_local_cache' => 0 ) );
	}

	public function test_normalise_code_keeps_digits_only() {
		$this->assertSame( '120787', ECF_Client::normalise_code( ' 120787J ' ) );
		$this->assertSame( '', ECF_Client::normalise_code( 'abc' ) );
	}

	public function test_normalise_domain_accepts_valid_and_defaults_to_standard() {
		$this->assertSame( 'RW', ECF_Client::normalise_domain( ' rw ' ) );
		$this->assertSame( 'S', ECF_Client::normalise_domain( 'nonsense' ) );
		$this->assertSame( 'S', ECF_Client::normalise_domain( '' ) );
	}

	public function test_cache_keys_are_normalised() {
		$this->assertSame( 'ecf_games_120787_R_100', ECF_Client::cache_key_games( '120787J', 'r', 100 ) );
		$this->assertSame( 'ecf_player_120787', ECF_Client::cache_key_player( '120787J' ) );
		$this->assertSame( 'ecf_rating_120787_S', ECF_Client::cache_key_rating( '120787J', 'bogus' ) );
		$this->assertSame( 'ecf_club_players_9BAJ', ECF_Client::cache_key_club_players( ' 9baj ' ) );
	}

	public function test_missing_identifiers_return_errors_without_http() {
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( 'ecf_bad_code', ECF_Client::get_rating( '' )->get_error_code() );
		$this->assertSame( 'ecf_bad_code', ECF_Client::get_player_by_code( 'xyz' )->get_error_code() );
		$this->assertSame( 'ecf_bad_code', ECF_Client::get_games( '' )->get_error_code() );
		$this->assertSame( 'ecf_bad_club', ECF_Client::get_club_players( '  ' )->get_error_code() );
		$this->assertSame( 'ecf_bad_club', ECF_Client::get_club_info( '' )->get_error_code() );
	}

	public function test_short_searches_return_empty_without_http() {
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( array(), ECF_Client::search_players( 'ab' ) );
		$this->assertSame( array(), ECF_Client::search_clubs( ' a ' ) );
	}

	public function test_get_games_unwraps_envelope_and_sends_normalised_query() {
		Functions\expect( 'wp_remote_get' )
			->once()
			->with(
				\Mockery::on(
					function ( $url ) {
						return false !== strpos( $url, 'https://rating.englishchess.org.uk/api/games?' )
							&& false !== strpos( $url, 'player_no=120787' )
							&& false !== strpos( $url, 'domain=R' )
							&& false !== strpos( $url, 'limit=500' );
					}
				),
				\Mockery::type( 'array' )
			)
			->andReturn(
				$this->response(
					200,
					array(
						'success' => true,
						'data'    => array( 'games' => array( array( 'id' => 1 ) ) ),
					)
				)
			);

		// Limit above the cap is clamped to 500.
		$this->assertSame( array( array( 'id' => 1 ) ), ECF_Client::get_games( '120787J', 'r', 9999 ) );
	}

	public function test_successful_response_is_cached() {
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn(
				$this->response(
					200,
					array(
						'success' => true,
						'data'    => array( 'games' => array() ),
					)
				)
			);

		ECF_Client::get_games( '120787' );
		ECF_Client::get_games( '120787' );
		$this->assertTrue( true ); // The once() expectation is the assertion.
	}

	public function test_club_players_returns_columns_and_players() {
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response(
				200,
				array(
					'success' => true,
					'data'    => array(
						'column_names' => array( 'name', 'rating' ),
						'players'      => array( array( 'A', 1500 ) ),
					),
				)
			)
		);

		$this->assertSame(
			array(
				'columns' => array( 'name', 'rating' ),
				'players' => array( array( 'A', 1500 ) ),
			),
			ECF_Client::get_club_players( '9BAJ' )
		);
	}

	public function test_transport_error_is_returned_and_cached_briefly() {
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'timeout' ) );

		$result = ECF_Client::get_club_info( '9BAJ' );

		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$ttls = array_column( $this->transients, 'ttl' );
		$this->assertSame( array( 2 * MINUTE_IN_SECONDS ), $ttls );
	}

	public function test_unparseable_body_is_an_error() {
		Functions\when( 'wp_remote_get' )->justReturn( $this->response( 200, '<html>oops' ) );

		$this->assertSame( 'ecf_bad_response', ECF_Client::get_player_by_code( '120787' )->get_error_code() );
	}

	public function test_api_failure_uses_service_message() {
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response(
				200,
				array(
					'success' => false,
					'message' => 'No such player',
				)
			)
		);

		$result = ECF_Client::get_player_by_code( '120787' );

		$this->assertSame( 'ecf_api_error', $result->get_error_code() );
		$this->assertSame( 'No such player', $result->get_error_message() );
	}

	public function test_http_error_status_is_an_error() {
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response(
				500,
				array(
					'success' => true,
					'data'    => array(),
				)
			)
		);

		$result = ECF_Client::get_rating( '120787' );

		$this->assertSame( 'ecf_api_error', $result->get_error_code() );
		$this->assertSame( array( 'status' => 500 ), $result->get_error_data() );
	}

	public function test_search_clubs_falls_back_to_raw_result_when_no_clubs_key() {
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response(
				200,
				array(
					'success' => true,
					'data'    => array( 'x' => 1 ),
				)
			)
		);

		$this->assertSame( array( 'x' => 1 ), ECF_Client::search_clubs( 'chess' ) );
	}

	public function test_cache_ttl_follows_settings_with_one_minute_floor() {
		$this->set_settings(
			array(
				'use_local_cache'   => 0,
				'cache_ecf_minutes' => 90,
			)
		);
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response(
				200,
				array(
					'success' => true,
					'data'    => array( 'games' => array() ),
				)
			)
		);

		ECF_Client::get_games( '120787' );

		$this->assertSame( array( 90 * MINUTE_IN_SECONDS ), array_column( $this->transients, 'ttl' ) );
	}
}
