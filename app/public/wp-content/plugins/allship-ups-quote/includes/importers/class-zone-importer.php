<?php
/**
 * Zone Importer for UPS country and zone data.
 *
 * Supports pivot CSV templates and original flat XLSX format (IATA.xlsx).
 * Handles format auto-detection, flat-to-pivot transformation, ZZ skipping,
 * US override detection, and extended area note flags.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Zone_Importer {

	/**
	 * Expected CSV headers for pivot template format.
	 */
	const EXPECTED_PIVOT_HEADERS = [
		'iata_code',
		'country_name',
		'direction',
		'wxs',
		'xpd',
		'wfm',
		'exw',
		'xpr',
		'wxp',
	];

	/**
	 * All 6 supported UPS service codes.
	 */
	const SUPPORTED_SERVICES = [
		'EXW',
		'WFM',
		'WXP',
		'WXS',
		'XPD',
		'XPR',
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
	 * Country Repository instance.
	 *
	 * @var Allship_UPS_Country_Repository
	 */
	private $country_repo;

	/**
	 * Zone Repository instance.
	 *
	 * @var Allship_UPS_Zone_Repository
	 */
	private $zone_repo;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_CSV_Parser|null         $csv_parser Optional parser.
	 * @param Allship_UPS_XLSX_Reader|null        $xlsx_reader Optional reader.
	 * @param Allship_UPS_Country_Repository|null $country_repo Optional country repo.
	 * @param Allship_UPS_Zone_Repository|null    $zone_repo Optional zone repo.
	 */
	public function __construct( $csv_parser = null, $xlsx_reader = null, $country_repo = null, $zone_repo = null ) {
		$this->csv_parser   = $csv_parser ?: new Allship_UPS_CSV_Parser();
		$this->xlsx_reader  = $xlsx_reader ?: new Allship_UPS_XLSX_Reader();
		$this->country_repo = $country_repo ?: new Allship_UPS_Country_Repository();
		$this->zone_repo    = $zone_repo ?: new Allship_UPS_Zone_Repository();
	}

	/**
	 * Auto-detect zone file format.
	 *
	 * @param string $file_path Path to uploaded zone file.
	 * @return string 'pivot_template', 'flat_xlsx', or 'flat_csv'.
	 * @throws Exception When file is unreadable or format is unknown.
	 */
	public function detect_format( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			throw new Exception( 'File not found: ' . esc_html( $file_path ) );
		}

		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		if ( 'csv' === $ext ) {
			$handle = fopen( $file_path, 'r' );
			if ( ! $handle ) {
				throw new Exception( 'Cannot open CSV file: ' . esc_html( $file_path ) );
			}
			$header = fgetcsv( $handle );
			fclose( $handle );

			if ( empty( $header ) ) {
				throw new Exception( 'Empty CSV file.' );
			}

			$headers_lower = array_map( function( $h ) {
				return strtolower( trim( (string) $h ) );
			}, $header );

			if ( in_array( 'sn', $headers_lower, true ) && in_array( 'cty', $headers_lower, true ) && in_array( 'mvn', $headers_lower, true ) ) {
				return 'flat_csv';
			}

			if ( in_array( 'iata_code', $headers_lower, true ) || in_array( 'iata', $headers_lower, true ) ) {
				return 'pivot_template';
			}

			throw new Exception( 'Unrecognized CSV zone format. Expected pivot template or flat UPS format.' );
		}

		if ( 'xlsx' === $ext ) {
			$rows = $this->xlsx_reader->parse( $file_path );
			if ( empty( $rows ) ) {
				throw new Exception( 'Empty XLSX workbook.' );
			}

			$header = array_map( function( $h ) {
				return strtolower( trim( (string) $h ) );
			}, $rows[0] );

			if ( in_array( 'sn', $header, true ) && in_array( 'cty', $header, true ) && in_array( 'mvn', $header, true ) ) {
				return 'flat_xlsx';
			}

			if ( in_array( 'iata_code', $header, true ) || in_array( 'iata', $header, true ) ) {
				return 'pivot_template';
			}

			throw new Exception( 'Unrecognized XLSX zone format.' );
		}

		throw new Exception( 'Unsupported file extension: .' . $ext );
	}

	/**
	 * Parse flat format rows (from IATA.xlsx or flat CSV).
	 *
	 * Skips IATA = 'ZZ', extracts unique countries, detects US override and extended area notes,
	 * and transforms multi-row service zone entries into normalized records.
	 *
	 * @param array $rows 2D array of rows from reader.
	 * @return array Array containing 'countries' and 'zones'.
	 */
	public function parse_flat_format( array $rows ) {
		if ( empty( $rows ) ) {
			return [
				'countries' => [],
				'zones'     => [],
			];
		}

		$header_row = array_shift( $rows );
		$col_indices = [];
		foreach ( $header_row as $idx => $name ) {
			$col_indices[ strtolower( trim( (string) $name ) ) ] = $idx;
		}

		$iata_idx     = isset( $col_indices['iata'] ) ? $col_indices['iata'] : 2;
		$country_idx  = isset( $col_indices['rate guide country name'] ) ? $col_indices['rate guide country name'] : ( isset( $col_indices['country'] ) ? $col_indices['country'] : 3 );
		$svc_type_idx = isset( $col_indices['svc type'] ) ? $col_indices['svc type'] : 4;
		$mvn_idx      = isset( $col_indices['mvn'] ) ? $col_indices['mvn'] : 5;

		$countries = [];
		$zones     = [];

		foreach ( $rows as $row ) {
			$raw_iata = isset( $row[ $iata_idx ] ) ? strtoupper( trim( (string) $row[ $iata_idx ] ) ) : '';

			// Skip empty and ZZ
			if ( empty( $raw_iata ) || 'ZZ' === $raw_iata ) {
				continue;
			}

			$country_name = isset( $row[ $country_idx ] ) ? trim( (string) $row[ $country_idx ] ) : '';
			$svc_type     = isset( $row[ $svc_type_idx ] ) ? trim( (string) $row[ $svc_type_idx ] ) : null;
			$mvn          = isset( $row[ $mvn_idx ] ) ? strtolower( trim( (string) $row[ $mvn_idx ] ) ) : 'export';

			// Record country
			if ( ! isset( $countries[ $raw_iata ] ) ) {
				$countries[ $raw_iata ] = [
					'iata_code'              => $raw_iata,
					'country_name'           => $country_name,
					'normalized_name'        => trim( str_replace( '*', '', $country_name ) ),
					'is_us_override'         => ( 'US' === $raw_iata ) ? 1 : 0,
					'has_extended_area_note' => ( false !== strpos( $country_name, '*' ) ) ? 1 : 0,
					'is_active'              => 1,
				];
			}

			// Parse services
			foreach ( self::SUPPORTED_SERVICES as $svc ) {
				$svc_key = strtolower( $svc );
				if ( isset( $col_indices[ $svc_key ] ) ) {
					$col_i = $col_indices[ $svc_key ];
					$zone_val = intval( isset( $row[ $col_i ] ) ? $row[ $col_i ] : 0 );
					if ( $zone_val > 0 ) {
						$zones[] = [
							'iata_code'    => $raw_iata,
							'country_name' => $country_name,
							'direction'    => $mvn,
							'service_code' => $svc,
							'service_type' => $svc_type,
							'zone'         => (string) $zone_val,
							'is_available' => 1,
						];
					}
				}
			}
		}

		return [
			'countries' => array_values( $countries ),
			'zones'     => $zones,
		];
	}

	/**
	 * Parse pivot CSV template rows.
	 *
	 * @param array $rows Associative rows from CSV parser.
	 * @return array Array containing 'countries' and 'zones'.
	 */
	public function parse_pivot_template( array $rows ) {
		$countries = [];
		$zones     = [];

		foreach ( $rows as $row ) {
			$raw_iata = '';
			if ( isset( $row['iata_code'] ) ) {
				$raw_iata = strtoupper( trim( (string) $row['iata_code'] ) );
			} elseif ( isset( $row['iata'] ) ) {
				$raw_iata = strtoupper( trim( (string) $row['iata'] ) );
			}

			if ( empty( $raw_iata ) || 'ZZ' === $raw_iata ) {
				continue;
			}

			$country_name = '';
			if ( isset( $row['country_name'] ) ) {
				$country_name = trim( (string) $row['country_name'] );
			} elseif ( isset( $row['country'] ) ) {
				$country_name = trim( (string) $row['country'] );
			}

			$direction = isset( $row['direction'] ) ? strtolower( trim( (string) $row['direction'] ) ) : 'export';
			if ( ! in_array( $direction, [ 'export', 'import' ], true ) ) {
				$direction = 'export';
			}

			if ( ! isset( $countries[ $raw_iata ] ) ) {
				$countries[ $raw_iata ] = [
					'iata_code'              => $raw_iata,
					'country_name'           => $country_name,
					'normalized_name'        => trim( str_replace( '*', '', $country_name ) ),
					'is_us_override'         => ( 'US' === $raw_iata ) ? 1 : 0,
					'has_extended_area_note' => ( false !== strpos( $country_name, '*' ) ) ? 1 : 0,
					'is_active'              => 1,
				];
			}

			foreach ( self::SUPPORTED_SERVICES as $svc ) {
				$svc_key = strtolower( $svc );
				$val     = isset( $row[ $svc_key ] ) ? $row[ $svc_key ] : ( isset( $row[ $svc ] ) ? $row[ $svc ] : 0 );
				$zone_val = intval( $val );

				if ( $zone_val > 0 ) {
					$svc_type = ( 'WFM' === $svc || 'WXP' === $svc ) ? 'Freight' : 'Package';
					$zones[]  = [
						'iata_code'    => $raw_iata,
						'country_name' => $country_name,
						'direction'    => $direction,
						'service_code' => $svc,
						'service_type' => $svc_type,
						'zone'         => (string) $zone_val,
						'is_available' => 1,
					];
				}
			}
		}

		return [
			'countries' => array_values( $countries ),
			'zones'     => $zones,
		];
	}

	/**
	 * Parse file by detecting format.
	 *
	 * @param string $file_path Path to file.
	 * @return array Parsed data containing 'countries' and 'zones'.
	 * @throws Exception On failure.
	 */
	public function parse_file( $file_path ) {
		$format = $this->detect_format( $file_path );

		if ( 'flat_xlsx' === $format ) {
			$rows = $this->xlsx_reader->parse( $file_path );
			return $this->parse_flat_format( $rows );
		}

		if ( 'flat_csv' === $format ) {
			$rows = [];
			$handle = fopen( $file_path, 'r' );
			while ( ( $row = fgetcsv( $handle ) ) !== false ) {
				$rows[] = $row;
			}
			fclose( $handle );
			return $this->parse_flat_format( $rows );
		}

		if ( 'pivot_template' === $format ) {
			$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
			if ( 'csv' === $ext ) {
				$rows = $this->csv_parser->parse( $file_path );
				return $this->parse_pivot_template( $rows );
			}
			$raw_rows = $this->xlsx_reader->parse( $file_path );
			$header   = array_shift( $raw_rows );
			$assoc    = [];
			foreach ( $raw_rows as $r ) {
				$row_a = [];
				foreach ( $header as $idx => $k ) {
					$row_a[ strtolower( trim( (string) $k ) ) ] = isset( $r[ $idx ] ) ? $r[ $idx ] : '';
				}
				$assoc[] = $row_a;
			}
			return $this->parse_pivot_template( $assoc );
		}

		throw new Exception( 'Unsupported format: ' . esc_html( $format ) );
	}

	/**
	 * Validate parsed country and zone data.
	 *
	 * Checks:
	 * - ZZ is skipped
	 * - US exists with is_us_override = 1
	 * - US has extended area note flag (has_extended_area_note = 1)
	 * - US has zone 5 for WXS, XPD, and WFM in export
	 * - WFM import has 0 available countries
	 *
	 * @param array $parsed_data Array containing 'countries' and 'zones'.
	 * @return array Validation result report.
	 */
	public function validate( array $parsed_data ) {
		$errors   = [];
		$warnings = [];

		$countries = isset( $parsed_data['countries'] ) ? $parsed_data['countries'] : [];
		$zones     = isset( $parsed_data['zones'] ) ? $parsed_data['zones'] : [];

		if ( empty( $countries ) ) {
			$errors[] = 'No countries found in parsed data.';
			return [
				'is_valid'         => false,
				'errors'           => $errors,
				'warnings'         => $warnings,
				'total_countries'  => 0,
				'total_zone_maps'  => 0,
				'services_summary' => [],
			];
		}

		// 1. Verify ZZ is excluded
		foreach ( $countries as $c ) {
			if ( 'ZZ' === $c['iata_code'] ) {
				$errors[] = 'ZZ was not skipped in countries list.';
			}
		}
		foreach ( $zones as $z ) {
			if ( 'ZZ' === $z['iata_code'] ) {
				$errors[] = 'ZZ was not skipped in zone mappings.';
			}
		}

		// 2. Verify US country attributes
		$us_country = null;
		foreach ( $countries as $c ) {
			if ( 'US' === $c['iata_code'] ) {
				$us_country = $c;
				break;
			}
		}

		if ( ! $us_country ) {
			$errors[] = 'United States (US) country record not found.';
		} else {
			if ( 1 !== $us_country['is_us_override'] ) {
				$errors[] = 'US is_us_override must be 1.';
			}
			if ( 1 !== $us_country['has_extended_area_note'] ) {
				$errors[] = 'US has_extended_area_note must be 1 (due to * in United States*).';
			}
		}

		// 3. Verify US export zones for WXS, XPD, WFM
		$us_zones = [];
		foreach ( $zones as $z ) {
			if ( 'US' === $z['iata_code'] && 'export' === $z['direction'] ) {
				$us_zones[ $z['service_code'] ] = $z['zone'];
			}
		}

		foreach ( [ 'WXS', 'XPD', 'WFM' ] as $req_svc ) {
			if ( ! isset( $us_zones[ $req_svc ] ) ) {
				$errors[] = "US export zone mapping for {$req_svc} missing.";
			} elseif ( '5' !== (string) $us_zones[ $req_svc ] ) {
				$errors[] = "US export zone for {$req_svc} must be '5', got '{$us_zones[ $req_svc ]}'.";
			}
		}

		// 4. Summarize available countries per service & direction
		$services_summary = [];
		foreach ( [ 'export', 'import' ] as $dir ) {
			foreach ( self::SUPPORTED_SERVICES as $svc ) {
				$services_summary[ $dir ][ $svc ] = 0;
			}
		}

		foreach ( $zones as $z ) {
			$dir = $z['direction'];
			$svc = $z['service_code'];
			if ( isset( $services_summary[ $dir ][ $svc ] ) ) {
				$services_summary[ $dir ][ $svc ]++;
			}
		}

		// 5. Verify WFM import count = 0
		if ( isset( $services_summary['import']['WFM'] ) && $services_summary['import']['WFM'] > 0 ) {
			$warnings[] = sprintf(
				'WFM import has %d available countries (expected 0 per current UPS rate agreement).',
				$services_summary['import']['WFM']
			);
		}

		return [
			'is_valid'         => empty( $errors ),
			'errors'           => $errors,
			'warnings'         => $warnings,
			'total_countries'  => count( $countries ),
			'total_zone_maps'  => count( $zones ),
			'services_summary' => $services_summary,
		];
	}

	/**
	 * Preview mode: parses and validates file, returns detailed summary without DB commit.
	 *
	 * @param string $file_path Path to uploaded zone file.
	 * @return array Preview summary report.
	 */
	public function preview( $file_path ) {
		try {
			$format      = $this->detect_format( $file_path );
			$parsed_data = $this->parse_file( $file_path );
			$validation  = $this->validate( $parsed_data );

			return [
				'status'           => $validation['is_valid'] ? 'success' : 'error',
				'format_detected'  => $format,
				'total_countries'  => $validation['total_countries'],
				'total_zone_maps'  => $validation['total_zone_maps'],
				'services_summary' => $validation['services_summary'],
				'errors'           => $validation['errors'],
				'warnings'         => $validation['warnings'],
			];
		} catch ( Exception $e ) {
			return [
				'status'           => 'error',
				'format_detected'  => 'unknown',
				'total_countries'  => 0,
				'total_zone_maps'  => 0,
				'services_summary' => [],
				'errors'           => [ $e->getMessage() ],
				'warnings'         => [],
			];
		}
	}

	/**
	 * Import countries and zone mappings into database.
	 *
	 * 1. Upserts all countries into ups_countries.
	 * 2. Fetches country IDs.
	 * 3. Bulk inserts zone mappings into ups_zone_maps for the specified rate_card_id.
	 *
	 * @param string $file_path Path to uploaded zone file.
	 * @param int    $rate_card_id Target rate card ID.
	 * @return array Import result summary.
	 * @throws Exception If validation fails or DB insert fails.
	 */
	public function import( $file_path, $rate_card_id ) {
		$rate_card_id = abs( (int) $rate_card_id );
		if ( ! $rate_card_id ) {
			throw new Exception( 'A valid rate_card_id is required for zone import.' );
		}

		$parsed_data = $this->parse_file( $file_path );
		$validation  = $this->validate( $parsed_data );

		if ( ! $validation['is_valid'] ) {
			throw new Exception( 'Zone validation failed: ' . implode( '; ', $validation['errors'] ) );
		}

		// 1. Bulk upsert countries
		$countries_upserted = $this->country_repo->bulk_upsert( $parsed_data['countries'] );

		// 2. Fetch all countries to map iata_code -> country_id
		$countries_db = $this->country_repo->get_all();
		$iata_to_id   = [];
		foreach ( $countries_db as $c ) {
			$iata_to_id[ $c->iata_code ] = $c->id;
		}

		// 3. Assemble zone records with country_id and rate_card_id
		$zone_rows = [];
		foreach ( $parsed_data['zones'] as $z ) {
			$iata = $z['iata_code'];
			if ( ! isset( $iata_to_id[ $iata ] ) ) {
				continue;
			}

			$zone_rows[] = [
				'rate_card_id' => $rate_card_id,
				'country_id'   => $iata_to_id[ $iata ],
				'direction'    => $z['direction'],
				'service_code' => $z['service_code'],
				'service_type' => isset( $z['service_type'] ) ? $z['service_type'] : null,
				'zone'         => $z['zone'],
				'is_available' => isset( $z['is_available'] ) ? $z['is_available'] : 1,
			];
		}

		// 4. Bulk insert zone mappings
		$zones_inserted = $this->zone_repo->bulk_insert( $zone_rows );

		return [
			'status'              => 'success',
			'countries_processed' => count( $parsed_data['countries'] ),
			'countries_upserted'  => $countries_upserted,
			'zones_processed'     => count( $zone_rows ),
			'zones_inserted'      => $zones_inserted,
			'rate_card_id'        => $rate_card_id,
			'services_summary'    => $validation['services_summary'],
		];
	}
}
