<?php
/**
 * Settings Manager for Allship UPS Quote plugin.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Settings_Manager {

	/**
	 * Database instance.
	 *
	 * @var wpdb|null
	 */
	private $wpdb;

	/**
	 * Settings table name with prefix.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * In-memory static cache for request-level caching.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $cache = null;

	/**
	 * Predefined schema for typing and sanitization.
	 *
	 * @var array<string, string>
	 */
	private static $schema = [
		'dim_divisor'              => 'int',
		'rounding_step_kg'         => 'float',
		'include_vat'              => 'bool',
		'vat_percent'              => 'float',
		'include_fsc'              => 'bool',
		'fsc_percent'              => 'float',
		'include_surge'            => 'bool',
		'surge_percent'            => 'float',
		'include_customs_fee'      => 'bool',
		'customs_fee_vnd'          => 'int',
		'delete_data_on_uninstall' => 'bool',
	];

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
		$this->table = $this->wpdb ? $this->wpdb->prefix . 'ups_settings' : '';
	}

	/**
	 * Load settings from database into memory cache.
	 *
	 * @return void
	 */
	private function load_cache() {
		if ( null !== self::$cache ) {
			return;
		}

		self::$cache = [];

		if ( ! $this->wpdb || empty( $this->table ) ) {
			return;
		}

		$results = $this->wpdb->get_results(
			"SELECT setting_key, setting_value FROM {$this->table}",
			defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A'
		);

		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$key   = $row['setting_key'];
				$value = $this->cast_value( $key, $row['setting_value'] );
				self::$cache[ $key ] = $value;
			}
		}
	}

	/**
	 * Retrieve a setting value.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value if setting is not present.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$this->load_cache();

		if ( is_array( self::$cache ) && array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		return $default;
	}

	/**
	 * Set or update a setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $value Setting value.
	 * @param int    $autoload Autoload flag. Default 1.
	 * @return bool True on success, false on failure.
	 */
	public function set( $key, $value, $autoload = 1 ) {
		$key = function_exists( 'sanitize_key' ) ? sanitize_key( $key ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
		if ( empty( $key ) ) {
			return false;
		}

		list( $clean_value, $stored_value ) = $this->sanitize_and_format( $key, $value );

		if ( ! $this->wpdb || empty( $this->table ) ) {
			if ( null === self::$cache ) {
				self::$cache = [];
			}
			self::$cache[ $key ] = $clean_value;
			return true;
		}

		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

		$existing = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE setting_key = %s",
				$key
			)
		);

		if ( null !== $existing ) {
			$result = $this->wpdb->update(
				$this->table,
				[
					'setting_value' => $stored_value,
					'autoload'      => $autoload ? 1 : 0,
					'updated_at'    => $now,
				],
				[ 'setting_key' => $key ],
				[ '%s', '%d', '%s' ],
				[ '%s' ]
			);
		} else {
			$result = $this->wpdb->insert(
				$this->table,
				[
					'setting_key'   => $key,
					'setting_value' => $stored_value,
					'autoload'      => $autoload ? 1 : 0,
					'updated_at'    => $now,
				],
				[ '%s', '%s', '%d', '%s' ]
			);
		}

		if ( false !== $result ) {
			if ( null === self::$cache ) {
				self::$cache = [];
			}
			self::$cache[ $key ] = $clean_value;
			return true;
		}

		return false;
	}

	/**
	 * Delete a setting.
	 *
	 * @param string $key Setting key.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $key ) {
		$key = function_exists( 'sanitize_key' ) ? sanitize_key( $key ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
		if ( empty( $key ) ) {
			return false;
		}

		$this->load_cache();

		if ( is_array( self::$cache ) && array_key_exists( $key, self::$cache ) ) {
			unset( self::$cache[ $key ] );
		}

		if ( ! $this->wpdb || empty( $this->table ) ) {
			return true;
		}

		$result = $this->wpdb->delete(
			$this->table,
			[ 'setting_key' => $key ],
			[ '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Get all settings as key-value array.
	 *
	 * @return array<string, mixed>
	 */
	public function get_all() {
		$this->load_cache();
		return is_array( self::$cache ) ? self::$cache : [];
	}

	/**
	 * Clear in-memory cache.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		self::$cache = null;
	}

	/**
	 * Sanitize and format value for internal use and database storage.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $value Input value.
	 * @return array{0: mixed, 1: string}
	 */
	private function sanitize_and_format( $key, $value ) {
		$type = isset( self::$schema[ $key ] ) ? self::$schema[ $key ] : null;

		if ( 'int' === $type ) {
			$clean  = (int) $value;
			$stored = (string) $clean;
		} elseif ( 'float' === $type ) {
			$clean  = (float) $value;
			$stored = (string) $clean;
		} elseif ( 'bool' === $type ) {
			$clean  = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			$stored = $clean ? 'true' : 'false';
		} elseif ( 'array' === $type ) {
			$clean  = is_array( $value ) ? $value : [];
			$stored = function_exists( 'wp_json_encode' ) ? wp_json_encode( $clean ) : json_encode( $clean );
		} else {
			if ( is_bool( $value ) ) {
				$clean  = $value;
				$stored = $value ? 'true' : 'false';
			} elseif ( is_int( $value ) ) {
				$clean  = $value;
				$stored = (string) $value;
			} elseif ( is_float( $value ) ) {
				$clean  = $value;
				$stored = (string) $value;
			} elseif ( is_array( $value ) ) {
				$clean  = $value;
				$stored = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
			} else {
				$clean  = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );
				$stored = $clean;
			}
		}

		return [ $clean, $stored ];
	}

	/**
	 * Cast raw database string to appropriate PHP typed value.
	 *
	 * @param string      $key Setting key.
	 * @param string|null $raw Raw value from DB.
	 * @return mixed
	 */
	private function cast_value( $key, $raw ) {
		if ( null === $raw ) {
			return null;
		}

		$type = isset( self::$schema[ $key ] ) ? self::$schema[ $key ] : null;

		if ( 'int' === $type ) {
			return (int) $raw;
		}

		if ( 'float' === $type ) {
			return (float) $raw;
		}

		if ( 'bool' === $type ) {
			return filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
		}

		if ( 'array' === $type ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : [];
		}

		if ( 'true' === $raw ) {
			return true;
		}
		if ( 'false' === $raw ) {
			return false;
		}

		return $raw;
	}
}
