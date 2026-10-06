<?php
/**
 * Unit tests for the dashboard figures.
 *
 * @package Chess_Army_Knife
 */

class MemberStatsTest extends Chess_Army_Knife_TestCase {

	const TODAY = '2026-10-15';

	private function person( array $extra = array() ) {
		return $extra + array(
			'status'                => 'active',
			'type_name'             => 'Adult',
			'created_at'            => '',
			'paid_on'               => '',
			'date_of_birth'         => '',
			'newsletter_consent_at' => '',
			'whatsapp_consent_at'   => '',
			'ecf_code'              => '',
			'ecf_rating'            => null,
		);
	}

	public function test_people_are_counted_by_their_state() {
		$stats = Chess_Army_Knife_Member_Stats::compute(
			array(
				$this->person(),
				$this->person( array( 'paid_on' => '2026-09-05' ) ),
				$this->person( array( 'paid_on' => '2026-10-01' ) ),
				$this->person( array( 'paid_on' => '2026-10-15' ) ),
				$this->person( array( 'status' => 'pending' ) ),
				$this->person( array( 'status' => 'rejected' ) ),
				$this->person( array( 'status' => 'cancelled' ) ),
				$this->person( array( 'status' => 'nonmember' ) ),
			),
			self::TODAY
		);

		$this->assertSame( 4, $stats['current'] );
		$this->assertSame( 1, $stats['unpaid'] );
		$this->assertSame( 1, $stats['pending'] );
		$this->assertSame( 1, $stats['guests'] );
	}

	public function test_joiners_are_counted_by_when_their_record_was_made() {
		$stats = Chess_Army_Knife_Member_Stats::compute(
			array(
				$this->person( array( 'created_at' => '2026-10-02 09:30:00' ) ),
				$this->person( array( 'created_at' => '2026-03-01 18:00:00' ) ),
				$this->person( array( 'created_at' => '2025-10-02 18:00:00' ) ),
				$this->person(),
			),
			self::TODAY
		);

		$this->assertSame( 1, $stats['joined_month'] );
		$this->assertSame( 2, $stats['joined_year'] );
	}

	public function test_current_members_are_split_by_type_age_choices_and_ecf() {
		$stats = Chess_Army_Knife_Member_Stats::compute(
			array(
				$this->person(
					array(
						'type_name'             => 'Junior',
						'date_of_birth'         => '2010-01-01',
						'newsletter_consent_at' => '2026-01-01 10:00:00',
						'ecf_code'              => '123456A',
						'ecf_rating'            => 950,
					)
				),
				$this->person(
					array(
						'whatsapp_consent_at' => '2026-01-01 10:00:00',
						'ecf_code'            => '654321B',
						'ecf_rating'          => 1830,
					)
				),
				$this->person( array( 'date_of_birth' => '2008-10-16' ) ), // Turns 18 tomorrow.
				$this->person(
					array(
						'status'    => 'pending',
						'type_name' => 'Junior',
					)
				), // Not counted.
			),
			self::TODAY
		);

		$this->assertSame( array( array( 'Adult', 2 ), array( 'Junior', 1 ) ), $stats['by_type'] );
		$this->assertSame( 2, $stats['juniors'] );
		$this->assertSame( 1, $stats['adults'] );
		$this->assertSame( 1, $stats['newsletter'] );
		$this->assertSame( 1, $stats['whatsapp'] );
		$this->assertSame( 2, $stats['with_ecf_code'] );
		$this->assertSame( 1, $stats['without_ecf_code'] );
		$this->assertSame( 1, $stats['unrated'] );
		$this->assertSame( 1, $stats['by_rating'][1][1] ); // 950 is in 800–999.
		$this->assertSame( 1, $stats['by_rating'][6][1] ); // 1830 is in 1800–1999.
	}

	public function test_age_is_worked_out_from_the_date_of_birth() {
		$this->assertTrue( Chess_Army_Knife_Member_Stats::is_junior( '2008-10-16', self::TODAY ) );
		$this->assertFalse( Chess_Army_Knife_Member_Stats::is_junior( '2008-10-15', self::TODAY ) ); // Turned 18 today.
		$this->assertFalse( Chess_Army_Knife_Member_Stats::is_junior( '', self::TODAY ) );
		$this->assertFalse( Chess_Army_Knife_Member_Stats::is_junior( 'nonsense', self::TODAY ) );
	}

	public function test_ratings_fall_into_bands() {
		$this->assertSame( 0, Chess_Army_Knife_Member_Stats::rating_band( 0 ) );
		$this->assertSame( 0, Chess_Army_Knife_Member_Stats::rating_band( 799 ) );
		$this->assertSame( 1, Chess_Army_Knife_Member_Stats::rating_band( 800 ) );
		$this->assertSame( 6, Chess_Army_Knife_Member_Stats::rating_band( 1999 ) );
		$this->assertSame( 7, Chess_Army_Knife_Member_Stats::rating_band( 2650 ) );
		$this->assertSame( '800–999', Chess_Army_Knife_Member_Stats::rating_band_label( 1 ) );
	}
}
