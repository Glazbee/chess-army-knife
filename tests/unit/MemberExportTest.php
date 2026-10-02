<?php
/**
 * Tests for the members CSV export.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class MemberExportTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_posts' )->justReturn( array() );
	}

	protected function member( array $fields = array() ) {
		return $fields + array(
			'id'                    => 1,
			'name'                  => 'Jane Smith',
			'email'                 => 'jane@example.test',
			'phone'                 => '+44 7700 900123',
			'date_of_birth'         => '',
			'guardian_name'         => '',
			'guardian_email'        => '',
			'guardian_phone'        => '',
			'ecf_code'              => '120787J',
			'ecf_rating'            => 1850,
			'ecf_rating_domain'     => 'S',
			'ecf_checked_at'        => '2026-09-29 08:00:00',
			'manual_rating'         => null,
			'type_name'             => 'Adult',
			'status'                => 'active',
			'start_date'            => '2026-01-01',
			'expiry_date'           => '2026-12-31',
			'payment_method'        => 'cash',
			'paid_on'               => '2026-01-02',
			'notes'                 => 'Private note',
			'newsletter_consent_at' => '2026-01-01 10:00:00',
			'whatsapp_consent_at'   => '',
			'created_at'            => '2026-01-01 10:00:00',
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

	public function test_every_row_has_a_cell_for_each_column() {
		$row = Chess_Army_Knife_Member_Export::row( $this->member(), '2026-09-30', array() );

		$this->assertCount( count( Chess_Army_Knife_Member_Export::columns() ), $row );
	}

	public function test_csv_has_a_header_and_one_row_per_member() {
		$rows = $this->parse(
			Chess_Army_Knife_Member_Export::to_csv(
				array(
					$this->member(),
					$this->member(
						array(
							'id'   => 2,
							'name' => 'Bob',
						)
					),
				),
				'2026-09-30',
				array( 1 => array( 'Club A', 'Club B' ) )
			)
		);

		$this->assertCount( 3, $rows );
		$this->assertSame( array_values( Chess_Army_Knife_Member_Export::columns() ), $rows[0] );
		$this->assertSame( 'Jane Smith', $rows[1][0] );
		$this->assertContains( '120787J', $rows[1] );
		$this->assertContains( '1850', $rows[1] );
		$this->assertContains( 'Club A, Club B', $rows[1] );
		$this->assertNotContains( 'Club A, Club B', $rows[2] );
	}

	public function test_private_notes_are_not_exported() {
		$csv = Chess_Army_Knife_Member_Export::to_csv( array( $this->member() ), '2026-09-30', array() );

		$this->assertStringNotContainsString( 'Private note', $csv );
	}

	public function test_an_expired_member_is_exported_as_expired() {
		$row = Chess_Army_Knife_Member_Export::row( $this->member( array( 'expiry_date' => '2026-01-31' ) ), '2026-09-30', array() );

		$this->assertSame( 'Expired', $row[1] );
	}

	public function test_commas_quotes_and_line_breaks_survive() {
		$rows = $this->parse( Chess_Army_Knife_Member_Export::to_csv( array( $this->member( array( 'name' => "O'Neil, \"Sam\"\nJr" ) ) ), '2026-09-30', array() ) );

		$this->assertSame( "O'Neil, \"Sam\"\nJr", $rows[1][0] );
	}

	public function test_cells_that_a_spreadsheet_would_run_as_formulas_are_made_plain_text() {
		$this->assertSame( "'=HYPERLINK(\"http://evil.test\")", Chess_Army_Knife_Member_Export::safe_cell( '=HYPERLINK("http://evil.test")' ) );
		$this->assertSame( "'@SUM(A1)", Chess_Army_Knife_Member_Export::safe_cell( '@SUM(A1)' ) );
		$this->assertSame( "'-2+3", Chess_Army_Knife_Member_Export::safe_cell( '-2+3' ) );
		$this->assertSame( "'+cmd|' /C calc'!A0", Chess_Army_Knife_Member_Export::safe_cell( "+cmd|' /C calc'!A0" ) );
	}

	public function test_ordinary_cells_and_phone_numbers_are_unchanged() {
		$this->assertSame( 'Jane Smith', Chess_Army_Knife_Member_Export::safe_cell( 'Jane Smith' ) );
		$this->assertSame( '', Chess_Army_Knife_Member_Export::safe_cell( '' ) );
		$this->assertSame( '+44 7700 900123', Chess_Army_Knife_Member_Export::safe_cell( '+44 7700 900123' ) );
		$this->assertSame( '07700 900123', Chess_Army_Knife_Member_Export::safe_cell( '07700 900123' ) );
	}

	public function test_a_malicious_name_is_neutralised_in_the_file() {
		$rows = $this->parse( Chess_Army_Knife_Member_Export::to_csv( array( $this->member( array( 'name' => '=1+1' ) ) ), '2026-09-30', array() ) );

		$this->assertSame( "'=1+1", $rows[1][0] );
	}
}
