<?php
/**
 * "Test connection" for the LMS API key: one cheap call (an organisation's seasons) that says whether
 * the key works, is refused, or the LMS cannot be reached, so a wrong key does not first show up as
 * an import that quietly finds nothing. It tests the key as saved, so save first.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_LMS_Test {

	const ACTION = 'chess_army_knife_test_lms';

	/**
	 * Hook up the button's handler.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * What the answer to the test call means.
	 *
	 * @param array|WP_Error $answer Result of Chess_Army_Knife_LMS_Client::get_seasons().
	 * @return array { status, message }: status is 'ok', 'key', 'unreachable' or 'other'.
	 */
	public static function describe( $answer ) {
		if ( ! is_wp_error( $answer ) ) {
			return array(
				'status'  => 'ok',
				'message' => sprintf(
					/* translators: %d: number of seasons */
					_n( 'Connected: the LMS accepted your key and lists %d season for that organisation.', 'Connected: the LMS accepted your key and lists %d seasons for that organisation.', count( $answer ), 'chess-army-knife' ),
					count( $answer )
				),
			);
		}

		$code = $answer->get_error_code();
		if ( in_array( $code, array( 'lms_unauthorised', 'lms_forbidden', 'lms_no_api_key' ), true ) ) {
			return array(
				'status'  => 'key',
				'message' => __( 'The LMS did not accept the key. Check that it is copied in full from your LMS account\'s "API keys" page, and save the settings before testing.', 'chess-army-knife' ),
			);
		}
		if ( 'lms_connection_error' === $code ) {
			return array(
				'status'  => 'unreachable',
				'message' => __( 'The LMS could not be reached from this website. Try again in a few minutes; if it keeps happening, your host may be blocking outgoing requests.', 'chess-army-knife' ),
			);
		}
		if ( 'lms_not_found' === $code ) {
			return array(
				'status'  => 'other',
				'message' => __( 'The LMS could not find that organisation. The key may be fine: check the organisation ID.', 'chess-army-knife' ),
			);
		}

		return array(
			'status'  => 'other',
			'message' => $answer->get_error_message(),
		);
	}

	/**
	 * The organisation to test with: the default one under Settings, else the first team's.
	 *
	 * @return string '' if there is none.
	 */
	public static function organisation() {
		$org = trim( (string) Chess_Army_Knife_Settings::get_options()['default_org_id'] );
		if ( '' === $org ) {
			foreach ( Chess_Army_Knife_Settings::get_club_teams() as $team ) {
				$org = trim( (string) $team['org'] );
				if ( '' !== $org ) {
					break;
				}
			}
		}

		return $org;
	}

	/**
	 * Run the test.
	 *
	 * @return array { status, message }; status can also be 'no_key' or 'no_org'.
	 */
	public static function run() {
		if ( '' === Chess_Army_Knife_LMS_Client::api_key() ) {
			return array(
				'status'  => 'no_key',
				'message' => __( 'There is no LMS API key saved yet.', 'chess-army-knife' ),
			);
		}

		$org = self::organisation();
		if ( '' === $org ) {
			return array(
				'status'  => 'no_org',
				'message' => __( 'Enter your LMS organisation ID (Settings) or add a team with a league first, so there is something to test with.', 'chess-army-knife' ),
			);
		}

		return self::describe( Chess_Army_Knife_LMS_Client::get_seasons( $org, true ) );
	}

	/**
	 * Handle the button: test, keep the answer for one page view, and go back.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::ACTION ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ) );
		}

		set_transient( self::transient_key(), self::run(), MINUTE_IN_SECONDS );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=' . Chess_Army_Knife_Settings::PAGE ) );
		exit;
	}

	/**
	 * Where the current user's last answer is kept.
	 *
	 * @return string
	 */
	protected static function transient_key() {
		return 'chess_army_knife_lms_test_' . get_current_user_id();
	}

	/**
	 * The button and, once, the answer to the last test.
	 *
	 * @return string Escaped HTML, or '' for someone who cannot change settings.
	 */
	public static function panel_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$answer = get_transient( self::transient_key() );
		delete_transient( self::transient_key() );

		$html = '<h2>' . esc_html__( 'LMS connection', 'chess-army-knife' ) . '</h2>';
		if ( is_array( $answer ) && isset( $answer['status'], $answer['message'] ) ) {
			$class = 'ok' === $answer['status'] ? 'notice-success' : 'notice-error';
			$html .= '<div class="notice ' . esc_attr( $class ) . ' inline" role="status"><p>' . esc_html( $answer['message'] ) . '</p></div>';
		}
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		$html .= wp_nonce_field( self::ACTION, '_wpnonce', true, false );
		$html .= '<p class="description">' . esc_html__( 'Checks the LMS API key you have saved, with one request. Save your settings first.', 'chess-army-knife' ) . '</p>';
		$html .= '<p><button type="submit" class="button">' . esc_html__( 'Test the LMS connection', 'chess-army-knife' ) . '</button></p>';

		return $html . '</form>';
	}
}

Chess_Army_Knife_LMS_Test::init();
