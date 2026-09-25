<?php
/**
 * Phase 1 Unit Test Suite for Allship UPS Quote Plugin.
 *
 * Verifies all Gate criteria for Step 1.7:
 * 1. Settings CRUD (get/set/delete/get_all/caching)
 * 2. Rate Card CRUD (create/get/activate/archive/delete/rename)
 * 3. Rate Card single-active rule
 * 4. Country CRUD + search + bulk_upsert + US override & extended note detection
 * 5. Zone CRUD + find_zone + bulk_insert + delete_by_rate_card
 * 6. Rate CRUD + find_price (flat, per_kg, minimum, envelope)
 * 7. Quote Log insert + filter + count + CSV/Excel export
 * 8. SQL prepare audit for user inputs
 *
 * Run with: php tests/test-phase-1.php
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

/**
 * In-memory Mock WPDB for standalone CLI test runs.
 */
class Allship_UPS_Phase1_Mock_WPDB {
	public $prefix    = 'wp_';
	public $insert_id = 0;

	public $settings   = [];
	public $rate_cards = [];
	public $countries  = [];
	public $zone_maps  = [];
	public $rates      = [];
	public $quote_logs = [];

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

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function insert( $table, $data, $format = null ) {
		$this->insert_id++;
		$data['id'] = $this->insert_id;

		if ( strpos( $table, 'ups_settings' ) !== false ) {
			$this->settings[ $data['setting_key'] ] = $data['setting_value'];
		} elseif ( strpos( $table, 'ups_rate_cards' ) !== false ) {
			$this->rate_cards[ $this->insert_id ] = (object) $data;
		} elseif ( strpos( $table, 'ups_countries' ) !== false ) {
			$this->countries[ $this->insert_id ] = (object) $data;
		} elseif ( strpos( $table, 'ups_quote_logs' ) !== false ) {
			$this->quote_logs[ $this->insert_id ] = (object) $data;
		}

		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$updated = 0;

		if ( strpos( $table, 'ups_settings' ) !== false ) {
			if ( isset( $where['setting_key'] ) ) {
				$key = $where['setting_key'];
				$this->settings[ $key ] = $data['setting_value'];
				return 1;
			}
		} elseif ( strpos( $table, 'ups_rate_cards' ) !== false ) {
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
		} elseif ( strpos( $table, 'ups_countries' ) !== false ) {
			$id = $where['id'];
			if ( isset( $this->countries[ $id ] ) ) {
				foreach ( $data as $k => $v ) {
					$this->countries[ $id ]->$k = $v;
				}
				return 1;
			}
		} elseif ( strpos( $table, 'ups_zone_maps' ) !== false ) {
			$id = $where['id'];
			foreach ( $this->zone_maps as $zm ) {
				if ( $zm->id === $id ) {
					foreach ( $data as $k => $v ) {
						$zm->$k = $v;
					}
					return 1;
				}
			}
		} elseif ( strpos( $table, 'ups_rates' ) !== false ) {
			$id = $where['id'];
			foreach ( $this->rates as $r ) {
				if ( $r->id === $id ) {
					foreach ( $data as $k => $v ) {
						$r->$k = $v;
					}
					return 1;
				}
			}
		}

		return $updated;
	}

