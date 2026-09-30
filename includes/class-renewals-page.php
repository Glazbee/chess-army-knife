<?php
/**
 * The Memberships > Renewals screen: who the next reminder run would email,
 * a button to send them now, and a test email to the signed-in user.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Renewals_Page {

	const SLUG = 'chess-army-knife-renewals';

	/**
	 * Hook up the screen and its actions.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 7 );
		add_action( 'admin_post_chess_army_knife_send_reminders', array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_chess_army_knife_test_reminder', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_chess_army_knife_renew_member', array( __CLASS__, 'handle_renew' ) );
	}

	/**
	 * Add the screen under Memberships.
	 */
	public static function add_menu() {
		add_submenu_page(
			Chess_Army_Knife_Memberships::MENU_SLUG,
			__( 'Renewals', 'chess-army-knife' ),
			__( 'Renewals', 'chess-army-knife' ),
			Chess_Army_Knife_Memberships::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Stop anyone without the membership permission.
	 */
	protected static function require_permission() {
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * The address of this screen.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	protected static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Queue every reminder that is due, now.
	 */
	public static function handle_send() {
		self::require_permission();
		check_admin_referer( 'chess_army_knife_send_reminders' );

		wp_safe_redirect( self::url( array( 'queued' => Chess_Army_Knife_Renewal_Reminders::run() ) ) );
		exit;
	}

	/**
	 * Send the reminder wording to the signed-in user, using a made-up member.
	 */
	public static function handle_test() {
		self::require_permission();
		check_admin_referer( 'chess_army_knife_test_reminder' );

		$user   = wp_get_current_user();
		$sample = array(
			'id'          => 0,
			'name'        => __( 'Sample Member', 'chess-army-knife' ),
			'type_name'   => __( 'Adult', 'chess-army-knife' ),
			'expiry_date' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' UTC' ) + 7 * DAY_IN_SECONDS ),
		);
		$mail   = Chess_Army_Knife_Renewal_Reminders::compose( $sample, 7 );
		$sent   = wp_mail( $user->user_email, '[' . __( 'Test', 'chess-army-knife' ) . '] ' . $mail['subject'], $mail['body'] );

		wp_safe_redirect( self::url( array( 'test' => $sent ? 'sent' : 'failed' ) ) );
		exit;
	}

	/**
	 * Mark a member as renewed: their membership runs on for another period.
	 */
	public static function handle_renew() {
		self::require_permission();

		$id = isset( $_GET['member'] ) ? absint( $_GET['member'] ) : 0;
		check_admin_referer( 'chess_army_knife_renew_member_' . $id );

		$expiry = Chess_Army_Knife_Membership_Store::renew_member( $id );

		wp_safe_redirect(
			add_query_arg(
				'' === $expiry ? array( 'error' => 'renew' ) : array( 'renewed' => $expiry ),
				admin_url( 'admin.php?page=' . Chess_Army_Knife_Memberships::MENU_SLUG )
			)
		);
		exit;
	}

	/**
	 * Render the screen.
	 */
	public static function render_page() {
		self::require_permission();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen state; nothing is changed.
		$notice = null;
		if ( isset( $_GET['queued'] ) ) {
			/* translators: %d: number of emails */
			$notice = array( 'success', sprintf( _n( '%d reminder queued. It will be sent within a few minutes.', '%d reminders queued. They will be sent within a few minutes.', absint( $_GET['queued'] ), 'chess-army-knife' ), absint( $_GET['queued'] ) ) );
		} elseif ( isset( $_GET['test'] ) ) {
			$notice = 'sent' === $_GET['test'] ? array( 'success', __( 'A test email was sent to your address.', 'chess-army-knife' ) ) : array( 'error', __( 'The test email could not be sent.', 'chess-army-knife' ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$enabled = Chess_Army_Knife_Renewal_Reminders::is_enabled();
		$due     = Chess_Army_Knife_Renewal_Reminders::due();
		$days    = implode( ', ', Chess_Army_Knife_Renewal_Reminders::schedule_days() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Renewal reminders', 'chess-army-knife' ); ?></h1>
			<?php
			if ( $notice ) {
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
			}
			?>
			<p>
				<?php
				echo esc_html(
					$enabled
						/* translators: %s: list of days, for example "30, 7, 0, -7" */
						? sprintf( __( 'Reminders are on. They go out at these days from the last day of membership: %s.', 'chess-army-knife' ), $days )
						: __( 'Reminders are off. Turn them on, and set the days and wording, under ECF & LMS > Settings.', 'chess-army-knife' )
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Due at the next run', 'chess-army-knife' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Member', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Last day of membership', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Reminder', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Email goes to', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Will be sent?', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $due ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Nobody is due a reminder.', 'chess-army-knife' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $due as $item ) : ?>
						<?php
						$member = $item['member'];
						$to     = Chess_Army_Knife_Membership_Store::contact_email( $member );
						$stage  = (int) $item['stage'];
						$sends  = '' !== $to && Chess_Army_Knife_Notification_Preferences::allows( $member, Chess_Army_Knife_Renewal_Reminders::CATEGORY );
						?>
						<tr>
							<td><?php echo esc_html( $member['name'] ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $member['expiry_date'] ) ); ?></td>
							<td>
								<?php
								if ( $stage > 0 ) {
									/* translators: %d: days before the last day of membership */
									echo esc_html( sprintf( _n( '%d day before', '%d days before', $stage, 'chess-army-knife' ), $stage ) );
								} elseif ( 0 === $stage ) {
									esc_html_e( 'On the last day', 'chess-army-knife' );
								} else {
									/* translators: %d: days after the last day of membership */
									echo esc_html( sprintf( _n( '%d day after', '%d days after', -$stage, 'chess-army-knife' ), -$stage ) );
								}
								?>
							</td>
							<td><?php echo '' === $to ? '&mdash;' : esc_html( $to ); ?></td>
							<td>
								<?php
								if ( '' === $to ) {
									esc_html_e( 'No: no email address', 'chess-army-knife' );
								} elseif ( ! $sends ) {
									esc_html_e( 'No: they turned these emails off', 'chess-army-knife' );
								} else {
									esc_html_e( 'Yes', 'chess-army-knife' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<?php if ( $enabled && $due ) : ?>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=chess_army_knife_send_reminders' ), 'chess_army_knife_send_reminders' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Send these now', 'chess-army-knife' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=chess_army_knife_test_reminder' ), 'chess_army_knife_test_reminder' ) ); ?>" class="button"><?php esc_html_e( 'Send me a test email', 'chess-army-knife' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'When a member pays, use Renew on the Members screen: it moves their last day forward by the length of their membership type and starts a fresh set of reminders.', 'chess-army-knife' ); ?></p>
		</div>
		<?php
	}
}

Chess_Army_Knife_Renewals_Page::init();
