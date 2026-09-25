# 03 - ASSETS & ENQUEUE GUIDE

> Chi tiết cách copy assets từ `logistic/` sang WP theme, và cấu hình enqueue đúng chuẩn.

---

## 1. Asset Copy Map

### Nguồn → Đích

| Nguồn (logistic/) | Đích (theme/) | Ghi chú |
|--------------------|---------------|---------|
| `assets/images/*` (42 files) | `assets/images/` | Copy toàn bộ, giữ nguyên tên |
| `assets/logo/logo-white.png` | `assets/logo/` | ĐÃ CÓ |
| `assets/logo/logomail.png` | `assets/logo/` | ĐÃ CÓ |
| `assets/fonts/manrope-*.woff2` (3 files) | `assets/fonts/` | ĐÃ CÓ |
| `assets/src/css/allship-base.css` | `assets/src/css/` | ĐÃ CÓ, cần sync mới nhất |
| `assets/src/css/allship-theme.css` | `assets/src/css/` | ĐÃ CÓ, cần sync |
| `assets/src/js/main.js` | `assets/src/js/` | Cần chỉnh sửa (xem bên dưới) |
| `assets/src/js/echarts-init.js` | `assets/src/js/` | Copy |
| `assets/src/js/echarts-customs.js` | `assets/src/js/` | Copy |
| `assets/src/js/echarts-network.js` | `assets/src/js/` | Copy |
| `assets/src/js/air-freight.js` | `assets/src/js/` | Copy |
| `assets/src/js/sea-freight.js` | `assets/src/js/` | Copy |
| `assets/src/js/domestic-freight.js` | `assets/src/js/` | Copy |
| `assets/src/js/project-cargo.js` | `assets/src/js/` | Copy |
| `assets/src/js/warehouse.js` | `assets/src/js/` | Copy |
| `assets/src/js/gioi-thieu.js` | `assets/src/js/` | Copy |
| `assets/src/js/lien-he.js` | `assets/src/js/` | Copy |

### KHÔNG copy (thay thế bởi WP):

| File | Lý do |
|------|-------|
| `components-data.js` | WP header/footer thay thế |
| `components.js` | WP header/footer thay thế |
| `three-globe.js` | Tùy chọn (nếu không dùng Three.js) |

---

## 2. Images Inventory (42 files)

### Hero Images (4 files, ~1MB each)
- `allship-logistics-cang-bien-hero.png` — Hero trang đường biển
- `allship-logistics-dich-vu-kho-bai-hero.png` — Hero trang kho bãi  
- `allship-logistics-gioi-thieu-hero.png` — Hero trang giới thiệu
- `allship-logistics-van-tai-duong-bo-xe-tai.jpg` — Hero trang nội địa

### Service Images (22 files)
- `allship-logistics-cang-bien-makabera.jpg`
- `allship-logistics-chuoi-cung-ung-toan-cau.jpg`
- `allship-logistics-chuyen-phat-nhanh.jpg`
- `allship-logistics-cong-nghe-quan-ly-chuoi-cung-ung.jpg`
- `allship-logistics-du-an-*.jpg` (6 files) — Portfolio hàng dự án
- `allship-logistics-hoat-dong-cang-bien.jpg`
- `allship-logistics-khai-bao-hai-quan-bento.jpg`
- `allship-logistics-kho-bai-va-luu-giu-hang-hoa.jpg`
- `allship-logistics-nhan-vien-giao-hang.jpg`
- `allship-logistics-thu-tuc-giay-to-xuat-nhap-khau.jpg`
- `allship-logistics-van-chuyen-*.jpg` (5 files)
- `allship-logistics-xe-*.jpg` (3 files)
- `hai-quan-*.jpg` (6 files) — Bento images trang Hải quan
- `kho-bai-*.jpg` (2 files) — Bento images trang Kho bãi

### Testimonial Avatars (8 files, ~5-8KB each)
- `testimonial-1.jpg` → `testimonial-8.jpg`

---

## 3. Enqueue Configuration (inc/setup.php)

### CDN Scripts (thứ tự load)

