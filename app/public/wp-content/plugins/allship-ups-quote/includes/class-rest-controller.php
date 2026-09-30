<?php
/**
 * REST API Controller for UPS Quote System.
 *
 * Implements REST routes under 'ups-quote/v1':
 * - POST /calculate
 * - POST /lead
 * - GET  /countries
 * - GET  /services
 * - GET  /directions
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	class WP_REST_Controller {}
}

class Allship_UPS_REST_Controller extends WP_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ups-quote/v1';

	/**
	 * Guard against duplicate route registrations.
	 *
	 * @var bool
	 */
	private static $routes_registered = false;

	/**
	 * Rate card repository.
	 *
	 * @var Allship_UPS_Rate_Card_Repository|null
	 */
	private $rate_card_repo;

	/**
	 * Country repository.
	 *
	 * @var Allship_UPS_Country_Repository|null
	 */
	private $country_repo;

	/**
	 * Zone repository.
	 *
	 * @var Allship_UPS_Zone_Repository|null
	 */
	private $zone_repo;

	/**
	 * Rate repository.
	 *
	 * @var Allship_UPS_Rate_Repository|null
	 */
	private $rate_repo;

	/**
	 * Service availability manager.
	 *
	 * @var Allship_UPS_Service_Availability_Manager|null
	 */
	private $service_mgr;

	/**
	 * Quote calculator orchestrator.
	 *
	 * @var Allship_UPS_Quote_Calculator|null
	 */
	private $calculator;

	/**
	 * Quote log repository.
	 *
	 * @var Allship_UPS_Quote_Log_Repository|null
	 */
	private $quote_log_repo;

	/**
	 * Settings manager.
	 *
	 * @var Allship_UPS_Settings_Manager|null
	 */
	private $settings_mgr;

	/**
	 * Quote lead repository.
	 *
	 * @var Allship_UPS_Quote_Lead_Repository|null
	 */
	private $quote_lead_repo;

	/**
	 * PDF renderer.
	 *
	 * @var Allship_UPS_Quote_Pdf_Renderer|null
	 */
	private $pdf_renderer;

	/**
	 * Quote mailer.
	 *
	 * @var Allship_UPS_Quote_Mailer|null
	 */
	private $quote_mailer;

	/**
	 * Constructor with dependency injection.
	 *
	 * @param Allship_UPS_Quote_Calculator|null              $calculator Quote calculator.
	 * @param Allship_UPS_Country_Repository|null            $country_repo Country repository.
	 * @param Allship_UPS_Rate_Card_Repository|null          $rate_card_repo Rate card repository.
	 * @param Allship_UPS_Zone_Repository|null               $zone_repo Zone repository.
	 * @param Allship_UPS_Rate_Repository|null               $rate_repo Rate repository.
	 * @param Allship_UPS_Service_Availability_Manager|null  $service_mgr Service availability manager.
	 * @param Allship_UPS_Quote_Log_Repository|null          $quote_log_repo Quote log repository.
	 * @param Allship_UPS_Settings_Manager|null              $settings_mgr Settings manager.
	 * @param Allship_UPS_Quote_Lead_Repository|null         $quote_lead_repo Quote lead repository.
	 * @param Allship_UPS_Quote_Pdf_Renderer|null            $pdf_renderer PDF renderer.
	 * @param Allship_UPS_Quote_Mailer|null                  $quote_mailer Quote mailer.
	 */
	public function __construct(
		$calculator = null,
		$country_repo = null,
		$rate_card_repo = null,
		$zone_repo = null,
		$rate_repo = null,
		$service_mgr = null,
		$quote_log_repo = null,
		$settings_mgr = null,
		$quote_lead_repo = null,
		$pdf_renderer = null,
		$quote_mailer = null
	) {
		$this->rate_card_repo  = $rate_card_repo ?: ( class_exists( 'Allship_UPS_Rate_Card_Repository' ) ? new Allship_UPS_Rate_Card_Repository() : null );
		$this->country_repo    = $country_repo ?: ( class_exists( 'Allship_UPS_Country_Repository' ) ? new Allship_UPS_Country_Repository() : null );
		$this->zone_repo       = $zone_repo ?: ( class_exists( 'Allship_UPS_Zone_Repository' ) ? new Allship_UPS_Zone_Repository() : null );
		$this->rate_repo       = $rate_repo ?: ( class_exists( 'Allship_UPS_Rate_Repository' ) ? new Allship_UPS_Rate_Repository() : null );
		$this->service_mgr     = $service_mgr ?: ( class_exists( 'Allship_UPS_Service_Availability_Manager' ) ? new Allship_UPS_Service_Availability_Manager( $this->rate_card_repo ) : null );
		$this->quote_log_repo  = $quote_log_repo ?: ( class_exists( 'Allship_UPS_Quote_Log_Repository' ) ? new Allship_UPS_Quote_Log_Repository() : null );
		$this->settings_mgr    = $settings_mgr ?: ( class_exists( 'Allship_UPS_Settings_Manager' ) ? new Allship_UPS_Settings_Manager() : null );
		$this->quote_lead_repo = $quote_lead_repo ?: ( class_exists( 'Allship_UPS_Quote_Lead_Repository' ) ? new Allship_UPS_Quote_Lead_Repository() : null );
		$this->pdf_renderer    = $pdf_renderer ?: ( class_exists( 'Allship_UPS_Quote_Pdf_Renderer' ) ? new Allship_UPS_Quote_Pdf_Renderer( $this->settings_mgr ) : null );
		$this->quote_mailer    = $quote_mailer ?: ( class_exists( 'Allship_UPS_Quote_Mailer' ) ? new Allship_UPS_Quote_Mailer( $this->settings_mgr ) : null );
		$this->calculator      = $calculator ?: ( class_exists( 'Allship_UPS_Quote_Calculator' ) ? new Allship_UPS_Quote_Calculator(
			$this->rate_card_repo,
			$this->country_repo,
			null,
			null,
			null,
			null,
			$this->quote_log_repo,
			$this->settings_mgr,
			$this->service_mgr
		) : null );

		if ( function_exists( 'add_action' ) ) {
			add_action( 'allship_ups_async_send_email', [ $this, 'handle_async_email_event' ], 10, 2 );
		}
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		if ( self::$routes_registered ) {
			return;
		}
		self::$routes_registered = true;

		$methods_post = defined( 'WP_REST_Server::CREATABLE' ) ? WP_REST_Server::CREATABLE : 'POST';
		$methods_get  = defined( 'WP_REST_Server::READABLE' ) ? WP_REST_Server::READABLE : 'GET';

		// POST /wp-json/ups-quote/v1/calculate
		register_rest_route(
			$this->namespace,
			'/calculate',
			[
				'methods'             => $methods_post,
				'callback'            => [ $this, 'calculate' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_calculate_args_schema(),
			]
		);

		// POST /wp-json/ups-quote/v1/lead
		register_rest_route(
			$this->namespace,
			'/lead',
			[
				'methods'             => $methods_post,
				'callback'            => [ $this, 'submit_lead' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_lead_args_schema(),
			]
		);

		// POST /wp-json/ups-quote/v1/export-quote
		register_rest_route(
			$this->namespace,
			'/export-quote',
			[
				'methods'             => $methods_post,
				'callback'            => [ $this, 'export_quote' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_export_quote_args_schema(),
			]
		);

		// GET /wp-json/ups-quote/v1/download-quote
		register_rest_route(
			$this->namespace,
			'/download-quote',
			[
				'methods'             => $methods_get,
				'callback'            => [ $this, 'download_quote' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'ref'   => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'token' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// POST /wp-json/ups-quote/v1/async-mail (Internal non-blocking email worker)
		register_rest_route(
			$this->namespace,
			'/async-mail',
			[
				'methods'             => $methods_post,
				'callback'            => [ $this, 'process_async_mail' ],
				'permission_callback' => '__return_true',
			]
		);

		// GET /wp-json/ups-quote/v1/countries
		register_rest_route(
			$this->namespace,
			'/countries',
			[
				'methods'             => $methods_get,
				'callback'            => [ $this, 'get_countries' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'direction'    => [
						'type'              => 'string',
						'default'           => 'export',
						'enum'              => [ 'export', 'import' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'service_code' => [
						'type'              => 'string',
						'required'          => false,
						'enum'              => [ 'EXW', 'XPR', 'WXS', 'XPD', 'WXP', 'WFM' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'rate_card_id' => [
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// GET /wp-json/ups-quote/v1/services
		register_rest_route(
			$this->namespace,
			'/services',
			[
				'methods'             => $methods_get,
				'callback'            => [ $this, 'get_services' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'direction'    => [
						'type'              => 'string',
						'default'           => 'export',
						'enum'              => [ 'export', 'import' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'rate_card_id' => [
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// GET /wp-json/ups-quote/v1/directions
		register_rest_route(
			$this->namespace,
			'/directions',
			[
				'methods'             => $methods_get,
				'callback'            => [ $this, 'get_directions' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'rate_card_id' => [
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Reset route registration flag (useful for testing).
	 *
	 * @return void
	 */
	public static function reset_routes_registered() {
		self::$routes_registered = false;
	}

	/**
	 * Schema definition for calculate endpoint args.
	 *
	 * @return array
	 */
	public function get_calculate_args_schema() {
		return [
			'direction'               => [
				'description'       => 'Chiều vận chuyển (export/import).',
				'type'              => 'string',
				'default'           => 'export',
				'enum'              => [ 'export', 'import' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'origin_iata'             => [
				'description'       => 'Mã IATA quốc gia gửi.',
				'type'              => 'string',
				'default'           => 'VN',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'origin_province'         => [
				'description'       => 'Tỉnh/thành phố gửi hàng.',
				'type'              => 'string',
				'default'           => 'TP. Hồ Chí Minh',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'destination_iata'        => [
				'description'       => 'Mã 2 hoặc 3 chữ cái IATA quốc gia đến.',
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function( $param ) {
					return (bool) preg_match( '/^[A-Za-z]{2,3}$/', trim( (string) $param ) );
				},
			],
			'destination_state'       => [
				'description'       => 'Bang/Tỉnh đến.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'destination_city'        => [
				'description'       => 'Thành phố đến.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'destination_postal_code' => [
				'description'       => 'Mã bưu chính/ZIP code đến.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'destination_address'     => [
				'description'       => 'Địa chỉ cụ thể người nhận.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'service_code'            => [
				'description'       => 'Mã dịch vụ UPS (hoặc ALL để tính toàn bộ dịch vụ so sánh).',
				'type'              => 'string',
				'required'          => false,
				'default'           => 'ALL',
				'enum'              => [ 'EXW', 'XPR', 'WXS', 'XPD', 'WXP', 'WFM', 'ALL' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'shipment_type'           => [
				'description'       => 'Phân loại hàng (document/nondocument).',
				'type'              => 'string',
				'default'           => 'nondocument',
				'enum'              => [ 'document', 'nondocument' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'envelope'                => [
				'description' => 'Là thư/chứng từ phong bì UPS Envelope.',
				'type'        => 'boolean',
				'default'     => false,
			],
			'rate_card_id'            => [
				'description'       => 'ID bảng cước cụ thể (mặc định bảng cước đang kích hoạt).',
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			],
			'pieces'                  => [
				'description' => 'Danh sách kiện hàng.',
				'type'        => 'array',
				'required'    => false,
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'quantity'         => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
						'actual_weight_kg' => [
							'type'    => 'number',
							'minimum' => 0.001,
						],
						'length_cm'        => [
							'type'    => 'number',
							'minimum' => 0,
						],
						'width_cm'         => [
							'type'    => 'number',
							'minimum' => 0,
						],
						'height_cm'        => [
							'type'    => 'number',
							'minimum' => 0,
						],
					],
				],
			],
		];
	}

	/**
	 * Schema definition for lead endpoint args.
	 *
	 * @return array
	 */
	public function get_lead_args_schema() {
		return [
			'name'             => [
				'description'       => 'Họ và tên khách hàng.',
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'phone'            => [
				'description'       => 'Số điện thoại hoặc Zalo liên hệ.',
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'email'            => [
				'description'       => 'Địa chỉ email.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_email',
			],
			'notes'            => [
				'description'       => 'Ghi chú thêm về lô hàng.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'quote_log_id'     => [
				'description'       => 'ID bản ghi báo giá đã tính.',
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			],
			'direction'        => [
				'description'       => 'Chiều vận chuyển.',
				'type'              => 'string',
				'default'           => 'export',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'service_code'     => [
				'description'       => 'Mã dịch vụ khách hàng chọn.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'destination_iata' => [
				'description'       => 'Mã quốc gia đến.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'destination_name' => [
				'description'       => 'Tên quốc gia đến.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'weight_kg'        => [
				'description' => 'Trọng lượng tính cước (kg).',
				'type'        => 'number',
				'required'    => false,
			],
			'total_price_vnd'  => [
				'description'       => 'Tổng giá cước tạm tính (VND).',
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			],
			'route_summary'    => [
				'description'       => 'Tóm tắt tuyến đường vận chuyển.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Get schema for POST /export-quote endpoint arguments.
	 *
	 * @return array
	 */
	public function get_export_quote_args_schema() {
		return [
			'name'                 => [
				'description'       => 'Họ và tên khách hàng.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'contact_name'         => [
				'description'       => 'Họ và tên khách hàng (alias).',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'email'                => [
				'description'       => 'Email nhận file báo giá PDF (bắt buộc).',
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_email',
			],
			'phone'                => [
				'description'       => 'Số điện thoại hoặc Zalo liên hệ.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'company_name'         => [
				'description'       => 'Tên công ty / Doanh nghiệp.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'notes'                => [
				'description'       => 'Ghi chú thêm về yêu cầu vận chuyển.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'quote_log_id'         => [
				'description'       => 'ID bản ghi báo giá đã tính.',
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			],
			'quote_id'             => [
				'description'       => 'ID bản ghi báo giá (alias).',
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			],
			'direction'            => [
				'description'       => 'Chiều vận chuyển (export/import).',
				'type'              => 'string',
				'default'           => 'export',
				'enum'              => [ 'export', 'import' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'service_code'         => [
				'description'       => 'Mã dịch vụ UPS.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'service_name'         => [
				'description'       => 'Tên hiển thị dịch vụ.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'origin_country'       => [
				'description'       => 'Mã nước gửi hàng (mặc định VN).',
				'type'              => 'string',
				'default'           => 'VN',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'destination_iata'     => [
				'description'       => 'Mã quốc gia đến.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'destination_name'     => [
				'description'       => 'Tên quốc gia đến.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'chargeable_weight_kg' => [
				'description' => 'Trọng lượng tính cước (kg).',
				'type'        => 'number',
				'required'    => false,
			],
			'weight_kg'            => [
				'description' => 'Trọng lượng tính cước alias (kg).',
				'type'        => 'number',
				'required'    => false,
			],
			'actual_weight_kg'     => [
				'description' => 'Trọng lượng thực tế (kg).',
				'type'        => 'number',
				'required'    => false,
			],
			'dim_weight_kg'        => [
				'description' => 'Trọng lượng thể tích (kg).',
				'type'        => 'number',
				'required'    => false,
			],
			'package_type'         => [
				'description'       => 'Loại hàng hoá (package/document).',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'base_price_vnd'       => [
				'description'       => 'Cước vận chuyển cơ bản (VND).',
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			],
			'total_price_vnd'      => [
				'description'       => 'Tổng cước thanh toán (VND).',
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			],
			'send_email'           => [
				'description' => 'Có gửi email báo giá cho khách hàng hay không.',
				'type'        => 'boolean',
				'default'     => true,
			],
		];
	}

	/**
	 * Get client IP address supporting reverse proxies & Cloudflare.
	 *
	 * @return string
	 */
	public function get_client_ip(): string {
		$headers = [
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		];

		foreach ( $headers as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip_list = explode( ',', (string) $_SERVER[ $h ] );
				$ip      = trim( $ip_list[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return ! empty( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
	}

	/**
	 * Detect device type (desktop, mobile, tablet) from User-Agent.
	 *
	 * @return string
	 */
	public function detect_device_type(): string {
		$ua = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( empty( $ua ) ) {
			return 'desktop';
		}

		if ( preg_match( '/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua ) ) {
			return 'tablet';
		}

		if ( preg_match( '/(mobi|ipod|phone|blackberry|opera mini|iemobile|mobile)/i', $ua ) ) {
			return 'mobile';
		}

		return 'desktop';
	}

	/**
	 * Rate limiter for quote calculation endpoint to prevent bot spam/flooding.
	 *
	 * Allows normal price checking/comparison by real users, but enforces:
	 * 1. Cooldown throttling: minimum 350ms between consecutive requests from same IP.
	 * 2. Rolling window rate limit: maximum 30 requests per minute per IP.
	 * 3. Whitelist: Administrators (manage_options) are completely exempt.
	 *
	 * @param string $client_ip Client IP address.
	 * @return true|array True if allowed, or error array with code, message, status.
	 */
	public function check_calculate_rate_limit( string $client_ip ) {
		// Unit testing / filter bypass
		if ( function_exists( 'apply_filters' ) && apply_filters( 'allship_ups_disable_rate_limit', false ) ) {
			return true;
		}

		// Admin bypass
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( empty( $client_ip ) || ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return true;
		}

		$rate_key = 'ups_rl_' . md5( $client_ip );
		$now      = microtime( true );
		$record   = get_transient( $rate_key );

		$max_per_minute = 30;
		if ( $this->settings_mgr && method_exists( $this->settings_mgr, 'get' ) ) {
			$max_per_minute = (int) $this->settings_mgr->get( 'rate_limit_max_per_min', 30 );
		}

		if ( is_array( $record ) && isset( $record['count'], $record['last_time'] ) ) {
			// 1. Cooldown burst throttle: min 350ms between requests
			if ( ( $now - (float) $record['last_time'] ) < 0.35 ) {
				return [
					'error'   => true,
					'code'    => 'RATE_LIMIT_COOLDOWN',
					'message' => 'Thao tác tra cứu quá nhanh. Vui lòng đợi trong giây lát.',
					'status'  => 429,
				];
			}

			// 2. Volume throttle: max requests in 60s
			if ( (int) $record['count'] >= $max_per_minute ) {
				return [
					'error'   => true,
					'code'    => 'RATE_LIMIT_EXCEEDED',
					'message' => 'Bạn đã thực hiện quá nhiều lượt tra cứu liên tục. Vui lòng đợi 1 phút trước khi tiếp tục.',
					'status'  => 429,
				];
			}

			$record['count']     = (int) $record['count'] + 1;
			$record['last_time'] = $now;
			set_transient( $rate_key, $record, 60 );
		} else {
			set_transient(
				$rate_key,
				[
					'count'     => 1,
					'last_time' => $now,
				],
				60
			);
		}

		return true;
	}

	/**
	 * POST /calculate — Perform quotation calculation.
	 *
	 * @param WP_REST_Request|object $request REST request object.
	 * @return WP_REST_Response
	 */
	public function calculate( $request ) {
		$params = is_object( $request ) && method_exists( $request, 'get_params' )
			? $request->get_params()
			: (array) $request;

		$client_ip   = $this->get_client_ip();
		$device_type = $this->detect_device_type();
		$user_agent  = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

		$rate_check = $this->check_calculate_rate_limit( $client_ip );
		if ( is_array( $rate_check ) && ! empty( $rate_check['error'] ) ) {
			return $this->error_response( $rate_check['code'], $rate_check['message'], $rate_check['status'] ?? 429 );
		}

		// 1. Normalize & Validate Input
		$direction        = ! empty( $params['direction'] ) ? strtolower( trim( (string) $params['direction'] ) ) : 'export';
		$destination_iata = ! empty( $params['destination_iata'] ) ? strtoupper( trim( (string) $params['destination_iata'] ) ) : '';
		$service_code     = ! empty( $params['service_code'] ) ? strtoupper( trim( (string) $params['service_code'] ) ) : 'ALL';
		$shipment_type    = ! empty( $params['shipment_type'] ) ? strtolower( trim( (string) $params['shipment_type'] ) ) : 'nondocument';
		$envelope         = ! empty( $params['envelope'] );

		if ( empty( $destination_iata ) || ! preg_match( '/^[A-Z]{2,3}$/', $destination_iata ) ) {
			return $this->error_response( 'INVALID_INPUT', 'Mã quốc gia đến (destination_iata) không hợp lệ.', 422 );
		}

		$valid_services = [ 'EXW', 'XPR', 'WXS', 'XPD', 'WXP', 'WFM', 'ALL' ];
		if ( empty( $service_code ) || ! in_array( $service_code, $valid_services, true ) ) {
			return $this->error_response( 'INVALID_INPUT', 'Mã dịch vụ UPS (service_code) không hợp lệ.', 422 );
		}

		$clean_pieces = [];
		if ( ! empty( $params['pieces'] ) && is_array( $params['pieces'] ) ) {
			if ( count( $params['pieces'] ) > 50 ) {
				return $this->error_response( 'INVALID_INPUT', 'Số kiện hàng tối đa cho phép là 50 kiện.', 422 );
			}
			foreach ( $params['pieces'] as $piece ) {
				if ( ! is_array( $piece ) ) {
					continue;
				}
				$clean_pieces[] = [
					'quantity'         => isset( $piece['quantity'] ) ? max( 1, min( 1000, absint( $piece['quantity'] ) ) ) : 1,
					'actual_weight_kg' => isset( $piece['actual_weight_kg'] ) ? max( 0.001, floatval( $piece['actual_weight_kg'] ) ) : 0.0,
					'length_cm'        => isset( $piece['length_cm'] ) ? max( 0.0, floatval( $piece['length_cm'] ) ) : 0.0,
					'width_cm'         => isset( $piece['width_cm'] ) ? max( 0.0, floatval( $piece['width_cm'] ) ) : 0.0,
					'height_cm'        => isset( $piece['height_cm'] ) ? max( 0.0, floatval( $piece['height_cm'] ) ) : 0.0,
				];
			}
		}

		if ( empty( $clean_pieces ) && ! $envelope ) {
			return $this->error_response( 'INVALID_INPUT', 'Vui lòng nhập ít nhất 1 kiện hàng.', 422 );
		}

		if ( ! $this->calculator ) {
			return $this->error_response( 'QUOTE_INTERNAL_ERROR', 'Hệ thống tính giá chưa sẵn sàng.', 500 );
		}

		// Cache Lookup
		$active_card_fingerprint = '';
		if ( $this->rate_card_repo && method_exists( $this->rate_card_repo, 'get_all_active' ) ) {
			$active_cards = $this->rate_card_repo->get_all_active();
			if ( ! empty( $active_cards ) ) {
				$active_card_fingerprint = implode(
					';',
					array_map(
						function ( $c ) {
							return ( $c->id ?? '' ) . ':' . ( $c->status ?? '' ) . ':' . ( $c->activated_at ?? '' );
						},
						$active_cards
					)
				);
			}
		}

		$cache_data = [
			$direction,
			$destination_iata,
			$params['destination_state'] ?? '',
			$service_code,
			$shipment_type,
			$envelope,
			$clean_pieces,
			$active_card_fingerprint,
		];
		$cache_payload = function_exists( 'wp_json_encode' ) ? wp_json_encode( $cache_data ) : json_encode( $cache_data );
		$cache_key     = 'ups_calc_v4_' . md5( (string) $cache_payload );

		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $this->success_response( $cached );
			}
		}

		$calc_input = [
			'direction'               => $direction,
			'origin_iata'             => ! empty( $params['origin_iata'] ) ? sanitize_text_field( $params['origin_iata'] ) : 'VN',
			'origin_province'         => ! empty( $params['origin_province'] ) ? sanitize_text_field( $params['origin_province'] ) : 'TP. Hồ Chí Minh',
			'destination_iata'        => $destination_iata,
			'destination_state'       => ! empty( $params['destination_state'] ) ? sanitize_text_field( $params['destination_state'] ) : null,
			'destination_city'        => ! empty( $params['destination_city'] ) ? sanitize_text_field( $params['destination_city'] ) : null,
			'destination_postal_code' => ! empty( $params['destination_postal_code'] ) ? sanitize_text_field( $params['destination_postal_code'] ) : null,
			'destination_address'     => ! empty( $params['destination_address'] ) ? sanitize_text_field( $params['destination_address'] ) : null,
			'shipment_type'           => $shipment_type,
			'envelope'                => $envelope,
			'rate_card_id'            => ! empty( $params['rate_card_id'] ) ? absint( $params['rate_card_id'] ) : null,
			'pieces'                  => $clean_pieces,
			'ip_address'              => $client_ip,
			'device_type'             => $device_type,
			'user_agent'              => $user_agent,
			'skip_log'                => true, // Do not write quote log on calculation request
		];

		// Mode 1: Batch Calculation across all 6 services
		if ( 'ALL' === $service_code ) {
			$all_services = [ 'EXW', 'XPR', 'WXS', 'XPD', 'WXP', 'WFM' ];
			$services_res = [];
			$best_price   = null;
			$metrics      = null;
			$best_res_obj = null;
			$best_service = null;

			// Do not log individual 6 loop services to DB
			$loop_input             = $calc_input;
			$loop_input['skip_log'] = true;

			foreach ( $all_services as $code ) {
				if ( 'document' === $shipment_type && in_array( $code, [ 'WXP', 'WFM', 'XPD' ], true ) ) {
					continue;
				}

				$single_res = $this->execute_single_service_calc( $loop_input, $code, false );
				if ( $single_res && ! $single_res->is_error() ) {
					$arr = $single_res->to_array()['data'];
					if ( null === $metrics ) {
						$metrics = [
							'actual_weight_kg'     => $arr['actual_weight_kg'] ?? 0,
							'volumetric_weight_kg' => $arr['dim_weight_kg'] ?? ( $arr['volumetric_weight_kg'] ?? 0 ),
							'chargeable_weight_kg' => $arr['chargeable_weight_kg'] ?? 0,
						];
					}
					$price = $arr['total_price_vnd'] ?? $arr['base_price_vnd'] ?? null;
					if ( $price && ( null === $best_price || $price < $best_price ) ) {
						$best_price   = $price;
						$best_service = $code;
						$best_res_obj = $single_res;
					}
					$services_res[] = [
						'code'             => $code,
						'name'             => $arr['service_name'] ?? $code,
						'price'            => $arr['base_price_vnd'] ?? null,
						'vat'              => $arr['vat_vnd'] ?? 0,
						'total_price'      => $price,
						'error'            => null,
						'notes'            => $arr['notes'] ?? [],
						'transit'          => $this->get_service_transit_vi( $code ),
						'zone'             => $arr['zone'] ?? null,
						'rate_zone'        => $arr['rate_zone'] ?? null,
						'rate_card_id'     => $arr['rate_card_id'] ?? null,
						'actual_weight_kg' => $arr['actual_weight_kg'] ?? null,
						'dim_weight_kg'    => $arr['dim_weight_kg'] ?? ( $arr['volumetric_weight_kg'] ?? null ),
						'chargeable_weight_kg' => $arr['chargeable_weight_kg'] ?? null,
					];
				} else {
					$services_res[] = [
						'code'        => $code,
						'name'        => $code,
						'price'       => null,
						'total_price' => null,
						'error'       => $single_res ? $single_res->error_message : 'Không hỗ trợ tuyến này',
						'notes'       => [],
						'transit'     => $this->get_service_transit_vi( $code ),
					];
				}
			}

			$inserted_log_id = null;

			$batch_data = [
				'services'     => $services_res,
				'best_price'   => $best_price,
				'metrics'      => $metrics,
				'quote_log_id' => $inserted_log_id ?: null,
			];

			if ( function_exists( 'set_transient' ) ) {
				set_transient( $cache_key, $batch_data, 3600 );
			}

			return $this->success_response( $batch_data );
		}

		// Mode 2: Single Service Calculation (Backward-compatible)
		$result = $this->execute_single_service_calc( $calc_input, $service_code, true );
		if ( $result->is_error() ) {
			return $this->error_response( $result->error_code, $result->error_message, $this->get_http_status( $result->error_code ) );
		}

		$data = $result->to_array()['data'];
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, $data, 3600 );
		}
		return $this->success_response( $data );
	}

	/**
	 * Helper: Execute single service calculation with multipliers & logging.
	 *
	 * @param array  $calc_input Base calculation parameters.
	 * @param string $service_code UPS service code.
	 * @param bool   $record_log Whether to update persistent quote log.
	 * @return Allship_UPS_Calculation_Result
	 */
	private function execute_single_service_calc( array $calc_input, string $service_code, $record_log = true ) {
		$rate_group = $this->service_mgr
			? $this->service_mgr->resolve_rate_group( $calc_input['direction'], $service_code, $calc_input['shipment_type'] )
			: null;

		$service_input               = $calc_input;
		$service_input['service_code'] = $service_code;
		$service_input['rate_group']   = $rate_group;
		if ( ! $record_log ) {
			$service_input['skip_log'] = true;
		}

		$has_direct_card = false;
		if ( $this->rate_card_repo && ! empty( $rate_group ) && method_exists( $this->rate_card_repo, 'get_active_id_for_rate_group' ) ) {
			$has_direct_card = (bool) $this->rate_card_repo->get_active_id_for_rate_group( $rate_group );
		}

		if ( 'WXP' === $service_code ) {
			if ( $has_direct_card ) {
				return $this->calculator->calculate( $service_input );
			}

			$base_input                 = $service_input;
			$base_input['service_code'] = 'WFM';
			if ( ! $record_log ) {
				$base_input['skip_log'] = true;
			}
			$result                     = $this->calculator->calculate( $base_input );

			if ( $result->is_error() ) {
				return $result;
			}

			$multiplier              = 1.22;
			$result->service_code    = 'WXP';
			$result->service_name    = 'Express Freight';
			$result->base_price_vnd  = (int) round( $result->base_price_vnd * $multiplier );
			$result->total_price_vnd = (int) round( $result->total_price_vnd * $multiplier );

			if ( $result->chargeable_weight_kg < 71.0 ) {
				$result->notes[] = 'Hỏa tốc hàng nặng. Dưới 71kg tính giá tối thiểu (Minimum).';
			}

			if ( $record_log && $this->quote_log_repo && ! empty( $result->quote_log_id ) ) {
				$this->quote_log_repo->update(
					$result->quote_log_id,
					[
						'service_code'    => $service_code,
						'base_price_vnd'  => $result->base_price_vnd,
						'total_price_vnd' => $result->total_price_vnd,
						'breakdown_json'  => [
							'base_price_vnd'  => $result->base_price_vnd,
							'fees'            => $result->fees,
							'total_price_vnd' => $result->total_price_vnd,
							'notes'           => $result->notes,
							'multiplier'      => $multiplier,
						],
					]
				);
			}

			return $result;
		} elseif ( in_array( $service_code, [ 'EXW', 'XPR' ], true ) ) {
			if ( $has_direct_card ) {
				return $this->calculator->calculate( $service_input );
			}

			$base_input                 = $service_input;
			$base_input['service_code'] = 'WXS';
			if ( ! $record_log ) {
				$base_input['skip_log'] = true;
			}
			$result                     = $this->calculator->calculate( $base_input );

			if ( $result->is_error() ) {
				return $result;
			}

			$multiplier     = ( 'EXW' === $service_code ) ? 1.25 : 1.15;
			$service_name   = ( 'EXW' === $service_code ) ? 'Express Early' : 'Express Plus';
			$surcharge_note = ( 'EXW' === $service_code )
				? 'Đã bao gồm phụ phí phát sớm (Early 8:30 AM).'
				: 'Đã bao gồm phụ phí phát ưu tiên (Plus 10:30 AM).';

			$result->service_code    = $service_code;
			$result->service_name    = $service_name;
			$result->base_price_vnd  = (int) round( $result->base_price_vnd * $multiplier );
			$result->total_price_vnd = (int) round( $result->total_price_vnd * $multiplier );
			$result->notes[]         = $surcharge_note;

			if ( $record_log && $this->quote_log_repo && ! empty( $result->quote_log_id ) ) {
				$this->quote_log_repo->update(
					$result->quote_log_id,
					[
						'service_code'    => $service_code,
						'base_price_vnd'  => $result->base_price_vnd,
						'total_price_vnd' => $result->total_price_vnd,
						'breakdown_json'  => [
							'base_price_vnd'  => $result->base_price_vnd,
							'fees'            => $result->fees,
							'total_price_vnd' => $result->total_price_vnd,
							'notes'           => $result->notes,
							'multiplier'      => $multiplier,
						],
					]
				);
			}

			return $result;
		}

		if ( ! $record_log ) {
			$service_input['skip_log'] = true;
		}
		return $this->calculator->calculate( $service_input );
	}

	/**
	 * Helper: Return Vietnamese transit label for UPS service code.
	 *
	 * @param string $code Service code.
	 * @return string
	 */
	private function get_service_transit_vi( string $code ) {
		switch ( $code ) {
			case 'EXW':
				return '1-2 ngày (Giao sớm)';
			case 'XPR':
				return '1-2 ngày (Giao ưu tiên)';
			case 'WXS':
				return '1-3 ngày';
			case 'XPD':
				return '3-5 ngày';
			case 'WXP':
				return '1-2 ngày (Trên 70kg)';
			case 'WFM':
				return '3-5 ngày (Trên 70kg)';
			default:
				return '1-3 ngày';
		}
	}

	/**
	 * POST /lead — Submit booking lead / quotation follow-up.
	 *
	 * @param WP_REST_Request|object $request REST request object.
	 * @return WP_REST_Response
	 */
	public function submit_lead( $request ) {
		$params = is_object( $request ) && method_exists( $request, 'get_params' )
			? $request->get_params()
			: (array) $request;

		$name  = ! empty( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		$phone = ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '';

		if ( empty( $name ) || empty( $phone ) ) {
			return $this->error_response(
				'INVALID_INPUT',
				'Vui lòng nhập họ tên và số điện thoại liên hệ.',
				422
			);
		}

		$clean_phone = preg_replace( '/[\s.\-()]/', '', trim( (string) $phone ) );
		if ( ! preg_match( '/^(?:\+84|84|0)\d{9}$/', $clean_phone ) ) {
			return $this->error_response(
				'INVALID_INPUT',
				'Số điện thoại không hợp lệ (hỗ trợ đầu số 0, 84 hoặc +84 và 9 chữ số tiếp theo).',
				422
			);
		}

		// Rate limiting: maximum 10 lead submissions per 5 minutes per IP address
		if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
			$client_ip      = ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';
			$rate_limit_key = 'allship_lead_limit_' . md5( $client_ip );
			$lead_count     = (int) get_transient( $rate_limit_key );
			if ( $lead_count >= 10 ) {
				return $this->error_response(
					'RATE_LIMIT_EXCEEDED',
					'Bạn đã gửi quá nhiều yêu cầu trong thời gian ngắn. Vui lòng thử lại sau 5 phút.',
					429
				);
			}
			set_transient( $rate_limit_key, $lead_count + 1, 300 );
		}

		$lead_id = 'lead_' . gmdate( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
		$lead    = [
			'lead_id'          => $lead_id,
			'name'             => $name,
			'phone'            => $clean_phone,
			'email'            => ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : '',
			'notes'            => ! empty( $params['notes'] ) ? sanitize_textarea_field( $params['notes'] ) : '',
			'quote_log_id'     => ! empty( $params['quote_log_id'] ) ? absint( $params['quote_log_id'] ) : null,
			'direction'        => ! empty( $params['direction'] ) ? sanitize_text_field( $params['direction'] ) : 'export',
			'service_code'     => ! empty( $params['service_code'] ) ? sanitize_text_field( $params['service_code'] ) : '',
			'destination_iata' => ! empty( $params['destination_iata'] ) ? strtoupper( sanitize_text_field( $params['destination_iata'] ) ) : '',
			'destination_name' => ! empty( $params['destination_name'] ) ? sanitize_text_field( $params['destination_name'] ) : '',
			'weight_kg'        => ! empty( $params['weight_kg'] ) ? floatval( $params['weight_kg'] ) : null,
			'total_price_vnd'  => ! empty( $params['total_price_vnd'] ) ? absint( $params['total_price_vnd'] ) : null,
			'route_summary'    => ! empty( $params['route_summary'] ) ? sanitize_text_field( $params['route_summary'] ) : '',
			'created_at'       => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
		];

		$client_ip   = ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';
		$device_type = $this->detect_device_type();
		$user_agent  = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( (string) $_SERVER['HTTP_USER_AGENT'] ), 0, 255 ) : '';

		// Create quote log entry for successful form submission
		$created_log_id = 0;
		if ( $this->quote_log_repo ) {
			$direction    = ! empty( $params['direction'] ) ? sanitize_text_field( $params['direction'] ) : 'export';
			$service_code = ! empty( $params['service_code'] ) ? sanitize_text_field( $params['service_code'] ) : '';
			$dest_iata    = ! empty( $params['destination_iata'] ) ? strtoupper( sanitize_text_field( $params['destination_iata'] ) ) : '';

			// 1. Rate Card ID resolution
			$rate_card_id = ! empty( $params['rate_card_id'] ) ? absint( $params['rate_card_id'] ) : 0;
			if ( ! $rate_card_id && $this->rate_card_repo ) {
				if ( $service_code && method_exists( $this->rate_card_repo, 'get_active_for_service' ) ) {
					$active_card = $this->rate_card_repo->get_active_for_service( $service_code, $direction );
				} else {
					$active_card = $this->rate_card_repo->get_active();
				}
				$rate_card_id = $active_card ? (int) $active_card->id : 1;
			}
			if ( ! $rate_card_id ) {
				$rate_card_id = 1;
			}

			// 2. Zone & Rate Zone resolution
			$zone      = ! empty( $params['zone'] ) ? sanitize_text_field( $params['zone'] ) : null;
			$rate_zone = ! empty( $params['rate_zone'] ) ? sanitize_text_field( $params['rate_zone'] ) : null;
			if ( ( empty( $zone ) || empty( $rate_zone ) ) && $dest_iata && $service_code && class_exists( 'Allship_UPS_Zone_Resolver' ) ) {
				$zone_resolver = new Allship_UPS_Zone_Resolver( $this->rate_card_repo, $this->country_repo );
				$zone_res      = $zone_resolver->resolve( $dest_iata, $service_code, $direction, $rate_card_id );
				if ( $zone_res && $zone_res->success ) {
					if ( empty( $zone ) ) {
						$zone = $zone_res->zone;
					}
					if ( empty( $rate_zone ) ) {
						$rate_zone = $zone_res->rate_zone;
					}
				}
			}

			// 3. Weight calculation (Actual weight & DIM weight)
			$actual_weight_kg = isset( $params['actual_weight_kg'] ) && '' !== $params['actual_weight_kg'] ? floatval( $params['actual_weight_kg'] ) : null;
			$dim_weight_kg    = isset( $params['dim_weight_kg'] ) && '' !== $params['dim_weight_kg'] ? floatval( $params['dim_weight_kg'] ) : null;

			$raw_pieces = ! empty( $params['pieces'] ) ? ( is_array( $params['pieces'] ) ? $params['pieces'] : json_decode( (string) $params['pieces'], true ) ) : [];
			if ( is_array( $raw_pieces ) && ! empty( $raw_pieces ) ) {
				$calc_act = 0.0;
				$calc_dim = 0.0;
				foreach ( $raw_pieces as $p ) {
					if ( ! is_array( $p ) ) continue;
					$qty = isset( $p['qty'] ) ? max( 1, (int) $p['qty'] ) : ( isset( $p['quantity'] ) ? max( 1, (int) $p['quantity'] ) : 1 );
					$w   = isset( $p['weight'] ) ? floatval( $p['weight'] ) : ( isset( $p['actual_weight_kg'] ) ? floatval( $p['actual_weight_kg'] ) : 0.0 );
					$l   = isset( $p['len'] ) ? floatval( $p['len'] ) : ( isset( $p['length_cm'] ) ? floatval( $p['length_cm'] ) : 0.0 );
					$wid = isset( $p['wid'] ) ? floatval( $p['wid'] ) : ( isset( $p['width_cm'] ) ? floatval( $p['width_cm'] ) : 0.0 );
					$h   = isset( $p['hei'] ) ? floatval( $p['hei'] ) : ( isset( $p['height_cm'] ) ? floatval( $p['height_cm'] ) : 0.0 );

					$calc_act += $w * $qty;
					if ( $l > 0 && $wid > 0 && $h > 0 ) {
						$calc_dim += ( ( $l * $wid * $h ) / 5500 ) * $qty;
					}
				}
				if ( null === $actual_weight_kg && $calc_act > 0 ) {
					$actual_weight_kg = round( $calc_act, 2 );
				}
				if ( null === $dim_weight_kg && $calc_dim > 0 ) {
					$dim_weight_kg = round( $calc_dim, 2 );
				}
			}
			if ( null === $actual_weight_kg && ! empty( $params['weight_kg'] ) ) {
				$actual_weight_kg = floatval( $params['weight_kg'] );
			}

			$weight_val  = ! empty( $params['chargeable_weight'] ) ? floatval( preg_replace( '/[^0-9.]/', '', (string) $params['chargeable_weight'] ) ) : ( ! empty( $params['weight_kg'] ) ? floatval( $params['weight_kg'] ) : ( $actual_weight_kg ? max( $actual_weight_kg, $dim_weight_kg ?: 0 ) : null ) );
			$total_price = ! empty( $params['total_price_raw'] ) ? absint( $params['total_price_raw'] ) : ( ! empty( $params['total_price_vnd'] ) ? absint( $params['total_price_vnd'] ) : null );
			$pieces_data = ! empty( $params['pieces'] ) ? ( is_array( $params['pieces'] ) ? wp_json_encode( $params['pieces'] ) : (string) $params['pieces'] ) : null;

			$created_log_id = (int) $this->quote_log_repo->insert( [
				'rate_card_id'         => $rate_card_id,
				'session_id'           => $lead_id,
				'ip_address'           => $client_ip,
				'device_type'          => $device_type,
				'user_agent'           => $user_agent,
				'direction'            => $direction,
				'origin_iata'          => 'VN',
				'origin_province'      => ! empty( $params['origin'] ) ? sanitize_text_field( $params['origin'] ) : 'TP. Hồ Chí Minh',
				'destination_iata'     => $dest_iata,
				'destination_address'  => ! empty( $params['destination'] ) ? sanitize_text_field( $params['destination'] ) : '',
				'service_code'         => $service_code,
				'zone'                 => $zone,
				'rate_zone'            => $rate_zone,
				'actual_weight_kg'     => $actual_weight_kg,
				'dim_weight_kg'        => $dim_weight_kg,
				'chargeable_weight_kg' => $weight_val,
				'total_price_vnd'      => $total_price,
				'pieces_json'          => $pieces_data,
				'breakdown_json'       => [
					'contact' => [
						'name'       => $name,
						'phone'      => $clean_phone,
						'email'      => ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : '',
						'notes'      => ! empty( $params['notes'] ) ? sanitize_textarea_field( $params['notes'] ) : '',
						'message'    => ! empty( $params['message'] ) ? sanitize_textarea_field( $params['message'] ) : '',
						'created_at' => $lead['created_at'],
					],
					'lead' => [
						'lead_id'    => $lead_id,
						'name'       => $name,
						'phone'      => $clean_phone,
						'email'      => ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : '',
						'notes'      => ! empty( $params['notes'] ) ? sanitize_textarea_field( $params['notes'] ) : '',
						'created_at' => $lead['created_at'],
					],
					'lead_source' => 'ups_quote_booking_modal',
				],
			] );
		}

		$lead['quote_log_id'] = $created_log_id;

		// Store in option log (keeps latest 200 leads)
		if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
			$leads   = (array) get_option( 'allship_ups_leads', [] );
			$leads[] = $lead;
			if ( count( $leads ) > 200 ) {
				$leads = array_slice( $leads, -200 );
			}
			update_option( 'allship_ups_leads', $leads, false );
		}

		// Fire hook for CRM / Webhook integration
		if ( function_exists( 'do_action' ) ) {
			do_action( 'allship_ups_lead_submitted', $lead );
		}

		// Bridge submission into FluentForm submissions table (for ff-frontend-entries)
		$ff_entry_id = 0;
		if ( class_exists( 'Allship_UPS_FluentForm_Bridge' ) ) {
			$bridge_payload = array_merge( (array) $params, $lead );
			if ( $created_log_id > 0 ) {
				$bridge_payload['quote_log_id'] = $created_log_id;
			}
			$ff_bridge   = new Allship_UPS_FluentForm_Bridge();
			$ff_entry_id = $ff_bridge->push_lead_to_fluentform( $bridge_payload );

			if ( $created_log_id > 0 && $ff_entry_id > 0 && $this->quote_log_repo ) {
				$log_obj = $this->quote_log_repo->get( $created_log_id );
				if ( $log_obj ) {
					$bd                           = is_array( $log_obj->breakdown ) ? $log_obj->breakdown : [];
					$bd['contact']['ff_entry_id'] = $ff_entry_id;
					$this->quote_log_repo->update( $created_log_id, [ 'breakdown_json' => $bd ] );
				}
			}
		}

		$response_data = [
			'message'      => 'Cảm ơn bạn! Yêu cầu tư vấn đã được gửi thành công. Chuyên viên Allship sẽ liên hệ trong ít phút.',
			'lead_id'      => $lead_id,
			'quote_log_id' => $created_log_id ?: null,
			'ff_entry_id'  => $ff_entry_id ?: null,
		];

		// Dispatch admin notification asynchronously (non-blocking)
		$this->dispatch_async_email( 'booking', [
			'lead_id'     => $lead_id,
			'lead'        => $lead,
			'name'        => $name,
			'clean_phone' => $clean_phone,
		] );

		return $this->success_response( $response_data );
	}

	/**
	 * POST /export-quote — Generate and export official quotation PDF, save lead, sync FluentForm and dispatch email.
	 *
	 * @param WP_REST_Request|object $request REST request object.
	 * @return WP_REST_Response
	 */
	public function export_quote( $request ) {
		$params = is_object( $request ) && method_exists( $request, 'get_params' )
			? $request->get_params()
			: (array) $request;

		$name  = ! empty( $params['contact_name'] ) ? sanitize_text_field( $params['contact_name'] ) : ( ! empty( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '' );
		$email = ! empty( $params['email'] ) && function_exists( 'sanitize_email' ) ? sanitize_email( $params['email'] ) : ( ! empty( $params['email'] ) ? trim( (string) $params['email'] ) : '' );

		if ( empty( $name ) ) {
			return $this->error_response(
				'INVALID_INPUT',
				'Vui lòng nhập họ tên của bạn để xuất báo giá.',
				422
			);
		}

		if ( empty( $email ) || ( function_exists( 'is_email' ) && ! is_email( $email ) ) ) {
			return $this->error_response(
				'INVALID_INPUT',
				'Vui lòng nhập địa chỉ email hợp lệ để nhận file báo giá.',
				422
			);
		}

		$phone        = ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '';
		$clean_phone  = ! empty( $phone ) ? preg_replace( '/[\s.\-()]/', '', trim( (string) $phone ) ) : '';
		$company_name = ! empty( $params['company_name'] ) ? sanitize_text_field( $params['company_name'] ) : '';
		$notes        = ! empty( $params['notes'] ) && function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $params['notes'] ) : '';

		$client_ip = $this->get_client_ip();

		// Rate limiting: maximum 15 quote exports per 10 minutes per IP
		if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
			$rate_limit_key = 'allship_export_limit_' . md5( $client_ip );
			$export_count   = (int) get_transient( $rate_limit_key );
			if ( $export_count >= 15 ) {
				return $this->error_response(
					'RATE_LIMIT_EXCEEDED',
					'Bạn đã gửi yêu cầu xuất báo giá quá nhiều lần trong thời gian ngắn. Vui lòng thử lại sau ít phút.',
					429
				);
			}
			set_transient( $rate_limit_key, $export_count + 1, 600 );
		}

		// Resolve calculation from quote_log_id if available
		$quote_log_id = ! empty( $params['quote_log_id'] ) ? absint( $params['quote_log_id'] ) : ( ! empty( $params['quote_id'] ) ? absint( $params['quote_id'] ) : 0 );
		$log_obj      = ( $quote_log_id > 0 && $this->quote_log_repo ) ? $this->quote_log_repo->get( $quote_log_id ) : null;

		$direction        = ! empty( $params['direction'] ) ? sanitize_text_field( $params['direction'] ) : ( $log_obj && ! empty( $log_obj->direction ) ? $log_obj->direction : 'export' );
		$service_code     = ! empty( $params['service_code'] ) ? strtoupper( sanitize_text_field( $params['service_code'] ) ) : ( $log_obj && ! empty( $log_obj->service_code ) ? $log_obj->service_code : 'WXS' );
		$origin_country   = ! empty( $params['origin_country'] ) ? strtoupper( sanitize_text_field( $params['origin_country'] ) ) : ( $log_obj && ! empty( $log_obj->origin_iata ) ? $log_obj->origin_iata : 'VN' );
		$destination_iata = ! empty( $params['destination_iata'] ) ? strtoupper( sanitize_text_field( $params['destination_iata'] ) ) : ( $log_obj && ! empty( $log_obj->destination_iata ) ? $log_obj->destination_iata : '' );
		$destination_name = ! empty( $params['destination_name'] ) ? sanitize_text_field( $params['destination_name'] ) : ( $log_obj && ! empty( $log_obj->destination_country_name ) ? $log_obj->destination_country_name : '' );

		if ( empty( $destination_name ) && $destination_iata && $this->country_repo && method_exists( $this->country_repo, 'get_by_code' ) ) {
			$country_row = $this->country_repo->get_by_code( $destination_iata );
			if ( $country_row && ! empty( $country_row->country_name ) ) {
				$destination_name = $country_row->country_name;
			}
		}

		$chargeable_weight_kg = isset( $params['chargeable_weight_kg'] ) ? floatval( $params['chargeable_weight_kg'] ) : ( isset( $params['weight_kg'] ) ? floatval( $params['weight_kg'] ) : ( $log_obj ? floatval( $log_obj->chargeable_weight_kg ) : 0.0 ) );
		$actual_weight_kg     = isset( $params['actual_weight_kg'] ) ? floatval( $params['actual_weight_kg'] ) : ( $log_obj && null !== $log_obj->actual_weight_kg ? floatval( $log_obj->actual_weight_kg ) : $chargeable_weight_kg );
		$dim_weight_kg        = isset( $params['dim_weight_kg'] ) ? floatval( $params['dim_weight_kg'] ) : ( $log_obj && null !== $log_obj->dim_weight_kg ? floatval( $log_obj->dim_weight_kg ) : 0.0 );

		$pieces = ! empty( $params['pieces'] )
			? ( is_array( $params['pieces'] ) ? $params['pieces'] : json_decode( (string) $params['pieces'], true ) )
			: ( ( $log_obj && ! empty( $log_obj->pieces ) ) ? $log_obj->pieces : [] );

		if ( is_array( $pieces ) && ! empty( $pieces ) ) {
			$calc_act = 0.0;
			$calc_dim = 0.0;
			foreach ( $pieces as $p ) {
				if ( ! is_array( $p ) ) {
					continue;
				}
				$qty = isset( $p['qty'] ) ? max( 1, (int) $p['qty'] ) : ( isset( $p['quantity'] ) ? max( 1, (int) $p['quantity'] ) : 1 );
				$w   = isset( $p['weight'] ) ? floatval( $p['weight'] ) : ( isset( $p['actual_weight_kg'] ) ? floatval( $p['actual_weight_kg'] ) : 0.0 );
				$l   = isset( $p['len'] ) ? floatval( $p['len'] ) : ( isset( $p['length_cm'] ) ? floatval( $p['length_cm'] ) : 0.0 );
				$wid = isset( $p['wid'] ) ? floatval( $p['wid'] ) : ( isset( $p['width_cm'] ) ? floatval( $p['width_cm'] ) : 0.0 );
				$h   = isset( $p['hei'] ) ? floatval( $p['hei'] ) : ( isset( $p['height_cm'] ) ? floatval( $p['height_cm'] ) : 0.0 );

				$calc_act += $w * $qty;
				if ( $l > 0 && $wid > 0 && $h > 0 ) {
					$calc_dim += ( ( $l * $wid * $h ) / 5500 ) * $qty;
				}
			}
			if ( ( ! isset( $params['actual_weight_kg'] ) || $actual_weight_kg <= 0 || $actual_weight_kg === $chargeable_weight_kg ) && $calc_act > 0 ) {
				$actual_weight_kg = round( $calc_act, 2 );
			}
			if ( ( ! isset( $params['dim_weight_kg'] ) || $dim_weight_kg <= 0 ) && $calc_dim > 0 ) {
				$dim_weight_kg = round( $calc_dim, 2 );
			}
		}

		$total_price_vnd = isset( $params['total_price_vnd'] ) ? absint( $params['total_price_vnd'] ) : ( $log_obj ? absint( $log_obj->total_price_vnd ) : 0 );
		$base_price_vnd  = isset( $params['base_price_vnd'] ) ? absint( $params['base_price_vnd'] ) : ( $log_obj ? absint( $log_obj->base_price_vnd ) : $total_price_vnd );

		// Breakdown components
		$log_bd           = ( $log_obj && is_array( $log_obj->breakdown ) ) ? $log_obj->breakdown : [];
		$fsc_percent      = isset( $params['fsc_percentage'] ) ? floatval( $params['fsc_percentage'] ) : ( isset( $params['fsc_percent'] ) ? floatval( $params['fsc_percent'] ) : ( ! empty( $log_bd['fsc_percent'] ) ? floatval( $log_bd['fsc_percent'] ) : 0.0 ) );
		$fsc_amount_vnd   = isset( $params['fsc_price_vnd'] ) ? absint( $params['fsc_price_vnd'] ) : ( isset( $params['fsc_amount_vnd'] ) ? absint( $params['fsc_amount_vnd'] ) : ( ! empty( $log_bd['fsc_amount'] ) ? absint( $log_bd['fsc_amount'] ) : 0 ) );
		$surge_amount_vnd = isset( $params['peak_price_vnd'] ) ? absint( $params['peak_price_vnd'] ) : ( isset( $params['surge_amount_vnd'] ) ? absint( $params['surge_amount_vnd'] ) : ( ! empty( $log_bd['surge_amount'] ) ? absint( $log_bd['surge_amount'] ) : 0 ) );
		$vat_amount_vnd   = isset( $params['vat_price_vnd'] ) ? absint( $params['vat_price_vnd'] ) : ( isset( $params['vat_amount_vnd'] ) ? absint( $params['vat_amount_vnd'] ) : ( ! empty( $log_bd['vat_amount'] ) ? absint( $log_bd['vat_amount'] ) : 0 ) );
		$customs_fee_vnd  = isset( $params['customs_fee_vnd'] ) ? absint( $params['customs_fee_vnd'] ) : 0;
		$vat_rate         = isset( $params['vat_rate'] ) ? floatval( $params['vat_rate'] ) : ( ! empty( $log_bd['vat_rate'] ) ? floatval( $log_bd['vat_rate'] ) : 8.0 );

		$service_name = ! empty( $params['service_name'] ) ? sanitize_text_field( $params['service_name'] ) : $this->get_service_name_vi( $service_code );
		$transit_time = $this->get_service_transit_time( $service_code );

		// 1. Generate unique quote reference
		$quote_ref = $this->quote_lead_repo ? $this->quote_lead_repo->generate_quote_ref() : ( 'AS-QUO-' . gmdate( 'Ym' ) . '-' . wp_rand( 1000, 9999 ) );

		// 2. Prepare payload for PDF renderer
		$total_pieces_count = 0;
		if ( is_array( $pieces ) && ! empty( $pieces ) ) {
			foreach ( $pieces as $p ) {
				$total_pieces_count += isset( $p['qty'] ) ? max( 1, (int) $p['qty'] ) : ( isset( $p['quantity'] ) ? max( 1, (int) $p['quantity'] ) : 1 );
			}
		} else {
			$total_pieces_count = 1;
		}

		$pdf_payload = [
			'company'  => [],
			'quote'    => [
				'quote_ref'    => $quote_ref,
				'created_at'   => date( 'd/m/Y' ),
				'service_code' => $service_code,
				'service_name' => $service_name,
				'direction'    => $direction,
				'origin'       => ( 'import' === $direction ) ? ( $destination_name ?: $destination_iata ) : 'Việt Nam',
				'destination'  => ( 'import' === $direction ) ? 'Việt Nam' : ( $destination_name ? "{$destination_name} ({$destination_iata})" : $destination_iata ),
				'incoterms'    => 'DDU / Door-to-Door',
				'transit_time' => $transit_time,
			],
			'customer' => [
				'name'    => $name,
				'company' => $company_name,
				'phone'   => $clean_phone ?: $phone,
				'email'   => $email,
			],
			'cargo'    => [
				'pieces'               => $pieces,
				'total_pieces'         => $total_pieces_count,
				'gross_weight_kg'      => $actual_weight_kg,
				'dim_weight_kg'        => $dim_weight_kg,
				'chargeable_weight_kg' => $chargeable_weight_kg,
			],
			'pricing'  => [
				'base_price_vnd'   => $base_price_vnd,
				'fsc_percent'      => $fsc_percent,
				'fsc_amount_vnd'   => $fsc_amount_vnd,
				'surge_amount_vnd' => $surge_amount_vnd,
				'customs_fee_vnd'  => $customs_fee_vnd,
				'vat_rate'         => $vat_rate,
				'vat_amount_vnd'   => $vat_amount_vnd,
				'total_price_vnd'  => $total_price_vnd,
			],
		];

		// 3. Compile & save PDF
		$pdf_path = '';
		$pdf_url  = '';
		$t_start = microtime( true );

		// 3. Render and save PDF file
		$pdf_path = '';
		$pdf_url  = '';
		if ( $this->pdf_renderer ) {
			$pdf_file = $this->pdf_renderer->save_pdf_to_file( $pdf_payload, $quote_ref . '.pdf' );
			if ( is_array( $pdf_file ) ) {
				$pdf_path = $pdf_file['path'] ?? '';
				$pdf_url  = $pdf_file['url'] ?? '';
			}
		}
		$t_pdf = round( ( microtime( true ) - $t_start ) * 1000 );

		// 4. Generate download token and URL
		$download_token = self::generate_download_token( $quote_ref );
		$download_url   = function_exists( 'rest_url' )
			? rest_url( 'ups-quote/v1/download-quote?ref=' . rawurlencode( $quote_ref ) . '&token=' . $download_token )
			: ( $pdf_url ?: '' );

		// 5. Prepare email payload
		$send_email      = ! isset( $params['send_email'] ) || ! empty( $params['send_email'] );
		$lead_email_data = [
			'quote_ref'            => $quote_ref,
			'contact_name'         => $name,
			'name'                 => $name,
			'company_name'         => $company_name,
			'email'                => $email,
			'phone'                => $clean_phone ?: $phone,
			'service_code'         => $service_code,
			'service_name'         => $service_name,
			'direction'            => $direction,
			'destination_name'     => $destination_name ?: $destination_iata,
			'destination_iata'     => $destination_iata,
			'chargeable_weight_kg' => $chargeable_weight_kg,
			'weight_kg'            => $chargeable_weight_kg,
			'total_price_vnd'      => $total_price_vnd,
			'notes'                => $notes,
			'download_url'         => $download_url ?: $pdf_url,
		];

		// 6. Insert lead record into wp_ups_quote_leads
		$t_db_start = microtime( true );
		$lead_id = 0;
		if ( $this->quote_lead_repo ) {
			$lead_id = $this->quote_lead_repo->insert( [
				'quote_ref'            => $quote_ref,
				'quote_log_id'         => $quote_log_id ?: null,
				'contact_name'         => $name,
				'company_name'         => $company_name,
				'email'                => $email,
				'phone'                => $clean_phone ?: $phone,
				'source'               => 'pdf_export',
				'direction'            => $direction,
				'service_code'         => $service_code,
				'origin_country'       => $origin_country,
				'destination_iata'     => $destination_iata,
				'destination_name'     => $destination_name,
				'chargeable_weight_kg' => $chargeable_weight_kg,
				'total_price_vnd'      => $total_price_vnd,
				'quote_data_json'      => $pdf_payload,
				'pdf_path'             => $pdf_path,
				'email_sent'           => 0,
				'email_sent_at'        => null,
				'download_count'       => 1,
				'ip_address'           => $client_ip,
				'user_agent'           => ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( (string) $_SERVER['HTTP_USER_AGENT'] ), 0, 255 ) : '',
			] );
		}

		// 7. Sync back to quote log if quote_log_id exists, OR create new quote log entry
		if ( $this->quote_log_repo ) {
			$contact_info = [
				'name'         => $name,
				'phone'        => $clean_phone ?: $phone,
				'email'        => $email,
				'company'      => $company_name,
				'company_name' => $company_name,
				'notes'        => $notes,
				'quote_ref'    => $quote_ref,
				'pdf_url'      => $pdf_url,
				'source'       => 'pdf_export',
				'type'         => 'pdf_export',
				'lead_id'      => $lead_id,
			];

			if ( $quote_log_id > 0 ) {
				$log_record = $this->quote_log_repo->get( $quote_log_id );
				if ( $log_record ) {
					$bd                = is_array( $log_record->breakdown ) ? $log_record->breakdown : [];
					$bd['contact']     = $contact_info;
					$bd['lead']        = $contact_info;
					$bd['lead_source'] = 'ups_quote_pdf_export';
					$bd['type']        = 'pdf_export';
					$this->quote_log_repo->update( $quote_log_id, [ 'breakdown_json' => $bd ] );
				}
			} else {
				$device_type = $this->detect_device_type();
				$user_agent  = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( (string) $_SERVER['HTTP_USER_AGENT'] ), 0, 255 ) : '';

				$rate_card_id = 0;
				if ( $this->rate_card_repo ) {
					if ( $service_code && method_exists( $this->rate_card_repo, 'get_active_for_service' ) ) {
						$active_card = $this->rate_card_repo->get_active_for_service( $service_code, $direction );
					} else {
						$active_card = $this->rate_card_repo->get_active();
					}
					$rate_card_id = $active_card ? (int) $active_card->id : 1;
				}
				if ( ! $rate_card_id ) {
					$rate_card_id = 1;
				}

				$zone      = ! empty( $params['zone'] ) ? sanitize_text_field( $params['zone'] ) : null;
				$rate_zone = ! empty( $params['rate_zone'] ) ? sanitize_text_field( $params['rate_zone'] ) : null;
				if ( ( empty( $zone ) || empty( $rate_zone ) ) && $destination_iata && $service_code && class_exists( 'Allship_UPS_Zone_Resolver' ) ) {
					$zone_resolver = new Allship_UPS_Zone_Resolver( $this->rate_card_repo, $this->country_repo );
					$zone_res      = $zone_resolver->resolve( $destination_iata, $service_code, $direction, $rate_card_id );
					if ( $zone_res && $zone_res->success ) {
						if ( empty( $zone ) ) {
							$zone = $zone_res->zone;
						}
						if ( empty( $rate_zone ) ) {
							$rate_zone = $zone_res->rate_zone;
						}
					}
				}

				$new_log_data = [
					'rate_card_id'         => $rate_card_id,
					'session_id'           => $quote_ref,
					'ip_address'           => $client_ip,
					'device_type'          => $device_type,
					'user_agent'           => $user_agent,
					'direction'            => $direction,
					'origin_iata'          => $origin_country ?: 'VN',
					'origin_province'      => ! empty( $params['origin'] ) ? sanitize_text_field( $params['origin'] ) : 'TP. Hồ Chí Minh',
					'destination_iata'     => $destination_iata,
					'destination_address'  => $destination_name,
					'service_code'         => $service_code,
					'shipment_type'        => ! empty( $params['shipment_type'] ) ? sanitize_text_field( $params['shipment_type'] ) : 'nondoc',
					'zone'                 => $zone,
					'rate_zone'            => $rate_zone,
					'actual_weight_kg'     => $actual_weight_kg,
					'dim_weight_kg'        => $dim_weight_kg,
					'chargeable_weight_kg' => $chargeable_weight_kg,
					'base_price_vnd'       => $base_price_vnd,
					'total_price_vnd'      => $total_price_vnd,
					'pieces_json'          => $pieces,
					'breakdown_json'       => [
						'contact'      => $contact_info,
						'lead'         => $contact_info,
						'lead_source'  => 'ups_quote_pdf_export',
						'type'         => 'pdf_export',
						'fsc_percent'  => $fsc_percent,
						'fsc_amount'   => $fsc_amount_vnd,
						'surge_amount' => $surge_amount_vnd,
						'customs_fee'  => $customs_fee_vnd,
						'vat_rate'     => $vat_rate,
						'vat_amount'   => $vat_amount_vnd,
					],
				];

				$quote_log_id = (int) $this->quote_log_repo->insert( $new_log_data );

				if ( $quote_log_id > 0 && $lead_id > 0 && $this->quote_lead_repo ) {
					$this->quote_lead_repo->update( $lead_id, [ 'quote_log_id' => $quote_log_id ] );
				}
			}
		}

		// 8. Bridge into FluentForm submissions table
		$ff_entry_id = 0;
		if ( class_exists( 'Allship_UPS_FluentForm_Bridge' ) ) {
			$bridge_payload = [
				'name'                 => $name,
				'email'                => $email,
				'phone'                => $clean_phone ?: $phone,
				'company_name'         => $company_name,
				'quote_ref'            => $quote_ref,
				'pdf_url'              => $pdf_url,
				'download_url'         => $download_url,
				'notes'                => $notes,
				'service_code'         => $service_code,
				'service'              => $service_name,
				'direction'            => $direction,
				'origin'               => $origin_country,
				'destination'          => $destination_name ?: $destination_iata,
				'chargeable_weight'    => $chargeable_weight_kg,
				'chargeable_weight_kg' => $chargeable_weight_kg,
				'total_price'          => $total_price_vnd,
				'total_price_vnd'      => $total_price_vnd,
				'hidden_source'        => 'ups_quote_pdf_export',
				'quote_log_id'         => $quote_log_id ?: null,
			];
			$ff_bridge   = new Allship_UPS_FluentForm_Bridge();
			$ff_entry_id = $ff_bridge->push_lead_to_fluentform( $bridge_payload );

			if ( $quote_log_id > 0 && $ff_entry_id > 0 && $this->quote_log_repo ) {
				$log_record = $this->quote_log_repo->get( $quote_log_id );
				if ( $log_record ) {
					$bd = is_array( $log_record->breakdown ) ? $log_record->breakdown : [];
					$bd['contact']['ff_entry_id'] = $ff_entry_id;
					$this->quote_log_repo->update( $quote_log_id, [ 'breakdown_json' => $bd ] );
				}
			}
		}

		// 8. Dispatch emails asynchronously (zero UI wait time!)
		$this->dispatch_async_email( 'export_quote', [
			'quote_ref'       => $quote_ref,
			'lead_email_data' => $lead_email_data,
			'pdf_path'        => $pdf_path,
			'send_email'      => $send_email,
			'lead_id'         => $lead_id,
		] );

		$response_payload = [
			'quote_ref'    => $quote_ref,
			'download_url' => $download_url,
			'pdf_url'      => $pdf_url,
			'lead_id'      => $lead_id,
			'quote_log_id' => $quote_log_id ?: null,
			'ff_entry_id'  => $ff_entry_id ?: null,
			'email_sent'   => (bool) ( $send_email && ! empty( $email ) ),
			'message'      => 'Báo giá đã được tạo thành công và gửi tới email của bạn.',
		];

		return $this->success_response( $response_payload );
	}

	/**
	 * GET /download-quote — Stream or download generated PDF quotation.
	 *
	 * @param WP_REST_Request|object $request REST request object.
	 * @return WP_REST_Response|void
	 */
	public function download_quote( $request ) {
		$params = is_object( $request ) && method_exists( $request, 'get_params' )
			? $request->get_params()
			: (array) $request;

		$ref = ! empty( $params['ref'] ) ? sanitize_text_field( $params['ref'] ) : '';

		if ( empty( $ref ) || ! $this->quote_lead_repo ) {
			return $this->error_response( 'NOT_FOUND', 'Không tìm thấy mã báo giá.', 404 );
		}

		$lead = $this->quote_lead_repo->get_by_quote_ref( $ref );
		if ( ! $lead ) {
			return $this->error_response( 'NOT_FOUND', 'Không tìm thấy thông tin báo giá.', 404 );
		}

		if ( empty( $lead->pdf_path ) || ! file_exists( $lead->pdf_path ) ) {
			return $this->error_response( 'FILE_NOT_FOUND', 'File báo giá không tồn tại hoặc đã bị xóa.', 404 );
		}

		// Security token check (mandatory for downloading quote files)
		$expected = self::generate_download_token( $ref );
		if ( empty( $params['token'] ) || ! hash_equals( $expected, (string) $params['token'] ) ) {
			return $this->error_response( 'FORBIDDEN', 'Token bảo mật không hợp lệ hoặc đã hết hạn.', 403 );
		}

		// Increment download counter
		if ( ! empty( $lead->id ) ) {
			$this->quote_lead_repo->increment_download( (int) $lead->id );
		}

		$file_path = $lead->pdf_path;
		$filename  = basename( $file_path );

		if ( defined( 'ALLSHIP_TESTING' ) && ALLSHIP_TESTING ) {
			return $this->success_response( [
				'downloaded' => true,
				'quote_ref'  => $ref,
				'pdf_path'   => $file_path,
				'filesize'   => filesize( $file_path ),
			] );
		}

		if ( ! headers_sent() ) {
			header( 'Content-Description: File Transfer' );
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
			header( 'Pragma: public' );
			header( 'Content-Length: ' . filesize( $file_path ) );
		}

		readfile( $file_path );
		exit;
	}

	/**
	 * Get Vietnamese display name for a service code.
	 *
	 * @param string $service_code Service code (e.g. WXS, XPR).
	 * @return string Human-friendly service name.
	 */
	public function get_service_name_vi( $service_code ) {
		$code = strtoupper( trim( (string) $service_code ) );
		$map  = [
			'EXW' => 'UPS Express Early',
			'XPR' => 'UPS Express Plus',
			'WXS' => 'UPS Express Saver',
			'XPD' => 'UPS Expedited',
			'WXP' => 'UPS Express Freight',
			'WFM' => 'UPS Freight Midday',
		];
		return $map[ $code ] ?? ( 'UPS ' . $code );
	}

	/**
	 * Get estimated transit time for a service code.
	 *
	 * @param string $service_code Service code.
	 * @return string Transit time description.
	 */
	public function get_service_transit_time( $service_code ) {
		$code = strtoupper( trim( (string) $service_code ) );
		$map  = [
			'EXW' => '1 - 2 ngày làm việc (Trước 9:00 AM)',
			'XPR' => '1 - 2 ngày làm việc (Trước 12:00 PM)',
			'WXS' => '1 - 3 ngày làm việc (Nhanh nhất)',
			'XPD' => '3 - 5 ngày làm việc (Tiết kiệm)',
			'WXP' => '1 - 3 ngày làm việc (Hàng nặng > 70kg)',
			'WFM' => '2 - 4 ngày làm việc (Hàng pallet/freight)',
		];
		return $map[ $code ] ?? '1 - 3 ngày làm việc';
	}

	/**
	 * GET /countries — Retrieve active destination countries.
	 *
	 * When service_code is provided, attaches the specific zone for that service.
	 *
	 * @param WP_REST_Request|object $request REST request object.
	 * @return WP_REST_Response
	 */
	public function get_countries( $request ) {
		global $wpdb;

		$params       = is_object( $request ) && method_exists( $request, 'get_params' ) ? $request->get_params() : (array) $request;
		$direction    = ! empty( $params['direction'] ) ? strtolower( trim( (string) $params['direction'] ) ) : 'export';
		$service_code = ! empty( $params['service_code'] ) ? strtoupper( trim( (string) $params['service_code'] ) ) : null;
		$rate_card_id = ! empty( $params['rate_card_id'] ) ? absint( $params['rate_card_id'] ) : 0;

		if ( ! $rate_card_id && $this->rate_card_repo ) {
			$active_card = null;
			if ( $service_code && method_exists( $this->rate_card_repo, 'get_active_for_service' ) ) {
				$active_card = $this->rate_card_repo->get_active_for_service( $service_code, $direction );
			}
			if ( empty( $active_card ) ) {
				$active_card = $this->rate_card_repo->get_active();
			}
			$rate_card_id = $active_card ? (int) $active_card->id : 1;
		}

		$card         = $this->rate_card_repo ? $this->rate_card_repo->get( $rate_card_id ) : null;
		$target_zs_id = ( $card && ! empty( $card->zone_set_id ) ) ? (int) $card->zone_set_id : $rate_card_id;

		$cache_key = 'allship_ups_countries_' . md5( $direction . '_' . ( $service_code ?: 'all' ) . '_' . $rate_card_id . '_' . $target_zs_id );
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $this->success_response( $cached );
			}
		}

		$table_countries = $wpdb ? $wpdb->prefix . 'ups_countries' : 'wp_ups_countries';
		$table_zones     = $wpdb ? $wpdb->prefix . 'ups_zone_maps' : 'wp_ups_zone_maps';

		if ( $service_code ) {
			// Resolve virtual services to physical zone mappings
			$zone_lookup_service = $service_code;
			if ( in_array( $service_code, [ 'EXW', 'XPR' ], true ) ) {
				$zone_lookup_service = 'WXS';
			} elseif ( 'WXP' === $service_code ) {
				$zone_lookup_service = 'WFM';
			}

			if ( $wpdb ) {
				$sql = "SELECT c.iata_code, c.country_name, c.normalized_name, c.is_us_override, c.has_extended_area_note,
				               zm.zone, zm.is_available
				        FROM {$table_countries} c
				        LEFT JOIN {$table_zones} zm ON zm.country_id = c.id
				             AND (zm.zone_set_id = %d OR zm.rate_card_id = %d)
				             AND zm.direction = %s
				             AND zm.service_code = %s
				        WHERE c.is_active = 1
				        ORDER BY c.country_name ASC";

				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $target_zs_id, $target_zs_id, $direction, $zone_lookup_service ) );
			} else {
				$rows = [];
			}

			$data = [];
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$zone_val = null !== $row->zone && '' !== trim( (string) $row->zone ) ? (string) $row->zone : '0';
					$data[]   = [
						'iata_code'              => $row->iata_code,
						'country_name'           => $row->country_name,
						'iata'                   => $row->iata_code,
						'name'                   => $row->country_name,
						'zone'                   => $zone_val,
						'is_available'           => (int) ( isset( $row->is_available ) ? $row->is_available : ( (int) $zone_val > 0 ? 1 : 0 ) ),
						'is_us_override'         => (int) $row->is_us_override,
						'has_extended_area_note' => (int) $row->has_extended_area_note,
					];
				}
			}

			if ( function_exists( 'set_transient' ) && ! empty( $data ) ) {
				set_transient( $cache_key, $data, 3600 );
			}

			return $this->success_response( $data );
		}

		// When no service_code is given, return countries with mapped wxs, xpd, wfm zones
		if ( $wpdb ) {
			$sql = "SELECT c.iata_code, c.country_name, c.normalized_name, c.is_us_override, c.has_extended_area_note,
			               MAX(CASE WHEN zm.service_code = 'WXS' THEN zm.zone ELSE NULL END) as wxs,
			               MAX(CASE WHEN zm.service_code = 'XPD' THEN zm.zone ELSE NULL END) as xpd,
			               MAX(CASE WHEN zm.service_code = 'WFM' THEN zm.zone ELSE NULL END) as wfm
			        FROM {$table_countries} c
			        LEFT JOIN {$table_zones} zm ON zm.country_id = c.id
			             AND zm.rate_card_id = %d
			             AND zm.direction = %s
			        WHERE c.is_active = 1
			        GROUP BY c.id
			        ORDER BY c.country_name ASC";

			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $rate_card_id, $direction ) );
		} else {
			$rows = [];
		}

		$data = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$data[] = [
					'iata_code'              => $row->iata_code,
					'country_name'           => $row->country_name,
					'iata'                   => $row->iata_code,
					'name'                   => $row->country_name,
					'wxs'                    => (int) ( $row->wxs ?: 0 ),
					'xpd'                    => (int) ( $row->xpd ?: 0 ),
					'wfm'                    => (int) ( $row->wfm ?: 0 ),
					'is_us_override'         => (int) $row->is_us_override,
					'has_extended_area_note' => (int) $row->has_extended_area_note,
				];
			}
		}

		if ( function_exists( 'set_transient' ) && ! empty( $data ) ) {
			set_transient( $cache_key, $data, 3600 );
		}

		return $this->success_response( $data );
	}

	/**
	 * GET /services — Retrieve the complete 6 services catalog with V4 metadata.
	 *
	 * @param WP_REST_Request|object $request REST request object.
	 * @return WP_REST_Response
	 */
	public function get_services( $request ) {
		global $wpdb;

		$params       = is_object( $request ) && method_exists( $request, 'get_params' ) ? $request->get_params() : (array) $request;
		$direction       = ! empty( $params['direction'] ) ? strtolower( trim( (string) $params['direction'] ) ) : 'export';
		$rate_card_id    = ! empty( $params['rate_card_id'] ) ? absint( $params['rate_card_id'] ) : 0;
		$active_ids      = [];
		$disabled_groups = [];

		if ( $rate_card_id ) {
			$active_ids = [ $rate_card_id ];
			$card       = $this->rate_card_repo ? $this->rate_card_repo->get( $rate_card_id ) : null;
			if ( $card && ! empty( $card->disabled_rate_groups_array ) ) {
				$disabled_groups = $card->disabled_rate_groups_array;
			}
		} elseif ( $this->rate_card_repo ) {
			$active_cards = method_exists( $this->rate_card_repo, 'get_all_active' )
				? $this->rate_card_repo->get_all_active()
				: ( $this->rate_card_repo->get_active() ? [ $this->rate_card_repo->get_active() ] : [] );

			foreach ( $active_cards as $c ) {
				$active_ids[] = (int) $c->id;
				if ( ! empty( $c->disabled_rate_groups_array ) ) {
					$disabled_groups = array_merge( $disabled_groups, $c->disabled_rate_groups_array );
				}
			}
			$disabled_groups = array_values( array_unique( $disabled_groups ) );
		}

		if ( empty( $active_ids ) ) {
			$active_ids = [ 1 ];
		}

		// Query rate group row counts from ups_rates
		$table_rates = $wpdb ? $wpdb->prefix . 'ups_rates' : 'wp_ups_rates';
		$row_counts  = [];

		if ( $wpdb && ! empty( $active_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $active_ids ), '%d' ) );
			$counts_raw   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT rate_group, COUNT(*) as count FROM {$table_rates} WHERE rate_card_id IN ($placeholders) GROUP BY rate_group",
					$active_ids
				)
			);
			if ( is_array( $counts_raw ) ) {
				foreach ( $counts_raw as $cnt ) {
					$row_counts[ $cnt->rate_group ] = (int) $cnt->count;
				}
			}
		}

		// Full 6 Services Catalog with V4 UI / UX metadata
		$catalog = [
			'EXW' => [
				'code'               => 'EXW',
				'name'               => 'Express Early',
				'cat'                => 'parcel',
				'has_document_split' => true,
				'icon'               => 'ph-globe',
				'icon_bg'            => '#FEF3C7',
				'icon_color'         => '#D97706',
				'desc_vi'            => 'Sớm · 1-2 ngày',
				'eta_vi'             => 'Trước 9:00 AM',
				'badge_text'         => 'Sớm',
				'badge_color'        => 'amber',
				'rate_groups'        => [
					'export' => [ 'export_exw_document', 'export_exw_nondocument' ],
					'import' => [ 'import_exw_document', 'import_exw_nondocument' ],
				],
			],
			'XPR' => [
				'code'               => 'XPR',
				'name'               => 'Express Plus',
				'cat'                => 'parcel',
				'has_document_split' => true,
				'icon'               => 'ph-rocket-launch',
				'icon_bg'            => '#EDE9FE',
				'icon_color'         => '#7C3AED',
				'desc_vi'            => 'Ưu tiên · 1-2 ngày',
				'eta_vi'             => 'Trước 12:00 PM',
				'badge_text'         => 'Ưu tiên',
				'badge_color'        => 'purple',
				'rate_groups'        => [
					'export' => [ 'export_xpr_document', 'export_xpr_nondocument' ],
					'import' => [ 'import_xpr_document', 'import_xpr_nondocument' ],
				],
			],
			'WXS' => [
				'code'               => 'WXS',
				'name'               => 'Express Saver',
				'cat'                => 'parcel',
				'has_document_split' => true,
				'icon'               => 'ph-airplane-tilt',
				'icon_bg'            => '#FEF3C7',
				'icon_color'         => '#D97706',
				'desc_vi'            => 'Nhanh nhất · 1-3 ngày',
				'eta_vi'             => '1-3 ngày',
				'badge_text'         => 'Phổ biến',
				'badge_color'        => 'amber',
				'rate_groups'        => [
					'export' => [ 'export_wxs_document', 'export_wxs_nondocument' ],
					'import' => [ 'import_wxs_document', 'import_wxs_nondocument' ],
				],
			],
			'XPD' => [
				'code'               => 'XPD',
				'name'               => 'Expedited',
				'cat'                => 'parcel',
				'has_document_split' => false,
				'icon'               => 'ph-truck',
				'icon_bg'            => '#E0E7FF',
				'icon_color'         => '#4338CA',
				'desc_vi'            => 'Tiết kiệm · 3-5 ngày',
				'eta_vi'             => '3-5 ngày',
				'badge_text'         => 'Tiết kiệm',
				'badge_color'        => 'indigo',
				'rate_groups'        => [
					'export' => [ 'export_xpd' ],
					'import' => [ 'import_xpd' ],
				],
			],
			'WXP' => [
				'code'               => 'WXP',
				'name'               => 'Express Freight',
				'cat'                => 'freight',
				'has_document_split' => false,
				'icon'               => 'ph-lightning',
				'icon_bg'            => '#FEE2E2',
				'icon_color'         => '#DC2626',
				'desc_vi'            => 'Hỏa tốc trên 70kg',
				'eta_vi'             => '1-2 ngày',
				'badge_text'         => 'Hỏa tốc nặng',
				'badge_color'        => 'rose',
				'rate_groups'        => [
					'export' => [ 'export_wxp' ],
					'import' => [ 'import_wxp' ],
				],
			],
			'WFM' => [
				'code'               => 'WFM',
				'name'               => 'Freight Midday',
				'cat'                => 'freight',
				'has_document_split' => false,
				'icon'               => 'ph-crane',
				'icon_bg'            => '#ECFDF5',
				'icon_color'         => '#059669',
				'desc_vi'            => 'Tiêu chuẩn trên 70kg',
				'eta_vi'             => '3-5 ngày',
				'badge_text'         => 'Tiết kiệm nặng',
				'badge_color'        => 'emerald',
				'rate_groups'        => [
					'export' => [ 'export_wfm' ],
					'import' => [ 'import_wfm' ],
				],
			],
		];

		$services_response = [];

		foreach ( $catalog as $code => $svc ) {
			$direction_groups = isset( $svc['rate_groups'][ $direction ] ) ? $svc['rate_groups'][ $direction ] : [];

			// Check admin disabled
			$admin_disabled = false;
			if ( ! empty( $direction_groups ) ) {
				$all_disabled = true;
				foreach ( $direction_groups as $group_name ) {
					if ( ! in_array( $group_name, $disabled_groups, true ) ) {
						$all_disabled = false;
						break;
					}
				}
				$admin_disabled = $all_disabled;
			}

			// Check direct data availability
			$svc_counts = [];
			$total_rows = 0;
			foreach ( $direction_groups as $group_name ) {
				$count                  = isset( $row_counts[ $group_name ] ) ? $row_counts[ $group_name ] : 0;
				$svc_counts[ $group_name ] = $count;
				$total_rows            += $count;
			}
			$has_direct_data = $total_rows > 0;
			$has_data        = $has_direct_data;

			$enabled = ! $admin_disabled;
			$reason  = null;

			// Base service data checks for multiplier-derived services
			$wxs_has_data = ( ( $row_counts["{$direction}_wxs_document"] ?? 0 ) + ( $row_counts["{$direction}_wxs_nondocument"] ?? 0 ) ) > 0;
			$wfm_has_data = ( $row_counts["{$direction}_wfm"] ?? 0 ) > 0;

			if ( in_array( $code, [ 'EXW', 'XPR' ], true ) ) {
				// Can use direct table or multiplier from WXS
				if ( ! $has_direct_data ) {
					$has_data = $wxs_has_data;
				}
			} elseif ( 'WXP' === $code ) {
				// Can use direct table or multiplier from WFM
				if ( ! $has_direct_data ) {
					$has_data = $wfm_has_data;
				}
			}

			if ( $admin_disabled ) {
				$enabled = false;
				$reason  = 'Admin đã tắt dịch vụ này.';
			} elseif ( ! $has_data ) {
				$enabled = false;
				if ( in_array( $code, [ 'EXW', 'XPR' ], true ) && ! $wxs_has_data ) {
					$reason = 'Chưa có bảng giá Express Saver (WXS) để tính cước.';
				} elseif ( 'WXP' === $code && ! $wfm_has_data ) {
					$reason = 'Chưa có bảng giá Freight (WFM) để tính cước.';
				} else {
					$reason = 'Chưa có bảng giá trong rate card hiện tại.';
				}
			}

			$services_response[] = [
				'code'               => $svc['code'],
				'name'               => $svc['name'],
				'enabled'            => (bool) $enabled,
				'has_data'           => (bool) $has_data,
				'has_document_split' => (bool) $svc['has_document_split'],
				'cat'                => $svc['cat'],
				'icon'               => $svc['icon'],
				'icon_bg'            => $svc['icon_bg'],
				'icon_color'         => $svc['icon_color'],
				'desc_vi'            => $svc['desc_vi'],
				'eta_vi'             => $svc['eta_vi'],
				'badge_text'         => $svc['badge_text'],
				'badge_color'        => $svc['badge_color'],
				'admin_disabled'     => $admin_disabled,
				'reason'             => $reason,
				'row_counts'         => $svc_counts,
			];
		}

		return $this->success_response( $services_response );
	}

	/**
	 * GET /directions — Retrieve available transport directions.
	 *
	 * @param WP_REST_Request|object $request REST request object.
	 * @return WP_REST_Response
	 */
	public function get_directions( $request ) {
		$params       = is_object( $request ) && method_exists( $request, 'get_params' ) ? $request->get_params() : (array) $request;
		$rate_card_id = ! empty( $params['rate_card_id'] ) ? absint( $params['rate_card_id'] ) : null;

		$available_directions = $this->service_mgr
			? $this->service_mgr->get_available_directions( $rate_card_id )
			: [ 'export' ];

		$directions = [
			[
				'direction'     => 'export',
				'label_vi'      => 'Xuất hàng — Việt Nam → Quốc tế',
				'label_short'   => 'Xuất hàng',
				'enabled'       => in_array( 'export', $available_directions, true ),
				'service_count' => 6,
			],
			[
				'direction'     => 'import',
				'label_vi'      => 'Nhập hàng — Quốc tế → Việt Nam',
				'label_short'   => 'Nhập hàng',
				'enabled'       => in_array( 'import', $available_directions, true ),
				'service_count' => in_array( 'import', $available_directions, true ) ? 6 : 0,
			],
		];

		return $this->success_response( $directions );
	}

	/**
	 * Helper: Return standard success response.
	 *
	 * @param mixed $data Payload.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response
	 */
	private function success_response( $data, $status = 200 ) {
		if ( class_exists( 'WP_REST_Response' ) ) {
			return new WP_REST_Response(
				[
					'success' => true,
					'data'    => $data,
				],
				$status
			);
		}

		return [
			'success' => true,
			'data'    => $data,
		];
	}

	/**
	 * Output JSON response immediately and terminate HTTP connection via fastcgi_finish_request,
	 * allowing subsequent time-consuming operations (such as wp_mail / SMTP delivery)
	 * to run asynchronously in the background worker.
	 *
	 * @param mixed $data Response payload.
	 * @param int   $status HTTP status code.
	 * @return bool True if connection was closed early, false otherwise.
	 */
	private function finish_request_early( $data, $status = 200 ) {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			if ( ! headers_sent() ) {
				status_header( $status );
				header( 'Content-Type: application/json; charset=' . ( function_exists( 'get_option' ) ? get_option( 'blog_charset', 'utf-8' ) : 'utf-8' ) );
			}
			echo wp_json_encode( [
				'success' => true,
				'data'    => $data,
			] );

			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			flush();

			fastcgi_finish_request();

			ignore_user_abort( true );
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 );
			}

			return true;
		}

		return false;
	}

	/**
	 * Dispatch email processing to background non-blocking worker.
	 * Returns immediately so client UI never waits for SMTP delivery.
	 *
	 * @param string $action 'export_quote' or 'booking'.
	 * @param array  $payload Data required for email delivery.
	 * @return void
	 */
	public function dispatch_async_email( $action, array $payload ) {
		// If running in CLI / Unit tests, execute immediately for deterministic assertions
		if ( php_sapi_name() === 'cli' || defined( 'ALLSHIP_TESTING' ) ) {
			$this->execute_email_job( $action, $payload );
			return;
		}

		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'allship_secret';
		$token  = hash_hmac( 'sha256', $action . '|' . ( $payload['quote_ref'] ?? $payload['lead_id'] ?? '' ), $secret );
		$payload['_async_token']  = $token;
		$payload['_async_action'] = $action;

		// 1. Primary: Non-blocking HTTP loopback to /async-mail (returns in ~10-20ms)
		/*
		if ( function_exists( 'rest_url' ) && function_exists( 'wp_remote_post' ) ) {
			$async_url = rest_url( 'ups-quote/v1/async-mail' );
			wp_remote_post(
				$async_url,
				[
					'method'    => 'POST',
					'timeout'   => 0.1,
					'blocking'  => false,
					'sslverify' => false,
					'headers'   => [ 'Content-Type' => 'application/json' ],
					'body'      => wp_json_encode( $payload ),
				]
			);
		}
		*/

		// Secondary fallback: Schedule single WP-Cron event in case loopback is blocked on certain server environments
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time(), 'allship_ups_async_send_email', [ $action, $payload ] );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Internal REST endpoint to process background email sending.
	 *
	 * @param WP_REST_Request|object $request
	 * @return WP_REST_Response
	 */
	public function process_async_mail( $request ) {
		$params = is_object( $request ) && method_exists( $request, 'get_params' ) ? $request->get_params() : (array) $request;
		$action = $params['_async_action'] ?? '';
		$token  = $params['_async_token'] ?? '';
		$ref    = $params['quote_ref'] ?? $params['lead_id'] ?? '';
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'allship_secret';
		$expected = hash_hmac( 'sha256', $action . '|' . $ref, $secret );

		if ( empty( $token ) || ! hash_equals( $expected, (string) $token ) ) {
			return $this->error_response( 'UNAUTHORIZED', 'Invalid async token', 403 );
		}

		// Prevent duplicate processing if WP-Cron also fires
		$dedup_key = 'allship_mail_done_' . md5( $action . '_' . $ref );
		if ( function_exists( 'get_transient' ) && get_transient( $dedup_key ) ) {
			return $this->success_response( [ 'skipped' => true ] );
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $dedup_key, 1, 300 );
		}

		$this->execute_email_job( $action, $params );

		return $this->success_response( [ 'dispatched' => true ] );
	}

	/**
	 * Cron hook callback for background email processing.
	 *
	 * @param string $action
	 * @param array  $payload
	 * @return void
	 */
	public function handle_async_email_event( $action, $payload ) {
		$ref = $payload['quote_ref'] ?? $payload['lead_id'] ?? '';
		$dedup_key = 'allship_mail_done_' . md5( $action . '_' . $ref );
		if ( function_exists( 'get_transient' ) && get_transient( $dedup_key ) ) {
			return;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $dedup_key, 1, 300 );
		}
		$this->execute_email_job( $action, $payload );
	}

	/**
	 * Core email execution routine.
	 *
	 * @param string $action
	 * @param array  $params
	 * @return void
	 */
	public function execute_email_job( $action, array $params ) {
		if ( 'export_quote' === $action ) {
			$lead_email_data = $params['lead_email_data'] ?? [];
			$pdf_path        = $params['pdf_path'] ?? '';
			$send_email      = ! empty( $params['send_email'] );
			$lead_id         = (int) ( $params['lead_id'] ?? 0 );

			if ( $send_email && $this->quote_mailer && ! empty( $lead_email_data['email'] ) ) {
				$sent = $this->quote_mailer->send_quote_to_customer( $lead_email_data, $pdf_path );
				if ( $sent && $lead_id > 0 && $this->quote_lead_repo ) {
					$this->quote_lead_repo->update( $lead_id, [
						'email_sent'    => 1,
						'email_sent_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
					] );
				}
			}

			if ( $this->quote_mailer ) {
				$this->quote_mailer->send_lead_alert_to_admin( $lead_email_data, $pdf_path );
			}
		} elseif ( 'booking' === $action ) {
			$lead        = $params['lead'] ?? [];
			$name        = $params['name'] ?? '';
			$clean_phone = $params['clean_phone'] ?? '';

			if ( function_exists( 'wp_mail' ) && function_exists( 'get_option' ) ) {
				$admin_email = get_option( 'admin_email' );
				if ( ! empty( $admin_email ) && is_email( $admin_email ) ) {
					$price_str = ! empty( $lead['total_price_vnd'] ) ? number_format( $lead['total_price_vnd'], 0, ',', '.' ) . ' VND' : 'Chưa có';
					$subject   = sprintf( '[Báo giá UPS] Lead đặt dịch vụ từ %s (%s)', $name, $clean_phone );
					$message   = "Khách hàng gửi yêu cầu tư vấn báo giá UPS:\n\n" .
						"Họ tên: {$name}\n" .
						"Số điện thoại: {$clean_phone}\n" .
						"Email: " . ( $lead['email'] ?? '' ) . "\n" .
						"Dịch vụ: " . ( $lead['service_code'] ?? '' ) . "\n" .
						"Tuyến: " . ( $lead['route_summary'] ?? '' ) . "\n" .
						"Tổng cước tạm tính: {$price_str}\n" .
						"Ghi chú: " . ( $lead['notes'] ?? '' ) . "\n" .
						"Thời gian: " . ( $lead['created_at'] ?? '' ) . "\n";
					wp_mail( $admin_email, $subject, $message );
				}
			}
		}
	}

	/**
	 * Helper: Return standard error response.
	 *
	 * @param string $code Standard error code.
	 * @param string $message User-friendly explanation.
	 * @param int    $status HTTP status code.
	 * @return WP_REST_Response
	 */
	private function error_response( $code, $message, $status = 422 ) {
		$payload = [
			'success' => false,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];

		if ( class_exists( 'WP_REST_Response' ) ) {
			return new WP_REST_Response( $payload, $status );
		}

		return $payload;
	}

	/**
	 * Map standardized error code to appropriate HTTP status.
	 *
	 * @param string $error_code Error code string.
	 * @return int HTTP status code.
	 */
	private function get_http_status( $error_code ) {
		$map = [
			'INVALID_INPUT'         => 422,
			'SERVICE_NOT_SUPPORTED' => 422,
			'UNKNOWN_DESTINATION'   => 422,
			'LANE_NOT_AVAILABLE'    => 422,
			'RATE_NOT_FOUND'        => 422,
			'DOCUMENT_OVER_5KG'     => 422,
			'NO_ACTIVE_RATE_CARD'   => 422,
			'RATE_CARD_NOT_FOUND'   => 422,
			'INVALID_RATE_FILE'     => 400,
			'QUOTE_INTERNAL_ERROR'  => 500,
			'RATE_LIMIT_COOLDOWN'   => 429,
			'RATE_LIMIT_EXCEEDED'   => 429,
		];

		return isset( $map[ $error_code ] ) ? $map[ $error_code ] : 422;
	}

	/**
	 * Generate secure HMAC download token for a quote reference.
	 *
	 * @param string $quote_ref Quote reference code.
	 * @return string 16-character hex token.
	 */
	public static function generate_download_token( $quote_ref ) {
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'allship_quote_download';
		return substr( hash_hmac( 'sha256', (string) $quote_ref, $secret ), 0, 16 );
	}
}
