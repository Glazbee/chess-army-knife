<?php
/**
 * Plugin Name:       Chess Army Knife
 * Plugin URI:        https://example.com/chess-army-knife
 * Description:       Gutenberg blocks for chess clubs: English Chess Federation (ECF) ratings and League Management System (LMS) data, club tournaments, club events and club memberships.
 * Version:           0.0.1
 * Requires at least: 7.1.2
 * Requires PHP:      7.4
 * Author:            Your Club
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chess-army-knife
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

define( 'Chess_Army_Knife_VERSION', '0.0.1' );
define( 'Chess_Army_Knife_FILE', __FILE__ );
define( 'Chess_Army_Knife_DIR', plugin_dir_path( __FILE__ ) );
define( 'Chess_Army_Knife_URL', plugin_dir_url( __FILE__ ) );

require_once Chess_Army_Knife_DIR . 'includes/class-chess-army-knife-cache.php';
require_once Chess_Army_Knife_DIR . 'includes/class-ecf-client.php';
require_once Chess_Army_Knife_DIR . 'includes/class-lms-client.php';
require_once Chess_Army_Knife_DIR . 'includes/class-settings-page.php';
require_once Chess_Army_Knife_DIR . 'includes/class-contrast.php';
require_once Chess_Army_Knife_DIR . 'includes/class-headings.php';
require_once Chess_Army_Knife_DIR . 'includes/class-a11y.php';
require_once Chess_Army_Knife_DIR . 'includes/class-form-state.php';
require_once Chess_Army_Knife_DIR . 'includes/class-rating-chart.php';
require_once Chess_Army_Knife_DIR . 'includes/class-templates.php';
require_once Chess_Army_Knife_DIR . 'includes/class-berger.php';
require_once Chess_Army_Knife_DIR . 'includes/class-standings.php';
require_once Chess_Army_Knife_DIR . 'includes/class-bracket.php';
require_once Chess_Army_Knife_DIR . 'includes/class-matching.php';
require_once Chess_Army_Knife_DIR . 'includes/class-swiss-dutch.php';
require_once Chess_Army_Knife_DIR . 'includes/class-tournament-store.php';
require_once Chess_Army_Knife_DIR . 'includes/class-tournaments.php';
require_once Chess_Army_Knife_DIR . 'includes/class-tournament-rest.php';
require_once Chess_Army_Knife_DIR . 'includes/class-player-selector.php';
require_once Chess_Army_Knife_DIR . 'includes/class-tournaments-page.php';
require_once Chess_Army_Knife_DIR . 'includes/class-tournament-summary.php';
require_once Chess_Army_Knife_DIR . 'includes/class-admin-refresh.php';
require_once Chess_Army_Knife_DIR . 'includes/class-events.php';
require_once Chess_Army_Knife_DIR . 'includes/class-events-admin.php';
require_once Chess_Army_Knife_DIR . 'includes/class-events-display.php';
require_once Chess_Army_Knife_DIR . 'includes/class-clubs.php';
require_once Chess_Army_Knife_DIR . 'includes/class-events-import.php';
require_once Chess_Army_Knife_DIR . 'includes/class-events-rest.php';
require_once Chess_Army_Knife_DIR . 'includes/class-events-feed.php';
require_once Chess_Army_Knife_DIR . 'includes/class-captains.php';
require_once Chess_Army_Knife_DIR . 'includes/class-selection.php';
require_once Chess_Army_Knife_DIR . 'includes/class-announcements.php';
require_once Chess_Army_Knife_DIR . 'includes/class-memberships.php';
require_once Chess_Army_Knife_DIR . 'includes/class-teams.php';
require_once Chess_Army_Knife_DIR . 'includes/class-teams-admin.php';
require_once Chess_Army_Knife_DIR . 'includes/class-membership-store.php';
require_once Chess_Army_Knife_DIR . 'includes/class-do-not-record.php';
require_once Chess_Army_Knife_DIR . 'includes/class-member-history.php';
require_once Chess_Army_Knife_DIR . 'includes/class-member-audit.php';
require_once Chess_Army_Knife_DIR . 'includes/class-member-export.php';
require_once Chess_Army_Knife_DIR . 'includes/class-membership-form.php';
require_once Chess_Army_Knife_DIR . 'includes/class-notification-preferences.php';
require_once Chess_Army_Knife_DIR . 'includes/class-mailer.php';
require_once Chess_Army_Knife_DIR . 'includes/class-renewal-reminders.php';
require_once Chess_Army_Knife_DIR . 'includes/class-rating-refresh.php';
require_once Chess_Army_Knife_DIR . 'includes/class-member-photos.php';
require_once Chess_Army_Knife_DIR . 'includes/class-member-requests.php';
require_once Chess_Army_Knife_DIR . 'includes/class-membership-privacy.php';
require_once Chess_Army_Knife_DIR . 'includes/class-policies.php';
require_once Chess_Army_Knife_DIR . 'includes/class-memberships-admin.php';
require_once Chess_Army_Knife_DIR . 'includes/class-members-page.php';
require_once Chess_Army_Knife_DIR . 'includes/class-availability-reply.php';
require_once Chess_Army_Knife_DIR . 'includes/class-selection-page.php';
require_once Chess_Army_Knife_DIR . 'includes/class-announcements-admin.php';
require_once Chess_Army_Knife_DIR . 'includes/class-member-portal.php';
require_once Chess_Army_Knife_DIR . 'includes/class-renewals-page.php';
require_once Chess_Army_Knife_DIR . 'includes/class-member-checks-page.php';
require_once Chess_Army_Knife_DIR . 'includes/class-member-stats.php';
require_once Chess_Army_Knife_DIR . 'includes/class-dashboard-page.php';
require_once Chess_Army_Knife_DIR . 'includes/class-block-help.php';
require_once Chess_Army_Knife_DIR . 'includes/class-access.php';
require_once Chess_Army_Knife_DIR . 'includes/class-setup.php';
require_once Chess_Army_Knife_DIR . 'includes/class-setup-checklist.php';
require_once Chess_Army_Knife_DIR . 'includes/class-menu.php';
require_once Chess_Army_Knife_DIR . 'includes/blocks.php';

/**
 * Plugin activation: create the persistent cache table and schedule its
 * daily cleanup of expired rows.
 */
