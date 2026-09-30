<?php
/**
 * Integration tests: people the club holds details for who are not members.
 *
 * @package Chess_Army_Knife
 */

class NonMembersTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Membership_Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Member_History::install_table();
	}

	private function person( $name, array $extra = array() ) {
		return Chess_Army_Knife_Membership_Store::save_member( $extra + array( 'name' => $name ) );
	}

	private function names( $view ) {
		$names = wp_list_pluck( Chess_Army_Knife_Membership_Store::get_members( array( 'view' => $view ) ), 'name' );
		sort( $names );
		return $names;
	}

	public function test_non_members_are_left_out_of_every_member_list_and_count() {
		$this->person( 'Member', array( 'status' => 'active' ) );
		$this->person( 'Applicant', array( 'status' => 'pending' ) );
		$this->person(
			'Guest',
			array(
				'status'   => 'nonmember',
				'ecf_code' => '111111A',
			)
		);

		foreach ( array( 'all', 'active', 'pending', 'expired', 'closed' ) as $view ) {
			$this->assertNotContains( 'Guest', $this->names( $view ), "$view leaves out non-members." );
		}
		$this->assertSame( array( 'Applicant', 'Member' ), $this->names( 'all' ) );
		$this->assertSame( array( 'Guest' ), $this->names( 'nonmember' ) );
		$this->assertSame( array( 'Applicant', 'Guest', 'Member' ), $this->names( 'people' ) );
		$this->assertSame( 2, Chess_Army_Knife_Membership_Store::count_view( 'all' ) );
		$this->assertSame( 1, Chess_Army_Knife_Membership_Store::count_view( 'active' ) );
		$this->assertSame( 1, Chess_Army_Knife_Membership_Store::count_view( 'nonmember' ) );
	}

	public function test_a_non_member_never_counts_as_a_current_member_even_with_an_expiry_date_in_the_future() {
		$this->person(
			'Guest',
			array(
				'status'      => 'nonmember',
				'expiry_date' => '2099-01-01',
			)
		);

		$this->assertSame( array(), $this->names( 'active' ) );
		$this->assertSame( array(), $this->names( 'expired' ) );
	}

	public function test_the_admin_form_can_record_someone_as_not_a_member() {
		$clean = Chess_Army_Knife_Membership_Store::sanitize_member(
			array(
				'name'   => 'Tournament Guest',
				'status' => 'nonmember',
			),
			true
		);

		$this->assertSame( 'nonmember', $clean['status'] );
		$this->assertArrayHasKey( 'nonmember', Chess_Army_Knife_Membership_Store::status_labels() );
	}

	public function test_the_public_form_can_never_create_a_non_member_or_choose_a_status() {
		$type = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Memberships::POST_TYPE,
				'post_title'  => 'Adult',
				'post_status' => 'publish',
			)
		);

		$clean = Chess_Army_Knife_Membership_Store::sanitize_member(
			array(
				'name'               => 'Ada',
				'email'              => 'ada@example.test',
				'membership_type_id' => (string) $type,
				'status'             => 'nonmember',
			),
			false
		);

		$this->assertArrayNotHasKey( 'status', $clean );
	}

	public function test_the_pickers_offer_members_and_guests_but_no_one_else() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->person(
			'Pat Member',
			array(
				'status'   => 'active',
				'ecf_code' => '100000A',
			)
		);
		$this->person(
			'Pat Guest',
			array(
				'status'   => 'nonmember',
				'ecf_code' => '200000B',
			)
		);
		$this->person( 'Pat Guest Nocode', array( 'status' => 'nonmember' ) );
		$this->person(
			'Pat Applicant',
			array(
				'status'   => 'pending',
				'ecf_code' => '300000C',
			)
		);
		$this->person(
			'Pat Left',
			array(
				'status'   => 'cancelled',
				'ecf_code' => '400000D',
			)
		);

		$request = new WP_REST_Request( 'GET', '/ecf-lms/v1/players' );
		$request->set_param( 'search', 'Pat' );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( array( 'Pat Guest', 'Pat Member' ), wp_list_pluck( $data, 'name' ) );
		$this->assertSame( 'not a club member', $data[0]['club'] );
		$this->assertSame( '', $data[1]['club'] );
	}

	public function test_ensure_person_creates_a_record_once_and_finds_it_again() {
		$first  = Chess_Army_Knife_Membership_Store::ensure_person( ' Guest One ', '12 34-5x' );
		$second = Chess_Army_Knife_Membership_Store::ensure_person( 'Guest One Renamed', '12345X' );

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( $first, $second, 'The ECF code identifies the person.' );
		$guest = Chess_Army_Knife_Membership_Store::get_member( $first );
		$this->assertSame( 'Guest One', $guest['name'] );
		$this->assertSame( '12345X', $guest['ecf_code'] );
		$this->assertSame( 'nonmember', $guest['status'] );
		$this->assertSame( 'manual', $guest['source'] );
	}

	public function test_ensure_person_without_a_code_matches_on_exact_name() {
		$first = Chess_Army_Knife_Membership_Store::ensure_person( 'No Code', '' );

		$this->assertSame( $first, Chess_Army_Knife_Membership_Store::ensure_person( 'No Code', '' ) );
		$this->assertNotSame( $first, Chess_Army_Knife_Membership_Store::ensure_person( 'No Code Two', '' ) );
		$this->assertSame( 0, Chess_Army_Knife_Membership_Store::ensure_person( '   ', '123' ) );
	}

	public function test_ensure_person_never_changes_an_existing_member() {
		$member = $this->person(
			'Real Member',
			array(
				'status'   => 'active',
				'ecf_code' => '777777M',
			)
		);

		$this->assertSame( $member, Chess_Army_Knife_Membership_Store::ensure_person( 'Real Member', '777777M' ) );
		$this->assertSame( 'active', Chess_Army_Knife_Membership_Store::get_member( $member )['status'] );
		$this->assertSame( array( 'Real Member' ), $this->names( 'active' ) );
	}

	public function test_using_a_guest_again_keeps_them_from_being_deleted_as_old() {
		global $wpdb;
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'member_retention_months' => 12,
			)
		);
		$stale  = $this->person(
			'Stale Guest',
			array(
				'status'   => 'nonmember',
				'ecf_code' => '1A',
			)
		);
		$active = $this->person(
			'Recent Guest',
			array(
				'status'   => 'nonmember',
				'ecf_code' => '2B',
			)
		);
		foreach ( array( $stale, $active ) as $id ) {
			$wpdb->update( Chess_Army_Knife_Membership_Store::table(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-30 months' ) ) ), array( 'id' => $id ) );
		}

		Chess_Army_Knife_Membership_Store::ensure_person( 'Recent Guest', '2B' );
		$erased = Chess_Army_Knife_Membership_Privacy::purge_old_records();

		$this->assertSame( 1, $erased );
		$this->assertNull( Chess_Army_Knife_Membership_Store::get_member( $stale ) );
		$this->assertNotNull( Chess_Army_Knife_Membership_Store::get_member( $active ) );
	}

	public function test_the_policy_mentions_people_who_are_not_members() {
		$text = '';
		foreach ( Chess_Army_Knife_Membership_Privacy::policy_sections() as $section ) {
			$text .= implode( ' ', $section['paragraphs'] );
		}

		$this->assertStringContainsString( 'without being members', $text );
	}
}
