# UPS Plugin Architecture Rules

> Áp dụng cho toàn bộ codebase `allship-ups-quote` plugin.

## Plugin Identity

- **Plugin slug**: `allship-ups-quote`
- **Plugin Name**: `Allship UPS Quote`
- **Text Domain**: `allship-ups-quote`
- **PHP**: 7.2+ compatible
- **WordPress Coding Standards**: BẮT BUỘC

## Architecture Principles

- Plugin WordPress **monolith**, chia module rõ ràng theo nghiệp vụ.
- **Custom database tables** (KHÔNG dùng post/meta cho bảng giá/zone).
- **No side-effects at file load time** — hook everything.
- **Dependency injection** qua constructor.
- **Prefix** tất cả global: `allship_ups_`.
- **Manual autoload** (require, KHÔNG dùng Composer autoload).
- **No jQuery** — Vanilla JS ES6+ only.

## Phase 1 Scope (NGHIÊM CẤM vượt scope)

- Chiều vận chuyển: **Export only** (direction = `export` cố định).
- Dịch vụ bật: `WXS`, `XPD`, `WFM`.
- Dịch vụ tắt: `EXW`, `XPR`, `WXP` (chưa có bảng giá).
- Origin mặc định: `VN`.
- Phụ phí: default OFF (VAT, FSC, Surge, Customs).

## 6 Custom Tables

| Table | Mục đích |
|-------|----------|
| `{prefix}ups_rate_cards` | Versioning bảng giá |
| `{prefix}ups_countries` | Danh sách quốc gia/IATA |
| `{prefix}ups_zone_maps` | Zone mapping |
| `{prefix}ups_rates` | Bảng giá chi tiết |
| `{prefix}ups_settings` | Cấu hình plugin |
| `{prefix}ups_quote_logs` | Log báo giá |

## Constants (bắt buộc define)

```php
ALLSHIP_UPS_QUOTE_VERSION
ALLSHIP_UPS_QUOTE_PATH
ALLSHIP_UPS_QUOTE_URL
```

## US5 Override Rule

- US thuộc Zone 5 nhưng dùng cột giá riêng `US5`.
- Zone lưu trong DB vẫn là `5`.
- `Zone_Resolver` trả `rate_zone = "US5"` khi destination = US.

## Weight Calculation

- Dim divisor: setting, mặc định `5500`.
- Rounding step: `0.5kg` (ceiling).
- Multi-piece: tính từng kiện riêng rồi SUM (KHÔNG cộng tổng rồi max).

## Documentation Reference

LUÔN tham khảo trước khi code:

| Docs | File |
|------|------|
| Business logic | `docs_plugin_quote/docs/ups-wp-plugin-design/01-business-requirements.md` |
| Data mapping | `docs_plugin_quote/docs/ups-wp-plugin-design/02-data-mapping-and-import.md` |
| Architecture | `docs_plugin_quote/docs/ups-wp-plugin-design/03-system-architecture.md` |
| DB + API spec | `docs_plugin_quote/docs/ups-wp-plugin-design/04-database-and-api-spec.md` |
| UI spec | `docs_plugin_quote/docs/ups-wp-plugin-design/05-admin-ui-and-public-ui.md` |
| Test plan | `docs_plugin_quote/docs/ups-wp-plugin-design/06-test-plan-and-acceptance.md` |
| ADR | `docs_plugin_quote/docs/ups-wp-plugin-design/07-adr.md` |
| Roadmap | `docs_plugin_quote/docs/ups-wp-plugin-design/08-implementation-roadmap.md` |
| IATA format | `docs_plugin_quote/docs/ups-wp-plugin-design/09-iata-data-format-update.md` |
| Frontend spec | `docs_plugin_quote/docs/ups-wp-plugin-design/10-frontend-tech-spec.md` |
| Backend spec | `docs_plugin_quote/docs/ups-wp-plugin-design/11-backend-tech-spec.md` |
| Theme integration | `docs_plugin_quote/docs/ups-wp-plugin-design/12-theme-integration.md` |
| Address dropdown | `docs_plugin_quote/docs/ups-wp-plugin-design/13-destination-address-dropdown-research.md` |
