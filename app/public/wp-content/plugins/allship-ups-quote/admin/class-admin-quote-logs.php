<?php
/**
 * Admin Quote Logs controller & WP_List_Table implementation.
 *
 * Implements Step 5.8 of Phase 5:
 * - WP_List_Table for displaying quote logs and leads.
 * - Filtering by date range (date_from, date_to), service_code, destination_iata, direction, search.
 * - Streaming export to CSV (with UTF-8 BOM for Microsoft Excel) and XML Excel.
 * - Nonce validation and strict capability gating ('manage_options').
 * - AJAX log detail modal viewer.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load WordPress WP_List_Table base class if not already loaded
if ( ! class_exists( 'WP_List_Table' ) ) {
	$list_table_path = ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	if ( file_exists( $list_table_path ) ) {
		require_once $list_table_path;
	}
}

/**
 * Custom WP_List_Table for Allship UPS Quote Logs.
 */
class Allship_UPS_Quote_Logs_List_Table extends WP_List_Table {

	/**
	 * Quote log repository instance.
	 *
	 * @var Allship_UPS_Quote_Log_Repository
	 */
	private $repo;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Quote_Log_Repository|null $repo Optional repository.
	 */
	public function __construct( $repo = null ) {
		parent::__construct(
			[
				'singular' => 'quote_log',
				'plural'   => 'quote_logs',
				'ajax'     => false,
			]
		);

		if ( null === $repo ) {
			if ( ! class_exists( 'Allship_UPS_Quote_Log_Repository' ) && file_exists( dirname( __DIR__ ) . '/includes/class-quote-log-repository.php' ) ) {
				require_once dirname( __DIR__ ) . '/includes/class-quote-log-repository.php';
			}
			$this->repo = class_exists( 'Allship_UPS_Quote_Log_Repository' ) ? new Allship_UPS_Quote_Log_Repository() : null;
		} else {
			$this->repo = $repo;
		}
	}

