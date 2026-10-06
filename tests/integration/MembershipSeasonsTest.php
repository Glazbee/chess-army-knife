<?php
/**
 * Integration tests: membership seasons, the payments ledger and the new season reset.
 *
 * @package Chess_Army_Knife
 */

class MembershipSeasonsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Membership_Seasons::seasons_table(), Chess_Army_Knife_Membership_Seasons::payments_table() ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Membership_Seasons::install_tables();
		delete_option( Chess_Army_Knife_Membership_Seasons::REVIEW_OPTION );
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

	private function pay( $id, $date, $amount = 2500, $method = 'cash' ) {
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'id'             => $id,
				'paid_on'        => $date,
				'payment_method' => $method,
				'payment_amount' => $amount,
			)
		);
	}

	public function test_there_is_no_season_until_one_is_started() {
		$this->assertNull( Chess_Army_Knife_Membership_Seasons::current() );
		$this->assertSame( array(), Chess_Army_Knife_Membership_Seasons::all() );
	}

	public function test_the_first_season_counts_the_payments_already_recorded_and_resets_nothing() {
		$paid   = $this->member();
		$unpaid = $this->member( array( 'name' => 'Alan Turing' ) );
		$this->pay( $paid, '2026-09-05' );

		$id = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );

		$this->assertIsInt( $id );
		$this->assertSame( '2026-09-05', Chess_Army_Knife_Membership_Store::get_member( $paid )['paid_on'] );
		$payments = Chess_Army_Knife_Membership_Seasons::payments_for_season( $id );
		$this->assertCount( 1, $payments );
		$this->assertSame( $paid, $payments[0]['member_id'] );
		$this->assertSame( 2500, $payments[0]['amount'] );
		$this->assertSame( '', Chess_Army_Knife_Membership_Store::get_member( $unpaid )['paid_on'] );
		$this->assertFalse( Chess_Army_Knife_Membership_Seasons::review_pending(), 'There is nothing to review before the first season.' );
	}

	public function test_a_new_season_marks_everyone_as_not_paid_and_keeps_the_old_payments() {
		$ada = $this->member( array( 'payment_reference' => 'ADA-1' ) );
		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );
		$this->pay( $ada, '2025-09-10', 2500, 'bank_transfer' );

		$new = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );

		$member = Chess_Army_Knife_Membership_Store::get_member( $ada );
		$this->assertSame( '', $member['paid_on'] );
		$this->assertSame( '', $member['payment_method'] );
		$this->assertNull( $member['payment_amount'] );
		$this->assertSame( 'ADA-1', $member['payment_reference'], 'Their reference is theirs, not this season\'s.' );
		$this->assertSame( 1, Chess_Army_Knife_Membership_Store::count_view( 'unpaid' ) );
		$this->assertSame( array(), Chess_Army_Knife_Membership_Seasons::payments_for_season( $new ) );

		$seasons = Chess_Army_Knife_Membership_Seasons::all();
		$this->assertSame( '2026/27', $seasons[0]['name'] );
		$this->assertSame( '2025/26', $seasons[1]['name'] );
		$this->assertSame( '2026-08-31', $seasons[1]['end_date'], 'The season before ends the day before.' );
		$this->assertCount( 1, Chess_Army_Knife_Membership_Seasons::payments_for_season( $seasons[1]['id'] ) );
		$this->assertTrue( Chess_Army_Knife_Membership_Seasons::review_pending() );
	}

	public function test_paying_in_the_new_season_adds_to_the_ledger_once() {
		$ada = $this->member();
		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );
		$this->pay( $ada, '2025-09-10' );
		$new = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );

		$this->pay( $ada, '2026-09-12', 2000 );
		$this->pay( $ada, '2026-09-13', 2000 ); // Corrected: still one payment for the season.

		$payments = Chess_Army_Knife_Membership_Seasons::payments_for_season( $new );
		$this->assertCount( 1, $payments );
		$this->assertSame( '2026-09-13', $payments[0]['paid_on'] );
		$this->assertCount( 2, Chess_Army_Knife_Membership_Seasons::paid_seasons( $ada ) );
		$this->assertSame( 0, Chess_Army_Knife_Membership_Store::count_view( 'unpaid' ) );
	}

	public function test_taking_a_payment_back_takes_it_out_of_the_ledger() {
		$ada = $this->member();
		$id  = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->pay( $ada, '2026-09-12' );
		$this->assertCount( 1, Chess_Army_Knife_Membership_Seasons::payments_for_season( $id ) );

		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'id'      => $ada,
				'paid_on' => null,
			)
		);

		$this->assertSame( array(), Chess_Army_Knife_Membership_Seasons::payments_for_season( $id ) );
	}

	public function test_a_bank_transfer_keeps_the_reference_but_cash_does_not() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'           => 0,
				'membership_use_references' => 1,
			)
		);
		$ada  = $this->member( array( 'payment_reference' => 'ADA-1' ) );
		$alan = $this->member( array( 'name' => 'Alan Turing', 'payment_reference' => 'ALAN-1' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$id   = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->pay( $ada, '2026-09-12', 2500, 'bank_transfer' );
		$this->pay( $alan, '2026-09-13', 2500, 'cash' );

		$by_member = array_column( Chess_Army_Knife_Membership_Seasons::payments_for_season( $id ), null, 'member_id' );

		$this->assertSame( 'ADA-1', $by_member[ $ada ]['reference'] );
		$this->assertSame( '', $by_member[ $alan ]['reference'] );
	}

	public function test_history_comes_from_the_seasons_paid_for() {
		$ada = $this->member();
		Chess_Army_Knife_Membership_Seasons::start( '2024/25', '2024-09-01' );
		$this->pay( $ada, '2024-09-10' );
		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );
		$this->pay( $ada, '2025-09-10' );
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' ); // Not paid yet.

		$periods = Chess_Army_Knife_Member_History::periods( Chess_Army_Knife_Membership_Store::get_member( $ada ) );

		$this->assertCount( 2, $periods );
		$this->assertSame( '2024-09-01', $periods[0]['start_date'] );
		$this->assertSame( '2025-08-31', $periods[0]['expiry_date'] );
		$this->assertSame( '2025-09-01', $periods[1]['start_date'] );
	}

	public function test_a_season_cannot_start_before_the_current_one() {
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );

		$result = Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );

		$this->assertWPError( $result );
		$this->assertCount( 1, Chess_Army_Knife_Membership_Seasons::all() );
	}

	public function test_someone_who_paid_in_an_earlier_season_is_kept_for_the_accounts_when_erased() {
		$ada = $this->member();
		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );
		$this->pay( $ada, '2025-09-10' );
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );

		$this->assertSame( 'anonymised', Chess_Army_Knife_Membership_Store::erase_member( $ada ) );

		$this->assertSame( Chess_Army_Knife_Membership_Store::erased_name(), Chess_Army_Knife_Membership_Store::get_member( $ada )['name'] );
		$this->assertTrue( Chess_Army_Knife_Membership_Seasons::has_payments( $ada ) );
	}

	public function test_deleting_a_member_deletes_their_payments() {
		$ada = $this->member();
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->pay( $ada, '2026-09-12' );

		Chess_Army_Knife_Membership_Store::delete_member( $ada );

		$this->assertFalse( Chess_Army_Knife_Membership_Seasons::has_payments( $ada ) );
	}

	public function test_the_treasurers_file_lists_the_seasons_payments() {
		$ada = $this->member( array( 'name' => 'Lovelace, Ada', 'nickname' => 'Ads' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$id  = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->pay( $ada, '2026-09-12', 1250 );

		$csv = Chess_Army_Knife_Payment_Export::to_csv( Chess_Army_Knife_Membership_Seasons::payments_for_season( $id ) );

		$this->assertStringContainsString( 'Ads,Lovelace,2026-09-12,Cash,,12.50', $csv );
	}
}
