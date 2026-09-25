<?php
/**
 * Surcharge and Tax Calculation Engine.
 *
 * Handles optional calculation of VAT, Fuel Surcharge (FSC), Surge fee,
 * and Customs fee based on administrative settings.
 * Surcharges are OFF by default in Phase 1 (ADR-006).
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fee result object representing an individual surcharge or tax item.
 */
class Allship_UPS_Fee implements ArrayAccess, JsonSerializable {

	/**
	 * Identifier code (e.g. 'vat', 'fsc', 'surge', 'customs_fee').
	 *
	 * @var string
	 */
	public $code = '';

	/**
	 * Display label in Vietnamese.
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * Fee amount in VND.
	 *
	 * @var int
	 */
	public $amount = 0;

	/**
	 * Calculation percentage if applicable (e.g. 10.0 for 10% VAT).
	 *
	 * @var float|null
	 */
	public $percent = null;

	/**
	 * Type of fee: 'percentage' or 'fixed'.
	 *
	 * @var string
	 */
	public $type = 'fixed';

	/**
	 * Optional description or calculation note.
	 *
	 * @var string
	 */
	public $description = '';

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
	 * Specify data which should be serialized to JSON.
	 *
	 * @return array
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this->to_array();
	}

	/**
	 * Convert fee object to plain associative array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return [
			'code'        => $this->code,
			'name'        => $this->name,
			'amount'      => (int) $this->amount,
			'percent'     => null !== $this->percent ? (float) $this->percent : null,
			'type'        => $this->type,
			'description' => $this->description,
		];
	}
}

/**
 * Surcharge Engine for computing surcharges and taxes.
 */
class Allship_UPS_Surcharge_Engine {

	/**
	 * Settings manager instance.
	 *
	 * @var Allship_UPS_Settings_Manager
	 */
	private $settings_mgr;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Settings_Manager|null $settings_mgr Optional settings manager.
	 */
	public function __construct( $settings_mgr = null ) {
		$this->settings_mgr = $settings_mgr ?: new Allship_UPS_Settings_Manager();
	}

	/**
	 * Calculate all active surcharges and taxes for a given base price and shipment input.
	 *
	 * By default (Phase 1), all settings are OFF and an empty array is returned.
	 * Allows custom extension via the 'allship_ups_surcharges' filter hook.
	 *
	 * @param int|float|object|array $base_price Base shipping price in VND or RateResult object.
	 * @param array                  $input Shipment request input.
	 * @param array                  $pieces Breakdown of pieces calculated by WeightCalculator.
	 * @return array<Allship_UPS_Fee> List of calculated fees.
	 */
	public function calculate( $base_price, array $input = [], array $pieces = [] ): array {
		$base_vnd = $this->extract_base_price( $base_price );
		$fees     = [];

		if ( $base_vnd <= 0 ) {
			return $this->apply_surcharges_filter( $fees, $base_price, $input, $pieces );
		}

		// 1. Read Surcharge Settings (Default all false)
		$include_fsc     = (bool) $this->settings_mgr->get( 'include_fsc', false );
		$fsc_percent     = (float) $this->settings_mgr->get( 'fsc_percent', 0.0 );

		$include_surge   = (bool) $this->settings_mgr->get( 'include_surge', false );
		$surge_percent   = (float) $this->settings_mgr->get( 'surge_percent', 0.0 );

		$include_customs = (bool) $this->settings_mgr->get( 'include_customs_fee', false );
		$customs_vnd     = (int) $this->settings_mgr->get( 'customs_fee_vnd', 10000 );

		$include_vat     = (bool) $this->settings_mgr->get( 'include_vat', false );
		$vat_percent     = (float) $this->settings_mgr->get( 'vat_percent', 10.0 );

		// 2. Fuel Surcharge (FSC)
		if ( $include_fsc && $fsc_percent > 0 ) {
			$fsc_amount = (int) round( $base_vnd * ( $fsc_percent / 100.0 ) );
			$fees[]     = new Allship_UPS_Fee( [
				'code'        => 'fsc',
				'name'        => sprintf( 'Phụ phí nhiên liệu (FSC %s%%)', $this->format_percent( $fsc_percent ) ),
				'amount'      => $fsc_amount,
				'percent'     => $fsc_percent,
				'type'        => 'percentage',
				'description' => sprintf( '%s%% trên cước cơ bản (%s đ)', $this->format_percent( $fsc_percent ), number_format( $base_vnd, 0, ',', '.' ) ),
			] );
		}

		// 3. Peak/Surge Fee
		if ( $include_surge && $surge_percent > 0 ) {
			$surge_amount = (int) round( $base_vnd * ( $surge_percent / 100.0 ) );
			$fees[]       = new Allship_UPS_Fee( [
				'code'        => 'surge',
				'name'        => sprintf( 'Phụ phí biến động (Surge fee %s%%)', $this->format_percent( $surge_percent ) ),
				'amount'      => $surge_amount,
				'percent'     => $surge_percent,
				'type'        => 'percentage',
				'description' => sprintf( '%s%% trên cước cơ bản (%s đ)', $this->format_percent( $surge_percent ), number_format( $base_vnd, 0, ',', '.' ) ),
			] );
		}

		// 4. Customs Fee (fixed per AWB)
		if ( $include_customs && $customs_vnd > 0 ) {
			$fees[] = new Allship_UPS_Fee( [
				'code'        => 'customs_fee',
				'name'        => 'Phí thủ tục hải quan (Customs fee)',
				'amount'      => $customs_vnd,
				'percent'     => null,
				'type'        => 'fixed',
				'description' => sprintf( '%s đ/vận đơn AWB', number_format( $customs_vnd, 0, ',', '.' ) ),
			] );
		}

		// 5. VAT (applied on total before VAT: base_price + applicable surcharges)
		if ( $include_vat && $vat_percent > 0 ) {
			$subtotal_before_vat = $base_vnd + $this->sum_fees( $fees );
			$vat_amount          = (int) round( $subtotal_before_vat * ( $vat_percent / 100.0 ) );

			$fees[] = new Allship_UPS_Fee( [
				'code'        => 'vat',
				'name'        => sprintf( 'Thuế GTGT (VAT %s%%)', $this->format_percent( $vat_percent ) ),
				'amount'      => $vat_amount,
				'percent'     => $vat_percent,
				'type'        => 'percentage',
				'description' => sprintf( '%s%% trên tổng trước thuế (%s đ)', $this->format_percent( $vat_percent ), number_format( $subtotal_before_vat, 0, ',', '.' ) ),
			] );
		}

		return $this->apply_surcharges_filter( $fees, $base_price, $input, $pieces );
	}

