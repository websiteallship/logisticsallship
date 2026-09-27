# 07. Architecture Decision Records

## ADR-001: Dùng custom database tables thay vì post/meta

Status: Accepted

### Context

Dữ liệu cần lưu gồm bảng giá theo zone/cân nặng, zone map theo country/service/direction, rate card version và quote logs. Đây là dữ liệu tra cứu dạng bảng, không phải nội dung CMS.

### Decision

Dùng custom tables:

- `ups_rate_cards`
- `ups_countries`
- `ups_zone_maps`
- `ups_rates`
- `ups_settings`
- `ups_quote_logs`

### Rationale

- Lookup nhanh bằng index.
- Dễ versioning bảng giá.
- Dễ audit/log.
- Tránh postmeta query phức tạp và chậm.

### Trade-offs

- Cần tự viết migration/dbDelta.
- Cần tự viết admin UI.

### Revisit trigger

Không cần xem lại trừ khi plugin phải đồng bộ dữ liệu ra hệ thống ERP/CRM lớn hơn.

## ADR-002: Phase 1 chỉ mở Export

Status: Accepted

### Context

Yêu cầu xác định phase 1 chỉ làm export. Import sẽ phát triển sau. File zone có cả Export/Import, nhưng UI dễ gây nhầm nếu mở cả hai.

### Decision

Ẩn direction khỏi public UI, cố định `direction=export`. Schema vẫn giữ field `direction`.

### Rationale

- Giảm rủi ro user chọn sai chiều.
- Không khóa khả năng mở rộng phase sau.
- Phù hợp phạm vi MVP.

### Trade-offs

- Người dùng chưa tự tính được chiều import.

### Revisit trigger

Khi có yêu cầu triển khai phase 2 import.

## ADR-003: Dùng `IATA.xlsx` làm nguồn zone chính

Status: Accepted

### Context

`Export zone` và `Import zone` chỉ có `WXS/XPR/XPD`. `WFM` nằm trong dữ liệu pivot/zone phẳng. `IATA.xlsx` đã pivot theo `Cty=VN`, đủ 6 service code và dễ import.

### Decision

Importer phase 1 ưu tiên đọc zone từ `IATA.xlsx`. `Export zone/Import zone` dùng để đối chiếu hoặc fallback cho Package services.

### Rationale

- Có đủ `WFM`.
- Ít dòng, cấu trúc rõ.
- Đã kiểm chứng với dữ liệu package.

### Trade-offs

- Cần đảm bảo file `IATA.xlsx` được cung cấp cùng file giá.

### Revisit trigger

Nếu nhà cung cấp bổ sung sheet zone phẳng chuẩn trực tiếp trong workbook giá.

## ADR-004: `US5` là rate column override, không phải zone mới

Status: Accepted

### Context

United States thuộc Zone 5 như Canada/Mexico/Puerto Rico, nhưng bảng giá có cột riêng `US5`.

### Decision

Lưu zone thật là `5`. Khi destination là `US`, `Zone_Resolver` trả thêm `rate_zone=US5`.

### Rationale

- Giữ đúng dữ liệu zone nguồn.
- Không làm sai logic zone map.
- Dễ hiển thị minh bạch: zone `5`, cột giá `US5`.

### Trade-offs

- Rate lookup cần biết `rate_zone`, không chỉ `zone`.

### Revisit trigger

Nếu tương lai có nhiều country override tương tự, chuyển sang bảng `ups_rate_zone_overrides`.

## ADR-005: Dim divisor là setting, mặc định `5500`

Status: Accepted

### Context

Yêu cầu ban đầu nêu công thức `/5000`, nhưng file giá hiện hành ghi `Dim 5500`.

### Decision

Tạo setting `dim_divisor`, mặc định `5500`.

### Rationale

- Bám theo file giá hiện hành.
- Vẫn linh hoạt nếu hợp đồng xác nhận `/5000`.

### Trade-offs

- Admin cần hiểu và cấu hình đúng.

### Revisit trigger

Khi nhận xác nhận hợp đồng chính thức về dim divisor.

