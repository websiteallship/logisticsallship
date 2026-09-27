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
	 * Constructor.
	 *
	 * @param Allship_UPS_Rate_Card_Repository|null $rate_card_repo Rate card repository.
	 * @param Allship_UPS_Settings_Manager|null     $settings_mgr Settings manager.
	 */
	public function __construct( $rate_card_repo = null, $settings_mgr = null ) {
		$this->rate_card_repo = $rate_card_repo ?: ( class_exists( 'Allship_UPS_Rate_Card_Repository' ) ? new Allship_UPS_Rate_Card_Repository() : null );
		$this->settings_mgr   = $settings_mgr ?: ( class_exists( 'Allship_UPS_Settings_Manager' ) ? new Allship_UPS_Settings_Manager() : null );
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
		wp_register_style(
			'ups-quote-form',
			$plugin_url . 'public/assets/css/quote-form.css',
			[ 'ups-quote-tailwind' ],
			$version
		);

		// 4. Form JS
		$deps = [ 'animejs' ];
		if ( function_exists( 'wp_script_is' ) && wp_script_is( 'allship-main', 'registered' ) ) {
			$deps[] = 'allship-main';
		}

		wp_register_script(
			'ups-quote-form',
			$plugin_url . 'public/assets/js/quote-form.js',
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
		$plugin_url = defined( 'ALLSHIP_UPS_QUOTE_URL' ) ? ALLSHIP_UPS_QUOTE_URL : plugins_url( '/', dirname( __FILE__ ) );

		$api_base = function_exists( 'rest_url' )
			? esc_url_raw( rest_url( 'ups-quote/v1' ) )
			: '/wp-json/ups-quote/v1';

		$nonce = function_exists( 'wp_create_nonce' )
			? wp_create_nonce( 'wp_rest' )
			: '';

		$dim_divisor  = $this->settings_mgr ? (int) $this->settings_mgr->get( 'dim_divisor', 5500 ) : 5500;
		$rounding_step = $this->settings_mgr ? (float) $this->settings_mgr->get( 'rounding_step_kg', 0.5 ) : 0.5;

		return [
			'pluginUrl'     => $plugin_url,
			'statesBaseUrl' => $plugin_url . 'public/assets/data/states/',
			'citiesBaseUrl' => $plugin_url . 'public/assets/data/cities/',
			'apiBase'       => $api_base,
			'nonce'         => $nonce,
			'currency'      => 'VND',
			'COUNTRIES'     => $this->get_active_countries(),
			'VN_PROVINCES'  => $this->get_vn_provinces(),
			'POPULAR_IATA'  => [ 'US', 'JP', 'KR', 'AU', 'CA', 'DE', 'GB', 'FR', 'SG', 'TW' ],
			'dim_divisor'   => $dim_divisor,
			'rounding_step' => $rounding_step,
		];
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
	 * Retrieve active countries with mapped export zones.
	 *
	 * @return array
	 */
	public function get_active_countries() {
		global $wpdb;

		$rate_card_id = 1;
		if ( $this->rate_card_repo ) {
			$active_card  = $this->rate_card_repo->get_active();
			$rate_card_id = $active_card ? (int) $active_card->id : 1;
		}

		if ( $wpdb ) {
			$table_countries = $wpdb->prefix . 'ups_countries';
			$table_zones     = $wpdb->prefix . 'ups_zone_maps';

			$sql = "SELECT c.iata_code, c.country_name, c.normalized_name, c.is_us_override, c.has_extended_area_note,
			               MAX(CASE WHEN zm.service_code = 'WXS' THEN zm.zone ELSE NULL END) as wxs,
			               MAX(CASE WHEN zm.service_code = 'XPD' THEN zm.zone ELSE NULL END) as xpd,
			               MAX(CASE WHEN zm.service_code = 'WFM' THEN zm.zone ELSE NULL END) as wfm
			        FROM {$table_countries} c
			        LEFT JOIN {$table_zones} zm ON zm.country_id = c.id
			             AND zm.rate_card_id = %d
			             AND zm.direction = 'export'
			        WHERE c.is_active = 1
			        GROUP BY c.id
			        ORDER BY c.country_name ASC";

			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $rate_card_id ) );

			if ( ! empty( $rows ) && is_array( $rows ) ) {
				$countries = [];
				foreach ( $rows as $row ) {
					$countries[] = [
						'iata'                   => $row->iata_code,
						'name'                   => $row->country_name,
						'normalized_name'        => $row->normalized_name,
						'is_us_override'         => (int) $row->is_us_override,
						'has_extended_area_note' => (int) $row->has_extended_area_note,
					];
				}
				return $countries;
			}
		}

		// Fallback: Load countries from bundled countries.json (stripped of zones)
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
