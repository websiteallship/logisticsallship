# 11. Backend Technical Specification

## 1. PHP Architecture

### 1.1. Nguyên tắc

- PHP 7.2+ compatible.
- WordPress Coding Standards.
- No side-effects at file load time — hook everything.
- Dependency injection qua constructor.
- Prefix tất cả global: `allship_ups_`.

### 1.2. Class Diagram

```
Plugin (bootstrap)
├── Activator          → dbDelta, create page, insert defaults
├── Deactivator        → cleanup transients
├── Settings_Manager   → ups_settings CRUD
├── Service_Availability_Manager → direction/service resolution per rate card
├── Config/Service_Registry     → SERVICE_REGISTRY constant (6 services × 2 dirs)
├── Repositories
│   ├── Rate_Card_Repository    → ups_rate_cards + directions/toggles
│   ├── Country_Repository      → ups_countries
│   ├── Zone_Repository         → ups_zone_maps
│   ├── Rate_Repository         → ups_rates
│   ├── Quote_Log_Repository    → ups_quote_logs
│   └── Quote_Lead_Repository   → ups_quote_leads (quản lý B2B Leads)
├── Importers
│   ├── CSV_Parser              → native fgetcsv
│   ├── XLSX_Reader             → SimpleXLSX wrapper
│   ├── Rate_Importer           → parse rate CSV/XLSX → new rate_group naming
│   ├── Zone_Importer           → parse zone CSV/XLSX (flat+pivot)
│   └── Import_Orchestrator     → điều phối, validate, preview
├── Calculator
│   ├── Weight_Calculator       → dim, rounding, multi-piece
│   ├── Zone_Resolver           → zone lookup + US5
│   ├── Rate_Lookup             → price lookup, brackets
│   ├── Surcharge_Engine        → fees (extensible)
│   └── Quote_Calculator        → orchestrator
├── Services
│   ├── PDF_Quote_Service       → Dompdf wrapper, A4 template, watermark, seal
│   └── FluentForm_Bridge       → 2-way sync giữa Leads và FluentForm submissions
├── REST_Controller             → /calculate, /lead, /export-quote, /download-quote, /async-mail
├── Shortcode                   → [ups_quote_form]
└── Admin
    ├── Admin_Menu              → menu registration
    ├── Admin_Import            → import UI + AJAX
    ├── Admin_Rates             → rate CRUD UI
    ├── Admin_Zones             → zone CRUD UI
    ├── Admin_Countries         → country CRUD UI
    ├── Admin_Settings          → settings form (weight, fees, PDF/company)
    ├── Admin_Rate_Cards        → rate card management + direction/service toggles
    └── Admin_Quote_Logs        → logs, lead status filter + export CSV/Excel
```

### 1.3. Autoloading

Không dùng Composer autoload (tránh conflict). Manual include:

```php
// allship-ups-quote.php
private function load_dependencies() {
    $includes = plugin_dir_path( __FILE__ ) . 'includes/';
    
    require_once $includes . 'class-settings-manager.php';
    require_once $includes . 'class-rate-card-repository.php';
    require_once $includes . 'class-country-repository.php';
    require_once $includes . 'class-zone-repository.php';
    require_once $includes . 'class-rate-repository.php';
    require_once $includes . 'class-quote-log-repository.php';
    // ... etc
    
    // SimpleXLSX - only when needed
    // require_once plugin_dir_path( __FILE__ ) . 'libs/SimpleXLSX.php';
}
```

## 2. Import Strategy

### 2.1. CSV-first, XLSX fallback

| Format | Library | Dependency |
|--------|---------|------------|
| CSV | `fgetcsv()` | Native PHP |
| XLSX | SimpleXLSX | 1 file ~100KB |

### 2.2. CSV Parser

