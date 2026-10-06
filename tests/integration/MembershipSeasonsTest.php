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

		$this->assertStringContainsString( 'Ads,Lovelace,,2026-09-12,Cash,,12.50,N', $csv );
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
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01', array( 'empty_squads' => true ) );

		$this->assertSame( array(), Chess_Army_Knife_Teams::squad( $team ) );
		$this->assertSame( array( $team => array( $ada ) ), Chess_Army_Knife_Teams::squads_of_season( $old ), 'The old squad is still on record.' );
	}

	public function test_the_first_season_leaves_the_squads_alone() {
		$team = $this->team();
		$ada  = $this->member();
		Chess_Army_Knife_Teams::set_squad( $team, array( $ada ) );

		$first = Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01', array( 'empty_squads' => true ) );

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

	/* -------------------------------------------------------------
	 * Last day, season time and LMS seasons
	 * ------------------------------------------------------------- */

	public function test_a_planned_last_day_is_kept_when_the_next_season_starts_after_it() {
		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01', array( 'end_date' => '2026-05-31' ) );
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );

		$seasons = Chess_Army_Knife_Membership_Seasons::all();

		$this->assertSame( '2026-05-31', $seasons[1]['end_date'], 'The planned last day stays; the summer is not season time.' );
		$this->assertSame( '', $seasons[0]['end_date'] );
	}

	public function test_a_season_with_no_planned_last_day_ends_the_day_before_the_next() {
		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );

		$this->assertSame( '2026-08-31', Chess_Army_Knife_Membership_Seasons::all()[1]['end_date'] );
	}

	public function test_a_last_day_before_the_first_day_is_refused() {
		$result = Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01', array( 'end_date' => '2025-08-01' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'season_end', $result->get_error_code() );
		$this->assertSame( array(), Chess_Army_Knife_Membership_Seasons::all() );
	}

	public function test_season_time_is_between_a_first_day_and_its_planned_last_day() {
		$this->assertTrue( Chess_Army_Knife_Membership_Seasons::contains_date( '2030-01-01' ), 'Nothing is outside season time before a season has been started.' );

		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01', array( 'end_date' => '2026-05-31' ) );

		$this->assertFalse( Chess_Army_Knife_Membership_Seasons::contains_date( '2025-08-31' ) );
		$this->assertTrue( Chess_Army_Knife_Membership_Seasons::contains_date( '2025-09-01' ) );
		$this->assertTrue( Chess_Army_Knife_Membership_Seasons::contains_date( '2026-05-31' ) );
		$this->assertFalse( Chess_Army_Knife_Membership_Seasons::contains_date( '2026-06-01' ) );

		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );

		$this->assertTrue( Chess_Army_Knife_Membership_Seasons::contains_date( '2027-03-01' ), 'A season with no last day runs on.' );
	}

	public function test_a_club_night_that_only_runs_in_season_time_is_left_out_of_the_summer() {
		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01', array( 'end_date' => '2026-05-31' ) );
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$night = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
				'post_title'  => 'Club night',
				'post_status' => 'publish',
			)
		);
		$all   = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
				'post_title'  => 'Summer blitz',
				'post_status' => 'publish',
			)
		);
		foreach ( array( $night, $all ) as $id ) {
			update_post_meta( $id, Chess_Army_Knife_Events::META_START, '2025-09-02 19:00:00' );
			update_post_meta( $id, Chess_Army_Knife_Events::META_REPEAT, 'weekly' );
		}
		update_post_meta( $night, Chess_Army_Knife_Events::META_IN_SEASON, '1' );

		$starts = function ( $title ) {
			$found = array();
			foreach ( Chess_Army_Knife_Events::query(
				array(
					'after'  => '2026-05-20 00:00:00',
					'before' => '2026-09-10 00:00:00',
					'limit'  => 0,
				)
			) as $event ) {
				if ( $title === $event['title'] ) {
					$found[] = substr( $event['start'], 0, 10 );
				}
			}
			return $found;
		};

		$this->assertSame( array( '2026-05-26', '2026-09-01', '2026-09-08' ), $starts( 'Club night' ) );
		$this->assertContains( '2026-07-14', $starts( 'Summer blitz' ), 'An event not tied to the season carries on.' );
	}

	public function test_an_occurrence_outside_season_time_cannot_be_downloaded() {
		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01', array( 'end_date' => '2026-05-31' ) );
		$night = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
				'post_title'  => 'Club night',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $night, Chess_Army_Knife_Events::META_START, '2025-09-02 19:00:00' );
		update_post_meta( $night, Chess_Army_Knife_Events::META_REPEAT, 'weekly' );
		update_post_meta( $night, Chess_Army_Knife_Events::META_IN_SEASON, '1' );

		$this->assertNotNull( Chess_Army_Knife_Events::get_occurrence( $night, '2026-05-26 19:00:00' ) );
		$this->assertNull( Chess_Army_Knife_Events::get_occurrence( $night, '2026-06-02 19:00:00' ) );
	}

	public function test_an_lms_season_belongs_to_one_season_of_ours() {
		$first  = Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01', array( 'lms_seasons' => array( '2024-2025', '2025-2026' ) ) );
		$second = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01', array( 'lms_seasons' => array( '2025-2026' ) ) );

		$this->assertSame( $second, Chess_Army_Knife_Membership_Seasons::for_lms_season( '2025-2026' )['id'] );
		$this->assertSame( array( '2024-2025' ), Chess_Army_Knife_Membership_Seasons::get( $first )['lms_seasons'] );
		$this->assertNull( Chess_Army_Knife_Membership_Seasons::for_lms_season( '2030-2031' ) );
	}

	public function test_the_lms_seasons_and_last_day_of_a_season_can_be_changed() {
		$id = Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );

		$this->assertTrue( Chess_Army_Knife_Membership_Seasons::update_details( $id, '2026-05-31', array( ' 2025-2026 ', '', '2025-2026' ) ) );

		$season = Chess_Army_Knife_Membership_Seasons::get( $id );
		$this->assertSame( '2026-05-31', $season['end_date'] );
		$this->assertSame( array( '2025-2026' ), $season['lms_seasons'] );

		$this->assertWPError( Chess_Army_Knife_Membership_Seasons::update_details( $id, '2025-01-01', array() ) );
		$this->assertWPError( Chess_Army_Knife_Membership_Seasons::update_details( 999999, '', array() ) );

		Chess_Army_Knife_Membership_Seasons::update_details( $id, '', array() );
		$this->assertSame( '', Chess_Army_Knife_Membership_Seasons::get( $id )['end_date'] );
	}

	public function test_the_lms_seasons_on_offer_include_those_kept_on_imported_games() {
		$game = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $game, Chess_Army_Knife_Events_Import::META_SEASON, '2025-2026' );

		$this->assertArrayHasKey( '2025-2026', Chess_Army_Knife_Membership_Seasons::available_lms_seasons() );
	}

	public function test_league_games_open_on_the_lms_season_of_the_current_season() {
		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01', array( 'lms_seasons' => array( '2025-2026' ) ) );
		$seasons = array(
			'2026-2027' => 3,
			'2025-2026' => 5,
		);

		$this->assertSame( '2025-2026', Chess_Army_Knife_League_Games::default_season( $seasons ) );
		$this->assertSame( '2026-2027', Chess_Army_Knife_League_Games::default_season( array( '2026-2027' => 3 ) ), 'Else the latest.' );
	}

	public function test_membership_carries_on_over_the_summer() {
		$ada = $this->member();
		Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01', array( 'end_date' => '2026-05-31' ) );
		$this->pay( $ada, '2025-09-10' );
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->pay( $ada, '2026-09-10' );

		$periods = Chess_Army_Knife_Member_History::periods( Chess_Army_Knife_Membership_Store::get_member( $ada ) );
		$summary = Chess_Army_Knife_Member_History::summarise( $periods, '2026-10-01' );

		$this->assertSame( '2026-08-31', $periods[0]['expiry_date'], 'Runs to the day before the next season, not to the last day of playing.' );
		$this->assertSame( array(), $summary['lapses'], 'The summer is not a lapse.' );
		$this->assertSame( '2025-09-01', $summary['continuous_since'] );
	}

	/* -------------------------------------------------------------
	 * A junior's free year
	 * ------------------------------------------------------------- */

	public function test_a_free_year_counts_as_paid_for_nothing_and_ends_with_the_season() {
		$junior = $this->member( array( 'name' => 'Young Player' ) );
		$id     = Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );
		$this->assertSame( 1, Chess_Army_Knife_Membership_Store::count_view( 'unpaid' ) );

		$this->assertTrue( Chess_Army_Knife_Membership_Store::mark_free_year( $junior ) );

		$member = Chess_Army_Knife_Membership_Store::get_member( $junior );
		$this->assertSame( 'free_year', $member['payment_method'] );
		$this->assertSame( 0, $member['payment_amount'] );
		$this->assertSame( 0, Chess_Army_Knife_Membership_Store::count_view( 'unpaid' ) );
		$payments = Chess_Army_Knife_Membership_Seasons::payments_for_season( $id );
		$this->assertCount( 1, $payments );
		$this->assertSame( 'free_year', $payments[0]['method'] );
		$this->assertSame( 0, $payments[0]['amount'] );

		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );

		$this->assertSame( 1, Chess_Army_Knife_Membership_Store::count_view( 'unpaid' ), 'They pay from the next season.' );
		$this->assertCount( 1, Chess_Army_Knife_Membership_Seasons::paid_seasons( $junior ), 'The free year stays in their history.' );
	}

	public function test_a_free_year_can_be_given_to_a_junior_who_has_been_a_member_for_some_time() {
		$junior = $this->member( array( 'name' => 'Young Player' ) );
		$id     = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );

		$clean       = Chess_Army_Knife_Membership_Store::sanitize_member(
			array(
				'name'           => 'Young Player',
				'payment_method' => 'free_year',
			),
			true
		);
		$clean['id'] = $junior;
		Chess_Army_Knife_Membership_Store::save_member( $clean );

		$payments = Chess_Army_Knife_Membership_Seasons::payments_for_season( $id );
		$this->assertSame( 'free_year', $payments[0]['method'] );
		$this->assertSame( current_time( 'Y-m-d' ), $payments[0]['paid_on'] );
	}

	public function test_only_a_current_member_can_have_a_free_year() {
		$this->assertFalse( Chess_Army_Knife_Membership_Store::mark_free_year( $this->member( array( 'status' => 'pending' ) ) ) );
		$this->assertFalse( Chess_Army_Knife_Membership_Store::mark_free_year( 999999 ) );
	}

	public function test_the_treasurers_file_shows_a_free_year_at_nothing() {
		$junior = $this->member( array( 'name' => 'Player, Young' ) );
		$id     = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		Chess_Army_Knife_Membership_Store::mark_free_year( $junior );

		$csv = Chess_Army_Knife_Payment_Export::to_csv( Chess_Army_Knife_Membership_Seasons::payments_for_season( $id ) );

		$this->assertStringContainsString( '"Free first year",,0.00,Y', $csv );
	}

	/* -------------------------------------------------------------
	 * Correcting payments
	 * ------------------------------------------------------------- */

	/**
	 * The one payment of a season.
	 *
	 * @param int $season_id Season id.
	 * @return array
	 */
	private function only_payment( $season_id ) {
		$payments = Chess_Army_Knife_Membership_Seasons::payments_for_season( $season_id );
		$this->assertCount( 1, $payments );
		return $payments[0];
	}

	public function test_a_payment_for_the_current_season_can_be_corrected_and_the_member_agrees() {
		$ada = $this->member( array( 'payment_reference' => 'ADA-1' ) );
		$id  = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->pay( $ada, '2026-09-12', 2500, 'cash' );

		$result = Chess_Army_Knife_Membership_Seasons::update_payment(
			$this->only_payment( $id )['id'],
			array(
				'paid_on'   => '2026-09-10',
				'method'    => 'bank_transfer',
				'amount'    => '£20.50',
				'reference' => ' ADA-9 ',
			)
		);

		$this->assertTrue( $result );
		$payment = $this->only_payment( $id );
		$this->assertSame( '2026-09-10', $payment['paid_on'] );
		$this->assertSame( 'bank_transfer', $payment['method'] );
		$this->assertSame( 2050, $payment['amount'] );
		$this->assertSame( 'ADA-9', $payment['reference'] );
		$member = Chess_Army_Knife_Membership_Store::get_member( $ada );
		$this->assertSame( '2026-09-10', $member['paid_on'] );
		$this->assertSame( 'bank_transfer', $member['payment_method'] );
		$this->assertSame( 2050, $member['payment_amount'] );
		$this->assertSame( 'ADA-1', $member['payment_reference'], 'Their own reference is not changed.' );
	}

	public function test_a_payment_for_an_earlier_season_is_corrected_without_touching_the_member() {
		$ada = $this->member();
		$old = Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );
		$this->pay( $ada, '2025-09-12', 2500, 'cash' );
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->pay( $ada, '2026-09-12', 2600, 'cash' );

		Chess_Army_Knife_Membership_Seasons::update_payment(
			$this->only_payment( $old )['id'],
			array(
				'paid_on' => '2025-09-11',
				'method'  => 'cash',
				'amount'  => '24',
			)
		);

		$this->assertSame( 2400, $this->only_payment( $old )['amount'] );
		$member = Chess_Army_Knife_Membership_Store::get_member( $ada );
		$this->assertSame( '2026-09-12', $member['paid_on'] );
		$this->assertSame( 2600, $member['payment_amount'] );
	}

	public function test_correcting_a_payment_to_a_free_year_makes_it_nothing_and_cash_has_no_reference() {
		$ada = $this->member();
		$id  = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->pay( $ada, '2026-09-12', 2500, 'bank_transfer' );

		Chess_Army_Knife_Membership_Seasons::update_payment(
			$this->only_payment( $id )['id'],
			array(
				'paid_on'   => '2026-09-12',
				'method'    => 'free_year',
				'amount'    => '25',
				'reference' => 'MEM-1',
			)
		);

		$payment = $this->only_payment( $id );
		$this->assertSame( 0, $payment['amount'] );
		$this->assertSame( '', $payment['reference'] );
		$this->assertSame( 0, Chess_Army_Knife_Membership_Store::get_member( $ada )['payment_amount'] );
	}

	/**
	 * @dataProvider invalid_corrections
	 */
	public function test_a_correction_that_is_not_valid_changes_nothing( array $input, $code ) {
		$ada = $this->member();
		$id  = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->pay( $ada, '2026-09-12', 2500, 'cash' );
		$payment = $this->only_payment( $id );

		$result = Chess_Army_Knife_Membership_Seasons::update_payment( $payment['id'], $input + array( 'paid_on' => '2026-09-13', 'method' => 'cash', 'amount' => '30' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $payment, $this->only_payment( $id ) );
	}

	public function invalid_corrections() {
		return array(
			'no date'       => array( array( 'paid_on' => '' ), 'payment_date' ),
			'impossible'    => array( array( 'paid_on' => '2026-02-30' ), 'payment_date' ),
			'unknown type'  => array( array( 'method' => 'cheque' ), 'payment_method' ),
			'not an amount' => array( array( 'amount' => 'lots' ), 'payment_amount' ),
		);
	}

	public function test_a_payment_that_does_not_exist_cannot_be_corrected_or_deleted() {
		$this->assertWPError( Chess_Army_Knife_Membership_Seasons::update_payment( 999999, array( 'paid_on' => '2026-09-13' ) ) );
		$this->assertFalse( Chess_Army_Knife_Membership_Seasons::delete_payment( 999999 ) );
	}

	public function test_deleting_a_payment_for_the_current_season_makes_the_member_unpaid_again() {
		$ada = $this->member();
		$id  = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->pay( $ada, '2026-09-12', 2500, 'cash' );
		$this->assertSame( 0, Chess_Army_Knife_Membership_Store::count_view( 'unpaid' ) );

		$this->assertTrue( Chess_Army_Knife_Membership_Seasons::delete_payment( $this->only_payment( $id )['id'] ) );

		$this->assertSame( array(), Chess_Army_Knife_Membership_Seasons::payments_for_season( $id ) );
		$member = Chess_Army_Knife_Membership_Store::get_member( $ada );
		$this->assertSame( '', $member['paid_on'] );
		$this->assertSame( '', $member['payment_method'] );
		$this->assertNull( $member['payment_amount'] );
		$this->assertSame( 1, Chess_Army_Knife_Membership_Store::count_view( 'unpaid' ) );
	}

	public function test_deleting_a_payment_for_an_earlier_season_leaves_this_seasons_payment_alone() {
		$ada = $this->member();
		$old = Chess_Army_Knife_Membership_Seasons::start( '2025/26', '2025-09-01' );
		$this->pay( $ada, '2025-09-12' );
		Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->pay( $ada, '2026-09-12' );

		Chess_Army_Knife_Membership_Seasons::delete_payment( $this->only_payment( $old )['id'] );

		$this->assertSame( array(), Chess_Army_Knife_Membership_Seasons::payments_for_season( $old ) );
		$this->assertSame( '2026-09-12', Chess_Army_Knife_Membership_Store::get_member( $ada )['paid_on'] );
		$this->assertCount( 1, Chess_Army_Knife_Membership_Seasons::paid_seasons( $ada ) );
	}

	public function test_the_payments_of_a_season_can_be_seen_edited_and_deleted_from_the_seasons_screen() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );
		$ada = $this->member( array( 'name' => 'Lovelace, Ada' ) );
		$id  = Chess_Army_Knife_Membership_Seasons::start( '2026/27', '2026-09-01' );
		$this->pay( $ada, '2026-09-12', 2500, 'cash' );
		$payment = $this->only_payment( $id );

		$_GET = array( 'payments' => $id );
		ob_start();
		Chess_Army_Knife_Seasons_Page::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Payments for 2026/27', $html );
		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringContainsString( 'chess_army_knife_delete_payment', $html );
		$this->assertStringContainsString( 'edit=' . $payment['id'], $html );
		$this->assertStringNotContainsString( 'Start your first season', $html, 'It is the payments, not the season list.' );

		$_GET = array(
			'payments' => $id,
			'edit'     => $payment['id'],
		);
		ob_start();
		Chess_Army_Knife_Seasons_Page::render_page();
		$html = ob_get_clean();
		$_GET = array();

		$this->assertStringContainsString( 'chess_army_knife_save_payment', $html );
		$this->assertStringContainsString( 'Correct the payment of Ada Lovelace', $html );
	}

	/* -------------------------------------------------------------
	 * Its own page
	 * ------------------------------------------------------------- */

	public function test_seasons_is_a_screen_of_its_own_and_not_a_tab_of_members() {
		$areas = wp_list_pluck( Chess_Army_Knife_Menu::areas(), null, 'key' );

		$this->assertArrayHasKey( 'seasons', $areas );
		$this->assertEmpty( $areas['seasons']['unlisted'], 'It has a menu item of its own.' );
		$this->assertArrayNotHasKey( 'seasons', Chess_Army_Knife_Member_Tabs::tabs() );
	}
}
