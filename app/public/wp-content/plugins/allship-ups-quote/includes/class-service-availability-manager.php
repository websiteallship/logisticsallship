<?php
/**
 * Service Availability Manager.
 *
 * Resolves available directions, active services, and rate group mappings
 * per rate card and admin toggles.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/config/service-registry.php';

class Allship_UPS_Service_Availability_Manager {

	/**
	 * Rate card repository.
	 *
	 * @var Allship_UPS_Rate_Card_Repository
	 */
	private $rate_card_repo;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Rate_Card_Repository|null $rate_card_repo Optional repository.
	 */
	public function __construct( $rate_card_repo = null ) {
		if ( null === $rate_card_repo ) {
			$rate_card_repo = new Allship_UPS_Rate_Card_Repository();
		}
		$this->rate_card_repo = $rate_card_repo;
	}

	/**
	 * Get list of available directions for a rate card.
	 *
	 * @param int|null $rate_card_id Optional rate card ID. Defaults to active card.
	 * @return array Array of direction strings, e.g. ['export', 'import'].
	 */
	public function get_available_directions( $rate_card_id = null ) {
		$card = $rate_card_id ? $this->rate_card_repo->get( $rate_card_id ) : $this->rate_card_repo->get_active();

		if ( $card && ! empty( $card->enabled_directions_array ) ) {
			return $card->enabled_directions_array;
		}

		return [ 'export' ];
	}

	/**
	 * Get services for a specific direction with enablement status.
	 *
	 * @param string   $direction 'export' or 'import'.
	 * @param int|null $rate_card_id Optional rate card ID.
	 * @return array List of service descriptors.
	 */
	public function get_services_for_direction( $direction, $rate_card_id = null ) {
		$direction = strtolower( trim( (string) $direction ) );
		if ( ! in_array( $direction, [ 'export', 'import' ], true ) ) {
			$direction = 'export';
		}

		$card            = $rate_card_id ? $this->rate_card_repo->get( $rate_card_id ) : $this->rate_card_repo->get_active();
		$disabled_groups = ( $card && ! empty( $card->disabled_rate_groups_array ) ) ? $card->disabled_rate_groups_array : [];

		$registry = function_exists( 'allship_ups_get_service_registry' ) ? allship_ups_get_service_registry() : ALLSHIP_UPS_SERVICE_REGISTRY;

		$services = [];
		foreach ( $registry as $code => $svc ) {
			$rate_groups = isset( $svc['rate_groups'][ $direction ] ) ? $svc['rate_groups'][ $direction ] : [];

			$is_enabled = true;
			if ( ! empty( $rate_groups ) ) {
				$all_disabled = true;
				foreach ( $rate_groups as $group_name ) {
					if ( ! in_array( $group_name, $disabled_groups, true ) ) {
						$all_disabled = false;
						break;
					}
				}
				if ( $all_disabled ) {
					$is_enabled = false;
				}
			}

			$services[] = [
				'service_code'       => $svc['service_code'],
				'name'               => $svc['name'],
				'category'           => $svc['category'],
				'has_document_split' => $svc['has_document_split'],
				'icon'               => $svc['icon'],
				'icon_bg'            => $svc['icon_bg'],
				'icon_color'         => $svc['icon_color'],
				'is_enabled'         => $is_enabled,
				'rate_groups'        => $rate_groups,
			];
		}

		return $services;
	}

	/**
	 * Resolve internal rate group identifier based on direction, service code and shipment type.
	 *
	 * @param string      $direction 'export' or 'import'.
	 * @param string      $service_code Service code (e.g. 'WXS', 'XPD', 'WFM', 'EXW', 'XPR', 'WXP').
	 * @param string|null $shipment_type 'document' or 'nondocument'.
	 * @return string Mapped rate group (e.g. 'export_wxs_nondocument').
	 */
	public function resolve_rate_group( $direction, $service_code, $shipment_type = null ) {
		$direction     = strtolower( trim( (string) $direction ) );
		if ( ! in_array( $direction, [ 'export', 'import' ], true ) ) {
			$direction = 'export';
		}

		$service_code  = strtoupper( trim( (string) $service_code ) );
		$shipment_type = strtolower( trim( (string) $shipment_type ) );

		$services_with_doc_split = [ 'EXW', 'XPR', 'WXS' ];

		if ( in_array( $service_code, $services_with_doc_split, true ) ) {
			$is_doc      = ( 'document' === $shipment_type || 'doc' === $shipment_type );
			$type_suffix = $is_doc ? 'document' : 'nondocument';
			return sprintf( '%s_%s_%s', $direction, strtolower( $service_code ), $type_suffix );
		}

		return sprintf( '%s_%s', $direction, strtolower( $service_code ) );
	}
}
