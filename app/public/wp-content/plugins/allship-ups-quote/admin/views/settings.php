<?php
/**
 * Admin View: Settings
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap allship-ups-admin-wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( $page_title ?? __( 'Cài đặt Quy tắc Tính cước & Phụ phí', 'allship-ups-quote' ) ); ?></h1>
	<hr class="wp-header-end">

	<div class="allship-card-container" style="margin-top: 15px;">
		<p class="description"><?php echo esc_html__( 'Cấu hình tham số tính cước: Dim Divisor, bước làm tròn cân nặng, VAT, FSC, Surge Fee và phí hải quan.', 'allship-ups-quote' ); ?></p>
	</div>
</div>
