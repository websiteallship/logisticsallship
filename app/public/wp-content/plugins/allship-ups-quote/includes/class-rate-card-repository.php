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

		// Business rule: only 1 card active at any time.
		if ( 'active' === $status ) {
			$this->wpdb->update(
				$this->table,
				[ 'status' => 'archived' ],
				[ 'status' => 'active' ]
			);
		}

		$inserted = $this->wpdb->insert(
			$this->table,
			[
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
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
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
	 * Retrieve current active rate card.
	 *
	 * @return object|null
	 */
	public function get_active() {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			"SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY activated_at DESC, id DESC LIMIT 1"
		);

		return $this->format_row( $row );
	}

	/**
	 * Retrieve current active rate card ID.
	 *
	 * @return int
	 */
	public function get_active_id() {
		$active = $this->get_active();
		return $active ? (int) $active->id : 0;
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
	 * Activate a rate card. Automatically archives previous active card.
	 *
	 * @param int $id Rate card ID.
	 * @return bool True on success, false on failure.
	 */
	public function activate( $id ) {
		$id = $this->abs_int( $id );
		if ( ! $id || ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		// Business rule: archive old active card.
		$this->wpdb->update(
			$this->table,
			[ 'status' => 'archived' ],
			[ 'status' => 'active' ]
		);

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
				do_action( 'allship_ups_rate_card_activated', $id );
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
}
