<?php
/**
 * The Chess Army Knife > Renewals screen: who the next reminder run would email,
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
		add_action( 'admin_post_chess_army_knife_send_reminders', array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_chess_army_knife_test_reminder', array( __CLASS__, 'handle_test' ) );
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
			'id'        => 0,
			'name'      => __( 'Sample Member', 'chess-army-knife' ),
			'type_name' => __( 'Adult', 'chess-army-knife' ),
		);
		$season = Chess_Army_Knife_Membership_Seasons::current();
		$mail   = Chess_Army_Knife_Renewal_Reminders::compose(
			$sample,
			$season ? $season : array(
				'id'   => 0,
				'name' => Chess_Army_Knife_Membership_Seasons::suggest_name( current_time( 'Y-m-d' ) ),
			)
		);
		$sent   = wp_mail( $user->user_email, '[' . __( 'Test', 'chess-army-knife' ) . '] ' . $mail['subject'], $mail['body'] );

		wp_safe_redirect( self::url( array( 'test' => $sent ? 'sent' : 'failed' ) ) );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Memberships::user_can_manage(), __( 'Renewals', 'chess-army-knife' ), 'members' ) ) {
			return;
		}

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
			<h1><?php esc_html_e( 'Members', 'chess-army-knife' ); ?></h1>
			<?php Chess_Army_Knife_Member_Tabs::render( 'renewals' ); ?>
			<?php
			if ( $notice ) {
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
			}
			?>
			<p>
				<?php
				echo esc_html(
					$enabled
						/* translators: %s: list of days, for example "0, 14, 28" */
						? sprintf( __( 'Reminders are on. Members who have not paid are emailed at these days from the start of the season: %s.', 'chess-army-knife' ), $days )
						: __( 'Reminders are off. Turn them on, and set the days and wording, under Chess Army Knife > Settings.', 'chess-army-knife' )
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Due at the next run', 'chess-army-knife' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
<caption class="screen-reader-text"><?php esc_html_e( 'Members due a reminder', 'chess-army-knife' ); ?></caption>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Member', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Season', 'chess-army-knife' ); ?></th>
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
							<td><?php echo esc_html( $item['season']['name'] ); ?></td>
							<td>
								<?php
								if ( 0 === $stage ) {
									esc_html_e( 'On the first day of the season', 'chess-army-knife' );
								} else {
									/* translators: %d: days after the season started */
									echo esc_html( sprintf( _n( '%d day after the season started', '%d days after the season started', $stage, 'chess-army-knife' ), $stage ) );
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
			<p class="description"><?php esc_html_e( 'When a member pays, record it on their record (or use Mark paid on the Members screen) and they are left out of the next reminders. Start a new season, under Seasons, to ask everyone to pay again.', 'chess-army-knife' ); ?></p>
		</div>
		<?php
	}
}

Chess_Army_Knife_Renewals_Page::init();
