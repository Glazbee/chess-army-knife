<?php
/**
 * The Chess Army Knife > Member Checks screen: lists that help a membership
 * secretary tidy the records. Members missing an ECF code, members whose code
 * is invalid, incomplete applications, expired memberships and possible
 * duplicates. Every list can be ticked and exported to CSV.
 *
 * Needs the chess_army_manage_memberships permission, like the Members screen.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Member_Checks_Page {

	const SLUG = 'chess-army-knife-member-checks';

	/** How many codes one click of "Check with the ECF" asks about: the ECF limits how much processing the club may use each day. */
	const VERIFY_BATCH = 10;

	/**
	 * Hook up the screen and its action.
	 */
	public static function init() {
		add_action( 'admin_post_chess_army_knife_verify_ecf_codes', array( __CLASS__, 'handle_verify' ) );
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
	 * A link to a member's edit screen, with their name.
	 *
	 * @param array $member Member row.
	 * @return string HTML.
	 */
	protected static function member_link( array $member ) {
		$url = add_query_arg(
			array(
				'page' => Chess_Army_Knife_Memberships::MENU_SLUG,
				'edit' => $member['id'],
			),
			admin_url( 'admin.php' )
		);
		return '<strong><a href="' . esc_url( $url ) . '">' . esc_html( $member['name'] ) . '</a></strong>';
	}

	/**
	 * The codes the ECF can be asked about: current members whose code looks right.
	 *
	 * @param array[] $members Every person held.
	 * @param string  $today   Today's site-local date, YYYY-MM-DD.
	 * @return array[]
	 */
	protected static function codes_to_verify( array $members, $today ) {
		return array_values(
			array_filter(
				$members,
				function ( $member ) use ( $today ) {
					return '' !== $member['ecf_code']
						&& Chess_Army_Knife_Member_Audit::ecf_code_looks_valid( $member['ecf_code'] )
						&& Chess_Army_Knife_Membership_Store::STATUS_ACTIVE === Chess_Army_Knife_Membership_Store::effective_status( $member, $today );
				}
			)
		);
	}

	/**
	 * Current members whose code the ECF said it does not know.
	 *
	 * @param array[] $members Current members with well-formed codes.
	 * @return array[]
	 */
	protected static function unknown_to_ecf( array $members ) {
		return array_values(
			array_filter(
				$members,
				function ( $member ) {
					return Chess_Army_Knife_Member_Audit::VERDICT_UNKNOWN === Chess_Army_Knife_Member_Audit::ecf_verdict( $member['ecf_code'] );
				}
			)
		);
	}

	/**
	 * Ask the ECF about the next few codes nobody has checked yet.
	 */
	public static function handle_verify() {
		self::require_permission();
		check_admin_referer( 'chess_army_knife_verify_ecf_codes' );

		$today   = current_time( 'Y-m-d' );
		$members = self::codes_to_verify( Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'people' ) ), $today );
		$result  = Chess_Army_Knife_Member_Audit::verify_next_codes( $members, self::VERIFY_BATCH );

		wp_safe_redirect(
			self::url(
				array(
					'check'         => 'invalid_ecf',
					'verified'      => $result['asked'],
					'verify_failed' => $result['failed'],
				)
			)
		);
		exit;
	}

	/**
	 * The checks, with the people each one finds.
	 *
	 * @param array[] $members Every person held.
	 * @param string  $today   Today's site-local date, YYYY-MM-DD.
	 * @return array[] Each { label, count } by check key.
	 */
	protected static function counts( array $members, $today ) {
		$expired = array_filter(
			$members,
			function ( $member ) use ( $today ) {
				return Chess_Army_Knife_Membership_Store::STATUS_EXPIRED === Chess_Army_Knife_Membership_Store::effective_status( $member, $today );
			}
		);

		return array(
			'missing_ecf' => array(
				'label' => __( 'Missing ECF codes', 'chess-army-knife' ),
				'count' => count( Chess_Army_Knife_Member_Audit::missing_ecf_code( $members, $today ) ),
			),
			'invalid_ecf' => array(
				'label' => __( 'Invalid ECF codes', 'chess-army-knife' ),
				'count' => count( Chess_Army_Knife_Member_Audit::malformed_ecf_code( $members, $today ) ) + count( self::unknown_to_ecf( self::codes_to_verify( $members, $today ) ) ),
			),
			'incomplete'  => array(
				'label' => __( 'Incomplete applications', 'chess-army-knife' ),
				'count' => count( Chess_Army_Knife_Member_Audit::incomplete_applications( $members, $today ) ),
			),
			'expired'     => array(
				'label' => __( 'Expired memberships', 'chess-army-knife' ),
				'count' => count( $expired ),
			),
			'duplicates'  => array(
				'label' => __( 'Possible duplicates', 'chess-army-knife' ),
				'count' => count( Chess_Army_Knife_Member_Audit::duplicate_groups( $members ) ),
			),
		);
	}

	/**
	 * A table of people with tick boxes and the export button.
	 *
	 * @param string[] $headings Column headings.
	 * @param array[]  $rows     Each { id, cells } with cells as HTML already escaped; or { group } for a heading row.
	 */
	protected static function render_table( array $headings, array $rows ) {
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing to tidy here.', 'chess-army-knife' ) . '</p>';
			return;
		}

		Chess_Army_Knife_Members_Page::bulk_form_open();
		Chess_Army_Knife_Members_Page::render_bulk_controls( false );
		?>
		<table class="wp-list-table widefat fixed striped">
