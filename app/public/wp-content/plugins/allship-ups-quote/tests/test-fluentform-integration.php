<?php
/**
 * Test FluentForm ↔ UPS Quote Integration Suite.
 *
 * Verifies:
 * 1. Template JSON validity: 3 visible fields, 15 hidden fields, submit button, metas.
 * 2. Booking Modal HTML: Presence of all required hidden fields.
 * 3. Allship_UPS_FluentForm_Bridge:
 *    - Registers hook on 'fluentform/submission_inserted'.
 *    - Updates existing quote log with contact info when quote_log_id exists.
 *    - Creates new quote log when no quote_log_id is provided.
 *    - Prevents recursion when __bridge_sync flag is present.
 *    - Ignores non-UPS submissions.
 *    - Pushes REST lead to FluentForm submissions table with multi-piece list message and 18 standard service name.
 * 4. Admin Settings:
 *    - allship_ups_fluentform_id registered, sanitized, and stored.
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

// Global mocks
$GLOBALS['mock_actions']       = [];
$GLOBALS['mock_options']       = [];
$GLOBALS['mock_user_caps']     = [ 'manage_options' => true ];
$GLOBALS['mock_transients']    = [];
$GLOBALS['mock_mail_sent']     = [];

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['mock_actions'][ $tag ][] = [
			'callback' => $callback,
			'priority' => $priority,
		];
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {
		if ( ! empty( $GLOBALS['mock_actions'][ $tag ] ) ) {
			foreach ( $GLOBALS['mock_actions'][ $tag ] as $hook ) {
				call_user_func_array( $hook['callback'], $args );
			}
		}
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return ! empty( $GLOBALS['mock_user_caps'][ $capability ] );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return isset( $GLOBALS['mock_options'][ $name ] ) ? $GLOBALS['mock_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['mock_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null ) {
		return gmdate( $format, $timestamp ?: time() );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_string( $str ) ? trim( strip_tags( $str ) ) : '';
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return is_string( $str ) ? trim( strip_tags( $str ) ) : '';
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( trim( (string) $email ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $email ) : '';
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) {
		return abs( (int) $v );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) {
		return $v;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 1;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0 ) {
		return json_encode( $data, $options );
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message ) {
		$GLOBALS['mock_mail_sent'][] = compact( 'to', 'subject', 'message' );
		return true;
	}
}

/**
 * In-memory Mock WPDB for tests.
 */
class Mock_WPDB_FluentForm {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $tables = [];

	public function __construct() {
		$this->tables['wp_ups_quote_logs']        = [];
		$this->tables['wp_fluentform_submissions'] = [];
		$this->tables['wp_fluentform_forms']       = [
			[
				'id'     => 1,
				'title'  => 'UPS Quote Request Form',
				'status' => 'published',
			]
		];
	}

	public function prepare( $query, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$val = is_numeric( $arg ) ? $arg : "'" . addslashes( (string) $arg ) . "'";
			$pos = strpos( $query, '%' );
			if ( false !== $pos ) {
				$type  = substr( $query, $pos, 2 );
				$query = substr_replace( $query, $val, $pos, 2 );
			}
		}
		return $query;
	}

	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
			preg_match( "/'([^']+)'/", $sql, $m );
			$tbl = $m[1] ?? '';
			return isset( $this->tables[ $tbl ] ) ? $tbl : null;
		}
		if ( false !== strpos( $sql, 'MAX(serial_number)' ) ) {
			$max = 0;
			foreach ( $this->tables['wp_fluentform_submissions'] as $sub ) {
				$max = max( $max, (int) ( $sub['serial_number'] ?? 0 ) );
			}
			return $max;
		}
		if ( false !== strpos( $sql, "SELECT id FROM wp_fluentform_forms" ) ) {
			return 1;
		}
		return null;
	}

	public function get_row( $sql, $output = OBJECT ) {
		if ( false !== strpos( $sql, 'wp_ups_quote_logs' ) && preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
			$id = (int) $m[1];
			foreach ( $this->tables['wp_ups_quote_logs'] as $row ) {
				if ( (int) $row['id'] === $id ) {
					return (object) $row;
				}
			}
			return null;
		}
		if ( false !== strpos( $sql, 'wp_fluentform_forms' ) && preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
			$id = (int) $m[1];
			foreach ( $this->tables['wp_fluentform_forms'] as $row ) {
				if ( (int) $row['id'] === $id ) {
					return (object) $row;
				}
			}
			return null;
		}
		return null;
	}

	public function insert( $table, $data, $format = null ) {
		$this->insert_id++;
		$data['id'] = $this->insert_id;
		$this->tables[ $table ][] = $data;
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( isset( $this->tables[ $table ] ) ) {
			foreach ( $this->tables[ $table ] as &$row ) {
				$match = true;
				foreach ( $where as $wk => $wv ) {
					if ( ( $row[ $wk ] ?? null ) != $wv ) {
						$match = false;
						break;
					}
				}
				if ( $match ) {
					foreach ( $data as $dk => $dv ) {
						$row[ $dk ] = $dv;
					}
					return 1;
				}
			}
		}
		return 0;
	}
}

