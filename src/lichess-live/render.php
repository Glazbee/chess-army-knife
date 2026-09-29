<?php
/**
 * Server-side render for the Lichess Live Games block.
 *
 * Renders the games in progress right now (from a ~30 second server-side
 * cache shared by all visitors); view.js then refreshes them from the
 * signed REST route so the page stays current without a reload.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its settings override this block's own.
$attributes = Chess_Army_Knife_Templates::apply( 'lichess-live', $attributes );

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'lichess-live', $attributes );

$usernames_raw = Chess_Army_Knife_Settings::resolve( 'lichess_usernames', isset( $attributes['usernames'] ) ? trim( (string) $attributes['usernames'] ) : '' );
$usernames     = Lichess_Client::parse_usernames( $usernames_raw );

if ( empty( $usernames ) ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'Lichess Live Games: add at least one Lichess username in the block settings.', 'chess-army-knife' )
	);
	return;
}

$title            = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$show_board       = ! isset( $attributes['showBoard'] ) || (bool) $attributes['showBoard'];
$auto_advance     = ! empty( $attributes['autoAdvance'] );
$interval_seconds = isset( $attributes['intervalSeconds'] ) ? max( 3, (int) $attributes['intervalSeconds'] ) : 8;
$empty_message    = isset( $attributes['emptyMessage'] ) ? trim( (string) $attributes['emptyMessage'] ) : '';
if ( '' === $empty_message ) {
	$empty_message = __( 'Nobody is playing on Lichess right now.', 'chess-army-knife' );
}

$games = Lichess_Client::get_live_games( $usernames );
$error = null;
if ( is_wp_error( $games ) ) {
	$error = $games;
	$games = array();
}
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<?php if ( '' !== $title ) : ?>
		<p class="ecf-lichess__title"><?php echo esc_html( $title ); ?></p>
	<?php endif; ?>

	<?php if ( $error && current_user_can( 'edit_posts' ) ) : ?>
		<div class="chess-army-knife-notice"><?php echo esc_html( $error->get_error_message() ); ?></div>
	<?php endif; ?>

	<div
		class="ecf-lichess"
		data-ecf-lichess
		data-endpoint="<?php echo esc_url( rest_url( Chess_Army_Knife_Lichess_Live::NAMESPACE_V1 . '/lichess-live' ) ); ?>"
		data-users="<?php echo esc_attr( implode( ',', $usernames ) ); ?>"
		data-board="<?php echo $show_board ? '1' : '0'; ?>"
		data-sig="<?php echo esc_attr( Chess_Army_Knife_Lichess_Live::sign( $usernames, $show_board ) ); ?>"
		data-position-endpoint="<?php echo esc_url( rest_url( Chess_Army_Knife_Lichess_Live::NAMESPACE_V1 . '/lichess-position' ) ); ?>"
		data-poll="<?php echo (int) ( Lichess_Client::cache_seconds() + 15 ); ?>"
		data-position-poll="<?php echo (int) ( Lichess_Client::position_cache_seconds() + 1 ); ?>"
		data-auto-advance="<?php echo $auto_advance ? '1' : '0'; ?>"
		data-interval="<?php echo (int) $interval_seconds; ?>"
	>
		<p class="ecf-lichess__empty" <?php echo $games ? 'hidden' : ''; ?>><?php echo esc_html( $empty_message ); ?></p>

		<div class="ecf-lichess__carousel" <?php echo $games ? '' : 'hidden'; ?>>
			<button type="button" class="ecf-lichess__nav ecf-lichess__prev" aria-label="<?php esc_attr_e( 'Previous game', 'chess-army-knife' ); ?>">‹</button>
			<div class="ecf-lichess__track" tabindex="0" aria-live="polite">
				<?php
				foreach ( $games as $game ) {
					echo Chess_Army_Knife_Lichess_Live::slide_html( $game, $show_board ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in slide_html().
				}
				?>
			</div>
			<button type="button" class="ecf-lichess__nav ecf-lichess__next" aria-label="<?php esc_attr_e( 'Next game', 'chess-army-knife' ); ?>">›</button>
		</div>
	</div>
</div>
