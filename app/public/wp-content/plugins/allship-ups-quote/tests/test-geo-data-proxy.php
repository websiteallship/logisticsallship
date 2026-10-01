<?php
/**
 * Test REST API Geo Data Proxy Route.
 *
 * Verifies GET /wp-json/ups-quote/v1/geo-data/(states|cities)/(code)
 * - Traversal protection
 * - 404 handling
 * - Valid JSON payload response
 * - Cache-Control header
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Mock WordPress functions
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = [];
		public function __construct( $params = [] ) {
			$this->params = $params;
		}
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public $headers = [];
		public function __construct( $data = null, $status = 200 ) {
			$this->data = $data;
			$this->status = $status;
		}
		public function header( $k, $v ) {
			$this->headers[ $k ] = $v;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code, $message, $data = [] ) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
}

require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';

echo "=== TEST: REST API GEO DATA PROXY ===\n\n";

$controller = new Allship_UPS_REST_Controller();

// Test 1: Fetch valid US states
echo "--- Test 1: Fetch valid US states ---\n";
$req = new WP_REST_Request([ 'type' => 'states', 'code' => 'US.json' ]);
$res = $controller->get_geo_data( $req );
assert( $res instanceof WP_REST_Response, "Must return WP_REST_Response" );
assert( $res->status === 200, "Status must be 200" );
assert( is_array( $res->data ) && ! empty( $res->data ), "Must return non-empty array of states" );
assert( isset( $res->headers['Cache-Control'] ), "Must set Cache-Control header" );
echo "✓ US states fetched successfully (" . count( $res->data ) . " states found).\n\n";

// Test 2: Fetch valid city (US-CA)
echo "--- Test 2: Fetch valid city (US-CA) ---\n";
$req = new WP_REST_Request([ 'type' => 'cities', 'code' => 'US-CA.json' ]);
$res = $controller->get_geo_data( $req );
if ( file_exists( dirname( __DIR__ ) . '/public/assets/data/cities/US-CA.json' ) ) {
	assert( $res instanceof WP_REST_Response, "Must return WP_REST_Response" );
	assert( $res->status === 200, "Status must be 200" );
	echo "✓ US-CA cities fetched successfully.\n\n";
} else {
	echo "• US-CA cities file not present, skipping assert.\n\n";
}

// Test 3: Path Traversal Attack
echo "--- Test 3: Path Traversal Protection ---\n";
$req = new WP_REST_Request([ 'type' => 'states', 'code' => '../../wp-config' ]);
$res = $controller->get_geo_data( $req );
assert( $res instanceof WP_Error, "Path traversal attempt must return WP_Error" );
echo "✓ Path traversal securely blocked.\n\n";

// Test 4: Non-existent code (404)
echo "--- Test 4: Non-existent Country Code ---\n";
$req = new WP_REST_Request([ 'type' => 'states', 'code' => 'NONEXISTENT999' ]);
$res = $controller->get_geo_data( $req );
assert( $res instanceof WP_Error, "Nonexistent file must return WP_Error" );
assert( $res->data['status'] === 404, "Error status must be 404" );
echo "✓ 404 returned for unknown country.\n\n";

echo "============================================\n";
echo "✓ ALL GEO DATA PROXY TESTS PASSED 100%!\n";
echo "============================================\n";
exit( 0 );
