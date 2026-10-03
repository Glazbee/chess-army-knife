<?php
/**
 * Integration tests: the league blocks reading the LMS v2 API.
 *
 * @package Chess_Army_Knife
 */

class LeagueBlocksTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'lms_api_key'     => 'secret',
			)
		);

		add_filter( 'pre_http_request', array( $this, 'serve_lms' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'serve_lms' ), 10 );
		parent::tear_down();
	}

	/**
	 * Answer LMS v2 requests with a small invented league (see tests/fixtures/lms-v2-results.json).
	 */
	public function serve_lms( $preempt, $args, $url ) {
		$base = Chess_Army_Knife_LMS_Client::V2_BASE . '/';
		if ( 0 !== strpos( $url, $base ) ) {
			return $preempt;
		}

		$results = json_decode( file_get_contents( Chess_Army_Knife_DIR . 'tests/fixtures/lms-v2-results.json' ), true );
		$bodies  = array(
			'org/270/seasons'  => array(
				'seasons' => array(
					array(
						'id'     => 2,
						'name'   => '2026-2027',
						'status' => 'active',
					),
					array(
						'id'     => 1,
						'name'   => '2025-2026',
						'status' => 'old',
					),
				),
			),
			'season/2/events'  => array(
				'events' => array(
					array(
						'id'   => 30,
						'name' => 'Division 1',
						'type' => 'team_league',
					),
				),
			),
			'season/1/events'  => array(
				'events' => array(
					array(
						'id'   => 20,
						'name' => 'Division 1',
						'type' => 'team_league',
					),
				),
			),
			'event/20/results' => $results,
			// The new season has nothing played: every fixture has 0-0 and an unknown winner.
			'event/30/results' => array(
				'fixtures' => array(
					array(
						'fixture_id' => 9,
						'date'       => '2099-01-26',
						'time'       => '19:45',
						'home_team'  => 'Test Club A',
						'away_team'  => 'Test Club B',
						'home_score' => 0,
						'away_score' => 0,
						'winner'     => 'unknown',
						'games'      => array(),
					),
				),
			),
		);

		$path = substr( $url, strlen( $base ) );
		return array(
			'headers'  => array(),
			'body'     => isset( $bodies[ $path ] ) ? wp_json_encode( $bodies[ $path ] ) : '{}',
			'response' => array(
				'code'    => isset( $bodies[ $path ] ) ? 200 : 404,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function render( $block, array $attributes = array() ) {
		return do_blocks( '<!-- wp:chess-army-knife/' . $block . ' ' . wp_json_encode( (object) $attributes ) . ' /-->' );
	}

	public function test_the_league_table_of_an_earlier_season_is_worked_out_from_its_results() {
		$html = $this->render(
			'league-table',
			array(
				'orgId'       => '270',
				'eventName'   => 'Division 1',
				'season'      => '2025-2026',
				'displayMode' => 'table',
			)
		);

		$this->assertStringContainsString( 'Test Club B', $html );
		$this->assertLessThan( strpos( $html, 'Test Club C' ), strpos( $html, 'Test Club B' ), 'B is top.' );
		$this->assertLessThan( strpos( $html, 'Test Club A' ), strpos( $html, 'Test Club C' ), 'A is bottom.' );
		$this->assertStringContainsString( '1.5', $html, 'Match points are in halves.' );
	}

	public function test_the_current_season_with_nothing_played_lists_the_teams_and_the_fixture_to_come() {
		$html = $this->render(
			'league-table',
			array(
				'orgId'     => '270',
				'eventName' => 'Division 1',
			)
		);

		$this->assertStringContainsString( 'Test Club A', $html );
		$this->assertStringNotContainsString( '0 – 0', $html, 'An unplayed fixture has no score.' );
		$this->assertStringContainsString( '19:45', $html );
	}

	public function test_a_missing_event_is_reported_not_fatal() {
		$html = $this->render(
			'league-table',
			array(
				'orgId'     => '270',
				'eventName' => 'Division 9',
			)
		);

		$this->assertStringContainsString( 'No event called', $html );
	}

	public function test_the_team_fixtures_block_shows_each_teams_last_result_and_next_fixture() {
		$html = $this->render(
			'team-carousel',
			array(
				'teamSource' => 'auto',
				'orgId'      => '270',
				'eventName'  => 'Division 1',
				'season'     => '2025-2026',
			)
		);

		$this->assertStringContainsString( 'Test Club A', $html );
		$this->assertStringContainsString( 'Home against Test Club C', $html, 'A: the unplayed fixture is next.' );
		$this->assertStringContainsString( 'Home against Test Club A, 3 to 2', $html, 'C: the last result, from its side.' );
		$this->assertStringContainsString( 'Away against Test Club C, 2 to 3', $html, 'A: the last result, from its side.' );
	}

	public function test_the_team_block_shows_position_results_and_boards() {
		$html = $this->render(
			'team-page',
			array(
				'team'      => 'Test Club A',
				'orgId'     => '270',
				'eventName' => 'Division 1',
				'season'    => '2025-2026',
			)
		);

		$this->assertStringContainsString( '3 of 3', $html, 'A is bottom of three.' );
		$this->assertStringContainsString( 'Home against Test Club C', $html, 'The fixture to come.' );
		$this->assertStringContainsString( 'Lost', $html );
		$this->assertStringContainsString( 'Board by board', $html );
		$this->assertStringContainsString( 'Player 1, A (1940)', $html );
		$this->assertStringContainsString( '(unrated)', $html );
	}

	public function test_the_team_block_can_leave_out_the_boards_and_says_when_the_team_is_not_in_the_event() {
		$without = $this->render(
			'team-page',
			array(
				'team'       => 'Test Club A',
				'orgId'      => '270',
				'eventName'  => 'Division 1',
				'season'     => '2025-2026',
				'showBoards' => false,
			)
		);
		$this->assertStringNotContainsString( 'Board by board', $without );

		$stranger = $this->render(
			'team-page',
			array(
				'team'      => 'Nobody',
				'orgId'     => '270',
				'eventName' => 'Division 1',
			)
		);
		$this->assertStringContainsString( 'not in this event', $stranger );
	}
}
