<?php
/**
 * Test Step 5.1 — Admin Menu Verification Suite.
 *
 * Verifies:
 * 1. Allship_UPS_Admin_Menu class structure & constants.
 * 2. Top-level menu: "UPS Quote", icon dashicons-calculator, capability manage_options.
 * 3. 7 Submenus: Dashboard, Import, Rates, Zones, Countries, Settings, Logs.
 * 4. Hook registration for admin_menu and admin_enqueue_scripts.
 * 5. Capability authorization gating (admin allowed, non-admin blocked).
 * 6. View rendering for all 7 admin pages.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Mock WordPress functions if running standalone CLI
if ( ! function_exists( 'add_action' ) ) {
	$GLOBALS['wp_actions'] = [];
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wp_actions'][ $tag ][] = [
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = 'default' ) {
		echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'http://example.com/wp-admin/' . $path;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'http://example.com/wp-json/' . $path;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'mock_nonce_' . md5( (string) $action );
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $echo = true ) {
		$result = ( (string) $selected === (string) $current ) ? " selected='selected'" : '';
		if ( $echo ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.com/wp-content/plugins/allship-ups-quote/';
	}
}

$GLOBALS['mock_menu_pages'] = [];
$GLOBALS['mock_submenu_pages'] = [];

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function = '', $icon_url = '', $position = null ) {
		$hook = 'toplevel_page_' . $menu_slug;
		$GLOBALS['mock_menu_pages'][ $menu_slug ] = [
			'page_title' => $page_title,
			'menu_title' => $menu_title,
			'capability' => $capability,
			'menu_slug'  => $menu_slug,
			'function'   => $function,
			'icon_url'   => $icon_url,
			'position'   => $position,
			'hook'       => $hook,
		];
		return $hook;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function = '', $position = null ) {
		$hook = $parent_slug . '_page_' . $menu_slug;
		$GLOBALS['mock_submenu_pages'][ $parent_slug ][ $menu_slug ] = [
			'parent_slug' => $parent_slug,
			'page_title'  => $page_title,
			'menu_title'  => $menu_title,
			'capability'  => $capability,
			'menu_slug'   => $menu_slug,
			'function'    => $function,
			'position'    => $position,
			'hook'        => $hook,
		];
		return $hook;
	}
}

$GLOBALS['mock_user_caps'] = [ 'manage_options' => true ];
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return ! empty( $GLOBALS['mock_user_caps'][ $cap ] );
	}
}

$GLOBALS['mock_wp_die_called'] = false;
$GLOBALS['mock_wp_die_message'] = '';
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $msg = '', $status = 403 ) {
		$GLOBALS['mock_wp_die_called'] = true;
		$GLOBALS['mock_wp_die_message'] = $msg;
		throw new Exception( 'WP_DIE: ' . $msg );
	}
}

require_once dirname( __DIR__ ) . '/admin/class-admin-menu.php';

echo "=== START TEST STEP 5.1: ADMIN MENU ===\n\n";

// --- Test 1: Class exists & register hooks ---
echo "--- Test 1: Class instantiation & hook registration ---\n";
$admin_menu = new Allship_UPS_Admin_Menu();
$admin_menu->register();

$has_menu_hook = false;
$has_enqueue_hook = false;
foreach ( $GLOBALS['wp_actions']['admin_menu'] ?? [] as $hook ) {
	if ( is_array( $hook['callback'] ) && $hook['callback'][0] instanceof Allship_UPS_Admin_Menu && $hook['callback'][1] === 'add_admin_menu' ) {
		$has_menu_hook = true;
	}
}
foreach ( $GLOBALS['wp_actions']['admin_enqueue_scripts'] ?? [] as $hook ) {
	if ( is_array( $hook['callback'] ) && $hook['callback'][0] instanceof Allship_UPS_Admin_Menu && $hook['callback'][1] === 'enqueue_admin_assets' ) {
		$has_enqueue_hook = true;
	}
}

if ( ! $has_menu_hook || ! $has_enqueue_hook ) {
	echo "✘ FAIL: Hooks 'admin_menu' or 'admin_enqueue_scripts' not registered correctly.\n";
	exit( 1 );
}
echo "✓ Hooks 'admin_menu' and 'admin_enqueue_scripts' successfully registered.\n\n";

// --- Test 2: Top-level Menu ---
echo "--- Test 2: Top-level menu registration ---\n";
$admin_menu->add_admin_menu();

$top_menu = $GLOBALS['mock_menu_pages']['allship-ups-quote'] ?? null;
if ( ! $top_menu ) {
	echo "✘ FAIL: Top-level menu 'allship-ups-quote' not found.\n";
	exit( 1 );
}

if ( $top_menu['menu_title'] !== 'UPS Quote' ) {
	echo "✘ FAIL: Expected menu title 'UPS Quote', got '{$top_menu['menu_title']}'.\n";
	exit( 1 );
}

if ( $top_menu['icon_url'] !== 'dashicons-calculator' ) {
	echo "✘ FAIL: Expected icon 'dashicons-calculator', got '{$top_menu['icon_url']}'.\n";
	exit( 1 );
}

if ( $top_menu['capability'] !== 'manage_options' ) {
	echo "✘ FAIL: Expected capability 'manage_options', got '{$top_menu['capability']}'.\n";
	exit( 1 );
}
echo "✓ Top-level menu verified: title='UPS Quote', icon='dashicons-calculator', cap='manage_options'.\n\n";

// --- Test 3: 7 Submenus Registration ---
echo "--- Test 3: 7 Submenus registration ---\n";
$submenus = $GLOBALS['mock_submenu_pages']['allship-ups-quote'] ?? [];

$expected_submenus = [
	'allship-ups-quote'     => 'Dashboard',
	'allship-ups-import'    => 'Import',
	'allship-ups-rates'     => 'Rates',
	'allship-ups-zones'     => 'Zones',
	'allship-ups-countries' => 'Countries',
	'allship-ups-settings'  => 'Settings',
	'allship-ups-logs'      => 'Logs',
];

if ( count( $submenus ) !== 7 ) {
	echo "✘ FAIL: Expected 7 submenus, found " . count( $submenus ) . ".\n";
	exit( 1 );
}

foreach ( $expected_submenus as $slug => $label ) {
	if ( ! isset( $submenus[ $slug ] ) ) {
		echo "✘ FAIL: Submenu '$slug' ($label) not registered.\n";
		exit( 1 );
	}
	$sub = $submenus[ $slug ];
	if ( $sub['capability'] !== 'manage_options' ) {
		echo "✘ FAIL: Submenu '$slug' capability must be 'manage_options', got '{$sub['capability']}'.\n";
		exit( 1 );
	}
	if ( $sub['menu_title'] !== $label ) {
		echo "✘ FAIL: Submenu '$slug' expected title '$label', got '{$sub['menu_title']}'.\n";
		exit( 1 );
	}
	echo "  • Submenu '{$slug}' ('{$label}'): Verified (cap=manage_options)\n";
}
echo "✓ All 7 submenus registered with correct slugs and capabilities.\n\n";

// --- Test 4: Capability & Security Gate ---
echo "--- Test 4: Capability gating (Admin vs Non-admin) ---\n";
// 4a: Admin allowed
$GLOBALS['mock_user_caps'] = [ 'manage_options' => true ];
$GLOBALS['mock_wp_die_called'] = false;
try {
	ob_start();
	$admin_menu->render_dashboard_page();
	$out = ob_get_clean();
	if ( empty( $out ) || strpos( $out, 'Quản lý Bảng giá' ) === false ) {
		echo "✘ FAIL: Admin render_dashboard_page output unexpected.\n";
		exit( 1 );
	}
} catch ( Exception $e ) {
	echo "✘ FAIL: Admin was blocked unexpectedly: " . $e->getMessage() . "\n";
	exit( 1 );
}
echo "✓ Admin with 'manage_options' successfully accessed dashboard view.\n";

// 4b: Non-admin blocked
$GLOBALS['mock_user_caps'] = [ 'manage_options' => false ];
$blocked = false;
try {
	ob_start();
	$admin_menu->render_dashboard_page();
	ob_end_clean();
} catch ( Exception $e ) {
	$blocked = true;
}

if ( ! $blocked || ! $GLOBALS['mock_wp_die_called'] ) {
	echo "✘ FAIL: Non-admin user was NOT blocked by capability check!\n";
	exit( 1 );
}
echo "✓ Non-admin user without 'manage_options' was correctly blocked with 403.\n\n";

// --- Test 5: All 7 Admin Views Render Correctly ---
echo "--- Test 5: Verify all 7 View Renderers ---\n";
$GLOBALS['mock_user_caps'] = [ 'manage_options' => true ];

$view_methods = [
	'render_dashboard_page' => 'Quản lý Bảng giá',
	'render_import_page'    => 'Import Bảng giá',
	'render_rates_page'     => 'Tra cứu',
	'render_zones_page'     => 'Quản lý Vùng cước',
	'render_countries_page' => 'Danh mục Quốc gia',
	'render_settings_page'  => 'Cài đặt Quy tắc Tính cước',
	'render_logs_page'      => 'Nhật ký Báo giá',
];

foreach ( $view_methods as $method => $expected_needle ) {
	ob_start();
	$admin_menu->$method();
	$html = ob_get_clean();

	if ( empty( $html ) || strpos( $html, $expected_needle ) === false ) {
		echo "✘ FAIL: Method $method() failed to render expected needle '$expected_needle'.\n";
		exit( 1 );
	}
	echo "  • $method(): Rendered successfully with '$expected_needle'\n";
}
echo "✓ All 7 view renderers execute cleanly without fatals.\n\n";

// --- Test 6: Hook Suffix Detection ---
echo "--- Test 6: Hook suffix detection ---\n";
$is_plugin = $admin_menu->is_plugin_page( 'toplevel_page_allship-ups-quote' );
$is_import = $admin_menu->is_plugin_page( 'allship-ups-quote_page_allship-ups-import' );
$is_other  = $admin_menu->is_plugin_page( 'edit.php' );

if ( ! $is_plugin || ! $is_import || $is_other ) {
	echo "✘ FAIL: is_plugin_page detection incorrect.\n";
	exit( 1 );
}
echo "✓ is_plugin_page correctly identifies plugin hooks and rejects foreign hooks.\n\n";

echo "============================================\n";
echo "✓ ALL TESTS STEP 5.1 PASSED 100%!\n";
echo "============================================\n";
exit( 0 );
