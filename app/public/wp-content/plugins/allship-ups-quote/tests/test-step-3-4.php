<?php
/**
 * Test Step 3.4 — Surcharge Engine.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Global filter registry for testing filter hooks without full WordPress core
global $mock_filters;
$mock_filters = [];

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		global $mock_filters;
		if ( ! isset( $mock_filters[ $tag ] ) ) {
			$mock_filters[ $tag ] = [];
		}
		$mock_filters[ $tag ][] = [
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		];
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		global $mock_filters;
		if ( empty( $mock_filters[ $tag ] ) ) {
			return $value;
		}
		foreach ( $mock_filters[ $tag ] as $hook ) {
			$call_args = array_merge( [ $value ], array_slice( $args, 0, $hook['accepted_args'] - 1 ) );
			$value     = call_user_func_array( $hook['callback'], $call_args );
		}
		return $value;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-settings-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-surcharge-engine.php';

echo "=== START TEST STEP 3.4: SURCHARGE ENGINE ===\n";

class Mock_WPDB_Settings {
	public $prefix = 'wp_';
	public $settings = [];

	public function get_results( $sql, $output = 'ARRAY_A' ) {
		$rows = [];
		foreach ( $this->settings as $k => $v ) {
			$rows[] = [
				'setting_key'   => $k,
				'setting_value' => (string) $v,
			];
		}
		return $rows;
	}

	public function get_var( $sql ) {
		return null;
	}
}

$mock_wpdb = new Mock_WPDB_Settings();

// Default settings: all surcharges false (Phase 1)
$mock_wpdb->settings = [
	'dim_divisor'              => '5500',
	'rounding_step_kg'         => '0.5',
	'include_vat'              => 'false',
	'vat_percent'              => '10.0',
	'include_fsc'              => 'false',
	'fsc_percent'              => '0.0',
	'include_surge'            => 'false',
	'surge_percent'            => '0.0',
	'include_customs_fee'      => 'false',
	'customs_fee_vnd'          => '10000',
	'delete_data_on_uninstall' => 'false',
];

Allship_UPS_Settings_Manager::clear_cache();
$settings_mgr = new Allship_UPS_Settings_Manager( $mock_wpdb );
$engine       = new Allship_UPS_Surcharge_Engine( $settings_mgr );

// TEST 1: Default OFF -> $fees = []
echo "\n--- Test 1: Default OFF -> fees is empty array ---\n";
$base_price = 1000000; // 1,000,000 VND
$fees = $engine->calculate( $base_price );
assert( is_array( $fees ), 'Fees must be an array' );
assert( count( $fees ) === 0, 'Default fees must be empty array, got ' . count( $fees ) );
assert( $engine->has_active_surcharges() === false, 'has_active_surcharges() must return false' );
assert( $engine->calculate_grand_total( $base_price, $fees ) === 1000000, 'Grand total must equal base_price when fees empty' );
echo "✓ Default OFF -> fees = []\n";

// TEST 2: VAT ON 10% -> fee added correctly
echo "\n--- Test 2: VAT ON 10% -> VAT fee calculated correctly ---\n";
Allship_UPS_Settings_Manager::clear_cache();
$mock_wpdb->settings['include_vat']   = 'true';
$mock_wpdb->settings['vat_percent']   = '10.0';
$settings_mgr = new Allship_UPS_Settings_Manager( $mock_wpdb );
$engine       = new Allship_UPS_Surcharge_Engine( $settings_mgr );

$fees_vat = $engine->calculate( 1000000 );
assert( count( $fees_vat ) === 1, 'Expected exactly 1 fee (VAT), got ' . count( $fees_vat ) );
$vat_fee = $fees_vat[0];
assert( $vat_fee->code === 'vat', "Fee code must be 'vat', got '{$vat_fee->code}'" );
assert( $vat_fee->amount === 100000, "10% of 1,000,000 must be 100,000 VND, got {$vat_fee->amount}" );
assert( $vat_fee->percent === 10.0, "VAT percent must be 10.0, got {$vat_fee->percent}" );
assert( $vat_fee->type === 'percentage', 'Type must be percentage' );
// ArrayAccess test
assert( $vat_fee['amount'] === 100000, 'ArrayAccess check on amount' );
// JSON serialization test
$json_data = json_decode( json_encode( $vat_fee ), true );
assert( $json_data['code'] === 'vat' );
assert( $json_data['amount'] === 100000 );

$grand_total = $engine->calculate_grand_total( 1000000, $fees_vat );
assert( $grand_total === 1100000, "Grand total must be 1,100,000 VND, got {$grand_total}" );
echo "✓ VAT ON 10% -> fee: 100,000 VND, grand total: 1,100,000 VND\n";

// TEST 3: Filter hook 'allship_ups_surcharges' callable and extensible
echo "\n--- Test 3: Filter hook 'allship_ups_surcharges' ---\n";
add_filter( 'allship_ups_surcharges', function( $current_fees, $base_rate, $input, $pieces ) {
	$current_fees[] = new Allship_UPS_Fee( [
		'code'        => 'extended_area',
		'name'        => 'Phụ phí vùng sâu vùng xa (Extended Area)',
		'amount'      => 150000,
		'percent'     => null,
		'type'        => 'fixed',
		'description' => 'Test filter hook fee',
	] );
	return $current_fees;
}, 10, 4 );

$filtered_fees = $engine->calculate( 1000000 );
$fee_codes     = array_map( function( $f ) { return $f->code; }, $filtered_fees );
assert( in_array( 'extended_area', $fee_codes, true ), 'Filter hook fee must be present in result' );
$ext_fee = null;
foreach ( $filtered_fees as $f ) {
	if ( 'extended_area' === $f->code ) {
		$ext_fee = $f;
		break;
	}
}
assert( $ext_fee !== null && $ext_fee->amount === 150000, 'Extended area fee amount must be 150,000 VND' );
echo "✓ Filter hook 'allship_ups_surcharges' successfully injected custom fee\n";

// Reset filters for subsequent tests
$mock_filters = [];

// TEST 4: Multi-fee compound test (FSC + Customs fee + VAT on compound total)
echo "\n--- Test 4: Compound fees (FSC 20% + Customs 10k + VAT 10%) ---\n";
Allship_UPS_Settings_Manager::clear_cache();
$mock_wpdb->settings['include_fsc']         = 'true';
$mock_wpdb->settings['fsc_percent']         = '20.0';
$mock_wpdb->settings['include_customs_fee'] = 'true';
$mock_wpdb->settings['customs_fee_vnd']     = '10000';
$mock_wpdb->settings['include_vat']         = 'true';
$mock_wpdb->settings['vat_percent']         = '10.0';

$settings_mgr = new Allship_UPS_Settings_Manager( $mock_wpdb );
$engine       = new Allship_UPS_Surcharge_Engine( $settings_mgr );

// Base price: 2,000,000 VND
// FSC 20% = 400,000 VND
// Customs fee = 10,000 VND
// Subtotal before VAT = 2,000,000 + 400,000 + 10,000 = 2,410,000 VND
// VAT 10% = 241,000 VND
// Total fees = 400,000 + 10,000 + 241,000 = 651,000 VND
// Grand total = 2,651,000 VND
$fees_compound = $engine->calculate( 2000000 );
assert( count( $fees_compound ) === 3, 'Expected 3 fees (FSC, Customs, VAT), got ' . count( $fees_compound ) );

$fees_by_code = [];
foreach ( $fees_compound as $f ) {
	$fees_by_code[ $f->code ] = $f;
}

assert( isset( $fees_by_code['fsc'] ) && $fees_by_code['fsc']->amount === 400000, 'FSC must be 400,000 VND' );
assert( isset( $fees_by_code['customs_fee'] ) && $fees_by_code['customs_fee']->amount === 10000, 'Customs fee must be 10,000 VND' );
assert( isset( $fees_by_code['vat'] ) && $fees_by_code['vat']->amount === 241000, 'VAT must be 241,000 VND' );

$compound_total = $engine->calculate_grand_total( 2000000, $fees_compound );
assert( $compound_total === 2651000, "Grand total must be 2,651,000 VND, got {$compound_total}" );
echo "✓ Compound fees verified: FSC (400k) + Customs (10k) + VAT (241k) = 651k fees -> Total 2,651,000 VND\n";

// TEST 5: Object input support (RateResult or stdClass)
echo "\n--- Test 5: Object input with price_vnd ---\n";
$rate_obj = (object) [ 'price_vnd' => 1000000, 'rate_group' => 'export_wxs_nondocument' ];
$fees_obj = $engine->calculate( $rate_obj );
assert( count( $fees_obj ) === 3, 'Should accept object with price_vnd' );
echo "✓ Object input with price_vnd parsed correctly\n";

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 3.4 PASSED 100%!\n";
echo "============================================\n";
