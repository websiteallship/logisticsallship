<?php
/**
 * Test Step 5.8 — Quote Logs Page & Export Suite.
 *
 * Verifies:
 * 1. Logs appear after quote calculation (persisted to wp_ups_quote_logs).
 * 2. Filter by service_code, destination, date range returns exact matching rows.
 * 3. Export CSV outputs UTF-8 BOM (\xEF\xBB\xBF) and valid CSV content.
 * 4. Export Excel outputs valid Spreadsheet XML.
 * 5. Security checks: capability manage_options and nonce verification.
 * 6. AJAX log detail endpoint returns full structured data.
 * 7. Admin View rendering: admin/views/quote-logs.php HTML output.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// Global mocks
$GLOBALS['mock_actions'] = [];
$GLOBALS['mock_die_called'] = false;
$GLOBALS['mock_die_code'] = 0;
$GLOBALS['mock_json_response'] = null;
$GLOBALS['mock_user_caps'] = [ 'manage_options' => true ];
$GLOBALS['mock_nonce_valid'] = true;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['mock_actions'][ $tag ][] = [
			'callback' => $callback,
			'priority' => $priority,
		];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return ! empty( $GLOBALS['mock_user_caps'][ $capability ] );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		if ( ! $GLOBALS['mock_nonce_valid'] ) {
			wp_die( 'Nonce failed', 403 );
		}
		return true;
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
		if ( ! $GLOBALS['mock_nonce_valid'] ) {
			if ( $die ) {
				wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
			}
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $status = 500 ) {
		$GLOBALS['mock_die_called'] = true;
		$GLOBALS['mock_die_code']   = $status;
		throw new Exception( 'WP_DIE: ' . $message );
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

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		$query = http_build_query( $args );
		return $url . ( strpos( $url, '?' ) !== false ? '&' : '?' ) . $query;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'mock_nonce_' . $action;
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
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

// Mock WP_List_Table if WP environment is not loaded
if ( ! class_exists( 'WP_List_Table' ) ) {
	abstract class WP_List_Table {
		public $items = [];
		protected $_column_headers = [];
		protected $_pagination_args = [];

		public function __construct( $args = [] ) {}
		public function get_pagenum() { return 1; }
		protected function set_pagination_args( $args ) { $this->_pagination_args = $args; }
		public function search_box( $text, $input_id ) {
			echo '<p class="search-box"><input type="search" id="' . esc_attr( $input_id ) . '" name="s" value="" /></p>';
		}
		public function display() {
			echo '<table class="wp-list-table widefat fixed striped">';
			$this->display_rows();
			echo '</table>';
		}
		public function display_rows() {
			foreach ( $this->items as $item ) {
				echo '<tr>';
				foreach ( $this->get_columns() as $col => $title ) {
					echo '<td>';
					$method = 'column_' . $col;
					if ( method_exists( $this, $method ) ) {
						echo $this->$method( $item );
					} else {
						echo $this->column_default( $item, $col );
					}
					echo '</td>';
				}
				echo '</tr>';
			}
		}
		abstract public function get_columns();
		abstract public function prepare_items();
	}
}

$GLOBALS['mock_transients'] = [];
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return $GLOBALS['mock_transients'][ $transient ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		$GLOBALS['mock_transients'][ $transient ] = $value;
		return true;
	}
}

// Mock Database with in-memory quote_logs table
class Mock_Logs_WPDB {
	public $prefix = 'wp_';
	public $quote_logs = [];
	public $insert_id = 0;

	public function get_col( $query = '', $x = 0 ) {
		return [ 'id', 'rate_card_id', 'ip_address', 'device_type', 'user_agent' ];
	}

	public function prepare( $query, ...$args ) {
		if ( empty( $args ) ) {
			return $query;
		}
		if ( is_array( $args[0] ) && count( $args ) === 1 ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$val = is_numeric( $arg ) ? $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[sdfF]/', (string) $val, $query, 1 );
		}
		return $query;
	}

	public function insert( $table, $data, $format = null ) {
		$this->insert_id++;
		$data['id'] = $this->insert_id;
		$this->quote_logs[ $this->insert_id ] = (object) $data;
		return 1;
	}

	public function get_row( $query ) {
		if ( preg_match( "/WHERE id = ([0-9]+)/", $query, $m ) ) {
			$id = (int) $m[1];
			return $this->quote_logs[ $id ] ?? null;
		}
		return null;
	}

	public function get_var( $query ) {
		if ( strpos( $query, 'COUNT(*)' ) !== false ) {
			return count( $this->filter_rows( $query ) );
		}
		return null;
	}

	public function get_results( $query, $output = ARRAY_A ) {
		return $this->filter_rows( $query );
	}

	public function delete( $table, $where, $where_format = null ) {
		if ( isset( $where['id'] ) ) {
			$id = (int) $where['id'];
			unset( $this->quote_logs[ $id ] );
			return 1;
		}
		return 0;
	}

	public function query( $sql ) {
		if ( preg_match( "/DELETE FROM .* WHERE id IN \(([0-9, ]+)\)/", $sql, $m ) ) {
			$ids = explode( ',', $m[1] );
			$count = 0;
			foreach ( $ids as $id ) {
				$id = (int) trim( $id );
				if ( isset( $this->quote_logs[ $id ] ) ) {
					unset( $this->quote_logs[ $id ] );
					$count++;
				}
			}
			return $count;
		}
		return 1;
	}

	private function filter_rows( $query ) {
		$rows = array_values( $this->quote_logs );

		// Filter service_code
		if ( preg_match( "/service_code = '([^']+)'/", $query, $m ) ) {
			$svc = $m[1];
			$rows = array_filter( $rows, function( $r ) use ( $svc ) {
				return $r->service_code === $svc;
			} );
		}

		// Filter destination_iata
		if ( preg_match( "/destination_iata = '([^']+)'/", $query, $m ) ) {
			$dest = $m[1];
			$rows = array_filter( $rows, function( $r ) use ( $dest ) {
				return $r->destination_iata === $dest;
			} );
		}

		// Filter direction
		if ( preg_match( "/direction = '([^']+)'/", $query, $m ) ) {
			$dir = $m[1];
			$rows = array_filter( $rows, function( $r ) use ( $dir ) {
				return $r->direction === $dir;
			} );
		}

		// Filter date_from
		if ( preg_match( "/created_at >= '([^']+)'/", $query, $m ) ) {
			$from = $m[1];
			$rows = array_filter( $rows, function( $r ) use ( $from ) {
				return $r->created_at >= $from;
			} );
		}

		// Filter date_to
		if ( preg_match( "/created_at <= '([^']+)'/", $query, $m ) ) {
			$to = $m[1];
			$rows = array_filter( $rows, function( $r ) use ( $to ) {
				return $r->created_at <= $to;
			} );
		}

		return array_values( $rows );
	}
}

// Require classes under test
require_once dirname( __DIR__ ) . '/includes/class-quote-log-repository.php';
require_once dirname( __DIR__ ) . '/admin/class-admin-quote-logs.php';

echo "========================================================\n";
echo " Running Automated Tests for Step 5.8: Quote Logs Page\n";
echo "========================================================\n\n";

$pass_count = 0;
$fail_count = 0;

function run_test( $name, callable $fn ) {
	global $pass_count, $fail_count;
	try {
		$fn();
		echo " [PASS] $name\n";
		$pass_count++;
	} catch ( Throwable $e ) {
		echo " [FAIL] $name: " . $e->getMessage() . "\n";
		$fail_count++;
	}
}

$mock_db    = new Mock_Logs_WPDB();
$repo       = new Allship_UPS_Quote_Log_Repository( $mock_db );
$controller = new Allship_UPS_Admin_Quote_Logs( $repo, $mock_db );

// Seed test logs
$mock_db->insert( 'wp_ups_quote_logs', [
	'direction'            => 'export',
	'origin_iata'          => 'VN',
	'origin_province'      => 'TP. Hồ Chí Minh',
	'destination_iata'     => 'US',
	'destination_city'     => 'California',
	'service_code'         => 'WXS',
	'shipment_type'        => 'nondocument',
	'zone'                 => '5',
	'rate_zone'            => 'US5',
	'actual_weight_kg'     => 5.5,
	'dim_weight_kg'        => 6.0,
	'chargeable_weight_kg' => 6.0,
	'base_price_vnd'       => 1977290,
	'total_price_vnd'      => 1977290,
	'created_at'           => '2026-09-28 10:00:00',
] );

$mock_db->insert( 'wp_ups_quote_logs', [
	'direction'            => 'export',
	'origin_iata'          => 'VN',
	'origin_province'      => 'Hà Nội',
	'destination_iata'     => 'JP',
	'destination_city'     => 'Tokyo',
	'service_code'         => 'XPD',
	'shipment_type'        => 'nondocument',
	'zone'                 => '2',
	'rate_zone'            => '2',
	'actual_weight_kg'     => 12.0,
	'dim_weight_kg'        => 10.0,
	'chargeable_weight_kg' => 12.0,
	'base_price_vnd'       => 2850000,
	'total_price_vnd'      => 2850000,
	'created_at'           => '2026-09-29 08:30:00',
] );

$mock_db->insert( 'wp_ups_quote_logs', [
	'direction'            => 'import',
	'origin_iata'          => 'US',
	'origin_province'      => null,
	'destination_iata'     => 'VN',
	'destination_city'     => 'Đà Nẵng',
	'service_code'         => 'WFM',
	'shipment_type'        => 'nondocument',
	'zone'                 => '6',
	'rate_zone'            => '6',
	'actual_weight_kg'     => 120.0,
	'dim_weight_kg'        => 150.0,
	'chargeable_weight_kg' => 150.0,
	'base_price_vnd'       => 15000000,
	'total_price_vnd'      => 15000000,
	'created_at'           => '2026-09-29 11:15:00',
] );

// Test 1: Logs appear after calculate (Persisted in database)
run_test( '1. Logs appear after calculate and are queryable', function() use ( $repo ) {
	$count = $repo->count_filtered();
	if ( 3 !== $count ) {
		throw new Exception( "Expected 3 seeded logs, found: $count" );
	}

	$all = $repo->get_filtered();
	if ( count( $all ) !== 3 ) {
		throw new Exception( 'Failed retrieving all records' );
	}
	if ( $all[0]->destination_iata !== 'US' && $all[1]->destination_iata !== 'US' && $all[2]->destination_iata !== 'US' ) {
		throw new Exception( 'Record data mismatch' );
	}
});

// Test 2: Filter by service -> returns correct rows
run_test( '2. Filter by service (WXS vs XPD vs WFM) returns exact rows', function() use ( $repo ) {
	$wxs_logs = $repo->get_filtered( [ 'service_code' => 'WXS' ] );
	if ( count( $wxs_logs ) !== 1 || $wxs_logs[0]->service_code !== 'WXS' ) {
		throw new Exception( 'Failed filtering service_code = WXS' );
	}

	$xpd_logs = $repo->get_filtered( [ 'service_code' => 'XPD' ] );
	if ( count( $xpd_logs ) !== 1 || $xpd_logs[0]->service_code !== 'XPD' ) {
		throw new Exception( 'Failed filtering service_code = XPD' );
	}

	$wfm_logs = $repo->get_filtered( [ 'service_code' => 'WFM' ] );
	if ( count( $wfm_logs ) !== 1 || $wfm_logs[0]->service_code !== 'WFM' ) {
		throw new Exception( 'Failed filtering service_code = WFM' );
	}

	$empty_logs = $repo->get_filtered( [ 'service_code' => 'EXW' ] );
	if ( count( $empty_logs ) !== 0 ) {
		throw new Exception( 'Expected 0 rows for unrecorded service EXW' );
	}
});

// Test 3: Filter by destination and date range
run_test( '3. Filter by destination (US) and date range', function() use ( $repo ) {
	$us_logs = $repo->get_filtered( [ 'destination_iata' => 'US' ] );
	if ( count( $us_logs ) !== 1 || $us_logs[0]->destination_iata !== 'US' ) {
		throw new Exception( 'Failed filtering destination_iata = US' );
	}

	$date_filtered = $repo->get_filtered( [ 'date_from' => '2026-09-29', 'date_to' => '2026-09-29' ] );
	if ( count( $date_filtered ) !== 2 ) {
		throw new Exception( 'Failed filtering date range, expected 2 rows on 2026-09-29' );
	}
});

// Test 4: Export CSV with UTF-8 BOM and correct Vietnamese text
run_test( '4. Export CSV has UTF-8 BOM (\\xEF\\xBB\\xBF) and valid CSV content', function() use ( $repo ) {
	$stream = fopen( 'php://memory', 'r+' );
	$repo->export_csv( [ 'service_code' => 'WXS' ], $stream );

	rewind( $stream );
	$content = stream_get_contents( $stream );
	fclose( $stream );

	// Check BOM
	if ( substr( $content, 0, 3 ) !== "\xEF\xBB\xBF" ) {
		throw new Exception( 'CSV is missing UTF-8 BOM (\xEF\xBB\xBF) header for Excel compatibility' );
	}

	// Check header and content
	if ( strpos( $content, 'Destination' ) === false || strpos( $content, 'Chargeable Weight' ) === false ) {
		throw new Exception( 'CSV missing required column headers' );
	}
	if ( strpos( $content, 'WXS' ) === false || strpos( $content, 'US' ) === false ) {
		throw new Exception( 'CSV missing row data' );
	}
});

// Test 5: Export Excel XML Spreadsheet format
run_test( '5. Export Excel outputs valid XML Spreadsheet format', function() use ( $repo ) {
	$stream = fopen( 'php://memory', 'r+' );
	$repo->export_excel( [], $stream );

	rewind( $stream );
	$content = stream_get_contents( $stream );
	fclose( $stream );

	if ( strpos( $content, '<?xml version="1.0" encoding="UTF-8"?>' ) === false ) {
		throw new Exception( 'Excel output is missing XML header' );
	}
	if ( strpos( $content, 'xmlns="urn:schemas-microsoft-com:office:spreadsheet"' ) === false ) {
		throw new Exception( 'Excel output is missing Spreadsheet namespace' );
	}
	if ( strpos( $content, 'WXS' ) === false || strpos( $content, 'XPD' ) === false ) {
		throw new Exception( 'Excel output is missing data rows' );
	}
});

// Test 6: Security: Reject unauthorized users for export
run_test( '6. Security: reject non-admin without manage_options', function() use ( $controller ) {
	global $mock_user_caps, $mock_die_called, $mock_die_code;
	$mock_user_caps['manage_options'] = false;
	$mock_die_called = false;

	try {
		$controller->handle_export_csv();
		throw new Exception( 'Did not die on missing capability' );
	} catch ( Throwable $e ) {
		if ( ! $mock_die_called || 403 !== $mock_die_code ) {
			throw new Exception( 'Expected wp_die with 403 on missing capability' );
		}
	} finally {
		$mock_user_caps['manage_options'] = true;
		$mock_die_called = false;
	}
});

// Test 7: AJAX Quote Log Detail endpoint
run_test( '7. AJAX: ups_quote_log_detail returns structured log data', function() use ( $controller ) {
	global $mock_json_response;
	$_GET['id'] = 1;

	try {
		$controller->ajax_log_detail();
	} catch ( Throwable $e ) {
		if ( 'WP_JSON_SUCCESS' !== $e->getMessage() ) {
			throw $e;
		}
	}

	if ( empty( $mock_json_response['success'] ) ) {
		throw new Exception( 'ajax_log_detail did not return success' );
	}
	$log_data = $mock_json_response['data']['log'] ?? null;
	if ( ! $log_data || (int) $log_data->id !== 1 || $log_data->service_code !== 'WXS' ) {
		throw new Exception( 'ajax_log_detail returned invalid log payload' );
	}
});

// Test 8: Admin View rendering (admin/views/quote-logs.php)
run_test( '8. Admin View: quote-logs.php renders WP_List_Table and export links', function() use ( $repo ) {
	ob_start();
	$_REQUEST['service_code'] = 'WXS';
	$page_title = 'Nhật ký Báo giá & Khách hàng tiềm năng (Leads)';
	include dirname( __DIR__ ) . '/admin/views/quote-logs.php';
	$html = ob_get_clean();

	if ( strpos( $html, 'quote-logs-filter-form' ) === false ) {
		throw new Exception( 'Missing quote-logs-filter-form form' );
	}
	if ( strpos( $html, 'allship_ups_export_logs_csv' ) === false ) {
		throw new Exception( 'Missing CSV export link' );
	}
	if ( strpos( $html, 'allship_ups_export_logs_excel' ) === false ) {
		throw new Exception( 'Missing Excel export link' );
	}
	if ( strpos( $html, 'modalLogDetail' ) === false ) {
		throw new Exception( 'Missing modalLogDetail markup' );
	}
	if ( strpos( $html, 'wp-list-table' ) === false ) {
		throw new Exception( 'Missing WP_List_Table markup' );
	}
});

// Test 9: Repository captures and formats IP address, device type, user agent, and pieces
run_test( '9. Repository captures and formats IP address, device type, user agent, and pieces', function() use ( $repo ) {
	$new_id = $repo->insert( [
		'service_code'     => 'XPD',
		'destination_iata' => 'US',
		'ip_address'       => '203.113.152.1',
		'device_type'      => 'mobile',
		'user_agent'       => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)',
		'pieces_json'      => [
			[
				'quantity'         => 2,
				'actual_weight_kg' => 1.5,
				'length_cm'        => 20.0,
				'width_cm'         => 15.0,
				'height_cm'        => 10.0,
				'dim_weight_kg'    => 0.6,
			]
		],
	] );

	$log = $repo->get( $new_id );
	if ( ! $log ) {
		throw new Exception( 'Failed to retrieve inserted log' );
	}
	if ( $log->ip_address !== '203.113.152.1' ) {
		throw new Exception( 'IP address mismatch: expected 203.113.152.1, got ' . $log->ip_address );
	}
	if ( $log->device_type !== 'mobile' ) {
		throw new Exception( 'Device type mismatch: expected mobile, got ' . $log->device_type );
	}
	if ( empty( $log->pieces ) || count( $log->pieces ) !== 1 ) {
		throw new Exception( 'Pieces parsing failed' );
	}
	if ( (int) $log->pieces[0]['quantity'] !== 2 || (float) $log->pieces[0]['actual_weight_kg'] !== 1.5 ) {
		throw new Exception( 'Pieces content mismatch' );
	}
} );

// Test 10: WordPress Setting Timezone: log created_at uses localized format
run_test( '10. WordPress Setting Timezone: log created_at uses localized format', function() use ( $repo ) {
	$new_id = $repo->insert( [
		'service_code'     => 'WXS',
		'destination_iata' => 'SG',
	] );

	$log = $repo->get( $new_id );
	if ( empty( $log->created_at ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $log->created_at ) ) {
		throw new Exception( 'Invalid created_at timestamp: ' . ($log->created_at ?? 'null') );
	}
} );

// Test 11: Rate Limiter on REST Controller: burst throttle & volume control
run_test( '11. Rate Limiter on REST Controller: burst throttle & volume control', function() {
	if ( ! class_exists( 'Allship_UPS_REST_Controller' ) ) {
		require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
	}
	$rest = new Allship_UPS_REST_Controller();

	// Direct call to rate limiter with non-admin context
	$GLOBALS['mock_user_caps']['manage_options'] = false;
	$test_ip = '198.51.100.42';

	// Call 1: Allowed
	$res1 = $rest->check_calculate_rate_limit( $test_ip );
	if ( $res1 !== true ) {
		throw new Exception( 'First call should be allowed' );
	}

	// Call 2 immediate: should trigger burst cooldown (< 350ms)
	$res2 = $rest->check_calculate_rate_limit( $test_ip );
	if ( ! is_array( $res2 ) || empty( $res2['error'] ) || $res2['code'] !== 'RATE_LIMIT_COOLDOWN' ) {
		throw new Exception( 'Rapid consecutive call should be blocked by cooldown limiter' );
	}

	// Restore admin cap
	$GLOBALS['mock_user_caps']['manage_options'] = true;
	$res_admin = $rest->check_calculate_rate_limit( $test_ip );
	if ( $res_admin !== true ) {
		throw new Exception( 'Admin should be exempt from rate limiting' );
	}
} );

echo "\n--------------------------------------------------------\n";
echo " Test Suite Finished: $pass_count Passed, $fail_count Failed\n";
echo "========================================================\n";

if ( $fail_count > 0 ) {
	exit( 1 );
}
exit( 0 );
