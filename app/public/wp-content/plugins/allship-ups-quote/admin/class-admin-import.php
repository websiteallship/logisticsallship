<?php
/**
 * Admin Import Controller.
 *
 * Implements Step 5.3 of Phase 5:
 * - AJAX: ups_import_preview, ups_import_execute, ups_import_cancel.
 * - Nonce and manage_options capability checks.
 * - Integration with Allship_UPS_Import_Orchestrator.
 * - Transient-backed two-phase wizard state.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Admin_Import {

	/**
	 * Import Orchestrator.
	 *
	 * @var Allship_UPS_Import_Orchestrator
	 */
	private $orchestrator;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Import_Orchestrator|null $orchestrator Optional orchestrator instance.
	 */
	public function __construct( $orchestrator = null ) {
		if ( ! $orchestrator ) {
			if ( ! class_exists( 'Allship_UPS_Import_Orchestrator' ) ) {
				$orchestrator_path = dirname( __DIR__ ) . '/includes/importers/class-import-orchestrator.php';
				if ( file_exists( $orchestrator_path ) ) {
					require_once $orchestrator_path;
				}
			}
			$this->orchestrator = class_exists( 'Allship_UPS_Import_Orchestrator' )
				? new Allship_UPS_Import_Orchestrator()
				: null;
		} else {
			$this->orchestrator = $orchestrator;
		}
	}

	/**
	 * Register admin AJAX hooks.
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_ups_import_preview', [ $this, 'ajax_import_preview' ] );
		add_action( 'wp_ajax_ups_import_execute', [ $this, 'ajax_import_execute' ] );
		add_action( 'wp_ajax_ups_import_cancel', [ $this, 'ajax_import_cancel' ] );
	}

	/**
	 * Security check: Nonce & Capability.
	 */
	private function verify_security() {
		check_ajax_referer( 'allship_ups_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'allship-ups-quote' ) ],
				403
			);
		}
	}

	/**
	 * AJAX: Preview Import File (Step 1 -> Step 2).
	 */
	public function ajax_import_preview() {
		$this->verify_security();

		$import_type = isset( $_POST['import_type'] ) && 'zones' === $_POST['import_type'] ? 'zones' : 'rates';

		$zone_mode          = isset( $_POST['zone_mode'] ) && 'new' === $_POST['zone_mode'] ? 'new' : 'existing';
		$zone_set_id        = isset( $_POST['zone_set_id'] ) ? absint( $_POST['zone_set_id'] ) : 0;
		$new_zone_set_name  = isset( $_POST['new_zone_set_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_zone_set_name'] ) ) : '';
		$zone_set_name      = isset( $_POST['zone_set_name'] ) ? sanitize_text_field( wp_unslash( $_POST['zone_set_name'] ) ) : '';
		$zone_set_desc      = isset( $_POST['zone_set_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['zone_set_description'] ) ) : '';

		if ( 'zones' === $import_type ) {
			if ( empty( $_FILES['zone_file'] ) || ! isset( $_FILES['zone_file']['error'] ) || UPLOAD_ERR_OK !== $_FILES['zone_file']['error'] ) {
				wp_send_json_error( [ 'message' => __( 'Vui lòng chọn file Phân vùng (Zone File).', 'allship-ups-quote' ) ], 400 );
				return;
			}
			if ( empty( $zone_set_name ) ) {
				$zone_set_name = 'UPS Zone Set ' . date( 'Y-m-d' );
			}
		} else {
			if ( empty( $_FILES['rate_file'] ) || ! isset( $_FILES['rate_file']['error'] ) || UPLOAD_ERR_OK !== $_FILES['rate_file']['error'] ) {
				wp_send_json_error( [ 'message' => __( 'Vui lòng chọn file Biểu phí (Rate File).', 'allship-ups-quote' ) ], 400 );
				return;
			}
			if ( 'new' === $zone_mode ) {
				if ( empty( $_FILES['zone_file'] ) || ! isset( $_FILES['zone_file']['error'] ) || UPLOAD_ERR_OK !== $_FILES['zone_file']['error'] ) {
					wp_send_json_error( [ 'message' => __( 'Vui lòng chọn file Zone mới cần import kèm bảng giá.', 'allship-ups-quote' ) ], 400 );
					return;
				}
			}
		}

		if ( ! $this->orchestrator ) {
			wp_send_json_error( [ 'message' => __( 'Import Orchestrator chưa sẵn sàng.', 'allship-ups-quote' ) ], 500 );
			return;
		}

		try {
			$rate_upload = null;
			$zone_upload = null;

			$rate_group = isset( $_POST['rate_group'] ) && '' !== trim( (string) $_POST['rate_group'] )
				? sanitize_text_field( wp_unslash( $_POST['rate_group'] ) )
				: 'export_wxs_nondocument';

			if ( 'zones' === $import_type ) {
				$zone_upload = $this->orchestrator->handle_upload( $_FILES['zone_file'] );
				$preview     = $this->orchestrator->preview( null, $zone_upload['file_path'], $rate_group );
			} else {
				$rate_upload = $this->orchestrator->handle_upload( $_FILES['rate_file'] );
				if ( 'new' === $zone_mode && ! empty( $_FILES['zone_file']['name'] ) && isset( $_FILES['zone_file']['error'] ) && UPLOAD_ERR_OK === $_FILES['zone_file']['error'] ) {
					$zone_upload = $this->orchestrator->handle_upload( $_FILES['zone_file'] );
				}
				$preview     = $this->orchestrator->preview( $rate_upload['file_path'], $zone_upload ? $zone_upload['file_path'] : null, $rate_group );
			}

			// Session Token & Transient Storage
			$token = function_exists( 'wp_generate_password' )
				? wp_generate_password( 24, false )
				: bin2hex( random_bytes( 12 ) );

			$rate_card_name = isset( $_POST['rate_card_name'] ) && '' !== trim( (string) $_POST['rate_card_name'] )
				? sanitize_text_field( wp_unslash( $_POST['rate_card_name'] ) )
				: ( 'UPS Rate Card ' . date( 'Y-m-d' ) );

			$valid_from = isset( $_POST['valid_from'] ) && '' !== trim( (string) $_POST['valid_from'] )
				? sanitize_text_field( wp_unslash( $_POST['valid_from'] ) )
				: date( 'Y-m-d' );

			$session_payload = [
				'import_type'          => $import_type,
				'rate_upload'          => $rate_upload,
				'zone_upload'          => $zone_upload,
				'preview'              => $preview,
				'rate_card_name'       => $rate_card_name,
				'valid_from'           => $valid_from,
				'rate_group'           => $rate_group,
				'zone_mode'            => $zone_mode,
				'zone_set_id'          => $zone_set_id,
				'new_zone_set_name'    => $new_zone_set_name,
				'zone_set_name'        => $zone_set_name,
				'zone_set_description' => $zone_set_desc,
			];

			set_transient( 'ups_imp_' . $token, $session_payload, 2 * HOUR_IN_SECONDS );

			// Build summary breakdown for UI
			$rates_preview = $preview['rates'] ?? [];
			$zones_preview = $preview['zones'] ?? null;

			$rate_groups_summary = [];
			if ( ! empty( $rates_preview['rate_groups'] ) ) {
				foreach ( $rates_preview['rate_groups'] as $rg_data ) {
					$rate_groups_summary[] = [
						'group_key'    => $rg_data['rate_group'],
						'group_name'   => $rg_data['label'] ?? $rg_data['rate_group'],
						'row_count'    => $rg_data['count'] ?? 0,
						'service_code' => '',
					];
				}
			} elseif ( ! empty( $rates_preview['groups'] ) ) {
				foreach ( $rates_preview['groups'] as $rg_key => $rg_data ) {
					$rate_groups_summary[] = [
						'group_key'    => $rg_key,
						'group_name'   => $rg_data['title'] ?? $rg_key,
						'row_count'    => $rg_data['rows_count'] ?? 0,
						'service_code' => $rg_data['service_code'] ?? '',
					];
				}
			}

			$has_us5 = ! empty( $rates_preview['has_us5'] );
			if ( ! $has_us5 && ! empty( $rates_preview['zones'] ) && is_array( $rates_preview['zones'] ) ) {
				$has_us5 = in_array( 'US5', $rates_preview['zones'], true );
			}

			$response_data = [
				'token'            => $token,
				'status'           => $preview['status'],
				'import_type'      => $import_type,
				'rate_card_name'   => $rate_card_name,
				'valid_from'       => $valid_from,
				'rate_group'       => $rate_group,
				'source_rate_file' => $rate_upload ? $rate_upload['source_file_name'] : null,
				'source_zone_file' => $zone_upload ? $zone_upload['source_file_name'] : null,
				'has_us5'          => $has_us5,
				'rate_groups'      => $rate_groups_summary,
				'countries_count'  => $zones_preview['total_countries'] ?? ( $zones_preview['countries_count'] ?? 0 ),
				'zone_maps_count'  => $zones_preview['total_zone_maps'] ?? ( $zones_preview['zone_maps_count'] ?? 0 ),
				'services_summary' => $zones_preview['services_summary'] ?? [],
				'warnings'         => $preview['warnings'] ?? [],
				'errors'           => $preview['errors'] ?? [],
				'is_valid'         => ( 'success' === $preview['status'] && empty( $preview['errors'] ) ),
			];

		} catch ( Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
			return;
		}

		wp_send_json_success( $response_data );
	}

	/**
	 * AJAX: Execute Import (Step 2 -> Step 3).
	 */
	public function ajax_import_execute() {
		$this->verify_security();

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( empty( $token ) ) {
			wp_send_json_error( [ 'message' => __( 'Thiếu mã phiên import (Token).', 'allship-ups-quote' ) ], 400 );
			return;
		}

		$session_data = get_transient( 'ups_imp_' . $token );
		if ( ! $session_data || ! is_array( $session_data ) ) {
			wp_send_json_error( [ 'message' => __( 'Phiên import đã hết hạn hoặc không tồn tại. Vui lòng tải lại file.', 'allship-ups-quote' ) ], 400 );
			return;
		}

		if ( ! $this->orchestrator ) {
			wp_send_json_error( [ 'message' => __( 'Import Orchestrator chưa sẵn sàng.', 'allship-ups-quote' ) ], 500 );
			return;
		}

		$result_payload = null;
		try {
			$card_name  = isset( $_POST['rate_card_name'] ) && '' !== trim( (string) $_POST['rate_card_name'] )
				? sanitize_text_field( wp_unslash( $_POST['rate_card_name'] ) )
				: $session_data['rate_card_name'];

			$valid_from = isset( $_POST['valid_from'] ) && '' !== trim( (string) $_POST['valid_from'] )
				? sanitize_text_field( wp_unslash( $_POST['valid_from'] ) )
				: $session_data['valid_from'];

			$import_type = isset( $session_data['import_type'] ) ? $session_data['import_type'] : 'rates';

			$import_params = [
				'rate_file_path'        => $session_data['rate_upload'] ? $session_data['rate_upload']['file_path'] : null,
				'zone_file_path'        => $session_data['zone_upload'] ? $session_data['zone_upload']['file_path'] : null,
				'rate_card_name'        => $card_name,
				'valid_from'            => $valid_from,
				'rate_group'            => isset( $session_data['rate_group'] ) ? $session_data['rate_group'] : 'export_wxs_nondocument',
				'source_file_name'      => $session_data['rate_upload'] ? $session_data['rate_upload']['source_file_name'] : ( $session_data['zone_upload'] ? $session_data['zone_upload']['source_file_name'] : null ),
				'source_file_hash'      => $session_data['rate_upload'] ? $session_data['rate_upload']['source_file_hash'] : ( $session_data['zone_upload'] ? $session_data['zone_upload']['source_file_hash'] : null ),
				'zone_set_id'           => isset( $session_data['zone_set_id'] ) ? $session_data['zone_set_id'] : null,
				'new_zone_set_name'     => isset( $session_data['new_zone_set_name'] ) ? $session_data['new_zone_set_name'] : null,
				'zone_set_name'         => isset( $session_data['zone_set_name'] ) ? $session_data['zone_set_name'] : null,
				'zone_set_description'  => isset( $session_data['zone_set_description'] ) ? $session_data['zone_set_description'] : null,
				'delete_files_after'    => true,
			];

			$result = $this->orchestrator->import( $import_params );

			// Clear transient session
			delete_transient( 'ups_imp_' . $token );

			// Invalidate any transient caches
			global $wpdb;
			if ( $wpdb ) {
				$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ups_%'" );
			}

			$message = ( 'zones' === $import_type )
				? __( 'Đã tạo và import thành công Bảng Zone Set!', 'allship-ups-quote' )
				: __( 'Đã import thành công bảng giá (Draft)!', 'allship-ups-quote' );

			$result_payload = [
				'import_type'  => $import_type,
				'rate_card_id' => $result['rate_card_id'] ?? null,
				'zone_set_id'  => $result['zone_set_id'] ?? null,
				'card_status'  => $result['card_status'] ?? ( 'zones' === $import_type ? 'active' : 'draft' ),
				'card_name'    => $card_name,
				'rate_summary' => $result['rate_summary'] ?? [],
				'zone_summary' => $result['zone_summary'] ?? null,
				'message'      => $message,
			];

		} catch ( Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
			return;
		}

		wp_send_json_success( $result_payload );
	}

	/**
	 * Get list of all zone sets for dropdown in views.
	 *
	 * @return array
	 */
	public function get_zone_sets_dropdown() {
		if ( class_exists( 'Allship_UPS_Zone_Set_Repository' ) ) {
			$repo = new Allship_UPS_Zone_Set_Repository();
			return $repo->get_all();
		}
		return [];
	}

	/**
	 * AJAX: Cancel Import Session (Cleans up temporary files without writing DB).
	 */
	public function ajax_import_cancel() {
		$this->verify_security();

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! empty( $token ) ) {
			$session_data = get_transient( 'ups_imp_' . $token );
			if ( is_array( $session_data ) ) {
				if ( ! empty( $session_data['rate_upload']['file_path'] ) && file_exists( $session_data['rate_upload']['file_path'] ) ) {
					@unlink( $session_data['rate_upload']['file_path'] );
				}
				if ( ! empty( $session_data['zone_upload']['file_path'] ) && file_exists( $session_data['zone_upload']['file_path'] ) ) {
					@unlink( $session_data['zone_upload']['file_path'] );
				}
			}
			delete_transient( 'ups_imp_' . $token );
		}

		wp_send_json_success( [
			'status'  => 'cancelled',
			'message' => __( 'Đã hủy phiên import. Không có dữ liệu nào được ghi.', 'allship-ups-quote' ),
		] );
	}
}
