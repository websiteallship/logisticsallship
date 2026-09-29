<?php
/**
 * Public Quote Form Template (Mockup V4 Implementation).
 *
 * Fully responsive, accessible UPS freight & parcel calculation interface.
 * Implements 8 sections: Hero, Direction Selector, Service Cards & Shipment Type,
 * Route Information, Piece Details & Volumetric Bar, Calculation Results,
 * Mobile Sticky Action Bar, and Pieces/Booking Modals.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$service_mgr          = class_exists( 'Allship_UPS_Service_Availability_Manager' ) ? new Allship_UPS_Service_Availability_Manager() : null;
$available_directions = $service_mgr ? $service_mgr->get_available_directions() : [ 'export', 'import' ];
$has_both_directions  = count( $available_directions ) > 1;
$default_direction    = in_array( 'export', $available_directions, true ) ? 'export' : ( ! empty( $available_directions[0] ) ? $available_directions[0] : 'export' );
$plugin_version       = defined( 'ALLSHIP_UPS_QUOTE_VERSION' ) ? ALLSHIP_UPS_QUOTE_VERSION : '1.0.0';

$settings_mgr = class_exists( 'Allship_UPS_Settings_Manager' ) ? new Allship_UPS_Settings_Manager() : null;
$dim_divisor  = $settings_mgr ? (int) $settings_mgr->get( 'dim_divisor', 5500 ) : 5500;

$initial_services = [];
if ( class_exists( 'Allship_UPS_REST_Controller' ) ) {
	$ctrl = new Allship_UPS_REST_Controller();
	$req  = class_exists( 'WP_REST_Request' ) ? new WP_REST_Request( 'GET', '/services' ) : (object) [ 'direction' => $default_direction ];
	if ( is_object( $req ) && method_exists( $req, 'set_param' ) ) {
		$req->set_param( 'direction', $default_direction );
	}
	$res = $ctrl->get_services( $req );
	if ( is_object( $res ) && method_exists( $res, 'get_data' ) ) {
		$data = $res->get_data();
		$initial_services = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : [];
	} elseif ( is_array( $res ) ) {
		$initial_services = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : [];
	}
}

$services_by_code = [];
foreach ( $initial_services as $s ) {
	$services_by_code[ $s['code'] ] = $s;
}

$has_any_enabled    = false;
$first_enabled_code = '';
$enabled_total      = 0;
$enabled_parcel     = 0;
$enabled_freight    = 0;

foreach ( [ 'EXW', 'XPR', 'WXS', 'XPD', 'WXP', 'WFM' ] as $sc ) {
	if ( ! empty( $services_by_code[ $sc ]['enabled'] ) ) {
		$has_any_enabled = true;
		$enabled_total++;
		if ( in_array( $sc, [ 'EXW', 'XPR', 'WXS', 'XPD' ], true ) ) {
			$enabled_parcel++;
		} else {
			$enabled_freight++;
		}
		if ( ! $first_enabled_code ) {
			$first_enabled_code = $sc;
		}
	}
}

$default_service_code = 'WXS';
if ( empty( $services_by_code[ $default_service_code ]['enabled'] ) && $first_enabled_code ) {
	$default_service_code = $first_enabled_code;
}

$service_names_map = [
	'EXW' => 'Express Early',
	'XPR' => 'Express Plus',
	'WXS' => 'Express Saver',
	'XPD' => 'Expedited',
	'WXP' => 'Express Freight',
	'WFM' => 'Freight Midday',
];
$default_service_name = $service_names_map[ $default_service_code ] ?? 'Express Saver';

// Auto-align grid layout class based on enabled count
$grid_cols_class = 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 grid-cols-auto-6';
if ( $enabled_total <= 1 ) {
	$grid_cols_class = 'grid-cols-auto-1 max-w-md';
} elseif ( $enabled_total === 2 ) {
	$grid_cols_class = 'grid-cols-auto-2 max-w-2xl';
} elseif ( $enabled_total === 3 ) {
	$grid_cols_class = 'grid-cols-auto-3 max-w-3xl';
} elseif ( $enabled_total === 4 ) {
	$grid_cols_class = 'grid-cols-auto-4';
} elseif ( $enabled_total === 5 ) {
	$grid_cols_class = 'grid-cols-auto-5';
}

$render_svc_card = function( $code, $cat, $doc_split, $name, $desc, $icon_class, $icon_bg, $icon_color, $tag_text, $tag_color ) use ( $services_by_code, $default_service_code ) {
	$s          = $services_by_code[ $code ] ?? null;
	$is_enabled = $s ? ! empty( $s['enabled'] ) : true;
	$is_active  = ( $code === $default_service_code && $is_enabled );
	$reason     = $s['reason'] ?? '';

	$badge_html = '<span class="font-bold text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded service-status-badge">' . esc_html__( 'Khả dụng', 'allship-ups-quote' ) . '</span>';
	if ( ! $is_enabled ) {
		if ( ! empty( $s['admin_disabled'] ) ) {
			$badge_html = '<span class="font-bold text-amber-800 bg-amber-100 px-1.5 py-0.5 rounded service-status-badge" title="' . esc_attr( $reason ) . '">' . esc_html__( 'Tạm tắt', 'allship-ups-quote' ) . '</span>';
		} else {
			$badge_html = '<span class="font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded service-status-badge" title="' . esc_attr( $reason ) . '">' . esc_html__( 'Chưa có giá', 'allship-ups-quote' ) . '</span>';
		}
	}

	$card_classes = 'service-card-item rounded-xl p-3 flex flex-col justify-between';
	if ( $is_active ) {
		$card_classes .= ' active';
	}
	if ( $is_enabled ) {
		$card_classes .= ' cursor-pointer';
	} else {
		$card_classes .= ' is-disabled opacity-45 cursor-not-allowed hidden';
	}

	?>
	<div class="<?php echo esc_attr( $card_classes ); ?>"
		 data-code="<?php echo esc_attr( $code ); ?>"
		 data-cat="<?php echo esc_attr( $cat ); ?>"
		 data-doc-split="<?php echo $doc_split ? 'true' : 'false'; ?>"
		 onclick="selectService('<?php echo esc_attr( $code ); ?>')"
		 role="radio"
		 aria-checked="<?php echo $is_active ? 'true' : 'false'; ?>"
		 tabindex="<?php echo $is_enabled ? '0' : '-1'; ?>"
		 <?php echo ! empty( $reason ) && ! $is_enabled ? 'title="' . esc_attr( $reason ) . '"' : ''; ?>
		 <?php if ( ! $is_enabled ) : ?>style="display: none !important;"<?php endif; ?>
		 aria-label="<?php echo esc_attr( ( function_exists( '__' ) ? __( 'Dịch vụ', 'allship-ups-quote' ) : 'Dịch vụ' ) . ' ' . $name ); ?>">
		<div class="card-check-badge absolute -top-1.5 -right-1.5 w-4.5 h-4.5 rounded-full bg-brand-red text-white text-[10px] items-center justify-center">
			<i class="ph-bold ph-check" aria-hidden="true"></i>
		</div>
		<div>
			<div class="flex items-center justify-between mb-2">
				<div class="w-8 h-8 rounded-lg flex items-center justify-center text-base <?php echo esc_attr( $icon_bg . ' ' . $icon_color ); ?>">
					<i class="ph-bold <?php echo esc_attr( $icon_class ); ?>" aria-hidden="true"></i>
				</div>
				<span class="text-[9px] font-bold text-slate-400 bg-slate-100 px-1 py-0.5 rounded"><?php echo esc_html( $code ); ?></span>
			</div>
			<div class="text-xs font-extrabold text-navy-900 leading-tight"><?php echo esc_html( $name ); ?></div>
			<div class="text-[10px] text-slate-500 mt-0.5 font-medium leading-snug"><?php echo esc_html( $desc ); ?></div>
		</div>
		<div class="mt-2 pt-1.5 border-t border-slate-100 flex items-center justify-between text-[9px]">
			<?php echo $badge_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="<?php echo esc_attr( $tag_color ); ?> font-bold"><?php echo esc_html( $tag_text ); ?></span>
		</div>
	</div>
	<?php
};
?>

<div id="ups-quote-app" class="ups-quote-form ups-quote-app max-w-[1200px] mx-auto my-4 md:my-8 px-3 sm:px-5 w-full font-sans text-navy-900 leading-relaxed" data-version="<?php echo esc_attr( $plugin_version ); ?>">

	<!-- ============================================================== -->
	<!-- SECTION 1: HERO HEADER                                         -->
	<!-- ============================================================== -->
	<header class="text-center mb-4 md:mb-7">
		<div class="inline-flex items-center gap-1.5 bg-blue-50 text-blue-600 text-xs md:text-[13px] font-bold px-3.5 py-1 rounded-full mb-2 tracking-wide">
			<?php echo esc_html__( 'Tính Cước UPS Tức Thì', 'allship-ups-quote' ); ?>
		</div>
		<h1 class="text-[22px] md:text-[32px] font-extrabold text-navy-900 tracking-tight mb-1">
			<?php echo esc_html__( 'Báo Giá Vận Chuyển Quốc Tế', 'allship-ups-quote' ); ?>
		</h1>
		<p class="hidden sm:block text-xs md:text-sm text-slate-600 max-w-[660px] mx-auto">
			<?php echo esc_html__( 'Tra cứu biểu cước hàng không UPS Net Rates chính hãng. Quy đổi tự động trọng lượng thể tích theo tiêu chuẩn IATA (DIM ', 'allship-ups-quote' ); ?><span id="heroDimDivisor"><?php echo esc_html( $dim_divisor ); ?></span><?php echo esc_html__( ').', 'allship-ups-quote' ); ?>
		</p>
		<div id="heroDirectionBadge" class="inline-flex items-center gap-2 mt-2 text-xs md:text-sm font-bold text-brand-red bg-brand-red-light px-3.5 py-1 rounded-full transition-all" aria-live="polite">
			<i class="ph-bold ph-airplane-takeoff" id="heroDirectionIcon" aria-hidden="true"></i>
			<span id="heroDirectionText" class="flex items-center gap-1.5 font-bold">
				<span><?php echo esc_html__( 'Việt Nam', 'allship-ups-quote' ); ?></span>
				<i class="ph-bold ph-arrow-right text-xs" aria-hidden="true"></i>
				<span><?php echo esc_html__( 'Quốc tế', 'allship-ups-quote' ); ?></span>
			</span>
		</div>
	</header>

	<!-- ============================================================== -->
	<!-- FORM CARD CONTAINER                                            -->
	<!-- ============================================================== -->
	<section class="bg-white rounded-2xl md:rounded-[24px] shadow-xl border border-slate-200/80 p-4 sm:p-6 md:p-8 relative mb-5 md:mb-9" id="quoteFormCard" aria-label="<?php echo esc_attr__( 'Biểu mẫu tính cước UPS', 'allship-ups-quote' ); ?>">

		<!-- ============================================================== -->
		<!-- SECTION 2: CHIỀU VẬN CHUYỂN (DIRECTION SELECTOR)               -->
		<!-- ============================================================== -->
		<div class="mb-5 md:mb-7" id="directionSectionWrapper"<?php if ( ! $has_both_directions ) : ?> style="display:none;"<?php endif; ?>>
			<div class="text-xs md:text-[13px] font-extrabold text-navy-900 mb-2 flex items-center justify-between">
				<span class="flex items-center gap-2 uppercase tracking-wide">
					<i class="ph-bold ph-arrows-left-right text-brand-red text-base" aria-hidden="true"></i>
					<span><?php echo esc_html__( '1. Chiều vận chuyển', 'allship-ups-quote' ); ?></span>
				</span>
				<span class="text-[11px] font-medium text-slate-400"><?php echo esc_html__( 'Chọn hướng gửi để nạp bảng giá', 'allship-ups-quote' ); ?></span>
			</div>

			<!-- Direction Segmented Cards -->
			<div class="grid grid-cols-1 sm:grid-cols-2 gap-2 p-1.5 bg-slate-100 rounded-xl border border-slate-200/60" role="radiogroup" aria-label="<?php echo esc_attr__( 'Chiều vận chuyển', 'allship-ups-quote' ); ?>">
				<button type="button" id="btnDirExport" onclick="selectDirection('export')"
						class="direction-pill-btn active flex items-center justify-between p-3 rounded-lg cursor-pointer text-left group"
						role="radio" aria-checked="true" tabindex="0">
					<div class="flex items-center gap-2.5">
						<div class="dir-icon-box w-9 h-9 rounded-lg bg-slate-200 text-slate-600 flex items-center justify-center text-base shrink-0 transition-colors">
							<i class="ph-bold ph-airplane-takeoff" aria-hidden="true"></i>
						</div>
						<div>
							<div class="text-xs md:text-sm font-extrabold text-navy-900 group-hover:text-brand-red transition-colors flex items-center gap-1.5">
								<span><?php echo esc_html__( 'Xuất hàng đi Quốc tế', 'allship-ups-quote' ); ?></span>
								<span class="text-[10px] font-bold px-1.5 py-0.2 rounded bg-brand-red-light text-brand-red uppercase"><?php echo esc_html__( 'Xuất khẩu', 'allship-ups-quote' ); ?></span>
							</div>
							<div class="text-[11px] text-slate-500 font-medium flex items-center gap-1.5">
								<span><?php echo esc_html__( 'Việt Nam', 'allship-ups-quote' ); ?></span>
								<i class="ph ph-arrow-right text-[10px] text-slate-400" aria-hidden="true"></i>
								<span><?php echo esc_html__( '220+ Quốc gia', 'allship-ups-quote' ); ?></span>
							</div>
						</div>
					</div>
					<div class="w-5 h-5 rounded-full border border-slate-300 flex items-center justify-center text-brand-red shrink-0 check-indicator">
						<i class="ph-bold ph-check text-xs" aria-hidden="true"></i>
					</div>
				</button>

				<button type="button" id="btnDirImport" onclick="selectDirection('import')"
						class="direction-pill-btn flex items-center justify-between p-3 rounded-lg cursor-pointer text-left text-slate-600 hover:text-navy-900 group"
						role="radio" aria-checked="false" tabindex="0">
					<div class="flex items-center gap-2.5">
						<div class="dir-icon-box w-9 h-9 rounded-lg bg-slate-200 text-slate-600 flex items-center justify-center text-base shrink-0 transition-colors">
							<i class="ph-bold ph-airplane-landing" aria-hidden="true"></i>
						</div>
						<div>
							<div class="text-xs md:text-sm font-extrabold text-navy-900 group-hover:text-blue-700 transition-colors flex items-center gap-1.5">
								<span><?php echo esc_html__( 'Nhập hàng về Việt Nam', 'allship-ups-quote' ); ?></span>
								<span class="text-[10px] font-bold px-1.5 py-0.2 rounded bg-blue-100 text-blue-700 uppercase"><?php echo esc_html__( 'Nhập khẩu', 'allship-ups-quote' ); ?></span>
							</div>
							<div class="text-[11px] text-slate-500 font-medium flex items-center gap-1.5">
								<span><?php echo esc_html__( 'Toàn cầu', 'allship-ups-quote' ); ?></span>
								<i class="ph ph-arrow-right text-[10px] text-slate-400" aria-hidden="true"></i>
								<span><?php echo esc_html__( 'Việt Nam', 'allship-ups-quote' ); ?></span>
							</div>
						</div>
					</div>
					<div class="w-5 h-5 rounded-full border border-slate-300 flex items-center justify-center text-brand-red shrink-0 check-indicator opacity-0">
						<i class="ph-bold ph-check text-xs" aria-hidden="true"></i>
					</div>
				</button>
			</div>
		</div>

		<!-- ============================================================== -->
		<!-- SECTION 3: CHỌN DỊCH VỤ UPS (6 CARDS + SHIPMENT TYPE TOGGLE)    -->
		<!-- ============================================================== -->
		<div class="mb-5 md:mb-7">
			<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">
				<div class="text-xs md:text-[13px] font-extrabold text-navy-900 flex items-center gap-2 uppercase tracking-wide">
					<i class="ph-bold ph-package text-brand-red text-base" aria-hidden="true"></i>
					<span><?php echo esc_html__( '2. Chọn dịch vụ UPS', 'allship-ups-quote' ); ?></span>
					<span class="text-xs font-semibold text-slate-400 normal-case" id="serviceCountNotice"><?php echo esc_html( sprintf( ( function_exists( '__' ) ? __( '(%d gói khả dụng)', 'allship-ups-quote' ) : '(%d gói khả dụng)' ), $enabled_total ) ); ?></span>
				</div>

				<!-- Category filter tabs for easy mobile navigation -->
				<div class="inline-flex p-1 bg-slate-100 rounded-xl gap-1 self-start sm:self-auto text-xs font-bold" role="tablist" aria-label="<?php echo esc_attr__( 'Lọc loại dịch vụ', 'allship-ups-quote' ); ?>">
					<button type="button" class="cat-filter-tab active px-3 py-1 rounded-lg cursor-pointer" data-cat="all" onclick="filterCategory('all', this)" role="tab" aria-selected="true">
						<?php echo esc_html( sprintf( ( function_exists( '__' ) ? __( 'Tất cả (%d)', 'allship-ups-quote' ) : 'Tất cả (%d)' ), $enabled_total ) ); ?>
					</button>
					<button type="button" class="cat-filter-tab px-3 py-1 rounded-lg cursor-pointer text-slate-600 hover:text-navy-900<?php echo $enabled_parcel === 0 ? ' opacity-40 pointer-events-none' : ''; ?>" data-cat="parcel" onclick="filterCategory('parcel', this)" role="tab" aria-selected="false">
						<?php echo esc_html( sprintf( ( function_exists( '__' ) ? __( 'Bưu kiện dưới 70kg (%d)', 'allship-ups-quote' ) : 'Bưu kiện dưới 70kg (%d)' ), $enabled_parcel ) ); ?>
					</button>
					<button type="button" class="cat-filter-tab px-3 py-1 rounded-lg cursor-pointer text-slate-600 hover:text-navy-900<?php echo $enabled_freight === 0 ? ' opacity-40 pointer-events-none' : ''; ?>" data-cat="freight" onclick="filterCategory('freight', this)" role="tab" aria-selected="false">
						<?php echo esc_html( sprintf( ( function_exists( '__' ) ? __( 'Hàng nặng trên 70kg (%d)', 'allship-ups-quote' ) : 'Hàng nặng trên 70kg (%d)' ), $enabled_freight ) ); ?>
					</button>
				</div>
			</div>

			<!-- Notice banner when no services are available -->
			<div id="noServicesAvailableNotice" class="<?php echo $has_any_enabled ? 'hidden' : ''; ?> mb-3 p-3.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-center gap-3">
				<i class="ph-bold ph-warning-circle text-xl text-amber-600 shrink-0" aria-hidden="true"></i>
				<div>
					<div class="font-bold"><?php echo esc_html__( 'Hiện chưa có bảng giá khả dụng cho chiều vận chuyển này.', 'allship-ups-quote' ); ?></div>
					<div class="text-[11px] text-amber-700 mt-0.5"><?php echo esc_html__( 'Vui lòng liên hệ Hotline 1900 252 338 để được nhân viên Allship hỗ trợ tra cứu giá nhanh.', 'allship-ups-quote' ); ?></div>
				</div>
			</div>

			<!-- Service Cards Grid with Adaptive Alignment -->
			<div class="grid <?php echo esc_attr( $grid_cols_class ); ?> gap-2 md:gap-2.5" id="serviceTabs" role="radiogroup" aria-label="<?php echo esc_attr__( 'Danh sách gói dịch vụ UPS', 'allship-ups-quote' ); ?>">
				<?php
				$render_svc_card( 'EXW', 'parcel', true, 'Express Early', 'Sớm · 1-2 ngày', 'ph-globe', 'bg-amber-100', 'text-amber-700', 'Sớm', 'text-amber-600' );
				$render_svc_card( 'XPR', 'parcel', true, 'Express Plus', 'Ưu tiên · 1-2 ngày', 'ph-rocket-launch', 'bg-purple-100', 'text-purple-700', 'Ưu tiên', 'text-purple-600' );
				$render_svc_card( 'WXS', 'parcel', true, 'Express Saver', 'Nhanh nhất · 1-3 ngày', 'ph-airplane-tilt', 'bg-amber-500', 'text-white shadow-xs', 'Phổ biến', 'text-amber-600' );
				$render_svc_card( 'XPD', 'parcel', false, 'Expedited', 'Tiết kiệm · 3-5 ngày', 'ph-truck', 'bg-indigo-100', 'text-indigo-700', 'Giá tốt', 'text-indigo-600' );
				$render_svc_card( 'WXP', 'freight', false, 'Express Freight', 'Hỏa tốc trên 70kg', 'ph-lightning', 'bg-rose-100', 'text-rose-600', 'Hỏa tốc nặng', 'text-rose-600' );
				$render_svc_card( 'WFM', 'freight', false, 'Freight Midday', 'Tiêu chuẩn trên 70kg', 'ph-crane', 'bg-emerald-100', 'text-emerald-700', 'Pallet', 'text-emerald-700' );
				?>
			</div>

			<!-- Shipment type toggle (Only shown for EXW, XPR, WXS) -->
			<?php $has_initial_doc_split = in_array( $default_service_code, [ 'EXW', 'XPR', 'WXS' ], true ); ?>
			<div class="mt-4 pt-3.5 border-t border-slate-100" id="shipmentTypeRow"<?php echo ! $has_initial_doc_split ? ' style="display:none;"' : ''; ?>>
				<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1.5 mb-2.5">
					<div class="flex items-center gap-2">
						<span class="text-xs font-extrabold text-navy-900 tracking-wide uppercase"><?php echo esc_html__( 'Phân loại bưu gửi', 'allship-ups-quote' ); ?></span>
						<span class="text-[11px] text-slate-400 font-semibold"><?php echo esc_html__( '(Mặc định: Hàng hóa)', 'allship-ups-quote' ); ?></span>
					</div>
					<div class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-slate-500 bg-slate-50 px-2.5 py-0.5 rounded-full border border-slate-200 self-start sm:self-auto">
						<i class="ph-bold ph-info text-brand-red text-xs" aria-hidden="true"></i>
						<span><?php echo esc_html__( 'Dịch vụ', 'allship-ups-quote' ); ?> <strong class="text-navy-900" id="currentServiceNameType"><?php echo esc_html( $default_service_name ); ?></strong> <?php echo esc_html__( 'có cước ưu đãi cho chứng từ ≤ 5kg', 'allship-ups-quote' ); ?></span>
					</div>
				</div>

				<div class="grid grid-cols-1 sm:grid-cols-2 gap-3" role="radiogroup" aria-label="<?php echo esc_attr__( 'Phân loại bưu gửi', 'allship-ups-quote' ); ?>">
					<!-- Non-doc Card -->
					<div class="type-card-opt active p-3 rounded-xl cursor-pointer flex items-center justify-between" id="optNondoc" onclick="setShipmentType('nondocument')" role="radio" aria-checked="true" tabindex="0">
						<div class="flex items-center gap-3">
							<div class="w-9 h-9 rounded-lg bg-slate-100 text-navy-900 flex items-center justify-center text-lg shrink-0">
								<i class="ph-bold ph-package" aria-hidden="true"></i>
							</div>
							<div>
								<div class="flex items-center gap-1.5">
									<span class="text-xs md:text-sm font-extrabold text-navy-900"><?php echo esc_html__( 'Hàng hóa thông thường', 'allship-ups-quote' ); ?></span>
									<span class="text-[10px] font-mono font-bold bg-slate-100 text-slate-600 px-1.5 py-0.2 rounded">Non-doc</span>
								</div>
								<div class="text-[11px] text-slate-500 mt-0.5"><?php echo esc_html__( 'Hàng mẫu, linh kiện, quà biếu, thương mại (Mọi mức cân)', 'allship-ups-quote' ); ?></div>
							</div>
						</div>
						<div class="w-5 h-5 rounded-full border-2 border-brand-red bg-brand-red text-white flex items-center justify-center text-xs type-check shrink-0">
							<i class="ph-bold ph-check" aria-hidden="true"></i>
						</div>
					</div>

					<!-- Doc Card -->
					<div class="type-card-opt p-3 rounded-xl cursor-pointer flex items-center justify-between" id="optDoc" onclick="setShipmentType('document')" role="radio" aria-checked="false" tabindex="0">
						<div class="flex items-center gap-3">
							<div class="w-9 h-9 rounded-lg bg-indigo-50 text-indigo-700 flex items-center justify-center text-lg shrink-0">
								<i class="ph-bold ph-file-text" aria-hidden="true"></i>
							</div>
							<div>
								<div class="flex items-center gap-1.5">
									<span class="text-xs md:text-sm font-extrabold text-navy-900"><?php echo esc_html__( 'Tài liệu & Chứng từ', 'allship-ups-quote' ); ?></span>
									<span class="text-[10px] font-mono font-bold bg-indigo-50 text-indigo-700 px-1.5 py-0.2 rounded">Document dưới 5kg</span>
								</div>
								<div class="text-[11px] text-slate-500 mt-0.5"><?php echo esc_html__( 'Bì thư UPS Envelope, hợp đồng, hồ sơ CO/CQ (Cước rẻ hơn)', 'allship-ups-quote' ); ?></div>
							</div>
						</div>
						<div class="w-5 h-5 rounded-full border-2 border-slate-300 text-white flex items-center justify-center text-xs type-check opacity-0 shrink-0">
							<i class="ph-bold ph-check" aria-hidden="true"></i>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- ============================================================== -->
		<!-- SECTION 4: THÔNG TIN TUYẾN VẬN CHUYỂN                          -->
		<!-- ============================================================== -->
		<div class="mb-5 md:mb-7">
			<div class="text-xs md:text-[13px] font-extrabold text-navy-900 mb-2.5 flex items-center gap-2 uppercase tracking-wide">
				<i class="ph-bold ph-map-pin text-brand-red text-base" aria-hidden="true"></i>
				<span><?php echo esc_html__( '3. Thông tin tuyến vận chuyển', 'allship-ups-quote' ); ?></span>
			</div>
			<div class="grid grid-cols-1 md:grid-cols-2 gap-2.5 md:gap-3.5" id="routeFieldsGrid">
				<!-- Origin Province Combobox -->
				<div class="relative" id="originCombobox">
					<select id="originProvince" name="origin_province" class="hidden">
						<option value="TP. Hồ Chí Minh">TP. Hồ Chí Minh</option>
					</select>
					<label for="originProvinceDisplay" id="originProvinceLabel" class="block text-[11px] md:text-xs font-semibold text-slate-600 mb-1">
						<?php echo esc_html__( 'Nơi gửi (Việt Nam)', 'allship-ups-quote' ); ?>
						<span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal"><?php echo esc_html__( '(tuỳ chọn)', 'allship-ups-quote' ); ?></span>
					</label>
					<div class="relative cursor-pointer" onclick="toggleOriginDropdown(true)">
						<input type="text" id="originProvinceDisplay" class="w-full h-[42px] md:h-[46px] bg-[#F1F4F9] border-[1.5px] border-transparent rounded-xl px-4 pr-9 font-sans text-sm font-semibold text-navy-900 transition-all appearance-none focus:outline-none focus:bg-white focus:border-brand-red focus:shadow-[0_0_0_3px_rgba(206,32,39,0.12)] cursor-pointer placeholder:text-slate-400 placeholder:font-medium" placeholder="<?php echo esc_attr__( 'Chọn hoặc tìm Tỉnh/Thành...', 'allship-ups-quote' ); ?>" readonly aria-haspopup="listbox" aria-expanded="false" value="TP. Hồ Chí Minh">
						<i class="ph-bold ph-caret-down select-chevron absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-base" aria-hidden="true"></i>
					</div>
					<div class="combobox-dropdown custom-scrollbar absolute top-[calc(100%+6px)] left-0 right-0 bg-white border border-slate-200 rounded-xl shadow-xl max-h-[340px] overflow-y-auto z-[100]" id="originDropdown" role="listbox">
						<div class="p-2.5 sticky top-0 bg-white border-b border-slate-200 z-[2] relative">
							<i class="ph ph-magnifying-glass absolute left-5 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
							<input type="text" id="originSearchInput" class="w-full h-[38px] bg-slate-50 border border-slate-200 rounded-lg px-3 pl-[34px] text-[13px] font-sans focus:outline-none focus:border-brand-red" placeholder="<?php echo esc_attr__( 'Tìm: TP.HCM, Hà Nội, Đà Nẵng...', 'allship-ups-quote' ); ?>" oninput="filterOriginProvinces(this.value)" aria-label="<?php echo esc_attr__( 'Ô tìm kiếm tỉnh thành gửi', 'allship-ups-quote' ); ?>">
						</div>
						<div id="originOptionsList" class="p-1"></div>
					</div>
				</div>

				<!-- Destination Country Combobox -->
				<div class="relative" id="countryCombobox">
					<label for="destCountryDisplay" id="destCountryLabel" class="block text-[11px] md:text-xs font-semibold text-slate-600 mb-1">
						<?php echo esc_html__( 'Nước đến', 'allship-ups-quote' ); ?> <span class="text-brand-red font-bold">*</span>
					</label>
					<div class="relative cursor-pointer" onclick="toggleCountryDropdown(true)">
						<input type="text" id="destCountryDisplay" class="w-full h-[42px] md:h-[46px] bg-[#F1F4F9] border-[1.5px] border-transparent rounded-xl px-4 pr-9 font-sans text-sm font-semibold text-navy-900 transition-all appearance-none focus:outline-none focus:bg-white focus:border-brand-red focus:shadow-[0_0_0_3px_rgba(206,32,39,0.12)] cursor-pointer placeholder:text-slate-400 placeholder:font-medium" placeholder="<?php echo esc_attr__( 'Tìm quốc gia hoặc mã IATA...', 'allship-ups-quote' ); ?>" readonly aria-haspopup="listbox" aria-expanded="false">
						<i class="ph-bold ph-caret-down select-chevron absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-base" aria-hidden="true"></i>
					</div>
					<div class="combobox-dropdown custom-scrollbar absolute top-[calc(100%+6px)] left-0 right-0 bg-white border border-slate-200 rounded-xl shadow-xl max-h-[340px] overflow-y-auto z-[100]" id="countryDropdown" role="listbox">
						<div class="p-2.5 sticky top-0 bg-white border-b border-slate-200 z-[2] relative">
							<i class="ph ph-magnifying-glass absolute left-5 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
							<input type="text" id="countrySearchInput" class="w-full h-[38px] bg-slate-50 border border-slate-200 rounded-lg px-3 pl-[34px] text-[13px] font-sans focus:outline-none focus:border-brand-red" placeholder="<?php echo esc_attr__( 'Tìm: US, Nhật, Đức, Australia...', 'allship-ups-quote' ); ?>" oninput="filterCountries(this.value)" aria-label="<?php echo esc_attr__( 'Ô tìm kiếm quốc gia', 'allship-ups-quote' ); ?>">
						</div>
						<div id="countryOptionsList" class="p-1"></div>
					</div>
				</div>
			</div>

			<!-- Destination address details (DAS optional support) -->
			<div id="destAddressBlock" class="mt-3.5 pt-3.5 border-t border-dashed border-slate-200">
				<div class="flex items-center justify-between mb-2.5 flex-wrap gap-1">
					<div class="text-[13px] font-bold text-navy-900 flex items-center gap-1.5">
						<i class="ph-bold ph-navigation-arrow text-brand-red text-sm" aria-hidden="true"></i>
						<span id="destAddressBlockTitle"><?php echo esc_html__( 'Chi tiết địa chỉ đến tại nước ngoài', 'allship-ups-quote' ); ?></span>
						<span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal"><?php echo esc_html__( '(tuỳ chọn)', 'allship-ups-quote' ); ?></span>
					</div>
					<span class="text-[11px] text-slate-500"><?php echo esc_html__( 'Hỗ trợ tra phụ phí vùng sâu/xa (DAS)', 'allship-ups-quote' ); ?></span>
				</div>

				<!-- Grid: State + City -->
				<div class="grid grid-cols-1 md:grid-cols-2 gap-2.5 mb-2.5">
					<div class="relative" id="stateCombobox">
						<select id="destState" name="dest_state" class="hidden">
							<option value=""><?php echo esc_html__( '— Chọn Bang / Tỉnh —', 'allship-ups-quote' ); ?></option>
						</select>
						<label for="destStateDisplay" id="destStateLabel" class="block text-[11px] md:text-xs font-semibold text-slate-600 mb-1">
							<?php echo esc_html__( 'Bang / Tỉnh', 'allship-ups-quote' ); ?>
							<span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal"><?php echo esc_html__( '(tuỳ chọn)', 'allship-ups-quote' ); ?></span>
						</label>
						<div class="relative cursor-pointer" onclick="toggleStateDropdown(true)">
							<input type="text" id="destStateDisplay" class="w-full h-[42px] md:h-[46px] bg-[#F1F4F9] border-[1.5px] border-transparent rounded-xl px-4 pr-9 font-sans text-sm font-semibold text-navy-900 transition-all appearance-none focus:outline-none focus:bg-white focus:border-brand-red focus:shadow-[0_0_0_3px_rgba(206,32,39,0.12)] cursor-pointer placeholder:text-slate-400 placeholder:font-medium" placeholder="<?php echo esc_attr__( 'Chọn hoặc tìm Bang / Tỉnh...', 'allship-ups-quote' ); ?>" readonly aria-haspopup="listbox" aria-expanded="false" value="">
							<i class="ph-bold ph-caret-down select-chevron absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-base" aria-hidden="true"></i>
						</div>
						<div class="combobox-dropdown custom-scrollbar absolute top-[calc(100%+6px)] left-0 right-0 bg-white border border-slate-200 rounded-xl shadow-xl max-h-[340px] overflow-y-auto z-[100]" id="stateDropdown" role="listbox">
							<div class="p-2.5 sticky top-0 bg-white border-b border-slate-200 z-[2] relative">
								<i class="ph ph-magnifying-glass absolute left-5 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
								<input type="text" id="stateSearchInput" class="w-full h-[38px] bg-slate-50 border border-slate-200 rounded-lg px-3 pl-[34px] text-[13px] font-sans focus:outline-none focus:border-brand-red" placeholder="<?php echo esc_attr__( 'Tìm bang / tỉnh...', 'allship-ups-quote' ); ?>" oninput="filterStates(this.value)" aria-label="<?php echo esc_attr__( 'Ô tìm kiếm bang / tỉnh', 'allship-ups-quote' ); ?>">
							</div>
							<div id="stateOptionsList" class="p-1"></div>
						</div>
					</div>
					<div class="relative" id="cityCombobox">
						<select id="destCity" name="dest_city" class="hidden">
							<option value=""><?php echo esc_html__( '— Chọn Thành phố —', 'allship-ups-quote' ); ?></option>
						</select>
						<label for="destCityDisplay" id="destCityLabel" class="block text-[11px] md:text-xs font-semibold text-slate-600 mb-1">
							<?php echo esc_html__( 'Thành phố', 'allship-ups-quote' ); ?>
							<span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal"><?php echo esc_html__( '(tuỳ chọn)', 'allship-ups-quote' ); ?></span>
						</label>
						<div class="relative cursor-pointer" onclick="toggleCityDropdown(true)">
							<input type="text" id="destCityDisplay" class="w-full h-[42px] md:h-[46px] bg-[#F1F4F9] border-[1.5px] border-transparent rounded-xl px-4 pr-9 font-sans text-sm font-semibold text-navy-900 transition-all appearance-none focus:outline-none focus:bg-white focus:border-brand-red focus:shadow-[0_0_0_3px_rgba(206,32,39,0.12)] cursor-pointer placeholder:text-slate-400 placeholder:font-medium" placeholder="<?php echo esc_attr__( 'Chọn hoặc tìm Thành phố...', 'allship-ups-quote' ); ?>" readonly aria-haspopup="listbox" aria-expanded="false" value="">
							<i class="ph-bold ph-caret-down select-chevron absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-base" aria-hidden="true"></i>
						</div>
						<div class="combobox-dropdown custom-scrollbar absolute top-[calc(100%+6px)] left-0 right-0 bg-white border border-slate-200 rounded-xl shadow-xl max-h-[340px] overflow-y-auto z-[100]" id="cityDropdown" role="listbox">
							<div class="p-2.5 sticky top-0 bg-white border-b border-slate-200 z-[2] relative">
								<i class="ph ph-magnifying-glass absolute left-5 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
								<input type="text" id="citySearchInput" class="w-full h-[38px] bg-slate-50 border border-slate-200 rounded-lg px-3 pl-[34px] text-[13px] font-sans focus:outline-none focus:border-brand-red" placeholder="<?php echo esc_attr__( 'Tìm thành phố...', 'allship-ups-quote' ); ?>" oninput="filterCities(this.value)" aria-label="<?php echo esc_attr__( 'Ô tìm kiếm thành phố', 'allship-ups-quote' ); ?>">
							</div>
							<div id="cityOptionsList" class="p-1"></div>
						</div>
					</div>
				</div>

				<!-- Grid: Zipcode + Street Address -->
				<div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
					<div class="relative">
						<label for="destZipcode" id="destZipcodeLabel" class="block text-[11px] md:text-xs font-semibold text-slate-600 mb-1">
							<?php echo esc_html__( 'Mã bưu chính (Zipcode)', 'allship-ups-quote' ); ?>
							<span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal"><?php echo esc_html__( '(tuỳ chọn)', 'allship-ups-quote' ); ?></span>
						</label>
						<input type="text" id="destZipcode" name="dest_zipcode" class="w-full h-[42px] md:h-[46px] bg-[#F1F4F9] border-[1.5px] border-transparent rounded-xl px-4 font-sans text-sm font-semibold text-navy-900 transition-all focus:outline-none focus:bg-white focus:border-brand-red focus:shadow-[0_0_0_3px_rgba(206,32,39,0.12)] placeholder:text-slate-400 placeholder:font-medium" placeholder="<?php echo esc_attr__( 'VD: 90210, 10001...', 'allship-ups-quote' ); ?>">
					</div>
					<div class="relative">
						<label for="destAddress" id="destAddressLabel" class="block text-[11px] md:text-xs font-semibold text-slate-600 mb-1">
							<?php echo esc_html__( 'Địa chỉ cụ thể', 'allship-ups-quote' ); ?>
							<span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal"><?php echo esc_html__( '(nếu có)', 'allship-ups-quote' ); ?></span>
						</label>
						<input type="text" id="destAddress" name="dest_address" class="w-full h-[42px] md:h-[46px] bg-[#F1F4F9] border-[1.5px] border-transparent rounded-xl px-4 font-sans text-sm font-semibold text-navy-900 transition-all focus:outline-none focus:bg-white focus:border-brand-red focus:shadow-[0_0_0_3px_rgba(206,32,39,0.12)] placeholder:text-slate-400 placeholder:font-medium" placeholder="<?php echo esc_attr__( 'Số nhà, tên đường, căn hộ / tòa nhà...', 'allship-ups-quote' ); ?>">
					</div>
				</div>
			</div>
		</div>

		<!-- ============================================================== -->
		<!-- SECTION 5: THÔNG TIN KIỆN HÀNG (PIECES TABLE & VOLUMETRIC BAR) -->
		<!-- ============================================================== -->
		<div class="mb-5 md:mb-7">
			<div class="text-xs md:text-[13px] font-extrabold text-navy-900 mb-2.5 flex items-center gap-2 uppercase tracking-wide">
				<i class="ph-bold ph-cube text-brand-red text-base" aria-hidden="true"></i>
				<span><?php echo esc_html__( '4. Thông tin kiện hàng', 'allship-ups-quote' ); ?></span>
			</div>

			<!-- Desktop Header -->
			<div class="pieces-header-desktop grid grid-cols-[70px_1fr_1fr_1fr_1fr_44px] gap-2.5 mb-2 px-1" aria-hidden="true">
				<div class="text-[11px] font-extrabold uppercase tracking-wide text-slate-500"><?php echo esc_html__( 'SL', 'allship-ups-quote' ); ?></div>
				<div class="text-[11px] font-extrabold uppercase tracking-wide text-slate-500"><?php echo esc_html__( 'Nặng (kg)', 'allship-ups-quote' ); ?></div>
				<div class="text-[11px] font-extrabold uppercase tracking-wide text-slate-500"><?php echo esc_html__( 'Dài (cm)', 'allship-ups-quote' ); ?></div>
				<div class="text-[11px] font-extrabold uppercase tracking-wide text-slate-500"><?php echo esc_html__( 'Rộng (cm)', 'allship-ups-quote' ); ?></div>
				<div class="text-[11px] font-extrabold uppercase tracking-wide text-slate-500"><?php echo esc_html__( 'Cao (cm)', 'allship-ups-quote' ); ?></div>
				<div></div>
			</div>

			<!-- Mobile Mini Header -->
			<div class="pieces-mobile-header" aria-hidden="true">
				<span><?php echo esc_html__( 'SL', 'allship-ups-quote' ); ?></span>
				<span>kg</span>
				<span>D</span>
				<span>R</span>
				<span>C</span>
				<span></span>
			</div>

			<!-- Pieces Rows Container -->
			<div id="piecesContainer" aria-label="<?php echo esc_attr__( 'Danh sách kiện hàng', 'allship-ups-quote' ); ?>"></div>

			<!-- Add Piece Button -->
			<button type="button" class="w-full h-[38px] md:h-11 bg-blue-50 border border-dashed border-blue-200 rounded-xl text-blue-700 font-sans text-xs md:text-[13px] font-bold cursor-pointer transition-all flex items-center justify-center gap-2 mb-2 md:mb-3 hover:bg-blue-100 hover:border-blue-400 hover:text-blue-800" onclick="addNewPiece()">
				<i class="ph-bold ph-plus-circle" aria-hidden="true"></i>
				<span><?php echo esc_html__( 'Thêm kiện hàng (tối đa 20)', 'allship-ups-quote' ); ?></span>
			</button>

			<div class="text-[11px] text-slate-400 font-medium mb-3 italic">
				<i class="ph ph-info text-amber-500" aria-hidden="true"></i>
				<span><?php echo esc_html__( 'Nhập kích thước (Dài x Rộng x Cao) để tính chính xác trọng lượng thể tích.', 'allship-ups-quote' ); ?></span>
			</div>

			<!-- Volumetric Summary Bar -->
			<div class="bg-slate-50 border border-slate-200 rounded-xl p-2.5 md:p-3 lg:px-4.5 lg:py-3 mb-3 md:mb-5 grid grid-cols-2 md:flex items-center justify-between gap-2 md:gap-3" aria-label="<?php echo esc_attr__( 'Bảng tổng hợp trọng lượng', 'allship-ups-quote' ); ?>">
				<div class="flex items-center gap-1.5 md:gap-2 text-xs md:text-[13px]">
					<span class="text-slate-500 font-medium"><i class="ph ph-stack" aria-hidden="true"></i> <?php echo esc_html__( 'Tổng kiện:', 'allship-ups-quote' ); ?></span>
					<span class="font-extrabold text-navy-900" id="totalPiecesVal">1</span>
				</div>
				<div class="flex items-center gap-1.5 md:gap-2 text-xs md:text-[13px]">
					<span class="text-slate-500 font-medium"><i class="ph ph-scales" aria-hidden="true"></i> <?php echo esc_html__( 'Cân thực:', 'allship-ups-quote' ); ?></span>
					<span class="font-extrabold text-navy-900" id="totalActualWeightVal">0.00 kg</span>
				</div>
				<div class="flex items-center gap-1.5 md:gap-2 text-xs md:text-[13px]">
					<span class="text-slate-500 font-medium"><i class="ph ph-cube" aria-hidden="true"></i> <?php echo esc_html__( 'Thể tích:', 'allship-ups-quote' ); ?></span>
					<span class="font-extrabold text-navy-900" id="totalDimWeightVal">0.00 kg</span>
				</div>
				<div class="flex items-center gap-1.5 md:gap-2 text-xs md:text-[13px]">
					<span class="text-slate-500 font-medium"><i class="ph-bold ph-check-circle" aria-hidden="true"></i> <?php echo esc_html__( 'Tính cước:', 'allship-ups-quote' ); ?></span>
					<span class="font-extrabold text-brand-red text-sm md:text-[15px]" id="chargeableWeightVal">0.00 kg</span>
				</div>
			</div>

			<!-- Inline Error Message Box -->
			<div class="inline-error mt-2 p-2.5 md:p-3.5 bg-red-50 border border-red-200 rounded-lg text-xs md:text-[13px] text-red-600 font-semibold items-center gap-2" id="formError" role="alert" aria-live="assertive">
				<i class="ph-bold ph-warning-circle text-lg shrink-0" aria-hidden="true"></i>
				<span id="formErrorText"></span>
			</div>

			<!-- Calculation CTA Button -->
			<div id="ctaWrapper" class="mt-4">
				<button type="button" class="btn-calculate w-full h-12 md:h-[54px] bg-linear-to-br from-brand-red to-brand-red-hover border-none rounded-xl text-white font-sans text-sm md:text-base font-extrabold cursor-pointer flex items-center justify-center gap-2.5 transition-all duration-300 shadow-brand relative overflow-hidden hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(206,32,39,0.3)] active:translate-y-0 disabled:opacity-70 disabled:cursor-not-allowed" id="btnCalculate" onclick="performCalculation()">
					<span class="btn-text flex items-center gap-2"><i class="ph-bold ph-calculator" aria-hidden="true"></i> <?php echo esc_html__( 'Tính cước ngay', 'allship-ups-quote' ); ?></span>
					<div class="spinner" aria-hidden="true"></div>
				</button>
			</div>
		</div>

	</section>

	<!-- ============================================================== -->
	<!-- SECTION 6: KẾT QUẢ TÍNH CƯỚC (RESULT SECTION)                  -->
	<!-- ============================================================== -->
	<section class="result-section mt-5 md:mt-9" id="resultSection" aria-label="<?php echo esc_attr__( 'Kết quả so sánh cước vận chuyển', 'allship-ups-quote' ); ?>">

		<div class="text-center mb-3 md:mb-6">
			<div class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 text-[11px] md:text-xs font-bold px-3 py-0.5 rounded-full mb-1.5">
				<i class="ph-fill ph-check-circle" aria-hidden="true"></i> <?php echo esc_html__( 'Kết quả báo giá tự động', 'allship-ups-quote' ); ?>
			</div>
			<h2 class="text-lg md:text-[24px] font-extrabold text-navy-900 leading-tight mb-1">
				<?php echo esc_html__( 'So Sánh Bảng Giá Các Gói Dịch Vụ', 'allship-ups-quote' ); ?>
			</h2>
			<p class="text-[11px] md:text-xs text-slate-500 max-w-[560px] mx-auto leading-relaxed">
				<?php echo esc_html__( 'Bảng cước đã quy đổi theo mốc 0.5kg chuẩn UPS. Chọn gói phù hợp với thời gian & ngân sách của bạn.', 'allship-ups-quote' ); ?>
			</p>
		</div>

		<!-- Route Summary Card (Optimized for Mobile & Desktop) -->
		<div class="bg-white border border-slate-200/90 rounded-2xl p-3 sm:p-4 mb-3.5 md:mb-5 shadow-xs" id="routeRibbon">
			<!-- Top Row: Route Path & Weight Badge -->
			<div class="flex items-center justify-between gap-2.5 pb-2.5 border-b border-slate-100">
				<div class="flex items-center gap-2.5 min-w-0">
					<div class="w-8 h-8 rounded-xl bg-red-50 text-brand-red flex items-center justify-center text-base shrink-0 shadow-xs">
						<i class="ph-bold ph-airplane-tilt" id="ribbonFlightIcon" aria-hidden="true"></i>
					</div>
					<div class="min-w-0">
						<div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider flex items-center gap-1">
							<span id="ribbonDirLabel" class="text-blue-700 font-extrabold"><?php echo esc_html__( 'Xuất khẩu', 'allship-ups-quote' ); ?></span>
							<span class="text-slate-300">·</span>
							<span id="ribbonServiceLabel" class="text-slate-600 font-bold">UPS WXS</span>
						</div>
						<div class="text-xs sm:text-sm font-extrabold text-navy-900 truncate" id="ribbonRouteTitle">
							TP. Hồ Chí Minh ➔ Hoa Kỳ (US)
						</div>
					</div>
				</div>

				<!-- Weight & Manifesto Trigger -->
				<button type="button" onclick="openPiecesDetailModal()" title="<?php echo esc_attr__( 'Bấm xem bảng kê chi tiết kiện hàng', 'allship-ups-quote' ); ?>"
						class="shrink-0 text-right bg-brand-red/10 hover:bg-brand-red/15 px-2.5 py-1.5 rounded-xl border border-brand-red/20 cursor-pointer transition-all active:scale-95">
					<div class="text-[9px] uppercase font-extrabold text-brand-red/80 leading-none"><?php echo esc_html__( 'Tính cước', 'allship-ups-quote' ); ?></div>
					<div class="text-xs font-black text-brand-red leading-tight mt-0.5 flex items-center gap-1" id="ribbonWeightDisplay">
						<span>0.0 kg</span>
						<span class="text-[10px] font-bold opacity-80">(1 kiện)</span>
					</div>
				</button>
			</div>

			<!-- Bottom Row: 3 Structured Metadata Columns -->
			<div class="grid grid-cols-3 gap-1.5 text-center text-[11px] mt-2.5 bg-slate-50/80 p-2 rounded-xl border border-slate-100">
				<div class="truncate px-1">
					<span class="text-[9px] text-slate-400 block uppercase font-extrabold tracking-wider"><?php echo esc_html__( 'Nơi đến', 'allship-ups-quote' ); ?></span>
					<strong class="text-navy-900 font-bold truncate block mt-0.5 text-[11px]" id="ribbonDestShort">—</strong>
				</div>
				<div class="border-x border-slate-200 px-1">
					<span class="text-[9px] text-slate-400 block uppercase font-extrabold tracking-wider"><?php echo esc_html__( 'Khu vực', 'allship-ups-quote' ); ?></span>
					<strong class="text-navy-900 font-bold block mt-0.5 text-[11px]" id="ribbonZoneShort">Zone —</strong>
				</div>
				<div class="truncate px-1">
					<span class="text-[9px] text-slate-400 block uppercase font-extrabold tracking-wider"><?php echo esc_html__( 'Loại hàng', 'allship-ups-quote' ); ?></span>
					<strong class="text-navy-900 font-bold block mt-0.5 text-[11px]" id="ribbonTypeShort"><?php echo esc_html__( 'Hàng hóa', 'allship-ups-quote' ); ?></strong>
				</div>
			</div>
		</div>

		<!-- Mobile-Specific View Toggle -->
		<div class="md:hidden flex items-center justify-between bg-slate-100 p-1 rounded-xl mb-3 border border-slate-200/80">
			<span class="text-xs font-extrabold text-navy-900 px-2 flex items-center gap-1">
				<i class="ph-bold ph-sliders text-brand-red" aria-hidden="true"></i> <?php echo esc_html__( 'Chế độ xem:', 'allship-ups-quote' ); ?>
			</span>
			<div class="flex items-center gap-1">
				<button type="button" id="btnMobileViewCompact" onclick="setMobileResultView('compact')"
						class="px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-navy-900 shadow-xs cursor-pointer">
					<i class="ph-bold ph-lightning text-amber-500 mr-1" aria-hidden="true"></i> <?php echo esc_html__( 'So sánh gọn (1 màn hình)', 'allship-ups-quote' ); ?>
				</button>
				<button type="button" id="btnMobileViewCards" onclick="setMobileResultView('cards')"
						class="px-2.5 py-1 rounded-lg text-xs font-bold text-slate-500 hover:text-navy-900 cursor-pointer">
					<i class="ph-bold ph-cards text-blue-600 mr-1" aria-hidden="true"></i> <?php echo esc_html__( 'Thẻ chi tiết', 'allship-ups-quote' ); ?>
				</button>
			</div>
		</div>

		<!-- 1. Mobile Compact Comparison List -->
		<div class="md:hidden space-y-2 mb-4" id="mobileCompactListContainer"></div>

		<!-- 2. Desktop Cards Grid / Mobile Full Cards -->
		<div class="hidden md:grid md:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-5 mb-4 md:mb-7" id="serviceCardsGrid"></div>

		<!-- Important Notice Box -->
		<div class="bg-amber-50 border border-amber-100 rounded-xl p-3 md:p-4 lg:px-5 mb-4 md:mb-7 flex gap-2 md:gap-3">
			<i class="ph-bold ph-info text-amber-600 text-base md:text-xl shrink-0 mt-0.5" aria-hidden="true"></i>
			<div class="text-[11px] md:text-xs text-amber-900 leading-relaxed md:leading-[1.7]">
				<strong><?php echo esc_html__( 'Lưu ý quan trọng:', 'allship-ups-quote' ); ?></strong>
				<ul class="mt-1 pl-4.5 list-disc">
					<li><?php echo esc_html__( 'Giá tạm tính theo bảng cước Net Rates UPS Vietnam, hiệu lực từ 20/08/2026.', 'allship-ups-quote' ); ?></li>
					<li><?php echo esc_html__( 'Chưa bao gồm: VAT, Phụ phí nhiên liệu (FSC), Surge fee, Phí hải quan 10.000đ/AWB, phụ phí vùng xa.', 'allship-ups-quote' ); ?></li>
					<li><?php echo esc_html__( 'Trọng lượng tính cước: MAX(cân thực, cân thể tích) - làm tròn lên mốc 0.5kg, tính riêng từng kiện.', 'allship-ups-quote' ); ?></li>
				</ul>
			</div>
		</div>

		<!-- Action Toolbar -->
		<div class="flex items-center justify-center gap-2 md:gap-3 flex-wrap">
			<button type="button" class="h-[38px] md:h-[42px] px-3 md:px-4.5 rounded-lg font-sans text-xs md:text-[13px] font-bold inline-flex items-center gap-2 cursor-pointer transition-all bg-white border-[1.5px] border-slate-200 text-slate-700 hover:border-slate-400 hover:bg-slate-50" onclick="window.print()">
				<i class="ph-bold ph-printer" aria-hidden="true"></i> <?php echo esc_html__( 'In / Lưu PDF', 'allship-ups-quote' ); ?>
			</button>
			<button type="button" class="h-[38px] md:h-[42px] px-3 md:px-4.5 rounded-lg font-sans text-xs md:text-[13px] font-bold inline-flex items-center gap-2 cursor-pointer transition-all bg-white border-[1.5px] border-slate-200 text-slate-700 hover:border-slate-400 hover:bg-slate-50" onclick="scrollToForm()">
				<i class="ph-bold ph-arrow-counter-clockwise" aria-hidden="true"></i> <?php echo esc_html__( 'Tính lại', 'allship-ups-quote' ); ?>
			</button>
			<button type="button" class="h-[38px] md:h-[42px] px-3 md:px-4.5 rounded-lg font-sans text-xs md:text-[13px] font-bold inline-flex items-center gap-2 cursor-pointer transition-all bg-brand-red border-none text-white shadow-brand hover:bg-brand-red-hover" onclick="openBookingModal()">
				<i class="ph-bold ph-chat-centered-dots" aria-hidden="true"></i> <?php echo esc_html__( 'Liên hệ tư vấn', 'allship-ups-quote' ); ?>
			</button>
		</div>

	</section>

	<!-- ============================================================== -->
	<!-- SECTION 7: MOBILE STICKY BOTTOM ACTION BAR                     -->
	<!-- ============================================================== -->
	<div class="fixed bottom-0 left-0 right-0 bg-white/95 backdrop-blur-md border-t border-slate-200/80 p-3 z-40 shadow-xl items-center justify-between gap-3" id="mobileStickyActionBar" aria-label="<?php echo esc_attr__( 'Thanh thao tác nhanh đặt dịch vụ', 'allship-ups-quote' ); ?>">
		<div class="flex flex-col">
			<div class="text-[10px] uppercase font-extrabold tracking-wide text-slate-400" id="stickyChosenServiceName">UPS Expedited</div>
			<div class="text-base font-black text-brand-red leading-tight" id="stickyChosenServicePrice">0 đ</div>
		</div>
		<button type="button" onclick="openBookingModal()"
				class="px-5 py-2.5 rounded-xl bg-brand-red text-white text-xs font-extrabold flex items-center gap-1.5 shadow-brand cursor-pointer">
			<span><?php echo esc_html__( 'Đặt dịch vụ này', 'allship-ups-quote' ); ?></span>
			<i class="ph-bold ph-arrow-right" aria-hidden="true"></i>
		</button>
	</div>

	<!-- ============================================================== -->
	<!-- SECTION 8: MODALS                                              -->
	<!-- ============================================================== -->

	<!-- 8.1 Pieces Breakdown Manifesto Modal -->
	<div class="modal-backdrop fixed inset-0 bg-navy-900/60 backdrop-blur-sm z-[200] items-center justify-center p-3 sm:p-5" id="piecesDetailModal" role="dialog" aria-modal="true" aria-labelledby="piecesModalTitle">
		<div class="bg-white rounded-2xl max-w-2xl w-full p-4 sm:p-6 shadow-2xl relative max-h-[90vh] flex flex-col">
			<!-- Header -->
			<div class="flex items-start justify-between pb-3 border-b border-slate-100 mb-3 shrink-0">
				<div>
					<div class="text-base sm:text-lg font-extrabold text-navy-900 flex items-center gap-2" id="piecesModalTitle">
						<i class="ph-bold ph-cube text-brand-red text-xl" aria-hidden="true"></i>
						<span><?php echo esc_html__( 'Bảng Quy Đổi Trọng Lượng Kiện Hàng', 'allship-ups-quote' ); ?></span>
					</div>
					<p class="text-xs text-slate-500 mt-0.5">
						<?php echo esc_html__( 'Quy chuẩn UPS: Từng kiện lấy', 'allship-ups-quote' ); ?>
						<strong class="text-navy-900"><?php echo esc_html__( 'MAX(Cân thực, [D×R×C]/5500)', 'allship-ups-quote' ); ?></strong>
						<?php echo esc_html__( 'làm tròn lên 0.5kg', 'allship-ups-quote' ); ?>
					</p>
				</div>
				<button type="button" class="text-slate-400 hover:text-navy-900 text-xl cursor-pointer p-1 rounded-lg hover:bg-slate-100" onclick="closeModals()" aria-label="<?php echo esc_attr__( 'Đóng bảng kê kiện', 'allship-ups-quote' ); ?>">
					<i class="ph-bold ph-x" aria-hidden="true"></i>
				</button>
			</div>

			<!-- Scrollable Table -->
			<div class="overflow-y-auto flex-1 pr-1 custom-scrollbar mb-3 border border-slate-200 rounded-xl">
				<table class="w-full text-left text-xs border-collapse">
					<thead class="bg-slate-50 text-slate-600 font-extrabold text-[11px] uppercase tracking-wider sticky top-0 border-b border-slate-200">
						<tr>
							<th class="py-2.5 px-3"><?php echo esc_html__( 'Kiện', 'allship-ups-quote' ); ?></th>
							<th class="py-2.5 px-3 text-center"><?php echo esc_html__( 'SL', 'allship-ups-quote' ); ?></th>
							<th class="py-2.5 px-3 text-center"><?php echo esc_html__( 'Kích thước (DxRxC)', 'allship-ups-quote' ); ?></th>
							<th class="py-2.5 px-3 text-right"><?php echo esc_html__( 'Cân thực', 'allship-ups-quote' ); ?></th>
							<th class="py-2.5 px-3 text-right"><?php echo esc_html__( 'Thể tích (DIM)', 'allship-ups-quote' ); ?></th>
							<th class="py-2.5 px-3 text-right"><?php echo esc_html__( 'Tính cước/Kiện', 'allship-ups-quote' ); ?></th>
							<th class="py-2.5 px-3 text-right text-brand-red"><?php echo esc_html__( 'Tổng dòng', 'allship-ups-quote' ); ?></th>
						</tr>
					</thead>
					<tbody class="divide-y divide-slate-100 text-slate-700 font-medium" id="piecesModalTableBody"></tbody>
				</table>
			</div>

			<!-- KPI Footnote -->
			<div class="bg-slate-50 border border-slate-200 rounded-xl p-2.5 shrink-0 grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">
				<div class="p-2 bg-white rounded-lg border border-slate-100">
					<div class="text-[10px] uppercase font-bold text-slate-400"><?php echo esc_html__( 'Tổng số kiện', 'allship-ups-quote' ); ?></div>
					<div class="text-sm font-black text-navy-900" id="modalTotalQty">0</div>
				</div>
				<div class="p-2 bg-white rounded-lg border border-slate-100">
					<div class="text-[10px] uppercase font-bold text-slate-400"><?php echo esc_html__( 'Tổng cân thực', 'allship-ups-quote' ); ?></div>
					<div class="text-sm font-black text-navy-900" id="modalTotalActual">0.0 kg</div>
				</div>
				<div class="p-2 bg-white rounded-lg border border-slate-100">
					<div class="text-[10px] uppercase font-bold text-slate-400"><?php echo esc_html__( 'Tổng thể tích', 'allship-ups-quote' ); ?></div>
					<div class="text-sm font-black text-navy-900" id="modalTotalDim">0.0 kg</div>
				</div>
				<div class="p-2 bg-brand-red/10 rounded-lg border border-brand-red/20">
					<div class="text-[10px] uppercase font-black text-brand-red"><?php echo esc_html__( 'Cân tính cước', 'allship-ups-quote' ); ?></div>
					<div class="text-base font-black text-brand-red" id="modalTotalChargeable">0.0 kg</div>
				</div>
			</div>

			<!-- Footer Action -->
			<div class="mt-3 pt-2.5 border-t border-slate-100 flex items-center justify-between shrink-0">
				<div class="text-[11px] text-slate-500 italic">
					* <?php echo esc_html__( 'Hệ số DIM ', 'allship-ups-quote' ); ?><span id="modalDimDivisor"><?php echo esc_html( $dim_divisor ); ?></span><?php echo esc_html__( ' chuẩn quốc tế IATA / UPS Air.', 'allship-ups-quote' ); ?>
				</div>
				<button type="button" onclick="closeModals()" class="px-5 py-2 rounded-xl bg-navy-900 text-white text-xs font-bold hover:bg-navy-800 cursor-pointer">
					<?php echo esc_html__( 'Đóng', 'allship-ups-quote' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- 8.2 Booking Lead Modal -->
	<div class="modal-backdrop fixed inset-0 bg-navy-900/60 backdrop-blur-sm z-[200] items-center justify-center p-4 sm:p-5" id="bookingModal" role="dialog" aria-modal="true" aria-labelledby="bookingModalTitle">
		<div class="bg-white rounded-[20px] max-w-[560px] w-full p-5 md:p-7 shadow-xl relative max-h-[90vh] overflow-y-auto">
			<button type="button" class="absolute top-4 right-4 w-[34px] h-[34px] bg-slate-100 border-none rounded-full text-slate-600 text-lg cursor-pointer flex items-center justify-center transition-all hover:bg-slate-200 hover:text-navy-900" onclick="closeModals()" aria-label="<?php echo esc_attr__( 'Đóng cửa sổ đặt chỗ', 'allship-ups-quote' ); ?>">&times;</button>
			<h3 class="text-xl font-extrabold mb-1" id="bookingModalTitle"><?php echo esc_html__( 'Yêu Cầu Tư Vấn & Đặt Dịch Vụ', 'allship-ups-quote' ); ?></h3>
			<p class="text-[13px] text-slate-500 mb-3"><?php echo esc_html__( 'Chuyên viên Allship sẽ liên hệ trong 5 - 10 phút để xác nhận AWB.', 'allship-ups-quote' ); ?></p>

			<!-- Route summary in Modal -->
			<div id="modalRouteSummary" class="mb-4 p-3 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-700 leading-relaxed"></div>

			<!-- Inline Booking Notice Box -->
			<div id="bookingNotice" class="hidden mb-3 p-3 rounded-xl text-xs font-semibold" role="alert" aria-live="polite"></div>

			<!-- Booking Lead Form -->
			<form id="upsBookingForm" onsubmit="handleBookingSubmit(event)">
				<div class="flex flex-col gap-3 mb-4">
					<div>
						<label for="bookingName" class="text-xs font-bold text-slate-700 mb-1 block">
							<?php echo esc_html__( 'Họ và tên', 'allship-ups-quote' ); ?> <span class="text-brand-red font-bold">*</span>
						</label>
						<input type="text" id="bookingName" name="name" class="w-full h-[44px] bg-[#F1F4F9] border-[1.5px] border-transparent rounded-xl px-4 font-sans text-sm font-semibold text-navy-900 transition-all focus:outline-none focus:bg-white focus:border-brand-red focus:shadow-[0_0_0_3px_rgba(206,32,39,0.12)] placeholder:text-slate-400 placeholder:font-medium" required placeholder="<?php echo esc_attr__( 'Nguyễn Văn An', 'allship-ups-quote' ); ?>">
					</div>
					<div>
						<label for="bookingPhone" class="text-xs font-bold text-slate-700 mb-1 block">
							<?php echo esc_html__( 'Số điện thoại / Zalo', 'allship-ups-quote' ); ?> <span class="text-brand-red font-bold">*</span>
						</label>
						<input type="tel" id="bookingPhone" name="phone" class="w-full h-[44px] bg-[#F1F4F9] border-[1.5px] border-transparent rounded-xl px-4 font-sans text-sm font-semibold text-navy-900 transition-all focus:outline-none focus:bg-white focus:border-brand-red focus:shadow-[0_0_0_3px_rgba(206,32,39,0.12)] placeholder:text-slate-400 placeholder:font-medium" required placeholder="<?php echo esc_attr__( '090 123 4567', 'allship-ups-quote' ); ?>">
					</div>
					<div>
						<label for="bookingNotes" class="text-xs font-bold text-slate-700 mb-1 block">
							<?php echo esc_html__( 'Ghi chú về lô hàng', 'allship-ups-quote' ); ?>
						</label>
						<textarea id="bookingNotes" name="notes" class="w-full h-[65px] bg-[#F1F4F9] border-[1.5px] border-transparent rounded-xl px-4 py-2 font-sans text-sm font-semibold text-navy-900 transition-all focus:outline-none focus:bg-white focus:border-brand-red focus:shadow-[0_0_0_3px_rgba(206,32,39,0.12)] placeholder:text-slate-400 placeholder:font-medium resize-none" placeholder="<?php echo esc_attr__( 'Mô tả hàng hóa, yêu cầu đóng gỗ, lấy hàng tận nơi...', 'allship-ups-quote' ); ?>"></textarea>
					</div>
				</div>
				<button type="submit" id="btnSubmitBooking" class="btn-calculate w-full h-[46px] bg-linear-to-br from-brand-red to-brand-red-hover border-none rounded-xl text-white font-sans text-sm font-extrabold cursor-pointer flex items-center justify-center gap-2 transition-all shadow-brand relative overflow-hidden">
					<span class="btn-text flex items-center gap-2"><i class="ph-bold ph-paper-plane-tilt" aria-hidden="true"></i> <?php echo esc_html__( 'Gửi Yêu Cầu Đặt Chỗ', 'allship-ups-quote' ); ?></span>
					<div class="spinner" aria-hidden="true"></div>
				</button>
			</form>
		</div>
	</div>

</div>