```php
class CSV_Parser {
    public function parse( string $file_path, array $expected_headers ): array {
        $handle = fopen( $file_path, 'r' );
        
        // BOM handling
        $bom = fread( $handle, 3 );
        if ( $bom !== "\xEF\xBB\xBF" ) {
            rewind( $handle );
        }
        
        $headers = fgetcsv( $handle );
        $this->validate_headers( $headers, $expected_headers );
        
        $rows = [];
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $rows[] = array_combine( $headers, $row );
        }
        
        fclose( $handle );
        return $rows;
    }
}
```

### 2.3. XLSX Reader

```php
class XLSX_Reader {
    public function parse( string $file_path, string $sheet_name = null ): array {
        if ( ! class_exists( 'SimpleXLSX' ) ) {
            require_once ALLSHIP_UPS_QUOTE_PATH . 'libs/SimpleXLSX.php';
        }
        
        $xlsx = SimpleXLSX::parse( $file_path );
        if ( ! $xlsx ) {
            throw new \Exception( SimpleXLSX::parseError() );
        }
        
        $sheet_index = 0;
        if ( $sheet_name ) {
            $names = $xlsx->sheetNames();
            $sheet_index = array_search( $sheet_name, $names );
        }
        
        return $xlsx->rows( $sheet_index );
    }
}
```

### 2.4. Rate Importer

```php
class Rate_Importer {
    const EXPECTED_RATE_HEADERS = [
        'rate_group', 'weight_label', 'weight_from', 'weight_to',
        'billing_unit', 'zone_1', 'zone_2', 'zone_3', 'zone_4',
        'zone_5', 'zone_6', 'zone_7', 'zone_8', 'zone_9', 'zone_10',
        'zone_us5'
    ];
    
    // Hỗ trợ 2 loại input:
    // 1. CSV template (headers = EXPECTED_RATE_HEADERS)
    // 2. XLSX gốc UPS (detect by label "Express Saver Document Rates:")
    
    public function import( string $file, int $rate_card_id ): ImportResult {
        $ext = pathinfo( $file, PATHINFO_EXTENSION );
        
        if ( $ext === 'csv' ) {
            $rows = $this->csv_parser->parse( $file, self::EXPECTED_RATE_HEADERS );
            return $this->import_from_template( $rows, $rate_card_id );
        }
        
        // XLSX: detect format
        $data = $this->xlsx_reader->parse( $file );
        if ( $this->is_ups_original_format( $data ) ) {
            return $this->import_from_ups_format( $data, $rate_card_id );
        }
        
        // Assume template format
        return $this->import_from_template_array( $data, $rate_card_id );
    }
    
    // Parse UPS original format: tìm label → parse block
    private function import_from_ups_format( array $data, int $rate_card_id ): ImportResult {
        $labels = [
            'saver_document'    => 'Express Saver Document Rates:',
            'saver_nondocument' => 'Express Saver Non-Document Rates:',
            'expedited'         => 'Expedited Rates:',
            'freight'           => 'Worldwide Express Freight Rates:',
        ];
        // ... find label rows, parse zone columns, extract rates
    }
}
```

### 2.5. Zone Importer

```php
class Zone_Importer {
    // Auto-detect format:
    // A. CSV pivot template: iata_code, country_name, direction, wxs, xpd, ...
    // B. XLSX flat (IATA.xlsx): SN, Cty, IATA, Country, Svc Type, Mvn, XPR, ...
    
    public function detect_format( array $headers ): string {
        if ( in_array( 'iata_code', $headers ) && in_array( 'direction', $headers ) ) {
            return 'pivot_template';
        }
        if ( in_array( 'SN', $headers ) && in_array( 'Cty', $headers ) && in_array( 'Mvn', $headers ) ) {
            return 'flat_ups';
        }
        throw new \Exception( 'INVALID_ZONE_FORMAT' );
    }
    
    // Flat → pivot conversion
    private function pivot_flat_data( array $rows ): array {
        $zone_map = [];
        foreach ( $rows as $row ) {
            $iata = $row['IATA'];
            if ( empty( $iata ) || $iata === 'ZZ' ) continue;
            
            $direction = strtolower( $row['Mvn'] ); // export | import
            $services = ['XPR', 'XPD', 'WXS', 'WXP', 'WFM', 'EXW'];
            
            foreach ( $services as $svc ) {
                $zone = intval( $row[ $svc ] ?? 0 );
                if ( $zone > 0 ) {
                    $key = $iata . '|' . $direction;
                    $zone_map[ $key ]['iata_code']    = $iata;
                    $zone_map[ $key ]['country_name'] = $row['Rate Guide Country Name'];
                    $zone_map[ $key ]['direction']    = $direction;
                    $zone_map[ $key ][ strtolower( $svc ) ] = $zone;
                }
            }
        }
        return array_values( $zone_map );
    }
}
```

