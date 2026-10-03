<?php
/**
 * Export of chosen members to a CSV file: contact details, ratings, ECF
 * codes and membership dates, for the treasurer, team captains or an import
 * into another system.
 *
 * The file holds personal details, so only someone with the membership
 * permission can download it, with a nonce, and the notes (private to the
 * membership secretary) are left out.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Member_Export {

	/**
	 * The columns, in order.
	 *
	 * @return string[] Heading for each column key.
	 */
	public static function columns() {
		return array(
			'name'               => __( 'Name', 'chess-army-knife' ),
			'status'             => __( 'Status', 'chess-army-knife' ),
			'membership_type'    => __( 'Membership type', 'chess-army-knife' ),
			'start_date'         => __( 'Start date', 'chess-army-knife' ),
			'expiry_date'        => __( 'Expiry date', 'chess-army-knife' ),
			'email'              => __( 'Email', 'chess-army-knife' ),
			'phone'              => __( 'Phone', 'chess-army-knife' ),
			'date_of_birth'      => __( 'Date of birth', 'chess-army-knife' ),
			'guardian_name'      => __( 'Parent or guardian', 'chess-army-knife' ),
			'guardian_email'     => __( 'Parent or guardian email', 'chess-army-knife' ),
			'guardian_phone'     => __( 'Parent or guardian phone', 'chess-army-knife' ),
			'ecf_code'           => __( 'ECF code', 'chess-army-knife' ),
			'ecf_rating'         => __( 'ECF rating', 'chess-army-knife' ),
			'ecf_rating_domain'  => __( 'ECF rating list', 'chess-army-knife' ),
			'ecf_checked_at'     => __( 'ECF rating checked (UTC)', 'chess-army-knife' ),
			'manual_rating'      => __( 'Manual rating', 'chess-army-knife' ),
			'teams'              => __( 'Teams', 'chess-army-knife' ),
			'paid_on'            => __( 'Payment received', 'chess-army-knife' ),
			'payment_method'     => __( 'Payment method', 'chess-army-knife' ),
			'payment_reference'  => __( 'Payment reference', 'chess-army-knife' ),
			'newsletter_consent' => __( 'Newsletter', 'chess-army-knife' ),
			'whatsapp_consent'   => __( 'WhatsApp groups', 'chess-army-knife' ),
			'joined_or_applied'  => __( 'Record created (UTC)', 'chess-army-knife' ),
		);
	}

	/**
	 * One member as a row of text cells, in the order of columns().
	 *
	 * @param array    $member    Member row.
	 * @param string   $today     Today's site-local date, YYYY-MM-DD.
	 * @param string[] $team_names Names of the squads they are in.
	 * @return string[]
	 */
	public static function row( array $member, $today, array $team_names ) {
		$statuses = Chess_Army_Knife_Membership_Store::status_labels() + array( Chess_Army_Knife_Membership_Store::STATUS_EXPIRED => __( 'Expired', 'chess-army-knife' ) );
		$status   = Chess_Army_Knife_Membership_Store::effective_status( $member, $today );
		$methods  = Chess_Army_Knife_Memberships::payment_methods();

		return array(
			$member['name'],
			isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status,
			$member['type_name'],
			$member['start_date'],
			$member['expiry_date'],
			$member['email'],
			$member['phone'],
			$member['date_of_birth'],
			$member['guardian_name'],
			$member['guardian_email'],
			$member['guardian_phone'],
			$member['ecf_code'],
			null === $member['ecf_rating'] ? '' : (string) $member['ecf_rating'],
			$member['ecf_rating_domain'],
			$member['ecf_checked_at'],
			null === $member['manual_rating'] ? '' : (string) $member['manual_rating'],
			implode( ', ', $team_names ),
			$member['paid_on'],
			isset( $methods[ $member['payment_method'] ] ) ? $methods[ $member['payment_method'] ] : $member['payment_method'],
			Chess_Army_Knife_Memberships::payment_reference( $member['id'], $member ),
			'' !== $member['newsletter_consent_at'] ? __( 'Yes', 'chess-army-knife' ) : __( 'No', 'chess-army-knife' ),
			'' === $member['whatsapp_consent_at'] ? __( 'No', 'chess-army-knife' ) : __( 'Yes', 'chess-army-knife' ),
			(string) $member['created_at'],
		);
	}

	/**
	 * Make a cell safe to open in a spreadsheet. A cell that starts with =, +,
	 * - or @ is read as a formula, which someone could use to run commands or
	 * leak data, so it is made plain text with a leading apostrophe. A phone
	 * number such as +44 7700 900123 is left as it is.
	 *
	 * @param string $cell Cell text.
	 * @return string
	 */
	public static function safe_cell( $cell ) {
		$cell = (string) $cell;

		if ( '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) && ! preg_match( '/^\+[0-9 ()\-]+$/', $cell ) ) {
			return "'" . $cell;
		}
		return $cell;
	}

	/**
	 * The whole file as text: a header row then one row per member.
	 *
	 * @param array[] $members Member rows.
	 * @param string  $today   Today's site-local date, YYYY-MM-DD.
	 * @param array   $squads  Names of the squads by member id (see Chess_Army_Knife_Teams::squad_names_by_person()).
	 * @return string CSV, starting with a byte order mark so Excel reads it as UTF-8.
	 */
	public static function to_csv( array $members, $today, array $squads ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- An in-memory stream to build the CSV text, not a file.
		$out = fopen( 'php://temp', 'r+' );

		fputcsv( $out, array_map( array( __CLASS__, 'safe_cell' ), array_values( self::columns() ) ), ',', '"', '' );
		foreach ( $members as $member ) {
			$teams = isset( $squads[ $member['id'] ] ) ? $squads[ $member['id'] ] : array();
			fputcsv( $out, array_map( array( __CLASS__, 'safe_cell' ), self::row( $member, $today, $teams ) ), ',', '"', '' );
		}

		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the in-memory stream above.

		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Send members to the browser as a download, and stop.
	 *
	 * @param array[] $members Member rows.
	 */
	public static function download( array $members ) {
		$csv = self::to_csv( $members, current_time( 'Y-m-d' ), Chess_Army_Knife_Teams::squad_names_by_person() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="members-' . current_time( 'Y-m-d' ) . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV file, not HTML; every cell is made safe for spreadsheets above.
		exit;
	}
}
