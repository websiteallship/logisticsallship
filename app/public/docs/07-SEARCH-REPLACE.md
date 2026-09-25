# 07 - SEARCH & REPLACE PATTERNS

> Các regex/patterns cần tìm và thay thế khi chuyển đổi HTML → PHP template.
> Sử dụng Node.js script để thao tác file (tránh PowerShell encoding issues với Vietnamese).

---

## 1. Asset Path Replacements

### Theme Assets (Icons, Backgrounds, Decor)
```
FIND:    src="assets/images/
REPLACE: src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/
```

### Content Images (đã upload vào WP Media Library)
```
FIND:    src="assets/images/
REPLACE: src="<?php $upload_dir = wp_upload_dir(); echo esc_url($upload_dir['baseurl']); ?>/2026/07/
```

### Images (closing quote after extension)
Cần đóng `"` đúng vị trí. Pattern:
```
FIND:    src="assets/images/([^"]+)"
REPLACE: src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/$1"
```

### Background images (inline style)
```
FIND:    style="background-image: url('assets/images/
REPLACE: style="background-image: url('<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/
```

### Logo paths
```
FIND:    src="assets/logo/
REPLACE: src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/logo/
```

---

## 2. Internal Link Replacements

### Page links (HTML → WP slug)

| HTML href | WP href |
|-----------|---------|
| `href="index.html"` | `href="<?php echo esc_url(home_url('/')); ?>"` |
| `href="gioi-thieu.html"` | `href="<?php echo esc_url(home_url('/gioi-thieu/')); ?>"` |
| `href="lien-he.html"` | `href="<?php echo esc_url(home_url('/lien-he/')); ?>"` |
| `href="dich-vu-hai-quan.html"` | `href="<?php echo esc_url(home_url('/dich-vu-hai-quan/')); ?>"` |
| `href="dich-vu-kho-bai.html"` | `href="<?php echo esc_url(home_url('/dich-vu-kho-bai/')); ?>"` |
| `href="dich-vu-van-chuyen-duong-bien.html"` | `href="<?php echo esc_url(home_url('/dich-vu-van-chuyen-duong-bien/')); ?>"` |
| `href="dich-vu-van-chuyen-hang-du-an.html"` | `href="<?php echo esc_url(home_url('/dich-vu-van-chuyen-hang-du-an/')); ?>"` |
| `href="dich-vu-van-chuyen-hang-khong.html"` | `href="<?php echo esc_url(home_url('/dich-vu-van-chuyen-hang-khong/')); ?>"` |
| `href="dich-vu-van-chuyen-noi-dia.html"` | `href="<?php echo esc_url(home_url('/dich-vu-van-chuyen-noi-dia/')); ?>"` |

### Anchor links (giữ nguyên)
```
href="#contact"     → giữ nguyên (triggers modal via JS)
href="#services"    → giữ nguyên (scroll on same page)
href="#hero"        → giữ nguyên
href="#main"        → giữ nguyên (skip link)
```

---

## 3. Elements to Remove (per page template)

### Remove from EVERY page file:

```html
<!-- REMOVE: Head section (handled by header.php) -->
<!DOCTYPE html> ... </head>

<!-- REMOVE: Body open (handled by header.php) -->
<body class="...">

<!-- REMOVE: Component placeholders -->
<div id="site-header-placeholder"></div>
<div id="site-footer-placeholder"></div>
<div id="site-modal-placeholder"></div>

<!-- REMOVE: Script tags (handled by wp_enqueue) -->
<script src="assets/src/js/components-data.js"></script>
<script src="assets/src/js/components.js"></script>
<script src="assets/src/js/main.js" defer></script>
<script src="assets/src/js/echarts-init.js" defer></script>
<script src="assets/src/js/three-globe.js" defer></script>
<script src="assets/src/js/sea-freight.js" defer></script>
<!-- ... all page-specific scripts -->

<!-- REMOVE: CDN script tags (handled by wp_enqueue) -->
<script src="https://unpkg.com/@tailwindcss/browser@4"></script>
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12/dist/gsap.min.js" defer></script>
<!-- ... all CDN scripts -->

<!-- REMOVE: CDN CSS links (handled by wp_enqueue) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">

<!-- REMOVE: Local CSS links (handled by wp_enqueue) -->
<link rel="stylesheet" href="assets/src/css/allship-base.css">

<!-- REMOVE: Inline Tailwind tokens (moved to header.php) -->
<style type="text/tailwindcss">@theme { ... }</style>

<!-- REMOVE: Closing tags (handled by footer.php) -->
</body>
</html>
```

