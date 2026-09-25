# 12. Theme Integration Guide

## 1. Mục tiêu

Plugin `allship-ups-quote` phải tích hợp seamless với theme `allship-logistics`:
- Kế thừa design system (colors, typography, shapes).
- Tận dụng thư viện JS/CSS đã có.
- Không conflict với theme assets.
- Responsive và accessible theo chuẩn theme.

## 2. Design Tokens được kế thừa

### 2.1. CSS Custom Properties từ theme

Plugin CSS đọc tokens từ `:root` hoặc inline styles trong `header.php`:

```css
/* Tokens có sẵn từ allship-logistics theme */
--color-brand-red:       #CE2027;
--color-brand-red-hover: #9F090E;
--color-brand-red-light: #FEE2E2;
--color-navy-900:        #0A1628;
--color-navy-800:        #0F2240;

/* Shadow từ theme */
--shadow-brand: 0 4px 14px color-mix(in srgb, #CE2027 25%, transparent);
```

### 2.2. Typography

Theme dùng **Manrope** (self-hosted woff2):

```css
/* Plugin kế thừa qua font-family */
.ups-quote-form {
  font-family: 'Manrope', system-ui, sans-serif;
}

/* Heading scales từ theme */
.ups-quote-form h2 { font-size: 24px; line-height: 36px; font-weight: 800; }
.ups-quote-form h3 { font-size: 20px; line-height: 24px; font-weight: 700; }
```

### 2.3. Shape system

```css
/* Buttons: rounded-lg (8px) — khớp theme */
.ups-quote-btn { border-radius: 8px; }

/* Cards: rounded-xl (12px) — khớp theme */
.ups-quote-card { border-radius: 12px; }

/* Badges: pill shape — khớp theme */
.ups-quote-badge { border-radius: 9999px; }
```

## 3. JS Libraries từ theme

### 3.1. GSAP 3.12

Theme enqueue:
```
gsap → https://cdn.jsdelivr.net/npm/gsap@3.12/dist/gsap.min.js
gsap-st → ScrollTrigger
```

Plugin detect và sử dụng:

```javascript
// quote-form.js
const hasGSAP = typeof window.gsap !== 'undefined';

function animateElement(el, props) {
  if (hasGSAP) {
    gsap.from(el, { ...props, ease: 'power2.out' });
  } else {
    el.style.opacity = '1';
    el.style.transform = 'none';
  }
}
```

### 3.2. Phosphor Icons

Theme loads từ CDN (deferred):
```
Regular: https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css
Bold:    https://unpkg.com/@phosphor-icons/web@2.1.1/src/bold/style.css
Fill:    https://unpkg.com/@phosphor-icons/web@2.1.1/src/fill/style.css
```

Plugin dùng icons:

```html
<i class="ph ph-package"></i>           <!-- Package icon -->
<i class="ph ph-airplane-tilt"></i>     <!-- Freight icon -->
<i class="ph ph-magnifying-glass"></i>  <!-- Search icon -->
<i class="ph ph-plus"></i>              <!-- Add piece -->
<i class="ph ph-trash"></i>             <!-- Remove piece -->
<i class="ph ph-calculator"></i>        <!-- Calculate -->
<i class="ph ph-warning-circle"></i>    <!-- Warning -->
<i class="ph ph-check-circle"></i>      <!-- Success -->
<i class="ph ph-info"></i>              <!-- Info note -->
```

### 3.3. Swiper 11

Theme có Swiper nhưng plugin không cần dùng.

### 3.4. ECharts

Theme có ECharts nhưng plugin không cần dùng.

## 4. Tailwind CSS v4 (CDN)

Theme dùng Tailwind v4 qua compiled CSS (`tailwind-compiled.css`). Plugin có thể dùng Tailwind utility classes có sẵn nếu cần, nhưng **primary styling bằng vanilla CSS** để plugin hoạt động độc lập nếu theme thay đổi.

```css
/* Plugin CSS: self-contained, fallback values */
.ups-quote-form {
  --ups-brand: var(--color-brand-red, #CE2027);
  /* Nếu theme tokens không tồn tại → dùng fallback */
}
```

## 5. Enqueue Strategy

### 5.1. Plugin không load global

Chỉ enqueue trên page có shortcode:

```php
function allship_ups_maybe_enqueue() {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) ) return;
    if ( ! has_shortcode( $post->post_content, 'ups_quote_form' ) ) return;
    
    // Enqueue plugin assets
    wp_enqueue_style( 'ups-quote-form', /* ... */ );
    wp_enqueue_script( 'ups-quote-form', /* ... */ );
}
add_action( 'wp_enqueue_scripts', 'allship_ups_maybe_enqueue' );
```

### 5.2. Script dependency

```php
// Plugin JS chạy SAU theme main.js (để GSAP/Phosphor đã sẵn sàng)
wp_enqueue_script( 'ups-quote-form', $uri, ['allship-main'], $ver, true );
```

Nếu theme handle không tồn tại (plugin dùng với theme khác), script vẫn load bình thường — chỉ thiếu GSAP animations.

### 5.3. Không conflict

Plugin tuân thủ:
- CSS prefix: `ups-quote-*` cho tất cả classes.
- JS namespace: `window.UPSQuote` cho global state.
- REST namespace: `ups-quote/v1` — khác theme và FluentForm.
- Nonce name: `ups_quote_nonce` — không trùng.

## 6. Page Template Integration

### 6.1. Shortcode trong page riêng

Plugin tạo page `bao-gia-ups` khi activate. Page dùng template `page.php` mặc định của theme:

```php
// Theme page.php (đã có):
<?php get_header(); ?>
<main id="main">
  <?php the_content(); ?>  <!-- Shortcode renders here -->
</main>
<?php get_footer(); ?>
```

### 6.2. Navigation

Admin cần thêm page "Báo giá UPS" vào WordPress menu thủ công (Appearance → Menus), hoặc plugin có thể auto-add nếu cấu hình.

## 7. Responsive Breakpoints

Theme dùng breakpoints qua Tailwind:

| Breakpoint | Min-width | Plugin support |
|------------|-----------|----------------|
| `sm` | 640px | ✅ |
| `md` | 768px | ✅ |
| `lg` | 1024px | ✅ |
| `xl` | 1280px | ✅ |

Plugin CSS responsive:

```css
/* Mobile first */
.ups-quote-piece-table { overflow-x: auto; }

@media (min-width: 768px) {
  .ups-quote-piece-table { overflow-x: visible; }
  .ups-quote-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
}
```

## 8. Checklist tương thích

- [ ] Plugin CSS không override theme styles (no `!important`).
- [ ] Plugin JS không modify DOM ngoài container `#ups-quote-form`.
- [ ] Phosphor Icons render đúng (font đã load từ theme).
- [ ] GSAP animation hoạt động (nếu GSAP available).
- [ ] Form responsive ở mọi breakpoint.
- [ ] Font Manrope kế thừa từ theme.
- [ ] Brand colors khớp theme.
- [ ] Nút submit dùng class `.btn-primary` style hoặc custom style khớp.
- [ ] Kết quả hiển thị trong glass-card style nếu phù hợp.
- [ ] Không JS error trong console khi theme assets đã load.
