<?php
/**
 * Fired during plugin activation and schema migrations.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Activator {

	/**
	 * Database schema version.
	 */
	const DB_VERSION = '1.3.0';

	/**
	 * Run activation tasks: create/update tables and version tracking.
	 */
	public static function activate() {
		self::migrate();
		self::create_quote_page();
	}

	/**
	 * Execute dbDelta migrations for the 7 custom tables.
	 */
	public static function migrate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$sql = "CREATE TABLE {$prefix}ups_zone_sets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  source_file_name VARCHAR(255) NULL,
  source_file_hash CHAR(64) NULL,
  record_count INT UNSIGNED DEFAULT 0,
  created_at DATETIME NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  PRIMARY KEY  (id)
) {$charset_collate};

CREATE TABLE {$prefix}ups_rate_cards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  zone_set_id BIGINT UNSIGNED NULL,
  market_code VARCHAR(10) NOT NULL DEFAULT 'VN',
  valid_from DATE NULL,
  source_file_name VARCHAR(255) NULL,
  source_file_hash CHAR(64) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  enabled_directions LONGTEXT NULL,
  disabled_rate_groups LONGTEXT NULL,
  imported_at DATETIME NOT NULL,
  activated_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY zone_set (zone_set_id),
  KEY market_status (market_code, status)
) {$charset_collate};

CREATE TABLE {$prefix}ups_countries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  iata_code VARCHAR(10) NOT NULL,
  country_name VARCHAR(255) NOT NULL,
  normalized_name VARCHAR(255) NULL,
  is_us_override TINYINT(1) NOT NULL DEFAULT 0,
  has_extended_area_note TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY iata_code (iata_code),
  KEY country_name (country_name)
) {$charset_collate};

CREATE TABLE {$prefix}ups_zone_maps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  zone_set_id BIGINT UNSIGNED NOT NULL,
  country_id BIGINT UNSIGNED NOT NULL,
  direction VARCHAR(10) NOT NULL,
  service_code VARCHAR(10) NOT NULL,
  service_type VARCHAR(20) NULL,
  zone VARCHAR(10) NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY unique_zone (zone_set_id, country_id, direction, service_code),
  KEY lookup_zone (zone_set_id, direction, service_code, country_id),
  KEY zone (zone)
) {$charset_collate};

CREATE TABLE {$prefix}ups_rates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rate_card_id BIGINT UNSIGNED NOT NULL,
  rate_group VARCHAR(50) NOT NULL,
  zone VARCHAR(10) NOT NULL,
  weight_label VARCHAR(50) NOT NULL,
  weight_from DECIMAL(10,3) NULL,
  weight_to DECIMAL(10,3) NULL,
  billing_unit VARCHAR(20) NOT NULL DEFAULT 'flat',
  price_vnd BIGINT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY unique_rate (rate_card_id, rate_group, zone, weight_label),
  KEY lookup_rate (rate_card_id, rate_group, zone, weight_from, weight_to),
  KEY billing_unit (billing_unit)
) {$charset_collate};

CREATE TABLE {$prefix}ups_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(100) NOT NULL,
  setting_value LONGTEXT NULL,
  autoload TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY setting_key (setting_key)
) {$charset_collate};

CREATE TABLE {$prefix}ups_quote_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rate_card_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  session_id VARCHAR(100) NULL,
  ip_address VARCHAR(45) NULL,
  device_type VARCHAR(20) NULL,
  user_agent VARCHAR(255) NULL,
  direction VARCHAR(10) NOT NULL,
  origin_iata VARCHAR(10) NOT NULL DEFAULT 'VN',
  origin_province VARCHAR(100) NULL,
  destination_iata VARCHAR(10) NOT NULL,
  destination_state VARCHAR(100) NULL,
  destination_city VARCHAR(100) NULL,
  destination_postal_code VARCHAR(30) NULL,
  destination_address VARCHAR(255) NULL,
  service_code VARCHAR(10) NOT NULL,
  shipment_type VARCHAR(30) NULL,
  zone VARCHAR(10) NULL,
  rate_zone VARCHAR(10) NULL,
  actual_weight_kg DECIMAL(10,3) NULL,
  dim_weight_kg DECIMAL(10,3) NULL,
  chargeable_weight_kg DECIMAL(10,3) NULL,
  base_price_vnd BIGINT UNSIGNED NULL,
  total_price_vnd BIGINT UNSIGNED NULL,
  pieces_json LONGTEXT NULL,
  breakdown_json LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY destination (destination_iata),
  KEY service_code (service_code),
  KEY ip_address (ip_address)
) {$charset_collate};