## 3. Database Migration

### 3.1. Version tracking

```php
const ALLSHIP_UPS_DB_VERSION = '1.0.0';

// Check on plugin load
$installed_version = get_option( 'allship_ups_db_version' );
if ( $installed_version !== ALLSHIP_UPS_DB_VERSION ) {
    Activator::migrate();
    update_option( 'allship_ups_db_version', ALLSHIP_UPS_DB_VERSION );
}
```

### 3.2. dbDelta idempotent

```php
public static function migrate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $prefix  = $wpdb->prefix;
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    
    $sql = "CREATE TABLE {$prefix}ups_rate_cards (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        market_code VARCHAR(10) NOT NULL DEFAULT 'VN',
        valid_from DATE NULL,
        source_file_name VARCHAR(255) NULL,
        source_file_hash CHAR(64) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        imported_at DATETIME NOT NULL,
        activated_at DATETIME NULL,
        created_by BIGINT UNSIGNED NULL,
        PRIMARY KEY (id),
        KEY status (status),
        KEY market_status (market_code, status)
    ) $charset;";
    
    // ... 5 more tables
    
    dbDelta( $sql );
}
```

### 3.3. Auto-create page

```php
public static function create_quote_page() {
    $page_id = get_option( 'allship_ups_quote_page_id' );
    if ( $page_id && get_post_status( $page_id ) ) {
        return; // Page exists
    }
    
    $page_id = wp_insert_post( [
        'post_title'   => 'Báo giá UPS',
        'post_name'    => 'bao-gia-ups',
        'post_content' => '[ups_quote_form]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_author'  => get_current_user_id() ?: 1,
    ] );
    
    update_option( 'allship_ups_quote_page_id', $page_id );
}
```

## 4. Multiple Rate Cards

### 4.1. Workflow

```
Admin tạo rate card → Đặt tên tùy ý → Import data → Preview → Activate
                                         ↓
                      "UPS VN Net Rates Q3-2026"
                      "Bảng giá đặc biệt Khách A"
                      "Test rates cho QA"
```

### 4.2. Quy tắc (Cập nhật ADR-009)

- Hỗ trợ **nhiều rate cards active đồng thời** để phục vụ nhiều dịch vụ và chiều vận chuyển khác nhau.
- **Quy tắc cô lập (Isolation Rule)**: Mỗi dịch vụ và chiều vận chuyển (`rate_group`, phân biệt rõ ràng giữa Export và Import) chỉ có tối đa **1 rate card active** tại một thời điểm.
- Khi activate card mới, hệ thống chỉ tự động chuyển các card active cũ **cùng `rate_group`** sang `archived`. Các card active của các dịch vụ khác (hoặc chiều khác) vẫn duy trì trạng thái `active`.
- Admin có thể test draft card bằng cách truyền `rate_card_id` cụ thể trong API payload.
- Bộ tính cước (`Quote_Calculator`) giải quyết động bảng giá active theo `rate_group` tương ứng với gói cước khách hàng chọn.

### 4.3. Rate Card Repository

