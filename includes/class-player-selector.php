<?php
/**
 * The "choose players" control shared by the create-tournament form and a
 * draft tournament: a searchable checklist of the club's members and guests, a
 * search of them by name (which fills in the rating code), and a manual entry
 * for anyone else, who is recorded as not being a member. The behaviour lives
 * in assets/admin.js.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Player_Selector {

	/** Most new players accepted in one submission. */
	const MAX_NEW_PLAYERS = 200;

	/**
	 * Load the admin script and styles on the Tournaments page.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the admin assets on our own pages only.
	 *
	 * @param string $hook Admin page hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, Chess_Army_Knife_Tournaments_Page::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'chess-army-knife-admin', Chess_Army_Knife_URL . 'assets/admin.css', array(), Chess_Army_Knife_VERSION );
		wp_enqueue_script( 'chess-army-knife-admin', Chess_Army_Knife_URL . 'assets/admin.js', array( 'wp-api-fetch' ), Chess_Army_Knife_VERSION, true );
		wp_localize_script(
			'chess-army-knife-admin',
			'chessArmyKnifeAdmin',
			array(
				'minRating' => Chess_Army_Knife_Membership_Store::MIN_MANUAL_RATING,
				'i18n'      => array(
					/* translators: %d: number of players */
					'selected'    => __( '%d selected', 'chess-army-knife' ),
					'add'         => __( 'Add', 'chess-army-knife' ),
					'use'         => __( 'Use', 'chess-army-knife' ),
					'select'      => __( 'Select', 'chess-army-knife' ),
					'saved'       => __( 'already listed', 'chess-army-knife' ),
					'remove'      => __( 'Remove', 'chess-army-knife' ),
					/* translators: %d: number of members found */
					'found'       => __( '%d members found. Use Tab to move to them.', 'chess-army-knife' ),
					'noResults'   => __( 'No matching members found. Only current members with an ECF code are listed.', 'chess-army-knife' ),
					'searchError' => __( 'The member search is not available right now.', 'chess-army-knife' ),
					'needName'    => __( 'Please enter a name.', 'chess-army-knife' ),
					/* translators: %d: lowest manual rating */
					'needRating'  => sprintf( __( 'A manual rating must be %d or higher.', 'chess-army-knife' ), Chess_Army_Knife_Membership_Store::MIN_MANUAL_RATING ),
					'manual'      => __( 'manual rating', 'chess-army-knife' ),
				),
			)
		);
	}

	/**
	 * Validate and clean one typed or found player.
	 *
	 * @param array $input Raw (unslashed) values: name, ecf_code, manual_rating.
	 * @return array|WP_Error name, ecf_code, manual_rating (int|null).
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
			if ( $rating < Chess_Army_Knife_Membership_Store::MIN_MANUAL_RATING || $rating > Chess_Army_Knife_Membership_Store::MAX_MANUAL_RATING ) {
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
		return sprintf( __( 'A manual rating must be between %1$d and %2$d.', 'chess-army-knife' ), Chess_Army_Knife_Membership_Store::MIN_MANUAL_RATING, Chess_Army_Knife_Membership_Store::MAX_MANUAL_RATING );
	}

	/**
	 * Clean the players typed or found in the selector.
	 *
	 * @param mixed $raw Unslashed `new_players` value from the form.
	 * @return array { players: array[] name/ecf_code/manual_rating, errors: string[] }
	 */
	public static function parse_new_players( $raw ) {
		$result = array(
			'players' => array(),
			'errors'  => array(),
		);
		if ( ! is_array( $raw ) ) {
			return $result;
		}

		$seen = array();
		foreach ( array_slice( $raw, 0, self::MAX_NEW_PLAYERS ) as $input ) {
			if ( ! is_array( $input ) ) {
				continue;
			}
			$clean = self::sanitize_player( $input );
			if ( is_wp_error( $clean ) ) {
				$result['errors'][] = $clean->get_error_message();
				continue;
			}
			// The same ECF code twice is the same person.
			if ( '' !== $clean['ecf_code'] ) {
				if ( isset( $seen[ $clean['ecf_code'] ] ) ) {
					continue;
				}
				$seen[ $clean['ecf_code'] ] = true;
			}
			$result['players'][] = $clean;
		}
		return $result;
	}

	/**
	 * Enter the players chosen in the selector in a draft tournament. Players
	 * found among the members or typed in are saved as profiles first (reusing
	 * the profile that already has the same ECF code).
	 *
	 * @param int   $tournament_id Tournament id.
	 * @param array $input         Unslashed form values: player_ids, new_players.
	 * @return array { added: int, errors: string[] }
	 */
	public static function enter_players( $tournament_id, array $input ) {
		$outcome = array(
			'added'  => 0,
			'errors' => array(),
		);

		$player_ids = isset( $input['player_ids'] ) && is_array( $input['player_ids'] ) ? array_map( 'absint', $input['player_ids'] ) : array();

		$new = self::parse_new_players( isset( $input['new_players'] ) ? $input['new_players'] : array() );
		foreach ( $new['players'] as $player ) {
			// The person's own record, or a new one marked as not being a member: nobody is entered without a record.
			$person_id = Chess_Army_Knife_Membership_Store::ensure_person( $player['name'], $player['ecf_code'], $player['manual_rating'] );
			if ( $person_id ) {
				$player_ids[] = $person_id;
			}
		}
		$outcome['errors'] = $new['errors'];

		foreach ( array_unique( array_filter( $player_ids ) ) as $player_id ) {
			$entered = Chess_Army_Knife_Tournaments::add_player( $tournament_id, $player_id );
			if ( ! is_wp_error( $entered ) ) {
				++$outcome['added'];
			} elseif ( 'tournament_duplicate' !== $entered->get_error_code() ) {
				$outcome['errors'][] = $entered->get_error_message();
			}
		}
		return $outcome;
	}

	/**
	 * Message to show after players were entered.
	 *
	 * @param array $outcome Result of enter_players().
	 * @return true|WP_Error
	 */
	public static function outcome( array $outcome ) {
		if ( ! empty( $outcome['errors'] ) ) {
			return new WP_Error( 'players_not_added', implode( ' ', array_unique( $outcome['errors'] ) ) );
		}
		return true;
	}

	/**
	 * Print the control.
	 *
	 * @param array[] $players Members and guests that can be chosen.
	 */
	public static function render( array $players ) {
		?>
		<div class="cak-selector" data-cak-selector>
			<?php if ( empty( $players ) ) : ?>
				<p class="description"><?php esc_html_e( 'No members or guests to choose from yet. Find club members below, or add someone who is not a member.', 'chess-army-knife' ); ?></p>
			<?php else : ?>
				<p>
					<label for="cak-filter"><strong><?php esc_html_e( 'Members and guests', 'chess-army-knife' ); ?></strong></label><br />
					<input type="search" id="cak-filter" class="regular-text" data-cak-filter placeholder="<?php esc_attr_e( 'Filter by name or ECF code…', 'chess-army-knife' ); ?>" />
					<button type="button" class="button-link" data-cak-select-shown><?php esc_html_e( 'Select all shown', 'chess-army-knife' ); ?></button>
					&middot;
					<button type="button" class="button-link" data-cak-clear><?php esc_html_e( 'Clear', 'chess-army-knife' ); ?></button>
					<span class="description" data-cak-count aria-live="polite"></span>
				</p>
				<div class="cak-selector__list" role="group" aria-label="<?php esc_attr_e( 'Members and guests', 'chess-army-knife' ); ?>">
					<?php foreach ( $players as $player ) : ?>
						<label class="cak-selector__item" data-cak-search="<?php echo esc_attr( strtolower( $player['name'] . ' ' . $player['ecf_code'] ) ); ?>" data-cak-code="<?php echo esc_attr( $player['ecf_code'] ); ?>">
							<input type="checkbox" name="player_ids[]" value="<?php echo esc_attr( $player['id'] ); ?>" />
							<?php echo esc_html( $player['name'] ); ?>
							<span class="description">
								<?php
								if ( '' !== $player['ecf_code'] ) {
									echo esc_html( $player['ecf_code'] );
								} elseif ( null !== $player['manual_rating'] ) {
									/* translators: %d: manual rating */
									echo esc_html( sprintf( __( 'rating %d (manual)', 'chess-army-knife' ), $player['manual_rating'] ) );
								} else {
									esc_html_e( 'unrated', 'chess-army-knife' );
								}
								?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p>
				<label for="cak-ecf-search"><strong><?php esc_html_e( 'Find a club member', 'chess-army-knife' ); ?></strong></label><br />
				<input type="search" id="cak-ecf-search" class="regular-text" data-cak-ecf-search autocomplete="off" placeholder="<?php esc_attr_e( 'Start typing a name…', 'chess-army-knife' ); ?>" />
				<span class="spinner" data-cak-spinner></span>
			</p>
			<p class="screen-reader-text" role="status" aria-live="polite" data-cak-search-status></p>
			<ul class="cak-selector__results" data-cak-results></ul>

			<details>
				<summary><?php esc_html_e( 'Player without an ECF code?', 'chess-army-knife' ); ?></summary>
				<p>
					<label class="screen-reader-text" for="cak-manual-name"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></label>
					<input type="text" id="cak-manual-name" class="regular-text" data-cak-manual-name placeholder="<?php esc_attr_e( 'Name', 'chess-army-knife' ); ?>" />
					<label class="screen-reader-text" for="cak-manual-rating"><?php esc_html_e( 'Rating', 'chess-army-knife' ); ?></label>
					<input type="number" id="cak-manual-rating" class="small-text" data-cak-manual-rating min="<?php echo esc_attr( Chess_Army_Knife_Membership_Store::MIN_MANUAL_RATING ); ?>" max="4000" placeholder="<?php esc_attr_e( 'Rating', 'chess-army-knife' ); ?>" />
					<button type="button" class="button" data-cak-manual-add><?php esc_html_e( 'Add', 'chess-army-knife' ); ?></button>
				</p>
				<p class="description">
					<?php
					/* translators: %d: lowest allowed manual rating */
					echo esc_html( sprintf( __( 'A manual rating of %d or higher must be entered. It is only used for seeding when the player has no ECF code.', 'chess-army-knife' ), Chess_Army_Knife_Membership_Store::MIN_MANUAL_RATING ) );
					?>
				</p>
				<p class="description cak-selector__error" data-cak-manual-error role="alert"></p>
			</details>

			<div data-cak-new-wrap hidden>
				<p><strong><?php esc_html_e( 'New players to add', 'chess-army-knife' ); ?></strong></p>
				<ul class="cak-selector__new" data-cak-new></ul>
			</div>
		</div>
		<?php
	}
}

Chess_Army_Knife_Player_Selector::init();
