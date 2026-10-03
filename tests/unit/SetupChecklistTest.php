<?php
/**
 * Tests for the Overview's "still to do" list and the last-import record.
 *
 * @package Chess_Army_Knife
 */

use Brain\Monkey\Functions;

class SetupChecklistTest extends Chess_Army_Knife_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '_n' )->alias(
			function ( $single, $plural, $number ) {
				return 1 === (int) $number ? $single : $plural;
			}
		);
		Functions\when( 'human_time_diff' )->alias(
			function ( $from, $to ) {
				return ( $to - $from ) . ' seconds';
			}
		);
	}

	private function facts( array $overrides = array() ) {
		return $overrides + array(
			'lms_key'            => true,
			'teams'              => 2,
			'league_teams'       => 2,
			'last_import'        => array(
				'time'    => 1000,
				'source'  => 'scheduled',
				'summary' => array(
					'created'     => 3,
					'updated'     => 1,
					'errors'      => array(),
					'key_problem' => false,
				),
			),
			'unsorted_clubs'     => 0,
			'tournaments'        => 1,
			'policies_attention' => 0,
		);
	}

	private function keys( array $facts ) {
		return array_column( Chess_Army_Knife_Setup_Checklist::items( $facts ), 'key' );
	}

	public function test_a_club_that_is_set_up_has_nothing_to_do() {
		$this->assertSame( array(), $this->keys( $this->facts() ) );
	}

	public function test_a_new_site_is_told_about_the_key_teams_and_tournaments() {
		$keys = $this->keys(
			$this->facts(
				array(
					'lms_key'     => false,
					'teams'       => 0,
					'last_import' => null,
					'tournaments' => 0,
				)
			)
		);

		$this->assertSame( array( 'lms_key', 'teams', 'tournaments' ), $keys );
	}

	public function test_a_rejected_key_is_a_warning_and_the_missing_key_is_not_repeated() {
		$rejected = $this->facts();
		$rejected['last_import']['summary']['key_problem'] = true;
		$rejected['last_import']['summary']['errors']      = array( 'Division 1: The LMS did not accept the API key.' );

		$this->assertSame( array( 'lms_key_rejected' ), $this->keys( $rejected ), 'One message, not also a failed-leagues one.' );
		$this->assertSame( array( 'lms_key' ), $this->keys( $this->facts( array( 'lms_key' => false ) ) ) );
	}

	public function test_a_key_with_teams_but_no_import_yet_suggests_importing() {
		$this->assertSame( array( 'first_import' ), $this->keys( $this->facts( array( 'last_import' => null ) ) ) );
		$this->assertSame( array( 'leagues' ), $this->keys( $this->facts( array( 'league_teams' => 0 ) ) ), 'No league, nothing to import.' );
	}

	public function test_failed_leagues_unsorted_clubs_and_policies_are_listed() {
		$facts                                     = $this->facts(
			array(
				'unsorted_clubs'     => 3,
				'policies_attention' => 2,
			)
		);
		$facts['last_import']['summary']['errors'] = array( 'Division 1: could not load' );

		$this->assertSame( array( 'import_errors', 'clubs', 'policies' ), $this->keys( $facts ) );
	}

	public function test_the_last_import_line_says_when_how_and_what() {
		$last = $this->facts()['last_import'];

		$this->assertSame( 'Last import: 500 seconds ago (daily): 3 created, 1 updated, 0 leagues failed.', Chess_Army_Knife_Events_Import::last_run_text( $last, 1500 ) );
		$this->assertSame( '', Chess_Army_Knife_Events_Import::last_run_text( null, 1500 ) );
	}

	public function test_last_run_ignores_a_damaged_record() {
		$this->assertNull( Chess_Army_Knife_Events_Import::last_run() );

		$this->options[ Chess_Army_Knife_Events_Import::LAST_OPTION ] = 'nonsense';
		$this->assertNull( Chess_Army_Knife_Events_Import::last_run() );

		$this->options[ Chess_Army_Knife_Events_Import::LAST_OPTION ] = $this->facts()['last_import'];
		$this->assertSame( 1000, Chess_Army_Knife_Events_Import::last_run()['time'] );
	}
}
