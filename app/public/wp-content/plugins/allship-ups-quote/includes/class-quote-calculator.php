<?php
/**
 * Quote Calculator Orchestrator.
 *
 * Implements the full quotation pipeline:
 * Input validation -> Rate card resolution -> Weight calculation ->
 * Zone resolution -> Rate lookup -> Surcharge calculation -> Quote logging.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured quote calculation result.
 */
class Allship_UPS_Quote_Result implements ArrayAccess, JsonSerializable {

	/**
	 * Whether quote calculation succeeded.
	 *
	 * @var bool
	 */
	public $success = true;

	/**
	 * Shipment direction ('export' or 'import').
	 *
	 * @var string
	 */
	public $direction = 'export';

	/**
	 * Origin IATA code (default 'VN').
	 *
	 * @var string
	 */
	public $origin_iata = 'VN';

	/**
	 * Origin province/city.
	 *
	 * @var string|null
	 */
	public $origin_province = 'TP. Hồ Chí Minh';

	/**
	 * Destination 2-letter IATA country code.
	 *
	 * @var string
	 */
	public $destination_iata = '';

	/**
	 * Official destination country name.
	 *
	 * @var string
	 */
	public $destination_name = '';

	/**
	 * Destination state/province code.
	 *
	 * @var string|null
	 */
	public $destination_state = null;

	/**
	 * Destination city.
	 *
	 * @var string|null
	 */
	public $destination_city = null;

	/**
	 * Destination postal/ZIP code.
	 *
	 * @var string|null
	 */
	public $destination_postal_code = null;

	/**
	 * Destination street address.
	 *
	 * @var string|null
	 */
	public $destination_address = null;

	/**
	 * UPS service code ('WXS', 'XPD', 'WFM', etc.).
	 *
	 * @var string
	 */
	public $service_code = '';

	/**
	 * Human-readable service name.
	 *
	 * @var string
	 */
	public $service_name = '';

	/**
	 * Shipment classification ('document' or 'nondocument').
	 *
	 * @var string
	 */
	public $shipment_type = 'nondocument';

	/**
	 * Physical destination zone.
	 *
	 * @var string
	 */
	public $zone = '';

	/**
	 * Effective pricing zone (e.g. 'US5', '5', '7').
	 *
	 * @var string
	 */
	public $rate_zone = '';

	/**
	 * Total actual gross weight in kg.
	 *
	 * @var float
	 */
	public $actual_weight_kg = 0.0;

	/**
	 * Total volumetric/dimensional weight in kg.
	 *
	 * @var float
	 */
	public $dim_weight_kg = 0.0;

	/**
	 * Total chargeable billable weight in kg.
	 *
	 * @var float
	 */
	public $chargeable_weight_kg = 0.0;

	/**
	 * Weight rounding step applied (default 0.5 kg).
	 *
	 * @var float
	 */
	public $rounding_step_kg = 0.5;

	/**
	 * Volumetric divisor applied (default 5500).
	 *
	 * @var int
	 */
	public $dim_divisor = 5500;

	/**
	 * Base freight price in VND.
	 *
	 * @var int
	 */
	public $base_price_vnd = 0;

	/**
	 * Active surcharges and fees.
	 *
	 * @var array
	 */
	public $fees = [];

	/**
	 * Grand total price in VND (base + fees).
	 *
	 * @var int
	 */
	public $total_price_vnd = 0;

	/**
	 * Currency code.
	 *
	 * @var string
	 */
	public $currency = 'VND';

	/**
	 * Quote notes and disclaimers.
	 *
	 * @var array<string>
	 */
	public $notes = [];

	/**
	 * Itemized piece weight breakdown.
	 *
	 * @var array
	 */
	public $pieces = [];

	/**
	 * Rate card ID used.
	 *
	 * @var int|null
	 */
	public $rate_card_id = null;

	/**
	 * Standardized error code if failed.
	 *
	 * @var string|null
	 */
	public $error_code = null;

	/**
	 * Human-readable error message.
	 *
	 * @var string|null
	 */
	public $error_message = null;

