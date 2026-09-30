<?php
/**
 * Tests that the block categories and the block help stay in step with the blocks.
 *
 * @package Chess_Army_Knife
 */

class BlockHelpTest extends Chess_Army_Knife_TestCase {

	/**
	 * Each block's folder name and its block.json, read from the source.
	 *
	 * @return array[] block.json contents by block name without the plugin's prefix.
	 */
	protected function blocks() {
		$blocks = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/src/*/block.json' ) as $file ) {
			$blocks[ basename( dirname( $file ) ) ] = json_decode( file_get_contents( $file ), true );
		}
		return $blocks;
	}

	public function test_there_are_blocks_to_check() {
		$this->assertGreaterThan( 15, count( $this->blocks() ) );
	}

	public function test_every_block_is_in_one_of_the_plugins_categories() {
		$categories = Chess_Army_Knife_Block_Help::categories();

		foreach ( $this->blocks() as $slug => $json ) {
			$this->assertArrayHasKey( $json['category'], $categories, $slug . ' uses a category the plugin does not define.' );
		}
	}

	public function test_no_category_is_empty() {
		$used = array_unique( array_column( $this->blocks(), 'category' ) );

		$this->assertEqualsCanonicalizing( array_keys( Chess_Army_Knife_Block_Help::categories() ), $used );
	}

	public function test_every_block_has_help_and_every_help_entry_has_a_block() {
		$blocks  = array_keys( $this->blocks() );
		$entries = array_keys( Chess_Army_Knife_Block_Help::entries() );

		$this->assertEqualsCanonicalizing( $blocks, $entries, 'Add or remove the block\'s entry in Chess_Army_Knife_Block_Help::entries().' );
	}

	public function test_every_help_entry_says_what_the_block_is_for_and_is_complete() {
		foreach ( Chess_Army_Knife_Block_Help::entries() as $slug => $entry ) {
			$this->assertSame( array( 'use', 'settings', 'needs', 'where' ), array_keys( $entry ), $slug );
			$this->assertNotSame( '', $entry['use'], $slug );
			$this->assertIsArray( $entry['settings'], $slug );
			$this->assertIsArray( $entry['needs'], $slug );
			$this->assertIsArray( $entry['where'], $slug );
		}
	}

	public function test_the_block_name_matches_its_folder() {
		foreach ( $this->blocks() as $slug => $json ) {
			$this->assertSame( Chess_Army_Knife_Block_Help::NAMESPACE_ . $slug, $json['name'] );
		}
	}

	public function test_the_categories_are_added_to_the_inserter_after_the_existing_ones() {
		$existing = array(
			array(
				'slug'  => 'text',
				'title' => 'Text',
			),
		);

		$all = Chess_Army_Knife_Block_Help::add_categories( $existing );

		$this->assertSame( 'text', $all[0]['slug'] );
		$this->assertSame( array_keys( Chess_Army_Knife_Block_Help::categories() ), array_column( array_slice( $all, 1 ), 'slug' ) );
		foreach ( array_slice( $all, 1 ) as $category ) {
			$this->assertNotSame( '', $category['title'] );
		}
	}
}
