<?php
/**
 * Club memberships: the membership types the club advertises (a post type
 * with a price and length) and the helpers around prices, expiry dates,
 * payment details and the permission needed to manage members.
 *
 * Each membership type (junior, adult, senior, ...) is a non-public post of
 * type chess_army_membership. Its description, price (in pence) and length
 * (in months, 0 for no expiry) live in post meta. The people who hold or
 * have applied for a membership live in their own table, see
 * Chess_Army_Knife_Membership_Store.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Memberships {

	const POST_TYPE  = 'chess_army_mem_type';
	const MENU_SLUG  = 'chess-army-memberships';
	const CAPABILITY = 'chess_army_manage_memberships';

	const META_DESCRIPTION = '_chess_army_membership_description';
	const META_PRICE       = '_chess_army_membership_price';
	const META_MONTHS      = '_chess_army_membership_months';

	/**
	 * Hook up registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the membership type post type.
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Membership Types', 'chess-army-knife' ),
					'singular_name'      => __( 'Membership Type', 'chess-army-knife' ),
					'add_new'            => __( 'Add Type', 'chess-army-knife' ),
					'add_new_item'       => __( 'Add New Membership Type', 'chess-army-knife' ),
					'edit_item'          => __( 'Edit Membership Type', 'chess-army-knife' ),
					'search_items'       => __( 'Search Membership Types', 'chess-army-knife' ),
					'not_found'          => __( 'No membership types found.', 'chess-army-knife' ),
					'not_found_in_trash' => __( 'No membership types found in the Trash.', 'chess-army-knife' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => self::MENU_SLUG,
				'show_in_rest' => false, // Classic editing screen: the details are plain fields.
				'supports'     => array( 'title', 'page-attributes' ), // Page order sets the order they are advertised in.
				'capabilities' => self::post_capabilities(),
			)
		);
	}

	/**
	 * Every post capability of the type, mapped to the one membership permission.
	 *
	 * @return array
	 */
	protected static function post_capabilities() {
		$names = array(
			'edit_post',
			'read_post',
			'delete_post',
			'edit_posts',
			'edit_others_posts',
			'delete_posts',
			'publish_posts',
			'read_private_posts',
			'delete_private_posts',
			'delete_published_posts',
			'delete_others_posts',
			'edit_private_posts',
			'edit_published_posts',
			'create_posts',
		);

		return array_fill_keys( $names, self::CAPABILITY );
	}

	/**
	 * Whether the current user may see and manage members and applications.
	 * The permission is not part of any role: it is given to individual users
	 * (see Chess_Army_Knife_Memberships_Admin), so not every administrator has it.
	 *
	 * @return bool
	 */
	public static function user_can_manage() {
		return current_user_can( self::CAPABILITY );
	}

	/* -------------------------------------------------------------
	 * Prices and lengths
	 * ------------------------------------------------------------- */

	/**
	 * Turn a typed price such as "25", "25.50" or "£25" into pence.
	 *
	 * @param string $text Typed price.
	 * @return int|null Null if it is not a valid price.
	 */
	public static function parse_price( $text ) {
		$text = trim( str_replace( array( self::currency_symbol(), ',', ' ' ), '', (string) $text ) );

		if ( ! preg_match( '/^(\d{1,6})(?:\.(\d{1,2}))?$/', $text, $parts ) ) {
			return null;
		}

		$pence = isset( $parts[2] ) ? (int) str_pad( $parts[2], 2, '0' ) : 0;
		return (int) $parts[1] * 100 + $pence;
	}

	/**
	 * The currency symbol shown before prices.
	 *
	 * @return string
	 */
	public static function currency_symbol() {
		/**
		 * Filter the currency symbol used for membership prices.
		 *
		 * @param string $symbol Symbol, £ by default.
		 */
		return (string) apply_filters( 'Chess_Army_Knife_membership_currency_symbol', '£' );
	}

	/**
	 * A price in pence as text: "£25", "£12.50" or "Free".
	 *
	 * @param int $pence Price in pence.
	 * @return string
	 */
	public static function format_price( $pence ) {
		$pence = (int) $pence;

		if ( $pence <= 0 ) {
			return __( 'Free', 'chess-army-knife' );
		}

		$amount = 0 === $pence % 100 ? (string) ( $pence / 100 ) : number_format( $pence / 100, 2, '.', '' );
		return self::currency_symbol() . $amount;
	}

	/**
	 * The price as a plain number for an edit field: "25" or "12.50".
	 *
	 * @param int $pence Price in pence.
	 * @return string
	 */
	public static function price_field_value( $pence ) {
		$pence = (int) $pence;
		return 0 === $pence % 100 ? (string) ( $pence / 100 ) : number_format( $pence / 100, 2, '.', '' );
	}

	/**
	 * How long a membership lasts, as text: "per year", "per month", "one-off".
	 *
	 * @param int $months Length in months, 0 for no expiry.
	 * @return string
	 */
	public static function period_label( $months ) {
		$months = (int) $months;

		if ( $months <= 0 ) {
			return __( 'one-off', 'chess-army-knife' );
		}
		if ( 12 === $months ) {
			return __( 'per year', 'chess-army-knife' );
		}
		if ( 1 === $months ) {
			return __( 'per month', 'chess-army-knife' );
		}

		/* translators: %d: number of months */
		return sprintf( _n( 'for %d month', 'for %d months', $months, 'chess-army-knife' ), $months );
	}

	/**
	 * The last day of a membership that starts on a date. A 12 month
	 * membership starting on 1 September runs to 31 August.
	 *
	 * @param string $start  Start date, YYYY-MM-DD.
	 * @param int    $months Length in months, 0 for no expiry.
	 * @return string YYYY-MM-DD, or '' for no expiry or an invalid start date.
	 */
	public static function expiry_from( $start, $months ) {
		$months = (int) $months;

		if ( $months <= 0 || ! self::is_valid_date( $start ) ) {
			return '';
		}

		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $start ) );

		$index = $year * 12 + ( $month - 1 ) + $months;
		$year  = intdiv( $index, 12 );
		$month = $index % 12 + 1;
		$day   = min( $day, (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) ) ); // 31 January plus one month is the end of February.

		return gmdate( 'Y-m-d', gmmktime( 0, 0, 0, $month, $day - 1, $year ) );
	}

	/**
	 * Whether text is a real date in YYYY-MM-DD form.
	 *
	 * @param string $date Text to check.
	 * @return bool
	 */
	public static function is_valid_date( $date ) {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/**
	 * Whether someone born on a date is under 18 on another date.
	 *
	 * @param string $date_of_birth Date of birth, YYYY-MM-DD.
	 * @param string $on            The date to check, YYYY-MM-DD.
	 * @return bool False for an invalid date of birth.
	 */
	public static function is_under_18( $date_of_birth, $on ) {
		if ( ! self::is_valid_date( $date_of_birth ) ) {
			return false;
		}

		// Dates in this form compare correctly as text, so 29 February needs no special case.
		$eighteenth = ( (int) substr( $date_of_birth, 0, 4 ) + 18 ) . substr( $date_of_birth, 4 );
		return (string) $on < $eighteenth;
	}

	/* -------------------------------------------------------------
	 * Membership types
	 * ------------------------------------------------------------- */

	/**
	 * Data for one membership type post.
	 *
	 * @param WP_Post $post Membership type.
	 * @return array {
	 *     @type int    $id
	 *     @type string $name
	 *     @type string $description
	 *     @type int    $price        In pence.
	 *     @type string $price_label  For example "£25".
	 *     @type int    $months       Length in months, 0 for no expiry.
	 *     @type string $period_label For example "per year".
	 *     @type string $status       Post status.
	 * }
	 */
	public static function type_data( $post ) {
		$id     = (int) $post->ID;
		$price  = (int) get_post_meta( $id, self::META_PRICE, true );
		$months = (int) get_post_meta( $id, self::META_MONTHS, true );

		return array(
			'id'           => $id,
			'name'         => get_the_title( $post ),
			'description'  => (string) get_post_meta( $id, self::META_DESCRIPTION, true ),
			'price'        => $price,
			'price_label'  => self::format_price( $price ),
			'months'       => $months,
			'period_label' => self::period_label( $months ),
			'status'       => $post->post_status,
		);
	}

	/**
	 * The membership types, in the order set by their page order.
	 *
	 * @param bool $published_only True for the types on offer, false to include drafts as well.
	 * @return array[] See type_data().
	 */
	public static function types( $published_only = true ) {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => $published_only ? 'publish' : array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);

		return array_map( array( __CLASS__, 'type_data' ), $posts );
	}

	/**
	 * One membership type.
	 *
	 * @param int $id Membership type id.
	 * @return array|null See type_data(); null if there is no such type.
	 */
	public static function get_type( $id ) {
		$post = get_post( (int) $id );
		return ( $post && self::POST_TYPE === $post->post_type ) ? self::type_data( $post ) : null;
	}

	/* -------------------------------------------------------------
	 * Teams
	 * ------------------------------------------------------------- */

	/**
	 * The club's team names, for choosing which teams' WhatsApp groups to join.
	 * They come from the Club Teams page; a team that plays in several seasons or
	 * divisions is listed once.
	 *
	 * @return string[]
	 */
	public static function team_names() {
		$names = array();
		foreach ( Chess_Army_Knife_Settings::get_club_teams() as $team ) {
			$name = trim( (string) $team['team'] );
			if ( '' !== $name ) {
				$names[ $name ] = $name;
			}
		}
		natcasesort( $names );
		return array_values( $names );
	}

	/* -------------------------------------------------------------
	 * Payment
	 * ------------------------------------------------------------- */

	/**
	 * The club's instructions for paying, as entered on the Settings page.
	 *
	 * @return string Plain text.
	 */
	public static function payment_instructions() {
		$options = Chess_Army_Knife_Settings::get_options();
		return isset( $options['membership_payment_info'] ) ? trim( (string) $options['membership_payment_info'] ) : '';
	}

	/**
	 * How members can have paid, for recording a payment.
	 *
	 * @return string[] Label for each method, keyed by method.
	 */
	public static function payment_methods() {
		$methods = array(
			'bank_transfer' => __( 'Bank transfer', 'chess-army-knife' ),
			'cash'          => __( 'Cash', 'chess-army-knife' ),
			'cheque'        => __( 'Cheque', 'chess-army-knife' ),
			'other'         => __( 'Other', 'chess-army-knife' ),
		);

		/**
		 * Filter the ways a membership payment can be recorded.
		 *
		 * @param string[] $methods Label for each method, keyed by method.
		 */
		return (array) apply_filters( 'Chess_Army_Knife_membership_payment_methods', $methods );
	}

	/**
	 * The reference a member should quote when paying, so the treasurer can
	 * match a bank transfer to a member.
	 *
	 * @param int $member_id Member id.
	 * @return string
	 */
	public static function payment_reference( $member_id ) {
		return 'MEM-' . (int) $member_id;
	}
}

Chess_Army_Knife_Memberships::init();
