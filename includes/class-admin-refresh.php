<?php
/**
 * Admin-only "Last refreshed X ago · Refresh now" control for blocks.
 *
 * The link works without JavaScript: it returns to the current page with the
 * cache keys to forget plus a nonce; template_redirect() clears just those
 * keys and redirects to a clean URL.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Admin_Refresh {

	const QUERY_VAR       = 'ecf_lms_refresh';
	const QUERY_VAR_KEYS  = 'ecf_lms_keys';
	const QUERY_VAR_NONCE = 'ecf_lms_refresh_nonce';
	const NONCE_ACTION    = 'ecf_lms_refresh';

	/**
	 * Boot the early request-time handler.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_refresh' ), 1 );
	}

	/**
	 * If the current request is a valid, authorised "refresh this
	 * block's data" request, clear the specified cache entries and
	 * redirect to the clean URL so the next render fetches fresh data.
	 */
	public static function maybe_handle_refresh() {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce = isset( $_GET[ self::QUERY_VAR_NONCE ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR_NONCE ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$encoded = isset( $_GET[ self::QUERY_VAR_KEYS ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR_KEYS ] ) ) : '';
		$keys    = array_filter( explode( ',', base64_decode( strtr( $encoded, '-_', '+/' ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		foreach ( $keys as $key ) {
			Chess_Army_Knife_Cache::forget( $key );
		}

		wp_safe_redirect( remove_query_arg( array( self::QUERY_VAR, self::QUERY_VAR_KEYS, self::QUERY_VAR_NONCE ) ) );
		exit;
	}

	/**
	 * Render the admin-only refresh bar for a set of cache keys, or an
	 * empty string if the current user shouldn't see it.
	 *
	 * @param string[] $cache_keys Raw cache keys backing the data just displayed.
	 * @param string   $label      Optional short label, e.g. "Club roster".
	 * @return string HTML (already escaped), or ''.
	 */
	public static function bar( $cache_keys, $label = '' ) {
		if ( ! current_user_can( 'manage_options' ) || empty( $cache_keys ) ) {
			return '';
		}

		$timestamps = array_filter( array_map( array( 'Chess_Army_Knife_Cache', 'get_created_at' ), $cache_keys ) );
		$oldest     = ! empty( $timestamps ) ? min( $timestamps ) : null;

		$encoded_keys = strtr( base64_encode( implode( ',', $cache_keys ) ), '+/', '-_' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $host . $request_uri;

		$refresh_url = add_query_arg(
			array(
				self::QUERY_VAR       => '1',
				self::QUERY_VAR_KEYS  => $encoded_keys,
				self::QUERY_VAR_NONCE => wp_create_nonce( self::NONCE_ACTION ),
			),
			$current_url
		);

		ob_start();
		?>
		<p class="chess-army-knife-admin-bar">
			<span class="chess-army-knife-admin-bar__badge"><?php esc_html_e( 'Admin only', 'chess-army-knife' ); ?></span>
			<?php if ( $label ) : ?>
				<span class="chess-army-knife-admin-bar__label"><?php echo esc_html( $label ); ?>:</span>
			<?php endif; ?>
			<?php if ( $oldest ) : ?>
				<?php
				printf(
					/* translators: %s: human-readable time difference, e.g. "4 minutes" */
					esc_html__( 'Last refreshed %s ago.', 'chess-army-knife' ),
					esc_html( human_time_diff( $oldest ) )
				);
				?>
			<?php elseif ( Chess_Army_Knife_Settings::use_local_cache() ) : ?>
				<?php esc_html_e( 'Not cached yet.', 'chess-army-knife' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Refresh time unavailable while using the transient cache backend.', 'chess-army-knife' ); ?>
			<?php endif; ?>
			<a href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh now', 'chess-army-knife' ); ?></a>
		</p>
		<?php
		return ob_get_clean();
	}
}

Chess_Army_Knife_Admin_Refresh::init();
