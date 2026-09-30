<?php
/**
 * The Memberships > Dashboard screen: a summary of the club's people.
 *
 * It shows totals only (see Chess_Army_Knife_Member_Stats), never names, and
 * is for people with the membership permission like the rest of the section.
 * Bars use one hue, darker meaning more, and every bar has its number beside
 * it, so nothing depends on colour or on hovering.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Dashboard_Page {

	const SLUG = 'chess-army-knife-dashboard';

	/**
	 * Hook up the screen.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 6 );
	}

	/**
	 * Add the screen under Memberships, after Members.
	 */
	public static function add_menu() {
		add_submenu_page(
			Chess_Army_Knife_Memberships::MENU_SLUG,
			__( 'Dashboard', 'chess-army-knife' ),
			__( 'Dashboard', 'chess-army-knife' ),
			Chess_Army_Knife_Memberships::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * A percentage of a total, rounded; 0 if the total is 0.
	 *
	 * @param int $part  Part.
	 * @param int $total Total.
	 * @return int
	 */
	public static function percent( $part, $total ) {
		return $total > 0 ? (int) round( 100 * $part / $total ) : 0;
	}

	/**
	 * A tile: a label over a number.
	 *
	 * @param string $label Label.
	 * @param int    $value Number.
	 * @param string $hint  Optional small text under the number.
	 * @param bool   $hero  Make it the large, leading figure.
	 */
	protected static function tile( $label, $value, $hint = '', $hero = false ) {
		printf(
			'<div class="cak-tile%1$s"><span class="cak-tile-label">%2$s</span><strong class="cak-tile-value">%3$s</strong>%4$s</div>',
			$hero ? ' cak-tile-hero' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $value ) ),
			'' !== $hint ? '<span class="cak-tile-hint">' . esc_html( $hint ) . '</span>' : ''
		);
	}

	/**
	 * Horizontal bars, one per row, each with its number.
	 *
	 * @param array[] $rows Each { label, count }.
	 */
	protected static function bars( array $rows ) {
		$max = 0;
		foreach ( $rows as $row ) {
			$max = max( $max, $row[1] );
		}
		echo '<ul class="cak-bars">';
		foreach ( $rows as $row ) {
			printf(
				'<li><span class="cak-bar-label">%1$s</span><span class="cak-bar-track"><span class="cak-bar-fill" style="width:%2$d%%" title="%3$s"></span></span><span class="cak-bar-count">%4$s</span></li>',
				esc_html( $row[0] ),
				$max > 0 ? (int) ceil( 100 * $row[1] / $max ) : 0, // A small count still shows; zero shows nothing.
				esc_attr( $row[0] . ': ' . $row[1] ),
				esc_html( number_format_i18n( $row[1] ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * A meter: how many of the current members, as a share.
	 *
	 * @param string $label Label.
	 * @param int    $count Count.
	 * @param int    $total Current members.
	 */
	protected static function meter( $label, $count, $total ) {
		$percent = self::percent( $count, $total );
		printf(
			'<div class="cak-meter"><div class="cak-meter-head"><span>%1$s</span><strong>%2$s</strong></div><div class="cak-bar-track"><span class="cak-bar-fill" style="width:%3$d%%"></span></div></div>',
			esc_html( $label ),
			/* translators: 1: percentage, 2: number of members, 3: number of current members */
			esc_html( sprintf( __( '%1$d%% (%2$d of %3$d)', 'chess-army-knife' ), $percent, $count, $total ) ),
			(int) $percent
		);
	}

	/**
	 * Render the screen.
	 */
	public static function render_page() {
		if ( ! Chess_Army_Knife_Memberships::user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ), '', array( 'response' => 403 ) );
		}

		$stats = Chess_Army_Knife_Member_Stats::get();
		$total = (int) $stats['current'];
		$ages  = (int) $stats['juniors'] + (int) $stats['adults'];
		?>
		<style>
			.cak-dash { --cak-surface: #fff; --cak-border: #dcdcde; --cak-ink: #1d2327; --cak-muted: #50575e; --cak-track: #e8eef7; --cak-bar: #2a78d6; --cak-bar-2: #eb6834; max-width: 1100px; }
			.cak-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; margin: 16px 0; }
			.cak-tile, .cak-card { background: var(--cak-surface); border: 1px solid var(--cak-border); border-radius: 4px; padding: 12px 14px; color: var(--cak-ink); }
			.cak-tile { display: flex; flex-direction: column; gap: 2px; }
			.cak-tile-label { color: var(--cak-muted); }
			.cak-tile-value { font-size: 26px; line-height: 1.2; }
			.cak-tile-hero { grid-column: span 2; }
			.cak-tile-hero .cak-tile-value { font-size: 48px; }
			.cak-tile-hint { color: var(--cak-muted); font-size: 12px; }
			.cak-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 12px; }
			.cak-card h2 { margin: 0 0 10px; font-size: 14px; }
			.cak-bars { margin: 0; padding: 0; list-style: none; }
			.cak-bars li { display: grid; grid-template-columns: 110px 1fr 36px; gap: 8px; align-items: center; margin: 6px 0; }
			.cak-bar-count { text-align: right; font-variant-numeric: tabular-nums; }
			.cak-bar-track { display: block; height: 10px; background: var(--cak-track); border-radius: 0 4px 4px 0; overflow: hidden; }
			.cak-bar-fill { display: block; height: 100%; background: var(--cak-bar); border-radius: 0 4px 4px 0; }
			.cak-meter { margin: 10px 0; }
			.cak-meter-head { display: flex; justify-content: space-between; margin-bottom: 4px; }
			.cak-split { display: flex; gap: 2px; height: 14px; margin: 8px 0; }
			.cak-split span { display: block; min-width: 2px; }
			.cak-split .a { background: var(--cak-bar); border-radius: 4px 0 0 4px; }
			.cak-split .b { background: var(--cak-bar-2); border-radius: 0 4px 4px 0; }
			.cak-legend { display: flex; gap: 16px; flex-wrap: wrap; margin: 0; padding: 0; list-style: none; }
			.cak-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; }
			.cak-note { color: var(--cak-muted); }
			@media (max-width: 600px) { .cak-tile-hero { grid-column: span 1; } }
		</style>
		<div class="wrap cak-dash">
			<h1><?php esc_html_e( 'Membership dashboard', 'chess-army-knife' ); ?></h1>

			<div class="cak-tiles">
				<?php
				self::tile( __( 'Current members', 'chess-army-knife' ), $stats['current'], '', true );
				self::tile( __( 'Pending applications', 'chess-army-knife' ), $stats['pending'] );
				self::tile( __( 'Expiring in 30 days', 'chess-army-knife' ), $stats['expiring_30_days'] );
				self::tile( __( 'Renewal reminders due', 'chess-army-knife' ), $stats['renewal_due'] );
				self::tile( __( 'Lapsed', 'chess-army-knife' ), $stats['lapsed'], __( 'active records past their last day', 'chess-army-knife' ) );
				self::tile( __( 'Joined this month', 'chess-army-knife' ), $stats['joined_month'] );
				self::tile( __( 'Joined this year', 'chess-army-knife' ), $stats['joined_year'] );
				self::tile( __( 'Guests (not members)', 'chess-army-knife' ), $stats['guests'] );
				?>
			</div>

			<div class="cak-cards">
				<div class="cak-card">
					<h2><?php esc_html_e( 'Current members by membership type', 'chess-army-knife' ); ?></h2>
					<?php $stats['by_type'] ? self::bars( $stats['by_type'] ) : print( '<p class="cak-note">' . esc_html__( 'No current members yet.', 'chess-army-knife' ) . '</p>' ); ?>
				</div>

				<div class="cak-card">
					<h2><?php esc_html_e( 'Juniors and adults', 'chess-army-knife' ); ?></h2>
					<?php if ( $ages ) : ?>
						<div class="cak-split" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: juniors, 2: adults */ __( '%1$d juniors, %2$d adults', 'chess-army-knife' ), $stats['juniors'], $stats['adults'] ) ); ?>">
							<span class="a" style="flex: <?php echo (int) $stats['juniors']; ?>"></span>
							<span class="b" style="flex: <?php echo (int) $stats['adults']; ?>"></span>
						</div>
						<ul class="cak-legend">
							<li><i style="background: var(--cak-bar)"></i><?php echo esc_html( sprintf( /* translators: %d: number of juniors */ __( 'Juniors (under 18): %d', 'chess-army-knife' ), $stats['juniors'] ) ); ?></li>
							<li><i style="background: var(--cak-bar-2)"></i><?php echo esc_html( sprintf( /* translators: %d: number of adults */ __( 'Adults: %d', 'chess-army-knife' ), $stats['adults'] ) ); ?></li>
						</ul>
						<p class="cak-note"><?php esc_html_e( 'Age is worked out from the date of birth; members with none are counted as adults.', 'chess-army-knife' ); ?></p>
					<?php else : ?>
						<p class="cak-note"><?php esc_html_e( 'No current members yet.', 'chess-army-knife' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="cak-card">
					<h2><?php esc_html_e( 'Choices and ECF codes', 'chess-army-knife' ); ?></h2>
					<?php
					self::meter( __( 'Agreed to the newsletter', 'chess-army-knife' ), $stats['newsletter'], $total );
					self::meter( __( 'Agreed to WhatsApp groups', 'chess-army-knife' ), $stats['whatsapp'], $total );
					self::meter( __( 'Have an ECF rating code', 'chess-army-knife' ), $stats['with_ecf_code'], $total );
					?>
				</div>

				<div class="cak-card">
					<h2><?php esc_html_e( 'ECF ratings of current members', 'chess-army-knife' ); ?></h2>
					<?php self::bars( $stats['by_rating'] ); ?>
					<p class="cak-note">
						<?php
						/* translators: %d: number of members without a stored rating */
						echo esc_html( sprintf( __( 'Not shown: %d without a stored ECF rating (no code, or not fetched yet). Default rating list only.', 'chess-army-knife' ), $stats['unrated'] ) );
						?>
					</p>
				</div>
			</div>

			<p class="cak-note">
				<?php
				/* translators: %s: how long ago the figures were worked out */
				echo esc_html( sprintf( __( 'Figures worked out %s ago; they refresh when a record changes or within the hour.', 'chess-army-knife' ), human_time_diff( $stats['as_of'] ) ) );
				?>
			</p>
		</div>
		<?php
	}
}

Chess_Army_Knife_Dashboard_Page::init();
