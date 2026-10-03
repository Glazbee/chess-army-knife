<?php
/**
 * Server-side render for the ECF Featured Player block.
 *
 * Shows a player's name, optional photo, current ECF rating and club (when
 * an ECF code is given), a free-text blurb on why they're featured, and
 * links to their chess.com and/or Lichess profiles. A player without an
 * ECF code can still be featured by display name alone.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its settings override this block's own.
$attributes = Chess_Army_Knife_Templates::apply( 'featured-player', $attributes );

$player_code   = Chess_Army_Knife_ECF_Client::normalise_code( $attributes['playerCode'] ?? '' );
$name          = trim( (string) ( $attributes['playerName'] ?? '' ) );
$heading       = trim( (string) ( $attributes['heading'] ?? '' ) );
$blurb         = trim( (string) ( $attributes['blurb'] ?? '' ) );
$image_id      = (int) ( $attributes['imageId'] ?? 0 );
$image_url     = (string) ( $attributes['imageUrl'] ?? '' );
$rating_domain = Chess_Army_Knife_ECF_Client::normalise_domain( Chess_Army_Knife_Settings::resolve( 'default_domain', $attributes['domain'] ?? '', 'S' ) );
$show_rating   = ! isset( $attributes['showRating'] ) || (bool) $attributes['showRating'];
$show_club     = ! isset( $attributes['showClub'] ) || (bool) $attributes['showClub'];
$show_links    = ! isset( $attributes['showLinks'] ) || (bool) $attributes['showLinks'];

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'featured-player', $attributes );

if ( '' === $player_code && '' === $name ) {
	printf(
		'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
		wp_kses_post( $wrapper_attributes ),
		esc_html__( 'ECF Featured Player: choose a player or enter a display name in the block settings.', 'chess-army-knife' )
	);
	return;
}

/**
 * Reduce a pasted profile URL or "@name" to a bare username, or ''
 * if nothing usable remains.
 *
 * @param string $value Raw user input.
 * @return string
 */
$clean_username = function ( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	$value = rtrim( preg_replace( '/[?#].*$/', '', $value ), '/' );
	$parts = explode( '/', $value );
	$value = ltrim( end( $parts ), '@' );
	return preg_replace( '/[^A-Za-z0-9_-]/', '', $value );
};

$chess_com = $clean_username( $attributes['chessComUser'] ?? '' );
$lichess   = $clean_username( $attributes['lichessUser'] ?? '' );

// ECF details (best effort: the block still renders without them).
$rating       = '';
$club         = '';
$player_title = '';
$admin_keys   = array();

if ( '' !== $player_code ) {
	$player       = Chess_Army_Knife_ECF_Client::get_player_by_code( $player_code );
	$admin_keys[] = Chess_Army_Knife_ECF_Client::cache_key_player( $player_code );

	if ( ! is_wp_error( $player ) && is_array( $player ) ) {
		if ( '' === $name && ! empty( $player['full_name'] ) ) {
			// The ECF returns "Surname, Forename".
			$name = implode( ' ', array_reverse( array_map( 'trim', explode( ',', $player['full_name'], 2 ) ) ) );
		}
		$club         = isset( $player['club_name'] ) ? (string) $player['club_name'] : '';
		$player_title = isset( $player['title'] ) ? (string) $player['title'] : '';
	}

	if ( $show_rating ) {
		$rating_data  = Chess_Army_Knife_ECF_Client::get_rating( $player_code, $rating_domain );
		$admin_keys[] = Chess_Army_Knife_ECF_Client::cache_key_rating( $player_code, $rating_domain );
		if ( ! is_wp_error( $rating_data ) && is_array( $rating_data ) ) {
			$value = $rating_data['revised_rating'] ?? ( $rating_data['original_rating'] ?? '' );
			if ( '' !== $value && null !== $value ) {
				$rating = (string) (int) $value;
			}
		}
	}
}

if ( '' === $name ) {
	$name = $player_code;
}

$domain_labels = array(
	'S'  => __( 'Standard', 'chess-army-knife' ),
	'R'  => __( 'Rapid', 'chess-army-knife' ),
	'B'  => __( 'Blitz', 'chess-army-knife' ),
	'SW' => __( 'Online Standard', 'chess-army-knife' ),
	'RW' => __( 'Online Rapid', 'chess-army-knife' ),
	'BW' => __( 'Online Blitz', 'chess-army-knife' ),
);

$has_links = $show_links && ( '' !== $chess_com || '' !== $lichess );
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<div <?php echo wp_kses_post( $wrapper_attributes ); ?>>
	<?php if ( '' !== $heading ) : ?>
		<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-featured__heading', $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
	<?php endif; ?>

	<?php echo Chess_Army_Knife_Admin_Refresh::bar( $admin_keys, __( 'Player data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Admin_Refresh::bar(). ?>

	<div class="cak-featured">
		<?php if ( $image_id || '' !== $image_url ) : ?>
			<div class="cak-featured__photo">
				<?php
				if ( $image_id && wp_attachment_is_image( $image_id ) ) {
					// The photo is decorative next to the name, so it only has an alt if the media library has one.
					echo wp_get_attachment_image( $image_id, 'medium' );
				} else {
					printf( '<img src="%1$s" alt="" loading="lazy" />', esc_url( $image_url ) );
				}
				?>
			</div>
		<?php endif; ?>

		<div class="cak-featured__body">
			<?php echo Chess_Army_Knife_A11y::heading( '' !== $heading ? 1 : 0, 'cak-featured__name', trim( $player_title . ' ' . $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

			<?php if ( '' !== $rating || ( $show_club && '' !== $club ) ) : ?>
				<p class="cak-featured__meta">
					<?php if ( '' !== $rating ) : ?>
						<span class="cak-featured__rating"><?php echo esc_html( $rating ); ?></span>
						<?php echo esc_html( isset( $domain_labels[ $rating_domain ] ) ? $domain_labels[ $rating_domain ] : '' ); ?>
					<?php endif; ?>
					<?php
					if ( '' !== $rating && $show_club && '' !== $club ) :
						?>
						<span aria-hidden="true">·</span> <?php endif; ?>
					<?php if ( $show_club && '' !== $club ) : ?>
						<?php echo esc_html( $club ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $blurb ) : ?>
				<div class="cak-featured__blurb"><?php echo wp_kses_post( wpautop( esc_html( $blurb ) ) ); ?></div>
			<?php endif; ?>

			<?php if ( $has_links ) : ?>
				<div class="cak-featured__links">
					<?php if ( '' !== $chess_com ) : ?>
						<a class="cak-featured__link" href="<?php echo esc_url( 'https://www.chess.com/member/' . $chess_com ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'chess.com profile', 'chess-army-knife' ); ?> <?php echo Chess_Army_Knife_A11y::hidden( sprintf( /* translators: %s: player name */ __( 'for %s (opens in a new tab)', 'chess-army-knife' ), $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden(). ?></a>
					<?php endif; ?>
					<?php if ( '' !== $lichess ) : ?>
						<a class="cak-featured__link" href="<?php echo esc_url( 'https://lichess.org/@/' . $lichess ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Lichess profile', 'chess-army-knife' ); ?> <?php echo Chess_Army_Knife_A11y::hidden( sprintf( /* translators: %s: player name */ __( 'for %s (opens in a new tab)', 'chess-army-knife' ), $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden(). ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
