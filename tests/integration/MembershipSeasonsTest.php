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
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Membership_Seasons::seasons_table(), Chess_Army_Knife_Membership_Seasons::payments_table(), Chess_Army_Knife_Teams::squad_table(), Chess_Army_Knife_Teams::squad_history_table() ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Membership_Seasons::install_tables();
		Chess_Army_Knife_Teams::install_table();
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

	private function team( $name = 'Club A' ) {
		return self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
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

	public function test_squads_carry_on_into_the_new_season_and_are_kept_for_the_one_that_ended() {
		$team = $this->team();
		$ada  = $this->member();
		$alan = $this->member( array( 'name' => 'Alan Turing' ) );
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada, $alan ) );
		$first = Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) ); // Alan left during the season.

		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );

		$this->assertSame( array( $ada ), Chess_Army_Knife_Teams::squad( $team ), 'The squad carries on to be reviewed.' );
		$this->assertSame( array( $team => array( $ada ) ), Chess_Army_Knife_Teams::squads_of_season( $first ), 'As it stood when the season ended.' );
	}

	public function test_a_season_can_start_with_every_squad_empty() {
		$team = $this->team();
		$ada  = $this->member();
		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );

		$old = Chess_Army_Knife_Membership_Seasons::all()[0]['id'];
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01', true );

		$this->assertSame( array(), Chess_Army_Knife_Teams::squad( $team ) );
		$this->assertSame( array( $team => array( $ada ) ), Chess_Army_Knife_Teams::squads_of_season( $old ), 'The old squad is still on record.' );
	}

	public function test_the_first_season_leaves_the_squads_alone() {
		$team = $this->team();
		$ada  = $this->member();
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );

		$first = Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01', true );

		$this->assertSame( array( $ada ), Chess_Army_Knife_Teams::squad( $team ) );
		$this->assertSame( array(), Chess_Army_Knife_Teams::squads_of_season( $first ) );
	}

	public function test_erasing_a_person_takes_them_out_of_the_squads_of_past_seasons() {
		$team = $this->team();
		$ada  = $this->member();
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );
		$first = Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->assertNotEmpty( Chess_Army_Knife_Teams::squads_of_season( $first ) );

		Chess_Army_Knife_Membership_Store::erase_member( $ada );

		$this->assertSame( array(), Chess_Army_Knife_Teams::squads_of_season( $first ) );
	}

	public function test_the_seasons_screen_lists_the_squads_of_each_season() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );
		$team = $this->team( 'Club A' );
		$ada  = $this->member( array( 'name' => 'Lovelace, Ada' ) );
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );
		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );

		ob_start();
		Chess_Army_Knife_Seasons_Page::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Squads by season', $html );
		$this->assertStringContainsString( '2025/26', $html );
		$this->assertStringContainsString( 'Club A', $html );
		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringContainsString( 'Start every squad empty', $html );
	}
}
