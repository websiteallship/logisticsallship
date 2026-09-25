# 04. Database và API spec

## 1. Custom tables

Prefix dùng `$wpdb->prefix`, ví dụ `wp_ups_rate_cards`.

## 2. `ups_rate_cards`

Lưu phiên bản bảng giá. Admin có thể tạo nhiều rate cards với tên tùy ý.
Chỉ 1 rate card `status=active` tại 1 thời điểm cho public quote.

```sql
CREATE TABLE {prefix}ups_rate_cards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  market_code VARCHAR(10) NOT NULL DEFAULT 'VN',
  valid_from DATE NULL,
  source_file_name VARCHAR(255) NULL,
  source_file_hash CHAR(64) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  enabled_directions JSON DEFAULT '["export"]',
  disabled_rate_groups JSON DEFAULT '[]',
  imported_at DATETIME NOT NULL,
  activated_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY status (status),
  KEY market_status (market_code, status)
);
```

| Column | Mô tả |
|--------|-------|
| `enabled_directions` | Chiều vận chuyển admin bật cho card này. Default `["export"]`. |
| `disabled_rate_groups` | Rate groups bị admin tắt thủ công. Default `[]` (tất cả ON). |

## 3. `ups_countries`

```sql
CREATE TABLE {prefix}ups_countries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  iata_code VARCHAR(10) NOT NULL,
  country_name VARCHAR(255) NOT NULL,
  normalized_name VARCHAR(255) NULL,
  is_us_override TINYINT(1) NOT NULL DEFAULT 0,
  has_extended_area_note TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY iata_code (iata_code),
  KEY country_name (country_name)
);
```

## 4. `ups_zone_maps`

```sql
CREATE TABLE {prefix}ups_zone_maps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rate_card_id BIGINT UNSIGNED NOT NULL,
  country_id BIGINT UNSIGNED NOT NULL,
  direction VARCHAR(10) NOT NULL,
  service_code VARCHAR(10) NOT NULL,
  service_type VARCHAR(20) NULL,
  zone VARCHAR(10) NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY unique_zone (rate_card_id, country_id, direction, service_code),
  KEY lookup_zone (rate_card_id, direction, service_code, country_id),
  KEY zone (zone)
);
```

`zone` dùng `VARCHAR` để lưu được `US5` nếu sau này cần, nhưng phase 1 vẫn lưu zone thực `1-10`; `US5` chỉ dùng ở bước chọn cột giá.

## 5. `ups_rates`

```sql
CREATE TABLE {prefix}ups_rates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rate_card_id BIGINT UNSIGNED NOT NULL,
  rate_group VARCHAR(50) NOT NULL,
  zone VARCHAR(10) NOT NULL,
  weight_label VARCHAR(50) NOT NULL,
  weight_from DECIMAL(10,3) NULL,
  weight_to DECIMAL(10,3) NULL,
  billing_unit VARCHAR(20) NOT NULL DEFAULT 'flat',
  price_vnd BIGINT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY unique_rate (rate_card_id, rate_group, zone, weight_label),
  KEY lookup_rate (rate_card_id, rate_group, zone, weight_from, weight_to),
  KEY billing_unit (billing_unit)
);
```

`billing_unit`:

- `flat`: giá trọn cho mốc cân.
- `per_kg`: giá theo kg cho bracket nặng.
- `minimum`: giá tối thiểu, hiện dùng cho `WFM`.

Rate group naming convention: `{direction}_{service_code}_{shipment_type}`. Xem chi tiết tại `01-business-requirements.md` § 3.1.

## 6. `ups_settings`

```sql
CREATE TABLE {prefix}ups_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(100) NOT NULL,
  setting_value LONGTEXT NULL,
  autoload TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY setting_key (setting_key)
);
```

Settings mặc định:

```json
{
  "dim_divisor": 5500,
  "rounding_step_kg": 0.5,
  "include_vat": false,
  "vat_percent": 0,
  "include_fsc": false,
  "fsc_percent": 0,
  "include_surge": false,
  "surge_percent": 0,
  "include_customs_fee": false,
  "customs_fee_vnd": 10000
}
```

> **Deprecated**: `phase_direction`, `enabled_services`, `disabled_services` đã chuyển sang `ups_rate_cards.enabled_directions` và `ups_rate_cards.disabled_rate_groups` (per rate card).

## 7. `ups_quote_logs`

```sql
CREATE TABLE {prefix}ups_quote_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rate_card_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  session_id VARCHAR(100) NULL,
  direction VARCHAR(10) NOT NULL,
  origin_iata VARCHAR(10) NOT NULL DEFAULT 'VN',
  origin_province VARCHAR(100) NULL,
  destination_iata VARCHAR(10) NOT NULL,
  destination_state VARCHAR(100) NULL,
  destination_city VARCHAR(100) NULL,
  destination_postal_code VARCHAR(30) NULL,
  destination_address VARCHAR(255) NULL,
  service_code VARCHAR(10) NOT NULL,
  shipment_type VARCHAR(30) NULL,
  zone VARCHAR(10) NULL,
  rate_zone VARCHAR(10) NULL,
  actual_weight_kg DECIMAL(10,3) NULL,
  dim_weight_kg DECIMAL(10,3) NULL,
  chargeable_weight_kg DECIMAL(10,3) NULL,
  base_price_vnd BIGINT UNSIGNED NULL,
  total_price_vnd BIGINT UNSIGNED NULL,
  pieces_json LONGTEXT NULL,
  breakdown_json LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY created_at (created_at),
  KEY destination (destination_iata),
  KEY service_code (service_code)
);
```

