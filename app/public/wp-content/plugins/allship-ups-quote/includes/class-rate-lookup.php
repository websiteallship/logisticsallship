<?php
/**
 * Rate Lookup Service.
 *
 * Implements price matching across flat brackets, per-kg brackets,
 * envelope rates, and freight minimums.
 * Enforces business rule: Document > 5kg -> DOCUMENT_OVER_5KG.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Result object for a rate lookup operation.
 */
class Allship_UPS_Rate_Result implements ArrayAccess {

	/**
	 * Whether lookup was successful.
	 *
	 * @var bool
	 */
	public $success = true;

	/**
	 * Matched rate row ID.
	 *
	 * @var int|null
	 */
	public $rate_id = null;

	/**
	 * Rate card ID.
	 *
	 * @var int|null
	 */
	public $rate_card_id = null;

	/**
	 * Internal rate group.
	 *
	 * @var string
	 */
	public $rate_group = '';

	/**
	 * Pricing zone code (e.g. '5', 'US5', '7').
	 *
	 * @var string
	 */
	public $rate_zone = '';

	/**
	 * Chargeable weight in kg.
	 *
	 * @var float
	 */
	public $chargeable_weight = 0.0;

	/**
	 * Weight label matched from rate card (e.g. '0.5', '21-44', 'UPS Envelope').
	 *
	 * @var string
	 */
	public $weight_label = '';

	/**
	 * Billing unit ('flat', 'per_kg', 'minimum', 'envelope').
	 *
	 * @var string
	 */
	public $billing_unit = 'flat';

	/**
	 * Unit price in VND.
	 *
	 * @var int
	 */
	public $unit_rate = 0;

	/**
	 * Calculated total base price in VND.
	 *
	 * @var int
	 */
	public $price_vnd = 0;

	/**
	 * Minimum threshold price in VND if applied.
	 *
	 * @var int|null
	 */
	public $min_price = null;

	/**
	 * Standardized error code if lookup failed.
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
	 * @param array $data Attributes.
	 */
	public function __construct( array $data = [] ) {
		foreach ( $data as $key => $val ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $val;
			}
		}
	}

	public function offsetExists( $offset ): bool {
		return property_exists( $this, $offset );
	}

	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
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

	/**
	 * Check whether this result represents an error.
	 *
	 * @return bool
	 */
	public function is_error() {
		return ! $this->success;
	}
}

class Allship_UPS_Rate_Lookup {

	/**
	 * Maximum allowed chargeable weight for Document shipments (5.0 kg).
	 */
	const MAX_DOCUMENT_WEIGHT_KG = 5.0;

	/**
	 * Rate repository.
	 *
	 * @var Allship_UPS_Rate_Repository
	 */
	private $rate_repo;

	/**
	 * Service availability manager.
	 *
	 * @var Allship_UPS_Service_Availability_Manager|null
	 */
	private $service_mgr;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Rate_Repository|null                  $rate_repo Optional rate repo.
	 * @param Allship_UPS_Service_Availability_Manager|null     $service_mgr Optional service mgr.
	 */
	public function __construct( $rate_repo = null, $service_mgr = null ) {
		$this->rate_repo   = $rate_repo ?: new Allship_UPS_Rate_Repository();
		$this->service_mgr = $service_mgr ?: ( class_exists( 'Allship_UPS_Service_Availability_Manager' ) ? new Allship_UPS_Service_Availability_Manager() : null );
	}

	/**
	 * Map service code, shipment type, and direction to internal rate group.
	 *
	 * @param string      $service_code Service code ('WXS', 'XPD', 'WFM', etc.).
	 * @param string|null $shipment_type 'document' or 'nondocument'.
	 * @param string      $direction 'export' or 'import'.
	 * @return string Mapped rate group (e.g. 'export_wxs_nondocument').
	 */
	public function map_rate_group( $service_code, $shipment_type = null, $direction = 'export' ) {
		if ( $this->service_mgr ) {
			return $this->service_mgr->resolve_rate_group( $direction, $service_code, $shipment_type );
		}

		$dir = strtolower( trim( (string) $direction ) );
		$svc = strtolower( trim( (string) $service_code ) );
		$typ = strtolower( trim( (string) $shipment_type ) );

		if ( 'wxs' === $svc || 'exw' === $svc || 'xpr' === $svc ) {
			$sub = ( 'document' === $typ || 'doc' === $typ ) ? 'document' : 'nondocument';
			return sprintf( '%s_%s_%s', $dir, $svc, $sub );
		}

		return sprintf( '%s_%s', $dir, $svc );
	}

