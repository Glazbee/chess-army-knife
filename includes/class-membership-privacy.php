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
				__( 'WhatsApp groups for teams', 'chess-army-knife' ) => implode( ', ', Chess_Army_Knife_Teams::labels( $member['whatsapp_teams'] ) ),
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

		// The events they have registered for.
		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) as $member ) {
			foreach ( Chess_Army_Knife_Event_Registrations::for_person( $member['id'] ) as $registration ) {
				$event   = get_post( $registration['event_id'] );
				$items[] = array(
					'group_id'    => 'chess-army-knife-registrations',
					'group_label' => __( 'Club events you registered for', 'chess-army-knife' ),
					'item_id'     => 'registration-' . $registration['id'],
					'data'        => array(
						array(
							'name'  => __( 'Event', 'chess-army-knife' ),
							'value' => $event ? get_the_title( $event ) : '',
						),
						array(
							'name'  => __( 'Status', 'chess-army-knife' ),
							'value' => Chess_Army_Knife_Event_Registrations::STATUS_WAITING === $registration['status'] ? __( 'Waiting list', 'chess-army-knife' ) : __( 'Registered', 'chess-army-knife' ),
						),
						array(
							'name'  => __( 'Guests', 'chess-army-knife' ),
							'value' => (string) $registration['guests'],
						),
						array(
							'name'  => __( 'Attended', 'chess-army-knife' ),
							'value' => $registration['attended'] ? __( 'Yes', 'chess-army-knife' ) : '',
						),
						array(
							'name'  => __( 'Registered (UTC)', 'chess-army-knife' ),
							'value' => $registration['registered_at'],
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
			$photos = count( Chess_Army_Knife_Member_Photos::photo_ids( $member['id'] ) );
			$paid   = '' !== $member['paid_on'];

			if ( 'anonymised' === Chess_Army_Knife_Membership_Store::erase_member( $member['id'] ) ) {
				$retained = true;
				if ( $paid ) {
					$messages[] = __( 'A payment record was kept for the club\'s accounts, without any personal details.', 'chess-army-knife' );
				}
				if ( Chess_Army_Knife_Tournament_Store::person_has_entries( $member['id'] ) ) {
					$messages[] = __( 'Tournament entries were kept, without any personal details, so past tournaments still add up.', 'chess-army-knife' );
				}
				if ( Chess_Army_Knife_Event_Registrations::person_has_registrations( $member['id'] ) ) {
					$messages[] = __( 'Event registrations were kept, without any personal details, so attendance at past events still adds up.', 'chess-army-knife' );
				}
				if ( $photos ) {
					/* translators: 1: number of photos, 2: member record number */
					$messages[] = sprintf( _n( '%1$d photo tagged with this person was not deleted, because photos can show other people. It needs reviewing by hand: in the Media Library, filter by the record "Erased member" (record %2$d).', '%1$d photos tagged with this person were not deleted, because photos can show other people. They need reviewing by hand: in the Media Library, filter by the record "Erased member" (record %2$d).', $photos, 'chess-army-knife' ), $photos, $member['id'] );
				}
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
	 * The club's data policy for members, as sections of plain text. The one
	 * source for the wording suggested in the privacy policy guide and for the
	 * Club Data Policy block, so the two cannot disagree and both follow the
	 * retention period and contact set on the Settings page.
	 *
	 * @return array[] Each { heading, paragraphs }, all plain text.
	 */
	public static function policy_sections() {
		$options = Chess_Army_Knife_Settings::get_options();
		$months  = (int) $options['member_retention_months'];
		$contact = (string) $options['data_contact_email'];

		if ( $months > 0 ) {
			/* translators: %d: number of months */
			$keep = sprintf( _n( 'We keep your details while you are a member and for %d month afterwards, then delete them. If we were paid, we keep a record of the payment for our accounts, without any personal details. Applications that are declined or never completed are deleted after the same time.', 'We keep your details while you are a member and for %d months afterwards, then delete them. If we were paid, we keep a record of the payment for our accounts, without any personal details. Applications that are declined or never completed are deleted after the same time.', $months, 'chess-army-knife' ), $months );
		} else {
			$keep = __( 'We keep your details until you ask us to delete them.', 'chess-army-knife' );
		}

		if ( '' !== $contact ) {
			/* translators: %s: email address */
			$ask = sprintf( __( 'To ask about your details, contact the club at %s.', 'chess-army-knife' ), $contact );
		} else {
			$ask = __( 'To ask about your details, contact the club.', 'chess-army-knife' );
		}

		$sections = array(
			array(
				'heading'    => __( 'What we collect', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'When you apply for membership we collect your name, the membership you want, your email address, your phone number (if you give one) and your ECF rating code (if you have one). We record the date and method of any payment, and club officers may add notes to your record.', 'chess-army-knife' ),
					__( 'We also keep the name and ECF rating code of people who take part in club events or tournaments without being members, and of anyone whose ECF rating we show on this website, marked as not being members. They are left out of our membership lists. Tournament entries refer to these records, so a person\'s details are only ever held in one place.', 'chess-army-knife' ),
					__( 'If you register for a club event we record that, with the time, the number of guests you bring and whether you came. If you are not a member we keep your name and email address as a record of a non-member. Registrations are deleted after the retention period below.', 'chess-army-knife' ),
					__( 'If you play for one of our teams we record which team or teams you are in, and whether you are its captain. Only club officers can see this.', 'chess-army-knife' ),
					__( 'For members under 18 we collect the junior\'s date of birth and a parent or guardian\'s name, email address and phone number. We write to the parent or guardian, not the junior, and only keep the junior\'s own email address or phone number if their parent or guardian has said we may contact them directly.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Why we use it', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'We use your details to run the chess club and your membership: to consider your application, to keep our list of members, to arrange club and team activities and to contact you about your membership. Our lawful basis is legitimate interests: running the club and looking after its members, together with providing your membership itself. We do not sell your details or use them for advertising.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Sharing with the English Chess Federation', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'The club provides its playing members to the English Chess Federation (ECF) so that their games can be rated. For this we give the ECF your name and ECF rating code, and any other details it needs to rate your games. We also fetch your current ECF rating from the ECF regularly and keep the latest one with your record. To do this we ask the ECF for the list of players at our club and keep only the ratings of people we already have a record of; the rest of that list is not stored. The ECF looks after its information under its own privacy policy.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Newsletters and WhatsApp groups', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'We only send you the club newsletter, or add you to a WhatsApp group for a club team, if you have said yes on the application form or to a club officer. These are separate choices: you can say no to either and still be a member. For WhatsApp we also record which team\'s group you asked to join. If you are added to a group, the other members can see your name and phone number, and WhatsApp itself handles the messages under its own terms. A junior is only added using their parent or guardian\'s agreement and number unless they say otherwise. You can change your mind at any time: use the Manage My Data page on this website, or contact us, and we will stop.', 'chess-army-knife' ),
					__( 'We also email members about their membership, such as renewal reminders, and about club events and fixtures. These are service messages, so they do not need a separate opt-in, but every email has a link to stop that kind of email. We keep a log of the emails we send (the subject and the time, not the text) for a limited time, and delete it if your details are erased. Emails for a junior go to their parent or guardian.', 'chess-army-knife' ),
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
					__( 'You can ask for a copy of your details, ask us to correct or delete them, ask us to limit how we use them, object to how we use them, or withdraw a consent at any time. A parent or guardian can do this for a junior. If you are unhappy with how we have handled your details you can complain to the Information Commissioner\'s Office at ico.org.uk.', 'chess-army-knife' ),
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
		 * Filter the sections of the club's data policy, for example to add
		 * the club's own paragraphs.
		 *
		 * @param array[] $sections Each { heading, paragraphs }, all plain text.
		 */
		return (array) apply_filters( 'Chess_Army_Knife_data_policy_sections', $sections );
	}

	/**
	 * Suggest wording for the site's privacy policy.
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text  = '<h2>' . esc_html__( 'Club membership', 'chess-army-knife' ) . '</h2>';
		$text .= '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested wording, kept up to date from your settings. You can also show it on any page with the Club Data Policy block. Review it and change it to match what your club really does; it is not legal advice.', 'chess-army-knife' ) . '</p>';
		foreach ( self::policy_sections() as $section ) {
			$text .= '<h3>' . esc_html( $section['heading'] ) . '</h3>';
			foreach ( $section['paragraphs'] as $paragraph ) {
				$text .= '<p>' . esc_html( $paragraph ) . '</p>';
			}
		}

		wp_add_privacy_policy_content( __( 'Chess Army Knife', 'chess-army-knife' ), wp_kses_post( $text ) );
	}
}

Chess_Army_Knife_Membership_Privacy::init();
