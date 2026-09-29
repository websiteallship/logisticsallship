<?php
/**
 * Test Step 5.7 — Settings Page Suite.
 *
 * Verifies:
 * 1. Allship_UPS_Admin_Settings hook registrations (admin_init, admin_post, wp_ajax).
 * 2. WP Settings API sections & fields registration (Weight, Fees, Advanced).
 * 3. Change dim_divisor -> save -> reload -> value persisted.
 * 4. Toggle VAT ON -> save -> value = true, vat_percent persisted.
 * 5. Invalid values -> sanitized (negative dim_divisor, negative/out-of-bounds percent, negative fees).
 * 6. Security gating: capability 'manage_options' and nonce validation.
 * 7. Transient cache invalidation upon settings save.
 * 8. View rendering: admin/views/settings.php output structure.
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
$GLOBALS['mock_settings_sections'] = [];
$GLOBALS['mock_settings_fields'] = [];
$GLOBALS['mock_redirect'] = null;
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

if ( ! function_exists( 'add_settings_section' ) ) {
	function add_settings_section( $id, $title, $callback, $page ) {
		$GLOBALS['mock_settings_sections'][ $page ][ $id ] = [
			'id'       => $id,
			'title'    => $title,
			'callback' => $callback,
		];
	}
}

if ( ! function_exists( 'add_settings_field' ) ) {
	function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = [] ) {
		$GLOBALS['mock_settings_fields'][ $page ][ $section ][ $id ] = [
			'id'       => $id,
			'title'    => $title,
			'callback' => $callback,
			'args'     => $args,
		];
	}
}

if ( ! function_exists( 'do_settings_sections' ) ) {
	function do_settings_sections( $page ) {
		if ( empty( $GLOBALS['mock_settings_sections'][ $page ] ) ) {
			return;
		}
		foreach ( $GLOBALS['mock_settings_sections'][ $page ] as $sec_id => $sec ) {
			echo '<h2>' . esc_html( $sec['title'] ) . '</h2>';
			if ( is_callable( $sec['callback'] ) ) {
				call_user_func( $sec['callback'] );
			}
			echo '<table class="form-table"><tbody>';
			if ( ! empty( $GLOBALS['mock_settings_fields'][ $page ][ $sec_id ] ) ) {
				foreach ( $GLOBALS['mock_settings_fields'][ $page ][ $sec_id ] as $f_id => $field ) {
					echo '<tr><th>' . esc_html( $field['title'] ) . '</th><td>';
					if ( is_callable( $field['callback'] ) ) {
						call_user_func( $field['callback'], $field['args'] ?? [] );
					}
					echo '</td></tr>';
				}
			}
			echo '</tbody></table>';
		}
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

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302 ) {
		$GLOBALS['mock_redirect'] = $location;
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

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
		$html = sprintf( '<input type="hidden" name="%s" value="mock_nonce_%s">', esc_attr( $name ), esc_attr( $action ) );
		if ( $echo ) {
			echo $html;
		}
		return $html;
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $echo = true ) {
		$result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
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
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

// Mock WPDB
class Mock_Settings_WPDB {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $settings = [];
	public $deleted_queries = [];

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

	public function get_results( $query, $output = ARRAY_A ) {
		$rows = [];
		foreach ( $this->settings as $k => $v ) {
			$rows[] = [
				'setting_key'   => $k,
				'setting_value' => $v,
			];
		}
		return $rows;
	}

	public function get_var( $query ) {
		if ( preg_replace( '/\s+/', ' ', trim( $query ) ) === "SELECT setting_value FROM {$this->prefix}ups_settings WHERE setting_key = 'delete_data_on_uninstall'" ) {
			return $this->settings['delete_data_on_uninstall'] ?? null;
		}
		if ( strpos( $query, 'WHERE setting_key =' ) !== false ) {
			preg_match( "/WHERE setting_key = '([^']+)'/", $query, $m );
			if ( ! empty( $m[1] ) && array_key_exists( $m[1], $this->settings ) ) {
				return '1'; // exists
			}
		}
		return null;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$key = $where['setting_key'];
		$this->settings[ $key ] = $data['setting_value'];
		return 1;
	}

	public function insert( $table, $data, $format = null ) {
		$key = $data['setting_key'];
		$this->settings[ $key ] = $data['setting_value'];
		return 1;
	}

	public function query( $sql ) {
		if ( strpos( $sql, 'DELETE FROM' ) !== false ) {
			$this->deleted_queries[] = $sql;
			return 1;
		}
		return 1;
	}
}

// Require needed classes
require_once dirname( __DIR__ ) . '/includes/class-settings-manager.php';
require_once dirname( __DIR__ ) . '/admin/class-admin-settings.php';

echo "========================================================\n";
echo " Running Automated Tests for Step 5.7: Settings Page\n";
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

$mock_db          = new Mock_Settings_WPDB();
$settings_manager = new Allship_UPS_Settings_Manager( $mock_db );
$controller       = new Allship_UPS_Admin_Settings( $settings_manager, $mock_db );

// Test 1: Hook Registration
run_test( '1. Hook registrations: admin_init, admin_post, wp_ajax', function() use ( $controller ) {
	$controller->register_hooks();
	global $mock_actions;
	if ( empty( $mock_actions['admin_init'] ) ) {
		throw new Exception( 'admin_init not registered' );
	}
	if ( empty( $mock_actions['admin_post_allship_ups_save_settings'] ) ) {
		throw new Exception( 'admin_post_allship_ups_save_settings not registered' );
	}
	if ( empty( $mock_actions['wp_ajax_allship_ups_save_settings'] ) ) {
		throw new Exception( 'wp_ajax_allship_ups_save_settings not registered' );
	}
});

// Test 2: WP Settings API Sections and Fields Registration
run_test( '2. WP Settings API sections & fields registration', function() use ( $controller ) {
	global $mock_settings_sections, $mock_settings_fields;
	$controller->register_settings();

	$page = Allship_UPS_Admin_Settings::SETTINGS_PAGE;
	if ( empty( $mock_settings_sections[ $page ]['allship_ups_section_weight'] ) ) {
		throw new Exception( 'Missing Weight section' );
	}
	if ( empty( $mock_settings_sections[ $page ]['allship_ups_section_fees'] ) ) {
		throw new Exception( 'Missing Fees section' );
	}
	if ( empty( $mock_settings_sections[ $page ]['allship_ups_section_advanced'] ) ) {
		throw new Exception( 'Missing Advanced section' );
	}

	// Verify required fields
	$weight_fields = $mock_settings_fields[ $page ]['allship_ups_section_weight'];
	if ( ! isset( $weight_fields['allship_ups_dim_divisor'] ) ) {
		throw new Exception( 'Missing dim_divisor field' );
	}
	if ( ! isset( $weight_fields['allship_ups_rounding_step_kg'] ) ) {
		throw new Exception( 'Missing rounding_step_kg field' );
	}

	$fee_fields = $mock_settings_fields[ $page ]['allship_ups_section_fees'];
	if ( ! isset( $fee_fields['allship_ups_vat'] ) ) {
		throw new Exception( 'Missing VAT field' );
	}
	if ( ! isset( $fee_fields['allship_ups_fsc'] ) ) {
		throw new Exception( 'Missing FSC field' );
	}
	if ( ! isset( $fee_fields['allship_ups_surge'] ) ) {
		throw new Exception( 'Missing Surge field' );
	}
	if ( ! isset( $fee_fields['allship_ups_customs_fee'] ) ) {
		throw new Exception( 'Missing Customs fee field' );
	}

	$adv_fields = $mock_settings_fields[ $page ]['allship_ups_section_advanced'];
	if ( ! isset( $adv_fields['allship_ups_delete_data_on_uninstall'] ) ) {
		throw new Exception( 'Missing delete_data_on_uninstall field' );
	}
});

// Test 3: Change dim_divisor -> save -> reload -> value persisted
run_test( '3. Change dim_divisor -> save -> reload -> value persisted', function() use ( $controller, $settings_manager, $mock_db ) {
	$input = [
		'dim_divisor'      => '5000',
		'rounding_step_kg' => '0.5',
	];

	$success = $controller->save_settings( $input );
	if ( ! $success ) {
		throw new Exception( 'save_settings returned false' );
	}

	// Reload from a fresh manager instance simulating new request
	Allship_UPS_Settings_Manager::clear_cache();
	$fresh_manager = new Allship_UPS_Settings_Manager( $mock_db );
	$val = $fresh_manager->get( 'dim_divisor' );

	if ( 5000 !== $val ) {
		throw new Exception( 'Expected dim_divisor to be 5000, got: ' . var_export( $val, true ) );
	}
});

// Test 4: Toggle VAT ON -> save -> value = true, vat_percent persisted
run_test( '4. Toggle VAT ON -> save -> value = true & vat_percent persisted', function() use ( $controller, $mock_db ) {
	$input = [
		'include_vat' => '1',
		'vat_percent' => '8.5',
	];

	$controller->save_settings( $input );

	Allship_UPS_Settings_Manager::clear_cache();
	$fresh_manager = new Allship_UPS_Settings_Manager( $mock_db );

	$vat_on  = $fresh_manager->get( 'include_vat' );
	$vat_pct = $fresh_manager->get( 'vat_percent' );

	if ( true !== $vat_on ) {
		throw new Exception( 'Expected include_vat to be true, got: ' . var_export( $vat_on, true ) );
	}
	if ( 8.5 !== $vat_pct ) {
		throw new Exception( 'Expected vat_percent to be 8.5, got: ' . var_export( $vat_pct, true ) );
	}

	// Test Toggle OFF
	$input_off = [
		'include_vat' => '',
		'vat_percent' => '8.5',
	];
	$controller->save_settings( $input_off );
	Allship_UPS_Settings_Manager::clear_cache();
	$fresh_manager2 = new Allship_UPS_Settings_Manager( $mock_db );
	if ( false !== $fresh_manager2->get( 'include_vat' ) ) {
		throw new Exception( 'Expected include_vat to be false when omitted' );
	}
});

// Test 5: Invalid values -> sanitized
run_test( '5. Invalid values -> properly sanitized', function() use ( $controller ) {
	$bad_input = [
		'dim_divisor'              => '-999',
		'rounding_step_kg'         => '-2.5',
		'vat_percent'              => '180.5',
		'fsc_percent'              => '-15',
		'surge_percent'            => 'not_a_number',
		'customs_fee_vnd'          => '-50000',
		'delete_data_on_uninstall' => '0',
	];

	$cleaned = $controller->sanitize_settings( $bad_input );

	// dim_divisor negative -> fallback 5500
	if ( $cleaned['dim_divisor'] !== 5500 ) {
		throw new Exception( 'Expected dim_divisor fallback 5500, got: ' . $cleaned['dim_divisor'] );
	}
	// rounding_step_kg negative -> fallback 0.5
	if ( $cleaned['rounding_step_kg'] !== 0.5 ) {
		throw new Exception( 'Expected rounding_step_kg fallback 0.5, got: ' . $cleaned['rounding_step_kg'] );
	}
	// vat_percent > 100 -> clamped to 100.0
	if ( $cleaned['vat_percent'] !== 100.0 ) {
		throw new Exception( 'Expected vat_percent clamped to 100.0, got: ' . $cleaned['vat_percent'] );
	}
	// fsc_percent < 0 -> clamped to 0.0
	if ( $cleaned['fsc_percent'] !== 0.0 ) {
		throw new Exception( 'Expected fsc_percent clamped to 0.0, got: ' . $cleaned['fsc_percent'] );
	}
	// surge_percent non-numeric -> 0.0
	if ( $cleaned['surge_percent'] !== 0.0 ) {
		throw new Exception( 'Expected surge_percent 0.0, got: ' . $cleaned['surge_percent'] );
	}
	// customs_fee_vnd negative -> clamped to 0
	if ( $cleaned['customs_fee_vnd'] !== 0 ) {
		throw new Exception( 'Expected customs_fee_vnd clamped to 0, got: ' . $cleaned['customs_fee_vnd'] );
	}
	// delete_data_on_uninstall false
	if ( $cleaned['delete_data_on_uninstall'] !== false ) {
		throw new Exception( 'Expected delete_data_on_uninstall false' );
	}
});

// Test 6: Security gating: capability check
run_test( '6. Security: reject unauthorized user without manage_options', function() use ( $controller ) {
	global $mock_user_caps, $mock_die_called, $mock_die_code;
	$mock_user_caps['manage_options'] = false;
	$mock_die_called = false;

	try {
		$controller->handle_save_settings();
		throw new Exception( 'Did not die on missing capability' );
	} catch ( Throwable $e ) {
		if ( ! $mock_die_called || 403 !== $mock_die_code ) {
			throw new Exception( 'Expected wp_die with 403 status code' );
		}
	} finally {
		$mock_user_caps['manage_options'] = true;
		$mock_die_called = false;
	}
});

// Test 7: Security gating: nonce validation
run_test( '7. Security: reject invalid nonce on POST', function() use ( $controller ) {
	global $mock_nonce_valid, $mock_die_called, $mock_die_code;
	$mock_nonce_valid = false;
	$mock_die_called  = false;

	try {
		$controller->handle_save_settings();
		throw new Exception( 'Did not die on invalid nonce' );
	} catch ( Throwable $e ) {
		if ( ! $mock_die_called || 403 !== $mock_die_code ) {
			throw new Exception( 'Expected wp_die with 403 on invalid nonce' );
		}
	} finally {
		$mock_nonce_valid = true;
		$mock_die_called  = false;
	}
});

// Test 8: Transient cache invalidation
run_test( '8. Transient cache invalidation upon settings save', function() use ( $controller, $mock_db ) {
	$mock_db->deleted_queries = [];
	$controller->save_settings( [ 'dim_divisor' => 5500 ] );

	if ( empty( $mock_db->deleted_queries ) ) {
		throw new Exception( 'Transients delete query was not executed' );
	}

	$query = $mock_db->deleted_queries[0];
	if ( strpos( $query, '_transient_ups_%' ) === false ) {
		throw new Exception( 'Transients delete query did not target _transient_ups_%: ' . $query );
	}
});

// Test 9: Admin View rendering (admin/views/settings.php)
run_test( '9. Admin View: settings.php HTML output renders without error', function() use ( $mock_db ) {
	ob_start();
	$_GET['settings-updated'] = 'true';
	$page_title = 'Cài đặt Quy tắc Tính cước & Phụ phí';
	include dirname( __DIR__ ) . '/admin/views/settings.php';
	$html = ob_get_clean();

	if ( strpos( $html, 'allshipUpsSettingsForm' ) === false ) {
		throw new Exception( 'Missing form id allshipUpsSettingsForm' );
	}
	if ( strpos( $html, 'allship_ups_settings_nonce' ) === false ) {
		throw new Exception( 'Missing nonce field in view' );
	}
	if ( strpos( $html, 'allship_ups_dim_divisor' ) === false ) {
		throw new Exception( 'Missing dim_divisor input in view' );
	}
	if ( strpos( $html, 'allship_ups_include_vat' ) === false ) {
		throw new Exception( 'Missing VAT toggle in view' );
	}
	if ( strpos( $html, 'allship_ups_delete_data_on_uninstall' ) === false ) {
		throw new Exception( 'Missing delete_data_on_uninstall checkbox in view' );
	}
	if ( strpos( $html, 'Cài đặt đã được lưu thành công' ) === false ) {
		throw new Exception( 'Missing success notice on settings-updated' );
	}
});

echo "\n--------------------------------------------------------\n";
echo " Test Suite Finished: $pass_count Passed, $fail_count Failed\n";
echo "========================================================\n";

if ( $fail_count > 0 ) {
	exit( 1 );
}
exit( 0 );
