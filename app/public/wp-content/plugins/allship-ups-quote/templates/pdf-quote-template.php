<?php
/**
 * PDF Quotation Template (A4 Portrait Single Page).
 *
 * Variables passed:
 * @var array $company
 * @var array $quote
 * @var array $customer
 * @var array $cargo
 * @var array $pricing
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DOMPDF_TEST_SUITE' ) ) {
	exit;
}

$c_name       = $company['name'] ?? 'CÔNG TY TNHH ALLSHIP LOGISTICS';
$c_tax        = $company['tax_id'] ?? '';
$c_address    = $company['address'] ?? '';
$c_hotline    = $company['hotline'] ?? '';
$c_email      = $company['email'] ?? '';
$c_website    = $company['website'] ?? 'https://allship.vn';
$c_logo        = $company['logo_url'] ?? '';
$c_logo_height = ! empty( $company['logo_height'] ) ? (int) $company['logo_height'] : 42;
if ( $c_logo_height < 25 || $c_logo_height > 80 ) {
	$c_logo_height = 42;
}

$c_logo_width_style = 'width: auto;';
if ( ! empty( $c_logo ) ) {
	$logo_dims = null;
	if ( strpos( $c_logo, 'data:image/' ) === 0 ) {
		$comma_pos = strpos( $c_logo, ',' );
		if ( false !== $comma_pos ) {
			$raw_data = base64_decode( substr( $c_logo, $comma_pos + 1 ) );
			if ( $raw_data && function_exists( 'getimagesizefromstring' ) ) {
				$logo_dims = @getimagesizefromstring( $raw_data );
			}
		}
	} elseif ( file_exists( $c_logo ) ) {
		$logo_dims = @getimagesize( $c_logo );
	}

	if ( ! empty( $logo_dims[0] ) && ! empty( $logo_dims[1] ) && $logo_dims[1] > 0 ) {
		$aspect_ratio = $logo_dims[0] / $logo_dims[1];
		$calc_width   = round( $c_logo_height * $aspect_ratio );
		// Constrain max width to 240px to fit left header column without pushing layout
		if ( $calc_width > 240 ) {
			$calc_width    = 240;
			$c_logo_height = round( 240 / $aspect_ratio );
		}
		$c_logo_width_style = 'width: ' . $calc_width . 'px;';
	}
}

$c_stamp      = $company['stamp_url'] ?? '';
$c_bank       = $company['bank_info'] ?? '';
$c_terms      = $company['terms_notes'] ?? '';

$q_ref        = $quote['quote_ref'] ?? 'AS-QUO-2026-0001';
$q_date       = $quote['created_at'] ?? gmdate( 'd/m/Y' );
$q_valid      = $quote['valid_until'] ?? '';
$q_service    = $quote['service_name'] ?? ( $quote['service_code'] ?? 'UPS Express' );
$q_dir        = ( ( $quote['direction'] ?? 'export' ) === 'import' ) ? 'Nhập khẩu (Import)' : 'Xuất khẩu (Export)';
$q_origin     = $quote['origin'] ?? 'Việt Nam';
$q_dest       = $quote['destination'] ?? 'Quốc tế';
$q_incoterm   = $quote['incoterms'] ?? 'DDU / Door-to-Door';
$q_transit    = $quote['transit_time'] ?? '1 - 3 ngày làm việc';

$cust_name    = $customer['name'] ?? 'Quý khách hàng';
$cust_company = $customer['company'] ?? '';
$cust_phone   = $customer['phone'] ?? '';
$cust_email   = $customer['email'] ?? '';

$total_price  = (float) ( $pricing['total_price_vnd'] ?? 0 );
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars( $q_ref, ENT_QUOTES, 'UTF-8' ); ?> - Báo Giá Allship Logistics</title>
<style>
	@page {
		margin: 14mm 14mm 12mm 18mm;
		size: a4 portrait;
	}
	* {
		box-sizing: border-box;
	}
	body, h1, h2, h3, h4, p, table, td, th {
		margin: 0;
		padding: 0;
	}
	body {
		font-family: 'DejaVu Sans', sans-serif;
		font-size: 8.5pt;
		line-height: 1.32;
		color: #1e293b;
		background: #ffffff;
	}

	/* Logo chìm watermark nằm chính giữa trang */
	.pdf-watermark {
		position: fixed;
		top: 36%;
		left: 5%;
		width: 90%;
		text-align: center;
		opacity: 0.055;
		z-index: -1000;
		transform: rotate(-25deg);
		transform-origin: 50% 50%;
	}
	.pdf-watermark img {
		width: 440px;
		max-width: 90%;
		height: auto;
		display: inline-block;
	}
	.pdf-watermark-text {
		font-size: 32pt;
		font-weight: bold;
		color: #0f172a;
		letter-spacing: 2px;
		text-transform: uppercase;
	}

	.header-table {
		width: 100%;
		border-collapse: collapse;
		margin-bottom: 6px;
		border-bottom: 2px solid #ce2027;
		padding-bottom: 4px;
	}
	.header-table td {
		vertical-align: top;
	}
	.company-title {
		font-size: 9.5pt;
		font-weight: bold;
		color: #ce2027;
		text-transform: uppercase;
		margin-bottom: 2px;
		letter-spacing: 0.2px;
	}
	.company-info {
		font-size: 7.2pt;
		color: #475569;
		line-height: 1.28;
	}
	.doc-title {
		font-size: 12.5pt;
		font-weight: bold;
		color: #0f172a;
		text-align: right;
		text-transform: uppercase;
		letter-spacing: 0.5px;
	}
	.doc-meta {
		font-size: 7.8pt;
		text-align: right;
		color: #334155;
		margin-top: 2px;
	}
	.doc-ref-badge {
		display: inline-block;
		font-family: monospace;
		font-weight: bold;
		font-size: 9pt;
		color: #ce2027;
		background: #fee2e2;
		padding: 2px 5px;
		border-radius: 3px;
		margin-top: 1px;
	}

	.section-heading {
		font-size: 8pt;
		font-weight: bold;
		color: #0f172a;
		background: #f1f5f9;
		padding: 2.5px 5px;
		border-left: 3px solid #ce2027;
		margin-top: 5px;
		margin-bottom: 3px;
		text-transform: uppercase;
	}

	.info-grid-table {
		width: 100%;
		border-collapse: collapse;
		margin-bottom: 3px;
	}
	.info-grid-table td {
		padding: 1.5px 3px;
		font-size: 7.8pt;
		vertical-align: top;
	}
	.label-cell {
		width: 16%;
		color: #64748b;
		font-weight: normal;
	}
	.val-cell {
		width: 34%;
		color: #0f172a;
		font-weight: 500;
	}

	.data-table {
		width: 100%;
		border-collapse: collapse;
		margin-top: 2px;
		margin-bottom: 4px;
		font-size: 7.8pt;
	}
	.data-table th {
		background: #0f2240;
		color: #ffffff;
		padding: 3.5px 5px;
		font-weight: bold;
		text-align: left;
		font-size: 7.2pt;
		border: 1px solid #0f2240;
	}
	.data-table td {
		padding: 2.5px 5px;
		border: 1px solid #cbd5e1;
		font-size: 7.5pt;
	}
	.data-table tr:nth-child(even) td {
		background: #f8fafc;
	}
	.text-center { text-align: center; }
	.text-right { text-align: right; }
	.text-bold { font-weight: bold; }
	.text-red { color: #ce2027; }

	.summary-box {
		width: 100%;
		border-collapse: collapse;
		margin-top: 3px;
		margin-bottom: 4px;
	}
	.summary-box td {
		padding: 1.5px 5px;
		font-size: 7.8pt;
	}
	.total-row td {
		background: #fef2f2;
		border-top: 1.5px solid #ce2027;
		border-bottom: 1.5px solid #ce2027;
		padding: 4px 5px;
	}
	.total-label {
		font-size: 8.5pt;
		font-weight: bold;
		color: #991b1b;
		text-transform: uppercase;
	}
	.total-amount {
		font-size: 11pt;
		font-weight: bold;
		color: #ce2027;
		text-align: right;
	}

	.footer-box {
		width: 100%;
		border-collapse: collapse;
		margin-top: 3px;
		font-size: 7.2pt;
	}
	.footer-box td {
		vertical-align: top;
		padding: 1.5px 3px;
	}
	.terms-text {
		color: #475569;
		font-size: 6.8pt;
		line-height: 1.25;
		white-space: pre-line;
	}
	.bank-box {
		background: #f8fafc;
		border: 1px dashed #cbd5e1;
		padding: 3px 5px;
		border-radius: 4px;
		font-size: 6.8pt;
		color: #334155;
		white-space: pre-line;
		line-height: 1.25;
	}

	.signature-table {
		width: 100%;
		border-collapse: collapse;
		margin-top: 8px;
	}
	.signature-table td {
		width: 50%;
		text-align: center;
		vertical-align: top;
		font-size: 7.8pt;
	}
	.sign-title {
		font-weight: bold;
		text-transform: uppercase;
		color: #0f172a;
		margin-bottom: 2px;
	}
	.sign-sub {
		font-size: 6.8pt;
		color: #64748b;
		font-style: italic;
	}
	.stamp-space {
		height: 40px;
	}
</style>
</head>
<body>

<!-- WATERMARK CHÌM CHÍNH GIỮA TRANG -->
<?php if ( ! empty( $c_logo ) ) : ?>
	<div class="pdf-watermark">
		<img src="<?php echo ( strpos( $c_logo, 'data:' ) === 0 ) ? $c_logo : ( function_exists( 'esc_url' ) ? esc_url( $c_logo ) : htmlspecialchars( $c_logo, ENT_QUOTES, 'UTF-8' ) ); ?>" alt="Watermark">
	</div>
<?php else : ?>
	<div class="pdf-watermark">
		<div class="pdf-watermark-text"><?php echo htmlspecialchars( $c_name, ENT_QUOTES, 'UTF-8' ); ?></div>
	</div>
<?php endif; ?>

<!-- HEADER -->
<table class="header-table">
	<tr>
		<td style="width: 55%;">
			<?php if ( ! empty( $c_logo ) ) : ?>
				<div class="company-logo-wrap" style="margin-bottom: 3px;">
					<img src="<?php echo ( strpos( $c_logo, 'data:' ) === 0 ) ? $c_logo : ( function_exists( 'esc_url' ) ? esc_url( $c_logo ) : htmlspecialchars( $c_logo, ENT_QUOTES, 'UTF-8' ) ); ?>" alt="<?php echo htmlspecialchars( $c_name, ENT_QUOTES, 'UTF-8' ); ?>" class="company-logo" style="height: <?php echo (int) $c_logo_height; ?>px; <?php echo $c_logo_width_style; ?> display: block; border: 0;">
				</div>
			<?php endif; ?>
			<div class="company-title"><?php echo htmlspecialchars( $c_name, ENT_QUOTES, 'UTF-8' ); ?></div>
			<div class="company-info">
				<?php if ( $c_tax ) : ?>
					<strong>MST:</strong> <?php echo htmlspecialchars( $c_tax, ENT_QUOTES, 'UTF-8' ); ?> | 
				<?php endif; ?>
				<strong>Hotline:</strong> <?php echo htmlspecialchars( $c_hotline, ENT_QUOTES, 'UTF-8' ); ?><br>
				<strong>Địa chỉ:</strong> <?php echo htmlspecialchars( $c_address, ENT_QUOTES, 'UTF-8' ); ?><br>
				<strong>Email:</strong> <?php echo htmlspecialchars( $c_email, ENT_QUOTES, 'UTF-8' ); ?> | 
				<strong>Website:</strong> <?php echo htmlspecialchars( $c_website, ENT_QUOTES, 'UTF-8' ); ?>
			</div>
		</td>
		<td style="width: 45%;">
			<div class="doc-title">BÁO GIÁ CƯỚC VẬN CHUYỂN</div>
			<div class="doc-meta">
				Số báo giá: <span class="doc-ref-badge"><?php echo htmlspecialchars( $q_ref, ENT_QUOTES, 'UTF-8' ); ?></span><br>
				Ngày lập: <strong><?php echo htmlspecialchars( $q_date, ENT_QUOTES, 'UTF-8' ); ?></strong><br>
				<?php if ( $q_valid ) : ?>
					Hiệu lực đến: <strong style="color:#ce2027;"><?php echo htmlspecialchars( $q_valid, ENT_QUOTES, 'UTF-8' ); ?></strong>
				<?php endif; ?>
			</div>
		</td>
	</tr>
</table>

<!-- PHẦN 1: THÔNG TIN KHÁCH HÀNG & TUYẾN VẬN CHUYỂN -->
<div class="section-heading">PHẦN 1: THÔNG TIN KHÁCH HÀNG & TUYẾN ĐƯỜNG VẬN CHUYỂN</div>
<table class="info-grid-table">
	<tr>
		<td class="label-cell">Khách hàng:</td>
		<td class="val-cell"><strong><?php echo htmlspecialchars( $cust_company ? $cust_company : $cust_name, ENT_QUOTES, 'UTF-8' ); ?></strong></td>
		<td class="label-cell">Chiều gửi:</td>
		<td class="val-cell"><strong><?php echo htmlspecialchars( $q_dir, ENT_QUOTES, 'UTF-8' ); ?></strong></td>
	</tr>
	<tr>
		<td class="label-cell">Người liên hệ:</td>
		<td class="val-cell"><?php echo htmlspecialchars( $cust_name, ENT_QUOTES, 'UTF-8' ); ?></td>
		<td class="label-cell">Điểm đi (POL):</td>
		<td class="val-cell"><?php echo htmlspecialchars( $q_origin, ENT_QUOTES, 'UTF-8' ); ?></td>
	</tr>
	<tr>
		<td class="label-cell">Điện thoại / Zalo:</td>
		<td class="val-cell"><?php echo htmlspecialchars( $cust_phone ?: '—', ENT_QUOTES, 'UTF-8' ); ?></td>
		<td class="label-cell">Điểm đến (POD):</td>
		<td class="val-cell"><strong><?php echo htmlspecialchars( $q_dest, ENT_QUOTES, 'UTF-8' ); ?></strong></td>
	</tr>
	<tr>
		<td class="label-cell">Hộp thư Email:</td>
		<td class="val-cell"><?php echo htmlspecialchars( $cust_email ?: '—', ENT_QUOTES, 'UTF-8' ); ?></td>
		<td class="label-cell">Dịch vụ UPS:</td>
		<td class="val-cell"><strong class="text-red"><?php echo htmlspecialchars( $q_service, ENT_QUOTES, 'UTF-8' ); ?></strong> (<?php echo htmlspecialchars( $q_transit, ENT_QUOTES, 'UTF-8' ); ?>)</td>
	</tr>
	<tr>
		<td class="label-cell">Điều kiện giao nhận:</td>
		<td class="val-cell"><?php echo htmlspecialchars( $q_incoterm, ENT_QUOTES, 'UTF-8' ); ?></td>
		<td class="label-cell">Hình thức:</td>
		<td class="val-cell">Chuyển phát nhanh đường hàng không (Express Air)</td>
	</tr>
</table>

<!-- PHẦN 2: CHI TIẾT KIỆN HÀNG & TRỌNG LƯỢNG TÍNH CƯỚC -->
<div class="section-heading">PHẦN 2: CHI TIẾT LÔ HÀNG (CARGO DETAILS)</div>
<table class="data-table">
	<thead>
		<tr>
			<th style="width: 6%; text-align: center;">STT</th>
			<th style="width: 14%; text-align: center;">Số kiện (Pcs)</th>
			<th style="width: 25%; text-align: center;">Kích thước (D &times; R &times; C cm)</th>
			<th style="width: 18%; text-align: right;">Cân thực tế (Gross kg)</th>
			<th style="width: 18%; text-align: right;">Cân quy đổi (Vol kg)</th>
			<th style="width: 19%; text-align: right;">Cân tính cước (Chg kg)</th>
		</tr>
	</thead>
	<tbody>
		<?php
		$pieces_list = $cargo['pieces'] ?? [];
		if ( ! empty( $pieces_list ) && is_array( $pieces_list ) ) :
			$stt = 1;
			foreach ( $pieces_list as $p ) :
				$qty      = (int) ( $p['qty'] ?? ( $p['quantity'] ?? 1 ) );
				$len      = (float) ( $p['len'] ?? ( $p['length_cm'] ?? 0 ) );
				$wid      = (float) ( $p['wid'] ?? ( $p['width_cm'] ?? 0 ) );
				$hei      = (float) ( $p['hei'] ?? ( $p['height_cm'] ?? 0 ) );
				$unit_act = (float) ( $p['weight'] ?? ( $p['actual_weight_kg'] ?? 0 ) );
				$act_w    = $unit_act * $qty;
				$divisor  = (float) ( $company['dim_divisor'] ?? 5500 );
				$unit_dim = ( $len > 0 && $wid > 0 && $hei > 0 ) ? ( ( $len * $wid * $hei ) / $divisor ) : 0.0;
				$dim_w    = $unit_dim * $qty;
				$unit_max = ceil( max( $unit_act, $unit_dim ) * 2 ) / 2;
				$chg_w    = isset( $p['chargeable_weight_kg'] ) ? (float) $p['chargeable_weight_kg'] : ( $unit_max * $qty );
				?>
				<tr>
					<td class="text-center"><?php echo $stt++; ?></td>
					<td class="text-center"><?php echo $qty; ?> kiện</td>
					<td class="text-center"><?php echo $len; ?> &times; <?php echo $wid; ?> &times; <?php echo $hei; ?> cm</td>
					<td class="text-right"><?php echo number_format( $act_w, 2 ); ?> kg</td>
					<td class="text-right"><?php echo number_format( $dim_w, 2 ); ?> kg</td>
					<td class="text-right text-bold"><?php echo number_format( $chg_w, 2 ); ?> kg</td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr>
				<td class="text-center">1</td>
				<td class="text-center"><?php echo (int) ( $cargo['total_pieces'] ?? 1 ); ?> kiện</td>
				<td class="text-center">Kiện chuẩn tiêu chuẩn</td>
				<td class="text-right"><?php echo number_format( (float) ( $cargo['gross_weight_kg'] ?? 0 ), 2 ); ?> kg</td>
				<td class="text-right"><?php echo number_format( (float) ( $cargo['dim_weight_kg'] ?? 0 ), 2 ); ?> kg</td>
				<td class="text-right text-bold"><?php echo number_format( (float) ( $cargo['chargeable_weight_kg'] ?? 0 ), 2 ); ?> kg</td>
			</tr>
		<?php endif; ?>
		<tr style="background: #f1f5f9; font-weight: bold;">
			<td colspan="3" class="text-right" style="padding-right: 10px;">TỔNG CỘNG TRỌNG LƯỢNG:</td>
			<td class="text-right"><?php echo number_format( (float) ( $cargo['gross_weight_kg'] ?? 0 ), 2 ); ?> kg</td>
			<td class="text-right"><?php echo number_format( (float) ( $cargo['dim_weight_kg'] ?? 0 ), 2 ); ?> kg</td>
			<td class="text-right text-red" style="font-size: 8.5pt;"><?php echo number_format( (float) ( $cargo['chargeable_weight_kg'] ?? 0 ), 2 ); ?> kg</td>
		</tr>
	</tbody>
</table>
<div style="font-size: 6.8pt; color: #64748b; font-style: italic; margin-top: -2px;">
	* Quy chuẩn tính trọng lượng thể tích theo Hiệp hội Vận tải Hàng không Quốc tế (IATA): (Dài &times; Rộng &times; Cao cm) / 5000. Trọng lượng tính cước là giá trị lớn hơn giữa cân nặng thực tế và cân nặng thể tích.
</div>

<!-- PHẦN 3: BẢNG GIÁ VÀ CHI TIẾT CƯỚC PHÍ -->
<div class="section-heading">PHẦN 3: CHI TIẾT CƯỚC PHÍ & PHỤ PHÍ (CHARGES BREAKDOWN)</div>
<table class="summary-box">
	<tr>
		<td style="width: 70%; border-bottom: 1px dashed #e2e8f0;">1. Cước vận chuyển chính (Base Freight):</td>
		<td style="width: 30%; border-bottom: 1px dashed #e2e8f0;" class="text-right text-bold">
			<?php echo number_format( (float) ( $pricing['base_price_vnd'] ?? 0 ), 0, ',', '.' ); ?> VND
		</td>
	</tr>
	<?php if ( ! empty( $pricing['fsc_amount_vnd'] ) && (float) $pricing['fsc_amount_vnd'] > 0 ) : ?>
	<tr>
		<td style="border-bottom: 1px dashed #e2e8f0;">2. Phụ phí nhiên liệu (Fuel Surcharge - FSC <?php echo (float) ( $pricing['fsc_percent'] ?? 0 ); ?>%):</td>
		<td style="border-bottom: 1px dashed #e2e8f0;" class="text-right">
			<?php echo number_format( (float) $pricing['fsc_amount_vnd'], 0, ',', '.' ); ?> VND
		</td>
	</tr>
	<?php endif; ?>
	<?php if ( ! empty( $pricing['surge_amount_vnd'] ) && (float) $pricing['surge_amount_vnd'] > 0 ) : ?>
	<tr>
		<td style="border-bottom: 1px dashed #e2e8f0;">3. Phụ phí mùa cao điểm (Peak / Demand Surcharge):</td>
		<td style="border-bottom: 1px dashed #e2e8f0;" class="text-right">
			<?php echo number_format( (float) $pricing['surge_amount_vnd'], 0, ',', '.' ); ?> VND
		</td>
	</tr>
	<?php endif; ?>
	<?php if ( ! empty( $pricing['customs_fee_vnd'] ) && (float) $pricing['customs_fee_vnd'] > 0 ) : ?>
	<tr>
		<td style="border-bottom: 1px dashed #e2e8f0;">4. Phí thủ tục thông quan hải quan (Customs Clearance Fee):</td>
		<td style="border-bottom: 1px dashed #e2e8f0;" class="text-right">
			<?php echo number_format( (float) $pricing['customs_fee_vnd'], 0, ',', '.' ); ?> VND
		</td>
	</tr>
	<?php endif; ?>
	<?php if ( ! empty( $pricing['vat_amount_vnd'] ) && (float) $pricing['vat_amount_vnd'] > 0 ) : ?>
	<tr>
		<td style="border-bottom: 1px dashed #e2e8f0;">5. Thuế Giá trị gia tăng (VAT <?php echo (float) ( $pricing['vat_percent'] ?? 8 ); ?>%):</td>
		<td style="border-bottom: 1px dashed #e2e8f0;" class="text-right">
			<?php echo number_format( (float) $pricing['vat_amount_vnd'], 0, ',', '.' ); ?> VND
		</td>
	</tr>
	<?php endif; ?>
	<tr class="total-row">
		<td class="total-label">TỔNG CỘNG CƯỚC TẠM TÍNH (TOTAL ESTIMATED):</td>
		<td class="total-amount"><?php echo number_format( $total_price, 0, ',', '.' ); ?> VND</td>
	</tr>
</table>

<!-- PHẦN 4: ĐIỀU KHOẢN VÀ THÔNG TIN THANH TOÁN -->
<div class="section-heading">PHẦN 4: ĐIỀU KHOẢN & THÔNG TIN THANH TOÁN</div>
<table class="footer-box">
	<tr>
		<td style="width: 55%;">
			<strong>Lưu ý và Điều khoản dịch vụ:</strong>
			<div class="terms-text"><?php echo htmlspecialchars( $c_terms, ENT_QUOTES, 'UTF-8' ); ?></div>
		</td>
		<td style="width: 45%;">
			<strong>Thông tin chuyển khoản thanh toán:</strong>
			<div class="bank-box"><?php echo htmlspecialchars( $c_bank, ENT_QUOTES, 'UTF-8' ); ?></div>
		</td>
	</tr>
</table>

<!-- SIGNATURE -->
<table class="signature-table">
	<tr>
		<td>
			<div class="sign-title">ĐẠI DIỆN KHÁCH HÀNG</div>
			<div class="sign-sub">(Ký và ghi rõ họ tên)</div>
			<div class="stamp-space"></div>
			<div><strong><?php echo htmlspecialchars( $cust_name, ENT_QUOTES, 'UTF-8' ); ?></strong></div>
		</td>
		<td>
			<div class="sign-title">ĐẠI DIỆN ALLSHIP LOGISTICS</div>
			<div class="sign-sub">(Ký điện tử & Đóng dấu xác nhận)</div>
			<div class="stamp-space">
				<?php if ( $c_stamp ) : ?>
					<img src="<?php echo ( strpos( $c_stamp, 'data:' ) === 0 ) ? $c_stamp : ( function_exists( 'esc_url' ) ? esc_url( $c_stamp ) : htmlspecialchars( $c_stamp, ENT_QUOTES, 'UTF-8' ) ); ?>" style="max-height: 48px;" alt="Stamp">
				<?php endif; ?>
			</div>
			<div style="color: #ce2027; font-weight: bold;"><?php echo htmlspecialchars( $c_name, ENT_QUOTES, 'UTF-8' ); ?></div>
		</td>
	</tr>
</table>

</body>
</html>
