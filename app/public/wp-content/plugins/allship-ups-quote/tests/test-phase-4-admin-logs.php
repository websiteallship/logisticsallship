<?php
/**
 * Phase 4 Unit Test Suite: Admin Quote Logs UI & Data Management
 *
 * Tests:
 * 1. Allship_UPS_Quote_Pdf_Renderer::cleanup_old_quotes()
 * 2. Allship_UPS_Quote_Log_Repository filtering (lead_type, search in breakdown) & exports (CSV, Excel)
 * 3. Allship_UPS_Quote_Logs_List_Table (column_contact, column_actions with PDF link, extra_tablenav filter)
 * 4. admin/views/quote-logs.php modal JavaScript template verification
 *
 * Run with: php tests/test-phase-4-admin-logs.php
 */

define( 'ABSPATH', sys_get_temp_dir() . '/allship_wp_test_phase4/' );
define( 'ALLSHIP_TESTING', true );
define( 'ALLSHIP_UPS_QUOTE_PATH', dirname( __DIR__ ) . '/' );

// Mock WordPress functions
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $val ) {
		return is_array( $val ) ? array_map( 'wp_unslash', $val ) : stripslashes( (string) $val );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $str, $domain = 'default' ) {
		return esc_html( $str );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $str, $domain = 'default' ) {
		return esc_attr( $str );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $str, $domain = 'default' ) {
		return $str;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $val ) {
		return abs( (int) $val );
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $echo = true ) {
		$res = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
		if ( $echo ) echo $res;
		return $res;
	}
}
if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num_words = 55, $more = null ) {
		$words = preg_split( '/\s+/', trim( (string) $text ) );
		if ( count( $words ) <= $num_words ) {
			return $text;
		}
		return implode( ' ', array_slice( $words, 0, $num_words ) ) . ( null === $more ? '&hellip;' : $more );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data, JSON_UNESCAPED_UNICODE );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return true;
	}
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return [
			'basedir' => ABSPATH . 'uploads',
			'baseurl' => 'http://localhost/uploads',
		];
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'http://localhost/wp-admin/' . ltrim( $path, '/' );
	}
}

// Mock WP_List_Table
if ( ! class_exists( 'WP_List_Table' ) ) {
	class WP_List_Table {
		public function __construct( $args = [] ) {}
		public function get_pagenum() { return 1; }
		public function set_pagination_args( $args ) {}
		public function current_action() { return false; }
	}
}

// Mock WPDB
class Mock_WPDB_Phase4 {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $data = [];

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

	public function insert( $table, $data, $format ) {
		$this->insert_id++;
		$data['id'] = $this->insert_id;
		$this->data[ $this->insert_id ] = (object) $data;
		return 1;
	}

	public function get_results( $query ) {
		$results = [];
		$clean_q = stripslashes( $query );
		foreach ( $this->data as $item ) {
			$match = true;

			// Lead type: pdf_export
			if ( strpos( $clean_q, '%"type":"pdf_export"%' ) !== false && strpos( $clean_q, 'NOT LIKE' ) === false ) {
				if ( empty( $item->breakdown_json ) || ( strpos( $item->breakdown_json, '"type":"pdf_export"' ) === false && strpos( $item->breakdown_json, '"lead_source":"ups_quote_pdf_export"' ) === false ) ) {
					$match = false;
				}
			// Lead type: booking
			} elseif ( strpos( $clean_q, 'NOT LIKE' ) !== false && strpos( $clean_q, '%"type":"pdf_export"%' ) !== false ) {
				$has_pdf = ! empty( $item->breakdown_json ) && ( strpos( $item->breakdown_json, '"type":"pdf_export"' ) !== false || strpos( $item->breakdown_json, '"lead_source":"ups_quote_pdf_export"' ) !== false );
				$has_contact = ! empty( $item->breakdown_json ) && ( strpos( $item->breakdown_json, '"contact"' ) !== false || strpos( $item->breakdown_json, '"lead"' ) !== false );
				if ( $has_pdf || ! $has_contact ) {
					$match = false;
				}
			// Lead type: guest
			} elseif ( strpos( $clean_q, 'breakdown_json IS NULL' ) !== false ) {
				$has_contact = ! empty( $item->breakdown_json ) && ( strpos( $item->breakdown_json, '"contact"' ) !== false || strpos( $item->breakdown_json, '"lead"' ) !== false );
				if ( $has_contact ) {
					$match = false;
				}
			}

			// Search mock
			if ( preg_match( "/breakdown_json LIKE '%([^%]+)%'/i", $clean_q, $m ) ) {
				$term = $m[1];
				if ( empty( $item->breakdown_json ) || stripos( $item->breakdown_json, $term ) === false ) {
					$match = false;
				}
			}

			if ( $match ) {
				$results[] = clone $item;
			}
		}
		return $results;
	}

