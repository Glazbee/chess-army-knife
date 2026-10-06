<?php
/**
 * Integration tests: the order of the plugin's menu.
 *
 * @package Chess_Army_Knife
 */

class MenuOrderTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_the_menu_items_are_in_the_order_the_club_asked_for() {
		$this->assertSame(
			array( 'Announcements', 'Dashboard', 'Seasons', 'Members', 'Officers', 'Teams', 'Tournaments', 'Club Events', 'Other Clubs', 'Templates', 'Setup', 'Settings', 'Block Help' ),
			wp_list_pluck( Chess_Army_Knife_Menu::listed_areas(), 'title' )
		);
	}

	public function test_a_tab_has_no_menu_item() {
		$keys = wp_list_pluck( Chess_Army_Knife_Menu::listed_areas(), 'key' );

		foreach ( array( 'member_checks', 'renewals', 'membership_types', 'team_groups', 'league_games', 'policies' ) as $tab ) {
			$this->assertNotContains( $tab, $keys, $tab );
		}
	}

	public function test_every_screen_in_the_menu_order_exists() {
		$keys = wp_list_pluck( Chess_Army_Knife_Menu::areas(), 'key' );

		$this->assertSame( array(), array_diff( Chess_Army_Knife_Menu::menu_order(), $keys ) );
	}

	public function test_the_overview_still_groups_the_screens_its_own_way() {
		$groups = array();
		foreach ( Chess_Army_Knife_Menu::areas() as $area ) {
			$groups[ $area['group'] ][] = $area['key'];
		}

		$this->assertSame( array( 'Members', 'Teams', 'Club', 'Setup', 'Help' ), array_keys( $groups ) );
		$this->assertContains( 'seasons', $groups['Club'] );
	}
}
