<?php
/**
 * Tests for the pure parts of Chess_Army_Knife_Clubs.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

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

	private function stub_cleaning() {
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	public function test_a_pasted_list_is_read_into_clubs_with_their_teams_and_venue() {
		$this->stub_cleaning();
		$text = "Club,Teams,Venue,Map,what3words\n"
			. "Stroud,Stroud Badgers;Stroud Hedgehogs,The Library,https://maps.app.goo.gl/x,///Index.Home.Raft\n"
			. "\n"
			. "\"Wotton, Hall\",Wotton Hall Lions | Wotton Hall Leopards,\"Hall, High Street\",,\n"
			. "Only A Name\n"
			. ",Nameless Team\n";

		$parsed = Chess_Army_Knife_Clubs::parse_csv( $text );

		$this->assertSame( array( 'Stroud', 'Wotton, Hall', 'Only A Name' ), array_column( $parsed['rows'], 'name' ) );
		$this->assertSame( array( 'Stroud Badgers', 'Stroud Hedgehogs' ), $parsed['rows'][0]['teams'] );
		$this->assertSame( 'index.home.raft', $parsed['rows'][0]['what3words'], 'Cleaned the way the event form cleans it.' );
		$this->assertSame( array( 'Wotton Hall Lions', 'Wotton Hall Leopards' ), $parsed['rows'][1]['teams'] );
		$this->assertSame( 'Hall, High Street', $parsed['rows'][1]['venue'], 'A comma inside quotes is part of the cell.' );
		$this->assertSame( array(), $parsed['rows'][2]['teams'] );
		$this->assertSame( 1, $parsed['skipped'], 'The line with no club name.' );
		$this->assertFalse( $parsed['too_many'] );
	}

	public function test_cells_pasted_straight_from_a_spreadsheet_are_tab_separated() {
		$this->stub_cleaning();

		$parsed = Chess_Army_Knife_Clubs::parse_csv( "Stroud\tStroud Badgers\tThe Library\n" );

		$this->assertSame( 'The Library', $parsed['rows'][0]['venue'] );
		$this->assertSame( array( 'Stroud Badgers' ), $parsed['rows'][0]['teams'] );
	}

	public function test_a_very_long_list_is_cut_short_and_a_non_web_map_link_is_dropped() {
		$this->stub_cleaning();
		Functions\when( 'esc_url_raw' )->alias(
			function ( $url ) {
				return preg_match( '#^https?://#', $url ) ? $url : '';
			}
		);

		$text   = "A,,,javascript:alert(1)\n" . str_repeat( "Club\n", Chess_Army_Knife_Clubs::MAX_CSV_ROWS + 5 );
		$parsed = Chess_Army_Knife_Clubs::parse_csv( $text );

		$this->assertCount( Chess_Army_Knife_Clubs::MAX_CSV_ROWS, $parsed['rows'] );
		$this->assertTrue( $parsed['too_many'] );
		$this->assertSame( '', $parsed['rows'][0]['map_url'] );
	}
}
