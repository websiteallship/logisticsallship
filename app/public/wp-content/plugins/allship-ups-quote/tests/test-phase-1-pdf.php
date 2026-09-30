<?php
/**
 * Unit Test Suite for Phase 1: DB Migration, Settings & PDF Quote Engine.
 *
 * Verifies:
 * 1. Activator schema migration: ups_quote_leads table structure & DB_VERSION = 1.3.0.
 * 2. Settings Manager: Company profile, defaults, multiline textarea & URL sanitization.
 * 3. Quote Lead Repository: CRUD, quote_ref generation, increment download, mark email sent.
 * 4. PDF Quote Template & Renderer: HTML generation and binary PDF compilation with Dompdf.
 *
 * Run with: php tests/test-phase-1-pdf.php
 *
 * @package Allship_UPS_Quote
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ALLSHIP_UPS_QUOTE_PATH', dirname( __DIR__ ) . '/' );
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

// Global Mocks for WordPress functions
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
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $str ) {
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
		$tmp = sys_get_temp_dir() . '/allship_wp_test_uploads';
		return [
			'basedir' => $tmp,
			'baseurl' => 'http://test.local/wp-content/uploads',
		];
	}
}

// Mock WPDB Class
class Mock_WPDB {
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

	public function delete( $table, $where, $where_format = null ) {
		$id = $where['id'] ?? 0;
		if ( isset( $this->records[ $table ][ $id ] ) ) {
			unset( $this->records[ $table ][ $id ] );
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
		if ( preg_match( '/SELECT\s+COUNT\(\*\)\s+FROM\s+([a-zA-Z0-9_]+)/i', $sql, $m ) ) {
			$table = $m[1];
			return count( $this->records[ $table ] ?? [] );
		}
		return 0;
	}

	public function get_results( $sql ) {
		$this->last_query = $sql;
		if ( preg_match( '/FROM\s+([a-zA-Z0-9_]+)/i', $sql, $m ) ) {
			$table = $m[1];
			return array_values( $this->records[ $table ] ?? [] );
		}
		return [];
	}

	public function esc_like( $str ) {
		return addcslashes( $str, '_%\\' );
	}
}

// ----------------------------------------------------
// START TEST RUNNER
// ----------------------------------------------------

echo "\n======================================================\n";
echo "  UNIT TESTS: PHASE 1 - DB, SETTINGS & PDF ENGINE\n";
echo "======================================================\n\n";

// 1. TEST ACTIVATOR & DB SCHEMA
echo "[1] Testing DB Activator Schema & Version...\n";
require_once ALLSHIP_UPS_QUOTE_PATH . 'includes/class-activator.php';
assert_equals( '1.3.0', Allship_UPS_Activator::DB_VERSION, 'Activator DB_VERSION is 1.3.0' );

$activator_code = file_get_contents( ALLSHIP_UPS_QUOTE_PATH . 'includes/class-activator.php' );
assert_true( strpos( $activator_code, 'CREATE TABLE {$prefix}ups_quote_leads' ) !== false, 'Activator contains ups_quote_leads table definition' );
assert_true( strpos( $activator_code, 'quote_ref VARCHAR(30) NOT NULL' ) !== false, 'ups_quote_leads table contains quote_ref column' );
assert_true( strpos( $activator_code, 'chargeable_weight_kg DECIMAL(10,3) NOT NULL' ) !== false, 'ups_quote_leads table contains chargeable_weight_kg column' );

// 2. TEST SETTINGS MANAGER
echo "\n[2] Testing Settings Manager (Company Profile & PDF Settings)...\n";
require_once ALLSHIP_UPS_QUOTE_PATH . 'includes/class-settings-manager.php';
$mock_wpdb = new Mock_WPDB();
$settings_mgr = new Allship_UPS_Settings_Manager( $mock_wpdb );

assert_equals( 'CÔNG TY TNHH ALLSHIP LOGISTICS', $settings_mgr->get( 'company_name' ), 'Default company_name fallback is correct' );
assert_equals( 'quote@allship.vn', $settings_mgr->get( 'company_email' ), 'Default company_email fallback is correct' );
assert_equals( 14, $settings_mgr->get( 'quote_validity_days' ), 'Default quote_validity_days is 14 days' );

// Test updating settings
$settings_mgr->set( 'company_name', 'ALLSHIP GLOBAL FREIGHT CO.' );
assert_equals( 'ALLSHIP GLOBAL FREIGHT CO.', $settings_mgr->get( 'company_name' ), 'Company name successfully updated and retrieved' );

$multiline_bank = "Techcombank TP.HCM\nSTK: 99998888\nALLSHIP LOGISTICS";
$settings_mgr->set( 'quote_bank_info', $multiline_bank );
assert_equals( $multiline_bank, $settings_mgr->get( 'quote_bank_info' ), 'Multiline textarea setting preserves newlines' );

// 3. TEST QUOTE LEAD REPOSITORY
echo "\n[3] Testing Quote Lead Repository (CRUD & Ref Generation)...\n";
require_once ALLSHIP_UPS_QUOTE_PATH . 'includes/class-quote-lead-repository.php';
$lead_repo = new Allship_UPS_Quote_Lead_Repository( $mock_wpdb );

$generated_ref = $lead_repo->generate_quote_ref();
assert_true( (bool) preg_match( '/^AS-QUO-\d{6}-\d{4}$/', $generated_ref ), "Generated quote_ref format matches AS-QUO-YYYYMM-XXXX ({$generated_ref})" );

$new_lead_id = $lead_repo->insert( [
	'quote_ref'            => 'AS-QUO-202610-0099',
	'contact_name'         => 'Nguyễn Văn Tuấn',
	'company_name'         => 'Công Ty TNHH Xuất Nhập Khẩu Quốc Tế Á Âu',
	'email'                => 'tuan.nguyen@aau.com.vn',
	'phone'                => '0909123456',
	'service_code'         => 'WXS',
	'destination_iata'     => 'US',
	'destination_name'     => 'California, United States',
	'chargeable_weight_kg' => 24.5,
	'total_price_vnd'      => 7560000,
	'quote_data_json'      => [ 'base_price' => 5450000, 'fsc' => 1550000 ],
] );

assert_true( $new_lead_id > 0, "Lead inserted successfully with ID: {$new_lead_id}" );

$lead_by_id = $lead_repo->get( $new_lead_id );
assert_true( null !== $lead_by_id, 'Lead retrieved by ID successfully' );
assert_equals( 'Nguyễn Văn Tuấn', $lead_by_id->contact_name, 'Lead contact name matches' );
assert_equals( 'AS-QUO-202610-0099', $lead_by_id->quote_ref, 'Lead quote_ref matches' );
assert_equals( 7560000, (int) $lead_by_id->total_price_vnd, 'Lead total price matches' );

$lead_by_ref = $lead_repo->get_by_quote_ref( 'AS-QUO-202610-0099' );
assert_true( null !== $lead_by_ref, 'Lead retrieved by Quote Ref successfully' );

// Test increment download
$lead_repo->increment_download( $new_lead_id );
$lead_updated = $lead_repo->get( $new_lead_id );
assert_equals( 2, (int) $lead_updated->download_count, 'Download count incremented to 2' );

// Test mark email sent
$lead_repo->mark_email_sent( $new_lead_id );
$lead_updated = $lead_repo->get( $new_lead_id );
assert_equals( 1, (int) $lead_updated->email_sent, 'Email sent flag marked as 1' );
assert_true( ! empty( $lead_updated->email_sent_at ), 'Email sent timestamp recorded' );

// 4. TEST PDF RENDERER & DOMPDF COMPILATION
echo "\n[4] Testing PDF Renderer & Dompdf Compilation...\n";
require_once ALLSHIP_UPS_QUOTE_PATH . 'vendor/autoload.php';
require_once ALLSHIP_UPS_QUOTE_PATH . 'includes/class-quote-pdf-renderer.php';

$pdf_renderer = new Allship_UPS_Quote_Pdf_Renderer( $settings_mgr );

$test_payload = [
	'company' => [
		'name'        => 'CÔNG TY TNHH ALLSHIP LOGISTICS',
		'tax_id'      => '0317320092',
		'address'     => 'Tòa nhà Allship, TP. Hồ Chí Minh, Việt Nam',
		'hotline'     => '1900 633 833',
		'email'       => 'quote@allship.vn',
		'website'     => 'https://allship.vn',
		'dim_divisor' => 5000,
	],
	'quote' => [
		'quote_ref'    => 'AS-QUO-202610-0099',
		'created_at'   => '30/09/2026',
		'valid_until'  => '14/10/2026',
		'direction'    => 'export',
		'service_code' => 'WXS',
		'service_name' => 'UPS Worldwide Saver',
		'origin'       => 'TP. Hồ Chí Minh, Việt Nam',
		'destination'  => 'California, United States (US)',
		'incoterms'    => 'DDU / Door-to-Door',
		'transit_time' => '1 - 3 ngày làm việc',
	],
	'customer' => [
		'name'    => 'Nguyễn Văn Tuấn',
		'company' => 'Công Ty TNHH Xuất Nhập Khẩu Quốc Tế Á Âu',
		'phone'   => '0909123456',
		'email'   => 'tuan.nguyen@aau.com.vn',
	],
	'cargo' => [
		'total_pieces'         => 2,
		'gross_weight_kg'      => 20.0,
		'dim_weight_kg'        => 24.0,
		'chargeable_weight_kg' => 24.0,
		'pieces'               => [
			[
				'qty'    => 2,
				'len'    => 50,
				'wid'    => 40,
				'hei'    => 30,
				'weight' => 10.0,
			],
		],
	],
	'pricing' => [
		'base_price_vnd' => 5450000,
		'fsc_percent'    => 28.5,
		'fsc_amount_vnd' => 1553250,
		'vat_percent'    => 8.0,
		'vat_amount_vnd' => 560260,
		'total_price_vnd'=> 7563510,
	],
];

// Test HTML generation
$html_output = $pdf_renderer->render_html( $test_payload );
assert_true( ! empty( $html_output ), 'HTML quotation rendered successfully' );
assert_true( strpos( $html_output, 'AS-QUO-202610-0099' ) !== false, 'HTML contains Quote Reference' );
assert_true( strpos( $html_output, 'CÔNG TY TNHH ALLSHIP LOGISTICS' ) !== false, 'HTML contains Company Name' );
assert_true( strpos( $html_output, 'Nguyễn Văn Tuấn' ) !== false, 'HTML contains Customer Name' );
assert_true( strpos( $html_output, 'Công Ty TNHH Xuất Nhập Khẩu Quốc Tế Á Âu' ) !== false, 'HTML contains Customer Company' );
assert_true( strpos( $html_output, '7.563.510 VND' ) !== false, 'HTML contains formatted Total Price' );

// Test Dompdf binary generation
$pdf_binary = $pdf_renderer->generate_pdf( $test_payload );
assert_true( ! empty( $pdf_binary ), 'Binary PDF generated without errors' );
assert_true( 0 === strpos( $pdf_binary, '%PDF-' ), 'Generated output starts with valid %PDF- header signature' );
assert_true( strlen( $pdf_binary ) > 1000, 'Generated PDF binary size is substantial (> 1KB): ' . strlen( $pdf_binary ) . ' bytes' );

// Test save PDF to file
$save_result = $pdf_renderer->save_pdf_to_file( $test_payload );
assert_true( false !== $save_result, 'save_pdf_to_file returned success' );
assert_true( file_exists( $save_result['path'] ), 'PDF file physically created at path: ' . $save_result['path'] );
assert_true( filesize( $save_result['path'] ) > 1000, 'Physical PDF file size verified (> 1KB)' );

// Clean up test file
if ( file_exists( $save_result['path'] ) ) {
	@unlink( $save_result['path'] );
}

echo "\n------------------------------------------------------\n";
echo "  TEST SUMMARY: {$tests_passed}/{$tests_run} assertions PASSED";
if ( $tests_failed > 0 ) {
	echo " (\033[31m{$tests_failed} FAILED\033[0m)";
} else {
	echo " (\033[32mALL PASS\033[0m)";
}
echo "\n======================================================\n\n";

exit( $tests_failed > 0 ? 1 : 0 );
