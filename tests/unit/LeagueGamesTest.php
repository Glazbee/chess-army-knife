<?php
/**
 * Tests for the League games screen's reading of kept results.
 *
 * @package Chess_Army_Knife
 */

class LeagueGamesTest extends Chess_Army_Knife_TestCase {

	private function player( $name, $code ) {
		return array(
			'name'   => $name,
			'code'   => $code,
			'rating' => 1800,
		);
	}

	private function game( $id, $date, $title, array $boards, array $sides = array( 'home' ), $winner = 'draw', $scores = array( '1', '1' ) ) {
		return array(
			'id'     => $id,
			'date'   => $date,
			'start'  => $date . ' 19:30:00',
			'title'  => $title,
			'sides'  => $sides,
			'result' => array(
				'home'       => 'home' === $sides[0] ? 'Our A' : 'Rivals',
				'away'       => 'home' === $sides[0] ? 'Rivals' : 'Our A',
				'home_score' => $scores[0],
				'away_score' => $scores[1],
				'winner'     => $winner,
				'games'      => $boards,
			),
		);
	}

	private function board( $number, $home_colour, $result, $home, $away ) {
		return array(
			'board'       => $number,
			'home_colour' => $home_colour,
			'result'      => $result,
			'home'        => $home,
			'away'        => $away,
		);
	}

	private function games() {
		$ada = $this->player( 'Ada Lovelace', '120787J' );
		$bea = $this->player( 'Bea Turing', '222222B' );

		return array(
			$this->game(
				2,
				'2026-11-02',
				'Rivals v Our A',
				array( $this->board( 1, 'W', 'home_win', array( 'name' => 'Rival One' ), $ada ) ),
				array( 'away' ),
				'home',
				array( '3', '1' )
			),
			$this->game(
				1,
				'2026-10-05',
				'Our A v Rivals',
				array(
					$this->board( 1, 'W', 'home_win', $ada, array( 'name' => 'Rival Two' ) ),
					$this->board( 2, 'B', 'draw', $bea, null ),
				),
				array( 'home' ),
				'home',
				array( '2', '0' )
			),
		);
	}

	public function test_a_side_wins_when_its_own_win_is_the_result() {
		$this->assertSame( 'win', Chess_Army_Knife_League_Games::outcome_of( 'home', 'home_win' ) );
		$this->assertSame( 'loss', Chess_Army_Knife_League_Games::outcome_of( 'home', 'away_win' ) );
		$this->assertSame( 'win', Chess_Army_Knife_League_Games::outcome_of( 'away', 'away_win' ) );
		$this->assertSame( 'draw', Chess_Army_Knife_League_Games::outcome_of( 'away', 'draw' ) );
		$this->assertSame( '', Chess_Army_Knife_League_Games::outcome_of( 'home', '' ), 'No result yet.' );
	}

	public function test_the_away_side_has_the_opposite_colour() {
		$this->assertSame( 'White', Chess_Army_Knife_League_Games::colour_of( 'home', 'W' ) );
		$this->assertSame( 'Black', Chess_Army_Knife_League_Games::colour_of( 'away', 'W' ) );
		$this->assertSame( 'White', Chess_Army_Knife_League_Games::colour_of( 'away', 'B' ) );
		$this->assertSame( '', Chess_Army_Knife_League_Games::colour_of( 'home', '' ) );
	}

	public function test_a_players_boards_come_from_either_side_of_the_match() {
		$boards = Chess_Army_Knife_League_Games::boards_of( $this->games(), '120787' );

		$this->assertCount( 2, $boards, 'The code matches with or without its letter.' );
		$this->assertSame( 'Rivals v Our A', $boards[0]['title'] );
		$this->assertSame( 'Black', $boards[0]['colour'] );
		$this->assertSame( 'Rival One', $boards[0]['opponent'] );
		$this->assertSame( 'loss', $boards[0]['outcome'] );
		$this->assertSame( 'win', $boards[1]['outcome'] );
		$this->assertSame(
			array(
				'win'  => 1,
				'draw' => 0,
				'loss' => 1,
			),
			Chess_Army_Knife_League_Games::tally( $boards )
		);
	}

	public function test_a_default_has_no_opponent_name() {
		$boards = Chess_Army_Knife_League_Games::boards_of( $this->games(), '222222B' );

		$this->assertCount( 1, $boards );
		$this->assertSame( '', $boards[0]['opponent'] );
		$this->assertSame( 'draw', $boards[0]['outcome'] );
	}

