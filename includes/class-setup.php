<?php
/**
 * First-run setup: one screen that asks for what the plugin needs to start
 * working (the club's name and venue, its regular events, its ECF details and
 * its LMS details), so nobody has to hunt for those settings.
 *
 * The screen is shown once, when the plugin is first turned on, and stays in
 * the menu so it can be used again. Everything it asks for can also be changed
 * later on the Settings screen and the Club Events list.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Setup {

	const PAGE         = 'chess-army-knife-setup';
	const ACTION       = 'chess_army_knife_setup';
	const DONE_OPTION  = 'Chess_Army_Knife_setup_done';
	const REDIRECT_KEY = 'Chess_Army_Knife_setup_redirect';
	const REQUIRED_CAP = 'manage_options';

	/** Marks an event made here, so running setup again does not make it twice. */
	const META_SETUP_KEY = '_chess_army_event_setup_key';

	/**
	 * Hook up the form, the first-run redirect and the reminder.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
	}

	/**
	 * Ask for the setup screen the next time an administrator opens the admin area.
	 * Called when the plugin is activated.
	 */
	public static function request_redirect() {
		if ( ! self::is_done() ) {
			set_transient( self::REDIRECT_KEY, 1, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Take an administrator to the setup screen once, straight after activation.
	 */
	public static function maybe_redirect() {
		if ( ! get_transient( self::REDIRECT_KEY ) ) {
			return;
		}
		delete_transient( self::REDIRECT_KEY );

		// Not for a bulk activation, a background request or someone who can't change settings.
		if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() || ! current_user_can( self::REQUIRED_CAP ) || self::is_done() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides whether to redirect.
			return;
		}

		wp_safe_redirect( self::screen_url() );
		exit;
	}

	/**
	 * Whether setup has been completed or skipped.
	 *
	 * @return bool
	 */
	public static function is_done() {
		return (bool) get_option( self::DONE_OPTION );
	}

	/**
	 * The address of the setup screen.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function screen_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * A message for the top of the Overview until setup is done.
	 *
	 * @return string Escaped HTML, or '' if there is nothing to say.
	 */
	public static function notice() {
		if ( self::is_done() || ! current_user_can( self::REQUIRED_CAP ) ) {
			return '';
		}

		return '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Welcome to Chess Army Knife.', 'chess-army-knife' ) . '</strong> '
			. esc_html__( 'Tell the plugin about your club so it can start working.', 'chess-army-knife' )
			. ' <a href="' . esc_url( self::screen_url() ) . '">' . esc_html__( 'Start setup', 'chess-army-knife' ) . '</a></p></div>';
	}

	/**
	 * The regular events the club can have made for it. Tournaments are not here: they come
	 * with the tournaments themselves (see Tournaments).
	 *
	 * @return array[] By key: { title, tag, weekday (0 Sunday to 6 Saturday), start, end }, the suggestions shown.
	 */
	public static function event_presets() {
		return array(
			'club_night'  => array(
				'title'   => __( 'Club night', 'chess-army-knife' ),
				'tag'     => __( 'Club night', 'chess-army-knife' ),
				'weekday' => 2,
				'start'   => '19:30',
				'end'     => '22:00',
			),
			'coaching'    => array(
				'title'   => __( 'Coaching session', 'chess-army-knife' ),
				'tag'     => __( 'Coaching', 'chess-army-knife' ),
				'weekday' => 6,
				'start'   => '10:00',
				'end'     => '12:00',
			),
			'competitive' => array(
				'title'   => __( 'Competitive games', 'chess-army-knife' ),
				'tag'     => __( 'Competitive games', 'chess-army-knife' ),
				'weekday' => 4,
				'start'   => '19:30',
				'end'     => '22:00',
			),
		);
	}

	/**
	 * The first date on or after a day that falls on a weekday.
	 *
	 * @param string $today   "Y-m-d".
	 * @param int    $weekday 0 (Sunday) to 6 (Saturday).
	 * @return string "Y-m-d".
	 */
	public static function next_weekday( $today, $weekday ) {
		$time = strtotime( $today . ' 12:00:00 UTC' );
		$gap  = ( (int) $weekday - (int) gmdate( 'w', $time ) + 7 ) % 7;

		return gmdate( 'Y-m-d', $time + $gap * DAY_IN_SECONDS );
	}

	/**
	 * Make weekly events, each from its first date.
	 *
	 * @param array[] $rows  Each { key, title, tag, weekday, start, end } (end may be '').
	 * @param string  $today "Y-m-d": the first events fall on or after it.
	 * @return int How many were made; one whose key already has an event is skipped.
	 */
	public static function create_events( array $rows, $today ) {
		$made = 0;

		foreach ( $rows as $row ) {
			if ( self::event_exists( $row['key'] ) ) {
				continue;
			}

			$date  = self::next_weekday( $today, $row['weekday'] );
			$start = Chess_Army_Knife_Events::combine_datetime( $date, $row['start'] );
			if ( '' === $start || '' === trim( $row['title'] ) ) {
				continue;
			}
			$end = '' !== $row['end'] ? Chess_Army_Knife_Events::combine_datetime( $date, $row['end'] ) : '';

			$meta = array(
				self::META_SETUP_KEY                 => $row['key'],
				Chess_Army_Knife_Events::META_START  => $start,
				Chess_Army_Knife_Events::META_REPEAT => 'weekly',
			);
			if ( '' !== $end && $end > $start ) {
				$meta[ Chess_Army_Knife_Events::META_END ] = $end;
			}

			$post_id = wp_insert_post(
				array(
					'post_type'   => Chess_Army_Knife_Events::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $row['title'],
					'meta_input'  => $meta,
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			if ( '' !== $row['tag'] ) {
				wp_set_object_terms( $post_id, array( $row['tag'] ), Chess_Army_Knife_Events::TAXONOMY );
			}
			++$made;
		}

		return $made;
	}

	/**
	 * Whether setup has already made the event for a key.
	 *
	 * @param string $key Preset key.
	 * @return bool
	 */
	protected static function event_exists( $key ) {
		$posts = get_posts(
			array(
				'post_type'      => Chess_Army_Knife_Events::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META_SETUP_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Finds the one event setup made for this key.
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Finds the one event setup made for this key.
			)
		);

		return ! empty( $posts );
	}

	/**
	 * Draw the setup screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( current_user_can( self::REQUIRED_CAP ), __( 'Setup', 'chess-army-knife' ), 'settings' ) ) {
			return;
		}

		$options = Chess_Army_Knife_Settings::get_options();
		$domains = Chess_Army_Knife_Settings::rating_domains();
		$days    = array(
			1 => __( 'Monday', 'chess-army-knife' ),
			2 => __( 'Tuesday', 'chess-army-knife' ),
			3 => __( 'Wednesday', 'chess-army-knife' ),
			4 => __( 'Thursday', 'chess-army-knife' ),
			5 => __( 'Friday', 'chess-army-knife' ),
			6 => __( 'Saturday', 'chess-army-knife' ),
			0 => __( 'Sunday', 'chess-army-knife' ),
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the outcome of the form.
		$made = isset( $_GET['cak_setup_events'] ) ? absint( $_GET['cak_setup_events'] ) : null;
		$done = isset( $_GET['cak_setup_done'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Set up your club', 'chess-army-knife' ); ?></h1>
			<p><?php esc_html_e( 'Fill in what you can: everything here can be changed later under Settings and Club Events, and you can leave anything blank for now.', 'chess-army-knife' ); ?></p>

			<?php if ( $done ) : ?>
				<div class="notice notice-success" role="status"><p>
					<?php
					echo esc_html(
						null === $made || 0 === $made
							? __( 'Saved.', 'chess-army-knife' )
							/* translators: %d: number of events made */
							: sprintf( _n( 'Saved, and %d regular event added.', 'Saved, and %d regular events added.', $made, 'chess-army-knife' ), $made )
					);
					?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>

				<h2><?php esc_html_e( 'Your club', 'chess-army-knife' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cak-setup-club-name"><?php esc_html_e( 'Club name', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="cak-setup-club-name" name="club_name" value="<?php echo esc_attr( $options['club_name'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( Chess_Army_Knife_Settings::club_name() ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cak-setup-club-venue"><?php esc_html_e( 'Club venue', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="cak-setup-club-venue" name="club_venue" value="<?php echo esc_attr( $options['club_venue'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. The Village Hall, High Street', 'chess-army-knife' ); ?>" />
							<p class="description"><?php esc_html_e( 'Where the club meets. Events are held here unless they say otherwise.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cak-setup-club-map"><?php esc_html_e( 'Venue on a map', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="url" id="cak-setup-club-map" name="club_venue_map" value="<?php echo esc_attr( $options['club_venue_map'] ); ?>" class="regular-text" placeholder="https://maps.app.goo.gl/..." />
							<p class="description"><?php esc_html_e( 'A Google Maps link to the venue (optional).', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cak-setup-club-w3w"><?php esc_html_e( 'Venue what3words address', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="cak-setup-club-w3w" name="club_venue_w3w" value="<?php echo esc_attr( $options['club_venue_w3w'] ); ?>" class="regular-text" placeholder="index.home.raft" />
							<p class="description"><?php esc_html_e( 'The three words for the venue\'s entrance (optional).', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Regular events', 'chess-army-knife' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Tick the events your club holds every week and they are added to the calendar. Change the day or time if you need to. Tournaments are added when you create the tournament, and league matches are imported from the LMS.', 'chess-army-knife' ); ?></p>
				<table class="widefat striped" style="max-width:900px">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Add', 'chess-army-knife' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Event', 'chess-army-knife' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Day', 'chess-army-knife' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Start time', 'chess-army-knife' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Stop time', 'chess-army-knife' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( self::event_presets() as $key => $preset ) : ?>
							<?php $exists = self::event_exists( $key ); ?>
							<tr>
								<td>
									<?php if ( $exists ) : ?>
										<?php esc_html_e( 'Added', 'chess-army-knife' ); ?>
									<?php else : ?>
										<input type="checkbox" id="cak-setup-add-<?php echo esc_attr( $key ); ?>" name="events[<?php echo esc_attr( $key ); ?>][add]" value="1" />
										<label class="screen-reader-text" for="cak-setup-add-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $preset['title'] ); ?></label>
									<?php endif; ?>
								</td>
								<td>
									<label class="screen-reader-text" for="cak-setup-title-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Event name', 'chess-army-knife' ); ?></label>
									<input type="text" id="cak-setup-title-<?php echo esc_attr( $key ); ?>" name="events[<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $preset['title'] ); ?>" <?php disabled( $exists ); ?> />
								</td>
								<td>
									<label class="screen-reader-text" for="cak-setup-day-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Day', 'chess-army-knife' ); ?></label>
									<select id="cak-setup-day-<?php echo esc_attr( $key ); ?>" name="events[<?php echo esc_attr( $key ); ?>][weekday]" <?php disabled( $exists ); ?>>
										<?php foreach ( $days as $number => $label ) : ?>
											<option value="<?php echo (int) $number; ?>" <?php selected( $preset['weekday'], $number ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<label class="screen-reader-text" for="cak-setup-start-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Start time', 'chess-army-knife' ); ?></label>
									<input type="time" id="cak-setup-start-<?php echo esc_attr( $key ); ?>" name="events[<?php echo esc_attr( $key ); ?>][start]" value="<?php echo esc_attr( $preset['start'] ); ?>" <?php disabled( $exists ); ?> />
								</td>
								<td>
									<label class="screen-reader-text" for="cak-setup-end-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Stop time', 'chess-army-knife' ); ?></label>
									<input type="time" id="cak-setup-end-<?php echo esc_attr( $key ); ?>" name="events[<?php echo esc_attr( $key ); ?>][end]" value="<?php echo esc_attr( $preset['end'] ); ?>" <?php disabled( $exists ); ?> />
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'English Chess Federation (ECF)', 'chess-army-knife' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cak-setup-ecf-code"><?php esc_html_e( 'ECF club code', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="cak-setup-ecf-code" name="ecf_club_code" value="<?php echo esc_attr( $options['ecf_club_code'] ); ?>" class="regular-text" placeholder="e.g. 4USL" />
							<p class="description"><?php esc_html_e( 'Lets members\' ratings be refreshed from the ECF in one request.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cak-setup-domain"><?php esc_html_e( 'Rating list to show', 'chess-army-knife' ); ?></label></th>
						<td>
							<select id="cak-setup-domain" name="default_domain">
								<?php foreach ( $domains as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options['default_domain'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'League Management System (LMS)', 'chess-army-knife' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cak-setup-lms-key"><?php esc_html_e( 'LMS API key', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="password" id="cak-setup-lms-key" name="lms_api_key" value="<?php echo esc_attr( $options['lms_api_key'] ); ?>" class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Create a key on your LMS account\'s "API keys" page. It is needed to import your teams\' fixtures into the calendar.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cak-setup-org"><?php esc_html_e( 'LMS organisation ID', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="cak-setup-org" name="default_org_id" value="<?php echo esc_attr( $options['default_org_id'] ); ?>" class="regular-text" placeholder="e.g. 613" />
							<p class="description"><?php esc_html_e( 'The number at the end of your league\'s LMS address. Each team\'s own league is added under Teams.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
				</table>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and continue', 'chess-army-knife' ); ?></button>
					<button type="submit" name="skip" value="1" class="button" formnovalidate><?php esc_html_e( 'Skip for now', 'chess-army-knife' ); ?></button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Next', 'chess-army-knife' ); ?></h2>
			<ul class="ul-disc">
				<li>
					<?php
					printf(
						/* translators: %s: link to the Policies screen */
						wp_kses_post( __( 'Set up your club\'s %s.', 'chess-army-knife' ) ),
						'<a href="' . esc_url( Chess_Army_Knife_Policies::screen_url() ) . '">' . esc_html__( 'policies', 'chess-army-knife' ) . '</a>'
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: %s: link to the Teams screen */
						wp_kses_post( __( 'Add your %s and the leagues they play in, then import their fixtures.', 'chess-army-knife' ) ),
						'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Teams::POST_TYPE ) ) . '">' . esc_html__( 'teams', 'chess-army-knife' ) . '</a>'
					);
					?>
				</li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Handle the form: save the settings, make the events ticked, and mark setup as done.
	 */
	public static function handle() {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}

		update_option( self::DONE_OPTION, 1 );

		if ( ! empty( $_POST['skip'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . Chess_Army_Knife_Menu::SLUG ) );
			exit;
		}

		$changes = array();
		foreach ( array( 'club_name', 'club_venue', 'club_venue_map', 'club_venue_w3w', 'ecf_club_code', 'default_domain', 'lms_api_key', 'default_org_id' ) as $name ) {
			if ( isset( $_POST[ $name ] ) ) {
				$changes[ $name ] = sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
			}
		}
		Chess_Army_Knife_Settings::save( $changes );

		$rows      = array();
		$presets   = self::event_presets();
		$submitted = isset( $_POST['events'] ) && is_array( $_POST['events'] ) ? wp_unslash( $_POST['events'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is cleaned below.
		foreach ( $presets as $key => $preset ) {
			if ( empty( $submitted[ $key ]['add'] ) ) {
				continue;
			}
			$row    = $submitted[ $key ];
			$rows[] = array(
				'key'     => $key,
				'title'   => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : $preset['title'],
				'tag'     => $preset['tag'],
				'weekday' => isset( $row['weekday'] ) ? max( 0, min( 6, (int) $row['weekday'] ) ) : $preset['weekday'],
				'start'   => isset( $row['start'] ) ? sanitize_text_field( $row['start'] ) : $preset['start'],
				'end'     => isset( $row['end'] ) ? sanitize_text_field( $row['end'] ) : '',
			);
		}
		$made = self::create_events( $rows, current_time( 'Y-m-d' ) );

		wp_safe_redirect(
			self::screen_url(
				array(
					'cak_setup_done'   => 1,
					'cak_setup_events' => $made,
				)
			)
		);
		exit;
	}
}

Chess_Army_Knife_Setup::init();