	/**
	 * Constructor.
	 *
	 * @param array $data Initial attributes.
	 */
	public function __construct( array $data = [] ) {
		foreach ( $data as $key => $val ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $val;
			}
		}
	}

	/**
	 * Static factory for an error result.
	 *
	 * @param string $code Standard error code.
	 * @param string $message User-friendly error message.
	 * @param array  $extra Optional additional attributes.
	 * @return self
	 */
	public static function error( string $code, string $message, array $extra = [] ): self {
		$extra['success']       = false;
		$extra['error_code']    = $code;
		$extra['error_message'] = $message;
		return new self( $extra );
	}

	/**
	 * Check whether this result represents a calculation error.
	 *
	 * @return bool
	 */
	public function is_error(): bool {
		return ! $this->success;
	}

	public function offsetExists( $offset ): bool {
		if ( 'data' === $offset || 'error' === $offset ) {
			return true;
		}
		return property_exists( $this, $offset );
	}

	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		if ( 'data' === $offset ) {
			return $this->get_data_array();
		}
		if ( 'error' === $offset ) {
			return $this->success ? null : [
				'code'    => $this->error_code,
				'message' => $this->error_message,
			];
		}
		return property_exists( $this, $offset ) ? $this->$offset : null;
	}

	public function offsetSet( $offset, $value ): void {
		if ( property_exists( $this, $offset ) ) {
			$this->$offset = $value;
		}
	}

	public function offsetUnset( $offset ): void {
		if ( property_exists( $this, $offset ) ) {
			$this->$offset = null;
		}
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this->to_array();
	}

	/**
	 * Convert to full API response structure.
	 *
	 * @return array
	 */
	public function to_array(): array {
		if ( ! $this->success ) {
			return [
				'success' => false,
				'error'   => [
					'code'    => $this->error_code,
					'message' => $this->error_message,
				],
			];
		}

		return [
			'success' => true,
			'data'    => $this->get_data_array(),
		];
	}

	/**
	 * Format data payload for successful response.
	 *
	 * @return array
	 */
	private function get_data_array(): array {
		$fees_array = [];
		foreach ( $this->fees as $fee ) {
			if ( is_object( $fee ) && method_exists( $fee, 'to_array' ) ) {
				$fees_array[] = $fee->to_array();
			} elseif ( is_array( $fee ) ) {
				$fees_array[] = $fee;
			}
		}

		return [
			'direction'               => $this->direction,
			'origin_iata'             => $this->origin_iata,
			'origin_province'         => $this->origin_province,
			'destination_iata'        => $this->destination_iata,
			'destination_name'        => $this->destination_name,
			'destination_state'       => $this->destination_state,
			'destination_city'        => $this->destination_city,
			'destination_postal_code' => $this->destination_postal_code,
			'destination_address'     => $this->destination_address,
			'service_code'            => $this->service_code,
			'service_name'            => $this->service_name,
			'shipment_type'           => $this->shipment_type,
			'zone'                    => $this->zone,
			'rate_zone'               => $this->rate_zone,
			'actual_weight_kg'        => round( (float) $this->actual_weight_kg, 3 ),
			'dim_weight_kg'           => round( (float) $this->dim_weight_kg, 3 ),
			'chargeable_weight_kg'    => round( (float) $this->chargeable_weight_kg, 3 ),
			'rounding_step_kg'        => (float) $this->rounding_step_kg,
			'dim_divisor'             => (int) $this->dim_divisor,
			'base_price_vnd'          => (int) $this->base_price_vnd,
			'fees'                    => $fees_array,
			'total_price_vnd'         => (int) $this->total_price_vnd,
			'currency'                => $this->currency,
			'notes'                   => $this->notes,
			'pieces'                  => $this->pieces,
		];
	}
}

/**
 * Main Quote Calculator orchestrating the whole quotation pipeline.
 */
class Allship_UPS_Quote_Calculator {

	/**
	 * Rate card repository.
	 *
	 * @var Allship_UPS_Rate_Card_Repository
	 */
	private $rate_card_repo;

	/**
	 * Country repository.
	 *
	 * @var Allship_UPS_Country_Repository
	 */
	private $country_repo;

	/**
	 * Zone resolver.
	 *
	 * @var Allship_UPS_Zone_Resolver
	 */
	private $zone_resolver;

