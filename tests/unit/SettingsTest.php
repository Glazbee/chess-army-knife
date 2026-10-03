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
				Chess_Army_Knife_Settings::FORM_MARKER => '1',
				'default_org_id'                       => '12ab3',
				'default_domain'                       => 'zz',
				'default_days_back'                    => '-4',
				'default_max_players'                  => '0',
				'match_time'                           => '25:99',
				'cache_ecf_minutes'                    => '1',
				'cache_lms_minutes'                    => '90',
				'use_local_cache'                      => '1',
			)
		);

		$this->assertSame( '123', $clean['default_org_id'] );
		$this->assertSame( 'S', $clean['default_domain'] );
		$this->assertSame( 1, $clean['default_days_back'] );
		$this->assertSame( 1, $clean['default_max_players'] );
		$this->assertSame( '19:30', $clean['match_time'] );
		$this->assertSame( Chess_Army_Knife_Settings::MIN_ECF_CACHE_MINUTES, $clean['cache_ecf_minutes'] );
		$this->assertSame( 90, $clean['cache_lms_minutes'] );
		$this->assertSame( 1, $clean['use_local_cache'] );
		$this->assertSame( 0, $clean['fast_cache_enabled'] );
		$this->assertSame( 0, $clean['delete_data_on_uninstall'] );
	}

	public function test_a_save_that_is_not_from_the_form_changes_only_what_it_sends() {
		$this->set_settings(
			array(
				'club_name'                 => 'Test Club',
				'use_local_cache'           => 0,
				'renewal_reminders_enabled' => 1,
				'delete_data_on_uninstall'  => 1,
			)
		);

		$clean = Chess_Army_Knife_Settings::sanitize( array( 'member_retention_months' => '24' ) );

		$this->assertSame( 'Test Club', $clean['club_name'] );
		$this->assertSame( 0, $clean['use_local_cache'] );
		$this->assertSame( 1, $clean['renewal_reminders_enabled'] );
		$this->assertSame( 1, $clean['delete_data_on_uninstall'] );
	}

	public function test_an_unticked_box_on_the_form_turns_the_setting_off() {
		$this->set_settings( array( 'renewal_reminders_enabled' => 1 ) );

		$clean = Chess_Army_Knife_Settings::sanitize( array( Chess_Army_Knife_Settings::FORM_MARKER => '1' ) );

		$this->assertSame( 0, $clean['renewal_reminders_enabled'] );
	}

	public function test_uninstall_data_deletion_is_opt_in() {
		$this->assertSame( 0, Chess_Army_Knife_Settings::defaults()['delete_data_on_uninstall'] );
		$this->assertSame(
			1,
			Chess_Army_Knife_Settings::sanitize(
				array(
					Chess_Army_Knife_Settings::FORM_MARKER => '1',
					'delete_data_on_uninstall'             => '1',
				)
			)['delete_data_on_uninstall']
		);
	}

	public function test_sanitize_keeps_legacy_club_teams_text() {
		$this->set_settings( array( 'club_teams' => '1 | Div 1 | Team A' ) );

		$clean = Chess_Army_Knife_Settings::sanitize( array() );

		$this->assertSame( '1 | Div 1 | Team A', $clean['club_teams'] );
	}

	public function test_the_club_name_falls_back_to_the_site_title() {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site &amp; Co' );
		Functions\when( 'wp_specialchars_decode' )->alias( 'htmlspecialchars_decode' );

		$this->assertSame( 'My Site & Co', Chess_Army_Knife_Settings::club_name() );

		$this->set_settings( array( 'club_name' => 'Central Birmingham Chess Club' ) );
		$this->assertSame( 'Central Birmingham Chess Club', Chess_Army_Knife_Settings::club_name() );
	}

	public function test_club_name_and_venue_are_saved_and_the_old_clutter_is_gone() {
		$clean = Chess_Army_Knife_Settings::sanitize(
			array(
				'club_name'  => '  Our Club ',
				'club_venue' => ' The Hall ',
			)
		);

		$this->assertSame( 'Our Club', $clean['club_name'] );
		$this->assertSame( 'The Hall', $clean['club_venue'] );
		foreach ( array( 'lms_base_url', 'default_event_name', 'default_event_location', 'safeguarding_officer', 'safeguarding_email', 'safeguarding_phone', 'data_contact_email' ) as $removed ) {
			$this->assertArrayNotHasKey( $removed, $clean );
		}
	}

	public function test_saving_settings_keeps_the_retention_period_set_on_the_policies_screen() {
		$this->set_settings( array( 'member_retention_months' => 36 ) );

		$this->assertSame( 36, Chess_Army_Knife_Settings::sanitize( array( 'club_name' => 'Our Club' ) )['member_retention_months'] );
		$this->assertSame( 120, Chess_Army_Knife_Settings::clean_retention_months( '9999' ) );
		$this->assertSame( 0, Chess_Army_Knife_Settings::clean_retention_months( '-5' ) );
	}

	public function test_a_saved_lms_key_is_kept_when_the_box_is_left_blank_and_replaced_or_removed_on_request() {
		Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		$this->set_settings( array( 'lms_api_key' => 'lmsk_saved' ) );

		$this->assertSame( 'lmsk_saved', Chess_Army_Knife_Settings::sanitize( array( 'lms_api_key' => '' ) )['lms_api_key'], 'Blank keeps it: the key is never shown again.' );
		$this->assertSame( 'lmsk_saved', Chess_Army_Knife_Settings::sanitize( array() )['lms_api_key'], 'A form without the box keeps it too.' );
		$this->assertSame( 'lmsk_new', Chess_Army_Knife_Settings::sanitize( array( 'lms_api_key' => ' lmsk_new ' ) )['lms_api_key'] );
		$this->assertSame(
			'',
			Chess_Army_Knife_Settings::sanitize(
				array(
					'lms_api_key'       => '',
					'lms_api_key_clear' => '1',
				)
			)['lms_api_key']
		);
	}
}