## 8. REST API quote

Endpoint:

```text
POST /wp-json/ups-quote/v1/calculate
```

Request:

```json
{
  "direction": "export",
  "origin_province": "TP. Hồ Chí Minh",
  "destination_iata": "US",
  "destination_state": "CA",
  "destination_city": "Los Angeles",
  "destination_postal_code": "90210",
  "destination_address": "123 Main St, Suite 400",
  "service_code": "WXS",
  "shipment_type": "nondocument",
  "pieces": [
    {
      "quantity": 1,
      "actual_weight_kg": 3.2,
      "length_cm": 30,
      "width_cm": 20,
      "height_cm": 15
    },
    {
      "quantity": 2,
      "actual_weight_kg": 1.1,
      "length_cm": 25,
      "width_cm": 20,
      "height_cm": 10
    }
  ]
}
```

Response thành công:

```json
{
  "success": true,
  "data": {
    "direction": "export",
    "origin_iata": "VN",
    "origin_province": "TP. Hồ Chí Minh",
    "destination_iata": "US",
    "destination_name": "United States*",
    "destination_state": "CA",
    "destination_city": "Los Angeles",
    "destination_postal_code": "90210",
    "destination_address": "123 Main St, Suite 400",
    "service_code": "WXS",
    "service_name": "Worldwide Express Saver",
    "shipment_type": "nondocument",
    "zone": "5",
    "rate_zone": "US5",
    "actual_weight_kg": 5.4,
    "dim_weight_kg": 2.636,
    "chargeable_weight_kg": 6.0,
    "rounding_step_kg": 0.5,
    "dim_divisor": 5500,
    "base_price_vnd": 1977290,
    "fees": [],
    "total_price_vnd": 1977290,
    "currency": "VND",
    "notes": [
      "Giá chưa bao gồm VAT, FSC, Surge fee, customs fee và phụ phí khác nếu có.",
      "United States dùng cột giá riêng US5."
    ],
    "pieces": [
      {
        "actual_weight_kg": 3.2,
        "dim_weight_kg": 1.637,
        "chargeable_weight_kg": 3.5
      },
      {
        "actual_weight_kg": 1.1,
        "dim_weight_kg": 0.909,
        "chargeable_weight_kg": 1.5,
        "quantity": 2
      }
    ]
  }
}
```

Response lỗi:

```json
{
  "success": false,
  "error": {
    "code": "LANE_NOT_AVAILABLE",
    "message": "Dịch vụ WFM chưa hỗ trợ điểm đến này ở chiều Export."
  }
}
```

## 9. REST API support endpoints

### Countries

```text
GET /wp-json/ups-quote/v1/countries?direction=export&service_code=WXS
```

Trả về danh sách country đang có zone khả dụng cho service.

### Directions

```text
GET /wp-json/ups-quote/v1/directions
```

Trả về chiều vận chuyển khả dụng (có ít nhất 1 service enabled):

```json
{
  "success": true,
  "data": [
    {
      "direction": "export",
      "label_vi": "Xuất hàng — Việt Nam → Quốc tế",
      "label_short": "Xuất hàng",
      "enabled": true,
      "service_count": 3
    },
    {
      "direction": "import",
      "label_vi": "Nhập hàng — Quốc tế → Việt Nam",
      "label_short": "Nhập hàng",
      "enabled": false,
      "service_count": 0
    }
  ]
}
```

### Services

```text
GET /wp-json/ups-quote/v1/services?direction=export
```

Trả về dịch vụ theo direction, bao gồm trạng thái bật/tắt, lý do tắt, và số rows:

```json
{
  "success": true,
  "data": [
    {
      "code": "WXS",
      "name": "Worldwide Express Saver",
      "enabled": true,
      "has_data": true,
      "admin_disabled": false,
      "has_document_split": true,
      "reason": null,
      "icon": "ph-airplane-tilt",
      "desc_vi": "Nhanh nhất · Tiết kiệm",
      "eta_vi": "1-3 ngày",
      "row_counts": { "export_wxs_document": 126, "export_wxs_nondocument": 234 }
    },
    { "code": "XPD", "enabled": true, "has_data": true },
    { "code": "WFM", "enabled": false, "has_data": true, "admin_disabled": true, "reason": "Admin đã tắt dịch vụ này" },
    { "code": "EXW", "enabled": false, "has_data": false, "reason": "Chưa có bảng giá trong rate card hiện tại" }
  ]
}
```

Default `direction=export` nếu không truyền (backward compatible).

## 10. Shortcode

```text
[ups_quote_form]    (shortcode tự động chèn vào page "Báo giá UPS" khi activate)
```

Options đề xuất:

```text
[ups_quote_form default_service="WXS" show_notes="1"]
```

Shortcode render form, enqueue JS/CSS, truyền nonce và API URL qua `wp_localize_script`.
