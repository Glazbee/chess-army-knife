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

// The membership permission is not data: take it back from whoever was given it.
foreach ( get_users( array( 'capability' => 'chess_army_manage_memberships' ) ) as $chess_army_knife_user ) {
	$chess_army_knife_user->remove_cap( 'chess_army_manage_memberships' );
}

global $wpdb;

$like = $wpdb->esc_like( '_transient_ecflms_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No WordPress API lists options by prefix.
$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
foreach ( $options as $option_name ) {
	delete_option( $option_name );
}

$like_timeout = $wpdb->esc_like( '_transient_timeout_ecflms_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No WordPress API lists options by prefix.
$timeout_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) );
foreach ( $timeout_options as $option_name ) {
	delete_option( $option_name );
}

// Drop the persistent cache table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall cleanup of plugin-owned data and tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}chess_army_knife_cache" );

// Club events and their tags are user data too: only removed if the admin opted in.
if ( $chess_army_knife_delete_data ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin-owned data and tables.
	$chess_army_knife_event_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'chess_army_event' ) );
	foreach ( $chess_army_knife_event_ids as $chess_army_knife_event_id ) {
		wp_delete_post( (int) $chess_army_knife_event_id, true );
	}

	register_taxonomy( 'chess_army_event_tag', 'chess_army_event' );
	$chess_army_knife_tag_ids = get_terms(
		array(
			'taxonomy'   => 'chess_army_event_tag',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	foreach ( is_array( $chess_army_knife_tag_ids ) ? $chess_army_knife_tag_ids : array() as $chess_army_knife_tag_id ) {
		wp_delete_term( (int) $chess_army_knife_tag_id, 'chess_army_event_tag' );
	}
}

// Membership types and members (personal details) are user data too: only removed if the admin opted in.
if ( $chess_army_knife_delete_data ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin-owned data and tables.
	$chess_army_knife_type_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'chess_army_mem_type' ) );
	foreach ( $chess_army_knife_type_ids as $chess_army_knife_type_id ) {
		wp_delete_post( (int) $chess_army_knife_type_id, true );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall cleanup of plugin-owned data and tables.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}chess_army_knife_members" );
}

// Tournament history is user data: only drop it if the admin opted in on the Settings page.
if ( $chess_army_knife_delete_data ) {
	foreach ( array( 'games', 'entries', 'tournaments', 'players' ) as $chess_army_knife_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall cleanup of plugin-owned data and tables.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}chess_army_knife_{$chess_army_knife_table}" );
	}
}
