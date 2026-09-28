<?php
/**
 * Rate Importer for UPS quote rates.
 *
 * Supports simple matrix format: Row 1 = zone headers (kg, 1..10, US5),
 * Row 2+ = weight + prices. Rate group is provided externally by the import form.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Rate_Importer {

	/**
	 * Valid zone identifiers.
	 */
	const VALID_ZONES = [ '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'US5' ];

	/**
	 * Valid rate group identifiers.
	 */
	const VALID_RATE_GROUPS = [
		// Export
		'export_exw_document'    => 'Express Early Document (EXW Doc) - Export',
		'export_exw_nondocument' => 'Express Early Non-Document (EXW Non-Doc) - Export',
		'export_xpr_document'    => 'Express Plus Document (XPR Doc) - Export',
		'export_xpr_nondocument' => 'Express Plus Non-Document (XPR Non-Doc) - Export',
		'export_wxs_document'    => 'Express Saver Document (WXS Doc) - Export',
		'export_wxs_nondocument' => 'Express Saver Non-Document (WXS Non-Doc) - Export',
		'export_xpd'             => 'Expedited (XPD) - Export',
		'export_wxp'             => 'Express Freight (WXP) - Export',
		'export_wfm'             => 'Freight Midday (WFM) - Export',
		// Import
		'import_exw_document'    => 'Express Early Document (EXW Doc) - Import',
		'import_exw_nondocument' => 'Express Early Non-Document (EXW Non-Doc) - Import',
		'import_xpr_document'    => 'Express Plus Document (XPR Doc) - Import',
		'import_xpr_nondocument' => 'Express Plus Non-Document (XPR Non-Doc) - Import',
		'import_wxs_document'    => 'Express Saver Document (WXS Doc) - Import',
		'import_wxs_nondocument' => 'Express Saver Non-Document (WXS Non-Doc) - Import',
		'import_xpd'             => 'Expedited (XPD) - Import',
		'import_wxp'             => 'Express Freight (WXP) - Import',
		'import_wfm'             => 'Freight Midday (WFM) - Import',
	];

	/**
	 * CSV Parser instance.
	 *
	 * @var Allship_UPS_CSV_Parser
	 */
	private $csv_parser;

	/**
	 * XLSX Reader instance.
	 *
	 * @var Allship_UPS_XLSX_Reader
	 */
	private $xlsx_reader;

	/**
	 * Rate Repository instance.
	 *
	 * @var Allship_UPS_Rate_Repository
	 */
	private $rate_repo;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_CSV_Parser|null      $csv_parser Optional parser.
	 * @param Allship_UPS_XLSX_Reader|null     $xlsx_reader Optional reader.
	 * @param Allship_UPS_Rate_Repository|null $rate_repo Optional repository.
	 */
	public function __construct( $csv_parser = null, $xlsx_reader = null, $rate_repo = null ) {
		$this->csv_parser  = $csv_parser ?: new Allship_UPS_CSV_Parser();
		$this->xlsx_reader = $xlsx_reader ?: new Allship_UPS_XLSX_Reader();
		$this->rate_repo   = $rate_repo ?: new Allship_UPS_Rate_Repository();
	}

	/**
	 * Read raw 2D rows from file (CSV or XLSX).
	 *
	 * @param string $file_path Path to file.
	 * @return array 2D array of rows.
	 * @throws Exception When file is unreadable or unsupported.
	 */
	public function read_rows( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			throw new Exception( 'File not found: ' . esc_html( $file_path ) );
		}

		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		if ( 'xlsx' === $ext ) {
			return $this->xlsx_reader->parse( $file_path );
		}

		if ( 'csv' === $ext ) {
			return $this->csv_parser->parse_raw( $file_path );
		}

		throw new Exception( 'Unsupported file format. Only .xlsx and .csv are accepted.' );
	}

	/**
	 * Detect file format.
	 *
	 * @param string $file_path File path.
	 * @return string Format identifier.
	 */
	public function detect_format( $file_path ) {
		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		return ( 'csv' === $ext ) ? 'csv_matrix' : 'xlsx_matrix';
	}

	/**
	 * Parse file directly into rate records.
	 *
	 * @param string $file_path Path to file.
	 * @param string $rate_group Rate group.
	 * @return array
	 */
	public function parse_file( $file_path, $rate_group = 'export_wxs_nondocument' ) {
		$rows = $this->read_rows( $file_path );
		return $this->parse_matrix( $rows, $rate_group );
	}

	/**
	 * Locate the header row containing zone columns (1..10, US5).
	 *
	 * Scans the first 10 rows for a row containing at least 5 zone identifiers.
	 *
	 * @param array $rows 2D array of rows.
	 * @return int|false Header row index, or false if not found.
	 */
	public function find_header_row( array $rows ) {
		$scan_limit = min( 10, count( $rows ) );

		for ( $i = 0; $i < $scan_limit; $i++ ) {
			$zone_count = 0;
			foreach ( $rows[ $i ] as $cell ) {
				$cell_str = strtoupper( trim( preg_replace( '/\s+/', '', (string) $cell ) ) );
				if ( in_array( $cell_str, [ '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'US5' ], true ) ) {
					$zone_count++;
				}
			}
			if ( $zone_count >= 5 ) {
				return $i;
			}
		}

		return false;
	}

	/**
	 * Parse a matrix rate file into normalized rate records.
	 *
	 * Expected format:
	 * - Header row: (kg) | 1 | 2 | 3 | ... | 10 | US5
	 * - Data rows:  0.5  | 760396 | 830467 | ... | 617141
	 *
	 * @param array  $rows 2D rows from read_rows().
	 * @param string $rate_group Rate group identifier (from form).
	 * @return array List of normalized rate records.
	 * @throws Exception When header row is not found.
	 */
	public function parse_matrix( array $rows, $rate_group ) {
		$header_idx = $this->find_header_row( $rows );

		if ( false === $header_idx ) {
			throw new Exception( 'Cannot find zone header row (need columns: 1, 2, ..., 10, US5).' );
		}

		// Map zone columns
		$zone_columns   = [];
		$weight_col_idx = null;
		$header_row     = $rows[ $header_idx ];

		foreach ( $header_row as $col_idx => $cell ) {
			$cell_str = strtoupper( trim( preg_replace( '/\s+/', '', (string) $cell ) ) );

			if ( in_array( $cell_str, [ '1', '2', '3', '4', '5', '6', '7', '8', '9', '10' ], true ) ) {
				$zone_columns[ $col_idx ] = $cell_str;
			} elseif ( 'US5' === $cell_str ) {
				$zone_columns[ $col_idx ] = 'US5';
			}
		}

		if ( count( $zone_columns ) < 10 ) {
			throw new Exception( 'Header row must contain at least zones 1-10. Found ' . count( $zone_columns ) . ' zone columns.' );
		}

		// Weight column = the column just before the first zone column
		$first_zone_col = min( array_keys( $zone_columns ) );
		$weight_col_idx = max( 0, $first_zone_col - 1 );

		// Parse data rows
		$records    = [];
		$sort_order = 0;
		$data_rows  = array_slice( $rows, $header_idx + 1 );

		foreach ( $data_rows as $row ) {
			$raw_weight = isset( $row[ $weight_col_idx ] ) ? $row[ $weight_col_idx ] : '';
			if ( '' === trim( (string) $raw_weight ) ) {
				continue;
			}

			$weight_info = $this->normalize_weight( $raw_weight );

			foreach ( $zone_columns as $col_idx => $zone ) {
				$raw_price = isset( $row[ $col_idx ] ) ? $row[ $col_idx ] : null;
				if ( null === $raw_price || '' === trim( (string) $raw_price ) ) {
					continue;
				}

				$price_clean = (int) round( (float) str_replace( [ ',', ' ' ], '', (string) $raw_price ) );

				$records[] = [
					'rate_group'   => $rate_group,
					'zone'         => (string) $zone,
					'weight_label' => $weight_info['weight_label'],
					'weight_from'  => $weight_info['weight_from'],
					'weight_to'    => $weight_info['weight_to'],
					'billing_unit' => $weight_info['billing_unit'],
					'price_vnd'    => $price_clean,
					'sort_order'   => ++$sort_order,
				];
			}
		}

		return $records;
	}

	/**
	 * Normalize raw weight or bracket string into structured attributes.
	 *
	 * Rules:
	 * - "UPS Envelope" -> billing_unit = 'flat', weight_from = null, weight_to = null
	 * - "Minimum"      -> billing_unit = 'minimum', weight_from = null, weight_to = null
	 * - ">1000"        -> billing_unit = 'per_kg', weight_from = 1000.0001, weight_to = null
	 * - "21-44"        -> billing_unit = 'per_kg', weight_from = 21, weight_to = 44
	 * - "0.5", 6       -> billing_unit = 'flat', weight_from = value, weight_to = value
	 *
	 * @param mixed $raw_weight Raw weight string or numeric value.
	 * @return array Normalized weight attributes.
	 */
	public function normalize_weight( $raw_weight ) {
		$raw_str = trim( (string) $raw_weight );

		// 1. UPS Envelope
		if ( 0 === strcasecmp( $raw_str, 'UPS Envelope' ) || false !== stripos( $raw_str, 'Envelope' ) ) {
			return [
				'weight_label' => 'UPS Envelope',
				'weight_from'  => null,
				'weight_to'    => null,
				'billing_unit' => 'flat',
			];
		}

		// 2. Minimum
		if ( 0 === strcasecmp( $raw_str, 'Minimum' ) ) {
			return [
				'weight_label' => 'Minimum',
				'weight_from'  => null,
				'weight_to'    => null,
				'billing_unit' => 'minimum',
			];
		}

		// 3. Open bracket: >1000 or > 1000 or +1000
		if ( preg_match( '/^[>+]\s*(\d+(?:\.\d+)?)$/', $raw_str, $matches ) ) {
			$base_val = (float) $matches[1];
			return [
				'weight_label' => '>' . (int) $base_val,
				'weight_from'  => (float) ( $base_val + 0.0001 ),
				'weight_to'    => null,
				'billing_unit' => 'per_kg',
			];
		}

		// 3b. Under/Minimum bracket: <70 or <=70
		if ( preg_match( '/^<=?\s*(\d+(?:\.\d+)?)$/', $raw_str, $matches ) ) {
			$base_val = (float) $matches[1];
			return [
				'weight_label' => '<' . (int) $base_val,
				'weight_from'  => 0.0,
				'weight_to'    => $base_val,
				'billing_unit' => 'flat',
			];
		}

		// 4. Weight range: 21-44, 45-70, etc.
		if ( preg_match( '/^(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)$/', $raw_str, $matches ) ) {
			return [
				'weight_label' => $matches[1] . '-' . $matches[2],
				'weight_from'  => (float) $matches[1],
				'weight_to'    => (float) $matches[2],
				'billing_unit' => 'per_kg',
			];
		}

		// 5. Fixed numeric weight: 0.5, 1, 1.5, 20
		if ( is_numeric( $raw_str ) ) {
			$val = (float) $raw_str;
			return [
				'weight_label' => (string) $raw_str,
				'weight_from'  => $val,
				'weight_to'    => $val,
				'billing_unit' => 'flat',
			];
		}

		// Fallback
		return [
			'weight_label' => $raw_str,
			'weight_from'  => null,
			'weight_to'    => null,
			'billing_unit' => 'flat',
		];
	}

	/**
	 * Validate parsed rate records.
	 *
	 * Checks:
	 * - Records exist
	 * - Zones 1-10 + US5 present
	 * - Positive numeric prices
	 *
	 * @param array $records List of rate records.
	 * @return array Validation result array.
	 */
	public function validate( array $records ) {
		$errors      = [];
		$warnings    = [];
		$found_zones = [];

		if ( empty( $records ) ) {
			$errors[] = 'No rate records parsed from file.';
			return [
				'is_valid'    => false,
				'errors'      => $errors,
				'warnings'    => $warnings,
				'rate_group'  => '',
				'zones'       => [],
				'total_rates' => 0,
			];
		}

		$rate_group = $records[0]['rate_group'] ?? '';

		foreach ( $records as $idx => $r ) {
			$zone  = isset( $r['zone'] ) ? $r['zone'] : '';
			$price = isset( $r['price_vnd'] ) ? $r['price_vnd'] : 0;
			$label = isset( $r['weight_label'] ) ? $r['weight_label'] : '';

			if ( $zone ) {
				$found_zones[ $zone ] = true;
			}

			if ( ! is_numeric( $price ) || $price <= 0 ) {
				$errors[] = sprintf(
					'Invalid price (%s) for zone %s, weight %s at record %d.',
					(string) $price,
					$zone,
					$label,
					$idx + 1
				);
			}
		}

		// Check required zones 1-10 + US5
		foreach ( self::VALID_ZONES as $req_zone ) {
			if ( empty( $found_zones[ $req_zone ] ) ) {
				if ( 'US5' === $req_zone ) {
					$warnings[] = 'Không tìm thấy cột US5. Nếu không cần US5, có thể bỏ qua.';
				} else {
					$errors[] = sprintf( 'Thiếu zone bắt buộc: %s.', $req_zone );
				}
			}
		}

		return [
			'is_valid'    => empty( $errors ),
			'errors'      => $errors,
			'warnings'    => $warnings,
			'rate_group'  => $rate_group,
			'zones'       => array_values( array_map( 'strval', array_keys( $found_zones ) ) ),
			'total_rates' => count( $records ),
		];
	}

	/**
	 * Preview mode: parses and validates file, returns detailed summary without DB commit.
	 *
	 * @param string $file_path Path to uploaded rate file.
	 * @param string $rate_group Rate group identifier from form.
	 * @return array Preview summary report.
	 */
	public function preview( $file_path, $rate_group = 'export_wxs_nondocument' ) {
		try {
			$rows       = $this->read_rows( $file_path );
			$records    = $this->parse_matrix( $rows, $rate_group );
			$validation = $this->validate( $records );

			// Count weight brackets
			$weight_brackets = [];
			foreach ( $records as $r ) {
				$w_label = $r['weight_label'];
				if ( ! in_array( $w_label, $weight_brackets, true ) ) {
					$weight_brackets[] = $w_label;
				}
			}

			$group_label = isset( self::VALID_RATE_GROUPS[ $rate_group ] )
				? self::VALID_RATE_GROUPS[ $rate_group ]
				: $rate_group;

			return [
				'status'          => $validation['is_valid'] ? 'success' : 'error',
				'format_detected' => 'matrix',
				'total_rates'     => count( $records ),
				'rate_groups'     => [
					[
						'rate_group'      => $rate_group,
						'label'           => $group_label,
						'count'           => count( $records ),
						'weight_brackets' => $weight_brackets,
					],
				],
				'zones'           => $validation['zones'],
				'errors'          => $validation['errors'],
				'warnings'        => $validation['warnings'],
			];
		} catch ( Exception $e ) {
			return [
				'status'          => 'error',
				'format_detected' => 'unknown',
				'total_rates'     => 0,
				'rate_groups'     => [],
				'zones'           => [],
				'errors'          => [ $e->getMessage() ],
				'warnings'        => [],
			];
		}
	}

	/**
	 * Import rates into database for a specific rate card.
	 *
	 * @param string $file_path Path to uploaded rate file.
	 * @param int    $rate_card_id Target rate card ID.
	 * @param string $rate_group Rate group identifier from form.
	 * @return array Import result summary.
	 * @throws Exception If validation fails or database error occurs.
	 */
	public function import( $file_path, $rate_card_id, $rate_group = 'export_wxs_nondocument' ) {
		$rate_card_id = abs( (int) $rate_card_id );
		if ( ! $rate_card_id ) {
			throw new Exception( 'A valid rate_card_id is required for rate import.' );
		}

		$rows       = $this->read_rows( $file_path );
		$records    = $this->parse_matrix( $rows, $rate_group );
		$validation = $this->validate( $records );

		if ( ! $validation['is_valid'] ) {
			throw new Exception( 'Rate validation failed: ' . implode( '; ', $validation['errors'] ) );
		}

		// Inject rate_card_id into all records
		foreach ( $records as &$record ) {
			$record['rate_card_id'] = $rate_card_id;
		}
		unset( $record );

		// Insert records via repository
		$inserted_count = $this->rate_repo->bulk_insert( $records );

		return [
			'status'               => 'success',
			'total_rates_imported' => $inserted_count,
			'total_rates'          => count( $records ),
			'rate_card_id'         => $rate_card_id,
			'rate_group'           => $rate_group,
			'zones'                => $validation['zones'],
		];
	}
}
