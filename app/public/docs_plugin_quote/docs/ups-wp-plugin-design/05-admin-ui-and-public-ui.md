# 05. Admin UI và Public UI

## 1. Admin menu

Menu chính: `UPS Rate Calculator`

Các màn hình:

| Màn hình | Mục đích |
|---|---|
| Rate Cards | Danh sách phiên bản bảng giá, trạng thái active/archive. Quản lý direction + rate group toggles. |
| Import Excel | Upload file bảng giá và file zone, chạy preview/validate/import. |
| Settings | Cấu hình dim divisor, rounding, phụ phí. |
| Zone Maps | Tra cứu/sửa zone theo country/service nếu cần override thủ công. |
| Quote Logs | Xem lịch sử báo giá, export CSV. |

## 2. Import Excel UI

### Step 1 - Upload

Trường upload:

- Rate file: `SDS_Express rate from 20.Aug.2026 (1).xlsx` hoặc `VN from 20.Aug.2026.xlsx`.
- Zone file: `IATA.xlsx` khuyến nghị.

### Step 2 - Validate preview

Hiển thị:

- Tên rate card.
- Valid from.
- Tìm thấy các bảng:
  - Express Saver Document Rates.
  - Express Saver Non-Document Rates.
  - Expedited Rates.
  - Worldwide Express Freight Rates.
- Tìm thấy cột `US5`.
- Số country import.
- Số zone map theo service.
- Cảnh báo dòng `ZZ` bị bỏ qua.
- Cảnh báo `EXW/XPR/WXP` chưa có bảng giá.

### Step 3 - Import as draft

Admin bấm `Import as draft`, hệ thống ghi DB nhưng chưa active.

### Step 4 - Smoke test

Admin chạy nhanh các case:

- US + WXS Non-document.
- CA + WXS Non-document.
- AE + WFM.
- Destination không có WFM.

### Step 5 - Activate

Chỉ sau khi preview và smoke test pass, admin mới bấm `Activate rate card`.

## 3. Rate Card Management UI

Mỗi rate card có trang detail để quản lý chiều và service:

### 3.1. Chiều vận chuyển (Direction toggles)

- `[✓] Xuất hàng (Export)` — Mặc định bật.
- `[ ] Nhập hàng (Import)` — Mặc định tắt.

Admin bật checkbox để mở section dịch vụ tương ứng.

### 3.2. Rate Group Grid (per direction)

Hiển thị đầy đủ 6 services với tất cả rate groups:

| Service | Rate Group | Rows | Toggle | Status |
|---|---|---|---|---|
| EXW — Express Worldwide | `export_exw_document` | 0 | ◑ OFF | Chưa có data |
| | `export_exw_nondocument` | 0 | ◑ OFF | Chưa có data |
| XPR — Express | `export_xpr_document` | 0 | ◑ OFF | Chưa có data |
| | `export_xpr_nondocument` | 0 | ◑ OFF | Chưa có data |
| WXS — Express Saver | `export_wxs_document` | 126 | [✓ ON] | ✅ |
| | `export_wxs_nondocument` | 234 | [✓ ON] | ✅ |
| XPD — Expedited | `export_xpd` | 234 | [✓ ON] | ✅ |
| WXP — Express Plus | `export_wxp` | 0 | ◑ OFF | Chưa có data |
| WFM — Express Freight | `export_wfm` | 48 | [ OFF] | Admin tắt |

Toggle logic:

| State | Visual | Interaction |
|-------|--------|-------------|
| Có data + enabled | `[✓ ON]` xanh | Clickable → tắt |
| Có data + admin tắt | `[ OFF]` đỏ + badge | Clickable → bật |
| Không có data | `◑ OFF` xám | Disabled, tooltip "Import bảng giá để bật" |
| Direction chưa bật | Section collapsed | Bật checkbox direction để mở |

## 4. Settings UI

Nhóm `Weight calculation`:

- Dim divisor: default `5500`.
- Rounding step: default `0.5kg`.
- Rounding mode: default `round up`.

Nhóm `Fees`:

- Include VAT: off.
- VAT percent.
- Include FSC: off.
- FSC percent.
- Include Surge fee: off.
- Surge percent.
- Include customs fee: off.
- Customs fee VND, default 10000.

> **Deprecated**: Nhóm `Phase` và `Services` đã chuyển sang Rate Card detail page (§ 3).

## 5. Public quote form

### Layout field