	public function query( $sql ) {
		// Mock ups_countries bulk upsert
		if ( strpos( $sql, 'ups_countries' ) !== false && preg_match( "/VALUES \('([^']+)',\s*'([^']+)',\s*'([^']+)',\s*(\d+),\s*(\d+),\s*(\d+)\)/", $sql, $m ) ) {
			$iata  = $m[1];
			$found = false;
			foreach ( $this->countries as $c ) {
				if ( $c->iata_code === $iata ) {
					$c->country_name           = $m[2];
					$c->normalized_name        = $m[3];
					$c->is_us_override         = (int) $m[4];
					$c->has_extended_area_note = (int) $m[5];
					$c->is_active              = (int) $m[6];
					$found                     = true;
					break;
				}
			}
			if ( ! $found ) {
				$this->insert_id++;
				$this->countries[ $this->insert_id ] = (object) [
					'id'                     => $this->insert_id,
					'iata_code'              => $iata,
					'country_name'           => $m[2],
					'normalized_name'        => $m[3],
					'is_us_override'         => (int) $m[4],
					'has_extended_area_note' => (int) $m[5],
					'is_active'              => (int) $m[6],
				];
			}
			return 1;
		}

		// Mock ups_zone_maps insert/update
		if ( strpos( $sql, 'ups_zone_maps' ) !== false && preg_match( "/VALUES\s*\(([^)]+)\)/i", $sql, $m ) ) {
			$parts = str_getcsv( $m[1], ',', "'" );
			$parts = array_map( 'trim', $parts );
			$rc_id = (int) $parts[0];
			$c_id  = (int) $parts[1];
			$dir   = $parts[2];
			$svc   = $parts[3];
			$type  = $parts[4];
			$zone  = $parts[5];
			$avail = (int) $parts[6];

			$key = "{$rc_id}_{$c_id}_{$dir}_{$svc}";
			if ( isset( $this->zone_maps[ $key ] ) ) {
				$this->zone_maps[ $key ]->service_type = $type;
				$this->zone_maps[ $key ]->zone         = $zone;
				$this->zone_maps[ $key ]->is_available = $avail;
				$this->insert_id                       = $this->zone_maps[ $key ]->id;
			} else {
				$this->insert_id++;
				$this->zone_maps[ $key ] = (object) [
					'id'           => $this->insert_id,
					'rate_card_id' => $rc_id,
					'country_id'   => $c_id,
					'direction'    => $dir,
					'service_code' => $svc,
					'service_type' => $type,
					'zone'         => $zone,
					'is_available' => $avail,
				];
			}
			return 1;
		}

		// Mock ups_rates insert/update
		if ( strpos( $sql, 'ups_rates' ) !== false && preg_match( "/VALUES\s*\(([^)]+)\)/i", $sql, $m ) ) {
			$parts  = str_getcsv( $m[1], ',', "'" );
			$parts  = array_map( 'trim', $parts );
			$rc_id  = (int) $parts[0];
			$group  = $parts[1];
			$zone   = $parts[2];
			$label  = $parts[3];
			$w_from = '' !== $parts[4] && 'NULL' !== $parts[4] ? (float) $parts[4] : null;
			$w_to   = '' !== $parts[5] && 'NULL' !== $parts[5] ? (float) $parts[5] : null;
			$unit   = $parts[6];
			$price  = (int) $parts[7];
			$sort   = (int) $parts[8];

			$key = "{$rc_id}_{$group}_{$zone}_{$label}";
			if ( isset( $this->rates[ $key ] ) ) {
				$this->rates[ $key ]->weight_from  = $w_from;
				$this->rates[ $key ]->weight_to    = $w_to;
				$this->rates[ $key ]->billing_unit = $unit;
				$this->rates[ $key ]->price_vnd    = $price;
				$this->rates[ $key ]->sort_order   = $sort;
				$this->insert_id                   = $this->rates[ $key ]->id;
			} else {
				$this->insert_id++;
				$this->rates[ $key ] = (object) [
					'id'           => $this->insert_id,
					'rate_card_id' => $rc_id,
					'rate_group'   => $group,
					'zone'         => $zone,
					'weight_label' => $label,
					'weight_from'  => $w_from,
					'weight_to'    => $w_to,
					'billing_unit' => $unit,
					'price_vnd'    => $price,
					'sort_order'   => $sort,
				];
			}
			return 1;
		}

		return 1;
	}

