<?php
/**
 * Tests for Chess_Army_Knife_Member_Audit: the checks that find records to tidy.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class MemberAuditTest extends Chess_Army_Knife_TestCase {

	const TODAY = '2026-09-30';

	protected function setUp(): void {
		parent::setUp();
		$this->set_settings( array( 'use_local_cache' => 0 ) );
	}

	/**
	 * A member row with the given fields over a complete, current adult member.
	 */
	protected function member( array $fields = array() ) {
		return $fields + array(
			'id'                 => 1,
			'name'               => 'Jane Smith',
			'email'              => 'jane@example.test',
			'phone'              => '',
			'date_of_birth'      => '',
			'guardian_name'      => '',
			'guardian_email'     => '',
			'guardian_phone'     => '',
			'ecf_code'           => '120787J',
			'membership_type_id' => 7,
			'type_name'          => 'Adult',
			'status'             => 'active',
			'source'             => 'form',
			'start_date'         => '2026-01-01',
			'expiry_date'        => '2026-12-31',
			'consent_at'         => '2026-01-01 10:00:00',
			'created_at'         => '2026-01-01 10:00:00',
		);
	}

	public function test_members_missing_a_code_are_current_members_only() {
		$members = array(
			$this->member(
				array(
					'id'       => 1,
					'ecf_code' => '',
				)
			),
			$this->member(
				array(
					'id'          => 2,
					'ecf_code'    => '',
					'expiry_date' => '2026-01-31',
				)
			),
			$this->member(
				array(
					'id'       => 3,
					'ecf_code' => '',
					'status'   => 'pending',
				)
			),
			$this->member( array( 'id' => 4 ) ),
		);

		$found = Chess_Army_Knife_Member_Audit::missing_ecf_code( $members, self::TODAY );

		$this->assertSame( array( 1 ), array_column( $found, 'id' ) );
	}

	public function test_ecf_code_format() {
		$this->assertTrue( Chess_Army_Knife_Member_Audit::ecf_code_looks_valid( '120787J' ) );
		$this->assertTrue( Chess_Army_Knife_Member_Audit::ecf_code_looks_valid( '120787' ) );
		$this->assertFalse( Chess_Army_Knife_Member_Audit::ecf_code_looks_valid( '12078' ) );
		$this->assertFalse( Chess_Army_Knife_Member_Audit::ecf_code_looks_valid( 'J120787' ) );
		$this->assertFalse( Chess_Army_Knife_Member_Audit::ecf_code_looks_valid( '120787JJ' ) );
		$this->assertFalse( Chess_Army_Knife_Member_Audit::ecf_code_looks_valid( 'ABCDEFG' ) );
	}

	public function test_malformed_codes_are_found_but_blank_ones_are_not() {
		$members = array(
			$this->member(
				array(
					'id'       => 1,
					'ecf_code' => '1234',
				)
			),
			$this->member(
				array(
					'id'       => 2,
					'ecf_code' => '',
				)
			),
			$this->member( array( 'id' => 3 ) ),
		);

		$found = Chess_Army_Knife_Member_Audit::malformed_ecf_code( $members, self::TODAY );

		$this->assertSame( array( 1 ), array_column( $found, 'id' ) );
	}

	public function test_an_error_from_the_ecf_about_a_missing_player_means_unknown_code() {
		$not_found = new WP_Error( 'ecf_api_error', 'No such player', array( 'status' => 404 ) );
		$down      = new WP_Error( 'ecf_api_error', 'Busy', array( 'status' => 503 ) );
		$limited   = new WP_Error( 'ecf_api_error', 'Slow down', array( 'status' => 429 ) );
		$timeout   = new WP_Error( 'http_request_failed', 'Timed out' );

		$this->assertTrue( Chess_Army_Knife_Member_Audit::is_not_found( $not_found ) );
		$this->assertFalse( Chess_Army_Knife_Member_Audit::is_not_found( $down ) );
		$this->assertFalse( Chess_Army_Knife_Member_Audit::is_not_found( $limited ) );
		$this->assertFalse( Chess_Army_Knife_Member_Audit::is_not_found( $timeout ) );
	}

	public function test_verifying_a_code_the_ecf_knows_is_valid_and_remembered() {
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn(
				$this->response(
					200,
					array(
						'success' => true,
						'data'    => array( 'full_name' => 'Jane Smith' ),
					)
				)
			);

		$this->assertNull( Chess_Army_Knife_Member_Audit::ecf_verdict( '120787J' ), 'Nothing is known before the ECF is asked.' );
		$this->assertSame( 'valid', Chess_Army_Knife_Member_Audit::verify_ecf_code( '120787J' ) );
		$this->assertSame( 'valid', Chess_Army_Knife_Member_Audit::verify_ecf_code( '120787' ), 'The second question is answered from the cache.' );
		$this->assertSame( 'valid', Chess_Army_Knife_Member_Audit::ecf_verdict( '120787J' ) );
	}

	public function test_verifying_a_code_the_ecf_does_not_know_is_unknown() {
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response(
				404,
				array(
					'success' => false,
					'message' => 'Not found',
				)
			)
		);

		$this->assertSame( 'unknown', Chess_Army_Knife_Member_Audit::verify_ecf_code( '999999X' ) );
		$this->assertSame( 'unknown', Chess_Army_Knife_Member_Audit::ecf_verdict( '999999X' ) );
	}

	public function test_an_ecf_outage_says_nothing_about_the_code() {
		Functions\when( 'wp_remote_get' )->justReturn( $this->response( 503, array( 'success' => false ) ) );

		$this->assertTrue( is_wp_error( Chess_Army_Knife_Member_Audit::verify_ecf_code( '120787J' ) ) );
		$this->assertNull( Chess_Army_Knife_Member_Audit::ecf_verdict( '120787J' ), 'A failure to ask is not an answer.' );
	}

	public function test_verifying_a_batch_asks_about_each_code_once_and_no_more_than_the_limit() {
		Functions\expect( 'wp_remote_get' )
			->times( 2 )
			->andReturn(
				$this->response(
					200,
					array(
						'success' => true,
						'data'    => array(),
					)
				)
			);

		$members = array(
			$this->member(
				array(
					'id'       => 1,
					'ecf_code' => '111111A',
				)
			),
			$this->member(
				array(
					'id'       => 2,
					'ecf_code' => '111111',
				)
			), // The same code as the first.
			$this->member(
				array(
					'id'       => 3,
					'ecf_code' => '222222B',
				)
			),
			$this->member(
				array(
					'id'       => 4,
					'ecf_code' => '333333C',
				)
			),
		);

		$result = Chess_Army_Knife_Member_Audit::verify_next_codes( $members, 2 );

		$this->assertSame(
			array(
				'asked'  => 2,
				'failed' => 0,
			),
			$result
		);
	}

	public function test_verifying_stops_when_the_ecf_keeps_failing() {
		Functions\expect( 'wp_remote_get' )
			->times( 3 )
			->andReturn( $this->response( 503, array( 'success' => false ) ) );

		$members = array();
		foreach ( range( 1, 6 ) as $i ) {
			$members[] = $this->member(
				array(
					'id'       => $i,
					'ecf_code' => $i . '00000A',
				)
			);
		}

		$result = Chess_Army_Knife_Member_Audit::verify_next_codes( $members, 10 );

		$this->assertSame( 3, $result['failed'] );
	}

	public function test_a_complete_application_has_no_problems() {
		$this->assertSame( array(), Chess_Army_Knife_Member_Audit::application_problems( $this->member( array( 'status' => 'pending' ) ), self::TODAY ) );
	}

	public function test_an_application_with_nothing_to_contact_is_incomplete() {
		$application = $this->member(
			array(
				'status'             => 'pending',
				'email'              => '',
				'membership_type_id' => 0,
				'consent_at'         => '',
			)
		);

		$this->assertCount( 3, Chess_Army_Knife_Member_Audit::application_problems( $application, self::TODAY ) );
	}

	public function test_a_parents_email_is_enough_contact_for_a_junior() {
		$junior = $this->member(
			array(
				'status'         => 'pending',
				'email'          => '',
				'date_of_birth'  => '2015-05-05',
				'guardian_name'  => 'Pat Smith',
				'guardian_email' => 'pat@example.test',
			)
		);

		$this->assertSame( array(), Chess_Army_Knife_Member_Audit::application_problems( $junior, self::TODAY ) );
	}

	public function test_a_junior_needs_a_date_of_birth_and_parent_details() {
		$junior = $this->member(
			array(
				'status'        => 'pending',
				'guardian_name' => 'Pat Smith',
			)
		);

		$problems = Chess_Army_Knife_Member_Audit::application_problems( $junior, self::TODAY );

		$this->assertCount( 2, $problems );
	}

	public function test_a_member_added_by_hand_needs_no_consent() {
		$member = $this->member(
			array(
				'status'     => 'pending',
				'source'     => 'manual',
				'consent_at' => '',
			)
		);

		$this->assertSame( array(), Chess_Army_Knife_Member_Audit::application_problems( $member, self::TODAY ) );
	}

	public function test_only_pending_applications_are_listed_oldest_first() {
		$members = array(
			$this->member(
				array(
					'id'         => 1,
					'status'     => 'pending',
					'email'      => '',
					'created_at' => '2026-03-01 00:00:00',
				)
			),
			$this->member(
				array(
					'id'     => 2,
					'status' => 'active',
					'email'  => '',
				)
			),
			$this->member(
				array(
					'id'         => 3,
					'status'     => 'pending',
					'email'      => '',
					'created_at' => '2026-02-01 00:00:00',
				)
			),
			$this->member(
				array(
					'id'     => 4,
					'status' => 'pending',
				)
			),
		);

		$found = Chess_Army_Knife_Member_Audit::incomplete_applications( $members, self::TODAY );

		$this->assertSame(
			array( 3, 1 ),
			array_map(
				function ( $item ) {
					return $item['member']['id'];
				},
				$found
			)
		);
	}

	public function test_name_key_ignores_case_punctuation_and_word_order() {
		$this->assertSame( Chess_Army_Knife_Member_Audit::name_key( 'John Smith' ), Chess_Army_Knife_Member_Audit::name_key( 'smith, JOHN' ) );
		$this->assertNotSame( Chess_Army_Knife_Member_Audit::name_key( 'John Smith' ), Chess_Army_Knife_Member_Audit::name_key( 'John Smyth' ) );
	}

	public function test_people_sharing_an_ecf_code_are_duplicates_even_without_the_letter() {
		$members = array(
			$this->member(
				array(
					'id'       => 1,
					'name'     => 'Jane Smith',
					'email'    => 'a@example.test',
					'ecf_code' => '120787J',
				)
			),
			$this->member(
				array(
					'id'       => 2,
					'name'     => 'J Smith',
					'email'    => 'b@example.test',
					'ecf_code' => '120787',
				)
			),
			$this->member(
				array(
					'id'       => 3,
					'name'     => 'Bob Jones',
					'email'    => 'c@example.test',
					'ecf_code' => '555555Z',
				)
			),
		);

		$groups = Chess_Army_Knife_Member_Audit::duplicate_groups( $members );

		$this->assertCount( 1, $groups );
		$this->assertSame( 'ecf_code', $groups[0]['reason'] );
		$this->assertSame( array( 1, 2 ), array_column( $groups[0]['members'], 'id' ) );
	}

	public function test_people_sharing_their_own_email_are_duplicates_but_a_shared_parents_email_is_not() {
		$members = array(
			$this->member(
				array(
					'id'       => 1,
					'name'     => 'Ann Jones',
					'email'    => 'Ann@Example.test',
					'ecf_code' => '',
				)
			),
			$this->member(
				array(
					'id'       => 2,
					'name'     => 'Annie Jones',
					'email'    => 'ann@example.test',
					'ecf_code' => '',
				)
			),
			$this->member(
				array(
					'id'             => 3,
					'name'           => 'Tom Lee',
					'email'          => '',
					'guardian_email' => 'pat@example.test',
					'ecf_code'       => '',
				)
			),
			$this->member(
				array(
					'id'             => 4,
					'name'           => 'Kim Lee',
					'email'          => '',
					'guardian_email' => 'pat@example.test',
					'ecf_code'       => '',
				)
			),
		);

		$groups = Chess_Army_Knife_Member_Audit::duplicate_groups( $members );

		$this->assertCount( 1, $groups );
		$this->assertSame( 'email', $groups[0]['reason'] );
		$this->assertSame( array( 1, 2 ), array_column( $groups[0]['members'], 'id' ) );
	}

	public function test_same_name_is_a_duplicate_unless_birthdays_or_codes_differ() {
		$members = array(
			$this->member(
				array(
					'id'            => 1,
					'name'          => 'Sam Green',
					'email'         => 'a@example.test',
					'ecf_code'      => '',
					'date_of_birth' => '',
				)
			),
			$this->member(
				array(
					'id'            => 2,
					'name'          => 'green sam',
					'email'         => 'b@example.test',
					'ecf_code'      => '',
					'date_of_birth' => '',
				)
			),
			$this->member(
				array(
					'id'            => 3,
					'name'          => 'Pat White',
					'email'         => 'c@example.test',
					'ecf_code'      => '',
					'date_of_birth' => '2010-01-01',
				)
			),
			$this->member(
				array(
					'id'            => 4,
					'name'          => 'Pat White',
					'email'         => 'd@example.test',
					'ecf_code'      => '',
					'date_of_birth' => '2012-02-02',
				)
			),
			$this->member(
				array(
					'id'       => 5,
					'name'     => 'Al Brown',
					'email'    => 'e@example.test',
					'ecf_code' => '111111A',
				)
			),
			$this->member(
				array(
					'id'       => 6,
					'name'     => 'Al Brown',
					'email'    => 'f@example.test',
					'ecf_code' => '222222B',
				)
			),
		);

		$groups = Chess_Army_Knife_Member_Audit::duplicate_groups( $members );

		$this->assertCount( 1, $groups, 'Different birthdays or ECF codes show they are different people.' );
		$this->assertSame( 'name', $groups[0]['reason'] );
		$this->assertSame( array( 1, 2 ), array_column( $groups[0]['members'], 'id' ) );
	}

	public function test_erased_records_are_not_duplicates_of_each_other() {
		$erased  = Chess_Army_Knife_Membership_Store::erased_name();
		$members = array(
			$this->member(
				array(
					'id'       => 1,
					'name'     => $erased,
					'email'    => '',
					'ecf_code' => '',
				)
			),
			$this->member(
				array(
					'id'       => 2,
					'name'     => $erased,
					'email'    => '',
					'ecf_code' => '',
				)
			),
		);

		$this->assertSame( array(), Chess_Army_Knife_Member_Audit::duplicate_groups( $members ) );
	}
}
