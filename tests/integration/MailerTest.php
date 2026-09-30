<?php
/**
 * Integration tests: queued email, who may be written to, unsubscribe links, and erasure.
 *
 * @package Chess_Army_Knife
 */

class MailerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Mailer::table(), Chess_Army_Knife_Notification_Preferences::table() ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Mailer::install_table();
		Chess_Army_Knife_Notification_Preferences::install_table();
		reset_phpmailer_instance();
	}

	public function tear_down() {
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function person( array $extra = array() ) {
		$id = Chess_Army_Knife_Membership_Store::save_member(
			$extra + array(
				'name'   => 'Ada Lovelace',
				'email'  => 'ada@example.test',
				'status' => 'active',
			)
		);
		return Chess_Army_Knife_Membership_Store::get_member( $id );
	}

	private function sent() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	public function test_queued_mail_is_sent_with_a_footer_and_unsubscribe_link() {
		$ada = $this->person();
		$this->assertGreaterThan( 0, Chess_Army_Knife_Mailer::queue( $ada, 'renewals', 'Renew', 'Please renew.' ) );

		$this->assertSame( 1, Chess_Army_Knife_Mailer::run() );

		$mail = $this->sent()[0];
		$this->assertSame( 'ada@example.test', $mail['to'][0][0] );
		$this->assertStringContainsString( 'Please renew.', $mail['body'] );
		$this->assertStringContainsString( 'cak_u=', $mail['body'] );

		$log = Chess_Army_Knife_Mailer::get_log_for_person( $ada['id'] );
		$this->assertSame( 'sent', $log[0]['status'] );
		global $wpdb;
		$this->assertNull( $wpdb->get_var( 'SELECT message FROM ' . Chess_Army_Knife_Mailer::table() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_a_junior_is_written_to_through_their_parent() {
		$junior = $this->person(
			array(
				'name'           => 'Junior',
				'email'          => '',
				'guardian_email' => 'parent@example.test',
			)
		);
		Chess_Army_Knife_Mailer::queue( $junior, 'renewals', 'Renew', 'Body' );
		Chess_Army_Knife_Mailer::run();

		$this->assertSame( 'parent@example.test', $this->sent()[0]['to'][0][0] );
	}

	public function test_nobody_is_queued_without_an_address_or_when_opted_out() {
		$no_address = $this->person( array( 'email' => '' ) );
		$this->assertSame( 0, Chess_Army_Knife_Mailer::queue( $no_address, 'renewals', 'S', 'B' ) );

		$ada = $this->person();
		Chess_Army_Knife_Notification_Preferences::opt_out( $ada['id'], 'renewals' );
		$this->assertSame( 0, Chess_Army_Knife_Mailer::queue( $ada, 'renewals', 'S', 'B' ) );
		$this->assertSame( 0, Chess_Army_Knife_Mailer::queue( $ada, 'nonsense', 'S', 'B' ) );
	}

	public function test_the_newsletter_needs_the_recorded_opt_in() {
		$ada = $this->person();
		$this->assertSame( 0, Chess_Army_Knife_Mailer::queue( $ada, 'newsletter', 'S', 'B' ) );

		Chess_Army_Knife_Notification_Preferences::opt_in( $ada['id'], 'newsletter' );
		$ada = Chess_Army_Knife_Membership_Store::get_member( $ada['id'] );
		$this->assertGreaterThan( 0, Chess_Army_Knife_Mailer::queue( $ada, 'newsletter', 'S', 'B' ) );
	}

	public function test_unsubscribing_before_the_send_skips_the_message() {
		$ada = $this->person();
		Chess_Army_Knife_Mailer::queue( $ada, 'fixtures', 'Fixture', 'B' );
		Chess_Army_Knife_Notification_Preferences::opt_out( $ada['id'], 'fixtures' );

		$this->assertSame( 0, Chess_Army_Knife_Mailer::run() );
		$this->assertSame( array(), $this->sent() );
		$this->assertSame( 'skipped', Chess_Army_Knife_Mailer::get_log_for_person( $ada['id'] )[0]['status'] );
	}

	public function test_a_failed_send_is_retried_then_given_up() {
		$ada = $this->person();
		Chess_Army_Knife_Mailer::queue( $ada, 'renewals', 'S', 'B' );
		add_filter( 'pre_wp_mail', '__return_false' );

		for ( $i = 0; $i < Chess_Army_Knife_Mailer::MAX_ATTEMPTS; $i++ ) {
			$this->assertSame( 'queued' === Chess_Army_Knife_Mailer::get_log_for_person( $ada['id'] )[0]['status'], true );
			Chess_Army_Knife_Mailer::run();
		}

		$this->assertSame( 'failed', Chess_Army_Knife_Mailer::get_log_for_person( $ada['id'] )[0]['status'] );
		remove_filter( 'pre_wp_mail', '__return_false' );
	}

	public function test_a_batch_is_limited() {
		add_filter(
			'Chess_Army_Knife_mail_batch',
			function () {
				return 2;
			}
		);
		$ada = $this->person();
		for ( $i = 0; $i < 3; $i++ ) {
			Chess_Army_Knife_Mailer::queue( $ada, 'renewals', 'S', 'B' );
		}

		$this->assertSame( 2, Chess_Army_Knife_Mailer::run() );
		$this->assertSame( 1, Chess_Army_Knife_Mailer::run() );
	}

	public function test_the_unsubscribe_link_is_signed_for_one_person_and_category() {
		$link = Chess_Army_Knife_Notification_Preferences::unsubscribe_url( 7, 'renewals' );
		parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $query );
		$value = $query['cak_u'];

		$this->assertSame(
			array(
				'person_id' => 7,
				'category'  => 'renewals',
			),
			Chess_Army_Knife_Notification_Preferences::parse_unsubscribe( $value )
		);
		$this->assertNull( Chess_Army_Knife_Notification_Preferences::parse_unsubscribe( str_replace( '7.', '8.', $value ) ) );
		$this->assertNull( Chess_Army_Knife_Notification_Preferences::parse_unsubscribe( str_replace( 'renewals', 'fixtures', $value ) ) );
		$this->assertNull( Chess_Army_Knife_Notification_Preferences::parse_unsubscribe( 'garbage' ) );
	}

	private function open_unsubscribe_link( $person_id, $method ) {
		$link = Chess_Army_Knife_Notification_Preferences::unsubscribe_url( $person_id, 'renewals' );
		parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simulates opening the signed link.
		$_SERVER['REQUEST_METHOD'] = $method;
		add_filter(
			'wp_die_handler',
			function () {
				return function ( $message ) {
					throw new Exception( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test only: captures the page text.
				};
			}
		);
		try {
			Chess_Army_Knife_Notification_Preferences::maybe_handle_unsubscribe();
		} catch ( Exception $e ) {
			return $e->getMessage();
		} finally {
			$_GET                      = array();
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}
		return '';
	}

	public function test_opening_the_link_only_asks_and_posting_to_it_unsubscribes() {
		$ada  = $this->person();
		$page = $this->open_unsubscribe_link( $ada['id'], 'GET' );
		$this->assertStringContainsString( '<form method="post"', $page );
		$this->assertSame( array(), Chess_Army_Knife_Notification_Preferences::get_opt_outs( $ada['id'] ) );

		$this->open_unsubscribe_link( $ada['id'], 'POST' );
		$this->assertSame( array( 'renewals' ), Chess_Army_Knife_Notification_Preferences::get_opt_outs( $ada['id'] ) );
	}

	public function test_opting_out_and_in_again() {
		$ada = $this->person();
		Chess_Army_Knife_Notification_Preferences::opt_out( $ada['id'], 'renewals' );
		Chess_Army_Knife_Notification_Preferences::opt_out( $ada['id'], 'renewals' );
		$this->assertSame( array( 'renewals' ), Chess_Army_Knife_Notification_Preferences::get_opt_outs( $ada['id'] ) );

		Chess_Army_Knife_Notification_Preferences::opt_in( $ada['id'], 'renewals' );
		$this->assertSame( array(), Chess_Army_Knife_Notification_Preferences::get_opt_outs( $ada['id'] ) );
	}

	public function test_unsubscribing_from_the_newsletter_withdraws_the_consent() {
		$ada = $this->person( array( 'newsletter_consent_at' => '2026-01-01 10:00:00' ) );
		Chess_Army_Knife_Notification_Preferences::opt_out( $ada['id'], 'newsletter' );

		$this->assertSame( '', Chess_Army_Knife_Membership_Store::get_member( $ada['id'] )['newsletter_consent_at'] );
	}

	public function test_erasing_a_person_removes_their_mail_log_and_choices() {
		$ada = $this->person();
		Chess_Army_Knife_Mailer::queue( $ada, 'renewals', 'S', 'B' );
		Chess_Army_Knife_Mailer::run();
		Chess_Army_Knife_Notification_Preferences::opt_out( $ada['id'], 'fixtures' );

		// A payment keeps the record, anonymised; the mail and choices still go.
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'id'      => $ada['id'],
				'paid_on' => '2026-01-01',
			)
		);
		$this->assertSame( 'anonymised', Chess_Army_Knife_Membership_Store::erase_member( $ada['id'] ) );

		$this->assertSame( array(), Chess_Army_Knife_Mailer::get_log_for_person( $ada['id'] ) );
		$this->assertSame( array(), Chess_Army_Knife_Notification_Preferences::get_opt_outs( $ada['id'] ) );
	}

	public function test_the_export_lists_emails_and_turned_off_kinds() {
		$ada = $this->person();
		Chess_Army_Knife_Mailer::queue( $ada, 'renewals', 'Time to renew', 'B' );
		Chess_Army_Knife_Mailer::run();
		Chess_Army_Knife_Notification_Preferences::opt_out( $ada['id'], 'fixtures' );

		$export = Chess_Army_Knife_Membership_Privacy::export( 'ada@example.test' );
		$groups = wp_list_pluck( $export['data'], 'group_id' );

		$this->assertContains( 'chess-army-knife-emails', $groups );
		$this->assertContains( 'chess-army-knife-email-choices', $groups );
		$this->assertStringContainsString( 'Time to renew', wp_json_encode( $export['data'] ) );
	}

	public function test_old_log_rows_are_pruned_but_queued_ones_are_not() {
		global $wpdb;
		$ada = $this->person();
		Chess_Army_Knife_Mailer::queue( $ada, 'renewals', 'Old sent', 'B' );
		Chess_Army_Knife_Mailer::queue( $ada, 'renewals', 'Old queued', 'B' );
		$wpdb->query( 'UPDATE ' . Chess_Army_Knife_Mailer::table() . " SET queued_at = '2000-01-01 00:00:00'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'UPDATE ' . Chess_Army_Knife_Mailer::table() . " SET status = 'sent' WHERE subject = 'Old sent'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->assertSame( 1, Chess_Army_Knife_Mailer::prune_log() );
		$this->assertCount( 1, Chess_Army_Knife_Mailer::get_log_for_person( $ada['id'] ) );
	}
}