CREATE TABLE {$prefix}ups_quote_leads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quote_ref VARCHAR(30) NOT NULL,
  quote_log_id BIGINT UNSIGNED NULL,
  contact_name VARCHAR(150) NOT NULL,
  company_name VARCHAR(255) NULL,
  email VARCHAR(100) NOT NULL,
  phone VARCHAR(30) NULL,
  source VARCHAR(50) DEFAULT 'pdf_export',
  direction VARCHAR(10) NOT NULL DEFAULT 'export',
  service_code VARCHAR(20) NOT NULL,
  origin_country VARCHAR(10) DEFAULT 'VN',
  destination_iata VARCHAR(10) NOT NULL,
  destination_name VARCHAR(150) NULL,
  chargeable_weight_kg DECIMAL(10,3) NOT NULL,
  total_price_vnd BIGINT UNSIGNED NOT NULL,
  quote_data_json LONGTEXT NOT NULL,
  pdf_path VARCHAR(255) NULL,
  email_sent TINYINT(1) DEFAULT 0,
  email_sent_at DATETIME NULL,
  download_count INT UNSIGNED DEFAULT 1,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY quote_ref (quote_ref),
  KEY email (email),
  KEY phone (phone),
  KEY created_at (created_at)
) {$charset_collate};";

		dbDelta( $sql );

		// Run inline schema transitions for existing databases if needed
		if ( $wpdb ) {
			// Check ups_quote_logs for new columns (ip_address, device_type, user_agent)
			$logs_table = $prefix . 'ups_quote_logs';
			$has_logs   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) );
			if ( $has_logs ) {
				$cols = $wpdb->get_col( "DESC `{$logs_table}`", 0 );
				if ( is_array( $cols ) ) {
					if ( ! in_array( 'ip_address', $cols, true ) ) {
						$wpdb->query( "ALTER TABLE `{$logs_table}` ADD COLUMN ip_address VARCHAR(45) NULL AFTER session_id" );
					}
					if ( ! in_array( 'device_type', $cols, true ) ) {
						$wpdb->query( "ALTER TABLE `{$logs_table}` ADD COLUMN device_type VARCHAR(20) NULL AFTER ip_address" );
					}
					if ( ! in_array( 'user_agent', $cols, true ) ) {
						$wpdb->query( "ALTER TABLE `{$logs_table}` ADD COLUMN user_agent VARCHAR(255) NULL AFTER device_type" );
					}
				}
			}

			// Check rate_cards for zone_set_id
			$rate_card_table = $prefix . 'ups_rate_cards';
			$has_table       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rate_card_table ) );
			if ( $has_table ) {
				$cols = $wpdb->get_col( "DESC `{$rate_card_table}`", 0 );
				if ( is_array( $cols ) && ! in_array( 'zone_set_id', $cols, true ) ) {
					$wpdb->query( "ALTER TABLE `{$rate_card_table}` ADD COLUMN zone_set_id BIGINT UNSIGNED NULL AFTER name" );
				}
			}

			// Check ups_zone_maps for unassigned records or empty zone sets
			$zone_set_table = $prefix . 'ups_zone_sets';
			$zone_map_table = $prefix . 'ups_zone_maps';
			$has_zm_table   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $zone_map_table ) );
			$has_zs_table   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $zone_set_table ) );

			if ( $has_zm_table && $has_zs_table ) {
				$zs_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$zone_set_table}`" );
				$unassigned_zm = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$zone_map_table}` WHERE zone_set_id = 0 OR zone_set_id IS NULL" );

				if ( 0 === $zs_count && $unassigned_zm > 0 ) {
					$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
					$wpdb->insert(
						$zone_set_table,
						[
							'name'             => 'Zone UPS Chuẩn 2026',
							'description'      => 'Bảng phân vùng mặc định chuyển đổi từ dữ liệu đã import',
							'source_file_name' => 'Zone chart.csv',
							'record_count'     => $unassigned_zm,
							'created_at'       => $now,
						],
						[ '%s', '%s', '%s', '%d', '%s' ]
					);
					$default_zs_id = $wpdb->insert_id ?: 1;

					$wpdb->query( "UPDATE `{$zone_map_table}` SET zone_set_id = {$default_zs_id} WHERE zone_set_id = 0 OR zone_set_id IS NULL" );
					if ( $has_table ) {
						$wpdb->query( "UPDATE `{$rate_card_table}` SET zone_set_id = {$default_zs_id} WHERE zone_set_id = 0 OR zone_set_id IS NULL" );
					}
				}
			}
		}

		self::insert_default_settings();

		update_option( 'allship_ups_db_version', self::DB_VERSION );
	}

	/**
	 * Insert default plugin settings if not already present.
	 */
	public static function insert_default_settings() {
		global $wpdb;

		$table = $wpdb->prefix . 'ups_settings';

		$defaults = [
			'dim_divisor'         => '5500',
			'rounding_step_kg'    => '0.5',
			'include_vat'         => 'false',
			'vat_percent'         => '0',
			'include_fsc'         => 'false',
			'fsc_percent'         => '0',
			'include_surge'       => 'false',
			'surge_percent'       => '0',
			'include_customs_fee' => 'false',
			'customs_fee_vnd'     => '10000',
		];

		// Check existing keys to avoid duplicates (idempotent).
		$existing_keys = $wpdb->get_col( "SELECT setting_key FROM {$table}" );
		if ( ! is_array( $existing_keys ) ) {
			$existing_keys = [];
		}

		$now = current_time( 'mysql' );

		foreach ( $defaults as $key => $value ) {
			if ( ! in_array( $key, $existing_keys, true ) ) {
				$wpdb->insert(
					$table,
					[
						'setting_key'   => $key,
						'setting_value' => $value,
						'autoload'      => 1,
						'updated_at'    => $now,
					],
					[ '%s', '%s', '%d', '%s' ]
				);
			}
		}
	}

	/**
	 * Create the quote form page if it doesn't already exist.
	 */
	public static function create_quote_page() {
		$page_id = get_option( 'allship_ups_quote_page_id' );

		// Page already exists and is valid.
		if ( $page_id && get_post_status( $page_id ) ) {
			return;
		}

		$page_id = wp_insert_post( [
			'post_title'   => 'Báo giá UPS',
			'post_name'    => 'bao-gia-ups',
			'post_content' => '[ups_quote_form]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => get_current_user_id() ?: 1,
		] );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'allship_ups_quote_page_id', $page_id );
		}
	}
}
