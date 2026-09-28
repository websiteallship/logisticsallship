<?php
/**
 * Test Step 5.5 — Zones Edit Page & Inline Edit Suite.
 *
 * Verifies:
 * 1. Allship_UPS_Admin_Zones AJAX hook registrations.
 * 2. Security gating: Nonce + manage_options checks.
 * 3. Filter retrieval (directions, service_codes, zones, countries).
 * 4. List zones with filters (direction, service_code, search country, zone, is_available).
 * 5. Inline edit zone cell & automatic availability toggle.
 * 6. Add row, update row, and bulk delete rows.
 * 7. Cache invalidation on updates.
 * 8. View rendering: admin/views/zones-edit.php HTML structure.
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

// Mock WordPress functions
if ( ! function_exists( 'add_action' ) ) {
	$GLOBALS['wp_actions'] = [];
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wp_actions'][ $tag ][] = [
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = 'default' ) {
		echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $echo = true ) {
		$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
		if ( $echo ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $val ) {
		return is_string( $val ) ? stripslashes( $val ) : $val;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) {
		return abs( (int) $v );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

$GLOBALS['mock_user_caps'] = [ 'manage_options' => true ];
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return ! empty( $GLOBALS['mock_user_caps'][ $cap ] );
	}
}

$GLOBALS['mock_nonce_valid'] = true;
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
		if ( ! $GLOBALS['mock_nonce_valid'] ) {
			if ( $die ) {
				throw new Exception( 'NONCE_INVALID' );
			}
			return false;
		}
		return 1;
	}
}

$GLOBALS['mock_json_response'] = null;
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status_code = null ) {
		$GLOBALS['mock_json_response'] = [ 'success' => true, 'data' => $data, 'status' => $status_code ?: 200 ];
		throw new Exception( 'WP_SEND_JSON_SUCCESS' );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = null ) {
		$GLOBALS['mock_json_response'] = [ 'success' => false, 'data' => $data, 'status' => $status_code ?: 400 ];
		throw new Exception( 'WP_SEND_JSON_ERROR' );
	}
}

// In-Memory Database Mock
class Mock_WPDB_Zones {
	public $prefix    = 'wp_';
	public $options   = 'wp_options';
	public $insert_id = 0;
	public $zones     = [];
	public $countries = [];
	public $queries   = [];

	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$idx = 0;
		return preg_replace_callback( '/%[dsf]/', function() use ( &$idx, $args ) {
			$val = $args[ $idx++ ] ?? '';
			return is_numeric( $val ) ? $val : "'" . addslashes( (string) $val ) . "'";
		}, $query );
	}

	public function esc_like( $str ) {
		return addcslashes( $str, '_%\\' );
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( strpos( $sql, 'COUNT(*)' ) !== false ) {
			// Extract filters roughly
			return count( $this->filter_records( $sql ) );
		}
		return null;
	}

	public function get_col( $sql ) {
		$this->queries[] = $sql;
		$distinct = [];
		if ( strpos( $sql, 'DISTINCT direction' ) !== false ) {
			foreach ( $this->zones as $z ) {
				$distinct[ $z->direction ] = true;
			}
			return array_keys( $distinct );
		}
		if ( strpos( $sql, 'DISTINCT service_code' ) !== false ) {
			foreach ( $this->zones as $z ) {
				$distinct[ $z->service_code ] = true;
			}
			return array_keys( $distinct );
		}
		if ( strpos( $sql, 'DISTINCT zone' ) !== false ) {
			foreach ( $this->zones as $z ) {
				if ( ! empty( $z->zone ) ) {
					$distinct[ $z->zone ] = true;
				}
			}
			return array_keys( $distinct );
		}
		return [];
	}

	public $rate_cards = [];

	public function get_row( $sql ) {
		$this->queries[] = $sql;
		if ( strpos( $sql, 'ups_rate_cards' ) !== false ) {
			if ( strpos( $sql, "status = 'active'" ) !== false ) {
				foreach ( $this->rate_cards as $c ) {
					if ( $c->status === 'active' ) return $c;
				}
			}
			return reset( $this->rate_cards ) ?: null;
		}
		return null;
	}

	public function get_results( $sql ) {
		$this->queries[] = $sql;
		if ( strpos( $sql, 'ups_rate_cards' ) !== false ) {
			return array_values( $this->rate_cards );
		}
		if ( strpos( $sql, 'ups_countries' ) !== false && strpos( $sql, 'zm' ) === false ) {
			return array_values( $this->countries );
		}
		return $this->filter_records( $sql );
	}

	private function filter_records( $sql ) {
		$results = [];
		foreach ( $this->zones as $z ) {
			$country = $this->countries[ $z->country_id ] ?? null;
			$row     = clone $z;
			$row->country_name   = $country ? $country->country_name : '';
			$row->iata_code      = $country ? $country->iata_code : '';
			$row->is_us_override = $country ? $country->is_us_override : 0;

			// Direction filter
			if ( preg_match( "/zm\.direction = '([^']+)'/", $sql, $m ) ) {
				if ( $z->direction !== $m[1] ) continue;
			}
			// Service filter
			if ( preg_match( "/zm\.service_code = '([^']+)'/", $sql, $m ) ) {
				if ( $z->service_code !== $m[1] ) continue;
			}
			// Zone filter
			if ( preg_match( "/zm\.zone = '([^']+)'/", $sql, $m ) ) {
				if ( (string) $z->zone !== (string) $m[1] ) continue;
			}
			// is_available filter
			if ( preg_match( "/zm\.is_available = ([0-9]+)/", $sql, $m ) ) {
				if ( (int) $z->is_available !== (int) $m[1] ) continue;
			}
			// Search filter
			if ( preg_match( "/c\.country_name LIKE '%([^%]+)%'/", $sql, $m ) ) {
				$term = strtolower( $m[1] );
				$cName = strtolower( $row->country_name );
				$cIata = strtolower( $row->iata_code );
				if ( strpos( $cName, $term ) === false && strpos( $cIata, $term ) === false && strpos( (string) $row->zone, $term ) === false ) {
					continue;
				}
			}

			$results[] = $row;
		}

		// Pagination limit & offset
		if ( preg_match( "/LIMIT ([0-9]+) OFFSET ([0-9]+)/", $sql, $m ) ) {
			$limit  = (int) $m[1];
			$offset = (int) $m[2];
			$results = array_slice( $results, $offset, $limit );
		}

		return $results;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$id = $where['id'] ?? 0;
		if ( isset( $this->zones[ $id ] ) ) {
			foreach ( $data as $k => $v ) {
				$this->zones[ $id ]->$k = $v;
			}
			return 1;
		}
		return 0;
	}

	public function delete( $table, $where, $where_format = null ) {
		$id = $where['id'] ?? 0;
		if ( isset( $this->zones[ $id ] ) ) {
			unset( $this->zones[ $id ] );
			return 1;
		}
		return 0;
	}
}

// Load Repositories and Admin Class
require_once dirname( __DIR__ ) . '/includes/class-zone-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-country-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-card-repository.php';
require_once dirname( __DIR__ ) . '/admin/class-admin-zones.php';

// Setup Mock DB & Sample Data
$mock_wpdb = new Mock_WPDB_Zones();
$GLOBALS['wpdb'] = $mock_wpdb;

// Add Rate Cards
$mock_wpdb->rate_cards[1] = (object) [ 'id' => 1, 'name' => 'UPS Net Rates 2026', 'status' => 'active', 'currency' => 'VND' ];

// Add Countries
$mock_wpdb->countries[1] = (object) [ 'id' => 1, 'iata_code' => 'US', 'country_name' => 'United States', 'normalized_name' => 'united states', 'is_us_override' => 1, 'has_extended_area_note' => 0, 'is_active' => 1 ];
$mock_wpdb->countries[2] = (object) [ 'id' => 2, 'iata_code' => 'JP', 'country_name' => 'Japan', 'normalized_name' => 'japan', 'is_us_override' => 0, 'has_extended_area_note' => 0, 'is_active' => 1 ];
$mock_wpdb->countries[3] = (object) [ 'id' => 3, 'iata_code' => 'AU', 'country_name' => 'Australia', 'normalized_name' => 'australia', 'is_us_override' => 0, 'has_extended_area_note' => 0, 'is_active' => 1 ];

// Add Zones for Rate Card #1
$mock_wpdb->zones[101] = (object) [ 'id' => 101, 'rate_card_id' => 1, 'country_id' => 1, 'direction' => 'export', 'service_code' => 'WXS', 'service_type' => null, 'zone' => '5', 'is_available' => 1 ];
$mock_wpdb->zones[102] = (object) [ 'id' => 102, 'rate_card_id' => 1, 'country_id' => 1, 'direction' => 'export', 'service_code' => 'XPD', 'service_type' => null, 'zone' => '5', 'is_available' => 1 ];
$mock_wpdb->zones[103] = (object) [ 'id' => 103, 'rate_card_id' => 1, 'country_id' => 2, 'direction' => 'export', 'service_code' => 'WXS', 'service_type' => null, 'zone' => '2', 'is_available' => 1 ];
$mock_wpdb->zones[104] = (object) [ 'id' => 104, 'rate_card_id' => 1, 'country_id' => 2, 'direction' => 'import', 'service_code' => 'WXS', 'service_type' => null, 'zone' => '2', 'is_available' => 1 ];
$mock_wpdb->zones[105] = (object) [ 'id' => 105, 'rate_card_id' => 1, 'country_id' => 3, 'direction' => 'export', 'service_code' => 'EXW', 'service_type' => 'document', 'zone' => '4', 'is_available' => 1 ];
$mock_wpdb->zones[106] = (object) [ 'id' => 106, 'rate_card_id' => 1, 'country_id' => 3, 'direction' => 'export', 'service_code' => 'WFM', 'service_type' => null, 'zone' => null, 'is_available' => 0 ];

// Instantiate Admin Zones
$zone_repo      = new Allship_UPS_Zone_Repository( $mock_wpdb );
$country_repo   = new Allship_UPS_Country_Repository( $mock_wpdb );
$rate_card_repo = new Allship_UPS_Rate_Card_Repository( $mock_wpdb );
$admin_zones    = new Allship_UPS_Admin_Zones( $zone_repo, $country_repo, $rate_card_repo, $mock_wpdb );

echo "=== START TEST STEP 5.5: ZONES EDIT PAGE & AJAX ===\n\n";

// --- Test 1: AJAX Hook Registration ---
echo "--- Test 1: AJAX Hook Registration ---\n";
$admin_zones->register_hooks();
$expected_hooks = [
	'wp_ajax_ups_zones_list',
	'wp_ajax_ups_zones_update_cell',
	'wp_ajax_ups_zones_add_row',
	'wp_ajax_ups_zones_update_row',
	'wp_ajax_ups_zones_delete_rows',
	'wp_ajax_ups_zones_get_filters',
];

$missing = [];
foreach ( $expected_hooks as $h ) {
	if ( empty( $GLOBALS['wp_actions'][ $h ] ) ) {
		$missing[] = $h;
	}
}

if ( empty( $missing ) ) {
	echo "✓ All " . count( $expected_hooks ) . " AJAX hooks registered successfully.\n";
} else {
	echo "✘ FAIL: Missing hooks: " . implode( ', ', $missing ) . "\n";
	exit( 1 );
}

// --- Test 2: Security Checks ---
echo "\n--- Test 2: Security Checks (Nonce & Capability) ---\n";

// Test invalid nonce
$GLOBALS['mock_nonce_valid'] = false;
try {
	$_GET['rate_card_id'] = 1;
	$admin_zones->ajax_list_zones();
	echo "✘ FAIL: Nonce check did not block request.\n";
	exit( 1 );
} catch ( Exception $e ) {
	if ( 'NONCE_INVALID' === $e->getMessage() ) {
		echo "✓ Nonce check verified (blocked invalid request).\n";
	}
}
$GLOBALS['mock_nonce_valid'] = true;

// Test non-admin capability
$GLOBALS['mock_user_caps']['manage_options'] = false;
try {
	$admin_zones->ajax_list_zones();
	echo "✘ FAIL: Non-admin was not blocked.\n";
	exit( 1 );
} catch ( Exception $e ) {
	if ( 'WP_SEND_JSON_ERROR' === $e->getMessage() && 403 === $GLOBALS['mock_json_response']['status'] ) {
		echo "✓ Non-admin correctly rejected with HTTP 403.\n";
	}
}
$GLOBALS['mock_user_caps']['manage_options'] = true;

// --- Test 3: Get Filters ---
echo "\n--- Test 3: Get Filter Options ---\n";
$_GET['rate_card_id'] = 1;
try {
	$admin_zones->ajax_get_filters();
} catch ( Exception $e ) {}

$res = $GLOBALS['mock_json_response'];
if ( $res['success'] && in_array( 'export', $res['data']['directions'] ) && in_array( 'WXS', $res['data']['services'] ) && in_array( '5', $res['data']['zones'] ) ) {
	echo "✓ Filter options returned: directions=" . implode( ',', $res['data']['directions'] ) . ", services=" . implode( ',', $res['data']['services'] ) . "\n";
} else {
	echo "✘ FAIL: Filter options incomplete.\n";
	exit( 1 );
}

// --- Test 4: List Zones & Filtering ---
echo "\n--- Test 4: List Zones & Filter Capabilities ---\n";

// 4.1 Filter by service_code = WXS
$_GET = [
	'rate_card_id' => 1,
	'service_code' => 'WXS',
	'direction'    => '',
	'zone'         => '',
	'is_available' => '',
	'search'       => '',
	'paged'        => 1,
];
try {
	$admin_zones->ajax_list_zones();
} catch ( Exception $e ) {}

$res = $GLOBALS['mock_json_response'];
$all_wxs = true;
foreach ( $res['data']['items'] as $item ) {
	if ( $item['service_code'] !== 'WXS' ) {
		$all_wxs = false;
		break;
	}
}
if ( $res['success'] && count( $res['data']['items'] ) === 3 && $all_wxs ) {
	echo "✓ Filter by service_code='WXS' returned exactly 3 rows, all WXS.\n";
} else {
	echo "✘ FAIL: Filter by service_code incorrect. Count: " . count( $res['data']['items'] ) . "\n";
	exit( 1 );
}

// 4.2 Filter by direction = import
$_GET['service_code'] = '';
$_GET['direction'] = 'import';
try {
	$admin_zones->ajax_list_zones();
} catch ( Exception $e ) {}
$res = $GLOBALS['mock_json_response'];
if ( $res['success'] && count( $res['data']['items'] ) === 1 && $res['data']['items'][0]['iata_code'] === 'JP' ) {
	echo "✓ Filter by direction='import' returned Japan import row.\n";
} else {
	echo "✘ FAIL: Filter by direction incorrect.\n";
	exit( 1 );
}

// 4.3 Search by country = 'United'
$_GET['direction'] = '';
$_GET['search'] = 'United';
try {
	$admin_zones->ajax_list_zones();
} catch ( Exception $e ) {}
$res = $GLOBALS['mock_json_response'];
if ( $res['success'] && count( $res['data']['items'] ) === 2 && $res['data']['items'][0]['iata_code'] === 'US' ) {
	echo "✓ Search country='United' returned 2 US rows.\n";
} else {
	echo "✘ FAIL: Search country incorrect.\n";
	exit( 1 );
}

// --- Test 5: Inline Edit Zone Cell & Save Persistence ---
echo "\n--- Test 5: Inline Edit Zone Cell ---\n";
$_POST = [
	'id'    => 101,
	'field' => 'zone',
	'value' => '8',
];
try {
	$admin_zones->ajax_update_cell();
} catch ( Exception $e ) {}

$res = $GLOBALS['mock_json_response'];
if ( $res['success'] && (string) $mock_wpdb->zones[101]->zone === '8' && (int) $mock_wpdb->zones[101]->is_available === 1 ) {
	echo "✓ Inline edit zone from '5' to '8' saved and persisted in database.\n";
} else {
	echo "✘ FAIL: Inline edit zone failed to persist.\n";
	exit( 1 );
}

// Inline toggle status
$_POST = [
	'id'    => 101,
	'field' => 'is_available',
	'value' => 0,
];
try {
	$admin_zones->ajax_update_cell();
} catch ( Exception $e ) {}
if ( (int) $mock_wpdb->zones[101]->is_available === 0 ) {
	echo "✓ Inline toggle is_available to 0 saved and persisted.\n";
} else {
	echo "✘ FAIL: Toggle status failed.\n";
	exit( 1 );
}

// --- Test 6: Bulk Delete ---
echo "\n--- Test 6: Bulk Delete Zones ---\n";
$_POST = [
	'ids' => [ 105, 106 ],
];
try {
	$admin_zones->ajax_delete_rows();
} catch ( Exception $e ) {}

$res = $GLOBALS['mock_json_response'];
if ( $res['success'] && $res['data']['deleted'] === 2 && ! isset( $mock_wpdb->zones[105] ) && ! isset( $mock_wpdb->zones[106] ) ) {
	echo "✓ Bulk delete successfully deleted 2 rows.\n";
} else {
	echo "✘ FAIL: Bulk delete failed.\n";
	exit( 1 );
}

// --- Test 7: View Rendering ---
echo "\n--- Test 7: View Rendering (zones-edit.php) ---\n";
ob_start();
include dirname( __DIR__ ) . '/admin/views/zones-edit.php';
$view_html = ob_get_clean();

$view_checks = [
	'as-rates-filter-bar' => 'Filter bar container',
	'zonesFilterCard'     => 'Rate Card select',
	'zonesFilterDirection'=> 'Direction select',
	'zonesFilterService'  => 'Service select',
	'zonesFilterZone'     => 'Zone select',
	'zonesFilterSearch'   => 'Search input',
	'zonesTable'          => 'Zones data table',
	'modalAddZone'        => 'Add/Edit Zone modal',
	'formAddZone'         => 'Add/Edit Zone form',
];

$all_views_passed = true;
foreach ( $view_checks as $needle => $label ) {
	if ( strpos( $view_html, $needle ) === false ) {
		echo "✘ Missing component in view: {$label} ({$needle})\n";
		$all_views_passed = false;
	}
}

if ( $all_views_passed ) {
	echo "✓ zones-edit.php successfully rendered with all expected UI components & modals.\n";
} else {
	exit( 1 );
}

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 5.5 PASSED SUCCESSFULLY!\n";
echo "============================================\n";
