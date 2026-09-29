<?php
/**
 * Tests for Lichess_Client and the Lichess Live block's slide/REST helpers.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class LichessClientTest extends Chess_Army_Knife_TestCase {

	/** @var array URLs requested through wp_remote_get(). */
	private $urls = array();

	protected function setUp(): void {
		parent::setUp();
		$this->set_settings( array( 'use_local_cache' => 0 ) );
		Functions\when( 'wp_hash' )->alias( 'md5' );
	}

	/**
	 * Queue responses for successive wp_remote_get() calls and record the URLs.
	 */
	private function queue_responses( array $responses ) {
		$this->urls = array();
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) use ( &$responses ) {
				$this->urls[] = $url;
				return array_shift( $responses );
			}
		);
	}

	private function status_body( array $users ) {
		return $this->response( 200, $users );
	}

	private function game_body( array $overrides = array() ) {
		return $this->response(
			200,
			array_merge(
				array(
					'id'      => 'abcd1234',
					'rated'   => true,
					'speed'   => 'rapid',
					'status'  => 'started',
					'clock'   => array(
						'initial'   => 600,
						'increment' => 5,
					),
					'players' => array(
						'white' => array(
							'user'   => array( 'name' => 'Alice' ),
							'rating' => 1650,
						),
						'black' => array(
							'user'   => array( 'name' => 'Bob' ),
							'rating' => 1580,
						),
					),
				),
				$overrides
			)
		);
	}

	public function test_parse_usernames_cleans_dedupes_and_sorts() {
		$this->assertSame(
			array( 'alice', 'bob_2', 'carol-x' ),
			Lichess_Client::parse_usernames( "Bob_2\nALICE, alice  carol-x\n<script>\nx\n" )
		);
	}

	public function test_parse_usernames_caps_the_list() {
		$raw = implode(
			"\n",
			array_map(
				function ( $i ) {
					return 'user' . $i;
				},
				range( 1, 150 )
			)
		);

		$this->assertCount( Lichess_Client::MAX_USERNAMES, Lichess_Client::parse_usernames( $raw ) );
	}

	public function test_empty_list_makes_no_request() {
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( array(), Lichess_Client::get_live_games( array() ) );
	}

	public function test_live_games_are_found_and_normalised() {
		$this->queue_responses(
			array(
				// Two club members playing each other share one game.
				$this->status_body(
					array(
						array(
							'id'        => 'alice',
							'name'      => 'Alice',
							'playingId' => 'abcd1234',
						),
						array(
							'id'        => 'bob',
							'name'      => 'Bob',
							'playingId' => 'abcd1234',
						),
						array(
							'id'   => 'carol',
							'name' => 'Carol',
						),
					)
				),
				$this->game_body(),
			)
		);

		$games = Lichess_Client::get_live_games( array( 'alice', 'bob', 'carol' ) );

		$this->assertCount( 1, $games );
		$this->assertSame( 'abcd1234', $games[0]['id'] );
		$this->assertSame( 'https://lichess.org/abcd1234', $games[0]['url'] );
		$this->assertSame( 'https://lichess.org/embed/game/abcd1234', $games[0]['embed_url'] );
		$this->assertSame( array( 'Alice', 'Bob' ), $games[0]['members'] );
		$this->assertSame(
			array(
				'name'   => 'Alice',
				'rating' => 1650,
			),
			$games[0]['white']
		);
		$this->assertSame( '10+5', $games[0]['time_control'] );
		$this->assertSame( 'rapid', $games[0]['speed'] );
		$this->assertTrue( $games[0]['rated'] );

		$this->assertStringContainsString( 'https://lichess.org/api/users/status?', $this->urls[0] );
		$this->assertStringContainsString( 'ids=alice%2Cbob%2Ccarol', $this->urls[0] );
		$this->assertStringContainsString( 'withGameIds=true', $this->urls[0] );
		$this->assertStringContainsString( 'https://lichess.org/game/export/abcd1234', $this->urls[1] );
	}

	public function test_nobody_playing_returns_empty_list() {
		$this->queue_responses(
			array(
				$this->status_body(
					array(
						array(
							'id'     => 'alice',
							'name'   => 'Alice',
							'online' => true,
						),
					)
				),
			)
		);

		$this->assertSame( array(), Lichess_Client::get_live_games( array( 'alice' ) ) );
		$this->assertCount( 1, $this->urls );
	}

	public function test_result_is_cached_briefly_and_shared() {
		$this->queue_responses( array( $this->status_body( array() ) ) );

		Lichess_Client::get_live_games( array( 'alice' ) );
		Lichess_Client::get_live_games( array( 'alice' ) );

		$this->assertCount( 1, $this->urls );

		$entry = reset( $this->transients );
		$this->assertSame( Lichess_Client::CACHE_SECONDS, $entry['ttl'] );
	}

	public function test_invalid_game_ids_are_ignored() {
		$this->queue_responses(
			array(
				$this->status_body(
					array(
						array(
							'id'        => 'alice',
							'name'      => 'Alice',
							'playingId' => '../etc/passwd',
						),
					)
				),
			)
		);

		$this->assertSame( array(), Lichess_Client::get_live_games( array( 'alice' ) ) );
		$this->assertCount( 1, $this->urls );
	}

	public function test_finished_game_is_skipped() {
		$this->queue_responses(
			array(
				$this->status_body(
					array(
						array(
							'id'        => 'alice',
							'name'      => 'Alice',
							'playingId' => 'abcd1234',
						),
					)
				),
				$this->game_body( array( 'status' => 'mate' ) ),
			)
		);

		$this->assertSame( array(), Lichess_Client::get_live_games( array( 'alice' ) ) );
	}

	public function test_game_kept_when_its_details_cannot_be_loaded() {
		$this->queue_responses(
			array(
				$this->status_body(
					array(
						array(
							'id'        => 'alice',
							'name'      => 'Alice',
							'playingId' => 'abcd1234',
						),
					)
				),
				$this->response( 500, '' ),
			)
		);

		$games = Lichess_Client::get_live_games( array( 'alice' ) );

		$this->assertCount( 1, $games );
		$this->assertSame( array( 'Alice' ), $games[0]['members'] );
		$this->assertSame( '', $games[0]['white']['name'] );
		$this->assertSame( '', $games[0]['time_control'] );
	}

	public function test_timeout_returns_error_and_is_cached_briefly() {
		$this->queue_responses( array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ) );

		$result = Lichess_Client::get_live_games( array( 'alice' ) );

		$this->assertSame( 'lichess_connection_error', $result->get_error_code() );
		$this->assertStringContainsString( 'timed out', $result->get_error_message() );

		Lichess_Client::get_live_games( array( 'alice' ) );
		$this->assertCount( 1, $this->urls, 'The failure is cached, not retried per visitor.' );
	}

	public function test_http_error_status_returns_error() {
		$this->queue_responses( array( $this->response( 503, 'down' ) ) );

		$this->assertSame( 'lichess_http_error', Lichess_Client::get_live_games( array( 'alice' ) )->get_error_code() );
	}

	public function test_unreadable_response_returns_error() {
		$this->queue_responses( array( $this->response( 200, '<html>not json</html>' ) ) );

		$this->assertSame( 'lichess_bad_response', Lichess_Client::get_live_games( array( 'alice' ) )->get_error_code() );
	}

	public function test_rate_limit_sets_backoff_and_blocks_further_requests() {
		$this->queue_responses( array( $this->response( 429, '' ) ) );

		$result = Lichess_Client::get_live_games( array( 'alice' ) );

		$this->assertSame( 'lichess_rate_limited', $result->get_error_code() );
		$this->assertSame( Lichess_Client::BACKOFF_SECONDS, $this->transients[ Lichess_Client::BACKOFF_KEY ]['ttl'] );

		// A different list is not cached, but must not reach Lichess during the back-off.
		$other = Lichess_Client::get_live_games( array( 'bob' ) );

		$this->assertSame( 'lichess_rate_limited', $other->get_error_code() );
		$this->assertCount( 1, $this->urls );
	}

	public function test_one_failed_status_batch_still_returns_the_other() {
		$names = array_map(
			function ( $i ) {
				return sprintf( 'user%02d', $i );
			},
			range( 1, Lichess_Client::STATUS_BATCH + 5 )
		);

		$this->queue_responses(
			array(
				$this->response( 500, '' ),
				$this->status_body(
					array(
						array(
							'id'        => 'user41',
							'name'      => 'user41',
							'playingId' => 'abcd1234',
						),
					)
				),
				$this->game_body(),
			)
		);

		$games = Lichess_Client::get_live_games( $names );

		$this->assertCount( 1, $games );
		$this->assertCount( 3, $this->urls );
	}

	public function test_every_status_batch_failing_is_an_error_not_an_empty_list() {
		$names = array_map(
			function ( $i ) {
				return sprintf( 'user%02d', $i );
			},
			range( 1, Lichess_Client::STATUS_BATCH + 5 )
		);

		$this->queue_responses( array( $this->response( 500, '' ), $this->response( 500, '' ) ) );

		$this->assertSame( 'lichess_http_error', Lichess_Client::get_live_games( $names )->get_error_code() );
	}

	public function test_format_clock() {
		$this->assertSame(
			'3+2',
			Lichess_Client::format_clock(
				array(
					'initial'   => 180,
					'increment' => 2,
				)
			)
		);
		$this->assertSame( '15s+0', Lichess_Client::format_clock( array( 'initial' => 15 ) ) );
		$this->assertSame( '', Lichess_Client::format_clock( null ) );
		$this->assertSame( '', Lichess_Client::format_clock( array() ) );
	}

	public function test_slide_html_escapes_and_lazy_loads_the_board() {
		$game = array(
			'id'           => 'abcd1234',
			'url'          => 'https://lichess.org/abcd1234',
			'embed_url'    => 'https://lichess.org/embed/game/abcd1234',
			'members'      => array( 'Alice' ),
			'white'        => array(
				'name'   => '<b>Alice</b>',
				'rating' => 1650,
			),
			'black'        => array(
				'name'   => '',
				'rating' => null,
			),
			'time_control' => '10+5',
			'speed'        => 'rapid',
			'rated'        => true,
		);
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );

		$with_board = Chess_Army_Knife_Lichess_Live::slide_html( $game, true );
		$text_only  = Chess_Army_Knife_Lichess_Live::slide_html( $game, false );

		$this->assertStringContainsString( 'data-src="https://lichess.org/embed/game/abcd1234"', $with_board );
		$this->assertStringNotContainsString( ' src=', $with_board );
		$this->assertStringNotContainsString( '<iframe', $text_only );
		$this->assertStringNotContainsString( '<b>Alice</b>', $with_board );
		$this->assertStringContainsString( 'Anonymous', $with_board );
		$this->assertStringContainsString( '10+5 · Rapid · Rated', $with_board );
		$this->assertStringContainsString( 'Watch on Lichess', $with_board );
	}

	public function test_rest_rejects_a_bad_signature_without_calling_lichess() {
		Functions\expect( 'wp_remote_get' )->never();

		$result = Chess_Army_Knife_Lichess_Live::rest_live_games(
			new ArrayObject(
				array(
					'users' => 'alice,bob',
					'board' => '1',
					'sig'   => 'forged',
				)
			)
		);

		$this->assertSame( 'chess_army_knife_bad_signature', $result->get_error_code() );
	}

	public function test_rest_rejects_usernames_that_were_not_signed() {
		Functions\expect( 'wp_remote_get' )->never();

		$sig = Chess_Army_Knife_Lichess_Live::sign( array( 'alice' ), true );

		$result = Chess_Army_Knife_Lichess_Live::rest_live_games(
			new ArrayObject(
				array(
					'users' => 'alice,mallory',
					'board' => '1',
					'sig'   => $sig,
				)
			)
		);

		$this->assertSame( 'chess_army_knife_bad_signature', $result->get_error_code() );
	}

	public function test_rest_returns_slides_for_a_signed_list() {
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );
		$this->queue_responses(
			array(
				$this->status_body(
					array(
						array(
							'id'        => 'alice',
							'name'      => 'Alice',
							'playingId' => 'abcd1234',
						),
					)
				),
				$this->game_body(),
			)
		);

		$response = Chess_Army_Knife_Lichess_Live::rest_live_games(
			new ArrayObject(
				array(
					'users' => 'alice,bob',
					'board' => '0',
					'sig'   => Chess_Army_Knife_Lichess_Live::sign( array( 'alice', 'bob' ), false ),
				)
			)
		);

		$this->assertSame( 'abcd1234', $response->data['slides'][0]['id'] );
		$this->assertStringContainsString( 'Alice (1650)', $response->data['slides'][0]['html'] );
		$this->assertStringNotContainsString( '<iframe', $response->data['slides'][0]['html'] );
	}

	public function test_rest_reports_unavailable_when_lichess_fails() {
		$this->queue_responses( array( $this->response( 500, '' ) ) );

		$result = Chess_Army_Knife_Lichess_Live::rest_live_games(
			new ArrayObject(
				array(
					'users' => 'alice',
					'board' => '1',
					'sig'   => Chess_Army_Knife_Lichess_Live::sign( array( 'alice' ), true ),
				)
			)
		);

		$this->assertSame( 'chess_army_knife_lichess_unavailable', $result->get_error_code() );
		$this->assertSame( array( 'status' => 503 ), $result->get_error_data() );
	}
}
