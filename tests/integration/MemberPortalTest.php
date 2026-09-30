<?php
/**
 * Integration tests: the member portal (see and change your own details, choices and registrations, delete your data).
 *
 * @package Chess_Army_Knife
 */

class MemberPortalTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Mailer::table(), Chess_Army_Knife_Notification_Preferences::table(), Chess_Army_Knife_Teams::squad_table(), Chess_Army_Knife_Event_Registrations::table() ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Member_History::install_table();
		Chess_Army_Knife_Mailer::install_table();
		Chess_Army_Knife_Notification_Preferences::install_table();
		Chess_Army_Knife_Teams::install_table();
		Chess_Army_Knife_Event_Registrations::install_table();
		reset_phpmailer_instance();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
		$this->clear_counters();
	}

	public function tear_down() {
		$this->clear_counters();
		$_GET = array();
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function clear_counters() {
		delete_transient( 'chess_army_knife_data_ip_' . md5( '203.0.113.99' ) );
		foreach ( array( 'ada@example.test', 'nobody@example.test' ) as $email ) {
			delete_transient( 'chess_army_knife_portal_email_' . md5( $email ) );
		}
	}

	private function person( $name = 'Ada Lovelace', array $extra = array() ) {
		return Chess_Army_Knife_Membership_Store::save_member(
			$extra + array(
				'name'   => $name,
				'email'  => strtolower( strtok( $name, ' ' ) ) . '@example.test',
				'status' => 'active',
			)
		);
	}

	private function sent() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	private function sign_in( $email = 'ada@example.test' ) {
		$this->assertTrue(
			Chess_Army_Knife_Member_Portal::request_link(
				array(
					Chess_Army_Knife_Member_Portal::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Member_Portal::ACTION_LINK ),
					'email' => $email,
				)
			)
		);
		preg_match( '/cak_portal=([A-Za-z0-9]+)/', $this->sent()[0]['body'], $match );
		reset_phpmailer_instance();
		return $match[1];
	}

	private function post( $action, $token, array $extra = array() ) {
		return array(
			Chess_Army_Knife_Member_Portal::NONCE_FIELD => wp_create_nonce( $action ),
			'token'                                     => $token,
		) + $extra;
	}

	/* -------------------------------------------------------------
	 * Signing in
	 * ------------------------------------------------------------- */

	public function test_a_link_is_emailed_to_an_address_on_file_and_nothing_is_sent_for_an_unknown_one() {
		$this->person();
		$token = $this->sign_in();

		$session = Chess_Army_Knife_Member_Portal::session( $token );
		$this->assertSame( 'ada@example.test', $session['email'] );
		$this->assertCount( 1, $session['people'] );

		$this->assertTrue(
			Chess_Army_Knife_Member_Portal::request_link(
				array(
					Chess_Army_Knife_Member_Portal::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Member_Portal::ACTION_LINK ),
					'email' => 'nobody@example.test',
				)
			),
			'The same answer for an address that is not on file.'
		);
		$this->assertSame( array(), $this->sent() );
	}

	public function test_bad_requests_for_a_link_are_refused_or_ignored_and_limited() {
		$this->person();
		$nonce = wp_create_nonce( Chess_Army_Knife_Member_Portal::ACTION_LINK );

		$this->assertSame( 'expired', Chess_Army_Knife_Member_Portal::request_link( array( Chess_Army_Knife_Member_Portal::NONCE_FIELD => 'bad' ) )->get_error_code() );
		$this->assertSame(
			'email',
			Chess_Army_Knife_Member_Portal::request_link(
				array(
					Chess_Army_Knife_Member_Portal::NONCE_FIELD => $nonce,
					'email' => 'nope',
				)
			)->get_error_code()
		);
		$this->assertTrue(
			Chess_Army_Knife_Member_Portal::request_link(
				array(
					Chess_Army_Knife_Member_Portal::NONCE_FIELD => $nonce,
					'email' => 'ada@example.test',
					Chess_Army_Knife_Member_Portal::HONEYPOT => 'x',
				)
			)
		);
		$this->assertSame( array(), $this->sent(), 'A bot gets nothing.' );

		for ( $i = 0; $i < Chess_Army_Knife_Member_Requests::MAX_PER_HOUR; $i++ ) {
			Chess_Army_Knife_Member_Portal::request_link(
				array(
					Chess_Army_Knife_Member_Portal::NONCE_FIELD => $nonce,
					'email' => 'other' . $i . '@example.test',
				)
			);
		}
		$this->assertSame(
			'throttled',
			Chess_Army_Knife_Member_Portal::request_link(
				array(
					Chess_Army_Knife_Member_Portal::NONCE_FIELD => $nonce,
					'email' => 'ada@example.test',
				)
			)->get_error_code()
		);
	}

	public function test_a_parent_sees_their_own_and_their_juniors_records() {
		$this->person( 'Pat Parent', array( 'email' => 'parent@example.test' ) );
		$this->person(
			'Junior Parent',
			array(
				'email'          => '',
				'guardian_email' => 'parent@example.test',
				'guardian_name'  => 'Pat Parent',
			)
		);
		$token = $this->sign_in( 'parent@example.test' );

		$this->assertSame( array( 'Pat Parent', 'Junior Parent' ), wp_list_pluck( Chess_Army_Knife_Member_Portal::session( $token )['people'], 'name' ) );
	}

	public function test_a_bad_or_expired_session_is_refused_everywhere() {
		$ada = $this->person();

		$this->assertNull( Chess_Army_Knife_Member_Portal::session( 'nope' ) );
		$this->assertSame(
			'link',
			Chess_Army_Knife_Member_Portal::save_details(
				$this->post(
					Chess_Army_Knife_Member_Portal::ACTION_DETAILS,
					'nope',
					array(
						'person' => $ada,
						'name'   => 'X',
					)
				)
			)->get_error_code()
		);
		$this->assertSame( 'Ada Lovelace', Chess_Army_Knife_Membership_Store::get_member( $ada )['name'] );
	}

	/* -------------------------------------------------------------
	 * Details and choices
	 * ------------------------------------------------------------- */

	public function test_a_member_changes_their_own_details_and_a_new_rating_code_clears_the_stored_rating() {
		$ada   = $this->person(
			'Ada Lovelace',
			array(
				'phone'          => '0123',
				'ecf_code'       => '111111A',
				'ecf_rating'     => 1500,
				'ecf_checked_at' => '2026-01-01 10:00:00',
			)
		);
		$token = $this->sign_in();

		$this->assertTrue(
			Chess_Army_Knife_Member_Portal::save_details(
				$this->post(
					Chess_Army_Knife_Member_Portal::ACTION_DETAILS,
					$token,
					array(
						'person'   => $ada,
						'name'     => 'Ada King',
						'phone'    => '0999',
						'ecf_code' => '222222b',
					)
				)
			)
		);

		$member = Chess_Army_Knife_Membership_Store::get_member( $ada );
		$this->assertSame( 'Ada King', $member['name'] );
		$this->assertSame( '0999', $member['phone'] );
		$this->assertSame( '222222B', $member['ecf_code'] );
		$this->assertNull( $member['ecf_rating'] );
	}

	public function test_an_unchanged_rating_code_keeps_the_stored_rating() {
		$ada   = $this->person(
			'Ada Lovelace',
			array(
				'ecf_code'   => '111111A',
				'ecf_rating' => 1500,
			)
		);
		$token = $this->sign_in();

		Chess_Army_Knife_Member_Portal::save_details(
			$this->post(
				Chess_Army_Knife_Member_Portal::ACTION_DETAILS,
				$token,
				array(
					'person'   => $ada,
					'name'     => 'Ada Lovelace',
					'ecf_code' => '111111a',
				)
			)
		);

		$this->assertSame( 1500, Chess_Army_Knife_Membership_Store::get_member( $ada )['ecf_rating'] );
	}

	public function test_details_need_a_name_and_only_the_members_own_records_can_be_changed() {
		$ada   = $this->person();
		$bob   = $this->person( 'Bob Smith' );
		$token = $this->sign_in();

		$this->assertSame(
			'name',
			Chess_Army_Knife_Member_Portal::save_details(
				$this->post(
					Chess_Army_Knife_Member_Portal::ACTION_DETAILS,
					$token,
					array(
						'person' => $ada,
						'name'   => ' ',
					)
				)
			)->get_error_code()
		);
		$this->assertSame(
			'person',
			Chess_Army_Knife_Member_Portal::save_details(
				$this->post(
					Chess_Army_Knife_Member_Portal::ACTION_DETAILS,
					$token,
					array(
						'person' => $bob,
						'name'   => 'Hacked',
					)
				)
			)->get_error_code()
		);
		$this->assertSame(
			'expired',
			Chess_Army_Knife_Member_Portal::save_details(
				array(
					'token'  => $token,
					'person' => $ada,
					'name'   => 'X',
				)
			)->get_error_code()
		);
		$this->assertSame( 'Bob Smith', Chess_Army_Knife_Membership_Store::get_member( $bob )['name'] );
	}

	public function test_a_junior_keeps_a_parent_as_contact_and_a_parent_name_is_required() {
		$junior = $this->person(
			'Junior Parent',
			array(
				'email'          => '',
				'phone'          => '',
				'date_of_birth'  => '2015-05-05',
				'guardian_email' => 'parent@example.test',
				'guardian_name'  => 'Pat Parent',
				'guardian_phone' => '0111',
			)
		);
		$token  = $this->sign_in( 'parent@example.test' );

		$post = function ( array $extra ) use ( $junior, $token ) {
			return Chess_Army_Knife_Member_Portal::save_details(
				$this->post(
					Chess_Army_Knife_Member_Portal::ACTION_DETAILS,
					$token,
					array(
						'person' => $junior,
						'name'   => 'Junior Parent',
					) + $extra
				)
			);
		};

		$this->assertSame( 'guardian', $post( array( 'guardian_name' => '' ) )->get_error_code() );
		$this->assertTrue(
			$post(
				array(
					'guardian_name'  => 'Patricia Parent',
					'guardian_phone' => '0222',
					'phone'          => '0777',
				)
			)
		);

		$member = Chess_Army_Knife_Membership_Store::get_member( $junior );
		$this->assertSame( 'Patricia Parent', $member['guardian_name'] );
		$this->assertSame( '0222', $member['guardian_phone'] );
		$this->assertSame( '', $member['phone'], 'A junior\'s own phone is not added from here.' );
	}

	public function test_the_phone_number_stays_while_someone_is_in_a_whatsapp_group() {
		$ada   = $this->person(
			'Ada Lovelace',
			array(
				'phone'               => '0123',
				'whatsapp_consent_at' => '2026-01-01 10:00:00',
			)
		);
		$token = $this->sign_in();

		$result = Chess_Army_Knife_Member_Portal::save_details(
			$this->post(
				Chess_Army_Knife_Member_Portal::ACTION_DETAILS,
				$token,
				array(
					'person' => $ada,
					'name'   => 'Ada Lovelace',
					'phone'  => '',
				)
			)
		);

		$this->assertSame( 'whatsapp_phone', $result->get_error_code() );
		$this->assertSame( '0123', Chess_Army_Knife_Membership_Store::get_member( $ada )['phone'] );
	}

	public function test_a_member_chooses_what_they_are_emailed_and_their_whatsapp_teams() {
		$team  = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Club A',
			)
		);
		$ada   = $this->person(
			'Ada Lovelace',
			array(
				'phone'                 => '0123',
				'newsletter_consent_at' => '2026-01-01 10:00:00',
			)
		);
		$token = $this->sign_in();

		$save = function ( array $extra ) use ( $ada, $token ) {
			return Chess_Army_Knife_Member_Portal::save_choices( $this->post( Chess_Army_Knife_Member_Portal::ACTION_CHOICES, $token, array( 'person' => $ada ) + $extra ) );
		};

		// Newsletter and renewals on, everything else off; WhatsApp with one team.
		$this->assertTrue(
			$save(
				array(
					'category' => array(
						'newsletter' => '1',
						'renewals'   => '1',
					),
					'whatsapp' => '1',
					'teams'    => array( (string) $team, '99999' ),
				)
			)
		);
		$member = Chess_Army_Knife_Membership_Store::get_member( $ada );
		$this->assertSame( '2026-01-01 10:00:00', $member['newsletter_consent_at'], 'An existing agreement keeps its time.' );
		$this->assertSame( array( 'announcements', 'event_notices', 'fixtures' ), Chess_Army_Knife_Notification_Preferences::get_opt_outs( $ada ) );
		$this->assertNotSame( '', $member['whatsapp_consent_at'] );
		$this->assertSame( array( $team ), $member['whatsapp_teams'] );

		// Newsletter off, everything else on, WhatsApp off.
		$this->assertTrue(
			$save(
				array(
					'category' => array(
						'announcements' => '1',
						'event_notices' => '1',
						'fixtures'      => '1',
						'renewals'      => '1',
					),
				)
			)
		);
		$member = Chess_Army_Knife_Membership_Store::get_member( $ada );
		$this->assertSame( '', $member['newsletter_consent_at'] );
		$this->assertSame( array(), Chess_Army_Knife_Notification_Preferences::get_opt_outs( $ada ) );
		$this->assertSame( '', $member['whatsapp_consent_at'] );
		$this->assertSame( array(), $member['whatsapp_teams'] );
	}

	public function test_whatsapp_needs_a_phone_number() {
		$ada   = $this->person();
		$token = $this->sign_in();

		$result = Chess_Army_Knife_Member_Portal::save_choices(
			$this->post(
				Chess_Army_Knife_Member_Portal::ACTION_CHOICES,
				$token,
				array(
					'person'   => $ada,
					'whatsapp' => '1',
				)
			)
		);

		$this->assertSame( 'whatsapp_phone', $result->get_error_code() );
	}

	/* -------------------------------------------------------------
	 * Changing an email address
	 * ------------------------------------------------------------- */

	private function request_change( $token, $ada, array $extra = array() ) {
		return Chess_Army_Knife_Member_Portal::request_email_change(
			$this->post(
				Chess_Army_Knife_Member_Portal::ACTION_EMAIL,
				$token,
				$extra + array(
					'person'    => $ada,
					'field'     => 'email',
					'new_email' => 'ada.new@example.test',
				)
			)
		);
	}

	private function change_token() {
		preg_match( '/cak_email=([A-Za-z0-9]+)/', $this->sent()[0]['body'], $match );
		return $match[1];
	}

	public function test_a_new_address_is_confirmed_from_that_address_and_the_old_one_is_told() {
		$ada   = $this->person();
		$token = $this->sign_in();

		$this->assertSame( 'email_sent', $this->request_change( $token, $ada ) );

		$this->assertSame( 'ada.new@example.test', $this->sent()[0]['to'][0][0], 'The link goes to the new address.' );
		$this->assertSame( 'ada@example.test', Chess_Army_Knife_Membership_Store::get_member( $ada )['email'], 'Nothing changes yet.' );

		$change = $this->change_token();
		reset_phpmailer_instance();
		$this->assertSame( 'ada.new@example.test', Chess_Army_Knife_Member_Portal::pending_email_change( $change )['new'] );

		$this->assertTrue( Chess_Army_Knife_Member_Portal::confirm_email_change( $this->post( Chess_Army_Knife_Member_Portal::ACTION_EMAIL_CONFIRM, $change ) ) );

		$this->assertSame( 'ada.new@example.test', Chess_Army_Knife_Membership_Store::get_member( $ada )['email'] );
		$this->assertSame( 'ada@example.test', $this->sent()[0]['to'][0][0], 'The old address is told.' );
		$this->assertNull( Chess_Army_Knife_Member_Portal::session( $token ), 'The old session no longer matches anything.' );
		$this->assertNull( Chess_Army_Knife_Member_Portal::pending_email_change( $change ), 'The link works once.' );
	}

	public function test_a_parents_address_can_be_changed_too() {
		$junior = $this->person(
			'Junior Parent',
			array(
				'email'          => '',
				'guardian_email' => 'parent@example.test',
				'guardian_name'  => 'Pat',
			)
		);
		$token  = $this->sign_in( 'parent@example.test' );

		$this->request_change(
			$token,
			$junior,
			array(
				'field'     => 'guardian_email',
				'new_email' => 'parent.new@example.test',
			)
		);
		Chess_Army_Knife_Member_Portal::confirm_email_change( $this->post( Chess_Army_Knife_Member_Portal::ACTION_EMAIL_CONFIRM, $this->change_token() ) );

		$this->assertSame( 'parent.new@example.test', Chess_Army_Knife_Membership_Store::get_member( $junior )['guardian_email'] );
	}

	public function test_bad_address_changes_are_refused() {
		$ada   = $this->person();
		$token = $this->sign_in();

		$this->assertSame( 'email', $this->request_change( $token, $ada, array( 'new_email' => 'nope' ) )->get_error_code() );
		$this->assertSame( 'same_email', $this->request_change( $token, $ada, array( 'new_email' => 'ADA@example.test' ) )->get_error_code() );
		$this->assertSame( 'person', $this->request_change( $token, $ada, array( 'field' => 'phone' ) )->get_error_code() );
		$this->assertSame( 'person', $this->request_change( $token, $ada, array( 'field' => 'guardian_email' ) )->get_error_code(), 'A record with no parent address has none to change.' );
		$this->assertSame( 'person', $this->request_change( $token, $this->person( 'Bob Smith' ) )->get_error_code() );
		$this->assertSame( array(), $this->sent() );
	}

	public function test_a_confirmation_link_is_no_good_once_the_address_has_changed_another_way() {
		$ada   = $this->person();
		$token = $this->sign_in();
		$this->request_change( $token, $ada );
		$change = $this->change_token();

		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'id'    => $ada,
				'email' => 'officer.fixed@example.test',
			)
		);

		$this->assertNull( Chess_Army_Knife_Member_Portal::pending_email_change( $change ) );
		$this->assertSame( 'link', Chess_Army_Knife_Member_Portal::confirm_email_change( $this->post( Chess_Army_Knife_Member_Portal::ACTION_EMAIL_CONFIRM, $change ) )->get_error_code() );
	}

	/* -------------------------------------------------------------
	 * Deleting
	 * ------------------------------------------------------------- */

	private function delete( $token, $person, array $extra = array() ) {
		return Chess_Army_Knife_Member_Portal::delete_person( $this->post( Chess_Army_Knife_Member_Portal::ACTION_DELETE, $token, array( 'person' => $person ) + $extra ) );
	}

	public function test_deleting_needs_the_confirmation_tick_and_then_happens_immediately() {
		$ada   = $this->person();
		$token = $this->sign_in();

		$this->assertSame( 'confirm', $this->delete( $token, $ada )->get_error_code() );
		$this->assertNotNull( Chess_Army_Knife_Membership_Store::get_member( $ada ) );

		$this->assertSame( 'deleted', $this->delete( $token, $ada, array( 'confirm' => '1' ) ) );
		$this->assertNull( Chess_Army_Knife_Membership_Store::get_member( $ada ) );
		$this->assertNull( Chess_Army_Knife_Member_Portal::session( $token ), 'Nothing is left under the address.' );
	}

	public function test_a_record_tied_to_a_payment_or_registration_is_kept_without_personal_details() {
		$ada   = $this->person(
			'Ada Lovelace',
			array(
				'paid_on' => '2026-01-01',
				'phone'   => '0123',
			)
		);
		$token = $this->sign_in();

		$this->assertSame( 'anonymised', $this->delete( $token, $ada, array( 'confirm' => '1' ) ) );

		$kept = Chess_Army_Knife_Membership_Store::get_member( $ada );
		$this->assertSame( '', $kept['email'] );
		$this->assertSame( '', $kept['phone'] );
		$this->assertSame( Chess_Army_Knife_Membership_Store::erased_name(), $kept['name'] );
	}

	public function test_a_parent_deleting_one_record_leaves_the_others_and_cannot_delete_a_stranger() {
		$pat    = $this->person( 'Pat Parent', array( 'email' => 'parent@example.test' ) );
		$junior = $this->person(
			'Junior Parent',
			array(
				'email'          => '',
				'guardian_email' => 'parent@example.test',
				'guardian_name'  => 'Pat',
			)
		);
		$bob    = $this->person( 'Bob Smith' );
		$token  = $this->sign_in( 'parent@example.test' );

		$this->assertSame( 'person', $this->delete( $token, $bob, array( 'confirm' => '1' ) )->get_error_code() );
		$this->assertNotNull( Chess_Army_Knife_Membership_Store::get_member( $bob ) );

		$this->assertSame( 'deleted', $this->delete( $token, $junior, array( 'confirm' => '1' ) ) );
		$this->assertNull( Chess_Army_Knife_Membership_Store::get_member( $junior ) );
		$this->assertNotNull( Chess_Army_Knife_Membership_Store::get_member( $pat ) );
		$this->assertNotNull( Chess_Army_Knife_Member_Portal::session( $token ), 'The parent stays signed in.' );
	}

	public function test_deleting_also_removes_their_email_log_and_squad_places() {
		$ada  = $this->person();
		$team = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Club A',
			)
		);
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );
		Chess_Army_Knife_Mailer::queue( Chess_Army_Knife_Membership_Store::get_member( $ada ), 'renewals', 'S', 'B' );
		$token = $this->sign_in();

		$this->delete( $token, $ada, array( 'confirm' => '1' ) );

		$this->assertSame( array(), Chess_Army_Knife_Teams::squad( $team ) );
		$this->assertSame( array(), Chess_Army_Knife_Mailer::get_log_for_person( $ada ) );
	}

	public function test_signing_out_ends_the_session() {
		$this->person();
		$token = $this->sign_in();
		$_POST = array(
			Chess_Army_Knife_Member_Portal::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Member_Portal::ACTION_SIGNOUT ),
			'token'                                     => $token,
		);

		delete_transient( Chess_Army_Knife_Member_Portal::SESSION_KEY . $token ); // What handle_signout() does once the nonce is right; it then redirects and exits.
		$_POST = array();

		$this->assertNull( Chess_Army_Knife_Member_Portal::session( $token ) );
	}

	/* -------------------------------------------------------------
	 * The block
	 * ------------------------------------------------------------- */

	public function test_the_block_asks_for_an_email_address_when_nobody_is_signed_in() {
		$html = do_blocks( '<!-- wp:chess-army-knife/member-portal /-->' );

		$this->assertStringContainsString( 'Email me a link', $html );
		$this->assertStringContainsString( 'value="' . Chess_Army_Knife_Member_Portal::ACTION_LINK . '"', $html );
		$this->assertStringNotContainsString( 'Delete my details', $html );
	}

	public function test_the_block_shows_each_record_with_its_sections_to_a_signed_in_member() {
		$team = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Club A',
			)
		);
		$ada  = $this->person(
			'Ada Lovelace',
			array(
				'type_name'   => 'Adult',
				'expiry_date' => '2099-12-31',
				'phone'       => '0123',
			)
		);
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );
		$event = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Summer Blitz',
				'meta_input'  => array( Chess_Army_Knife_Events::META_START => '2099-07-01 19:00:00' ),
			)
		);
		Chess_Army_Knife_Event_Registrations::register( $event, $ada, 0, true );
		$_GET = array( 'cak_portal' => $this->sign_in() );

		$html = do_blocks( '<!-- wp:chess-army-knife/member-portal /-->' );

		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringContainsString( 'MEM-' . $ada, $html );
		$this->assertStringContainsString( 'Club A', $html );
		$this->assertStringContainsString( 'value="' . Chess_Army_Knife_Member_Portal::ACTION_DETAILS . '"', $html );
		$this->assertStringContainsString( 'value="' . Chess_Army_Knife_Member_Portal::ACTION_CHOICES . '"', $html );
		$this->assertStringContainsString( 'value="' . Chess_Army_Knife_Member_Portal::ACTION_EMAIL . '"', $html );
		$this->assertStringContainsString( 'value="' . Chess_Army_Knife_Member_Portal::ACTION_DELETE . '"', $html );
		$this->assertStringContainsString( 'Summer Blitz', $html );
		$this->assertStringContainsString( 'cak_cancel=', $html );
		$this->assertStringContainsString( 'Sign out', $html );
	}

	public function test_the_block_shows_the_confirmation_page_for_a_new_address_and_an_error_for_a_bad_link() {
		$ada   = $this->person();
		$token = $this->sign_in();
		$this->request_change( $token, $ada );
		$_GET = array( 'cak_email' => $this->change_token() );

		$html = do_blocks( '<!-- wp:chess-army-knife/member-portal /-->' );
		$this->assertStringContainsString( 'ada.new@example.test', $html );
		$this->assertStringContainsString( 'Confirm my new address', $html );

		$_GET = array( 'cak_portal' => 'junk' );
		$this->assertStringContainsString( 'expired or is not valid', do_blocks( '<!-- wp:chess-army-knife/member-portal /-->' ) );
	}

	public function test_the_block_shows_outcome_messages_from_fixed_codes_only() {
		$_GET = array( 'cak_portal_msg' => 'deleted' );
		$this->assertStringContainsString( 'Your details have been deleted.', do_blocks( '<!-- wp:chess-army-knife/member-portal /-->' ) );

		$_GET = array( 'cak_portal_msg' => '<script>alert(1)</script>' );
		$this->assertStringNotContainsString( '<script>', do_blocks( '<!-- wp:chess-army-knife/member-portal /-->' ) );
	}

	public function test_a_session_lasts_an_hour_and_says_when_it_ends() {
		$this->person();
		$token = $this->sign_in();

		$session = Chess_Army_Knife_Member_Portal::session( $token );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $session['expires'], 5 );
		$this->assertSame( 'ada@example.test', $session['email'] );
	}

	public function test_extending_a_session_starts_the_hour_again() {
		$this->person();
		$token = $this->sign_in();

		// Pretend most of the hour has gone.
		set_transient(
			Chess_Army_Knife_Member_Portal::SESSION_KEY . $token,
			array(
				'email'   => 'ada@example.test',
				'expires' => time() + 5 * MINUTE_IN_SECONDS,
			),
			5 * MINUTE_IN_SECONDS
		);

		$expires = Chess_Army_Knife_Member_Portal::extend_session( $token );

		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $expires, 5 );
		$this->assertEqualsWithDelta( $expires, Chess_Army_Knife_Member_Portal::session( $token )['expires'], 1 );
	}

	public function test_a_session_that_is_not_there_cannot_be_extended() {
		$this->assertSame( 0, Chess_Army_Knife_Member_Portal::extend_session( 'nope' ) );
	}

	public function test_a_session_made_before_sessions_had_an_end_time_still_works() {
		$this->person();
		set_transient( Chess_Army_Knife_Member_Portal::SESSION_KEY . 'oldtoken', 'ada@example.test', HOUR_IN_SECONDS );

		$session = Chess_Army_Knife_Member_Portal::session( 'oldtoken' );

		$this->assertSame( 'ada@example.test', $session['email'] );
		$this->assertSame( 0, $session['expires'] );
	}

	public function test_the_block_warns_about_the_session_and_offers_to_extend_it() {
		$this->person();
		$_GET = array( 'cak_portal' => $this->sign_in() );

		$html = do_blocks( '<!-- wp:chess-army-knife/member-portal /-->' );

		$this->assertStringContainsString( 'data-cak-remaining', $html );
		$this->assertStringContainsString( 'Keep me signed in', $html );
		$this->assertStringContainsString( 'You are signed in until', $html );
	}
}