function Chess_Army_Knife_activate() {
	Chess_Army_Knife_Cache::install_table();
	Chess_Army_Knife_Tournament_Store::install_tables();
	Chess_Army_Knife_Membership_Store::install_table();
	Chess_Army_Knife_Member_History::install_table();
	Chess_Army_Knife_Notification_Preferences::install_table();
	Chess_Army_Knife_Mailer::install_table();
	Chess_Army_Knife_Teams::install_table();
	Chess_Army_Knife_Selection::install_tables();

	// The event post type needs its URLs registered before they are flushed.
	Chess_Army_Knife_Events::register();
	flush_rewrite_rules();

	// Whoever turns the plugin on can manage members; they can then give the permission to others.
	$activating_user = wp_get_current_user();
	if ( $activating_user->exists() && $activating_user->has_cap( 'manage_options' ) ) {
		$activating_user->add_cap( Chess_Army_Knife_Memberships::CAPABILITY );
	}

	if ( ! wp_next_scheduled( 'Chess_Army_Knife_cleanup_cache' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'Chess_Army_Knife_cleanup_cache' );
	}

	Chess_Army_Knife_Rating_Refresh::schedule();
	Chess_Army_Knife_Mailer::schedule();
	Chess_Army_Knife_Renewal_Reminders::schedule();
	Chess_Army_Knife_Announcements::schedule();

	// Ask for the club's details once, as soon as the plugin is on.
	Chess_Army_Knife_Setup::request_redirect();
}
register_activation_hook( __FILE__, 'Chess_Army_Knife_activate' );

/**
 * Plugin deactivation: clear any cached API responses so stale data
 * doesn't linger if the plugin is reactivated later with new settings,
 * and unschedule the cleanup cron.
 */
function Chess_Army_Knife_deactivate() {
	Chess_Army_Knife_Cache::flush_all();
	flush_rewrite_rules();
	wp_clear_scheduled_hook( 'Chess_Army_Knife_cleanup_cache' );
	Chess_Army_Knife_Rating_Refresh::unschedule();
	Chess_Army_Knife_Mailer::unschedule();
	Chess_Army_Knife_Renewal_Reminders::unschedule();
	Chess_Army_Knife_Announcements::unschedule();
	Chess_Army_Knife_Events_Import::unschedule();
}
register_deactivation_hook( __FILE__, 'Chess_Army_Knife_deactivate' );

/**
 * Daily housekeeping: delete expired rows from the persistent cache
 * table so it doesn't grow unbounded.
 */
function Chess_Army_Knife_cleanup_cache() {
	global $wpdb;
	$table = $wpdb->prefix . 'chess_army_knife_cache';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cleanup of the plugin-owned cache table; the table name is internal.
	$wpdb->query( "DELETE FROM {$table} WHERE expires_at < UTC_TIMESTAMP()" );
}
add_action( 'Chess_Army_Knife_cleanup_cache', 'Chess_Army_Knife_cleanup_cache' );

/**
 * Safety net: if the plugin was updated from a version that didn't have
 * the cache table, create it on the next admin page load rather than
 * requiring a deactivate/reactivate cycle.
 */
function Chess_Army_Knife_maybe_upgrade() {
	if ( get_option( 'Chess_Army_Knife_db_version' ) !== Chess_Army_Knife_VERSION ) {
		Chess_Army_Knife_Cache::install_table();
		Chess_Army_Knife_Tournament_Store::install_tables();
		Chess_Army_Knife_Membership_Store::install_table();
		Chess_Army_Knife_Member_History::install_table();
		Chess_Army_Knife_Notification_Preferences::install_table();
		Chess_Army_Knife_Mailer::install_table();
		Chess_Army_Knife_Teams::install_table();
		Chess_Army_Knife_Selection::install_tables();

		update_option( 'Chess_Army_Knife_db_version', Chess_Army_Knife_VERSION );
	}
}
add_action( 'admin_init', 'Chess_Army_Knife_maybe_upgrade' );

/**
 * Load the plugin's translations.
 */
function Chess_Army_Knife_load_textdomain() {
	load_plugin_textdomain( 'chess-army-knife', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'Chess_Army_Knife_load_textdomain' );
