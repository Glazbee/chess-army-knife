<?php
/**
 * Tests for the teams the import suggests adding.
 *
 * @package Chess_Army_Knife
 */

class TeamSuggestionsTest extends Chess_Army_Knife_TestCase {

	private function found( $team = 'Wotton Hall A' ) {
		return array(
			array(
				'org'   => '613',
				'event' => 'Division 1',
				'team'  => $team,
			),
		);
	}

	public function test_a_team_seen_in_an_earlier_season_is_not_current() {
		$kept = Chess_Army_Knife_Team_Suggestions::merge( array(), $this->found(), array( '613' => '2017-2018' ), false );

		$entry = $kept['613|division 1|wotton hall a'];
		$this->assertSame( array( '2017-2018' ), $entry['seasons'] );
		$this->assertFalse( $entry['active'] );
		$this->assertFalse( $entry['ignored'] );
	}

	public function test_seeing_a_team_again_adds_its_season_and_a_running_season_makes_it_current() {
		$kept = Chess_Army_Knife_Team_Suggestions::merge( array(), $this->found(), array( '613' => '2017-2018' ), false );
		$kept = Chess_Army_Knife_Team_Suggestions::merge( $kept, $this->found( 'wotton hall a' ), array( '613' => '2018-2019' ), false );
		$kept = Chess_Army_Knife_Team_Suggestions::merge( $kept, $this->found(), array( '613' => '2018-2019' ), false );

		$this->assertCount( 1, $kept, 'The same team in the same division is one suggestion, whatever its case.' );
		$this->assertSame( array( '2017-2018', '2018-2019' ), reset( $kept )['seasons'] );

		$kept = Chess_Army_Knife_Team_Suggestions::merge( $kept, $this->found(), array( '613' => '2026-2027' ), true );
		$this->assertTrue( reset( $kept )['active'] );
	}

	public function test_an_ignored_team_stays_ignored_when_seen_again() {
		$kept = Chess_Army_Knife_Team_Suggestions::merge( array(), $this->found(), array(), false );
		$key  = key( $kept );

		$kept[ $key ]['ignored'] = true;
		$kept                    = Chess_Army_Knife_Team_Suggestions::merge( $kept, $this->found(), array(), false );

		$this->assertTrue( $kept[ $key ]['ignored'] );
	}
}
