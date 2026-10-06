<?php
/**
 * Integration tests: renewal reminders and renewing a membership.
 *
 * @package Chess_Army_Knife
 */

class RenewalRemindersTest extends WP_UnitTestCase {

	const TODAY = '2026-10-01';

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'           => 0,
				'renewal_reminders_enabled' => 1,
				'membership_payment_info'   => 'Pay at the club.',
			)
		);
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Mailer::table(), Chess_Army_Knife_Notification_Preferences::table() ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Membership_Seasons::install_tables();
		Chess_Army_Knife_Mailer::install_table();
		Chess_Army_Knife_Notification_Preferences::install_table();
		reset_phpmailer_instance();
	}

	private function member( $expiry, array $extra = array() ) {
		return Chess_Army_Knife_Membership_Store::save_member(
			$extra + array(
				'name'        => 'Ada Lovelace',
				'email'       => 'ada@example.test',
				'status'      => 'active',
				'type_name'   => 'Adult',
				'expiry_date' => $expiry,
			)
		);
	}

	private function run_on( $today ) {
		$queued = 0;
		foreach ( Chess_Army_Knife_Renewal_Reminders::due( $today ) as $item ) {
			$message = Chess_Army_Knife_Renewal_Reminders::compose( $item['member'], $item['days_left'] );
			$queued += Chess_Army_Knife_Mailer::queue( $item['member'], 'renewals', $message['subject'], $message['body'] ) ? 1 : 0;
			Chess_Army_Knife_Membership_Store::save_member(
				array(
					'id'               => $item['member']['id'],
					'renewal_reminder' => $item['member']['expiry_date'] . '|' . $item['stage'],
				)
			);
		}
		return $queued;
	}

	public function test_a_member_is_emailed_once_per_stage() {
		$this->member( '2026-10-31' ); // 30 days left.

		$this->assertCount( 1, Chess_Army_Knife_Renewal_Reminders::due( self::TODAY ) );
		$this->assertSame( 1, $this->run_on( self::TODAY ) );
		$this->assertSame( 0, $this->run_on( '2026-10-02' ) ); // The next day: same stage, already sent.
		$this->assertSame( 1, $this->run_on( '2026-10-24' ) ); // 7 days left.
		$this->assertSame( 0, $this->run_on( '2026-10-25' ) );
		$this->assertSame( 1, $this->run_on( '2026-10-31' ) ); // The last day.
		$this->assertSame( 1, $this->run_on( '2026-11-07' ) ); // A week after.
		$this->assertSame( 0, $this->run_on( '2026-11-08' ) );
	}

	public function test_nobody_outside_the_window_or_not_current_is_due() {
		$this->member( '2026-12-31' ); // Too far ahead.
		$this->member( '2026-06-01' ); // Lapsed months ago.
		$this->member( '2026-10-05', array( 'status' => 'cancelled' ) );
		$this->member( '2026-10-05', array( 'status' => 'nonmember' ) );
		$this->member( '', array( 'name' => 'No expiry' ) );

		$this->assertSame( array(), Chess_Army_Knife_Renewal_Reminders::due( self::TODAY ) );
	}

	public function test_renewing_starts_a_fresh_cycle() {
		$id = $this->member( '2026-10-05' );
		$this->run_on( self::TODAY );

		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'id'          => $id,
				'expiry_date' => '2027-10-05',
			)
		);
		$this->assertSame( array(), Chess_Army_Knife_Renewal_Reminders::due( self::TODAY ) );
		$this->assertCount( 1, Chess_Army_Knife_Renewal_Reminders::due( '2027-09-10' ) );
	}

	public function test_the_email_carries_the_payment_instructions_and_reference() {
		$id = $this->member( '2026-10-08' );

		$mail = Chess_Army_Knife_Renewal_Reminders::compose( Chess_Army_Knife_Membership_Store::get_member( $id ), 7 );

		$this->assertStringContainsString( 'Ada Lovelace', $mail['subject'] );
		$this->assertStringContainsString( 'Adult membership for Ada Lovelace runs out on', $mail['body'] );
		$this->assertStringContainsString( 'in 7 days', $mail['body'] );
		$this->assertStringContainsString( 'Pay at the club.', $mail['body'] );
		$this->assertStringContainsString( 'MEM-' . $id, $mail['body'] );
	}

	public function test_the_clubs_own_wording_is_used_with_placeholders() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'          => 0,
				'renewal_reminder_subject' => 'Renew {name}!',
				'renewal_reminder_message' => 'Ref {reference}, ends {expiry}.',
			)
		);
		$id   = $this->member( '2026-10-08' );
		$mail = Chess_Army_Knife_Renewal_Reminders::compose( Chess_Army_Knife_Membership_Store::get_member( $id ), 7 );

		$this->assertSame( 'Renew Ada Lovelace!', $mail['subject'] );
		$this->assertStringStartsWith( 'Ref MEM-' . $id . ', ends ', $mail['body'] );
	}

	public function test_a_junior_is_written_to_through_their_parent() {
		$this->member(
			'2026-10-05',
			array(
				'name'           => 'Junior',
				'email'          => '',
				'guardian_email' => 'parent@example.test',
			)
		);
		Chess_Army_Knife_Renewal_Reminders::run();
		Chess_Army_Knife_Mailer::run();

		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertSame( 'parent@example.test', $sent[0]['to'][0][0] );
	}

	public function test_nothing_is_sent_while_reminders_are_off_or_after_opting_out() {
		$id = $this->member( '2026-10-05' );

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$this->assertSame( 0, Chess_Army_Knife_Renewal_Reminders::run() );

		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'           => 0,
				'renewal_reminders_enabled' => 1,
			)
		);
		Chess_Army_Knife_Notification_Preferences::opt_out( $id, 'renewals' );
		$this->assertSame( 0, Chess_Army_Knife_Renewal_Reminders::run() );
		$this->assertSame( array(), Chess_Army_Knife_Mailer::get_log_for_person( $id ) );
	}

	public function test_renewing_extends_from_the_day_after_the_current_end_or_from_today() {
		$type = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Memberships::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Adult',
			)
		);
		update_post_meta( $type, Chess_Army_Knife_Memberships::META_MONTHS, 12 );

		$current = $this->member( '2026-10-31', array( 'membership_type_id' => $type ) );
		$lapsed  = $this->member( '2026-06-30', array( 'membership_type_id' => $type ) );

		$this->assertSame( '2027-10-31', Chess_Army_Knife_Membership_Store::renew_member( $current, self::TODAY ) );
		$this->assertSame( '2027-09-30', Chess_Army_Knife_Membership_Store::renew_member( $lapsed, self::TODAY ) );
		$this->assertSame( self::TODAY, Chess_Army_Knife_Membership_Store::get_member( $current )['paid_on'] );
	}

	public function test_a_membership_that_cannot_be_renewed_is_left_alone() {
		$no_type = $this->member( '2026-10-31' );
		$this->assertSame( '', Chess_Army_Knife_Membership_Store::renew_member( $no_type, self::TODAY ) );
		$this->assertSame( '', Chess_Army_Knife_Membership_Store::renew_member( 99999, self::TODAY ) );
		$this->assertSame( '2026-10-31', Chess_Army_Knife_Membership_Store::get_member( $no_type )['expiry_date'] );
	}

	public function test_the_marker_is_exported_and_erased() {
		$id = $this->member( '2026-10-05', array( 'paid_on' => '2026-01-01' ) );
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'id'               => $id,
				'renewal_reminder' => '2026-10-05|7',
			)
		);

		$this->assertStringContainsString( '2026-10-05|7', wp_json_encode( Chess_Army_Knife_Membership_Privacy::export( 'ada@example.test' )['data'] ) );

		Chess_Army_Knife_Membership_Store::erase_member( $id );
		$this->assertSame( '', Chess_Army_Knife_Membership_Store::get_member( $id )['renewal_reminder'] );
	}

	public function test_the_renewals_screen_lists_who_is_due() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );
		$this->member( '2026-10-05' );
		$this->member(
			'2026-10-05',
			array(
				'name'  => 'No Address',
				'email' => '',
			)
		);

		ob_start();
		Chess_Army_Knife_Renewals_Page::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Lovelace, Ada', $html );
		$this->assertStringContainsString( 'ada@example.test', $html );
		$this->assertStringContainsString( 'No: no email address', $html );
		$this->assertStringContainsString( 'Send these now', $html );
	}
}
