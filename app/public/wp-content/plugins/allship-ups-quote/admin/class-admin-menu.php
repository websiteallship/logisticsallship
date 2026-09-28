<?php
/**
 * Admin Menu registration and page routing.
 *
 * Implements Step 5.1 of Phase 5:
 * - Top-level menu: "UPS Quote", icon dashicons-calculator.
 * - 7 Submenus: Dashboard, Import, Rates, Zones, Countries, Settings, Logs.
 * - Strict capability check: 'manage_options'.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Admin_Menu {

	/**
	 * Menu capability required.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Top-level menu slug.
	 */
	const PARENT_SLUG = 'allship-ups-quote';

	/**
	 * Registered submenu page hooks.
	 *
	 * @var array
	 */
	private $hook_suffixes = [];

	/**
	 * Register WordPress hooks.
	 */
	public function register() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Register top-level and submenu pages.
	 */
	public function add_admin_menu() {
		// Top-level menu
		$main_hook = add_menu_page(
			__( 'Báo giá UPS — Allship', 'allship-ups-quote' ),
			__( 'UPS Quote', 'allship-ups-quote' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			[ $this, 'render_dashboard_page' ],
			'dashicons-calculator',
			30
		);
		$this->hook_suffixes[ self::PARENT_SLUG ] = $main_hook;

		// 1. Submenu: Dashboard (Rate Cards Overview)
		$this->hook_suffixes['dashboard'] = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Tổng quan & Bảng giá — UPS Quote', 'allship-ups-quote' ),
			__( 'Dashboard', 'allship-ups-quote' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			[ $this, 'render_dashboard_page' ]
		);

		// 2. Submenu: Import
		$this->hook_suffixes['import'] = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Import Excel — UPS Quote', 'allship-ups-quote' ),
			__( 'Import', 'allship-ups-quote' ),
			self::CAPABILITY,
			'allship-ups-import',
			[ $this, 'render_import_page' ]
		);

		// 3. Submenu: Rates
		$this->hook_suffixes['rates'] = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Quản lý Giá cước — UPS Quote', 'allship-ups-quote' ),
			__( 'Rates', 'allship-ups-quote' ),
			self::CAPABILITY,
			'allship-ups-rates',
			[ $this, 'render_rates_page' ]
		);

		// 4. Submenu: Zones
		$this->hook_suffixes['zones'] = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Quản lý Vùng Zone — UPS Quote', 'allship-ups-quote' ),
			__( 'Zones', 'allship-ups-quote' ),
			self::CAPABILITY,
			'allship-ups-zones',
			[ $this, 'render_zones_page' ]
		);

		// 5. Submenu: Countries
		$this->hook_suffixes['countries'] = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Quốc gia & Điểm đến — UPS Quote', 'allship-ups-quote' ),
			__( 'Countries', 'allship-ups-quote' ),
			self::CAPABILITY,
			'allship-ups-countries',
			[ $this, 'render_countries_page' ]
		);

		// 6. Submenu: Settings
		$this->hook_suffixes['settings'] = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Cài đặt Cước & Phụ phí — UPS Quote', 'allship-ups-quote' ),
			__( 'Settings', 'allship-ups-quote' ),
			self::CAPABILITY,
			'allship-ups-settings',
			[ $this, 'render_settings_page' ]
		);

		// 7. Submenu: Logs
		$this->hook_suffixes['logs'] = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Nhật ký Báo giá & Leads — UPS Quote', 'allship-ups-quote' ),
			__( 'Logs', 'allship-ups-quote' ),
			self::CAPABILITY,
			'allship-ups-logs',
			[ $this, 'render_logs_page' ]
		);
	}

	/**
	 * Get registered hook suffixes.
	 *
	 * @return array
	 */
	public function get_hook_suffixes() {
		return $this->hook_suffixes;
	}

	/**
	 * Check if current admin page belongs to the plugin.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	public function is_plugin_page( $hook ) {
		if ( empty( $hook ) ) {
			return false;
		}
		return in_array( $hook, $this->hook_suffixes, true ) || strpos( $hook, 'allship-ups-' ) !== false;
	}

	/**
	 * Enqueue admin scripts & styles on plugin pages only.
	 *
	 * @param string $hook Page hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( ! $this->is_plugin_page( $hook ) ) {
			return;
		}

		$ver = defined( 'ALLSHIP_UPS_QUOTE_VERSION' ) ? ALLSHIP_UPS_QUOTE_VERSION : '1.0.0';
		$url = defined( 'ALLSHIP_UPS_QUOTE_URL' ) ? ALLSHIP_UPS_QUOTE_URL : plugin_dir_url( dirname( __FILE__ ) );

		// Admin CSS if exists
		$css_path = dirname( __FILE__ ) . '/assets/css/admin.css';
		if ( file_exists( $css_path ) ) {
			$css_ver = filemtime( $css_path ) ?: $ver;
			wp_enqueue_style( 'allship-ups-admin', $url . 'admin/assets/css/admin.css', [ 'dashicons' ], $css_ver );
		}

		// Admin JS if exists
		$js_path = dirname( __FILE__ ) . '/assets/js/admin.js';
		if ( file_exists( $js_path ) ) {
			$js_ver = filemtime( $js_path ) ?: $ver;
			wp_enqueue_script( 'allship-ups-admin', $url . 'admin/assets/js/admin.js', [ 'jquery' ], $js_ver, true );
			wp_localize_script(
				'allship-ups-admin',
				'allshipUpsAdminConfig',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'allship_ups_admin' ),
					'apiBase' => esc_url_raw( rest_url( 'ups-quote/v1' ) ),
				]
			);
		}
	}

	/**
	 * Render 1. Dashboard / Rate Cards Page.
	 */
	public function render_dashboard_page() {
		$this->check_capability();
		$this->render_view( 'rate-cards.php', [ 'page_title' => __( 'Quản lý Bảng giá (Rate Cards)', 'allship-ups-quote' ) ] );
	}

	/**
	 * Render 2. Import Excel Page.
	 */
	public function render_import_page() {
		$this->check_capability();
		$this->render_view( 'import.php', [ 'page_title' => __( 'Import Bảng giá & Zone UPS', 'allship-ups-quote' ) ] );
	}

	/**
	 * Render 3. Rates Edit Page.
	 */
	public function render_rates_page() {
		$this->check_capability();
		$this->render_view( 'rates-edit.php', [ 'page_title' => __( 'Tra cứu & Chỉnh sửa Mức cước (Rates)', 'allship-ups-quote' ) ] );
	}

	/**
	 * Render 4. Zones Edit Page.
	 */
	public function render_zones_page() {
		$this->check_capability();
		$this->render_view( 'zones-edit.php', [ 'page_title' => __( 'Quản lý Vùng cước (Zones)', 'allship-ups-quote' ) ] );
	}

	/**
	 * Render 5. Countries Edit Page.
	 */
	public function render_countries_page() {
		$this->check_capability();
		$this->render_view( 'countries-edit.php', [ 'page_title' => __( 'Danh mục Quốc gia & Điểm đến', 'allship-ups-quote' ) ] );
	}

	/**
	 * Render 6. Settings Page.
	 */
	public function render_settings_page() {
		$this->check_capability();
		$this->render_view( 'settings.php', [ 'page_title' => __( 'Cài đặt Quy tắc Tính cước & Phụ phí', 'allship-ups-quote' ) ] );
	}

	/**
	 * Render 7. Logs Page.
	 */
	public function render_logs_page() {
		$this->check_capability();
		$this->render_view( 'quote-logs.php', [ 'page_title' => __( 'Nhật ký Báo giá & Khách hàng tiềm năng (Leads)', 'allship-ups-quote' ) ] );
	}

	/**
	 * Check capability before rendering any page.
	 */
	private function check_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'allship-ups-quote' ), 403 );
		}
	}

	/**
	 * Load view template with fallback container.
	 *
	 * @param string $view_file Filename inside admin/views/.
	 * @param array  $data      Context variables.
	 */
	private function render_view( $view_file, $data = [] ) {
		$file_path = dirname( __FILE__ ) . '/views/' . $view_file;

		extract( $data ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract

		if ( file_exists( $file_path ) ) {
			include $file_path;
		} else {
			?>
			<div class="wrap allship-ups-admin-wrap">
				<h1 class="wp-heading-inline"><?php echo esc_html( $data['page_title'] ?? 'UPS Quote' ); ?></h1>
				<hr class="wp-header-end">
				<div class="notice notice-info inline">
					<p><?php echo esc_html( sprintf( __( 'Màn hình %s đang được tải hoặc khởi tạo.', 'allship-ups-quote' ), $view_file ) ); ?></p>
				</div>
			</div>
			<?php
		}
	}
}
