<?php
/**
 * Groups of teams: a wider heading such as an association or "Internal teams" that the Club Teams
 * block can list teams under. A group has a name, a blurb (its excerpt) and a place in the order.
 * Each team picks at most one group.
 *
 * Groups are edited by anyone who can edit teams.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Team_Groups {

	const POST_TYPE = 'chess_army_group';

	/**
	 * Hook up registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the post type.
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Groups', 'chess-army-knife' ),
					'singular_name'      => __( 'Group', 'chess-army-knife' ),
					'add_new'            => __( 'Add Group', 'chess-army-knife' ),
					'add_new_item'       => __( 'Add New Group', 'chess-army-knife' ),
					'edit_item'          => __( 'Edit Group', 'chess-army-knife' ),
					'search_items'       => __( 'Search Groups', 'chess-army-knife' ),
					'not_found'          => __( 'No groups found.', 'chess-army-knife' ),
					'not_found_in_trash' => __( 'No groups found in the Trash.', 'chess-army-knife' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => false, // Listed in the plugin's menu (see Chess_Army_Knife_Menu).
				'show_in_rest' => false,
				'supports'     => array( 'title', 'excerpt', 'page-attributes' ), // The excerpt is the blurb shown under the group's heading.
				'capabilities' => Chess_Army_Knife_Memberships::post_capabilities( Chess_Army_Knife_Teams::CAPABILITY ),
			)
		);
	}

	/**
	 * The published groups, in their page order.
	 *
	 * @return array[] Each { id, name, blurb, order }.
	 */
	public static function all() {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
		return array_map( array( __CLASS__, 'data' ), $posts );
	}

	/**
	 * One published group.
	 *
	 * @param int $id Group id.
	 * @return array|null { id, name, blurb, order }; null if there is no such group.
	 */
	public static function get( $id ) {
		if ( (int) $id <= 0 ) {
			return null;
		}
		$post = get_post( (int) $id );
		return ( $post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) ? self::data( $post ) : null;
	}

	/**
	 * The fields of a group post.
	 *
	 * @param WP_Post $post Group post.
	 * @return array
	 */
	protected static function data( $post ) {
		return array(
			'id'    => (int) $post->ID,
			'name'  => get_the_title( $post ),
			'blurb' => (string) $post->post_excerpt,
			'order' => (int) $post->menu_order,
		);
	}
}

Chess_Army_Knife_Team_Groups::init();
