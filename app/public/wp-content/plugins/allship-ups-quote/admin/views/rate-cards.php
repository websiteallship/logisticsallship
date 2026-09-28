<?php
/**
 * Admin View: Rate Cards List & Management
 *
 * Model: 1 Rate Card = 1 Rate Group (Single service per card)
 * Columns: ID, Tên Bảng giá, Nhóm cước (Rate Group), Trạng thái, Thao tác.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Allship_UPS_Admin_Rate_Cards' ) && file_exists( dirname( __DIR__ ) . '/class-admin-rate-cards.php' ) ) {
	require_once dirname( __DIR__ ) . '/class-admin-rate-cards.php';
}

$controller = class_exists( 'Allship_UPS_Admin_Rate_Cards' ) ? new Allship_UPS_Admin_Rate_Cards() : null;
$cards      = $controller ? $controller->get_rate_cards_list() : [];

// Build rate_group label map from Importer constant
$rate_group_labels = [];
if ( class_exists( 'Allship_UPS_Rate_Importer' ) ) {
	$rate_group_labels = Allship_UPS_Rate_Importer::VALID_RATE_GROUPS;
}
?>

<div class="wrap allship-ups-admin-wrap">
	<div class="as-page-header">
		<h1 class="wp-heading-inline as-page-header__title"><?php echo esc_html( $page_title ?? __( 'Quản lý Bảng giá (Rate Cards)', 'allship-ups-quote' ) ); ?></h1>
		<div class="as-page-header__actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=allship-ups-import' ) ); ?>" class="as-btn as-btn--primary page-title-action button-primary">
				<span class="dashicons dashicons-upload"></span>
				<span><?php echo esc_html__( 'Import Bảng giá', 'allship-ups-quote' ); ?></span>
			</a>
		</div>
	</div>
	<hr class="wp-header-end">

	<div id="allshipGlobalNotice" class="notice notice-success is-dismissible" style="display:none;">
		<p></p>
	</div>

	<?php if ( empty( $cards ) ) : ?>
		<div class="as-empty-state allship-empty-card">
			<div class="as-empty-state__icon-wrap allship-empty-icon">
				<span class="dashicons dashicons-media-spreadsheet as-empty-state__icon"></span>
			</div>
			<h3 class="as-empty-state__title"><?php echo esc_html__( 'Chưa có Bảng giá UPS nào trong hệ thống', 'allship-ups-quote' ); ?></h3>
			<p class="as-empty-state__desc">
				<?php echo esc_html__( 'Hệ thống cần ít nhất một bảng giá đang hoạt động (Active) để khách hàng tra cứu cước phí. Bắt đầu bằng cách import file Excel bảng giá.', 'allship-ups-quote' ); ?>
			</p>
			<div class="as-empty-state__actions allship-empty-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=allship-ups-import' ) ); ?>" class="as-btn as-btn--primary as-btn--lg button button-primary button-hero">
					<span class="dashicons dashicons-upload"></span>
					<span><?php echo esc_html__( 'Import từ file Excel', 'allship-ups-quote' ); ?></span>
				</a>
			</div>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped table-view-list rate-cards-table" style="margin-top: 15px;">
			<thead>
				<tr>
					<th scope="col" style="width: 55px;">ID</th>
					<th scope="col"><?php echo esc_html__( 'Tên Bảng giá', 'allship-ups-quote' ); ?></th>
					<th scope="col" style="width: 320px;"><?php echo esc_html__( 'Nhóm cước (Rate Group)', 'allship-ups-quote' ); ?></th>
					<th scope="col" style="width: 130px; text-align: center;"><?php echo esc_html__( 'Trạng thái', 'allship-ups-quote' ); ?></th>
					<th scope="col" style="width: 290px; text-align: right;"><?php echo esc_html__( 'Thao tác', 'allship-ups-quote' ); ?></th>
				</tr>
			</thead>
			<tbody id="rateCardsTableBody">
				<?php foreach ( $cards as $card ) : ?>
					<?php
					$rg_info = $card['rate_group_info'];
					$rg_key  = $rg_info ? $rg_info['key'] : null;
					$rg_raw_label = $rg_key && isset( $rate_group_labels[ $rg_key ] ) ? $rate_group_labels[ $rg_key ] : ( $rg_key ?: '' );

					// Clean label: remove redundant trailing "- Export" or "- Import"
					$rg_clean_label = preg_replace( '/\s*-\s*(Export|Import)$/i', '', $rg_raw_label );

					// Detect direction from rate_group prefix
					$direction_label = '';
					$direction_class = '';
					if ( $rg_key ) {
						if ( 0 === strpos( $rg_key, 'import_' ) ) {
							$direction_label = 'Import';
							$direction_class = 'allship-chip-green';
						} else {
							$direction_label = 'Export';
							$direction_class = 'allship-chip-blue';
						}
					}
					?>
					<tr id="rate-card-row-<?php echo esc_attr( $card['id'] ); ?>" class="<?php echo 'active' === $card['status'] ? 'is-active-card' : ''; ?>">
						<td><span style="font-weight: 700; color: #64748b;">#<?php echo esc_html( $card['id'] ); ?></span></td>
						<td>
							<strong>
								<a href="javascript:void(0)" onclick="AllshipAdmin.openRenameModal(<?php echo esc_attr( $card['id'] ); ?>, '<?php echo esc_js( $card['name'] ); ?>')" class="row-title" title="<?php echo esc_attr__( 'Bấm để đổi tên bảng giá', 'allship-ups-quote' ); ?>">
									<?php echo esc_html( $card['name'] ); ?>
								</a>
							</strong>
							<div class="row-meta" style="font-size: 11px; color: #64748b; margin-top: 4px;">
								<span>Mã TT: <strong><?php echo esc_html( $card['market_code'] ); ?></strong></span>
								<span style="margin: 0 4px; color: #cbd5e1;">|</span>
								<span>Hiệu lực: <?php echo esc_html( $card['valid_from'] ); ?></span>
								<?php if ( $card['imported_at'] ) : ?>
									<span style="margin: 0 4px; color: #cbd5e1;">|</span>
									<span>Import: <?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $card['imported_at'] ) ) ); ?></span>
								<?php endif; ?>
							</div>
						</td>
						<td>
							<?php if ( $rg_key ) : ?>
								<div class="as-rate-group-info">
									<div class="as-rate-group-title">
										<span class="allship-chip <?php echo esc_attr( $direction_class ); ?>"><?php echo esc_html( $direction_label ); ?></span>
										<strong style="font-size: 13px; color: #1e293b;"><?php echo esc_html( $rg_clean_label ); ?></strong>
									</div>
									<span class="as-rate-group-key"><?php echo esc_html( $rg_key ); ?></span>
								</div>
							<?php else : ?>
								<span style="color: #94a3b8; font-size: 12px;"><em>Chưa có dữ liệu cước</em></span>
							<?php endif; ?>
						</td>
						<td style="text-align: center;">
							<?php echo $card['status_badge']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
						<td style="text-align: right; white-space: nowrap;">
							<div class="allship-action-buttons">
								<button type="button" class="button button-small" onclick="AllshipAdmin.openRenameModal(<?php echo esc_attr( $card['id'] ); ?>, '<?php echo esc_js( $card['name'] ); ?>')" title="<?php echo esc_attr__( 'Đổi tên bảng giá', 'allship-ups-quote' ); ?>">
									<span class="dashicons dashicons-edit"></span>
									<span><?php echo esc_html__( 'Sửa tên', 'allship-ups-quote' ); ?></span>
								</button>
								<?php if ( 'active' === $card['status'] ) : ?>
									<button type="button" class="button button-small" onclick="AllshipAdmin.archiveCard(<?php echo esc_attr( $card['id'] ); ?>)" title="<?php echo esc_attr__( 'Lưu trữ (ngừng kích hoạt)', 'allship-ups-quote' ); ?>">
										<span class="dashicons dashicons-archive"></span>
										<span><?php echo esc_html__( 'Lưu trữ', 'allship-ups-quote' ); ?></span>
									</button>
								<?php else : ?>
									<button type="button" class="button button-small button-primary" onclick="AllshipAdmin.activateCard(<?php echo esc_attr( $card['id'] ); ?>)" title="<?php echo esc_attr__( 'Kích hoạt áp dụng bảng giá này', 'allship-ups-quote' ); ?>">
										<span class="dashicons dashicons-yes-alt"></span>
										<span><?php echo esc_html__( 'Kích hoạt', 'allship-ups-quote' ); ?></span>
									</button>
									<button type="button" class="button button-small button-link-delete" onclick="AllshipAdmin.deleteCard(<?php echo esc_attr( $card['id'] ); ?>)" title="<?php echo esc_attr__( 'Xóa vĩnh viễn bảng giá này', 'allship-ups-quote' ); ?>">
										<span class="dashicons dashicons-trash"></span>
										<span><?php echo esc_html__( 'Xóa', 'allship-ups-quote' ); ?></span>
									</button>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<!-- Modal: Đổi tên Bảng giá -->
<div id="modalRenameRateCard" class="allship-modal-backdrop" style="display:none;">
	<div class="allship-modal-box" style="max-width: 480px;">
		<div class="allship-modal-header">
			<h3><?php echo esc_html__( 'Đổi tên Bảng giá', 'allship-ups-quote' ); ?></h3>
			<button type="button" class="allship-modal-close" onclick="AllshipAdmin.closeModals()">&times;</button>
		</div>
		<form id="formRenameRateCard" onsubmit="AllshipAdmin.handleRenameSubmit(event)">
			<input type="hidden" id="renameCardId" name="id">
			<div class="allship-modal-body">
				<table class="form-table" style="margin: 0;">
					<tr>
						<th scope="row" style="width: 100px;"><label for="renameCardName"><?php echo esc_html__( 'Tên mới', 'allship-ups-quote' ); ?></label></th>
						<td>
							<input type="text" id="renameCardName" name="name" class="regular-text" required style="width: 100%;">
						</td>
					</tr>
				</table>
			</div>
			<div class="allship-modal-footer">
				<button type="button" class="button" onclick="AllshipAdmin.closeModals()"><?php echo esc_html__( 'Hủy', 'allship-ups-quote' ); ?></button>
				<button type="submit" id="btnSubmitRenameCard" class="button button-primary"><?php echo esc_html__( 'Lưu tên mới', 'allship-ups-quote' ); ?></button>
			</div>
		</form>
	</div>
</div>
