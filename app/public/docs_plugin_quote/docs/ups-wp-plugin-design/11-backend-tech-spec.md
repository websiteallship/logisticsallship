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
│   └── Quote_Log_Repository    → ups_quote_logs
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
├── REST_Controller             → /calculate, /countries, /services, /directions
├── Shortcode                   → [ups_quote_form]
└── Admin
    ├── Admin_Menu              → menu registration
    ├── Admin_Import            → import UI + AJAX
    ├── Admin_Rates             → rate CRUD UI
    ├── Admin_Zones             → zone CRUD UI
    ├── Admin_Countries         → country CRUD UI
    ├── Admin_Settings          → settings form (weight + fees only)
    ├── Admin_Rate_Cards        → rate card management + direction/service toggles
    └── Admin_Quote_Logs        → logs + export
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

### 4.2. Quy tắc

- **Một** rate card active tại một thời điểm cho public.
- Admin có thể tạo nhiều rate card (draft/archived) để chuẩn bị trước.
- Khi activate card mới, card cũ tự động chuyển archived.
- Admin có thể test draft card bằng cách truyền `rate_card_id` trong API.
- Rate card name hiển thị trong kết quả báo giá và quote logs.

### 4.3. Rate Card Repository

```php
class Rate_Card_Repository {
    public function create( array $data ): int {
        // $data = ['name' => 'UPS VN Rates Q3-2026', 'valid_from' => '2026-08-20', ...]
    }
    
    public function activate( int $id ): void {
        // Deactivate current active → set new active
        $this->wpdb->update( $this->table, ['status' => 'archived'], ['status' => 'active'] );
        $this->wpdb->update( $this->table, [
            'status' => 'active',
            'activated_at' => current_time( 'mysql' )
        ], ['id' => $id] );
    }
    
    public function get_active(): ?object {
        return $this->wpdb->get_row(
            "SELECT * FROM {$this->table} WHERE status = 'active' LIMIT 1"
        );
    }
    
    public function get_all(): array {
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY imported_at DESC"
        );
    }
}
```

## 5. Caching

```php
// Country list (thay đổi ít)
function get_countries_cached( $service_code, $direction ) {
    $key = "ups_countries_{$service_code}_{$direction}";
    $cached = get_transient( $key );
    if ( $cached !== false ) return $cached;
    
    $data = $this->country_repo->get_available( $service_code, $direction );
    set_transient( $key, $data, HOUR_IN_SECONDS );
    return $data;
}

// Invalidate khi import mới
function on_rate_card_activated() {
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ups_%'" );
}
```

## 6. Security

### 6.1. REST API

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
            'enum'     => ['EXW', 'XPR', 'WXS', 'XPD', 'WXP', 'WFM'],
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
