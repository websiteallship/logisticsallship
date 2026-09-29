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
	 */
	public function __construct(
		$calculator = null,
		$country_repo = null,
		$rate_card_repo = null,
		$zone_repo = null,
		$rate_repo = null,
		$service_mgr = null,
		$quote_log_repo = null,
		$settings_mgr = null
	) {
		$this->rate_card_repo = $rate_card_repo ?: ( class_exists( 'Allship_UPS_Rate_Card_Repository' ) ? new Allship_UPS_Rate_Card_Repository() : null );
		$this->country_repo   = $country_repo ?: ( class_exists( 'Allship_UPS_Country_Repository' ) ? new Allship_UPS_Country_Repository() : null );
		$this->zone_repo      = $zone_repo ?: ( class_exists( 'Allship_UPS_Zone_Repository' ) ? new Allship_UPS_Zone_Repository() : null );
		$this->rate_repo      = $rate_repo ?: ( class_exists( 'Allship_UPS_Rate_Repository' ) ? new Allship_UPS_Rate_Repository() : null );
		$this->service_mgr    = $service_mgr ?: ( class_exists( 'Allship_UPS_Service_Availability_Manager' ) ? new Allship_UPS_Service_Availability_Manager( $this->rate_card_repo ) : null );
		$this->quote_log_repo = $quote_log_repo ?: ( class_exists( 'Allship_UPS_Quote_Log_Repository' ) ? new Allship_UPS_Quote_Log_Repository() : null );
		$this->settings_mgr   = $settings_mgr ?: ( class_exists( 'Allship_UPS_Settings_Manager' ) ? new Allship_UPS_Settings_Manager() : null );
		$this->calculator     = $calculator ?: ( class_exists( 'Allship_UPS_Quote_Calculator' ) ? new Allship_UPS_Quote_Calculator(
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
		$cache_key     = 'ups_calc_v2_' . md5( (string) $cache_payload );

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
							'volumetric_weight_kg' => $arr['volumetric_weight_kg'] ?? 0,
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

			// Single consolidated quote log entry for the user's calculation request
			if ( $this->quote_log_repo && $best_res_obj && ! empty( $best_price ) ) {
				try {
					$this->quote_log_repo->insert( [
						'rate_card_id'            => $best_res_obj->rate_card_id,
						'ip_address'              => $client_ip,
						'device_type'             => $device_type,
						'user_agent'              => $user_agent,
						'direction'               => $calc_input['direction'],
						'origin_iata'             => $calc_input['origin_iata'],
						'origin_province'         => $calc_input['origin_province'],
						'destination_iata'        => $calc_input['destination_iata'],
						'destination_state'       => $calc_input['destination_state'],
						'destination_city'        => $calc_input['destination_city'],
						'destination_postal_code' => $calc_input['destination_postal_code'],
						'destination_address'     => $calc_input['destination_address'],
						'service_code'            => $best_service ?: 'ALL',
						'shipment_type'           => $calc_input['shipment_type'],
						'zone'                    => $best_res_obj->zone,
						'rate_zone'               => $best_res_obj->rate_zone,
						'actual_weight_kg'        => $metrics['actual_weight_kg'] ?? $best_res_obj->actual_weight_kg,
						'dim_weight_kg'           => $metrics['volumetric_weight_kg'] ?? $best_res_obj->dim_weight_kg,
						'chargeable_weight_kg'    => $metrics['chargeable_weight_kg'] ?? $best_res_obj->chargeable_weight_kg,
						'base_price_vnd'          => $best_res_obj->base_price_vnd,
						'total_price_vnd'         => $best_price,
						'pieces_json'             => $best_res_obj->pieces,
						'breakdown_json'          => [
							'services'   => $services_res,
							'best_price' => $best_price,
							'metrics'    => $metrics,
						],
					] );
				} catch ( Exception $e ) {
					// Fail-safe: logging should not break calculation
				}
			}

			$batch_data = [
				'services'   => $services_res,
				'best_price' => $best_price,
				'metrics'    => $metrics,
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

		$clean_phone = preg_replace( '/[^0-9]/', '', $phone );
		if ( strlen( $clean_phone ) < 8 || strlen( $clean_phone ) > 15 ) {
			return $this->error_response(
				'INVALID_INPUT',
				'Số điện thoại không hợp lệ (yêu cầu từ 8 đến 15 chữ số).',
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
			'phone'            => $phone,
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

		// Attach contact details to quote log if quote_log_id is provided
		if ( ! empty( $lead['quote_log_id'] ) && $this->quote_log_repo ) {
			$existing_log = $this->quote_log_repo->get( $lead['quote_log_id'] );
			if ( $existing_log ) {
				$breakdown          = is_array( $existing_log->breakdown ) ? $existing_log->breakdown : [];
				$breakdown['lead']  = [
					'lead_id'    => $lead_id,
					'name'       => $name,
					'phone'      => $phone,
					'email'      => $lead['email'],
					'notes'      => $lead['notes'],
					'created_at' => $lead['created_at'],
				];
				$this->quote_log_repo->update(
					$lead['quote_log_id'],
					[ 'breakdown_json' => $breakdown ]
				);
			}
		}

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

		// Send email notification to site admin
		if ( function_exists( 'wp_mail' ) && function_exists( 'get_option' ) ) {
			$admin_email = get_option( 'admin_email' );
			if ( ! empty( $admin_email ) && is_email( $admin_email ) ) {
				$price_str = $lead['total_price_vnd'] ? number_format( $lead['total_price_vnd'], 0, ',', '.' ) . ' VND' : 'Chưa có';
				$subject   = sprintf( '[Báo giá UPS] Lead đặt dịch vụ từ %s (%s)', $name, $phone );
				$message   = "Khách hàng gửi yêu cầu tư vấn báo giá UPS:\n\n" .
					"Họ tên: {$name}\n" .
					"Số điện thoại: {$phone}\n" .
					"Email: {$lead['email']}\n" .
					"Dịch vụ: {$lead['service_code']}\n" .
					"Tuyến: {$lead['route_summary']}\n" .
					"Tổng cước tạm tính: {$price_str}\n" .
					"Ghi chú: {$lead['notes']}\n" .
					"Thời gian: {$lead['created_at']}\n";
				wp_mail( $admin_email, $subject, $message );
			}
		}

		return $this->success_response( [
			'message' => 'Cảm ơn bạn! Yêu cầu tư vấn đã được gửi thành công. Chuyên viên Allship sẽ liên hệ trong ít phút.',
			'lead_id' => $lead_id,
		] );
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
}