```php
class Allship_UPS_Rate_Card_Repository {
    public function create( array $data ): int {
        // Tạo rate card mới (mặc định status = 'draft')
    }

    public function get_rate_groups_for_card( int $id ): array {
        // Lấy danh sách các rate_group có trong bảng giá (từ ups_rates)
    }

    public function get_conflicting_active_cards( int $id ): array {
        // Tìm các card active khác có trùng lặp rate_group với card $id
    }
    
    public function activate( int $id ): bool {
        // 1. Archive các card active đang xung đột cùng rate_group
        $conflicts = $this->get_conflicting_active_cards( $id );
        foreach ( $conflicts as $conflict_id ) {
            $this->wpdb->update( $this->table, [ 'status' => 'archived' ], [ 'id' => $conflict_id ] );
        }
        // 2. Kích hoạt card chỉ định
        return (bool) $this->wpdb->update( $this->table, [
            'status'       => 'active',
            'activated_at' => current_time( 'mysql' ),
        ], [ 'id' => $id ] );
    }
    
    public function get_active( ?string $rate_group = null ): ?object {
        // Trả về card active theo rate_group, hoặc card active mới nhất
    }

    public function get_active_id( ?string $rate_group = null ): int {
        // Trả về ID card active
    }

    public function get_active_for_rate_group( string $rate_group ): ?object {
        // Query card active có chứa rate_group chỉ định
    }

    public function get_active_id_for_rate_group( string $rate_group ): int {
        // Query ID card active theo rate_group
    }

    public function get_all_active(): array {
        // Danh sách tất cả rate cards đang active
    }
    
    public function get_all(): array {
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY imported_at DESC"
        );
    }
}
```

## 5. Caching

### 5.1. Country list cache
```php
function get_countries_cached( $service_code, $direction ) {
    $key = "ups_countries_{$service_code}_{$direction}";
    $cached = get_transient( $key );
    if ( $cached !== false ) return $cached;
    
    $data = $this->country_repo->get_available( $service_code, $direction );
    set_transient( $key, $data, HOUR_IN_SECONDS );
    return $data;
}
```

### 5.2. Quote Calculation Transients Cache
- Lưu kết quả tính toán dựa trên mã băm MD5 của bộ tham số đầu vào đã chuẩn hóa:
```php
$cache_payload = [
    'rate_card_id'    => $rate_card_id,
    'direction'       => $direction,
    'destination'     => $dest_iata,
    'service_code'    => $service_code,
    'shipment_type'   => $shipment_type,
    'pieces'          => $pieces_params,
];
$cache_key = 'ups_calc_' . md5( wp_json_encode( $cache_payload ) );
$cached_result = get_transient( $cache_key );
if ( false !== $cached_result ) {
    return rest_ensure_response( $cached_result );
}
// Sau khi tính toán thành công:
set_transient( $cache_key, $response_data, HOUR_IN_SECONDS );
```

### 5.3. Cache Invalidation
- Xóa toàn bộ transients tính toán và metadata khi import/activate bảng giá mới:
```php
function on_rate_card_activated() {
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ups_%' OR option_name LIKE '_transient_timeout_ups_%'" );
}
```

## 6. Security

### 6.1. Zero-Leakage Data Sanitization
- Tuyệt đối không xuất dữ liệu zone nội bộ (`wxs`, `xpd`, `wfm`, `zone`) vào frontend hoặc thẻ script:
```php
public function get_active_countries(): array {
    $countries = $this->country_repo->get_active_countries();
    $sanitized = [];
    foreach ( $countries as $c ) {
        $sanitized[] = [
            'iata_code'    => $c['iata_code'],
            'country_name' => $c['country_name'],
        ];
    }
    return $sanitized;
}
```

### 6.2. REST API & Batch Calculation
- Hỗ trợ `service_code: "ALL"` để tính toán cùng lúc tất cả 6 dịch vụ trên server:
```php
register_rest_route( 'ups-quote/v1', '/calculate', [
    'methods'             => 'POST',
    'callback'            => [ $this, 'calculate_quote' ],
    'permission_callback' => '__return_true', // Public endpoint
    'args'                => [
        'direction' => [
            'required'          => false,
            'type'              => 'string',
            'default'           => 'export',
            'enum'              => ['export', 'import'],
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'destination_iata' => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => function( $v ) {
                return preg_match( '/^[A-Z]{2}$/', strtoupper( $v ) );
            },
        ],
        'service_code' => [
            'required' => true,
            'type'     => 'string',
            'enum'     => ['EXW', 'XPR', 'WXS', 'XPD', 'WXP', 'WFM', 'ALL'],
        ],
        // ...
    ],
] );
```

