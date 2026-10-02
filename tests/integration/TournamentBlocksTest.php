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
		foreach ( array( 'games', 'entries', 'tournaments', 'members' ) as $name ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Tournament_Store::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Tournament_Store::install_tables();
	}

	/**
	 * Create a tournament of $count manually rated players, optionally started.
	 *
	 * @return int Tournament id.
	 */
	private function tournament( $name, $count, $start = true, array $args = array() ) {
		$id = Chess_Army_Knife_Tournaments::create( array_merge( array( 'name' => $name ), $args ) );
		$this->assertIsInt( $id );

		for ( $i = 1; $i <= $count; $i++ ) {
			$player = Chess_Army_Knife_Membership_Store::add_guest(
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
		// Empty attributes must be an object ({}), not an array ([]), to parse as a block.
		return do_blocks( '<!-- wp:chess-army-knife/' . $block . ' ' . wp_json_encode( (object) $attributes ) . ' /-->' );
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

	public function test_games_block_shows_rounds_a_page_with_previous_and_next_links() {
		$id   = $this->tournament( 'Big', 8 ); // Seven rounds of four games.
		$_GET = array();

		$first = $this->render( 'tournament-games', array( 'tournamentId' => $id ) );
		$this->assertSame( 4, substr_count( $first, 'data-game-id=' ), 'One round by default.' );
		$this->assertStringContainsString( 'Round 1', $first );
		$this->assertStringContainsString( 'Page 1 of 7: Round 1', $first );
		$this->assertStringContainsString( 'rel="next"', $first );
		$this->assertStringNotContainsString( 'rel="prev"', $first );

		$_GET  = array( 'cak_games_' . $id => '3' );
		$third = $this->render(
			'tournament-games',
			array(
				'tournamentId'  => $id,
				'roundsPerPage' => 3,
			)
		);
		$this->assertSame( 4, substr_count( $third, 'data-game-id=' ), 'Page 3 of 3 is the seventh round alone.' );
		$this->assertStringContainsString( 'Page 3 of 3: Round 7', $third );
		$this->assertStringContainsString( 'rel="prev"', $third );
		$this->assertStringNotContainsString( 'rel="next"', $third );

		$_GET = array( 'cak_games_' . $id => '99' );
		$this->assertStringContainsString( 'Page 7 of 7', $this->render( 'tournament-games', array( 'tournamentId' => $id ) ), 'A page past the end shows the last.' );

		$_GET = array();
		$two  = $this->render(
			'tournament-games',
			array(
				'tournamentId'  => $id,
				'roundsPerPage' => 2,
			)
		);
		$this->assertSame( 8, substr_count( $two, 'data-game-id=' ) );
		$this->assertStringContainsString( 'Round 1 to Round 2', $two );

		$all = $this->render(
			'tournament-games',
			array(
				'tournamentId'  => $id,
				'roundsPerPage' => 0,
			)
		);
		$this->assertSame( 28, substr_count( $all, 'data-game-id=' ) );
		$this->assertStringNotContainsString( 'cak-games__pages', $all, 'No page links when everything is on one page.' );
	}

	public function test_winners_block_renders_a_table_or_an_empty_message() {
		$this->assertStringContainsString( 'No tournaments have finished', $this->render( 'tournament-winners' ) );

		$id = $this->tournament( 'Spring Open', 3 );
		$this->finish( $id );
		$html = $this->render( 'tournament-winners', array( 'title' => 'Hall of fame' ) );
		$this->assertStringContainsString( 'Hall of fame', $html );
		$this->assertStringContainsString( 'Spring Open Player 1', $html );
	}

	private function row_named( array $table, $name ) {
		foreach ( $table['rows'] as $row ) {
			if ( $name === $row['name'] ) {
				return $row;
			}
		}
		return null;
	}

	public function test_the_cross_table_lists_each_rounds_points_and_the_total_in_rank_order() {
		$id = $this->tournament( 'Cup', 4 );
		$this->finish( $id );

		$table = Chess_Army_Knife_Tournament_Summary::crosstable( Chess_Army_Knife_Tournament_Store::get_tournament( $id ), 0 );

		$this->assertSame( array( 1, 2, 3 ), $table['rounds'] );
		$this->assertCount( 4, $table['rows'] );
		$this->assertSame( array( 1, 2, 3, 4 ), array_column( $table['rows'], 'rank' ) );
		$this->assertSame( 6.0, array_sum( array_column( $table['rows'], 'total' ) ) ); // Six games, a point each.
		foreach ( $table['rows'] as $row ) {
			$this->assertSame( $row['total'], array_sum( $row['scores'] ) );
			$this->assertCount( 3, $row['scores'] );
		}
		$totals = array_column( $table['rows'], 'total' );
		$sorted = $totals;
		rsort( $sorted );
		$this->assertSame( $sorted, $totals );
	}

	public function test_the_cross_table_leaves_games_without_a_result_blank() {
		$id    = $this->tournament( 'Cup', 4 );
		$games = Chess_Army_Knife_Tournaments::games_to_play( $id );
		Chess_Army_Knife_Tournaments::record_result( $games[0]['id'], '1/2-1/2' );

		$table = Chess_Army_Knife_Tournament_Summary::crosstable( Chess_Army_Knife_Tournament_Store::get_tournament( $id ), 0 );

		$played = array_filter(
			$table['rows'],
			function ( $row ) {
				return null !== $row['scores'][1];
			}
		);
		$this->assertCount( 2, $played );
		foreach ( $played as $row ) {
			$this->assertSame( 0.5, $row['scores'][1] );
			$this->assertNull( $row['scores'][2] );
		}
	}

	public function test_the_cross_table_shows_swiss_byes_and_requested_byes() {
		$id = $this->tournament(
			'Open',
			3,
			true,
			array(
				'format' => 'swiss',
				'rounds' => 2,
			)
		);
		$this->finish( $id );

		$requested = Chess_Army_Knife_Tournament_Store::get_entries( $id )[0];
		Chess_Army_Knife_Tournaments::request_bye( $id, $requested['id'], 2, 'half' );
		$this->assertSame( 2, Chess_Army_Knife_Tournaments::next_round( $id ) );

		$table = Chess_Army_Knife_Tournament_Summary::crosstable( Chess_Army_Knife_Tournament_Store::get_tournament( $id ), 0 );

		$this->assertSame( array( 1, 2 ), $table['rounds'] );
		$this->assertSame( 0.5, $this->row_named( $table, $requested['name'] )['scores'][2] );
		// Round 1's pairing bye scored a point for somebody.
		$round_one = array_column( array_column( $table['rows'], 'scores' ), 1 );
		$this->assertCount( 3, array_filter( $round_one, 'is_float' ) );
		$this->assertSame( 2.0, array_sum( $round_one ) ); // A game and a bye point.
	}

	public function test_the_standings_block_shows_a_table_with_a_column_per_round() {
		$id = $this->tournament( 'Cup', 4 );
		$this->finish( $id );

		$html = do_blocks( '<!-- wp:chess-army-knife/tournament-standings {"tournamentId":' . $id . '} /-->' );

		foreach ( array( '>R1<', '>R2<', '>R3<', '>Total<', 'Cup Player 1' ) as $expected ) {
			$this->assertStringContainsString( $expected, $html );
		}
		$this->assertStringNotContainsString( '>R4<', $html );
	}

	public function test_the_standings_block_has_a_table_per_group() {
		$id = $this->tournament( 'Groups', 8, true, array( 'groups' => 2 ) );

		$html = do_blocks( '<!-- wp:chess-army-knife/tournament-standings {"tournamentId":' . $id . '} /-->' );

		$this->assertSame( 2, substr_count( $html, '<table' ) );
		$this->assertStringContainsString( 'Group A', $html );
		$this->assertStringContainsString( 'Group B', $html );
	}

	public function test_the_standings_block_handles_unstarted_knockout_and_missing_tournaments() {
		$draft = $this->tournament( 'Draft', 3, false );
		$this->assertStringContainsString( 'not started', do_blocks( '<!-- wp:chess-army-knife/tournament-standings {"tournamentId":' . $draft . '} /-->' ) );

		$knockout = $this->tournament( 'KO', 4, true, array( 'format' => 'knockout' ) );
		$html     = do_blocks( '<!-- wp:chess-army-knife/tournament-standings {"tournamentId":' . $knockout . '} /-->' );
		$this->assertStringContainsString( 'no standings table', $html );
		$this->assertStringNotContainsString( '<table', $html );

		$this->assertStringContainsString( 'choose a tournament', do_blocks( '<!-- wp:chess-army-knife/tournament-standings {} /-->' ) );
	}
}
