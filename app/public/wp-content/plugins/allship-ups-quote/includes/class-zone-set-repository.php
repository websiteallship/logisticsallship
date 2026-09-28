<?php
/**
 * Zone Set Repository for managing ups_zone_sets table.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Zone_Set_Repository {

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
		$this->table = $this->wpdb ? $this->wpdb->prefix . 'ups_zone_sets' : '';
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
	 * Get all zone sets with usage count.
	 *
	 * @param array $args Optional query filters.
	 * @return array
	 */
	public function get_all( array $args = [] ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return [];
		}

		$table_rate_cards = $this->wpdb->prefix . 'ups_rate_cards';

		$sql = "SELECT zs.*, 
				       COALESCE(rc_stats.used_count, 0) AS used_count
				FROM {$this->table} zs
				LEFT JOIN (
					SELECT zone_set_id, COUNT(*) AS used_count
					FROM {$table_rate_cards}
					WHERE zone_set_id IS NOT NULL
					GROUP BY zone_set_id
				) rc_stats ON zs.id = rc_stats.zone_set_id
				ORDER BY zs.id DESC";

		$rows = $this->wpdb->get_results( $sql );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'format_row' ], $rows );
	}

	/**
	 * Find a zone set by ID.
	 *
	 * @param int $id Zone set ID.
	 * @return object|null
	 */
	public function find_by_id( $id ) {
		$id = $this->abs_int( $id );
		if ( ! $id || ! $this->wpdb || empty( $this->table ) ) {
			return null;
		}

		$table_rate_cards = $this->wpdb->prefix . 'ups_rate_cards';

		$sql = $this->wpdb->prepare(
			"SELECT zs.*, 
			        COALESCE(rc_stats.used_count, 0) AS used_count
			 FROM {$this->table} zs
			 LEFT JOIN (
				 SELECT zone_set_id, COUNT(*) AS used_count
				 FROM {$table_rate_cards}
				 WHERE zone_set_id IS NOT NULL
				 GROUP BY zone_set_id
			 ) rc_stats ON zs.id = rc_stats.zone_set_id
			 WHERE zs.id = %d
			 LIMIT 1",
			$id
		);

		$row = $this->wpdb->get_row( $sql );

		return $row ? $this->format_row( $row ) : null;
	}

	/**
	 * Get default or first available zone set.
	 *
	 * @return object|null
	 */
	public function get_default_or_first() {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row( "SELECT * FROM {$this->table} ORDER BY id ASC LIMIT 1" );

		return $row ? $this->format_row( $row ) : null;
	}

	/**
	 * Create a new zone set.
	 *
	 * @param array $data Zone set attributes.
	 * @return int Created zone set ID or 0 on failure.
	 */
	public function create( array $data ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return 0;
		}

		$name             = isset( $data['name'] ) ? $this->sanitize_text( $data['name'] ) : ( 'UPS Zone Set ' . date( 'Y-m-d' ) );
		$description      = ! empty( $data['description'] ) ? $this->sanitize_text( $data['description'] ) : null;
		$source_file_name = ! empty( $data['source_file_name'] ) ? $this->sanitize_text( $data['source_file_name'] ) : null;
		$source_file_hash = ! empty( $data['source_file_hash'] ) ? $this->sanitize_text( $data['source_file_hash'] ) : null;
		$record_count     = isset( $data['record_count'] ) ? $this->abs_int( $data['record_count'] ) : 0;
		$created_at       = ! empty( $data['created_at'] ) ? $data['created_at'] : ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) );
		$created_by       = isset( $data['created_by'] ) ? $this->abs_int( $data['created_by'] ) : ( function_exists( 'get_current_user_id' ) ? ( get_current_user_id() ?: null ) : null );

		$res = $this->wpdb->insert(
			$this->table,
			[
				'name'             => $name,
				'description'      => $description,
				'source_file_name' => $source_file_name,
				'source_file_hash' => $source_file_hash,
				'record_count'     => $record_count,
				'created_at'       => $created_at,
				'created_by'       => $created_by,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
		);

		return $res ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * Update an existing zone set.
	 *
	 * @param int   $id Zone set ID.
	 * @param array $data Fields to update.
	 * @return bool True on success, false on failure.
	 */
	public function update( $id, array $data ) {
		$id = $this->abs_int( $id );
		if ( ! $id || empty( $data ) || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		$fields  = [];
		$formats = [];

		if ( isset( $data['name'] ) ) {
			$fields['name'] = $this->sanitize_text( $data['name'] );
			$formats[]      = '%s';
		}
		if ( isset( $data['description'] ) ) {
			$fields['description'] = $this->sanitize_text( $data['description'] );
			$formats[]             = '%s';
		}
		if ( isset( $data['record_count'] ) ) {
			$fields['record_count'] = $this->abs_int( $data['record_count'] );
			$formats[]              = '%d';
		}
		if ( isset( $data['source_file_name'] ) ) {
			$fields['source_file_name'] = $this->sanitize_text( $data['source_file_name'] );
			$formats[]                  = '%s';
		}
		if ( isset( $data['source_file_hash'] ) ) {
			$fields['source_file_hash'] = $this->sanitize_text( $data['source_file_hash'] );
			$formats[]                  = '%s';
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
	 * Count rate cards referencing this zone set.
	 *
	 * @param int $zone_set_id Zone set ID.
	 * @return int
	 */
	public function count_rate_cards_using( $zone_set_id ) {
		$zone_set_id = $this->abs_int( $zone_set_id );
		if ( ! $zone_set_id || ! $this->wpdb ) {
			return 0;
		}

		$table_rate_cards = $this->wpdb->prefix . 'ups_rate_cards';
		$count            = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_rate_cards} WHERE zone_set_id = %d",
				$zone_set_id
			)
		);

		return (int) $count;
	}

	/**
	 * Update the record_count column for a zone set by counting ups_zone_maps.
	 *
	 * @param int $zone_set_id Zone set ID.
	 * @return int Actual record count.
	 */
	public function update_record_count( $zone_set_id ) {
		$zone_set_id = $this->abs_int( $zone_set_id );
		if ( ! $zone_set_id || ! $this->wpdb ) {
			return 0;
		}

		$table_maps = $this->wpdb->prefix . 'ups_zone_maps';
		$count      = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_maps} WHERE zone_set_id = %d",
				$zone_set_id
			)
		);

		$count = (int) $count;
		$this->update( $zone_set_id, [ 'record_count' => $count ] );

		return $count;
	}

	/**
	 * Delete a zone set. Refuses deletion if any rate card is currently using it.
	 *
	 * @param int  $id Zone set ID.
	 * @param bool $force Force delete even if referenced (default false).
	 * @return bool True on success, false if blocked or on DB failure.
	 */
	public function delete( $id, $force = false ) {
		$id = $this->abs_int( $id );
		if ( ! $id || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		if ( ! $force && $this->count_rate_cards_using( $id ) > 0 ) {
			return false;
		}

		// Cascade delete zone maps for this zone set
		$table_maps = $this->wpdb->prefix . 'ups_zone_maps';
		$this->wpdb->delete(
			$table_maps,
			[ 'zone_set_id' => $id ],
			[ '%d' ]
		);

		// Delete zone set
		$res = $this->wpdb->delete(
			$this->table,
			[ 'id' => $id ],
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
		$row->record_count = isset( $row->record_count ) ? (int) $row->record_count : 0;
		$row->used_count   = isset( $row->used_count ) ? (int) $row->used_count : 0;
		if ( isset( $row->created_by ) && null !== $row->created_by ) {
			$row->created_by = (int) $row->created_by;
		}

		return $row;
	}
}
