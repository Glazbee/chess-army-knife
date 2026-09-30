<?php
/**
 * An iCalendar (.ics) feed of the club's events, so members can subscribe to
 * them in their own calendar app. It carries only what the public event blocks
 * already show: titles, times, locations and leagues. A feed can be narrowed to
 * some teams, to home or away fixtures and to some tags by query arguments, so
 * there is one address for the whole club and one for each team.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Events_Feed {

	const QUERY_VAR = 'chess_army_ics';

	/** Most events in one feed. */
	const MAX_EVENTS = 500;

	/**
	 * Serve the feed when it is asked for.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ) );
	}

	/**
	 * The address of a feed.
	 *
	 * @param int[]    $team_ids Teams to include; none means every event.
	 * @param string   $venue    'all', 'home' or 'away'.
	 * @param string[] $tags     Tag slugs.
	 * @return string
	 */
	public static function url( array $team_ids = array(), $venue = 'all', array $tags = array() ) {
		$args = array( self::QUERY_VAR => '1' );
		if ( $team_ids ) {
			$args['teams'] = implode( ',', array_map( 'absint', $team_ids ) );
		}
		if ( in_array( $venue, array( 'home', 'away' ), true ) ) {
			$args['venue'] = $venue;
		}
		if ( $tags ) {
			$args['tags'] = implode( ',', $tags );
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Send the feed if this request is for it.
	 */
	public static function maybe_serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A public, read-only feed of public data.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		$team_ids = isset( $_GET['teams'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['teams'] ) ) ) ) ) : array();
		$venue    = isset( $_GET['venue'] ) ? sanitize_key( wp_unslash( $_GET['venue'] ) ) : 'all';
		$tags     = isset( $_GET['tags'] ) ? Chess_Army_Knife_Events_Display::tag_slugs( explode( ',', sanitize_text_field( wp_unslash( $_GET['tags'] ) ) ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$events = Chess_Army_Knife_Events::query(
			array(
				'after' => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'Y-m-d H:i:s' ) . ' UTC' ) - 30 * DAY_IN_SECONDS ), // A little history, so a subscriber keeps recent results.
				'tags'  => $tags,
				'teams' => $team_ids,
				'limit' => self::MAX_EVENTS,
			)
		);
		$events = Chess_Army_Knife_Events_Display::filter_by_venue( $events, $venue, $team_ids );

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="club-events.ics"' );
		header( 'Cache-Control: public, max-age=900' ); // Calendar apps poll; a quarter of an hour is fresh enough.

		echo self::build( $events, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), (string) wp_parse_url( home_url(), PHP_URL_HOST ), time() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, escaped by escape_text(); not HTML.
		exit;
	}

	/**
	 * Build a calendar.
	 *
	 * @param array[] $events Event data (see Chess_Army_Knife_Events::data()).
	 * @param string  $name   Calendar name.
	 * @param string  $host   Site host, for event ids.
	 * @param int     $now    Unix time, for the stamp on each event.
	 * @return string
	 */
	public static function build( array $events, $name, $host, $now ) {
		/**
		 * Filter how long a fixture with no end time is taken to last, in seconds.
		 *
		 * @param int $seconds Default three hours.
		 */
		$default_length = (int) apply_filters( 'Chess_Army_Knife_event_feed_length', 3 * HOUR_IN_SECONDS );

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Chess Army Knife//Club events//EN',
			'CALSCALE:GREGORIAN',
			'X-WR-CALNAME:' . self::escape_text( $name ),
		);

		foreach ( $events as $event ) {
			if ( null === $event['start_ts'] ) {
				continue;
			}
			$end_ts = '' !== $event['end'] ? Chess_Army_Knife_Events::to_timestamp( $event['end'] ) : null;
			$end_ts = null !== $end_ts && $end_ts > $event['start_ts'] ? $end_ts : $event['start_ts'] + $default_length;

			$description = array();
			foreach ( $event['leagues'] as $league ) {
				$description[] = $league['event'];
			}
			foreach ( $event['tournaments'] as $tournament ) {
				$description[] = $tournament['name'];
			}
			$team_label = Chess_Army_Knife_Events_Display::team_label( $event );
			if ( '' !== $team_label ) {
				array_unshift( $description, $team_label );
			}

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $event['id'] . '@' . $host;
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', $now );
			$lines[] = 'DTSTART:' . gmdate( 'Ymd\THis\Z', $event['start_ts'] );
			$lines[] = 'DTEND:' . gmdate( 'Ymd\THis\Z', $end_ts );
			$lines[] = 'SUMMARY:' . self::escape_text( $event['title'] );
			if ( '' !== $event['location'] ) {
				$lines[] = 'LOCATION:' . self::escape_text( $event['location'] );
			}
			if ( $description ) {
				$lines[] = 'DESCRIPTION:' . self::escape_text( implode( "\n", $description ) );
			}
			$lines[] = 'URL:' . $event['url'];
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * Escape text for an iCalendar value.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function escape_text( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\,', '\n' ), $text );
	}

	/**
	 * Fold a line to the 75 bytes the format allows, without splitting a character.
	 *
	 * @param string $line Line.
	 * @return string Lines joined with a CRLF and a space.
	 */
	public static function fold( $line ) {
		$out    = array();
		$limit  = 75;
		$length = strlen( $line );

		while ( $length > $limit ) {
			$part   = mb_strcut( $line, 0, $limit, 'UTF-8' );
			$out[]  = $part;
			$line   = mb_strcut( $line, strlen( $part ), null, 'UTF-8' );
			$length = strlen( $line );
			$limit  = 74; // A continuation line starts with a space.
		}
		$out[] = $line;

		return implode( "\r\n ", $out );
	}
}

Chess_Army_Knife_Events_Feed::init();