### 6.2. Admin AJAX

```php
// Nonce check
check_ajax_referer( 'allship_ups_admin', 'nonce' );

// Capability check
if ( ! current_user_can( 'manage_options' ) ) {
    wp_send_json_error( 'Unauthorized', 403 );
}

// SQL safety
$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
```

### 6.3. File Upload

```php
$allowed_types = ['text/csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
$max_size = 10 * MB_IN_BYTES; // 10MB

$file = $_FILES['import_file'];
if ( ! in_array( $file['type'], $allowed_types ) ) {
    wp_die( 'File type not allowed' );
}
if ( $file['size'] > $max_size ) {
    wp_die( 'File too large' );
}

// Move to private directory (not public)
$upload_dir = plugin_dir_path( __FILE__ ) . 'private/uploads/';
wp_mkdir_p( $upload_dir );
move_uploaded_file( $file['tmp_name'], $upload_dir . wp_unique_filename( $upload_dir, $file['name'] ) );
```

## 7. Quote Log Export

### 7.1. CSV Export

```php
public function export_csv( array $filters ) {
    $logs = $this->quote_log_repo->get_filtered( $filters );
    
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=quote-logs-' . date('Y-m-d') . '.csv' );
    
    $output = fopen( 'php://output', 'w' );
    // UTF-8 BOM for Excel compatibility
    fputs( $output, "\xEF\xBB\xBF" );
    
    fputcsv( $output, [
        'ID', 'Date', 'Destination', 'Service', 'Shipment Type',
        'Zone', 'Rate Zone', 'Actual Weight', 'Chargeable Weight',
        'Base Price (VND)', 'Total Price (VND)', 'Rate Card'
    ] );
    
    foreach ( $logs as $log ) {
        fputcsv( $output, [ /* ... */ ] );
    }
    
    fclose( $output );
    exit;
}
```

## 8. Hook & Filter Points

```php
// Cho theme/plugin khác extend
apply_filters( 'allship_ups_service_registry', $registry );
apply_filters( 'allship_ups_services', $services, $direction );
apply_filters( 'allship_ups_directions', $directions );
apply_filters( 'allship_ups_surcharges', $fees, $base_rate, $input );
apply_filters( 'allship_ups_quote_result', $result, $input );
apply_filters( 'allship_ups_quote_notes', $notes, $result );
do_action( 'allship_ups_quote_calculated', $result, $input );
do_action( 'allship_ups_rate_card_activated', $rate_card_id );
do_action( 'allship_ups_data_imported', $rate_card_id, $type );
do_action( 'allship_ups_rate_groups_toggled', $rate_card_id, $disabled_groups );
```

### 4.3. Xử lý dữ liệu địa chỉ đến và log báo giá (Phase 1)

Các trường địa chỉ gửi và đến được sanitize và lưu trữ:

```php
$origin_province = isset($params['origin_province']) ? sanitize_text_field($params['origin_province']) : null;
$dest_state      = isset($params['destination_state']) ? sanitize_text_field($params['destination_state']) : null;
$dest_city       = isset($params['destination_city']) ? sanitize_text_field($params['destination_city']) : null;
$dest_postal     = isset($params['destination_postal_code']) ? sanitize_text_field($params['destination_postal_code']) : null;
$dest_address    = isset($params['destination_address']) ? sanitize_text_field($params['destination_address']) : null;

// Ghi log vào {prefix}ups_quote_logs
$wpdb->insert(
    $table_logs,
    [
        'origin_iata'             => 'VN',
        'origin_province'         => $origin_province,
        'destination_iata'        => $dest_iata,
        'destination_state'       => $dest_state,
        'destination_city'        => $dest_city,
        'destination_postal_code' => $dest_postal,
        'destination_address'     => $dest_address,
        'service_code'            => $service_code,
        // ... các trường cân nặng, giá và pieces_json
    ]
);
```

