<?php
/**
 * Test Step 5.9 — Admin Assets Suite.
 *
 * Verifies:
 * 1. admin/assets/css/admin.css exists and contains design tokens & components.
 * 2. admin/assets/js/admin.js exists, has valid structure, and handles admin interactions.
 * 3. Assets are enqueued ONLY on Allship UPS plugin admin pages.
 * 4. Assets are NOT enqueued on standard WordPress admin pages (index.php, edit.php, plugins.php, etc.).
 * 5. Script localization provides ajaxUrl, nonce, and apiBase.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Global mocks
$GLOBALS['enqueued_styles'] = [];
$GLOBALS['enqueued_scripts'] = [];
$GLOBALS['localized_scripts'] = [];

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
		$GLOBALS['enqueued_styles'][ $handle ] = [
			'src'   => $src,
			'deps'  => $deps,
			'ver'   => $ver,
			'media' => $media,
		];
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $in_footer = false ) {
		$GLOBALS['enqueued_scripts'][ $handle ] = [
			'src'       => $src,
			'deps'      => $deps,
			'ver'       => $ver,
			'in_footer' => $in_footer,
		];
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ) {
		$GLOBALS['localized_scripts'][ $handle ][ $object_name ] = $l10n;
		return true;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'mock_nonce_' . $action;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '', $scheme = 'admin' ) {
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '', $scheme = 'rest' ) {
		return 'https://example.com/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'https://example.com/wp-content/plugins/allship-ups-quote/';
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function = '', $icon_url = '', $position = null ) {
		return 'toplevel_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function = '' ) {
		if ( $parent_slug === $menu_slug ) {
			return 'toplevel_page_' . $parent_slug;
		}
		return $parent_slug . '_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'wp_enqueue_media' ) ) {
	function wp_enqueue_media() {
		$GLOBALS['wp_media_enqueued'] = true;
	}
}

require_once dirname( __DIR__ ) . '/admin/class-admin-menu.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo " [PASS] " . $message . "\n";
		$passed++;
	} else {
		echo " [FAIL] " . $message . "\n";
		$failed++;
	}
}

echo "========================================================\n";
echo " Running Automated Tests for Step 5.9: Admin Assets\n";
echo "========================================================\n\n";

$plugin_dir = dirname( __DIR__ );
$css_file = $plugin_dir . '/admin/assets/css/admin.css';
$js_file  = $plugin_dir . '/admin/assets/js/admin.js';

// 1. Files exist and non-empty
assert_test( file_exists( $css_file ) && filesize( $css_file ) > 1000, "admin.css exists and is non-empty (" . filesize( $css_file ) . " bytes)" );
assert_test( file_exists( $js_file ) && filesize( $js_file ) > 1000, "admin.js exists and is non-empty (" . filesize( $js_file ) . " bytes)" );

// 2. CSS Content & Design Tokens
$css_content = file_get_contents( $css_file );
assert_test( strpos( $css_content, '--as-brand-red' ) !== false, "admin.css defines brand token --as-brand-red (#CE2027)" );
assert_test( strpos( $css_content, '--as-navy-900' ) !== false, "admin.css defines navy token --as-navy-900 (#0A1628)" );
assert_test( strpos( $css_content, '--as-radius-sm: 0px' ) !== false, "admin.css enforces flat sharp corners (--as-radius: 0px)" );
assert_test( strpos( $css_content, '.as-page-header' ) !== false, "admin.css defines .as-page-header component" );
assert_test( strpos( $css_content, '.as-btn' ) !== false, "admin.css defines .as-btn button component" );
assert_test( strpos( $css_content, '.as-modal' ) !== false, "admin.css defines .as-modal component" );
assert_test( strpos( $css_content, '.as-table' ) !== false, "admin.css defines .as-table component" );

// 3. JS Content & Structure
$js_content = file_get_contents( $js_file );
assert_test( strpos( $js_content, 'window.AllshipAdmin' ) !== false, "admin.js exports AllshipAdmin to global window" );
assert_test( strpos( $js_content, 'allshipUpsAdminConfig' ) !== false, "admin.js consumes localized config object allshipUpsAdminConfig" );
assert_test( strpos( $js_content, 'closeModals' ) !== false, "admin.js has modal control (closeModals)" );
assert_test( strpos( $js_content, 'initRatesPage' ) !== false, "admin.js provides rates management interface" );
assert_test( strpos( $js_content, 'initZonesPage' ) !== false, "admin.js provides zones management interface" );
assert_test( strpos( $js_content, 'initCountriesPage' ) !== false, "admin.js provides countries management interface" );

// 4. Enqueue Logic on Plugin Pages
$admin_menu = new Allship_UPS_Admin_Menu();
$admin_menu->add_admin_menu();

$plugin_hooks = [
	'toplevel_page_allship-ups-quote',
	'allship-ups-quote_page_allship-ups-import',
	'allship-ups-quote_page_allship-ups-rates',
	'allship-ups-quote_page_allship-ups-zones',
	'allship-ups-quote_page_allship-ups-countries',
	'allship-ups-quote_page_allship-ups-settings',
	'allship-ups-quote_page_allship-ups-logs',
];

$all_plugin_pages_pass = true;
foreach ( $plugin_hooks as $hook ) {
	$GLOBALS['enqueued_styles'] = [];
	$GLOBALS['enqueued_scripts'] = [];
	$GLOBALS['localized_scripts'] = [];

	$admin_menu->enqueue_admin_assets( $hook );

	$has_css = isset( $GLOBALS['enqueued_styles']['allship-ups-admin'] );
	$has_js  = isset( $GLOBALS['enqueued_scripts']['allship-ups-admin'] );
	$has_l10n = isset( $GLOBALS['localized_scripts']['allship-ups-admin']['allshipUpsAdminConfig'] );

	if ( ! $has_css || ! $has_js || ! $has_l10n ) {
		$all_plugin_pages_pass = false;
		echo " [FAIL] Failed asset loading on plugin hook: $hook\n";
	}
}
assert_test( $all_plugin_pages_pass, "admin CSS and JS correctly enqueued on all 7 plugin admin pages" );

// Check localization content
$l10n = $GLOBALS['localized_scripts']['allship-ups-admin']['allshipUpsAdminConfig'];
assert_test( ! empty( $l10n['ajaxUrl'] ) && strpos( $l10n['ajaxUrl'], 'admin-ajax.php' ) !== false, "Localized config contains correct ajaxUrl" );
assert_test( ! empty( $l10n['nonce'] ) && strpos( $l10n['nonce'], 'allship_ups_admin' ) !== false, "Localized config contains valid nonce" );
assert_test( ! empty( $l10n['apiBase'] ) && strpos( $l10n['apiBase'], 'ups-quote/v1' ) !== false, "Localized config contains REST apiBase" );

// Check style and script dependencies
$css_deps = $GLOBALS['enqueued_styles']['allship-ups-admin']['deps'] ?? [];
$js_deps  = $GLOBALS['enqueued_scripts']['allship-ups-admin']['deps'] ?? [];
assert_test( in_array( 'dashicons', $css_deps, true ), "CSS dependency includes 'dashicons'" );
assert_test( in_array( 'jquery', $js_deps, true ), "JS dependency includes 'jquery'" );

// 5. Enqueue Logic on Non-Plugin Pages (MUST NOT LOAD)
$other_hooks = [
	'index.php',
	'edit.php',
	'post.php',
	'upload.php',
	'plugins.php',
	'themes.php',
	'users.php',
	'tools.php',
	'options-general.php',
	'edit-comments.php',
];

$all_other_pages_blocked = true;
foreach ( $other_hooks as $hook ) {
	$GLOBALS['enqueued_styles'] = [];
	$GLOBALS['enqueued_scripts'] = [];
	$GLOBALS['localized_scripts'] = [];

	$admin_menu->enqueue_admin_assets( $hook );

	if ( isset( $GLOBALS['enqueued_styles']['allship-ups-admin'] ) || isset( $GLOBALS['enqueued_scripts']['allship-ups-admin'] ) ) {
		$all_other_pages_blocked = false;
		echo " [FAIL] Assets mistakenly loaded on non-plugin hook: $hook\n";
	}
}
assert_test( $all_other_pages_blocked, "admin CSS and JS are strictly NOT loaded on any other WordPress admin page" );

echo "\n--------------------------------------------------------\n";
echo " Test Suite Finished: $passed Passed, $failed Failed\n";
echo "========================================================\n";

if ( $failed > 0 ) {
	exit( 1 );
}
exit( 0 );
