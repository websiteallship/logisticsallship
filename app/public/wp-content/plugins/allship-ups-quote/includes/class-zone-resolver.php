<?php
/**
 * Zone Resolver for UPS service lanes and destinations.
 *
 * Resolves destination zone mappings, applies ADR-004 US5 override,
 * and enforces structured error codes: UNKNOWN_DESTINATION,
 * LANE_NOT_AVAILABLE, and SERVICE_NOT_SUPPORTED.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object representing zone resolution outcome.
 */
class Allship_UPS_Zone_Result {

	/**
	 * Whether resolution was successful.
	 *
	 * @var bool
	 */
	public $success = false;

	/**
	 * Transport direction ('export' or 'import').
	 *
	 * @var string
	 */
	public $direction = 'export';

	/**
	 * Service code (e.g. 'WXS', 'XPD', 'WFM').
	 *
	 * @var string
	 */
	public $service_code = '';

	/**
	 * 2-letter destination IATA code.
	 *
	 * @var string
	 */
	public $destination_iata = '';

	/**
	 * Destination country ID.
	 *
	 * @var int|null
	 */
	public $country_id = null;

	/**
	 * Destination country full name.
	 *
	 * @var string
	 */
	public $country_name = '';

	/**
	 * Physical mapped zone string (e.g. '5', '7').
	 *
	 * @var string|null
	 */
	public $zone = null;

	/**
	 * Effective pricing column zone string (e.g. 'US5' for US, or '5', '7').
	 *
	 * @var string|null
	 */
	public $rate_zone = null;

	/**
	 * Whether US5 rate column override was applied.
	 *
	 * @var bool
	 */
	public $is_us_override = false;

	/**
	 * Whether destination country contains extended area note (*).
	 *
	 * @var bool
	 */
	public $has_extended_area_note = false;

	/**
	 * Target rate card ID.
	 *
	 * @var int|null
	 */
	public $rate_card_id = null;

	/**
	 * Standardized error code if resolution failed.
	 *
	 * @var string|null
	 */
	public $error_code = null;

	/**
	 * Human-readable error explanation if failed.
	 *
	 * @var string|null
	 */
	public $error_message = null;

	/**
	 * Constructor.
	 *
	 * @param array $data Attributes.
	 */
	public function __construct( array $data = [] ) {
		foreach ( $data as $key => $val ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $val;
			}
		}
	}

	/**
	 * Check whether this result is an error.
	 *
	 * @return bool
	 */
	public function is_error() {
		return ! $this->success;
	}
}

class Allship_UPS_Zone_Resolver {

	/**
	 * Supported services in Phase 1 (Export parcel/freight).
	 */
	const SUPPORTED_PHASE1_SERVICES = [
		'WXS',
		'XPD',
		'WFM',
	];

	/**
	 * Zone repository instance.
	 *
	 * @var Allship_UPS_Zone_Repository
	 */
	private $zone_repo;

	/**
	 * Country repository instance.
	 *
	 * @var Allship_UPS_Country_Repository
	 */
	private $country_repo;

	/**
	 * Settings manager instance.
	 *
	 * @var Allship_UPS_Settings_Manager|null
	 */
	private $settings;

	/**
	 * Service availability manager instance.
	 *
	 * @var Allship_UPS_Service_Availability_Manager|null
	 */
	private $service_mgr;

	/**
	 * Rate card repository instance.
	 *
	 * @var Allship_UPS_Rate_Card_Repository|null
	 */
	private $rate_card_repo;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Zone_Repository|null                  $zone_repo Zone repo.
	 * @param Allship_UPS_Country_Repository|null               $country_repo Country repo.
	 * @param Allship_UPS_Settings_Manager|null                 $settings Settings manager.
	 * @param Allship_UPS_Service_Availability_Manager|null     $service_mgr Service availability mgr.
	 * @param Allship_UPS_Rate_Card_Repository|null             $rate_card_repo Rate card repo.
	 */
	public function __construct(
		$zone_repo = null,
		$country_repo = null,
		$settings = null,
		$service_mgr = null,
		$rate_card_repo = null
	) {
		$this->zone_repo      = $zone_repo ?: new Allship_UPS_Zone_Repository();
		$this->country_repo   = $country_repo ?: new Allship_UPS_Country_Repository();
		$this->settings       = $settings ?: ( class_exists( 'Allship_UPS_Settings_Manager' ) ? new Allship_UPS_Settings_Manager() : null );
		$this->service_mgr    = $service_mgr ?: ( class_exists( 'Allship_UPS_Service_Availability_Manager' ) ? new Allship_UPS_Service_Availability_Manager() : null );
		$this->rate_card_repo = $rate_card_repo ?: ( class_exists( 'Allship_UPS_Rate_Card_Repository' ) ? new Allship_UPS_Rate_Card_Repository() : null );
	}

