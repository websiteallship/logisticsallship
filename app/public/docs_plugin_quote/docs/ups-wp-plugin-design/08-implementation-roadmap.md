# 08. Implementation Roadmap — Chi tiết từng Phase/Step

> Plugin: **Allship UPS Quote** (`allship-ups-quote`)
> Cập nhật: 24/09/2026

---

## Quy ước ký hiệu

| Ký hiệu | Ý nghĩa |
|----------|----------|
| 🛠 Skills | Skills cần đọc SKILL.md trước khi bắt đầu step |
| 📏 Rules | Rules bắt buộc tuân thủ trong step |
| 📖 Docs | Tài liệu thiết kế cần tham khảo |
| 🧪 Tests | Unit tests / verification sau step |
| ✅ Gate | Điều kiện PHẢI pass trước khi sang step tiếp |

---

## Phase 0: Plugin Skeleton & Database (Foundation)

> **Mục tiêu**: Plugin activate được, tables tạo đúng, có settings mặc định, page tự tạo.

### Step 0.1 — Tạo plugin bootstrap

🛠 **Skills**:
- `wp-plugin-development` — Plugin architecture, activation/deactivation, hooks
- `wordpress` — WP coding standards, plugin boilerplate
- `software-architecture` — Module separation, DI pattern
- `clean-code` — Naming, file organization

📏 **Rules**:
- `ups-plugin-architecture.md` — Plugin identity, constants, structure
- `ups-plugin-coding-standards.md` — Naming convention, class structure
- `ups-plugin-security.md` — Direct access prevention (`ABSPATH` check)

📖 **Docs**:
- `03-system-architecture.md` § 1-2 — Kiến trúc, thành phần chính
- `11-backend-tech-spec.md` § 1.1-1.3 — PHP architecture, autoloading

**Tasks**:
- [ ] Tạo thư mục `wp-content/plugins/allship-ups-quote/`.
- [ ] Tạo `allship-ups-quote.php` với plugin header:
  - Plugin Name: `Allship UPS Quote`
  - Version: `1.0.0`
  - Text Domain: `allship-ups-quote`
- [ ] Define constants: `ALLSHIP_UPS_QUOTE_VERSION`, `ALLSHIP_UPS_QUOTE_PATH`, `ALLSHIP_UPS_QUOTE_URL`.
- [ ] Tạo `includes/class-plugin.php` — main plugin class, register hooks.
- [ ] Manual autoload: require tất cả includes khi `plugins_loaded`.
- [ ] Thêm `if (!defined('ABSPATH')) exit;` cho MỌI file PHP.

🧪 **Tests Step 0.1**:
- [ ] File `allship-ups-quote.php` có đúng plugin header.
- [ ] Constants defined đúng giá trị.
- [ ] `class-plugin.php` load được không lỗi.
- [ ] Plugin xuất hiện trong WP Admin → Plugins list.

---

### Step 0.2 — Database migration (Activator)

🛠 **Skills**:
- `wp-plugin-development` — Activation hooks, dbDelta
- `php-pro` — SQL generation, $wpdb usage
- `backend-security-coder` — SQL injection prevention

📏 **Rules**:
- `ups-plugin-architecture.md` — 6 custom tables schema
- `ups-plugin-security.md` — `$wpdb->prepare()`, sanitization
- `ups-plugin-coding-standards.md` — DB table prefix `ups_`

📖 **Docs**:
- `04-database-and-api-spec.md` § 1-7 — Full schema cho 6 tables
- `11-backend-tech-spec.md` § 3.1-3.2 — Version tracking, dbDelta

**Tasks**:
- [ ] Tạo `includes/class-activator.php`.
- [ ] Viết `dbDelta()` tạo 6 tables:
  - `{prefix}ups_rate_cards` — phiên bản bảng giá, hỗ trợ đặt tên tùy ý.
  - `{prefix}ups_countries` — danh sách quốc gia/IATA.
  - `{prefix}ups_zone_maps` — zone mapping theo rate_card/country/direction/service.
  - `{prefix}ups_rates` — bảng giá theo rate_group/zone/weight.
  - `{prefix}ups_settings` — cấu hình plugin.
  - `{prefix}ups_quote_logs` — log báo giá (gồm origin_province, destination_state, destination_city, destination_postal_code, destination_address).
- [ ] Lưu schema version vào `wp_options` (`allship_ups_db_version`).
- [ ] Idempotent: chạy lại không lỗi, không mất data.

🧪 **Tests Step 0.2**:
```sql
-- Kiểm tra 6 tables tồn tại:
SHOW TABLES LIKE '%ups_rate_cards';
SHOW TABLES LIKE '%ups_countries';
SHOW TABLES LIKE '%ups_zone_maps';
SHOW TABLES LIKE '%ups_rates';
SHOW TABLES LIKE '%ups_settings';
SHOW TABLES LIKE '%ups_quote_logs';

-- Kiểm tra schema version:
SELECT option_value FROM wp_options WHERE option_name = 'allship_ups_db_version';
-- Expected: '1.0.0'
```
- [ ] 6 tables có đúng columns theo `04-database-and-api-spec.md`.
- [ ] Chạy activator 2 lần liên tiếp → không lỗi, không duplicate.

---

### Step 0.3 — Default settings

🛠 **Skills**:
- `wp-plugin-development` — Options API
- `php-pro` — JSON encode/decode

📏 **Rules**:
- `ups-plugin-architecture.md` — Default values
- `ups-plugin-coding-standards.md` — Naming convention

📖 **Docs**:
- `04-database-and-api-spec.md` § 6 — Settings schema + defaults

**Tasks**:
- [ ] Insert settings mặc định khi activate:
  - `dim_divisor` = `5500`
  - `rounding_step_kg` = `0.5`
  - `include_vat` = `false`, `vat_percent` = `0`
  - `include_fsc` = `false`, `fsc_percent` = `0`
  - `include_surge` = `false`, `surge_percent` = `0`
  - `include_customs_fee` = `false`, `customs_fee_vnd` = `10000`

> **Note**: `phase_direction`, `enabled_services`, `disabled_services` đã deprecated — chuyển sang `ups_rate_cards.enabled_directions` và `ups_rate_cards.disabled_rate_groups` (per rate card).

🧪 **Tests Step 0.3**:
```sql
SELECT * FROM wp_ups_settings;
-- Expected: 10+ rows với setting_key/setting_value đúng defaults
```
- [ ] Mỗi setting key tồn tại và có giá trị mặc định.
- [ ] Insert lại không duplicate.

---

### Step 0.4 — Auto-create page

🛠 **Skills**:
- `wp-plugin-development` — wp_insert_post, options
- `wordpress` — Page management

📏 **Rules**:
- `ups-plugin-architecture.md` — Page slug `bao-gia-ups`

📖 **Docs**:
- `11-backend-tech-spec.md` § 3.3 — Auto-create page logic

**Tasks**:
- [ ] Tạo page "Báo giá UPS" với slug `bao-gia-ups`.
- [ ] Nội dung page: `[ups_quote_form]`.
- [ ] Lưu page ID vào `wp_options` (`allship_ups_quote_page_id`).
- [ ] Kiểm tra page tồn tại trước khi tạo (idempotent).

🧪 **Tests Step 0.4**:
- [ ] Page "Báo giá UPS" tồn tại trong WP Admin → Pages.
- [ ] Page content = `[ups_quote_form]`.
- [ ] `get_option('allship_ups_quote_page_id')` trả đúng page ID.
- [ ] Deactivate → activate → KHÔNG tạo page thứ 2.

---

### Step 0.5 — Deactivator & Uninstall

🛠 **Skills**:
- `wp-plugin-development` — Deactivation, uninstall hooks
- `backend-security-coder` — Safe data cleanup

📏 **Rules**:
- `ups-plugin-security.md` — Capability check
- `ups-plugin-coding-standards.md` — File structure

