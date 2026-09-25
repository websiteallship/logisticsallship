# 06 - CONVERSION CHECKLIST

> Checklist từng bước thực hiện khi chuyển đổi mỗi trang HTML sang WP.

---

## Phase 1: Foundation (Làm 1 lần)

### 1.1 Theme Skeleton
- [ ] Cập nhật `style.css` metadata (Author, Theme URI, Version)
- [ ] Cập nhật `functions.php` — require `inc/wp-cleanup.php`
- [ ] Tạo `inc/wp-cleanup.php` (copy từ `05-CLEANUP-PERFORMANCE.md`)
- [ ] Cập nhật `inc/setup.php` — tách enqueue ra function riêng, thêm conditional page JS
- [ ] Cập nhật `inc/acf-fields.php` — thêm `company_logo_white` field
- [ ] Thêm helper `allship_option()` function

### 1.2 Assets Copy
- [ ] Copy `logistic/assets/images/*` (42 files) → `theme/assets/images/`
- [ ] Sync `logistic/assets/src/css/allship-base.css` → `theme/assets/src/css/`
- [ ] Sync `logistic/assets/src/css/allship-theme.css` → `theme/assets/src/css/`
- [ ] Copy page-specific JS files (11 files) → `theme/assets/src/js/`
- [ ] Modify `main.js`: replace `onReady()` with `DOMContentLoaded`

### 1.3 Header & Footer
- [ ] Cập nhật `header.php`: thêm inline Tailwind tokens `<style type="text/tailwindcss">`
- [ ] Cập nhật `header.php`: thêm Swiper CSS CDN link
- [ ] Cập nhật `header.php`: thêm preload hints (fonts, CDN preconnect)
- [ ] Xác nhận `footer.php` có `<?php wp_footer(); ?>`
- [ ] Tạo `template-parts/quote-modal.php`

### 1.4 Template Parts
- [ ] Tạo `template-parts/trust-bar.php` (copy trust bar HTML)
- [ ] Tạo `template-parts/testimonials.php` (copy testimonials HTML)
- [ ] Tạo `template-parts/cta-section.php` (copy CTA HTML)

### 1.5 WP Admin
- [ ] Tạo 9 WP Pages (xem `01-ARCHITECTURE.md` → Section 5)
- [ ] Gán template cho mỗi page
- [ ] Tạo Primary Menu (Appearance → Menus)
- [ ] Cấu hình Menu structure (với dropdown Dịch vụ)
- [ ] Settings → Reading → Front page: Static page → "Trang chủ"
- [ ] Settings → Permalinks → Post name
- [ ] Populate ACF Theme Options (logo, phone, email, address, socials)
- [ ] Import FluentForm (nếu có JSON export)

---

## Phase 2: Page Conversion (Lặp lại cho mỗi trang)

### Thứ tự khuyến nghị:
1. `front-page.php` (phức tạp nhất, làm trước để xử lý lỗi)
2. `page-lien-he.php` (đơn giản nhất)
3. `page-gioi-thieu.php`
4. `page-dich-vu-hai-quan.php`
5. `page-dich-vu-kho-bai.php`
6. Các trang dịch vụ còn lại

### Checklist cho MỖI trang:

#### A. Tạo file template
- [ ] Tạo file `page-xxx.php` với đúng Template Name
- [ ] Thêm `get_header()` đầu file
- [ ] Thêm `get_footer()` cuối file

#### B. Copy content
- [ ] Copy toàn bộ `<main>` ... `</main>` từ file HTML gốc
- [ ] Paste vào giữa `get_header()` và `get_footer()`

#### C. Fix asset paths
- [ ] Thay TẤT CẢ `src="assets/` → `src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/`
- [ ] Thay TẤT CẢ `href="dich-vu-xxx.html"` → `href="<?php echo esc_url(home_url('/dich-vu-xxx/')); ?>"`
- [ ] Thay TẤT CẢ `href="index.html"` → `href="<?php echo esc_url(home_url('/')); ?>"`
- [ ] Thay `href="gioi-thieu.html"` → `href="<?php echo esc_url(home_url('/gioi-thieu/')); ?>"`
- [ ] Thay `href="lien-he.html"` → `href="<?php echo esc_url(home_url('/lien-he/')); ?>"`

#### D. Replace shared components
- [ ] Thay Trust Bar inline HTML → `<?php get_template_part('template-parts/trust-bar'); ?>`
- [ ] Thay Testimonials inline HTML → `<?php get_template_part('template-parts/testimonials'); ?>`
- [ ] Thay CTA inline HTML → `<?php get_template_part('template-parts/cta-section'); ?>`
- [ ] Thay Contact Form HTML → `<?php echo do_shortcode('[fluentform id="1"]'); ?>`

#### E. Remove obsolete code
- [ ] Xóa `<div id="site-header-placeholder">`
- [ ] Xóa `<div id="site-footer-placeholder">`
- [ ] Xóa `<div id="site-modal-placeholder">`
- [ ] Xóa `<script src="...components-data.js">`
- [ ] Xóa `<script src="...components.js">`
- [ ] Xóa `<script src="...main.js">` (handled by enqueue)
- [ ] Xóa page-specific `<script>` tags (handled by enqueue)
- [ ] Xóa `<head>` section (handled by header.php)
- [ ] Xóa `</body></html>` (handled by footer.php)

#### F. Verify
- [ ] Mở trang trên browser → giao diện khớp HTML gốc
- [ ] Kiểm tra console: không có 404 errors
- [ ] Kiểm tra mobile responsive (375px)
- [ ] Kiểm tra interactions: scroll animations, accordions, tabs
- [ ] Kiểm tra navigation links hoạt động
- [ ] Kiểm tra images load đúng

---

## Phase 3: Final QA

- [ ] Tất cả 9 trang render đúng
- [ ] Header sticky scroll hoạt động
- [ ] Mobile menu mở/đóng đúng
- [ ] Footer accordion mobile hoạt động
- [ ] GSAP scroll animations chạy
- [ ] Swiper testimonials carousel chạy
- [ ] Counter animations chạy
- [ ] Quote modal mở/đóng đúng
- [ ] FluentForm submit hoạt động
- [ ] Pricing tabs chuyển đổi đúng (trang Hải quan)
- [ ] Project cargo tabs hoạt động
- [ ] ECharts map hiển thị (trang chủ)
- [ ] Coverage map zoom hoạt động (mobile)
- [ ] Custom cursor hoạt động (desktop)
- [ ] ACF Theme Options → thay đổi phone → frontend cập nhật
- [ ] SEO: kiểm tra `<title>` tags (Yoast/RankMath)
- [ ] Responsive: 375px, 768px, 1024px, 1440px
- [ ] `lang="vi"` trên `<html>`
- [ ] Không có broken images
- [ ] Lighthouse: Performance > 85, Accessibility > 90
