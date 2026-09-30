<?php
/**
 * Admin settings screen: global defaults (LMS org id, rating
 * domain, LMS host override) so editors don't have to repeat them in
 * every block, plus cache duration controls and a "clear cache" action.
 *
 * Blocks store an *empty* value for anything that should follow the
 * global default (e.g. a blank "rating list" or "LMS organisation"); render.php
 * for each block resolves that via Chess_Army_Knife_Settings::resolve().
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Settings {

	const OPTION = 'Chess_Army_Knife_settings';

	/**
	 * Boot the settings screen.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_ecf_lms_clear_cache', array( __CLASS__, 'handle_clear_cache' ) );
	}

	/**
	 * Add the top-level admin menu (Settings is its first/default page;
	 * other plugin admin screens register their own submenu under the
	 * same top-level slug).
	 */
	public static function add_menu() {
		add_menu_page(
			__( 'Chess Army Knife', 'chess-army-knife' ),
			__( 'ECF & LMS', 'chess-army-knife' ),
			'manage_options',
			'chess-army-knife',
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-line',
			76
		);
		add_submenu_page(
			'chess-army-knife',
			__( 'Settings', 'chess-army-knife' ),
			__( 'Settings', 'chess-army-knife' ),
			'manage_options',
			'chess-army-knife',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the option and its fields.
	 */
	public static function register_settings() {
		register_setting(
			'Chess_Army_Knife',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'default_org_id'            => '',
			'default_event_name'        => '',
			'default_domain'            => 'S',
			'ecf_club_code'             => '', // The club's ECF code, to refresh all members' ratings in one request.
			'default_days_back'         => 60,
			'default_max_players'       => 12,
			'lms_base_url'              => '',
			'default_event_location'    => '', // Used by club events that don't set their own.
			'membership_payment_info'   => '', // How to pay for a membership (bank details, cash at the club...).
			'data_contact_email'        => '', // Shown in the data policy as who to contact about personal data.
			'member_retention_months'   => 24, // Months to keep lapsed members and old applications; 0 keeps them for ever.
			'renewal_reminders_enabled' => 0, // Email members as their membership runs out.
			'renewal_reminder_days'     => '30,7,0,-7', // Days before (positive) or after (negative) the last day of membership.
			'renewal_reminder_subject'  => '',
			'renewal_reminder_message'  => '',
			'club_teams'                => '',  // Raw textarea: one "org | event | team" per line.
			'fast_cache_enabled'        => 1,
			'match_time'                => '19:30',
			'cache_ecf_minutes'         => 360, // Player info / games.
			'cache_lms_minutes'         => 30,  // League tables / matches / fixtures.
			'use_local_cache'           => 1,   // Persistent custom-table cache vs. plain transients.
			'delete_data_on_uninstall'  => 0, // Also delete tournaments and players when the plugin is deleted.
		);
	}

	/**
	 * Get the saved options merged with defaults.
	 *
	 * @return array
	 */
	public static function get_options() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Resolve a per-block value against its global default: if the block
	 * attribute is empty/unset, fall back to the configured global
	 * default for that field, then to a hard-coded fallback.
	 *
	 * @param string $field         Key into get_options(), e.g. "default_domain".
	 * @param mixed  $block_value   The block attribute's current value.
	 * @param mixed  $hard_fallback Used if both the block and the global setting are empty.
	 * @return mixed
	 */
	public static function resolve( $field, $block_value, $hard_fallback = '' ) {
		if ( null !== $block_value && '' !== $block_value ) {
			return $block_value;
		}

		$options = self::get_options();
		if ( isset( $options[ $field ] ) && '' !== $options[ $field ] ) {
			return $options[ $field ];
		}

		return $hard_fallback;
	}

	/**
	 * Get the configured cache duration (minutes) for a data bucket,
	 * falling back to $default if nothing specific is configured.
	 *
	 * Buckets starting with "ecf_" use the shared ECF minutes setting;
	 * buckets starting with "lms_" use the shared LMS minutes setting.
	 *
	 * @param string $bucket  e.g. "ecf_games", "lms_table".
	 * @param int    $default Fallback minutes.
	 * @return int
	 */
	public static function get_cache_minutes( $bucket, $default ) {
		$options = self::get_options();

		if ( 0 === strpos( $bucket, 'ecf_' ) ) {
			$minutes = (int) $options['cache_ecf_minutes'];
		} elseif ( 0 === strpos( $bucket, 'lms_' ) ) {
			$minutes = (int) $options['cache_lms_minutes'];
		} else {
			$minutes = $default;
		}

		$minutes = $minutes > 0 ? $minutes : $default;

		/**
		 * Filter the cache duration (in minutes) for a given data bucket.
		 *
		 * @param int    $minutes Cache duration in minutes.
		 * @param string $bucket  Bucket identifier.
		 */
		return (int) apply_filters( 'Chess_Army_Knife_cache_minutes', $minutes, $bucket );
	}

	/**
	 * Whether "match-day fast cache" is active: from 30 minutes before the
	 * configured usual kick-off time until midnight, every day.
	 *
	 * A plain time-of-day rule, because checking real fixtures would itself need
	 * an uncached API call. It only shortens cache durations, so the extra API
	 * load is small and bounded.
	 *
	 * @return bool
	 */
	public static function in_fast_cache_window() {
		$options = self::get_options();

		if ( empty( $options['fast_cache_enabled'] ) ) {
			return false;
		}

		$match_time = $options['match_time'];
		if ( ! preg_match( '/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $match_time ) ) {
			$match_time = '19:30';
		}

		list( $hour, $minute ) = array_map( 'intval', explode( ':', $match_time ) );

		$now          = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Compared with site-local wall-clock times built by mktime() below.
		$window_start = mktime( $hour, $minute, 0, (int) current_time( 'n' ), (int) current_time( 'j' ), (int) current_time( 'Y' ) ) - ( 30 * MINUTE_IN_SECONDS );
		$window_end   = mktime( 23, 59, 59, (int) current_time( 'n' ), (int) current_time( 'j' ), (int) current_time( 'Y' ) );

		return $now >= $window_start && $now <= $window_end;
	}

	/**
	 * The cache duration (minutes) to use for near-live LMS data right
	 * now: the fast-window duration if that window is active, otherwise
	 * the normal configured/default duration for the bucket.
	 *
	 * @param string $bucket  e.g. "lms_table".
	 * @param int    $default Fallback minutes if nothing is configured.
	 * @return int
	 */
	public static function get_effective_lms_cache_minutes( $bucket, $default ) {
		if ( self::in_fast_cache_window() ) {
			return 5;
		}
		return self::get_cache_minutes( $bucket, $default );
	}

	/**
	 * Get the club's league entries: every LMS organisation, event and team
	 * name the club's teams play in. They are kept on the teams themselves
	 * (see Chess_Army_Knife_Teams).
	 *
	 * @return array[] List of { org, event, team, team_id }.
	 */
	public static function get_club_teams() {
		if ( class_exists( 'Chess_Army_Knife_Teams' ) ) {
			return Chess_Army_Knife_Teams::league_entries();
		}
		return array();
	}

	/**
	 * Whether the persistent local-database cache should be used instead
	 * of plain WordPress transients.
	 *
	 * @return bool
	 */
	public static function use_local_cache() {
		$options = self::get_options();
		return ! empty( $options['use_local_cache'] );
	}

	/**
	 * Sanitize the settings form submission.
	 *
	 * @param array $input Raw form input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$clean = self::defaults();

		if ( isset( $input['default_org_id'] ) ) {
			$clean['default_org_id'] = preg_replace( '/[^0-9]/', '', $input['default_org_id'] );
		}
		if ( isset( $input['default_event_name'] ) ) {
			$clean['default_event_name'] = sanitize_text_field( $input['default_event_name'] );
		}
		if ( isset( $input['ecf_club_code'] ) ) {
			$clean['ecf_club_code'] = strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', sanitize_text_field( $input['ecf_club_code'] ) ) );
		}
		if ( isset( $input['default_domain'] ) ) {
			$clean['default_domain'] = Chess_Army_Knife_ECF_Client::normalise_domain( $input['default_domain'] );
		}
		if ( isset( $input['default_days_back'] ) ) {
			$clean['default_days_back'] = max( 1, (int) $input['default_days_back'] );
		}
		if ( isset( $input['default_max_players'] ) ) {
			$clean['default_max_players'] = max( 1, (int) $input['default_max_players'] );
		}
		if ( isset( $input['default_event_location'] ) ) {
			$clean['default_event_location'] = sanitize_text_field( $input['default_event_location'] );
		}
		if ( isset( $input['membership_payment_info'] ) ) {
			$clean['membership_payment_info'] = sanitize_textarea_field( $input['membership_payment_info'] );
		}
		if ( isset( $input['data_contact_email'] ) ) {
			$clean['data_contact_email'] = sanitize_email( $input['data_contact_email'] );
		}
		if ( isset( $input['member_retention_months'] ) ) {
			$clean['member_retention_months'] = max( 0, min( 120, (int) $input['member_retention_months'] ) );
		}
		$clean['renewal_reminders_enabled'] = ! empty( $input['renewal_reminders_enabled'] ) ? 1 : 0;
		if ( isset( $input['renewal_reminder_days'] ) ) {
			$clean['renewal_reminder_days'] = implode( ',', Chess_Army_Knife_Renewal_Reminders::parse_schedule( sanitize_text_field( $input['renewal_reminder_days'] ) ) );
		}
		if ( isset( $input['renewal_reminder_subject'] ) ) {
			$clean['renewal_reminder_subject'] = sanitize_text_field( $input['renewal_reminder_subject'] );
		}
		if ( isset( $input['renewal_reminder_message'] ) ) {
			$clean['renewal_reminder_message'] = sanitize_textarea_field( $input['renewal_reminder_message'] );
		}
		if ( isset( $input['lms_base_url'] ) ) {
			$clean['lms_base_url'] = esc_url_raw( trim( $input['lms_base_url'] ) );
		}
		// The old free-text team list no longer has a field on this page;
		// keep whatever was stored so the one-off migration into the Club
		// Teams page still works if settings are saved first.
		$existing                    = self::get_options();
		$clean['club_teams']         = $existing['club_teams'];
		$clean['fast_cache_enabled'] = ! empty( $input['fast_cache_enabled'] ) ? 1 : 0;
		if ( isset( $input['match_time'] ) && preg_match( '/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $input['match_time'] ) ) {
			$clean['match_time'] = $input['match_time'];
		}
		if ( isset( $input['cache_ecf_minutes'] ) ) {
			$clean['cache_ecf_minutes'] = max( 5, (int) $input['cache_ecf_minutes'] );
		}
		if ( isset( $input['cache_lms_minutes'] ) ) {
			$clean['cache_lms_minutes'] = max( 5, (int) $input['cache_lms_minutes'] );
		}
		$clean['use_local_cache']          = ! empty( $input['use_local_cache'] ) ? 1 : 0;
		$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] ) ? 1 : 0;

		return $clean;
	}

	/**
	 * Handle the "Clear cached data" button.
	 */
	public static function handle_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ecf_lms_clear_cache' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ) );
		}

		Chess_Army_Knife_Cache::flush_all();

		wp_safe_redirect( add_query_arg( 'chess_army_knife_cache_cleared', '1', admin_url( 'options-general.php?page=chess-army-knife' ) ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = self::get_options();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Chess Army Knife', 'chess-army-knife' ); ?></h1>

			<?php if ( isset( $_GET['chess_army_knife_cache_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice; nothing is changed. ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Cached ECF/LMS data has been cleared.', 'chess-army-knife' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %s: link to the Teams screen */
					wp_kses_post( __( 'The leagues your club\'s teams have entered are set on each team, under %s.', 'chess-army-knife' ) ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Chess_Army_Knife_Teams::POST_TYPE ) ) . '">' . esc_html__( 'Teams', 'chess-army-knife' ) . '</a>'
				);
				?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'Chess_Army_Knife' ); ?>

				<h2><?php esc_html_e( 'Global defaults', 'chess-army-knife' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Set these once and leave the matching field blank in a block to use them automatically. Any block can still override a default individually.', 'chess-army-knife' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="default_org_id"><?php esc_html_e( 'Default LMS organisation ID', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="default_org_id" name="<?php echo esc_attr( self::OPTION ); ?>[default_org_id]" value="<?php echo esc_attr( $options['default_org_id'] ); ?>" class="regular-text" placeholder="e.g. 613" />
							<p class="description">
								<?php esc_html_e( 'The numeric ID at the end of your league\'s LMS homepage URL, e.g. lms.englishchess.org.uk/lms/organisation/613 → 613. Used by "League Standings" and "Team Carousel" blocks when left blank.', 'chess-army-knife' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_event_name"><?php esc_html_e( 'Default event/division name', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="default_event_name" name="<?php echo esc_attr( self::OPTION ); ?>[default_event_name]" value="<?php echo esc_attr( $options['default_event_name'] ); ?>" class="regular-text" placeholder="e.g. Division 1" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_domain"><?php esc_html_e( 'Default rating list', 'chess-army-knife' ); ?></label></th>
						<td>
							<select id="default_domain" name="<?php echo esc_attr( self::OPTION ); ?>[default_domain]">
								<?php
								$domains = array(
									'S'  => __( 'Standard (OTB)', 'chess-army-knife' ),
									'R'  => __( 'Rapid (OTB)', 'chess-army-knife' ),
									'B'  => __( 'Blitz (OTB)', 'chess-army-knife' ),
									'SW' => __( 'Online Standard', 'chess-army-knife' ),
									'RW' => __( 'Online Rapid', 'chess-army-knife' ),
									'BW' => __( 'Online Blitz', 'chess-army-knife' ),
								);
								foreach ( $domains as $value => $label ) {
									printf(
										'<option value="%1$s" %2$s>%3$s</option>',
										esc_attr( $value ),
										selected( $options['default_domain'], $value, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ecf_club_code"><?php esc_html_e( 'ECF club code', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="ecf_club_code" name="<?php echo esc_attr( self::OPTION ); ?>[ecf_club_code]" value="<?php echo esc_attr( $options['ecf_club_code'] ); ?>" class="regular-text" placeholder="e.g. 4USL" />
							<p class="description"><?php esc_html_e( 'Your club\'s ECF code. Members\' ratings are then refreshed from the ECF\'s club list in a single request instead of one request per member. Only ratings of people already on your records are kept; the rest of the list is not stored.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_days_back"><?php esc_html_e( 'Default "days to look back"', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="number" min="1" id="default_days_back" name="<?php echo esc_attr( self::OPTION ); ?>[default_days_back]" value="<?php echo esc_attr( $options['default_days_back'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Used by "Club Results" and "Biggest Gainers".', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_max_players"><?php esc_html_e( 'Default "players checked from roster"', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="number" min="1" id="default_max_players" name="<?php echo esc_attr( self::OPTION ); ?>[default_max_players]" value="<?php echo esc_attr( $options['default_max_players'] ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_event_location"><?php esc_html_e( 'Default event location', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="default_event_location" name="<?php echo esc_attr( self::OPTION ); ?>[default_event_location]" value="<?php echo esc_attr( $options['default_event_location'] ); ?>" class="regular-text" placeholder="e.g. The Village Hall, High Street" />
							<p class="description"><?php esc_html_e( 'Where club events are usually held. A club event that has no location of its own uses this.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="membership_payment_info"><?php esc_html_e( 'How to pay for membership', 'chess-army-knife' ); ?></label></th>
						<td>
							<textarea id="membership_payment_info" name="<?php echo esc_attr( self::OPTION ); ?>[membership_payment_info]" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Bank transfer to Any Chess Club, sort code 00-00-00, account 12345678, quoting your reference. Or pay cash at the club.', 'chess-army-knife' ); ?>"><?php echo esc_textarea( $options['membership_payment_info'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown to people who apply for membership and, if you choose, beside the advertised memberships. The website never takes payments itself. Plain text only.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="data_contact_email"><?php esc_html_e( 'Data protection contact', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="email" id="data_contact_email" name="<?php echo esc_attr( self::OPTION ); ?>[data_contact_email]" value="<?php echo esc_attr( $options['data_contact_email'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Shown publicly in the Club Data Policy block as who to contact to see, correct or delete personal details. Use a club address rather than a personal one.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="member_retention_months"><?php esc_html_e( 'Keep old membership records for', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="number" min="0" max="120" id="member_retention_months" name="<?php echo esc_attr( self::OPTION ); ?>[member_retention_months]" value="<?php echo esc_attr( $options['member_retention_months'] ); ?>" class="small-text" />
							<?php esc_html_e( 'months', 'chess-army-knife' ); ?>
							<p class="description"><?php esc_html_e( 'Members whose membership ended, and applications that were declined, cancelled or never approved, are deleted automatically this long after. A record with a payment on it is kept for your accounts, but its personal details are removed. Enter 0 to keep everything until you delete it yourself. Current members are never removed.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Renewal reminders', 'chess-army-knife' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[renewal_reminders_enabled]" value="1" <?php checked( ! empty( $options['renewal_reminders_enabled'] ) ); ?> /> <?php esc_html_e( 'Email members as their membership runs out', 'chess-army-knife' ); ?></label>
							<p class="description"><?php esc_html_e( 'A daily job emails current members once at each stage below (a junior\'s email goes to their parent or guardian). Members can stop these emails with the link in each one. See Memberships > Renewals for who would be emailed.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="renewal_reminder_days"><?php esc_html_e( 'Reminder days', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="renewal_reminder_days" name="<?php echo esc_attr( self::OPTION ); ?>[renewal_reminder_days]" value="<?php echo esc_attr( $options['renewal_reminder_days'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Days before the last day of membership, separated by commas; 0 is the last day itself and a negative number is days after. The default, 30,7,0,-7, sends four reminders.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="renewal_reminder_subject"><?php esc_html_e( 'Reminder subject', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="renewal_reminder_subject" name="<?php echo esc_attr( self::OPTION ); ?>[renewal_reminder_subject]" value="<?php echo esc_attr( $options['renewal_reminder_subject'] ); ?>" class="large-text" placeholder="<?php echo esc_attr( Chess_Army_Knife_Renewal_Reminders::default_text()['subject'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="renewal_reminder_message"><?php esc_html_e( 'Reminder message', 'chess-army-knife' ); ?></label></th>
						<td>
							<textarea id="renewal_reminder_message" name="<?php echo esc_attr( self::OPTION ); ?>[renewal_reminder_message]" rows="6" class="large-text" placeholder="<?php echo esc_attr( Chess_Army_Knife_Renewal_Reminders::default_text()['body'] ); ?>"><?php echo esc_textarea( $options['renewal_reminder_message'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Leave blank to use the wording shown. You can use {name}, {type}, {expiry}, {when} (for example "runs out on 31 March"), {reference} and {payment} (your payment instructions with the member\'s reference). Plain text only.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Advanced', 'chess-army-knife' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lms_base_url"><?php esc_html_e( 'LMS API base URL override', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="lms_base_url" name="<?php echo esc_attr( self::OPTION ); ?>[lms_base_url]" value="<?php echo esc_attr( $options['lms_base_url'] ); ?>" class="regular-text" placeholder="https://lms.englishchess.org.uk/lms/lmsrest/league" />
							<p class="description"><?php esc_html_e( 'Only needed if the ECF changes their LMS API host again. Leave blank to use the built-in default (with an automatic fallback to the legacy host if needed).', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Persistent local cache', 'chess-army-knife' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[use_local_cache]" value="1" <?php checked( ! empty( $options['use_local_cache'] ) ); ?> />
								<?php esc_html_e( 'Store fetched ECF/LMS data in a dedicated database table instead of transients', 'chess-army-knife' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Recommended. Transients can be evicted early by some hosting setups (object caches, low-memory limits); a dedicated table keeps cached data until it actually expires, so pages aren\'t re-fetching from the ECF/LMS APIs on every visit.', 'chess-army-knife' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cache_ecf_minutes"><?php esc_html_e( 'ECF ratings cache duration (minutes)', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="number" min="5" id="cache_ecf_minutes" name="<?php echo esc_attr( self::OPTION ); ?>[cache_ecf_minutes]" value="<?php echo esc_attr( $options['cache_ecf_minutes'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Player info, games and club rosters change slowly (ratings update monthly, games nightly). 360 minutes (6 hours) is a sensible default; the ECF API has a daily processing-time budget per site, so avoid setting this very low.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cache_lms_minutes"><?php esc_html_e( 'LMS league data cache duration (minutes)', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="number" min="5" id="cache_lms_minutes" name="<?php echo esc_attr( self::OPTION ); ?>[cache_lms_minutes]" value="<?php echo esc_attr( $options['cache_lms_minutes'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'League tables and match results. This is the "normal" duration used outside the match-day fast-cache window below.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Match-day fast cache', 'chess-army-knife' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[fast_cache_enabled]" value="1" <?php checked( ! empty( $options['fast_cache_enabled'] ) ); ?> />
								<?php esc_html_e( 'Automatically refresh LMS league data every 5 minutes from 30 minutes before kick-off until midnight', 'chess-army-knife' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Gives near-live standings and match updates on match evenings without having to change the cache duration by hand. Applies every day at the time below; outside that window (and on non-match days) the normal LMS cache duration above applies as usual, so this typically only costs a handful of extra requests per evening.', 'chess-army-knife' ); ?>
							</p>
							<p>
								<label for="match_time"><?php esc_html_e( 'Usual kick-off time:', 'chess-army-knife' ); ?></label>
								<input type="time" id="match_time" name="<?php echo esc_attr( self::OPTION ); ?>[match_time]" value="<?php echo esc_attr( $options['match_time'] ); ?>" />
							</p>
							<?php if ( self::in_fast_cache_window() ) : ?>
								<p><strong><?php esc_html_e( 'Fast cache is active right now.', 'chess-army-knife' ); ?></strong></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'When the plugin is deleted', 'chess-army-knife' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $options['delete_data_on_uninstall'] ) ); ?> />
								<?php esc_html_e( 'Also delete all tournaments, results, player profiles, club events, membership types and members', 'chess-army-knife' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default, so deleting the plugin keeps your tournament history. Settings and cached data are always removed. This cannot be undone.', 'chess-army-knife' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Cache', 'chess-army-knife' ); ?></h2>
			<p>
				<?php
				$stats = Chess_Army_Knife_Cache::stats();
				printf(
					/* translators: 1: number of cached entries, 2: storage backend name */
					esc_html__( 'Currently storing %1$d cached response(s) using the %2$s backend.', 'chess-army-knife' ),
					(int) $stats['count'],
					esc_html( $stats['backend'] )
				);
				?>
			</p>
			<p><?php esc_html_e( 'If you\'ve just corrected a code, or data looks stale, clear the cache to force fresh lookups.', 'chess-army-knife' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ecf_lms_clear_cache" />
				<?php wp_nonce_field( 'ecf_lms_clear_cache' ); ?>
				<?php submit_button( __( 'Clear cached data', 'chess-army-knife' ), 'secondary' ); ?>
			</form>

			<hr />
			<p>
				<?php
				printf(
					/* translators: 1: ECF API docs link, 2: LMS docs link */
					wp_kses_post( __( 'Data sources: %1$s and %2$s.', 'chess-army-knife' ) ),
					'<a href="https://rating.englishchess.org.uk/help/api" target="_blank" rel="noopener noreferrer">' . esc_html__( 'ECF Ratings API', 'chess-army-knife' ) . '</a>',
					'<a href="https://lms.englishchess.org.uk/lms/node/34" target="_blank" rel="noopener noreferrer">' . esc_html__( 'ECF LMS API', 'chess-army-knife' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}

Chess_Army_Knife_Settings::init();
