<?php
/**
 * Tests for choosing a member for the Featured Player and Rating Chart blocks.
 *
 * @package Chess_Army_Knife
 */

class RotatingMemberTest extends Chess_Army_Knife_TestCase {

	private function members( $count ) {
		$members = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$members[] = array( 'code' => 'C' . $i );
		}
		return $members;
	}

	/**
	 * The first day of a round of turns, so that a run of days does not straddle two shuffles.
	 */
	private function round_start( $count ) {
		return (int) ceil( strtotime( '2026-10-05 00:00:00 UTC' ) / 86400 / $count ) * $count * 86400;
	}

	public function test_an_unknown_rotation_means_none() {
		$this->assertSame( 'none', Chess_Army_Knife_Rotating_Member::clean_rotation( 'fortnight' ) );
		$this->assertSame( 'none', Chess_Army_Knife_Rotating_Member::clean_rotation( null ) );
		$this->assertSame( 'week', Chess_Army_Knife_Rotating_Member::clean_rotation( 'week' ) );
	}

	public function test_the_slot_changes_once_each_period() {
		$monday = strtotime( '2026-10-05 00:00:00 UTC' );

		$this->assertSame( Chess_Army_Knife_Rotating_Member::slot( 'hour', $monday ) + 1, Chess_Army_Knife_Rotating_Member::slot( 'hour', $monday + 3600 ) );
		$this->assertSame( Chess_Army_Knife_Rotating_Member::slot( 'day', $monday ), Chess_Army_Knife_Rotating_Member::slot( 'day', $monday + 86399 ) );
		$this->assertSame( Chess_Army_Knife_Rotating_Member::slot( 'day', $monday ) + 1, Chess_Army_Knife_Rotating_Member::slot( 'day', $monday + 86400 ) );
		$this->assertSame( Chess_Army_Knife_Rotating_Member::slot( 'month', strtotime( '2026-10-01 UTC' ) ), Chess_Army_Knife_Rotating_Member::slot( 'month', strtotime( '2026-10-31 23:59:59 UTC' ) ) );
		$this->assertSame( Chess_Army_Knife_Rotating_Member::slot( 'month', strtotime( '2026-10-31 UTC' ) ) + 1, Chess_Army_Knife_Rotating_Member::slot( 'month', strtotime( '2026-11-01 UTC' ) ) );
	}

	public function test_a_week_begins_on_a_monday() {
		$sunday = strtotime( '2026-10-04 12:00:00 UTC' );
		$monday = strtotime( '2026-10-05 00:00:00 UTC' );

		$this->assertSame( Chess_Army_Knife_Rotating_Member::slot( 'week', $sunday ) + 1, Chess_Army_Knife_Rotating_Member::slot( 'week', $monday ) );
		$this->assertSame( Chess_Army_Knife_Rotating_Member::slot( 'week', $monday ), Chess_Army_Knife_Rotating_Member::slot( 'week', $monday + 6 * 86400 ) );
	}

	public function test_the_same_member_is_picked_within_a_period_and_everyone_gets_a_turn() {
		$members = $this->members( 4 );
		$start   = $this->round_start( 4 ) + 9 * 3600;

		$this->assertSame(
			Chess_Army_Knife_Rotating_Member::pick( $members, 'day', 'x', $start ),
			Chess_Army_Knife_Rotating_Member::pick( $members, 'day', 'x', $start + 3600 )
		);

		$seen = array();
		for ( $day = 0; $day < 4; $day++ ) {
			$seen[] = Chess_Army_Knife_Rotating_Member::pick( $members, 'day', 'x', $start + $day * 86400 )['code'];
		}
		$this->assertCount( 4, array_unique( $seen ), 'Nobody comes round twice before everyone has had a turn.' );
	}

	public function test_the_order_is_shuffled_not_alphabetical_and_changes_with_the_seed() {
		$members = $this->members( 12 );
		$start   = $this->round_start( 12 );
		$order   = function ( $seed ) use ( $members, $start ) {
			$codes = array();
			for ( $day = 0; $day < 12; $day++ ) {
				$codes[] = Chess_Army_Knife_Rotating_Member::pick( $members, 'day', $seed, $start + $day * 86400 )['code'];
			}
			return $codes;
		};

		$this->assertNotSame( array_column( $members, 'code' ), $order( 'a' ), 'Not in list order.' );
		$this->assertNotSame( $order( 'a' ), $order( 'b' ) );
		$this->assertSame( $order( 'a' ), $order( 'a' ), 'The same seed gives the same turns.' );
		$this->assertCount( 12, array_unique( $order( 'a' ) ) );
	}

	public function test_nobody_to_pick_gives_null() {
		$this->assertNull( Chess_Army_Knife_Rotating_Member::pick( array(), 'day', 'x', time() ) );
	}

	public function test_growth_uses_the_first_and_last_rating_in_the_period() {
		$games = array(
			array(
				'game_date'     => '2026-10-01',
				'player_rating' => '1650',
			),
			array(
				'game_date'     => '2026-09-01',
				'player_rating' => '1600',
			),
			array(
				'game_date'     => '2026-06-01',
				'player_rating' => '1400',
			),
			array(
				'game_date'     => '2026-09-20',
				'player_rating' => null,
			),
		);

		$growth = Chess_Army_Knife_Rotating_Member::growth_from_games( $games, '2026-08-01', 2 );

		$this->assertSame( 1600.0, $growth['from'] );
		$this->assertSame( 1650.0, $growth['to'] );
		$this->assertSame( 50.0, $growth['gain'] );
		$this->assertSame( 2, $growth['games'] );
	}

	public function test_too_few_games_is_no_trend() {
		$games = array(
			array(
				'game_date'     => '2026-10-01',
				'player_rating' => '1650',
			),
		);

		$this->assertNull( Chess_Army_Knife_Rotating_Member::growth_from_games( $games, '2026-08-01', 2 ) );
	}

	public function test_a_fall_is_reported_as_a_negative_gain() {
		$games = array(
			array(
				'game_date'     => '2026-09-01',
				'player_rating' => '1650',
			),
			array(
				'game_date'     => '2026-10-01',
				'player_rating' => '1600',
			),
		);

		$this->assertSame( -50.0, Chess_Army_Knife_Rotating_Member::growth_from_games( $games, '2026-08-01', 2 )['gain'] );
	}
}
