<?php
/**
 * Rate Repository for managing ups_rates table.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Rate_Repository {

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
		$this->table = $this->wpdb ? $this->wpdb->prefix . 'ups_rates' : '';
	}

	/**
	 * Find price for a given shipment specification.
	 *
	 * Evaluates flat, per_kg (with bracket matching and minimum pricing), and envelope rules.
	 *
	 * @param int    $rate_card_id Rate card ID.
	 * @param string $rate_group Internal rate group name (e.g. 'export_wxs_nondocument').
	 * @param string $zone Mapped zone string (e.g. '5', 'US5').
	 * @param float  $chargeable_weight Chargeable weight in kg.
	 * @param bool   $is_envelope Whether shipment is a UPS Document Envelope.
	 * @return object|null Result object containing price_vnd and metadata, or null if not found.
	 */
	public function find_price( $rate_card_id, $rate_group, $zone, $chargeable_weight, $is_envelope = false ) {
		$rate_card_id      = abs( (int) $rate_card_id );
		$rate_group        = function_exists( 'sanitize_key' ) ? sanitize_key( $rate_group ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $rate_group ) );
		$zone              = trim( (string) $zone );
		$chargeable_weight = (float) $chargeable_weight;

		if ( ! $rate_card_id || empty( $rate_group ) || empty( $zone ) || ! $this->wpdb || empty( $this->table ) ) {
			return null;
		}

		// 1. Envelope lookup
		if ( $is_envelope ) {
			$row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table}
					 WHERE rate_card_id = %d
					   AND rate_group = %s
					   AND zone = %s
					   AND ( billing_unit = 'envelope' OR weight_label = 'UPS Envelope' )
					 LIMIT 1",
					$rate_card_id,
					$rate_group,
					$zone
				)
			);

			if ( $row ) {
				$result               = new stdClass();
				$result->rate_id      = (int) $row->id;
				$result->rate_card_id = (int) $row->rate_card_id;
				$result->rate_group   = $row->rate_group;
				$result->zone         = $row->zone;
				$result->weight_label = $row->weight_label;
				$result->billing_unit = $row->billing_unit;
				$result->unit_rate    = (int) $row->price_vnd;
				$result->price_vnd    = (int) $row->price_vnd;
				return $result;
			}
		}

		// 2. Flat rate lookup (smallest bracket where weight_to >= chargeable_weight)
		$flat_row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE rate_card_id = %d
				   AND rate_group = %s
				   AND zone = %s
				   AND billing_unit = 'flat'
				   AND weight_to >= %f
				 ORDER BY weight_to ASC
				 LIMIT 1",
				$rate_card_id,
				$rate_group,
				$zone,
				$chargeable_weight
			)
		);

		if ( $flat_row ) {
			$result               = new stdClass();
			$result->rate_id      = (int) $flat_row->id;
			$result->rate_card_id = (int) $flat_row->rate_card_id;
			$result->rate_group   = $flat_row->rate_group;
			$result->zone         = $flat_row->zone;
			$result->weight_label = $flat_row->weight_label;
			$result->billing_unit = $flat_row->billing_unit;
			$result->unit_rate    = (int) $flat_row->price_vnd;
			$result->price_vnd    = (int) $flat_row->price_vnd;
			return $result;
		}

		// 3. Per-kg bracket lookup
		$per_kg_row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE rate_card_id = %d
				   AND rate_group = %s
				   AND zone = %s
				   AND billing_unit = 'per_kg'
				   AND weight_from <= %f
				   AND ( weight_to >= %f OR weight_to IS NULL )
				 ORDER BY weight_from DESC
				 LIMIT 1",
				$rate_card_id,
				$rate_group,
				$zone,
				$chargeable_weight,
				$chargeable_weight
			)
		);

		if ( $per_kg_row ) {
			$unit_rate   = (int) $per_kg_row->price_vnd;
			$total_price = (int) round( $unit_rate * $chargeable_weight );

			// Check for minimum charge row
			$min_row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table}
					 WHERE rate_card_id = %d
					   AND rate_group = %s
					   AND zone = %s
					   AND billing_unit = 'minimum'
					 LIMIT 1",
					$rate_card_id,
					$rate_group,
					$zone
				)
			);

			$min_price = $min_row ? (int) $min_row->price_vnd : null;
			if ( null !== $min_price && $min_price > $total_price ) {
				$total_price = $min_price;
			}

			$result               = new stdClass();
			$result->rate_id      = (int) $per_kg_row->id;
			$result->rate_card_id = (int) $per_kg_row->rate_card_id;
			$result->rate_group   = $per_kg_row->rate_group;
			$result->zone         = $per_kg_row->zone;
			$result->weight_label = $per_kg_row->weight_label;
			$result->billing_unit = $per_kg_row->billing_unit;
			$result->unit_rate    = $unit_rate;
			$result->min_price    = $min_price;
			$result->price_vnd    = $total_price;
			return $result;
		}

		// 4. Minimum-only fallback
		$min_only_row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE rate_card_id = %d
				   AND rate_group = %s
				   AND zone = %s
				   AND billing_unit = 'minimum'
				 LIMIT 1",
				$rate_card_id,
				$rate_group,
				$zone
			)
		);

		if ( $min_only_row ) {
			$result               = new stdClass();
			$result->rate_id      = (int) $min_only_row->id;
			$result->rate_card_id = (int) $min_only_row->rate_card_id;
			$result->rate_group   = $min_only_row->rate_group;
			$result->zone         = $min_only_row->zone;
			$result->weight_label = $min_only_row->weight_label;
			$result->billing_unit = $min_only_row->billing_unit;
			$result->unit_rate    = (int) $min_only_row->price_vnd;
			$result->price_vnd    = (int) $min_only_row->price_vnd;
			return $result;
		}

		return null;
	}

	/**
	 * Get rates for a rate card with optional query filters.
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

		$where  = [ 'rate_card_id = %d' ];
		$params = [ $rate_card_id ];

		if ( ! empty( $args['rate_group'] ) ) {
			$where[]  = 'rate_group = %s';
			$params[] = function_exists( 'sanitize_key' ) ? sanitize_key( $args['rate_group'] ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $args['rate_group'] ) );
		}

		if ( ! empty( $args['zone'] ) ) {
			$where[]  = 'zone = %s';
			$params[] = trim( (string) $args['zone'] );
		}

		if ( ! empty( $args['billing_unit'] ) ) {
			$where[]  = 'billing_unit = %s';
			$params[] = trim( (string) $args['billing_unit'] );
		}

		$where_sql = implode( ' AND ', $where );
		$limit_sql = '';
		if ( ! empty( $args['limit'] ) ) {
			$limit     = max( 1, (int) $args['limit'] );
			$offset    = ! empty( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
			$limit_sql = $this->wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		$sql = "SELECT * FROM {$this->table}
				WHERE {$where_sql}
				ORDER BY rate_group ASC, zone ASC, sort_order ASC, weight_from ASC" . $limit_sql;

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, $params )
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'format_row' ], $rows );
	}

	/**
	 * Insert a single rate row. Handles duplicate constraint by updating.
	 *
	 * @param array $data Rate row data.
	 * @return int Rate ID or 0 on failure.
	 */
	public function insert( array $data ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return 0;
		}

		$rate_card_id = abs( (int) ( isset( $data['rate_card_id'] ) ? $data['rate_card_id'] : 0 ) );
		$rate_group   = function_exists( 'sanitize_key' ) ? sanitize_key( isset( $data['rate_group'] ) ? $data['rate_group'] : '' ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) ( isset( $data['rate_group'] ) ? $data['rate_group'] : '' ) ) );
		$zone         = trim( (string) ( isset( $data['zone'] ) ? $data['zone'] : '' ) );
		$weight_label = trim( (string) ( isset( $data['weight_label'] ) ? $data['weight_label'] : '' ) );

		if ( ! $rate_card_id || empty( $rate_group ) || empty( $zone ) || empty( $weight_label ) ) {
			return 0;
		}

		$weight_from  = isset( $data['weight_from'] ) && '' !== $data['weight_from'] ? (float) $data['weight_from'] : null;
		$weight_to    = isset( $data['weight_to'] ) && '' !== $data['weight_to'] ? (float) $data['weight_to'] : null;
		$billing_unit = isset( $data['billing_unit'] ) && in_array( $data['billing_unit'], [ 'flat', 'per_kg', 'minimum', 'envelope' ], true ) ? $data['billing_unit'] : 'flat';
		$price_vnd    = abs( (int) ( isset( $data['price_vnd'] ) ? $data['price_vnd'] : 0 ) );
		$sort_order   = abs( (int) ( isset( $data['sort_order'] ) ? $data['sort_order'] : 0 ) );

		$sql = $this->wpdb->prepare(
			"INSERT INTO {$this->table} (rate_card_id, rate_group, zone, weight_label, weight_from, weight_to, billing_unit, price_vnd, sort_order)
			 VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %d)
			 ON DUPLICATE KEY UPDATE
			   weight_from = VALUES(weight_from),
			   weight_to = VALUES(weight_to),
			   billing_unit = VALUES(billing_unit),
			   price_vnd = VALUES(price_vnd),
			   sort_order = VALUES(sort_order)",
			$rate_card_id,
			$rate_group,
			$zone,
			$weight_label,
			null !== $weight_from ? (string) $weight_from : null,
			null !== $weight_to ? (string) $weight_to : null,
			$billing_unit,
			$price_vnd,
			$sort_order
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
				 WHERE rate_card_id = %d AND rate_group = %s AND zone = %s AND weight_label = %s LIMIT 1",
				$rate_card_id,
				$rate_group,
				$zone,
				$weight_label
			)
		);

		return $existing_id ? (int) $existing_id : 1;
	}

	/**
	 * Update an existing rate row.
	 *
	 * @param int   $id Rate ID.
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

		if ( isset( $data['weight_from'] ) ) {
			$fields['weight_from'] = '' !== $data['weight_from'] ? (float) $data['weight_from'] : null;
			$formats[]             = '%s';
		}
		if ( isset( $data['weight_to'] ) ) {
			$fields['weight_to'] = '' !== $data['weight_to'] ? (float) $data['weight_to'] : null;
			$formats[]           = '%s';
		}
		if ( isset( $data['billing_unit'] ) ) {
			$fields['billing_unit'] = trim( (string) $data['billing_unit'] );
			$formats[]              = '%s';
		}
		if ( isset( $data['price_vnd'] ) ) {
			$fields['price_vnd'] = abs( (int) $data['price_vnd'] );
			$formats[]           = '%d';
		}
		if ( isset( $data['sort_order'] ) ) {
			$fields['sort_order'] = abs( (int) $data['sort_order'] );
			$formats[]            = '%d';
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
	 * Delete a single rate row.
	 *
	 * @param int $id Rate row ID.
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
	 * Bulk insert or update multiple rate rows.
	 *
	 * @param array $rows Array of rate data.
	 * @return int Count of rows processed.
	 */
	public function bulk_insert( array $rows ) {
		if ( empty( $rows ) || ! $this->wpdb || empty( $this->table ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $rows as $row ) {
			$rate_card_id = abs( (int) ( isset( $row['rate_card_id'] ) ? $row['rate_card_id'] : 0 ) );
			$rate_group   = function_exists( 'sanitize_key' ) ? sanitize_key( isset( $row['rate_group'] ) ? $row['rate_group'] : '' ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) ( isset( $row['rate_group'] ) ? $row['rate_group'] : '' ) ) );
			$zone         = trim( (string) ( isset( $row['zone'] ) ? $row['zone'] : '' ) );
			$weight_label = trim( (string) ( isset( $row['weight_label'] ) ? $row['weight_label'] : '' ) );

			if ( ! $rate_card_id || empty( $rate_group ) || empty( $zone ) || empty( $weight_label ) ) {
				continue;
			}

			$weight_from  = isset( $row['weight_from'] ) && '' !== $row['weight_from'] ? (float) $row['weight_from'] : null;
			$weight_to    = isset( $row['weight_to'] ) && '' !== $row['weight_to'] ? (float) $row['weight_to'] : null;
			$billing_unit = isset( $row['billing_unit'] ) && in_array( $row['billing_unit'], [ 'flat', 'per_kg', 'minimum', 'envelope' ], true ) ? $row['billing_unit'] : 'flat';
			$price_vnd    = abs( (int) ( isset( $row['price_vnd'] ) ? $row['price_vnd'] : 0 ) );
			$sort_order   = abs( (int) ( isset( $row['sort_order'] ) ? $row['sort_order'] : 0 ) );

			$sql = $this->wpdb->prepare(
				"INSERT INTO {$this->table} (rate_card_id, rate_group, zone, weight_label, weight_from, weight_to, billing_unit, price_vnd, sort_order)
				 VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %d)
				 ON DUPLICATE KEY UPDATE
				   weight_from = VALUES(weight_from),
				   weight_to = VALUES(weight_to),
				   billing_unit = VALUES(billing_unit),
				   price_vnd = VALUES(price_vnd),
				   sort_order = VALUES(sort_order)",
				$rate_card_id,
				$rate_group,
				$zone,
				$weight_label,
				null !== $weight_from ? (string) $weight_from : null,
				null !== $weight_to ? (string) $weight_to : null,
				$billing_unit,
				$price_vnd,
				$sort_order
			);

			if ( false !== $this->wpdb->query( $sql ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Delete all rates associated with a rate card.
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
	 * Format raw row to typed object.
	 *
	 * @param object|null $row Raw database row.
	 * @return object|null
	 */
	private function format_row( $row ) {
		if ( ! $row || ! is_object( $row ) ) {
			return null;
		}

		$row->id           = (int) $row->id;
		$row->rate_card_id = (int) $row->rate_card_id;
		$row->price_vnd    = (int) $row->price_vnd;
		$row->sort_order   = (int) $row->sort_order;
		$row->weight_from  = null !== $row->weight_from ? (float) $row->weight_from : null;
		$row->weight_to    = null !== $row->weight_to ? (float) $row->weight_to : null;

		return $row;
	}
}
