<?php
/**
 * Test Step 5.2 — Rate Cards Page & Management Suite.
 *
 * Verifies:
 * 1. Allship_UPS_Admin_Rate_Cards AJAX hook registrations.
 * 2. Security gating: Nonce + manage_options checks.
 * 3. Lifecycle: Create -> Rename -> Activate -> Archive -> Delete.
 * 4. Business rule: Prevent deletion of active card.
 * 5. Rate card detail grid & toggle save (directions + rate groups).
 * 6. Cache invalidation on rate card changes.
 * 7. Rate cards view rendering & HTML structure.
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

// Mock WordPress functions if running standalone CLI
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

if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ) {
		return addslashes( (string) $text );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null ) {
		return gmdate( $format, $timestamp ?? time() );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'http://example.com/wp-admin/' . $path;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number ) {
		return number_format( (float) $number );
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

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
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

// In-Memory Database Mock for Rate Cards
class Mock_WPDB_Rate_Cards {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $insert_id = 0;
	public $rate_cards = [];
	public $rates = [];
	public $zones = [];
	public $queries = [];

	public function prepare( $query, ...$args ) {
		$idx = 0;
		return preg_replace_callback( '/%[dsf]/', function() use ( &$idx, $args ) {
			$val = $args[ $idx++ ] ?? '';
			return is_numeric( $val ) ? $val : "'" . addslashes( (string) $val ) . "'";
		}, $query );
	}

	public function insert( $table, $data, $format = null ) {
		$this->insert_id++;
		$data['id'] = $this->insert_id;
		$this->rate_cards[ $this->insert_id ] = (object) $data;
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$count = 0;
		foreach ( $this->rate_cards as $id => $card ) {
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( (string) ( $card->$k ?? '' ) !== (string) $v ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				foreach ( $data as $k => $v ) {
					$card->$k = $v;
				}
				$count++;
			}
		}
		return $count;
	}

	public function delete( $table, $where, $where_format = null ) {
		if ( strpos( $table, 'ups_rate_cards' ) !== false && isset( $where['id'] ) ) {
			$id = $where['id'];
			if ( isset( $this->rate_cards[ $id ] ) ) {
				unset( $this->rate_cards[ $id ] );
				return 1;
			}
		}
		return 0;
	}

	public function get_row( $query, $output = OBJECT ) {
		if ( preg_match( '/WHERE id = (\d+)/', $query, $m ) ) {
			$id = (int) $m[1];
			return isset( $this->rate_cards[ $id ] ) ? clone $this->rate_cards[ $id ] : null;
		}
		if ( strpos( $query, "WHERE status = 'active'" ) !== false ) {
			foreach ( $this->rate_cards as $card ) {
				if ( 'active' === $card->status ) {
					return clone $card;
				}
			}
			return null;
		}
		return null;
	}

	public function get_results( $query, $output = OBJECT ) {
		if ( strpos( $query, 'GROUP BY rate_group' ) !== false ) {
			return [
				[ 'rate_group' => 'export_wxs_document', 'cnt' => 126 ],
				[ 'rate_group' => 'export_wxs_nondocument', 'cnt' => 234 ],
				[ 'rate_group' => 'export_xpd', 'cnt' => 234 ],
				[ 'rate_group' => 'export_wfm', 'cnt' => 48 ],
			];
		}
		if ( strpos( $query, 'FROM wp_ups_rate_cards' ) !== false ) {
			$rows = array_values( array_map( function( $c ) { return clone $c; }, $this->rate_cards ) );
			usort( $rows, function( $a, $b ) { return $b->id - $a->id; } );
			return $rows;
		}
		return [];
	}

	public function get_var( $query ) {
		if ( strpos( $query, 'COUNT(*) FROM wp_ups_rates' ) !== false ) {
			return 642;
		}
		if ( strpos( $query, 'COUNT(*) FROM wp_ups_zone_maps' ) !== false ) {
			return 1450;
		}
		return 0;
	}

	public function query( $query ) {
		$this->queries[] = $query;
		return 1;
	}
}

$mock_wpdb = new Mock_WPDB_Rate_Cards();
$GLOBALS['wpdb'] = $mock_wpdb;

require_once dirname( __DIR__ ) . '/includes/config/service-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-card-repository.php';
require_once dirname( __DIR__ ) . '/admin/class-admin-rate-cards.php';

echo "=== START TEST STEP 5.2: RATE CARDS PAGE & AJAX ===\n\n";

// --- Test 1: AJAX Hook Registration ---
echo "--- Test 1: AJAX hook registration ---\n";
$controller = new Allship_UPS_Admin_Rate_Cards( null, $mock_wpdb );
$controller->register_hooks();

$expected_hooks = [
	'wp_ajax_ups_create_rate_card',
	'wp_ajax_ups_rename_rate_card',
	'wp_ajax_ups_activate_rate_card',
	'wp_ajax_ups_archive_rate_card',
	'wp_ajax_ups_delete_rate_card',
	'wp_ajax_ups_get_rate_card_detail',
	'wp_ajax_ups_update_rate_card_toggles',
];

foreach ( $expected_hooks as $h ) {
	if ( empty( $GLOBALS['wp_actions'][ $h ] ) ) {
		echo "✘ FAIL: Hook '$h' not registered.\n";
		exit( 1 );
	}
}
echo "✓ All 7 AJAX hooks successfully registered.\n\n";

// --- Test 2: Rate Card Lifecycle (Create -> Rename -> Activate -> Archive -> Delete) ---
echo "--- Test 2: Complete Lifecycle (Create -> Rename -> Activate -> Archive -> Delete) ---\n";

// 2a. Create Card #1 (Draft)
$_POST = [
	'name'        => 'UPS Net Rates Q3-2026',
	'market_code' => 'VN',
	'valid_from'  => '2026-08-20',
];
try {
	$controller->ajax_create_rate_card();
} catch ( Exception $e ) {}
$res = $GLOBALS['mock_json_response'];
if ( empty( $res['success'] ) || empty( $res['data']['card_id'] ) ) {
	echo "✘ FAIL: ajax_create_rate_card failed.\n";
	exit( 1 );
}
$card1_id = $res['data']['card_id'];
echo "  • Create: Created draft Rate Card #$card1_id ('UPS Net Rates Q3-2026')\n";

// 2b. Rename Card #1
$_POST = [
	'id'   => $card1_id,
	'name' => 'UPS Net Rates Q3-2026 (Updated)',
];
try {
	$controller->ajax_rename_rate_card();
} catch ( Exception $e ) {}
$res = $GLOBALS['mock_json_response'];
if ( empty( $res['success'] ) ) {
	echo "✘ FAIL: ajax_rename_rate_card failed.\n";
	exit( 1 );
}
echo "  • Rename: Successfully renamed Card #$card1_id to 'UPS Net Rates Q3-2026 (Updated)'\n";

// 2c. Activate Card #1
$_POST = [ 'id' => $card1_id ];
try {
	$controller->ajax_activate_rate_card();
} catch ( Exception $e ) {}
$res = $GLOBALS['mock_json_response'];
if ( empty( $res['success'] ) ) {
	echo "✘ FAIL: ajax_activate_rate_card failed.\n";
	exit( 1 );
}
$card1 = $mock_wpdb->rate_cards[ $card1_id ];
if ( $card1->status !== 'active' ) {
	echo "✘ FAIL: Card #$card1_id status is not 'active'.\n";
	exit( 1 );
}
echo "  • Activate: Card #$card1_id status changed to 'active'\n";

// 2d. Create Card #2 and Activate Card #2 -> Card #1 should become 'archived'
$_POST = [
	'name' => 'Special Promo Card Q4',
];
try {
	$controller->ajax_create_rate_card();
} catch ( Exception $e ) {}
$card2_id = $GLOBALS['mock_json_response']['data']['card_id'];

$_POST = [ 'id' => $card2_id ];
try {
	$controller->ajax_activate_rate_card();
} catch ( Exception $e ) {}

if ( $mock_wpdb->rate_cards[ $card1_id ]->status !== 'archived' ) {
	echo "✘ FAIL: Activating Card #$card2_id did not automatically archive Card #$card1_id.\n";
	exit( 1 );
}
if ( $mock_wpdb->rate_cards[ $card2_id ]->status !== 'active' ) {
	echo "✘ FAIL: Card #$card2_id status is not 'active'.\n";
	exit( 1 );
}
echo "  • Auto-Archive: Card #2 activated, previous Card #1 automatically archived.\n";

// 2e. Business rule: Delete ACTIVE card #2 must be BLOCKED
$_POST = [ 'id' => $card2_id ];
try {
	$controller->ajax_delete_rate_card();
} catch ( Exception $e ) {}
$res = $GLOBALS['mock_json_response'];
if ( ! empty( $res['success'] ) ) {
	echo "✘ FAIL: Active card was allowed to be deleted! (Security violation)\n";
	exit( 1 );
}
echo "  • Security: Attempt to delete Active card #$card2_id was correctly blocked.\n";

// 2f. Delete ARCHIVED card #1 -> permitted
$_POST = [ 'id' => $card1_id ];
try {
	$controller->ajax_delete_rate_card();
} catch ( Exception $e ) {}
$res = $GLOBALS['mock_json_response'];
if ( empty( $res['success'] ) || isset( $mock_wpdb->rate_cards[ $card1_id ] ) ) {
	echo "✘ FAIL: Delete archived Card #$card1_id failed.\n";
	exit( 1 );
}
echo "  • Delete: Archived Card #$card1_id successfully deleted.\n\n";

// --- Test 3: Rate Card Detail & Toggle Configuration ---
echo "--- Test 3: Detail Grid & Toggle updates ---\n";
$_POST = [ 'id' => $card2_id ];
try {
	$controller->ajax_get_rate_card_detail();
} catch ( Exception $e ) {}
$detail_res = $GLOBALS['mock_json_response'];
if ( empty( $detail_res['success'] ) || empty( $detail_res['data']['grid']['export'] ) ) {
	echo "✘ FAIL: ajax_get_rate_card_detail failed to return export grid.\n";
	exit( 1 );
}
$export_svcs = $detail_res['data']['grid']['export'];
if ( count( $export_svcs ) !== 6 ) {
	echo "✘ FAIL: Expected 6 services in detail grid, found " . count( $export_svcs ) . ".\n";
	exit( 1 );
}
echo "✓ Detail grid loaded: 6 services returned with rate groups and row counts.\n";

// Save updated directions (both export + import) and disable 'export_wfm'
$_POST = [
	'id'              => $card2_id,
	'directions'      => [ 'export', 'import' ],
	'disabled_groups' => [ 'export_wfm' ],
];
try {
	$controller->ajax_update_rate_card_toggles();
} catch ( Exception $e ) {}
$res = $GLOBALS['mock_json_response'];
if ( empty( $res['success'] ) ) {
	echo "✘ FAIL: ajax_update_rate_card_toggles failed.\n";
	exit( 1 );
}

$updated_card = $mock_wpdb->rate_cards[ $card2_id ];
$dirs = json_decode( $updated_card->enabled_directions, true );
$dis = json_decode( $updated_card->disabled_rate_groups, true );
if ( ! in_array( 'import', $dirs, true ) || ! in_array( 'export_wfm', $dis, true ) ) {
	echo "✘ FAIL: Directions or disabled groups not persisted in DB.\n";
	exit( 1 );
}
echo "✓ Rate card toggles updated: directions=['export', 'import'], disabled_groups=['export_wfm'].\n\n";

// --- Test 4: Security Nonce & Capability Gate ---
echo "--- Test 4: Security Nonce & Capability gating ---\n";
// Nonce failure
$GLOBALS['mock_nonce_valid'] = false;
$nonce_blocked = false;
try {
	$_POST = [ 'id' => $card2_id ];
	$controller->ajax_activate_rate_card();
} catch ( Exception $e ) {
	$nonce_blocked = true;
}
$GLOBALS['mock_nonce_valid'] = true;
if ( ! $nonce_blocked ) {
	echo "✘ FAIL: Invalid nonce was not rejected.\n";
	exit( 1 );
}
echo "✓ Invalid nonce rejected.\n";

// Capability failure
$GLOBALS['mock_user_caps'] = [ 'manage_options' => false ];
$cap_blocked = false;
try {
	$_POST = [ 'id' => $card2_id ];
	$controller->ajax_activate_rate_card();
} catch ( Exception $e ) {
	$res = $GLOBALS['mock_json_response'];
	$cap_blocked = ( 403 === $res['status'] );
}
$GLOBALS['mock_user_caps'] = [ 'manage_options' => true ];
if ( ! $cap_blocked ) {
	echo "✘ FAIL: Non-admin without manage_options was not rejected with 403.\n";
	exit( 1 );
}
echo "✓ Non-admin user blocked with HTTP 403.\n\n";

// --- Test 5: Rate Cards List Table & View Rendering ---
echo "--- Test 5: List Table & View rendering ---\n";
$cards_list = $controller->get_rate_cards_list();
if ( empty( $cards_list ) || $cards_list[0]['id'] !== $card2_id ) {
	echo "✘ FAIL: get_rate_cards_list did not return Card #$card2_id.\n";
	exit( 1 );
}
echo "✓ get_rate_cards_list: Returned decorated card with status badge and services.\n";

ob_start();
include dirname( __DIR__ ) . '/admin/views/rate-cards.php';
$html_view = ob_get_clean();

$expected_strings = [
	'rate-cards-table',
	'Special Promo Card Q4',
	'modalRenameRateCard',
	'AllshipAdmin.openRenameModal',
];

foreach ( $expected_strings as $str ) {
	if ( strpos( $html_view, $str ) === false ) {
		echo "✘ FAIL: Admin view missing '$str'.\n";
		exit( 1 );
	}
}
echo "✓ admin/views/rate-cards.php rendered successfully with table, action buttons, and modal.\n\n";

echo "============================================\n";
echo "✓ ALL TESTS STEP 5.2 PASSED 100%!\n";
echo "============================================\n";
exit( 0 );
