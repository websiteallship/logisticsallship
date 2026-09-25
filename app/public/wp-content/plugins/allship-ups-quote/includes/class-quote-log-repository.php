<?php
/**
 * Quote Log Repository for managing ups_quote_logs table.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Quote_Log_Repository {

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
		$this->table = $this->wpdb ? $this->wpdb->prefix . 'ups_quote_logs' : '';
	}

	/**
	 * Insert a new quote log entry.
	 *
	 * @param array $data Quote log attributes.
	 * @return int Inserted ID or 0 on failure.
	 */
	public function insert( array $data ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return 0;
		}

		$sanitize_text = function_exists( 'sanitize_text_field' ) ? 'sanitize_text_field' : 'trim';
		$json_func     = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';

		$rate_card_id           = ! empty( $data['rate_card_id'] ) ? abs( (int) $data['rate_card_id'] ) : null;
		$user_id                = isset( $data['user_id'] ) ? ( ! empty( $data['user_id'] ) ? abs( (int) $data['user_id'] ) : null ) : ( function_exists( 'get_current_user_id' ) ? ( get_current_user_id() ?: null ) : null );
		$session_id             = ! empty( $data['session_id'] ) ? $sanitize_text( $data['session_id'] ) : null;
		$direction              = ! empty( $data['direction'] ) ? strtolower( trim( (string) $data['direction'] ) ) : 'export';
		$origin_iata            = ! empty( $data['origin_iata'] ) ? strtoupper( trim( (string) $data['origin_iata'] ) ) : 'VN';
		$origin_province        = ! empty( $data['origin_province'] ) ? $sanitize_text( $data['origin_province'] ) : null;
		$destination_iata       = ! empty( $data['destination_iata'] ) ? strtoupper( trim( (string) $data['destination_iata'] ) ) : '';
		$destination_state      = ! empty( $data['destination_state'] ) ? $sanitize_text( $data['destination_state'] ) : null;
		$destination_city       = ! empty( $data['destination_city'] ) ? $sanitize_text( $data['destination_city'] ) : null;
		$destination_postal_code = ! empty( $data['destination_postal_code'] ) ? $sanitize_text( $data['destination_postal_code'] ) : null;
		$destination_address    = ! empty( $data['destination_address'] ) ? $sanitize_text( $data['destination_address'] ) : null;
		$service_code           = ! empty( $data['service_code'] ) ? strtoupper( trim( (string) $data['service_code'] ) ) : '';
		$shipment_type          = ! empty( $data['shipment_type'] ) ? $sanitize_text( $data['shipment_type'] ) : null;
		$zone                   = ! empty( $data['zone'] ) ? trim( (string) $data['zone'] ) : null;
		$rate_zone              = ! empty( $data['rate_zone'] ) ? trim( (string) $data['rate_zone'] ) : null;
		$actual_weight_kg       = isset( $data['actual_weight_kg'] ) && '' !== $data['actual_weight_kg'] ? (float) $data['actual_weight_kg'] : null;
		$dim_weight_kg          = isset( $data['dim_weight_kg'] ) && '' !== $data['dim_weight_kg'] ? (float) $data['dim_weight_kg'] : null;
		$chargeable_weight_kg   = isset( $data['chargeable_weight_kg'] ) && '' !== $data['chargeable_weight_kg'] ? (float) $data['chargeable_weight_kg'] : null;
		$base_price_vnd         = isset( $data['base_price_vnd'] ) && '' !== $data['base_price_vnd'] ? abs( (int) $data['base_price_vnd'] ) : null;
		$total_price_vnd        = isset( $data['total_price_vnd'] ) && '' !== $data['total_price_vnd'] ? abs( (int) $data['total_price_vnd'] ) : null;

		$pieces_json = ! empty( $data['pieces_json'] ) ? ( is_array( $data['pieces_json'] ) ? $json_func( $data['pieces_json'] ) : (string) $data['pieces_json'] ) : null;
		$breakdown_json = ! empty( $data['breakdown_json'] ) ? ( is_array( $data['breakdown_json'] ) ? $json_func( $data['breakdown_json'] ) : (string) $data['breakdown_json'] ) : null;
		$created_at     = ! empty( $data['created_at'] ) ? $data['created_at'] : ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) );

		$inserted = $this->wpdb->insert(
			$this->table,
			[
				'rate_card_id'            => $rate_card_id,
				'user_id'                 => $user_id,
				'session_id'              => $session_id,
				'direction'               => $direction,
				'origin_iata'             => $origin_iata,
				'origin_province'         => $origin_province,
				'destination_iata'        => $destination_iata,
				'destination_state'       => $destination_state,
				'destination_city'        => $destination_city,
				'destination_postal_code' => $destination_postal_code,
				'destination_address'     => $destination_address,
				'service_code'            => $service_code,
				'shipment_type'           => $shipment_type,
				'zone'                    => $zone,
				'rate_zone'               => $rate_zone,
				'actual_weight_kg'        => $actual_weight_kg,
				'dim_weight_kg'           => $dim_weight_kg,
				'chargeable_weight_kg'    => $chargeable_weight_kg,
				'base_price_vnd'          => $base_price_vnd,
				'total_price_vnd'         => $total_price_vnd,
				'pieces_json'             => $pieces_json,
				'breakdown_json'          => $breakdown_json,
				'created_at'              => $created_at,
			],
			[
				'%d', '%d', '%s', '%s', '%s', '%s',
				'%s', '%s', '%s', '%s', '%s', '%s',
				'%s', '%s', '%s', '%f', '%f', '%f',
				'%d', '%d', '%s', '%s', '%s'
			]
		);

		return false !== $inserted ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * Build query conditions from filters.
	 *
	 * @param array $filters Query filter keys.
	 * @return array{0: string, 1: array} Where SQL clause and parameters.
	 */
	private function build_where( array $filters ) {
		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $filters['direction'] ) ) {
			$where[]  = 'direction = %s';
			$params[] = strtolower( trim( (string) $filters['direction'] ) );
		}

		if ( ! empty( $filters['destination_iata'] ) ) {
			$where[]  = 'destination_iata = %s';
			$params[] = strtoupper( trim( (string) $filters['destination_iata'] ) );
		}

		if ( ! empty( $filters['service_code'] ) ) {
			$where[]  = 'service_code = %s';
			$params[] = strtoupper( trim( (string) $filters['service_code'] ) );
		}

		if ( ! empty( $filters['rate_card_id'] ) ) {
			$where[]  = 'rate_card_id = %d';
			$params[] = abs( (int) $filters['rate_card_id'] );
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = trim( (string) $filters['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = trim( (string) $filters['date_to'] ) . ' 23:59:59';
		}

		if ( ! empty( $filters['search'] ) ) {
			$term    = trim( (string) $filters['search'] );
			$esc     = method_exists( $this->wpdb, 'esc_like' ) ? $this->wpdb->esc_like( $term ) : addcslashes( $term, '_%\\' );
			$like    = '%' . $esc . '%';
			$where[] = '( destination_iata LIKE %s OR session_id LIKE %s OR destination_city LIKE %s OR destination_address LIKE %s )';
			$params  = array_merge( $params, [ $like, $like, $like, $like ] );
		}

		return [ implode( ' AND ', $where ), $params ];
	}

	/**
	 * Retrieve filtered quote log records.
	 *
	 * @param array $filters Query filters.
	 * @param int   $page Page number (1-indexed).
	 * @param int   $per_page Items per page. -1 for unlimited.
	 * @return array
	 */
	public function get_filtered( array $filters = [], $page = 1, $per_page = 20 ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return [];
		}

		list( $where_sql, $params ) = $this->build_where( $filters );

		$orderby = 'created_at';
		$order   = 'DESC';

		if ( ! empty( $filters['orderby'] ) && in_array( $filters['orderby'], [ 'id', 'created_at', 'total_price_vnd', 'destination_iata' ], true ) ) {
			$orderby = $filters['orderby'];
		}
		if ( ! empty( $filters['order'] ) && 'ASC' === strtoupper( $filters['order'] ) ) {
			$order = 'ASC';
		}

		$limit_sql = '';
		if ( (int) $per_page > 0 ) {
			$page      = max( 1, (int) $page );
			$limit     = (int) $per_page;
			$offset    = ( $page - 1 ) * $limit;
			$limit_sql = $this->wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		$sql = "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY {$orderby} {$order}" . $limit_sql;

		if ( ! empty( $params ) ) {
			$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ) );
		} else {
			$rows = $this->wpdb->get_results( $sql );
		}

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'format_row' ], $rows );
	}

	/**
	 * Count filtered quote log records.
	 *
	 * @param array $filters Query filters.
	 * @return int Total records count.
	 */
	public function count_filtered( array $filters = [] ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return 0;
		}

		list( $where_sql, $params ) = $this->build_where( $filters );

		$sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			return (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, $params ) );
		}

		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Export filtered quote logs to CSV format.
	 *
	 * @param array         $filters Query filters.
	 * @param resource|null $stream Optional stream resource. If null, outputs to php://output.
	 * @return void
	 */
	public function export_csv( array $filters = [], $stream = null ) {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die( 'Unauthorized', 403 );
			}
			return;
		}

		$logs = $this->get_filtered( $filters, 1, -1 );

		$close_on_finish = false;
		if ( null === $stream ) {
			if ( ! headers_sent() ) {
				header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename=quote-logs-' . gmdate( 'Y-m-d' ) . '.csv' );
			}
			$stream = fopen( 'php://output', 'w' );
			$close_on_finish = true;
		}

		// UTF-8 BOM for Microsoft Excel compatibility
		fputs( $stream, "\xEF\xBB\xBF" );

		fputcsv(
			$stream,
			[
				'ID',
				'Date',
				'Direction',
				'Origin',
				'Destination',
				'Destination City',
				'Service',
				'Shipment Type',
				'Zone',
				'Rate Zone',
				'Actual Weight (kg)',
				'Dim Weight (kg)',
				'Chargeable Weight (kg)',
				'Base Price (VND)',
				'Total Price (VND)',
				'Rate Card ID',
			]
		);

		foreach ( $logs as $log ) {
			fputcsv(
				$stream,
				[
					$log->id,
					$log->created_at,
					$log->direction,
					$log->origin_iata,
					$log->destination_iata,
					$log->destination_city,
					$log->service_code,
					$log->shipment_type,
					$log->zone,
					$log->rate_zone,
					$log->actual_weight_kg,
					$log->dim_weight_kg,
					$log->chargeable_weight_kg,
					$log->base_price_vnd,
					$log->total_price_vnd,
					$log->rate_card_id,
				]
			);
		}

		if ( $close_on_finish ) {
			fclose( $stream );
			exit;
		}
	}

	/**
	 * Export filtered quote logs to Excel XML spreadsheet format.
	 *
	 * @param array         $filters Query filters.
	 * @param resource|null $stream Optional stream resource. If null, outputs to php://output.
	 * @return void
	 */
	public function export_excel( array $filters = [], $stream = null ) {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die( 'Unauthorized', 403 );
			}
			return;
		}

		$logs = $this->get_filtered( $filters, 1, -1 );

		$close_on_finish = false;
		if ( null === $stream ) {
			if ( ! headers_sent() ) {
				header( 'Content-Type: application/vnd.ms-excel; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename=quote-logs-' . gmdate( 'Y-m-d' ) . '.xls' );
			}
			$stream = fopen( 'php://output', 'w' );
			$close_on_finish = true;
		}

		fputs( $stream, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" );
		fputs( $stream, "<?mso-application progid=\"Excel.Sheet\"?>\n" );
		fputs( $stream, "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\"\n" );
		fputs( $stream, " xmlns:o=\"urn:schemas-microsoft-com:office:office\"\n" );
		fputs( $stream, " xmlns:x=\"urn:schemas-microsoft-com:office:excel\"\n" );
		fputs( $stream, " xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n" );
		fputs( $stream, " <Worksheet ss:Name=\"Quote Logs\">\n" );
		fputs( $stream, "  <Table>\n" );

		$headers = [
			'ID', 'Date', 'Direction', 'Origin', 'Destination', 'Destination City',
			'Service', 'Shipment Type', 'Zone', 'Rate Zone', 'Actual Weight (kg)',
			'Dim Weight (kg)', 'Chargeable Weight (kg)', 'Base Price (VND)',
			'Total Price (VND)', 'Rate Card ID'
		];

		fputs( $stream, "   <Row>\n" );
		foreach ( $headers as $h ) {
			fputs( $stream, "    <Cell><Data ss:Type=\"String\">" . htmlspecialchars( $h, ENT_QUOTES, 'UTF-8' ) . "</Data></Cell>\n" );
		}
		fputs( $stream, "   </Row>\n" );

		foreach ( $logs as $log ) {
			fputs( $stream, "   <Row>\n" );
			$cols = [
				[ 'Number', $log->id ],
				[ 'String', $log->created_at ],
				[ 'String', $log->direction ],
				[ 'String', $log->origin_iata ],
				[ 'String', $log->destination_iata ],
				[ 'String', (string) $log->destination_city ],
				[ 'String', $log->service_code ],
				[ 'String', (string) $log->shipment_type ],
				[ 'String', (string) $log->zone ],
				[ 'String', (string) $log->rate_zone ],
				[ 'Number', $log->actual_weight_kg ],
				[ 'Number', $log->dim_weight_kg ],
				[ 'Number', $log->chargeable_weight_kg ],
				[ 'Number', $log->base_price_vnd ],
				[ 'Number', $log->total_price_vnd ],
				[ 'Number', $log->rate_card_id ],
			];
			foreach ( $cols as $col ) {
				$val = null !== $col[1] ? htmlspecialchars( (string) $col[1], ENT_QUOTES, 'UTF-8' ) : '';
				fputs( $stream, "    <Cell><Data ss:Type=\"{$col[0]}\">{$val}</Data></Cell>\n" );
			}
			fputs( $stream, "   </Row>\n" );
		}

		fputs( $stream, "  </Table>\n" );
		fputs( $stream, " </Worksheet>\n" );
		fputs( $stream, "</Workbook>\n" );

		if ( $close_on_finish ) {
			fclose( $stream );
			exit;
		}
	}

	/**
	 * Format database row into typed object.
	 *
	 * @param object|null $row Raw database row.
	 * @return object|null
	 */
	private function format_row( $row ) {
		if ( ! $row || ! is_object( $row ) ) {
			return null;
		}

		$row->id                   = (int) $row->id;
		$row->rate_card_id         = null !== $row->rate_card_id ? (int) $row->rate_card_id : null;
		$row->user_id              = null !== $row->user_id ? (int) $row->user_id : null;
		$row->actual_weight_kg     = null !== $row->actual_weight_kg ? (float) $row->actual_weight_kg : null;
		$row->dim_weight_kg        = null !== $row->dim_weight_kg ? (float) $row->dim_weight_kg : null;
		$row->chargeable_weight_kg = null !== $row->chargeable_weight_kg ? (float) $row->chargeable_weight_kg : null;
		$row->base_price_vnd       = null !== $row->base_price_vnd ? (int) $row->base_price_vnd : null;
		$row->total_price_vnd      = null !== $row->total_price_vnd ? (int) $row->total_price_vnd : null;

		$row->pieces    = ! empty( $row->pieces_json ) ? json_decode( $row->pieces_json, true ) : [];
		$row->breakdown = ! empty( $row->breakdown_json ) ? json_decode( $row->breakdown_json, true ) : [];

		return $row;
	}
}
