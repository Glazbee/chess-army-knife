<?php
/**
 * Tests for Chess_Army_Knife_LMS_Client: request fallbacks, error handling and payload normalisation.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class LmsClientTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->set_settings(
			array(
				'use_local_cache'    => 0,
				'fast_cache_enabled' => 0,
			)
		);
	}

	/**
	 * Queue responses for successive wp_remote_request() calls and record the calls.
	 */
	private function queue_responses( array $responses, array &$calls = array() ) {
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$responses, &$calls ) {
				$calls[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array_shift( $responses );
			}
		);
	}

	public function test_cache_key_is_normalised() {
		$this->assertSame( 'lms2_table_12_division 1', Chess_Army_Knife_LMS_Client::cache_key( 'table', ' 12 ', ' Division 1 ' ) );
	}

	public function test_missing_params_return_error_without_http() {
		Functions\expect( 'wp_remote_request' )->never();

		$this->assertSame( 'lms_missing_params', Chess_Army_Knife_LMS_Client::get_table( '', 'Div 1' )->get_error_code() );
		$this->assertSame( 'lms_missing_params', Chess_Army_Knife_LMS_Client::get_matches( '12', ' ' )->get_error_code() );
	}

	public function test_first_attempt_is_json_post_to_bare_type_url() {
		$calls = array();
		$this->queue_responses( array( $this->response( 200, array( 'table' => array( array( 'team' => 'A' ) ) ) ) ), $calls );

		$result = Chess_Army_Knife_LMS_Client::get_table( '12', 'Division 1' );

		$this->assertSame( array( 'table' => array( array( 'team' => 'A' ) ) ), $result );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'https://lms.englishchess.org.uk/lms/lmsrest/league/table', $calls[0]['url'] );
		$this->assertSame( 'POST', $calls[0]['args']['method'] );
		$this->assertSame( 'application/json', $calls[0]['args']['headers']['Content-Type'] );
		$this->assertSame(
			array(
				'org'  => '12',
				'name' => 'Division 1',
			),
			json_decode( $calls[0]['args']['body'], true )
		);
	}

	public function test_media_type_and_method_errors_fall_through_to_next_shape() {
		$calls = array();
		$this->queue_responses(
			array(
				$this->response( 415, '' ),
				$this->response( 405, '' ),
				$this->response( 200, array( 'rows' => array( array( 'a' => 1 ) ) ) ),
			),
			$calls
		);

		$result = Chess_Army_Knife_LMS_Client::get_events( '12', 'Division 1' );

		$this->assertSame( array( 'rows' => array( array( 'a' => 1 ) ) ), $result );
		$this->assertCount( 3, $calls );
		$this->assertSame( 'GET', $calls[2]['args']['method'] );
		$this->assertStringContainsString( 'org=12', $calls[2]['url'] );
	}

	public function test_http_error_stops_without_further_attempts_and_carries_debug() {
		$calls = array();
		$this->queue_responses( array( $this->response( 404, 'nope' ) ), $calls );

		$result = Chess_Army_Knife_LMS_Client::get_table( '12', 'Division 1' );

		$this->assertSame( 'lms_http_error', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertCount( 1, $calls );
	}

	public function test_error_reply_with_200_status_is_an_error() {
		$this->queue_responses( array( $this->response( 200, array( 'error' => 'invalid type table.json' ) ) ) );

		$result = Chess_Army_Knife_LMS_Client::get_table( '12', 'Division 1' );

		$this->assertSame( 'lms_api_error', $result->get_error_code() );
		$this->assertStringContainsString( 'invalid type table.json', $result->get_error_message() );
	}

	public function test_unparseable_and_empty_bodies_are_errors() {
		$this->queue_responses( array( $this->response( 200, 'not json' ) ) );
		$this->assertSame( 'lms_bad_response', Chess_Army_Knife_LMS_Client::get_table( '12', 'A' )->get_error_code() );

		$this->transients = array();
		$this->queue_responses( array( $this->response( 200, array() ) ) );
		$this->assertSame( 'lms_empty', Chess_Army_Knife_LMS_Client::get_table( '12', 'B' )->get_error_code() );
	}

	public function test_connection_failure_tries_every_shape_then_falls_back_to_legacy_host() {
		$calls = array();
		// Primary host: 3 shapes fail to connect; legacy host then succeeds.
		$fail = new WP_Error( 'http_request_failed', 'Could not resolve host' );
		$this->queue_responses(
			array( $fail, $fail, $fail, $this->response( 200, array( 'table' => array( array( 'team' => 'A' ) ) ) ) ),
			$calls
		);

		$result = Chess_Army_Knife_LMS_Client::get_table( '12', 'Division 1' );

		$this->assertArrayHasKey( 'table', $result );
		$this->assertCount( 4, $calls );
		$this->assertStringStartsWith( 'https://ecflms.org.uk/', $calls[3]['url'] );
	}

	public function test_all_hosts_failing_returns_connection_error() {
		$this->queue_responses( array_fill( 0, 6, new WP_Error( 'http_request_failed', 'down' ) ) );

		$this->assertSame( 'lms_connection_error', Chess_Army_Knife_LMS_Client::get_table( '12', 'Division 1' )->get_error_code() );
	}

	public function test_results_are_cached() {
		$calls = array();
		$this->queue_responses( array( $this->response( 200, array( 'table' => array( array( 'team' => 'A' ) ) ) ) ), $calls );

		Chess_Army_Knife_LMS_Client::get_table( '12', 'Division 1' );
		Chess_Army_Knife_LMS_Client::get_table( '12', 'Division 1' );

		$this->assertCount( 1, $calls );
	}

	public function test_pick_returns_first_non_empty_candidate() {
		$row = array(
			'a' => '',
			'b' => null,
			'c' => 0,
			'd' => 'x',
		);

		$this->assertSame( 0, Chess_Army_Knife_LMS_Client::pick( $row, array( 'a', 'b', 'c', 'd' ) ) );
		$this->assertSame( 'x', Chess_Army_Knife_LMS_Client::pick( $row, array( 'a', 'd' ) ) );
		$this->assertSame( 'fb', Chess_Army_Knife_LMS_Client::pick( $row, array( 'a', 'zzz' ), 'fb' ) );
		$this->assertSame( 'fb', Chess_Army_Knife_LMS_Client::pick( 'not an array', array( 'a' ), 'fb' ) );
	}

	public function test_is_list() {
		$this->assertTrue( Chess_Army_Knife_LMS_Client::is_list( array( 'a', 'b' ) ) );
		$this->assertFalse( Chess_Army_Knife_LMS_Client::is_list( array( 'k' => 'v' ) ) );
		$this->assertFalse( Chess_Army_Knife_LMS_Client::is_list( 'str' ) );
	}

	public function test_find_rows_handles_wrapped_bare_and_nested_payloads() {
		$rows = array( array( 'team' => 'A' ) );

		$this->assertSame( $rows, Chess_Army_Knife_LMS_Client::find_rows( array( 'table' => $rows ) ) );
		$this->assertSame( $rows, Chess_Army_Knife_LMS_Client::find_rows( array( 'custom' => $rows ), array( 'custom' ) ) );
		$this->assertSame( $rows, Chess_Army_Knife_LMS_Client::find_rows( $rows ) );
		$this->assertSame( $rows, Chess_Army_Knife_LMS_Client::find_rows( array( 'weird' => $rows ) ) );
		$this->assertSame( array(), Chess_Army_Knife_LMS_Client::find_rows( array( 'weird' => 'x' ) ) );
		$this->assertSame( array(), Chess_Army_Knife_LMS_Client::find_rows( 'nope' ) );
	}

	public function test_normalise_table_row_flat() {
		$row = Chess_Army_Knife_LMS_Client::normalise_table_row(
			array(
				'pos'  => 1,
				'team' => 'Alpha',
				'p'    => 5,
				'w'    => 3,
				'd'    => 1,
				'l'    => 1,
				'pts'  => 7,
			)
		);

		$this->assertSame(
			array(
				'position' => '1',
				'team'     => 'Alpha',
				'played'   => '5',
				'won'      => '3',
				'drawn'    => '1',
				'lost'     => '1',
				'points'   => '7',
			),
			$row
		);
	}

	public function test_normalise_table_row_nested_entry_with_team_object() {
		$row = Chess_Army_Knife_LMS_Client::normalise_table_row(
			array(
				'position' => 2,
				'entry'    => array(
					'team'   => array( 'name' => 'Beta' ),
					'played' => 4,
					'points' => 6,
				),
			)
		);

		$this->assertSame( '2', $row['position'] );
		$this->assertSame( 'Beta', $row['team'] );
		$this->assertSame( '4', $row['played'] );
		$this->assertSame( '6', $row['points'] );
		$this->assertSame( '', $row['won'] );
	}

	public function test_normalise_rows_reject_non_arrays() {
		$this->assertNull( Chess_Army_Knife_LMS_Client::normalise_table_row( 'x' ) );
		$this->assertNull( Chess_Army_Knife_LMS_Client::normalise_match_row( 'x' ) );
	}

	public function test_normalise_match_row() {
		$row = Chess_Army_Knife_LMS_Client::normalise_match_row(
			array(
				'date'        => '2026-01-15',
				'left'        => array( 'name' => 'Alpha' ),
				'away'        => 'Beta',
				'left_score'  => 2.5,
				'right_score' => 1.5,
				'venue'       => array( 'name' => 'Town Hall' ),
			)
		);

		$this->assertSame(
			array(
				'venue'       => 'Town Hall',
				'date'        => '2026-01-15',
				'time'        => '',
				'home'        => 'Alpha',
				'away'        => 'Beta',
				'home_score'  => '2.5',
				'away_score'  => '1.5',
				'result_text' => '',
			),
			$row
		);
	}

	public function test_normalise_match_row_uses_result_text_fallback() {
		$row = Chess_Army_Knife_LMS_Client::normalise_match_row(
			array(
				'home'   => 'A',
				'away'   => 'B',
				'result' => '3-1',
			)
		);

		$this->assertSame( '3-1', $row['result_text'] );
		$this->assertSame( '', $row['home_score'] );
	}

	/**
	 * Answer v2 requests from a map of path (after the v2 base) => body, recording the calls.
	 */
	private function serve_v2( array $bodies, array &$calls = array() ) {
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) use ( $bodies, &$calls ) {
				$calls[] = array(
					'url'  => $url,
					'args' => $args,
				);
				$path    = substr( $url, strlen( Chess_Army_Knife_LMS_Client::V2_BASE ) + 1 );
				return isset( $bodies[ $path ] ) ? $this->response( 200, $bodies[ $path ] ) : $this->response( 404, array( 'error' => 'nope' ) );
			}
		);
	}

	private function v2_league() {
		return array(
			'org/702/seasons'  => array(
				'seasons' => array(
					array(
						'id'     => 1,
						'name'   => '2024-25',
						'status' => 'old',
					),
					array(
						'id'     => 2,
						'name'   => '2025-26',
						'status' => 'active',
					),
				),
			),
			'season/2/events'  => array(
				'events' => array(
					array(
						'id'   => 20,
						'name' => 'Division Two',
						'type' => 'team_league',
					),
					array(
						'id'   => 21,
						'name' => 'Division One',
						'type' => 'team_league',
					),
				),
			),
			'event/21/results' => array(
				'event_name' => 'Division One',
				'event_type' => 'team_league',
				'fixtures'   => array(
					array(
						'fixture_id' => 5,
						'round'      => 1,
						'date'       => '2026-10-05',
						'time'       => '19:30',
						'home_team'  => 'Central Birmingham-1',
						'away_team'  => 'Solihull-1',
						'games'      => array(),
					),
					array(
						'fixture_id' => 6,
						'round'      => 2,
						'date'       => null,
						'time'       => null,
						'home_team'  => 'Solihull-1',
						'away_team'  => 'Central Birmingham-1',
						'games'      => array(),
					),
				),
			),
		);
	}

	public function test_fixtures_need_an_api_key_and_make_no_request_without_one() {
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( 'lms_no_api_key', Chess_Army_Knife_LMS_Client::get_fixtures( '702', 'Division One' )->get_error_code() );
	}

	public function test_fixtures_are_found_through_the_active_season_and_named_event() {
		$this->set_settings(
			array(
				'lms_api_key'        => 'lmsk_secret',
				'use_local_cache'    => 0,
				'fast_cache_enabled' => 0,
			)
		);
		$calls = array();
		$this->serve_v2( $this->v2_league(), $calls );

		$rows = Chess_Army_Knife_LMS_Client::get_fixtures( '702', ' division  one ' );

		$this->assertCount( 3, $calls );
		$this->assertSame( 'Bearer lmsk_secret', $calls[0]['args']['headers']['Authorization'] );
		$this->assertSame( Chess_Army_Knife_LMS_Client::V2_BASE . '/event/21/results', $calls[2]['url'] );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'Central Birmingham-1', $rows[0]['home'] );
		$this->assertSame( 'Solihull-1', $rows[0]['away'] );
		$this->assertSame( '2026-10-05', $rows[0]['date'] );
		$this->assertSame( '19:30', $rows[0]['time'] );
		$this->assertSame( '', $rows[1]['date'], 'An unscheduled fixture has no date.' );
	}

	public function test_an_event_missing_from_the_active_season_is_an_error() {
		$this->set_settings(
			array(
				'lms_api_key'        => 'lmsk_secret',
				'use_local_cache'    => 0,
				'fast_cache_enabled' => 0,
			)
		);
		$this->serve_v2( $this->v2_league() );

		$this->assertSame( 'lms_event_not_found', Chess_Army_Knife_LMS_Client::get_fixtures( '702', 'Division Three' )->get_error_code() );
	}

	public function test_a_rejected_key_is_reported() {
		$this->set_settings(
			array(
				'lms_api_key'        => 'wrong',
				'use_local_cache'    => 0,
				'fast_cache_enabled' => 0,
			)
		);
		Functions\when( 'wp_remote_get' )->justReturn( $this->response( 401, array( 'error' => 'Unauthenticated' ) ) );

		$this->assertSame( 'lms_unauthorised', Chess_Army_Knife_LMS_Client::get_fixtures( '702', 'Division One' )->get_error_code() );
	}

	public function test_an_unknown_organisation_is_reported() {
		$this->set_settings(
			array(
				'lms_api_key'        => 'lmsk_secret',
				'use_local_cache'    => 0,
				'fast_cache_enabled' => 0,
			)
		);
		$this->serve_v2( array() );

		$this->assertSame( 'lms_not_found', Chess_Army_Knife_LMS_Client::get_fixtures( '999', 'Division One' )->get_error_code() );
	}

	public function test_a_fixture_lists_the_players_who_played_by_their_ecf_code() {
		$row = Chess_Army_Knife_LMS_Client::normalise_fixture(
			array(
				'date'      => '2026-10-05',
				'home_team' => 'Our A',
				'away_team' => 'Rivals',
				'games'     => array(
					array(
						'board'       => 1,
						'home_player' => array(
							'lms_id'      => 7,
							'rating_code' => '123456A',
							'name'        => 'Lovelace, Ada',
						),
						'away_player' => array(
							'lms_id'      => 8,
							'rating_code' => null,
							'name'        => 'Unrated, Una',
						),
					),
					array(
						'board'       => 2,
						'home_player' => array(
							'lms_id'      => -1,
							'rating_code' => '000000X',
							'name'        => 'Default',
						),
						'away_player' => array(
							'lms_id'      => 9,
							'rating_code' => '654321B',
							'name'        => 'Babbage, Bea',
						),
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'side' => 'home',
					'code' => '123456A',
					'name' => 'Lovelace, Ada',
				),
				array(
					'side' => 'away',
					'code' => '654321B',
					'name' => 'Babbage, Bea',
				),
			),
			$row['players'],
			'A player with no code, and a default slot, are left out.'
		);
	}

	public function test_a_fixture_with_no_games_has_no_players() {
		$this->assertSame(
			array(),
			Chess_Army_Knife_LMS_Client::normalise_fixture(
				array(
					'home_team' => 'A',
					'away_team' => 'B',
				)
			)['players']
		);
	}

	public function test_an_earlier_season_can_be_asked_for_by_name_and_is_cached_for_longer() {
		$this->set_settings(
			array(
				'lms_api_key'        => 'lmsk_secret',
				'use_local_cache'    => 0,
				'fast_cache_enabled' => 0,
			)
		);
		$bodies                     = $this->v2_league();
		$bodies['season/1/events']  = array(
			'events' => array(
				array(
					'id'   => 10,
					'name' => 'Division One',
					'type' => 'team_league',
				),
			),
		);
		$bodies['event/10/results'] = array(
			'fixtures' => array(
				array(
					'fixture_id' => 1,
					'date'       => '2025-01-10',
					'home_team'  => 'Old A',
					'away_team'  => 'Old B',
					'home_score' => 3,
					'away_score' => 2,
					'winner'     => 'home',
					'games'      => array(),
				),
			),
		);
		$calls                      = array();
		$this->serve_v2( $bodies, $calls );

		$rows = Chess_Army_Knife_LMS_Client::get_fixtures( '702', 'Division One', false, '2024-25' );

		$this->assertSame( 'Old A', $rows[0]['home'] );
		$this->assertSame( '3', $rows[0]['home_score'] );
		$this->assertSame( Chess_Army_Knife_LMS_Client::V2_BASE . '/event/10/results', $calls[2]['url'], 'The old season, not the active one.' );
		$this->assertGreaterThan( DAY_IN_SECONDS, Chess_Army_Knife_LMS_Client::V2_HISTORY_TTL );
	}

	public function test_the_seasons_of_an_organisation_are_listed() {
		$this->set_settings(
			array(
				'lms_api_key'        => 'lmsk_secret',
				'use_local_cache'    => 0,
				'fast_cache_enabled' => 0,
			)
		);
		$this->serve_v2( $this->v2_league() );

		$seasons = Chess_Army_Knife_LMS_Client::get_seasons( '702' );

		$this->assertSame( array( 1, 2 ), array_column( $seasons, 'id' ) );
		$this->assertSame( array( 'old', 'active' ), array_column( $seasons, 'status' ) );
	}
}
