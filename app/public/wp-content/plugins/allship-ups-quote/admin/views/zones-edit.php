<?php
/**
 * Admin View: Zones Edit — WP_List_Table style with AJAX inline edit.
 *
 * Implements Step 5.5 of Phase 5:
 * - Filters: rate_card_id, direction, service_code, zone, is_available, search country/IATA.
 * - Inline edit: click zone cell → input → blur/Enter → AJAX save.
 * - Add/Edit zone modal.
 * - Bulk delete.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get zone sets, rate cards & countries for dropdown.
$admin_zones    = class_exists( 'Allship_UPS_Admin_Zones' ) ? new Allship_UPS_Admin_Zones() : null;
$zone_sets      = ( $admin_zones && method_exists( $admin_zones, 'get_zone_sets_dropdown' ) ) ? $admin_zones->get_zone_sets_dropdown() : [];
$active_zs_id   = ( $admin_zones && method_exists( $admin_zones, 'get_active_zone_set_id' ) ) ? $admin_zones->get_active_zone_set_id() : 0;
$rate_cards     = $admin_zones ? $admin_zones->get_rate_cards_dropdown() : [];
$active_card_id = $admin_zones ? $admin_zones->get_active_rate_card_id() : 0;
$countries      = $admin_zones ? $admin_zones->get_countries_dropdown() : [];
?>
<div class="wrap allship-ups-admin-wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( $page_title ?? __( 'Quản lý Vùng cước (Zones)', 'allship-ups-quote' ) ); ?></h1>
	<hr class="wp-header-end">

	<!-- Global Notice -->
	<div id="allshipZonesNotice" class="notice is-dismissible" style="display:none;">
		<p></p>
		<button type="button" class="notice-dismiss" onclick="this.parentElement.style.display='none';"><span class="screen-reader-text"><?php esc_html_e( 'Đóng', 'allship-ups-quote' ); ?></span></button>
	</div>

	<!-- ====== FILTER BAR ====== -->
	<div class="allship-card-container" style="margin-top: 15px; padding: 16px 20px;">
		<div class="as-rates-filter-bar">
			<!-- Zone Set Selector (supports zone set or rate card) -->
			<div class="as-rates-filter-group">
				<label for="zonesFilterCard"><?php esc_html_e( 'Bảng Zone:', 'allship-ups-quote' ); ?></label>
				<select id="zonesFilterCard" class="as-rates-select">
					<option value=""><?php esc_html_e( '— Chọn Bảng Zone —', 'allship-ups-quote' ); ?></option>
					<?php if ( ! empty( $zone_sets ) ) : ?>
						<?php foreach ( $zone_sets as $zs ) : ?>
							<option value="<?php echo esc_attr( $zs['id'] ); ?>"
								<?php selected( $zs['id'], $active_zs_id ); ?>>
								<?php
								$rec_cnt = function_exists( 'number_format_i18n' ) ? number_format_i18n( $zs['record_count'] ) : number_format( $zs['record_count'] );
								echo esc_html(
									sprintf(
										'#%d — %s (%s dòng - %d RC)',
										$zs['id'],
										$zs['name'],
										$rec_cnt,
										$zs['used_count']
									)
								);
								?>
							</option>
						<?php endforeach; ?>
					<?php else : ?>
						<?php foreach ( $rate_cards as $card ) : ?>
							<option value="<?php echo esc_attr( $card['id'] ); ?>"
								<?php selected( $card['id'], $active_card_id ); ?>
								data-status="<?php echo esc_attr( $card['status'] ); ?>">
								<?php
								echo esc_html(
									sprintf(
										'#%d — %s [%s]',
										$card['id'],
										$card['name'],
										strtoupper( $card['status'] )
									)
								);
								?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>

			<!-- Direction -->
			<div class="as-rates-filter-group">
				<label for="zonesFilterDirection"><?php esc_html_e( 'Chiều:', 'allship-ups-quote' ); ?></label>
				<select id="zonesFilterDirection" class="as-rates-select">
					<option value=""><?php esc_html_e( 'Tất cả', 'allship-ups-quote' ); ?></option>
					<option value="export"><?php esc_html_e( 'Xuất khẩu (Export)', 'allship-ups-quote' ); ?></option>
					<option value="import"><?php esc_html_e( 'Nhập khẩu (Import)', 'allship-ups-quote' ); ?></option>
				</select>
			</div>

			<!-- Service Code -->
			<div class="as-rates-filter-group">
				<label for="zonesFilterService"><?php esc_html_e( 'Dịch vụ:', 'allship-ups-quote' ); ?></label>
				<select id="zonesFilterService" class="as-rates-select">
					<option value=""><?php esc_html_e( 'Tất cả', 'allship-ups-quote' ); ?></option>
				</select>
			</div>

			<!-- Zone -->
			<div class="as-rates-filter-group">
				<label for="zonesFilterZone"><?php esc_html_e( 'Zone:', 'allship-ups-quote' ); ?></label>
				<select id="zonesFilterZone" class="as-rates-select">
					<option value=""><?php esc_html_e( 'Tất cả', 'allship-ups-quote' ); ?></option>
				</select>
			</div>

			<!-- Status -->
			<div class="as-rates-filter-group">
				<label for="zonesFilterStatus"><?php esc_html_e( 'Trạng thái:', 'allship-ups-quote' ); ?></label>
				<select id="zonesFilterStatus" class="as-rates-select">
					<option value=""><?php esc_html_e( 'Tất cả', 'allship-ups-quote' ); ?></option>
					<option value="1"><?php esc_html_e( 'Có hiệu lực', 'allship-ups-quote' ); ?></option>
					<option value="0"><?php esc_html_e( 'Không hỗ trợ', 'allship-ups-quote' ); ?></option>
				</select>
			</div>

			<!-- Search -->
			<div class="as-rates-filter-group">
				<label for="zonesFilterSearch"><?php esc_html_e( 'Tìm kiếm:', 'allship-ups-quote' ); ?></label>
				<input type="text" id="zonesFilterSearch" class="as-rates-input" placeholder="<?php esc_attr_e( 'Quốc gia, IATA...', 'allship-ups-quote' ); ?>">
			</div>

			<!-- Actions -->
			<div class="as-rates-filter-actions">
				<button type="button" class="as-btn as-btn--primary as-btn--sm" id="btnZonesFilter" disabled>
					<span class="dashicons dashicons-search" style="margin-top:3px;"></span>
					<?php esc_html_e( 'Lọc', 'allship-ups-quote' ); ?>
				</button>
				<button type="button" class="as-btn as-btn--secondary as-btn--sm" id="btnZonesReset">
					<span class="dashicons dashicons-dismiss" style="margin-top:3px;"></span>
					<?php esc_html_e( 'Reset', 'allship-ups-quote' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- ====== TOOLBAR ====== -->
	<div class="as-rates-toolbar" id="zonesToolbar" style="display:none;">
		<div class="as-rates-toolbar-left">
			<span id="zonesCountLabel" class="as-rates-count"></span>
			<button type="button" class="as-btn as-btn--brand as-btn--sm" id="btnAddZone">
				<span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span>
				<?php esc_html_e( 'Thêm vùng cước', 'allship-ups-quote' ); ?>
			</button>
			<button type="button" class="as-btn as-btn--secondary as-btn--sm" id="btnEditSelectedZone" style="display:none;">
				<span class="dashicons dashicons-edit" style="margin-top:3px;"></span>
				<?php esc_html_e( 'Sửa dòng', 'allship-ups-quote' ); ?>
			</button>
			<button type="button" class="as-btn as-btn--danger as-btn--sm" id="btnDeleteSelectedZones" style="display:none;">
				<span class="dashicons dashicons-trash" style="margin-top:3px;"></span>
				<?php esc_html_e( 'Xóa đã chọn', 'allship-ups-quote' ); ?>
			</button>
		</div>
		<div class="as-rates-toolbar-right" id="zonesPaginationTop"></div>
	</div>

	<!-- ====== ZONES TABLE ====== -->
	<div class="allship-card-container as-rates-table-container" id="zonesTableContainer" style="display:none;">
		<table class="as-table as-rates-data-table" id="zonesTable">
			<thead>
				<tr>
					<th class="as-rates-th-check"><input type="checkbox" id="zonesCheckAll" title="<?php esc_attr_e( 'Chọn tất cả', 'allship-ups-quote' ); ?>"></th>
					<th class="as-rates-th-id"><?php esc_html_e( 'ID', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'Quốc gia / Điểm đến', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'Chiều', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'Dịch vụ', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'Loại hàng', 'allship-ups-quote' ); ?></th>
					<th style="min-width: 90px;"><?php esc_html_e( 'Zone', 'allship-ups-quote' ); ?></th>
					<th style="min-width: 120px;"><?php esc_html_e( 'Trạng thái', 'allship-ups-quote' ); ?></th>
					<th class="as-rates-th-actions"><?php esc_html_e( 'Xóa', 'allship-ups-quote' ); ?></th>
				</tr>
			</thead>
			<tbody id="zonesTableBody">
				<tr class="as-rates-empty-row">
					<td colspan="9" style="text-align:center;color:var(--as-text-muted);padding:40px 20px;">
						<?php esc_html_e( 'Chọn Rate Card và bấm "Lọc" để xem danh sách vùng cước.', 'allship-ups-quote' ); ?>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- Empty State (before filter) -->
	<div class="allship-card-container" id="zonesEmptyState" style="margin-top:15px;">
		<div class="as-empty-state" style="padding:60px 20px;text-align:center;">
			<span class="dashicons dashicons-admin-site-alt3" style="font-size:48px;color:var(--as-text-light);display:block;margin-bottom:12px;"></span>
			<h3 style="margin:0 0 8px;color:var(--as-text-dark);"><?php esc_html_e( 'Quản lý Phân vùng Quốc gia (Zones)', 'allship-ups-quote' ); ?></h3>
			<p style="margin:0;color:var(--as-text-muted);max-width:500px;margin:0 auto;">
				<?php esc_html_e( 'Chọn một Bảng Zone ở thanh bộ lọc phía trên để hiển thị danh sách ánh xạ Zone theo Quốc gia. Bạn có thể sửa trực tiếp Zone bằng cách nhấp vào ô Zone tương ứng.', 'allship-ups-quote' ); ?>
			</p>
		</div>
	</div>

	<!-- Pagination Bottom -->
	<div class="as-rates-pagination-bottom" id="zonesPaginationBottom" style="display:none;"></div>

	<!-- ====== ADD/EDIT ROW MODAL ====== -->
	<div class="allship-modal-backdrop" id="modalAddZone" style="display:none;">
		<div class="as-modal__box" style="max-width:560px;">
			<div class="as-modal__header">
				<h3 class="as-modal__title" id="modalZoneTitle"><?php esc_html_e( 'Thêm vùng cước mới', 'allship-ups-quote' ); ?></h3>
				<button type="button" class="as-modal__close" onclick="AllshipAdmin.closeModals();">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>
			<form id="formAddZone" class="as-form">
				<input type="hidden" id="editZoneId" value="">
				<div class="as-modal__body">
					<div class="as-form__row">
						<label for="addZoneCountry"><?php esc_html_e( 'Quốc gia *', 'allship-ups-quote' ); ?></label>
						<select id="addZoneCountry" name="country_id" required>
							<option value=""><?php esc_html_e( '— Chọn Quốc gia —', 'allship-ups-quote' ); ?></option>
							<?php foreach ( $countries as $c ) : ?>
								<option value="<?php echo esc_attr( $c['id'] ); ?>">
									<?php echo esc_html( sprintf( '[%s] %s', $c['iata_code'], $c['country_name'] ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="as-form__row as-form__row--half">
						<div>
							<label for="addZoneDirection"><?php esc_html_e( 'Chiều vận chuyển *', 'allship-ups-quote' ); ?></label>
							<select id="addZoneDirection" name="direction" required>
								<option value="export"><?php esc_html_e( 'Xuất khẩu (Export)', 'allship-ups-quote' ); ?></option>
								<option value="import"><?php esc_html_e( 'Nhập khẩu (Import)', 'allship-ups-quote' ); ?></option>
							</select>
						</div>
						<div>
							<label for="addZoneService"><?php esc_html_e( 'Mã dịch vụ *', 'allship-ups-quote' ); ?></label>
							<select id="addZoneService" name="service_code" required>
								<option value=""><?php esc_html_e( '— Chọn dịch vụ —', 'allship-ups-quote' ); ?></option>
							</select>
						</div>
					</div>
					<div class="as-form__row as-form__row--half">
						<div>
							<label for="addZoneType"><?php esc_html_e( 'Loại hàng', 'allship-ups-quote' ); ?></label>
							<select id="addZoneType" name="service_type">
								<option value=""><?php esc_html_e( 'Chung (Default)', 'allship-ups-quote' ); ?></option>
								<option value="document"><?php esc_html_e( 'Document (Tài liệu)', 'allship-ups-quote' ); ?></option>
								<option value="nondocument"><?php esc_html_e( 'Non-document (Hàng hóa)', 'allship-ups-quote' ); ?></option>
							</select>
						</div>
						<div>
							<label for="addZoneCode"><?php esc_html_e( 'Zone', 'allship-ups-quote' ); ?></label>
							<input type="text" id="addZoneCode" name="zone" placeholder="VD: 5">
						</div>
					</div>
					<div class="as-form__row">
						<label for="addZoneAvailable"><?php esc_html_e( 'Trạng thái', 'allship-ups-quote' ); ?></label>
						<select id="addZoneAvailable" name="is_available">
							<option value="1"><?php esc_html_e( 'Có hiệu lực (Available)', 'allship-ups-quote' ); ?></option>
							<option value="0"><?php esc_html_e( 'Không hỗ trợ (Unavailable)', 'allship-ups-quote' ); ?></option>
						</select>
					</div>
				</div>
				<div class="as-modal__footer">
					<button type="button" class="as-btn as-btn--secondary" onclick="AllshipAdmin.closeModals();">
						<?php esc_html_e( 'Hủy', 'allship-ups-quote' ); ?>
					</button>
					<button type="submit" class="as-btn as-btn--primary" id="btnSubmitAddZone">
						<span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Thêm vùng cước', 'allship-ups-quote' ); ?>
					</button>
				</div>
			</form>
		</div>
	</div>
</div>
