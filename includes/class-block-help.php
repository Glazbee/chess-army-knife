<?php
/**
 * The block inserter's categories, and the Block Help screen: how to add the
 * plugin's blocks, what each one needs, and where to set that up.
 *
 * A block's category is set in its block.json (one of the slugs below). The
 * help screen reads the registered blocks, so a block cannot be listed in the
 * wrong group; the explanations are kept here, one entry per block, and a test
 * checks that every block has one.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Block_Help {

	const PAGE       = 'chess-army-knife-block-help';
	const NAMESPACE_ = 'chess-army-knife/';

	/**
	 * Hook up the categories.
	 */
	public static function init() {
		add_filter( 'block_categories_all', array( __CLASS__, 'add_categories' ) );
	}

	/**
	 * The groups the plugin's blocks are sorted into, in the order they are listed.
	 *
	 * @return string[] Title by category slug.
	 */
	public static function categories() {
		return array(
			'chess-army-knife-ratings'     => __( 'Chess: Ratings & Players', 'chess-army-knife' ),
			'chess-army-knife-leagues'     => __( 'Chess: Leagues & Teams', 'chess-army-knife' ),
			'chess-army-knife-tournaments' => __( 'Chess: Tournaments', 'chess-army-knife' ),
			'chess-army-knife-events'      => __( 'Chess: Events', 'chess-army-knife' ),
			'chess-army-knife-membership'  => __( 'Chess: Membership', 'chess-army-knife' ),
		);
	}

	/**
	 * Add the groups to the block inserter.
	 *
	 * @param array[] $categories Block categories.
	 * @return array[]
	 */
	public static function add_categories( $categories ) {
		foreach ( self::categories() as $slug => $title ) {
			$categories[] = array(
				'slug'  => $slug,
				'title' => $title,
			);
		}
		return $categories;
	}

	/**
	 * What to tell someone about each block, in the order to list them within a group.
	 *
	 * Each entry is { use, settings, needs, where }: what the block is for, the
	 * options worth knowing about, what must be set up first, and the screens
	 * (keys of Chess_Army_Knife_Menu::areas()) where that is done.
	 *
	 * @return array[] Entry by block name without the plugin's prefix.
	 */
	public static function entries() {
		$ecf_code = __( 'Members need an ECF rating code on their record (Members screen).', 'chess-army-knife' );

		return array(
			'rating-chart'         => array(
				'use'      => __( 'A line chart of how one player\'s rating has moved over their recent rated games, with current, peak, lowest and change figures There is a written summary of the chart, and the ratings can be shown as a table.', 'chess-army-knife' ),
				'settings' => array(
					__( 'Search for the player by name: only people the club holds a record of, with an ECF code, are offered.', 'chess-army-knife' ),
					__( 'Choose the rating list (standard, rapid, blitz or an online list), how many games to draw, the height, and whether to show the figures.', 'chess-army-knife' ),
				),
				'needs'    => array( $ecf_code ),
				'where'    => array( 'members' ),
			),
			'club-results'         => array(
				'use'      => __( 'A feed of the latest rated results of the club\'s current members: win, draw or loss, colour and event. Opponents are not shown.', 'chess-army-knife' ),
				'settings' => array(
					__( 'Limit how many players are checked, games per player, how many days back to look, and how many results to show.', 'chess-army-knife' ),
					__( 'Keep "players to check" modest: each one is a request to the ECF, which limits how much the site may use each day.', 'chess-army-knife' ),
				),
				'needs'    => array( $ecf_code ),
				'where'    => array( 'members' ),
			),
			'biggest-gainers'      => array(
				'use'      => __( 'The current members whose rating has risen the most over a recent period.', 'chess-army-knife' ),
				'settings' => array(
					__( 'Choose how many to list, the look-back period, and the fewest games a player needs to be counted.', 'chess-army-knife' ),
				),
				'needs'    => array( $ecf_code ),
				'where'    => array( 'members' ),
			),
			'featured-player'      => array(
				'use'      => __( 'Spotlight a player with a photo, a short blurb on why they are featured, their ECF rating and links to their chess.com and Lichess profiles.', 'chess-army-knife' ),
				'settings' => array(
					__( 'Choose a player from the club\'s records, or type a display name instead: a player with no ECF code can still be featured.', 'chess-army-knife' ),
					__( 'Pick a photo from the media library, and choose which of rating, club and links to show.', 'chess-army-knife' ),
				),
				'needs'    => array(),
				'where'    => array(),
			),
			'league-table'         => array(
				'use'      => __( 'A league table and the recent and upcoming matches of a league on the ECF League Management System (LMS). Needs the LMS API key from Settings.', 'chess-army-knife' ),
				'settings' => array(
					__( 'Give the LMS organisation ID and the exact event or division name. Put one event per line to show several divisions.', 'chess-army-knife' ),
					__( 'Show the table, the matches or both, and how many matches to list: the next ones coming up, then the latest results. The table is worked out from the results, with one point for a win and half a point for a draw.', 'chess-army-knife' ),
					__( 'Leave Season empty for the current season, or type an earlier one as the LMS names it (for example 2025-2026) to show its final table.', 'chess-army-knife' ),
					__( 'Teams with a league entry on a team of yours are highlighted automatically; "highlight team" is only for extra teams.', 'chess-army-knife' ),
				),
				'needs'    => array(
					__( 'A default organisation ID and event name can be set once under Settings, so the block needs no setting of its own.', 'chess-army-knife' ),
				),
				'where'    => array( 'settings', 'teams' ),
			),
			'team-carousel'        => array(
				'use'      => __( 'Every team with its last result and next fixture, as a list or as a carousel. The list is the default: nothing moves, and it works without scripts.', 'chess-army-knife' ),
				'settings' => array(
					__( 'Teams come from your own teams\' league entries (the default), from every team in one event\'s league table, or from a list you type.', 'chess-army-knife' ),
					__( 'Choose a list (every team at once) or a carousel (one team at a time, with buttons and a pause button), and whether the carousel may move on by itself. A carousel that moves on by itself never starts for visitors whose device asks for less motion, and stops when they use any button.', 'chess-army-knife' ),
				),
				'needs'    => array(
					__( 'For "my club\'s teams", each team needs at least one league entry.', 'chess-army-knife' ),
				),
				'where'    => array( 'teams' ),
			),
			'team-page'            => array(
				'use'      => __( 'One team\'s league position, next fixture and every result, with the players on each board and who won. Handy for a team\'s own page.', 'chess-army-knife' ),
				'settings' => array(
					__( 'Type the team name as the LMS spells it, the organisation ID and the event. With no team typed, your first team (and its league) is used.', 'chess-army-knife' ),
					__( 'Leave Season empty for the current season, or type an earlier one (for example 2025-2026) to show how that season went.', 'chess-army-knife' ),
					__( 'Board-by-board results can be switched off.', 'chess-army-knife' ),
				),
				'needs'    => array(
					__( 'The LMS API key from Settings.', 'chess-army-knife' ),
				),
				'where'    => array( 'settings', 'teams' ),
			),
			'team-profiles'        => array(
				'use'      => __( 'The club\'s teams: name, description, home venue and the leagues they play in. The captain and squad are never shown.', 'chess-army-knife' ),
				'settings' => array(
					__( 'Show every team, or choose one.', 'chess-army-knife' ),
				),
				'needs'    => array(
					__( 'Teams, with a description (the excerpt), venue and league entries.', 'chess-army-knife' ),
				),
				'where'    => array( 'teams' ),
			),
			'tournament-status'    => array(
				'use'      => __( 'Where a tournament stands: its status, format, players, current round, games played and the winner.', 'chess-army-knife' ),
				'settings' => array( __( 'Choose the tournament.', 'chess-army-knife' ) ),
				'needs'    => array( __( 'A tournament, created on the Tournaments screen.', 'chess-army-knife' ) ),
				'where'    => array( 'tournaments' ),
			),
			'tournament-games'     => array(
				'use'      => __( 'The games in a tournament that have no result yet, grouped by round. Administrators also get score selectors and a Save button.', 'chess-army-knife' ),
				'settings' => array( __( 'Choose the tournament.', 'chess-army-knife' ) ),
				'needs'    => array( __( 'A tournament that has started.', 'chess-army-knife' ) ),
				'where'    => array( 'tournaments' ),
			),
			'tournament-standings' => array(
				'use'      => __( 'A cross-table: each player\'s points in every round and their total, in rank order.', 'chess-army-knife' ),
				'settings' => array( __( 'Choose the tournament.', 'chess-army-knife' ) ),
				'needs'    => array( __( 'A tournament, created on the Tournaments screen.', 'chess-army-knife' ) ),
				'where'    => array( 'tournaments' ),
			),
			'tournament-winners'   => array(
				'use'      => __( 'The winners of completed tournaments, most recent first.', 'chess-army-knife' ),
				'settings' => array( __( 'Choose how many to list.', 'chess-army-knife' ) ),
				'needs'    => array( __( 'At least one completed tournament.', 'chess-army-knife' ) ),
				'where'    => array( 'tournaments' ),
			),
			'tournament-players'   => array(
				'use'      => __( 'The players in a tournament with their ECF codes and ratings.', 'chess-army-knife' ),
				'settings' => array( __( 'Choose the tournament.', 'chess-army-knife' ) ),
				'needs'    => array( __( 'A tournament, created on the Tournaments screen.', 'chess-army-knife' ) ),
				'where'    => array( 'tournaments' ),
			),
			'club-event-calendar'  => array(
				'use'      => __( 'Upcoming club events, as a list by date or a month grid.', 'chess-army-knife' ),
				'settings' => array(
					__( 'Show only events with chosen tags or teams, and only home or away fixtures.', 'chess-army-knife' ),
					__( 'Choose what each entry shows, and whether visitors get a link to subscribe from their own calendar.', 'chess-army-knife' ),
				),
				'needs'    => array(
					__( 'Club events, added by hand or brought in from the LMS with Import Events.', 'chess-army-knife' ),
				),
				'where'    => array( 'club_events', 'import_events' ),
			),
			'next-club-event'      => array(
				'use'      => __( 'The next club event, the next three, or today and tomorrow, optionally only those with a chosen tag such as "in-house".', 'chess-army-knife' ),
				'settings' => array(
					__( 'Choose the tags to match, what to show, and the message when nothing is coming up.', 'chess-army-knife' ),
				),
				'needs'    => array( __( 'Club events.', 'chess-army-knife' ) ),
				'where'    => array( 'club_events' ),
			),
			'memberships'          => array(
				'use'      => __( 'The memberships the club offers, with their prices and descriptions, and how to pay.', 'chess-army-knife' ),
				'settings' => array(
					__( 'Choose what to show, and give the address of your application form to add a "Join" link to each one.', 'chess-army-knife' ),
				),
				'needs'    => array(
					__( 'Published membership types, and the payment instructions under Settings.', 'chess-army-knife' ),
				),
				'where'    => array( 'membership_types', 'settings' ),
			),
			'membership-form'      => array(
				'use'      => __( 'A form for people to apply for membership. Applications wait for the club to review them and are never published.', 'chess-army-knife' ),
				'settings' => array(
					__( 'Add your own introduction and the wording people agree to about how their details are kept.', 'chess-army-knife' ),
				),
				'needs'    => array(
					__( 'At least one published membership type. Applications then appear on the Members screen as pending.', 'chess-army-knife' ),
				),
				'where'    => array( 'membership_types', 'members' ),
			),
			'member-portal'        => array(
				'use'      => __( 'Lets members see and correct their own details, choose their emails, change their email address and ask for their data to be deleted. They sign in with a link sent by email: no account needed.', 'chess-army-knife' ),
				'settings' => array( __( 'Put it on a page for members.', 'chess-army-knife' ) ),
				'needs'    => array(),
				'where'    => array(),
			),
			'my-data'              => array(
				'use'      => __( 'Lets a member stop the newsletter or WhatsApp groups, ask for a copy of their data or ask for it to be deleted, with each request confirmed by an emailed link.', 'chess-army-knife' ),
				'settings' => array( __( 'Put it on a page, for example linked from your privacy policy or club data policy.', 'chess-army-knife' ) ),
				'needs'    => array(),
				'where'    => array(),
			),
		);
	}

	/**
	 * The plugin's registered blocks, grouped by category, in the order of categories() and entries().
	 *
	 * @return array[] List of block types by category slug, each { slug, title, entry }.
	 */
	public static function grouped_blocks() {
		$grouped  = array();
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( self::entries() as $slug => $entry ) {
			$block = $registry->get_registered( self::NAMESPACE_ . $slug );
			if ( ! $block ) {
				continue;
			}
			$grouped[ (string) $block->category ][] = array(
				'slug'  => $slug,
				'title' => $block->title,
				'entry' => $entry,
			);
		}
		return $grouped;
	}

	/**
	 * A list of sentences as bullets.
	 *
	 * @param string   $heading Heading for the list.
	 * @param string[] $items   Sentences.
	 */
	protected static function render_list( $heading, array $items ) {
		if ( ! $items ) {
			return;
		}
		?>
		<p><strong><?php echo esc_html( $heading ); ?></strong></p>
		<ul style="list-style:disc;margin-left:1.5em">
			<?php foreach ( $items as $item ) : ?>
				<li><?php echo esc_html( $item ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Render the screen.
	 */
	public static function render_page() {
		$grouped    = self::grouped_blocks();
		$categories = self::categories();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Block Help', 'chess-army-knife' ); ?></h1>
			<p class="description"><?php esc_html_e( 'How to put the club\'s information on your website with the plugin\'s blocks.', 'chess-army-knife' ); ?></p>

			<h2><?php esc_html_e( 'Adding a block', 'chess-army-knife' ); ?></h2>
			<ol style="list-style:decimal;margin-left:1.5em">
				<li><?php esc_html_e( 'Edit the page or post where you want it.', 'chess-army-knife' ); ?></li>
				<li><?php esc_html_e( 'Click the + button and search for the block\'s name, or scroll to one of the "Chess:" groups below.', 'chess-army-knife' ); ?></li>
				<li><?php esc_html_e( 'With the block selected, open the Settings panel on the right (the Block tab) to choose what it shows.', 'chess-army-knife' ); ?></li>
				<li><?php esc_html_e( 'A block that still needs something says so, in the editor and on the page, until it is set up.', 'chess-army-knife' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'Good to know', 'chess-army-knife' ); ?></h2>
			<ul style="list-style:disc;margin-left:1.5em">
				<li><?php esc_html_e( 'The blocks only show people the club holds a record of, and never a captain, squad or contact details.', 'chess-army-knife' ); ?></li>
				<li><?php esc_html_e( 'Information from the ECF and the LMS is kept for a while rather than fetched on every visit, so a change there can take some time to appear. Administrators can clear it under Settings.', 'chess-army-knife' ); ?></li>
				<li><?php esc_html_e( 'Many blocks have a Template choice in their settings. A template is a saved set of options for that kind of block, made once under Templates and reused wherever you like.', 'chess-army-knife' ); ?></li>
			</ul>

			<?php foreach ( $categories as $category => $title ) : ?>
				<?php if ( empty( $grouped[ $category ] ) ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<h2><?php echo esc_html( $title ); ?></h2>
				<?php foreach ( $grouped[ $category ] as $block ) : ?>
					<?php $entry = $block['entry']; ?>
					<details style="background:#fff;border:1px solid #dcdcde;padding:8px 12px;margin:0 0 8px;max-width:900px">
						<summary style="cursor:pointer"><strong><?php echo esc_html( $block['title'] ); ?></strong> &mdash; <?php echo esc_html( $entry['use'] ); ?></summary>
						<?php self::render_list( __( 'Settings', 'chess-army-knife' ), $entry['settings'] ); ?>
						<?php self::render_list( __( 'Set up first', 'chess-army-knife' ), $entry['needs'] ); ?>
						<?php
						$links = array();
						foreach ( $entry['where'] as $key ) {
							$area = Chess_Army_Knife_Menu::area( $key );
							if ( $area ) {
								$links[] = '<a href="' . esc_url( Chess_Army_Knife_Menu::url( $area ) ) . '">' . esc_html( $area['title'] ) . '</a>';
							}
						}
						if ( $links ) {
							echo '<p>' . esc_html__( 'Set up in:', 'chess-army-knife' ) . ' ' . wp_kses_post( implode( ', ', $links ) ) . '</p>';
						}
						?>
					</details>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}
}

Chess_Army_Knife_Block_Help::init();