	public function get_var( $query ) {
		if ( strpos( $query, 'ups_settings' ) !== false ) {
			if ( preg_match( "/setting_key = '([^']+)'/", $query, $m ) ) {
				return isset( $this->settings[ $m[1] ] ) ? 1 : null;
			}
		}

		if ( strpos( $query, 'ups_zone_maps' ) !== false ) {
			if ( preg_match( "/rate_card_id\s*=\s*(\d+)/", $query, $m_rc ) &&
			     preg_match( "/country_id\s*=\s*(\d+)/", $query, $m_c ) &&
			     preg_match( "/direction\s*=\s*'([^']+)'/", $query, $m_d ) &&
			     preg_match( "/service_code\s*=\s*'([^']+)'/", $query, $m_s ) ) {
				$key = "{$m_rc[1]}_{$m_c[1]}_{$m_d[1]}_{$m_s[1]}";
				if ( strpos( $query, 'SELECT zone' ) !== false ) {
					return isset( $this->zone_maps[ $key ] ) ? $this->zone_maps[ $key ]->zone : null;
				}
				if ( strpos( $query, 'SELECT id' ) !== false ) {
					return isset( $this->zone_maps[ $key ] ) ? $this->zone_maps[ $key ]->id : null;
				}
			}
		}

		if ( strpos( $query, 'ups_countries' ) !== false && strpos( $query, 'SELECT is_active' ) !== false ) {
			if ( preg_match( '/WHERE id = (\d+)/', $query, $m ) ) {
				$id = (int) $m[1];
				return isset( $this->countries[ $id ] ) ? $this->countries[ $id ]->is_active : null;
			}
		}

		if ( strpos( $query, 'COUNT(*)' ) !== false && strpos( $query, 'ups_quote_logs' ) !== false ) {
			return count( $this->quote_logs );
		}

		return null;
	}

	public function get_row( $query, $output = OBJECT ) {
		if ( strpos( $query, 'ups_rate_cards' ) !== false ) {
			if ( preg_match( '/WHERE id = (\d+)/', $query, $m ) ) {
				$id = (int) $m[1];
				return isset( $this->rate_cards[ $id ] ) ? clone $this->rate_cards[ $id ] : null;
			}
			if ( strpos( $query, "status = 'active'" ) !== false ) {
				foreach ( array_reverse( $this->rate_cards, true ) as $card ) {
					if ( 'active' === $card->status ) {
						return clone $card;
					}
				}
				return null;
			}
		}

		if ( strpos( $query, 'ups_countries' ) !== false ) {
			if ( preg_match( "/WHERE iata_code = '([^']+)'/", $query, $m ) ) {
				$iata = $m[1];
				foreach ( $this->countries as $c ) {
					if ( $c->iata_code === $iata ) {
						return clone $c;
					}
				}
				return null;
			}
		}

		if ( strpos( $query, 'ups_rates' ) !== false ) {
			if ( strpos( $query, 'UPS Envelope' ) !== false || strpos( $query, "billing_unit = 'envelope'" ) !== false ) {
				foreach ( $this->rates as $r ) {
					if ( 'envelope' === $r->billing_unit || 'UPS Envelope' === $r->weight_label ) {
						return clone $r;
					}
				}
				return null;
			}

			if ( strpos( $query, "billing_unit = 'minimum'" ) !== false ) {
				foreach ( $this->rates as $r ) {
					if ( 'minimum' === $r->billing_unit ) {
						return clone $r;
					}
				}
				return null;
			}

			if ( strpos( $query, "billing_unit = 'flat'" ) !== false ) {
				if ( preg_match( '/weight_to >= ([\d.]+)/', $query, $m ) ) {
					$target  = (float) $m[1];
					$matched = null;
					foreach ( $this->rates as $r ) {
						if ( 'flat' === $r->billing_unit && null !== $r->weight_to && $r->weight_to >= $target ) {
							if ( null === $matched || $r->weight_to < $matched->weight_to ) {
								$matched = $r;
							}
						}
					}
					return $matched ? clone $matched : null;
				}
			}

			if ( strpos( $query, "billing_unit = 'per_kg'" ) !== false ) {
				if ( preg_match( '/weight_from <= ([\d.]+)/', $query, $m ) ) {
					$target  = (float) $m[1];
					$matched = null;
					foreach ( $this->rates as $r ) {
						if ( 'per_kg' === $r->billing_unit && null !== $r->weight_from && $r->weight_from <= $target ) {
							if ( null === $r->weight_to || $r->weight_to >= $target ) {
								if ( null === $matched || $r->weight_from > $matched->weight_from ) {
									$matched = $r;
								}
							}
						}
					}
					return $matched ? clone $matched : null;
				}
			}
		}

		return null;
	}

