<?php
/**
 * Tests for Chess_Army_Knife_Access and the menu's permission setting.
 *
 * @package Chess_Army_Knife
 */

class AccessTest extends Chess_Army_Knife_TestCase {

	public function test_someone_who_may_use_a_screen_gets_no_notice() {
		ob_start();
		$go_on = Chess_Army_Knife_Access::render_unless( true, 'Members', 'members' );
		$html  = ob_get_clean();

		$this->assertTrue( $go_on );
		$this->assertSame( '', $html );
	}

	public function test_someone_who_may_not_is_told_what_the_screen_needs_and_how_to_get_it() {
		ob_start();
		$go_on = Chess_Army_Knife_Access::render_unless( false, 'Members', 'members' );
		$html  = ob_get_clean();

		$this->assertFalse( $go_on );
		$this->assertStringContainsString( '<h1>Members</h1>', $html );
		$this->assertStringContainsString( 'You do not have permission to use this page.', $html );
		$this->assertStringContainsString( '&quot;manage members&quot; permission', $html );
		$this->assertStringContainsString( 'Ask a club administrator', $html );
	}

	public function test_each_kind_of_screen_says_something_different() {
		$needs = array();
		foreach ( array_keys( Chess_Army_Knife_Access::requirements() ) as $kind ) {
			ob_start();
			Chess_Army_Knife_Access::render_notice( 'Screen', $kind );
			$needs[ $kind ] = ob_get_clean();
		}

		$this->assertSame( array_keys( Chess_Army_Knife_Access::requirements() ), array_keys( $needs ) );
		$this->assertStringContainsString( 'captain', $needs['selection'] );
		$this->assertStringContainsString( 'administrator', $needs['settings'] );
		$this->assertStringContainsString( 'club teams', $needs['teams'] );
	}

	public function test_an_unknown_kind_still_explains_rather_than_failing() {
		ob_start();
		Chess_Army_Knife_Access::render_notice( 'Screen', 'nonsense' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'You do not have permission to use this page.', $html );
	}

	public function test_every_kind_has_a_short_label_for_the_overview() {
		foreach ( array_keys( Chess_Army_Knife_Access::requirements() ) as $kind ) {
			$this->assertNotSame( '', Chess_Army_Knife_Access::label( $kind ), $kind );
		}
		$this->assertSame( '', Chess_Army_Knife_Access::label( 'nonsense' ) );
	}

	public function test_the_menu_is_for_everyone_signed_in_unless_the_site_says_otherwise() {
		$this->assertSame( 'read', Chess_Army_Knife_Menu::capability() );

		\Brain\Monkey\Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'Chess_Army_Knife_menu_capability' === $hook ? 'edit_posts' : $value;
			}
		);
		$this->assertSame( 'edit_posts', Chess_Army_Knife_Menu::capability() );
	}
}
