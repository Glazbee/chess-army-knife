<?php
/**
 * Integration tests: captains, availability requests and replies, line-ups.
 *
 * @package Chess_Army_Knife
 */

class SelectionTest extends WP_UnitTestCase {

	/** @var int Club A team id. */
	private $team = 0;

	/** @var int Fixture (event) id. */
	private $event = 0;

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( Chess_Army_Knife_Membership_Store::table(), Chess_Army_Knife_Mailer::table(), Chess_Army_Knife_Notification_Preferences::table(), Chess_Army_Knife_Teams::squad_table(), Chess_Army_Knife_Selection::table( 'availability' ), Chess_Army_Knife_Selection::table( 'lineups' ) ) as $table ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Membership_Seasons::install_tables();
		Chess_Army_Knife_Mailer::install_table();
		Chess_Army_Knife_Notification_Preferences::install_table();
		Chess_Army_Knife_Teams::install_table();
		Chess_Army_Knife_Selection::install_tables();
		reset_phpmailer_instance();
		// Mail is sent at once here so the test can read it; a request sends it after the reply.
		add_filter( 'Chess_Army_Knife_send_mail_after_response', '__return_false' );

		$this->team  = $this->team( 'Club A' );
		$this->event = $this->fixture( 'Club A v Rivals', '2099-10-05 19:30:00', $this->team );
	}

	public function tear_down() {
		remove_filter( 'Chess_Army_Knife_send_mail_after_response', '__return_false' );
		$_GET  = array();
		$_POST = array();
		reset_phpmailer_instance();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tear_down();
	}

	private function team( $name ) {
		return self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
				'meta_input'  => array( Chess_Army_Knife_Captains::META_BOARDS => 3 ),
			)
		);
	}

	private function fixture( $title, $start, $team_id, $side = 'home' ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
				'meta_input'  => array( Chess_Army_Knife_Events::META_START => $start ),
			)
		);
		add_post_meta( $id, Chess_Army_Knife_Events::META_TEAM, $team_id );
		update_post_meta( $id, Chess_Army_Knife_Events::META_SIDES, array( $team_id => $side ) );
		return $id;
	}

	private function player( $name, $rating, array $extra = array() ) {
		$id = Chess_Army_Knife_Membership_Store::save_member(
			$extra + array(
				'name'       => $name,
				'email'      => strtolower( strtok( $name, ' ' ) ) . '@example.test',
				'status'     => 'active',
				'ecf_rating' => $rating,
			)
		);
		Chess_Army_Knife_Teams::set_squad( $this->team, array_merge( Chess_Army_Knife_Teams::squad( $this->team ), array( $id ) ) );
		return $id;
	}

	private function sent() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	private function send_queue() {
		Chess_Army_Knife_Mailer::run();
		$mails = $this->sent();
		reset_phpmailer_instance();
		return $mails;
	}

	/* -------------------------------------------------------------
	 * Captains
	 * ------------------------------------------------------------- */

	public function test_the_captains_user_gets_and_loses_the_permission() {
		$a    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$b    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$team = $this->team( 'Club B' );

		Chess_Army_Knife_Captains::set_user( $this->team, $a );
		$this->assertTrue( user_can( $a, Chess_Army_Knife_Captains::CAPABILITY ) );

		Chess_Army_Knife_Captains::set_user( $team, $a );
		Chess_Army_Knife_Captains::set_user( $this->team, $b );
		$this->assertTrue( user_can( $a, Chess_Army_Knife_Captains::CAPABILITY ), 'Still captains Club B.' );

		Chess_Army_Knife_Captains::set_user( $team, 0 );
		$this->assertFalse( user_can( $a, Chess_Army_Knife_Captains::CAPABILITY ) );
		$this->assertTrue( user_can( $b, Chess_Army_Knife_Captains::CAPABILITY ) );
	}

	public function test_a_captain_works_with_their_own_teams_only_and_officers_with_all() {
		$other   = $this->team( 'Club B' );
		$captain = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Chess_Army_Knife_Captains::set_user( $this->team, $captain );
		$officer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $officer )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		$stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( array( $this->team ), wp_list_pluck( Chess_Army_Knife_Captains::teams_for_user( $captain ), 'id' ) );
		$this->assertEqualsCanonicalizing( array( $this->team, $other ), wp_list_pluck( Chess_Army_Knife_Captains::teams_for_user( $officer ), 'id' ) );
		$this->assertSame( array(), Chess_Army_Knife_Captains::teams_for_user( $stranger ) );

		wp_set_current_user( $captain );
		$this->assertTrue( Chess_Army_Knife_Captains::can_manage_team( $this->team ) );
		$this->assertFalse( Chess_Army_Knife_Captains::can_manage_team( $other ) );
		$this->assertTrue( Chess_Army_Knife_Captains::user_can_select() );
		wp_set_current_user( $stranger );
		$this->assertFalse( Chess_Army_Knife_Captains::user_can_select() );
	}

	public function test_boards_default_and_are_limited() {
		$this->assertSame( 3, Chess_Army_Knife_Captains::boards( $this->team ) );
		$fresh = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( Chess_Army_Knife_Captains::DEFAULT_BOARDS, Chess_Army_Knife_Captains::boards( $fresh ) );
		update_post_meta( $this->team, Chess_Army_Knife_Captains::META_BOARDS, 999 );
		$this->assertSame( Chess_Army_Knife_Captains::MAX_BOARDS, Chess_Army_Knife_Captains::boards( $this->team ) );
	}

	public function test_only_someone_who_can_promote_users_sets_the_captains_login() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $admin )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $admin );

		$save = function ( $user_id ) {
			$_POST = array(
				Chess_Army_Knife_Teams_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Teams_Admin::NONCE_ACTION ),
				'chess_army_team_captain_user'            => (string) $user_id,
				'chess_army_team_boards'                  => '6',
			);
			Chess_Army_Knife_Teams_Admin::save( $this->team );
			$_POST = array();
		};
		$save( $user );

		$this->assertSame( $user, Chess_Army_Knife_Captains::user_of_team( $this->team ) );
		$this->assertSame( 6, Chess_Army_Knife_Captains::boards( $this->team ) );

		// An officer without the power to promote users cannot change it.
		$officer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $officer )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		get_userdata( $officer )->add_cap( 'edit_post' );
		wp_set_current_user( $officer );
		$this->assertFalse( current_user_can( 'promote_users' ) );
		$save( $officer );
		$this->assertSame( $user, Chess_Army_Knife_Captains::user_of_team( $this->team ) );
	}

	/* -------------------------------------------------------------
	 * Fixtures and the pool
	 * ------------------------------------------------------------- */

	public function test_fixtures_are_the_events_linked_to_the_teams_and_a_fixture_needs_its_team() {
		$other = $this->team( 'Club B' );
		$this->fixture( 'Club B v Town', '2099-10-12 19:30:00', $other );

		$this->assertSame( array( 'Club A v Rivals' ), wp_list_pluck( wp_list_pluck( Chess_Army_Knife_Selection::fixtures( array( $this->team ) ), 'event' ), 'title' ) );
		$this->assertNotNull( Chess_Army_Knife_Selection::fixture( $this->event, $this->team ) );
		$this->assertNull( Chess_Army_Knife_Selection::fixture( $this->event, $other ), 'Club B is not playing in this fixture.' );
		$this->assertNull( Chess_Army_Knife_Selection::fixture( 999999, $this->team ) );
	}

	public function test_the_pool_is_current_members_of_the_squad_best_rated_first() {
		$low    = $this->player( 'Low Rated', 1200 );
		$high   = $this->player( 'High Rated', 1900 );
		$none   = $this->player( 'No Rating', null, array( 'manual_rating' => 1500 ) );
		$lapsed = $this->player( 'Lapsed Member', 2000, array( 'expiry_date' => '2000-01-01' ) );
		$guest  = $this->player( 'A Guest', 2100, array( 'status' => 'nonmember' ) );

		$this->assertSame( array( $high, $none, $low ), wp_list_pluck( Chess_Army_Knife_Selection::pool( $this->team ), 'id' ) );
		$this->assertNotContains( $lapsed, wp_list_pluck( Chess_Army_Knife_Selection::pool( $this->team ), 'id' ) );
		$this->assertNotContains( $guest, wp_list_pluck( Chess_Army_Knife_Selection::pool( $this->team ), 'id' ) );
	}

	/* -------------------------------------------------------------
	 * Availability
	 * ------------------------------------------------------------- */

	public function test_the_squad_is_asked_once_and_reminders_reach_only_those_who_have_not_replied() {
		$ada = $this->player( 'Ada Lovelace', 1800 );
		$bob = $this->player( 'Bob Smith', 1700 );

		$this->assertSame( 2, Chess_Army_Knife_Selection::request( $this->event, $this->team ) );
		$mails = $this->send_queue();
		$this->assertCount( 2, $mails );
		$this->assertStringContainsString( 'cak_avail=', $mails[0]['body'] );
		$this->assertStringContainsString( 'Can you play?', $mails[0]['subject'] );

		Chess_Army_Knife_Selection::respond( Chess_Army_Knife_Selection::availability( $this->event, $this->team )[ $ada ]['id'], 'yes' );

		$this->assertSame( 1, Chess_Army_Knife_Selection::request( $this->event, $this->team ), 'Only Bob is reminded.' );
		$this->assertSame( 'bob@example.test', $this->send_queue()[0]['to'][0][0] );
		$this->assertSame( 'yes', Chess_Army_Knife_Selection::availability( $this->event, $this->team )[ $ada ]['response'] );
		$this->assertSame( $bob, Chess_Army_Knife_Selection::availability( $this->event, $this->team )[ $bob ]['person_id'] );
	}

	public function test_someone_who_turned_off_fixture_emails_is_not_emailed() {
		$ada = $this->player( 'Ada Lovelace', 1800 );
		Chess_Army_Knife_Notification_Preferences::opt_out( $ada, Chess_Army_Knife_Selection::CATEGORY );

		$this->assertSame( 0, Chess_Army_Knife_Selection::request( $this->event, $this->team ) );
		$this->assertSame( array(), $this->send_queue() );
	}

	public function test_nothing_is_asked_once_the_fixture_has_started() {
		$this->player( 'Ada Lovelace', 1800 );
		$past = $this->fixture( 'Old fixture', '2000-01-01 19:30:00', $this->team );

		$this->assertSame( 0, Chess_Army_Knife_Selection::request( $past, $this->team ) );
	}

	public function test_a_reply_link_is_signed_and_the_answer_can_be_changed_until_the_fixture_starts() {
		$ada = $this->player( 'Ada Lovelace', 1800 );
		Chess_Army_Knife_Selection::request( $this->event, $this->team );
		$row = Chess_Army_Knife_Selection::availability( $this->event, $this->team )[ $ada ];
		parse_str( (string) wp_parse_url( Chess_Army_Knife_Selection::reply_url( $row ), PHP_URL_QUERY ), $query );
		$value = $query['cak_avail'];

		$this->assertSame( $row['id'], Chess_Army_Knife_Selection::row_for_link( $value )['id'] );
		$this->assertNull( Chess_Army_Knife_Selection::row_for_link( str_replace( $row['id'] . '.', '999.', $value ) ) );
		$this->assertNull( Chess_Army_Knife_Selection::row_for_link( 'junk' ) );

		$this->assertTrue( Chess_Army_Knife_Selection::respond( $row['id'], 'maybe' ) );
		$this->assertTrue( Chess_Army_Knife_Selection::respond( $row['id'], 'no' ) );
		$this->assertSame( 'no', Chess_Army_Knife_Selection::availability( $this->event, $this->team )[ $ada ]['response'] );
		$this->assertInstanceOf( 'WP_Error', Chess_Army_Knife_Selection::respond( $row['id'], 'perhaps' ) );

		update_post_meta( $this->event, Chess_Army_Knife_Events::META_START, '2000-01-01 19:30:00' );
		$this->assertSame( 'closed', Chess_Army_Knife_Selection::respond( $row['id'], 'yes' )->get_error_code() );
	}

	private function open_reply_page( array $row, $method, array $post = array() ) {
		parse_str( (string) wp_parse_url( Chess_Army_Knife_Selection::reply_url( $row ), PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simulates opening the signed link.
		$_SERVER['REQUEST_METHOD'] = $method;
		$_POST                     = $post;
		add_filter(
			'wp_die_handler',
			function () {
				return function ( $message ) {
					throw new Exception( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test only: captures the page text.
				};
			}
		);
		try {
			Chess_Army_Knife_Availability_Reply::maybe_show();
		} catch ( Exception $e ) {
			return $e->getMessage();
		}
		return '';
	}

	public function test_opening_the_reply_page_only_asks_and_pressing_a_button_records_the_answer() {
		$ada = $this->player( 'Ada Lovelace', 1800 );
		Chess_Army_Knife_Selection::request( $this->event, $this->team );
		$row = Chess_Army_Knife_Selection::availability( $this->event, $this->team )[ $ada ];

		$page = $this->open_reply_page( $row, 'GET' );
		$this->assertStringContainsString( 'Club A', $page );
		$this->assertStringContainsString( 'value="maybe"', $page );
		$this->assertSame( '', Chess_Army_Knife_Selection::availability( $this->event, $this->team )[ $ada ]['response'] );

		$done = $this->open_reply_page( $row, 'POST', array( 'response' => 'yes' ) );
		$this->assertStringContainsString( 'Yes, I can play', $done );
		$this->assertSame( 'yes', Chess_Army_Knife_Selection::availability( $this->event, $this->team )[ $ada ]['response'] );
	}

	/* -------------------------------------------------------------
	 * Line-ups
	 * ------------------------------------------------------------- */

	public function test_a_draft_holds_only_current_squad_members_once_each_within_the_boards() {
		$ada = $this->player( 'Ada Lovelace', 1800 );
		$bob = $this->player( 'Bob Smith', 1700 );
		$cy  = $this->player( 'Cy Jones', 1600 );
		$out = Chess_Army_Knife_Membership_Store::save_member(
			array(
				'name'   => 'Outsider',
				'status' => 'active',
			)
		);

		$filled = Chess_Army_Knife_Selection::save_draft(
			$this->event,
			$this->team,
			array(
				1 => $ada,
				2 => $ada,  // Twice.
				3 => $out,  // Not in the squad.
				4 => $bob,  // Beyond the three boards.
			)
		);

		$this->assertSame( 1, $filled );
		$this->assertSame( array( 1 => $ada ), Chess_Army_Knife_Selection::lineup( $this->event, $this->team, false ) );
		$this->assertSame( array(), Chess_Army_Knife_Selection::selections_for_person( $ada ), 'A draft is not visible to the player.' );
		unset( $cy );
	}

	public function test_a_suggested_line_up_takes_yes_then_maybe_best_rated_first() {
		$ada = $this->player( 'Ada Lovelace', 1800 );
		$bob = $this->player( 'Bob Smith', 1700 );
		$cy  = $this->player( 'Cy Jones', 1600 );
		$di  = $this->player( 'Di Brown', 1900 );
		$ed  = $this->player( 'Ed White', 2000 );
		Chess_Army_Knife_Selection::request( $this->event, $this->team );
		$replies = Chess_Army_Knife_Selection::availability( $this->event, $this->team );
		Chess_Army_Knife_Selection::respond( $replies[ $bob ]['id'], 'yes' );
		Chess_Army_Knife_Selection::respond( $replies[ $cy ]['id'], 'yes' );
		Chess_Army_Knife_Selection::respond( $replies[ $di ]['id'], 'maybe' );
		Chess_Army_Knife_Selection::respond( $replies[ $ed ]['id'], 'no' );
		// Ada has not replied.

		$this->assertSame(
			array(
				1 => $bob,
				2 => $cy,
				3 => $di,
			),
			Chess_Army_Knife_Selection::suggest( $this->event, $this->team )
		);
	}

	public function test_publishing_tells_those_picked_then_only_those_added_or_dropped() {
		$ada = $this->player( 'Ada Lovelace', 1800 );
		$bob = $this->player( 'Bob Smith', 1700 );
		$cy  = $this->player( 'Cy Jones', 1600 );

		Chess_Army_Knife_Selection::save_draft(
			$this->event,
			$this->team,
			array(
				1 => $ada,
				2 => $bob,
			)
		);
		$this->assertSame( 2, Chess_Army_Knife_Selection::publish( $this->event, $this->team ) );
		$mails = $this->send_queue();
		$this->assertSame(
			array( 'ada@example.test', 'bob@example.test' ),
			array_map(
				function ( $m ) {
					return $m['to'][0][0];
				},
				$mails
			)
		);
		$this->assertStringContainsString( 'board 1', $mails[0]['body'] );
		$this->assertStringContainsString( 'You are selected', $mails[0]['subject'] );

		$picked = Chess_Army_Knife_Selection::selections_for_person( $ada );
		$this->assertSame( 1, $picked[0]['board'] );
		$this->assertSame( 'Club A', $picked[0]['team']['name'] );

		// Bob is swapped for Cy: Cy is told they are in, Bob that they are out, Ada nothing.
		Chess_Army_Knife_Selection::save_draft(
			$this->event,
			$this->team,
			array(
				1 => $ada,
				2 => $cy,
			)
		);
		$this->assertSame( 2, Chess_Army_Knife_Selection::publish( $this->event, $this->team ) );
		$mails = $this->send_queue();
		$this->assertSame(
			array( 'cy@example.test', 'bob@example.test' ),
			array_map(
				function ( $m ) {
					return $m['to'][0][0];
				},
				$mails
			)
		);
		$this->assertStringContainsString( 'Change to the line-up', $mails[1]['subject'] );
		$this->assertSame( array(), Chess_Army_Knife_Selection::selections_for_person( $bob ) );
	}

	public function test_a_line_up_cannot_be_published_after_the_fixture_has_started() {
		$ada = $this->player( 'Ada Lovelace', 1800 );
		Chess_Army_Knife_Selection::save_draft( $this->event, $this->team, array( 1 => $ada ) );
		update_post_meta( $this->event, Chess_Army_Knife_Events::META_START, '2000-01-01 19:30:00' );

		$this->assertSame( 0, Chess_Army_Knife_Selection::publish( $this->event, $this->team ) );
		$this->assertSame( array(), Chess_Army_Knife_Selection::lineup( $this->event, $this->team, true ) );
	}

	/* -------------------------------------------------------------
	 * Privacy
	 * ------------------------------------------------------------- */

	public function test_replies_and_line_ups_are_exported_and_erased_with_the_person() {
		$ada = $this->player( 'Ada Lovelace', 1800 );
		Chess_Army_Knife_Selection::request( $this->event, $this->team );
		Chess_Army_Knife_Selection::respond( Chess_Army_Knife_Selection::availability( $this->event, $this->team )[ $ada ]['id'], 'yes' );
		Chess_Army_Knife_Selection::save_draft( $this->event, $this->team, array( 2 => $ada ) );
		Chess_Army_Knife_Selection::publish( $this->event, $this->team );

		$export = Chess_Army_Knife_Membership_Privacy::export( 'ada@example.test' );
		$this->assertContains( 'chess-army-knife-selection', wp_list_pluck( $export['data'], 'group_id' ) );
		$this->assertStringContainsString( 'Club A v Rivals', wp_json_encode( $export['data'] ) );
		$this->assertTrue( Chess_Army_Knife_Selection::person_has_records( $ada ) );

		Chess_Army_Knife_Membership_Privacy::erase( 'ada@example.test' );

		$this->assertFalse( Chess_Army_Knife_Selection::person_has_records( $ada ) );
		$this->assertSame( array(), Chess_Army_Knife_Selection::availability( $this->event, $this->team ) );
	}

	public function test_deleting_a_fixture_removes_its_replies_and_line_ups() {
		$ada = $this->player( 'Ada Lovelace', 1800 );
		Chess_Army_Knife_Selection::request( $this->event, $this->team );
		Chess_Army_Knife_Selection::save_draft( $this->event, $this->team, array( 1 => $ada ) );

		wp_delete_post( $this->event, true );

		$this->assertSame( array(), Chess_Army_Knife_Selection::availability( $this->event, $this->team ) );
		$this->assertSame( array(), Chess_Army_Knife_Selection::lineup( $this->event, $this->team, false ) );
	}

	public function test_old_replies_and_line_ups_are_pruned_after_the_retention_period() {
		global $wpdb;
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'         => 0,
				'member_retention_months' => 24,
			)
		);
		$ada = $this->player( 'Ada Lovelace', 1800 );
		Chess_Army_Knife_Selection::request( $this->event, $this->team );
		$old = $this->fixture( 'Ancient fixture', '2000-01-01 19:30:00', $this->team );
		$wpdb->insert(
			Chess_Army_Knife_Selection::table( 'lineups' ),
			array(
				'event_id'     => $old,
				'team_id'      => $this->team,
				'person_id'    => $ada,
				'board'        => 1,
				'is_published' => 1,
			)
		);
		$wpdb->update( Chess_Army_Knife_Selection::table( 'availability' ), array( 'requested_at' => '2000-01-01 00:00:00' ), array( 'person_id' => $ada ) );
		Chess_Army_Knife_Selection::save_draft( $this->event, $this->team, array( 1 => $ada ) );

		$this->assertSame( 2, Chess_Army_Knife_Selection::prune() );
		$this->assertSame( array(), Chess_Army_Knife_Selection::availability( $this->event, $this->team ) );
		$this->assertSame( array( 1 => $ada ), Chess_Army_Knife_Selection::lineup( $this->event, $this->team, false ), 'A current fixture\'s line-up stays.' );
	}

	/* -------------------------------------------------------------
	 * The screen
	 * ------------------------------------------------------------- */

	private function render() {
		ob_start();
		try {
			Chess_Army_Knife_Selection_Page::render_page();
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	public function test_a_captain_sees_their_fixtures_and_their_squad_but_not_another_teams() {
		$ada     = $this->player( 'Ada Lovelace', 1800 );
		$other   = $this->team( 'Club B' );
		$other_e = $this->fixture( 'Club B v Town', '2099-10-12 19:30:00', $other );
		$captain = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Chess_Army_Knife_Captains::set_user( $this->team, $captain );
		wp_set_current_user( $captain );
		Chess_Army_Knife_Selection::request( $this->event, $this->team );

		$list = $this->render();
		$this->assertStringContainsString( 'Club A v Rivals', $list );
		$this->assertStringNotContainsString( 'Club B v Town', $list );

		$_GET = array(
			'event' => (string) $this->event,
			'team'  => (string) $this->team,
		);
		$page = $this->render();
		$this->assertStringContainsString( 'Lovelace, Ada', $page );
		$this->assertStringContainsString( 'name="board[1]"', $page );
		$this->assertStringContainsString( 'Publish and tell the players', $page );

		$_GET = array(
			'event' => (string) $other_e,
			'team'  => (string) $other,
		);
		$this->expectException( 'WPDieException' );
		$this->render();
		unset( $ada );
	}

	public function test_someone_with_no_part_in_selection_is_told_what_they_need() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$html = $this->render();

		$this->assertStringContainsString( 'You do not have permission to use this page.', $html );
		$this->assertStringContainsString( 'captain', $html );
	}

	public function test_the_portal_shows_a_members_published_fixtures() {
		$ada = $this->player( 'Ada Lovelace', 1800 );
		Chess_Army_Knife_Selection::save_draft( $this->event, $this->team, array( 2 => $ada ) );
		Chess_Army_Knife_Selection::publish( $this->event, $this->team );
		reset_phpmailer_instance();
		Chess_Army_Knife_Member_Portal::request_link(
			array(
				Chess_Army_Knife_Member_Portal::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Member_Portal::ACTION_LINK ),
				'email' => 'ada@example.test',
			)
		);
		preg_match( '/cak_portal=([A-Za-z0-9]+)/', $this->sent()[0]['body'], $match );
		$_GET = array( 'cak_portal' => $match[1] );

		$html = do_blocks( '<!-- wp:chess-army-knife/member-portal /-->' );

		$this->assertStringContainsString( 'Fixtures you are picked for', $html );
		$this->assertStringContainsString( 'Club A v Rivals', $html );
		$this->assertStringContainsString( 'board 2', $html );
	}

	public function test_the_team_overview_gathers_the_teams_details_squad_fixtures_and_silent_players() {
		$ada = $this->player( 'Ada Lovelace', 1900 );
		$bea = $this->player( 'Bea Babbage', 1800 );
		Chess_Army_Knife_Selection::request( $this->event, $this->team );
		$row = Chess_Army_Knife_Selection::availability( $this->event, $this->team )[ $ada ];
		Chess_Army_Knife_Selection::respond( $row['id'], 'yes' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$user = wp_get_current_user();
		$user->add_cap( Chess_Army_Knife_Teams::CAPABILITY );

		ob_start();
		Chess_Army_Knife_Team_Overview::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Club A', $html );
		$this->assertStringContainsString( 'Club A v Rivals', $html );
		$this->assertStringContainsString( '1 yes, 0 maybe, 0 no, 1 not replied', $html );
		$this->assertStringContainsString( 'Not yet replied for the next fixture', $html );
		$this->assertStringContainsString( 'Babbage, Bea', $html, 'She has not replied.' );
		$this->assertStringContainsString( '1900', $html );
		$this->assertNotNull( $bea );
	}

	public function test_the_team_overview_is_for_those_who_look_after_teams() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		Chess_Army_Knife_Team_Overview::render_page();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'Club A v Rivals', $html );
	}
}
