<?php
/**
 * Club events: a custom post type with free-form tags, plus the query and
 * data helpers the blocks use.
 *
 * Each event is a post of type chess_army_event. It has no page of its own:
 * a page can be attached to it (see page_url()). Its date and time, end
 * time, repeat rule, location, attached tournaments and attached leagues
 * live in post meta. Times are stored as "Y-m-d H:i:s" in the site's
 * timezone, which sorts and compares correctly as text. A repeating event
 * is stored once, with the date and time of its first occurrence, and
 * query() works out each occurrence. Any number of events can share a
 * night or even a start time; they sort by start time, then title.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Events {

	const POST_TYPE = 'chess_army_event';
	const TAXONOMY  = 'chess_army_event_tag';

	const META_START       = '_chess_army_event_start';
	const META_END         = '_chess_army_event_end'; // End of the first occurrence; later ones last as long.
	const META_REPEAT      = '_chess_army_event_repeat'; // 'weekly', 'monthly' or 'annually'; absent for a one-off.
	const META_UNTIL       = '_chess_army_event_until'; // Last date a repeating event can occur, "Y-m-d"; absent for no end.
	const META_SKIP        = '_chess_army_event_skip'; // Dates, "Y-m-d", on which a repeating event does not happen (holidays).
	const META_STATUS      = '_chess_army_event_status'; // 'cancelled' or 'moved'; absent for an event going ahead as planned.
	const META_STATUS_NOTE = '_chess_army_event_status_note'; // A few words for the status, such as where a moved event went.
	const META_TYPE        = '_chess_army_event_type'; // A key of types(); absent for an event with no type.
	const META_PAGE        = '_chess_army_event_page'; // Id of the page attached to the event.
	const META_COLOUR      = '_chess_army_event_colour'; // Hex colour of the event's bubble in the calendar; absent for the default.
	const META_LOCATION    = '_chess_army_event_location';
	const META_MAP         = '_chess_army_event_map'; // Link to the venue on a map (Google Maps or similar).
	const META_W3W         = '_chess_army_event_w3w'; // The venue's what3words address, "word.word.word".
	const META_TOURNAMENTS = '_chess_army_event_tournaments';
	const META_LEAGUES     = '_chess_army_event_leagues';
	const META_TEAM        = '_chess_army_event_team'; // One row per club team playing in the fixture.
	const META_SIDES       = '_chess_army_event_sides'; // Team id => 'home' or 'away'.

	/** Term meta holding a tag's colour, a hex code. */
	const TAG_COLOUR_META = 'chess_army_tag_colour';

	/** How far ahead, in days, a repeating event is worked out when a query has no end. */
	const HORIZON_DAYS = 366;

	/** Most occurrences worked out for one event, so a mistake in the data cannot loop for long. */
	const MAX_OCCURRENCES = 1000;

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
				'public'            => false, // Events have no pages, so their tags have none either.
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => false,
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
					'search_items'       => __( 'Search Club Events', 'chess-army-knife' ),
					'not_found'          => __( 'No club events found.', 'chess-army-knife' ),
					'not_found_in_trash' => __( 'No club events found in the Trash.', 'chess-army-knife' ),
				),
				'public'       => false, // An event has no page of its own; see page_url().
				'show_ui'      => true,
				'show_in_menu' => false, // Listed in the plugin's menu (see Chess_Army_Knife_Menu).
				'show_in_rest' => true,
				'has_archive'  => false,
				'supports'     => array( 'title' ),
				'taxonomies'   => array( self::TAXONOMY ),
				'rewrite'      => false,
			)
		);
	}

	/**
	 * A map link: an http or https address, or ''.
	 *
	 * @param string $url Raw address.
	 * @return string
	 */
	public static function clean_map_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );

		return $url;
	}

	/**
	 * A what3words address as "word.word.word", from what people type or paste:
	 * "///index.home.raft", "index.home.raft" or a what3words.com link.
	 *
	 * @param string $raw What was entered.
	 * @return string The cleaned address, or '' if it is not three words.
	 */
	public static function clean_what3words( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		$raw = preg_replace( '#^https?://(?:www\.)?what3words\.com/#', '', $raw );
		$raw = ltrim( $raw, '/' );

		return preg_match( '/^[\p{L}\p{N}\-]+\.[\p{L}\p{N}\-]+\.[\p{L}\p{N}\-]+$/u', $raw ) ? $raw : '';
	}

	/**
	 * The club venue's map link and what3words address from Settings, with its name.
	 *
	 * @return string[] { location, map_url, what3words }.
	 */
	public static function default_venue() {
		$options = Chess_Army_Knife_Settings::get_options();

		return array(
			'location'   => trim( (string) $options['club_venue'] ),
			'map_url'    => (string) $options['club_venue_map'],
			'what3words' => (string) $options['club_venue_w3w'],
		);
	}

	/**
	 * The kinds of event a club has. A type gives an event a tag and, if that tag has no colour chosen, a
	 * colour (always one of tag_palette(), so the text on its calendar bubble stays readable). Anything
	 * finer than a type, such as a team's name, is still a tag.
	 *
	 * @return array[] By key: { label, tag, colour }.
	 */
	public static function types() {
		$palette = self::tag_palette();
		$types   = array(
			'club_night'   => array(
				'label'  => __( 'Club night', 'chess-army-knife' ),
				'tag'    => __( 'Club night', 'chess-army-knife' ),
				'colour' => $palette[0],
			),
			'coaching'     => array(
				'label'  => __( 'Coaching', 'chess-army-knife' ),
				'tag'    => __( 'Coaching', 'chess-army-knife' ),
				'colour' => $palette[2],
			),
			'competitive'  => array(
				'label'  => __( 'Competitive games', 'chess-army-knife' ),
				'tag'    => __( 'Competitive games', 'chess-army-knife' ),
				'colour' => $palette[3],
			),
			'tournament'   => array(
				'label'  => __( 'Tournament', 'chess-army-knife' ),
				'tag'    => __( 'Tournament', 'chess-army-knife' ),
				'colour' => $palette[4],
			),
			'league_match' => array(
				'label'  => __( 'League match', 'chess-army-knife' ),
				'tag'    => __( 'League match', 'chess-army-knife' ),
				'colour' => $palette[1],
			),
		);

		/**
		 * Filter the kinds of event.
		 *
		 * @param array[] $types By key: { label, tag, colour (a hex code) }.
		 */
		$filtered = apply_filters( 'Chess_Army_Knife_event_types', $types );

		return is_array( $filtered ) ? $filtered : $types;
	}

	/**
	 * Give an event a type: it gets the type's tag, and the tag gets the type's colour unless a colour was already
	 * chosen for it.
	 *
	 * @param int    $post_id Event id.
	 * @param string $type    A key of types(); '' takes the type away (the tag stays: it may have been added by hand).
	 * @return bool Whether the event now has a type.
	 */
	public static function apply_type( $post_id, $type ) {
		$types = self::types();
		if ( '' === (string) $type || ! isset( $types[ $type ] ) ) {
			delete_post_meta( $post_id, self::META_TYPE );
			return false;
		}

		update_post_meta( $post_id, self::META_TYPE, $type );
		wp_set_object_terms( $post_id, array( $types[ $type ]['tag'] ), self::TAXONOMY, true );

		$term = get_term_by( 'name', $types[ $type ]['tag'], self::TAXONOMY );
		if ( $term && ! is_wp_error( $term ) && '' === (string) get_term_meta( $term->term_id, self::TAG_COLOUR_META, true ) && sanitize_hex_color( $types[ $type ]['colour'] ) ) {
			update_term_meta( $term->term_id, self::TAG_COLOUR_META, sanitize_hex_color( $types[ $type ]['colour'] ) );
		}

		return true;
	}

	/**
	 * Colours given to tags that have none chosen, so no event is left grey.
	 *
	 * @return string[] Hex colours, distinguishable from one another.
	 */
	public static function tag_palette() {
		return array( '#2a78d6', '#d62a2a', '#2a9d5c', '#c77d0a', '#8a4fd6', '#d62a8f', '#0f9aa8', '#7a5c2e' );
	}

	/**
	 * A tag's colour: the one chosen for it, or one from the palette picked by its name,
	 * so the same tag always has the same colour.
	 *
	 * @param WP_Term $term Event tag.
	 * @return string Hex colour.
	 */
	public static function tag_colour( $term ) {
		$colour = sanitize_hex_color( (string) get_term_meta( $term->term_id, self::TAG_COLOUR_META, true ) );
		if ( $colour ) {
			return $colour;
		}

		$palette = self::tag_palette();
		return $palette[ crc32( (string) $term->slug ) % count( $palette ) ];
	}

	/**
	 * The club's venue, used by events that don't set their own location.
	 *
	 * @return string
	 */
	public static function default_location() {
		return (string) Chess_Army_Knife_Settings::get_options()['club_venue'];
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
	 * The start of each occurrence of an event within a window.
	 *
	 * A one-off event has one occurrence. A repeating one occurs on the same
	 * weekday every week, on the same day of the month every month (the last
	 * day of a shorter month) or on the same date every year (28 February for
	 * 29 February in other years), at the same time of day.
	 *
	 * @param string $first  Start of the first occurrence, "Y-m-d H:i:s".
	 * @param string $repeat 'weekly', 'monthly', 'annually' or ''.
	 * @param string $until  Last date it can occur, "Y-m-d", or '' for no end.
	 * @param string $from   Earliest start wanted, "Y-m-d H:i:s", or '' for no limit.
	 * @param string $to     Starts must be before this, "Y-m-d H:i:s", or '' to look a year ahead.
	 * @param array  $skip   Dates, "Y-m-d", on which a repeating event does not happen.
	 * @return string[] Starts, "Y-m-d H:i:s", earliest first.
	 */
	public static function occurrence_starts( $first, $repeat, $until, $from, $to, array $skip = array() ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}:\d{2})$/', (string) $first, $m ) ) {
			return array();
		}
		list( , $year, $month, $day, $time ) = $m;

		if ( ! in_array( $repeat, array( 'weekly', 'monthly', 'annually' ), true ) ) {
			return ( '' === $from || $first >= $from ) && ( '' === $to || $first < $to ) ? array( $first ) : array();
		}

		if ( '' === $to ) {
			$base = '' !== $from && $from > $first ? $from : $first;
			$to   = gmdate( 'Y-m-d H:i:s', strtotime( $base . ' UTC' ) + self::HORIZON_DAYS * DAY_IN_SECONDS );
		}

		$starts = array();
		for ( $n = 0; $n < self::MAX_OCCURRENCES; $n++ ) {
			if ( 'weekly' === $repeat ) {
				$date = gmdate( 'Y-m-d', gmmktime( 12, 0, 0, (int) $month, (int) $day + 7 * $n, (int) $year ) );
			} else {
				$months = 'monthly' === $repeat ? $n : 12 * $n;
				$index  = (int) $year * 12 + (int) $month - 1 + $months;
				$y      = intdiv( $index, 12 );
				$mo     = $index % 12 + 1;
				$last   = (int) gmdate( 't', gmmktime( 12, 0, 0, $mo, 1, $y ) );
				$date   = sprintf( '%04d-%02d-%02d', $y, $mo, min( (int) $day, $last ) );
			}

			$start = $date . ' ' . $time;
			if ( $start >= $to || ( '' !== $until && $date > $until ) ) {
				break;
			}
			if ( ( '' === $from || $start >= $from ) && ! in_array( $date, $skip, true ) ) {
				$starts[] = $start;
			}
		}

		return $starts;
	}

	/**
	 * Get events as data arrays, soonest first by default. A repeating event
	 * appears once for each of its occurrences in the window, all with the same id.
	 *
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type string   $after  Earliest start ("now", a site-local "Y-m-d H:i:s", or '' for no limit).
	 *                            An event already under way at that time (its end is later) is included.
	 *     @type string   $before Only events starting before this site-local "Y-m-d H:i:s". Without it a
	 *                            repeating event is shown for a year ahead.
	 *     @type string[] $tags   Tag slugs; an event with any of them matches.
	 *     @type int[]    $teams  Team ids; a fixture of any of these teams matches.
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
				'teams'  => array(),
				'limit'  => 10,
				'order'  => 'ASC',
			)
		);

		$after = 'now' === $args['after'] ? current_time( 'mysql' ) : (string) $args['after'];

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => self::META_START,
				'compare' => 'EXISTS',
			),
		);

		$team_ids = array_values( array_filter( array_map( 'absint', (array) $args['teams'] ) ) );
		if ( $team_ids ) {
			$meta_query[] = array(
				'key'     => self::META_TEAM,
				'value'   => $team_ids,
				'compare' => 'IN',
				'type'    => 'NUMERIC',
			);
		}

		$query_args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- No other API filters events by meta or tag; the post type is small.
		);

		$tags = array_filter( array_map( 'sanitize_title', (array) $args['tags'] ) );
		if ( $tags ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- No other API filters events by meta or tag; the post type is small.
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'slug',
					'terms'    => array_values( $tags ),
				),
			);
		}

		// Occurrences are worked out here, because a repeating event is stored once.
		$events = array();
		foreach ( get_posts( $query_args ) as $post ) {
			$first    = (string) get_post_meta( $post->ID, self::META_START, true );
			$end      = (string) get_post_meta( $post->ID, self::META_END, true );
			$length   = '' !== $end ? max( 0, (int) self::to_timestamp( $end ) - (int) self::to_timestamp( $first ) ) : 0;
			$earliest = '' !== $after ? gmdate( 'Y-m-d H:i:s', strtotime( $after . ' UTC' ) - $length ) : ''; // Counts an occurrence still under way.

			$starts = self::occurrence_starts(
				$first,
				(string) get_post_meta( $post->ID, self::META_REPEAT, true ),
				(string) get_post_meta( $post->ID, self::META_UNTIL, true ),
				$earliest,
				(string) $args['before'],
				self::skipped_dates( $post->ID )
			);
			foreach ( $starts as $start ) {
				$events[] = self::data( $post, $start );
			}
		}

		$descending = 'DESC' === strtoupper( $args['order'] );
		usort(
			$events,
			function ( $a, $b ) use ( $descending ) {
				$by_start = strcmp( $a['start'], $b['start'] );
				if ( 0 === $by_start ) {
					return strnatcasecmp( $a['title'], $b['title'] );
				}
				return $descending ? -$by_start : $by_start;
			}
		);

		return $args['limit'] > 0 ? array_slice( $events, 0, (int) $args['limit'] ) : $events;
	}

	/**
	 * Read dates typed one per line, or separated by commas or spaces.
	 *
	 * @param string $text Typed dates, YYYY-MM-DD.
	 * @return string[] The valid ones, earliest first, each once.
	 */
	public static function parse_dates( $text ) {
		$dates = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $text ) as $date ) {
			if ( '' !== self::combine_datetime( $date, '00:00' ) ) {
				$dates[ $date ] = $date;
			}
		}
		ksort( $dates );

		return array_values( $dates );
	}

	/**
	 * The dates a repeating event skips.
	 *
	 * @param int $event_id Event id.
	 * @return string[] "Y-m-d" dates.
	 */
	public static function skipped_dates( $event_id ) {
		return array_values( array_filter( (array) get_post_meta( $event_id, self::META_SKIP, true ) ) );
	}

	/**
	 * Labels for the states an event can be in other than going ahead.
	 *
	 * @return string[] Label for each status.
	 */
	public static function status_labels() {
		return array(
			'cancelled' => __( 'Cancelled', 'chess-army-knife' ),
			'moved'     => __( 'Moved', 'chess-army-knife' ),
		);
	}

	/**
	 * One occurrence of a published event, for downloading it.
	 *
	 * @param int    $event_id Event id.
	 * @param string $start    Start of the occurrence, "Y-m-d H:i:s".
	 * @return array|null See data(); null if there is no such published event or it does not happen then.
	 */
	public static function get_occurrence( $event_id, $start ) {
		// get_post( 0 ) would give the page being viewed.
		$post = $event_id ? get_post( (int) $event_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$start  = (string) $start;
		$starts = self::occurrence_starts(
			(string) get_post_meta( $post->ID, self::META_START, true ),
			(string) get_post_meta( $post->ID, self::META_REPEAT, true ),
			(string) get_post_meta( $post->ID, self::META_UNTIL, true ),
			$start,
			gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC' ) + 1 ),
			self::skipped_dates( $post->ID )
		);

		return in_array( $start, $starts, true ) ? self::data( $post, $start ) : null;
	}

	/**
	 * The event that has a tournament attached, if any.
	 *
	 * @param int $tournament_id Tournament id.
	 * @return int The event's id, or 0 if no event (published, draft or private) has it attached.
	 */
	public static function for_tournament( $tournament_id ) {
		$tournament_id = (int) $tournament_id;
		if ( ! $tournament_id ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META_TOURNAMENTS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Events that have tournaments; the post type is small.
			)
		);

		foreach ( $ids as $id ) {
			$attached = array_map( 'intval', (array) get_post_meta( $id, self::META_TOURNAMENTS, true ) );
			if ( in_array( $tournament_id, $attached, true ) ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * The published event a page is attached to.
	 *
	 * @param int $page_id Page id.
	 * @return int The event's id, or 0 if no published event has this page attached.
	 */
	public static function event_for_page( $page_id ) {
		$page_id = (int) $page_id;
		if ( ! $page_id ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META_PAGE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup of a page's event; the post type is small.
				'meta_value'     => $page_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * One published event as data, at its next occurrence (or its first, if a one-off is over or a
	 * repeat has ended), for the page that is about it.
	 *
	 * @param int $event_id Event id.
	 * @return array|null See data(); null if there is no such published event.
	 */
	public static function details( $event_id ) {
		// get_post( 0 ) would give the page being viewed.
		$post = $event_id ? get_post( (int) $event_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$first  = (string) get_post_meta( $post->ID, self::META_START, true );
		$end    = (string) get_post_meta( $post->ID, self::META_END, true );
		$length = '' !== $end ? max( 0, (int) self::to_timestamp( $end ) - (int) self::to_timestamp( $first ) ) : 0;
		$now    = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' UTC' ) - $length ); // An occurrence still under way counts.

		$starts = self::occurrence_starts(
			$first,
			(string) get_post_meta( $post->ID, self::META_REPEAT, true ),
			(string) get_post_meta( $post->ID, self::META_UNTIL, true ),
			$now,
			'',
			self::skipped_dates( $post->ID )
		);

		return self::data( $post, $starts ? $starts[0] : '' );
	}

	/**
	 * The page attached to an event, if it is published (a draft would only give visitors a 404).
	 *
	 * @param int $event_id Event id.
	 * @return string The page's address, or '' if the event has no published page.
	 */
	public static function page_url( $event_id ) {
		$page_id = (int) get_post_meta( $event_id, self::META_PAGE, true );
		// get_post( 0 ) would give the page being viewed, so an event with no page must not ask.
		$page = $page_id ? get_post( $page_id ) : null;

		return ( $page && 'page' === $page->post_type && 'publish' === $page->post_status ) ? (string) get_permalink( $page ) : '';
	}

	/**
	 * Everything the blocks need to show one event.
	 *
	 * @param WP_Post $post  Event post.
	 * @param string  $start Start of one occurrence of a repeating event, "Y-m-d H:i:s"; the first by default.
	 * @return array {
	 *     @type int      $id
	 *     @type string   $title
	 *     @type string   $url                 The event's page, or '' if it has no published page.
	 *     @type string   $start               Site-local "Y-m-d H:i:s".
	 *     @type int|null $start_ts            Unix timestamp of the start.
	 *     @type string   $end                 Site-local "Y-m-d H:i:s", or ''.
	 *     @type string   $repeat              'weekly', 'monthly', 'annually' or ''.
	 *     @type string   $type                A key of types(), or ''.
	 *     @type string   $status              'cancelled', 'moved' or ''.
	 *     @type string   $status_note         A few words about the status, or ''.
	 *     @type string   $colour              The event's own colour, a hex code, or ''.
	 *     @type string   $location            The event's own location, or the club venue.
	 *     @type string   $map_url             Link to it on a map, or ''.
	 *     @type string   $what3words          Its what3words address, "word.word.word", or ''.
	 *     @type array[]  $tags                Each { name, slug, colour }.
	 *     @type array[]  $tournaments         Each { id, name, url } (url '' unless it has a published page).
	 *     @type array[]  $leagues             Each { org, event }.
	 *     @type array[]  $teams               The club teams playing, each { id, name, colour, side }.
	 * }
	 */
	public static function data( $post, $start = '' ) {
		$id    = (int) $post->ID;
		$first = (string) get_post_meta( $id, self::META_START, true );
		$end   = (string) get_post_meta( $id, self::META_END, true );
		$start = '' !== $start ? (string) $start : $first;
		$venue = array(
			'location'   => trim( (string) get_post_meta( $id, self::META_LOCATION, true ) ),
			'map_url'    => (string) get_post_meta( $id, self::META_MAP, true ),
			'what3words' => (string) get_post_meta( $id, self::META_W3W, true ),
		);
		// An event with no venue of its own is held at the club venue, unless it is an away fixture.
		// No stored sides is an empty string, so an event that is not a fixture is never "away".
		$sides = array_filter( (array) get_post_meta( $id, self::META_SIDES, true ) );
		$away  = $sides && ! in_array( 'home', $sides, true );
		if ( '' === implode( '', $venue ) && ! $away ) {
			$venue = self::default_venue();
		}

		$tags  = array();
		$terms = get_the_terms( $id, self::TAXONOMY );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$tags[] = array(
				'name'   => $term->name,
				'slug'   => $term->slug,
				'colour' => self::tag_colour( $term ),
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

		$teams = array();
		$sides = (array) get_post_meta( $id, self::META_SIDES, true );
		foreach ( get_post_meta( $id, self::META_TEAM, false ) as $team_id ) {
			$team = Chess_Army_Knife_Teams::get( (int) $team_id );
			if ( $team ) {
				$teams[] = array(
					'id'     => $team['id'],
					'name'   => $team['name'],
					'colour' => $team['colour'],
					'side'   => isset( $sides[ $team['id'] ] ) ? (string) $sides[ $team['id'] ] : '',
				);
			}
		}

		// Every occurrence lasts as long as the first.
		$start_ts = self::to_timestamp( $start );
		if ( '' !== $end && $start !== $first && null !== $start_ts && null !== self::to_timestamp( $first ) && null !== self::to_timestamp( $end ) ) {
			$end = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC' ) + ( strtotime( $end . ' UTC' ) - strtotime( $first . ' UTC' ) ) );
		}

		$status = (string) get_post_meta( $id, self::META_STATUS, true );
		$status = isset( self::status_labels()[ $status ] ) ? $status : '';

		return array(
			'id'          => $id,
			'title'       => get_the_title( $post ),
			'url'         => self::page_url( $id ),
			'start'       => $start,
			'start_ts'    => $start_ts,
			'end'         => $end,
			'repeat'      => (string) get_post_meta( $id, self::META_REPEAT, true ),
			'type'        => (string) get_post_meta( $id, self::META_TYPE, true ),
			'status'      => $status,
			'status_note' => $status ? (string) get_post_meta( $id, self::META_STATUS_NOTE, true ) : '',
			'colour'      => (string) sanitize_hex_color( (string) get_post_meta( $id, self::META_COLOUR, true ) ),
			'location'    => $venue['location'],
			'map_url'     => $venue['map_url'],
			'what3words'  => $venue['what3words'],
			'tags'        => $tags,
			'tournaments' => $tournaments,
			'leagues'     => $leagues,
			'teams'       => $teams,
		);
	}
}

Chess_Army_Knife_Events::init();
