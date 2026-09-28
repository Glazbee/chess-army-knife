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

	public function test_round_robin_is_the_supported_format() {
		$this->assertArrayHasKey( 'round-robin', Chess_Army_Knife_Tournaments::formats() );
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
