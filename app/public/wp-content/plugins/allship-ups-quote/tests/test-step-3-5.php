<?php
/**
 * Test Step 3.5 — Quote Calculator (Integration Test).
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/config/service-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-settings-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-card-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-country-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-zone-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-quote-log-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-weight-calculator.php';
require_once dirname( __DIR__ ) . '/includes/class-zone-resolver.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-lookup.php';
require_once dirname( __DIR__ ) . '/includes/class-surcharge-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-quote-calculator.php';

echo "=== START TEST STEP 3.5: QUOTE CALCULATOR (INTEGRATION) ===\n";

class Mock_WPDB_QuoteIntegration {
	public $prefix = 'wp_';
	public $countries = [];
	public $zones = [];
	public $rates = [];
	public $settings = [];
	public $logs = [];
	public $insert_id = 0;

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

	public function get_results( $sql, $output = OBJECT ) {
		// wp_ups_settings
		if ( false !== strpos( $sql, 'ups_settings' ) ) {
			$res = [];
			foreach ( $this->settings as $k => $v ) {
				$res[] = [
					'setting_key'   => $k,
					'setting_value' => (string) $v,
				];
			}
			return $res;
		}
		return [];
	}

	public function get_row( $sql, $output = OBJECT ) {
		// 1. Countries
		if ( false !== strpos( $sql, 'ups_countries' ) ) {
			preg_match( "/iata_code = '([A-Z]{2})'/", $sql, $m );
			if ( ! empty( $m[1] ) && isset( $this->countries[ $m[1] ] ) ) {
				return (object) $this->countries[ $m[1] ];
			}
			return null;
		}

		// 2. Active Rate Card
		if ( false !== strpos( $sql, 'ups_rate_cards' ) ) {
			return (object) [
				'id'         => 1,
				'card_name'  => 'Standard 2026',
				'is_active'  => 1,
				'valid_from' => '2026-01-01',
			];
		}

		// 3. Rates
		if ( false !== strpos( $sql, 'ups_rates' ) ) {
			preg_match( "/rate_card_id = (\d+)/", $sql, $m_rc );
			preg_match( "/rate_group = '([^']+)'/", $sql, $m_rg );
			preg_match( "/zone = '([^']+)'/", $sql, $m_z );

			$rc_id = ! empty( $m_rc[1] ) ? (int) $m_rc[1] : null;
			$rg    = ! empty( $m_rg[1] ) ? $m_rg[1] : null;
			$zone  = ! empty( $m_z[1] ) ? $m_z[1] : null;

			// Flat lookup
			if ( false !== strpos( $sql, "billing_unit = 'flat'" ) ) {
				preg_match( "/weight_to >= ([\d.]+)/", $sql, $m_w );
				$w_target = ! empty( $m_w[1] ) ? (float) $m_w[1] : 0.0;
				$matched  = null;
				foreach ( $this->rates as $r ) {
					if ( ( null === $rc_id || $r->rate_card_id === $rc_id )
						&& ( null === $rg || $r->rate_group === $rg )
						&& ( null === $zone || $r->zone === $zone )
						&& 'flat' === $r->billing_unit
						&& null !== $r->weight_to
						&& $r->weight_to >= $w_target ) {
						if ( null === $matched || $r->weight_to < $matched->weight_to ) {
							$matched = $r;
						}
					}
				}
				return $matched ? clone $matched : null;
			}

			// Per_kg lookup
			if ( false !== strpos( $sql, "billing_unit = 'per_kg'" ) ) {
				preg_match( "/weight_from <= ([\d.]+)/", $sql, $m_wf );
				$w_target = ! empty( $m_wf[1] ) ? (float) $m_wf[1] : 0.0;
				$matched  = null;
				foreach ( $this->rates as $r ) {
					if ( ( null === $rc_id || $r->rate_card_id === $rc_id )
						&& ( null === $rg || $r->rate_group === $rg )
						&& ( null === $zone || $r->zone === $zone )
						&& 'per_kg' === $r->billing_unit
						&& null !== $r->weight_from
						&& $r->weight_from <= $w_target
						&& ( null === $r->weight_to || $r->weight_to >= $w_target ) ) {
						if ( null === $matched || $r->weight_from > $matched->weight_from ) {
							$matched = $r;
						}
					}
				}
				return $matched ? clone $matched : null;
			}
		}

		return null;
	}

	public function get_var( $sql ) {
		// Active rate card ID
		if ( false !== strpos( $sql, 'ups_rate_cards' ) ) {
			return 1;
		}

		// Zone lookup
		if ( false !== strpos( $sql, 'ups_zone_maps' ) ) {
			preg_match( "/rate_card_id = (\d+)\s+AND country_id = (\d+)\s+AND direction = '([a-z]+)'\s+AND service_code = '([A-Z]+)'/", $sql, $m );
			if ( ! empty( $m ) ) {
				$key = "{$m[1]}_{$m[2]}_{$m[3]}_{$m[4]}";
				return isset( $this->zones[ $key ] ) ? $this->zones[ $key ] : null;
			}
		}

		return null;
	}

	public function insert( $table, $data, $format = null ) {
		if ( false !== strpos( $table, 'ups_quote_logs' ) ) {
			$this->insert_id++;
			$this->logs[] = (object) array_merge( [ 'id' => $this->insert_id ], $data );
			return 1;
		}
		return 1;
	}
}

$mock_wpdb = new Mock_WPDB_QuoteIntegration();

// 1. Seed Countries
$mock_wpdb->countries = [
	'US' => [
		'id'                     => 1,
		'iata_code'              => 'US',
		'country_name'           => 'United States*',
		'normalized_name'        => 'United States',
		'is_us_override'         => 1,
		'has_extended_area_note' => 1,
		'is_active'              => 1,
	],
	'CA' => [
		'id'                     => 2,
		'iata_code'              => 'CA',
		'country_name'           => 'Canada*',
		'normalized_name'        => 'Canada',
		'is_us_override'         => 0,
		'has_extended_area_note' => 1,
		'is_active'              => 1,
	],
	'AF' => [
		'id'                     => 3,
		'iata_code'              => 'AF',
		'country_name'           => 'Afghanistan',
		'normalized_name'        => 'Afghanistan',
		'is_us_override'         => 0,
		'has_extended_area_note' => 0,
		'is_active'              => 1,
	],
];

// 2. Seed Zone mappings: {rate_card_id}_{country_id}_{direction}_{service}
$mock_wpdb->zones = [
	// US (country_id 1)
	'1_1_export_WXS' => '5',
	'1_1_export_XPD' => '5',
	'1_1_export_WFM' => '5',
	// CA (country_id 2)
	'1_2_export_WXS' => '5',
	// AF (country_id 3): WXS=9, WFM=0 (lane unavailable)
	'1_3_export_WXS' => '9',
	'1_3_export_WFM' => '0',
];

// 3. Seed Rates
$mock_wpdb->rates = [
	// US5: WXS Non-doc 4.0kg flat = 1400000 VND
	(object) [
		'id'           => 1,
		'rate_card_id' => 1,
		'rate_group'   => 'export_wxs_nondocument',
		'zone'         => 'US5',
		'weight_label' => '4.0',
		'weight_from'  => 4.0,
		'weight_to'    => 4.0,
		'billing_unit' => 'flat',
		'price_vnd'    => 1400000,
	],
	// US5: WXS Non-doc 6.0kg flat = 1977290 VND
	(object) [
		'id'           => 2,
		'rate_card_id' => 1,
		'rate_group'   => 'export_wxs_nondocument',
		'zone'         => 'US5',
		'weight_label' => '6.0',
		'weight_from'  => 6.0,
		'weight_to'    => 6.0,
		'billing_unit' => 'flat',
		'price_vnd'    => 1977290,
	],
	// US5: WXS Document 5.0kg flat = 3510000 VND
	(object) [
		'id'           => 3,
		'rate_card_id' => 1,
		'rate_group'   => 'export_wxs_document',
		'zone'         => 'US5',
		'weight_label' => '5.0',
		'weight_from'  => 5.0,
		'weight_to'    => 5.0,
		'billing_unit' => 'flat',
		'price_vnd'    => 3510000,
	],
];

// 4. Seed Settings
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

// Wire Repositories and Services
$rate_card_repo   = new Allship_UPS_Rate_Card_Repository( $mock_wpdb );
$country_repo     = new Allship_UPS_Country_Repository( $mock_wpdb );
$zone_repo        = new Allship_UPS_Zone_Repository( $mock_wpdb );
$rate_repo        = new Allship_UPS_Rate_Repository( $mock_wpdb );
$quote_log_repo   = new Allship_UPS_Quote_Log_Repository( $mock_wpdb );
$settings_mgr     = new Allship_UPS_Settings_Manager( $mock_wpdb );
$weight_calc      = new Allship_UPS_Weight_Calculator();
$zone_resolver    = new Allship_UPS_Zone_Resolver( $zone_repo, $country_repo, $settings_mgr );
$rate_lookup      = new Allship_UPS_Rate_Lookup( $rate_repo );
$surcharge_engine = new Allship_UPS_Surcharge_Engine( $settings_mgr );

$calc = new Allship_UPS_Quote_Calculator(
	$rate_card_repo,
	$country_repo,
	$zone_resolver,
	$weight_calc,
	$rate_lookup,
	$surcharge_engine,
	$quote_log_repo,
	$settings_mgr
);

// ==========================================
// CASE 1: US WXS Non-doc 6kg
// ==========================================
echo "\n--- Case 1: US WXS Non-doc 6kg ---\n";
$r1 = $calc->calculate([
	'destination_iata' => 'US',
	'service_code'     => 'WXS',
	'shipment_type'    => 'nondocument',
	'pieces'           => [
		[
			'quantity'         => 1,
			'actual_weight_kg' => 6,
			'length_cm'        => 10,
			'width_cm'         => 10,
			'height_cm'        => 10,
		],
	],
]);

assert( $r1->success === true, 'Case 1 must succeed' );
assert( $r1->rate_zone === 'US5', "Expected rate_zone US5, got '{$r1->rate_zone}'" );
assert( $r1->zone === '5', "Expected physical zone 5, got '{$r1->zone}'" );
assert( $r1->chargeable_weight_kg === 6.0, "Expected chargeable_weight_kg 6.0, got {$r1->chargeable_weight_kg}" );
assert( $r1->base_price_vnd > 0, 'base_price_vnd must be > 0' );
assert( $r1->base_price_vnd === 1977290, "Expected 1977290 VND, got {$r1->base_price_vnd}" );
assert( $r1->total_price_vnd === 1977290, "Expected total_price_vnd 1977290, got {$r1->total_price_vnd}" );
echo "✓ Case 1 passed: rate_zone={$r1->rate_zone}, chargeable_weight={$r1->chargeable_weight_kg}kg, base_price={$r1->base_price_vnd} VND\n";

// ==========================================
// CASE 2: Multi-piece 1.3 + 2.1 → 4.0kg
// ==========================================
echo "\n--- Case 2: Multi-piece 1.3 + 2.1 -> 4.0kg ---\n";
$r2 = $calc->calculate([
	'destination_iata' => 'US',
	'service_code'     => 'WXS',
	'shipment_type'    => 'nondocument',
	'pieces'           => [
		[
			'quantity'         => 1,
			'actual_weight_kg' => 1.3,
			'length_cm'        => 10,
			'width_cm'         => 10,
			'height_cm'        => 10,
		],
		[
			'quantity'         => 1,
			'actual_weight_kg' => 2.1,
			'length_cm'        => 10,
			'width_cm'         => 10,
			'height_cm'        => 10,
		],
	],
]);

assert( $r2->success === true, 'Case 2 must succeed' );
// piece 1: 1.3kg -> 1.5kg; piece 2: 2.1kg -> 2.5kg; total = 4.0kg
assert( $r2->chargeable_weight_kg === 4.0, "Expected chargeable_weight_kg 4.0, got {$r2->chargeable_weight_kg}" );
assert( $r2->base_price_vnd === 1400000, "Expected base_price_vnd 1400000, got {$r2->base_price_vnd}" );
echo "✓ Case 2 passed: multi-piece (1.3 + 2.1) -> chargeable_weight={$r2->chargeable_weight_kg}kg\n";

// ==========================================
// CASE 3: Document 6kg → DOCUMENT_OVER_5KG
// ==========================================
echo "\n--- Case 3: Document 6kg -> DOCUMENT_OVER_5KG ---\n";
$r3 = $calc->calculate([
	'destination_iata' => 'US',
	'service_code'     => 'WXS',
	'shipment_type'    => 'document',
	'pieces'           => [
		[
			'quantity'         => 1,
			'actual_weight_kg' => 6.0,
			'length_cm'        => 10,
			'width_cm'         => 10,
			'height_cm'        => 10,
		],
	],
]);

assert( $r3->success === false, 'Case 3 must return error' );
assert( $r3->is_error() === true, 'is_error() must be true' );
assert( $r3->error_code === 'DOCUMENT_OVER_5KG', "Expected DOCUMENT_OVER_5KG, got '{$r3->error_code}'" );
echo "✓ Case 3 passed: Document 6kg returned error '{$r3->error_code}'\n";

// ==========================================
// CASE 4: WFM unavailable → LANE_NOT_AVAILABLE
// ==========================================
echo "\n--- Case 4: WFM unavailable -> LANE_NOT_AVAILABLE ---\n";
$r4 = $calc->calculate([
	'destination_iata' => 'AF',
	'service_code'     => 'WFM',
	'shipment_type'    => 'nondocument',
	'pieces'           => [
		[
			'quantity'         => 1,
			'actual_weight_kg' => 80.0,
			'length_cm'        => 100,
			'width_cm'         => 80,
			'height_cm'        => 80,
		],
	],
]);

assert( $r4->success === false, 'Case 4 must return error' );
assert( $r4->is_error() === true, 'is_error() must be true' );
assert( $r4->error_code === 'LANE_NOT_AVAILABLE', "Expected LANE_NOT_AVAILABLE, got '{$r4->error_code}'" );
echo "✓ Case 4 passed: WFM to AF returned error '{$r4->error_code}'\n";

// ==========================================
// CASE 5: Structured API Response Output
// ==========================================
echo "\n--- Case 5: Structured API response format ---\n";
$response_success = $r1->to_array();
assert( $response_success['success'] === true );
assert( isset( $response_success['data'] ) );
assert( $response_success['data']['service_code'] === 'WXS' );
assert( $response_success['data']['rate_zone'] === 'US5' );
assert( $response_success['data']['destination_name'] === 'United States*' );
assert( is_array( $response_success['data']['notes'] ) );
assert( is_array( $response_success['data']['pieces'] ) );

$response_error = $r4->to_array();
assert( $response_error['success'] === false );
assert( isset( $response_error['error']['code'] ) && $response_error['error']['code'] === 'LANE_NOT_AVAILABLE' );
echo "✓ Case 5 passed: Structured API array format complies with specification\n";

// ==========================================
// CASE 6: Quote Log Persistence Verification
// ==========================================
echo "\n--- Case 6: Quote log persistence ---\n";
assert( count( $mock_wpdb->logs ) > 0, 'Quote logs must have recorded successful calculations' );
$logged = $mock_wpdb->logs[0];
assert( $logged->destination_iata === 'US' );
assert( $logged->service_code === 'WXS' );
assert( $logged->rate_zone === 'US5' );
assert( $logged->base_price_vnd === 1977290 );
echo "✓ Case 6 passed: Quote calculation accurately persisted in wp_ups_quote_logs\n";

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 3.5 PASSED 100%!\n";
echo "============================================\n";
