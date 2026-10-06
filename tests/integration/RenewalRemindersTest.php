<?php
/**
 * Integration tests: payment reminders for members who have not paid for the season.
 *
 * @package Chess_Army_Knife
 */

class RenewalRemindersTest extends WP_UnitTestCase {

	/** @var string First day of the season, a few days ago so that the first reminder is due. */
	private $start;

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
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Membership_Seasons::seasons_table(), Chess_Army_Knife_Membership_Seasons::payments_table(), Chess_Army_Knife_Mailer::table(), Chess_Army_Knife_Notification_Preferences::table() ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Membership_Seasons::install_tables();
		Chess_Army_Knife_Mailer::install_table();
		Chess_Army_Knife_Notification_Preferences::install_table();
		delete_option( Chess_Army_Knife_Membership_Seasons::REVIEW_OPTION );
		reset_phpmailer_instance();

		$this->start = wp_date( 'Y-m-d', strtotime( '-3 days' ) );
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', $this->start );
	}

	/**
	 * A date some days after the season started.
	 *
	 * @param int $days Days after the first day.
	 * @return string Y-m-d.
	 */
	private function day( $days ) {
		return gmdate( 'Y-m-d', strtotime( $this->start . ' UTC' ) + $days * DAY_IN_SECONDS );
	}

	private function member( array $extra = array() ) {
		return Chess_Army_Knife_Membership_Store::save_member(
			$extra + array(
				'name'      => 'Ada Lovelace',
				'email'     => 'ada@example.test',
				'status'    => 'active',
				'type_name' => 'Adult',
			)
		);
	}

	private function run_on( $today ) {
		$queued = 0;
		foreach ( Chess_Army_Knife_Renewal_Reminders::due( $today ) as $item ) {
			$message = Chess_Army_Knife_Renewal_Reminders::compose( $item['member'], $item['season'] );
			$queued += Chess_Army_Knife_Mailer::queue( $item['member'], 'renewals', $message['subject'], $message['body'] ) ? 1 : 0;
			Chess_Army_Knife_Membership_Store::save_member(
				array(
					'id'               => $item['member']['id'],
					'renewal_reminder' => $item['season']['id'] . '|' . $item['stage'],
				)
			);
		}
		return $queued;
	}

	public function test_a_member_is_emailed_once_per_stage() {
		$this->member();

		$this->assertCount( 1, Chess_Army_Knife_Renewal_Reminders::due( $this->day( 0 ) ) );
		$this->assertSame( 1, $this->run_on( $this->day( 0 ) ) ); // The first day.
		$this->assertSame( 0, $this->run_on( $this->day( 1 ) ) ); // The next day: same stage, already sent.
		$this->assertSame( 1, $this->run_on( $this->day( 14 ) ) );
		$this->assertSame( 0, $this->run_on( $this->day( 15 ) ) );
		$this->assertSame( 1, $this->run_on( $this->day( 28 ) ) );
		$this->assertSame( 0, $this->run_on( $this->day( 35 ) ) );
		$this->assertSame( 0, $this->run_on( $this->day( 90 ) ), 'Nobody is chased for ever.' );
	}

	public function test_nobody_who_has_paid_or_is_not_current_is_due() {
		$this->member( array( 'paid_on' => $this->day( 1 ) ) );
		$this->member(
			array(
				'name'   => 'Left',
				'status' => 'cancelled',
			)
		);
		$this->member(
			array(
				'name'   => 'Guest',
				'status' => 'nonmember',
			)
		);
		$this->member(
			array(
				'name'   => 'Applicant',
				'status' => 'pending',
			)
		);

		$this->assertSame( array(), Chess_Army_Knife_Renewal_Reminders::due( $this->day( 0 ) ) );
	}

	public function test_paying_stops_the_reminders() {
		$id = $this->member();
		$this->run_on( $this->day( 0 ) );

		Chess_Army_Knife_Membership_Store::mark_paid( $id );

		$this->assertSame( array(), Chess_Army_Knife_Renewal_Reminders::due( $this->day( 14 ) ) );
	}

	public function test_a_new_season_starts_a_fresh_cycle() {
		$id = $this->member();
		$this->run_on( $this->day( 0 ) );
		$this->run_on( $this->day( 14 ) );
		Chess_Army_Knife_Membership_Store::mark_paid( $id );

		Chess_Army_Knife_Membership_Seasons::start( '2027/28', $this->day( 365 ) );

		$this->assertSame( array(), Chess_Army_Knife_Renewal_Reminders::due( $this->day( 364 ) ), 'Before the new season starts.' );
		$this->assertCount( 1, Chess_Army_Knife_Renewal_Reminders::due( $this->day( 365 ) ) );
	}

	public function test_nothing_is_due_before_a_season_has_been_started() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Chess_Army_Knife_Membership_Seasons::seasons_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->member();

		$this->assertSame( array(), Chess_Army_Knife_Renewal_Reminders::due( $this->day( 0 ) ) );
	}

	public function test_the_email_carries_the_payment_instructions_and_reference() {
		$id = $this->member();

		$mail = Chess_Army_Knife_Renewal_Reminders::compose( Chess_Army_Knife_Membership_Store::get_member( $id ), Chess_Army_Knife_Membership_Seasons::current() );

		$this->assertStringContainsString( 'Ada Lovelace', $mail['subject'] );
		$this->assertStringContainsString( 'Adult membership for Ada Lovelace has not been paid for the 2026/27 season', $mail['body'] );
		$this->assertStringContainsString( 'Pay at the club.', $mail['body'] );
		$this->assertStringContainsString( 'MEM-' . $id, $mail['body'] );
	}

	public function test_the_clubs_own_wording_is_used_with_placeholders() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'          => 0,
				'renewal_reminder_subject' => 'Pay {name}!',
				'renewal_reminder_message' => 'Ref {reference}, for {season}.',
			)
		);
		$id   = $this->member();
		$mail = Chess_Army_Knife_Renewal_Reminders::compose( Chess_Army_Knife_Membership_Store::get_member( $id ), Chess_Army_Knife_Membership_Seasons::current() );

		$this->assertSame( 'Pay Ada Lovelace!', $mail['subject'] );
		$this->assertSame( 'Ref MEM-' . $id . ', for 2026/27.', $mail['body'] );
	}

	public function test_a_junior_is_written_to_through_their_parent() {
		$this->member(
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
		$id = $this->member();

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

	public function test_the_marker_is_exported_and_erased() {
		$id = $this->member( array( 'paid_on' => '2026-01-01' ) );
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'id'               => $id,
				'renewal_reminder' => '1|14',
			)
		);

		$this->assertStringContainsString( '1|14', wp_json_encode( Chess_Army_Knife_Membership_Privacy::export( 'ada@example.test' )['data'] ) );

		Chess_Army_Knife_Membership_Store::erase_member( $id );
		$this->assertSame( '', Chess_Army_Knife_Membership_Store::get_member( $id )['renewal_reminder'] );
	}

	public function test_the_renewals_screen_lists_who_is_due() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );
		$this->member();
		$this->member(
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
