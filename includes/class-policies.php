<?php
/**
 * The club's policies as ordinary, editable pages: the club data policy, the
 * safeguarding policy and the privacy policy.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

/**
 * Each policy is a real WordPress page, so a club can edit it in the block editor,
 * publish it, put it in a menu and link to it from anywhere on the site.
 *
 * Policies are specific to each club, so nothing is made without being asked. The
 * Policies screen asks, for each one, whether the club wants it, and for the few details
 * the starting text needs. The plugin then writes the text once, as a draft, and the policy
 * stays "not finished" until someone has read it and marked it as reviewed. The plugin
 * never overwrites a page again unless asked to put its wording back.
 */
class Chess_Army_Knife_Policies {

	const PAGE           = 'chess-army-knife-policies';
	const OPTION         = 'Chess_Army_Knife_policy_pages';
	const ACTION_SETUP   = 'chess_army_knife_policy_setup';
	const ACTION_REVIEW  = 'chess_army_knife_policy_review';
	const ACTION_RESTORE = 'chess_army_knife_policy_restore';
	const SHORTCODE      = 'chess_army_policy_link';
	const PRIVACY_OPTION = 'wp_page_for_privacy_policy';
	const REQUIRED_CAP   = 'edit_pages';

	/**
	 * Hook up the screen's actions, the link shortcode and the reminder on a policy's own page.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION_SETUP, array( __CLASS__, 'handle_setup' ) );
		add_action( 'admin_post_' . self::ACTION_REVIEW, array( __CLASS__, 'handle_review' ) );
		add_action( 'admin_post_' . self::ACTION_RESTORE, array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_notices', array( __CLASS__, 'edit_screen_notice' ) );
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
	 * What the club has decided about each policy.
	 *
	 * @return array[] By policy: { choice: 'use' or 'skip', id, adopted, reviewed_at }. adopted means the page was
	 *                 on the site before the plugin and is not the plugin's to rewrite or to ask about.
	 */
	protected static function saved() {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Remember something about one policy.
	 *
	 * @param string $key    Policy, a key of policies().
	 * @param array  $values Values to set.
	 */
	protected static function remember( $key, array $values ) {
		$saved         = self::saved();
		$saved[ $key ] = array_merge(
			array(
				'choice'      => '',
				'id'          => 0,
				'adopted'     => false,
				'reviewed_at' => 0,
			),
			isset( $saved[ $key ] ) ? $saved[ $key ] : array(),
			$values
		);
		update_option( self::OPTION, $saved );
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
	 * Where a policy stands.
	 *
	 * @param string $key Policy, a key of policies().
	 * @return string 'undecided' (not asked yet, or the page was removed), 'skipped' (the club will do it itself),
	 *                'review' (a page exists that nobody has marked as reviewed) or 'done'.
	 */
	public static function status( $key ) {
		$saved = self::saved();
		if ( isset( $saved[ $key ]['choice'] ) && 'skip' === $saved[ $key ]['choice'] ) {
			return 'skipped';
		}
		if ( null === self::page( $key ) ) {
			return 'undecided';
		}
		return ! empty( $saved[ $key ]['adopted'] ) || ! empty( $saved[ $key ]['reviewed_at'] ) ? 'done' : 'review';
	}

	/**
	 * When a policy was marked as reviewed.
	 *
	 * @param string $key Policy, a key of policies().
	 * @return int Unix time, or 0 if it has not been.
	 */
	public static function reviewed_at( $key ) {
		$saved = self::saved();
		return isset( $saved[ $key ]['reviewed_at'] ) ? (int) $saved[ $key ]['reviewed_at'] : 0;
	}

	/**
	 * How many policies still need the club's attention: not yet decided, or not yet reviewed.
	 *
	 * @return int
	 */
	public static function attention_count() {
		$count = 0;
		foreach ( array_keys( self::policies() ) as $key ) {
			if ( in_array( self::status( $key ), array( 'undecided', 'review' ), true ) ) {
				++$count;
			}
		}
		return $count;
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
	 * Make the page for one policy, or adopt the site's own privacy policy page if it has one.
	 * The page is a draft, and the policy is marked as needing review.
	 *
	 * @param string $key Policy, a key of policies().
	 * @return int|WP_Error The page id.
	 */
	public static function create( $key ) {
		$policies = self::policies();
		if ( ! isset( $policies[ $key ] ) ) {
			return new WP_Error( 'policy_unknown', __( 'That policy is not known.', 'chess-army-knife' ) );
		}

		// A site that already has a privacy policy keeps it: it is the owner's text, so it is not rewritten.
		if ( 'privacy' === $key ) {
			$existing = get_post( (int) get_option( self::PRIVACY_OPTION ) );
			if ( $existing && 'page' === $existing->post_type && 'trash' !== $existing->post_status ) {
				self::remember(
					$key,
					array(
						'choice'  => 'use',
						'id'      => (int) $existing->ID,
						'adopted' => true,
					)
				);
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

		self::remember(
			$key,
			array(
				'choice'      => 'use',
				'id'          => (int) $id,
				'adopted'     => false,
				'reviewed_at' => 0,
			)
		);

		// The site's privacy policy page, for the link WordPress and other plugins show.
		if ( 'privacy' === $key && ! get_option( self::PRIVACY_OPTION ) ) {
			update_option( self::PRIVACY_OPTION, (int) $id );
		}

		return (int) $id;
	}

	/**
	 * Put the plugin's wording back on a policy's page. The earlier text stays in the page's
	 * revisions, and the policy needs reviewing again.
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
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		self::remember( $key, array( 'reviewed_at' => 0 ) );
		return (int) $id;
	}

	/**
	 * Answer the question for one policy: the club wants it (with the details the text needs), or will do it itself.
	 *
	 * @param string $key     Policy, a key of policies().
	 * @param bool   $use     Whether the club wants the plugin to make the page.
	 * @param array  $details Values for the policy's fields(), by field name.
	 * @return int|true|WP_Error The page id when one was made, true when skipped.
	 */
	public static function set_up( $key, $use, array $details = array() ) {
		$policies = self::policies();
		if ( ! isset( $policies[ $key ] ) ) {
			return new WP_Error( 'policy_unknown', __( 'That policy is not known.', 'chess-army-knife' ) );
		}

		if ( ! $use ) {
			self::remember( $key, array( 'choice' => 'skip' ) );
			return true;
		}

		self::save_details( $key, $details );

		// Asking twice does not make a second page.
		$existing = self::page( $key );
		if ( $existing ) {
			self::remember( $key, array( 'choice' => 'use' ) );
			return (int) $existing->ID;
		}
		return self::create( $key );
	}

	/**
	 * Say whether the club has read a policy and found that it matches what the club does.
	 *
	 * @param string $key      Policy, a key of policies().
	 * @param bool   $reviewed True to mark it reviewed, false to ask for another review.
	 */
	public static function mark_reviewed( $key, $reviewed ) {
		self::remember( $key, array( 'reviewed_at' => $reviewed ? time() : 0 ) );
	}

	/**
	 * The details a policy's starting text asks the club for. They are saved in the plugin's settings,
	 * so they are also found there.
	 *
	 * @param string $key Policy, a key of policies().
	 * @return array[] Each { name, label, type, help }.
	 */
	public static function fields( $key ) {
		if ( 'data' === $key ) {
			return array(
				array(
					'name'  => 'data_contact_email',
					'label' => __( 'Who should people contact about their personal details? (an email address)', 'chess-army-knife' ),
					'type'  => 'email',
					'help'  => __( 'Use a club address rather than a personal one.', 'chess-army-knife' ),
				),
				array(
					'name'  => 'member_retention_months',
					'label' => __( 'How many months do you keep details after someone stops being a member?', 'chess-army-knife' ),
					'type'  => 'number',
					'help'  => __( 'Use 0 to keep them until the person asks you to delete them.', 'chess-army-knife' ),
				),
			);
		}
		if ( 'safeguarding' === $key ) {
			return array(
				array(
					'name'  => 'safeguarding_officer',
					'label' => __( 'Name of the club\'s safeguarding officer', 'chess-army-knife' ),
					'type'  => 'text',
					'help'  => '',
				),
				array(
					'name'  => 'safeguarding_email',
					'label' => __( 'Their email address', 'chess-army-knife' ),
					'type'  => 'email',
					'help'  => '',
				),
				array(
					'name'  => 'safeguarding_phone',
					'label' => __( 'Their phone number (optional)', 'chess-army-knife' ),
					'type'  => 'text',
					'help'  => '',
				),
			);
		}
		return array();
	}

	/**
	 * Save the details a policy asked for into the plugin's settings.
	 *
	 * @param string $key     Policy, a key of policies().
	 * @param array  $details Values by field name.
	 */
	protected static function save_details( $key, array $details ) {
		$options = Chess_Army_Knife_Settings::get_options();
		$changed = false;
		foreach ( self::fields( $key ) as $field ) {
			if ( isset( $details[ $field['name'] ] ) ) {
				$options[ $field['name'] ] = $details[ $field['name'] ];
				$changed                   = true;
			}
		}
		if ( $changed ) {
			update_option( Chess_Army_Knife_Settings::OPTION, $options );
		}
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
	 * The starting text of the safeguarding policy. It is a general version of a real club's policy,
	 * built on the English Chess Federation's safeguarding guidance. The club should read it, fill in
	 * the gaps in square brackets and change it to match what it really does.
	 *
	 * @return array[] Each { heading, paragraphs, items? }.
	 */
	public static function safeguarding_sections() {
		$options = Chess_Army_Knife_Settings::get_options();
		$club    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$club    = '' !== $club ? $club : __( 'The Club', 'chess-army-knife' );
		$name    = trim( (string) $options['safeguarding_officer'] );
		$email   = trim( (string) $options['safeguarding_email'] );
		$phone   = trim( (string) $options['safeguarding_phone'] );

		$sections = array(
			array(
				'heading'    => __( 'Safeguarding policy statement', 'chess-army-knife' ),
				'paragraphs' => array(
					/* translators: %s: club name */
					sprintf( __( '%s (the Club) wants everyone who plays chess to feel safe, welcome and able to enjoy it. Safeguarding means keeping people safe from harm. Everyone at the Club has a part to play: players, coaches, officials, administrators, staff, volunteers, spectators, parents and carers. What we do, on and off the chess board, helps build a friendly culture where everyone is included.', 'chess-army-knife' ), $club ),
					__( 'We will do this by:', 'chess-army-knife' ),
				),
				'items'      => array(
					__( 'having the right people in place', 'chess-army-knife' ),
					__( 'creating the right culture and environment', 'chess-army-knife' ),
					__( 'making sure there are clear steps for reporting and responding to safeguarding concerns', 'chess-army-knife' ),
					__( 'adopting the English Chess Federation\'s (ECF) Safeguarding Policy and Guidance', 'chess-army-knife' ),
					__( 'making sure all Club officers and Team Captains have a Disclosure and Barring Service (DBS) check', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Having the right people in place', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'Everyone at our Club is responsible for safeguarding. We also have a named Club Safeguarding Officer.', 'chess-army-knife' ),
					__( 'The Club Safeguarding Officer is:', 'chess-army-knife' ),
				),
				'items'      => array(
					__( 'the first point of contact for all children, parents and carers, volunteers and members at the Club', 'chess-army-knife' ),
					__( 'a member of our committee', 'chess-army-knife' ),
					__( 'a source of safeguarding advice for the Club, its committee and its members', 'chess-army-knife' ),
					__( 'the Club\'s main contact for the ECF\'s Safeguarding Team and other safeguarding bodies outside the Club', 'chess-army-knife' ),
					__( 'the person who makes sure there are clear, complete steps for raising and handling safeguarding concerns', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Creating the right culture and environment', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'Everyone who plays chess has the right to enjoy the game and to be safe from abuse of any kind. This applies whatever their age, gender, race, religion, sexual orientation, ability or disability. Safeguarding starts with high standards and a positive culture. That gives everyone the best chance to enjoy themselves and the game.', 'chess-army-knife' ),
					__( 'We listen. We ask children, parents and carers, volunteers and other members what they think, and we act on it. This helps people feel able to speak up about worries, including poor practice, abuse and neglect.', 'chess-army-knife' ),
					__( 'We seek to create a partnership with parents and carers, so that they know what to expect from us and what we expect of them.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Code of conduct', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'In keeping with this aim, the Club expects all Club members to have regard to the following code of conduct.', 'chess-army-knife' ),
				),
				'items'      => array(
					__( 'Keep children safe by supervising them well and planning chess activities properly.', 'chess-army-knife' ),
					__( 'Think about the wellbeing and safety of everyone taking part before you start an activity such as coaching or running a chess event.', 'chess-army-knife' ),
					__( 'Encourage and guide participants to accept responsibility for their own performance and behaviour.', 'chess-army-knife' ),
					__( 'Treat all young people fairly and make sure they feel valued and respected, and have no favourites.', 'chess-army-knife' ),
					__( 'Do not allow discrimination because of someone\'s religion, race, gender, social class or disability.', 'chess-army-knife' ),
					__( 'Do not allow any bullying, bad language or inappropriate behaviour.', 'chess-army-knife' ),
					__( 'Tell the Safeguarding Officer about any accident, or any suspected abuse or poor practice.', 'chess-army-knife' ),
					__( 'Do not smoke or drink alcohol during direct coaching.', 'chess-army-knife' ),
					__( 'Avoid taking photos or videos without permission, especially of children.', 'chess-army-knife' ),
					__( 'Do not give or accept gifts to or from children and young people without their parents\' or guardians\' permission.', 'chess-army-knife' ),
					__( 'Do not add children to your social media accounts, or take their phone numbers, unless their parents have said you may.', 'chess-army-knife' ),
					__( 'Never take children to your home, a hotel bedroom or a similar place (for example for coaching) unless one of these is true. Another person is there who is the child\'s parent or guardian, or who the parent or guardian has approved. Or the parent or guardian has clearly agreed.', 'chess-army-knife' ),
					__( 'Plan activities so that at least one other person is there, or so that others can see or hear what is going on. This matters most for one-to-one training and for travelling to or from chess events.', 'chess-army-knife' ),
				),
			),
			array(
				/* translators: %s: club name */
				'heading'    => sprintf( __( '%s and the digital world', 'chess-army-knife' ), $club ),
				'paragraphs' => array(
					__( 'Managing the Club\'s online presence is part of safeguarding.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Internet and social media', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'Our website and social media will follow these rules:', 'chess-army-knife' ),
				),
				'items'      => array(
					__( 'Every social media account will have a password. At least two members of staff or volunteers will have access to each account and its password.', 'chess-army-knife' ),
					__( 'At least two Club members, chosen by the committee, will watch each account. This keeps things open.', 'chess-army-knife' ),
					__( 'The members who run our online presence will ask the Safeguarding Officer for advice on keeping people safe.', 'chess-army-knife' ),
					__( 'These members will remove inappropriate posts by children or adults and say why. They will tell anyone affected, and the parents of any children involved.', 'chess-army-knife' ),
					__( 'The Club will make sure children know who runs our social media accounts. They will also know who to tell if something online worries them.', 'chess-army-knife' ),
					__( 'Our account, page and event settings will be private, so only invited Club members can see what is posted.', 'chess-army-knife' ),
					__( 'Do not post details that could identify a child on social media, such as a home address, school name or phone number.', 'chess-army-knife' ),
					__( 'Any posts or correspondence will be consistent with our aims and tone as a club.', 'chess-army-knife' ),
					__( 'We will ask parents to agree to Club members contacting their children through Club social media accounts, video calls or any other way.', 'chess-army-knife' ),
					__( 'Parents must give permission before photos or videos of their child are posted on social media.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Using mobile phones or other digital technology to communicate', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'When Club members use phones or other devices to talk, send video or message (including texts, email and apps such as WhatsApp or Facebook Messenger), the Club will take these steps to keep children safe:', 'chess-army-knife' ),
				),
				'items'      => array(
					__( 'Club members will avoid having children\'s own mobile numbers. They will contact a parent instead.', 'chess-army-knife' ),
					__( 'Parental permission will be sought each time we need to contact children directly. The purpose of each contact will be clearly identified and agreed.', 'chess-army-knife' ),
					__( 'There will be a way to check what is said, such as copying texts, messages or emails to another member of staff or to a parent.', 'chess-army-knife' ),
					__( 'Do not take or share pictures or videos of other people if it could invade their privacy.', 'chess-army-knife' ),
					__( 'We will use texts, emails and messages to pass on information, such as details of chess matches. A group chat may sometimes be casual, but it must always be friendly. We will not accept offensive messages.', 'chess-army-knife' ),
				),
			),
			array(
				'heading' => __( 'What we expect of Club members online', 'chess-army-knife' ),
				'items'   => array(
					__( 'Club members should not communicate with children through personal accounts.', 'chess-army-knife' ),
					__( 'Club members should not "friend" or "follow" children from their personal accounts. When using Club accounts, they should keep the same boundaries online as they would in person.', 'chess-army-knife' ),
					__( 'Club members should make sure anything they post on public personal accounts is accurate and suitable, because children may "follow" them.', 'chess-army-knife' ),
					__( 'Club members should not contact parents through personal social media. They should use a more formal way: face to face, an email or a letter, or a Club account or website.', 'chess-army-knife' ),
					__( 'Club members should not email or message children, including through Club social media or messaging apps, without copying in a parent or guardian.', 'chess-army-knife' ),
					__( 'Emails and messages should be polite and professional, as if writing to a colleague. Do not use kisses (X\'s), slang or inappropriate language.', 'chess-army-knife' ),
					__( 'Club members should not delete messages sent to or from Club accounts.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Reporting and responding to safeguarding concerns', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'We want everyone at the Club to feel able to raise a concern, however small. Dealing with concerns quickly, as they come up, helps us keep people safe.', 'chess-army-knife' ),
					__( 'We will take every worry, suspicion and allegation seriously. For every concern we follow the 3 Rs: respond properly, record it in confidence, and report it where needed. This way concerns are dealt with fairly and quickly.', 'chess-army-knife' ),
					__( 'Club members do not have to decide whether abuse has happened, or investigate it. Their job is to act on a concern and report it quickly.', 'chess-army-knife' ),
					__( 'If a child is in immediate danger, call 999.', 'chess-army-knife' ),
					__( 'We share private information about safeguarding only with people who need to know. We may need to share it with the ECF Safeguarding Officer, or with local agencies that are responsible for safeguarding by law. If we are not sure, we will ask the ECF for advice.', 'chess-army-knife' ),
					__( 'Each year the Safeguarding Officer will report to the AGM in [add the month of your AGM]. The report will include any changes to this policy and any new advice from the ECF.', 'chess-army-knife' ),
					__( 'This policy will be on display [add where, for example on the Club\'s noticeboard]. Players and parents can ask for a copy after they register with the Club. The Club\'s Safeguarding Officer can give you a form for recording concerns or allegations of abuse, harm or neglect. Their details are below.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Club commitment', 'chess-army-knife' ),
				'paragraphs' => array(
					/* translators: %s: club name */
					sprintf( __( '%s is committed to this Safeguarding Policy Statement.', 'chess-army-knife' ), $club ),
					__( 'Date completed: [add the date].', 'chess-army-knife' ),
				),
			),
			array(
				'heading' => __( 'Our Club Safeguarding Officer\'s details', 'chess-army-knife' ),
				'items'   => array(
					/* translators: %s: the safeguarding officer's name */
					sprintf( __( 'Name: %s', 'chess-army-knife' ), '' !== $name ? $name : __( '[add the name of your safeguarding officer]', 'chess-army-knife' ) ),
					/* translators: %s: email address */
					sprintf( __( 'Email address: %s', 'chess-army-knife' ), '' !== $email ? $email : __( '[add their email address]', 'chess-army-knife' ) ),
					/* translators: %s: phone number */
					sprintf( __( 'Phone number: %s', 'chess-army-knife' ), '' !== $phone ? $phone : __( '[add their phone number]', 'chess-army-knife' ) ),
				),
			),
		);

		/**
		 * Filter the sections of the safeguarding policy's starting text.
		 *
		 * @param array[] $sections Each { heading, paragraphs?, items? }.
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
	 * The address of this screen.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function screen_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * A message for the top of the Overview and the Policies screen while policies are not finished.
	 *
	 * @return string Escaped HTML, or '' if everything is done or skipped.
	 */
	public static function attention_notice() {
		$count = self::attention_count();
		if ( 0 === $count ) {
			return '';
		}

		$undecided = 0;
		foreach ( array_keys( self::policies() ) as $key ) {
			if ( 'undecided' === self::status( $key ) ) {
				++$undecided;
			}
		}

		$message = $undecided
			? __( 'Your club\'s policies are not set up yet. Each club\'s policies are different, so the plugin makes nothing until you say what you want. For each policy you can have a draft page made from an example, or say that you will do it yourself.', 'chess-army-knife' )
			: __( 'A policy page is a draft from a general example and has not been marked as reviewed. Read it, change it to match what your club really does, and then mark it as reviewed. Until then it is not finished.', 'chess-army-knife' );

		return '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Your club\'s policies need your attention.', 'chess-army-knife' ) . '</strong> ' . esc_html( $message ) . ' <a href="' . esc_url( self::screen_url() ) . '">' . esc_html__( 'Go to Policies', 'chess-army-knife' ) . '</a></p></div>';
	}

	/**
	 * Remind whoever opens a policy page that nobody has said it is finished.
	 */
	public static function edit_screen_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || 'page' !== $screen->post_type ) {
			return;
		}
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: chooses whether to show a reminder.
		foreach ( self::policies() as $key => $policy ) {
			$page = self::page( $key );
			if ( $page && $page->ID === $post_id && 'review' === self::status( $key ) ) {
				echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'This policy is not finished.', 'chess-army-knife' ) . '</strong> ';
				echo esc_html__( 'It started as a general example. Make sure it matches what your club really does before you publish it, then mark it as reviewed.', 'chess-army-knife' );
				echo ' <a href="' . esc_url( self::screen_url() ) . '">' . esc_html__( 'Go to Policies', 'chess-army-knife' ) . '</a></p></div>';
				return;
			}
		}
	}

	/**
	 * A policy's example wording, as plain text to read before deciding.
	 *
	 * @param string $key Policy, a key of policies().
	 * @return array[] Each { heading, paragraphs, items? }.
	 */
	protected static function example( $key ) {
		if ( 'data' === $key ) {
			return Chess_Army_Knife_Membership_Privacy::policy_sections();
		}
		if ( 'safeguarding' === $key ) {
			return self::safeguarding_sections();
		}
		return array(
			array(
				'heading'    => __( 'The site\'s privacy policy', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'WordPress\'s own suggested privacy policy text, which gathers what each plugin on the site says about the data it holds, followed by a short note pointing to the club data policy and the safeguarding policy. If the site already has a privacy policy page, the plugin uses that page and does not change it.', 'chess-army-knife' ),
				),
			),
		);
	}

	/**
	 * The text of a message on this screen.
	 *
	 * @param string $code Code from the address.
	 * @return string
	 */
	protected static function message( $code ) {
		$messages = array(
			'created'  => __( 'The draft page has been made. Read it and change it to match your club, then mark it as reviewed.', 'chess-army-knife' ),
			'skipped'  => __( 'Noted. The plugin will not make that page. You can change your mind here at any time.', 'chess-army-knife' ),
			'restored' => __( 'The plugin\'s wording is back on the page, and it needs reviewing again. The earlier text is kept in the page\'s revisions.', 'chess-army-knife' ),
			'reviewed' => __( 'Marked as reviewed.', 'chess-army-knife' ),
			'unmarked' => __( 'Marked as needing a review.', 'chess-army-knife' ),
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : '';
	}

	/**
	 * The fields a policy asks for, as form rows.
	 *
	 * @param string $key Policy, a key of policies().
	 */
	protected static function render_fields( $key ) {
		$options = Chess_Army_Knife_Settings::get_options();
		foreach ( self::fields( $key ) as $field ) {
			$id = 'cak-policy-' . $key . '-' . $field['name'];
			?>
			<p>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label><br />
				<input type="<?php echo esc_attr( $field['type'] ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $field['name'] ); ?>" value="<?php echo esc_attr( isset( $options[ $field['name'] ] ) ? $options[ $field['name'] ] : '' ); ?>" class="<?php echo 'number' === $field['type'] ? 'small-text' : 'regular-text'; ?>" <?php echo 'number' === $field['type'] ? 'min="0" max="120"' : ''; ?> <?php echo '' !== $field['help'] ? 'aria-describedby="' . esc_attr( $id ) . '-help"' : ''; ?> />
				<?php if ( '' !== $field['help'] ) : ?>
					<br /><span id="<?php echo esc_attr( $id ); ?>-help" class="description"><?php echo esc_html( $field['help'] ); ?></span>
				<?php endif; ?>
			</p>
			<?php
		}
	}

	/**
	 * Draw the Policies screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( current_user_can( self::REQUIRED_CAP ), __( 'Policies', 'chess-army-knife' ), 'pages' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the outcome of an action.
		$done  = isset( $_GET['cak_policy_done'] ) ? self::message( sanitize_key( wp_unslash( $_GET['cak_policy_done'] ) ) ) : '';
		$error = isset( $_GET['cak_policy_error'] ) ? sanitize_key( wp_unslash( $_GET['cak_policy_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Policies', 'chess-army-knife' ); ?></h1>
			<p>
				<?php esc_html_e( 'A club\'s policies are specific to that club, so the plugin makes nothing until you ask. For each policy you can have a draft page made from a general example, using a few details from you, or say that you will write it yourself. A page is an ordinary page: edit it in the block editor, publish it, put it in a menu and link to it from anywhere on the site.', 'chess-army-knife' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'The examples are general, and are not legal advice.', 'chess-army-knife' ); ?></strong>
				<?php esc_html_e( 'A policy is not finished until someone at the club has read it, changed it to match what the club really does, and marked it as reviewed.', 'chess-army-knife' ); ?>
			</p>

			<?php echo self::attention_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in attention_notice(). ?>

			<?php if ( '' !== $done ) : ?>
				<div class="notice notice-success" role="status"><p><?php echo esc_html( $done ); ?></p></div>
			<?php elseif ( '' !== $error ) : ?>
				<div class="notice notice-error" role="alert"><p><?php echo esc_html( 'confirm' === $error ? __( 'Tick the box to say you understand, then try again.', 'chess-army-knife' ) : __( 'That could not be done.', 'chess-army-knife' ) ); ?></p></div>
			<?php endif; ?>

			<?php foreach ( self::policies() as $key => $policy ) : ?>
				<?php
				$post   = self::page( $key );
				$status = self::status( $key );
				?>
				<h2 id="policy-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $policy['title'] ); ?></h2>
				<p class="description"><?php echo esc_html( $policy['description'] ); ?></p>

				<?php if ( 'review' === $status || 'done' === $status ) : ?>
					<p>
						<strong>
							<?php
							if ( 'done' === $status && self::reviewed_at( $key ) ) {
								/* translators: %s: date the policy was marked as reviewed */
								echo esc_html( sprintf( __( 'Reviewed on %s.', 'chess-army-knife' ), wp_date( get_option( 'date_format' ), self::reviewed_at( $key ) ) ) );
							} elseif ( 'done' === $status ) {
								esc_html_e( 'This page was already on your site, so the plugin leaves it alone.', 'chess-army-knife' );
							} else {
								esc_html_e( 'Not finished: a draft from a general example, not yet reviewed.', 'chess-army-knife' );
							}
							?>
						</strong>
						<?php echo esc_html( 'publish' === $post->post_status ? __( 'The page is published.', 'chess-army-knife' ) : __( 'The page is not public yet.', 'chess-army-knife' ) ); ?>
					</p>
					<p>
						<a class="button" href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $policy['title'] ); ?></span></a>
						<a class="button" href="<?php echo esc_url( 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post ) ); ?>"><?php echo esc_html( 'publish' === $post->post_status ? __( 'View', 'chess-army-knife' ) : __( 'Preview', 'chess-army-knife' ) ); ?><span class="screen-reader-text"> <?php echo esc_html( $policy['title'] ); ?></span></a>
					</p>

					<?php if ( 'review' === $status ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REVIEW ); ?>" />
							<input type="hidden" name="policy" value="<?php echo esc_attr( $key ); ?>" />
							<input type="hidden" name="reviewed" value="1" />
							<?php wp_nonce_field( self::ACTION_REVIEW . '_' . $key ); ?>
							<p>
								<label>
									<input type="checkbox" name="confirm" value="1" required />
									<?php
									/* translators: %s: policy name */
									echo esc_html( sprintf( __( 'I have read the %s page and it matches what our club does', 'chess-army-knife' ), $policy['title'] ) );
									?>
									<span class="cak-required"><?php esc_html_e( '(required)', 'chess-army-knife' ); ?></span>
								</label>
							</p>
							<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Mark as reviewed', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $policy['title'] ); ?></span></button></p>
						</form>
					<?php elseif ( self::reviewed_at( $key ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REVIEW ); ?>" />
							<input type="hidden" name="policy" value="<?php echo esc_attr( $key ); ?>" />
							<input type="hidden" name="reviewed" value="0" />
							<?php wp_nonce_field( self::ACTION_REVIEW . '_' . $key ); ?>
							<p><button type="submit" class="button"><?php esc_html_e( 'Needs another review', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $policy['title'] ); ?></span></button></p>
						</form>
					<?php endif; ?>

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
									echo esc_html( sprintf( __( 'Replace the text of the %s page with the plugin\'s example wording', 'chess-army-knife' ), $policy['title'] ) );
									?>
									<span class="cak-required"><?php esc_html_e( '(required)', 'chess-army-knife' ); ?></span>
								</label>
							</p>
							<p><button type="submit" class="button"><?php esc_html_e( 'Put the example wording back', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $policy['title'] ); ?></span></button></p>
						</form>
					<?php endif; ?>

				<?php else : ?>
					<?php if ( 'skipped' === $status ) : ?>
						<p><strong><?php esc_html_e( 'You said you will do this yourself.', 'chess-army-knife' ); ?></strong> <?php esc_html_e( 'Change your mind below and the plugin will make a draft page.', 'chess-army-knife' ); ?></p>
					<?php endif; ?>

					<details>
						<summary><?php esc_html_e( 'Read the example wording', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $policy['title'] ); ?></span></summary>
						<div class="cak-policy-example">
							<?php foreach ( self::example( $key ) as $section ) : ?>
								<h3><?php echo esc_html( $section['heading'] ); ?></h3>
								<?php foreach ( isset( $section['paragraphs'] ) ? (array) $section['paragraphs'] : array() as $paragraph ) : ?>
									<p><?php echo esc_html( $paragraph ); ?></p>
								<?php endforeach; ?>
								<?php if ( ! empty( $section['items'] ) ) : ?>
									<ul class="ul-disc">
										<?php foreach ( $section['items'] as $item ) : ?>
											<li><?php echo esc_html( $item ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</details>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SETUP ); ?>" />
						<input type="hidden" name="policy" value="<?php echo esc_attr( $key ); ?>" />
						<?php wp_nonce_field( self::ACTION_SETUP . '_' . $key ); ?>
						<?php if ( self::fields( $key ) ) : ?>
							<p><?php esc_html_e( 'Tell us about your club and we will put it in the example. You can change all of this on the page afterwards.', 'chess-army-knife' ); ?></p>
							<?php self::render_fields( $key ); ?>
						<?php endif; ?>
						<p>
							<button type="submit" name="use" value="1" class="button button-primary"><?php esc_html_e( 'Yes, make a draft page', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $policy['title'] ); ?></span></button>
							<?php if ( 'skipped' !== $status ) : ?>
								<button type="submit" name="use" value="0" class="button" formnovalidate><?php esc_html_e( 'No, I will do it myself', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $policy['title'] ); ?></span></button>
							<?php endif; ?>
						</p>
					</form>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Handle the answer to "do you want this policy?".
	 */
	public static function handle_setup() {
		$key = isset( $_POST['policy'] ) ? sanitize_key( wp_unslash( $_POST['policy'] ) ) : '';
		check_admin_referer( self::ACTION_SETUP . '_' . $key );
		self::require_permission();

		$use     = ! empty( $_POST['use'] );
		$details = array();
		foreach ( self::fields( $key ) as $field ) {
			if ( isset( $_POST[ $field['name'] ] ) ) {
				$details[ $field['name'] ] = sanitize_text_field( wp_unslash( $_POST[ $field['name'] ] ) );
			}
		}

		$result = self::set_up( $key, $use, $details );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::screen_url( array( 'cak_policy_error' => 'failed' ) ) );
			exit;
		}
		wp_safe_redirect( self::screen_url( array( 'cak_policy_done' => $use ? 'created' : 'skipped' ) ) . '#policy-' . $key );
		exit;
	}

	/**
	 * Handle "Mark as reviewed" and "Needs another review".
	 */
	public static function handle_review() {
		$key = isset( $_POST['policy'] ) ? sanitize_key( wp_unslash( $_POST['policy'] ) ) : '';
		check_admin_referer( self::ACTION_REVIEW . '_' . $key );
		self::require_permission();

		$reviewed = ! empty( $_POST['reviewed'] );
		if ( $reviewed && empty( $_POST['confirm'] ) ) {
			wp_safe_redirect( self::screen_url( array( 'cak_policy_error' => 'confirm' ) ) . '#policy-' . $key );
			exit;
		}
		if ( ! isset( self::policies()[ $key ] ) ) {
			wp_safe_redirect( self::screen_url( array( 'cak_policy_error' => 'failed' ) ) );
			exit;
		}

		self::mark_reviewed( $key, $reviewed );
		wp_safe_redirect( self::screen_url( array( 'cak_policy_done' => $reviewed ? 'reviewed' : 'unmarked' ) ) . '#policy-' . $key );
		exit;
	}

	/**
	 * Handle "Put the example wording back".
	 */
	public static function handle_restore() {
		$key = isset( $_POST['policy'] ) ? sanitize_key( wp_unslash( $_POST['policy'] ) ) : '';
		check_admin_referer( self::ACTION_RESTORE . '_' . $key );
		self::require_permission();

		if ( empty( $_POST['confirm'] ) ) {
			wp_safe_redirect( self::screen_url( array( 'cak_policy_error' => 'confirm' ) ) . '#policy-' . $key );
			exit;
		}

		$page = self::page( $key );
		if ( $page && ! current_user_can( 'edit_post', $page->ID ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}

		$result = self::restore( $key );
		wp_safe_redirect( self::screen_url( is_wp_error( $result ) ? array( 'cak_policy_error' => 'failed' ) : array( 'cak_policy_done' => 'restored' ) ) . '#policy-' . $key );
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
