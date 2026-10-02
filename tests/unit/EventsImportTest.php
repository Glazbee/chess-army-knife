<?php
/**
 * Tests for the pure parts of Chess_Army_Knife_Events_Import.
 *
 * @package Chess_Army_Knife
 */

class EventsImportTest extends Chess_Army_Knife_TestCase {

	/**
	 * @dataProvider dates
	 */
	public function test_parse_date( $raw, $expected ) {
		$this->assertSame( $expected, Chess_Army_Knife_Events_Import::parse_date( $raw ) );
	}

	public function dates() {
		return array(
			'iso'                => array( '2026-10-05', '2026-10-05' ),
			'iso with time'      => array( '2026-10-05 19:30', '2026-10-05' ),
			'iso datetime'       => array( '2026-10-05T19:30:00', '2026-10-05' ),
			'uk slashes'         => array( '05/10/2026', '2026-10-05' ),
			'uk single digits'   => array( '5/9/2026', '2026-09-05' ),
			'uk dots'            => array( '05.10.2026', '2026-10-05' ),
			'padded with space'  => array( '  2026-10-05 ', '2026-10-05' ),
			'impossible date'    => array( '2026-02-30', '' ),
			'impossible uk date' => array( '31/04/2026', '' ),
			'words'              => array( 'Monday 5th October', '' ),
			'empty'              => array( '', '' ),
		);
	}

	/**
	 * @dataProvider times
	 */
	public function test_parse_time( $raw, $expected ) {
		$this->assertSame( $expected, Chess_Army_Knife_Events_Import::parse_time( $raw ) );
	}

	public function times() {
		return array(
			'plain'               => array( '19:30', '19:30' ),
			'with seconds'        => array( '19:30:00', '19:30' ),
			'single digit hour'   => array( '7:15', '07:15' ),
			'after a date'        => array( '2026-10-05 19:30', '19:30' ),
			'iso datetime'        => array( '2026-10-05T19:30:00', '19:30' ),
			'midnight'            => array( '00:00', '00:00' ),
			'hour out of range'   => array( '24:00', '' ),
			'minute out of range' => array( '19:75', '' ),
			'date only'           => array( '2026-10-05', '' ),
			'empty'               => array( '', '' ),
			'words'               => array( 'evening', '' ),
		);
	}

	public function test_match_key_ignores_case_and_padding_but_not_the_fixture() {
		$key = Chess_Army_Knife_Events_Import::match_key( '613', 'Division 1', 'Alpha', 'Beta', '2026-10-05' );

		$this->assertSame( $key, Chess_Army_Knife_Events_Import::match_key( ' 613 ', 'division 1', ' ALPHA', 'beta ', '2026-10-05' ) );
		$this->assertNotSame( $key, Chess_Army_Knife_Events_Import::match_key( '613', 'Division 1', 'Beta', 'Alpha', '2026-10-05' ), 'Home and away are different fixtures.' );
		$this->assertNotSame( $key, Chess_Army_Knife_Events_Import::match_key( '613', 'Division 1', 'Alpha', 'Beta', '2026-11-05' ) );
		$this->assertNotSame( $key, Chess_Army_Knife_Events_Import::match_key( '613', 'Division 2', 'Alpha', 'Beta', '2026-10-05' ) );
	}

	private function match( $home, $away, $date, array $extra = array() ) {
		return array_merge(
			array(
				'venue'       => '',
				'date'        => $date,
				'time'        => '',
				'home'        => $home,
				'away'        => $away,
				'home_score'  => '',
				'away_score'  => '',
				'result_text' => '',
			),
			$extra
		);
	}

	private function team( $team, $org = '613', $event = 'Division 1' ) {
		return array(
			'org'   => $org,
			'event' => $event,
			'team'  => $team,
		);
	}

	public function test_plan_creates_a_candidate_for_each_upcoming_fixture_of_the_club() {
		$matches = array(
			'613|division 1' => array(
				$this->match( 'Our A', 'Rivals', '2026-10-05', array( 'venue' => 'Town Hall' ) ),
				$this->match( 'Others', 'Elsewhere', '2026-10-05' ),
				$this->match( 'Rivals', 'Our A', '2026-11-02', array( 'time' => '20:00' ) ),
			),
		);

		$plan = Chess_Army_Knife_Events_Import::plan( array( $this->team( 'Our A' ) ), $matches, '2026-09-30', '19:30' );

		$this->assertSame( 0, $plan['skipped'] );
		$this->assertCount( 2, $plan['candidates'], 'A fixture between other clubs is ignored.' );
		$this->assertSame( 'Our A v Rivals', $plan['candidates'][0]['title'] );
		$this->assertSame( '2026-10-05 19:30:00', $plan['candidates'][0]['start'], 'No time from the LMS: the usual kick-off.' );
		$this->assertSame( 'Town Hall', $plan['candidates'][0]['location'] );
		$this->assertSame( '613|Division 1', $plan['candidates'][0]['league'] );
		$this->assertSame( '2026-11-02 20:00:00', $plan['candidates'][1]['start'], 'A time from the LMS wins.' );
	}

