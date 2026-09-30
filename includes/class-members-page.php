<?php
/**
 * "Members" admin page: the list of members and applications, the form to
 * add or edit one by hand (for people who can't use the online form), and
 * the approve, decline and delete actions.
 *
 * Everything here needs the chess_army_manage_memberships permission.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Members_Page {

	/**
	 * Boot the form handlers.
	 */
	public static function init() {
		add_action( 'admin_post_chess_army_knife_save_member', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_chess_army_knife_member_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_chess_army_knife_delete_member', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Address of the members page, with extra query arguments.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	protected static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => Chess_Army_Knife_Memberships::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Stop anyone without the membership permission.
	 */
	protected static function require_permission() {
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), '', array( 'response' => 403 ) );
		}
	}

	/* -------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------- */

	/**
	 * Handle the add/edit form.
	 */
	public static function handle_save() {
		self::require_permission();
		check_admin_referer( 'chess_army_knife_save_member' );

		$id       = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		$previous = $id ? Chess_Army_Knife_Membership_Store::get_member( $id ) : null;
		$clean    = Chess_Army_Knife_Membership_Store::sanitize_member( wp_unslash( $_POST ), true );
		$form     = $id ? array( 'edit' => $id ) : array( 'edit' => 'new' );

		if ( is_wp_error( $clean ) ) {
			wp_safe_redirect( self::url( $form + array( 'error' => $clean->get_error_code() ) ) );
			exit;
		}

		// A member who becomes active with no dates starts today and lasts as long as their type says.
		$becomes_active = Chess_Army_Knife_Membership_Store::STATUS_ACTIVE === $clean['status'] && ( ! $previous || Chess_Army_Knife_Membership_Store::STATUS_ACTIVE !== $previous['status'] );
		if ( $becomes_active ) {
			$type = Chess_Army_Knife_Memberships::get_type( $clean['membership_type_id'] );
			if ( null === $clean['start_date'] ) {
				$clean['start_date'] = current_time( 'Y-m-d' );
			}
			if ( null === $clean['expiry_date'] ) {
				$clean['expiry_date'] = Chess_Army_Knife_Memberships::expiry_from( $clean['start_date'], $type ? $type['months'] : 0 ) ?: null;
			}
		}

		if ( $id ) {
			$clean['id'] = $id;
		} else {
			$clean['source'] = Chess_Army_Knife_Membership_Store::SOURCE_MANUAL;
		}
		Chess_Army_Knife_Membership_Store::save_member( $clean );

		wp_safe_redirect( self::url( array( 'saved' => '1' ) ) );
		exit;
	}

	/**
	 * Handle the approve, decline and cancel links.
	 */
	public static function handle_status() {
		self::require_permission();

		$id     = isset( $_GET['member'] ) ? absint( $_GET['member'] ) : 0;
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		check_admin_referer( 'chess_army_knife_member_status_' . $id );

		if ( ! Chess_Army_Knife_Membership_Store::get_member( $id ) || ! isset( Chess_Army_Knife_Membership_Store::status_labels()[ $status ] ) ) {
			wp_safe_redirect( self::url( array( 'error' => 'member_missing' ) ) );
			exit;
		}

		if ( Chess_Army_Knife_Membership_Store::STATUS_ACTIVE === $status ) {
			Chess_Army_Knife_Membership_Store::approve( $id );
		} else {
			Chess_Army_Knife_Membership_Store::set_status( $id, $status );
		}

		wp_safe_redirect( self::url( array( 'updated' => $status ) ) );
		exit;
	}

	/**
	 * Handle the delete link.
	 */
	public static function handle_delete() {
		self::require_permission();

		$id = isset( $_GET['member'] ) ? absint( $_GET['member'] ) : 0;
		check_admin_referer( 'chess_army_knife_delete_member_' . $id );

		Chess_Army_Knife_Membership_Store::delete_member( $id );

		wp_safe_redirect( self::url( array( 'deleted' => '1' ) ) );
		exit;
	}

	/* -------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------- */

	/**
	 * A message for an error code passed back in the address.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	protected static function error_message( $code ) {
		$messages = array(
			'member_missing'    => __( 'That member no longer exists.', 'chess-army-knife' ),
			'member_status'     => __( 'Please choose a status.', 'chess-army-knife' ),
			'member_date'       => __( 'Please enter dates as YYYY-MM-DD.', 'chess-army-knife' ),
			'member_date_order' => __( 'The expiry date cannot be before the start date.', 'chess-army-knife' ),
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : Chess_Army_Knife_Membership_Form::error_message( $code );
	}

	/**
	 * Render the page: the add/edit form if asked for, otherwise the list.
	 */
	public static function render_page() {
		self::require_permission();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen state; nothing is changed.
		if ( isset( $_GET['edit'] ) ) {
			$edit   = sanitize_text_field( wp_unslash( $_GET['edit'] ) );
			$member = 'new' === $edit ? null : Chess_Army_Knife_Membership_Store::get_member( absint( $edit ) );
			if ( 'new' !== $edit && ! $member ) {
				wp_die( esc_html( self::error_message( 'member_missing' ) ), '', array( 'back_link' => true ) );
			}
			self::render_form( $member );
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		self::render_list();
	}

	/**
	 * Notices for the outcome of the last action.
	 */
	protected static function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen state; nothing is changed.
		if ( isset( $_GET['saved'] ) ) {
			$notice = array( 'success', __( 'Member saved.', 'chess-army-knife' ) );
		} elseif ( isset( $_GET['deleted'] ) ) {
			$notice = array( 'success', __( 'Member deleted.', 'chess-army-knife' ) );
		} elseif ( isset( $_GET['updated'] ) ) {
			$notice = array( 'success', __( 'Member updated.', 'chess-army-knife' ) );
		} elseif ( isset( $_GET['error'] ) ) {
			$notice = array( 'error', self::error_message( sanitize_key( wp_unslash( $_GET['error'] ) ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( isset( $notice ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}
	}

	/**
	 * A link that runs one of the handlers, with its nonce.
	 *
	 * @param string $action Handler suffix: member_status or delete_member.
	 * @param int    $id     Member id.
	 * @param array  $args   Extra query arguments.
	 * @return string
	 */
	protected static function action_url( $action, $id, array $args = array() ) {
		$args['action'] = 'chess_army_knife_' . $action;
		$args['member'] = $id;
		$url            = add_query_arg( $args, admin_url( 'admin-post.php' ) );
		return wp_nonce_url( $url, 'chess_army_knife_' . $action . '_' . $id );
	}

	/**
	 * The list of members with its views, search and row actions.
	 */
	protected static function render_list() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen state; nothing is changed.
		$views = Chess_Army_Knife_Membership_Store::view_labels();
		// Applications waiting for review come first; otherwise show the current members.
		$default_view = Chess_Army_Knife_Membership_Store::count_view( 'pending' ) ? 'pending' : 'active';
		$view         = isset( $_GET['view'] ) && isset( $views[ sanitize_key( wp_unslash( $_GET['view'] ) ) ] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : $default_view;
		$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$members = Chess_Army_Knife_Membership_Store::get_members(
			array(
				'view'   => $view,
				'search' => $search,
			)
		);
		$today   = current_time( 'Y-m-d' );
		$labels  = Chess_Army_Knife_Membership_Store::status_labels() + array( Chess_Army_Knife_Membership_Store::STATUS_EXPIRED => __( 'Expired', 'chess-army-knife' ) );
		$methods = Chess_Army_Knife_Memberships::payment_methods();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Members', 'chess-army-knife' ); ?></h1>
			<a href="<?php echo esc_url( self::url( array( 'edit' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add member', 'chess-army-knife' ); ?></a>
			<hr class="wp-header-end" />

			<?php self::render_notices(); ?>

			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $views as $key => $label ) {
					$links[] = sprintf(
						'<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
						esc_url( self::url( array( 'view' => $key ) ) ),
						$key === $view ? ' class="current" aria-current="page"' : '',
						esc_html( $label ),
						Chess_Army_Knife_Membership_Store::count_view( $key )
					);
				}
				echo wp_kses_post( implode( ' | </li>', $links ) . '</li>' );
				?>
			</ul>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Chess_Army_Knife_Memberships::MENU_SLUG ); ?>" />
				<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
				<p class="search-box">
					<label class="screen-reader-text" for="member-search-input"><?php esc_html_e( 'Search members', 'chess-army-knife' ); ?></label>
					<input type="search" id="member-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" />
					<input type="submit" class="button" value="<?php esc_attr_e( 'Search members', 'chess-army-knife' ); ?>" />
				</p>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Membership', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Status', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Contact', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Payment', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $members ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No members found.', 'chess-army-knife' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $members as $member ) : ?>
						<?php $status = Chess_Army_Knife_Membership_Store::effective_status( $member, $today ); ?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( self::url( array( 'edit' => $member['id'] ) ) ); ?>"><?php echo esc_html( $member['name'] ); ?></a></strong>
								<div class="row-actions">
									<?php if ( Chess_Army_Knife_Membership_Store::STATUS_PENDING === $member['status'] ) : ?>
										<a href="<?php echo esc_url( self::action_url( 'member_status', $member['id'], array( 'status' => Chess_Army_Knife_Membership_Store::STATUS_ACTIVE ) ) ); ?>"><?php esc_html_e( 'Approve', 'chess-army-knife' ); ?></a> |
										<a href="<?php echo esc_url( self::action_url( 'member_status', $member['id'], array( 'status' => Chess_Army_Knife_Membership_Store::STATUS_REJECTED ) ) ); ?>"><?php esc_html_e( 'Decline', 'chess-army-knife' ); ?></a> |
									<?php endif; ?>
									<a href="<?php echo esc_url( self::url( array( 'edit' => $member['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'chess-army-knife' ); ?></a> |
									<a href="<?php echo esc_url( self::action_url( 'delete_member', $member['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this member and their details?', 'chess-army-knife' ) ); ?>');"><?php esc_html_e( 'Delete', 'chess-army-knife' ); ?></a>
								</div>
							</td>
							<td><?php echo esc_html( $member['type_name'] ); ?></td>
							<td><?php echo esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ); ?></td>
							<td><?php echo '' === $member['expiry_date'] ? '&mdash;' : esc_html( mysql2date( get_option( 'date_format' ), $member['expiry_date'] ) ); ?></td>
							<td>
								<?php echo esc_html( $member['email'] ); ?>
								<?php echo '' !== $member['email'] && '' !== $member['phone'] ? '<br />' : ''; ?>
								<?php echo esc_html( $member['phone'] ); ?>
							</td>
							<td>
								<?php
								if ( '' === $member['paid_on'] ) {
									esc_html_e( 'Not paid', 'chess-army-knife' );
								} else {
									$method = isset( $methods[ $member['payment_method'] ] ) ? $methods[ $member['payment_method'] ] : '';
									/* translators: 1: date the payment was received, 2: payment method */
									echo esc_html( trim( sprintf( __( 'Paid %1$s %2$s', 'chess-army-knife' ), mysql2date( get_option( 'date_format' ), $member['paid_on'] ), $method ) ) );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * The form to add a member, or edit one.
	 *
	 * @param array|null $member The member to edit, or null to add one.
	 */
	protected static function render_form( $member ) {
		$editing = null !== $member;
		$member  = $editing ? $member : array_fill_keys( array( 'name', 'email', 'phone', 'date_of_birth', 'guardian_name', 'ecf_code', 'payment_method', 'paid_on', 'notes', 'expiry_date', 'consent_at' ), '' ) + array(
			'membership_type_id' => 0,
			'status'             => Chess_Army_Knife_Membership_Store::STATUS_ACTIVE,
			'start_date'         => current_time( 'Y-m-d' ),
		);
		$types   = Chess_Army_Knife_Memberships::types( false );

		// The details a treasurer needs to match a payment.
		$reference = $editing ? Chess_Army_Knife_Memberships::payment_reference( $member['id'] ) : '';
		?>
		<div class="wrap">
			<h1><?php echo $editing ? esc_html__( 'Edit member', 'chess-army-knife' ) : esc_html__( 'Add a member', 'chess-army-knife' ); ?></h1>
			<?php if ( ! $editing ) : ?>
				<p class="description"><?php esc_html_e( 'Use this to add someone who cannot fill in the online form, for example if they joined in person.', 'chess-army-knife' ); ?></p>
			<?php endif; ?>

			<?php self::render_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="chess_army_knife_save_member" />
				<input type="hidden" name="member_id" value="<?php echo esc_attr( $editing ? $member['id'] : 0 ); ?>" />
				<?php wp_nonce_field( 'chess_army_knife_save_member' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="name"><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="name" name="name" class="regular-text" value="<?php echo esc_attr( $member['name'] ); ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="email"><?php esc_html_e( 'Email', 'chess-army-knife' ); ?></label></th>
						<td><input type="email" id="email" name="email" class="regular-text" value="<?php echo esc_attr( $member['email'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="phone"><?php esc_html_e( 'Phone', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="phone" name="phone" class="regular-text" value="<?php echo esc_attr( $member['phone'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="date_of_birth"><?php esc_html_e( 'Date of birth', 'chess-army-knife' ); ?></label></th>
						<td><input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo esc_attr( $member['date_of_birth'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="guardian_name"><?php esc_html_e( 'Parent or guardian', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="guardian_name" name="guardian_name" class="regular-text" value="<?php echo esc_attr( $member['guardian_name'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ecf_code"><?php esc_html_e( 'ECF rating code', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="ecf_code" name="ecf_code" class="regular-text" value="<?php echo esc_attr( $member['ecf_code'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="membership_type_id"><?php esc_html_e( 'Membership type', 'chess-army-knife' ); ?></label></th>
						<td>
							<select id="membership_type_id" name="membership_type_id">
								<option value="0"><?php esc_html_e( '— None —', 'chess-army-knife' ); ?></option>
								<?php foreach ( $types as $type ) : ?>
									<option value="<?php echo esc_attr( $type['id'] ); ?>" <?php selected( $member['membership_type_id'], $type['id'] ); ?>>
										<?php echo esc_html( $type['name'] . ' (' . $type['price_label'] . ' ' . $type['period_label'] . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="status"><?php esc_html_e( 'Status', 'chess-army-knife' ); ?></label></th>
						<td>
							<select id="status" name="status">
								<?php foreach ( Chess_Army_Knife_Membership_Store::status_labels() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $member['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="start_date"><?php esc_html_e( 'Starts', 'chess-army-knife' ); ?></label></th>
						<td><input type="date" id="start_date" name="start_date" value="<?php echo esc_attr( $member['start_date'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="expiry_date"><?php esc_html_e( 'Expires', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="date" id="expiry_date" name="expiry_date" value="<?php echo esc_attr( $member['expiry_date'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The last day of membership. When an application is approved, or a member is added as active, a blank date is filled in from the length of their membership type.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="paid_on"><?php esc_html_e( 'Payment received', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="date" id="paid_on" name="paid_on" value="<?php echo esc_attr( $member['paid_on'] ); ?>" />
							<select id="payment_method" name="payment_method" aria-label="<?php esc_attr_e( 'Payment method', 'chess-army-knife' ); ?>">
								<option value=""><?php esc_html_e( '— Method —', 'chess-army-knife' ); ?></option>
								<?php foreach ( Chess_Army_Knife_Memberships::payment_methods() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $member['payment_method'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( $editing ) : ?>
								<p class="description">
									<?php
									/* translators: %s: the payment reference for this member */
									echo esc_html( sprintf( __( 'Payments are made outside the website. Their bank transfer reference is %s.', 'chess-army-knife' ), $reference ) );
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $editing ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Consent', 'chess-army-knife' ); ?></th>
							<td>
								<?php
								if ( '' !== $member['consent_at'] ) {
									/* translators: %s: date and time the applicant agreed */
									echo esc_html( sprintf( __( 'Agreed to the club keeping their details on %s (from the online form).', 'chess-army-knife' ), get_date_from_gmt( $member['consent_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) );
								} else {
									esc_html_e( 'Not recorded: added by the club.', 'chess-army-knife' );
								}
								?>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="notes"><?php esc_html_e( 'Notes', 'chess-army-knife' ); ?></label></th>
						<td>
							<textarea id="notes" name="notes" rows="4" class="large-text"><?php echo esc_textarea( $member['notes'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Private: only people who can manage members see these.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( $editing ? __( 'Save member', 'chess-army-knife' ) : __( 'Add member', 'chess-army-knife' ) ); ?>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Back to members', 'chess-army-knife' ); ?></a>
			</form>
		</div>
		<?php
	}
}

Chess_Army_Knife_Members_Page::init();
