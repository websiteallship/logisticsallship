<?php
/**
 * Test Step 2.3 — Rate Importer.
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
require_once dirname( __DIR__ ) . '/includes/importers/class-rate-importer.php';

echo "=== START TEST STEP 2.3: RATE IMPORTER ===\n";

$xlsx_path = dirname( __DIR__, 4 ) . '/docs_plugin_quote/docs/VN from 20.Aug.2026.xlsx';

class Mock_WPDB_Rate_Step23 {
	public $prefix = 'wp_';
	public $insert_id = 1;
	public $queries = [];

	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$pos = strpos( $query, '%' );
			if ( false !== $pos ) {
				$val = ( null === $arg ) ? 'NULL' : ( is_numeric( $arg ) ? $arg : "'" . addslashes( (string) $arg ) . "'" );
				$query = substr_replace( $query, $val, $pos, 2 );
			}
		}
		return $query;
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}
}

$mock_wpdb = new Mock_WPDB_Rate_Step23();
$rate_repo = new Allship_UPS_Rate_Repository( $mock_wpdb );
$importer  = new Allship_UPS_Rate_Importer( null, null, $rate_repo );

// 1. Weight bracket normalization tests
echo "\n--- Test 1: Weight bracket normalizations ---\n";

$w_21_44 = $importer->normalize_weight( '21-44' );
assert( $w_21_44['billing_unit'] === 'per_kg' );
assert( $w_21_44['weight_from'] == 21.0 );
assert( $w_21_44['weight_to'] == 44.0 );
echo "✓ Weight '21-44' -> billing_unit=per_kg, weight_from=21, weight_to=44\n";

$w_env = $importer->normalize_weight( 'UPS Envelope' );
assert( $w_env['billing_unit'] === 'flat' );
assert( $w_env['weight_from'] === null );
assert( $w_env['weight_to'] === null );
echo "✓ Weight 'UPS Envelope' -> billing_unit=flat, weight_from=null\n";

$w_gt1000 = $importer->normalize_weight( '>1000' );
assert( $w_gt1000['billing_unit'] === 'per_kg' );
assert( abs( $w_gt1000['weight_from'] - 1000.0001 ) < 0.00001 );
assert( $w_gt1000['weight_to'] === null );
echo "✓ Weight '>1000' -> billing_unit=per_kg, weight_from=1000.0001\n";

$w_min = $importer->normalize_weight( 'Minimum' );
assert( $w_min['billing_unit'] === 'minimum' );
assert( $w_min['weight_from'] === null );
assert( $w_min['weight_to'] === null );
echo "✓ Weight 'Minimum' -> billing_unit=minimum\n";

// 2. Parse & Validate 'VN from 20.Aug.2026.xlsx'
echo "\n--- Test 2: Parse & Validate 'VN from 20.Aug.2026.xlsx' ---\n";
assert( file_exists( $xlsx_path ), "XLSX file must exist at $xlsx_path" );

$format = $importer->detect_format( $xlsx_path );
assert( $format === 'ups_xlsx', "Expected 'ups_xlsx', got $format" );
echo "✓ Format detected: $format\n";

$records = $importer->parse_file( $xlsx_path );
echo "✓ Parsed " . count( $records ) . " rate records from XLSX\n";
assert( count( $records ) > 1000 );

$validation = $importer->validate( $records );
assert( $validation['is_valid'] === true );

$expected_groups = [
	'export_wxs_document',
	'export_wxs_nondocument',
	'export_xpd',
	'export_wfm',
];
foreach ( $expected_groups as $eg ) {
	assert( in_array( $eg, $validation['rate_groups'], true ), "Missing $eg" );
}
echo "✓ 4 rate groups found: " . implode( ', ', $validation['rate_groups'] ) . "\n";

$expected_zones = [ '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'US5' ];
foreach ( $expected_zones as $ez ) {
	assert( in_array( $ez, $validation['zones'], true ), "Missing zone $ez" );
}
echo "✓ All zones 1-10 + US5 found: " . implode( ', ', $validation['zones'] ) . "\n";

// 3. Preview mode (no DB commit)
echo "\n--- Test 3: Preview mode (no DB commit) ---\n";
$pre_count = count( $mock_wpdb->queries );
$preview = $importer->preview( $xlsx_path );
assert( $preview['status'] === 'success' );
assert( $preview['total_rates'] === count( $records ) );
assert( count( $mock_wpdb->queries ) === $pre_count );
assert( count( $preview['rate_groups'] ) === 4 );
echo "✓ Preview mode verified without DB commit (Total rates: {$preview['total_rates']})\n";

// 4. Missing rate group validation error
echo "\n--- Test 4: Missing rate group validation error ---\n";
$incomplete_records = array_filter( $records, function( $r ) {
	return $r['rate_group'] !== 'export_wfm';
} );
$invalid_val = $importer->validate( $incomplete_records );
assert( $invalid_val['is_valid'] === false );
$found_err = false;
foreach ( $invalid_val['errors'] as $err ) {
	if ( false !== stripos( $err, 'Missing required rate group: export_wfm' ) ) {
		$found_err = true;
		break;
	}
}
assert( $found_err );
echo "✓ Missing rate group properly detected with clear error\n";

// 5. CSV template format
echo "\n--- Test 5: CSV template format parsing & validation ---\n";
$csv_temp = sys_get_temp_dir() . '/test-step23-rate-template.csv';
$csv_content = "rate_group,weight_label,weight_from,weight_to,billing_unit,zone_1,zone_2,zone_3,zone_4,zone_5,zone_6,zone_7,zone_8,zone_9,zone_10,zone_us5\n";
$csv_content .= "export_wxs_document,UPS Envelope,,,flat,100,200,300,400,500,600,700,800,900,1000,550\n";
$csv_content .= "export_wxs_document,0.5,0.5,0.5,flat,120,220,320,420,520,620,720,820,920,1020,570\n";
$csv_content .= "export_wxs_nondocument,21-44,21,44,per_kg,50,60,70,80,90,100,110,120,130,140,95\n";
$csv_content .= "export_xpd,1,1,1,flat,80,90,100,110,120,130,140,150,160,170,125\n";
$csv_content .= "export_wfm,Minimum,,,minimum,5000,6000,7000,8000,9000,10000,11000,12000,13000,14000,9500\n";
file_put_contents( $csv_temp, $csv_content );

$csv_records = $importer->parse_file( $csv_temp );
assert( count( $csv_records ) === 55 );
$csv_preview = $importer->preview( $csv_temp );
assert( $csv_preview['status'] === 'success' );
assert( count( $csv_preview['rate_groups'] ) === 4 );
unlink( $csv_temp );
echo "✓ CSV template format verified\n";

// 6. DB Commit via import()
echo "\n--- Test 6: DB Commit via import() ---\n";
$import_res = $importer->import( $xlsx_path, 1 );
assert( $import_res['status'] === 'success' );
assert( $import_res['imported'] === count( $records ) );
echo "✓ import() committed {$import_res['imported']} rate rows to DB\n";

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 2.3 PASSED 100%!\n";
echo "============================================\n";
