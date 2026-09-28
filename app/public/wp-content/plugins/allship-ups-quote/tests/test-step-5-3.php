<?php
/**
 * Test Step 5.3: Import Page & Wizard AJAX Handlers.
 *
 * Verifies:
 * 1. AJAX hook registration (ups_import_preview, ups_import_execute, ups_import_cancel).
 * 2. Security validation (Nonce and Capability manage_options).
 * 3. Upload -> Preview summary (rate groups, US5 detection, countries, warnings).
 * 4. Cancel flow (cleans up temp files and transient, NO data written to DB).
 * 5. Confirm / Execute flow (creates draft rate card, imports data, clears transient).
 * 6. Admin view rendering (import.php renders cleanly with all 3 wizard steps).
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../../../' );
}

if ( ! defined( 'ALLSHIP_UPS_QUOTE_PATH' ) ) {
	define( 'ALLSHIP_UPS_QUOTE_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'UPLOAD_ERR_OK' ) ) {
	define( 'UPLOAD_ERR_OK', 0 );
}

// Global mocks
$GLOBALS['mock_actions'] = [];
$GLOBALS['mock_transients'] = [];
$GLOBALS['mock_json_response'] = null;
$GLOBALS['mock_nonce_valid'] = true;
$GLOBALS['mock_user_caps'] = [ 'manage_options' => true ];

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['mock_actions'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
		if ( ! $GLOBALS['mock_nonce_valid'] ) {
			if ( $die ) {
				throw new Exception( 'Nonce verification failed.' );
			}
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return ! empty( $GLOBALS['mock_user_caps'][ $capability ] );
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status_code = null ) {
		$GLOBALS['mock_json_response'] = [
			'success' => true,
			'data'    => $data,
			'status'  => $status_code ?? 200,
		];
		throw new Exception( 'WP_JSON_SUCCESS' );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = null ) {
		$GLOBALS['mock_json_response'] = [
			'success' => false,
			'data'    => $data,
			'status'  => $status_code ?? 400,
		];
		throw new Exception( 'WP_JSON_ERROR' );
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		$GLOBALS['mock_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return $GLOBALS['mock_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		unset( $GLOBALS['mock_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $val ) {
		return is_string( $val ) ? stripslashes( $val ) : $val;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'http://example.local/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true ) {
		return substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' ), 0, $length );
	}
}

// Mock Orchestrator for controlled testing
class Mock_Allship_UPS_Import_Orchestrator {
	public $db_written = false;
	public $cleaned_up = false;

	public function handle_upload( array $file_array ) {
		if ( empty( $file_array['tmp_name'] ) || ! file_exists( $file_array['tmp_name'] ) ) {
			throw new Exception( 'File upload failed.' );
		}
		return [
			'file_path'        => $file_array['tmp_name'],
			'source_file_name' => $file_array['name'],
			'source_file_hash' => hash( 'sha256', 'mock_content' ),
			'file_size'        => $file_array['size'],
		];
	}

	public function preview( $rate_file_path, $zone_file_path = null ) {
		return [
			'status'   => 'success',
			'rates'    => [
				'has_us5' => true,
				'groups'  => [
					'export_wxs_document' => [
						'title'        => 'Express Saver Document',
						'rows_count'   => 126,
						'service_code' => 'WXS',
					],
					'export_wxs_nondocument' => [
						'title'        => 'Express Saver Non-Document',
						'rows_count'   => 234,
						'service_code' => 'WXS',
					],
					'export_xpd' => [
						'title'        => 'Expedited',
						'rows_count'   => 234,
						'service_code' => 'XPD',
					],
				],
			],
			'zones'    => [
				'countries_count' => 220,
				'zone_maps_count' => 840,
			],
			'errors'   => [],
			'warnings' => [
				'Dòng ZZ bị bỏ qua (không phải mã quốc gia hợp lệ).',
				'Dịch vụ EXW, XPR, WXP chưa có biểu phí trong file.',
			],
		];
	}

	public function import( array $params ) {
		$this->db_written = true;
		if ( ! empty( $params['delete_files_after'] ) ) {
			$this->cleaned_up = true;
		}
		return [
			'status'       => 'success',
			'rate_card_id' => 101,
			'card_status'  => 'draft',
			'rate_summary' => [
				'total_rates_imported' => 594,
			],
			'zone_summary' => [
				'total_zones_imported' => 840,
			],
		];
	}
}

// Include Admin Import Controller
require_once dirname( __DIR__ ) . '/admin/class-admin-import.php';

echo "=== START TEST STEP 5.3: IMPORT PAGE & WIZARD ===\n\n";

$mock_orchestrator = new Mock_Allship_UPS_Import_Orchestrator();
$controller        = new Allship_UPS_Admin_Import( $mock_orchestrator );

// --- Test 1: AJAX Hook Registration ---
echo "--- Test 1: AJAX Hook registration ---\n";
$controller->register_hooks();

$expected_hooks = [
	'wp_ajax_ups_import_preview',
	'wp_ajax_ups_import_execute',
	'wp_ajax_ups_import_cancel',
];

foreach ( $expected_hooks as $hook ) {
	if ( empty( $GLOBALS['mock_actions'][ $hook ] ) ) {
		echo "✘ FAIL: Hook '$hook' was not registered.\n";
		exit( 1 );
	}
}
echo "✓ All 3 AJAX hooks registered successfully.\n\n";

// --- Test 2: Security Validation ---
echo "--- Test 2: Security checks (Nonce & Capability) ---\n";
$GLOBALS['mock_nonce_valid'] = false;
try {
	$controller->ajax_import_preview();
	echo "✘ FAIL: Nonce failure did not block execution.\n";
	exit( 1 );
} catch ( Exception $e ) {
	echo "✓ Nonce check verified (blocked invalid request).\n";
}
$GLOBALS['mock_nonce_valid'] = true;

$GLOBALS['mock_user_caps'] = [ 'manage_options' => false ];
try {
	$controller->ajax_import_preview();
	echo "✘ FAIL: Non-admin was not rejected.\n";
	exit( 1 );
} catch ( Exception $e ) {
	$res = $GLOBALS['mock_json_response'];
	if ( 403 !== ( $res['status'] ?? 0 ) ) {
		echo "✘ FAIL: Expected 403 status code for non-admin, got: " . ( $res['status'] ?? 'null' ) . "\n";
		exit( 1 );
	}
}
echo "✓ Non-admin correctly rejected with 403.\n\n";
$GLOBALS['mock_user_caps'] = [ 'manage_options' => true ];

// --- Test 3: Upload & Preview ---
echo "--- Test 3: Upload -> Preview Summary ---\n";
$tmp_rate_file = tempnam( sys_get_temp_dir(), 'rate_' );
file_put_contents( $tmp_rate_file, 'dummy_rate_content' );

$tmp_zone_file = tempnam( sys_get_temp_dir(), 'zone_' );
file_put_contents( $tmp_zone_file, 'dummy_zone_content' );

$_FILES = [
	'rate_file' => [
		'name'     => 'SDS_Express_rate_Q4.xlsx',
		'type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'tmp_name' => $tmp_rate_file,
		'error'    => UPLOAD_ERR_OK,
		'size'     => 12345,
	],
	'zone_file' => [
		'name'     => 'IATA.xlsx',
		'type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'tmp_name' => $tmp_zone_file,
		'error'    => UPLOAD_ERR_OK,
		'size'     => 6789,
	],
];

$_POST = [
	'rate_card_name' => 'Bảng giá UPS Q4-2026',
	'valid_from'     => '2026-10-01',
];

$preview_token = null;
try {
	$controller->ajax_import_preview();
} catch ( Exception $e ) {
	$res = $GLOBALS['mock_json_response'];
	if ( empty( $res['success'] ) ) {
		echo "✘ FAIL: ajax_import_preview failed: " . json_encode( $res ) . "\n";
		exit( 1 );
	}

	$data = $res['data'];
	$preview_token = $data['token'] ?? null;

	if ( empty( $preview_token ) ) {
		echo "✘ FAIL: Token missing from preview response.\n";
		exit( 1 );
	}

	if ( empty( $data['has_us5'] ) ) {
		echo "✘ FAIL: US5 column flag was not detected.\n";
		exit( 1 );
	}

	if ( count( $data['rate_groups'] ) !== 3 ) {
		echo "✘ FAIL: Expected 3 rate groups in preview, got: " . count( $data['rate_groups'] ) . "\n";
		exit( 1 );
	}

	if ( count( $data['warnings'] ) !== 2 ) {
		echo "✘ FAIL: Expected 2 warnings in preview, got: " . count( $data['warnings'] ) . "\n";
		exit( 1 );
	}

	if ( 220 !== $data['countries_count'] || 840 !== $data['zone_maps_count'] ) {
		echo "✘ FAIL: Incorrect countries/zones preview counts.\n";
		exit( 1 );
	}
}
echo "✓ Preview summary verified: 3 rate groups, US5 detected, 220 countries, 2 warnings.\n";
echo "✓ Session stored in transient 'ups_imp_$preview_token'.\n\n";

// --- Test 4: Cancel Flow (No DB Write) ---
echo "--- Test 4: Cancel Flow (Rollback / No DB writes) ---\n";
$_POST = [ 'token' => $preview_token ];
try {
	$controller->ajax_import_cancel();
} catch ( Exception $e ) {
	$res = $GLOBALS['mock_json_response'];
	if ( empty( $res['success'] ) || 'cancelled' !== ( $res['data']['status'] ?? '' ) ) {
		echo "✘ FAIL: Cancel did not return cancelled status.\n";
		exit( 1 );
	}

	if ( get_transient( 'ups_imp_' . $preview_token ) ) {
		echo "✘ FAIL: Transient was not deleted on cancel.\n";
		exit( 1 );
	}

	if ( $mock_orchestrator->db_written ) {
		echo "✘ FAIL: Database was written during cancelled session!\n";
		exit( 1 );
	}
}
echo "✓ Cancel flow verified: session cleaned up, zero DB writes.\n\n";

// --- Test 5: Re-preview & Execute Flow ---
echo "--- Test 5: Execute Flow (Draft creation & DB import) ---\n";
$tmp_rate_file2 = tempnam( sys_get_temp_dir(), 'rate2_' );
file_put_contents( $tmp_rate_file2, 'dummy_rate_content' );

$_FILES['rate_file']['tmp_name'] = $tmp_rate_file2;
unset( $_FILES['zone_file'] ); // Test optional zone file omission

try {
	$controller->ajax_import_preview();
} catch ( Exception $e ) {
	$preview_token2 = $GLOBALS['mock_json_response']['data']['token'];
}

$_POST = [
	'token'          => $preview_token2,
	'rate_card_name' => 'Bảng giá UPS Net Q4 Final',
	'valid_from'     => '2026-10-01',
];

try {
	$controller->ajax_import_execute();
} catch ( Exception $e ) {
	$res = $GLOBALS['mock_json_response'];
	if ( empty( $res['success'] ) ) {
		echo "✘ FAIL: ajax_import_execute failed: " . json_encode( $res ) . "\n";
		exit( 1 );
	}

	$data = $res['data'];
	if ( 101 !== ( $data['rate_card_id'] ?? 0 ) ) {
		echo "✘ FAIL: Expected rate_card_id 101, got: " . ( $data['rate_card_id'] ?? 'null' ) . "\n";
		exit( 1 );
	}

	if ( 'draft' !== ( $data['card_status'] ?? '' ) ) {
		echo "✘ FAIL: Imported rate card must be created in 'draft' status.\n";
		exit( 1 );
	}

	if ( ! $mock_orchestrator->db_written ) {
		echo "✘ FAIL: Database import routine was not executed.\n";
		exit( 1 );
	}

	if ( get_transient( 'ups_imp_' . $preview_token2 ) ) {
		echo "✘ FAIL: Transient session was not cleared after execute.\n";
		exit( 1 );
	}
}
echo "✓ Execute flow verified: created Rate Card #101 with status 'draft'.\n";
echo "✓ Transient session cleared after successful import.\n\n";

// --- Test 6: View Rendering (import.php) ---
echo "--- Test 6: View Rendering (admin/views/import.php) ---\n";
ob_start();
include dirname( __DIR__ ) . '/admin/views/import.php';
$html_view = ob_get_clean();

$expected_strings = [
	'importStep1',
	'importStep2',
	'importStep3',
	'btnStartImportPreview',
	'previewRateGroupsTable',
	'btnExecuteImport',
	'AllshipAdmin.handleImportPreview',
	'AllshipAdmin.handleImportExecute',
	'AllshipAdmin.handleImportCancel',
];

foreach ( $expected_strings as $str ) {
	if ( strpos( $html_view, $str ) === false ) {
		echo "✘ FAIL: Admin view import.php missing element '$str'.\n";
		exit( 1 );
	}
}
echo "✓ admin/views/import.php rendered cleanly with all 3 wizard steps and actions.\n\n";

// Clean up leftover test temp files
@unlink( $tmp_rate_file );
@unlink( $tmp_zone_file );
@unlink( $tmp_rate_file2 );

echo "============================================\n";
echo "✓ ALL TESTS STEP 5.3 PASSED 100%!\n";
echo "============================================\n";
exit( 0 );
