<?php
/**
 * Tests for Chess_Army_Knife_Do_Not_Record.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class DoNotRecordTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_salt' )->justReturn( 'a-secret-salt' );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	public function test_codes_and_names_are_compared_in_a_steady_form() {
		$this->assertSame( '123456A', Chess_Army_Knife_Do_Not_Record::clean_code( ' 123-456 a ' ) );
		$this->assertSame( Chess_Army_Knife_Do_Not_Record::clean_name( 'Lovelace, Ada' ), Chess_Army_Knife_Do_Not_Record::clean_name( '  ada   LOVELACE ' ), 'The order of the words does not matter.' );
	}

	public function test_nothing_is_blocked_until_someone_is_added() {
		$this->assertFalse( Chess_Army_Knife_Do_Not_Record::is_blocked( '123456A', 'Ada Lovelace' ) );
		$this->assertSame( 0, Chess_Army_Knife_Do_Not_Record::count() );
	}

	public function test_a_person_is_blocked_by_their_code_with_or_without_the_letter() {
		Chess_Army_Knife_Do_Not_Record::add( '123456A', 'Ada Lovelace' );

		$this->assertTrue( Chess_Army_Knife_Do_Not_Record::is_blocked( '123456a', '' ) );
		$this->assertTrue( Chess_Army_Knife_Do_Not_Record::is_blocked( '123456', 'Someone Else' ), 'The digits alone match; a code is used when there is one.' );
		$this->assertFalse( Chess_Army_Knife_Do_Not_Record::is_blocked( '654321B', 'Ada Lovelace' ), 'A different code is a different person, whatever the name.' );
	}

	public function test_a_name_only_blocks_when_there_is_no_code_and_at_least_two_words() {
		Chess_Army_Knife_Do_Not_Record::add( '123456A', 'Ada Lovelace' );

		$this->assertTrue( Chess_Army_Knife_Do_Not_Record::is_blocked( '', 'Lovelace, Ada' ) );
		$this->assertFalse( Chess_Army_Knife_Do_Not_Record::is_blocked( '', 'Ada' ) );

		Chess_Army_Knife_Do_Not_Record::add( '', 'Madonna' );
		$this->assertSame( 3, Chess_Army_Knife_Do_Not_Record::count(), 'A single word is never kept: code, digits and name only.' );
	}

	public function test_the_list_holds_no_code_or_name() {
		Chess_Army_Knife_Do_Not_Record::add( '123456A', 'Ada Lovelace' );

		$stored = wp_json_encode( $this->options[ Chess_Army_Knife_Do_Not_Record::OPTION ] );
		$this->assertStringNotContainsString( '123456', $stored );
		$this->assertStringNotContainsStringIgnoringCase( 'lovelace', $stored );
		foreach ( array_keys( $this->options[ Chess_Army_Knife_Do_Not_Record::OPTION ] ) as $print ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $print );
		}
	}

	public function test_a_person_can_be_allowed_again_and_adding_twice_changes_nothing() {
		$this->assertSame( 2, Chess_Army_Knife_Do_Not_Record::add( '123456A', '' ) );
		$this->assertSame( 0, Chess_Army_Knife_Do_Not_Record::add( '123456A', '' ) );

		$this->assertSame( 2, Chess_Army_Knife_Do_Not_Record::remove( '123456A', '' ) );
		$this->assertFalse( Chess_Army_Knife_Do_Not_Record::is_blocked( '123456A', '' ) );
		$this->assertSame( 0, Chess_Army_Knife_Do_Not_Record::remove( '123456A', '' ) );
	}

	public function test_a_name_cannot_match_a_code() {
		Chess_Army_Knife_Do_Not_Record::add( '', 'Ada Lovelace' );

		$this->assertFalse( Chess_Army_Knife_Do_Not_Record::is_blocked( 'Lovelace Ada', '' ), 'Fingerprints are made for a kind, so a name is never taken for a code.' );
	}

	public function test_a_different_secret_gives_different_fingerprints() {
		$first = Chess_Army_Knife_Do_Not_Record::fingerprints( '123456A', '' );
		Functions\when( 'wp_salt' )->justReturn( 'another-secret' );

		$this->assertNotSame( $first, Chess_Army_Knife_Do_Not_Record::fingerprints( '123456A', '' ) );
	}
}