	public function test_an_unplayed_game_has_no_boards() {
		$games    = $this->games();
		$games[0] = array(
			'id'     => 3,
			'date'   => '2026-12-01',
			'start'  => '2026-12-01 19:30:00',
			'title'  => 'Our A v Later',
			'sides'  => array( 'home' ),
			'result' => null,
		);

		$this->assertCount( 1, Chess_Army_Knife_League_Games::boards_of( $games, '120787' ) );
	}

	public function test_players_are_found_by_part_of_their_name() {
		$found = Chess_Army_Knife_League_Games::find_players( $this->games(), 'love' );

		$this->assertCount( 1, $found );
		$this->assertSame( 2, reset( $found )['games'] );
		$this->assertSame( array(), Chess_Army_Knife_League_Games::find_players( $this->games(), 'Rival' ), 'An opponent is not one of the club\'s players.' );
		$this->assertSame( array(), Chess_Army_Knife_League_Games::find_players( $this->games(), '  ' ) );
	}

	public function test_the_score_has_the_home_team_on_the_left() {
		$games = $this->games();

		$this->assertSame( '3 - 1', Chess_Army_Knife_League_Games::score_text( $games[0] ), 'The club was away, and its score is on the right.' );
		$this->assertSame( '2 - 0', Chess_Army_Knife_League_Games::score_text( $games[1] ) );
		$this->assertSame( '', Chess_Army_Knife_League_Games::score_text( array( 'result' => null ) ) );
		$this->assertSame( '3 - 1', Chess_Army_Knife_League_Games::boards_of( $games, '120787' )[0]['score'] );
	}

	public function test_a_match_is_won_or_lost_by_the_side_the_club_is_on() {
		$games = $this->games();

		$this->assertSame( 'loss', Chess_Army_Knife_League_Games::club_outcome( $games[0] ), 'The home side won, and the club was away.' );
		$this->assertSame( 'win', Chess_Army_Knife_League_Games::club_outcome( $games[1] ) );
		$this->assertSame( 'Rivals', Chess_Army_Knife_League_Games::opponent_of( $games[0] ) );

		$games[1]['sides'] = array( 'home', 'away' );
		$this->assertSame( '', Chess_Army_Knife_League_Games::club_outcome( $games[1] ), 'Two club teams met.' );
		$this->assertSame( '', Chess_Army_Knife_League_Games::opponent_of( $games[1] ) );
	}

	public function test_games_can_be_filtered_by_opponent_and_result() {
		$games = $this->games();

		$this->assertCount( 2, Chess_Army_Knife_League_Games::filter_games( $games, '', '' ) );
		$this->assertCount( 2, Chess_Army_Knife_League_Games::filter_games( $games, 'rival', '' ) );
		$this->assertCount( 0, Chess_Army_Knife_League_Games::filter_games( $games, 'Elsewhere', '' ) );

		$won = Chess_Army_Knife_League_Games::filter_games( $games, '', 'win' );
		$this->assertCount( 1, $won );
		$this->assertSame( 1, $won[0]['id'] );

		$games[] = array(
			'id'     => 3,
			'date'   => '2026-12-01',
			'start'  => '2026-12-01 19:30:00',
			'title'  => 'Our A v Later',
			'sides'  => array( 'home' ),
			'result' => null,
		);
		$this->assertCount( 1, Chess_Army_Knife_League_Games::filter_games( $games, 'later', '' ), 'A game not played yet is found by its title.' );
		$this->assertCount( 0, Chess_Army_Knife_League_Games::filter_games( $games, 'later', 'win' ), 'It has no result to be a win.' );
	}

	public function test_a_player_is_matched_to_a_member_by_ecf_code() {
		$members = array(
			array(
				'name'     => 'Augusta Ada King',
				'ecf_code' => '120787',
			),
		);

		$found = Chess_Army_Knife_League_Games::find_players( $this->games(), 'augusta', $members );

		$this->assertCount( 1, $found, 'The member\'s own name finds the player in the results.' );
		$this->assertSame( 'Augusta Ada King', reset( $found )['member'] );

		$by_result_name = Chess_Army_Knife_League_Games::find_players( $this->games(), 'Bea', $members );
		$this->assertSame( '', reset( $by_result_name )['member'], 'No record has this code.' );
	}
}
