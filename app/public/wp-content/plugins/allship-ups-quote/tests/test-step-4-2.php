<?php
/**
 * Test Step 4.2 — Shortcode & Data Localize.
 *
 * Verifies:
 * - [ups_quote_form] registration.
 * - Conditional enqueue logic (only when shortcode present).
 * - wp_localize_script payload (upsQuoteConfig: apiBase, nonce, currency, COUNTRIES, VN_PROVINCES, POPULAR_IATA, dim_divisor, rounding_step).
 * - states_by_country.js asset existence and integrity.
 * - Anime.js CDN registration.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'ALLSHIP_UPS_QUOTE_VERSION' ) ) {
	define( 'ALLSHIP_UPS_QUOTE_VERSION', '1.0.0' );
}

if ( ! defined( 'ALLSHIP_UPS_QUOTE_PATH' ) ) {
	define( 'ALLSHIP_UPS_QUOTE_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'ALLSHIP_UPS_QUOTE_URL' ) ) {
	define( 'ALLSHIP_UPS_QUOTE_URL', 'http://logistic.local/wp-content/plugins/allship-ups-quote/' );
}

$registered_shortcodes = [];
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {
		global $registered_shortcodes;
		$registered_shortcodes[ $tag ] = $callback;
	}
}

if ( ! function_exists( 'has_shortcode' ) ) {
	function has_shortcode( $content, $tag ) {
		return false !== strpos( (string) $content, '[' . $tag );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {}
}

$registered_scripts = [];
$enqueued_scripts   = [];
$registered_styles  = [];
$enqueued_styles   = [];
$localized_scripts  = [];

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = [], $ver = false, $in_footer = false ) {
		global $registered_scripts;
		$registered_scripts[ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $in_footer = false ) {
		global $enqueued_scripts;
		$enqueued_scripts[ $handle ] = true;
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src, $deps = [], $ver = false, $media = 'all' ) {
		global $registered_styles;
		$registered_styles[ $handle ] = compact( 'src', 'deps', 'ver', 'media' );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
		global $enqueued_styles;
		$enqueued_styles[ $handle ] = true;
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ) {
		global $localized_scripts;
		$localized_scripts[ $handle ][ $object_name ] = $l10n;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'http://logistic.local/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'mock_nonce_' . $action;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $str, $domain = 'default' ) {
		return $str;
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $str, $domain = 'default' ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES );
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 1;
		public $post_title = '';
		public $post_content = '';
		public function __construct( $title = '', $content = '' ) {
			$this->post_title   = $title;
			$this->post_content = $content;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-settings-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-card-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-shortcode.php';

echo "=== START TEST STEP 4.2: SHORTCODE & DATA LOCALIZE ===\n";

$shortcode = new Allship_UPS_Shortcode();

// --- Test 1: Register Shortcode ---
echo "\n--- Test 1: Shortcode Registration ---\n";
$shortcode->register();
assert( isset( $registered_shortcodes['ups_quote_form'] ), "Shortcode [ups_quote_form] must be registered" );
echo "✓ Shortcode [ups_quote_form] successfully registered\n";

// --- Test 2: Asset Registration ---
echo "\n--- Test 2: Asset Registration ---\n";
$shortcode->register_assets();
assert( isset( $registered_scripts['animejs'] ), "Anime.js script must be registered" );
assert( $registered_scripts['animejs']['src'] === 'https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.2/anime.min.js', "Anime.js must match CDN URL" );
assert( isset( $registered_scripts['ups-quote-form'] ), "Form script must be registered" );
assert( isset( $registered_styles['ups-quote-form'] ), "Form stylesheet must be registered" );
echo "✓ Assets registered: animejs (3.2.2 CDN), ups-quote-form, ups-quote-form.css (zero monolithic states bundle)\n";

// --- Test 3: Conditional Enqueue — Homepage (No Shortcode) ---
echo "\n--- Test 3: Conditional Enqueue (Homepage) ---\n";
global $post, $enqueued_scripts, $enqueued_styles;
$enqueued_scripts = [];
$enqueued_styles  = [];
Allship_UPS_Shortcode::reset_assets_enqueued();

$post = new WP_Post( 'Trang chủ', 'Chào mừng đến với Allship Logistics' );
$shortcode->maybe_enqueue_assets();

assert( empty( $enqueued_scripts ), "No scripts should be enqueued on page without shortcode" );
assert( empty( $enqueued_styles ), "No styles should be enqueued on page without shortcode" );
echo "✓ Visit homepage: CSS/JS NOT loaded (zero footprint)\n";

// --- Test 4: Conditional Enqueue — "Báo giá UPS" Page ---
echo "\n--- Test 4: Conditional Enqueue ('Báo giá UPS' Page) ---\n";
$post = new WP_Post( 'Báo giá UPS', '[ups_quote_form]' );
$shortcode->maybe_enqueue_assets();

assert( isset( $enqueued_scripts['animejs'] ), "Anime.js must be enqueued" );
assert( ! isset( $enqueued_scripts['ups-quote-states'] ), "ups-quote-states should NOT be enqueued (lazy loaded)" );
assert( isset( $enqueued_scripts['ups-quote-form'] ), "ups-quote-form must be enqueued" );
assert( isset( $enqueued_styles['ups-quote-form'] ), "ups-quote-form CSS must be enqueued" );
echo "✓ Visit page 'Báo giá UPS': CSS and form JS assets loaded on-demand\n";

// --- Test 5: Localized Script Data (upsQuoteConfig) ---
echo "\n--- Test 5: Localized Script Data (upsQuoteConfig) ---\n";
global $localized_scripts;
assert( isset( $localized_scripts['ups-quote-form']['upsQuoteConfig'] ), "upsQuoteConfig must be localized" );
$cfg = $localized_scripts['ups-quote-form']['upsQuoteConfig'];

assert( $cfg['apiBase'] === 'http://logistic.local/wp-json/ups-quote/v1', "apiBase correct" );
assert( ! empty( $cfg['statesBaseUrl'] ), "statesBaseUrl must be defined" );
assert( ! empty( $cfg['citiesBaseUrl'] ), "citiesBaseUrl must be defined" );
assert( $cfg['nonce'] === 'mock_nonce_wp_rest', "nonce injected" );
assert( $cfg['currency'] === 'VND', "currency VND" );
assert( is_array( $cfg['COUNTRIES'] ) && ! empty( $cfg['COUNTRIES'] ), "COUNTRIES array populated" );
assert( is_array( $cfg['VN_PROVINCES'] ) && count( $cfg['VN_PROVINCES'] ) === 63, "VN_PROVINCES must have 63 provinces, got " . count( $cfg['VN_PROVINCES'] ) );
assert( $cfg['POPULAR_IATA'] === [ 'US', 'JP', 'KR', 'AU', 'CA', 'DE', 'GB', 'FR', 'SG', 'TW' ], "POPULAR_IATA matches spec" );
assert( $cfg['dim_divisor'] === 5500, "dim_divisor = 5500" );
assert( $cfg['rounding_step'] == 0.5, "rounding_step = 0.5" );
echo "✓ upsQuoteConfig verified: statesBaseUrl, citiesBaseUrl, apiBase, nonce, currency, COUNTRIES, 63 VN_PROVINCES, 10 POPULAR_IATA\n";

// --- Test 6: On-Demand States Data Integrity ---
echo "\n--- Test 6: On-Demand States Data Integrity ---\n";
$states_dir = dirname( __DIR__ ) . '/public/assets/data/states';
assert( is_dir( $states_dir ), "states data dir must exist in public/assets/data/states" );
$us_states_file = $states_dir . '/US.json';
assert( file_exists( $us_states_file ), "US.json must exist in on-demand states" );
$us_content = file_get_contents( $us_states_file );
$us_data = json_decode( $us_content, true );
assert( is_array( $us_data ) && count( $us_data ) >= 50, "US states count must be at least 50" );
echo "✓ On-demand states directory valid (" . count( scandir( $states_dir ) ) . " files, US states verified)\n";

// --- Test 7: Shortcode Render Output ---
echo "\n--- Test 7: Shortcode Render Output ---\n";
$html = $shortcode->render_shortcode();
assert( false !== strpos( $html, 'id="ups-quote-app"' ), "Must render #ups-quote-app" );
assert( false !== strpos( $html, 'class="ups-quote-form' ), "Must have .ups-quote-form class" );
echo "✓ Rendered shortcode HTML container: #ups-quote-app.ups-quote-form\n";

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 4.2 PASSED 100%!\n";
echo "============================================\n";
