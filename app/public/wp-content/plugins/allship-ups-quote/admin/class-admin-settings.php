<?php
/**
 * Admin Settings controller & Settings API handler.
 *
 * Implements Step 5.7 of Phase 5:
 * - WP Settings API registration (sections: Weight, Fees, Advanced).
 * - Strict capability check: 'manage_options'.
 * - Nonce validation and input sanitization callbacks.
 * - Integration with Allship_UPS_Settings_Manager (wp_ups_settings table).
 * - Cache & calculation transient invalidation upon save.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Admin_Settings {

	/**
	 * Nonce action for saving settings.
	 */
	const NONCE_ACTION = 'allship_ups_save_settings';

	/**
	 * Nonce field name.
	 */
	const NONCE_NAME = 'allship_ups_settings_nonce';

	/**
	 * Settings group slug.
	 */
	const SETTINGS_GROUP = 'allship-ups-settings-group';

	/**
	 * Settings page slug.
	 */
	const SETTINGS_PAGE = 'allship-ups-settings';

	/**
	 * Required user capability.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Settings manager instance.
	 *
	 * @var Allship_UPS_Settings_Manager|null
	 */
	private $settings_manager;

	/**
	 * Database instance.
	 *
	 * @var wpdb|null
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Settings_Manager|null $settings_manager Optional.
	 * @param wpdb|null                         $wpdb             Optional.
	 */
	public function __construct( $settings_manager = null, $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;

		if ( null === $settings_manager ) {
			if ( ! class_exists( 'Allship_UPS_Settings_Manager' ) && file_exists( dirname( __DIR__ ) . '/includes/class-settings-manager.php' ) ) {
				require_once dirname( __DIR__ ) . '/includes/class-settings-manager.php';
			}
			$this->settings_manager = class_exists( 'Allship_UPS_Settings_Manager' ) ? new Allship_UPS_Settings_Manager( $this->wpdb ) : null;
		} else {
			$this->settings_manager = $settings_manager;
		}
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_' . self::NONCE_ACTION, [ $this, 'handle_save_settings' ] );
		add_action( 'wp_ajax_' . self::NONCE_ACTION, [ $this, 'ajax_save_settings' ] );
	}

	/**
	 * Register Settings API sections and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		// Section 1: Weight Calculation
		add_settings_section(
			'allship_ups_section_weight',
			__( '1. Quy tắc Tính Cân Nặng (Weight Calculation)', 'allship-ups-quote' ),
			[ $this, 'render_section_weight_description' ],
			self::SETTINGS_PAGE
		);

		add_settings_field(
			'allship_ups_dim_divisor',
			__( 'Hệ số chia thể tích (Dim Divisor)', 'allship-ups-quote' ),
			[ $this, 'render_field_dim_divisor' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_weight'
		);

		add_settings_field(
			'allship_ups_rounding_step_kg',
			__( 'Bước làm tròn trọng lượng (kg)', 'allship-ups-quote' ),
			[ $this, 'render_field_rounding_step_kg' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_weight'
		);

		// Section 2: Fees & Surcharges
		add_settings_section(
			'allship_ups_section_fees',
			__( '2. Cấu hình Phụ Phí & Thuế (Fees & Taxes)', 'allship-ups-quote' ),
			[ $this, 'render_section_fees_description' ],
			self::SETTINGS_PAGE
		);

		add_settings_field(
			'allship_ups_vat',
			__( 'Thuế Giá Trị Gia Tăng (VAT)', 'allship-ups-quote' ),
			[ $this, 'render_field_vat' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_fees'
		);

		add_settings_field(
			'allship_ups_fsc',
			__( 'Phụ phí nhiên liệu (FSC)', 'allship-ups-quote' ),
			[ $this, 'render_field_fsc' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_fees'
		);

		add_settings_field(
			'allship_ups_surge',
			__( 'Phụ phí mùa cao điểm (Surge Fee)', 'allship-ups-quote' ),
			[ $this, 'render_field_surge' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_fees'
		);

		add_settings_field(
			'allship_ups_customs_fee',
			__( 'Phí thủ tục hải quan (Customs Fee)', 'allship-ups-quote' ),
			[ $this, 'render_field_customs_fee' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_fees'
		);

		// Section 3: Advanced
		add_settings_section(
			'allship_ups_section_advanced',
			__( '3. Cài đặt Nâng Cao & Hệ Thống (Advanced)', 'allship-ups-quote' ),
			[ $this, 'render_section_advanced_description' ],
			self::SETTINGS_PAGE
		);

		add_settings_field(
			'allship_ups_delete_data_on_uninstall',
			__( 'Xóa dữ liệu khi gỡ cài đặt', 'allship-ups-quote' ),
			[ $this, 'render_field_delete_data_on_uninstall' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_advanced'
		);

		add_settings_field(
			'allship_ups_fluentform_id',
			__( 'FluentForm Form ID liên kết', 'allship-ups-quote' ),
			[ $this, 'render_field_fluentform_id' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_advanced'
		);

		// Section 4: Company Profile & PDF Quotation
		add_settings_section(
			'allship_ups_section_company_pdf',
			__( '4. Thông Tin Doanh Nghiệp & Mẫu Báo Giá PDF (Company Profile & PDF Quote)', 'allship-ups-quote' ),
			[ $this, 'render_section_company_pdf_description' ],
			self::SETTINGS_PAGE
		);

		add_settings_field(
			'allship_ups_company_name',
			__( 'Tên Doanh Nghiệp / Công ty', 'allship-ups-quote' ),
			[ $this, 'render_field_company_name' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);

		add_settings_field(
			'allship_ups_company_tax_id',
			__( 'Mã Số Thuế (Tax ID)', 'allship-ups-quote' ),
			[ $this, 'render_field_company_tax_id' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);

		add_settings_field(
			'allship_ups_company_address',
			__( 'Địa chỉ Trụ sở', 'allship-ups-quote' ),
			[ $this, 'render_field_company_address' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);

		add_settings_field(
			'allship_ups_company_hotline',
			__( 'Hotline / Số điện thoại', 'allship-ups-quote' ),
			[ $this, 'render_field_company_hotline' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);

		add_settings_field(
			'allship_ups_company_email',
			__( 'Email liên hệ Báo giá', 'allship-ups-quote' ),
			[ $this, 'render_field_company_email' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);

		add_settings_field(
			'allship_ups_company_website',
			__( 'Website Doanh Nghiệp', 'allship-ups-quote' ),
			[ $this, 'render_field_company_website' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);

		add_settings_field(
			'allship_ups_company_logo_url',
			__( 'Logo Doanh Nghiệp (In trên PDF)', 'allship-ups-quote' ),
			[ $this, 'render_field_company_logo_url' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);

		add_settings_field(
			'allship_ups_company_logo_height',
			__( 'Kích thước Logo trên PDF (Scale Height)', 'allship-ups-quote' ),
			[ $this, 'render_field_company_logo_height' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);

		add_settings_field(
			'allship_ups_quote_validity_days',
			__( 'Thời hạn hiệu lực Báo giá (Ngày)', 'allship-ups-quote' ),
			[ $this, 'render_field_quote_validity_days' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);

		add_settings_field(
			'allship_ups_quote_bank_info',
			__( 'Thông tin Tài khoản Thanh toán', 'allship-ups-quote' ),
			[ $this, 'render_field_quote_bank_info' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);

		add_settings_field(
			'allship_ups_quote_terms_notes',
			__( 'Điều khoản & Lưu ý mẫu Báo giá', 'allship-ups-quote' ),
			[ $this, 'render_field_quote_terms_notes' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);

		add_settings_field(
			'allship_ups_quote_digital_stamp_url',
			__( 'URL Ảnh Con Dấu / Chữ Ký Điện Tử', 'allship-ups-quote' ),
			[ $this, 'render_field_quote_digital_stamp_url' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);

		add_settings_field(
			'allship_ups_quote_admin_notify_emails',
			__( 'Email nhận thông báo Lead xuất PDF', 'allship-ups-quote' ),
			[ $this, 'render_field_quote_admin_notify_emails' ],
			self::SETTINGS_PAGE,
			'allship_ups_section_company_pdf'
		);
	}

	/**
	 * Section 1 description.
	 */
	public function render_section_weight_description() {
		echo '<p class="description">' . esc_html__( 'Cấu hình công thức quy đổi kích thước thể tích sang trọng lượng tính cước và bước làm tròn cân nặng.', 'allship-ups-quote' ) . '</p>';
	}

	/**
	 * Section 2 description.
	 */
	public function render_section_fees_description() {
		echo '<p class="description">' . esc_html__( 'Bật hoặc tắt việc tính gộp các khoản phụ phí và thuế vào kết quả báo giá cuối cùng. Khi tắt, bảng giá chỉ hiển thị cước gốc vận chuyển.', 'allship-ups-quote' ) . '</p>';
	}

	/**
	 * Section 3 description.
	 */
	public function render_section_advanced_description() {
		echo '<p class="description">' . esc_html__( 'Quản trị cơ sở dữ liệu và dọn dẹp hệ thống khi gỡ bỏ plugin.', 'allship-ups-quote' ) . '</p>';
	}

	/**
	 * Render Dim Divisor input.
	 */
	public function render_field_dim_divisor() {
		$value = (int) $this->get_setting( 'dim_divisor', 5500 );
		?>
		<div class="as-field-inline">
			<input type="number" name="dim_divisor" id="allship_ups_dim_divisor" value="<?php echo esc_attr( $value ); ?>" min="1" step="1" class="regular-text" style="width: 140px;">
			<span class="as-unit-label">cm³ / kg</span>
		</div>
		<p class="description">
			<?php esc_html_e( 'Công thức: (Dài × Rộng × Cao cm) / Dim Divisor. Tiêu chuẩn quốc tế mặc định của UPS là 5500.', 'allship-ups-quote' ); ?>
		</p>
		<?php
	}

	/**
	 * Render Rounding Step input.
	 */
	public function render_field_rounding_step_kg() {
		$value = (float) $this->get_setting( 'rounding_step_kg', 0.5 );
		?>
		<div class="as-field-inline">
			<input type="number" name="rounding_step_kg" id="allship_ups_rounding_step_kg" value="<?php echo esc_attr( $value ); ?>" min="0.01" step="0.01" class="regular-text" style="width: 140px;">
			<span class="as-unit-label">kg</span>
		</div>
		<p class="description">
			<?php esc_html_e( 'Trọng lượng tính cước luôn được làm tròn lên (Round Up) theo bội số này. Mặc định: 0.5 kg (hoặc 1.0 kg cho hàng Freight).', 'allship-ups-quote' ); ?>
		</p>
		<?php
	}

	/**
	 * Render VAT field (Checkbox toggle + percent).
	 */
	public function render_field_vat() {
		$enabled = (bool) $this->get_setting( 'include_vat', false );
		$percent = (float) $this->get_setting( 'vat_percent', 0.0 );
		?>
		<div class="as-setting-group-box">
			<label class="as-checkbox-label">
				<input type="checkbox" name="include_vat" id="allship_ups_include_vat" value="1" <?php checked( $enabled, true ); ?>>
				<strong><?php esc_html_e( 'Tính thuế VAT vào báo giá', 'allship-ups-quote' ); ?></strong>
			</label>
			<div class="as-field-inline" style="margin-top: 8px;">
				<label for="allship_ups_vat_percent"><?php esc_html_e( 'Tỷ lệ VAT:', 'allship-ups-quote' ); ?></label>
				<input type="number" name="vat_percent" id="allship_ups_vat_percent" value="<?php echo esc_attr( $percent ); ?>" min="0" max="100" step="0.1" class="small-text" style="width: 90px;">
				<span class="as-unit-label">%</span>
			</div>
			<p class="description">
				<?php esc_html_e( 'Áp dụng trên tổng cước gốc và các phụ phí khác (thường là 8% hoặc 10%).', 'allship-ups-quote' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render FSC field (Checkbox toggle + percent).
	 */
	public function render_field_fsc() {
		$enabled = (bool) $this->get_setting( 'include_fsc', false );
		$percent = (float) $this->get_setting( 'fsc_percent', 0.0 );
		?>
		<div class="as-setting-group-box">
			<label class="as-checkbox-label">
				<input type="checkbox" name="include_fsc" id="allship_ups_include_fsc" value="1" <?php checked( $enabled, true ); ?>>
				<strong><?php esc_html_e( 'Tính phụ phí xăng dầu / nhiên liệu (FSC)', 'allship-ups-quote' ); ?></strong>
			</label>
			<div class="as-field-inline" style="margin-top: 8px;">
				<label for="allship_ups_fsc_percent"><?php esc_html_e( 'Tỷ lệ FSC:', 'allship-ups-quote' ); ?></label>
				<input type="number" name="fsc_percent" id="allship_ups_fsc_percent" value="<?php echo esc_attr( $percent ); ?>" min="0" max="100" step="0.1" class="small-text" style="width: 90px;">
				<span class="as-unit-label">%</span>
			</div>
			<p class="description">
				<?php esc_html_e( 'Phần trăm phụ phí nhiên liệu do UPS công bố hàng tuần hoặc hàng tháng.', 'allship-ups-quote' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render Surge fee field (Checkbox toggle + percent).
	 */
	public function render_field_surge() {
		$enabled = (bool) $this->get_setting( 'include_surge', false );
		$percent = (float) $this->get_setting( 'surge_percent', 0.0 );
		?>
		<div class="as-setting-group-box">
			<label class="as-checkbox-label">
				<input type="checkbox" name="include_surge" id="allship_ups_include_surge" value="1" <?php checked( $enabled, true ); ?>>
				<strong><?php esc_html_e( 'Tính phụ phí cao điểm (Surge Fee)', 'allship-ups-quote' ); ?></strong>
			</label>
			<div class="as-field-inline" style="margin-top: 8px;">
				<label for="allship_ups_surge_percent"><?php esc_html_e( 'Tỷ lệ Surge Fee:', 'allship-ups-quote' ); ?></label>
				<input type="number" name="surge_percent" id="allship_ups_surge_percent" value="<?php echo esc_attr( $percent ); ?>" min="0" max="100" step="0.1" class="small-text" style="width: 90px;">
				<span class="as-unit-label">%</span>
			</div>
			<p class="description">
				<?php esc_html_e( 'Phần trăm áp dụng vào các mùa lễ tết hoặc cao điểm vận chuyển quốc tế.', 'allship-ups-quote' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render Customs fee field (Checkbox toggle + VND amount).
	 */
	public function render_field_customs_fee() {
		$enabled = (bool) $this->get_setting( 'include_customs_fee', false );
		$fee_vnd = (int) $this->get_setting( 'customs_fee_vnd', 10000 );
		?>
		<div class="as-setting-group-box">
			<label class="as-checkbox-label">
				<input type="checkbox" name="include_customs_fee" id="allship_ups_include_customs_fee" value="1" <?php checked( $enabled, true ); ?>>
				<strong><?php esc_html_e( 'Tính phí thủ tục hải quan', 'allship-ups-quote' ); ?></strong>
			</label>
			<div class="as-field-inline" style="margin-top: 8px;">
				<label for="allship_ups_customs_fee_vnd"><?php esc_html_e( 'Mức phí:', 'allship-ups-quote' ); ?></label>
				<input type="number" name="customs_fee_vnd" id="allship_ups_customs_fee_vnd" value="<?php echo esc_attr( $fee_vnd ); ?>" min="0" step="1000" class="regular-text" style="width: 140px;">
				<span class="as-unit-label">VNĐ / lô</span>
			</div>
			<p class="description">
				<?php esc_html_e( 'Mức phí cố định tính thêm cho mỗi lần gửi hàng có thủ tục hải quan (Mặc định: 10,000 VNĐ).', 'allship-ups-quote' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render Delete Data on Uninstall checkbox.
	 */
	public function render_field_delete_data_on_uninstall() {
		$enabled = (bool) $this->get_setting( 'delete_data_on_uninstall', false );
		?>
		<div class="as-setting-group-box as-setting-group-box--danger">
			<label class="as-checkbox-label">
				<input type="checkbox" name="delete_data_on_uninstall" id="allship_ups_delete_data_on_uninstall" value="1" <?php checked( $enabled, true ); ?>>
				<strong style="color: var(--as-danger, #dc2626);"><?php esc_html_e( 'Xóa sạch toàn bộ dữ liệu bảng giá, zone maps, lịch sử báo giá khi gỡ plugin', 'allship-ups-quote' ); ?></strong>
			</label>
			<p class="description" style="color: #64748b; margin-top: 6px;">
				<?php esc_html_e( 'CẢNH BÁO: Khi tùy chọn này được bật, việc xóa plugin Allship UPS Quote từ menu Plugins sẽ xóa vĩnh viễn toàn bộ 6 bảng DB (ups_rate_cards, ups_rates, ups_zone_maps, ups_countries, ups_settings, ups_quote_logs). Dữ liệu sẽ KHÔNG thể khôi phục.', 'allship-ups-quote' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render FluentForm Form ID setting.
	 */
	public function render_field_fluentform_id() {
		$form_id = (int) $this->get_setting( 'fluentform_id', 0 );
		if ( ! $form_id && function_exists( 'get_option' ) ) {
			$form_id = (int) get_option( 'allship_ups_fluentform_id', 0 );
		}
		?>
		<div class="as-setting-group-box">
			<input type="number" name="fluentform_id" id="allship_ups_fluentform_id" value="<?php echo esc_attr( $form_id ? $form_id : '' ); ?>" min="0" placeholder="<?php esc_attr_e( 'Tự động nhận diện', 'allship-ups-quote' ); ?>" class="small-text" style="width: 160px;">
			<p class="description">
				<?php esc_html_e( 'Nhập Form ID của FluentForm để đẩy dữ liệu lead khi khách hàng đặt chỗ qua Booking Modal. Để trống nếu muốn hệ thống tự động tìm form UPS đã tạo.', 'allship-ups-quote' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Section 4 description: Company Profile & PDF Quotation.
	 */
	public function render_section_company_pdf_description() {
		echo '<p class="description">' . esc_html__( 'Cấu hình thông tin pháp nhân doanh nghiệp, thông tin thanh toán và các điều khoản hiển thị trên tệp PDF Báo giá xuất cho khách hàng.', 'allship-ups-quote' ) . '</p>';
	}

	/**
	 * Render Company Name input.
	 */
	public function render_field_company_name() {
		$value = (string) $this->get_setting( 'company_name', 'CÔNG TY TNHH ALLSHIP LOGISTICS' );
		?>
		<input type="text" name="company_name" id="allship_ups_company_name" value="<?php echo esc_attr( $value ); ?>" class="regular-text" style="width: 100%; max-width: 450px;">
		<p class="description"><?php esc_html_e( 'Tên doanh nghiệp xuất hiện trên Header của tệp Báo giá PDF.', 'allship-ups-quote' ); ?></p>
		<?php
	}

	/**
	 * Render Company Tax ID input.
	 */
	public function render_field_company_tax_id() {
		$value = (string) $this->get_setting( 'company_tax_id', '0317320092' );
		?>
		<input type="text" name="company_tax_id" id="allship_ups_company_tax_id" value="<?php echo esc_attr( $value ); ?>" class="regular-text" style="width: 200px;">
		<p class="description"><?php esc_html_e( 'Mã số thuế doanh nghiệp in trên Header báo giá.', 'allship-ups-quote' ); ?></p>
		<?php
	}

	/**
	 * Render Company Address input.
	 */
	public function render_field_company_address() {
		$value = (string) $this->get_setting( 'company_address', 'Tầng 3, Tòa nhà Allship, TP. Hồ Chí Minh, Việt Nam' );
		?>
		<textarea name="company_address" id="allship_ups_company_address" rows="2" class="large-text" style="max-width: 500px;"><?php echo esc_html( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Địa chỉ văn phòng / trụ sở chính của công ty.', 'allship-ups-quote' ); ?></p>
		<?php
	}

	/**
	 * Render Company Hotline input.
	 */
	public function render_field_company_hotline() {
		$value = (string) $this->get_setting( 'company_hotline', '1900 633 833 / 0903 000 888' );
		?>
		<input type="text" name="company_hotline" id="allship_ups_company_hotline" value="<?php echo esc_attr( $value ); ?>" class="regular-text" style="width: 280px;">
		<p class="description"><?php esc_html_e( 'Hotline tư vấn hỗ trợ hiển thị trên Báo giá PDF.', 'allship-ups-quote' ); ?></p>
		<?php
	}

	/**
	 * Render Company Email input.
	 */
	public function render_field_company_email() {
		$value = (string) $this->get_setting( 'company_email', 'quote@allship.vn' );
		?>
		<input type="email" name="company_email" id="allship_ups_company_email" value="<?php echo esc_attr( $value ); ?>" class="regular-text" style="width: 280px;">
		<p class="description"><?php esc_html_e( 'Email phòng cước tiếp nhận phản hồi từ khách hàng.', 'allship-ups-quote' ); ?></p>
		<?php
	}

	/**
	 * Render Company Website input.
	 */
	public function render_field_company_website() {
		$value = (string) $this->get_setting( 'company_website', 'https://allship.vn' );
		?>
		<input type="url" name="company_website" id="allship_ups_company_website" value="<?php echo esc_attr( $value ); ?>" class="regular-text" style="width: 350px;">
		<p class="description"><?php esc_html_e( 'Địa chỉ website chính thức của doanh nghiệp.', 'allship-ups-quote' ); ?></p>
		<?php
	}

	/**
	 * Render Company Logo input with WP Media Library picker.
	 */
	public function render_field_company_logo_url() {
		$value       = (string) $this->get_setting( 'company_logo_url', '' );
		$logo_id     = (int) $this->get_setting( 'company_logo_id', 0 );
		$logo_height = (int) $this->get_setting( 'company_logo_height', 42 );
		$has_logo    = ! empty( $value );
		?>
		<div class="as-field-logo-wrap" style="max-width: 520px;">
			<div id="allship_logo_preview_container" style="background: #f8fafc; border: 1px dashed #cbd5e1; padding: 10px 14px; margin-bottom: 10px; min-height: 52px; display: flex; align-items: center; justify-content: flex-start; gap: 16px;">
				<img id="allship_ups_logo_preview" src="<?php echo esc_url( $value ); ?>" alt="<?php esc_attr_e( 'Logo preview', 'allship-ups-quote' ); ?>" style="<?php echo $has_logo ? '' : 'display:none; '; ?>height: <?php echo esc_attr( $logo_height ); ?>px; width: auto; max-width: 250px; object-fit: contain; border: 1px solid #e2e8f0; background: #ffffff; padding: 4px;">
				<div id="allship_logo_placeholder" style="<?php echo $has_logo ? 'display:none;' : ''; ?>color: #64748b; font-size: 13px;">
					<span class="dashicons dashicons-format-image" style="vertical-align: middle; margin-right: 4px; color: #94a3b8;"></span>
					<em><?php esc_html_e( 'Chưa chọn logo (Sẽ hiển thị tên công ty dạng chữ)', 'allship-ups-quote' ); ?></em>
				</div>
			</div>

			<div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
				<button type="button" class="button button-secondary" id="allship_btn_select_logo">
					<span class="dashicons dashicons-upload" style="margin-top: 3px; font-size: 17px; margin-right: 2px;"></span>
					<span id="allship_btn_select_logo_text"><?php echo $has_logo ? esc_html__( 'Thay đổi Logo', 'allship-ups-quote' ) : esc_html__( 'Chọn Logo từ Thư viện', 'allship-ups-quote' ); ?></span>
				</button>
				<button type="button" class="button button-link-delete" id="allship_btn_remove_logo" style="<?php echo $has_logo ? '' : 'display:none;'; ?> color: #b32d2e; text-decoration: none;">
					<span class="dashicons dashicons-trash" style="margin-top: 3px; font-size: 17px;"></span>
					<?php esc_html_e( 'Xóa Logo', 'allship-ups-quote' ); ?>
				</button>
			</div>

			<input type="hidden" name="company_logo_url" id="allship_ups_company_logo_url" value="<?php echo esc_attr( $value ); ?>">
			<input type="hidden" name="company_logo_id" id="allship_ups_company_logo_id" value="<?php echo esc_attr( $logo_id ); ?>">

			<p class="description"><?php esc_html_e( 'Chọn file ảnh logo từ thư viện Media WordPress (PNG trong suốt hoặc JPG sắc nét). Hệ thống tự động nhúng Data URI để in ấn siêu tốc và không bị vỡ tỉ lệ.', 'allship-ups-quote' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render Company Logo Height scale control.
	 */
	public function render_field_company_logo_height() {
		$value = (int) $this->get_setting( 'company_logo_height', 42 );
		if ( $value < 25 || $value > 80 ) {
			$value = 42;
		}
		?>
		<div class="as-field-inline" style="display: flex; align-items: center; gap: 14px; max-width: 520px;">
			<input type="range" name="company_logo_height" id="allship_ups_company_logo_height" min="25" max="80" step="1" value="<?php echo esc_attr( $value ); ?>" style="width: 220px; cursor: pointer;">
			<div style="font-weight: 600; color: #1e293b; min-width: 55px;">
				<span id="allship_logo_height_display"><?php echo esc_attr( $value ); ?></span>px
			</div>
			<button type="button" class="button button-small" id="allship_btn_reset_logo_height" title="<?php esc_attr_e( 'Đặt về mặc định (42px)', 'allship-ups-quote' ); ?>"><?php esc_html_e( 'Mặc định (42px)', 'allship-ups-quote' ); ?></button>
		</div>
		<p class="description"><?php esc_html_e( 'Điều chỉnh chiều cao hiển thị của Logo trên PDF (25px - 80px, chuẩn đẹp: 42px). Chiều rộng sẽ tự động scale theo đúng tỉ lệ gốc (aspect-ratio), đảm bảo logo không bao giờ bị méo hoặc vỡ hình.', 'allship-ups-quote' ); ?></p>
		<?php
	}

	/**
	 * Render Quote Validity Days input.
	 */
	public function render_field_quote_validity_days() {
		$value = (int) $this->get_setting( 'quote_validity_days', 14 );
		?>
		<div class="as-field-inline">
			<input type="number" name="quote_validity_days" id="allship_ups_quote_validity_days" value="<?php echo esc_attr( $value ); ?>" min="1" max="365" class="small-text" style="width: 100px;">
			<span class="as-unit-label"><?php esc_html_e( 'ngày', 'allship-ups-quote' ); ?></span>
		</div>
		<p class="description"><?php esc_html_e( 'Số ngày hiệu lực kể từ ngày khách hàng xuất báo giá (Ngày hết hạn = Ngày tạo + Số ngày này).', 'allship-ups-quote' ); ?></p>
		<?php
	}

	/**
	 * Render Quote Bank Info textarea.
	 */
	public function render_field_quote_bank_info() {
		$default = "Ngân hàng: Techcombank - Chi nhánh TP.HCM\nSố tài khoản: 19038888999999\nChủ tài khoản: CONG TY TNHH ALLSHIP LOGISTICS";
		$value   = (string) $this->get_setting( 'quote_bank_info', $default );
		?>
		<textarea name="quote_bank_info" id="allship_ups_quote_bank_info" rows="3" class="large-text" style="max-width: 550px; font-family: monospace; font-size: 12px;"><?php echo esc_html( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Thông tin số tài khoản ngân hàng để khách thanh toán chuyển khoản.', 'allship-ups-quote' ); ?></p>
		<?php
	}

	/**
	 * Render Quote Terms Notes textarea.
	 */
	public function render_field_quote_terms_notes() {
		$default = "1. Báo giá chưa bao gồm thuế nhập khẩu và thuế giá trị gia tăng tại nước đến (nếu có).\n2. Hàng hóa phải tuân thủ nghiêm ngặt quy định an toàn bay quốc tế của IATA và UPS.\n3. Thời gian giao hàng dự kiến tính theo ngày làm việc (không bao gồm Thứ 7, Chủ Nhật và ngày lễ).";
		$value   = (string) $this->get_setting( 'quote_terms_notes', $default );
		?>
		<textarea name="quote_terms_notes" id="allship_ups_quote_terms_notes" rows="4" class="large-text" style="max-width: 550px; font-size: 12px; line-height: 1.5;"><?php echo esc_html( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Các điều khoản thương mại, điều kiện đóng gói và lưu ý in tại Phần 4 của Báo giá PDF.', 'allship-ups-quote' ); ?></p>
		<?php
	}

	/**
	 * Render Quote Digital Stamp URL input.
	 */
	public function render_field_quote_digital_stamp_url() {
		$value = (string) $this->get_setting( 'quote_digital_stamp_url', '' );
		?>
		<input type="url" name="quote_digital_stamp_url" id="allship_ups_quote_digital_stamp_url" value="<?php echo esc_attr( $value ); ?>" class="large-text" style="max-width: 450px;" placeholder="https://example.com/stamp.png">
		<p class="description"><?php esc_html_e( 'URL hình ảnh con dấu đỏ hoặc chữ ký điện tử PNG trong suốt in dưới mục Đại diện Allship Logistics.', 'allship-ups-quote' ); ?></p>
		<?php
	}

	/**
	 * Render Quote Admin Notify Emails input.
	 */
	public function render_field_quote_admin_notify_emails() {
		$value = (string) $this->get_setting( 'quote_admin_notify_emails', 'sales@allship.vn' );
		?>
		<input type="text" name="quote_admin_notify_emails" id="allship_ups_quote_admin_notify_emails" value="<?php echo esc_attr( $value ); ?>" class="large-text" style="max-width: 450px;">
		<p class="description"><?php esc_html_e( 'Danh sách email nhận thông báo khi có khách tải file báo giá (phân tách nhiều email bằng dấu phẩy).', 'allship-ups-quote' ); ?></p>
		<?php
	}

	/**
	 * Sanitize and validate all settings input.
	 *
	 * @param array $input Raw input array from POST.
	 * @return array<string, mixed> Sanitized key-value pairs.
	 */
	public function sanitize_settings( array $input ) {
		$sanitized = [];

		// 1. dim_divisor: Positive integer, default 5500
		$dim = isset( $input['dim_divisor'] ) ? (int) $input['dim_divisor'] : 5500;
		$sanitized['dim_divisor'] = ( $dim > 0 ) ? $dim : 5500;

		// 2. rounding_step_kg: Positive float >= 0.01, default 0.5
		$step = isset( $input['rounding_step_kg'] ) ? (float) $input['rounding_step_kg'] : 0.5;
		$sanitized['rounding_step_kg'] = ( $step >= 0.01 ) ? $step : 0.5;

		// 3. include_vat: Boolean
		$sanitized['include_vat'] = ! empty( $input['include_vat'] );

		// 4. vat_percent: Float 0.0 to 100.0
		$vat = isset( $input['vat_percent'] ) ? (float) $input['vat_percent'] : 0.0;
		$sanitized['vat_percent'] = max( 0.0, min( 100.0, $vat ) );

		// 5. include_fsc: Boolean
		$sanitized['include_fsc'] = ! empty( $input['include_fsc'] );

		// 6. fsc_percent: Float 0.0 to 100.0
		$fsc = isset( $input['fsc_percent'] ) ? (float) $input['fsc_percent'] : 0.0;
		$sanitized['fsc_percent'] = max( 0.0, min( 100.0, $fsc ) );

		// 7. include_surge: Boolean
		$sanitized['include_surge'] = ! empty( $input['include_surge'] );

		// 8. surge_percent: Float 0.0 to 100.0
		$surge = isset( $input['surge_percent'] ) ? (float) $input['surge_percent'] : 0.0;
		$sanitized['surge_percent'] = max( 0.0, min( 100.0, $surge ) );

		// 9. include_customs_fee: Boolean
		$sanitized['include_customs_fee'] = ! empty( $input['include_customs_fee'] );

		// 10. customs_fee_vnd: Non-negative integer
		$customs = isset( $input['customs_fee_vnd'] ) ? (int) $input['customs_fee_vnd'] : 0;
		$sanitized['customs_fee_vnd'] = max( 0, $customs );

		// 11. delete_data_on_uninstall: Boolean
		$sanitized['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		// 12. fluentform_id: Non-negative integer
		$sanitized['fluentform_id'] = ! empty( $input['fluentform_id'] ) ? absint( $input['fluentform_id'] ) : 0;

		// 13. company_name
		$sanitized['company_name'] = ! empty( $input['company_name'] ) ? sanitize_text_field( $input['company_name'] ) : 'CÔNG TY TNHH ALLSHIP LOGISTICS';

		// 14. company_tax_id
		$sanitized['company_tax_id'] = ! empty( $input['company_tax_id'] ) ? sanitize_text_field( $input['company_tax_id'] ) : '';

		// 15. company_address
		$sanitized['company_address'] = ! empty( $input['company_address'] ) ? sanitize_textarea_field( $input['company_address'] ) : '';

		// 16. company_hotline
		$sanitized['company_hotline'] = ! empty( $input['company_hotline'] ) ? sanitize_text_field( $input['company_hotline'] ) : '';

		// 17. company_email
		$sanitized['company_email'] = ! empty( $input['company_email'] ) ? sanitize_email( $input['company_email'] ) : '';

		// 18. company_website
		$sanitized['company_website'] = ! empty( $input['company_website'] ) ? esc_url_raw( $input['company_website'] ) : '';

		// 19. company_logo_url
		$sanitized['company_logo_url'] = ! empty( $input['company_logo_url'] ) ? esc_url_raw( $input['company_logo_url'] ) : '';

		// 19b. company_logo_id
		$sanitized['company_logo_id'] = ! empty( $input['company_logo_id'] ) ? absint( $input['company_logo_id'] ) : 0;

		// 19c. company_logo_height
		$logo_h = isset( $input['company_logo_height'] ) ? (int) $input['company_logo_height'] : 42;
		$sanitized['company_logo_height'] = ( $logo_h >= 25 && $logo_h <= 80 ) ? $logo_h : 42;

		// 20. quote_validity_days
		$days = isset( $input['quote_validity_days'] ) ? (int) $input['quote_validity_days'] : 14;
		$sanitized['quote_validity_days'] = ( $days > 0 ) ? min( 365, $days ) : 14;

		// 21. quote_bank_info
		$sanitized['quote_bank_info'] = ! empty( $input['quote_bank_info'] ) ? sanitize_textarea_field( $input['quote_bank_info'] ) : '';

		// 22. quote_terms_notes
		$sanitized['quote_terms_notes'] = ! empty( $input['quote_terms_notes'] ) ? sanitize_textarea_field( $input['quote_terms_notes'] ) : '';

		// 23. quote_digital_stamp_url
		$sanitized['quote_digital_stamp_url'] = ! empty( $input['quote_digital_stamp_url'] ) ? esc_url_raw( $input['quote_digital_stamp_url'] ) : '';

		// 24. quote_admin_notify_emails
		$sanitized['quote_admin_notify_emails'] = ! empty( $input['quote_admin_notify_emails'] ) ? sanitize_text_field( $input['quote_admin_notify_emails'] ) : '';

		return $sanitized;
	}

	/**
	 * Save settings into database and clear transient caches.
	 *
	 * @param array $raw_data Raw input from POST.
	 * @return bool True on success.
	 */
	public function save_settings( array $raw_data ) {
		if ( ! $this->settings_manager ) {
			return false;
		}

		$cleaned = $this->sanitize_settings( $raw_data );

		foreach ( $cleaned as $key => $value ) {
			$this->settings_manager->set( $key, $value );
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( 'allship_ups_fluentform_id', $cleaned['fluentform_id'] );
		}

		// Invalidate calculation transients and memory cache
		$this->clear_transients();
		if ( class_exists( 'Allship_UPS_Settings_Manager' ) ) {
			Allship_UPS_Settings_Manager::clear_cache();
		}

		return true;
	}

	/**
	 * Handle admin_post form submission.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bạn không có quyền thay đổi cài đặt này.', 'allship-ups-quote' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$this->save_settings( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$redirect_url = add_query_arg(
			[
				'page'             => self::SETTINGS_PAGE,
				'settings-updated' => 'true',
			],
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle AJAX save settings submission.
	 *
	 * @return void
	 */
	public function ajax_save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'allship-ups-quote' ) ], 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$this->save_settings( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		wp_send_json_success(
			[
				'message' => __( 'Cài đặt đã được lưu thành công.', 'allship-ups-quote' ),
			]
		);
	}

	/**
	 * Clear all transient caches for quotes and calculations.
	 *
	 * @return void
	 */
	public function clear_transients() {
		if ( ! $this->wpdb ) {
			return;
		}

		$options_table = $this->wpdb->prefix . 'options';
		$this->wpdb->query(
			"DELETE FROM {$options_table} WHERE option_name LIKE '_transient_ups_%' OR option_name LIKE '_transient_timeout_ups_%'"
		);
	}

	/**
	 * Helper: Get setting value.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get_setting( $key, $default = null ) {
		if ( ! $this->settings_manager ) {
			return $default;
		}
		return $this->settings_manager->get( $key, $default );
	}

	/**
	 * Helper: Get all settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_all_settings() {
		if ( ! $this->settings_manager ) {
			return [];
		}
		return $this->settings_manager->get_all();
	}
}
