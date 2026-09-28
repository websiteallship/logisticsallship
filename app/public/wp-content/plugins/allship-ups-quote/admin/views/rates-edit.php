<?php
/**
 * Admin View: Rates Edit — WP_List_Table style with AJAX inline edit.
 *
 * Step 5.4 of Phase 5.
 * Filters: rate_card_id, rate_group, zone, billing_unit, search.
 * Inline edit: click cell → input → blur/Enter → AJAX save.
 * Add row modal. Bulk delete.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get rate cards for dropdown.
$admin_rates    = class_exists( 'Allship_UPS_Admin_Rates' ) ? new Allship_UPS_Admin_Rates() : null;
$rate_cards     = $admin_rates ? $admin_rates->get_rate_cards_dropdown() : [];
$active_card_id = $admin_rates ? $admin_rates->get_active_rate_card_id() : 0;
?>
<div class="wrap allship-ups-admin-wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( $page_title ?? __( 'Tra cứu & Chỉnh sửa Mức cước (Rates)', 'allship-ups-quote' ) ); ?></h1>
	<hr class="wp-header-end">

	<!-- Global Notice -->
	<div id="allshipRatesNotice" class="notice is-dismissible" style="display:none;">
		<p></p>
		<button type="button" class="notice-dismiss" onclick="this.parentElement.style.display='none';"><span class="screen-reader-text"><?php esc_html_e( 'Đóng', 'allship-ups-quote' ); ?></span></button>
	</div>

	<!-- ====== FILTER BAR ====== -->
	<div class="allship-card-container" style="margin-top: 15px; padding: 16px 20px;">
		<div class="as-rates-filter-bar">
			<!-- Rate Card Selector -->
			<div class="as-rates-filter-group">
				<label for="ratesFilterCard"><?php esc_html_e( 'Bảng giá:', 'allship-ups-quote' ); ?></label>
				<select id="ratesFilterCard" class="as-rates-select">
					<option value=""><?php esc_html_e( '— Chọn Rate Card —', 'allship-ups-quote' ); ?></option>
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
				</select>
			</div>

			<!-- Rate Group -->
			<div class="as-rates-filter-group">
				<label for="ratesFilterGroup"><?php esc_html_e( 'Rate Group:', 'allship-ups-quote' ); ?></label>
				<select id="ratesFilterGroup" class="as-rates-select">
					<option value=""><?php esc_html_e( 'Tất cả', 'allship-ups-quote' ); ?></option>
				</select>
			</div>

			<!-- Zone -->
			<div class="as-rates-filter-group">
				<label for="ratesFilterZone"><?php esc_html_e( 'Zone:', 'allship-ups-quote' ); ?></label>
				<select id="ratesFilterZone" class="as-rates-select">
					<option value=""><?php esc_html_e( 'Tất cả', 'allship-ups-quote' ); ?></option>
				</select>
			</div>

			<!-- Billing Unit -->
			<div class="as-rates-filter-group">
				<label for="ratesFilterBilling"><?php esc_html_e( 'Billing:', 'allship-ups-quote' ); ?></label>
				<select id="ratesFilterBilling" class="as-rates-select">
					<option value=""><?php esc_html_e( 'Tất cả', 'allship-ups-quote' ); ?></option>
				</select>
			</div>

			<!-- Search -->
			<div class="as-rates-filter-group">
				<label for="ratesFilterSearch"><?php esc_html_e( 'Tìm:', 'allship-ups-quote' ); ?></label>
				<input type="text" id="ratesFilterSearch" class="as-rates-input" placeholder="<?php esc_attr_e( 'weight_label...', 'allship-ups-quote' ); ?>">
			</div>

			<!-- Actions -->
			<div class="as-rates-filter-actions">
				<button type="button" class="as-btn as-btn--primary as-btn--sm" id="btnRatesFilter" disabled>
					<span class="dashicons dashicons-search" style="margin-top:3px;"></span>
					<?php esc_html_e( 'Lọc', 'allship-ups-quote' ); ?>
				</button>
				<button type="button" class="as-btn as-btn--secondary as-btn--sm" id="btnRatesReset">
					<span class="dashicons dashicons-dismiss" style="margin-top:3px;"></span>
					<?php esc_html_e( 'Reset', 'allship-ups-quote' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- ====== TOOLBAR ====== -->
	<div class="as-rates-toolbar" id="ratesToolbar" style="display:none;">
		<div class="as-rates-toolbar-left">
			<span id="ratesCountLabel" class="as-rates-count"></span>
			<button type="button" class="as-btn as-btn--brand as-btn--sm" id="btnAddRate">
				<span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span>
				<?php esc_html_e( 'Thêm dòng', 'allship-ups-quote' ); ?>
			</button>
			<button type="button" class="as-btn as-btn--secondary as-btn--sm" id="btnEditSelected" style="display:none;">
				<span class="dashicons dashicons-edit" style="margin-top:3px;"></span>
				<?php esc_html_e( 'Sửa dòng', 'allship-ups-quote' ); ?>
			</button>
			<button type="button" class="as-btn as-btn--danger as-btn--sm" id="btnDeleteSelected" style="display:none;">
				<span class="dashicons dashicons-trash" style="margin-top:3px;"></span>
				<?php esc_html_e( 'Xóa đã chọn', 'allship-ups-quote' ); ?>
			</button>
		</div>
		<div class="as-rates-toolbar-right" id="ratesPaginationTop"></div>
	</div>

	<!-- ====== RATES TABLE ====== -->
	<div class="allship-card-container as-rates-table-container" id="ratesTableContainer" style="display:none;">
		<table class="as-table as-rates-data-table" id="ratesTable">
			<thead>
				<tr>
					<th class="as-rates-th-check"><input type="checkbox" id="ratesCheckAll" title="<?php esc_attr_e( 'Chọn tất cả', 'allship-ups-quote' ); ?>"></th>
					<th class="as-rates-th-id"><?php esc_html_e( 'ID', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'Rate Group', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'Zone', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'Weight Label', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'From (kg)', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'To (kg)', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'Billing', 'allship-ups-quote' ); ?></th>
					<th class="as-rates-th-price"><?php esc_html_e( 'Giá (VND)', 'allship-ups-quote' ); ?></th>
					<th><?php esc_html_e( 'Sort', 'allship-ups-quote' ); ?></th>
					<th class="as-rates-th-actions"><?php esc_html_e( 'Xóa', 'allship-ups-quote' ); ?></th>
				</tr>
			</thead>
			<tbody id="ratesTableBody">
				<tr class="as-rates-empty-row">
					<td colspan="11" style="text-align:center;color:var(--as-text-muted);padding:40px 20px;">
						<?php esc_html_e( 'Chọn Rate Card và bấm "Lọc" để xem danh sách mức cước.', 'allship-ups-quote' ); ?>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- Empty State (before filter) -->
	<div class="allship-card-container" id="ratesEmptyState" style="margin-top:15px;">
		<div class="as-empty-state" style="padding:60px 20px;text-align:center;">
			<span class="dashicons dashicons-list-view" style="font-size:48px;color:var(--as-text-light);display:block;margin-bottom:12px;"></span>
			<h3 style="margin:0 0 8px;color:var(--as-text-dark);"><?php esc_html_e( 'Quản lý Mức cước theo Rate Card', 'allship-ups-quote' ); ?></h3>
			<p style="margin:0;color:var(--as-text-muted);max-width:480px;margin:0 auto;">
				<?php esc_html_e( 'Chọn một Rate Card ở thanh bộ lọc phía trên, sau đó bấm "Lọc" để hiển thị danh sách các dòng cước. Bạn có thể sửa trực tiếp bằng cách nhấp vào ô cần sửa.', 'allship-ups-quote' ); ?>
			</p>
		</div>
	</div>

	<!-- Pagination Bottom -->
	<div class="as-rates-pagination-bottom" id="ratesPaginationBottom" style="display:none;"></div>

	<!-- ====== ADD/EDIT ROW MODAL ====== -->
	<div class="allship-modal-backdrop" id="modalAddRate" style="display:none;">
		<div class="as-modal__box" style="max-width:560px;">
			<div class="as-modal__header">
				<h3 class="as-modal__title" id="modalRateTitle"><?php esc_html_e( 'Thêm dòng cước mới', 'allship-ups-quote' ); ?></h3>
				<button type="button" class="as-modal__close" onclick="AllshipAdmin.closeModals();">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>
			<form id="formAddRate" class="as-form">
				<input type="hidden" id="editRateId" value="">
				<div class="as-modal__body">
					<div class="as-form__row">
						<label for="addRateGroup"><?php esc_html_e( 'Rate Group *', 'allship-ups-quote' ); ?></label>
						<select id="addRateGroup" name="rate_group" required>
							<option value=""><?php esc_html_e( '— Chọn Rate Group —', 'allship-ups-quote' ); ?></option>
						</select>
					</div>
					<div class="as-form__row as-form__row--half">
						<div>
							<label for="addRateZone"><?php esc_html_e( 'Zone *', 'allship-ups-quote' ); ?></label>
							<select id="addRateZone" name="zone" required>
								<option value=""><?php esc_html_e( '— Chọn Zone —', 'allship-ups-quote' ); ?></option>
							</select>
						</div>
						<div>
							<label for="addRateBilling"><?php esc_html_e( 'Billing Unit', 'allship-ups-quote' ); ?></label>
							<select id="addRateBilling" name="billing_unit">
								<option value="flat">flat</option>
								<option value="per_kg">per_kg</option>
								<option value="minimum">minimum</option>
								<option value="envelope">envelope</option>
							</select>
						</div>
					</div>
					<div class="as-form__row">
						<label for="addRateLabel"><?php esc_html_e( 'Weight Label *', 'allship-ups-quote' ); ?></label>
						<input type="text" id="addRateLabel" name="weight_label" required placeholder="0.5">
					</div>
					<div class="as-form__row as-form__row--half">
						<div>
							<label for="addRateFrom"><?php esc_html_e( 'Weight From (kg)', 'allship-ups-quote' ); ?></label>
							<input type="number" step="0.001" id="addRateFrom" name="weight_from" placeholder="0">
						</div>
						<div>
							<label for="addRateTo"><?php esc_html_e( 'Weight To (kg)', 'allship-ups-quote' ); ?></label>
							<input type="number" step="0.001" id="addRateTo" name="weight_to" placeholder="0.5">
						</div>
					</div>
					<div class="as-form__row as-form__row--half">
						<div>
							<label for="addRatePrice"><?php esc_html_e( 'Giá (VND) *', 'allship-ups-quote' ); ?></label>
							<input type="number" id="addRatePrice" name="price_vnd" required placeholder="850000" min="0">
						</div>
						<div>
							<label for="addRateSort"><?php esc_html_e( 'Sort Order', 'allship-ups-quote' ); ?></label>
							<input type="number" id="addRateSort" name="sort_order" placeholder="0" min="0" value="0">
						</div>
					</div>
				</div>
				<div class="as-modal__footer">
					<button type="button" class="as-btn as-btn--secondary" onclick="AllshipAdmin.closeModals();">
						<?php esc_html_e( 'Hủy', 'allship-ups-quote' ); ?>
					</button>
					<button type="submit" class="as-btn as-btn--primary" id="btnSubmitAddRate">
						<span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Thêm dòng cước', 'allship-ups-quote' ); ?>
					</button>
				</div>
			</form>
		</div>
	</div>
</div>
