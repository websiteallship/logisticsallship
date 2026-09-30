<?php
/**
 * Unit Test Suite for Phase 3: Frontend UX/UI & Modal Lead Collection.
 *
 * Verifies:
 * 1. View Template (quote-form.php): Action toolbar button, mobile sticky trigger, modal markup (#quotePdfExportModal), form fields.
 * 2. JavaScript (quote-form.js): Service card dual action buttons, mobile list trigger, modal open/reset/submit handlers, auto-download flow.
 * 3. CSS (quote-form.css): Modal entrance keyframe animation, direct download button hover & active styling.
 *
 * Run with: php tests/test-phase-3-frontend.php
 *
 * @package Allship_UPS_Quote
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

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

function assert_contains( $needle, $haystack, $message = '' ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( strpos( $haystack, $needle ) !== false ) {
		$tests_passed++;
		echo "  \033[32m✔ PASS:\033[0m {$message}\n";
	} else {
		$tests_failed++;
		echo "  \033[31m✘ FAIL:\033[0m {$message} (Not found: '{$needle}')\n";
	}
}

echo "========================================================\n";
echo " ALLSHIP UPS QUOTE - PHASE 3 TEST SUITE\n";
echo "========================================================\n\n";

// -----------------------------------------------------------------------------
// Test Group 1: View Template (quote-form.php)
// -----------------------------------------------------------------------------
echo "[Group 1: View Template (quote-form.php)]\n";

$view_path = dirname( __DIR__ ) . '/public/views/quote-form.php';
assert_true( file_exists( $view_path ), 'quote-form.php exists' );
$view_content = file_get_contents( $view_path );

assert_contains( 'openPdfExportModal()', $view_content, 'Action toolbar contains openPdfExportModal() call' );
assert_contains( 'Tải Báo Giá PDF Chính Thức', $view_content, 'Action toolbar displays prominent PDF export button text' );
assert_contains( 'id="mobileStickyActionBar"', $view_content, 'Mobile sticky action bar is present' );
assert_contains( 'ph-file-pdf', $view_content, 'Mobile sticky action bar has PDF icon button' );
assert_contains( 'id="quotePdfExportModal"', $view_content, 'Modal #quotePdfExportModal is present' );
assert_contains( 'id="pdfExportName"', $view_content, 'Name input field #pdfExportName is present' );
assert_contains( 'id="pdfExportCompany"', $view_content, 'Company input field #pdfExportCompany is present' );
assert_contains( 'id="pdfExportEmail"', $view_content, 'Email input field #pdfExportEmail is present' );
assert_contains( 'id="pdfExportPhone"', $view_content, 'Phone input field #pdfExportPhone is present' );
assert_contains( 'id="pdfExportNotes"', $view_content, 'Notes input field #pdfExportNotes is present' );
assert_contains( 'id="pdfExportAttachPolicies"', $view_content, 'Checkbox #pdfExportAttachPolicies is present' );
assert_contains( 'id="btnSubmitPdfExport"', $view_content, 'Submit button #btnSubmitPdfExport is present' );
assert_contains( 'id="pdfExportSuccessState"', $view_content, 'Success state screen #pdfExportSuccessState is present' );
assert_contains( 'id="pdfDirectDownloadLink"', $view_content, 'Direct download link #pdfDirectDownloadLink is present' );

echo "\n";

// -----------------------------------------------------------------------------
// Test Group 2: JavaScript Logic (quote-form.js)
// -----------------------------------------------------------------------------
echo "[Group 2: JavaScript Logic (quote-form.js)]\n";

$js_path = dirname( __DIR__ ) . '/public/assets/js/quote-form.js';
assert_true( file_exists( $js_path ), 'quote-form.js exists' );
$js_content = file_get_contents( $js_path );

assert_contains( 'function openPdfExportModal', $js_content, 'openPdfExportModal function is defined' );
assert_contains( 'function resetPdfExportModalState', $js_content, 'resetPdfExportModalState function is defined' );
assert_contains( 'function handlePdfExportSubmit', $js_content, 'handlePdfExportSubmit function is defined' );
assert_contains( "document.getElementById('quotePdfExportModal')", $js_content, 'quotePdfExportModal modal element queried' );
assert_contains( "'/export-quote'", $js_content, 'Calls REST endpoint /export-quote' );
assert_contains( 'UPSQuote.openPdfExportModal', $js_content, 'Cards trigger UPSQuote.openPdfExportModal' );
assert_contains( 'window.openPdfExportModal', $js_content, 'openPdfExportModal exported to global window scope' );
assert_contains( 'dlAnchor.download', $js_content, 'Auto-download mechanism via programmatic anchor click implemented' );

echo "\n";

// -----------------------------------------------------------------------------
// Test Group 3: CSS Styles & Animations (quote-form.css)
// -----------------------------------------------------------------------------
echo "[Group 3: CSS Styles & Animations (quote-form.css)]\n";

$css_path = dirname( __DIR__ ) . '/public/assets/css/quote-form.css';
assert_true( file_exists( $css_path ), 'quote-form.css exists' );
$css_content = file_get_contents( $css_path );

assert_contains( '#quotePdfExportModal.open', $css_content, 'Styles defined for #quotePdfExportModal.open' );
assert_contains( 'modalPopIn', $css_content, 'modalPopIn entrance animation is defined' );
assert_contains( '#pdfDirectDownloadLink', $css_content, '#pdfDirectDownloadLink styling is defined' );

echo "\n========================================================\n";
echo " TEST SUMMARY\n";
echo "========================================================\n";
echo "Total tests: {$tests_run}\n";
echo "Passed:      \033[32m{$tests_passed}\033[0m\n";
echo "Failed:      " . ( $tests_failed > 0 ? "\033[31m{$tests_failed}\033[0m" : "0" ) . "\n";

if ( 0 === $tests_failed ) {
	echo "\n\033[32m🎉 ALL PHASE 3 UNIT TESTS PASSED SUCCESSFULLY!\033[0m\n\n";
	exit( 0 );
} else {
	echo "\n\033[31m❌ SOME TESTS FAILED!\033[0m\n\n";
	exit( 1 );
}
