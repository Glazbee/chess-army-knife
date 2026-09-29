<?php
/**
 * Server-side render for the Club Event Calendar block: upcoming events
 * listed under a heading for each date. Several events on one night are
 * listed together, in start-time order.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its settings override this block's own.
$attributes = Chess_Army_Knife_Templates::apply( 'club-event-calendar', $attributes );

$title         = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$empty_message = isset( $attributes['emptyMessage'] ) ? trim( (string) $attributes['emptyMessage'] ) : '';
$count         = isset( $attributes['count'] ) ? max( 1, min( 100, (int) $attributes['count'] ) ) : 10;
$options       = Chess_Army_Knife_Events_Display::options( $attributes );
$events        = Chess_Army_Knife_Events::query(
	array(
		'tags'  => Chess_Army_Knife_Events_Display::tag_slugs( isset( $attributes['tags'] ) ? $attributes['tags'] : array() ),
		'limit' => $count,
	)
);

// Group by calendar day, keeping the query's order.
$days = array();
foreach ( $events as $event ) {
	$days[ substr( $event['start'], 0, 10 ) ][] = $event;
}

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'club-event-calendar', $attributes );
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<p class="cak-event__heading"><?php echo esc_html( '' !== $title ? $title : __( 'Upcoming club events', 'chess-army-knife' ) ); ?></p>

	<?php if ( empty( $days ) ) : ?>
		<div class="chess-army-knife-empty"><?php echo esc_html( '' !== $empty_message ? $empty_message : __( 'No upcoming events.', 'chess-army-knife' ) ); ?></div>
	<?php else : ?>
		<?php foreach ( $days as $day_events ) : ?>
			<section class="cak-calendar__day">
				<h3 class="cak-calendar__date"><?php echo esc_html( Chess_Army_Knife_Events_Display::date_label( $day_events[0] ) ); ?></h3>
				<ul class="cak-calendar__events">
					<?php foreach ( $day_events as $event ) : ?>
						<li class="cak-event">
							<p class="cak-event__when"><span class="cak-event__time"><?php echo esc_html( Chess_Army_Knife_Events_Display::time_label( $event ) ); ?></span></p>
							<p class="cak-event__title"><a href="<?php echo esc_url( $event['url'] ); ?>"><?php echo esc_html( $event['title'] ); ?></a></p>
							<?php echo Chess_Army_Knife_Events_Display::details_html( $event, $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in details_html(). ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
