<?php
/**
 * Tests for paging the games to play.
 *
 * @package Chess_Army_Knife
 */

class GamesPaginationTest extends Chess_Army_Knife_TestCase {

	/**
	 * Sections like games_to_play() makes: rounds of four games each.
	 *
	 * @param int $rounds Number of rounds.
	 * @return array[]
	 */
	private function sections( $rounds ) {
		$sections = array();
		$id       = 1;
		for ( $round = 1; $round <= $rounds; $round++ ) {
			for ( $i = 0; $i < 4; $i++ ) {
				$sections[ 'Round ' . $round ][] = array( 'id' => $id++ );
			}
		}

		return $sections;
	}

	private function ids( array $paged ) {
		$ids = array();
		foreach ( $paged['sections'] as $games ) {
			$ids = array_merge( $ids, array_column( $games, 'id' ) );
		}

		return $ids;
	}

	public function test_a_page_holds_the_games_asked_for_and_the_pages_cover_every_game_once() {
		$sections = $this->sections( 5 ); // 20 games.

		$first = Chess_Army_Knife_Tournament_Summary::paginate( $sections, 1, 6 );
		$this->assertSame( array( 1, 2, 3, 4, 5, 6 ), $this->ids( $first ) );
		$this->assertSame( 4, $first['pages'] );
		$this->assertSame( 20, $first['total'] );
		$this->assertSame( array( 1, 6 ), array( $first['first'], $first['last'] ) );

		$all = array();
		for ( $page = 1; $page <= 4; $page++ ) {
			$all = array_merge( $all, $this->ids( Chess_Army_Knife_Tournament_Summary::paginate( $sections, $page, 6 ) ) );
		}
		$this->assertSame( range( 1, 20 ), $all );
	}

	public function test_a_round_that_runs_over_a_page_continues_under_its_heading() {
		$page = Chess_Army_Knife_Tournament_Summary::paginate( $this->sections( 3 ), 2, 6 );

		$this->assertSame( array( 'Round 2', 'Round 3' ), array_keys( $page['sections'] ), 'Games 7 to 12 are the last two of round 2 and all of round 3.' );
		$this->assertSame( array( 7, 8 ), array_column( $page['sections']['Round 2'], 'id' ) );
	}

	public function test_the_last_page_is_a_short_one_and_a_page_out_of_range_is_moved_in() {
		$sections = $this->sections( 3 ); // 12 games.

		$last = Chess_Army_Knife_Tournament_Summary::paginate( $sections, 3, 5 );
		$this->assertSame( array( 11, 12 ), $this->ids( $last ) );
		$this->assertSame( array( 11, 12 ), array( $last['first'], $last['last'] ) );

		$this->assertSame( $this->ids( $last ), $this->ids( Chess_Army_Knife_Tournament_Summary::paginate( $sections, 99, 5 ) ) );
		$this->assertSame( 1, Chess_Army_Knife_Tournament_Summary::paginate( $sections, -4, 5 )['page'] );
	}

	public function test_a_page_size_of_zero_shows_everything_on_one_page() {
		$page = Chess_Army_Knife_Tournament_Summary::paginate( $this->sections( 3 ), 2, 0 );

		$this->assertSame( 12, count( $this->ids( $page ) ) );
		$this->assertSame( 1, $page['pages'] );
		$this->assertSame( 1, $page['page'] );
	}

	public function test_no_games_is_one_empty_page() {
		$page = Chess_Army_Knife_Tournament_Summary::paginate( array(), 1, 20 );

		$this->assertSame( array(), $page['sections'] );
		$this->assertSame( array( 1, 0, 0, 0 ), array( $page['pages'], $page['total'], $page['first'], $page['last'] ) );
	}
}
