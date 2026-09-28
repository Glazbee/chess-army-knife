<?php
/**
 * Runs when the plugin is deleted via the Plugins screen. Removes the
 * plugin's settings option and any cached API data. Uploaded posts and
 * page content that use the blocks are left untouched (the blocks will
 * simply stop rendering data if the plugin isn't reinstalled).
 *
 * @package Chess_Army_Knife
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Read the settings before they are deleted: tournament data is only removed if the admin opted in.
$chess_army_knife_settings    = get_option( 'Chess_Army_Knife_settings', array() );
$chess_army_knife_delete_data = is_array( $chess_army_knife_settings ) && ! empty( $chess_army_knife_settings['delete_data_on_uninstall'] );

delete_option( 'Chess_Army_Knife_settings' );
delete_option( 'Chess_Army_Knife_club_teams' );
delete_option( 'Chess_Army_Knife_templates' );
delete_option( 'Chess_Army_Knife_db_version' );

global $wpdb;

$like = $wpdb->esc_like( '_transient_ecflms_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
foreach ( $options as $option_name ) {
	delete_option( $option_name );
}

$like_timeout = $wpdb->esc_like( '_transient_timeout_ecflms_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$timeout_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) );
foreach ( $timeout_options as $option_name ) {
	delete_option( $option_name );
}

// Drop the persistent cache table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}chess_army_knife_cache" );

// Tournament history is user data: only drop it if the admin opted in on the Settings page.
if ( $chess_army_knife_delete_data ) {
	foreach ( array( 'games', 'entries', 'tournaments', 'players' ) as $chess_army_knife_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}chess_army_knife_{$chess_army_knife_table}" );
	}
}
