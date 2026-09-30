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

		// Vendor autoloader (Dompdf, etc.)
		if ( file_exists( ALLSHIP_UPS_QUOTE_PATH . 'vendor/autoload.php' ) ) {
			require_once ALLSHIP_UPS_QUOTE_PATH . 'vendor/autoload.php';
		}

		$files = [
			// Lifecycle & DB
			'class-activator.php',
			// Config
			'config/service-registry.php',
			// Repositories
			'class-settings-manager.php',
			'class-zone-set-repository.php',
			'class-rate-card-repository.php',
			'class-country-repository.php',
			'class-zone-repository.php',
			'class-rate-repository.php',
			'class-quote-log-repository.php',
			'class-quote-lead-repository.php',
			// Services & Calculators
			'class-service-availability-manager.php',
			'class-weight-calculator.php',
			'class-zone-resolver.php',
			'class-rate-lookup.php',
			'class-surcharge-engine.php',
			'class-quote-calculator.php',
			'class-quote-pdf-renderer.php',
			'class-quote-mailer.php',
			// Importers
			'importers/class-csv-parser.php',
			'importers/class-xlsx-reader.php',
			'importers/class-rate-importer.php',
			'importers/class-zone-importer.php',
			'importers/class-import-orchestrator.php',
			// Endpoints & Views
			'class-rest-controller.php',
			'class-shortcode.php',
			// FluentForm Integration Bridge
			'class-fluentform-bridge.php',
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
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// Register Admin Menu and AJAX handlers in admin context
		if ( is_admin() ) {
			if ( class_exists( 'Allship_UPS_Admin_Menu' ) ) {
				$admin_menu = new Allship_UPS_Admin_Menu();
				$admin_menu->register();
			}
			if ( class_exists( 'Allship_UPS_Admin_Rate_Cards' ) ) {
				$admin_rate_cards = new Allship_UPS_Admin_Rate_Cards();
				$admin_rate_cards->register_hooks();
			}
			if ( class_exists( 'Allship_UPS_Admin_Import' ) ) {
				$admin_import = new Allship_UPS_Admin_Import();
				$admin_import->register_hooks();
			}
			if ( class_exists( 'Allship_UPS_Admin_Rates' ) ) {
				$admin_rates = new Allship_UPS_Admin_Rates();
				$admin_rates->register_hooks();
			}
			if ( class_exists( 'Allship_UPS_Admin_Zones' ) ) {
				$admin_zones = new Allship_UPS_Admin_Zones();
				$admin_zones->register_hooks();
			}
			if ( class_exists( 'Allship_UPS_Admin_Countries' ) ) {
				$admin_countries = new Allship_UPS_Admin_Countries();
				$admin_countries->register_hooks();
			}
			if ( class_exists( 'Allship_UPS_Admin_Settings' ) ) {
				$admin_settings = new Allship_UPS_Admin_Settings();
				$admin_settings->register_hooks();
			}
			if ( class_exists( 'Allship_UPS_Admin_Quote_Logs' ) ) {
				$admin_quote_logs = new Allship_UPS_Admin_Quote_Logs();
				$admin_quote_logs->register_hooks();
			}
		}
	}

	/**
	 * Register plugin REST routes on 'rest_api_init'.
	 */
	public function register_rest_routes() {
		if ( class_exists( 'Allship_UPS_REST_Controller' ) ) {
			$controller = new Allship_UPS_REST_Controller();
			$controller->register_routes();
		}
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

		// Initialize public shortcode & asset enqueue manager.
		if ( class_exists( 'Allship_UPS_Shortcode' ) ) {
			$shortcode = new Allship_UPS_Shortcode();
			$shortcode->register();
		}

		// Initialize FluentForm Bridge.
		if ( class_exists( 'Allship_UPS_FluentForm_Bridge' ) ) {
			$bridge = new Allship_UPS_FluentForm_Bridge();
			$bridge->init();
		}

		// Schedule daily PDF quotes cleanup if not scheduled.
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) ) {
			if ( ! wp_next_scheduled( 'allship_ups_cleanup_old_quotes_cron' ) ) {
				wp_schedule_event( time(), 'daily', 'allship_ups_cleanup_old_quotes_cron' );
			}
		}
		if ( class_exists( 'Allship_UPS_Quote_Pdf_Renderer' ) ) {
			add_action( 'allship_ups_cleanup_old_quotes_cron', [ 'Allship_UPS_Quote_Pdf_Renderer', 'cleanup_old_quotes' ] );
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
