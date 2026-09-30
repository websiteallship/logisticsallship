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

Nhóm `Advanced`:

- FluentForm Lead Form ID: default `0` (Tự động nhận diện form FluentForm có tiêu đề chứa "UPS" hoặc "Báo Giá"; admin có thể điền ID cụ thể để ép buộc nhận lead).

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

## 6. Bảng kết quả (Result View & Service Comparison)

Bảng kết quả hiển thị dạng Card Grid (Desktop) và Compact Comparison List (Mobile):

### 6.1. Quy tắc hiển thị Card (Cập nhật ADR-010)
- **Ẩn toàn bộ dịch vụ không có giá khả dụng**: Chỉ hiển thị các service card có cước tính toán thành công (`price > 0`). Tuyệt đối không render các card xám kèm thông báo "Không có bảng giá nào đang hoạt động...".
- **Dynamic Auto-Aligning Grid**:
  - Khi có **1 dịch vụ khả dụng**: Render grid 1 cột căn giữa (`grid-cols-1 max-w-md mx-auto`), tạo sự cân đối sang trọng.
  - Khi có **2 dịch vụ khả dụng**: Render grid 2 cột cân bằng (`grid-cols-1 md:grid-cols-2 max-w-3xl mx-auto`).
  - Khi có **3 dịch vụ trở lên**: Render grid 3 cột tiêu chuẩn (`grid-cols-1 md:grid-cols-2 lg:grid-cols-3`).
  - Khi **0 dịch vụ khả dụng** (theo filter): Hiển thị banner empty-state thân thiện kèm nút liên hệ hotline 1900 252 338.
- **Auto-Switch Service**: Nếu dịch vụ khách hàng chọn ở form trước đó không có bảng giá hoạt động, kết quả tự động chuyển active sang dịch vụ có giá đầu tiên và cập nhật badge ribbon header.
- **Mobile Compact View**: Danh sách thu gọn trên mobile chỉ liệt kê các gói có giá, hiển thị giá rõ ràng, không hiển thị dòng "Liên hệ / Báo giá riêng" cho các bảng giá không tồn tại.

### 6.2. Nội dung chi tiết trên mỗi Card:

| Dòng | Nội dung |
|---|---|
| Service | Tên dịch vụ, mã service và thời gian vận chuyển (Transit time) |
| Destination | Country và IATA code |
| Zone | Zone vật lý và Rate zone (`US5` nếu Mỹ) |
| Actual weight | Cân thực tế kiện hàng |
| Chargeable weight | Cân tính cước sau quy đổi thể tích và làm tròn |
| Base price | Giá cước tạm tính theo bảng giá active |
| Action Button | Nút "Liên hệ đặt dịch vụ" mở Booking Modal với thông tin tuyến điền sẵn |

## 7. Dual CTA Buttons & Modal Xuất Báo Giá PDF (Lead Capture)

Trên mỗi thẻ kết quả dịch vụ và thanh tóm tắt Best Price Summary Bar, hệ thống hiển thị cặp nút hành động:
- **Nút 1: "Liên hệ tư vấn"** (trước đây là Đặt dịch vụ): Mở modal tư vấn dịch vụ dành cho khách muốn nhân viên liên hệ ngay.
- **Nút 2: "Tải Báo Giá PDF"**: Mở modal thu thập Lead B2B để tải file báo giá chính thức có con dấu điện tử.

### 7.1. Booking Modal (Liên Hệ Tư Vấn)
1. **Header**: Tiêu đề "Yêu Cầu Tư Vấn Báo Giá UPS", nút đóng `(X)`, backdrop blur.
2. **Card Tóm tắt Hành trình & Giá (Route Summary Card)**:
   - Tuyến gửi/nhận (chi tiết địa chỉ, thành phố, bang, quốc gia).
   - Badge chiều vận chuyển (`Xuất khẩu` hoặc `Nhập khẩu`).
   - Tên gói dịch vụ đã chọn, trọng lượng tính cước và tổng cước tạm tính màu đỏ thương hiệu `#CE2027`.
3. **Form Nhập Liệu**: Họ và tên *, Số điện thoại *, Ghi chú lô hàng.
4. **Trường ẩn đồng bộ FluentForm**: `service`, `hidden_service_name`, `message`, `quote_log_id`, `pieces_json`...

### 7.2. Modal Xuất Báo Giá PDF Chính Thức (`#quotePdfExportModal`)
1. **Trường nhập liệu**:
   - `Họ và tên *`: In trực tiếp lên phần Người nhận báo giá trên PDF.
   - `Tên công ty / Doanh nghiệp`: In trang trọng dưới tên khách hàng trên PDF.
   - `Email nhận file *`: Hệ thống tự động gửi file PDF đính kèm về hòm thư này.
   - `Số điện thoại / Zalo`: Chuyên viên Allship hỗ trợ giải đáp thủ tục hải quan.
