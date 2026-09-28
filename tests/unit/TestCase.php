<?php
/**
 * Base test case: boots Brain Monkey and stubs common WordPress helpers.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class Chess_Army_Knife_TestCase extends PHPUnit\Framework\TestCase {

	/** @var array Fake option store used by get_option(). */
	protected $options = array();

	/** @var array Fake transient store used by get/set_transient(). */
	protected $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();

		$this->options    = array();
		$this->transients = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ]['value'] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) {
				$this->transients[ $key ] = array( 'value' => $value, 'ttl' => $ttl );
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return $value;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['response']['code'] ?? 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return $response['body'] ?? '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Set saved plugin settings (merged over defaults by get_options()).
	 */
	protected function set_settings( array $settings ) {
		$this->options['Chess_Army_Knife_settings'] = $settings;
	}

	/**
	 * Build a fake wp_remote_* response.
	 */
	protected function response( $code, $body ) {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => is_string( $body ) ? $body : json_encode( $body ),
		);
	}
}
