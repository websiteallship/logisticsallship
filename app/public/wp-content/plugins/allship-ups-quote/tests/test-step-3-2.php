<?php
/**
 * Test Step 3.2 — Zone Resolver.
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

require_once dirname( __DIR__ ) . '/includes/class-settings-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-card-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-country-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-zone-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-zone-resolver.php';

echo "=== START TEST STEP 3.2: ZONE RESOLVER ===\n";

class Mock_WPDB_ZoneResolver {
	public $prefix = 'wp_';
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

	public function get_row( $sql, $output = 'OBJECT' ) {
		// Mock country lookup: SELECT * FROM wp_ups_countries WHERE iata_code = %s LIMIT 1
		if ( false !== strpos( $sql, 'ups_countries' ) ) {
			preg_match( "/iata_code = '([A-Z]{2})'/", $sql, $m );
			if ( ! empty( $m[1] ) && isset( $this->countries[ $m[1] ] ) ) {
				return (object) $this->countries[ $m[1] ];
			}
		}
		return null;
	}

	public function get_var( $sql ) {
		// Mock zone lookup: SELECT zone FROM wp_ups_zone_maps WHERE rate_card_id = %d AND country_id = %d AND direction = %s AND service_code = %s
		if ( false !== strpos( $sql, 'ups_zone_maps' ) ) {
			preg_match( "/rate_card_id = (\d+)\s+AND country_id = (\d+)\s+AND direction = '([a-z]+)'\s+AND service_code = '([A-Z]+)'/", $sql, $m );
			if ( ! empty( $m ) ) {
				$rc_id   = (int) $m[1];
				$c_id    = (int) $m[2];
				$dir     = $m[3];
				$svc     = $m[4];
				$key     = "{$rc_id}_{$c_id}_{$dir}_{$svc}";
				return isset( $this->zones[ $key ] ) ? $this->zones[ $key ] : null;
			}
		}
		return null;
	}
}

$mock_wpdb = new Mock_WPDB_ZoneResolver();

// Populate countries
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
	'AE' => [
		'id'                     => 3,
		'iata_code'              => 'AE',
		'country_name'           => 'United Arab Emirates',
		'normalized_name'        => 'United Arab Emirates',
		'is_us_override'         => 0,
		'has_extended_area_note' => 0,
		'is_active'              => 1,
	],
	'AF' => [
		'id'                     => 4,
		'iata_code'              => 'AF',
		'country_name'           => 'Afghanistan',
		'normalized_name'        => 'Afghanistan',
		'is_us_override'         => 0,
		'has_extended_area_note' => 0,
		'is_active'              => 1,
	],
];

$rc_id = 1;

// Populate zone mappings: "{rate_card_id}_{country_id}_{direction}_{service_code}"
$mock_wpdb->zones = [
	// US (id 1): WXS=5, XPD=5, WFM=5
	"{$rc_id}_1_export_WXS" => '5',
	"{$rc_id}_1_export_XPD" => '5',
	"{$rc_id}_1_export_WFM" => '5',
	// CA (id 2): WXS=5, XPD=5, WFM=5
	"{$rc_id}_2_export_WXS" => '5',
	"{$rc_id}_2_export_XPD" => '5',
	"{$rc_id}_2_export_WFM" => '5',
	// AE (id 3): WFM=7, WXS=7
	"{$rc_id}_3_export_WFM" => '7',
	"{$rc_id}_3_export_WXS" => '7',
	// AF (id 4): WXS=9, XPD=9, WFM=0 (or not mapped)
	"{$rc_id}_4_export_WXS" => '9',
	"{$rc_id}_4_export_XPD" => '9',
	"{$rc_id}_4_export_WFM" => '0', // WFM = 0
];

$zone_repo    = new Allship_UPS_Zone_Repository( $mock_wpdb );
$country_repo = new Allship_UPS_Country_Repository( $mock_wpdb );
$settings     = new Allship_UPS_Settings_Manager( $mock_wpdb );

$zr = new Allship_UPS_Zone_Resolver( $zone_repo, $country_repo, $settings );

// TEST 1: US + WXS export -> zone 5, rate_zone US5
echo "\n--- Test 1: US + WXS export -> zone 5, rate_zone US5 ---\n";
$r_us = $zr->resolve( 'export', 'WXS', 'US', $rc_id );
assert( $r_us->success === true, 'US resolution must succeed' );
assert( $r_us->zone === '5', "US physical zone must be '5', got '{$r_us->zone}'" );
assert( $r_us->rate_zone === 'US5', "US rate_zone must be 'US5', got '{$r_us->rate_zone}'" );
assert( $r_us->is_us_override === true, 'is_us_override must be true' );
assert( $r_us->has_extended_area_note === true, 'has_extended_area_note must be true' );
echo "✓ US + WXS export -> zone: {$r_us->zone}, rate_zone: {$r_us->rate_zone}\n";

// TEST 2: CA + WXS export -> zone 5, rate_zone 5
echo "\n--- Test 2: CA + WXS export -> zone 5, rate_zone 5 ---\n";
$r_ca = $zr->resolve( 'export', 'WXS', 'CA', $rc_id );
assert( $r_ca->success === true, 'CA resolution must succeed' );
assert( $r_ca->zone === '5', "CA physical zone must be '5'" );
assert( $r_ca->rate_zone === '5', "CA rate_zone must be '5', got '{$r_ca->rate_zone}'" );
assert( $r_ca->is_us_override === false, 'CA is_us_override must be false' );
echo "✓ CA + WXS export -> zone: {$r_ca->zone}, rate_zone: {$r_ca->rate_zone}\n";

// TEST 3: AE + WFM -> zone 7
echo "\n--- Test 3: AE + WFM -> zone 7 ---\n";
$r_ae = $zr->resolve( 'export', 'WFM', 'AE', $rc_id );
assert( $r_ae->success === true, 'AE resolution must succeed' );
assert( $r_ae->zone === '7', "AE physical zone must be '7', got '{$r_ae->zone}'" );
assert( $r_ae->rate_zone === '7', "AE rate_zone must be '7'" );
echo "✓ AE + WFM export -> zone: {$r_ae->zone}, rate_zone: {$r_ae->rate_zone}\n";

// TEST 4: Country WFM=0 -> LANE_NOT_AVAILABLE
echo "\n--- Test 4: Country WFM=0 -> LANE_NOT_AVAILABLE ---\n";
$r_wfm0 = $zr->resolve( 'export', 'WFM', 'AF', $rc_id );
assert( $r_wfm0->success === false, 'WFM=0 must fail resolution' );
assert( $r_wfm0->is_error() === true );
assert( $r_wfm0->error_code === 'LANE_NOT_AVAILABLE', "Expected LANE_NOT_AVAILABLE, got '{$r_wfm0->error_code}'" );
echo "✓ Country WFM=0 (AF) -> LANE_NOT_AVAILABLE\n";

// TEST 5: XPR -> SERVICE_NOT_SUPPORTED
echo "\n--- Test 5: XPR -> SERVICE_NOT_SUPPORTED ---\n";
$r_xpr = $zr->resolve( 'export', 'XPR', 'US', $rc_id );
assert( $r_xpr->success === false );
assert( $r_xpr->error_code === 'SERVICE_NOT_SUPPORTED', "Expected SERVICE_NOT_SUPPORTED, got '{$r_xpr->error_code}'" );
echo "✓ XPR -> SERVICE_NOT_SUPPORTED\n";

// TEST 6: Unknown country -> UNKNOWN_DESTINATION
echo "\n--- Test 6: Unknown Country -> UNKNOWN_DESTINATION ---\n";
$r_unknown = $zr->resolve( 'export', 'WXS', 'XX', $rc_id );
assert( $r_unknown->success === false );
assert( $r_unknown->error_code === 'UNKNOWN_DESTINATION', "Expected UNKNOWN_DESTINATION, got '{$r_unknown->error_code}'" );
echo "✓ Unknown country (XX) -> UNKNOWN_DESTINATION\n";

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 3.2 PASSED 100%!\n";
echo "============================================\n";
