<?php
/**
 * Tests for the tournament CSV export.
 *
 * @package Chess_Army_Knife
 */

class TournamentExportTest extends Chess_Army_Knife_TestCase {

	protected function entry( $id, $name, array $fields = array() ) {
		return $fields + array(
			'id'            => $id,
			'name'          => $name,
			'ecf_code'      => '1000' . $id . 'A',
			'start_rating'  => 1500 + $id,
			'rating_source' => 'ecf',
			'seed'          => $id,
			'status'        => 'active',
		);
	}

	protected function game( $round, $white, $black, $result, array $fields = array() ) {
		return $fields + array(
			'stage'          => 'main',
			'round'          => $round,
			'white_entry_id' => $white,
			'black_entry_id' => $black,
			'result'         => $result,
			'is_bye'         => 0,
		);
	}

	protected function tournament( $format = 'swiss' ) {
		return array(
			'id'     => 1,
			'name'   => 'Club Championship',
			'format' => $format,
		);
	}

	public function test_a_row_gives_colour_opponent_and_result_for_each_round() {
		$entries = array( $this->entry( 1, 'Cave, Nathan' ), $this->entry( 2, 'Smith, Jane' ) );
		$games   = array( $this->game( 1, 1, 2, '1-0' ), $this->game( 2, 2, 1, '1/2-1/2' ) );

		$rows = Chess_Army_Knife_Tournament_Export::rows( $this->tournament(), $entries, $games );

		$this->assertCount( 3, $rows );
		$this->assertCount( count( $rows[0] ), $rows[1] );
		$this->assertSame( array( 'Cave, Nathan', '10001A', '1501', 'ECF rating list', '1', 'Playing', 'W', 'Smith, Jane', '1', 'B', 'Smith, Jane', '0.5', '1.5' ), $rows[1] );
		$this->assertSame( array( 'Smith, Jane', '10002A', '1502', 'ECF rating list', '2', 'Playing', 'B', 'Cave, Nathan', '0', 'W', 'Cave, Nathan', '0.5', '0.5' ), $rows[2] );
	}

	public function test_games_without_a_result_are_left_out() {
		$entries = array( $this->entry( 1, 'Cave, Nathan' ), $this->entry( 2, 'Smith, Jane' ) );
		$games   = array( $this->game( 1, 1, 2, '1-0' ), $this->game( 2, 2, 1, null ) );

		$rows = Chess_Army_Knife_Tournament_Export::rows( $this->tournament(), $entries, $games );

		$this->assertCount( 6 + 3 + 1, $rows[0], 'Only the round with a result has columns.' );
	}

	public function test_byes_have_no_colour_and_score_by_kind() {
		$entries = array( $this->entry( 1, 'Cave, Nathan' ), $this->entry( 2, 'Smith, Jane' ), $this->entry( 3, 'Jones, Pat' ) );
		$games   = array(
			$this->game( 1, 1, 2, '1-0' ),
			$this->game( 1, 3, null, '1-0', array( 'is_bye' => 1 ) ),
			$this->game( 2, 3, null, 'bye-half', array( 'is_bye' => 1 ) ),
		);

		$rows = Chess_Army_Knife_Tournament_Export::rows( $this->tournament(), $entries, $games );

		$this->assertSame( array( '', 'Bye', '1', '', 'Bye', '0.5', '1.5' ), array_slice( $rows[3], 6 ) );
	}

	public function test_a_round_robin_bye_scores_nothing() {
		$entries = array( $this->entry( 1, 'Cave, Nathan' ) );
		$games   = array( $this->game( 1, 1, null, '1-0', array( 'is_bye' => 1 ) ) );

		$rows = Chess_Army_Knife_Tournament_Export::rows( $this->tournament( 'round-robin' ), $entries, $games );

		$this->assertSame( '0', end( $rows[1] ) );
	}

	public function test_a_forfeit_is_marked_and_a_player_without_a_rating_is_blank() {
		$entries = array(
			$this->entry(
				1,
				'Cave, Nathan',
				array(
					'start_rating'  => null,
					'rating_source' => 'none',
					'ecf_code'      => '',
					'seed'          => null,
					'status'        => 'withdrawn',
				)
			),
			$this->entry( 2, 'Smith, Jane' ),
		);
		$games   = array( $this->game( 1, 1, 2, '-+' ) );

		$rows = Chess_Army_Knife_Tournament_Export::rows( $this->tournament(), $entries, $games );

		$this->assertSame( array( 'Cave, Nathan', '', '', '', '', 'Withdrawn', 'W', 'Smith, Jane', '0 (forfeit)', '0' ), $rows[1] );
	}

	public function test_a_name_that_looks_like_a_formula_is_made_safe() {
		$csv = Chess_Army_Knife_Tournament_Export::to_csv( $this->tournament(), array( $this->entry( 1, '=HYPERLINK("x")' ) ), array( $this->game( 1, 1, null, '1-0', array( 'is_bye' => 1 ) ) ) );

		$this->assertSame( "\xEF\xBB\xBF", substr( $csv, 0, 3 ) );
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
	}

	public function test_knockout_rounds_follow_the_main_stage_with_tie_breaks_joined() {
		$entries = array( $this->entry( 1, 'Cave, Nathan' ), $this->entry( 2, 'Smith, Jane' ) );
		$games   = array(
			$this->game( 1, 1, 2, '1-0' ),
			$this->game( 1, 1, 2, '1/2-1/2', array( 'stage' => 'knockout' ) ),
			$this->game( 1, 2, 1, '0-1', array( 'stage' => 'knockout' ) ),
		);

		$rows = Chess_Army_Knife_Tournament_Export::rows( $this->tournament( 'round-robin' ), $entries, $games );

		$this->assertCount( 6 + 3 + 3 + 1, $rows[0] );
		$this->assertSame( array( 'W / B', 'Smith, Jane / Smith, Jane', '0.5 / 1' ), array_slice( $rows[1], 9, 3 ) );
	}
}