📖 **Docs**:
- `07-adr.md` — ADR decisions on data retention

**Tasks**:
- [ ] Tạo `includes/class-deactivator.php` — cleanup transients.
- [ ] Tạo `uninstall.php` — drop tables + delete page nếu setting `delete_data_on_uninstall`.

🧪 **Tests Step 0.5**:
- [ ] Deactivate → transients cleared.
- [ ] Uninstall (khi `delete_data_on_uninstall` = true) → 6 tables dropped, page deleted.
- [ ] Uninstall (khi `delete_data_on_uninstall` = false) → data giữ nguyên.

---

### Step 0.6 — Phase 0 Verification

✅ **Gate — PHẢI pass ALL trước khi sang Phase 1**:

| # | Check | Status |
|---|-------|--------|
| 1 | Plugin activate → không PHP error/warning | ☐ |
| 2 | 6 tables tồn tại trong DB đúng schema | ☐ |
| 3 | Page "Báo giá UPS" tồn tại với shortcode | ☐ |
| 4 | Deactivate → activate lại → không duplicate | ☐ |
| 5 | Settings mặc định đọc được | ☐ |
| 6 | Không `ABSPATH` bypass trên bất kỳ file nào | ☐ |

---

## Phase 1: Data Layer (Repositories + Settings)

> **Mục tiêu**: CRUD hoàn chỉnh cho tất cả tables, sẵn sàng cho importer và calculator.

### Step 1.1 — Settings Manager

🛠 **Skills**:
- `php-pro` — OOP patterns, static caching
- `clean-code` — Single Responsibility, method naming
- `backend-security-coder` — Input sanitization

📏 **Rules**:
- `ups-plugin-coding-standards.md` — Class naming `Allship_UPS_Settings_Manager`
- `ups-plugin-security.md` — Sanitize values khi set

📖 **Docs**:
- `04-database-and-api-spec.md` § 6 — Settings table schema
- `11-backend-tech-spec.md` § 1.2 — Class diagram

**Tasks**:
- [ ] Tạo `includes/class-settings-manager.php`.
- [ ] Methods: `get($key, $default)`, `set($key, $value)`, `get_all()`, `delete($key)`.
- [ ] Cache trong memory (static property) trong 1 request.
- [ ] Sanitize values khi set.

🧪 **Tests Step 1.1**:
```php
$sm = new Allship_UPS_Settings_Manager();
assert($sm->get('dim_divisor', 5000) === 5500);
$sm->set('dim_divisor', 6000);
assert($sm->get('dim_divisor') === 6000);
$sm->delete('dim_divisor');
assert($sm->get('dim_divisor', 5000) === 5000);
```

---

### Step 1.2 — Rate Card Repository

🛠 **Skills**:
- `php-pro` — Repository pattern, $wpdb
- `clean-code` — Method naming, return types
- `backend-security-coder` — SQL prepare

📏 **Rules**:
- `ups-plugin-security.md` — `$wpdb->prepare()` cho mọi query
- `ups-plugin-coding-standards.md` — Error codes

📖 **Docs**:
- `04-database-and-api-spec.md` § 2 — `ups_rate_cards` schema
- `11-backend-tech-spec.md` § 4.1-4.3 — Rate card workflow, repository

**Tasks**:
- [ ] Tạo `includes/class-rate-card-repository.php`.
- [ ] Methods:
  - `create(array $data): int` — tạo rate card với tên tùy ý.
  - `get(int $id): ?object`
  - `get_active(): ?object` — rate card đang active.
  - `get_all(): array` — tất cả rate cards, ordered by imported_at DESC.
  - `activate(int $id): bool` — archive card cũ, activate card mới.
  - `archive(int $id): bool`
  - `delete(int $id): bool` — xóa card + cascade rates/zones.
  - `update_name(int $id, string $name): bool` — đổi tên rate card.
  - `update_directions(int $id, array $directions): bool` — bật/tắt chiều vận chuyển.
  - `update_disabled_groups(int $id, array $groups): bool` — toggle rate groups.
- [ ] Business rule: chỉ 1 card active tại 1 thời điểm.
- [ ] Tạo `includes/config/service-registry.php` — `SERVICE_REGISTRY` constant (6 services × 2 directions = 18 rate groups).
- [ ] Tạo `includes/class-service-availability-manager.php`:
  - `get_services_for_direction(string $direction, ?int $rate_card_id): array`
  - `get_available_directions(?int $rate_card_id): array`
  - `resolve_rate_group(string $direction, string $service_code, ?string $shipment_type): string`

🧪 **Tests Step 1.2**:
```php
$repo = new Allship_UPS_Rate_Card_Repository();
$id1 = $repo->create(['name' => 'Test Card 1', 'market_code' => 'VN']);
$id2 = $repo->create(['name' => 'Test Card 2', 'market_code' => 'VN']);
$repo->activate($id1);
assert($repo->get_active()->id === $id1);
$repo->activate($id2);
assert($repo->get_active()->id === $id2);
assert($repo->get($id1)->status === 'archived');
$repo->update_name($id2, 'Renamed Card');
assert($repo->get($id2)->name === 'Renamed Card');
```

---

### Step 1.3 — Country Repository

🛠 **Skills**:
- `php-pro` — Bulk operations, upsert
- `backend-security-coder` — SQL prepare

📏 **Rules**:
- `ups-plugin-security.md` — Sanitize text fields
- `ups-plugin-data-import.md` — US override, extended area detection

📖 **Docs**:
- `04-database-and-api-spec.md` § 3 — `ups_countries` schema
- `09-iata-data-format-update.md` — IATA data structure

**Tasks**:
- [ ] Tạo `includes/class-country-repository.php`.
- [ ] Methods: `find_by_iata`, `get_all_active`, `search`, `insert`, `update`, `toggle_active`, `bulk_upsert`.
- [ ] Detect `is_us_override` (IATA=US).
- [ ] Detect `has_extended_area_note` (name chứa `*`).

🧪 **Tests Step 1.3**:
```php
$repo = new Allship_UPS_Country_Repository();
$repo->insert(['iata_code' => 'US', 'country_name' => 'United States*']);
$us = $repo->find_by_iata('US');
assert($us->is_us_override === 1);
assert($us->has_extended_area_note === 1);
$results = $repo->search('United');
assert(count($results) >= 1);
```

---

### Step 1.4 — Zone Repository

🛠 **Skills**:
- `php-pro` — Complex queries, joins
- `backend-security-coder` — SQL prepare

📏 **Rules**:
- `ups-plugin-security.md` — Prepared statements
- `ups-plugin-architecture.md` — US5 override rule

📖 **Docs**:
- `04-database-and-api-spec.md` § 4 — `ups_zone_maps` schema

**Tasks**:
- [ ] Tạo `includes/class-zone-repository.php`.
- [ ] Methods: `find_zone`, `get_by_rate_card`, `insert`, `update`, `delete`, `bulk_insert`, `delete_by_rate_card`.
- [ ] Unique constraint: (rate_card_id, country_id, direction, service_code).

🧪 **Tests Step 1.4**:
```php
$repo = new Allship_UPS_Zone_Repository();
$repo->insert([
    'rate_card_id' => 1, 'country_id' => 1,
    'direction' => 'export', 'service_code' => 'WXS', 'zone' => '5'
]);
$zone = $repo->find_zone(1, 1, 'export', 'WXS');
assert($zone === '5');
// Duplicate insert → error hoặc update
```

---

### Step 1.5 — Rate Repository

🛠 **Skills**:
- `php-pro` — Decimal handling, bracket matching
- `backend-security-coder` — SQL prepare

📏 **Rules**:
- `ups-plugin-security.md` — Prepared statements
- `ups-plugin-data-import.md` — Billing unit types (flat, per_kg, minimum)

