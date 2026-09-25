<?php
/**
 * Test Step 3.3 — Rate Lookup.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-rate-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-lookup.php';

echo "=== START TEST STEP 3.3: RATE LOOKUP ===\n";

class Mock_WPDB_RateLookup {
	public $prefix = 'wp_';
	public $rates = [];
	private $auto_id = 0;

	public function add_rate( array $data ) {
		$this->auto_id++;
		$obj = (object) [
			'id'           => $this->auto_id,
			'rate_card_id' => isset( $data['rate_card_id'] ) ? (int) $data['rate_card_id'] : 1,
			'rate_group'   => isset( $data['rate_group'] ) ? $data['rate_group'] : '',
			'zone'         => isset( $data['zone'] ) ? (string) $data['zone'] : '',
			'weight_label' => isset( $data['weight_label'] ) ? (string) $data['weight_label'] : '',
			'weight_from'  => isset( $data['weight_from'] ) && '' !== $data['weight_from'] && null !== $data['weight_from'] ? (float) $data['weight_from'] : null,
			'weight_to'    => isset( $data['weight_to'] ) && '' !== $data['weight_to'] && null !== $data['weight_to'] ? (float) $data['weight_to'] : null,
			'billing_unit' => isset( $data['billing_unit'] ) ? $data['billing_unit'] : 'flat',
			'price_vnd'    => isset( $data['price_vnd'] ) ? (int) $data['price_vnd'] : 0,
			'sort_order'   => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
		];
		$this->rates[] = $obj;
		return $this->auto_id;
	}

	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$pos = strpos( $query, '%' );
			if ( false !== $pos ) {
				$val = ( null === $arg ) ? 'NULL' : ( is_numeric( $arg ) ? $arg : "'" . addslashes( (string) $arg ) . "'" );
				$query = substr_replace( $query, $val, $pos, 2 );
			}
		}
		return $query;
	}

	public function get_row( $sql, $output = 'OBJECT' ) {
		preg_match( "/rate_card_id = (\d+)/", $sql, $m_rc );
		preg_match( "/rate_group = '([^']+)'/", $sql, $m_rg );
		preg_match( "/zone = '([^']+)'/", $sql, $m_z );

		$rc_id = ! empty( $m_rc[1] ) ? (int) $m_rc[1] : null;
		$rg    = ! empty( $m_rg[1] ) ? $m_rg[1] : null;
		$zone  = ! empty( $m_z[1] ) ? $m_z[1] : null;

		// 1. Envelope
		if ( false !== strpos( $sql, "billing_unit = 'envelope'" ) || false !== strpos( $sql, "UPS Envelope" ) ) {
			foreach ( $this->rates as $r ) {
				if ( ( null === $rc_id || $r->rate_card_id === $rc_id )
					&& ( null === $rg || $r->rate_group === $rg )
					&& ( null === $zone || $r->zone === $zone )
					&& ( 'envelope' === $r->billing_unit || 'UPS Envelope' === $r->weight_label ) ) {
					return clone $r;
				}
			}
			return null;
		}

		// 2. Minimum
		if ( false !== strpos( $sql, "billing_unit = 'minimum'" ) ) {
			foreach ( $this->rates as $r ) {
				if ( ( null === $rc_id || $r->rate_card_id === $rc_id )
					&& ( null === $rg || $r->rate_group === $rg )
					&& ( null === $zone || $r->zone === $zone )
					&& 'minimum' === $r->billing_unit ) {
					return clone $r;
				}
			}
			return null;
		}

		// 3. Flat
		if ( false !== strpos( $sql, "billing_unit = 'flat'" ) ) {
			preg_match( "/weight_to >= ([\d.]+)/", $sql, $m_w );
			$w_target   = ! empty( $m_w[1] ) ? (float) $m_w[1] : 0.0;
			$candidates = [];
			foreach ( $this->rates as $r ) {
				if ( ( null === $rc_id || $r->rate_card_id === $rc_id )
					&& ( null === $rg || $r->rate_group === $rg )
					&& ( null === $zone || $r->zone === $zone )
					&& 'flat' === $r->billing_unit
					&& null !== $r->weight_to
					&& $r->weight_to >= $w_target ) {
					$candidates[] = $r;
				}
			}
			if ( empty( $candidates ) ) {
				return null;
			}
			usort( $candidates, function( $a, $b ) {
				return $a->weight_to <=> $b->weight_to;
			} );
			return clone $candidates[0];
		}

		// 4. Per_kg
		if ( false !== strpos( $sql, "billing_unit = 'per_kg'" ) ) {
			preg_match( "/weight_from <= ([\d.]+)/", $sql, $m_wf );
			$w_target   = ! empty( $m_wf[1] ) ? (float) $m_wf[1] : 0.0;
			$candidates = [];
			foreach ( $this->rates as $r ) {
				if ( ( null === $rc_id || $r->rate_card_id === $rc_id )
					&& ( null === $rg || $r->rate_group === $rg )
					&& ( null === $zone || $r->zone === $zone )
					&& 'per_kg' === $r->billing_unit
					&& null !== $r->weight_from
					&& $r->weight_from <= $w_target
					&& ( null === $r->weight_to || $r->weight_to >= $w_target ) ) {
					$candidates[] = $r;
				}
			}
			if ( empty( $candidates ) ) {
				return null;
			}
			usort( $candidates, function( $a, $b ) {
				return $b->weight_from <=> $a->weight_from;
			} );
			return clone $candidates[0];
		}

		return null;
	}
}

$mock_wpdb = new Mock_WPDB_RateLookup();

// Seed Rates matching actual Excel data for rate_card_id = 1
$rc_id = 1;

// 1. WXS Document - US5: 0.5kg=935293, 1.0kg=1274248, 5.0kg=3510000
$mock_wpdb->add_rate([
	'rate_card_id' => $rc_id,
	'rate_group'   => 'export_wxs_document',
	'zone'         => 'US5',
	'weight_label' => '0.5',
	'weight_from'  => 0.5,
	'weight_to'    => 0.5,
	'billing_unit' => 'flat',
	'price_vnd'    => 935293,
]);
$mock_wpdb->add_rate([
	'rate_card_id' => $rc_id,
	'rate_group'   => 'export_wxs_document',
	'zone'         => 'US5',
	'weight_label' => '1.0',
	'weight_from'  => 1.0,
	'weight_to'    => 1.0,
	'billing_unit' => 'flat',
	'price_vnd'    => 1274248,
]);
$mock_wpdb->add_rate([
	'rate_card_id' => $rc_id,
	'rate_group'   => 'export_wxs_document',
	'zone'         => 'US5',
	'weight_label' => '5.0',
	'weight_from'  => 5.0,
	'weight_to'    => 5.0,
	'billing_unit' => 'flat',
	'price_vnd'    => 3510000,
]);
// Envelope for Document
$mock_wpdb->add_rate([
	'rate_card_id' => $rc_id,
	'rate_group'   => 'export_wxs_document',
	'zone'         => 'US5',
	'weight_label' => 'UPS Envelope',
	'weight_from'  => null,
	'weight_to'    => null,
	'billing_unit' => 'envelope',
	'price_vnd'    => 750000,
]);

// 2. WXS Non-doc - Zone 5: 6.0kg flat = 1686981 VND
$mock_wpdb->add_rate([
	'rate_card_id' => $rc_id,
	'rate_group'   => 'export_wxs_nondocument',
	'zone'         => '5',
	'weight_label' => '5.5',
	'weight_from'  => 5.5,
	'weight_to'    => 5.5,
	'billing_unit' => 'flat',
	'price_vnd'    => 1580000,
]);
$mock_wpdb->add_rate([
	'rate_card_id' => $rc_id,
	'rate_group'   => 'export_wxs_nondocument',
	'zone'         => '5',
	'weight_label' => '6.0',
	'weight_from'  => 6.0,
	'weight_to'    => 6.0,
	'billing_unit' => 'flat',
	'price_vnd'    => 1686981,
]);

// 3. WXS Non-doc - Zone 5: Heavy bracket 21-44 per_kg = 153636 VND/kg
$mock_wpdb->add_rate([
	'rate_card_id' => $rc_id,
	'rate_group'   => 'export_wxs_nondocument',
	'zone'         => '5',
	'weight_label' => '21-44',
	'weight_from'  => 21.0,
	'weight_to'    => 44.0,
	'billing_unit' => 'per_kg',
	'price_vnd'    => 153636,
]);

// 4. WFM - Zone 5: Minimum = 13089824, bracket 71-99 = 184363 VND/kg
$mock_wpdb->add_rate([
	'rate_card_id' => $rc_id,
	'rate_group'   => 'export_wfm',
	'zone'         => '5',
	'weight_label' => 'Minimum',
	'weight_from'  => null,
	'weight_to'    => null,
	'billing_unit' => 'minimum',
	'price_vnd'    => 13089824,
]);
$mock_wpdb->add_rate([
	'rate_card_id' => $rc_id,
	'rate_group'   => 'export_wfm',
	'zone'         => '5',
	'weight_label' => '71-99',
	'weight_from'  => 71.0,
	'weight_to'    => 99.0,
	'billing_unit' => 'per_kg',
	'price_vnd'    => 184363,
]);

$rate_repo = new Allship_UPS_Rate_Repository( $mock_wpdb );
$lookup    = new Allship_UPS_Rate_Lookup( $rate_repo );

// TEST 0: map_rate_group
echo "\n--- Test 0: map_rate_group mappings ---\n";
assert( $lookup->map_rate_group( 'WXS', 'document' ) === 'export_wxs_document' );
assert( $lookup->map_rate_group( 'WXS', 'nondocument' ) === 'export_wxs_nondocument' );
assert( $lookup->map_rate_group( 'WFM' ) === 'export_wfm' );
assert( $lookup->map_rate_group( 'XPD', 'nondocument' ) === 'export_xpd' );
assert( $lookup->map_rate_group( 'WXS', 'document', 'import' ) === 'import_wxs_document' );
echo "✓ map_rate_group correctly resolves rate groups\n";

// TEST 1: WXS Document 1kg US -> cột US5, giá dòng 1kg = 1,274,248 VND
echo "\n--- Test 1: WXS Document 1kg US -> US5, dòng 1kg (1,274,248 VND) ---\n";
$rg_doc = $lookup->map_rate_group( 'WXS', 'document' );
$r1     = $lookup->find_price( $rc_id, $rg_doc, 'US5', 1.0, false );
assert( $r1->success === true, 'Test 1 should succeed' );
assert( $r1->price_vnd === 1274248, "Expected 1274248 VND, got {$r1->price_vnd}" );
assert( $r1->rate_zone === 'US5', "Expected US5, got {$r1->rate_zone}" );
assert( $r1->billing_unit === 'flat', 'Billing unit must be flat' );
assert( $r1['price_vnd'] === 1274248, 'ArrayAccess check on price_vnd' );
echo "✓ Test 1 passed: price_vnd = {$r1->price_vnd} VND\n";

// TEST 2: WXS Non-doc 6kg Zone 5 -> flat giá dòng 6kg = 1,686,981 VND
echo "\n--- Test 2: WXS Non-doc 6kg Zone 5 -> flat dòng 6kg (1,686,981 VND) ---\n";
$rg_nondoc = $lookup->map_rate_group( 'WXS', 'nondocument' );
$r2        = $lookup->find_price( $rc_id, $rg_nondoc, '5', 6.0, false );
assert( $r2->success === true, 'Test 2 should succeed' );
assert( $r2->price_vnd === 1686981, "Expected 1686981 VND, got {$r2->price_vnd}" );
assert( $r2->billing_unit === 'flat', 'Billing unit must be flat' );
assert( $r2->weight_label === '6.0', "Weight label must be 6.0, got {$r2->weight_label}" );
echo "✓ Test 2 passed: price_vnd = {$r2->price_vnd} VND (flat)\n";

// TEST 3: WXS Non-doc 25kg -> bracket 21-44, per_kg × 25 = 153,636 × 25 = 3,840,900 VND
echo "\n--- Test 3: WXS Non-doc 25kg -> bracket 21-44, per_kg × 25 (3,840,900 VND) ---\n";
$r3 = $lookup->find_price( $rc_id, $rg_nondoc, '5', 25.0, false );
assert( $r3->success === true, 'Test 3 should succeed' );
assert( $r3->billing_unit === 'per_kg', 'Billing unit must be per_kg' );
assert( $r3->unit_rate === 153636, "Unit rate must be 153636, got {$r3->unit_rate}" );
assert( $r3->price_vnd === 3840900, "Expected 3840900 VND, got {$r3->price_vnd}" );
echo "✓ Test 3 passed: unit_rate = {$r3->unit_rate} VND/kg × 25kg = {$r3->price_vnd} VND\n";

// TEST 4a: WFM 80kg -> max(minimum 13,089,824, bracket 71-99 × 80 = 14,749,040)
echo "\n--- Test 4a: WFM 80kg -> max(minimum, bracket 71-99 × 80) ---\n";
$rg_wfm = $lookup->map_rate_group( 'WFM' );
$r4a    = $lookup->find_price( $rc_id, $rg_wfm, '5', 80.0, false );
assert( $r4a->success === true, 'Test 4a should succeed' );
assert( $r4a->unit_rate === 184363, "Unit rate must be 184363, got {$r4a->unit_rate}" );
assert( $r4a->min_price === 13089824, "Minimum threshold must be 13089824, got {$r4a->min_price}" );
assert( $r4a->price_vnd === 14749040, "Expected 14749040 VND, got {$r4a->price_vnd}" );
echo "✓ Test 4a passed: 80kg × 184,363 = 14,749,040 VND (> min 13,089,824 VND)\n";

// TEST 4b: WFM 71kg -> 71 × 184,363 = 13,089,773 < minimum 13,089,824 -> takes minimum
echo "\n--- Test 4b: WFM 71kg -> takes minimum threshold ---\n";
$r4b = $lookup->find_price( $rc_id, $rg_wfm, '5', 71.0, false );
assert( $r4b->success === true, 'Test 4b should succeed' );
assert( $r4b->price_vnd === 13089824, "Expected minimum 13089824 VND, got {$r4b->price_vnd}" );
echo "✓ Test 4b passed: 71kg × 184,363 = 13,089,773 < min -> took minimum 13,089,824 VND\n";

// TEST 5: Document 6kg -> error DOCUMENT_OVER_5KG
echo "\n--- Test 5: Document 6kg -> error DOCUMENT_OVER_5KG ---\n";
$r5 = $lookup->find_price( $rc_id, $rg_doc, 'US5', 6.0, false );
assert( $r5->success === false, 'Test 5 must fail' );
assert( $r5->is_error() === true, 'is_error() must return true' );
assert( $r5->error_code === 'DOCUMENT_OVER_5KG', "Expected DOCUMENT_OVER_5KG, got '{$r5->error_code}'" );
echo "✓ Test 5 passed: Document 6kg returned error_code 'DOCUMENT_OVER_5KG'\n";

// TEST 6: Envelope lookup
echo "\n--- Test 6: UPS Envelope lookup ---\n";
$r6 = $lookup->find_price( $rc_id, $rg_doc, 'US5', 0.2, true );
assert( $r6->success === true, 'Envelope lookup should succeed' );
assert( $r6->price_vnd === 750000, "Expected 750000 VND, got {$r6->price_vnd}" );
assert( $r6->billing_unit === 'envelope', 'Billing unit must be envelope' );
echo "✓ Test 6 passed: Envelope price_vnd = {$r6->price_vnd} VND\n";

// TEST 7: Rate not found
echo "\n--- Test 7: Rate not found -> RATE_NOT_FOUND ---\n";
$r7 = $lookup->find_price( $rc_id, $rg_doc, 'UNKNOWN_ZONE', 1.0, false );
assert( $r7->success === false, 'Unknown zone lookup must fail' );
assert( $r7->error_code === 'RATE_NOT_FOUND', "Expected RATE_NOT_FOUND, got '{$r7->error_code}'" );
echo "✓ Test 7 passed: Unknown zone returned error_code 'RATE_NOT_FOUND'\n";

echo "\n============================================\n";
echo "✓ ALL TESTS STEP 3.3 PASSED 100%!\n";
echo "============================================\n";
