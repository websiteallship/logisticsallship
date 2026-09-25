# 01. Đặc tả nghiệp vụ

## 1. Mục tiêu

Xây dựng plugin WordPress cho phép người dùng tự tính báo giá cước UPS từ Việt Nam đi quốc tế dựa trên:

- Dịch vụ vận chuyển.
- Điểm đi, mặc định Việt Nam trong phase 1.
- Điểm đến theo country/IATA.
- Loại hàng hóa: tài liệu hoặc hàng hóa phi tài liệu nếu dùng `WXS`.
- Số kiện, cân nặng thực, kích thước từng kiện.
- Bảng zone và bảng giá được import từ Excel.

Kết quả trả về là bảng giá minh bạch gồm zone, cân tính cước, giá gốc, ghi chú phụ phí chưa bao gồm, và log phục vụ đối soát.

## 2. Phạm vi phase 1

### Có làm

- Chiều vận chuyển: `Export` từ Việt Nam đi nước ngoài (mặc định bật). Schema hỗ trợ sẵn `Import` — admin bật trong Rate Card detail khi có bảng giá.
- Dịch vụ phase 1 (bật mặc định):
  - `WXS` - Worldwide Express Saver.
  - `XPD` - Expedited.
  - `WFM` - Worldwide Express Freight.
- Schema template sẵn cho đủ 6 services × 2 directions (18 rate groups) — chỉ cần import data + admin bật, không cần sửa code.
- Import dữ liệu bảng giá từ `VN from 20.Aug.2026`.
- Import dữ liệu zone ưu tiên từ `IATA.xlsx` vì file này đã pivot sẵn cho `Cty=VN` và đủ cả Package/Freight.
- Public quote form bằng shortcode WordPress, có direction selector (ẩn nếu chỉ 1 chiều bật).
- REST API nội bộ cho form gọi AJAX.
- Admin upload/import file giá và quản lý cấu hình, bật/tắt service per rate card.
- Ghi log báo giá.

### Chưa làm trong phase 1

- Bảng giá chiều `Import` về Việt Nam (schema đã sẵn sàng, chưa có data).
- Bảng giá cho `EXW`, `XPR`, `WXP` (template đã tạo, chưa có data → auto-disabled).
- Tự động cập nhật FSC, Surge fee, VAT từ nguồn bên ngoài.
- Tạo đơn hàng thật với UPS API.
- Thanh toán online.

## 3. Mapping dịch vụ

| Nhóm | Mã | Tên | has_document_split | Bảng giá phase 1 | Trạng thái |
|---|---|---|---|---|---|
| Package | `EXW` | Express Worldwide | ✅ | Chưa có | Auto-disabled |
| Package | `XPR` | Express | ✅ | Chưa có | Auto-disabled |
| Package | `WXS` | Worldwide Express Saver | ✅ | Export: Document + Non-document | Bật |
| Package | `XPD` | Expedited | ❌ | Export: Expedited Rates | Bật |
| Freight | `WXP` | Worldwide Express Plus | ❌ | Chưa có | Auto-disabled |
| Freight | `WFM` | Worldwide Express Freight | ❌ | Export: Freight Rates | Bật |

Admin bật/tắt từng service **per rate card** trong Rate Card detail page. Service không có data (0 rows) tự động disabled, admin không thể bật.

### 3.1. Rate Group Naming Convention

Pattern: `{direction}_{service_code}_{shipment_type}` (shipment_type bỏ nếu service không có `has_document_split`).

