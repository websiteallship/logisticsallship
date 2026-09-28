<?php
/**
 * Import Orchestrator for managing rate & zone imports.
 *
 * Coordinates file uploads, pre-flight validation, preview mode,
 * draft rate card creation, multi-importer execution, and atomic rollback.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Import_Orchestrator {

	/**
	 * Max upload file size: 10MB.
	 */
	const MAX_FILE_SIZE = 10485760; // 10 * 1024 * 1024 bytes

	/**
	 * Allowed file extensions.
	 */
	const ALLOWED_EXTENSIONS = [
		'csv',
		'xlsx',
	];

	/**
	 * Permitted MIME types.
	 */
	const ALLOWED_MIME_TYPES = [
		'text/csv',
		'text/plain',
		'application/csv',
		'text/x-csv',
		'application/x-csv',
		'text/comma-separated-values',
		'text/x-comma-separated-values',
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/zip',
		'application/octet-stream',
	];

	/**
	 * Rate Importer instance.
	 *
	 * @var Allship_UPS_Rate_Importer
	 */
	private $rate_importer;

	/**
	 * Zone Importer instance.
	 *
	 * @var Allship_UPS_Zone_Importer
	 */
	private $zone_importer;

	/**
	 * Rate Card Repository.
	 *
	 * @var Allship_UPS_Rate_Card_Repository
	 */
	private $rate_card_repo;

	/**
	 * Rate Repository.
	 *
	 * @var Allship_UPS_Rate_Repository
	 */
	private $rate_repo;

	/**
	 * Zone Repository.
	 *
	 * @var Allship_UPS_Zone_Repository
	 */
	/**
	 * Zone Set Repository.
	 *
	 * @var Allship_UPS_Zone_Set_Repository
	 */
	private $zone_set_repo;

	/**
	 * Database instance.
	 *
	 * @var wpdb|null
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Rate_Importer|null      $rate_importer Optional rate importer.
	 * @param Allship_UPS_Zone_Importer|null      $zone_importer Optional zone importer.
	 * @param Allship_UPS_Rate_Card_Repository|null $rate_card_repo Optional rate card repo.
	 * @param Allship_UPS_Rate_Repository|null    $rate_repo Optional rate repo.
	 * @param Allship_UPS_Zone_Repository|null    $zone_repo Optional zone repo.
	 * @param Allship_UPS_Zone_Set_Repository|null $zone_set_repo Optional zone set repo.
	 * @param wpdb|null                           $wpdb Optional wpdb instance.
	 */
	public function __construct(
		$rate_importer = null,
		$zone_importer = null,
		$rate_card_repo = null,
		$rate_repo = null,
		$zone_repo = null,
		$zone_set_repo = null,
		$wpdb = null
	) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb           = $wpdb;
		$this->rate_importer  = $rate_importer ?: ( class_exists( 'Allship_UPS_Rate_Importer' ) ? new Allship_UPS_Rate_Importer() : null );
		$this->zone_importer  = $zone_importer ?: ( class_exists( 'Allship_UPS_Zone_Importer' ) ? new Allship_UPS_Zone_Importer() : null );
		$this->rate_card_repo = $rate_card_repo ?: ( class_exists( 'Allship_UPS_Rate_Card_Repository' ) ? new Allship_UPS_Rate_Card_Repository( $this->wpdb ) : null );
		$this->rate_repo      = $rate_repo ?: ( class_exists( 'Allship_UPS_Rate_Repository' ) ? new Allship_UPS_Rate_Repository( $this->wpdb ) : null );
		$this->zone_repo      = $zone_repo ?: ( class_exists( 'Allship_UPS_Zone_Repository' ) ? new Allship_UPS_Zone_Repository( $this->wpdb ) : null );
		$this->zone_set_repo  = $zone_set_repo ?: ( class_exists( 'Allship_UPS_Zone_Set_Repository' ) ? new Allship_UPS_Zone_Set_Repository( $this->wpdb ) : null );
	}

	/**
	 * Get private storage directory for file uploads.
	 *
	 * @return string Absolute directory path ending with slash.
	 */
	public function get_private_upload_dir() {
		$dir = '';
		if ( defined( 'ALLSHIP_UPS_QUOTE_PATH' ) ) {
			$dir = ALLSHIP_UPS_QUOTE_PATH . 'private/uploads/';
		} else {
			$dir = sys_get_temp_dir() . '/allship-ups-private/';
		}

		if ( ! file_exists( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			} else {
				@mkdir( $dir, 0755, true );
			}

			// Secure directory with index.php and .htaccess
			if ( file_exists( $dir ) ) {
				@file_put_contents( $dir . 'index.php', '<?php // Silence is golden' );
				@file_put_contents( $dir . '.htaccess', "Deny from all\n" );
			}
		}

		return $dir;
	}

	/**
	 * Handle file upload securely.
	 *
	 * Validates MIME type, file extension, and file size (<= 10MB).
	 * Moves file to secure private directory and calculates SHA256 checksum.
	 *
	 * @param array $file_array Standard $_FILES entry.
	 * @return array Upload metadata [file_path, source_file_name, source_file_hash, file_size].
	 * @throws Exception On invalid payload, size limit exceeded, or disallowed MIME.
	 */
	public function handle_upload( array $file_array ) {
		if ( ! isset( $file_array['error'] ) || UPLOAD_ERR_OK !== $file_array['error'] ) {
			$code = isset( $file_array['error'] ) ? (int) $file_array['error'] : -1;
			throw new Exception( 'File upload failed with error code ' . $code . '.' );
		}

		$size = isset( $file_array['size'] ) ? (int) $file_array['size'] : 0;
		if ( $size <= 0 || $size > self::MAX_FILE_SIZE ) {
			throw new Exception( 'File too large. Maximum allowed size is 10MB.' );
		}

		$filename = isset( $file_array['name'] ) ? (string) $file_array['name'] : '';
		$ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
			throw new Exception( 'Invalid file extension (.' . esc_html( $ext ) . '). Only CSV and XLSX files are permitted.' );
		}

		// Validate MIME
		$mime = isset( $file_array['type'] ) ? strtolower( trim( $file_array['type'] ) ) : '';
		if ( function_exists( 'mime_content_type' ) && ! empty( $file_array['tmp_name'] ) && file_exists( $file_array['tmp_name'] ) ) {
			$detected_mime = @mime_content_type( $file_array['tmp_name'] );
			if ( $detected_mime ) {
				$mime = strtolower( trim( $detected_mime ) );
			}
		}

		if ( ! empty( $mime ) && ! in_array( $mime, self::ALLOWED_MIME_TYPES, true ) ) {
			throw new Exception( 'Invalid file MIME type (' . esc_html( $mime ) . '). Only CSV and XLSX files are permitted.' );
		}

		$upload_dir = $this->get_private_upload_dir();
		$safe_name  = function_exists( 'sanitize_file_name' )
			? sanitize_file_name( $filename )
			: preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', $filename );

		$unique_file_name = uniqid( 'ups_', true ) . '-' . $safe_name;
		$target_path      = $upload_dir . $unique_file_name;

		$moved = false;
		if ( is_uploaded_file( $file_array['tmp_name'] ) ) {
			$moved = @move_uploaded_file( $file_array['tmp_name'], $target_path );
		} else {
			$moved = @copy( $file_array['tmp_name'], $target_path );
		}

		if ( ! $moved || ! file_exists( $target_path ) ) {
			throw new Exception( 'Failed to store uploaded file in private directory.' );
		}

		$sha256 = hash_file( 'sha256', $target_path );

		return [
			'file_path'        => $target_path,
			'source_file_name' => $filename,
			'source_file_hash' => $sha256,
			'file_size'        => $size,
		];
	}

	/**
	 * Preview mode: aggregates rate & zone previews without database writes.
	 *
	/**
	 * Preview mode: aggregates rate & zone previews without database writes.
	 *
	 * Supports rate-only, zone-only, or combined preview.
	 *
	 * @param string|null $rate_file_path Absolute path to rate file.
	 * @param string|null $zone_file_path Optional absolute path to zone file.
	 * @return array Consolidated preview summary.
	 * @throws Exception If no files provided.
	 */
	public function preview( $rate_file_path = null, $zone_file_path = null, $rate_group = 'export_wxs_nondocument' ) {
		if ( empty( $rate_file_path ) && empty( $zone_file_path ) ) {
			throw new Exception( 'Cần ít nhất một file Biểu phí hoặc Phân vùng để kiểm tra.' );
		}

		$rate_preview = null;
		if ( $rate_file_path && file_exists( $rate_file_path ) ) {
			$rate_preview = $this->rate_importer->preview( $rate_file_path, $rate_group );
		}

		$zone_preview = null;
		if ( $zone_file_path && file_exists( $zone_file_path ) ) {
			$zone_preview = $this->zone_importer->preview( $zone_file_path );
		}

		$rate_errors   = $rate_preview ? $rate_preview['errors'] : [];
		$zone_errors   = $zone_preview ? $zone_preview['errors'] : [];
		$errors        = array_merge( $rate_errors, $zone_errors );

		$rate_warnings = $rate_preview ? $rate_preview['warnings'] : [];
		$zone_warnings = $zone_preview ? $zone_preview['warnings'] : [];
		$warnings      = array_merge( $rate_warnings, $zone_warnings );

		$is_rate_valid = $rate_preview ? ( 'success' === $rate_preview['status'] && empty( $rate_errors ) ) : true;
		$is_zone_valid = $zone_preview ? ( 'success' === $zone_preview['status'] && empty( $zone_errors ) ) : true;
		$is_valid      = $is_rate_valid && $is_zone_valid && ( null !== $rate_preview || null !== $zone_preview );

		return [
			'status'        => $is_valid ? 'success' : 'error',
			'rates'         => $rate_preview,
			'zones'         => $zone_preview,
			'errors'        => $errors,
			'warnings'      => $warnings,
			'is_db_written' => false,
		];
	}

	/**
	 * Execute full atomic import.
	 *
	 * 1. Pre-flight validation & preview.
	 * 2. Starts DB transaction.
	 * 3. Creates rate card record with status 'draft' (if rate file provided).
	 * 4. Imports rates via Rate_Importer (if rate file provided).
	 * 5. Imports zones via Zone_Importer (if zone file provided).
	 * 6. Commits transaction.
	 * 7. On any failure: rolls back transaction, deletes rate card and cascading data, rethrows.
	 * 8. Optional file cleanup.
	 *
	 * @param array $params Import parameters:
	 *                      - rate_file_path (string, optional)
	 *                      - zone_file_path (string, optional)
	 *                      - rate_card_name (string, optional)
	 *                      - valid_from (string, optional)
	 *                      - source_file_name (string, optional)
	 *                      - source_file_hash (string, optional)
	 *                      - delete_files_after (bool, default false)
	 * @return array Import summary report.
	 * @throws Exception If pre-flight validation fails or any step errors.
	 */
	public function import( array $params ) {
		$rate_file_path     = isset( $params['rate_file_path'] ) ? $params['rate_file_path'] : '';
		$zone_file_path     = isset( $params['zone_file_path'] ) ? $params['zone_file_path'] : null;
		$rate_card_name     = isset( $params['rate_card_name'] ) ? $params['rate_card_name'] : '';
		$valid_from         = isset( $params['valid_from'] ) ? $params['valid_from'] : date( 'Y-m-d' );
		$source_file_name   = isset( $params['source_file_name'] ) ? $params['source_file_name'] : ( $rate_file_path ? basename( $rate_file_path ) : ( $zone_file_path ? basename( $zone_file_path ) : null ) );
		$source_file_hash   = isset( $params['source_file_hash'] ) ? $params['source_file_hash'] : ( file_exists( $rate_file_path ) ? hash_file( 'sha256', $rate_file_path ) : ( file_exists( $zone_file_path ) ? hash_file( 'sha256', $zone_file_path ) : null ) );
		$delete_files_after = isset( $params['delete_files_after'] ) ? (bool) $params['delete_files_after'] : false;
		$rate_group         = isset( $params['rate_group'] ) ? $params['rate_group'] : 'export_wxs_nondocument';

		if ( empty( $rate_file_path ) && empty( $zone_file_path ) ) {
			throw new Exception( 'Cần ít nhất một file Biểu phí hoặc Phân vùng để thực hiện import.' );
		}

		// 1. Pre-flight Validation & Preview
		$preview_result = $this->preview( $rate_file_path, $zone_file_path, $rate_group );
		if ( 'error' === $preview_result['status'] || ! empty( $preview_result['errors'] ) ) {
			throw new Exception( 'Validation failed before import: ' . implode( '; ', $preview_result['errors'] ) );
		}

		$created_rate_card_id = null;
		$created_zone_set_id  = null;
		$rate_summary         = null;
		$zone_summary         = null;

		// 2. Start Transaction
		if ( $this->wpdb ) {
			$this->wpdb->query( 'START TRANSACTION' );
		}

		try {
			$effective_zone_set_id = ! empty( $params['zone_set_id'] ) ? abs( (int) $params['zone_set_id'] ) : null;

			// 3. Import Zones if new zone file is provided
			if ( $zone_file_path && file_exists( $zone_file_path ) ) {
				$zs_name = ! empty( $params['zone_set_name'] )
					? $params['zone_set_name']
					: ( ! empty( $params['new_zone_set_name'] )
						? $params['new_zone_set_name']
						: ( ! empty( $rate_card_name )
							? ( 'Zone - ' . $rate_card_name )
							: ( 'UPS Zone Set ' . date( 'Y-m-d H:i' ) ) ) );

				$zs_desc = isset( $params['zone_set_description'] )
					? $params['zone_set_description']
					: ( ! empty( $rate_card_name ) ? ( 'Tạo cùng bảng giá ' . $rate_card_name ) : null );

				if ( $this->zone_set_repo ) {
					$created_zone_set_id = $this->zone_set_repo->create( [
						'name'             => $zs_name,
						'description'      => $zs_desc,
						'source_file_name' => basename( $zone_file_path ),
						'source_file_hash' => hash_file( 'sha256', $zone_file_path ),
					] );
					if ( ! $created_zone_set_id ) {
						throw new Exception( 'Không thể tạo Zone Set mới trong database.' );
					}
				} else {
					$created_zone_set_id = 1;
				}

				$zone_summary          = $this->zone_importer->import( $zone_file_path, $created_zone_set_id );
				$effective_zone_set_id = $created_zone_set_id;
			}

			// 4. Import Rates & Create Rate Card (if rate file provided)
			if ( ! empty( $rate_file_path ) && file_exists( $rate_file_path ) ) {
				// Fallback to default or first available zone set if none selected or created
				if ( ! $effective_zone_set_id && $this->zone_set_repo ) {
					$default_zs = $this->zone_set_repo->get_default_or_first();
					if ( $default_zs ) {
						$effective_zone_set_id = (int) $default_zs->id;
					}
				}

				$direction = ( 0 === strpos( $rate_group, 'import_' ) ) ? 'import' : 'export';

				$card_data = [
					'name'                 => ! empty( $rate_card_name ) ? $rate_card_name : ( 'UPS Rate Card ' . date( 'Y-m-d H:i:s' ) ),
					'zone_set_id'          => $effective_zone_set_id,
					'market_code'          => 'VN',
					'valid_from'           => $valid_from,
					'source_file_name'     => $source_file_name,
					'source_file_hash'     => $source_file_hash,
					'status'               => 'draft',
					'enabled_directions'   => [ $direction ],
					'disabled_rate_groups' => [],
				];

				$created_rate_card_id = $this->rate_card_repo->create( $card_data );
				if ( ! $created_rate_card_id ) {
					throw new Exception( 'Failed to create draft rate card in database.' );
				}

				$rate_summary = $this->rate_importer->import( $rate_file_path, $created_rate_card_id, $rate_group );
			}

			// 5. Commit Transaction
			if ( $this->wpdb ) {
				$this->wpdb->query( 'COMMIT' );
			}

			// 6. Cleanup temporary files if requested
			if ( $delete_files_after ) {
				if ( $rate_file_path && file_exists( $rate_file_path ) ) {
					@unlink( $rate_file_path );
				}
				if ( $zone_file_path && file_exists( $zone_file_path ) ) {
					@unlink( $zone_file_path );
				}
			}

			return [
				'status'       => 'success',
				'rate_card_id' => $created_rate_card_id,
				'zone_set_id'  => $effective_zone_set_id,
				'card_status'  => $created_rate_card_id ? 'draft' : null,
				'rate_summary' => $rate_summary,
				'zone_summary' => $zone_summary,
			];
		} catch ( Exception $e ) {
			// Rollback Transaction
			if ( $this->wpdb ) {
				$this->wpdb->query( 'ROLLBACK' );
			}

			// Explicit cascade deletion of card and created zone set
			if ( $created_rate_card_id ) {
				$this->rate_card_repo->delete( $created_rate_card_id );
			}
			if ( $created_zone_set_id && $this->zone_set_repo ) {
				$this->zone_set_repo->delete( $created_zone_set_id, true );
			}

			// Cleanup temporary files if requested
			if ( $delete_files_after ) {
				if ( file_exists( $rate_file_path ) ) {
					@unlink( $rate_file_path );
				}
				if ( $zone_file_path && file_exists( $zone_file_path ) ) {
					@unlink( $zone_file_path );
				}
			}

			throw $e;
		}
	}
}
