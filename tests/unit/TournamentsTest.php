<?php
/**
 * Unit tests for the pure parts of Chess_Army_Knife_Tournaments and the Players page validation.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class TournamentsTest extends Chess_Army_Knife_TestCase {

	private function entry( $name, $rating ) {
		return array(
			'name'         => $name,
			'start_rating' => $rating,
		);
	}

	public function test_seed_order_is_by_rating_then_name_with_unrated_last() {
		$ordered = Chess_Army_Knife_Tournaments::seed_order(
			array(
				$this->entry( 'Zed', null ),
				$this->entry( 'Bea', 1800 ),
				$this->entry( 'Al', 1800 ),
				$this->entry( 'Cy', 2000 ),
				$this->entry( 'Abe', null ),
			)
		);

		$this->assertSame( array( 'Cy', 'Al', 'Bea', 'Abe', 'Zed' ), array_column( $ordered, 'name' ) );
	}

	public function test_capability_can_be_filtered() {
		$this->assertSame( 'manage_options', Chess_Army_Knife_Tournaments::capability() );

		Functions\when( 'apply_filters' )->justReturn( 'edit_others_posts' );
		$this->assertSame( 'edit_others_posts', Chess_Army_Knife_Tournaments::capability() );
	}

	public function test_supported_formats() {
		$this->assertArrayHasKey( 'round-robin', Chess_Army_Knife_Tournaments::formats() );
		$this->assertArrayHasKey( 'knockout', Chess_Army_Knife_Tournaments::formats() );
		$this->assertArrayHasKey( 'swiss', Chess_Army_Knife_Tournaments::formats() );
	}

	public function test_swiss_config_and_round_limit() {
		$swiss = array(
			'format'   => 'swiss',
			'settings' => array(
				'rounds'  => 6,
				'groups'  => 3,
				'advance' => 2,
			),
		);

		$this->assertSame(
			array(
				'groups'         => 1,
				'advance'        => 0,
				'rounds'         => 6,
				'initial_colour' => 'white',
			),
			Chess_Army_Knife_Tournaments::config( $swiss )
		);
		$this->assertFalse( Chess_Army_Knife_Tournaments::has_knockout_stage( $swiss ) );
		$this->assertSame(
			5,
			Chess_Army_Knife_Tournaments::config(
				array(
					'format'   => 'swiss',
					'settings' => array(),
				)
			)['rounds']
		);

		// Nobody can meet twice, so there can be at most players - 1 rounds.
		$this->assertTrue( Chess_Army_Knife_Tournaments::check_player_count( 7, Chess_Army_Knife_Tournaments::config( $swiss ) ) );
		$this->assertSame( 'tournament_rounds_players', Chess_Army_Knife_Tournaments::check_player_count( 6, Chess_Army_Knife_Tournaments::config( $swiss ) )->get_error_code() );
	}

	public function test_players_are_dealt_into_groups_snake_style() {
		// 8 players into 4 groups: 1-2-3-4 then 4-3-2-1.
		$this->assertSame( array( 1, 2, 3, 4, 4, 3, 2, 1 ), Chess_Army_Knife_Tournaments::snake_groups( 8, 4 ) );
		// 8 players into 2 groups: 1-2, 2-1, 1-2, 2-1.
		$this->assertSame( array( 1, 2, 2, 1, 1, 2, 2, 1 ), Chess_Army_Knife_Tournaments::snake_groups( 8, 2 ) );
		// Uneven: 7 players into 3 groups; group sizes differ by at most one.
		$assignment = Chess_Army_Knife_Tournaments::snake_groups( 7, 3 );
		$sizes      = array_count_values( $assignment );
		$this->assertSame( 7, array_sum( $sizes ) );
		$this->assertLessThanOrEqual( 1, max( $sizes ) - min( $sizes ) );
	}

	public function test_config_defaults_and_knockout_ignores_groups() {
		$round_robin = array(
			'format'   => 'round-robin',
			'settings' => array(
				'groups'  => 4,
				'advance' => 2,
			),
		);
		$this->assertSame(
			array(
				'groups'         => 4,
				'advance'        => 2,
				'rounds'         => 0,
				'initial_colour' => 'white',
			),
			Chess_Army_Knife_Tournaments::config( $round_robin )
		);
		$this->assertTrue( Chess_Army_Knife_Tournaments::has_knockout_stage( $round_robin ) );

		$plain = array(
			'format'   => 'round-robin',
			'settings' => array(),
		);
		$this->assertSame(
			array(
				'groups'         => 1,
				'advance'        => 0,
				'rounds'         => 0,
				'initial_colour' => 'white',
			),
			Chess_Army_Knife_Tournaments::config( $plain )
		);
		$this->assertFalse( Chess_Army_Knife_Tournaments::has_knockout_stage( $plain ) );

		$knockout = array(
			'format'   => 'knockout',
			'settings' => array(
				'groups'  => 4,
				'advance' => 2,
			),
		);
		$this->assertSame(
			array(
				'groups'         => 1,
				'advance'        => 0,
				'rounds'         => 0,
				'initial_colour' => 'white',
			),
			Chess_Army_Knife_Tournaments::config( $knockout )
		);
		$this->assertTrue( Chess_Army_Knife_Tournaments::has_knockout_stage( $knockout ) );
	}

	public function test_player_count_rules() {
		$one_group = array(
			'groups'  => 1,
			'advance' => 0,
		);
		$this->assertInstanceOf( WP_Error::class, Chess_Army_Knife_Tournaments::check_player_count( 1, $one_group ) );
		$this->assertTrue( Chess_Army_Knife_Tournaments::check_player_count( 2, $one_group ) );

		$four_groups = array(
			'groups'  => 4,
			'advance' => 2,
		);
		$this->assertSame( 'tournament_groups_players', Chess_Army_Knife_Tournaments::check_player_count( 7, $four_groups )->get_error_code() );
		// 8 players in 4 groups of 2: advancing 2 would be everyone.
		$this->assertSame( 'tournament_advance_players', Chess_Army_Knife_Tournaments::check_player_count( 8, $four_groups )->get_error_code() );
		$this->assertTrue( Chess_Army_Knife_Tournaments::check_player_count( 16, $four_groups ) );
	}

	public function test_group_labels() {
		$this->assertSame( 'Group A', Chess_Army_Knife_Tournaments::group_label( 1 ) );
		$this->assertSame( 'Group D', Chess_Army_Knife_Tournaments::group_label( 4 ) );
	}

	public function test_lookup_rating_uses_manual_rating_without_an_ecf_code() {
		$result = Chess_Army_Knife_Tournaments::lookup_rating(
			array(
				'ecf_code'      => '',
				'manual_rating' => 1234,
			),
			'S'
		);

		$this->assertSame(
			array(
				'rating' => 1234,
				'source' => 'manual',
			),
			$result
		);
	}

	public function test_lookup_rating_reports_none_when_nothing_is_known() {
		$result = Chess_Army_Knife_Tournaments::lookup_rating(
			array(
				'ecf_code'      => '',
				'manual_rating' => null,
			),
			'S'
		);

		$this->assertSame( 'none', $result['source'] );
		$this->assertNull( $result['rating'] );
	}

	public function test_result_options_explain_tie_breaks_in_knockouts() {
		$this->assertSame( '½-½', Chess_Army_Knife_Tournaments_Page::result_options( false )['1/2-1/2'] );
		$this->assertStringContainsString( 'tie-break', Chess_Army_Knife_Tournaments_Page::result_options( true )['1/2-1/2'] );
	}

	public function test_player_profile_validation() {
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );

		$clean = Chess_Army_Knife_Player_Selector::sanitize_player(
			array(
				'name'          => '  Alice ',
				'ecf_code'      => ' 120787j ',
				'manual_rating' => '1650',
			)
		);
		$this->assertSame(
			array(
				'name'          => 'Alice',
				'ecf_code'      => '120787J',
				'manual_rating' => 1650,
			),
			$clean
		);

		$this->assertSame( 'player_name', Chess_Army_Knife_Player_Selector::sanitize_player( array( 'name' => '  ' ) )->get_error_code() );
		$this->assertSame(
			'player_rating',
			Chess_Army_Knife_Player_Selector::sanitize_player(
				array(
					'name'          => 'A',
					'manual_rating' => '9999',
				)
			)->get_error_code()
		);
		$this->assertNull(
			Chess_Army_Knife_Player_Selector::sanitize_player(
				array(
					'name'          => 'A',
					'manual_rating' => '',
				)
			)['manual_rating']
		);
	}

	public function test_a_manual_rating_must_be_1300_or_higher() {
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );

		$below = Chess_Army_Knife_Player_Selector::sanitize_player(
			array(
				'name'          => 'A',
				'manual_rating' => '1299',
			)
		);
		$this->assertSame( 'player_rating', $below->get_error_code() );
		$this->assertStringContainsString( '1300', $below->get_error_message() );

		$this->assertSame(
			1300,
			Chess_Army_Knife_Player_Selector::sanitize_player(
				array(
					'name'          => 'A',
					'manual_rating' => '1300',
				)
			)['manual_rating']
		);
	}

	public function test_new_players_from_the_selector_are_cleaned_and_deduplicated() {
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );

		$parsed = Chess_Army_Knife_Player_Selector::parse_new_players(
			array(
				array(
					'name'     => 'Alice',
					'ecf_code' => '120787j',
				),
				array(
					'name'     => 'Alice again',
					'ecf_code' => '120787J',
				),
				array(
					'name'          => 'Bob',
					'ecf_code'      => '',
					'manual_rating' => '1450',
				),
				array(
					'name'          => 'Cy',
					'manual_rating' => '900',
				),
				'not a player',
				array( 'name' => '  ' ),
			)
		);

		$this->assertSame( array( 'Alice', 'Bob' ), array_column( $parsed['players'], 'name' ) );
		$this->assertSame( 1450, $parsed['players'][1]['manual_rating'] );
		$this->assertCount( 2, $parsed['errors'] ); // Cy's rating and the blank name.
		$this->assertSame( array(), Chess_Army_Knife_Player_Selector::parse_new_players( 'nonsense' )['players'] );
	}

	public function test_a_tournament_page_shows_status_standings_games_and_players() {
		$content = Chess_Army_Knife_Tournaments::page_content(
			array(
				'id'     => 7,
				'format' => 'swiss',
			)
		);

		$order = array();
		foreach ( array( 'tournament-status', 'tournament-standings', 'tournament-games', 'tournament-players' ) as $block ) {
			$marker = '<!-- wp:chess-army-knife/' . $block . ' {"tournamentId":7} /-->';
			$this->assertStringContainsString( $marker, $content );
			$order[] = strpos( $content, $marker );
		}
		$sorted = $order;
		sort( $sorted );
		$this->assertSame( $sorted, $order );
	}

	public function test_a_knockout_tournament_page_has_no_standings() {
		$content = Chess_Army_Knife_Tournaments::page_content(
			array(
				'id'     => 7,
				'format' => 'knockout',
			)
		);

		$this->assertStringNotContainsString( 'tournament-standings', $content );
		$this->assertStringContainsString( 'tournament-games', $content );
	}

	public function test_rating_from_data_picks_the_first_usable_rating() {
		$this->assertSame( 1720, Chess_Army_Knife_Tournaments::rating_from_data( array( 'revised_rating' => '1720' ) ) );
		$this->assertSame(
			1650,
			Chess_Army_Knife_Tournaments::rating_from_data(
				array(
					'revised_rating'  => 0,
					'original_rating' => 1650,
				)
			)
		);
		$this->assertNull( Chess_Army_Knife_Tournaments::rating_from_data( array( 'revised_rating' => 'none' ) ) );
		$this->assertNull( Chess_Army_Knife_Tournaments::rating_from_data( new WP_Error( 'x', 'y' ) ) );
	}

	public function test_points_are_formatted_with_halves() {
		$this->assertSame( '0', Chess_Army_Knife_Tournaments_Page::format_points( 0.0 ) );
		$this->assertSame( '½', Chess_Army_Knife_Tournaments_Page::format_points( 0.5 ) );
		$this->assertSame( '3', Chess_Army_Knife_Tournaments_Page::format_points( 3.0 ) );
		$this->assertSame( '2½', Chess_Army_Knife_Tournaments_Page::format_points( 2.5 ) );
	}

	public function test_a_round_paired_with_a_cut_short_search_is_flagged() {
		$tournament = array(
			'settings' => array( 'approximate_rounds' => array( '3', 5 ) ),
		);

		$this->assertTrue( Chess_Army_Knife_Tournaments::is_round_approximate( $tournament, 3 ) );
		$this->assertTrue( Chess_Army_Knife_Tournaments::is_round_approximate( $tournament, 5 ) );
		$this->assertFalse( Chess_Army_Knife_Tournaments::is_round_approximate( $tournament, 4 ) );
		$this->assertFalse( Chess_Army_Knife_Tournaments::is_round_approximate( array( 'settings' => array() ), 1 ) );
	}
}
