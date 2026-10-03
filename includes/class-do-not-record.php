<?php
/**
 * People the club has been asked not to record. When someone asks to be deleted, the club
 * must not quietly make a record of them again, for example because their ECF rating code
 * turns up in a tournament, a lookup or an import.
 *
 * Remembering who not to record is itself a record, so the list holds no codes or names.
 * Each entry is a one-way fingerprint (a keyed hash) that can say "yes, this is someone we
 * were asked not to record" but cannot be turned back into the code or name. Anyone with the
 * site's secret keys could still test a guess against it, so it protects against reading the
 * database, not against someone holding the keys. If the keys are changed the list stops
 * matching and has to be built again.
 *
 * It only stops the plugin creating records by itself (tournament entry, ECF lookups,
 * imports). An admin who adds the person by hand, or a person who applies again, is making
 * a deliberate choice, and is not blocked.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Do_Not_Record {

	const OPTION = 'Chess_Army_Knife_do_not_record';
	const PAGE   = 'chess-army-knife-do-not-record';
	const ACTION = 'chess_army_knife_do_not_record';

	const ACTION_EXPORT = 'chess_army_knife_do_not_record_export';
	const ACTION_IMPORT = 'chess_army_knife_do_not_record_import';

	/** Most fingerprints taken from one pasted backup, so a mistake cannot fill the options table. */
	const MAX_IMPORT = 50000;

	/**
	 * Hook up the screen's form.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_' . self::ACTION_IMPORT, array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * An ECF rating code as it is compared: upper case letters and digits.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function clean_code( $code ) {
		return strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', (string) $code ) );
	}

	/**
	 * A name as it is compared: lower case, the order of the words ignored, so "Lovelace, Ada"
	 * and "Ada Lovelace" are the same person.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function clean_name( $name ) {
		$words = preg_split( '/[\s,]+/u', strtolower( trim( (string) $name ) ), -1, PREG_SPLIT_NO_EMPTY );
		sort( $words );

		return implode( ' ', $words );
	}

	/**
	 * The fingerprints that stand for a code and a name.
	 *
	 * @param string $code ECF rating code, or ''.
	 * @param string $name Full name, or ''.
	 * @return string[] Keyed hashes: the code, the code's digits alone (the LMS and the ECF may give a code with or without its letter), and the name.
	 */
	public static function fingerprints( $code, $name ) {
		$prints = array();
		$code   = self::clean_code( $code );

		if ( '' !== $code ) {
			$prints[] = self::fingerprint( 'code', $code );
			$digits   = preg_replace( '/\D/', '', $code );
			if ( '' !== $digits && $digits !== $code ) {
				$prints[] = self::fingerprint( 'code', $digits );
			}
		}

		$name = self::clean_name( $name );
		if ( count( explode( ' ', $name ) ) >= 2 ) { // A single word is too likely to be someone else.
			$prints[] = self::fingerprint( 'name', $name );
		}

		return $prints;
	}

	/**
	 * One keyed hash.
	 *
	 * @param string $kind  'code' or 'name', so a name can never match a code.
	 * @param string $value Cleaned value.
	 * @return string
	 */
	protected static function fingerprint( $kind, $value ) {
		return hash_hmac( 'sha256', $kind . '|' . $value, wp_salt( 'auth' ) );
	}

	/**
	 * Every fingerprint held.
	 *
	 * @return array Fingerprint => when it was added (Unix time).
	 */
	protected static function held() {
		$held = get_option( self::OPTION, array() );

		return is_array( $held ) ? $held : array();
	}

	/**
	 * How many fingerprints are held.
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::held() );
	}

	/**
	 * Ask the plugin not to record a person again.
	 *
	 * @param string $code ECF rating code, or ''.
	 * @param string $name Full name, or ''.
	 * @return int How many fingerprints were added.
	 */
	public static function add( $code, $name ) {
		$held  = self::held();
		$added = 0;

		foreach ( self::fingerprints( $code, $name ) as $print ) {
			if ( ! isset( $held[ $print ] ) ) {
				$held[ $print ] = time();
				++$added;
			}
		}

		if ( $added ) {
			update_option( self::OPTION, $held, false );
		}

		return $added;
	}

	/**
	 * Allow a person to be recorded again.
	 *
	 * @param string $code ECF rating code, or ''.
	 * @param string $name Full name, or ''.
	 * @return int How many fingerprints were removed.
	 */
	public static function remove( $code, $name ) {
		$held    = self::held();
		$removed = 0;

		foreach ( self::fingerprints( $code, $name ) as $print ) {
			if ( isset( $held[ $print ] ) ) {
				unset( $held[ $print ] );
				++$removed;
			}
		}

		if ( $removed ) {
			update_option( self::OPTION, $held, false );
		}

		return $removed;
	}

	/**
	 * The list as text, to keep as a backup: one fingerprint per line with the time it was added.
	 *
	 * @return string
	 */
	public static function export_text() {
		$held = self::held();
		ksort( $held );

		$lines = array( '# Chess Army Knife: Do Not Record. One-way fingerprints, no names or codes.' );
		foreach ( $held as $print => $added ) {
			$lines[] = $print . "\t" . (int) $added;
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Add the fingerprints in a backup made by export_text().
	 *
	 * @param string $text The backup.
	 * @return int|null How many were new; null if the text had more than MAX_IMPORT lines.
	 */
	public static function import_text( $text ) {
		$lines = preg_split( '/\R/', (string) $text );
		if ( count( $lines ) > self::MAX_IMPORT + 5 ) {
			return null;
		}

		$held  = self::held();
		$added = 0;
		foreach ( $lines as $line ) {
			// A fingerprint is 64 hex digits; the time after it is optional. Anything else is skipped.
			if ( ! preg_match( '/^([0-9a-f]{64})(?:\s+(\d+))?\s*$/', trim( $line ), $m ) || isset( $held[ $m[1] ] ) ) {
				continue;
			}
			$held[ $m[1] ] = isset( $m[2] ) ? (int) $m[2] : time();
			++$added;
		}

		if ( $added ) {
			update_option( self::OPTION, $held, false );
		}

		return $added;
	}

	/**
	 * Send the backup as a download.
	 */
	public static function handle_export() {
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() || ! check_admin_referer( self::ACTION_EXPORT ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="do-not-record-' . current_time( 'Y-m-d' ) . '.txt"' );
		echo self::export_text(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A text file of hex fingerprints, not HTML.
		exit;
	}

	/**
	 * Add the fingerprints from a pasted backup.
	 */
	public static function handle_import() {
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() || ! check_admin_referer( self::ACTION_IMPORT ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}

		$text  = isset( $_POST['backup'] ) ? wp_unslash( $_POST['backup'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only 64-digit hex lines are taken, by import_text().
		$added = self::import_text( is_string( $text ) ? $text : '' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE,
					'cak_dnr_done' => null === $added ? 'toobig' : 'restored',
					'cak_dnr_n'    => (int) $added,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Whether the club was asked not to record this person. A code is the reliable test and is
	 * used when there is one; a name is only used when there is no code, and may stop a different
	 * person with the same name, so the message that follows should say how to proceed.
	 *
	 * @param string $code ECF rating code, or ''.
	 * @param string $name Full name, or ''.
	 * @return bool
	 */
	public static function is_blocked( $code, $name ) {
		$held = self::held();
		if ( ! $held ) {
			return false;
		}

		$by_code = '' !== self::clean_code( $code );
		foreach ( self::fingerprints( $by_code ? $code : '', $by_code ? '' : $name ) as $print ) {
			if ( isset( $held[ $print ] ) ) {
				return true;
			}
		}

		return false;
	}

	/* -------------------------------------------------------------
	 * The screen
	 * ------------------------------------------------------------- */

	/**
	 * Draw the Do Not Record screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Memberships::user_can_manage(), __( 'Do Not Record', 'chess-army-knife' ), 'members' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the outcome of a form.
		$done = isset( $_GET['cak_dnr_done'] ) ? sanitize_key( wp_unslash( $_GET['cak_dnr_done'] ) ) : '';
		$n    = isset( $_GET['cak_dnr_n'] ) ? absint( $_GET['cak_dnr_n'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Members', 'chess-army-knife' ); ?></h1>
			<?php Chess_Army_Knife_Member_Tabs::render( 'dnr' ); ?>
			<p><?php esc_html_e( 'When someone asks to be deleted, the club should not make a record of them again by accident: for example when their ECF rating code turns up in a tournament, an ECF lookup or a league import. Add them here and the plugin will not create a record for them by itself. If they play in a tournament they are entered by name only, with no record behind the entry, so nothing links their games to them.', 'chess-army-knife' ); ?></p>
			<p><?php esc_html_e( 'This list holds no codes or names, only one-way fingerprints that cannot be turned back into them, so keeping it does not keep the person\'s details. Deleting a member from the Members screen can add them here for you.', 'chess-army-knife' ); ?></p>

			<?php if ( 'added' === $done ) : ?>
				<div class="notice notice-success" role="status"><p><?php echo esc_html( $n ? __( 'Added. The plugin will not record that person again.', 'chess-army-knife' ) : __( 'They were already on the list.', 'chess-army-knife' ) ); ?></p></div>
			<?php elseif ( 'removed' === $done ) : ?>
				<div class="notice notice-success" role="status"><p><?php echo esc_html( $n ? __( 'Removed. That person can be recorded again.', 'chess-army-knife' ) : __( 'They were not on the list.', 'chess-army-knife' ) ); ?></p></div>
			<?php elseif ( 'restored' === $done ) : ?>
				<div class="notice notice-success" role="status"><p><?php echo esc_html( sprintf( /* translators: %d: number of fingerprints */ _n( 'Restored: %d new fingerprint added.', 'Restored: %d new fingerprints added.', $n, 'chess-army-knife' ), $n ) ); ?></p></div>
			<?php elseif ( 'toobig' === $done ) : ?>
				<div class="notice notice-error" role="alert"><p><?php esc_html_e( 'That is too long to be a backup of this list. Nothing was added.', 'chess-army-knife' ); ?></p></div>
			<?php elseif ( 'empty' === $done ) : ?>
				<div class="notice notice-error" role="alert"><p><?php esc_html_e( 'Enter an ECF rating code, or a first name and surname.', 'chess-army-knife' ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of fingerprints */
						_n( '%d fingerprint is held.', '%d fingerprints are held.', self::count(), 'chess-army-knife' ),
						self::count()
					)
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<p>
					<label for="cak-dnr-code"><?php esc_html_e( 'ECF rating code', 'chess-army-knife' ); ?></label><br />
					<input type="text" id="cak-dnr-code" name="ecf_code" class="regular-text" autocomplete="off" />
					<br /><span class="description"><?php esc_html_e( 'The reliable way: it identifies one person.', 'chess-army-knife' ); ?></span>
				</p>
				<p>
					<label for="cak-dnr-name"><?php esc_html_e( 'First name and surname (optional)', 'chess-army-knife' ); ?></label><br />
					<input type="text" id="cak-dnr-name" name="full_name" class="regular-text" autocomplete="off" aria-describedby="cak-dnr-name-help" />
					<br /><span id="cak-dnr-name-help" class="description"><?php esc_html_e( 'Only used when someone is entered without an ECF code, and it can stop a different person with the same name. Two or more words are needed.', 'chess-army-knife' ); ?></span>
				</p>
				<p>
					<button type="submit" name="do" value="add" class="button button-primary"><?php esc_html_e( 'Do not record this person', 'chess-army-knife' ); ?></button>
					<button type="submit" name="do" value="remove" class="button"><?php esc_html_e( 'Allow this person to be recorded again', 'chess-army-knife' ); ?></button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Backup', 'chess-army-knife' ); ?></h2>
			<p><strong><?php esc_html_e( 'The fingerprints depend on this site\'s secret keys (the salts in wp-config.php).', 'chess-army-knife' ); ?></strong> <?php esc_html_e( 'If those keys are ever changed, the list stops matching anyone, and has to be built again from the people who asked; a backup made before the change will not help either. If the database is lost but the keys are kept, a backup can put the list back. The backup holds only fingerprints, never names or codes.', 'chess-army-knife' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_EXPORT ); ?>" />
				<?php wp_nonce_field( self::ACTION_EXPORT ); ?>
				<p><button type="submit" class="button" <?php disabled( 0 === self::count() ); ?>><?php esc_html_e( 'Download a backup of the list', 'chess-army-knife' ); ?></button></p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_IMPORT ); ?>" />
				<?php wp_nonce_field( self::ACTION_IMPORT ); ?>
				<p>
					<label for="cak-dnr-backup"><?php esc_html_e( 'Restore from a backup: paste its contents here', 'chess-army-knife' ); ?></label><br />
					<textarea id="cak-dnr-backup" name="backup" rows="4" class="large-text code" autocomplete="off"></textarea>
				</p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Add these to the list', 'chess-army-knife' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the add and remove buttons.
	 */
	public static function handle() {
		check_admin_referer( self::ACTION );
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), 403 );
		}

		$code = isset( $_POST['ecf_code'] ) ? sanitize_text_field( wp_unslash( $_POST['ecf_code'] ) ) : '';
		$name = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '';
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : 'add';

		if ( ! self::fingerprints( $code, $name ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => self::PAGE,
						'cak_dnr_done' => 'empty',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$n = 'remove' === $do ? self::remove( $code, $name ) : self::add( $code, $name );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE,
					'cak_dnr_done' => 'remove' === $do ? 'removed' : 'added',
					'cak_dnr_n'    => $n,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

Chess_Army_Knife_Do_Not_Record::init();
