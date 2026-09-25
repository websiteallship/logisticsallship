# UPS Plugin Coding Standards

## PHP Conventions

### Naming

| Element | Convention | Example |
|---------|-----------|---------|
| Class | `Allship_UPS_*` | `Allship_UPS_Weight_Calculator` |
| Method | `snake_case` | `calculate_dim_weight()` |
| Variable | `$snake_case` | `$rate_card_id` |
| Constant | `UPPER_SNAKE` | `ALLSHIP_UPS_QUOTE_VERSION` |
| Hook prefix | `allship_ups_` | `allship_ups_surcharges` |
| DB table prefix | `ups_` (sau `$wpdb->prefix`) | `wp_ups_rate_cards` |
| Option prefix | `allship_ups_` | `allship_ups_db_version` |
| Transient prefix | `ups_` | `ups_countries_WXS_export` |
| REST namespace | `ups-quote/v1` | `/wp-json/ups-quote/v1/calculate` |
| Nonce name | `allship_ups_admin` | — |

### Class Structure

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Allship_UPS_Example {
    private $wpdb;
    private $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ups_example';
    }
}
```

### Error Handling

Dùng structured error codes (KHÔNG dùng generic messages):

| Error Code | HTTP | Ý nghĩa |
|-----------|------|---------|
| `SERVICE_NOT_SUPPORTED` | 422 | Dịch vụ chưa mở phase 1 |
| `UNKNOWN_DESTINATION` | 422 | Country không tồn tại |
| `LANE_NOT_AVAILABLE` | 422 | Zone = 0 cho service/destination |
| `RATE_NOT_FOUND` | 422 | Không tìm được dòng giá |
| `DOCUMENT_OVER_5KG` | 422 | Document > 5kg |
| `INVALID_RATE_FILE` | 400 | File import sai format |
| `QUOTE_INTERNAL_ERROR` | 500 | Lỗi server |

### Hooks & Filters

```php
// Filter points (cho theme/plugin extend)
apply_filters( 'allship_ups_services', $services );
apply_filters( 'allship_ups_surcharges', $fees, $base_rate, $input );
apply_filters( 'allship_ups_quote_result', $result, $input );
apply_filters( 'allship_ups_quote_notes', $notes, $result );

// Action points
do_action( 'allship_ups_quote_calculated', $result, $input );
do_action( 'allship_ups_rate_card_activated', $rate_card_id );
do_action( 'allship_ups_data_imported', $rate_card_id, $type );
```

## JavaScript Conventions

### Namespace

```javascript
window.UPSQuote = window.UPSQuote || {};
```

### CSS Prefix

- Tất cả classes: `ups-quote-*`
- Container: `#ups-quote-form`
- KHÔNG modify DOM ngoài container

### Event Pattern

```javascript
document.addEventListener('DOMContentLoaded', function() {
    // Init
});
```

### API Calls

```javascript
const response = await fetch(`${upsQuoteConfig.apiBase}/calculate`, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': upsQuoteConfig.nonce
    },
    body: JSON.stringify(data)
});
```

## File Structure

```
wp-content/plugins/allship-ups-quote/
├── allship-ups-quote.php          # Bootstrap
├── uninstall.php                  # Cleanup
├── includes/
│   ├── class-plugin.php           # Main class
│   ├── class-activator.php        # DB migration
│   ├── class-deactivator.php      # Transient cleanup
│   ├── class-settings-manager.php # Settings CRUD
│   ├── class-rate-card-repository.php
│   ├── class-country-repository.php
│   ├── class-zone-repository.php
│   ├── class-rate-repository.php
│   ├── class-quote-log-repository.php
│   ├── class-weight-calculator.php
│   ├── class-zone-resolver.php
│   ├── class-rate-lookup.php
│   ├── class-surcharge-engine.php
│   ├── class-quote-calculator.php
│   ├── class-rest-controller.php
│   ├── class-shortcode.php
│   └── importers/
│       ├── class-csv-parser.php
│       ├── class-xlsx-reader.php
│       ├── class-rate-importer.php
│       ├── class-zone-importer.php
│       └── class-import-orchestrator.php
├── admin/
│   ├── class-admin-menu.php
│   ├── views/ (rate-cards, import, rates-edit, zones-edit, countries-edit, settings, quote-logs)
│   └── assets/ (css/admin.css, js/admin.js)
├── public/
│   ├── views/quote-form.php
│   └── assets/ (css/quote-form.css, js/quote-form.js)
├── libs/
│   └── SimpleXLSX.php
└── templates/
    ├── rate-template.csv
    └── zone-template.csv
```

## Encoding Safety (Kế thừa từ AGENTS.md)

- **KHÔNG** dùng PowerShell `Get-Content`, `Set-Content` cho file chứa tiếng Việt.
- **LUÔN** dùng Node.js `fs.readFileSync/writeFileSync` cho file operations có UTF-8.
