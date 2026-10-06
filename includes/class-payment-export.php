<?php
/**
 * Export of a season's membership payments to a CSV file for the treasurer, who opens it in Excel: who paid,
 * when, how, the bank transfer reference and the amount.
 *
 * Only someone with the membership permission can download it, with a nonce.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Payment_Export {

	/**
	 * The columns, in order.
	 *
	 * @return string[] Heading for each column.
	 */
	public static function columns() {
		return array(
			__( 'First name', 'chess-army-knife' ),
			__( 'Last Name', 'chess-army-knife' ),
			__( 'Membership Type', 'chess-army-knife' ),
			__( 'Payment Date', 'chess-army-knife' ),
			__( 'Payment Type', 'chess-army-knife' ),
			__( 'Payment reference', 'chess-army-knife' ),
			__( 'Amount', 'chess-army-knife' ),
			__( 'Free Year', 'chess-army-knife' ),
		);
	}

	/**
	 * One payment as a row of text cells, in the order of columns().
	 *
	 * @param array $payment Payment from Chess_Army_Knife_Membership_Seasons::payments_for_season().
	 * @return string[]
	 */
	public static function row( array $payment ) {
		$methods = Chess_Army_Knife_Memberships::payment_methods();

		if ( Chess_Army_Knife_Membership_Store::erased_name() === $payment['name'] ) {
			$first   = '';
			$surname = $payment['name'];
		} else {
			$parts = Chess_Army_Knife_Names::parse( $payment['name'] );
			// The first name is the member's nickname, or their first name if they have none.
			$first   = '' !== trim( $payment['nickname'] ) ? trim( $payment['nickname'] ) : $parts['first'];
			$surname = $parts['surname'];
		}

		return array(
			$first,
			$surname,
			$payment['type_name'],
			$payment['paid_on'],
			isset( $methods[ $payment['method'] ] ) ? $methods[ $payment['method'] ] : $payment['method'],
			$payment['reference'],
			// A plain number, so a spreadsheet can add the column up.
			null === $payment['amount'] ? '' : number_format( $payment['amount'] / 100, 2, '.', '' ),
			Chess_Army_Knife_Memberships::FREE_YEAR === $payment['method'] ? 'Y' : 'N',
		);
	}

	/**
	 * The whole file as text: a header row then one row per payment.
	 *
	 * @param array[] $payments Payments from Chess_Army_Knife_Membership_Seasons::payments_for_season().
	 * @return string CSV, starting with a byte order mark so Excel reads it as UTF-8.
	 */
	public static function to_csv( array $payments ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- An in-memory stream to build the CSV text, not a file.
		$out = fopen( 'php://temp', 'r+' );

		fputcsv( $out, self::columns(), ',', '"', '' );
		foreach ( $payments as $payment ) {
			fputcsv( $out, array_map( array( 'Chess_Army_Knife_Member_Export', 'safe_cell' ), self::row( $payment ) ), ',', '"', '' );
		}

		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the in-memory stream above.

		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Send a season's payments to the browser as a download, and stop.
	 *
	 * @param array $season Season from Chess_Army_Knife_Membership_Seasons.
	 */
	public static function download( array $season ) {
		$csv = self::to_csv( Chess_Army_Knife_Membership_Seasons::payments_for_season( $season['id'] ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="payments-' . sanitize_file_name( str_replace( '/', '-', $season['name'] ) ) . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV file, not HTML; every cell is made safe for spreadsheets above.
		exit;
	}
}
