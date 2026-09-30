<?php
/**
 * The page a player opens from an availability email: it shows the fixture and
 * three buttons (yes, maybe, no). Opening the link changes nothing; only a
 * button press does, so a mail scanner opening links cannot answer for anyone.
 * The link is signed for one person and one fixture, so it needs no login.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Availability_Reply {

	const QUERY_VAR = 'cak_avail';

	/**
	 * Show the page, and record a reply when a button is pressed.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_show' ) );
	}

	/**
	 * Handle a request for the reply page.
	 */
	public static function maybe_show() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The signed link is the authority; nothing is changed by a GET.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The signed link is checked in row_for_link().
		$value = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$row   = Chess_Army_Knife_Selection::row_for_link( $value );
		if ( ! $row ) {
			wp_die( esc_html__( 'This link is not valid.', 'chess-army-knife' ), '', array( 'response' => 400 ) );
		}

		$fixture = Chess_Army_Knife_Selection::fixture( $row['event_id'], $row['team_id'] );
		if ( ! $fixture ) {
			wp_die( esc_html__( 'This fixture is no longer on the calendar.', 'chess-army-knife' ), '', array( 'response' => 404 ) );
		}

		$labels = array(
			'yes'   => __( 'Yes, I can play', 'chess-army-knife' ),
			'maybe' => __( 'Maybe', 'chess-army-knife' ),
			'no'    => __( 'No, I cannot play', 'chess-army-knife' ),
		);

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The signed link in the address is the authority, checked above.
			$response = isset( $_POST['response'] ) ? sanitize_key( wp_unslash( $_POST['response'] ) ) : '';
			$result   = Chess_Army_Knife_Selection::respond( $row['id'], $response );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
			}
			/* translators: %s: the reply, for example "Yes, I can play" */
			wp_die( esc_html( sprintf( __( 'Thank you. You replied: %s. You can use the same link to change your answer until the fixture starts.', 'chess-army-knife' ), $labels[ $response ] ) ), esc_html__( 'Reply saved', 'chess-army-knife' ), array( 'response' => 200 ) );
		}

		$when = trim( Chess_Army_Knife_Events_Display::date_label( $fixture['event'] ) . ' ' . Chess_Army_Knife_Events_Display::time_label( $fixture['event'] ) );
		$html = '<form method="post" action="' . esc_url( Chess_Army_Knife_Selection::reply_url( $row ) ) . '">'
			/* translators: 1: team name, 2: fixture, 3: date and time */
			. '<p>' . esc_html( sprintf( __( '%1$s: can you play in %2$s (%3$s)?', 'chess-army-knife' ), $fixture['team']['name'], $fixture['event']['title'], $when ) ) . '</p>';
		foreach ( $labels as $key => $label ) {
			$html .= '<p><button type="submit" name="response" value="' . esc_attr( $key ) . '" class="button">' . esc_html( $label ) . '</button></p>';
		}
		$html .= '</form>';

		wp_die(
			wp_kses(
				$html,
				array(
					'form'   => array(
						'method' => true,
						'action' => true,
					),
					'p'      => array(),
					'button' => array(
						'type'  => true,
						'name'  => true,
						'value' => true,
						'class' => true,
					),
				)
			),
			esc_html__( 'Can you play?', 'chess-army-knife' ),
			array( 'response' => 200 )
		);
	}
}

Chess_Army_Knife_Availability_Reply::init();
