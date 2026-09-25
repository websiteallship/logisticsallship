# Thiết kế hệ thống Plugin WordPress: Công cụ tính giá cước UPS tự động

## 1. Phân tích dữ liệu nguồn (đã kiểm tra trực tiếp file Excel)

File có **4 sheet**, không phải 2 như mô tả ban đầu:

| Sheet | Vai trò |
|---|---|
| `VN from 20.Aug.2026` | Bảng giá gốc theo Zone (1–10, US5) và theo cân nặng, chia 4 nhóm dịch vụ |
| `Export zone` | Bảng tra cứu **zone xuất khẩu** từ VN → từng quốc gia, theo mã IATA |
| `Import zone` | Bảng tra cứu **zone nhập khẩu** vào VN ← từng quốc gia, theo mã IATA |
| `Zn Chart` | Pivot table tổng hợp (có slicer chọn quốc gia gốc AU/CNN/HK/.../VN) — **đây chỉ là báo cáo trực quan**, dữ liệu gốc thực chất nằm ở 2 sheet Export/Import zone |

→ Với plugin, nên **import trực tiếp từ `Export zone` và `Import zone`** (dữ liệu sạch, dạng bảng phẳng: `IATA | Country | WXS | XPR | XPD`), bỏ qua `Zn Chart` vì nó chỉ là pivot hiển thị.

### 1.1. Cấu trúc bảng giá (`VN from 20.Aug.2026`)

4 khối bảng giá độc lập, mỗi khối có cột **Zone 1–10 + US5**, hàng theo cân nặng:

| Khối | Dòng | Đơn vị cân nặng |
|---|---|---|
| Express Saver – Document | 10–24 | 0.5–5.0 kg (bước 0.5) + UPS Envelope |
| Express Saver – Non-Document | 27–75 | 0.5–20 kg (bước 0.5) + 7 bậc hàng nặng (21‑44…>1000) |
| Expedited | 78–106 | tương tự Non-Document |
| Worldwide Express Freight | 110–117 | chỉ có bậc hàng nặng (Minimum, 71‑99…>1000) |

Ngoài ra có bảng **Accessorial Surcharge** (dòng 120–135): Dim Divisor = 5500, và các phụ phí dạng % giảm/miễn (không phải số tiền tuyệt đối) — cần **bảng cấu hình % riêng**, KHÔNG hard-code.

### 1.2. Mã dịch vụ (service code) — ĐÃ CHỐT theo xác nhận của khách hàng

6 mã dịch vụ UPS chia 2 nhóm:

| Nhóm | Mã | Tên đầy đủ | Bảng giá | Trạng thái |
|---|---|---|---|---|
| Package | `EXW` | Express Worldwide / Ex Works | *(chưa có)* | Bỏ qua — phát triển sau |
| Package | `XPR` | Express (tiêu chuẩn) | *(chưa có)* | Bỏ qua — phát triển sau |
| Package | `WXS` | Worldwide Express Saver | **Express Saver Document Rates** + **Express Saver Non-Document Rates** | ✅ Phase 1 |
| Package | `XPD` | Expedited | **Expedited Rates** | ✅ Phase 1 |
| Freight | `WXP` | Worldwide Express Plus | *(chưa có)* | Bỏ qua — phát triển sau |
| Freight | `WFM` | Worldwide Express Freight | **Worldwide Express Freight Rates** | ✅ Phase 1 |

Ghi chú kỹ thuật quan trọng: **nguồn dữ liệu zone cho từng mã không nằm cùng 1 sheet**:

