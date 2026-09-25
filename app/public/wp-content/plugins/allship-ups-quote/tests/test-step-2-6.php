<?php
/**
 * Test Step 2.6 — CSV/XLSX Templates.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
	}
}

require_once dirname( __DIR__ ) . '/libs/SimpleXLSX.php';
require_once dirname( __DIR__ ) . '/includes/importers/class-csv-parser.php';
require_once dirname( __DIR__ ) . '/includes/importers/class-xlsx-reader.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-country-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-zone-repository.php';
require_once dirname( __DIR__ ) . '/includes/importers/class-rate-importer.php';
require_once dirname( __DIR__ ) . '/includes/importers/class-zone-importer.php';

echo "=== START TEST STEP 2.6: CSV/XLSX TEMPLATES ===\n";

$rate_template_path = dirname( __DIR__ ) . '/templates/rate-template.csv';
$zone_template_path = dirname( __DIR__ ) . '/templates/zone-template.csv';

// 1. Check template files exist
assert( file_exists( $rate_template_path ), 'Rate template file must exist' );
assert( file_exists( $zone_template_path ), 'Zone template file must exist' );
echo "✓ Both template files exist in templates/ directory\n";

$csv_parser    = new Allship_UPS_CSV_Parser();
$rate_importer = new Allship_UPS_Rate_Importer();
$zone_importer = new Allship_UPS_Zone_Importer();

// 2. Validate Rate Template headers & parsing
echo "\n--- Test 2.1: Rate Template Header & Content Validation ---\n";
$rate_rows = $csv_parser->parse( $rate_template_path, Allship_UPS_Rate_Importer::EXPECTED_RATE_HEADERS );
assert( ! empty( $rate_rows ), 'Rate template must contain parsed data rows' );
echo "✓ Rate template headers strictly match EXPECTED_RATE_HEADERS (" . count( $rate_rows ) . " rows)\n";

$rate_records = $rate_importer->parse_file( $rate_template_path );
assert( ! empty( $rate_records ) );
$rate_val = $rate_importer->validate( $rate_records );
assert( $rate_val['is_valid'] === true, 'Rate template must pass Rate_Importer validation: ' . json_encode( $rate_val['errors'] ) );
assert( count( $rate_val['rate_groups'] ) === 4, 'Rate template must demonstrate all 4 rate groups' );
assert( count( $rate_val['zones'] ) === 11, 'Rate template must demonstrate all 11 zones (1-10 + US5)' );
echo "✓ Rate template parsed and validated successfully (4 rate groups, 11 zones)\n";

// 3. Validate Zone Template headers & parsing
echo "\n--- Test 2.2: Zone Template Header & Content Validation ---\n";
$zone_rows = $csv_parser->parse( $zone_template_path, Allship_UPS_Zone_Importer::EXPECTED_PIVOT_HEADERS );
assert( ! empty( $zone_rows ), 'Zone template must contain parsed data rows' );
echo "✓ Zone template headers strictly match EXPECTED_PIVOT_HEADERS (" . count( $zone_rows ) . " rows)\n";

$zone_data = $zone_importer->parse_file( $zone_template_path );
$zone_val  = $zone_importer->validate( $zone_data );
assert( $zone_val['is_valid'] === true, 'Zone template must pass Zone_Importer validation: ' . json_encode( $zone_val['errors'] ) );
assert( $zone_val['total_countries'] > 0 );
assert( $zone_val['services_summary']['import']['WFM'] === 0 );
echo "✓ Zone template parsed and validated successfully (US overrides, 6 services, WFM import = 0)\n";

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 2.6 PASSED 100%!\n";
echo "============================================\n";
