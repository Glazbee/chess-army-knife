<?php
/**
 * Club events: a custom post type with free-form tags, plus the query and
 * data helpers the blocks use.
 *
 * Each event is a post of type chess_army_event. Its date and time, end
 * time, location, attached tournaments and attached leagues live in post
 * meta. Times are stored as "Y-m-d H:i:s" in the site's timezone, which
 * sorts and compares correctly as text. Any number of events can share a
 * night or even a start time; they sort by start time, then title.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Events {

	const POST_TYPE = 'chess_army_event';
	const TAXONOMY  = 'chess_army_event_tag';

	const META_START       = '_chess_army_event_start';
	const META_END         = '_chess_army_event_end';
	const META_LOCATION    = '_chess_army_event_location';
	const META_TOURNAMENTS = '_chess_army_event_tournaments';
	const META_LEAGUES     = '_chess_army_event_leagues';

	/**
	 * Hook up registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the event post type and its tags.
	 */
	public static function register() {
		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Event tags', 'chess-army-knife' ),
					'singular_name' => __( 'Event tag', 'chess-army-knife' ),
					'add_new_item'  => __( 'Add new tag', 'chess-army-knife' ),
					'search_items'  => __( 'Search tags', 'chess-army-knife' ),
				),
				'hierarchical'      => false, // Free-form tags rather than fixed categories.
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'club-event-tag' ),
			)
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Club Events', 'chess-army-knife' ),
					'singular_name'      => __( 'Club Event', 'chess-army-knife' ),
					'add_new'            => __( 'Add Event', 'chess-army-knife' ),
					'add_new_item'       => __( 'Add New Club Event', 'chess-army-knife' ),
					'edit_item'          => __( 'Edit Club Event', 'chess-army-knife' ),
					'view_item'          => __( 'View Club Event', 'chess-army-knife' ),
					'search_items'       => __( 'Search Club Events', 'chess-army-knife' ),
					'not_found'          => __( 'No club events found.', 'chess-army-knife' ),
					'not_found_in_trash' => __( 'No club events found in the Trash.', 'chess-army-knife' ),
				),
				'public'       => true,
				'show_in_menu' => 'chess-army-knife', // Under the plugin's own menu.
				'show_in_rest' => true,
				'has_archive'  => false,
				'supports'     => array( 'title', 'editor' ),
				'taxonomies'   => array( self::TAXONOMY ),
				'rewrite'      => array( 'slug' => 'club-events' ),
			)
		);
	}

	/**
	 * The location used by events that don't set their own.
	 *
	 * @return string
	 */
	public static function default_location() {
		$options = Chess_Army_Knife_Settings::get_options();
		return isset( $options['default_event_location'] ) ? (string) $options['default_event_location'] : '';
	}

	/**
	 * Start time suggested for a new event.
	 *
	 * @return string HH:MM.
	 */
	public static function default_time() {
		return (string) apply_filters( 'Chess_Army_Knife_event_default_time', '19:00' );
	}

	/**
	 * Join a date and time into the stored "Y-m-d H:i:s" form.
	 *
	 * @param string $date Date, YYYY-MM-DD.
	 * @param string $time Time, HH:MM.
	 * @return string The combined value, or '' if either part is invalid.
	 */
	public static function combine_datetime( $date, $time ) {
		$date = (string) $date;
		$time = (string) $time;

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d ) || ! checkdate( (int) $d[2], (int) $d[3], (int) $d[1] ) ) {
			return '';
		}
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time ) ) {
			return '';
		}

		return $date . ' ' . $time . ':00';
	}

	/**
	 * Unix timestamp for a stored site-local date and time.
	 *
	 * @param string $local Stored "Y-m-d H:i:s" value.
	 * @return int|null Null if the value is not a valid date and time.
	 */
	public static function to_timestamp( $local ) {
		$date = date_create_immutable_from_format( 'Y-m-d H:i:s', (string) $local, wp_timezone() );
		return $date ? $date->getTimestamp() : null;
	}

	/**
	 * Stable text form of a league (LMS organisation and event name).
	 *
	 * @param string $org   LMS organisation id.
	 * @param string $event LMS event / division name.
	 * @return string
	 */
	public static function league_ref( $org, $event ) {
		return trim( (string) $org ) . '|' . trim( (string) $event );
	}

	/**
	 * Split a league reference made by league_ref().
	 *
	 * @param string $ref Reference.
	 * @return array|null { org, event }, or null if it is not a valid reference.
	 */
	public static function parse_league_ref( $ref ) {
		$parts = explode( '|', (string) $ref, 2 );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$org   = preg_replace( '/[^0-9]/', '', $parts[0] );
		$event = trim( $parts[1] );

		if ( '' === $org || '' === $event ) {
			return null;
		}

		return array(
			'org'   => $org,
			'event' => $event,
		);
	}

	/**
	 * Get events as data arrays, soonest first by default.
	 *
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type string   $after  Earliest start ("now", a site-local "Y-m-d H:i:s", or '' for no limit).
	 *                            An event already under way at that time (its end is later) is included.
	 *     @type string   $before Only events starting before this site-local "Y-m-d H:i:s".
	 *     @type string[] $tags   Tag slugs; an event with any of them matches.
	 *     @type int      $limit  Maximum events (0 for all).
	 *     @type string   $order  ASC or DESC by start time.
	 * }
	 * @return array[] See data().
	 */
	public static function query( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'after'  => 'now',
				'before' => '',
				'tags'   => array(),
				'limit'  => 10,
				'order'  => 'ASC',
			)
		);

		$meta_query = array(
			'relation'     => 'AND',
			'start_clause' => array(
				'key'     => self::META_START,
				'compare' => 'EXISTS',
				'type'    => 'DATETIME',
			),
		);

		if ( '' !== $args['after'] ) {
			$after        = 'now' === $args['after'] ? current_time( 'mysql' ) : $args['after'];
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => self::META_START,
					'value'   => $after,
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
				array(
					'key'     => self::META_END,
					'value'   => $after,
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
			);
		}

		if ( '' !== $args['before'] ) {
			$meta_query[] = array(
				'key'     => self::META_START,
				'value'   => $args['before'],
				'compare' => '<',
				'type'    => 'DATETIME',
			);
		}

		$query_args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $args['limit'] > 0 ? (int) $args['limit'] : -1,
			'no_found_rows'  => true,
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_meta_query
			'orderby'        => array(
				'start_clause' => 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC',
				'title'        => 'ASC',
			),
		);

		$tags = array_filter( array_map( 'sanitize_title', (array) $args['tags'] ) );
		if ( $tags ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'slug',
					'terms'    => array_values( $tags ),
				),
			);
		}

		$events = array();
		foreach ( get_posts( $query_args ) as $post ) {
			$events[] = self::data( $post );
		}

		return $events;
	}

	/**
	 * Everything the blocks need to show one event.
	 *
	 * @param WP_Post $post Event post.
	 * @return array {
	 *     @type int      $id
	 *     @type string   $title
	 *     @type string   $url
	 *     @type string   $start               Site-local "Y-m-d H:i:s".
	 *     @type int|null $start_ts            Unix timestamp of the start.
	 *     @type string   $end                 Site-local "Y-m-d H:i:s", or ''.
	 *     @type string   $location            The event's own location, or the default.
	 *     @type array[]  $tags                Each { name, slug, url }.
	 *     @type array[]  $tournaments         Each { id, name, url } (url '' unless it has a published page).
	 *     @type array[]  $leagues             Each { org, event }.
	 * }
	 */
	public static function data( $post ) {
		$id       = (int) $post->ID;
		$start    = (string) get_post_meta( $id, self::META_START, true );
		$location = trim( (string) get_post_meta( $id, self::META_LOCATION, true ) );

		$tags  = array();
		$terms = get_the_terms( $id, self::TAXONOMY );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$link   = get_term_link( $term );
			$tags[] = array(
				'name' => $term->name,
				'slug' => $term->slug,
				'url'  => is_wp_error( $link ) ? '' : $link,
			);
		}

		$tournaments = array();
		foreach ( (array) get_post_meta( $id, self::META_TOURNAMENTS, true ) as $tournament_id ) {
			$tournament = Chess_Army_Knife_Tournament_Store::get_tournament( (int) $tournament_id );
			if ( ! $tournament ) {
				continue; // The tournament was deleted after being attached.
			}
			$page          = Chess_Army_Knife_Tournaments::get_page( $tournament );
			$tournaments[] = array(
				'id'   => (int) $tournament['id'],
				'name' => $tournament['name'],
				// A draft page would only give visitors a 404.
				'url'  => ( $page && 'publish' === $page->post_status ) ? get_permalink( $page ) : '',
			);
		}

		$leagues = array();
		foreach ( (array) get_post_meta( $id, self::META_LEAGUES, true ) as $ref ) {
			$league = self::parse_league_ref( $ref );
			if ( $league ) {
				$leagues[] = $league;
			}
		}

		return array(
			'id'          => $id,
			'title'       => get_the_title( $post ),
			'url'         => get_permalink( $post ),
			'start'       => $start,
			'start_ts'    => self::to_timestamp( $start ),
			'end'         => (string) get_post_meta( $id, self::META_END, true ),
			'location'    => '' !== $location ? $location : self::default_location(),
			'tags'        => $tags,
			'tournaments' => $tournaments,
			'leagues'     => $leagues,
		);
	}
}

Chess_Army_Knife_Events::init();
