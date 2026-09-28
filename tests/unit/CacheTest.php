<?php
/**
 * Tests for Chess_Army_Knife_Cache (transient backend).
 *
 * @package Chess_Army_Knife
 */

class CacheTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->set_settings( array( 'use_local_cache' => 0 ) );
	}

	public function test_key_is_prefixed_and_hashed() {
		$this->assertSame( 'ecflms_' . md5( 'abc' ), Chess_Army_Knife_Cache::key( 'abc' ) );
	}

	public function test_remember_generates_once_then_serves_from_cache() {
		$calls     = 0;
		$generator = function () use ( &$calls ) {
			++$calls;
			return array( 'value' => $calls );
		};

		$first  = Chess_Army_Knife_Cache::remember( 'k', 600, $generator );
		$second = Chess_Army_Knife_Cache::remember( 'k', 600, $generator );

		$this->assertSame( array( 'value' => 1 ), $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $calls );
	}

	public function test_remember_uses_requested_ttl_for_success() {
		Chess_Army_Knife_Cache::remember(
			'k',
			600,
			function () {
				return 'ok';
			}
		);

		$this->assertSame( array( 600 ), array_column( $this->transients, 'ttl' ) );
	}

	public function test_errors_are_cached_for_at_most_two_minutes() {
		Chess_Army_Knife_Cache::remember(
			'k',
			6 * HOUR_IN_SECONDS,
			function () {
				return new WP_Error( 'boom', 'failed' );
			}
		);

		$this->assertSame( array( 120 ), array_column( $this->transients, 'ttl' ) );
	}

	public function test_forget_removes_entry() {
		Chess_Army_Knife_Cache::remember(
			'k',
			600,
			function () {
				return 'ok';
			}
		);
		Chess_Army_Knife_Cache::forget( 'k' );

		$this->assertSame( array(), $this->transients );
	}

	public function test_created_at_unknown_for_transient_backend() {
		$this->assertNull( Chess_Army_Knife_Cache::get_created_at( 'k' ) );
	}
}