📖 **Docs**:
- `04-database-and-api-spec.md` § 5 — `ups_rates` schema
- `01-business-requirements.md` § 8 — Rate lookup rules

**Tasks**:
- [ ] Tạo `includes/class-rate-repository.php`.
- [ ] Methods: `find_price`, `get_by_rate_card`, `insert`, `update`, `delete`, `bulk_insert`, `delete_by_rate_card`.
- [ ] Lookup logic: flat (exact/ceil), per_kg (bracket match), minimum.

🧪 **Tests Step 1.5**:
```php
$repo = new Allship_UPS_Rate_Repository();
// Insert test rates then verify lookup
$repo->insert([
    'rate_card_id' => 1, 'rate_group' => 'saver_nondocument',
    'zone' => '5', 'weight_label' => '6', 'weight_from' => 6, 'weight_to' => 6,
    'billing_unit' => 'flat', 'price_vnd' => 500000
]);
$result = $repo->find_price(1, 'saver_nondocument', '5', 6.0, false);
assert($result->price_vnd === 500000);
```

---

### Step 1.6 — Quote Log Repository

🛠 **Skills**:
- `php-pro` — Streaming output, CSV generation
- `backend-security-coder` — Data export security

📏 **Rules**:
- `ups-plugin-security.md` — Capability check for export
- `ups-plugin-coding-standards.md` — Error codes

📖 **Docs**:
- `04-database-and-api-spec.md` § 7 — `ups_quote_logs` schema
- `11-backend-tech-spec.md` § 7 — CSV export logic

**Tasks**:
- [ ] Tạo `includes/class-quote-log-repository.php`.
- [ ] Methods: `insert`, `get_filtered`, `count_filtered`, `export_csv`, `export_excel`.

🧪 **Tests Step 1.6**:
```php
$repo = new Allship_UPS_Quote_Log_Repository();
$id = $repo->insert([...test data...]);
assert($id > 0);
$logs = $repo->get_filtered([], 1, 10);
assert(count($logs) >= 1);
$count = $repo->count_filtered([]);
assert($count >= 1);
```

---

### Step 1.7 — Phase 1 Verification

✅ **Gate — PHẢI pass ALL trước khi sang Phase 2**:

| # | Check | Status |
|---|-------|--------|
| 1 | Settings CRUD hoạt động (get/set/delete) | ☐ |
| 2 | Rate Card: create, activate, archive, delete, rename | ☐ |
| 3 | Rate Card: chỉ 1 active tại 1 thời điểm | ☐ |
| 4 | Country: CRUD + search + bulk_upsert | ☐ |
| 5 | Zone: CRUD + find_zone + bulk_insert | ☐ |
| 6 | Rate: CRUD + find_price (flat, per_kg, minimum) | ☐ |
| 7 | Quote Log: insert + filter + count | ☐ |
| 8 | `$wpdb->prepare()` cho MỌI query có user input | ☐ |

---

## Phase 2: Importers (CSV + XLSX)

> **Mục tiêu**: Import được bảng giá và zone từ CSV template + XLSX gốc UPS.

### Step 2.1 — CSV Parser

🛠 **Skills**:
- `php-pro` — fgetcsv, SplFileObject, BOM handling
- `clean-code` — Single Responsibility

📏 **Rules**:
- `ups-plugin-data-import.md` — UTF-8 BOM handling, header validation
- `ups-plugin-security.md` — File input validation

📖 **Docs**:
- `11-backend-tech-spec.md` § 2.2 — CSV Parser spec

**Tasks**:
- [ ] Tạo `includes/importers/class-csv-parser.php`.
- [ ] Native `fgetcsv()` / `SplFileObject`.
- [ ] UTF-8 BOM handling.
- [ ] Header validation: so sánh headers với expected list.
- [ ] Return structured array.

🧪 **Tests Step 2.1**:
- [ ] Parse CSV test file → đúng số rows.
- [ ] CSV with BOM → parse OK.
- [ ] CSV missing headers → throw error.

---

### Step 2.2 — XLSX Reader

🛠 **Skills**:
- `php-pro` — External lib integration, lazy loading

📏 **Rules**:
- `ups-plugin-data-import.md` — SimpleXLSX only, lazy load
- `ups-plugin-coding-standards.md` — libs/ directory

📖 **Docs**:
- `11-backend-tech-spec.md` § 2.3 — XLSX Reader spec

**Tasks**:
- [ ] Download `SimpleXLSX.php` (~100KB) vào `libs/`.
- [ ] Tạo `includes/importers/class-xlsx-reader.php` wrapper.
- [ ] Methods: `parse(string $file, ?string $sheet): array`.
- [ ] Error handling: file corrupt, sheet không tồn tại.
- [ ] Lazy load: chỉ require khi cần.

🧪 **Tests Step 2.2**:
- [ ] Parse `VN from 20.Aug.2026.xlsx` → data array.
- [ ] Parse `IATA.xlsx` → 2838+ rows.
- [ ] Corrupt file → clear error message.

---

### Step 2.3 — Rate Importer

🛠 **Skills**:
- `php-pro` — String parsing, regex, array manipulation
- `clean-code` — Complex logic decomposition
- `systematic-debugging` — Edge case handling

📏 **Rules**:
- `ups-plugin-data-import.md` — Label detection, weight bracket normalization, 4 rate groups
- `ups-plugin-architecture.md` — Rate group mapping

📖 **Docs**:
- `02-data-mapping-and-import.md` § 2-5 — Rate file structure, weight normalization
- `11-backend-tech-spec.md` § 2.4 — Rate Importer code

**Tasks**:
- [ ] Tạo `includes/importers/class-rate-importer.php`.
- [ ] **Hỗ trợ 2 format input:**
  - **CSV template**: headers = `rate_group, weight_label, weight_from, weight_to, billing_unit, zone_1...zone_10, zone_us5`.
  - **XLSX gốc UPS**: detect bằng label "Express Saver Document Rates:" → parse tự động.
- [ ] Chuẩn hóa weight brackets (UPS Envelope, flat, per_kg, minimum).
- [ ] Validate: 4 rate groups found, zones 1-10 + US5, giá là số dương.
- [ ] Preview mode: return summary trước khi commit DB.

🧪 **Tests Step 2.3**:
- [ ] Import `VN from 20.Aug.2026.xlsx` → 4 rate groups found.
- [ ] Weight "21-44" → `billing_unit=per_kg, weight_from=21, weight_to=44`.
- [ ] Weight "UPS Envelope" → `billing_unit=flat, weight_from=null`.
- [ ] Weight ">1000" → `billing_unit=per_kg, weight_from=1000.0001`.
- [ ] Weight "Minimum" → `billing_unit=minimum`.
- [ ] Preview mode → summary without DB commit.
- [ ] Missing rate group → clear error.

---

### Step 2.4 — Zone Importer

🛠 **Skills**:
- `php-pro` — Data transformation, pivot logic
- `clean-code` — Complex algorithm clarity

📏 **Rules**:
- `ups-plugin-data-import.md` — Flat format pivot, ZZ skip, US override, extended area
- `ups-plugin-architecture.md` — Zone import rules

📖 **Docs**:
- `09-iata-data-format-update.md` — IATA.xlsx flat format (2838 rows, 12 columns)
- `02-data-mapping-and-import.md` § 6-7 — Zone import rules, validation numbers
- `11-backend-tech-spec.md` § 2.5 — Zone Importer code

**Tasks**:
- [ ] Tạo `includes/importers/class-zone-importer.php`.
- [ ] **Auto-detect 2 format:**
  - **Pivot CSV template**: `iata_code, country_name, direction, wxs, xpd, wfm, exw, xpr, wxp`.
  - **Flat XLSX (IATA.xlsx)**: `SN, Cty, IATA, Country, Svc Type, Mvn, XPR, XPD, WXS, WXP, WFM, EXW` → pivot tự động.
