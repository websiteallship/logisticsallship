<?php
/**
 * Quote Lead Repository for managing ups_quote_leads table.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Quote_Lead_Repository {

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
		$this->table = $this->wpdb ? $this->wpdb->prefix . 'ups_quote_leads' : '';
	}

	/**
	 * Insert a new quote lead entry.
	 *
	 * @param array $data Lead attributes.
	 * @return int Inserted ID or 0 on failure.
	 */
	public function insert( array $data ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return 0;
		}

		$sanitize_text = function_exists( 'sanitize_text_field' ) ? 'sanitize_text_field' : 'trim';
		$json_func     = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';

		$quote_ref            = ! empty( $data['quote_ref'] ) ? $sanitize_text( $data['quote_ref'] ) : $this->generate_quote_ref();
		$quote_log_id         = ! empty( $data['quote_log_id'] ) ? absint( $data['quote_log_id'] ) : null;
		$contact_name         = ! empty( $data['contact_name'] ) ? $sanitize_text( $data['contact_name'] ) : '';
		$company_name         = ! empty( $data['company_name'] ) ? $sanitize_text( $data['company_name'] ) : null;
		$email                = ! empty( $data['email'] ) && function_exists( 'sanitize_email' ) ? sanitize_email( $data['email'] ) : ( ! empty( $data['email'] ) ? trim( (string) $data['email'] ) : '' );
		$phone                = ! empty( $data['phone'] ) ? $sanitize_text( $data['phone'] ) : null;
		$source               = ! empty( $data['source'] ) ? $sanitize_text( $data['source'] ) : 'pdf_export';
		$direction            = ! empty( $data['direction'] ) ? strtolower( $sanitize_text( $data['direction'] ) ) : 'export';
		$service_code         = ! empty( $data['service_code'] ) ? strtoupper( $sanitize_text( $data['service_code'] ) ) : '';
		$origin_country       = ! empty( $data['origin_country'] ) ? strtoupper( $sanitize_text( $data['origin_country'] ) ) : 'VN';
		$destination_iata     = ! empty( $data['destination_iata'] ) ? strtoupper( $sanitize_text( $data['destination_iata'] ) ) : '';
		$destination_name     = ! empty( $data['destination_name'] ) ? $sanitize_text( $data['destination_name'] ) : null;
		$chargeable_weight_kg = isset( $data['chargeable_weight_kg'] ) ? floatval( $data['chargeable_weight_kg'] ) : 0.0;
		$total_price_vnd      = isset( $data['total_price_vnd'] ) ? abs( (int) $data['total_price_vnd'] ) : 0;
		$quote_data_json      = isset( $data['quote_data_json'] ) ? ( is_array( $data['quote_data_json'] ) ? $json_func( $data['quote_data_json'] ) : (string) $data['quote_data_json'] ) : '{}';
		$pdf_path             = ! empty( $data['pdf_path'] ) ? $sanitize_text( $data['pdf_path'] ) : null;
		$email_sent           = ! empty( $data['email_sent'] ) ? 1 : 0;
		$email_sent_at        = ! empty( $data['email_sent_at'] ) ? $data['email_sent_at'] : null;
		$download_count       = isset( $data['download_count'] ) ? max( 1, (int) $data['download_count'] ) : 1;
		$ip_address           = ! empty( $data['ip_address'] ) ? $sanitize_text( $data['ip_address'] ) : null;
		$user_agent           = ! empty( $data['user_agent'] ) ? mb_substr( $sanitize_text( $data['user_agent'] ), 0, 255 ) : null;
		$created_at           = ! empty( $data['created_at'] ) ? $data['created_at'] : ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) );

		$record = [
			'quote_ref'            => $quote_ref,
			'quote_log_id'         => $quote_log_id,
			'contact_name'         => $contact_name,
			'company_name'         => $company_name,
			'email'                => $email,
			'phone'                => $phone,
			'source'               => $source,
			'direction'            => $direction,
			'service_code'         => $service_code,
			'origin_country'       => $origin_country,
			'destination_iata'     => $destination_iata,
			'destination_name'     => $destination_name,
			'chargeable_weight_kg' => $chargeable_weight_kg,
			'total_price_vnd'      => $total_price_vnd,
			'quote_data_json'      => $quote_data_json,
			'pdf_path'             => $pdf_path,
			'email_sent'           => $email_sent,
			'email_sent_at'        => $email_sent_at,
			'download_count'       => $download_count,
			'ip_address'           => $ip_address,
			'user_agent'           => $user_agent,
			'created_at'           => $created_at,
		];

		$formats = [
			'%s', // quote_ref
			$quote_log_id !== null ? '%d' : null,
			'%s', // contact_name
			$company_name !== null ? '%s' : null,
			'%s', // email
			$phone !== null ? '%s' : null,
			'%s', // source
			'%s', // direction
			'%s', // service_code
			'%s', // origin_country
			'%s', // destination_iata
			$destination_name !== null ? '%s' : null,
			'%f', // chargeable_weight_kg
			'%d', // total_price_vnd
			'%s', // quote_data_json
			$pdf_path !== null ? '%s' : null,
			'%d', // email_sent
			$email_sent_at !== null ? '%s' : null,
			'%d', // download_count
			$ip_address !== null ? '%s' : null,
			$user_agent !== null ? '%s' : null,
			'%s', // created_at
		];

		// Filter null format items
		$filtered_record  = [];
		$filtered_formats = [];
		$keys             = array_keys( $record );
		for ( $i = 0; $i < count( $keys ); $i++ ) {
			$k = $keys[ $i ];
			if ( null !== $formats[ $i ] ) {
				$filtered_record[ $k ] = $record[ $k ];
				$filtered_formats[]    = $formats[ $i ];
			}
		}

		$result = $this->wpdb->insert( $this->table, $filtered_record, $filtered_formats );
		if ( false === $result ) {
			return 0;
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Retrieve a single lead record by ID.
	 *
	 * @param int $id Lead ID.
	 * @return object|null Lead record object or null.
	 */
	public function get( $id ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return null;
		}

		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d",
				absint( $id )
			)
		);
	}

	/**
	 * Retrieve a single lead record by Quote Reference.
	 *
	 * @param string $ref Quote reference.
	 * @return object|null Lead record object or null.
	 */
	public function get_by_quote_ref( $ref ) {
		if ( ! $this->wpdb || empty( $this->table ) || empty( $ref ) ) {
			return null;
		}

		$sanitize_text = function_exists( 'sanitize_text_field' ) ? 'sanitize_text_field' : 'trim';
		$clean_ref     = $sanitize_text( $ref );

		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE quote_ref = %s",
				$clean_ref
			)
		);
	}

	/**
	 * Update an existing lead record.
	 *
	 * @param int   $id Lead ID.
	 * @param array $data Attributes to update.
	 * @return bool True on success, false on failure.
	 */
	public function update( $id, array $data ) {
		if ( ! $this->wpdb || empty( $this->table ) || empty( $id ) ) {
			return false;
		}

		$sanitize_text = function_exists( 'sanitize_text_field' ) ? 'sanitize_text_field' : 'trim';
		$json_func     = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';

		$fields  = [];
		$formats = [];

		if ( isset( $data['pdf_path'] ) ) {
			$fields['pdf_path'] = $sanitize_text( $data['pdf_path'] );
			$formats[]          = '%s';
		}

		if ( isset( $data['email_sent'] ) ) {
			$fields['email_sent'] = ! empty( $data['email_sent'] ) ? 1 : 0;
			$formats[]            = '%d';
		}

		if ( isset( $data['email_sent_at'] ) ) {
			$fields['email_sent_at'] = $data['email_sent_at'];
			$formats[]               = '%s';
		}

		if ( isset( $data['download_count'] ) ) {
			$fields['download_count'] = absint( $data['download_count'] );
			$formats[]                = '%d';
		}

		if ( isset( $data['quote_data_json'] ) ) {
			$fields['quote_data_json'] = is_array( $data['quote_data_json'] ) ? $json_func( $data['quote_data_json'] ) : (string) $data['quote_data_json'];
			$formats[]                 = '%s';
		}

		if ( empty( $fields ) ) {
			return false;
		}

		$result = $this->wpdb->update(
			$this->table,
			$fields,
			[ 'id' => absint( $id ) ],
			$formats,
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Increment download count for a lead.
	 *
	 * @param int $id Lead ID.
	 * @return bool
	 */
	public function increment_download( $id ) {
		if ( ! $this->wpdb || empty( $this->table ) || empty( $id ) ) {
			return false;
		}

		return false !== $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table} SET download_count = download_count + 1 WHERE id = %d",
				absint( $id )
			)
		);
	}

	/**
	 * Mark lead as email sent.
	 *
	 * @param int $id Lead ID.
	 * @return bool
	 */
	public function mark_email_sent( $id ) {
		if ( ! $this->wpdb || empty( $this->table ) || empty( $id ) ) {
			return false;
		}

		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		return $this->update(
			$id,
			[
				'email_sent'    => 1,
				'email_sent_at' => $now,
			]
		);
	}

	/**
	 * Delete a lead record.
	 *
	 * @param int $id Lead ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $id ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return false;
		}

		$result = $this->wpdb->delete(
			$this->table,
			[ 'id' => absint( $id ) ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * List lead records with filtering and pagination.
	 *
	 * @param array $filters Query parameters.
	 * @return array Array of record objects.
	 */
	public function list( array $filters = [] ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return [];
		}

		$where_clauses = [ '1=1' ];
		$where_values  = [];

		$this->build_where_query( $filters, $where_clauses, $where_values );

		$where_sql = implode( ' AND ', $where_clauses );
		if ( ! empty( $where_values ) ) {
			$where_sql = $this->wpdb->prepare( $where_sql, $where_values );
		}

		$per_page = isset( $filters['per_page'] ) ? max( 1, (int) $filters['per_page'] ) : 20;
		$page     = isset( $filters['page'] ) ? max( 1, (int) $filters['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$orderby = 'created_at';
		$order   = 'DESC';

		if ( ! empty( $filters['orderby'] ) ) {
			$allowed_orderby = [ 'id', 'created_at', 'total_price_vnd', 'contact_name', 'email', 'download_count' ];
			if ( in_array( $filters['orderby'], $allowed_orderby, true ) ) {
				$orderby = $filters['orderby'];
			}
		}

		if ( ! empty( $filters['order'] ) && 'ASC' === strtoupper( $filters['order'] ) ) {
			$order = 'ASC';
		}

		$sql = "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$sql = $this->wpdb->prepare( $sql, $per_page, $offset );

		return (array) $this->wpdb->get_results( $sql );
	}

	/**
	 * Count lead records matching filters.
	 *
	 * @param array $filters Query parameters.
	 * @return int Total records count.
	 */
	public function count( array $filters = [] ) {
		if ( ! $this->wpdb || empty( $this->table ) ) {
			return 0;
		}

		$where_clauses = [ '1=1' ];
		$where_values  = [];

		$this->build_where_query( $filters, $where_clauses, $where_values );

		$where_sql = implode( ' AND ', $where_clauses );
		if ( ! empty( $where_values ) ) {
			$where_sql = $this->wpdb->prepare( $where_sql, $where_values );
		}

		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}" );
	}

	/**
	 * Build parameterized where clauses from filters.
	 *
	 * @param array $filters       Input filters.
	 * @param array &$where_clauses Reference to where SQL clauses.
	 * @param array &$where_values  Reference to prepared values.
	 * @return void
	 */
	private function build_where_query( array $filters, array &$where_clauses, array &$where_values ) {
		if ( ! empty( $filters['service_code'] ) ) {
			$where_clauses[] = 'service_code = %s';
			$where_values[]  = strtoupper( trim( (string) $filters['service_code'] ) );
		}

		if ( ! empty( $filters['direction'] ) ) {
			$where_clauses[] = 'direction = %s';
			$where_values[]  = strtolower( trim( (string) $filters['direction'] ) );
		}

		if ( ! empty( $filters['destination_iata'] ) ) {
			$where_clauses[] = 'destination_iata = %s';
			$where_values[]  = strtoupper( trim( (string) $filters['destination_iata'] ) );
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where_clauses[] = 'created_at >= %s';
			$where_values[]  = trim( (string) $filters['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where_clauses[] = 'created_at <= %s';
			$where_values[]  = trim( (string) $filters['date_to'] ) . ' 23:59:59';
		}

		if ( ! empty( $filters['search'] ) ) {
			$search_term     = '%' . $this->wpdb->esc_like( trim( (string) $filters['search'] ) ) . '%';
			$where_clauses[] = '(contact_name LIKE %s OR company_name LIKE %s OR email LIKE %s OR phone LIKE %s OR quote_ref LIKE %s)';
			$where_values[]  = $search_term;
			$where_values[]  = $search_term;
			$where_values[]  = $search_term;
			$where_values[]  = $search_term;
			$where_values[]  = $search_term;
		}
	}

	/**
	 * Generate a unique quote reference (e.g. AS-QUO-202610-0012).
	 *
	 * @return string
	 */
	public function generate_quote_ref() {
		$prefix_code = 'AS-QUO-' . gmdate( 'Ym' ) . '-';
		$seq         = wp_rand( 1000, 9999 );

		if ( $this->wpdb && ! empty( $this->table ) ) {
			$count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
			$seq   = str_pad( (string) ( $count + 1 ), 4, '0', STR_PAD_LEFT );
		}

		return $prefix_code . $seq;
	}
}
