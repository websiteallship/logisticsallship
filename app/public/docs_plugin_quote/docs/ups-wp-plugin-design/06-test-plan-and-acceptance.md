# 06. Test plan và tiêu chí nghiệm thu

## 1. Mục tiêu test

Đảm bảo plugin:

- Import đúng dữ liệu Excel.
- Tra đúng zone theo direction/service/destination.
- Chọn đúng cột `US5` cho Mỹ.
- Tính đúng cân quy đổi và rounding.
- Tính đúng nhiều kiện.
- Trả lỗi rõ khi service/tuyến/bảng giá không khả dụng.

## 2. Unit tests

### Weight calculator

| Case | Input | Expected |
|---|---|---|
| Actual lớn hơn dim | actual 3.2, dim 1.7 | chargeable 3.5 |
| Dim lớn hơn actual | actual 1.0, dim 1.3 | chargeable 1.5 |
| Đúng mốc 0.5 | actual 2.5 | chargeable 2.5 |
| Lẻ nhỏ | actual 0.1 | chargeable 0.5 |
| Nhiều kiện | 1.3kg + 2.1kg | 1.5 + 2.5 = 4.0 |

### Zone resolver

| Case | Expected |
|---|---|
| `US + WXS + export` | zone `5`, rate zone `US5` |
| `CA + WXS + export` | zone `5`, rate zone `5` |
| `AE + WFM + export` | zone `7` |
| Country có WFM = 0 | `LANE_NOT_AVAILABLE` |
| Service `XPR` phase 1 | `SERVICE_NOT_SUPPORTED` |

### Rate lookup

| Case | Expected |
|---|---|
| WXS Document 1kg US | lấy cột `US5`, dòng 1kg |
| WXS Non-document 6kg Zone 5 | lấy dòng 6kg, zone 5 |
| XPD 1.3kg | làm tròn 1.5kg trước, nhưng XPD bảng mốc 1kg/2kg cần quy tắc lookup lên mốc tiếp theo có sẵn |
| WXS Non-document 25kg | dùng bracket `21-44`, tính `per_kg x 25` |
| WFM 80kg | max(minimum, rate_per_kg bracket `71-99` x 80) |

Lưu ý: XPD trong file hiện có mốc 1kg, 2kg... đến 20kg, không có mốc 0.5. Với mọi bảng, nguyên tắc tổng quát là làm tròn lên mốc tính cước rồi chọn dòng nhỏ nhất `>= chargeable_weight` nếu không có mốc đúng.

## 3. Import tests

### Rate file validation

- Tìm đủ 4 bảng giá.
- Tìm đủ zone columns `1-10` và `US5`.
- Parse được `UPS Envelope`.
- Parse được bracket `21-44`, `>1000`, `Minimum`.
- Parse được Accessorial `Dim 5500`.

### Zone file validation

- Import country `US`, `CA`, `MX`, `PR`.
- Dòng `ZZ` bị skip.
- `US` có `is_us_override = 1`.
- `United States*` có `has_extended_area_note = 1`.
- Export WFM có 36 country/vùng khả dụng.
- Import WFM có 0 country/vùng khả dụng.

## 4. Integration test cases

### Case 1 - US, WXS Non-document, 6kg

Input:

```json
{
  "destination_iata": "US",
  "service_code": "WXS",
  "shipment_type": "nondocument",
  "pieces": [
    { "quantity": 1, "actual_weight_kg": 6, "length_cm": 10, "width_cm": 10, "height_cm": 10 }
  ]
}
```

Expected:

- Zone thật: `5`.
- Rate zone: `US5`.
- Chargeable weight: `6.0`.
- Base price: giá dòng 6kg, cột US5 trong Express Saver Non-Document.

### Case 2 - Canada, WXS Non-document, 6kg

Expected:

- Zone thật: `5`.
- Rate zone: `5`.
- Base price khác US nếu cột Zone 5 khác `US5`.

### Case 3 - AE, WFM, 80kg

Expected:

- Zone export WFM: `7`.
- Dùng bảng Worldwide Express Freight.
- Dùng bracket `71-99`, so với minimum.

### Case 4 - Document quá cân

Input `WXS + document + 6kg`.

Expected:

- Không tự dùng Non-document.
- Trả lỗi nghiệp vụ hoặc yêu cầu user đổi loại hàng.

### Case 5 - Multi-piece

Input:

- Kiện 1: actual 1.3kg, dim 0.8kg -> 1.5kg.
- Kiện 2: actual 1.1kg, dim 2.2kg -> 2.5kg.

Expected:

- Shipment chargeable = 4.0kg.
- Log lưu breakdown từng kiện.

## 5. Acceptance criteria

### Import

- Admin import được file giá và zone.
- Preview hiển thị đúng số bảng, số country, số zone map.
- Không active dữ liệu nếu validate fail.
- Có thể rollback rate card active trước đó.

### Public quote

- User tính được giá bằng shortcode.
- Form không hiển thị Import trong phase 1.
- Service chưa hỗ trợ không xuất hiện hoặc bị disable kèm note.
- Kết quả hiển thị zone, rate zone, cân tính cước, giá, ghi chú phụ phí.

### Admin

- Admin xem được quote logs.
- Admin chỉnh được dim divisor và rounding step.
- Admin bật/tắt phụ phí nhưng mặc định off.

### Kỹ thuật

- Không dùng postmeta để lưu bảng giá.
- Query lookup có index.
- Code tuân chuẩn WordPress escaping/sanitization.
- Có unit tests cho weight, zone, rate lookup.

## 6. Rủi ro còn lại

| Rủi ro | Cách giảm |
|---|---|
| Quy tắc dim divisor `/5000` hay `/5500` tranh chấp | Đưa vào setting, mặc định theo file là `5500`, yêu cầu xác nhận hợp đồng. |
| Heavy bracket là per kg hay flat | Tài liệu chọn per kg theo thông lệ bảng cước, cần khách xác nhận khi nghiệm thu. |
| FSC/Surge/VAT thay đổi định kỳ | Phase 1 không tính, chỉ để setting và ghi note. |
| File Excel layout đổi | Importer tìm label thay vì số dòng cố định. |
| Country có dấu `*` phát sinh phụ phí remote | Lưu flag, chưa tự tính fee trong phase 1. |
