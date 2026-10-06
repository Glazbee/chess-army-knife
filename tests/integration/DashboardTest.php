<?php
/**
 * Integration tests: the membership dashboard.
 *
 * @package Chess_Army_Knife
 */

class DashboardTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Membership_Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Membership_Seasons::install_tables();
		Chess_Army_Knife_Member_Stats::forget();
	}

	private function render() {
		ob_start();
		Chess_Army_Knife_Dashboard_Page::render_page();
		return ob_get_clean();
	}

	private function as_officer() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );
	}

	public function test_the_figures_follow_changes_to_the_records() {
		$this->as_officer();
		$this->assertSame( 0, Chess_Army_Knife_Member_Stats::get()['current'] );

		$id = Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'   => 'Ada Lovelace',
				'status' => 'active',
			)
		);
		$this->assertSame( 1, Chess_Army_Knife_Member_Stats::get()['current'] ); // The cache was dropped by the change.

		Chess_Army_Knife_Membership_Store::delete_member( $id );
		$this->assertSame( 0, Chess_Army_Knife_Member_Stats::get()['current'] );
	}

	public function test_the_dashboard_shows_totals_and_no_names() {
		$this->as_officer();
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'      => 'Ada Lovelace',
				'status'    => 'active',
				'type_name' => 'Adult',
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Current members', $html );
		$this->assertStringContainsString( 'Adult', $html );
		$this->assertStringNotContainsString( 'Ada Lovelace', $html );
	}

	public function test_someone_without_the_permission_is_told_what_they_need_and_sees_no_figures() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		Chess_Army_Knife_Dashboard_Page::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'You do not have permission to use this page.', $html );
		$this->assertStringNotContainsString( 'cak-tile', $html );
	}
}
