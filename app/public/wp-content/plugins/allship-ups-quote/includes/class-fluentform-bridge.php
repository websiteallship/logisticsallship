<?php
/**
 * FluentForm Bridge for Allship UPS Quote System.
 *
 * Integrates FluentForm submissions with Allship UPS Quote Logs:
 * 1. Listens on 'fluentform/submission_inserted' hook to sync leads into wp_ups_quote_logs.
 * 2. Bridges REST /lead submissions into FluentForm submissions table so they appear in ff-frontend-entries.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Allship_UPS_FluentForm_Bridge {

	/**
	 * Quote Log repository.
	 *
	 * @var Allship_UPS_Quote_Log_Repository|null
	 */
	private $log_repo;

	/**
	 * Database instance.
	 *
	 * @var wpdb|null
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param Allship_UPS_Quote_Log_Repository|null $log_repo Quote log repository.
	 * @param wpdb|null                          $wpdb     Database instance.
	 */
	public function __construct( $log_repo = null, $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb     = $wpdb;
		$this->log_repo = $log_repo ?: ( class_exists( 'Allship_UPS_Quote_Log_Repository' ) ? new Allship_UPS_Quote_Log_Repository( $this->wpdb ) : null );
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'fluentform/submission_inserted', [ $this, 'on_submission_inserted' ], 20, 3 );
	}

	/**
	 * Handle FluentForm submission inserted event.
	 *
	 * @param int         $insert_id Form submission ID.
	 * @param array       $form_data Form input data.
	 * @param object|null $form      Form metadata object.
	 * @return void
	 */
	public function on_submission_inserted( $insert_id, $form_data, $form = null ) {
		if ( empty( $form_data ) || ! is_array( $form_data ) ) {
			return;
		}

		// Prevent infinite recursion if submission was triggered by this bridge
		if ( ! empty( $form_data['__bridge_sync'] ) ) {
			return;
		}

		// Check if this form submission belongs to UPS Quote
		$is_ups_quote = false;
		if ( ! empty( $form_data['hidden_source'] ) && in_array( $form_data['hidden_source'], [ 'ups_quote_booking_modal', 'ups-quote-form' ], true ) ) {
			$is_ups_quote = true;
		} elseif ( ! empty( $form_data['service_code'] ) || ! empty( $form_data['quote_log_id'] ) ) {
			$is_ups_quote = true;
		} elseif ( $form && ! empty( $form->title ) && ( false !== stripos( $form->title, 'UPS' ) || false !== stripos( $form->title, 'Báo Giá' ) ) ) {
			$is_ups_quote = true;
		} elseif ( function_exists( 'get_option' ) ) {
			$configured_form_id = (int) get_option( 'allship_ups_fluentform_id', 0 );
			if ( $configured_form_id > 0 && $form && (int) $form->id === $configured_form_id ) {
				$is_ups_quote = true;
			}
		}

		if ( ! $is_ups_quote ) {
			return;
		}

		$sanitize_text = function_exists( 'sanitize_text_field' ) ? 'sanitize_text_field' : 'trim';

		// Extract contact info
		$name    = ! empty( $form_data['name'] ) ? $sanitize_text( $form_data['name'] ) : ( ! empty( $form_data['names'] ) ? $sanitize_text( is_array( $form_data['names'] ) ? implode( ' ', $form_data['names'] ) : $form_data['names'] ) : '' );
		$phone   = ! empty( $form_data['phone'] ) ? $sanitize_text( $form_data['phone'] ) : ( ! empty( $form_data['so_dien_thoai'] ) ? $sanitize_text( $form_data['so_dien_thoai'] ) : '' );
		$email   = ! empty( $form_data['email'] ) && function_exists( 'sanitize_email' ) ? sanitize_email( $form_data['email'] ) : '';
		$notes   = ! empty( $form_data['notes'] ) && function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $form_data['notes'] ) : ( ! empty( $form_data['ghi_chu'] ) ? sanitize_textarea_field( $form_data['ghi_chu'] ) : '' );
		$message = ! empty( $form_data['message'] ) && function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $form_data['message'] ) : '';

		$created_at = function_exists( 'wp_date' )
			? wp_date( 'Y-m-d H:i:s' )
			: ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) );

		$contact_meta = [
			'source'      => 'fluentform',
			'ff_entry_id' => (int) $insert_id,
			'name'        => $name,
			'phone'       => $phone,
			'email'       => $email,
			'notes'       => $notes,
			'message'     => $message,
			'created_at'  => $created_at,
		];

		$quote_log_id = ! empty( $form_data['quote_log_id'] ) ? absint( $form_data['quote_log_id'] ) : 0;

		// Case A: Quote log ID exists -> update existing log
		if ( $quote_log_id && $this->log_repo ) {
			$existing_log = $this->log_repo->get( $quote_log_id );
			if ( $existing_log ) {
				$breakdown            = is_array( $existing_log->breakdown ) ? $existing_log->breakdown : [];
				$breakdown['contact'] = $contact_meta;
				$this->log_repo->update(
					$quote_log_id,
					[ 'breakdown_json' => $breakdown ]
				);
				return;
			}
		}

		// Case B: No matching quote log -> create a new quote log entry
		if ( $this->log_repo ) {
			$direction        = ! empty( $form_data['direction'] ) ? strtolower( trim( (string) $form_data['direction'] ) ) : 'export';
			$origin           = ! empty( $form_data['origin'] ) ? $sanitize_text( $form_data['origin'] ) : 'TP. Hồ Chí Minh';
			$destination_iata = ! empty( $form_data['destination_iata'] ) ? strtoupper( trim( (string) $form_data['destination_iata'] ) ) : 'US';
			$destination      = ! empty( $form_data['destination'] ) ? $sanitize_text( $form_data['destination'] ) : '';
			$service_code     = ! empty( $form_data['service_code'] ) ? strtoupper( trim( (string) $form_data['service_code'] ) ) : 'ALL';
			$weight_kg        = ! empty( $form_data['chargeable_weight'] ) ? floatval( preg_replace( '/[^0-9.]/', '', (string) $form_data['chargeable_weight'] ) ) : null;
			$total_price_vnd  = ! empty( $form_data['total_price_raw'] ) ? absint( $form_data['total_price_raw'] ) : null;
			$pieces_json      = ! empty( $form_data['pieces_json'] ) ? ( is_array( $form_data['pieces_json'] ) ? wp_json_encode( $form_data['pieces_json'] ) : (string) $form_data['pieces_json'] ) : null;

			$client_ip   = ! empty( $form_data['ip'] ) ? $sanitize_text( $form_data['ip'] ) : ( ! empty( $_SERVER['REMOTE_ADDR'] ) ? $sanitize_text( (string) $_SERVER['REMOTE_ADDR'] ) : '127.0.0.1' );
			$device_type = $this->detect_device_type();
			$user_agent  = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( $sanitize_text( (string) $_SERVER['HTTP_USER_AGENT'] ), 0, 255 ) : '';

			$this->log_repo->insert( [
				'session_id'           => 'ff-' . (int) $insert_id,
				'ip_address'           => $client_ip,
				'device_type'          => $device_type,
				'user_agent'           => $user_agent,
				'direction'            => $direction,
				'origin_iata'          => 'VN',
				'origin_province'      => $origin,
				'destination_iata'     => $destination_iata,
				'destination_address'  => $destination,
				'service_code'         => $service_code,
				'actual_weight_kg'     => $weight_kg,
				'chargeable_weight_kg' => $weight_kg,
				'total_price_vnd'      => $total_price_vnd,
				'pieces_json'          => $pieces_json,
				'breakdown_json'       => [
					'contact'     => $contact_meta,
					'lead_source' => 'fluentform_direct',
				],
			] );
		}
	}

	/**
	 * Bridge a REST lead submission into FluentForm submissions table.
	 *
	 * Allows submissions from the booking modal to appear in ff-frontend-entries Lead Dashboard.
	 *
	 * @param array $lead REST lead payload.
	 * @return int Inserted submission ID or 0.
	 */
	public function push_lead_to_fluentform( array $lead ) {
		if ( ! $this->wpdb ) {
			return 0;
		}

		$table_submissions = $this->wpdb->prefix . 'fluentform_submissions';
		$has_table         = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table_submissions ) );
		if ( ! $has_table ) {
			return 0;
		}

		// Resolve FluentForm Form ID
		$form_id = 0;
		if ( function_exists( 'get_option' ) ) {
			$form_id = (int) get_option( 'allship_ups_fluentform_id', 0 );
		}

		$table_forms = $this->wpdb->prefix . 'fluentform_forms';
		$has_forms   = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table_forms ) );

		if ( ! $form_id && $has_forms ) {
			// Find form matching UPS
			$found = $this->wpdb->get_var( "SELECT id FROM {$table_forms} WHERE status = 'published' AND (title LIKE '%UPS%' OR title LIKE '%Báo Giá%') ORDER BY id DESC LIMIT 1" );
			if ( $found ) {
				$form_id = (int) $found;
			} else {
				// Fallback to latest published form
				$form_id = (int) $this->wpdb->get_var( "SELECT id FROM {$table_forms} WHERE status = 'published' ORDER BY id DESC LIMIT 1" );
			}
		}

		if ( ! $form_id ) {
			$form_id = 1;
		}

		// Calculate serial number
		$serial = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT MAX(serial_number) FROM {$table_submissions} WHERE form_id = %d",
				$form_id
			)
		);
		$serial++;

		// Build FluentForm response JSON matching the 18 standard services and ff-frontend-entries
		$form_data = [
			'name'                => $lead['name'] ?? '',
			'phone'               => $lead['phone'] ?? '',
			'email'               => $lead['email'] ?? '',
			'company_name'        => $lead['company_name'] ?? ( $lead['company'] ?? '' ),
			'quote_ref'           => $lead['quote_ref'] ?? '',
			'pdf_url'             => $lead['pdf_url'] ?? '',
			'service'             => $lead['service'] ?? ( $lead['service_code'] ?? '' ),
			'hidden_service_name' => $lead['hidden_service_name'] ?? 'Dịch vụ chuyển phát quốc tế UPS',
			'message'             => ! empty( $lead['message'] ) ? $lead['message'] : ( $lead['notes'] ?? '' ),
			'notes'               => $lead['notes'] ?? '',
			'direction'           => $lead['direction'] ?? 'export',
			'direction_label'     => ( 'import' === ( $lead['direction'] ?? '' ) ) ? 'Nhập khẩu' : 'Xuất khẩu',
			'origin'              => $lead['origin'] ?? 'TP. Hồ Chí Minh',
			'destination'         => $lead['destination'] ?? ( $lead['destination_name'] ?? '' ),
			'destination_iata'    => $lead['destination_iata'] ?? '',
			'service_code'        => $lead['service_code'] ?? '',
			'service_name'        => $lead['service_name'] ?? '',
			'chargeable_weight'   => ! empty( $lead['chargeable_weight'] ) ? $lead['chargeable_weight'] : ( ( $lead['weight_kg'] ?? 0 ) . ' kg' ),
			'total_price'         => ! empty( $lead['total_price'] ) ? $lead['total_price'] : ( number_format( (float) ( $lead['total_price_vnd'] ?? 0 ), 0, ',', '.' ) . ' VND' ),
			'total_price_raw'     => $lead['total_price_vnd'] ?? 0,
			'quote_log_id'        => $lead['quote_log_id'] ?? 0,
			'route_summary'       => $lead['route_summary'] ?? '',
			'pieces_json'         => ! empty( $lead['pieces'] ) ? ( is_array( $lead['pieces'] ) ? wp_json_encode( $lead['pieces'] ) : (string) $lead['pieces'] ) : '',
			'hidden_source'       => ! empty( $lead['hidden_source'] ) ? $lead['hidden_source'] : 'ups_quote_booking_modal',
			'__bridge_sync'       => true, // Flag to prevent infinite hook loop
		];

		$client_ip   = ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';
		$device_type = $this->detect_device_type();
		$browser     = $this->detect_browser();
		$user_id     = function_exists( 'get_current_user_id' ) ? ( get_current_user_id() ?: null ) : null;
		$source_url  = ! empty( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ( function_exists( 'home_url' ) ? home_url() : '' );
		$now         = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

		$insert_fields = [
			'form_id'       => $form_id,
			'serial_number' => $serial,
			'response'      => function_exists( 'wp_json_encode' ) ? wp_json_encode( $form_data, JSON_UNESCAPED_UNICODE ) : json_encode( $form_data ),
			'source_url'    => $source_url,
			'user_id'       => $user_id,
			'status'        => 'unread',
			'browser'       => $browser,
			'device'        => $device_type,
			'ip'            => $client_ip,
			'created_at'    => $now,
			'updated_at'    => $now,
		];

		$inserted = $this->wpdb->insert(
			$table_submissions,
			$insert_fields,
			[ '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return 0;
		}

		$entry_id = (int) $this->wpdb->insert_id;

		// Get form object if table exists
		$form_obj = null;
		if ( $has_forms ) {
			$form_obj = $this->wpdb->get_row(
				$this->wpdb->prepare( "SELECT * FROM {$table_forms} WHERE id = %d", $form_id )
			);
		}

		// Fire custom hook without triggering FluentForm default email notification pipeline
		if ( function_exists( 'do_action' ) ) {
			do_action( 'allship_ups_fluentform_bridged', $entry_id, $form_data, $form_obj );
		}

		return $entry_id;
	}

	/**
	 * Detect device type.
	 *
	 * @return string
	 */
	private function detect_device_type(): string {
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
	 * Detect browser name.
	 *
	 * @return string
	 */
	private function detect_browser(): string {
		$ua = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( empty( $ua ) ) {
			return 'Web';
		}
		if ( preg_match( '/Edg/i', $ua ) ) {
			return 'Edge';
		}
		if ( preg_match( '/Chrome/i', $ua ) ) {
			return 'Chrome';
		}
		if ( preg_match( '/Firefox/i', $ua ) ) {
			return 'Firefox';
		}
		if ( preg_match( '/Safari/i', $ua ) ) {
			return 'Safari';
		}
		if ( preg_match( '/Opera|OPR/i', $ua ) ) {
			return 'Opera';
		}
		return 'Web';
	}
}
