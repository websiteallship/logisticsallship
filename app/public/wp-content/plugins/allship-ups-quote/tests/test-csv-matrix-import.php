<?php
/**
 * Test CSV Matrix Import and Weight/Calculation Verification
 */

define( 'ABSPATH', __DIR__ . '/../../../../' );
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require_once __DIR__ . '/../includes/class-rate-repository.php';
require_once __DIR__ . '/../includes/importers/class-csv-parser.php';
require_once __DIR__ . '/../includes/importers/class-xlsx-reader.php';
require_once __DIR__ . '/../includes/importers/class-rate-importer.php';

echo "=== START TEST: CSV MATRIX IMPORT & CALCULATION LOGIC ===\n\n";

$csv_path = realpath( __DIR__ . '/../../../../docs_plugin_quote/docs/mau-bang-gia.csv' );
if ( ! file_exists( $csv_path ) ) {
	die( "CSV file not found: $csv_path\n" );
}

$importer = new Allship_UPS_Rate_Importer();

// 1. Detect format
$format = $importer->detect_format( $csv_path );
echo "1. Detect format: $format\n";
assert( $format === 'csv_matrix', "Expected 'csv_matrix', got '$format'" );
echo "✓ Format detected correctly as 'csv_matrix'.\n\n";

// 2. Parse file
$records = $importer->parse_file( $csv_path );
echo "2. Total parsed records: " . count( $records ) . "\n";
assert( count( $records ) === 517, "Expected 517 records, got " . count( $records ) );
echo "✓ Parsed 517 records (47 weight tiers x 11 zones).\n\n";

// 3. Validate
$validation = $importer->validate( $records );
echo "3. Validation:\n";
echo "   Is valid: " . ( $validation['is_valid'] ? 'YES' : 'NO' ) . "\n";
echo "   Errors: " . count( $validation['errors'] ) . "\n";
echo "   Warnings: " . count( $validation['warnings'] ) . "\n";
assert( $validation['is_valid'] === true, "Validation failed!" );
assert( count( $validation['errors'] ) === 0, "Errors found during validation!" );
echo "✓ Validation passed with zero errors.\n\n";

// 4. Verify Weight Brackets & Billing Units in Zone US5
echo "4. Checking Weight Brackets & Billing Units for Zone US5:\n";
$us5_records = array_filter( $records, function( $r ) {
	return $r['zone'] === 'US5';
} );

$expected_checks = [
	'0.5'     => [ 'billing_unit' => 'flat',   'price' => 617141 ],
	'20.0'    => [ 'billing_unit' => 'flat',   'price' => 3242975 ],
	'21-44'   => [ 'billing_unit' => 'per_kg', 'price' => 150003, 'weight_from' => 21.0, 'weight_to' => 44.0 ],
	'45-70'   => [ 'billing_unit' => 'per_kg', 'price' => 150003, 'weight_from' => 45.0, 'weight_to' => 70.0 ],
	'71-99'   => [ 'billing_unit' => 'per_kg', 'price' => 150003, 'weight_from' => 71.0, 'weight_to' => 99.0 ],
	'100-299' => [ 'billing_unit' => 'per_kg', 'price' => 150003, 'weight_from' => 100.0, 'weight_to' => 299.0 ],
	'300-499' => [ 'billing_unit' => 'per_kg', 'price' => 150003, 'weight_from' => 300.0, 'weight_to' => 499.0 ],
	'500-999' => [ 'billing_unit' => 'per_kg', 'price' => 150003, 'weight_from' => 500.0, 'weight_to' => 999.0 ],
	'>1000'   => [ 'billing_unit' => 'per_kg', 'price' => 150003, 'weight_from' => 1000.0001, 'weight_to' => null ],
];

foreach ( $expected_checks as $label => $exp ) {
	$found = null;
	foreach ( $us5_records as $rec ) {
		if ( $rec['weight_label'] === $label ) {
			$found = $rec;
			break;
		}
	}
	assert( $found !== null, "Record for '$label' not found in US5!" );
	assert( $found['billing_unit'] === $exp['billing_unit'], "Billing unit mismatch for '$label'!" );
	assert( $found['price_vnd'] === $exp['price'], "Price mismatch for '$label'!" );
	echo "   ✓ Tier '{$label}': billing_unit='{$found['billing_unit']}', price=" . number_format( $found['price_vnd'] ) . " VND\n";
}
echo "\n";

