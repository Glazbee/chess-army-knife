<?php
/**
 * Admin side of club events: the "Event details" box on the edit screen
 * (repeat, dates, times, location, page, tournaments and leagues) and the
 * When / Location columns on the events list.
 *
 * Tags use WordPress's own tag box.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Events_Admin {

	const NONCE_ACTION = 'chess_army_knife_save_event';
	const NONCE_FIELD  = 'chess_army_knife_event_nonce';

	/**
	 * Hook up the admin screens.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_' . Chess_Army_Knife_Events::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . Chess_Army_Knife_Events::POST_TYPE, array( __CLASS__, 'save' ) );
		add_filter( 'manage_' . Chess_Army_Knife_Events::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Chess_Army_Knife_Events::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . Chess_Army_Knife_Events::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'sort_list' ) );
		add_action( Chess_Army_Knife_Events::TAXONOMY . '_add_form_fields', array( __CLASS__, 'render_new_tag_colour' ) );
		add_action( Chess_Army_Knife_Events::TAXONOMY . '_edit_form_fields', array( __CLASS__, 'render_tag_colour' ) );
		add_action( 'created_' . Chess_Army_Knife_Events::TAXONOMY, array( __CLASS__, 'save_tag_colour' ) );
		add_action( 'edited_' . Chess_Army_Knife_Events::TAXONOMY, array( __CLASS__, 'save_tag_colour' ) );
	}

	/**
	 * The colour field on the "add tag" form.
	 */
	public static function render_new_tag_colour() {
		wp_nonce_field( 'chess_army_knife_tag_colour', 'chess_army_knife_tag_colour_nonce' );
		?>
		<div class="form-field">
			<label for="chess_army_tag_colour"><?php esc_html_e( 'Colour', 'chess-army-knife' ); ?></label>
			<input type="color" id="chess_army_tag_colour" name="chess_army_tag_colour" value="#2a78d6" />
			<label><input type="checkbox" name="chess_army_tag_colour_default" value="1" checked="checked" /> <?php esc_html_e( 'Choose a colour for me', 'chess-army-knife' ); ?></label>
			<p><?php esc_html_e( 'Events with this tag are this colour in the calendar, and the calendar\'s key shows it.', 'chess-army-knife' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The colour field on the "edit tag" form.
	 *
	 * @param WP_Term $term Tag being edited.
	 */
	public static function render_tag_colour( $term ) {
		$chosen = (string) sanitize_hex_color( (string) get_term_meta( $term->term_id, Chess_Army_Knife_Events::TAG_COLOUR_META, true ) );
		wp_nonce_field( 'chess_army_knife_tag_colour', 'chess_army_knife_tag_colour_nonce' );
		?>
		<tr class="form-field">
			<th scope="row"><label for="chess_army_tag_colour"><?php esc_html_e( 'Colour', 'chess-army-knife' ); ?></label></th>
			<td>
				<input type="color" id="chess_army_tag_colour" name="chess_army_tag_colour" value="<?php echo esc_attr( '' !== $chosen ? $chosen : Chess_Army_Knife_Events::tag_colour( $term ) ); ?>" />
				<label><input type="checkbox" name="chess_army_tag_colour_default" value="1" <?php checked( '' === $chosen ); ?> /> <?php esc_html_e( 'Choose a colour for me', 'chess-army-knife' ); ?></label>
				<p class="description"><?php esc_html_e( 'Events with this tag are this colour in the calendar, and the calendar\'s key shows it.', 'chess-army-knife' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save a tag's colour.
	 *
	 * @param int $term_id Tag id.
	 */
	public static function save_tag_colour( $term_id ) {
		if ( ! isset( $_POST['chess_army_knife_tag_colour_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['chess_army_knife_tag_colour_nonce'] ) ), 'chess_army_knife_tag_colour' ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$colour = ( ! empty( $_POST['chess_army_tag_colour_default'] ) || ! isset( $_POST['chess_army_tag_colour'] ) ) ? '' : (string) sanitize_hex_color( sanitize_text_field( wp_unslash( $_POST['chess_army_tag_colour'] ) ) );
		if ( '' === $colour ) {
			delete_term_meta( $term_id, Chess_Army_Knife_Events::TAG_COLOUR_META );
		} else {
			update_term_meta( $term_id, Chess_Army_Knife_Events::TAG_COLOUR_META, $colour );
		}
	}

	/**
	 * Add the details box.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'chess_army_event_details',
			__( 'Event details', 'chess-army-knife' ),
			array( __CLASS__, 'render_meta_box' ),
			Chess_Army_Knife_Events::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * How an event can repeat.
	 *
	 * @return string[] Value => label; the empty value is a one-off.
	 */
	public static function repeat_options() {
		return array(
			''         => __( 'Does not repeat', 'chess-army-knife' ),
			'weekly'   => __( 'Every week', 'chess-army-knife' ),
			'monthly'  => __( 'Every month', 'chess-army-knife' ),
			'annually' => __( 'Every year', 'chess-army-knife' ),
		);
	}

	/**
	 * Leagues the club can attach: each distinct division among the teams' league entries.
	 *
	 * @return array[] Each { ref, label }.
	 */
	public static function available_leagues() {
		$leagues = array();

		foreach ( Chess_Army_Knife_Settings::get_club_teams() as $team ) {
			$ref = Chess_Army_Knife_Events::league_ref( $team['org'], $team['event'] );
			if ( ! isset( $leagues[ $ref ] ) ) {
				$leagues[ $ref ] = array(
					'ref'   => $ref,
					/* translators: 1: league / division name, 2: LMS organisation id */
					'label' => sprintf( __( '%1$s (organisation %2$s)', 'chess-army-knife' ), $team['event'], $team['org'] ),
				);
			}
		}

		return array_values( $leagues );
	}

	/**
	 * Render the details box.
	 *
	 * @param WP_Post $post Event being edited.
	 */
	public static function render_meta_box( $post ) {
		$start    = (string) get_post_meta( $post->ID, Chess_Army_Knife_Events::META_START, true );
		$end      = (string) get_post_meta( $post->ID, Chess_Army_Knife_Events::META_END, true );
		$location = (string) get_post_meta( $post->ID, Chess_Army_Knife_Events::META_LOCATION, true );

		$repeat = (string) get_post_meta( $post->ID, Chess_Army_Knife_Events::META_REPEAT, true );
		$until  = (string) get_post_meta( $post->ID, Chess_Army_Knife_Events::META_UNTIL, true );
		$page   = (int) get_post_meta( $post->ID, Chess_Army_Knife_Events::META_PAGE, true );
		$colour = (string) sanitize_hex_color( (string) get_post_meta( $post->ID, Chess_Army_Knife_Events::META_COLOUR, true ) );

		$date       = '' !== $start ? substr( $start, 0, 10 ) : '';
		$start_time = '' !== $start ? substr( $start, 11, 5 ) : Chess_Army_Knife_Events::default_time();
		$end_time   = '' !== $end ? substr( $end, 11, 5 ) : '';

		$selected_tournaments = array_map( 'intval', (array) get_post_meta( $post->ID, Chess_Army_Knife_Events::META_TOURNAMENTS, true ) );
		$selected_leagues     = (array) get_post_meta( $post->ID, Chess_Army_Knife_Events::META_LEAGUES, true );
		$tournaments          = Chess_Army_Knife_Tournament_Store::get_tournaments();
		$leagues              = self::available_leagues();
		$default_location     = Chess_Army_Knife_Events::default_location();

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="chess_army_event_repeat"><?php esc_html_e( 'Repeats', 'chess-army-knife' ); ?></label></th>
				<td>
					<select id="chess_army_event_repeat" name="chess_army_event_repeat">
						<?php foreach ( self::repeat_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $repeat, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_event_date"><?php esc_html_e( 'Start date', 'chess-army-knife' ); ?></label></th>
				<td><input type="date" id="chess_army_event_date" name="chess_army_event_date" value="<?php echo esc_attr( $date ); ?>" required /></td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_event_until"><?php esc_html_e( 'Stop date', 'chess-army-knife' ); ?></label></th>
				<td>
					<input type="date" id="chess_army_event_until" name="chess_army_event_until" value="<?php echo esc_attr( $until ); ?>" />
					<p class="description"><?php esc_html_e( 'For a repeating event: the last day it can happen. Leave blank to repeat with no end.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_event_start_time"><?php esc_html_e( 'Start time', 'chess-army-knife' ); ?></label></th>
				<td><input type="time" id="chess_army_event_start_time" name="chess_army_event_start_time" value="<?php echo esc_attr( $start_time ); ?>" required /></td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_event_end_time"><?php esc_html_e( 'Stop time', 'chess-army-knife' ); ?></label></th>
				<td><input type="time" id="chess_army_event_end_time" name="chess_army_event_end_time" value="<?php echo esc_attr( $end_time ); ?>" /> <span class="description"><?php esc_html_e( 'Optional.', 'chess-army-knife' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_event_colour"><?php esc_html_e( 'Colour', 'chess-army-knife' ); ?></label></th>
				<td>
					<input type="color" id="chess_army_event_colour" name="chess_army_event_colour" value="<?php echo esc_attr( '' !== $colour ? $colour : '#2a78d6' ); ?>" />
					<label>
						<input type="checkbox" name="chess_army_event_colour_default" value="1" <?php checked( '' === $colour ); ?> />
						<?php esc_html_e( 'Use the default colour', 'chess-army-knife' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Colours this event\'s bubble in the calendar. The default is the team\'s colour for a fixture, or none.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_event_location"><?php esc_html_e( 'Location', 'chess-army-knife' ); ?></label></th>
				<td>
					<input type="text" id="chess_army_event_location" name="chess_army_event_location" value="<?php echo esc_attr( $location ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $default_location ); ?>" />
					<p class="description">
						<?php
						if ( '' !== $default_location ) {
							/* translators: %s: the club venue */
							echo esc_html( sprintf( __( 'Leave blank to use the club venue: %s.', 'chess-army-knife' ), $default_location ) );
						} else {
							esc_html_e( 'Set your club venue under Settings, and leave this blank to use it.', 'chess-army-knife' );
						}
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="chess_army_event_page"><?php esc_html_e( 'Page', 'chess-army-knife' ); ?></label></th>
				<td>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes the dropdown it prints.
					wp_dropdown_pages(
						array(
							'name'              => 'chess_army_event_page',
							'id'                => 'chess_army_event_page',
							'selected'          => $page,
							'show_option_none'  => __( 'No page', 'chess-army-knife' ),
							'option_none_value' => '0',
						)
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					<?php if ( $page && get_edit_post_link( $page ) ) : ?>
						<a href="<?php echo esc_url( get_edit_post_link( $page ) ); ?>"><?php esc_html_e( 'Edit page', 'chess-army-knife' ); ?></a>
					<?php endif; ?>
					<?php if ( current_user_can( 'edit_pages' ) ) : ?>
						<label style="display:block">
							<input type="checkbox" name="chess_army_event_create_page" value="1" />
							<?php esc_html_e( 'Create a draft page for this event when I save', 'chess-army-knife' ); ?>
						</label>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'An event has no page of its own. Attach a page to link the event to it wherever it is listed.', 'chess-army-knife' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Tournaments', 'chess-army-knife' ); ?></th>
				<td>
					<?php if ( empty( $tournaments ) ) : ?>
						<p class="description"><?php esc_html_e( 'No tournaments have been created yet.', 'chess-army-knife' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $tournaments as $tournament ) : ?>
						<label style="display:block">
							<input type="checkbox" name="chess_army_event_tournaments[]" value="<?php echo esc_attr( $tournament['id'] ); ?>" <?php checked( in_array( (int) $tournament['id'], $selected_tournaments, true ) ); ?> />
							<?php echo esc_html( $tournament['name'] ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Leagues', 'chess-army-knife' ); ?></th>
				<td>
					<?php if ( empty( $leagues ) ) : ?>
						<p class="description"><?php esc_html_e( 'Add leagues to your teams, under Teams, to attach them here.', 'chess-army-knife' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $leagues as $league ) : ?>
						<label style="display:block">
							<input type="checkbox" name="chess_army_event_leagues[]" value="<?php echo esc_attr( $league['ref'] ); ?>" <?php checked( in_array( $league['ref'], $selected_leagues, true ) ); ?> />
							<?php echo esc_html( $league['label'] ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the details box.
	 *
	 * @param int $post_id Event id.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$date       = isset( $_POST['chess_army_event_date'] ) ? sanitize_text_field( wp_unslash( $_POST['chess_army_event_date'] ) ) : '';
		$start_time = isset( $_POST['chess_army_event_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['chess_army_event_start_time'] ) ) : '';
		$end_time   = isset( $_POST['chess_army_event_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['chess_army_event_end_time'] ) ) : '';

		$start = Chess_Army_Knife_Events::combine_datetime( $date, $start_time );
		$end   = '' !== $end_time ? Chess_Army_Knife_Events::combine_datetime( $date, $end_time ) : '';

		// An end that isn't after the start is dropped rather than saved.
		if ( '' === $start || $end <= $start ) {
			$end = '';
		}

		// Only a real repeat is kept, and a stop date only makes sense for one, on or after the start date.
		$repeat = isset( $_POST['chess_army_event_repeat'] ) ? sanitize_key( wp_unslash( $_POST['chess_army_event_repeat'] ) ) : '';
		if ( ! array_key_exists( $repeat, self::repeat_options() ) ) {
			$repeat = '';
		}
		$until = isset( $_POST['chess_army_event_until'] ) ? sanitize_text_field( wp_unslash( $_POST['chess_army_event_until'] ) ) : '';
		if ( '' === $repeat || '' === Chess_Army_Knife_Events::combine_datetime( $until, '00:00' ) || $until < $date ) {
			$until = '';
		}

		// An imported event that is edited by hand is no longer overwritten by imports.
		if ( '' !== (string) get_post_meta( $post_id, Chess_Army_Knife_Events_Import::META_LMS_KEY, true ) ) {
			update_post_meta( $post_id, Chess_Army_Knife_Events_Import::META_EDITED, 1 );
		}

		self::save_meta( $post_id, Chess_Army_Knife_Events::META_START, $start );
		self::save_meta( $post_id, Chess_Army_Knife_Events::META_END, $end );
		self::save_meta( $post_id, Chess_Army_Knife_Events::META_REPEAT, $repeat );
		self::save_meta( $post_id, Chess_Army_Knife_Events::META_UNTIL, $until );
		self::save_page( $post_id );

		// The colour picker always sends a value, so "default" has its own box.
		$colour = ( ! empty( $_POST['chess_army_event_colour_default'] ) || ! isset( $_POST['chess_army_event_colour'] ) ) ? '' : (string) sanitize_hex_color( sanitize_text_field( wp_unslash( $_POST['chess_army_event_colour'] ) ) );
		self::save_meta( $post_id, Chess_Army_Knife_Events::META_COLOUR, $colour );
		self::save_meta( $post_id, Chess_Army_Knife_Events::META_LOCATION, isset( $_POST['chess_army_event_location'] ) ? sanitize_text_field( wp_unslash( $_POST['chess_army_event_location'] ) ) : '' );

		// Only tournaments that exist can be attached.
		$tournament_ids = array();
		$known_ids      = wp_list_pluck( Chess_Army_Knife_Tournament_Store::get_tournaments(), 'id' );
		$known_ids      = array_map( 'intval', $known_ids );
		foreach ( isset( $_POST['chess_army_event_tournaments'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['chess_army_event_tournaments'] ) ) : array() as $tournament_id ) {
			if ( in_array( $tournament_id, $known_ids, true ) ) {
				$tournament_ids[] = $tournament_id;
			}
		}
		self::save_meta( $post_id, Chess_Army_Knife_Events::META_TOURNAMENTS, array_values( array_unique( $tournament_ids ) ) );

		// Only leagues among the club's teams can be attached.
		$allowed_leagues = wp_list_pluck( self::available_leagues(), 'ref' );
		$leagues         = array();
		foreach ( isset( $_POST['chess_army_event_leagues'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['chess_army_event_leagues'] ) ) : array() as $ref ) {
			if ( in_array( $ref, $allowed_leagues, true ) ) {
				$leagues[] = $ref;
			}
		}
		self::save_meta( $post_id, Chess_Army_Knife_Events::META_LEAGUES, array_values( array_unique( $leagues ) ) );
	}

	/**
	 * Attach the chosen page to the event, or make a draft one if asked.
	 *
	 * @param int $post_id Event id.
	 */
	protected static function save_page( $post_id ) {
		// The nonce was checked in save().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$page_id = isset( $_POST['chess_army_event_page'] ) ? absint( $_POST['chess_army_event_page'] ) : 0;

		if ( ! empty( $_POST['chess_army_event_create_page'] ) && ! $page_id && current_user_can( 'edit_pages' ) ) {
			$created = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_title'  => get_the_title( $post_id ),
				)
			);
			$page_id = is_wp_error( $created ) ? 0 : (int) $created;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Only an existing page can be attached.
		$page = $page_id ? get_post( $page_id ) : null;
		self::save_meta( $post_id, Chess_Army_Knife_Events::META_PAGE, $page && 'page' === $page->post_type ? $page_id : '' );
	}

	/**
	 * Store a meta value, or remove it when empty.
	 *
	 * @param int          $post_id Event id.
	 * @param string       $key     Meta key.
	 * @param string|array $value   Value.
	 */
	protected static function save_meta( $post_id, $key, $value ) {
		if ( '' === $value || array() === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Add When and Location columns after the title.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['chess_army_when']     = __( 'When', 'chess-army-knife' );
				$out['chess_army_location'] = __( 'Location', 'chess-army-knife' );
			}
		}
		return $out;
	}

	/**
	 * Fill the extra columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Event id.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'chess_army_when' === $column ) {
			$start = (string) get_post_meta( $post_id, Chess_Army_Knife_Events::META_START, true );
			$ts    = Chess_Army_Knife_Events::to_timestamp( $start );
			echo $ts ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ) : esc_html__( 'No date set', 'chess-army-knife' );

			$repeat  = (string) get_post_meta( $post_id, Chess_Army_Knife_Events::META_REPEAT, true );
			$options = self::repeat_options();
			if ( '' !== $repeat && isset( $options[ $repeat ] ) ) {
				echo '<br />' . esc_html( $options[ $repeat ] );
			}
		} elseif ( 'chess_army_location' === $column ) {
			$location = trim( (string) get_post_meta( $post_id, Chess_Army_Knife_Events::META_LOCATION, true ) );
			echo esc_html( '' !== $location ? $location : Chess_Army_Knife_Events::default_location() );
		}
	}

	/**
	 * Make the When column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		$columns['chess_army_when'] = 'chess_army_when';
		return $columns;
	}

	/**
	 * Order the events list by date, newest first unless the admin chose otherwise.
	 * Events without a date stay in the list.
	 *
	 * @param WP_Query $query The query.
	 */
	public static function sort_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || Chess_Army_Knife_Events::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$orderby = $query->get( 'orderby' );
		if ( '' !== $orderby && 'chess_army_when' !== $orderby ) {
			return;
		}

		$order = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) && 'chess_army_when' === $orderby ? 'ASC' : 'DESC';

		$query->set(
			'meta_query',
			array(
				'relation'     => 'OR',
				'start_clause' => array(
					'key'     => Chess_Army_Knife_Events::META_START,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => Chess_Army_Knife_Events::META_START,
					'compare' => 'NOT EXISTS',
				),
			)
		);
		$query->set( 'orderby', array( 'start_clause' => $order ) );
	}
}

Chess_Army_Knife_Events_Admin::init();
