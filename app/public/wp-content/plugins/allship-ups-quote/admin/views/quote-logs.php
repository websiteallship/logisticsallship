<?php
/**
 * Admin View: Quote Logs & Leads
 *
 * Implements Step 5.8 of Phase 5:
 * - WP_List_Table presentation of quote calculation logs.
 * - Comprehensive filtering by date range, service, destination, direction.
 * - Export buttons for CSV (with UTF-8 BOM) and Excel XML.
 * - Interactive detail modal viewer for each quote log entry.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'allship-ups-quote' ), 403 );
}

// Ensure required classes are loaded
if ( ! class_exists( 'Allship_UPS_Quote_Log_Repository' ) && file_exists( dirname( dirname( __FILE__ ) ) . '/includes/class-quote-log-repository.php' ) ) {
	require_once dirname( dirname( __FILE__ ) ) . '/includes/class-quote-log-repository.php';
}
if ( ! class_exists( 'Allship_UPS_Admin_Quote_Logs' ) && file_exists( dirname( __FILE__ ) . '/class-admin-quote-logs.php' ) ) {
	require_once dirname( __FILE__ ) . '/class-admin-quote-logs.php';
}

$repo       = class_exists( 'Allship_UPS_Quote_Log_Repository' ) ? new Allship_UPS_Quote_Log_Repository() : null;
$list_table = class_exists( 'Allship_UPS_Quote_Logs_List_Table' ) ? new Allship_UPS_Quote_Logs_List_Table( $repo ) : null;

if ( $list_table ) {
	$list_table->prepare_items();
}

// Build Export URLs with active query filters
$export_nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( Allship_UPS_Admin_Quote_Logs::EXPORT_NONCE_ACTION ) : '';
$current_filters = [
	'direction'        => ! empty( $_REQUEST['direction'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['direction'] ) ) : '',
	'service_code'     => ! empty( $_REQUEST['service_code'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['service_code'] ) ) : '',
	'destination_iata' => ! empty( $_REQUEST['destination_iata'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['destination_iata'] ) ) : '',
	'date_from'        => ! empty( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '',
	'date_to'          => ! empty( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '',
	's'                => ! empty( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
	'nonce'            => $export_nonce,
];

$export_csv_url   = add_query_arg( array_merge( [ 'action' => 'allship_ups_export_logs_csv' ], array_filter( $current_filters ) ), admin_url( 'admin-post.php' ) );
$export_excel_url = add_query_arg( array_merge( [ 'action' => 'allship_ups_export_logs_excel' ], array_filter( $current_filters ) ), admin_url( 'admin-post.php' ) );
?>

<div class="wrap allship-ups-admin-wrap">
	<div class="as-page-header">
		<h1 class="wp-heading-inline as-page-header__title"><?php echo esc_html( $page_title ?? __( 'Nhật ký Báo giá & Khách hàng tiềm năng (Leads)', 'allship-ups-quote' ) ); ?></h1>
		<div class="as-page-header__actions">
			<a href="<?php echo esc_url( $export_csv_url ); ?>" class="as-btn as-btn--primary">
				<span class="dashicons dashicons-media-spreadsheet" style="margin-top:2px;"></span>
				<span><?php esc_html_e( 'Xuất CSV (UTF-8 BOM)', 'allship-ups-quote' ); ?></span>
			</a>
			<a href="<?php echo esc_url( $export_excel_url ); ?>" class="as-btn as-btn--secondary">
				<span class="dashicons dashicons-download" style="margin-top:2px;"></span>
				<span><?php esc_html_e( 'Xuất Excel (.xls)', 'allship-ups-quote' ); ?></span>
			</a>
		</div>
	</div>
	<hr class="wp-header-end">

	<div class="allship-card-container" style="margin-top: 15px; padding: 20px;">
		<form id="quote-logs-filter-form" method="get">
			<input type="hidden" name="page" value="allship-ups-logs" />
			<?php
			if ( $list_table ) {
				$list_table->search_box( __( 'Tìm kiếm nhật ký', 'allship-ups-quote' ), 'quote_log_search' );
				$list_table->display();
			} else {
				echo '<p>' . esc_html__( 'Không thể tải bảng dữ liệu nhật ký.', 'allship-ups-quote' ) . '</p>';
			}
			?>
		</form>
	</div>
</div>

<!-- ====== DETAIL MODAL ====== -->
<div id="modalLogDetail" class="allship-modal-backdrop" style="display:none;position:fixed;inset:0;background:rgba(10,22,40,0.6);backdrop-filter:blur(3px);z-index:99999;align-items:center;justify-content:center;padding:20px;">
	<div class="allship-modal-box" style="background:#fff;width:100%;max-width:760px;max-height:90vh;overflow-y:auto;padding:24px;border:1px solid #cbd5e1;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);position:relative;">
		<button type="button" class="as-btn-close-modal" onclick="document.getElementById('modalLogDetail').style.display='none';" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
		
		<div style="border-bottom:1px solid #e2e8f0;padding-bottom:12px;margin-bottom:16px;">
			<h2 style="margin:0 0 4px 0;font-size:18px;color:#0f172a;" id="logDetailTitle"><?php esc_html_e( 'Chi Tiết Nhật Ký Báo Giá', 'allship-ups-quote' ); ?></h2>
			<span style="font-size:12px;color:#64748b;" id="logDetailSub"><?php esc_html_e( 'Thông tin toàn diện phiên tính cước và thông tin kiện hàng.', 'allship-ups-quote' ); ?></span>
		</div>

		<div id="logDetailContent" style="font-size:13px;color:#1e293b;line-height:1.6;">
			<!-- Injected dynamically via JavaScript -->
			<div style="text-align:center;padding:30px;color:#64748b;">
				<span class="spinner is-active" style="float:none;margin:0 auto 10px;"></span>
				<p><?php esc_html_e( 'Đang tải dữ liệu...', 'allship-ups-quote' ); ?></p>
			</div>
		</div>

		<div style="margin-top:20px;padding-top:14px;border-top:1px solid #e2e8f0;text-align:right;">
			<button type="button" class="as-btn as-btn--secondary" onclick="document.getElementById('modalLogDetail').style.display='none';">
				<?php esc_html_e( 'Đóng', 'allship-ups-quote' ); ?>
			</button>
		</div>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
	const modal = document.getElementById('modalLogDetail');
	const content = document.getElementById('logDetailContent');
	const title = document.getElementById('logDetailTitle');

	function parseBrowser(ua) {
		if (!ua || typeof ua !== 'string' || ua === '—') return '—';

		let browser = 'Web';
		let os = '';

		if (/Windows NT 10\.0/i.test(ua)) os = 'Windows 10/11';
		else if (/Windows NT 6\.3/i.test(ua)) os = 'Windows 8.1';
		else if (/Windows NT 6\.1/i.test(ua)) os = 'Windows 7';
		else if (/Windows/i.test(ua)) os = 'Windows';
		else if (/iPhone|iPad|iPod/i.test(ua)) os = 'iOS';
		else if (/Android/i.test(ua)) os = 'Android';
		else if (/Mac OS X/i.test(ua)) os = 'macOS';
		else if (/Linux/i.test(ua)) os = 'Linux';

		let m;
		if ((m = ua.match(/CocCoc\/([0-9.]+)/i))) browser = 'Cốc Cốc ' + m[1].split('.')[0];
		else if ((m = ua.match(/Edg\/([0-9.]+)/i))) browser = 'Edge ' + m[1].split('.')[0];
		else if ((m = ua.match(/(?:OPR|Opera)\/([0-9.]+)/i))) browser = 'Opera ' + m[1].split('.')[0];
		else if ((m = ua.match(/Chrome\/([0-9.]+)/i))) browser = 'Chrome ' + m[1].split('.')[0];
		else if ((m = ua.match(/Firefox\/([0-9.]+)/i))) browser = 'Firefox ' + m[1].split('.')[0];
		else if ((m = ua.match(/Version\/([0-9.]+).*Safari/i))) browser = 'Safari ' + m[1].split('.')[0];
		else if (/Safari/i.test(ua)) browser = 'Safari';

		return os ? browser + ' (' + os + ')' : browser;
	}

	document.querySelectorAll('.btn-view-log-detail').forEach(function(btn) {
		btn.addEventListener('click', function(e) {
			e.preventDefault();
			const id = this.getAttribute('data-id');
			if (!id) return;

			modal.style.display = 'flex';
			title.textContent = 'Chi Tiết Nhật Ký Báo Giá #' + id;
			content.innerHTML = '<div style="text-align:center;padding:30px;color:#64748b;"><span class="spinner is-active" style="float:none;margin:0 auto 10px;"></span><p>Đang tải dữ liệu...</p></div>';

			const ajaxUrl = (typeof window.allshipUpsAdminConfig !== 'undefined' && window.allshipUpsAdminConfig.ajaxUrl) 
				? window.allshipUpsAdminConfig.ajaxUrl 
				: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

			const nonce = (typeof window.allshipUpsAdminConfig !== 'undefined' && window.allshipUpsAdminConfig.nonce)
				? window.allshipUpsAdminConfig.nonce
				: '<?php echo esc_attr( wp_create_nonce( 'allship_ups_admin' ) ); ?>';

			jQuery.getJSON(ajaxUrl, {
				action: 'ups_quote_log_detail',
				nonce: nonce,
				id: id
			}, function(res) {
				if (!res.success || !res.data || !res.data.log) {
					content.innerHTML = '<div class="notice notice-error"><p>' + ((res.data && res.data.message) || 'Không thể tải chi tiết.') + '</p></div>';
					return;
				}

				const log = res.data.log;
				let piecesHtml = '';
				if (Array.isArray(log.pieces) && log.pieces.length > 0) {
					piecesHtml = '<table class="widefat striped" style="margin-top:8px;font-size:12px;">' +
						'<thead><tr><th>#</th><th>Số lượng</th><th>Cân thực (kg)</th><th>Kích thước (DxRxC cm)</th><th>Cân DIM (kg)</th></tr></thead><tbody>';
					log.pieces.forEach(function(p, idx) {
						var qty = (typeof p.quantity !== 'undefined') ? p.quantity : (p.qty || 1);
						var actualWeight = (typeof p.actual_weight_kg !== 'undefined') ? p.actual_weight_kg : (p.weight || 0);
						var len = (typeof p.length_cm !== 'undefined') ? p.length_cm : (p.len || 0);
						var wid = (typeof p.width_cm !== 'undefined') ? p.width_cm : (p.wid || 0);
						var hei = (typeof p.height_cm !== 'undefined') ? p.height_cm : (p.hei || 0);
						var dim = (typeof p.dim_weight_kg !== 'undefined')
							? p.dim_weight_kg
							: (typeof p.dim_weight !== 'undefined' ? p.dim_weight : ((len * wid * hei) / 5500));
						var dimFormatted = (!isNaN(dim) && dim !== null) ? Number(dim).toFixed(2) : '0.00';

						piecesHtml += '<tr>' +
							'<td>' + (idx + 1) + '</td>' +
							'<td>' + qty + '</td>' +
							'<td>' + actualWeight + '</td>' +
							'<td>' + len + ' &times; ' + wid + ' &times; ' + hei + '</td>' +
							'<td>' + dimFormatted + '</td>' +
							'</tr>';
					});
					piecesHtml += '</tbody></table>';
				} else {
					piecesHtml = '<p style="color:#64748b;font-style:italic;">Không có chi tiết kiện hàng.</p>';
				}

				let contactDetail = '';
				const contact = (log.breakdown && (log.breakdown.contact || log.breakdown.lead)) || null;
				const leadSource = (log.breakdown && log.breakdown.lead_source) || '';
				if (contact && (contact.name || contact.phone || contact.notes)) {
					const cleanPhone = (contact.phone || '').replace(/[^0-9+]/g, '');
					let sourceLabel = 'Booking Modal';
					if (leadSource === 'fluentform' && contact.ff_entry_id) {
						sourceLabel = 'FluentForm #' + contact.ff_entry_id;
					} else if (contact.ff_entry_id) {
						sourceLabel = 'Booking Modal (Entry #' + contact.ff_entry_id + ')';
					} else if (leadSource === 'fluentform') {
						sourceLabel = 'FluentForm';
					}
					const sourceBadge = ' <span style="background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;">' + sourceLabel + '</span>';
					contactDetail = '<div style="background:#f0f9ff;padding:12px 14px;border:1px solid #bae6fd;border-radius:6px;margin-bottom:16px;">' +
						'<strong style="display:block;margin-bottom:8px;color:#0369a1;font-size:13px;">Thông Tin Khách Hàng Đặt Dịch Vụ: ' + sourceBadge + '</strong>' +
						'<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px;">' +
							'<div><strong>Họ và tên:</strong> <span style="color:#0f172a;font-weight:700;">' + (contact.name || '—') + '</span></div>' +
							'<div><strong>Số điện thoại / Zalo:</strong> <a href="tel:' + cleanPhone + '" style="color:#2563eb;font-weight:700;text-decoration:none;">' + (contact.phone || '—') + '</a></div>' +
							(contact.email ? '<div><strong>Email:</strong> ' + contact.email + '</div>' : '') +
						'</div>' +
						(contact.notes ? '<div style="margin-top:8px;font-size:12px;color:#334155;background:#ffffff;padding:8px 10px;border:1px dashed #cbd5e1;border-radius:4px;"><strong>Ghi chú từ khách:</strong> ' + contact.notes + '</div>' : '') +
						'</div>';
				}

				let addressDetail = '';
				if (log.destination_address || log.destination_city || log.destination_state || log.destination_postal_code) {
					addressDetail = '<div style="background:#f8fafc;padding:10px 14px;border:1px solid #e2e8f0;margin-top:8px;">' +
						'<strong>Địa chỉ chi tiết:</strong> ' + [log.destination_address, log.destination_city, log.destination_state, log.destination_postal_code].filter(Boolean).join(', ') +
						'</div>';
				}

				const cleanUA = parseBrowser(log.user_agent);
				const rawUA = String(log.user_agent || '').replace(/"/g, '&quot;');
				let clientDetail = '<div style="background:#f8fafc;padding:10px 14px;border:1px solid #e2e8f0;margin-top:8px;font-size:12px;">' +
					'<strong>Thiết bị & IP:</strong> ' +
					'<span style="display:inline-block;margin-right:16px;">IP: <strong style="font-family:monospace;color:#0f172a;">' + (log.ip_address || '—') + '</strong></span>' +
					'<span style="display:inline-block;margin-right:16px;">Thiết bị: <strong>' + (log.device_type ? log.device_type.toUpperCase() : 'DESKTOP') + '</strong></span>' +
					'<span style="display:inline-block;color:#0f172a;" title="' + rawUA + '">Trình duyệt: <strong>' + cleanUA + '</strong></span>' +
					'</div>';

				content.innerHTML = '' +
					contactDetail +
					'<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">' +
						'<div style="background:#f8fafc;padding:12px 14px;border:1px solid #e2e8f0;">' +
							'<strong style="display:block;margin-bottom:6px;color:#0f172a;">Thông tin tuyến đường:</strong>' +
							'<div><strong>Chiều:</strong> ' + (log.direction === 'import' ? 'Nhập hàng' : 'Xuất hàng') + '</div>' +
							'<div><strong>Nơi gửi:</strong> ' + (log.origin_province ? log.origin_province + ' (' + log.origin_iata + ')' : log.origin_iata) + '</div>' +
							'<div><strong>Điểm đến:</strong> ' + log.destination_iata + (log.destination_city ? ' - ' + log.destination_city : '') + '</div>' +
							'<div><strong>Vùng Zone:</strong> ' + (log.rate_zone ? log.rate_zone + ' (Zone ' + log.zone + ')' : (log.zone || '—')) + '</div>' +
						'</div>' +
						'<div style="background:#f8fafc;padding:12px 14px;border:1px solid #e2e8f0;">' +
							'<strong style="display:block;margin-bottom:6px;color:#0f172a;">Thông tin cước & dịch vụ:</strong>' +
							'<div><strong>Dịch vụ:</strong> ' + log.service_code + ' (' + (log.shipment_type === 'document' ? 'Chứng từ' : 'Hàng hóa') + ')</div>' +
							'<div><strong>Cân tính cước:</strong> <strong style="color:#CE2027;">' + (log.chargeable_weight_kg || 0) + ' kg</strong></div>' +
							'<div><strong>Cước cơ bản:</strong> ' + Number(log.base_price_vnd || 0).toLocaleString('vi-VN') + ' đ</div>' +
							'<div><strong>Tổng cước:</strong> <strong style="color:#059669;font-size:14px;">' + Number(log.total_price_vnd || 0).toLocaleString('vi-VN') + ' đ</strong></div>' +
						'</div>' +
					'</div>' +
					addressDetail +
					clientDetail +
					'<div style="margin-top:16px;">' +
						'<strong style="display:block;margin-bottom:6px;color:#0f172a;">Danh sách kiện hàng:</strong>' +
						piecesHtml +
					'</div>' +
					'<div style="margin-top:14px;font-size:11px;color:#94a3b8;">' +
						'Thời gian tạo: ' + log.created_at + ' | Session ID: ' + (log.session_id || '—') + ' | Rate Card: ' + (log.rate_card_name ? log.rate_card_name + ' (ID ' + log.rate_card_id + ')' : (log.rate_card_id || '—')) +
					'</div>';
			}).fail(function() {
				content.innerHTML = '<div class="notice notice-error"><p>Lỗi kết nối máy chủ khi tải chi tiết bản ghi.</p></div>';
			});
		});
	});
});
</script>