	/**
	 * Resolve zone and effective rate_zone for a shipment destination.
	 *
	 * Implements ADR-004:
	 * - United States (US) has actual physical zone 5, but rate_zone is 'US5'.
	 * - Other countries (CA, MX, etc.) have actual zone 5 and rate_zone '5'.
	 *
	 * Enforces error codes:
	 * - SERVICE_NOT_SUPPORTED: Service not open in Phase 1 (e.g. XPR, EXW, WXP).
	 * - UNKNOWN_DESTINATION: IATA code not in ups_countries table.
	 * - LANE_NOT_AVAILABLE: Country zone is 0 or unavailable for requested service.
	 *
	 * @param string   $direction 'export' or 'import'.
	 * @param string   $service_code UPS service code ('WXS', 'XPD', 'WFM').
	 * @param string   $destination_iata 2-letter IATA code ('US', 'CA', etc.).
	 * @param int|null $rate_card_id Target rate card ID. Defaults to active card.
	 * @return Allship_UPS_Zone_Result
	 */
	public function resolve( $direction, $service_code, $destination_iata, $rate_card_id = null ) {
		$direction        = strtolower( trim( (string) $direction ) );
		$service_code     = strtoupper( trim( (string) $service_code ) );
		$destination_iata = strtoupper( trim( (string) $destination_iata ) );
		$rate_card_id     = abs( (int) $rate_card_id );

		if ( empty( $direction ) ) {
			$direction = 'export';
		}

		// 1. Check supported service
		if ( ! in_array( $service_code, self::SUPPORTED_PHASE1_SERVICES, true ) ) {
			return new Allship_UPS_Zone_Result( [
				'success'          => false,
				'direction'        => $direction,
				'service_code'     => $service_code,
				'destination_iata' => $destination_iata,
				'error_code'       => 'SERVICE_NOT_SUPPORTED',
				'error_message'    => "Dịch vụ '{$service_code}' chưa được hỗ trợ trong phiên bản hiện tại.",
			] );
		}

		// 2. Lookup destination country
		$country = $this->country_repo->find_by_iata( $destination_iata );
		if ( ! $country ) {
			return new Allship_UPS_Zone_Result( [
				'success'          => false,
				'direction'        => $direction,
				'service_code'     => $service_code,
				'destination_iata' => $destination_iata,
				'error_code'       => 'UNKNOWN_DESTINATION',
				'error_message'    => "Quốc gia hoặc mã IATA '{$destination_iata}' không tồn tại trong hệ thống.",
			] );
		}

		// 3. Resolve rate card ID if not supplied
		if ( ! $rate_card_id && $this->rate_card_repo ) {
			$active_card = $this->rate_card_repo->get_active();
			if ( $active_card ) {
				$rate_card_id = (int) $active_card->id;
			}
		}

		if ( ! $rate_card_id ) {
			return new Allship_UPS_Zone_Result( [
				'success'          => false,
				'direction'        => $direction,
				'service_code'     => $service_code,
				'destination_iata' => $destination_iata,
				'error_code'       => 'RATE_CARD_NOT_FOUND',
				'error_message'    => 'Không tìm thấy bảng giá nào đang hoạt động.',
			] );
		}

		// 4. Query physical zone from zone repository
		$raw_zone = $this->zone_repo->find_zone( $rate_card_id, $country->id, $direction, $service_code );

		if ( null === $raw_zone || '' === trim( $raw_zone ) || '0' === trim( $raw_zone ) ) {
			return new Allship_UPS_Zone_Result( [
				'success'          => false,
				'direction'        => $direction,
				'service_code'     => $service_code,
				'destination_iata' => $destination_iata,
				'country_id'       => (int) $country->id,
				'country_name'     => $country->country_name,
				'error_code'       => 'LANE_NOT_AVAILABLE',
				'error_message'    => "Tuyến dịch vụ '{$service_code}' không khả dụng tới quốc gia '{$country->country_name}' ({$destination_iata}).",
			] );
		}

		$zone_clean = trim( (string) $raw_zone );

		// 5. ADR-004 US5 override check
		$is_us = ( 'US' === $destination_iata || ( isset( $country->is_us_override ) && 1 === (int) $country->is_us_override ) );
		$rate_zone = $is_us ? 'US5' : $zone_clean;

		return new Allship_UPS_Zone_Result( [
			'success'                => true,
			'direction'              => $direction,
			'service_code'           => $service_code,
			'destination_iata'       => $destination_iata,
			'country_id'             => (int) $country->id,
			'country_name'           => $country->country_name,
			'zone'                   => $zone_clean,
			'rate_zone'              => $rate_zone,
			'is_us_override'         => $is_us,
			'has_extended_area_note' => ! empty( $country->has_extended_area_note ),
			'rate_card_id'           => $rate_card_id,
			'error_code'             => null,
			'error_message'          => null,
		] );
	}
}