- [ ] Skip IATA = `ZZ`.
- [ ] Detect US → `is_us_override = 1`.
- [ ] Detect `*` → `has_extended_area_note = 1`.
- [ ] Validate: có US, có WXS/XPD/WFM export.

🧪 **Tests Step 2.4**:
- [ ] Import `IATA.xlsx` (flat) → 249 countries (ZZ skipped).
- [ ] US → `is_us_override = 1`, zone 5 cho WXS/XPD/WFM export.
- [ ] `United States*` → `has_extended_area_note = 1`.
- [ ] WFM import → 0 countries available.
- [ ] Import CSV pivot template → same result.

---

### Step 2.5 — Import Orchestrator

🛠 **Skills**:
- `php-pro` — Transaction handling, atomic operations
- `backend-security-coder` — File upload security
- `software-architecture` — Orchestration pattern

📏 **Rules**:
- `ups-plugin-data-import.md` — Flow, MIME check, size limit, SHA256, atomic rollback
- `ups-plugin-security.md` — File upload rules

📖 **Docs**:
- `05-admin-ui-and-public-ui.md` § 2 — Import UI flow (Upload → Preview → Import → Activate)

**Tasks**:
- [ ] Tạo `includes/importers/class-import-orchestrator.php`.
- [ ] Flow: Upload → Validate → Preview → Create rate card (draft) → Import rates + zones → Return summary.
- [ ] File upload handling: MIME check, size limit 10MB, private directory, delete after import.
- [ ] SHA256 hash cho source file.
- [ ] Atomic: nếu import fail → rollback (delete rate card + data).

🧪 **Tests Step 2.5**:
- [ ] Upload + Preview → summary correct, no DB write.
- [ ] Import → rate card created as draft.
- [ ] Simulate failure midway → rollback, no orphan data.
- [ ] File too large → error.
- [ ] Invalid MIME → error.

---

### Step 2.6 — CSV/XLSX Templates

🛠 **Skills**:
- `documentation-templates` — Template creation

📏 **Rules**:
- `ups-plugin-data-import.md` — Expected headers

**Tasks**:
- [ ] Tạo `templates/rate-template.csv` — mẫu bảng giá.
- [ ] Tạo `templates/zone-template.csv` — mẫu zone chart.

🧪 **Tests Step 2.6**:
- [ ] Templates pass header validation.

---

### Step 2.7 — Phase 2 Verification

✅ **Gate — PHẢI pass ALL trước khi sang Phase 3**:

| # | Check | Status |
|---|-------|--------|
| 1 | Import `VN from 20.Aug.2026.xlsx` → 4 rate groups, đúng số dòng | ☐ |
| 2 | Import `IATA.xlsx` (flat) → 249 countries, US `is_us_override` | ☐ |
| 3 | Import CSV template → cùng kết quả | ☐ |
| 4 | Preview mode → summary đúng, không commit DB | ☐ |
| 5 | File sai format → error message rõ ràng | ☐ |
| 6 | ZZ bị skip | ☐ |
| 7 | Atomic rollback khi fail | ☐ |

---

## Phase 3: Calculator Engine (Business Logic)

> **Mục tiêu**: Tính cước chính xác cho mọi case đã chốt.

### Step 3.1 — Weight Calculator

🛠 **Skills**:
- `php-pro` — Math precision, ceil/floor
- `clean-code` — Pure functions, testability
- `uncle-bob-craft` — Unit-testable design

📏 **Rules**:
- `ups-plugin-architecture.md` — Weight calculation formula
- `ups-plugin-testing.md` — Weight calculator test cases

📖 **Docs**:
- `01-business-requirements.md` § 5 — Cân quy đổi, 1 kiện, nhiều kiện
- `06-test-plan-and-acceptance.md` § 2 — Weight calculator test cases

**Tasks**:
- [ ] Tạo `includes/class-weight-calculator.php`.
- [ ] `calculate_dim_weight(L, W, H, dim_divisor): float`
- [ ] `ceil_to_step(weight, step): float`
- [ ] `calculate_piece(actual, L, W, H, dim_divisor, step): PieceResult`
- [ ] `calculate_pieces(array $pieces, dim_divisor, step): array`
- [ ] `total_chargeable(array $piece_results): float`
- [ ] Xử lý `quantity > 1`: tính 1 kiện × quantity.

🧪 **Tests Step 3.1** (BẮT BUỘC tất cả pass):
```php
$wc = new Allship_UPS_Weight_Calculator();

// actual > dim → chargeable 3.5
assert($wc->ceil_to_step(max(3.2, 1.7), 0.5) === 3.5);

// dim > actual → chargeable 1.5
assert($wc->ceil_to_step(max(1.0, 1.3), 0.5) === 1.5);

// đúng mốc
assert($wc->ceil_to_step(2.5, 0.5) === 2.5);

// lẻ nhỏ
assert($wc->ceil_to_step(0.1, 0.5) === 0.5);

// multi-piece: 1.3 + 2.1 → 1.5 + 2.5 = 4.0
$pieces = $wc->calculate_pieces([
    ['actual_weight_kg' => 1.3, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10, 'quantity' => 1],
    ['actual_weight_kg' => 2.1, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10, 'quantity' => 1],
], 5500, 0.5);
assert($wc->total_chargeable($pieces) === 4.0);
```

---

### Step 3.2 — Zone Resolver

🛠 **Skills**:
- `php-pro` — Query optimization, join
- `clean-code` — Error code pattern

📏 **Rules**:
- `ups-plugin-architecture.md` — US5 override rule
- `ups-plugin-testing.md` — Zone resolver test cases
- `ups-plugin-coding-standards.md` — Error codes

📖 **Docs**:
- `01-business-requirements.md` § 6-7 — Zone rules, US5 exception
- `07-adr.md` — ADR-004: US5 rate column override

**Tasks**:
- [ ] Tạo `includes/class-zone-resolver.php`.
- [ ] `resolve(direction, service_code, destination_iata, rate_card_id): ZoneResult`.
- [ ] US5 override: destination = US → `rate_zone = "US5"`.
- [ ] Error codes: `UNKNOWN_DESTINATION`, `LANE_NOT_AVAILABLE`, `SERVICE_NOT_SUPPORTED`.

🧪 **Tests Step 3.2** (BẮT BUỘC tất cả pass):
```php
$zr = new Allship_UPS_Zone_Resolver($zone_repo, $country_repo, $settings);

// US + WXS export → zone 5, rate_zone US5
$r = $zr->resolve('export', 'WXS', 'US', $rc_id);
assert($r->zone === '5' && $r->rate_zone === 'US5');

// CA + WXS export → zone 5, rate_zone 5
$r = $zr->resolve('export', 'WXS', 'CA', $rc_id);
assert($r->zone === '5' && $r->rate_zone === '5');

// AE + WFM → zone 7
$r = $zr->resolve('export', 'WFM', 'AE', $rc_id);
assert($r->zone === '7');

// Country WFM=0 → LANE_NOT_AVAILABLE
// XPR → SERVICE_NOT_SUPPORTED
```

---

### Step 3.3 — Rate Lookup

🛠 **Skills**:
- `php-pro` — Complex conditional logic, bracket matching
- `clean-code` — Guard clauses, early returns

📏 **Rules**:
- `ups-plugin-architecture.md` — Rate group mapping
- `ups-plugin-testing.md` — Rate lookup test cases
- `ups-plugin-data-import.md` — Billing unit types

📖 **Docs**:
- `01-business-requirements.md` § 8 — Rate lookup rules (Document, Non-doc, WFM)
- `07-adr.md` — ADR-007: Heavy bracket per_kg

**Tasks**:
- [ ] Tạo `includes/class-rate-lookup.php`.
- [ ] `find_price(rate_card_id, rate_group, rate_zone, chargeable_weight, is_envelope): RateResult`.
- [ ] Logic: flat, per_kg, minimum.
- [ ] `map_rate_group(service_code, shipment_type): string`.
- [ ] Document > 5kg → error `DOCUMENT_OVER_5KG`.

