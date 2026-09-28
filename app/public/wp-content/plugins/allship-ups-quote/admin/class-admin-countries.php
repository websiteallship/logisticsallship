<?php
/**
 * Admin Countries controller & AJAX handlers.
 *
 * Implements Step 5.6 of Phase 5:
 * - List countries with search (name, normalized_name, IATA).
 * - Filter by status (active / inactive) & US override.
 * - Toggle active switch via AJAX.
 * - Toggle US override flag via AJAX.
 * - Add & update country records.
 * - Bulk active / inactive status updates.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Admin_Countries {

	/**
	 * Nonce action — shared with other admin modules.
	 */
	const NONCE_ACTION = 'allship_ups_admin';

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 50;

	/**
	 * Country repository.
	 *
	 * @var Allship_UPS_Country_Repository|null
	 */
	private $country_repo;

	/**
	 * Database instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Country_Repository|null $country_repo Optional.
	 * @param wpdb|null                           $wpdb         Optional.
	 */
	public function __construct( $country_repo = null, $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;

		if ( null === $country_repo ) {
			if ( ! class_exists( 'Allship_UPS_Country_Repository' ) && file_exists( dirname( __DIR__ ) . '/includes/class-country-repository.php' ) ) {
				require_once dirname( __DIR__ ) . '/includes/class-country-repository.php';
			}
			$country_repo = class_exists( 'Allship_UPS_Country_Repository' ) ? new Allship_UPS_Country_Repository( $this->wpdb ) : null;
		}
		$this->country_repo = $country_repo;
	}

	/**
	 * Register AJAX hooks.
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_ups_countries_list', [ $this, 'ajax_list_countries' ] );
		add_action( 'wp_ajax_ups_countries_toggle_active', [ $this, 'ajax_toggle_active' ] );
		add_action( 'wp_ajax_ups_countries_toggle_us_override', [ $this, 'ajax_toggle_us_override' ] );
		add_action( 'wp_ajax_ups_countries_add', [ $this, 'ajax_add_country' ] );
		add_action( 'wp_ajax_ups_countries_update', [ $this, 'ajax_update_country' ] );
		add_action( 'wp_ajax_ups_countries_bulk_status', [ $this, 'ajax_bulk_status' ] );
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
	 * Clear transient caches after country changes.
	 */
	private function invalidate_caches() {
		if ( ! $this->wpdb ) {
			return;
		}
		$this->wpdb->query( "DELETE FROM {$this->wpdb->options} WHERE option_name LIKE '_transient_ups_%' OR option_name LIKE '_transient_timeout_ups_%'" );
	}

	/**
	 * AJAX: List countries with search, filters, and pagination.
	 */
	public function ajax_list_countries() {
		$this->verify_security();

		if ( ! $this->wpdb ) {
			wp_send_json_error( [ 'message' => __( 'Lỗi kết nối cơ sở dữ liệu.', 'allship-ups-quote' ) ] );
		}

		$table = $this->wpdb->prefix . 'ups_countries';

		$where  = [ '1=1' ];
		$params = [];

		// Filter status
		if ( isset( $_GET['status'] ) && '' !== $_GET['status'] ) {
			$status   = sanitize_text_field( wp_unslash( $_GET['status'] ) );
			if ( 'active' === $status ) {
				$where[] = 'is_active = 1';
			} elseif ( 'inactive' === $status ) {
				$where[] = 'is_active = 0';
			}
		}

		// Filter US override
		if ( isset( $_GET['is_us_override'] ) && '' !== $_GET['is_us_override'] ) {
			$where[]  = 'is_us_override = %d';
			$params[] = absint( $_GET['is_us_override'] );
		}

		// Search term (name, normalized_name, IATA code)
		if ( ! empty( $_GET['search'] ) ) {
			$search_raw = sanitize_text_field( wp_unslash( $_GET['search'] ) );
			$like       = '%' . $this->wpdb->esc_like( $search_raw ) . '%';
			$where[]    = '( country_name LIKE %s OR normalized_name LIKE %s OR iata_code LIKE %s )';
			$params[]   = $like;
			$params[]   = $like;
			$params[]   = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// Total matching rows
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( ! empty( $params ) ) {
			$total = (int) $this->wpdb->get_var( $this->wpdb->prepare( $count_sql, $params ) );
		} else {
			$total = (int) $this->wpdb->get_var( $count_sql );
		}

		// Global counts for badges
		$active_count   = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_active = 1" );
		$inactive_count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_active = 0" );
		$all_count      = $active_count + $inactive_count;

		// Pagination
		$per_page = isset( $_GET['per_page'] ) ? max( 10, min( 200, (int) $_GET['per_page'] ) ) : self::PER_PAGE;
		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;
		$pages    = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

		// Order
		$order_by = 'country_name ASC';
		if ( ! empty( $_GET['search'] ) ) {
			// When searching, sort exact IATA match first, then prefix, then rest
			$search_iata = strtoupper( trim( sanitize_text_field( wp_unslash( $_GET['search'] ) ) ) );
			$order_by    = $this->wpdb->prepare(
				"CASE WHEN iata_code = %s THEN 1 WHEN country_name LIKE %s THEN 2 ELSE 3 END, country_name ASC",
				$search_iata,
				$this->wpdb->esc_like( $search_iata ) . '%'
			);
		}

		$params_with_limit   = $params;
		$params_with_limit[] = $per_page;
		$params_with_limit[] = $offset;

		$select_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d";
		$rows       = $this->wpdb->get_results( $this->wpdb->prepare( $select_sql, $params_with_limit ) );

		$items = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$items[] = [
					'id'                     => (int) $row->id,
					'iata_code'              => $row->iata_code,
					'country_name'           => $row->country_name,
					'normalized_name'        => $row->normalized_name,
					'is_us_override'         => (int) $row->is_us_override,
					'has_extended_area_note' => (int) $row->has_extended_area_note,
					'is_active'              => (int) $row->is_active,
				];
			}
		}

		wp_send_json_success( [
			'items'          => $items,
			'total'          => $total,
			'page'           => $page,
			'pages'          => $pages,
			'per_page'       => $per_page,
			'all_count'      => $all_count,
			'active_count'   => $active_count,
			'inactive_count' => $inactive_count,
		] );
	}

	/**
	 * AJAX: Toggle is_active switch for a country.
	 */
	public function ajax_toggle_active() {
		$this->verify_security();

		if ( ! $this->country_repo ) {
			wp_send_json_error( [ 'message' => __( 'Repository chưa sẵn sàng.', 'allship-ups-quote' ) ] );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Thiếu ID quốc gia.', 'allship-ups-quote' ) ] );
		}

		$is_active = isset( $_POST['is_active'] ) ? ( (int) $_POST['is_active'] === 1 ? 1 : 0 ) : null;
		$updated   = $this->country_repo->toggle_active( $id, $is_active );

		if ( ! $updated ) {
			wp_send_json_error( [ 'message' => __( 'Không thể cập nhật trạng thái quốc gia.', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		// Fetch current state
		$current_status = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT is_active FROM {$this->wpdb->prefix}ups_countries WHERE id = %d", $id )
		);

		wp_send_json_success( [
			'id'        => $id,
			'is_active' => $current_status,
			'message'   => $current_status ? __( 'Đã kích hoạt quốc gia.', 'allship-ups-quote' ) : __( 'Đã tắt kích hoạt quốc gia.', 'allship-ups-quote' ),
		] );
	}

	/**
	 * AJAX: Toggle is_us_override flag for a country.
	 */
	public function ajax_toggle_us_override() {
		$this->verify_security();

		if ( ! $this->country_repo ) {
			wp_send_json_error( [ 'message' => __( 'Repository chưa sẵn sàng.', 'allship-ups-quote' ) ] );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Thiếu ID quốc gia.', 'allship-ups-quote' ) ] );
		}

		$current = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT is_us_override FROM {$this->wpdb->prefix}ups_countries WHERE id = %d", $id )
		);
		$new_val = $current ? 0 : 1;

		$updated = $this->country_repo->update( $id, [ 'is_us_override' => $new_val ] );
		if ( ! $updated ) {
			wp_send_json_error( [ 'message' => __( 'Không thể cập nhật cờ US Override.', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		wp_send_json_success( [
			'id'             => $id,
			'is_us_override' => $new_val,
			'message'        => $new_val ? __( 'Đã bật US Override cho quốc gia.', 'allship-ups-quote' ) : __( 'Đã tắt US Override cho quốc gia.', 'allship-ups-quote' ),
		] );
	}

	/**
	 * AJAX: Add new country record.
	 */
	public function ajax_add_country() {
		$this->verify_security();

		if ( ! $this->country_repo ) {
			wp_send_json_error( [ 'message' => __( 'Repository chưa sẵn sàng.', 'allship-ups-quote' ) ] );
		}

		$iata_code    = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['iata_code'] ?? '' ) ) ) );
		$country_name = trim( sanitize_text_field( wp_unslash( $_POST['country_name'] ?? '' ) ) );

		if ( empty( $iata_code ) || empty( $country_name ) ) {
			wp_send_json_error( [ 'message' => __( 'Vui lòng nhập Mã IATA và Tên quốc gia.', 'allship-ups-quote' ) ] );
		}

		if ( strlen( $iata_code ) > 10 ) {
			wp_send_json_error( [ 'message' => __( 'Mã IATA không được dài quá 10 ký tự.', 'allship-ups-quote' ) ] );
		}

		// Check duplicate IATA
		$existing = $this->country_repo->find_by_iata( $iata_code );
		if ( $existing ) {
			wp_send_json_error( [ 'message' => sprintf( __( 'Mã IATA "%s" đã tồn tại.', 'allship-ups-quote' ), $iata_code ) ] );
		}

		$normalized_name = isset( $_POST['normalized_name'] ) && '' !== trim( $_POST['normalized_name'] )
			? sanitize_text_field( wp_unslash( $_POST['normalized_name'] ) )
			: trim( str_replace( '*', '', $country_name ) );

		$is_us_override  = isset( $_POST['is_us_override'] ) ? ( (int) $_POST['is_us_override'] === 1 ? 1 : 0 ) : ( 'US' === $iata_code ? 1 : 0 );
		$has_extended    = isset( $_POST['has_extended_area_note'] ) ? ( (int) $_POST['has_extended_area_note'] === 1 ? 1 : 0 ) : ( strpos( $country_name, '*' ) !== false ? 1 : 0 );
		$is_active       = isset( $_POST['is_active'] ) ? ( (int) $_POST['is_active'] === 1 ? 1 : 0 ) : 1;

		$new_id = $this->country_repo->insert( [
			'iata_code'              => $iata_code,
			'country_name'           => $country_name,
			'normalized_name'        => $normalized_name,
			'is_us_override'         => $is_us_override,
			'has_extended_area_note' => $has_extended,
			'is_active'              => $is_active,
		] );

		if ( ! $new_id ) {
			wp_send_json_error( [ 'message' => __( 'Không thể thêm quốc gia mới.', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		wp_send_json_success( [
			'id'      => $new_id,
			'message' => __( 'Đã thêm quốc gia thành công.', 'allship-ups-quote' ),
		] );
	}

	/**
	 * AJAX: Update an existing country record.
	 */
	public function ajax_update_country() {
		$this->verify_security();

		if ( ! $this->country_repo ) {
			wp_send_json_error( [ 'message' => __( 'Repository chưa sẵn sàng.', 'allship-ups-quote' ) ] );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Thiếu ID quốc gia.', 'allship-ups-quote' ) ] );
		}

		$iata_code    = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['iata_code'] ?? '' ) ) ) );
		$country_name = trim( sanitize_text_field( wp_unslash( $_POST['country_name'] ?? '' ) ) );

		if ( empty( $iata_code ) || empty( $country_name ) ) {
			wp_send_json_error( [ 'message' => __( 'Mã IATA và Tên quốc gia không được để trống.', 'allship-ups-quote' ) ] );
		}

		// Check if IATA is taken by another country
		$existing = $this->country_repo->find_by_iata( $iata_code );
		if ( $existing && (int) $existing->id !== $id ) {
			wp_send_json_error( [ 'message' => sprintf( __( 'Mã IATA "%s" đã thuộc về quốc gia khác.', 'allship-ups-quote' ), $iata_code ) ] );
		}

		$normalized_name = isset( $_POST['normalized_name'] ) && '' !== trim( $_POST['normalized_name'] )
			? sanitize_text_field( wp_unslash( $_POST['normalized_name'] ) )
			: trim( str_replace( '*', '', $country_name ) );

		$is_us_override  = isset( $_POST['is_us_override'] ) ? ( (int) $_POST['is_us_override'] === 1 ? 1 : 0 ) : 0;
		$has_extended    = isset( $_POST['has_extended_area_note'] ) ? ( (int) $_POST['has_extended_area_note'] === 1 ? 1 : 0 ) : 0;
		$is_active       = isset( $_POST['is_active'] ) ? ( (int) $_POST['is_active'] === 1 ? 1 : 0 ) : 1;

		$updated = $this->country_repo->update( $id, [
			'iata_code'              => $iata_code,
			'country_name'           => $country_name,
			'normalized_name'        => $normalized_name,
			'is_us_override'         => $is_us_override,
			'has_extended_area_note' => $has_extended,
			'is_active'              => $is_active,
		] );

		if ( false === $updated ) {
			wp_send_json_error( [ 'message' => __( 'Không thể cập nhật quốc gia.', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		wp_send_json_success( [
			'message' => __( 'Đã cập nhật thông tin quốc gia.', 'allship-ups-quote' ),
		] );
	}

	/**
	 * AJAX: Bulk activate or deactivate countries.
	 */
	public function ajax_bulk_status() {
		$this->verify_security();

		if ( ! $this->country_repo ) {
			wp_send_json_error( [ 'message' => __( 'Repository chưa sẵn sàng.', 'allship-ups-quote' ) ] );
		}

		$ids = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : [];
		if ( is_string( $ids ) ) {
			$ids = json_decode( $ids, true );
		}
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'Vui lòng chọn ít nhất 1 quốc gia.', 'allship-ups-quote' ) ] );
		}

		$is_active = isset( $_POST['is_active'] ) && (int) $_POST['is_active'] === 1 ? 1 : 0;
		$count     = 0;

		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id && $this->country_repo->toggle_active( $id, $is_active ) ) {
				$count++;
			}
		}

		$this->invalidate_caches();

		wp_send_json_success( [
			'count'   => $count,
			'message' => sprintf(
				$is_active
					? __( 'Đã kích hoạt %d quốc gia thành công.', 'allship-ups-quote' )
					: __( 'Đã tắt kích hoạt %d quốc gia thành công.', 'allship-ups-quote' ),
				$count
			),
		] );
	}
}
