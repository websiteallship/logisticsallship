<?php
/**
 * Plugin Name:       Allship UPS Quote
 * Plugin URI:        https://allship.vn
 * Description:       Hệ thống tính giá và báo giá UPS Express tự động cho Allship Logistics.
 * Version:           1.0.0
 * Author:            Allship Logistics
 * Author URI:        https://allship.vn
 * Text Domain:       allship-ups-quote
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define core plugin constants.
define( 'ALLSHIP_UPS_QUOTE_VERSION', '1.0.0' );
define( 'ALLSHIP_UPS_QUOTE_PATH', plugin_dir_path( __FILE__ ) );
define( 'ALLSHIP_UPS_QUOTE_URL', plugin_dir_url( __FILE__ ) );
define( 'ALLSHIP_UPS_QUOTE_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class.
 */
require_once ALLSHIP_UPS_QUOTE_PATH . 'includes/class-plugin.php';

/**
 * Register activation and deactivation hooks.
 */
register_activation_hook( __FILE__, function() {
	if ( file_exists( ALLSHIP_UPS_QUOTE_PATH . 'includes/class-activator.php' ) ) {
		require_once ALLSHIP_UPS_QUOTE_PATH . 'includes/class-activator.php';
		if ( class_exists( 'Allship_UPS_Activator' ) ) {
			Allship_UPS_Activator::activate();
		}
	}
} );

register_deactivation_hook( __FILE__, function() {
	if ( file_exists( ALLSHIP_UPS_QUOTE_PATH . 'includes/class-deactivator.php' ) ) {
		require_once ALLSHIP_UPS_QUOTE_PATH . 'includes/class-deactivator.php';
		if ( class_exists( 'Allship_UPS_Deactivator' ) ) {
			Allship_UPS_Deactivator::deactivate();
		}
	}
} );

/**
 * Bootstrap the plugin after all active plugins are loaded.
 */
function allship_ups_quote_run() {
	$plugin = Allship_UPS_Plugin::get_instance();
	$plugin->run();
}
add_action( 'plugins_loaded', 'allship_ups_quote_run' );
