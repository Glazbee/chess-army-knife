<?php
/**
 * The member portal: a member sees and corrects their own details, chooses what
 * the club may email them about, sees their membership and event places, and
 * can delete their own data, all without an account.
 *
 * Access is by an emailed private link to an address the club holds (a
 * junior's is reached through a parent's address). The link opens a session
 * that lasts a couple of hours; every change is a form with its own nonce that
 * is also checked against the session, and only records under that address can
 * be touched. The answer to asking for a link never says whether an address is
 * on file, and the emails one visitor or address can cause are limited.
 *
 * An email address is what identifies a member, so changing one is confirmed
 * from the new address before it takes effect, and the old address is told.
 * Deleting a record is immediate once confirmed, using the same erasure as the
 * officers' and WordPress's own tools: the record is deleted, or, if a payment,
 * photos or tournament entries tie it to the club's
 * accounts, kept without any personal details.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Member_Portal {

	const ACTION_LINK          = 'chess_army_knife_portal_link';
	const ACTION_DETAILS       = 'chess_army_knife_portal_details';
	const ACTION_CHOICES       = 'chess_army_knife_portal_choices';
	const ACTION_EMAIL         = 'chess_army_knife_portal_email';
	const ACTION_EMAIL_CONFIRM = 'chess_army_knife_portal_email_confirm';
	const ACTION_DELETE        = 'chess_army_knife_portal_delete';
	const ACTION_SIGNOUT       = 'chess_army_knife_portal_signout';
	const ACTION_EXTEND        = 'chess_army_knife_portal_extend';
	const NONCE_FIELD          = 'chess_army_knife_portal_nonce';
	const HONEYPOT             = 'cak_url';
	const ANCHOR               = 'cak-portal';
	const SESSION_KEY          = 'chess_army_knife_portal_';
	const EMAIL_CHANGE_KEY     = 'chess_army_knife_emailchg_';

	/** The fields of a record whose value is an email address that can be changed. */
	const EMAIL_FIELDS = array( 'email', 'guardian_email' );

	/**
	 * Hook up the form handlers.
	 */
	public static function init() {
		foreach ( array(
			self::ACTION_LINK          => 'handle_link',
			self::ACTION_DETAILS       => 'handle_details',
			self::ACTION_CHOICES       => 'handle_choices',
			self::ACTION_EMAIL         => 'handle_email',
			self::ACTION_EMAIL_CONFIRM => 'handle_email_confirm',
			self::ACTION_DELETE        => 'handle_delete',
			self::ACTION_SIGNOUT       => 'handle_signout',
			self::ACTION_EXTEND        => 'handle_extend',
		) as $action => $method ) {
			add_action( 'admin_post_nopriv_' . $action, array( __CLASS__, $method ) );
			add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
		}
	}

	/** Seconds before a session ends at which the page starts warning the visitor. */
	const WARN_SECONDS = 900;

	/**
	 * How long a session lasts, in seconds. Every save, and the Extend button, starts it again.
	 *
	 * @return int
	 */
	public static function session_length() {
		/**
		 * Filter how long an emailed portal link keeps the portal open.
		 *
		 * @param int $seconds Default one hour.
		 */
		return max( 5 * MINUTE_IN_SECONDS, (int) apply_filters( 'Chess_Army_Knife_portal_session_seconds', HOUR_IN_SECONDS ) );
	}

	/* -------------------------------------------------------------
	 * Form handlers
	 * ------------------------------------------------------------- */

	/**
	 * The page a form was sent from.
	 *
	 * @return string
	 */
	protected static function page_url() {
		$url = isset( $_POST['cak_redirect'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['cak_redirect'] ) ), home_url( '/' ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by the caller before anything is changed.
		return remove_query_arg( array( 'cak_portal', 'cak_portal_msg', 'cak_portal_sent', 'cak_portal_error', 'cak_email', Chess_Army_Knife_Form_State::PARAM ), $url );
	}

	/**
	 * Send the visitor back to the portal with a message.
	 *
	 * @param array       $args  Query arguments (a message or error code).
	 * @param string|null $token Session token to keep them signed in, or null to sign them out.
	 */
	protected static function back( array $args, $token = null ) {
		if ( $token ) {
			$args['cak_portal'] = $token;
		}
		wp_safe_redirect( add_query_arg( $args, self::page_url() ) . '#' . self::ANCHOR );
		exit;
	}

	/**
	 * The session token sent with a form.
	 *
	 * @return string
	 */
	protected static function posted_token() {
		return isset( $_POST['token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by the action before anything is changed.
	}

	/**
	 * Run an action and send the visitor back with its outcome.
	 *
	 * @param string $message Message code to show on success.
	 * @param mixed  $result  The action's result: true, a message code, or an error.
	 */
	protected static function finish( $message, $result ) {
		$token = self::posted_token();
		// Saving something counts as being here, so the session starts again.
		if ( ! is_wp_error( $result ) ) {
			self::extend_session( $token );
		}
		if ( is_wp_error( $result ) ) {
			self::back(
				array(
					'cak_portal_error'                 => $result->get_error_code(),
					Chess_Army_Knife_Form_State::PARAM => Chess_Army_Knife_Form_State::save( wp_unslash( $_POST ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only keeps what was typed so it can be shown again.
				),
				self::session( $token ) ? $token : null
			);
		}
		self::back( array( 'cak_portal_msg' => is_string( $result ) ? $result : $message ), self::session( $token ) ? $token : null );
	}

	/**
	 * Handle the form asking for a link.
	 */
	public static function handle_link() {
		$result = self::request_link( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in request_link().
		if ( is_wp_error( $result ) ) {
			self::back(
				array(
					'cak_portal_error'                 => $result->get_error_code(),
					Chess_Army_Knife_Form_State::PARAM => Chess_Army_Knife_Form_State::save( wp_unslash( $_POST ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only keeps what was typed so it can be shown again.
				)
			);
		}
		self::back( array( 'cak_portal_sent' => '1' ) );
	}

	/**
	 * Handle the details form.
	 */
	public static function handle_details() {
		self::finish( 'details', self::save_details( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in save_details().
	}

	/**
	 * Handle the email choices form.
	 */
	public static function handle_choices() {
		self::finish( 'choices', self::save_choices( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in save_choices().
	}

	/**
	 * Handle the change-of-address form.
	 */
	public static function handle_email() {
		self::finish( 'email_sent', self::request_email_change( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in request_email_change().
	}

	/**
	 * Handle the confirmation of a new address.
	 */
	public static function handle_email_confirm() {
		$result = self::confirm_email_change( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in confirm_email_change().
		self::back( is_wp_error( $result ) ? array( 'cak_portal_error' => $result->get_error_code() ) : array( 'cak_portal_msg' => 'email_done' ) );
	}

	/**
	 * Handle deleting a record.
	 */
	public static function handle_delete() {
		self::finish( 'deleted', self::delete_person( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in delete_person().
	}

	/**
	 * Handle the Extend my session button.
	 */
	public static function handle_extend() {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		$token = self::posted_token();
		if ( wp_verify_nonce( $nonce, self::ACTION_EXTEND ) && self::extend_session( $token ) ) {
			self::back( array( 'cak_portal_msg' => 'extended' ), $token );
		}
		self::back( array( 'cak_portal_error' => 'link' ) );
	}

	/**
	 * Handle signing out.
	 */
	public static function handle_signout() {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( wp_verify_nonce( $nonce, self::ACTION_SIGNOUT ) ) {
			delete_transient( self::SESSION_KEY . self::posted_token() );
		}
		self::back( array( 'cak_portal_msg' => 'signed_out' ) );
	}

	/* -------------------------------------------------------------
	 * Signing in
	 * ------------------------------------------------------------- */

	/**
	 * Email a private link to the portal.
	 *
	 * @param array $input Raw (unslashed) form values: email.
	 * @return true|WP_Error True whether or not the address is on file, so it cannot be probed.
	 */
	public static function request_link( array $input ) {
		$nonce = isset( $input[ self::NONCE_FIELD ] ) ? sanitize_text_field( $input[ self::NONCE_FIELD ] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION_LINK ) ) {
			return new WP_Error( 'expired', __( 'The form has expired. Please try again.', 'chess-army-knife' ) );
		}
		if ( ! empty( $input[ self::HONEYPOT ] ) ) {
			return true; // A bot: say nothing.
		}

		$email = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'email', __( 'Please enter a valid email address.', 'chess-army-knife' ) );
		}

		$ip_key    = Chess_Army_Knife_Member_Requests::visitor_key();
		$email_key = 'chess_army_knife_portal_email_' . md5( strtolower( $email ) );
		if ( Chess_Army_Knife_Member_Requests::over_limit( $ip_key ) ) {
			return new WP_Error( 'throttled', __( 'Too many requests from your connection. Please try again later.', 'chess-army-knife' ) );
		}
		if ( Chess_Army_Knife_Member_Requests::over_limit( $email_key ) ) {
			return true;
		}
		Chess_Army_Knife_Member_Requests::count( $ip_key );
		Chess_Army_Knife_Member_Requests::count( $email_key );

		if ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) ) {
			$token = wp_generate_password( 32, false );
			self::store_session( $token, $email );

			$site = Chess_Army_Knife_Settings::club_name();
			$link = add_query_arg( 'cak_portal', $token, self::page_url() ) . '#' . self::ANCHOR;
			/* translators: %s: site name */
			$subject = sprintf( __( '[%s] Your membership details', 'chess-army-knife' ), $site );
			/* translators: 1: site name, 2: link, 3: minutes the link works for */
			$body = sprintf( __( "Someone asked to see and change the details %1\$s holds for this email address.\n\nIf that was you, open this link within %3\$d minutes:\n\n%2\$s\n\nIf it was not you, ignore this email and nothing will change.", 'chess-army-knife' ), $site, $link, max( 1, (int) round( self::session_length() / MINUTE_IN_SECONDS ) ) );
			wp_mail( $email, $subject, $body );
		}
		return true;
	}

	/**
	 * The session a link opened.
	 *
	 * @param string $token Token from the link.
	 * @return array|null { email, people, expires }, or null if the link is not valid, has expired, or the address is no longer on any record. expires is a Unix time.
	 */
	public static function session( $token ) {
		$token  = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		$stored = '' === $token ? false : get_transient( self::SESSION_KEY . $token );
		// A session made before sessions had an end time is just the address.
		$email   = is_array( $stored ) && isset( $stored['email'] ) ? $stored['email'] : $stored;
		$expires = is_array( $stored ) && isset( $stored['expires'] ) ? (int) $stored['expires'] : 0;
		if ( ! is_string( $email ) || '' === $email ) {
			return null;
		}

		$people = Chess_Army_Knife_Membership_Store::get_members_by_email( $email );
		return $people ? array(
			'email'   => $email,
			'expires' => $expires,
			'people'  => $people,
		) : null;
	}

	/**
	 * Start (or restart) a session.
	 *
	 * @param string $token Session token.
	 * @param string $email The address the session is for.
	 * @return int When it ends, as a Unix time.
	 */
	protected static function store_session( $token, $email ) {
		$expires = time() + self::session_length();
		set_transient(
			self::SESSION_KEY . $token,
			array(
				'email'   => $email,
				'expires' => $expires,
			),
			self::session_length()
		);
		return $expires;
	}

	/**
	 * Give a live session its full length again.
	 *
	 * @param string $token Session token.
	 * @return int When it now ends as a Unix time, or 0 if there is no session.
	 */
	public static function extend_session( $token ) {
		$session = self::session( $token );
		return $session ? self::store_session( preg_replace( '/[^A-Za-z0-9]/', '', (string) $token ), $session['email'] ) : 0;
	}

	/**
	 * A record the session may touch.
	 *
	 * @param string $token     Session token.
	 * @param int    $person_id Record id.
	 * @return array|WP_Error The record, or an error if the link is not valid or the record is not under the session's address.
	 */
	protected static function person_in_session( $token, $person_id ) {
		$session = self::session( $token );
		if ( ! $session ) {
			return new WP_Error( 'link', __( 'That link has expired. Please ask for a new one.', 'chess-army-knife' ) );
		}
		foreach ( $session['people'] as $person ) {
			if ( $person['id'] === (int) $person_id ) {
				return $person;
			}
		}
		return new WP_Error( 'person', __( 'That record could not be found.', 'chess-army-knife' ) );
	}

	/**
	 * Check the nonce of one of the portal's forms.
	 *
	 * @param array  $input  Form values.
	 * @param string $action Action the nonce was made for.
	 * @return true|WP_Error
	 */
	protected static function check_nonce( array $input, $action ) {
		$nonce = isset( $input[ self::NONCE_FIELD ] ) ? sanitize_text_field( $input[ self::NONCE_FIELD ] ) : '';
		return wp_verify_nonce( $nonce, $action ) ? true : new WP_Error( 'expired', __( 'The form has expired. Please try again.', 'chess-army-knife' ) );
	}

	/**
	 * The session and record a posted form is about.
	 *
	 * @param array  $input  Form values: token and person.
	 * @param string $action Action the nonce was made for.
	 * @return array|WP_Error The record.
	 */
	protected static function posted_person( array $input, $action ) {
		$valid = self::check_nonce( $input, $action );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		return self::person_in_session( isset( $input['token'] ) ? $input['token'] : '', isset( $input['person'] ) ? absint( $input['person'] ) : 0 );
	}

	/* -------------------------------------------------------------
	 * Changing details and choices
	 * ------------------------------------------------------------- */

	/**
	 * Whether a record is a junior, who is written to through a parent or guardian.
	 *
	 * @param array $person Record.
	 * @return bool
	 */
	public static function is_junior( array $person ) {
		return '' !== $person['date_of_birth'] && Chess_Army_Knife_Memberships::is_under_18( $person['date_of_birth'], current_time( 'Y-m-d' ) );
	}

	/**
	 * Save a member's own details. The date of birth, membership and payment are the club's to set, and an email address changes only through a confirmed request.
	 *
	 * @param array $input Raw (unslashed) form values: token, person, name, phone, ecf_code, and for a junior guardian_name and guardian_phone.
	 * @return true|WP_Error
	 */
	public static function save_details( array $input ) {
		$person = self::posted_person( $input, self::ACTION_DETAILS );
		if ( is_wp_error( $person ) ) {
			return $person;
		}

		$name = isset( $input['name'] ) ? trim( sanitize_text_field( $input['name'] ) ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'name', __( 'Please enter a name.', 'chess-army-knife' ) );
		}

		$update = array(
			'id'       => $person['id'],
			'name'     => $name,
			'ecf_code' => isset( $input['ecf_code'] ) ? strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', $input['ecf_code'] ) ) : '',
		);

		// A junior's own phone is only kept if a parent said the junior may be contacted directly, which is the officers' record to keep.
		$update['phone'] = self::is_junior( $person ) && '' === $person['phone'] ? '' : ( isset( $input['phone'] ) ? sanitize_text_field( $input['phone'] ) : $person['phone'] );
		if ( '' !== $person['guardian_name'] . $person['guardian_email'] ) {
			$update['guardian_name']  = isset( $input['guardian_name'] ) ? sanitize_text_field( $input['guardian_name'] ) : $person['guardian_name'];
			$update['guardian_phone'] = isset( $input['guardian_phone'] ) ? sanitize_text_field( $input['guardian_phone'] ) : $person['guardian_phone'];
			if ( '' === $update['guardian_name'] ) {
				return new WP_Error( 'guardian', __( 'Please give a parent or guardian\'s name.', 'chess-army-knife' ) );
			}
		}

		// A WhatsApp group shows a phone number, so one must stay on the record while they are in one.
		$phones = $update['phone'] . ( isset( $update['guardian_phone'] ) ? $update['guardian_phone'] : $person['guardian_phone'] );
		if ( '' !== $person['whatsapp_consent_at'] && '' === $phones ) {
			return new WP_Error( 'whatsapp_phone', __( 'You are in a WhatsApp group, which needs a phone number. Turn that off first if you want to remove it.', 'chess-army-knife' ) );
		}

		// A different rating code means a different player, so the stored rating is fetched again.
		$code_changed = strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', $person['ecf_code'] ) ) !== $update['ecf_code'];

		Chess_Army_Knife_Membership_Store::save_member( $update );
		if ( $code_changed ) {
			Chess_Army_Knife_Membership_Store::clear_rating( $person['id'] );
		}
		return true;
	}

	/**
	 * Save what a member is happy to be emailed about, and WhatsApp.
	 *
	 * @param array $input Raw (unslashed) form values: token, person, category[key] for each ticked category, whatsapp, teams[].
	 * @return true|WP_Error
	 */
	public static function save_choices( array $input ) {
		$person = self::posted_person( $input, self::ACTION_CHOICES );
		if ( is_wp_error( $person ) ) {
			return $person;
		}

		$wanted = isset( $input['category'] ) && is_array( $input['category'] ) ? array_keys( $input['category'] ) : array();
		foreach ( array_keys( Chess_Army_Knife_Notification_Preferences::categories() ) as $category ) {
			$on = in_array( $category, $wanted, true );
			if ( $on && ! Chess_Army_Knife_Notification_Preferences::allows( $person, $category ) ) {
				Chess_Army_Knife_Notification_Preferences::opt_in( $person['id'], $category );
			} elseif ( ! $on && Chess_Army_Knife_Notification_Preferences::allows( $person, $category ) ) {
				Chess_Army_Knife_Notification_Preferences::opt_out( $person['id'], $category );
			}
		}

		$whatsapp = ! empty( $input['whatsapp'] );
		if ( $whatsapp && '' === $person['phone'] . $person['guardian_phone'] ) {
			return new WP_Error( 'whatsapp_phone', __( 'Please add a phone number to your details to be added to a WhatsApp group.', 'chess-army-knife' ) );
		}
		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'id'                  => $person['id'],
				'whatsapp_consent_at' => $whatsapp ? ( '' !== $person['whatsapp_consent_at'] ? $person['whatsapp_consent_at'] : current_time( 'mysql', true ) ) : null, // An existing agreement keeps its original time.
			)
		);
		return true;
	}

	/* -------------------------------------------------------------
	 * Changing an email address
	 * ------------------------------------------------------------- */

	/**
	 * Ask to change an email address: a link is sent to the new address, and nothing changes until it is followed.
	 *
	 * @param array $input Raw (unslashed) form values: token, person, field (email or guardian_email), new_email.
	 * @return string|WP_Error 'email_sent', or an error.
	 */
	public static function request_email_change( array $input ) {
		$person = self::posted_person( $input, self::ACTION_EMAIL );
		if ( is_wp_error( $person ) ) {
			return $person;
		}

		$field = isset( $input['field'] ) ? sanitize_key( $input['field'] ) : '';
		$new   = isset( $input['new_email'] ) ? sanitize_email( $input['new_email'] ) : '';
		if ( ! in_array( $field, self::EMAIL_FIELDS, true ) || '' === $person[ $field ] ) {
			return new WP_Error( 'person', __( 'That record could not be found.', 'chess-army-knife' ) );
		}
		if ( ! is_email( $new ) ) {
			return new WP_Error( 'email', __( 'Please enter a valid email address.', 'chess-army-knife' ) );
		}
		if ( 0 === strcasecmp( $new, $person[ $field ] ) ) {
			return new WP_Error( 'same_email', __( 'That is the address already held.', 'chess-army-knife' ) );
		}

		$ip_key = Chess_Army_Knife_Member_Requests::visitor_key();
		if ( Chess_Army_Knife_Member_Requests::over_limit( $ip_key ) ) {
			return new WP_Error( 'throttled', __( 'Too many requests from your connection. Please try again later.', 'chess-army-knife' ) );
		}
		Chess_Army_Knife_Member_Requests::count( $ip_key );

		$token = wp_generate_password( 32, false );
		set_transient(
			self::EMAIL_CHANGE_KEY . $token,
			array(
				'person' => $person['id'],
				'field'  => $field,
				'old'    => $person[ $field ],
				'new'    => $new,
			),
			DAY_IN_SECONDS
		);

		$site = Chess_Army_Knife_Settings::club_name();
		$link = add_query_arg( 'cak_email', $token, self::page_url() ) . '#' . self::ANCHOR;
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Confirm your new email address', 'chess-army-knife' ), $site );
		/* translators: 1: site name, 2: link, 3: hours the link works for */
		$body = sprintf( __( "Someone asked %1\$s to use this email address instead of an old one.\n\nIf that was you, open this link within %3\$d hours to confirm:\n\n%2\$s\n\nIf it was not you, ignore this email and nothing will change.", 'chess-army-knife' ), $site, $link, 24 );
		wp_mail( $new, $subject, $body );

		return 'email_sent';
	}

	/**
	 * What a confirmation link for a new address is for.
	 *
	 * @param string $token Token from the link.
	 * @return array|null { person, field, old, new } or null if the link has expired or the record has changed since.
	 */
	public static function pending_email_change( $token ) {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		$data  = '' === $token ? false : get_transient( self::EMAIL_CHANGE_KEY . $token );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$person = Chess_Army_Knife_Membership_Store::get_member( $data['person'] );
		return $person && $person[ $data['field'] ] === $data['old'] ? $data : null;
	}

	/**
	 * Confirm a new address. The old address, if there was one, is told.
	 *
	 * @param array $input Raw (unslashed) form values: token.
	 * @return true|WP_Error
	 */
	public static function confirm_email_change( array $input ) {
		$valid = self::check_nonce( $input, self::ACTION_EMAIL_CONFIRM );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$pending = self::pending_email_change( isset( $input['token'] ) ? $input['token'] : '' );
		if ( ! $pending ) {
			return new WP_Error( 'link', __( 'That link has expired or is not valid. Please ask for the change again.', 'chess-army-knife' ) );
		}

		Chess_Army_Knife_Membership_Store::save_member(
			array(
				'id'              => $pending['person'],
				$pending['field'] => $pending['new'],
			)
		);
		delete_transient( self::EMAIL_CHANGE_KEY . preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['token'] ) );

		$site = Chess_Army_Knife_Settings::club_name();
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your email address was changed', 'chess-army-knife' ), $site );
		/* translators: 1: site name, 2: the new email address */
		$body = sprintf( __( "The email address %1\$s holds for you was changed to %2\$s.\n\nIf you did not ask for this, please contact the club straight away.", 'chess-army-knife' ), $site, $pending['new'] );
		wp_mail( $pending['old'], $subject, $body );

		return true;
	}

	/* -------------------------------------------------------------
	 * Deleting
	 * ------------------------------------------------------------- */

	/**
	 * Delete a member's own record, straight away. It is removed, or, if a payment, photos or tournament entries tie it to the club's accounts, kept without any personal details.
	 *
	 * @param array $input Raw (unslashed) form values: token, person, confirm.
	 * @return string|WP_Error 'deleted' or 'anonymised', or an error.
	 */
	public static function delete_person( array $input ) {
		$person = self::posted_person( $input, self::ACTION_DELETE );
		if ( is_wp_error( $person ) ) {
			return $person;
		}
		if ( empty( $input['confirm'] ) ) {
			return new WP_Error( 'confirm', __( 'Please tick the box to confirm you want your details deleted.', 'chess-army-knife' ) );
		}

		// A person who deletes their own details asked to go: they are not recorded again by accident.
		$result = Chess_Army_Knife_Membership_Store::erase_member( $person['id'], true );
		return '' === $result ? new WP_Error( 'person', __( 'That record could not be found.', 'chess-army-knife' ) ) : $result;
	}

	/* -------------------------------------------------------------
	 * Messages
	 * ------------------------------------------------------------- */

	/**
	 * A message for an outcome code passed back in the address.
	 *
	 * @param string $code Message code.
	 * @return string
	 */
	public static function message( $code ) {
		$messages = array(
			'details'    => __( 'Your details have been saved.', 'chess-army-knife' ),
			'choices'    => __( 'Your choices have been saved.', 'chess-army-knife' ),
			'email_sent' => __( 'We have emailed a link to the new address. Your address changes when you follow it.', 'chess-army-knife' ),
			'email_done' => __( 'Your email address has been changed. To carry on, ask for a new link at the new address.', 'chess-army-knife' ),
			'deleted'    => __( 'Your details have been deleted.', 'chess-army-knife' ),
			'anonymised' => __( 'Your personal details have been deleted. A record without your details is kept because it is tied to the club\'s accounts, such as a payment, tournament or event.', 'chess-army-knife' ),
			'signed_out' => __( 'You have signed out.', 'chess-army-knife' ),
			'extended'   => __( 'Your session has been extended.', 'chess-army-knife' ),
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : '';
	}

	/**
	 * A message for an error code passed back in the address.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( $code ) {
		$messages = array(
			'expired'        => __( 'The form has expired. Please try again.', 'chess-army-knife' ),
			'email'          => __( 'Please enter a valid email address.', 'chess-army-knife' ),
			'same_email'     => __( 'That is the address already held.', 'chess-army-knife' ),
			'throttled'      => __( 'Too many requests from your connection. Please try again later.', 'chess-army-knife' ),
			'link'           => __( 'That link has expired or is not valid. Please ask for a new one.', 'chess-army-knife' ),
			'person'         => __( 'That record could not be found.', 'chess-army-knife' ),
			'name'           => __( 'Please enter a name.', 'chess-army-knife' ),
			'guardian'       => __( 'Please give a parent or guardian\'s name.', 'chess-army-knife' ),
			'whatsapp_phone' => __( 'WhatsApp groups need a phone number on your details.', 'chess-army-knife' ),
			'confirm'        => __( 'Please tick the box to confirm you want your details deleted.', 'chess-army-knife' ),
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Something went wrong. Please try again.', 'chess-army-knife' );
	}
}

Chess_Army_Knife_Member_Portal::init();
