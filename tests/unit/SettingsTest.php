<?php
/**
 * Tests for Chess_Army_Knife_Settings.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class SettingsTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	public function test_get_options_merges_saved_over_defaults() {
		$this->set_settings( array( 'default_domain' => 'R' ) );

		$options = Chess_Army_Knife_Settings::get_options();

		$this->assertSame( 'R', $options['default_domain'] );
		$this->assertSame( 12, $options['default_max_players'] );
	}

	public function test_get_options_ignores_corrupt_saved_value() {
		$this->options['Chess_Army_Knife_settings'] = 'garbage';

		$this->assertSame( Chess_Army_Knife_Settings::defaults(), Chess_Army_Knife_Settings::get_options() );
	}

	public function test_resolve_prefers_block_value() {
		$this->set_settings( array( 'default_club_code' => 'GLOBAL' ) );

		$this->assertSame( 'BLOCK', Chess_Army_Knife_Settings::resolve( 'default_club_code', 'BLOCK' ) );
	}

	public function test_resolve_falls_back_to_global_then_hard_fallback() {
		$this->set_settings( array( 'default_club_code' => 'GLOBAL' ) );

		$this->assertSame( 'GLOBAL', Chess_Army_Knife_Settings::resolve( 'default_club_code', '' ) );
		$this->assertSame( 'GLOBAL', Chess_Army_Knife_Settings::resolve( 'default_club_code', null ) );
		$this->assertSame( 'HARD', Chess_Army_Knife_Settings::resolve( 'default_org_id', '', 'HARD' ) );
	}

	public function test_get_cache_minutes_uses_bucket_prefix() {
		$this->set_settings(
			array(
				'cache_ecf_minutes' => 100,
				'cache_lms_minutes' => 20,
			)
		);

		$this->assertSame( 100, Chess_Army_Knife_Settings::get_cache_minutes( 'ecf_games', 1 ) );
		$this->assertSame( 20, Chess_Army_Knife_Settings::get_cache_minutes( 'lms_table', 1 ) );
		$this->assertSame( 7, Chess_Army_Knife_Settings::get_cache_minutes( 'other', 7 ) );
	}

	public function test_get_cache_minutes_uses_default_when_not_positive() {
		$this->set_settings( array( 'cache_ecf_minutes' => 0 ) );

		$this->assertSame( 45, Chess_Army_Knife_Settings::get_cache_minutes( 'ecf_games', 45 ) );
	}

	public function test_use_local_cache_flag() {
		$this->assertTrue( Chess_Army_Knife_Settings::use_local_cache() );
		$this->set_settings( array( 'use_local_cache' => 0 ) );
		$this->assertFalse( Chess_Army_Knife_Settings::use_local_cache() );
	}

	/**
	 * Pretend the site clock reads $time today.
	 */
	private function set_site_time( $time ) {
		$ts = strtotime( "2026-01-15 {$time}" );
		Functions\when( 'current_time' )->alias(
			function ( $type ) use ( $ts ) {
				return 'timestamp' === $type ? $ts : gmdate( $type, $ts );
			}
		);
	}

	public function test_fast_cache_window_starts_thirty_minutes_before_match_time() {
		$this->set_settings( array( 'match_time' => '19:30' ) );

		$this->set_site_time( '18:59' );
		$this->assertFalse( Chess_Army_Knife_Settings::in_fast_cache_window() );

		$this->set_site_time( '19:00' );
		$this->assertTrue( Chess_Army_Knife_Settings::in_fast_cache_window() );

		$this->set_site_time( '23:59' );
		$this->assertTrue( Chess_Army_Knife_Settings::in_fast_cache_window() );
	}

	public function test_fast_cache_window_can_be_disabled() {
		$this->set_settings( array( 'fast_cache_enabled' => 0 ) );
		$this->set_site_time( '20:00' );

		$this->assertFalse( Chess_Army_Knife_Settings::in_fast_cache_window() );
	}

	public function test_invalid_match_time_falls_back_to_default() {
		$this->set_settings( array( 'match_time' => 'late' ) );

		$this->set_site_time( '18:59' );
		$this->assertFalse( Chess_Army_Knife_Settings::in_fast_cache_window() );
		$this->set_site_time( '19:00' );
		$this->assertTrue( Chess_Army_Knife_Settings::in_fast_cache_window() );
	}

	public function test_effective_lms_minutes_is_five_in_window_else_configured() {
		$this->set_settings( array( 'cache_lms_minutes' => 30 ) );

		$this->set_site_time( '12:00' );
		$this->assertSame( 30, Chess_Army_Knife_Settings::get_effective_lms_cache_minutes( 'lms_table', 30 ) );

		$this->set_site_time( '20:00' );
		$this->assertSame( 5, Chess_Army_Knife_Settings::get_effective_lms_cache_minutes( 'lms_table', 30 ) );
	}

	public function test_sanitize_cleans_and_bounds_input() {
		$clean = Chess_Army_Knife_Settings::sanitize(
			array(
				'default_org_id'      => '12ab3',
				'default_domain'      => 'zz',
				'default_days_back'   => '-4',
				'default_max_players' => '0',
				'match_time'          => '25:99',
				'cache_ecf_minutes'   => '1',
				'cache_lms_minutes'   => '90',
				'use_local_cache'     => '1',
			)
		);

		$this->assertSame( '123', $clean['default_org_id'] );
		$this->assertSame( 'S', $clean['default_domain'] );
		$this->assertSame( 1, $clean['default_days_back'] );
		$this->assertSame( 1, $clean['default_max_players'] );
		$this->assertSame( '19:30', $clean['match_time'] );
		$this->assertSame( 5, $clean['cache_ecf_minutes'] );
		$this->assertSame( 90, $clean['cache_lms_minutes'] );
		$this->assertSame( 1, $clean['use_local_cache'] );
		$this->assertSame( 0, $clean['fast_cache_enabled'] );
		$this->assertSame( 0, $clean['delete_data_on_uninstall'] );
	}

	public function test_uninstall_data_deletion_is_opt_in() {
		$this->assertSame( 0, Chess_Army_Knife_Settings::defaults()['delete_data_on_uninstall'] );
		$this->assertSame( 1, Chess_Army_Knife_Settings::sanitize( array( 'delete_data_on_uninstall' => '1' ) )['delete_data_on_uninstall'] );
	}

	public function test_sanitize_keeps_legacy_club_teams_text() {
		$this->set_settings( array( 'club_teams' => '1 | Div 1 | Team A' ) );

		$clean = Chess_Army_Knife_Settings::sanitize( array() );

		$this->assertSame( '1 | Div 1 | Team A', $clean['club_teams'] );
	}
}