- `WXS`, `XPD` → lấy trực tiếp từ 2 sheet `Export zone` / `Import zone` (cột C=WXS, D=XPR, E=XPD — đã có sẵn, sạch, đúng 1 dòng/quốc gia).
- `WFM` → **không có** trong `Export zone`/`Import zone`. Phải lấy từ sheet `Zn Chart` (cột H = WFM-export, cột N = WFM-import), vì đây là mã thuộc nhóm Freight, không nằm trong 2 bảng zone Package nói trên. Cần viết importer riêng đọc đúng 2 cột này từ `Zn Chart`, join theo mã IATA.
- `EXW`, `XPR`, `WXP` — dữ liệu zone đã tồn tại sẵn trong `Zn Chart` (nhiều quốc gia có giá trị zone thực, không phải toàn 0 như tôi đánh giá nhầm ở bản trước), nhưng **chưa có bảng giá tương ứng** trong sheet `VN from 20.Aug.2026` → import zone map cho 3 mã này ngay từ bây giờ (để không phải re-import sau), nhưng **khoá (disable) ở tầng nghiệp vụ** cho đến khi có bảng giá thật. DB schema ở mục 2 đã tính sẵn việc này (`rate_table` không có dòng nào cho `EXW/XPR/WXP` → lookup trả rỗng → UI hiển thị "Dịch vụ sắp ra mắt").

### 1.3. Cột `US5` — ngoại lệ theo quốc gia, không phải zone

Đã đối chiếu dữ liệu: `US5` là mức giá **riêng cho United States**, dù Mỹ được xếp vào **Zone 5** giống Canada, Mexico, Puerto Rico (cả export lẫn import, cả 3 mã WXS/XPR/XPD). Giá cột US5 luôn cao hơn một chút so với giá Zone 5 chung (ví dụ Saver Document 1kg: Zone5 = 1.258.417đ, US5 = 1.274.248đ).

→ Hệ quả thiết kế: bảng `rate_table` cần zone `"5"` và zone override riêng `"US5"`. Logic tra cứu (mục 3, Bước 4) phải thêm rule:
```
IF destination_country_iata == 'US' → dùng cột giá 'US5' thay vì cột 'Zone 5'
ELSE → dùng cột giá theo zone tra được bình thường
```
Đây là **ngoại lệ cứng theo mã quốc gia**, nên lưu bằng 1 cờ `is_us_override` trong bảng `countries`, không nên mô hình hoá US thành "zone 11" riêng vì zone thật của US trong bảng zone map vẫn là 5 (dùng để tra `Export zone`/`Import zone` bình thường), chỉ khác ở bước chọn CỘT GIÁ cuối cùng.

### 1.4. Đã kiểm tra lại nguồn dữ liệu `Zn Chart` — xác nhận đây là PivotTable thật, không phải bảng tĩnh

Đã mở file ở mức XML (`xl/pivotTables/`, `xl/pivotCache/`) để kiểm chứng, phát hiện thêm chi tiết quan trọng:

- `Zn Chart` là một **PivotTable kết nối tới Excel Data Model (Power Pivot)**, không phải một bảng dữ liệu tĩnh nằm sẵn trên sheet. Dữ liệu gốc (mọi tổ hợp Country × Direction × Package/Freight × Svc × Zone) thực chất nằm trong **Data Model ẩn** của workbook — cấu trúc các field đã thấy: `SN, Cty, Mvn, Svc, Svc Avail, ...` — không lộ ra ở dạng sheet phẳng nào cả, kể cả `Export zone`/`Import zone` (2 sheet đó chỉ là 2 pivot/export khác, chỉ lọc đúng 3 cột WXS/XPR/XPD).
- Đã xác nhận cấu trúc cột đúng như ảnh bạn gửi: `Export → Package (EXW, XPR, WXS, XPD)` + `Export → Freight (WXP, WFM)`, và tương tự cho `Import`. Đây khớp 100% với mapping đã chốt ở mục 1.2 (WFM nằm trong nhóm Freight, cột thứ 6 của Export và cột thứ 6 của Import).
- Đã kiểm tra định dạng số của các ô: giá trị `0` trong cache thực chất được **hiển thị thành dấu `"-"`** nhờ custom number format (`#,##0;-#,##0;"-"`) — tức là cách hiểu "0 = tuyến/dịch vụ không áp dụng" ở bản thiết kế trước là **đúng**, không phải lỗi dữ liệu.

**Ảnh hưởng tới cách lấy dữ liệu WFM cho import script**:

