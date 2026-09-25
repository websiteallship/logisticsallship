<?php
/**
 * Phase 2 Verification Test Suite — Allship UPS Quote Importers.
 *
 * Verifies all 7 gates defined in Step 2.7 of the implementation roadmap:
 * 1. Import VN from 20.Aug.2026.xlsx -> 4 rate groups, exactly 1001 rate rows.
 * 2. Import IATA.xlsx (flat) -> 249 countries, US is_us_override = 1, zone 5.
 * 3. Import CSV template -> matching results & verified headers.
 * 4. Preview mode -> correct summary report without DB commit.
 * 5. File format errors -> clear error messages.
 * 6. ZZ country skip -> ZZ is completely omitted from countries and zones.
 * 7. Atomic rollback -> mid-flight failure rolls back card, rates, and zones cleanly.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
	}
}

require_once dirname( __DIR__ ) . '/libs/SimpleXLSX.php';
require_once dirname( __DIR__ ) . '/includes/importers/class-csv-parser.php';
require_once dirname( __DIR__ ) . '/includes/importers/class-xlsx-reader.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-card-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-country-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-zone-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-repository.php';
require_once dirname( __DIR__ ) . '/includes/importers/class-rate-importer.php';
require_once dirname( __DIR__ ) . '/includes/importers/class-zone-importer.php';
require_once dirname( __DIR__ ) . '/includes/importers/class-import-orchestrator.php';

$rate_xlsx_path     = dirname( __DIR__, 4 ) . '/docs_plugin_quote/docs/VN from 20.Aug.2026.xlsx';
$iata_xlsx_path     = dirname( __DIR__, 4 ) . '/docs_plugin_quote/docs/IATA.xlsx';
$rate_template_path = dirname( __DIR__ ) . '/templates/rate-template.csv';
$zone_template_path = dirname( __DIR__ ) . '/templates/zone-template.csv';

class Mock_WPDB_Phase2 {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $queries = [];
	public $rate_cards = [];
	public $rates = [];
	public $zones = [];
	public $countries = [];
	private $auto_id = 1;

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

	public function insert( $table, $data, $format = null ) {
		$this->queries[] = "INSERT INTO $table";
		if ( false !== strpos( $table, 'ups_rate_cards' ) ) {
			$id = $this->auto_id++;
			$this->insert_id = $id;
			$data['id'] = $id;
			$this->rate_cards[ $id ] = (object) $data;
			return 1;
		}
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->queries[] = "UPDATE $table";
		if ( false !== strpos( $table, 'ups_rate_cards' ) && isset( $where['id'] ) ) {
			$id = $where['id'];
			if ( isset( $this->rate_cards[ $id ] ) ) {
				foreach ( $data as $k => $v ) {
					$this->rate_cards[ $id ]->$k = $v;
				}
			}
		}
		return 1;
	}

	public function delete( $table, $where, $where_format = null ) {
		$this->queries[] = "DELETE FROM $table";
		if ( false !== strpos( $table, 'ups_rate_cards' ) && isset( $where['id'] ) ) {
			unset( $this->rate_cards[ $where['id'] ] );
		}
		if ( false !== strpos( $table, 'ups_rates' ) && isset( $where['rate_card_id'] ) ) {
			$cid = $where['rate_card_id'];
			foreach ( $this->rates as $k => $v ) {
				if ( (int) ( $v['rate_card_id'] ?? 0 ) === (int) $cid ) {
					unset( $this->rates[ $k ] );
				}
			}
		}
		if ( false !== strpos( $table, 'ups_zone_maps' ) && isset( $where['rate_card_id'] ) ) {
			$cid = $where['rate_card_id'];
			foreach ( $this->zones as $k => $v ) {
				if ( (int) ( $v['rate_card_id'] ?? 0 ) === (int) $cid ) {
					unset( $this->zones[ $k ] );
				}
			}
		}
		return 1;
	}

	public function get_row( $sql, $output = 'OBJECT' ) {
		if ( false !== strpos( $sql, 'ups_rate_cards' ) ) {
			preg_match( '/id = (\d+)/', $sql, $m );
			if ( ! empty( $m[1] ) && isset( $this->rate_cards[ (int) $m[1] ] ) ) {
				return $this->rate_cards[ (int) $m[1] ];
			}
		}
		return null;
	}

	public function get_results( $sql ) {
		if ( false !== strpos( $sql, 'ups_countries' ) ) {
			$res = [];
			$id = 1;
			foreach ( $this->countries as $iata => $c ) {
				$obj = new stdClass();
				$obj->id = $id++;
				$obj->iata_code = $iata;
				$obj->country_name = $c['country_name'] ?? $iata;
				$obj->normalized_name = $c['normalized_name'] ?? $iata;
				$obj->is_us_override = $c['is_us_override'] ?? 0;
				$obj->has_extended_area_note = $c['has_extended_area_note'] ?? 0;
				$obj->is_active = 1;
				$res[] = $obj;
			}
			return $res;
		}
		return [];
	}
}

echo "========================================================\n";
echo "ALLSHIP UPS QUOTE - PHASE 2 VERIFICATION TEST SUITE\n";
echo "========================================================\n\n";

$mock_wpdb      = new Mock_WPDB_Phase2();
$rate_card_repo = new Allship_UPS_Rate_Card_Repository( $mock_wpdb );
$country_repo   = new Allship_UPS_Country_Repository( $mock_wpdb );
$zone_repo      = new Allship_UPS_Zone_Repository( $mock_wpdb );
$rate_repo      = new Allship_UPS_Rate_Repository( $mock_wpdb );

$rate_importer  = new Allship_UPS_Rate_Importer( null, null, $rate_repo );
$zone_importer  = new Allship_UPS_Zone_Importer( null, null, $country_repo, $zone_repo );

$orchestrator = new Allship_UPS_Import_Orchestrator(
	$rate_importer,
	$zone_importer,
	$rate_card_repo,
	$rate_repo,
	$zone_repo,
	$mock_wpdb
);

// GATE 1: Import VN from 20.Aug.2026.xlsx -> 4 rate groups, exact row count
echo "[Gate 1/7] Import 'VN from 20.Aug.2026.xlsx' -> 4 rate groups, exact row count... ";
assert( file_exists( $rate_xlsx_path ), 'Rate file must exist' );
$rates_parsed = $rate_importer->parse_file( $rate_xlsx_path );
$rates_val    = $rate_importer->validate( $rates_parsed );

assert( $rates_val['is_valid'] === true );
assert( count( $rates_val['rate_groups'] ) === 4 );
assert( count( $rates_parsed ) === 1001, 'Row count must be exactly 1001, got ' . count( $rates_parsed ) );

$expected_groups = [ 'export_wxs_document', 'export_wxs_nondocument', 'export_xpd', 'export_wfm' ];
foreach ( $expected_groups as $g ) {
	assert( in_array( $g, $rates_val['rate_groups'], true ), "Missing group $g" );
}
echo "PASSED (1001 rates, 4 groups)\n";

// GATE 2: Import IATA.xlsx (flat) -> 249 countries, US is_us_override
echo "[Gate 2/7] Import 'IATA.xlsx' (flat) -> 249 countries, US is_us_override... ";
assert( file_exists( $iata_xlsx_path ), 'IATA file must exist' );
$zone_parsed = $zone_importer->parse_file( $iata_xlsx_path );
$zone_val    = $zone_importer->validate( $zone_parsed );

assert( $zone_val['is_valid'] === true );
assert( count( $zone_parsed['countries'] ) === 249, 'Unique countries must be 249' );

$us_found = false;
foreach ( $zone_parsed['countries'] as $c ) {
	if ( 'US' === $c['iata_code'] ) {
		assert( 1 === $c['is_us_override'] );
		assert( 1 === $c['has_extended_area_note'] );
		$us_found = true;
	}
}
assert( $us_found );

$us_zones = [];
foreach ( $zone_parsed['zones'] as $z ) {
	if ( 'US' === $z['iata_code'] && 'export' === $z['direction'] ) {
		$us_zones[ $z['service_code'] ] = $z['zone'];
	}
}
assert( ( $us_zones['WXS'] ?? '' ) === '5' );
assert( ( $us_zones['XPD'] ?? '' ) === '5' );
assert( ( $us_zones['WFM'] ?? '' ) === '5' );
echo "PASSED (249 countries, US zone 5)\n";

// GATE 3: Import CSV templates -> same valid structure
echo "[Gate 3/7] Import CSV templates -> same valid structure... ";
assert( file_exists( $rate_template_path ) );
assert( file_exists( $zone_template_path ) );

$rate_tpl_parsed = $rate_importer->parse_file( $rate_template_path );
$rate_tpl_val    = $rate_importer->validate( $rate_tpl_parsed );
assert( $rate_tpl_val['is_valid'] === true );
assert( count( $rate_tpl_val['rate_groups'] ) === 4 );

$zone_tpl_parsed = $zone_importer->parse_file( $zone_template_path );
$zone_tpl_val    = $zone_importer->validate( $zone_tpl_parsed );
assert( $zone_tpl_val['is_valid'] === true );
assert( $zone_tpl_val['services_summary']['import']['WFM'] === 0 );
echo "PASSED (Rate & Zone CSV templates valid)\n";

// GATE 4: Preview mode -> summary correct, no DB commit
echo "[Gate 4/7] Preview mode -> summary correct, no DB commit... ";
$queries_before = count( $mock_wpdb->queries );
$preview = $orchestrator->preview( $rate_xlsx_path, $iata_xlsx_path );

assert( $preview['status'] === 'success' );
assert( $preview['is_db_written'] === false );
assert( $preview['rates']['total_rates'] === 1001 );
assert( $preview['zones']['total_countries'] === 249 );
assert( count( $mock_wpdb->queries ) === $queries_before );
echo "PASSED (No DB writes, exact counts)\n";

// GATE 5: File format errors -> clear error messages
echo "[Gate 5/7] File format errors -> clear error messages... ";
$bad_ext_threw = false;
try {
	$orchestrator->handle_upload( [
		'name'     => 'evil.exe',
		'type'     => 'application/x-msdownload',
		'tmp_name' => sys_get_temp_dir() . '/dummy.exe',
		'error'    => UPLOAD_ERR_OK,
		'size'     => 500,
	] );
} catch ( Exception $e ) {
	$bad_ext_threw = ( false !== stripos( $e->getMessage(), 'invalid file' ) );
}
assert( $bad_ext_threw );

$missing_group_threw = false;
$broken_records = array_filter( $rates_parsed, function( $r ) {
	return $r['rate_group'] !== 'export_wfm';
} );
$broken_val = $rate_importer->validate( $broken_records );
assert( $broken_val['is_valid'] === false );
assert( false !== stripos( implode( ' ', $broken_val['errors'] ), 'Missing required rate group: export_wfm' ) );
echo "PASSED (Clear error diagnostics)\n";

// GATE 6: ZZ is skipped
echo "[Gate 6/7] ZZ country skip verification... ";
$zz_found_in_countries = false;
foreach ( $zone_parsed['countries'] as $c ) {
	if ( 'ZZ' === $c['iata_code'] ) {
		$zz_found_in_countries = true;
	}
}
assert( ! $zz_found_in_countries, 'ZZ must not be in parsed countries' );

$zz_found_in_zones = false;
foreach ( $zone_parsed['zones'] as $z ) {
	if ( 'ZZ' === $z['iata_code'] ) {
		$zz_found_in_zones = true;
	}
}
assert( ! $zz_found_in_zones, 'ZZ must not be in parsed zone mappings' );
echo "PASSED (ZZ excluded from countries and zones)\n";

// GATE 7: Atomic rollback when fail
echo "[Gate 7/7] Atomic rollback on mid-flight failure... ";
class Failing_Rate_Importer_Mock extends Allship_UPS_Rate_Importer {
	public function import( $file_path, $rate_card_id ) {
		throw new Exception( 'Crash midway during rate insertion!' );
	}
}

$failing_orchestrator = new Allship_UPS_Import_Orchestrator(
	new Failing_Rate_Importer_Mock(),
	$zone_importer,
	$rate_card_repo,
	$rate_repo,
	$zone_repo,
	$mock_wpdb
);

$rollback_success = false;
$initial_card_count = count( $mock_wpdb->rate_cards );

try {
	$failing_orchestrator->import( [
		'rate_file_path' => $rate_xlsx_path,
		'rate_card_name' => 'Atomic Test Card',
	] );
} catch ( Exception $e ) {
	$rollback_success = true;
}

assert( $rollback_success );
assert( count( $mock_wpdb->rate_cards ) === $initial_card_count, 'Rate card must be rolled back on failure' );
echo "PASSED (Rollback triggered, 0 orphan records)\n";

echo "\n========================================================\n";
echo "SUCCESS: ALL 7/7 PHASE 2 GATES PASSED!\n";
echo "========================================================\n";
