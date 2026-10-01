<?php
/**
 * The club's policies as ordinary, editable pages: the club data policy, the
 * safeguarding policy and the privacy policy.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

/**
 * Each policy is a real WordPress page, so a club can edit it in the block
 * editor, publish it, put it in a menu and link to it from anywhere on the site.
 * The plugin writes the starting text once, as a draft, and never overwrites a
 * page again unless someone asks for the plugin's wording to be put back.
 */
class Chess_Army_Knife_Policies {

	const PAGE           = 'chess-army-knife-policies';
	const OPTION         = 'Chess_Army_Knife_policy_pages';
	const ACTION_CREATE  = 'chess_army_knife_policy_create';
	const ACTION_RESTORE = 'chess_army_knife_policy_restore';
	const SHORTCODE      = 'chess_army_policy_link';
	const PRIVACY_OPTION = 'wp_page_for_privacy_policy';
	const REQUIRED_CAP   = 'edit_pages';

	/**
	 * Hook up the screen's actions and the link shortcode.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION_CREATE, array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_' . self::ACTION_RESTORE, array( __CLASS__, 'handle_restore' ) );
		add_action( 'init', array( __CLASS__, 'register_shortcode' ) );
	}

	/**
	 * Register the link shortcode.
	 */
	public static function register_shortcode() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'link_shortcode' ) );
	}

	/**
	 * The policies, by short name.
	 *
	 * @return array[] Each { title, slug, description }.
	 */
	public static function policies() {
		return array(
			'data'         => array(
				'title'       => __( 'Club data policy', 'chess-army-knife' ),
				'slug'        => 'club-data-policy',
				'description' => __( 'How the club handles its members\' personal data.', 'chess-army-knife' ),
			),
			'safeguarding' => array(
				'title'       => __( 'Safeguarding policy', 'chess-army-knife' ),
				'slug'        => 'safeguarding-policy',
				'description' => __( 'How the club keeps children and adults at risk safe, and what to do if you are worried.', 'chess-army-knife' ),
			),
			'privacy'      => array(
				'title'       => __( 'Privacy policy', 'chess-army-knife' ),
				'slug'        => 'privacy-policy',
				'description' => __( 'The whole site\'s privacy policy. If the site already has one, this is that page.', 'chess-army-knife' ),
			),
		);
	}

	/**
	 * Which page each policy lives on.
	 *
	 * @return array[] By policy: { id, adopted } where adopted means the page was there before the plugin and is not the plugin's to rewrite.
	 */
	protected static function saved() {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * The page a policy lives on, if it still exists.
	 *
	 * @param string $key Policy, a key of policies().
	 * @return WP_Post|null
	 */
	public static function page( $key ) {
		$saved = self::saved();
		if ( empty( $saved[ $key ]['id'] ) ) {
			return null;
		}
		$post = get_post( (int) $saved[ $key ]['id'] );
		return $post && 'page' === $post->post_type && 'trash' !== $post->post_status ? $post : null;
	}

	/**
	 * Whether the plugin may rewrite a policy's page: not if it was already the site's own.
	 *
	 * @param string $key Policy, a key of policies().
	 * @return bool
	 */
	public static function can_restore( $key ) {
		$saved = self::saved();
		return null !== self::page( $key ) && empty( $saved[ $key ]['adopted'] );
	}

	/**
	 * The address of a policy's page, if it is published.
	 *
	 * @param string $key Policy, a key of policies().
	 * @return string Empty if there is no published page.
	 */
	public static function url( $key ) {
		$post = self::page( $key );
		return $post && 'publish' === $post->post_status ? (string) get_permalink( $post ) : '';
	}

	/**
	 * A link to a policy: [chess_army_policy_link policy=data]. Used inside the starting text so
	 * links keep working when a page is published or its address changes. Until the page is
	 * published it is just the name.
	 *
	 * @param array $atts Shortcode attributes: policy.
	 * @return string
	 */
	public static function link_shortcode( $atts ) {
		$atts     = shortcode_atts( array( 'policy' => 'data' ), $atts, self::SHORTCODE );
		$policies = self::policies();
		$key      = isset( $policies[ $atts['policy'] ] ) ? $atts['policy'] : 'data';
		$title    = $policies[ $key ]['title'];
		$url      = self::url( $key );
		return '' === $url ? esc_html( $title ) : '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
	}

	/* -------------------------------------------------------------
	 * Writing the pages
	 * ------------------------------------------------------------- */

	/**
	 * Make a page for every policy that has none. Pages are drafts, so nothing goes public
	 * before the club has read it.
	 */
	public static function create_missing() {
		foreach ( array_keys( self::policies() ) as $key ) {
			if ( null === self::page( $key ) ) {
				self::create( $key );
			}
		}
	}

	/**
	 * Make the page for one policy, or adopt the site's own privacy policy page if it has one.
	 *
	 * @param string $key Policy, a key of policies().
	 * @return int|WP_Error The page id.
	 */
	public static function create( $key ) {
		$policies = self::policies();
		if ( ! isset( $policies[ $key ] ) ) {
			return new WP_Error( 'policy_unknown', __( 'That policy is not known.', 'chess-army-knife' ) );
		}

		$saved = self::saved();

		// A site that already has a privacy policy keeps it: it is the owner's text, so it is not rewritten.
		if ( 'privacy' === $key ) {
			$existing = get_post( (int) get_option( self::PRIVACY_OPTION ) );
			if ( $existing && 'page' === $existing->post_type && 'trash' !== $existing->post_status ) {
				$saved[ $key ] = array(
					'id'      => (int) $existing->ID,
					'adopted' => true,
				);
				update_option( self::OPTION, $saved );
				return (int) $existing->ID;
			}
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $policies[ $key ]['title'],
				'post_name'    => $policies[ $key ]['slug'],
				'post_content' => self::content( $key ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$saved[ $key ] = array(
			'id'      => (int) $id,
			'adopted' => false,
		);
		update_option( self::OPTION, $saved );

		// The site's privacy policy page, for the link WordPress and other plugins show.
		if ( 'privacy' === $key && ! get_option( self::PRIVACY_OPTION ) ) {
			update_option( self::PRIVACY_OPTION, (int) $id );
		}

		return (int) $id;
	}

	/**
	 * Put the plugin's wording back on a policy's page. The earlier text stays in the page's revisions.
	 *
	 * @param string $key Policy, a key of policies().
	 * @return int|WP_Error The page id.
	 */
	public static function restore( $key ) {
		$post = self::page( $key );
		if ( ! $post ) {
			return self::create( $key );
		}
		if ( ! self::can_restore( $key ) ) {
			return new WP_Error( 'policy_adopted', __( 'That page was on the site before the plugin, so the plugin will not rewrite it.', 'chess-army-knife' ) );
		}

		$id = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => self::content( $key ),
			),
			true
		);
		return is_wp_error( $id ) ? $id : (int) $id;
	}

	/* -------------------------------------------------------------
	 * The starting text
	 * ------------------------------------------------------------- */

	/**
	 * The starting text of a policy, as block editor markup.
	 *
	 * @param string $key Policy, a key of policies().
	 * @return string
	 */
	public static function content( $key ) {
		switch ( $key ) {
			case 'data':
				return self::blocks( Chess_Army_Knife_Membership_Privacy::policy_sections() );
			case 'safeguarding':
				return self::blocks( self::safeguarding_sections() );
			default:
				return self::privacy_content();
		}
	}

	/**
	 * Sections as block editor markup: a heading, then its paragraphs and an optional list.
	 *
	 * @param array[] $sections Each { heading, paragraphs, items? }, all plain text; the link shortcode in it still works.
	 * @return string
	 */
	public static function blocks( array $sections ) {
		$out = '';
		foreach ( $sections as $section ) {
			$out .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $section['heading'] ) . "</h2>\n<!-- /wp:heading -->\n\n";
			foreach ( isset( $section['paragraphs'] ) ? (array) $section['paragraphs'] : array() as $paragraph ) {
				$out .= "<!-- wp:paragraph -->\n<p>" . esc_html( $paragraph ) . "</p>\n<!-- /wp:paragraph -->\n\n";
			}
			if ( ! empty( $section['items'] ) ) {
				$out .= "<!-- wp:list -->\n<ul class=\"wp-block-list\">";
				foreach ( $section['items'] as $item ) {
					$out .= "<!-- wp:list-item -->\n<li>" . esc_html( $item ) . "</li>\n<!-- /wp:list-item -->";
				}
				$out .= "</ul>\n<!-- /wp:list -->\n\n";
			}
		}
		return trim( $out );
	}

	/**
	 * The starting text of the safeguarding policy. It is general: the club should read it,
	 * fill in the gaps and change it to match what it really does.
	 *
	 * @return array[] Each { heading, paragraphs, items? }.
	 */
	public static function safeguarding_sections() {
		$options = Chess_Army_Knife_Settings::get_options();
		$club    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$club    = '' !== $club ? $club : __( 'The club', 'chess-army-knife' );
		$name    = trim( (string) $options['safeguarding_officer'] );
		$email   = trim( (string) $options['safeguarding_email'] );

		if ( '' !== $name ) {
			/* translators: 1: the safeguarding officer's name */
			$officer = sprintf( __( 'The club\'s safeguarding officer is %1$s.', 'chess-army-knife' ), $name );
		} else {
			$officer = __( 'The club\'s safeguarding officer is [add the name of your safeguarding officer].', 'chess-army-knife' );
		}
		if ( '' !== $email ) {
			/* translators: %s: email address */
			$officer .= ' ' . sprintf( __( 'You can contact them at %s.', 'chess-army-knife' ), $email );
		} else {
			$officer .= ' ' . __( 'You can contact them at [add their email address or phone number].', 'chess-army-knife' );
		}

		$sections = array(
			array(
				'heading'    => __( 'Our promise', 'chess-army-knife' ),
				'paragraphs' => array(
					/* translators: %s: club name */
					sprintf( __( '%s wants everyone who plays chess with us to be safe and to enjoy it. Children and adults at risk have the right to be protected from harm, abuse and neglect. Everyone in the club has a part to play in this.', 'chess-army-knife' ), $club ),
				),
			),
			array(
				'heading'    => __( 'Who this policy is for', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'This policy is for everyone involved in the club: members, parents and guardians, volunteers, committee members, coaches, tournament controllers and visitors. It covers everything the club does, in person and online, including matches, tournaments and trips.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Our safeguarding officer', 'chess-army-knife' ),
				'paragraphs' => array(
					$officer,
					__( 'They take the lead on any concern and make sure this policy is followed.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'How we keep people safe', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'We do the following.', 'chess-army-knife' ),
				),
				'items'      => array(
					__( 'We choose carefully the adults who work with children or take them to events. They have the checks that the law and the English Chess Federation (ECF) ask for, such as a Disclosure and Barring Service (DBS) check.', 'chess-army-knife' ),
					__( 'We avoid leaving a child alone with one adult. Activities happen where other people can see.', 'chess-army-knife' ),
					__( 'We keep the contact details of a junior\'s parent or guardian, so we can reach them when we need to. [chess_army_policy_link policy=data] explains how we look after them.', 'chess-army-knife' ),
					__( 'We expect everyone to treat each other with respect. Bullying, harassment, discrimination and abuse are not allowed.', 'chess-army-knife' ),
					__( 'We talk to children about club business openly. We copy in a parent or guardian, and we use club channels, not private messages.', 'chess-army-knife' ),
					__( 'We only take and share photos of children with the agreement of a parent or guardian.', 'chess-army-knife' ),
					__( 'We follow the ECF\'s safeguarding policy at ECF events and for ECF-rated play.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Online play and messages', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'Online games and chat can carry risks. An adult with the right checks should run any online club event for juniors, with a parent or guardian\'s agreement. Adults must not ask children for private contact.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'If you are worried', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'If a child is in immediate danger, call 999.', 'chess-army-knife' ),
					__( 'If you are worried about a child or an adult, tell the safeguarding officer as soon as you can. If your worry is about the safeguarding officer, tell another member of the committee.', 'chess-army-knife' ),
					__( 'You can also call the NSPCC helpline on 0808 800 5000. Children can call Childline for free on 0800 1111.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'What happens next', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'We take every concern seriously. The safeguarding officer will listen, write down what was said and decide with the committee what to do. They may need to tell the local authority children\'s services, the police or the ECF.', 'chess-army-knife' ),
					__( 'We keep what people tell us private, and share it only with people who need to know. We cannot promise to keep a concern secret if a child may be at risk.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Keeping records', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'We keep records of concerns safely, for as long as the law and guidance say. Only the safeguarding officer and people who need them can see them.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Reviewing this policy', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'The committee reviews this policy at least once a year, and after any serious concern. It was last reviewed on [add the date].', 'chess-army-knife' ),
				),
			),
		);

		/**
		 * Filter the sections of the safeguarding policy's starting text.
		 *
		 * @param array[] $sections Each { heading, paragraphs, items? }.
		 */
		return (array) apply_filters( 'Chess_Army_Knife_safeguarding_sections', $sections );
	}

	/**
	 * The starting text of the privacy policy: WordPress's own suggested text, which gathers
	 * what every plugin on the site says about the data it holds, then a pointer to the club
	 * data policy.
	 *
	 * @return string
	 */
	public static function privacy_content() {
		$content = '';
		$file    = ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		if ( file_exists( $file ) ) {
			require_once $file;
			if ( class_exists( 'WP_Privacy_Policy_Content' ) && method_exists( 'WP_Privacy_Policy_Content', 'get_default_content' ) ) {
				$content = (string) WP_Privacy_Policy_Content::get_default_content( false, true );
			}
		}

		// The site's name and address are all a general text can start from; the rest is for the owner to write.
		if ( '' === trim( $content ) ) {
			$content = self::blocks(
				array(
					array(
						'heading'    => __( 'Who we are', 'chess-army-knife' ),
						'paragraphs' => array(
							/* translators: %s: site address */
							sprintf( __( 'Our website address is: %s.', 'chess-army-knife' ), home_url() ),
						),
					),
					array(
						'heading'    => __( 'What personal data we collect and why', 'chess-army-knife' ),
						'paragraphs' => array(
							__( '[Describe what this website collects, for example from comments, contact forms and cookies, and why.]', 'chess-army-knife' ),
						),
					),
				)
			);
		}

		return $content . "\n\n" . self::blocks(
			array(
				array(
					'heading'    => __( 'Club membership', 'chess-army-knife' ),
					'paragraphs' => array(
						__( 'How the club handles its members\' personal data is explained in our [chess_army_policy_link policy=data]. How we keep children and adults at risk safe is explained in our [chess_army_policy_link policy=safeguarding].', 'chess-army-knife' ),
					),
				),
			)
		);
	}

	/* -------------------------------------------------------------
	 * The screen
	 * ------------------------------------------------------------- */

	/**
	 * A short name for a page's status.
	 *
	 * @param WP_Post|null $post The page.
	 * @return string
	 */
	protected static function status_label( $post ) {
		if ( ! $post ) {
			return __( 'No page yet', 'chess-army-knife' );
		}
		$labels = array(
			'publish' => __( 'Published', 'chess-army-knife' ),
			'draft'   => __( 'Draft: not public yet', 'chess-army-knife' ),
			'pending' => __( 'Waiting for review', 'chess-army-knife' ),
			'private' => __( 'Private', 'chess-army-knife' ),
			'future'  => __( 'Scheduled', 'chess-army-knife' ),
		);
		return isset( $labels[ $post->post_status ] ) ? $labels[ $post->post_status ] : $post->post_status;
	}

	/**
	 * The address of this screen.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	protected static function screen_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Draw the Policies screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( current_user_can( self::REQUIRED_CAP ), __( 'Policies', 'chess-army-knife' ), 'pages' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the outcome of an action.
		$done  = isset( $_GET['cak_policy_done'] ) ? sanitize_key( wp_unslash( $_GET['cak_policy_done'] ) ) : '';
		$error = isset( $_GET['cak_policy_error'] ) ? sanitize_key( wp_unslash( $_GET['cak_policy_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Policies', 'chess-army-knife' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Each policy is an ordinary page. Edit it like any other page, publish it, put it in a menu and link to it from anywhere. The plugin wrote the starting text once, as a draft; it never changes a page again unless you ask it to put its wording back. Read each policy and change it to match what your club really does. The text is a starting point, not legal advice.', 'chess-army-knife' ); ?>
			</p>

			<?php if ( 'restored' === $done ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'The plugin\'s wording is back on the page. The earlier text is kept in the page\'s revisions.', 'chess-army-knife' ); ?></p></div>
			<?php elseif ( 'created' === $done ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'The page has been made, as a draft.', 'chess-army-knife' ); ?></p></div>
			<?php elseif ( '' !== $error ) : ?>
				<div class="notice notice-error" role="alert"><p><?php echo esc_html( 'confirm' === $error ? __( 'Tick the box to say you understand, then try again.', 'chess-army-knife' ) : __( 'That could not be done.', 'chess-army-knife' ) ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped">
				<caption class="screen-reader-text"><?php esc_html_e( 'The club\'s policies', 'chess-army-knife' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Policy', 'chess-army-knife' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Page', 'chess-army-knife' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::policies() as $key => $policy ) : ?>
						<?php $post = self::page( $key ); ?>
						<tr>
							<th scope="row">
								<?php echo esc_html( $policy['title'] ); ?>
								<br /><span class="description"><?php echo esc_html( $policy['description'] ); ?></span>
							</th>
							<td>
								<?php echo esc_html( self::status_label( $post ) ); ?>
								<?php if ( $post && ! self::can_restore( $key ) ) : ?>
									<br /><span class="description"><?php esc_html_e( 'This page was already on your site, so the plugin leaves its text alone.', 'chess-army-knife' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $post ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $policy['title'] ); ?></span></a>
									|
									<a href="<?php echo esc_url( 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post ) ); ?>"><?php echo esc_html( 'publish' === $post->post_status ? __( 'View', 'chess-army-knife' ) : __( 'Preview', 'chess-army-knife' ) ); ?><span class="screen-reader-text"> <?php echo esc_html( $policy['title'] ); ?></span></a>
									<?php if ( self::can_restore( $key ) ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESTORE ); ?>" />
											<input type="hidden" name="policy" value="<?php echo esc_attr( $key ); ?>" />
											<?php wp_nonce_field( self::ACTION_RESTORE . '_' . $key ); ?>
											<p>
												<label>
													<input type="checkbox" name="confirm" value="1" required />
													<?php
													/* translators: %s: policy name */
													echo esc_html( sprintf( __( 'Replace the text of the %s page with the plugin\'s wording', 'chess-army-knife' ), $policy['title'] ) );
													?>
												</label>
											</p>
											<button type="submit" class="button"><?php esc_html_e( 'Put the plugin\'s wording back', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $policy['title'] ); ?></span></button>
										</form>
									<?php endif; ?>
								<?php else : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CREATE ); ?>" />
										<input type="hidden" name="policy" value="<?php echo esc_attr( $key ); ?>" />
										<?php wp_nonce_field( self::ACTION_CREATE . '_' . $key ); ?>
										<button type="submit" class="button button-primary"><?php esc_html_e( 'Make the page', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $policy['title'] ); ?></span></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description">
				<?php esc_html_e( 'The data policy page is written from your settings (how long details are kept, and who to contact). If you change those later, edit the page, or put the plugin\'s wording back. The safeguarding officer\'s name and email are also set under Settings.', 'chess-army-knife' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle "Make the page".
	 */
	public static function handle_create() {
		$key = isset( $_POST['policy'] ) ? sanitize_key( wp_unslash( $_POST['policy'] ) ) : '';
		check_admin_referer( self::ACTION_CREATE . '_' . $key );
		self::require_permission();

		$result = self::create( $key );
		wp_safe_redirect( self::screen_url( is_wp_error( $result ) ? array( 'cak_policy_error' => 'failed' ) : array( 'cak_policy_done' => 'created' ) ) );
		exit;
	}

	/**
	 * Handle "Put the plugin's wording back".
	 */
	public static function handle_restore() {
		$key = isset( $_POST['policy'] ) ? sanitize_key( wp_unslash( $_POST['policy'] ) ) : '';
		check_admin_referer( self::ACTION_RESTORE . '_' . $key );
		self::require_permission();

		if ( empty( $_POST['confirm'] ) ) {
			wp_safe_redirect( self::screen_url( array( 'cak_policy_error' => 'confirm' ) ) );
			exit;
		}

		$page = self::page( $key );
		if ( $page && ! current_user_can( 'edit_post', $page->ID ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}

		$result = self::restore( $key );
		wp_safe_redirect( self::screen_url( is_wp_error( $result ) ? array( 'cak_policy_error' => 'failed' ) : array( 'cak_policy_done' => 'restored' ) ) );
		exit;
	}

	/**
	 * Stop anyone who may not edit pages.
	 */
	protected static function require_permission() {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}
	}
}

Chess_Army_Knife_Policies::init();
