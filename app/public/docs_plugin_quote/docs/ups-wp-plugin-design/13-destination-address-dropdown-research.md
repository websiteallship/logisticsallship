# 13. Nghiên cứu & Thiết kế Dropdown Địa chỉ đến (Bang, Thành phố, Zipcode)

> Plugin: **Allship UPS Quote** (`allship-ups-quote`)  
> Cập nhật: 24/09/2026  
> Tình trạng: **Đã chốt Giải pháp A (Static JSON + Text Zipcode)** cho Phase 1  

---

## 1. Tính năng này có ảnh hưởng giá cước UPS không?

## 1. Tính năng này có ảnh hưởng giá cước UPS không?

### ✅ CÓ — Zipcode ảnh hưởng trực tiếp đến giá

| Yếu tố | Ảnh hưởng giá? | Ghi chú |
|---------|:-:|---------|
| Country (IATA) | ✅ Xác định Zone | Đã có trong hệ thống |
| State/Province | ⚠️ Gián tiếp | Chỉ là nhóm con của country |
| City | ⚠️ Gián tiếp | Phụ thuộc vào zipcode |
| **Zipcode** | ✅ **Trực tiếp** | Xác định chính xác Zone, **DAS surcharge**, remote area surcharge |

### Cụ thể với context Allship Phase 1:

> **Bảng giá hiện tại** (`VN from 20.Aug.2026.xlsx` + `IATA.xlsx`) chỉ tính giá theo **Country → Zone → Weight**. Chưa có logic surcharge theo zipcode.

**Kết luận Phase 1**: State/City/Zipcode **không ảnh hưởng giá tính** trong hệ thống hiện tại, nhưng:
- Thu thập thông tin này → sales tư vấn DAS/Remote surcharge chính xác hơn
- Tăng độ tin cậy cho khách ("biết nơi gửi đến cụ thể")
- Chuẩn bị cho Phase 2 (UPS Rating API thực tế)

---

## 2. Phân tích các giải pháp

### Giải pháp A: Static JSON từ dr5hn database (⭐ Recommended)

