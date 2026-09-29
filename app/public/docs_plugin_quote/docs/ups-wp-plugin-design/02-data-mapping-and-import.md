# 02. Mapping dữ liệu và thiết kế importer

## 1. Cấu trúc file đã kiểm tra

### 1.1. `SDS_Express rate from 20.Aug.2026 (1).xlsx`

Workbook đầy đủ có 5 sheet:

| Sheet | Số dòng x cột | Vai trò |
|---|---:|---|
| `VN from 20.Aug.2026` | 263 x 15 | Bảng giá chính. |
| `Zn Chart` | 262 x 14 | Pivot/tổng hợp zone theo service. |
| `Export zone` | 252 x 6 | Zone export cho `WXS`, `XPR`, `XPD`. |
| `Import zone` | 257 x 6 | Zone import cho `WXS`, `XPR`, `XPD`. |
| `Zn` | 255 x 14 | Bảng zone đã pivot tương tự `IATA.xlsx`. |

### 1.2. `IATA.xlsx`

> **⚠️ CẬP NHẬT (23/09/2026)**: Cấu trúc thực tế của file này là **flat/unpivoted** (2838 dòng, 12 cột), KHÔNG phải pivot 255×14 như mô tả bên dưới. Xem chi tiết tại [09-iata-data-format-update.md](09-iata-data-format-update.md).
> Importer phải hỗ trợ cả 2 format: flat (XLSX gốc) và pivot (CSV template plugin).

~~Sheet `Sheet1`, 255 x 14. Đây là nguồn import zone phù hợp nhất cho plugin vì đã pivot theo `Cty=VN` và có đủ:~~

Cấu trúc thực tế — Sheet `VN`, 2838 dòng, 12 cột:

| Cột | Nội dung |
|---|---|
| A | `IATA` |
| B | `Rate Guide Country Name` |
| C-H | Export: `EXW`, `WFM`, `WXP`, `WXS`, `XPD`, `XPR` |
| I-N | Import: `EXW`, `WFM`, `WXP`, `WXS`, `XPD`, `XPR` |

Dòng `ZZ - Tortola (British Virgin Islands)` là dòng rác/trùng với `VG - British Virgin Islands`, cần loại bỏ khi import.

### 1.3. `Zone chart.xlsx`

File này là bảng dữ liệu phẳng 42.480 dòng, 24 cột. Các cột quan trọng:

| Cột | Ý nghĩa |
|---|---|
| `Cty` | Market gốc, cần lọc `VN`. |
| `Mvn` | Direction: `Export` hoặc `Import`. |
| `Svc` | Service code: `EXW`, `XPR`, `WXS`, `XPD`, `WXP`, `WFM`. |
| `Svc Avail` | Service availability. |
| `Zn` | Zone thực tế, `0` nghĩa là không áp dụng. |
| `IATA` | Mã điểm đến/đi. |
| `Rate Guide Country Name` | Tên quốc gia/vùng. |
| `Svc Type` | `Package` hoặc `Freight`. |

File này hữu ích để audit, nhưng importer phase 1 nên ưu tiên `IATA.xlsx` vì đã được lọc/pivot sẵn, nhỏ hơn và dễ kiểm soát.

## 2. Cấu trúc bảng giá `VN from 20.Aug.2026`

| Khối | Dòng | Service | Cột zone |
|---|---:|---|---|
| Express Saver Document Rates | 10-23 | `WXS` Document | Zone 1-10 + `US5` |
| Express Saver Non-Document Rates | 26-75 | `WXS` Non-document | Zone 1-10 + `US5` |
| Expedited Rates | 77-106 | `XPD` | Zone 1-10 + `US5` |
| Worldwide Express Freight Rates | 109-117 | `WFM` | Zone 1-10 + `US5` |
| Accessorial Surcharge | 120-135 | Cấu hình tham khảo | Không phải bảng giá chính |

Các cột giá trong sheet:

| Cột Excel | Nội dung |
|---|---|
| B | Weight hoặc weight bracket |
| C-L | Zone `1` đến `10` |
| M | `US5` |

## 3. Bảng mapping service group

| Internal `rate_group` | Điều kiện | Label trong Excel |
|---|---|---|
| `saver_document` | `service_code=WXS`, `shipment_type=document` | Express Saver Document Rates |
| `saver_nondocument` | `service_code=WXS`, `shipment_type=nondocument` | Express Saver Non-Document Rates |
| `expedited` | `service_code=XPD` | Expedited Rates |
| `freight` | `service_code=WFM` | Worldwide Express Freight Rates |

## 4. Quy tắc importer bảng giá

Importer hiện tại được thiết kế để đọc cấu trúc **Simple Matrix format** thay vì quét label tự động:

- **Hàng tiêu đề (Header row):** Chứa các cột zone `1` đến `10` và `US5`. (Cột ngay trước cột zone đầu tiên được ngầm hiểu là cột cân nặng).
- **Hàng dữ liệu (Data rows):** Các hàng bên dưới chứa trọng lượng (weight/bracket) và giá tiền tương ứng cho từng cột zone.
- **Rate Group:** Được xác định từ bên ngoài (thông qua dropdown chọn dịch vụ trên form import của Admin) thay vì quét label bên trong file.

