<?php
/**
 * "Manage my data": what a member can do for themselves without an account.
 *
 * - Withdraw the newsletter and WhatsApp consents (or give them again). This
 *   is done from a private link sent to the address the club holds, so nobody
 *   can change someone else's choices.
 * - Ask for a copy of their data, or for it to be deleted. These use
 *   WordPress's own personal data requests: the person confirms by email, then
 *   the request appears under Tools > Export / Erase Personal Data, where the
 *   club's exporter and eraser (see Chess_Army_Knife_Membership_Privacy) do
 *   the work.
 *
 * The answer never says whether an address is on file, and the number of
 * emails one visitor or address can cause is limited.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Member_Requests {

	const ACTION_REQUEST  = 'chess_army_knife_data_request';
	const ACTION_WITHDRAW = 'chess_army_knife_data_withdraw';
	const NONCE_FIELD     = 'chess_army_knife_data_nonce';
	const HONEYPOT        = 'cak_url';
	const ANCHOR          = 'cak-my-data';
	const MAX_PER_HOUR    = 5;
	const TOKEN_KEY       = 'chess_army_knife_withdraw_';

	/** What a visitor can ask for, and how each is done. */
	const CHOICES = array( 'withdraw', 'export', 'erase' );

	/**
	 * Hook up the form handlers.
	 */
	public static function init() {
		foreach ( array(
			self::ACTION_REQUEST  => 'handle_request',
			self::ACTION_WITHDRAW => 'handle_withdraw',
		) as $action => $method ) {
			add_action( 'admin_post_nopriv_' . $action, array( __CLASS__, $method ) );
			add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
		}
		add_action( 'template_redirect', array( __CLASS__, 'protect_token_pages' ) );
	}

	/** Query arguments that carry a sign-in or confirmation token from an emailed link. */
	const TOKEN_PARAMS = array( 'cak_portal', 'cak_email', 'cak_withdraw' );

	/**
	 * Whether a request is one opened from an emailed link, with a token in the address.
	 *
	 * @param array $query The request's query arguments.
	 * @return bool
	 */
	public static function is_token_page( array $query ) {
		foreach ( self::TOKEN_PARAMS as $param ) {
			if ( isset( $query[ $param ] ) && '' !== (string) $query[ $param ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keep a page opened from an emailed link out of caches, and stop the browser passing its address
	 * (and so the token) on in the Referer header to anything the page links to.
	 */
	public static function protect_token_pages() {
		if ( self::is_token_page( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides on headers; nothing is changed.
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	/**
	 * The page a form was sent from, so the visitor can be sent back to it.
	 *
	 * @return string
	 */
	protected static function page_url() {
		$url = isset( $_POST['cak_redirect'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['cak_redirect'] ) ), home_url( '/' ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by the caller before anything is changed.
		return remove_query_arg( array( 'cak_data_sent', 'cak_data_error', 'cak_data_done', 'cak_withdraw', Chess_Army_Knife_Form_State::PARAM ), $url );
	}

	/**
	 * Handle the "what would you like to do" form.
	 */
	public static function handle_request() {
		$page   = self::page_url();
		$result = self::request( wp_unslash( $_POST ), $page ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in request().

		$args = array( 'cak_data_sent' => '1' );
		if ( is_wp_error( $result ) ) {
			$args = array( 'cak_data_error' => $result->get_error_code() ) + Chess_Army_Knife_Form_State::redirect_args( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only keeps what was typed so it can be shown again.
		}
		wp_safe_redirect( add_query_arg( $args, $page ) . '#' . self::ANCHOR );
		exit;
	}

	/**
	 * Handle the form on the private withdrawal page.
	 */
	public static function handle_withdraw() {
		$page   = self::page_url();
		$result = self::withdraw( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in withdraw().

		$args = is_wp_error( $result ) ? array( 'cak_data_error' => $result->get_error_code() ) : array( 'cak_data_done' => '1' );
		wp_safe_redirect( add_query_arg( $args, $page ) . '#' . self::ANCHOR );
		exit;
	}

	/**
	 * Act on a request for withdrawal, a copy of the data, or deletion.
	 *
	 * @param array  $input    Raw (unslashed) form values.
	 * @param string $page_url The page the block is on, for the link in the email.
	 * @return true|WP_Error True whether or not the address is on file, so it cannot be probed.
	 */
	public static function request( array $input, $page_url ) {
		$nonce = isset( $input[ self::NONCE_FIELD ] ) ? sanitize_text_field( $input[ self::NONCE_FIELD ] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION_REQUEST ) ) {
			return new WP_Error( 'expired', __( 'The form has expired. Please try again.', 'chess-army-knife' ) );
		}

		if ( ! empty( $input[ self::HONEYPOT ] ) ) {
			return true; // A bot: say nothing.
		}

		$email  = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';
		$choice = isset( $input['choice'] ) ? sanitize_key( $input['choice'] ) : '';
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'email', __( 'Please enter a valid email address.', 'chess-army-knife' ) );
		}
		if ( ! in_array( $choice, self::CHOICES, true ) ) {
			return new WP_Error( 'choice', __( 'Please choose what you would like to do.', 'chess-army-knife' ) );
		}

		if ( self::over_limit( self::visitor_key() ) ) {
			return new WP_Error( 'throttled', __( 'Too many requests from your connection. Please try again later.', 'chess-army-knife' ) );
		}
		// One address cannot be used to send a flood of emails; the visitor is told nothing different.
		if ( self::over_limit( 'chess_army_knife_data_email_' . md5( strtolower( $email ) ) ) ) {
			return true;
		}
		self::count( self::visitor_key() );
		self::count( 'chess_army_knife_data_email_' . md5( strtolower( $email ) ) );

		if ( 'withdraw' === $choice ) {
			if ( Chess_Army_Knife_Membership_Store::get_members_by_email( $email ) ) {
				self::send_withdrawal_link( $email, $page_url );
			}
			return true;
		}

		$action     = 'export' === $choice ? 'export_personal_data' : 'remove_personal_data';
		$request_id = wp_create_user_request( $email, $action );
		if ( ! is_wp_error( $request_id ) ) {
			wp_send_user_request( $request_id ); // WordPress emails the person a link to confirm it is them.
		}
		return true;
	}

	/**
	 * Email a one-time link to the private withdrawal page.
	 *
	 * @param string $email    Address the club holds.
	 * @param string $page_url The page the block is on.
	 */
	protected static function send_withdrawal_link( $email, $page_url ) {
		$token = wp_generate_password( 32, false );
		set_transient( self::TOKEN_KEY . $token, $email, DAY_IN_SECONDS );

		$link = add_query_arg( 'cak_withdraw', $token, $page_url ) . '#' . self::ANCHOR;
		$site = Chess_Army_Knife_Settings::club_name();

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your data choices', 'chess-army-knife' ), $site );
		/* translators: 1: site name, 2: link, 3: hours the link works for */
		$body = sprintf( __( "Someone asked to change the data choices held by %1\$s for this email address (for example to stop the newsletter or WhatsApp groups).\n\nIf that was you, open this link within %3\$d hours:\n\n%2\$s\n\nIf it was not you, ignore this email and nothing will change.", 'chess-army-knife' ), $site, $link, 24 );

		wp_mail( $email, $subject, $body );
	}

	/**
	 * The address and members behind a withdrawal link.
	 *
	 * @param string $token Token from the link.
	 * @return array|null { email, people } or null if the link is not valid or has expired.
	 */
	public static function people_for_token( $token ) {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		$email = '' === $token ? false : get_transient( self::TOKEN_KEY . $token );

		if ( ! is_string( $email ) || '' === $email ) {
			return null;
		}
		return array(
			'email'  => $email,
			'people' => Chess_Army_Knife_Membership_Store::get_members_by_email( $email ),
		);
	}

	/**
	 * Save the choices made on the private withdrawal page.
	 *
	 * @param array $input Raw (unslashed) form values: token, newsletter[id] / whatsapp[id] for each ticked box, and teams[id][] for the teams chosen.
	 * @return true|WP_Error
	 */
	public static function withdraw( array $input ) {
		$nonce = isset( $input[ self::NONCE_FIELD ] ) ? sanitize_text_field( $input[ self::NONCE_FIELD ] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION_WITHDRAW ) ) {
			return new WP_Error( 'expired', __( 'The form has expired. Please try again.', 'chess-army-knife' ) );
		}

		$found = self::people_for_token( isset( $input['token'] ) ? $input['token'] : '' );
		if ( ! $found ) {
			return new WP_Error( 'link', __( 'That link has expired. Please ask for a new one.', 'chess-army-knife' ) );
		}

		$newsletter = isset( $input['newsletter'] ) && is_array( $input['newsletter'] ) ? array_map( 'absint', array_keys( $input['newsletter'] ) ) : array();
		$whatsapp   = isset( $input['whatsapp'] ) && is_array( $input['whatsapp'] ) ? array_map( 'absint', array_keys( $input['whatsapp'] ) ) : array();
		$now        = current_time( 'mysql', true );

		foreach ( $found['people'] as $person ) {
			$update = array( 'id' => $person['id'] );
			foreach ( array(
				'newsletter_consent_at' => in_array( $person['id'], $newsletter, true ),
				'whatsapp_consent_at'   => in_array( $person['id'], $whatsapp, true ),
			) as $column => $wanted ) {
				if ( ! $wanted ) {
					$update[ $column ] = null; // Withdrawn.
				} elseif ( '' === $person[ $column ] ) {
					$update[ $column ] = $now; // Given again; an existing consent keeps its original time.
				}
			}
			Chess_Army_Knife_Membership_Store::save_member( $update );
		}

		delete_transient( self::TOKEN_KEY . preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['token'] ) );
		return true;
	}

	/**
	 * A message for an error code passed back in the address.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( $code ) {
		$messages = array(
			'expired'   => __( 'The form has expired. Please try again.', 'chess-army-knife' ),
			'email'     => __( 'Please enter a valid email address.', 'chess-army-knife' ),
			'choice'    => __( 'Please choose what you would like to do.', 'chess-army-knife' ),
			'throttled' => __( 'Too many requests from your connection. Please try again later.', 'chess-army-knife' ),
			'link'      => __( 'That link has expired. Please ask for a new one.', 'chess-army-knife' ),
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Something went wrong. Please try again.', 'chess-army-knife' );
	}

	/**
	 * Transient key that counts one visitor's recent requests.
	 *
	 * @return string
	 */
	public static function visitor_key() {
		return 'chess_army_knife_data_ip_' . md5( self::visitor_address() );
	}

	/**
	 * The address of the visitor, for limiting how often one visitor can use a form.
	 * It is the connection's address. A site behind a proxy or CDN sees the proxy's address for
	 * everybody, so it can supply the real one with the filter below.
	 *
	 * @return string
	 */
	public static function visitor_address() {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filter the address a visitor's form submissions are counted against.
		 *
		 * @param string $address The connection's address (REMOTE_ADDR).
		 */
		return (string) apply_filters( 'Chess_Army_Knife_visitor_address', $address );
	}

	/**
	 * Whether a counter has reached the hourly limit.
	 *
	 * @param string $key Transient key.
	 * @return bool
	 */
	public static function over_limit( $key ) {
		return (int) get_transient( $key ) >= self::MAX_PER_HOUR;
	}

	/**
	 * Add one to an hourly counter.
	 *
	 * @param string $key Transient key.
	 */
	public static function count( $key ) {
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}
}

Chess_Army_Knife_Member_Requests::init();
