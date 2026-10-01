<?php
/**
 * Server-side render for the Next Club Event block.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its settings override this block's own.
$attributes = Chess_Army_Knife_Templates::apply( 'next-club-event', $attributes );

$block_title   = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$empty_message = isset( $attributes['emptyMessage'] ) ? trim( (string) $attributes['emptyMessage'] ) : '';
$options       = Chess_Army_Knife_Events_Display::options( $attributes );
$events        = Chess_Army_Knife_Events::query(
	array(
		'tags'  => Chess_Army_Knife_Events_Display::tag_slugs( isset( $attributes['tags'] ) ? $attributes['tags'] : array() ),
		'limit' => 1,
	)
);

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'next-club-event', $attributes );
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-event__heading', '' !== $block_title ? $block_title : __( 'Next club event', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

	<?php if ( empty( $events ) ) : ?>
		<div class="chess-army-knife-empty"><?php echo esc_html( '' !== $empty_message ? $empty_message : __( 'No upcoming events.', 'chess-army-knife' ) ); ?></div>
	<?php else : ?>
		<?php $event = $events[0]; ?>
		<div class="cak-event">
			<p class="cak-event__when">
				<span class="cak-event__date"><?php echo esc_html( Chess_Army_Knife_Events_Display::date_label( $event ) ); ?></span>
				<span class="cak-event__time"><?php echo esc_html( Chess_Army_Knife_Events_Display::time_label( $event ) ); ?></span>
			</p>
			<p class="cak-event__title"><?php echo Chess_Army_Knife_Events_Display::title_html( $event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in title_html(). ?></p>
			<?php echo Chess_Army_Knife_Events_Display::details_html( $event, $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in details_html(). ?>
		</div>
	<?php endif; ?>
</div>
