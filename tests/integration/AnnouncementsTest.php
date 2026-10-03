<?php
/**
 * Integration tests: announcements to an audience, scheduling, and the member archive.
 *
 * @package Chess_Army_Knife
 */

class AnnouncementsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Mailer::table(), Chess_Army_Knife_Notification_Preferences::table(), Chess_Army_Knife_Teams::squad_table(), Chess_Army_Knife_Selection::table( 'availability' ), Chess_Army_Knife_Selection::table( 'lineups' ) ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Member_History::install_table();
		Chess_Army_Knife_Mailer::install_table();
		Chess_Army_Knife_Notification_Preferences::install_table();
		Chess_Army_Knife_Teams::install_table();
		Chess_Army_Knife_Selection::install_tables();
		reset_phpmailer_instance();
		// Mail is sent at once here so the test can read it; a request sends it after the reply.
		add_filter( 'Chess_Army_Knife_send_mail_after_response', '__return_false' );
	}

	public function tear_down() {
		remove_filter( 'Chess_Army_Knife_send_mail_after_response', '__return_false' );
		$_POST = array();
		$_GET  = array();
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function person( $name, array $extra = array() ) {
		return Chess_Army_Knife_Membership_Store::save_member(
			$extra + array(
				'name'   => $name,
				'email'  => strtolower( strtok( $name, ' ' ) ) . '@example.test',
				'status' => 'active',
			)
		);
	}

	private function announcement( array $meta = array(), $content = "First paragraph.\n\nSecond <b>paragraph</b> & more." ) {
		return self::factory()->post->create(
			array(
				'post_type'    => Chess_Army_Knife_Announcements::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Club night moves',
				'post_content' => $content,
				'meta_input'   => $meta,
			)
		);
	}

	private function delivered() {
		Chess_Army_Knife_Mailer::run();
		$mails = tests_retrieve_phpmailer_instance()->mock_sent;
		reset_phpmailer_instance();
		return array_map(
			function ( $mail ) {
				return $mail['to'][0][0];
			},
			$mails
		);
	}

	public function test_an_announcement_goes_to_every_current_member_once() {
		$this->person( 'Ada Lovelace' );
		$this->person( 'Bob Smith' );
		$this->person( 'Lapsed Member', array( 'expiry_date' => '2000-01-01' ) );
		$this->person( 'A Guest', array( 'status' => 'nonmember' ) );
		$this->person( 'Pending One', array( 'status' => 'pending' ) );
		$id = $this->announcement();

		$result = Chess_Army_Knife_Announcements::send( $id );

		$this->assertSame( 2, $result['queued'] );
		$this->assertSame( array( 'ada@example.test', 'bob@example.test' ), $this->delivered() );
		$this->assertNull( Chess_Army_Knife_Announcements::send( $id ), 'It is sent once.' );
		$this->assertSame( array(), $this->delivered() );
	}

	public function test_the_email_is_plain_text_with_paragraphs_and_the_unsubscribe_footer() {
		$this->person( 'Ada Lovelace' );
		Chess_Army_Knife_Announcements::send( $this->announcement() );

		Chess_Army_Knife_Mailer::run();
		$mail = tests_retrieve_phpmailer_instance()->mock_sent[0];

		$this->assertSame( 'Club night moves', $mail['subject'] );
		$this->assertStringContainsString( "First paragraph.\n\nSecond paragraph & more.", str_replace( "\r\n", "\n", $mail['body'] ) );
		$this->assertStringNotContainsString( '<b>', $mail['body'] );
		$this->assertStringContainsString( 'cak_u=', $mail['body'] );
		$this->assertStringContainsString( 'Club announcements', $mail['body'] );
	}

	public function test_people_who_turned_announcements_off_or_have_no_address_are_skipped_and_counted() {
		$off = $this->person( 'Off Person' );
		Chess_Army_Knife_Notification_Preferences::opt_out( $off, 'announcements' );
		$this->person( 'No Address', array( 'email' => '' ) );
		$this->person( 'Ada Lovelace' );

		$result = Chess_Army_Knife_Announcements::send( $this->announcement() );

		$this->assertSame( 1, $result['queued'] );
		$this->assertSame( 2, $result['skipped'] );
		$this->assertSame( array( 'ada@example.test' ), $this->delivered() );
	}

	public function test_the_newsletter_goes_only_to_those_who_agreed_to_it() {
		$this->person( 'Ada Lovelace', array( 'newsletter_consent_at' => '2026-01-01 10:00:00' ) );
		$this->person( 'Bob Smith' );

		Chess_Army_Knife_Announcements::send( $this->announcement( array( Chess_Army_Knife_Announcements::META_CATEGORY => 'newsletter' ) ) );

		$this->assertSame( array( 'ada@example.test' ), $this->delivered() );
	}

	public function test_a_team_audience_is_the_squads_current_members() {
		$team = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Club A',
			)
		);
		$ada  = $this->person( 'Ada Lovelace' );
		$bob  = $this->person( 'Bob Smith' );
		$old  = $this->person( 'Lapsed Member', array( 'expiry_date' => '2000-01-01' ) );
		$this->person( 'Cy Jones' );
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada, $bob, $old ) );

		Chess_Army_Knife_Announcements::send(
			$this->announcement(
				array(
					Chess_Army_Knife_Announcements::META_AUDIENCE => 'teams',
					Chess_Army_Knife_Announcements::META_TEAMS    => array( $team ),
				)
			)
		);

		$this->assertSame( array( 'ada@example.test', 'bob@example.test' ), $this->delivered() );
	}

	public function test_a_junior_audience_reaches_the_parent_once_even_for_two_juniors() {
		$this->person( 'Adult Member' );
		$this->person(
			'Junior One',
			array(
				'email'          => '',
				'date_of_birth'  => '2015-05-05',
				'guardian_email' => 'parent@example.test',
				'guardian_name'  => 'Pat',
			)
		);
		$this->person(
			'Junior Two',
			array(
				'email'          => '',
				'date_of_birth'  => '2013-03-03',
				'guardian_email' => 'parent@example.test',
				'guardian_name'  => 'Pat',
			)
		);

		$result = Chess_Army_Knife_Announcements::send( $this->announcement( array( Chess_Army_Knife_Announcements::META_AUDIENCE => 'juniors' ) ) );

		$this->assertSame( 1, $result['queued'] );
		$this->assertSame( array( 'parent@example.test' ), $this->delivered() );
	}

	public function test_an_address_shared_by_several_members_gets_one_email() {
		$this->person( 'Pat Parent', array( 'email' => 'family@example.test' ) );
		$this->person( 'Sam Parent', array( 'email' => 'family@example.test' ) );

		$result = Chess_Army_Knife_Announcements::send( $this->announcement() );

		$this->assertSame( 1, $result['queued'] );
	}

	/* -------------------------------------------------------------
	 * Scheduling and the screen
	 * ------------------------------------------------------------- */

	private function officer() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );
	}

	private function save_box( $id, array $post ) {
		$_POST = $post + array( Chess_Army_Knife_Announcements_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Announcements_Admin::NONCE_ACTION ) );
		Chess_Army_Knife_Announcements_Admin::save( $id, get_post( $id ) );
		$_POST = array();
	}

	public function test_saving_with_send_ticked_sends_now_and_without_it_keeps_a_draft() {
		$this->officer();
		$this->person( 'Ada Lovelace' );
		$id = $this->announcement();

		$this->save_box( $id, array( 'chess_army_ann_audience' => 'members' ) );
		$this->assertSame( '', Chess_Army_Knife_Announcements::settings( $id )['state'] );
		$this->assertSame( array(), $this->delivered() );

		$this->save_box(
			$id,
			array(
				'chess_army_ann_audience' => 'members',
				'chess_army_ann_send'     => '1',
			)
		);
		$this->assertSame( 'sent', Chess_Army_Knife_Announcements::settings( $id )['state'] );
		$this->assertSame( array( 'ada@example.test' ), $this->delivered() );

		// Once sent, saving again changes nothing and sends nothing more.
		$this->save_box(
			$id,
			array(
				'chess_army_ann_audience' => 'juniors',
				'chess_army_ann_send'     => '1',
			)
		);
		$this->assertSame( 'members', Chess_Army_Knife_Announcements::settings( $id )['audience'] );
		$this->assertSame( array(), $this->delivered() );
	}

	public function test_a_later_time_is_scheduled_then_sent_by_the_hourly_job_when_due() {
		$this->officer();
		$this->person( 'Ada Lovelace' );
		$id = $this->announcement();

		$this->save_box(
			$id,
			array(
				'chess_army_ann_send' => '1',
				'chess_army_ann_date' => '2099-01-01',
				'chess_army_ann_time' => '10:00',
			)
		);

		$this->assertSame( 'scheduled', Chess_Army_Knife_Announcements::settings( $id )['state'] );
		$this->assertSame( 0, Chess_Army_Knife_Announcements::send_due(), 'Not due yet.' );

		update_post_meta( $id, Chess_Army_Knife_Announcements::META_SEND_AT, '2000-01-01 10:00:00' );
		$this->assertSame( 1, Chess_Army_Knife_Announcements::send_due() );
		$this->assertSame( array( 'ada@example.test' ), $this->delivered() );

		// Unticking cancels a schedule.
		$other = $this->announcement();
		$this->save_box(
			$other,
			array(
				'chess_army_ann_send' => '1',
				'chess_army_ann_date' => '2099-01-01',
			)
		);
		$this->save_box( $other, array() );
		$this->assertSame( '', Chess_Army_Knife_Announcements::settings( $other )['state'] );
	}

	public function test_only_someone_with_the_membership_permission_can_send() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->person( 'Ada Lovelace' );
		$id = $this->announcement();

		$this->save_box( $id, array( 'chess_army_ann_send' => '1' ) );

		$this->assertSame( '', Chess_Army_Knife_Announcements::settings( $id )['state'] );
		$this->assertSame( array(), $this->delivered() );
	}

	public function test_bad_choices_are_replaced_by_safe_ones() {
		$this->officer();
		$id = $this->announcement();

		$this->save_box(
			$id,
			array(
				'chess_army_ann_audience' => 'everyone',
				'chess_army_ann_category' => 'fixtures',
				'chess_army_ann_teams'    => array( '99999' ),
			)
		);

		$settings = Chess_Army_Knife_Announcements::settings( $id );
		$this->assertSame( 'members', $settings['audience'] );
		$this->assertSame( 'announcements', $settings['category'] );
		$this->assertSame( array(), $settings['teams'] );
	}

	public function test_the_screen_shows_the_send_box_and_the_whatsapp_list_for_a_team() {
		$this->officer();
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
				'phone'               => '0123',
				'whatsapp_consent_at' => '2026-01-01 10:00:00',
			)
		);
		$bob  = $this->person( 'Bob Smith', array( 'phone' => '0456' ) );
		$cat  = $this->person(
			'Cat Jones',
			array(
				'phone'               => '0789',
				'whatsapp_consent_at' => '2026-01-01 10:00:00',
			)
		);
		// Ada is in the squad and agreed; Bob is in it but has not agreed; Cat agreed but is not in it.
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada, $bob ) );
		$id = $this->announcement(
			array(
				Chess_Army_Knife_Announcements::META_AUDIENCE => 'teams',
				Chess_Army_Knife_Announcements::META_TEAMS => array( $team ),
			)
		);

		ob_start();
		Chess_Army_Knife_Announcements_Admin::render( get_post( $id ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Who gets it', $html );
		$this->assertStringContainsString( 'Right now that is 2 people', $html, 'Ada and Bob are in the squad; Cat is not.' );
		$this->assertStringContainsString( 'Lovelace, Ada — 0123', $html );
		$this->assertStringNotContainsString( 'Bob Smith', $html );
		$this->assertStringNotContainsString( 'Cat Jones', $html, 'Only squad members are listed for a team.' );
	}

	/* -------------------------------------------------------------
	 * The archive
	 * ------------------------------------------------------------- */

	public function test_the_member_portal_shows_sent_announcements_for_their_audience_only() {
		$ada = $this->person( 'Ada Lovelace' );
		$this->person(
			'Junior One',
			array(
				'email'          => '',
				'date_of_birth'  => '2015-05-05',
				'guardian_email' => 'parent@example.test',
				'guardian_name'  => 'Pat',
			)
		);
		$sent = $this->announcement( array(), 'Hall is closed on Monday.' );
		Chess_Army_Knife_Announcements::send( $sent );
		$draft   = $this->announcement( array(), 'Not sent yet.' );
		$juniors = $this->announcement( array( Chess_Army_Knife_Announcements::META_AUDIENCE => 'juniors' ), 'Junior coaching starts.' );
		Chess_Army_Knife_Announcements::send( $juniors );
		reset_phpmailer_instance();

		Chess_Army_Knife_Member_Portal::request_link(
			array(
				Chess_Army_Knife_Member_Portal::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Member_Portal::ACTION_LINK ),
				'email' => 'ada@example.test',
			)
		);
		preg_match( '/cak_portal=([A-Za-z0-9]+)/', tests_retrieve_phpmailer_instance()->mock_sent[0]['body'], $match );
		$_GET = array( 'cak_portal' => $match[1] );

		$html = do_blocks( '<!-- wp:chess-army-knife/member-portal /-->' );

		$this->assertStringContainsString( 'Club announcements', $html );
		$this->assertStringContainsString( 'Hall is closed on Monday.', $html );
		$this->assertStringNotContainsString( 'Not sent yet.', $html );
		$this->assertStringNotContainsString( 'Junior coaching starts.', $html, 'Ada is not a junior.' );
		$this->assertSame( array( $sent ), wp_list_pluck( Chess_Army_Knife_Announcements::for_person( Chess_Army_Knife_Membership_Store::get_member( $ada ) ), 'id' ) );
		unset( $draft );
	}

	public function test_the_portal_lets_a_member_switch_announcements_off() {
		$this->assertArrayHasKey( 'announcements', Chess_Army_Knife_Notification_Preferences::categories() );
	}
}
