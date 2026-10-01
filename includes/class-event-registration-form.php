<?php
/**
 * The public side of event registration, with no account needed.
 *
 * A visitor gives their name and email address and the number of guests. We
 * email a private link to that address (so nobody can register someone else),
 * and registering happens when the link's page is confirmed. The address is
 * matched to the people the club already holds: a member, a guest, or a
 * junior through a parent's address; if there is nobody, the visitor is
 * recorded as a guest (not a member). The answer never says whether an
 * address is on file, and the emails one visitor or address can cause are
 * limited. Every confirmation email carries a signed link to cancel.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Event_Registration_Form {

	const ACTION_REQUEST = 'chess_army_knife_event_register';
	const ACTION_CONFIRM = 'chess_army_knife_event_confirm';
	const ACTION_CANCEL  = 'chess_army_knife_event_cancel';
	const NONCE_FIELD    = 'chess_army_knife_event_nonce';
	const HONEYPOT       = 'cak_url';
	const ANCHOR         = 'cak-registration';
	const TOKEN_KEY      = 'chess_army_knife_reg_';
	const CATEGORY       = 'event_notices';

	/**
	 * Hook up the form handlers.
	 */
	public static function init() {
		foreach ( array(
			self::ACTION_REQUEST => 'handle_request',
			self::ACTION_CONFIRM => 'handle_confirm',
			self::ACTION_CANCEL  => 'handle_cancel',
		) as $action => $method ) {
			add_action( 'admin_post_nopriv_' . $action, array( __CLASS__, $method ) );
			add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
		}
	}

	/**
	 * The page a form was sent from, so the visitor can be sent back to it.
	 *
	 * @return string
	 */
	protected static function page_url() {
		$url = isset( $_POST['cak_redirect'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['cak_redirect'] ) ), home_url( '/' ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by the caller before anything is changed.
		return remove_query_arg( array( 'cak_reg', 'cak_reg_sent', 'cak_reg_error', 'cak_reg_done', 'cak_cancel', 'cak_cancel_done', Chess_Army_Knife_Form_State::PARAM ), $url );
	}

	/**
	 * Send the visitor back to the page with a message code.
	 *
	 * @param array $args Query arguments.
	 */
	protected static function back( array $args ) {
		wp_safe_redirect( add_query_arg( $args, self::page_url() ) . '#' . self::ANCHOR );
		exit;
	}

	/**
	 * Handle the registration form.
	 */
	public static function handle_request() {
		$result = self::request( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in request().
		if ( is_wp_error( $result ) ) {
			self::back(
				array(
					'cak_reg_error'                    => $result->get_error_code(),
					Chess_Army_Knife_Form_State::PARAM => Chess_Army_Knife_Form_State::save( wp_unslash( $_POST ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only keeps what was typed so it can be shown again.
				)
			);
		}
		self::back( array( 'cak_reg_sent' => '1' ) );
	}

	/**
	 * Handle the confirmation page.
	 */
	public static function handle_confirm() {
		$result = self::confirm( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in confirm().
		self::back( is_wp_error( $result ) ? array( 'cak_reg_error' => $result->get_error_code() ) : array( 'cak_reg_done' => $result ) );
	}

	/**
	 * Handle the cancellation page.
	 */
	public static function handle_cancel() {
		$result = self::cancel( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in cancel().
		self::back( is_wp_error( $result ) ? array( 'cak_reg_error' => $result->get_error_code() ) : array( 'cak_cancel_done' => '1' ) );
	}

	/**
	 * Start a registration: email a private link to confirm it.
	 *
	 * @param array $input Raw (unslashed) form values.
	 * @return true|WP_Error True whether or not the address is on file, so it cannot be probed.
	 */
	public static function request( array $input ) {
		$nonce = isset( $input[ self::NONCE_FIELD ] ) ? sanitize_text_field( $input[ self::NONCE_FIELD ] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION_REQUEST ) ) {
			return new WP_Error( 'expired', __( 'The form has expired. Please try again.', 'chess-army-knife' ) );
		}
		if ( ! empty( $input[ self::HONEYPOT ] ) ) {
			return true; // A bot: say nothing.
		}

		$event_id = isset( $input['event_id'] ) ? absint( $input['event_id'] ) : 0;
		$name     = isset( $input['name'] ) ? trim( sanitize_text_field( $input['name'] ) ) : '';
		$email    = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';
		$guests   = isset( $input['guests'] ) ? absint( $input['guests'] ) : 0;

		if ( ! Chess_Army_Knife_Event_Registrations::is_open( $event_id ) ) {
			return new WP_Error( 'closed', __( 'Registration for this event is closed.', 'chess-army-knife' ) );
		}
		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'details', __( 'Please enter your name and a valid email address.', 'chess-army-knife' ) );
		}
		if ( empty( $input['consent'] ) ) {
			return new WP_Error( 'consent', __( 'Please tick the box to say the club may keep your details for this event.', 'chess-army-knife' ) );
		}

		$ip_key    = Chess_Army_Knife_Member_Requests::visitor_key();
		$email_key = 'chess_army_knife_reg_email_' . md5( strtolower( $email ) );
		if ( Chess_Army_Knife_Member_Requests::over_limit( $ip_key ) ) {
			return new WP_Error( 'throttled', __( 'Too many requests from your connection. Please try again later.', 'chess-army-knife' ) );
		}
		// One address cannot be used to send a flood of emails; the visitor is told nothing different.
		if ( Chess_Army_Knife_Member_Requests::over_limit( $email_key ) ) {
			return true;
		}
		Chess_Army_Knife_Member_Requests::count( $ip_key );
		Chess_Army_Knife_Member_Requests::count( $email_key );

		$token = wp_generate_password( 32, false );
		set_transient(
			self::TOKEN_KEY . $token,
			array(
				'event_id' => $event_id,
				'email'    => $email,
				'name'     => $name,
				'guests'   => $guests,
			),
			DAY_IN_SECONDS
		);

		$event = Chess_Army_Knife_Events::data( get_post( $event_id ) );
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$link  = add_query_arg( 'cak_reg', $token, self::event_url( $event_id ) ) . '#' . self::ANCHOR;

		/* translators: 1: site name, 2: event name */
		$subject = sprintf( __( '[%1$s] Confirm your place: %2$s', 'chess-army-knife' ), $site, $event['title'] );
		/* translators: 1: event name, 2: link, 3: hours the link works for */
		$body = sprintf( __( "Someone asked to register for %1\$s using this email address.\n\nIf that was you, open this link within %3\$d hours to confirm your place:\n\n%2\$s\n\nIf it was not you, ignore this email and nothing will happen.", 'chess-army-knife' ), $event['title'], $link, 24 );
		wp_mail( $email, $subject, $body );

		return true;
	}

	/**
	 * What a confirmation link is for.
	 *
	 * @param string $token Token from the link.
	 * @return array|null { event_id, email, name, guests, people }, or null if the link is not valid or has expired. "people" are the records the address belongs to that can take part.
	 */
	public static function pending( $token ) {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		$data  = '' === $token ? false : get_transient( self::TOKEN_KEY . $token );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$data['people'] = array_values( array_filter( Chess_Army_Knife_Membership_Store::get_members_by_email( $data['email'] ), array( 'Chess_Army_Knife_Membership_Store', 'can_play' ) ) );
		return $data;
	}

	/**
	 * Register the people chosen on the confirmation page.
	 *
	 * @param array $input Raw (unslashed) form values: token, people[id] for each ticked person and guests[id].
	 * @return string|WP_Error 'registered' if everyone got a place, else 'waiting'; or an error.
	 */
	public static function confirm( array $input ) {
		$nonce = isset( $input[ self::NONCE_FIELD ] ) ? sanitize_text_field( $input[ self::NONCE_FIELD ] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION_CONFIRM ) ) {
			return new WP_Error( 'expired', __( 'The form has expired. Please try again.', 'chess-army-knife' ) );
		}

		$pending = self::pending( isset( $input['token'] ) ? $input['token'] : '' );
		if ( ! $pending ) {
			return new WP_Error( 'link', __( 'That link has expired. Please register again.', 'chess-army-knife' ) );
		}
		if ( ! Chess_Army_Knife_Event_Registrations::is_open( $pending['event_id'] ) ) {
			return new WP_Error( 'closed', __( 'Registration for this event is closed.', 'chess-army-knife' ) );
		}

		$chosen = array();
		if ( $pending['people'] ) {
			$ticked = isset( $input['people'] ) && is_array( $input['people'] ) ? array_map( 'absint', array_keys( $input['people'] ) ) : array();
			foreach ( $pending['people'] as $person ) {
				if ( in_array( $person['id'], $ticked, true ) ) {
					$chosen[ $person['id'] ] = isset( $input['guests'][ $person['id'] ] ) ? absint( $input['guests'][ $person['id'] ] ) : 0;
				}
			}
			if ( ! $chosen ) {
				return new WP_Error( 'nopeople', __( 'Please choose who is coming.', 'chess-army-knife' ) );
			}
		} else {
			// Nobody on file: they are recorded as a guest, not a member.
			$id = Chess_Army_Knife_Membership_Store::save_member(
				array(
					'name'       => $pending['name'],
					'email'      => $pending['email'],
					'status'     => Chess_Army_Knife_Membership_Store::STATUS_NONMEMBER,
					'source'     => Chess_Army_Knife_Membership_Store::SOURCE_FORM,
					'consent_at' => current_time( 'mysql', true ),
				)
			);
			if ( ! $id ) {
				return new WP_Error( 'save_failed', __( 'Sorry, we could not save your registration. Please try again.', 'chess-army-knife' ) );
			}
			$chosen[ $id ] = $pending['guests'];
		}

		$outcome = 'registered';
		foreach ( $chosen as $person_id => $guests ) {
			$status = Chess_Army_Knife_Event_Registrations::register( $pending['event_id'], $person_id, $guests );
			if ( is_wp_error( $status ) ) {
				return $status;
			}
			if ( Chess_Army_Knife_Event_Registrations::STATUS_WAITING === $status ) {
				$outcome = 'waiting';
			}
			self::send_confirmation( $person_id, $pending['event_id'], $status );
		}

		delete_transient( self::TOKEN_KEY . preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['token'] ) );
		return $outcome;
	}

	/* -------------------------------------------------------------
	 * Cancelling
	 * ------------------------------------------------------------- */

	/**
	 * The signature that makes a cancel link valid for one registration.
	 *
	 * @param array $registration Registration row.
	 * @return string
	 */
	protected static function sign( array $registration ) {
		return substr( hash_hmac( 'sha256', 'cancel|' . $registration['id'] . '|' . $registration['event_id'] . '|' . $registration['person_id'], wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * The page emailed links open: the event's page, which holds the registration form.
	 *
	 * @param int $event_id Event id.
	 * @return string The home page if the event has no published page.
	 */
	protected static function event_url( $event_id ) {
		$url = Chess_Army_Knife_Events::page_url( $event_id );
		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * The link in an email that cancels a registration.
	 *
	 * @param array $registration Registration row.
	 * @return string
	 */
	public static function cancel_url( array $registration ) {
		return add_query_arg( 'cak_cancel', rawurlencode( $registration['id'] . '.' . self::sign( $registration ) ), self::event_url( $registration['event_id'] ) ) . '#' . self::ANCHOR;
	}

	/**
	 * The registration a cancel link is for.
	 *
	 * @param string $value Value of the link's query argument.
	 * @return array|null Registration row, or null if the link is not valid (or it was cancelled already).
	 */
	public static function registration_for_link( $value ) {
		$parts = explode( '.', (string) $value );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return null;
		}
		$registration = Chess_Army_Knife_Event_Registrations::get_by_id( (int) $parts[0] );
		return $registration && hash_equals( self::sign( $registration ), $parts[1] ) ? $registration : null;
	}

	/**
	 * Cancel the registration a link is for.
	 *
	 * @param array $input Raw (unslashed) form values: cancel, the link's value.
	 * @return true|WP_Error
	 */
	public static function cancel( array $input ) {
		$nonce = isset( $input[ self::NONCE_FIELD ] ) ? sanitize_text_field( $input[ self::NONCE_FIELD ] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION_CANCEL ) ) {
			return new WP_Error( 'expired', __( 'The form has expired. Please try again.', 'chess-army-knife' ) );
		}

		$registration = self::registration_for_link( isset( $input['cancel'] ) ? sanitize_text_field( $input['cancel'] ) : '' );
		if ( ! $registration ) {
			return new WP_Error( 'link', __( 'That link is not valid, or the registration was cancelled already.', 'chess-army-knife' ) );
		}

		foreach ( Chess_Army_Knife_Event_Registrations::cancel( $registration['id'] ) as $person_id ) {
			self::send_confirmation( $person_id, $registration['event_id'], Chess_Army_Knife_Event_Registrations::STATUS_REGISTERED, true );
		}
		return true;
	}

	/* -------------------------------------------------------------
	 * Emails
	 * ------------------------------------------------------------- */

	/**
	 * Email someone about their place. Goes through the mailer, so it follows their email choices.
	 *
	 * @param int    $person_id Person id.
	 * @param int    $event_id  Event id.
	 * @param string $status    'registered' or 'waiting'.
	 * @param bool   $promoted  True when they were moved up from the waiting list.
	 */
	protected static function send_confirmation( $person_id, $event_id, $status, $promoted = false ) {
		$person       = Chess_Army_Knife_Membership_Store::get_member( $person_id );
		$registration = Chess_Army_Knife_Event_Registrations::get( $event_id, $person_id );
		$post         = get_post( $event_id );
		if ( ! $person || ! $registration || ! $post ) {
			return;
		}

		$event = Chess_Army_Knife_Events::data( $post );
		$when  = trim( Chess_Army_Knife_Events_Display::date_label( $event ) . ' ' . Chess_Army_Knife_Events_Display::time_label( $event ) );
		$lines = array(
			/* translators: %s: name of the person */
			sprintf( __( 'Hello %s,', 'chess-army-knife' ), $person['name'] ),
			'',
		);

		if ( Chess_Army_Knife_Event_Registrations::STATUS_WAITING === $status ) {
			/* translators: %s: event name */
			$subject = sprintf( __( 'On the waiting list: %s', 'chess-army-knife' ), $event['title'] );
			$lines[] = __( 'The event is full, so you are on the waiting list. We will email you if a place becomes free.', 'chess-army-knife' );
		} else {
			/* translators: %s: event name */
			$subject = sprintf( $promoted ? __( 'A place is free: %s', 'chess-army-knife' ) : __( 'You are registered: %s', 'chess-army-knife' ), $event['title'] );
			$lines[] = $promoted ? __( 'A place has become free and you are now registered.', 'chess-army-knife' ) : __( 'You are registered.', 'chess-army-knife' );
		}

		$lines[] = '';
		$lines[] = $event['title'] . ( '' !== $when ? ' - ' . $when : '' );
		if ( '' !== $event['location'] ) {
			$lines[] = $event['location'];
		}
		if ( $registration['guests'] ) {
			/* translators: %d: number of guests */
			$lines[] = sprintf( _n( 'Including %d guest.', 'Including %d guests.', $registration['guests'], 'chess-army-knife' ), $registration['guests'] );
		}
		$lines[] = '';
		$lines[] = __( 'If you can no longer come, please cancel so someone else can have the place:', 'chess-army-knife' );
		$lines[] = self::cancel_url( $registration );

		Chess_Army_Knife_Mailer::queue( $person, self::CATEGORY, $subject, implode( "\n", $lines ) );
	}

	/**
	 * A message for a code passed back in the address.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( $code ) {
		$messages = array(
			'expired'     => __( 'The form has expired. Please try again.', 'chess-army-knife' ),
			'closed'      => __( 'Registration for this event is closed.', 'chess-army-knife' ),
			'details'     => __( 'Please enter your name and a valid email address.', 'chess-army-knife' ),
			'consent'     => __( 'Please tick the box to say the club may keep your details for this event.', 'chess-army-knife' ),
			'throttled'   => __( 'Too many requests from your connection. Please try again later.', 'chess-army-knife' ),
			'link'        => __( 'That link has expired or is not valid. Please register again.', 'chess-army-knife' ),
			'nopeople'    => __( 'Please choose who is coming.', 'chess-army-knife' ),
			'full'        => __( 'There is not room for that many guests.', 'chess-army-knife' ),
			'save_failed' => __( 'Sorry, we could not save your registration. Please try again.', 'chess-army-knife' ),
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Something went wrong. Please try again.', 'chess-army-knife' );
	}
}

Chess_Army_Knife_Event_Registration_Form::init();
