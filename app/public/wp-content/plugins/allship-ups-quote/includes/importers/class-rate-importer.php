<?php
/**
 * Rate Importer for UPS quote rates.
 *
 * Supports CSV templates and original UPS XLSX format.
 * Normalizes weight brackets and validates rate groups, zones, and pricing.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Rate_Importer {

	/**
	 * Expected CSV headers for template format.
	 */
	const EXPECTED_RATE_HEADERS = [
		'rate_group',
		'weight_label',
		'weight_from',
		'weight_to',
		'billing_unit',
		'zone_1',
		'zone_2',
		'zone_3',
		'zone_4',
		'zone_5',
		'zone_6',
		'zone_7',
		'zone_8',
		'zone_9',
		'zone_10',
		'zone_us5',
	];

	/**
	 * Required 4 rate groups for full export rate coverage.
	 */
	const REQUIRED_RATE_GROUPS = [
		'export_wxs_document',
		'export_wxs_nondocument',
		'export_xpd',
		'export_wfm',
	];

	/**
	 * Required zones.
	 */
	const REQUIRED_ZONES = [
		'1', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'US5',
	];

	/**
	 * Label detection mapping for original UPS XLSX format.
	 */
	const UPS_RATE_LABELS = [
		'Express Saver Document Rates:'     => 'export_wxs_document',
		'Express Saver Non-Document Rates:' => 'export_wxs_nondocument',
		'Expedited Rates:'                  => 'export_xpd',
		'Worldwide Express Freight Rates:'  => 'export_wfm',
	];

	/**
	 * Rate group name normalization and aliases.
	 */
	const RATE_GROUP_ALIASES = [
		'saver_document'         => 'export_wxs_document',
		'saver_doc'              => 'export_wxs_document',
		'export_wxs_document'    => 'export_wxs_document',
		'saver_nondocument'      => 'export_wxs_nondocument',
		'saver_nondoc'           => 'export_wxs_nondocument',
		'export_wxs_nondocument' => 'export_wxs_nondocument',
		'expedited'              => 'export_xpd',
		'export_xpd'             => 'export_xpd',
		'freight'                => 'export_wfm',
		'export_wfm'             => 'export_wfm',
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
	 * Detect input file format.
	 *
	 * @param string $file_path Path to file.
	 * @return string 'csv_template', 'ups_xlsx', or 'xlsx_template'.
	 * @throws Exception When format cannot be recognized.
	 */
	public function detect_format( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			throw new Exception( 'File not found: ' . esc_html( $file_path ) );
		}

		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		if ( 'csv' === $ext ) {
			return 'csv_template';
		}

		if ( 'xlsx' === $ext ) {
			$rows = $this->xlsx_reader->parse( $file_path );
			if ( $this->is_ups_original_format( $rows ) ) {
				return 'ups_xlsx';
			}

			if ( ! empty( $rows ) ) {
				$first_row = array_map( 'strval', $rows[0] );
				$intersect = array_intersect( self::EXPECTED_RATE_HEADERS, $first_row );
				if ( count( $intersect ) >= 6 ) {
					return 'xlsx_template';
				}
			}

			// If neither matched clearly, check if UPS labels exist anywhere in workbook
			foreach ( $rows as $row ) {
				foreach ( $row as $cell ) {
					if ( is_string( $cell ) && false !== stripos( $cell, 'Express Saver Document Rates' ) ) {
						return 'ups_xlsx';
					}
				}
			}

			throw new Exception( 'Unrecognized XLSX rate format. Missing required UPS section labels.' );
		}

		throw new Exception( 'Unsupported file extension: .' . $ext );
	}

	/**
	 * Check whether rows match UPS original XLSX rate sheet.
	 *
	 * @param array $rows 2D array of rows.
	 * @return bool
	 */
	public function is_ups_original_format( array $rows ) {
		foreach ( $rows as $row ) {
			foreach ( $row as $cell ) {
				if ( is_string( $cell ) && false !== stripos( $cell, 'Express Saver Document Rates:' ) ) {
					return true;
				}
			}
		}
		return false;
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
	 * Normalize rate group alias to standard canonical identifier.
	 *
	 * @param string $rate_group Raw rate group.
	 * @return string Canonical rate group.
	 */
	public function normalize_rate_group( $rate_group ) {
		$clean = strtolower( trim( (string) $rate_group ) );
		if ( isset( self::RATE_GROUP_ALIASES[ $clean ] ) ) {
			return self::RATE_GROUP_ALIASES[ $clean ];
		}
		return $clean;
	}

	/**
	 * Parse rates from original UPS format XLSX rows.
	 *
	 * Scans for the 4 section headers, detects zone columns, and parses all weight brackets.
	 *
	 * @param array $rows 2D rows from XLSX.
	 * @return array List of normalized rate records.
	 */
	public function parse_ups_format( array $rows ) {
		$records            = [];
		$current_rate_group = null;
		$zone_columns       = [];
		$weight_col_idx     = null;
		$sort_order         = 0;

		$total_rows = count( $rows );

		for ( $i = 0; $i < $total_rows; $i++ ) {
			$row = $rows[ $i ];

			// Check for section label in any cell of this row
			$found_label_group = null;
			$is_stop_label     = false;

			foreach ( $row as $cell_idx => $cell_val ) {
				$val_trimmed = trim( (string) $cell_val );

				if ( false !== stripos( $val_trimmed, 'Accessorial Surcharge' ) ) {
					$is_stop_label = true;
					break;
				}

				foreach ( self::UPS_RATE_LABELS as $label => $group_id ) {
					if ( 0 === strcasecmp( $val_trimmed, $label ) || false !== stripos( $val_trimmed, $label ) ) {
						$found_label_group = $group_id;
						break 2;
					}
				}
			}

			if ( $is_stop_label ) {
				$current_rate_group = null;
				$zone_columns       = [];
				continue;
			}

			if ( $found_label_group ) {
				$current_rate_group = $found_label_group;
				$zone_columns       = [];
				$weight_col_idx     = null;
				continue;
			}

			if ( ! $current_rate_group ) {
				continue;
			}

			// If current rate group is active, but we haven't found zone headers yet:
			if ( empty( $zone_columns ) ) {
				$detected_zones = [];
				foreach ( $row as $col_idx => $cell_val ) {
					$cell_str = trim( (string) $cell_val );
					if ( in_array( $cell_str, [ '1', '2', '3', '4', '5', '6', '7', '8', '9', '10' ], true ) ) {
						$detected_zones[ $col_idx ] = $cell_str;
					} elseif ( 0 === strcasecmp( preg_replace( '/\s+/', '', $cell_str ), 'US5' ) ) {
						$detected_zones[ $col_idx ] = 'US5';
					}
				}

				if ( count( $detected_zones ) >= 10 ) {
					$zone_columns   = $detected_zones;
					// Determine weight col: the cell containing '(kg)' or right before the first zone col
					$first_zone_col = min( array_keys( $zone_columns ) );
					$weight_col_idx = max( 0, $first_zone_col - 1 );
				}
				continue;
			}

			// We have an active rate group and zone headers. Read rate row:
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
					'rate_group'   => $current_rate_group,
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
	 * Parse rates from template CSV rows.
	 *
	 * @param array $rows Array of associative rows from CSV parser.
	 * @return array List of normalized rate records.
	 */
	public function parse_csv_template( array $rows ) {
		$records    = [];
		$sort_order = 0;

		foreach ( $rows as $row ) {
			$raw_group = isset( $row['rate_group'] ) ? $row['rate_group'] : '';
			if ( '' === trim( (string) $raw_group ) ) {
				continue;
			}

			$rate_group   = $this->normalize_rate_group( $raw_group );
			$weight_label = trim( (string) ( isset( $row['weight_label'] ) ? $row['weight_label'] : '' ) );

			if ( '' === $weight_label ) {
				continue;
			}

			// Normalize weight parameters
			$normalized_weight = $this->normalize_weight( $weight_label );

			$weight_from = ( isset( $row['weight_from'] ) && '' !== trim( (string) $row['weight_from'] ) )
				? (float) $row['weight_from']
				: $normalized_weight['weight_from'];

			$weight_to = ( isset( $row['weight_to'] ) && '' !== trim( (string) $row['weight_to'] ) )
				? (float) $row['weight_to']
				: $normalized_weight['weight_to'];

			$billing_unit = ( ! empty( $row['billing_unit'] ) && in_array( $row['billing_unit'], [ 'flat', 'per_kg', 'minimum', 'envelope' ], true ) )
				? $row['billing_unit']
				: $normalized_weight['billing_unit'];

			// Extract 10 zones + US5
			for ( $z = 1; $z <= 10; $z++ ) {
				$col_key = 'zone_' . $z;
				if ( isset( $row[ $col_key ] ) && '' !== trim( (string) $row[ $col_key ] ) ) {
					$price = (int) round( (float) str_replace( [ ',', ' ' ], '', (string) $row[ $col_key ] ) );
					$records[] = [
						'rate_group'   => $rate_group,
						'zone'         => (string) $z,
						'weight_label' => $weight_label,
						'weight_from'  => $weight_from,
						'weight_to'    => $weight_to,
						'billing_unit' => $billing_unit,
						'price_vnd'    => $price,
						'sort_order'   => ++$sort_order,
					];
				}
			}

			if ( isset( $row['zone_us5'] ) && '' !== trim( (string) $row['zone_us5'] ) ) {
				$price = (int) round( (float) str_replace( [ ',', ' ' ], '', (string) $row['zone_us5'] ) );
				$records[] = [
					'rate_group'   => $rate_group,
					'zone'         => 'US5',
					'weight_label' => $weight_label,
					'weight_from'  => $weight_from,
					'weight_to'    => $weight_to,
					'billing_unit' => $billing_unit,
					'price_vnd'    => $price,
					'sort_order'   => ++$sort_order,
				];
			}
		}

		return $records;
	}

	/**
	 * Parse rates from template XLSX rows.
	 *
	 * @param array $rows Raw 2D array from XLSX.
	 * @return array
	 */
	public function parse_xlsx_template( array $rows ) {
		if ( empty( $rows ) ) {
			return [];
		}

		$header_row = array_shift( $rows );
		$headers    = array_map( function( $h ) {
			return strtolower( trim( (string) $h ) );
		}, $header_row );

		$assoc_rows = [];
		foreach ( $rows as $row ) {
			$assoc = [];
			foreach ( $headers as $idx => $key ) {
				$assoc[ $key ] = isset( $row[ $idx ] ) ? $row[ $idx ] : '';
			}
			$assoc_rows[] = $assoc;
		}

		return $this->parse_csv_template( $assoc_rows );
	}

	/**
	 * Parse file based on detected format.
	 *
	 * @param string $file_path File path.
	 * @return array List of normalized rate records.
	 * @throws Exception On read or parse error.
	 */
	public function parse_file( $file_path ) {
		$format = $this->detect_format( $file_path );

		if ( 'csv_template' === $format ) {
			$rows = $this->csv_parser->parse( $file_path, self::EXPECTED_RATE_HEADERS );
			return $this->parse_csv_template( $rows );
		}

		if ( 'ups_xlsx' === $format ) {
			$rows = $this->xlsx_reader->parse( $file_path );
			return $this->parse_ups_format( $rows );
		}

		if ( 'xlsx_template' === $format ) {
			$rows = $this->xlsx_reader->parse( $file_path );
			return $this->parse_xlsx_template( $rows );
		}

		throw new Exception( 'Unsupported format: ' . esc_html( $format ) );
	}

	/**
	 * Validate parsed rate records.
	 *
	 * Checks:
	 * - 4 rate groups present
	 * - Zones 1-10 + US5 present
	 * - Positive numeric prices
	 *
	 * @param array $records List of rate records.
	 * @return array Validation result array.
	 */
	public function validate( array $records ) {
		$errors      = [];
		$warnings    = [];
		$found_groups = [];
		$found_zones  = [];

		if ( empty( $records ) ) {
			$errors[] = 'No rate records parsed from file.';
			return [
				'is_valid'    => false,
				'errors'      => $errors,
				'warnings'    => $warnings,
				'rate_groups' => [],
				'zones'       => [],
				'total_rates' => 0,
			];
		}

		foreach ( $records as $idx => $r ) {
			$group = isset( $r['rate_group'] ) ? $r['rate_group'] : '';
			$zone  = isset( $r['zone'] ) ? $r['zone'] : '';
			$price = isset( $r['price_vnd'] ) ? $r['price_vnd'] : 0;
			$label = isset( $r['weight_label'] ) ? $r['weight_label'] : '';

			if ( $group ) {
				$found_groups[ $group ] = true;
			}
			if ( $zone ) {
				$found_zones[ $zone ] = true;
			}

			if ( ! is_numeric( $price ) || $price <= 0 ) {
				$errors[] = sprintf(
					'Invalid price (%s) for rate group %s, zone %s, weight %s at record %d.',
					(string) $price,
					$group,
					$zone,
					$label,
					$idx + 1
				);
			}
		}

		// Check 4 required rate groups
		foreach ( self::REQUIRED_RATE_GROUPS as $req_group ) {
			if ( empty( $found_groups[ $req_group ] ) ) {
				$errors[] = sprintf( 'Missing required rate group: %s.', $req_group );
			}
		}

		// Check required zones 1-10 + US5
		foreach ( self::REQUIRED_ZONES as $req_zone ) {
			if ( empty( $found_zones[ $req_zone ] ) ) {
				$errors[] = sprintf( 'Missing required zone: %s.', $req_zone );
			}
		}

		$zones_list = array_map( 'strval', array_keys( $found_zones ) );

		return [
			'is_valid'    => empty( $errors ),
			'errors'      => $errors,
			'warnings'    => $warnings,
			'rate_groups' => array_values( array_map( 'strval', array_keys( $found_groups ) ) ),
			'zones'       => array_values( $zones_list ),
			'total_rates' => count( $records ),
		];
	}

	/**
	 * Preview mode: parses and validates file, returns detailed summary without DB commit.
	 *
	 * @param string $file_path Path to uploaded rate file.
	 * @return array Preview summary report.
	 */
	public function preview( $file_path ) {
		try {
			$format     = $this->detect_format( $file_path );
			$records    = $this->parse_file( $file_path );
			$validation = $this->validate( $records );

			// Summarize by rate group
			$group_summaries = [];
			foreach ( $records as $r ) {
				$g = $r['rate_group'];
				if ( ! isset( $group_summaries[ $g ] ) ) {
					$group_summaries[ $g ] = [
						'rate_group'     => $g,
						'count'          => 0,
						'weight_brackets' => [],
					];
				}
				$group_summaries[ $g ]['count']++;
				$w_label = $r['weight_label'];
				if ( ! in_array( $w_label, $group_summaries[ $g ]['weight_brackets'], true ) ) {
					$group_summaries[ $g ]['weight_brackets'][] = $w_label;
				}
			}

			return [
				'status'          => $validation['is_valid'] ? 'success' : 'error',
				'format_detected' => $format,
				'total_rates'     => count( $records ),
				'rate_groups'     => array_values( $group_summaries ),
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
	 * @return array Import result summary.
	 * @throws Exception If validation fails or database error occurs.
	 */
	public function import( $file_path, $rate_card_id ) {
		$rate_card_id = abs( (int) $rate_card_id );
		if ( ! $rate_card_id ) {
			throw new Exception( 'A valid rate_card_id is required for rate import.' );
		}

		$records    = $this->parse_file( $file_path );
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
			'status'       => 'success',
			'imported'     => $inserted_count,
			'total_rates'  => count( $records ),
			'rate_card_id' => $rate_card_id,
			'rate_groups'  => $validation['rate_groups'],
			'zones'        => $validation['zones'],
		];
	}
}
