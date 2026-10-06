<?php
/**
 * Integration tests: tagging members in photos, finding a member's photos, and how that
 * fits with exporting and erasing personal data.
 *
 * @package Chess_Army_Knife
 */

class MemberPhotosTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Membership_Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Membership_Seasons::install_tables();
	}

	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	private function manager() {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );
		return $user;
	}

	private function member( $name, array $extra = array() ) {
		return Chess_Army_Knife_Membership_Store::save_member(
			$extra + array(
				'name'   => $name,
				'status' => 'active',
			)
		);
	}

	private function photo( $title = 'Club night', $mime = 'image/jpeg' ) {
		$id = self::factory()->attachment->create(
			array(
				'post_title'     => $title,
				'post_mime_type' => $mime,
				'post_status'    => 'inherit',
			)
		);
		// WordPress only treats an attachment as an image once it has a file.
		update_attached_file( $id, sanitize_title( $title ) . ( 'image/jpeg' === $mime ? '.jpg' : '.pdf' ) );
		return $id;
	}

	/**
	 * Submit the tagging field the way the Media Library does.
	 *
	 * @param int   $photo   Photo id.
	 * @param array $members Ticked member ids, or null to leave the field out of the form.
	 */
	private function submit_tags( $photo, $members ) {
		$attachment = array();
		if ( null !== $members ) {
			$attachment[ Chess_Army_Knife_Member_Photos::SHOWN ] = '1';
			$attachment[ Chess_Army_Knife_Member_Photos::FIELD ] = array_map( 'strval', $members );
		}
		apply_filters( 'attachment_fields_to_save', array( 'ID' => $photo ), $attachment );
	}

	private function field_html( $photo ) {
		$fields = apply_filters( 'attachment_fields_to_edit', array(), get_post( $photo ) );
		return isset( $fields['chess_army_members'] ) ? $fields['chess_army_members']['html'] : null;
	}

	/* -------------------------------------------------------------
	 * Tagging
	 * ------------------------------------------------------------- */

	public function test_the_checklist_offers_members_and_guests_to_people_who_manage_members() {
		$this->manager();
		$this->member( 'Member, Ada' );
		$this->member( 'Guest, Gus', array( 'status' => 'nonmember' ) );
		$this->member( 'Pat Applicant', array( 'status' => 'pending' ) );
		$this->member( 'Lapsed Member', array( 'expiry_date' => '2020-01-01' ) );
		$photo = $this->photo();

		$html = $this->field_html( $photo );

		$this->assertStringContainsString( 'Member, Ada', $html );
		$this->assertStringContainsString( 'Guest, Gus', $html );
		$this->assertStringNotContainsString( 'Applicant, Pat', $html );
		$this->assertStringNotContainsString( 'Member, Lapsed', $html );
		$this->assertStringContainsString( 'name="attachments[' . $photo . '][chess_army_members][]"', $html );
	}

	public function test_someone_already_tagged_stays_in_the_checklist_even_if_no_longer_a_member() {
		$this->manager();
		$lapsed = $this->member( 'Lapsed Member', array( 'expiry_date' => '2020-01-01' ) );
		$photo  = $this->photo();
		Chess_Army_Knife_Member_Photos::set_members( $photo, array( $lapsed ) );

		$html = $this->field_html( $photo );

		$this->assertStringContainsString( 'Member, Lapsed', $html );
		$this->assertMatchesRegularExpression( '/value="' . $lapsed . '"\s+checked=\'checked\'/', $html );
	}

	public function test_the_checklist_escapes_names() {
		$this->manager();
		$this->member( '<script>alert(1)</script>' );

		$html = $this->field_html( $this->photo() );

		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_the_checklist_is_only_for_managers_and_only_on_photos() {
		$this->member( 'Member, Ada' );
		$photo = $this->photo();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertNull( $this->field_html( $photo ), 'An editor without the membership permission does not see it.' );

		$this->manager();
		$this->assertNull( $this->field_html( $this->photo( 'Minutes', 'application/pdf' ) ), 'Documents are not photos.' );
		$this->assertNotNull( $this->field_html( $photo ) );
	}

	public function test_ticking_members_tags_the_photo_and_unticking_removes_them() {
		$this->manager();
		$ada   = $this->member( 'Member, Ada' );
		$gus   = $this->member( 'Guest, Gus', array( 'status' => 'nonmember' ) );
		$photo = $this->photo();

		$this->submit_tags( $photo, array( $ada, $gus ) );
		$this->assertEqualSets( array( $ada, $gus ), Chess_Army_Knife_Member_Photos::member_ids( $photo ) );

		$this->submit_tags( $photo, array( $gus ) );
		$this->assertSame( array( $gus ), Chess_Army_Knife_Member_Photos::member_ids( $photo ) );

		$this->submit_tags( $photo, array() );
		$this->assertSame( array(), Chess_Army_Knife_Member_Photos::member_ids( $photo ), 'Unticking everyone clears the tags.' );
	}

	public function test_a_form_that_did_not_show_the_checklist_leaves_the_tags_alone() {
		$this->manager();
		$ada   = $this->member( 'Member, Ada' );
		$photo = $this->photo();
		Chess_Army_Knife_Member_Photos::set_members( $photo, array( $ada ) );

		$this->submit_tags( $photo, null );

		$this->assertSame( array( $ada ), Chess_Army_Knife_Member_Photos::member_ids( $photo ) );
	}

	public function test_only_members_that_exist_can_be_tagged_and_duplicates_are_ignored() {
		$this->manager();
		$ada   = $this->member( 'Member, Ada' );
		$photo = $this->photo();

		$this->submit_tags( $photo, array( $ada, $ada, 999999, 0 ) );

		$this->assertSame( array( $ada ), Chess_Army_Knife_Member_Photos::member_ids( $photo ) );
	}

	public function test_someone_without_the_permission_cannot_change_tags() {
		$ada   = $this->member( 'Member, Ada' );
		$photo = $this->photo();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->submit_tags( $photo, array( $ada ) );

		$this->assertSame( array(), Chess_Army_Knife_Member_Photos::member_ids( $photo ) );
	}

	/* -------------------------------------------------------------
	 * Finding a member's photos
	 * ------------------------------------------------------------- */

	public function test_a_members_photos_can_be_found_and_counted() {
		$ada    = $this->member( 'Member, Ada' );
		$gus    = $this->member( 'Guest, Gus', array( 'status' => 'nonmember' ) );
		$first  = $this->photo( 'First' );
		$second = $this->photo( 'Second' );
		$third  = $this->photo( 'Third' );
		Chess_Army_Knife_Member_Photos::set_members( $first, array( $ada, $gus ) );
		Chess_Army_Knife_Member_Photos::set_members( $second, array( $ada ) );

		$this->assertEqualSets( array( $first, $second ), Chess_Army_Knife_Member_Photos::photo_ids( $ada ) );
		$this->assertSame( array( $first ), Chess_Army_Knife_Member_Photos::photo_ids( $gus ) );
		$this->assertSame( array(), Chess_Army_Knife_Member_Photos::photo_ids( 999 ) );
		$this->assertSame(
			array(
				$ada => 2,
				$gus => 1,
			),
			Chess_Army_Knife_Member_Photos::counts()
		);
		$this->assertNotContains( $third, Chess_Army_Knife_Member_Photos::photo_ids( $ada ) );
	}

	public function test_the_media_library_can_be_filtered_to_one_members_photos() {
		$this->manager();
		$ada = $this->member( 'Member, Ada' );
		$tag = $this->photo( 'Tagged' );
		$this->photo( 'Untagged' );
		Chess_Army_Knife_Member_Photos::set_members( $tag, array( $ada ) );
		set_current_screen( 'upload' );
		$_GET[ Chess_Army_Knife_Member_Photos::QUERY_VAR ] = (string) $ada;

		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Makes this the main query, as on the Media Library screen.
		$found                   = $query->query(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'fields'      => 'ids',
			)
		);

		$this->assertSame( array( $tag ), array_map( 'intval', $found ) );
	}

	public function test_the_media_library_filter_is_ignored_without_the_permission() {
		$ada = $this->member( 'Member, Ada' );
		$tag = $this->photo( 'Tagged' );
		$this->photo( 'Untagged' );
		Chess_Army_Knife_Member_Photos::set_members( $tag, array( $ada ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		set_current_screen( 'upload' );
		$_GET[ Chess_Army_Knife_Member_Photos::QUERY_VAR ] = (string) $ada;

		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Makes this the main query, as on the Media Library screen.
		$found                   = $query->query(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'fields'      => 'ids',
			)
		);

		$this->assertGreaterThanOrEqual( 2, count( $found ), 'A non-manager cannot use the filter to learn who is in which photo.' );
	}

	public function test_the_grid_view_query_can_be_limited_to_one_members_photos() {
		$this->manager();
		$ada = $this->member( 'Member, Ada' );
		$tag = $this->photo( 'Tagged' );
		$this->photo( 'Untagged' );
		Chess_Army_Knife_Member_Photos::set_members( $tag, array( $ada ) );
		$_REQUEST['query'] = array( Chess_Army_Knife_Member_Photos::QUERY_VAR => (string) $ada );

		$query = apply_filters(
			'ajax_query_attachments_args',
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'fields'      => 'ids',
			)
		);

		$this->assertSame( array( $tag ), array_map( 'intval', get_posts( $query ) ) );

		// "All members" (0), or no filter at all, changes nothing.
		$_REQUEST['query'] = array( Chess_Army_Knife_Member_Photos::QUERY_VAR => '0' );
		$this->assertArrayNotHasKey( 'meta_query', apply_filters( 'ajax_query_attachments_args', array() ) );
		$_REQUEST['query'] = array();
		$this->assertArrayNotHasKey( 'meta_query', apply_filters( 'ajax_query_attachments_args', array() ) );
		unset( $_REQUEST['query'] );
	}

	public function test_the_grid_view_filter_is_ignored_without_the_permission() {
		$ada = $this->member( 'Member, Ada' );
		Chess_Army_Knife_Member_Photos::set_members( $this->photo(), array( $ada ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['query'] = array( Chess_Army_Knife_Member_Photos::QUERY_VAR => (string) $ada );

		$this->assertArrayNotHasKey( 'meta_query', apply_filters( 'ajax_query_attachments_args', array() ), 'A non-manager cannot use the grid to learn who is in which photo.' );
		unset( $_REQUEST['query'] );
	}

	public function test_the_grid_filter_script_lists_only_people_who_are_in_a_photo_and_only_for_managers() {
		$ada = $this->member( 'Member, Ada' );
		$this->member( 'Nobody Photographed' );
		Chess_Army_Knife_Member_Photos::set_members( $this->photo(), array( $ada ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		do_action( 'wp_enqueue_media' );
		$this->assertFalse( wp_script_is( 'chess-army-knife-media-filter', 'enqueued' ) );

		$this->manager();
		do_action( 'wp_enqueue_media' );
		$this->assertTrue( wp_script_is( 'chess-army-knife-media-filter', 'enqueued' ) );
		$data = wp_scripts()->get_data( 'chess-army-knife-media-filter', 'data' );
		$this->assertStringContainsString( 'Member, Ada', $data );
		$this->assertStringNotContainsString( 'Photographed, Nobody', $data );
		$this->assertStringContainsString( 'All members', $data );
		$this->assertContains( 'media-views', wp_scripts()->registered['chess-army-knife-media-filter']->deps );
	}

	public function test_the_media_library_shows_who_is_tagged_and_only_to_managers() {
		$ada   = $this->member( 'Member, Ada' );
		$photo = $this->photo();
		Chess_Army_Knife_Member_Photos::set_members( $photo, array( $ada ) );
		$render = function () use ( $photo ) {
			ob_start();
			Chess_Army_Knife_Member_Photos::render_column( 'chess_army_members', $photo );
			return ob_get_clean();
		};

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', $render() );
		$this->assertArrayNotHasKey( 'chess_army_members', apply_filters( 'manage_media_columns', array() ) );

		$this->manager();
		$this->assertSame( 'Member, Ada', $render() );
		$this->assertArrayHasKey( 'chess_army_members', apply_filters( 'manage_media_columns', array() ) );
	}

	public function test_the_member_filter_lists_only_people_who_are_in_a_photo() {
		$this->manager();
		$ada = $this->member( 'Member, Ada' );
		$this->member( 'Nobody Photographed' );
		Chess_Army_Knife_Member_Photos::set_members( $this->photo(), array( $ada ) );

		ob_start();
		Chess_Army_Knife_Member_Photos::render_filter( 'attachment' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Member, Ada', $html );
		$this->assertStringNotContainsString( 'Photographed, Nobody', $html );
	}

	public function test_a_members_page_shows_their_photos_and_a_link_from_the_list() {
		$this->manager();
		$ada   = $this->member( 'Member, Ada' );
		$photo = $this->photo( 'Club night' );
		Chess_Army_Knife_Member_Photos::set_members( $photo, array( $ada ) );

		$list = function () {
			ob_start();
			Chess_Army_Knife_Members_Page::render_page();
			return ob_get_clean();
		};
		$_GET = array( 'view' => 'active' );
		$this->assertStringContainsString( 'Photos (1)', $list() );

		$_GET = array( 'edit' => (string) $ada );
		$html = $list();
		$this->assertStringContainsString( 'Tagged in 1 photo', $html );
		$this->assertStringContainsString( 'Club night', $html );
		$this->assertStringContainsString( 'chess_army_member=' . $ada, $html );
	}

	/* -------------------------------------------------------------
	 * Personal data
	 * ------------------------------------------------------------- */

	public function test_deleting_a_member_takes_them_off_every_photo_but_keeps_the_photos() {
		$ada   = $this->member( 'Member, Ada' );
		$gus   = $this->member( 'Guest, Gus' );
		$photo = $this->photo();
		Chess_Army_Knife_Member_Photos::set_members( $photo, array( $ada, $gus ) );

		Chess_Army_Knife_Membership_Store::delete_member( $ada );

		$this->assertSame( array( $gus ), Chess_Army_Knife_Member_Photos::member_ids( $photo ) );
		$this->assertNotNull( get_post( $photo ) );
	}

	public function test_deleting_a_photo_removes_its_tags() {
		$ada   = $this->member( 'Member, Ada' );
		$photo = $this->photo();
		Chess_Army_Knife_Member_Photos::set_members( $photo, array( $ada ) );

		wp_delete_attachment( $photo, true );

		$this->assertSame( array(), Chess_Army_Knife_Member_Photos::photo_ids( $ada ) );
	}

	public function test_the_export_lists_the_photos_a_person_is_tagged_in() {
		$ada   = $this->member( 'Member, Ada', array( 'email' => 'ada@example.test' ) );
		$photo = $this->photo( 'Club night' );
		Chess_Army_Knife_Member_Photos::set_members( $photo, array( $ada ) );

		$data   = Chess_Army_Knife_Membership_Privacy::export( 'ada@example.test' )['data'];
		$photos = array_values(
			array_filter(
				$data,
				function ( $item ) {
					return 'chess-army-knife-photos' === $item['group_id'];
				}
			)
		);

		$this->assertCount( 1, $photos );
		$this->assertSame( 'photo-' . $photo, $photos[0]['item_id'] );
		$this->assertSame( 'Club night', wp_list_pluck( $photos[0]['data'], 'value', 'name' )['Title'] );
	}

	public function test_erasing_a_person_tagged_in_photos_keeps_the_photos_and_a_bare_record_to_find_them_by() {
		$ada   = $this->member(
			'Member, Ada',
			array(
				'email' => 'ada@example.test',
				'phone' => '0123',
			)
		);
		$photo = $this->photo();
		Chess_Army_Knife_Member_Photos::set_members( $photo, array( $ada ) );

		$result = Chess_Army_Knife_Membership_Privacy::erase( 'ada@example.test' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertStringContainsString( 'photo tagged with this person was not deleted', implode( ' ', $result['messages'] ) );
		$this->assertStringContainsString( 'record ' . $ada, implode( ' ', $result['messages'] ) );
		$member = Chess_Army_Knife_Membership_Store::get_member( $ada );
		$this->assertSame( Chess_Army_Knife_Membership_Store::erased_name(), $member['name'] );
		$this->assertSame( '', $member['email'] );
		$this->assertSame( '', $member['phone'] );
		$this->assertSame( array( $photo ), Chess_Army_Knife_Member_Photos::photo_ids( $ada ) );
	}

	public function test_the_policy_tells_people_about_photo_tags() {
		$text = '';
		foreach ( Chess_Army_Knife_Membership_Privacy::policy_sections() as $section ) {
			$text .= $section['heading'] . ' ' . implode( ' ', $section['paragraphs'] );
		}

		$this->assertStringContainsString( 'Photos', $text );
		$this->assertStringContainsString( 'tag each photo', $text );
	}
}
