<?php
/**
 * Integration tests: the lists of teams and clubs are kept for the request, and forgotten when one changes.
 *
 * @package Chess_Army_Knife
 */

class KeptListsTest extends WP_UnitTestCase {

	public function test_the_list_of_teams_follows_changes_to_a_team() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
				'post_title'  => 'Lions',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( array( 'Lions' ), wp_list_pluck( Chess_Army_Knife_Teams::all(), 'name' ) );

		update_post_meta( $id, Chess_Army_Knife_Teams::META_VENUE, 'The Hall' );
		$this->assertSame( 'The Hall', Chess_Army_Knife_Teams::all()[0]['venue'], 'A changed field shows at once.' );

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Tigers',
			)
		);
		$this->assertSame( 'Tigers', Chess_Army_Knife_Teams::all()[0]['name'] );

		wp_trash_post( $id );
		$this->assertSame( array(), Chess_Army_Knife_Teams::all() );
	}

	public function test_a_post_of_another_type_leaves_the_kept_list_alone() {
		self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Teams::POST_TYPE,
				'post_title'  => 'Lions',
				'post_status' => 'publish',
			)
		);
		Chess_Army_Knife_Teams::all();
		$this->assertNotFalse( wp_cache_get( 'all', Chess_Army_Knife_Teams::MEMO_GROUP ) );

		self::factory()->post->create( array( 'post_type' => 'post' ) );

		$this->assertNotFalse( wp_cache_get( 'all', Chess_Army_Knife_Teams::MEMO_GROUP ), 'An import saves many events; they must not empty the list each time.' );
	}

	public function test_the_list_of_clubs_follows_changes_to_a_club() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Chess_Army_Knife_Clubs::POST_TYPE,
				'post_title'  => 'Stroud',
				'post_status' => 'private',
			)
		);
		$this->assertSame( array( 'Stroud' ), wp_list_pluck( Chess_Army_Knife_Clubs::all(), 'name' ) );

		update_post_meta( $id, Chess_Army_Knife_Clubs::META_VENUE, 'The Library' );
		$this->assertSame( 'The Library', Chess_Army_Knife_Clubs::all()[0]['venue'] );

		wp_delete_post( $id, true );
		$this->assertSame( array(), Chess_Army_Knife_Clubs::all() );
	}
}
