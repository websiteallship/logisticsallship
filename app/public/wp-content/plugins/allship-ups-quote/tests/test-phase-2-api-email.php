<?php
/**
 * Unit Test Suite for Phase 2: REST API, FluentForm Bridge & Email Engine.
 *
 * Verifies:
 * 1. FluentForm Bridge: Support for company_name, quote_ref, pdf_url, hidden_source.
 * 2. Quote Mailer: Customer quotation email with PDF attachment & admin lead alert via wp_mail().
 * 3. REST API POST /export-quote: Validation, PDF generation, lead repo insertion, quote log update, bridge push.
 * 4. REST API GET /download-quote: Token verification, file serving simulation, download counter increment.
 *
 * Run with: php tests/test-phase-2-api-email.php
 *
 * @package Allship_UPS_Quote
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ALLSHIP_UPS_QUOTE_PATH', dirname( __DIR__ ) . '/' );
define( 'ALLSHIP_TESTING', true );
define( 'DOMPDF_TEST_SUITE', true );

// Simple Assertion Helpers
$tests_run    = 0;
$tests_passed = 0;
$tests_failed = 0;

function assert_true( $condition, $message = '' ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $condition ) {
		$tests_passed++;
		echo "  \033[32m✔ PASS:\033[0m {$message}\n";
	} else {
		$tests_failed++;
		echo "  \033[31m✘ FAIL:\033[0m {$message}\n";
	}
}

function assert_equals( $expected, $actual, $message = '' ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $expected === $actual ) {
		$tests_passed++;
		echo "  \033[32m✔ PASS:\033[0m {$message}\n";
	} else {
		$tests_failed++;
		$exp_str = is_scalar( $expected ) ? var_export( $expected, true ) : json_encode( $expected );
		$act_str = is_scalar( $actual ) ? var_export( $actual, true ) : json_encode( $actual );
		echo "  \033[31m✘ FAIL:\033[0m {$message} (Expected: {$exp_str}, got: {$act_str})\n";
	}
}

// Global Mocks
$mock_mails_sent = [];
$mock_options    = [
	'admin_email'               => 'admin@allship.vn',
	'allship_ups_fluentform_id' => 1,
];
$mock_transients = [];

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( trim( (string) $email ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $email ) : '';
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return (bool) filter_var( trim( (string) $email ), FILTER_VALIDATE_EMAIL );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return htmlspecialchars( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data, JSON_UNESCAPED_UNICODE );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $num ) {
		return abs( (int) $num );
	}
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return mt_rand( $min, $max );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql' ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0755, true );
	}
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		$tmp = sys_get_temp_dir() . '/allship_wp_test_phase2';
		return [
			'basedir' => $tmp,
			'baseurl' => 'http://logistic.allship.vn/wp-content/uploads',
		];
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		global $mock_options;
		return $mock_options[ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $val, $autoload = null ) {
		global $mock_options;
		$mock_options[ $name ] = $val;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		global $mock_transients;
		return $mock_transients[ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		global $mock_transients;
		$mock_transients[ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ) {
		global $mock_mails_sent;
		$mock_mails_sent[] = [
			'to'          => $to,
			'subject'     => $subject,
			'message'     => $message,
			'headers'     => $headers,
			'attachments' => $attachments,
		];
		return true;
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'http://logistic.allship.vn/wp-json/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $val ) {
		return $val;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = [] ) {}
}

// Mock WPDB Class
class Mock_WPDB_Phase2 {
	public $prefix = 'wp_';
	public $last_query = '';
	public $insert_id = 1;
	public $records = [];

	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$val = is_numeric( $arg ) ? $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[sdf]/', $val, $query, 1 );
		}
		return $query;
	}

	public function insert( $table, $data, $format = null ) {
		$id = ++$this->insert_id;
		$data['id'] = $id;
		$this->records[ $table ][ $id ] = (object) $data;
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$id = $where['id'] ?? 0;
		if ( isset( $this->records[ $table ][ $id ] ) ) {
			foreach ( $data as $k => $v ) {
				$this->records[ $table ][ $id ]->$k = $v;
			}
			return 1;
		}
		return 0;
	}

	public function query( $sql ) {
		$this->last_query = $sql;
		if ( preg_match( '/UPDATE\s+`?([a-zA-Z0-9_]+)`?\s+SET\s+download_count\s*=\s*download_count\s*\+\s*1\s+WHERE\s+id\s*=\s*(\d+)/i', $sql, $m ) ) {
			$table = $m[1];
			$id = (int) $m[2];
			if ( isset( $this->records[ $table ][ $id ] ) ) {
				$this->records[ $table ][ $id ]->download_count = ( $this->records[ $table ][ $id ]->download_count ?? 1 ) + 1;
				return 1;
			}
		}
		return 1;
	}

	public function get_row( $sql ) {
		$this->last_query = $sql;
		if ( preg_match( '/FROM\s+([a-zA-Z0-9_]+)\s+WHERE\s+id\s*=\s*(\d+)/i', $sql, $m ) ) {
			return $this->records[ $m[1] ][ (int) $m[2] ] ?? null;
		}
		if ( preg_match( '/FROM\s+([a-zA-Z0-9_]+)\s+WHERE\s+quote_ref\s*=\s*\'([^\']+)\'/i', $sql, $m ) ) {
			$table = $m[1];
			$ref   = $m[2];
			if ( ! empty( $this->records[ $table ] ) ) {
				foreach ( $this->records[ $table ] as $item ) {
					if ( ( $item->quote_ref ?? '' ) === $ref ) {
						return $item;
					}
				}
			}
		}
		return null;
	}

	public function get_var( $sql ) {
		$this->last_query = $sql;
		if ( stripos( $sql, 'SHOW TABLES LIKE' ) !== false ) {
			return 'wp_fluentform_submissions';
		}
		if ( preg_match( '/SELECT\s+MAX\(serial_number\)/i', $sql ) ) {
			return 1;
		}
		if ( preg_match( '/COUNT\(\*\)\s+FROM\s+([a-zA-Z0-9_]+)\s+WHERE\s+quote_ref\s*=\s*\'([^\']+)\'/i', $sql, $m ) ) {
			$table = $m[1];
			$ref   = $m[2];
			if ( ! empty( $this->records[ $table ] ) ) {
				foreach ( $this->records[ $table ] as $item ) {
					if ( ( $item->quote_ref ?? '' ) === $ref ) {
						return 1;
					}
				}
			}
			return 0;
		}
		return 0;
	}

	public function get_col( $sql, $col_offset = 0 ) {
		return [ 'id', 'form_id', 'status', 'response', 'source_url', 'user_id', 'browser', 'device', 'ip', 'created_at', 'updated_at' ];
	}
}

// Load Composer autoloader
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Load Plugin Classes
require_once dirname( __DIR__ ) . '/includes/class-settings-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-quote-lead-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-quote-log-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-quote-pdf-renderer.php';
require_once dirname( __DIR__ ) . '/includes/class-quote-mailer.php';
require_once dirname( __DIR__ ) . '/includes/class-fluentform-bridge.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';

echo "========================================================\n";
echo " ALLSHIP UPS QUOTE - PHASE 2 TEST SUITE\n";
echo "========================================================\n\n";

$mock_wpdb = new Mock_WPDB_Phase2();
$GLOBALS['wpdb'] = $mock_wpdb;

// -----------------------------------------------------------------------------
// Test Group 1: FluentForm Bridge Extension
// -----------------------------------------------------------------------------
echo "[Group 1: FluentForm Bridge Extension]\n";

$bridge = new Allship_UPS_FluentForm_Bridge( null, $mock_wpdb );

$bridge_payload = [
	'name'                 => 'Nguyễn Văn Test',
	'company_name'         => 'Công Ty XNK Toàn Cầu',
	'email'                => 'test.lead@toancau.vn',
	'phone'                => '0987654321',
	'notes'                => 'Hàng may mặc xuất khẩu đi Mỹ',
	'quote_ref'            => 'AS-QUO-202609-0099',
	'pdf_url'              => 'http://logistic.allship.vn/quotes/AS-QUO-202609-0099.pdf',
	'service_code'         => 'WXS',
	'service'              => 'UPS Express Saver',
	'direction'            => 'export',
	'origin'               => 'VN',
	'destination'          => 'United States (US)',
	'chargeable_weight_kg' => 12.5,
	'total_price_vnd'      => 4500000,
	'hidden_source'        => 'ups_quote_pdf_export',
];

$entry_id = $bridge->push_lead_to_fluentform( $bridge_payload );
assert_true( $entry_id > 0, "push_lead_to_fluentform returns valid entry ID: {$entry_id}" );

$ff_submissions = $mock_wpdb->records['wp_fluentform_submissions'] ?? [];
assert_true( isset( $ff_submissions[ $entry_id ] ), "Submission inserted into wp_fluentform_submissions" );

$saved_row = $ff_submissions[ $entry_id ];
$saved_response = json_decode( $saved_row->response, true );

assert_equals( 'Nguyễn Văn Test', $saved_response['name'], 'Response JSON contains customer name' );
assert_equals( 'Công Ty XNK Toàn Cầu', $saved_response['company_name'], 'Response JSON contains company name' );
assert_equals( 'test.lead@toancau.vn', $saved_response['email'], 'Response JSON contains email' );
assert_equals( 'AS-QUO-202609-0099', $saved_response['quote_ref'], 'Response JSON contains quote_ref' );
assert_equals( 'ups_quote_pdf_export', $saved_response['hidden_source'], 'Response JSON contains hidden_source' );

echo "\n";

// -----------------------------------------------------------------------------
// Test Group 2: Quote Mailer Service
// -----------------------------------------------------------------------------
echo "[Group 2: Quote Mailer Service]\n";

$settings_mgr = new Allship_UPS_Settings_Manager( $mock_wpdb );
$settings_mgr->set( 'company_name', 'CÔNG TY TNHH ALLSHIP LOGISTICS' );
$settings_mgr->set( 'company_email', 'quote@allship.vn' );
$settings_mgr->set( 'quote_admin_notify_emails', 'sales@allship.vn, ops@allship.vn' );

$mailer = new Allship_UPS_Quote_Mailer( $settings_mgr );

// Create dummy PDF file for attachment test
$test_pdf_dir = wp_upload_dir()['basedir'];
wp_mkdir_p( $test_pdf_dir );
$test_pdf_path = $test_pdf_dir . '/test_quote.pdf';
file_put_contents( $test_pdf_path, '%PDF-1.4 mock content' );

$mock_mails_sent = [];
$customer_mail_res = $mailer->send_quote_to_customer( [
	'quote_ref'        => 'AS-QUO-202609-0099',
	'name'             => 'Nguyễn Văn Test',
	'contact_name'     => 'Nguyễn Văn Test',
	'company_name'     => 'Công Ty XNK Toàn Cầu',
	'email'            => 'client@test.com',
	'phone'            => '0987654321',
	'service_name'     => 'UPS Express Saver',
	'destination_name' => 'Hoa Kỳ (US)',
	'weight_kg'        => 12.5,
	'total_price_vnd'  => 4500000,
	'download_url'     => 'http://logistic.allship.vn/download?ref=AS-QUO-202609-0099',
], $test_pdf_path );

assert_true( $customer_mail_res, 'send_quote_to_customer returned true' );
assert_equals( 1, count( $mock_mails_sent ), 'One customer email dispatched' );
$sent_cust = $mock_mails_sent[0];
assert_equals( 'client@test.com', $sent_cust['to'], 'Customer email recipient matches' );
assert_true( strpos( $sent_cust['subject'], 'AS-QUO-202609-0099' ) !== false, 'Subject contains quote_ref' );
assert_true( in_array( $test_pdf_path, $sent_cust['attachments'], true ), 'PDF file attached to email' );
assert_true( strpos( $sent_cust['message'], '12.50 kg' ) !== false, 'Customer email contains correct formatted weight 12.50 kg' );

// Test Admin Notification
$admin_mail_res = $mailer->send_lead_alert_to_admin( [
	'quote_ref'        => 'AS-QUO-202609-0099',
	'name'             => 'Nguyễn Văn Test',
	'contact_name'     => 'Nguyễn Văn Test',
	'company_name'     => 'Công Ty XNK Toàn Cầu',
	'email'            => 'client@test.com',
	'phone'            => '0987654321',
	'service_name'     => 'UPS Express Saver',
	'destination_name' => 'Hoa Kỳ (US)',
	'weight_kg'        => 12.5,
	'total_price_vnd'  => 4500000,
	'notes'            => 'Khách xuất gấp trong tuần',
	'download_url'     => 'http://logistic.allship.vn/download?ref=AS-QUO-202609-0099',
], $test_pdf_path );

assert_true( $admin_mail_res, 'send_lead_alert_to_admin returned true' );
assert_equals( 2, count( $mock_mails_sent ), 'Two emails dispatched in total' );
$sent_admin = $mock_mails_sent[1];
$admin_recipients = is_array( $sent_admin['to'] ) ? $sent_admin['to'] : [ $sent_admin['to'] ];
assert_true( in_array( 'sales@allship.vn', $admin_recipients, true ), 'Admin email sent to sales recipient' );
assert_true( strpos( $sent_admin['message'], 'Nguyễn Văn Test' ) !== false, 'Admin alert body mentions lead name' );
assert_true( strpos( $sent_admin['message'], '12.50 kg' ) !== false, 'Admin email contains correct formatted weight 12.50 kg' );

echo "\n";

// -----------------------------------------------------------------------------
// Test Group 3: REST API POST /export-quote
// -----------------------------------------------------------------------------
echo "[Group 3: REST API POST /export-quote]\n";

$lead_repo      = new Allship_UPS_Quote_Lead_Repository( $mock_wpdb );
$quote_log_repo = new Allship_UPS_Quote_Log_Repository( $mock_wpdb );
$pdf_renderer   = new Allship_UPS_Quote_Pdf_Renderer( $settings_mgr );

$rest_controller = new Allship_UPS_REST_Controller(
	null,
	null,
	null,
	null,
	null,
	null,
	$quote_log_repo,
	$settings_mgr,
	$lead_repo,
	$pdf_renderer,
	$mailer
);

// 3.1 Input validation checks
$err_missing_name = $rest_controller->export_quote( [
	'email' => 'test@client.com',
] );
assert_equals( false, $err_missing_name['success'], 'Fails when contact name is empty' );
assert_equals( 'INVALID_INPUT', $err_missing_name['error']['code'], 'Error code is INVALID_INPUT' );

$err_missing_email = $rest_controller->export_quote( [
	'name'  => 'Trần Báo Giá',
	'email' => 'invalid-email-format',
] );
assert_equals( false, $err_missing_email['success'], 'Fails when email is invalid' );

// 3.2 Pre-insert a quote log to test sync
$quote_log_id = $quote_log_repo->insert( [
	'service_code'         => 'WXS',
	'destination_iata'     => 'US',
	'chargeable_weight_kg' => 8.5,
	'actual_weight_kg'     => 8.0,
	'dim_weight_kg'        => 8.5,
	'base_price_vnd'       => 3200000,
	'total_price_vnd'      => 4100000,
	'breakdown_json'       => [
		'fsc_percent'  => 28.5,
		'fsc_amount'   => 912000,
		'vat_rate'     => 8.0,
		'vat_amount'   => 328960,
		'surge_amount' => 0,
	],
] );
assert_true( $quote_log_id > 0, "Created mock quote log entry #{$quote_log_id}" );

// 3.3 Full export execution
$mock_mails_sent = [];
$export_res = $rest_controller->export_quote( [
	'contact_name'         => 'Trần Hoàng Logistics',
	'company_name'         => 'Công Ty TNHH Hoàng Minh',
	'email'                => 'hoangminh@logistics.vn',
	'phone'                => '0912345678',
	'notes'                => 'Yêu cầu pick-up tại kho Tân Bình',
	'quote_log_id'         => $quote_log_id,
	'service_code'         => 'WXS',
	'destination_iata'     => 'US',
	'destination_name'     => 'United States',
	'chargeable_weight_kg' => 8.5,
	'total_price_vnd'      => 4100000,
	'base_price_vnd'       => 3200000,
	'send_email'           => true,
] );

assert_true( $export_res['success'], 'POST /export-quote executed successfully' );
$data = $export_res['data'];

assert_true( ! empty( $data['quote_ref'] ), "Quote ref generated: {$data['quote_ref']}" );
assert_true( (bool) preg_match( '/^AS-QUO-\d{6}-\d{4}$/', $data['quote_ref'] ), 'Quote ref matches AS-QUO-YYYYMM-XXXX pattern' );
assert_true( ! empty( $data['download_url'] ), "Download URL present: {$data['download_url']}" );
assert_true( ! empty( $data['lead_id'] ), "Lead record ID returned: {$data['lead_id']}" );
assert_true( $data['email_sent'], 'Email sent flag is true' );

// Verify lead in DB
$lead_db = $lead_repo->get( $data['lead_id'] );
assert_true( null !== $lead_db, 'Lead found in wp_ups_quote_leads' );
assert_equals( 'Trần Hoàng Logistics', $lead_db->contact_name, 'Lead contact_name matches' );
assert_equals( 'Công Ty TNHH Hoàng Minh', $lead_db->company_name, 'Lead company_name matches' );
assert_equals( 'hoangminh@logistics.vn', $lead_db->email, 'Lead email matches' );
assert_equals( 4100000, (int) $lead_db->total_price_vnd, 'Lead total_price_vnd matches' );
assert_true( file_exists( $lead_db->pdf_path ), "Generated PDF file exists at {$lead_db->pdf_path}" );

// Verify quote log updated with lead contact info
$updated_log = $quote_log_repo->get( $quote_log_id );
assert_true( isset( $updated_log->breakdown['contact'] ), 'Quote log breakdown contains contact section' );
assert_equals( 'Trần Hoàng Logistics', $updated_log->breakdown['contact']['name'], 'Quote log contains contact name' );
assert_equals( $data['quote_ref'], $updated_log->breakdown['contact']['quote_ref'], 'Quote log contains quote_ref' );
assert_equals( 'pdf_export', $updated_log->breakdown['contact']['source'], 'Quote log source is pdf_export' );

// 3.4 Export without pre-existing quote_log_id (auto-insert into quote_log_repo)
$export_res2 = $rest_controller->export_quote( [
	'contact_name'         => 'Lê Văn An',
	'company_name'         => 'An Phát Export',
	'email'                => 'an@anphat.vn',
	'phone'                => '0933112233',
	'quote_log_id'         => 0,
	'service_code'         => 'XPD',
	'destination_iata'     => 'US',
	'destination_name'     => 'United States',
	'chargeable_weight_kg' => 6.5,
	'total_price_vnd'      => 2250307,
	'base_price_vnd'       => 2000000,
	'pieces'               => [
		[ 'qty' => 1, 'weight' => 3.2, 'len' => 30, 'wid' => 20, 'hei' => 15 ],
		[ 'qty' => 2, 'weight' => 2.2, 'len' => 25, 'wid' => 20, 'hei' => 10 ],
	],
	'send_email'           => true,
] );
assert_true( $export_res2['success'], 'Export without quote_log_id succeeded' );
assert_true( ! empty( $export_res2['data']['quote_log_id'] ), 'New quote_log_id was generated automatically' );
$new_log = $quote_log_repo->get( $export_res2['data']['quote_log_id'] );
assert_true( null !== $new_log, 'Log record exists in quote_log_repo' );
assert_equals( 'ups_quote_pdf_export', $new_log->breakdown['lead_source'], 'Lead source is ups_quote_pdf_export' );
assert_equals( 'Lê Văn An', $new_log->breakdown['contact']['name'], 'Contact name in new log matches' );
assert_equals( 6.5, (float) $new_log->chargeable_weight_kg, 'Chargeable weight in new log is 6.5' );
assert_true( (float) $new_log->actual_weight_kg > 0, 'Actual weight was computed from pieces' );
assert_true( (float) $new_log->dim_weight_kg > 0, 'DIM weight was computed from pieces' );

echo "\n";

// -----------------------------------------------------------------------------
// Test Group 4: REST API GET /download-quote
// -----------------------------------------------------------------------------
echo "[Group 4: REST API GET /download-quote]\n";

// 4.1 Valid download with token
$token = substr( hash_hmac( 'sha256', $data['quote_ref'], 'allship_quote_download' ), 0, 16 );
$dl_res = $rest_controller->download_quote( [
	'ref'   => $data['quote_ref'],
	'token' => $token,
] );

assert_true( $dl_res['success'], 'Valid quote download request succeeded' );
assert_equals( $data['quote_ref'], $dl_res['data']['quote_ref'], 'Downloaded correct quote_ref' );
assert_true( $dl_res['data']['filesize'] > 1000, 'PDF filesize > 1KB' );

// Verify download counter incremented
$lead_after_dl = $lead_repo->get( $data['lead_id'] );
assert_equals( 2, (int) $lead_after_dl->download_count, 'Download count incremented to 2' );

// 4.2 Invalid token check
$invalid_token_res = $rest_controller->download_quote( [
	'ref'   => $data['quote_ref'],
	'token' => 'wrong_token_123',
] );
assert_equals( false, $invalid_token_res['success'], 'Fails with wrong security token' );
assert_equals( 'FORBIDDEN', $invalid_token_res['error']['code'], 'Error code is FORBIDDEN' );

// 4.3 Non-existent quote_ref check
$not_found_res = $rest_controller->download_quote( [
	'ref'   => 'AS-QUO-NONEXISTENT',
] );
assert_equals( false, $not_found_res['success'], 'Fails for non-existent quote_ref' );
assert_equals( 'NOT_FOUND', $not_found_res['error']['code'], 'Error code is NOT_FOUND' );

// -----------------------------------------------------------------------------
// Test Summary
// -----------------------------------------------------------------------------
echo "\n========================================================\n";
echo " TEST SUMMARY\n";
echo "========================================================\n";
echo "Total tests: {$tests_run}\n";
echo "Passed:      \033[32m{$tests_passed}\033[0m\n";
echo "Failed:      " . ( $tests_failed > 0 ? "\033[31m{$tests_failed}\033[0m" : "0" ) . "\n";

if ( 0 === $tests_failed ) {
	echo "\n\033[32m🎉 ALL PHASE 2 UNIT TESTS PASSED SUCCESSFULLY!\033[0m\n\n";
	exit( 0 );
} else {
	echo "\n\033[31m❌ SOME TESTS FAILED!\033[0m\n\n";
	exit( 1 );
}
