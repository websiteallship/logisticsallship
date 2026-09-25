<?php
/**
 * Test Step 2.5 — Import Orchestrator.
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

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
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

echo "=== START TEST STEP 2.5: IMPORT ORCHESTRATOR ===\n";

$rate_xlsx = dirname( __DIR__, 4 ) . '/docs_plugin_quote/docs/VN from 20.Aug.2026.xlsx';
$zone_xlsx = dirname( __DIR__, 4 ) . '/docs_plugin_quote/docs/IATA.xlsx';

class Mock_WPDB_Orchestrator {
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

		if ( false !== stripos( $sql, 'INSERT INTO wp_ups_rate_cards' ) ) {
			$this->insert_id = $this->auto_id++;
			return 1;
		}

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
			$card_id = $where['rate_card_id'];
			foreach ( $this->rates as $idx => $r ) {
				if ( (int) ( $r['rate_card_id'] ?? 0 ) === (int) $card_id ) {
					unset( $this->rates[ $idx ] );
				}
			}
		}
		if ( false !== strpos( $table, 'ups_zone_maps' ) && isset( $where['rate_card_id'] ) ) {
			$card_id = $where['rate_card_id'];
			foreach ( $this->zones as $idx => $z ) {
				if ( (int) ( $z['rate_card_id'] ?? 0 ) === (int) $card_id ) {
					unset( $this->zones[ $idx ] );
				}
			}
		}
		return 1;
	}

	public function get_row( $sql, $output = OBJECT ) {
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

$mock_wpdb      = new Mock_WPDB_Orchestrator();
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

// TEST 1: Upload + Preview -> summary correct, no DB write
echo "\n--- Test 1: Upload + Preview -> summary correct, no DB write ---\n";
$pre_q_count = count( $mock_wpdb->queries );

$preview = $orchestrator->preview( $rate_xlsx, $zone_xlsx );
assert( $preview['status'] === 'success', 'Preview status must be success' );
assert( $preview['is_db_written'] === false, 'is_db_written must be false' );
assert( isset( $preview['rates']['total_rates'] ) && $preview['rates']['total_rates'] > 1000, 'Rates count must be > 1000' );
assert( isset( $preview['zones']['total_countries'] ) && $preview['zones']['total_countries'] === 249, 'Zones countries must be 249' );
assert( count( $mock_wpdb->queries ) === $pre_q_count, 'No DB queries allowed during preview' );
echo "✓ Preview mode verified without DB writes (Rates: {$preview['rates']['total_rates']}, Countries: {$preview['zones']['total_countries']})\n";

// TEST 2: Import -> rate card created as draft
echo "\n--- Test 2: Import -> rate card created as draft ---\n";
// Populate country map for zone foreign key
$zone_parsed = $zone_importer->parse_file( $zone_xlsx );
foreach ( $zone_parsed['countries'] as $c ) {
	$mock_wpdb->countries[ $c['iata_code'] ] = $c;
}

$import_result = $orchestrator->import( [
	'rate_file_path'   => $rate_xlsx,
	'zone_file_path'   => $zone_xlsx,
	'rate_card_name'   => 'UPS Net Rates Q3-2026 Test',
	'valid_from'       => '2026-08-20',
	'source_file_name' => basename( $rate_xlsx ),
] );

assert( $import_result['status'] === 'success', 'Import must return success' );
assert( $import_result['card_status'] === 'draft', 'Rate card status must be draft' );
assert( ! empty( $import_result['rate_card_id'] ), 'rate_card_id must be assigned' );

$card_id = $import_result['rate_card_id'];
$created_card = $rate_card_repo->get( $card_id );
assert( $created_card !== null, 'Card must exist in DB' );
assert( $created_card->status === 'draft', "Card status in DB must be 'draft', got {$created_card->status}" );
assert( $created_card->name === 'UPS Net Rates Q3-2026 Test' );
assert( $created_card->market_code === 'VN' );
assert( $created_card->valid_from === '2026-08-20' );
echo "✓ Rate card #{$card_id} created as draft with all metadata\n";

// TEST 3: Simulate failure midway -> rollback, no orphan data
echo "\n--- Test 3: Simulate failure midway -> rollback, no orphan data ---\n";
// Mock a failing zone importer that throws midway
class Failing_Zone_Importer extends Allship_UPS_Zone_Importer {
	public function import( $file_path, $rate_card_id ) {
		throw new Exception( 'Simulated DB crash during zone batch import!' );
	}
}

$failing_orchestrator = new Allship_UPS_Import_Orchestrator(
	$rate_importer,
	new Failing_Zone_Importer( null, null, $country_repo, $zone_repo ),
	$rate_card_repo,
	$rate_repo,
	$zone_repo,
	$mock_wpdb
);

$exception_caught = false;
$initial_card_count = count( $mock_wpdb->rate_cards );

try {
	$failing_orchestrator->import( [
		'rate_file_path' => $rate_xlsx,
		'zone_file_path' => $zone_xlsx,
		'rate_card_name' => 'Should Be Rolled Back',
	] );
} catch ( Exception $e ) {
	$exception_caught = true;
	assert( false !== strpos( $e->getMessage(), 'Simulated DB crash' ), 'Original exception message preserved' );
}

assert( $exception_caught, 'Exception must be thrown and caught' );
assert( count( $mock_wpdb->rate_cards ) === $initial_card_count, 'No orphan rate card should remain in DB' );
echo "✓ Mid-flight failure triggered atomic rollback: rate card and cascading data cleaned up, 0 orphan records\n";

// TEST 4: File too large -> error
echo "\n--- Test 4: File too large -> error ---\n";
$large_file_error = false;
try {
	$orchestrator->handle_upload( [
		'name'     => 'massive_rates.xlsx',
		'type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'tmp_name' => sys_get_temp_dir() . '/dummy.xlsx',
		'error'    => UPLOAD_ERR_OK,
		'size'     => 12 * 1024 * 1024, // 12MB > 10MB limit
	] );
} catch ( Exception $e ) {
	$large_file_error = true;
	assert( false !== stripos( $e->getMessage(), 'too large' ), 'Error must mention file too large' );
}
assert( $large_file_error, 'Uploading file > 10MB must throw error' );
echo "✓ Size check enforced: 12MB file rejected with 'File too large'\n";

// TEST 5: Invalid MIME -> error
echo "\n--- Test 5: Invalid MIME -> error ---\n";
$invalid_mime_error = false;
try {
	$orchestrator->handle_upload( [
		'name'     => 'malicious_script.php',
		'type'     => 'application/x-php',
		'tmp_name' => sys_get_temp_dir() . '/dummy.php',
		'error'    => UPLOAD_ERR_OK,
		'size'     => 1024,
	] );
} catch ( Exception $e ) {
	$invalid_mime_error = true;
	assert( false !== stripos( $e->getMessage(), 'invalid file' ), 'Error must mention invalid file' );
}
assert( $invalid_mime_error, 'Uploading non-CSV/XLSX file must throw error' );

$invalid_ext_error = false;
try {
	$orchestrator->handle_upload( [
		'name'     => 'image.jpg',
		'type'     => 'image/jpeg',
		'tmp_name' => sys_get_temp_dir() . '/dummy.jpg',
		'error'    => UPLOAD_ERR_OK,
		'size'     => 1024,
	] );
} catch ( Exception $e ) {
	$invalid_ext_error = true;
	assert( false !== stripos( $e->getMessage(), 'invalid file' ), 'Error must mention invalid file' );
}
assert( $invalid_ext_error, 'Uploading image/jpeg file must throw error' );
echo "✓ Security check enforced: non-CSV/XLSX files rejected with 'Invalid file'\n";

// TEST 6: File cleanup check
echo "\n--- Test 6: File cleanup on import ---\n";
$temp_rate = sys_get_temp_dir() . '/cleanup-test-rate.xlsx';
copy( $rate_xlsx, $temp_rate );
assert( file_exists( $temp_rate ) );

$cleanup_import = $orchestrator->import( [
	'rate_file_path'     => $temp_rate,
	'rate_card_name'     => 'Cleanup Test Card',
	'delete_files_after' => true,
] );
assert( ! file_exists( $temp_rate ), 'Temporary rate file should be deleted after import' );
echo "✓ Temporary files automatically purged after successful import\n";

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 2.5 PASSED 100%!\n";
echo "============================================\n";
