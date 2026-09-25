# 09. Cập nhật cấu trúc dữ liệu IATA.xlsx

## 1. Lý do cập nhật

Tài liệu `02-data-mapping-and-import.md` mô tả `IATA.xlsx` có 255 dòng, 14 cột dạng pivot:

```
A=IATA | B=Country | C-H=Export (EXW,WFM,WXP,WXS,XPD,XPR) | I-N=Import (EXW,WFM,WXP,WXS,XPD,XPR)
```

**Thực tế** file `IATA.xlsx` (sheet `VN`) có cấu trúc **flat/unpivoted**, 2838 dòng.

## 2. Cấu trúc thực tế

### 2.1. Columns

| Cột | Header | Kiểu | Mô tả |
|-----|--------|------|-------|
| A | `SN` | Number | Serial number (unique row ID) |
| B | `Cty` | Text | Market code, luôn = `VN` |
| C | `IATA` | Text | Mã quốc gia/vùng (2 ký tự) |
| D | `Rate Guide Country Name` | Text | Tên quốc gia, có thể kèm `*` |
| E | `Svc Type` | Text | `Package` hoặc `Freight` |
| F | `Mvn` | Text | `Export` hoặc `Import` |
| G | `XPR` | Number | Zone cho Express |
| H | `XPD` | Number | Zone cho Expedited |
| I | `WXS` | Number | Zone cho Worldwide Express Saver |
| J | `WXP` | Number | Zone cho Worldwide Express Plus |
| K | `WFM` | Number | Zone cho Worldwide Express Freight |
| L | `EXW` | Number | Zone cho Express Worldwide |

### 2.2. Đặc điểm dữ liệu

- **2838 dòng** (không tính header).
- Mỗi dòng chứa **đúng 1 service** có zone > 0, các service còn lại = 0.
- Một country có **nhiều dòng** (1 dòng cho mỗi service × direction × svc_type).
- Ví dụ: `US` (United States) có **12 dòng**.

### 2.3. Ví dụ dữ liệu US

```
SN    | Cty | IATA | Country          | Svc Type | Mvn    | XPR | XPD | WXS | WXP | WFM | EXW
22145 | VN  | US   | United States*   | Package  | Export | 5   | 0   | 0   | 0   | 0   | 0
22146 | VN  | US   | United States*   | Package  | Export | 0   | 0   | 5   | 0   | 0   | 0
22147 | VN  | US   | United States*   | Package  | Export | 0   | 5   | 0   | 0   | 0   | 0
22148 | VN  | US   | United States*   | Package  | Import | 5   | 0   | 0   | 0   | 0   | 0
22149 | VN  | US   | United States*   | Package  | Import | 0   | 0   | 5   | 0   | 0   | 0
22150 | VN  | US   | United States*   | Package  | Import | 0   | 5   | 0   | 0   | 0   | 0
26027 | VN  | US   | United States*   | Freight  | Export | 0   | 0   | 0   | 5   | 0   | 0
26028 | VN  | US   | United States*   | Freight  | Export | 0   | 0   | 0   | 0   | 5   | 0
26029 | VN  | US   | United States*   | Freight  | Import | 0   | 0   | 0   | 5   | 0   | 0
26030 | VN  | US   | United States*   | Freight  | Import | 0   | 0   | 0   | 0   | 0   | 0
33567 | VN  | US   | United States*   | Package  | Export | 0   | 0   | 0   | 0   | 0   | 5
33568 | VN  | US   | United States*   | Package  | Import | 0   | 0   | 0   | 0   | 0   | 5
```

### 2.4. Unique IATA codes

250 mã IATA duy nhất (bao gồm `ZZ` cần loại bỏ → còn 249).

## 3. Logic pivot cho importer

### 3.1. Thuật toán

```
INPUT: 2838 dòng flat
OUTPUT: zone_map[iata][direction][service_code] = zone

FOR mỗi dòng:
  SKIP nếu IATA rỗng hoặc == "ZZ"
  
  FOR mỗi service_column IN [XPR, XPD, WXS, WXP, WFM, EXW]:
    zone_value = cell value
    IF zone_value > 0:
      key = (IATA, direction=Mvn, service_code=service_column)
      zone_map[key] = zone_value
```

### 3.2. Kết quả pivot kỳ vọng

Sau pivot, mỗi country có tối đa 12 zone entries (6 service × 2 direction):

| IATA | Direction | WXS | XPD | WFM | EXW | XPR | WXP |
|------|-----------|-----|-----|-----|-----|-----|-----|
| US   | Export    | 5   | 5   | 5   | 5   | 5   | 5   |
| US   | Import    | 5   | 5   | 0   | 5   | 5   | 5   |
| AF   | Export    | 9   | 9   | 0   | 0   | 0   | 0   |
| AF   | Import    | 10  | 0   | 0   | 0   | 10  | 0   |

### 3.3. Validation sau pivot

- US phải có zone 5 cho WXS/XPD/WFM export.
- WFM import phải có 0 country khả dụng (đã xác nhận).
- ZZ phải bị skip.
- Tổng unique country (trừ ZZ): 249.

## 4. Ảnh hưởng tới design docs khác

| Document | Mục cần cập nhật |
|----------|------------------|
| `02-data-mapping-and-import.md` § 1.2 | Cấu trúc IATA.xlsx: thay mô tả pivot 255×14 bằng flat 2838×12 |
| `02-data-mapping-and-import.md` § 6 | Quy tắc importer zone: thêm bước pivot |
| `02-data-mapping-and-import.md` § 7 | Số liệu kiểm chứng: vẫn đúng, chỉ khác cách đọc |
| `03-system-architecture.md` § 2 | Zone_Importer: ghi chú hỗ trợ flat format |

## 5. Tương thích CSV template

Plugin cung cấp CSV template dạng **pivot** (dễ cho admin):

```csv
iata_code,country_name,direction,wxs,xpd,wfm,exw,xpr,wxp
AF,Afghanistan,export,9,9,0,0,0,0
US,United States*,export,5,5,5,5,5,5
```

Importer hỗ trợ cả 2 format input:
- **CSV pivot** (template chuẩn plugin).
- **XLSX flat** (file gốc từ UPS/đối tác, auto-detect & pivot).
