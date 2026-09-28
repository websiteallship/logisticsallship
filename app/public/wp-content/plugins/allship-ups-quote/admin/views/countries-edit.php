<?php
/**
 * Admin View: Countries Edit — WP_List_Table style with AJAX toggle & search.
 *
 * Step 5.6 of Phase 5.
 * Filters: search (name/IATA), status (active/inactive), is_us_override.
 * Toggle active switch via AJAX.
 * Add / Edit country modal.
 * Bulk activate / deactivate.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap allship-ups-admin-wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( $page_title ?? __( 'Danh mục Quốc gia & Điểm đến (Countries)', 'allship-ups-quote' ) ); ?></h1>
	<hr class="wp-header-end">

	<!-- Global Notice -->
	<div id="allshipCountriesNotice" class="notice is-dismissible" style="display:none;">
		<p></p>
		<button type="button" class="notice-dismiss" onclick="this.parentElement.style.display='none';"><span class="screen-reader-text"><?php esc_html_e( 'Đóng', 'allship-ups-quote' ); ?></span></button>
	</div>

	<!-- ====== FILTER BAR ====== -->
	<div class="allship-card-container" style="margin-top: 15px; padding: 16px 20px;">
		<div class="as-rates-filter-bar">
			<!-- Search input -->
			<div class="as-rates-filter-group" style="flex: 2; min-width: 240px;">
				<label for="countriesFilterSearch"><?php esc_html_e( 'Tìm kiếm:', 'allship-ups-quote' ); ?></label>
				<input type="text" id="countriesFilterSearch" class="as-rates-input" placeholder="<?php esc_attr_e( 'Tên quốc gia hoặc mã IATA (US, VN, Japan, United...)...', 'allship-ups-quote' ); ?>">
			</div>

			<!-- Status Filter -->
			<div class="as-rates-filter-group">
				<label for="countriesFilterStatus"><?php esc_html_e( 'Trạng thái:', 'allship-ups-quote' ); ?></label>
				<select id="countriesFilterStatus" class="as-rates-select">
					<option value=""><?php esc_html_e( 'Tất cả trạng thái', 'allship-ups-quote' ); ?></option>
					<option value="active"><?php esc_html_e( 'Đang hoạt động (Active)', 'allship-ups-quote' ); ?></option>
					<option value="inactive"><?php esc_html_e( 'Đã vô hiệu hóa (Inactive)', 'allship-ups-quote' ); ?></option>
				</select>
			</div>

			<!-- US Override Filter -->
			<div class="as-rates-filter-group">
				<label for="countriesFilterUS"><?php esc_html_e( 'US Override:', 'allship-ups-quote' ); ?></label>
				<select id="countriesFilterUS" class="as-rates-select">
					<option value=""><?php esc_html_e( 'Tất cả', 'allship-ups-quote' ); ?></option>
					<option value="1"><?php esc_html_e( 'Có US Override', 'allship-ups-quote' ); ?></option>
					<option value="0"><?php esc_html_e( 'Tiêu chuẩn (Không)', 'allship-ups-quote' ); ?></option>
				</select>
			</div>

			<!-- Action Buttons -->
			<div class="as-rates-filter-actions">
				<button type="button" class="as-btn as-btn--primary as-btn--sm" id="btnCountriesFilter">
					<span class="dashicons dashicons-search" style="margin-top:3px;"></span>
					<?php esc_html_e( 'Lọc', 'allship-ups-quote' ); ?>
				</button>
				<button type="button" class="as-btn as-btn--secondary as-btn--sm" id="btnCountriesReset">
					<span class="dashicons dashicons-dismiss" style="margin-top:3px;"></span>
					<?php esc_html_e( 'Reset', 'allship-ups-quote' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- ====== TOOLBAR ====== -->
	<div class="as-rates-toolbar" id="countriesToolbar">
		<div class="as-rates-toolbar-left">
			<span id="countriesCountLabel" class="as-rates-count"></span>
			<button type="button" class="as-btn as-btn--brand as-btn--sm" id="btnAddCountry">
				<span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span>
				<?php esc_html_e( 'Thêm quốc gia', 'allship-ups-quote' ); ?>
			</button>
			<button type="button" class="as-btn as-btn--primary as-btn--sm" id="btnBulkActivate" style="display:none;">
				<span class="dashicons dashicons-yes-alt" style="margin-top:3px;"></span>
				<?php esc_html_e( 'Bật đã chọn', 'allship-ups-quote' ); ?>
			</button>
			<button type="button" class="as-btn as-btn--danger as-btn--sm" id="btnBulkDeactivate" style="display:none;">
				<span class="dashicons dashicons-dismiss" style="margin-top:3px;"></span>
				<?php esc_html_e( 'Tắt đã chọn', 'allship-ups-quote' ); ?>
			</button>
		</div>
		<div class="as-rates-toolbar-right" id="countriesPaginationTop"></div>
	</div>

	<!-- ====== COUNTRIES TABLE ====== -->
	<div class="allship-card-container as-rates-table-container" id="countriesTableContainer">
		<table class="as-table as-rates-data-table" id="countriesTable">
			<thead>
				<tr>
					<th class="as-rates-th-check"><input type="checkbox" id="countriesCheckAll" title="<?php esc_attr_e( 'Chọn tất cả', 'allship-ups-quote' ); ?>"></th>
					<th class="as-rates-th-id"><?php esc_html_e( 'ID', 'allship-ups-quote' ); ?></th>
					<th style="width: 100px;"><?php esc_html_e( 'IATA Code', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'Tên quốc gia', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'Tên chuẩn hóa (Normalized)', 'allship-ups-quote' ); ?></th>
					<th style="width: 120px; text-align: center;"><?php esc_html_e( 'US Override', 'allship-ups-quote' ); ?></th>
					<th style="width: 120px; text-align: center;"><?php esc_html_e( 'Vùng xa (*)', 'allship-ups-quote' ); ?></th>
					<th style="width: 150px; text-align: center;"><?php esc_html_e( 'Trạng thái', 'allship-ups-quote' ); ?></th>
					<th class="as-rates-th-actions"><?php esc_html_e( 'Sửa', 'allship-ups-quote' ); ?></th>
				</tr>
			</thead>
			<tbody id="countriesTableBody">
				<tr class="as-rates-loading">
					<td colspan="9" style="text-align:center;padding:40px 20px;">
						<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Đang tải danh sách quốc gia...', 'allship-ups-quote' ); ?>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- Pagination Bottom -->
	<div class="as-rates-pagination-bottom" id="countriesPaginationBottom" style="display:none;"></div>

	<!-- ====== ADD / EDIT COUNTRY MODAL ====== -->
	<div class="allship-modal-backdrop" id="modalCountry" style="display:none;">
		<div class="as-modal__box" style="max-width:540px;">
			<div class="as-modal__header">
				<h3 class="as-modal__title" id="modalCountryTitle"><?php esc_html_e( 'Thêm quốc gia mới', 'allship-ups-quote' ); ?></h3>
				<button type="button" class="as-modal__close" onclick="AllshipAdmin.closeModals();">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>
			<form id="formCountry" class="as-form">
				<input type="hidden" id="editCountryId" value="">
				<div class="as-modal__body">
					<div class="as-form__row as-form__row--half">
						<div>
							<label for="countryIata"><?php esc_html_e( 'Mã IATA * (2 ký tự)', 'allship-ups-quote' ); ?></label>
							<input type="text" id="countryIata" name="iata_code" required maxlength="10" placeholder="US, VN, JP..." style="text-transform:uppercase;">
						</div>
						<div>
							<label for="countryActive"><?php esc_html_e( 'Trạng thái hoạt động', 'allship-ups-quote' ); ?></label>
							<select id="countryActive" name="is_active">
								<option value="1"><?php esc_html_e( 'Bật (Active)', 'allship-ups-quote' ); ?></option>
								<option value="0"><?php esc_html_e( 'Tắt (Inactive)', 'allship-ups-quote' ); ?></option>
							</select>
						</div>
					</div>

					<div class="as-form__row">
						<label for="countryName"><?php esc_html_e( 'Tên quốc gia *', 'allship-ups-quote' ); ?></label>
						<input type="text" id="countryName" name="country_name" required placeholder="<?php esc_attr_e( 'United States, Vietnam...', 'allship-ups-quote' ); ?>">
					</div>

					<div class="as-form__row">
						<label for="countryNormName"><?php esc_html_e( 'Tên chuẩn hóa (Tùy chọn)', 'allship-ups-quote' ); ?></label>
						<input type="text" id="countryNormName" name="normalized_name" placeholder="<?php esc_attr_e( 'Tự động lấy tên bỏ ký tự * nếu để trống', 'allship-ups-quote' ); ?>">
					</div>

					<div class="as-form__row as-form__row--half">
						<div>
							<label for="countryUsOverride"><?php esc_html_e( 'Quy tắc US Override', 'allship-ups-quote' ); ?></label>
							<select id="countryUsOverride" name="is_us_override">
								<option value="0"><?php esc_html_e( 'Không áp dụng (0)', 'allship-ups-quote' ); ?></option>
								<option value="1"><?php esc_html_e( 'Áp dụng cước US5 (1)', 'allship-ups-quote' ); ?></option>
							</select>
						</div>
						<div>
							<label for="countryExtended"><?php esc_html_e( 'Ghi chú vùng sâu / xa (*)', 'allship-ups-quote' ); ?></label>
							<select id="countryExtended" name="has_extended_area_note">
								<option value="0"><?php esc_html_e( 'Bình thường (0)', 'allship-ups-quote' ); ?></option>
								<option value="1"><?php esc_html_e( 'Vùng sâu xa có dấu * (1)', 'allship-ups-quote' ); ?></option>
							</select>
						</div>
					</div>
				</div>
				<div class="as-modal__footer">
					<button type="button" class="as-btn as-btn--secondary" onclick="AllshipAdmin.closeModals();">
						<?php esc_html_e( 'Hủy', 'allship-ups-quote' ); ?>
					</button>
					<button type="submit" class="as-btn as-btn--primary" id="btnSubmitCountry">
						<span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span>
						<span id="btnSubmitCountryLabel"><?php esc_html_e( 'Thêm quốc gia', 'allship-ups-quote' ); ?></span>
					</button>
				</div>
			</form>
		</div>
	</div>
</div>
