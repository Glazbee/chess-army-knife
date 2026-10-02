<?php
/**
 * Shared markup for the club event blocks: date and time labels, and an
 * event's location, tags and attached tournaments and leagues.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Events_Display {

	/**
	 * Tag slugs from a block's "tags" attribute, cleaned.
	 *
	 * @param mixed $tags Attribute value.
	 * @return string[]
	 */
	public static function tag_slugs( $tags ) {
		return array_values( array_filter( array_map( 'sanitize_title', array_map( 'strval', (array) $tags ) ) ) );
	}

	/**
	 * The event's date in the site's date format.
	 *
	 * @param array $event Event data (see Chess_Army_Knife_Events::data()).
	 * @return string
	 */
	public static function date_label( array $event ) {
		return null === $event['start_ts'] ? '' : wp_date( get_option( 'date_format' ), $event['start_ts'] );
	}

	/**
	 * The event's time: "19:30", or "19:30 – 22:00" with an end time.
	 *
	 * @param array $event Event data.
	 * @return string
	 */
	public static function time_label( array $event ) {
		if ( null === $event['start_ts'] ) {
			return '';
		}

		$format = get_option( 'time_format' );
		$label  = wp_date( $format, $event['start_ts'] );
		$end_ts = '' !== $event['end'] ? Chess_Army_Knife_Events::to_timestamp( $event['end'] ) : null;

		if ( null !== $end_ts ) {
			$label .= ' – ' . wp_date( $format, $end_ts );
		}

		return $label;
	}

	/**
	 * Just the start time, as it shows in a calendar bubble: "7:30 pm".
	 *
	 * @param array $event Event data.
	 * @return string
	 */
	public static function start_time_label( array $event ) {
		return null === $event['start_ts'] ? '' : wp_date( get_option( 'time_format' ), $event['start_ts'] );
	}

	/**
	 * Whether a fixture is home, away or between two of the club's own teams.
	 *
	 * @param array $event Event data.
	 * @return string 'home', 'away', 'derby', or '' for an event that is not a team fixture.
	 */
	public static function side_of_event( array $event ) {
		$sides = array_unique( array_column( $event['teams'], 'side' ) );
		if ( in_array( 'home', $sides, true ) && in_array( 'away', $sides, true ) ) {
			return 'derby';
		}
		if ( in_array( 'home', $sides, true ) ) {
			return 'home';
		}
		return in_array( 'away', $sides, true ) ? 'away' : '';
	}

	/**
	 * "Club A (home)": the team or teams in a fixture with their side.
	 *
	 * @param array $event Event data.
	 * @return string Plain text, or '' for an event with no team.
	 */
	public static function team_label( array $event ) {
		if ( ! $event['teams'] ) {
			return '';
		}
		$sides = array(
			'home' => __( 'home', 'chess-army-knife' ),
			'away' => __( 'away', 'chess-army-knife' ),
		);
		$parts = array();
		foreach ( $event['teams'] as $team ) {
			/* translators: 1: team name, 2: home or away */
			$parts[] = isset( $sides[ $team['side'] ] ) ? sprintf( __( '%1$s (%2$s)', 'chess-army-knife' ), $team['name'], $sides[ $team['side'] ] ) : $team['name'];
		}
		return implode( ', ', $parts );
	}

	/**
	 * Keep only home or away fixtures. A fixture counts as home if any of the
	 * chosen teams (or, with none chosen, any team in it) is at home.
	 *
	 * @param array[] $events   Events.
	 * @param string  $venue    'home', 'away'; anything else keeps everything.
	 * @param int[]   $team_ids Teams chosen on the block, if any.
	 * @return array[]
	 */
	public static function filter_by_venue( array $events, $venue, array $team_ids = array() ) {
		if ( ! in_array( $venue, array( 'home', 'away' ), true ) ) {
			return $events;
		}
		return array_values(
			array_filter(
				$events,
				function ( $event ) use ( $venue, $team_ids ) {
					foreach ( $event['teams'] as $team ) {
						if ( $venue === $team['side'] && ( ! $team_ids || in_array( $team['id'], $team_ids, true ) ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * The inline style that carries an event's colour to its CSS: its own colour or, if
	 * it has none, its team's (which is only used beside the team's name).
	 *
	 * @param array $event     Event data.
	 * @param bool  $use_teams Whether a team's colour may be used.
	 * @return string ' style="..."' with a leading space, or '' if there is no colour.
	 */
	public static function colour_style( array $event, $use_teams = true ) {
		$colour = isset( $event['colour'] ) ? sanitize_hex_color( $event['colour'] ) : '';

		if ( ! $colour && $use_teams && '' !== self::team_label( $event ) ) {
			foreach ( $event['teams'] as $team ) {
				$colour = sanitize_hex_color( $team['colour'] );
				if ( $colour ) {
					break;
				}
			}
		}

		// Then the colour of its first tag, which the key explains.
		if ( ! $colour ) {
			foreach ( $event['tags'] as $tag ) {
				$colour = isset( $tag['colour'] ) ? sanitize_hex_color( $tag['colour'] ) : '';
				if ( $colour ) {
					break;
				}
			}
		}

		return $colour ? ' style="--cak-event-colour:' . esc_attr( $colour ) . '"' : '';
	}

	/**
	 * The key to the colours: each tag in use, with its colour.
	 *
	 * @param string[] $tag_slugs Only these tags; none means every tag in use.
	 * @return string Escaped HTML, or '' if no tag is in use.
	 */
	public static function key_html( array $tag_slugs = array() ) {
		$args = array(
			'taxonomy'   => Chess_Army_Knife_Events::TAXONOMY,
			'hide_empty' => true,
		);
		if ( $tag_slugs ) {
			$args['slug'] = $tag_slugs;
		}

		$terms = get_terms( $args );
		if ( ! is_array( $terms ) || ! $terms ) {
			return '';
		}

		$html = '<ul class="cak-key" aria-label="' . esc_attr__( 'Key to event colours', 'chess-army-knife' ) . '">';
		foreach ( $terms as $term ) {
			$html .= '<li class="cak-key__item" style="--cak-event-colour:' . esc_attr( Chess_Army_Knife_Events::tag_colour( $term ) ) . '"><span class="cak-bubble__dot" aria-hidden="true"></span> ' . esc_html( $term->name ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * An event's title, linked to its page if it has one.
	 *
	 * @param array $event Event data.
	 * @return string Escaped HTML.
	 */
	public static function title_html( array $event ) {
		return '' !== $event['url'] ? '<a href="' . esc_url( $event['url'] ) . '">' . esc_html( $event['title'] ) . '</a>' : esc_html( $event['title'] );
	}

	/**
	 * What to say about an event that is cancelled or moved.
	 *
	 * @param array $event Event data.
	 * @return string Plain text such as "Cancelled: no hall this week", or '' if it is going ahead.
	 */
	public static function status_text( array $event ) {
		$status = isset( $event['status'] ) ? (string) $event['status'] : '';
		$labels = Chess_Army_Knife_Events::status_labels();
		if ( ! isset( $labels[ $status ] ) ) {
			return '';
		}

		$note = isset( $event['status_note'] ) ? trim( (string) $event['status_note'] ) : '';
		return '' !== $note ? $labels[ $status ] . ': ' . $note : $labels[ $status ];
	}

	/**
	 * A link that downloads one event for the visitor's own calendar.
	 *
	 * @param array $event Event data.
	 * @return string Escaped HTML, or '' for an event that is cancelled.
	 */
	public static function calendar_link_html( array $event ) {
		if ( empty( $event['id'] ) || ( isset( $event['status'] ) && 'cancelled' === $event['status'] ) ) {
			return '';
		}

		/* translators: %s: event title */
		$label = sprintf( __( 'Add %s to my calendar', 'chess-army-knife' ), $event['title'] );
		return '<p class="cak-event__calendar"><a href="' . esc_url( Chess_Army_Knife_Events_Feed::event_url( $event ) ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html__( 'Add to my calendar', 'chess-army-knife' ) . '</a></p>';
	}

	/**
	 * An event's venue: its name, with links to it on a map and its what3words address.
	 *
	 * @param array $event Event data.
	 * @return string Escaped HTML, or '' if the event has no venue.
	 */
	public static function location_html( array $event ) {
		$map = isset( $event['map_url'] ) ? (string) $event['map_url'] : '';
		$w3w = isset( $event['what3words'] ) ? (string) $event['what3words'] : '';
		if ( '' === $event['location'] && '' === $map && '' === $w3w ) {
			return '';
		}

		$html = '<p class="cak-event__location">' . esc_html( $event['location'] );
		if ( '' !== $map ) {
			/* translators: %s: venue name */
			$label = '' !== $event['location'] ? sprintf( __( 'Map of %s', 'chess-army-knife' ), $event['location'] ) : __( 'Map of the venue', 'chess-army-knife' );
			$html .= ( '' !== $event['location'] ? ' ' : '' ) . '<a class="cak-event__map" href="' . esc_url( $map ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html__( 'Map', 'chess-army-knife' ) . '</a>';
		}
		if ( '' !== $w3w ) {
			$html .= ( '' !== $event['location'] || '' !== $map ? ' ' : '' ) . '<a class="cak-event__w3w" href="' . esc_url( 'https://what3words.com/' . rawurlencode( $w3w ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: three words */ __( 'what3words address %s', 'chess-army-knife' ), $w3w ) ) . '">///' . esc_html( $w3w ) . '</a>';
		}
		return $html . '</p>';
	}

	/**
	 * The location, tags and attached tournaments/leagues of an event.
	 *
	 * @param array $event   Event data.
	 * @param array $options show_location, show_tags and show_links flags.
	 * @return string Escaped HTML, or '' if there is nothing to show.
	 */
	public static function details_html( array $event, array $options ) {
		$html = '';

		$status = self::status_text( $event );
		if ( '' !== $status ) {
			$html .= '<p class="cak-event__status">' . esc_html( $status ) . '</p>';
		}

		$team_label = self::team_label( $event );
		if ( ! empty( $options['show_teams'] ) && '' !== $team_label ) {
			$html .= '<p class="cak-event__team">' . esc_html( $team_label ) . '</p>';
		}

		if ( ! empty( $options['show_location'] ) ) {
			$html .= self::location_html( $event );
		}

		if ( ! empty( $options['show_links'] ) ) {
			$items = array();
			foreach ( $event['tournaments'] as $tournament ) {
				$items[] = '' !== $tournament['url']
					? '<a href="' . esc_url( $tournament['url'] ) . '">' . esc_html( $tournament['name'] ) . '</a>'
					: esc_html( $tournament['name'] );
			}
			foreach ( $event['leagues'] as $league ) {
				$items[] = esc_html( $league['event'] );
			}
			if ( $items ) {
				$html .= '<p class="cak-event__links">' . implode( ', ', $items ) . '</p>'; // Each item is escaped above.
			}
		}

		if ( ! empty( $options['show_links'] ) ) {
			$html .= self::calendar_link_html( $event );
		}

		if ( ! empty( $options['show_tags'] ) && $event['tags'] ) {
			$html .= '<p class="cak-event__tags">';
			foreach ( $event['tags'] as $tag ) {
				$html .= '<span class="cak-event__tag">' . esc_html( $tag['name'] ) . '</span> ';
			}
			$html .= '</p>';
		}

		return $html;
	}

	/**
	 * Display options from block attributes (all on unless switched off).
	 *
	 * @param array $attributes Block attributes.
	 * @return array
	 */
	public static function options( array $attributes ) {
		return array(
			'show_location' => ! isset( $attributes['showLocation'] ) || $attributes['showLocation'],
			'show_tags'     => ! isset( $attributes['showTags'] ) || $attributes['showTags'],
			'show_links'    => ! isset( $attributes['showLinks'] ) || $attributes['showLinks'],
			'show_teams'    => ! isset( $attributes['showTeams'] ) || $attributes['showTeams'],
		);
	}

	/**
	 * Read a "YYYY-MM" month.
	 *
	 * @param string $month Month text.
	 * @return int[]|null { year, month }, or null if it isn't a real month.
	 */
	public static function parse_month( $month ) {
		if ( ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', (string) $month, $m ) ) {
			return null;
		}

		return array( (int) $m[1], (int) $m[2] );
	}

	/**
	 * The month a number of months away from another.
	 *
	 * @param int $year   Year.
	 * @param int $month  Month, 1-12.
	 * @param int $offset Months to move (negative for earlier).
	 * @return string "YYYY-MM".
	 */
	public static function shift_month( $year, $month, $offset ) {
		$index = $year * 12 + ( $month - 1 ) + $offset;

		return sprintf( '%04d-%02d', intdiv( $index, 12 ), $index % 12 + 1 );
	}

	/**
	 * The weeks of a month for a calendar grid.
	 *
	 * @param int $year          Year.
	 * @param int $month         Month, 1-12.
	 * @param int $start_of_week First day of the week, 0 (Sunday) to 6 (Saturday).
	 * @return array[] Each week is seven entries: a day number, or null outside the month.
	 */
	public static function month_weeks( $year, $month, $start_of_week ) {
		$days   = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
		$offset = ( (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, 1, $year ) ) - $start_of_week + 7 ) % 7;

		$cells = array_merge( array_fill( 0, $offset, null ), range( 1, $days ) );
		$cells = array_pad( $cells, (int) ceil( count( $cells ) / 7 ) * 7, null );

		return array_chunk( $cells, 7 );
	}

	/**
	 * The month grid: a table of weeks with each day's events, and the same events as a
	 * list of days for small screens. The stylesheet shows one and hides the other, so
	 * a screen reader meets the events once.
	 *
	 * @param int     $year    Year.
	 * @param int     $month   Month, 1-12.
	 * @param array[] $events  Events starting in the month (see Chess_Army_Knife_Events::data()).
	 * @param array   $options show_location and show_teams flags.
	 * @return string Escaped HTML.
	 */
	public static function month_html( $year, $month, array $events, array $options ) {
		$start_of_week = (int) get_option( 'start_of_week', 1 ) % 7;
		$today         = current_time( 'Y-m-d' );

		$by_day = array();
		foreach ( $events as $event ) {
			$by_day[ substr( $event['start'], 0, 10 ) ][] = $event;
		}

		$label = self::month_label( $year, $month );

		$html = '<table class="cak-month__table"><caption class="cak-visually-hidden">' . esc_html( $label ) . '</caption><thead><tr>';
		for ( $i = 0; $i < 7; $i++ ) {
			// 2024-01-07 was a Sunday.
			$timestamp = gmmktime( 12, 0, 0, 1, 7 + ( ( $start_of_week + $i ) % 7 ), 2024 );
			$weekday   = wp_date( 'D', $timestamp, new DateTimeZone( 'UTC' ) );
			$full_day  = wp_date( 'l', $timestamp, new DateTimeZone( 'UTC' ) );
			$html     .= '<th scope="col"><abbr title="' . esc_attr( $full_day ) . '">' . esc_html( $weekday ) . '</abbr></th>';
		}
		$html .= '</tr></thead><tbody>';

		foreach ( self::month_weeks( $year, $month, $start_of_week ) as $week ) {
			$html .= '<tr>';
			foreach ( $week as $day ) {
				if ( null === $day ) {
					$html .= '<td class="cak-month__day is-outside"></td>';
					continue;
				}

				$date       = sprintf( '%04d-%02d-%02d', $year, $month, $day );
				$day_events = isset( $by_day[ $date ] ) ? $by_day[ $date ] : array();
				$classes    = 'cak-month__day' . ( $day_events ? ' has-events' : '' ) . ( $date === $today ? ' is-today' : '' );

				// The number is what is seen; a screen reader hears the whole date.
				$html .= '<td class="' . esc_attr( $classes ) . '"><span class="cak-month__daynum"><span aria-hidden="true">' . (int) $day . '</span>'
					. '<span class="cak-visually-hidden">' . esc_html( self::full_date( $year, $month, $day ) ) . '</span></span>';
				$html .= self::month_events_html( $day_events, $options );
				$html .= '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		// The same days, as a list, for a phone.
		$html .= '<ul class="cak-month__list">';
		foreach ( $by_day as $date => $day_events ) {
			$parts = explode( '-', $date );
			$tag   = Chess_Army_Knife_Headings::tag( 1 );
			$html .= '<li class="cak-month__list-day"><' . $tag . ' class="cak-month__list-date">' . esc_html( self::full_date( (int) $parts[0], (int) $parts[1], (int) $parts[2] ) ) . '</' . $tag . '>'
				. self::month_events_html( $day_events, $options ) . '</li>';
		}
		$html .= '</ul>';

		if ( ! $by_day ) {
			$html .= '<p class="cak-month__none">' . esc_html__( 'No events this month.', 'chess-army-knife' ) . '</p>';
		}

		return $html;
	}

	/**
	 * A day's events as a list of bubbles.
	 *
	 * @param array[] $day_events Events on the day.
	 * @param array   $options    show_location and show_teams flags.
	 * @return string Escaped HTML, or '' if there are no events.
	 */
	protected static function month_events_html( array $day_events, array $options ) {
		if ( ! $day_events ) {
			return '';
		}

		$html = '<ul class="cak-month__events">';
		foreach ( $day_events as $event ) {
			$html .= '<li class="cak-month__event"' . self::colour_style( $event, ! empty( $options['show_teams'] ) ) . '>' . self::bubble_html( $event, $options ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * One event as a bubble, like Google Calendar's: its start time and title on one line.
	 * Selecting it opens a panel with the rest. It is a details element, so it works
	 * without scripts and is announced as expandable; view.js only tidies it up.
	 *
	 * @param array $event   Event data.
	 * @param array $options show_location and show_teams flags.
	 * @return string Escaped HTML.
	 */
	public static function bubble_html( array $event, array $options ) {
		$when = trim( self::date_label( $event ) . ', ' . self::time_label( $event ), ' ,' );

		$status = self::status_text( $event );
		$label  = isset( $event['status'] ) && isset( Chess_Army_Knife_Events::status_labels()[ $event['status'] ] ) ? Chess_Army_Knife_Events::status_labels()[ $event['status'] ] : '';

		$html  = '<details class="cak-bubble' . ( '' !== $label ? ' cak-bubble--' . esc_attr( $event['status'] ) : '' ) . '"><summary class="cak-bubble__summary">';
		$html .= '<span class="cak-bubble__dot" aria-hidden="true"></span>';
		$html .= '<span class="cak-bubble__time">' . esc_html( self::start_time_label( $event ) ) . '</span> ';
		// The status is written out, so it is not only shown by a line through the title.
		$html .= '' !== $label ? '<span class="cak-bubble__status">' . esc_html( $label ) . '</span> ' : '';
		$html .= '<span class="cak-bubble__title">' . esc_html( $event['title'] ) . '</span>';
		$html .= '</summary><div class="cak-bubble__panel">';
		$html .= '<p class="cak-bubble__name">' . esc_html( $event['title'] ) . '</p>';
		$html .= '<p class="cak-bubble__when">' . esc_html( $when ) . '</p>';
		$html .= self::details_html(
			$event,
			array(
				'show_location' => ! empty( $options['show_location'] ),
				'show_teams'    => ! empty( $options['show_teams'] ),
				'show_links'    => true,
				'show_tags'     => true,
			)
		);
		if ( '' !== $event['url'] ) {
			$html .= '<p class="cak-bubble__more"><a href="' . esc_url( $event['url'] ) . '">' . esc_html__( 'More about this event', 'chess-army-knife' ) . '</a></p>';
		}
		return $html . '</div></details>';
	}

	/**
	 * A date written out in full, such as "Monday 5 October 2026".
	 *
	 * @param int $year  Year.
	 * @param int $month Month, 1-12.
	 * @param int $day   Day of the month.
	 * @return string
	 */
	public static function full_date( $year, $month, $day ) {
		return wp_date( 'l ' . get_option( 'date_format' ), gmmktime( 12, 0, 0, $month, $day, $year ), new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The events of a month, ready for month_html().
	 *
	 * @param int      $year  Year.
	 * @param int      $month Month, 1-12.
	 * @param string[] $tags     Tag slugs.
	 * @param int[]    $team_ids Team ids; none means every event.
	 * @param string   $venue    'all', 'home' or 'away'.
	 * @return array[]
	 */
	public static function month_events( $year, $month, array $tags, array $team_ids = array(), $venue = 'all' ) {
		return self::filter_by_venue(
			Chess_Army_Knife_Events::query(
				array(
					'after'  => sprintf( '%04d-%02d-01 00:00:00', $year, $month ),
					'before' => self::shift_month( $year, $month, 1 ) . '-01 00:00:00',
					'tags'   => $tags,
					'teams'  => $team_ids,
					'limit'  => 0,
				)
			),
			$venue,
			$team_ids
		);
	}

	/**
	 * The month's label, e.g. "October 2026".
	 *
	 * @param int $year  Year.
	 * @param int $month Month, 1-12.
	 * @return string
	 */
	public static function month_label( $year, $month ) {
		return wp_date( 'F Y', gmmktime( 12, 0, 0, $month, 1, $year ), new DateTimeZone( 'UTC' ) );
	}
}
