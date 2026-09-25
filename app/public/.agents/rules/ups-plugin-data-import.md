# UPS Plugin Data Import Rules

> Áp dụng cho importer modules trong `includes/importers/`.

## Import Strategy

- **CSV-first** (zero dependency) + **SimpleXLSX** (~100KB) cho `.xlsx`.
- KHÔNG dùng PhpSpreadsheet (quá nặng).
- SimpleXLSX chỉ `require_once` khi cần (lazy load).

## Supported Formats

### Rate File

| Format | Source | Detection |
|--------|--------|-----------|
| CSV template | Plugin template | Headers match `EXPECTED_RATE_HEADERS` |
| XLSX gốc UPS | `VN from 20.Aug.2026.xlsx` | Label "Express Saver Document Rates:" |

### Zone File

| Format | Source | Detection |
|--------|--------|-----------|
| CSV pivot | Plugin template | Headers có `iata_code` + `direction` |
| XLSX flat | `IATA.xlsx` (2838 rows) | Headers có `SN` + `Cty` + `Mvn` |

## Rate Import Rules

### Label Detection (KHÔNG hardcode số dòng)

```
Express Saver Document Rates:      → rate_group = saver_document
Express Saver Non-Document Rates:  → rate_group = saver_nondocument
Expedited Rates:                   → rate_group = expedited
Worldwide Express Freight Rates:   → rate_group = freight
```

### Weight Bracket Normalization

| Excel | weight_from | weight_to | billing_unit |
|-------|-------------|-----------|-------------|
| `UPS Envelope` | null | null | `flat` |
| `0.5`, `1`, `1.5` | = value | = value | `flat` |
| `21-44` | 21 | 44 | `per_kg` |
| `>1000` | 1000.0001 | null | `per_kg` |
| `Minimum` | null | null | `minimum` |

### Validation (PHẢI check)

- ✅ 4 rate groups found
- ✅ Zones 1-10 + US5
- ✅ Giá là số dương
- ⚠️ Warning (không chặn): EXW/XPR/WXP chưa có bảng giá

## Zone Import Rules

### Flat Format Pivot Logic

```
FOR mỗi dòng:
  SKIP nếu IATA rỗng hoặc == "ZZ"
  FOR mỗi service IN [XPR, XPD, WXS, WXP, WFM, EXW]:
    IF zone > 0:
      zone_map[IATA][direction][service] = zone
```

### Country Detection

- `IATA == "US"` → `is_us_override = 1`
- `country_name` chứa `*` → `has_extended_area_note = 1`

### Expected Results After Import

- 249 unique countries (ZZ excluded)
- US: zone 5 cho WXS/XPD/WFM export
- WFM import: 0 countries

## Rate Card Management

- **1 rate card active** tại 1 thời điểm.
- Admin tạo tên tùy ý cho rate card.
- Flow: Upload → Validate → Preview → Create Draft → Import → Activate.
- Activate card mới → card cũ tự archived.
- SHA256 hash cho source file.
- Import PHẢI atomic: fail → rollback.

## File Upload

- MIME: `.csv`, `.xlsx` only.
- Size: ≤ 10MB.
- Save to private dir → delete after import.
- UTF-8 BOM handling cho CSV.