	public function test_plan_takes_the_time_from_a_datetime_in_the_date_field() {
		$matches = array( '613|division 1' => array( $this->match( 'Our A', 'Rivals', '2026-10-05T18:45:00' ) ) );

		$plan = Chess_Army_Knife_Events_Import::plan( array( $this->team( 'Our A' ) ), $matches, '2026-09-30', '19:30' );

		$this->assertSame( '2026-10-05 18:45:00', $plan['candidates'][0]['start'] );
	}

	public function test_plan_ignores_past_fixtures_but_counts_unreadable_dates() {
		$matches = array(
			'613|division 1' => array(
				$this->match( 'Our A', 'Rivals', '2026-09-01' ),
				$this->match( 'Our A', 'Other', '2026-09-30' ),
				$this->match( 'Our A', 'Mystery', 'TBC' ),
				$this->match( 'Our A', 'Blank', '' ),
			),
		);

		$plan = Chess_Army_Knife_Events_Import::plan( array( $this->team( 'Our A' ) ), $matches, '2026-09-30', '19:30' );

		$this->assertCount( 1, $plan['candidates'], 'Today counts as upcoming; earlier days do not.' );
		$this->assertSame( 'Our A v Other', $plan['candidates'][0]['title'] );
		$this->assertSame( 2, $plan['skipped'] );
	}

	public function test_two_club_teams_meeting_make_one_event() {
		$matches = array( '613|division 1' => array( $this->match( 'Our A', 'Our B', '2026-10-05' ) ) );

		$plan = Chess_Army_Knife_Events_Import::plan( array( $this->team( 'Our A' ), $this->team( 'Our B' ) ), $matches, '2026-09-30', '19:30' );

		$this->assertCount( 1, $plan['candidates'] );
	}

	public function test_plan_matches_team_names_loosely_only_when_there_is_no_exact_match() {
		$matches = array(
			'613|division 1' => array(
				$this->match( 'Hammersmith 1', 'Rivals', '2026-10-05' ),
				$this->match( 'Hammersmith 2', 'Rivals', '2026-10-12' ),
			),
		);

		$exact = Chess_Army_Knife_Events_Import::plan( array( $this->team( 'Hammersmith 1' ) ), $matches, '2026-09-30', '19:30' );
		$loose = Chess_Army_Knife_Events_Import::plan( array( $this->team( 'Hammersmith' ) ), $matches, '2026-09-30', '19:30' );

		$this->assertCount( 1, $exact['candidates'] );
		$this->assertCount( 2, $loose['candidates'] );
	}

	public function test_plan_handles_a_league_that_failed_to_load() {
		$plan = Chess_Army_Knife_Events_Import::plan( array( $this->team( 'Our A' ) ), array( '613|division 1' => array() ), '2026-09-30', '19:30' );

		$this->assertSame( array(), $plan['candidates'] );
		$this->assertSame( 0, $plan['skipped'] );
	}

	public function test_plan_keeps_leagues_apart() {
		$matches = array(
			'613|division 1' => array( $this->match( 'Our A', 'Rivals', '2026-10-05' ) ),
			'613|division 2' => array( $this->match( 'Our B', 'Others', '2026-10-05' ) ),
		);

		$plan = Chess_Army_Knife_Events_Import::plan(
			array( $this->team( 'Our A' ), $this->team( 'Our B', '613', 'Division 2' ) ),
			$matches,
			'2026-09-30',
			'19:30'
		);

		$this->assertSame( array( '613|Division 1', '613|Division 2' ), array_column( $plan['candidates'], 'league' ) );
	}

	public function test_the_players_of_our_team_are_collected_from_every_fixture_even_past_ones() {
		$matches = array(
			'613|division 1' => array(
				$this->match(
					'Our A',
					'Rivals',
					'2026-01-05',
					array(
						'players' => array(
							array(
								'side' => 'home',
								'code' => '123456a',
								'name' => 'Ada',
							),
							array(
								'side' => 'away',
								'code' => '999999Z',
								'name' => 'Their player',
							),
						),
					)
				),
				$this->match(
					'Rivals',
					'Our A',
					'2026-02-05',
					array(
						'players' => array(
							array(
								'side' => 'home',
								'code' => '888888Y',
								'name' => 'Their other',
							),
							array(
								'side' => 'away',
								'code' => '123456A',
								'name' => 'Ada',
							),
							array(
								'side' => 'away',
								'code' => '222222B',
								'name' => 'Bea',
							),
						),
					)
				),
			),
		);

		$by_team = Chess_Army_Knife_Events_Import::players_by_team( array( $this->team( 'Our A' ) ), $matches );
		$players = array_values( $by_team )[0];

		$this->assertSame( array( 'Ada', 'Bea' ), array_column( $players, 'name' ), 'Only our side, and a player who played twice is listed once.' );
	}

	public function test_a_team_with_no_board_results_has_no_players() {
		$matches = array( '613|division 1' => array( $this->match( 'Our A', 'Rivals', '2026-10-05' ) ) );

		$this->assertSame( array(), Chess_Army_Knife_Events_Import::players_by_team( array( $this->team( 'Our A' ) ), $matches ) );
	}
}