<caption class="screen-reader-text"><?php esc_html_e( 'Members to check', 'chess-army-knife' ); ?></caption>
			<thead>
				<tr>
					<td class="manage-column column-cb check-column"><input type="checkbox" aria-label="<?php esc_attr_e( 'Select all', 'chess-army-knife' ); ?>" /></td>
					<?php foreach ( $headings as $heading ) : ?>
						<th><?php echo esc_html( $heading ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php if ( isset( $row['group'] ) ) : ?>
						<tr><td></td><td colspan="<?php echo esc_attr( count( $headings ) ); ?>"><em><?php echo esc_html( $row['group'] ); ?></em></td></tr>
						<?php continue; ?>
					<?php endif; ?>
					<tr>
						<th scope="row" class="check-column"><label class="cak-check"><input type="checkbox" name="members[]" value="<?php echo esc_attr( $row['id'] ); ?>" /><span class="screen-reader-text"><?php echo esc_html( isset( $row['cells'][0] ) ? wp_strip_all_tags( $row['cells'][0] ) : __( 'Select this person', 'chess-army-knife' ) ); ?></span></label></th>
						<?php foreach ( $row['cells'] as $cell ) : ?>
							<td><?php echo wp_kses_post( $cell ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</form>
		<?php
	}

	/**
	 * The contact details shown beside a person.
	 *
	 * @param array $member Member row.
	 * @return string HTML.
	 */
	protected static function contact_cell( array $member ) {
		$email = Chess_Army_Knife_Membership_Store::contact_email( $member );
		return '' === $email ? '&mdash;' : esc_html( $email );
	}

	/**
	 * Render the screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Memberships::user_can_manage(), __( 'Member Checks', 'chess-army-knife' ), 'members' ) ) {
			return;
		}

		$today   = current_time( 'Y-m-d' );
		$members = Chess_Army_Knife_Membership_Store::get_members( array( 'view' => 'people' ) );
		$checks  = self::counts( $members, $today );
		$format  = get_option( 'date_format' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen state; nothing is changed.
		$current = isset( $_GET['check'] ) ? sanitize_key( wp_unslash( $_GET['check'] ) ) : '';
		if ( ! isset( $checks[ $current ] ) ) {
			$current = 'missing_ecf';
			foreach ( $checks as $key => $check ) {
				if ( $check['count'] ) {
					$current = $key;
					break;
				}
			}
		}
		$notice = Chess_Army_Knife_Members_Page::bulk_notice();
		if ( isset( $_GET['verified'] ) ) {
			$asked  = absint( $_GET['verified'] );
			$failed = isset( $_GET['verify_failed'] ) ? absint( $_GET['verify_failed'] ) : 0;
			$notice = array(
				$failed ? 'warning' : 'success',
				sprintf(
					/* translators: 1: codes asked about, 2: codes the ECF could not answer for */
					_n( 'Asked the ECF about %1$d code; %2$d could not be checked and will be tried again.', 'Asked the ECF about %1$d codes; %2$d could not be checked and will be tried again.', $asked, 'chess-army-knife' ),
					$asked,
					$failed
				),
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Member Checks', 'chess-army-knife' ); ?></h1>
			<?php
			if ( $notice ) {
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
			}
			?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $checks as $key => $check ) : ?>
					<a href="<?php echo esc_url( self::url( array( 'check' => $key ) ) ); ?>" class="nav-tab<?php echo $key === $current ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $check['label'] ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $check['count'] ) ); ?>)</span>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php
			switch ( $current ) {
				case 'invalid_ecf':
					self::render_invalid_ecf( $members, $today );
					break;
				case 'incomplete':
					self::render_incomplete( $members, $today, $format );
					break;
				case 'expired':
					self::render_expired( $members, $today, $format );
					break;
				case 'duplicates':
					self::render_duplicates( $members );
					break;
				default:
					self::render_missing_ecf( $members, $today );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Current members with no ECF code.
	 *
	 * @param array[] $members Every person held.
	 * @param string  $today   Today's site-local date, YYYY-MM-DD.
	 */
	protected static function render_missing_ecf( array $members, $today ) {
		$rows = array();
		foreach ( Chess_Army_Knife_Member_Audit::missing_ecf_code( $members, $today ) as $member ) {
			$rows[] = array(
				'id'    => $member['id'],
				'cells' => array(
					self::member_link( $member ),
					esc_html( $member['type_name'] ),
					null === $member['manual_rating'] ? '&mdash;' : esc_html( (string) $member['manual_rating'] ),
					self::contact_cell( $member ),
				),
			);
		}

		echo '<p class="description">' . esc_html__( 'Current members with no ECF rating code, so no rating can be shown for them. Add their code on their record.', 'chess-army-knife' ) . '</p>';
		self::render_table( array( __( 'Name', 'chess-army-knife' ), __( 'Membership', 'chess-army-knife' ), __( 'Manual rating', 'chess-army-knife' ), __( 'Contact', 'chess-army-knife' ) ), $rows );
	}

	/**
	 * Current members whose ECF code looks wrong, or that the ECF does not know.
	 *
	 * @param array[] $members Every person held.
	 * @param string  $today   Today's site-local date, YYYY-MM-DD.
	 */
	protected static function render_invalid_ecf( array $members, $today ) {
		$coded      = self::codes_to_verify( $members, $today );
		$unverified = 0;
		$seen       = array();
		foreach ( $coded as $member ) {
			$digits = Chess_Army_Knife_ECF_Client::normalise_code( $member['ecf_code'] );
			if ( ! isset( $seen[ $digits ] ) && null === Chess_Army_Knife_Member_Audit::ecf_verdict( $digits ) ) {
				++$unverified;
			}
			$seen[ $digits ] = true;
		}

		$rows = array();
		foreach ( Chess_Army_Knife_Member_Audit::malformed_ecf_code( $members, $today ) as $member ) {
			$rows[] = array(
				'id'    => $member['id'],
				'cells' => array(
					self::member_link( $member ),
					esc_html( $member['ecf_code'] ),
					esc_html__( 'Does not look like an ECF code (six digits and a letter).', 'chess-army-knife' ),
					self::contact_cell( $member ),
				),
			);
		}
		foreach ( self::unknown_to_ecf( $coded ) as $member ) {
			$rows[] = array(
				'id'    => $member['id'],
				'cells' => array(
					self::member_link( $member ),
					esc_html( $member['ecf_code'] ),
					esc_html__( 'The ECF does not know this code.', 'chess-army-knife' ),
					self::contact_cell( $member ),
				),
			);
		}

		echo '<p class="description">' . esc_html__( 'Codes that do not look like an ECF code are found straight away. To find codes the ECF has no player for, check them with the ECF: a few at a time, to stay within the ECF\'s daily allowance, and each answer is kept for six hours.', 'chess-army-knife' ) . '</p>';
		?>
		<p>
			<?php if ( $unverified ) : ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=chess_army_knife_verify_ecf_codes' ), 'chess_army_knife_verify_ecf_codes' ) ); ?>" class="button button-primary">
					<?php
					/* translators: %d: number of codes to ask the ECF about in one go */
					echo esc_html( sprintf( __( 'Check the next %d codes with the ECF', 'chess-army-knife' ), min( $unverified, self::VERIFY_BATCH ) ) );
					?>
				</a>
				<?php
				/* translators: %d: number of codes not yet checked */
				echo esc_html( sprintf( _n( '%d code has not been checked yet.', '%d codes have not been checked yet.', $unverified, 'chess-army-knife' ), $unverified ) );
				?>
			<?php else : ?>
				<?php esc_html_e( 'Every code has been checked with the ECF recently.', 'chess-army-knife' ); ?>
			<?php endif; ?>
		</p>
		<?php
		self::render_table( array( __( 'Name', 'chess-army-knife' ), __( 'ECF code', 'chess-army-knife' ), __( 'Problem', 'chess-army-knife' ), __( 'Contact', 'chess-army-knife' ) ), $rows );
	}

	/**
	 * Pending applications missing something.
	 *
	 * @param array[] $members Every person held.
	 * @param string  $today   Today's site-local date, YYYY-MM-DD.
	 * @param string  $format  Date format.
	 */
	protected static function render_incomplete( array $members, $today, $format ) {
		$rows = array();
		foreach ( Chess_Army_Knife_Member_Audit::incomplete_applications( $members, $today ) as $item ) {
			$member = $item['member'];
			$rows[] = array(
				'id'    => $member['id'],
				'cells' => array(
					self::member_link( $member ),
					esc_html( mysql2date( $format, $member['created_at'] ) ),
					esc_html( implode( '; ', $item['problems'] ) ),
					self::contact_cell( $member ),
				),
			);
		}

		echo '<p class="description">' . esc_html__( 'Pending applications that are missing something, oldest first.', 'chess-army-knife' ) . '</p>';
		self::render_table( array( __( 'Name', 'chess-army-knife' ), __( 'Applied', 'chess-army-knife' ), __( 'Missing', 'chess-army-knife' ), __( 'Contact', 'chess-army-knife' ) ), $rows );
	}

	/**
	 * Members whose last day of membership has passed.
	 *
	 * @param array[] $members Every person held.
	 * @param string  $today   Today's site-local date, YYYY-MM-DD.
	 * @param string  $format  Date format.
	 */
	protected static function render_expired( array $members, $today, $format ) {
		$expired = array_filter(
			$members,
			function ( $member ) use ( $today ) {
				return Chess_Army_Knife_Membership_Store::STATUS_EXPIRED === Chess_Army_Knife_Membership_Store::effective_status( $member, $today );
			}
		);
		usort(
			$expired,
			function ( $a, $b ) {
				return strcmp( $b['expiry_date'], $a['expiry_date'] ); // Most recently lapsed first: the likeliest to renew.
			}
		);

		$rows = array();
		foreach ( $expired as $member ) {
			$renew  = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'chess_army_knife_renew_member',
						'member' => $member['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'chess_army_knife_renew_member_' . $member['id']
			);
			$rows[] = array(
				'id'    => $member['id'],
				'cells' => array(
					self::member_link( $member ),
					esc_html( $member['type_name'] ),
					esc_html( mysql2date( $format, $member['expiry_date'] ) ),
					esc_html( Chess_Army_Knife_Member_History::duration_label( gmdate( 'Y-m-d', strtotime( $member['expiry_date'] . ' UTC' ) + DAY_IN_SECONDS ), $today ) ),
					self::contact_cell( $member ),
					'<a href="' . esc_url( $renew ) . '">' . esc_html__( 'Renew', 'chess-army-knife' ) . '</a>',
				),
			);
		}

		echo '<p class="description">' . esc_html__( 'Members whose last day of membership has passed, most recent first. Renew moves their last day forward by the length of their membership type.', 'chess-army-knife' ) . '</p>';
		self::render_table( array( __( 'Name', 'chess-army-knife' ), __( 'Membership', 'chess-army-knife' ), __( 'Last day', 'chess-army-knife' ), __( 'Lapsed for', 'chess-army-knife' ), __( 'Contact', 'chess-army-knife' ), '' ), $rows );
	}

	/**
	 * People who may be in the records twice.
	 *
	 * @param array[] $members Every person held.
	 */
	protected static function render_duplicates( array $members ) {
		$reasons  = array(
			'ecf_code' => __( 'Same ECF code', 'chess-army-knife' ),
			'email'    => __( 'Same email address', 'chess-army-knife' ),
			'name'     => __( 'Same name', 'chess-army-knife' ),
		);
		$statuses = Chess_Army_Knife_Membership_Store::status_labels() + array( Chess_Army_Knife_Membership_Store::STATUS_EXPIRED => __( 'Expired', 'chess-army-knife' ) );
		$today    = current_time( 'Y-m-d' );
		$rows     = array();

		foreach ( Chess_Army_Knife_Member_Audit::duplicate_groups( $members ) as $group ) {
			$rows[] = array( 'group' => $reasons[ $group['reason'] ] );
			foreach ( $group['members'] as $member ) {
				$status = Chess_Army_Knife_Membership_Store::effective_status( $member, $today );
				$rows[] = array(
					'id'    => $member['id'],
					'cells' => array(
						self::member_link( $member ),
						esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status ),
						'' === $member['ecf_code'] ? '&mdash;' : esc_html( $member['ecf_code'] ),
						self::contact_cell( $member ),
					),
				);
			}
		}

		echo '<p class="description">' . esc_html__( 'People who share an ECF code or an email address, or who have the same name and could be one person. Open each record to decide which to keep, and delete the other. A parent\'s email address is not used, as brothers and sisters share one.', 'chess-army-knife' ) . '</p>';
		self::render_table( array( __( 'Name', 'chess-army-knife' ), __( 'Status', 'chess-army-knife' ), __( 'ECF code', 'chess-army-knife' ), __( 'Contact', 'chess-army-knife' ) ), $rows );
	}
}

Chess_Army_Knife_Member_Checks_Page::init();
