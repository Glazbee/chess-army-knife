<?php
/**
 * Tests for the treasurer's payments CSV.
 *
 * @package Chess_Army_Knife
 */

class PaymentExportTest extends Chess_Army_Knife_TestCase {

	protected function payment( array $fields = array() ) {
		return $fields + array(
			'member_id' => 1,
			'name'      => 'Lovelace, Ada Augusta',
			'nickname'  => '',
			'type_name' => 'Adult',
			'paid_on'   => '2026-09-05',
			'method'    => 'bank_transfer',
			'reference' => 'MEM-1',
			'amount'    => 2550,
		);
	}

	protected function parse( $csv ) {
		$this->assertSame( "\xEF\xBB\xBF", substr( $csv, 0, 3 ), 'Starts with a byte order mark for Excel.' );
		$stream = fopen( 'php://temp', 'r+' );
		fwrite( $stream, substr( $csv, 3 ) );
		rewind( $stream );
		$rows = array();
		$row  = fgetcsv( $stream, 0, ',', '"', '' );
		while ( false !== $row ) {
			$rows[] = $row;
			$row    = fgetcsv( $stream, 0, ',', '"', '' );
		}
		fclose( $stream );
		return $rows;
	}

	public function test_a_row_has_the_names_date_type_reference_and_amount() {
		$this->assertSame(
			array( 'Ada', 'Lovelace', '2026-09-05', 'Bank transfer', 'MEM-1', '25.50' ),
			Chess_Army_Knife_Payment_Export::row( $this->payment() )
		);
	}

	public function test_a_nickname_is_used_in_place_of_the_first_name() {
		$row = Chess_Army_Knife_Payment_Export::row( $this->payment( array( 'nickname' => 'Ads' ) ) );

		$this->assertSame( 'Ads', $row[0] );
		$this->assertSame( 'Lovelace', $row[1] );
	}

	public function test_a_surname_with_a_particle_stays_together() {
		$row = Chess_Army_Knife_Payment_Export::row( $this->payment( array( 'name' => 'Pieter van der Berg' ) ) );

		$this->assertSame( 'Pieter', $row[0] );
		$this->assertSame( 'van der Berg', $row[1] );
	}

	public function test_an_erased_member_keeps_their_payment_without_a_name() {
		$row = Chess_Army_Knife_Payment_Export::row( $this->payment( array( 'name' => Chess_Army_Knife_Membership_Store::erased_name() ) ) );

		$this->assertSame( '', $row[0] );
		$this->assertSame( 'Erased member', $row[1] );
	}

	public function test_a_payment_with_no_amount_has_a_blank_amount() {
		$row = Chess_Army_Knife_Payment_Export::row( $this->payment( array( 'amount' => null ) ) );

		$this->assertSame( '', $row[5] );
	}

	public function test_a_whole_pound_amount_has_two_decimal_places() {
		$row = Chess_Army_Knife_Payment_Export::row( $this->payment( array( 'amount' => 2500 ) ) );

		$this->assertSame( '25.00', $row[5] );
	}

	public function test_cash_has_no_reference() {
		$row = Chess_Army_Knife_Payment_Export::row(
			$this->payment(
				array(
					'method'    => 'cash',
					'reference' => '',
				)
			)
		);

		$this->assertSame( 'Cash', $row[3] );
		$this->assertSame( '', $row[4] );
	}

	public function test_the_file_has_a_header_and_a_row_for_each_payment() {
		$rows = $this->parse(
			Chess_Army_Knife_Payment_Export::to_csv(
				array(
					$this->payment(),
					$this->payment(
						array(
							'name'   => 'Turing, Alan',
							'amount' => 1000,
						)
					),
				)
			)
		);

		$this->assertCount( 3, $rows );
		$this->assertSame( Chess_Army_Knife_Payment_Export::columns(), $rows[0] );
		$this->assertSame( 'Lovelace', $rows[1][1] );
		$this->assertSame( 'Turing', $rows[2][1] );
	}

	public function test_a_season_with_no_payments_is_just_the_header() {
		$rows = $this->parse( Chess_Army_Knife_Payment_Export::to_csv( array() ) );

		$this->assertCount( 1, $rows );
	}

	public function test_a_malicious_nickname_is_neutralised_in_the_file() {
		$rows = $this->parse( Chess_Army_Knife_Payment_Export::to_csv( array( $this->payment( array( 'nickname' => '=1+1' ) ) ) ) );

		$this->assertSame( "'=1+1", $rows[1][0] );
	}
}
