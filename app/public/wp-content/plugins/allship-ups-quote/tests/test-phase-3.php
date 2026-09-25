<?php
/**
 * Phase 3 Verification Test Suite for Allship UPS Quote Plugin.
 *
 * Verifies all 8 Gate criteria for Step 3.6:
 * Gate 1: Weight Calculator: 5/5 test cases pass
 * Gate 2: Zone Resolver: 5/5 test cases pass (including US5 override)
 * Gate 3: Rate Lookup: 5/5 test cases pass
 * Gate 4: Surcharge Engine: default OFF, extensible
 * Gate 5: Quote Calculator: 4/4 integration cases pass
 * Gate 6: Quote log written correctly
 * Gate 7: Multi-piece calculation tính từng kiện riêng
 * Gate 8: WFM minimum logic đúng
 *
 * Run with: php tests/test-phase-3.php
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

echo "====================================================\n";
echo "=== ALLSHIP UPS QUOTE - PHASE 3 VERIFICATION GATE ===\n";
echo "====================================================\n\n";

class Allship_UPS_Phase3_Mock_WPDB {
	public $prefix    = 'wp_';
	public $insert_id = 0;

	public $settings   = [];
	public $rate_cards = [];
	public $countries  = [];
	public $zone_maps  = [];
	public $rates      = [];
	public $quote_logs = [];

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
		if ( false !== strpos( $sql, 'ups_countries' ) ) {
			preg_match( "/iata_code = '([A-Z]{2})'/", $sql, $m );
			if ( ! empty( $m[1] ) && isset( $this->countries[ $m[1] ] ) ) {
				return (object) $this->countries[ $m[1] ];
			}
			return null;
		}

		if ( false !== strpos( $sql, 'ups_rate_cards' ) ) {
			return (object) [
				'id'         => 1,
				'card_name'  => 'Standard Rate Card 2026',
				'is_active'  => 1,
				'valid_from' => '2026-01-01',
			];
		}

		if ( false !== strpos( $sql, 'ups_rates' ) ) {
			preg_match( "/rate_card_id = (\d+)/", $sql, $m_rc );
			preg_match( "/rate_group = '([^']+)'/", $sql, $m_rg );
			preg_match( "/zone = '([^']+)'/", $sql, $m_z );

			$rc_id = ! empty( $m_rc[1] ) ? (int) $m_rc[1] : null;
			$rg    = ! empty( $m_rg[1] ) ? $m_rg[1] : null;
			$zone  = ! empty( $m_z[1] ) ? $m_z[1] : null;

			// Envelope
			if ( false !== strpos( $sql, "billing_unit = 'envelope'" ) || false !== strpos( $sql, "UPS Envelope" ) ) {
				foreach ( $this->rates as $r ) {
					if ( ( null === $rc_id || $r->rate_card_id === $rc_id )
						&& ( null === $rg || $r->rate_group === $rg )
						&& ( null === $zone || $r->zone === $zone )
						&& ( 'envelope' === $r->billing_unit || 'UPS Envelope' === $r->weight_label ) ) {
						return clone $r;
					}
				}
				return null;
			}

			// Minimum
			if ( false !== strpos( $sql, "billing_unit = 'minimum'" ) ) {
				foreach ( $this->rates as $r ) {
					if ( ( null === $rc_id || $r->rate_card_id === $rc_id )
						&& ( null === $rg || $r->rate_group === $rg )
						&& ( null === $zone || $r->zone === $zone )
						&& 'minimum' === $r->billing_unit ) {
						return clone $r;
					}
				}
				return null;
			}

			// Flat
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

			// Per_kg
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
		if ( false !== strpos( $sql, 'ups_rate_cards' ) ) {
			return 1;
		}

		if ( false !== strpos( $sql, 'ups_zone_maps' ) ) {
			preg_match( "/rate_card_id = (\d+)\s+AND country_id = (\d+)\s+AND direction = '([a-z]+)'\s+AND service_code = '([A-Z]+)'/", $sql, $m );
			if ( ! empty( $m ) ) {
				$key = "{$m[1]}_{$m[2]}_{$m[3]}_{$m[4]}";
				return isset( $this->zone_maps[ $key ] ) ? $this->zone_maps[ $key ] : null;
			}
		}

		return null;
	}

	public function insert( $table, $data, $format = null ) {
		if ( false !== strpos( $table, 'ups_quote_logs' ) ) {
			$this->insert_id++;
			$this->quote_logs[] = (object) array_merge( [ 'id' => $this->insert_id ], $data );
			return 1;
		}
		return 1;
	}
}

$mock_wpdb = new Allship_UPS_Phase3_Mock_WPDB();

// Populate Master Data
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

$mock_wpdb->countries = [
	'US' => [ 'id' => 1, 'iata_code' => 'US', 'country_name' => 'United States*', 'normalized_name' => 'United States', 'is_us_override' => 1, 'has_extended_area_note' => 1, 'is_active' => 1 ],
	'CA' => [ 'id' => 2, 'iata_code' => 'CA', 'country_name' => 'Canada*', 'normalized_name' => 'Canada', 'is_us_override' => 0, 'has_extended_area_note' => 1, 'is_active' => 1 ],
	'AE' => [ 'id' => 3, 'iata_code' => 'AE', 'country_name' => 'United Arab Emirates', 'normalized_name' => 'United Arab Emirates', 'is_us_override' => 0, 'has_extended_area_note' => 0, 'is_active' => 1 ],
	'AF' => [ 'id' => 4, 'iata_code' => 'AF', 'country_name' => 'Afghanistan', 'normalized_name' => 'Afghanistan', 'is_us_override' => 0, 'has_extended_area_note' => 0, 'is_active' => 1 ],
];

$rc_id = 1;
$mock_wpdb->zone_maps = [
	"{$rc_id}_1_export_WXS" => '5',
	"{$rc_id}_1_export_XPD" => '5',
	"{$rc_id}_1_export_WFM" => '5',
	"{$rc_id}_2_export_WXS" => '5',
	"{$rc_id}_3_export_WFM" => '7',
	"{$rc_id}_4_export_WXS" => '9',
	"{$rc_id}_4_export_WFM" => '0',
];

$mock_wpdb->rates = [
	// WXS Document US5
	(object) [ 'id' => 1, 'rate_card_id' => $rc_id, 'rate_group' => 'export_wxs_document', 'zone' => 'US5', 'weight_label' => '1.0', 'weight_from' => 1.0, 'weight_to' => 1.0, 'billing_unit' => 'flat', 'price_vnd' => 1274248 ],
	(object) [ 'id' => 2, 'rate_card_id' => $rc_id, 'rate_group' => 'export_wxs_document', 'zone' => 'US5', 'weight_label' => '5.0', 'weight_from' => 5.0, 'weight_to' => 5.0, 'billing_unit' => 'flat', 'price_vnd' => 3510000 ],
	// WXS Non-doc Zone 5
	(object) [ 'id' => 3, 'rate_card_id' => $rc_id, 'rate_group' => 'export_wxs_nondocument', 'zone' => '5', 'weight_label' => '6.0', 'weight_from' => 6.0, 'weight_to' => 6.0, 'billing_unit' => 'flat', 'price_vnd' => 1686981 ],
	(object) [ 'id' => 4, 'rate_card_id' => $rc_id, 'rate_group' => 'export_wxs_nondocument', 'zone' => '5', 'weight_label' => '21-44', 'weight_from' => 21.0, 'weight_to' => 44.0, 'billing_unit' => 'per_kg', 'price_vnd' => 153636 ],
	// WXS Non-doc US5
	(object) [ 'id' => 5, 'rate_card_id' => $rc_id, 'rate_group' => 'export_wxs_nondocument', 'zone' => 'US5', 'weight_label' => '4.0', 'weight_from' => 4.0, 'weight_to' => 4.0, 'billing_unit' => 'flat', 'price_vnd' => 1400000 ],
	(object) [ 'id' => 6, 'rate_card_id' => $rc_id, 'rate_group' => 'export_wxs_nondocument', 'zone' => 'US5', 'weight_label' => '6.0', 'weight_from' => 6.0, 'weight_to' => 6.0, 'billing_unit' => 'flat', 'price_vnd' => 1977290 ],
	// WFM Zone 5
	(object) [ 'id' => 7, 'rate_card_id' => $rc_id, 'rate_group' => 'export_wfm', 'zone' => '5', 'weight_label' => 'Minimum', 'weight_from' => null, 'weight_to' => null, 'billing_unit' => 'minimum', 'price_vnd' => 13089824 ],
	(object) [ 'id' => 8, 'rate_card_id' => $rc_id, 'rate_group' => 'export_wfm', 'zone' => '5', 'weight_label' => '71-99', 'weight_from' => 71.0, 'weight_to' => 99.0, 'billing_unit' => 'per_kg', 'price_vnd' => 184363 ],
];

Allship_UPS_Settings_Manager::clear_cache();

// Services
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
$calc             = new Allship_UPS_Quote_Calculator(
	$rate_card_repo,
	$country_repo,
	$zone_resolver,
	$weight_calc,
	$rate_lookup,
	$surcharge_engine,
	$quote_log_repo,
	$settings_mgr
);

$passed_gates = 0;
$total_gates  = 8;

// ================================================================
// GATE 1: Weight Calculator: 5/5 test cases pass
// ================================================================
echo "[Gate 1/8] Weight Calculator 5/5 cases: ";
$pw1 = $weight_calc->calculate_piece( 3.2, 30, 20, 15, 5500, 0.5 ); // 3.2 vs 1.636 -> 3.5
assert( $pw1['chargeable_weight_kg'] === 3.5 );

$pw2 = $weight_calc->calculate_piece( 1.0, 25, 20, 15, 5500, 0.5 ); // 1.0 vs 1.364 -> 1.5
assert( $pw2['chargeable_weight_kg'] === 1.5 );

$pw3 = $weight_calc->calculate_piece( 2.5, 10, 10, 10, 5500, 0.5 ); // 2.5 -> 2.5
assert( $pw3['chargeable_weight_kg'] === 2.5 );

$pw4 = $weight_calc->calculate_piece( 0.1, 10, 10, 10, 5500, 0.5 ); // 0.1 -> 0.5
assert( $pw4['chargeable_weight_kg'] === 0.5 );

$pieces_res = $weight_calc->calculate_pieces( [
	[ 'actual_weight_kg' => 1.3, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10 ],
	[ 'actual_weight_kg' => 2.1, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10 ],
], 5500, 0.5 );
$total_cw = array_sum( array_column( $pieces_res, 'chargeable_weight_kg' ) );
assert( $total_cw === 4.0 ); // 1.5 + 2.5 = 4.0
$passed_gates++;
echo "PASSED (5/5 cases)\n";

// ================================================================
// GATE 2: Zone Resolver: 5/5 test cases pass (including US5)
// ================================================================
echo "[Gate 2/8] Zone Resolver 5/5 cases (including US5): ";
$zr_us = $zone_resolver->resolve( 'export', 'WXS', 'US', $rc_id );
assert( $zr_us->success === true && $zr_us->rate_zone === 'US5' && $zr_us->zone === '5' && $zr_us->is_us_override === true );

$zr_ca = $zone_resolver->resolve( 'export', 'WXS', 'CA', $rc_id );
assert( $zr_ca->success === true && $zr_ca->rate_zone === '5' && $zr_ca->is_us_override === false );

$zr_ae = $zone_resolver->resolve( 'export', 'WFM', 'AE', $rc_id );
assert( $zr_ae->success === true && $zr_ae->rate_zone === '7' );

$zr_af = $zone_resolver->resolve( 'export', 'WFM', 'AF', $rc_id );
assert( $zr_af->success === false && $zr_af->error_code === 'LANE_NOT_AVAILABLE' );

$zr_unk = $zone_resolver->resolve( 'export', 'WXS', 'ZZ', $rc_id );
assert( $zr_unk->success === false && $zr_unk->error_code === 'UNKNOWN_DESTINATION' );
$passed_gates++;
echo "PASSED (5/5 cases)\n";

// ================================================================
// GATE 3: Rate Lookup: 5/5 test cases pass
// ================================================================
echo "[Gate 3/8] Rate Lookup 5/5 cases: ";
// 1. WXS Doc 1kg US -> US5: 1274248
$rl1 = $rate_lookup->find_price( $rc_id, 'export_wxs_document', 'US5', 1.0, false );
assert( $rl1->success === true && $rl1->price_vnd === 1274248 );

// 2. WXS Non-doc 6kg Zone 5: 1686981
$rl2 = $rate_lookup->find_price( $rc_id, 'export_wxs_nondocument', '5', 6.0, false );
assert( $rl2->success === true && $rl2->price_vnd === 1686981 );

// 3. WXS Non-doc 25kg Zone 5: 25 * 153636 = 3840900
$rl3 = $rate_lookup->find_price( $rc_id, 'export_wxs_nondocument', '5', 25.0, false );
assert( $rl3->success === true && $rl3->price_vnd === 3840900 );

// 4. WFM 80kg: max(13089824, 80 * 184363 = 14749040) = 14749040
$rl4 = $rate_lookup->find_price( $rc_id, 'export_wfm', '5', 80.0, false );
assert( $rl4->success === true && $rl4->price_vnd === 14749040 );

// 5. Doc 6kg -> error DOCUMENT_OVER_5KG
$rl5 = $rate_lookup->find_price( $rc_id, 'export_wxs_document', 'US5', 6.0, false );
assert( $rl5->success === false && $rl5->error_code === 'DOCUMENT_OVER_5KG' );
$passed_gates++;
echo "PASSED (5/5 cases)\n";

// ================================================================
// GATE 4: Surcharge Engine: default OFF, extensible
// ================================================================
echo "[Gate 4/8] Surcharge Engine default OFF & extensible: ";
$fees_def = $surcharge_engine->calculate( 1000000 );
assert( is_array( $fees_def ) && empty( $fees_def ), 'Default surcharges must be empty' );

// Extensible filter hook test
add_filter( 'allship_ups_surcharges', function( $f ) {
	$f[] = new Allship_UPS_Fee( [ 'code' => 'test_fee', 'amount' => 50000 ] );
	return $f;
} );
$fees_hook = $surcharge_engine->calculate( 1000000 );
assert( count( $fees_hook ) === 1 && $fees_hook[0]->code === 'test_fee' );
$mock_filters = []; // clear filter
$passed_gates++;
echo "PASSED\n";

// ================================================================
// GATE 5: Quote Calculator: 4/4 integration cases pass
// ================================================================
echo "[Gate 5/8] Quote Calculator 4/4 integration cases: ";
// Case 1: US WXS Non-doc 6kg
$qc1 = $calc->calculate([
	'destination_iata' => 'US',
	'service_code'     => 'WXS',
	'shipment_type'    => 'nondocument',
	'pieces'           => [ [ 'quantity' => 1, 'actual_weight_kg' => 6, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10 ] ],
]);
assert( $qc1->rate_zone === 'US5' && $qc1->chargeable_weight_kg === 6.0 && $qc1->base_price_vnd === 1977290 );

// Case 2: Multi-piece 1.3 + 2.1 -> 4.0
$qc2 = $calc->calculate([
	'destination_iata' => 'US',
	'service_code'     => 'WXS',
	'shipment_type'    => 'nondocument',
	'pieces'           => [
		[ 'actual_weight_kg' => 1.3, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10 ],
		[ 'actual_weight_kg' => 2.1, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10 ],
	],
]);
assert( $qc2->chargeable_weight_kg === 4.0 );

// Case 3: Document 6kg -> DOCUMENT_OVER_5KG
$qc3 = $calc->calculate([
	'destination_iata' => 'US',
	'service_code'     => 'WXS',
	'shipment_type'    => 'document',
	'pieces'           => [ [ 'actual_weight_kg' => 6.0, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10 ] ],
]);
assert( $qc3->is_error() && $qc3->error_code === 'DOCUMENT_OVER_5KG' );

// Case 4: WFM unavailable -> LANE_NOT_AVAILABLE
$qc4 = $calc->calculate([
	'destination_iata' => 'AF',
	'service_code'     => 'WFM',
	'pieces'           => [ [ 'actual_weight_kg' => 80.0, 'length_cm' => 50, 'width_cm' => 50, 'height_cm' => 50 ] ],
]);
assert( $qc4->is_error() && $qc4->error_code === 'LANE_NOT_AVAILABLE' );
$passed_gates++;
echo "PASSED (4/4 cases)\n";

// ================================================================
// GATE 6: Quote log written correctly
// ================================================================
echo "[Gate 6/8] Quote log written correctly: ";
assert( count( $mock_wpdb->quote_logs ) >= 2, 'Logs must be saved in database' );
$last_log = end( $mock_wpdb->quote_logs );
assert( ! empty( $last_log->destination_iata ) );
assert( isset( $last_log->base_price_vnd ) );
assert( isset( $last_log->chargeable_weight_kg ) );
$passed_gates++;
echo "PASSED (" . count( $mock_wpdb->quote_logs ) . " logs recorded)\n";

// ================================================================
// GATE 7: Multi-piece calculation tính từng kiện riêng
// ================================================================
echo "[Gate 7/8] Multi-piece calculation tính từng kiện riêng: ";
// Individual: 1.3kg -> 1.5kg, 2.1kg -> 2.5kg => Sum = 4.0kg.
// If wrongly calculated as sum first: 1.3 + 2.1 = 3.4kg -> rounded to 3.5kg.
assert( $qc2->chargeable_weight_kg === 4.0, "Must be 4.0kg (1.5 + 2.5), not 3.5kg" );
$passed_gates++;
echo "PASSED (1.5 + 2.5 = 4.0kg)\n";

// ================================================================
// GATE 8: WFM minimum logic đúng
// ================================================================
echo "[Gate 8/8] WFM minimum logic: ";
// 71kg * 184363 = 13089773 < minimum 13089824 -> takes minimum
$rl_min = $rate_lookup->find_price( $rc_id, 'export_wfm', '5', 71.0, false );
assert( $rl_min->price_vnd === 13089824, "Must enforce minimum of 13089824 VND, got {$rl_min->price_vnd}" );

// 80kg * 184363 = 14749040 > minimum 13089824 -> takes per_kg total
$rl_abv = $rate_lookup->find_price( $rc_id, 'export_wfm', '5', 80.0, false );
assert( $rl_abv->price_vnd === 14749040, "Must use per_kg calculation of 14749040 VND, got {$rl_abv->price_vnd}" );
$passed_gates++;
echo "PASSED (min applied when below, per_kg applied when above)\n";

echo "\n====================================================\n";
echo "RESULT: {$passed_gates}/{$total_gates} GATES PASSED (100%)\n";
echo "STATUS: READY FOR PHASE 4!\n";
echo "====================================================\n";