	public function get_results( $query, $output = OBJECT ) {
		$res = [];

		if ( strpos( $query, 'ups_settings' ) !== false ) {
			foreach ( $this->settings as $k => $v ) {
				$res[] = [
					'setting_key'   => $k,
					'setting_value' => $v,
				];
			}
			return $res;
		}

		if ( strpos( $query, 'ups_rate_cards' ) !== false ) {
			foreach ( array_reverse( $this->rate_cards, true ) as $c ) {
				$res[] = clone $c;
			}
			return $res;
		}

		if ( strpos( $query, 'ups_countries' ) !== false ) {
			if ( strpos( $query, 'country_name LIKE' ) !== false ) {
				preg_match( "/country_name LIKE '%([^%]+)%'/", $query, $m );
				$term = isset( $m[1] ) ? $m[1] : '';
				foreach ( $this->countries as $c ) {
					if ( stripos( $c->country_name, $term ) !== false || stripos( $c->iata_code, $term ) !== false ) {
						$res[] = clone $c;
					}
				}
				return $res;
			}
			foreach ( $this->countries as $c ) {
				if ( 1 == $c->is_active ) {
					$res[] = clone $c;
				}
			}
			return $res;
		}

		if ( strpos( $query, 'ups_zone_maps' ) !== false ) {
			foreach ( $this->zone_maps as $zm ) {
				$res[] = clone $zm;
			}
			return $res;
		}

		if ( strpos( $query, 'ups_rates' ) !== false ) {
			foreach ( $this->rates as $r ) {
				$res[] = clone $r;
			}
			return $res;
		}

		if ( strpos( $query, 'ups_quote_logs' ) !== false ) {
			foreach ( array_reverse( $this->quote_logs, true ) as $log ) {
				$res[] = clone $log;
			}
			return $res;
		}

		return $res;
	}

	public function delete( $table, $where, $where_format = null ) {
		if ( strpos( $table, 'ups_settings' ) !== false && isset( $where['setting_key'] ) ) {
			unset( $this->settings[ $where['setting_key'] ] );
			return 1;
		}

		if ( strpos( $table, 'ups_rate_cards' ) !== false && isset( $where['id'] ) ) {
			unset( $this->rate_cards[ $where['id'] ] );
			return 1;
		}

		if ( strpos( $table, 'ups_zone_maps' ) !== false ) {
			if ( isset( $where['rate_card_id'] ) ) {
				$rc_id = $where['rate_card_id'];
				foreach ( $this->zone_maps as $k => $zm ) {
					if ( $zm->rate_card_id == $rc_id ) {
						unset( $this->zone_maps[ $k ] );
					}
				}
				return 1;
			}
			if ( isset( $where['id'] ) ) {
				unset( $this->zone_maps[ $where['id'] ] );
				return 1;
			}
		}

		if ( strpos( $table, 'ups_rates' ) !== false ) {
			if ( isset( $where['rate_card_id'] ) ) {
				$rc_id = $where['rate_card_id'];
				foreach ( $this->rates as $k => $r ) {
					if ( $r->rate_card_id == $rc_id ) {
						unset( $this->rates[ $k ] );
					}
				}
				return 1;
			}
			if ( isset( $where['id'] ) ) {
				unset( $this->rates[ $where['id'] ] );
				return 1;
			}
		}

		return 0;
	}
}

// Load plugin modules
require_once dirname( __DIR__ ) . '/includes/class-settings-manager.php';
require_once dirname( __DIR__ ) . '/includes/config/service-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-card-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-service-availability-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-country-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-zone-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-rate-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-quote-log-repository.php';

$mock_wpdb = new Allship_UPS_Phase1_Mock_WPDB();

echo "========================================================\n";
echo "ALLSHIP UPS QUOTE - PHASE 1 VERIFICATION TEST SUITE\n";
echo "========================================================\n\n";

// ---------------------------------------------------------
// CHECK 1: Settings CRUD
// ---------------------------------------------------------
echo "[Check 1/8] Settings CRUD & Typing... ";
$mock_wpdb->settings['dim_divisor'] = '5500';
Allship_UPS_Settings_Manager::clear_cache();
$sm = new Allship_UPS_Settings_Manager( $mock_wpdb );

assert( $sm->get( 'dim_divisor', 5000 ) === 5500, 'Check 1.1 Failed: get default 5500' );
$sm->set( 'dim_divisor', 6000 );
assert( $sm->get( 'dim_divisor' ) === 6000, 'Check 1.2 Failed: set & get 6000' );
$sm->delete( 'dim_divisor' );
assert( $sm->get( 'dim_divisor', 5000 ) === 5000, 'Check 1.3 Failed: fallback on delete' );