| rate_group | Direction | Service | Type | Phase 1 |
|---|---|---|---|---|
| `export_wxs_document` | Export | WXS | Document | ✅ |
| `export_wxs_nondocument` | Export | WXS | Non-document | ✅ |
| `export_xpd` | Export | XPD | All | ✅ |
| `export_wfm` | Export | WFM | Freight | ✅ |
| `export_exw_document` | Export | EXW | Document | ❌ |
| `export_exw_nondocument` | Export | EXW | Non-document | ❌ |
| `export_xpr_document` | Export | XPR | Document | ❌ |
| `export_xpr_nondocument` | Export | XPR | Non-document | ❌ |
| `export_wxp` | Export | WXP | Freight | ❌ |
| `import_wxs_document` | Import | WXS | Document | ❌ |
| `import_wxs_nondocument` | Import | WXS | Non-document | ❌ |
| `import_xpd` | Import | XPD | All | ❌ |
| `import_wfm` | Import | WFM | Freight | ❌ |
| `import_exw_document` | Import | EXW | Document | ❌ |
| `import_exw_nondocument` | Import | EXW | Non-document | ❌ |
| `import_xpr_document` | Import | XPR | Document | ❌ |
| `import_xpr_nondocument` | Import | XPR | Non-document | ❌ |
| `import_wxp` | Import | WXP | Freight | ❌ |

Tổng: 18 rate group templates. Phase 1 chỉ có data cho 4 groups (export).

## 4. Quy tắc chọn bảng giá

Rate group được xác định bằng `resolve_rate_group(direction, service_code, shipment_type)`:

| Direction | Service | Điều kiện | rate_group |
|---|---|---|---|
| `export` | `WXS` | Loại hàng = Document | `export_wxs_document` |
| `export` | `WXS` | Loại hàng = Non-document | `export_wxs_nondocument` |
| `export` | `XPD` | Mọi loại hàng | `export_xpd` |
| `export` | `WFM` | Hàng freight | `export_wfm` |
| `import` | `WXS` | Loại hàng = Document | `import_wxs_document` |
| `import` | `WXS` | Loại hàng = Non-document | `import_wxs_nondocument` |
| `import` | `XPD` | Mọi loại hàng | `import_xpd` |
| `import` | `WFM` | Hàng freight | `import_wfm` |

Với `WXS`, `EXW`, `XPR` (services có `has_document_split`), UI bắt buộc hỏi loại hàng. Với `XPD`, `WFM`, `WXP`, trường loại hàng ẩn.

## 5. Quy tắc tính cân

### 5.1. Cân quy đổi

File giá ghi `Dim 5500` trong phần Accessorial Surcharge, vì vậy mặc định hệ thống dùng:

```text
dim_weight = length_cm x width_cm x height_cm / 5500
```

Trong yêu cầu ban đầu có nêu `/5000`. Để tránh tranh chấp, hệ thống phải thiết kế `dim_divisor` là setting trong admin. Giá trị mặc định theo file hiện hành là `5500`; admin có thể đổi thành `5000` nếu hợp đồng xác nhận.

### 5.2. Một kiện

```text
piece_chargeable_weight = ceil_to_step(max(actual_weight, dim_weight), 0.5)
```

Ví dụ: kiện 1.3kg, dim weight 1.1kg -> max = 1.3kg -> làm tròn lên 1.5kg.

### 5.3. Nhiều kiện trong một lô

UPS cần tính từng kiện rồi cộng:

```text
shipment_chargeable_weight = sum(piece_chargeable_weight_i)
```

Không được cộng tổng cân thực và tổng dim rồi mới lấy max, vì cách đó có thể làm sai giá.

## 6. Quy tắc tra zone

Phase 1 cố định:

```text
direction = export
origin = VN
destination = country/IATA user chọn
service_code = WXS | XPD | WFM
```

Tra zone theo `destination_iata` và `service_code`. Nếu zone bằng `0`, rỗng hoặc `-`, service không hỗ trợ tuyến đó.

### 6.1. Thu thập thông tin địa chỉ gửi & địa chỉ đến (Phase 1 - Giải pháp A)

Nhằm tối ưu quy trình tư vấn sales và sẵn sàng cho Phase 2 (UPS Rating & Surcharge API), form thu thập các trường địa chỉ (hoàn toàn **tuỳ chọn**, không làm gián đoạn luồng tra cước):

1. **Nơi gửi (Origin)**:
   - Quốc gia: Mặc định cố định Việt Nam (`VN`).
   - Tỉnh/Thành phố gửi: Dropdown 63 tỉnh/thành Việt Nam (tuỳ chọn, hỗ trợ điều phối kho và tư vấn viên khu vực).