	/**
	 * Weight calculator.
	 *
	 * @var Allship_UPS_Weight_Calculator
	 */
	private $weight_calc;

	/**
	 * Rate lookup engine.
	 *
	 * @var Allship_UPS_Rate_Lookup
	 */
	private $rate_lookup;

	/**
	 * Surcharge engine.
	 *
	 * @var Allship_UPS_Surcharge_Engine
	 */
	private $surcharge_engine;

	/**
	 * Quote log repository.
	 *
	 * @var Allship_UPS_Quote_Log_Repository
	 */
	private $quote_log_repo;

	/**
	 * Settings manager.
	 *
	 * @var Allship_UPS_Settings_Manager
	 */
	private $settings_mgr;

	/**
	 * Service availability manager.
	 *
	 * @var Allship_UPS_Service_Availability_Manager|null
	 */
	private $service_mgr;

	/**
	 * Constructor with Dependency Injection.
	 *
	 * @param Allship_UPS_Rate_Card_Repository|null        $rate_card_repo Optional rate card repo.
	 * @param Allship_UPS_Country_Repository|null          $country_repo Optional country repo.
	 * @param Allship_UPS_Zone_Resolver|null              $zone_resolver Optional zone resolver.
	 * @param Allship_UPS_Weight_Calculator|null          $weight_calc Optional weight calculator.
	 * @param Allship_UPS_Rate_Lookup|null                 $rate_lookup Optional rate lookup.
	 * @param Allship_UPS_Surcharge_Engine|null            $surcharge_engine Optional surcharge engine.
	 * @param Allship_UPS_Quote_Log_Repository|null        $quote_log_repo Optional quote log repo.
	 * @param Allship_UPS_Settings_Manager|null            $settings_mgr Optional settings manager.
	 * @param Allship_UPS_Service_Availability_Manager|null $service_mgr Optional service mgr.
	 */
	public function __construct(
		$rate_card_repo = null,
		$country_repo = null,
		$zone_resolver = null,
		$weight_calc = null,
		$rate_lookup = null,
		$surcharge_engine = null,
		$quote_log_repo = null,
		$settings_mgr = null,
		$service_mgr = null
	) {
		$this->rate_card_repo   = $rate_card_repo ?: ( class_exists( 'Allship_UPS_Rate_Card_Repository' ) ? new Allship_UPS_Rate_Card_Repository() : null );
		$this->country_repo     = $country_repo ?: ( class_exists( 'Allship_UPS_Country_Repository' ) ? new Allship_UPS_Country_Repository() : null );
		$this->settings_mgr     = $settings_mgr ?: ( class_exists( 'Allship_UPS_Settings_Manager' ) ? new Allship_UPS_Settings_Manager() : null );
		$this->weight_calc      = $weight_calc ?: ( class_exists( 'Allship_UPS_Weight_Calculator' ) ? new Allship_UPS_Weight_Calculator() : null );
		$this->zone_resolver    = $zone_resolver ?: ( class_exists( 'Allship_UPS_Zone_Resolver' ) ? new Allship_UPS_Zone_Resolver() : null );
		$this->rate_lookup      = $rate_lookup ?: ( class_exists( 'Allship_UPS_Rate_Lookup' ) ? new Allship_UPS_Rate_Lookup() : null );
		$this->surcharge_engine = $surcharge_engine ?: ( class_exists( 'Allship_UPS_Surcharge_Engine' ) ? new Allship_UPS_Surcharge_Engine( $this->settings_mgr ) : null );
		$this->quote_log_repo   = $quote_log_repo ?: ( class_exists( 'Allship_UPS_Quote_Log_Repository' ) ? new Allship_UPS_Quote_Log_Repository() : null );
		$this->service_mgr      = $service_mgr ?: ( class_exists( 'Allship_UPS_Service_Availability_Manager' ) ? new Allship_UPS_Service_Availability_Manager() : null );
	}

