<?php
/**
 * Main plugin class.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Allship_UPS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Allship_UPS_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
	}

	/**
	 * Manual autoload of plugin modules.
	 */
	private function load_dependencies() {
		$includes = ALLSHIP_UPS_QUOTE_PATH . 'includes/';

		$files = [
			// Lifecycle & DB
			'class-activator.php',
			// Config
			'config/service-registry.php',
			// Repositories
			'class-settings-manager.php',
			'class-rate-card-repository.php',
			'class-country-repository.php',
			'class-zone-repository.php',
			'class-rate-repository.php',
			'class-quote-log-repository.php',
			// Services & Calculators
			'class-service-availability-manager.php',
			'class-weight-calculator.php',
			'class-zone-resolver.php',
			'class-rate-lookup.php',
			'class-surcharge-engine.php',
			'class-quote-calculator.php',
			// Importers
			'importers/class-csv-parser.php',
			'importers/class-xlsx-reader.php',
			'importers/class-rate-importer.php',
			'importers/class-zone-importer.php',
			'importers/class-import-orchestrator.php',
			// Endpoints & Views
			'class-rest-controller.php',
			'class-shortcode.php',
		];

		foreach ( $files as $file ) {
			if ( file_exists( $includes . $file ) ) {
				require_once $includes . $file;
			}
		}

		// Admin-only dependencies
		if ( is_admin() ) {
			$admin_files = [
				ALLSHIP_UPS_QUOTE_PATH . 'admin/class-admin-menu.php',
				ALLSHIP_UPS_QUOTE_PATH . 'admin/class-admin-import.php',
				ALLSHIP_UPS_QUOTE_PATH . 'admin/class-admin-rates.php',
				ALLSHIP_UPS_QUOTE_PATH . 'admin/class-admin-zones.php',
				ALLSHIP_UPS_QUOTE_PATH . 'admin/class-admin-countries.php',
				ALLSHIP_UPS_QUOTE_PATH . 'admin/class-admin-settings.php',
				ALLSHIP_UPS_QUOTE_PATH . 'admin/class-admin-rate-cards.php',
				ALLSHIP_UPS_QUOTE_PATH . 'admin/class-admin-quote-logs.php',
			];

			foreach ( $admin_files as $file ) {
				if ( file_exists( $file ) ) {
					require_once $file;
				}
			}
		}
	}

	/**
	 * Register core WordPress hooks.
	 */
	public function register_hooks() {
		add_action( 'init', [ $this, 'init' ] );
	}

	/**
	 * Initialization callback on 'init'.
	 */
	public function init() {
		load_plugin_textdomain(
			'allship-ups-quote',
			false,
			dirname( ALLSHIP_UPS_QUOTE_BASENAME ) . '/languages'
		);

		// Schema migration check.
		if ( class_exists( 'Allship_UPS_Activator' ) && get_option( 'allship_ups_db_version' ) !== Allship_UPS_Activator::DB_VERSION ) {
			Allship_UPS_Activator::migrate();
		}

		do_action( 'allship_ups_quote_init', $this );
	}

	/**
	 * Run plugin routines.
	 */
	public function run() {
		$this->register_hooks();
	}
}
