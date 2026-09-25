# UPS Plugin Testing Rules

> Mỗi Phase PHẢI có verification step trước khi chuyển Phase tiếp theo.

## Test Framework

- Unit tests bằng PHP script chạy trực tiếp (hoặc PHPUnit nếu có sẵn).
- Integration tests qua REST API (curl / browser).
- Manual verification checklist cho UI.

## Mandatory Test Cases

### Weight Calculator

| Case | Input | Expected |
|------|-------|----------|
| Actual > dim | actual 3.2, dim 1.7 | chargeable **3.5** |
| Dim > actual | actual 1.0, dim 1.3 | chargeable **1.5** |
| Đúng mốc 0.5 | actual 2.5 | chargeable **2.5** |
| Lẻ nhỏ | actual 0.1 | chargeable **0.5** |
| Multi-piece | 1.3kg + 2.1kg | 1.5 + 2.5 = **4.0** |

### Zone Resolver

| Case | Expected |
|------|----------|
| US + WXS export | zone `5`, rate_zone `US5` |
| CA + WXS export | zone `5`, rate_zone `5` |
| AE + WFM export | zone `7` |
| Country WFM=0 | `LANE_NOT_AVAILABLE` |
| Service XPR | `SERVICE_NOT_SUPPORTED` |

### Rate Lookup

| Case | Expected |
|------|----------|
| WXS Document 1kg US | cột US5, dòng 1kg |
| WXS Non-doc 6kg Zone 5 | dòng 6kg flat |
| WXS Non-doc 25kg | bracket 21-44, per_kg × 25 |
| WFM 80kg | max(minimum, bracket 71-99 × 80) |
| Document 6kg | error `DOCUMENT_OVER_5KG` |

### Import Validation

| Check | Expected |
|-------|----------|
| Import IATA.xlsx flat | 249 countries (ZZ skipped) |
| US import | `is_us_override = 1` |
| Country with `*` | `has_extended_area_note = 1` |
| Find 4 rate groups | ✅ |
| Find zones 1-10 + US5 | ✅ |
| File sai format | Error message rõ ràng |

### Integration Tests (Full Flow)

| # | Case | Validate |
|---|------|----------|
| 1 | US + WXS Non-doc 6kg | rate_zone=US5, price từ cột US5 |
| 2 | CA + WXS Non-doc 6kg | rate_zone=5, price khác US |
| 3 | AE + WFM 80kg | max(minimum, bracket × 80) |
| 4 | WXS Document 6kg | Error `DOCUMENT_OVER_5KG` |
| 5 | Multi-piece 1.3+2.1kg | Total chargeable 4.0kg |
| 6 | WFM unavailable dest | Error `LANE_NOT_AVAILABLE` |
| 7 | Import new rate card | Old card archived, new active |

## Verification per Phase

### Phase 0 — Plugin Skeleton
- [ ] Activate → no PHP error
- [ ] 6 tables exist in DB
- [ ] Page "Báo giá UPS" created
- [ ] Deactivate → activate → no duplicates
- [ ] Default settings readable

### Phase 1 — Data Layer
- [ ] All repositories CRUD work
- [ ] Rate card: only 1 active at a time
- [ ] `$wpdb->prepare()` for all queries

### Phase 2 — Importers
- [ ] Import VN rate XLSX → 4 groups
- [ ] Import IATA.xlsx flat → 249 countries
- [ ] Import CSV template → same result
- [ ] Preview mode → correct summary
- [ ] Bad file → clear error

### Phase 3 — Calculator
- [ ] All weight test cases pass
- [ ] All zone test cases pass
- [ ] All rate lookup test cases pass
- [ ] US5 override works
- [ ] Multi-piece calculation correct

### Phase 4 — REST API + Frontend
- [ ] API endpoint returns correct JSON
- [ ] Form renders correctly
- [ ] 3-service comparison works
- [ ] Booking modal works
- [ ] Mobile responsive OK

### Phase 5 — Admin UI
- [ ] Import wizard works
- [ ] CRUD rates/zones/countries
- [ ] Settings save/load
- [ ] Quote logs filter + export

### Phase 6 — QA & Security
- [ ] No PHP warnings in debug log
- [ ] All input sanitized
- [ ] All output escaped
- [ ] Cross-browser OK
- [ ] All integration tests pass
