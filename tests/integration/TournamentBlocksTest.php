<?php
/**
 * Integration tests: the public tournament blocks (status, games to play, past winners).
 *
 * @package Chess_Army_Knife
 */

class TournamentBlocksTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( 'games', 'entries', 'tournaments', 'players' ) as $name ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Tournament_Store::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Tournament_Store::install_tables();
	}

	/**
	 * Create a tournament of $count manually rated players, optionally started.
	 *
	 * @return int Tournament id.
	 */
	private function tournament( $name, $count, $start = true ) {
		$id = Chess_Army_Knife_Tournaments::create( array( 'name' => $name ) );
		$this->assertIsInt( $id );

		for ( $i = 1; $i <= $count; $i++ ) {
			$player = Chess_Army_Knife_Tournament_Store::save_player(
				array(
					'name'          => "{$name} Player {$i}",
					'ecf_code'      => '',
					'manual_rating' => 2200 - $i * 10,
				)
			);
			Chess_Army_Knife_Tournaments::add_player( $id, $player );
		}

		if ( $start ) {
			$this->assertTrue( Chess_Army_Knife_Tournaments::start( $id ) );
		}
		return $id;
	}

	/**
	 * Give every game of a tournament a white win.
	 */
	private function finish( $tournament_id ) {
		foreach ( Chess_Army_Knife_Tournaments::games_to_play( $tournament_id ) as $game ) {
			Chess_Army_Knife_Tournaments::record_result( $game['id'], '1-0' );
		}
	}

	private function render( $block, array $attributes = array() ) {
		return do_blocks( '<!-- wp:chess-army-knife/' . $block . ' ' . wp_json_encode( $attributes ) . ' /-->' );
	}

	public function test_status_of_a_draft_and_a_running_tournament() {
		$id = $this->tournament( 'Club Championship', 4, false );

		$draft = Chess_Army_Knife_Tournament_Summary::status( Chess_Army_Knife_Tournament_Store::get_tournament( $id ) );
		$this->assertSame( 'draft', $draft['status'] );
		$this->assertSame( 4, $draft['players'] );
		$this->assertSame( 0, $draft['games_total'] );
		$this->assertNull( $draft['champion'] );

		Chess_Army_Knife_Tournaments::start( $id );
		$games   = Chess_Army_Knife_Tournaments::games_to_play( $id );
		$running = Chess_Army_Knife_Tournament_Summary::status( Chess_Army_Knife_Tournament_Store::get_tournament( $id ) );
		$this->assertSame( 'active', $running['status'] );
		$this->assertSame( 6, $running['games_total'] );
		$this->assertSame( 0, $running['games_played'] );

		Chess_Army_Knife_Tournaments::record_result( $games[0]['id'], '1-0' );
		$running = Chess_Army_Knife_Tournament_Summary::status( Chess_Army_Knife_Tournament_Store::get_tournament( $id ) );
		$this->assertSame( 1, $running['games_played'] );
	}

	public function test_games_to_play_shrink_as_results_are_entered() {
		$id = $this->tournament( 'Club Championship', 4 );

		$sections = Chess_Army_Knife_Tournament_Summary::games_to_play( $id );
		$this->assertSame( array( 'Round 1', 'Round 2', 'Round 3' ), array_keys( $sections ) );
		$this->assertCount( 2, $sections['Round 1'] );

		$this->finish( $id );
		$this->assertSame( array(), Chess_Army_Knife_Tournament_Summary::games_to_play( $id ) );
	}

	public function test_winners_lists_only_finished_tournaments_newest_first() {
		$first  = $this->tournament( 'Spring Open', 3 );
		$second = $this->tournament( 'Autumn Open', 3 );
		$this->tournament( 'Unfinished', 3 );
		$this->tournament( 'Not started', 3, false );

		$this->finish( $first );
		$this->finish( $second );
		Chess_Army_Knife_Tournament_Store::save_tournament(
			array(
				'id'           => $first,
				'completed_at' => '2026-03-01 12:00:00',
			)
		);
		Chess_Army_Knife_Tournament_Store::save_tournament(
			array(
				'id'           => $second,
				'completed_at' => '2026-09-01 12:00:00',
			)
		);

		$winners = Chess_Army_Knife_Tournament_Summary::winners();
		$this->assertSame( array( 'Autumn Open', 'Spring Open' ), array_column( $winners, 'tournament' ) );
		$this->assertSame( 'Autumn Open Player 1', $winners[0]['winner'] );
		$this->assertCount( 1, Chess_Army_Knife_Tournament_Summary::winners( 1 ) );
	}

	public function test_winners_is_empty_when_nothing_has_finished() {
		$this->tournament( 'Unfinished', 3 );
		$this->assertSame( array(), Chess_Army_Knife_Tournament_Summary::winners() );
	}

	public function test_status_block_renders_for_visitors() {
		$id   = $this->tournament( 'Club Championship', 4 );
		$html = $this->render( 'tournament-status', array( 'tournamentId' => $id ) );

		$this->assertStringContainsString( 'Club Championship', $html );
		$this->assertStringContainsString( 'In progress', $html );
		$this->assertStringContainsString( '0 of 6', $html );
	}

	public function test_blocks_ask_for_a_tournament_when_none_is_chosen() {
		$this->assertStringContainsString( 'choose a tournament', $this->render( 'tournament-status' ) );
		$this->assertStringContainsString( 'choose a tournament', $this->render( 'tournament-games', array( 'tournamentId' => 999 ) ) );
	}

	public function test_games_block_lists_pairings_and_handles_draft_and_finished() {
		$draft = $this->tournament( 'Draft Cup', 3, false );
		$this->assertStringContainsString( 'not started yet', $this->render( 'tournament-games', array( 'tournamentId' => $draft ) ) );

		$id   = $this->tournament( 'Club Championship', 4 );
		$html = $this->render( 'tournament-games', array( 'tournamentId' => $id ) );
		$this->assertStringContainsString( 'Round 1', $html );
		$this->assertStringContainsString( 'Club Championship Player 1', $html );

		$this->finish( $id );
		$this->assertStringContainsString( 'no games waiting', $this->render( 'tournament-games', array( 'tournamentId' => $id ) ) );
	}

	public function test_winners_block_renders_a_table_or_an_empty_message() {
		$this->assertStringContainsString( 'No tournaments have finished', $this->render( 'tournament-winners' ) );

		$id = $this->tournament( 'Spring Open', 3 );
		$this->finish( $id );
		$html = $this->render( 'tournament-winners', array( 'title' => 'Hall of fame' ) );
		$this->assertStringContainsString( 'Hall of fame', $html );
		$this->assertStringContainsString( 'Spring Open Player 1', $html );
	}
}
