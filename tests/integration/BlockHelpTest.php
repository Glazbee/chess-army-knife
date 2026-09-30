<?php
/**
 * Integration tests: the block categories and the Block Help screen.
 *
 * @package Chess_Army_Knife
 */

class BlockHelpTest extends WP_UnitTestCase {

	public function test_every_block_is_registered_in_one_of_the_plugins_categories() {
		$registry = WP_Block_Type_Registry::get_instance();
		$grouped  = Chess_Army_Knife_Block_Help::grouped_blocks();

		$this->assertSame( count( Chess_Army_Knife_Block_Help::entries() ), array_sum( array_map( 'count', $grouped ) ), 'Every block with help is registered.' );
		foreach ( Chess_Army_Knife_Block_Help::entries() as $slug => $entry ) {
			$block = $registry->get_registered( Chess_Army_Knife_Block_Help::NAMESPACE_ . $slug );
			$this->assertNotNull( $block, $slug );
			$this->assertArrayHasKey( $block->category, Chess_Army_Knife_Block_Help::categories(), $slug );
		}
	}

	public function test_the_categories_are_offered_by_the_block_editor() {
		$slugs = wp_list_pluck( apply_filters( 'block_categories_all', array(), new WP_Block_Editor_Context() ), 'slug' );

		foreach ( array_keys( Chess_Army_Knife_Block_Help::categories() ) as $category ) {
			$this->assertContains( $category, $slugs );
		}
	}

	public function test_every_screen_a_block_is_set_up_in_exists() {
		foreach ( Chess_Army_Knife_Block_Help::entries() as $slug => $entry ) {
			foreach ( $entry['where'] as $key ) {
				$this->assertNotNull( Chess_Army_Knife_Menu::area( $key ), $slug . ' points at a screen that does not exist: ' . $key );
			}
		}
	}

	public function test_anyone_can_read_the_help_which_lists_each_block_under_its_group() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		Chess_Army_Knife_Block_Help::render_page();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'You do not have permission', $html );
		$this->assertStringContainsString( 'Adding a block', $html );
		foreach ( Chess_Army_Knife_Block_Help::categories() as $title ) {
			$this->assertStringContainsString( esc_html( $title ), $html );
		}
		$this->assertStringContainsString( 'ECF Rating Chart', $html );
		$this->assertStringContainsString( 'Membership Application Form', $html );
		$this->assertStringContainsString( 'Tournament Standings', $html );
		$this->assertLessThan( strpos( $html, 'ECF League Standings' ), strpos( $html, 'Chess: Leagues &amp; Teams' ), 'A block is listed after its group heading.' );
	}

	public function test_the_help_is_in_the_menu_for_everyone() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		global $menu, $submenu;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$menu    = array();
		$submenu = array();
		do_action( 'admin_menu' );

		$this->assertContains( Chess_Army_Knife_Block_Help::PAGE, wp_list_pluck( $submenu[ Chess_Army_Knife_Menu::SLUG ], 2 ) );
	}
}
