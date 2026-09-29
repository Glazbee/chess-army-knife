<?php
/**
 * Minimal stand-in for WP_REST_Response.
 *
 * @package Chess_Army_Knife
 */

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $headers = array();

		public function __construct( $data = null ) {
			$this->data = $data;
		}

		public function header( $key, $value ) {
			$this->headers[ $key ] = $value;
		}
	}
}