	/**
	 * Get table columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return [
			'cb'            => '<input type="checkbox" />',
			'id'            => __( 'Mã log', 'allship-ups-quote' ),
			'created_at'    => __( 'Thời gian', 'allship-ups-quote' ),
			'client'        => __( 'Thiết bị / IP', 'allship-ups-quote' ),
			'direction'     => __( 'Chiều', 'allship-ups-quote' ),
			'route'         => __( 'Tuyến đường', 'allship-ups-quote' ),
			'service_code'  => __( 'Dịch vụ', 'allship-ups-quote' ),
			'shipment_type' => __( 'Phân loại', 'allship-ups-quote' ),
			'weights'       => __( 'Trọng lượng', 'allship-ups-quote' ),
			'total_price'   => __( 'Tổng cước tạm tính', 'allship-ups-quote' ),
			'actions'       => __( 'Thao tác', 'allship-ups-quote' ),
		];
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array<string, array>
	 */
	public function get_sortable_columns() {
		return [
			'id'               => [ 'id', false ],
			'created_at'       => [ 'created_at', true ], // Default DESC
			'total_price'      => [ 'total_price_vnd', false ],
			'destination_iata' => [ 'destination_iata', false ],
		];
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions() {
		return [
			'bulk_delete' => __( 'Xóa bản ghi đã chọn', 'allship-ups-quote' ),
		];
	}

	/**
	 * Render Checkbox column.
	 *
	 * @param object $item Log row item.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="log_ids[]" value="%d" />',
			absint( $item->id )
		);
	}

	/**
	 * Render ID column.
	 *
	 * @param object $item Log row item.
	 * @return string
	 */
	protected function column_id( $item ) {
		return sprintf(
			'<strong>#%d</strong>',
			absint( $item->id )
		);
	}

	/**
	 * Render Date & Time column.
	 *
	 * @param object $item Log row item.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		$time = ! empty( $item->created_at ) ? strtotime( $item->created_at ) : 0;
		if ( ! $time ) {
			return '—';
		}
		return sprintf(
			'<span title="%s">%s</span><br><small class="text-muted">%s</small>',
			esc_attr( $item->created_at ),
			esc_html( date( 'd/m/Y', $time ) ),
			esc_html( date( 'H:i:s', $time ) )
		);
	}

	/**
	 * Render Client (Device & IP) column.
	 *
	 * @param object $item Log row item.
	 * @return string
	 */
	protected function column_client( $item ) {
		$device = ! empty( $item->device_type ) ? esc_html( ucfirst( $item->device_type ) ) : 'Desktop';
		$ip     = ! empty( $item->ip_address ) ? esc_html( $item->ip_address ) : '—';
		$icon   = 'dashicons-desktop';
		$dtype  = strtolower( (string) ( $item->device_type ?? '' ) );
		if ( 'mobile' === $dtype ) {
			$icon = 'dashicons-smartphone';
		} elseif ( 'tablet' === $dtype ) {
			$icon = 'dashicons-tablet';
		}

		return sprintf(
			'<div style="font-size:12px;line-height:1.4;">' .
			'<span><span class="dashicons %s" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:2px;"></span>%s</span><br>' .
			'<small style="color:#64748b;font-family:monospace;">%s</small>' .
			'</div>',
			esc_attr( $icon ),
			$device,
			$ip
		);
	}

	/**
	 * Render Direction column.
	 *
	 * @param object $item Log row item.
	 * @return string
	 */
	protected function column_direction( $item ) {
		$dir = strtolower( (string) $item->direction );
		if ( 'import' === $dir ) {
			return '<span class="as-badge" style="background:#e0f2fe;color:#0284c7;font-weight:700;">' . esc_html__( 'Nhập hàng', 'allship-ups-quote' ) . '</span>';
		}
		return '<span class="as-badge" style="background:#ecfdf5;color:#059669;font-weight:700;">' . esc_html__( 'Xuất hàng', 'allship-ups-quote' ) . '</span>';
	}

	/**
	 * Render Route column.
	 *
	 * @param object $item Log row item.
	 * @return string
	 */
	protected function column_route( $item ) {
		$origin = esc_html( $item->origin_iata ?? 'VN' );
		$dest   = esc_html( $item->destination_iata ?? '—' );
		$city   = ! empty( $item->destination_city ) ? ' (' . esc_html( $item->destination_city ) . ')' : '';

		return sprintf(
			'<strong>%s &rarr; %s%s</strong>',
			$origin,
			$dest,
			$city
		);
	}

	/**
	 * Render Service column.
	 *
	 * @param object $item Log row item.
	 * @return string
	 */
	protected function column_service_code( $item ) {
		$code = strtoupper( (string) $item->service_code );
		$name_map = [
			'EXW' => 'Express Early',
			'XPR' => 'Express Plus',
			'WXS' => 'Express Saver',
			'XPD' => 'Expedited',
			'WXP' => 'Express Freight',
			'WFM' => 'Freight Midday',
		];
		$svc_name = $name_map[ $code ] ?? $code;

		return sprintf(
			'<span class="as-tag" style="background:#f1f5f9;font-weight:700;color:#0f172a;padding:2px 8px;border:1px solid #cbd5e1;">%s</span><br><small style="color:#64748b;">%s</small>',
			esc_html( $code ),
			esc_html( $svc_name )
		);
	}

	/**
	 * Render Shipment Type column.
	 *
	 * @param object $item Log row item.
	 * @return string
	 */
	protected function column_shipment_type( $item ) {
		$type = strtolower( (string) $item->shipment_type );
		if ( 'document' === $type ) {
			return '<span style="color:#2563eb;font-weight:600;">' . esc_html__( 'Chứng từ (Doc)', 'allship-ups-quote' ) . '</span>';
		}
		return '<span style="color:#475569;">' . esc_html__( 'Hàng hóa (Non-doc)', 'allship-ups-quote' ) . '</span>';
	}

	/**
	 * Render Weights breakdown column.
	 *
	 * @param object $item Log row item.
	 * @return string
	 */
	protected function column_weights( $item ) {
		$actual = null !== $item->actual_weight_kg ? number_format( $item->actual_weight_kg, 1 ) . ' kg' : '—';
		$dim    = null !== $item->dim_weight_kg ? number_format( $item->dim_weight_kg, 1 ) . ' kg' : '—';
		$charge = null !== $item->chargeable_weight_kg ? number_format( $item->chargeable_weight_kg, 1 ) . ' kg' : '—';

		return sprintf(
			'<div style="font-size:12px;line-height:1.4;">' .
			'<span>' . esc_html__( 'Thực:', 'allship-ups-quote' ) . ' %s</span> | ' .
			'<span>' . esc_html__( 'DIM:', 'allship-ups-quote' ) . ' %s</span><br>' .
			'<strong style="color:var(--as-brand-red, #CE2027);">' . esc_html__( 'Cước:', 'allship-ups-quote' ) . ' %s</strong>' .
			'</div>',
			esc_html( $actual ),
			esc_html( $dim ),
			esc_html( $charge )
		);
	}

	/**
	 * Render Total Price column.
	 *
	 * @param object $item Log row item.
	 * @return string
	 */
	protected function column_total_price( $item ) {
		if ( empty( $item->total_price_vnd ) ) {
			return '<span style="color:#94a3b8;">' . esc_html__( 'Chưa có giá', 'allship-ups-quote' ) . '</span>';
		}
		return sprintf(
			'<strong style="font-size:14px;color:#0f172a;">%s đ</strong>',
			esc_html( number_format( $item->total_price_vnd, 0, ',', '.' ) )
		);
	}

	/**
	 * Render Actions column.
	 *
	 * @param object $item Log row item.
	 * @return string
	 */
	protected function column_actions( $item ) {
		return sprintf(
			'<button type="button" class="as-btn as-btn--secondary as-btn--sm btn-view-log-detail" data-id="%d">' .
			'<span class="dashicons dashicons-visibility" style="margin-top:2px;"></span>' .
			'<span>%s</span>' .
			'</button>',
			absint( $item->id ),
			esc_html__( 'Chi tiết', 'allship-ups-quote' )
		);
	}

	/**
	 * Default fallback column handler.
	 *
	 * @param object $item Log row item.
	 * @param string $column_name Column slug.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '—';
	}

	/**
	 * Extra table navigation and filters (Rendered above and below the table).
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current_dir     = isset( $_REQUEST['direction'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['direction'] ) ) : '';
		$current_svc     = isset( $_REQUEST['service_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['service_code'] ) ) ) : '';
		$current_dest    = isset( $_REQUEST['destination_iata'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['destination_iata'] ) ) ) : '';
		$current_from    = isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '';
		$current_to      = isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '';

		?>
		<div class="alignleft actions as-logs-filter-bar" style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
			<!-- Direction Filter -->
			<select name="direction" id="filter_direction" class="as-rates-select" style="height:32px;">
				<option value=""><?php esc_html_e( '— Tất cả chiều —', 'allship-ups-quote' ); ?></option>
				<option value="export" <?php selected( $current_dir, 'export' ); ?>><?php esc_html_e( 'Xuất hàng', 'allship-ups-quote' ); ?></option>
				<option value="import" <?php selected( $current_dir, 'import' ); ?>><?php esc_html_e( 'Nhập hàng', 'allship-ups-quote' ); ?></option>
			</select>

			<!-- Service Code Filter -->
			<select name="service_code" id="filter_service_code" class="as-rates-select" style="height:32px;">
				<option value=""><?php esc_html_e( '— Tất cả dịch vụ —', 'allship-ups-quote' ); ?></option>
				<option value="EXW" <?php selected( $current_svc, 'EXW' ); ?>>EXW — Express Early</option>
				<option value="XPR" <?php selected( $current_svc, 'XPR' ); ?>>XPR — Express Plus</option>
				<option value="WXS" <?php selected( $current_svc, 'WXS' ); ?>>WXS — Express Saver</option>
				<option value="XPD" <?php selected( $current_svc, 'XPD' ); ?>>XPD — Expedited</option>
				<option value="WXP" <?php selected( $current_svc, 'WXP' ); ?>>WXP — Express Freight</option>
				<option value="WFM" <?php selected( $current_svc, 'WFM' ); ?>>WFM — Freight Midday</option>
			</select>

			<!-- Destination IATA Filter -->
			<input type="text" name="destination_iata" id="filter_destination_iata" value="<?php echo esc_attr( $current_dest ); ?>" placeholder="<?php esc_attr_e( 'Mã Nước (US, JP...)', 'allship-ups-quote' ); ?>" style="width:110px;height:32px;" maxlength="5">

			<!-- Date Range Filter -->
			<span style="font-size:12px;color:#64748b;font-weight:600;"><?php esc_html_e( 'Từ:', 'allship-ups-quote' ); ?></span>
			<input type="date" name="date_from" id="filter_date_from" value="<?php echo esc_attr( $current_from ); ?>" style="height:32px;">

			<span style="font-size:12px;color:#64748b;font-weight:600;"><?php esc_html_e( 'Đến:', 'allship-ups-quote' ); ?></span>
			<input type="date" name="date_to" id="filter_date_to" value="<?php echo esc_attr( $current_to ); ?>" style="height:32px;">

			<input type="submit" id="post-query-submit" class="button" value="<?php esc_attr_e( 'Lọc nhật ký', 'allship-ups-quote' ); ?>" style="height:32px;">

			<?php if ( ! empty( $current_dir ) || ! empty( $current_svc ) || ! empty( $current_dest ) || ! empty( $current_from ) || ! empty( $current_to ) || ! empty( $_REQUEST['s'] ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=allship-ups-logs' ) ); ?>" class="button" style="height:32px;display:inline-flex;align-items:center;"><?php esc_html_e( 'Xóa lọc', 'allship-ups-quote' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Prepare query arguments and fetch records from repository.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;

		// Columns headers setup
		$columns  = $this->get_columns();
		$hidden   = [];
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = [ $columns, $hidden, $sortable ];

		// Collect query filters
		$filters = [];
		if ( ! empty( $_REQUEST['direction'] ) ) {
			$filters['direction'] = sanitize_text_field( wp_unslash( $_REQUEST['direction'] ) );
		}
		if ( ! empty( $_REQUEST['service_code'] ) ) {
			$filters['service_code'] = sanitize_text_field( wp_unslash( $_REQUEST['service_code'] ) );
		}
		if ( ! empty( $_REQUEST['destination_iata'] ) ) {
			$filters['destination_iata'] = sanitize_text_field( wp_unslash( $_REQUEST['destination_iata'] ) );
		}
		if ( ! empty( $_REQUEST['date_from'] ) ) {
			$filters['date_from'] = sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) );
		}
		if ( ! empty( $_REQUEST['date_to'] ) ) {
			$filters['date_to'] = sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) );
		}
		if ( ! empty( $_REQUEST['s'] ) ) {
			$filters['search'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		}
		if ( ! empty( $_REQUEST['orderby'] ) ) {
			$filters['orderby'] = sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) );
		}
		if ( ! empty( $_REQUEST['order'] ) ) {
			$filters['order'] = sanitize_text_field( wp_unslash( $_REQUEST['order'] ) );
		}

		$current_page = $this->get_pagenum();
		$total_items  = $this->repo ? $this->repo->count_filtered( $filters ) : 0;
		$items        = $this->repo ? $this->repo->get_filtered( $filters, $current_page, $per_page ) : [];

		$this->items = $items;

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			]
		);
	}
}

/**
 * Main Controller for Admin Quote Logs & Export actions.
 */
class Allship_UPS_Admin_Quote_Logs {

	/**
	 * Nonce action for admin operations.
	 */
	const NONCE_ACTION = 'allship_ups_admin';

	/**
	 * Nonce action for CSV / Excel export.
	 */
	const EXPORT_NONCE_ACTION = 'allship_ups_export_logs';

	/**
	 * Quote log repository.
	 *
	 * @var Allship_UPS_Quote_Log_Repository|null
	 */
	private $quote_log_repo;

	/**
	 * Database instance.
	 *
	 * @var wpdb|null
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Quote_Log_Repository|null $quote_log_repo Optional.
	 * @param wpdb|null                            $wpdb           Optional.
	 */
	public function __construct( $quote_log_repo = null, $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;

		if ( null === $quote_log_repo ) {
			if ( ! class_exists( 'Allship_UPS_Quote_Log_Repository' ) && file_exists( dirname( __DIR__ ) . '/includes/class-quote-log-repository.php' ) ) {
				require_once dirname( __DIR__ ) . '/includes/class-quote-log-repository.php';
			}
			$this->quote_log_repo = class_exists( 'Allship_UPS_Quote_Log_Repository' ) ? new Allship_UPS_Quote_Log_Repository( $this->wpdb ) : null;
		} else {
			$this->quote_log_repo = $quote_log_repo;
		}
	}

	/**
	 * Register actions and AJAX hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_allship_ups_export_logs_csv', [ $this, 'handle_export_csv' ] );
		add_action( 'admin_post_allship_ups_export_logs_excel', [ $this, 'handle_export_excel' ] );
		add_action( 'wp_ajax_ups_quote_log_detail', [ $this, 'ajax_log_detail' ] );
		add_action( 'wp_ajax_ups_quote_logs_delete', [ $this, 'ajax_delete_logs' ] );
	}

	/**
	 * Collect and sanitize export filters from query parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_sanitized_filters() {
		$filters = [];

		if ( ! empty( $_REQUEST['direction'] ) ) {
			$filters['direction'] = sanitize_text_field( wp_unslash( $_REQUEST['direction'] ) );
		}
		if ( ! empty( $_REQUEST['service_code'] ) ) {
			$filters['service_code'] = sanitize_text_field( wp_unslash( $_REQUEST['service_code'] ) );
		}
		if ( ! empty( $_REQUEST['destination_iata'] ) ) {
			$filters['destination_iata'] = sanitize_text_field( wp_unslash( $_REQUEST['destination_iata'] ) );
		}
		if ( ! empty( $_REQUEST['date_from'] ) ) {
			$filters['date_from'] = sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) );
		}
		if ( ! empty( $_REQUEST['date_to'] ) ) {
			$filters['date_to'] = sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) );
		}
		if ( ! empty( $_REQUEST['s'] ) ) {
			$filters['search'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		}

		return $filters;
	}

	/**
	 * Handle admin_post CSV export request.
	 *
	 * @return void
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền thực hiện thao tác này.', 'allship-ups-quote' ), 403 );
		}

		check_admin_referer( self::EXPORT_NONCE_ACTION, 'nonce' );

		if ( ! $this->quote_log_repo ) {
			wp_die( esc_html__( 'Lỗi kết nối kho dữ liệu.', 'allship-ups-quote' ) );
		}

		$filters = $this->get_sanitized_filters();
		$this->quote_log_repo->export_csv( $filters );
		exit;
	}

	/**
	 * Handle admin_post Excel XML export request.
	 *
	 * @return void
	 */
	public function handle_export_excel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền thực hiện thao tác này.', 'allship-ups-quote' ), 403 );
		}

		check_admin_referer( self::EXPORT_NONCE_ACTION, 'nonce' );

		if ( ! $this->quote_log_repo ) {
			wp_die( esc_html__( 'Lỗi kết nối kho dữ liệu.', 'allship-ups-quote' ) );
		}

		$filters = $this->get_sanitized_filters();
		$this->quote_log_repo->export_excel( $filters );
		exit;
	}

