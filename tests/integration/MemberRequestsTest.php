<?php
/**
 * Integration tests: consent on joining, and members managing their own data
 * (withdrawing consent, asking for a copy, asking for deletion) without an account.
 *
 * @package Chess_Army_Knife
 */

class MemberRequestsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Membership_Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Chess_Army_Knife_Membership_Store::install_table();
		reset_phpmailer_instance();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$this->clear_counters();
	}

	public function tear_down() {
		$this->clear_counters();
		$_GET = array();
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function clear_counters() {
		delete_transient( 'chess_army_knife_data_ip_' . md5( '203.0.113.77' ) );
		foreach ( array( 'ada@example.test', 'nobody@example.test', 'charles@example.test' ) as $email ) {
			delete_transient( 'chess_army_knife_data_email_' . md5( $email ) );
		}
		delete_transient( 'chess_army_knife_apply_' . md5( '203.0.113.77' ) );
	}

	private function member( array $extra = array() ) {
		return Chess_Army_Knife_Membership_Store::save_member(
			$extra + array(
				'name'   => 'Ada Lovelace',
				'email'  => 'ada@example.test',
				'status' => 'active',
			)
		);
	}

	private function emails() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	private function request( array $overrides = array() ) {
		return Chess_Army_Knife_Member_Requests::request(
			$overrides + array(
				Chess_Army_Knife_Member_Requests::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Member_Requests::ACTION_REQUEST ),
				'email'  => 'ada@example.test',
				'choice' => 'withdraw',
			),
			'http://example.org/my-data/'
		);
	}

	private function token_from_email() {
		$body = $this->emails()[0]['body'];
		$this->assertMatchesRegularExpression( '/cak_withdraw=([A-Za-z0-9]{32})/', $body );
		preg_match( '/cak_withdraw=([A-Za-z0-9]{32})/', $body, $found );
		return $found[1];
	}

	/* -------------------------------------------------------------
	 * Consent on joining
	 * ------------------------------------------------------------- */

	public function test_joining_through_the_form_records_all_three_agreements_from_the_one_tick() {
		$type = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Memberships::POST_TYPE,
				'post_title'  => 'Adult',
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

		$member = Chess_Army_Knife_Membership_Store::get_member( $id );
		$this->assertNotSame( '', $member['consent_at'] );
		$this->assertSame( $member['consent_at'], $member['newsletter_consent_at'] );
		$this->assertSame( $member['consent_at'], $member['whatsapp_consent_at'] );
	}

	public function test_the_form_states_what_the_tick_covers_and_has_no_separate_boxes() {
		self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Memberships::POST_TYPE,
				'post_title'  => 'Adult',
				'post_status' => 'publish',
			)
		);

		$html = do_blocks( '<!-- wp:chess-army-knife/membership-form /-->' );

		$this->assertStringContainsString( 'emailing me the club newsletter', $html );
		$this->assertStringContainsString( 'WhatsApp groups', $html );
		$this->assertStringContainsString( 'withdraw the newsletter and WhatsApp at any time', $html );
		$this->assertStringNotContainsString( 'name="newsletter"', $html );
		$this->assertStringNotContainsString( 'name="whatsapp"', $html );
	}

	public function test_a_member_added_by_the_club_starts_agreed_and_can_be_unticked() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );
		$_GET = array( 'edit' => 'new' );

		ob_start();
		Chess_Army_Knife_Members_Page::render_page();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="newsletter" value="1"\s+checked=\'checked\'/', $html );
		$this->assertMatchesRegularExpression( '/name="whatsapp" value="1"\s+checked=\'checked\'/', $html );
	}

	/* -------------------------------------------------------------
	 * Asking to change choices
	 * ------------------------------------------------------------- */

	public function test_a_known_address_is_emailed_a_private_link_to_change_its_choices() {
		$this->member();

		$this->assertTrue( $this->request() );

		$emails = $this->emails();
		$this->assertCount( 1, $emails );
		$this->assertSame( 'ada@example.test', $emails[0]['to'][0][0] );
		$this->assertStringContainsString( 'http://example.org/my-data/?cak_withdraw=', $emails[0]['body'] );
		$this->assertStringContainsString( '#' . Chess_Army_Knife_Member_Requests::ANCHOR, $emails[0]['body'] );
		$this->assertSame( 'ada@example.test', get_transient( Chess_Army_Knife_Member_Requests::TOKEN_KEY . $this->token_from_email() ) );
	}

	public function test_an_unknown_address_gets_the_same_answer_and_no_email() {
		$this->member();

		$this->assertTrue( $this->request( array( 'email' => 'nobody@example.test' ) ), 'The answer must not reveal whether an address is on file.' );

		$this->assertSame( array(), $this->emails() );
	}

	public function test_a_junior_can_be_managed_from_their_parents_address() {
		$this->member(
			array(
				'name'           => 'Junior',
				'email'          => '',
				'guardian_email' => 'charles@example.test',
			)
		);

		$this->request( array( 'email' => 'charles@example.test' ) );

		$this->assertCount( 1, $this->emails() );
		$found = Chess_Army_Knife_Member_Requests::people_for_token( $this->token_from_email() );
		$this->assertSame( array( 'Junior' ), wp_list_pluck( $found['people'], 'name' ) );
	}

	/**
	 * @dataProvider bad_requests
	 */
	public function test_bad_requests_are_refused_and_send_nothing( array $overrides, $code ) {
		$this->member();

		$result = $this->request( $overrides );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array(), $this->emails() );
	}

	public function bad_requests() {
		return array(
			'expired form'   => array( array( Chess_Army_Knife_Member_Requests::NONCE_FIELD => 'wrong' ), 'expired' ),
			'no email'       => array( array( 'email' => '' ), 'email' ),
			'invalid email'  => array( array( 'email' => 'not-an-email' ), 'email' ),
			'unknown choice' => array( array( 'choice' => 'sell-my-data' ), 'choice' ),
			'no choice'      => array( array( 'choice' => '' ), 'choice' ),
		);
	}

	public function test_a_filled_honeypot_does_nothing() {
		$this->member();

		$this->assertTrue( $this->request( array( Chess_Army_Knife_Member_Requests::HONEYPOT => 'http://spam.test' ) ) );

		$this->assertSame( array(), $this->emails() );
	}

	public function test_one_address_cannot_be_used_to_flood_someone_with_emails() {
		$this->member();

		for ( $i = 0; $i < 8; $i++ ) {
			$this->assertTrue( $this->request() );
			delete_transient( 'chess_army_knife_data_ip_' . md5( '203.0.113.77' ) ); // Different visitors, same target address.
		}

		$this->assertCount( Chess_Army_Knife_Member_Requests::MAX_PER_HOUR, $this->emails() );
	}

	public function test_one_visitor_is_limited_and_told_so() {
		for ( $i = 0; $i < Chess_Army_Knife_Member_Requests::MAX_PER_HOUR; $i++ ) {
			$this->assertTrue( $this->request( array( 'email' => "person{$i}@example.test" ) ) );
		}

		$result = $this->request( array( 'email' => 'ada@example.test' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'throttled', $result->get_error_code() );
	}

	/* -------------------------------------------------------------
	 * Copy of the data, and deletion: WordPress's own requests
	 * ------------------------------------------------------------- */

	/**
	 * @dataProvider wordpress_requests
	 */
	public function test_a_copy_or_deletion_becomes_a_wordpress_request_the_person_must_confirm( $choice, $action ) {
		$this->member();

		$this->assertTrue( $this->request( array( 'choice' => $choice ) ) );

		$requests = get_posts(
			array(
				'post_type'   => 'user_request',
				'post_status' => 'any',
				'title'       => 'ada@example.test',
				'post_name'   => $action,
			)
		);
		$this->assertCount( 1, $requests );
		$this->assertSame( 'request-pending', $requests[0]->post_status, 'Nothing happens until they confirm by email.' );
		$emails = $this->emails();
		$this->assertCount( 1, $emails );
		$this->assertSame( 'ada@example.test', $emails[0]['to'][0][0] );
		$this->assertStringContainsString( 'confirmaction', $emails[0]['body'] );
	}

	public function wordpress_requests() {
		return array(
			'copy of my data' => array( 'export', 'export_personal_data' ),
			'delete my data'  => array( 'erase', 'remove_personal_data' ),
		);
	}

	public function test_asking_twice_does_not_error_or_reveal_anything() {
		$this->member();

		$this->assertTrue( $this->request( array( 'choice' => 'erase' ) ) );
		$this->assertTrue( $this->request( array( 'choice' => 'erase' ) ) );
	}

	/* -------------------------------------------------------------
	 * The private link
	 * ------------------------------------------------------------- */

	private function withdraw( $token, array $ticked, array $overrides = array() ) {
		return Chess_Army_Knife_Member_Requests::withdraw(
			$overrides + array(
				Chess_Army_Knife_Member_Requests::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Member_Requests::ACTION_WITHDRAW ),
				'token' => $token,
			) + $ticked
		);
	}

	private function link_for( $email ) {
		$this->request( array( 'email' => $email ) );
		return $this->token_from_email();
	}

	public function test_unticking_withdraws_consent_and_ticking_keeps_the_original_time() {
		$id    = $this->member(
			array(
				'newsletter_consent_at' => '2026-01-01 09:00:00',
				'whatsapp_consent_at'   => '2026-01-01 09:00:00',
			)
		);
		$token = $this->link_for( 'ada@example.test' );

		$this->assertTrue( $this->withdraw( $token, array( 'newsletter' => array( $id => '1' ) ) ) );

		$member = Chess_Army_Knife_Membership_Store::get_member( $id );
		$this->assertSame( '2026-01-01 09:00:00', $member['newsletter_consent_at'], 'Still agreed: the original time stays.' );
		$this->assertSame( '', $member['whatsapp_consent_at'], 'Withdrawn.' );
		$this->assertSame( 'active', $member['status'], 'They stay a member.' );
	}

	public function test_they_can_give_consent_again_later() {
		$id    = $this->member();
		$token = $this->link_for( 'ada@example.test' );

		$this->withdraw( $token, array( 'whatsapp' => array( $id => '1' ) ) );

		$member = Chess_Army_Knife_Membership_Store::get_member( $id );
		$this->assertSame( '', $member['newsletter_consent_at'] );
		$this->assertNotSame( '', $member['whatsapp_consent_at'] );
	}

	public function test_a_link_only_changes_the_people_under_its_own_address() {
		$ada   = $this->member(
			array(
				'newsletter_consent_at' => '2026-01-01 09:00:00',
			)
		);
		$other = $this->member(
			array(
				'name'                  => 'Someone Else',
				'email'                 => 'other@example.test',
				'newsletter_consent_at' => '2026-01-01 09:00:00',
			)
		);
		$token = $this->link_for( 'ada@example.test' );

		// Even if the form is tampered with to include another person's id.
		$this->withdraw( $token, array() );
		$this->withdraw( $this->link_for_again( 'ada@example.test' ), array( 'newsletter' => array( $other => '1' ) ) );

		$this->assertSame( '', Chess_Army_Knife_Membership_Store::get_member( $ada )['newsletter_consent_at'] );
		$this->assertSame( '2026-01-01 09:00:00', Chess_Army_Knife_Membership_Store::get_member( $other )['newsletter_consent_at'], 'Someone else\'s choices are never touched.' );
	}

	private function link_for_again( $email ) {
		reset_phpmailer_instance();
		delete_transient( 'chess_army_knife_data_email_' . md5( $email ) );
		delete_transient( 'chess_army_knife_data_ip_' . md5( '203.0.113.77' ) );
		return $this->link_for( $email );
	}

	public function test_a_link_works_once_and_a_bad_or_missing_link_or_form_changes_nothing() {
		$id    = $this->member( array( 'newsletter_consent_at' => '2026-01-01 09:00:00' ) );
		$token = $this->link_for( 'ada@example.test' );

		$this->assertSame( 'expired', $this->withdraw( $token, array(), array( Chess_Army_Knife_Member_Requests::NONCE_FIELD => 'wrong' ) )->get_error_code() );
		$this->assertSame( 'link', $this->withdraw( 'not-a-real-token', array() )->get_error_code() );
		$this->assertSame( 'link', $this->withdraw( '', array() )->get_error_code() );
		$this->assertSame( '2026-01-01 09:00:00', Chess_Army_Knife_Membership_Store::get_member( $id )['newsletter_consent_at'] );

		$this->assertTrue( $this->withdraw( $token, array() ) );
		$this->assertSame( '', Chess_Army_Knife_Membership_Store::get_member( $id )['newsletter_consent_at'] );
		$this->assertSame( 'link', $this->withdraw( $token, array() )->get_error_code(), 'The link is used up.' );
	}

	/* -------------------------------------------------------------
	 * The block
	 * ------------------------------------------------------------- */

	public function test_the_block_is_registered_and_asks_what_to_do() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'chess-army-knife/my-data' ) );

		$html = do_blocks( '<!-- wp:chess-army-knife/my-data /-->' );

		$this->assertStringContainsString( 'name="email"', $html );
		foreach ( Chess_Army_Knife_Member_Requests::CHOICES as $choice ) {
			$this->assertStringContainsString( 'value="' . $choice . '"', $html );
		}
		$this->assertStringContainsString( 'name="' . Chess_Army_Knife_Member_Requests::NONCE_FIELD . '"', $html );
		$this->assertStringContainsString( 'name="' . Chess_Army_Knife_Member_Requests::HONEYPOT . '"', $html );
		$this->assertStringContainsString( 'Manage my data', $html );
	}

	public function test_the_block_tells_everyone_to_check_their_email_without_saying_whether_they_are_on_file() {
		$_GET = array( 'cak_data_sent' => '1' );

		$html = do_blocks( '<!-- wp:chess-army-knife/my-data /-->' );

		$this->assertStringContainsString( 'If the club holds details under that email address', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_the_block_shows_the_persons_choices_on_a_valid_link_and_nothing_on_a_bad_one() {
		$this->member( array( 'newsletter_consent_at' => '2026-01-01 09:00:00' ) );
		$token = $this->link_for( 'ada@example.test' );

		$_GET = array( 'cak_withdraw' => $token );
		$html = do_blocks( '<!-- wp:chess-army-knife/my-data /-->' );
		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertMatchesRegularExpression( '/name="newsletter\[\d+\]" value="1"\s+checked=\'checked\'/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="whatsapp\[\d+\]" value="1"\s+checked/', $html );

		$_GET = array( 'cak_withdraw' => 'bogus' );
		$html = do_blocks( '<!-- wp:chess-army-knife/my-data /-->' );
		$this->assertStringNotContainsString( 'Ada Lovelace', $html );
		$this->assertStringContainsString( 'That link has expired', $html );
	}

	public function test_the_block_confirms_saved_choices_and_never_reflects_arbitrary_text() {
		$_GET = array( 'cak_data_done' => '1' );
		$this->assertStringContainsString( 'Your choices have been saved', do_blocks( '<!-- wp:chess-army-knife/my-data /-->' ) );

		$_GET = array( 'cak_data_error' => '<script>alert(1)</script>' );
		$html = do_blocks( '<!-- wp:chess-army-knife/my-data /-->' );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'Something went wrong', $html );
	}

	public function test_the_policy_tells_people_how_to_withdraw() {
		$text = '';
		foreach ( Chess_Army_Knife_Membership_Privacy::policy_sections() as $section ) {
			$text .= implode( ' ', $section['paragraphs'] );
		}

		$this->assertStringContainsString( 'By joining the club you agree', $text );
		$this->assertStringContainsString( 'Manage My Data page', $text );
	}
}
