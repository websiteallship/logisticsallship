# UPS Plugin Frontend Rules

> Áp dụng cho `public/` và frontend code trong plugin `allship-ups-quote`.

## Tech Stack Frontend

| Component | Tech | Source |
|-----------|------|--------|
| DOM | Vanilla JS ES6+ | Native |
| HTTP | Fetch API | Native |
| Number format | `Intl.NumberFormat('vi-VN')` | Native |
| Icons | Phosphor Icons | Theme CDN |
| Animations | GSAP 3.12 | Theme CDN |
| Fonts | Manrope (woff2) | Theme |
| CSS | Custom Properties + Vanilla | Plugin riêng |

## KHÔNG ĐƯỢC

- ❌ jQuery, React, Vue
- ❌ Thêm dependencies mới
- ❌ Modify DOM ngoài `#ups-quote-form`
- ❌ Inline `<script>` tags
- ❌ Hardcoded hex colors → dùng CSS tokens
- ❌ `!important` trong CSS

## CSS Namespace

- Tất cả classes prefix: `ups-quote-*`
- Container: `.ups-quote-form`
- CSS tokens từ theme (với fallback):

```css
.ups-quote-form {
    --ups-brand: var(--color-brand-red, #CE2027);
    --ups-brand-hover: var(--color-brand-red-hover, #9F090E);
    --ups-navy: var(--color-navy-900, #0A1628);
    --ups-success: #059669;
    --ups-error: #dc2626;
    --ups-warning: #d97706;
}
```

## Design System (kế thừa Theme Allship)

### Colors

| Token | Value |
|-------|-------|
| Brand Red | `#CE2027` |
| Brand Red Hover | `#9F090E` |
| Brand Red Light | `#FEE2E2` |
| Brand Gold | `#F59E0B` |
| Navy 900 | `#0A1628` |
| Emerald | `#059669` |

### Typography (Manrope)

```css
font-family: 'Manrope', system-ui, sans-serif;
```

### Shapes

- Buttons: `border-radius: 8px`
- Cards: `border-radius: 12px`
- Badges: `border-radius: 9999px`

## Enqueue Strategy

- Chỉ load trên page có shortcode `[ups_quote_form]`.
- Dependency: `allship-main` (theme JS handle).
- `wp_localize_script()` cho `apiBase`, `nonce`, `currency`.

## Animation

- Detect GSAP: `if (window.gsap) { ... }`
- CSS fallback nếu GSAP không available.
- `@media (prefers-reduced-motion: reduce)` → disable animations.

## Responsive Breakpoints

| Breakpoint | Min-width |
|------------|-----------|
| Mobile | < 640px |
| sm | 640px |
| md | 768px |
| lg | 1024px |
| xl | 1280px |

## Accessibility

- Label cho MỌI input (KHÔNG chỉ placeholder).
- `aria-required="true"` cho required fields.
- `role="alert"` cho error messages.
- `aria-live="polite"` cho result area.
- Keyboard navigation: Tab order, Enter, Escape.
- Color contrast ≥ 4.5:1.
- Touch targets ≥ 44px trên mobile.

## Mockup Reference

Form UI PHẢI khớp 100% với: `mockups/public-quote-mockup-v2.html`
