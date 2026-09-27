<?php
/**
 * PHP Wrapper Test Suite for Step 4.4 — Frontend JavaScript Architecture
 *
 * Runs `node test-step-4-4.js` and validates all 12 frontend behavioral gates:
 * 1. window.UPSQuote global API availability
 * 2. Country search: Tiếng Việt ("Nhật", "Mỹ") + IATA ("DE") + English ("Australia")
 * 3. Dynamic pieces add/remove with real-time volumetric updates
 * 4. Category filter tabs (all, parcel, freight)
 * 5 & 6. Document split toggle on EXW/XPR/WXS and hide on XPD
 * 7. Simultaneous 6-services comparison calculation
 * 8. "GIÁ TỐT NHẤT" badge detection
 * 9. Lane availability fallback to "Liên hệ"
 * 10. Mobile compact list view and sticky action bar update
 * 11. Pieces detail modal breakdown table and KPI cards
 * 12. Booking modal auto route summary & lead submission
 *
 * @package Allship_UPS_Quote
 */

$test_js = __DIR__ . '/test-step-4-4.js';
if ( ! file_exists( $test_js ) ) {
	echo "✘ test-step-4-4.js not found!\n";
	exit( 1 );
}

$cmd = 'node ' . escapeshellarg( $test_js );
$output = [];
$return_var = 0;
exec( $cmd, $output, $return_var );

echo implode( "\n", $output ) . "\n";

if ( 0 === $return_var ) {
	echo "\n✔ PHP Step 4.4 Test Runner: SUCCESS (exit code 0)\n";
	exit( 0 );
} else {
	echo "\n✘ PHP Step 4.4 Test Runner: FAILED (exit code {$return_var})\n";
	exit( 1 );
}
