# 10. Frontend Technical Specification

## 1. Kiến trúc tổng quan

### 1.1. Nguyên tắc

- **Vanilla JS** (ES6+), không framework (React/Vue/jQuery).
- **Zero extra dependencies** — tận dụng thư viện đã có trong theme.
- **Progressive enhancement** — form hoạt động cơ bản nếu JS lỗi.
- **Mobile-first responsive**.

### 1.2. Tech stack frontend

| Thành phần | Công nghệ | Nguồn |
|------------|-----------|-------|
| DOM manipulation | Vanilla JS | Native |
| HTTP requests | Fetch API | Native |
| Number format | `Intl.NumberFormat('vi-VN')` | Native |
| Icons | Phosphor Icons | Có sẵn trong theme CDN |
| Animations | GSAP 3.12 | Có sẵn trong theme CDN |
| Fonts | Manrope (woff2) | Có sẵn trong theme |
| CSS | Custom Properties + Vanilla | Plugin CSS riêng |

### 1.3. File structure

```
allship-ups-quote/
├── public/
│   ├── assets/
│   │   ├── css/
│   │   │   └── quote-form.css       # Form styles
│   │   └── js/
│   │       └── quote-form.js        # Form logic
│   └── views/
│       └── quote-form.php           # Shortcode template
```

## 2. API Contract

### 2.1. POST `/wp-json/ups-quote/v1/calculate`

**Request:**

