<?php
/**
 * Unit tests for the renewal reminder schedule rules.
 *
 * @package Chess_Army_Knife
 */

class RenewalRemindersTest extends Chess_Army_Knife_TestCase {

	public function test_the_schedule_is_read_sorted_and_cleaned() {
		$this->assertSame( array( 30, 7, 0, -7 ), Chess_Army_Knife_Renewal_Reminders::parse_schedule( '0, 30,-7,7,7' ) );
		$this->assertSame( array( 30, 7, 0, -7 ), Chess_Army_Knife_Renewal_Reminders::parse_schedule( 'soon' ) );
		$this->assertSame( array( 365, -90 ), Chess_Army_Knife_Renewal_Reminders::parse_schedule( '9999,-500' ) );
		$this->assertCount( 6, Chess_Army_Knife_Renewal_Reminders::parse_schedule( '1,2,3,4,5,6,7,8' ) );
	}

	public function test_a_member_is_in_the_latest_stage_reached() {
		$schedule = array( 30, 7, 0, -7 );

		$this->assertNull( Chess_Army_Knife_Renewal_Reminders::stage_for( 31, $schedule ) );
		$this->assertSame( 30, Chess_Army_Knife_Renewal_Reminders::stage_for( 30, $schedule ) );
		$this->assertSame( 30, Chess_Army_Knife_Renewal_Reminders::stage_for( 8, $schedule ) );
		$this->assertSame( 7, Chess_Army_Knife_Renewal_Reminders::stage_for( 7, $schedule ) );
		$this->assertSame( 0, Chess_Army_Knife_Renewal_Reminders::stage_for( 0, $schedule ) );
		$this->assertSame( 0, Chess_Army_Knife_Renewal_Reminders::stage_for( -6, $schedule ) );
		$this->assertSame( -7, Chess_Army_Knife_Renewal_Reminders::stage_for( -7, $schedule ) );
	}

	public function test_a_stage_missed_by_more_than_the_grace_period_is_skipped() {
		$this->assertSame( -7, Chess_Army_Knife_Renewal_Reminders::stage_for( -14, array( 30, 7, 0, -7 ) ) );
		$this->assertNull( Chess_Army_Knife_Renewal_Reminders::stage_for( -15, array( 30, 7, 0, -7 ) ) );
	}

	public function test_a_stage_is_only_sent_once_per_expiry_date() {
		$this->assertFalse( Chess_Army_Knife_Renewal_Reminders::already_sent( '', '2026-10-31', 30 ) );
		$this->assertTrue( Chess_Army_Knife_Renewal_Reminders::already_sent( '2026-10-31|30', '2026-10-31', 30 ) );
		$this->assertTrue( Chess_Army_Knife_Renewal_Reminders::already_sent( '2026-10-31|7', '2026-10-31', 30 ) ); // A later stage went already.
		$this->assertFalse( Chess_Army_Knife_Renewal_Reminders::already_sent( '2026-10-31|30', '2026-10-31', 7 ) );
		$this->assertFalse( Chess_Army_Knife_Renewal_Reminders::already_sent( '2025-10-31|0', '2026-10-31', 30 ) ); // Renewed since.
	}
}