0. Chiều vận chuyển (Direction selector):
   - Xuất hàng (Việt Nam → Quốc tế).
   - Nhập hàng (Quốc tế → Việt Nam).
   - Ẩn direction selector nếu chỉ 1 chiều bật.
   - Khi đổi chiều, service tabs và origin/destination labels cập nhật dynamic.
1. Dịch vụ (dynamic từ API `/services?direction=...`):
   - Chỉ hiển thị services enabled + có data.
   - Services admin tắt: hiện mờ (opacity 0.45) + tooltip.
   - Services không có data: không hiện.
2. Loại hàng:
   - Document.
   - Non-document.
   - Chỉ hiện khi chọn service có `has_document_split` (WXS, EXW, XPR).
3. Tuyến vận chuyển & Điểm đến:
   - Export: Nơi gửi = VN (dropdown tỉnh), Nước đến = searchable combobox.
   - Import: Nước gửi = searchable combobox, Nơi nhận = VN (dropdown tỉnh).
   - Labels thích ứng theo direction.
   - Chi tiết địa chỉ đến (tuỳ chọn - Phase 1 Giải pháp A):
     - Bang / Tỉnh: Dropdown lọc theo quốc gia từ Static JSON.
     - Thành phố: Dropdown lọc theo Bang/Quốc gia.
     - Mã bưu chính (Zipcode): Text input.
     - Địa chỉ cụ thể: Text input.
4. Kiện hàng:
   - Bảng nhiều dòng.
   - Số lượng.
   - Cân nặng thực.
   - Dài/rộng/cao.
   - Nút thêm/xóa kiện.
5. Nút tính giá.
6. Bảng kết quả.

## 6. Bảng kết quả

Nên hiển thị:

| Dòng | Nội dung |
|---|---|
| Service | Tên dịch vụ và mã service |
| Destination | Country và IATA |
| Zone | Zone thật |
| Rate zone | `US5` nếu Mỹ, nếu không là Zone |
| Actual weight | Tổng cân thực |
| Dim weight | Tổng cân quy đổi |
| Chargeable weight | Cân tính cước sau rounding |
| Base price | Giá theo bảng |
| Fees | Rỗng/off trong phase 1 hoặc chi tiết nếu bật |
| Total | Tổng giá |
| Notes | Giá chưa gồm VAT/FSC/Surge/fees nếu chưa bật |

## 7. UX edge cases

| Trường hợp | UI xử lý |
|---|---|
| Chọn Document nhưng cân > 5kg | Báo: Document chỉ có bảng đến 5kg, vui lòng chọn Non-document hoặc liên hệ tư vấn. |
| Chọn WFM tới country không có WFM | Báo tuyến không hỗ trợ freight. |
| Chọn US | Kết quả hiển thị ghi chú dùng `US5`. |
| Không nhập kích thước | Cho phép nếu chính sách cho phép, dim weight = 0 và cảnh báo nên nhập đủ. |
| Nhiều kiện | Hiển thị breakdown từng kiện. |
| Giá chưa gồm phụ phí | Luôn hiển thị note rõ. |
| Tất cả service bị tắt | Hiện thông báo "Chưa có dịch vụ khả dụng" + hotline. |
| Chỉ 1 direction bật | Ẩn direction selector, hiện label chiều phía trên. |
| Đổi direction | Service tabs reload dynamic, origin/dest labels đổi chiều. |
| Service admin-disabled (có data) | Tab mờ (opacity 0.45), non-clickable, tooltip lý do. |

## 8. Accessibility và responsive

- Form dùng label rõ ràng, không chỉ placeholder.
- Input number có unit trong label: kg, cm.
- Kết quả dùng table responsive.
- Error hiển thị gần field và có summary ở đầu form.
- Nút tính giá có loading state.
- Không khóa form bằng alert popup khó đọc.

## 9. Nội dung tiếng Việt đề xuất

Service labels:

- `EXW`: Express Worldwide.
- `XPR`: Express.
- `WXS`: Worldwide Express Saver.
- `XPD`: Expedited.
- `WXP`: Worldwide Express Plus.
- `WFM`: Worldwide Express Freight.

Notes:

- `Giá tạm tính theo bảng cước hiệu lực từ 20/08/2026.`
- `Giá chưa bao gồm VAT, FSC, Surge fee, customs fee và các phụ phí khác nếu có.`
- `Cân tính cước được làm tròn lên mốc 0.5kg.`
- `Với nhiều kiện, hệ thống tính cân quy đổi riêng từng kiện rồi cộng lại.`
