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

	private function game( $id, $date, $title, array $boards ) {
		return array(
			'id'     => $id,
			'date'   => $date,
			'start'  => $date . ' 19:30:00',
			'title'  => $title,
			'result' => array(
				'home'       => 'Our A',
				'away'       => 'Rivals',
				'home_score' => '1',
				'away_score' => '1',
				'winner'     => 'draw',
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
				array( $this->board( 1, 'W', 'home_win', array( 'name' => 'Rival One' ), $ada ) )
			),
			$this->game(
				1,
				'2026-10-05',
				'Our A v Rivals',
				array(
					$this->board( 1, 'W', 'home_win', $ada, array( 'name' => 'Rival Two' ) ),
					$this->board( 2, 'B', 'draw', $bea, null ),
				)
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
}
