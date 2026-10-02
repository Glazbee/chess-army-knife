<?php
/**
 * The directory of other clubs: each club's venue and the team names it plays under, so
 * an away fixture imported from the LMS can be given the venue of the club that hosts it.
 *
 * The LMS gives no venues. The club's own teams have a venue on the team, and the rest are
 * learned here: every import records the team names it sees, and the Sort Clubs screen
 * asks which team names belong to one club and where that club plays. Nothing is guessed
 * silently, and the directory is private: it only feeds the location of events.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Clubs {

	const POST_TYPE    = 'chess_army_club';
	const META_VENUE   = '_chess_army_club_venue';
	const META_MAP     = '_chess_army_club_map';
	const META_W3W     = '_chess_army_club_w3w';
	const META_TEAM    = '_chess_army_club_team'; // One row for each team name the club plays under.
	const SEEN_OPTION  = 'Chess_Army_Knife_clubs_seen'; // Team names seen in imports: lower case => as written.
	const PAGE         = 'chess-army-knife-sort-clubs';
	const ACTION_SORT  = 'chess_army_knife_sort_clubs';
	const NONCE_ACTION = 'chess_army_knife_save_club';
	const NONCE_FIELD  = 'chess_army_knife_club_nonce';

	/**
	 * Hook up the post type, its edit box and the sorting form.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ) );
		add_action( 'admin_post_' . self::ACTION_SORT, array( __CLASS__, 'handle_sort' ) );
		add_action( 'admin_notices', array( __CLASS__, 'list_notice' ) );

		// The list is kept for the request (an import looks teams and clubs up for every fixture) and forgotten when one changes.
		foreach ( array( 'save_post', 'before_delete_post', 'wp_trash_post', 'untrashed_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_memo_for_post' ) );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_memo_for_meta' ), 10, 2 );
		}   }

	/**
	 * Register the club post type: private, listed in the plugin's menu.
	 */
	public static function register() {
		wp_cache_add_non_persistent_groups( self::MEMO_GROUP ); // Kept for the request only.
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Clubs', 'chess-army-knife' ),
					'singular_name'      => __( 'Club', 'chess-army-knife' ),
					'add_new'            => __( 'Add Club', 'chess-army-knife' ),
					'add_new_item'       => __( 'Add New Club', 'chess-army-knife' ),
					'edit_item'          => __( 'Edit Club', 'chess-army-knife' ),
					'search_items'       => __( 'Search Clubs', 'chess-army-knife' ),
					'not_found'          => __( 'No clubs found.', 'chess-army-knife' ),
					'not_found_in_trash' => __( 'No clubs found in the Trash.', 'chess-army-knife' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => false, // Listed in the plugin's menu (see Chess_Army_Knife_Menu).
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'capabilities' => Chess_Army_Knife_Memberships::post_capabilities( Chess_Army_Knife_Teams::CAPABILITY ),
			)
		);
	}

	/* -------------------------------------------------------------
	 * Pure helpers
	 * ------------------------------------------------------------- */

	/**
	 * A team name without its team number or letter: "Central Birmingham-2" is "Central Birmingham".
	 *
	 * @param string $name Team name.
	 * @return string
	 */
	public static function stem( $name ) {
		$name = trim( (string) $name );
		$stem = preg_replace( '/[\s\-]+(?:\(?\d+\)?|[A-Z])$/u', '', $name );

		return '' !== trim( (string) $stem ) ? trim( $stem ) : $name;
	}

	/**
	 * Suggest which team names belong to one club. Names that differ only by a team number or
	 * letter are one club. Beyond that, names that start with the same word (Stroud Otters,
	 * Stroud Badgers) are suggested together. It is only a suggestion: the admin confirms it.
	 *
	 * @param string[] $names Team names.
	 * @return array[] Each { name (suggested club name), teams }, by name.
	 */
	public static function suggest_groups( array $names ) {
		$by_stem = array();
		foreach ( array_unique( array_map( 'trim', $names ) ) as $name ) {
			if ( '' === $name ) {
				continue;
			}
			$by_stem[ strtolower( self::stem( $name ) ) ]['stem']    = self::stem( $name );
			$by_stem[ strtolower( self::stem( $name ) ) ]['teams'][] = $name;
		}

		/**
		 * Filter the first words that say nothing about which club a team is in.
		 *
		 * @param string[] $words Lower case words.
		 */
		$too_common = (array) apply_filters( 'Chess_Army_Knife_club_stop_words', array( 'the', 'city', 'north', 'south', 'east', 'west', 'new', 'old', 'royal', 'st', 'saint', 'united', 'town', 'chess' ) );

		$by_word = array();
		foreach ( $by_stem as $key => $group ) {
			$word = strtolower( strtok( $group['stem'], " \t-" ) );
			if ( strlen( $word ) < 3 || in_array( $word, $too_common, true ) ) {
				$word = '';
			}
			$by_word[ '' !== $word ? 'w:' . $word : 's:' . $key ][ $key ] = $group;
		}

		$groups = array();
		foreach ( $by_word as $key => $stems ) {
			$teams = array();
			foreach ( $stems as $group ) {
				$teams = array_merge( $teams, $group['teams'] );
			}
			sort( $teams, SORT_NATURAL | SORT_FLAG_CASE );

			// One stem is named by it; several share a first word, which names them.
			$first    = reset( $stems );
			$groups[] = array(
				'name'  => 1 === count( $stems ) ? $first['stem'] : ucfirst( strtok( $first['stem'], " \t-" ) ),
				'teams' => $teams,
			);
		}

		usort(
			$groups,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $groups;
	}

	/* -------------------------------------------------------------
	 * The directory
	 * ------------------------------------------------------------- */

	/** Object cache group holding the list from all() for the request. */
	const MEMO_GROUP = 'chess_army_knife_memo';

	/**
	 * Forget the kept list, because a post of this type changed.
	 *
	 * @param int $post_id Post id.
	 */
	public static function flush_memo_for_post( $post_id ) {
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			wp_cache_delete( 'clubs', self::MEMO_GROUP );
		}
	}

	/**
	 * Forget the kept list, because a post of this type had a meta value changed.
	 *
	 * @param int $meta_id Meta id.
	 * @param int $post_id Post id.
	 */
	public static function flush_memo_for_meta( $meta_id, $post_id ) {
		self::flush_memo_for_post( $post_id );
	}

	/**
	 * Every club in the directory.
	 *
	 * @return array[] Each { id, name, venue, map_url, what3words, teams }.
	 */
	public static function all() {
		$kept = wp_cache_get( 'clubs', self::MEMO_GROUP );
		if ( is_array( $kept ) ) {
			return $kept;
		}

		$clubs = array();
		foreach ( get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		) as $post ) {
			$clubs[] = self::data( $post );
		}
		wp_cache_set( 'clubs', $clubs, self::MEMO_GROUP );

		return $clubs;
	}

	/**
	 * One club as data.
	 *
	 * @param WP_Post $post Club post.
	 * @return array { id, name, venue, map_url, what3words, teams }.
	 */
	public static function data( $post ) {
		return array(
			'id'         => (int) $post->ID,
			'name'       => get_the_title( $post ),
			'venue'      => (string) get_post_meta( $post->ID, self::META_VENUE, true ),
			'map_url'    => (string) get_post_meta( $post->ID, self::META_MAP, true ),
			'what3words' => (string) get_post_meta( $post->ID, self::META_W3W, true ),
			'teams'      => array_map( 'strval', get_post_meta( $post->ID, self::META_TEAM, false ) ),
		);
	}

	/**
	 * The club a team name belongs to.
	 *
	 * @param string $team Team name as the LMS gives it.
	 * @return array|null The club (see data()), or null if no club has the team.
	 */
	public static function find_by_team( $team ) {
		$team = trim( (string) $team );
		if ( '' === $team ) {
			return null;
		}

		foreach ( self::all() as $club ) {
			foreach ( $club['teams'] as $name ) {
				if ( 0 === strcasecmp( trim( $name ), $team ) ) {
					return $club;
				}
			}
		}

		return null;
	}

	/**
	 * The venue of the club a team plays for, when that is where the team is at home.
	 *
	 * @param string $team Team name as the LMS gives it.
	 * @return array|null { location, map_url, what3words }, or null if there is no club or it has no venue.
	 */
	public static function venue_of_team( $team ) {
		$club = self::find_by_team( $team );
		if ( ! $club || '' === $club['venue'] . $club['map_url'] . $club['what3words'] ) {
			return null;
		}

		return array(
			'location'   => $club['venue'],
			'map_url'    => $club['map_url'],
			'what3words' => $club['what3words'],
		);
	}

	/* -------------------------------------------------------------
	 * Team names seen
	 * ------------------------------------------------------------- */

	/**
	 * Remember team names an import has seen.
	 *
	 * @param string[] $names Team names.
	 */
	public static function note_seen( array $names ) {
		$seen    = self::seen();
		$changed = false;

		foreach ( $names as $name ) {
			$name = trim( (string) $name );
			if ( '' !== $name && ! isset( $seen[ strtolower( $name ) ] ) ) {
				$seen[ strtolower( $name ) ] = $name;
				$changed                     = true;
			}
		}

		if ( $changed ) {
			update_option( self::SEEN_OPTION, $seen, false );
		}
	}

	/**
	 * Every team name seen.
	 *
	 * @return string[] Lower case name => name as written.
	 */
	public static function seen() {
		$seen = get_option( self::SEEN_OPTION, array() );

		return is_array( $seen ) ? $seen : array();
	}

	/**
	 * The team names the club's own teams play under: they never need a club.
	 *
	 * @return string[] Lower case.
	 */
	protected static function own_team_names() {
		$names = array();
		foreach ( Chess_Army_Knife_Settings::get_club_teams() as $entry ) {
			$names[] = strtolower( trim( $entry['team'] ) );
		}
		foreach ( Chess_Army_Knife_Teams::all() as $team ) {
			$names[] = strtolower( trim( $team['name'] ) );
		}

		return array_filter( $names );
	}

	/**
	 * Team names seen that are not the club's own and are in no club yet.
	 *
	 * @return string[] Names as written, in order.
	 */
	public static function unsorted() {
		$assigned = array();
		foreach ( self::all() as $club ) {
			foreach ( $club['teams'] as $name ) {
				$assigned[ strtolower( trim( $name ) ) ] = true;
			}
		}
		$own = array_flip( self::own_team_names() );

		$names = array();
		foreach ( self::seen() as $key => $name ) {
			if ( ! isset( $assigned[ $key ] ) && ! isset( $own[ $key ] ) ) {
				$names[] = $name;
			}
		}
		natcasesort( $names );

		return array_values( $names );
	}

	/* -------------------------------------------------------------
	 * The club edit screen
	 * ------------------------------------------------------------- */

	/**
	 * Add the details box.
	 */
	public static function add_meta_box() {
		add_meta_box( 'chess_army_club_details', __( 'Club details', 'chess-army-knife' ), array( __CLASS__, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * The fields shared by the club box and the sorting form.
	 *
	 * @param string $prefix Prefix for the ids, so each form's fields are distinct.
	 * @param array  $club   Values: venue, map_url, what3words.
	 */
	protected static function render_venue_fields( $prefix, array $club ) {
		?>
		<p>
			<label for="<?php echo esc_attr( $prefix ); ?>-venue"><?php esc_html_e( 'Where do they play? (venue name and address)', 'chess-army-knife' ); ?></label><br />
			<input type="text" id="<?php echo esc_attr( $prefix ); ?>-venue" name="club_venue" value="<?php echo esc_attr( $club['venue'] ); ?>" class="regular-text" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $prefix ); ?>-map"><?php esc_html_e( 'Google Maps link (optional)', 'chess-army-knife' ); ?></label><br />
			<input type="url" id="<?php echo esc_attr( $prefix ); ?>-map" name="club_map" value="<?php echo esc_attr( $club['map_url'] ); ?>" class="regular-text" placeholder="https://maps.app.goo.gl/..." />
		</p>
		<p>
			<label for="<?php echo esc_attr( $prefix ); ?>-w3w"><?php esc_html_e( 'what3words address (optional)', 'chess-army-knife' ); ?></label><br />
			<input type="text" id="<?php echo esc_attr( $prefix ); ?>-w3w" name="club_w3w" value="<?php echo esc_attr( $club['what3words'] ); ?>" placeholder="index.home.raft" />
		</p>
		<?php
	}

	/**
	 * Draw the details box.
	 *
	 * @param WP_Post $post Club being edited.
	 */
	public static function render_meta_box( $post ) {
		$club = self::data( $post );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		self::render_venue_fields( 'cak-club', $club );
		?>
		<p>
			<label for="cak-club-teams"><?php esc_html_e( 'Team names in the LMS, one on each line', 'chess-army-knife' ); ?></label><br />
			<textarea id="cak-club-teams" name="club_teams" rows="5" class="large-text" aria-describedby="cak-club-teams-help"><?php echo esc_textarea( implode( "\n", $club['teams'] ) ); ?></textarea>
			<span id="cak-club-teams-help" class="description"><?php esc_html_e( 'Exactly as the LMS spells them, for example "Stroud Otters". When one of these teams is at home, the fixture is held at the venue above.', 'chess-army-knife' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Clean the venue fields of a submitted form.
	 *
	 * @param array $input Submitted values (unslashed).
	 * @return array { venue, map_url, what3words }.
	 */
	protected static function clean_venue( array $input ) {
		return array(
			'venue'      => isset( $input['club_venue'] ) ? sanitize_text_field( $input['club_venue'] ) : '',
			'map_url'    => isset( $input['club_map'] ) ? Chess_Army_Knife_Events::clean_map_url( sanitize_text_field( $input['club_map'] ) ) : '',
			'what3words' => isset( $input['club_w3w'] ) ? Chess_Army_Knife_Events::clean_what3words( sanitize_text_field( $input['club_w3w'] ) ) : '',
		);
	}

	/**
	 * Store a club's venue and team names.
	 *
	 * @param int      $post_id Club id.
	 * @param array    $venue   { venue, map_url, what3words }.
	 * @param string[] $teams   Team names; the club's teams are set to exactly these.
	 */
	protected static function store( $post_id, array $venue, array $teams ) {
		foreach ( array(
			self::META_VENUE => $venue['venue'],
			self::META_MAP   => $venue['map_url'],
			self::META_W3W   => $venue['what3words'],
		) as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		delete_post_meta( $post_id, self::META_TEAM );
		foreach ( array_unique( array_filter( array_map( 'trim', $teams ) ) ) as $team ) {
			add_post_meta( $post_id, self::META_TEAM, $team );
		}
	}

	/**
	 * Save the details box.
	 *
	 * @param int $post_id Club id.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is cleaned below.
		$teams = isset( $input['club_teams'] ) ? preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( $input['club_teams'] ) ) : array();

		self::store( $post_id, self::clean_venue( $input ), $teams );
	}

	/**
	 * Say on the club list how many team names are waiting to be sorted.
	 */
	public static function list_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . self::POST_TYPE !== $screen->id || ! Chess_Army_Knife_Teams::user_can_manage() ) {
			return;
		}

		$count = count( self::unsorted() );
		if ( ! $count ) {
			return;
		}

		echo '<div class="notice notice-info"><p>' . esc_html(
			/* translators: %d: number of team names */
			sprintf( _n( '%d team name seen in the LMS is not in a club yet.', '%d team names seen in the LMS are not in a club yet.', $count, 'chess-army-knife' ), $count )
		) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Sort them into clubs', 'chess-army-knife' ) . '</a></p></div>';
	}

	/* -------------------------------------------------------------
	 * Sorting teams into clubs
	 * ------------------------------------------------------------- */

	/**
	 * Draw the Sort Clubs screen: each suggested group of team names, to turn into a club
	 * or add to one that exists.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Teams::user_can_manage(), __( 'Sort Clubs', 'chess-army-knife' ), 'teams' ) ) {
			return;
		}

		$groups = self::suggest_groups( self::unsorted() );
		$clubs  = self::all();
		$done   = isset( $_GET['cak_club_done'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the outcome of a form.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sort Clubs', 'chess-army-knife' ); ?></h1>
			<p><?php esc_html_e( 'The LMS does not say where teams play. Import Events notes every team name it sees. Tell the plugin which names belong to one club and where that club plays, and away fixtures are given that venue the next time you import. The names are suggestions: untick any that do not belong. Nothing here is shown on your website except the venue on events.', 'chess-army-knife' ); ?></p>
			<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ); ?>"><?php esc_html_e( 'See all clubs', 'chess-army-knife' ); ?></a></p>

			<?php if ( $done ) : ?>
				<div class="notice notice-success" role="status"><p><?php esc_html_e( 'Saved. Import Events again to give the fixtures their venues.', 'chess-army-knife' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $groups ) : ?>
				<p><?php esc_html_e( 'Every team name seen is in a club, or none has been seen yet. Run Import Events to look for more.', 'chess-army-knife' ); ?></p>
			<?php endif; ?>

			<?php foreach ( $groups as $index => $group ) : ?>
				<?php $id = 'cak-sort-' . (int) $index; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cak-sort-club">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SORT ); ?>" />
					<?php wp_nonce_field( self::ACTION_SORT ); ?>
					<fieldset>
						<legend><strong><?php echo esc_html( $group['name'] ); ?></strong></legend>
						<p><?php esc_html_e( 'Teams in this club:', 'chess-army-knife' ); ?></p>
						<ul>
							<?php foreach ( $group['teams'] as $team_index => $team ) : ?>
								<li>
									<label>
										<input type="checkbox" name="teams[]" value="<?php echo esc_attr( $team ); ?>" checked="checked" />
										<?php echo esc_html( $team ); ?>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>
						<p>
							<label for="<?php echo esc_attr( $id ); ?>-existing"><?php esc_html_e( 'Add them to a club you have already made', 'chess-army-knife' ); ?></label><br />
							<select id="<?php echo esc_attr( $id ); ?>-existing" name="existing_club">
								<option value="0"><?php esc_html_e( 'No: make a new club', 'chess-army-knife' ); ?></option>
								<?php foreach ( $clubs as $club ) : ?>
									<option value="<?php echo (int) $club['id']; ?>"><?php echo esc_html( $club['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="<?php echo esc_attr( $id ); ?>-name"><?php esc_html_e( 'Name of the new club', 'chess-army-knife' ); ?></label><br />
							<input type="text" id="<?php echo esc_attr( $id ); ?>-name" name="club_name" value="<?php echo esc_attr( $group['name'] ); ?>" class="regular-text" />
						</p>
						<?php
						self::render_venue_fields(
							$id,
							array(
								'venue'      => '',
								'map_url'    => '',
								'what3words' => '',
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'You can leave the venue blank for now. When adding to an existing club, its own venue is kept and the boxes above are ignored.', 'chess-army-knife' ); ?></p>
						<p>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save club', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $group['name'] ); ?></span></button>
						</p>
					</fieldset>
					<hr />
				</form>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Handle one group: make a club of the ticked teams, or add them to an existing club.
	 */
	public static function handle_sort() {
		check_admin_referer( self::ACTION_SORT );
		if ( ! Chess_Army_Knife_Teams::user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}

		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked above; each value is cleaned below.
		$teams = isset( $input['teams'] ) && is_array( $input['teams'] ) ? array_map( 'sanitize_text_field', $input['teams'] ) : array();

		// Only names that really were seen can be put in a club.
		$seen  = self::seen();
		$teams = array_values(
			array_filter(
				$teams,
				function ( $team ) use ( $seen ) {
					return isset( $seen[ strtolower( trim( $team ) ) ] );
				}
			)
		);

		if ( $teams ) {
			self::add_teams_to_club( $teams, isset( $input['existing_club'] ) ? absint( $input['existing_club'] ) : 0, isset( $input['club_name'] ) ? sanitize_text_field( $input['club_name'] ) : '', self::clean_venue( $input ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE,
					'cak_club_done' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Put team names in an existing club, or in a new one.
	 *
	 * @param string[] $teams    Team names.
	 * @param int      $existing An existing club to add them to, or 0 for a new club.
	 * @param string   $name     Name for a new club.
	 * @param array    $venue    { venue, map_url, what3words } for a new club.
	 * @return int|WP_Error The club's id.
	 */
	public static function add_teams_to_club( array $teams, $existing, $name, array $venue ) {
		$club = $existing ? get_post( $existing ) : null;
		if ( $club && self::POST_TYPE === $club->post_type ) {
			// The club keeps its own venue.
			$current = self::data( $club );
			self::store(
				$club->ID,
				array(
					'venue'      => $current['venue'],
					'map_url'    => $current['map_url'],
					'what3words' => $current['what3words'],
				),
				array_merge( $current['teams'], $teams )
			);
			return (int) $club->ID;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '' !== trim( $name ) ? $name : self::stem( reset( $teams ) ),
			),
			true
		);
		if ( ! is_wp_error( $id ) ) {
			self::store( $id, $venue, $teams );
		}

		return $id;
	}
}

Chess_Army_Knife_Clubs::init();
