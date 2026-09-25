<?php
/**
 * Fired during plugin deactivation.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Deactivator {

	/**
	 * Clean up transients on deactivation.
	 */
	public static function deactivate() {
		global $wpdb;

		// Delete all plugin transients.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_ups_%'
			    OR option_name LIKE '_transient_timeout_ups_%'"
		);
	}
}
