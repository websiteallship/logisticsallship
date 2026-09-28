<?php
/**
 * Admin View: Quote Logs
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap allship-ups-admin-wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( $page_title ?? __( 'Nhật ký Báo giá & Khách hàng tiềm năng (Leads)', 'allship-ups-quote' ) ); ?></h1>
	<hr class="wp-header-end">

	<div class="allship-card-container" style="margin-top: 15px;">
		<p class="description"><?php echo esc_html__( 'Theo dõi lịch sử tra cứu cước và danh sách thông tin khách hàng đặt dịch vụ.', 'allship-ups-quote' ); ?></p>
	</div>
</div>
