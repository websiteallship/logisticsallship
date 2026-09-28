<?php
/**
 * Admin Rate Cards controller & AJAX handlers.
 *
 * Implements Step 5.2 of Phase 5:
 * - List rate cards with direction & service badges.
 * - Actions: Create, Rename, Activate, Archive, Delete.
 * - Rate card detail: Direction toggles, Service rate groups grid, row counts.
 * - AJAX save with nonce + manage_options check + cache invalidation.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Admin_Rate_Cards {

	/**
	 * Nonce action.
	 */
	const NONCE_ACTION = 'allship_ups_admin';

	/**
	 * Rate card repository.
	 *
	 * @var Allship_UPS_Rate_Card_Repository
	 */
	private $rate_card_repo;

	/**
	 * Database instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Rate_Card_Repository|null $rate_card_repo Optional repository.
	 * @param wpdb|null                          $wpdb           Optional database.
	 */
	public function __construct( $rate_card_repo = null, $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;

		if ( null === $rate_card_repo ) {
			if ( ! class_exists( 'Allship_UPS_Rate_Card_Repository' ) && file_exists( dirname( __DIR__ ) . '/includes/class-rate-card-repository.php' ) ) {
				require_once dirname( __DIR__ ) . '/includes/class-rate-card-repository.php';
			}
			$rate_card_repo = class_exists( 'Allship_UPS_Rate_Card_Repository' ) ? new Allship_UPS_Rate_Card_Repository( $this->wpdb ) : null;
		}
		$this->rate_card_repo = $rate_card_repo;
	}

	/**
	 * Register AJAX hooks.
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_ups_create_rate_card', [ $this, 'ajax_create_rate_card' ] );
		add_action( 'wp_ajax_ups_rename_rate_card', [ $this, 'ajax_rename_rate_card' ] );
		add_action( 'wp_ajax_ups_activate_rate_card', [ $this, 'ajax_activate_rate_card' ] );
		add_action( 'wp_ajax_ups_archive_rate_card', [ $this, 'ajax_archive_rate_card' ] );
		add_action( 'wp_ajax_ups_delete_rate_card', [ $this, 'ajax_delete_rate_card' ] );
		add_action( 'wp_ajax_ups_get_rate_card_detail', [ $this, 'ajax_get_rate_card_detail' ] );
		add_action( 'wp_ajax_ups_update_rate_card_toggles', [ $this, 'ajax_update_rate_card_toggles' ] );
	}

	/**
	 * Verify AJAX request security.
	 */
	private function verify_security() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'allship-ups-quote' ) ], 403 );
		}
	}

	/**
	 * Clear all transient caches.
	 */
	public function invalidate_caches() {
		if ( ! $this->wpdb ) {
			return;
		}
		$this->wpdb->query( "DELETE FROM {$this->wpdb->options} WHERE option_name LIKE '_transient_ups_%' OR option_name LIKE '_transient_timeout_ups_%'" );
	}

	/**
	 * Get list of rate cards decorated with counts and badges.
	 *
	 * @return array
	 */
	public function get_rate_cards_list() {
		if ( ! $this->rate_card_repo ) {
			return [];
		}

		$cards = $this->rate_card_repo->get_all();
		$list  = [];

		foreach ( $cards as $card ) {
			$card_id = (int) $card->id;

			// Count rates & zones
			$rate_count = 0;
			$zone_count = 0;
			$rate_group_info = null;
			if ( $this->wpdb ) {
				$rate_count = (int) $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT COUNT(*) FROM {$this->wpdb->prefix}ups_rates WHERE rate_card_id = %d",
						$card_id
					)
				);
				$zone_count = (int) $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT COUNT(*) FROM {$this->wpdb->prefix}ups_zone_maps WHERE rate_card_id = %d",
						$card_id
					)
				);
				// Get rate_group breakdown
				$rg_row = $this->wpdb->get_row(
					$this->wpdb->prepare(
						"SELECT rate_group, COUNT(*) as cnt FROM {$this->wpdb->prefix}ups_rates WHERE rate_card_id = %d GROUP BY rate_group LIMIT 1",
						$card_id
					)
				);
				if ( $rg_row ) {
					$rate_group_info = [
						'key'   => $rg_row->rate_group,
						'count' => (int) $rg_row->cnt,
					];
				}
			}

			// Enabled services summary
			$disabled_groups = $card->disabled_rate_groups_array ?? [];
			$enabled_services = [];
			$services_meta = defined( 'ALLSHIP_UPS_SERVICE_REGISTRY' ) ? ALLSHIP_UPS_SERVICE_REGISTRY : [];

			foreach ( $services_meta as $code => $conf ) {
				$export_groups = $conf['rate_groups']['export'] ?? [];
				$has_active_group = false;
				foreach ( $export_groups as $group_name ) {
					if ( ! in_array( $group_name, $disabled_groups, true ) ) {
						$has_active_group = true;
						break;
					}
				}
				if ( $has_active_group ) {
					$enabled_services[] = $code;
				}
			}

			$list[] = [
				'id'                   => $card_id,
				'name'                 => $card->name,
				'market_code'          => $card->market_code,
				'valid_from'           => $card->valid_from ?: '—',
				'status'               => $card->status,
				'status_badge'         => $this->get_status_badge_html( $card->status ),
				'enabled_directions'   => $card->enabled_directions_array,
				'enabled_services'     => $enabled_services,
				'disabled_rate_groups' => $disabled_groups,
				'rate_count'           => $rate_count,
				'zone_count'           => $zone_count,
				'rate_group_info'      => $rate_group_info,
				'imported_at'          => $card->imported_at,
				'activated_at'         => $card->activated_at,
			];
		}

		return $list;
	}

	/**
	 * Get HTML badge for status.
	 *
	 * @param string $status Rate card status.
	 * @return string HTML badge.
	 */
	public function get_status_badge_html( $status ) {
		switch ( $status ) {
			case 'active':
				return '<span class="allship-badge allship-badge-active"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'Active', 'allship-ups-quote' ) . '</span>';
			case 'draft':
				return '<span class="allship-badge allship-badge-draft"><span class="dashicons dashicons-edit"></span> ' . esc_html__( 'Draft', 'allship-ups-quote' ) . '</span>';
			case 'archived':
			default:
				return '<span class="allship-badge allship-badge-archived"><span class="dashicons dashicons-archive"></span> ' . esc_html__( 'Archived', 'allship-ups-quote' ) . '</span>';
		}
	}

	/**
	 * AJAX: Create new draft rate card.
	 */
	public function ajax_create_rate_card() {
		$this->verify_security();

		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$valid_from = isset( $_POST['valid_from'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_from'] ) ) : null;
		$market     = isset( $_POST['market_code'] ) ? sanitize_text_field( wp_unslash( $_POST['market_code'] ) ) : 'VN';

		if ( empty( $name ) ) {
			wp_send_json_error( [ 'message' => __( 'Vui lòng nhập tên bảng giá.', 'allship-ups-quote' ) ] );
		}

		$card_id = $this->rate_card_repo->create( [
			'name'        => $name,
			'market_code' => $market ?: 'VN',
			'valid_from'  => $valid_from,
			'status'      => 'draft',
		] );

		if ( ! $card_id ) {
			wp_send_json_error( [ 'message' => __( 'Không thể tạo bảng giá. Vui lòng thử lại.', 'allship-ups-quote' ) ] );
		}

		wp_send_json_success( [
			'card_id' => $card_id,
			'message' => __( 'Đã tạo bảng giá mới thành công.', 'allship-ups-quote' ),
		] );
	}

	/**
	 * AJAX: Rename rate card.
	 */
	public function ajax_rename_rate_card() {
		$this->verify_security();

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( ! $id || empty( $name ) ) {
			wp_send_json_error( [ 'message' => __( 'Thông tin không hợp lệ.', 'allship-ups-quote' ) ] );
		}

		$res = $this->rate_card_repo->update_name( $id, $name );
		if ( ! $res ) {
			wp_send_json_error( [ 'message' => __( 'Không thể đổi tên bảng giá.', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		wp_send_json_success( [ 'message' => __( 'Đã cập nhật tên bảng giá.', 'allship-ups-quote' ) ] );
	}

	/**
	 * AJAX: Activate rate card.
	 */
	public function ajax_activate_rate_card() {
		$this->verify_security();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'ID bảng giá không hợp lệ.', 'allship-ups-quote' ) ] );
		}

		$res = $this->rate_card_repo->activate( $id );
		if ( ! $res ) {
			wp_send_json_error( [ 'message' => __( 'Không thể kích hoạt bảng giá.', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		wp_send_json_success( [ 'message' => __( 'Đã kích hoạt bảng giá thành công.', 'allship-ups-quote' ) ] );
	}

	/**
	 * AJAX: Archive rate card.
	 */
	public function ajax_archive_rate_card() {
		$this->verify_security();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'ID bảng giá không hợp lệ.', 'allship-ups-quote' ) ] );
		}

		$res = $this->rate_card_repo->archive( $id );
		if ( ! $res ) {
			wp_send_json_error( [ 'message' => __( 'Không thể lưu trữ bảng giá.', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		wp_send_json_success( [ 'message' => __( 'Đã chuyển bảng giá sang trạng thái lưu trữ.', 'allship-ups-quote' ) ] );
	}

	/**
	 * AJAX: Delete rate card.
	 */
	public function ajax_delete_rate_card() {
		$this->verify_security();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'ID bảng giá không hợp lệ.', 'allship-ups-quote' ) ] );
		}

		$card = $this->rate_card_repo->get( $id );
		if ( $card && 'active' === $card->status ) {
			wp_send_json_error( [ 'message' => __( 'Không thể xóa bảng giá đang kích hoạt (Active). Hãy kích hoạt bảng giá khác trước.', 'allship-ups-quote' ) ] );
		}

		$res = $this->rate_card_repo->delete( $id );
		if ( ! $res ) {
			wp_send_json_error( [ 'message' => __( 'Không thể xóa bảng giá.', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		wp_send_json_success( [ 'message' => __( 'Đã xóa bảng giá và toàn bộ dữ liệu liên quan.', 'allship-ups-quote' ) ] );
	}

	/**
	 * AJAX: Get rate card detail + service rows grid.
	 */
	public function ajax_get_rate_card_detail() {
		$this->verify_security();

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'ID bảng giá không hợp lệ.', 'allship-ups-quote' ) ] );
		}

		$card = $this->rate_card_repo->get( $id );
		if ( ! $card ) {
			wp_send_json_error( [ 'message' => __( 'Không tìm thấy bảng giá.', 'allship-ups-quote' ) ] );
		}

		// Count rows per rate_group
		$row_counts = [];
		if ( $this->wpdb ) {
			$counts = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT rate_group, COUNT(*) as cnt FROM {$this->wpdb->prefix}ups_rates WHERE rate_card_id = %d GROUP BY rate_group",
					$id
				),
				ARRAY_A
			);
			foreach ( $counts as $row ) {
				$row_counts[ $row['rate_group'] ] = (int) $row['cnt'];
			}
		}

		// Build services grid structure
		$services_meta = defined( 'ALLSHIP_UPS_SERVICE_REGISTRY' ) ? ALLSHIP_UPS_SERVICE_REGISTRY : [];
		$directions = [ 'export', 'import' ];
		$grid = [];

		$disabled_groups = $card->disabled_rate_groups_array ?? [];

		foreach ( $directions as $dir ) {
			$grid[ $dir ] = [];
			foreach ( $services_meta as $code => $conf ) {
				$groups = $conf['rate_groups'][ $dir ] ?? [];
				$group_items = [];

				foreach ( $groups as $type_key => $group_name ) {
					$rows = $row_counts[ $group_name ] ?? 0;
					$is_disabled_admin = in_array( $group_name, $disabled_groups, true );
					$has_data = $rows > 0;

					$group_items[] = [
						'type'          => $type_key,
						'rate_group'    => $group_name,
						'rows'          => $rows,
						'has_data'      => $has_data,
						'is_enabled'    => $has_data && ! $is_disabled_admin,
						'admin_disabled'=> $is_disabled_admin,
					];
				}

				$grid[ $dir ][] = [
					'service_code' => $code,
					'name'         => $conf['name'],
					'category'     => $conf['category'],
					'icon'         => $conf['icon'] ?? 'dashicons-admin-generic',
					'icon_bg'      => $conf['icon_bg'] ?? '#f1f5f9',
					'icon_color'   => $conf['icon_color'] ?? '#334155',
					'rate_groups'  => $group_items,
				];
			}
		}

		wp_send_json_success( [
			'card' => [
				'id'                   => $card->id,
				'name'                 => $card->name,
				'market_code'          => $card->market_code,
				'valid_from'           => $card->valid_from,
				'status'               => $card->status,
				'enabled_directions'   => $card->enabled_directions_array,
				'disabled_rate_groups' => $disabled_groups,
			],
			'grid' => $grid,
		] );
	}

	/**
	 * AJAX: Update rate card toggles (directions & rate groups).
	 */
	public function ajax_update_rate_card_toggles() {
		$this->verify_security();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'ID bảng giá không hợp lệ.', 'allship-ups-quote' ) ] );
		}

		$directions = isset( $_POST['directions'] ) && is_array( $_POST['directions'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['directions'] ) )
			: [ 'export' ];

		$disabled_groups = isset( $_POST['disabled_groups'] ) && is_array( $_POST['disabled_groups'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['disabled_groups'] ) )
			: [];

		$this->rate_card_repo->update_directions( $id, $directions );
		$this->rate_card_repo->update_disabled_groups( $id, $disabled_groups );

		$this->invalidate_caches();

		wp_send_json_success( [ 'message' => __( 'Đã lưu cấu hình dịch vụ bảng giá thành công.', 'allship-ups-quote' ) ] );
	}
}