## ADR-006: Phụ phí phase 1 mặc định không tính

Status: Accepted

### Context

File ghi giá chưa gồm VAT/FSC/Surge/customs fee/phụ phí khác. Yêu cầu hiện tại không đưa các khoản này vào hệ thống, nhưng cần cơ chế mở rộng.

### Decision

Không tự cộng phụ phí trong phase 1. Tạo settings và `Surcharge_Engine` nhưng default off.

### Rationale

- Tránh báo sai khi chưa có nguồn % hiện hành.
- Sau này bật được mà không sửa kiến trúc.

### Trade-offs

- Giá public là giá base, cần note rõ.

### Revisit trigger

Khi có nguồn cập nhật FSC/Surge/VAT chính thức.

## ADR-007: Heavy bracket tính theo kg

Status: Proposed

### Context

Bảng giá các dòng `21-44`, `45-70`, `>1000` có giá nhỏ hơn nhiều so với dòng flat, phù hợp cách hiểu giá theo kg.

### Decision

Với `billing_unit=per_kg`, tính:

```text
price = rate_per_kg x chargeable_weight
```

Riêng WFM áp dụng:

```text
price = max(minimum, rate_per_kg x chargeable_weight)
```

### Rationale

- Phù hợp thông lệ bảng cước hàng nặng.
- Dòng `Minimum` của WFM chỉ có ý nghĩa nếu bracket là per kg.

### Trade-offs

- Cần khách xác nhận bằng văn bản để tránh tranh chấp.

### Revisit trigger

Nếu hợp đồng UPS quy định bracket là flat hoặc có công thức minimum khác.

## ADR-008: Thin Client, On-demand States Chunks và Server-Side Batch Quote Calculation

Status: Accepted

### Context

Trước đây, client nhúng nguyên bundle monolithic `states_by_country.js` (185KB) và dữ liệu localized script `upsQuoteConfig.countries` chứa các trường zone nội bộ của hãng (`wxs`, `xpd`, `wfm`). Điều này vừa làm phình dung lượng tải trang, vừa gây rò rỉ cấu trúc phân vùng nội bộ khi người dùng xem DevTools.

### Decision

1. **Zero-Leakage Data Sanitization**: Loại bỏ 100% các cột zone (`wxs`, `xpd`, `wfm`, `zone`) khỏi dữ liệu trả về cho frontend (`get_active_countries()`). Frontend chỉ nhận `{ iata_code, country_name }`. Mọi logic định tuyến zone được bảo vệ và xử lý tại backend.
2. **On-Demand States Chunks**: Loại bỏ file monolithic 185KB `states_by_country.js`. Tách thành 196 file JSON độc lập theo quốc gia (`assets/data/states/{iata}.json`, 0.5KB - 1.6KB/file). Client tải on-demand qua `fetch()` khi user chọn quốc gia đích và cache vào memory (`stateChunkCache`).
3. **Server-Side Batch Calculation**: Endpoint `POST /calculate` hỗ trợ `service_code: "ALL"`, tính toán toàn bộ các dịch vụ khả dụng trong 1 request duy nhất, trả về từ điển `services`.
4. **Transient Cache Acceleration**: Lưu kết quả tính cước bằng WordPress Transients (`ups_calc_{md5}`) với TTL 3600 giây (1 giờ). Tự động dọn dẹp cache khi kích hoạt bảng giá mới.

### Rationale

- Bảo mật tuyệt đối dữ liệu nội bộ và phân vùng giá cước của hãng vận chuyển.
- Giảm tải dung lượng trang ban đầu (>180KB JS được cắt giảm).
- Phản hồi tức thì (<10ms) với các yêu cầu tính giá lặp lại nhờ Transients cache.
- Giảm thiểu round-trip mạng từ nhiều request riêng lẻ về 1 request duy nhất.

### Trade-offs

- Cần 1 network request nhỏ (<2KB) khi người dùng lần đầu chọn một quốc gia có phân bang (US, CA, AU...). Tuy nhiên request này được cache in-memory ngay lập tức.

