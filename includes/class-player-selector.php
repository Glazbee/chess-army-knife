<?php
/**
 * The "choose players" control shared by the create-tournament form and a
 * draft tournament: two lists, the people who can be entered on the left (with a
 * search box) and the entrants on the right, which is what is saved. Someone who
 * is not a member is added in a box of their own, with an ECF code (the plugin
 * looks the person up) or without one (a name and an optional rating). The
 * behaviour lives in assets/tournament-picker.js.
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
		wp_enqueue_script( 'chess-army-knife-admin', Chess_Army_Knife_URL . 'assets/admin.js', array(), Chess_Army_Knife_VERSION, true );
		wp_enqueue_script( 'chess-army-knife-tournament-picker', Chess_Army_Knife_URL . 'assets/tournament-picker.js', array( 'jquery-ui-sortable', 'jquery-touch-punch' ), Chess_Army_Knife_VERSION, true );
		wp_localize_script(
			'chess-army-knife-tournament-picker',
			'chessArmyKnifePicker',
			array(
				'minRating' => Chess_Army_Knife_Membership_Store::MIN_MANUAL_RATING,
				'i18n'      => array(
					/* translators: %d: number of players */
					'count'      => __( '%d entered', 'chess-army-knife' ),
					'remove'     => __( 'Remove', 'chess-army-knife' ),
					'needName'   => __( 'Please enter a name.', 'chess-army-knife' ),
					'needCode'   => __( 'Please enter the ECF code.', 'chess-army-knife' ),
					/* translators: %d: lowest manual rating */
					'needRating' => sprintf( __( 'A manual rating must be %d or higher.', 'chess-army-knife' ), Chess_Army_Knife_Membership_Store::MIN_MANUAL_RATING ),
					/* translators: %s: ECF code */
					'codeNote'   => __( 'ECF code %s (name looked up when you save)', 'chess-army-knife' ),
					/* translators: %s: rating */
					'manualNote' => __( 'manual rating %s', 'chess-army-knife' ),
					'noCodeNote' => __( 'no code, not rated', 'chess-army-knife' ),
				),
			)
		);
	}

	/**
	 * Validate and clean one typed or found player.
	 *
	 * @param array $input Raw (unslashed) values: name, ecf_code, manual_rating.
	 * @return array|WP_Error name (blank when only a code was given), ecf_code, manual_rating (int|null).
	 */
	public static function sanitize_player( array $input ) {
		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		$code = isset( $input['ecf_code'] ) ? strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', $input['ecf_code'] ) ) : '';
		// With a code the name can be left blank: the ECF is asked for it.
		if ( '' === $name && '' === $code ) {
			return new WP_Error( 'player_name', __( 'Please enter a name.', 'chess-army-knife' ) );
		}

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
			// Only a code: the person's name is looked up, and kept on their record.
			if ( '' === $player['name'] ) {
				$recorded = Chess_Army_Knife_Membership_Store::record_ecf_player( $player['ecf_code'] );
				if ( is_wp_error( $recorded ) ) {
					/* translators: 1: ECF code, 2: reason */
					$outcome['errors'][] = sprintf( __( 'ECF code %1$s: %2$s', 'chess-army-knife' ), $player['ecf_code'], $recorded->get_error_message() );
				} else {
					$player_ids[] = $recorded;
				}
				continue;
			}

			// The person's own record, or a new one marked as not being a member. Only someone the club was asked not to record is entered without one.
			$person_id = Chess_Army_Knife_Membership_Store::ensure_person( $player['name'], $player['ecf_code'], $player['manual_rating'] );
			if ( $person_id ) {
				$player_ids[] = $person_id;
			} elseif ( Chess_Army_Knife_Do_Not_Record::is_blocked( $player['ecf_code'], $player['name'] ) ) {
				// The club was asked not to record this person: they play under their name only, with no record behind it.
				$entered = Chess_Army_Knife_Tournaments::add_unlinked_player( $tournament_id, $player['name'], $player['manual_rating'] );
				if ( ! is_wp_error( $entered ) ) {
					++$outcome['added'];
				} elseif ( 'tournament_duplicate' !== $entered->get_error_code() ) {
					$outcome['errors'][] = $entered->get_error_message();
				}
			}
		}
		$outcome['errors'] = array_merge( $outcome['errors'], $new['errors'] );

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
	 * Remove from a draft tournament the people who are no longer in the entrants list, then enter the
	 * people in it who are not yet entered. The list that was submitted is the entrants.
	 *
	 * @param int   $tournament_id Tournament id.
	 * @param array $input         Unslashed form values: player_ids, keep_entry_ids, new_players.
	 * @return array { added: int, removed: int, errors: string[] }
	 */
	public static function sync_players( $tournament_id, array $input ) {
		$wanted = isset( $input['player_ids'] ) && is_array( $input['player_ids'] ) ? array_map( 'absint', $input['player_ids'] ) : array();
		$keep   = isset( $input['keep_entry_ids'] ) && is_array( $input['keep_entry_ids'] ) ? array_map( 'absint', $input['keep_entry_ids'] ) : array();
		$errors = array();
		$gone   = 0;

		foreach ( Chess_Army_Knife_Tournament_Store::get_entries( $tournament_id ) as $entry ) {
			// Someone entered by name only has no person to match, so the entry id says whether they stay.
			$stays = $entry['player_id'] ? in_array( $entry['player_id'], $wanted, true ) : in_array( $entry['id'], $keep, true );
			if ( $stays ) {
				continue;
			}
			$removed = Chess_Army_Knife_Tournaments::remove_player( $tournament_id, $entry['id'] );
			if ( is_wp_error( $removed ) ) {
				$errors[] = $removed->get_error_message();
				break;
			}
			++$gone;
		}

		$entered            = self::enter_players( $tournament_id, $input );
		$entered['removed'] = $gone;
		$entered['errors']  = array_merge( $errors, $entered['errors'] );
		return $entered;
	}

	/**
	 * How a person's rating reads beside their name: the ECF rating last checked, a manual rating, or
	 * "no rating". The tournament fetches a fresh ECF rating when it starts.
	 *
	 * @param array $person Person row.
	 * @return string
	 */
	public static function rating_label( array $person ) {
		if ( ! empty( $person['ecf_rating'] ) ) {
			return (string) $person['ecf_rating'];
		}
		if ( ! empty( $person['manual_rating'] ) ) {
			/* translators: %d: manual rating */
			return sprintf( __( '%d (manual)', 'chess-army-knife' ), $person['manual_rating'] );
		}
		return __( '(no rating)', 'chess-army-knife' );
	}

	/**
	 * One person in either list.
	 *
	 * @param array $person   Person row: id, name, ecf_code, ecf_rating, manual_rating. An entry made by name only has id 0
	 *                        and an entry_id.
	 * @param bool  $entered  Whether the person is in the entrants list.
	 */
	protected static function render_person( array $person, $entered ) {
		$unlinked = empty( $person['id'] );
		?>
		<li class="cak-picker-person" data-name="<?php echo esc_attr( strtolower( $person['name'] . ' ' . $person['ecf_code'] ) ); ?>" data-unlinked="<?php echo $unlinked ? '1' : '0'; ?>" data-label="<?php echo esc_attr( $person['name'] ); ?>">
			<span class="cak-drag-handle dashicons dashicons-menu" aria-hidden="true"></span>
			<?php if ( $unlinked ) : ?>
				<input type="hidden" name="keep_entry_ids[]" value="<?php echo esc_attr( $person['entry_id'] ); ?>" />
			<?php else : ?>
				<input type="hidden" name="player_ids[]" value="<?php echo esc_attr( $person['id'] ); ?>" <?php disabled( ! $entered ); ?> />
			<?php endif; ?>
			<span class="cak-picker-person__text">
				<?php echo esc_html( $person['name'] ); ?>
				<span class="description">
					<?php echo esc_html( self::rating_label( $person ) ); ?>
					<?php echo '' !== $person['ecf_code'] ? '· ' . esc_html( $person['ecf_code'] ) : ''; ?>
				</span>
			</span>
			<button type="button" class="button cak-remove" <?php echo $entered ? '' : 'style="display:none"'; ?>>
				<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php /* translators: %s: player name */ echo esc_html( sprintf( __( 'Take %s out of the tournament', 'chess-army-knife' ), $person['name'] ) ); ?></span>
			</button>
			<button type="button" class="button cak-add" <?php echo $entered ? 'style="display:none"' : ''; ?>>
				<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php /* translators: %s: player name */ echo esc_html( sprintf( __( 'Enter %s in the tournament', 'chess-army-knife' ), $person['name'] ) ); ?></span>
			</button>
		</li>
		<?php
	}

	/**
	 * Print the control.
	 *
	 * @param array[] $available People who can be entered and are not yet: members and recorded guests.
	 * @param array[] $entered   People already entered (shown in the right list), each a person row; one made by name only has no id and carries entry_id.
	 */
	public static function render( array $available, array $entered = array() ) {
		?>
		<div class="cak-picker" data-cak-picker data-added="<?php /* translators: %s: player name */ esc_attr_e( '%s entered', 'chess-army-knife' ); ?>" data-removed="<?php /* translators: %s: player name */ esc_attr_e( '%s taken out', 'chess-army-knife' ); ?>">
			<p class="description"><?php esc_html_e( 'Drag people from the left into the tournament on the right, or use the arrow buttons. The list on the right is who plays. The rating shown is the last ECF rating checked, or the manual rating; the ECF is asked again when the tournament starts.', 'chess-army-knife' ); ?></p>
			<div class="cak-picker__columns">
				<div class="cak-picker__column">
					<h3 id="cak-picker-available-title"><?php esc_html_e( 'Members and guests', 'chess-army-knife' ); ?></h3>
					<p>
						<label for="cak-picker-filter" class="screen-reader-text"><?php esc_html_e( 'Find a member', 'chess-army-knife' ); ?></label>
						<input type="search" id="cak-picker-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Find a member by name or ECF code', 'chess-army-knife' ); ?>" data-cak-filter />
					</p>
					<ul class="cak-picker__list cak-picker__available" aria-labelledby="cak-picker-available-title">
						<?php foreach ( $available as $person ) : ?>
							<?php self::render_person( $person, false ); ?>
						<?php endforeach; ?>
					</ul>
				</div>
				<div class="cak-picker__column">
					<h3 id="cak-picker-entered-title"><?php esc_html_e( 'In the tournament', 'chess-army-knife' ); ?> <span class="description" data-cak-count></span></h3>
					<ul class="cak-picker__list cak-picker__entered" aria-labelledby="cak-picker-entered-title">
						<?php foreach ( $entered as $person ) : ?>
							<?php self::render_person( $person, true ); ?>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
			<p class="screen-reader-text" role="status" aria-live="polite" data-cak-status></p>

			<fieldset class="cak-picker__guest">
				<legend><strong><?php esc_html_e( 'Add someone who is not a member', 'chess-army-knife' ); ?></strong></legend>
				<p>
					<label><input type="radio" name="cak_guest_kind" value="code" checked data-cak-guest-kind /> <?php esc_html_e( 'I have their ECF code', 'chess-army-knife' ); ?></label><br />
					<label><input type="radio" name="cak_guest_kind" value="none" data-cak-guest-kind /> <?php esc_html_e( 'They have no ECF code', 'chess-army-knife' ); ?></label>
				</p>
				<p data-cak-guest-code>
					<label for="cak-guest-code"><?php esc_html_e( 'ECF code', 'chess-army-knife' ); ?></label>
					<input type="text" id="cak-guest-code" class="regular-text" data-cak-guest-code-input autocomplete="off" />
					<button type="button" class="button" data-cak-guest-add><?php esc_html_e( 'Add', 'chess-army-knife' ); ?></button><br />
					<span class="description"><?php esc_html_e( 'Their name and rating are looked up from the ECF when you save. If the club already has them, their record is used.', 'chess-army-knife' ); ?></span>
				</p>
				<p data-cak-guest-none hidden>
					<label for="cak-guest-name"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></label>
					<input type="text" id="cak-guest-name" class="regular-text" data-cak-guest-name />
					<label for="cak-guest-rating"><?php esc_html_e( 'Rating (optional)', 'chess-army-knife' ); ?></label>
					<input type="number" id="cak-guest-rating" class="small-text" data-cak-guest-rating min="<?php echo esc_attr( Chess_Army_Knife_Membership_Store::MIN_MANUAL_RATING ); ?>" max="<?php echo esc_attr( Chess_Army_Knife_Membership_Store::MAX_MANUAL_RATING ); ?>" />
					<button type="button" class="button" data-cak-guest-add><?php esc_html_e( 'Add', 'chess-army-knife' ); ?></button><br />
					<span class="description">
						<?php
						/* translators: 1: lowest manual rating, 2: highest manual rating */
						echo esc_html( sprintf( __( 'A rating must be between %1$d and %2$d. Leave it blank if you do not know it: they then play unrated.', 'chess-army-knife' ), Chess_Army_Knife_Membership_Store::MIN_MANUAL_RATING, Chess_Army_Knife_Membership_Store::MAX_MANUAL_RATING ) );
						?>
					</span>
				</p>
				<p class="description cak-picker__error" data-cak-guest-error role="alert"></p>
				<p class="description"><?php esc_html_e( 'Someone who is not a member is kept on the club\'s records as a non-member, with their name, ECF code and rating only. They are left out of member lists. They are still checked against the Do Not Record list.', 'chess-army-knife' ); ?></p>
				<ul class="cak-picker__new" data-cak-new></ul>
			</fieldset>
		</div>
		<?php
	}
}

Chess_Army_Knife_Player_Selector::init();
