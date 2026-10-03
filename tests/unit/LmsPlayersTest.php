<?php
/**
 * Tests for adding the players found in LMS results as members.
 *
 * @package Chess_Army_Knife
 */

class LmsPlayersTest extends Chess_Army_Knife_TestCase {

	public function test_only_listed_players_can_be_added() {
		$candidates = array(
			'120787' => array(
				'code'        => '120787J',
				'name'        => 'Ada Lovelace',
				'rating'      => 1850,
				'games'       => 3,
				'last_played' => '2026-02-01',
			),
		);

		$this->assertSame( 0, Chess_Army_Knife_LMS_Players::add( array( '999999', '5' ), $candidates ), 'A forged key adds nobody.' );
		$this->assertSame( 0, Chess_Army_Knife_LMS_Players::add( array(), $candidates ) );
	}
}
