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
	const DB_VERSION = '1.0.0';

	/**
	 * Run activation tasks: create/update tables and version tracking.
	 */
	public static function activate() {
		self::migrate();
		self::create_quote_page();
	}

	/**
	 * Execute dbDelta migrations for the 6 custom tables.
	 */
	public static function migrate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$sql = "CREATE TABLE {$prefix}ups_rate_cards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
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
  rate_card_id BIGINT UNSIGNED NOT NULL,
  country_id BIGINT UNSIGNED NOT NULL,
  direction VARCHAR(10) NOT NULL,
  service_code VARCHAR(10) NOT NULL,
  service_type VARCHAR(20) NULL,
  zone VARCHAR(10) NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY unique_zone (rate_card_id, country_id, direction, service_code),
  KEY lookup_zone (rate_card_id, direction, service_code, country_id),
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
  KEY service_code (service_code)
) {$charset_collate};";

		dbDelta( $sql );

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
