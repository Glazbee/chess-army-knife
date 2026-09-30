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
				__( 'Agreed to the club keeping these details (UTC)', 'chess-army-knife' ) => $member['consent_at'],
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
	 * Suggest wording for the site's privacy policy.
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$options = Chess_Army_Knife_Settings::get_options();
		$months  = (int) $options['member_retention_months'];

		$text  = '<h2>' . esc_html__( 'Club membership', 'chess-army-knife' ) . '</h2>';
		$text .= '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested wording for the club membership application form. Review it and change it to match what your club really does; it is not legal advice.', 'chess-army-knife' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If you apply for membership through our form, we collect your name, email address, date of birth, phone number and ECF rating code (if you give them) and, for junior members, a parent or guardian\'s name. We use these only to consider your application, to run your membership and to contact you about it. We keep a record of when you agreed to this.', 'chess-army-knife' ) . '</p>';
		$text .= '<p>' . esc_html__( 'We do not take payments on this website. If you pay by bank transfer or in cash, we record the date and method of payment against your membership.', 'chess-army-knife' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Only club officers who have been given access can see membership details. We do not share them with anyone else.', 'chess-army-knife' ) . '</p>';
		if ( $months > 0 ) {
			/* translators: %d: number of months */
			$text .= '<p>' . esc_html( sprintf( _n( 'We keep your details while you are a member and for %d month afterwards, then delete them. If we were paid, we keep a record of the payment for our accounts, without your personal details.', 'We keep your details while you are a member and for %d months afterwards, then delete them. If we were paid, we keep a record of the payment for our accounts, without your personal details.', $months, 'chess-army-knife' ), $months ) ) . '</p>';
		} else {
			$text .= '<p>' . esc_html__( 'We keep your details until you ask us to delete them.', 'chess-army-knife' ) . '</p>';
		}
		$text .= '<p>' . esc_html__( 'You can ask us for a copy of your details, ask us to correct or delete them, or withdraw your consent at any time by contacting the club.', 'chess-army-knife' ) . '</p>';
		$text .= '<p>' . esc_html__( 'To limit repeated submissions, the form briefly remembers a scrambled version of your internet address for one hour. It is not kept after that.', 'chess-army-knife' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Chess Army Knife', 'chess-army-knife' ), wp_kses_post( $text ) );
	}
}

Chess_Army_Knife_Membership_Privacy::init();
