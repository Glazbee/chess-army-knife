<?php
/**
 * Data protection for members: plugs into WordPress's own personal data
 * export and erase tools (Tools > Export / Erase Personal Data), deletes old
 * records after the retention period set on the Settings page, and adds
 * suggested wording to the privacy policy guide.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Membership_Privacy {

	const EXPORT_GROUP = 'chess-army-knife-membership';

	/**
	 * Hook up the privacy tools and the daily clean-up.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
		// The plugin's existing daily job; sites that already have it scheduled need nothing new.
		add_action( 'Chess_Army_Knife_cleanup_cache', array( __CLASS__, 'purge_old_records' ) );
	}

	/**
	 * Add the exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['chess-army-knife-membership'] = array(
			'exporter_friendly_name' => __( 'Club membership', 'chess-army-knife' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['chess-army-knife-membership'] = array(
			'eraser_friendly_name' => __( 'Club membership', 'chess-army-knife' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Everything held about the person with an email address.
	 *
	 * @param string $email Email address.
	 * @return array Data in the form WordPress's exporter expects.
	 */
	public static function export( $email ) {
		$items   = array();
		$methods = Chess_Army_Knife_Memberships::payment_methods();
		$labels  = Chess_Army_Knife_Membership_Store::status_labels();

		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) as $member ) {
			$fields = array(
				__( 'Name', 'chess-army-knife' )           => $member['name'],
				__( 'Nickname', 'chess-army-knife' )       => $member['nickname'],
				__( 'Featured Player blurb', 'chess-army-knife' ) => $member['blurb'],
				__( 'Email', 'chess-army-knife' )          => $member['email'],
				__( 'Phone', 'chess-army-knife' )          => $member['phone'],
				__( 'Date of birth', 'chess-army-knife' )  => $member['date_of_birth'],
				__( 'Parent or guardian', 'chess-army-knife' ) => $member['guardian_name'],
				__( 'ECF rating code', 'chess-army-knife' ) => $member['ecf_code'],
				__( 'Manual rating', 'chess-army-knife' )  => null === $member['manual_rating'] ? '' : $member['manual_rating'],
				__( 'Latest ECF rating (fetched from the ECF)', 'chess-army-knife' ) => null === $member['ecf_rating'] ? '' : $member['ecf_rating'] . ( '' !== $member['ecf_rating_domain'] ? ' (' . $member['ecf_rating_domain'] . ')' : '' ),
				__( 'Membership type', 'chess-army-knife' ) => $member['type_name'],
				__( 'Status', 'chess-army-knife' )         => isset( $labels[ $member['status'] ] ) ? $labels[ $member['status'] ] : $member['status'],
				__( 'Membership starts', 'chess-army-knife' ) => $member['start_date'],
				__( 'Membership expires', 'chess-army-knife' ) => $member['expiry_date'],
				__( 'Payment received on', 'chess-army-knife' ) => $member['paid_on'],
				__( 'Payment method', 'chess-army-knife' ) => isset( $methods[ $member['payment_method'] ] ) ? $methods[ $member['payment_method'] ] : '',
				__( 'Club notes', 'chess-army-knife' )     => $member['notes'],
				__( 'Parent or guardian email', 'chess-army-knife' ) => $member['guardian_email'],
				__( 'Parent or guardian phone', 'chess-army-knife' ) => $member['guardian_phone'],
				__( 'Agreed to the club keeping these details (UTC)', 'chess-army-knife' ) => $member['consent_at'],
				__( 'Agreed to receive the newsletter (UTC)', 'chess-army-knife' ) => $member['newsletter_consent_at'],
				__( 'Agreed to be added to WhatsApp groups (UTC)', 'chess-army-knife' ) => $member['whatsapp_consent_at'],
				__( 'Last renewal reminder sent (expiry date and days before it)', 'chess-army-knife' ) => $member['renewal_reminder'],
				__( 'Record created (UTC)', 'chess-army-knife' ) => $member['created_at'],
			);

			$data = array();
			foreach ( $fields as $name => $value ) {
				if ( '' !== (string) $value ) {
					$data[] = array(
						'name'  => $name,
						'value' => $value,
					);
				}
			}

			$items[] = array(
				'group_id'    => self::EXPORT_GROUP,
				'group_label' => __( 'Club membership', 'chess-army-knife' ),
				'item_id'     => 'membership-' . $member['id'],
				'data'        => $data,
			);
		}

		// The emails the club has sent them, and the kinds they have turned off.
		$categories = Chess_Army_Knife_Notification_Preferences::categories();
		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) as $member ) {
			foreach ( Chess_Army_Knife_Mailer::get_log_for_person( $member['id'] ) as $mail ) {
				$items[] = array(
					'group_id'    => 'chess-army-knife-emails',
					'group_label' => __( 'Emails from the club', 'chess-army-knife' ),
					'item_id'     => 'email-' . $mail['id'],
					'data'        => array(
						array(
							'name'  => __( 'Subject', 'chess-army-knife' ),
							'value' => $mail['subject'],
						),
						array(
							'name'  => __( 'Kind', 'chess-army-knife' ),
							'value' => isset( $categories[ $mail['category'] ] ) ? $categories[ $mail['category'] ] : $mail['category'],
						),
						array(
							'name'  => __( 'Status', 'chess-army-knife' ),
							'value' => $mail['status'],
						),
						array(
							'name'  => __( 'Sent (UTC)', 'chess-army-knife' ),
							'value' => (string) $mail['sent_at'],
						),
					),
				);
			}

			$opt_outs = array();
			foreach ( Chess_Army_Knife_Notification_Preferences::get_opt_outs( $member['id'] ) as $category ) {
				$opt_outs[] = isset( $categories[ $category ] ) ? $categories[ $category ] : $category;
			}
			if ( $opt_outs ) {
				$items[] = array(
					'group_id'    => 'chess-army-knife-email-choices',
					'group_label' => __( 'Emails you have turned off', 'chess-army-knife' ),
					'item_id'     => 'email-choices-' . $member['id'],
					'data'        => array(
						array(
							'name'  => __( 'Kinds of email', 'chess-army-knife' ),
							'value' => implode( ', ', $opt_outs ),
						),
					),
				);
			}
		}

		// Whether they can play, and the line-ups they were picked for.
		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) as $member ) {
			foreach ( Chess_Army_Knife_Selection::records_for_person( $member['id'] ) as $record ) {
				$event   = get_post( $record['event_id'] );
				$team    = Chess_Army_Knife_Teams::get( $record['team_id'] );
				$items[] = array(
					'group_id'    => 'chess-army-knife-selection',
					'group_label' => __( 'Club team selection', 'chess-army-knife' ),
					'item_id'     => 'selection-' . $record['event_id'] . '-' . $record['team_id'] . '-' . $member['id'],
					'data'        => array(
						array(
							'name'  => __( 'Fixture', 'chess-army-knife' ),
							'value' => $event ? get_the_title( $event ) : '',
						),
						array(
							'name'  => __( 'Team', 'chess-army-knife' ),
							'value' => $team ? $team['name'] : '',
						),
						array(
							'name'  => __( 'Your reply', 'chess-army-knife' ),
							'value' => $record['response'],
						),
						array(
							'name'  => __( 'Board picked for', 'chess-army-knife' ),
							'value' => $record['board'] ? (string) $record['board'] : '',
						),
					),
				);
			}
		}

		// The teams they are in the squad of, or captain.
		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) as $member ) {
			foreach ( Chess_Army_Knife_Teams::teams_of_person( $member['id'] ) as $team ) {
				$items[] = array(
					'group_id'    => 'chess-army-knife-teams',
					'group_label' => __( 'Club teams you are in', 'chess-army-knife' ),
					'item_id'     => 'team-' . $team['id'] . '-' . $member['id'],
					'data'        => array(
						array(
							'name'  => __( 'Team', 'chess-army-knife' ),
							'value' => $team['name'],
						),
						array(
							'name'  => __( 'Captain', 'chess-army-knife' ),
							'value' => $team['captain'] ? __( 'Yes', 'chess-army-knife' ) : '',
						),
					),
				);
			}
		}

		// The positions they have held as a club officer, and when.
		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) as $member ) {
			foreach ( Chess_Army_Knife_Officers::terms() as $term ) {
				if ( $term['person_id'] !== $member['id'] ) {
					continue;
				}
				$items[] = array(
					'group_id'    => 'chess-army-knife-officers',
					'group_label' => __( 'Club officer positions you have held', 'chess-army-knife' ),
					'item_id'     => 'officer-' . $term['id'],
					'data'        => array(
						array(
							'name'  => __( 'Position', 'chess-army-knife' ),
							'value' => Chess_Army_Knife_Officers::label_of_term( $term ),
						),
						array(
							'name'  => __( 'From', 'chess-army-knife' ),
							'value' => $term['start_date'],
						),
						array(
							'name'  => __( 'To', 'chess-army-knife' ),
							'value' => $term['end_date'],
						),
					),
				);
			}
		}

		// Each period of membership they have held.
		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) as $member ) {
			foreach ( Chess_Army_Knife_Member_History::periods( $member ) as $index => $period ) {
				$items[] = array(
					'group_id'    => 'chess-army-knife-membership-history',
					'group_label' => __( 'Your membership history', 'chess-army-knife' ),
					'item_id'     => 'membership-period-' . $member['id'] . '-' . $index,
					'data'        => array(
						array(
							'name'  => __( 'Membership', 'chess-army-knife' ),
							'value' => $period['type_name'],
						),
						array(
							'name'  => __( 'Started', 'chess-army-knife' ),
							'value' => $period['start_date'],
						),
						array(
							'name'  => __( 'Last day', 'chess-army-knife' ),
							'value' => $period['expiry_date'],
						),
					),
				);
			}
		}

		// The tournaments they have been entered in.
		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) as $member ) {
			foreach ( Chess_Army_Knife_Tournament_Store::get_tournaments_for_person( $member['id'] ) as $tournament ) {
				$items[] = array(
					'group_id'    => 'chess-army-knife-tournaments',
					'group_label' => __( 'Club tournaments you have entered', 'chess-army-knife' ),
					'item_id'     => 'tournament-' . $tournament['id'] . '-' . $member['id'],
					'data'        => array(
						array(
							'name'  => __( 'Tournament', 'chess-army-knife' ),
							'value' => $tournament['name'],
						),
					),
				);
			}
		}

		// The photos they are tagged in, once each even if two of their records are tagged in the same photo.
		$seen = array();
		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) as $member ) {
			foreach ( Chess_Army_Knife_Member_Photos::photo_ids( $member['id'] ) as $photo_id ) {
				if ( isset( $seen[ $photo_id ] ) ) {
					continue;
				}
				$seen[ $photo_id ] = true;
				$items[]           = array(
					'group_id'    => 'chess-army-knife-photos',
					'group_label' => __( 'Club photos you are tagged in', 'chess-army-knife' ),
					'item_id'     => 'photo-' . $photo_id,
					'data'        => array(
						array(
							'name'  => __( 'Photo', 'chess-army-knife' ),
							'value' => (string) wp_get_attachment_url( $photo_id ),
						),
						array(
							'name'  => __( 'Title', 'chess-army-knife' ),
							'value' => get_the_title( $photo_id ),
						),
					),
				);
			}
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase what is held about the person with an email address.
	 *
	 * @param string $email Email address.
	 * @return array Result in the form WordPress's eraser expects.
	 */
	public static function erase( $email ) {
		$removed  = false;
		$retained = false;
		$messages = array();

		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) as $member ) {
			$photos      = count( Chess_Army_Knife_Member_Photos::photo_ids( $member['id'] ) );
			$paid        = '' !== $member['paid_on'];
			$had_entries = Chess_Army_Knife_Tournament_Store::person_has_entries( $member['id'] );

			// Someone who asks to be erased is not recorded again by accident.
			if ( 'anonymised' === Chess_Army_Knife_Membership_Store::erase_member( $member['id'], true ) ) {
				$retained = true;
				if ( $paid ) {
					$messages[] = __( 'A payment record was kept for the club\'s accounts, without any personal details.', 'chess-army-knife' );
				}
				if ( $photos ) {
					/* translators: 1: number of photos, 2: member record number */
					$messages[] = sprintf( _n( '%1$d photo tagged with this person was not deleted, because photos can show other people. It needs reviewing by hand: in the Media Library, filter by the record "Erased member" (record %2$d).', '%1$d photos tagged with this person were not deleted, because photos can show other people. They need reviewing by hand: in the Media Library, filter by the record "Erased member" (record %2$d).', $photos, 'chess-army-knife' ), $photos, $member['id'] );
				}
			}
			if ( $had_entries ) {
				$messages[] = __( 'The name was kept on the results of tournaments already started, as a historical record, with nothing linking it to a person.', 'chess-army-knife' );
			}
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Erase members whose retention period has ended. Run daily.
	 *
	 * @return int How many records were erased.
	 */
	public static function purge_old_records() {
		$options = Chess_Army_Knife_Settings::get_options();
		$months  = (int) $options['member_retention_months'];

		if ( $months <= 0 ) {
			return 0;
		}

		$cutoff = strtotime( '-' . $months . ' months' );
		$count  = 0;

		foreach ( Chess_Army_Knife_Membership_Store::get_stale_members( gmdate( 'Y-m-d H:i:s', $cutoff ), wp_date( 'Y-m-d', $cutoff ) ) as $member ) {
			Chess_Army_Knife_Membership_Store::erase_member( $member['id'] );
			++$count;
		}

		return $count;
	}

	/**
	 * The club's data policy for members, as sections of plain text. The
	 * starting text of the Club data policy page (see Chess_Army_Knife_Policies),
	 * which follows the retention period set on the Policies screen.
	 *
	 * @return array[] Each { heading, paragraphs }, all plain text.
	 */
	public static function policy_sections() {
		$options = Chess_Army_Knife_Settings::get_options();
		$months  = (int) $options['member_retention_months'];

		if ( $months > 0 ) {
			/* translators: %d: number of months */
			$keep = sprintf( _n( 'We keep your details while you are a member and for %d month afterwards, then delete them. If we were paid, we keep a record of the payment for our accounts, without any personal details. Applications that are declined or never completed are deleted after the same time.', 'We keep your details while you are a member and for %d months afterwards, then delete them. If we were paid, we keep a record of the payment for our accounts, without any personal details. Applications that are declined or never completed are deleted after the same time.', $months, 'chess-army-knife' ), $months );
		} else {
			$keep = __( 'We keep your details until you ask us to delete them.', 'chess-army-knife' );
		}

		$ask = __( 'To ask about your details, contact the club at [add an email address].', 'chess-army-knife' );

		$sections = array(
			array(
				'heading'    => __( 'What we collect', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'When you apply, we collect your name, the membership you want and your email address. We also collect your phone number and ECF rating code, if you give them. We record when and how you paid. Club officers may add notes to your record.', 'chess-army-knife' ),
					__( 'We also keep the name and ECF rating code of people who take part in tournaments without being members, and of anyone whose ECF rating we show on this website, marked as not being members. They are left out of our membership lists. Tournament entries refer to these records, so a person\'s details are only ever held in one place. If you ask us to delete your details, your name stays on the results of tournaments already played, as a historical record, but nothing links it to you.', 'chess-army-knife' ),
					__( 'If you ask us to delete your details, we may keep a one-way scrambled code made from your ECF rating code and name. It cannot be turned back into either, and is only used so that we do not record you again by mistake.', 'chess-army-knife' ),
					__( 'You can see and correct your details, choose what we email you, and delete your details yourself, at any time, from the members\' page on this website. You sign in with a link we email to the address we hold for you.', 'chess-army-knife' ),
					__( 'If you play in a club team, the team captain can see your name and rating. The captain can ask if you can play in a match, and can record your reply and whether they pick you. We delete a reply or line-up when we delete the match, when you ask us to delete your details, or after the retention period.', 'chess-army-knife' ),
					__( 'If you play for one of our teams we record which team or teams you are in, and whether you are its captain. A club officer puts you in a team, or you are added when the league\'s match results show you played for it. Only club officers can see this.', 'chess-army-knife' ),
					__( 'For members under 18, we collect the junior\'s date of birth. We also collect a parent or guardian\'s name, email address and phone number. We write to the parent or guardian, not to the junior. We keep the junior\'s own email address or phone number only if their parent or guardian says we may contact the junior.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Why we use it', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'We use your details to run the club and your membership. That means deciding on your application, keeping our list of members, arranging club and team activities, and contacting you about your membership. The law lets us do this because of our legitimate interests: running the club and looking after its members. We do not sell your details or use them for advertising.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Sharing with the English Chess Federation', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'The club gives the names of its playing members to the English Chess Federation (ECF), so that their games can be rated. We give the ECF your name and ECF rating code, and anything else it needs to rate your games. We also ask the ECF for your latest rating, and keep it with your record. To do that, we ask for the ECF\'s list of players at our club. We keep only the ratings of people we already have a record of. We do not keep the rest of the list. The ECF looks after its own information under its own privacy policy.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Newsletters and WhatsApp groups', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'We only send you the club newsletter, or add you to a WhatsApp group for a club team, if you have said yes on the application form or to a club officer. These are separate choices: you can say no to either and still be a member. For WhatsApp we also record which team\'s group you asked to join. If you are added to a group, the other members can see your name and phone number, and WhatsApp itself handles the messages under its own terms. A junior is only added using their parent or guardian\'s agreement and number unless they say otherwise. You can change your mind at any time: use the Manage My Data page on this website, or contact us, and we will stop.', 'chess-army-knife' ),
					__( 'We also email members about their membership (for example, renewal reminders), club events and matches, and club announcements. Members can read past announcements after signing in to their member page. These emails do not need a separate yes from you, because they are part of running your membership. Every one has a link to stop that kind of email. We keep a log of the emails we send. It has the subject and the time, but not the text. We keep it for a limited time, and delete it if your details are erased. Emails for a junior go to their parent or guardian.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Photos', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'When we take photos at club events, a club officer may tag each photo with the names of the members in it, so that we can find every photo of a person if they ask to see them, or ask us to delete them. The tags can only be seen by club officers. You can ask us to remove a tag or delete a photo of you.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Who can see your details', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'Only club officers who have been given access can see membership details on this website. We do not take payments on this website: if you pay by bank transfer or in cash, we note the date and method against your membership.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'How long we keep it', 'chess-army-knife' ),
				'paragraphs' => array( $keep ),
			),
			array(
				'heading'    => __( 'Your rights', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'You can ask for a copy of your details. You can ask us to correct them or delete them. You can ask us to limit how we use them, or object to how we use them. You can take back a consent at any time. A parent or guardian can do this for a junior. If you are not happy with how we have handled your details, you can complain to the Information Commissioner\'s Office at ico.org.uk.', 'chess-army-knife' ),
					$ask,
				),
			),
			array(
				'heading'    => __( 'Spam protection', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'To stop the application form being misused, it briefly remembers a scrambled version of your internet address for one hour. It is not kept after that.', 'chess-army-knife' ),
				),
			),
		);

		/**
		 * Filter the sections of the club data policy's starting text, for example to add
		 * the club's own paragraphs.
		 *
		 * @param array[] $sections Each { heading, paragraphs }, all plain text.
		 */
		return (array) apply_filters( 'Chess_Army_Knife_data_policy_sections', $sections );
	}

	/**
	 * The wording suggested for the site's privacy policy: a pointer to the club data policy page,
	 * which holds the detail and can be edited on its own.
	 *
	 * @return string HTML, or '' until the club has made its data policy page, when there is nothing to point to.
	 */
	public static function policy_guide_text() {
		if ( null === Chess_Army_Knife_Policies::page( 'data' ) ) {
			return '';
		}

		$text  = '<h2>' . esc_html__( 'Club membership', 'chess-army-knife' ) . '</h2>';
		$text .= '<p class="privacy-policy-tutorial">' . esc_html__( 'The detail is on the Club data policy page, which you can edit under Chess Army Knife → Policies. Link to it here, and change this text to match what your club really does. It is not legal advice.', 'chess-army-knife' ) . '</p>';
		$text .= '<p>' . esc_html__( 'How the club handles its members\' personal data is explained in our', 'chess-army-knife' ) . ' [' . Chess_Army_Knife_Policies::SHORTCODE . ' policy=data].</p>';

		return wp_kses_post( $text );
	}

	/**
	 * Offer that wording in the Privacy Policy Guide.
	 */
	public static function add_policy_content() {
		$text = self::policy_guide_text();
		if ( '' !== $text && function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'Chess Army Knife', 'chess-army-knife' ), $text );
		}
	}
}

Chess_Army_Knife_Membership_Privacy::init();