	/**
	 * Find price for given rate group, pricing zone, weight, and envelope flag.
	 *
	 * Enforces DOCUMENT_OVER_5KG business rule.
	 * Evaluates flat, per_kg, and minimum rules via Rate_Repository.
	 *
	 * @param int    $rate_card_id Rate card ID.
	 * @param string $rate_group Canonical rate group (e.g. 'export_wxs_document').
	 * @param string $rate_zone Effective pricing zone (e.g. 'US5', '5', '7').
	 * @param float  $chargeable_weight Chargeable weight in kg.
	 * @param bool   $is_envelope Whether shipment is a UPS Document Envelope.
	 * @return Allship_UPS_Rate_Result
	 */
	public function find_price( $rate_card_id, $rate_group, $rate_zone, $chargeable_weight, $is_envelope = false ) {
		$rate_card_id      = abs( (int) $rate_card_id );
		$rate_group        = strtolower( trim( (string) $rate_group ) );
		$rate_zone         = trim( (string) $rate_zone );
		$chargeable_weight = max( 0.0, (float) $chargeable_weight );
		$is_envelope       = (bool) $is_envelope;

		// 1. Check Document > 5kg rule
		$is_document = ( false !== stripos( $rate_group, 'document' ) || false !== stripos( $rate_group, 'doc' ) )
			&& false === stripos( $rate_group, 'nondocument' )
			&& false === stripos( $rate_group, 'nondoc' );

		if ( $is_document && ! $is_envelope && $chargeable_weight > self::MAX_DOCUMENT_WEIGHT_KG ) {
			return new Allship_UPS_Rate_Result( [
				'success'           => false,
				'rate_card_id'      => $rate_card_id,
				'rate_group'        => $rate_group,
				'rate_zone'         => $rate_zone,
				'chargeable_weight' => $chargeable_weight,
				'error_code'        => 'DOCUMENT_OVER_5KG',
				'error_message'     => 'Kiện hàng Document không được vượt quá 5kg (quy định UPS). Vui lòng chọn loại hàng Non-document.',
			] );
		}

		// 2. Query price from rate repository
		$raw_result = $this->rate_repo->find_price(
			$rate_card_id,
			$rate_group,
			$rate_zone,
			$chargeable_weight,
			$is_envelope
		);

		if ( ! $raw_result ) {
			return new Allship_UPS_Rate_Result( [
				'success'           => false,
				'rate_card_id'      => $rate_card_id,
				'rate_group'        => $rate_group,
				'rate_zone'         => $rate_zone,
				'chargeable_weight' => $chargeable_weight,
				'error_code'        => 'RATE_NOT_FOUND',
				'error_message'     => "Không tìm thấy mức giá phù hợp cho nhóm cước '{$rate_group}', vùng '{$rate_zone}', cân nặng {$chargeable_weight}kg.",
			] );
		}

		return new Allship_UPS_Rate_Result( [
			'success'           => true,
			'rate_id'           => isset( $raw_result->rate_id ) ? (int) $raw_result->rate_id : 0,
			'rate_card_id'      => $rate_card_id,
			'rate_group'        => $rate_group,
			'rate_zone'         => $rate_zone,
			'chargeable_weight' => $chargeable_weight,
			'weight_label'      => isset( $raw_result->weight_label ) ? $raw_result->weight_label : '',
			'billing_unit'      => isset( $raw_result->billing_unit ) ? $raw_result->billing_unit : 'flat',
			'unit_rate'         => isset( $raw_result->unit_rate ) ? (int) $raw_result->unit_rate : 0,
			'price_vnd'         => isset( $raw_result->price_vnd ) ? (int) $raw_result->price_vnd : 0,
			'min_price'         => isset( $raw_result->min_price ) ? (int) $raw_result->min_price : null,
			'error_code'        => null,
			'error_message'     => null,
		] );
	}

	/**
	 * Convenience method: maps service and type, then finds price.
	 *
	 * @param string      $service_code Service code ('WXS', 'XPD', 'WFM').
	 * @param string|null $shipment_type 'document' or 'nondocument'.
	 * @param string      $rate_zone Effective pricing zone (e.g. 'US5', '5').
	 * @param float       $chargeable_weight Chargeable weight in kg.
	 * @param bool        $is_envelope Whether envelope.
	 * @param int         $rate_card_id Rate card ID.
	 * @param string      $direction Direction ('export' or 'import').
	 * @return Allship_UPS_Rate_Result
	 */
	public function lookup(
		$service_code,
		$shipment_type,
		$rate_zone,
		$chargeable_weight,
		$is_envelope = false,
		$rate_card_id = 1,
		$direction = 'export'
	) {
		$rate_group = $this->map_rate_group( $service_code, $shipment_type, $direction );
		return $this->find_price( $rate_card_id, $rate_group, $rate_zone, $chargeable_weight, $is_envelope );
	}
}
