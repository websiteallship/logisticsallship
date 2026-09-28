<?php
/**
 * Test Step 5.3 Extension: Separate Rates and Zones import flows & Zone sample download.
 */

// Mock WordPress environment
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../../' );
}
if ( ! defined( 'ALLSHIP_UPS_QUOTE_PATH' ) ) {
	define( 'ALLSHIP_UPS_QUOTE_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ALLSHIP_UPS_QUOTE_URL' ) ) {
	define( 'ALLSHIP_UPS_QUOTE_URL', 'http://example.com/wp-content/plugins/allship-ups-quote/' );
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return filter_var( $url, FILTER_SANITIZE_URL ); }
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'http://example.com/wp-admin/' . $path; }
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $echo = true ) {
		$result = ( (string) $selected === (string) $current ) ? " selected='selected'" : '';
		if ( $echo ) echo $result;
		return $result;
	}
}

echo "=== START TEST: SEPARATE RATES & ZONES IMPORT ===\n\n";

// 1. Verify Sample Zone File
echo "--- Test 1: Zone Sample File Verification ---\n";
$zone_sample_file = ALLSHIP_UPS_QUOTE_PATH . 'assets/samples/mau-phan-vung-iata.csv';
assert( file_exists( $zone_sample_file ), 'mau-phan-vung-iata.csv must exist in assets/samples/' );
$header_line = trim( fgets( fopen( $zone_sample_file, 'r' ) ) );
echo "Header: $header_line\n";
assert( strpos( $header_line, 'IATA' ) !== false, 'Header must contain IATA' );
assert( strpos( $header_line, 'XPR' ) !== false, 'Header must contain XPR' );
assert( strpos( $header_line, 'EXW' ) !== false, 'Header must contain EXW' );
echo "✓ Zone sample file exists and contains standard headers.\n\n";

// 2. Verify View Structure (import.php)
echo "--- Test 2: View Structure (admin/views/import.php) ---\n";
ob_start();
$page_title = 'Import Bảng giá & Vùng cước UPS';
include ALLSHIP_UPS_QUOTE_PATH . 'admin/views/import.php';
$html = ob_get_clean();

assert( strpos( $html, 'id="tabSwitchRates"' ) !== false, 'Must have tabSwitchRates' );
assert( strpos( $html, 'id="tabSwitchZones"' ) !== false, 'Must have tabSwitchZones' );
assert( strpos( $html, 'id="formImportUploadRates"' ) !== false, 'Must have formImportUploadRates' );
assert( strpos( $html, 'id="formImportUploadZones"' ) !== false, 'Must have formImportUploadZones' );
assert( strpos( $html, 'mau-phan-vung-iata.csv' ) !== false, 'Must contain link to mau-phan-vung-iata.csv' );
assert( strpos( $html, 'mau-bang-gia-ups-sample.xlsx' ) !== false, 'Must contain link to mau-bang-gia-ups-sample.xlsx' );

// Ensure formImportUploadZones DOES NOT contain rate_card_name or valid_from
$form_zones_start = strpos( $html, 'id="formImportUploadZones"' );
$form_zones_end   = strpos( $html, '</form>', $form_zones_start );
$form_zones_html  = substr( $html, $form_zones_start, $form_zones_end - $form_zones_start );

assert( strpos( $form_zones_html, 'rate_card_name' ) === false, 'formImportUploadZones must NOT contain rate_card_name' );
assert( strpos( $form_zones_html, 'valid_from' ) === false, 'formImportUploadZones must NOT contain valid_from' );
assert( strpos( $form_zones_html, 'inputZoneFileOnly' ) !== false, 'formImportUploadZones must contain inputZoneFileOnly' );
echo "✓ View structure verified: Rates and Zones are completely separated.\n\n";

echo "============================================\n";
echo "✓ ALL SEPARATE IMPORT TESTS PASSED 100%!\n";
echo "============================================\n";