	/**
	 * Calculate a quotation from an input request payload.
	 *
	 * Flow: Validate -> Active rate card -> Weight calc -> Zone resolve -> Rate lookup -> Surcharge -> Log -> Result.
	 *
	 * @param array $input Raw input payload.
	 * @return Allship_UPS_Quote_Result
	 */
	public function calculate( array $input ): Allship_UPS_Quote_Result {
		try {
			// 1. Input Normalization & Validation
			$direction        = ! empty( $input['direction'] ) ? strtolower( trim( (string) $input['direction'] ) ) : 'export';
			$service_code     = ! empty( $input['service_code'] ) ? strtoupper( trim( (string) $input['service_code'] ) ) : '';
			$destination_iata = ! empty( $input['destination_iata'] ) ? strtoupper( trim( (string) $input['destination_iata'] ) ) : '';
			$shipment_type    = ! empty( $input['shipment_type'] ) ? strtolower( trim( (string) $input['shipment_type'] ) ) : 'nondocument';
			$is_envelope      = ! empty( $input['envelope'] );
			$origin_iata      = ! empty( $input['origin_iata'] ) ? strtoupper( trim( (string) $input['origin_iata'] ) ) : 'VN';
			$origin_province  = ! empty( $input['origin_province'] ) ? trim( (string) $input['origin_province'] ) : 'TP. Hồ Chí Minh';

			if ( empty( $destination_iata ) ) {
				return Allship_UPS_Quote_Result::error( 'INVALID_INPUT', 'Vui lòng chọn quốc gia đến.' );
			}

			if ( empty( $service_code ) ) {
				return Allship_UPS_Quote_Result::error( 'INVALID_INPUT', 'Vui lòng chọn dịch vụ UPS.' );
			}

			$raw_pieces = isset( $input['pieces'] ) && is_array( $input['pieces'] ) ? $input['pieces'] : [];
			if ( empty( $raw_pieces ) && ! $is_envelope ) {
				return Allship_UPS_Quote_Result::error( 'INVALID_INPUT', 'Vui lòng nhập ít nhất 1 kiện hàng.' );
			}

			// 2. Active Rate Card Resolution
			if ( ! empty( $input['rate_card_id'] ) ) {
				$rate_card_id = abs( (int) $input['rate_card_id'] );
			} elseif ( $this->rate_card_repo ) {
				if ( method_exists( $this->rate_card_repo, 'get_active_id' ) ) {
					$rate_card_id = $this->rate_card_repo->get_active_id();
				} else {
					$active_card  = $this->rate_card_repo->get_active();
					$rate_card_id = $active_card ? (int) $active_card->id : 0;
				}
			} else {
				$rate_card_id = 1;
			}

			if ( ! $rate_card_id ) {
				return Allship_UPS_Quote_Result::error( 'NO_ACTIVE_RATE_CARD', 'Không có bảng giá nào đang hoạt động trong hệ thống.' );
			}

			// 3. Country Verification
			$country = $this->country_repo ? $this->country_repo->get_by_iata( $destination_iata ) : null;
			$destination_name = $country && ! empty( $country->country_name ) ? $country->country_name : $destination_iata;

			// 4. Weight Calculation
			$dim_divisor      = $this->settings_mgr ? (int) $this->settings_mgr->get( 'dim_divisor', 5500 ) : 5500;
			$rounding_step_kg = $this->settings_mgr ? (float) $this->settings_mgr->get( 'rounding_step_kg', 0.5 ) : 0.5;

			if ( $is_envelope ) {
				$actual_weight_kg     = 0.2;
				$dim_weight_kg        = 0.0;
				$chargeable_weight_kg = 0.5;
				$calculated_pieces    = [
					[
						'quantity'             => 1,
						'actual_weight_kg'     => 0.2,
						'length_cm'            => 30.0,
						'width_cm'             => 20.0,
						'height_cm'            => 1.0,
						'dim_weight_kg'        => 0.0,
						'chargeable_weight_kg' => 0.5,
					],
				];
			} else {
				$calculated_pieces = $this->weight_calc ? $this->weight_calc->calculate_pieces( $raw_pieces, $dim_divisor, $rounding_step_kg ) : [];

				$actual_weight_kg     = 0.0;
				$dim_weight_kg        = 0.0;
				$chargeable_weight_kg = 0.0;

				foreach ( $calculated_pieces as $piece ) {
					$qty                  = isset( $piece['quantity'] ) ? max( 1, (int) $piece['quantity'] ) : 1;
					$actual_weight_kg     += (float) $piece['actual_weight_kg'] * $qty;
					$dim_weight_kg        += (float) $piece['dim_weight_kg'] * $qty;
					$chargeable_weight_kg += (float) $piece['chargeable_weight_kg'] * $qty;
				}

				$actual_weight_kg     = round( $actual_weight_kg, 3 );
				$dim_weight_kg        = round( $dim_weight_kg, 3 );
				$chargeable_weight_kg = round( $chargeable_weight_kg, 3 );
			}

			// 5. Zone Resolution (with US5 override & lane check)
			if ( ! $this->zone_resolver ) {
				return Allship_UPS_Quote_Result::error( 'QUOTE_INTERNAL_ERROR', 'Zone Resolver service is unavailable.' );
			}

			$zone_res = $this->zone_resolver->resolve( $direction, $service_code, $destination_iata, $rate_card_id );
			if ( ! $zone_res->success ) {
				return Allship_UPS_Quote_Result::error(
					$zone_res->error_code ?: 'LANE_NOT_AVAILABLE',
					$zone_res->error_message ?: 'Tuyến này không hỗ trợ dịch vụ đã chọn.'
				);
			}

			$zone      = $zone_res->zone;
			$rate_zone = $zone_res->rate_zone;

			// 6. Rate Group Mapping & Price Lookup
			if ( ! $this->rate_lookup ) {
				return Allship_UPS_Quote_Result::error( 'QUOTE_INTERNAL_ERROR', 'Rate Lookup service is unavailable.' );
			}

			$rate_group = $this->rate_lookup->map_rate_group( $service_code, $shipment_type, $direction );
			$rate_res   = $this->rate_lookup->find_price(
				$rate_card_id,
				$rate_group,
				$rate_zone,
				$chargeable_weight_kg,
				$is_envelope
			);

			if ( ! $rate_res->success ) {
				return Allship_UPS_Quote_Result::error(
					$rate_res->error_code ?: 'RATE_NOT_FOUND',
					$rate_res->error_message ?: 'Không tìm thấy mức giá phù hợp cho yêu cầu này.'
				);
			}

			$base_price_vnd = (int) $rate_res->price_vnd;

			// 7. Surcharges & Taxes Calculation
			$fees = [];
			if ( $this->surcharge_engine ) {
				$fees = $this->surcharge_engine->calculate( $rate_res, $input, $calculated_pieces );
			}

			$total_price_vnd = $this->surcharge_engine
				? $this->surcharge_engine->calculate_grand_total( $base_price_vnd, $fees )
				: $base_price_vnd;

			// 8. Notes & Disclaimers
			$notes = [
				'Giá chưa bao gồm VAT, FSC, Surge fee, customs fee và phụ phí khác nếu có.',
			];

			if ( ! empty( $zone_res->is_us_override ) ) {
				$notes[] = 'United States dùng cột giá riêng US5.';
			}

			if ( ! empty( $zone_res->has_extended_area_note ) ) {
				$notes[] = 'Khu vực có thể phát sinh phụ phí vùng sâu vùng xa (Extended Area Surcharge).';
			}

			if ( function_exists( 'apply_filters' ) ) {
				$notes = apply_filters( 'allship_ups_quote_notes', $notes, $rate_res, $input );
			}

			// 9. Resolve Service Display Name
			$service_name = $this->resolve_service_name( $service_code );

			// 10. Construct Result Object
			$result = new Allship_UPS_Quote_Result( [
				'success'                 => true,
				'direction'               => $direction,
				'origin_iata'             => $origin_iata,
				'origin_province'         => $origin_province,
				'destination_iata'        => $destination_iata,
				'destination_name'        => $destination_name,
				'destination_state'       => isset( $input['destination_state'] ) ? trim( (string) $input['destination_state'] ) : null,
				'destination_city'        => isset( $input['destination_city'] ) ? trim( (string) $input['destination_city'] ) : null,
				'destination_postal_code' => isset( $input['destination_postal_code'] ) ? trim( (string) $input['destination_postal_code'] ) : null,
				'destination_address'     => isset( $input['destination_address'] ) ? trim( (string) $input['destination_address'] ) : null,
				'service_code'            => $service_code,
				'service_name'            => $service_name,
				'shipment_type'           => $shipment_type,
				'zone'                    => $zone,
				'rate_zone'               => $rate_zone,
				'actual_weight_kg'        => $actual_weight_kg,
				'dim_weight_kg'           => $dim_weight_kg,
				'chargeable_weight_kg'    => $chargeable_weight_kg,
				'rounding_step_kg'        => $rounding_step_kg,
				'dim_divisor'             => $dim_divisor,
				'base_price_vnd'          => $base_price_vnd,
				'fees'                    => $fees,
				'total_price_vnd'         => $total_price_vnd,
				'currency'                => 'VND',
				'notes'                   => $notes,
				'pieces'                  => $calculated_pieces,
				'rate_card_id'            => $rate_card_id,
			] );

			// 11. Log Quote Request
			$this->log_quote( $input, $result );

			// 12. Hook Execution
			if ( function_exists( 'apply_filters' ) ) {
				$result = apply_filters( 'allship_ups_quote_result', $result, $input );
			}

			if ( function_exists( 'do_action' ) ) {
				do_action( 'allship_ups_quote_calculated', $result, $input );
			}

			return $result;

		} catch ( Exception $e ) {
			return Allship_UPS_Quote_Result::error(
				'QUOTE_INTERNAL_ERROR',
				'Đã xảy ra lỗi hệ thống trong quá trình tính giá. Vui lòng thử lại sau.'
			);
		}
	}

