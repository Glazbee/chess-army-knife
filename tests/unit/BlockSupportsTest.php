<?php
/**
 * Every block offers WordPress's own styling controls, so a site can change colours, spacing,
 * type and borders from the editor without custom CSS.
 *
 * @package Chess_Army_Knife
 */

class BlockSupportsTest extends Chess_Army_Knife_TestCase {

	/**
	 * Each block's block.json, by slug.
	 *
	 * @return array[]
	 */
	private function blocks() {
		$blocks = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/src/*/block.json' ) as $path ) {
			$blocks[ basename( dirname( $path ) ) ] = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in a test.
		}

		return $blocks;
	}

	public function test_every_block_offers_colour_spacing_typography_and_border_controls() {
		$this->assertNotEmpty( $this->blocks() );

		foreach ( $this->blocks() as $slug => $json ) {
			foreach ( array( 'color', 'spacing', 'typography', 'border' ) as $support ) {
				$this->assertArrayHasKey( $support, $json['supports'], "$slug should support $support." );
			}
			$this->assertFalse( $json['supports']['html'], "$slug is a dynamic block, so no HTML editing." );
		}
	}

	public function test_the_built_blocks_match_the_sources() {
		foreach ( $this->blocks() as $slug => $json ) {
			$built = dirname( __DIR__, 2 ) . '/build/' . $slug . '/block.json';
			$this->assertFileExists( $built, "$slug has not been built." );
			$this->assertSame( $json['supports'], json_decode( (string) file_get_contents( $built ), true )['supports'], "$slug: run npm run build." ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in a test.
		}
	}
}
