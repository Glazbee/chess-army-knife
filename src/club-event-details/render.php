<?php
/**
 * Server-side render for the Club Event Details block: when and where one event is. On a page that an
 * event has attached, it finds the event by itself.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$event_id = isset( $attributes['eventId'] ) ? (int) $attributes['eventId'] : 0;
if ( ! $event_id ) {
	$event_id = Chess_Army_Knife_Events::event_for_page( (int) get_the_ID() );
}
$event = Chess_Army_Knife_Events::details( $event_id );

if ( ! $event ) {
	// A visitor sees nothing; someone editing is told why.
	if ( current_user_can( 'edit_posts' ) ) {
		printf(
			'<div %1$s><div class="chess-army-knife-empty">%2$s</div></div>',
			wp_kses_post( get_block_wrapper_attributes() ),
			esc_html__( 'Club Event Details: no event is attached to this page. Attach this page to an event under Club Events, or choose an event in the block settings.', 'chess-army-knife' )
		);
	}
	return;
}

$options = Chess_Army_Knife_Events_Display::options( $attributes );
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<p class="cak-event__when">
		<span class="cak-event__date"><?php echo esc_html( Chess_Army_Knife_Events_Display::date_label( $event ) ); ?></span>
		<span class="cak-event__time"><?php echo esc_html( Chess_Army_Knife_Events_Display::time_label( $event ) ); ?></span>
	</p>
	<?php echo Chess_Army_Knife_Events_Display::details_html( $event, $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in details_html(). ?>
</div>
