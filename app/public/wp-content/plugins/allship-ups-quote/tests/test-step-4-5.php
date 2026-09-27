<?php
/**
 * Test Step 4.5: Frontend CSS & Design System
 */

$css_file = dirname(__DIR__) . '/public/assets/css/quote-form.css';
if ( ! file_exists( $css_file ) ) {
    die( "❌ CSS file not found.\n" );
}

$css_content = file_get_contents( $css_file );
$tests = [];

// 1. Brand colors match theme (#CE2027)
$tests['Brand Color #CE2027 present'] = (strpos( $css_content, '#CE2027' ) !== false);

// 2. Mobile 375px / 768px breakpoints
$tests['Mobile media query max-width: 768px present'] = (strpos( $css_content, '@media (max-width: 768px)' ) !== false);

// 3. Print styles
$tests['Print media query present'] = (strpos( $css_content, '@media print' ) !== false);
$tests['Print hides buttons'] = (preg_match( '/@media print.*?\.btn-calculate.*display:\s*none/is', $css_content ) === 1);

// 4. All V4 CSS classes
$classes = [
    '.direction-pill-btn',
    '.cat-filter-tab',
    '.service-card-item',
    '.type-card-opt',
    '.mobile-comp-row',
    '.card-check-badge',
    '.btn-calculate',
    '.modal-backdrop',
    '.result-section',
    '.card-breakdown',
    '.piece-row',
    '.cell-input',
    '.btn-delete-piece',
    '.custom-scrollbar',
    '.spinner',
    '.inline-error',
    '#mobileStickyActionBar'
];

foreach ( $classes as $cls ) {
    $tests["Class $cls exists"] = (strpos( $css_content, $cls ) !== false);
}

// Result
$passed = 0;
$total = count( $tests );
echo "=== START TESTS STEP 4.5: FRONTEND CSS ===\n\n";

foreach ( $tests as $name => $result ) {
    if ( $result ) {
        echo "✔ Test Passed: $name\n";
        $passed++;
    } else {
        echo "❌ Test Failed: $name\n";
    }
}

echo "\nSummary: $passed/$total tests passed.\n";

if ( $passed === $total ) {
    echo "ALL TESTS STEP 4.5 PASSED 100%!\n";
    exit(0);
} else {
    echo "Some tests failed.\n";
    exit(1);
}