**Nguồn**: [github.com/dr5hn/countries-states-cities-database](https://github.com/dr5hn/countries-states-cities-database)

| Tiêu chí | Đánh giá |
|-----------|----------|
| Dữ liệu | 250+ countries, **5,300+ states**, 153K+ cities |
| Format | JSON, SQL, CSV |
| Auth | ❌ Không cần |
| Uptime | ♾️ Tự host, không phụ thuộc |
| Cập nhật | Community-maintained, ít thay đổi |
| File size | `states.json` ~2MB, `countries+states` gzipped ~200KB |

**Cách implement**:
```
1. Download states.json (chỉ cần state-level cho Phase 1)
2. Build JSON: { "US": [...states], "JP": [...states], ... }
3. Lọc chỉ các IATA country có trong bảng zone (~250 nước)
4. Khi user chọn country → filter states → render dropdown
5. Zipcode = text input tự do (không validate cụ thể)
```

**Ưu điểm**:
- ✅ **Offline 100%** — form không bao giờ bị lỗi do API third-party
- ✅ Zero latency khi switch country
- ✅ Không giới hạn request, không API key
- ✅ File nhỏ, bundle được vào plugin
- ✅ Data ổn định (states/provinces ít thay đổi)

**Nhược điểm**:
- ❌ Không validate zipcode (chỉ text input)
- ❌ Không có city list (quá nặng ~271MB full)

---

### Giải pháp B: CountriesNow API (Free, No Auth)

**Base URL**: `https://countriesnow.space/api/v0.1`

| Endpoint | Method | Mô tả |
|----------|--------|--------|
| `/countries/states` | POST `{"country":"..."}` | Lấy states/provinces |
| `/countries/state/cities` | POST `{"country":"...","state":"..."}` | Lấy cities theo state |

**Ưu điểm**:
- ✅ Free, không cần API key
- ✅ Có cả states + cities
- ✅ Dữ liệu chuẩn ISO

**Nhược điểm**:
- ❌ **Phụ thuộc uptime** — API chết = form hỏng
- ❌ Latency mỗi lần switch country (200-500ms)
- ❌ Không có zipcode
- ❌ Không phù hợp cho production shipping form

---

### Giải pháp C: Google Places Autocomplete API

**Ưu điểm**:
- ✅ Autocomplete thông minh, parse address components
- ✅ Validate address thực tế
- ✅ Hỗ trợ zipcode

**Nhược điểm**:
- ❌ **Tốn phí** ($17/1000 requests, session $0.017/session)
- ❌ Cần Google Cloud billing account + API key
- ❌ Overkill cho "tra cước tạm tính"
- ❌ UX phức tạp hơn cho user VN (gõ tiếng Anh)

---

### Giải pháp D: Country State City API (CSC)

**URL**: `https://countrystatecity.in`

| Tiêu chí | Đánh giá |
|-----------|----------|
| Free tier | 100 req/ngày |
| Paid | $3/tháng (10K req) |
| Data | Giống dr5hn (cùng nguồn) |

**Nhược điểm**: Tốn phí khi scale, phụ thuộc third-party.

---

### Giải pháp E: UPS Address Validation API

**Dùng cho Phase 2+** khi cần:
- Validate address chính xác trước khi tạo đơn
- Tính DAS/Remote surcharge
- Tích hợp UPS Rating API (giá real-time)

**Không phù hợp Phase 1** vì:
- Cần UPS Developer account + OAuth 2.0
- Chỉ validate US/PR (Street Level)
- Quá phức tạp cho "tra cước tham khảo"

---

## 3. Khuyến nghị cho Allship Plugin

### Phase 1: **Static JSON + Text Zipcode** (Giải pháp A)

```
┌─────────────────────────────────────────────┐
│  Nước đến *     │ 🇺🇸 United States (US)   ▼│  ← Có sẵn
├─────────────────────────────────────────────┤
│  Bang / Tỉnh    │ California              ▼│  ← Conditional dropdown
│  (tuỳ chọn)     │   (load từ static JSON)   │     từ static states.json
├─────────────────────────────────────────────┤
│  Zipcode        │ 90210                     │  ← Free text input
│  (tuỳ chọn)     │   (không validate)        │     placeholder: "VD: 90210"
└─────────────────────────────────────────────┘
```

#### Logic conditional:
```js
// Khi user chọn country:
onCountrySelect(iata) → {
  const states = STATES_DATA[iata]; // từ static JSON
  if (states && states.length > 0) {
    showStateDropdown(states);       // Hiện dropdown searchable
    showZipcodeInput();              // Hiện text input
  } else {
    hideStateDropdown();             // Ẩn nếu country nhỏ (Monaco, Vatican...)
    hideZipcodeInput();
  }
}
```

#### Data format cho `states_by_country.json`:
```json
{
  "US": [
    { "name": "Alabama", "code": "AL" },
    { "name": "Alaska", "code": "AK" },
    { "name": "California", "code": "CA" }
  ],
  "JP": [
    { "name": "Tokyo", "code": "13" },
    { "name": "Osaka", "code": "27" }
  ],
  "AU": [
    { "name": "New South Wales", "code": "NSW" },
    { "name": "Victoria", "code": "VIC" }
  ]
}
```

#### File size ước tính:
- Chỉ lấy states cho ~250 countries có zone: **~80-120KB** (gzipped ~20KB)
- Không cần cities (quá nặng, không ảnh hưởng giá)

#### UX trên form:
| Thao tác user | Kết quả |
|---------------|---------|
| Chọn country có states | Hiện dropdown "Bang / Tỉnh / Khu vực" (searchable) |
| Chọn country không có states | Ẩn dropdown state |
| Gõ zipcode | Free text, placeholder gợi ý format |
| Tất cả các trường này | **Tuỳ chọn** — không bắt buộc, không block tính giá |
| Submit API | Gửi kèm `{ state: "CA", zipcode: "90210" }` → log cho sales |

### Phase 2+ (Tương lai):
- Dùng UPS Rating API real-time → giá chính xác theo zipcode
- Dùng UPS Address Validation → validate + suggest addresses
- Tích hợp DAS/Remote surcharge logic

---

## 4. So sánh tổng quan

| Tiêu chí | A: Static JSON | B: CountriesNow | C: Google Places | D: CSC API | E: UPS API |
|----------|:-:|:-:|:-:|:-:|:-:|
| **Chi phí** | Free | Free | **$17/1K** | $3/mo | Free* |
| **Uptime** | ♾️ | ⚠️ | ✅ | ✅ | ✅ |
| **Offline** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **States** | ✅ | ✅ | ✅ | ✅ | ❌ |
| **Cities** | ❌ | ✅ | ✅ | ✅ | ❌ |
| **Zipcode validate** | ❌ | ❌ | ✅ | ❌ | ✅ (US) |
| **Setup phức tạp** | Thấp | Thấp | Cao | Trung bình | Cao |
| **Phù hợp Phase 1** | ⭐⭐⭐ | ⭐⭐ | ⭐ | ⭐⭐ | ❌ |

---

## 5. Implementation plan

### Bước 1: Tạo `states_by_country.json`
- Download từ dr5hn, lọc chỉ countries có trong IATA zone list
- Format: `{ [iso2]: [{ name, code }] }`
- Estimate: ~100KB

### Bước 2: UI conditional fields
- Sau country dropdown → hiện state dropdown (searchable, tuỳ chọn)
- Text input cho zipcode (tuỳ chọn)
- Label thay đổi theo country:
  - US → "State" + "ZIP Code"
  - JP → "Prefecture" + "Postal Code"
  - AU → "State/Territory" + "Postcode"
  - Default → "Tỉnh / Bang / Khu vực" + "Mã bưu chính"

### Bước 3: API contract update
```json
{
  "destination_iata": "US",
  "destination_state": "CA",        // optional
  "destination_zipcode": "90210",   // optional
  "service_code": "WXS",
  "pieces": [...]
}
```
→ Giá vẫn tính theo Zone (không đổi), nhưng log lưu state + zipcode cho sales.

### Bước 4: Admin log enrichment
- Quote log thêm cột `dest_state`, `dest_zipcode`
- Sales filter được "khách tra cước đi California" hoặc "tra cước gửi zipcode vùng sâu"