## 9. Calculation Engine Priority & Zone Resolver Resolution (Cập nhật ADR-010)

### 9.1. Ưu tiên Bảng giá Trực tiếp (Direct Rate Card Priority)

Khi khách hàng yêu cầu tính toán gói cước (đặc biệt là các gói cước có khả năng phái sinh như WXP, EXW, XPR):

```php
private function execute_single_service_calc( array $calc_input, string $service_code, $record_log = true ) {
    $rate_group = $this->service_mgr
        ? $this->service_mgr->resolve_rate_group( $calc_input['direction'], $service_code, $calc_input['shipment_type'] )
        : null;

    $service_input                 = $calc_input;
    $service_input['service_code'] = $service_code;
    $service_input['rate_group']   = $rate_group;

    // 1. Kiểm tra xem có bảng giá active trực tiếp cho rate group hay không
    $has_direct_card = false;
    if ( $this->rate_card_repo && ! empty( $rate_group ) && method_exists( $this->rate_card_repo, 'get_active_id_for_rate_group' ) ) {
        $has_direct_card = (bool) $this->rate_card_repo->get_active_id_for_rate_group( $rate_group );
    }

    // 2. Nếu đã có bảng giá active trực tiếp -> Tính toán trực tiếp theo bảng giá đó
    if ( 'WXP' === $service_code ) {
        if ( $has_direct_card ) {
            return $this->calculator->calculate( $service_input );
        }
        // Fallback: Nếu không có bảng giá riêng, tính theo WFM × 1.22
        $base_input                 = $service_input;
        $base_input['service_code'] = 'WFM';
        return $this->calculate_with_multiplier( $base_input, 1.22, 'WXP', 'Express Freight' );
    } elseif ( in_array( $service_code, [ 'EXW', 'XPR' ], true ) ) {
        if ( $has_direct_card ) {
            return $this->calculator->calculate( $service_input );
        }
        // Fallback: Tính theo WXS × 1.25 (EXW) hoặc × 1.15 (XPR)
        $base_input                 = $service_input;
        $base_input['service_code'] = 'WXS';
        $multiplier = ( 'EXW' === $service_code ) ? 1.25 : 1.15;
        return $this->calculate_with_multiplier( $base_input, $multiplier, $service_code );
    }

    return $this->calculator->calculate( $service_input );
}
```

### 9.2. Zone Resolver Resolution & Physical Zone Fallback

`Allship_UPS_Zone_Resolver` hỗ trợ đầy đủ cả 6 dịch vụ UPS và có cơ chế fallback thông minh khi bảng phân vùng không có cột riêng cho dịch vụ phái sinh:

```php
const SUPPORTED_PHASE1_SERVICES = [
    'WXS',
    'XPD',
    'WFM',
    'EXW',
    'XPR',
    'WXP',
];

// Query physical zone
$raw_zone = $this->zone_repo->find_zone( $zone_set_id, $country->id, $direction, $service_code );

// Fallback tra cứu zone physical nếu không map trực tiếp:
if ( null === $raw_zone || '' === trim( $raw_zone ) || '0' === trim( $raw_zone ) ) {
    if ( 'WXP' === $service_code ) {
        $raw_zone = $this->zone_repo->find_zone( $zone_set_id, $country->id, $direction, 'WFM' ); // Cùng freight zone
    } elseif ( in_array( $service_code, [ 'EXW', 'XPR' ], true ) ) {
        $raw_zone = $this->zone_repo->find_zone( $zone_set_id, $country->id, $direction, 'WXS' ); // Cùng express saver zone
    }
}
```