	/**
	 * Calculate total fee amount from fees array.
	 *
	 * @param array $fees Array of Fee objects or associative arrays.
	 * @return int Total fee amount in VND.
	 */
	public function sum_fees( array $fees ): int {
		$total = 0;
		foreach ( $fees as $fee ) {
			if ( $fee instanceof Allship_UPS_Fee ) {
				$total += (int) $fee->amount;
			} elseif ( is_array( $fee ) && isset( $fee['amount'] ) ) {
				$total += (int) $fee['amount'];
			} elseif ( is_object( $fee ) && isset( $fee->amount ) ) {
				$total += (int) $fee->amount;
			}
		}
		return $total;
	}

	/**
	 * Calculate grand total price (base_price + total fees).
	 *
	 * @param int|float|object|array $base_price Base price.
	 * @param array                  $fees Calculated fees.
	 * @return int Grand total in VND.
	 */
	public function calculate_grand_total( $base_price, array $fees = [] ): int {
		$base_vnd   = $this->extract_base_price( $base_price );
		$fees_total = $this->sum_fees( $fees );
		return $base_vnd + $fees_total;
	}

	/**
	 * Check whether any surcharge/tax is currently active in settings.
	 *
	 * @return bool True if at least one fee setting is enabled.
	 */
	public function has_active_surcharges(): bool {
		return (bool) (
			$this->settings_mgr->get( 'include_fsc', false ) ||
			$this->settings_mgr->get( 'include_surge', false ) ||
			$this->settings_mgr->get( 'include_customs_fee', false ) ||
			$this->settings_mgr->get( 'include_vat', false )
		);
	}

	/**
	 * Apply WordPress filter hook 'allship_ups_surcharges'.
	 *
	 * @param array                  $fees Current fees array.
	 * @param int|float|object|array $base_price Base price or rate result.
	 * @param array                  $input Input payload.
	 * @param array                  $pieces Pieces payload.
	 * @return array
	 */
	private function apply_surcharges_filter( array $fees, $base_price, array $input, array $pieces ): array {
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'allship_ups_surcharges', $fees, $base_price, $input, $pieces );
			return is_array( $filtered ) ? $filtered : $fees;
		}

		return $fees;
	}

	/**
	 * Extract integer VND price from various possible input types.
	 *
	 * @param int|float|object|array $base_price Base price input.
	 * @return int Price in VND.
	 */
	private function extract_base_price( $base_price ): int {
		if ( is_int( $base_price ) ) {
			return max( 0, $base_price );
		}

		if ( is_numeric( $base_price ) ) {
			return max( 0, (int) round( (float) $base_price ) );
		}

		if ( is_object( $base_price ) ) {
			if ( isset( $base_price->price_vnd ) ) {
				return max( 0, (int) $base_price->price_vnd );
			}
			if ( isset( $base_price['price_vnd'] ) ) {
				return max( 0, (int) $base_price['price_vnd'] );
			}
		}

		if ( is_array( $base_price ) && isset( $base_price['price_vnd'] ) ) {
			return max( 0, (int) $base_price['price_vnd'] );
		}

		return 0;
	}

	/**
	 * Format float percentage to trim trailing zero decimals.
	 *
	 * @param float $percent Percentage value.
	 * @return string Formatted percentage (e.g. '10' instead of '10.0', '7.5' for 7.5).
	 */
	private function format_percent( float $percent ): string {
		if ( (float) ( (int) $percent ) === $percent ) {
			return (string) ( (int) $percent );
		}
		return rtrim( rtrim( sprintf( '%.2f', $percent ), '0' ), '.' );
	}
}
