# 02 - PAGE TEMPLATES MAP

> Mapping chi tiết từng file HTML gốc → page template PHP.
> Mỗi page template bắt đầu bằng `<?php /* Template Name: ... */ ?>` (trừ `front-page.php`).

---

## Quy ước chung cho MỌI page template

```php
<?php /* Template Name: Tên trang */ ?>
<?php get_header(); ?>

<main id="main">
  <!-- PASTE hardcode HTML sections here -->
  <!-- Thay src="assets/..." bằng src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/..." -->
  <!-- Thay href tĩnh bằng dynamic links nếu cần -->
</main>

<?php get_footer(); ?>
```

### Checklist chuyển đổi cho mỗi section:
1. Copy HTML nguyên bản từ `<section>` đến `</section>`
2. Thay `src="assets/images/..."` → `src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/..."`
3. Thay `href="dich-vu-xxx.html"` → `href="<?php echo esc_url(home_url('/dich-vu-xxx/')); ?>"`
4. Thay `href="#contact"` → `href="#contact"` (giữ nguyên cho modal trigger)
5. Xóa `<div id="site-header-placeholder">` và `<div id="site-footer-placeholder">` (WP dùng `get_header()` / `get_footer()`)
6. Xóa các script tags cuối file (`components-data.js`, `components.js`)
7. Giữ nguyên tất cả Tailwind classes, `data-anim` attributes, GSAP/Swiper selectors

---

## 1. front-page.php ← index.html

**Không cần Template Name** (WP tự nhận diện `front-page.php`).

### Sections (theo thứ tự):

| # | Section ID | Mô tả | CDN Dependencies | Page JS |
|---|-----------|--------|-------------------|---------|
| 1 | `#hero` | Hero với ECharts world map background, floating cards, CTA | ECharts, Three.js | `echarts-init.js`, `three-globe.js` |
| 2 | `#trust-bar` | Logo marquee (animate-marquee) | — | — |
| 3 | `#services` | Bento Grid 7 services cards (6 service + link) | — | — |
| 4 | `#why-choose` | Stats counters + differentiators | — | `main.js` (counter) |
| 5 | _(customs)_ | Chi tiết dịch vụ Hải quan (split layout) | — | — |
| 6 | _(sea-freight)_ | Chi tiết Đường biển (split layout reversed) | — | — |
| 7 | _(air-freight)_ | Chi tiết Hàng không (bento features) | — | — |
| 8 | `#process` | Timeline 4 bước (sticky left + vertical cards) | — | `main.js` (scroll anim) |
| 9 | `#coverage` | Coverage Map SVG (zoom controls mobile) | — | `main.js` (zoom fn) |
| 10 | `#testimonials` | Swiper carousel 8 slides | Swiper CSS+JS | `main.js` (Swiper init) |
| 11 | `#cta` | CTA centered block (dark bg) | — | — |

### Lưu ý đặc biệt:
- Section Hero cần `<div id="echarts-world-map">` cho ECharts background
- World map SVG ở section Coverage dùng external image: thay CDN wikimedia → local asset
- `three-globe.js` tạo canvas background → load conditional
- **Trust Bar**: dùng `get_template_part('template-parts/trust-bar')` thay vì copy inline
- **Testimonials**: dùng `get_template_part('template-parts/testimonials')` 
- **CTA**: dùng `get_template_part('template-parts/cta-section')`

---

## 2. page-dich-vu-hai-quan.php ← dich-vu-hai-quan.html

```php
<?php /* Template Name: Dịch vụ Hải quan */ ?>
```

### Sections:

| # | Mô tả | Dependencies |
|---|--------|-------------|
| 1 | Hero centered (hero image + badge float) | — |
| 2 | Trust Bar (get_template_part) | — |
| 3 | Core Features Bento 6 cells (icons + descriptions) | — |
| 4 | Bento hình ảnh (3 image cards) | — |
| 5 | Hồ sơ cần chuẩn bị - Document Accordion (js-doc-accordion-trigger) | `main.js` |
| 6 | Bảng giá - CSS Radio Tabs (tab-of, tab-local, tab-dem-det) | `allship-base.css` (radio tab CSS) |
| 7 | Đối tượng khách hàng - Bento cards | — |
| 8 | Quy trình 4 bước (get_template_part hoặc inline) | — |
| 9 | FAQ Accordion (faq-trigger) | `main.js` |
| 10 | Testimonials (get_template_part) | Swiper |
| 11 | CTA (get_template_part) | — |

### Page-specific JS: `echarts-customs.js` (nếu có chart)

### Lưu ý:
- Pricing tabs dùng CSS pure (radio inputs) → không cần JS
- Document accordion dùng `.js-doc-accordion-trigger` → handled by `main.js`
- Hero image: `hai-quan-1.jpg` → local assets

---