	/**
	 * AJAX: Get detailed record data for modal view.
	 *
	 * @return void
	 */
	public function ajax_log_detail() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'allship-ups-quote' ) ], 403 );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! $id || ! $this->quote_log_repo ) {
			wp_send_json_error( [ 'message' => __( 'ID bản ghi không hợp lệ.', 'allship-ups-quote' ) ] );
		}

		$log = $this->quote_log_repo->get( $id );
		if ( ! $log ) {
			wp_send_json_error( [ 'message' => __( 'Không tìm thấy dữ liệu nhật ký báo giá.', 'allship-ups-quote' ) ] );
		}

		wp_send_json_success(
			[
				'log' => $log,
			]
		);
	}

	/**
	 * AJAX: Delete log records.
	 *
	 * @return void
	 */
	public function ajax_delete_logs() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'allship-ups-quote' ) ], 403 );
		}

		if ( ! $this->quote_log_repo ) {
			wp_send_json_error( [ 'message' => __( 'Lỗi cơ sở dữ liệu.', 'allship-ups-quote' ) ] );
		}

		if ( ! empty( $_POST['id'] ) ) {
			$id = absint( $_POST['id'] );
			$this->quote_log_repo->delete( $id );
			wp_send_json_success( [ 'message' => __( 'Đã xóa bản ghi thành công.', 'allship-ups-quote' ) ] );
		} elseif ( ! empty( $_POST['ids'] ) && is_array( $_POST['ids'] ) ) {
			$ids     = array_map( 'absint', $_POST['ids'] );
			$deleted = $this->quote_log_repo->delete_multiple( $ids );
			wp_send_json_success( [ 'message' => sprintf( __( 'Đã xóa %d bản ghi thành công.', 'allship-ups-quote' ), $deleted ) ] );
		}

		wp_send_json_error( [ 'message' => __( 'Không có bản ghi nào được chọn để xóa.', 'allship-ups-quote' ) ] );
	}
}
