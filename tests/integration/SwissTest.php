<?php
/**
 * Integration tests: Swiss tournaments (FIDE Dutch system) running against real WordPress.
 *
 * @package Chess_Army_Knife
 */

class SwissTest extends WP_UnitTestCase {

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
	 * Create and start a Swiss with $count players (Player 1 is the top seed).
	 *
	 * @param array $args Extra arguments for Chess_Army_Knife_Tournaments::create().
	 * @return int Tournament id.
	 */
	private function started( $count, $rounds, array $args = array() ) {
		$id = Chess_Army_Knife_Tournaments::create(
			array_merge(
				array(
					'name'   => 'Open',
					'format' => 'swiss',
					'rounds' => $rounds,
				),
				$args
			)
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		for ( $i = 1; $i <= $count; $i++ ) {
			$player = Chess_Army_Knife_Membership_Store::add_guest(
				array(
					'name'          => "Player {$i}",
					'ecf_code'      => '',
					'manual_rating' => 2200 - $i * 10,
				)
			);
			Chess_Army_Knife_Tournaments::add_player( $id, $player );
		}

		$started = Chess_Army_Knife_Tournaments::start( $id );
		$this->assertTrue( $started, is_wp_error( $started ) ? $started->get_error_message() : '' );
		return $id;
	}

	private function round_games( $tournament_id, $round ) {
		return array_values(
			array_filter(
				Chess_Army_Knife_Tournament_Store::get_games( $tournament_id ),
				function ( $game ) use ( $round ) {
					return 'main' === $game['stage'] && $game['round'] === $round;
				}
			)
		);
	}

	/**
	 * Play every real game of a round; the higher seed (lower seed number) wins.
	 */
	private function play_round( $tournament_id, $round ) {
		$seed_of = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id ) as $entry ) {
			$seed_of[ $entry['id'] ] = $entry['seed'];
		}
		foreach ( $this->round_games( $tournament_id, $round ) as $game ) {
			if ( $game['is_bye'] || null !== $game['result'] ) {
				continue;
			}
			$white_wins = $seed_of[ $game['white_entry_id'] ] < $seed_of[ $game['black_entry_id'] ];
			$updated    = Chess_Army_Knife_Tournaments::record_result( $game['id'], $white_wins ? '1-0' : '0-1' );
			$this->assertNotWPError( $updated );
		}
	}

	/**
	 * Unordered pairs "a-b" of the real games in a round.
	 */
	private function pairs( $tournament_id, $round ) {
		$pairs = array();
		foreach ( $this->round_games( $tournament_id, $round ) as $game ) {
			if ( ! $game['is_bye'] ) {
				$ids     = array( $game['white_entry_id'], $game['black_entry_id'] );
				$pairs[] = min( $ids ) . '-' . max( $ids );
			}
		}
		sort( $pairs );
		return $pairs;
	}

	public function test_creating_a_swiss_needs_a_sensible_number_of_rounds() {
		$this->assertWPError(
			Chess_Army_Knife_Tournaments::create(
				array(
					'name'   => 'X',
					'format' => 'swiss',
					'rounds' => 0,
				)
			)
		);
		$this->assertWPError(
			Chess_Army_Knife_Tournaments::create(
				array(
					'name'   => 'X',
					'format' => 'swiss',
					'rounds' => 99,
				)
			)
		);
		$this->assertIsInt(
			Chess_Army_Knife_Tournaments::create(
				array(
					'name'   => 'X',
					'format' => 'swiss',
					'rounds' => 5,
				)
			)
		);
	}

	public function test_too_many_rounds_for_the_field_is_refused_at_the_start() {
		$id = Chess_Army_Knife_Tournaments::create(
			array(
				'name'   => 'X',
				'format' => 'swiss',
				'rounds' => 5,
			)
		);
		for ( $i = 1; $i <= 4; $i++ ) {
			Chess_Army_Knife_Tournaments::add_player(
				$id,
				Chess_Army_Knife_Membership_Store::add_guest(
					array(
						'name'          => "P{$i}",
						'ecf_code'      => '',
						'manual_rating' => 1500 + $i,
					)
				)
			);
		}

		$this->assertSame( 'tournament_rounds_players', Chess_Army_Knife_Tournaments::start( $id )->get_error_code() );
	}

	public function test_starting_pairs_the_first_round_top_half_against_bottom_half() {
		$id      = $this->started( 6, 3 );
		$by_seed = array();
		foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $id ) as $entry ) {
			$by_seed[ $entry['seed'] ] = $entry['id'];
		}

		$expected = array();
		foreach ( array( array( 1, 4 ), array( 2, 5 ), array( 3, 6 ) ) as $pair ) {
			$expected[] = min( $by_seed[ $pair[0] ], $by_seed[ $pair[1] ] ) . '-' . max( $by_seed[ $pair[0] ], $by_seed[ $pair[1] ] );
		}
		sort( $expected );

		$this->assertSame( $expected, $this->pairs( $id, 1 ) );
		$this->assertSame( 1, Chess_Army_Knife_Tournaments::current_round( $id ) );
		$this->assertSame( 'active', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );

		// Seed 1 has White on the odd first board, seed 2 Black on the even one.
		$games = $this->round_games( $id, 1 );
		$this->assertSame( $by_seed[1], $games[0]['white_entry_id'] );
		$this->assertSame( $by_seed[5], $games[1]['white_entry_id'] );
	}

	public function test_the_next_round_needs_every_result_of_the_current_one() {
		$id = $this->started( 6, 3 );

		$this->assertSame( 'swiss_round_unfinished', Chess_Army_Knife_Tournaments::next_round( $id )->get_error_code() );

		$this->play_round( $id, 1 );
		$this->assertSame( 2, Chess_Army_Knife_Tournaments::next_round( $id ) );
		$this->assertCount( 3, $this->round_games( $id, 2 ) );
	}

	public function test_a_whole_tournament_never_repeats_a_pairing_and_finishes_after_the_last_round() {
		$id   = $this->started( 8, 4 );
		$seen = array();

		for ( $round = 1; $round <= 4; $round++ ) {
			if ( $round > 1 ) {
				$this->assertSame( $round, Chess_Army_Knife_Tournaments::next_round( $id ) );
			}
			foreach ( $this->pairs( $id, $round ) as $pair ) {
				$this->assertNotContains( $pair, $seen, "round {$round} repeated {$pair}" );
				$seen[] = $pair;
			}
			$this->assertSame( 'active', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
			$this->play_round( $id, $round );
		}

		$this->assertSame( 'complete', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
		$this->assertSame( 'swiss_last_round', Chess_Army_Knife_Tournaments::next_round( $id )->get_error_code() );

		// The top seed won every game, so wins the tournament.
		$champion = Chess_Army_Knife_Tournaments::champion( $id );
		$this->assertSame( 1, $champion['seed'] );
		$standings = Chess_Army_Knife_Tournaments::standings( $id, 0 );
		$this->assertSame( 4.0, $standings[0]['points'] );
	}

	public function test_an_odd_field_gives_a_different_player_the_bye_each_round() {
		$id       = $this->started( 5, 3 );
		$bye_from = array();

		for ( $round = 1; $round <= 3; $round++ ) {
			if ( $round > 1 ) {
				$this->assertSame( $round, Chess_Army_Knife_Tournaments::next_round( $id ) );
			}
			$byes = array_values(
				array_filter(
					$this->round_games( $id, $round ),
					function ( $game ) {
						return $game['is_bye'];
					}
				)
			);
			$this->assertCount( 1, $byes, "round {$round}" );
			$this->assertNull( $byes[0]['black_entry_id'] );
			$this->assertNotContains( $byes[0]['white_entry_id'], $bye_from );
			$bye_from[] = $byes[0]['white_entry_id'];
			$this->play_round( $id, $round );
		}

		// Every round scores three points in total: two games and a one-point bye.
		$standings = Chess_Army_Knife_Tournaments::standings( $id, 0 );
		$this->assertSame( 9.0, array_sum( wp_list_pluck( $standings, 'points' ) ) ); // Three rounds of two games plus a bye point.
		$this->assertSame( 'complete', Chess_Army_Knife_Tournament_Store::get_tournament( $id )['status'] );
	}

	public function test_a_round_can_be_paired_again_only_before_any_result() {
		$id = $this->started( 6, 3 );
		$this->assertSame( 1, Chess_Army_Knife_Tournaments::redo_round( $id ) );
		$this->assertCount( 3, $this->round_games( $id, 1 ) );

		$first = $this->round_games( $id, 1 )[0];
		Chess_Army_Knife_Tournaments::record_result( $first['id'], '1-0' );

		$this->assertSame( 'swiss_round_started', Chess_Army_Knife_Tournaments::redo_round( $id )->get_error_code() );
	}

	public function test_withdrawing_gives_the_opponent_a_forfeit_win_and_drops_the_player_from_later_rounds() {
		$id      = $this->started( 6, 3 );
		$entries = Chess_Army_Knife_Tournament_Store::get_entries( $id );
		$last    = $entries[5];

		$this->assertTrue( Chess_Army_Knife_Tournaments::withdraw( $last['id'] ) );

		$forfeited = null;
		foreach ( $this->round_games( $id, 1 ) as $game ) {
			if ( $game['white_entry_id'] === $last['id'] || $game['black_entry_id'] === $last['id'] ) {
				$forfeited = $game;
			}
		}
		$this->assertContains( $forfeited['result'], array( '+-', '-+' ) );

		// The forfeit stands in for a win but is not a played game.
		$standings = Chess_Army_Knife_Tournaments::standings( $id, 0 );
		$this->assertSame( 1.0, max( wp_list_pluck( $standings, 'points' ) ) );

		$this->play_round( $id, 1 );
		Chess_Army_Knife_Tournaments::next_round( $id );

		$in_round_two = array();
		foreach ( $this->round_games( $id, 2 ) as $game ) {
			$in_round_two[] = $game['white_entry_id'];
			if ( $game['black_entry_id'] ) {
				$in_round_two[] = $game['black_entry_id'];
			}
		}
		$this->assertNotContains( $last['id'], $in_round_two );
		$this->assertCount( 5, $in_round_two ); // Five players left: two games and a bye.
	}

	public function test_forfeit_results_are_valid_in_a_swiss_but_not_in_a_knockout() {
		$id   = $this->started( 4, 2 );
		$game = $this->round_games( $id, 1 )[0];

		$this->assertNotWPError( Chess_Army_Knife_Tournaments::record_result( $game['id'], '+-' ) );
		$this->assertSame( '+-', Chess_Army_Knife_Tournament_Store::get_game( $game['id'] )['result'] );

		$knockout = Chess_Army_Knife_Tournaments::create(
			array(
				'name'   => 'Cup',
				'format' => 'knockout',
			)
		);
		foreach ( array( 'A', 'B' ) as $name ) {
			Chess_Army_Knife_Tournaments::add_player(
				$knockout,
				Chess_Army_Knife_Membership_Store::add_guest(
					array(
						'name'          => $name,
						'ecf_code'      => '',
						'manual_rating' => 1500,
					)
				)
			);
		}
		Chess_Army_Knife_Tournaments::start( $knockout );
		$final = Chess_Army_Knife_Tournament_Store::get_games( $knockout )[0];

		$this->assertWPError( Chess_Army_Knife_Tournaments::record_result( $final['id'], '+-' ) );
	}

	public function test_next_round_is_only_for_swiss_tournaments() {
		$id = Chess_Army_Knife_Tournaments::create(
			array(
				'name'   => 'League',
				'format' => 'round-robin',
			)
		);

		$this->assertSame( 'swiss_only', Chess_Army_Knife_Tournaments::next_round( $id )->get_error_code() );
	}

	private function entry_named( $tournament_id, $name ) {
		foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id ) as $entry ) {
			if ( $name === $entry['name'] ) {
				return $entry;
			}
		}
		return null;
	}

	public function test_the_initial_colour_decides_the_top_seeds_colour_in_round_one() {
		$default = $this->started( 4, 2 );
		$black   = $this->started( 4, 2, array( 'initial_colour' => 'black' ) );

		$this->assertSame( 'white', Chess_Army_Knife_Tournaments::config( Chess_Army_Knife_Tournament_Store::get_tournament( $default ) )['initial_colour'] );
		$this->assertSame( 'black', Chess_Army_Knife_Tournaments::config( Chess_Army_Knife_Tournament_Store::get_tournament( $black ) )['initial_colour'] );

		$top_default = $this->entry_named( $default, 'Player 1' )['id'];
		$top_black   = $this->entry_named( $black, 'Player 1' )['id'];
		$this->assertSame( $top_default, $this->round_games( $default, 1 )[0]['white_entry_id'] );
		$this->assertSame( $top_black, $this->round_games( $black, 1 )[0]['black_entry_id'] );
	}

	public function test_a_requested_bye_sits_the_player_out_and_scores_its_points() {
		$id     = $this->started( 6, 3 );
		$target = $this->entry_named( $id, 'Player 1' )['id'];

		// Only rounds that are not paired yet, within the tournament, with a valid kind.
		$this->assertWPError( Chess_Army_Knife_Tournaments::request_bye( $id, $target, 1, 'half' ) );
		$this->assertWPError( Chess_Army_Knife_Tournaments::request_bye( $id, $target, 4, 'half' ) );
		$this->assertWPError( Chess_Army_Knife_Tournaments::request_bye( $id, $target, 2, 'full' ) );

		$this->assertTrue( Chess_Army_Knife_Tournaments::request_bye( $id, $target, 2, 'half' ) );
		$this->assertTrue( Chess_Army_Knife_Tournaments::request_bye( $id, $target, 3, 'zero' ) );
		$this->assertTrue( Chess_Army_Knife_Tournaments::cancel_bye( $id, $target, 3 ) );
		$this->assertSame( array( 2 => array( $target => 'half' ) ), Chess_Army_Knife_Tournaments::requested_byes( Chess_Army_Knife_Tournament_Store::get_tournament( $id ) ) );

		$this->play_round( $id, 1 );
		$this->assertSame( 2, Chess_Army_Knife_Tournaments::next_round( $id ) );

		$games = $this->round_games( $id, 2 );
		$byes  = array_values(
			array_filter(
				$games,
				function ( $game ) {
					return $game['is_bye'];
				}
			)
		);
		$asked = array_values(
			array_filter(
				$byes,
				function ( $game ) use ( $target ) {
					return $game['white_entry_id'] === $target;
				}
			)
		);
		$this->assertCount( 1, $asked );
		$this->assertSame( Chess_Army_Knife_Standings::HALF_POINT_BYE, $asked[0]['result'] );
		foreach ( $games as $game ) {
			if ( ! $game['is_bye'] ) {
				$this->assertNotSame( $target, $game['white_entry_id'] );
				$this->assertNotSame( $target, $game['black_entry_id'] );
			}
		}

		// The player scored round 1 (a win as top seed) plus half a point for the bye.
		$rows = array_column( Chess_Army_Knife_Tournaments::standings( $id, 0 ), null, 'entry_id' );
		$this->assertSame( 1.5, $rows[ $target ]['points'] );
	}

	public function test_a_round_with_a_requested_bye_can_still_be_paired_again() {
		$id     = $this->started( 6, 3 );
		$target = $this->entry_named( $id, 'Player 2' )['id'];
		Chess_Army_Knife_Tournaments::request_bye( $id, $target, 2, 'zero' );
		$this->play_round( $id, 1 );
		Chess_Army_Knife_Tournaments::next_round( $id );

		$this->assertSame( 2, Chess_Army_Knife_Tournaments::redo_round( $id ) );

		$asked = array_filter(
			$this->round_games( $id, 2 ),
			function ( $game ) use ( $target ) {
				return $game['is_bye'] && $game['white_entry_id'] === $target;
			}
		);
		$this->assertCount( 1, $asked );
	}

	public function test_a_withdrawn_player_no_longer_receives_a_requested_bye() {
		$id     = $this->started( 6, 3 );
		$target = $this->entry_named( $id, 'Player 6' )['id'];
		Chess_Army_Knife_Tournaments::request_bye( $id, $target, 2, 'half' );
		$this->play_round( $id, 1 );
		Chess_Army_Knife_Tournaments::withdraw( $target );
		Chess_Army_Knife_Tournaments::next_round( $id );

		foreach ( $this->round_games( $id, 2 ) as $game ) {
			$this->assertNotSame( $target, $game['white_entry_id'] );
			$this->assertNotSame( $target, $game['black_entry_id'] );
		}
	}
}