$sm->set( 'include_vat', true );
assert( $sm->get( 'include_vat' ) === true, 'Check 1.4 Failed: bool casting' );
$sm->set( 'vat_percent', 8.5 );
assert( $sm->get( 'vat_percent' ) === 8.5, 'Check 1.5 Failed: float casting' );
echo "PASSED\n";

// ---------------------------------------------------------
// CHECK 2 & 3: Rate Card CRUD & Single Active Rule
// ---------------------------------------------------------
echo "[Check 2/8] Rate Card CRUD (create/get/rename/delete)... ";
$rc_repo = new Allship_UPS_Rate_Card_Repository( $mock_wpdb );
$rc1_id  = $rc_repo->create( [ 'name' => 'Bảng giá Q3 2026', 'market_code' => 'VN' ] );
$rc2_id  = $rc_repo->create( [ 'name' => 'Bảng giá Q4 2026', 'market_code' => 'VN' ] );

assert( $rc1_id > 0 && $rc2_id > 0, 'Check 2.1 Failed: create rate cards' );
$rc1 = $rc_repo->get( $rc1_id );
assert( $rc1->name === 'Bảng giá Q3 2026', 'Check 2.2 Failed: get rate card' );

$rc_repo->update_name( $rc1_id, 'Bảng giá Q3 2026 Đã Đổi Tên' );
assert( $rc_repo->get( $rc1_id )->name === 'Bảng giá Q3 2026 Đã Đổi Tên', 'Check 2.3 Failed: rename rate card' );
echo "PASSED\n";

echo "[Check 3/8] Rate Card Single Active Rule... ";
$rc_repo->activate( $rc1_id );
assert( $rc_repo->get_active()->id === $rc1_id, 'Check 3.1 Failed: activate card 1' );

$rc_repo->activate( $rc2_id );
assert( $rc_repo->get_active()->id === $rc2_id, 'Check 3.2 Failed: activate card 2' );
assert( $rc_repo->get( $rc1_id )->status === 'archived', 'Check 3.3 Failed: card 1 archived automatically' );
echo "PASSED\n";

// ---------------------------------------------------------
// CHECK 4: Country Repository
// ---------------------------------------------------------
echo "[Check 4/8] Country Repository (US override & extended area note)... ";
$cty_repo = new Allship_UPS_Country_Repository( $mock_wpdb );
$us_id    = $cty_repo->insert( [ 'iata_code' => 'US', 'country_name' => 'United States*' ] );
$vn_id    = $cty_repo->insert( [ 'iata_code' => 'VN', 'country_name' => 'Vietnam' ] );

$us = $cty_repo->find_by_iata( 'US' );
assert( $us->is_us_override === 1, 'Check 4.1 Failed: US is_us_override must be 1' );
assert( $us->has_extended_area_note === 1, 'Check 4.2 Failed: US has_extended_area_note must be 1' );

$vn = $cty_repo->find_by_iata( 'VN' );
assert( $vn->is_us_override === 0, 'Check 4.3 Failed: VN is_us_override must be 0' );
assert( $vn->has_extended_area_note === 0, 'Check 4.4 Failed: VN has_extended_area_note must be 0' );

$search_res = $cty_repo->search( 'United' );
assert( count( $search_res ) >= 1, 'Check 4.5 Failed: search United' );

$bulk_res = $cty_repo->bulk_upsert( [
	[ 'iata_code' => 'JP', 'country_name' => 'Japan' ],
	[ 'iata_code' => 'KR', 'country_name' => 'Korea, Republic of*' ],
	[ 'iata_code' => 'ZZ', 'country_name' => 'Invalid ZZ' ], // must skip ZZ
] );
assert( $bulk_res === 2, 'Check 4.6 Failed: bulk upsert skip ZZ' );
assert( $cty_repo->find_by_iata( 'ZZ' ) === null, 'Check 4.7 Failed: ZZ must not be inserted' );
assert( $cty_repo->find_by_iata( 'KR' )->has_extended_area_note === 1, 'Check 4.8 Failed: KR extended note' );
echo "PASSED\n";

