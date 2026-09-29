<?php
/**
 * Plugin Name:       Chess Army Knife
 * Plugin URI:        https://example.com/chess-army-knife
 * Description:       Gutenberg blocks for chess clubs: English Chess Federation (ECF) ratings and League Management System (LMS) data, club tournaments and club events.
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
require_once Chess_Army_Knife_DIR . 'includes/class-club-teams-page.php';
require_once Chess_Army_Knife_DIR . 'includes/class-templates.php';
require_once Chess_Army_Knife_DIR . 'includes/class-berger.php';
require_once Chess_Army_Knife_DIR . 'includes/class-standings.php';
require_once Chess_Army_Knife_DIR . 'includes/class-bracket.php';
require_once Chess_Army_Knife_DIR . 'includes/class-matching.php';
require_once Chess_Army_Knife_DIR . 'includes/class-swiss-dutch.php';
require_once Chess_Army_Knife_DIR . 'includes/class-tournament-store.php';
require_once Chess_Army_Knife_DIR . 'includes/class-tournaments.php';
require_once Chess_Army_Knife_DIR . 'includes/class-tournament-rest.php';
require_once Chess_Army_Knife_DIR . 'includes/class-players-page.php';
require_once Chess_Army_Knife_DIR . 'includes/class-player-selector.php';
require_once Chess_Army_Knife_DIR . 'includes/class-tournaments-page.php';
require_once Chess_Army_Knife_DIR . 'includes/class-tournament-summary.php';
require_once Chess_Army_Knife_DIR . 'includes/class-admin-refresh.php';
require_once Chess_Army_Knife_DIR . 'includes/class-events.php';
require_once Chess_Army_Knife_DIR . 'includes/class-events-admin.php';
require_once Chess_Army_Knife_DIR . 'includes/class-events-display.php';
require_once Chess_Army_Knife_DIR . 'includes/class-events-import.php';
require_once Chess_Army_Knife_DIR . 'includes/class-events-rest.php';
require_once Chess_Army_Knife_DIR . 'includes/blocks.php';

/**
 * Plugin activation: create the persistent cache table and schedule its
 * daily cleanup of expired rows.
 */
function Chess_Army_Knife_activate() {
	Chess_Army_Knife_Cache::install_table();
	Chess_Army_Knife_Tournament_Store::install_tables();

	// The event post type needs its URLs registered before they are flushed.
	Chess_Army_Knife_Events::register();
	flush_rewrite_rules();

	if ( ! wp_next_scheduled( 'Chess_Army_Knife_cleanup_cache' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'Chess_Army_Knife_cleanup_cache' );
	}
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