🧪 **Tests Step 3.3** (BẮT BUỘC tất cả pass):
- [ ] WXS Document 1kg US → cột US5, giá dòng 1kg.
- [ ] WXS Non-doc 6kg Zone 5 → flat giá dòng 6kg.
- [ ] WXS Non-doc 25kg → bracket 21-44, per_kg × 25.
- [ ] WFM 80kg → max(minimum, bracket 71-99 × 80).
- [ ] Document 6kg → error `DOCUMENT_OVER_5KG`.

---

### Step 3.4 — Surcharge Engine

🛠 **Skills**:
- `php-pro` — Extensible architecture, filter pattern
- `software-architecture` — Plugin extension points

📏 **Rules**:
- `ups-plugin-architecture.md` — Phase 1 surcharges OFF
- `ups-plugin-coding-standards.md` — Hook/filter points

📖 **Docs**:
- `01-business-requirements.md` § 9 — Phụ phí và VAT
- `07-adr.md` — ADR-006: Phụ phí phase 1 default off

**Tasks**:
- [ ] Tạo `includes/class-surcharge-engine.php`.
- [ ] `calculate(base_price, input, pieces): array<Fee>`.
- [ ] Đọc settings: VAT, FSC, Surge, customs_fee. Mặc định tất cả off.
- [ ] Filter hook: `allship_ups_surcharges`.

🧪 **Tests Step 3.4**:
- [ ] Default OFF → `$fees = []`.
- [ ] VAT ON 10% → fee added correctly.
- [ ] Filter hook callable.

---

### Step 3.5 — Quote Calculator (Orchestrator)

🛠 **Skills**:
- `php-pro` — Orchestration, DI
- `software-architecture` — Pipeline pattern
- `clean-code` — Error handling, structured results

📏 **Rules**:
- `ups-plugin-coding-standards.md` — Hooks, error codes
- `ups-plugin-testing.md` — Integration test cases

📖 **Docs**:
- `03-system-architecture.md` § 4-5 — Luồng báo giá, pseudocode

**Tasks**:
- [ ] Tạo `includes/class-quote-calculator.php`.
- [ ] `calculate(array $input): QuoteResult`.
- [ ] Flow: Validate → Rate card → Weight → Zone → Rate → Surcharge → Log → Return.
- [ ] Error handling: catch + wrap thành structured error response.

🧪 **Tests Step 3.5** (Integration — BẮT BUỘC):
```php
$calc = new Allship_UPS_Quote_Calculator(...);

// Case 1: US WXS Non-doc 6kg
$r = $calc->calculate([
    'destination_iata' => 'US', 'service_code' => 'WXS',
    'shipment_type' => 'nondocument',
    'pieces' => [['quantity'=>1, 'actual_weight_kg'=>6, 'length_cm'=>10, 'width_cm'=>10, 'height_cm'=>10]]
]);
assert($r->rate_zone === 'US5');
assert($r->chargeable_weight_kg === 6.0);
assert($r->base_price_vnd > 0);

// Case 2: Multi-piece 1.3 + 2.1 → 4.0
// Case 3: Document 6kg → DOCUMENT_OVER_5KG
// Case 4: WFM unavailable → LANE_NOT_AVAILABLE
```

---

### Step 3.6 — Phase 3 Verification

✅ **Gate — PHẢI pass ALL trước khi sang Phase 4**:

| # | Check | Status |
|---|-------|--------|
| 1 | Weight Calculator: 5/5 test cases pass | ☐ |
| 2 | Zone Resolver: 5/5 test cases pass (including US5) | ☐ |
| 3 | Rate Lookup: 5/5 test cases pass | ☐ |
| 4 | Surcharge Engine: default OFF, extensible | ☐ |
| 5 | Quote Calculator: 4/4 integration cases pass | ☐ |
| 6 | Quote log written correctly | ☐ |
| 7 | Multi-piece calculation tính từng kiện riêng | ☐ |
| 8 | WFM minimum logic đúng | ☐ |

---

## Phase 4: REST API, Shortcode & Frontend UI/UX (Theo Mockup V2)

> **Mục tiêu**: Hiện thực hóa trọn vẹn giao diện và trải nghiệm người dùng theo bản thiết kế chuẩn [public-quote-mockup-v2.html](../../mockups/public-quote-mockup-v2.html).

### Step 4.1 — REST Controller

🛠 **Skills**:
- `wp-rest-api` — register_rest_route, args schema, permission_callback
- `backend-security-coder` — Input validation, sanitization
- `php-pro` — JSON response, error handling

📏 **Rules**:
- `ups-plugin-security.md` — REST API security, sanitize callbacks
- `ups-plugin-coding-standards.md` — REST namespace `ups-quote/v1`, error codes
- `ups-plugin-architecture.md` — API endpoints spec

📖 **Docs**:
- `04-database-and-api-spec.md` § 8-9 — REST API spec, response format
- `10-frontend-tech-spec.md` § 2 — API contract, request/response samples

**Tasks**:
- [ ] Tạo `includes/class-rest-controller.php`.
- [ ] Register routes: `POST /calculate`, `POST /lead`, `GET /countries`, `GET /services`, `GET /directions`.
- [ ] `/services` nhận query param `direction` (default `export`), trả về services từ `Service_Availability_Manager`.
- [ ] `/directions` trả về chiều vận chuyển khả dụng từ `Service_Availability_Manager`.
- [ ] `/calculate` nhận field `direction` (default `export`), dùng `resolve_rate_group()` để xác định rate_group.
- [ ] Input validation với WP REST `args` schema.
- [ ] `service_code` enum mở rộng: `['EXW', 'XPR', 'WXS', 'XPD', 'WXP', 'WFM']`.
- [ ] Sanitize: `sanitize_text_field()`, `absint()`, `floatval()`.
- [ ] Ghi log vào `ups_quote_logs` kèm đầy đủ address fields.

🧪 **Tests Step 4.1**:
```bash
# Test calculate endpoint
curl -X POST http://logistic.local/wp-json/ups-quote/v1/calculate \
  -H "Content-Type: application/json" \
  -d '{"destination_iata":"US","service_code":"WXS","shipment_type":"nondocument","pieces":[{"quantity":1,"actual_weight_kg":6,"length_cm":30,"width_cm":20,"height_cm":15}]}'
# Expected: {"success":true,"data":{"rate_zone":"US5",...}}

# Test countries endpoint
curl http://logistic.local/wp-json/ups-quote/v1/countries?direction=export&service_code=WXS
# Expected: {"success":true,"data":[...249 countries...]}

# Test services endpoint
curl http://logistic.local/wp-json/ups-quote/v1/services
# Expected: {"success":true,"data":[{"code":"WXS","enabled":true},{"code":"XPD","enabled":true},{"code":"WFM","enabled":true},...]}

# Test invalid input
curl -X POST http://logistic.local/wp-json/ups-quote/v1/calculate \
  -d '{"destination_iata":"INVALID"}'
# Expected: validation error
```

---

### Step 4.2 — Shortcode & Data Localize

🛠 **Skills**:
- `wp-plugin-development` — Shortcodes, wp_localize_script
- `wordpress` — Conditional enqueue

📏 **Rules**:
- `ups-plugin-frontend.md` — Enqueue strategy, nur trên page có shortcode
- `ups-plugin-coding-standards.md` — Script handle naming

📖 **Docs**:
- `10-frontend-tech-spec.md` § 4 — Enqueue strategy
- `12-theme-integration.md` § 5 — Plugin enqueue, dependency

**Tasks**:
- [ ] Tạo `includes/class-shortcode.php`.
- [ ] Register `[ups_quote_form]`.
- [ ] Chỉ enqueue assets khi shortcode xuất hiện.
- [ ] `wp_localize_script()`: apiBase, nonce, currency, COUNTRIES, VN_PROVINCES, dim_divisor, rounding_step.