### 9.3. Transient Cache Fingerprinting

Cache key tính cước kết hợp `active_card_fingerprint` để tự động bust cache ngay khi dữ liệu bảng giá thay đổi:

```php
$active_cards = $this->rate_card_repo->get_all_active();
$active_card_fingerprint = implode( ';', array_map( function( $c ) {
    return $c->id . ':' . $c->status . ':' . $c->activated_at;
}, $active_cards ) );

$cache_payload = [
    $direction, $destination_iata, $destination_state,
    $service_code, $shipment_type, $envelope, $clean_pieces,
    $active_card_fingerprint,
];
$cache_key = 'ups_calc_v2_' . md5( wp_json_encode( $cache_payload ) );
```

## 10. FluentForm Bridge & Lead Processing

### 10.1. Class `Allship_UPS_FluentForm_Bridge`

File: `includes/class-fluentform-bridge.php`

Class phụ trách làm cầu nối 2 chiều giữa Allship UPS Quote và FluentForm:

```php
class Allship_UPS_FluentForm_Bridge {
    private $log_repo;
    private $wpdb;

    public function __construct( $log_repo = null, $wpdb = null ) { ... }
    public function init() {
        add_action( 'fluentform/submission_inserted', [ $this, 'on_submission_inserted' ], 20, 3 );
    }

    public function push_lead_to_fluentform( array $lead ): int { ... }
    public function on_submission_inserted( $insert_id, $form_data, $form = null ) { ... }
    private function detect_device_type(): string { ... }
    private function detect_browser(): string { ... }
}
```

**Nguyên lý hoạt động chính:**
1. **Chống lặp đệ quy (`__bridge_sync`)**: Khi `push_lead_to_fluentform()` gọi hook `fluentform/submission_inserted`, mảng dữ liệu có gắn `__bridge_sync = true`. Hàm `on_submission_inserted()` kiểm tra cờ này và lập tức return để tránh vòng lặp vô hạn.
2. **Auto-Detect Form ID**: Đọc từ option `allship_ups_fluentform_id`. Nếu bằng 0, tự động truy vấn tìm form có status `published` và title chứa `UPS` hoặc `Báo Giá`.
3. **Serial Number Calculation**: Luôn tính toán `MAX(serial_number) + 1` theo chuẩn nội bộ của FluentForm.
4. **Metadata Detection**: Phân tích `HTTP_USER_AGENT` để gán chính xác `device` (desktop/tablet/mobile) và `browser` (Chrome, Safari, Firefox, Edge, Opera).

### 10.2. REST Endpoint Lead: `Allship_UPS_REST_Controller::handle_lead`

File: `includes/class-rest-controller.php`

- Route: `POST /wp-json/ups-quote/v1/lead`
- Callback: `handle_lead(WP_REST_Request $request)`
- Rate limiting: Transient `allship_lead_limit_{md5(ip)}`, tối đa 10 lần/5 phút.
- Gắn dữ liệu liên hệ vào `breakdown_json` của quote log:
  ```php
  $breakdown['lead'] = [
      'lead_id'    => $lead_id,
      'name'       => $name,
      'phone'      => $phone,
      'email'      => $lead['email'],
      'notes'      => $lead['notes'],
      'created_at' => $lead['created_at'],
  ];
  $this->quote_log_repo->update( $lead['quote_log_id'], [ 'breakdown_json' => $breakdown ] );
  ```
- Gọi FluentForm Bridge:
  ```php
  if ( class_exists( 'Allship_UPS_FluentForm_Bridge' ) ) {
      $ff_bridge   = new Allship_UPS_FluentForm_Bridge();
      $ff_entry_id = $ff_bridge->push_lead_to_fluentform( $bridge_payload );
  }
  ```

### 10.3. Quản lý và Xoá hàng loạt Quote Logs (Bulk Delete)

