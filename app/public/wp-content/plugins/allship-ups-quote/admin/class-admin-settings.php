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
