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

	public function test_each_policy_gets_a_draft_page_with_starting_text() {
		Chess_Army_Knife_Policies::create_missing();

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
		Chess_Army_Knife_Policies::create_missing();
		$first = Chess_Army_Knife_Policies::page( 'data' )->ID;
		Chess_Army_Knife_Policies::create_missing();

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
		Chess_Army_Knife_Policies::create_missing();

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

		Chess_Army_Knife_Policies::create_missing();

		$this->assertSame( $existing, Chess_Army_Knife_Policies::page( 'privacy' )->ID );
		$this->assertFalse( Chess_Army_Knife_Policies::can_restore( 'privacy' ) );
		$this->assertWPError( Chess_Army_Knife_Policies::restore( 'privacy' ) );
		$this->assertSame( 'Written by the owner.', get_post( $existing )->post_content );
	}

	public function test_putting_the_plugins_wording_back_replaces_edits_and_keeps_a_revision() {
		Chess_Army_Knife_Policies::create_missing();
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
		Chess_Army_Knife_Policies::create_missing();
		wp_delete_post( Chess_Army_Knife_Policies::page( 'data' )->ID, true );
		$this->assertNull( Chess_Army_Knife_Policies::page( 'data' ) );

		Chess_Army_Knife_Policies::restore( 'data' );

		$this->assertNotNull( Chess_Army_Knife_Policies::page( 'data' ) );
	}

	public function test_a_policy_page_has_a_link_only_once_it_is_published() {
		Chess_Army_Knife_Policies::create_missing();
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
		Chess_Army_Knife_Policies::create_missing();
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
