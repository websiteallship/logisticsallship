<?php
/**
 * Service Registry configuration.
 *
 * Defines the 6 UPS services, categories, document split behavior,
 * UI icons/colors, and the 18 mapped rate groups.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ALLSHIP_UPS_SERVICE_REGISTRY' ) ) {
	define(
		'ALLSHIP_UPS_SERVICE_REGISTRY',
		[
			'EXW' => [
				'service_code'       => 'EXW',
				'name'               => 'Express Early',
				'category'           => 'parcel',
				'has_document_split' => true,
				'icon'               => 'ph-globe',
				'icon_bg'            => '#FEF3C7',
				'icon_color'         => '#D97706',
				'rate_groups'        => [
					'export' => [
						'document'    => 'export_exw_document',
						'nondocument' => 'export_exw_nondocument',
					],
					'import' => [
						'document'    => 'import_exw_document',
						'nondocument' => 'import_exw_nondocument',
					],
				],
			],
			'XPR' => [
				'service_code'       => 'XPR',
				'name'               => 'Express Plus',
				'category'           => 'parcel',
				'has_document_split' => true,
				'icon'               => 'ph-rocket-launch',
				'icon_bg'            => '#EDE9FE',
				'icon_color'         => '#7C3AED',
				'rate_groups'        => [
					'export' => [
						'document'    => 'export_xpr_document',
						'nondocument' => 'export_xpr_nondocument',
					],
					'import' => [
						'document'    => 'import_xpr_document',
						'nondocument' => 'import_xpr_nondocument',
					],
				],
			],
			'WXS' => [
				'service_code'       => 'WXS',
				'name'               => 'Express Saver',
				'category'           => 'parcel',
				'has_document_split' => true,
				'icon'               => 'ph-airplane-tilt',
				'icon_bg'            => '#FEF3C7',
				'icon_color'         => '#D97706',
				'rate_groups'        => [
					'export' => [
						'document'    => 'export_wxs_document',
						'nondocument' => 'export_wxs_nondocument',
					],
					'import' => [
						'document'    => 'import_wxs_document',
						'nondocument' => 'import_wxs_nondocument',
					],
				],
			],
			'XPD' => [
				'service_code'       => 'XPD',
				'name'               => 'Expedited',
				'category'           => 'parcel',
				'has_document_split' => false,
				'icon'               => 'ph-truck',
				'icon_bg'            => '#E0E7FF',
				'icon_color'         => '#4338CA',
				'rate_groups'        => [
					'export' => [
						'all' => 'export_xpd',
					],
					'import' => [
						'all' => 'import_xpd',
					],
				],
			],
			'WXP' => [
				'service_code'       => 'WXP',
				'name'               => 'Express Freight',
				'category'           => 'freight',
				'has_document_split' => false,
				'icon'               => 'ph-lightning',
				'icon_bg'            => '#FEE2E2',
				'icon_color'         => '#DC2626',
				'rate_groups'        => [
					'export' => [
						'all' => 'export_wxp',
					],
					'import' => [
						'all' => 'import_wxp',
					],
				],
			],
			'WFM' => [
				'service_code'       => 'WFM',
				'name'               => 'Freight Midday',
				'category'           => 'freight',
				'has_document_split' => false,
				'icon'               => 'ph-crane',
				'icon_bg'            => '#ECFDF5',
				'icon_color'         => '#059669',
				'rate_groups'        => [
					'export' => [
						'all' => 'export_wfm',
					],
					'import' => [
						'all' => 'import_wfm',
					],
				],
			],
		]
	);
}

if ( ! function_exists( 'allship_ups_get_service_registry' ) ) {
	/**
	 * Retrieve filtered service registry.
	 *
	 * @return array
	 */
	function allship_ups_get_service_registry() {
		$registry = ALLSHIP_UPS_SERVICE_REGISTRY;
		return function_exists( 'apply_filters' ) ? apply_filters( 'allship_ups_service_registry', $registry ) : $registry;
	}
}
