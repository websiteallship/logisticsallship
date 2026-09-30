<?php
/**
 * Quote Mailer service using standard WordPress wp_mail().
 *
 * Fully compatible with all SMTP plugins (FluentSMTP, WP Mail SMTP, Post SMTP, etc.).
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Quote_Mailer {

	/**
	 * Settings manager instance.
	 *
	 * @var Allship_UPS_Settings_Manager|null
	 */
	private $settings_mgr;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Settings_Manager|null $settings_mgr Settings manager.
	 */
	public function __construct( $settings_mgr = null ) {
		if ( null === $settings_mgr ) {
			if ( ! class_exists( 'Allship_UPS_Settings_Manager' ) && file_exists( __DIR__ . '/class-settings-manager.php' ) ) {
				require_once __DIR__ . '/class-settings-manager.php';
			}
			$this->settings_mgr = class_exists( 'Allship_UPS_Settings_Manager' ) ? new Allship_UPS_Settings_Manager() : null;
		} else {
			$this->settings_mgr = $settings_mgr;
		}
	}

	/**
	 * Send official quotation PDF email to customer.
	 *
	 * @param array  $lead     Lead / quotation details.
	 * @param string $pdf_path Absolute path to the generated PDF file.
	 * @return bool True if email accepted for delivery, false otherwise.
	 */
	public function send_quote_to_customer( array $lead, $pdf_path = '' ) {
		$recipient = ! empty( $lead['email'] ) ? trim( (string) $lead['email'] ) : '';
		if ( empty( $recipient ) || ! function_exists( 'is_email' ) || ! is_email( $recipient ) ) {
			return false;
		}

		$c_name     = $this->get_setting( 'company_name', 'CÔNG TY TNHH ALLSHIP LOGISTICS' );
		$c_hotline  = $this->get_setting( 'company_hotline', '1900 633 833 / 0903 000 888' );
		$c_email    = $this->get_setting( 'company_email', 'quote@allship.vn' );
		$c_website  = $this->get_setting( 'company_website', 'https://allship.vn' );

		$quote_ref  = $lead['quote_ref'] ?? 'AS-QUO';
		$service    = $lead['service_name'] ?? ( $lead['service_code'] ?? 'UPS Express' );
		$subject    = sprintf( '[Allship Logistics] Báo Giá Vận Chuyển Quốc Tế - %s (%s)', $quote_ref, $service );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', esc_attr( $c_name ), esc_attr( $c_email ) ),
			sprintf( 'Reply-To: %s', esc_attr( $c_email ) ),
		];

		$attachments = [];
		if ( ! empty( $pdf_path ) && file_exists( $pdf_path ) ) {
			$attachments[] = $pdf_path;
		}

		$body = $this->build_customer_email_html( $lead, [
			'company_name'    => $c_name,
			'company_hotline' => $c_hotline,
			'company_email'   => $c_email,
			'company_website' => $c_website,
			'bank_info'       => $this->get_setting( 'quote_bank_info', '' ),
		] );

		if ( ! function_exists( 'wp_mail' ) ) {
			return false;
		}

		return (bool) wp_mail( $recipient, $subject, $body, $headers, $attachments );
	}

	/**
	 * Send new lead notification email to admin and sales team.
	 *
	 * @param array  $lead     Lead details.
	 * @param string $pdf_path Absolute path to the generated PDF file.
	 * @return bool
	 */
	public function send_lead_alert_to_admin( array $lead, $pdf_path = '' ) {
		$notify_raw = (string) $this->get_setting( 'quote_admin_notify_emails', '' );
		$recipients = [];

		if ( ! empty( $notify_raw ) ) {
			$parts = explode( ',', $notify_raw );
			foreach ( $parts as $p ) {
				$em = trim( $p );
				if ( function_exists( 'is_email' ) && is_email( $em ) ) {
					$recipients[] = $em;
				}
			}
		}

		if ( empty( $recipients ) && function_exists( 'get_option' ) ) {
			$admin_em = get_option( 'admin_email' );
			if ( ! empty( $admin_em ) && is_email( $admin_em ) ) {
				$recipients[] = $admin_em;
			}
		}

		if ( empty( $recipients ) ) {
			return false;
		}

		$quote_ref   = $lead['quote_ref'] ?? 'AS-QUO';
		$name        = $lead['contact_name'] ?? ( $lead['name'] ?? 'Khách hàng' );
		$company     = ! empty( $lead['company_name'] ) ? ( ' (' . $lead['company_name'] . ')' ) : '';
		$phone       = $lead['phone'] ?? '—';
		$price_str   = ! empty( $lead['total_price_vnd'] ) ? number_format( (float) $lead['total_price_vnd'], 0, ',', '.' ) . ' VND' : '—';
		$service     = $lead['service_code'] ?? 'UPS';
		$dest        = $lead['destination_name'] ?? ( $lead['destination_iata'] ?? '—' );
		$source_type = ( ( $lead['source'] ?? '' ) === 'pdf_export' ) ? 'Xuất Báo Giá PDF' : 'Đặt Dịch Vụ';

		$subject = sprintf( '[Lead %s] %s%s - %s (%s)', $source_type, $name, $company, $quote_ref, $service );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
		];

		$attachments = [];
		if ( ! empty( $pdf_path ) && file_exists( $pdf_path ) ) {
			$attachments[] = $pdf_path;
		}

		$raw_weight = $lead['chargeable_weight_kg'] ?? ( $lead['weight_kg'] ?? ( $lead['chargeable_weight'] ?? ( $lead['weight'] ?? 0 ) ) );
		$weight_num = floatval( $raw_weight );
		$weight_str = esc_html( (string) ( $weight_num > 0 ? ( ( (float) ( (int) $weight_num ) === $weight_num ) ? (string) (int) $weight_num : number_format( $weight_num, 2, '.', '' ) ) : ( $raw_weight ?: '0' ) ) );

		$body = '<div style="font-family: Arial, sans-serif; font-size: 14px; color: #1e293b; max-width: 650px; line-height: 1.6; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">' .
			'<div style="background: #ce2027; color: #ffffff; padding: 16px 20px;">' .
				'<h2 style="margin: 0; font-size: 18px;">Thông Báo Lead Báo Giá Mới Từ Website</h2>' .
				'<span style="font-size: 12px; opacity: 0.9;">Mã tham chiếu: ' . esc_html( $quote_ref ) . ' | Nguồn: ' . esc_html( $source_type ) . '</span>' .
			'</div>' .
			'<div style="padding: 20px;">' .
				'<p>Hệ thống vừa ghi nhận khách hàng xuất bảng báo giá cước vận chuyển quốc tế:</p>' .
				'<table style="width: 100%; border-collapse: collapse; font-size: 13px; margin: 15px 0;">' .
					'<tr><td style="padding: 6px 10px; background: #f8fafc; width: 35%;"><strong>Họ và tên:</strong></td><td style="padding: 6px 10px;">' . esc_html( $name ) . '</td></tr>' .
					'<tr><td style="padding: 6px 10px; background: #f8fafc;"><strong>Công ty / Tổ chức:</strong></td><td style="padding: 6px 10px;">' . esc_html( $lead['company_name'] ?? '—' ) . '</td></tr>' .
					'<tr><td style="padding: 6px 10px; background: #f8fafc;"><strong>Hộp thư Email:</strong></td><td style="padding: 6px 10px;"><a href="mailto:' . esc_attr( $lead['email'] ?? '' ) . '">' . esc_html( $lead['email'] ?? '—' ) . '</a></td></tr>' .
					'<tr><td style="padding: 6px 10px; background: #f8fafc;"><strong>Số điện thoại:</strong></td><td style="padding: 6px 10px;"><a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', (string) $phone ) ) . '"><strong>' . esc_html( $phone ) . '</strong></a></td></tr>' .
					'<tr><td style="padding: 6px 10px; background: #f8fafc;"><strong>Dịch vụ UPS:</strong></td><td style="padding: 6px 10px;"><strong>' . esc_html( $service ) . '</strong></td></tr>' .
					'<tr><td style="padding: 6px 10px; background: #f8fafc;"><strong>Tuyến đường:</strong></td><td style="padding: 6px 10px;">' . esc_html( $lead['origin_country'] ?? 'VN' ) . ' &rarr; ' . esc_html( $dest ) . '</td></tr>' .
					'<tr><td style="padding: 6px 10px; background: #f8fafc;"><strong>Cân nặng tính cước:</strong></td><td style="padding: 6px 10px;">' . $weight_str . ' kg</td></tr>' .
					'<tr><td style="padding: 6px 10px; background: #f8fafc;"><strong>Tổng cước tạm tính:</strong></td><td style="padding: 6px 10px; color: #ce2027; font-weight: bold; font-size: 15px;">' . esc_html( $price_str ) . '</td></tr>' .
				'</table>' .
				( ! empty( $lead['pdf_url'] ) ? '<p><a href="' . esc_url( $lead['pdf_url'] ) . '" style="display: inline-block; background: #0f2240; color: #ffffff; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 13px;">Tải / Xem file PDF Báo Giá</a></p>' : '' ) .
				'<hr style="border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;">' .
				'<span style="font-size: 12px; color: #64748b;">Khách hàng vừa tải báo giá trực tiếp, vui lòng liên hệ chăm sóc và chốt đơn trong vòng 15 phút.</span>' .
			'</div>' .
		'</div>';

		if ( ! function_exists( 'wp_mail' ) ) {
			return false;
		}

		return (bool) wp_mail( $recipients, $subject, $body, $headers, $attachments );
	}

	/**
	 * Build responsive HTML template for customer quotation email.
	 *
	 * @param array $lead    Lead details.
	 * @param array $company Company settings.
	 * @return string HTML email content.
	 */
	private function build_customer_email_html( array $lead, array $company ) {
		$name      = ! empty( $lead['contact_name'] ) ? esc_html( $lead['contact_name'] ) : ( ! empty( $lead['name'] ) ? esc_html( $lead['name'] ) : 'Quý khách' );
		$quote_ref = esc_html( $lead['quote_ref'] ?? 'AS-QUO' );
		$service   = esc_html( $lead['service_name'] ?? ( $lead['service_code'] ?? 'UPS Express' ) );
		$origin    = esc_html( $lead['origin_country'] ?? 'Việt Nam' );
		$dest      = esc_html( $lead['destination_name'] ?? ( $lead['destination_iata'] ?? 'Quốc tế' ) );
		$raw_weight = $lead['chargeable_weight_kg'] ?? ( $lead['weight_kg'] ?? ( $lead['chargeable_weight'] ?? ( $lead['weight'] ?? 0 ) ) );
		$weight_num = floatval( $raw_weight );
		$weight     = esc_html( (string) ( $weight_num > 0 ? ( ( (float) ( (int) $weight_num ) === $weight_num ) ? (string) (int) $weight_num : number_format( $weight_num, 2, '.', '' ) ) : ( $raw_weight ?: '0' ) ) );
		$price_str = ! empty( $lead['total_price_vnd'] ) ? number_format( (float) $lead['total_price_vnd'], 0, ',', '.' ) . ' VND' : '—';
		$c_name    = esc_html( $company['company_name'] );
		$hotline   = esc_html( $company['company_hotline'] );
		$email     = esc_html( $company['company_email'] );
		$website   = esc_url( $company['company_website'] );
		$bank      = ! empty( $company['bank_info'] ) ? nl2br( esc_html( $company['bank_info'] ) ) : '';

		return '<!DOCTYPE html>' .
		'<html>' .
		'<head><meta charset="utf-8"></head>' .
		'<body style="font-family: Arial, sans-serif; background-color: #f1f5f9; padding: 20px; margin: 0;">' .
		'<div style="max-width: 600px; background-color: #ffffff; margin: 0 auto; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">' .
			// Brand Header
			'<div style="background-color: #ce2027; padding: 20px 24px; text-align: left;">' .
				'<h1 style="color: #ffffff; margin: 0; font-size: 20px; text-transform: uppercase;">' . $c_name . '</h1>' .
				'<span style="color: #fee2e2; font-size: 13px;">Dịch vụ Chuyển phát nhanh Quốc tế & Vận tải Toàn cầu</span>' .
			'</div>' .
			// Content Body
			'<div style="padding: 24px; color: #1e293b; font-size: 14px; line-height: 1.6;">' .
				'<p>Kính gửi <strong>' . $name . '</strong>,</p>' .
				'<p>Allship Logistics xin chân thành cảm ơn Quý khách đã quan tâm và tra cứu cước vận chuyển quốc tế trên hệ thống của chúng tôi.</p>' .
				'<p>Đính kèm theo email này là <strong>Bảng Báo Giá Chính Thức (File PDF)</strong> với chi tiết tuyến đường và phụ phí như sau:</p>' .
				// Summary Card
				'<div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px; margin: 20px 0;">' .
					'<table style="width: 100%; border-collapse: collapse; font-size: 13px;">' .
						'<tr><td style="color: #64748b; padding: 4px 0; width: 40%;">Mã số Báo giá:</td><td><strong style="color: #ce2027; font-family: monospace; font-size: 14px;">' . $quote_ref . '</strong></td></tr>' .
						'<tr><td style="color: #64748b; padding: 4px 0;">Gói dịch vụ:</td><td><strong>' . $service . '</strong></td></tr>' .
						'<tr><td style="color: #64748b; padding: 4px 0;">Tuyến vận chuyển:</td><td>' . $origin . ' &rarr; <strong>' . $dest . '</strong></td></tr>' .
						'<tr><td style="color: #64748b; padding: 4px 0;">Trọng lượng tính cước:</td><td>' . $weight . ' kg</td></tr>' .
						'<tr style="border-top: 1px solid #cbd5e1;"><td style="color: #0f172a; padding: 10px 0 0 0; font-weight: bold; font-size: 14px;">Tổng cước tạm tính:</td><td style="padding: 10px 0 0 0; color: #ce2027; font-weight: bold; font-size: 16px;">' . $price_str . '</td></tr>' .
					'</table>' .
				'</div>' .
				( ! empty( $lead['pdf_url'] ) ? '<p style="text-align: center; margin: 24px 0;"><a href="' . esc_url( $lead['pdf_url'] ) . '" style="background-color: #ce2027; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;">Tải Báo Giá PDF Ngay</a></p>' : '' ) .
				( $bank ? '<div style="background-color: #f1f5f9; border-left: 4px solid #ce2027; padding: 12px 14px; margin: 20px 0; font-size: 12px; color: #334155;"><strong>Thông tin thanh toán:</strong><br>' . $bank . '</div>' : '' ) .
				'<p>Chuyên viên cước của Allship sẽ liên hệ hỗ trợ Quý khách giải đáp thắc mắc về mã HS, thủ tục khai báo hải quan và lịch trình nhận hàng.</p>' .
				'<p style="margin-top: 24px;">Trân trọng,<br><strong>Phòng Chăm Sóc Khách Hàng - Allship Logistics</strong><br>' .
				'Hotline: <a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $hotline ) ) . '" style="color: #ce2027; font-weight: bold; text-decoration: none;">' . $hotline . '</a> | Email: ' . $email . '<br>' .
				'Website: <a href="' . $website . '" style="color: #2563eb; text-decoration: none;">' . $website . '</a></p>' .
			'</div>' .
			// Footer
			'<div style="background-color: #0f2240; color: #94a3b8; padding: 14px 24px; font-size: 11px; text-align: center;">' .
				'&copy; ' . gmdate( 'Y' ) . ' ' . $c_name . '. Tất cả quyền được bảo lưu.' .
			'</div>' .
		'</div>' .
		'</body>' .
		'</html>';
	}

	/**
	 * Helper to get a setting value.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private function get_setting( $key, $default = '' ) {
		if ( ! $this->settings_mgr ) {
			return $default;
		}
		return $this->settings_mgr->get( $key, $default );
	}
}
