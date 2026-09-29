<?php
/**
 * Admin View: Settings Page (WP Settings API)
 *
 * Implements Step 5.7 of Phase 5:
 * - 3 Sections: Weight, Fees, Advanced.
 * - Nonce validation and strict capability manage_options.
 * - Flat sharp-corner design system aligned with admin.css.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'allship-ups-quote' ), 403 );
}

// Ensure Admin Settings controller is loaded and initialized
if ( ! class_exists( 'Allship_UPS_Admin_Settings' ) && file_exists( dirname( __DIR__ ) . '/class-admin-settings.php' ) ) {
	require_once dirname( __DIR__ ) . '/class-admin-settings.php';
}

$settings_controller = class_exists( 'Allship_UPS_Admin_Settings' ) ? new Allship_UPS_Admin_Settings() : null;

// Ensure settings sections and fields are registered
if ( $settings_controller ) {
	$settings_controller->register_settings();
}

$is_updated = isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'];
?>

<div class="wrap allship-ups-admin-wrap">
	<div class="as-page-header">
		<h1 class="wp-heading-inline as-page-header__title"><?php echo esc_html( $page_title ?? __( 'Cài đặt Quy tắc Tính cước & Phụ phí', 'allship-ups-quote' ) ); ?></h1>
	</div>
	<hr class="wp-header-end">

	<?php if ( $is_updated ) : ?>
		<div class="notice notice-success is-dismissible" style="margin-top: 15px;">
			<p><strong><?php esc_html_e( 'Cài đặt đã được lưu thành công.', 'allship-ups-quote' ); ?></strong> <?php esc_html_e( 'Bộ nhớ tạm (cache) cước phí đã được làm mới.', 'allship-ups-quote' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="as-settings-container">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="allshipUpsSettingsForm">
			<input type="hidden" name="action" value="<?php echo esc_attr( Allship_UPS_Admin_Settings::NONCE_ACTION ); ?>">
			<?php wp_nonce_field( Allship_UPS_Admin_Settings::NONCE_ACTION, Allship_UPS_Admin_Settings::NONCE_NAME ); ?>

			<?php do_settings_sections( Allship_UPS_Admin_Settings::SETTINGS_PAGE ); ?>

			<div class="as-form-actions">
				<button type="submit" class="as-btn as-btn--primary as-btn--lg button button-primary button-hero" id="submitSettingsBtn">
					<span class="dashicons dashicons-saved"></span>
					<span><?php esc_html_e( 'Lưu Cài đặt', 'allship-ups-quote' ); ?></span>
				</button>
			</div>
		</form>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
	// Sync toggle checkboxes with their corresponding number inputs for clarity
	const feeToggles = [
		{ toggleId: 'allship_ups_include_vat', inputId: 'allship_ups_vat_percent' },
		{ toggleId: 'allship_ups_include_fsc', inputId: 'allship_ups_fsc_percent' },
		{ toggleId: 'allship_ups_include_surge', inputId: 'allship_ups_surge_percent' },
		{ toggleId: 'allship_ups_include_customs_fee', inputId: 'allship_ups_customs_fee_vnd' }
	];

	feeToggles.forEach(function(item) {
		const toggleEl = document.getElementById(item.toggleId);
		const inputEl = document.getElementById(item.inputId);
		if (!toggleEl || !inputEl) return;

		function updateState() {
			if (toggleEl.checked) {
				inputEl.removeAttribute('disabled');
				inputEl.style.opacity = '1';
			} else {
				inputEl.style.opacity = '0.55';
			}
		}

		toggleEl.addEventListener('change', updateState);
		updateState();
	});
});
</script>