```php
function allship_enqueue_assets() {
    $v = wp_get_theme()->get('Version');
    $uri = get_template_directory_uri() . '/assets';

    // ── CSS ──
    wp_enqueue_style('allship-base', $uri . '/src/css/allship-base.css', [], $v);
    wp_enqueue_style('allship-fluentform', $uri . '/src/css/fluentform-custom.css', ['allship-base'], $v);
    wp_enqueue_style('swiper-css', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css', [], '11');

    // ── CDN Scripts (render-blocking → <head>) ──
    // Tailwind v4 CDN (MUST load before body parses Tailwind classes)
    wp_enqueue_script('tailwind-cdn', 'https://unpkg.com/@tailwindcss/browser@4', [], null, false);
    // Phosphor Icons
    wp_enqueue_script('phosphor-icons', 'https://unpkg.com/@phosphor-icons/web@2.1.1', [], null, false);

    // ── CDN Scripts (defer → footer) ──
    wp_enqueue_script('gsap', 'https://cdn.jsdelivr.net/npm/gsap@3.12/dist/gsap.min.js', [], '3.12', true);
    wp_enqueue_script('gsap-st', 'https://cdn.jsdelivr.net/npm/gsap@3.12/dist/ScrollTrigger.min.js', ['gsap'], '3.12', true);
    wp_enqueue_script('swiper-js', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js', [], '11', true);

    // ── Core Theme JS ──
    wp_enqueue_script('allship-main', $uri . '/src/js/main.js', ['gsap', 'gsap-st', 'swiper-js'], $v, true);

    // ── Conditional: ECharts (index + customs pages) ──
    if (is_front_page()) {
        wp_enqueue_script('echarts', 'https://fastly.jsdelivr.net/npm/echarts@4.9.0/dist/echarts.min.js', [], '4.9', true);
        wp_enqueue_script('echarts-world', 'https://fastly.jsdelivr.net/npm/echarts@4.9.0/map/js/world.js', ['echarts'], '4.9', true);
        wp_enqueue_script('allship-echarts-init', $uri . '/src/js/echarts-init.js', ['echarts-world'], $v, true);
        // Three.js (optional)
        wp_enqueue_script('threejs', 'https://cdn.jsdelivr.net/npm/three/build/three.min.js', [], null, true);
        wp_enqueue_script('allship-three-globe', $uri . '/src/js/three-globe.js', ['threejs'], $v, true);
    }

    // ── Conditional: Page-specific JS ──
    if (is_page_template('page-dich-vu-hai-quan.php')) {
        wp_enqueue_script('allship-echarts-customs', $uri . '/src/js/echarts-customs.js', ['allship-main'], $v, true);
    }
    if (is_page_template('page-dich-vu-van-chuyen-duong-bien.php')) {
        wp_enqueue_script('allship-sea-freight', $uri . '/src/js/sea-freight.js', ['allship-main'], $v, true);
    }
    if (is_page_template('page-dich-vu-van-chuyen-hang-khong.php')) {
        wp_enqueue_script('allship-air-freight', $uri . '/src/js/air-freight.js', ['allship-main'], $v, true);
    }
    if (is_page_template('page-dich-vu-van-chuyen-noi-dia.php')) {
        wp_enqueue_script('allship-domestic', $uri . '/src/js/domestic-freight.js', ['allship-main'], $v, true);
    }
    if (is_page_template('page-dich-vu-van-chuyen-hang-du-an.php')) {
        wp_enqueue_script('allship-project-cargo', $uri . '/src/js/project-cargo.js', ['allship-main'], $v, true);
    }
    if (is_page_template('page-dich-vu-kho-bai.php')) {
        wp_enqueue_script('allship-warehouse', $uri . '/src/js/warehouse.js', ['allship-main'], $v, true);
    }
    if (is_page_template('page-gioi-thieu.php')) {
        wp_enqueue_script('allship-gioi-thieu', $uri . '/src/js/gioi-thieu.js', ['allship-main'], $v, true);
        wp_enqueue_script('allship-echarts-network', $uri . '/src/js/echarts-network.js', ['allship-main'], $v, true);
    }
    if (is_page_template('page-lien-he.php')) {
        wp_enqueue_script('allship-lien-he', $uri . '/src/js/lien-he.js', ['allship-main'], $v, true);
    }
}
add_action('wp_enqueue_scripts', 'allship_enqueue_assets');
```

### Script Attributes (defer)

```php
function allship_script_attributes($tag, $handle) {
    $defer = ['gsap', 'gsap-st', 'swiper-js', 'echarts', 'echarts-world', 'threejs',
              'allship-main', 'allship-echarts-init', 'allship-three-globe',
              'allship-sea-freight', 'allship-air-freight', 'allship-domestic',
              'allship-project-cargo', 'allship-warehouse', 'allship-gioi-thieu',
              'allship-echarts-network', 'allship-echarts-customs', 'allship-lien-he'];
    if (in_array($handle, $defer, true)) {
        return str_replace(' src', ' defer src', $tag);
    }
    return $tag;
}
add_filter('script_loader_tag', 'allship_script_attributes', 10, 2);
```

