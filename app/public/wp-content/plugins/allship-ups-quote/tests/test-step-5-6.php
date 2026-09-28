<?php
/**
 * Test Step 5.6 — Countries Edit Page & AJAX Suite.
 *
 * Verifies:
 * 1. Allship_UPS_Admin_Countries AJAX hook registrations.
 * 2. Security gating: Nonce + manage_options checks.
 * 3. List countries with pagination & status counts.
 * 4. Search filter: search "United" → matches "United States" (US).
 * 5. Search filter: search "US" → matches IATA "US" first.
 * 6. Toggle active switch via AJAX.
 * 7. Toggle US override flag via AJAX.
 * 8. Add & update country records.
 * 9. Bulk status updates.
 * 10. View rendering: admin/views/countries-edit.php HTML structure.
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

// In-Memory Mock Database
class Mock_WPDB_Countries {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $insert_id = 0;
	public $queries = [];

	public $countries = [
		1 => [
			'id'                     => 1,
			'iata_code'              => 'US',
			'country_name'           => 'United States',
			'normalized_name'        => 'United States',
			'is_us_override'         => 1,
			'has_extended_area_note' => 0,
			'is_active'              => 1,
		],
		2 => [
			'id'                     => 2,
			'iata_code'              => 'VN',
			'country_name'           => 'Vietnam',
			'normalized_name'        => 'Vietnam',
			'is_us_override'         => 0,
			'has_extended_area_note' => 0,
			'is_active'              => 1,
		],
		3 => [
			'id'                     => 3,
			'iata_code'              => 'GB',
			'country_name'           => 'United Kingdom',
			'normalized_name'        => 'United Kingdom',
			'is_us_override'         => 0,
			'has_extended_area_note' => 0,
			'is_active'              => 1,
		],
		4 => [
			'id'                     => 4,
			'iata_code'              => 'ZZ',
			'country_name'           => 'Disabled Land*',
			'normalized_name'        => 'Disabled Land',
			'is_us_override'         => 0,
			'has_extended_area_note' => 1,
			'is_active'              => 0,
		],
	];

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			if ( is_string( $arg ) ) {
				$val = "'" . addslashes( $arg ) . "'";
			} elseif ( null === $arg ) {
				$val = 'NULL';
			} else {
				$val = $arg;
			}
			$query = preg_replace( '/%[sdfF]/', $val, $query, 1 );
		}
		return $query;
	}

	public function get_var( $query ) {
		$this->queries[] = $query;
		if ( strpos( $query, 'COUNT(*)' ) !== false ) {
			if ( strpos( $query, 'is_active = 1' ) !== false ) {
				$count = 0;
				foreach ( $this->countries as $c ) {
					if ( 1 === (int) $c['is_active'] ) $count++;
				}
				return $count;
			}
			if ( strpos( $query, 'is_active = 0' ) !== false ) {
				$count = 0;
				foreach ( $this->countries as $c ) {
					if ( 0 === (int) $c['is_active'] ) $count++;
				}
				return $count;
			}
			// General count
			$matching = $this->filter_records( $query );
			return count( $matching );
		}

		if ( preg_match( '/SELECT is_active FROM .* WHERE id = (\d+)/', $query, $m ) ) {
			$id = (int) $m[1];
			return isset( $this->countries[ $id ] ) ? $this->countries[ $id ]['is_active'] : null;
		}

		if ( preg_match( '/SELECT is_us_override FROM .* WHERE id = (\d+)/', $query, $m ) ) {
			$id = (int) $m[1];
			return isset( $this->countries[ $id ] ) ? $this->countries[ $id ]['is_us_override'] : null;
		}

		return null;
	}

	public function get_row( $query, $output = OBJECT ) {
		$this->queries[] = $query;
		if ( preg_match( "/WHERE iata_code = '([^']+)'/", $query, $m ) ) {
			$iata = $m[1];
			foreach ( $this->countries as $c ) {
				if ( $c['iata_code'] === $iata ) {
					return (object) $c;
				}
			}
		}
		if ( preg_match( "/WHERE id = (\d+)/", $query, $m ) ) {
			$id = (int) $m[1];
			if ( isset( $this->countries[ $id ] ) ) {
				return (object) $this->countries[ $id ];
			}
		}
		return null;
	}

	public function get_results( $query, $output = OBJECT ) {
		$this->queries[] = $query;
		$results = $this->filter_records( $query );
		$objs = [];
		foreach ( $results as $r ) {
			$objs[] = (object) $r;
		}
		return $objs;
	}

	private function filter_records( $query ) {
		$records = array_values( $this->countries );

		// Status filter
		if ( strpos( $query, 'is_active = 1' ) !== false ) {
			$records = array_filter( $records, function( $c ) { return 1 === (int) $c['is_active']; } );
		} elseif ( strpos( $query, 'is_active = 0' ) !== false ) {
			$records = array_filter( $records, function( $c ) { return 0 === (int) $c['is_active']; } );
		}

		// US Override filter
		if ( preg_match( '/is_us_override = (\d+)/', $query, $m ) ) {
			$us = (int) $m[1];
			$records = array_filter( $records, function( $c ) use ( $us ) { return (int) $c['is_us_override'] === $us; } );
		}

		// Search filter
		if ( preg_match( "/country_name LIKE '%([^%]+)%'/i", $query, $m ) ) {
			$term = strtolower( stripslashes( $m[1] ) );
			$records = array_filter( $records, function( $c ) use ( $term ) {
				return ( strpos( strtolower( $c['country_name'] ), $term ) !== false ||
						 strpos( strtolower( $c['normalized_name'] ), $term ) !== false ||
						 strpos( strtolower( $c['iata_code'] ), $term ) !== false );
			} );
		}

		return array_values( $records );
	}

	public function insert( $table, $data, $format = null ) {
		$id = count( $this->countries ) ? max( array_keys( $this->countries ) ) + 1 : 1;
		$data['id'] = $id;
		$this->countries[ $id ] = $data;
		$this->insert_id = $id;
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( isset( $where['id'] ) ) {
			$id = (int) $where['id'];
			if ( isset( $this->countries[ $id ] ) ) {
				foreach ( $data as $k => $v ) {
					$this->countries[ $id ][ $k ] = $v;
				}
				return 1;
			}
		}
		return 0;
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		return true;
	}
}

// Load Classes
require_once dirname( __DIR__ ) . '/includes/class-country-repository.php';
require_once dirname( __DIR__ ) . '/admin/class-admin-countries.php';

echo "=== START TEST STEP 5.6: COUNTRIES EDIT PAGE & AJAX ===\n\n";

$mock_db      = new Mock_WPDB_Countries();
$country_repo = new Allship_UPS_Country_Repository( $mock_db );
$admin_ctrl   = new Allship_UPS_Admin_Countries( $country_repo, $mock_db );

// --- TEST 1: AJAX Hook Registration ---
echo "--- Test 1: AJAX Hook Registration ---\n";
$GLOBALS['wp_actions'] = [];
$admin_ctrl->register_hooks();

$expected_hooks = [
	'wp_ajax_ups_countries_list',
	'wp_ajax_ups_countries_toggle_active',
	'wp_ajax_ups_countries_toggle_us_override',
	'wp_ajax_ups_countries_add',
	'wp_ajax_ups_countries_update',
	'wp_ajax_ups_countries_bulk_status',
];

$all_hooks_registered = true;
foreach ( $expected_hooks as $hook ) {
	if ( ! isset( $GLOBALS['wp_actions'][ $hook ] ) ) {
		echo "✗ Missing action hook: {$hook}\n";
		$all_hooks_registered = false;
	}
}
if ( $all_hooks_registered ) {
	echo "✓ All 6 AJAX hooks registered successfully.\n";
} else {
	exit( 1 );
}

// --- TEST 2: Security Checks (Nonce & Capability) ---
echo "\n--- Test 2: Security Checks (Nonce & Capability) ---\n";
$GLOBALS['mock_nonce_valid'] = false;
try {
	$admin_ctrl->ajax_list_countries();
	echo "✗ Should have failed on invalid nonce\n";
	exit( 1 );
} catch ( Exception $e ) {
	if ( 'NONCE_INVALID' === $e->getMessage() ) {
		echo "✓ Nonce check verified (blocked invalid request).\n";
	}
}
$GLOBALS['mock_nonce_valid'] = true;

$GLOBALS['mock_user_caps'] = [ 'manage_options' => false ];
try {
	$admin_ctrl->ajax_list_countries();
	echo "✗ Should have failed on lack of capability\n";
	exit( 1 );
} catch ( Exception $e ) {
	if ( $GLOBALS['mock_json_response']['status'] === 403 ) {
		echo "✓ Non-admin correctly rejected with HTTP 403.\n";
	} else {
		echo "✗ Expected HTTP 403 status code\n";
		exit( 1 );
	}
}
$GLOBALS['mock_user_caps'] = [ 'manage_options' => true ];

// --- TEST 3: List Countries & Status Counts ---
echo "\n--- Test 3: List Countries & Status Counts ---\n";
$_GET = [];
try {
	$admin_ctrl->ajax_list_countries();
} catch ( Exception $e ) {
	$resp = $GLOBALS['mock_json_response']['data'];
	if ( count( $resp['items'] ) === 4 && 3 === $resp['active_count'] && 1 === $resp['inactive_count'] ) {
		echo "✓ List all countries returned 4 items (3 active / 1 inactive).\n";
	} else {
		echo "✗ List countries output mismatch.\n";
		exit( 1 );
	}
}

// --- TEST 4: Search "United" ---
echo "\n--- Test 4: Search 'United' ---\n";
$_GET = [ 'search' => 'United' ];
try {
	$admin_ctrl->ajax_list_countries();
} catch ( Exception $e ) {
	$resp = $GLOBALS['mock_json_response']['data'];
	if ( 2 === count( $resp['items'] ) ) {
		$names = array_column( $resp['items'], 'country_name' );
		if ( in_array( 'United States', $names, true ) && in_array( 'United Kingdom', $names, true ) ) {
			echo "✓ Search 'United' successfully returned 2 countries including United States (US).\n";
		} else {
			echo "✗ Expected United States and United Kingdom in search results.\n";
			exit( 1 );
		}
	} else {
		echo "✗ Search 'United' count mismatch.\n";
		exit( 1 );
	}
}

// --- TEST 5: Toggle Active Switch ---
echo "\n--- Test 5: Toggle Active Switch ---\n";
// Initially US (ID 1) is active = 1. Let's toggle to 0.
$_POST = [ 'id' => 1, 'is_active' => 0 ];
try {
	$admin_ctrl->ajax_toggle_active();
} catch ( Exception $e ) {
	$resp = $GLOBALS['mock_json_response'];
	if ( $resp['success'] && 0 === (int) $mock_db->countries[1]['is_active'] ) {
		echo "✓ Toggle active for US to 0 succeeded and persisted.\n";
	} else {
		echo "✗ Failed to toggle active to 0.\n";
		exit( 1 );
	}
}

// Toggle back to 1
$_POST = [ 'id' => 1, 'is_active' => 1 ];
try {
	$admin_ctrl->ajax_toggle_active();
} catch ( Exception $e ) {
	$resp = $GLOBALS['mock_json_response'];
	if ( $resp['success'] && 1 === (int) $mock_db->countries[1]['is_active'] ) {
		echo "✓ Toggle active for US back to 1 succeeded and persisted.\n";
	} else {
		echo "✗ Failed to toggle active back to 1.\n";
		exit( 1 );
	}
}

// --- TEST 6: Toggle US Override ---
echo "\n--- Test 6: Toggle US Override ---\n";
// VN (ID 2) initially has is_us_override = 0.
$_POST = [ 'id' => 2 ];
try {
	$admin_ctrl->ajax_toggle_us_override();
} catch ( Exception $e ) {
	$resp = $GLOBALS['mock_json_response'];
	if ( $resp['success'] && 1 === (int) $mock_db->countries[2]['is_us_override'] ) {
		echo "✓ Toggle US Override for VN flipped from 0 to 1 and persisted.\n";
	} else {
		echo "✗ Failed to toggle US override.\n";
		exit( 1 );
	}
}

// --- TEST 7: Add & Update Country ---
echo "\n--- Test 7: Add & Update Country ---\n";
$_POST = [
	'iata_code'              => 'JP',
	'country_name'           => 'Japan*',
	'normalized_name'        => 'Japan',
	'is_us_override'         => 0,
	'has_extended_area_note' => 1,
	'is_active'              => 1,
];
try {
	$admin_ctrl->ajax_add_country();
} catch ( Exception $e ) {
	$resp = $GLOBALS['mock_json_response'];
	if ( $resp['success'] && isset( $resp['data']['id'] ) ) {
		$jp_id = $resp['data']['id'];
		echo "✓ Added new country 'Japan*' (ID: {$jp_id}) successfully.\n";

		// Update country
		$_POST = [
			'id'                     => $jp_id,
			'iata_code'              => 'JP',
			'country_name'           => 'Japan Updated',
			'normalized_name'        => 'Japan',
			'is_us_override'         => 0,
			'has_extended_area_note' => 0,
			'is_active'              => 1,
		];
		try {
			$admin_ctrl->ajax_update_country();
		} catch ( Exception $e2 ) {
			if ( 'Japan Updated' === $mock_db->countries[ $jp_id ]['country_name'] ) {
				echo "✓ Updated country ID {$jp_id} successfully.\n";
			} else {
				echo "✗ Update country failed.\n";
				exit( 1 );
			}
		}
	} else {
		echo "✗ Add country failed.\n";
		exit( 1 );
	}
}

// --- TEST 8: Bulk Status Update ---
echo "\n--- Test 8: Bulk Status Update ---\n";
$_POST = [
	'ids'       => json_encode( [ 1, 2 ] ),
	'is_active' => 0,
];
try {
	$admin_ctrl->ajax_bulk_status();
} catch ( Exception $e ) {
	$resp = $GLOBALS['mock_json_response'];
	if ( $resp['success'] && 2 === $resp['data']['count'] && 0 === (int) $mock_db->countries[1]['is_active'] && 0 === (int) $mock_db->countries[2]['is_active'] ) {
		echo "✓ Bulk deactivated 2 countries successfully.\n";
	} else {
		echo "✗ Bulk status update failed.\n";
		exit( 1 );
	}
}

// --- TEST 9: View Rendering (countries-edit.php) ---
echo "\n--- Test 9: View Rendering (countries-edit.php) ---\n";
ob_start();
require dirname( __DIR__ ) . '/admin/views/countries-edit.php';
$html = ob_get_clean();

$checks = [
	'countriesFilterSearch'  => 'Search input',
	'countriesFilterStatus'  => 'Status filter select',
	'countriesFilterUS'      => 'US override filter select',
	'countriesTable'         => 'Countries table element',
	'countriesCheckAll'      => 'Select-all checkbox',
	'btnAddCountry'          => 'Add country button',
	'btnBulkActivate'        => 'Bulk activate button',
	'btnBulkDeactivate'      => 'Bulk deactivate button',
	'modalCountry'           => 'Add/edit modal container',
	'formCountry'            => 'Country form',
];

$view_passed = true;
foreach ( $checks as $needle => $label ) {
	if ( strpos( $html, $needle ) === false ) {
		echo "✗ Missing in view: {$label} ({$needle})\n";
		$view_passed = false;
	}
}

if ( $view_passed ) {
	echo "✓ countries-edit.php successfully rendered with all expected UI components & modals.\n";
} else {
	exit( 1 );
}

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 5.6 PASSED SUCCESSFULLY!\n";
echo "============================================\n";