	public function get_var( $query ) {
		return count( $this->get_results( $query ) );
	}

	public function get_row( $query ) {
		if ( preg_match( '/WHERE id = (\d+)/', $query, $m ) ) {
			$id = (int) $m[1];
			return isset( $this->data[ $id ] ) ? clone $this->data[ $id ] : null;
		}
		return null;
	}

	public function delete( $table, $where, $where_format ) {
		if ( isset( $where['id'] ) && isset( $this->data[ $where['id'] ] ) ) {
			unset( $this->data[ $where['id'] ] );
			return 1;
		}
		return 0;
	}
}

// Require classes under test
require_once dirname( __DIR__ ) . '/includes/class-quote-pdf-renderer.php';
require_once dirname( __DIR__ ) . '/includes/class-quote-log-repository.php';
require_once dirname( __DIR__ ) . '/admin/class-admin-quote-logs.php';

// Test runner state
$total_tests  = 0;
$passed_tests = 0;
$failed_tests = 0;

function assert_test( $condition, $message ) {
	global $total_tests, $passed_tests, $failed_tests;
	$total_tests++;
	if ( $condition ) {
		$passed_tests++;
		echo "  \033[32m✔ PASS\033[0m: {$message}\n";
	} else {
		$failed_tests++;
		echo "  \033[31m✖ FAIL\033[0m: {$message}\n";
	}
}

echo "========================================================\n";
echo " ALLSHIP UPS QUOTE - PHASE 4 TEST SUITE\n";
echo "========================================================\n\n";

// ------------------------------------------------------------------------
// Group 1: PDF Cleanup Service (Task 4.5)
// ------------------------------------------------------------------------
echo "[Group 1: PDF Old Files Cleanup]\n";

$test_quotes_dir = ABSPATH . 'uploads/allship-quotes/2026/01';
if ( ! is_dir( $test_quotes_dir ) ) {
	mkdir( $test_quotes_dir, 0777, true );
}

// Create an old PDF file (dated 40 days ago)
$old_pdf = $test_quotes_dir . '/AS-QUO-OLD.pdf';
file_put_contents( $old_pdf, '%PDF-1.4 Mock Old Quote' );
touch( $old_pdf, time() - ( 40 * 86400 ) );

// Create a recent PDF file (dated today)
$new_pdf = $test_quotes_dir . '/AS-QUO-NEW.pdf';
file_put_contents( $new_pdf, '%PDF-1.4 Mock New Quote' );
touch( $new_pdf, time() );

assert_test( file_exists( $old_pdf ), 'Old mock PDF file exists before cleanup' );
assert_test( file_exists( $new_pdf ), 'Recent mock PDF file exists before cleanup' );

$deleted = Allship_UPS_Quote_Pdf_Renderer::cleanup_old_quotes( 30 );
assert_test( $deleted >= 1, "cleanup_old_quotes(30) deleted at least 1 file (deleted: {$deleted})" );
assert_test( ! file_exists( $old_pdf ), 'Old PDF file (> 30 days) was deleted' );
assert_test( file_exists( $new_pdf ), 'Recent PDF file was preserved' );

// Cleanup test dir
@unlink( $new_pdf );
@rmdir( $test_quotes_dir );

// ------------------------------------------------------------------------
// Group 2: Repository Filtering & Broad Search (Task 4.1 & 4.2)
// ------------------------------------------------------------------------
echo "\n[Group 2: Repository Filtering & Lead Classification]\n";

$mock_wpdb = new Mock_WPDB_Phase4();
$repo = new Allship_UPS_Quote_Log_Repository( $mock_wpdb );

// Insert 1: PDF Quote Export Lead
$id1 = $repo->insert( [
	'direction'       => 'export',
	'destination_iata'=> 'US',
	'service_code'    => 'WXS',
	'total_price_vnd' => 2500000,
	'created_at'      => '2026-09-30 10:00:00',
	'breakdown_json'  => [
		'contact'     => [
			'name'      => 'Nguyễn Thị Bích',
			'company'   => 'Công ty Cổ phần Toàn Cầu',
			'email'     => 'bich@toancau.vn',
			'phone'     => '0912345678',
			'quote_ref' => 'AS-QUO-202609-0015',
			'pdf_url'   => 'http://localhost/uploads/allship-quotes/2026/09/AS-QUO-202609-0015.pdf',
			'type'      => 'pdf_export',
		],
		'lead_source' => 'ups_quote_pdf_export',
	],
] );