// 5. Verify Rate Calculation
echo "5. Calculation Verification for Zone US5:\n";

// Mock calculation based on parsed US5 records
function calculate_rate( $chargeable_weight, $records ) {
	// Flat tiers
	$flat_records = array_filter( $records, function( $r ) {
		return $r['billing_unit'] === 'flat';
	} );
	usort( $flat_records, function( $a, $b ) {
		return $a['weight_to'] <=> $b['weight_to'];
	} );

	foreach ( $flat_records as $r ) {
		if ( $r['weight_to'] >= $chargeable_weight ) {
			return [
				'billing_unit' => 'flat',
				'weight_label' => $r['weight_label'],
				'unit_rate'    => $r['price_vnd'],
				'base_rate'    => $r['price_vnd'],
			];
		}
	}

	// Per-kg tiers
	$per_kg_records = array_filter( $records, function( $r ) {
		return $r['billing_unit'] === 'per_kg';
	} );

	$candidates = array_filter( $per_kg_records, function( $r ) use ( $chargeable_weight ) {
		return $r['weight_to'] === null || $r['weight_to'] >= $chargeable_weight;
	} );

	usort( $candidates, function( $a, $b ) {
		if ( $a['weight_to'] === null && $b['weight_to'] === null ) return 0;
		if ( $a['weight_to'] === null ) return 1;
		if ( $b['weight_to'] === null ) return -1;
		return $a['weight_to'] <=> $b['weight_to'];
	} );

	if ( ! empty( $candidates ) ) {
		$matched = reset( $candidates );
		return [
			'billing_unit' => 'per_kg',
			'weight_label' => $matched['weight_label'],
			'unit_rate'    => $matched['price_vnd'],
			'base_rate'    => (int) round( $chargeable_weight * $matched['price_vnd'] ),
		];
	}

	return null;
}

$test_cases = [
	[ 'weight' => 20.0, 'expected_unit' => 'flat',   'expected_bracket' => '20.0',  'expected_base' => 3242975 ],
	[ 'weight' => 20.5, 'expected_unit' => 'per_kg', 'expected_bracket' => '21-44', 'expected_base' => 3075062 ],
	[ 'weight' => 25.0, 'expected_unit' => 'per_kg', 'expected_bracket' => '21-44', 'expected_base' => 3750075 ],
	[ 'weight' => 50.0, 'expected_unit' => 'per_kg', 'expected_bracket' => '45-70', 'expected_base' => 7500150 ],
	[ 'weight' => 80.0, 'expected_unit' => 'per_kg', 'expected_bracket' => '71-99', 'expected_base' => 12000240 ],
	[ 'weight' => 1200.0, 'expected_unit' => 'per_kg', 'expected_bracket' => '>1000', 'expected_base' => 180003600 ],
];

foreach ( $test_cases as $tc ) {
	$calc = calculate_rate( $tc['weight'], $us5_records );
	assert( $calc !== null, "Rate not found for {$tc['weight']}kg" );
	assert( $calc['billing_unit'] === $tc['expected_unit'], "Unit mismatch for {$tc['weight']}kg" );
	assert( $calc['weight_label'] === $tc['expected_bracket'], "Bracket mismatch for {$tc['weight']}kg" );
	assert( $calc['base_rate'] === $tc['expected_base'], "Base rate mismatch for {$tc['weight']}kg: got {$calc['base_rate']}, expected {$tc['expected_base']}" );

	echo sprintf(
		"   ✓ Weight %6.1f kg -> Bracket '%-8s' (%-6s) -> Base Rate: %12s VND (%s)\n",
		$tc['weight'],
		$calc['weight_label'],
		$calc['billing_unit'],
		number_format( $calc['base_rate'] ),
		$calc['billing_unit'] === 'flat' ? 'Flat rate' : "{$tc['weight']} kg x " . number_format( $calc['unit_rate'] )
	);
}

echo "\n============================================\n";
echo "✓ ALL TESTS PASSED 100%!\n";
echo "============================================\n";
