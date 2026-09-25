<?php
/**
 * Country Repository for managing ups_countries table.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Country_Repository {

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
		$this->table = $this->wpdb ? $this->wpdb->prefix . 'ups_countries' : '';
	}

	/**
	 * Find country by 2-letter IATA code.
	 *
	 * @param string $iata_code Country IATA code.
	 * @return object|null
	 */
	public function find_by_iata( $iata_code ) {
		$iata = strtoupper( trim( (string) $iata_code ) );
		if ( empty( $iata ) || ! $this->wpdb || empty( $this->table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE iata_code = %s LIMIT 1",
				$iata
			)
		);

		return $this->format_row( $row );
	}

	/**
	 * Alias for find_by_iata.
	 *
	 * @param string $iata_code Country IATA code.
	 * @return object|null
	 */
	public function get_by_iata( $iata_code ) {
		return $this->find_by_iata( $iata_code );
	}

	/**
	 * Retrieve all active countries ordered alphabetically.
	 *
	 * @return array
	 */
	public function get_all_active() {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return [];
		}

		$rows = $this->wpdb->get_results(
			"SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY country_name ASC"
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'format_row' ], $rows );
	}

	/**
	 * Retrieve all countries.
	 *
	 * @return array
	 */
	public function get_all() {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return [];
		}

		$rows = $this->wpdb->get_results(
			"SELECT * FROM {$this->table} ORDER BY country_name ASC"
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'format_row' ], $rows );
	}

	/**
	 * Search active countries by keyword (name, normalized name, or IATA code).
	 *
	 * @param string $keyword Search term.
	 * @param int    $limit Max records to return. Default 20.
	 * @return array
	 */
	public function search( $keyword, $limit = 20 ) {
		$keyword = trim( (string) $keyword );
		if ( empty( $keyword ) || ! $this->wpdb || empty( $this->table ) ) {
			return [];
		}

		$esc_like = method_exists( $this->wpdb, 'esc_like' ) ? $this->wpdb->esc_like( $keyword ) : addcslashes( $keyword, '_%\\' );
		$like     = '%' . $esc_like . '%';
		$limit    = max( 1, (int) $limit );

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE is_active = 1
				   AND ( country_name LIKE %s OR normalized_name LIKE %s OR iata_code LIKE %s )
				 ORDER BY
				   CASE
				     WHEN iata_code = %s THEN 1
				     WHEN country_name LIKE %s THEN 2
				     ELSE 3
				   END,
				   country_name ASC
				 LIMIT %d",
				$like,
				$like,
				$like,
				strtoupper( $keyword ),
				$esc_like . '%',
				$limit
			)
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'format_row' ], $rows );
	}

	/**
	 * Insert a new country record.
	 *
	 * @param array $data Country data.
	 * @return int Inserted ID or 0 on failure.
	 */
	public function insert( array $data ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return 0;
		}

		$iata_code    = strtoupper( trim( isset( $data['iata_code'] ) ? $data['iata_code'] : '' ) );
		$country_name = trim( isset( $data['country_name'] ) ? $data['country_name'] : '' );

		if ( empty( $iata_code ) || empty( $country_name ) ) {
			return 0;
		}

		$normalized_name = isset( $data['normalized_name'] ) ? trim( $data['normalized_name'] ) : trim( str_replace( '*', '', $country_name ) );
		$is_us_override  = isset( $data['is_us_override'] ) ? (int) $data['is_us_override'] : ( 'US' === $iata_code ? 1 : 0 );
		$has_extended    = isset( $data['has_extended_area_note'] ) ? (int) $data['has_extended_area_note'] : ( strpos( $country_name, '*' ) !== false ? 1 : 0 );
		$is_active       = isset( $data['is_active'] ) ? (int) $data['is_active'] : 1;

		$inserted = $this->wpdb->insert(
			$this->table,
			[
				'iata_code'              => $iata_code,
				'country_name'           => $country_name,
				'normalized_name'        => $normalized_name,
				'is_us_override'         => $is_us_override,
				'has_extended_area_note' => $has_extended,
				'is_active'              => $is_active,
			],
			[ '%s', '%s', '%s', '%d', '%d', '%d' ]
		);

		return false !== $inserted ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * Update an existing country.
	 *
	 * @param int   $id Country ID.
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

		if ( isset( $data['iata_code'] ) ) {
			$fields['iata_code'] = strtoupper( trim( $data['iata_code'] ) );
			$formats[]           = '%s';
		}
		if ( isset( $data['country_name'] ) ) {
			$country_name           = trim( $data['country_name'] );
			$fields['country_name'] = $country_name;
			$formats[]              = '%s';
			if ( ! isset( $data['has_extended_area_note'] ) ) {
				$fields['has_extended_area_note'] = strpos( $country_name, '*' ) !== false ? 1 : 0;
				$formats[]                        = '%d';
			}
			if ( ! isset( $data['normalized_name'] ) ) {
				$fields['normalized_name'] = trim( str_replace( '*', '', $country_name ) );
				$formats[]                 = '%s';
			}
		}
		if ( isset( $data['normalized_name'] ) ) {
			$fields['normalized_name'] = trim( $data['normalized_name'] );
			$formats[]                 = '%s';
		}
		if ( isset( $data['is_us_override'] ) ) {
			$fields['is_us_override'] = (int) $data['is_us_override'];
			$formats[]                = '%d';
		}
		if ( isset( $data['has_extended_area_note'] ) ) {
			$fields['has_extended_area_note'] = (int) $data['has_extended_area_note'];
			$formats[]                        = '%d';
		}
		if ( isset( $data['is_active'] ) ) {
			$fields['is_active'] = (int) $data['is_active'];
			$formats[]           = '%d';
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
	 * Toggle active status of a country.
	 *
	 * @param int       $id Country ID.
	 * @param bool|null $is_active Specific status to set, or null to invert.
	 * @return bool True on success, false on failure.
	 */
	public function toggle_active( $id, $is_active = null ) {
		$id = abs( (int) $id );
		if ( ! $id || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		if ( null === $is_active ) {
			$current = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT is_active FROM {$this->table} WHERE id = %d",
					$id
				)
			);
			$new_val = $current ? 0 : 1;
		} else {
			$new_val = $is_active ? 1 : 0;
		}

		$res = $this->wpdb->update(
			$this->table,
			[ 'is_active' => $new_val ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);

		return false !== $res;
	}

	/**
	 * Bulk upsert countries (insert or update on duplicate iata_code).
	 *
	 * @param array $rows Array of country data rows.
	 * @return int Number of rows processed successfully.
	 */
	public function bulk_upsert( array $rows ) {
		if ( empty( $rows ) || ! $this->wpdb || empty( $this->table ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $rows as $row ) {
			$iata = strtoupper( trim( isset( $row['iata_code'] ) ? $row['iata_code'] : '' ) );
			if ( empty( $iata ) || 'ZZ' === $iata ) {
				continue;
			}

			$name = trim( isset( $row['country_name'] ) ? $row['country_name'] : '' );
			$norm = isset( $row['normalized_name'] ) ? trim( $row['normalized_name'] ) : trim( str_replace( '*', '', $name ) );
			$us   = isset( $row['is_us_override'] ) ? (int) $row['is_us_override'] : ( 'US' === $iata ? 1 : 0 );
			$ext  = isset( $row['has_extended_area_note'] ) ? (int) $row['has_extended_area_note'] : ( strpos( $name, '*' ) !== false ? 1 : 0 );
			$act  = isset( $row['is_active'] ) ? (int) $row['is_active'] : 1;

			$sql = $this->wpdb->prepare(
				"INSERT INTO {$this->table} (iata_code, country_name, normalized_name, is_us_override, has_extended_area_note, is_active)
				 VALUES (%s, %s, %s, %d, %d, %d)
				 ON DUPLICATE KEY UPDATE
				   country_name = VALUES(country_name),
				   normalized_name = VALUES(normalized_name),
				   is_us_override = VALUES(is_us_override),
				   has_extended_area_note = VALUES(has_extended_area_note),
				   is_active = VALUES(is_active)",
				$iata,
				$name,
				$norm,
				$us,
				$ext,
				$act
			);

			if ( false !== $this->wpdb->query( $sql ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Format raw row to typed object.
	 *
	 * @param object|null $row Database row.
	 * @return object|null
	 */
	private function format_row( $row ) {
		if ( ! $row || ! is_object( $row ) ) {
			return null;
		}

		$row->id                     = (int) $row->id;
		$row->is_us_override         = (int) $row->is_us_override;
		$row->has_extended_area_note = (int) $row->has_extended_area_note;
		$row->is_active              = (int) $row->is_active;

		return $row;
	}
}
