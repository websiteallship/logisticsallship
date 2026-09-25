<?php
/**
 * Weight Calculator for UPS shipments.
 *
 * Implements dimensional weight calculation, step-based ceiling,
 * individual piece chargeable weight calculation, quantity handling,
 * and multi-piece total aggregation.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Result object for an individual piece.
 *
 * Supports both property access and array access.
 */
class Allship_UPS_Piece_Result implements ArrayAccess {

	/**
	 * Actual weight in kg.
	 *
	 * @var float
	 */
	public $actual_weight_kg = 0.0;

	/**
	 * Dimensions in cm.
	 *
	 * @var float
	 */
	public $length_cm = 0.0;
	public $width_cm  = 0.0;
	public $height_cm = 0.0;

	/**
	 * Volumetric/dimensional weight in kg.
	 *
	 * @var float
	 */
	public $dim_weight_kg = 0.0;

	/**
	 * Chargeable weight for a single unit (after ceil_to_step) in kg.
	 *
	 * @var float
	 */
	public $unit_chargeable_kg = 0.0;

	/**
	 * Quantity of identical pieces.
	 *
	 * @var int
	 */
	public $quantity = 1;

	/**
	 * Total chargeable weight for this piece line (unit_chargeable_kg * quantity).
	 *
	 * @var float
	 */
	public $chargeable_weight_kg = 0.0;

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
	 * Convert to array.
	 *
	 * @return array
	 */
	public function to_array() {
		return [
			'actual_weight_kg'     => $this->actual_weight_kg,
			'length_cm'            => $this->length_cm,
			'width_cm'             => $this->width_cm,
			'height_cm'            => $this->height_cm,
			'dim_weight_kg'        => $this->dim_weight_kg,
			'unit_chargeable_kg'   => $this->unit_chargeable_kg,
			'quantity'             => $this->quantity,
			'chargeable_weight_kg' => $this->chargeable_weight_kg,
		];
	}
}

class Allship_UPS_Weight_Calculator {

	/**
	 * Default dimensional weight divisor (Accessorial standard).
	 */
	const DEFAULT_DIM_DIVISOR = 5500.0;

	/**
	 * Default weight rounding step (0.5 kg).
	 */
	const DEFAULT_ROUNDING_STEP = 0.5;

	/**
	 * Calculate dimensional (volumetric) weight for given dimensions.
	 *
	 * Formula: (L * W * H) / dim_divisor
	 *
	 * @param float $l Length in cm.
	 * @param float $w Width in cm.
	 * @param float $h Height in cm.
	 * @param float $dim_divisor Divisor (default 5500).
	 * @return float Dimensional weight in kg.
	 */
	public function calculate_dim_weight( $l, $w, $h, $dim_divisor = self::DEFAULT_DIM_DIVISOR ) {
		$l = max( 0.0, (float) $l );
		$w = max( 0.0, (float) $w );
		$h = max( 0.0, (float) $h );

		$divisor = (float) $dim_divisor;
		if ( $divisor <= 0.0 ) {
			$divisor = self::DEFAULT_DIM_DIVISOR;
		}

		return (float) ( ( $l * $w * $h ) / $divisor );
	}

	/**
	 * Round weight up to the nearest step.
	 *
	 * Example with step 0.5:
	 * - 0.1 -> 0.5
	 * - 2.5 -> 2.5
	 * - 3.2 -> 3.5
	 *
	 * Uses precision rounding to prevent floating-point representation anomalies.
	 *
	 * @param float $weight Weight in kg.
	 * @param float $step Rounding step in kg (default 0.5).
	 * @return float Rounded weight in kg.
	 */
	public function ceil_to_step( $weight, $step = self::DEFAULT_ROUNDING_STEP ) {
		$weight = (float) $weight;
		$step   = (float) $step;

		if ( $weight <= 0.0 ) {
			return 0.0;
		}

		if ( $step <= 0.0 ) {
			return $weight;
		}

		// Avoid precision issues (e.g. 2.5 / 0.5 becoming 5.000000000000001)
		$quotient = round( $weight / $step, 9 );
		$steps    = ceil( $quotient );

		return (float) ( $steps * $step );
	}

