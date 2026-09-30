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

		foreach ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) as $member ) {
			if ( 'anonymised' === Chess_Army_Knife_Membership_Store::erase_member( $member['id'] ) ) {
				$retained = true;
			}
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'A payment record was kept for the club\'s accounts, without any personal details.', 'chess-army-knife' ) ) : array(),
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
					__( 'The club provides its playing members to the English Chess Federation (ECF) so that their games can be rated. For this we give the ECF your name and ECF rating code, and any other details it needs to rate your games. The ECF looks after that information under its own privacy policy.', 'chess-army-knife' ),
				),
			),
			array(
				'heading'    => __( 'Newsletters and WhatsApp groups', 'chess-army-knife' ),
				'paragraphs' => array(
					__( 'We only send you the club newsletter, or add you to a WhatsApp group for a club team, if you have said yes. These are separate choices and you can say no to either and still be a member. If you are added to a WhatsApp group, the other members of the group can see your name and phone number, and WhatsApp itself handles the messages under its own terms. A junior is only added to a group with their parent or guardian\'s agreement, using the parent or guardian\'s number unless they say otherwise. You can change your mind at any time and we will stop.', 'chess-army-knife' ),
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