---

## 4. main.js Modification

### Cần thay đổi:

1. **Xóa `onReady()` wrapper** (không cần chờ `components:ready` vì WP render header/footer trực tiếp):

```javascript
// TRƯỚC (HTML):
function onReady(fn) { ... }
onReady(() => { ... });

// SAU (WP):
document.addEventListener('DOMContentLoaded', () => { ... });
```

2. **Xóa PhIcon custom element** nếu dùng Phosphor Icons CDN trực tiếp (đã có trong HTML).

3. **Giữ nguyên**: nav scroll, mobile menu, GSAP hero, scroll animations, Swiper init, FAQ accordion, footer accordion, counter, modal quote, pricing tabs, custom cursor, map zoom.

---

## 5. Tailwind v4 CDN Integration

### Trong `header.php` — `<head>` section:

```php
<!-- Tailwind v4 CDN (render-blocking, MUST be in <head>) -->
<script src="https://unpkg.com/@tailwindcss/browser@4"></script>

<!-- Tailwind Theme Tokens (inline) -->
<style type="text/tailwindcss">
@theme {
  --color-brand-red: #CE2027;
  --color-brand-red-hover: #9F090E;
  --color-brand-red-light: #FEE2E2;
  --color-brand-red-dark: #B12000;
  --color-brand-black: #000000;
  --color-navy-900: #0A1628;
  --color-navy-800: #0F2240;
  --color-navy-700: #153060;
  --color-navy-600: #1B4080;
  --color-white: #FFFFFF;
  --color-slate-50: #F8FAFC;
  --color-slate-100: #F1F5F9;
  --color-slate-200: #E2E8F0;
  --color-slate-400: #94A3B8;
  --color-slate-600: #475569;
  --color-slate-700: #334155;
  --color-slate-900: #0F172A;
  --color-success: #7A9C59;
  --color-warning: #F59E0B;
  --color-error: #CE2027;
  --color-info: #3B82F6;
  --shadow-brand: 0 4px 14px color-mix(in srgb, var(--color-brand-red) 25%, transparent);
  --font-sans: 'Manrope', system-ui, sans-serif;
}

@layer utilities {
  .text-hero { font-weight: 800; font-size: 36px; line-height: 1.1; letter-spacing: -0.02em; }
  @media (min-width: 768px) { .text-hero { font-size: 56px; } }
  .text-h1 { font-weight: 800; font-size: 32px; line-height: 1.15; letter-spacing: -0.02em; }
  @media (min-width: 768px) { .text-h1 { font-size: 48px; } }
  .text-h2 { font-weight: 800; font-size: 24px; line-height: 1.2; letter-spacing: -0.02em; }
  @media (min-width: 768px) { .text-h2 { font-size: 36px; } }
  .text-h3 { font-weight: 700; font-size: 20px; line-height: 1.3; letter-spacing: -0.02em; }
  @media (min-width: 768px) { .text-h3 { font-size: 24px; } }
  .text-h4 { font-weight: 700; font-size: 18px; line-height: 1.4; letter-spacing: -0.02em; }
  @media (min-width: 768px) { .text-h4 { font-size: 20px; } }
  .text-body-lg { font-weight: 500; font-size: 16px; line-height: 1.6; max-width: 65ch; }
  @media (min-width: 768px) { .text-body-lg { font-size: 18px; } }
  .text-body { font-weight: 500; font-size: 16px; line-height: 1.6; max-width: 65ch; }
  .text-caption { font-weight: 500; font-size: 12px; line-height: 1.5; }
  @media (min-width: 768px) { .text-caption { font-size: 14px; } }
  .text-overline { font-weight: 800; font-size: 11px; line-height: 1.4; text-transform: uppercase; letter-spacing: 0.1em; }
  @media (min-width: 768px) { .text-overline { font-size: 12px; } }
  .bg-dot-pattern {
    background-image: radial-gradient(var(--color-navy-900) 1px, transparent 1px);
    background-size: 24px 24px;
  }
}
</style>
```

> **QUAN TRỌNG**: Block `<style type="text/tailwindcss">` PHẢI nằm trong `<head>`, SAU tag `<script>` Tailwind CDN, TRƯỚC `<?php wp_head(); ?>`.
