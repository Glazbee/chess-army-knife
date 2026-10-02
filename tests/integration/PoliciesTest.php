<?php
/**
 * Integration tests: the policy pages.
 *
 * @package Chess_Army_Knife
 */

class PoliciesTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Chess_Army_Knife_Policies::OPTION );
		delete_option( 'wp_page_for_privacy_policy' );
		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private static function all_set_up() {
		foreach ( array( 'data', 'safeguarding', 'privacy' ) as $key ) {
			Chess_Army_Knife_Policies::set_up( $key, true );
		}
	}

	public function test_nothing_is_made_until_the_club_says_so() {
		foreach ( array( 'data', 'safeguarding', 'privacy' ) as $key ) {
			$this->assertNull( Chess_Army_Knife_Policies::page( $key ) );
			$this->assertSame( 'undecided', Chess_Army_Knife_Policies::status( $key ) );
		}
		$this->assertSame( 3, Chess_Army_Knife_Policies::attention_count() );
		$this->assertStringContainsString( 'not set up yet', Chess_Army_Knife_Policies::attention_notice() );
		$this->assertCount(
			0,
			get_posts(
				array(
					'post_type'   => 'page',
					'post_status' => 'any',
					'name'        => 'safeguarding-policy',
				)
			)
		);
	}

	public function test_saying_no_makes_nothing_and_stops_the_reminder_for_that_policy() {
		$this->assertTrue( Chess_Army_Knife_Policies::set_up( 'safeguarding', false ) );

		$this->assertNull( Chess_Army_Knife_Policies::page( 'safeguarding' ) );
		$this->assertSame( 'skipped', Chess_Army_Knife_Policies::status( 'safeguarding' ) );
		$this->assertSame( 2, Chess_Army_Knife_Policies::attention_count() );

		// A change of mind makes the page.
		Chess_Army_Knife_Policies::set_up( 'safeguarding', true );
		$this->assertNotNull( Chess_Army_Knife_Policies::page( 'safeguarding' ) );
	}

	public function test_a_new_policy_is_not_finished_until_it_has_been_marked_as_reviewed() {
		self::all_set_up();
		$this->assertSame( 'review', Chess_Army_Knife_Policies::status( 'data' ) );
		$this->assertStringContainsString( 'marked as reviewed', Chess_Army_Knife_Policies::attention_notice() );
		$this->assertStringNotContainsString( 'not set up yet', Chess_Army_Knife_Policies::attention_notice() );

		Chess_Army_Knife_Policies::mark_reviewed( 'data', true );
		$this->assertSame( 'done', Chess_Army_Knife_Policies::status( 'data' ) );
		$this->assertGreaterThan( 0, Chess_Army_Knife_Policies::reviewed_at( 'data' ) );

		Chess_Army_Knife_Policies::mark_reviewed( 'data', false );
		$this->assertSame( 'review', Chess_Army_Knife_Policies::status( 'data' ) );
	}

	public function test_putting_the_example_back_means_it_needs_reviewing_again() {
		Chess_Army_Knife_Policies::set_up( 'data', true );
		Chess_Army_Knife_Policies::mark_reviewed( 'data', true );

		Chess_Army_Knife_Policies::restore( 'data' );

		$this->assertSame( 'review', Chess_Army_Knife_Policies::status( 'data' ) );
	}

	public function test_a_privacy_page_the_site_already_has_needs_no_review() {
		$existing = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $existing );

		Chess_Army_Knife_Policies::set_up( 'privacy', true );

		$this->assertSame( 'done', Chess_Army_Knife_Policies::status( 'privacy' ) );
	}

	public function test_someone_opening_an_unreviewed_policy_page_is_reminded() {
		Chess_Army_Knife_Policies::set_up( 'data', true );
		$id                = Chess_Army_Knife_Policies::page( 'data' )->ID;
		$screen            = WP_Screen::get( 'page' );
		$screen->base      = 'post';
		$screen->post_type = 'page';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test stands in for the page editor.
		$GLOBALS['current_screen'] = $screen;
		$_GET['post']              = $id;

		ob_start();
		Chess_Army_Knife_Policies::edit_screen_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'This policy is not finished', $notice );

		Chess_Army_Knife_Policies::mark_reviewed( 'data', true );
		ob_start();
		Chess_Army_Knife_Policies::edit_screen_notice();
		$this->assertSame( '', ob_get_clean() );
		unset( $_GET['post'] );
	}

	public function test_the_privacy_guide_points_to_the_data_policy_only_once_it_exists() {
		$this->assertNull( Chess_Army_Knife_Policies::page( 'data' ) );
		$this->assertSame( '', Chess_Army_Knife_Membership_Privacy::policy_guide_text() );

		Chess_Army_Knife_Policies::set_up( 'data', true );
		$this->assertStringContainsString( 'chess_army_policy_link', Chess_Army_Knife_Membership_Privacy::policy_guide_text() );
	}

	public function test_each_policy_gets_a_draft_page_with_starting_text() {
		self::all_set_up();

		foreach ( array( 'data', 'safeguarding', 'privacy' ) as $key ) {
			$page = Chess_Army_Knife_Policies::page( $key );
			$this->assertNotNull( $page, $key );
			$this->assertSame( 'draft', $page->post_status, 'Nothing goes public before the club has read it.' );
			$this->assertNotSame( '', trim( $page->post_content ), $key );
		}
		$this->assertStringContainsString( 'Sharing with the English Chess Federation', Chess_Army_Knife_Policies::page( 'data' )->post_content );
		$this->assertStringContainsString( 'Reporting and responding to safeguarding concerns', Chess_Army_Knife_Policies::page( 'safeguarding' )->post_content );
	}

	public function test_making_the_pages_twice_does_not_make_copies() {
		self::all_set_up();
		$first = Chess_Army_Knife_Policies::page( 'data' )->ID;
		Chess_Army_Knife_Policies::set_up( 'data', true );

		$this->assertSame( $first, Chess_Army_Knife_Policies::page( 'data' )->ID );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'page',
					'post_status' => 'any',
					'name'        => 'club-data-policy',
				)
			)
		);
	}

	public function test_the_privacy_policy_page_becomes_the_sites_privacy_page() {
		self::all_set_up();

		$this->assertSame( Chess_Army_Knife_Policies::page( 'privacy' )->ID, (int) get_option( 'wp_page_for_privacy_policy' ) );
		$this->assertStringContainsString( 'chess_army_policy_link', Chess_Army_Knife_Policies::page( 'privacy' )->post_content, 'It points to the club policies.' );
	}

	public function test_a_privacy_page_the_site_already_has_is_used_and_never_rewritten() {
		$existing = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Our privacy policy',
				'post_content' => 'Written by the owner.',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $existing );

		self::all_set_up();

		$this->assertSame( $existing, Chess_Army_Knife_Policies::page( 'privacy' )->ID );
		$this->assertFalse( Chess_Army_Knife_Policies::can_restore( 'privacy' ) );
		$this->assertWPError( Chess_Army_Knife_Policies::restore( 'privacy' ) );
		$this->assertSame( 'Written by the owner.', get_post( $existing )->post_content );
	}

	public function test_putting_the_plugins_wording_back_replaces_edits_and_keeps_a_revision() {
		self::all_set_up();
		$id = Chess_Army_Knife_Policies::page( 'safeguarding' )->ID;
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => 'The club\'s own words.',
			)
		);

		Chess_Army_Knife_Policies::restore( 'safeguarding' );

		$this->assertStringContainsString( 'Reporting and responding to safeguarding concerns', get_post( $id )->post_content );
		$this->assertNotEmpty( wp_get_post_revisions( $id ), 'The earlier text can be got back.' );
	}

	public function test_restoring_a_page_that_was_deleted_makes_it_again() {
		self::all_set_up();
		wp_delete_post( Chess_Army_Knife_Policies::page( 'data' )->ID, true );
		$this->assertNull( Chess_Army_Knife_Policies::page( 'data' ) );

		Chess_Army_Knife_Policies::restore( 'data' );

		$this->assertNotNull( Chess_Army_Knife_Policies::page( 'data' ) );
	}

	public function test_a_policy_page_has_a_link_only_once_it_is_published() {
		self::all_set_up();
		$page = Chess_Army_Knife_Policies::page( 'data' );

		$this->assertSame( '', Chess_Army_Knife_Policies::url( 'data' ) );
		$this->assertSame( 'Club data policy', do_shortcode( '[chess_army_policy_link policy=data]' ) );

		wp_update_post(
			array(
				'ID'          => $page->ID,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( get_permalink( $page->ID ), Chess_Army_Knife_Policies::url( 'data' ) );
		$this->assertStringContainsString( '<a href="' . get_permalink( $page->ID ) . '">Club data policy</a>', do_shortcode( '[chess_army_policy_link policy=data]' ) );
	}

	public function test_the_data_policy_starts_from_the_retention_period() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'member_retention_months' => 18,
			)
		);

		$text = Chess_Army_Knife_Policies::content( 'data' );

		$this->assertStringContainsString( 'for 18 months afterwards', $text );
		$this->assertStringContainsString( '[add an email address]', $text, 'The contact is left for the club to write.' );
	}

	public function test_the_safeguarding_policy_leaves_the_officers_details_to_fill_in() {
		$text = Chess_Army_Knife_Policies::content( 'safeguarding' );

		$this->assertStringContainsString( '[add the name of your safeguarding officer]', $text );
		$this->assertStringContainsString( '[add their email address]', $text );
		$this->assertStringContainsString( '[add their phone number]', $text );
	}

	public function test_the_club_name_is_used_in_the_safeguarding_policy() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'club_name'       => 'Central Birmingham Chess Club',
			)
		);

		$this->assertStringContainsString( 'Central Birmingham Chess Club (the Club)', Chess_Army_Knife_Policies::content( 'safeguarding' ) );
	}

	public function test_the_retention_period_is_set_on_the_policies_screen_and_kept_when_settings_are_saved() {
		Chess_Army_Knife_Policies::set_retention_months( '36' );
		$this->assertSame( 36, Chess_Army_Knife_Settings::get_options()['member_retention_months'] );

		Chess_Army_Knife_Policies::set_retention_months( '9999' );
		$this->assertSame( 120, Chess_Army_Knife_Settings::get_options()['member_retention_months'] );

		$clean = Chess_Army_Knife_Settings::sanitize( array( 'club_name' => 'Our Club' ) );
		$this->assertSame( 120, $clean['member_retention_months'], 'Saving Settings does not reset it.' );
	}

	public function test_the_safeguarding_policy_explains_the_word_safeguarding() {
		$this->assertStringContainsString( 'Safeguarding means keeping people safe from harm', Chess_Army_Knife_Policies::content( 'safeguarding' ) );
	}

	public function test_only_people_who_can_edit_pages_may_use_the_screen() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		Chess_Army_Knife_Policies::render_page();
		$denied = ob_get_clean();
		$this->assertStringNotContainsString( 'Put the plugin', $denied );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		self::all_set_up();
		ob_start();
		Chess_Army_Knife_Policies::render_page();
		$allowed = ob_get_clean();
		$this->assertStringContainsString( 'Club data policy', $allowed );
		$this->assertStringContainsString( 'Safeguarding policy', $allowed );
		$this->assertStringContainsString( 'Privacy policy', $allowed );
	}

	public function test_the_screen_is_in_the_menu() {
		$area = Chess_Army_Knife_Menu::area( 'policies' );
		$this->assertNotNull( $area );
		$this->assertSame( Chess_Army_Knife_Policies::PAGE, $area['slug'] );
	}

	public function test_the_old_data_policy_block_is_gone() {
		$this->assertFalse( WP_Block_Type_Registry::get_instance()->is_registered( 'chess-army-knife/data-policy' ) );
	}

	public function test_only_an_administrator_or_a_membership_officer_may_change_how_long_details_are_kept() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( current_user_can( Chess_Army_Knife_Policies::REQUIRED_CAP ), 'An editor can edit the policy pages.' );
		$this->assertFalse( Chess_Army_Knife_Policies::can_set_retention(), 'But not decide when people\'s records are deleted.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( Chess_Army_Knife_Policies::can_set_retention() );

		$officer = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $officer )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $officer );
		$this->assertTrue( Chess_Army_Knife_Policies::can_set_retention() );
	}

	public function test_the_saved_lms_key_is_not_written_into_the_settings_page() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'lms_api_key'     => 'lmsk_very_secret',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		Chess_Army_Knife_Settings::render_page();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'lmsk_very_secret', $html );
		$this->assertStringContainsString( 'A key is saved', $html );
	}
}
