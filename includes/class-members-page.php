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
		add_action( 'admin_post_chess_army_knife_refresh_ratings', array( __CLASS__, 'handle_refresh_ratings' ) );
		add_action( 'admin_post_chess_army_knife_bulk_members', array( __CLASS__, 'handle_bulk' ) );
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

		$clean = self::keep_recorded_consents( $clean, $previous );

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
		$saved_id = Chess_Army_Knife_Membership_Store::save_member( $clean );

		// A rating belongs to the code it was fetched for.
		if ( $previous && $previous['ecf_code'] !== $clean['ecf_code'] ) {
			Chess_Army_Knife_Membership_Store::clear_rating( $saved_id );
		}

		// Any number of teams, or none: the boxes are only on the form when the club has teams.
		if ( ! empty( $_POST['squad_teams_shown'] ) ) {
			Chess_Army_Knife_Teams::set_teams_of_person( $saved_id, isset( $_POST['squad_teams'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['squad_teams'] ) ) : array() );
		}

		wp_safe_redirect( self::url( array( 'saved' => '1' ) ) );
		exit;
	}

	/**
	 * A consent that was already recorded keeps its original time when its box
	 * is still ticked; unticking it withdraws the consent.
	 *
	 * @param array      $clean    Cleaned form values.
	 * @param array|null $previous The member as saved before, or null for a new one.
	 * @return array
	 */
	public static function keep_recorded_consents( array $clean, $previous ) {
		foreach ( array( 'newsletter_consent_at', 'whatsapp_consent_at' ) as $consent ) {
			if ( $previous && '' !== $previous[ $consent ] && null !== $clean[ $consent ] ) {
				$clean[ $consent ] = $previous[ $consent ];
			}
		}
		return $clean;
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
	 * Handle the "Refresh ECF ratings" link: check one batch now instead of waiting for the hourly job.
	 */
	public static function handle_refresh_ratings() {
		self::require_permission();
		check_admin_referer( 'chess_army_knife_refresh_ratings_0' );

		$result = Chess_Army_Knife_Rating_Refresh::run();

		wp_safe_redirect(
			self::url(
				array(
					'rr_checked' => $result['checked'],
					'rr_updated' => $result['updated'],
					'rr_failed'  => $result['failed'],
				)
			)
		);
		exit;
	}

	/**
	 * Handle the bulk actions on ticked members: change their membership type,
	 * add them to a team or take them out of one, or export them to CSV.
	 * The Member Checks screen uses this too, for its export.
	 */
	public static function handle_bulk() {
		self::require_permission();
		check_admin_referer( 'chess_army_knife_bulk_members' );

		$ids     = isset( $_POST['members'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['members'] ) ) : array();
		$members = Chess_Army_Knife_Membership_Store::get_members_by_ids( $ids );
		$action  = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$back    = wp_get_referer() ? remove_query_arg( array( 'bulk', 'bulk_count', 'bulk_to', 'bulk_error' ), wp_get_referer() ) : self::url();

		if ( ! $members ) {
			wp_safe_redirect( add_query_arg( 'bulk_error', 'none', $back ) );
			exit;
		}

		if ( 'export' === $action ) {
			Chess_Army_Knife_Member_Export::download( $members );
		}

		if ( 'erase' === $action ) {
			if ( empty( $_POST['erase_confirm'] ) ) {
				wp_safe_redirect( add_query_arg( 'bulk_error', 'erase_confirm', $back ) );
				exit;
			}
			$do_not_record = ! empty( $_POST['erase_do_not_record'] );
			$deleted       = 0;
			$kept          = 0;
			foreach ( $members as $member ) {
				if ( 'anonymised' === Chess_Army_Knife_Membership_Store::erase_member( $member['id'], $do_not_record ) ) {
					++$kept;
				} else {
					++$deleted;
				}
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'bulk'       => 'erase',
						'bulk_count' => $deleted,
						'bulk_to'    => $kept,
					),
					$back
				)
			);
			exit;
		}

		$done = array(
			'bulk'       => $action,
			'bulk_count' => count( $members ),
		);

		if ( 'type' === $action ) {
			$type = isset( $_POST['bulk_type'] ) ? Chess_Army_Knife_Memberships::get_type( absint( $_POST['bulk_type'] ) ) : null;
			if ( ! $type ) {
				wp_safe_redirect( add_query_arg( 'bulk_error', 'type', $back ) );
				exit;
			}
			foreach ( $members as $member ) {
				Chess_Army_Knife_Membership_Store::set_type( $member['id'], $type );
			}
			$done['bulk_to'] = $type['name'];
		} elseif ( 'team_add' === $action || 'team_remove' === $action ) {
			$team = isset( $_POST['bulk_team'] ) ? Chess_Army_Knife_Teams::get( absint( $_POST['bulk_team'] ) ) : null;
			if ( ! $team ) {
				wp_safe_redirect( add_query_arg( 'bulk_error', 'team', $back ) );
				exit;
			}
			$member_ids = wp_list_pluck( $members, 'id' );
			if ( 'team_add' === $action ) {
				Chess_Army_Knife_Teams::add_to_squad( $team['id'], $member_ids );
			} else {
				Chess_Army_Knife_Teams::remove_from_squad( $team['id'], $member_ids );
			}
			$done['bulk_to'] = $team['name'];
		} else {
			wp_safe_redirect( add_query_arg( 'bulk_error', 'action', $back ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $done ) ), $back ) );
		exit;
	}

	/**
	 * The notice for the outcome of a bulk action, from the address.
	 *
	 * @return array|null { type, message }, or null if there was no bulk action.
	 */
	public static function bulk_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen state; nothing is changed.
		if ( isset( $_GET['bulk_error'] ) ) {
			$errors = array(
				'none'          => __( 'Tick at least one member first.', 'chess-army-knife' ),
				'type'          => __( 'Please choose a membership type.', 'chess-army-knife' ),
				'team'          => __( 'Please choose a team.', 'chess-army-knife' ),
				'action'        => __( 'Please choose what to do with the ticked members.', 'chess-army-knife' ),
				'erase_confirm' => __( 'Tick the box to confirm you want the ticked people\'s details deleted. Nothing was changed.', 'chess-army-knife' ),
			);
			$code   = sanitize_key( wp_unslash( $_GET['bulk_error'] ) );
			return array( 'error', isset( $errors[ $code ] ) ? $errors[ $code ] : $errors['action'] );
		}

		if ( ! isset( $_GET['bulk'], $_GET['bulk_count'], $_GET['bulk_to'] ) ) {
			return null;
		}
		$action = sanitize_key( wp_unslash( $_GET['bulk'] ) );
		$count  = absint( $_GET['bulk_count'] );
		$to     = sanitize_text_field( wp_unslash( $_GET['bulk_to'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'erase' === $action ) {
			/* translators: 1: number of people deleted, 2: number of records kept without personal details */
			return array( 'success', sprintf( __( 'Deleted %1$d people. %2$d records were kept without any personal details because they are tied to a payment, photos or a tournament.', 'chess-army-knife' ), $count, absint( $_GET['bulk_to'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state.
		}
		if ( 'type' === $action ) {
			/* translators: 1: number of members, 2: membership type */
			return array( 'success', sprintf( _n( '%1$d member moved to %2$s.', '%1$d members moved to %2$s.', $count, 'chess-army-knife' ), $count, $to ) );
		}
		if ( 'team_add' === $action ) {
			/* translators: 1: number of members, 2: team name */
			return array( 'success', sprintf( _n( '%1$d member added to %2$s.', '%1$d members added to %2$s.', $count, 'chess-army-knife' ), $count, $to ) );
		}
		if ( 'team_remove' === $action ) {
			/* translators: 1: number of members, 2: team name */
			return array( 'success', sprintf( _n( '%1$d member removed from %2$s.', '%1$d members removed from %2$s.', $count, 'chess-army-knife' ), $count, $to ) );
		}
		return null;
	}

	/**
	 * The start of the form around a table of members that can be ticked.
	 */
	public static function bulk_form_open() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="chess_army_knife_bulk_members" />
			<?php wp_nonce_field( 'chess_army_knife_bulk_members' ); ?>
		<?php
	}

	/**
	 * The controls above a table of members that can be ticked: what to do with
	 * the ticked members, and the membership type or team it applies to.
	 * Must be inside the form opened by bulk_form_open().
	 *
	 * @param bool $full False to offer only the export, as the Member Checks screen does.
	 */
	public static function render_bulk_controls( $full = true ) {
		?>
		<div class="tablenav top">
			<div class="alignleft actions bulkactions">
				<label for="bulk_action" class="screen-reader-text"><?php esc_html_e( 'Action for ticked members', 'chess-army-knife' ); ?></label>
				<select name="bulk_action" id="bulk_action">
					<?php if ( $full ) : ?>
						<option value=""><?php esc_html_e( 'Bulk actions', 'chess-army-knife' ); ?></option>
						<option value="type"><?php esc_html_e( 'Change membership type to…', 'chess-army-knife' ); ?></option>
						<option value="team_add"><?php esc_html_e( 'Add to team…', 'chess-army-knife' ); ?></option>
						<option value="team_remove"><?php esc_html_e( 'Remove from team…', 'chess-army-knife' ); ?></option>
						<option value="erase"><?php esc_html_e( 'Delete personal details…', 'chess-army-knife' ); ?></option>
					<?php endif; ?>
					<option value="export"><?php esc_html_e( 'Export to CSV', 'chess-army-knife' ); ?></option>
				</select>
				<?php if ( $full ) : ?>
					<label for="bulk_type" class="screen-reader-text"><?php esc_html_e( 'Membership type', 'chess-army-knife' ); ?></label>
					<select name="bulk_type" id="bulk_type">
						<option value="0"><?php esc_html_e( 'Membership type', 'chess-army-knife' ); ?></option>
						<?php foreach ( Chess_Army_Knife_Memberships::types( false ) as $type ) : ?>
							<option value="<?php echo esc_attr( $type['id'] ); ?>"><?php echo esc_html( $type['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<label for="bulk_team" class="screen-reader-text"><?php esc_html_e( 'Team', 'chess-army-knife' ); ?></label>
					<select name="bulk_team" id="bulk_team">
						<option value="0"><?php esc_html_e( 'Team', 'chess-army-knife' ); ?></option>
						<?php foreach ( Chess_Army_Knife_Teams::choices() as $team_id => $team_name ) : ?>
							<option value="<?php echo esc_attr( $team_id ); ?>"><?php echo esc_html( $team_name ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<?php if ( $full ) : ?>
					<span class="cak-bulk-erase">
						<label><input type="checkbox" name="erase_confirm" value="1" /> <?php esc_html_e( 'For "Delete personal details": I understand this cannot be undone', 'chess-army-knife' ); ?></label>
						<label><input type="checkbox" name="erase_do_not_record" value="1" /> <?php esc_html_e( 'and do not record them again', 'chess-army-knife' ); ?></label>
					</span>
				<?php endif; ?>
				<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'chess-army-knife' ); ?>" />
			</div>
		</div>
		<?php
	}

	/**
	 * Handle the delete link.
	 */
	public static function handle_delete() {
		self::require_permission();

		$id = isset( $_GET['member'] ) ? absint( $_GET['member'] ) : 0;
		check_admin_referer( 'chess_army_knife_delete_member_' . $id );

		// Deleted outright, unless the record is tied to a payment, photos or a tournament, in which case its personal details are removed and the rest is kept.
		// The second link also stops the plugin recording the person again by itself.
		Chess_Army_Knife_Membership_Store::erase_member( $id, ! empty( $_GET['do_not_record'] ) );

		wp_safe_redirect( self::url( array( 'deleted' => empty( $_GET['do_not_record'] ) ? '1' : 'dnr' ) ) );
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
			'renew'             => __( 'That membership could not be renewed: it must be a current or lapsed member whose type has a length.', 'chess-army-knife' ),
			'member_rating'     => __( 'That manual rating is out of range.', 'chess-army-knife' ),
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : Chess_Army_Knife_Membership_Form::error_message( $code );
	}

	/**
	 * Render the page: the add/edit form if asked for, otherwise the list.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Access::render_unless( Chess_Army_Knife_Memberships::user_can_manage(), __( 'Members', 'chess-army-knife' ), 'members' ) ) {
			return;
		}

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
			$notice = array( 'success', 'dnr' === $_GET['deleted'] ? __( 'Member deleted. The plugin will not record them again by itself.', 'chess-army-knife' ) : __( 'Member deleted.', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only screen state, compared with fixed text.
		} elseif ( isset( $_GET['updated'] ) ) {
			$notice = array( 'success', __( 'Member updated.', 'chess-army-knife' ) );
		} elseif ( isset( $_GET['renewed'] ) ) {
			/* translators: %s: new last day of membership */
			$notice = array( 'success', sprintf( __( 'Membership renewed until %s.', 'chess-army-knife' ), mysql2date( get_option( 'date_format' ), sanitize_text_field( wp_unslash( $_GET['renewed'] ) ) ) ) );
		} elseif ( isset( $_GET['rr_checked'] ) ) {
			$notice = array(
				isset( $_GET['rr_failed'] ) && absint( $_GET['rr_failed'] ) ? 'warning' : 'success',
				sprintf(
					/* translators: 1: members checked, 2: ratings updated, 3: members the ECF could not give a rating for */
					__( 'Checked %1$d members with the ECF: %2$d ratings updated, %3$d could not be fetched. Everyone else is checked by the hourly job.', 'chess-army-knife' ),
					absint( $_GET['rr_checked'] ),
					isset( $_GET['rr_updated'] ) ? absint( $_GET['rr_updated'] ) : 0,
					isset( $_GET['rr_failed'] ) ? absint( $_GET['rr_failed'] ) : 0
				),
			);
		} elseif ( isset( $_GET['error'] ) ) {
			$notice = array( 'error', self::error_message( sanitize_key( wp_unslash( $_GET['error'] ) ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $notice ) ) {
			$notice = self::bulk_notice();
		}

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
		$photos  = Chess_Army_Knife_Member_Photos::counts();
		$labels  = Chess_Army_Knife_Membership_Store::status_labels() + array( Chess_Army_Knife_Membership_Store::STATUS_EXPIRED => __( 'Expired', 'chess-army-knife' ) );
		$methods = Chess_Army_Knife_Memberships::payment_methods();
		$squads  = Chess_Army_Knife_Teams::squad_names_by_person();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Members', 'chess-army-knife' ); ?></h1>
			<a href="<?php echo esc_url( self::url( array( 'edit' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add member', 'chess-army-knife' ); ?></a>
			<a href="<?php echo esc_url( self::action_url( 'refresh_ratings', 0 ) ); ?>" class="page-title-action"><?php esc_html_e( 'Refresh ECF ratings', 'chess-army-knife' ); ?></a>
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

			<?php self::bulk_form_open(); ?>
			<?php self::render_bulk_controls(); ?>
			<table class="wp-list-table widefat fixed striped">
<caption class="screen-reader-text"><?php esc_html_e( 'Members', 'chess-army-knife' ); ?></caption>
				<thead>
					<tr>
						<td class="manage-column column-cb check-column"><input type="checkbox" aria-label="<?php esc_attr_e( 'Select all', 'chess-army-knife' ); ?>" /></td>
						<th><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Membership', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Teams', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Status', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Contact', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Payment', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Agreed to', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'ECF rating', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $members ) ) : ?>
						<tr><td colspan="10"><?php esc_html_e( 'No members found.', 'chess-army-knife' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $members as $member ) : ?>
						<?php $status = Chess_Army_Knife_Membership_Store::effective_status( $member, $today ); ?>
						<tr>
							<th scope="row" class="check-column"><label class="cak-check"><input type="checkbox" name="members[]" value="<?php echo esc_attr( $member['id'] ); ?>" /><span class="screen-reader-text"><?php echo esc_html( $member['name'] ); ?></span></label></th>
							<td>
								<strong><a href="<?php echo esc_url( self::url( array( 'edit' => $member['id'] ) ) ); ?>"><?php echo esc_html( $member['name'] ); ?></a></strong>
								<div class="row-actions">
									<?php if ( Chess_Army_Knife_Membership_Store::STATUS_PENDING === $member['status'] ) : ?>
										<a href="<?php echo esc_url( self::action_url( 'member_status', $member['id'], array( 'status' => Chess_Army_Knife_Membership_Store::STATUS_ACTIVE ) ) ); ?>"><?php esc_html_e( 'Approve', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $member['name'] ); ?></span></a> |
										<a href="<?php echo esc_url( self::action_url( 'member_status', $member['id'], array( 'status' => Chess_Army_Knife_Membership_Store::STATUS_REJECTED ) ) ); ?>"><?php esc_html_e( 'Decline', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $member['name'] ); ?></span></a> |
									<?php endif; ?>
									<a href="<?php echo esc_url( self::url( array( 'edit' => $member['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $member['name'] ); ?></span></a> |
									<a href="<?php echo esc_url( self::url( array( 'edit' => $member['id'] ) ) . '#membership-history' ); ?>"><?php esc_html_e( 'History', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $member['name'] ); ?></span></a> |
									<?php if ( Chess_Army_Knife_Membership_Store::STATUS_ACTIVE === $member['status'] ) : ?>
										<a href="<?php echo esc_url( self::action_url( 'renew_member', $member['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Renew this membership for another period and note the payment as received today?', 'chess-army-knife' ) ); ?>');"><?php esc_html_e( 'Renew', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $member['name'] ); ?></span></a> |
									<?php endif; ?>
									<?php if ( ! empty( $photos[ $member['id'] ] ) ) : ?>
										<a href="<?php echo esc_url( Chess_Army_Knife_Member_Photos::library_url( $member['id'] ) ); ?>">
											<?php
											/* translators: %d: number of photos the member is tagged in */
											echo esc_html( sprintf( _n( 'Photos (%d)', 'Photos (%d)', $photos[ $member['id'] ], 'chess-army-knife' ), $photos[ $member['id'] ] ) );
											?>
											<span class="screen-reader-text"> <?php echo esc_html( $member['name'] ); ?></span>
										</a> |
									<?php endif; ?>
									<a href="<?php echo esc_url( self::action_url( 'delete_member', $member['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this person and their details? If they have a payment, photos or tournament entries on record, those stay but without their personal details.', 'chess-army-knife' ) ); ?>');"><?php esc_html_e( 'Delete', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $member['name'] ); ?></span></a> |
									<a href="<?php echo esc_url( self::action_url( 'delete_member', $member['id'], array( 'do_not_record' => '1' ) ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this person and their details, and do not record them again? Their ECF rating code and name are kept only as a one-way fingerprint, so imports and tournaments will not create a record for them. Anything tied to the club\'s accounts stays, without their details.', 'chess-army-knife' ) ); ?>');"><?php esc_html_e( 'Delete and do not record again', 'chess-army-knife' ); ?><span class="screen-reader-text"> <?php echo esc_html( $member['name'] ); ?></span></a>
								</div>
							</td>
							<td><?php echo esc_html( $member['type_name'] ); ?></td>
							<td><?php echo isset( $squads[ $member['id'] ] ) ? esc_html( implode( ', ', $squads[ $member['id'] ] ) ) : '&mdash;'; ?></td>
							<td><?php echo esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ); ?></td>
							<td><?php echo '' === $member['expiry_date'] ? '&mdash;' : esc_html( mysql2date( get_option( 'date_format' ), $member['expiry_date'] ) ); ?></td>
							<td>
								<?php echo esc_html( Chess_Army_Knife_Membership_Store::contact_email( $member ) ); ?>
								<?php if ( '' !== $member['phone'] . $member['guardian_phone'] ) : ?>
									<br /><?php echo esc_html( '' !== $member['phone'] ? $member['phone'] : $member['guardian_phone'] ); ?>
								<?php endif; ?>
								<?php if ( '' !== $member['guardian_name'] ) : ?>
									<br />
									<?php
									/* translators: %s: name of a junior's parent or guardian */
									echo esc_html( sprintf( __( 'Parent or guardian: %s', 'chess-army-knife' ), $member['guardian_name'] ) );
									?>
								<?php endif; ?>
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
							<td>
								<?php
								$agreed = array();
								if ( '' !== $member['newsletter_consent_at'] ) {
									$agreed[] = __( 'Newsletter', 'chess-army-knife' );
								}
								if ( '' !== $member['whatsapp_consent_at'] ) {
									$agreed[] = __( 'WhatsApp', 'chess-army-knife' );
								}
								echo $agreed ? esc_html( implode( ', ', $agreed ) ) : '&mdash;';
								?>
							</td>
							<td>
								<?php
								if ( '' === $member['ecf_code'] ) {
									echo '&mdash;';
								} elseif ( '' === $member['ecf_checked_at'] ) {
									esc_html_e( 'Not checked yet', 'chess-army-knife' );
								} else {
									echo esc_html( null === $member['ecf_rating'] ? __( 'No rating', 'chess-army-knife' ) : (string) $member['ecf_rating'] );
									/* translators: %s: how long ago, for example "2 hours" */
									echo '<br /><span class="description">' . esc_html( sprintf( __( 'checked %s ago', 'chess-army-knife' ), human_time_diff( strtotime( $member['ecf_checked_at'] . ' UTC' ) ) ) ) . '</span>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</form>
		</div>
		<?php
	}

	/**
	 * A member's history: when they first joined, how long they have been a
	 * member, and each period of membership with the gaps between them.
	 *
	 * @param array $member Member row.
	 */
	protected static function render_history( array $member ) {
		$today   = current_time( 'Y-m-d' );
		$periods = Chess_Army_Knife_Member_History::periods( $member );
		$summary = Chess_Army_Knife_Member_History::summarise( $periods, $today );
		$format  = get_option( 'date_format' );
		?>
		<h2 id="membership-history"><?php esc_html_e( 'Membership history', 'chess-army-knife' ); ?></h2>
		<?php if ( ! $periods ) : ?>
			<p class="description"><?php esc_html_e( 'No membership has been recorded yet: history starts when an application is approved.', 'chess-army-knife' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<p>
			<?php
			/* translators: %s: date the person first became a member */
			echo esc_html( sprintf( __( 'First became a member on %s.', 'chess-army-knife' ), mysql2date( $format, $summary['first_joined'] ) ) );
			?>
			<?php if ( null !== $summary['continuous_since'] ) : ?>
				<?php
				/* translators: 1: date the current unbroken membership began, 2: how long ago */
				echo esc_html( sprintf( __( 'Continuously a member since %1$s (%2$s).', 'chess-army-knife' ), mysql2date( $format, $summary['continuous_since'] ), Chess_Army_Knife_Member_History::duration_label( $summary['continuous_since'], $today ) ) );
				?>
			<?php else : ?>
				<?php esc_html_e( 'Not a member at the moment.', 'chess-army-knife' ); ?>
			<?php endif; ?>
			<?php
			/* translators: %s: total length of time they have been a member, for example "3 years, 2 months" */
			echo esc_html( sprintf( __( 'In all, %s as a member.', 'chess-army-knife' ), Chess_Army_Knife_Member_History::duration_label( $summary['runs'][0]['from'], gmdate( 'Y-m-d', strtotime( $summary['runs'][0]['from'] . ' UTC' ) + max( 0, $summary['days'] - 1 ) * DAY_IN_SECONDS ) ) ) );
			?>
		</p>
		<?php if ( $summary['lapses'] ) : ?>
			<p><strong><?php esc_html_e( 'Lapses', 'chess-army-knife' ); ?></strong></p>
			<ul>
				<?php foreach ( $summary['lapses'] as $lapse ) : ?>
					<li>
						<?php
						/* translators: 1: first day without membership, 2: last day without membership, 3: length of the gap */
						echo esc_html( sprintf( __( '%1$s to %2$s (%3$s)', 'chess-army-knife' ), mysql2date( $format, $lapse['from'] ), mysql2date( $format, $lapse['to'] ), Chess_Army_Knife_Member_History::duration_label( $lapse['from'], $lapse['to'] ) ) );
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<table class="wp-list-table widefat fixed striped">
<caption class="screen-reader-text"><?php esc_html_e( 'Membership history', 'chess-army-knife' ); ?></caption>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Membership', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Started', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Last day', 'chess-army-knife' ); ?></th>
					<th><?php esc_html_e( 'Paid', 'chess-army-knife' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_reverse( $periods ) as $period ) : ?>
					<tr>
						<td><?php echo '' === $period['type_name'] ? '&mdash;' : esc_html( $period['type_name'] ); ?></td>
						<td><?php echo esc_html( mysql2date( $format, $period['start_date'] ) ); ?></td>
						<td><?php echo '' === $period['expiry_date'] ? esc_html__( 'No end date', 'chess-army-knife' ) : esc_html( mysql2date( $format, $period['expiry_date'] ) ); ?></td>
						<td><?php echo '' === $period['paid_on'] ? esc_html__( 'Not recorded', 'chess-army-knife' ) : esc_html( mysql2date( $format, $period['paid_on'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The form to add a member, or edit one.
	 *
	 * @param array|null $member The member to edit, or null to add one.
	 */
	protected static function render_form( $member ) {
		$editing = null !== $member;
		$member  = $editing ? $member : array(
			'manual_rating' => null,
		) + array_fill_keys( array( 'name', 'email', 'phone', 'date_of_birth', 'guardian_name', 'ecf_code', 'payment_method', 'paid_on', 'notes', 'expiry_date', 'consent_at', 'guardian_email', 'guardian_phone', 'newsletter_consent_at', 'whatsapp_consent_at' ), '' ) + array(
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
						<th scope="row"><label for="guardian_email"><?php esc_html_e( 'Parent or guardian email', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="email" id="guardian_email" name="guardian_email" class="regular-text" value="<?php echo esc_attr( $member['guardian_email'] ); ?>" />
							<p class="description"><?php esc_html_e( 'For juniors: write to the parent or guardian rather than the junior, unless they have said otherwise.', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="guardian_phone"><?php esc_html_e( 'Parent or guardian phone', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="guardian_phone" name="guardian_phone" class="regular-text" value="<?php echo esc_attr( $member['guardian_phone'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ecf_code"><?php esc_html_e( 'ECF rating code', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="ecf_code" name="ecf_code" class="regular-text" value="<?php echo esc_attr( $member['ecf_code'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="manual_rating"><?php esc_html_e( 'Manual rating', 'chess-army-knife' ); ?></label></th>
						<td>
							<input type="number" id="manual_rating" name="manual_rating" min="<?php echo esc_attr( Chess_Army_Knife_Membership_Store::MIN_MANUAL_RATING ); ?>" max="<?php echo esc_attr( Chess_Army_Knife_Membership_Store::MAX_MANUAL_RATING ); ?>" value="<?php echo esc_attr( null === $member['manual_rating'] ? '' : $member['manual_rating'] ); ?>" />
							<p class="description">
								<?php
								/* translators: %d: lowest manual rating */
								echo esc_html( sprintf( __( 'Only for someone without an ECF code: used to seed them in tournaments. %d or higher.', 'chess-army-knife' ), Chess_Army_Knife_Membership_Store::MIN_MANUAL_RATING ) );
								?>
							</p>
						</td>
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
							<p class="description"><?php esc_html_e( 'Use "Not a member" for people the club holds details for who have not joined, such as tournament guests. They can be chosen as tournament players and tagged in photos, but are left out of the member lists and counts.', 'chess-army-knife' ); ?></p>
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
						<th scope="row"><?php esc_html_e( 'Optional extras', 'chess-army-knife' ); ?></th>
						<td>
							<label style="display:block">
								<input type="checkbox" name="newsletter" value="1" <?php checked( '' !== $member['newsletter_consent_at'] ); ?> />
								<?php esc_html_e( 'Has agreed to receive the club newsletter', 'chess-army-knife' ); ?>
							</label>
							<label style="display:block">
								<input type="checkbox" name="whatsapp" value="1" <?php checked( '' !== $member['whatsapp_consent_at'] ); ?> />
								<?php esc_html_e( 'Has agreed to be added to the WhatsApp groups of the teams they are in (their phone number is visible to the group)', 'chess-army-knife' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Tick only if the member, or for a junior their parent or guardian, has said yes, in person or in writing. Untick to record that they have withdrawn (members can also do this themselves on the Manage My Data page).', 'chess-army-knife' ); ?></p>
						</td>
					</tr>
					<?php $club_teams = Chess_Army_Knife_Teams::choices(); ?>
					<?php if ( $club_teams ) : ?>
						<?php $member_squads = $editing ? Chess_Army_Knife_Teams::squad_team_ids_of_person( $member['id'] ) : array(); ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Teams', 'chess-army-knife' ); ?></th>
							<td>
								<input type="hidden" name="squad_teams_shown" value="1" />
								<?php foreach ( $club_teams as $team_id => $team_name ) : ?>
									<label style="display:block">
										<input type="checkbox" name="squad_teams[]" value="<?php echo esc_attr( $team_id ); ?>" <?php checked( in_array( $team_id, $member_squads, true ) ); ?> />
										<?php echo esc_html( $team_name ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'The squads this person is in: any number, or none. Who captains a team is set on the team itself.', 'chess-army-knife' ); ?></p>
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
				<?php if ( $editing ) : ?>
					<?php $photo_ids = Chess_Army_Knife_Member_Photos::photo_ids( $member['id'] ); ?>
					<h2><?php esc_html_e( 'Photos', 'chess-army-knife' ); ?></h2>
					<?php if ( empty( $photo_ids ) ) : ?>
						<p class="description"><?php esc_html_e( 'Not tagged in any photos. Tag members in a photo\'s details in the Media Library.', 'chess-army-knife' ); ?></p>
					<?php else : ?>
						<p>
							<?php
							/* translators: %d: number of photos */
							echo esc_html( sprintf( _n( 'Tagged in %d photo. Photos may show other people, so check before sharing or deleting.', 'Tagged in %d photos. Photos may show other people, so check before sharing or deleting.', count( $photo_ids ), 'chess-army-knife' ), count( $photo_ids ) ) );
							?>
							<a href="<?php echo esc_url( Chess_Army_Knife_Member_Photos::library_url( $member['id'] ) ); ?>"><?php esc_html_e( 'View in the Media Library', 'chess-army-knife' ); ?></a>
						</p>
						<p class="cak-member-photos">
							<?php foreach ( $photo_ids as $photo_id ) : ?>
								<?php $photo_link = get_edit_post_link( $photo_id ); ?>
								<a href="<?php echo esc_url( $photo_link ? $photo_link : '' ); ?>" aria-label="<?php echo esc_attr( get_the_title( $photo_id ) ); ?>" style="display:inline-block;margin:0 8px 8px 0">
									<?php
									$thumbnail = wp_get_attachment_image( $photo_id, array( 100, 100 ) );
									echo $thumbnail ? wp_kses_post( $thumbnail ) : esc_html( get_the_title( $photo_id ) );
									?>
								</a>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $editing ) : ?>
					<?php self::render_history( $member ); ?>
				<?php endif; ?>
				<?php submit_button( $editing ? __( 'Save member', 'chess-army-knife' ) : __( 'Add member', 'chess-army-knife' ) ); ?>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Back to members', 'chess-army-knife' ); ?></a>
			</form>
		</div>
		<?php
	}
}

Chess_Army_Knife_Members_Page::init();