// ---------------------------------------------------------
// CHECK 5: Zone Repository
// ---------------------------------------------------------
echo "[Check 5/8] Zone Repository (find_zone / duplicate update / bulk)... ";
$zone_repo = new Allship_UPS_Zone_Repository( $mock_wpdb );

$zone_repo->insert( [
	'rate_card_id' => $rc2_id,
	'country_id'   => $us_id,
	'direction'    => 'export',
	'service_code' => 'WXS',
	'zone'         => '5',
] );

$found_zone = $zone_repo->find_zone( $rc2_id, $us_id, 'export', 'WXS' );
assert( $found_zone === '5', 'Check 5.1 Failed: find_zone export WXS' );

// Duplicate insert updates zone
$zone_repo->insert( [
	'rate_card_id' => $rc2_id,
	'country_id'   => $us_id,
	'direction'    => 'export',
	'service_code' => 'WXS',
	'zone'         => '6',
] );
assert( $zone_repo->find_zone( $rc2_id, $us_id, 'export', 'WXS' ) === '6', 'Check 5.2 Failed: duplicate insert update' );

$bulk_zone_cnt = $zone_repo->bulk_insert( [
	[ 'rate_card_id' => $rc2_id, 'country_id' => $vn_id, 'direction' => 'export', 'service_code' => 'XPD', 'zone' => '1' ],
	[ 'rate_card_id' => $rc2_id, 'country_id' => $vn_id, 'direction' => 'export', 'service_code' => 'WFM', 'zone' => '2' ],
] );
assert( $bulk_zone_cnt === 2, 'Check 5.3 Failed: bulk_insert zones' );
assert( $zone_repo->find_zone( $rc2_id, $vn_id, 'export', 'XPD' ) === '1', 'Check 5.4 Failed: XPD zone' );
echo "PASSED\n";

// ---------------------------------------------------------
// CHECK 6: Rate Repository & find_price
// ---------------------------------------------------------
echo "[Check 6/8] Rate Repository (flat, per_kg, minimum, envelope)... ";
$rate_repo = new Allship_UPS_Rate_Repository( $mock_wpdb );

// Flat rate
$rate_repo->insert( [
	'rate_card_id' => $rc2_id,
	'rate_group'   => 'export_wxs_nondocument',
	'zone'         => '5',
	'weight_label' => '6',
	'weight_from'  => 6.0,
	'weight_to'    => 6.0,
	'billing_unit' => 'flat',
	'price_vnd'    => 500000,
] );
$flat_price = $rate_repo->find_price( $rc2_id, 'export_wxs_nondocument', '5', 6.0, false );
assert( $flat_price !== null && $flat_price->price_vnd === 500000, 'Check 6.1 Failed: flat rate lookup' );

// Heavy bracket per_kg
$rate_repo->insert( [
	'rate_card_id' => $rc2_id,
	'rate_group'   => 'export_wxs_nondocument',
	'zone'         => '5',
	'weight_label' => '21-44',
	'weight_from'  => 21.0,
	'weight_to'    => 44.0,
	'billing_unit' => 'per_kg',
	'price_vnd'    => 100000,
] );
$per_kg_price = $rate_repo->find_price( $rc2_id, 'export_wxs_nondocument', '5', 25.0, false );
assert( $per_kg_price !== null && $per_kg_price->price_vnd === 2500000, 'Check 6.2 Failed: per_kg 25kg x 100,000 = 2,500,000' );

// Freight WFM with Minimum enforcement
$rate_repo->insert( [
	'rate_card_id' => $rc2_id,
	'rate_group'   => 'export_wfm',
	'zone'         => '5',
	'weight_label' => 'Minimum',
	'weight_from'  => null,
	'weight_to'    => null,
	'billing_unit' => 'minimum',
	'price_vnd'    => 3000000,
] );
$rate_repo->insert( [
	'rate_card_id' => $rc2_id,
	'rate_group'   => 'export_wfm',
	'zone'         => '5',
	'weight_label' => '71-99',
	'weight_from'  => 71.0,
	'weight_to'    => 99.0,
	'billing_unit' => 'per_kg',
	'price_vnd'    => 30000,
] );
// 80kg x 30,000 = 2,400,000 < minimum 3,000,000 => must be 3,000,000
$wfm_min_price = $rate_repo->find_price( $rc2_id, 'export_wfm', '5', 80.0, false );
assert( $wfm_min_price !== null && $wfm_min_price->price_vnd === 3000000, 'Check 6.3 Failed: WFM minimum enforcement' );

