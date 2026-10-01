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

	public function test_the_details_asked_for_are_saved_in_settings_and_used_in_the_text() {
		Chess_Army_Knife_Policies::set_up(
			'safeguarding',
			true,
			array(
				'safeguarding_officer' => 'Sam Example',
				'safeguarding_email'   => 'safe@club.test',
				'safeguarding_phone'   => '07700 900123',
			)
		);

		$options = Chess_Army_Knife_Settings::get_options();
		$this->assertSame( 'Sam Example', $options['safeguarding_officer'] );
		$this->assertSame( '07700 900123', $options['safeguarding_phone'] );
		$content = Chess_Army_Knife_Policies::page( 'safeguarding' )->post_content;
		$this->assertStringContainsString( 'Sam Example', $content );
		$this->assertStringContainsString( 'safe@club.test / 07700 900123', $content );
	}

	public function test_saving_the_details_keeps_the_rest_of_the_settings() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'     => 0,
				'default_org_id'      => '613',
				'default_max_players' => 20,
			)
		);

		Chess_Army_Knife_Policies::set_up( 'data', true, array( 'data_contact_email' => 'secretary@club.test' ) );

		$options = Chess_Army_Knife_Settings::get_options();
		$this->assertSame( '613', $options['default_org_id'] );
		$this->assertSame( 20, $options['default_max_players'] );
		$this->assertSame( 'secretary@club.test', $options['data_contact_email'] );
	}

	public function test_a_new_policy_is_not_finished_until_it_has_been_marked_as_reviewed() {
		Chess_Army_Knife_Policies::set_up( 'data', true );
		$this->assertSame( 'review', Chess_Army_Knife_Policies::status( 'data' ) );
		$this->assertStringContainsString( 'not yet reviewed', Chess_Army_Knife_Policies::attention_notice() );

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
		$this->assertStringNotContainsString( 'chess_army_policy_link', wp_json_encode( self::guide_text() ) );

		Chess_Army_Knife_Policies::set_up( 'data', true );
		$this->assertStringContainsString( 'chess_army_policy_link', wp_json_encode( self::guide_text() ) );
	}

	private static function guide_text() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' );
		remove_all_actions( 'admin_init' );
		do_action( 'admin_init' );
		Chess_Army_Knife_Membership_Privacy::add_policy_content();
		return WP_Privacy_Policy_Content::get_suggested_policy_text();
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
		$this->assertStringContainsString( 'If you are worried', Chess_Army_Knife_Policies::page( 'safeguarding' )->post_content );
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

		$this->assertStringContainsString( 'If you are worried', get_post( $id )->post_content );
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

	public function test_the_data_policy_starts_from_the_settings() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'member_retention_months' => 18,
				'data_contact_email'      => 'secretary@club.test',
			)
		);

		$text = Chess_Army_Knife_Policies::content( 'data' );

		$this->assertStringContainsString( 'for 18 months afterwards', $text );
		$this->assertStringContainsString( 'secretary@club.test', $text );
	}

	public function test_the_safeguarding_policy_names_the_officer_or_leaves_a_gap_to_fill() {
		$this->assertStringContainsString( '[add the name of your safeguarding officer]', Chess_Army_Knife_Policies::content( 'safeguarding' ) );

		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'      => 0,
				'safeguarding_officer' => 'Sam Example',
				'safeguarding_email'   => 'safe@club.test',
			)
		);
		$text = Chess_Army_Knife_Policies::content( 'safeguarding' );

		$this->assertStringContainsString( 'Sam Example', $text );
		$this->assertStringContainsString( 'safe@club.test', $text );
		$this->assertStringNotContainsString( '[add the name', $text );
	}

	public function test_the_safeguarding_settings_are_cleaned() {
		$clean = Chess_Army_Knife_Settings::sanitize(
			array(
				'safeguarding_officer' => '  <b>Sam</b> Example ',
				'safeguarding_email'   => 'not an email',
			)
		);
		$this->assertSame( 'Sam Example', $clean['safeguarding_officer'] );
		$this->assertSame( '', $clean['safeguarding_email'] );
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
}
