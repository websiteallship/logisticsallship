<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Allship_UPS_Quote
 */

// Abort if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table_settings = $wpdb->prefix . 'ups_settings';

// Check if user opted to delete data on uninstall.
$delete_data = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT setting_value FROM {$table_settings} WHERE setting_key = %s",
		'delete_data_on_uninstall'
	)
);

if ( 'true' !== $delete_data ) {
	return;
}

// Drop all 6 custom tables.
$tables = [
	$wpdb->prefix . 'ups_quote_logs',
	$wpdb->prefix . 'ups_rates',
	$wpdb->prefix . 'ups_zone_maps',
	$wpdb->prefix . 'ups_countries',
	$wpdb->prefix . 'ups_rate_cards',
	$wpdb->prefix . 'ups_settings',
];

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete the auto-created page.
$page_id = get_option( 'allship_ups_quote_page_id' );
if ( $page_id ) {
	wp_delete_post( $page_id, true );
}

// Clean up wp_options.
delete_option( 'allship_ups_db_version' );
delete_option( 'allship_ups_quote_page_id' );

// Clean up transients.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_ups_%'
	    OR option_name LIKE '_transient_timeout_ups_%'"
);
