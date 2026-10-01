<?php
/**
 * Integration tests: the accessibility features that depend on WordPress.
 *
 * @package Chess_Army_Knife
 */

class AccessibilityTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( 'Chess_Army_Knife_settings', array( 'use_local_cache' => 0 ) );
		foreach ( array( 'games', 'entries', 'tournaments', 'members' ) as $name ) {
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Chess_Army_Knife_Tournament_Store::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		Chess_Army_Knife_Tournament_Store::install_tables();
		$_GET = array();
	}

	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	private function render( $block, array $attributes = array() ) {
		return do_blocks( '<!-- wp:chess-army-knife/' . $block . ' ' . wp_json_encode( (object) $attributes ) . ' /-->' );
	}

	/**
	 * A started tournament of four manually rated players.
	 *
	 * @return int Tournament id.
	 */
	private function tournament() {
		$id = Chess_Army_Knife_Tournaments::create( array( 'name' => 'Club Cup' ) );
		for ( $i = 1; $i <= 4; $i++ ) {
			$player = Chess_Army_Knife_Membership_Store::add_guest(
				array(
					'name'          => "Player {$i}",
					'ecf_code'      => '',
					'manual_rating' => 2200 - $i * 10,
				)
			);
			Chess_Army_Knife_Tournaments::add_player( $id, $player );
		}
		Chess_Army_Knife_Tournaments::start( $id );
		return $id;
	}

	public function test_block_titles_are_headings_at_the_level_chosen_in_settings() {
		$id = $this->tournament();

		$this->assertStringContainsString( '<h2 class="cak-status__title cak-block-title">', $this->render( 'tournament-status', array( 'tournamentId' => $id ) ) );

		update_option(
			'Chess_Army_Knife_settings',
			array(
				'use_local_cache' => 0,
				'heading_level'   => 3,
			)
		);
		$this->assertStringContainsString( '<h3 class="cak-status__title cak-block-title">', $this->render( 'tournament-status', array( 'tournamentId' => $id ) ) );
	}

	public function test_standings_table_has_a_caption_and_scoped_headers() {
		$id   = $this->tournament();
		$html = $this->render( 'tournament-standings', array( 'tournamentId' => $id ) );

		$this->assertStringContainsString( '<caption class="cak-visually-hidden">Club Cup standings</caption>', $html );
		$this->assertStringContainsString( '<th scope="col"', $html );
		$this->assertStringContainsString( '<th scope="row">', $html );
		$this->assertStringContainsString( '<abbr title="Round 1">R1</abbr>', $html );
		$this->assertStringContainsString( 'role="region"', $html, 'A wide table can be scrolled with the keyboard.' );
		$this->assertStringContainsString( 'tabindex="0"', $html );
	}

	public function test_players_and_winners_tables_have_captions() {
		$id = $this->tournament();

		$this->assertStringContainsString( '<caption class="cak-visually-hidden">Players in Club Cup</caption>', $this->render( 'tournament-players', array( 'tournamentId' => $id ) ) );
		foreach ( Chess_Army_Knife_Tournaments::games_to_play( $id ) as $game ) {
			Chess_Army_Knife_Tournaments::record_result( $game['id'], '1-0' );
		}
		$this->assertStringContainsString( '<caption class="cak-visually-hidden">Past winners</caption>', $this->render( 'tournament-winners' ) );
	}

	public function test_games_say_who_has_white_and_black() {
		$id   = $this->tournament();
		$html = $this->render( 'tournament-games', array( 'tournamentId' => $id ) );

		$this->assertStringContainsString( '(White)', $html );
		$this->assertStringContainsString( '(Black)', $html );
		$this->assertStringContainsString( '<h3 class="cak-games__round">', $html );
	}

	public function test_body_class_follows_the_high_contrast_setting() {
		$this->assertNotContains( 'cak-contrast-always', Chess_Army_Knife_contrast_body_class( array() ) );
		$this->assertNotContains( 'cak-contrast-off', Chess_Army_Knife_contrast_body_class( array() ) );

		update_option( 'Chess_Army_Knife_settings', array( 'contrast_mode' => 'always' ) );
		$this->assertContains( 'cak-contrast-always', Chess_Army_Knife_contrast_body_class( array() ) );

		update_option( 'Chess_Army_Knife_settings', array( 'contrast_mode' => 'off' ) );
		$this->assertContains( 'cak-contrast-off', Chess_Army_Knife_contrast_body_class( array() ) );
	}

	public function test_a_form_that_comes_back_with_an_error_keeps_what_was_typed_and_names_the_field() {
		set_transient(
			Chess_Army_Knife_Form_State::PREFIX . 'abc123',
			array(
				'email'  => 'ann@example',
				'choice' => 'export',
			),
			300
		);
		$_GET = array(
			'cak_form'       => 'abc123',
			'cak_data_error' => 'email',
		);

		$html = $this->render( 'my-data' );

		$this->assertStringContainsString( 'value="ann@example"', $html );
		$this->assertMatchesRegularExpression( '/value="export"\s+checked=\'checked\'/', $html );
		$this->assertStringContainsString( 'Problem:', $html );
		$this->assertStringContainsString( 'href="#cak-data-email"', $html );
		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertStringContainsString( 'aria-describedby="cak-data-notice"', $html );
		$this->assertStringContainsString( '(required)', $html );
	}

	public function test_the_chart_needs_no_script() {
		$block_json = json_decode( file_get_contents( Chess_Army_Knife_DIR . 'build/rating-chart/block.json' ), true );
		$this->assertArrayNotHasKey( 'viewScript', $block_json );
		$this->assertFileDoesNotExist( Chess_Army_Knife_DIR . 'build/rating-chart/view.js' );
	}

	public function test_the_fixtures_block_is_a_list_unless_a_carousel_is_asked_for_and_never_moves_by_default() {
		$attributes = json_decode( file_get_contents( Chess_Army_Knife_DIR . 'build/team-carousel/block.json' ), true )['attributes'];
		$this->assertSame( 'list', $attributes['layout']['default'] );
		$this->assertFalse( $attributes['autoAdvance']['default'] );
		$this->assertGreaterThanOrEqual( 5, $attributes['intervalSeconds']['default'] );
	}

	public function test_the_admin_accessibility_styles_reach_only_the_plugins_screens() {
		set_current_screen( 'toplevel_page_chess-army-knife' );
		$this->assertTrue( Chess_Army_Knife_Menu::is_plugin_screen() );
		$this->assertStringContainsString( 'cak-admin-screen', Chess_Army_Knife_Menu::body_class( '' ) );

		set_current_screen( 'edit-' . Chess_Army_Knife_Teams::POST_TYPE );
		$this->assertTrue( Chess_Army_Knife_Menu::is_plugin_screen() );

		set_current_screen( 'edit-post' );
		$this->assertFalse( Chess_Army_Knife_Menu::is_plugin_screen() );
		$this->assertSame( 'wp-admin', Chess_Army_Knife_Menu::body_class( 'wp-admin' ) );
	}
}
