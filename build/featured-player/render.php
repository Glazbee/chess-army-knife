<?php
/**
 * Server-side render for the Featured Player block.
 *
 * Shows a current club member's name, optional photo, current ECF rating and club, a free-text blurb on why
 * they're featured, and links to their chess.com and/or Lichess profiles. Only current members can be
 * featured. The block can instead rotate: every hour, day, week or month it moves on to another current
 * member whose rating has risen over the period, and shows that member's own blurb (set on their record).
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Apply the chosen template (if any): its settings override this block's own.
$attributes = Chess_Army_Knife_Templates::apply( 'featured-player', $attributes );

$name_style    = Chess_Army_Knife_Names::style_for( $attributes );
$player_code   = Chess_Army_Knife_ECF_Client::normalise_code( $attributes['playerCode'] ?? '' );
$name          = '';
$heading       = trim( (string) ( $attributes['heading'] ?? '' ) );
$subtitle      = trim( (string) ( $attributes['subtitle'] ?? '' ) );
$blurb         = trim( (string) ( $attributes['blurb'] ?? '' ) );
$image_id      = (int) ( $attributes['imageId'] ?? 0 );
$image_url     = (string) ( $attributes['imageUrl'] ?? '' );
$rating_domain = Chess_Army_Knife_ECF_Client::normalise_domain( Chess_Army_Knife_Settings::resolve( 'default_domain', $attributes['domain'] ?? '', 'S' ) );
$show_rating   = ! isset( $attributes['showRating'] ) || (bool) $attributes['showRating'];
$show_links    = ! isset( $attributes['showLinks'] ) || (bool) $attributes['showLinks'];
$show_change   = ! isset( $attributes['showChange'] ) || (bool) $attributes['showChange'];
$rotation      = Chess_Army_Knife_Rotating_Member::clean_rotation( $attributes['rotation'] ?? '' );
$days_back     = max( 1, (int) Chess_Army_Knife_Settings::resolve( 'default_days_back', empty( $attributes['daysBack'] ) ? '' : $attributes['daysBack'], 60 ) );
$chess_com     = '';
$lichess       = '';

$wrapper_attributes = Chess_Army_Knife_Templates::wrapper_attributes( 'featured-player', $attributes );

if ( Chess_Army_Knife_Rotating_Member::NONE !== $rotation ) {
	// Whose turn it is among the members whose rating has risen; a chosen photo, blurb and links belong to one person, so they are not used.
	$chosen = Chess_Army_Knife_Rotating_Member::pick(
		Chess_Army_Knife_Rotating_Member::growing_members( 'S', $days_back, 2 ),
		$rotation,
		Chess_Army_Knife_Rotating_Member::salt( 'featured|S' ),
		Chess_Army_Knife_Rotating_Member::local_time()
	);
	if ( ! $chosen ) {
		printf(
			'<div %1$s><div class="chess-army-knife-empty">%2$s</div></div>',
			wp_kses_post( $wrapper_attributes ),
			esc_html__( 'No current member has a rising rating in this period yet.', 'chess-army-knife' )
		);
		return;
	}
	$player_code = Chess_Army_Knife_ECF_Client::normalise_code( $chosen['code'] );
	$name        = Chess_Army_Knife_Names::format( $chosen['name'], $name_style, $chosen['nickname'] );
	$image_id    = 0;
	$image_url   = '';
	$show_links  = false;
	// The member's own blurb, read fresh so an edit shows at once.
	$chosen_row = Chess_Army_Knife_Membership_Store::find_by_ecf_code( $chosen['code'] );
	$blurb      = $chosen_row ? $chosen_row['blurb'] : '';
} else {
	// Only a current member can be featured; the name comes from their record.
	$member = '' === $player_code ? null : Chess_Army_Knife_Rotating_Member::current_member_by_code( $player_code );
	if ( ! $member ) {
		printf(
			'<div %1$s><div class="chess-army-knife-notice">%2$s</div></div>',
			wp_kses_post( $wrapper_attributes ),
			esc_html__( 'Featured Player: choose a current club member in the block settings, or turn on rotation.', 'chess-army-knife' )
		);
		return;
	}
	$name = Chess_Army_Knife_Names::format( $member['name'], $name_style, $member['nickname'] );
	// The block's own blurb comes first, then the member's.
	if ( '' === $blurb ) {
		$blurb = $member['blurb'];
	}
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

if ( Chess_Army_Knife_Rotating_Member::NONE === $rotation ) {
	$chess_com = $clean_username( $attributes['chessComUser'] ?? '' );
	$lichess   = $clean_username( $attributes['lichessUser'] ?? '' );
}

// ECF details (best effort: the block still renders without them).
$rating       = '';
$change       = null;
$player_title = '';
$admin_keys   = array();

if ( '' !== $player_code ) {
	$player       = Chess_Army_Knife_ECF_Client::get_player_by_code( $player_code );
	$admin_keys[] = Chess_Army_Knife_ECF_Client::cache_key_player( $player_code );

	if ( ! is_wp_error( $player ) && is_array( $player ) ) {
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

	// How the rating moved over the period, from the games the ECF has recorded.
	if ( $show_rating && $show_change ) {
		$admin_keys[] = Chess_Army_Knife_ECF_Client::cache_key_games( $player_code, $rating_domain, Chess_Army_Knife_Rotating_Member::GAMES_PER_MEMBER );
		$games        = Chess_Army_Knife_ECF_Client::get_games( $player_code, $rating_domain, Chess_Army_Knife_Rotating_Member::GAMES_PER_MEMBER );
		$growth       = is_wp_error( $games ) ? null : Chess_Army_Knife_Rotating_Member::growth_from_games( (array) $games, gmdate( 'Y-m-d', strtotime( '-' . $days_back . ' days' ) ), 2 );
		$change       = $growth ? (int) round( $growth['gain'] ) : null;
	}
}

if ( '' === $name ) {
	$name = $player_code;
}

// The title is "Featured Player" unless the block has its own; both lines can use {player} and {club}.
$heading  = Chess_Army_Knife_Settings::with_player( '' !== $heading ? $heading : __( 'Featured Player', 'chess-army-knife' ), $name );
$subtitle = Chess_Army_Knife_Settings::with_player( $subtitle, $name );

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
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-featured__heading', $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
	<?php if ( '' !== $subtitle ) : ?>
		<p class="cak-featured__subtitle"><?php echo esc_html( $subtitle ); ?></p>
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
			<?php echo Chess_Army_Knife_A11y::heading( 1, 'cak-featured__name', trim( $player_title . ' ' . $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>

			<?php if ( '' !== $rating || ( $show_links && '' !== $player_code ) ) : ?>
				<?php $period_id = wp_unique_id( 'cak-featured-period-' ); ?>
				<p class="cak-featured__meta">
					<?php if ( '' !== $rating ) : ?>
						<span class="cak-featured__rating"><?php echo esc_html( $rating ); ?></span>
						<?php echo esc_html( isset( $domain_labels[ $rating_domain ] ) ? $domain_labels[ $rating_domain ] : '' ); ?>
						<?php if ( null !== $change ) : ?>
							<span class="cak-featured__change <?php echo esc_attr( $change >= 0 ? 'is-up' : 'is-down' ); ?>" aria-describedby="<?php echo esc_attr( $period_id ); ?>">(<?php echo esc_html( ( $change >= 0 ? '+' : '−' ) . abs( $change ) ); ?>)</span>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( '' !== $rating && $show_links && '' !== $player_code ) : ?>
						<span aria-hidden="true">·</span>
					<?php endif; ?>
					<?php if ( $show_links && '' !== $player_code ) : ?>
						<a class="cak-featured__link" href="<?php echo esc_url( Chess_Army_Knife_ECF_Client::profile_url( $player_code ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'ECF Profile', 'chess-army-knife' ); ?> <?php echo Chess_Army_Knife_A11y::hidden( sprintf( /* translators: %s: player name */ __( 'for %s (opens in a new tab)', 'chess-army-knife' ), $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in hidden(). ?></a>
					<?php endif; ?>
				</p>
				<?php if ( null !== $change ) : ?>
					<p class="cak-featured__period" id="<?php echo esc_attr( $period_id ); ?>">
						<?php
						/* translators: %d: number of days */
						echo esc_html( sprintf( _n( 'Over a %d day period', 'Over a %d day period', $days_back, 'chess-army-knife' ), $days_back ) );
						?>
					</p>
				<?php endif; ?>
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
