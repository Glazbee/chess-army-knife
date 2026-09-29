<?php
/**
 * "Players" admin page: saved player profiles (name, optional ECF rating
 * code, optional manual rating) that can be entered into tournaments.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Players_Page {

	const SLUG = 'chess-army-knife-players';

	/** The lowest rating that can be entered by hand. */
	const MIN_MANUAL_RATING = 1300;

	/**
	 * Boot the admin page and its form handlers.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_chess_army_knife_save_player', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_chess_army_knife_delete_player', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public static function add_menu() {
		add_submenu_page(
			'chess-army-knife',
			__( 'Players', 'chess-army-knife' ),
			__( 'Players', 'chess-army-knife' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Validate and clean a submitted profile.
	 *
	 * @param array $input Raw (unslashed) form values.
	 * @return array|WP_Error name, ecf_code, manual_rating.
	 */
	public static function sanitize_player( array $input ) {
		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'player_name', __( 'Please enter a name.', 'chess-army-knife' ) );
		}

		$code = isset( $input['ecf_code'] ) ? strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', $input['ecf_code'] ) ) : '';

		$rating = null;
		if ( isset( $input['manual_rating'] ) && '' !== trim( (string) $input['manual_rating'] ) ) {
			$rating = (int) $input['manual_rating'];
			if ( $rating < self::MIN_MANUAL_RATING || $rating > 4000 ) {
				return new WP_Error( 'player_rating', self::rating_message() );
			}
		}

		return array(
			'name'          => $name,
			'ecf_code'      => $code,
			'manual_rating' => $rating,
		);
	}

	/**
	 * The message for a manual rating that is out of range.
	 *
	 * @return string
	 */
	protected static function rating_message() {
		/* translators: 1: lowest manual rating, 2: highest manual rating */
		return sprintf( __( 'A manual rating must be between %1$d and %2$d.', 'chess-army-knife' ), self::MIN_MANUAL_RATING, 4000 );
	}

	/**
	 * Human-readable message for an error code passed back in the URL.
	 *
	 * @param string $code Error code from sanitize_player().
	 * @return string
	 */
	protected static function error_message( $code ) {
		$messages = array(
			'player_name'   => __( 'Please enter a name.', 'chess-army-knife' ),
			'player_rating' => self::rating_message(),
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Something went wrong.', 'chess-army-knife' );
	}

	/**
	 * Handle the add/edit form.
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'chess_army_knife_save_player' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ) );
		}

		$clean = self::sanitize_player( wp_unslash( $_POST ) );
		$args  = array( 'page' => self::SLUG );

		if ( is_wp_error( $clean ) ) {
			$args['error'] = $clean->get_error_code();
		} else {
			$clean['id'] = isset( $_POST['player_id'] ) ? (int) $_POST['player_id'] : 0;
			Chess_Army_Knife_Tournament_Store::save_player( $clean );
			$args['saved'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle a delete link.
	 */
	public static function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'chess_army_knife_delete_player' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ) );
		}

		Chess_Army_Knife_Tournament_Store::delete_player( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'deleted' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$players = Chess_Army_Knife_Tournament_Store::get_players();
		$editing = isset( $_GET['edit'] ) ? Chess_Army_Knife_Tournament_Store::get_player( (int) $_GET['edit'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state; nothing is changed.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Players', 'chess-army-knife' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Saved player profiles for tournaments. With an ECF rating code, the current ECF rating is fetched when a tournament starts and used for seeding; a manual rating is only used for players without one.', 'chess-army-knife' ); ?>
			</p>

			<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Player saved.', 'chess-army-knife' ); ?></p></div>
			<?php elseif ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Player deleted.', 'chess-army-knife' ); ?></p></div>
			<?php elseif ( isset( $_GET['error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( self::error_message( sanitize_key( wp_unslash( $_GET['error'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'ECF code', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Manual rating', 'chess-army-knife' ); ?></th>
						<th style="width:140px;"><?php esc_html_e( 'Actions', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $players ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No players saved yet.', 'chess-army-knife' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $players as $player ) : ?>
						<tr>
							<td><?php echo esc_html( $player['name'] ); ?></td>
							<td><?php echo esc_html( $player['ecf_code'] ); ?></td>
							<td><?php echo null === $player['manual_rating'] ? '&mdash;' : esc_html( $player['manual_rating'] ); ?></td>
							<td>
								<a href="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'page' => self::SLUG,
											'edit' => $player['id'],
										),
										admin_url( 'admin.php' )
									)
								);
								?>
											"><?php esc_html_e( 'Edit', 'chess-army-knife' ); ?></a>
								|
								<a
									href="
									<?php
									echo esc_url(
										wp_nonce_url(
											add_query_arg(
												array(
													'action' => 'chess_army_knife_delete_player',
													'id' => $player['id'],
												),
												admin_url( 'admin-post.php' )
											),
											'chess_army_knife_delete_player'
										)
									);
									?>
											"
									onclick="return confirm('<?php echo esc_js( __( 'Delete this player? Existing tournaments keep their name.', 'chess-army-knife' ) ); ?>');"
								><?php esc_html_e( 'Delete', 'chess-army-knife' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo $editing ? esc_html__( 'Edit player', 'chess-army-knife' ) : esc_html__( 'Add a player', 'chess-army-knife' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="chess_army_knife_save_player" />
				<input type="hidden" name="player_id" value="<?php echo esc_attr( $editing ? $editing['id'] : 0 ); ?>" />
				<?php wp_nonce_field( 'chess_army_knife_save_player' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cak-ecf-search"><?php esc_html_e( 'Find on the ECF list', 'chess-army-knife' ); ?></label></th>
						<td data-cak-fill>
							<input type="search" id="cak-ecf-search" class="regular-text" data-cak-ecf-search autocomplete="off" placeholder="<?php esc_attr_e( 'Start typing a surname…', 'chess-army-knife' ); ?>" />
							<span class="spinner" data-cak-spinner></span>
							<ul class="cak-selector__results" data-cak-results></ul>
							<p class="description"><?php esc_html_e( 'Choose a player to fill in their name and ECF rating code.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="name"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="name" name="name" class="regular-text" value="<?php echo esc_attr( $editing ? $editing['name'] : '' ); ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ecf_code"><?php esc_html_e( 'ECF rating code', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="ecf_code" name="ecf_code" class="regular-text" placeholder="e.g. 120787J" value="<?php echo esc_attr( $editing ? $editing['ecf_code'] : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Preferred. Used to fetch the live ECF rating when a tournament starts.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="manual_rating"><?php esc_html_e( 'Manual rating', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="number" id="manual_rating" name="manual_rating" min="<?php echo esc_attr( self::MIN_MANUAL_RATING ); ?>" max="4000" value="<?php echo esc_attr( $editing && null !== $editing['manual_rating'] ? $editing['manual_rating'] : '' ); ?>" />
							<p class="description">
								<?php
								/* translators: %d: lowest manual rating */
								echo esc_html( sprintf( __( 'Only for players without an ECF code. A rating of %d or higher must be entered. Used for seeding when there is no code or the ECF rating cannot be found.', 'chess-army-knife' ), self::MIN_MANUAL_RATING ) );
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( $editing ? __( 'Save player', 'chess-army-knife' ) : __( 'Add player', 'chess-army-knife' ) ); ?>
			</form>
		</div>
		<?php
	}
}

Chess_Army_Knife_Players_Page::init();
