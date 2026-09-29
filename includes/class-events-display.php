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
}
