<?php
/**
 * PDF Quotation Renderer using Dompdf.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_Quote_Pdf_Renderer {

	/**
	 * Settings manager instance.
	 *
	 * @var Allship_UPS_Settings_Manager|null
	 */
	private $settings_manager;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Settings_Manager|null $settings_manager Settings manager.
	 */
	public function __construct( $settings_manager = null ) {
		if ( null === $settings_manager ) {
			if ( ! class_exists( 'Allship_UPS_Settings_Manager' ) && file_exists( __DIR__ . '/class-settings-manager.php' ) ) {
				require_once __DIR__ . '/class-settings-manager.php';
			}
			$this->settings_manager = class_exists( 'Allship_UPS_Settings_Manager' ) ? new Allship_UPS_Settings_Manager() : null;
		} else {
			$this->settings_manager = $settings_manager;
		}
	}

	/**
	 * Render HTML content for the quotation.
	 *
	 * @param array $payload Calculation, lead and quote details.
	 * @return string HTML string.
	 */
	public function render_html( array $payload ) {
		$company = $this->prepare_company_data( $payload['company'] ?? [] );
		$quote   = $this->prepare_quote_data( $payload['quote'] ?? [], $company );
		$customer= $payload['customer'] ?? [];
		$cargo   = $payload['cargo'] ?? [];
		$pricing = $payload['pricing'] ?? [];

		$template_path = dirname( __DIR__ ) . '/templates/pdf-quote-template.php';
		if ( ! file_exists( $template_path ) ) {
			return '';
		}

		ob_start();
		include $template_path;
		return (string) ob_get_clean();
	}

	/**
	 * Generate binary PDF data from quotation payload.
	 *
	 * @param array $payload Quotation data.
	 * @return string Binary PDF string or empty string on failure.
	 */
	public function generate_pdf( array $payload ) {
		// Ensure vendor autoloader is loaded
		$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
		if ( ! class_exists( 'Dompdf\Dompdf' ) && file_exists( $autoload ) ) {
			require_once $autoload;
		}

		if ( ! class_exists( 'Dompdf\Dompdf' ) ) {
			return '';
		}

		$html = $this->render_html( $payload );
		if ( empty( $html ) ) {
			return '';
		}

		$options = new Dompdf\Options();
		$options->set( 'isHtml5ParserEnabled', true );
		$options->set( 'isRemoteEnabled', true );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'isFontSubsettingEnabled', true );
		$options->set( 'chroot', [ ABSPATH, dirname( __DIR__ ) ] );

		$dompdf = new Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		$output = (string) $dompdf->output();
		unset( $dompdf );
		return $output;
	}

	/**
	 * Save generated PDF directly to upload directory.
	 *
	 * @param array  $payload Quotation data.
	 * @param string $custom_filename Optional specific filename.
	 * @return array{path: string, url: string}|false
	 */
	public function save_pdf_to_file( array $payload, $custom_filename = '' ) {
		$pdf_content = $this->generate_pdf( $payload );
		if ( empty( $pdf_content ) ) {
			return false;
		}

		$quote_ref = ! empty( $payload['quote']['quote_ref'] )
			? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $payload['quote']['quote_ref'] )
			: 'AS-QUO-' . gmdate( 'YmdHis' );

		$filename = $custom_filename ? $custom_filename : ( $quote_ref . '.pdf' );

		$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [
			'basedir' => dirname( __DIR__ ) . '/uploads',
			'baseurl' => '',
		];

		$year  = gmdate( 'Y' );
		$month = gmdate( 'm' );
		$dir   = $upload_dir['basedir'] . '/allship-quotes/' . $year . '/' . $month;

		if ( ! file_exists( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		} elseif ( ! file_exists( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		// Security: Place index.html and .htaccess to protect quote directory listing & PHP execution
		$base_quotes_dir = $upload_dir['basedir'] . '/allship-quotes';
		if ( file_exists( $base_quotes_dir ) ) {
			if ( ! file_exists( $base_quotes_dir . '/index.html' ) ) {
				@file_put_contents( $base_quotes_dir . '/index.html', '' );
			}
			if ( ! file_exists( $base_quotes_dir . '/.htaccess' ) ) {
				$htaccess = "# Block PHP execution and directory listing\n<FilesMatch \"\\.(php|phtml|php5|php7|php8)$\">\n    Order Deny,Allow\n    Deny from all\n</FilesMatch>\nOptions -Indexes\n";
				@file_put_contents( $base_quotes_dir . '/.htaccess', $htaccess );
			}
		}

		$file_path = $dir . '/' . $filename;
		$written   = file_put_contents( $file_path, $pdf_content );
		if ( false === $written ) {
			return false;
		}

		$file_url = ( ! empty( $upload_dir['baseurl'] ) )
			? ( $upload_dir['baseurl'] . '/allship-quotes/' . $year . '/' . $month . '/' . $filename )
			: '';

		return [
			'path' => $file_path,
			'url'  => $file_url,
		];
	}

	/**
	 * Prepare company data by merging payload with settings manager defaults.
	 *
	 * @param array $override Company overrides.
	 * @return array
	 */
	private function prepare_company_data( array $override = [] ) {
		$get = function( $key, $def = '' ) use ( $override ) {
			if ( isset( $override[ $key ] ) && '' !== $override[ $key ] ) {
				return $override[ $key ];
			}
			$short_key = str_replace( [ 'company_', 'quote_' ], '', $key );
			if ( isset( $override[ $short_key ] ) && '' !== $override[ $short_key ] ) {
				return $override[ $short_key ];
			}
			return $this->settings_manager ? $this->settings_manager->get( $key, $def ) : $def;
		};

		return [
			'name'        => $get( 'company_name', 'CÔNG TY TNHH ALLSHIP LOGISTICS' ),
			'tax_id'      => $get( 'company_tax_id', '0317320092' ),
			'address'     => $get( 'company_address', 'Tầng 3, Tòa nhà Allship, TP. Hồ Chí Minh, Việt Nam' ),
			'hotline'     => $get( 'company_hotline', '1900 633 833 / 0903 000 888' ),
			'email'       => $get( 'company_email', 'quote@allship.vn' ),
			'website'     => $get( 'company_website', 'https://allship.vn' ),
			'logo_url'    => self::resolve_local_image_data_uri( $get( 'company_logo_url', '' ), (int) $get( 'company_logo_id', 0 ) ),
			'logo_id'     => (int) $get( 'company_logo_id', 0 ),
			'logo_height' => (int) $get( 'company_logo_height', 42 ),
			'stamp_url'   => self::resolve_local_image_data_uri( $get( 'quote_digital_stamp_url', '' ) ),
			'bank_info'   => $get( 'quote_bank_info', "Ngân hàng: Techcombank - Chi nhánh TP.HCM\nSố tài khoản: 19038888999999\nChủ tài khoản: CONG TY TNHH ALLSHIP LOGISTICS" ),
			'terms_notes' => $get( 'quote_terms_notes', "1. Báo giá chưa bao gồm thuế nhập khẩu và thuế giá trị gia tăng tại nước đến (nếu có).\n2. Hàng hóa phải tuân thủ nghiêm ngặt quy định an toàn bay quốc tế của IATA và UPS.\n3. Thời gian giao hàng dự kiến tính theo ngày làm việc (không bao gồm Thứ 7, Chủ Nhật và ngày lễ)." ),
			'dim_divisor' => (int) $get( 'dim_divisor', 5000 ),
		];
	}

	/**
	 * Convert local image URL or Attachment ID to base64 Data URI to avoid slow loopback HTTP requests in Dompdf.
	 *
	 * @param string $url Image URL or file path.
	 * @param int    $attachment_id Optional WordPress Media Attachment ID.
	 * @return string Data URI or original URL.
	 */
	public static function resolve_local_image_data_uri( $url, $attachment_id = 0 ) {
		if ( empty( $url ) && empty( $attachment_id ) ) {
			return '';
		}

		$local_path = '';

		// 1. Resolve directly via WordPress attachment ID if available
		if ( ! empty( $attachment_id ) && function_exists( 'get_attached_file' ) ) {
			$attached = get_attached_file( $attachment_id );
			if ( ! empty( $attached ) && file_exists( $attached ) ) {
				$local_path = $attached;
			}
		}

		// 2. Resolve via URL or local file path
		if ( empty( $local_path ) && ! empty( $url ) && is_string( $url ) ) {
			if ( strpos( $url, 'data:' ) === 0 ) {
				return $url;
			}

			if ( file_exists( $url ) ) {
				$local_path = $url;
			} else {
				$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : null;
				if ( $upload_dir && ! empty( $upload_dir['baseurl'] ) && strpos( $url, $upload_dir['baseurl'] ) === 0 ) {
					$rel  = substr( $url, strlen( $upload_dir['baseurl'] ) );
					$path = $upload_dir['basedir'] . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
					if ( file_exists( $path ) ) {
						$local_path = $path;
					}
				}

				if ( empty( $local_path ) && function_exists( 'site_url' ) && defined( 'ABSPATH' ) ) {
					$site_url = site_url();
					if ( strpos( $url, $site_url ) === 0 ) {
						$rel  = substr( $url, strlen( $site_url ) );
						$path = rtrim( ABSPATH, '/\\' ) . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
						if ( file_exists( $path ) ) {
							$local_path = $path;
						}
					}
				}

				// Universal fallback: handle /wp-content/uploads/ even if port/host differs (e.g. localhost:10046 vs 127.0.0.1)
				if ( empty( $local_path ) ) {
					if ( preg_match( '#/wp-content/uploads/(.+)$#i', $url, $matches ) ) {
						$basedir = ( $upload_dir && ! empty( $upload_dir['basedir'] ) ) ? $upload_dir['basedir'] : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads' : '' );
						if ( $basedir ) {
							$cand = rtrim( $basedir, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $matches[1] );
							if ( file_exists( $cand ) ) {
								$local_path = $cand;
							}
						}
					}
				}

				// Fallback: match any /wp-content/ path
				if ( empty( $local_path ) && defined( 'WP_CONTENT_DIR' ) ) {
					if ( preg_match( '#/wp-content/(.+)$#i', $url, $matches ) ) {
						$cand = rtrim( WP_CONTENT_DIR, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $matches[1] );
						if ( file_exists( $cand ) ) {
							$local_path = $cand;
						}
					}
				}

				// Fallback: match absolute path relative to ABSPATH from URL path
				if ( empty( $local_path ) && defined( 'ABSPATH' ) ) {
					$url_path = parse_url( $url, PHP_URL_PATH );
					if ( ! empty( $url_path ) ) {
						$cand = rtrim( ABSPATH, '/\\' ) . str_replace( '/', DIRECTORY_SEPARATOR, $url_path );
						if ( file_exists( $cand ) ) {
							$local_path = $cand;
						}
					}
				}
			}
		}

		if ( ! empty( $local_path ) && file_exists( $local_path ) && filesize( $local_path ) < 5000000 ) {
			$ext  = strtolower( pathinfo( $local_path, PATHINFO_EXTENSION ) );
			$mime = ( 'jpg' === $ext || 'jpeg' === $ext ) ? 'image/jpeg' : ( ( 'svg' === $ext ) ? 'image/svg+xml' : ( ( 'gif' === $ext ) ? 'image/gif' : 'image/png' ) );
			$data = @file_get_contents( $local_path );
			if ( false !== $data ) {
				return 'data:' . $mime . ';base64,' . base64_encode( $data );
			}
		}

		return $url;
	}

	/**
	 * Prepare quote metadata (validity date calculation, etc.).
	 *
	 * @param array $quote_data Raw quote data.
	 * @param array $company_data Resolved company settings.
	 * @return array
	 */
	private function prepare_quote_data( array $quote_data, array $company_data ) {
		$created_ts = ! empty( $quote_data['created_at_ts'] ) ? (int) $quote_data['created_at_ts'] : time();
		$val_days   = $this->settings_manager ? (int) $this->settings_manager->get( 'quote_validity_days', 14 ) : 14;

		if ( empty( $quote_data['created_at'] ) ) {
			$quote_data['created_at'] = date( 'd/m/Y', $created_ts );
		}

		if ( empty( $quote_data['valid_until'] ) ) {
			$quote_data['valid_until'] = date( 'd/m/Y', $created_ts + ( $val_days * 86400 ) );
		}

		return $quote_data;
	}

	/**
	 * Clean up temporary quote PDF files older than specified days.
	 *
	 * @param int $days Retention days (default 30).
	 * @return int Number of deleted files.
	 */
	public static function cleanup_old_quotes( $days = 30 ) {
		$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [
			'basedir' => dirname( __DIR__ ) . '/uploads',
		];

		$base_dir = $upload_dir['basedir'] . '/allship-quotes';
		if ( ! is_dir( $base_dir ) ) {
			return 0;
		}

		$cutoff_time = time() - ( max( 1, (int) $days ) * 86400 );
		$deleted_count = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				$filename = $item->getFilename();
				if ( 'pdf' === strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
					if ( $item->getMTime() < $cutoff_time ) {
						if ( @unlink( $item->getRealPath() ) ) {
							$deleted_count++;
						}
					}
				}
			} elseif ( $item->isDir() ) {
				// Remove empty subdirectories
				$files = scandir( $item->getRealPath() );
				if ( is_array( $files ) && count( array_diff( $files, [ '.', '..' ] ) ) === 0 ) {
					@rmdir( $item->getRealPath() );
				}
			}
		}

		return $deleted_count;
	}
}