2. **Thanh Tiến Trình Ngang (Progress Bar % Loading)**:
   - Khi bấm submit, modal chuyển sang màn hình loading thanh tiến trình thực tế:
     - 18%: "Đang chuẩn bị dữ liệu tuyến vận chuyển..."
     - 48%: "Đang tổng hợp thông tin cước & phụ phí..."
     - 86%: "Đang biên dịch bảng báo giá PDF & con dấu điện tử..."
     - 100%: "Hoàn tất! Báo giá PDF sẵn sàng tải về..."
   - **Tối ưu tốc độ**: Nhờ cơ chế gửi email ngầm bất đồng bộ, API phản hồi chỉ sau ~0.7s, tiến trình chạy thẳng 100% trong ~1.0 giây, triệt tiêu hoàn toàn hiện tượng kẹt 96%.
3. **Màn hình Thành công & Auto-Download**:
   - Tự động kích hoạt tải file về máy qua liên kết ẩn `a[download]`.
   - Cung cấp nút bấm "Tải lại file PDF" dự phòng nếu trình duyệt chặn popup.

## 8. Quản Lý Bản Ghi Báo Giá & Lead B2B (Quote Logs Mini-CRM)

Tại menu **UPS Rate Calculator ➔ Quote Logs**:
- Sử dụng bảng chuẩn `WP_List_Table` với phân trang 20 dòng/trang.
- **Thao tác hàng loạt (Bulk Delete)**: Admin tích chọn các checkbox bản ghi muốn xoá, chọn action `Xóa` trong dropdown và bấm `Áp dụng`. Hệ thống gọi `process_bulk_action()` thực thi xoá đồng loạt thông qua `delete_multiple()` của repository và hiển thị thông báo thành công.
- **Phân loại Lead Badge tại cột "Khách hàng"**:
  - `Báo giá PDF`: Badge màu đỏ đậm, hiển thị mã tham chiếu `Quote Ref: AS-QUO-XXXX`, Tên công ty, Email `mailto:`, SĐT `tel:`.
  - `Đặt dịch vụ`: Badge màu xanh lục, khách điền form yêu cầu tư vấn.
  - `Khách vãng lai`: Badge màu xám, tra cứu giá tự do.
- **Cột "Thao tác" — Nút xem file PDF trực tiếp**: Đối với các bản ghi đã xuất PDF, hiển thị nút đỏ biểu tượng PDF cho phép Admin xem/tải lại file bất kỳ lúc nào.
- **Bộ lọc tương tác (Interaction Filter)**: Dropdown lọc theo loại tương tác (`Tất cả` | `Khách đã xuất PDF` | `Khách đặt dịch vụ` | `Khách vãng lai`).
- **Modal chi tiết bản ghi (B2B Lead Info Card)**: Hiển thị card riêng biệt nổi bật thông tin doanh nghiệp, MST, người liên hệ, mã hiệu lực và nút mở trực tiếp file PDF.
- **Xuất dữ liệu Excel / CSV**: Bổ sung đầy đủ cột `Tên công ty`, `Email`, `Quote Ref`, `Loại tương tác`, `Link PDF` kèm tiền tố UTF-8 BOM chuẩn tiếng Việt.

## 9. UX edge cases

| Trường hợp | UI xử lý |
|---|---|
| Điểm gửi khởi tạo | Mặc định luôn là "TP. Hồ Chí Minh", đồng bộ qua `state.originProvince`, không bị nhảy về "An Giang" khi load danh sách tỉnh. |
| Chỉ có 1 hoặc 2 bảng giá active (e.g. XPD & WXP) | Chỉ render đúng 1 hoặc 2 card tương ứng, grid tự động co vào giữa (`max-w-md` hoặc `max-w-3xl`). |
| Bảng giá WXP active trực tiếp | Tính trực tiếp theo đơn giá của rate card `export_wxp`, không cưỡng ép chuyển sang WFM hay nhân hệ số phái sinh. |
| Chọn Document nhưng cân > 5kg | Báo: Document chỉ có bảng đến 5kg, vui lòng chọn Non-document hoặc liên hệ tư vấn. |
| Chọn WFM tới country không có WFM | Báo tuyến không hỗ trợ freight. |
| Chọn US | Kết quả hiển thị ghi chú dùng `US5`. |
| Không nhập kích thước | Cho phép nếu chính sách cho phép, dim weight = 0 và cảnh báo nên nhập đủ. |
| Nhiều kiện | Hiển thị modal "Xem bảng kê X kiện" với bảng breakdown chi tiết từng kiện. |
| Giá chưa gồm phụ phí | Luôn hiển thị note rõ trong card. |
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
