<?php
/**
 * Integration tests: membership types, members, the application form, the
 * permission that guards them, and the two blocks.
 *
 * @package Chess_Army_Knife
 */

class MembershipsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Membership_Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Member_History::install_table();
	}

	public function tear_down() {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Create a membership type.
	 *
	 * @param string $name   Name.
	 * @param int    $price  Price in pence.
	 * @param int    $months Length in months.
	 * @param array  $post   Other post fields.
	 * @return int
	 */
	private function membership_type( $name, $price, $months = 12, array $post = array() ) {
		$id = self::factory()->post->create(
			$post + array(
				'post_type'   => Chess_Army_Knife_Memberships::POST_TYPE,
				'post_title'  => $name,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, Chess_Army_Knife_Memberships::META_PRICE, $price );
		update_post_meta( $id, Chess_Army_Knife_Memberships::META_MONTHS, $months );
		return $id;
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

	private function ids_of( array $members ) {
		return wp_list_pluck( $members, 'id' );
	}

	private function names( array $members ) {
		return wp_list_pluck( $members, 'name' );
	}

	private function render( $block, array $attributes = array() ) {
		return do_blocks( '<!-- wp:chess-army-knife/' . $block . ' ' . wp_json_encode( (object) $attributes ) . ' /-->' );
	}

	/* -------------------------------------------------------------
	 * Membership types
	 * ------------------------------------------------------------- */

	public function test_the_membership_type_post_type_is_registered_and_private() {
		$this->assertTrue( post_type_exists( Chess_Army_Knife_Memberships::POST_TYPE ) );
		$this->assertFalse( is_post_type_viewable( Chess_Army_Knife_Memberships::POST_TYPE ), 'Types are advertised by a block, not on pages of their own.' );
	}

	public function test_types_are_ordered_by_page_order_and_only_published_are_offered() {
		$this->membership_type( 'Senior', 3000, 12, array( 'menu_order' => 2 ) );
		$this->membership_type( 'Junior', 1000, 12, array( 'menu_order' => 1 ) );
		$this->membership_type( 'Hidden', 500, 12, array( 'post_status' => 'draft' ) );

		$this->assertSame(
			array( 'Junior', 'Senior' ),
			$this->names(
				array_map(
					function ( $type ) {
						return array( 'name' => $type['name'] );
					},
					Chess_Army_Knife_Memberships::types()
				)
			)
		);
		$this->assertCount( 3, Chess_Army_Knife_Memberships::types( false ) );
	}

	public function test_type_data_carries_price_and_length() {
		$id   = $this->membership_type( 'Adult', 2550, 12 );
		$type = Chess_Army_Knife_Memberships::get_type( $id );

		$this->assertSame( 2550, $type['price'] );
		$this->assertSame( '£25.50', $type['price_label'] );
		$this->assertSame( 'per year', $type['period_label'] );
		$this->assertNull( Chess_Army_Knife_Memberships::get_type( self::factory()->post->create() ), 'An ordinary post is not a membership type.' );
	}

	public function test_saving_the_details_box_stores_the_price_in_pence() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $admin );
		$id = $this->membership_type( 'Adult', 100 );

		$_POST = array(
			Chess_Army_Knife_Memberships_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Memberships_Admin::NONCE_ACTION ),
			'chess_army_membership_description' => 'For over 18s',
			'chess_army_membership_price'       => '£25.50',
			'chess_army_membership_months'      => '12',
		);
		Chess_Army_Knife_Memberships_Admin::save( $id );

		$type = Chess_Army_Knife_Memberships::get_type( $id );
		$this->assertSame( 2550, $type['price'] );
		$this->assertSame( 'For over 18s', $type['description'] );

		// A price that can't be read leaves the old one, rather than making the membership free.
		$_POST['chess_army_membership_price'] = 'lots';
		Chess_Army_Knife_Memberships_Admin::save( $id );
		$this->assertSame( 2550, Chess_Army_Knife_Memberships::get_type( $id )['price'] );
	}

	public function test_saving_the_details_box_needs_a_valid_nonce_and_permission() {
		$id = $this->membership_type( 'Adult', 100 );

		$_POST = array(
			Chess_Army_Knife_Memberships_Admin::NONCE_FIELD => 'wrong',
			'chess_army_membership_price' => '99',
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Chess_Army_Knife_Memberships_Admin::save( $id );
		$this->assertSame( 100, Chess_Army_Knife_Memberships::get_type( $id )['price'], 'Bad nonce.' );

		// Administrators do not have the membership permission unless it was given to them.
		$_POST[ Chess_Army_Knife_Memberships_Admin::NONCE_FIELD ] = wp_create_nonce( Chess_Army_Knife_Memberships_Admin::NONCE_ACTION );
		Chess_Army_Knife_Memberships_Admin::save( $id );
		$this->assertSame( 100, Chess_Army_Knife_Memberships::get_type( $id )['price'], 'No permission.' );
	}

	/* -------------------------------------------------------------
	 * Permission
	 * ------------------------------------------------------------- */

	public function test_administrators_do_not_get_the_membership_permission_automatically() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertFalse( user_can( $admin, Chess_Army_Knife_Memberships::CAPABILITY ) );

		get_userdata( $admin )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $admin );

		$this->assertTrue( Chess_Army_Knife_Memberships::user_can_manage() );
	}

	public function test_the_permission_can_be_given_to_someone_who_is_not_an_administrator() {
		$treasurer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $treasurer )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $treasurer );

		$this->assertTrue( Chess_Army_Knife_Memberships::user_can_manage() );
		$this->assertFalse( current_user_can( 'manage_options' ) );
	}

	public function test_the_profile_checkbox_gives_and_takes_the_permission() {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$target = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $admin );

		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'update-user_' . $target ),
			Chess_Army_Knife_Memberships_Admin::PROFILE_FIELD => '1',
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test setup: copies the request the test builds.
		Chess_Army_Knife_Memberships_Admin::save_profile_field( $target );
		$this->assertTrue( user_can( $target, Chess_Army_Knife_Memberships::CAPABILITY ) );

		unset( $_POST[ Chess_Army_Knife_Memberships_Admin::PROFILE_FIELD ] );
		Chess_Army_Knife_Memberships_Admin::save_profile_field( $target );
		$this->assertFalse( user_can( $target, Chess_Army_Knife_Memberships::CAPABILITY ) );
	}

	public function test_only_someone_who_can_promote_users_can_change_the_permission() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$_POST    = array(
			'_wpnonce' => wp_create_nonce( 'update-user_' . $editor ),
			Chess_Army_Knife_Memberships_Admin::PROFILE_FIELD => '1',
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test setup: copies the request the test builds.
		Chess_Army_Knife_Memberships_Admin::save_profile_field( $editor );

		$this->assertFalse( user_can( $editor, Chess_Army_Knife_Memberships::CAPABILITY ), 'Nobody can give themselves the permission.' );
	}

	public function test_member_actions_are_refused_without_the_permission() {
		$id = $this->member();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array( 'member' => $id );

		foreach ( array( 'handle_delete', 'handle_status', 'handle_save' ) as $handler ) {
			try {
				Chess_Army_Knife_Members_Page::$handler();
				$this->fail( "$handler ran without the permission." );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $e->getCode() === 0 ? 403 : $e->getCode() );
			}
		}

		$this->assertNotNull( Chess_Army_Knife_Membership_Store::get_member( $id ) );
	}

	/* -------------------------------------------------------------
	 * Members
	 * ------------------------------------------------------------- */

	public function test_a_member_round_trips_through_the_store() {
		$type = $this->membership_type( 'Adult', 2500 );
		$id   = $this->member(
			array(
				'name'               => 'Grace Hopper',
				'membership_type_id' => $type,
				'type_name'          => 'Adult',
				'expiry_date'        => '2027-08-31',
				'source'             => Chess_Army_Knife_Membership_Store::SOURCE_MANUAL,
			)
		);

		$member = Chess_Army_Knife_Membership_Store::get_member( $id );

		$this->assertSame( 'Hopper, Grace', $member['name'] );
		$this->assertSame( $type, $member['membership_type_id'] );
		$this->assertSame( '2027-08-31', $member['expiry_date'] );
		$this->assertSame( '', $member['start_date'], 'A missing date reads back as an empty string.' );
		$this->assertSame( 'manual', $member['source'] );

		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'id'    => $id,
				'phone' => '01234',
			)
		);
		$this->assertSame( '01234', Chess_Army_Knife_Membership_Store::get_member( $id )['phone'] );
		$this->assertSame( 'Hopper, Grace', Chess_Army_Knife_Membership_Store::get_member( $id )['name'], 'An update changes only the given fields.' );

		Chess_Army_Knife_Membership_Store::delete_member( $id );
		$this->assertNull( Chess_Army_Knife_Membership_Store::get_member( $id ) );
	}

	public function test_a_new_member_defaults_to_a_pending_application_from_the_form() {
		$id     = Chess_Army_Knife_Membership_Store::save_member( array( 'name' => 'Newcomer' ) );
		$member = Chess_Army_Knife_Membership_Store::get_member( $id );

		$this->assertSame( 'pending', $member['status'] );
		$this->assertSame( 'form', $member['source'] );
	}

	public function test_views_separate_current_pending_expired_and_closed_members() {
		$today = current_time( 'Y-m-d' );

		$this->member( array( 'name' => 'Current no expiry' ) );
		$this->member(
			array(
				'name'        => 'Current until today',
				'expiry_date' => $today,
			)
		);
		$this->member(
			array(
				'name'        => 'Lapsed',
				'expiry_date' => gmdate( 'Y-m-d', strtotime( '-1 day', strtotime( $today ) ) ),
			)
		);
		$this->member(
			array(
				'name'   => 'Applicant',
				'status' => 'pending',
			)
		);
		$this->member(
			array(
				'name'   => 'Declined',
				'status' => 'rejected',
			)
		);
		$this->member(
			array(
				'name'   => 'Left',
				'status' => 'cancelled',
			)
		);

		$view = function ( $view ) {
			$names = $this->names( Chess_Army_Knife_Membership_Store::get_members( array( 'view' => $view ) ) );
			sort( $names );
			return $names;
		};

		$this->assertSame( array( 'Current no expiry', 'Current until today' ), $view( 'active' ) );
		$this->assertSame( array( 'Applicant' ), $view( 'pending' ) );
		$this->assertSame( array( 'Lapsed' ), $view( 'expired' ) );
		$this->assertSame( array( 'Declined', 'Left' ), $view( 'closed' ) );
		$this->assertCount( 6, Chess_Army_Knife_Membership_Store::get_members() );

		$this->assertSame( 2, Chess_Army_Knife_Membership_Store::count_view( 'active' ) );
		$this->assertSame( 1, Chess_Army_Knife_Membership_Store::count_view( 'pending' ) );
		$this->assertSame( 6, Chess_Army_Knife_Membership_Store::count_view( 'all' ) );
	}

	public function test_members_can_be_searched_by_name_or_email_without_sql_injection() {
		$this->member(
			array(
				'name'  => 'Ada Lovelace',
				'email' => 'ada@example.test',
			)
		);
		$this->member(
			array(
				'name'  => "Conan O'Brien",
				'email' => 'conan@club.test',
			)
		);

		$search = function ( $text ) {
			return $this->names( Chess_Army_Knife_Membership_Store::get_members( array( 'search' => $text ) ) );
		};

		$this->assertSame( array( 'Lovelace, Ada' ), $search( 'lovel' ) );
		$this->assertSame( array( "O'Brien, Conan" ), $search( 'club.test' ) );
		$this->assertSame( array( "Conan O'Brien" ), $search( "O'Brien" ) );
		$this->assertSame( array(), $search( "' OR 1=1 --" ) );
		$this->assertSame( array(), $search( '%' ), 'A percent sign is not a wildcard.' );
	}

	public function test_approving_starts_today_and_runs_for_the_length_of_the_type() {
		$type = $this->membership_type( 'Adult', 2500, 12 );
		$id   = $this->member(
			array(
				'status'             => 'pending',
				'membership_type_id' => $type,
			)
		);

		$this->assertTrue( Chess_Army_Knife_Membership_Store::approve( $id ) );

		$today  = current_time( 'Y-m-d' );
		$member = Chess_Army_Knife_Membership_Store::get_member( $id );
		$this->assertSame( 'active', $member['status'] );
		$this->assertSame( $today, $member['start_date'] );
		$this->assertSame( Chess_Army_Knife_Memberships::expiry_from( $today, 12 ), $member['expiry_date'] );
		$this->assertFalse( Chess_Army_Knife_Membership_Store::approve( 999999 ) );
	}

	public function test_approving_keeps_dates_that_were_already_set_and_never_expires_a_lifetime_type() {
		$lifetime = $this->membership_type( 'Life', 10000, 0 );
		$id       = $this->member(
			array(
				'status'             => 'pending',
				'membership_type_id' => $lifetime,
			)
		);
		Chess_Army_Knife_Membership_Store::approve( $id );
		$this->assertSame( '', Chess_Army_Knife_Membership_Store::get_member( $id )['expiry_date'] );

		$dated = $this->member(
			array(
				'status'      => 'pending',
				'start_date'  => '2026-09-01',
				'expiry_date' => '2027-06-30',
			)
		);
		Chess_Army_Knife_Membership_Store::approve( $dated );
		$member = Chess_Army_Knife_Membership_Store::get_member( $dated );
		$this->assertSame( '2026-09-01', $member['start_date'] );
		$this->assertSame( '2027-06-30', $member['expiry_date'] );
	}

	/* -------------------------------------------------------------
	 * Admin screens
	 * ------------------------------------------------------------- */

	private function manager() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );
	}

	private function page_html() {
		ob_start();
		Chess_Army_Knife_Members_Page::render_page();
		return ob_get_clean();
	}

	public function test_the_members_page_opens_on_pending_applications_and_escapes_details() {
		$this->manager();
		$this->member( array( 'name' => 'Current Member' ) );
		$this->member(
			array(
				'name'   => '<script>alert(1)</script>',
				'status' => 'pending',
			)
		);

		$html = $this->page_html();

		$this->assertStringContainsString( 'Approve', $html );
		$this->assertStringContainsString( 'Decline', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( 'Member, Current', $html );

		$_GET = array( 'view' => 'active' );
		$this->assertStringContainsString( 'Member, Current', $this->page_html() );
	}

	public function test_the_members_page_opens_on_current_members_when_nothing_is_pending() {
		$this->manager();
		$this->member( array( 'name' => 'Current Member' ) );

		$this->assertStringContainsString( 'Member, Current', $this->page_html() );
	}

	public function test_the_members_page_shows_the_add_and_edit_forms() {
		$this->manager();
		$type = $this->membership_type( 'Adult', 2500 );
		$id   = $this->member(
			array(
				'name'               => 'Grace Hopper',
				'membership_type_id' => $type,
			)
		);

		$_GET = array( 'edit' => 'new' );
		$add  = $this->page_html();
		$this->assertStringContainsString( 'Add a member', $add );
		$this->assertStringContainsString( 'Adult (£25 per year)', $add );

		$this->assertStringContainsString( 'name="guardian_email"', $add );
		$this->assertStringContainsString( 'name="newsletter"', $add );
		$this->assertStringContainsString( 'name="whatsapp"', $add );

		$_GET = array( 'edit' => (string) $id );
		$edit = $this->page_html();
		$this->assertStringContainsString( 'value="Grace Hopper"', $edit );
		$this->assertStringContainsString( 'MEM-' . $id, $edit );
	}

	private function build_menu() {
		global $menu, $submenu;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$menu    = array();
		$submenu = array();
		do_action( 'admin_menu' );

		return $submenu;
	}

	public function test_one_menu_lists_every_screen_for_everyone_and_opens_on_the_overview() {
		$expected = array(
			Chess_Army_Knife_Memberships::MENU_SLUG,
			Chess_Army_Knife_Dashboard_Page::SLUG,
			'edit.php?post_type=' . Chess_Army_Knife_Announcements::POST_TYPE,
			'edit.php?post_type=' . Chess_Army_Knife_Teams::POST_TYPE,
			Chess_Army_Knife_Tournaments_Page::SLUG,
			'edit.php?post_type=' . Chess_Army_Knife_Events::POST_TYPE,
			Chess_Army_Knife_Templates::PAGE,
			Chess_Army_Knife_Settings::PAGE,
		);

		// A member with no club permissions still sees every screen: each one explains what it needs.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$submenu = $this->build_menu();
		$slugs   = wp_list_pluck( $submenu[ Chess_Army_Knife_Menu::SLUG ], 2 );

		$this->assertSame( Chess_Army_Knife_Menu::SLUG, reset( $slugs ), 'The menu opens on the Overview.' );
		foreach ( $expected as $slug ) {
			$this->assertContains( $slug, $slugs );
		}
		$this->assertArrayNotHasKey( Chess_Army_Knife_Memberships::MENU_SLUG, $submenu, 'There is no separate Memberships menu.' );
		$this->assertArrayNotHasKey( 'chess-army-teams', $submenu, 'There is no separate Teams menu.' );
		foreach ( array( Chess_Army_Knife_Member_Checks_Page::SLUG, Chess_Army_Knife_Renewals_Page::SLUG, Chess_Army_Knife_LMS_Players::PAGE, Chess_Army_Knife_Do_Not_Record::PAGE, 'edit.php?post_type=' . Chess_Army_Knife_Memberships::POST_TYPE, Chess_Army_Knife_Clubs::PAGE, Chess_Army_Knife_Squad_Review::PAGE, Chess_Army_Knife_Events_Import::PAGE, Chess_Army_Knife_Policies::PAGE ) as $tab ) {
			$this->assertNotContains( $tab, $slugs, 'This is a tab of another screen, not a menu item.' );
		}
		$this->assertNotContains( 'edit.php?post_type=' . Chess_Army_Knife_Team_Groups::POST_TYPE, $slugs, 'Groups is a tab of Teams, not a menu item.' );
		$this->assertNotContains( Chess_Army_Knife_Selection_Page::SLUG, $slugs, 'Selection is a tab of Teams, not a menu item.' );
		$this->assertNotContains( Chess_Army_Knife_Team_Overview::PAGE, $slugs, 'Overview is a tab of Teams, not a menu item.' );
	}

	public function test_screens_a_user_may_not_use_explain_what_they_need_instead_of_failing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$screens = array(
			array( 'Chess_Army_Knife_Members_Page', 'Manage members' ),
			array( 'Chess_Army_Knife_Dashboard_Page', 'manage members' ),
			array( 'Chess_Army_Knife_Member_Checks_Page', 'manage members' ),
			array( 'Chess_Army_Knife_Renewals_Page', 'manage members' ),
			array( 'Chess_Army_Knife_Selection_Page', 'captain' ),
			array( 'Chess_Army_Knife_Events_Import', 'club teams' ),
			array( 'Chess_Army_Knife_Tournaments_Page', 'administrator' ),
			array( 'Chess_Army_Knife_Templates', 'administrator' ),
			array( 'Chess_Army_Knife_Settings', 'administrator' ),
		);
		foreach ( $screens as $screen ) {
			ob_start();
			call_user_func( array( $screen[0], 'render_page' ) );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'You do not have permission to use this page.', $html, $screen[0] );
			$this->assertStringContainsStringIgnoringCase( $screen[1], $html, $screen[0] );
		}
	}

	public function test_a_user_with_the_permission_sees_the_screen_not_the_notice() {
		$this->manager();

		ob_start();
		Chess_Army_Knife_Members_Page::render_page();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'You do not have permission', $html );
		$this->assertStringContainsString( 'Add member', $html );
	}

	public function test_the_overview_says_which_screens_the_user_can_and_cannot_use() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		Chess_Army_Knife_Menu::render_overview();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Needs: Manage members', $html );
		$this->assertSame( 1, substr_count( $html, 'You can use this' ), 'Only the Block Help, which is for everyone.' );

		$this->manager();
		ob_start();
		Chess_Army_Knife_Menu::render_overview();
		$html = ob_get_clean();
		$this->assertGreaterThan( 1, substr_count( $html, 'You can use this' ), 'The member screens as well.' );
	}

	public function test_the_members_list_shows_a_juniors_parent_as_the_contact_and_who_agreed_to_what() {
		$this->manager();
		$this->member(
			array(
				'name'                  => 'Junior Player',
				'email'                 => '',
				'guardian_name'         => 'Charles Player',
				'guardian_email'        => 'charles@example.test',
				'guardian_phone'        => '07700 900123',
				'newsletter_consent_at' => '2026-09-01 10:00:00',
			)
		);

		$html = $this->page_html();

		$this->assertStringContainsString( 'charles@example.test', $html );
		$this->assertStringContainsString( '07700 900123', $html );
		$this->assertStringContainsString( 'Parent or guardian: Charles Player', $html );
		$this->assertStringContainsString( 'Newsletter', $html );
		$this->assertStringNotContainsString( 'WhatsApp', $html );
	}

	public function test_a_recorded_consent_keeps_its_time_until_it_is_withdrawn() {
		$previous = Chess_Army_Knife_Membership_Store::get_member( $this->member( array( 'newsletter_consent_at' => '2026-01-01 09:00:00' ) ) );
		$ticked   = array(
			'newsletter_consent_at' => '2026-09-29 12:00:00',
			'whatsapp_consent_at'   => '2026-09-29 12:00:00',
		);

		$kept = Chess_Army_Knife_Members_Page::keep_recorded_consents( $ticked, $previous );
		$this->assertSame( '2026-01-01 09:00:00', $kept['newsletter_consent_at'], 'Still agreed: the original time stays.' );
		$this->assertSame( '2026-09-29 12:00:00', $kept['whatsapp_consent_at'], 'Newly agreed: now.' );

		$withdrawn = Chess_Army_Knife_Members_Page::keep_recorded_consents(
			array(
				'newsletter_consent_at' => null,
				'whatsapp_consent_at'   => null,
			),
			$previous
		);
		$this->assertNull( $withdrawn['newsletter_consent_at'] );

		$this->assertSame( $ticked, Chess_Army_Knife_Members_Page::keep_recorded_consents( $ticked, null ), 'A new member has nothing to keep.' );
	}

	public function test_the_members_page_tells_someone_without_the_permission_what_they_need() {
		$this->member( array( 'name' => 'Grace Hopper' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		Chess_Army_Knife_Members_Page::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'You do not have permission to use this page.', $html );
		$this->assertStringNotContainsString( 'Grace Hopper', $html, 'Nobody\'s details are shown.' );
	}

	public function test_editing_a_member_that_does_not_exist_is_refused() {
		$this->manager();
		$_GET = array( 'edit' => '999999' );

		$this->expectException( WPDieException::class );
		Chess_Army_Knife_Members_Page::render_page();
	}

	/* -------------------------------------------------------------
	 * The application form
	 * ------------------------------------------------------------- */

	private function application( array $overrides = array() ) {
		return $overrides + array(
			Chess_Army_Knife_Membership_Form::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Membership_Form::ACTION ),
			'name'                                        => 'Ada Lovelace',
			'email'                                       => 'ada@example.test',
			'membership_type_id'                          => (string) $this->type_id,
			'consent'                                     => '1',
		);
	}

	/** @var int Membership type the form tests apply for. */
	private $type_id = 0;

	public function test_a_valid_adult_application_is_saved_as_a_pending_member() {
		$this->type_id = $this->membership_type( 'Adult', 2500 );

		$id = Chess_Army_Knife_Membership_Form::submit(
			$this->application(
				array(
					'phone'         => '01234',
					'ecf_code'      => '12345j',
					'date_of_birth' => '1980-05-01',
				)
			)
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$member = Chess_Army_Knife_Membership_Store::get_member( $id );
		$this->assertSame( 'pending', $member['status'] );
		$this->assertSame( 'form', $member['source'] );
		$this->assertSame( 'Adult', $member['type_name'] );
		$this->assertSame( '12345J', $member['ecf_code'] );
		$this->assertSame( '', $member['date_of_birth'], 'An adult\'s date of birth is not kept.' );
		$this->assertSame( array( 'Lovelace, Ada' ), $this->names( Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'pending' ) ) ) );
	}

	public function test_a_junior_application_is_saved_with_the_parents_details_instead_of_their_own() {
		$this->type_id = $this->membership_type( 'Junior', 1000 );

		$id = Chess_Army_Knife_Membership_Form::submit(
			$this->application(
				array(
					'name'           => 'Junior Player',
					'is_junior'      => '1',
					'date_of_birth'  => '2015-05-01',
					'guardian_name'  => 'Charles Player',
					'guardian_email' => 'charles@example.test',
					'guardian_phone' => '07700 900123',
					'phone'          => '07700 900456',
					'newsletter'     => '1',
					'whatsapp'       => '1',
				)
			)
		);

		$member = Chess_Army_Knife_Membership_Store::get_member( $id );
		$this->assertSame( '', $member['email'], 'The junior\'s own email is not kept.' );
		$this->assertSame( '', $member['phone'] );
		$this->assertSame( '2015-05-01', $member['date_of_birth'] );
		$this->assertSame( 'charles@example.test', $member['guardian_email'] );
		$this->assertSame( 'charles@example.test', Chess_Army_Knife_Membership_Store::contact_email( $member ) );
		$this->assertNotSame( '', $member['newsletter_consent_at'] );
		$this->assertNotSame( '', $member['whatsapp_consent_at'] );
		$this->assertSame( array( $id ), $this->ids_of( Chess_Army_Knife_Membership_Store::get_members( array( 'search' => 'charles@' ) ) ), 'A junior can be found by their parent\'s email.' );
	}

	public function test_a_junior_application_without_a_parent_is_refused() {
		$this->type_id = $this->membership_type( 'Junior', 1000 );

		$result = Chess_Army_Knife_Membership_Form::submit(
			$this->application(
				array(
					'is_junior'     => '1',
					'date_of_birth' => '2015-05-01',
				)
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'member_guardian', $result->get_error_code() );
		$this->assertSame( 0, Chess_Army_Knife_Membership_Store::count_view( 'all' ) );
	}

	public function test_an_application_cannot_choose_its_own_status_or_dates() {
		$this->type_id = $this->membership_type( 'Junior', 1000 );

		$id = Chess_Army_Knife_Membership_Form::submit(
			$this->application(
				array(
					'status'      => 'active',
					'expiry_date' => '2099-01-01',
					'notes'       => 'x',
				)
			)
		);

		$member = Chess_Army_Knife_Membership_Store::get_member( $id );
		$this->assertSame( 'pending', $member['status'] );
		$this->assertSame( '', $member['expiry_date'] );
		$this->assertSame( '', $member['notes'] );
	}

	public function test_an_application_for_a_draft_type_is_refused() {
		$this->type_id = $this->membership_type( 'Hidden', 1000, 12, array( 'post_status' => 'draft' ) );

		$result = Chess_Army_Knife_Membership_Form::submit( $this->application() );

		$this->assertWPError( $result );
		$this->assertSame( 'member_type', $result->get_error_code() );
		$this->assertSame( 0, Chess_Army_Knife_Membership_Store::count_view( 'all' ) );
	}

	public function test_a_bad_nonce_and_a_honeypot_save_nothing() {
		$this->type_id = $this->membership_type( 'Junior', 1000 );

		$bad = Chess_Army_Knife_Membership_Form::submit( $this->application( array( Chess_Army_Knife_Membership_Form::NONCE_FIELD => 'bad' ) ) );
		$bot = Chess_Army_Knife_Membership_Form::submit( $this->application( array( Chess_Army_Knife_Membership_Form::HONEYPOT => 'http://spam.test' ) ) );

		$this->assertSame( 'expired', $bad->get_error_code() );
		$this->assertSame( 0, $bot );
		$this->assertSame( 0, Chess_Army_Knife_Membership_Store::count_view( 'all' ) );
	}

	public function test_one_visitor_can_only_apply_a_few_times_an_hour() {
		$this->type_id          = $this->membership_type( 'Junior', 1000 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
		delete_transient( 'chess_army_knife_apply_' . md5( '203.0.113.50' ) );

		for ( $i = 0; $i < Chess_Army_Knife_Membership_Form::MAX_PER_HOUR; $i++ ) {
			$this->assertGreaterThan( 0, Chess_Army_Knife_Membership_Form::submit( $this->application() ) );
		}
		$result = Chess_Army_Knife_Membership_Form::submit( $this->application() );

		$this->assertSame( 'throttled', $result->get_error_code() );
		delete_transient( 'chess_army_knife_apply_' . md5( '203.0.113.50' ) );
	}

	/* -------------------------------------------------------------
	 * Blocks
	 * ------------------------------------------------------------- */

	public function test_both_blocks_are_registered() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'chess-army-knife/memberships' ) );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'chess-army-knife/membership-form' ) );
	}

	public function test_the_memberships_block_advertises_types_with_prices_and_how_to_pay() {
		$this->membership_type( 'Junior', 1000, 12, array( 'menu_order' => 1 ) );
		$adult = $this->membership_type( 'Adult', 2550, 12, array( 'menu_order' => 2 ) );
		update_post_meta( $adult, Chess_Army_Knife_Memberships::META_DESCRIPTION, 'For over 18s' );
		$this->membership_type( 'Hidden', 500, 12, array( 'post_status' => 'draft' ) );
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'membership_payment_info' => "Sort code 00-00-00\n<b>Cash</b> at the club",
			)
		);

		$html = $this->render( 'memberships', array( 'joinUrl' => 'https://example.test/join/' ) );

		$this->assertStringContainsString( 'Junior', $html );
		$this->assertStringContainsString( '£10', $html );
		$this->assertStringContainsString( '£25.50', $html );
		$this->assertStringContainsString( 'per year', $html );
		$this->assertStringContainsString( 'For over 18s', $html );
		$this->assertStringNotContainsString( 'Hidden', $html );
		$this->assertLessThan( strpos( $html, 'Adult' ), strpos( $html, 'Junior' ), 'In page order.' );
		$this->assertStringContainsString( 'Sort code 00-00-00', $html );
		$this->assertStringContainsString( '&lt;b&gt;Cash&lt;/b&gt;', $html, 'Payment details are plain text.' );
		$this->assertStringContainsString( 'https://example.test/join/?membership_type=' . $adult . '#' . Chess_Army_Knife_Membership_Form::ANCHOR, $html );
	}

	public function test_the_memberships_block_can_hide_prices_and_payment_details() {
		$this->membership_type( 'Junior', 1000 );
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'membership_payment_info' => 'Sort code 00-00-00',
			)
		);

		$html = $this->render(
			'memberships',
			array(
				'showPrice'       => false,
				'showPaymentInfo' => false,
			)
		);

		$this->assertStringContainsString( 'Junior', $html );
		$this->assertStringNotContainsString( '£10', $html );
		$this->assertStringNotContainsString( 'Sort code', $html );
		$this->assertStringNotContainsString( 'Apply for this membership', $html, 'No link without a form page.' );
	}

	public function test_the_memberships_block_says_so_when_there_is_nothing_to_advertise() {
		$this->assertStringContainsString( 'No memberships are available at the moment.', $this->render( 'memberships' ) );
		$this->assertStringContainsString( 'Coming soon', $this->render( 'memberships', array( 'emptyMessage' => 'Coming soon' ) ) );
	}

	public function test_a_free_membership_says_free_without_a_period() {
		$this->membership_type( 'Guest', 0, 12 );

		$html = $this->render( 'memberships' );

		$this->assertStringContainsString( 'Free', $html );
		$this->assertStringNotContainsString( 'per year', $html );
	}

	public function test_the_form_block_shows_the_form_with_its_protections() {
		$type = $this->membership_type( 'Junior', 1000 );
		$this->membership_type( 'Hidden', 500, 12, array( 'post_status' => 'draft' ) );

		$html = $this->render( 'membership-form' );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'value="' . Chess_Army_Knife_Membership_Form::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="' . Chess_Army_Knife_Membership_Form::NONCE_FIELD . '"', $html );
		$this->assertStringContainsString( 'name="' . Chess_Army_Knife_Membership_Form::HONEYPOT . '"', $html );
		$this->assertStringContainsString( 'name="consent"', $html );
		foreach ( array( 'is_junior', 'guardian_name', 'guardian_email', 'guardian_phone', 'junior_contact' ) as $field ) {
			$this->assertStringContainsString( 'name="' . $field . '"', $html );
		}
		$this->assertStringContainsString( 'value="' . $type . '"', $html );
		$this->assertStringNotContainsString( 'Hidden', $html );
	}

	public function test_the_form_block_preselects_the_type_from_the_advertised_link() {
		$this->membership_type( 'Junior', 1000 );
		$adult = $this->membership_type( 'Adult', 2500 );
		$_GET  = array( 'membership_type' => (string) $adult );

		$html = $this->render( 'membership-form' );

		$this->assertMatchesRegularExpression( '/<option value="' . $adult . '"\s+selected=\'selected\'/', $html );
	}

	public function test_the_form_block_is_closed_when_no_membership_is_on_offer() {
		$html = $this->render( 'membership-form' );

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'not open at the moment', $html );
	}

	public function test_the_form_block_thanks_the_applicant_with_a_reference_and_how_to_pay() {
		$this->membership_type( 'Junior', 1000 );
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'membership_payment_info' => 'Sort code 00-00-00',
			)
		);
		$_GET = array( 'cak_membership_applied' => '42' );

		$html = $this->render( 'membership-form' );

		$this->assertStringContainsString( 'Thank you', $html );
		$this->assertStringContainsString( 'MEM-42', $html );
		$this->assertStringContainsString( 'Sort code 00-00-00', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_the_form_block_shows_an_error_and_never_reflects_arbitrary_text() {
		$this->membership_type( 'Junior', 1000 );
		$_GET = array( 'cak_membership_error' => 'member_email' );
		$this->assertStringContainsString( 'Please enter a valid email address.', $this->render( 'membership-form' ) );

		$_GET = array( 'cak_membership_error' => '<script>alert(1)</script>' );
		$html = $this->render( 'membership-form' );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'Something went wrong', $html );
	}

	public function test_settings_keep_payment_info_as_plain_text() {
		$clean = Chess_Army_Knife_Settings::sanitize( array( 'membership_payment_info' => "Pay <script>x</script> by\ntransfer" ) );

		$this->assertStringNotContainsString( '<script>', $clean['membership_payment_info'] );
		$this->assertStringContainsString( "\n", $clean['membership_payment_info'] );
	}
}
