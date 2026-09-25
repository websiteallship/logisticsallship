<?php
/**
 * Zone Repository for managing ups_zone_maps table.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Zone_Repository {

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
		$this->table = $this->wpdb ? $this->wpdb->prefix . 'ups_zone_maps' : '';
	}

	/**
	 * Find mapped zone string for a rate card, country, direction, and service.
	 *
	 * @param int    $rate_card_id Rate card ID.
	 * @param int    $country_id Country ID.
	 * @param string $direction 'export' or 'import'.
	 * @param string $service_code Service code (e.g. 'WXS', 'XPD', etc.).
	 * @return string|null Zone code (e.g. '5') or null if not found.
	 */
	public function find_zone( $rate_card_id, $country_id, $direction, $service_code ) {
		$rate_card_id = abs( (int) $rate_card_id );
		$country_id   = abs( (int) $country_id );
		$direction    = strtolower( trim( (string) $direction ) );
		$service_code = strtoupper( trim( (string) $service_code ) );

		if ( ! $rate_card_id || ! $country_id || empty( $direction) || empty( $service_code ) || ! $this->wpdb || empty( $this->table ) ) {
			return null;
		}

		$zone = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT zone FROM {$this->table}
				 WHERE rate_card_id = %d
				   AND country_id = %d
				   AND direction = %s
				   AND service_code = %s
				 LIMIT 1",
				$rate_card_id,
				$country_id,
				$direction,
				$service_code
			)
		);

		return null !== $zone ? (string) $zone : null;
	}

	/**
	 * Get zone records for a specific rate card with optional filters.
	 *
	 * @param int   $rate_card_id Rate card ID.
	 * @param array $args Optional query filters.
	 * @return array
	 */
	public function get_by_rate_card( $rate_card_id, array $args = [] ) {
		$rate_card_id = abs( (int) $rate_card_id );
		if ( ! $rate_card_id || ! $this->wpdb || empty( $this->table ) ) {
			return [];
		}

		$table_countries = $this->wpdb->prefix . 'ups_countries';
		$where           = [ 'zm.rate_card_id = %d' ];
		$params          = [ $rate_card_id ];

		if ( ! empty( $args['direction'] ) ) {
			$where[]  = 'zm.direction = %s';
			$params[] = strtolower( trim( (string) $args['direction'] ) );
		}

		if ( ! empty( $args['service_code'] ) ) {
			$where[]  = 'zm.service_code = %s';
			$params[] = strtoupper( trim( (string) $args['service_code'] ) );
		}

		if ( ! empty( $args['country_id'] ) ) {
			$where[]  = 'zm.country_id = %d';
			$params[] = abs( (int) $args['country_id'] );
		}

		if ( isset( $args['is_available'] ) && '' !== $args['is_available'] ) {
			$where[]  = 'zm.is_available = %d';
			$params[] = $args['is_available'] ? 1 : 0;
		}

		$where_sql = implode( ' AND ', $where );
		$limit_sql = '';
		if ( ! empty( $args['limit'] ) ) {
			$limit     = max( 1, (int) $args['limit'] );
			$offset    = ! empty( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
			$limit_sql = $this->wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		$sql = "SELECT zm.*, c.iata_code, c.country_name, c.is_us_override
				FROM {$this->table} zm
				LEFT JOIN {$table_countries} c ON zm.country_id = c.id
				WHERE {$where_sql}
				ORDER BY zm.country_id ASC, zm.service_code ASC" . $limit_sql;

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, $params )
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'format_row' ], $rows );
	}

	/**
	 * Insert a zone mapping record. Updates existing record if duplicate constraint matches.
	 *
	 * @param array $data Record fields.
	 * @return int Record ID or 0 on failure.
	 */
	public function insert( array $data ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return 0;
		}

		$rate_card_id = abs( (int) ( isset( $data['rate_card_id'] ) ? $data['rate_card_id'] : 0 ) );
		$country_id   = abs( (int) ( isset( $data['country_id'] ) ? $data['country_id'] : 0 ) );
		$direction    = strtolower( trim( (string) ( isset( $data['direction'] ) ? $data['direction'] : 'export' ) ) );
		$service_code = strtoupper( trim( (string) ( isset( $data['service_code'] ) ? $data['service_code'] : '' ) ) );

		if ( ! $rate_card_id || ! $country_id || empty( $direction ) || empty( $service_code ) ) {
			return 0;
		}

		$service_type = isset( $data['service_type'] ) ? trim( (string) $data['service_type'] ) : null;
		$zone         = isset( $data['zone'] ) ? (string) $data['zone'] : null;

		if ( isset( $data['is_available'] ) ) {
			$is_available = $data['is_available'] ? 1 : 0;
		} else {
			$is_available = ( null !== $zone && '' !== $zone && '0' !== $zone && (int) $zone > 0 ) ? 1 : 0;
		}

		$sql = $this->wpdb->prepare(
			"INSERT INTO {$this->table} (rate_card_id, country_id, direction, service_code, service_type, zone, is_available)
			 VALUES (%d, %d, %s, %s, %s, %s, %d)
			 ON DUPLICATE KEY UPDATE
			   service_type = VALUES(service_type),
			   zone = VALUES(zone),
			   is_available = VALUES(is_available)",
			$rate_card_id,
			$country_id,
			$direction,
			$service_code,
			$service_type,
			$zone,
			$is_available
		);

		$res = $this->wpdb->query( $sql );

		if ( false === $res ) {
			return 0;
		}

		if ( ! empty( $this->wpdb->insert_id ) ) {
			return (int) $this->wpdb->insert_id;
		}

		$existing_id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->table}
				 WHERE rate_card_id = %d AND country_id = %d AND direction = %s AND service_code = %s LIMIT 1",
				$rate_card_id,
				$country_id,
				$direction,
				$service_code
			)
		);

		return $existing_id ? (int) $existing_id : 1;
	}

	/**
	 * Update an existing zone mapping record.
	 *
	 * @param int   $id Record ID.
	 * @param array $data Updated fields.
	 * @return bool True on success, false on failure.
	 */
	public function update( $id, array $data ) {
		$id = abs( (int) $id );
		if ( ! $id || empty( $data ) || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		$fields  = [];
		$formats = [];

		if ( isset( $data['service_type'] ) ) {
			$fields['service_type'] = trim( (string) $data['service_type'] );
			$formats[]              = '%s';
		}
		if ( isset( $data['zone'] ) ) {
			$fields['zone'] = (string) $data['zone'];
			$formats[]      = '%s';
		}
		if ( isset( $data['is_available'] ) ) {
			$fields['is_available'] = $data['is_available'] ? 1 : 0;
			$formats[]              = '%d';
		}

		if ( empty( $fields ) ) {
			return false;
		}

		$res = $this->wpdb->update(
			$this->table,
			$fields,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);

		return false !== $res;
	}

	/**
	 * Delete a single zone mapping by ID.
	 *
	 * @param int $id Record ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $id ) {
		$id = abs( (int) $id );
		if ( ! $id || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		$res = $this->wpdb->delete(
			$this->table,
			[ 'id' => $id ],
			[ '%d' ]
		);

		return false !== $res;
	}

	/**
	 * Bulk insert/upsert zone records.
	 *
	 * @param array $rows Array of zone mapping records.
	 * @return int Count of rows inserted or updated.
	 */
	public function bulk_insert( array $rows ) {
		if ( empty( $rows ) || ! $this->wpdb || empty( $this->table ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $rows as $row ) {
			$rate_card_id = abs( (int) ( isset( $row['rate_card_id'] ) ? $row['rate_card_id'] : 0 ) );
			$country_id   = abs( (int) ( isset( $row['country_id'] ) ? $row['country_id'] : 0 ) );
			$direction    = strtolower( trim( (string) ( isset( $row['direction'] ) ? $row['direction'] : 'export' ) ) );
			$service_code = strtoupper( trim( (string) ( isset( $row['service_code'] ) ? $row['service_code'] : '' ) ) );

			if ( ! $rate_card_id || ! $country_id || empty( $direction ) || empty( $service_code ) ) {
				continue;
			}

			$service_type = isset( $row['service_type'] ) ? trim( (string) $row['service_type'] ) : null;
			$zone         = isset( $row['zone'] ) ? (string) $row['zone'] : null;

			if ( isset( $row['is_available'] ) ) {
				$is_available = $row['is_available'] ? 1 : 0;
			} else {
				$is_available = ( null !== $zone && '' !== $zone && '0' !== $zone && (int) $zone > 0 ) ? 1 : 0;
			}

			$sql = $this->wpdb->prepare(
				"INSERT INTO {$this->table} (rate_card_id, country_id, direction, service_code, service_type, zone, is_available)
				 VALUES (%d, %d, %s, %s, %s, %s, %d)
				 ON DUPLICATE KEY UPDATE
				   service_type = VALUES(service_type),
				   zone = VALUES(zone),
				   is_available = VALUES(is_available)",
				$rate_card_id,
				$country_id,
				$direction,
				$service_code,
				$service_type,
				$zone,
				$is_available
			);

			if ( false !== $this->wpdb->query( $sql ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Delete all zone mappings for a rate card.
	 *
	 * @param int $rate_card_id Rate card ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_by_rate_card( $rate_card_id ) {
		$rate_card_id = abs( (int) $rate_card_id );
		if ( ! $rate_card_id || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		$res = $this->wpdb->delete(
			$this->table,
			[ 'rate_card_id' => $rate_card_id ],
			[ '%d' ]
		);

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

		$row->id           = (int) $row->id;
		$row->rate_card_id = (int) $row->rate_card_id;
		$row->country_id   = (int) $row->country_id;
		$row->is_available = (int) $row->is_available;
		if ( isset( $row->is_us_override ) ) {
			$row->is_us_override = (int) $row->is_us_override;
		}

		return $row;
	}
}
