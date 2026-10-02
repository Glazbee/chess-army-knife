<?php
/**
 * Tests for the pure parts of Chess_Army_Knife_Clubs.
 *
 * @package Chess_Army_Knife
 */

class ClubsTest extends Chess_Army_Knife_TestCase {

	/**
	 * @dataProvider stems
	 */
	public function test_stem_drops_a_team_number_or_letter( $name, $expected ) {
		$this->assertSame( $expected, Chess_Army_Knife_Clubs::stem( $name ) );
	}

	public function stems() {
		return array(
			'hyphen number'  => array( 'Central Birmingham-1', 'Central Birmingham' ),
			'space number'   => array( 'Stroud 2', 'Stroud' ),
			'bracket number' => array( 'Solihull (3)', 'Solihull' ),
			'letter'         => array( 'Kings Heath A', 'Kings Heath' ),
			'nickname kept'  => array( 'Stroud Otters', 'Stroud Otters' ),
			'only a number'  => array( '1', '1' ),
			'padded'         => array( '  Selly Oak 4 ', 'Selly Oak' ),
		);
	}

	public function test_numbered_teams_are_one_club() {
		$groups = Chess_Army_Knife_Clubs::suggest_groups( array( 'Solihull-2', 'Solihull-1', 'Solihull-10' ) );

		$this->assertCount( 1, $groups );
		$this->assertSame( 'Solihull', $groups[0]['name'] );
		$this->assertSame( array( 'Solihull-1', 'Solihull-2', 'Solihull-10' ), $groups[0]['teams'], 'In natural order.' );
	}

	public function test_teams_sharing_a_first_word_are_suggested_together() {
		$groups = Chess_Army_Knife_Clubs::suggest_groups( array( 'Stroud Otters', 'Stroud Badgers', 'Kings Heath-1' ) );

		$this->assertSame( array( 'Kings Heath', 'Stroud' ), array_column( $groups, 'name' ) );
		$this->assertSame( array( 'Stroud Badgers', 'Stroud Otters' ), $groups[1]['teams'] );
	}

	public function test_a_common_first_word_does_not_join_unrelated_clubs() {
		$groups = Chess_Army_Knife_Clubs::suggest_groups( array( 'City Wanderers', 'City Rovers', 'North Stars' ) );

		$this->assertCount( 3, $groups, 'City and North say nothing about the club.' );
	}

	public function test_blank_and_repeated_names_are_ignored() {
		$groups = Chess_Army_Knife_Clubs::suggest_groups( array( '', ' ', 'Stroud Otters', 'Stroud Otters' ) );

		$this->assertCount( 1, $groups );
		$this->assertSame( array( 'Stroud Otters' ), $groups[0]['teams'] );
	}
}
