<?php
/**
 * Removes plugin data on uninstall, if the admin opted in.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'saunaplan_tick' );

$saunaplan_settings = get_option( 'saunaplan_settings' );
if ( empty( $saunaplan_settings['delete_data'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'units', 'residents', 'sessions', 'bookings', 'participants', 'waitlist', 'ratings', 'series' ) as $saunaplan_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}saunaplan_{$saunaplan_table}" ); // phpcs:ignore WordPress.DB
}
delete_option( 'saunaplan_settings' );
delete_option( 'saunaplan_db_version' );
delete_option( 'saunaplan_last_cleanup' );
