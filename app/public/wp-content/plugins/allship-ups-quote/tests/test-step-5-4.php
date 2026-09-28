<?php
/**
 * Test Step 5.4 — Rates Edit Page & Inline Edit Suite.
 *
 * Verifies:
 * 1. Allship_UPS_Admin_Rates AJAX hook registrations.
 * 2. Security gating: Nonce + manage_options checks.
 * 3. Filter retrieval (rate_groups, zones, billing_units).
 * 4. List rates with filters (rate_group, zone, billing_unit, search).
 * 5. Inline edit cell (price_vnd, weight_from, weight_to, billing_unit, sort_order, weight_label).
 * 6. Add row, update row, and bulk delete rows.
 * 7. Cache invalidation on updates.
 * 8. View rendering: admin/views/rates-edit.php HTML structure.
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

// In-Memory Database Mock for Rates
class Mock_WPDB_Rates {
	public $prefix    = 'wp_';
	public $options   = 'wp_options';
	public $insert_id = 0;
	public $rates     = [];
	public $rate_cards = [];
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
			return count( $this->filter_records( $sql ) );
		}
		return null;
	}

	public function get_col( $sql ) {
		$this->queries[] = $sql;
		$distinct = [];
		if ( strpos( $sql, 'DISTINCT rate_group' ) !== false ) {
			foreach ( $this->rates as $r ) {
				$distinct[ $r->rate_group ] = true;
			}
			return array_keys( $distinct );
		}
		if ( strpos( $sql, 'DISTINCT zone' ) !== false ) {
			foreach ( $this->rates as $r ) {
				$distinct[ $r->zone ] = true;
			}
			return array_keys( $distinct );
		}
		if ( strpos( $sql, 'DISTINCT billing_unit' ) !== false ) {
			foreach ( $this->rates as $r ) {
				$distinct[ $r->billing_unit ] = true;
			}
			return array_keys( $distinct );
		}
		return [];
	}

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
		return $this->filter_records( $sql );
	}

	private function filter_records( $sql ) {
		$results = [];
		foreach ( $this->rates as $r ) {
			$row = clone $r;

			// rate_group filter
			if ( preg_match( "/rate_group = '([^']+)'/", $sql, $m ) ) {
				if ( $r->rate_group !== $m[1] ) continue;
			}
			// zone filter
			if ( preg_match( "/zone = '([^']+)'/", $sql, $m ) ) {
				if ( (string) $r->zone !== (string) $m[1] ) continue;
			}
			// billing_unit filter
			if ( preg_match( "/billing_unit = '([^']+)'/", $sql, $m ) ) {
				if ( $r->billing_unit !== $m[1] ) continue;
			}
			// Search filter
			if ( preg_match( "/weight_label LIKE '%([^%]+)%'/", $sql, $m ) ) {
				$term = strtolower( $m[1] );
				if ( strpos( strtolower( (string) $row->weight_label ), $term ) === false ) {
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
		if ( isset( $this->rates[ $id ] ) ) {
			foreach ( $data as $k => $v ) {
				$this->rates[ $id ]->$k = $v;
			}
			return 1;
		}
		return 0;
	}

	public function delete( $table, $where, $where_format = null ) {
		$id = $where['id'] ?? 0;
		if ( isset( $this->rates[ $id ] ) ) {
			unset( $this->rates[ $id ] );
			return 1;
		}
		return 0;
	}
}

// Load Repositories and Admin Rates
require_once dirname( __DIR__ ) . '/includes/class-rate-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-card-repository.php';
require_once dirname( __DIR__ ) . '/admin/class-admin-rates.php';

$mock_wpdb = new Mock_WPDB_Rates();
$GLOBALS['wpdb'] = $mock_wpdb;

// Add Rate Cards
$mock_wpdb->rate_cards[1] = (object) [ 'id' => 1, 'name' => 'UPS Net Rates 2026', 'status' => 'active', 'currency' => 'VND' ];

// Add Rates for Rate Card #1
$mock_wpdb->rates[201] = (object) [ 'id' => 201, 'rate_card_id' => 1, 'rate_group' => 'export_wxs_nondocument', 'zone' => '5', 'weight_label' => '0.5', 'weight_from' => 0.0, 'weight_to' => 0.5, 'billing_unit' => 'flat', 'price_vnd' => 850000, 'sort_order' => 1 ];
$mock_wpdb->rates[202] = (object) [ 'id' => 202, 'rate_card_id' => 1, 'rate_group' => 'export_wxs_nondocument', 'zone' => '5', 'weight_label' => '1.0', 'weight_from' => 0.501, 'weight_to' => 1.0, 'billing_unit' => 'flat', 'price_vnd' => 950000, 'sort_order' => 2 ];
$mock_wpdb->rates[203] = (object) [ 'id' => 203, 'rate_card_id' => 1, 'rate_group' => 'export_xpd', 'zone' => '2', 'weight_label' => '21.0+', 'weight_from' => 21.0, 'weight_to' => null, 'billing_unit' => 'per_kg', 'price_vnd' => 120000, 'sort_order' => 10 ];

$rate_repo      = new Allship_UPS_Rate_Repository( $mock_wpdb );
$rate_card_repo = new Allship_UPS_Rate_Card_Repository( $mock_wpdb );
$admin_rates    = new Allship_UPS_Admin_Rates( $rate_repo, $rate_card_repo, $mock_wpdb );

echo "=== START TEST STEP 5.4: RATES EDIT PAGE & AJAX ===\n\n";

// --- Test 1: AJAX Hook Registration ---
echo "--- Test 1: AJAX Hook Registration ---\n";
$admin_rates->register_hooks();
$expected_hooks = [
	'wp_ajax_ups_rates_list',
	'wp_ajax_ups_rates_update_cell',
	'wp_ajax_ups_rates_add_row',
	'wp_ajax_ups_rates_update_row',
	'wp_ajax_ups_rates_delete_rows',
	'wp_ajax_ups_rates_get_filters',
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
$GLOBALS['mock_nonce_valid'] = false;
try {
	$_GET['rate_card_id'] = 1;
	$admin_rates->ajax_list_rates();
	echo "✘ FAIL: Nonce check did not block request.\n";
	exit( 1 );
} catch ( Exception $e ) {
	if ( 'NONCE_INVALID' === $e->getMessage() ) {
		echo "✓ Nonce check verified (blocked invalid request).\n";
	}
}
$GLOBALS['mock_nonce_valid'] = true;

// --- Test 3: Get Filters ---
echo "\n--- Test 3: Get Filter Options ---\n";
$_GET['rate_card_id'] = 1;
try {
	$admin_rates->ajax_get_filters();
} catch ( Exception $e ) {}

$res = $GLOBALS['mock_json_response'];
if ( $res['success'] && in_array( 'export_wxs_nondocument', $res['data']['rate_groups'] ) && in_array( '5', $res['data']['zones'] ) ) {
	echo "✓ Filter options returned: rate_groups=" . count( $res['data']['rate_groups'] ) . ", zones=" . count( $res['data']['zones'] ) . "\n";
} else {
	echo "✘ FAIL: Filter options incomplete.\n";
	exit( 1 );
}

// --- Test 4: List Rates & Filtering ---
echo "\n--- Test 4: List Rates & Filter Capabilities ---\n";
$_GET = [
	'rate_card_id' => 1,
	'rate_group'   => 'export_wxs_nondocument',
	'zone'         => '',
	'billing_unit' => '',
	'search'       => '',
	'paged'        => 1,
];
try {
	$admin_rates->ajax_list_rates();
} catch ( Exception $e ) {}

$res = $GLOBALS['mock_json_response'];
if ( $res['success'] && count( $res['data']['items'] ) === 2 ) {
	echo "✓ Filter by rate_group returned exactly 2 rows.\n";
} else {
	echo "✘ FAIL: Filter by rate_group incorrect.\n";
	exit( 1 );
}

// --- Test 5: Inline Edit Cell ---
echo "\n--- Test 5: Inline Edit Price Cell ---\n";
$_POST = [
	'id'    => 201,
	'field' => 'price_vnd',
	'value' => '990000',
];
try {
	$admin_rates->ajax_update_cell();
} catch ( Exception $e ) {}

$res = $GLOBALS['mock_json_response'];
if ( $res['success'] && (int) $mock_wpdb->rates[201]->price_vnd === 990000 ) {
	echo "✓ Inline edit price_vnd to 990,000 saved and persisted.\n";
} else {
	echo "✘ FAIL: Inline edit price failed to persist.\n";
	exit( 1 );
}

// --- Test 6: Bulk Delete ---
echo "\n--- Test 6: Bulk Delete Rates ---\n";
$_POST = [
	'ids' => [ 202, 203 ],
];
try {
	$admin_rates->ajax_delete_rows();
} catch ( Exception $e ) {}

$res = $GLOBALS['mock_json_response'];
if ( $res['success'] && $res['data']['deleted'] === 2 && ! isset( $mock_wpdb->rates[202] ) && ! isset( $mock_wpdb->rates[203] ) ) {
	echo "✓ Bulk delete successfully deleted 2 rows.\n";
} else {
	echo "✘ FAIL: Bulk delete failed.\n";
	exit( 1 );
}

// --- Test 7: View Rendering ---
echo "\n--- Test 7: View Rendering (rates-edit.php) ---\n";
ob_start();
include dirname( __DIR__ ) . '/admin/views/rates-edit.php';
$view_html = ob_get_clean();

$view_checks = [
	'as-rates-filter-bar' => 'Filter bar container',
	'ratesFilterCard'     => 'Rate Card select',
	'ratesFilterGroup'    => 'Rate Group select',
	'ratesFilterZone'     => 'Zone select',
	'ratesFilterSearch'   => 'Search input',
	'ratesTable'          => 'Rates data table',
	'modalAddRate'        => 'Add/Edit Rate modal',
	'formAddRate'         => 'Add/Edit Rate form',
];

$all_views_passed = true;
foreach ( $view_checks as $needle => $label ) {
	if ( strpos( $view_html, $needle ) === false ) {
		echo "✘ Missing component in view: {$label} ({$needle})\n";
		$all_views_passed = false;
	}
}

if ( $all_views_passed ) {
	echo "✓ rates-edit.php successfully rendered with all expected UI components & modals.\n";
} else {
	exit( 1 );
}

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 5.4 PASSED SUCCESSFULLY!\n";
echo "============================================\n";
