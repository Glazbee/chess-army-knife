<?php
/**
 * Integration tests: the Club Event Calendar's month grid and its REST route.
 *
 * @package Chess_Army_Knife
 */

class EventCalendarMonthTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		update_option( 'start_of_week', 1 );
		foreach ( array( 'games', 'entries', 'tournaments', 'members' ) as $name ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Tournament_Store::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Tournament_Store::install_tables();
	}

	private function event( $title, $start, array $tags = array() ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, Chess_Army_Knife_Events::META_START, $start );
		if ( $tags ) {
			wp_set_object_terms( $id, $tags, Chess_Army_Knife_Events::TAXONOMY );
		}
		return $id;
	}

	private function month_request( array $params ) {
		$request = new WP_REST_Request( 'GET', '/chess-army-knife/v1/events-month' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/**
	 * A month in the near future, as "YYYY-MM" and its year and month.
	 *
	 * @param int $offset Months from now.
	 * @return array
	 */
	private function month_from_now( $offset ) {
		$now   = Chess_Army_Knife_Events_Display::parse_month( current_time( 'Y-m' ) );
		$month = Chess_Army_Knife_Events_Display::shift_month( $now[0], $now[1], $offset );
		return array_merge( array( $month ), Chess_Army_Knife_Events_Display::parse_month( $month ) );
	}

	public function test_the_month_layout_shows_this_months_events_including_earlier_days() {
		list( $month ) = $this->month_from_now( 0 );
		$this->event( 'Early night', $month . '-01 19:00:00' );
		$this->event( 'Late night', $month . '-28 19:00:00' );
		list( $next ) = $this->month_from_now( 1 );
		$this->event( 'Next month night', $next . '-10 19:00:00' );

		$html = do_blocks( '<!-- wp:chess-army-knife/club-event-calendar {"layout":"month"} /-->' );

		$this->assertStringContainsString( 'data-cak-month', $html );
		$this->assertStringContainsString( 'data-month="' . $month . '"', $html );
		$this->assertStringContainsString( 'Early night', $html, 'Days already past this month are shown.' );
		$this->assertStringContainsString( 'Late night', $html );
		$this->assertStringNotContainsString( 'Next month night', $html );
		$this->assertStringContainsString( '<table class="cak-month__table">', $html );
		$this->assertStringContainsString( 'cak-bubble__summary', $html, 'Each event is a bubble.' );
	}

	public function test_the_month_layout_respects_the_tag_filter() {
		list( $month ) = $this->month_from_now( 0 );
		$this->event( 'Club blitz', $month . '-10 19:00:00', array( 'In-house' ) );
		$this->event( 'Open day', $month . '-11 19:00:00', array( 'Tournament' ) );

		$html = do_blocks( '<!-- wp:chess-army-knife/club-event-calendar {"layout":"month","tags":["in-house"]} /-->' );

		$this->assertStringContainsString( 'Club blitz', $html );
		$this->assertStringNotContainsString( 'Open day', $html );
		$this->assertStringContainsString( 'data-tags="in-house"', $html );
	}

	public function test_the_default_layout_is_still_the_agenda() {
		$html = do_blocks( '<!-- wp:chess-army-knife/club-event-calendar /-->' );

		$this->assertStringNotContainsString( 'data-cak-month', $html );
		$this->assertStringContainsString( 'No upcoming events.', $html );
	}

	public function test_the_route_returns_a_month_with_its_neighbours() {
		list( $month, $year, $number ) = $this->month_from_now( 2 );
		$this->event( 'Future night', $month . '-15 19:00:00' );

		$response = $this->month_request( array( 'month' => $month ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $month, $data['month'] );
		$this->assertSame( Chess_Army_Knife_Events_Display::month_label( $year, $number ), $data['label'] );
		$this->assertSame( Chess_Army_Knife_Events_Display::shift_month( $year, $number, -1 ), $data['prev'] );
		$this->assertSame( Chess_Army_Knife_Events_Display::shift_month( $year, $number, 1 ), $data['next'] );
		$this->assertStringContainsString( 'Future night', $data['html'] );
	}

	public function test_the_route_filters_by_tag() {
		list( $month ) = $this->month_from_now( 1 );
		$this->event( 'Club blitz', $month . '-10 19:00:00', array( 'In-house' ) );
		$this->event( 'Open day', $month . '-11 19:00:00', array( 'Tournament' ) );

		$data = $this->month_request(
			array(
				'month' => $month,
				'tags'  => 'in-house',
			)
		)->get_data();

		$this->assertStringContainsString( 'Club blitz', $data['html'] );
		$this->assertStringNotContainsString( 'Open day', $data['html'] );
	}

	public function test_the_route_rejects_a_bad_or_faraway_month() {
		foreach ( array( 'nonsense', '2026-13', '2026-1', '1900-01', '2999-01' ) as $bad ) {
			$this->assertSame( 400, $this->month_request( array( 'month' => $bad ) )->get_status(), $bad );
		}
	}

	public function test_the_route_is_public() {
		wp_set_current_user( 0 );
		list( $month ) = $this->month_from_now( 0 );

		$this->assertSame( 200, $this->month_request( array( 'month' => $month ) )->get_status() );
	}

	public function test_drafts_are_not_in_the_grid() {
		list( $month ) = $this->month_from_now( 1 );
		$id            = $this->event( 'Secret night', $month . '-10 19:00:00' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);

		$data = $this->month_request( array( 'month' => $month ) )->get_data();

		$this->assertStringNotContainsString( 'Secret night', $data['html'] );
	}
}