| Phương án | Ưu điểm | Nhược điểm |
|---|---|---|
| A. Parse trực tiếp lưới hiển thị của `Zn Chart` (đọc cell values, map theo vị trí cột đã xác nhận: cột H = Export‑WFM, cột N = Import‑WFM) | Làm được ngay, không cần chờ UPS/đối tác | **Phụ thuộc slicer "Mvn"** đang neo ở VN tại thời điểm file được lưu — nếu người dùng Excel lỡ đổi slicer sang thị trường khác rồi lưu & gửi file, importer sẽ đọc nhầm dữ liệu của thị trường khác mà không có cảnh báo |
| B. Yêu cầu bên cấp dữ liệu (UPS/đối tác) xuất thêm 1 sheet phẳng "WFM zone" giống hệt cấu trúc `Export zone`/`Import zone` hiện có | An toàn, đúng chuẩn 2 sheet kia, dễ audit | Cần thời gian chờ phía cung cấp dữ liệu |

**Khuyến nghị**: Dùng phương án A cho bản demo/MVP nhưng **bắt buộc thêm bước kiểm tra an toàn khi import**: đọc giá trị các slicer item đang active (`xl/slicerCaches/slicerCache*.xml`) để xác nhận `Cty = VN` trước khi nạp dữ liệu (lưu ý: field chọn thị trường gốc tên thật là **`Cty`**, còn `Mvn` mới là field phân biệt Export/Import — đã sửa lại sau khi kiểm chứng trực tiếp trên pivot, xem mục 1.5); nếu khác VN → dừng import và báo lỗi rõ ràng cho admin, tránh nạp nhầm dữ liệu thị trường khác vào hệ thống. Về lâu dài nên chuyển sang phương án B.

### 1.5. Đã export & kiểm chứng — file nguồn WFM chính thức (`IATA.xlsx`)

Khách hàng đã tự build 1 pivot mới từ Data Model (Rows: `IATA` + `Rate Guide Country Name`; Columns: `Mvn`(Export/Import) × `Svc`(6 mã); Filter: `Cty=VN`), paste values ra file phẳng. Đã đối chiếu chéo với `Export zone`/`Import zone`:

- ✅ WXS/XPR/XPD khớp 100% với 2 sheet chuẩn ở 3 quốc gia mẫu kiểm tra (US, AU, AE) — xác nhận cách lấy dữ liệu qua pivot mới là đáng tin.
- ⚠️ Có 1 dòng dữ liệu rác: **`ZZ — Tortola (British Virgin Islands)`**, trùng lặp với `VG — British Virgin Islands` đã có sẵn. **Loại bỏ dòng `ZZ` khi import.**
- 🔑 **Phát hiện nghiệp vụ quan trọng**: quét toàn bộ 250 quốc gia, cột Import‑WFM = 0 ở **100%** các dòng — tức là **WFM (Worldwide Express Freight) hiện chỉ tồn tại ở chiều Export, chưa có chiều Import** (36/250 quốc gia có Export‑WFM > 0). Đây là sự thật nghiệp vụ, không phải lỗi — và khớp với việc Phase 1 chỉ triển khai Export nên không phát sinh vấn đề gì.

→ Mục 1.4 (rủi ro phụ thuộc slicer) coi như đã xử lý xong cho MVP: dùng đúng file phẳng này làm nguồn import cho `WFM`, thay vì phải parse `Zn Chart` trực tiếp mỗi lần.

Dùng custom tables (không dùng post/meta vì dữ liệu dạng ma trận, hiệu năng tra cứu quan trọng):

```
wp_ups_countries         (id, iata_code, country_name, is_extended_area)
wp_ups_zone_map          (id, country_id, direction[export|import], service_code[WXS|XPD|XPR], zone) 
wp_ups_rate_table        (id, service_group[saver_doc|saver_nondoc|expedited|freight],
                           zone, weight_from, weight_to, is_weight_break, price_vnd)
wp_ups_surcharge_config  (id, surcharge_name, type[percent_off|fixed], value, applies_to[])
wp_ups_settings          (dim_divisor, vat_percent, fsc_percent, surge_fee, customs_fee_awb, valid_from)
wp_ups_quote_log         (id, user_id, origin, dest, service, weight, dim_weight, chargeable_weight, 
                           zone, base_price, surcharges_json, total, created_at)
```

