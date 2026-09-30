<?php
/**
 * Tags on photos: a club officer ticks the members who appear in a photo when
 * it is uploaded (or later, on the photo's details), so every photo of a
 * member can be found again, for example to answer a subject access request.
 *
 * A tag is post meta on the photo holding the member's id, never their name,
 * so a member's details stay in one place, the members table. Only people who
 * can manage members see or change the tags.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Member_Photos {

	const META      = '_chess_army_photo_member';
	const FIELD     = 'chess_army_members';
	const SHOWN     = 'chess_army_members_shown';
	const QUERY_VAR = 'chess_army_member';

	/**
	 * Hook up the photo fields and the Media Library filter.
	 */
	public static function init() {
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'add_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_field' ), 10, 2 );
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_media_list' ) );
	}

	/* -------------------------------------------------------------
	 * Tags
	 * ------------------------------------------------------------- */

	/**
	 * The members tagged in a photo.
	 *
	 * @param int $attachment_id Photo id.
	 * @return int[] Member ids.
	 */
	public static function member_ids( $attachment_id ) {
		return array_values( array_unique( array_map( 'intval', (array) get_post_meta( (int) $attachment_id, self::META, false ) ) ) );
	}

	/**
	 * Replace the members tagged in a photo.
	 *
	 * @param int   $attachment_id Photo id.
	 * @param int[] $member_ids    Member ids.
	 */
	public static function set_members( $attachment_id, array $member_ids ) {
		delete_post_meta( (int) $attachment_id, self::META );
		foreach ( array_unique( array_filter( array_map( 'absint', $member_ids ) ) ) as $member_id ) {
			add_post_meta( (int) $attachment_id, self::META, $member_id );
		}
	}

	/**
	 * The photos a member is tagged in, newest first.
	 *
	 * @param int $member_id Member id.
	 * @return int[] Photo ids.
	 */
	public static function photo_ids( $member_id ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- No other API finds the photos tagged with a member.
						array(
							'key'   => self::META,
							'value' => (int) $member_id,
						),
					),
				)
			)
		);
	}

	/**
	 * How many photos each member is tagged in.
	 *
	 * @return int[] Photo count, keyed by member id.
	 */
	public static function counts() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No WordPress API counts posts by meta value.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value, COUNT(*) AS photos FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value", self::META ), ARRAY_A );

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['meta_value'] ] = (int) $row['photos'];
		}
		return $counts;
	}

	/**
	 * Take a member off every photo, when their record is deleted.
	 *
	 * @param int $member_id Member id.
	 */
	public static function remove_member( $member_id ) {
		delete_metadata( 'post', 0, self::META, (int) $member_id, true );
	}

	/**
	 * The Media Library filtered to one member's photos.
	 *
	 * @param int $member_id Member id.
	 * @return string
	 */
	public static function library_url( $member_id ) {
		return add_query_arg(
			array(
				'mode'          => 'list',
				self::QUERY_VAR => (int) $member_id,
			),
			admin_url( 'upload.php' )
		);
	}

	/**
	 * Whether the current user may see and change photo tags.
	 *
	 * @return bool
	 */
	protected static function user_can_tag() {
		return Chess_Army_Knife_Memberships::user_can_manage();
	}

	/* -------------------------------------------------------------
	 * The photo's details
	 * ------------------------------------------------------------- */

	/**
	 * Add the "members in this photo" checklist to a photo's details, in the
	 * upload dialog and on the Edit Media screen.
	 *
	 * @param array   $fields Fields shown.
	 * @param WP_Post $post   The attachment.
	 * @return array
	 */
	public static function add_field( $fields, $post ) {
		if ( ! self::user_can_tag() || ! wp_attachment_is_image( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}

		$tagged     = self::member_ids( $post->ID );
		$candidates = array();
		// Current members, and guests who are not members (people at events are photographed too).
		foreach ( array( 'active', 'nonmember' ) as $view ) {
			foreach ( Chess_Army_Knife_Membership_Store::get_members( array( 'view' => $view ) ) as $member ) {
				$candidates[ $member['id'] ] = $member['name'];
			}
		}
		// Someone already tagged stays in the list even if they are no longer a current member.
		foreach ( $tagged as $member_id ) {
			$member = Chess_Army_Knife_Membership_Store::get_member( $member_id );
			if ( $member ) {
				$candidates[ $member['id'] ] = $member['name'];
			}
		}
		asort( $candidates );

		$name  = 'attachments[' . (int) $post->ID . ']';
		$html  = '<input type="hidden" name="' . esc_attr( $name . '[' . self::SHOWN . ']' ) . '" value="1" />';
		$html .= '<div style="max-height:10em;overflow:auto;padding:4px 8px;background:#fff;border:1px solid #c3c4c7;">';
		foreach ( $candidates as $member_id => $member_name ) {
			$html .= '<label style="display:block"><input type="checkbox" name="' . esc_attr( $name . '[' . self::FIELD . '][]' ) . '" value="' . (int) $member_id . '"' . checked( in_array( (int) $member_id, $tagged, true ), true, false ) . ' /> ' . esc_html( $member_name ) . '</label>';
		}
		if ( ! $candidates ) {
			$html .= esc_html__( 'There are no current members to tag.', 'chess-army-knife' );
		}
		$html .= '</div>';

		$fields[ self::FIELD ] = array(
			'label' => __( 'Members in this photo', 'chess-army-knife' ),
			'input' => 'html',
			'html'  => $html,
			'helps' => __( 'Tick everyone who appears, so all photos of a member can be found later. Only people who manage members can see this.', 'chess-army-knife' ),
		);

		return $fields;
	}

	/**
	 * Save the checklist. A form that did not show it leaves the tags alone.
	 *
	 * @param array $post       Attachment post data.
	 * @param array $attachment Submitted fields for the attachment.
	 * @return array
	 */
	public static function save_field( $post, $attachment ) {
		$id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;

		if ( ! $id || empty( $attachment[ self::SHOWN ] ) || ! self::user_can_tag() || ! current_user_can( 'edit_post', $id ) || ! wp_attachment_is_image( $id ) ) {
			return $post;
		}

		$submitted = isset( $attachment[ self::FIELD ] ) ? array_map( 'absint', (array) $attachment[ self::FIELD ] ) : array();

		// Only members that exist can be tagged.
		$members = array();
		foreach ( $submitted as $member_id ) {
			if ( $member_id && Chess_Army_Knife_Membership_Store::get_member( $member_id ) ) {
				$members[] = $member_id;
			}
		}
		self::set_members( $id, $members );

		return $post;
	}

	/* -------------------------------------------------------------
	 * The Media Library
	 * ------------------------------------------------------------- */

	/**
	 * Add a Members column to the Media Library list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		if ( self::user_can_tag() ) {
			$columns[ self::FIELD ] = __( 'Members', 'chess-army-knife' );
		}
		return $columns;
	}

	/**
	 * Fill the Members column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Photo id.
	 */
	public static function render_column( $column, $post_id ) {
		if ( self::FIELD !== $column || ! self::user_can_tag() ) {
			return;
		}

		$names = array();
		foreach ( self::member_ids( $post_id ) as $member_id ) {
			$member = Chess_Army_Knife_Membership_Store::get_member( $member_id );
			if ( $member ) {
				$names[] = $member['name'];
			}
		}
		echo $names ? esc_html( implode( ', ', $names ) ) : '&mdash;';
	}

	/**
	 * A member drop-down beside the Media Library's other filters.
	 *
	 * @param string $post_type The list being shown.
	 */
	public static function render_filter( $post_type ) {
		if ( 'attachment' !== $post_type || ! self::user_can_tag() ) {
			return;
		}

		$chosen = isset( $_GET[ self::QUERY_VAR ] ) ? absint( $_GET[ self::QUERY_VAR ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter; nothing is changed.
		echo '<label class="screen-reader-text" for="chess-army-member-filter">' . esc_html__( 'Filter by member', 'chess-army-knife' ) . '</label>';
		echo '<select id="chess-army-member-filter" name="' . esc_attr( self::QUERY_VAR ) . '"><option value="0">' . esc_html__( 'All members', 'chess-army-knife' ) . '</option>';

		$counts = self::counts();
		foreach ( Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'people' ) ) as $member ) {
			if ( empty( $counts[ $member['id'] ] ) ) {
				continue; // Only members who are in a photo.
			}
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $member['id'], selected( $chosen, $member['id'], false ), esc_html( $member['name'] ) );
		}
		echo '</select>';
	}

	/**
	 * Limit the Media Library list to one member's photos.
	 *
	 * @param WP_Query $query The query.
	 */
	public static function filter_media_list( $query ) {
		$member_id = isset( $_GET[ self::QUERY_VAR ] ) ? absint( $_GET[ self::QUERY_VAR ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter; nothing is changed.

		if ( ! $member_id || ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) || ! self::user_can_tag() ) {
			return;
		}

		$query->set(
			'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- No other API finds the photos tagged with a member.
			array(
				array(
					'key'   => self::META,
					'value' => $member_id,
				),
			)
		);
	}
}

Chess_Army_Knife_Member_Photos::init();
