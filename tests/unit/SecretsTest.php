<?php
/**
 * Tests for the encryption of the LMS API key at rest.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class SecretsTest extends Chess_Army_Knife_TestCase {

	/** @var string The site's salt, which the encryption key comes from. */
	private $salt = 'salt-one';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_salt' )->alias(
			function () {
				return $this->salt;
			}
		);
	}

	public function test_a_secret_is_stored_unreadably_and_comes_back() {
		$stored = Chess_Army_Knife_Secrets::encrypt( 'lmsk_secret_key' );

		$this->assertStringStartsWith( Chess_Army_Knife_Secrets::PREFIX, $stored );
		$this->assertStringNotContainsString( 'lmsk_secret_key', $stored );
		$this->assertSame( 'lmsk_secret_key', Chess_Army_Knife_Secrets::decrypt( $stored ) );
	}

	public function test_the_same_secret_is_stored_differently_each_time() {
		$this->assertNotSame( Chess_Army_Knife_Secrets::encrypt( 'lmsk_secret_key' ), Chess_Army_Knife_Secrets::encrypt( 'lmsk_secret_key' ) );
	}

	public function test_an_empty_or_already_encrypted_value_is_left_alone() {
		$stored = Chess_Army_Knife_Secrets::encrypt( 'lmsk_secret_key' );

		$this->assertSame( '', Chess_Army_Knife_Secrets::encrypt( '' ) );
		$this->assertSame( $stored, Chess_Army_Knife_Secrets::encrypt( $stored ), 'Saving twice does not encrypt twice.' );
	}

	public function test_a_key_saved_before_encryption_existed_is_read_as_it_is() {
		$this->assertSame( 'lmsk_old_plain_key', Chess_Army_Knife_Secrets::decrypt( 'lmsk_old_plain_key' ) );
	}

	public function test_a_damaged_or_foreign_value_reads_as_empty_rather_than_as_garbage() {
		$stored = Chess_Army_Knife_Secrets::encrypt( 'lmsk_secret_key' );

		$this->assertSame( '', Chess_Army_Knife_Secrets::decrypt( substr( $stored, 0, -4 ) . 'AAAA' ), 'Changed bytes are noticed.' );
		$this->assertSame( '', Chess_Army_Knife_Secrets::decrypt( Chess_Army_Knife_Secrets::PREFIX . 'not base64 !!' ) );
		$this->assertSame( '', Chess_Army_Knife_Secrets::decrypt( Chess_Army_Knife_Secrets::PREFIX . base64_encode( 'short' ) ) );

		$this->salt = 'salt-two';
		$this->assertSame( '', Chess_Army_Knife_Secrets::decrypt( $stored ), 'Changed salts cannot read it, so the key must be entered again.' );
	}

	public function test_saving_the_settings_encrypts_the_key_and_reading_them_decrypts_it() {
		$saved = Chess_Army_Knife_Secrets::protect_option(
			array(
				'club_name'   => 'Our Club',
				'lms_api_key' => 'lmsk_secret_key',
			)
		);

		$this->assertSame( 'Our Club', $saved['club_name'] );
		$this->assertStringNotContainsString( 'lmsk_secret_key', $saved['lms_api_key'] );

		$this->options[ Chess_Army_Knife_Settings::OPTION ] = $saved;
		$this->assertSame( 'lmsk_secret_key', Chess_Army_Knife_Settings::get_options()['lms_api_key'] );
		$this->assertSame( 'lmsk_secret_key', Chess_Army_Knife_LMS_Client::api_key() );
	}

	public function test_anything_that_is_not_a_settings_array_passes_through() {
		$this->assertSame( 'text', Chess_Army_Knife_Secrets::protect_option( 'text' ) );
	}
}