Lý do tách `zone_map` khỏi `rate_table`: 1 zone dùng chung cho nhiều quốc gia, và zone export/import độc lập nhau — tách ra tránh trùng lặp dữ liệu, và cho phép UPS cập nhật zone theo quý mà không đụng bảng giá.

---

## 3. Luồng nghiệp vụ tính giá (business logic)

```
Bước 1 — Input người dùng:
  origin (VN cố định hoặc chọn nếu multi-origin sau này)
  destination country
  direction: export | import
  service: Saver / Expedited / Freight
  loại hàng: document | non-document (chỉ áp dụng nếu chọn Saver)
  cân nặng thực (kg)
  kích thước D×R×C (cm) — optional, nhiều kiện thì cộng dồn

Bước 2 — Tính khối lượng quy đổi:
  dim_weight = (D × R × C) / dim_divisor   [dim_divisor = 5500, lấy từ Accessorial Surcharge]
  chargeable_weight = MAX(dim_weight, actual_weight)
  → làm tròn lên theo quy tắc UPS (thường 0.5kg hoặc 0.1kg — cần xác nhận)

Bước 3 — Tra zone:
  zone = lookup(zone_map, country=destination, direction='export', service_code)
  [Phase 1 chỉ hỗ trợ direction='export' — xem mục 1.4]
  nếu zone rỗng/'-' → dịch vụ không hỗ trợ tuyến này → báo lỗi cho user
  nếu destination = 'US' → đánh dấu cờ use_us5 = true (xem mục 1.3)

Bước 4 — Tra bảng giá:
  service_group = map(service, is_document)   [WXS+có chứng từ→saver_doc; WXS+không→saver_nondoc; XPD→expedited; WFM→freight]
  price_column = use_us5 ? 'US5' : ('Zone ' + zone)
  row = lookup(rate_table, service_group, price_column, chargeable_weight nằm trong [weight_from, weight_to])
  → chargeable_weight KHÔNG khớp đúng mốc 0.5kg có sẵn (vd 1.3kg) → làm tròn LÊN mốc 0.5kg gần nhất 
    (đề xuất theo thông lệ UPS chuẩn — cần khách hàng xác nhận cuối cùng, xem mục 5)
  → nếu > 20kg (Saver/Expedited) hoặc thuộc khung Freight → dùng khung "bậc hàng nặng" (100‑299kg, v.v.)
  base_price = row.price_vnd

Bước 5 — Cộng phụ phí:
  Với mỗi surcharge trong wp_ups_surcharge_config có applies_to chứa service này:
     nếu type=percent_off → đây là mức GIẢM trên phụ phí gốc, không phải phụ phí phát sinh
       (file hiện tại toàn ghi "100% Off" nghĩa là KHÔNG tính phụ phí đó cho khách này — 
        hệ thống chỉ cần field on/off, phần trăm chỉ hiển thị tham khảo)
     Fuel Surcharge = 50% off → cần base fuel surcharge % gốc từ UPS (KHÔNG có trong file) 
       → phải bổ sung bảng "current fuel surcharge %" cập nhật định kỳ, admin nhập tay hoặc gọi API UPS

Bước 6 — Tổng kết:
  total_before_vat = base_price + Σ surcharges_không_được_miễn + customs_fee(10,000đ/AWB)
  total = total_before_vat × (1 + VAT%)   [VAT nhập ở Settings, KHÔNG có trong file gốc]

Bước 7 — Trả kết quả + log vào wp_ups_quote_log để đối soát sau này
```

