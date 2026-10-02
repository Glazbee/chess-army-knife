<?php
/**
 * Tests for paging the games to play by rounds.
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

	public function test_a_page_holds_whole_rounds_and_the_pages_cover_every_round_once() {
		$sections = $this->sections( 7 );

		$first = Chess_Army_Knife_Tournament_Summary::paginate( $sections, 1, 3 );
		$this->assertSame( array( 'Round 1', 'Round 2', 'Round 3' ), $first['labels'] );
		$this->assertCount( 4, $first['sections']['Round 1'], 'A round is never split.' );
		$this->assertSame( 3, $first['pages'] );
		$this->assertSame( 7, $first['rounds'] );

		$all = array();
		for ( $page = 1; $page <= 3; $page++ ) {
			$all = array_merge( $all, Chess_Army_Knife_Tournament_Summary::paginate( $sections, $page, 3 )['labels'] );
		}
		$this->assertSame( array_keys( $sections ), $all );
	}

	public function test_one_round_a_page_is_the_default_shape() {
		$page = Chess_Army_Knife_Tournament_Summary::paginate( $this->sections( 4 ), 2, 1 );

		$this->assertSame( array( 'Round 2' ), $page['labels'] );
		$this->assertSame( 4, $page['pages'] );
	}

	public function test_the_last_page_is_short_and_a_page_out_of_range_is_moved_in() {
		$sections = $this->sections( 7 );

		$this->assertSame( array( 'Round 7' ), Chess_Army_Knife_Tournament_Summary::paginate( $sections, 3, 3 )['labels'] );
		$this->assertSame( array( 'Round 7' ), Chess_Army_Knife_Tournament_Summary::paginate( $sections, 99, 3 )['labels'] );
		$this->assertSame( 1, Chess_Army_Knife_Tournament_Summary::paginate( $sections, -4, 3 )['page'] );
	}

	public function test_zero_rounds_a_page_shows_every_round_on_one_page() {
		$page = Chess_Army_Knife_Tournament_Summary::paginate( $this->sections( 5 ), 2, 0 );

		$this->assertCount( 5, $page['sections'] );
		$this->assertSame( array( 1, 1 ), array( $page['pages'], $page['page'] ) );
	}

	public function test_no_games_is_one_empty_page() {
		$page = Chess_Army_Knife_Tournament_Summary::paginate( array(), 1, 1 );

		$this->assertSame( array(), $page['sections'] );
		$this->assertSame( array( 1, 0 ), array( $page['pages'], $page['rounds'] ) );
	}
}
