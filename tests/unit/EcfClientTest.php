<?php
/**
 * Tests for Chess_Army_Knife_ECF_Client.
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
		$this->assertSame( '120787', Chess_Army_Knife_ECF_Client::normalise_code( ' 120787J ' ) );
		$this->assertSame( '', Chess_Army_Knife_ECF_Client::normalise_code( 'abc' ) );
	}

	public function test_normalise_domain_accepts_valid_and_defaults_to_standard() {
		$this->assertSame( 'RW', Chess_Army_Knife_ECF_Client::normalise_domain( ' rw ' ) );
		$this->assertSame( 'S', Chess_Army_Knife_ECF_Client::normalise_domain( 'nonsense' ) );
		$this->assertSame( 'S', Chess_Army_Knife_ECF_Client::normalise_domain( '' ) );
	}

	public function test_cache_keys_are_normalised() {
		$this->assertSame( 'ecf_games_120787_R_100', Chess_Army_Knife_ECF_Client::cache_key_games( '120787J', 'r', 100 ) );
		$this->assertSame( 'ecf_player_120787', Chess_Army_Knife_ECF_Client::cache_key_player( '120787J' ) );
		$this->assertSame( 'ecf_rating_120787_S', Chess_Army_Knife_ECF_Client::cache_key_rating( '120787J', 'bogus' ) );
	}

	public function test_missing_identifiers_return_errors_without_http() {
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( 'ecf_bad_code', Chess_Army_Knife_ECF_Client::get_rating( '' )->get_error_code() );
		$this->assertSame( 'ecf_bad_code', Chess_Army_Knife_ECF_Client::get_player_by_code( 'xyz' )->get_error_code() );
		$this->assertSame( 'ecf_bad_code', Chess_Army_Knife_ECF_Client::get_games( '' )->get_error_code() );
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
		$this->assertSame( array( array( 'id' => 1 ) ), Chess_Army_Knife_ECF_Client::get_games( '120787J', 'r', 9999 ) );
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

		Chess_Army_Knife_ECF_Client::get_games( '120787' );
		Chess_Army_Knife_ECF_Client::get_games( '120787' );
		$this->assertTrue( true ); // The once() expectation is the assertion.
	}

	public function test_transport_error_is_returned_and_cached_briefly() {
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'timeout' ) );

		$result = Chess_Army_Knife_ECF_Client::get_player_by_code( '120787' );

		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$ttls = array_column( $this->transients, 'ttl' );
		$this->assertSame( array( 2 * MINUTE_IN_SECONDS ), $ttls );
	}

	public function test_unparseable_body_is_an_error() {
		Functions\when( 'wp_remote_get' )->justReturn( $this->response( 200, '<html>oops' ) );

		$this->assertSame( 'ecf_bad_response', Chess_Army_Knife_ECF_Client::get_player_by_code( '120787' )->get_error_code() );
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

		$result = Chess_Army_Knife_ECF_Client::get_player_by_code( '120787' );

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

		$result = Chess_Army_Knife_ECF_Client::get_rating( '120787' );

		$this->assertSame( 'ecf_api_error', $result->get_error_code() );
		$this->assertSame( array( 'status' => 500 ), $result->get_error_data() );
	}

	public function test_no_player_is_fetched_from_the_ecf_unless_the_clubs_records_allow_it() {
		Functions\expect( 'wp_remote_get' )->never();
		$asked = array();
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value, ...$args ) use ( &$asked ) {
				$asked[] = array( $hook, $args );
				return 'Chess_Army_Knife_before_ecf_player_lookup' === $hook ? new WP_Error( 'ecf_unrecorded', 'Not on record.' ) : $value;
			}
		);

		$this->assertSame( 'ecf_unrecorded', Chess_Army_Knife_ECF_Client::get_rating( '120787J' )->get_error_code() );
		$this->assertSame( 'ecf_unrecorded', Chess_Army_Knife_ECF_Client::get_games( '120787J' )->get_error_code() );
		$this->assertSame( 'ecf_unrecorded', Chess_Army_Knife_ECF_Client::get_player_by_code( '120787J' )->get_error_code() );
		$this->assertSame( array( array( 'Chess_Army_Knife_before_ecf_player_lookup', array( '120787' ) ) ), array_slice( $asked, 0, 1 ), 'The gate is asked about the normalised code.' );
	}

	public function test_the_lookup_that_records_a_new_player_skips_the_gate() {
		$gated = false;
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) use ( &$gated ) {
				$gated = $gated || 'Chess_Army_Knife_before_ecf_player_lookup' === $hook;
				return $value;
			}
		);
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response(
				200,
				array(
					'success' => true,
					'data'    => array( 'full_name' => 'Test Player' ),
				)
			)
		);

		$player = Chess_Army_Knife_ECF_Client::get_player_by_code( '120787J', false );

		$this->assertSame( array( 'full_name' => 'Test Player' ), $player );
		$this->assertFalse( $gated );
	}

	private function roster_response( array $rows, array $columns = array( 'ECF_code', 'full_name', 'std', 'rpd', 'btz' ) ) {
		return $this->response(
			200,
			array(
				'success' => true,
				'data'    => array(
					'column_names' => $columns,
					'players'      => $rows,
				),
			)
		);
	}

	public function test_the_club_roster_is_fetched_once_and_only_the_wanted_players_are_kept() {
		Functions\expect( 'wp_remote_get' )
			->once()
			->with(
				\Mockery::on(
					function ( $url ) {
						return false !== strpos( $url, 'https://rating.englishchess.org.uk/api/clubs/players?' ) && false !== strpos( $url, 'code=4USL' );
					}
				),
				\Mockery::any()
			)
			->andReturn(
				$this->roster_response(
					array(
						array( '100001A', 'Wanted One', 1650, 1500, 1400 ),
						array( '100002B', 'Wanted Two', null, 1510, 0 ),
						array( '999999Z', 'Not Recorded', 2100, 2000, 1900 ),
					)
				)
			);

		$ratings = Chess_Army_Knife_ECF_Client::get_club_ratings( ' 4usl ', 's', array( '100001A', '100002', '555555X' ) );

		$this->assertSame(
			array(
				'100001' => 1650,
				'100002' => null,
			),
			$ratings,
			'Someone not asked for is dropped; an unrated player is listed with no rating; a wanted player the roster lacks is absent.'
		);
		$this->assertSame( array(), $this->transients, 'The roster is never cached.' );
	}

	public function test_the_roster_column_follows_the_rating_list() {
		Functions\when( 'wp_remote_get' )->justReturn( $this->roster_response( array( array( '100001A', 'One', 1650, 1500, 1400 ) ) ) );

		$this->assertSame( array( '100001' => 1500 ), Chess_Army_Knife_ECF_Client::get_club_ratings( '4USL', 'R', array( '100001' ) ) );
		$this->assertSame( array( '100001' => 1400 ), Chess_Army_Knife_ECF_Client::get_club_ratings( '4USL', 'B', array( '100001' ) ) );
	}

	public function test_the_roster_refuses_without_a_request_when_it_cannot_help() {
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( 'ecf_bad_club', Chess_Army_Knife_ECF_Client::get_club_ratings( '  ', 'S', array( '1' ) )->get_error_code() );
		$this->assertSame( 'ecf_roster_domain', Chess_Army_Knife_ECF_Client::get_club_ratings( '4USL', 'SW', array( '1' ) )->get_error_code() );
	}

	public function test_a_roster_without_the_expected_columns_is_an_error_and_nothing_leaks() {
		Functions\when( 'wp_remote_get' )->justReturn( $this->roster_response( array( array( 'x', 'y' ) ), array( 'name', 'other' ) ) );

		$this->assertSame( 'ecf_bad_response', Chess_Army_Knife_ECF_Client::get_club_ratings( '4USL', 'S', array( '100001' ) )->get_error_code() );
	}

	public function test_roster_errors_are_passed_on() {
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'timeout' ) );

		$this->assertSame( 'http_request_failed', Chess_Army_Knife_ECF_Client::get_club_ratings( '4USL', 'S', array( '100001' ) )->get_error_code() );
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

		Chess_Army_Knife_ECF_Client::get_games( '120787' );

		$this->assertSame( array( 90 * MINUTE_IN_SECONDS ), array_column( $this->transients, 'ttl' ) );
	}
}