**Lưu ý quan trọng**: File Excel ghi rõ *"Rate is not include VAT and surcharge (FSC, Surge fee...)"* — bảng giá hiển thị **chưa** gồm VAT, phí nhiên liệu (FSC %), phí biến động (Surge fee %). Khách hàng xác nhận đúng là các % này không có trong file → giữ nguyên thiết kế: màn hình Admin `page-surcharge-settings.php` cho nhập tay/point tới nguồn cập nhật định kỳ, KHÔNG hard-code.

### 3.1. Xử lý lô hàng nhiều kiện (multi-piece shipment) — đã chốt cách tính

Với 1 lô gồm N kiện, tính **riêng từng kiện** rồi cộng dồn (đúng chuẩn UPS), không gộp kích thước trước:

```
FOR mỗi kiện i trong lô:
    dim_weight_i    = (D_i × R_i × C_i) / 5500
    piece_weight_i  = MAX(actual_weight_i, dim_weight_i)   [làm tròn theo từng kiện]

chargeable_weight_lô = Σ piece_weight_i   (cộng dồn SAU khi đã lấy MAX từng kiện, không lấy MAX của tổng)
```
→ Khác với cách tính sai thường gặp là "cộng tổng cân thực + cộng tổng dim weight rồi mới so sánh" — UPS luôns tính max theo **từng kiện** trước. Cần lưu chi tiết từng kiện vào `quote_log` (dạng JSON) để đối soát khi có khiếu nại.

### 3.2. Phạm vi Phase 1 — chỉ chiều Export

Đã chốt: **Phase 1 chỉ triển khai chiều Export** (gửi hàng từ VN đi nước ngoài). Chiều Import (nhận hàng về VN) sẽ phát triển ở phase sau. Thiết kế DB/logic vẫn giữ trường `direction` (enum `export|import`) ngay từ đầu để không phải sửa schema khi mở rộng, nhưng:
- UI Phase 1 **không hiển thị lựa chọn chiều** cho user (mặc định luôn là export, ẩn hoàn toàn), tránh gây nhầm lẫn như đã lưu ý trước đó.
- Import chỉ cần thêm data (`Import zone` sheet đã có sẵn, cùng cấu trúc) + bật lại UI chọn chiều, không cần sửa logic tính giá.

---

## 4. Kiến trúc Plugin WordPress

```
ups-rate-calculator/
├── ups-rate-calculator.php          (bootstrap, hooks)
├── includes/
│   ├── class-importer.php           (đọc file Excel → DB, dùng PhpSpreadsheet)
│   ├── class-zone-resolver.php      (Bước 3)
│   ├── class-rate-lookup.php        (Bước 4)
│   ├── class-surcharge-engine.php   (Bước 5)
│   ├── class-quote-calculator.php   (điều phối toàn bộ, gọi API)
│   └── class-rest-api.php           (REST endpoint /wp-json/ups/v1/quote)
├── admin/
│   ├── page-rate-import.php         (upload Excel mới, versioning theo "Valid from")
│   ├── page-zone-mapping.php        (sửa tay mapping WXS/XPD/XPR)
│   ├── page-surcharge-settings.php  (bật/tắt & nhập % từng phụ phí)
│   └── page-quote-logs.php          (xem lịch sử báo giá)
└── public/
    ├── shortcode-quote-form.php     ([ups_quote_form])
    └── assets/js/quote-form.js      (gọi REST API bằng fetch, hiển thị kết quả không reload trang)
```

**REST API** (`/wp-json/ups/v1/quote`, method POST):
```json
{
  "direction": "export",
  "destination_iata": "US",
  "service": "saver",
  "is_document": false,
  "weight_kg": 3.2,
  "dimensions_cm": [30, 20, 15]
}
```
Trả về JSON: zone, chargeable_weight, base_price, danh sách surcharge, total, breakdown chi tiết để hiển thị bảng minh bạch cho khách hàng.

**Import Excel**: dùng thư viện `PhpSpreadsheet`, admin upload file mỗi khi UPS ra bảng giá mới → hệ thống tự parse theo đúng cấu trúc dòng/cột đã map ở mục 1 (nên viết importer bám theo **tên nhãn dòng** "Express Saver Document Rates:" thay vì số dòng cố định, để chịu được thay đổi nhỏ về layout giữa các phiên bản file).

