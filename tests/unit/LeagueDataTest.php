<?php
/**
 * Tests for the league table and team views worked out from LMS v2 fixtures.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class LeagueDataTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return $value;
			}
		);
	}

	/**
	 * The invented league in tests/fixtures/lms-v2-results.json, as the client reads it.
	 *
	 * @return array[]
	 */
	private function fixtures() {
		$data = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/lms-v2-results.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local fixture in a test.

		return array_map( array( 'Chess_Army_Knife_LMS_Client', 'normalise_fixture' ), $data['fixtures'] );
	}

	public function test_scores_are_read_as_text_whole_or_half() {
		$fixtures = $this->fixtures();

		$this->assertSame( '1.5', $fixtures[0]['home_score'] );
		$this->assertSame( '3.5', $fixtures[0]['away_score'] );
		$this->assertSame( '3', $fixtures[2]['home_score'], 'A whole score from the LMS is an integer.' );
		$this->assertSame( '', $fixtures[3]['home_score'], 'Not played yet.' );
		$this->assertSame( 'away', $fixtures[0]['winner'] );
		$this->assertSame( '', $fixtures[3]['winner'] );
	}

	public function test_boards_are_read_with_colours_results_and_missing_ratings() {
		$games = $this->fixtures()[1]['games'];

		$this->assertCount( 5, $games );
		$this->assertSame( 'draw', $games[0]['result'] );
		$this->assertSame( 'B', $games[0]['home_colour'] );
		$this->assertSame( 'Player 1, B', $games[0]['home']['name'] );
		$this->assertSame( 1940, $games[0]['home']['rating'] );
		$this->assertNull( $games[4]['away']['rating'], 'An unrated player has a null rating.' );
		$this->assertSame( array(), $this->fixtures()[3]['games'] );
	}

	public function test_a_default_has_no_player() {
		$games = Chess_Army_Knife_LMS_Client::normalise_games(
			array(
				array(
					'board'       => 1,
					'result'      => 'away_win',
					'home_player' => array(
						'lms_id' => -1,
						'name'   => 'Default',
					),
					'away_player' => array(
						'lms_id'      => 9,
						'rating_code' => '123456A',
						'name'        => 'Real, Rita',
						'rating'      => 1500,
					),
				),
			)
		);

		$this->assertNull( $games[0]['home'] );
		$this->assertSame( 'Real, Rita', $games[0]['away']['name'] );
	}

	public function test_the_table_is_worked_out_from_the_results() {
		$table = Chess_Army_Knife_League_Data::standings( $this->fixtures() );

		$this->assertSame( array( 'Test Club B', 'Test Club C', 'Test Club A' ), array_column( $table, 'team' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( $table, 'position' ) );
		$this->assertSame( array( 3, 3, 0 ), array_column( $table, 'points' ) );
		$this->assertSame( array( 2, 2, 2 ), array_column( $table, 'played' ), 'The unplayed fixture is not counted.' );
		$this->assertSame( array( 1, 1, 0 ), array_column( $table, 'won' ) );
		$this->assertSame( array( 1, 1, 0 ), array_column( $table, 'drawn' ) );
		$this->assertSame( array( 0, 0, 2 ), array_column( $table, 'lost' ) );
		$this->assertSame( array( 6.0, 5.5, 3.5 ), array_column( $table, 'for' ) );
		$this->assertSame( array( 4.0, 4.5, 6.5 ), array_column( $table, 'against' ) );
	}

	public function test_a_league_with_nothing_played_still_lists_its_teams() {
		$unplayed = array( $this->fixtures()[3] );

		$table = Chess_Army_Knife_League_Data::standings( $unplayed );

		$this->assertSame( array( 'Test Club A', 'Test Club C' ), array_column( $table, 'team' ), 'Level on nothing, so by name.' );
		$this->assertSame( array( 0, 0 ), array_column( $table, 'played' ) );
	}

	public function test_the_points_for_a_win_and_a_draw_can_be_changed() {
		$table = Chess_Army_Knife_League_Data::standings(
			$this->fixtures(),
			array(
				'win'  => 3,
				'draw' => 1,
			)
		);

		$this->assertSame( array( 4, 4, 0 ), array_column( $table, 'points' ) );
	}

	public function test_a_team_sees_its_own_fixtures_from_its_side() {
		$rows = Chess_Army_Knife_League_Data::team_fixtures( $this->fixtures(), ' test  club a ' );

		$this->assertCount( 3, $rows );
		$this->assertSame( array( 'Test Club B', 'Test Club C', 'Test Club C' ), array_column( $rows, 'opponent' ) );
		$this->assertSame( array( 'home', 'away', 'home' ), array_column( $rows, 'side' ) );
		$this->assertSame( array( 'lost', 'lost', '' ), array_column( $rows, 'outcome' ) );
		$this->assertSame( array( '1.5', '2', '' ), array_column( $rows, 'for' ) );
		$this->assertSame( array( '3.5', '3', '' ), array_column( $rows, 'against' ) );
	}

	public function test_the_next_fixture_is_the_first_unplayed_one_from_today() {
		$rows = Chess_Army_Knife_League_Data::team_fixtures( $this->fixtures(), 'Test Club A' );

		$this->assertSame( 4, Chess_Army_Knife_League_Data::next_fixture( $rows, '2026-10-02' )['fixture']['fixture_id'] );
		$this->assertNull( Chess_Army_Knife_League_Data::next_fixture( $rows, '2100-01-01' ), 'It is in the past by then.' );
	}

	public function test_boards_are_shown_from_the_chosen_teams_side_with_colours_flipped_for_away() {
		$fixture = $this->fixtures()[0]; // Test Club A at home to B: board 1 home plays black, and it was a draw.

		$home = Chess_Army_Knife_League_Data::boards_for_side( $fixture, 'home' );
		$away = Chess_Army_Knife_League_Data::boards_for_side( $fixture, 'away' );

		$this->assertSame( 'B', $home[0]['colour'] );
		$this->assertSame( 'W', $away[0]['colour'] );
		$this->assertSame( 'Player 1, A', $home[0]['player'] );
		$this->assertSame( 'Player 1, B', $home[0]['opponent'] );
		$this->assertSame( array( 'draw', 'win', 'loss', 'loss', 'loss' ), array_column( $home, 'result' ) );
		$this->assertSame( array( 'draw', 'loss', 'win', 'win', 'win' ), array_column( $away, 'result' ) );
	}

	public function test_seasons_are_chosen_by_status_id_or_name() {
		$seasons = array(
			array(
				'id'     => 2279,
				'name'   => '2026-2027',
				'status' => 'active',
			),
			array(
				'id'     => 1842,
				'name'   => '2025-2026',
				'status' => 'old',
			),
		);

		$this->assertSame( array( 2279 ), Chess_Army_Knife_LMS_Client::seasons_matching( $seasons, 'active' ) );
		$this->assertSame( array( 2279 ), Chess_Army_Knife_LMS_Client::seasons_matching( $seasons, '' ) );
		$this->assertSame( array( 1842 ), Chess_Army_Knife_LMS_Client::seasons_matching( $seasons, '1842' ) );
		$this->assertSame( array( 1842 ), Chess_Army_Knife_LMS_Client::seasons_matching( $seasons, ' 2025-2026 ' ) );
		$this->assertSame( array(), Chess_Army_Knife_LMS_Client::seasons_matching( $seasons, '2019 season' ) );
	}
}
