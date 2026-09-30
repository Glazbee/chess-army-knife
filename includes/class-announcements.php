<?php
/**
 * Club announcements: a message to an audience, sent by email now or at a
 * chosen time, and kept so members can read past ones in the Member Portal.
 *
 * An announcement is a non-public post of type chess_army_announce (19
 * characters; WordPress allows 20). Its audience is all current members, the
 * squads of chosen teams, or juniors (which means their parents, who are the
 * contact). Each is sent through the mailer, so it goes to the member's
 * contact address (a junior's parent), only if they have not turned that kind
 * of email off, and only once to an address even if it appears for several
 * people. The newsletter category needs the newsletter opt-in; the general
 * announcement category is a service message that members can switch off.
 * WhatsApp groups stay manual: the screen lists who agreed to a team's group.
 *
 * There is no approval step: officers with the membership permission send
 * directly. What was sent, when and to how many is kept with the post.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Announcements {

	const POST_TYPE = 'chess_army_announce';
	const HOOK      = 'Chess_Army_Knife_send_announcements';

	const META_AUDIENCE = '_chess_army_ann_audience';
	const META_TEAMS    = '_chess_army_ann_teams';
	const META_CATEGORY = '_chess_army_ann_category';
	const META_SEND_AT  = '_chess_army_ann_send_at';
	const META_STATE    = '_chess_army_ann_state';
	const META_RESULT   = '_chess_army_ann_result';

	const STATE_SCHEDULED = 'scheduled';
	const STATE_SENT      = 'sent';

	/**
	 * Hook up registration and the scheduled sending.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'send_due' ) );
	}

	/**
	 * Register the announcement post type, under the Memberships menu.
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Announcements', 'chess-army-knife' ),
					'singular_name'      => __( 'Announcement', 'chess-army-knife' ),
					'add_new'            => __( 'Add Announcement', 'chess-army-knife' ),
					'add_new_item'       => __( 'Add New Announcement', 'chess-army-knife' ),
					'edit_item'          => __( 'Edit Announcement', 'chess-army-knife' ),
					'search_items'       => __( 'Search Announcements', 'chess-army-knife' ),
					'not_found'          => __( 'No announcements found.', 'chess-army-knife' ),
					'not_found_in_trash' => __( 'No announcements found in the Trash.', 'chess-army-knife' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => Chess_Army_Knife_Memberships::MENU_SLUG,
				'show_in_rest' => false, // Classic editing screen: the message is plain text with paragraphs.
				'supports'     => array( 'title', 'editor' ),
				'capabilities' => Chess_Army_Knife_Memberships::post_capabilities(),
			)
		);
	}

	/**
	 * Schedule the hourly job that sends announcements that are due.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Stop the job (on deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The audiences an announcement can go to.
	 *
	 * @return string[] Key => label.
	 */
	public static function audiences() {
		return array(
			'members' => __( 'All current members', 'chess-army-knife' ),
			'teams'   => __( 'The squads of chosen teams', 'chess-army-knife' ),
			'juniors' => __( 'Juniors (sent to their parents or guardians)', 'chess-army-knife' ),
		);
	}

	/**
	 * The kinds of email an announcement can be sent as.
	 *
	 * @return string[] Category key => label.
	 */
	public static function categories() {
		$all = Chess_Army_Knife_Notification_Preferences::categories();
		return array(
			'announcements' => $all['announcements'],
			Chess_Army_Knife_Notification_Preferences::NEWSLETTER => $all[ Chess_Army_Knife_Notification_Preferences::NEWSLETTER ],
		);
	}

	/**
	 * An announcement's settings.
	 *
	 * @param int $post_id Announcement id.
	 * @return array { audience, teams, category, send_at, state, result }.
	 */
	public static function settings( $post_id ) {
		$audience = (string) get_post_meta( $post_id, self::META_AUDIENCE, true );
		$category = (string) get_post_meta( $post_id, self::META_CATEGORY, true );
		$teams    = get_post_meta( $post_id, self::META_TEAMS, true );
		$result   = get_post_meta( $post_id, self::META_RESULT, true );

		return array(
			'audience' => isset( self::audiences()[ $audience ] ) ? $audience : 'members',
			'teams'    => is_array( $teams ) ? array_values( array_map( 'intval', $teams ) ) : array(),
			'category' => isset( self::categories()[ $category ] ) ? $category : 'announcements',
			'send_at'  => (string) get_post_meta( $post_id, self::META_SEND_AT, true ),
			'state'    => (string) get_post_meta( $post_id, self::META_STATE, true ),
			'result'   => is_array( $result ) ? $result : array(),
		);
	}

	/* -------------------------------------------------------------
	 * Audience
	 * ------------------------------------------------------------- */

	/**
	 * The people an announcement is for.
	 *
	 * @param string $audience members, teams or juniors.
	 * @param int[]  $team_ids Team ids, for the teams audience.
	 * @return array[] Person rows.
	 */
	public static function audience_people( $audience, array $team_ids = array() ) {
		$members = Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'active' ) );

		if ( 'juniors' === $audience ) {
			return array_values( array_filter( $members, array( 'Chess_Army_Knife_Member_Portal', 'is_junior' ) ) );
		}
		if ( 'teams' === $audience ) {
			$ids = array();
			foreach ( $team_ids as $team_id ) {
				$ids = array_merge( $ids, Chess_Army_Knife_Teams::squad( $team_id ) );
			}
			$ids = array_unique( $ids );
			return array_values(
				array_filter(
					$members,
					function ( $person ) use ( $ids ) {
						return in_array( $person['id'], $ids, true );
					}
				)
			);
		}
		return $members;
	}

	/**
	 * Whether a person is in an announcement's audience (used for the archive).
	 *
	 * @param array $person      Person row.
	 * @param array $settings    See settings().
	 * @return bool
	 */
	public static function is_for( array $person, array $settings ) {
		foreach ( self::audience_people( $settings['audience'], $settings['teams'] ) as $member ) {
			if ( $member['id'] === $person['id'] ) {
				return true;
			}
		}
		return false;
	}

	/* -------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------- */

	/**
	 * The text of an announcement as plain text for an email.
	 *
	 * @param WP_Post $post Announcement.
	 * @return string
	 */
	public static function plain_text( $post ) {
		$html = str_replace( array( '</p>', '<br>', '<br />', '<br/>' ), "\n", wpautop( $post->post_content ) );
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Send an announcement now. It is sent once: a second call does nothing.
	 *
	 * @param int $post_id Announcement id.
	 * @return array|null { queued, skipped, sent_at } or null if it was not sent (already sent, or no such announcement).
	 */
	public static function send( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		$settings = self::settings( $post_id );
		if ( self::STATE_SENT === $settings['state'] ) {
			return null;
		}

		// Marked first, so two overlapping runs cannot send it twice.
		update_post_meta( $post_id, self::META_STATE, self::STATE_SENT );

		$body    = self::plain_text( $post );
		$subject = get_the_title( $post );
		$queued  = 0;
		$skipped = 0;
		$seen    = array();

		foreach ( self::audience_people( $settings['audience'], $settings['teams'] ) as $person ) {
			$address = strtolower( Chess_Army_Knife_Membership_Store::contact_email( $person ) );
			if ( '' !== $address && isset( $seen[ $address ] ) ) {
				continue; // One email to an address, however many people it covers.
			}
			if ( Chess_Army_Knife_Mailer::queue( $person, $settings['category'], $subject, $body ) ) {
				++$queued;
				$seen[ $address ] = true;
			} else {
				++$skipped; // No address, or they have turned this kind of email off.
			}
		}

		$result = array(
			'queued'  => $queued,
			'skipped' => $skipped,
			'sent_at' => current_time( 'mysql' ),
		);
		update_post_meta( $post_id, self::META_RESULT, $result );
		return $result;
	}

	/**
	 * Send the scheduled announcements that are due. Runs hourly.
	 *
	 * @return int How many were sent.
	 */
	public static function send_due() {
		$count = 0;
		$due   = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => self::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Finds the few announcements waiting to go out.
				'meta_value'     => self::STATE_SCHEDULED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Finds the few announcements waiting to go out.
			)
		);
		foreach ( $due as $post ) {
			$settings = self::settings( $post->ID );
			if ( '' === $settings['send_at'] || $settings['send_at'] <= current_time( 'mysql' ) ) {
				$count += self::send( $post->ID ) ? 1 : 0;
			}
		}
		return $count;
	}

	/* -------------------------------------------------------------
	 * The archive
	 * ------------------------------------------------------------- */

	/**
	 * The sent announcements a person was in the audience of, newest first.
	 *
	 * @param array $person Person row.
	 * @param int   $limit  Most to return.
	 * @return array[] Each { id, title, sent_at, html }.
	 */
	public static function for_person( array $person, $limit = 10 ) {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_key'       => self::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The announcement post type is small.
				'meta_value'     => self::STATE_SENT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The announcement post type is small.
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			$settings = self::settings( $post->ID );
			if ( ! self::is_for( $person, $settings ) ) {
				continue;
			}
			$sent_at = isset( $settings['result']['sent_at'] ) ? $settings['result']['sent_at'] : '';
			$out[]   = array(
				'id'      => $post->ID,
				'title'   => get_the_title( $post ),
				'sent_at' => $sent_at,
				'html'    => wp_kses_post( wpautop( $post->post_content ) ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $b['sent_at'], $a['sent_at'] );
			}
		);
		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}
}

Chess_Army_Knife_Announcements::init();