1. **Repository Method**: `Allship_UPS_Quote_Log_Repository::delete_multiple(array $ids)`:
   ```php
   public function delete_multiple( array $ids ) {
       $clean_ids = array_filter( array_map( 'absint', $ids ) );
       if ( empty( $clean_ids ) || ! $this->wpdb || empty( $this->table ) ) {
           return 0;
       }
       $placeholders = implode( ',', array_fill( 0, count( $clean_ids ), '%d' ) );
       $sql          = $this->wpdb->prepare( "DELETE FROM {$this->table} WHERE id IN ($placeholders)", $clean_ids );
       return (int) $this->wpdb->query( $sql );
   }
   ```
2. **WP_List_Table Bulk Action**: `Allship_UPS_Quote_Logs_List_Table::process_bulk_action()`:
   - Được gọi trực tiếp tại dòng đầu của `prepare_items()`.
   - Kiểm tra `current_user_can('manage_options')` và thực thi xóa danh sách `$_REQUEST['log_ids']`.
   - Xuất notice `notice-success is-dismissible` thông báo số lượng bản ghi đã xoá thành công mà không gây lỗi `headers already sent`.

---

## 11. PDF Export Engine & Async Background Queue

Chi tiết đầy đủ tham chiếu tại tài liệu [`15-quote-pdf-and-lead-flow.md`](15-quote-pdf-and-lead-flow.md).

### 11.1. Dompdf Engine & Render Performance

- **Class**: `Allship_UPS_PDF_Quote_Service` (`includes/class-pdf-quote-service.php`).
- **Cấu hình Dompdf**:
  - `isRemoteEnabled => true` (hỗ trợ load asset logo, dấu đỏ).
  - `defaultFont => 'DejaVu Sans'` (hỗ trợ tiếng Việt Unicode không bị ô vuông).
  - Tự động fallback nhúng SVG hoặc file path trực tiếp để render < 0.25s.
- **Bố cục A4 đơn trang (Zero overflow)**:
  - Khổ A4 đứng (`210mm x 297mm`), margin `8mm 12mm 10mm 12mm`.
  - Watermark logo trung tâm mờ opacity 0.04.
  - Con dấu điện tử đỏ & chữ ký Allship ở góc phải chân trang.
  - Tên công ty pháp nhân hiển thị trang trọng ngay dưới logo thương hiệu.

### 11.2. Async Background Queue Dispatcher

Xử lý triệt để bài toán độ trễ gửi email SMTP (từ 13.3s giảm còn 0.72s):

```php
// Dispatcher không nghẽn luồng:
private function dispatch_async_email( int $lead_id, int $log_id, string $pdf_path, array $lead_info ): void {
    if ( function_exists( 'fastcgi_finish_request' ) ) {
        // Môi trường PHP-FPM: Đóng kết nối HTTP trước, tiếp tục chạy PHP ngầm
        fastcgi_finish_request();
        $this->process_and_send_quote_emails( $lead_id, $log_id, $pdf_path, $lead_info );
        return;
    }

    // Môi trường CGI/LocalWP/Shared Host: Lên lịch background cron tức thì
    wp_schedule_single_event(
        time(),
        'allship_ups_send_quote_emails_async',
        [ $lead_id, $log_id, $pdf_path, $lead_info ]
    );

    // Kích hoạt worker không chờ đợi (Non-blocking spawn)
    if ( function_exists( 'spawn_cron' ) ) {
        spawn_cron( time() );
    }
}
```

- **Hook đăng ký tại `class-plugin.php`**:
  ```php
  add_action( 'allship_ups_send_quote_emails_async', [ $rest_controller, 'process_and_send_quote_emails' ], 10, 4 );
  ```
- **Bảo mật HMAC Token Download**:
  ```php
  $token = hash_hmac( 'sha256', "ups_quote_{$log_id}_{$lead_id}", wp_salt( 'auth' ) );
  ```
- **Ngắt lặp thông báo FluentForm**:
  Sử dụng custom action `allship_ups_fluentform_bridged` thay vì hook chuẩn `submission_inserted` để tránh loop thông báo trùng lặp.