	/**
	 * Calculate chargeable weight for a single piece item.
	 *
	 * Chargeable weight = ceil_to_step( max( actual, dim ), step ) * quantity.
	 *
	 * @param float $actual Actual weight in kg.
	 * @param float $l Length in cm.
	 * @param float $w Width in cm.
	 * @param float $h Height in cm.
	 * @param float $dim_divisor Divisor (default 5500).
	 * @param float $step Rounding step (default 0.5).
	 * @param int   $quantity Quantity of identical packages (default 1).
	 * @return Allship_UPS_Piece_Result
	 */
	public function calculate_piece(
		$actual,
		$l = 0.0,
		$w = 0.0,
		$h = 0.0,
		$dim_divisor = self::DEFAULT_DIM_DIVISOR,
		$step = self::DEFAULT_ROUNDING_STEP,
		$quantity = 1
	) {
		$actual_weight = max( 0.0, (float) $actual );
		$dim_weight    = $this->calculate_dim_weight( $l, $w, $h, $dim_divisor );
		$max_weight    = max( $actual_weight, $dim_weight );

		$unit_chargeable = $this->ceil_to_step( $max_weight, $step );
		$qty             = max( 1, (int) $quantity );
		$total_chargeable = (float) ( $unit_chargeable * $qty );

		return new Allship_UPS_Piece_Result( [
			'actual_weight_kg'     => $actual_weight,
			'length_cm'            => (float) $l,
			'width_cm'             => (float) $w,
			'height_cm'            => (float) $h,
			'dim_weight_kg'        => $dim_weight,
			'unit_chargeable_kg'   => $unit_chargeable,
			'quantity'             => $qty,
			'chargeable_weight_kg' => $total_chargeable,
		] );
	}

	/**
	 * Calculate pieces list into individual piece results.
	 *
	 * @param array $pieces Array of piece definitions.
	 * @param float $dim_divisor Divisor (default 5500).
	 * @param float $step Rounding step (default 0.5).
	 * @return Allship_UPS_Piece_Result[]
	 */
	public function calculate_pieces( array $pieces, $dim_divisor = self::DEFAULT_DIM_DIVISOR, $step = self::DEFAULT_ROUNDING_STEP ) {
		$results = [];

		foreach ( $pieces as $p ) {
			$actual = 0.0;
			if ( isset( $p['actual_weight_kg'] ) ) {
				$actual = $p['actual_weight_kg'];
			} elseif ( isset( $p['actual_weight'] ) ) {
				$actual = $p['actual_weight'];
			} elseif ( isset( $p['weight'] ) ) {
				$actual = $p['weight'];
			} elseif ( isset( $p['actual'] ) ) {
				$actual = $p['actual'];
			}

			$l = isset( $p['length_cm'] ) ? $p['length_cm'] : ( isset( $p['length'] ) ? $p['length'] : ( isset( $p['l'] ) ? $p['l'] : 0.0 ) );
			$w = isset( $p['width_cm'] ) ? $p['width_cm'] : ( isset( $p['width'] ) ? $p['width'] : ( isset( $p['w'] ) ? $p['w'] : 0.0 ) );
			$h = isset( $p['height_cm'] ) ? $p['height_cm'] : ( isset( $p['height'] ) ? $p['height'] : ( isset( $p['h'] ) ? $p['h'] : 0.0 ) );

			$qty = isset( $p['quantity'] ) ? $p['quantity'] : ( isset( $p['qty'] ) ? $p['qty'] : 1 );

			$results[] = $this->calculate_piece( $actual, $l, $w, $h, $dim_divisor, $step, $qty );
		}

		return $results;
	}

	/**
	 * Compute total chargeable weight across piece results.
	 *
	 * Sums individual piece line chargeable weights.
	 *
	 * @param array $piece_results Array of Allship_UPS_Piece_Result or associative arrays.
	 * @return float
	 */
	public function total_chargeable( array $piece_results ) {
		$total = 0.0;

		foreach ( $piece_results as $piece ) {
			if ( is_object( $piece ) ) {
				$total += (float) ( isset( $piece->chargeable_weight_kg ) ? $piece->chargeable_weight_kg : 0.0 );
			} elseif ( is_array( $piece ) ) {
				$total += (float) ( isset( $piece['chargeable_weight_kg'] ) ? $piece['chargeable_weight_kg'] : 0.0 );
			}
		}

		return (float) $total;
	}
}
