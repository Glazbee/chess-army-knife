<?php
/**
 * Integration tests: registering for club events.
 *
 * @package Chess_Army_Knife
 */

class EventRegistrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Mailer::table(), Chess_Army_Knife_Notification_Preferences::table(), Chess_Army_Knife_Teams::squad_table(), Chess_Army_Knife_Event_Registrations::table() ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Mailer::install_table();
		Chess_Army_Knife_Notification_Preferences::install_table();
		Chess_Army_Knife_Teams::install_table();
		Chess_Army_Knife_Event_Registrations::install_table();
		reset_phpmailer_instance();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.88';
		$this->clear_counters();
	}

	public function tear_down() {
		$this->clear_counters();
		$_GET  = array();
		$_POST = array();
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function clear_counters() {
		delete_transient( 'chess_army_knife_data_ip_' . md5( '203.0.113.88' ) );
		foreach ( array( 'ada@example.test', 'new@example.test', 'parent@example.test' ) as $email ) {
			delete_transient( 'chess_army_knife_reg_email_' . md5( $email ) );
		}
	}

	private function event( array $meta = array() ) {
		return self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Summer Blitz',
				'meta_input'  => $meta + array(
					Chess_Army_Knife_Events::META_START => '2099-07-01 19:00:00',
					Chess_Army_Knife_Event_Registrations::META_ENABLED => 1,
					Chess_Army_Knife_Event_Registrations::META_MAX_GUESTS => 3,
				),
			)
		);
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

	private function request( $event, array $extra = array() ) {
		return Chess_Army_Knife_Event_Registration_Form::request(
			$extra + array(
				Chess_Army_Knife_Event_Registration_Form::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Event_Registration_Form::ACTION_REQUEST ),
				'event_id' => (string) $event,
				'name'     => 'Ada Lovelace',
				'email'    => 'ada@example.test',
				'guests'   => '0',
				'consent'  => '1',
			)
		);
	}

	private function token_from_email() {
		$this->assertNotEmpty( $this->sent(), 'A confirmation email was sent.' );
		preg_match( '/cak_reg=([A-Za-z0-9]+)/', $this->sent()[0]['body'], $match );
		reset_phpmailer_instance();
		return $match[1];
	}

	private function confirm( $token, array $extra = array() ) {
		return Chess_Army_Knife_Event_Registration_Form::confirm(
			$extra + array(
				Chess_Army_Knife_Event_Registration_Form::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Event_Registration_Form::ACTION_CONFIRM ),
				'token' => $token,
			)
		);
	}

	/* -------------------------------------------------------------
	 * Places
	 * ------------------------------------------------------------- */

	public function test_capacity_counts_guests_and_a_full_event_uses_a_waiting_list() {
		$event = $this->event( array( Chess_Army_Knife_Event_Registrations::META_CAPACITY => 4 ) );
		$a     = $this->person( 'Ada Lovelace' );
		$b     = $this->person( 'Bob Smith' );
		$c     = $this->person( 'Cy Jones' );

		$this->assertSame( 'registered', Chess_Army_Knife_Event_Registrations::register( $event, $a, 2 ) ); // Three places.
		$this->assertSame( 1, Chess_Army_Knife_Event_Registrations::places_left( $event ) );
		$this->assertSame( 'waiting', Chess_Army_Knife_Event_Registrations::register( $event, $b, 1 ), 'Two places do not fit in one.' );
		$this->assertSame( 'registered', Chess_Army_Knife_Event_Registrations::register( $event, $c, 0 ) );
		$this->assertSame( 0, Chess_Army_Knife_Event_Registrations::places_left( $event ) );
	}

	public function test_guests_are_limited_per_person() {
		$event = $this->event();
		$a     = $this->person();

		Chess_Army_Knife_Event_Registrations::register( $event, $a, 99 );

		$this->assertSame( 3, Chess_Army_Knife_Event_Registrations::get( $event, $a )['guests'] );
	}

	public function test_registering_twice_changes_the_guests_instead_of_duplicating() {
		$event = $this->event();
		$a     = $this->person();

		Chess_Army_Knife_Event_Registrations::register( $event, $a, 1 );
		Chess_Army_Knife_Event_Registrations::register( $event, $a, 2 );

		$this->assertCount( 1, Chess_Army_Knife_Event_Registrations::for_event( $event ) );
		$this->assertSame( 2, Chess_Army_Knife_Event_Registrations::get( $event, $a )['guests'] );
	}

	public function test_cancelling_frees_the_place_for_the_first_waiting_party_that_fits() {
		$event = $this->event( array( Chess_Army_Knife_Event_Registrations::META_CAPACITY => 2 ) );
		$a     = $this->person( 'Ada Lovelace' );
		$b     = $this->person( 'Bob Smith' );
		$c     = $this->person( 'Cy Jones' );
		$d     = $this->person( 'Di Brown' );
		Chess_Army_Knife_Event_Registrations::register( $event, $a, 0 );
		Chess_Army_Knife_Event_Registrations::register( $event, $b, 0 );
		Chess_Army_Knife_Event_Registrations::register( $event, $c, 1 ); // Waiting: needs two.
		Chess_Army_Knife_Event_Registrations::register( $event, $d, 0 ); // Waiting: needs one.

		$promoted = Chess_Army_Knife_Event_Registrations::cancel( Chess_Army_Knife_Event_Registrations::get( $event, $a )['id'] );

		$this->assertSame( array( $d ), $promoted, 'A smaller party is moved up past one that does not fit.' );
		$this->assertNull( Chess_Army_Knife_Event_Registrations::get( $event, $a ), 'A cancelled registration is deleted.' );
	}

	public function test_registration_closes_at_the_closing_time_or_the_start() {
		$event = $this->event( array( Chess_Army_Knife_Event_Registrations::META_CLOSES => '2099-06-30 12:00:00' ) );

		$this->assertTrue( Chess_Army_Knife_Event_Registrations::is_open( $event, '2099-06-30 11:59:59' ) );
		$this->assertFalse( Chess_Army_Knife_Event_Registrations::is_open( $event, '2099-06-30 12:00:00' ) );

		$no_close = $this->event();
		$this->assertTrue( Chess_Army_Knife_Event_Registrations::is_open( $no_close, '2099-07-01 18:59:59' ) );
		$this->assertFalse( Chess_Army_Knife_Event_Registrations::is_open( $no_close, '2099-07-01 19:00:00' ) );

		$off = $this->event( array( Chess_Army_Knife_Event_Registrations::META_ENABLED => 0 ) );
		$this->assertFalse( Chess_Army_Knife_Event_Registrations::is_open( $off ) );
		$this->assertInstanceOf( 'WP_Error', Chess_Army_Knife_Event_Registrations::register( $off, $this->person() ) );
	}

	/* -------------------------------------------------------------
	 * The public flow
	 * ------------------------------------------------------------- */

	public function test_the_visitor_is_emailed_a_link_and_nothing_is_registered_until_it_is_confirmed() {
		$event = $this->event();
		$this->person();

		$this->assertTrue( $this->request( $event ) );

		$this->assertSame( 'ada@example.test', $this->sent()[0]['to'][0][0] );
		$this->assertStringContainsString( 'cak_reg=', $this->sent()[0]['body'] );
		$this->assertSame( array(), Chess_Army_Knife_Event_Registrations::for_event( $event ) );
	}

	public function test_the_answer_is_the_same_for_an_unknown_address() {
		$event = $this->event();

		$this->assertTrue( $this->request( $event, array( 'email' => 'new@example.test' ) ) );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_a_bad_form_is_refused_or_ignored() {
		$event = $this->event();

		$this->assertSame( 'expired', $this->request( $event, array( Chess_Army_Knife_Event_Registration_Form::NONCE_FIELD => 'nope' ) )->get_error_code() );
		$this->assertSame( 'details', $this->request( $event, array( 'email' => 'not an email' ) )->get_error_code() );
		$this->assertSame( 'details', $this->request( $event, array( 'name' => ' ' ) )->get_error_code() );
		$this->assertSame( 'consent', $this->request( $event, array( 'consent' => '' ) )->get_error_code() );
		$this->assertSame( 'closed', $this->request( 999999 )->get_error_code() );
		$this->assertTrue( $this->request( $event, array( Chess_Army_Knife_Event_Registration_Form::HONEYPOT => 'http://spam' ) ), 'A bot is told nothing.' );
		$this->assertSame( array(), $this->sent() );
	}

	public function test_one_visitor_cannot_send_a_flood_of_emails() {
		$event = $this->event();

		for ( $i = 0; $i < Chess_Army_Knife_Member_Requests::MAX_PER_HOUR; $i++ ) {
			$this->assertTrue( $this->request( $event, array( 'email' => 'ada' . $i . '@example.test' ) ) );
		}

		$this->assertSame( 'throttled', $this->request( $event )->get_error_code() );
	}

	public function test_confirming_registers_a_member_and_sends_a_confirmation_with_a_cancel_link() {
		$event = $this->event();
		$ada   = $this->person();
		$this->request( $event, array( 'guests' => '2' ) );
		$token = $this->token_from_email();

		$pending = Chess_Army_Knife_Event_Registration_Form::pending( $token );
		$this->assertSame( array( $ada ), wp_list_pluck( $pending['people'], 'id' ) );

		$this->assertSame(
			'registered',
			$this->confirm(
				$token,
				array(
					'people' => array( $ada => '1' ),
					'guests' => array( $ada => '2' ),
				)
			)
		);
		$this->assertSame( 2, Chess_Army_Knife_Event_Registrations::get( $event, $ada )['guests'] );
		$this->assertNull( Chess_Army_Knife_Event_Registration_Form::pending( $token ), 'The link works once.' );

		Chess_Army_Knife_Mailer::run();
		$body = $this->sent()[0]['body'];
		$this->assertStringContainsString( 'You are registered', $body );
		$this->assertStringContainsString( 'Summer Blitz', $body );
		$this->assertStringContainsString( 'cak_cancel=', $body );
	}

	public function test_an_unknown_address_becomes_a_guest_record_not_a_member() {
		$event = $this->event();
		$this->request(
			$event,
			array(
				'email' => 'new@example.test',
				'name'  => 'Nia New',
			)
		);
		$token = $this->token_from_email();

		$this->assertSame( 'registered', $this->confirm( $token ) );

		$people = Chess_Army_Knife_Membership_Store::get_members_by_email( 'new@example.test' );
		$this->assertCount( 1, $people );
		$this->assertSame( 'nonmember', $people[0]['status'] );
		$this->assertSame( 'Nia New', $people[0]['name'] );
		$this->assertNotSame( '', $people[0]['consent_at'] );
		$this->assertNotNull( Chess_Army_Knife_Event_Registrations::get( $event, $people[0]['id'] ) );
	}

	public function test_a_parent_can_register_a_junior_and_chooses_who_is_coming() {
		$event  = $this->event();
		$parent = $this->person( 'Pat Parent', array( 'email' => 'parent@example.test' ) );
		$junior = $this->person(
			'Junior Parent',
			array(
				'email'          => '',
				'guardian_email' => 'parent@example.test',
			)
		);
		$this->request(
			$event,
			array(
				'email' => 'parent@example.test',
				'name'  => 'Pat Parent',
			)
		);
		$token = $this->token_from_email();

		$this->assertCount( 2, Chess_Army_Knife_Event_Registration_Form::pending( $token )['people'] );
		$this->assertSame( 'nopeople', $this->confirm( $token )->get_error_code() );

		$this->confirm( $token, array( 'people' => array( $junior => '1' ) ) );

		$this->assertNotNull( Chess_Army_Knife_Event_Registrations::get( $event, $junior ) );
		$this->assertNull( Chess_Army_Knife_Event_Registrations::get( $event, $parent ) );
	}

	public function test_someone_not_ticked_or_not_on_the_address_is_not_registered() {
		$event = $this->event();
		$ada   = $this->person();
		$bob   = $this->person( 'Bob Smith' );
		$this->request( $event );
		$token = $this->token_from_email();

		$this->confirm( $token, array( 'people' => array( $bob => '1' ) ) );

		$this->assertSame( array(), Chess_Army_Knife_Event_Registrations::for_event( $event ), 'Bob is not under this address.' );
		$this->assertNull( Chess_Army_Knife_Event_Registrations::get( $event, $ada ) );
	}

	public function test_a_full_event_tells_the_visitor_they_are_waiting() {
		$event = $this->event( array( Chess_Army_Knife_Event_Registrations::META_CAPACITY => 1 ) );
		$bob   = $this->person( 'Bob Smith' );
		Chess_Army_Knife_Event_Registrations::register( $event, $bob );
		$ada = $this->person();
		$this->request( $event );

		$this->assertSame( 'waiting', $this->confirm( $this->token_from_email(), array( 'people' => array( $ada => '1' ) ) ) );
		$this->assertSame( 'waiting', Chess_Army_Knife_Event_Registrations::get( $event, $ada )['status'] );
	}

	public function test_confirming_needs_a_valid_form_and_link() {
		$event = $this->event();
		$this->person();
		$this->request( $event );
		$token = $this->token_from_email();

		$this->assertSame( 'expired', $this->confirm( $token, array( Chess_Army_Knife_Event_Registration_Form::NONCE_FIELD => 'nope' ) )->get_error_code() );
		$this->assertSame( 'link', $this->confirm( 'notatoken' )->get_error_code() );
	}

	public function test_a_registration_can_be_cancelled_from_its_signed_link_and_the_waiting_list_moves_up() {
		$event = $this->event( array( Chess_Army_Knife_Event_Registrations::META_CAPACITY => 1 ) );
		$ada   = $this->person();
		$bob   = $this->person( 'Bob Smith' );
		Chess_Army_Knife_Event_Registrations::register( $event, $ada );
		Chess_Army_Knife_Event_Registrations::register( $event, $bob );
		$registration = Chess_Army_Knife_Event_Registrations::get( $event, $ada );
		parse_str( (string) wp_parse_url( Chess_Army_Knife_Event_Registration_Form::cancel_url( $registration ), PHP_URL_QUERY ), $query );
		$value = $query['cak_cancel'];

		$this->assertNull( Chess_Army_Knife_Event_Registration_Form::registration_for_link( str_replace( $registration['id'] . '.', '99.', $value ) ) );
		$this->assertNull( Chess_Army_Knife_Event_Registration_Form::registration_for_link( 'junk' ) );
		$this->assertSame( 'expired', Chess_Army_Knife_Event_Registration_Form::cancel( array( 'cancel' => $value ) )->get_error_code() );

		$this->assertTrue(
			Chess_Army_Knife_Event_Registration_Form::cancel(
				array(
					Chess_Army_Knife_Event_Registration_Form::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Event_Registration_Form::ACTION_CANCEL ),
					'cancel' => $value,
				)
			)
		);

		$this->assertNull( Chess_Army_Knife_Event_Registrations::get( $event, $ada ) );
		$this->assertSame( 'registered', Chess_Army_Knife_Event_Registrations::get( $event, $bob )['status'] );
		Chess_Army_Knife_Mailer::run();
		$this->assertStringContainsString( 'A place is free', $this->sent()[0]['subject'] );
		$this->assertSame( 'bob@example.test', $this->sent()[0]['to'][0][0] );
	}

	public function test_someone_who_turned_off_event_emails_is_still_registered_but_not_emailed() {
		$event = $this->event();
		$ada   = $this->person();
		Chess_Army_Knife_Notification_Preferences::opt_out( $ada, Chess_Army_Knife_Event_Registration_Form::CATEGORY );
		$this->request( $event );

		$this->confirm( $this->token_from_email(), array( 'people' => array( $ada => '1' ) ) );
		Chess_Army_Knife_Mailer::run();

		$this->assertNotNull( Chess_Army_Knife_Event_Registrations::get( $event, $ada ) );
		$this->assertSame( array(), $this->sent() );
	}

	/* -------------------------------------------------------------
	 * The block
	 * ------------------------------------------------------------- */

	public function test_the_block_shows_the_form_places_left_and_closed_states() {
		$event = $this->event( array( Chess_Army_Knife_Event_Registrations::META_CAPACITY => 5 ) );
		$this->go_to( get_permalink( $event ) );

		$html = do_blocks( '<!-- wp:chess-army-knife/event-registration /-->' );

		$this->assertStringContainsString( 'name="email"', $html );
		$this->assertStringContainsString( '5 places left', $html );
		$this->assertStringContainsString( 'name="guests"', $html );

		$closed = $this->event( array( Chess_Army_Knife_Event_Registrations::META_CLOSES => '2000-01-01 00:00:00' ) );
		$this->assertStringContainsString( 'Registration for this event is closed.', do_blocks( '<!-- wp:chess-army-knife/event-registration {"eventId":' . $closed . '} /-->' ) );

		$off = $this->event( array( Chess_Army_Knife_Event_Registrations::META_ENABLED => 0 ) );
		$this->assertSame( '', trim( do_blocks( '<!-- wp:chess-army-knife/event-registration {"eventId":' . $off . '} /-->' ) ) );
	}

	public function test_the_block_shows_the_confirmation_page_for_a_link() {
		$event = $this->event();
		$ada   = $this->person();
		$this->request( $event );
		$_GET = array( 'cak_reg' => $this->token_from_email() );

		$html = do_blocks( '<!-- wp:chess-army-knife/event-registration {"eventId":' . $event . '} /-->' );

		$this->assertStringContainsString( 'Who is coming?', $html );
		$this->assertStringContainsString( 'name="people[' . $ada . ']"', $html );
		$this->assertStringContainsString( 'value="' . Chess_Army_Knife_Event_Registration_Form::ACTION_CONFIRM . '"', $html );
	}

	/* -------------------------------------------------------------
	 * Officers
	 * ------------------------------------------------------------- */

	private function officer( $with_permission = true ) {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( $with_permission ) {
			get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		}
		wp_set_current_user( $user );
	}

	private function save_box( $event, array $post ) {
		$_POST = $post + array( Chess_Army_Knife_Event_Registrations_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Event_Registrations_Admin::NONCE_ACTION ) );
		Chess_Army_Knife_Event_Registrations_Admin::save( $event );
		$_POST = array();
	}

	public function test_an_officer_sets_the_rules_takes_attendance_and_adds_and_removes_people() {
		$this->officer();
		$event = $this->event( array( Chess_Army_Knife_Event_Registrations::META_ENABLED => 0 ) );
		$ada   = $this->person();
		$bob   = $this->person( 'Bob Smith' );
		Chess_Army_Knife_Event_Registrations::register( $event, $ada, 0, true );

		$this->save_box(
			$event,
			array(
				'chess_army_reg_enabled'     => '1',
				'chess_army_reg_capacity'    => '1',
				'chess_army_reg_max_guests'  => '2',
				'chess_army_reg_closes_date' => '2099-06-30',
				'chess_army_reg_closes_time' => '12:00',
				'chess_army_reg_attended'    => array( (string) $ada ),
				'chess_army_reg_add'         => (string) $bob,
			)
		);

		$settings = Chess_Army_Knife_Event_Registrations::settings( $event );
		$this->assertTrue( $settings['enabled'] );
		$this->assertSame( 1, $settings['capacity'] );
		$this->assertSame( 2, $settings['max_guests'] );
		$this->assertSame( '2099-06-30 12:00:00', $settings['closes'] );
		$this->assertTrue( Chess_Army_Knife_Event_Registrations::get( $event, $ada )['attended'] );
		$this->assertFalse( Chess_Army_Knife_Event_Registrations::get( $event, $bob )['attended'] );
		$this->assertSame( 'registered', Chess_Army_Knife_Event_Registrations::get( $event, $bob )['status'], 'An officer can add someone past the capacity.' );

		$this->save_box( $event, array( 'chess_army_reg_remove' => array( (string) Chess_Army_Knife_Event_Registrations::get( $event, $bob )['id'] ) ) );
		$this->assertNull( Chess_Army_Knife_Event_Registrations::get( $event, $bob ) );
	}

	public function test_without_the_membership_permission_only_the_settings_can_be_changed() {
		$this->officer( false );
		$event = $this->event();
		$ada   = $this->person();
		Chess_Army_Knife_Event_Registrations::register( $event, $ada, 0, true );

		$this->save_box(
			$event,
			array(
				'chess_army_reg_enabled'  => '1',
				'chess_army_reg_capacity' => '9',
				'chess_army_reg_remove'   => array( (string) Chess_Army_Knife_Event_Registrations::get( $event, $ada )['id'] ),
				'chess_army_reg_add'      => (string) $this->person( 'Bob Smith' ),
			)
		);

		$this->assertSame( 9, Chess_Army_Knife_Event_Registrations::settings( $event )['capacity'] );
		$this->assertCount( 1, Chess_Army_Knife_Event_Registrations::for_event( $event ), 'Nobody was removed or added.' );

		ob_start();
		Chess_Army_Knife_Event_Registrations_Admin::render( get_post( $event ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'only visible to people who can manage members', $html );
		$this->assertStringNotContainsString( 'Ada Lovelace', $html );
	}

	/* -------------------------------------------------------------
	 * Privacy
	 * ------------------------------------------------------------- */

	public function test_registrations_are_exported_and_a_record_with_them_is_kept_anonymised_when_erased() {
		$event = $this->event();
		$ada   = $this->person();
		Chess_Army_Knife_Event_Registrations::register( $event, $ada, 1 );
		Chess_Army_Knife_Event_Registrations::set_attendance( $event, array( $ada ) );

		$export = Chess_Army_Knife_Membership_Privacy::export( 'ada@example.test' );
		$this->assertContains( 'chess-army-knife-registrations', wp_list_pluck( $export['data'], 'group_id' ) );
		$this->assertStringContainsString( 'Summer Blitz', wp_json_encode( $export['data'] ) );

		$result = Chess_Army_Knife_Membership_Privacy::erase( 'ada@example.test' );

		$this->assertTrue( $result['items_retained'] );
		$kept = Chess_Army_Knife_Membership_Store::get_member( $ada );
		$this->assertSame( '', $kept['email'] );
		$this->assertSame( Chess_Army_Knife_Membership_Store::erased_name(), $kept['name'] );
		$this->assertSame( 1, Chess_Army_Knife_Event_Registrations::get( $event, $ada )['guests'], 'The counts stay.' );
	}

	public function test_a_record_without_registrations_is_deleted_when_erased() {
		$ada = $this->person();

		Chess_Army_Knife_Membership_Privacy::erase( 'ada@example.test' );

		$this->assertNull( Chess_Army_Knife_Membership_Store::get_member( $ada ) );
	}

	public function test_deleting_an_event_deletes_its_registrations() {
		$event = $this->event();
		Chess_Army_Knife_Event_Registrations::register( $event, $this->person() );

		wp_delete_post( $event, true );

		$this->assertSame( array(), Chess_Army_Knife_Event_Registrations::for_event( $event ) );
	}

	public function test_old_registrations_are_pruned_after_the_retention_period() {
		global $wpdb;
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'member_retention_months' => 24,
			)
		);
		$event = $this->event();
		$old   = $this->person( 'Old Timer' );
		$new   = $this->person( 'New Comer' );
		Chess_Army_Knife_Event_Registrations::register( $event, $old );
		Chess_Army_Knife_Event_Registrations::register( $event, $new );
		$wpdb->update( Chess_Army_Knife_Event_Registrations::table(), array( 'registered_at' => '2000-01-01 00:00:00' ), array( 'person_id' => $old ) );

		$this->assertSame( 1, Chess_Army_Knife_Event_Registrations::prune() );
		$this->assertNull( Chess_Army_Knife_Event_Registrations::get( $event, $old ) );
		$this->assertNotNull( Chess_Army_Knife_Event_Registrations::get( $event, $new ) );
	}
}
