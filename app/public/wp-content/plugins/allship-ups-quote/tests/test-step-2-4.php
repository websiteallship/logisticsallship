<?php
/**
 * Test Step 2.4 — Zone Importer.
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
require_once dirname( __DIR__ ) . '/includes/class-country-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-zone-repository.php';
require_once dirname( __DIR__ ) . '/includes/importers/class-zone-importer.php';

echo "=== START TEST STEP 2.4: ZONE IMPORTER ===\n";

$iata_xlsx_path = dirname( __DIR__, 4 ) . '/docs_plugin_quote/docs/IATA.xlsx';

class Mock_WPDB_Zone_Step24 {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $queries = [];
	public $countries = [];
	public $zones = [];

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

	public function get_results( $sql ) {
		if ( false !== strpos( $sql, 'ups_countries' ) ) {
			$res = [];
			$id = 1;
			foreach ( $this->countries as $iata => $c ) {
				$obj = new stdClass();
				$obj->id = $id++;
				$obj->iata_code = $iata;
				$obj->country_name = $c['country_name'];
				$obj->normalized_name = $c['normalized_name'];
				$obj->is_us_override = $c['is_us_override'];
				$obj->has_extended_area_note = $c['has_extended_area_note'];
				$obj->is_active = $c['is_active'];
				$res[] = $obj;
			}
			return $res;
		}
		return [];
	}
}

$mock_wpdb    = new Mock_WPDB_Zone_Step24();
$country_repo = new Allship_UPS_Country_Repository( $mock_wpdb );
$zone_repo    = new Allship_UPS_Zone_Repository( $mock_wpdb );
$importer     = new Allship_UPS_Zone_Importer( null, null, $country_repo, $zone_repo );

// 1. Import IATA.xlsx (flat) -> 249 countries (ZZ skipped)
echo "\n--- Test 1: Import IATA.xlsx (flat) -> 249 countries (ZZ skipped) ---\n";
assert( file_exists( $iata_xlsx_path ), "IATA.xlsx must exist at $iata_xlsx_path" );

$format = $importer->detect_format( $iata_xlsx_path );
assert( $format === 'flat_xlsx', "Format must be flat_xlsx, got $format" );
echo "✓ Format detected: $format\n";

$parsed = $importer->parse_file( $iata_xlsx_path );
$countries = $parsed['countries'];
$zones     = $parsed['zones'];

assert( count( $countries ) === 249, "Total unique countries must be 249, got " . count( $countries ) );

$has_zz = false;
foreach ( $countries as $c ) {
	if ( $c['iata_code'] === 'ZZ' ) {
		$has_zz = true;
	}
}
assert( ! $has_zz, "ZZ must be skipped" );
echo "✓ 249 countries found (ZZ skipped)\n";

// 2. US attributes & zone 5 for WXS/XPD/WFM export
echo "\n--- Test 2: US -> is_us_override = 1, zone 5 for WXS/XPD/WFM export ---\n";
$us_country = null;
foreach ( $countries as $c ) {
	if ( $c['iata_code'] === 'US' ) {
		$us_country = $c;
		break;
	}
}
assert( $us_country !== null );
assert( $us_country['is_us_override'] === 1 );
assert( $us_country['has_extended_area_note'] === 1 );
echo "✓ US -> is_us_override = 1, has_extended_area_note = 1\n";

$us_export_zones = [];
foreach ( $zones as $z ) {
	if ( $z['iata_code'] === 'US' && $z['direction'] === 'export' ) {
		$us_export_zones[ $z['service_code'] ] = $z['zone'];
	}
}
assert( ( $us_export_zones['WXS'] ?? '' ) === '5' );
assert( ( $us_export_zones['XPD'] ?? '' ) === '5' );
assert( ( $us_export_zones['WFM'] ?? '' ) === '5' );
echo "✓ US export zone is 5 for WXS, XPD, and WFM\n";

// 3. United States* -> has_extended_area_note = 1
echo "\n--- Test 3: United States* -> has_extended_area_note = 1 ---\n";
assert( strpos( $us_country['country_name'], '*' ) !== false );
assert( $us_country['has_extended_area_note'] === 1 );
echo "✓ 'United States*' has_extended_area_note = 1 verified\n";

// 4. WFM import -> 0 countries available
echo "\n--- Test 4: WFM import -> 0 countries available ---\n";
$wfm_import_count = 0;
foreach ( $zones as $z ) {
	if ( $z['service_code'] === 'WFM' && $z['direction'] === 'import' && $z['is_available'] ) {
		$wfm_import_count++;
	}
}
assert( $wfm_import_count === 0 );
echo "✓ WFM import -> 0 countries available verified\n";

$validation = $importer->validate( $parsed );
assert( $validation['is_valid'] === true );
echo "✓ Validation passed\n";

// 5. Preview mode (no DB commit)
echo "\n--- Test 5: Preview mode (no DB commit) ---\n";
$pre_count = count( $mock_wpdb->queries );
$preview = $importer->preview( $iata_xlsx_path );
assert( $preview['status'] === 'success' );
assert( $preview['total_countries'] === 249 );
assert( count( $mock_wpdb->queries ) === $pre_count );
echo "✓ Preview mode verified without DB commit\n";

// 6. CSV Pivot Template format
echo "\n--- Test 6: Import CSV pivot template -> same result ---\n";
$csv_temp = sys_get_temp_dir() . '/test-zone-pivot-template-step24.csv';
$csv_content = "iata_code,country_name,direction,wxs,xpd,wfm,exw,xpr,wxp\n";
$csv_content .= "US,United States*,export,5,5,5,5,5,5\n";
$csv_content .= "US,United States*,import,5,5,0,5,5,5\n";
$csv_content .= "CA,Canada*,export,5,5,5,5,5,5\n";
$csv_content .= "AF,Afghanistan,export,9,9,0,0,0,0\n";
$csv_content .= "AF,Afghanistan,import,10,0,0,0,10,0\n";
$csv_content .= "ZZ,Tortola,export,9,9,0,0,0,0\n";
file_put_contents( $csv_temp, $csv_content );

$csv_format = $importer->detect_format( $csv_temp );
assert( $csv_format === 'pivot_template' );

$csv_parsed = $importer->parse_file( $csv_temp );
assert( count( $csv_parsed['countries'] ) === 3 );

$csv_val = $importer->validate( $csv_parsed );
assert( $csv_val['is_valid'] === true );
assert( $csv_val['services_summary']['import']['WFM'] === 0 );

$us_found_csv = false;
foreach ( $csv_parsed['countries'] as $c ) {
	if ( $c['iata_code'] === 'US' ) {
		assert( $c['is_us_override'] === 1 );
		assert( $c['has_extended_area_note'] === 1 );
		$us_found_csv = true;
	}
}
assert( $us_found_csv );
unlink( $csv_temp );
echo "✓ CSV pivot template verified with same US overrides, ZZ skip, and WFM import = 0\n";

// 7. DB commit via import()
echo "\n--- Test 7: DB commit via import() ---\n";
foreach ( $parsed['countries'] as $c ) {
	$mock_wpdb->countries[ $c['iata_code'] ] = $c;
}
$import_res = $importer->import( $iata_xlsx_path, 1 );
assert( $import_res['status'] === 'success' );
assert( $import_res['countries_processed'] === 249 );
assert( $import_res['zones_processed'] === count( $zones ) );
echo "✓ import() processed 249 countries and " . count( $zones ) . " zone mappings into DB\n";

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 2.4 PASSED 100%!\n";
echo "============================================\n";