---

## 4. Encoding Safety

> **QUAN TRỌNG (từ LESSONS_LEARNED.md)**: 
> KHÔNG dùng PowerShell để đọc/ghi file chứa ký tự Vietnamese.
> Dùng Node.js hoặc PHP cho file operations.

### Node.js Script Template (safe encoding)

```javascript
// convert-page.js — Run with: node convert-page.js
const fs = require('fs');
const path = require('path');

const sourceFile = process.argv[2]; // e.g. 'index.html'
const outputFile = process.argv[3]; // e.g. 'front-page.php'

let html = fs.readFileSync(path.join(__dirname, sourceFile), 'utf8');

// 1. Extract <main>...</main> content
const mainMatch = html.match(/<main[^>]*>([\s\S]*?)<\/main>/);
if (!mainMatch) { console.error('No <main> found'); process.exit(1); }
let content = mainMatch[0];

// 2. Replace asset paths
content = content.replace(/src="assets\//g, 
  'src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/');

// 3. Replace page links
const linkMap = {
  'index.html': "<?php echo esc_url(home_url('/')); ?>",
  'gioi-thieu.html': "<?php echo esc_url(home_url('/gioi-thieu/')); ?>",
  'lien-he.html': "<?php echo esc_url(home_url('/lien-he/')); ?>",
  'dich-vu-hai-quan.html': "<?php echo esc_url(home_url('/dich-vu-hai-quan/')); ?>",
  'dich-vu-kho-bai.html': "<?php echo esc_url(home_url('/dich-vu-kho-bai/')); ?>",
  'dich-vu-van-chuyen-duong-bien.html': "<?php echo esc_url(home_url('/dich-vu-van-chuyen-duong-bien/')); ?>",
  'dich-vu-van-chuyen-hang-du-an.html': "<?php echo esc_url(home_url('/dich-vu-van-chuyen-hang-du-an/')); ?>",
  'dich-vu-van-chuyen-hang-khong.html': "<?php echo esc_url(home_url('/dich-vu-van-chuyen-hang-khong/')); ?>",
  'dich-vu-van-chuyen-noi-dia.html': "<?php echo esc_url(home_url('/dich-vu-van-chuyen-noi-dia/')); ?>",
};
for (const [html, wp] of Object.entries(linkMap)) {
  content = content.replace(new RegExp(`href="${html}"`, 'g'), `href="${wp}"`);
}

// 4. Wrap with WP template
const templateName = process.argv[4] || '';
let output = '';
if (templateName) {
  output += `<?php /* Template Name: ${templateName} */ ?>\n`;
}
output += `<?php get_header(); ?>\n\n`;
output += content;
output += `\n\n<?php get_footer(); ?>\n`;

fs.writeFileSync(path.join(__dirname, outputFile), output, 'utf8');
console.log(`✅ Created ${outputFile}`);
```

### Usage:
```bash
node convert-page.js index.html front-page.php
node convert-page.js dich-vu-hai-quan.html page-dich-vu-hai-quan.php "Dịch vụ Hải quan"
node convert-page.js lien-he.html page-lien-he.php "Liên hệ"
```

---

## 5. Special Cases

### World Map Image (index.html Coverage section)
```
FIND:    src="https://upload.wikimedia.org/wikipedia/commons/e/ec/World_map_blank_without_borders.svg"
ACTION:  Download SVG → save to assets/images/world-map.svg → use local path
REPLACE: src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/world-map.svg"
```

### ECharts Container
```
Keep:    <div id="echarts-world-map" class="..."></div>
Note:    Only present in front-page.php. JS init handled by echarts-init.js (conditional enqueue)
```

### FluentForm Integration
```
FIND:    <form id="quote-form" class="space-y-4">...</form>
REPLACE: <?php echo do_shortcode('[fluentform id="1"]'); ?>
```
