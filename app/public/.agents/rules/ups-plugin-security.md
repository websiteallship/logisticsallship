# UPS Plugin Security Rules

> BẮT BUỘC cho mọi file PHP/JS trong plugin `allship-ups-quote`.

## PHP Security

### Input Sanitization (KHÔNG ĐƯỢC bỏ qua)

```php
// Text fields
sanitize_text_field( $input )

// Numbers
absint( $input )        // unsigned int
floatval( $input )      // float

// Arrays
array_map( 'sanitize_text_field', $input )
```

### Output Escaping (KHÔNG ĐƯỢC bỏ qua)

```php
esc_html( $text )       // Text display
esc_attr( $value )      // HTML attributes
esc_url( $url )         // URLs (href, src)
wp_kses_post( $html )   // Rich text
```

### Database Security

- **LUÔN** dùng `$wpdb->prepare()` cho mọi query có user input.
- **KHÔNG BAO GIỜ** interpolate biến trực tiếp vào SQL.

```php
// ĐÚNG:
$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );

// SAI:
$wpdb->query( "SELECT * FROM {$table} WHERE id = {$id}" );
```

### Capability & Nonce

- Admin actions: kiểm tra `current_user_can('manage_options')`.
- Admin AJAX: `check_ajax_referer('allship_ups_admin', 'nonce')`.
- REST API args: validate server-side với `args` schema.
- Public REST endpoint: `permission_callback => '__return_true'` (nhưng validate args).

### File Upload

- MIME check: chỉ `.csv`, `.xlsx`.
- Size limit: `10MB`.
- Lưu vào **private directory** (KHÔNG public).
- Xóa file sau khi import xong.

### Direct Access Prevention

Mọi file PHP PHẢI có:
```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

## REST API Security

```php
register_rest_route( 'ups-quote/v1', '/calculate', [
    'methods'             => 'POST',
    'permission_callback' => '__return_true',
    'args'                => [
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
            'enum'     => ['WXS', 'XPD', 'WFM'],
        ],
    ],
] );
```

## JavaScript Security

- KHÔNG trust client-side data — validate EVERYTHING server-side.
- Dùng `wp_localize_script()` truyền nonce + API URL (KHÔNG hardcode).
- Escape HTML khi render dynamic content vào DOM.
