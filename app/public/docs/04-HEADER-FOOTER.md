# 04 - HEADER & FOOTER CONVERSION

> Chi tiết chuyển đổi header.html và footer.html sang header.php và footer.php.
> Đây là 2 file DUY NHẤT dùng ACF dynamic content.

---

## 1. header.php — Structure

### HTML source: `logistic/components/header.html`
### WP target: `wp-content/themes/allship-logistics/header.php`

```
┌──────────────────────────────────────────────────┐
│ <!DOCTYPE html>                                   │
│ <html lang="vi">                                  │
│ <head>                                            │
│   meta charset, viewport                          │
│   Tailwind CDN <script>                           │
│   Tailwind @theme tokens (inline <style>)         │
│   Phosphor Icons CDN                              │
│   allship-base.css                                │
│   <?php wp_head(); ?>                             │
│ </head>                                           │
│ <body <?php body_class(); ?>>                     │
│ <?php wp_body_open(); ?>                          │
│                                                    │
│ <!-- Skip Link -->                                │
│ <a href="#main" class="sr-only...">               │
│                                                    │
│ <!-- HEADER -->                                   │
│ <header id="header" class="site-header...">       │
│   <nav>                                           │
│     [Logo — ACF company_logo]                     │
│     [Desktop Menu — wp_nav_menu('menu-1')]        │
│     [Desktop Right: Phone ACF + CTA button]       │
│     [Mobile Hamburger]                            │
│   </nav>                                          │
│ </header>                                         │
│                                                    │
│ <!-- MOBILE MENU -->                              │
│ <div id="mob-menu">                               │
│   [Backdrop]                                      │
│   [Panel: wp_nav_menu('menu-1') + Phone + CTA]   │
│ </div>                                            │
└──────────────────────────────────────────────────┘
```

### Dynamic Elements:

| Element | Source | PHP Code |
|---------|--------|----------|
| Logo img | ACF `company_logo` | `<?php $logo = allship_option('company_logo', get_template_directory_uri() . '/assets/logo/logomail.png'); ?>` |
| Phone | ACF `company_phone` | `<?php $phone = allship_option('company_phone', '1900 252 338'); ?>` |
| Home URL | WP | `<?php echo esc_url(home_url('/')); ?>` |
| Desktop nav | wp_nav_menu | `wp_nav_menu(['theme_location' => 'menu-1', ...])` |
| Mobile nav | wp_nav_menu | `wp_nav_menu(['theme_location' => 'menu-1', 'menu_id' => 'mobile-primary-menu', ...])` |

### Dropdown Menu

Desktop menu cần dropdown cho "Dịch vụ". WP `wp_nav_menu()` mặc định render `<ul><li>` flat.

**Giải pháp**: Hardcode dropdown HTML trong header.php (giống HTML source), KHÔNG dùng wp_nav_menu cho dropdown phức tạp 2 cột.

**Hoặc**: Dùng Custom Walker class để render dropdown đúng cấu trúc.

### header.php đã có sẵn (conversation trước):
- File hiện tại: `wp-content/themes/allship-logistics/header.php` (119 lines)
- Đã tích hợp: Logo ACF, wp_nav_menu, Phone ACF, Mobile menu
- **Cần bổ sung**: Inline Tailwind tokens `<style type="text/tailwindcss">` trong `<head>`

---

## 2. footer.php — Structure

### HTML source: `logistic/components/footer.html`
### WP target: `wp-content/themes/allship-logistics/footer.php`

```
┌──────────────────────────────────────────────────┐
│ <!-- FOOTER -->                                   │
│ <footer class="bg-navy-900 text-white">           │
│   <div class="grid grid-cols-1 md:grid-cols-4">   │
│     [Col 1: Logo white + Company description]     │
│     [Col 2: Dịch vụ links — Widget or hardcode]   │
│     [Col 3: Thông tin — ACF contact fields]       │
│     [Col 4: Social links — ACF social_*]          │
│   </div>                                          │
│   <div class="border-t">                          │
│     [Copyright line]                              │
│   </div>                                          │
│ </footer>                                         │
│                                                    │
│ <!-- Quote Modal -->                              │
│ <?php get_template_part('template-parts/quote-modal'); ?>│
│                                                    │
│ <?php wp_footer(); ?>                             │
│ </body>                                           │
│ </html>                                           │
└──────────────────────────────────────────────────┘
```

