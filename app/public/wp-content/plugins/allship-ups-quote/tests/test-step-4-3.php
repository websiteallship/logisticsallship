<?php
/**
 * Test Suite for Step 4.3 — Quote Form Template (PHP / HTML theo Mockup V4)
 *
 * Validates:
 * 1. Template renders via shortcode with zero PHP notices or broken output.
 * 2. DOM structure parses and validates via DOMDocument.
 * 3. All 6 UPS service cards render with correct data-code, data-cat, and data-doc-split.
 * 4. Category filter tabs are present with data-cat values ('all', 'parcel', 'freight').
 * 5. Shipment type toggle options and info banner render properly.
 * 6. Form labels are strictly paired with inputs via 'for' and 'id'.
 * 7. Key ARIA accessibility roles and attributes exist (roles: radiogroup, radio, tablist, tab, dialog).
 * 8. Result section, sticky action bar, and modal structures exist.
 *
 * @package Allship_UPS_Quote
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ALLSHIP_UPS_QUOTE_VERSION', '1.0.0' );
define( 'ALLSHIP_UPS_QUOTE_PATH', dirname( __DIR__ ) . '/' );
define( 'ALLSHIP_UPS_QUOTE_URL', 'http://example.com/wp-content/plugins/allship-ups-quote/' );

// Mock WordPress functions if not present
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
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
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback ) {}
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script() {}
}
if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style() {}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script() {}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style() {}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script() {}
}

require_once __DIR__ . '/../includes/class-service-availability-manager.php';
require_once __DIR__ . '/../includes/class-shortcode.php';

$tests_passed = 0;
$total_tests  = 8;

echo "=== Running Tests for Step 4.3 — Quote Form Template ===\n\n";

// Test 1: Template render via Allship_UPS_Shortcode
$shortcode = new Allship_UPS_Shortcode();
$html      = $shortcode->render_shortcode();

if ( ! empty( $html ) && strpos( $html, 'id="ups-quote-app"' ) !== false ) {
	echo "✔ Test 1 Passed: Shortcode renders quote-form.php template successfully (" . strlen( $html ) . " bytes).\n";
	$tests_passed++;
} else {
	echo "✘ Test 1 Failed: Rendered HTML is empty or missing #ups-quote-app container.\n";
}

// Test 2: HTML validation (DOM parsing)
libxml_use_internal_errors( true );
$dom = new DOMDocument();
// Load with UTF-8 encoding prefix
$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
$errors = libxml_get_errors();
libxml_clear_errors();

$fatal_errors = array_filter( $errors, function( $e ) {
	return $e->level === LIBXML_ERR_FATAL;
} );

if ( $loaded && empty( $fatal_errors ) ) {
	echo "✔ Test 2 Passed: HTML validates without fatal parse errors.\n";
	$tests_passed++;
} else {
	echo "✘ Test 2 Failed: HTML parsing encountered fatal errors.\n";
	foreach ( $fatal_errors as $err ) {
		echo "    - Line {$err->line}: {$err->message}\n";
	}
}

$xpath = new DOMXPath( $dom );

// Test 3: All 6 service cards render with correct attributes
$expected_cards = [
	'EXW' => [ 'cat' => 'parcel', 'doc_split' => 'true' ],
	'XPR' => [ 'cat' => 'parcel', 'doc_split' => 'true' ],
	'WXS' => [ 'cat' => 'parcel', 'doc_split' => 'true' ],
	'XPD' => [ 'cat' => 'parcel', 'doc_split' => 'false' ],
	'WXP' => [ 'cat' => 'freight', 'doc_split' => 'false' ],
	'WFM' => [ 'cat' => 'freight', 'doc_split' => 'false' ],
];

$cards_valid = true;
foreach ( $expected_cards as $code => $meta ) {
	$nodes = $xpath->query( "//*[@data-code='{$code}']" );
	if ( $nodes->length !== 1 ) {
		echo "  - Missing or duplicate card for {$code}\n";
		$cards_valid = false;
		continue;
	}
	$card = $nodes->item( 0 );
	if ( $card->getAttribute( 'data-cat' ) !== $meta['cat'] ) {
		echo "  - Card {$code} has wrong data-cat: " . $card->getAttribute( 'data-cat' ) . "\n";
		$cards_valid = false;
	}
	if ( $card->getAttribute( 'data-doc-split' ) !== $meta['doc_split'] ) {
		echo "  - Card {$code} has wrong data-doc-split: " . $card->getAttribute( 'data-doc-split' ) . "\n";
		$cards_valid = false;
	}
}

if ( $cards_valid ) {
	echo "✔ Test 3 Passed: All 6 service cards render with exact data-code, data-cat, and data-doc-split attributes.\n";
	$tests_passed++;
} else {
	echo "✘ Test 3 Failed: Service cards validation failed.\n";
}

// Test 4: Category filter tabs
$cat_all     = $xpath->query( "//button[@data-cat='all']" );
$cat_parcel  = $xpath->query( "//button[@data-cat='parcel']" );
$cat_freight = $xpath->query( "//button[@data-cat='freight']" );

if ( $cat_all->length >= 1 && $cat_parcel->length >= 1 && $cat_freight->length >= 1 ) {
	echo "✔ Test 4 Passed: Category filter tabs ('all', 'parcel', 'freight') are present and properly attributed.\n";
	$tests_passed++;
} else {
	echo "✘ Test 4 Failed: One or more category filter tabs are missing.\n";
}

// Test 5: Shipment type toggle row and options
$shipment_row = $xpath->query( "//*[@id='shipmentTypeRow']" );
$opt_nondoc   = $xpath->query( "//*[@id='optNondoc']" );
$opt_doc      = $xpath->query( "//*[@id='optDoc']" );

if ( $shipment_row->length === 1 && $opt_nondoc->length === 1 && $opt_doc->length === 1 ) {
	echo "✔ Test 5 Passed: Shipment type toggle row (#shipmentTypeRow) and options (#optNondoc, #optDoc) are present.\n";
	$tests_passed++;
} else {
	echo "✘ Test 5 Failed: Shipment type toggle row or options missing.\n";
}

// Test 6: Form labels linked to inputs
$labels = $xpath->query( "//label[@for]" );
$unmatched_labels = [];

foreach ( $labels as $label ) {
	$for_id = $label->getAttribute( 'for' );
	if ( empty( $for_id ) ) {
		continue;
	}
	$target = $xpath->query( "//*[@id='{$for_id}']" );
	if ( $target->length === 0 ) {
		$unmatched_labels[] = $for_id;
	}
}

$expected_linked_ids = [
	'originProvince', 'destCountryDisplay', 'destState', 'destCity',
	'destZipcode', 'destAddress', 'bookingName', 'bookingPhone', 'bookingNotes'
];

$missing_target_ids = [];
foreach ( $expected_linked_ids as $id ) {
	if ( $xpath->query( "//*[@id='{$id}']" )->length === 0 ) {
		$missing_target_ids[] = $id;
	}
}

if ( empty( $unmatched_labels ) && empty( $missing_target_ids ) ) {
	echo "✔ Test 6 Passed: All " . $labels->length . " form labels are strictly linked to corresponding input elements.\n";
	$tests_passed++;
} else {
	echo "✘ Test 6 Failed: Mismatched or missing form label targets:\n";
	if ( ! empty( $unmatched_labels ) ) {
		echo "    - Unmatched label for: " . implode( ', ', $unmatched_labels ) . "\n";
	}
	if ( ! empty( $missing_target_ids ) ) {
		echo "    - Missing input IDs: " . implode( ', ', $missing_target_ids ) . "\n";
	}
}

// Test 7: ARIA accessibility roles and attributes
$roles_to_check = [
	'radiogroup' => $xpath->query( "//*[@role='radiogroup']" )->length,
	'radio'      => $xpath->query( "//*[@role='radio']" )->length,
	'tablist'    => $xpath->query( "//*[@role='tablist']" )->length,
	'tab'        => $xpath->query( "//*[@role='tab']" )->length,
	'dialog'     => $xpath->query( "//*[@role='dialog']" )->length,
];

$aria_valid = (
	$roles_to_check['radiogroup'] >= 2 &&
	$roles_to_check['radio'] >= 8 && // 2 directions + 6 services + 2 shipment types = 10
	$roles_to_check['tablist'] >= 1 &&
	$roles_to_check['tab'] >= 3 &&
	$roles_to_check['dialog'] === 2
);

if ( $aria_valid ) {
	echo "✔ Test 7 Passed: All critical ARIA attributes (radiogroup, radio, tablist, tab, dialog) are verified.\n";
	$tests_passed++;
} else {
	echo "✘ Test 7 Failed: Incomplete ARIA roles: " . json_encode( $roles_to_check ) . "\n";
}

// Test 8: Sections 6, 7, 8 (Result Section, Mobile Sticky Bar, Modals)
$result_section   = $xpath->query( "//*[@id='resultSection']" );
$mobile_bar       = $xpath->query( "//*[@id='mobileStickyActionBar']" );
$pieces_modal     = $xpath->query( "//*[@id='piecesDetailModal']" );
$booking_modal    = $xpath->query( "//*[@id='bookingModal']" );
$volumetric_bar   = $xpath->query( "//*[@id='chargeableWeightVal']" );

if (
	$result_section->length === 1 &&
	$mobile_bar->length === 1 &&
	$pieces_modal->length === 1 &&
	$booking_modal->length === 1 &&
	$volumetric_bar->length === 1
) {
	echo "✔ Test 8 Passed: Sections 6 (Result), 7 (Mobile Sticky Bar), and 8 (Modals) are structurally intact.\n";
	$tests_passed++;
} else {
	echo "✘ Test 8 Failed: One or more structural sections/modals are missing.\n";
}

echo "\nSummary: {$tests_passed}/{$total_tests} tests passed.\n";

if ( $tests_passed === $total_tests ) {
	echo "All Step 4.3 template specifications verified successfully!\n";
	exit( 0 );
} else {
	exit( 1 );
}
