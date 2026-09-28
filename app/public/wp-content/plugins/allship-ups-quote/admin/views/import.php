<?php
/**
 * Admin View: Import Excel & Zone Wizard
 *
 * Implements Step 5.3 of Phase 5:
 * - 3-step wizard: Upload -> Validate & Preview -> Complete.
 * - Multi-file upload (Rate file + optional Zone file).
 * - Preview summary (Rate groups, US5 detection, Countries, Zones, Warnings).
 * - AJAX execution (ups_import_preview, ups_import_execute, ups_import_cancel).
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$admin_import = class_exists( 'Allship_UPS_Admin_Import' ) ? new Allship_UPS_Admin_Import() : null;
$zone_sets    = $admin_import ? $admin_import->get_zone_sets_dropdown() : [];
?>

<div class="wrap allship-ups-admin-wrap">
	<div class="as-page-header">
		<h1 class="wp-heading-inline as-page-header__title"><?php echo esc_html( $page_title ?? __( 'Import Bảng giá & Vùng cước UPS', 'allship-ups-quote' ) ); ?></h1>
		<div class="as-page-header__actions">
			<a href="<?php echo esc_url( defined( 'ALLSHIP_UPS_QUOTE_URL' ) ? ALLSHIP_UPS_QUOTE_URL . 'assets/samples/mau-bang-gia-ups-sample.xlsx' : '#' ); ?>" class="as-btn as-btn--secondary page-title-action" download>
				<span class="dashicons dashicons-download"></span>
				<span><?php echo esc_html__( 'Tải file Bảng giá mẫu (.xlsx)', 'allship-ups-quote' ); ?></span>
			</a>
			<a href="<?php echo esc_url( defined( 'ALLSHIP_UPS_QUOTE_URL' ) ? ALLSHIP_UPS_QUOTE_URL . 'assets/samples/mau-phan-vung-iata.csv' : '#' ); ?>" class="as-btn as-btn--secondary page-title-action" download>
				<span class="dashicons dashicons-location-alt"></span>
				<span><?php echo esc_html__( 'Tải file Zone mẫu (.csv)', 'allship-ups-quote' ); ?></span>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=allship-ups-quote' ) ); ?>" class="as-btn as-btn--secondary page-title-action">
				<span class="dashicons dashicons-arrow-left-alt"></span>
				<span><?php echo esc_html__( 'Quay lại Bảng giá', 'allship-ups-quote' ); ?></span>
			</a>
		</div>
	</div>
	<hr class="wp-header-end">

	<div id="allshipImportNotice" class="notice" style="display:none; margin-top: 15px;">
		<p></p>
	</div>

	<!-- Wizard Stepper Navigation -->
	<ol class="as-wizard-steps" style="margin-top: 20px;">
		<li id="stepTab1" class="as-wizard-step as-wizard-step--active">
			<span class="as-wizard-step__badge">1</span>
			<span><?php echo esc_html__( 'Tải lên File', 'allship-ups-quote' ); ?></span>
		</li>
		<li id="stepTab2" class="as-wizard-step">
			<span class="as-wizard-step__badge">2</span>
			<span><?php echo esc_html__( 'Xem trước & Kiểm tra (Preview)', 'allship-ups-quote' ); ?></span>
		</li>
		<li id="stepTab3" class="as-wizard-step">
			<span class="as-wizard-step__badge">3</span>
			<span><?php echo esc_html__( 'Hoàn tất Import', 'allship-ups-quote' ); ?></span>
		</li>
	</ol>

	<!-- =======================================================================
	     STEP 1: UPLOAD FILES (TÁCH RIÊNG: BIỂU PHÍ & PHÂN VÙNG)
	     ======================================================================= -->
	<div id="importStep1" class="as-card">
		<!-- Sub-Tab Switcher: Rates vs Zones -->
		<div class="as-import-tabs">
			<button type="button" id="tabSwitchRates" class="as-import-tab as-import-tab--active" onclick="AllshipAdmin.switchImportType('rates')">
				<span class="dashicons dashicons-media-spreadsheet"></span>
				<span><?php echo esc_html__( '1. Import Biểu phí (Rates)', 'allship-ups-quote' ); ?></span>
			</button>
			<button type="button" id="tabSwitchZones" class="as-import-tab" onclick="AllshipAdmin.switchImportType('zones')">
				<span class="dashicons dashicons-admin-site-alt3"></span>
				<span><?php echo esc_html__( '2. Import Phân vùng Quốc gia (Zones)', 'allship-ups-quote' ); ?></span>
			</button>
		</div>

		<!-- SECTION 1: RATES IMPORT FORM -->
		<form id="formImportUploadRates" onsubmit="AllshipAdmin.handleImportPreview(event, 'rates')">
			<input type="hidden" name="import_type" value="rates">
			<div class="as-card__header" style="border-bottom: 1px solid var(--as-border); padding-bottom: 12px; margin-bottom: 16px;">
				<h2 class="as-card__title" style="margin: 0; font-size: 16px;">
					<span class="dashicons dashicons-upload" style="margin-right: 6px;"></span>
					<?php echo esc_html__( 'Bước 1: Chọn biểu phí UPS (Rate Card)', 'allship-ups-quote' ); ?>
				</h2>
			</div>

			<table class="form-table" role="presentation" style="margin-top: 0;">
				<tbody>
					<tr>
						<th scope="row">
							<label for="importCardName"><?php echo esc_html__( 'Tên Bảng giá', 'allship-ups-quote' ); ?> <span class="required" style="color:red;">*</span></label>
						</th>
						<td>
							<input type="text" id="importCardName" name="rate_card_name" class="regular-text" required value="UPS Net Rates <?php echo esc_attr( date( 'Y-m-d' ) ); ?>" placeholder="VD: UPS Net Rates 2026">
							<p class="description"><?php echo esc_html__( 'Tên nhận diện cho bảng giá mới được tạo (sẽ lưu ở trạng thái Draft).', 'allship-ups-quote' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="importValidFrom"><?php echo esc_html__( 'Ngày có hiệu lực', 'allship-ups-quote' ); ?> <span class="required" style="color:red;">*</span></label>
						</th>
						<td>
							<input type="date" id="importValidFrom" name="valid_from" class="regular-text" required value="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>">
						</td>
					</tr>
					<tr>
					<th scope="row">
						<label for="importRateGroup"><?php echo esc_html__( 'Nhóm cước (Dịch vụ)', 'allship-ups-quote' ); ?> <span class="required" style="color:red;">*</span></label>
					</th>
					<td>
						<select id="importRateGroup" name="rate_group" class="regular-text" required>
							<?php
							$rate_groups = class_exists( 'Allship_UPS_Rate_Importer' )
								? Allship_UPS_Rate_Importer::VALID_RATE_GROUPS
								: [ 'export_wxs_nondocument' => 'Express Saver Non-Document (WXS Non-Doc) - Export' ];
							$last_prefix = '';
							foreach ( $rate_groups as $key => $label ) :
								$prefix = strpos( $key, 'import_' ) === 0 ? 'import' : 'export';
								if ( $prefix !== $last_prefix ) :
									if ( $last_prefix ) echo '</optgroup>';
									$group_label = 'export' === $prefix ? 'Xuất khẩu (Export)' : 'Nhập khẩu (Import)';
							?>
								<optgroup label="<?php echo esc_attr( $group_label ); ?>">
							<?php $last_prefix = $prefix; endif; ?>
								<option value="<?php echo esc_attr( $key ); ?>"<?php echo ( 'export_wxs_nondocument' === $key ) ? ' selected="selected"' : ''; ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
							<?php if ( $last_prefix ) echo '</optgroup>'; ?>
						</select>
						<p class="description"><?php echo esc_html__( 'Chọn nhóm dịch vụ cước tương ứng với file bảng giá đang import. Mỗi lần import 1 nhóm.', 'allship-ups-quote' ); ?></p>
					</td>
				</tr>
					<tr>
						<th scope="row">
							<label><?php echo esc_html__( 'Phân vùng Quốc gia (Zone)', 'allship-ups-quote' ); ?> <span class="required" style="color:red;">*</span></label>
						</th>
						<td>
							<fieldset style="margin-bottom: 12px;">
								<label style="margin-right: 20px; font-weight: 600; cursor: pointer;">
									<input type="radio" name="zone_mode" id="zoneModeExisting" value="existing" checked onchange="AllshipAdmin.toggleZoneMode('existing')">
									<?php echo esc_html__( 'Sử dụng Bảng Zone đã có', 'allship-ups-quote' ); ?>
								</label>
								<label style="font-weight: 600; cursor: pointer;">
									<input type="radio" name="zone_mode" id="zoneModeNew" value="new" onchange="AllshipAdmin.toggleZoneMode('new')">
									<?php echo esc_html__( 'Import kèm Bảng Zone mới', 'allship-ups-quote' ); ?>
								</label>
							</fieldset>

							<!-- Container 1: Chọn Zone đã có -->
							<div id="zoneModeExistingContainer" style="margin-top: 8px;">
								<select id="importZoneSetId" name="zone_set_id" class="regular-text" style="width: 100%; max-width: 450px;">
									<?php if ( empty( $zone_sets ) ) : ?>
										<option value=""><?php echo esc_html__( '— Chưa có Bảng Zone nào trong hệ thống —', 'allship-ups-quote' ); ?></option>
									<?php else : ?>
										<?php foreach ( $zone_sets as $zs ) : ?>
											<option value="<?php echo esc_attr( $zs->id ); ?>">
												<?php
												$rec_cnt = function_exists( 'number_format_i18n' ) ? number_format_i18n( $zs->record_count ) : number_format( $zs->record_count );
												echo esc_html(
													sprintf(
														'%s (%s dòng - %d bảng giá dùng)',
														$zs->name,
														$rec_cnt,
														$zs->used_count
													)
												);
												?>
											</option>
										<?php endforeach; ?>
									<?php endif; ?>
								</select>
								<p class="description"><?php echo esc_html__( 'Bảng giá mới sẽ tái sử dụng cấu hình phân vùng quốc gia từ Bảng Zone được chọn.', 'allship-ups-quote' ); ?></p>
							</div>

							<!-- Container 2: Upload Zone mới kèm theo -->
							<div id="zoneModeNewContainer" style="display: none; margin-top: 12px; padding: 16px; background: #f8fafc; border: 1px solid var(--as-border); border-radius: 8px; max-width: 550px;">
								<div style="margin-bottom: 12px;">
									<label for="importNewZoneSetName" style="display: block; font-weight: 600; margin-bottom: 4px;">
										<?php echo esc_html__( 'Tên Bảng Zone mới', 'allship-ups-quote' ); ?> <span class="required" style="color:red;">*</span>
									</label>
									<input type="text" id="importNewZoneSetName" name="new_zone_set_name" class="regular-text" style="width: 100%;" placeholder="VD: Zone UPS 2026">
								</div>
								<div>
									<label style="display: block; font-weight: 600; margin-bottom: 4px;">
										<?php echo esc_html__( 'File Phân vùng (IATA Zone Mapping)', 'allship-ups-quote' ); ?> <span class="required" style="color:red;">*</span>
									</label>
									<div class="as-dropzone" id="dropzoneRateZone" style="padding: 16px 12px;">
										<input type="file" id="inputRateZoneFile" name="zone_file" class="as-dropzone__input" accept=".xlsx,.csv" onchange="AllshipAdmin.updateFileNameDisplay('inputRateZoneFile', 'rateZoneFileNameDisplay')">
										<div class="as-dropzone__content">
											<span class="dashicons dashicons-location-alt" style="font-size: 28px; width: 28px; height: 28px; color: var(--as-wp-blue);"></span>
											<p style="margin: 6px 0 2px; font-weight: 600;" id="rateZoneFileNameDisplay"><?php echo esc_html__( 'Kéo thả file Zone hoặc bấm để chọn', 'allship-ups-quote' ); ?></p>
											<span style="font-size: 11px; color: var(--as-text-muted);"><?php echo esc_html__( 'Định dạng .csv hoặc .xlsx (Tối đa 10MB)', 'allship-ups-quote' ); ?></span>
										</div>
									</div>
								</div>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="inputRateFile"><?php echo esc_html__( 'File Biểu phí (Rates)', 'allship-ups-quote' ); ?> <span class="required" style="color:red;">*</span></label>
						</th>
						<td>
							<div class="as-dropzone" id="dropzoneRate">
								<input type="file" id="inputRateFile" name="rate_file" class="as-dropzone__input" accept=".xlsx,.csv" required onchange="AllshipAdmin.updateFileNameDisplay('inputRateFile', 'rateFileNameDisplay')">
								<div class="as-dropzone__content">
									<span class="dashicons dashicons-media-spreadsheet" style="font-size: 32px; width: 32px; height: 32px; color: var(--as-wp-blue);"></span>
									<p style="margin: 8px 0 4px; font-weight: 600;" id="rateFileNameDisplay"><?php echo esc_html__( 'Kéo thả file biểu phí hoặc bấm để chọn', 'allship-ups-quote' ); ?></p>
									<span style="font-size: 12px; color: var(--as-text-muted);"><?php echo esc_html__( 'Định dạng .xlsx (Tối đa 10MB)', 'allship-ups-quote' ); ?></span>
								</div>
							</div>
							<p class="description">
								<?php echo esc_html__( 'File biểu phí UPS xuất khẩu hoặc nhập khẩu.', 'allship-ups-quote' ); ?>
								<a href="<?php echo esc_url( defined( 'ALLSHIP_UPS_QUOTE_URL' ) ? ALLSHIP_UPS_QUOTE_URL . 'assets/samples/mau-bang-gia-ups-sample.xlsx' : '#' ); ?>" download style="font-weight: 600; margin-left: 6px;">
									<span class="dashicons dashicons-download" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
									<?php echo esc_html__( 'Tải file Bảng giá mẫu (.xlsx)', 'allship-ups-quote' ); ?>
								</a>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--as-border); display: flex; justify-content: flex-end; gap: 12px;">
				<button type="submit" id="btnStartImportPreviewRates" class="as-btn as-btn--primary as-btn--lg button button-primary">
					<span class="dashicons dashicons-arrow-right-alt"></span>
					<span><?php echo esc_html__( 'Tiếp tục: Kiểm tra dữ liệu Biểu phí (Preview)', 'allship-ups-quote' ); ?></span>
				</button>
			</div>
		</form>

		<!-- SECTION 2: ZONES IMPORT FORM (STANDALONE ZONE SET) -->
		<form id="formImportUploadZones" style="display:none;" onsubmit="AllshipAdmin.handleImportPreview(event, 'zones')">
			<input type="hidden" name="import_type" value="zones">
			<div class="as-card__header" style="border-bottom: 1px solid var(--as-border); padding-bottom: 12px; margin-bottom: 16px;">
				<h2 class="as-card__title" style="margin: 0; font-size: 16px;">
					<span class="dashicons dashicons-admin-site-alt3" style="margin-right: 6px;"></span>
					<?php echo esc_html__( 'Bước 1: Import Bảng Phân vùng mới (Zone Set độc lập)', 'allship-ups-quote' ); ?>
				</h2>
			</div>

			<table class="form-table" role="presentation" style="margin-top: 0;">
				<tbody>
					<tr>
						<th scope="row">
							<label for="inputStandaloneZoneSetName"><?php echo esc_html__( 'Tên Bảng Phân vùng', 'allship-ups-quote' ); ?> <span class="required" style="color:red;">*</span></label>
						</th>
						<td>
							<input type="text" id="inputStandaloneZoneSetName" name="zone_set_name" class="regular-text" required value="Zone UPS <?php echo esc_attr( date( 'Y-m-d' ) ); ?>" placeholder="VD: Zone UPS 2026 Standard">
							<p class="description"><?php echo esc_html__( 'Đặt tên định danh cho Bảng Zone để tái sử dụng nhiều lần cho các Bảng giá khác nhau.', 'allship-ups-quote' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="inputStandaloneZoneSetDesc"><?php echo esc_html__( 'Mô tả / Ghi chú', 'allship-ups-quote' ); ?></label>
						</th>
						<td>
							<textarea id="inputStandaloneZoneSetDesc" name="zone_set_description" class="large-text" rows="2" placeholder="Ghi chú về nguồn file, phiên bản bảng Zone..."></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="inputZoneFileOnly"><?php echo esc_html__( 'File Phân vùng (Zones)', 'allship-ups-quote' ); ?> <span class="required" style="color:red;">*</span></label>
						</th>
						<td>
							<div class="as-dropzone" id="dropzoneZoneOnly">
								<input type="file" id="inputZoneFileOnly" name="zone_file" class="as-dropzone__input" accept=".xlsx,.csv" required onchange="AllshipAdmin.updateFileNameDisplay('inputZoneFileOnly', 'zoneFileNameDisplayOnly')">
								<div class="as-dropzone__content">
									<span class="dashicons dashicons-location-alt" style="font-size: 32px; width: 32px; height: 32px; color: var(--as-wp-blue);"></span>
									<p style="margin: 8px 0 4px; font-weight: 600;" id="zoneFileNameDisplayOnly"><?php echo esc_html__( 'Kéo thả file phân vùng (IATA) hoặc bấm để chọn', 'allship-ups-quote' ); ?></p>
									<span style="font-size: 12px; color: var(--as-text-muted);"><?php echo esc_html__( 'Định dạng .csv hoặc .xlsx (Tối đa 10MB)', 'allship-ups-quote' ); ?></span>
								</div>
							</div>
							<p class="description">
								<?php echo esc_html__( 'Cập nhật bảng ánh xạ mã quốc gia sang các Zone cước phí theo từng dịch vụ (EXW, WFM, WXP, WXS, XPD, XPR). Dữ liệu này dùng chung cho toàn hệ thống và tái sử dụng cho nhiều bảng giá.', 'allship-ups-quote' ); ?>
								<a href="<?php echo esc_url( defined( 'ALLSHIP_UPS_QUOTE_URL' ) ? ALLSHIP_UPS_QUOTE_URL . 'assets/samples/mau-phan-vung-iata.xlsx' : '#' ); ?>" download style="font-weight: 600; margin-left: 6px;">
									<span class="dashicons dashicons-download" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
									<?php echo esc_html__( 'Tải file Zone mẫu tham khảo (IATA.xlsx)', 'allship-ups-quote' ); ?>
								</a>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--as-border); display: flex; justify-content: flex-end; gap: 12px;">
				<button type="submit" id="btnStartImportPreviewZones" class="as-btn as-btn--primary as-btn--lg button button-primary">
					<span class="dashicons dashicons-arrow-right-alt"></span>
					<span><?php echo esc_html__( 'Tiếp tục: Kiểm tra dữ liệu Phân vùng (Preview)', 'allship-ups-quote' ); ?></span>
				</button>
			</div>
		</form>
	</div>

	<!-- =======================================================================
	     STEP 2: PREVIEW & VALIDATION SUMMARY
	     ======================================================================= -->
	<div id="importStep2" class="as-card" style="display:none;">
		<div class="as-card__header">
			<h2 class="as-card__title">
				<span class="dashicons dashicons-visibility" style="margin-right: 6px;"></span>
				<?php echo esc_html__( 'Bước 2: Kết quả kiểm tra dữ liệu (Pre-flight Preview)', 'allship-ups-quote' ); ?>
			</h2>
			<div id="previewStatusBadge"></div>
		</div>

		<!-- Rates Overview Stats -->
		<div class="as-stats-grid" id="previewRateStatsBox">
			<div class="as-stat-card">
				<div class="as-stat-card__val" id="previewGroupsCount">0</div>
				<div class="as-stat-card__label"><?php echo esc_html__( 'Nhóm cước tìm thấy', 'allship-ups-quote' ); ?></div>
			</div>
			<div class="as-stat-card">
				<div class="as-stat-card__val" id="previewUs5Flag"><?php echo esc_html__( 'Không', 'allship-ups-quote' ); ?></div>
				<div class="as-stat-card__label"><?php echo esc_html__( 'Phát hiện cột US5 (Mỹ)', 'allship-ups-quote' ); ?></div>
			</div>
		</div>

		<!-- Zones Overview Stats -->
		<div class="as-stats-grid" id="previewZoneStatsBox" style="display:none;">
			<div class="as-stat-card">
				<div class="as-stat-card__val" id="previewZoneCountriesVal">0</div>
				<div class="as-stat-card__label"><?php echo esc_html__( 'Quốc gia / Lãnh thổ', 'allship-ups-quote' ); ?></div>
			</div>
			<div class="as-stat-card">
				<div class="as-stat-card__val" id="previewZoneMapsVal">0</div>
				<div class="as-stat-card__label"><?php echo esc_html__( 'Dòng Mapping Zone', 'allship-ups-quote' ); ?></div>
			</div>
		</div>

		<!-- Blocking Errors -->
		<div id="previewErrorsContainer" style="display:none; margin-bottom: 20px;">
			<div class="notice notice-error inline" style="margin: 0; padding: 12px 16px;">
				<h4 style="margin: 0 0 6px 0; color: #b32d2e; font-weight: 700;"><?php echo esc_html__( 'Phát hiện lỗi không thể import:', 'allship-ups-quote' ); ?></h4>
				<ul id="previewErrorsList" style="margin: 0; padding-left: 20px; list-style: disc;"></ul>
			</div>
		</div>

		<!-- Warnings -->
		<div id="previewWarningsContainer" style="display:none; margin-bottom: 20px;">
			<div class="notice notice-warning inline" style="margin: 0; padding: 12px 16px;">
				<h4 style="margin: 0 0 6px 0; color: #b26200; font-weight: 700;"><?php echo esc_html__( 'Cảnh báo cấu trúc (Hệ thống vẫn cho phép import):', 'allship-ups-quote' ); ?></h4>
				<ul id="previewWarningsList" style="margin: 0; padding-left: 20px; list-style: disc;"></ul>
			</div>
		</div>

		<!-- Detected Rate Groups Section (Rates Mode) -->
		<div id="previewRateGroupsSection">
			<h3 style="font-size: 15px; font-weight: 700; margin: 20px 0 10px; color: var(--as-text-dark);">
				<?php echo esc_html__( 'Chi tiết các Nhóm cước phát hiện trong file:', 'allship-ups-quote' ); ?>
			</h3>
			<table class="wp-list-table widefat fixed striped as-table" id="previewRateGroupsTable">
				<thead>
					<tr>
						<th scope="col" style="width: 120px;"><?php echo esc_html__( 'Mã dịch vụ', 'allship-ups-quote' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Tên Nhóm cước', 'allship-ups-quote' ); ?></th>
						<th scope="col" style="width: 180px;"><?php echo esc_html__( 'Khóa hệ thống (Key)', 'allship-ups-quote' ); ?></th>
						<th scope="col" style="width: 140px; text-align: right;"><?php echo esc_html__( 'Số dòng mức cước', 'allship-ups-quote' ); ?></th>
					</tr>
				</thead>
				<tbody id="previewRateGroupsBody">
					<!-- Injected by JavaScript -->
				</tbody>
			</table>
		</div>

		<!-- Services Summary Section (Zones Mode) -->
		<div id="previewZoneServicesSection" style="display:none;">
			<h3 style="font-size: 15px; font-weight: 700; margin: 20px 0 10px; color: var(--as-text-dark);">
				<?php echo esc_html__( 'Phân bố dịch vụ cước trong file phân vùng:', 'allship-ups-quote' ); ?>
			</h3>
			<div id="previewZoneServicesList" style="padding: 16px; background: #f8fafc; border: 1px solid var(--as-border); display: flex; flex-wrap: wrap;">
				<!-- Injected by JavaScript -->
			</div>
		</div>

		<!-- Actions -->
		<div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--as-border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
			<button type="button" class="as-btn as-btn--danger button button-link-delete" onclick="AllshipAdmin.handleImportCancel()">
				<span class="dashicons dashicons-trash"></span>
				<span><?php echo esc_html__( 'Hủy bỏ phiên import', 'allship-ups-quote' ); ?></span>
			</button>

			<div style="display: flex; gap: 10px;">
				<button type="button" class="as-btn as-btn--secondary button" onclick="AllshipAdmin.backToStep1()">
					<span class="dashicons dashicons-arrow-left-alt"></span>
					<span><?php echo esc_html__( 'Quay lại tải file khác', 'allship-ups-quote' ); ?></span>
				</button>
				<button type="button" id="btnExecuteImport" class="as-btn as-btn--primary as-btn--lg button button-primary" onclick="AllshipAdmin.handleImportExecute()">
					<span class="dashicons dashicons-yes-alt"></span>
					<span><?php echo esc_html__( 'Xác nhận Import', 'allship-ups-quote' ); ?></span>
				</button>
			</div>
		</div>
	</div>

	<!-- =======================================================================
	     STEP 3: IMPORT COMPLETE
	     ======================================================================= -->
	<div id="importStep3" class="as-card" style="display:none; text-align: center; padding: 48px 24px;">
		<div style="width: 64px; height: 64px; margin: 0 auto 16px; background: var(--as-success-bg); border: 1px solid var(--as-success-border); display: flex; align-items: center; justify-content: center; color: var(--as-success);">
			<span class="dashicons dashicons-yes" style="font-size: 40px; width: 40px; height: 40px;"></span>
		</div>
		<h2 style="font-size: 22px; font-weight: 700; color: var(--as-text-dark); margin: 0 0 8px;">
			<?php echo esc_html__( 'Import hoàn tất thành công!', 'allship-ups-quote' ); ?>
		</h2>
		<p id="resCompleteMsg" style="font-size: 14px; color: var(--as-text-muted); max-width: 580px; margin: 0 auto 24px; line-height: 1.6;">
			<?php echo esc_html__( 'Dữ liệu đã được nạp thành công vào hệ thống.', 'allship-ups-quote' ); ?>
		</p>

		<div class="allship-section-box" style="max-width: 500px; margin: 0 auto 28px; text-align: left;">
			<table class="widefat" style="border: none; box-shadow: none;">
				<tbody>
					<tr id="resCardIdRow">
						<th style="padding: 8px 12px; font-weight: 600; width: 180px;"><?php echo esc_html__( 'Mã Bảng giá (ID):', 'allship-ups-quote' ); ?></th>
						<td style="padding: 8px 12px;"><strong id="resCardId">#0</strong></td>
					</tr>
					<tr id="resCardNameRow">
						<th style="padding: 8px 12px; font-weight: 600;"><?php echo esc_html__( 'Tên Bảng giá:', 'allship-ups-quote' ); ?></th>
						<td style="padding: 8px 12px;" id="resCardName">-</td>
					</tr>
					<tr id="resRatesCountRow">
						<th style="padding: 8px 12px; font-weight: 600;"><?php echo esc_html__( 'Dòng cước đã nạp:', 'allship-ups-quote' ); ?></th>
						<td style="padding: 8px 12px;" id="resRatesCount">0</td>
					</tr>
					<tr id="resZonesCountRow">
						<th style="padding: 8px 12px; font-weight: 600;"><?php echo esc_html__( 'Dòng Zone đã nạp:', 'allship-ups-quote' ); ?></th>
						<td style="padding: 8px 12px;" id="resZonesCount">0</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div style="display: flex; justify-content: center; gap: 14px; flex-wrap: wrap;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=allship-ups-quote' ) ); ?>" class="as-btn as-btn--primary as-btn--lg button button-primary">
				<span class="dashicons dashicons-list-view"></span>
				<span><?php echo esc_html__( 'Đi tới Quản lý Bảng giá (Rate Cards)', 'allship-ups-quote' ); ?></span>
			</a>
			<button type="button" class="as-btn as-btn--secondary as-btn--lg button" onclick="AllshipAdmin.resetImportWizard()">
				<span class="dashicons dashicons-plus-alt2"></span>
				<span><?php echo esc_html__( 'Import thêm file khác', 'allship-ups-quote' ); ?></span>
			</button>
		</div>
	</div>
</div>
