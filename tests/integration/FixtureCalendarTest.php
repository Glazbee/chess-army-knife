<?php
/**
 * Integration tests: the team-aware calendar (team filter, home or away, colours, feed).
 *
 * @package Chess_Army_Knife
 */

class FixtureCalendarTest extends WP_UnitTestCase {

	/** @var int Club A team id. */
	private $team_a = 0;

	/** @var int Club B team id. */
	private $team_b = 0;

	public function set_up() {
		parent::set_up();
		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );

		$this->team_a = $this->team( 'Club A', '#2a78d6' );
		$this->team_b = $this->team( 'Club B', '' );
	}

	private function team( $name, $colour ) {
		return self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
				'meta_input'  => array( Chess_Army_Knife_Teams::META_COLOUR => $colour ),
			)
		);
	}

	private function fixture( $title, $date, array $sides ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
				'meta_input'  => array( Chess_Army_Knife_Events::META_START => $date . ' 19:30:00' ),
			)
		);
		foreach ( $sides as $team_id => $side ) {
			add_post_meta( $id, Chess_Army_Knife_Events::META_TEAM, $team_id );
		}
		update_post_meta( $id, Chess_Army_Knife_Events::META_SIDES, $sides );
		return $id;
	}

	private function seed() {
		$this->fixture( 'A home game', '2099-10-05', array( $this->team_a => 'home' ) );
		$this->fixture( 'A away game', '2099-10-12', array( $this->team_a => 'away' ) );
		$this->fixture( 'B home game', '2099-10-19', array( $this->team_b => 'home' ) );
		$this->fixture( 'Club night', '2099-10-26', array() );
	}

	private function titles( array $events ) {
		return wp_list_pluck( $events, 'title' );
	}

	public function test_events_can_be_limited_to_teams() {
		$this->seed();

		$this->assertSame( array( 'A home game', 'A away game' ), $this->titles( Chess_Army_Knife_Events::query( array( 'teams' => array( $this->team_a ) ) ) ) );
		$this->assertSame( array( 'A home game', 'A away game', 'B home game' ), $this->titles( Chess_Army_Knife_Events::query( array( 'teams' => array( $this->team_a, $this->team_b ) ) ) ) );
		$this->assertCount( 4, Chess_Army_Knife_Events::query( array( 'teams' => array() ) ) );
	}

	public function test_the_agenda_shows_team_side_and_colour() {
		$this->seed();

		$html = do_blocks( '<!-- wp:chess-army-knife/club-event-calendar /-->' );

		$this->assertStringContainsString( 'Club A (home)', $html );
		$this->assertStringContainsString( 'Club B (home)', $html );
		$this->assertStringContainsString( '--cak-event-colour:#2a78d6', $html );
		$this->assertSame( 2, substr_count( $html, '--cak-event-colour' ), 'Club B has no colour; a club night has no team.' );
	}

	public function test_the_agenda_can_be_one_team_and_home_or_away() {
		$this->seed();

		$html = do_blocks( '<!-- wp:chess-army-knife/club-event-calendar {"teamIds":[' . $this->team_a . '],"venue":"away"} /-->' );

		$this->assertStringContainsString( 'A away game', $html );
		$this->assertStringNotContainsString( 'A home game', $html );
		$this->assertStringNotContainsString( 'B home game', $html );

		$home = do_blocks( '<!-- wp:chess-army-knife/club-event-calendar {"venue":"home","count":1} /-->' );
		$this->assertStringContainsString( 'A home game', $home );
		$this->assertStringNotContainsString( 'B home game', $home, 'The count applies after the filter.' );
	}

	public function test_the_month_grid_and_its_route_follow_the_same_filters() {
		$next = gmdate( 'Y-m', strtotime( current_time( 'Y-m-15' ) . ' +1 month' ) );
		$this->fixture( 'A home month', $next . '-10', array( $this->team_a => 'home' ) );
		$this->fixture( 'B home month', $next . '-11', array( $this->team_b => 'home' ) );
		$this->fixture( 'B away month', $next . '-12', array( $this->team_b => 'away' ) );
		$request = new WP_REST_Request( 'GET', '/' . Chess_Army_Knife_Events_REST::NAMESPACE_V1 . '/events-month' );
		$request->set_query_params(
			array(
				'month' => $next,
				'teams' => (string) $this->team_b,
				'venue' => 'home',
			)
		);

		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertStringContainsString( 'B home month', $data['html'] );
		$this->assertStringNotContainsString( 'A home month', $data['html'] );
		$this->assertStringNotContainsString( 'B away month', $data['html'] );

		$grid = do_blocks( '<!-- wp:chess-army-knife/club-event-calendar {"layout":"month","teamIds":[' . $this->team_a . '],"venue":"home"} /-->' );
		$this->assertStringContainsString( 'data-teams="' . $this->team_a . '"', $grid );
		$this->assertStringContainsString( 'data-venue="home"', $grid );
	}

	public function test_the_subscribe_link_follows_the_blocks_selection_and_can_be_hidden() {
		$html = do_blocks( '<!-- wp:chess-army-knife/club-event-calendar {"teamIds":[' . $this->team_a . '],"venue":"home"} /-->' );

		$this->assertStringContainsString( 'chess_army_ics=1', $html );
		$this->assertStringContainsString( 'teams=' . $this->team_a, $html );
		$this->assertStringContainsString( 'venue=home', $html );

		$none = do_blocks( '<!-- wp:chess-army-knife/club-event-calendar {"showSubscribe":false} /-->' );
		$this->assertStringNotContainsString( 'chess_army_ics', $none );
	}

	public function test_the_feed_holds_the_selected_fixtures() {
		$this->seed();

		$events = Chess_Army_Knife_Events_Display::filter_by_venue( Chess_Army_Knife_Events::query( array( 'teams' => array( $this->team_a ) ) ), 'away', array( $this->team_a ) );
		$ics    = Chess_Army_Knife_Events_Feed::build( $events, 'Club', 'example.org', time() );

		$this->assertSame( 1, substr_count( $ics, 'BEGIN:VEVENT' ) );
		$this->assertStringContainsString( 'SUMMARY:A away game', $ics );
		$this->assertStringContainsString( 'DTSTART:20991012T', $ics );
	}

	public function test_the_feed_address_carries_only_the_chosen_filters() {
		$this->assertSame( home_url( '/?chess_army_ics=1' ), Chess_Army_Knife_Events_Feed::url() );
		$this->assertSame( home_url( '/?chess_army_ics=1&teams=3,4&venue=away&tags=blitz' ), Chess_Army_Knife_Events_Feed::url( array( 3, 4 ), 'away', array( 'blitz' ) ) );
	}

	public function test_a_team_colour_is_saved_only_if_valid() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user )->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
		wp_set_current_user( $user );

		$post = function ( array $extra ) {
			$_POST = $extra + array( Chess_Army_Knife_Teams_Admin::NONCE_FIELD => wp_create_nonce( Chess_Army_Knife_Teams_Admin::NONCE_ACTION ) );
			Chess_Army_Knife_Teams_Admin::save( $this->team_b );
			$_POST = array();
			return Chess_Army_Knife_Teams::get( $this->team_b )['colour'];
		};

		$this->assertSame( '#ff8800', $post( array( 'chess_army_team_colour' => '#ff8800' ) ) );
		$this->assertSame( '', $post( array( 'chess_army_team_colour' => 'javascript:alert(1)' ) ) );
		$this->assertSame(
			'',
			$post(
				array(
					'chess_army_team_colour'    => '#ff8800',
					'chess_army_team_no_colour' => '1',
				)
			)
		);
	}
}
