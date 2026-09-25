# Kế hoạch đưa Entries FluentForm ra Frontend

Xây dựng một custom shortcode để hiển thị danh sách entries của một form cụ thể ra ngoài Frontend (UI/UX tương tự Admin), bao gồm tính năng lọc và tìm kiếm bằng AJAX.

## Đề xuất Giải pháp (Proposed Changes)

1. **Tạo Shortcode `[fluentform_frontend_entries form_id="1"]`**
   - Đăng ký shortcode hiển thị khung giao diện table danh sách entries và bộ lọc.
   
2. **Giao diện Frontend (UI/UX)**
   - Sử dụng CSS/JS tùy chỉnh kết hợp với thư viện (như DataTables hoặc Vue/React nhỏ gọn) hoặc JS thuần + AJAX để render dữ liệu giống giao diện backend.
   - Các bộ lọc: Tìm kiếm theo từ khóa (Search), Lọc theo trạng thái (Unread, Read, Trashed), Lọc theo ngày tháng (Date Range).

3. **Backend AJAX API (Xử lý dữ liệu)**
   - Tạo endpoint AJAX (ví dụ `wp_ajax_nopriv_ff_frontend_entries` và `wp_ajax_ff_frontend_entries`).
   - Sử dụng `\FluentForm\App\Api\Submission` hoặc model `\FluentForm\App\Models\Submission` để query data.
   - Trả về JSON chứa danh sách `data` và thông tin `paginate`.

4. **Tích hợp File Code (Custom Plugin hoặc Theme Functions)**
   - Khuyến nghị: Tạo một plugin nhỏ (ví dụ `fluentform-frontend-entries`) để dễ bảo trì, thay vì viết thẳng vào lõi của `fluentform` (tránh mất code khi update plugin).

### Cấu trúc File dự kiến (Nếu tạo Custom Plugin)
#### [NEW] `wp-content/plugins/ff-frontend-entries/ff-frontend-entries.php`
- Chứa logic đăng ký shortcode, AJAX handlers.

#### [NEW] `wp-content/plugins/ff-frontend-entries/assets/js/frontend-entries.js`
- Chứa logic gọi AJAX, xử lý sự kiện click filter/pagination và render DOM.

#### [NEW] `wp-content/plugins/ff-frontend-entries/assets/css/frontend-entries.css`
- Chứa style bảng (table) và UI giống với màn hình admin của FluentForm.

## Open Questions

> [!IMPORTANT]
> 1. Bạn muốn viết thẳng tính năng này như một module/addon trong core của plugin `fluentform` hiện tại hay tách ra thành **1 plugin độc lập** để an toàn khi update?
> 2. Việc hiển thị entries ở frontend có cần yêu cầu quyền (ví dụ: chỉ cho phép user đã đăng nhập, hoặc role cụ thể) không?

## Verification Plan
- Chèn shortcode `[fluentform_frontend_entries form_id="1"]` vào một page.
- Load page ngoài frontend, kiểm tra hiển thị table.
- Thử nghiệm các bộ lọc search, status, pagination hoạt động thông qua network AJAX.
