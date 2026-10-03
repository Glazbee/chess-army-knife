<?php
/**
 * Tests for the pure parts of Chess_Army_Knife_Officers: cleaning and ordering.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class OfficersTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
			}
		);
		Functions\when( 'wp_list_pluck' )->alias(
			function ( $list, $field, $index_key = null ) {
				return array_column( $list, $field, $index_key );
			}
		);
		Functions\when( 'wp_generate_password' )->alias(
			function () {
				static $n = 0;
				++$n;
				return 'GEN' . $n;
			}
		);
	}

	private function column( array $list, $field ) {
		return array_values( array_column( $list, $field ) );
	}

	private function items() {
		return array(
			array(
				'key'   => 'chair',
				'label' => 'Chairman',
				'kind'  => 'position',
			),
			array(
				'key'   => 'sec',
				'label' => 'Secretary',
				'kind'  => 'position',
			),
			array(
				'key'   => 'team-7',
				'label' => 'Captain, Club A',
				'kind'  => 'captain',
			),
		);
	}

	public function test_positions_need_a_name() {
		$positions = Chess_Army_Knife_Officers::clean_positions(
			array(
				array(
					'id'   => 'chair',
					'name' => ' Chairman ',
				),
				array(
					'id'   => 'blank',
					'name' => '   ',
				),
				'garbage',
			)
		);

		$this->assertSame(
			array(
				array(
					'id'   => 'chair',
					'name' => 'Chairman',
				),
			),
			$positions
		);
	}

	public function test_a_new_position_is_given_an_id() {
		$positions = Chess_Army_Knife_Officers::clean_positions( array( array( 'name' => 'Treasurer' ) ) );

		$this->assertMatchesRegularExpression( '/^pgen\d+$/', $positions[0]['id'] );
	}

	public function test_a_repeated_id_or_the_captain_id_is_replaced() {
		$positions = Chess_Army_Knife_Officers::clean_positions(
			array(
				array(
					'id'   => 'a',
					'name' => 'One',
				),
				array(
					'id'   => 'a',
					'name' => 'Two',
				),
				array(
					'id'   => 'captain',
					'name' => 'Three',
				),
			)
		);

		$this->assertCount( 3, array_unique( $this->column( $positions, 'id' ) ) );
		$this->assertSame( 'a', $positions[0]['id'] );
		$this->assertNotSame( 'captain', $positions[2]['id'] );
	}

	public function test_the_list_of_positions_is_capped() {
		$many = array();
		for ( $i = 0; $i < Chess_Army_Knife_Officers::MAX_POSITIONS + 5; $i++ ) {
			$many[] = array( 'name' => 'Position ' . $i );
		}

		$this->assertCount( Chess_Army_Knife_Officers::MAX_POSITIONS, Chess_Army_Knife_Officers::clean_positions( $many ) );
	}

	public function test_a_corrupt_option_is_no_positions() {
		$this->assertSame( array(), Chess_Army_Knife_Officers::clean_positions( 'garbage' ) );
	}

	public function test_move_swaps_a_position_with_its_neighbour() {
		$positions = array(
			array(
				'id'   => 'a',
				'name' => 'A',
			),
			array(
				'id'   => 'b',
				'name' => 'B',
			),
			array(
				'id'   => 'c',
				'name' => 'C',
			),
		);

		$this->assertSame( array( 'a', 'c', 'b' ), $this->column( Chess_Army_Knife_Officers::move( $positions, 'b', 'down' ), 'id' ) );
		$this->assertSame( array( 'b', 'a', 'c' ), $this->column( Chess_Army_Knife_Officers::move( $positions, 'b', 'up' ), 'id' ) );
	}

	public function test_move_stops_at_the_ends_and_ignores_unknown_ids() {
		$positions = array(
			array(
				'id'   => 'a',
				'name' => 'A',
			),
			array(
				'id'   => 'b',
				'name' => 'B',
			),
		);

		$this->assertSame( $positions, Chess_Army_Knife_Officers::move( $positions, 'a', 'up' ) );
		$this->assertSame( $positions, Chess_Army_Knife_Officers::move( $positions, 'b', 'down' ) );
		$this->assertSame( $positions, Chess_Army_Knife_Officers::move( $positions, 'nope', 'down' ) );
	}

	public function test_items_follow_the_blocks_order() {
		$ordered = Chess_Army_Knife_Officers::order_items( $this->items(), array( 'team-7', 'chair' ) );

		$this->assertSame( array( 'team-7', 'chair', 'sec' ), $this->column( $ordered, 'key' ), 'Keys not mentioned follow, in the default order.' );
	}

	public function test_items_ignore_unknown_and_repeated_keys_in_the_order() {
		$ordered = Chess_Army_Knife_Officers::order_items( $this->items(), array( 'gone', 'sec', 'sec', 42 ) );

		$this->assertSame( array( 'sec', 'chair', 'team-7' ), $this->column( $ordered, 'key' ) );
	}

	public function test_no_order_keeps_the_default_order() {
		$this->assertSame( $this->items(), Chess_Army_Knife_Officers::order_items( $this->items(), array() ) );
		$this->assertSame( $this->items(), Chess_Army_Knife_Officers::order_items( $this->items(), 'garbage' ) );
	}

	public function test_a_captaincy_is_labelled_with_the_team() {
		$captain  = array(
			'position_key'  => 'captain',
			'position_name' => 'Club A',
		);
		$chairman = array(
			'position_key'  => 'chair',
			'position_name' => 'Chairman',
		);

		$this->assertSame( 'Captain, Club A', Chess_Army_Knife_Officers::label_of_term( $captain ) );
		$this->assertSame( 'Chairman', Chess_Army_Knife_Officers::label_of_term( $chairman ) );
	}
}
