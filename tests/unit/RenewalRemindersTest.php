<?php
/**
 * Unit tests for the payment reminder schedule rules.
 *
 * @package Chess_Army_Knife
 */

class RenewalRemindersTest extends Chess_Army_Knife_TestCase {

	public function test_the_schedule_is_read_sorted_and_cleaned() {
		$this->assertSame( array( 0, 7, 30 ), Chess_Army_Knife_Renewal_Reminders::parse_schedule( '30,0,7,7' ) );
		$this->assertSame( array( 0, 14, 28 ), Chess_Army_Knife_Renewal_Reminders::parse_schedule( 'soon' ) );
		$this->assertSame( array( 365 ), Chess_Army_Knife_Renewal_Reminders::parse_schedule( '9999,-5' ) );
		$this->assertCount( 6, Chess_Army_Knife_Renewal_Reminders::parse_schedule( '1,2,3,4,5,6,7,8' ) );
	}

	public function test_a_member_is_in_the_latest_stage_reached() {
		$schedule = array( 0, 14, 28 );

		$this->assertSame( 0, Chess_Army_Knife_Renewal_Reminders::stage_for( 0, $schedule ) );
		$this->assertSame( 0, Chess_Army_Knife_Renewal_Reminders::stage_for( 13, $schedule ) );
		$this->assertSame( 14, Chess_Army_Knife_Renewal_Reminders::stage_for( 14, $schedule ) );
		$this->assertSame( 14, Chess_Army_Knife_Renewal_Reminders::stage_for( 27, $schedule ) );
		$this->assertSame( 28, Chess_Army_Knife_Renewal_Reminders::stage_for( 28, $schedule ) );
	}

	public function test_nothing_is_due_before_the_first_stage() {
		$this->assertNull( Chess_Army_Knife_Renewal_Reminders::stage_for( 6, array( 7, 21 ) ) );
		$this->assertNull( Chess_Army_Knife_Renewal_Reminders::stage_for( -1, array( 0, 14 ) ), 'Before the season starts.' );
	}

	public function test_the_last_stage_missed_by_more_than_the_grace_period_is_skipped() {
		$this->assertSame( 28, Chess_Army_Knife_Renewal_Reminders::stage_for( 35, array( 0, 14, 28 ) ) );
		$this->assertNull( Chess_Army_Knife_Renewal_Reminders::stage_for( 36, array( 0, 14, 28 ) ) );
		$this->assertSame( 0, Chess_Army_Knife_Renewal_Reminders::stage_for( 13, array( 0, 14, 28 ) ), 'An earlier stage is not missed, only the last.' );
	}

	public function test_a_stage_is_only_sent_once_per_season() {
		$this->assertFalse( Chess_Army_Knife_Renewal_Reminders::already_sent( '', 3, 0 ) );
		$this->assertTrue( Chess_Army_Knife_Renewal_Reminders::already_sent( '3|0', 3, 0 ) );
		$this->assertTrue( Chess_Army_Knife_Renewal_Reminders::already_sent( '3|14', 3, 0 ) ); // A later stage went already.
		$this->assertFalse( Chess_Army_Knife_Renewal_Reminders::already_sent( '3|0', 3, 14 ) );
		$this->assertFalse( Chess_Army_Knife_Renewal_Reminders::already_sent( '2|28', 3, 0 ) ); // A new season.
	}
}
