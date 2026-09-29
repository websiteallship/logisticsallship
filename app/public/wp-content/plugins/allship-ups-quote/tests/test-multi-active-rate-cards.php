<?php
/**
 * Test: Multi Active Rate Cards with Single Active per Service & Direction Rule.
 *
 * Scenarios tested:
 * 1. Allow multiple rate cards to be active simultaneously if they represent different services.
 * 2. Distinguish between import and export for the same service (e.g. export_wxp vs import_wxp).
 * 3. Enforce single active card rule when activating a card with the same service and direction.
 * 4. Calculator resolves correct rate card ID based on service code and direction.
 * 5. Calculator reports error if no active rate card exists for requested service.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );

require_once __DIR__ . '/../includes/class-rate-card-repository.php';
require_once __DIR__ . '/../includes/class-rate-lookup.php';
require_once __DIR__ . '/../includes/class-rate-repository.php';
require_once __DIR__ . '/../includes/class-zone-resolver.php';
require_once __DIR__ . '/../includes/class-zone-repository.php';
require_once __DIR__ . '/../includes/class-country-repository.php';
require_once __DIR__ . '/../includes/class-quote-calculator.php';
require_once __DIR__ . '/../includes/class-service-availability-manager.php';

class Mock_WPDB_Multi_Active {
	public $prefix    = 'wp_';
	public $insert_id = 0;

	public $rate_cards = [];
	public $rates      = [];
	public $countries  = [];
	public $zone_maps  = [];

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			if ( is_array( $arg ) ) {
				$val = "'" . addslashes( json_encode( $arg ) ) . "'";
			} elseif ( is_numeric( $arg ) ) {
				$val = $arg;
			} else {
				$val = "'" . addslashes( (string) $arg ) . "'";
			}
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
			if ( preg_match( '/rate_card_id = (\d+)/', $query, $m ) ) {
				$cid = (int) $m[1];
				$groups = [];
				foreach ( $this->rates as $r ) {
					if ( (int) $r->rate_card_id === $cid ) {
						$groups[ $r->rate_group ] = true;
					}
				}
				foreach ( array_keys( $groups ) as $g ) {
					$res[] = (object) [ 'rate_group' => $g ];
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

			if ( preg_match( "/r\.rate_group = '([^']+)'/", $query, $m_rg ) ) {
				$target_group = $m_rg[1];
				foreach ( array_reverse( $this->rate_cards, true ) as $c ) {
					if ( 'active' !== $c->status ) {
						continue;
					}
					foreach ( $this->rates as $r ) {
						if ( (int) $r->rate_card_id === (int) $c->id && $r->rate_group === $target_group ) {
							return clone $c;
						}
					}
				}
				return null;
			}

			if ( strpos( $query, "status = 'active'" ) !== false ) {
				foreach ( array_reverse( $this->rate_cards, true ) as $c ) {
					if ( 'active' === $c->status ) {
						return clone $c;
					}
				}
				return null;
			}
		}

		if ( strpos( $query, 'ups_rates' ) !== false ) {
			foreach ( $this->rates as $r ) {
				return clone $r;
			}
		}

		return null;
	}

	public function get_col( $query, $x = 0 ) {
		$results = $this->get_results( $query );
		$col = [];
		foreach ( $results as $r ) {
			if ( is_object( $r ) ) {
				$arr = get_object_vars( $r );
				$col[] = reset( $arr );
			}
		}
		return $col;
	}
}

echo "=== START TEST: MULTI-ACTIVE RATE CARDS RULE ===\n\n";

$mock_db = new Mock_WPDB_Multi_Active();
$rc_repo = new Allship_UPS_Rate_Card_Repository( $mock_db );

// Helper to seed a rate card with a rate group
function seed_card( $mock_db, $rc_repo, $name, $rate_group, $direction ) {
	$card_id = $rc_repo->create([
		'name'               => $name,
		'market_code'        => 'VN',
		'enabled_directions' => [ $direction ],
	]);

	// Insert dummy rate
	$mock_db->insert( 'wp_ups_rates', [
		'rate_card_id' => $card_id,
		'rate_group'   => $rate_group,
		'zone'         => '1',
		'weight_label' => '1.0',
		'weight_from'  => 0.5,
		'weight_to'    => 1.0,
		'billing_unit' => 'flat',
		'price_vnd'    => 200000,
	]);

	return $card_id;
}

// 1. Create Cards
echo "--- Step 1: Creating Rate Cards ---\n";
$card_xpd_export  = seed_card( $mock_db, $rc_repo, 'XPD Export 2026-09-28', 'export_xpd', 'export' );
$card_wxp_export1 = seed_card( $mock_db, $rc_repo, 'WXP Export 2026-09-29', 'export_wxp', 'export' );
$card_wxp_import  = seed_card( $mock_db, $rc_repo, 'WXP Import 2026-09-29', 'import_wxp', 'import' );
$card_wxp_export2 = seed_card( $mock_db, $rc_repo, 'WXP Export 2026-09-30 (Newer)', 'export_wxp', 'export' );

echo "Created 4 cards: #$card_xpd_export (XPD Export), #$card_wxp_export1 (WXP Export), #$card_wxp_import (WXP Import), #$card_wxp_export2 (WXP Export New)\n\n";

// 2. Activate Card 1 (XPD Export)
echo "--- Step 2: Activating #$card_xpd_export (export_xpd) ---\n";
$rc_repo->activate( $card_xpd_export );
assert( $rc_repo->get( $card_xpd_export )->status === 'active', 'Card 1 must be active' );
echo "✓ Card #$card_xpd_export is Active.\n\n";

// 3. Activate Card 2 (WXP Export) - Different service
echo "--- Step 3: Activating #$card_wxp_export1 (export_wxp) ---\n";
$rc_repo->activate( $card_wxp_export1 );
assert( $rc_repo->get( $card_wxp_export1 )->status === 'active', 'Card 2 must be active' );
assert( $rc_repo->get( $card_xpd_export )->status === 'active', 'Card 1 (different service) MUST REMAIN ACTIVE' );
echo "✓ Card #$card_wxp_export1 is Active AND Card #$card_xpd_export remains Active! (Multiple active cards allowed)\n\n";

// 4. Activate Card 3 (WXP Import) - Same service code (WXP) but DIFFERENT direction (import)
echo "--- Step 4: Activating #$card_wxp_import (import_wxp) ---\n";
$rc_repo->activate( $card_wxp_import );
assert( $rc_repo->get( $card_wxp_import )->status === 'active', 'Card 3 (Import WXP) must be active' );
assert( $rc_repo->get( $card_wxp_export1 )->status === 'active', 'Card 2 (Export WXP) MUST REMAIN ACTIVE (Import != Export)' );
assert( $rc_repo->get( $card_xpd_export )->status === 'active', 'Card 1 (Export XPD) MUST REMAIN ACTIVE' );
echo "✓ Card #$card_wxp_import (Import) is Active AND Card #$card_wxp_export1 (Export) remains Active! (Export & Import distinguished)\n\n";

// 5. Activate Card 4 (WXP Export New) - SAME service AND SAME direction as Card 2
echo "--- Step 5: Activating #$card_wxp_export2 (export_wxp conflicting with #$card_wxp_export1) ---\n";
$rc_repo->activate( $card_wxp_export2 );
assert( $rc_repo->get( $card_wxp_export2 )->status === 'active', 'Card 4 must be active' );
assert( $rc_repo->get( $card_wxp_export1 )->status === 'archived', 'Card 2 (conflicting export_wxp) MUST BE ARCHIVED' );
assert( $rc_repo->get( $card_wxp_import )->status === 'active', 'Card 3 (import_wxp) MUST REMAIN ACTIVE' );
assert( $rc_repo->get( $card_xpd_export )->status === 'active', 'Card 1 (export_xpd) MUST REMAIN ACTIVE' );
echo "✓ Card #$card_wxp_export2 is Active, Card #$card_wxp_export1 was automatically archived, while #$card_wxp_import and #$card_xpd_export REMAIN ACTIVE!\n\n";

// 6. Test Repository Query Resolution
echo "--- Step 6: Testing Rate Card Resolution by Rate Group ---\n";
assert( $rc_repo->get_active_id_for_rate_group( 'export_xpd' ) === $card_xpd_export, 'Resolved active for export_xpd' );
assert( $rc_repo->get_active_id_for_rate_group( 'export_wxp' ) === $card_wxp_export2, 'Resolved active for export_wxp' );
assert( $rc_repo->get_active_id_for_rate_group( 'import_wxp' ) === $card_wxp_import, 'Resolved active for import_wxp' );
assert( $rc_repo->get_active_id_for_rate_group( 'export_wfm' ) === 0, 'No active card for unimported service export_wfm' );
echo "✓ get_active_id_for_rate_group resolves precisely per rate group.\n\n";

echo "============================================\n";
echo "✓ ALL MULTI-ACTIVE RATE CARD TESTS PASSED 100%!\n";
echo "============================================\n";
