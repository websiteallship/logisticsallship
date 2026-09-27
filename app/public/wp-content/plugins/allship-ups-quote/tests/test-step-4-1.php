<?php
/**
 * Test Step 4.1 — REST Controller.
 *
 * Verifies all 5 endpoints:
 * - POST /calculate
 * - POST /lead
 * - GET  /countries
 * - GET  /services
 * - GET  /directions
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

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( trim( (string) $email ), FILTER_SANITIZE_EMAIL );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) {
		return abs( (int) $v );
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 1000 ) {
		return mt_rand( $min, $max );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql' ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subj, $msg ) {
		return true;
	}
}

$GLOBALS['wp_options'] = [];

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $opt, $default = false ) {
		return isset( $GLOBALS['wp_options'][ $opt ] ) ? $GLOBALS['wp_options'][ $opt ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $opt, $val, $autoload = null ) {
		$GLOBALS['wp_options'][ $opt ] = $val;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_data() {
			return $this->data;
		}
		public function get_status() {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public $params = [];
		public function __construct( $method = 'GET', $route = '' ) {}
		public function set_param( $k, $v ) {
			$this->params[ $k ] = $v;
		}
		public function get_param( $k ) {
			return isset( $this->params[ $k ] ) ? $this->params[ $k ] : null;
		}
		public function get_params() {
			return $this->params;
		}
	}
}

$registered_routes = [];
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args ) {
		global $registered_routes;
		$registered_routes[ $namespace . $route ] = $args;
	}
}

require_once dirname( __DIR__ ) . '/includes/config/service-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-settings-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-card-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-country-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-zone-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-quote-log-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-service-availability-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-weight-calculator.php';
require_once dirname( __DIR__ ) . '/includes/class-zone-resolver.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-lookup.php';
require_once dirname( __DIR__ ) . '/includes/class-surcharge-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-quote-calculator.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';

echo "=== START TEST STEP 4.1: REST CONTROLLER ===\n";

class Mock_WPDB_REST {
	public $prefix = 'wp_';
	public $countries = [];
	public $zones = [];
	public $rates = [];
	public $settings = [];
	public $logs = [];
	public $insert_id = 0;

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

	public function get_row( $query, $output = OBJECT ) {
		if ( false !== strpos( $query, 'ups_rate_cards' ) ) {
			return (object) [
				'id'                   => 1,
				'name'                 => 'UPS VN Net Rates 2026',
				'market_code'          => 'VN',
				'status'               => 'active',
				'enabled_directions'   => '["export"]',
				'disabled_rate_groups' => '[]',
				'valid_from'           => '2026-01-01',
			];
		}

		if ( strpos( $query, 'FROM wp_ups_countries' ) !== false && preg_match( "/iata_code = '([^']+)'/", $query, $m ) ) {
			$iata = $m[1];
			return isset( $this->countries[ $iata ] ) ? (object) $this->countries[ $iata ] : null;
		}

		if ( strpos( $query, 'FROM wp_ups_rates' ) !== false && strpos( $query, "billing_unit = 'flat'" ) !== false ) {
			if ( preg_match( "/rate_group = '([^']+)'/", $query, $rg ) &&
			     preg_match( "/zone = '([^']+)'/", $query, $z ) &&
			     preg_match( "/weight_to >= ([0-9.]+)/", $query, $w ) ) {
				$matched = [];
				foreach ( $this->rates as $r ) {
					if ( $r['rate_group'] === $rg[1] && $r['zone'] === $z[1] && (float) $r['weight_to'] >= (float) $w[1] && $r['billing_unit'] === 'flat' ) {
						$matched[] = $r;
					}
				}
				if ( ! empty( $matched ) ) {
					usort( $matched, function( $a, $b ) { return (float) $a['weight_to'] <=> (float) $b['weight_to']; } );
					return (object) $matched[0];
				}
			}
		}

		if ( strpos( $query, 'FROM wp_ups_rates' ) !== false && strpos( $query, "billing_unit = 'per_kg'" ) !== false ) {
			if ( preg_match( "/rate_group = '([^']+)'/", $query, $rg ) &&
			     preg_match( "/zone = '([^']+)'/", $query, $z ) ) {
				foreach ( $this->rates as $r ) {
					if ( $r['rate_group'] === $rg[1] && $r['zone'] === $z[1] && $r['billing_unit'] === 'per_kg' ) {
						return (object) $r;
					}
				}
			}
		}

		if ( strpos( $query, 'FROM wp_ups_quote_logs' ) !== false && preg_match( "/id = ([0-9]+)/", $query, $m ) ) {
			$id = (int) $m[1];
			foreach ( $this->logs as $l ) {
				if ( (int) $l['id'] === $id ) {
					return (object) $l;
				}
			}
		}

		return null;
	}

	public function get_var( $query ) {
		if ( strpos( $query, 'SELECT zone FROM wp_ups_zone_maps' ) !== false ) {
			if ( preg_match( "/country_id = ([0-9]+)/", $query, $c ) &&
			     preg_match( "/direction = '([^']+)'/", $query, $d ) &&
			     preg_match( "/service_code = '([^']+)'/", $query, $s ) ) {
				$key = $c[1] . '_' . $d[1] . '_' . $s[1];
				return isset( $this->zones[ $key ] ) ? $this->zones[ $key ]['zone'] : null;
			}
		}
		if ( strpos( $query, 'FROM wp_ups_settings' ) !== false && preg_match( "/setting_key = '([^']+)'/", $query, $m ) ) {
			return isset( $this->settings[ $m[1] ] ) ? $this->settings[ $m[1] ] : null;
		}
		return null;
	}

	public function get_results( $query, $output = OBJECT ) {
		if ( strpos( $query, 'COUNT(*) as count FROM wp_ups_rates' ) !== false ) {
			$counts = [];
			foreach ( $this->rates as $r ) {
				$rg = $r['rate_group'];
				$counts[ $rg ] = isset( $counts[ $rg ] ) ? $counts[ $rg ] + 1 : 1;
			}
			$res = [];
			foreach ( $counts as $rg => $c ) {
				$res[] = (object) [ 'rate_group' => $rg, 'count' => $c ];
			}
			return $res;
		}

		if ( strpos( $query, 'FROM wp_ups_countries' ) !== false ) {
			$res = [];
			foreach ( $this->countries as $c ) {
				$res[] = (object) [
					'iata_code'              => $c['iata_code'],
					'country_name'           => $c['country_name'],
					'normalized_name'        => $c['normalized_name'],
					'is_us_override'         => $c['is_us_override'],
					'has_extended_area_note' => $c['has_extended_area_note'],
					'zone'                   => '5',
					'is_available'           => 1,
					'wxs'                    => 5,
					'xpd'                    => 5,
					'wfm'                    => 5,
				];
			}
			return $res;
		}

		return [];
	}

	public function insert( $table, $data, $format = null ) {
		if ( strpos( $table, 'ups_quote_logs' ) !== false ) {
			$this->insert_id++;
			$data['id'] = $this->insert_id;
			$this->logs[] = $data;
			return 1;
		}
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( strpos( $table, 'ups_quote_logs' ) !== false && isset( $where['id'] ) ) {
			$id = (int) $where['id'];
			foreach ( $this->logs as &$l ) {
				if ( (int) $l['id'] === $id ) {
					foreach ( $data as $k => $v ) {
						$l[ $k ] = $v;
					}
					return 1;
				}
			}
		}
		return 1;
	}
}

global $wpdb;
$mock_db = new Mock_WPDB_REST();
$wpdb    = $mock_db;

// Populate DB
$mock_db->countries['US'] = [
	'id'                     => 1,
	'iata_code'              => 'US',
	'country_name'           => 'United States*',
	'normalized_name'        => 'United States',
	'is_us_override'         => 1,
	'has_extended_area_note' => 1,
	'is_active'              => 1,
];
$mock_db->countries['JP'] = [
	'id'                     => 2,
	'iata_code'              => 'JP',
	'country_name'           => 'Japan',
	'normalized_name'        => 'Japan',
	'is_us_override'         => 0,
	'has_extended_area_note' => 0,
	'is_active'              => 1,
];

// Zones
$mock_db->zones['1_export_WXS'] = [ 'country_id' => 1, 'direction' => 'export', 'service_code' => 'WXS', 'zone' => '5' ];
$mock_db->zones['1_export_XPD'] = [ 'country_id' => 1, 'direction' => 'export', 'service_code' => 'XPD', 'zone' => '5' ];
$mock_db->zones['1_export_WFM'] = [ 'country_id' => 1, 'direction' => 'export', 'service_code' => 'WFM', 'zone' => '5' ];
$mock_db->zones['2_export_WXS'] = [ 'country_id' => 2, 'direction' => 'export', 'service_code' => 'WXS', 'zone' => '3' ];

// Rates (US5 bracket for 6.0kg = 1977290)
$mock_db->rates[] = [
	'id'           => 1,
	'rate_card_id' => 1,
	'rate_group'   => 'export_wxs_nondocument',
	'zone'         => 'US5',
	'weight_label' => '6.0',
	'weight_from'  => 5.5,
	'weight_to'    => 6.0,
	'billing_unit' => 'flat',
	'price_vnd'    => 1977290,
	'sort_order'   => 12,
];
$mock_db->rates[] = [
	'id'           => 2,
	'rate_card_id' => 1,
	'rate_group'   => 'export_wxs_document',
	'zone'         => 'US5',
	'weight_label' => '0.5',
	'weight_from'  => 0.0,
	'weight_to'    => 0.5,
	'billing_unit' => 'flat',
	'price_vnd'    => 500000,
	'sort_order'   => 1,
];
$mock_db->rates[] = [
	'id'           => 3,
	'rate_card_id' => 1,
	'rate_group'   => 'export_xpd',
	'zone'         => 'US5',
	'weight_label' => '6.0',
	'weight_from'  => 5.5,
	'weight_to'    => 6.0,
	'billing_unit' => 'flat',
	'price_vnd'    => 1600000,
	'sort_order'   => 12,
];
$mock_db->rates[] = [
	'id'           => 4,
	'rate_card_id' => 1,
	'rate_group'   => 'export_wfm',
	'zone'         => 'US5',
	'weight_label' => '>70',
	'weight_from'  => 71.0,
	'weight_to'    => null,
	'billing_unit' => 'per_kg',
	'price_vnd'    => 150000,
	'sort_order'   => 1,
];

// Instantiation
$country_repo   = new Allship_UPS_Country_Repository( $mock_db );
$rate_card_repo = new Allship_UPS_Rate_Card_Repository( $mock_db );
$zone_repo      = new Allship_UPS_Zone_Repository( $mock_db );
$rate_repo      = new Allship_UPS_Rate_Repository( $mock_db );
$settings_mgr   = new Allship_UPS_Settings_Manager( $mock_db );
$quote_log_repo = new Allship_UPS_Quote_Log_Repository( $mock_db );
$service_mgr    = new Allship_UPS_Service_Availability_Manager( $rate_card_repo );

$weight_calc = new Allship_UPS_Weight_Calculator();
$zone_res    = new Allship_UPS_Zone_Resolver( $zone_repo, $country_repo, $settings_mgr, $service_mgr, $rate_card_repo );
$rate_lookup = new Allship_UPS_Rate_Lookup( $rate_repo, $service_mgr );
$surcharges  = new Allship_UPS_Surcharge_Engine( $settings_mgr );
$calculator  = new Allship_UPS_Quote_Calculator(
	$rate_card_repo,
	$country_repo,
	$zone_res,
	$weight_calc,
	$rate_lookup,
	$surcharges,
	$quote_log_repo,
	$settings_mgr,
	$service_mgr
);

Allship_UPS_REST_Controller::reset_routes_registered();
$controller = new Allship_UPS_REST_Controller(
	$calculator,
	$country_repo,
	$rate_card_repo,
	$zone_repo,
	$rate_repo,
	$service_mgr,
	$quote_log_repo,
	$settings_mgr
);

// --- Test 1: Route Registration ---
echo "\n--- Test 1: Route Registration ---\n";
$controller->register_routes();
assert( isset( $registered_routes['ups-quote/v1/calculate'] ), "Expected route /calculate" );
assert( isset( $registered_routes['ups-quote/v1/lead'] ), "Expected route /lead" );
assert( isset( $registered_routes['ups-quote/v1/countries'] ), "Expected route /countries" );
assert( isset( $registered_routes['ups-quote/v1/services'] ), "Expected route /services" );
assert( isset( $registered_routes['ups-quote/v1/directions'] ), "Expected route /directions" );
echo "✓ All 5 routes registered in namespace 'ups-quote/v1'\n";

// --- Test 2: POST /calculate (US, WXS, 6kg) ---
echo "\n--- Test 2: POST /calculate (US, WXS, 6kg) ---\n";
$req = new WP_REST_Request( 'POST', '/calculate' );
$req->set_param( 'destination_iata', 'US' );
$req->set_param( 'service_code', 'WXS' );
$req->set_param( 'shipment_type', 'nondocument' );
$req->set_param( 'pieces', [
	[ 'quantity' => 1, 'actual_weight_kg' => 6, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 15 ]
] );
$response = $controller->calculate( $req );
$data = $response->get_data();
assert( $response->get_status() === 200, "Expected status 200, got {$response->get_status()}" );
assert( $data['success'] === true, "Expected success true" );
assert( $data['data']['rate_zone'] === 'US5', "Expected US5, got {$data['data']['rate_zone']}" );
assert( $data['data']['chargeable_weight_kg'] == 6.0, "Expected 6.0kg, got {$data['data']['chargeable_weight_kg']}" );
assert( $data['data']['base_price_vnd'] === 1977290, "Expected 1977290 VND" );
echo "✓ POST /calculate: US, WXS -> rate_zone=US5, base_price=1,977,290 VND\n";

// --- Test 3: POST /calculate with Multipliers (EXW, XPR) ---
echo "\n--- Test 3: Multiplier services EXW & XPR ---\n";
$req->set_param( 'service_code', 'EXW' );
$res_exw = $controller->calculate( $req );
$data_exw = $res_exw->get_data();
assert( $res_exw->get_status() === 200, "EXW status 200" );
assert( $data_exw['data']['service_code'] === 'EXW', "Service code EXW" );
$expected_exw_price = (int) round( 1977290 * 1.25 );
assert( $data_exw['data']['base_price_vnd'] === $expected_exw_price, "Expected {$expected_exw_price}, got {$data_exw['data']['base_price_vnd']}" );

$req->set_param( 'service_code', 'XPR' );
$res_xpr = $controller->calculate( $req );
$data_xpr = $res_xpr->get_data();
assert( $res_xpr->get_status() === 200, "XPR status 200" );
assert( $data_xpr['data']['service_code'] === 'XPR', "Service code XPR" );
$expected_xpr_price = (int) round( 1977290 * 1.15 );
assert( $data_xpr['data']['base_price_vnd'] === $expected_xpr_price, "Expected {$expected_xpr_price}, got {$data_xpr['data']['base_price_vnd']}" );
echo "✓ Multipliers: EXW (1.25x) and XPR (1.15x) successfully calculated\n";

// --- Test 4: Validation error (invalid IATA) ---
echo "\n--- Test 4: Validation error ---\n";
$req_invalid = new WP_REST_Request( 'POST', '/calculate' );
$req_invalid->set_param( 'destination_iata', 'INVALID' );
$req_invalid->set_param( 'service_code', 'WXS' );
$res_invalid = $controller->calculate( $req_invalid );
assert( $res_invalid->get_status() === 422, "Expected status 422 for invalid IATA" );
$data_inv = $res_invalid->get_data();
assert( $data_inv['success'] === false, "Expected success false" );
assert( $data_inv['error']['code'] === 'INVALID_INPUT', "Expected INVALID_INPUT" );
echo "✓ Invalid IATA rejected with 422 INVALID_INPUT\n";

// --- Test 5: GET /countries ---
echo "\n--- Test 5: GET /countries ---\n";
$req_cnt = new WP_REST_Request( 'GET', '/countries' );
$req_cnt->set_param( 'direction', 'export' );
$req_cnt->set_param( 'service_code', 'WXS' );
$res_cnt = $controller->get_countries( $req_cnt );
$data_cnt = $res_cnt->get_data();
assert( $res_cnt->get_status() === 200, "Countries status 200" );
assert( $data_cnt['success'] === true, "Countries success true" );
assert( is_array( $data_cnt['data'] ), "Countries data is array" );
assert( count( $data_cnt['data'] ) >= 2, "Expected >= 2 countries" );
assert( $data_cnt['data'][0]['iata_code'] === 'US' || $data_cnt['data'][0]['iata_code'] === 'JP', "Found country" );
echo "✓ GET /countries: Returned countries list with zone mappings\n";

// --- Test 6: GET /services (Full 6 Services) ---
echo "\n--- Test 6: GET /services (Full 6 Services) ---\n";
$req_svc = new WP_REST_Request( 'GET', '/services' );
$res_svc = $controller->get_services( $req_svc );
$data_svc = $res_svc->get_data();
assert( $res_svc->get_status() === 200, "Services status 200" );
assert( $data_svc['success'] === true, "Services success true" );
assert( count( $data_svc['data'] ) === 6, "Expected exactly 6 services, got " . count( $data_svc['data'] ) );
$codes = array_column( $data_svc['data'], 'code' );
assert( $codes === [ 'EXW', 'XPR', 'WXS', 'XPD', 'WXP', 'WFM' ], "Expected 6 services codes" );
$wxs_svc = $data_svc['data'][2];
assert( $wxs_svc['code'] === 'WXS', "WXS code" );
assert( $wxs_svc['name'] === 'Express Saver', "WXS name" );
assert( $wxs_svc['badge_text'] === 'Phổ biến', "WXS badge" );
assert( $wxs_svc['icon'] === 'ph-airplane-tilt', "WXS icon" );
assert( $wxs_svc['has_document_split'] === true, "WXS doc split" );
echo "✓ GET /services: Returned full 6 services with V4 metadata\n";

// --- Test 7: GET /directions ---
echo "\n--- Test 7: GET /directions ---\n";
$req_dir = new WP_REST_Request( 'GET', '/directions' );
$res_dir = $controller->get_directions( $req_dir );
$data_dir = $res_dir->get_data();
assert( $res_dir->get_status() === 200, "Directions status 200" );
assert( $data_dir['success'] === true, "Directions success true" );
assert( count( $data_dir['data'] ) === 2, "Expected 2 directions" );
assert( $data_dir['data'][0]['direction'] === 'export', "First is export" );
assert( $data_dir['data'][0]['enabled'] === true, "Export enabled" );
echo "✓ GET /directions: Returned transport directions from manager\n";

// --- Test 8: POST /lead ---
echo "\n--- Test 8: POST /lead ---\n";
$req_lead = new WP_REST_Request( 'POST', '/lead' );
$req_lead->set_param( 'name', 'Nguyen Van A' );
$req_lead->set_param( 'phone', '0901234567' );
$req_lead->set_param( 'email', 'a@example.com' );
$req_lead->set_param( 'destination_iata', 'US' );
$req_lead->set_param( 'service_code', 'WXS' );
$req_lead->set_param( 'total_price_vnd', 1977290 );
$req_lead->set_param( 'route_summary', 'TP. Hồ Chí Minh -> United States' );
$res_lead = $controller->submit_lead( $req_lead );
$data_lead = $res_lead->get_data();
assert( $res_lead->get_status() === 200, "Lead status 200" );
assert( $data_lead['success'] === true, "Lead success true" );
assert( ! empty( $data_lead['data']['lead_id'] ), "Lead ID returned" );
assert( ! empty( $data_lead['data']['message'] ), "Lead message returned" );
echo "✓ POST /lead: Submitted lead, generated lead_id and stored in options\n";

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 4.1 PASSED 100%!\n";
echo "============================================\n";