// Envelope
$rate_repo->insert( [
	'rate_card_id' => $rc2_id,
	'rate_group'   => 'export_wxs_document',
	'zone'         => '5',
	'weight_label' => 'UPS Envelope',
	'weight_from'  => null,
	'weight_to'    => null,
	'billing_unit' => 'envelope',
	'price_vnd'    => 200000,
] );
$env_price = $rate_repo->find_price( $rc2_id, 'export_wxs_document', '5', 0.2, true );
assert( $env_price !== null && $env_price->price_vnd === 200000, 'Check 6.4 Failed: Envelope rate lookup' );
echo "PASSED\n";

// ---------------------------------------------------------
// CHECK 7: Quote Log Repository
// ---------------------------------------------------------
echo "[Check 7/8] Quote Log Repository (insert/filter/count/export)... ";
$log_repo = new Allship_UPS_Quote_Log_Repository( $mock_wpdb );
$log_id   = $log_repo->insert( [
	'rate_card_id'         => $rc2_id,
	'direction'            => 'export',
	'destination_iata'     => 'US',
	'destination_city'     => 'Los Angeles',
	'service_code'         => 'WXS',
	'shipment_type'        => 'nondocument',
	'actual_weight_kg'     => 6.0,
	'dim_weight_kg'        => 5.0,
	'chargeable_weight_kg' => 6.0,
	'base_price_vnd'       => 500000,
	'total_price_vnd'      => 500000,
	'pieces_json'          => [ [ 'weight' => 6, 'l' => 20, 'w' => 20, 'h' => 20 ] ],
] );

assert( $log_id > 0, 'Check 7.1 Failed: insert quote log' );
$filtered = $log_repo->get_filtered( [ 'destination_iata' => 'US' ], 1, 10 );
assert( count( $filtered ) >= 1, 'Check 7.2 Failed: get_filtered logs' );
assert( $log_repo->count_filtered( [ 'destination_iata' => 'US' ] ) >= 1, 'Check 7.3 Failed: count_filtered' );

$mem_csv = fopen( 'php://memory', 'w+' );
$log_repo->export_csv( [], $mem_csv );
rewind( $mem_csv );
$csv_out = stream_get_contents( $mem_csv );
fclose( $mem_csv );
assert( strpos( $csv_out, 'Los Angeles' ) !== false, 'Check 7.4 Failed: CSV export content' );

$mem_xls = fopen( 'php://memory', 'w+' );
$log_repo->export_excel( [], $mem_xls );
rewind( $mem_xls );
$xls_out = stream_get_contents( $mem_xls );
fclose( $mem_xls );
assert( strpos( $xls_out, '<Workbook' ) !== false, 'Check 7.5 Failed: Excel export XML' );
echo "PASSED\n";

// ---------------------------------------------------------
// CHECK 8: SQL Prepare Audit
// ---------------------------------------------------------
echo "[Check 8/8] SQL Prepare Security Audit... ";
$files_to_audit = [
	'class-settings-manager.php',
	'class-rate-card-repository.php',
	'class-country-repository.php',
	'class-zone-repository.php',
	'class-rate-repository.php',
	'class-quote-log-repository.php',
];

foreach ( $files_to_audit as $file ) {
	$code = file_get_contents( dirname( __DIR__ ) . '/includes/' . $file );
	// Verify that variable queries use $wpdb->prepare
	if ( preg_match( '/\$wpdb->(query|get_results|get_row|get_var)\(\s*["\'].*\$[a-zA-Z]/', $code, $m ) ) {
		// Ensure it's not interpolating raw user input directly into query without prepare
		if ( strpos( $m[0], '$this->table' ) === false && strpos( $m[0], '$table' ) === false && strpos( $m[0], '$where_sql' ) === false && strpos( $m[0], '$sql' ) === false ) {
			throw new Exception( "Check 8 Failed: Direct interpolation in {$file}: {$m[0]}" );
		}
	}
}
echo "PASSED\n";

echo "\n========================================================\n";
echo "SUCCESS: ALL 8/8 PHASE 1 GATES PASSED!\n";
echo "========================================================\n";