// Insert 2: Regular Booking Modal Lead
$id2 = $repo->insert( [
	'direction'       => 'export',
	'destination_iata'=> 'JP',
	'service_code'    => 'EXW',
	'total_price_vnd' => 1800000,
	'created_at'      => '2026-09-30 11:00:00',
	'breakdown_json'  => [
		'contact'     => [
			'name'        => 'Trần Văn Cường',
			'phone'       => '0987654321',
			'ff_entry_id' => 88,
		],
		'lead_source' => 'fluentform',
	],
] );

// Insert 3: Anonymous Guest (No contact info)
$id3 = $repo->insert( [
	'direction'       => 'import',
	'destination_iata'=> 'VN',
	'service_code'    => 'WXP',
	'total_price_vnd' => 5000000,
	'created_at'      => '2026-09-30 12:00:00',
	'breakdown_json'  => [],
] );

assert_test( $id1 === 1 && $id2 === 2 && $id3 === 3, 'Mock records inserted successfully' );

// Filter lead_type = pdf_export
$pdf_leads = $repo->get_filtered( [ 'lead_type' => 'pdf_export' ] );
assert_test( count( $pdf_leads ) === 1, 'lead_type=pdf_export returns exactly 1 record' );
assert_test( $pdf_leads[0]->id === 1, 'lead_type=pdf_export returned the correct record #1' );

// Filter lead_type = booking
$booking_leads = $repo->get_filtered( [ 'lead_type' => 'booking' ] );
assert_test( count( $booking_leads ) === 1, 'lead_type=booking returns exactly 1 record' );
assert_test( $booking_leads[0]->id === 2, 'lead_type=booking returned the correct record #2' );

// Filter lead_type = guest
$guest_logs = $repo->get_filtered( [ 'lead_type' => 'guest' ] );
assert_test( count( $guest_logs ) === 1, 'lead_type=guest returns exactly 1 record' );
assert_test( $guest_logs[0]->id === 3, 'lead_type=guest returned the correct record #3' );

// Search by quote_ref in breakdown
$search_ref = $repo->get_filtered( [ 'search' => 'AS-QUO-202609-0015' ] );
assert_test( count( $search_ref ) === 1 && $search_ref[0]->id === 1, 'Search finds record by quote_ref' );

// Search by company name in breakdown
$search_co = $repo->get_filtered( [ 'search' => 'Toàn Cầu' ] );
assert_test( count( $search_co ) === 1 && $search_co[0]->id === 1, 'Search finds record by company name' );

// Search by customer email in breakdown
$search_email = $repo->get_filtered( [ 'search' => 'bich@toancau.vn' ] );
assert_test( count( $search_email ) === 1 && $search_email[0]->id === 1, 'Search finds record by email' );

// ------------------------------------------------------------------------
// Group 3: CSV & Excel XML Export with Lead Columns (Task 4.4)
// ------------------------------------------------------------------------
echo "\n[Group 3: CSV & Excel XML Exports]\n";

// Test CSV Export Stream
$csv_stream = fopen( 'php://memory', 'r+' );
$repo->export_csv( [], $csv_stream );
rewind( $csv_stream );
$csv_content = stream_get_contents( $csv_stream );
fclose( $csv_stream );

assert_test( strpos( $csv_content, "\xEF\xBB\xBF" ) === 0, 'CSV export begins with UTF-8 BOM' );
assert_test( strpos( $csv_content, 'Company Name' ) !== false, 'CSV contains Company Name header' );
assert_test( strpos( $csv_content, 'Customer Email' ) !== false, 'CSV contains Customer Email header' );
assert_test( strpos( $csv_content, 'Quote Ref' ) !== false, 'CSV contains Quote Ref header' );
assert_test( strpos( $csv_content, 'Interaction Type' ) !== false, 'CSV contains Interaction Type header' );
assert_test( strpos( $csv_content, 'PDF URL' ) !== false, 'CSV contains PDF URL header' );
assert_test( strpos( $csv_content, 'Công ty Cổ phần Toàn Cầu' ) !== false, 'CSV contains customer company value' );
assert_test( strpos( $csv_content, 'bich@toancau.vn' ) !== false, 'CSV contains customer email value' );
assert_test( strpos( $csv_content, 'AS-QUO-202609-0015' ) !== false, 'CSV contains quote_ref value' );
assert_test( strpos( $csv_content, 'Báo giá PDF' ) !== false, 'CSV classifies interaction as Báo giá PDF' );

// Test Excel XML Export Stream
$excel_stream = fopen( 'php://memory', 'r+' );
$repo->export_excel( [], $excel_stream );
rewind( $excel_stream );
$excel_content = stream_get_contents( $excel_stream );
fclose( $excel_stream );

