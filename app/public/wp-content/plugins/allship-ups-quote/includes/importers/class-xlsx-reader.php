<?php
/**
 * XLSX Reader wrapper using SimpleXLSX.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_XLSX_Reader {

	/**
	 * Lazy-load SimpleXLSX library.
	 *
	 * @return void
	 * @throws Exception If library file cannot be found.
	 */
	private function ensure_library() {
		if ( ! class_exists( 'Shuchkin\SimpleXLSX' ) && ! class_exists( 'SimpleXLSX' ) ) {
			$lib_path = defined( 'ALLSHIP_UPS_QUOTE_PATH' )
				? ALLSHIP_UPS_QUOTE_PATH . 'libs/SimpleXLSX.php'
				: dirname( dirname( __DIR__ ) ) . '/libs/SimpleXLSX.php';

			if ( ! file_exists( $lib_path ) ) {
				throw new Exception( 'XLSX_LIB_NOT_FOUND: SimpleXLSX library not found at ' . $lib_path );
			}

			require_once $lib_path;
		}
	}

	/**
	 * Parse an Excel (.xlsx) file into a 2D array of rows.
	 *
	 * @param string      $file_path Path to the XLSX file.
	 * @param string|null $sheet Optional sheet name. Defaults to first sheet (index 0).
	 * @return array Array of row arrays.
	 * @throws Exception If file is corrupt, not readable, or sheet not found.
	 */
	public function parse( $file_path, $sheet = null ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new Exception( 'FILE_NOT_READABLE: Cannot read file at ' . $file_path );
		}

		$this->ensure_library();

		$class_name = class_exists( 'Shuchkin\SimpleXLSX' ) ? 'Shuchkin\SimpleXLSX' : 'SimpleXLSX';

		$xlsx = call_user_func( [ $class_name, 'parse' ], $file_path );
		if ( ! $xlsx ) {
			$error = call_user_func( [ $class_name, 'parseError' ] );
			throw new Exception( 'CORRUPT_XLSX: Failed to parse Excel file. ' . $error );
		}

		$sheet_names = $xlsx->sheetNames();
		$sheet_index = 0;

		if ( null !== $sheet && '' !== trim( $sheet ) ) {
			$target      = trim( $sheet );
			$found_index = null;

			foreach ( $sheet_names as $idx => $name ) {
				if ( 0 === strcasecmp( trim( $name ), $target ) ) {
					$found_index = $idx;
					break;
				}
			}

			if ( null === $found_index ) {
				throw new Exception(
					sprintf(
						"SHEET_NOT_FOUND: Sheet '%s' not found in file. Available sheets: %s",
						$sheet,
						implode( ', ', $sheet_names )
					)
				);
			}

			$sheet_index = $found_index;
		}

		$rows = $xlsx->rows( $sheet_index );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Retrieve list of sheet names from an XLSX file.
	 *
	 * @param string $file_path Path to the XLSX file.
	 * @return array Array of sheet names.
	 * @throws Exception If file cannot be parsed.
	 */
	public function get_sheets( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new Exception( 'FILE_NOT_READABLE: Cannot read file at ' . $file_path );
		}

		$this->ensure_library();

		$class_name = class_exists( 'Shuchkin\SimpleXLSX' ) ? 'Shuchkin\SimpleXLSX' : 'SimpleXLSX';
		$xlsx       = call_user_func( [ $class_name, 'parse' ], $file_path );

		if ( ! $xlsx ) {
			$error = call_user_func( [ $class_name, 'parseError' ] );
			throw new Exception( 'CORRUPT_XLSX: Failed to parse Excel file. ' . $error );
		}

		return $xlsx->sheetNames();
	}
}
