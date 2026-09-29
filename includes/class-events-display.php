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
	 * The location, tags and attached tournaments/leagues of an event.
	 *
	 * @param array $event   Event data.
	 * @param array $options show_location, show_tags and show_links flags.
	 * @return string Escaped HTML, or '' if there is nothing to show.
	 */
	public static function details_html( array $event, array $options ) {
		$html = '';

		if ( ! empty( $options['show_location'] ) && '' !== $event['location'] ) {
			$html .= '<p class="cak-event__location">' . esc_html( $event['location'] ) . '</p>';
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
	 * The month grid: a table of weeks with each day's events.
	 *
	 * @param int     $year    Year.
	 * @param int     $month   Month, 1-12.
	 * @param array[] $events  Events starting in the month (see Chess_Army_Knife_Events::data()).
	 * @param array   $options show_location flag (adds the location as a tooltip).
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

		$html = '<table class="cak-month__table"><caption class="screen-reader-text">' . esc_html( $label ) . '</caption><thead><tr>';
		for ( $i = 0; $i < 7; $i++ ) {
			// 2024-01-07 was a Sunday.
			$weekday = wp_date( 'D', gmmktime( 12, 0, 0, 1, 7 + ( ( $start_of_week + $i ) % 7 ), 2024 ), new DateTimeZone( 'UTC' ) );
			$html   .= '<th scope="col">' . esc_html( $weekday ) . '</th>';
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

				$html .= '<td class="' . esc_attr( $classes ) . '"><span class="cak-month__daynum">' . (int) $day . '</span>';
				if ( $day_events ) {
					$html .= '<ul class="cak-month__events">';
					foreach ( $day_events as $event ) {
						$tooltip = ! empty( $options['show_location'] ) && '' !== $event['location'] ? ' title="' . esc_attr( $event['location'] ) . '"' : '';
						$html   .= '<li class="cak-month__event"><span class="cak-month__time">' . esc_html( self::time_label( $event ) ) . '</span> '
							. '<a href="' . esc_url( $event['url'] ) . '"' . $tooltip . '>' . esc_html( $event['title'] ) . '</a></li>';
					}
					$html .= '</ul>';
				}
				$html .= '</td>';
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * The events of a month, ready for month_html().
	 *
	 * @param int      $year  Year.
	 * @param int      $month Month, 1-12.
	 * @param string[] $tags  Tag slugs.
	 * @return array[]
	 */
	public static function month_events( $year, $month, array $tags ) {
		return Chess_Army_Knife_Events::query(
			array(
				'after'  => sprintf( '%04d-%02d-01 00:00:00', $year, $month ),
				'before' => self::shift_month( $year, $month, 1 ) . '-01 00:00:00',
				'tags'   => $tags,
				'limit'  => 0,
			)
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