2. **Địa chỉ đến (Destination)**:
   - Quốc gia đến (`dest_country` / `destination_iata`): **Bắt buộc** để tra Zone cước.
   - Bang / Tỉnh đến (`dest_state`): **Dropdown tuỳ chọn**, lọc theo quốc gia từ Static JSON (`states_by_country.json`). Nhãn hiển thị linh hoạt theo nước (State, Province, Prefecture, Bundesland...).
   - Thành phố đến (`dest_city`): **Dropdown tuỳ chọn**, lọc theo Bang / Quốc gia tương ứng từ Static data + tuỳ chọn "Khác (nhập địa chỉ cụ thể)".
   - Mã bưu chính (`dest_postal_code` / Zipcode): **Nhập thủ công tuỳ chọn**. Giúp sales tra cứu phụ phí vùng sâu / vùng xa (DAS - Delivery Area Surcharge, Extended / Remote Area Surcharge).
   - Địa chỉ cụ thể (`dest_address`): **Nhập thủ công tuỳ chọn** (số nhà, tên đường, căn hộ / tòa nhà...).

> **Lưu ý nghiệp vụ**: Trong Phase 1, bảng cước tính theo `Country -> Zone -> Weight`. Các trường Bang/Thành phố/Zipcode không làm thay đổi số tiền cước cơ bản của Phase 1, nhưng được lưu vào log và chuyển tiếp sang booking lead để sales tư vấn phụ phí DAS chính xác.

## 7. Ngoại lệ United States - `US5`

United States vẫn thuộc Zone 5 trong bảng zone, nhưng bảng giá có cột riêng `US5`.

Quy tắc:

```text
if destination_iata == "US":
    rate_zone = "US5"
else:
    rate_zone = zone
```

Không mô hình hóa Mỹ thành zone mới. Zone nghiệp vụ vẫn là `5`; chỉ cột giá cuối cùng được override thành `US5`.

## 8. Quy tắc tra giá theo cân

### 8.1. Document WXS

- Có mức `UPS Envelope`.
- Có các mốc 0.5kg đến 5kg, bước 0.5kg.
- Nếu vượt 5kg, không dùng bảng Document; hệ thống nên báo lỗi hoặc yêu cầu chuyển sang Non-document tùy chính sách kinh doanh.

### 8.2. Non-document WXS và XPD

- Có mốc cân thường đến 20kg.
- Sau 20kg có các bậc nặng: `21-44`, `45-70`, `71-99`, `100-299`, `300-499`, `500-999`, `>1000`.
- Các bậc nặng được xem là giá theo kg. Tổng cước = `rate_per_kg x chargeable_weight`, sau đó áp dụng minimum nếu được cấu hình.

### 8.3. WFM

- Có dòng `Minimum`.
- Có các bậc nặng từ `71-99` trở lên.
- Tổng cước đề xuất:

```text
freight_price = max(minimum_price_for_zone, rate_per_kg_for_bracket x chargeable_weight)
```

Nếu cân dưới mức freight tối thiểu, UI nên cảnh báo hoặc vẫn dùng minimum tùy chính sách bán hàng.

## 9. Phụ phí và VAT

File giá ghi rõ:

```text
Rate is not include VAT and surcharge (FSC, Surge fee, customs fee 10,000 vnd/AWB) and other if any
```

Phase 1 không tự cộng các khoản này vào giá cuối cùng, trừ khi admin bật setting. Tuy nhiên schema và code phải có extension point để sau này thêm:

- VAT.
- Fuel surcharge/FSC.
- Surge fee.
- Customs fee 10.000 VND/AWB.
- Remote/Extended Area.
- Additional Handling.
- Disbursement Fee.

Kết quả public cần hiển thị ghi chú: giá chưa bao gồm VAT/FSC/Surge/fees nếu các fee này chưa bật.

## 10. Tiêu chí thành công

- User chọn service, destination, kiện hàng và nhận kết quả không reload trang.
- Kết quả tra đúng zone theo file nguồn.
- Mỹ luôn dùng cột `US5`.
- Cân lẻ luôn làm tròn lên mốc 0.5kg.
- Nhiều kiện tính đúng theo từng kiện.
- Admin có thể import lại bảng giá mới mà không sửa code.
