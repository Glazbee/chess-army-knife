<?php
/**
 * Tests for the input handling and status logic of Chess_Army_Knife_Membership_Store.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class MembershipStoreTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
		Functions\when( 'sanitize_email' )->alias( 'trim' );
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
			}
		);
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-09-29' );
		Functions\when( 'get_the_title' )->alias(
			function ( $post ) {
				return $post->post_title;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );

		// Membership types: 7 is on offer, 8 is a draft.
		$types = array(
			7 => (object) array(
				'ID'          => 7,
				'post_type'   => Chess_Army_Knife_Memberships::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Junior',
			),
			8 => (object) array(
				'ID'          => 8,
				'post_type'   => Chess_Army_Knife_Memberships::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Retired',
			),
		);
		Functions\when( 'get_post' )->alias(
			function ( $id ) use ( $types ) {
				return isset( $types[ $id ] ) ? $types[ $id ] : null;
			}
		);
	}

	private function form_input( array $overrides = array() ) {
		return $overrides + array(
			'name'               => 'Ada Lovelace',
			'email'              => 'ada@example.test',
			'membership_type_id' => '7',
		);
	}

	public function test_an_adult_application_is_cleaned_and_keeps_no_junior_details() {
		$member = Chess_Army_Knife_Membership_Store::sanitize_member(
			$this->form_input(
				array(
					'phone'          => ' 07700 900123 ',
					'ecf_code'       => ' 12 34-5j ',
					'date_of_birth'  => '1980-05-01',
					'guardian_name'  => 'Charles',
					'guardian_email' => 'charles@example.test',
					'guardian_phone' => '0123',
				)
			),
			false
		);

		$this->assertSame(
			array(
				'name'                  => 'Ada Lovelace',
				'email'                 => 'ada@example.test',
				'phone'                 => '07700 900123',
				'date_of_birth'         => null,
				'guardian_name'         => '',
				'guardian_email'        => '',
				'guardian_phone'        => '',
				'ecf_code'              => '12345J',
				'membership_type_id'    => 7,
				'type_name'             => 'Junior',
				'newsletter_consent_at' => null,
				'whatsapp_consent_at'   => null,
			),
			$member
		);
	}

	private function junior_input( array $overrides = array() ) {
		return $overrides + array(
			'name'               => 'Junior Player',
			'membership_type_id' => '7',
			'is_junior'          => '1',
			'date_of_birth'      => '2015-05-01',
			'guardian_name'      => 'Charles Player',
			'guardian_email'     => 'charles@example.test',
			'guardian_phone'     => '07700 900123',
			'email'              => 'junior@example.test',
			'phone'              => '07700 900456',
		);
	}

	public function test_a_junior_is_contacted_through_their_parent_and_their_own_details_are_dropped() {
		$member = Chess_Army_Knife_Membership_Store::sanitize_member( $this->junior_input(), false );

		$this->assertSame( '', $member['email'] );
		$this->assertSame( '', $member['phone'] );
		$this->assertSame( '2015-05-01', $member['date_of_birth'] );
		$this->assertSame( 'Charles Player', $member['guardian_name'] );
		$this->assertSame( 'charles@example.test', $member['guardian_email'] );
		$this->assertSame( '07700 900123', $member['guardian_phone'] );
		$this->assertSame( 'charles@example.test', Chess_Army_Knife_Membership_Store::contact_email( $member ) );
	}

	public function test_a_junior_keeps_their_own_details_only_when_the_parent_says_so() {
		$member = Chess_Army_Knife_Membership_Store::sanitize_member( $this->junior_input( array( 'junior_contact' => '1' ) ), false );

		$this->assertSame( 'junior@example.test', $member['email'] );
		$this->assertSame( '07700 900456', $member['phone'] );
		$this->assertSame( 'junior@example.test', Chess_Army_Knife_Membership_Store::contact_email( $member ) );
	}

	public function test_someone_whose_date_of_birth_says_under_18_is_treated_as_a_junior() {
		$input = $this->junior_input( array( 'is_junior' => '' ) );

		$this->assertSame( '', Chess_Army_Knife_Membership_Store::sanitize_member( $input, false )['email'] );

		unset( $input['guardian_name'] );
		$result = Chess_Army_Knife_Membership_Store::sanitize_member( $input, false );
		$this->assertSame( 'member_guardian', $result->get_error_code(), 'Leaving the box unticked does not avoid the parent details.' );
	}

	public function test_someone_who_turns_18_today_is_an_adult() {
		Functions\when( 'current_time' )->justReturn( '2033-05-01' );
		$member = Chess_Army_Knife_Membership_Store::sanitize_member( $this->form_input( array( 'date_of_birth' => '2015-05-01' ) ), false );

		$this->assertNull( $member['date_of_birth'] );
		$this->assertSame( 'ada@example.test', $member['email'] );
	}

	/**
	 * @dataProvider invalid_junior_input
	 */
	public function test_a_junior_application_needs_the_parent_details( array $overrides, $code ) {
		$result = Chess_Army_Knife_Membership_Store::sanitize_member( $this->junior_input( $overrides ), false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function invalid_junior_input() {
		return array(
			'no date of birth'   => array( array( 'date_of_birth' => '' ), 'member_dob' ),
			'no guardian name'   => array( array( 'guardian_name' => '' ), 'member_guardian' ),
			'no guardian email'  => array( array( 'guardian_email' => '' ), 'member_guardian' ),
			'bad guardian email' => array( array( 'guardian_email' => 'nope' ), 'member_email' ),
		);
	}

	public function test_the_optional_extras_are_recorded_separately_and_only_when_ticked() {
		$none = Chess_Army_Knife_Membership_Store::sanitize_member( $this->form_input( array( 'phone' => '0123' ) ), false );
		$both = Chess_Army_Knife_Membership_Store::sanitize_member(
			$this->form_input(
				array(
					'phone'      => '0123',
					'newsletter' => '1',
					'whatsapp'   => '1',
				)
			),
			false
		);
		$one  = Chess_Army_Knife_Membership_Store::sanitize_member( $this->form_input( array( 'newsletter' => '1' ) ), false );

		$this->assertNull( $none['newsletter_consent_at'] );
		$this->assertNull( $none['whatsapp_consent_at'] );
		$this->assertSame( '2026-09-29', $both['newsletter_consent_at'] );
		$this->assertSame( '2026-09-29', $both['whatsapp_consent_at'] );
		$this->assertSame( '2026-09-29', $one['newsletter_consent_at'] );
		$this->assertNull( $one['whatsapp_consent_at'] );
	}

	public function test_public_application_ignores_admin_only_fields() {
		$member = Chess_Army_Knife_Membership_Store::sanitize_member(
			$this->form_input(
				array(
					'status'      => 'active',
					'expiry_date' => '2030-01-01',
					'notes'       => 'sneaky',
				)
			),
			false
		);

		$this->assertArrayNotHasKey( 'status', $member );
		$this->assertArrayNotHasKey( 'expiry_date', $member );
		$this->assertArrayNotHasKey( 'notes', $member );
	}

	/**
	 * @dataProvider invalid_public_input
	 */
	public function test_public_application_rejects_invalid_input( array $overrides, $code ) {
		$result = Chess_Army_Knife_Membership_Store::sanitize_member( $this->form_input( $overrides ), false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function invalid_public_input() {
		return array(
			'no name'                 => array( array( 'name' => '  ' ), 'member_name' ),
			'no email'                => array( array( 'email' => '' ), 'member_email' ),
			'bad email'               => array( array( 'email' => 'not-an-email' ), 'member_email' ),
			'bad date of birth'       => array( array( 'date_of_birth' => '01/05/2015' ), 'member_dob' ),
			'date of birth is future' => array( array( 'date_of_birth' => '2027-01-01' ), 'member_dob' ),
			'no type'                 => array( array( 'membership_type_id' => '' ), 'member_type' ),
			'unknown type'            => array( array( 'membership_type_id' => '99' ), 'member_type' ),
			'type not on offer'       => array( array( 'membership_type_id' => '8' ), 'member_type' ),
		);
	}

	public function test_admin_can_add_a_member_with_only_a_name() {
		$member = Chess_Army_Knife_Membership_Store::sanitize_member( array( 'name' => 'Grace' ), true );

		$this->assertSame( 'Grace', $member['name'] );
		$this->assertSame( '', $member['email'] );
		$this->assertSame( 0, $member['membership_type_id'] );
		$this->assertSame( Chess_Army_Knife_Membership_Store::STATUS_ACTIVE, $member['status'] );
		$this->assertNull( $member['expiry_date'] );
	}

	public function test_admin_can_keep_a_type_that_is_no_longer_offered() {
		$member = Chess_Army_Knife_Membership_Store::sanitize_member(
			array(
				'name'               => 'Grace',
				'membership_type_id' => '8',
			),
			true
		);

		$this->assertSame( 8, $member['membership_type_id'] );
		$this->assertSame( 'Retired', $member['type_name'] );
	}

	public function test_admin_fields_are_validated() {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return $value;
			}
		);

		$member = Chess_Army_Knife_Membership_Store::sanitize_member(
			array(
				'name'           => 'Grace',
				'status'         => 'cancelled',
				'start_date'     => '2026-09-01',
				'expiry_date'    => '2027-08-31',
				'paid_on'        => '2026-09-05',
				'payment_method' => 'cash',
				'notes'          => ' Paid at the club ',
			),
			true
		);

		$this->assertSame( 'cancelled', $member['status'] );
		$this->assertSame( '2026-09-01', $member['start_date'] );
		$this->assertSame( '2027-08-31', $member['expiry_date'] );
		$this->assertSame( '2026-09-05', $member['paid_on'] );
		$this->assertSame( 'cash', $member['payment_method'] );
		$this->assertSame( 'Paid at the club', $member['notes'] );
	}

	public function test_admin_unknown_payment_method_is_dropped() {
		$member = Chess_Army_Knife_Membership_Store::sanitize_member(
			array(
				'name'           => 'Grace',
				'payment_method' => 'bitcoin',
			),
			true
		);

		$this->assertSame( '', $member['payment_method'] );
	}

	/**
	 * @dataProvider invalid_admin_input
	 */
	public function test_admin_rejects_invalid_input( array $overrides, $code ) {
		$result = Chess_Army_Knife_Membership_Store::sanitize_member( $overrides + array( 'name' => 'Grace' ), true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function invalid_admin_input() {
		return array(
			'unknown status'      => array( array( 'status' => 'vip' ), 'member_status' ),
			'bad expiry date'     => array( array( 'expiry_date' => 'next year' ), 'member_date' ),
			'bad paid date'       => array( array( 'paid_on' => '2026-13-01' ), 'member_date' ),
			'expiry before start' => array(
				array(
					'start_date'  => '2026-09-01',
					'expiry_date' => '2026-08-01',
				),
				'member_date_order',
			),
			'bad email'           => array( array( 'email' => 'nope' ), 'member_email' ),
			'unknown type'        => array( array( 'membership_type_id' => '99' ), 'member_type' ),
		);
	}

	public function test_effective_status_turns_lapsed_active_members_into_expired() {
		$today = '2026-09-29';
		$base  = array(
			'status'      => 'active',
			'expiry_date' => '',
		);

		$this->assertSame( 'active', Chess_Army_Knife_Membership_Store::effective_status( $base, $today ), 'No expiry never lapses.' );
		$this->assertSame( 'active', Chess_Army_Knife_Membership_Store::effective_status( array( 'expiry_date' => '2026-09-29' ) + $base, $today ), 'The expiry date is the last day.' );
		$this->assertSame( 'expired', Chess_Army_Knife_Membership_Store::effective_status( array( 'expiry_date' => '2026-09-28' ) + $base, $today ) );
		$this->assertSame(
			'cancelled',
			Chess_Army_Knife_Membership_Store::effective_status(
				array(
					'status'      => 'cancelled',
					'expiry_date' => '2020-01-01',
				),
				$today
			),
			'Only active members expire.'
		);
		$this->assertSame(
			'pending',
			Chess_Army_Knife_Membership_Store::effective_status(
				array(
					'status'      => 'pending',
					'expiry_date' => '',
				),
				$today
			)
		);
	}
}
