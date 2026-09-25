# Bộ tài liệu thiết kế Plugin WordPress: Allship UPS Quote

Ngày lập: 23/09/2026  
Cập nhật: 23/09/2026  
Plugin slug: `allship-ups-quote`  
Phạm vi: Phase 1 - tính giá chiều Export, gửi hàng từ Việt Nam đi quốc tế.

## 1. Nguồn dữ liệu đã nghiên cứu

| File | Vai trò trong hệ thống |
|---|---|
| `VN from 20.Aug.2026.xlsx` | File bảng giá rút gọn, 1 sheet `VN from 20.Aug.2026`. |
| `SDS_Express rate from 20.Aug.2026 (1).xlsx` | Workbook đầy đủ gồm bảng giá, `Zn Chart`, `Export zone`, `Import zone`, `Zn`. Dùng để kiểm chứng cấu trúc dữ liệu. |
| `Zone chart.xlsx` | Dữ liệu zone dạng phẳng 42.480 dòng, gồm `Cty`, `Mvn`, `Svc`, `Zn`, `IATA`, `Rate Guide Country Name`, `Svc Type`. |
| `IATA.xlsx` | Bảng zone phẳng đã pivot theo `Cty=VN`, dễ import nhất cho đủ 6 service code Export/Import. |
| `thiet-ke-plugin-tinh-cuoc-ups.md` | Tài liệu phân tích cũ, đã dùng để đối chiếu nhưng không lấy làm nguồn duy nhất vì terminal đọc bị lỗi encoding. |

## 2. Tài liệu trong bộ này

| Tài liệu | Nội dung |
|---|---|
| [01-business-requirements.md](01-business-requirements.md) | Bài toán, phạm vi phase 1, thuật ngữ, quy tắc tính cước. |
| [02-data-mapping-and-import.md](02-data-mapping-and-import.md) | Cấu trúc Excel, mapping service/zone/rate, quy tắc importer. |
| [03-system-architecture.md](03-system-architecture.md) | Kiến trúc plugin WordPress, module, luồng xử lý. |
| [04-database-and-api-spec.md](04-database-and-api-spec.md) | Schema custom tables, REST API, shortcode, response mẫu. |
| [05-admin-ui-and-public-ui.md](05-admin-ui-and-public-ui.md) | Màn hình admin, form báo giá ngoài website, UX tránh nhầm Export/Import. |
| [06-test-plan-and-acceptance.md](06-test-plan-and-acceptance.md) | Test cases, dữ liệu mẫu, tiêu chí nghiệm thu. |
| [07-adr.md](07-adr.md) | Architecture Decision Records cho các quyết định chính. |
| [08-implementation-roadmap.md](08-implementation-roadmap.md) | Roadmap chi tiết từng phase/step (đã cập nhật). |
| [09-iata-data-format-update.md](09-iata-data-format-update.md) | Cập nhật cấu trúc thực tế IATA.xlsx (flat vs pivot). |
| [10-frontend-tech-spec.md](10-frontend-tech-spec.md) | Đặc tả kỹ thuật frontend: Vanilla JS, API contract, UI. |
| [11-backend-tech-spec.md](11-backend-tech-spec.md) | Đặc tả kỹ thuật backend: PHP, CSV/XLSX import, DB, caching. |
| [12-theme-integration.md](12-theme-integration.md) | Hướng dẫn tích hợp với theme allship-logistics. |
| [13-destination-address-dropdown-research.md](13-destination-address-dropdown-research.md) | Nghiên cứu và đặc tả dropdown tỉnh/thành - bang - zipcode cho điểm đến (Giải pháp A: Static JSON + Text Zipcode). |

## 3. Kết luận thiết kế ngắn

- Plugin name: **Allship UPS Quote**, slug `allship-ups-quote`.
- Phase 1 chỉ mở `Export`.
- Dịch vụ phase 1: `WXS`, `XPD`, `WFM`.
- `WXS` tách thành Document và Non-document.
- `EXW`, `XPR`, `WXP` có thể import zone trước nhưng chưa mở tính giá vì chưa có bảng giá tương ứng.
- Cân quy đổi mặc định: `D x R x C / 5500` (đã chốt). Setting `dim_divisor` cho admin.
- Heavy bracket (`21-44`, `45-70`...): **per_kg** (đã chốt). Giá × cân tính cước.
- Cân tính cước mỗi kiện: `max(actual_weight, dim_weight)`, làm tròn lên mốc `0.5kg`, sau đó cộng nhiều kiện.
- Giá file gốc chưa gồm VAT, FSC, Surge fee, customs fee 10.000 VND/AWB và phụ phí khác.
- Mỹ dùng cột giá riêng `US5`, không dùng giá Zone 5 chung.
- Import dữ liệu: **CSV-first** (zero dependency) + SimpleXLSX (~100KB) cho .xlsx.
- Admin có thể tạo **nhiều bảng giá** (rate cards) với tên tùy ý, chỉ 1 active.
- Admin có thể chỉnh sửa thủ công rates/zones qua UI.
- Plugin tự tạo page "Báo giá UPS" (slug `bao-gia-ups`) khi activate.
- Quote logs hỗ trợ export CSV/Excel.
- `IATA.xlsx` có cấu trúc **flat** (2838 dòng), xem chi tiết tại `09-iata-data-format-update.md`.
