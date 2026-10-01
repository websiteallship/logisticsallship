<?php
/**
 * Shortcode & Enqueue Manager for UPS Quote Form.
 *
 * Registers [ups_quote_form], conditionally enqueues styles and scripts
 * only when shortcode is rendered, and localizes upsQuoteConfig.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Shortcode {

	/**
	 * Rate card repository.
	 *
	 * @var Allship_UPS_Rate_Card_Repository|null
	 */
	private $rate_card_repo;

	/**
	 * Settings manager.
	 *
	 * @var Allship_UPS_Settings_Manager|null
	 */
	private $settings_mgr;

	/**
	 * Whether assets have been enqueued.
	 *
	 * @var bool
	 */
	private static $assets_enqueued = false;

	/**
	 * Country repository.
	 *
	 * @var Allship_UPS_Country_Repository|null
	 */
	private $country_repo;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Rate_Card_Repository|null $rate_card_repo Rate card repository.
	 * @param Allship_UPS_Settings_Manager|null     $settings_mgr Settings manager.
	 * @param Allship_UPS_Country_Repository|null   $country_repo Country repository.
	 */
	public function __construct( $rate_card_repo = null, $settings_mgr = null, $country_repo = null ) {
		$this->rate_card_repo = $rate_card_repo ?: ( class_exists( 'Allship_UPS_Rate_Card_Repository' ) ? new Allship_UPS_Rate_Card_Repository() : null );
		$this->settings_mgr   = $settings_mgr ?: ( class_exists( 'Allship_UPS_Settings_Manager' ) ? new Allship_UPS_Settings_Manager() : null );
		$this->country_repo   = $country_repo ?: ( class_exists( 'Allship_UPS_Country_Repository' ) ? new Allship_UPS_Country_Repository() : null );
	}

	/**
	 * Register shortcode and conditional enqueue hooks.
	 *
	 * @return void
	 */
	public function register() {
		if ( function_exists( 'add_shortcode' ) ) {
			add_shortcode( 'ups_quote_form', [ $this, 'render_shortcode' ] );
		}
		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
			add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
		}
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'template_include', [ $this, 'maybe_override_template' ], 99 );
		}
	}

	/**
	 * Register plugin styles and scripts with WordPress.
	 *
	 * @return void
	 */
	public function register_assets() {
		$plugin_url = defined( 'ALLSHIP_UPS_QUOTE_URL' ) ? ALLSHIP_UPS_QUOTE_URL : plugins_url( '/', dirname( __FILE__ ) );
		$version    = defined( 'ALLSHIP_UPS_QUOTE_VERSION' ) ? ALLSHIP_UPS_QUOTE_VERSION : '1.0.0';

		// 1. Anime.js 3.2 CDN
		wp_register_script(
			'animejs',
			'https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.2/anime.min.js',
			[],
			'3.2.2',
			true
		);

		// 2. Plugin-bundled Tailwind CSS (standalone, theme-independent, scoped)
		wp_register_style(
			'ups-quote-tailwind',
			$plugin_url . 'public/assets/css/quote-form-tw.css',
			[],
			$version
		);

		// 3. Form component CSS (vanilla, non-Tailwind styles)
		$css_suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$css_file   = file_exists( dirname( __DIR__ ) . '/public/assets/css/quote-form' . $css_suffix . '.css' )
			? 'quote-form' . $css_suffix . '.css'
			: 'quote-form.css';

		wp_register_style(
			'ups-quote-form',
			$plugin_url . 'public/assets/css/' . $css_file,
			[ 'ups-quote-tailwind' ],
			$version
		);

		// 4. Form JS
		$deps = [ 'animejs' ];
		if ( function_exists( 'wp_script_is' ) && wp_script_is( 'allship-main', 'registered' ) ) {
			$deps[] = 'allship-main';
		}

		$suffix  = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$js_file = file_exists( dirname( __DIR__ ) . '/public/assets/js/quote-form' . $suffix . '.js' )
			? 'quote-form' . $suffix . '.js'
			: 'quote-form.js';

		wp_register_script(
			'ups-quote-form',
			$plugin_url . 'public/assets/js/' . $js_file,
			$deps,
			$version,
			true
		);
	}

	/**
	 * Check current post content and enqueue assets if shortcode is present.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets() {
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		if ( function_exists( 'has_shortcode' ) && has_shortcode( $post->post_content, 'ups_quote_form' ) ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Enqueue all plugin assets and inject localized config.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( self::$assets_enqueued ) {
			return;
		}
		self::$assets_enqueued = true;

		// Ensure registered
		$this->register_assets();

		wp_enqueue_style( 'ups-quote-tailwind' );
		wp_enqueue_style( 'ups-quote-form' );
		wp_enqueue_script( 'animejs' );
		wp_enqueue_script( 'ups-quote-form' );

		$this->localize_script();
	}

	/**
	 * Inject upsQuoteConfig object into ups-quote-form script.
	 *
	 * @return void
	 */
	public function localize_script() {
		$config = $this->get_config_data();
		wp_localize_script( 'ups-quote-form', 'upsQuoteConfig', $config );
	}

	/**
	 * Build complete configuration object for client-side JavaScript.
	 *
	 * @return array
	 */
	public function get_config_data() {
		$api_base = function_exists( 'rest_url' )
			? esc_url_raw( rest_url( 'ups-quote/v1' ) )
			: '/wp-json/ups-quote/v1';

		if ( function_exists( 'wp_make_link_relative' ) ) {
			$relative_api = wp_make_link_relative( $api_base );
			if ( ! empty( $relative_api ) ) {
				$api_base = $relative_api;
			}
		}

		$states_base_url = rtrim( $api_base, '/' ) . '/geo-data/states/';
		$cities_base_url = rtrim( $api_base, '/' ) . '/geo-data/cities/';

		$nonce = function_exists( 'wp_create_nonce' )
			? wp_create_nonce( 'wp_rest' )
			: '';

		$dim_divisor  = $this->settings_mgr ? (int) $this->settings_mgr->get( 'dim_divisor', 5500 ) : 5500;
		$rounding_step = $this->settings_mgr ? (float) $this->settings_mgr->get( 'rounding_step_kg', 0.5 ) : 0.5;

		return [
			'pluginUrl'     => '',
			'statesBaseUrl' => $states_base_url,
			'citiesBaseUrl' => $cities_base_url,
			'apiBase'       => $api_base,
			'nonce'         => $nonce,
			'currency'      => 'VND',
			'COUNTRIES'     => $this->get_active_countries(),
			'VN_PROVINCES'  => $this->get_vn_provinces(),
			'POPULAR_IATA'     => [ 'US', 'AU', 'CA', 'JP', 'KR', 'TW', 'SG', 'MY', 'TH', 'GB', 'DE', 'FR', 'HK' ],
			'dim_divisor'      => $dim_divisor,
			'rounding_step'    => $rounding_step,
			'INITIAL_SERVICES' => $this->get_initial_services( 'export' ),
		];
	}

	/**
	 * Retrieve initial service availability for given direction.
	 *
	 * @param string $direction 'export' or 'import'.
	 * @return array
	 */
	public function get_initial_services( $direction = 'export' ) {
		if ( ! class_exists( 'Allship_UPS_REST_Controller' ) && file_exists( dirname( __FILE__ ) . '/class-rest-controller.php' ) ) {
			require_once dirname( __FILE__ ) . '/class-rest-controller.php';
		}
		if ( class_exists( 'Allship_UPS_REST_Controller' ) ) {
			$ctrl = new Allship_UPS_REST_Controller();
			$req  = class_exists( 'WP_REST_Request' ) ? new WP_REST_Request( 'GET', '/services' ) : (object) [ 'direction' => $direction ];
			if ( is_object( $req ) && method_exists( $req, 'set_param' ) ) {
				$req->set_param( 'direction', $direction );
			}
			$res = $ctrl->get_services( $req );
			$raw_services = [];
			if ( is_object( $res ) && method_exists( $res, 'get_data' ) ) {
				$data = $res->get_data();
				$raw_services = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : [];
			} elseif ( is_array( $res ) ) {
				$raw_services = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : [];
			}

			// Clean up dead weight: strip redundant UI metadata already handled by SERVICE_REGISTRY in quote-form.js
			return array_map( function( $s ) {
				return [
					'code'           => $s['code'] ?? '',
					'enabled'        => ! empty( $s['enabled'] ),
					'admin_disabled' => ! empty( $s['admin_disabled'] ),
					'reason'         => $s['reason'] ?? '',
				];
			}, $raw_services );
		}
		return [];
	}

	/**
	 * Retrieve list of 63 Vietnamese provinces.
	 *
	 * @return array
	 */
	public function get_vn_provinces() {
		return [
			'An Giang', 'Bà Rịa - Vũng Tàu', 'Bắc Giang', 'Bắc Kạn', 'Bạc Liêu', 'Bắc Ninh',
			'Bến Tre', 'Bình Định', 'Bình Dương', 'Bình Phước', 'Bình Thuận', 'Cà Mau',
			'Cần Thơ', 'Cao Bằng', 'Đà Nẵng', 'Đắk Lắk', 'Đắk Nông', 'Điện Biên',
			'Đồng Nai', 'Đồng Tháp', 'Gia Lai', 'Hà Giang', 'Hà Nam', 'Hà Nội',
			'Hà Tĩnh', 'Hải Dương', 'Hải Phòng', 'Hậu Giang', 'Hòa Bình', 'Hưng Yên',
			'Khánh Hòa', 'Kiên Giang', 'Kon Tum', 'Lai Châu', 'Lâm Đồng', 'Lạng Sơn',
			'Lào Cai', 'Long An', 'Nam Định', 'Nghệ An', 'Ninh Bình', 'Ninh Thuận',
			'Phú Thọ', 'Phú Yên', 'Quảng Bình', 'Quảng Nam', 'Quảng Ngãi', 'Quảng Ninh',
			'Quảng Trị', 'Sóc Trăng', 'Sơn La', 'Tây Ninh', 'Thái Bình', 'Thái Nguyên',
			'Thanh Hóa', 'Thừa Thiên Huế', 'Tiền Giang', 'TP. Hồ Chí Minh', 'Trà Vinh',
			'Tuyên Quang', 'Vĩnh Long', 'Vĩnh Phúc', 'Yên Bái',
		];
	}

	/**
	 * Clean country name from residual quotes, asterisks, or hash symbols.
	 *
	 * @param string $name Raw country name.
	 * @return string Sanitized display name.
	 */
	private function clean_country_name( $name ) {
		$name = trim( (string) $name );
		$name = trim( $name, " \t\n\r\0\x0B\"'" );
		$name = rtrim( $name, '*#' );
		return trim( $name );
	}

	/**
	 * Retrieve active countries with mapped export zones.
	 *
	 * @return array
	 */
	public function get_active_countries() {
		global $wpdb;

		if ( $this->country_repo ) {
			$rows = $this->country_repo->get_all_active();
			if ( ! empty( $rows ) && is_array( $rows ) ) {
				$countries = [];
				foreach ( $rows as $row ) {
					$clean_name = $this->clean_country_name( $row->country_name );
					$countries[] = [
						'iata'                   => $row->iata_code,
						'name'                   => $clean_name,
						'normalized_name'        => ! empty( $row->normalized_name ) ? $this->clean_country_name( $row->normalized_name ) : $clean_name,
						'is_us_override'         => (int) $row->is_us_override,
						'has_extended_area_note' => (int) $row->has_extended_area_note,
					];
				}
				return $countries;
			}
		}

		if ( $wpdb ) {
			$table_countries = $wpdb->prefix . 'ups_countries';
			$rows            = $wpdb->get_results( "SELECT iata_code, country_name, normalized_name, is_us_override, has_extended_area_note FROM {$table_countries} WHERE is_active = 1 ORDER BY country_name ASC" );

			if ( ! empty( $rows ) && is_array( $rows ) ) {
				$countries = [];
				foreach ( $rows as $row ) {
					$clean_name = $this->clean_country_name( $row->country_name );
					$countries[] = [
						'iata'                   => $row->iata_code,
						'name'                   => $clean_name,
						'normalized_name'        => ! empty( $row->normalized_name ) ? $this->clean_country_name( $row->normalized_name ) : $clean_name,
						'is_us_override'         => (int) $row->is_us_override,
						'has_extended_area_note' => (int) $row->has_extended_area_note,
					];
				}
				return $countries;
			}
		}

		// Fallback: Load countries from bundled countries.json
		$json_path = dirname( __DIR__ ) . '/public/assets/data/countries.json';
		if ( file_exists( $json_path ) ) {
			$json_data = json_decode( file_get_contents( $json_path ), true );
			if ( is_array( $json_data ) && ! empty( $json_data ) ) {
				$clean = [];
				foreach ( $json_data as $c ) {
					$clean[] = [
						'iata' => $c['iata'],
						'name' => $c['name'],
					];
				}
				return $clean;
			}
		}

		// Fallback: Default key destinations if JSON is missing (Zero-Leakage)
		return [
			[ 'iata' => 'US', 'name' => 'United States*', 'is_us_override' => 1, 'has_extended_area_note' => 1 ],
			[ 'iata' => 'CA', 'name' => 'Canada*', 'is_us_override' => 0, 'has_extended_area_note' => 1 ],
			[ 'iata' => 'JP', 'name' => 'Japan*#', 'is_us_override' => 0, 'has_extended_area_note' => 1 ],
			[ 'iata' => 'KR', 'name' => 'Korea, South#', 'is_us_override' => 0, 'has_extended_area_note' => 0 ],
			[ 'iata' => 'AU', 'name' => 'Australia*#', 'is_us_override' => 0, 'has_extended_area_note' => 1 ],
			[ 'iata' => 'DE', 'name' => 'Germany*', 'is_us_override' => 0, 'has_extended_area_note' => 1 ],
			[ 'iata' => 'FR', 'name' => 'France*', 'is_us_override' => 0, 'has_extended_area_note' => 1 ],
			[ 'iata' => 'GB', 'name' => 'United Kingdom*', 'is_us_override' => 0, 'has_extended_area_note' => 1 ],
			[ 'iata' => 'SG', 'name' => 'Singapore#', 'is_us_override' => 0, 'has_extended_area_note' => 0 ],
			[ 'iata' => 'TW', 'name' => 'Taiwan, China*#', 'is_us_override' => 0, 'has_extended_area_note' => 1 ],
		];
	}

	/**
	 * Render shortcode [ups_quote_form].
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_shortcode( $atts = [] ) {
		$this->enqueue_assets();

		$template_path = defined( 'ALLSHIP_UPS_QUOTE_PATH' )
			? ALLSHIP_UPS_QUOTE_PATH . 'public/views/quote-form.php'
			: dirname( __DIR__ ) . '/public/views/quote-form.php';

		// Allow theme override in theme/allship-ups-quote/quote-form.php
		if ( function_exists( 'get_stylesheet_directory' ) ) {
			$theme_override = get_stylesheet_directory() . '/allship-ups-quote/quote-form.php';
			if ( file_exists( $theme_override ) ) {
				$template_path = $theme_override;
			}
		}

		ob_start();
		if ( file_exists( $template_path ) ) {
			include $template_path;
		} else {
			echo '<div id="ups-quote-app" class="ups-quote-form"></div>';
		}
		return ob_get_clean();
	}

	/**
	 * Override page template when the page contains [ups_quote_form] shortcode.
	 *
	 * Uses the plugin-provided template unless the active theme already has
	 * a custom template assigned (e.g. page-bao-gia-ups.php).
	 *
	 * @param string $template Current resolved template path.
	 * @return string Modified template path.
	 */
	public function maybe_override_template( $template ) {
		if ( ! is_singular( 'page' ) ) {
			return $template;
		}

		global $post;
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return $template;
		}

		// Only apply if page content has our shortcode.
		if ( ! function_exists( 'has_shortcode' ) || ! has_shortcode( $post->post_content, 'ups_quote_form' ) ) {
			return $template;
		}

		// If the theme already provides a custom template for this page, respect it.
		$page_template = get_page_template_slug( $post->ID );
		if ( ! empty( $page_template ) ) {
			return $template;
		}

		// Use plugin-provided template.
		$plugin_template = defined( 'ALLSHIP_UPS_QUOTE_PATH' )
			? ALLSHIP_UPS_QUOTE_PATH . 'templates/page-quote.php'
			: dirname( __DIR__ ) . '/templates/page-quote.php';

		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $template;
	}

	/**
	 * Reset enqueue flag (used in testing).
	 *
	 * @return void
	 */
	public static function reset_assets_enqueued() {
		self::$assets_enqueued = false;
	}
}