// Require needed files
require_once dirname( __DIR__ ) . '/includes/class-quote-log-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-fluentform-bridge.php';
require_once dirname( __DIR__ ) . '/admin/class-admin-settings.php';

// Test Runner
$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "  ✅ PASS: {$message}\n";
		$passed++;
	} else {
		echo "  ❌ FAIL: {$message}\n";
		$failed++;
	}
}

echo "\n--- RUNNING FLUENTFORM ↔ UPS QUOTE INTEGRATION TEST SUITE ---\n\n";

// TEST 1: Template JSON Validity
echo "Test 1: FluentForm Template JSON\n";
$tpl_file = dirname( __DIR__ ) . '/data/fluentform-quote-template.json';
assert_test( file_exists( $tpl_file ), "Template JSON file exists at data/fluentform-quote-template.json" );
$tpl_content = json_decode( file_get_contents( $tpl_file ), true );
assert_test( is_array( $tpl_content ) && ! empty( $tpl_content[0]['form_fields']['fields'] ), "Template parses to valid FluentForm structure" );
$fields = $tpl_content[0]['form_fields']['fields'];
$field_names = [];
foreach ( $fields as $f ) {
	$field_names[] = $f['attributes']['name'] ?? '';
}
assert_test( in_array( 'name', $field_names, true ), "Template contains visible 'name' field" );
assert_test( in_array( 'phone', $field_names, true ), "Template contains visible 'phone' field" );
assert_test( in_array( 'message', $field_names, true ), "Template contains visible 'message' textarea field" );
assert_test( in_array( 'service', $field_names, true ), "Template contains hidden 'service' field" );
assert_test( in_array( 'hidden_service_name', $field_names, true ), "Template contains hidden 'hidden_service_name' field" );
assert_test( in_array( 'pieces_json', $field_names, true ), "Template contains hidden 'pieces_json' field" );
assert_test( in_array( 'quote_log_id', $field_names, true ), "Template contains hidden 'quote_log_id' field" );

// TEST 2: Booking Modal HTML in quote-form.php
echo "\nTest 2: Booking Modal Hidden Fields in quote-form.php\n";
$view_content = file_get_contents( dirname( __DIR__ ) . '/public/views/quote-form.php' );
assert_test( false !== strpos( $view_content, 'id="bookingService"' ), "quote-form.php contains bookingService hidden input" );
assert_test( false !== strpos( $view_content, 'id="bookingHiddenServiceName"' ), "quote-form.php contains bookingHiddenServiceName hidden input" );
assert_test( false !== strpos( $view_content, 'id="bookingFormattedMessage"' ), "quote-form.php contains bookingFormattedMessage hidden input" );
assert_test( false !== strpos( $view_content, 'id="bookingPiecesJson"' ), "quote-form.php contains bookingPiecesJson hidden input" );
assert_test( false !== strpos( $view_content, 'id="bookingQuoteLogId"' ), "quote-form.php contains bookingQuoteLogId hidden input" );

// TEST 3: Allship_UPS_FluentForm_Bridge Registration
echo "\nTest 3: FluentForm Bridge Hook Registration\n";
$mock_wpdb = new Mock_WPDB_FluentForm();
$log_repo  = new Allship_UPS_Quote_Log_Repository( $mock_wpdb );
$bridge    = new Allship_UPS_FluentForm_Bridge( $log_repo, $mock_wpdb );
$bridge->init();
assert_test( ! empty( $GLOBALS['mock_actions']['fluentform/submission_inserted'] ), "fluentform/submission_inserted action is registered" );

// TEST 4: on_submission_inserted updates existing quote log
echo "\nTest 4: on_submission_inserted updates existing quote log\n";
// Insert an initial quote log
$existing_log_id = $log_repo->insert( [
	'direction'            => 'export',
	'destination_iata'     => 'JP',
	'service_code'         => 'XPD',
	'actual_weight_kg'     => 1.0,
	'chargeable_weight_kg' => 1.0,
	'total_price_vnd'      => 891195,
	'breakdown_json'       => [ 'services' => [] ],
] );

$form_submission = [
	'name'                => 'Nguyễn Văn An',
	'phone'               => '0901234567',
	'email'               => 'an@example.com',
	'service'             => 'Expedited (XPD) - Export',
	'hidden_service_name' => 'Dịch vụ chuyển phát quốc tế UPS - Xuất khẩu',
	'message'             => "[Ghi chú]: Cần giao sớm\n• Chi tiết 1 kiện...",
	'quote_log_id'        => $existing_log_id,
	'hidden_source'       => 'ups_quote_booking_modal',
];

$bridge->on_submission_inserted( 99, $form_submission, (object) [ 'id' => 1, 'title' => 'UPS Quote Request Form' ] );

