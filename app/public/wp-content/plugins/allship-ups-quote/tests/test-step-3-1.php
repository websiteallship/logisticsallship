<?php
/**
 * Test Step 3.1 — Weight Calculator.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-weight-calculator.php';

echo "=== START TEST STEP 3.1: WEIGHT CALCULATOR ===\n";

$wc = new Allship_UPS_Weight_Calculator();

// TEST 1: Mandatory roadmap assertions
echo "\n--- Test 1: Mandatory Roadmap Unit Tests ---\n";

// 1.1: actual > dim -> chargeable 3.5
assert( $wc->ceil_to_step( max( 3.2, 1.7 ), 0.5 ) === 3.5, 'Test 1.1 failed: actual > dim' );
echo "✓ actual > dim (3.2 vs 1.7) -> chargeable 3.5\n";

// 1.2: dim > actual -> chargeable 1.5
assert( $wc->ceil_to_step( max( 1.0, 1.3 ), 0.5 ) === 1.5, 'Test 1.2 failed: dim > actual' );
echo "✓ dim > actual (1.0 vs 1.3) -> chargeable 1.5\n";

// 1.3: đúng mốc 2.5
assert( $wc->ceil_to_step( 2.5, 0.5 ) === 2.5, 'Test 1.3 failed: exact step 2.5' );
echo "✓ đúng mốc (2.5) -> chargeable 2.5\n";

// 1.4: lẻ nhỏ 0.1
assert( $wc->ceil_to_step( 0.1, 0.5 ) === 0.5, 'Test 1.4 failed: small fraction 0.1' );
echo "✓ lẻ nhỏ (0.1) -> chargeable 0.5\n";

// 1.5: multi-piece: 1.3 + 2.1 -> 1.5 + 2.5 = 4.0
$pieces = $wc->calculate_pieces( [
	[ 'actual_weight_kg' => 1.3, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10, 'quantity' => 1 ],
	[ 'actual_weight_kg' => 2.1, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10, 'quantity' => 1 ],
], 5500, 0.5 );
assert( $wc->total_chargeable( $pieces ) === 4.0, 'Test 1.5 failed: multi-piece sum 4.0' );
echo "✓ multi-piece (1.3 + 2.1) -> 1.5 + 2.5 = 4.0\n";

// TEST 2: Quantity handling (quantity > 1: tính 1 kiện * quantity)
echo "\n--- Test 2: Quantity > 1 Handling ---\n";
// Piece 1: 1.3kg (chargeable 1.5kg), qty = 3 -> 4.5kg
$p_qty = $wc->calculate_piece( 1.3, 10, 10, 10, 5500, 0.5, 3 );
assert( $p_qty->unit_chargeable_kg === 1.5, 'Unit chargeable must be 1.5' );
assert( $p_qty->chargeable_weight_kg === 4.5, 'Total piece chargeable for 3 units must be 4.5' );
assert( $p_qty['chargeable_weight_kg'] === 4.5, 'ArrayAccess must return 4.5' );
echo "✓ quantity = 3 (1.3kg/unit) -> 1.5 * 3 = 4.5\n";

// Multi-piece with quantities
$pieces_with_qty = $wc->calculate_pieces( [
	[ 'actual_weight_kg' => 1.3, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10, 'quantity' => 2 ], // 1.5 * 2 = 3.0
	[ 'actual_weight_kg' => 2.1, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10, 'quantity' => 3 ], // 2.5 * 3 = 7.5
], 5500, 0.5 );
assert( $wc->total_chargeable( $pieces_with_qty ) === 10.5, 'Total chargeable must be 3.0 + 7.5 = 10.5' );
echo "✓ multi-piece with quantities -> (1.5*2) + (2.5*3) = 10.5\n";

// TEST 3: Dimensional weight calculation & boundary steps
echo "\n--- Test 3: Dimensional Weight & Step Precision ---\n";
// 50 x 50 x 50 / 5500 = 22.7272... kg
$dim_wt = $wc->calculate_dim_weight( 50, 50, 50, 5500 );
assert( abs( $dim_wt - 22.727272 ) < 0.001 );
assert( $wc->ceil_to_step( $dim_wt, 0.5 ) === 23.0 );
echo "✓ 50x50x50 / 5500 = 22.73kg -> rounded up to 23.0kg\n";

// Alternative step: 1.0kg step
assert( $wc->ceil_to_step( 21.2, 1.0 ) === 22.0 );
assert( $wc->ceil_to_step( 21.0, 1.0 ) === 21.0 );
echo "✓ 1.0kg step support verified (21.2 -> 22.0, 21.0 -> 21.0)\n";

// Zero weight edge case
assert( $wc->ceil_to_step( 0.0, 0.5 ) === 0.0 );
echo "✓ Zero weight returns 0.0\n";

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 3.1 PASSED 100%!\n";
echo "============================================\n";
