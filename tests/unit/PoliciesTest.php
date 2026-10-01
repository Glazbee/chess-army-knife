<?php
/**
 * Tests for the markup Chess_Army_Knife_Policies writes.
 *
 * @package Chess_Army_Knife
 */

class PoliciesTest extends Chess_Army_Knife_TestCase {

	public function test_sections_become_headings_paragraphs_and_lists() {
		$markup = Chess_Army_Knife_Policies::blocks(
			array(
				array(
					'heading'    => 'Our promise',
					'paragraphs' => array( 'First.', 'Second.' ),
					'items'      => array( 'One', 'Two' ),
				),
			)
		);

		$this->assertStringContainsString( "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Our promise</h2>\n<!-- /wp:heading -->", $markup );
		$this->assertSame( 2, substr_count( $markup, '<!-- wp:paragraph -->' ) );
		$this->assertSame( 2, substr_count( $markup, '<!-- wp:list-item -->' ) );
		$this->assertStringContainsString( '<ul class="wp-block-list">', $markup );
	}

	public function test_text_is_escaped_because_sections_are_plain_text() {
		$markup = Chess_Army_Knife_Policies::blocks(
			array(
				array(
					'heading'    => '<b>Heading</b>',
					'paragraphs' => array( '<script>alert(1)</script>' ),
					'items'      => array( '<img src=x onerror=alert(1)>' ),
				),
			)
		);

		$this->assertStringNotContainsString( '<script>', $markup );
		$this->assertStringNotContainsString( '<b>', $markup );
		$this->assertStringNotContainsString( '<img', $markup );
		$this->assertStringContainsString( '&lt;script&gt;', $markup );
	}

	public function test_the_link_shortcode_survives_escaping() {
		$markup = Chess_Army_Knife_Policies::blocks(
			array(
				array(
					'heading'    => 'H',
					'paragraphs' => array( 'See our [chess_army_policy_link policy=data].' ),
				),
			)
		);

		$this->assertStringContainsString( '[chess_army_policy_link policy=data]', $markup );
	}

	public function test_a_section_with_nothing_but_a_heading_is_fine() {
		$markup = Chess_Army_Knife_Policies::blocks( array( array( 'heading' => 'Only a heading' ) ) );
		$this->assertStringContainsString( 'Only a heading', $markup );
		$this->assertStringNotContainsString( 'wp:paragraph', $markup );
	}

	public function test_there_are_three_policies_each_with_a_title_and_a_slug() {
		$policies = Chess_Army_Knife_Policies::policies();

		$this->assertSame( array( 'data', 'safeguarding', 'privacy' ), array_keys( $policies ) );
		foreach ( $policies as $policy ) {
			$this->assertNotSame( '', $policy['title'] );
			$this->assertMatchesRegularExpression( '/^[a-z-]+$/', $policy['slug'] );
		}
	}
}
