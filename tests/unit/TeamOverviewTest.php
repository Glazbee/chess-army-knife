<?php
/**
 * Tests for how the Team Overview counts the replies to an availability request.
 *
 * @package Chess_Army_Knife
 */

class TeamOverviewTest extends Chess_Army_Knife_TestCase {

	public function test_replies_are_counted_and_the_silent_are_listed() {
		$squad        = array( 1, 2, 3, 4, 5 );
		$availability = array(
			1  => array( 'response' => 'yes' ),
			2  => array( 'response' => 'no' ),
			3  => array( 'response' => '' ),      // Asked, no reply.
			4  => array( 'response' => 'maybe' ),
			// 5 was never asked.
			99 => array( 'response' => 'yes' ),  // No longer in the squad: not counted.
		);

		$counts = Chess_Army_Knife_Team_Overview::reply_counts( $squad, $availability );

		$this->assertSame( 1, $counts['yes'] );
		$this->assertSame( 1, $counts['maybe'] );
		$this->assertSame( 1, $counts['no'] );
		$this->assertSame( array( 3, 5 ), $counts['waiting'] );
	}

	public function test_an_empty_squad_has_no_replies() {
		$this->assertSame(
			array(
				'yes'     => 0,
				'maybe'   => 0,
				'no'      => 0,
				'waiting' => array(),
			),
			Chess_Army_Knife_Team_Overview::reply_counts( array(), array( 1 => array( 'response' => 'yes' ) ) )
		);
	}

	public function test_a_made_up_reply_is_not_a_reply() {
		$counts = Chess_Army_Knife_Team_Overview::reply_counts( array( 1 ), array( 1 => array( 'response' => 'waiting' ) ) );

		$this->assertSame( array( 1 ), $counts['waiting'] );
		$this->assertSame( 0, $counts['yes'] + $counts['maybe'] + $counts['no'] );
	}
}
