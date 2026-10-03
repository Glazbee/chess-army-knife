<?php
/**
 * Tests for the results kept on league match events.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class EventResultsTest extends Chess_Army_Knife_TestCase {

	private function board( $number, $result, $home, $away ) {
		return array(
			'board'       => $number,
			'home_colour' => 'W',
			'result'      => $result,
			'home'        => $home,
			'away'        => $away,
		);
	}

	private function player( $name, $code, $rating = null ) {
		return array(
			'name'   => $name,
			'code'   => $code,
			'rating' => $rating,
		);
	}

	private function played_match() {
		return array(
			'home'       => 'Our A',
			'away'       => 'Rivals',
			'home_score' => '1.5',
			'away_score' => '0.5',
			'winner'     => 'home',
			'games'      => array(
				$this->board( 1, 'home_win', $this->player( 'Ada Lovelace', '120787J', 1850 ), $this->player( 'Bob Rival', '999999A', 1700 ) ),
				$this->board( 2, 'draw', $this->player( 'Bea Turing', '222222B', null ), null ),
			),
		);
	}

	public function test_an_unplayed_fixture_has_no_result() {
		$match           = $this->played_match();
		$match['winner'] = '';

		$this->assertNull( Chess_Army_Knife_Event_Results::build( $match, array( 'home' ) ) );
	}

	public function test_only_the_clubs_own_players_keep_a_code_and_rating() {
		$result = Chess_Army_Knife_Event_Results::build( $this->played_match(), array( 'home' ) );

		$this->assertSame( '1.5', $result['home_score'] );
		$this->assertSame( 'home', $result['winner'] );
		$this->assertSame( $this->player( 'Ada Lovelace', '120787J', 1850 ), $result['games'][0]['home'] );
		$this->assertSame( array( 'name' => 'Bob Rival' ), $result['games'][0]['away'], 'An opponent is kept by name only.' );
		$this->assertNull( $result['games'][1]['away'], 'A default has no player.' );
	}

	public function test_when_the_club_is_the_away_side_its_players_are_the_ones_with_codes() {
		$result = Chess_Army_Knife_Event_Results::build( $this->played_match(), array( 'away' ) );

		$this->assertSame( array( 'name' => 'Ada Lovelace' ), $result['games'][0]['home'] );
		$this->assertSame( '999999A', $result['games'][0]['away']['code'] );
	}

	public function test_codes_match_on_their_digits() {
		$this->assertTrue( Chess_Army_Knife_Event_Results::same_code( '120787J', '120787' ) );
		$this->assertFalse( Chess_Army_Knife_Event_Results::same_code( '120787J', '120788J' ) );
		$this->assertFalse( Chess_Army_Knife_Event_Results::same_code( '', '' ) );
	}

	public function test_scrubbing_a_person_keeps_the_board_and_its_result() {
		$result   = Chess_Army_Knife_Event_Results::build( $this->played_match(), array( 'home' ) );
		$scrubbed = Chess_Army_Knife_Event_Results::scrub( $result, '120787', 'Ada Lovelace' );

		$this->assertSame( array( 'name' => '' ), $scrubbed['games'][0]['home'] );
		$this->assertSame( 'home_win', $scrubbed['games'][0]['result'] );
		$this->assertSame( 'Bea Turing', $scrubbed['games'][1]['home']['name'], 'Nobody else is touched.' );
		$this->assertSame( 'Bob Rival', $scrubbed['games'][0]['away']['name'] );
	}

	public function test_scrubbing_without_a_code_goes_by_name() {
		$result   = Chess_Army_Knife_Event_Results::build( $this->played_match(), array( 'away' ) );
		$scrubbed = Chess_Army_Knife_Event_Results::scrub( $result, '', 'bob rival' );

		$this->assertSame( array( 'name' => '' ), $scrubbed['games'][0]['away'] );
		$this->assertSame( 'Ada Lovelace', $scrubbed['games'][0]['home']['name'] );
	}

	public function test_the_clubs_players_are_counted_across_results_with_their_latest_details() {
		$early = Chess_Army_Knife_Event_Results::build( $this->played_match(), array( 'home' ) );
		$late  = $early;

		$late['games'][0]['home']['rating'] = 1900;
		$late['games'][0]['home']['code']   = '120787';

		$players = Chess_Army_Knife_Event_Results::club_players(
			array(
				array(
					'date'   => '2026-02-01',
					'result' => $late,
				),
				array(
					'date'   => '2025-10-01',
					'result' => $early,
				),
			)
		);

		$this->assertCount( 2, $players, 'Opponents have no code, so are not listed.' );
		$this->assertSame( 2, $players['120787']['games'] );
		$this->assertSame( 1900, $players['120787']['rating'] );
		$this->assertSame( '2026-02-01', $players['120787']['last_played'] );
		$this->assertSame( 2, $players['222222']['games'], 'Bea played in both.' );
	}

	public function test_a_player_withheld_is_not_listed() {
		$result                     = Chess_Army_Knife_Event_Results::build( $this->played_match(), array( 'home' ) );
		$result['games'][0]['home'] = array( 'name' => '' );
		$players                    = Chess_Army_Knife_Event_Results::club_players(
			array(
				array(
					'date'   => '2026-02-01',
					'result' => $result,
				),
			)
		);

		$this->assertEquals( array( '222222' ), array_keys( $players ) );
	}

	public function test_result_text() {
		$this->assertSame( '1–0', Chess_Army_Knife_Event_Results::result_text( 'home_win' ) );
		$this->assertSame( '0–1', Chess_Army_Knife_Event_Results::result_text( 'away_win' ) );
		$this->assertSame( '½–½', Chess_Army_Knife_Event_Results::result_text( 'draw' ) );
		$this->assertSame( '–', Chess_Army_Knife_Event_Results::result_text( '' ) );
	}

	public function test_the_page_shows_the_score_and_each_board() {
		$result = Chess_Army_Knife_Event_Results::build( $this->played_match(), array( 'home' ) );
		Functions\when( 'get_post_meta' )->justReturn( $result );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$html = Chess_Army_Knife_Event_Results::html( 5 );

		$this->assertStringContainsString( 'Our A 1.5–0.5 Rivals', $html );
		$this->assertStringContainsString( '<th scope="row">1</th><td>Ada Lovelace</td><td>1–0</td><td>Bob Rival</td>', $html );
		$this->assertStringContainsString( 'A player', $html, 'A default has no name to show.' );
		$this->assertStringNotContainsString( '120787', $html, 'Codes are never shown.' );
	}

	public function test_names_can_be_left_off_the_page() {
		$result = Chess_Army_Knife_Event_Results::build( $this->played_match(), array( 'home' ) );
		Functions\when( 'get_post_meta' )->justReturn( $result );
		Functions\when( 'apply_filters' )->justReturn( false );

		$html = Chess_Army_Knife_Event_Results::html( 5 );

		$this->assertStringContainsString( 'Our A 1.5–0.5 Rivals', $html );
		$this->assertStringNotContainsString( 'Ada Lovelace', $html );
	}

	public function test_an_event_with_no_result_shows_nothing() {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$this->assertSame( '', Chess_Army_Knife_Event_Results::html( 5 ) );
	}
}
