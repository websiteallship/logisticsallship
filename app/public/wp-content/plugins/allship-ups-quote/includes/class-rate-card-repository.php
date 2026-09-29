<?php
/**
 * Rate Card Repository for managing ups_rate_cards table.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Rate_Card_Repository {

	/**
	 * Database instance.
	 *
	 * @var wpdb|null
	 */
	private $wpdb;

	/**
	 * Table name with prefix.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $wpdb Optional database object.
	 */
	public function __construct( $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb  = $wpdb;
		$this->table = $this->wpdb ? $this->wpdb->prefix . 'ups_rate_cards' : '';
	}

	/**
	 * Sanitize text field.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function sanitize_text( $text ) {
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $text ) : trim( (string) $text );
	}

	/**
	 * Cast to positive int.
	 *
	 * @param mixed $val Value.
	 * @return int
	 */
	private function abs_int( $val ) {
		return function_exists( 'absint' ) ? absint( $val ) : abs( (int) $val );
	}

	/**
	 * Create a new rate card.
	 *
	 * @param array $data Rate card attributes.
	 * @return int Created rate card ID or 0 on failure.
	 */
	public function create( array $data ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return 0;
		}

		$name                 = isset( $data['name'] ) ? $this->sanitize_text( $data['name'] ) : 'Untitled Rate Card';
		$zone_set_id          = ! empty( $data['zone_set_id'] ) ? $this->abs_int( $data['zone_set_id'] ) : null;
		$market_code          = isset( $data['market_code'] ) ? $this->sanitize_text( $data['market_code'] ) : 'VN';
		$valid_from           = ! empty( $data['valid_from'] ) ? $this->sanitize_text( $data['valid_from'] ) : null;
		$source_file_name     = ! empty( $data['source_file_name'] ) ? $this->sanitize_text( $data['source_file_name'] ) : null;
		$source_file_hash     = ! empty( $data['source_file_hash'] ) ? $this->sanitize_text( $data['source_file_hash'] ) : null;
		$status               = isset( $data['status'] ) && in_array( $data['status'], [ 'draft', 'active', 'archived' ], true ) ? $data['status'] : 'draft';
		$json_func            = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
		$enabled_directions   = isset( $data['enabled_directions'] ) ? ( is_array( $data['enabled_directions'] ) ? $json_func( $data['enabled_directions'] ) : (string) $data['enabled_directions'] ) : $json_func( [ 'export' ] );
		$disabled_rate_groups = isset( $data['disabled_rate_groups'] ) ? ( is_array( $data['disabled_rate_groups'] ) ? $json_func( $data['disabled_rate_groups'] ) : (string) $data['disabled_rate_groups'] ) : $json_func( [] );
		$imported_at          = ! empty( $data['imported_at'] ) ? $data['imported_at'] : ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) );
		$created_by           = isset( $data['created_by'] ) ? $this->abs_int( $data['created_by'] ) : ( function_exists( 'get_current_user_id' ) ? ( get_current_user_id() ?: null ) : null );


		$fields = [
			'name'                 => $name,
			'market_code'          => $market_code,
			'valid_from'           => $valid_from,
			'source_file_name'     => $source_file_name,
			'source_file_hash'     => $source_file_hash,
			'status'               => $status,
			'enabled_directions'   => $enabled_directions,
			'disabled_rate_groups' => $disabled_rate_groups,
			'imported_at'          => $imported_at,
			'activated_at'         => ( 'active' === $status ) ? $imported_at : null,
			'created_by'           => $created_by,
		];
		$formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ];

		if ( null !== $zone_set_id ) {
			$fields['zone_set_id'] = $zone_set_id;
			$formats[]             = '%d';
		}

		$inserted = $this->wpdb->insert(
			$this->table,
			$fields,
			$formats
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Retrieve a rate card by ID.
	 *
	 * @param int $id Rate card ID.
	 * @return object|null
	 */
	public function get( $id ) {
		$id = $this->abs_int( $id );
		if ( ! $id || ! $this->wpdb || empty( $this->table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
				$id
			)
		);

		return $this->format_row( $row );
	}

	/**
	 * Safe helper for $wpdb->get_col with fallback to get_results.
	 *
	 * @param string $query SQL query.
	 * @return array
	 */
	private function db_get_col( $query ) {
		if ( ! $this->wpdb ) {
			return [];
		}

		if ( method_exists( $this->wpdb, 'get_col' ) ) {
			$col = $this->wpdb->get_col( $query );
			return is_array( $col ) ? array_values( array_filter( $col ) ) : [];
		}

		if ( method_exists( $this->wpdb, 'get_results' ) ) {
			$rows = $this->wpdb->get_results( $query );
			if ( is_array( $rows ) ) {
				$col = [];
				foreach ( $rows as $r ) {
					if ( is_object( $r ) ) {
						$arr   = get_object_vars( $r );
						$col[] = reset( $arr );
					} elseif ( is_array( $r ) ) {
						$col[] = reset( $r );
					} else {
						$col[] = $r;
					}
				}
				return array_values( array_filter( $col ) );
			}
		}

		return [];
	}

	/**
	 * Retrieve distinct rate groups contained in a rate card.
	 *
	 * @param int $id Rate card ID.
	 * @return array
	 */
	public function get_rate_groups_for_card( $id ) {
		$id = $this->abs_int( $id );
		if ( ! $id || ! $this->wpdb ) {
			return [];
		}

		$table_rates = $this->wpdb->prefix . 'ups_rates';
		$sql         = $this->wpdb->prepare(
			"SELECT DISTINCT rate_group FROM {$table_rates} WHERE rate_card_id = %d",
			$id
		);

		return $this->db_get_col( $sql );
	}

	/**
	 * Get IDs of other active rate cards that conflict with this card
	 * (i.e. share any rate_group, distinguishing service and import/export).
	 *
	 * @param int $id Rate card ID.
	 * @return array List of conflicting active card IDs.
	 */
	public function get_conflicting_active_cards( $id ) {
		$id = $this->abs_int( $id );
		if ( ! $id || ! $this->wpdb || empty( $this->table ) ) {
			return [];
		}

		$card_groups  = $this->get_rate_groups_for_card( $id );
		$active_cards = $this->get_all_active();

		if ( empty( $active_cards ) ) {
			return [];
		}

		$conflicts = [];
		foreach ( $active_cards as $active_card ) {
			$other_id = (int) $active_card->id;
			if ( $other_id === $id ) {
				continue;
			}

			$other_groups = $this->get_rate_groups_for_card( $other_id );

			if ( ! empty( $card_groups ) && ! empty( $other_groups ) ) {
				// Conflict only if they share rate groups (same service & direction)
				if ( ! empty( array_intersect( $card_groups, $other_groups ) ) ) {
					$conflicts[] = $other_id;
				}
			} elseif ( empty( $card_groups ) && empty( $other_groups ) ) {
				// Both are unconfigured/dummy cards without rates: conflict
				$conflicts[] = $other_id;
			}
		}

		return array_values( array_unique( $conflicts ) );
	}

	/**
	 * Retrieve active rate card.
	 * If rate_group is specified, returns the active card providing that service & direction.
	 * Otherwise returns the most recently activated active rate card.
	 *
	 * @param string|null $rate_group Optional rate group identifier.
	 * @return object|null
	 */
	public function get_active( $rate_group = null ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return null;
		}

		if ( ! empty( $rate_group ) ) {
			return $this->get_active_for_rate_group( $rate_group );
		}

		$row = $this->wpdb->get_row(
			"SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY activated_at DESC, id DESC LIMIT 1"
		);

		return $this->format_row( $row );
	}

	/**
	 * Retrieve active rate card ID.
	 *
	 * @param string|null $rate_group Optional rate group identifier.
	 * @return int
	 */
	public function get_active_id( $rate_group = null ) {
		$active = $this->get_active( $rate_group );
		return $active ? (int) $active->id : 0;
	}

	/**
	 * Retrieve active rate card providing a specific rate group.
	 *
	 * @param string $rate_group Canonical rate group (e.g. 'export_wxs_nondocument', 'import_xpd').
	 * @return object|null
	 */
	public function get_active_for_rate_group( $rate_group ) {
		$rate_group = function_exists( 'sanitize_key' ) ? sanitize_key( $rate_group ) : trim( (string) $rate_group );
		if ( empty( $rate_group ) || ! $this->wpdb || empty( $this->table ) ) {
			return null;
		}

		$table_rates = $this->wpdb->prefix . 'ups_rates';
		$row         = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT rc.* 
				 FROM {$this->table} rc
				 INNER JOIN {$table_rates} r ON rc.id = r.rate_card_id
				 WHERE rc.status = 'active'
				   AND r.rate_group = %s
				 ORDER BY rc.activated_at DESC, rc.id DESC
				 LIMIT 1",
				$rate_group
			)
		);

		return $this->format_row( $row );
	}

	/**
	 * Retrieve active rate card ID for a specific rate group.
	 *
	 * @param string $rate_group Canonical rate group.
	 * @return int
	 */
	public function get_active_id_for_rate_group( $rate_group ) {
		$card = $this->get_active_for_rate_group( $rate_group );
		return $card ? (int) $card->id : 0;
	}

	/**
	 * Retrieve active rate card for a given service code, direction and shipment type.
	 *
	 * @param string $service_code Service code ('WXS', 'XPD', 'WXP', etc.).
	 * @param string $direction 'export' or 'import'.
	 * @param string $shipment_type 'nondocument' or 'document'.
	 * @return object|null
	 */
	public function get_active_for_service( $service_code, $direction = 'export', $shipment_type = 'nondocument' ) {
		$dir = strtolower( trim( (string) $direction ) );
		$svc = strtolower( trim( (string) $service_code ) );
		$typ = strtolower( trim( (string) $shipment_type ) );

		if ( in_array( $svc, [ 'wxs', 'exw', 'xpr' ], true ) ) {
			$sub = ( 'document' === $typ || 'doc' === $typ ) ? 'document' : 'nondocument';
			$rate_group = sprintf( '%s_%s_%s', $dir, $svc, $sub );
		} else {
			$rate_group = sprintf( '%s_%s', $dir, $svc );
		}

		return $this->get_active_for_rate_group( $rate_group );
	}

	/**
	 * Retrieve all currently active rate cards.
	 *
	 * @return array
	 */
	public function get_all_active() {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return [];
		}

		$rows = $this->wpdb->get_results(
			"SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY activated_at DESC, id DESC"
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'format_row' ], $rows );
	}

	/**
	 * Map of active rate groups to their active rate card IDs.
	 *
	 * @return array Array in format [ 'export_xpd' => 1, 'export_wxp' => 2, ... ]
	 */
	public function get_active_rate_groups_map() {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return [];
		}

		$table_rates = $this->wpdb->prefix . 'ups_rates';
		$rows        = $this->wpdb->get_results(
			"SELECT r.rate_group, rc.id as rate_card_id
			 FROM {$this->table} rc
			 INNER JOIN {$table_rates} r ON rc.id = r.rate_card_id
			 WHERE rc.status = 'active'
			 GROUP BY r.rate_group, rc.id
			 ORDER BY rc.activated_at DESC, rc.id DESC"
		);

		$map = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! isset( $map[ $row->rate_group ] ) ) {
					$map[ $row->rate_group ] = (int) $row->rate_card_id;
				}
			}
		}

		return $map;
	}

	/**
	 * Retrieve all rate cards ordered by imported_at DESC.
	 *
	 * @return array
	 */
	public function get_all() {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return [];
		}

		$rows = $this->wpdb->get_results(
			"SELECT * FROM {$this->table} ORDER BY imported_at DESC, id DESC"
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'format_row' ], $rows );
	}

	/**
	 * Activate a rate card.
	 * Business rule: Multiple cards can be active simultaneously, but only ONE card
	 * per service & direction (rate_group). Automatically archives only conflicting cards.
	 *
	 * @param int $id Rate card ID.
	 * @return bool True on success, false on failure.
	 */
	public function activate( $id ) {
		$id = $this->abs_int( $id );
		if ( ! $id || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		// 1. Find and archive other active cards that conflict on service & direction (rate_group)
		$conflicts = $this->get_conflicting_active_cards( $id );
		foreach ( $conflicts as $conflict_id ) {
			$this->wpdb->update(
				$this->table,
				[ 'status' => 'archived' ],
				[ 'id' => $conflict_id ],
				[ '%s' ],
				[ '%d' ]
			);
		}

		// 2. Activate target card
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

		$res = $this->wpdb->update(
			$this->table,
			[
				'status'       => 'active',
				'activated_at' => $now,
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( false !== $res ) {
			if ( function_exists( 'do_action' ) ) {
				do_action( 'allship_ups_rate_card_activated', $id, $conflicts );
			}
			return true;
		}

		return false;
	}

	/**
	 * Archive a rate card.
	 *
	 * @param int $id Rate card ID.
	 * @return bool True on success, false on failure.
	 */
	public function archive( $id ) {
		$id = $this->abs_int( $id );
		if ( ! $id || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		$res = $this->wpdb->update(
			$this->table,
			[ 'status' => 'archived' ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);

		return false !== $res;
	}

	/**
	 * Delete a rate card and cascade-delete its rates and zone mappings.
	 *
	 * @param int $id Rate card ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $id ) {
		$id = $this->abs_int( $id );
		if ( ! $id || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		$table_rates = $this->wpdb->prefix . 'ups_rates';
		$table_zones = $this->wpdb->prefix . 'ups_zone_maps';

		$this->wpdb->delete( $table_rates, [ 'rate_card_id' => $id ], [ '%d' ] );
		$this->wpdb->delete( $table_zones, [ 'rate_card_id' => $id ], [ '%d' ] );

		$res = $this->wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );

		return false !== $res;
	}

	/**
	 * Rename a rate card.
	 *
	 * @param int    $id Rate card ID.
	 * @param string $name New name.
	 * @return bool True on success, false on failure.
	 */
	public function update_name( $id, $name ) {
		$id   = $this->abs_int( $id );
		$name = $this->sanitize_text( $name );
		if ( ! $id || empty( $name ) || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		$res = $this->wpdb->update(
			$this->table,
			[ 'name' => $name ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);

		return false !== $res;
	}

	/**
	 * Update enabled directions for a rate card.
	 *
	 * @param int   $id Rate card ID.
	 * @param array $directions Enabled directions.
	 * @return bool True on success, false on failure.
	 */
	public function update_directions( $id, array $directions ) {
		$id = $this->abs_int( $id );
		if ( ! $id || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		$allowed = [ 'export', 'import' ];
		$clean   = array_values( array_intersect( $directions, $allowed ) );
		if ( empty( $clean ) ) {
			$clean = [ 'export' ];
		}

		$json_func = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
		$json      = $json_func( $clean );

		$res = $this->wpdb->update(
			$this->table,
			[ 'enabled_directions' => $json ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);

		return false !== $res;
	}

	/**
	 * Update disabled rate groups for a rate card.
	 *
	 * @param int   $id Rate card ID.
	 * @param array $groups Disabled rate groups.
	 * @return bool True on success, false on failure.
	 */
	public function update_disabled_groups( $id, array $groups ) {
		$id = $this->abs_int( $id );
		if ( ! $id || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		$clean = array_values( array_unique( array_filter( array_map( function_exists( 'sanitize_key' ) ? 'sanitize_key' : 'trim', $groups ) ) ) );

		$json_func = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
		$json      = $json_func( $clean );

		$res = $this->wpdb->update(
			$this->table,
			[ 'disabled_rate_groups' => $json ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( false !== $res && function_exists( 'do_action' ) ) {
			do_action( 'allship_ups_rate_groups_toggled', $id, $clean );
		}

		return false !== $res;
	}

	/**
	 * Format database row into typed object.
	 *
	 * @param object|null $row Database row.
	 * @return object|null
	 */
	private function format_row( $row ) {
		if ( ! $row || ! is_object( $row ) ) {
			return null;
		}

		$row->id = (int) $row->id;
		$row->zone_set_id = isset( $row->zone_set_id ) && null !== $row->zone_set_id ? (int) $row->zone_set_id : null;
		if ( isset( $row->created_by ) && null !== $row->created_by ) {
			$row->created_by = (int) $row->created_by;
		}

		$row->enabled_directions_array = ! empty( $row->enabled_directions ) ? json_decode( $row->enabled_directions, true ) : [ 'export' ];
		if ( ! is_array( $row->enabled_directions_array ) ) {
			$row->enabled_directions_array = [ 'export' ];
		}

		$row->disabled_rate_groups_array = ! empty( $row->disabled_rate_groups ) ? json_decode( $row->disabled_rate_groups, true ) : [];
		if ( ! is_array( $row->disabled_rate_groups_array ) ) {
			$row->disabled_rate_groups_array = [];
		}

		return $row;
	}

	/**
	 * Find rate card by ID (alias for get).
	 *
	 * @param int $id Rate card ID.
	 * @return object|null
	 */
	public function find_by_id( $id ) {
		return $this->get( $id );
	}
}
