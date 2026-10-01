<?php
/**
 * Integration tests: personal data export and erasure, retention, and consent for members.
 *
 * @package Chess_Army_Knife
 */

class MembershipPrivacyTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Membership_Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Member_History::install_table();
		Chess_Army_Knife_Cache::install_table(); // The daily job that purges members also clears expired cache rows.
	}

	private function member( array $data = array() ) {
		return Chess_Army_Knife_Membership_Store::save_member(
			$data + array(
				'name'   => 'Ada Lovelace',
				'email'  => 'ada@example.test',
				'status' => Chess_Army_Knife_Membership_Store::STATUS_ACTIVE,
			)
		);
	}

	/**
	 * Make a record look as if it was last changed some time ago.
	 *
	 * @param int    $id     Member id.
	 * @param string $months How long ago, for example "30 months".
	 */
	private function age( $id, $months ) {
		global $wpdb;
		$wpdb->update( Chess_Army_Knife_Membership_Store::table(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', strtotime( "-$months" ) ) ), array( 'id' => $id ) );
	}

	private function ids( array $members ) {
		return wp_list_pluck( $members, 'id' );
	}

	public function test_the_exporter_and_eraser_are_registered_with_wordpress() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'chess-army-knife-membership', $exporters );
		$this->assertIsCallable( $exporters['chess-army-knife-membership']['callback'] );
		$this->assertArrayHasKey( 'chess-army-knife-membership', $erasers );
		$this->assertIsCallable( $erasers['chess-army-knife-membership']['callback'] );
	}

	public function test_the_exporter_returns_everything_held_about_an_email_address() {
		$id = $this->member(
			array(
				'phone'          => '01234',
				'date_of_birth'  => '2015-05-01',
				'guardian_name'  => 'Charles',
				'type_name'      => 'Junior',
				'paid_on'        => '2026-09-05',
				'payment_method' => 'cash',
				'notes'          => 'Prefers email',
				'consent_at'     => '2026-09-01 10:00:00',
			)
		);
		$this->member(
			array(
				'name'  => 'Someone Else',
				'email' => 'other@example.test',
			)
		);

		$result = Chess_Army_Knife_Membership_Privacy::export( 'ada@example.test' );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'membership-' . $id, $result['data'][0]['item_id'] );
		$values = wp_list_pluck( $result['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'Ada Lovelace', $values['Name'] );
		$this->assertSame( '2015-05-01', $values['Date of birth'] );
		$this->assertSame( 'Charles', $values['Parent or guardian'] );
		$this->assertSame( 'Cash', $values['Payment method'] );
		$this->assertSame( 'Prefers email', $values['Club notes'] );
		$this->assertSame( '2026-09-01 10:00:00', $values['Agreed to the club keeping these details (UTC)'] );
		$this->assertArrayNotHasKey( 'Membership expires', $values, 'Empty fields are left out.' );
	}

	public function test_the_exporter_finds_nothing_for_an_unknown_or_empty_address() {
		$this->member();

		$this->assertSame( array(), Chess_Army_Knife_Membership_Privacy::export( 'nobody@example.test' )['data'] );
		$this->assertSame( array(), Chess_Army_Knife_Membership_Privacy::export( '' )['data'] );
	}

	public function test_the_eraser_deletes_a_record_with_no_payment() {
		$id = $this->member( array( 'phone' => '01234' ) );

		$result = Chess_Army_Knife_Membership_Privacy::erase( 'ada@example.test' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertNull( Chess_Army_Knife_Membership_Store::get_member( $id ) );
	}

	public function test_the_eraser_strips_personal_details_from_a_record_with_a_payment_but_keeps_it_for_the_accounts() {
		$id = $this->member(
			array(
				'phone'              => '01234',
				'date_of_birth'      => '2015-05-01',
				'guardian_name'      => 'Charles',
				'ecf_code'           => '12345J',
				'notes'              => 'Prefers email',
				'consent_at'         => '2026-09-01 10:00:00',
				'membership_type_id' => 0,
				'type_name'          => 'Junior',
				'paid_on'            => '2026-09-05',
				'payment_method'     => 'bank_transfer',
				'expiry_date'        => '2027-08-31',
			)
		);

		$result = Chess_Army_Knife_Membership_Privacy::erase( 'ada@example.test' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertNotEmpty( $result['messages'] );

		$member = Chess_Army_Knife_Membership_Store::get_member( $id );
		$this->assertSame( Chess_Army_Knife_Membership_Store::erased_name(), $member['name'] );
		foreach ( array( 'email', 'phone', 'date_of_birth', 'guardian_name', 'ecf_code', 'notes', 'consent_at' ) as $field ) {
			$this->assertSame( '', $member[ $field ], "$field is erased." );
		}
		$this->assertSame( '2026-09-05', $member['paid_on'] );
		$this->assertSame( 'bank_transfer', $member['payment_method'] );
		$this->assertSame( 'Junior', $member['type_name'] );
		$this->assertSame( array(), Chess_Army_Knife_Membership_Store::get_members_by_email( 'ada@example.test' ), 'They can no longer be found by email.' );
	}

	private function junior() {
		return $this->member(
			array(
				'name'                  => 'Junior Player',
				'email'                 => '',
				'date_of_birth'         => '2015-05-01',
				'guardian_name'         => 'Charles Player',
				'guardian_email'        => 'charles@example.test',
				'guardian_phone'        => '07700 900123',
				'newsletter_consent_at' => '2026-09-01 10:00:00',
				'whatsapp_consent_at'   => '2026-09-01 10:00:00',
				'paid_on'               => '2026-09-05',
			)
		);
	}

	public function test_a_junior_is_found_and_exported_by_their_parents_email() {
		$id = $this->junior();

		$data = Chess_Army_Knife_Membership_Privacy::export( 'charles@example.test' )['data'];

		$this->assertCount( 1, $data );
		$this->assertSame( 'membership-' . $id, $data[0]['item_id'] );
		$values = wp_list_pluck( $data[0]['data'], 'value', 'name' );
		$this->assertSame( 'Junior Player', $values['Name'] );
		$this->assertSame( 'Charles Player', $values['Parent or guardian'] );
		$this->assertSame( 'charles@example.test', $values['Parent or guardian email'] );
		$this->assertSame( '2026-09-01 10:00:00', $values['Agreed to receive the newsletter (UTC)'] );
		$this->assertSame( '2026-09-01 10:00:00', $values['Agreed to be added to WhatsApp groups (UTC)'] );
	}

	public function test_erasing_by_a_parents_email_strips_the_juniors_record_including_the_parent_and_consents() {
		$id = $this->junior();

		$result = Chess_Army_Knife_Membership_Privacy::erase( 'charles@example.test' );

		$this->assertTrue( $result['items_removed'] );
		$member = Chess_Army_Knife_Membership_Store::get_member( $id );
		$this->assertSame( Chess_Army_Knife_Membership_Store::erased_name(), $member['name'] );
		foreach ( array( 'guardian_name', 'guardian_email', 'guardian_phone', 'date_of_birth', 'newsletter_consent_at', 'whatsapp_consent_at' ) as $field ) {
			$this->assertSame( '', $member[ $field ], "$field is erased." );
		}
		$this->assertSame( array(), Chess_Army_Knife_Membership_Store::get_members_by_email( 'charles@example.test' ) );
	}

	public function test_the_eraser_only_touches_the_address_it_was_asked_about() {
		$this->member();
		$other = $this->member(
			array(
				'name'  => 'Someone Else',
				'email' => 'other@example.test',
			)
		);

		$result = Chess_Army_Knife_Membership_Privacy::erase( 'nobody@example.test' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertNotNull( Chess_Army_Knife_Membership_Store::get_member( $other ) );
		$this->assertCount( 2, Chess_Army_Knife_Membership_Store::get_members() );
	}

	public function test_an_application_records_when_consent_was_given() {
		$type = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Memberships::POST_TYPE,
				'post_title'  => 'Junior',
				'post_status' => 'publish',
			)
		);

		$id = Chess_Army_Knife_Membership_Form::submit(
			array(
				Chess_Army_Knife_Membership_Form::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Membership_Form::ACTION ),
				'name'               => 'Ada Lovelace',
				'email'              => 'ada@example.test',
				'membership_type_id' => (string) $type,
				'consent'            => '1',
			)
		);

		$consent = Chess_Army_Knife_Membership_Store::get_member( $id )['consent_at'];
		$this->assertNotSame( '', $consent );
		$this->assertLessThan( 10, abs( time() - strtotime( $consent . ' UTC' ) ) );
		delete_transient( 'chess_army_knife_apply_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test clean-up.
	}

	public function test_a_member_added_by_the_club_has_no_consent_recorded() {
		$id = $this->member();

		$this->assertSame( '', Chess_Army_Knife_Membership_Store::get_member( $id )['consent_at'] );
	}

	/* -------------------------------------------------------------
	 * Retention
	 * ------------------------------------------------------------- */

	public function test_old_records_are_erased_after_the_retention_period() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'member_retention_months' => 24,
			)
		);
		$today = current_time( 'Y-m-d' );

		$old_declined = $this->member(
			array(
				'name'   => 'Old declined',
				'email'  => 'a@example.test',
				'status' => 'rejected',
			)
		);
		$old_pending  = $this->member(
			array(
				'name'   => 'Old pending',
				'email'  => 'b@example.test',
				'status' => 'pending',
			)
		);
		$old_left     = $this->member(
			array(
				'name'   => 'Old cancelled',
				'email'  => 'c@example.test',
				'status' => 'cancelled',
			)
		);
		$lapsed       = $this->member(
			array(
				'name'        => 'Lapsed long ago',
				'email'       => 'd@example.test',
				'expiry_date' => wp_date( 'Y-m-d', strtotime( '-30 months' ) ),
			)
		);
		$lapsed_paid  = $this->member(
			array(
				'name'        => 'Lapsed and paid',
				'email'       => 'e@example.test',
				'expiry_date' => wp_date( 'Y-m-d', strtotime( '-30 months' ) ),
				'paid_on'     => '2023-09-01',
			)
		);

		$recent_declined = $this->member(
			array(
				'name'   => 'Recent declined',
				'email'  => 'f@example.test',
				'status' => 'rejected',
			)
		);
		$lapsed_lately   = $this->member(
			array(
				'name'        => 'Lapsed lately',
				'email'       => 'g@example.test',
				'expiry_date' => wp_date( 'Y-m-d', strtotime( '-1 month' ) ),
			)
		);
		$current         = $this->member(
			array(
				'name'        => 'Current',
				'email'       => 'h@example.test',
				'expiry_date' => $today,
			)
		);
		$no_expiry       = $this->member(
			array(
				'name'  => 'Life member',
				'email' => 'i@example.test',
			)
		);

		foreach ( array( $old_declined, $old_pending, $old_left ) as $id ) {
			$this->age( $id, '30 months' );
		}
		$this->age( $no_expiry, '30 months' );
		$this->age( $current, '30 months' );

		$erased = Chess_Army_Knife_Membership_Privacy::purge_old_records();

		$this->assertSame( 5, $erased );
		foreach ( array( $old_declined, $old_pending, $old_left, $lapsed ) as $id ) {
			$this->assertNull( Chess_Army_Knife_Membership_Store::get_member( $id ), "Record $id is deleted." );
		}
		$this->assertSame( Chess_Army_Knife_Membership_Store::erased_name(), Chess_Army_Knife_Membership_Store::get_member( $lapsed_paid )['name'], 'A paid record is kept without personal details.' );
		foreach ( array( $recent_declined, $lapsed_lately, $current, $no_expiry ) as $id ) {
			$this->assertNotNull( Chess_Army_Knife_Membership_Store::get_member( $id ), "Record $id is kept." );
			$this->assertNotSame( Chess_Army_Knife_Membership_Store::erased_name(), Chess_Army_Knife_Membership_Store::get_member( $id )['name'] );
		}

		$this->assertSame( 0, Chess_Army_Knife_Membership_Privacy::purge_old_records(), 'Running again finds nothing new, including the already anonymised record.' );
	}

	public function test_nothing_is_erased_when_retention_is_switched_off() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'member_retention_months' => 0,
			)
		);
		$id = $this->member( array( 'status' => 'rejected' ) );
		$this->age( $id, '100 months' );

		$this->assertSame( 0, Chess_Army_Knife_Membership_Privacy::purge_old_records() );
		$this->assertNotNull( Chess_Army_Knife_Membership_Store::get_member( $id ) );
	}

	public function test_the_purge_runs_from_the_plugins_daily_job() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'member_retention_months' => 12,
			)
		);
		$id = $this->member( array( 'status' => 'rejected' ) );
		$this->age( $id, '20 months' );

		do_action( 'Chess_Army_Knife_cleanup_cache' );

		$this->assertNull( Chess_Army_Knife_Membership_Store::get_member( $id ) );
	}

	public function test_the_retention_setting_is_kept_between_zero_and_ten_years() {
		$this->assertSame( 24, Chess_Army_Knife_Settings::defaults()['member_retention_months'] );
		$this->assertSame( 0, Chess_Army_Knife_Settings::sanitize( array( 'member_retention_months' => '-5' ) )['member_retention_months'] );
		$this->assertSame( 120, Chess_Army_Knife_Settings::sanitize( array( 'member_retention_months' => '9999' ) )['member_retention_months'] );
		$this->assertSame( 36, Chess_Army_Knife_Settings::sanitize( array( 'member_retention_months' => '36' ) )['member_retention_months'] );
	}

	public function test_the_form_block_links_to_the_privacy_policy_when_the_site_has_one() {
		self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Memberships::POST_TYPE,
				'post_title'  => 'Junior',
				'post_status' => 'publish',
			)
		);
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Privacy',
				'post_status' => 'publish',
			)
		);

		$without = do_blocks( '<!-- wp:chess-army-knife/membership-form /-->' );
		update_option( 'wp_page_for_privacy_policy', $page );
		$with = do_blocks( '<!-- wp:chess-army-knife/membership-form /-->' );

		$this->assertStringNotContainsString( 'Read how we handle your data', $without );
		$this->assertStringContainsString( 'Read how we handle your data', $with );
		$this->assertStringContainsString( get_permalink( $page ), $with );
	}

	/* -------------------------------------------------------------
	 * The data policy
	 * ------------------------------------------------------------- */

	private function policy_text() {
		$text = '';
		foreach ( Chess_Army_Knife_Membership_Privacy::policy_sections() as $section ) {
			$text .= $section['heading'] . "\n" . implode( "\n", $section['paragraphs'] ) . "\n";
		}
		return $text;
	}

	public function test_the_policy_covers_the_lawful_basis_the_ecf_juniors_and_the_optional_extras() {
		$text = $this->policy_text();

		$this->assertStringContainsString( 'legitimate interests', $text );
		$this->assertStringContainsString( 'English Chess Federation', $text );
		$this->assertStringContainsString( 'ECF rating code', $text );
		$this->assertStringContainsString( 'parent or guardian', $text );
		$this->assertStringContainsString( 'WhatsApp', $text );
		$this->assertStringContainsString( 'newsletter', $text );
		$this->assertStringContainsString( 'Information Commissioner', $text );
	}

	public function test_the_policy_follows_the_retention_period() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'member_retention_months' => 18,
			)
		);
		$text = $this->policy_text();
		$this->assertStringContainsString( 'for 18 months afterwards', $text );

		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'member_retention_months' => 0,
			)
		);
		$text = $this->policy_text();
		$this->assertStringContainsString( 'until you ask us to delete them', $text );
		$this->assertStringContainsString( 'contact the club at [add an email address]', $text );
	}

	public function test_the_policy_is_added_to_the_privacy_policy_guide() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' ); // WordPress ignores policy text outside the admin.
		remove_all_actions( 'admin_init' ); // Only the fact that it has run matters here; its other callbacks call WordPress.org.
		do_action( 'admin_init' ); // WordPress only accepts policy text once admin_init has run.

		Chess_Army_Knife_Policies::set_up( 'data', true ); // The guide points to the page, so the page must exist.
		Chess_Army_Knife_Membership_Privacy::add_policy_content();
		$suggested = wp_json_encode( WP_Privacy_Policy_Content::get_suggested_policy_text() );

		// The detail lives on the Club data policy page; the guide points to it.
		$this->assertStringContainsString( 'Club membership', $suggested );
		$this->assertStringContainsString( 'chess_army_policy_link', $suggested );
		$this->assertStringContainsString( 'privacy-policy-tutorial', $suggested );
	}
}
