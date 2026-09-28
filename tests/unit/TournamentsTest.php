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
				'groups'  => 4,
				'advance' => 2,
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
				'groups'  => 1,
				'advance' => 0,
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
				'groups'  => 1,
				'advance' => 0,
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

		$clean = Chess_Army_Knife_Players_Page::sanitize_player(
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

		$this->assertSame( 'player_name', Chess_Army_Knife_Players_Page::sanitize_player( array( 'name' => '  ' ) )->get_error_code() );
		$this->assertSame(
			'player_rating',
			Chess_Army_Knife_Players_Page::sanitize_player(
				array(
					'name'          => 'A',
					'manual_rating' => '9999',
				)
			)->get_error_code()
		);
		$this->assertNull(
			Chess_Army_Knife_Players_Page::sanitize_player(
				array(
					'name'          => 'A',
					'manual_rating' => '',
				)
			)['manual_rating']
		);
	}

	public function test_points_are_formatted_with_halves() {
		$this->assertSame( '0', Chess_Army_Knife_Tournaments_Page::format_points( 0.0 ) );
		$this->assertSame( '½', Chess_Army_Knife_Tournaments_Page::format_points( 0.5 ) );
		$this->assertSame( '3', Chess_Army_Knife_Tournaments_Page::format_points( 3.0 ) );
		$this->assertSame( '2½', Chess_Army_Knife_Tournaments_Page::format_points( 2.5 ) );
	}
}