assert_test( strpos( $excel_content, '<?xml version="1.0" encoding="UTF-8"?>' ) !== false, 'Excel export is valid XML' );
assert_test( strpos( $excel_content, 'Company Name' ) !== false, 'Excel XML contains Company Name header' );
assert_test( strpos( $excel_content, 'Quote Ref' ) !== false, 'Excel XML contains Quote Ref header' );
assert_test( strpos( $excel_content, 'AS-QUO-202609-0015' ) !== false, 'Excel XML contains quote_ref data' );

// ------------------------------------------------------------------------
// Group 4: WP_List_Table Column Renderers (Task 4.2)
// ------------------------------------------------------------------------
echo "\n[Group 4: List Table UI Columns]\n";

$table = new Allship_UPS_Quote_Logs_List_Table( $repo );

// Reflection to test protected column methods
$ref_class = new ReflectionClass( 'Allship_UPS_Quote_Logs_List_Table' );
$col_contact = $ref_class->getMethod( 'column_contact' );
$col_contact->setAccessible( true );

$col_actions = $ref_class->getMethod( 'column_actions' );
$col_actions->setAccessible( true );

$contact1_html = $col_contact->invoke( $table, $mock_wpdb->data[1] );
assert_test( strpos( $contact1_html, 'Nguyễn Thị Bích' ) !== false, 'column_contact shows customer name' );
assert_test( strpos( $contact1_html, 'Công ty Cổ phần Toàn Cầu' ) !== false, 'column_contact shows company name' );
assert_test( strpos( $contact1_html, 'mailto:bich@toancau.vn' ) !== false, 'column_contact renders mailto: link' );
assert_test( strpos( $contact1_html, 'tel:0912345678' ) !== false, 'column_contact renders tel: link' );
assert_test( strpos( $contact1_html, 'as-badge--pdf' ) !== false, 'column_contact renders PDF badge' );
assert_test( strpos( $contact1_html, 'AS-QUO-202609-0015' ) !== false, 'column_contact displays quote_ref in badge' );

$contact3_html = $col_contact->invoke( $table, $mock_wpdb->data[3] );
assert_test( strpos( $contact3_html, 'Khách vãng lai' ) !== false, 'column_contact displays Khách vãng lai for guest' );

$action1_html = $col_actions->invoke( $table, $mock_wpdb->data[1] );
assert_test( strpos( $action1_html, 'btn-view-log-detail' ) !== false, 'column_actions has detail button' );
assert_test( strpos( $action1_html, 'dashicons-pdf' ) !== false, 'column_actions renders PDF icon for PDF quote log' );
assert_test( strpos( $action1_html, 'AS-QUO-202609-0015.pdf' ) !== false, 'column_actions links to pdf_url' );

$action2_html = $col_actions->invoke( $table, $mock_wpdb->data[2] );
assert_test( strpos( $action2_html, 'dashicons-pdf' ) === false, 'column_actions does not render PDF link when no PDF was exported' );

// ------------------------------------------------------------------------
// Group 5: View & Modal Detail Template (Task 4.3)
// ------------------------------------------------------------------------
echo "\n[Group 5: Admin View & Detail Modal JS]\n";

$view_file = dirname( __DIR__ ) . '/admin/views/quote-logs.php';
assert_test( file_exists( $view_file ), 'admin/views/quote-logs.php exists' );

$view_code = file_get_contents( $view_file );
assert_test( strpos( $view_code, "'lead_type'" ) !== false, 'views/quote-logs.php includes lead_type in current_filters' );
assert_test( strpos( $view_code, 'isPdfLead' ) !== false, 'views/quote-logs.php checks isPdfLead in modal script' );
assert_test( strpos( $view_code, 'Thông Tin Khách Hàng Xuất Báo Giá PDF' ) !== false, 'views/quote-logs.php modal renders B2B PDF quote heading' );
assert_test( strpos( $view_code, 'Mở File PDF Báo Giá Chính Thức' ) !== false, 'views/quote-logs.php modal contains direct PDF open button' );
assert_test( strpos( $view_code, 'log.breakdown.contact.company' ) !== false || strpos( $view_code, 'contact.company' ) !== false, 'views/quote-logs.php modal displays company name' );

echo "\n========================================================\n";
echo " TEST SUMMARY\n";
echo "========================================================\n";
echo "Total tests: {$total_tests}\n";
echo "Passed:      \033[32m{$passed_tests}\033[0m\n";
echo "Failed:      " . ( $failed_tests > 0 ? "\033[31m{$failed_tests}\033[0m" : "0" ) . "\n\n";

if ( $failed_tests === 0 ) {
	echo "🎉 ALL PHASE 4 UNIT TESTS PASSED SUCCESSFULLY!\n\n";
	exit( 0 );
} else {
	echo "❌ SOME TESTS FAILED.\n\n";
	exit( 1 );
}
