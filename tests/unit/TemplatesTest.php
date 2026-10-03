<?php
/**
 * Tests for Chess_Army_Knife_Templates.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class TemplatesTest extends Chess_Army_Knife_TestCase {

	private function add_template( $id, $block, array $values ) {
		$this->options[ Chess_Army_Knife_Templates::OPTION ][ $id ] = array(
			'id'     => $id,
			'name'   => "Template {$id}",
			'block'  => $block,
			'values' => $values,
		);
	}

	public function test_get_all_tolerates_corrupt_option() {
		$this->options[ Chess_Army_Knife_Templates::OPTION ] = 'garbage';

		$this->assertSame( array(), Chess_Army_Knife_Templates::get_all() );
	}

	public function test_get_returns_null_for_unknown_or_empty_id() {
		$this->add_template( 't1', 'rating-chart', array() );

		$this->assertNull( Chess_Army_Knife_Templates::get( '' ) );
		$this->assertNull( Chess_Army_Knife_Templates::get( 'nope' ) );
		$this->assertSame( 't1', Chess_Army_Knife_Templates::get( 't1' )['id'] );
	}

	public function test_list_for_block_filters_by_block_type() {
		$this->add_template( 't1', 'rating-chart', array() );
		$this->add_template( 't2', 'club-results', array() );

		$this->assertSame(
			array(
				array(
					'id'   => 't1',
					'name' => 'Template t1',
				),
			),
			Chess_Army_Knife_Templates::list_for_block( 'rating-chart' )
		);
	}

	public function test_every_block_type_has_fields() {
		foreach ( array_keys( Chess_Army_Knife_Templates::block_types() ) as $slug ) {
			$this->assertNotEmpty( Chess_Army_Knife_Templates::fields( $slug ), $slug );
		}
	}

	public function test_apply_without_template_returns_attributes_unchanged() {
		$attributes = array(
			'templateId' => '',
			'domain'     => 'R',
		);

		$this->assertSame( $attributes, Chess_Army_Knife_Templates::apply( 'rating-chart', $attributes ) );
	}

	public function test_apply_ignores_template_for_a_different_block() {
		$this->add_template( 't1', 'club-results', array( 'domain' => 'B' ) );
		$attributes = array(
			'templateId' => 't1',
			'domain'     => 'R',
		);

		$this->assertSame( $attributes, Chess_Army_Knife_Templates::apply( 'rating-chart', $attributes ) );
	}

	public function test_apply_overrides_block_settings_with_template_values() {
		$settings_field = null;
		foreach ( Chess_Army_Knife_Templates::fields( 'featured-player' ) as $field ) {
			if ( 'settings' === $field['group'] && 'select' === $field['type'] && isset( $field['options']['1'], $field['options']['0'] ) ) {
				$settings_field = $field['key'];
				break;
			}
		}
		$this->assertNotNull( $settings_field, 'Expected a Yes/No setting on featured-player.' );

		$this->add_template( 't1', 'featured-player', array( $settings_field => '0' ) );

		$result = Chess_Army_Knife_Templates::apply(
			'featured-player',
			array(
				'templateId'    => 't1',
				$settings_field => true,
			)
		);

		$this->assertFalse( $result[ $settings_field ] );
	}

	public function test_apply_skips_blank_template_values() {
		$this->add_template( 't1', 'rating-chart', array( 'domain' => '' ) );
		$attributes = array(
			'templateId' => 't1',
			'domain'     => 'R',
		);

		$this->assertSame( 'R', Chess_Army_Knife_Templates::apply( 'rating-chart', $attributes )['domain'] );
	}

	public function test_rating_chart_accent_becomes_line_colour() {
		$this->add_template( 't1', 'rating-chart', array( 'accent' => '#ff0000' ) );

		$result = Chess_Army_Knife_Templates::apply( 'rating-chart', array( 'templateId' => 't1' ) );

		$this->assertSame( '#ff0000', $result['lineColor'] );
	}

	public function test_wrapper_attributes_without_template_uses_plain_wrapper() {
		Functions\expect( 'get_block_wrapper_attributes' )->once()->with( array() )->andReturn( 'class="plain"' );

		$this->assertSame( 'class="plain"', Chess_Army_Knife_Templates::wrapper_attributes( 'rating-chart', array() ) );
	}

	public function test_wrapper_attributes_builds_style_from_valid_values_only() {
		Functions\when( 'sanitize_hex_color' )->alias(
			function ( $color ) {
				return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ? $color : null;
			}
		);
		Functions\when( 'sanitize_html_class' )->returnArg();
		$this->add_template(
			't1',
			'rating-chart',
			array(
				'accent' => '#ff0000',
				'bg'     => 'red; evil',
				'radius' => '8',
			)
		);

		$captured = null;
		Functions\when( 'get_block_wrapper_attributes' )->alias(
			function ( $args ) use ( &$captured ) {
				$captured = $args;
				return 'wrapped';
			}
		);

		$this->assertSame( 'wrapped', Chess_Army_Knife_Templates::wrapper_attributes( 'rating-chart', array( 'templateId' => 't1' ) ) );
		$this->assertSame(
			array(
				'class' => 'ecf-tpl-t1',
				'style' => '--ecf-accent:#ff0000;--ecf-accent-soft:#ff000022;border-radius:8px;overflow:hidden',
			),
			$captured
		);
	}

	public function test_custom_css_scopes_and_strips_tags_once_per_template() {
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		$this->add_template( 't1', 'rating-chart', array( 'custom_css' => '{block} a{color:red}<script>x</script>' ) );

		$first = Chess_Army_Knife_Templates::custom_css( array( 'templateId' => 't1' ) );

		$this->assertStringContainsString( '.ecf-tpl-t1 a{color:red}', $first );
		$this->assertStringNotContainsString( '<script>', $first );
		$this->assertSame( '', Chess_Army_Knife_Templates::custom_css( array( 'templateId' => 't1' ) ) );
	}
}
