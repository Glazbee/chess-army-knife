<?php
/**
 * Integration tests: the background job that keeps every current member's ECF rating up to date.
 *
 * @package Chess_Army_Knife
 */

class RatingRefreshTest extends WP_UnitTestCase {

	/** @var string[] Player numbers requested from the ECF, in order. */
	private $requested = array();

	/** @var array Rating to answer with, or 'error', by player number. */
	private $ratings = array();

	/** @var array|string Rows of the club roster the ECF answers with (code, name, std, rpd, btz), or 'error'. */
	private $roster = array();

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Membership_Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Membership_Seasons::install_tables();
		$this->requested = array();
		$this->ratings   = array();
		$this->roster    = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

				if ( false !== strpos( (string) wp_parse_url( $url, PHP_URL_PATH ), '/clubs/players' ) ) {
					$this->requested[] = 'roster:' . $query['code'];
					if ( 'error' === $this->roster ) {
						$body   = array(
							'success' => false,
							'message' => 'No such club.',
						);
						$status = 404;
					} else {
						$body   = array(
							'success' => true,
							'data'    => array(
								'column_names' => array( 'ECF_code', 'full_name', 'std', 'rpd', 'btz' ),
								'players'      => $this->roster,
							),
						);
						$status = 200;
					}
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode( $body ),
						'response' => array(
							'code'    => $status,
							'message' => '',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				$number            = isset( $query['player_no'] ) ? $query['player_no'] : ( isset( $query['code'] ) ? $query['code'] : '' );
				$this->requested[] = $number;
				$answer            = array_key_exists( $number, $this->ratings ) ? $this->ratings[ $number ] : 1500;

				if ( 'error' === $answer ) {
					$body   = array(
						'success' => false,
						'message' => 'No such player.',
					);
					$status = 404;
				} elseif ( 'down' === $answer ) {
					$body   = array(
						'success' => false,
						'message' => 'Try later.',
					);
					$status = 503;
				} elseif ( 'none' === $answer ) {
					$body   = array(
						'success' => true,
						'data'    => array( 'revised_rating' => null ),
					);
					$status = 200;
				} else {
					$body   = array(
						'success' => true,
						'data'    => array( 'revised_rating' => $answer ),
					);
					$status = 200;
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	private function member( $name, $code, array $extra = array() ) {
		return Chess_Army_Knife_Membership_Store::save_member(
			$extra + array(
				'name'     => $name,
				'status'   => 'active',
				'ecf_code' => $code,
			)
		);
	}

	private function row( $id ) {
		return Chess_Army_Knife_Membership_Store::get_member( $id );
	}

	private function set_checked( $id, $when ) {
		global $wpdb;
		$wpdb->update( Chess_Army_Knife_Membership_Store::table(), array( 'ecf_checked_at' => $when ), array( 'id' => $id ) );
	}

	public function test_every_current_member_with_a_code_gets_their_rating_stored() {
		$this->ratings = array(
			'100001' => 1650,
			'100002' => 1820,
		);
		$one           = $this->member( 'One', '100001A' );
		$two           = $this->member( 'Two', '100002B' );

		$result = Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame(
			array(
				'checked' => 2,
				'updated' => 2,
				'failed'  => 0,
				'roster'  => 0,
			),
			$result
		);
		$this->assertSame( 1650, $this->row( $one )['ecf_rating'] );
		$this->assertSame( 1820, $this->row( $two )['ecf_rating'] );
		$this->assertSame( 'S', $this->row( $one )['ecf_rating_domain'] );
		$this->assertNotSame( '', $this->row( $one )['ecf_checked_at'] );
	}

	public function test_only_current_members_with_a_code_are_refreshed() {
		$this->member( 'Current', '100001A' );
		$this->member( 'No code', '' );
		$this->member( 'Applicant', '100002B', array( 'status' => 'pending' ) );
		$this->member( 'Left', '100004D', array( 'status' => 'cancelled' ) );
		$this->member( 'Guest', '100005E', array( 'status' => 'nonmember' ) );

		Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( array( '100001' ), array_unique( $this->requested ), 'The ECF is only asked about the current member.' );
	}

	public function test_a_refresh_never_keeps_an_old_record_alive_by_changing_its_last_changed_time() {
		global $wpdb;
		$id = $this->member( 'One', '100001A' );
		$wpdb->update( Chess_Army_Knife_Membership_Store::table(), array( 'updated_at' => '2020-01-01 00:00:00' ), array( 'id' => $id ) );

		Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( '2020-01-01 00:00:00', $wpdb->get_var( $wpdb->prepare( 'SELECT updated_at FROM ' . Chess_Army_Knife_Membership_Store::table() . ' WHERE id = %d', $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test reads the plugin's own table.
		$this->assertSame( 1500, $this->row( $id )['ecf_rating'] );
	}

	public function test_a_batch_at_a_time_works_through_everyone_least_recently_checked_first() {
		$ids = array();
		foreach ( range( 1, 5 ) as $n ) {
			$ids[ $n ] = $this->member( "Member $n", "10000{$n}A" );
		}
		// Member 3 was checked a long time ago, member 4 a little while ago; the rest never.
		$this->set_checked( $ids[3], gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) );
		$this->set_checked( $ids[4], gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );

		$first = Chess_Army_Knife_Rating_Refresh::run( 2 );
		$this->assertSame( 2, $first['checked'] );
		$this->assertCount( 2, $this->requested );
		$this->assertSame( array(), array_diff( $this->requested, array( '100001', '100002', '100005' ) ), 'Never-checked members go before anyone checked before.' );

		$this->requested = array();
		Chess_Army_Knife_Rating_Refresh::run( 2 );
		$this->assertCount( 2, $this->requested );

		$this->requested = array();
		Chess_Army_Knife_Rating_Refresh::run( 2 );
		$this->assertCount( 1, $this->requested, 'Whoever is left.' );

		// The long-ago checked members are due again after the never-checked ones, then everyone is fresh.
		$this->requested = array();
		Chess_Army_Knife_Rating_Refresh::run( 10 );
		$this->assertSame( array(), $this->requested, 'Everyone has now been checked within the cache period.' );
	}

	public function test_members_become_due_again_once_the_cache_period_has_passed() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache'   => 0,
				'cache_ecf_minutes' => 60,
			)
		);
		$id = $this->member( 'One', '100001A' );
		Chess_Army_Knife_Rating_Refresh::run();
		$this->requested = array();

		Chess_Army_Knife_Rating_Refresh::run();
		$this->assertSame( array(), $this->requested, 'Checked a moment ago.' );

		$this->set_checked( $id, gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) );
		$this->ratings['100001'] = 1700;
		Chess_Army_Knife_Rating_Refresh::run();

		$this->assertNotEmpty( $this->requested );
		$this->assertSame( 1700, $this->row( $id )['ecf_rating'] );
	}

	public function test_the_cache_the_blocks_read_is_refilled_so_visitors_never_wait() {
		Chess_Army_Knife_Cache::remember(
			Chess_Army_Knife_ECF_Client::cache_key_rating( '100001', 'S' ),
			HOUR_IN_SECONDS,
			function () {
				return array( 'revised_rating' => 1400 ); // An old copy.
			}
		);
		$this->member( 'One', '100001A' );
		$this->ratings['100001'] = 1750;

		Chess_Army_Knife_Rating_Refresh::run();
		$this->requested = array();
		$rating          = Chess_Army_Knife_Tournaments::rating_from_data( Chess_Army_Knife_ECF_Client::get_rating( '100001A', 'S' ) );

		$this->assertSame( 1750, $rating, 'The old cached copy was replaced.' );
		$this->assertSame( array(), $this->requested, 'A visitor is served from the cache.' );
	}

	public function test_a_code_the_ecf_rejects_is_noted_and_not_retried_every_hour() {
		$id                      = $this->member( 'One', '100001A', array( 'ecf_rating' => 1600 ) );
		$this->ratings['100001'] = 'error';

		$result = Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 1600, $this->row( $id )['ecf_rating'], 'An earlier rating is kept.' );
		$this->assertNotSame( '', $this->row( $id )['ecf_checked_at'] );

		$this->requested = array();
		Chess_Army_Knife_Rating_Refresh::run();
		$this->assertSame( array(), $this->requested, 'Not retried until the next cache period.' );
	}