## 3. page-dich-vu-kho-bai.php ← dich-vu-kho-bai.html

```php
<?php /* Template Name: Dịch vụ Kho bãi */ ?>
```

### Sections:

| # | Mô tả | Dependencies |
|---|--------|-------------|
| 1 | Hero centered (hero image) | — |
| 2 | Trust Bar | — |
| 3 | Core Features Bento | — |
| 4 | Pipeline Steps (interactive steps) | `warehouse.js` |
| 5 | Stats counters | `main.js` |
| 6 | FAQ Accordion | `main.js` |
| 7 | Testimonials | Swiper |
| 8 | CTA | — |

### Page-specific JS: `warehouse.js`

---

## 4. page-dich-vu-van-chuyen-duong-bien.php ← dich-vu-van-chuyen-duong-bien.html

```php
<?php /* Template Name: Vận chuyển Đường biển */ ?>
```

### Sections:

| # | Mô tả | Dependencies |
|---|--------|-------------|
| 1 | Hero centered | — |
| 2 | Trust Bar | — |
| 3 | Core Features Bento | — |
| 4 | Route Map SVG | — |
| 5 | Pricing info | — |
| 6 | FAQ Accordion | `main.js` |
| 7 | Testimonials | Swiper |
| 8 | CTA | — |

### Page-specific JS: `sea-freight.js`

---

## 5. page-dich-vu-van-chuyen-hang-du-an.php ← dich-vu-van-chuyen-hang-du-an.html

```php
<?php /* Template Name: Hàng Dự án */ ?>
```

### Sections:

| # | Mô tả | Dependencies |
|---|--------|-------------|
| 1 | Hero centered | — |
| 2 | Trust Bar | — |
| 3 | Core Features | — |
| 4 | Service Tabs (interactive tab switching) | `project-cargo.js` |
| 5 | Portfolio gallery (6 project images) | — |
| 6 | FAQ Accordion | `main.js` |
| 7 | Testimonials | Swiper |
| 8 | CTA | — |

### Page-specific JS: `project-cargo.js`
### Lưu ý: Tabs dùng JS switching (`.js-pricing-tabs button` hoặc custom) → kiểm tra selectors match

---

## 6. page-dich-vu-van-chuyen-hang-khong.php ← dich-vu-van-chuyen-hang-khong.html

```php
<?php /* Template Name: Vận chuyển Hàng không */ ?>
```

### Sections:

| # | Mô tả | Dependencies |
|---|--------|-------------|
| 1 | Hero centered | — |
| 2 | Trust Bar | — |
| 3 | Core Features Bento | — |
| 4 | Route Map / Coverage | — |
| 5 | Pricing tiers | — |
| 6 | FAQ Accordion | `main.js` |
| 7 | Testimonials | Swiper |
| 8 | CTA | — |

### Page-specific JS: `air-freight.js`

---

## 7. page-dich-vu-van-chuyen-noi-dia.php ← dich-vu-van-chuyen-noi-dia.html

```php
<?php /* Template Name: Vận chuyển Nội địa */ ?>
```

### Sections:

| # | Mô tả | Dependencies |
|---|--------|-------------|
| 1 | Hero centered | — |
| 2 | Trust Bar | — |
| 3 | Core Features | — |
| 4 | Coverage Map (Vietnam routes) | — |
| 5 | Stats counters | `main.js` |
| 6 | FAQ Accordion | `main.js` |
| 7 | Testimonials | Swiper |
| 8 | CTA | — |

### Page-specific JS: `domestic-freight.js`

---

## 8. page-gioi-thieu.php ← gioi-thieu.html

```php
<?php /* Template Name: Giới thiệu */ ?>
```

### Sections:

| # | Mô tả | Dependencies |
|---|--------|-------------|
| 1 | Hero + SVG Network Graph (GSAP animated) | — |
| 2 | Company Overview | — |
| 3 | Timeline (năm thành lập, mốc phát triển) | — |
| 4 | Stats counters | `main.js` |
| 5 | Core Values | — |
| 6 | CTA | — |

### Page-specific JS: `gioi-thieu.js`, `echarts-network.js`

---

## 9. page-lien-he.php ← lien-he.html

```php
<?php /* Template Name: Liên hệ */ ?>
```

### Sections:

| # | Mô tả | Dependencies |
|---|--------|-------------|
| 1 | Hero centered | — |
| 2 | Contact Info Cards (phone, email, address) | — |
| 3 | Contact Form (FluentForm shortcode) | FluentForm plugin |
| 4 | Google Maps embed | — |
| 5 | CTA | — |

### Page-specific JS: `lien-he.js`

### Lưu ý:
- Contact info (phone, email, address) lấy từ ACF Theme Options
- Form: `<?php echo do_shortcode('[fluentform id="1"]'); ?>`
- Map: hardcode Google Maps iframe hoặc ACF embed field