	/**
	 * Log calculation result to database.
	 *
	 * @param array                    $input Raw input payload.
	 * @param Allship_UPS_Quote_Result $result Calculation result.
	 * @return void
	 */
	private function log_quote( array $input, Allship_UPS_Quote_Result $result ) {
		if ( ! $this->quote_log_repo ) {
			return;
		}

		try {
			$log_data = [
				'rate_card_id'            => $result->rate_card_id,
				'direction'               => $result->direction,
				'origin_iata'             => $result->origin_iata,
				'origin_province'         => $result->origin_province,
				'destination_iata'        => $result->destination_iata,
				'destination_state'       => $result->destination_state,
				'destination_city'        => $result->destination_city,
				'destination_postal_code' => $result->destination_postal_code,
				'destination_address'     => $result->destination_address,
				'service_code'            => $result->service_code,
				'shipment_type'           => $result->shipment_type,
				'zone'                    => $result->zone,
				'rate_zone'               => $result->rate_zone,
				'actual_weight_kg'        => $result->actual_weight_kg,
				'dim_weight_kg'           => $result->dim_weight_kg,
				'chargeable_weight_kg'    => $result->chargeable_weight_kg,
				'base_price_vnd'          => $result->base_price_vnd,
				'total_price_vnd'         => $result->total_price_vnd,
				'pieces_json'             => $result->pieces,
				'breakdown_json'          => [
					'base_price_vnd'  => $result->base_price_vnd,
					'fees'            => $result->fees,
					'total_price_vnd' => $result->total_price_vnd,
					'notes'           => $result->notes,
				],
			];

			$this->quote_log_repo->insert( $log_data );
		} catch ( Exception $e ) {
			// Fail-safe: Logging failure should never break quotation calculation.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
				error_log( 'Allship UPS Quote Log Error: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Resolve user-friendly service display name from registry or default map.
	 *
	 * @param string $service_code Service code ('WXS', 'XPD', etc.).
	 * @return string
	 */
	private function resolve_service_name( string $service_code ): string {
		if ( defined( 'ALLSHIP_UPS_SERVICE_REGISTRY' ) ) {
			$reg = ALLSHIP_UPS_SERVICE_REGISTRY;
			if ( isset( $reg[ $service_code ]['name'] ) ) {
				return $reg[ $service_code ]['name'];
			}
		}

		$fallback_names = [
			'EXW' => 'Express Early',
			'XPR' => 'Express Plus',
			'WXS' => 'Express Saver',
			'XPD' => 'Expedited',
			'WXP' => 'Express Freight',
			'WFM' => 'Worldwide Express Freight',
		];

		return isset( $fallback_names[ $service_code ] ) ? $fallback_names[ $service_code ] : $service_code;
	}
}
