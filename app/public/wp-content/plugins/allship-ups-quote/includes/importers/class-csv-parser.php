<?php
/**
 * CSV Parser for importing rates and zones.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_CSV_Parser {

	/**
	 * Parse CSV file into an array of associative row arrays.
	 *
	 * Handles UTF-8 BOM, validates expected headers, and trims content.
	 *
	 * @param string $file_path Path to the CSV file.
	 * @param array  $expected_headers List of header names that must be present.
	 * @param string $delimiter CSV delimiter character. Default ','.
	 * @return array Array of associative rows.
	 * @throws Exception If file cannot be read, is empty, or lacks expected headers.
	 */
	public function parse( $file_path, array $expected_headers = [], $delimiter = ',' ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new Exception( 'FILE_NOT_READABLE: Cannot read file at ' . $file_path );
		}

		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			throw new Exception( 'FILE_OPEN_FAILED: Unable to open ' . $file_path );
		}

		// Handle UTF-8 BOM
		$bom = fread( $handle, 3 );
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $handle );
		}

		$headers = fgetcsv( $handle, 0, $delimiter );
		if ( false === $headers || empty( $headers ) ) {
			fclose( $handle );
			throw new Exception( 'EMPTY_CSV_FILE: No header row found in file.' );
		}

		// Clean possible residual BOM from first header token
		$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] );
		$headers    = array_map( 'trim', $headers );

		// Validate headers
		if ( ! empty( $expected_headers ) ) {
			$this->validate_headers( $headers, $expected_headers, $handle );
		}

		$rows = [];
		while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			// Skip completely empty lines
			if ( 1 === count( $row ) && null === $row[0] ) {
				continue;
			}

			// Clean and combine row with headers
			$row_count    = count( $row );
			$header_count = count( $headers );

			if ( $row_count === $header_count ) {
				$rows[] = array_combine( $headers, array_map( 'trim', $row ) );
			} elseif ( $row_count > 0 ) {
				// Normalize column count
				$padded = array_pad( array_map( 'trim', $row ), $header_count, '' );
				$rows[] = array_combine( $headers, array_slice( $padded, 0, $header_count ) );
			}
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Validate that all required headers are present.
	 *
	 * @param array    $headers Parsed file headers.
	 * @param array    $expected_headers Expected headers.
	 * @param resource $handle Open file handle.
	 * @return void
	 * @throws Exception If required headers are missing.
	 */
	private function validate_headers( array $headers, array $expected_headers, $handle ) {
		$missing       = [];
		$headers_lower = array_map( 'strtolower', $headers );

		foreach ( $expected_headers as $expected ) {
			if ( ! in_array( strtolower( trim( $expected ) ), $headers_lower, true ) ) {
				$missing[] = $expected;
			}
		}

		if ( ! empty( $missing ) ) {
			fclose( $handle );
			throw new Exception( 'MISSING_HEADERS: ' . implode( ', ', $missing ) );
		}
	}
}