```json
{
  "direction": "export",
  "destination_iata": "US",
  "service_code": "WXS",
  "shipment_type": "nondocument",
  "rate_card_id": null,
  "origin_province": "TP. Hồ Chí Minh",
  "destination_state": "CA",
  "destination_city": "Los Angeles",
  "destination_postal_code": "90210",
  "destination_address": "123 Main St, Suite 400",
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

Ghi chú: `rate_card_id` = null → dùng rate card active. Admin có thể truyền ID cụ thể để test draft.

**Response thành công:**

```json
{
  "success": true,
  "data": {
    "rate_card": {
      "id": 3,
      "name": "UPS VN Net Rates Q3-2026"
    },
    "direction": "export",
    "origin_iata": "VN",
    "destination_iata": "US",
    "destination_name": "United States*",
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
      "Giá tạm tính theo bảng cước \"UPS VN Net Rates Q3-2026\", hiệu lực từ 20/08/2026.",
      "Giá chưa bao gồm VAT, FSC, Surge fee, customs fee và các phụ phí khác nếu có.",
      "Cân tính cước được làm tròn lên mốc 0.5kg.",
      "United States dùng cột giá riêng US5."
    ],
    "pieces": [
      {
        "index": 1,
        "actual_weight_kg": 3.2,
        "dim_weight_kg": 1.636,
        "chargeable_weight_kg": 3.5
      },
      {
        "index": 2,
        "quantity": 2,
        "actual_weight_kg": 1.1,
        "dim_weight_kg": 0.909,
        "chargeable_weight_kg": 1.5,
        "subtotal_chargeable_kg": 3.0
      }
    ]
  }
}
```

**Response lỗi:**

```json
{
  "success": false,
  "error": {
    "code": "LANE_NOT_AVAILABLE",
    "message": "Dịch vụ WFM chưa hỗ trợ điểm đến này ở chiều Export."
  }
}
```

### 2.2. GET `/wp-json/ups-quote/v1/countries`

Query params: `?direction=export&service_code=WXS`

**Response:**

```json
{
  "success": true,
  "data": [
    { "iata_code": "AE", "country_name": "United Arab Emirates", "zone": "7" },
    { "iata_code": "US", "country_name": "United States*", "zone": "5" }
  ]
}
```

### 2.3. GET `/wp-json/ups-quote/v1/services?direction=export`

**Response:**

```json
{
  "success": true,
  "data": [
    { "code": "EXW", "name": "Express Worldwide", "enabled": false, "has_data": false, "has_document_split": true, "reason": "Chưa có bảng giá trong rate card hiện tại", "icon": "ph-globe", "desc_vi": "Chuyển phát nhanh toàn cầu", "eta_vi": "1-2 ngày" },
    { "code": "XPR", "name": "Express", "enabled": false, "has_data": false, "has_document_split": true, "reason": "Chưa có bảng giá trong rate card hiện tại", "icon": "ph-rocket-launch" },
    { "code": "WXS", "name": "Worldwide Express Saver", "enabled": true, "has_data": true, "has_document_split": true, "icon": "ph-airplane-tilt", "desc_vi": "Nhanh nhất · Tiết kiệm", "eta_vi": "1-3 ngày", "row_counts": { "export_wxs_document": 126, "export_wxs_nondocument": 234 } },
    { "code": "XPD", "name": "Expedited", "enabled": true, "has_data": true, "has_document_split": false, "icon": "ph-truck", "desc_vi": "Tiết kiệm", "eta_vi": "3-5 ngày" },
    { "code": "WXP", "name": "Worldwide Express Plus", "enabled": false, "has_data": false, "has_document_split": false, "reason": "Chưa có bảng giá", "icon": "ph-lightning" },
    { "code": "WFM", "name": "Worldwide Express Freight", "enabled": true, "has_data": true, "has_document_split": false, "icon": "ph-crane", "desc_vi": "Hàng nặng >70kg", "eta_vi": "3-5 ngày" }
  ]
}
```

Default `direction=export` nếu không truyền (backward compat).

### 2.4. GET `/wp-json/ups-quote/v1/directions`

**Response:**

```json
{
  "success": true,
  "data": [
    { "direction": "export", "label_vi": "Xuất hàng — Việt Nam → Quốc tế", "label_short": "Xuất hàng", "enabled": true, "service_count": 3 },
    { "direction": "import", "label_vi": "Nhập hàng — Quốc tế → Việt Nam", "label_short": "Nhập hàng", "enabled": false, "service_count": 0 }
  ]
}
```

## 3. UI Components

### 3.1. Quote Form Layout

```
┌─────────────────────────────────────────────────┐
│  📦 BÁO GIÁ CƯỚC UPS                           │
│  Chiều gửi: Việt Nam → Quốc tế                 │
├─────────────────────────────────────────────────┤
│                                                 │
│  Dịch vụ vận chuyển *                           │
│  ┌──────────────────────────────────────┐       │
│  │ ○ Worldwide Express Saver           │       │
│  │ ○ Expedited                          │       │
│  │ ○ Worldwide Express Freight          │       │
│  └──────────────────────────────────────┘       │
│                                                 │
│  Loại hàng * (chỉ hiện khi chọn WXS)           │
│  ┌──────────────────────────────────────┐       │
│  │ ○ Tài liệu (Document)               │       │
│  │ ○ Hàng hóa (Non-document)           │       │
│  └──────────────────────────────────────┘       │
│                                                 │
│  Điểm đến *                                     │
│  ┌──────────────────────────────────────┐       │
│  │ 🔍 Tìm quốc gia hoặc mã IATA...    │       │
│  └──────────────────────────────────────┘       │
│                                                 │
│  Thông tin kiện hàng                            │
│  ┌────┬────────┬──────┬──────┬──────┬──┐       │
│  │ #  │ SL     │ Cân  │ Dài  │ Rộng │ Cao│      │
│  │    │        │ (kg) │ (cm) │ (cm) │(cm)│      │
│  ├────┼────────┼──────┼──────┼──────┼──┤       │
│  │ 1  │ [1]    │[3.2] │[30]  │[20]  │[15]│     │
│  │ 2  │ [2]    │[1.1] │[25]  │[20]  │[10]│     │
│  └────┴────────┴──────┴──────┴──────┴──┘       │
│  [+ Thêm kiện]                 [🗑 Xóa kiện]   │
│                                                 │
│  ┌──────────────────────────────────────┐       │
│  │         TÍNH GIÁ CƯỚC               │       │
│  └──────────────────────────────────────┘       │
│                                                 │
├─────────────────────────────────────────────────┤
│  KẾT QUẢ BÁO GIÁ                               │
│                                                 │
│  Dịch vụ:        Worldwide Express Saver (WXS) │
│  Điểm đến:       United States* (US)            │
│  Zone:           5 → Cột giá: US5              │
│  ─────────────────────────────────────────────  │
│  Cân thực tế:    5.4 kg                         │
│  Cân quy đổi:    2.636 kg                       │
│  Cân tính cước:  6.0 kg                         │
│  ─────────────────────────────────────────────  │
│  Giá cước:       1.977.290 VND                  │
│  ─────────────────────────────────────────────  │
│  ⓘ Giá chưa bao gồm VAT, FSC, Surge fee,      │
│    customs fee và các phụ phí khác nếu có.      │
│                                                 │
│  Chi tiết từng kiện:                            │
│  Kiện 1: 3.2kg → dim 1.6kg → tính cước 3.5kg  │
│  Kiện 2: 1.1kg ×2 → dim 0.9kg → tính 1.5kg ×2 │
└─────────────────────────────────────────────────┘
```

### 3.2. Searchable Country Dropdown

Tự viết lightweight (không thêm library):

```javascript
class CountrySelect {
  // Features:
  // - Input text filter (search by name or IATA code)
  // - Dropdown list với scroll
  // - Keyboard navigation (arrow up/down, enter, escape)
  // - Hiển thị: "US — United States*" hoặc "AE — United Arab Emirates"
  // - Lazy load: fetch countries khi user focus vào field
  // - Debounce search: 300ms
  // - Accessible: aria-label, role="listbox", role="option"
}
```

### 3.3. Piece Table

```javascript
class PieceTable {
  // Features:
  // - Dynamic add/remove rows
  // - Input validation: positive numbers, max values
  // - Min 1 row, max 20 rows
  // - Auto-calculate dim weight preview (client-side)
  // - Tab navigation giữa các fields
}
```

### 3.4. Direction Selector

```javascript
class DirectionSelector {
  // Features:
  // - Load available directions from API /directions
  // - Hide selector if only 1 direction enabled
  // - On direction change:
  //   - Reload service tabs from API /services?direction=...
  //   - Update origin/destination labels (export: VN→Quốc tế, import: Quốc tế→VN)
  //   - Reset selected service to first enabled
  // - Visual: 2 tabs “Xuất hàng” / “Nhập hàng”
  // - Disabled direction: muted + tooltip
}
```

### 3.5. Dynamic Service Tabs

```javascript
class ServiceTabs {
  // Features:
  // - Render from API /services?direction=... response
  // - Use icon, color_bg, color_icon, desc_vi, eta_vi from API
  // - Enabled services: clickable tabs
  // - Admin-disabled (has_data=true, admin_disabled=true): muted tab + tooltip
  // - No-data services: hidden
  // - On service change: show/hide shipment type toggle (has_document_split)
}
```

## 4. Enqueue Strategy

```php
// Chỉ load trên page có shortcode
function allship_ups_enqueue_public() {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'ups_quote_form' ) ) {
        return;
    }

    $uri = plugin_dir_url( __FILE__ ) . 'public/assets';
    $ver = ALLSHIP_UPS_QUOTE_VERSION;

    wp_enqueue_style( 'ups-quote-form', $uri . '/css/quote-form.css', [], $ver );
    wp_enqueue_script( 'ups-quote-form', $uri . '/js/quote-form.js', [], $ver, true );

    wp_localize_script( 'ups-quote-form', 'upsQuoteConfig', [
        'apiBase'  => esc_url_raw( rest_url( 'ups-quote/v1' ) ),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
        'currency' => 'VND',
    ] );
}
```

## 5. CSS Strategy

### 5.1. Token sharing với theme

```css
/* quote-form.css */
.ups-quote-form {
  /* Kế thừa từ theme */
  font-family: 'Manrope', system-ui, sans-serif;
  --ups-brand: var(--color-brand-red, #CE2027);
  --ups-brand-hover: var(--color-brand-red-hover, #9F090E);
  --ups-navy: var(--color-navy-900, #0A1628);

  /* Plugin-specific tokens */
  --ups-card-bg: #ffffff;
  --ups-card-border: #e5e7eb;
  --ups-card-radius: 12px;
  --ups-input-radius: 8px;
  --ups-success: #059669;
  --ups-error: #dc2626;
  --ups-warning: #d97706;
}
```

### 5.2. CSS class prefix

Tất cả classes plugin dùng prefix `ups-quote-` để tránh conflict:
- `ups-quote-form`
- `ups-quote-result`
- `ups-quote-piece-row`
- `ups-quote-country-select`

## 6. Animation Strategy

```javascript
// Detect GSAP từ theme
function animateResult(el) {
  if (window.gsap) {
    gsap.from(el, {
      y: 20, opacity: 0, duration: 0.5,
      ease: 'power2.out'
    });
  } else {
    // CSS fallback
    el.classList.add('ups-quote-fade-in');
  }
}
```

CSS fallback:

```css
.ups-quote-fade-in {
  animation: upsQuoteFadeIn 0.4s ease-out;
}

@keyframes upsQuoteFadeIn {
  from { opacity: 0; transform: translateY(16px); }
  to   { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
  .ups-quote-fade-in { animation: none; }
}
```

## 7. Error Handling UI

| Error code | Hiển thị |
|------------|----------|
| `SERVICE_NOT_SUPPORTED` | Banner warning trên form |
| `UNKNOWN_DESTINATION` | Inline error dưới country field |
| `LANE_NOT_AVAILABLE` | Result area: alert card |
| `RATE_NOT_FOUND` | Result area: alert card |
| `DOCUMENT_OVER_5KG` | Inline error + gợi ý chuyển Non-document |
| Network error | Toast notification |

## 8. Accessibility

- Label cho mọi input (không chỉ placeholder).
- `aria-required="true"` cho required fields.
- `role="alert"` cho error messages.
- Keyboard: Tab order logic, Enter submit.
- Focus trap trong dropdown.
- Color contrast ratio ≥ 4.5:1.
- Screen reader: `aria-live="polite"` cho result area.

### 2.4. Xử lý địa chỉ đến (Phase 1: Static JSON + Text Zipcode)

- **Data source**: `states_by_country.json` (~185KB) được load vào client-side (qua script tag `states_by_country.js` hoặc `wp_localize_script`).
- **Conditional logic**:
  - Khi user chọn Quốc gia:
    - Tra cứu danh sách bang từ `STATES_BY_COUNTRY[iata]`.
    - Cập nhật nhãn thích ứng (US: State/ZIP Code; CA: Province/Postal Code; AU: State/Postcode; JP: Prefecture/Postal Code; DE: Bundesland/PLZ).
    - Render dropdown Bang/Tỉnh. Nếu quốc gia không phân bang, disable dropdown với thông báo phù hợp.
  - Khi user chọn Bang:
    - Tra cứu danh mục thành phố từ `MAJOR_CITIES_BY_STATE[iata][state]`.
    - Render dropdown Thành phố kèm tuỳ chọn "Khác (nhập địa chỉ cụ thể bên dưới)".
  - Trường Zipcode và Địa chỉ cụ thể: Input text nhập tay tự do.
- **Booking modal & Results ribbon**: Tự động hiển thị tóm tắt đầy đủ các thành phần địa chỉ đã nhập (ví dụ: `Los Angeles, California (90210) · United States`).