### Dynamic Elements:

| Element | Source | PHP Code |
|---------|--------|----------|
| Logo trắng | ACF `company_logo_white` | `<?php $logo_w = allship_option('company_logo_white', get_template_directory_uri() . '/assets/logo/logo-white.png'); ?>` |
| Phone | ACF `company_phone` | Reuse `$phone` |
| Email | ACF `company_email` | `<?php $email = allship_option('company_email', 'info@allship.vn'); ?>` |
| Address | ACF `company_address` | `<?php $address = allship_option('company_address', 'TP. Hồ Chí Minh, Việt Nam'); ?>` |
| Facebook | ACF `social_facebook` | `<?php $fb = allship_option('social_facebook'); ?>` |
| Zalo | ACF `social_zalo` | `<?php $zalo = allship_option('social_zalo'); ?>` |
| YouTube | ACF `social_youtube` | `<?php $yt = allship_option('social_youtube'); ?>` |
| Service links | Widget `footer-1` hoặc hardcode | `<?php dynamic_sidebar('footer-1'); ?>` |
| Copyright year | PHP | `<?php echo date('Y'); ?>` |

### Footer Accordion (Mobile)

Footer dùng accordion trên mobile (`.js-footer-accordion`). Logic JS đã có trong `main.js`.

**Giữ nguyên cấu trúc HTML**:
- `.js-footer-accordion` wrapper
- `h4` trigger
- `.js-accordion-content` panel
- `.js-accordion-icon` chevron

---

## 3. Quote Modal (template-parts/quote-modal.php)

### Source: `logistic/components/quote-modal.html`

**2 options**:

### Option A: FluentForm Shortcode (Khuyến nghị)
```php
<!-- Quote Modal -->
<div id="quote-modal" class="fixed inset-0 z-[100] flex items-center justify-center hidden opacity-0 transition-opacity duration-300">
  <div class="absolute inset-0 bg-slate-900/75 backdrop-blur-sm" id="modal-overlay"></div>
  <div class="relative bg-white rounded-xl ... max-w-lg ...">
    <!-- Header -->
    <div class="bg-slate-50 px-6 py-4 border-b ... flex justify-between items-center">
      <div class="flex items-center gap-3">
        <div class="bg-red-50 p-2 rounded-lg text-brand-red">
          <i class="ph-bold ph-phone-call text-xl"></i>
        </div>
        <h3 class="text-h3 text-navy-900">Yêu cầu tư vấn & Báo giá</h3>
      </div>
      <button id="close-modal-btn" class="..."><i class="ph ph-x text-xl"></i></button>
    </div>
    <!-- Body: FluentForm -->
    <div class="px-6 py-6 overflow-y-auto">
      <?php echo do_shortcode('[fluentform id="1"]'); ?>
    </div>
  </div>
</div>
```

### Option B: Hardcode Form (copy nguyên từ quote-modal.html)
Giữ nguyên HTML form nhưng **KHÔNG** xử lý backend → chỉ dùng tạm cho giao diện.

---

## 4. Security Escaping Checklist

| Data | Function | Ví dụ |
|------|----------|-------|
| Text hiển thị | `esc_html()` | `<?php echo esc_html($phone); ?>` |
| URL (href, src) | `esc_url()` | `<?php echo esc_url($logo); ?>` |
| HTML attribute | `esc_attr()` | `alt="<?php echo esc_attr($name); ?>"` |
| Phone href | `esc_attr()` + strip | `href="tel:<?php echo esc_attr(preg_replace('/[^0-9]/', '', $phone)); ?>"` |
| Email href | `esc_attr()` | `href="mailto:<?php echo esc_attr($email); ?>"` |
