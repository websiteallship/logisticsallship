# 01 - KIẾN TRÚC THEME WORDPRESS

> **Approach**: Hardcode toàn bộ nội dung trang (text, ảnh). Chỉ ACF cho Header/Footer (logo, phone, email, address, socials). Menu dùng `wp_nav_menu()`, footer dùng Widget.

---

## 1. Cấu trúc thư mục Theme

```text
wp-content/themes/allship-logistics/
├── style.css                              # Khai báo theme metadata
├── functions.php                          # Entry point, require inc/*
├── index.php                              # Fallback bắt buộc
├── screenshot.png                         # Screenshot WP Admin (1200x900)
│
├── header.php                             # <html>, <head>, Navbar (ACF + wp_nav_menu)
├── footer.php                             # Footer (ACF + Widget), Quote Modal, wp_footer()
│
├── front-page.php                         # Trang chủ (hardcode)
├── page-dich-vu-hai-quan.php              # Template Name: Dịch vụ Hải quan
├── page-dich-vu-kho-bai.php               # Template Name: Dịch vụ Kho bãi
├── page-dich-vu-van-chuyen-duong-bien.php # Template Name: Vận chuyển Đường biển
├── page-dich-vu-van-chuyen-hang-du-an.php # Template Name: Hàng Dự án
├── page-dich-vu-van-chuyen-hang-khong.php # Template Name: Vận chuyển Hàng không
├── page-dich-vu-van-chuyen-noi-dia.php    # Template Name: Vận chuyển Nội địa
├── page-gioi-thieu.php                    # Template Name: Giới thiệu
├── page-lien-he.php                       # Template Name: Liên hệ
│
├── template-parts/                        # Components dùng chung
│   ├── quote-modal.php                    # FluentForm modal (thay hardcoded form)
│   ├── trust-bar.php                      # Logo marquee strip
│   ├── cta-section.php                    # CTA cuối trang
│   └── testimonials.php                   # Swiper carousel
│
├── inc/                                   # Logic PHP tách biệt
│   ├── setup.php                          # Theme support, menus, widgets, enqueue
│   ├── acf-fields.php                     # ACF Theme Options (logo, phone, email, address, socials)
│   └── wp-cleanup.php                     # Xóa emoji, generator, jQuery migrate, block CSS
│
└── assets/                                # Static assets
    ├── fonts/                             # Manrope woff2 (3 files)
    ├── images/                            # 42 files từ logistic/assets/images/
    ├── logo/                              # logo-white.png, logomail.png
    └── src/
        ├── css/
        │   ├── allship-base.css           # Core CSS (fonts, tokens, nav, buttons, animations)
        │   ├── allship-theme.css          # Tailwind @theme tokens (SOURCE OF TRUTH)
        │   └── fluentform-custom.css      # FluentForm style overrides
        └── js/
            ├── main.js                    # Core JS (nav, scroll, counter, modal, accordion, cursor)
            ├── echarts-init.js            # ECharts world map (index only)
            ├── echarts-customs.js         # ECharts customs page
            ├── echarts-network.js         # SVG network graph (gioi-thieu)
            ├── air-freight.js             # Page: hàng không
            ├── sea-freight.js             # Page: đường biển
            ├── domestic-freight.js         # Page: nội địa
            ├── project-cargo.js           # Page: hàng dự án
            ├── warehouse.js               # Page: kho bãi
            ├── gioi-thieu.js              # Page: giới thiệu
            └── lien-he.js                 # Page: liên hệ
```

---

## 2. ACF Scope (Chỉ Header/Footer)

### Theme Options Page

| Field | Type | Name | Dùng ở |
|-------|------|------|--------|
| Logo | Image (url) | `company_logo` | header.php |
| Logo trắng | Image (url) | `company_logo_white` | footer.php |
| Hotline | Text | `company_phone` | header.php, footer.php |
| Email | Text | `company_email` | footer.php |
| Địa chỉ | Textarea | `company_address` | footer.php |
| Facebook URL | URL | `social_facebook` | footer.php |
| Zalo | Text | `social_zalo` | footer.php |
| YouTube | URL | `social_youtube` | footer.php |

### Helper function (đã có trong inc/acf-fields.php)

```php
function allship_option( $name, $default = '' ) {
    if ( ! function_exists( 'get_field' ) ) return $default;
    $val = get_field( $name, 'option' );
    return ( $val !== null && $val !== '' && $val !== false ) ? $val : $default;
}
```

---

## 3. Phân biệt Dynamic vs Hardcode

| Thành phần | Approach | Chi tiết |
|------------|----------|----------|
| **Header nav** | `wp_nav_menu('menu-1')` | Menu WP Admin → Appearance → Menus |
| **Header logo** | ACF `company_logo` | Fallback: `assets/logo/logomail.png` |
| **Header phone** | ACF `company_phone` | Fallback: `1900 252 338` |
| **Footer links** | Widget `footer-1` | WP Admin → Appearance → Widgets |
| **Footer contact info** | ACF `company_*` | Phone, email, address |
| **Footer socials** | ACF `social_*` | Facebook, Zalo, YouTube |
| **Page content (text)** | **HARDCODE PHP** | Copy nguyên từ HTML |
| **Page images** | **HARDCODE** | `get_template_directory_uri() . '/assets/images/...'` |
| **Forms** | FluentForm shortcode | `do_shortcode('[fluentform id="X"]')` |
| **Quote Modal** | FluentForm hoặc hardcode form | template-parts/quote-modal.php |

---

## 4. WP Menu Structure

### Primary Menu (menu-1)
```
Trang chủ          → /
Giới thiệu         → /gioi-thieu/
Dịch vụ ▾
  ├── Hải quan            → /dich-vu-hai-quan/
  ├── Vận chuyển Đường biển → /dich-vu-van-chuyen-duong-bien/
  ├── Vận chuyển Hàng không → /dich-vu-van-chuyen-hang-khong/
  ├── Vận chuyển Nội địa   → /dich-vu-van-chuyen-noi-dia/
  ├── Kho bãi              → /dich-vu-kho-bai/
  └── Hàng Dự án           → /dich-vu-van-chuyen-hang-du-an/
Liên hệ            → /lien-he/
```

### Footer Widget Area (footer-1)
Dùng **Custom HTML Widget** hoặc **Navigation Menu Widget** để render footer links.

---

## 5. WordPress Pages (WP Admin)

Tạo 9 WP Pages và gán Template:

| Page Title | Slug | Template |
|-----------|------|----------|
| Trang chủ | _(front-page)_ | `front-page.php` (auto) |
| Giới thiệu | `gioi-thieu` | Giới thiệu |
| Dịch vụ Hải quan | `dich-vu-hai-quan` | Dịch vụ Hải quan |
| Dịch vụ Kho bãi | `dich-vu-kho-bai` | Dịch vụ Kho bãi |
| Vận chuyển Đường biển | `dich-vu-van-chuyen-duong-bien` | Vận chuyển Đường biển |
| Hàng Dự án | `dich-vu-van-chuyen-hang-du-an` | Hàng Dự án |
| Vận chuyển Hàng không | `dich-vu-van-chuyen-hang-khong` | Vận chuyển Hàng không |
| Vận chuyển Nội địa | `dich-vu-van-chuyen-noi-dia` | Vận chuyển Nội địa |
| Liên hệ | `lien-he` | Liên hệ |

**Settings → Reading → Front page**: Chọn "Trang chủ"
