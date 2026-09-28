<?php
/**
 * Admin Zones controller & AJAX handlers.
 *
 * Implements Step 5.5 of Phase 5:
 * - List zones with filters (zone_set_id / rate_card_id, direction, service_code, search country).
 * - Pagination (50 rows per page).
 * - Inline edit: click cell → input → AJAX save.
 * - Add new zone mapping row.
 * - Bulk delete selected rows.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Admin_Zones {

	/**
	 * Nonce action — shared with other admin modules.
	 */
	const NONCE_ACTION = 'allship_ups_admin';

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 50;

	/**
	 * Zone repository.
	 *
	 * @var Allship_UPS_Zone_Repository|null
	 */
	private $zone_repo;

	/**
	 * Country repository.
	 *
	 * @var Allship_UPS_Country_Repository|null
	 */
	private $country_repo;

	/**
	 * Rate card repository.
	 *
	 * @var Allship_UPS_Rate_Card_Repository|null
	 */
	private $rate_card_repo;

	/**
	 * Zone set repository.
	 *
	 * @var Allship_UPS_Zone_Set_Repository|null
	 */
	private $zone_set_repo;

	/**
	 * Database instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Zone_Repository|null      $zone_repo      Optional.
	 * @param Allship_UPS_Country_Repository|null   $country_repo   Optional.
	 * @param Allship_UPS_Rate_Card_Repository|null $rate_card_repo Optional.
	 * @param Allship_UPS_Zone_Set_Repository|null  $zone_set_repo  Optional.
	 * @param wpdb|null                             $wpdb           Optional.
	 */
	public function __construct( $zone_repo = null, $country_repo = null, $rate_card_repo = null, $zone_set_repo = null, $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;

		if ( null === $zone_repo ) {
			if ( ! class_exists( 'Allship_UPS_Zone_Repository' ) && file_exists( dirname( __DIR__ ) . '/includes/class-zone-repository.php' ) ) {
				require_once dirname( __DIR__ ) . '/includes/class-zone-repository.php';
			}
			$zone_repo = class_exists( 'Allship_UPS_Zone_Repository' ) ? new Allship_UPS_Zone_Repository( $this->wpdb ) : null;
		}
		$this->zone_repo = $zone_repo;

		if ( null === $country_repo ) {
			if ( ! class_exists( 'Allship_UPS_Country_Repository' ) && file_exists( dirname( __DIR__ ) . '/includes/class-country-repository.php' ) ) {
				require_once dirname( __DIR__ ) . '/includes/class-country-repository.php';
			}
			$country_repo = class_exists( 'Allship_UPS_Country_Repository' ) ? new Allship_UPS_Country_Repository( $this->wpdb ) : null;
		}
		$this->country_repo = $country_repo;

		if ( null === $rate_card_repo ) {
			if ( ! class_exists( 'Allship_UPS_Rate_Card_Repository' ) && file_exists( dirname( __DIR__ ) . '/includes/class-rate-card-repository.php' ) ) {
				require_once dirname( __DIR__ ) . '/includes/class-rate-card-repository.php';
			}
			$rate_card_repo = class_exists( 'Allship_UPS_Rate_Card_Repository' ) ? new Allship_UPS_Rate_Card_Repository( $this->wpdb ) : null;
		}
		$this->rate_card_repo = $rate_card_repo;

		if ( null === $zone_set_repo ) {
			if ( ! class_exists( 'Allship_UPS_Zone_Set_Repository' ) && file_exists( dirname( __DIR__ ) . '/includes/class-zone-set-repository.php' ) ) {
				require_once dirname( __DIR__ ) . '/includes/class-zone-set-repository.php';
			}
			$zone_set_repo = class_exists( 'Allship_UPS_Zone_Set_Repository' ) ? new Allship_UPS_Zone_Set_Repository( $this->wpdb ) : null;
		}
		$this->zone_set_repo = $zone_set_repo;
	}

	/**
	 * Register AJAX hooks.
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_ups_zones_list', [ $this, 'ajax_list_zones' ] );
		add_action( 'wp_ajax_ups_zones_update_cell', [ $this, 'ajax_update_cell' ] );
		add_action( 'wp_ajax_ups_zones_add_row', [ $this, 'ajax_add_row' ] );
		add_action( 'wp_ajax_ups_zones_update_row', [ $this, 'ajax_update_row' ] );
		add_action( 'wp_ajax_ups_zones_delete_rows', [ $this, 'ajax_delete_rows' ] );
		add_action( 'wp_ajax_ups_zones_get_filters', [ $this, 'ajax_get_filters' ] );
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
	 * Clear transient caches after zone changes.
	 */
	private function invalidate_caches() {
		if ( ! $this->wpdb ) {
			return;
		}
		$this->wpdb->query( "DELETE FROM {$this->wpdb->options} WHERE option_name LIKE '_transient_ups_%' OR option_name LIKE '_transient_timeout_ups_%'" );
	}

	/**
	 * Resolve zone_set_id from request params (supports zone_set_id or rate_card_id).
	 *
	 * @return int
	 */
	private function resolve_requested_zone_set_id() {
		if ( isset( $_GET['zone_set_id'] ) && absint( $_GET['zone_set_id'] ) > 0 ) {
			return absint( $_GET['zone_set_id'] );
		}
		if ( isset( $_POST['zone_set_id'] ) && absint( $_POST['zone_set_id'] ) > 0 ) {
			return absint( $_POST['zone_set_id'] );
		}
		// Fallback for rate_card_id
		$rc_id = isset( $_GET['rate_card_id'] ) ? absint( $_GET['rate_card_id'] ) : ( isset( $_POST['rate_card_id'] ) ? absint( $_POST['rate_card_id'] ) : 0 );
		if ( $rc_id && $this->rate_card_repo ) {
			$card = $this->rate_card_repo->get( $rc_id );
			if ( $card && ! empty( $card->zone_set_id ) ) {
				return (int) $card->zone_set_id;
			}
		}
		return $rc_id;
	}

	/**
	 * AJAX: Get available filter options (directions, service_codes, zones) for a zone set.
	 */
	public function ajax_get_filters() {
		$this->verify_security();

		$zone_set_id = $this->resolve_requested_zone_set_id();
		if ( ! $zone_set_id || ! $this->wpdb ) {
			wp_send_json_error( [ 'message' => __( 'Thiếu zone_set_id.', 'allship-ups-quote' ) ] );
		}

		$table = $this->wpdb->prefix . 'ups_zone_maps';

		// Distinct directions
		$directions = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT direction FROM {$table} WHERE (zone_set_id = %d OR rate_card_id = %d) ORDER BY direction ASC",
				$zone_set_id,
				$zone_set_id
			)
		);

		// Distinct service_codes
		$services = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT service_code FROM {$table} WHERE (zone_set_id = %d OR rate_card_id = %d) ORDER BY service_code ASC",
				$zone_set_id,
				$zone_set_id
			)
		);

		// Distinct zones (non-empty)
		$zones = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT zone FROM {$table} WHERE (zone_set_id = %d OR rate_card_id = %d) AND zone IS NOT NULL AND zone != '' ORDER BY CAST(zone AS UNSIGNED) ASC, zone ASC",
				$zone_set_id,
				$zone_set_id
			)
		);

		// Available countries for modal
		$countries = $this->get_countries_dropdown();

		wp_send_json_success( [
			'directions' => is_array( $directions ) ? $directions : [],
			'services'   => is_array( $services ) ? $services : [],
			'zones'      => is_array( $zones ) ? $zones : [],
			'countries'  => $countries,
		] );
	}

	/**
	 * AJAX: List zones with filters and pagination.
	 */
	public function ajax_list_zones() {
		$this->verify_security();

		$zone_set_id = $this->resolve_requested_zone_set_id();
		if ( ! $zone_set_id || ! $this->wpdb ) {
			wp_send_json_error( [ 'message' => __( 'Vui lòng chọn một Bảng phân vùng (Zone Set).', 'allship-ups-quote' ) ] );
		}

		$table_zm        = $this->wpdb->prefix . 'ups_zone_maps';
		$table_countries = $this->wpdb->prefix . 'ups_countries';

		// Build WHERE clause
		$where  = [ '(zm.zone_set_id = %d OR zm.rate_card_id = %d)' ];
		$params = [ $zone_set_id, $zone_set_id ];

		if ( ! empty( $_GET['direction'] ) ) {
			$where[]  = 'zm.direction = %s';
			$params[] = strtolower( sanitize_key( wp_unslash( $_GET['direction'] ) ) );
		}

		if ( ! empty( $_GET['service_code'] ) ) {
			$where[]  = 'zm.service_code = %s';
			$params[] = strtoupper( sanitize_text_field( wp_unslash( $_GET['service_code'] ) ) );
		}

		if ( isset( $_GET['zone'] ) && '' !== $_GET['zone'] ) {
			$where[]  = 'zm.zone = %s';
			$params[] = sanitize_text_field( wp_unslash( $_GET['zone'] ) );
		}

		if ( isset( $_GET['is_available'] ) && '' !== $_GET['is_available'] ) {
			$where[]  = 'zm.is_available = %d';
			$params[] = absint( $_GET['is_available'] );
		}

		if ( ! empty( $_GET['search'] ) ) {
			$search   = '%' . $this->wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['search'] ) ) ) . '%';
			$where[]  = '(c.country_name LIKE %s OR c.iata_code LIKE %s OR zm.zone LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		$where_sql = implode( ' AND ', $where );

		// Count total
		$count_sql = "SELECT COUNT(*) FROM {$table_zm} zm
		              LEFT JOIN {$table_countries} c ON zm.country_id = c.id
		              WHERE {$where_sql}";
		$total     = (int) $this->wpdb->get_var( $this->wpdb->prepare( $count_sql, $params ) );

		// Pagination
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$limit  = self::PER_PAGE;
		$offset = ( $page - 1 ) * $limit;
		$pages  = max( 1, (int) ceil( $total / $limit ) );

		// Order
		$order_by = 'c.country_name ASC, zm.service_code ASC';
		if ( ! empty( $_GET['orderby'] ) ) {
			$col = sanitize_key( wp_unslash( $_GET['orderby'] ) );
			$dir = ( isset( $_GET['order'] ) && 'DESC' === strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'DESC' : 'ASC';
			$allowed = [
				'country_name' => 'c.country_name',
				'iata_code'    => 'c.iata_code',
				'direction'    => 'zm.direction',
				'service_code' => 'zm.service_code',
				'service_type' => 'zm.service_type',
				'zone'         => 'CAST(zm.zone AS UNSIGNED) ' . $dir . ', zm.zone',
				'is_available' => 'zm.is_available',
			];
			if ( isset( $allowed[ $col ] ) ) {
				$order_by = ( 'zone' === $col ) ? $allowed[ $col ] : ( $allowed[ $col ] . ' ' . $dir );
			}
		}

		$params_paged   = $params;
		$params_paged[] = $limit;
		$params_paged[] = $offset;

		$query = "SELECT zm.*, c.iata_code, c.country_name, c.is_us_override, c.has_extended_area_note
		          FROM {$table_zm} zm
		          LEFT JOIN {$table_countries} c ON zm.country_id = c.id
		          WHERE {$where_sql}
		          ORDER BY {$order_by}
		          LIMIT %d OFFSET %d";

		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $query, $params_paged ) );

		$items = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$items[] = [
					'id'                     => (int) $r->id,
					'country_id'             => (int) $r->country_id,
					'iata_code'              => $r->iata_code ?? '',
					'country_name'           => $r->country_name ?? '',
					'is_us_override'         => (int) ( $r->is_us_override ?? 0 ),
					'has_extended_area_note' => (int) ( $r->has_extended_area_note ?? 0 ),
					'direction'              => $r->direction,
					'service_code'           => $r->service_code,
					'service_type'           => $r->service_type,
					'zone'                   => $r->zone ?? '',
					'is_available'           => (int) $r->is_available,
				];
			}
		}

		wp_send_json_success( [
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'pages' => $pages,
			'limit' => $limit,
		] );
	}

	/**
	 * AJAX: Inline edit a cell value (zone or is_available).
	 */
	public function ajax_update_cell() {
		$this->verify_security();

		if ( ! $this->zone_repo ) {
			wp_send_json_error( [ 'message' => __( 'Repository chưa sẵn sàng.', 'allship-ups-quote' ) ] );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Thiếu ID dòng vùng cước.', 'allship-ups-quote' ) ] );
		}

		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$value = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

		$allowed_fields = [ 'zone', 'is_available', 'service_type' ];
		if ( ! in_array( $field, $allowed_fields, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Trường cập nhật không hợp lệ.', 'allship-ups-quote' ) ] );
		}

		$update_data = [];
		if ( 'zone' === $field ) {
			$zone_clean = trim( (string) $value );
			$update_data['zone'] = ( '' === $zone_clean ) ? null : $zone_clean;
			// Automatically mark unavailable if zone is empty or 0
			if ( null === $update_data['zone'] || '0' === $update_data['zone'] ) {
				$update_data['is_available'] = 0;
			} else {
				$update_data['is_available'] = 1;
			}
		} elseif ( 'is_available' === $field ) {
			$update_data['is_available'] = absint( $value ) ? 1 : 0;
		} elseif ( 'service_type' === $field ) {
			$update_data['service_type'] = '' !== trim( (string) $value ) ? trim( (string) $value ) : null;
		}

		$res = $this->zone_repo->update( $id, $update_data );

		if ( ! $res ) {
			wp_send_json_error( [ 'message' => __( 'Không thể cập nhật ô dữ liệu.', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		wp_send_json_success( [
			'message'      => __( 'Đã lưu thay đổi.', 'allship-ups-quote' ),
			'is_available' => isset( $update_data['is_available'] ) ? $update_data['is_available'] : null,
		] );
	}

	/**
	 * AJAX: Add a new zone row.
	 */
	public function ajax_add_row() {
		$this->verify_security();

		if ( ! $this->zone_repo ) {
			wp_send_json_error( [ 'message' => __( 'Repository chưa sẵn sàng.', 'allship-ups-quote' ) ] );
		}

		$zone_set_id  = $this->resolve_requested_zone_set_id();
		$country_id   = isset( $_POST['country_id'] ) ? absint( $_POST['country_id'] ) : 0;
		$direction    = isset( $_POST['direction'] ) ? strtolower( sanitize_key( wp_unslash( $_POST['direction'] ) ) ) : 'export';
		$service_code = isset( $_POST['service_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['service_code'] ) ) ) : '';
		$service_type = isset( $_POST['service_type'] ) && '' !== $_POST['service_type'] ? sanitize_text_field( wp_unslash( $_POST['service_type'] ) ) : null;
		$zone         = isset( $_POST['zone'] ) ? sanitize_text_field( wp_unslash( $_POST['zone'] ) ) : '';
		$is_available = isset( $_POST['is_available'] ) ? absint( $_POST['is_available'] ) : ( ( '' !== $zone && '0' !== $zone ) ? 1 : 0 );

		if ( ! $zone_set_id || ! $country_id || empty( $direction ) || empty( $service_code ) ) {
			wp_send_json_error( [ 'message' => __( 'Vui lòng điền đầy đủ: Bảng phân vùng (Zone Set), Quốc gia, Chiều vận chuyển, Mã dịch vụ.', 'allship-ups-quote' ) ] );
		}

		$new_id = $this->zone_repo->insert( [
			'zone_set_id'  => $zone_set_id,
			'rate_card_id' => $zone_set_id,
			'country_id'   => $country_id,
			'direction'    => $direction,
			'service_code' => $service_code,
			'service_type' => $service_type,
			'zone'         => $zone,
			'is_available' => $is_available,
		] );

		if ( ! $new_id ) {
			wp_send_json_error( [ 'message' => __( 'Không thể thêm vùng cước. Có thể trùng lặp (quốc gia + chiều + dịch vụ).', 'allship-ups-quote' ) ] );
		}

		if ( $this->zone_set_repo ) {
			$this->zone_set_repo->update_record_count( $zone_set_id );
		}

		$this->invalidate_caches();

		wp_send_json_success( [
			'id'      => $new_id,
			'message' => __( 'Đã lưu vùng cước thành công.', 'allship-ups-quote' ),
		] );
	}

	/**
	 * AJAX: Update an entire zone row.
	 */
	public function ajax_update_row() {
		$this->verify_security();

		if ( ! $this->zone_repo ) {
			wp_send_json_error( [ 'message' => __( 'Repository chưa sẵn sàng.', 'allship-ups-quote' ) ] );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Thiếu ID dòng vùng cước.', 'allship-ups-quote' ) ] );
		}

		$service_type = isset( $_POST['service_type'] ) ? sanitize_text_field( wp_unslash( $_POST['service_type'] ) ) : null;
		$zone         = isset( $_POST['zone'] ) ? sanitize_text_field( wp_unslash( $_POST['zone'] ) ) : '';
		$is_available = isset( $_POST['is_available'] ) ? absint( $_POST['is_available'] ) : ( ( '' !== $zone && '0' !== $zone ) ? 1 : 0 );

		$updated = $this->zone_repo->update( $id, [
			'service_type' => $service_type,
			'zone'         => $zone,
			'is_available' => $is_available,
		] );

		if ( false === $updated ) {
			wp_send_json_error( [ 'message' => __( 'Không thể cập nhật vùng cước.', 'allship-ups-quote' ) ] );
		}

		$this->invalidate_caches();

		wp_send_json_success( [
			'message' => __( 'Đã cập nhật vùng cước thành công.', 'allship-ups-quote' ),
		] );
	}

	/**
	 * AJAX: Delete one or more zone rows.
	 */
	public function ajax_delete_rows() {
		$this->verify_security();

		if ( ! $this->zone_repo ) {
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
			if ( $id && $this->zone_repo->delete( $id ) ) {
				$deleted++;
			}
		}

		$this->invalidate_caches();

		wp_send_json_success( [
			'deleted' => $deleted,
			/* translators: %d: number of deleted rows */
			'message' => sprintf( __( 'Đã xóa %d vùng cước.', 'allship-ups-quote' ), $deleted ),
		] );
	}

	/**
	 * Get list of all zone sets for the dropdown selector.
	 *
	 * @return array
	 */
	public function get_zone_sets_dropdown() {
		if ( ! $this->zone_set_repo ) {
			return [];
		}

		$sets = $this->zone_set_repo->get_all();
		$list = [];

		foreach ( $sets as $s ) {
			$list[] = [
				'id'           => (int) $s->id,
				'name'         => $s->name,
				'record_count' => (int) $s->record_count,
				'used_count'   => (int) $s->used_count,
			];
		}

		return $list;
	}

	/**
	 * Get the active or first zone set ID for default selection.
	 *
	 * @return int
	 */
	public function get_active_zone_set_id() {
		if ( $this->rate_card_repo ) {
			$active_card = $this->rate_card_repo->get_active();
			if ( $active_card && ! empty( $active_card->zone_set_id ) ) {
				return (int) $active_card->zone_set_id;
			}
		}

		if ( $this->zone_set_repo ) {
			$first = $this->zone_set_repo->get_default_or_first();
			if ( $first ) {
				return (int) $first->id;
			}
		}

		return 0;
	}

	/**
	 * Backward compatibility: get rate cards list for selector.
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
				'id'          => (int) $card->id,
				'name'        => $card->name,
				'status'      => $card->status,
				'zone_set_id' => isset( $card->zone_set_id ) ? (int) $card->zone_set_id : null,
			];
		}

		return $list;
	}

	/**
	 * Backward compatibility: get active rate card ID.
	 *
	 * @return int
	 */
	public function get_active_rate_card_id() {
		if ( ! $this->rate_card_repo ) {
			return 0;
		}
		return $this->rate_card_repo->get_active_id();
	}

	/**
	 * Get list of countries for the Add Zone modal dropdown.
	 *
	 * @return array
	 */
	public function get_countries_dropdown() {
		if ( ! $this->country_repo ) {
			return [];
		}

		$countries = $this->country_repo->get_all_active();
		$list      = [];

		foreach ( $countries as $c ) {
			$list[] = [
				'id'           => (int) $c->id,
				'iata_code'    => $c->iata_code,
				'country_name' => $c->country_name,
			];
		}

		return $list;
	}
}
