<?php
/**
 * Tests for how people's names are written.
 *
 * @package Chess_Army_Knife
 */

class NamesTest extends Chess_Army_Knife_TestCase {

	public function test_a_name_in_either_order_is_split_the_same_way() {
		$expected = array(
			'first'   => 'Ada',
			'middle'  => array( 'Augusta' ),
			'surname' => 'Lovelace',
		);

		$this->assertSame( $expected, Chess_Army_Knife_Names::parse( 'Ada Augusta Lovelace' ) );
		$this->assertSame( $expected, Chess_Army_Knife_Names::parse( 'Lovelace, Ada Augusta' ) );
	}

	public function test_particles_stay_with_the_surname() {
		$this->assertSame( 'De Mello', Chess_Army_Knife_Names::parse( 'Simon De Mello' )['surname'] );
		$this->assertSame( 'van der Berg', Chess_Army_Knife_Names::parse( 'Anna van der Berg' )['surname'] );
		$this->assertSame( 'Simon', Chess_Army_Knife_Names::parse( 'Simon De Mello' )['first'] );
	}

	public function test_names_are_written_first_name_first_or_surname_first() {
		$this->assertSame( 'Ada Lovelace', Chess_Army_Knife_Names::format( 'Lovelace, Ada Augusta', Chess_Army_Knife_Names::FIRST_SURNAME ) );
		$this->assertSame( 'Lovelace, Ada A', Chess_Army_Knife_Names::format( 'Ada Augusta Lovelace', Chess_Army_Knife_Names::SURNAME_FIRST ) );
		$this->assertSame( 'De Mello, Simon', Chess_Army_Knife_Names::format( 'Simon De Mello', Chess_Army_Knife_Names::SURNAME_FIRST ) );
		$this->assertSame( 'Lovelace, Ada A B', Chess_Army_Knife_Names::format( 'Ada Augusta Beatrice Lovelace', Chess_Army_Knife_Names::SURNAME_FIRST ) );
	}

	public function test_a_nickname_replaces_the_first_name() {
		$person = array(
			'name'     => 'Madeline Rose Dupree',
			'nickname' => 'Maddy',
		);

		$this->assertSame( 'Maddy Dupree', Chess_Army_Knife_Names::person( $person, Chess_Army_Knife_Names::FIRST_SURNAME ) );
		$this->assertSame( 'Dupree, Maddy R', Chess_Army_Knife_Names::person( $person, Chess_Army_Knife_Names::SURNAME_FIRST ) );
		$this->assertSame( 'Madeline Dupree', Chess_Army_Knife_Names::person( array( 'name' => 'Madeline Rose Dupree' ), Chess_Army_Knife_Names::FIRST_SURNAME ) );
	}

	public function test_a_single_word_is_left_alone() {
		$this->assertSame( 'Madonna', Chess_Army_Knife_Names::format( 'Madonna', Chess_Army_Knife_Names::SURNAME_FIRST ) );
		$this->assertSame( '', Chess_Army_Knife_Names::format( '', Chess_Army_Knife_Names::FIRST_SURNAME ) );
	}

	public function test_a_block_follows_the_site_unless_it_chooses() {
		$this->set_settings( array( 'name_format' => 'surname_first' ) );

		$this->assertSame( 'surname_first', Chess_Army_Knife_Names::style_for( array() ) );
		$this->assertSame( 'surname_first', Chess_Army_Knife_Names::style_for( array( 'nameFormat' => '' ) ) );
		$this->assertSame( 'first_surname', Chess_Army_Knife_Names::style_for( array( 'nameFormat' => 'first_surname' ) ) );
		$this->assertSame( 'surname_first', Chess_Army_Knife_Names::style_for( array( 'nameFormat' => 'nonsense' ) ), 'Anything else follows the site.' );
	}

	public function test_the_site_writes_first_name_first_by_default() {
		$this->assertSame( 'first_surname', Chess_Army_Knife_Names::site_style() );
	}
}
