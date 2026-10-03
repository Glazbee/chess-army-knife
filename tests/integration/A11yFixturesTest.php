<?php
/**
 * Writes the plugin's block markup to HTML files so axe-core can check it in CI (see
 * scripts/axe-check.js). It does nothing unless CAK_A11Y_DIR names a folder, so a normal run of the
 * tests is not slowed or changed. A fixture that cannot be made is a failure, so the check cannot
 * quietly cover less than it should.
 *
 * @package Chess_Army_Knife
 */

class A11yFixturesTest extends WP_UnitTestCase {

	/** @var string Folder to write to. */
	private $dir = '';

	public function set_up() {
		parent::set_up();

		$this->dir = (string) getenv( 'CAK_A11Y_DIR' );
		if ( '' === $this->dir ) {
			$this->markTestSkipped( 'Set CAK_A11Y_DIR to write the accessibility fixtures.' );
		}
		if ( ! is_dir( $this->dir ) ) {
			mkdir( $this->dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- A folder for test output.
		}

		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'lms_api_key'     => 'secret',
			)
		);
		add_filter( 'pre_http_request', array( $this, 'serve_lms' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'serve_lms' ), 10 );
		parent::tear_down();
	}

	/**
	 * Answer LMS v2 requests with the small invented league in tests/fixtures/lms-v2-results.json.
	 */
	public function serve_lms( $preempt, $args, $url ) {
		$base = Chess_Army_Knife_LMS_Client::V2_BASE . '/';
		if ( 0 !== strpos( $url, $base ) ) {
			return $preempt;
		}

		$bodies = array(
			'org/270/seasons'  => array(
				'seasons' => array(
					array(
						'id'     => 1,
						'name'   => '2025-2026',
						'status' => 'old',
					),
				),
			),
			'season/1/events'  => array(
				'events' => array(
					array(
						'id'   => 20,
						'name' => 'Division 1',
						'type' => 'team_league',
					),
				),
			),
			'event/20/results' => json_decode( (string) file_get_contents( Chess_Army_Knife_DIR . 'tests/fixtures/lms-v2-results.json' ), true ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local fixture.
		);
		$path   = substr( $url, strlen( $base ) );

		return array(
			'headers'  => array(),
			'body'     => isset( $bodies[ $path ] ) ? wp_json_encode( $bodies[ $path ] ) : '{}',
			'response' => array(
				'code'    => isset( $bodies[ $path ] ) ? 200 : 404,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Render a block and write it as a page of its own.
	 *
	 * @param string $name       File name without the extension.
	 * @param string $block      Block slug.
	 * @param array  $attributes Block attributes.
	 */
	private function write( $name, $block, array $attributes = array() ) {
		$html = do_blocks( '<!-- wp:chess-army-knife/' . $block . ' ' . wp_json_encode( (object) $attributes ) . ' /-->' );
		$this->assertGreaterThan( 100, strlen( trim( $html ) ), $name . ' rendered almost nothing, so there is nothing to check.' );

		$page = '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><title>' . esc_html( $name ) . '</title></head><body><main>' . $html . '</main></body></html>';
		$this->assertNotFalse( file_put_contents( $this->dir . '/' . $name . '.html', $page ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test output.
	}

	private function event( $title, $start, array $tags = array(), array $meta = array() ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, Chess_Army_Knife_Events::META_START, $start );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		if ( $tags ) {
			wp_set_object_terms( $id, $tags, Chess_Army_Knife_Events::TAXONOMY );
		}
		return $id;
	}

	public function test_the_event_blocks() {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$this->event( 'Club night', '2099-01-05 19:30:00', array( 'Club night' ), array( Chess_Army_Knife_Events::META_LOCATION => 'The Library' ) );
		$this->event(
			'Cancelled blitz',
			'2099-01-06 19:30:00',
			array( 'Blitz' ),
			array(
				Chess_Army_Knife_Events::META_STATUS      => 'cancelled',
				Chess_Army_Knife_Events::META_STATUS_NOTE => 'Hall closed',
			)
		);
		$attached = $this->event( 'Summer Cup', '2099-01-07 10:00:00', array( 'Tournament' ), array( Chess_Army_Knife_Events::META_PAGE => $page ) );

		$this->write( 'calendar-agenda', 'club-event-calendar', array( 'layout' => 'agenda' ) );
		$this->write( 'calendar-month', 'club-event-calendar', array( 'layout' => 'month' ) );
		$this->write( 'next-event-three', 'next-club-event', array( 'show' => 'three' ) );

		$GLOBALS['post'] = get_post( $page ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The details block reads the page being viewed.
		setup_postdata( $GLOBALS['post'] );
		$this->write( 'event-details', 'club-event-details' );
		$this->assertNotSame( 0, $attached );
	}

	public function test_the_membership_blocks() {
		foreach ( array(
			'Adult'  => 0,
			'Junior' => 1,
		) as $name => $junior ) {
			$id = self::factory()->post->create(
				array(
					'post_type'   => Chess_Army_Knife_Memberships::POST_TYPE,
					'post_title'  => $name,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $id, Chess_Army_Knife_Memberships::META_PRICE, 2500 );
			update_post_meta( $id, Chess_Army_Knife_Memberships::META_MONTHS, 12 );
			update_post_meta( $id, Chess_Army_Knife_Memberships::META_JUNIOR, (string) $junior );
		}

		$this->write( 'memberships', 'memberships' );
		$this->write( 'membership-form', 'membership-form' );
		$this->write( 'manage-my-data', 'my-data' );
		$this->write( 'member-portal', 'member-portal' );
	}

	public function test_the_officers_block() {
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Officers::install_table();
		Chess_Army_Knife_Officers::save_positions( array( array( 'name' => 'Chairman' ), array( 'name' => 'Secretary' ) ) );
		foreach ( Chess_Army_Knife_Officers::positions() as $position ) {
			Chess_Army_Knife_Officers::assign(
				$position['id'],
				Chess_Army_Knife_Membership_Store::save_member(
					array(
						'name'   => 'Officer ' . $position['name'],
						'email'  => strtolower( $position['name'] ) . '@example.test',
						'status' => 'active',
					)
				),
				'2024-03-12'
			);
		}

		$this->write( 'officers', 'officers', array( 'showTenure' => true ) );
	}

	public function test_the_league_blocks() {
		$league = array(
			'orgId'     => '270',
			'eventName' => 'Division 1',
			'season'    => '2025-2026',
		);

		$this->write( 'league-table', 'league-table', $league );
		$this->write( 'team-fixtures', 'team-carousel', array_merge( $league, array( 'teamSource' => 'auto' ) ) );
		$this->write( 'team-results', 'team-page', array_merge( $league, array( 'team' => 'Test Club A' ) ) );
	}
}