	public function test_a_service_that_is_down_does_not_use_up_the_members_check() {
		$id                      = $this->member( 'One', '100001A', array( 'ecf_rating' => 1600 ) );
		$this->ratings['100001'] = 'down';

		$result = Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 1600, $this->row( $id )['ecf_rating'] );

		$this->requested         = array();
		$this->ratings['100001'] = 1650;
		Chess_Army_Knife_Cache::flush_all(); // The failure is remembered for a couple of minutes.
		Chess_Army_Knife_Rating_Refresh::run();
		$this->assertNotSame( array(), $this->requested, 'Tried again on the next run.' );
		$this->assertSame( 1650, $this->row( $id )['ecf_rating'] );
	}

	public function test_the_run_stops_early_when_the_ecf_keeps_failing() {
		foreach ( range( 1, 8 ) as $n ) {
			$this->member( "Member $n", "10000{$n}A" );
			$this->ratings[ "10000{$n}" ] = 'error';
		}

		$result = Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( Chess_Army_Knife_Rating_Refresh::MAX_FAILURES_IN_A_ROW, $result['checked'], 'It does not keep hammering a service that is down or limiting us.' );
	}

	public function test_a_success_with_no_rating_keeps_the_earlier_one() {
		$id                      = $this->member(
			'One',
			'100001A',
			array(
				'ecf_rating'        => 1600,
				'ecf_rating_domain' => 'S',
			)
		);
		$this->ratings['100001'] = 'none';

		$result = Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( 1600, $this->row( $id )['ecf_rating'] );
		$this->assertNotSame( '', $this->row( $id )['ecf_checked_at'] );
	}

	public function test_the_rating_list_follows_the_default_in_settings() {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'default_domain'  => 'R',
			)
		);
		$id = $this->member( 'One', '100001A' );

		Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( 'R', $this->row( $id )['ecf_rating_domain'] );
	}

	public function test_the_batch_size_can_be_changed() {
		foreach ( range( 1, 5 ) as $n ) {
			$this->member( "Member $n", "10000{$n}A" );
		}
		add_filter(
			'Chess_Army_Knife_rating_refresh_batch',
			function () {
				return 3;
			}
		);

		$this->assertSame( 3, Chess_Army_Knife_Rating_Refresh::run()['checked'] );
	}

	public function test_changing_a_persons_code_forgets_the_rating_fetched_for_the_old_one() {
		$id = $this->member( 'One', '100001A' );
		Chess_Army_Knife_Rating_Refresh::run();
		$this->assertSame( 1500, $this->row( $id )['ecf_rating'] );

		Chess_Army_Knife_Membership_Store::clear_rating( $id );

		$member = $this->row( $id );
		$this->assertNull( $member['ecf_rating'] );
		$this->assertSame( '', $member['ecf_rating_domain'] );
		$this->assertSame( '', $member['ecf_checked_at'] );
	}

	/* -------------------------------------------------------------
	 * One request for the whole club
	 * ------------------------------------------------------------- */

	private function with_club( $domain = 'S' ) {
		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'ecf_club_code'   => '4USL',
				'default_domain'  => $domain,
			)
		);
	}

	public function test_one_request_to_the_club_roster_refreshes_every_member_at_once() {
		$this->with_club();
		$ids          = array();
		$this->roster = array();
		foreach ( range( 1, 6 ) as $n ) {
			$ids[ $n ]      = $this->member( "Member $n", "10000{$n}A" );
			$this->roster[] = array( "10000{$n}A", "Member $n", 1500 + $n, 1400 + $n, 1300 + $n );
		}

		$result = Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( array( 'roster:4USL' ), $this->requested, 'One request for all six, none per player.' );
		$this->assertSame(
			array(
				'checked' => 6,
				'updated' => 6,
				'failed'  => 0,
				'roster'  => 6,
			),
			$result
		);
		foreach ( $ids as $n => $id ) {
			$this->assertSame( 1500 + $n, $this->row( $id )['ecf_rating'] );
			$this->assertNotSame( '', $this->row( $id )['ecf_checked_at'] );
		}
	}

	public function test_the_rating_list_picks_the_matching_column_of_the_roster() {
		$this->with_club( 'R' );
		$id             = $this->member( 'One', '100001A' );
		$this->roster[] = array( '100001A', 'One', 1500, 1400, 1300 );

		Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( 1400, $this->row( $id )['ecf_rating'] );
		$this->assertSame( 'R', $this->row( $id )['ecf_rating_domain'] );
	}

	public function test_only_people_the_club_already_has_a_record_of_are_kept_from_the_roster() {
		global $wpdb;
		$this->with_club();
		$this->member( 'Our Member', '100001A' );
		$this->roster = array(
			array( '100001A', 'Our Member', 1600, 0, 0 ),
			array( '777777Z', 'Stranger One', 1900, 0, 0 ),
			array( '888888Y', 'Stranger Two', 1200, 0, 0 ),
		);
		$table        = Chess_Army_Knife_Membership_Store::table();

		Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'Nobody is written down just for being in the ECF list.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test reads the plugin's own table.
		$this->assertNull( Chess_Army_Knife_Membership_Store::find_by_ecf_code( '777777' ) );
		foreach ( array( '777777', '888888' ) as $stranger ) {
			$this->assertFalse( get_transient( Chess_Army_Knife_Cache::key( Chess_Army_Knife_ECF_Client::cache_key_rating( $stranger, 'S' ) ) ), 'No cached rating for them.' );
		}
		$this->assertFalse( get_transient( Chess_Army_Knife_Cache::key( 'ecf_club_players_4USL' ) ), 'The roster itself is never cached.' );
		$this->assertSame( array( 'roster:4USL' ), $this->requested );
	}

	public function test_the_roster_refills_the_cache_the_blocks_read() {
		$this->with_club();
		$this->member( 'One', '100001A' );
		$this->roster[] = array( '100001A', 'One', 1777, 0, 0 );

		Chess_Army_Knife_Rating_Refresh::run();
		$this->requested = array();
		$rating          = Chess_Army_Knife_Tournaments::rating_from_data( Chess_Army_Knife_ECF_Client::get_rating( '100001A', 'S' ) );

		$this->assertSame( 1777, $rating );
		$this->assertSame( array(), $this->requested, 'A visitor is served from the cache.' );
	}

	public function test_members_the_roster_does_not_list_are_checked_individually() {
		$this->with_club();
		$listed                  = $this->member( 'Listed', '100001A' );
		$missing                 = $this->member( 'Missing', '100002B' );
		$this->roster[]          = array( '100001A', 'Listed', 1600, 0, 0 );
		$this->ratings['100002'] = 1850;

		$result = Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( array( 'roster:4USL', '100002' ), $this->requested );
		$this->assertSame( 1600, $this->row( $listed )['ecf_rating'] );
		$this->assertSame( 1850, $this->row( $missing )['ecf_rating'] );
		$this->assertSame( 1, $result['roster'] );
		$this->assertSame( 2, $result['checked'] );
	}

	public function test_if_the_roster_request_fails_each_member_is_still_checked_individually() {
		$this->with_club();
		$id           = $this->member( 'One', '100001A' );
		$this->roster = 'error';

		$result = Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( array( 'roster:4USL', '100001' ), $this->requested );
		$this->assertSame( 1500, $this->row( $id )['ecf_rating'] );
		$this->assertSame( 0, $result['roster'] );
	}

	public function test_an_online_rating_list_is_not_in_the_roster_so_members_are_checked_individually() {
		$this->with_club( 'SW' );
		$this->member( 'One', '100001A' );

		Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( array( '100001' ), $this->requested, 'No roster request for a list the roster does not have.' );
	}

	public function test_nothing_due_means_no_request_at_all_even_with_a_club_code() {
		$this->with_club();
		$this->member( 'One', '100001A' );
		$this->roster[] = array( '100001A', 'One', 1600, 0, 0 );
		Chess_Army_Knife_Rating_Refresh::run();
		$this->requested = array();

		$result = Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( array(), $this->requested );
		$this->assertSame( 0, $result['checked'] );
	}

	public function test_an_unrated_member_in_the_roster_keeps_their_earlier_rating_and_is_marked_checked() {
		$this->with_club();
		$id             = $this->member(
			'One',
			'100001A',
			array(
				'ecf_rating'        => 1500,
				'ecf_rating_domain' => 'S',
			)
		);
		$this->roster[] = array( '100001A', 'One', null, 0, 0 );

		$result = Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( 1500, $this->row( $id )['ecf_rating'] );
		$this->assertNotSame( '', $this->row( $id )['ecf_checked_at'] );
		$this->assertSame( 0, $result['updated'] );
	}

	public function test_the_roster_matches_a_code_with_or_without_its_letter() {
		$this->with_club();
		$id             = $this->member( 'One', '100001' );
		$this->roster[] = array( '100001A', 'One', 1640, 0, 0 );

		Chess_Army_Knife_Rating_Refresh::run();

		$this->assertSame( 1640, $this->row( $id )['ecf_rating'] );
	}

	public function test_the_club_code_setting_is_cleaned() {
		$this->assertSame( '4USL', Chess_Army_Knife_Settings::sanitize( array( 'ecf_club_code' => ' 4usl <b>' ) )['ecf_club_code'] );
		$this->assertSame( '', Chess_Army_Knife_Settings::defaults()['ecf_club_code'] );
	}

	/* -------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------- */

	public function test_the_job_is_scheduled_hourly_and_removed_on_deactivation() {
		wp_clear_scheduled_hook( Chess_Army_Knife_Rating_Refresh::HOOK );
		$this->assertFalse( wp_next_scheduled( Chess_Army_Knife_Rating_Refresh::HOOK ) );

		$this->assertNotFalse( has_action( 'init', array( 'Chess_Army_Knife_Rating_Refresh', 'schedule' ) ), 'It schedules itself on every page load if missing.' );
		Chess_Army_Knife_Rating_Refresh::schedule();
		$this->assertNotFalse( wp_next_scheduled( Chess_Army_Knife_Rating_Refresh::HOOK ) );
		$this->assertSame( 'hourly', wp_get_schedule( Chess_Army_Knife_Rating_Refresh::HOOK ) );

		Chess_Army_Knife_deactivate();
		$this->assertFalse( wp_next_scheduled( Chess_Army_Knife_Rating_Refresh::HOOK ) );

		Chess_Army_Knife_Rating_Refresh::schedule();
		$this->assertNotFalse( wp_next_scheduled( Chess_Army_Knife_Rating_Refresh::HOOK ) );
	}

	public function test_the_scheduled_hook_runs_the_refresh() {
		$id = $this->member( 'One', '100001A' );

		do_action( Chess_Army_Knife_Rating_Refresh::HOOK );

		$this->assertSame( 1500, $this->row( $id )['ecf_rating'] );
	}

	/* -------------------------------------------------------------
	 * On screen, and personal data
	 * ------------------------------------------------------------- */

	private function manager() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );
	}

	private function members_page() {
		$_GET = array( 'view' => 'active' );
		ob_start();
		Chess_Army_Knife_Members_Page::render_page();
		$html = ob_get_clean();
		$_GET = array();
		return $html;
	}

	public function test_the_members_list_shows_each_rating_and_how_fresh_it_is() {
		$this->manager();
		$this->ratings['100001'] = 1650;
		$this->member( 'Checked Member', '100001A' );
		$this->member( 'Waiting Member', '100002B' );
		$this->member( 'No Code Member', '' );
		Chess_Army_Knife_Rating_Refresh::run( 1 );

		$html = $this->members_page();

		$this->assertStringContainsString( 'ECF rating', $html );
		$this->assertStringContainsString( '1650', $html );
		$this->assertMatchesRegularExpression( '/checked [^<]+ ago/', $html );
		$this->assertStringContainsString( 'Not checked yet', $html );
		$this->assertStringContainsString( 'Refresh ECF ratings', $html );
		$this->assertStringContainsString( 'action=chess_army_knife_refresh_ratings', $html );
	}

	public function test_the_result_of_a_manual_refresh_is_reported_on_the_members_screen() {
		$this->manager();

		$_GET = array(
			'view'       => 'active',
			'rr_checked' => '5',
			'rr_updated' => '4',
			'rr_failed'  => '1',
		);
		ob_start();
		Chess_Army_Knife_Members_Page::render_page();
		$html = ob_get_clean();
		$_GET = array();

		$this->assertStringContainsString( 'Checked 5 members with the ECF: 4 ratings updated, 1 could not be fetched.', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringNotContainsString( 'Member updated.', $html );
	}

	public function test_the_refresh_button_is_refused_without_the_permission() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->expectException( WPDieException::class );
		Chess_Army_Knife_Members_Page::handle_refresh_ratings();
	}

	public function test_the_stored_rating_is_exported_and_erased_with_the_person() {
		$id = $this->member(
			'One',
			'100001A',
			array(
				'email'   => 'one@example.test',
				'paid_on' => '2026-09-05',
			)
		);
		Chess_Army_Knife_Rating_Refresh::run();

		$values = wp_list_pluck( Chess_Army_Knife_Membership_Privacy::export( 'one@example.test' )['data'][0]['data'], 'value', 'name' );
		$this->assertSame( '1500 (S)', $values['Latest ECF rating (fetched from the ECF)'] );

		Chess_Army_Knife_Membership_Privacy::erase( 'one@example.test' );
		$member = $this->row( $id );
		$this->assertNull( $member['ecf_rating'] );
		$this->assertSame( '', $member['ecf_checked_at'] );
		$this->assertSame( '', $member['ecf_code'] );
	}

	public function test_the_policy_says_ratings_are_fetched_and_kept() {
		$text = '';
		foreach ( Chess_Army_Knife_Membership_Privacy::policy_sections() as $section ) {
			$text .= implode( ' ', $section['paragraphs'] );
		}

		$this->assertStringContainsString( 'ask the ECF for your latest rating', $text );
	}
}
