<?php
/**
 * Admin settings screen: global defaults (club code, LMS org id, rating
 * domain, LMS host override) so editors don't have to repeat them in
 * every block, plus cache duration controls and a "clear cache" action.
 *
 * Blocks store an *empty* value for anything that should follow the
 * global default (e.g. a blank "rating list" or "club code"); render.php
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
	 * other plugin admin screens, like Club Teams, register their own
	 * submenu under the same top-level slug).
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
			'default_club_code'        => '',
			'default_org_id'           => '',
			'default_event_name'       => '',
			'default_domain'           => 'S',
			'default_days_back'        => 60,
			'default_max_players'      => 12,
			'lms_base_url'             => '',
			'default_event_location'   => '', // Used by club events that don't set their own.
			'club_teams'               => '',  // Raw textarea: one "org | event | team" per line.
			'fast_cache_enabled'       => 1,
			'match_time'               => '19:30',
			'cache_ecf_minutes'        => 360, // Player info / games / club roster.
			'cache_lms_minutes'        => 30,  // League tables / matches / fixtures.
			'use_local_cache'          => 1,   // Persistent custom-table cache vs. plain transients.
			'delete_data_on_uninstall' => 0, // Also delete tournaments and players when the plugin is deleted.
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
	 * Whether "match-day fast cache" should currently be active: from 30
	 * minutes before the configured usual match kick-off time through to
	 * midnight, every day. Outside that window (including entirely, if
	 * the feature is switched off) normal cache durations apply.
	 *
	 * This is intentionally a simple time-of-day rule rather than one
	 * that checks actual fixtures first - checking fixtures would itself
	 * require an uncached API call, defeating the purpose. In exchange
	 * for that simplicity, the fast window applies every evening rather
	 * than only on real match nights; since it only shortens the cache
	 * duration (not the number of page views), the extra API load is
	 * small and bounded, and it needs zero day-to-day admin attention.
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

		$now          = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
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
	 * Get the club's configured teams. Delegates to the structured
	 * "Club Teams" admin page storage; the old free-text "org | event |
	 * team" per-line format (from before that page existed) is parsed
	 * automatically as a one-time migration if the structured list is
	 * still empty and legacy text is present.
	 *
	 * @return array[] List of { org, event, team }.
	 */
	public static function get_club_teams() {
		if ( class_exists( 'Chess_Army_Knife_Club_Teams_Page' ) ) {
			return Chess_Army_Knife_Club_Teams_Page::get_teams();
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

		if ( isset( $input['default_club_code'] ) ) {
			$clean['default_club_code'] = strtoupper( sanitize_text_field( $input['default_club_code'] ) );
		}
		if ( isset( $input['default_org_id'] ) ) {
			$clean['default_org_id'] = preg_replace( '/[^0-9]/', '', $input['default_org_id'] );
		}
		if ( isset( $input['default_event_name'] ) ) {
			$clean['default_event_name'] = sanitize_text_field( $input['default_event_name'] );
		}
		if ( isset( $input['default_domain'] ) ) {
			$clean['default_domain'] = ECF_Client::normalise_domain( $input['default_domain'] );
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

			<?php if ( isset( $_GET['chess_army_knife_cache_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Cached ECF/LMS data has been cleared.', 'chess-army-knife' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %s: link to the Club Teams admin page */
					wp_kses_post( __( 'Manage the list of teams your club has entered in each division on the %s page.', 'chess-army-knife' ) ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=chess-army-knife-club-teams' ) ) . '">' . esc_html__( 'Club Teams', 'chess-army-knife' ) . '</a>'
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
						<th scope="row"><label for="default_club_code"><?php esc_html_e( 'Default ECF club code', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="text" id="default_club_code" name="<?php echo esc_attr( self::OPTION ); ?>[default_club_code]" value="<?php echo esc_attr( $options['default_club_code'] ); ?>" class="regular-text" placeholder="e.g. 9BAJ" />
							<p class="description"><?php esc_html_e( 'Used by "Club Results" and "Biggest Gainers" blocks when their own club field is left blank.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
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
								<?php esc_html_e( 'Also delete all tournaments, results and player profiles', 'chess-army-knife' ); ?>
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