🧪 **Tests Step 4.2**:
- [ ] Visit page "Báo giá UPS" → CSS/JS loaded.
- [ ] Visit homepage → CSS/JS NOT loaded.
- [ ] `upsQuoteConfig` JS object available in browser console.

---

### Step 4.3 — Quote Form Template (PHP / HTML theo Mockup V2)

🛠 **Skills**:
- `frontend-design` — Production-grade UI, visual excellence
- `ui-ux-designer` — Form UX, interaction patterns
- `fixing-accessibility` — ARIA labels, keyboard navigation
- `form-cro` — Form optimization for conversion

📏 **Rules**:
- `ups-plugin-frontend.md` — Design system, colors, typography, shapes
- `ups-plugin-security.md` — Output escaping in templates

📖 **Docs**:
- `10-frontend-tech-spec.md` § 3 — UI components spec
- `05-admin-ui-and-public-ui.md` § 4-7 — Public UI layout, result table, UX edge cases
- `12-theme-integration.md` § 2-4 — Design tokens, typography, shapes
- `13-destination-address-dropdown-research.md` — Address fields design

**Tasks**:
- [ ] Tạo `public/views/quote-form.php` kế thừa 100% từ mockup V2.
- [ ] 7 sections: Header, Direction selector, Service tabs (dynamic), Route/Address, Pieces table, Results/Compare, Booking modal.
- [ ] Direction selector: ẩn nếu chỉ 1 chiều bật, hiện 2 tabs nếu 2 chiều.
- [ ] Service tabs: render dynamic từ `/services?direction=...` API.

🧪 **Tests Step 4.3**:
- [ ] HTML validates (no broken tags).
- [ ] All ARIA attributes present.
- [ ] All form labels linked to inputs.

---

### Step 4.4 — Frontend JavaScript Architecture

🛠 **Skills**:
- `javascript-pro` — ES6+ modules, Fetch API, async/await
- `modern-javascript-patterns` — Destructuring, arrow functions
- `frontend-developer` — Component architecture, state management
- `clean-code` — Module separation

📏 **Rules**:
- `ups-plugin-frontend.md` — Vanilla JS only, CSS namespace, API calls
- `ups-plugin-coding-standards.md` — JS namespace `window.UPSQuote`

📖 **Docs**:
- `10-frontend-tech-spec.md` § 2-7 — API contract, components, animations, error handling
- `13-destination-address-dropdown-research.md` § 5 — Implementation plan for address fields

**Tasks**:
- [ ] Tạo `public/assets/js/quote-form.js` với modules:
  - DirectionSelector
  - ServiceTabs (dynamic)
  - CountryCombobox
  - DestinationAddress (Giải pháp A - static JSON)
  - PieceManager
  - LiveMetricsEngine
  - QuoteComparisonEngine
  - BookingModalManager
  - MobileStickyCTA

🧪 **Tests Step 4.4**:
- [ ] No JS errors in console.
- [ ] Country search: tiếng Việt + IATA + English.
- [ ] Piece add/remove: metrics recalculate real-time.
- [ ] API call: spinner → result → comparison cards.
- [ ] Booking modal: open → fill → submit → inline success.

---

### Step 4.5 — Frontend CSS & Design System

🛠 **Skills**:
- `frontend-design` — Color tokens, responsive layouts, print styles
- `web-design-guidelines` — Design system compliance
- `fixing-motion-performance` — Animation performance

📏 **Rules**:
- `ups-plugin-frontend.md` — All CSS tokens, responsive breakpoints, accessibility
- `ups-plugin-architecture.md` — Brand colors, typography

📖 **Docs**:
- `12-theme-integration.md` § 2-4 — Design tokens, shapes, JS libraries

**Tasks**:
- [ ] Tạo `public/assets/css/quote-form.css`.
- [ ] Color tokens, typography, shadows, responsive layouts, print styles.

