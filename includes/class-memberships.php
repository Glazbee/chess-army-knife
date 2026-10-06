<?php
/**
 * Club memberships: the membership types the club advertises (a post type
 * with a price) and the helpers around prices, payment details and the permission needed to manage members.
 *
 * Each membership type (junior, adult, senior, ...) is a non-public post of
 * type chess_army_membership. Its description and price (in pence), for a season,
 * live in post meta. The people who hold or
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

	/** The payment method for a junior's free year: the season counts as paid, for nothing. */
	const FREE_YEAR = 'free_year';

	const META_DESCRIPTION = '_chess_army_membership_description';
	const META_PRICE       = '_chess_army_membership_price';
	const META_JUNIOR      = '_chess_army_membership_junior';

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
				'show_in_menu' => false, // Listed in the plugin's menu (see Chess_Army_Knife_Menu).
				'show_in_rest' => false, // Classic editing screen: the details are plain fields.
				'supports'     => array( 'title', 'page-attributes' ), // Page order sets the order they are advertised in.
				'capabilities' => self::post_capabilities(),
			)
		);
	}

	/**
	 * Every post capability of a type, mapped to one permission.
	 *
	 * @param string $capability The permission; the membership one by default.
	 * @return array
	 */
	public static function post_capabilities( $capability = self::CAPABILITY ) {
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

		return array_fill_keys( $names, $capability );
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
	 *     @type string $period_label Always "per season".
	 *     @type bool   $is_junior    Whether the type is for juniors (under 18).
	 *     @type string $status       Post status.
	 * }
	 */
	public static function type_data( $post ) {
		$id    = (int) $post->ID;
		$price = (int) get_post_meta( $id, self::META_PRICE, true );

		return array(
			'id'           => $id,
			'name'         => get_the_title( $post ),
			'description'  => (string) get_post_meta( $id, self::META_DESCRIPTION, true ),
			'price'        => $price,
			'price_label'  => self::format_price( $price ),
			'period_label' => __( 'per season', 'chess-army-knife' ),
			'is_junior'    => '1' === (string) get_post_meta( $id, self::META_JUNIOR, true ),
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
	 * Payment
	 * ------------------------------------------------------------- */

	/**
	 * The club's instructions for paying, as entered on the Settings page. {reference} is filled in with the
	 * member's payment reference when it is for a particular member, and says "your payment reference" when
	 * it is not (the text shown beside the advertised memberships); it is left out if the club uses no references.
	 *
	 * @param int        $member_id Member the instructions are for, or 0 for no one in particular.
	 * @param array|null $member    The member's row, if there is one to hand.
	 * @return string Plain text.
	 */
	public static function payment_instructions( $member_id = 0, $member = null ) {
		$options = Chess_Army_Knife_Settings::get_options();
		$text    = isset( $options['membership_payment_info'] ) ? trim( (string) $options['membership_payment_info'] ) : '';
		if ( false === strpos( $text, '{reference}' ) ) {
			return $text;
		}

		if ( ! self::use_references() ) {
			$reference = '';
		} elseif ( $member_id > 0 ) {
			$reference = self::payment_reference( $member_id, $member );
		} else {
			$reference = __( 'your payment reference', 'chess-army-knife' );
		}
		return str_replace( '{reference}', $reference, $text );
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
			self::FREE_YEAR => __( 'Free first year', 'chess-army-knife' ),
		);

		/**
		 * Filter the ways a membership payment can be recorded.
		 *
		 * @param string[] $methods Label for each method, keyed by method.
		 */
		return (array) apply_filters( 'Chess_Army_Knife_membership_payment_methods', $methods );
	}

	/**
	 * Whether the club gives members a payment reference at all. A club that takes cash may not.
	 *
	 * @return bool
	 */
	public static function use_references() {
		return ! empty( Chess_Army_Knife_Settings::get_options()['membership_use_references'] );
	}

	/**
	 * The start of a reference the club's number makes for a member, from Settings.
	 *
	 * @return string
	 */
	public static function reference_prefix() {
		return (string) Chess_Army_Knife_Settings::get_options()['membership_reference_prefix'];
	}

	/**
	 * The reference a member should quote when paying by bank transfer, so the treasurer can match a payment to
	 * a member: one chosen for them, else the club's prefix and their number. Empty if the club does not use
	 * references.
	 *
	 * @param int        $member_id Member id.
	 * @param array|null $member    The member's row, if there is one to hand (it may hold a chosen reference).
	 * @return string
	 */
	public static function payment_reference( $member_id, $member = null ) {
		if ( ! self::use_references() ) {
			return '';
		}
		if ( is_array( $member ) && ! empty( $member['payment_reference'] ) ) {
			return (string) $member['payment_reference'];
		}
		return self::reference_prefix() . (int) $member_id;
	}

	/**
	 * The member a generated reference belongs to: "MEM-42" is member 42. A reference that is not of the
	 * club's prefix and a number gives 0.
	 *
	 * @param string $reference Text that may be a reference.
	 * @return int
	 */
	public static function member_id_of_reference( $reference ) {
		$reference = trim( (string) $reference );
		$prefix    = self::reference_prefix();
		if ( '' !== $prefix && 0 !== stripos( $reference, $prefix ) ) {
			return 0;
		}
		$rest = substr( $reference, strlen( $prefix ) );
		return ctype_digit( $rest ) ? (int) $rest : 0;
	}

	/**
	 * What a person is told after applying for membership: the club's own wording from Settings, or else a plain
	 * thank-you (with the payment reference, if the club uses them). {reference} is filled in.
	 *
	 * @param int $member_id The application's id, or 0 if there is none to give a reference for.
	 * @return string Plain text.
	 */
	public static function thanks_text( $member_id ) {
		$text = trim( (string) Chess_Army_Knife_Settings::get_options()['membership_thanks_message'] );
		if ( '' === $text ) {
			$text = __( 'Thank you! Your application has been received and the club will be in touch once it has been reviewed.', 'chess-army-knife' );
			if ( $member_id > 0 && self::use_references() ) {
				$text .= "\n\n" . __( 'If you pay by bank transfer, please use this payment reference: {reference}', 'chess-army-knife' );
			}
		}
		return strtr(
			$text,
			array(
				'{reference}' => $member_id > 0 ? self::payment_reference( $member_id ) : '',
			)
		);
	}
}

Chess_Army_Knife_Memberships::init();