=> Do đó, mỗi lần import chỉ xử lý **1 dịch vụ (Rate Group)**. Để đưa dữ liệu 4 dịch vụ vào hệ thống, cần thực hiện import 4 lần với cùng 1 file, mỗi lần chọn một `Rate Group` khác nhau trên giao diện.

Quy trình đọc file:
1. Quét 10 dòng đầu để tìm dòng chứa ít nhất 5 từ khóa zone (1,2,3,4,5,6,7,8,9,10,US5).
2. Lấy chỉ số cột zone tương ứng.
3. Đọc từng dòng weight/bracket từ sau dòng header.
4. Chuẩn hóa mỗi dòng thành record:

```json
{
  "rate_card_id": 1,
  "rate_group": "export_wxs_nondocument", // Lấy từ form input
  "weight_label": "3.5",
  "weight_from": 3.5,
  "weight_to": 3.5,
  "billing_unit": "flat",
  "zone": "7",
  "price_vnd": 1571660
}
```

## 5. Chuẩn hóa weight/bracket

| Dữ liệu Excel | `weight_from` | `weight_to` | `billing_unit` | Ghi chú |
|---|---:|---:|---|---|
| `UPS Envelope` | null | null | `flat` | Chỉ cho Document. |
| `0.5`, `1`, `1.5` | chính nó | chính nó | `flat` | Mốc cân thường. |
| `21-44` | 21 | 44 | `per_kg` | Bậc nặng. |
| `100-299` | 100 | 299 | `per_kg` | Bậc nặng. |
| `>1000` | 1000.0001 | null | `per_kg` | Bậc mở. |
| `Minimum` | null | null | `minimum` | Chỉ WFM. |

## 6. Quy tắc importer zone

Nguồn khuyến nghị: `IATA.xlsx`.

Với mỗi dòng từ row 6:

1. Bỏ qua nếu `IATA` rỗng.
2. Bỏ qua nếu `IATA = ZZ`.
3. Tạo/cập nhật country:

```text
iata_code = cột A
country_name = cột B
is_us_override = iata_code == "US"
has_extended_area_note = country_name chứa "*"
```

4. Tạo zone map cho từng cột service:

```text
direction = export/import
service_code = EXW/WFM/WXP/WXS/XPD/XPR
zone = cell value
is_available = zone != 0 and zone != "-" and zone not empty
```

5. Phase 1 chỉ dùng export, nhưng vẫn có thể import cả import để sẵn dữ liệu cho phase sau.

## 7. Số liệu kiểm chứng từ `IATA.xlsx`

Không tính dòng `ZZ`:

### Export

| Service | Số quốc gia/vùng có zone |
|---|---:|
| `EXW` | 52 |
| `WFM` | 36 |
| `WXP` | 77 |
| `WXS` | 223 |
| `XPD` | 222 |
| `XPR` | 135 |

### Import

| Service | Số quốc gia/vùng có zone |
|---|---:|
| `EXW` | 206 |
| `WFM` | 0 |
| `WXP` | 82 |
| `WXS` | 211 |
| `XPD` | 85 |
| `XPR` | 206 |

Kết luận nghiệp vụ: `WFM` hiện chỉ có chiều Export trong bộ dữ liệu này.

## 8. Kiểm chứng ngoại lệ US5

Trong `IATA.xlsx`:

| IATA | Country | Export WXS | Export XPD | Export WFM |
|---|---|---:|---:|---:|
| `US` | United States* | 5 | 5 | 5 |
| `CA` | Canada* | 5 | 5 | 5 |
| `MX` | Mexico* | 5 | 5 | 5 |
| `PR` | Puerto Rico* | 5 | 5 | 5 |

Nhưng khi tra giá:

- `US` dùng cột `US5`.
- `CA`, `MX`, `PR` dùng cột Zone `5`.

## 9. Versioning dữ liệu

Mỗi lần import tạo một `rate_card` mới:

```text
name = "UPS Vietnam Prepaid Net Rates in VND"
valid_from = 2026-08-20
source_file_name = tên file upload
source_file_hash = sha256
status = draft/imported/active/archived
```

Hệ thống cho phép **nhiều `rate_card` active đồng thời** để phục vụ nhiều dịch vụ khác nhau. Tuy nhiên, với cùng một dịch vụ và chiều vận chuyển (Rate Group, ví dụ `export_wxp`), chỉ có duy nhất **một** bảng giá active tại một thời điểm. Khi kích hoạt một bảng giá mới cho dịch vụ X, bảng giá active cũ của dịch vụ X sẽ tự động chuyển sang `archived`, trong khi các bảng giá active của các dịch vụ khác (và chiều khác) vẫn duy trì trạng thái `active`. Admin có thể import trước dưới dạng `draft`, preview, test rồi mới bấm activate.

## 10. Validation khi import

Importer phải báo lỗi nếu:

- Không tìm thấy dòng header chứa các cột zone `1-10` và `US5` trong 10 dòng đầu.
- Thiếu cột `US5`.
- Thiếu zone `1-10`.
- Giá không phải số ở dòng giá.
- File zone không có `IATA` và `Rate Guide Country Name`.
- Không tìm thấy country `US`.
- Dòng `US` không có zone 5 cho `WXS/XPD/WFM` export.

Importer nên cảnh báo, không chặn, nếu:

- Có service chưa có bảng giá (`EXW/XPR/WXP`).
- Có country chứa `*`.
- Có zone import nhưng phase 1 chưa dùng.
