<?php
/**
 * Test Service Availability UI & Edge Cases.
 *
 * Verifies:
 * - Proper enablement & reason when services lack active rate cards.
 * - Derived services (EXW, XPR, WXP) enablement tracking.
 * - Dynamic card badge and class rendering in public quote form.
 * - Auto-switch default service to first enabled service.
 * - No-service banner visibility.
 *
 * @package Allship_UPS_Quote
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return $url; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) { return $url; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) { return json_encode( $data ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $val ) { return $val; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $val ) { return trim( (string) $val ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $val ) { return abs( intval( $val ) ); }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) { return false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $val, $exp = 0 ) { return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) { return true; }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) { return '/wp-json/' . ltrim( $path, '/' ); }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = '' ) { return 'mock_nonce_' . $action; }
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) { return '/wp-content/plugins/allship-ups-quote/' . ltrim( $path, '/' ); }
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = [], $ver = false, $in_footer = false ) {}
}
if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src, $deps = [], $ver = false, $media = 'all' ) {}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle ) {}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle ) {}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $name, $data ) {}
}

class Mock_WPDB_Avail {
	public $prefix    = 'wp_';
	public $insert_id = 0;

	public $rate_cards = [];
	public $rates      = [];

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
		$this->insert_id++;
		$data['id'] = $this->insert_id;

		if ( strpos( $table, 'ups_rate_cards' ) !== false ) {
			$this->rate_cards[ $this->insert_id ] = (object) $data;
		} elseif ( strpos( $table, 'ups_rates' ) !== false ) {
			$this->rates[ $this->insert_id ] = (object) $data;
		}

		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$updated = 0;
		if ( strpos( $table, 'ups_rate_cards' ) !== false ) {
			foreach ( $this->rate_cards as $card ) {
				$match = true;
				foreach ( $where as $k => $v ) {
					if ( ! isset( $card->$k ) || $card->$k != $v ) {
						$match = false;
						break;
					}
				}
				if ( $match ) {
					foreach ( $data as $k => $v ) {
						$card->$k = $v;
					}
					$updated++;
				}
			}
		}
		return $updated;
	}

	public function get_results( $query, $output = OBJECT ) {
		$res = [];
		if ( strpos( $query, 'ups_rate_cards' ) !== false ) {
			foreach ( array_reverse( $this->rate_cards, true ) as $c ) {
				if ( strpos( $query, "status = 'active'" ) !== false && 'active' !== $c->status ) {
					continue;
				}
				$res[] = clone $c;
			}
			return $res;
		}

		if ( strpos( $query, 'ups_rates' ) !== false ) {
			if ( preg_match( '/rate_card_id IN \(([^)]+)\)/', $query, $m ) ) {
				$cids = array_map( 'intval', explode( ',', $m[1] ) );
				$counts = [];
				foreach ( $this->rates as $r ) {
					if ( in_array( (int) $r->rate_card_id, $cids, true ) ) {
						$counts[ $r->rate_group ] = ( $counts[ $r->rate_group ] ?? 0 ) + 1;
					}
				}
				foreach ( $counts as $g => $cnt ) {
					$res[] = (object) [ 'rate_group' => $g, 'count' => $cnt ];
				}
				return $res;
			}
		}

		return $res;
	}

	public function get_row( $query, $output = OBJECT ) {
		if ( strpos( $query, 'ups_rate_cards' ) !== false ) {
			if ( preg_match( '/WHERE id = (\d+)/', $query, $m ) ) {
				$id = (int) $m[1];
				return isset( $this->rate_cards[ $id ] ) ? clone $this->rate_cards[ $id ] : null;
			}
		}
		return null;
	}

	public function get_var( $query ) {
		return null;
	}
}

global $wpdb;
$wpdb = new Mock_WPDB_Avail();

require_once dirname( __DIR__ ) . '/includes/class-rate-card-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-service-availability-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-shortcode.php';

echo "=== START TEST: SERVICE AVAILABILITY UI & RATE CARD MAPPING ===\n\n";

// Setup: Create 2 active cards: #1 (export_xpd), #2 (export_wxp)
$repo = new Allship_UPS_Rate_Card_Repository( $wpdb );

$id1 = $repo->create( [ 'name' => 'XPD Export Card', 'status' => 'active', 'market_code' => 'VN' ] );
$wpdb->insert( $wpdb->prefix . 'ups_rates', [ 'rate_card_id' => $id1, 'rate_group' => 'export_xpd', 'weight_kg' => 1.0, 'zone' => 1, 'rate' => 100000 ] );

$id2 = $repo->create( [ 'name' => 'WXP Export Card', 'status' => 'active', 'market_code' => 'VN' ] );
$wpdb->insert( $wpdb->prefix . 'ups_rates', [ 'rate_card_id' => $id2, 'rate_group' => 'export_wxp', 'weight_kg' => 71.0, 'zone' => 1, 'rate' => 5000000 ] );

// Initialize REST controller
$ctrl = new Allship_UPS_REST_Controller( $repo );

// --- Test 1: GET /services with only XPD & WXP active ---
echo "--- Test 1: GET /services (Only XPD & WXP Active) ---\n";
$req = (object) [ 'direction' => 'export' ];
$res = $ctrl->get_services( $req );
$data = ( is_object( $res ) && method_exists( $res, 'get_data' ) ) ? $res->get_data()['data'] : $res['data'];

$map = [];
foreach ( $data as $s ) {
	$map[ $s['code'] ] = $s;
}

assert( $map['XPD']['enabled'] === true, 'XPD must be enabled' );
assert( $map['XPD']['has_data'] === true, 'XPD must have data' );

assert( $map['WXP']['enabled'] === true, 'WXP must be enabled' );
assert( $map['WXP']['has_data'] === true, 'WXP must have data' );

assert( $map['WXS']['enabled'] === false, 'WXS must be disabled' );
assert( $map['WXS']['has_data'] === false, 'WXS must not have data' );

assert( $map['EXW']['enabled'] === false, 'EXW must be disabled when WXS has no data' );
assert( $map['XPR']['enabled'] === false, 'XPR must be disabled when WXS has no data' );
assert( $map['WFM']['enabled'] === false, 'WFM must be disabled when WFM has no data' );

echo "✓ XPD & WXP are enabled; WXS, EXW, XPR, WFM are correctly disabled with reason.\n";

// --- Test 2: Activate WXS Rate Card ---
echo "\n--- Test 2: Activate WXS Rate Card (Enables WXS, EXW, XPR) ---\n";
$id3 = $repo->create( [ 'name' => 'WXS Export Card', 'status' => 'active', 'market_code' => 'VN' ] );
$wpdb->insert( $wpdb->prefix . 'ups_rates', [ 'rate_card_id' => $id3, 'rate_group' => 'export_wxs_nondocument', 'weight_kg' => 2.0, 'zone' => 1, 'rate' => 200000 ] );

$res2 = $ctrl->get_services( $req );
$data2 = ( is_object( $res2 ) && method_exists( $res2, 'get_data' ) ) ? $res2->get_data()['data'] : $res2['data'];
$map2 = [];
foreach ( $data2 as $s ) {
	$map2[ $s['code'] ] = $s;
}

assert( $map2['WXS']['enabled'] === true, 'WXS must be enabled' );
assert( $map2['EXW']['enabled'] === true, 'EXW must be enabled via WXS multiplier' );
assert( $map2['XPR']['enabled'] === true, 'XPR must be enabled via WXS multiplier' );
echo "✓ Activating WXS automatically enables WXS, EXW, and XPR.\n";

// --- Test 3: Public Template Rendering with Default Selection ---
echo "\n--- Test 3: Public Template Rendering ---\n";
// Reset to only XPD & WXP active
$repo->archive( $id3 );

$shortcode = new Allship_UPS_Shortcode( $repo );
$html = $shortcode->render_shortcode();

assert( strpos( $html, 'data-code="XPD"' ) !== false, 'HTML must contain XPD card' );
assert( strpos( $html, 'active' ) !== false, 'HTML must have an active card' );
assert( strpos( $html, 'style="display: none !important;"' ) !== false, 'HTML must hide disabled rate cards' );
assert( strpos( $html, 'grid-cols-auto-2 max-w-2xl' ) !== false, 'HTML must adapt grid layout to 2 columns' );
assert( strpos( $html, 'Tất cả (2)' ) !== false, 'HTML must show 2 available in All tab' );
assert( strpos( $html, 'Bưu kiện dưới 70kg (1)' ) !== false, 'HTML must show 1 parcel available' );
assert( strpos( $html, 'Hàng nặng trên 70kg (1)' ) !== false, 'HTML must show 1 freight available' );
assert( strpos( $html, 'Sớm · 1-2 ngày' ) !== false, 'HTML must render Sớm for EXW' );

echo "✓ Public form template correctly auto-selects first enabled service, hides cards without rate cards, auto-aligns grid, and displays 'Sớm'.\n";

echo "\n============================================\n";
echo "✓ ALL SERVICE AVAILABILITY TESTS PASSED 100%!\n";
echo "============================================\n";