---

## 5. Đã chốt vs còn cần xác nhận

### Đã chốt (theo phản hồi của khách hàng)
- ✅ Mapping service code → bảng giá (mục 1.2).
- ✅ Cách hiểu & xử lý cột `US5` (mục 1.3).
- ✅ Nguồn & cách lấy dữ liệu zone cho WFM — xác nhận `Zn Chart` là PivotTable nối Data Model, đề xuất phương án A (parse trực tiếp + kiểm tra slicer an toàn) cho MVP (mục 1.4).
- ✅ Giá chưa gồm VAT/FSC/Surge fee → **không đưa các phụ phí này vào hệ thống ở giai đoạn hiện tại**, nhưng thiết kế cơ chế mở rộng sẵn (bảng `surcharge_config` + hook tính toán ở Bước 5 vẫn giữ nguyên, chỉ đơn giản là không có dòng dữ liệu nào active) để khi có số liệu thật chỉ cần nhập cấu hình, không cần sửa code.
- ✅ Cách tính cân nặng lô nhiều kiện (mục 3.1).
- ✅ Phase 1 chỉ làm chiều Export, ẩn lựa chọn chiều trên UI (mục 3.2).
- ✅ Quy tắc làm tròn cân nặng: **áp dụng mặc định làm tròn LÊN mốc 0.5kg gần nhất** khi cân nặng tính cước không trùng khớp mốc có sẵn trong bảng — khách hàng đồng ý dùng làm mặc định hệ thống. *(Vẫn khuyến nghị ghi rõ quy tắc này vào hợp đồng/SLA với khách hàng cuối để tránh tranh chấp phí, nhưng không còn là điểm chặn kỹ thuật.)*

- ✅ Nguồn dữ liệu WFM đã có file phẳng đã kiểm chứng (`IATA.xlsx`, mục 1.5) — sẵn sàng dùng làm dữ liệu mẫu để code importer.
- ✅ Xác nhận WFM chỉ có chiều Export (không phải thiếu dữ liệu) — không ảnh hưởng Phase 1.

### Còn cần xác nhận trước khi code
1. Loại bỏ dòng rác `ZZ` khỏi dữ liệu import (mục 1.5) — cần quy tắc lọc chung cho các mã IATA lạ/không chuẩn tương tự nếu phát sinh thêm khi UPS cập nhật dữ liệu sau này.
2. Thời điểm và cách phía UPS/đối tác cung cấp bảng giá cho `EXW`, `XPR`, `WXP` trong tương lai (để chuẩn bị trước cấu trúc file import cho phase sau).

Nếu ổn, bước tiếp theo tôi có thể viết import script mẫu (PHP hoặc Python) đọc trực tiếp từ file Excel này theo đúng cấu trúc đã chốt.

### 1.5. Thiết kế địa chỉ gửi và địa chỉ đến (Phase 1: Giải pháp A - Static JSON + Text Zipcode)

- **Nơi gửi**: Cố định Việt Nam (`VN`), có dropdown chọn 63 tỉnh/thành Việt Nam (tuỳ chọn).
- **Địa chỉ đến**:
  - Quốc gia đến: Bắt buộc, dùng tra Zone tính giá.
  - Bang/Tỉnh: Dropdown tuỳ chọn từ Static JSON `states_by_country.json` (196 quốc gia), nhãn thích ứng theo nước (State, Province, Prefecture...).
  - Thành phố: Dropdown tuỳ chọn từ danh mục đô thị trung tâm + option nhập khác.
  - Mã bưu chính (Zipcode): Nhập thủ công tuỳ chọn, phục vụ tư vấn viên sales xác định phụ phí vùng sâu vùng xa (DAS / Remote Area Surcharge).
  - Địa chỉ cụ thể: Nhập thủ công nếu có (số nhà, tên đường...).
- **Ý nghĩa kỹ thuật**: 100% offline, không phụ thuộc API third-party, zero latency, không phát sinh chi phí duy trì.