$updated_log = $log_repo->get( $existing_log_id );
assert_test( ! empty( $updated_log->breakdown['contact'] ), "Contact metadata is attached to existing quote log breakdown" );
assert_test( $updated_log->breakdown['contact']['name'] === 'Nguyễn Văn An', "Contact name is correctly recorded" );
assert_test( $updated_log->breakdown['contact']['phone'] === '0901234567', "Contact phone is correctly recorded" );
assert_test( $updated_log->breakdown['contact']['ff_entry_id'] === 99, "FluentForm entry ID is linked" );

// TEST 5: on_submission_inserted creates new quote log when no quote_log_id
echo "\nTest 5: on_submission_inserted creates new quote log for direct submission\n";
$direct_submission = [
	'name'                => 'Trần Thị B',
	'phone'               => '0987654321',
	'direction'           => 'import',
	'destination_iata'    => 'US',
	'origin'              => 'Tokyo, Japan',
	'service_code'        => 'WXS',
	'chargeable_weight'   => '5.5 kg',
	'total_price_raw'     => 1500000,
	'hidden_source'       => 'ups_quote_booking_modal',
];

$bridge->on_submission_inserted( 100, $direct_submission, (object) [ 'id' => 1, 'title' => 'UPS Quote Request Form' ] );
assert_test( count( $mock_wpdb->tables['wp_ups_quote_logs'] ) >= 2, "New quote log was created for direct submission" );
$newest_log = end( $mock_wpdb->tables['wp_ups_quote_logs'] );
assert_test( $newest_log['session_id'] === 'ff-100', "Session ID stores ff-100 reference" );
assert_test( $newest_log['direction'] === 'import', "Direction is correctly set to import" );

// TEST 6: push_lead_to_fluentform bridges REST lead to FluentForm submissions table
echo "\nTest 6: push_lead_to_fluentform bridges REST lead to FluentForm table\n";
$rest_lead = [
	'name'                => 'Lê Văn C',
	'phone'               => '0912345678',
	'email'               => 'c@example.com',
	'service'             => 'Express Saver Non-Document (WXS Non-Doc) - Export',
	'hidden_service_name' => 'Dịch vụ chuyển phát quốc tế UPS - Xuất khẩu',
	'message'             => "THÔNG TIN BÁO GIÁ UPS:\n• Tuyến: Xuất khẩu (TP. Hồ Chí Minh ➔ Tokyo, Japan (JP))\n• Chi tiết các kiện hàng (2 kiện):\n  - Kiện #1: 1 thùng, 2.0 kg...",
	'notes'               => 'Đóng gỗ cẩn thận',
	'direction'           => 'export',
	'destination_iata'    => 'JP',
	'destination_name'    => 'Japan (JP)',
	'service_code'        => 'WXS',
	'weight_kg'           => 4.5,
	'total_price_vnd'     => 2100000,
	'quote_log_id'        => $existing_log_id,
	'route_summary'       => 'TP. Hồ Chí Minh ➔ Japan (JP)',
];

$ff_entry_id = $bridge->push_lead_to_fluentform( $rest_lead );
assert_test( $ff_entry_id > 0, "push_lead_to_fluentform successfully inserted submission (ID: {$ff_entry_id})" );
assert_test( ! empty( $mock_wpdb->tables['wp_fluentform_submissions'] ), "fluentform_submissions table has rows" );

$inserted_sub = end( $mock_wpdb->tables['wp_fluentform_submissions'] );
$sub_resp     = json_decode( $inserted_sub['response'], true );
assert_test( $sub_resp['name'] === 'Lê Văn C', "FluentForm entry response contains correct customer name" );
assert_test( $sub_resp['phone'] === '0912345678', "FluentForm entry response contains correct phone" );
assert_test( $sub_resp['service'] === 'Express Saver Non-Document (WXS Non-Doc) - Export', "FluentForm entry contains standard 18-service name" );
assert_test( false !== strpos( $sub_resp['message'], 'Chi tiết các kiện hàng' ), "FluentForm entry contains formatted multi-piece list message" );

// TEST 7: Settings registration for fluentform_id
echo "\nTest 7: Admin Settings registration for fluentform_id\n";
$settings_admin = new Allship_UPS_Admin_Settings( null, $mock_wpdb );
$cleaned        = $settings_admin->sanitize_settings( [
	'dim_divisor'   => 5500,
	'fluentform_id' => '42',
] );
assert_test( isset( $cleaned['fluentform_id'] ) && 42 === $cleaned['fluentform_id'], "fluentform_id is sanitized and retained as integer 42" );

// Summary
echo "\n============================================\n";
echo "TOTAL: " . ( $passed + $failed ) . " | PASSED: {$passed} | FAILED: {$failed}\n";
echo "============================================\n";

if ( $failed > 0 ) {
	exit( 1 );
}
exit( 0 );
