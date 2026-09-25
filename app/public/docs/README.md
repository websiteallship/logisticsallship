# HTML → WordPress Conversion Documentation

> **Project**: Allship Logistics (`logistic.allship.vn`)
> **Approach**: Hardcode page content, ACF only for Header/Footer dynamic data
> **Updated**: 2026-07-15

---

## Document Index

| File | Nội dung |
|------|----------|
| [01-ARCHITECTURE.md](01-ARCHITECTURE.md) | Cấu trúc theme, ACF scope, WP Pages, Menu structure |
| [02-PAGE-TEMPLATES-MAP.md](02-PAGE-TEMPLATES-MAP.md) | Mapping chi tiết 9 HTML files → 9 PHP templates, sections inventory |
| [03-ASSETS-ENQUEUE.md](03-ASSETS-ENQUEUE.md) | Copy map 42 images + 11 JS + 2 CSS, enqueue config, Tailwind CDN integration |
| [04-HEADER-FOOTER.md](04-HEADER-FOOTER.md) | Header/Footer conversion với ACF, dropdown menu, quote modal, security escaping |
| [05-CLEANUP-PERFORMANCE.md](05-CLEANUP-PERFORMANCE.md) | WP cleanup script, performance targets, image optimization, plugins |
| [06-CONVERSION-CHECKLIST.md](06-CONVERSION-CHECKLIST.md) | Step-by-step checklist: Foundation → Page Conversion → Final QA |
| [07-SEARCH-REPLACE.md](07-SEARCH-REPLACE.md) | Regex patterns, link mappings, Node.js conversion script, encoding safety |
| [html-to-wp-guidelines.md](html-to-wp-guidelines.md) | Coding standards: Clean Code, Security escaping, Performance rules |

---

## Quick Reference

### Approach Summary
- **Header/Footer**: ACF dynamic (logo, phone, email, address, socials) + `wp_nav_menu()`
- **Page content**: 100% hardcode (copy HTML → PHP), fix asset paths only
- **Forms**: FluentForm shortcode `[fluentform id="X"]`
- **CSS**: Tailwind v4 CDN + inline `@theme` tokens + `allship-base.css`
- **JS**: `main.js` (core) + page-specific JS (conditional enqueue)

### Execution Order
```
1. Foundation  → setup.php, wp-cleanup.php, acf-fields.php, assets copy
2. Header/Footer → header.php (tokens), footer.php, quote-modal.php
3. Template Parts → trust-bar, testimonials, cta-section
4. Pages (1→9) → front-page.php first, then simplest pages
5. WP Admin → create pages, menus, settings, ACF data
6. Verify → visual comparison, console errors, responsive, interactions
```

### Files Already Done ✅
- `header.php` — Basic structure (needs Tailwind tokens update)
- `footer.php` — Complete
- `functions.php` — Entry point
- `inc/setup.php` — Theme setup + enqueue (needs update)
- `inc/acf-fields.php` — Theme Options (needs `company_logo_white` field)
- `style.css` — Theme metadata

### Files To Create 🔴
- `inc/wp-cleanup.php`
- `front-page.php`
- `page-dich-vu-hai-quan.php`
- `page-dich-vu-kho-bai.php`
- `page-dich-vu-van-chuyen-duong-bien.php`
- `page-dich-vu-van-chuyen-hang-du-an.php`
- `page-dich-vu-van-chuyen-hang-khong.php`
- `page-dich-vu-van-chuyen-noi-dia.php`
- `page-gioi-thieu.php`
- `page-lien-he.php`
- `template-parts/quote-modal.php`
- `template-parts/trust-bar.php`
- `template-parts/testimonials.php`
- `template-parts/cta-section.php`

### Critical Guardrails
1. **Encoding**: NEVER use PowerShell for Vietnamese text files → use Node.js
2. **Tailwind tokens**: `<style type="text/tailwindcss">` MUST be in `<head>` AFTER Tailwind CDN script
3. **main.js**: Replace `onReady()`/`components:ready` → `DOMContentLoaded`
4. **Security**: All dynamic output must use `esc_html()`, `esc_url()`, `esc_attr()`
5. **Images**: Hero images = `loading="eager" fetchpriority="high"`, rest = `loading="lazy"`
