<?php
/**
 * The public membership application form: receives a submission, checks it
 * and saves it as a pending application for the club to review.
 *
 * The form itself is drawn by the Membership Application Form block. It posts
 * to admin-post.php, then sends the visitor back to the page it came from with
 * the outcome in the address (the same way WordPress's own forms do).
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Membership_Form {

	const ACTION       = 'chess_army_knife_membership_apply';
	const NONCE_FIELD  = 'chess_army_knife_membership_nonce';
	const HONEYPOT     = 'cak_website';
	const ANCHOR       = 'cak-membership-form';
	const MAX_PER_HOUR = 5;

	/**
	 * Hook up the form handler for visitors and for logged-in users.
	 */
	public static function init() {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle a submitted form and send the visitor back to the page.
	 */
	public static function handle() {
		$page = isset( $_POST['cak_redirect'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['cak_redirect'] ) ), home_url( '/' ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in submit().
		$page = remove_query_arg( array( 'cak_membership_applied', 'cak_membership_error', Chess_Army_Knife_Form_State::PARAM ), $page );

		$result = self::submit( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in submit().

		if ( is_wp_error( $result ) ) {
			$args = array(
				'cak_membership_error'             => $result->get_error_code(),
				Chess_Army_Knife_Form_State::PARAM => Chess_Army_Knife_Form_State::save( wp_unslash( $_POST ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only keeps what was typed so it can be shown again.
			);
		} else {
			$args = array( 'cak_membership_applied' => $result );
		}

		wp_safe_redirect( add_query_arg( $args, $page ) . '#' . self::ANCHOR );
		exit;
	}

	/**
	 * Check a submission and save it as a pending application.
	 *
	 * @param array $input Raw (unslashed) form values.
	 * @return int|WP_Error The new member id (0 for a submission that was quietly
	 *                      dropped as spam), or an error whose code names the problem.
	 */
	public static function submit( array $input ) {
		$nonce = isset( $input[ self::NONCE_FIELD ] ) ? sanitize_text_field( $input[ self::NONCE_FIELD ] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			return new WP_Error( 'expired', __( 'The form has expired. Please try again.', 'chess-army-knife' ) );
		}

		// Real visitors never see the hidden field, so only a bot fills it in. Say nothing, so it doesn't learn.
		if ( ! empty( $input[ self::HONEYPOT ] ) ) {
			return 0;
		}

		if ( self::is_throttled() ) {
			return new WP_Error( 'throttled', __( 'Too many applications from your connection. Please try again later.', 'chess-army-knife' ) );
		}

		if ( empty( $input['consent'] ) ) {
			return new WP_Error( 'consent', __( 'Please agree to the club keeping your details.', 'chess-army-knife' ) );
		}

		$member = Chess_Army_Knife_Membership_Store::sanitize_member( $input, false );
		if ( is_wp_error( $member ) ) {
			return $member;
		}

		self::count_attempt();

		$id = Chess_Army_Knife_Membership_Store::save_member(
			$member + array(
				'status'     => Chess_Army_Knife_Membership_Store::STATUS_PENDING,
				'source'     => Chess_Army_Knife_Membership_Store::SOURCE_FORM,
				'consent_at' => current_time( 'mysql', true ), // When the applicant confirmed they had read how the club uses their details.
			)
		);

		// A save that did not happen must never look like success to the applicant.
		if ( ! $id ) {
			return new WP_Error( 'save_failed', __( 'Sorry, we could not save your application. Please try again, or contact the club.', 'chess-army-knife' ) );
		}

		return $id;
	}

	/**
	 * A message for an error code passed back in the address.
	 *
	 * @param string $code Error code from submit().
	 * @return string
	 */
	public static function error_message( $code ) {
		$messages = array(
			'expired'         => __( 'The form has expired. Please try again.', 'chess-army-knife' ),
			'save_failed'     => __( 'Sorry, we could not save your application. Please try again, or contact the club.', 'chess-army-knife' ),
			'throttled'       => __( 'Too many applications from your connection. Please try again later.', 'chess-army-knife' ),
			'consent'         => __( 'Please agree to the club keeping your details.', 'chess-army-knife' ),
			'member_name'     => __( 'Please enter your name.', 'chess-army-knife' ),
			'member_email'    => __( 'Please enter a valid email address.', 'chess-army-knife' ),
			'member_dob'      => __( 'Please enter a valid date of birth.', 'chess-army-knife' ),
			'member_type'     => __( 'Please choose a membership type.', 'chess-army-knife' ),
			'member_whatsapp' => __( 'Please give a phone number to be added to the WhatsApp group.', 'chess-army-knife' ),
			'member_guardian' => __( 'Please give a parent or guardian\'s name and email address.', 'chess-army-knife' ),
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Something went wrong. Please try again.', 'chess-army-knife' );
	}

	/**
	 * Transient key that counts one visitor's recent applications.
	 *
	 * @return string
	 */
	protected static function throttle_key() {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'chess_army_knife_apply_' . md5( $address );
	}

	/**
	 * Whether this visitor has already applied too often in the last hour.
	 *
	 * @return bool
	 */
	protected static function is_throttled() {
		return (int) get_transient( self::throttle_key() ) >= self::MAX_PER_HOUR;
	}

	/**
	 * Note that this visitor has just applied.
	 */
	protected static function count_attempt() {
		set_transient( self::throttle_key(), (int) get_transient( self::throttle_key() ) + 1, HOUR_IN_SECONDS );
	}
}

Chess_Army_Knife_Membership_Form::init();
