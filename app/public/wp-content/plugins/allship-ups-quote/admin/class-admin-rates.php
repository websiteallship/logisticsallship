<?php
/**
 * Admin Rates controller & AJAX handlers.
 *
 * Implements Step 5.4 of Phase 5:
 * - List rates with filters (rate_card_id, rate_group, zone).
 * - Pagination (50 rows per page).
 * - Inline edit: click cell → input → AJAX save.
 * - Add new rate row.
 * - Bulk delete selected rows.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Admin_Rates {

	/**
	 * Nonce action — shared with other admin modules.
	 */
	const NONCE_ACTION = 'allship_ups_admin';

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 50;

	/**
	 * Rate repository.
	 *
	 * @var Allship_UPS_Rate_Repository|null
	 */
	private $rate_repo;

	/**
	 * Rate card repository.
	 *
	 * @var Allship_UPS_Rate_Card_Repository|null
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
	 * @param Allship_UPS_Rate_Repository|null      $rate_repo      Optional.
	 * @param Allship_UPS_Rate_Card_Repository|null  $rate_card_repo Optional.
	 * @param wpdb|null                              $wpdb           Optional.
	 */
	public function __construct( $rate_repo = null, $rate_card_repo = null, $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;

		if ( null === $rate_repo ) {
			if ( ! class_exists( 'Allship_UPS_Rate_Repository' ) && file_exists( dirname( __DIR__ ) . '/includes/class-rate-repository.php' ) ) {
				require_once dirname( __DIR__ ) . '/includes/class-rate-repository.php';
			}
			$rate_repo = class_exists( 'Allship_UPS_Rate_Repository' ) ? new Allship_UPS_Rate_Repository( $this->wpdb ) : null;
		}
		$this->rate_repo = $rate_repo;

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
		add_action( 'wp_ajax_ups_rates_list', [ $this, 'ajax_list_rates' ] );
		add_action( 'wp_ajax_ups_rates_update_cell', [ $this, 'ajax_update_cell' ] );
		add_action( 'wp_ajax_ups_rates_add_row', [ $this, 'ajax_add_row' ] );
		add_action( 'wp_ajax_ups_rates_update_row', [ $this, 'ajax_update_row' ] );
		add_action( 'wp_ajax_ups_rates_delete_rows', [ $this, 'ajax_delete_rows' ] );
		add_action( 'wp_ajax_ups_rates_get_filters', [ $this, 'ajax_get_filters' ] );
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
	 * Clear transient caches after rate changes.
	 */
	private function invalidate_caches() {
		if ( ! $this->wpdb ) {
			return;
		}
		$this->wpdb->query( "DELETE FROM {$this->wpdb->options} WHERE option_name LIKE '_transient_ups_%' OR option_name LIKE '_transient_timeout_ups_%'" );
	}

	/**
	 * AJAX: Get available filter options (rate_groups, zones) for a rate card.
	 */
	public function ajax_get_filters() {
		$this->verify_security();

		$rate_card_id = isset( $_GET['rate_card_id'] ) ? absint( $_GET['rate_card_id'] ) : 0;
		if ( ! $rate_card_id || ! $this->wpdb ) {
			wp_send_json_error( [ 'message' => __( 'Thiếu rate_card_id.', 'allship-ups-quote' ) ] );
		}

		$table = $this->wpdb->prefix . 'ups_rates';

		// Distinct rate_groups
		$rate_groups = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT rate_group FROM {$table} WHERE rate_card_id = %d ORDER BY rate_group ASC",
				$rate_card_id
			)
		);

		// Distinct zones
		$zones = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT zone FROM {$table} WHERE rate_card_id = %d ORDER BY CAST(zone AS UNSIGNED) ASC, zone ASC",
				$rate_card_id
			)
		);

		// Distinct billing_units
		$billing_units = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT billing_unit FROM {$table} WHERE rate_card_id = %d ORDER BY billing_unit ASC",
				$rate_card_id
			)
		);

		wp_send_json_success( [
			'rate_groups'   => is_array( $rate_groups ) ? $rate_groups : [],
			'zones'         => is_array( $zones ) ? $zones : [],
			'billing_units' => is_array( $billing_units ) ? $billing_units : [],
		] );
	}

	/**
	 * AJAX: List rates with filters and pagination.
	 */
	public function ajax_list_rates() {
		$this->verify_security();

		$rate_card_id = isset( $_GET['rate_card_id'] ) ? absint( $_GET['rate_card_id'] ) : 0;
		if ( ! $rate_card_id || ! $this->wpdb ) {
			wp_send_json_error( [ 'message' => __( 'Vui lòng chọn một Rate Card.', 'allship-ups-quote' ) ] );
		}

		$table = $this->wpdb->prefix . 'ups_rates';

		// Build WHERE
		$where  = [ 'rate_card_id = %d' ];
		$params = [ $rate_card_id ];

		if ( ! empty( $_GET['rate_group'] ) ) {
			$where[]  = 'rate_group = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['rate_group'] ) );
		}

		if ( isset( $_GET['zone'] ) && '' !== $_GET['zone'] ) {
			$where[]  = 'zone = %s';
			$params[] = sanitize_text_field( wp_unslash( $_GET['zone'] ) );
		}

		if ( ! empty( $_GET['billing_unit'] ) ) {
			$where[]  = 'billing_unit = %s';
			$params[] = sanitize_text_field( wp_unslash( $_GET['billing_unit'] ) );
		}

		if ( ! empty( $_GET['search'] ) ) {
			$search   = '%' . $this->wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['search'] ) ) ) . '%';
			$where[]  = 'weight_label LIKE %s';
			$params[] = $search;
		}

		$where_sql = implode( ' AND ', $where );

		// Count total
		$total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
				$params
			)
		);

		// Pagination
		$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$offset = ( $page - 1 ) * self::PER_PAGE;
		$pages  = $total > 0 ? (int) ceil( $total / self::PER_PAGE ) : 1;

		// Fetch rows
		$params_with_limit   = $params;
		$params_with_limit[] = self::PER_PAGE;
		$params_with_limit[] = $offset;

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE {$where_sql}
				 ORDER BY rate_group ASC, zone ASC, sort_order ASC, weight_from ASC
				 LIMIT %d OFFSET %d",
				$params_with_limit
			)
		);

		$items = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$items[] = [
					'id'           => (int) $row->id,
					'rate_card_id' => (int) $row->rate_card_id,
					'rate_group'   => $row->rate_group,
					'zone'         => $row->zone,
					'weight_label' => $row->weight_label,
					'weight_from'  => null !== $row->weight_from ? (float) $row->weight_from : null,
					'weight_to'    => null !== $row->weight_to ? (float) $row->weight_to : null,
					'billing_unit' => $row->billing_unit,
					'price_vnd'    => (int) $row->price_vnd,
					'sort_order'   => (int) $row->sort_order,
				];
			}
		}

		wp_send_json_success( [
			'items'        => $items,
			'total'        => $total,
			'page'         => $page,
			'pages'        => $pages,
			'per_page'     => self::PER_PAGE,
			'rate_card_id' => $rate_card_id,
		] );
	}

	/**
	 * AJAX: Inline update a single cell (field) of a rate row.
	 */
	public function ajax_update_cell() {
		$this->verify_security();

		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$value = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

		if ( ! $id || empty( $field ) ) {
			wp_send_json_error( [ 'message' => __( 'Thiếu ID hoặc tên trường.', 'allship-ups-quote' ) ] );
		}

		// Whitelist editable fields
		$allowed_fields = [ 'price_vnd', 'weight_from', 'weight_to', 'billing_unit', 'sort_order', 'weight_label' ];
		if ( ! in_array( $field, $allowed_fields, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Trường không được phép chỉnh sửa.', 'allship-ups-quote' ) ] );
		}

		if ( ! $this->rate_repo ) {
			wp_send_json_error( [ 'message' => __( 'Repository chưa sẵn sàng.', 'allship-ups-quote' ) ] );
		}

		// Sanitize value
		$data = [];
		switch ( $field ) {
			case 'price_vnd':
				$data['price_vnd'] = abs( (int) $value );
				break;
			case 'weight_from':
			case 'weight_to':
				$data[ $field ] = ( '' !== $value && null !== $value ) ? (float) $value : '';
				break;
			case 'billing_unit':
				if ( ! in_array( $value, [ 'flat', 'per_kg', 'minimum', 'envelope' ], true ) ) {
					wp_send_json_error( [ 'message' => __( 'Giá trị billing_unit không hợp lệ.', 'allship-ups-quote' ) ] );
				}
				$data['billing_unit'] = $value;
				break;
			case 'sort_order':
				$data['sort_order'] = abs( (int) $value );
				break;
			case 'weight_label':
				$data['weight_label'] = sanitize_text_field( $value );
				if ( empty( $data['weight_label'] ) ) {
					wp_send_json_error( [ 'message' => __( 'weight_label không được để trống.', 'allship-ups-quote' ) ] );
				}
				// weight_label is part of unique key, update via raw SQL
				$this->wpdb->update(
					$this->wpdb->prefix . 'ups_rates',
					[ 'weight_label' => $data['weight_label'] ],
					[ 'id' => $id ],
					[ '%s' ],
					[ '%d' ]
				);
				$this->invalidate_caches();
				wp_send_json_success( [ 'message' => __( 'Đã cập nhật.', 'allship-ups-quote' ) ] );
				return; // Early return since weight_label handled separately.
		}

		$result = $this->rate_repo->update( $id, $data );
		if ( ! $result ) {
			wp_send_json_error( [ 'message' => __( 'Không thể cập nhật dòng cước.', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		wp_send_json_success( [ 'message' => __( 'Đã cập nhật.', 'allship-ups-quote' ) ] );
	}

	/**
	 * AJAX: Add a new rate row.
	 */
	public function ajax_add_row() {
		$this->verify_security();

		if ( ! $this->rate_repo ) {
			wp_send_json_error( [ 'message' => __( 'Repository chưa sẵn sàng.', 'allship-ups-quote' ) ] );
		}

		$rate_card_id = isset( $_POST['rate_card_id'] ) ? absint( $_POST['rate_card_id'] ) : 0;
		$rate_group   = isset( $_POST['rate_group'] ) ? sanitize_key( wp_unslash( $_POST['rate_group'] ) ) : '';
		$zone         = isset( $_POST['zone'] ) ? sanitize_text_field( wp_unslash( $_POST['zone'] ) ) : '';
		$weight_label = isset( $_POST['weight_label'] ) ? sanitize_text_field( wp_unslash( $_POST['weight_label'] ) ) : '';
		$weight_from  = isset( $_POST['weight_from'] ) && '' !== $_POST['weight_from'] ? (float) $_POST['weight_from'] : null;
		$weight_to    = isset( $_POST['weight_to'] ) && '' !== $_POST['weight_to'] ? (float) $_POST['weight_to'] : null;
		$billing_unit = isset( $_POST['billing_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_unit'] ) ) : 'flat';
		$price_vnd    = isset( $_POST['price_vnd'] ) ? abs( (int) $_POST['price_vnd'] ) : 0;
		$sort_order   = isset( $_POST['sort_order'] ) ? abs( (int) $_POST['sort_order'] ) : 0;

		if ( ! $rate_card_id || empty( $rate_group ) || empty( $zone ) || empty( $weight_label ) ) {
			wp_send_json_error( [ 'message' => __( 'Vui lòng điền đầy đủ: Rate Card, Rate Group, Zone, Weight Label.', 'allship-ups-quote' ) ] );
		}

		$new_id = $this->rate_repo->insert( [
			'rate_card_id' => $rate_card_id,
			'rate_group'   => $rate_group,
			'zone'         => $zone,
			'weight_label' => $weight_label,
			'weight_from'  => $weight_from,
			'weight_to'    => $weight_to,
			'billing_unit' => $billing_unit,
			'price_vnd'    => $price_vnd,
			'sort_order'   => $sort_order,
		] );

		if ( ! $new_id ) {
			wp_send_json_error( [ 'message' => __( 'Không thể thêm dòng cước. Có thể trùng lặp (rate_group + zone + weight_label).', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		wp_send_json_success( [
			'id'      => $new_id,
			'message' => __( 'Đã thêm dòng cước mới.', 'allship-ups-quote' ),
		] );
	}

	/**
	 * AJAX: Update an entire rate row.
	 */
	public function ajax_update_row() {
		$this->verify_security();

		if ( ! $this->rate_repo ) {
			wp_send_json_error( [ 'message' => __( 'Repository chưa sẵn sàng.', 'allship-ups-quote' ) ] );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Thiếu ID dòng cước.', 'allship-ups-quote' ) ] );
		}

		$rate_card_id = isset( $_POST['rate_card_id'] ) ? absint( $_POST['rate_card_id'] ) : 0;
		$rate_group   = isset( $_POST['rate_group'] ) ? sanitize_key( wp_unslash( $_POST['rate_group'] ) ) : '';
		$zone         = isset( $_POST['zone'] ) ? sanitize_text_field( wp_unslash( $_POST['zone'] ) ) : '';
		$weight_label = isset( $_POST['weight_label'] ) ? sanitize_text_field( wp_unslash( $_POST['weight_label'] ) ) : '';
		$weight_from  = isset( $_POST['weight_from'] ) && '' !== $_POST['weight_from'] ? (float) $_POST['weight_from'] : null;
		$weight_to    = isset( $_POST['weight_to'] ) && '' !== $_POST['weight_to'] ? (float) $_POST['weight_to'] : null;
		$billing_unit = isset( $_POST['billing_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_unit'] ) ) : 'flat';
		$price_vnd    = isset( $_POST['price_vnd'] ) ? abs( (int) $_POST['price_vnd'] ) : 0;
		$sort_order   = isset( $_POST['sort_order'] ) ? abs( (int) $_POST['sort_order'] ) : 0;

		if ( ! $rate_card_id || empty( $rate_group ) || empty( $zone ) || empty( $weight_label ) ) {
			wp_send_json_error( [ 'message' => __( 'Vui lòng điền đầy đủ thông tin bắt buộc.', 'allship-ups-quote' ) ] );
		}

		$updated = $this->rate_repo->update( $id, [
			'rate_card_id' => $rate_card_id,
			'rate_group'   => $rate_group,
			'zone'         => $zone,
			'weight_label' => $weight_label,
			'weight_from'  => $weight_from,
			'weight_to'    => $weight_to,
			'billing_unit' => $billing_unit,
			'price_vnd'    => $price_vnd,
			'sort_order'   => $sort_order,
		] );

		if ( false === $updated ) {
			wp_send_json_error( [ 'message' => __( 'Không thể cập nhật dòng cước.', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		wp_send_json_success( [
			'message' => __( 'Đã cập nhật dòng cước.', 'allship-ups-quote' ),
		] );
	}

	/**
	 * AJAX: Delete one or more rate rows.
	 */
	public function ajax_delete_rows() {
		$this->verify_security();

		if ( ! $this->rate_repo ) {
			wp_send_json_error( [ 'message' => __( 'Repository chưa sẵn sàng.', 'allship-ups-quote' ) ] );
		}

		$ids = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : [];
		if ( is_string( $ids ) ) {
			$ids = json_decode( $ids, true );
		}
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'Vui lòng chọn ít nhất 1 dòng để xóa.', 'allship-ups-quote' ) ] );
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id && $this->rate_repo->delete( $id ) ) {
				$deleted++;
			}
		}

		$this->invalidate_caches();

		wp_send_json_success( [
			'deleted' => $deleted,
			/* translators: %d: number of deleted rows */
			'message' => sprintf( __( 'Đã xóa %d dòng cước.', 'allship-ups-quote' ), $deleted ),
		] );
	}

	/**
	 * Get rate cards list for the dropdown selector in the view.
	 *
	 * @return array
	 */
	public function get_rate_cards_dropdown() {
		if ( ! $this->rate_card_repo ) {
			return [];
		}

		$cards = $this->rate_card_repo->get_all();
		$list  = [];

		foreach ( $cards as $card ) {
			$list[] = [
				'id'     => (int) $card->id,
				'name'   => $card->name,
				'status' => $card->status,
			];
		}

		return $list;
	}

	/**
	 * Get the active rate card ID for default selection.
	 *
	 * @return int
	 */
	public function get_active_rate_card_id() {
		if ( ! $this->rate_card_repo ) {
			return 0;
		}
		return $this->rate_card_repo->get_active_id();
	}
}
