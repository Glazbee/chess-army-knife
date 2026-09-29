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
$layout        = isset( $attributes['layout'] ) && 'month' === $attributes['layout'] ? 'month' : 'agenda';

if ( 'month' === $layout ) {
	list( $event_year, $month ) = Chess_Army_Knife_Events_Display::parse_month( current_time( 'Y-m' ) );

	$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'club-event-calendar', $attributes );
	?>
	<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
	<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
		<?php if ( '' !== $block_title ) : ?>
			<p class="cak-event__heading"><?php echo esc_html( $block_title ); ?></p>
		<?php endif; ?>
		<div
			class="cak-month"
			data-cak-month
			data-endpoint="<?php echo esc_url( rest_url( Chess_Army_Knife_Events_REST::NAMESPACE_V1 . '/events-month' ) ); ?>"
			data-month="<?php echo esc_attr( sprintf( '%04d-%02d', $event_year, $month ) ); ?>"
			data-tags="<?php echo esc_attr( implode( ',', $tag_slugs ) ); ?>"
			data-location="<?php echo $options['show_location'] ? '1' : '0'; ?>"
		>
			<div class="cak-month__nav">
				<button type="button" class="cak-month__prev" hidden aria-label="<?php esc_attr_e( 'Previous month', 'chess-army-knife' ); ?>">‹</button>
				<span class="cak-month__label" aria-live="polite"><?php echo esc_html( Chess_Army_Knife_Events_Display::month_label( $event_year, $month ) ); ?></span>
				<button type="button" class="cak-month__next" hidden aria-label="<?php esc_attr_e( 'Next month', 'chess-army-knife' ); ?>">›</button>
			</div>
			<div class="cak-month__grid">
				<?php echo Chess_Army_Knife_Events_Display::month_html( $event_year, $month, Chess_Army_Knife_Events_Display::month_events( $event_year, $month, $tag_slugs ), $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in month_html(). ?>
			</div>
		</div>
	</div>
	<?php
	return;
}

$events = Chess_Army_Knife_Events::query(
	array(
		'tags'  => $tag_slugs,
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
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<p class="cak-event__heading"><?php echo esc_html( '' !== $block_title ? $block_title : __( 'Upcoming club events', 'chess-army-knife' ) ); ?></p>

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
