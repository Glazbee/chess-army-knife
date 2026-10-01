<?php
/**
 * Server-side render for the Club Event Calendar block. Two layouts:
 * an agenda of upcoming events under a heading for each date (several
 * events on one night are listed together, in start-time order), or a
 * month grid that view.js moves between months.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its settings override this block's own.
$attributes = Chess_Army_Knife_Templates::apply( 'club-event-calendar', $attributes );

$block_title   = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$empty_message = isset( $attributes['emptyMessage'] ) ? trim( (string) $attributes['emptyMessage'] ) : '';
$count         = isset( $attributes['count'] ) ? max( 1, min( 100, (int) $attributes['count'] ) ) : 10;
$options       = Chess_Army_Knife_Events_Display::options( $attributes );
$tag_slugs     = Chess_Army_Knife_Events_Display::tag_slugs( isset( $attributes['tags'] ) ? $attributes['tags'] : array() );
$team_ids      = isset( $attributes['teamIds'] ) ? array_values( array_filter( array_map( 'absint', (array) $attributes['teamIds'] ) ) ) : array();
$venue         = isset( $attributes['venue'] ) && in_array( $attributes['venue'], array( 'home', 'away' ), true ) ? $attributes['venue'] : 'all';
$subscribe_url = ! isset( $attributes['showSubscribe'] ) || $attributes['showSubscribe'] ? Chess_Army_Knife_Events_Feed::url( $team_ids, $venue, $tag_slugs ) : '';
$layout        = isset( $attributes['layout'] ) && 'month' === $attributes['layout'] ? 'month' : 'agenda';

if ( 'month' === $layout ) {
	list( $event_year, $month ) = Chess_Army_Knife_Events_Display::parse_month( current_time( 'Y-m' ) );

	$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'club-event-calendar', $attributes );
	?>
	<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
	<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
		<?php if ( '' !== $block_title ) : ?>
			<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-event__heading', $block_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
		<?php endif; ?>
		<div
			class="cak-month"
			data-cak-month
			data-endpoint="<?php echo esc_url( rest_url( Chess_Army_Knife_Events_REST::NAMESPACE_V1 . '/events-month' ) ); ?>"
			data-month="<?php echo esc_attr( sprintf( '%04d-%02d', $event_year, $month ) ); ?>"
			data-tags="<?php echo esc_attr( implode( ',', $tag_slugs ) ); ?>"
			data-location="<?php echo $options['show_location'] ? '1' : '0'; ?>"
			data-teams="<?php echo esc_attr( implode( ',', $team_ids ) ); ?>"
			data-venue="<?php echo esc_attr( $venue ); ?>"
			data-show-teams="<?php echo $options['show_teams'] ? '1' : '0'; ?>"
			data-msg-loading="<?php esc_attr_e( 'Loading…', 'chess-army-knife' ); ?>"
			data-msg-error="<?php esc_attr_e( 'That month could not be loaded. The month shown has not changed.', 'chess-army-knife' ); ?>"
		>
			<div class="cak-month__nav">
				<button type="button" class="cak-month__prev" hidden aria-label="<?php esc_attr_e( 'Previous month', 'chess-army-knife' ); ?>"><span aria-hidden="true">‹</span></button>
				<span class="cak-month__label" role="status" aria-live="polite" aria-atomic="true"><?php echo esc_html( Chess_Army_Knife_Events_Display::month_label( $event_year, $month ) ); ?></span>
				<button type="button" class="cak-month__next" hidden aria-label="<?php esc_attr_e( 'Next month', 'chess-army-knife' ); ?>"><span aria-hidden="true">›</span></button>
			</div>
			<p class="cak-month__message" role="status" aria-live="polite"></p>
			<div class="cak-month__grid">
				<?php echo Chess_Army_Knife_Events_Display::month_html( $event_year, $month, Chess_Army_Knife_Events_Display::month_events( $event_year, $month, $tag_slugs, $team_ids, $venue ), $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in month_html(). ?>
			</div>
		</div>
		<?php if ( '' !== $subscribe_url ) : ?>
			<p class="cak-event__subscribe"><a href="<?php echo esc_url( $subscribe_url ); ?>"><?php esc_html_e( 'Subscribe to this calendar (.ics)', 'chess-army-knife' ); ?></a></p>
		<?php endif; ?>
	</div>
	<?php
	return;
}

// A home or away filter is applied after the query, so the count is of the events that remain.
$events = Chess_Army_Knife_Events::query(
	array(
		'tags'  => $tag_slugs,
		'teams' => $team_ids,
		'limit' => 'all' === $venue ? $count : 0,
	)
);
$events = array_slice( Chess_Army_Knife_Events_Display::filter_by_venue( $events, $venue, $team_ids ), 0, $count );

// Group by calendar day, keeping the query's order.
$days = array();
foreach ( $events as $event ) {
	$days[ substr( $event['start'], 0, 10 ) ][] = $event;
}

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'club-event-calendar', $attributes );
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-event__heading', '' !== $block_title ? $block_title : __( 'Upcoming club events', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php if ( empty( $days ) ) : ?>
		<div class="chess-army-knife-empty"><?php echo esc_html( '' !== $empty_message ? $empty_message : __( 'No upcoming events.', 'chess-army-knife' ) ); ?></div>
	<?php else : ?>
		<?php foreach ( $days as $day_events ) : ?>
			<section class="cak-calendar__day">
				<?php echo Chess_Army_Knife_A11y::heading( 1, 'cak-calendar__date', Chess_Army_Knife_Events_Display::date_label( $day_events[0] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
				<ul class="cak-calendar__events">
					<?php foreach ( $day_events as $event ) : ?>
						<li class="cak-event"<?php echo $options['show_teams'] && '' !== Chess_Army_Knife_Events_Display::team_label( $event ) ? Chess_Army_Knife_Events_Display::colour_style( $event ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in colour_style(). ?>>
							<p class="cak-event__when"><span class="cak-event__time"><?php echo esc_html( Chess_Army_Knife_Events_Display::time_label( $event ) ); ?></span></p>
							<p class="cak-event__title"><?php echo Chess_Army_Knife_Events_Display::title_html( $event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in title_html(). ?></p>
							<?php echo Chess_Army_Knife_Events_Display::details_html( $event, $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in details_html(). ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
	<?php endif; ?>
	<?php if ( '' !== $subscribe_url ) : ?>
		<p class="cak-event__subscribe"><a href="<?php echo esc_url( $subscribe_url ); ?>"><?php esc_html_e( 'Subscribe to this calendar (.ics)', 'chess-army-knife' ); ?></a></p>
	<?php endif; ?>
</div>