🧪 **Tests Step 4.5**:
- [ ] Brand colors match theme (#CE2027).
- [ ] Mobile 375px → no overflow.
- [ ] Print → clean layout, no buttons/forms.
- [ ] Color contrast ≥ 4.5:1.

---

### Step 4.6 — Phase 4 Verification

✅ **Gate — PHẢI pass ALL trước khi sang Phase 5**:

| # | Check | Status |
|---|-------|--------|
| 1 | REST API `/calculate` với `direction` param returns correct JSON | ☐ |
| 2 | REST API `/countries` returns 249 countries | ☐ |
| 3 | REST API `/services?direction=export` returns dynamic services | ☐ |
| 4 | REST API `/directions` returns available directions | ☐ |
| 5 | Form renders correctly on "Báo giá UPS" page | ☐ |
| 6 | Direction selector: ẩn khi chỉ 1 chiều, dynamic switch | ☐ |
| 7 | Service tabs dynamic từ API, có enabled/disabled states | ☐ |
| 8 | has_document_split: Document/Non-doc toggle cho WXS/EXW/XPR | ☐ |
| 9 | Country combobox: search tiếng Việt + IATA | ☐ |
| 10 | Address fields: State dropdown → City dropdown adaptive | ☐ |
| 11 | Piece table: add/remove + live metrics | ☐ |
| 12 | Calculate: comparison cards display | ☐ |
| 13 | Booking modal: submit + inline success | ☐ |
| 14 | Mobile 375px responsive: no overflow | ☐ |
| 15 | No JS console errors | ☐ |

---

## Phase 5: Admin UI

> **Mục tiêu**: Admin quản lý import, rates, zones, settings, logs.

### Step 5.1 — Admin Menu

🛠 **Skills**:
- `wp-plugin-development` — Admin menus, submenus, capability
- `wordpress` — Admin page registration

📏 **Rules**:
- `ups-plugin-security.md` — Capability `manage_options`
- `ups-plugin-coding-standards.md` — Admin class naming

📖 **Docs**:
- `05-admin-ui-and-public-ui.md` § 1 — Admin menu structure

**Tasks**:
- [ ] Tạo `admin/class-admin-menu.php`.
- [ ] Top-level menu: "UPS Quote", icon `dashicons-calculator`.
- [ ] 7 submenus: Dashboard, Import, Rates, Zones, Countries, Settings, Logs.

🧪 **Tests Step 5.1**:
- [ ] Menu visible in WP Admin sidebar.
- [ ] All submenus accessible.
- [ ] Non-admin user → menu not visible.

---

### Step 5.2 — Rate Cards Page

🛠 **Skills**:
- `wp-plugin-development` — WP_List_Table
- `php-pro` — AJAX handlers

📏 **Rules**:
- `ups-plugin-security.md` — Nonce + capability check
- `ups-plugin-coding-standards.md` — Status badges

📖 **Docs**:
- `05-admin-ui-and-public-ui.md` § 1 — Rate Cards management

**Tasks**:
- [ ] Tạo `admin/views/rate-cards.php` — WP_List_Table.
- [ ] Cột: ID, Tên, Chiều vận chuyển, Dịch vụ (icons), Status, Actions.
- [ ] Actions: Đổi tên, Activate, Archive, Delete.
- [ ] Button "Tạo mới": modal → tạo draft rate card.
- [ ] Rate Card detail page / modal:
  - [ ] Direction toggles (Export/Import checkboxes).
  - [ ] Rate group grid per direction: 6 services × rate groups với toggle ON/OFF.
  - [ ] Toggle disabled khi rate group có 0 rows.
  - [ ] AJAX save: `ups_update_rate_card_toggles`.
  - [ ] Cache invalidation sau save.

🧪 **Tests Step 5.2**:
- [ ] List all rate cards with correct status.
- [ ] Create → rename → activate → archive → delete cycle.

---

### Step 5.3 — Import Page

🛠 **Skills**:
- `wp-plugin-development` — AJAX, file upload
- `frontend-developer` — Step wizard UI
- `backend-security-coder` — File upload security

📏 **Rules**:
- `ups-plugin-security.md` — Nonce, capability, MIME check
- `ups-plugin-data-import.md` — Import flow

📖 **Docs**:
- `05-admin-ui-and-public-ui.md` § 2 — Import wizard steps

**Tasks**:
- [ ] Tạo `admin/views/import.php` — Step wizard.
- [ ] AJAX: `ups_import_preview`, `ups_import_execute`.
- [ ] Preview summary: rate groups, countries, zones, warnings.

🧪 **Tests Step 5.3**:
- [ ] Upload XLSX → preview summary correct.
- [ ] Confirm → data imported.
- [ ] Cancel → no data written.

---

### Step 5.4 — Rates Edit Page

🛠 **Skills**:
- `wp-plugin-development` — WP_List_Table, AJAX inline edit
- `javascript-pro` — AJAX handlers

📖 **Docs**:
- `05-admin-ui-and-public-ui.md` § 1 — Zone Maps, Rates edit

**Tasks**:
- [ ] Tạo `admin/views/rates-edit.php` — WP_List_Table + inline edit.
- [ ] Filter: rate_group, zone. Pagination.
- [ ] Inline edit: click cell → input → save AJAX.

🧪 **Tests Step 5.4**:
- [ ] Filter by rate_group → correct rows.
- [ ] Edit price → save → reload → value persisted.
- [ ] Add row → visible in list.
- [ ] Delete rows → removed.

---

### Step 5.5 — Zones Edit Page

🛠 **Skills**: Same as Step 5.4

**Tasks**:
- [ ] Tạo `admin/views/zones-edit.php` — WP_List_Table + inline edit.
- [ ] Filter: direction, service_code, search country.

🧪 **Tests Step 5.5**:
- [ ] Filter by service → correct rows.
- [ ] Edit zone → save → persisted.

---

### Step 5.6 — Countries Edit Page

🛠 **Skills**: Same as Step 5.4

**Tasks**:
- [ ] Tạo `admin/views/countries-edit.php` — WP_List_Table.
- [ ] Toggle active switch. Search by name/IATA.

🧪 **Tests Step 5.6**:
- [ ] Toggle active → status changed.
- [ ] Search "United" → US result.

---

### Step 5.7 — Settings Page

🛠 **Skills**:
- `wp-plugin-development` — Settings API
- `backend-security-coder` — Sanitize callbacks

📏 **Rules**:
- `ups-plugin-security.md` — Nonce validation, sanitize callbacks

📖 **Docs**:
- `05-admin-ui-and-public-ui.md` § 3 — Settings sections

**Tasks**:
- [ ] Tạo `admin/views/settings.php` — WP Settings API.
- [ ] Sections: Weight, Fees, Advanced.
- [ ] Sanitize callbacks. Nonce validation.

> **Note**: Nhóm `Services` và `Phase` đã chuyển sang Rate Card detail page.

🧪 **Tests Step 5.7**:
- [ ] Change dim_divisor → save → reload → value persisted.
- [ ] Toggle VAT ON → save → value = true.
- [ ] Invalid value → sanitized.

---

### Step 5.8 — Quote Logs Page

🛠 **Skills**:
- `wp-plugin-development` — WP_List_Table, CSV streaming
- `php-pro` — File streaming, UTF-8 BOM

📖 **Docs**:
- `11-backend-tech-spec.md` § 7 — CSV export

**Tasks**:
- [ ] Tạo `admin/views/quote-logs.php` — WP_List_Table.
- [ ] Filters: date range, service, destination.
- [ ] Export CSV + Excel (UTF-8 BOM).

🧪 **Tests Step 5.8**:
- [ ] Logs appear after calculate.
- [ ] Filter by service → correct rows.
- [ ] Export CSV → file downloads, opens in Excel correctly (Vietnamese text OK).

---

### Step 5.9 — Admin Assets

🛠 **Skills**:
- `frontend-design` — Admin styles
- `javascript-pro` — AJAX handlers

📏 **Rules**:
- `ups-plugin-frontend.md` — Admin assets chỉ load trên plugin pages

**Tasks**:
- [ ] Tạo `admin/assets/css/admin.css`, `admin/assets/js/admin.js`.
- [ ] Enqueue chỉ trên plugin admin pages.

🧪 **Tests Step 5.9**:
- [ ] Admin CSS/JS loaded on plugin pages.
- [ ] Admin CSS/JS NOT loaded on other admin pages.

---

### Step 5.10 — Phase 5 Verification

✅ **Gate — PHẢI pass ALL trước khi sang Phase 6**:

| # | Check | Status |
|---|-------|--------|
| 1 | Admin import CSV/XLSX với preview | ☐ |
| 2 | Admin CRUD rates, zones, countries | ☐ |
| 3 | Admin tạo/đặt tên/activate/archive rate cards | ☐ |
| 4 | Settings save/load đúng | ☐ |
| 5 | Quote logs filter + export CSV | ☐ |
| 6 | Admin assets chỉ load trên plugin pages | ☐ |
| 7 | Nonce + capability check trên mọi AJAX | ☐ |

---

## Phase 6: QA, Security, Hardening

> **Mục tiêu**: Plugin production-ready, secure, performant.

### Step 6.1 — Security Audit

🛠 **Skills**:
- `007` — Security audit, OWASP checks, code review
- `backend-security-coder` — SQL injection, XSS, CSRF
- `frontend-security-coder` — XSS prevention, output sanitization
- `wordpress-penetration-testing` — WP-specific vulnerabilities

📏 **Rules**:
- `ups-plugin-security.md` — TOÀN BỘ rules
- `ups-plugin-coding-standards.md` — Escaping patterns

📖 **Docs**:
- `03-system-architecture.md` § 7 — Security requirements

**Tasks**:
- [ ] Audit nonce mọi admin AJAX/form.
- [ ] Audit capability check mọi admin action.
- [ ] Audit `$wpdb->prepare()` mọi query.
- [ ] Audit sanitize input, escape output.
- [ ] Audit file upload: MIME, size, private dir.
- [ ] Audit REST API args validation.
- [ ] Audit `ABSPATH` check mọi file.

🧪 **Tests Step 6.1**:
- [ ] grep codebase: NO direct SQL interpolation.
- [ ] grep codebase: ALL admin actions have nonce check.
- [ ] grep codebase: ALL PHP files have ABSPATH guard.

---

### Step 6.2 — Performance

🛠 **Skills**:
- `web-performance-optimization` — Caching, lazy loading
- `wp-performance` — Transients, query optimization

📏 **Rules**:
- `ups-plugin-frontend.md` — Conditional asset loading

📖 **Docs**:
- `03-system-architecture.md` § 8 — Caching strategy

**Tasks**:
- [ ] Country list cached (transient, 1 hour).
- [ ] Active rate card ID cached.
- [ ] Invalidate transients khi activate/import.
- [ ] DB queries có index.
- [ ] Assets chỉ load khi cần.

🧪 **Tests Step 6.2**:
- [ ] Second API call faster (cache hit).
- [ ] Import new data → transients cleared.
- [ ] Page without shortcode → no plugin assets loaded.

---

### Step 6.3 — Edge Cases

🛠 **Skills**:
- `systematic-debugging` — Edge case discovery
- `bug-hunter` — Root cause analysis

📏 **Rules**:
- `ups-plugin-testing.md` — Full test matrix

**Tasks**:
- [ ] No active rate card → user-friendly error.
- [ ] Empty rate card → error on calculate.
- [ ] Weight = 0 → error.
- [ ] Dimensions = 0 → dim weight = 0, use actual.
- [ ] Quantity = 0 → skip.
- [ ] 20 pieces → handles correctly.
- [ ] Large file import → PHP memory OK.

🧪 **Tests Step 6.3**:
- [ ] Each edge case tested and handled gracefully.

---

### Step 6.4 — Accessibility Audit

🛠 **Skills**:
- `fixing-accessibility` — ARIA, keyboard navigation
- `wcag-audit-patterns` — WCAG 2.2 compliance

📏 **Rules**:
- `ups-plugin-frontend.md` — Accessibility requirements

**Tasks**:
- [ ] Form labels, `aria-required`, `aria-invalid`.
- [ ] Keyboard navigation (Tab, Enter, Escape).
- [ ] `role="alert"` for errors.
- [ ] `aria-live="polite"` for results.
- [ ] Color contrast ≥ 4.5:1.

🧪 **Tests Step 6.4**:
- [ ] Tab through entire form → logical order.
- [ ] Screen reader: results announced.
- [ ] Contrast checker: all text passes.

---

### Step 6.5 — Cross-browser & Responsive

🛠 **Skills**:
- `frontend-design` — Responsive testing
- `web-design-guidelines` — Browser compatibility

**Tasks**:
- [ ] Chrome, Firefox, Safari, Edge.
- [ ] Mobile 320px-768px, Tablet 768px-1024px, Desktop 1024px+.

🧪 **Tests Step 6.5**:
- [ ] No layout breaks on any target device/browser.
- [ ] Touch targets ≥ 44px on mobile.

---

### Step 6.6 — Integration Test (Full Flow)

🛠 **Skills**:
- `systematic-debugging` — End-to-end testing
- `code-review-checklist` — Final review

📏 **Rules**:
- `ups-plugin-testing.md` — All integration test cases

**Tasks & Tests**:
- [ ] **Full flow**: Import → Calculate → Log → Export.
- [ ] **Case US + WXS Non-doc 6kg** → US5, correct price.
- [ ] **Case CA + WXS Non-doc 6kg** → Zone 5, different price.
- [ ] **Case AE + WFM 80kg** → max(minimum, bracket × 80).
- [ ] **Case Document 6kg** → DOCUMENT_OVER_5KG error.
- [ ] **Case multi-piece 1.3+2.1** → chargeable 4.0kg.
- [ ] **Case WFM unavailable destination** → LANE_NOT_AVAILABLE.
- [ ] **Case import new rate card** → old archived, new active.

---

### Step 6.7 — Phase 6 Verification

✅ **Gate — PHẢI pass ALL trước khi sang Phase 7**:

| # | Check | Status |
|---|-------|--------|
| 1 | Không PHP warning/error trong debug log | ☐ |
| 2 | Mọi input sanitized | ☐ |
| 3 | Mọi output escaped | ☐ |
| 4 | Mọi SQL prepared | ☐ |
| 5 | Performance acceptable (cache working) | ☐ |
| 6 | Mobile/Tablet/Desktop responsive OK | ☐ |
| 7 | Chrome/Firefox/Safari/Edge OK | ☐ |
| 8 | WCAG accessibility pass | ☐ |
| 9 | 7/7 integration test cases pass | ☐ |

---

## Phase 7: Documentation & Handover

> **Mục tiêu**: Tài liệu vận hành cho admin, README cho developer.

### Step 7.1 — Admin Guide

🛠 **Skills**:
- `documentation-templates` — Documentation structure
- `readme` — User documentation

📖 **Docs**: Reference all design docs for accuracy.

**Tasks**:
- [ ] Hướng dẫn import bảng giá mới (CSV/XLSX).
- [ ] Hướng dẫn quản lý rate cards.
- [ ] Hướng dẫn chỉnh sửa rate/zone thủ công.
- [ ] Hướng dẫn cấu hình settings.
- [ ] Hướng dẫn export quote logs.

---

### Step 7.2 — Developer README

🛠 **Skills**:
- `readme` — README.md best practices
- `documentation-templates` — API docs

**Tasks**:
- [ ] Cài đặt plugin.
- [ ] Cấu trúc file.
- [ ] Hook/filter reference.
- [ ] REST API reference.
- [ ] Shortcode reference.

---

### Step 7.3 — Sample Data

**Tasks**:
- [ ] Sample rate CSV.
- [ ] Sample zone CSV.
- [ ] IATA.xlsx và VN rate file cho dev environment.

---

### Step 7.4 — Phase 7 Verification

✅ **Gate — PHẢI pass ALL trước khi bàn giao**:

| # | Check | Status |
|---|-------|--------|
| 1 | Admin guide: admin tự vận hành import/quản lý | ☐ |
| 2 | Developer README: dev tiếp tục phát triển được | ☐ |
| 3 | Sample data: test lại toàn bộ flow | ☐ |

---

## Backlog: Phase 2 (Tương lai)

- [ ] Import bảng giá chiều Import (data, không cần sửa code — bật direction trong Rate Card detail).
- [ ] Import bảng giá cho `EXW`, `XPR`, `WXP` (template rate group đã sẵn — chỉ cần import data + bật toggle).
- [ ] Tích hợp nguồn FSC/Surge/VAT tự động.
- [ ] Remote/Extended area detection.
- [ ] Tích hợp tạo shipment UPS API.
- [ ] WooCommerce shipping method.
- [ ] Multi-origin (không chỉ VN).

---

## Checklist trước khi bàn giao

- [ ] Plugin version trong header.
- [ ] Migration idempotent.
- [ ] Uninstall policy: setting cho phép giữ/xóa data.
- [ ] Sample import files trong dev.
- [ ] Test cases US5 pass.
- [ ] Test cases multi-piece pass.
- [ ] Test cases service unavailable pass.
- [ ] Quote log đủ data đối soát.
- [ ] Note giá chưa gồm phụ phí hiển thị.
- [ ] Admin guide viết xong.
- [ ] Page "Báo giá UPS" tồn tại sau activate.
- [ ] Multiple rate cards hoạt động.
- [ ] Export CSV/Excel hoạt động.

---

## Tổng hợp Skills theo Phase

| Phase | Skills chính |
|-------|-------------|
| **0: Skeleton** | `wp-plugin-development`, `wordpress`, `software-architecture`, `clean-code`, `php-pro`, `backend-security-coder` |
| **1: Data Layer** | `php-pro`, `clean-code`, `backend-security-coder`, `uncle-bob-craft` |
| **2: Importers** | `php-pro`, `clean-code`, `systematic-debugging`, `backend-security-coder`, `software-architecture` |
| **3: Calculator** | `php-pro`, `clean-code`, `uncle-bob-craft`, `software-architecture` |
| **4: API + Frontend** | `wp-rest-api`, `javascript-pro`, `modern-javascript-patterns`, `frontend-design`, `frontend-developer`, `ui-ux-designer`, `fixing-accessibility`, `form-cro`, `web-design-guidelines` |
| **5: Admin UI** | `wp-plugin-development`, `frontend-developer`, `javascript-pro`, `backend-security-coder` |
| **6: QA + Security** | `007`, `backend-security-coder`, `frontend-security-coder`, `wordpress-penetration-testing`, `web-performance-optimization`, `wp-performance`, `systematic-debugging`, `bug-hunter`, `fixing-accessibility`, `wcag-audit-patterns`, `code-review-checklist` |
| **7: Docs** | `documentation-templates`, `readme` |

## Tổng hợp Rules áp dụng

| Rule file | Áp dụng cho |
|-----------|-------------|
| `ups-plugin-architecture.md` | Phase 0-7 (MỌI phase) |
| `ups-plugin-coding-standards.md` | Phase 0-7 (MỌI phase) |
| `ups-plugin-security.md` | Phase 0-6 (đặc biệt Phase 6) |
| `ups-plugin-testing.md` | Phase 0-6 (verification gates) |
| `ups-plugin-frontend.md` | Phase 4 (chủ yếu), Phase 5-6 |
| `ups-plugin-data-import.md` | Phase 2 (chủ yếu), Phase 1 |
